<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_fuel', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_log (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    fuel_company_id   INT NOT NULL,
    vehicle_id        INT NOT NULL,
    driver_id         INT DEFAULT NULL,
    trip_id           INT DEFAULT NULL,
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

/* ── Auto-migrate: add trip_id if missing ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    foreach ([
        'trip_id'        => "INT DEFAULT NULL AFTER driver_id",
        'fuel_bill_path'   => "VARCHAR(255) DEFAULT NULL",
        'created_by'       => "INT DEFAULT 0",
        'driver_advance'   => "DECIMAL(10,2) DEFAULT 0",
    ] as $col => $def) {
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_fuel_log'
            AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
        if (!$exists) $db->query("ALTER TABLE fleet_fuel_log ADD COLUMN `$col` $def");
    }
})($db);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete'])) {
    $db->query("DELETE FROM fleet_fuel_log WHERE id=".(int)$_GET['delete']);
    showAlert('success','Fuel entry deleted.');
    $back = $_GET['back'] ?? '';
    redirect($back ? 'fleet_trips.php?action=view&id='.(int)$back : 'fleet_fuel.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fuel'])) {
    requirePerm('fleet_fuel', $id > 0 ? 'update' : 'create');
    $fc_id   = (int)$_POST['fuel_company_id'];
    $veh_id  = (int)$_POST['vehicle_id'];
    $drv_id  = (int)($_POST['driver_id'] ?? 0);
    $trip_id = (int)($_POST['trip_id'] ?? 0);
    $date    = sanitize($_POST['fuel_date']);
    $litres  = (float)$_POST['litres'];
    $rate    = (float)$_POST['rate_per_litre'];
    $amount  = round($litres * $rate, 2);
    $odo     = (int)($_POST['odometer'] ?? 0);
    $mode    = sanitize($_POST['payment_mode'] ?? 'Credit');
    $bill    = sanitize($_POST['bill_no'] ?? '');
    $drv_adv = (float)($_POST['driver_advance'] ?? 0);
    $notes   = sanitize($_POST['notes'] ?? '');
    $drv_sql  = $drv_id  ? $drv_id  : 'NULL';
    $trip_sql = $trip_id ? $trip_id : 'NULL';
    $back     = sanitize($_POST['back'] ?? '');

    if (!$fc_id || !$veh_id || !$date || $litres <= 0) {
        showAlert('danger','Fuel Company, Vehicle, Date and Litres are required.');
        redirect("fleet_fuel.php?action=".($id>0?"edit&id=$id":'add'));
    }

    /* ── Handle fuel bill upload via R2 ── */
    $bill_path_sql  = '';
    $old_bill_path  = $id > 0 ? ($db->query("SELECT fuel_bill_path FROM fleet_fuel_log WHERE id=$id LIMIT 1")->fetch_assoc()['fuel_bill_path'] ?? '') : '';
    if (!empty($_POST['delete_fuel_bill']) && $old_bill_path) {
        r2_delete($old_bill_path);
        $bill_path_sql = ", fuel_bill_path=NULL";
        $old_bill_path = '';
    }
    $new_bill_key = r2_handle_upload('fuel_bill', 'fuel_bills/fuel', $old_bill_path);
    if ($new_bill_key) {
        $fuel_bill_path = $new_bill_key;
        $bill_path_sql  = ", fuel_bill_path='".$db->real_escape_string($new_bill_key)."'";
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_fuel_log SET fuel_company_id=$fc_id, vehicle_id=$veh_id,
            driver_id=$drv_sql, trip_id=$trip_sql, fuel_date='$date', litres=$litres,
            rate_per_litre=$rate, amount=$amount, odometer=$odo,
            payment_mode='$mode', bill_no='$bill', notes='$notes',
            driver_advance=$drv_adv$bill_path_sql
            WHERE id=$id");
        showAlert('success','Fuel entry updated.');
    } else {
        $doc_col = $bill_path_sql ? ',fuel_bill_path' : '';
        $doc_val = isset($fuel_bill_path) ? (",'".$db->real_escape_string($fuel_bill_path)."'") : '';
        $uid_fuel = (int)($_SESSION['user_id'] ?? 0);
        $db->query("INSERT INTO fleet_fuel_log
            (fuel_company_id,vehicle_id,driver_id,trip_id,fuel_date,litres,rate_per_litre,
             amount,odometer,payment_mode,bill_no,notes,driver_advance$doc_col,created_by)
            VALUES ($fc_id,$veh_id,$drv_sql,$trip_sql,'$date',$litres,$rate,
            $amount,$odo,'$mode','$bill','$notes',$drv_adv$doc_val,$uid_fuel)");
        showAlert('success','Fuel entry added.');
    }
    // Return to trip view if came from there
    if ($back) redirect('fleet_trips.php?action=view&id='.$back);
    redirect('fleet_fuel.php');
}

