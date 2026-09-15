#!/usr/local/bin/ea-php81
<?php
/* ═══════════════════════════════════════════════════════════════
   backup.php — Cron entry point for automated backups
   File: backup/backup.php

   Cron (daily at 2:00 AM) — add in cPanel → Cron Jobs:
   0 2 * * * /usr/local/bin/ea-php81 /home/tsgimpex/public_html/despatch_mgmt/backup/backup.php >> /home/tsgimpex/backup_tmp/backup.log 2>&1
   ═══════════════════════════════════════════════════════════════ */

define('CLI_BACKUP', true);
set_time_limit(600);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/BackupManager.php';

echo "\n========================================\n";
echo "TSG DMS Backup — " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

$mgr = new BackupManager();
$log = $mgr->runFullBackup();

$hasError = false;
foreach ($log as $entry) {
    $tag = $entry['level'] === 'error' ? '[ERROR]' : '[  OK ]';
    echo "[{$entry['time']}] {$tag} {$entry['msg']}\n";
    if ($entry['level'] === 'error') $hasError = true;
}

echo "========================================\n\n";
exit($hasError ? 1 : 0);
