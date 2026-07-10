<?php
/**
 * Steward — Web Installer
 *
 * Extract the package to your web root, then visit /steward/setup/ in a browser.
 * PHP 8.0+ required with pdo, pdo_mysql, and mbstring extensions.
 */
define('MONEY_SETUP_MODE', true);
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Give each installation its own session name so browsers can't share
// MM_SETUP cookies between instances on the same host.
$_setupDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/setup/index.php')), '/');
session_name('MM_SETUP_' . substr(md5($_setupDir), 0, 8));
session_start();

$appRoot        = dirname(__DIR__);
$configDir      = $appRoot . '/config';
$schemaFile     = $appRoot . '/install/schema.sql';
$sampleDataFile = $appRoot . '/sql/sample_data.sql';
$lockFile       = __DIR__ . '/.installed';

// Defense-in-depth: clear any session that predates this instance's wizard.
if (!isset($_SESSION['mm_setup_root']) || $_SESSION['mm_setup_root'] !== $appRoot) {
    $_SESSION = [];
}
$_SESSION['mm_setup_root'] = $appRoot;

// ── helpers ───────────────────────────────────────────────────────────────────

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function setupUrl(string $step = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $step ? $base . '/?step=' . urlencode($step) : $base . '/';
}

function appBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/setup/index.php');
    // /steward/setup/index.php  →  /steward
    $base = rtrim(dirname(dirname($script)), '/');
    if ($base === '.') $base = '';
    return $scheme . '://' . $host . $base;
}

function detectBasePath(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/setup/index.php');
    $base   = rtrim(dirname(dirname($script)), '/');
    return ($base === '.' || $base === '/') ? '' : $base;
}

/**
 * Execute every semicolon-terminated statement in a SQL file.
 * Returns an array of error strings (empty = success).
 */
function importSql(PDO $pdo, string $file): array
{
    $errors = [];
    $buffer = '';
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        // Skip comment lines and MySQL version-conditional hints
        if (preg_match('/^\s*(--|\/\*!|\/\*)/', $line)) {
            continue;
        }
        $buffer .= ' ' . $line;
        if (str_ends_with(rtrim($line), ';')) {
            $stmt = trim($buffer);
            $buffer = '';
            if ($stmt === '' || $stmt === ';') {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                // Suppress "already exists" / duplicate-key errors from seed data
                if (!str_contains($msg, 'Duplicate entry') &&
                    !str_contains($msg, 'already exists')) {
                    $errors[] = $msg;
                }
            }
        }
    }
    return $errors;
}

/**
 * Bring an existing database up to date via the migration files, without ever
 * dropping a table. Used when the target database already has data — running
 * install/schema.sql there would DROP TABLE every core table before recreating
 * them empty. Returns an array of fatal error messages (empty = success).
 */
function applyPendingMigrations(PDO $pdo, string $appRoot): array
{
    $errors = [];
    $benign = [1060, 1050, 1061, 1091]; // dup col, table exists, dup key, can't drop

    // Older databases may predate the schema_migrations table itself.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version    SMALLINT UNSIGNED NOT NULL,
            filename   VARCHAR(100)      NOT NULL,
            applied_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $applied = array_flip(array_map(
        'intval',
        $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
    ));

    $files = glob($appRoot . '/sql/migrations/*.sql') ?: [];
    natsort($files);
    foreach ($files as $path) {
        if (!preg_match('/(\d+)_/', basename($path), $m)) {
            continue;
        }
        $version = (int)$m[1];
        if (isset($applied[$version])) {
            continue;
        }

        $sql = @file_get_contents($path);
        if ($sql === false) {
            $errors[] = "Could not read migration " . basename($path);
            break;
        }
        $stripped   = preg_replace('/--[^\r\n]*/', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $stripped)));
        $migErrors  = [];
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                if (!in_array((int)($e->errorInfo[1] ?? 0), $benign, true)) {
                    $migErrors[] = basename($path) . ': ' . $e->getMessage();
                }
            }
        }
        if (!empty($migErrors)) {
            $errors = array_merge($errors, $migErrors);
            break; // stop at the first failing migration — don't skip ahead
        }

        $pdo->prepare(
            'INSERT IGNORE INTO schema_migrations (version, filename, applied_at) VALUES (?, ?, NOW())'
        )->execute([$version, basename($path)]);
    }

    return $errors;
}

