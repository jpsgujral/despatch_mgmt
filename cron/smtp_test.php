<?php
define('CRON_MODE', true);
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
$db = getDB();

$smtp = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure FROM company_settings LIMIT 1")->fetch_assoc();

echo "Host: " . $smtp['smtp_host'] . "\n";
echo "Port: " . $smtp['smtp_port'] . "\n";
echo "User: " . $smtp['smtp_user'] . "\n";
echo "Secure: " . $smtp['smtp_secure'] . "\n\n";

require_once $base_path . '/includes/phpmailer/src/Exception.php';
require_once $base_path . '/includes/phpmailer/src/PHPMailer.php';
require_once $base_path . '/includes/phpmailer/src/SMTP.php';

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$mail->SMTPDebug = 2;
$mail->isSMTP();
$mail->Host       = $smtp['smtp_host'];
$mail->SMTPAuth   = !empty($smtp['smtp_pass']);
$mail->Username   = $smtp['smtp_user'];
$mail->Password   = $smtp['smtp_pass'];

if ($smtp['smtp_secure'] === 'ssl') {
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
} elseif ($smtp['smtp_secure'] === 'tls') {
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
} else {
    $mail->SMTPSecure = '';
    $mail->SMTPAutoTLS = false;
}
$mail->Port = (int)$smtp['smtp_port'];

// Bypass SSL cert verification
$mail->SMTPOptions = [
    'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]
];

$mail->setFrom($smtp['smtp_user'], 'DMS Test');
$mail->addAddress($smtp['smtp_user']);
$mail->Subject = 'SMTP Test - DMS';
$mail->Body    = 'Test email from DMS - ' . date('Y-m-d H:i:s');

try {
    $mail->send();
    echo "\nSUCCESS: Email sent!\n";
} catch (Exception $e) {
    echo "\nFAILED: " . $mail->ErrorInfo . "\n";
}
