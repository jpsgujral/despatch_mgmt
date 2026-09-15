<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/trading_common.php';
$db = getDB();
tradingEnsureTables($db);
requirePerm('aggregate_sources', 'view');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if (isset($_GET['delete'])) {
    requirePerm('aggregate_sources', 'delete');
    $db->query("DELETE FROM aggregate_sources WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Aggregate source deleted.');
    redirect('aggregate_sources.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('aggregate_sources', $id > 0 ? 'update' : 'create');
    $source_code = sanitize($_POST['source_code'] ?? '');
    $source_name = sanitize($_POST['source_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $status = sanitize($_POST['status'] ?? 'Active');
    $errors = [];
    if ($source_code === '') $errors[] = 'Source Code is required.';
    if ($source_name === '') $errors[] = 'Source Name is required.';
    $dup = $db->query("SELECT id FROM aggregate_sources WHERE source_code='" . $db->real_escape_string($source_code) . "'" . ($id > 0 ? " AND id!=$id" : ''));
    if ($dup && $dup->num_rows > 0) $errors[] = 'Source Code already exists.';
    if ($errors) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        if ($id > 0) {
            $db->query("UPDATE aggregate_sources SET source_code='" . $db->real_escape_string($source_code) . "', source_name='" . $db->real_escape_string($source_name) . "', description='" . $db->real_escape_string($description) . "', status='" . $db->real_escape_string($status) . "' WHERE id=$id");
            showAlert('success', 'Aggregate source updated.');
        } else {
            $db->query("INSERT INTO aggregate_sources (source_code,source_name,description,status) VALUES ('" . $db->real_escape_string($source_code) . "','" . $db->real_escape_string($source_name) . "','" . $db->real_escape_string($description) . "','" . $db->real_escape_string($status) . "')");
            showAlert('success', 'Aggregate source added.');
        }
        redirect('aggregate_sources.php');
    }
}

$source = [];
if ($action === 'edit' && $id > 0) $source = tradingFetchRow($db, "SELECT * FROM aggregate_sources WHERE id=$id LIMIT 1");

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-geo-fill me-2"></i>Aggregate Sources';</script>
<?php if ($action === 'list'): ?>
<div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);border-left:4px solid #172554;color:#fff"><span class="fw-bold"><i class="bi bi-geo-fill me-2"></i>Aggregate Source Master</span><?php if (canDo('aggregate_sources','create')): ?><a href="?action=add" class="btn btn-sm btn-light fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Source</a><?php endif; ?></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead class="table-light"><tr><th>#</th><th>Code</th><th>Source Name</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $list = tradingFetchAll($db, "SELECT * FROM aggregate_sources ORDER BY source_name"); $i=1; foreach ($list as $v): ?><tr><td><?= $i++ ?></td><td><span class="badge bg-dark"><?= htmlspecialchars($v['source_code']) ?></span></td><td class="fw-bold"><?= htmlspecialchars($v['source_name']) ?></td><td><?= htmlspecialchars($v['description'] ?: '-') ?></td><td><span class="badge bg-<?= $v['status']==='Active'?'primary':'secondary' ?>"><?= htmlspecialchars($v['status']) ?></span></td><td><?php if (canDo('aggregate_sources','update')): ?><a href="?action=edit&id=<?= (int)$v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a><?php endif; ?><?php if (canDo('aggregate_sources','delete')): ?><button onclick="confirmDelete(<?= (int)$v['id'] ?>,'aggregate_sources.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0 fw-bold"><?= $action==='edit'?'Edit':'Add' ?> Aggregate Source</h5><a href="aggregate_sources.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a></div>
<form method="POST"><div class="card shadow-sm"><div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-geo-fill me-2"></i>Source Details</div><div class="card-body"><div class="row g-3">
<div class="col-6 col-md-3"><label class="form-label">Source Code *</label><input type="text" name="source_code" class="form-control" required value="<?= htmlspecialchars($source['source_code'] ?? '') ?>"></div>
<div class="col-12 col-md-5"><label class="form-label">Source Name *</label><input type="text" name="source_name" class="form-control" required value="<?= htmlspecialchars($source['source_name'] ?? '') ?>"></div>
<div class="col-6 col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="Active" <?= ($source['status']??'Active')==='Active'?'selected':'' ?>>Active</option><option value="Inactive" <?= ($source['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option></select></div>
<div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" value="<?= htmlspecialchars($source['description'] ?? '') ?>"></div>
</div></div></div><div class="text-end mt-3"><a href="aggregate_sources.php" class="btn btn-outline-secondary me-2">Cancel</a><button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $action==='edit'?'Update':'Save' ?></button></div></form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
