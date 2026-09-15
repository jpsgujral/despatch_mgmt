<?php
// R2 Upload Debug Tool — DELETE AFTER USE
// Upload to despatch_mgmt/ and open in browser

define('R2_WORKER_URL',  'https://dms-r2-upload.jpsgujral.workers.dev');
define('R2_WORKER_TOKEN','dms_worker_s3cur3_t0k3n_2024');

$result = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['testfile'])) {
    $f    = $_FILES['testfile'];
    $body = file_get_contents($f['tmp_name']);
    $contentLength = mb_strlen($body, '8bit');

    $ch = curl_init(R2_WORKER_URL . '/debug_test/test_upload.pdf');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POSTREDIR      => 7,
        CURLOPT_VERBOSE        => false,
        CURLOPT_HTTPHEADER     => [
            'X-DMS-Token: ' . R2_WORKER_TOKEN,
            'Content-Type: application/pdf',
            'Content-Length: ' . $contentLength,
            'Expect: ',
        ],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo  = curl_getinfo($ch);
    curl_close($ch);

    $result = [
        'file_name'      => $f['name'],
        'file_size'      => $f['size'],
        'content_length' => $contentLength,
        'http_code'      => $httpCode,
        'curl_error'     => $curlError,
        'worker_response'=> $response,
        'effective_url'  => $curlInfo['url'],
        'redirect_count' => $curlInfo['redirect_count'],
        'token_sent'     => R2_WORKER_TOKEN,
    ];
}
?>
<!DOCTYPE html>
<html>
<head><title>R2 Upload Test</title>
<style>body{font-family:monospace;padding:20px} pre{background:#f4f4f4;padding:15px;border-radius:5px}</style>
</head>
<body>
<h3>R2 Upload Debug</h3>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="testfile" accept=".pdf,.jpg,.png">
    <button type="submit">Test Upload</button>
</form>
<?php if ($result): ?>
<h4>Result:</h4>
<pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)) ?></pre>
<?php endif; ?>
</body>
</html>
