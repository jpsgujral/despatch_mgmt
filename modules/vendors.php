<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
/* ── Page-level view permission check ── */
requirePerm('vendors', 'view');


/* ── Safe ALTER: works on MySQL 5.6+ (no IF NOT EXISTS support) ── */
function safeAddColumn($db, $table, $column, $definition) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '$dbname'
          AND TABLE_NAME   = '$table'
          AND COLUMN_NAME  = '$column'
        LIMIT 1
    ")->num_rows;
    if (!$exists) {
        $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function vendorPlainInput($value) {
    $value = trim((string)$value);
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return getDB()->real_escape_string($value);
}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ══════════════════════════════════════════════════════════════
   AUTO-ALTER: add Ship-To / Bill-To columns if not present
   Safe to run on every page load — checks INFORMATION_SCHEMA first
══════════════════════════════════════════════════════════════ */
/* ── Safe column additions (MySQL 5.6+ compatible) ── */
safeAddColumn($db, 'vendors', 'bill_address', "TEXT AFTER address");
safeAddColumn($db, 'vendors', 'bill_city',    "VARCHAR(60) AFTER bill_address");
safeAddColumn($db, 'vendors', 'bill_state',   "VARCHAR(60) AFTER bill_city");
safeAddColumn($db, 'vendors', 'bill_pincode', "VARCHAR(10) AFTER bill_state");
safeAddColumn($db, 'vendors', 'bill_country', "VARCHAR(50) DEFAULT 'India' AFTER bill_pincode");
safeAddColumn($db, 'vendors', 'bill_gstin',   "VARCHAR(20) AFTER bill_country");
safeAddColumn($db, 'vendors', 'ship_name',    "VARCHAR(120) AFTER bill_gstin");
safeAddColumn($db, 'vendors', 'ship_address', "TEXT AFTER ship_name");
safeAddColumn($db, 'vendors', 'ship_city',    "VARCHAR(60) AFTER ship_address");
safeAddColumn($db, 'vendors', 'ship_state',   "VARCHAR(60) AFTER ship_city");
safeAddColumn($db, 'vendors', 'ship_pincode', "VARCHAR(10) AFTER ship_state");
safeAddColumn($db, 'vendors', 'ship_country', "VARCHAR(50) DEFAULT 'India' AFTER ship_pincode");
safeAddColumn($db, 'vendors', 'ship_gstin',   "VARCHAR(20) AFTER ship_country");
safeAddColumn($db, 'vendors', 'ship_contact', "VARCHAR(100) AFTER ship_gstin");
safeAddColumn($db, 'vendors', 'ship_phone',   "VARCHAR(20) AFTER ship_contact");
// One-time migration: copy old address fields into bill_ fields where bill_address is NULL
$db->query("UPDATE vendors SET
    bill_address = address,
    bill_city    = city,
    bill_state   = state,
    bill_pincode = pincode,
    bill_country = IFNULL(country,'India'),
    bill_gstin   = gstin
    WHERE bill_address IS NULL AND (address IS NOT NULL AND address != '')");

/* ══════════════════════════════════════════════════════════════
   DELETE
══════════════════════════════════════════════════════════════ */
/* ── Drop UNIQUE constraint on vendor_code if it exists ── */
$dbname_vc = $db->query("SELECT DATABASE()")->fetch_row()[0];
$vc_idx = $db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA='$dbname_vc' AND TABLE_NAME='vendors'
      AND COLUMN_NAME='vendor_code' AND NON_UNIQUE=0
    LIMIT 1")->fetch_row();
if ($vc_idx) {
    @$db->query("ALTER TABLE vendors DROP INDEX `{$vc_idx[0]}`");
}

if (isset($_GET['delete'])) {
    requirePerm('vendors', 'delete');
    $db->query("DELETE FROM vendors WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Vendor deleted successfully.');
    redirect('vendors.php');
}

/* ══════════════════════════════════════════════════════════════
   SAVE / UPDATE
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requirePerm('vendors', $id > 0 ? 'update' : 'create');

    $fields = [
        // core
        'vendor_code','vendor_name','contact_person','email','phone','mobile',
        'gstin','pan','payment_terms','credit_limit','status',
        // legacy (keep for backward compat)
        'address','city','state','pincode','country',
        // bill-to
        'bill_address','bill_city','bill_state','bill_pincode','bill_country','bill_gstin',
        // ship-to
        'ship_name','ship_address','ship_city','ship_state','ship_pincode','ship_country',
        'ship_gstin','ship_contact','ship_phone',
    ];

    $data = [];
    foreach ($fields as $f) {
        $data[$f] = vendorPlainInput($_POST[$f] ?? '');
    }

    // Mirror bill-to into legacy columns so old queries still work
    $data['address'] = $data['bill_address'];
    $data['city']    = $data['bill_city'];
    $data['state']   = $data['bill_state'];
    $data['pincode'] = $data['bill_pincode'];
    $data['country'] = $data['bill_country'] ?: 'India';

    // If "same as bill-to" was ticked, copy bill fields into ship fields
    if (!empty($_POST['ship_same_as_bill'])) {
        $data['ship_address'] = $data['bill_address'];
        $data['ship_city']    = $data['bill_city'];
        $data['ship_state']   = $data['bill_state'];
        $data['ship_pincode'] = $data['bill_pincode'];
        $data['ship_country'] = $data['bill_country'];
        $data['ship_gstin']   = $data['bill_gstin'];
    }

    if (empty($data['vendor_code']) || empty($data['vendor_name'])) {
        showAlert('danger', 'Vendor Code and Vendor Name are required.');
    } else {
        try {
            if ($id > 0) {
                $set = implode(',', array_map(fn($k,$v) => "$k='$v'", array_keys($data), $data));
                $db->query("UPDATE vendors SET $set WHERE id=$id");
                showAlert('success', 'Vendor updated successfully.');
            } else {
                $cols = implode(',', array_keys($data));
                $vals = "'" . implode("','", array_values($data)) . "'";
                $db->query("INSERT INTO vendors ($cols) VALUES ($vals)");
                showAlert('success', 'Vendor added successfully.');
            }
            redirect('vendors.php');
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) {
                showAlert('danger', 'Save failed: a database unique constraint is blocking duplicate vendor codes. Please contact your administrator to remove the unique index on vendor_code.');
            } else {
                showAlert('danger', 'Database error: ' . htmlspecialchars($e->getMessage()));
            }
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   FETCH FOR EDIT / VIEW
══════════════════════════════════════════════════════════════ */
$vendor = [];
if (in_array($action, ['edit','view']) && $id > 0) {
    $vendor = $db->query("SELECT * FROM vendors WHERE id=$id")->fetch_assoc();
    if (!$vendor) redirect('vendors.php');
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-building me-2"></i>Vendor Master';</script>

<!-- ══════════════════════════════════════════════════════════
     LIST VIEW
══════════════════════════════════════════════════════════════ -->
<?php if ($action == 'list'): ?>

<div class="card shadow-sm">
<div class="card-header d-flex justify-content-between align-items-center py-2"
     style="background:linear-gradient(135deg,#1E3A8A,#2563EB);border-left:4px solid #172554;color:#fff">
    <span class="fw-bold"><i class="bi bi-building me-2"></i>Vendors</span>
    <a href="?action=add" class="btn btn-sm btn-light fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Vendor</a>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
    <thead class="table-light"><tr>
        <th>#</th><th>Vendor Name</th>
        <th>Ship-To City</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php
    $vendors = $db->query("SELECT * FROM vendors ORDER BY vendor_name");
    $i = 1;
    while ($v = $vendors->fetch_assoc()):
    ?>
    <tr>
        <td class="text-muted"><?= $i++ ?></td>
        <td>
            <a href="?action=view&id=<?= $v['id'] ?>" class="text-decoration-none fw-bold text-dark">
                <?= htmlspecialchars($v['vendor_name']) ?>
            </a>
        </td>
        <td>
            <?php if ($v['ship_city']): ?>
                <span class="fw-semibold"><?= htmlspecialchars($v['ship_city']) ?></span>
                <?php if ($v['ship_state']): ?>
                <br><small class="text-muted"><?= htmlspecialchars($v['ship_state']) ?></small>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td>
            <a href="?action=view&id=<?= $v['id'] ?>"  class="btn btn-action btn-outline-info  me-1"><i class="bi bi-eye"></i></a>
            <a href="?action=edit&id=<?= $v['id'] ?>"  class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
            <button onclick="confirmDelete(<?= $v['id'] ?>, 'vendors.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     VIEW DETAIL
══════════════════════════════════════════════════════════════ -->
<?php elseif ($action == 'view'): ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= htmlspecialchars($vendor['vendor_name']) ?></h5>
    <div>
        <a href="?action=edit&id=<?= $id ?>" class="btn btn-outline-primary btn-sm me-2">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <a href="vendors.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="row g-3">

    <!-- Basic & Contact -->
    <div class="col-12 col-md-6">
    <div class="card h-100 shadow-sm">
    <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-person-badge me-2"></i>Basic Information</div>
    <div class="card-body">
    <table class="table table-borderless table-sm mb-0 fw-semibold">
        <tr><td class="fw-semibold text-dark" width="40%">Vendor Code</td><td class="fw-bold"><?= htmlspecialchars($vendor['vendor_code']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">Vendor Name</td><td class="fw-bold"><?= htmlspecialchars($vendor['vendor_name']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">Contact Person</td><td class="fw-bold"><?= htmlspecialchars($vendor['contact_person']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">Phone</td><td class="fw-bold"><?= htmlspecialchars($vendor['phone']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">Mobile</td><td class="fw-bold"><?= htmlspecialchars($vendor['mobile']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">Email</td><td class="fw-bold"><?= htmlspecialchars($vendor['email']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">Status</td>
            <td><span class="badge bg-<?= $vendor['status']=='Active'?'success':'secondary' ?>"><?= $vendor['status'] ?></span></td></tr>
        <tr><td class="fw-semibold text-dark">Payment Terms</td><td class="fw-bold"><?= htmlspecialchars($vendor['payment_terms']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">Credit Limit</td><td>₹<?= number_format($vendor['credit_limit'],2) ?></td></tr>
    </table>
    </div></div></div>

    <!-- Tax & Bank -->
    <div class="col-12 col-md-6">
    <div class="card h-100 shadow-sm">
    <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-receipt me-2"></i>Tax Details</div>
    <div class="card-body">
    <table class="table table-borderless table-sm mb-0 fw-semibold">
        <tr><td class="fw-semibold text-dark" width="40%">GSTIN</td><td class="fw-bold font-monospace"><?= htmlspecialchars($vendor['gstin']) ?></td></tr>
        <tr><td class="fw-semibold text-dark">PAN</td><td class="fw-bold font-monospace"><?= htmlspecialchars($vendor['pan']) ?></td></tr>


    </table>
    </div></div></div>

    <!-- Bill-To Address -->
    <div class="col-12 col-md-6">
    <div class="card h-100 shadow-sm">
    <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff">
        <i class="bi bi-receipt-cutoff me-2"></i>Bill-To Address
    </div>
    <div class="card-body">
    <?php
    $bill_city    = ($vendor['bill_city']    ?? '') ?: ($vendor['city']    ?? '');
    $bill_state   = ($vendor['bill_state']   ?? '') ?: ($vendor['state']   ?? '');
    $bill_pincode = ($vendor['bill_pincode'] ?? '') ?: ($vendor['pincode'] ?? '');
    $bill_country = ($vendor['bill_country'] ?? '') ?: ($vendor['country'] ?? 'India');
    $bill_address = ($vendor['bill_address'] ?? '') ?: ($vendor['address'] ?? '');
    $bill_gstin   = ($vendor['bill_gstin']   ?? '') ?: ($vendor['gstin']   ?? '');
    ?>
    <address class="mb-0" style="line-height:1.8">
        <strong><?= htmlspecialchars($vendor['vendor_name']) ?></strong><br>
        <?= nl2br(htmlspecialchars($bill_address)) ?><br>
        <?= htmlspecialchars($bill_city) ?>
        <?= $bill_state   ? ', '.htmlspecialchars($bill_state)   : '' ?>
        <?= $bill_pincode ? ' – '.htmlspecialchars($bill_pincode) : '' ?><br>
        <?= htmlspecialchars($bill_country) ?>
        <?php if ($bill_gstin): ?>
        <br>GSTIN: <strong><?= htmlspecialchars($bill_gstin) ?></strong>
        <?php endif; ?>
    </address>
    </div></div></div>

    <!-- Ship-To Address -->
    <div class="col-12 col-md-6">
    <div class="card h-100 border-success">
    <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff">
        <i class="bi bi-truck me-2"></i>Ship-To Address
    </div>
    <div class="card-body">
    <?php
    $same = (empty($vendor['ship_address'] ?? '') && empty($vendor['ship_city'] ?? ''));
    if ($same):
    ?>
        <div class="text-secondary fw-semibold mb-2" style="font-size:.82rem"><i class="bi bi-info-circle me-1"></i>Same as Bill-To address</div>
        <address class="mb-0" style="line-height:1.8">
            <strong><?= htmlspecialchars($vendor['vendor_name']) ?></strong><br>
            <?= nl2br(htmlspecialchars($bill_address)) ?><br>
            <?= htmlspecialchars($bill_city) ?>
            <?= $bill_state   ? ', '.htmlspecialchars($bill_state)   : '' ?>
            <?= $bill_pincode ? ' – '.htmlspecialchars($bill_pincode) : '' ?><br>
            <?= htmlspecialchars($bill_country) ?>
        </address>
    <?php else: ?>
        <address class="mb-0" style="line-height:1.8">
            <strong><?= htmlspecialchars(($vendor['ship_name'] ?? '') ?: $vendor['vendor_name']) ?></strong><br>
            <?php if ($vendor['ship_contact'] ?? ''): ?>
            Attn: <?= htmlspecialchars($vendor['ship_contact'] ?? '') ?>
            <?php if ($vendor['ship_phone'] ?? ''): ?> | <?= htmlspecialchars($vendor['ship_phone']) ?><?php endif; ?><br>
            <?php endif; ?>
            <?= nl2br(htmlspecialchars($vendor['ship_address'] ?? '')) ?><br>
            <?= htmlspecialchars($vendor['ship_city'] ?? '') ?>
            <?= ($vendor['ship_state']   ?? '') ? ', '.htmlspecialchars($vendor['ship_state'])   : '' ?>
            <?= ($vendor['ship_pincode'] ?? '') ? ' – '.htmlspecialchars($vendor['ship_pincode']) : '' ?><br>
            <?= htmlspecialchars(($vendor['ship_country'] ?? '') ?: 'India') ?>
            <?php if ($vendor['ship_gstin'] ?? ''): ?>
            <br>GSTIN: <strong><?= htmlspecialchars($vendor['ship_gstin']) ?></strong>
            <?php endif; ?>
        </address>
    <?php endif; ?>
    </div></div></div>

</div><!-- /row -->

<!-- ══════════════════════════════════════════════════════════
     ADD / EDIT FORM
══════════════════════════════════════════════════════════════ -->
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $action=='edit'?'Edit':'Add New' ?> Vendor</h5>
    <a href="vendors.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="POST" id="vendorForm">
<div class="row g-3">

    <!-- ── 1. Basic Information ─────────────────────────────── -->
    <div class="col-12">
    <div class="card shadow-sm">
    <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-info-circle me-2"></i>Basic Information</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Vendor Code *</label>
            <input type="text" name="vendor_code" class="form-control" required
                   value="<?= htmlspecialchars($vendor['vendor_code'] ?? '') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Vendor Name *</label>
            <input type="text" name="vendor_name" class="form-control" required
                   value="<?= htmlspecialchars($vendor['vendor_name'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Contact Person</label>
            <input type="text" name="contact_person" class="form-control"
                   value="<?= htmlspecialchars($vendor['contact_person'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($vendor['email'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control"
                   value="<?= htmlspecialchars($vendor['phone'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Mobile</label>
            <input type="text" name="mobile" class="form-control"
                   value="<?= htmlspecialchars($vendor['mobile'] ?? '') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">GSTIN</label>
            <input type="text" name="gstin" class="form-control" maxlength="15"
                   value="<?= htmlspecialchars($vendor['gstin'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">PAN</label>
            <input type="text" name="pan" class="form-control" maxlength="10"
                   value="<?= htmlspecialchars($vendor['pan'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="Active"   <?= ($vendor['status']??'Active')=='Active'  ?'selected':'' ?>>Active</option>
                <option value="Inactive" <?= ($vendor['status']??'')=='Inactive'      ?'selected':'' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Payment Terms</label>
            <input type="text" name="payment_terms" class="form-control"
                   placeholder="e.g. Net 30 Days"
                   value="<?= htmlspecialchars($vendor['payment_terms'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Credit Limit (₹)</label>
            <input type="number" name="credit_limit" class="form-control"
                   value="<?= $vendor['credit_limit'] ?? 0 ?>">
        </div>
    </div></div>
    </div></div>

    <!-- ── 2. Bill-To Address ────────────────────────────────── -->
    <div class="col-12 col-md-6">
    <div class="card h-100 shadow-sm">
    <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff">
        <i class="bi bi-receipt-cutoff me-2"></i>Bill-To Address
        <small class="ms-2 opacity-75">(Invoicing / Legal address)</small>
    </div>
    <div class="card-body"><div class="row g-2">
        <?php
        $baddr = ($vendor['bill_address'] ?? '') ?: ($vendor['address'] ?? '');
        $bcity = ($vendor['bill_city']    ?? '') ?: ($vendor['city']    ?? '');
        $bst   = ($vendor['bill_state']   ?? '') ?: ($vendor['state']   ?? '');
        $bpin  = ($vendor['bill_pincode'] ?? '') ?: ($vendor['pincode'] ?? '');
        $bcou  = ($vendor['bill_country'] ?? '') ?: ($vendor['country'] ?? 'India');
        $bgst  = ($vendor['bill_gstin']   ?? '') ?: ($vendor['gstin']   ?? '');
        ?>
        <div class="col-12">
            <label class="form-label">Street / Area</label>
            <textarea name="bill_address" id="bill_address" class="form-control" rows="2"><?= htmlspecialchars($baddr) ?></textarea>
        </div>
        <div class="col-12 col-sm-6 col-md-5">
            <label class="form-label">City</label>
            <input type="text" name="bill_city" id="bill_city" class="form-control"
                   value="<?= htmlspecialchars($bcity) ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">State</label>
            <input type="text" name="bill_state" id="bill_state" class="form-control"
                   value="<?= htmlspecialchars($bst) ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Pincode</label>
            <input type="text" name="bill_pincode" id="bill_pincode" class="form-control"
                   value="<?= htmlspecialchars($bpin) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label">Country</label>
            <input type="text" name="bill_country" id="bill_country" class="form-control"
                   value="<?= htmlspecialchars($bcou) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label">Bill-To GSTIN</label>
            <input type="text" name="bill_gstin" id="bill_gstin" class="form-control" maxlength="15"
                   value="<?= htmlspecialchars($bgst) ?>">
        </div>
    </div></div>
    </div></div>

    <!-- ── 3. Ship-To Address ────────────────────────────────── -->
    <div class="col-12 col-md-6">
    <div class="card h-100 border-success">
    <div class="card-header fw-bold d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff">
        <span><i class="bi bi-truck me-2"></i>Ship-To Address
            <small class="ms-2 opacity-75">(Delivery / Warehouse address)</small>
        </span>
    </div>
    <div class="card-body">
        <!-- Same-as checkbox -->
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="shipSameBill" name="ship_same_as_bill"
                   value="1" onchange="toggleShipSame(this)"
                   <?php
                   $shipEmpty = empty($vendor['ship_address'] ?? '') && empty($vendor['ship_city'] ?? '');
                   echo $shipEmpty && $action!='add' ? 'checked' : '';
                   ?>>
            <label class="form-check-label fw-semibold" for="shipSameBill">
                Same as Bill-To Address
            </label>
        </div>
        <div id="shipFields" class="row g-2" <?= ($shipEmpty && $action!='add') ? 'style="opacity:0.4;pointer-events:none"' : '' ?>>
            <div class="col-12">
                <label class="form-label">Delivery Location / Warehouse Name</label>
                <input type="text" name="ship_name" class="form-control"
                       placeholder="e.g. Mumbai Warehouse"
                       value="<?= htmlspecialchars($vendor['ship_name'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Street / Area</label>
                <textarea name="ship_address" class="form-control" rows="2"><?= htmlspecialchars($vendor['ship_address'] ?? '') ?></textarea>
            </div>
            <div class="col-12 col-sm-6 col-md-5">
                <label class="form-label">City</label>
                <input type="text" name="ship_city" class="form-control"
                       value="<?= htmlspecialchars($vendor['ship_city'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">State</label>
                <input type="text" name="ship_state" class="form-control"
                       value="<?= htmlspecialchars($vendor['ship_state'] ?? '') ?>">
            </div>
            <div class="col-6 col-sm-4 col-md-3">
                <label class="form-label">Pincode</label>
                <input type="text" name="ship_pincode" class="form-control"
                       value="<?= htmlspecialchars($vendor['ship_pincode'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">Country</label>
                <input type="text" name="ship_country" class="form-control"
                       value="<?= htmlspecialchars($vendor['ship_country'] ?? 'India') ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">Ship-To GSTIN</label>
                <input type="text" name="ship_gstin" class="form-control" maxlength="15"
                       value="<?= htmlspecialchars($vendor['ship_gstin'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">Contact Person</label>
                <input type="text" name="ship_contact" class="form-control"
                       value="<?= htmlspecialchars($vendor['ship_contact'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">Contact Phone</label>
                <input type="text" name="ship_phone" class="form-control"
                       value="<?= htmlspecialchars($vendor['ship_phone'] ?? '') ?>">
            </div>
        </div>
    </div>
    </div></div>


    <!-- ── Submit ────────────────────────────────────────────── -->
    <div class="col-12 text-end">
        <a href="vendors.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check2 me-1"></i><?= $action=='edit'?'Update':'Save' ?> Vendor
        </button>
    </div>

</div><!-- /row -->
</form>

<script>
function toggleShipSame(cb) {
    const wrap = document.getElementById('shipFields');
    if (cb.checked) {
        wrap.style.opacity        = '0.4';
        wrap.style.pointerEvents  = 'none';
    } else {
        wrap.style.opacity        = '1';
        wrap.style.pointerEvents  = 'auto';
    }
}

// On page load respect initial checkbox state
document.addEventListener('DOMContentLoaded', () => {
    const cb = document.getElementById('shipSameBill');
    if (cb) toggleShipSame(cb);
});
</script>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>
