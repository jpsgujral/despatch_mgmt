<?php
/* ═══════════════════════════════════════════════════════════════
   BackupManager.php — Backup/Restore engine
   File: backup/BackupManager.php

   Uses the Cloudflare Worker relay (same as r2_helper.php).
   Backups stored under:  backups/db/  and  backups/app/
   (Worker prepends dms_uploads/, so final R2 path is
    dms_uploads/backups/db/...  and  dms_uploads/backups/app/...)
   ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/r2_helper.php';

// Retention: backups older than this are auto-purged
if (!defined('BACKUP_RETAIN_DAYS')) define('BACKUP_RETAIN_DAYS', 30);

// Staging area for dump/zip files (outside public_html, writable)
if (!defined('BACKUP_TMP_DIR')) define('BACKUP_TMP_DIR', '/home/tsgimpex/backup_tmp');

class BackupManager
{
    private string $tmpDir;
    private array  $log = [];

    public function __construct()
    {
        $this->tmpDir = rtrim(BACKUP_TMP_DIR, '/');
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0750, true);
        }
    }

    // ----------------------------------------------------------------
    // PUBLIC API
    // ----------------------------------------------------------------

    /** Full backup: DB dump + app zip → upload to R2. Returns log array. */
    public function runFullBackup(): array
    {
        $this->log = [];
        if ($this->isPaused()) {
            $this->info("Backup is paused. No backup was created.");
            return $this->log;
        }

        $ts        = date('Ymd_His');
        $prefix    = "backup_{$ts}";

        try {
            // 1. DB dump + gzip
            $sqlFile = $this->dumpDatabase($prefix);
            $this->info("DB dump created.");
            $dbGz = $this->gzipFile($sqlFile);
            @unlink($sqlFile);
            $this->info("DB compressed: " . basename($dbGz));

            // 2. App zip
            $appZip = $this->zipAppFiles($prefix);
            $this->info("App zipped: " . basename($appZip));

            // 3. Upload via Worker
            $dbKey  = "backups/db/{$prefix}_db.sql.gz";
            $appKey = "backups/app/{$prefix}_app.zip";

            if (!r2_upload($dbGz, $dbKey, 'application/octet-stream')) {
                throw new Exception("R2 upload failed for DB backup.");
            }
            $this->info("DB uploaded → {$dbKey}");

            if (!r2_upload($appZip, $appKey, 'application/octet-stream')) {
                throw new Exception("R2 upload failed for App backup.");
            }
            $this->info("App uploaded → {$appKey}");

            // 4. Cleanup tmp
            @unlink($dbGz);
            @unlink($appZip);
            $this->info("Temp files cleaned.");

            // 5. Prune old backups
            $pruned = $this->pruneOldBackups();
            if ($pruned > 0) $this->info("Pruned {$pruned} old backup(s) from R2.");

            $this->info("✅ Backup complete: {$prefix}");

        } catch (Exception $e) {
            $this->error("❌ Backup FAILED: " . $e->getMessage());
            foreach (glob($this->tmpDir . "/{$prefix}*") ?: [] as $f) @unlink($f);
        }

        return $this->log;
    }

    /**
     * List all backups grouped by prefix (newest first).
     * Returns: [ 'backup_20260324_020000' => ['db'=>[key,size,last_modified], 'app'=>[...], 'date'=>..., 'size'=>...] ]
     */
    public function listBackups(): array
    {
        $dbObjects  = $this->workerList('backups/db/');
        $appObjects = $this->workerList('backups/app/');

        $grouped = [];
        foreach ($dbObjects as $obj) {
            $p = $this->extractPrefix($obj['key']);
            $grouped[$p]['db']   = $obj;
            $grouped[$p]['date'] = $obj['last_modified'];
            $grouped[$p]['size'] = ($grouped[$p]['size'] ?? 0) + (int)$obj['size'];
        }
        foreach ($appObjects as $obj) {
            $p = $this->extractPrefix($obj['key']);
            $grouped[$p]['app']  = $obj;
            $grouped[$p]['date'] = $grouped[$p]['date'] ?? $obj['last_modified'];
            $grouped[$p]['size'] = ($grouped[$p]['size'] ?? 0) + (int)$obj['size'];
        }

        krsort($grouped);
        return $grouped;
    }

    /** Restore DB from R2. Returns log. */
    public function restoreDatabase(string $r2Key): array
    {
        $this->log = [];
        try {
            $localGz  = $this->tmpDir . '/' . basename($r2Key);
            $localSql = preg_replace('/\.gz$/', '', $localGz);

            $this->workerDownload($r2Key, $localGz);
            $this->info("Downloaded: " . basename($r2Key));

            $this->gunzipFile($localGz, $localSql);
            @unlink($localGz);
            $this->info("Decompressed SQL.");

            $this->importDatabase($localSql);
            @unlink($localSql);
            $this->info("✅ Database restored successfully.");

        } catch (Exception $e) {
            $this->error("❌ DB Restore FAILED: " . $e->getMessage());
        }
        return $this->log;
    }

    /** Restore app files from R2. Returns log. */
    public function restoreAppFiles(string $r2Key): array
    {
        $this->log = [];
        try {
            $localZip = $this->tmpDir . '/' . basename($r2Key);

            $this->workerDownload($r2Key, $localZip);
            $this->info("Downloaded: " . basename($r2Key));

            $zip = new ZipArchive();
            if ($zip->open($localZip) !== true) {
                throw new Exception("Cannot open zip archive.");
            }
            $appRoot = rtrim(dirname(__DIR__), '/');
            $zip->extractTo($appRoot);
            $zip->close();
            @unlink($localZip);
            $this->info("✅ App files restored to {$appRoot}");

        } catch (Exception $e) {
            $this->error("❌ App Restore FAILED: " . $e->getMessage());
        }
        return $this->log;
    }

    /** Delete a backup set (db + app) by prefix. Returns log. */
    public function deleteBackup(string $prefix): array
    {
        $this->log = [];
        try {
            r2_delete("backups/db/{$prefix}_db.sql.gz");
            r2_delete("backups/app/{$prefix}_app.zip");
            $this->info("Deleted: {$prefix}");
        } catch (Exception $e) {
            $this->error("Delete FAILED: " . $e->getMessage());
        }
        return $this->log;
    }

    public function getLog(): array { return $this->log; }

    public function isPaused(): bool
    {
        return is_file($this->pauseFlagFile());
    }

    public function setPaused(bool $paused, string $changedBy = 'admin'): void
    {
        $flagFile = $this->pauseFlagFile();

        if ($paused) {
            $payload = [
                'paused_at' => date('c'),
                'paused_by' => $changedBy ?: 'admin',
            ];
            file_put_contents($flagFile, json_encode($payload, JSON_PRETTY_PRINT));
            return;
        }

        if (is_file($flagFile)) {
            @unlink($flagFile);
        }
    }

    public function getPauseInfo(): array
    {
        $flagFile = $this->pauseFlagFile();
        if (!is_file($flagFile)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($flagFile), true);
        return is_array($data) ? $data : [];
    }

    private function pauseFlagFile(): string
    {
        return $this->tmpDir . '/backup_paused.flag';
    }

    // ----------------------------------------------------------------
    // WORKER RELAY — LIST & DOWNLOAD
    // (Upload & Delete reuse r2_upload() / r2_delete() from r2_helper.php)
    // ----------------------------------------------------------------

    private function workerDownload(string $r2Key, string $localDest): void
    {
        $url = R2_WORKER_URL . '/' . ltrim($r2Key, '/');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['X-DMS-Token: ' . R2_WORKER_TOKEN],
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr)       throw new Exception("cURL download error: {$curlErr}");
        if ($httpCode !== 200) throw new Exception("Worker GET {$httpCode} for: {$r2Key}");
        if (file_put_contents($localDest, $body) === false) {
            throw new Exception("Cannot write to: {$localDest}");
        }
    }

    private function workerList(string $prefix): array
    {
        $url = R2_WORKER_URL . '/?' . http_build_query(['list' => '1', 'prefix' => $prefix]);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['X-DMS-Token: ' . R2_WORKER_TOKEN],
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr)          throw new Exception("cURL list error: {$curlErr}");
        if ($httpCode !== 200) throw new Exception("Worker LIST {$httpCode}");

        $data = json_decode($body, true);
        return $data['objects'] ?? [];
    }

    // ----------------------------------------------------------------
    // DATABASE
    // ----------------------------------------------------------------

    private function dumpDatabase(string $prefix): string
    {
        $outFile = "{$this->tmpDir}/{$prefix}_db.sql";
        $db      = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            throw new Exception("DB connect failed: " . $db->connect_error);
        }
        $db->set_charset('utf8mb4');

        $fh = fopen($outFile, 'w');
        if (!$fh) throw new Exception("Cannot write to: {$outFile}");

        // Header
        fwrite($fh, "-- TSG DMS Database Backup\n");
        fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "-- Database: " . DB_NAME . "\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($fh, "SET NAMES utf8mb4;\n\n");

        // Get all tables
        $tables = [];
        $res = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $res->fetch_row()) $tables[] = $row[0];

        foreach ($tables as $table) {
            $escaped = $db->real_escape_string($table);

            // DROP + CREATE
            fwrite($fh, "-- Table: `{$table}`\n");
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
            $createRes = $db->query("SHOW CREATE TABLE `{$escaped}`");
            $createRow = $createRes->fetch_row();
            fwrite($fh, $createRow[1] . ";\n\n");

            // Row count
            $countRes = $db->query("SELECT COUNT(*) FROM `{$escaped}`");
            $count    = (int)$countRes->fetch_row()[0];
            if ($count === 0) continue;

            // Data in batches of 500
            $offset = 0;
            $batch  = 500;
            while ($offset < $count) {
                $dataRes = $db->query("SELECT * FROM `{$escaped}` LIMIT {$batch} OFFSET {$offset}");
                $rows    = [];
                while ($dataRow = $dataRes->fetch_row()) {
                    $vals = [];
                    foreach ($dataRow as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = "'" . $db->real_escape_string($val) . "'";
                        }
                    }
                    $rows[] = '(' . implode(',', $vals) . ')';
                }
                if (!empty($rows)) {
                    fwrite($fh, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $rows) . ";\n");
                }
                $offset += $batch;
            }
            fwrite($fh, "\n");
        }

        // Views
        $viewRes = $db->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        while ($row = $viewRes->fetch_row()) {
            $view       = $row[0];
            $escapedV   = $db->real_escape_string($view);
            $createRes  = $db->query("SHOW CREATE VIEW `{$escapedV}`");
            $createRow  = $createRes->fetch_row();
            fwrite($fh, "-- View: `{$view}`\n");
            fwrite($fh, "DROP VIEW IF EXISTS `{$view}`;\n");
            fwrite($fh, $createRow[1] . ";\n\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        $db->close();

        if (filesize($outFile) < 100) {
            throw new Exception("DB dump appears empty: {$outFile}");
        }
        return $outFile;
    }

    private function importDatabase(string $sqlFile): void
    {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            throw new Exception("DB connect failed: " . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        $db->query("SET FOREIGN_KEY_CHECKS=0");

        $sql     = file_get_contents($sqlFile);
        if ($sql === false) throw new Exception("Cannot read SQL file: {$sqlFile}");

        // Split on statement boundaries (semicolons not inside quotes)
        $statements = $this->splitSql($sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0) continue;
            if (!$db->query($stmt)) {
                $err = $db->error;
                $db->query("SET FOREIGN_KEY_CHECKS=1");
                $db->close();
                throw new Exception("SQL import error: " . $err . " in: " . substr($stmt, 0, 100));
            }
        }

        $db->query("SET FOREIGN_KEY_CHECKS=1");
        $db->close();
    }

    /** Split a SQL dump into individual statements safely. */
    private function splitSql(string $sql): array
    {
        $statements = [];
        $current    = '';
        $inString   = false;
        $strChar    = '';
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];

            if ($inString) {
                $current .= $c;
                if ($c === '\\') {
                    $current .= $sql[++$i] ?? '';
                } elseif ($c === $strChar) {
                    $inString = false;
                }
            } elseif ($c === "'" || $c === '"' || $c === '`') {
                $inString = true;
                $strChar  = $c;
                $current .= $c;
            } elseif ($c === ';') {
                $statements[] = $current;
                $current      = '';
            } else {
                $current .= $c;
            }
        }
        if (trim($current) !== '') $statements[] = $current;
        return $statements;
    }

    // ----------------------------------------------------------------
    // FILE OPS
    // ----------------------------------------------------------------

    private function gzipFile(string $src): string
    {
        $dst = $src . '.gz';
        $in  = fopen($src, 'rb');
        $out = gzopen($dst, 'wb9');
        if (!$in || !$out) throw new Exception("gzip failed: {$src}");
        while (!feof($in)) gzwrite($out, fread($in, 65536));
        fclose($in); gzclose($out);
        return $dst;
    }

    private function gunzipFile(string $src, string $dst): void
    {
        $in  = gzopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if (!$in || !$out) throw new Exception("gunzip failed: {$src}");
        while (!gzeof($in)) fwrite($out, gzread($in, 65536));
        gzclose($in); fclose($out);
    }

    private function zipAppFiles(string $prefix): string
    {
        $outFile = "{$this->tmpDir}/{$prefix}_app.zip";
        $zip     = new ZipArchive();
        if ($zip->open($outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Cannot create zip: {$outFile}");
        }
        $appRoot = rtrim(dirname(__DIR__), '/');
        $exclude = [$this->tmpDir, $appRoot . '/backup_tmp'];
        $this->addDirToZip($zip, $appRoot, $appRoot, $exclude);
        $zip->close();

        if (!file_exists($outFile) || filesize($outFile) < 100) {
            throw new Exception("App zip empty or failed.");
        }
        return $outFile;
    }

    private function addDirToZip(ZipArchive $zip, string $dir, string $base, array $exclude): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $dir . '/' . $item;
            foreach ($exclude as $ex) {
                if (strpos($fullPath, $ex) === 0) continue 2;
            }
            $rel = ltrim(str_replace($base, '', $fullPath), '/');
            if (is_dir($fullPath)) {
                $zip->addEmptyDir($rel);
                $this->addDirToZip($zip, $fullPath, $base, $exclude);
            } elseif (is_file($fullPath)) {
                $zip->addFile($fullPath, $rel);
            }
        }
    }

    // ----------------------------------------------------------------
    // HOUSEKEEPING
    // ----------------------------------------------------------------

    private function pruneOldBackups(): int
    {
        $cutoff = time() - (BACKUP_RETAIN_DAYS * 86400);
        $pruned = 0;
        try {
            foreach ($this->listBackups() as $prefix => $data) {
                if (!empty($data['date']) && strtotime($data['date']) < $cutoff) {
                    $this->deleteBackup($prefix);
                    $pruned++;
                }
            }
        } catch (Exception $e) {
            $this->error("Prune warning: " . $e->getMessage());
        }
        return $pruned;
    }

    private function extractPrefix(string $key): string
    {
        // backups/db/backup_20260324_020000_db.sql.gz → backup_20260324_020000
        return preg_replace('/_(db\.sql\.gz|app\.zip)$/', '', basename($key));
    }

    // ----------------------------------------------------------------
    // LOGGING
    // ----------------------------------------------------------------

    private function info(string $msg): void
    {
        $this->log[] = ['level' => 'info',  'msg' => $msg, 'time' => date('H:i:s')];
    }

    private function error(string $msg): void
    {
        $this->log[] = ['level' => 'error', 'msg' => $msg, 'time' => date('H:i:s')];
    }
}
