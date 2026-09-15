<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (file_exists('../includes/r2_helper.php')) require_once '../includes/r2_helper.php';
$db = getDB();
requirePerm('fleet_expenses', 'view');

/* ── Constants ── */
define('MISC_FUEL_TYPE',  'Supervisor Car Fuel');
define('MISC_VEH_TYPES', ['Travel Allowance','Room Rent','Mobile Recharge',
                           'Pollution Certificate','Police Challan','Other']);

/* ── Auto-migrate: add misc_source column to fleet_expenses ── */
(function($db) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    foreach ([
        'misc_source'          => "VARCHAR(20) DEFAULT NULL COMMENT 'misc_fuel or misc_veh'",
        'fuel_company_id'      => "INT DEFAULT NULL COMMENT 'for misc fuel entries'",
        'fuel_log_id'          => "INT DEFAULT NULL COMMENT 'linked fleet_fuel_log id'",
        'supervisor_vehicle_no'=> "VARCHAR(30) DEFAULT NULL COMMENT 'supervisor car reg no (free text)'",
    ] as $col => $def) {
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_expenses'
            AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
        if (!$exists) $db->query("ALTER TABLE fleet_expenses ADD COLUMN `$col` $def");
    }
    // fleet_fuel_log: add misc_source flag so it appears in fuel company ledger
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_fuel_log'
        AND COLUMN_NAME='misc_source' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE fleet_fuel_log ADD COLUMN misc_source TINYINT(1) DEFAULT 0 COMMENT '1=supervisor misc fuel'");
})($db);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ════════════════════════════════════════════════
   AJAX: add inline vendor
═══════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_vendor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    requirePerm('fleet_expenses', 'create');
    $vn = trim($_POST['vendor_name'] ?? '');
    if (!$vn) { echo json_encode(['success'=>false,'error'=>'Name required']); exit; }
    $vns = $db->real_escape_string($vn);
    $ex  = $db->query("SELECT id,vendor_name FROM fleet_expense_vendors WHERE LOWER(TRIM(vendor_name))=LOWER('$vns') LIMIT 1")->fetch_assoc();
    if ($ex) { echo json_encode(['success'=>true,'id'=>(int)$ex['id'],'name'=>$ex['vendor_name']]); exit; }
    $db->query("INSERT INTO fleet_expense_vendors (vendor_name) VALUES ('$vns')");
    echo json_encode(['success'=>true,'id'=>(int)$db->insert_id,'name'=>$vn]);
    exit;
}

/* ════════════════════════════════════════════════
   DELETE
═══════════════════════════════════════════════ */
if (isset($_GET['delete'])) {
    requirePerm('fleet_expenses','delete');
    $did = (int)$_GET['delete'];
    // Check if it's a fuel log entry (misc_fuel)
    $row = $db->query("SELECT misc_source, fuel_log_id FROM fleet_expenses WHERE id=$did LIMIT 1")->fetch_assoc();
    if ($row && $row['misc_source'] === 'misc_fuel' && !empty($row['fuel_log_id'])) {
        $db->query("DELETE FROM fleet_fuel_log WHERE id=".(int)$row['fuel_log_id']." AND misc_source=1");
    }
    $db->query("DELETE FROM fleet_expenses WHERE id=$did");
    showAlert('success','Misc expense deleted.');
    redirect('fleet_misc_expenses.php');
}

