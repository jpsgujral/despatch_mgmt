<?php
/*
 * Lightweight health endpoint for uptime monitors and failover checks.
 * Returns JSON only and does not require an authenticated session.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$startedAt = microtime(true);
$rootDir = __DIR__;

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

try {
    require_once $rootDir . '/config.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'app' => 'Despatch Management System',
        'checked_at' => date('c'),
        'checks' => [
            'config' => [
                'ok' => false,
                'message' => 'Config failed to load',
                'error' => $e->getMessage(),
            ],
        ],
        'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ], JSON_PRETTY_PRINT);
    exit;
}

$status = [
    'ok' => true,
    'app' => defined('APP_NAME') ? APP_NAME : 'Despatch Management System',
    'version' => defined('APP_VERSION') ? APP_VERSION : '',
    'checked_at' => date('c'),
    'checks' => [],
];

$dbStart = microtime(true);
$db = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$db) {
    $status['ok'] = false;
    $status['checks']['database'] = [
        'ok' => false,
        'message' => 'Database connection failed',
        'error' => mysqli_connect_error(),
        'latency_ms' => round((microtime(true) - $dbStart) * 1000, 2),
    ];
} else {
    mysqli_set_charset($db, 'utf8mb4');
    $pingOk = @mysqli_query($db, 'SELECT 1 AS ok');
    $status['checks']['database'] = [
        'ok' => (bool)$pingOk,
        'message' => $pingOk ? 'Database reachable' : 'Database query failed',
        'error' => $pingOk ? '' : mysqli_error($db),
        'latency_ms' => round((microtime(true) - $dbStart) * 1000, 2),
    ];
    if (!$pingOk) {
        $status['ok'] = false;
    }
    mysqli_close($db);
}

$status['checks']['filesystem'] = [
    'ok' => is_dir($rootDir) && is_readable($rootDir),
    'message' => 'Application files readable',
];

if (!$status['checks']['filesystem']['ok']) {
    $status['ok'] = false;
}

$status['latency_ms'] = round((microtime(true) - $startedAt) * 1000, 2);

http_response_code($status['ok'] ? 200 : 503);
echo json_encode($status, JSON_PRETTY_PRINT);
