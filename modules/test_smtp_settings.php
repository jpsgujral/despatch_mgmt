<?php
ini_set('display_errors', 0);
set_exception_handler(function($e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'msg' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/phpmailer/src/Exception.php';
require_once __DIR__ . '/../includes/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../includes/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$db = getDB();
requirePerm('company_settings', 'view');

header('Content-Type: application/json');

function smtpPlain($value) {
    $value = trim((string)($value ?? ''));
    for ($i = 0; $i < 5; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return $value;
}

function smtpSocketCheck(string $host, int $port, int $timeout = 8): array {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return ['ok' => true, 'msg' => 'TCP connection successful'];
    }
    $msg = trim($errstr ?: ('Socket error ' . $errno));
    return ['ok' => false, 'msg' => $msg, 'code' => $errno];
}

function sendViaMailTransport(string $smtp_user, string $from_name, string $to, string $subject, string $body): bool {
    $mail = new PHPMailer(true);
    $mail->isMail();
    $mail->CharSet = 'UTF-8';
    $mail->ContentType = 'text/html';
    $mail->From = $smtp_user;
    $mail->FromName = $from_name;
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->AltBody = $body;
    return $mail->send();
}

$smtp = $db->query("SELECT company_name, email, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_name
    FROM company_settings LIMIT 1")->fetch_assoc();

$smtp_host = smtpPlain($smtp['smtp_host'] ?? '');
$smtp_user = smtpPlain($smtp['smtp_user'] ?? '');
$smtp_pass = smtpPlain($smtp['smtp_pass'] ?? '');
$smtp_port = (int)($smtp['smtp_port'] ?? 587);
$secure    = strtolower(smtpPlain($smtp['smtp_secure'] ?? 'tls'));
$from_name = smtpPlain($smtp['smtp_from_name'] ?? '') ?: (smtpPlain($smtp['company_name'] ?? '') ?: 'DMS');
$company_email = smtpPlain($smtp['email'] ?? '');
$to = filter_var($company_email, FILTER_VALIDATE_EMAIL) ? $company_email : $smtp_user;

if ($smtp_host === '' || $smtp_user === '' || $smtp_pass === '') {
    echo json_encode(['ok' => false, 'msg' => 'SMTP Host, Username and Password are required.']);
    exit;
}

if (!filter_var($smtp_user, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'msg' => 'Company Email or SMTP Username must be a valid email address.']);
    exit;
}

$socketCheck = smtpSocketCheck($smtp_host, $smtp_port ?: ($secure === 'ssl' ? 465 : 587));
if (!$socketCheck['ok']) {
    try {
        $subject = 'DMS Mail Transport Test - ' . date('d M Y H:i');
        $body = 'Mail transport test email from Despatch Management System at ' . date('Y-m-d H:i:s') . '.';
        sendViaMailTransport($smtp_user, $from_name, $to, $subject, $body);
        echo json_encode([
            'ok' => true,
            'msg' => 'SMTP is unreachable from this server, so test email was sent using server mail transport to ' . $to . '.'
        ]);
        exit;
    } catch (Throwable $e) {
        $detail = $smtp_host . ':' . ($smtp_port ?: ($secure === 'ssl' ? 465 : 587)) . ' - ' . $socketCheck['msg'];
        echo json_encode([
            'ok' => false,
            'msg' => 'Server cannot reach SMTP host (' . $detail . ') and server mail transport also failed: ' . $e->getMessage()
        ]);
        exit;
    }
}

try {
    $mail = new PHPMailer(true);
    $smtp_debug_log = [];
    $mail->isSMTP();
    $mail->CharSet = 'UTF-8';
    $mail->Host = $smtp_host;
    $mail->Port = $smtp_port ?: ($secure === 'ssl' ? 465 : 587);
    $mail->SMTPAuth = true;
    $mail->Username = $smtp_user;
    $mail->Password = $smtp_pass;

    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->Timeout = 20;
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) use (&$smtp_debug_log) {
        $smtp_debug_log[] = trim((string)$str);
    };
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->From = $smtp_user;
    $mail->FromName = $from_name;
    $mail->addAddress($to);
    $mail->Subject = 'DMS SMTP Test - ' . date('d M Y H:i');
    $mail->Body    = 'SMTP test email from Despatch Management System at ' . date('Y-m-d H:i:s') . '.';
    $mail->AltBody = $mail->Body;
    $mail->send();

    echo json_encode(['ok' => true, 'msg' => 'SMTP test email sent to ' . $to . '.']);
} catch (Throwable $e) {
    $msg = $mail->ErrorInfo ?: $e->getMessage();
    try {
        $subject = 'DMS Mail Transport Fallback Test - ' . date('d M Y H:i');
        $body = 'Mail transport fallback test email from Despatch Management System at ' . date('Y-m-d H:i:s') . '.';
        sendViaMailTransport($smtp_user, $from_name, $to, $subject, $body);
        echo json_encode([
            'ok' => true,
            'msg' => 'SMTP authentication failed, but test email was sent using server mail transport to ' . $to . '. SMTP said: ' . $msg
        ]);
        exit;
    } catch (Throwable $fallbackEx) {
        if (stripos($msg, 'authenticate') !== false) {
            $msg .= ' Check SMTP username/password. For Gmail, use an App Password.';
        } elseif (stripos($msg, 'connect') !== false) {
            $msg .= ' Check SMTP host, port and security setting. If those are correct, the server may be blocked from outbound SMTP.';
        }
        $msg .= ' Mail transport fallback also failed: ' . $fallbackEx->getMessage() . '.';
    }
    $debug_tail = '';
    if (!empty($smtp_debug_log)) {
        $tail = array_slice($smtp_debug_log, -4);
        $debug_tail = ' Debug: ' . implode(' | ', $tail);
    }
    echo json_encode(['ok' => false, 'msg' => $msg . $debug_tail]);
}
