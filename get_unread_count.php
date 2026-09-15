<?php
/**
 * get_unread_count.php
 * Returns unread message count as JSON for badge polling.
 * Called via AJAX from header or any page.
 */
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

if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['unread' => 0]);
    exit;
}

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// Ensure table exists before querying
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

$res   = $db->query("SELECT COUNT(*) AS cnt FROM dms_messages WHERE recipient_id=$uid AND is_read=0 AND deleted_by_recipient=0");
$count = $res ? (int)$res->fetch_assoc()['cnt'] : 0;

header('Content-Type: application/json');
echo json_encode(['unread' => $count]);
