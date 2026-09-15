<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (file_exists('../includes/r2_helper.php')) require_once '../includes/r2_helper.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'error'=>'Not authenticated.']); exit;
}

$trip_id = (int)($_POST['trip_id'] ?? 0);
if (!$trip_id || empty($_FILES['doc_file']['name']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'error'=>'Invalid request or no file.']); exit;
}

$allowed = ['pdf','jpg','jpeg','png','webp','gif'];
$ext     = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
$size    = $_FILES['doc_file']['size'];

if (!in_array($ext, $allowed) || $size > 10*1024*1024) {
    echo json_encode(['success'=>false,'error'=>'Upload failed. Check file type (PDF/JPG/PNG/WEBP, max 10MB).']); exit;
}

$newKey  = 'trip_docs/trip_'.$trip_id.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
$tmpPath = $_FILES['doc_file']['tmp_name'];
$body    = file_get_contents($tmpPath);

$ch = curl_init(R2_WORKER_URL . '/' . $newKey);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER     => [
        'X-DMS-Token: ' . R2_WORKER_TOKEN,
        'Content-Type: ' . r2_mime($newKey),
        'Content-Length: ' . strlen($body),
    ],
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['success'=>false,'error'=>'Upload failed. Check file type (PDF/JPG/PNG/WEBP, max 10MB).']); exit;
}

$db       = getDB();
$doc_type = $db->real_escape_string(sanitize($_POST['doc_type'] ?? 'Other'));
$doc_name = $db->real_escape_string(sanitize($_POST['doc_name'] ?? $_FILES['doc_file']['name']));
$r2_esc   = $db->real_escape_string($newKey);
$uid      = (int)($_SESSION['user_id'] ?? 0);
$db->query("INSERT INTO fleet_trip_documents (trip_id,doc_type,doc_name,file_path,uploaded_by)
    VALUES ($trip_id,'$doc_type','$doc_name','$r2_esc',$uid)");
$new_id = $db->insert_id;

echo json_encode([
    'success'  => true,
    'id'       => $new_id,
    'r2_key'   => $newKey,
    'url'      => r2_url($newKey),
    'doc_type' => $doc_type,
    'doc_name' => $doc_name,
]);
