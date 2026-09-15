<?php
// Simple image server for local uploads
$allowed_dir = dirname(__DIR__) . '/uploads/';
$path = $_GET['f'] ?? '';

// Security: no path traversal, only images
if (empty($path) || strpos($path, '..') !== false || strpos($path, '/') === 0) {
    http_response_code(400); exit;
}

$abs = $allowed_dir . $path;
if (!file_exists($abs)) { http_response_code(404); exit; }

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match($ext) {
    'jpg','jpeg' => 'image/jpeg',
    'png'        => 'image/png',
    'gif'        => 'image/gif',
    'webp'       => 'image/webp',
    default      => null
};
if (!$mime) { http_response_code(403); exit; }

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=86400');
readfile($abs);
