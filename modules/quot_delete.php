<?php
/**
 * Quotations Module — Delete (draft only)
 * Path: modules/quotations/delete.php
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('quotations', 'delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: quot_index.php'); exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) { header('Location: quot_index.php'); exit; }

$db = getDB();

// Only allow deleting drafts
$stmt = $db->prepare("SELECT id, status FROM quotations WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$q = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$q || $q['status'] !== 'draft') {
    $db->close();
    header('Location: quot_index.php'); exit;
}

$stmt = $db->prepare("DELETE FROM quotations WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();
$db->close();

header('Location: quot_index.php'); exit;
