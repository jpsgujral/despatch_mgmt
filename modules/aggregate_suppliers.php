<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/trading_common.php';
$db = getDB();
tradingEnsureTables($db);
requirePerm('aggregate_suppliers', 'view');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if (isset($_GET['delete'])) {
    requirePerm('aggregate_suppliers', 'delete');
    $db->query("DELETE FROM aggregate_suppliers WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Aggregate supplier deleted.');
    redirect('aggregate_suppliers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('aggregate_suppliers', $id > 0 ? 'update' : 'create');
    $data = [
        'supplier_code' => sanitize($_POST['supplier_code'] ?? ''),
        'supplier_name' => sanitize($_POST['supplier_name'] ?? ''),
        'contact_person' => sanitize($_POST['contact_person'] ?? ''),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'mobile' => sanitize($_POST['mobile'] ?? ''),
        'gstin' => sanitize($_POST['gstin'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'city' => sanitize($_POST['city'] ?? ''),
        'state' => sanitize($_POST['state'] ?? ''),
        'status' => sanitize($_POST['status'] ?? 'Active'),
    ];
    $errors = [];
    if ($data['supplier_code'] === '') $errors[] = 'Supplier Code is required.';
    if ($data['supplier_name'] === '') $errors[] = 'Supplier Name is required.';
    $dup = $db->query("SELECT id FROM aggregate_suppliers WHERE supplier_code='" . $db->real_escape_string($data['supplier_code']) . "'" . ($id > 0 ? " AND id!=$id" : ''));
    if ($dup && $dup->num_rows > 0) $errors[] = 'Supplier Code already exists.';
    if ($errors) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        $set = implode(',', array_map(fn($k, $v) => "$k='$v'", array_keys($data), $data));
        if ($id > 0) {
            $db->query("UPDATE aggregate_suppliers SET $set WHERE id=$id");
            showAlert('success', 'Aggregate supplier updated.');
        } else {
            $db->query("INSERT INTO aggregate_suppliers (" . implode(',', array_keys($data)) . ") VALUES ('" . implode("','", array_values($data)) . "')");
            showAlert('success', 'Aggregate supplier added.');
        }
        redirect('aggregate_suppliers.php');
    }
}

$supplier = [];
if ($action === 'edit' && $id > 0) $supplier = tradingFetchRow($db, "SELECT * FROM aggregate_suppliers WHERE id=$id LIMIT 1");

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-truck-front me-2"></i>Aggregate Suppliers';</script>
<?php if ($action === 'list'): ?>
<div class="card shadow-sm">
<div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);border-left:4px solid #172554;color:#fff">
    <span class="fw-bold"><i class="bi bi-truck-front me-2"></i>Aggregate Supplier Master</span>
    <?php if (canDo('aggregate_suppliers','create')): ?><a href="?action=add" class="btn btn-sm btn-light fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Supplier</a><?php endif; ?>
</div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0">
<thead class="table-light"><tr><th>#</th><th>Code</th><th>Name</th><th>Mobile</th><th>City</th><th>Status</th><th>Actions</th></tr></thead>
<tbody><?php $list = tradingFetchAll($db, "SELECT * FROM aggregate_suppliers ORDER BY supplier_name"); $i=1; foreach ($list as $v): ?><tr>
<td><?= $i++ ?></td><td><span class="badge bg-dark"><?= htmlspecialchars($v['supplier_code']) ?></span></td><td class="fw-bold"><?= htmlspecialchars($v['supplier_name']) ?></td><td><?= htmlspecialchars($v['mobile'] ?: '-') ?></td><td><?= htmlspecialchars($v['city'] ?: '-') ?></td><td><span class="badge bg-<?= $v['status']==='Active'?'primary':'secondary' ?>"><?= htmlspecialchars($v['status']) ?></span></td>
<td><?php if (canDo('aggregate_suppliers','update')): ?><a href="?action=edit&id=<?= (int)$v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a><?php endif; ?><?php if (canDo('aggregate_suppliers','delete')): ?><button onclick="confirmDelete(<?= (int)$v['id'] ?>,'aggregate_suppliers.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div></div></div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0 fw-bold"><?= $action==='edit'?'Edit':'Add' ?> Aggregate Supplier</h5><a href="aggregate_suppliers.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a></div>
<form method="POST"><div class="card shadow-sm"><div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-truck-front me-2"></i>Supplier Details</div><div class="card-body"><div class="row g-3">
<div class="col-6 col-md-3"><label class="form-label">Supplier Code *</label><input type="text" name="supplier_code" class="form-control" required value="<?= htmlspecialchars($supplier['supplier_code'] ?? '') ?>"></div>
<div class="col-12 col-md-5"><label class="form-label">Supplier Name *</label><input type="text" name="supplier_name" class="form-control" required value="<?= htmlspecialchars($supplier['supplier_name'] ?? '') ?>"></div>
<div class="col-6 col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="Active" <?= ($supplier['status']??'Active')==='Active'?'selected':'' ?>>Active</option><option value="Inactive" <?= ($supplier['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option></select></div>
<div class="col-6 col-md-4"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>"></div>
<div class="col-6 col-md-4"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>"></div>
<div class="col-6 col-md-4"><label class="form-label">Mobile</label><input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($supplier['mobile'] ?? '') ?>"></div>
<div class="col-6 col-md-4"><label class="form-label">GSTIN</label><input type="text" name="gstin" class="form-control" value="<?= htmlspecialchars($supplier['gstin'] ?? '') ?>"></div>
<div class="col-6 col-md-4"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($supplier['city'] ?? '') ?>"></div>
<div class="col-6 col-md-4"><label class="form-label">State</label><input type="text" name="state" class="form-control" value="<?= htmlspecialchars($supplier['state'] ?? '') ?>"></div>
<div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($supplier['address'] ?? '') ?></textarea></div>
</div></div></div><div class="text-end mt-3"><a href="aggregate_suppliers.php" class="btn btn-outline-secondary me-2">Cancel</a><button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $action==='edit'?'Update':'Save' ?></button></div></form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
