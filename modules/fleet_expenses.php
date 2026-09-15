<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (file_exists('../includes/r2_helper.php')) require_once '../includes/r2_helper.php';
$db = getDB();
requirePerm('fleet_expenses', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_expense_vendors (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_name     VARCHAR(200) NOT NULL,
    status          ENUM('Active','Inactive') DEFAULT 'Active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vendor_name (vendor_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS fleet_expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT NOT NULL,
    trip_id         INT DEFAULT NULL,
    expense_date    DATE NOT NULL,
    expense_type    VARCHAR(60) NOT NULL,
    vendor_name     VARCHAR(200),
    description     TEXT,
    amount          DECIMAL(12,2) DEFAULT 0,
    payment_mode    VARCHAR(40) DEFAULT 'Cash',
    bill_no         VARCHAR(80),
    odometer        INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Auto-migrate: add trip_id if missing ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    foreach ([
        'trip_id'    => "INT DEFAULT NULL AFTER vehicle_id",
        'vendor_id'   => "INT DEFAULT NULL AFTER expense_type",
        'payment_status' => "ENUM('Paid','Not Paid') DEFAULT 'Not Paid' AFTER amount",
        'created_by'  => "INT DEFAULT 0",
        'bill_image'  => "VARCHAR(255) DEFAULT NULL COMMENT 'R2 key for bill image'",
    ] as $col => $def) {
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_expenses'
            AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
        if (!$exists) $db->query("ALTER TABLE fleet_expenses ADD COLUMN `$col` $def");
    }
})($db);

