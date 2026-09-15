<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('source_of_material', 'view');

/* Ensure table exists */
$db->query("CREATE TABLE IF NOT EXISTS source_of_material (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    source_code VARCHAR(30) NOT NULL UNIQUE,
    source_name VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    status      ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if (isset($_GET['delete'])) {
    requirePerm('source_of_material', 'delete');
    $db->query("DELETE FROM source_of_material WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Source of Material deleted.');
    redirect('source_of_material.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('source_of_material', $id > 0 ? 'update' : 'create');
    $source_code = sanitize($_POST['source_code'] ?? '');
    $source_name = sanitize($_POST['source_name'] ?? '');
    $description = substr(preg_replace('/\D/', '', sanitize($_POST['description'] ?? '')), 0, 6);
    $status      = sanitize($_POST['status'] ?? 'Active');

    $errors = [];
    if (empty($source_code)) $errors[] = 'Source Code is required.';
    if (empty($source_name)) $errors[] = 'Source Name is required.';

    if ($description !== '' && !preg_match('/^\d{6}$/', $description)) $errors[] = 'Pin Code must be 6 digits.';

    $codeEsc = $db->real_escape_string($source_code);
    $dup = $db->query("SELECT id FROM source_of_material WHERE source_code='$codeEsc'" . ($id > 0 ? " AND id!=$id" : ""))->num_rows;
    if ($dup > 0) $errors[] = 'Source Code already exists.';

    if (!empty($errors)) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        $esc = fn($v) => $db->real_escape_string($v);
        if ($id > 0) {
            $db->query("UPDATE source_of_material SET
                source_code='{$esc($source_code)}', source_name='{$esc($source_name)}',
                description='{$esc($description)}', status='{$esc($status)}'
                WHERE id=$id");
            showAlert('success', 'Source of Material updated.');
        } else {
            $db->query("INSERT INTO source_of_material (source_code, source_name, description, status)
                VALUES ('{$esc($source_code)}','{$esc($source_name)}','{$esc($description)}','{$esc($status)}')");
            showAlert('success', 'Source of Material added.');
        }
        redirect('source_of_material.php');
    }
}

$som = [];
if ($action === 'edit' && $id > 0) {
    $som = $db->query("SELECT * FROM source_of_material WHERE id=$id")->fetch_assoc();
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-geo-fill me-2"></i>Source of Material';</script>

<?php if ($action === 'list'): ?>
<div class="card shadow-sm">
<div class="card-header d-flex justify-content-between align-items-center py-2"
     style="background:linear-gradient(135deg,#1E3A8A,#2563EB);border-left:4px solid #172554;color:#fff">
    <span class="fw-bold"><i class="bi bi-geo-fill me-2"></i>Source of Material</span>
    <?php if (canDo('source_of_material','create')): ?>
    <a href="?action=add" class="btn btn-sm btn-light fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Source</a>
    <?php endif; ?>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
    <thead class="table-light"><tr>
        <th>#</th><th>Code</th><th>Source Name</th><th style="width:110px">Pin Code</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php
    $list = $db->query("SELECT * FROM source_of_material ORDER BY source_name");
    $i = 1;
    while ($v = $list->fetch_assoc()):
    ?>
    <tr>
        <td class="text-muted"><?= $i++ ?></td>
        <td><span class="badge bg-dark"><?= htmlspecialchars($v['source_code']) ?></span></td>
        <td class="fw-bold"><?= htmlspecialchars($v['source_name']) ?></td>
        <td class="text-muted font-monospace" style="width:110px"><?= htmlspecialchars($v['description'] ?: '—') ?></td>
        <td><span class="badge bg-<?= $v['status']==='Active'?'primary':'secondary' ?>"><?= $v['status'] ?></span></td>
        <td>
            <?php if (canDo('source_of_material','update')): ?>
            <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (canDo('source_of_material','delete')): ?>
            <button onclick="confirmDelete(<?= $v['id'] ?>,'source_of_material.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div></div></div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $action==='edit'?'Edit':'Add' ?> Source of Material</h5>
    <a href="source_of_material.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<div class="row g-3">
<div class="col-12"><div class="card shadow-sm"><div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-geo-fill me-2"></i>Source Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Source Code *</label>
        <input type="text" name="source_code" class="form-control" required
               value="<?= htmlspecialchars($som['source_code']??'') ?>">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Source Name *</label>
        <input type="text" name="source_name" class="form-control" required
               value="<?= htmlspecialchars($som['source_name']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="Active"   <?= ($som['status']??'Active')==='Active'  ?'selected':'' ?>>Active</option>
            <option value="Inactive" <?= ($som['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Pin Code</label>
        <input type="text" name="description" class="form-control font-monospace" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
               value="<?= htmlspecialchars($som['description']??'') ?>">
    </div>
</div></div></div></div>
<div class="col-12 text-end">
    <a href="source_of_material.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $action==='edit'?'Update':'Save' ?></button>
</div>
</div>
</form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