$vehicles       = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles WHERE status='Active' ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
$drivers        = $db->query("SELECT id,full_name FROM fleet_drivers WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$fuel_companies = $db->query("SELECT id,company_name,credit_terms,default_fuel_price FROM fleet_fuel_companies WHERE status='Active' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);
$trips          = $db->query("SELECT t.id, t.trip_no, t.trip_date, v.reg_no
    FROM fleet_trips t LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    WHERE t.status != 'Cancelled' ORDER BY t.trip_date DESC, t.id DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-droplet-fill me-2"></i>Fuel Management';</script>
<?php

/* ════════════════════ LIST ════════════════════ */
if ($action === 'list'):
$filter_veh  = (int)($_GET['vehicle'] ?? 0);
$filter_trip = (int)($_GET['trip_id'] ?? 0);
$filter_mo   = sanitize($_GET['month'] ?? date('Y-m'));
$_uid_fuel = (int)($_SESSION['user_id'] ?? 0);

$where = "WHERE fl.fuel_date LIKE '".substr($filter_mo,0,7)."%'";
if ($filter_veh)  $where .= " AND fl.vehicle_id=$filter_veh";
if ($filter_trip) $where .= " AND fl.trip_id=$filter_trip";
// All users can view all fuel entries

$entries = $db->query("SELECT fl.*, v.reg_no, v.make, v.model,
    d.full_name AS driver_name, fc.company_name AS fuel_company,
    t.trip_no
    FROM fleet_fuel_log fl
    LEFT JOIN fleet_vehicles v ON fl.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON fl.driver_id=d.id
    LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id=fc.id
    LEFT JOIN fleet_trips t ON fl.trip_id=t.id
    $where ORDER BY fl.fuel_date DESC, fl.id DESC")->fetch_all(MYSQLI_ASSOC);

$totals = ['litres'=>0,'amount'=>0,'credit'=>0,'cash'=>0];
foreach ($entries as $e) {
    $totals['litres'] += (float)($e['litres'] ?? 0);
    $totals['amount'] += (float)($e['amount'] ?? 0);
    if (($e['payment_mode'] ?? '') === 'Credit') $totals['credit'] += (float)($e['amount'] ?? 0);
    else $totals['cash'] += (float)($e['amount'] ?? 0);
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fuel Management</h5>
    <?php if (canDo('fleet_fuel','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Fuel Entry</a>
    <?php endif; ?>
</div>

<!-- Filters -->
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
            <option value="<?= $v['id'] ?>" <?= $filter_veh==$v['id']?'selected':'' ?>><?= htmlspecialchars((string)($v['reg_no'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Trip</label>
        <select name="trip_id" class="form-select form-select-sm">
            <option value="">All Trips</option>
            <?php foreach ($trips as $tr): ?>
            <option value="<?= $tr['id'] ?>" <?= $filter_trip==$tr['id']?'selected':'' ?>>
                <?= htmlspecialchars((string)($tr['trip_no'] ?? '')) ?> — <?= htmlspecialchars((string)($tr['reg_no'] ?? '')) ?> (<?= !empty($tr['trip_date']) ? date('d/m/Y',strtotime($tr['trip_date'])) : '—' ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div></div>

<!-- Summary cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Litres</small><div class="fw-bold fs-6"><?= number_format($totals['litres'],2) ?> L</div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Amount</small><div class="fw-bold fs-6 text-primary">₹<?= number_format($totals['amount'],2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Credit</small><div class="fw-bold fs-6 text-danger">₹<?= number_format($totals['credit'],2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Cash</small><div class="fw-bold fs-6 text-success">₹<?= number_format($totals['cash'],2) ?></div></div></div>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Date</th><th>Trip</th><th>Vehicle</th><th>Driver</th><th>Fuel Company</th>
    <th class="text-end">Litres</th><th class="text-end">Rate</th>
    <th class="text-end">Amount</th><th class="text-end">Total</th><th>Mode</th><th>Bill No</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($entries as $e): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($e['fuel_date'])) ?></td>
    <td><?= $e['trip_no'] ? '<a href="fleet_trips.php?action=view&id='.$e['trip_id'].'" class="badge bg-info text-dark text-decoration-none">'.htmlspecialchars((string)($e['trip_no'] ?? '')).'</a>' : '<span class="text-muted">—</span>' ?></td>
    <td><span class="badge bg-dark"><?= htmlspecialchars((string)($e['reg_no'] ?? '')) ?></span></td>
    <td><?= htmlspecialchars((string)($e['driver_name'] ?? '—')) ?></td>
    <td><?= htmlspecialchars((string)($e['fuel_company'] ?? '')) ?></td>
    <td class="text-end"><?= number_format((float)($e['litres'] ?? 0), 2) ?> L</td>
    <td class="text-end">₹<?= number_format((float)($e['rate_per_litre'] ?? 0), 2) ?></td>
    <td class="text-end fw-bold">₹<?= number_format((float)($e['amount'] ?? 0), 2) ?></td>
    <td class="text-end fw-bold text-success">₹<?= number_format((float)($e['amount'] ?? 0) + (float)($e['driver_advance'] ?? 0), 2) ?></td>
    <td><span class="badge bg-<?= ($e['payment_mode'] ?? '') === 'Credit' ? 'warning text-dark' : 'success' ?>"><?= htmlspecialchars((string)($e['payment_mode'] ?? '—')) ?></span></td>
    <td><?= htmlspecialchars((string)($e['bill_no'] ?? '—')) ?>
        <?php if (!empty($e['fuel_bill_path'])): ?>
        <a href="<?= htmlspecialchars(r2_url($e['fuel_bill_path'])) ?>" target="_blank" class="ms-1" title="View Bill">
            <i class="bi bi-file-earmark-text text-success"></i>
        </a>
        <?php endif; ?>
    </td>
    <td>
        <?php if (canDo('fleet_fuel','update')): ?>
        <a href="?action=edit&id=<?= $e['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <a href="?delete=<?= $e['id'] ?><?= $e['trip_id']?'&back='.$e['trip_id']:'' ?>" onclick="return confirm('Delete this entry?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<?php
/* ════════════════════ ADD/EDIT ════════════════════ */
else:
$e    = [];
$back = (int)($_GET['back'] ?? 0); // trip_id to return to
if ($id > 0) $e = $db->query("SELECT * FROM fleet_fuel_log WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
// Pre-fill vehicle/driver from trip if coming from trip view
$prefill_trip = (int)($_GET['trip'] ?? ($e['trip_id'] ?? 0));
$trip_prefill = [];
if ($prefill_trip) {
    $trip_prefill = $db->query("SELECT t.*, v.reg_no FROM fleet_trips t
        LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
        WHERE t.id=$prefill_trip LIMIT 1")->fetch_assoc() ?? [];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Fuel Entry
        <?php if ($prefill_trip && isset($trip_prefill['trip_no'])): ?>
        <span class="badge bg-info text-dark ms-2"><?= htmlspecialchars($trip_prefill['trip_no']) ?></span>
        <?php endif; ?>
    </h5>
    <a href="<?= $back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_fuel.php' ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="save_fuel" value="1">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="back" value="<?= $back ?: ($prefill_trip ?: '') ?>">
<div class="row g-3">
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-droplet-fill me-2"></i>Fuel Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Trip Reference</label>
        <select name="trip_id" id="tripRefSelect" class="form-select" onchange="fillTripDetails(this)">
            <option value="">— No Trip (General) —</option>
            <?php foreach ($trips as $tr): ?>
            <option value="<?= $tr['id'] ?>"
                data-vehicle="<?= $tr['id'] ?>"
                data-reg="<?= htmlspecialchars($tr['reg_no']) ?>"
                data-date="<?= $tr['trip_date'] ?>"
                <?= ($e['trip_id']??$prefill_trip)==$tr['id']?'selected':'' ?>>
                <?= htmlspecialchars($tr['trip_no']) ?> — <?= htmlspecialchars($tr['reg_no']) ?> (<?= date('d/m/Y',strtotime($tr['trip_date'])) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Fuel Company *</label>
        <select name="fuel_company_id" id="fuelCompanySelect" class="form-select" required onchange="onFuelCompanyChange()">
            <option value="">— Select —</option>
            <?php foreach ($fuel_companies as $fc): ?>
            <option value="<?= $fc['id'] ?>" data-price="<?= htmlspecialchars(number_format((float)($fc['default_fuel_price'] ?? 0), 2, '.', '')) ?>" <?= ($e['fuel_company_id']??0)==$fc['id']?'selected':'' ?>>
                <?= htmlspecialchars($fc['company_name']) ?> (<?= $fc['credit_terms'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Vehicle *</label>
        <select name="vehicle_id" id="vehicleSelFuel" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>"
                <?= ($e['vehicle_id']??$trip_prefill['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].' '.$v['make']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Driver</label>
        <select name="driver_id" id="driverSelFuel" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>"
                <?= ($e['driver_id']??$trip_prefill['driver_id']??0)==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Date *</label>
        <input type="date" name="fuel_date" class="form-control"
               value="<?= $e['fuel_date'] ?? $trip_prefill['trip_date'] ?? date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Litres *</label>
        <input type="number" name="litres" class="form-control" step="0.01"
               value="<?= $e['litres']??'' ?>" required onchange="calcAmt()" placeholder="0.00">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Rate/Litre (₹)</label>
        <input type="number" name="rate_per_litre" class="form-control" step="0.01"
               value="<?= isset($e['rate_per_litre']) ? htmlspecialchars($e['rate_per_litre']) : '' ?>" onchange="calcAmt()" placeholder="0.00">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Amount (₹)</label>
        <input type="number" id="amtDisplay" class="form-control bg-light" step="0.01"
               value="<?= $e['amount']??'' ?>" readonly>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <option <?= ($e['payment_mode']??'Credit')==='Credit'?'selected':'' ?>>Credit</option>
            <option <?= ($e['payment_mode']??'')==='Cash'?'selected':'' ?>>Cash</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Bill No</label>
        <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($e['bill_no']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Driver Advance (₹)</label>
        <input type="number" id="drvAdvDisplay" name="driver_advance" class="form-control" step="0.01"
               value="<?= $e['driver_advance']??0 ?>" placeholder="0.00" onchange="calcAmt()">
        <div class="form-text">Amount paid to driver, billed via fuel company</div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Total (₹)</label>
        <input type="number" id="totalDisplay" class="form-control bg-success bg-opacity-10 fw-bold" step="0.01"
               readonly placeholder="0.00">
        <div class="form-text text-success">Fuel + Advance</div>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Notes / Remarks</label>
        <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($e['notes']??'') ?>" placeholder="e.g. Before loading, After loading, Extra due to overload">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Fuel Bill (PDF / Image)</label>
        <?php $cur_bill = $e['fuel_bill_path'] ?? ''; ?>
        <?php if ($cur_bill): ?>
        <div class="mb-1">
            <a href="<?= htmlspecialchars(r2_url($cur_bill)) ?>" target="_blank" class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-earmark-arrow-down me-1"></i>View Current Bill
            </a>
            <label class="ms-2 text-danger small">
                <input type="checkbox" name="delete_fuel_bill" value="1" class="form-check-input me-1">Remove
            </label>
        </div>
        <?php endif; ?>
        <input type="file" name="fuel_bill" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp">
        <div class="form-text">PDF, JPG, PNG (max 5MB)</div>
    </div>
</div></div></div></div>
<div class="col-12 text-end">
    <a href="<?= $back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_fuel.php' ?>" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Entry</button>
</div>
</div>
</form>

<?php
// Build trip→vehicle/driver map for JS
$trip_veh_map = [];
foreach ($trips as $tr) $trip_veh_map[$tr['id']] = $tr;
// Need vehicle_id and driver_id from fleet_trips
$trip_details_res = $db->query("SELECT id, vehicle_id, driver_id, trip_date FROM fleet_trips WHERE status != 'Cancelled' ORDER BY id DESC LIMIT 200");
$trip_details_map = [];
if ($trip_details_res) while ($td = $trip_details_res->fetch_assoc()) {
    $trip_details_map[$td['id']] = $td;
}
?>
<script>
const tripDetailsMap = <?= json_encode($trip_details_map, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function fillTripDetails(sel) {
    var tid = parseInt(sel.value) || 0;
    if (!tid) return;
    var td = tripDetailsMap[tid] || {};
    if (td.vehicle_id) document.getElementById('vehicleSelFuel').value = td.vehicle_id;
    if (td.driver_id)  document.getElementById('driverSelFuel').value  = td.driver_id;
    if (td.trip_date)  document.querySelector('[name="fuel_date"]').value = td.trip_date;
}
function onFuelCompanyChange() {
    var sel = document.getElementById('fuelCompanySelect');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    var price = parseFloat(opt.getAttribute('data-price')) || 0;
    if (price > 0) {
        document.querySelector('[name="rate_per_litre"]').value = price.toFixed(2);
        calcAmt();
    }
}
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($id === 0): ?>
    onFuelCompanyChange();
    <?php endif; ?>
    calcAmt();
});

function calcAmt() {
    var l   = parseFloat(document.querySelector('[name=litres]').value)||0;
    var r   = parseFloat(document.querySelector('[name=rate_per_litre]').value)||0;
    var amt = l * r;
    var adv = parseFloat(document.getElementById('drvAdvDisplay').value)||0;
    document.getElementById('amtDisplay').value   = amt.toFixed(2);
    document.getElementById('totalDisplay').value = (amt + adv).toFixed(2);
}
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