/* Safe seed/backfill for legacy manual vendor/workshop names */
$db->query("INSERT IGNORE INTO fleet_expense_vendors (vendor_name)
    SELECT DISTINCT TRIM(vendor_name)
    FROM fleet_expenses
    WHERE vendor_name IS NOT NULL AND TRIM(vendor_name) != ''");
$db->query("UPDATE fleet_expenses e
    INNER JOIN fleet_expense_vendors v
        ON LOWER(TRIM(e.vendor_name)) = LOWER(TRIM(v.vendor_name))
    SET e.vendor_id = v.id
    WHERE (e.vendor_id IS NULL OR e.vendor_id = 0)
      AND e.vendor_name IS NOT NULL
      AND TRIM(e.vendor_name) != ''");

if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_vendor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    requirePerm('fleet_expenses', 'create');

    $vendor_name = trim((string)($_POST['vendor_name'] ?? ''));
    if ($vendor_name === '') {
        echo json_encode(['success' => false, 'error' => 'Vendor / Workshop name required.']);
        exit;
    }

    $vendor_name_sql = $db->real_escape_string($vendor_name);
    $existing = $db->query("SELECT id, vendor_name FROM fleet_expense_vendors
        WHERE LOWER(TRIM(vendor_name)) = LOWER(TRIM('$vendor_name_sql'))
        LIMIT 1")->fetch_assoc();
    if ($existing) {
        echo json_encode(['success' => true, 'id' => (int)$existing['id'], 'name' => html_entity_decode($existing['vendor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')]);
        exit;
    }

    $db->query("INSERT INTO fleet_expense_vendors (vendor_name) VALUES ('$vendor_name_sql')");
    if ($db->insert_id > 0) {
        echo json_encode(['success' => true, 'id' => (int)$db->insert_id, 'name' => html_entity_decode($vendor_name, ENT_QUOTES | ENT_HTML5, 'UTF-8')]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => $db->error ?: 'Could not add Vendor / Workshop.']);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'set_payment_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    requirePerm('fleet_expenses', 'update');

    $expense_id = (int)($_POST['expense_id'] ?? 0);
    $payment_status = trim((string)($_POST['payment_status'] ?? ''));
    $payment_mode = sanitize($_POST['payment_mode'] ?? '');
    $allowed_modes = ['Cash','NEFT','RTGS','Cheque','UPI'];

    if (!$expense_id || !in_array($payment_status, ['Paid', 'Not Paid'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid payment update request.']);
        exit;
    }
    if ($payment_mode !== '' && !in_array($payment_mode, $allowed_modes, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid payment mode.']);
        exit;
    }

    $existing = $db->query("SELECT id FROM fleet_expenses WHERE id=$expense_id LIMIT 1")->fetch_assoc();
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Expense entry not found.']);
        exit;
    }

    $status_sql = $db->real_escape_string($payment_status);
    $mode_sql = $payment_mode !== '' ? "'" . $db->real_escape_string($payment_mode) . "'" : "payment_mode";
    $db->query("UPDATE fleet_expenses
        SET payment_status='$status_sql', payment_mode=$mode_sql
        WHERE id=$expense_id");

    echo json_encode(['success' => true]);
    exit;
}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

$expense_types = ['Tyre','Tyre Puncher','Fly Ash Labour Charge','Grease Charge','Service/Oil Change','Repair','Permit Renewal','Insurance Renewal',
    'Fitness Renewal','PUC Renewal','Battery','Brake','Clutch','Electrical','Body Work',
    'Toll/RTO','Driver Allowance','Supervisor Salary','Miscellaneous'];

/* ── Delete ── */
if (isset($_GET['delete'])) {
    $back = (int)($_GET['back'] ?? 0);
    $db->query("DELETE FROM fleet_expenses WHERE id=".(int)$_GET['delete']);
    showAlert('success','Expense deleted.');
    redirect($back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_expenses.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    requirePerm('fleet_expenses', $id > 0 ? 'update' : 'create');
    $veh_id  = (int)$_POST['vehicle_id'];
    $trip_id = (int)($_POST['trip_id'] ?? 0);
    $date    = sanitize($_POST['expense_date']);
    $type    = sanitize($_POST['expense_type']);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $vendor  = '';
    if ($vendor_id > 0) {
        $vendor_row = $db->query("SELECT vendor_name FROM fleet_expense_vendors WHERE id=$vendor_id LIMIT 1")->fetch_assoc();
        $vendor = sanitize($vendor_row['vendor_name'] ?? '');
        if ($vendor === '') {
            $vendor_id = 0;
        }
    }
    $desc    = sanitize($_POST['description'] ?? '');
    $amount  = (float)$_POST['amount'];
    $payment_status = sanitize($_POST['payment_status'] ?? 'Not Paid');
    $mode    = sanitize($_POST['payment_mode'] ?? 'Cash');
    $bill    = sanitize($_POST['bill_no'] ?? '');
    $back    = sanitize($_POST['back'] ?? '');
    $trip_sql = $trip_id ? $trip_id : 'NULL';
    if (!in_array($payment_status, ['Paid', 'Not Paid'], true)) $payment_status = 'Not Paid';

    if (!$veh_id || !$date || !$type || $amount <= 0) {
        showAlert('danger','Vehicle, Date, Type and Amount are required.');
        redirect("fleet_expenses.php?action=".($id>0?"edit&id=$id":'add'));
    }

    $bill_img_sql = '';
    $new_img_key  = '';
    $old_bill_img = $id > 0
        ? ($db->query("SELECT bill_image FROM fleet_expenses WHERE id=$id LIMIT 1")->fetch_assoc()['bill_image'] ?? '')
        : '';
    if (!empty($_POST['delete_bill_image']) && $old_bill_img) {
        if (function_exists('r2_delete')) r2_delete($old_bill_img);
        $bill_img_sql = ", bill_image=NULL";
        $old_bill_img = '';
    }
    // Direct cURL upload (bypasses r2_handle_upload)
    if (!empty($_FILES['bill_image']['name']) && $_FILES['bill_image']['error'] === 0) {
        $_ext = strtolower(pathinfo($_FILES['bill_image']['name'], PATHINFO_EXTENSION));
        $_allowed = ['pdf','jpg','jpeg','png','webp','gif'];
        if (in_array($_ext, $_allowed) && $_FILES['bill_image']['size'] <= 10485760) {
            $_body    = file_get_contents($_FILES['bill_image']['tmp_name']);
            $_newKey  = 'expense_bills/exp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $_ext;
            $_mime    = $_ext === 'pdf' ? 'application/pdf' : 'image/' . ($_ext === 'jpg' ? 'jpeg' : $_ext);
            $_wurl    = defined('R2_WORKER_URL') ? R2_WORKER_URL : 'https://dms-r2-upload.jpsgujral.workers.dev';
            $_token   = defined('R2_WORKER_TOKEN') ? R2_WORKER_TOKEN : 'dms_worker_s3cur3_t0k3n_2024';
            $_ch = curl_init($_wurl . '/' . $_newKey);
            curl_setopt_array($_ch, [
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_POSTFIELDS     => $_body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    'X-DMS-Token: ' . $_token,
                    'Content-Type: ' . $_mime,
                    'Content-Length: ' . strlen($_body),
                ],
            ]);
            $_resp = curl_exec($_ch);
            $_code = curl_getinfo($_ch, CURLINFO_HTTP_CODE);
            curl_close($_ch);
            if ($_code === 200) {
                $new_img_key = $_newKey;
                $bill_img_sql = ", bill_image='".$db->real_escape_string($_newKey)."'";
                if ($old_bill_img && function_exists('r2_delete')) r2_delete($old_bill_img);
            }
        }
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_expenses SET
            vehicle_id=$veh_id, trip_id=$trip_sql,
            expense_date='$date', expense_type='$type', vendor_id=" . ($vendor_id ?: 'NULL') . ", vendor_name='$vendor',
            description='$desc', amount=$amount, payment_status='$payment_status', payment_mode='$mode',
            bill_no='$bill'$bill_img_sql
            WHERE id=$id");
        showAlert('success', 'Expense updated.');
    } else {
        $uid_exp = (int)($_SESSION['user_id'] ?? 0);
        $img_col = ''; $img_val = '';
        if (preg_match("/bill_image='([^']+)'/", $bill_img_sql, $bm)) {
            $img_col = ',bill_image';
            $img_val = ",'".$db->real_escape_string($bm[1])."'";
        }
        $db->query("INSERT INTO fleet_expenses
            (vehicle_id,trip_id,expense_date,expense_type,vendor_id,vendor_name,description,
             amount,payment_status,payment_mode,bill_no,created_by$img_col)
            VALUES ($veh_id,$trip_sql,'$date','$type',".($vendor_id ?: 'NULL').",'$vendor','$desc',
            $amount,'$payment_status','$mode','$bill',$uid_exp$img_val)");
        $id = $db->insert_id;
        showAlert('success', 'Expense added.');
    }
    if ($back) redirect('fleet_trips.php?action=view&id='.$back);
    redirect('fleet_expenses.php');
}

$vehicles = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
$trips    = $db->query("SELECT t.id, t.trip_no, t.trip_date, v.reg_no
    FROM fleet_trips t LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    WHERE t.status != 'Cancelled' ORDER BY t.trip_date DESC, t.id DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$expense_vendors = $db->query("SELECT id, vendor_name FROM fleet_expense_vendors WHERE status='Active' ORDER BY vendor_name ASC")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-tools me-2"></i>Vehicle Expenses';</script>
<?php

/* ════════ LIST ════════ */
if ($action === 'list'):
$filter_veh  = (int)($_GET['vehicle'] ?? 0);
$filter_mo   = sanitize($_GET['month'] ?? date('Y-m'));
$filter_type = sanitize($_GET['type'] ?? '');
$filter_trip = (int)($_GET['trip_id'] ?? 0);
$filter_pay_list = sanitize($_GET['payment_status'] ?? '');

$where = "WHERE e.expense_date LIKE '".substr($filter_mo,0,7)."%'";
if ($filter_veh)  $where .= " AND e.vehicle_id=$filter_veh";
if ($filter_type) $where .= " AND e.expense_type='".($db->real_escape_string($filter_type))."'";
if ($filter_trip) $where .= " AND e.trip_id=$filter_trip";
if (in_array($filter_pay_list, ['Paid','Not Paid'], true))
    $where .= " AND COALESCE(e.payment_status,'Not Paid')='".$db->real_escape_string($filter_pay_list)."'";
// if (!isAdmin())   $where .= " AND e.created_by=".(int)($_SESSION['user_id']??0);

$expenses = $db->query("SELECT e.*, v.reg_no, v.make, v.model, t.trip_no,
        COALESCE(ev.vendor_name, e.vendor_name) AS vendor_display
    FROM fleet_expenses e
    LEFT JOIN fleet_vehicles v ON e.vehicle_id=v.id
    LEFT JOIN fleet_trips t ON e.trip_id=t.id
    LEFT JOIN fleet_expense_vendors ev ON e.vendor_id=ev.id
    $where ORDER BY e.expense_date DESC, e.id DESC")->fetch_all(MYSQLI_ASSOC);

$total = array_sum(array_column($expenses,'amount'));
$by_type = [];
foreach ($expenses as $ex) $by_type[$ex['expense_type']] = ($by_type[$ex['expense_type']] ?? 0) + $ex['amount'];
arsort($by_type);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Vehicle Expenses</h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?action=ledger" class="btn btn-outline-dark"><i class="bi bi-journal-text me-1"></i>Vendor / Workshop Ledger</a>
        <?php if (canDo('fleet_expenses','create')): ?>
        <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Expense</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3"><div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $filter_mo ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Vehicle</label>
        <select name="vehicle" class="form-select form-select-sm">
            <option value="">All Vehicles</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $filter_veh==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['reg_no']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Expense Type</label>
        <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            <?php foreach ($expense_types as $et): ?>
            <option <?= $filter_type===$et?'selected':'' ?>><?= $et ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Trip</label>
        <select name="trip_id" class="form-select form-select-sm">
            <option value="">All Trips</option>
            <?php foreach ($trips as $tr): ?>
            <option value="<?= $tr['id'] ?>" <?= $filter_trip==$tr['id']?'selected':'' ?>>
                <?= htmlspecialchars($tr['trip_no']) ?> — <?= htmlspecialchars($tr['reg_no']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Payment Status</label>
        <select name="payment_status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="Not Paid" <?= $filter_pay_list === 'Not Paid' ? 'selected' : '' ?>>Not Paid</option>
            <option value="Paid" <?= $filter_pay_list === 'Paid' ? 'selected' : '' ?>>Paid</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div></div>

<?php
$total_paid   = array_sum(array_map(fn($e) => ($e['payment_status'] ?? 'Not Paid') === 'Paid' ? (float)$e['amount'] : 0, $expenses));
$total_unpaid = array_sum(array_map(fn($e) => ($e['payment_status'] ?? 'Not Paid') !== 'Paid' ? (float)$e['amount'] : 0, $expenses));
?>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Expenses</small><div class="fw-bold text-danger fs-6">₹<?= number_format($total,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Entries</small><div class="fw-bold fs-6"><?= count($expenses) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center p-2"><small class="text-muted text-success">Paid</small><div class="fw-bold text-success fs-6">₹<?= number_format($total_paid,2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center p-2"><small class="text-muted text-warning">Not Paid</small><div class="fw-bold text-warning fs-6">₹<?= number_format($total_unpaid,2) ?></div></div></div>
    <?php if ($by_type): $top = array_key_first($by_type); ?>
    <div class="col-12 col-md-2"><div class="card p-2"><small class="text-muted">Top Type</small><div class="fw-bold small"><?= htmlspecialchars($top) ?> — ₹<?= number_format($by_type[$top],2) ?></div></div></div>
    <?php endif; ?>
</div>

<?php
$expenses_by_vehicle = [];
foreach ($expenses as $ex) {
    $vehicle_key = trim((string)($ex['reg_no'] ?? ''));
    if ($vehicle_key === '') $vehicle_key = 'Unassigned Vehicle';
    $expenses_by_vehicle[$vehicle_key][] = $ex;
}
?>

<?php if (empty($expenses_by_vehicle)): ?>
<div class="alert alert-info mb-0">No vehicle expense records found for the selected filters.</div>
<?php else: ?>
<?php foreach ($expenses_by_vehicle as $vehicle_no => $vehicle_rows): ?>
<?php
    $vehicle_total = array_sum(array_map(fn($row) => (float)$row['amount'], $vehicle_rows));
    $vehicle_paid = array_sum(array_map(fn($row) => (($row['payment_status'] ?? 'Not Paid') === 'Paid') ? (float)$row['amount'] : 0, $vehicle_rows));
    $vehicle_unpaid = $vehicle_total - $vehicle_paid;
?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="fw-semibold"><i class="bi bi-truck-front me-2"></i><?= htmlspecialchars($vehicle_no) ?></div>
        <div class="small">
            <span class="badge bg-light text-dark me-2">Entries: <?= count($vehicle_rows) ?></span>
            <span class="badge bg-light text-dark me-2">Total: Rs.<?= number_format($vehicle_total,2) ?></span>
            <span class="badge bg-success me-2">Paid: Rs.<?= number_format($vehicle_paid,2) ?></span>
            <span class="badge bg-warning text-dark">Not Paid: Rs.<?= number_format($vehicle_unpaid,2) ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr>
                    <th>Date</th><th>Trip</th><th>Type</th><th>Vendor</th>
                    <th class="text-end">Amount</th><th>Status</th><th>Mode</th><th>Bill No</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($vehicle_rows as $ex): ?>
                <tr>
                    <td><?= date('d/m/Y',strtotime($ex['expense_date'])) ?></td>
                    <td><?= $ex['trip_no'] ? '<a href="fleet_trips.php?action=view&id='.$ex['trip_id'].'" class="badge bg-info text-dark text-decoration-none">'.htmlspecialchars($ex['trip_no']).'</a>' : '<span class="text-muted">-</span>' ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($ex['expense_type']) ?></span></td>
                    <td><?= htmlspecialchars($ex['vendor_display']??'-') ?></td>
                    <td class="text-end fw-bold">Rs.<?= number_format($ex['amount'],2) ?></td>
                    <td><span class="badge bg-<?= ($ex['payment_status'] ?? 'Not Paid') === 'Paid' ? 'success' : 'warning text-dark' ?>"><?= htmlspecialchars($ex['payment_status'] ?? 'Not Paid') ?></span></td>
                    <td><?= htmlspecialchars($ex['payment_mode']) ?></td>
                    <td><?= htmlspecialchars($ex['bill_no']??'-') ?>
                        <?php if (!empty($ex['bill_image'])): ?>
                        <a href="<?= htmlspecialchars(r2_url($ex['bill_image'])) ?>" target="_blank" class="ms-1" title="View Bill Image">
                            <i class="bi bi-image text-success"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (canDo('fleet_expenses','update')): ?>
                        <a href="?action=edit&id=<?= $ex['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if (true): ?>
                        <a href="?delete=<?= $ex['id'] ?><?= $ex['trip_id']?'&back='.$ex['trip_id']:'' ?>" onclick="return confirm('Delete?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
/* ════════ ADD/EDIT ════════ */
/* ════════ LEDGER ════════ */
elseif ($action === 'ledger'):
$filter_vendor = (int)($_GET['vendor_id'] ?? 0);
$filter_pay = sanitize($_GET['payment_status'] ?? '');
$filter_month = sanitize($_GET['month'] ?? '');

$ledger_where = "WHERE 1=1";
if ($filter_month) {
    $ledger_where .= " AND e.expense_date LIKE '".substr($filter_month,0,7)."%'";
}
if ($filter_vendor) $ledger_where .= " AND COALESCE(e.vendor_id,0)=$filter_vendor";
if (in_array($filter_pay, ['Paid','Not Paid'], true)) {
    $ledger_where .= " AND COALESCE(e.payment_status,'Not Paid')='".$db->real_escape_string($filter_pay)."'";
}

$ledger_rows = $db->query("SELECT e.*, v.reg_no, t.trip_no,
        COALESCE(ev.vendor_name, e.vendor_name, 'Unassigned') AS vendor_display
    FROM fleet_expenses e
    LEFT JOIN fleet_vehicles v ON e.vehicle_id=v.id
    LEFT JOIN fleet_trips t ON e.trip_id=t.id
    LEFT JOIN fleet_expense_vendors ev ON e.vendor_id=ev.id
    $ledger_where
    ORDER BY vendor_display ASC, e.expense_date DESC, e.id DESC")->fetch_all(MYSQLI_ASSOC);

$ledger_groups = [];
foreach ($ledger_rows as $row) {
    $vendor_key = $row['vendor_display'] ?: 'Unassigned';
    $ledger_groups[$vendor_key][] = $row;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Vendor / Workshop Ledger</h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?action=list" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Expenses</a>
        <?php if (canDo('fleet_expenses','create')): ?>
        <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Expense</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3"><div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <input type="hidden" name="action" value="ledger">
    <div class="col-12 col-md-3">
        <label class="form-label form-label-sm">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $filter_month ?>">
        <div class="form-text">Leave blank for up-to-date ledger.</div>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label form-label-sm">Vendor / Workshop</label>
        <select name="vendor_id" class="form-select form-select-sm">
            <option value="">All Vendors / Workshops</option>
            <?php foreach ($expense_vendors as $vendor_opt): ?>
            <option value="<?= $vendor_opt['id'] ?>" <?= $filter_vendor === (int)$vendor_opt['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($vendor_opt['vendor_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Payment Status</label>
        <select name="payment_status" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="Not Paid" <?= $filter_pay === 'Not Paid' ? 'selected' : '' ?>>Not Paid</option>
            <option value="Paid" <?= $filter_pay === 'Paid' ? 'selected' : '' ?>>Paid</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div></div>

<?php if (!$ledger_rows): ?>
<div class="alert alert-info">No ledger entries found for the selected filters.</div>
<?php else: ?>
<?php foreach ($ledger_groups as $vendor_name => $rows): ?>
<?php
    $group_total = array_sum(array_column($rows, 'amount'));
    $group_paid = 0;
    $group_unpaid = 0;
    foreach ($rows as $row) {
        if (($row['payment_status'] ?? 'Not Paid') === 'Paid') $group_paid += (float)$row['amount'];
        else $group_unpaid += (float)$row['amount'];
    }
?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><i class="bi bi-building me-2"></i><?= htmlspecialchars($vendor_name) ?></div>
        <div class="small">
            <span class="badge bg-light text-dark me-2">Total: Rs.<?= number_format($group_total,2) ?></span>
            <span class="badge bg-success me-2">Paid: Rs.<?= number_format($group_paid,2) ?></span>
            <span class="badge bg-warning text-dark">Not Paid: Rs.<?= number_format($group_unpaid,2) ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th><th>Trip</th><th>Vehicle</th><th>Type</th><th>Description</th>
                        <th class="text-end">Amount</th><th>Bill</th>
                        <th style="min-width:160px">Payment Status</th>
                        <th style="min-width:140px">Method</th>
                        <?php if (canDo('fleet_expenses','update') || canDo('fleet_expenses','update')): ?>
                        <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $isPaid = ($row['payment_status'] ?? 'Not Paid') === 'Paid'; ?>
                    <tr id="ledger-row-<?= $row['id'] ?>">
                        <td><?= date('d/m/Y', strtotime($row['expense_date'])) ?></td>
                        <td><?= $row['trip_no'] ? '<a href="fleet_trips.php?action=view&id='.$row['trip_id'].'" class="badge bg-info text-dark text-decoration-none">'.htmlspecialchars($row['trip_no']).'</a>' : '<span class="text-muted">-</span>' ?></td>
                        <td><span class="badge bg-dark"><?= htmlspecialchars($row['reg_no'] ?? '-') ?></span></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($row['expense_type']) ?></span></td>
                        <td><?= htmlspecialchars($row['description'] ?: '-') ?></td>
                        <td class="text-end fw-bold">₹<?= number_format((float)$row['amount'],2) ?></td>
                        <td>
                            <?= htmlspecialchars($row['bill_no'] ?: '-') ?>
                            <?php if (!empty($row['bill_image'])): ?>
                            <a href="<?= htmlspecialchars(r2_url($row['bill_image'])) ?>" target="_blank" class="ms-1" title="View Bill Image">
                                <i class="bi bi-image text-success"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (canDo('fleet_expenses','update')): ?>
                            <select class="form-select form-select-sm ledger-status-sel" data-id="<?= $row['id'] ?>">
                                <option value="Not Paid" <?= !$isPaid ? 'selected' : '' ?>>Not Paid</option>
                                <option value="Paid"     <?= $isPaid  ? 'selected' : '' ?>>Paid</option>
                            </select>
                            <?php else: ?>
                            <span class="badge bg-<?= $isPaid ? 'success' : 'warning text-dark' ?>">
                                <?= $isPaid ? 'Paid' : 'Not Paid' ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (canDo('fleet_expenses','update')): ?>
                            <select class="form-select form-select-sm ledger-mode-sel" data-id="<?= $row['id'] ?>">
                                <?php foreach (['Cash','NEFT','RTGS','Cheque','UPI'] as $m): ?>
                                <option value="<?= $m ?>" <?= ($row['payment_mode'] ?? 'Cash') === $m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <span class="small"><?= htmlspecialchars($row['payment_mode'] ?: 'Cash') ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if (canDo('fleet_expenses','update')): ?>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-primary ledger-save-btn"
                                    data-id="<?= $row['id'] ?>"
                                    title="Save payment changes">
                                <i class="bi bi-check2"></i>
                            </button>
                            <a href="?action=edit&id=<?= $row['id'] ?>&back_to=ledger"
                               class="btn btn-sm btn-outline-secondary ms-1"
                               title="Edit full expense">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php elseif ($action === 'add' || $action === 'edit'): /* ADD / EDIT */
$ex   = [];
$back = (int)($_GET['back'] ?? 0);
if ($id > 0) $ex = $db->query("SELECT * FROM fleet_expenses WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
$prefill_trip = (int)($_GET['trip'] ?? ($ex['trip_id'] ?? 0));
$trip_prefill = [];
if ($prefill_trip) {
    $trip_prefill = $db->query("SELECT t.*, v.reg_no FROM fleet_trips t
        LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
        WHERE t.id=$prefill_trip LIMIT 1")->fetch_assoc() ?? [];
}

// Build trip→vehicle map for JS
$trip_details_res = $db->query("SELECT id,vehicle_id,trip_date FROM fleet_trips WHERE status!='Cancelled' ORDER BY id DESC LIMIT 200");
$trip_details_map = [];
if ($trip_details_res) while ($td = $trip_details_res->fetch_assoc()) {
    $trip_details_map[$td['id']] = $td;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Vehicle Expense
        <?php if ($prefill_trip && isset($trip_prefill['trip_no'])): ?>
        <span class="badge bg-info text-dark ms-2"><?= htmlspecialchars($trip_prefill['trip_no']) ?></span>
        <?php endif; ?>
    </h5>
    <a href="<?= $back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_expenses.php' ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="save_expense" value="1">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="back" value="<?= $back ?: $prefill_trip ?>">
<div class="card"><div class="card-body"><div class="row g-3">

    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Trip Reference</label>
        <select name="trip_id" id="tripRefExp" class="form-select" onchange="fillTripExp(this)">
            <option value="">— No Trip (General) —</option>
            <?php foreach ($trips as $tr): ?>
            <option value="<?= $tr['id'] ?>"
                <?= ($ex['trip_id']??$prefill_trip)==$tr['id']?'selected':'' ?>>
                <?= htmlspecialchars($tr['trip_no']) ?> — <?= htmlspecialchars($tr['reg_no']) ?> (<?= date('d/m/Y',strtotime($tr['trip_date'])) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Vehicle *</label>
        <select name="vehicle_id" id="vehSelExp" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($ex['vehicle_id']??$trip_prefill['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].' '.$v['make']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Date *</label>
        <input type="date" name="expense_date" class="form-control"
               value="<?= $ex['expense_date'] ?? $trip_prefill['trip_date'] ?? date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Expense Type *</label>
        <select name="expense_type" class="form-select" required>
            <option value="">— Select Type —</option>
            <?php foreach ($expense_types as $et): ?>
            <option <?= ($ex['expense_type']??'')===$et?'selected':'' ?>><?= $et ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Bill No</label>
        <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($ex['bill_no']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount (₹) *</label>
        <input type="number" name="amount" class="form-control" step="0.01" value="<?= $ex['amount']??'' ?>" required placeholder="0.00">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Vendor / Workshop</label>
        <div class="input-group">
            <select name="vendor_id" id="vendorSelExp" class="form-select">
                <option value="">— Select Vendor / Workshop —</option>
                <?php foreach ($expense_vendors as $vendor_opt): ?>
                <option value="<?= $vendor_opt['id'] ?>" <?= (int)($ex['vendor_id'] ?? 0) === (int)$vendor_opt['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($vendor_opt['vendor_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (canDo('fleet_expenses','create')): ?>
            <button type="button" class="btn btn-outline-success" onclick="addExpenseVendor()"><i class="bi bi-plus-lg"></i></button>
            <?php endif; ?>
        </div>
        <?php if (!empty($ex['vendor_name']) && empty($ex['vendor_id'])): ?>
        <div class="form-text text-muted">Legacy saved name: <?= htmlspecialchars($ex['vendor_name']) ?></div>
        <?php endif; ?>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Payment Status</label>
        <select name="payment_status" class="form-select">
            <option value="Not Paid" <?= ($ex['payment_status'] ?? 'Not Paid') === 'Not Paid' ? 'selected' : '' ?>>Not Paid</option>
            <option value="Paid" <?= ($ex['payment_status'] ?? '') === 'Paid' ? 'selected' : '' ?>>Paid</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Method of Payment</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['Cash','NEFT','RTGS','Cheque','UPI'] as $m): ?>
            <option <?= ($ex['payment_mode']??'Cash')===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">The same payment method is used later in the Vendor / Workshop ledger.</div>
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($ex['description']??'') ?></textarea>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Bill Image / Receipt</label>
        <?php $cur_img = $ex['bill_image'] ?? ''; ?>
        <?php if ($cur_img): ?>
        <div class="mb-2">
            <a href="<?= htmlspecialchars(r2_url($cur_img)) ?>" target="_blank" class="btn btn-outline-success btn-sm">
                <i class="bi bi-image me-1"></i>View Current Bill
            </a>
            <label class="ms-2 form-check-label text-danger small">
                <input type="checkbox" name="delete_bill_image" value="1" class="form-check-input me-1">Remove
            </label>
        </div>
        <?php endif; ?>
        <input type="file" name="bill_image" class="form-control" accept="image/*,.pdf">
        <div class="form-text">JPG, PNG, PDF accepted. Max 5MB.</div>
    </div>
    <div class="col-12 text-end">
        <a href="<?= $back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_expenses.php' ?>" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Expense</button>
    </div>
</div></div></div>
</form>
<script>
const tripDetailsMapExp = <?= json_encode($trip_details_map, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function fillTripExp(sel) {
    var tid = parseInt(sel.value) || 0;
    if (!tid) return;
    var td = tripDetailsMapExp[tid] || {};
    if (td.vehicle_id) document.getElementById('vehSelExp').value = td.vehicle_id;
    if (td.trip_date)  document.querySelector('[name="expense_date"]').value = td.trip_date;
}

function addExpenseVendor() {
    var name = window.prompt('Enter Vendor / Workshop name');
    if (!name) return;
    name = name.trim();
    if (!name) return;

    var fd = new FormData();
    fd.append('vendor_name', name);

    fetch('fleet_expenses.php?ajax=add_vendor', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.success) {
                alert(d.error || 'Could not add Vendor / Workshop.');
                return;
            }
            var sel = document.getElementById('vendorSelExp');
            var exists = Array.from(sel.options).some(function(opt){
                return String(opt.value) === String(d.id);
            });
            if (!exists) {
                var opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.name;
                sel.appendChild(opt);
            }
            sel.value = d.id;
        })
        .catch(function(){
            alert('Network error while adding Vendor / Workshop.');
        });
}

function markExpensePaid(expenseId) {
    var modeEl = document.getElementById('ledgerPayMode' + expenseId);
    var paymentMode = modeEl ? modeEl.value : 'Cash';
    var fd = new FormData();
    fd.append('expense_id', expenseId);
    fd.append('payment_status', 'Paid');
    fd.append('payment_mode', paymentMode);
    fetch('fleet_expenses.php?ajax=set_payment_status', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.success) { alert(d.error || 'Could not update.'); return; }
            window.location.reload();
        }).catch(function(){ alert('Network error.'); });
}

function markExpenseUnpaid(expenseId) {
    if (!confirm('Revert this expense to Not Paid?')) return;
    var fd = new FormData();
    fd.append('expense_id', expenseId);
    fd.append('payment_status', 'Not Paid');
    fetch('fleet_expenses.php?ajax=set_payment_status', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.success) { alert(d.error || 'Could not update.'); return; }
            window.location.reload();
        }).catch(function(){ alert('Network error.'); });
}

/* ── Ledger inline save ── */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.ledger-save-btn');
    if (!btn) return;
    var id = btn.dataset.id;
    var row = document.getElementById('ledger-row-' + id);
    var statusSel = row.querySelector('.ledger-status-sel');
    var modeSel   = row.querySelector('.ledger-mode-sel');
    if (!statusSel || !modeSel) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    var fd = new FormData();
    fd.append('expense_id', id);
    fd.append('payment_status', statusSel.value);
    fd.append('payment_mode',   modeSel.value);

    fetch('fleet_expenses.php?ajax=set_payment_status', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.success) {
                alert(d.error || 'Could not save changes.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2"></i>';
                return;
            }
            /* visual feedback — flash row green briefly */
            row.style.transition = 'background 0.3s';
            row.style.background = '#d1e7dd';
            setTimeout(function(){ row.style.background = ''; }, 1200);
            btn.innerHTML = '<i class="bi bi-check2"></i>';
            btn.disabled = false;
        })
        .catch(function(){
            alert('Network error while saving.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2"></i>';
        });
});
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>

