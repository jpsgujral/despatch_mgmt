<?php
/**
 * send_report.php — Standalone email trigger
 * Upload to: /despatch_mgmt/modules/send_report.php
 * Called via AJAX from report pages
 */

// Must be first — before any output
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';  // 'daily' or 'monthly'
$date = $_GET['date'] ?? date('Y-m-d');
$month = $_GET['month'] ?? date('Y-m');

if (!in_array($type, ['daily', 'monthly'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid report type.']);
    exit;
}

if ($type === 'daily' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid report date.']);
    exit;
}

if ($type === 'monthly' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid report month.']);
    exit;
}

$cron_file = dirname(__DIR__) . '/cron/' . ($type === 'monthly' ? 'monthly' : 'daily') . '_report.php';

if (!file_exists($cron_file)) {
    echo json_encode(['ok' => false, 'msg' => 'Cron file not found: ' . $cron_file]);
    exit;
}

function report_email_log_file() {
    $log_dir = dirname(__DIR__) . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0775, true);
    }

    return $log_dir . '/report_email.log';
}

function find_php_cli_binary() {
    $candidates = [
        PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'),
        PHP_BINARY,
        'php',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'php' || is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function append_report_email_log($type, $message) {
    $log = report_email_log_file();
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($type) . '] ' . rtrim((string)$message) . PHP_EOL;
    @file_put_contents($log, $line, FILE_APPEND);
}

function start_report_email_process($cron_file, $type, $date, $month) {
    $php = find_php_cli_binary();
    if (!$php) {
        return [false, 'PHP CLI binary not found.'];
    }

    $arg = $type === 'monthly' ? '--month=' . $month : '--date=' . $date;
    $log = report_email_log_file();
    $header = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($type) . '] STARTED' . PHP_EOL;
    @file_put_contents($log, $header, FILE_APPEND);

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($cron_file) . ' ' . escapeshellarg($arg)
        . ' >> ' . escapeshellarg($log) . ' 2>&1';

    if (PHP_OS_FAMILY === 'Windows') {
        if (!function_exists('popen')) {
            return [false, 'Process start function is disabled.'];
        }
        @pclose(@popen('start /B "" ' . $cmd, 'r'));
    } else {
        if (!function_exists('exec')) {
            return [false, 'Process start function is disabled.'];
        }
        @exec($cmd . ' &');
    }

    return [true, 'Report email started. Check logs/report_email.log for delivery status.'];
}

function run_report_email_inline($cron_file, $type, $date, $month) {
    if (!defined('REPORT_INLINE_MODE')) {
        define('REPORT_INLINE_MODE', true);
    }

    $GLOBALS['REPORT_FORCE_DATE'] = $date;
    $GLOBALS['REPORT_FORCE_MONTH'] = $month;

    ob_start();
    try {
        include $cron_file;
        $output = trim((string)ob_get_clean());
    } catch (Throwable $e) {
        $output = trim((string)ob_get_clean());
        $detail = trim($output . "\n" . $e->getMessage());
        append_report_email_log($type, 'INLINE ERROR: ' . $detail);
        return [false, $detail ?: 'Report email failed.'];
    }

    if ($output !== '') {
        append_report_email_log($type, "INLINE OUTPUT:\n" . $output);
    }

    if (stripos($output, 'OK:') !== false) {
        return [true, $output];
    }

    if (preg_match('/\bsent\b/i', (string)$output)) {
        return [true, $output];
    }

    if (stripos($output, 'ERROR:') !== false) {
        return [false, $output ?: 'Report email failed.'];
    }

    return [true, $output ?: 'Report email sent.'];
}

ignore_user_abort(true);
set_time_limit(60);

// The email send can wait on SMTP. Release the PHP session lock first so
// other app pages/AJAX calls do not hang behind this request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$result = start_report_email_process($cron_file, $type, $date, $month);
if (!$result[0]) {
    append_report_email_log($type, 'BACKGROUND START FAILED: ' . $result[1]);
    $result = run_report_email_inline($cron_file, $type, $date, $month);
}

echo json_encode(['ok' => $result[0], 'msg' => $result[1]]);
exit;
