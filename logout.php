<?php
require_once __DIR__ . '/includes/config.php';
$display_name = trim((string)($_SESSION['full_name'] ?? '')) ?: (string)($_SESSION['username'] ?? 'friend');

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

session_start();
showAlert('info', '<i class="bi bi-heart me-2"></i>Logged out successfully. Take care, <strong>' . htmlspecialchars($display_name) . '</strong> — see you soon.');
header('Location: login.php'); exit;
