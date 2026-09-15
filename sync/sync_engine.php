<?php
// ============================================================
//  TSGImpex Sync Engine — Core Library
// ============================================================
require_once __DIR__ . '/sync_config.php';

class SyncDB {
    private static $pdo = null;

    public static function get(): PDO {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }
        return self::$pdo;
    }
}

class SyncLogger {
    public static function log(string $level, string $msg): void {
        // Rotate if too large
        if (file_exists(SYNC_LOG_FILE) && filesize(SYNC_LOG_FILE) > MAX_LOG_SIZE_MB * 1024 * 1024) {
            rename(SYNC_LOG_FILE, SYNC_LOG_FILE . '.bak');
        }
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $msg . PHP_EOL;
        file_put_contents(SYNC_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }

    public static function getLogs(int $lines = 100): array {
        if (!file_exists(SYNC_LOG_FILE)) return [];
        $all = file(SYNC_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice(array_reverse($all), 0, $lines);
    }
}

class SyncLock {
    public static function acquire(): bool {
        if (file_exists(SYNC_LOCK_FILE)) {
            $age = time() - (int)file_get_contents(SYNC_LOCK_FILE);
            if ($age < SYNC_TIMEOUT) return false; // Still locked
            self::release(); // Stale lock
        }
        file_put_contents(SYNC_LOCK_FILE, time());
        return true;
    }

    public static function release(): void {
        if (file_exists(SYNC_LOCK_FILE)) unlink(SYNC_LOCK_FILE);
    }

    public static function isLocked(): bool {
        if (!file_exists(SYNC_LOCK_FILE)) return false;
        return (time() - (int)file_get_contents(SYNC_LOCK_FILE)) < SYNC_TIMEOUT;
    }
}

class SyncEngine {

    // ── PUSH: Send local changes to remote ───────────────────
    public static function push(string $table, string $pk): array {
        $pdo = SyncDB::get();
        $results = ['sent' => 0, 'errors' => []];

        // Get rows changed since last push
        $lastSync = self::getLastSync($table, 'push');
        $stmt = $pdo->prepare(
            "SELECT * FROM `$table` WHERE updated_at > ? ORDER BY updated_at ASC LIMIT " . SYNC_BATCH_SIZE
        );
        $stmt->execute([$lastSync]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            SyncLogger::log('info', "PUSH $table: nothing to send");
            return $results;
        }

        // Send to remote API
        $response = self::callRemoteAPI('receive', [
            'table'   => $table,
            'pk'      => $pk,
            'rows'    => $rows,
            'origin'  => THIS_SITE,
        ]);

        if ($response['ok']) {
            $results['sent'] = count($rows);
            self::setLastSync($table, 'push', end($rows)['updated_at']);
            SyncLogger::log('info', "PUSH $table: sent {$results['sent']} rows");
        } else {
            $results['errors'][] = $response['error'] ?? 'Unknown remote error';
            SyncLogger::log('error', "PUSH $table: " . ($response['error'] ?? 'unknown'));
        }

        return $results;
    }

    // ── PULL: Fetch remote changes and apply locally ──────────
    public static function pull(string $table, string $pk): array {
        $results = ['received' => 0, 'errors' => []];
        $lastSync = self::getLastSync($table, 'pull');

        $response = self::callRemoteAPI('export', [
            'table'     => $table,
            'pk'        => $pk,
            'since'     => $lastSync,
            'batch'     => SYNC_BATCH_SIZE,
        ]);

        if (!$response['ok']) {
            $results['errors'][] = $response['error'] ?? 'Remote export failed';
            SyncLogger::log('error', "PULL $table: " . ($response['error'] ?? 'unknown'));
            return $results;
        }

        $rows = $response['rows'] ?? [];
        if (empty($rows)) {
            SyncLogger::log('info', "PULL $table: nothing new");
            return $results;
        }

        self::upsertRows($table, $pk, $rows);
        $results['received'] = count($rows);
        self::setLastSync($table, 'pull', end($rows)['updated_at']);
        SyncLogger::log('info', "PULL $table: applied {$results['received']} rows");

        return $results;
    }

    // ── UPSERT rows into local DB ─────────────────────────────
    public static function upsertRows(string $table, string $pk, array $rows): void {
        if (empty($rows)) return;
        $pdo = SyncDB::get();
        $cols = array_keys($rows[0]);

        $placeholders = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $values       = implode(', ', array_fill(0, count($cols), '?'));
        $updates      = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));

        $sql  = "INSERT INTO `$table` ($placeholders) VALUES ($values) ON DUPLICATE KEY UPDATE $updates";
        $stmt = $pdo->prepare($sql);

        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
        }
    }

    // ── EXPORT rows for remote (called by API) ────────────────
    public static function exportRows(string $table, string $pk, string $since, int $batch): array {
        $pdo  = SyncDB::get();
        $stmt = $pdo->prepare(
            "SELECT * FROM `$table` WHERE updated_at > ? ORDER BY updated_at ASC LIMIT $batch"
        );
        $stmt->execute([$since]);
        return $stmt->fetchAll();
    }

    // ── FULL SYNC: push + pull all tables ────────────────────
    public static function fullSync(): array {
        if (!SyncLock::acquire()) {
            return ['error' => 'Sync already running'];
        }

        $summary = ['tables' => [], 'started_at' => date('Y-m-d H:i:s')];
        SyncLogger::log('info', '=== FULL SYNC STARTED ===');

        foreach (SYNC_TABLES as $table => $pk) {
            $push = self::push($table, $pk);
            $pull = self::pull($table, $pk);
            $summary['tables'][$table] = [
                'pushed'   => $push['sent'],
                'pulled'   => $pull['received'],
                'errors'   => array_merge($push['errors'], $pull['errors']),
            ];
        }

        $summary['finished_at'] = date('Y-m-d H:i:s');
        SyncLogger::log('info', '=== FULL SYNC DONE ===');
        SyncLock::release();
        return $summary;
    }

    // ── Helpers ──────────────────────────────────────────────
    private static function callRemoteAPI(string $action, array $payload): array {
        $payload['action'] = $action;
        $payload['secret'] = SYNC_SECRET;

        $ch = curl_init(REMOTE_SYNC_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => SYNC_TIMEOUT,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['ok' => false, 'error' => "cURL error: $err"];

        $data = json_decode($raw, true);
        if (!is_array($data)) return ['ok' => false, 'error' => "Bad response: $raw"];
        return $data;
    }

    private static function getLastSync(string $table, string $direction): string {
        $file = __DIR__ . "/logs/lastsync_{$table}_{$direction}.txt";
        return file_exists($file) ? trim(file_get_contents($file)) : '1970-01-01 00:00:00';
    }

    private static function setLastSync(string $table, string $direction, string $ts): void {
        $file = __DIR__ . "/logs/lastsync_{$table}_{$direction}.txt";
        file_put_contents($file, $ts);
    }

    // ── Get stats for dashboard ───────────────────────────────
    public static function getStats(): array {
        $pdo    = SyncDB::get();
        $stats  = [];
        $tables = SYNC_TABLES;

        foreach ($tables as $table => $pk) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                $last  = $pdo->query("SELECT MAX(updated_at) FROM `$table`")->fetchColumn();
                $stats[$table] = ['rows' => $count, 'last_modified' => $last ?? 'N/A'];
            } catch (Exception $e) {
                $stats[$table] = ['rows' => '?', 'last_modified' => 'Table missing — run ALTER TABLE'];
            }
        }
        return $stats;
    }
}