/** Create a PDO connection; throws PDOException on failure. */
function tryConnect(string $host, string $name, string $user, string $pass): PDO
{
    return new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_TIMEOUT            => 5,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

/** Write config/database.php and config/app.php; returns null on success or an error string. */
function writeConfigFiles(array $db, array $cfg, string $configDir): ?string
{
    // Single-quote a PHP string literal value safely
    $q = static fn(string $s): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";

    // ── config/database.php ──
    $dbPhp  = "<?php\n";
    $dbPhp .= "define('DB_HOST',    " . $q($db['dbHost']) . ");\n";
    $dbPhp .= "define('DB_NAME',    " . $q($db['dbName']) . ");\n";
    $dbPhp .= "define('DB_USER',    " . $q($db['dbUser']) . ");\n";
    $dbPhp .= "define('DB_PASS',    " . $q($db['dbPass']) . ");\n";
    $dbPhp .= "define('DB_CHARSET', 'utf8mb4');\n\n";
    $dbPhp .= <<<'PHP'
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Database connection failed. Please check configuration.');
        }
    }
    return $pdo;
}
PHP;

    if (file_put_contents($configDir . '/database.php', $dbPhp) === false) {
        return 'Could not write config/database.php — check directory permissions.';
    }

    // ── config/app.php ──
    $bp = $q($cfg['basePath']);
    $tz = $q($cfg['timezone']);

    $appPhp  = "<?php\n";
    // Setup redirect: fires on every page load if database.php is missing
    $appPhp .= <<<'PHP'
if (!file_exists(__DIR__ . '/database.php') && !defined('MONEY_SETUP_MODE') && php_sapi_name() !== 'cli') {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $webBase = ($docRoot && str_starts_with($appRoot, $docRoot)) ? substr($appRoot, strlen($docRoot)) : '';
    header('Location: ' . $scheme . '://' . $host . $webBase . '/setup/');
    exit;
}

PHP;
    // Read version from the source app.php before we overwrite it; fall back to a literal.
    $_ver = '3.3.0';
    if (preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/",
                   @file_get_contents($configDir . '/app.php') ?: '', $_mv)) {
        $_ver = $_mv[1];
    }
    $appPhp .= "define('APP_NAME',           'Steward');\n";
    $appPhp .= "define('APP_VERSION',        '{$_ver}');\n";
    $appPhp .= "define('APP_SCHEMA_VERSION', 1);\n";
    $appPhp .= "define('BASE_PATH',          {$bp});\n\n";
    $appPhp .= <<<'PHP'
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_name('STEWARD_SESSION');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => BASE_PATH === '' ? '/' : BASE_PATH . '/']);
    session_start();
}

PHP;
    $appPhp .= "date_default_timezone_set({$tz});\n\n";
    $appPhp .= <<<'PHP'
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('MONEY_SYMBOL',   '$');
define('MONEY_DECIMALS', 2);

// ── HTTPS enforcement ───────────────────────────────────────────────────────
// Runs on every request before any output. Skipped during setup (no DB yet) and CLI.
if (file_exists(__DIR__ . '/database.php') && php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    if (getSetting('enforce_https') === '1') {
        $_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                 || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        if (!$_isHttps) {
            header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
    }
}
PHP;

    if (file_put_contents($configDir . '/app.php', $appPhp) === false) {
        return 'Could not write config/app.php — check directory permissions.';
    }

    return null;
}

/** Test whether AllowOverride FileInfo is active for the setup directory by fetching the probe URL. */
function testAllowOverride(): bool
{
    if (!function_exists('curl_init')) return true; // can't test — assume OK

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/setup/index.php'), '/');
    $url    = $scheme . '://' . $host . $base . '/probe-check';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    // If cURL couldn't connect (loopback blocked, port mismatch, IPv6 issue, etc.),
    // treat as inconclusive and let setup proceed — Apache config can be verified manually.
    if ($errno !== 0 || $body === false) return true;

    return $body === 'PROBE_OK';
}

