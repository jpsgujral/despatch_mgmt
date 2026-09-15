<?php
// Create zip backup of entire despatch_mgmt project
$source = '/home/tsgimpex/public_html/despatch_mgmt';
$backup = '/home/tsgimpex/public_html/despatch_mgmt_backup_' . date('Y-m-d_His') . '.zip';

$zip = new ZipArchive();
if ($zip->open($backup, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Cannot create zip file");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
foreach ($iterator as $file) {
    $filePath   = $file->getRealPath();
    $relativePath = substr($filePath, strlen($source) + 1);
    
    if ($file->isDir()) {
        $zip->addEmptyDir($relativePath);
    } else {
        $zip->addFile($filePath, $relativePath);
        $count++;
    }
}

$zip->close();

$size = round(filesize($backup) / 1024 / 1024, 2);
echo "Backup created successfully!<br>";
echo "File: $backup<br>";
echo "Files included: $count<br>";
echo "Size: {$size} MB<br><br>";
echo "<a href='../despatch_mgmt_backup_" . date('Y-m-d_His') . ".zip'>Download</a> (if link doesn't work, download via cPanel File Manager)";
echo "<br><br><b>Backup filename: " . basename($backup) . "</b>";
