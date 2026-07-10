<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_PATH . '/login');
        exit;
    }

    // Idle session timeout
    $timeoutMinutes = (int)getSetting('session_timeout_minutes', '0');
    if ($timeoutMinutes > 0) {
        $lastActivity = $_SESSION['last_activity'] ?? null;
        if ($lastActivity !== null && (time() - $lastActivity) > $timeoutMinutes * 60) {
            logActivity('session_timeout', 'Session expired after ' . $timeoutMinutes . ' min of inactivity');
            session_destroy();
            header('Location: ' . BASE_PATH . '/login?reason=timeout');
            exit;
        }
    }
    $_SESSION['last_activity'] = time();

    $stmt = getDB()->prepare('SELECT is_active FROM users WHERE id = ?');
    $stmt->execute([currentUserId()]);
    if (!$stmt->fetchColumn()) {
        session_destroy();
        header('Location: ' . BASE_PATH . '/login');
        exit;
    }

    checkPendingMigrations();
}

function checkPendingMigrations(): void {
    if (php_sapi_name() === 'cli') return;

    // Allow migration management and auth pages to load regardless
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (in_array($script, ['migrations.php', 'run_migrations.php', 'login.php', 'logout.php'], true)) return;

    // Short-circuit: cached "all clean" for up to 60 seconds to avoid a DB hit on every request
    $now = time();
    if (isset($_SESSION['mig_ok_ts']) && ($now - $_SESSION['mig_ok_ts']) < 60) return;

    $pending = getPendingMigrations();

    if (empty($pending)) {
        $_SESSION['mig_ok_ts'] = $now;
        return;
    }

    // Pending migrations exist — clear cache so every request re-checks until resolved
    unset($_SESSION['mig_ok_ts']);

    $count = count($pending);
    $noun  = $count === 1 ? 'migration' : 'migrations';

    // AJAX / JSON requests get a machine-readable error instead of a redirect
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
              || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => "Database update required ($count pending $noun). Visit Settings → Migrations."]);
        exit;
    }

    if (isAdmin()) {
        setFlash('warning', "$count pending database $noun must be applied before using the application.");
        header('Location: ' . BASE_PATH . '/settings/migrations');
        exit;
    }

    // Non-admin / viewer: show a simple maintenance screen
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Maintenance — Steward</title>'
       . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f7fb;'
       . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.box{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);padding:48px;'
       . 'max-width:420px;text-align:center}'
       . 'h1{font-size:1.3rem;color:#1a3a5c;margin:16px 0 10px}'
       . 'p{color:#6b7280;line-height:1.6;margin:0}</style></head>'
       . '<body><div class="box"><div style="font-size:3rem">🔧</div>'
       . '<h1>Maintenance in Progress</h1>'
       . '<p>A database update is being applied. Please try again in a few minutes.<br>'
       . 'Contact your administrator if this persists.</p>'
       . '</div></body></html>';
    exit;
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/../includes/header.php';
        echo '<div class="alert alert-danger m-4">Access denied. Insufficient permissions.</div>';
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentUserRole(): string {
    return $_SESSION['user_role'] ?? '';
}

function isAdmin(): bool {
    return currentUserRole() === 'administrator';
}

function canEdit(): bool {
    return in_array(currentUserRole(), ['user', 'administrator'], true);
}

function canDelete(): bool {
    if (isAdmin()) return true;
    return getSetting('users_can_delete_transactions', '1') === '1';
}

function canManageBudgets(): bool {
    if (isAdmin()) return true;
    return getSetting('users_can_manage_budgets', '1') === '1';
}

function login(string $username, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, username, password_hash, full_name, role, is_active FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Regenerate session ID on login to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['user_fullname'] = $user['full_name'];
    $_SESSION['user_role']     = $user['role'];

    // Update last login
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

    logActivity('login', 'Signed in', (int)$user['id'], $user['full_name'] ?: $user['username']);

    return true;
}

function logout(): void {
    logActivity('logout', 'Signed out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