/** System requirements check. Returns array of result items. */
function checkRequirements(string $configDir): array
{
    $results = [];

    $phpOk = PHP_VERSION_ID >= 80000;
    $results[] = [
        'label' => 'PHP version (8.0 or higher)',
        'ok'    => $phpOk,
        'value' => PHP_VERSION,
        'fix'   => 'Upgrade to PHP 8.0 or higher.',
    ];

    foreach (['pdo', 'pdo_mysql', 'mbstring'] as $ext) {
        $ok = extension_loaded($ext);
        $results[] = [
            'label' => "PHP extension: {$ext}",
            'ok'    => $ok,
            'value' => $ok ? 'loaded' : 'missing',
            'fix'   => "Install the php-{$ext} package and restart your web server.",
        ];
    }

    $writable = is_writable($configDir);
    $results[] = [
        'label' => 'config/ directory is writable',
        'ok'    => $writable,
        'value' => $writable ? 'writable' : 'not writable',
        'fix'   => "sudo chown www-data:www-data {$configDir}",
    ];

    $rewriteOk = function_exists('apache_get_modules')
        ? in_array('mod_rewrite', apache_get_modules(), true)
        : true; // non-Apache or CLI: skip module check
    $results[] = [
        'label' => 'Apache mod_rewrite enabled',
        'ok'    => $rewriteOk,
        'value' => $rewriteOk ? 'enabled' : 'not detected',
        'fix'   => 'Run: sudo a2enmod rewrite && sudo systemctl reload apache2',
    ];

    // Detect the filesystem path for the helpful snippet below
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html'), '/');
    $appBase = rtrim(dirname(dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/setup/index.php'))), '/');
    $fsPath  = $docRoot . $appBase;

    $overrideOk = testAllowOverride();
    $results[] = [
        'label'   => 'AllowOverride FileInfo active',
        'ok'      => $overrideOk,
        'value'   => $overrideOk ? 'working' : 'not set',
        'fix'     => 'Add this block to your Apache virtual host config, then: sudo systemctl reload apache2',
        'snippet' => "<Directory {$fsPath}>\n    AllowOverride FileInfo\n</Directory>",
    ];

    return $results;
}

// ── already installed? ────────────────────────────────────────────────────────

if (file_exists($configDir . '/database.php') && file_exists($lockFile)) {
    redirect(appBaseUrl() . '/');
}

// ── step routing & POST handlers ──────────────────────────────────────────────

$step   = $_GET['step'] ?? 'requirements';
$errors = [];

// ── POST: database step ───────────────────────────────────────────────────────

if ($step === 'db' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'Host, database name, and username are all required.';
    } else {
        try {
            $testPdo = tryConnect($dbHost, $dbName, $dbUser, $dbPass);
            $testPdo->query('SELECT 1');
            // Detect whether this database already contains Steward data.
            $dbHasData = false;
            try {
                $dbHasData = (int)$testPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
            } catch (PDOException $e) {
                // Table absent = fresh database; leave $dbHasData false.
            }
            unset($testPdo);
            $_SESSION['mm_setup']['db']          = compact('dbHost', 'dbName', 'dbUser', 'dbPass');
            $_SESSION['mm_setup']['db_has_data'] = $dbHasData;
            redirect(setupUrl('settings'));
        } catch (PDOException $e) {
            $errors[] = 'Connection failed: ' . $e->getMessage();
        }
    }
    // fall through to re-render the database form with errors
}

// ── POST: settings step ───────────────────────────────────────────────────────

if ($step === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['mm_setup']['db'])) {
        redirect(setupUrl('db'));
    }

    $instanceName = trim($_POST['instance_name'] ?? '');
    $basePath     = trim(trim($_POST['base_path'] ?? ''), '/');
    $basePath     = ($basePath === '') ? '' : '/' . $basePath;
    $timezone     = trim($_POST['timezone'] ?? 'America/New_York');
    // Disallow sample data when the database already contains existing data.
    $dbHasData  = $_SESSION['mm_setup']['db_has_data'] ?? false;
    $sampleData = !$dbHasData && !empty($_POST['sample_data']);

    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $errors[] = "Unknown timezone \"{$timezone}\". See php.net/timezones for valid values.";
    }

    if (empty($errors)) {
        $_SESSION['mm_setup']['settings'] = compact('instanceName', 'basePath', 'timezone', 'sampleData');
        redirect(setupUrl('install'));
    }
    // fall through to re-render settings form with errors
}

// ── GET: install step ─────────────────────────────────────────────────────────

