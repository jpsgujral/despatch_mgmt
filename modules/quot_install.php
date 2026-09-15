<?php
/**
 * Quotation Module - One-time DB Installer
 * Run once via browser or cPanel terminal, then DELETE this file.
 * Safe to run multiple times (uses IF NOT EXISTS).
 */
require_once '../includes/config.php';

$db = getDB();
$errors = [];
$success = [];

// Table 1: quotations (header)
$sql1 = "CREATE TABLE IF NOT EXISTS `quotations` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quotation_no`      VARCHAR(50) NOT NULL UNIQUE,
  `quotation_date`    DATE NOT NULL,
  `valid_till`        DATE NOT NULL,
  `quotation_for`     VARCHAR(255) NOT NULL DEFAULT 'Supply of Ashcrete Fly Ash',
  -- Client info
  `client_name`       VARCHAR(255) NOT NULL,
  `client_company`    VARCHAR(255) NOT NULL,
  `client_addr1`      VARCHAR(255) DEFAULT NULL,
  `client_addr2`      VARCHAR(255) DEFAULT NULL,
  `client_city`       VARCHAR(100) DEFAULT NULL,
  `client_state`      VARCHAR(100) DEFAULT NULL,
  `client_pin`        VARCHAR(10)  DEFAULT NULL,
  `client_mobile`     VARCHAR(20)  DEFAULT NULL,
  `client_email`      VARCHAR(150) DEFAULT NULL,
  -- Reference
  `ref_person`        VARCHAR(255) DEFAULT NULL,
  `ref_mobile`        VARCHAR(20)  DEFAULT NULL,
  `ref_email`         VARCHAR(150) DEFAULT NULL,
  -- Terms (overridable per quotation)
  `payment_terms`     TEXT DEFAULT NULL,
  `delivery_schedule` TEXT DEFAULT NULL,
  `offer_validity`    TEXT DEFAULT NULL,
  `rate_revision`     TEXT DEFAULT NULL,
  `other_terms`       TEXT DEFAULT NULL,
  -- Meta
  `status`            ENUM('draft','sent','accepted','rejected','expired') NOT NULL DEFAULT 'draft',
  `notes`             TEXT DEFAULT NULL,
  `created_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($db->query($sql1)) {
    $success[] = "Table `quotations` created / already exists.";
} else {
    $errors[] = "quotations: " . $db->error;
}

// Table 2: quotation_items (the particulars rows)
$sql2 = "CREATE TABLE IF NOT EXISTS `quotation_items` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quotation_id`  INT UNSIGNED NOT NULL,
  `sort_order`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `particular`    VARCHAR(255) NOT NULL,
  `detail`        TEXT NOT NULL,
  FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($db->query($sql2)) {
    $success[] = "Table `quotation_items` created / already exists.";
} else {
    $errors[] = "quotation_items: " . $db->error;
}

// Table 3: quotation_sequence (auto-numbering)
$sql3 = "CREATE TABLE IF NOT EXISTS `quotation_sequence` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `prefix`    VARCHAR(20) NOT NULL DEFAULT 'TSG',
  `year`      SMALLINT UNSIGNED NOT NULL,
  `last_seq`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `prefix_year` (`prefix`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($db->query($sql3)) {
    $success[] = "Table `quotation_sequence` created / already exists.";
} else {
    $errors[] = "quotation_sequence: " . $db->error;
}

$db->close();
?>
<!DOCTYPE html>
<html>
<head><title>Quotation Module Installer</title>
<style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px}
.ok{color:green}.err{color:red}h2{margin-bottom:20px}</style>
</head>
<body>
<h2>Quotation Module — DB Installer</h2>
<?php foreach($success as $s): ?>
  <p class="ok">✅ <?= htmlspecialchars($s) ?></p>
<?php endforeach; ?>
<?php foreach($errors as $e): ?>
  <p class="err">❌ <?= htmlspecialchars($e) ?></p>
<?php endforeach; ?>
<?php if(empty($errors)): ?>
  <hr>
  <p><strong>✅ Installation complete.</strong><br>
  <em>Please DELETE this file (<code>install.php</code>) from the server now.</em></p>
  <p><a href="quot_index.php">→ Go to Quotations Module</a></p>
<?php endif; ?>
</body>
</html>
