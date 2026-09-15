<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_customers_master', 'view');

/* ── Create table — same structure as despatch vendors ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_customers_master (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_code     VARCHAR(20) NOT NULL,
    vendor_name     VARCHAR(100) NOT NULL,
    contact_person  VARCHAR(100),
    email           VARCHAR(100),
    phone           VARCHAR(20),
    mobile          VARCHAR(20),
    gstin           VARCHAR(20),
    pan             VARCHAR(15),
    payment_terms   VARCHAR(100),
    credit_limit    DECIMAL(12,2) DEFAULT 0,
    status          ENUM('Active','Inactive') DEFAULT 'Active',
    address         TEXT,
    city            VARCHAR(50),
    state           VARCHAR(50),
    pincode         VARCHAR(10),
    country         VARCHAR(50) DEFAULT 'India',
    bill_address    TEXT,
    bill_city       VARCHAR(60),
    bill_state      VARCHAR(60),
    bill_pincode    VARCHAR(10),
    bill_country    VARCHAR(50) DEFAULT 'India',
    bill_gstin      VARCHAR(20),
    ship_name       VARCHAR(120),
    ship_address    TEXT,
    ship_city       VARCHAR(60),
    ship_state      VARCHAR(60),
    ship_pincode    VARCHAR(10),
    ship_country    VARCHAR(50) DEFAULT 'India',
    ship_gstin      VARCHAR(20),
    ship_contact    VARCHAR(100),
    ship_phone      VARCHAR(20),
    bank_name       VARCHAR(100),
    account_no      VARCHAR(30),
    ifsc_code       VARCHAR(20),
    company_id      INT DEFAULT 1,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Drop unique index on vendor_code if it exists (allow duplicate codes) ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $idx = $db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_customers_master'
        AND COLUMN_NAME='vendor_code' AND NON_UNIQUE=0 LIMIT 1")->fetch_assoc();
    if ($idx) {
        $idxName = $db->real_escape_string($idx['INDEX_NAME']);
        $db->query("ALTER TABLE fleet_customers_master DROP INDEX `$idxName`");
    }
})($db);

/* ── Add company_id if upgrading ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_customers_master'
        AND COLUMN_NAME='company_id' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE fleet_customers_master ADD COLUMN company_id INT DEFAULT 1");
})($db);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

function fleet_customer_plain($value) {
    $value = trim((string)($value ?? ''));
    for ($i = 0; $i < 5; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return $value;
}

function fleet_customer_sql($value) {
    return getDB()->real_escape_string(fleet_customer_plain($value));
}

function fleet_customer_clean_row(array $row): array {
    foreach ([
        'vendor_code','vendor_name','contact_person','email','phone','mobile','gstin','pan',
        'payment_terms','status','address','city','state','pincode','country',
        'bill_address','bill_city','bill_state','bill_pincode','bill_country','bill_gstin',
        'ship_name','ship_address','ship_city','ship_state','ship_pincode','ship_country',
        'ship_gstin','ship_contact','ship_phone','bank_name','account_no','ifsc_code',
        'notes','company_name'
    ] as $field) {
        if (array_key_exists($field, $row)) $row[$field] = fleet_customer_plain($row[$field]);
    }
    return $row;
}

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $refs = [];
    $r = $db->query("SELECT COUNT(*) c FROM fleet_purchase_orders WHERE vendor_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Customer POs';
    $r = $db->query("SELECT COUNT(*) c FROM fleet_trips WHERE vendor_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Trip Orders';
    if (!empty($refs)) {
        showAlert('danger', 'Cannot delete — linked records exist in: ' . implode(', ', $refs) . '. Set Status to Inactive instead.');
    } else {
        $db->query("DELETE FROM fleet_customers_master WHERE id=$did");
        showAlert('success', 'Customer deleted permanently.');
    }
    redirect('fleet_customers_master.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer'])) {
    requirePerm('fleet_customers_master', $id > 0 ? 'update' : 'create');

    $code    = fleet_customer_sql($_POST['vendor_code']);
    $name    = fleet_customer_sql($_POST['vendor_name']);
    $contact = fleet_customer_sql($_POST['contact_person'] ?? '');
    $email   = fleet_customer_sql($_POST['email']          ?? '');
    $phone   = fleet_customer_sql($_POST['phone']          ?? '');
    $mobile  = fleet_customer_sql($_POST['mobile']         ?? '');
    $gstin   = fleet_customer_sql($_POST['gstin']          ?? '');
    $pan     = fleet_customer_sql($_POST['pan']            ?? '');
    $pterms  = fleet_customer_sql($_POST['payment_terms']  ?? '');
    $climit  = (float)($_POST['credit_limit']    ?? 0);
    $status  = fleet_customer_sql($_POST['status']         ?? 'Active');
    $addr    = fleet_customer_sql($_POST['address']        ?? '');
    $city    = fleet_customer_sql($_POST['city']           ?? '');
    $state   = fleet_customer_sql($_POST['state']          ?? '');
    $pin     = fleet_customer_sql($_POST['pincode']        ?? '');
    $country = fleet_customer_sql($_POST['country']        ?? 'India');
    $b_addr  = fleet_customer_sql($_POST['bill_address']   ?? '');
    $b_city  = fleet_customer_sql($_POST['bill_city']      ?? '');
    $b_state = fleet_customer_sql($_POST['bill_state']     ?? '');
    $b_pin   = fleet_customer_sql($_POST['bill_pincode']   ?? '');
    $b_ctry  = fleet_customer_sql($_POST['bill_country']   ?? 'India');
    $b_gst   = fleet_customer_sql($_POST['bill_gstin']     ?? '');
    $s_name  = fleet_customer_sql($_POST['ship_name']      ?? '');
    $s_addr  = fleet_customer_sql($_POST['ship_address']   ?? '');
    $s_city  = fleet_customer_sql($_POST['ship_city']      ?? '');
    $s_state = fleet_customer_sql($_POST['ship_state']     ?? '');
    $s_pin   = fleet_customer_sql($_POST['ship_pincode']   ?? '');
    $s_ctry  = fleet_customer_sql($_POST['ship_country']   ?? 'India');
    $s_gst   = fleet_customer_sql($_POST['ship_gstin']     ?? '');
    $s_cont  = fleet_customer_sql($_POST['ship_contact']   ?? '');
    $s_ph    = fleet_customer_sql($_POST['ship_phone']     ?? '');
    $bank    = fleet_customer_sql($_POST['bank_name']      ?? '');
    $accno   = fleet_customer_sql($_POST['account_no']     ?? '');
    $ifsc    = fleet_customer_sql($_POST['ifsc_code']      ?? '');
    $notes   = fleet_customer_sql($_POST['notes']          ?? '');
    $co_id   = (int)($_POST['company_id']        ?? activeCompanyId());

    if (!$code || !$name) {
        showAlert('danger', 'Customer Code and Name are required.');
        redirect("fleet_customers_master.php?action=$action&id=$id");
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_customers_master SET
            vendor_code='$code', vendor_name='$name', contact_person='$contact',
            email='$email', phone='$phone', mobile='$mobile', gstin='$gstin', pan='$pan',
            payment_terms='$pterms', credit_limit=$climit, status='$status',
            address='$addr', city='$city', state='$state', pincode='$pin', country='$country',
            bill_address='$b_addr', bill_city='$b_city', bill_state='$b_state',
            bill_pincode='$b_pin', bill_country='$b_ctry', bill_gstin='$b_gst',
            ship_name='$s_name', ship_address='$s_addr', ship_city='$s_city',
            ship_state='$s_state', ship_pincode='$s_pin', ship_country='$s_ctry',
            ship_gstin='$s_gst', ship_contact='$s_cont', ship_phone='$s_ph',
            bank_name='$bank', account_no='$accno', ifsc_code='$ifsc', notes='$notes'
            WHERE id=$id");
        showAlert('success', 'Customer updated.');
    } else {
        $db->query("INSERT INTO fleet_customers_master
            (vendor_code,vendor_name,contact_person,email,phone,mobile,gstin,pan,
             payment_terms,credit_limit,status,address,city,state,pincode,country,
             bill_address,bill_city,bill_state,bill_pincode,bill_country,bill_gstin,
             ship_name,ship_address,ship_city,ship_state,ship_pincode,ship_country,
             ship_gstin,ship_contact,ship_phone,bank_name,account_no,ifsc_code,company_id,notes)
            VALUES ('$code','$name','$contact','$email','$phone','$mobile','$gstin','$pan',
            '$pterms',$climit,'$status','$addr','$city','$state','$pin','$country',
            '$b_addr','$b_city','$b_state','$b_pin','$b_ctry','$b_gst',
            '$s_name','$s_addr','$s_city','$s_state','$s_pin','$s_ctry',
            '$s_gst','$s_cont','$s_ph','$bank','$accno','$ifsc',$co_id,'$notes')");
        showAlert('success', 'Customer added.');
    }
    redirect('fleet_customers_master.php');
}

$all_companies = getAllCompanies();
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-person-lines-fill me-2"></i>Fleet Customer Master';</script>

<?php
/* ══════════════════════════════════
   LIST
══════════════════════════════════ */
if ($action === 'list'):
$customers = $db->query("SELECT fcm.*, co.company_name FROM fleet_customers_master fcm LEFT JOIN companies co ON fcm.company_id=co.id ORDER BY co.company_name ASC, fcm.status ASC, fcm.vendor_name ASC")->fetch_all(MYSQLI_ASSOC);
$customers = array_map('fleet_customer_clean_row', $customers);

