<?php
// ============================================================
//  TSGImpex Sync Runner — for Cron (Linux) / Task Scheduler (Windows)
//
//  LINUX CRON (every 30 min):
//    */30 * * * * php /var/www/html/sync/sync_run.php >> /dev/null 2>&1
//
//  WINDOWS TASK SCHEDULER:
//    Program: C:\xampp\php\php.exe
//    Arguments: C:\xampp\htdocs\tsgimpex\sync\sync_run.php
//    Schedule: Every 30 minutes
// ============================================================

// Only allow CLI execution (not via browser)
if (php_sapi_name() !== 'cli') {
    // Allow web trigger only with secret param (for manual trigger from dashboard)
    if (($_GET['secret'] ?? '') !== SYNC_SECRET) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

require_once __DIR__ . '/../sync_config.php';
require_once __DIR__ . '/sync_engine.php';

// Re-allow web trigger now config is loaded
if (php_sapi_name() !== 'cli' && ($_GET['secret'] ?? '') !== SYNC_SECRET) {
    http_response_code(403); echo "Forbidden\n"; exit;
}

echo "[" . date('Y-m-d H:i:s') . "] TSGImpex Sync starting...\n";

$result = SyncEngine::fullSync();

if (isset($result['error'])) {
    echo "ERROR: " . $result['error'] . "\n";
    exit(1);
}

foreach ($result['tables'] as $table => $stat) {
    $errs = empty($stat['errors']) ? 'OK' : 'ERRORS: ' . implode(', ', $stat['errors']);
    echo "  $table — pushed: {$stat['pushed']}, pulled: {$stat['pulled']} | $errs\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Sync complete.\n";