/* ════════════════════════════════════════════════
   SAVE
═══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_misc'])) {
    error_log('[misc_debug] REACHED SAVE. POST keys: '.implode(',',array_keys($_POST)).' cat='.(($_POST['expense_cat'])??'missing').' veh_date='.($_POST['veh_expense_date']??'missing').' veh_amt='.($_POST['veh_amount']??'missing'));
    requirePerm('fleet_expenses', $id > 0 ? 'update' : 'create');

    $expense_cat = $_POST['expense_cat'] ?? 'veh';
    // Read date and amount from the correct section's fields
    if ($expense_cat === 'fuel') {
        $date   = sanitize($_POST['fuel_expense_date'] ?? '');
        $amount = (float)($_POST['fuel_amount'] ?? 0);
    } else {
        $date   = sanitize($_POST['veh_expense_date'] ?? '');
        $amount = (float)($_POST['veh_amount'] ?? 0);
    }
    $desc        = sanitize($_POST['description'] ?? '');
    $bill_no     = sanitize($_POST['bill_no'] ?? '');
    $mode        = sanitize($_POST['payment_mode'] ?? 'Cash');
    $pay_status  = in_array($_POST['payment_status'] ?? '', ['Paid','Not Paid']) ? sanitize($_POST['payment_status']) : 'Not Paid';
    $uid         = (int)($_SESSION['user_id'] ?? 0);

    if (!$date || $amount <= 0) {
        showAlert('danger','Date and Amount are required.');
        redirect("fleet_misc_expenses.php?action=".($id>0?"edit&id=$id":'add'));
    }

    /* ── FUEL category ── */
    if ($expense_cat === 'fuel') {
        $fc_id      = (int)($_POST['fuel_company_id'] ?? 0);
        $sup_veh_no = $db->real_escape_string(trim($_POST['supervisor_vehicle_no'] ?? ''));
        $litres     = (float)($_POST['litres'] ?? 0);
        $fuel_rate  = (float)($_POST['fuel_rate'] ?? 0);
        $rate       = $fuel_rate > 0 ? $fuel_rate : ($litres > 0 ? round($amount / $litres, 2) : 0);
        // Credit if Not Paid, Cash if Paid
        $fl_mode    = ($pay_status === 'Paid') ? 'Cash' : 'Credit';
        $mode       = $fl_mode; // payment_mode mirrors credit/cash

        if (!$fc_id) { showAlert('danger','Fuel Company is required.'); redirect("fleet_misc_expenses.php?action=add"); }

        if ($id > 0) {
            $db->query("UPDATE fleet_expenses SET
                expense_date='$date', amount=$amount, description='$desc',
                bill_no='$bill_no', payment_mode='$fl_mode', payment_status='$pay_status',
                vehicle_id=0, supervisor_vehicle_no='$sup_veh_no', fuel_company_id=$fc_id
                WHERE id=$id AND misc_source='misc_fuel'");
            $fl_id = (int)($db->query("SELECT fuel_log_id FROM fleet_expenses WHERE id=$id LIMIT 1")->fetch_assoc()['fuel_log_id'] ?? 0);
            if ($fl_id) {
                $db->query("UPDATE fleet_fuel_log SET
                    fuel_date='$date', fuel_company_id=$fc_id,
                    litres=$litres, rate_per_litre=$rate, amount=$amount,
                    payment_mode='$fl_mode', bill_no='$bill_no', notes='$desc'
                    WHERE id=$fl_id AND misc_source=1");
            }
            showAlert('success','Misc fuel expense updated.');
        } else {
            // Insert into fleet_fuel_log — vehicle_id=0 (supervisor car not in fleet)
            $db->query("INSERT INTO fleet_fuel_log
                (fuel_company_id, vehicle_id, fuel_date, litres, rate_per_litre, amount,
                 payment_mode, bill_no, notes, misc_source)
                VALUES ($fc_id, 0, '$date', $litres, $rate, $amount,
                '$fl_mode', '$bill_no', '$desc', 1)");
            $fl_id = $db->insert_id;

            $db->query("INSERT INTO fleet_expenses
                (vehicle_id, expense_date, expense_type, description, amount, payment_status,
                 payment_mode, bill_no, created_by, misc_source, fuel_company_id, fuel_log_id,
                 supervisor_vehicle_no)
                VALUES (0, '$date', '".MISC_FUEL_TYPE."', '$desc', $amount,
                '$pay_status', '$fl_mode', '$bill_no', $uid, 'misc_fuel', $fc_id, $fl_id,
                '$sup_veh_no')");
            $msg = $fl_mode === 'Credit'
                ? 'Supervisor fuel recorded and added to fuel company outstanding.'
                : 'Supervisor fuel recorded as cash payment.';
            showAlert('success', $msg);
        }

    /* ── VEHICLE P&L category ── */
    } else {
        $veh_id    = (int)($_POST['vehicle_id'] ?? 0);
        $exp_type  = sanitize($_POST['expense_type'] ?? 'Other');
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        $vendor    = '';
        if ($vendor_id > 0) {
            $vr = $db->query("SELECT vendor_name FROM fleet_expense_vendors WHERE id=$vendor_id LIMIT 1")->fetch_assoc();
            $vendor = sanitize($vr['vendor_name'] ?? '');
        }

        if (!$veh_id) { showAlert('danger','Vehicle is required.'); redirect("fleet_misc_expenses.php?action=add"); }
        if (!in_array($exp_type, MISC_VEH_TYPES)) $exp_type = 'Other';

        if ($id > 0) {
            $db->query("UPDATE fleet_expenses SET
                vehicle_id=$veh_id, expense_date='$date', expense_type='$exp_type',
                vendor_id=".($vendor_id?:'NULL').", vendor_name='$vendor',
                description='$desc', amount=$amount, payment_status='$pay_status',
                payment_mode='$mode', bill_no='$bill_no'
                WHERE id=$id AND misc_source='misc_veh'");
            showAlert('success','Misc expense updated.');
        } else {
            $db->query("INSERT INTO fleet_expenses
                (vehicle_id, trip_id, expense_date, expense_type, vendor_id, vendor_name,
                 description, amount, payment_status, payment_mode, bill_no, created_by, misc_source)
                VALUES ($veh_id, NULL, '$date', '$exp_type', ".($vendor_id?:'NULL').", '$vendor',
                '$desc', $amount, '$pay_status', '$mode', '$bill_no', $uid, 'misc_veh')");
            showAlert('success','Misc expense recorded and will appear in Vehicle P&L.');
        }
    }
    redirect('fleet_misc_expenses.php');
}

/* ════════════════════════════════════════════════
   LOAD DATA
═══════════════════════════════════════════════ */
$vehicles      = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles WHERE status='Active' ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
$fuel_companies= $db->query("SELECT id,company_name,credit_terms,default_fuel_price FROM fleet_fuel_companies WHERE status='Active' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);
$exp_vendors   = $db->query("SELECT id,vendor_name FROM fleet_expense_vendors WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);

// Outstanding per fuel company for JS
$fc_outstanding = [];
$fc_default_price = [];
foreach ($fuel_companies as $fc) {
    $cred = (float)$db->query("SELECT COALESCE(SUM(amount),0) s FROM fleet_fuel_log WHERE fuel_company_id=".$fc['id']." AND payment_mode='Credit'")->fetch_assoc()['s'];
    $paid = (float)$db->query("SELECT COALESCE(SUM(amount),0) s FROM fleet_fuel_payments WHERE fuel_company_id=".$fc['id'])->fetch_assoc()['s'];
    $fc_outstanding[$fc['id']]    = round($cred - $paid, 2);
    $fc_default_price[$fc['id']] = (float)($fc['default_fuel_price'] ?? 0);
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-clipboard2-plus me-2"></i>Misc Expenses';</script>
<?php

/* ════════════════════════════════════════════════
   LIST
═══════════════════════════════════════════════ */
if ($action === 'list'):
$f_month  = sanitize($_GET['month'] ?? date('Y-m'));
$f_veh    = (int)($_GET['vehicle'] ?? 0);
$f_cat    = sanitize($_GET['cat'] ?? ''); // 'misc_fuel' or 'misc_veh'

$where = "WHERE (e.misc_source='misc_fuel' OR e.misc_source='misc_veh')
    AND e.expense_date LIKE '".substr($f_month,0,7)."%'";
if ($f_veh) $where .= " AND e.vehicle_id=$f_veh";
if ($f_cat) $where .= " AND e.misc_source='".$db->real_escape_string($f_cat)."'";

$misc_rows = $db->query("
    SELECT e.*,
           COALESCE(v.reg_no, '—')              AS reg_no,
           COALESCE(fc.company_name, '')         AS fuel_company_name,
           COALESCE(ev.vendor_name, e.vendor_name, '') AS vendor_display
    FROM fleet_expenses e
    LEFT JOIN fleet_vehicles v    ON v.id = e.vehicle_id
    LEFT JOIN fleet_fuel_companies fc ON fc.id = e.fuel_company_id
    LEFT JOIN fleet_expense_vendors ev ON ev.id = e.vendor_id
    $where
    ORDER BY e.expense_date DESC, e.id DESC
")->fetch_all(MYSQLI_ASSOC);

$total_fuel = array_sum(array_map(fn($r) => $r['misc_source']==='misc_fuel' ? (float)$r['amount'] : 0, $misc_rows));
$total_veh  = array_sum(array_map(fn($r) => $r['misc_source']==='misc_veh'  ? (float)$r['amount'] : 0, $misc_rows));
$total_all  = $total_fuel + $total_veh;
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Misc Expenses</h5>
    <?php if (canDo('fleet_expenses','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Misc Expense</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $f_month ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Vehicle</label>
        <select name="vehicle" class="form-select form-select-sm">
            <option value="">All Vehicles</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $f_veh==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['reg_no']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Category</label>
        <select name="cat" class="form-select form-select-sm">
            <option value="">All Categories</option>
            <option value="misc_fuel" <?= $f_cat==='misc_fuel'?'selected':'' ?>>Supervisor Car Fuel → Fuel Company</option>
            <option value="misc_veh"  <?= $f_cat==='misc_veh' ?'selected':'' ?>>Vehicle P&L Expenses</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div></div>

<!-- Summary cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2 h-100">
        <small class="text-muted">Total Misc</small>
        <div class="fw-bold text-danger fs-6">₹<?= number_format($total_all,2) ?></div>
    </div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2 h-100">
        <small class="text-muted"><i class="bi bi-fuel-pump me-1 text-warning"></i>Supervisor Fuel</small>
        <div class="fw-bold text-warning fs-6">₹<?= number_format($total_fuel,2) ?></div>
        <small class="text-muted" style="font-size:.7rem">→ Fuel Company Ledger</small>
    </div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2 h-100">
        <small class="text-muted"><i class="bi bi-truck-front me-1 text-info"></i>Vehicle P&L</small>
        <div class="fw-bold text-info fs-6">₹<?= number_format($total_veh,2) ?></div>
        <small class="text-muted" style="font-size:.7rem">→ Vehicle P&L</small>
    </div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2 h-100">
        <small class="text-muted">Entries</small>
        <div class="fw-bold fs-6"><?= count($misc_rows) ?></div>
    </div></div>
</div>

<?php if (empty($misc_rows)): ?>
<div class="alert alert-info">No misc expense records found for the selected filters.</div>
<?php else: ?>
<div class="card">
<div class="table-responsive">
<table class="table table-hover table-sm mb-0 align-middle">
<thead class="table-light">
<tr>
    <th>Date</th>
    <th>Category</th>
    <th>Type / Company</th>
    <th>Vehicle</th>
    <th>Vendor / Details</th>
    <th class="text-end">Amount</th>
    <th>Status</th>
    <th>Mode</th>
    <th>Bill No</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($misc_rows as $r): ?>
<tr>
    <td><?= date('d/m/Y', strtotime($r['expense_date'])) ?></td>
    <td>
        <?php if ($r['misc_source'] === 'misc_fuel'): ?>
        <span class="badge bg-warning text-dark"><i class="bi bi-fuel-pump me-1"></i>Supervisor Fuel</span>
        <?php else: ?>
        <span class="badge bg-info text-dark"><i class="bi bi-truck-front me-1"></i>Vehicle P&L</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($r['misc_source'] === 'misc_fuel'): ?>
        <?= htmlspecialchars($r['fuel_company_name'] ?: '—') ?>
        <?php else: ?>
        <span class="badge bg-secondary"><?= htmlspecialchars($r['expense_type']) ?></span>
        <?php endif; ?>
    </td>
    <td><?= htmlspecialchars($r['reg_no']) ?></td>
    <td>
        <?= htmlspecialchars($r['vendor_display'] ?: '') ?>
        <?php if ($r['description']): ?>
        <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($r['description'],0,60,'…')) ?></small>
        <?php endif; ?>
    </td>
    <td class="text-end fw-bold text-danger">₹<?= number_format($r['amount'],2) ?></td>
    <td><span class="badge bg-<?= ($r['payment_status']??'Not Paid')==='Paid'?'success':'warning text-dark' ?>">
        <?= htmlspecialchars($r['payment_status']??'Not Paid') ?></span></td>
    <td><?= htmlspecialchars($r['payment_mode']??'') ?></td>
    <td><?= htmlspecialchars($r['bill_no']??'—') ?></td>
    <td>
        <?php if (canDo('fleet_expenses','update')): ?>
        <a href="?action=edit&id=<?= $r['id'] ?>" class="btn btn-action btn-outline-primary me-1" title="Edit"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (canDo('fleet_expenses','delete') || isAdmin()): ?>
        <a href="?delete=<?= $r['id'] ?>" onclick="return confirm('Delete this misc expense?')" class="btn btn-action btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light">
<tr>
    <th colspan="5" class="text-end">Total</th>
    <th class="text-end text-danger">₹<?= number_format($total_all,2) ?></th>
    <th colspan="4"></th>
</tr>
</tfoot>
</table>
</div>
</div>
<?php endif; ?>

<?php
/* ════════════════════════════════════════════════
   ADD / EDIT
═══════════════════════════════════════════════ */
elseif ($action === 'add' || $action === 'edit'):
$ex = [];
if ($id > 0) {
    $ex = $db->query("SELECT * FROM fleet_expenses WHERE id=$id AND (misc_source='misc_fuel' OR misc_source='misc_veh') LIMIT 1")->fetch_assoc() ?? [];
    if (!$ex) { showAlert('danger','Record not found.'); redirect('fleet_misc_expenses.php'); }
}
$is_fuel = ($ex['misc_source'] ?? ($_GET['cat'] ?? '')) === 'misc_fuel';
// Pre-fetch litres for fuel edit
$edit_litres = 0;
if ($id > 0 && $is_fuel && !empty($ex['fuel_log_id'])) {
    $fl = $db->query("SELECT litres FROM fleet_fuel_log WHERE id=".(int)$ex['fuel_log_id']." LIMIT 1")->fetch_assoc();
    $edit_litres = (float)($fl['litres'] ?? 0);
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Misc Expense</h5>
    <a href="fleet_misc_expenses.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="POST">
<input type="hidden" name="save_misc" value="1">
<input type="hidden" name="id" value="<?= $id ?>">

<div class="card mb-3">
<div class="card-header fw-semibold"><i class="bi bi-tag me-2"></i>Expense Category</div>
<div class="card-body">
<div class="row g-3">
    <div class="col-12">
        <div class="d-flex gap-3 flex-wrap">
            <!-- Fuel category -->
            <div class="form-check form-check-inline p-3 border rounded <?= $is_fuel?'border-warning bg-warning bg-opacity-10':'' ?>" style="cursor:pointer" id="catFuelBox">
                <input class="form-check-input" type="radio" name="expense_cat" id="catFuel" value="fuel"
                    <?= $is_fuel?'checked':'' ?> onchange="switchCat('fuel')">
                <label class="form-check-label fw-semibold" for="catFuel">
                    <i class="bi bi-fuel-pump me-2 text-warning"></i>Supervisor Car Fuel
                    <div class="text-muted" style="font-size:.78rem;font-weight:normal">Links to Fuel Company ledger<br>Adds to fuel company outstanding</div>
                </label>
            </div>
            <!-- Vehicle P&L category -->
            <div class="form-check form-check-inline p-3 border rounded <?= !$is_fuel?'border-info bg-info bg-opacity-10':'' ?>" style="cursor:pointer" id="catVehBox">
                <input class="form-check-input" type="radio" name="expense_cat" id="catVeh" value="veh"
                    <?= !$is_fuel?'checked':'' ?> onchange="switchCat('veh')">
                <label class="form-check-label fw-semibold" for="catVeh">
                    <i class="bi bi-truck-front me-2 text-info"></i>Vehicle P&L Expense
                    <div class="text-muted" style="font-size:.78rem;font-weight:normal">Travel allowance, room rent,<br>mobile, pollution, police challan</div>
                </label>
            </div>
        </div>
    </div>
</div>
</div>
</div>

<!-- ── FUEL fields ── -->
<div id="fuelSection" class="card mb-3" style="<?= $is_fuel?'':'display:none' ?>">
<div class="card-header fw-semibold" style="background:#fff3cd"><i class="bi bi-fuel-pump me-2 text-warning"></i>Supervisor Car Fuel Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Fuel Company <span class="text-danger">*</span></label>
        <select name="fuel_company_id" id="fuelCompSel" class="form-select" onchange="onFuelCompChange(this.value)">
            <option value="">— Select Fuel Company —</option>
            <?php foreach ($fuel_companies as $fc): ?>
            <option value="<?= $fc['id'] ?>"
                data-price="<?= (float)($fc['default_fuel_price']??0) ?>"
                <?= (int)($ex['fuel_company_id']??0)==$fc['id']?'selected':'' ?>>
                <?= htmlspecialchars($fc['company_name']) ?>
                (<?= $fc['credit_terms'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Outstanding Balance</label>
        <input type="text" id="fcOutstanding" class="form-control bg-light" readonly placeholder="Select company">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Vehicle No <small class="text-muted">(Optional)</small></label>
        <input type="text" name="supervisor_vehicle_no" id="supervisorVehNo" class="form-control"
            value="<?= htmlspecialchars($ex['supervisor_vehicle_no'] ?? '') ?>"
            placeholder="e.g. MH12AB1234">
        <div class="form-text">Supervisor car reg no</div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
        <input type="date" name="fuel_expense_date" id="dateFieldFuel" class="form-control"
            value="<?= htmlspecialchars($ex['expense_date'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Rate/Litre (₹)</label>
        <div class="input-group">
            <span class="input-group-text">₹</span>
            <input type="number" name="fuel_rate" id="fuelRate" class="form-control" step="0.01"
                placeholder="Auto from company" oninput="calcFuelAmt()">
        </div>
        <div class="form-text">Auto-filled from fuel company default</div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Litres</label>
        <input type="number" name="litres" id="litresField" class="form-control" step="0.01"
            placeholder="0.00" value="<?= $edit_litres ?: '' ?>" oninput="calcFuelAmt()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount (₹) <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text">₹</span>
            <input type="number" name="fuel_amount" id="amtFieldFuel" class="form-control" step="0.01"
                value="<?= htmlspecialchars($ex['amount'] ?? '') ?>" placeholder="0.00"
                oninput="calcFuelRate()">
        </div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Bill No</label>
        <input type="text" name="bill_no" class="form-control"
            value="<?= htmlspecialchars($ex['bill_no'] ?? '') ?>" placeholder="Optional">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Credit / Cash</label>
        <select name="payment_status" class="form-select" id="payStatusFuel">
            <option value="Not Paid" <?= ($ex['payment_status']??'Not Paid')==='Not Paid'?'selected':'' ?>>Credit — Added to Fuel Company Account</option>
            <option value="Paid"     <?= ($ex['payment_status']??'')==='Paid'?'selected':'' ?>>Cash — Paid Immediately</option>
        </select>
        <div class="form-text">Credit → outstanding added to fuel company ledger</div>
    </div>
    <input type="hidden" name="payment_mode" value="Credit">
    <div class="col-12">
        <label class="form-label">Description / Notes</label>
        <input type="text" name="description" class="form-control"
            value="<?= htmlspecialchars($ex['description'] ?? '') ?>"
            placeholder="e.g. Supervisor car fuel — site visit Nagpur">
    </div>
</div></div>
</div>

<!-- ── VEHICLE P&L fields ── -->
<div id="vehSection" class="card mb-3" style="<?= !$is_fuel?'':'display:none' ?>">
<div class="card-header fw-semibold" style="background:#cff4fc"><i class="bi bi-truck-front me-2 text-info"></i>Vehicle P&L Expense Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-3">
        <label class="form-label fw-bold">Vehicle <span class="text-danger">*</span></label>
        <select name="vehicle_id" id="vehSelVeh" class="form-select">
            <option value="">— Select Vehicle —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= (int)($ex['vehicle_id']??0)==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['reg_no']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label fw-bold">Expense Type <span class="text-danger">*</span></label>
        <select name="expense_type" class="form-select">
            <?php foreach (MISC_VEH_TYPES as $et): ?>
            <option value="<?= $et ?>" <?= ($ex['expense_type']??'')===$et?'selected':'' ?>><?= $et ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
        <input type="date" name="veh_expense_date" id="dateFieldVeh" class="form-control" value="<?= htmlspecialchars($ex['expense_date'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount (₹) <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text">₹</span>
            <input type="number" name="veh_amount" id="amtFieldVeh" class="form-control" step="0.01"
                value="<?= htmlspecialchars($ex['amount'] ?? '') ?>" placeholder="0.00">
        </div>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Vendor / Payee</label>
        <div class="input-group">
            <select name="vendor_id" id="vendorSelVeh" class="form-select">
                <option value="">— Select Vendor —</option>
                <?php foreach ($exp_vendors as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= (int)($ex['vendor_id']??0)==$ev['id']?'selected':'' ?>><?= htmlspecialchars($ev['vendor_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (canDo('fleet_expenses','create')): ?>
            <button type="button" class="btn btn-outline-success" onclick="addMiscVendor()"><i class="bi bi-plus-lg"></i></button>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Bill No</label>
        <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($ex['bill_no'] ?? '') ?>" placeholder="Optional">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Payment Status</label>
        <select name="payment_status" class="form-select">
            <option value="Not Paid" <?= ($ex['payment_status']??'Not Paid')==='Not Paid'?'selected':'' ?>>Not Paid</option>
            <option value="Paid"     <?= ($ex['payment_status']??'')==='Paid'?'selected':'' ?>>Paid</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['Cash','NEFT','RTGS','Cheque','UPI'] as $m): ?>
            <option <?= ($ex['payment_mode']??'Cash')===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Description / Notes</label>
        <textarea name="description" class="form-control" rows="2" placeholder="e.g. Travel allowance for supervisor — site visit Nagpur"><?= htmlspecialchars($ex['description'] ?? '') ?></textarea>
    </div>
</div></div>
</div>

<!-- Submit -->
<div class="text-end mb-4">
    <a href="fleet_misc_expenses.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Expense</button>
</div>
</form>

<script>
var fcOutstanding  = <?= json_encode($fc_outstanding) ?>;
var fcDefaultPrice = <?= json_encode($fc_default_price) ?>;

function switchCat(cat) {
    var isFuel = cat === 'fuel';
    document.getElementById('fuelSection').style.display = isFuel ? '' : 'none';
    document.getElementById('vehSection').style.display  = isFuel ? 'none' : '';
    document.getElementById('catFuelBox').classList.toggle('border-warning', isFuel);
    document.getElementById('catFuelBox').classList.toggle('bg-warning',     isFuel);
    document.getElementById('catFuelBox').classList.toggle('bg-opacity-10',  isFuel);
    document.getElementById('catVehBox').classList.toggle('border-info', !isFuel);
    document.getElementById('catVehBox').classList.toggle('bg-info',     !isFuel);
    document.getElementById('catVehBox').classList.toggle('bg-opacity-10',!isFuel);
}

function onFuelCompChange(fcId) {
    // Show outstanding balance
    var outEl = document.getElementById('fcOutstanding');
    if (fcId && fcOutstanding[fcId] !== undefined) {
        var val = parseFloat(fcOutstanding[fcId]);
        outEl.value = '₹' + val.toLocaleString('en-IN',{minimumFractionDigits:2});
        outEl.className = 'form-control bg-light fw-bold ' + (val > 0 ? 'text-danger' : 'text-success');
    } else {
        outEl.value = ''; outEl.className = 'form-control bg-light';
    }
    // Auto-populate rate from company default fuel price
    var rateEl = document.getElementById('fuelRate');
    if (fcId && fcDefaultPrice[fcId] && parseFloat(fcDefaultPrice[fcId]) > 0) {
        rateEl.value = parseFloat(fcDefaultPrice[fcId]).toFixed(2);
    } else {
        rateEl.value = '';
    }
    calcFuelAmt(); // recalculate amount with new rate
}

function calcFuelAmt() {
    // litres × rate → auto-fill amount
    var litres = parseFloat(document.getElementById('litresField').value) || 0;
    var rate   = parseFloat(document.getElementById('fuelRate').value)    || 0;
    if (litres > 0 && rate > 0) {
        document.getElementById('amtFieldFuel').value = (litres * rate).toFixed(2);
    }
}

function calcFuelRate() {
    // When amount is manually typed, recalc amount if litres+rate are set
    calcFuelAmt();
}

function addMiscVendor() {
    var name = window.prompt('Enter Vendor / Payee name');
    if (!name || !name.trim()) return;
    var fd = new FormData();
    fd.append('vendor_name', name.trim());
    fetch('fleet_misc_expenses.php?ajax=add_vendor', {method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(d){
            if (!d.success) { alert(d.error||'Could not add vendor'); return; }
            var sel = document.getElementById('vendorSelVeh');
            var exists = Array.from(sel.options).some(function(o){return String(o.value)===String(d.id);});
            if (!exists) {
                var opt = document.createElement('option');
                opt.value = d.id; opt.textContent = d.name;
                sel.appendChild(opt);
            }
            sel.value = d.id;
        })
        .catch(function(){alert('Network error');});
}

// Init on load
(function(){
    var fuelRad = document.getElementById('catFuel');
    switchCat(fuelRad && fuelRad.checked ? 'fuel' : 'veh');
    var fcSel = document.getElementById('fuelCompSel');
    if (fcSel && fcSel.value) onFuelCompChange(fcSel.value);
})();
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