if ($step === 'install') {
    $db  = $_SESSION['mm_setup']['db']       ?? null;
    $cfg = $_SESSION['mm_setup']['settings'] ?? null;

    if (!$db || !$cfg) {
        redirect(setupUrl('db'));
    }

    $dbHasData = $_SESSION['mm_setup']['db_has_data'] ?? false;

    // Idempotent: already installed just means we need to redirect
    if (file_exists($lockFile)) {
        redirect(setupUrl('done'));
    }

    $installOk = true;

    // 1. Verify DB connection
    try {
        $pdo = tryConnect($db['dbHost'], $db['dbName'], $db['dbUser'], $db['dbPass']);
    } catch (PDOException $e) {
        $errors[] = 'Database connection failed: ' . $e->getMessage();
        $errors[] = 'Go back to the Database step and re-enter your credentials.';
        $installOk = false;
    }

    // 2. Bring the schema up to date.
    //    A database that already has data is only ever migrated — install/schema.sql
    //    DROPs every core table before recreating it, which would destroy that data.
    //    A fresh database gets the full baseline schema.
    if ($installOk && $dbHasData) {
        $migErrors = applyPendingMigrations($pdo, $appRoot);
        if (!empty($migErrors)) {
            array_unshift($migErrors, 'Migration errors:');
            $errors = array_merge($errors, $migErrors);
            $installOk = false;
        }
    } elseif ($installOk) {
        if (!file_exists($schemaFile)) {
            $errors[] = "Schema file not found: {$schemaFile}";
            $installOk = false;
        } else {
            $sqlErrors = importSql($pdo, $schemaFile);
            if (!empty($sqlErrors)) {
                array_unshift($sqlErrors, 'Schema import errors:');
                $errors = array_merge($errors, $sqlErrors);
                $installOk = false;
            }
        }
    }

    // 3. Seed schema_migrations so the upgrade system doesn't flag existing migrations as pending.
    //    install/schema.sql already incorporates every migration's changes, so mark them all applied.
    //    (Not needed on the existing-data path — applyPendingMigrations() records each one as it runs.)
    if ($installOk && !$dbHasData) {
        $migDir = $appRoot . '/sql/migrations';
        foreach (glob($migDir . '/*.sql') ?: [] as $f) {
            if (preg_match('/(\d+)_/', basename($f), $m)) {
                $pdo->prepare(
                    'INSERT IGNORE INTO schema_migrations (version, filename, applied_at) VALUES (?, ?, NOW())'
                )->execute([(int)$m[1], basename($f)]);
            }
        }
    }

    // 4. Write instance name to settings table (non-fatal)
    if ($installOk && !empty($cfg['instanceName'])) {
        try {
            $pdo->prepare(
                "INSERT INTO settings (key_name, value) VALUES ('instance_name', ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            )->execute([$cfg['instanceName']]);
        } catch (PDOException $e) { /* non-fatal */ }
    }

    // 5. Optional sample data (non-fatal — log errors but continue)
    if ($installOk && $cfg['sampleData'] && file_exists($sampleDataFile)) {
        importSql($pdo, $sampleDataFile);
    }

    // 6. Write configuration files
    if ($installOk) {
        $writeError = writeConfigFiles($db, $cfg, $configDir);
        if ($writeError !== null) {
            $errors[] = $writeError;
            $installOk = false;
        }
    }

    // 7. Create lock file and redirect to done
    if ($installOk) {
        file_put_contents($lockFile, date('c'));
        $_SESSION['mm_setup']['done'] = [
            'appUrl'    => appBaseUrl(),
            'hasSample' => $cfg['sampleData'],
        ];
        redirect(setupUrl('done'));
    }

    // If we reach here, installation failed — fall through to render with $errors
}

// ── compute step number for the progress indicator ────────────────────────────

$stepNumbers = ['requirements' => 1, 'db' => 2, 'settings' => 3, 'install' => 3, 'done' => 4];
$currentStep = $stepNumbers[$step] ?? 1;

$pageTitles = [
    'requirements' => 'Requirements',
    'db'           => 'Database Connection',
    'settings'     => 'Application Settings',
    'install'      => 'Installing',
    'done'         => 'Installation Complete',
];
$pageTitle = $pageTitles[$step] ?? 'Setup';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — Steward Setup</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
     font-size:15px;background:#eef1f6;color:#1a1a2e;min-height:100vh}

.topbar{background:#002f6c;padding:16px 24px;display:flex;align-items:center;gap:12px;
        box-shadow:0 2px 8px rgba(0,0,0,.35)}
.topbar-icon{width:34px;height:34px;background:#fff;border-radius:7px;display:flex;
             align-items:center;justify-content:center;flex-shrink:0}
.topbar-icon svg{width:22px;height:22px;fill:#002f6c}
.topbar-name{color:#fff;font-size:17px;font-weight:700;letter-spacing:.2px}
.topbar-sub{color:rgba(255,255,255,.5);font-size:12px;margin-left:auto}

.steps-bar{background:#001e4a;padding:12px 0;display:flex;justify-content:center}
.steps-inner{display:flex;align-items:center}
.step-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;
          justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
.step-name{font-size:12px;margin:0 10px;white-space:nowrap}
.step-line{width:36px;height:2px;background:rgba(255,255,255,.15)}
.step-done .step-dot{background:#28a745;color:#fff}
.step-done .step-name{color:rgba(255,255,255,.65)}
.step-active .step-dot{background:#fff;color:#002f6c}
.step-active .step-name{color:#fff;font-weight:600}
.step-future .step-dot{background:transparent;color:rgba(255,255,255,.3);
                        border:2px solid rgba(255,255,255,.2)}
.step-future .step-name{color:rgba(255,255,255,.3)}

.wrap{display:flex;justify-content:center;padding:40px 16px 64px}
.card{background:#fff;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.1);
      width:100%;max-width:520px}
.card-head{background:#f4f7fb;border-bottom:1px solid #dde3ed;padding:20px 26px;
           display:flex;align-items:center;gap:12px;border-radius:10px 10px 0 0}
.card-head-icon{width:38px;height:38px;background:#002f6c;border-radius:8px;
                display:flex;align-items:center;justify-content:center;flex-shrink:0}
.card-head-icon svg{width:20px;height:20px;fill:#fff}
.card-head h1{font-size:17px;font-weight:700;color:#002f6c}
.card-body{padding:26px}

/* checks */
.checks{list-style:none;display:flex;flex-direction:column;gap:10px}
.chk{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;
     border-radius:7px;border:1px solid}
.chk.ok{background:#f0faf3;border-color:#c3e6cb}
.chk.fail{background:#fff5f5;border-color:#f5c2c7}
.chk-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;
          justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;margin-top:1px}
.chk.ok   .chk-icon{background:#28a745;color:#fff}
.chk.fail .chk-icon{background:#dc3545;color:#fff}
.chk-title{font-weight:600;font-size:14px}
.chk-val{font-size:12px;color:#666;margin-top:2px}
.chk-fix{font-size:12px;color:#c0392b;margin-top:4px;font-family:'Consolas','Courier New',monospace}
.chk-snippet{font-size:11px;background:#fff3f3;border:1px solid #f5c2c7;border-radius:5px;padding:8px 10px;margin-top:6px;color:#4a1010;white-space:pre;font-family:'Consolas','Courier New',monospace}

/* form */
.fg{margin-bottom:20px}
.fg label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:6px}
.fg input[type=text],.fg input[type=password]{
    width:100%;padding:10px 13px;border:1px solid #cdd4de;border-radius:7px;
    font-size:14px;color:#1a1a2e;outline:none;transition:border-color .15s,box-shadow .15s}
.fg input:focus{border-color:#0057b7;box-shadow:0 0 0 3px rgba(0,87,183,.14)}
.fg .hint{font-size:12px;color:#777;margin-top:5px;line-height:1.5}
.fg .hint code{background:#eef1f6;padding:1px 5px;border-radius:4px}
hr.sep{border:none;border-top:1px solid #eaedf2;margin:24px 0}

/* checkbox option */
.opt{display:flex;align-items:flex-start;gap:10px;padding:14px;
     background:#f4f7fb;border-radius:8px;border:1px solid #dde3ed;cursor:pointer}
.opt input[type=checkbox]{width:16px;height:16px;margin-top:3px;cursor:pointer;accent-color:#002f6c}
.opt-label strong{display:block;font-size:14px;font-weight:600;color:#1a1a2e;margin-bottom:3px}
.opt-label span{font-size:12px;color:#666;line-height:1.5}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:7px;
     font-size:14px;font-weight:600;cursor:pointer;border:none;text-decoration:none;
     transition:filter .15s,transform .1s}
.btn:active{transform:translateY(1px)}
.btn-primary{background:#0057b7;color:#fff}
.btn-primary:hover{filter:brightness(1.1)}
.btn-success{background:#28a745;color:#fff}
.btn-success:hover{filter:brightness(1.08)}
.btn-ghost{background:#fff;color:#0057b7;border:1px solid #c5d6ee}
.btn-ghost:hover{background:#f0f6ff}
.btn-row{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:26px}

/* alerts */
.alert{padding:12px 16px;border-radius:7px;font-size:14px;margin-bottom:20px;line-height:1.6}
.alert-danger{background:#fff5f5;border:1px solid #f5c2c7;color:#721c24}
.alert-danger ul{margin:6px 0 0 18px}
.alert-warning{background:#fffbf0;border:1px solid #ffd88a;color:#7a5800}
.alert-success{background:#f0faf3;border:1px solid #c3e6cb;color:#155724}
.alert-info{background:#f0f7ff;border:1px solid #b8d4f5;color:#0c3680}

/* done page */
.done-wrap{text-align:center;padding:10px 0 24px}
.done-circle{width:68px;height:68px;background:#28a745;border-radius:50%;
             display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
.done-circle svg{width:36px;height:36px;fill:#fff}
.done-wrap h2{font-size:22px;font-weight:700;color:#002f6c;margin-bottom:8px}
.done-wrap > p{color:#555;font-size:14px;margin-bottom:24px}
.creds{background:#f4f7fb;border:1px solid #dde3ed;border-radius:8px;
       padding:16px 20px;text-align:left;margin-bottom:20px}
.creds h4{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;
          color:#888;margin-bottom:10px}
.cred-row{display:flex;justify-content:space-between;padding:6px 0;
          border-bottom:1px solid #eaedf2;font-size:14px}
.cred-row:last-child{border:none}
.cred-row strong{color:#1a1a2e}
.cred-row code{background:#e4e9f5;padding:2px 8px;border-radius:4px;
               font-size:13px;font-family:'Consolas','Courier New',monospace}
.warn-box{background:#fffbf0;border:1px solid #ffd88a;border-radius:8px;
          padding:13px 16px;font-size:13px;color:#7a5800;display:flex;gap:10px;
          text-align:left;margin-top:20px}
.warn-box svg{flex-shrink:0;margin-top:1px;fill:#7a5800}
.warn-box code{background:#fff3cd;padding:1px 5px;border-radius:3px;
               font-family:'Consolas','Courier New',monospace;font-size:12px}

/* install progress */
.progress-page{text-align:center;padding:16px 0}
.spinner{width:48px;height:48px;border:4px solid #dde3ed;border-top-color:#0057b7;
         border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 16px}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-icon">
    <svg viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>
  </div>
  <span class="topbar-name">Steward</span>
  <span class="topbar-sub">Setup Wizard</span>
</header>

<?php if ($step !== 'done'): ?>
<nav class="steps-bar" aria-label="Installation steps">
  <div class="steps-inner">
  <?php
  $stepDefs = [1 => 'Requirements', 2 => 'Database', 3 => 'Settings', 4 => 'Complete'];
  $i = 0;
  foreach ($stepDefs as $n => $label):
    $cls = $n < $currentStep ? 'step-done' : ($n === $currentStep ? 'step-active' : 'step-future');
    $icon = ($n < $currentStep) ? '✓' : $n;
    $i++;
  ?>
    <div class="<?= $cls ?>">
      <div style="display:flex;align-items:center">
        <div class="step-dot"><?= $icon ?></div>
        <span class="step-name"><?= $label ?></span>
      </div>
    </div>
    <?php if ($i < count($stepDefs)): ?>
    <div class="step-line"></div>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>
</nav>
<?php endif; ?>

<main class="wrap">
<div class="card">

<?php /* ════════════════════════════════════════════════════════════════════
  STEP 1 — REQUIREMENTS
════════════════════════════════════════════════════════════════════════════ */
if ($step === 'requirements'):
    $checks  = checkRequirements($configDir);
    $allPass = array_reduce($checks, fn($c, $r) => $c && $r['ok'], true);
?>
<div class="card-head">
  <div class="card-head-icon">
    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
  </div>
  <h1>Requirements Check</h1>
</div>
<div class="card-body">
  <ul class="checks">
    <?php foreach ($checks as $r): ?>
    <li class="chk <?= $r['ok'] ? 'ok' : 'fail' ?>">
      <div class="chk-icon"><?= $r['ok'] ? '✓' : '✗' ?></div>
      <div>
        <div class="chk-title"><?= h($r['label']) ?></div>
        <div class="chk-val"><?= h($r['value']) ?></div>
        <?php if (!$r['ok']): ?>
        <div class="chk-fix"><?= h($r['fix']) ?></div>
        <?php if (!empty($r['snippet'])): ?>
        <pre class="chk-snippet"><?= h($r['snippet']) ?></pre>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($allPass): ?>
    <div class="alert alert-success" style="margin-top:18px">All requirements met. Ready to proceed.</div>
    <div class="btn-row">
      <a href="<?= setupUrl('db') ?>" class="btn btn-primary">Continue &rarr;</a>
    </div>
  <?php else: ?>
    <div class="alert alert-danger" style="margin-top:18px">
      Resolve the issues above, then reload this page.
    </div>
  <?php endif; ?>
</div>

<?php /* ════════════════════════════════════════════════════════════════════
  STEP 2 — DATABASE
════════════════════════════════════════════════════════════════════════════ */
elseif ($step === 'db'):
    $prev = $_SESSION['mm_setup']['db'] ?? [];
?>
<div class="card-head">
  <div class="card-head-icon">
    <svg viewBox="0 0 24 24"><path d="M12 3C7 3 3 5.24 3 8v8c0 2.76 4 5 9 5s9-2.24 9-5V8c0-2.76-4-5-9-5zm0 2c4.42 0 7 1.79 7 3s-2.58 3-7 3-7-1.79-7-3 2.58-3 7-3zm7 11c0 1.21-2.58 3-7 3s-7-1.79-7-3v-2.23C6.61 15.55 9.12 16 12 16s5.39-.45 7-1.23V16zm0-5c0 1.21-2.58 3-7 3s-7-1.79-7-3v-2.23C6.61 10.55 9.12 11 12 11s5.39-.45 7-1.23V11z"/></svg>
  </div>
  <h1>Database Connection</h1>
</div>
<div class="card-body">
  <p class="hint" style="margin-bottom:18px;font-size:14px;color:#555">
    Create your MySQL / MariaDB database first, then enter the connection
    details below. Steward will connect to an existing database — it
    does not create one for you.
  </p>

  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><?= h($errors[0]) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= setupUrl('db') ?>" autocomplete="off" novalidate>

    <div class="fg">
      <label for="db_host">Database Host</label>
      <input type="text" id="db_host" name="db_host"
             value="<?= h($prev['dbHost'] ?? 'localhost') ?>" placeholder="localhost" required>
      <div class="hint">Usually <code>localhost</code>. Try <code>127.0.0.1</code> if socket auth fails.</div>
    </div>

    <div class="fg">
      <label for="db_name">Database Name</label>
      <input type="text" id="db_name" name="db_name"
             value="<?= h($prev['dbName'] ?? '') ?>" placeholder="money" required>
    </div>

    <div class="fg">
      <label for="db_user">Database Username</label>
      <input type="text" id="db_user" name="db_user"
             value="<?= h($prev['dbUser'] ?? '') ?>" placeholder="money" required
             autocomplete="username">
    </div>

    <div class="fg">
      <label for="db_pass">Database Password</label>
      <input type="password" id="db_pass" name="db_pass"
             value="<?= h($prev['dbPass'] ?? '') ?>" autocomplete="current-password">
      <div class="hint">Leave blank if the user has no password.</div>
    </div>

    <div class="btn-row">
      <a href="<?= setupUrl() ?>" class="btn btn-ghost">&larr; Back</a>
      <button type="submit" class="btn btn-primary">Test Connection &amp; Continue &rarr;</button>
    </div>
  </form>
</div>

<?php /* ════════════════════════════════════════════════════════════════════
  STEP 3 — SETTINGS
════════════════════════════════════════════════════════════════════════════ */
elseif ($step === 'settings'):
    $prev      = $_SESSION['mm_setup']['settings'] ?? [];
    $autoBase  = detectBasePath();
    $timezones = DateTimeZone::listIdentifiers();
    $dbHasData = $_SESSION['mm_setup']['db_has_data'] ?? false;
?>
<div class="card-head">
  <div class="card-head-icon">
    <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94L2.86 14.52c-.18.14-.23.4-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
  </div>
  <h1>Application Settings</h1>
</div>
<div class="card-body">

  <?php if ($dbHasData): ?>
  <div class="alert alert-warning" style="margin-bottom:20px">
    <strong>Existing data detected.</strong> This database already contains Steward data.
    The schema will be updated if needed, but your existing accounts, transactions, and users
    will not be modified. Sample data import is disabled to prevent duplicates.
  </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><?= h($errors[0]) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= setupUrl('settings') ?>" novalidate>

    <div class="fg">
      <label for="instance_name">Instance Name <span style="font-weight:400;color:#888">(optional)</span></label>
      <input type="text" id="instance_name" name="instance_name"
             value="<?= h($prev['instanceName'] ?? '') ?>" placeholder="My Finances" maxlength="80">
      <div class="hint">Displayed on the login page to distinguish this installation from others.</div>
    </div>

    <div class="fg">
      <label for="base_path">URL Base Path</label>
      <input type="text" id="base_path" name="base_path"
             value="<?= h($prev['basePath'] ?? $autoBase) ?>" placeholder="/money">
      <div class="hint">
        The path after your domain name. Leave blank if Steward is at your
        web root (<code>http://server/</code>).
        Auto-detected: <code><?= h($autoBase ?: '(root)') ?></code>
      </div>
    </div>

    <div class="fg">
      <label for="timezone">Timezone</label>
      <input type="text" id="timezone" name="timezone"
             value="<?= h($prev['timezone'] ?? 'America/New_York') ?>"
             list="tz-list" placeholder="America/New_York" required>
      <datalist id="tz-list">
        <?php foreach ($timezones as $tz): ?>
        <option value="<?= h($tz) ?>">
        <?php endforeach; ?>
      </datalist>
      <div class="hint">Start typing to filter. Full list: <code>php.net/timezones</code>.</div>
    </div>

    <hr class="sep">

    <?php if ($dbHasData): ?>
    <div class="opt" style="opacity:.55;cursor:not-allowed">
      <input type="checkbox" disabled>
      <div class="opt-label">
        <strong>Load sample data</strong>
        <span>Disabled — the database already contains data. Loading sample data would
              create duplicate accounts and transactions.</span>
      </div>
    </div>
    <?php else: ?>
    <label class="opt">
      <input type="checkbox" name="sample_data" value="1"
             <?= !empty($prev['sampleData']) ? 'checked' : '' ?>>
      <div class="opt-label">
        <strong>Load sample data</strong>
        <span>Creates three demo accounts, 60+ categorised transactions, and three
              test users (admin&nbsp;/&nbsp;Admin123!, john&nbsp;/&nbsp;John123!,
              viewer&nbsp;/&nbsp;View123!). Uncheck for a clean installation.</span>
      </div>
    </label>
    <?php endif; ?>

    <div class="btn-row">
      <a href="<?= setupUrl('db') ?>" class="btn btn-ghost">&larr; Back</a>
      <button type="submit" class="btn btn-primary">Install &rarr;</button>
    </div>
  </form>
</div>

<?php /* ════════════════════════════════════════════════════════════════════
  STEP 4 — INSTALL (running or error)
════════════════════════════════════════════════════════════════════════════ */
elseif ($step === 'install'):
?>
<div class="card-head">
  <div class="card-head-icon">
    <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
  </div>
  <h1><?= empty($errors) ? 'Installing…' : 'Installation Failed' ?></h1>
</div>
<div class="card-body">
  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <strong>The following errors occurred:</strong>
      <ul style="margin-top:8px">
        <?php foreach ($errors as $e): ?>
        <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="btn-row">
      <a href="<?= setupUrl('db') ?>" class="btn btn-ghost">&larr; Back to Database</a>
    </div>
  <?php else: ?>
    <div class="progress-page">
      <div class="spinner"></div>
      <p style="color:#555">Setting up your database and writing configuration…</p>
    </div>
    <script>
      // Short delay so the spinner is visible, then re-request this page to run install
      setTimeout(function(){ window.location.reload(); }, 300);
    </script>
  <?php endif; ?>
</div>

<?php /* ════════════════════════════════════════════════════════════════════
  STEP 5 — DONE
════════════════════════════════════════════════════════════════════════════ */
elseif ($step === 'done'):
    $done      = $_SESSION['mm_setup']['done'] ?? [];
    $loginUrl  = rtrim($done['appUrl'] ?? appBaseUrl(), '/') . '/login';
    $hasSample = $done['hasSample'] ?? false;
    unset($_SESSION['mm_setup']); // clean up setup session
?>
<div class="card-body" style="padding-top:34px">
  <div class="done-wrap">
    <div class="done-circle">
      <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
    </div>
    <h2>Installation Complete!</h2>
    <p>Steward has been configured and is ready to use.</p>
    <a href="<?= h($loginUrl) ?>" class="btn btn-success" style="margin:0 auto">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="#fff"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/></svg>
      Log In to Steward
    </a>
  </div>

  <div class="creds">
    <h4>Default Admin Credentials</h4>
    <div class="cred-row"><strong>admin</strong> <code>Admin123!</code></div>
    <?php if ($hasSample): ?>
    <div class="cred-row"><strong>john</strong>   <code>John123!</code></div>
    <div class="cred-row"><strong>viewer</strong> <code>View123!</code></div>
    <?php endif; ?>
  </div>
  <div class="warn-box">
    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
    <div>
      <strong>Security reminder:</strong> Delete the <code>setup/</code> directory
      once you have logged in successfully to prevent anyone from re-running
      the installer.<br>
      <code>rm -rf <?= h(rtrim(__DIR__, '/\\')) ?></code>
    </div>
  </div>
</div>

<?php endif; ?>

</div><!-- /.card -->
</main>

</body>
</html>
