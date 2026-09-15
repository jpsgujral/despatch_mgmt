<?php
/**
 * Safe SQL export helper for local backup/import workflows.
 *
 * Usage:
 *   Browser: open /backup/export_sql.php as an admin to download a .sql file
 *   CLI:     php backup/export_sql.php > backup.sql
 *   CLI one table: php backup/export_sql.php agent_commissions > agent_commissions.sql
 */

if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/../includes/config.php';
} else {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/auth.php';
    if (!isAdmin()) {
        http_response_code(403);
        exit('Admin access required.');
    }
}

$db = getDB();
$db->set_charset('utf8mb4');

$tableOnly = null;
if (PHP_SAPI === 'cli' && !empty($argv[1])) {
    $tableOnly = preg_replace('/[^A-Za-z0-9_]/', '', $argv[1]);
    if ($tableOnly === '') $tableOnly = null;
} elseif (!empty($_GET['table'])) {
    $tableOnly = preg_replace('/[^A-Za-z0-9_]/', '', $_GET['table']);
    if ($tableOnly === '') $tableOnly = null;
}

$lines = [];
$lines[] = "-- Despatch Management SQL Export";
$lines[] = "-- Generated: " . date('Y-m-d H:i:s');
$lines[] = "-- Database: " . DB_NAME;
$lines[] = "";
$lines[] = "SET FOREIGN_KEY_CHECKS=0;";
$lines[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
$lines[] = "SET NAMES utf8mb4;";
$lines[] = "";

$tables = [];
if ($tableOnly) {
    $tables[] = $tableOnly;
} else {
    $res = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
}

foreach ($tables as $table) {
    $escTable = $db->real_escape_string($table);
    $show = $db->query("SHOW CREATE TABLE `{$escTable}`");
    if (!$show) {
        continue;
    }
    $createRow = $show->fetch_row();
    if (!$createRow || empty($createRow[1])) {
        continue;
    }

    $lines[] = "-- Table: `{$table}`";
    $lines[] = "DROP TABLE IF EXISTS `{$table}`;";
    $lines[] = $createRow[1] . ";";
    $lines[] = "";

    $countRes = $db->query("SELECT COUNT(*) AS c FROM `{$escTable}`");
    $countRow = $countRes ? $countRes->fetch_assoc() : ['c' => 0];
    $count = (int)($countRow['c'] ?? 0);
    if ($count === 0) {
        continue;
    }

    $offset = 0;
    $batch = 500;
    while ($offset < $count) {
        $dataRes = $db->query("SELECT * FROM `{$escTable}` LIMIT {$batch} OFFSET {$offset}");
        if (!$dataRes) {
            break;
        }
        $rows = [];
        while ($row = $dataRes->fetch_row()) {
            $vals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = "'" . $db->real_escape_string($val) . "'";
                }
            }
            $rows[] = '(' . implode(',', $vals) . ')';
        }
        if ($rows) {
            $lines[] = "INSERT INTO `{$table}` VALUES";
            $lines[] = implode(",\n", $rows) . ";";
            $lines[] = "";
        }
        $offset += $batch;
    }
}

if (!$tableOnly) {
    $views = [];
    $vres = $db->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
    while ($row = $vres->fetch_row()) {
        $views[] = $row[0];
    }
    foreach ($views as $view) {
        $escView = $db->real_escape_string($view);
        $show = $db->query("SHOW CREATE VIEW `{$escView}`");
        if (!$show) {
            continue;
        }
        $createRow = $show->fetch_row();
        if (!$createRow || empty($createRow[1])) {
            continue;
        }
        $lines[] = "-- View: `{$view}`";
        $lines[] = "DROP VIEW IF EXISTS `{$view}`;";
        $lines[] = $createRow[1] . ";";
        $lines[] = "";
    }
}

$lines[] = "SET FOREIGN_KEY_CHECKS=1;";
$sql = implode("\n", $lines) . "\n";

if (PHP_SAPI === 'cli') {
    if (!empty($argv[2]) && preg_match('/^[A-Za-z0-9_.-]+$/', $argv[2])) {
        file_put_contents($argv[2], $sql);
        fwrite(STDOUT, "Export written to {$argv[2]}\n");
    } else {
        fwrite(STDOUT, $sql);
    }
    exit(0);
}

$baseName = $tableOnly ? $tableOnly : DB_NAME;
$fileName = $baseName . '_' . date('Ymd_His') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($sql));
echo $sql;
