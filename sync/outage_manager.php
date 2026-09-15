<?php
// ============================================================
//  TSGImpex Outage Manager
//  - Checks if live server is reachable
//  - Queues local changes when live is down
//  - Replays the queue when live comes back up
//  - Runs on LOCAL XAMPP only
// ============================================================
require_once __DIR__ . '/../sync_config.php';
require_once __DIR__ . '/sync_engine.php';

class OutageManager {

    // ── Check if live server is reachable ────────────────────
    public static function isLiveUp(): bool {
        $ch = curl_init(REMOTE_SYNC_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['action' => 'ping', 'secret' => SYNC_SECRET]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || !$raw) {
            self::logHealth('DOWN', $err ?: 'No response');
            return false;
        }
        $data = json_decode($raw, true);
        $up   = isset($data['ok']) && $data['ok'] === true;
        self::logHealth($up ? 'UP' : 'DOWN', $data['error'] ?? ($up ? 'OK' : 'Bad response'));
        return $up;
    }

    // ── Queue a change that happened while live is down ───────
    // Call this from your app code when INSERT/UPDATE/DELETE happens
    // Example: OutageManager::queueChange('orders', 'id', $orderId, 'INSERT', $rowData);
    public static function queueChange(
        string $table,
        string $pkColumn,
        string $pkValue,
        string $action,   // INSERT | UPDATE | DELETE
        array  $rowData
    ): void {
        $pdo  = SyncDB::get();
        $stmt = $pdo->prepare("
            INSERT INTO `_sync_outage_queue`
                (table_name, pk_column, pk_value, action, row_data)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$table, $pkColumn, $pkValue, $action, json_encode($rowData)]);
        SyncLogger::log('info', "QUEUED $action on $table #$pkValue (offline)");
    }

    // ── Replay the entire queue to live server ────────────────
    // Called automatically when live comes back up
    public static function replayQueue(): array {
        $pdo     = SyncDB::get();
        $results = ['replayed' => 0, 'failed' => 0, 'skipped' => 0];

        $stmt = $pdo->prepare("
            SELECT * FROM `_sync_outage_queue`
            WHERE replayed = 0
            ORDER BY queued_at ASC
        ");
        $stmt->execute();
        $queue = $stmt->fetchAll();

        if (empty($queue)) {
            SyncLogger::log('info', 'REPLAY: queue is empty, nothing to replay');
            return $results;
        }

        SyncLogger::log('info', 'REPLAY: starting — ' . count($queue) . ' changes queued');

        foreach ($queue as $item) {
            $table    = $item['table_name'];
            $pk       = $item['pk_column'];
            $pkVal    = $item['pk_value'];
            $action   = $item['action'];
            $rowData  = json_decode($item['row_data'], true);

            // Validate table is allowed
            if (!array_key_exists($table, SYNC_TABLES)) {
                self::markReplayed($pdo, $item['id'], 'skipped: table not in SYNC_TABLES');
                $results['skipped']++;
                continue;
            }

            // Send this change to remote via API
            $response = self::sendToRemote($table, $pk, $action, $rowData);

            if ($response['ok']) {
                self::markReplayed($pdo, $item['id']);
                $results['replayed']++;
                SyncLogger::log('info', "REPLAY OK: $action $table #$pkVal");
            } else {
                $err = $response['error'] ?? 'Unknown';
                self::markFailed($pdo, $item['id'], $err);
                $results['failed']++;
                SyncLogger::log('error', "REPLAY FAIL: $action $table #$pkVal — $err");
            }
        }

        SyncLogger::log('info', "REPLAY DONE: {$results['replayed']} ok, {$results['failed']} failed, {$results['skipped']} skipped");
        return $results;
    }

    // ── Get queue status summary ──────────────────────────────
    public static function getQueueStats(): array {
        $pdo = SyncDB::get();
        return [
            'pending'  => (int)$pdo->query("SELECT COUNT(*) FROM `_sync_outage_queue` WHERE replayed = 0")->fetchColumn(),
            'replayed' => (int)$pdo->query("SELECT COUNT(*) FROM `_sync_outage_queue` WHERE replayed = 1 AND error IS NULL")->fetchColumn(),
            'failed'   => (int)$pdo->query("SELECT COUNT(*) FROM `_sync_outage_queue` WHERE error IS NOT NULL AND replayed = 0")->fetchColumn(),
            'total'    => (int)$pdo->query("SELECT COUNT(*) FROM `_sync_outage_queue`")->fetchColumn(),
        ];
    }

    // ── Get pending queue items for dashboard ─────────────────
    public static function getPendingQueue(int $limit = 50): array {
        $pdo  = SyncDB::get();
        $stmt = $pdo->prepare("
            SELECT id, table_name, pk_value, action, queued_at, error
            FROM `_sync_outage_queue`
            WHERE replayed = 0
            ORDER BY queued_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    // ── Get health log ────────────────────────────────────────
    public static function getHealthLog(int $limit = 30): array {
        $pdo  = SyncDB::get();
        $stmt = $pdo->prepare("
            SELECT status, checked_at, response
            FROM `_sync_health_log`
            ORDER BY checked_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    // ── Get last known live status ────────────────────────────
    public static function getLastStatus(): ?array {
        $pdo = SyncDB::get();
        return $pdo->query("
            SELECT status, checked_at, response
            FROM `_sync_health_log`
            ORDER BY checked_at DESC
            LIMIT 1
        ")->fetch() ?: null;
    }

    // ── Full outage recovery cycle ────────────────────────────
    // Called by the watchdog runner every few minutes
    public static function watchdog(): array {
        $result = ['live_up' => false, 'replayed' => 0, 'failed' => 0, 'regular_sync' => false];

        $liveUp = self::isLiveUp();
        $result['live_up'] = $liveUp;

        if (!$liveUp) {
            SyncLogger::log('warn', 'WATCHDOG: live server is DOWN — offline mode active');
            return $result;
        }

        // Live is up — first replay the queue
        $pending = self::getQueueStats()['pending'];
        if ($pending > 0) {
            SyncLogger::log('info', "WATCHDOG: live is UP, replaying $pending queued changes");
            $replay = self::replayQueue();
            $result['replayed'] = $replay['replayed'];
            $result['failed']   = $replay['failed'];
        }

        // Then do a normal two-way sync
        if (!SyncLock::isLocked()) {
            $syncResult = SyncEngine::fullSync();
            $result['regular_sync'] = true;
        }

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────
    private static function sendToRemote(string $table, string $pk, string $action, array $row): array {
        // For DELETE, send a special delete action
        // For INSERT/UPDATE, use the standard receive (upsert)
        $payload = [
            'secret' => SYNC_SECRET,
            'table'  => $table,
            'pk'     => $pk,
            'origin' => THIS_SITE,
        ];

        if ($action === 'DELETE') {
            $payload['action']   = 'delete';
            $payload['pk_value'] = $row[$pk] ?? null;
        } else {
            $payload['action'] = 'receive';
            $payload['rows']   = [$row];
        }

        $ch = curl_init(REMOTE_SYNC_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['ok' => false, 'error' => "cURL: $err"];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['ok' => false, 'error' => "Bad response: $raw"];
    }

    private static function markReplayed(PDO $pdo, int $id, ?string $note = null): void {
        $stmt = $pdo->prepare("
            UPDATE `_sync_outage_queue`
            SET replayed = 1, replayed_at = NOW(), error = ?
            WHERE id = ?
        ");
        $stmt->execute([$note, $id]);
    }

    private static function markFailed(PDO $pdo, int $id, string $error): void {
        $stmt = $pdo->prepare("
            UPDATE `_sync_outage_queue` SET error = ? WHERE id = ?
        ");
        $stmt->execute([$error, $id]);
    }

    private static function logHealth(string $status, string $response = ''): void {
        try {
            $pdo  = SyncDB::get();
            $stmt = $pdo->prepare("
                INSERT INTO `_sync_health_log` (status, response) VALUES (?, ?)
            ");
            $stmt->execute([$status, substr($response, 0, 255)]);
        } catch (Exception $e) { /* silently fail if table not ready yet */ }
    }
}
