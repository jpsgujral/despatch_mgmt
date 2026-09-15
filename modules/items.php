<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
/* ── Page-level view permission check ── */
requirePerm('items', 'view');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if (isset($_GET['delete'])) {
    requirePerm('items', 'delete');
    $db->query("DELETE FROM items WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Item deleted successfully.');
    redirect('items.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requirePerm('items', $id > 0 ? 'update' : 'create');
    $fields = ['item_code','item_name','description','category','uom','hsn_code','status'];
    $data = [];
    foreach ($fields as $f) $data[$f] = sanitize($_POST[$f] ?? '');

    if (empty($data['item_code']) || empty($data['item_name'])) {
        showAlert('danger', 'Item Code and Item Name are required.');
    } else {
        if ($id > 0) {
            $set = implode(',', array_map(fn($k,$v) => "$k='$v'", array_keys($data), $data));
            $db->query("UPDATE items SET $set WHERE id=$id");
            showAlert('success', 'Item updated successfully.');
        } else {
            $cols = implode(',', array_keys($data));
            $vals = "'" . implode("','", array_values($data)) . "'";
            $db->query("INSERT INTO items ($cols) VALUES ($vals)");
            showAlert('success', 'Item added successfully.');
        }
        redirect('items.php');
    }
}

$item = [];
if ($action == 'edit' && $id > 0) {
    $item = $db->query("SELECT * FROM items WHERE id=$id")->fetch_assoc();
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-box-seam me-2"></i>Item Master';</script>

<?php if ($action == 'list'): ?>
<div class="card shadow-sm">
<div class="card-header d-flex justify-content-between align-items-center py-2"
     style="background:linear-gradient(135deg,#1E3A8A,#2563EB);border-left:4px solid #172554;color:#fff">
    <span class="fw-bold"><i class="bi bi-box-seam me-2"></i>Item Master</span>
    <a href="?action=add" class="btn btn-sm btn-light fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Item</a>
</div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover datatable mb-0">
        <thead class="table-light"><tr>
            <th>#</th><th>Item Code</th><th>Item Name</th>
            <th>HSN</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php
        $items = $db->query("SELECT * FROM items ORDER BY item_name");
        $i=1;
        while ($v=$items->fetch_assoc()):
        ?>
        <tr>
            <td class="text-muted"><?= $i++ ?></td>
            <td><span class="badge bg-primary"><?= htmlspecialchars($v['item_code']) ?></span></td>
            <td class="fw-bold"><?= htmlspecialchars($v['item_name']) ?></td>
            <td><code class="fw-bold"><?= htmlspecialchars($v['hsn_code']) ?></code></td>
            <td>
                <a href="item_offer_email.php?id=<?= $v['id'] ?>" class="btn btn-action btn-outline-success me-1" title="Send Offer">
                    <i class="bi bi-envelope-paper"></i>
                </a>
                <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                <button onclick="confirmDelete(<?= $v['id'] ?>,'items.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $action=='edit'?'Edit':'Add New' ?> Item</h5>
    <a href="items.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<div class="card shadow-sm">
<div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-box-seam me-2"></i>Item Details</div>
<div class="card-body">
<div class="row g-3">
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label">Item Code *</label>
        <input type="text" name="item_code" class="form-control" required value="<?= htmlspecialchars($item['item_code'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Item Name *</label>
        <input type="text" name="item_name" class="form-control" required value="<?= htmlspecialchars($item['item_name'] ?? '') ?>">
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label">Category</label>
        <select name="category" class="form-select">
            <?php foreach(['Raw Material','Finished Goods','Components','Spare Parts','Consumables','Packing Material','Other'] as $cat): ?>
            <option value="<?= $cat ?>" <?= ($item['category']??'')==$cat?'selected':'' ?>><?= $cat ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="1"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">UOM *</label>
        <select name="uom" class="form-select">
            <?php foreach(['MT','Nos','Kg','Gm','Litre','Mtr','Cm','Set','Box','Carton','Bundle','Pair','Dozen','Bag'] as $u): ?>
            <option value="<?= $u ?>" <?= ($item['uom'] ?? 'MT') == $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">HSN Code</label>
        <input type="text" name="hsn_code" class="form-control" value="<?= htmlspecialchars($item['hsn_code'] ?? '') ?>">
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="Active" <?= ($item['status']??'Active')=='Active'?'selected':'' ?>>Active</option>
            <option value="Inactive" <?= ($item['status']??'')=='Inactive'?'selected':'' ?>>Inactive</option>
        </select>
    </div>
</div>
</div>
</div>
<div class="text-end mt-3">
    <a href="items.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $action=='edit'?'Update':'Save' ?> Item</button>
</div>
</form>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
