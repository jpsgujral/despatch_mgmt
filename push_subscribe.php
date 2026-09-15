<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/webpush_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$endpoint = trim((string)($payload['endpoint'] ?? ''));
$keys = (array)($payload['keys'] ?? []);
$p256dh = trim((string)($keys['p256dh'] ?? ''));
$auth = trim((string)($keys['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing subscription data']);
    exit;
}

$db = getDB();
$db->set_charset('utf8mb4');
dmsEnsurePushTables($db);
dmsEnsureVapidKeys($db);

$uid = (int)$_SESSION['user_id'];
$endpointHash = hash('sha256', $endpoint);
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$stmt = $db->prepare("INSERT INTO dms_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent, status)
    VALUES (?, ?, ?, ?, ?, ?, 'Active')
    ON DUPLICATE KEY UPDATE
        user_id = VALUES(user_id),
        endpoint = VALUES(endpoint),
        p256dh = VALUES(p256dh),
        auth = VALUES(auth),
        user_agent = VALUES(user_agent),
        status = 'Active',
        updated_at = NOW()");
$stmt->bind_param("isssss", $uid, $endpointHash, $endpoint, $p256dh, $auth, $ua);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save subscription']);
    exit;
}

echo json_encode(['ok' => true]);

