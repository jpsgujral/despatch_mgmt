<?php
require_once __DIR__ . '/includes/config.php';
// Simple password protection
if (($_GET['key'] ?? '') !== 'tsg2024debug') {
    die('Access denied');
}
$db = getDB();
$users = $db->query("SELECT id, username, role, status, permissions FROM app_users")->fetch_all(MYSQLI_ASSOC);
echo "<pre style='font-family:monospace;font-size:13px;padding:20px'>";
foreach ($users as $u) {
    echo "ID: {$u['id']} | User: {$u['username']} | Role: {$u['role']} | Status: {$u['status']}\n";
    echo "Raw permissions from DB:\n";
    echo "  " . var_export($u['permissions'], true) . "\n";
    $decoded = json_decode($u['permissions'] ?? '{}', true);
    echo "Decoded permissions:\n";
    print_r($decoded);
    echo "\n" . str_repeat('-', 60) . "\n";
}
echo "</pre>";
