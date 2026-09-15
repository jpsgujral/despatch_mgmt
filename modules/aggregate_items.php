<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/trading_common.php';
$db = getDB();
tradingEnsureTables($db);
requirePerm('aggregate_items', 'view');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if (isset($_GET['delete'])) {
    requirePerm('aggregate_items', 'delete');
    $db->query("DELETE FROM aggregate_items WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Aggregate item deleted.');
    redirect('aggregate_items.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('aggregate_items', $id > 0 ? 'update' : 'create');
    $data = [
        'item_code' => sanitize($_POST['item_code'] ?? ''),
        'item_name' => sanitize($_POST['item_name'] ?? ''),
        'description' => sanitize($_POST['description'] ?? ''),
        'uom' => sanitize($_POST['uom'] ?? 'MT'),
        'hsn_code' => sanitize($_POST['hsn_code'] ?? ''),
        'rate_per_unit' => (float)($_POST['rate_per_unit'] ?? 0),
        'conversion_ratio' => max(0.0001, (float)($_POST['conversion_ratio'] ?? 1)),
        'status' => sanitize($_POST['status'] ?? 'Active'),
    ];
    $errors = [];
    if ($data['item_code'] === '') $errors[] = 'Item Code is required.';
    if ($data['item_name'] === '') $errors[] = 'Item Name is required.';
    $dup = $db->query("SELECT id FROM aggregate_items WHERE item_code='" . $db->real_escape_string($data['item_code']) . "'" . ($id > 0 ? " AND id!=$id" : ''));
    if ($dup && $dup->num_rows > 0) $errors[] = 'Item Code already exists.';
    if ($errors) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        $setParts = [];
        foreach ($data as $k => $v) $setParts[] = is_numeric($v) ? "$k=$v" : "$k='" . $db->real_escape_string((string)$v) . "'";
        if ($id > 0) {
            $db->query("UPDATE aggregate_items SET " . implode(',', $setParts) . " WHERE id=$id");
            showAlert('success', 'Aggregate item updated.');
        } else {
            $db->query("INSERT INTO aggregate_items (item_code,item_name,description,uom,hsn_code,rate_per_unit,conversion_ratio,status) VALUES ('" . $db->real_escape_string($data['item_code']) . "','" . $db->real_escape_string($data['item_name']) . "','" . $db->real_escape_string($data['description']) . "','" . $db->real_escape_string($data['uom']) . "','" . $db->real_escape_string($data['hsn_code']) . "',{$data['rate_per_unit']},{$data['conversion_ratio']},'" . $db->real_escape_string($data['status']) . "')");
            showAlert('success', 'Aggregate item added.');
        }
        redirect('aggregate_items.php');
    }
}

$item = [];
if ($action === 'edit' && $id > 0) $item = tradingFetchRow($db, "SELECT * FROM aggregate_items WHERE id=$id LIMIT 1");

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-box-seam me-2"></i>Aggregate Items';</script>
<?php if ($action === 'list'): ?>
<div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);border-left:4px solid #172554;color:#fff"><span class="fw-bold"><i class="bi bi-box-seam me-2"></i>Aggregate Item Master</span><?php if (canDo('aggregate_items','create')): ?><a href="?action=add" class="btn btn-sm btn-light fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Item</a><?php endif; ?></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead class="table-light"><tr><th>#</th><th>Code</th><th>Item</th><th>UOM</th><th>Rate</th><th>Conversion</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $list = tradingFetchAll($db, "SELECT * FROM aggregate_items ORDER BY item_name"); $i=1; foreach ($list as $v): ?><tr><td><?= $i++ ?></td><td><span class="badge bg-primary"><?= htmlspecialchars($v['item_code']) ?></span></td><td class="fw-bold"><?= htmlspecialchars($v['item_name']) ?></td><td><?= htmlspecialchars($v['uom']) ?></td><td>Rs.<?= number_format((float)$v['rate_per_unit'],2) ?></td><td><?= number_format((float)$v['conversion_ratio'],4) ?></td><td><span class="badge bg-<?= $v['status']==='Active'?'primary':'secondary' ?>"><?= htmlspecialchars($v['status']) ?></span></td><td><?php if (canDo('aggregate_items','update')): ?><a href="?action=edit&id=<?= (int)$v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a><?php endif; ?><?php if (canDo('aggregate_items','delete')): ?><button onclick="confirmDelete(<?= (int)$v['id'] ?>,'aggregate_items.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0 fw-bold"><?= $action==='edit'?'Edit':'Add' ?> Aggregate Item</h5><a href="aggregate_items.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a></div>
<form method="POST"><div class="card shadow-sm"><div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-box-seam me-2"></i>Item Details</div><div class="card-body"><div class="row g-3">
<div class="col-6 col-md-3"><label class="form-label">Item Code *</label><input type="text" name="item_code" class="form-control" required value="<?= htmlspecialchars($item['item_code'] ?? '') ?>"></div>
<div class="col-12 col-md-5"><label class="form-label">Item Name *</label><input type="text" name="item_name" class="form-control" required value="<?= htmlspecialchars($item['item_name'] ?? '') ?>"></div>
<div class="col-6 col-md-2"><label class="form-label">UOM</label><select name="uom" class="form-select"><?php foreach(['MT','Nos','Kg','Bag'] as $u): ?><option value="<?= $u ?>" <?= ($item['uom'] ?? 'MT') === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="Active" <?= ($item['status']??'Active')==='Active'?'selected':'' ?>>Active</option><option value="Inactive" <?= ($item['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option></select></div>
<div class="col-12 col-md-5"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="1"><?= htmlspecialchars($item['description'] ?? '') ?></textarea></div>
<div class="col-6 col-md-2"><label class="form-label">HSN Code</label><input type="text" name="hsn_code" class="form-control" value="<?= htmlspecialchars($item['hsn_code'] ?? '') ?>"></div>
<div class="col-6 col-md-2"><label class="form-label">Rate</label><input type="number" name="rate_per_unit" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars((string)($item['rate_per_unit'] ?? '0')) ?>"></div>
<div class="col-6 col-md-3"><label class="form-label">Conversion Ratio</label><input type="number" name="conversion_ratio" class="form-control" step="0.0001" min="0.0001" value="<?= htmlspecialchars((string)($item['conversion_ratio'] ?? '1.0000')) ?>"><div class="form-text">Royalty MT = Royalty Cmtr / Conversion Ratio</div></div>
</div></div></div><div class="text-end mt-3"><a href="aggregate_items.php" class="btn btn-outline-secondary me-2">Cancel</a><button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $action==='edit'?'Update':'Save' ?></button></div></form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
