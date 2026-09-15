<?php
// ============================================================
//  TSGImpex Sync API — Place this file on BOTH servers
//  URL: /sync/sync_api.php
//  Accepts JSON POST requests from the other server only.
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../sync_config.php';
require_once __DIR__ . '/sync_engine.php';

// ── Auth ─────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || ($data['secret'] ?? '') !== SYNC_SECRET) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $data['action'] ?? '';
$table  = $data['table']  ?? '';
$pk     = $data['pk']     ?? 'id';
$tables = SYNC_TABLES;

// Validate table is in whitelist
if (!array_key_exists($table, $tables) && in_array($action, ['receive', 'export'])) {
    echo json_encode(['ok' => false, 'error' => "Table '$table' not in sync list"]);
    exit;
}

// ── Actions ───────────────────────────────────────────────────
switch ($action) {

    // Remote pushes rows to us → we upsert them
    case 'receive':
        $rows = $data['rows'] ?? [];
        if (empty($rows)) {
            echo json_encode(['ok' => true, 'message' => 'Nothing to receive']);
            exit;
        }
        try {
            SyncEngine::upsertRows($table, $pk, $rows);
            SyncLogger::log('info', "API receive: inserted/updated " . count($rows) . " rows in $table from " . ($data['origin'] ?? '?'));
            echo json_encode(['ok' => true, 'inserted' => count($rows)]);
        } catch (Exception $e) {
            SyncLogger::log('error', "API receive $table: " . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    // Remote requests rows from us → we export them
    case 'export':
        $since = $data['since'] ?? '1970-01-01 00:00:00';
        $batch = (int)($data['batch'] ?? SYNC_BATCH_SIZE);
        try {
            $rows = SyncEngine::exportRows($table, $pk, $since, $batch);
            echo json_encode(['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
        } catch (Exception $e) {
            SyncLogger::log('error', "API export $table: " . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    // Remote replays a DELETE from outage queue
    case 'delete':
        $pkValue = $data['pk_value'] ?? null;
        if (!$pkValue || !array_key_exists($table, SYNC_TABLES)) {
            echo json_encode(['ok' => false, 'error' => 'Missing pk_value or table not allowed']);
            break;
        }
        try {
            $pdo  = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?");
            $stmt->execute([$pkValue]);
            SyncLogger::log('info', "API delete: removed $table #$pkValue from " . ($data['origin'] ?? '?'));
            echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()]);
        } catch (Exception $e) {
            SyncLogger::log('error', "API delete $table: " . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    // Ping — check if API is reachable
    case 'ping':
        echo json_encode(['ok' => true, 'site' => THIS_SITE, 'version' => SYNC_VERSION, 'time' => date('Y-m-d H:i:s')]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => "Unknown action: $action"]);
}
