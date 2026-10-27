<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/settings/preferences');
    exit;
}

// Detect post_max_size overflow: when exceeded, PHP empties $_POST and $_FILES entirely,
// which makes the CSRF check fail with a confusing error. Catch it explicitly first.
$maxLabel = round(uploadMaxBytes() / 1048576, 1) . ' MB';
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    setFlash('error', "The image is too large. The maximum upload size is {$maxLabel}.");
    header('Location: ' . BASE_PATH . '/settings/preferences');
    exit;
}
verifyCsrf();

$dest   = __DIR__ . '/../assets/img/login_bg_custom.jpg';
$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    if (file_exists($dest)) {
        unlink($dest);
    }
    setSetting('login_bg', null);
    setFlash('success', 'Login background image removed.');
    header('Location: ' . BASE_PATH . '/settings/preferences');
    exit;
}

if ($action === 'upload') {
    $file = $_FILES['login_bg_image'] ?? null;
    if ($file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        setFlash('error', "The image is too large. The maximum upload size is {$maxLabel}.");
        header('Location: ' . BASE_PATH . '/settings/preferences');
        exit;
    }
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Upload failed. Please try again.');
        header('Location: ' . BASE_PATH . '/settings/preferences');
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        setFlash('error', 'Invalid file type. Please upload a JPEG, PNG, or WebP image.');
        header('Location: ' . BASE_PATH . '/settings/preferences');
        exit;
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        setFlash('error', 'Could not save the image. Please check file permissions.');
        header('Location: ' . BASE_PATH . '/settings/preferences');
        exit;
    }

    setSetting('login_bg', (string)time());
    setFlash('success', 'Login background image updated.');
    header('Location: ' . BASE_PATH . '/settings/preferences');
    exit;
}

header('Location: ' . BASE_PATH . '/settings/preferences');
exit;
