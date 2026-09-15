<?php
// Simple one-way database pull.
// Database name is the same on live and local phpMyAdmin/XAMPP.

define('SYNC_VERSION', 'simple-pull-1.0');
define('DB_NAME', 'tsgimpex_despatch_mgmt');

// Change this to 'live' on the live server copy.
// Keep this as 'local' on the XAMPP/local copy.
define('local', 'local');

// Use the same secret on live and local. Change before uploading.
define('SYNC_SECRET', 'XU7w5+g@@wkv');

// Local or live DB credentials for the machine where this file is placed.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

// Local only: URL of live read-only API.
define('LIVE_SYNC_API_URL', 'https://tsgimpex.com/despatch_mgmt/tsgimpex-sync/sync_api.php');

define('SYNC_BATCH_SIZE', 500);
define('SYNC_TIMEOUT', 60);
define('SYNC_LOG_FILE', __DIR__ . '/logs/pull.log');

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}
