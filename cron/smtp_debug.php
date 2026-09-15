<?php
/**
 * SMTP Debug Test
 * Run once to diagnose auth failure, then DELETE this file from server
 */
define('CRON_MODE', true);
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
$db = getDB();

$smtp = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_name
    FROM company_settings LIMIT 1")->fetch_assoc();

echo "=== SMTP Settings from DB ===\n";
echo "Host:    " . $smtp['smtp_host'] . "\n";
echo "Port:    " . $smtp['smtp_port'] . "\n";
echo "User:    " . $smtp['smtp_user'] . "\n";
echo "Pass:    [" . $smtp['smtp_pass'] . "] (length=" . strlen($smtp['smtp_pass']) . ")\n";
echo "Secure:  " . $smtp['smtp_secure'] . "\n";
echo "\n";

// Check PHPMailer
$phpmailer_paths = [
    $base_path . '/includes/phpmailer/src/PHPMailer.php',
    $base_path . '/includes/PHPMailer/src/PHPMailer.php',
];
$phpmailer_path = null;
foreach ($phpmailer_paths as $p) {
    if (file_exists($p)) { $phpmailer_path = $p; break; }
}

if (!$phpmailer_path) {
    die("ERROR: PHPMailer not found\n");
}
echo "PHPMailer: $phpmailer_path\n\n";

$phpmailer_smtp = str_replace('PHPMailer.php','SMTP.php',$phpmailer_path);
$phpmailer_exc  = str_replace('PHPMailer.php','Exception.php',$phpmailer_path);
require_once $phpmailer_exc;
require_once $phpmailer_path;
require_once $phpmailer_smtp;

echo "=== Attempting SMTP connection ===\n";

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$mail->SMTPDebug  = 2; // Full debug output
$mail->Debugoutput = function($str, $level) {
    echo date('H:i:s') . " [$level] $str\n";
};

try {
    $mail->isSMTP();
    $mail->Host       = $smtp['smtp_host'];
    $mail->Port       = (int)($smtp['smtp_port'] ?: 587);
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp['smtp_user'];
    $mail->Password   = trim($smtp['smtp_pass']); // trim in case of whitespace
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ];
    $mail->setFrom($smtp['smtp_user'], 'SMTP Test');
    $mail->addAddress($smtp['smtp_user']); // send test to self
    $mail->Subject = 'SMTP Test ' . date('Y-m-d H:i:s');
    $mail->Body    = 'SMTP test email from TSG Despatch cron.';
    $mail->send();
    echo "\n=== SUCCESS: Test email sent to " . $smtp['smtp_user'] . " ===\n";
} catch (Exception $e) {
    echo "\n=== FAILED: " . $mail->ErrorInfo . " ===\n";
}
