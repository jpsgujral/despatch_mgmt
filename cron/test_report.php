<?php
echo "<pre>";
echo "PHP Version: " . phpversion() . "\n";
echo "Current Dir: " . __DIR__ . "\n";
echo "Config Path: " . dirname(__DIR__) . "/includes/config.php\n";
echo "Config Exists: " . (file_exists(dirname(__DIR__) . '/includes/config.php') ? 'YES' : 'NO') . "\n";

$paths = [
    dirname(__DIR__) . '/vendor/PHPMailer/src/PHPMailer.php',
    dirname(__DIR__) . '/includes/phpmailer/src/PHPMailer.php',
    dirname(__DIR__) . '/includes/phpmailer/PHPMailer.php',
];
echo "\nPHPMailer search:\n";
foreach ($paths as $p) {
    echo "  $p => " . (file_exists($p) ? 'FOUND' : 'not found') . "\n";
}

try {
    require_once dirname(__DIR__) . '/includes/config.php';
    $db = getDB();
    echo "\nDB Connection: OK\n";
    $smtp = $db->query("SELECT smtp_host, smtp_user FROM company_settings LIMIT 1")->fetch_assoc();
    echo "SMTP Host: " . ($smtp['smtp_host'] ?: 'NOT SET') . "\n";
    echo "SMTP User: " . ($smtp['smtp_user'] ?: 'NOT SET') . "\n";
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
}
echo "</pre>";
