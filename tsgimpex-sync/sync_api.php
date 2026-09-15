<?php
// Read-only API for LIVE server. It never writes to live database.
header('Content-Type: application/json');

require_once __DIR__ . '/sync_config.php';

function sync_response(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sync_db(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || ($payload['secret'] ?? '') !== SYNC_SECRET) {
    sync_response(['ok' => false, 'error' => 'Unauthorized'], 403);
}

$action = (string)($payload['action'] ?? '');
$pdo = sync_db();

try {
    if ($action === 'ping') {
        sync_response([
            'ok' => true,
            'site' => THIS_SITE,
            'database' => DB_NAME,
            'version' => SYNC_VERSION,
            'read_only' => true,
            'time' => date('Y-m-d H:i:s'),
        ]);
    }

    if ($action === 'tables') {
        $tables = [];
        $stmt = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $tables[] = $row[0];
        }
        sync_response(['ok' => true, 'tables' => $tables]);
    }

    if ($action === 'create_table') {
        $table = (string)($payload['table'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            sync_response(['ok' => false, 'error' => 'Invalid table'], 400);
        }
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        sync_response(['ok' => true, 'table' => $table, 'create_sql' => $row['Create Table'] ?? '']);
    }

    if ($action === 'rows') {
        $table = (string)($payload['table'] ?? '');
        $offset = max(0, (int)($payload['offset'] ?? 0));
        $limit = max(1, min((int)($payload['limit'] ?? SYNC_BATCH_SIZE), SYNC_BATCH_SIZE));

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            sync_response(['ok' => false, 'error' => 'Invalid table'], 400);
        }

        $stmt = $pdo->query("SELECT * FROM `$table` LIMIT $limit OFFSET $offset");
        $rows = $stmt->fetchAll();
        sync_response(['ok' => true, 'table' => $table, 'rows' => $rows, 'count' => count($rows)]);
    }

    // Explicitly reject write-like actions.
    if (in_array($action, ['push', 'receive', 'delete', 'update', 'insert'], true)) {
        sync_response(['ok' => false, 'error' => 'Writes are disabled. This API is read-only.'], 405);
    }

    sync_response(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    sync_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
