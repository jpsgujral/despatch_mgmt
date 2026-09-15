<?php
// Live server template. Copy this as sync_config.php on live server.
define('SYNC_VERSION', '3.0.0-local-backup');
define('THIS_SITE', 'live');
define('SYNC_SECRET', getenv('TSG_SYNC_SECRET') ?: 'CHANGE_THIS_LONG_RANDOM_SECRET');

define('DB_HOST', 'localhost');
define('DB_NAME', 'tsgimpex_despatch_mgmt');
define('DB_USER', 'tsgimpex_tsg');
define('DB_PASS', ';l%r07dDBIgeUBrr');

// Not used on live in read-only export mode.
define('REMOTE_SYNC_URL', '');

define('SYNC_BATCH_SIZE', 500);
define('SYNC_TIMEOUT', 60);
define('SYNC_LOG_FILE', __DIR__ . '/sync/logs/live_export.log');
define('SYNC_LOCK_FILE', __DIR__ . '/sync/logs/live_export.lock');
define('ALLOW_LIVE_WRITES', false);
define('LOCAL_REPLACE_TABLES', false);

require __DIR__ . '/sync_tables.php';
