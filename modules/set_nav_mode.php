<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$mode = $_POST['mode'] ?? 'classic';
if (!in_array($mode, ['classic', 'wide_burger'], true)) {
    $mode = 'classic';
}
$_SESSION['desktop_nav_mode_preview'] = $mode;
$_SESSION['desktop_nav_mode'] = $mode;

$db = getDB();
$safe_mode = $db->real_escape_string($mode);
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid > 0) {
    $db->query("UPDATE app_users SET desktop_nav_mode='$safe_mode' WHERE id=$uid");
}

$redirect_to = (string)($_POST['redirect_to'] ?? '../index.php');
$redirect_to = trim($redirect_to);
if ($redirect_to === '' || preg_match('/^https?:\/\//i', $redirect_to) || strpos($redirect_to, "\n") !== false || strpos($redirect_to, "\r") !== false) {
    $redirect_to = '../index.php';
}
if ($redirect_to[0] !== '/' && strpos($redirect_to, '../') !== 0 && strpos($redirect_to, './') !== 0) {
    $redirect_to = '../' . ltrim($redirect_to, '/');
}

showAlert('success', $mode === 'wide_burger' ? 'Wide menu mode enabled for your account.' : 'Classic sidebar mode enabled for your account.');
header('Location: ' . $redirect_to);
exit;
