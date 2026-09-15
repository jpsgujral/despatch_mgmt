<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (!isAdmin()) die('Admin only');

echo '<style>body{font-family:Arial;padding:20px} table{border-collapse:collapse;width:100%} td,th{border:1px solid #ccc;padding:6px 10px} .ok{color:green} .fail{color:red} .warn{color:orange}</style>';
echo '<h2>Upload Diagnostic</h2>';

// PHP config
echo '<h3>PHP Upload Settings</h3><table>';
$cfg = [
    'file_uploads'       => ini_get('file_uploads'),
    'upload_max_filesize'=> ini_get('upload_max_filesize'),
    'post_max_size'      => ini_get('post_max_size'),
    'max_file_uploads'   => ini_get('max_file_uploads'),
    'upload_tmp_dir'     => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
];
foreach ($cfg as $k => $v) {
    $ok = ($k === 'file_uploads' && $v == '1') ? 'ok' : '';
    echo "<tr><td><b>$k</b></td><td class='$ok'>$v</td></tr>";
}
echo '</table>';

// Upload dir check
$img_dir = dirname(__DIR__) . '/uploads/company/';
echo '<h3>Upload Directory</h3><table>';
echo '<tr><td>Path</td><td>'.htmlspecialchars($img_dir).'</td></tr>';
echo '<tr><td>Exists</td><td class="'.(is_dir($img_dir)?'ok':'fail').'">'.(is_dir($img_dir)?'YES':'NO — will be created on save').'</td></tr>';
echo '<tr><td>Writable</td><td class="'.(is_writable($img_dir)?'ok':'fail').'">'.(is_writable($img_dir)?'YES':'NO — PERMISSION ERROR').'</td></tr>';
echo '</table>';

// Test upload form
echo '<h3>Test Upload</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_file'])) {
    echo '<table>';
    echo '<tr><td>$_FILES data</td><td><pre>'.print_r($_FILES['test_file'], true).'</pre></td></tr>';
    $f = $_FILES['test_file'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        echo '<tr><td>Error</td><td class="ok">NONE</td></tr>';
        echo '<tr><td>Tmp file exists</td><td>'.(file_exists($f['tmp_name'])?'<span class="ok">YES</span>':'<span class="fail">NO</span>').'</td></tr>';
        $info = @getimagesize($f['tmp_name']);
        echo '<tr><td>getimagesize result</td><td>'.($info ? '<span class="ok">'.print_r($info,true).'</span>' : '<span class="fail">FAILED — not detected as image</span>').'</td></tr>';
        // Try to move
        if (!is_dir($img_dir)) @mkdir($img_dir, 0755, true);
        $dest = $img_dir.'test_'.time().'.'.strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        $moved = move_uploaded_file($f['tmp_name'], $dest);
        echo '<tr><td>move_uploaded_file</td><td class="'.($moved?'ok':'fail').'">'.($moved?'SUCCESS → '.$dest:'FAILED').'</td></tr>';
        if ($moved) unlink($dest); // cleanup
    } else {
        $errors = [1=>'File too large (php.ini)',2=>'File too large (form)',3=>'Partial upload',4=>'No file',6=>'No tmp dir',7=>'Write failed',8=>'Stopped by extension'];
        echo '<tr><td>Upload Error</td><td class="fail">'.$f['error'].': '.($errors[$f['error']]??'Unknown').'</td></tr>';
    }
    echo '</table>';
}
?>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="test_file" accept="image/*" required style="margin:10px 0;display:block">
    <button type="submit" style="background:#1a5632;color:#fff;border:none;padding:8px 20px;cursor:pointer">Test Upload</button>
</form>
