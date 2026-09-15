<?php
// Run this only on local XAMPP. It copies live DB data into local phpMyAdmin DB.
// It creates missing local tables from live and replaces local table rows.

require_once __DIR__ . '/sync_config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

if (live !== 'local') {
    echo "This pull script must run only on local XAMPP. Set THIS_SITE to local.\n";
    exit(1);
}

function log_line(string $message): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    echo $line;
    file_put_contents(SYNC_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function local_db(): PDO {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    return $pdo;
}

function live_call(string $action, array $data = []): array {
    $data['action'] = $action;
    $data['secret'] = SYNC_SECRET;

    $ch = curl_init(LIVE_SYNC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => SYNC_TIMEOUT,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'error' => 'cURL: ' . $err];
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Bad response from live'];
}

function insert_rows(PDO $pdo, string $table, array $rows): void {
    if (!$rows) return;
    $cols = array_keys($rows[0]);
    $colSql = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $valSql = implode(', ', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare("INSERT INTO `$table` ($colSql) VALUES ($valSql)");
    foreach ($rows as $row) {
        $stmt->execute(array_map(fn($c) => $row[$c] ?? null, $cols));
    }
}

$ping = live_call('ping');
if (empty($ping['ok'])) {
    log_line('Live ping failed: ' . ($ping['error'] ?? 'unknown'));
    exit(1);
}

log_line('Connected to live database: ' . ($ping['database'] ?? DB_NAME));

$tablesResp = live_call('tables');
if (empty($tablesResp['ok'])) {
    log_line('Could not fetch live table list: ' . ($tablesResp['error'] ?? 'unknown'));
    exit(1);
}

$pdo = local_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

try {
    foreach ($tablesResp['tables'] as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            log_line("Skipping invalid table name: $table");
            continue;
        }

        $createResp = live_call('create_table', ['table' => $table]);
        if (empty($createResp['ok']) || empty($createResp['create_sql'])) {
            throw new RuntimeException("Could not get create SQL for $table: " . ($createResp['error'] ?? 'unknown'));
        }

        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        $pdo->exec($createResp['create_sql']);

        $offset = 0;
        $imported = 0;
        do {
            $rowsResp = live_call('rows', [
                'table' => $table,
                'offset' => $offset,
                'limit' => SYNC_BATCH_SIZE,
            ]);
            if (empty($rowsResp['ok'])) {
                throw new RuntimeException("Could not fetch rows for $table: " . ($rowsResp['error'] ?? 'unknown'));
            }

            $rows = $rowsResp['rows'] ?? [];
            insert_rows($pdo, $table, $rows);
            $count = count($rows);
            $imported += $count;
            $offset += $count;
        } while ($count === SYNC_BATCH_SIZE);

        log_line("Imported $table: $imported rows");
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

log_line('Local pull completed.');
