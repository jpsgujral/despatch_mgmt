<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<pre>";
    echo "POST data:\n";
    print_r($_POST);
    echo "\nFILES data:\n";
    print_r($_FILES);
    
    if (!empty($_FILES['testfile']['name'])) {
        echo "\nFile received: " . $_FILES['testfile']['name'] . "\n";
        echo "Size: " . $_FILES['testfile']['size'] . " bytes\n";
        echo "Error code: " . $_FILES['testfile']['error'] . "\n";
        echo "Tmp name: " . $_FILES['testfile']['tmp_name'] . "\n";
        echo "Tmp exists: " . (file_exists($_FILES['testfile']['tmp_name']) ? 'YES' : 'NO') . "\n";
    } else {
        echo "\nNO FILE RECEIVED\n";
    }
    echo "</pre>";
    echo '<a href="test_upload.php">Try again</a>';
    exit;
}
?>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="testfile"><br><br>
    <input type="hidden" name="test_field" value="hello">
    <button type="submit">Upload Test</button>
</form>
