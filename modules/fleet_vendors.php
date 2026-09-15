<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_vendors', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_vendors (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_code     VARCHAR(20) DEFAULT '',
    vendor_name     VARCHAR(200) NOT NULL,
    contact_person  VARCHAR(120) DEFAULT '',
    phone           VARCHAR(20) DEFAULT '',
    email           VARCHAR(120) DEFAULT '',
    address         TEXT,
    city            VARCHAR(80) DEFAULT '',
    state           VARCHAR(80) DEFAULT '',
    gstin           VARCHAR(20) DEFAULT '',
    pan             VARCHAR(20) DEFAULT '',
    bank_name       VARCHAR(100) DEFAULT '',
    account_no      VARCHAR(40) DEFAULT '',
    ifsc_code       VARCHAR(20) DEFAULT '',
    vendor_type     VARCHAR(60) DEFAULT '',
    status          ENUM('Active','Inactive') DEFAULT 'Active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $refs = [];
    $r = $db->query("SELECT COUNT(*) c FROM fleet_purchase_orders WHERE vendor_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Fleet Purchase Orders';
    $r = $db->query("SELECT COUNT(*) c FROM fleet_trips WHERE vendor_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Trip Orders';
    if (!empty($refs)) {
        showAlert('danger', 'Cannot delete — vendor has linked records in: ' . implode(', ', $refs) . '. Set Status to Inactive instead.');
    } else {
        $db->query("DELETE FROM fleet_vendors WHERE id=$did");
        showAlert('success', 'Vendor deleted.');
    }
    redirect('fleet_vendors.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vendor'])) {
    requirePerm('fleet_vendors', $id > 0 ? 'update' : 'create');
    $code    = sanitize($_POST['vendor_code'] ?? '');
    $name    = sanitize($_POST['vendor_name']);
    $contact = sanitize($_POST['contact_person'] ?? '');
    $phone   = sanitize($_POST['phone'] ?? '');
    $email   = sanitize($_POST['email'] ?? '');
    $addr    = sanitize($_POST['address'] ?? '');
    $city    = sanitize($_POST['city'] ?? '');
    $state   = sanitize($_POST['state'] ?? '');
    $gstin   = sanitize($_POST['gstin'] ?? '');
    $pan     = sanitize($_POST['pan'] ?? '');
    $bank    = sanitize($_POST['bank_name'] ?? '');
    $accno   = sanitize($_POST['account_no'] ?? '');
    $ifsc    = sanitize($_POST['ifsc_code'] ?? '');
    $vtype   = sanitize($_POST['vendor_type'] ?? '');
    $status  = sanitize($_POST['status'] ?? 'Active');
    $notes   = sanitize($_POST['notes'] ?? '');

    if (!$name) { showAlert('danger','Vendor Name is required.'); redirect("fleet_vendors.php?action=$action&id=$id"); }

    if ($id > 0) {
        $db->query("UPDATE fleet_vendors SET
            vendor_code='$code', vendor_name='$name', contact_person='$contact',
            phone='$phone', email='$email', address='$addr', city='$city', state='$state',
            gstin='$gstin', pan='$pan', bank_name='$bank', account_no='$accno',
            ifsc_code='$ifsc', vendor_type='$vtype', status='$status', notes='$notes'
            WHERE id=$id");
        showAlert('success', 'Vendor updated.');
    } else {
        $db->query("INSERT INTO fleet_vendors
            (vendor_code,vendor_name,contact_person,phone,email,address,city,state,
             gstin,pan,bank_name,account_no,ifsc_code,vendor_type,status,notes)
            VALUES ('$code','$name','$contact','$phone','$email','$addr','$city','$state',
            '$gstin','$pan','$bank','$accno','$ifsc','$vtype','$status','$notes')");
        showAlert('success', 'Vendor added.');
    }
    redirect('fleet_vendors.php');
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-building me-2"></i>Fleet Vendor Master';</script>
<?php

/* ── LIST ── */
if ($action === 'list'):
$vendors = $db->query("SELECT * FROM fleet_vendors ORDER BY status ASC, vendor_name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fleet Vendor Master</h5>
    <?php if (canDo('fleet_vendors','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Vendor</a>
    <?php endif; ?>
</div>
<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Code</th><th>Vendor Name</th><th>Type</th><th>Contact</th>
    <th>Phone</th><th>City</th><th>GSTIN</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($vendors as $v):
    $sc = $v['status'] === 'Active' ? 'success' : 'secondary';
?>
<tr>
    <td><?= htmlspecialchars($v['vendor_code'] ?: '—') ?></td>
    <td><strong><?= htmlspecialchars($v['vendor_name']) ?></strong></td>
    <td><?= htmlspecialchars($v['vendor_type'] ?: '—') ?></td>
    <td><?= htmlspecialchars($v['contact_person'] ?: '—') ?></td>
    <td><?= htmlspecialchars($v['phone'] ?: '—') ?></td>
    <td><?= htmlspecialchars($v['city'] ?: '—') ?></td>
    <td><?= htmlspecialchars($v['gstin'] ?: '—') ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $v['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_vendors','update')): ?>
        <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $v['id'] ?>" onclick="return confirm('Delete vendor <?= htmlspecialchars(addslashes($v['vendor_name'])) ?>?\nThis will fail if vendor has linked POs or trips.\nSet Status to Inactive to disable instead.')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<?php
/* ── ADD / EDIT ── */
else:
$v = [];
if ($id > 0) $v = $db->query("SELECT * FROM fleet_vendors WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
$vendor_types = ['Material Supplier','Spare Parts','Tyre Supplier','Service Workshop','Fuel Supplier','Other'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'Add' ?> Fleet Vendor</h5>
    <a href="fleet_vendors.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_vendor" value="1">
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-building me-2"></i>Vendor Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Vendor Code</label>
        <input type="text" name="vendor_code" class="form-control" value="<?= htmlspecialchars($v['vendor_code'] ?? '') ?>" placeholder="V001">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Vendor Name *</label>
        <input type="text" name="vendor_name" class="form-control" value="<?= htmlspecialchars($v['vendor_name'] ?? '') ?>" required>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Vendor Type</label>
        <select name="vendor_type" class="form-select">
            <option value="">— Select Type —</option>
            <?php foreach ($vendor_types as $vt): ?>
            <option <?= ($v['vendor_type'] ?? '') === $vt ? 'selected' : '' ?>><?= $vt ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option <?= ($v['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
            <option <?= ($v['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Contact Person</label>
        <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($v['contact_person'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($v['phone'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($v['email'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">GSTIN</label>
        <input type="text" name="gstin" class="form-control" value="<?= htmlspecialchars($v['gstin'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">PAN</label>
        <input type="text" name="pan" class="form-control" value="<?= htmlspecialchars($v['pan'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($v['city'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">State</label>
        <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($v['state'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($v['address'] ?? '') ?></textarea>
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-bank me-2"></i>Bank Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label">Bank Name</label>
        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($v['bank_name'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Account No</label>
        <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($v['account_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">IFSC Code</label>
        <input type="text" name="ifsc_code" class="form-control" value="<?= htmlspecialchars($v['ifsc_code'] ?? '') ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($v['notes'] ?? '') ?></textarea>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_vendors.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Vendor</button>
</div>
</div>
</form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