// Group by company
$grouped = [];
foreach ($customers as $c) {
    $key = $c['company_name'] ?? 'Unassigned';
    $grouped[$key][] = $c;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fleet Customer Master</h5>
    <?php if (canDo('fleet_customers_master','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Customer</a>
    <?php endif; ?>
</div>

<?php foreach ($grouped as $companyName => $list): ?>
<div class="card mb-3">
<div class="card-header d-flex justify-content-between align-items-center py-2">
    <span><i class="bi bi-buildings me-2"></i><strong><?= htmlspecialchars($companyName) ?></strong></span>
    <span class="badge bg-secondary"><?= count($list) ?> customer<?= count($list) > 1 ? 's' : '' ?></span>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Code</th><th>Customer Name</th><th>Contact</th><th>Phone</th>
    <th>City</th><th>GSTIN</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($list as $c):
    $sc = $c['status'] === 'Active' ? 'success' : 'secondary';
?>
<tr>
    <td><?= htmlspecialchars($c['vendor_code']) ?></td>
    <td><strong><?= htmlspecialchars($c['vendor_name']) ?></strong><br>
        <small class="text-muted"><?= htmlspecialchars($c['contact_person'] ?: '') ?></small></td>
    <td><?= htmlspecialchars($c['contact_person'] ?: '—') ?></td>
    <td><?= htmlspecialchars($c['phone'] ?: ($c['mobile'] ?: '—')) ?></td>
    <td><?= htmlspecialchars($c['city'] ?: '—') ?></td>
    <td><?= htmlspecialchars($c['gstin'] ?: '—') ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $c['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_customers_master','update')): ?>
        <a href="?action=edit&id=<?= $c['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $c['id'] ?>" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($c['vendor_name'])) ?>?\n\nBlocked if linked to POs or trips.')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>
<?php endforeach; ?>

<?php
/* ══════════════════════════════════
   ADD / EDIT
══════════════════════════════════ */
else:
$c = [];
if ($id > 0) $c = fleet_customer_clean_row($db->query("SELECT * FROM fleet_customers_master WHERE id=$id LIMIT 1")->fetch_assoc() ?? []);

/* Auto-generate code for new customer */
if ($id === 0) {
    $last = $db->query("SELECT vendor_code FROM fleet_customers_master ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $next_num = $last ? (int)filter_var($last['vendor_code'], FILTER_SANITIZE_NUMBER_INT) + 1 : 1;
    $auto_code = 'FC' . str_pad($next_num, 4, '0', STR_PAD_LEFT);
} else {
    $auto_code = $c['vendor_code'] ?? '';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'Add' ?> Fleet Customer</h5>
    <a href="fleet_customers_master.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_customer" value="1">
<div class="row g-3">

<!-- Basic Info -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Customer Information</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Customer Code *</label>
        <input type="text" name="vendor_code" class="form-control text-uppercase" value="<?= htmlspecialchars($auto_code) ?>" required>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Customer Name *</label>
        <input type="text" name="vendor_name" class="form-control" value="<?= htmlspecialchars($c['vendor_name'] ?? '') ?>" required>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Contact Person</label>
        <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($c['contact_person'] ?? '') ?>">
    </div>
    <?php if (count($all_companies) > 1): ?>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Company *</label>
        <select name="company_id" class="form-select" required>
            <?php foreach ($all_companies as $co): ?>
            <option value="<?= $co['id'] ?>" <?= ($c['company_id'] ?? activeCompanyId()) == $co['id'] ? 'selected' : '' ?>><?= htmlspecialchars($co['company_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" name="company_id" value="<?= activeCompanyId() ?>">
    <?php endif; ?>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option <?= ($c['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
            <option <?= ($c['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($c['phone'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Mobile</label>
        <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($c['mobile'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($c['email'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">GSTIN</label>
        <input type="text" name="gstin" class="form-control" value="<?= htmlspecialchars($c['gstin'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">PAN</label>
        <input type="text" name="pan" class="form-control" value="<?= htmlspecialchars($c['pan'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Payment Terms</label>
        <input type="text" name="payment_terms" class="form-control" value="<?= htmlspecialchars($c['payment_terms'] ?? '') ?>" placeholder="e.g. 30 days">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Credit Limit (₹)</label>
        <input type="number" name="credit_limit" class="form-control" step="0.01" value="<?= $c['credit_limit'] ?? 0 ?>">
    </div>
</div></div></div></div>

<!-- Address -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-geo-alt me-2"></i>Address</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-5">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($c['address'] ?? '') ?></textarea>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($c['city'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">State</label>
        <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($c['state'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Pincode</label>
        <input type="text" name="pincode" class="form-control" value="<?= htmlspecialchars($c['pincode'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Country</label>
        <input type="text" name="country" class="form-control" value="<?= htmlspecialchars($c['country'] ?? 'India') ?>">
    </div>
</div></div></div></div>

<!-- Bill-To Address -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-receipt me-2"></i>Bill-To Address <small class="text-muted fw-normal">(Invoicing / Legal)</small></div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-5">
        <label class="form-label">Bill Address</label>
        <textarea name="bill_address" class="form-control" rows="2"><?= htmlspecialchars($c['bill_address'] ?? '') ?></textarea>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="bill_city" class="form-control" value="<?= htmlspecialchars($c['bill_city'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">State</label>
        <input type="text" name="bill_state" class="form-control" value="<?= htmlspecialchars($c['bill_state'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Pincode</label>
        <input type="text" name="bill_pincode" class="form-control" value="<?= htmlspecialchars($c['bill_pincode'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">GSTIN</label>
        <input type="text" name="bill_gstin" class="form-control" value="<?= htmlspecialchars($c['bill_gstin'] ?? '') ?>">
    </div>
</div></div></div></div>

<!-- Ship-To Address -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-truck me-2"></i>Ship-To Address <small class="text-muted fw-normal">(Delivery / Site)</small></div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Ship-To Name</label>
        <input type="text" name="ship_name" class="form-control" value="<?= htmlspecialchars($c['ship_name'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Contact</label>
        <input type="text" name="ship_contact" class="form-control" value="<?= htmlspecialchars($c['ship_contact'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Phone</label>
        <input type="text" name="ship_phone" class="form-control" value="<?= htmlspecialchars($c['ship_phone'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">GSTIN</label>
        <input type="text" name="ship_gstin" class="form-control" value="<?= htmlspecialchars($c['ship_gstin'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Ship Address</label>
        <textarea name="ship_address" class="form-control" rows="2"><?= htmlspecialchars($c['ship_address'] ?? '') ?></textarea>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="ship_city" class="form-control" value="<?= htmlspecialchars($c['ship_city'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">State</label>
        <input type="text" name="ship_state" class="form-control" value="<?= htmlspecialchars($c['ship_state'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Pincode</label>
        <input type="text" name="ship_pincode" class="form-control" value="<?= htmlspecialchars($c['ship_pincode'] ?? '') ?>">
    </div>
</div></div></div></div>

<!-- Bank Details -->
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
    <a href="fleet_customers_master.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Customer</button>
</div>
</div>
</form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
