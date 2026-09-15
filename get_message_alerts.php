<?php
$baseDir = __DIR__;
for ($i = 0; $i < 4; $i++) {
    if (is_file($baseDir . '/includes/config.php') && is_file($baseDir . '/includes/auth.php')) {
        break;
    }
    $parent = dirname($baseDir);
    if ($parent === $baseDir) {
        break;
    }
    $baseDir = $parent;
}
require_once $baseDir . '/includes/config.php';
require_once $baseDir . '/includes/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => true, 'unread' => 0, 'alerts' => [], 'max_unread_id' => 0]);
    exit;
}

$db = getDB();
$uid = (int)$_SESSION['user_id'];
$last_id = (int)($_GET['last_id'] ?? 0);
if ($last_id < 0) $last_id = 0;

// Ensure message table exists before querying.
$db->query("CREATE TABLE IF NOT EXISTS `dms_messages` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `sender_id`   INT NOT NULL,
    `recipient_id` INT NOT NULL,
    `subject`     VARCHAR(255) NOT NULL DEFAULT '(No Subject)',
    `body`        TEXT NOT NULL,
    `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_by_sender`    TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_by_recipient` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(`recipient_id`),
    INDEX(`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$unread_res = $db->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(id),0) AS mx
    FROM dms_messages
    WHERE recipient_id=$uid AND is_read=0 AND deleted_by_recipient=0");
$unread = 0;
$max_unread_id = 0;
if ($unread_res) {
    $u = $unread_res->fetch_assoc();
    $unread = (int)($u['cnt'] ?? 0);
    $max_unread_id = (int)($u['mx'] ?? 0);
}

$alerts = [];
$alerts_q = $db->query("
    SELECT m.id, m.subject, m.body, m.created_at,
           COALESCE(u.full_name, u.username) AS sender_name
    FROM dms_messages m
    JOIN app_users u ON u.id = m.sender_id
    WHERE m.recipient_id = $uid
      AND m.is_read = 0
      AND m.deleted_by_recipient = 0
      AND m.id > $last_id
    ORDER BY m.id ASC
    LIMIT 20
");
if ($alerts_q) {
    while ($r = $alerts_q->fetch_assoc()) {
        $preview = (string)$r['body'];
        if (function_exists('mb_substr')) {
            $preview = mb_substr($preview, 0, 140);
        } else {
            $preview = substr($preview, 0, 140);
        }
        $alerts[] = [
            'id' => (int)$r['id'],
            'sender_name' => (string)$r['sender_name'],
            'subject' => (string)$r['subject'],
            'body_preview' => $preview,
            'created_at' => (string)$r['created_at'],
        ];
    }
}

echo json_encode([
    'ok' => true,
    'unread' => $unread,
    'max_unread_id' => $max_unread_id,
    'alerts' => $alerts,
]);
