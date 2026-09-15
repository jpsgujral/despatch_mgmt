<?php
// ============================================================
//  TSGImpex Watchdog Runner — LOCAL XAMPP ONLY
//  Checks live server health every 5 minutes.
//  If live is down   → logs the outage, stays offline.
//  If live comes up  → replays queued changes, then full sync.
//
//  WINDOWS TASK SCHEDULER SETUP:
//    Program : C:\xampp\php\php.exe
//    Arguments: C:\xampp\htdocs\tsgimpex\sync\watchdog_run.php
//    Schedule : Every 5 minutes
//
//  LINUX CRON (if running XAMPP on Linux):
//    */5 * * * * php /opt/lampp/htdocs/tsgimpex/sync/watchdog_run.php
// ============================================================

if (php_sapi_name() !== 'cli') {
    // Allow manual web trigger with secret
    parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
    if (($qs['secret'] ?? '') !== @constant('SYNC_SECRET')) {
        // Config not loaded yet, load it and recheck
        require_once __DIR__ . '/../sync_config.php';
        if (($qs['secret'] ?? '') !== SYNC_SECRET) {
            http_response_code(403); echo "Forbidden\n"; exit;
        }
    }
    header('Content-Type: text/plain');
}

require_once __DIR__ . '/../sync_config.php';
require_once __DIR__ . '/sync_engine.php';
require_once __DIR__ . '/outage_manager.php';

// Only run on local XAMPP
if (THIS_SITE !== 'local') {
    echo "Watchdog only runs on local XAMPP. Set THIS_SITE=local in sync_config.php\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Watchdog checking live server...\n";

$result = OutageManager::watchdog();

if (!$result['live_up']) {
    echo "⚠  LIVE SERVER IS DOWN — offline mode active. Changes will be queued.\n";
    $q = OutageManager::getQueueStats();
    echo "   Queue: {$q['pending']} pending, {$q['replayed']} replayed, {$q['failed']} failed\n";
    exit(0);
}

echo "✓  Live server is UP\n";

if ($result['replayed'] > 0 || $result['failed'] > 0) {
    echo "   Queue replay: {$result['replayed']} sent, {$result['failed']} failed\n";
}

if ($result['regular_sync']) {
    echo "   Regular two-way sync completed\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Watchdog done.\n";
