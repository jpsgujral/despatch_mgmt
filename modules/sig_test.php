<?php
require_once '../includes/config.php';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    echo "<h3>POST Result</h3>";
    echo "<b>\$_FILES:</b><pre>" . print_r($_FILES, true) . "</pre>";
    echo "<b>\$_POST keys:</b><pre>" . print_r(array_keys($_POST), true) . "</pre>";
    
    $img_dir = dirname(__DIR__) . '/uploads/company/';
    echo "<b>Upload dir exists:</b> " . (is_dir($img_dir) ? 'YES' : 'NO - will create') . "<br>";
    echo "<b>Upload dir writable:</b> " . (is_writable($img_dir) ? 'YES' : 'NO') . "<br>";
    
    if (!is_dir($img_dir)) {
        $made = @mkdir($img_dir, 0755, true);
        echo "<b>mkdir result:</b> " . ($made ? 'OK' : 'FAILED') . "<br>";
    }
    
    foreach (['seal','mtc_sig','checked_by_sig'] as $input_name) {
        if (empty($_FILES[$input_name]['name'])) continue;
        echo "<hr><b>Testing: $input_name</b><br>";
        $file = $_FILES[$input_name];
        echo "Error: {$file['error']}, Size: {$file['size']}<br>";
        $info = @getimagesize($file['tmp_name']);
        echo "getimagesize type: " . ($info ? $info[2] : 'FAILED') . "<br>";
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fname = $input_name . '_test_' . time() . '.' . $ext;
        $dest  = $img_dir . $fname;
        $moved = move_uploaded_file($file['tmp_name'], $dest);
        echo "move_uploaded_file: " . ($moved ? "<span style='color:green'>SUCCESS → $dest</span>" : "<span style='color:red'>FAILED</span>") . "<br>";
        if ($moved) echo "<img src='../uploads/company/$fname' style='max-height:80px'><br>";
    }
} else {
    echo '<form method="POST" enctype="multipart/form-data">';
    echo '<div class="mb-2"><label>Seal:</label> <input type="file" name="seal" accept="image/*"></div>';
    echo '<div class="mb-2"><label>MTC Sig:</label> <input type="file" name="mtc_sig" accept="image/*"></div>';
    echo '<div class="mb-2"><label>Checked By Sig:</label> <input type="file" name="checked_by_sig" accept="image/*"></div>';
    echo '<button type="submit">Test</button></form>';
}
