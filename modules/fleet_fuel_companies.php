<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_fuel_companies', 'view');

/* ── Auto-create table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_companies (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_name    VARCHAR(200) NOT NULL,
    contact_person  VARCHAR(120),
    phone           VARCHAR(20),
    email           VARCHAR(120),
    address         TEXT,
    gstin           VARCHAR(20),
    credit_terms    ENUM('Weekly','Fortnightly','Monthly','Cash') DEFAULT 'Weekly',
    credit_days     INT DEFAULT 7,
    credit_limit    DECIMAL(12,2) DEFAULT 0,
    bank_name       VARCHAR(100),
    account_no      VARCHAR(40),
    ifsc_code       VARCHAR(20),
    status          ENUM('Active','Inactive') DEFAULT 'Active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Auto-migrate: add default_fuel_price if missing ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_fuel_companies'
        AND COLUMN_NAME='default_fuel_price' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE fleet_fuel_companies ADD COLUMN default_fuel_price DECIMAL(8,2) DEFAULT 0.00 AFTER credit_limit");
})($db);

/* ── Create dependent tables if not exist (prevents subquery errors) ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_log (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    fuel_company_id   INT NOT NULL,
    vehicle_id        INT,
    driver_id         INT,
    fuel_date         DATE NOT NULL,
    litres            DECIMAL(10,2) DEFAULT 0,
    rate_per_litre    DECIMAL(8,2) DEFAULT 0,
    amount            DECIMAL(12,2) DEFAULT 0,
    odometer          INT DEFAULT 0,
    payment_mode      ENUM('Credit','Cash') DEFAULT 'Credit',
    bill_no           VARCHAR(60),
    notes             TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_payments (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    fuel_company_id   INT NOT NULL,
    payment_date      DATE NOT NULL,
    amount            DECIMAL(12,2) NOT NULL,
    payment_mode      VARCHAR(40) DEFAULT 'NEFT',
    reference_no      VARCHAR(80),
    remarks           TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];

    // Check references
    $refs = [];
    $r = $db->query("SELECT COUNT(*) c FROM fleet_fuel_log WHERE fuel_company_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Fuel Entries';
    $r = $db->query("SELECT COUNT(*) c FROM fleet_fuel_payments WHERE fuel_company_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Fuel Payments';

    if (!empty($refs)) {
        showAlert('danger', 'Cannot delete — this fuel company has linked records in: ' . implode(', ', $refs) . '. Remove those records first or mark the company as Inactive instead.');
    } else {
        $db->query("DELETE FROM fleet_fuel_companies WHERE id=$did");
        showAlert('success', 'Fuel company deleted permanently.');
    }
    redirect('fleet_fuel_companies.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    requirePerm('fleet_fuel_companies', $id > 0 ? 'update' : 'create');
    $name    = sanitize($_POST['company_name']);
    $contact = sanitize($_POST['contact_person'] ?? '');
    $phone   = sanitize($_POST['phone'] ?? '');
    $email   = sanitize($_POST['email'] ?? '');
    $addr    = sanitize($_POST['address'] ?? '');
    $gstin   = sanitize($_POST['gstin'] ?? '');
    $terms   = sanitize($_POST['credit_terms'] ?? 'Weekly');
    $days    = (int)($_POST['credit_days'] ?? 7);
    $limit   = (float)($_POST['credit_limit'] ?? 0);
    $def_price = (float)($_POST['default_fuel_price'] ?? 0);
    $bank    = sanitize($_POST['bank_name'] ?? '');
    $accno   = sanitize($_POST['account_no'] ?? '');
    $ifsc    = sanitize($_POST['ifsc_code'] ?? '');
    $status  = sanitize($_POST['status'] ?? 'Active');
    $notes   = sanitize($_POST['notes'] ?? '');

    if (!$name) { showAlert('danger','Company Name is required.'); redirect("fleet_fuel_companies.php?action=$action&id=$id"); }

    if ($id > 0) {
        $db->query("UPDATE fleet_fuel_companies SET
            company_name='$name', contact_person='$contact', phone='$phone', email='$email',
            address='$addr', gstin='$gstin', credit_terms='$terms', credit_days=$days,
            credit_limit=$limit, default_fuel_price=$def_price, bank_name='$bank', account_no='$accno', ifsc_code='$ifsc',
            status='$status', notes='$notes'
            WHERE id=$id");
        showAlert('success','Fuel company updated.');
    } else {
        $db->query("INSERT INTO fleet_fuel_companies
            (company_name,contact_person,phone,email,address,gstin,credit_terms,credit_days,
             credit_limit,default_fuel_price,bank_name,account_no,ifsc_code,status,notes)
            VALUES ('$name','$contact','$phone','$email','$addr','$gstin','$terms',$days,
            $limit,$def_price,'$bank','$accno','$ifsc','$status','$notes')");
        showAlert('success','Fuel company added.');
    }
    redirect('fleet_fuel_companies.php');
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-fuel-pump me-2"></i>Fuel Company Master';</script>

<?php
$terms_colors = ['Weekly'=>'primary','Fortnightly'=>'info','Monthly'=>'warning','Cash'=>'secondary'];

/* ── LIST ── */
if ($action === 'list'):
$companies = $db->query("SELECT fc.*,
    (SELECT COALESCE(SUM(fl.amount),0) FROM fleet_fuel_log fl WHERE fl.fuel_company_id=fc.id AND fl.payment_mode='Credit') AS total_credit,
    (SELECT COALESCE(SUM(fp.amount),0) FROM fleet_fuel_payments fp WHERE fp.fuel_company_id=fc.id) AS total_paid
    FROM fleet_fuel_companies fc ORDER BY fc.status ASC, fc.company_name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fuel Company Master</h5>
    <?php if (canDo('fleet_fuel_companies','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Fuel Company</a>
    <?php endif; ?>
</div>
<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Company</th><th>Contact</th><th>Phone</th>
    <th>Credit Terms</th><th>Credit Limit</th>
    <th>Outstanding</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($companies as $c):
    $sc  = $c['status'] === 'Active' ? 'success' : 'secondary';
    $tc  = $terms_colors[$c['credit_terms']] ?? 'secondary';
    $outstanding = (float)$c['total_credit'] - (float)$c['total_paid'];
?>
<tr>
    <td><strong><?= htmlspecialchars($c['company_name']) ?></strong><br>
        <small class="text-muted"><?= htmlspecialchars($c['gstin'] ?: '') ?></small></td>
    <td><?= htmlspecialchars($c['contact_person'] ?: '—') ?></td>
    <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
    <td><span class="badge bg-<?= $tc ?>"><?= $c['credit_terms'] ?></span>
        <?php if ($c['credit_terms'] !== 'Cash'): ?>
        <small class="text-muted">(<?= $c['credit_days'] ?> days)</small>
        <?php endif; ?>
    </td>
    <td>₹<?= number_format($c['credit_limit'], 0) ?></td>
    <td class="<?= $outstanding > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
        ₹<?= number_format($outstanding, 2) ?>
    </td>
    <td><span class="badge bg-<?= $sc ?>"><?= $c['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_fuel_companies','update')): ?>
        <a href="?action=edit&id=<?= $c['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $c['id'] ?>" onclick="return confirm('Permanently delete <?= htmlspecialchars(addslashes($c['company_name'])) ?>?\n\nThis will fail if the company has linked fuel entries or payments.\nTo disable without deleting, edit and set Status to Inactive.')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php
/* ── ADD / EDIT ── */
else:
$c = [];
if ($id > 0) $c = $db->query("SELECT * FROM fleet_fuel_companies WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'Add' ?> Fuel Company</h5>
    <a href="fleet_fuel_companies.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_company" value="1">
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-fuel-pump me-2"></i>Company Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-5">
        <label class="form-label fw-bold">Company Name *</label>
        <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($c['company_name'] ?? '') ?>" required>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Contact Person</label>
        <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($c['contact_person'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($c['phone'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option <?= ($c['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
            <option <?= ($c['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($c['email'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">GSTIN</label>
        <input type="text" name="gstin" class="form-control" value="<?= htmlspecialchars($c['gstin'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($c['address'] ?? '') ?></textarea>
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-calendar-check me-2"></i>Credit Terms</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Credit Terms</label>
        <select name="credit_terms" class="form-select" id="creditTerms" onchange="toggleDays()">
            <?php foreach (['Weekly','Fortnightly','Monthly','Cash'] as $t): ?>
            <option <?= ($c['credit_terms'] ?? 'Weekly') === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2" id="creditDaysWrap">
        <label class="form-label">Credit Days</label>
        <input type="number" name="credit_days" class="form-control" value="<?= $c['credit_days'] ?? 7 ?>" min="1">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Credit Limit (₹)</label>
        <input type="number" name="credit_limit" class="form-control" step="0.01" value="<?= $c['credit_limit'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Default Fuel Price (₹ / Litre)</label>
        <input type="number" name="default_fuel_price" class="form-control" step="0.01" value="<?= isset($c['default_fuel_price']) ? htmlspecialchars(number_format((float)$c['default_fuel_price'], 2, '.', '')) : '' ?>" placeholder="0.00">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-bank me-2"></i>Bank Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label">Bank Name</label>
        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($c['bank_name'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Account No</label>
        <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($c['account_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">IFSC Code</label>
        <input type="text" name="ifsc_code" class="form-control" value="<?= htmlspecialchars($c['ifsc_code'] ?? '') ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($c['notes'] ?? '') ?></textarea>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_fuel_companies.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save</button>
</div>
</div>
</form>
<script>
function toggleDays() {
    var t = document.getElementById('creditTerms').value;
    document.getElementById('creditDaysWrap').style.display = t === 'Cash' ? 'none' : '';
}
toggleDays();
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
