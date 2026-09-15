<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_overhead_helper.php';
$db = getDB();
requirePerm('fleet_vehicle_pnl', 'view');
fleetEnsureOverheadRates($db);

$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_trip_end_date = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' AND COLUMN_NAME='end_date' LIMIT 1")->num_rows > 0;
$trip_period_expr = $has_trip_end_date
    ? "CASE WHEN end_date IS NOT NULL AND CAST(end_date AS CHAR) <> '0000-00-00' THEN end_date ELSE trip_date END"
    : "trip_date";

$month = sanitize($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);
if ($vehicle_id <= 0) { showAlert('danger','Vehicle required.'); redirect('fleet_vehicle_pnl.php?month='.$month); }

$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$veh = $db->query("SELECT reg_no, make, model FROM fleet_vehicles WHERE id=$vehicle_id LIMIT 1")->fetch_assoc();

$trips_sql = "SELECT id, trip_no, trip_date, total_weight, freight_amount, subtotal, driver_id,
        $trip_period_expr AS pnl_trip_date,
        driver_advance, toll_amount, loading_charges, unloading_charges, other_expenses
    FROM fleet_trips
    WHERE vehicle_id=$vehicle_id AND status='Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'
    ORDER BY $trip_period_expr, id";
$trips_q = $db->query($trips_sql);
if (!$trips_q) {
    throw new Exception('Fleet Vehicle P&L drill-down trip query failed: ' . $db->error);
}
$trips = $trips_q->fetch_all(MYSQLI_ASSOC);

$fuel = $db->query("SELECT fl.fuel_date, fl.trip_id, fl.litres, fl.rate_per_litre, fl.amount, fl.driver_advance, fl.bill_no,
        fc.company_name AS vendor_name, t.trip_no
    FROM fleet_fuel_log fl
    LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id=fc.id
    LEFT JOIN fleet_trips t ON fl.trip_id=t.id
    WHERE fl.vehicle_id=$vehicle_id AND fl.fuel_date BETWEEN '$month_start' AND '$month_end'
    ORDER BY fl.fuel_date, fl.id")->fetch_all(MYSQLI_ASSOC);

$exp = $db->query("SELECT e.expense_date, e.trip_id, e.expense_type, e.vendor_name, e.amount, e.bill_no,
        t.trip_no
    FROM fleet_expenses e
    LEFT JOIN fleet_trips t ON e.trip_id=t.id
    WHERE e.vehicle_id=$vehicle_id AND e.expense_date BETWEEN '$month_start' AND '$month_end'
    ORDER BY e.expense_date, e.id")->fetch_all(MYSQLI_ASSOC);

$driver_trip_total = [];
foreach ($trips as $t) {
    $did = (int)$t['driver_id'];
    if ($did > 0) $driver_trip_total[$did] = ($driver_trip_total[$did] ?? 0) + 1;
}

$salary = [];
if ($driver_trip_total) {
    $driver_ids = implode(',', array_map('intval', array_keys($driver_trip_total)));
    $sal_rows = $db->query("SELECT s.driver_id, s.net_payable, d.full_name
        FROM fleet_driver_salary s
        LEFT JOIN fleet_drivers d ON d.id=s.driver_id
        WHERE s.salary_month='$month' AND s.driver_id IN ($driver_ids)")->fetch_all(MYSQLI_ASSOC);
    foreach ($sal_rows as $s) {
        $did = (int)$s['driver_id'];
        $alloc = (float)$s['net_payable'] / max(1, (int)$driver_trip_total[$did]);
        $salary[] = ['driver_name'=>$s['full_name'] ?: ('Driver#'.$did), 'net_payable'=>(float)$s['net_payable'], 'alloc_to_vehicle'=>$alloc];
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-graph-up-arrow me-2"></i>P&L Drill-down';</script>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">P&amp;L Drill-down: <?= htmlspecialchars($veh['reg_no'] ?? ('Vehicle#'.$vehicle_id)) ?> (<?= htmlspecialchars($month) ?>)</h5>
    <a href="fleet_vehicle_pnl.php?month=<?= urlencode($month) ?>&vehicle_id=<?= $vehicle_id ?>" class="btn btn-outline-secondary btn-sm">Back</a>
</div>

<?php
$trip_weight_total = 0.0;
$trip_revenue_total = 0.0;
$trip_inline_total = 0.0;
foreach ($trips as $idx => $t) {
    $trip_weight_total += (float)($t['total_weight'] ?? 0);
    $trip_revenue_total += ((float)$t['subtotal'] > 0 ? (float)$t['subtotal'] : (float)$t['freight_amount']);
    $trip_inline_total += (float)$t['toll_amount'] + (float)$t['loading_charges'] + (float)$t['unloading_charges'] + (float)$t['other_expenses'];
    $overhead = fleetTripOverheadAmount($db, (string)($t['pnl_trip_date'] ?: $t['trip_date']), (float)($t['total_weight'] ?? 0));
    $trips[$idx]['_overhead_amount'] = (float)$overhead['amount'];
    $trips[$idx]['_overhead_rate'] = (float)$overhead['rate_per_mt'];
}
$trip_overhead_total = array_sum(array_column($trips, '_overhead_amount'));
?>
<div class="card mb-3"><div class="card-header">Trips Revenue + Inline Expenses</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Trip</th><th class="text-end">Weight</th><th class="text-end">Revenue</th><th class="text-end">Inline Expenses</th><th class="text-end">Statutory / MT</th></tr></thead><tbody>
<?php foreach($trips as $t): $rev=(float)$t['subtotal']>0?(float)$t['subtotal']:(float)$t['freight_amount']; $inl=(float)$t['toll_amount']+(float)$t['loading_charges']+(float)$t['unloading_charges']+(float)$t['other_expenses']; ?>
<tr><td><?= date('d/m/Y',strtotime($t['pnl_trip_date'] ?: $t['trip_date'])) ?></td><td><a href="fleet_trips.php?action=view&id=<?= (int)$t['id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_vehicle_pnl_drilldown.php') ?>"><?= htmlspecialchars($t['trip_no']) ?></a></td><td class="text-end"><?= number_format((float)$t['total_weight'],3) ?></td><td class="text-end text-success">&#8377;<?= number_format($rev,2) ?></td><td class="text-end">&#8377;<?= number_format($inl,2) ?></td><td class="text-end">&#8377;<?= number_format((float)$t['_overhead_amount'],2) ?><br><small class="text-muted">&#8377;<?= number_format((float)$t['_overhead_rate'],2) ?>/MT</small></td></tr>
<?php endforeach; if(!$trips): ?><tr><td colspan="6" class="text-muted p-2">No trips.</td></tr><?php endif; ?>
</tbody>
<?php if($trips): ?>
<tfoot class="table-light">
<tr>
    <th colspan="2" class="text-end">Total</th>
    <th class="text-end"><?= number_format($trip_weight_total,3) ?></th>
    <th class="text-end text-success">&#8377;<?= number_format($trip_revenue_total,2) ?></th>
    <th class="text-end">&#8377;<?= number_format($trip_inline_total,2) ?></th>
    <th class="text-end">&#8377;<?= number_format($trip_overhead_total,2) ?></th>
</tr>
</tfoot>
<?php endif; ?>
</table></div></div>

<div class="card mb-3"><div class="card-header">Fuel Entries</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Vendor</th><th>Challan No</th><th class="text-end">Litres</th><th class="text-end">Rate</th><th class="text-end">Amount</th><th class="text-end">Advance</th></tr></thead><tbody>
<?php foreach($fuel as $f): ?><tr><td><?= date('d/m/Y',strtotime($f['fuel_date'])) ?></td><td><?= htmlspecialchars($f['vendor_name'] ?: '-') ?></td><td><?= $f['trip_no'] ? htmlspecialchars($f['trip_no']) : '-' ?></td><td class="text-end"><?= number_format((float)$f['litres'],2) ?></td><td class="text-end">&#8377;<?= number_format((float)$f['rate_per_litre'],2) ?></td><td class="text-end">&#8377;<?= number_format((float)$f['amount'],2) ?></td><td class="text-end">&#8377;<?= number_format((float)$f['driver_advance'],2) ?></td></tr><?php endforeach; if(!$fuel): ?><tr><td colspan="7" class="text-muted p-2">No fuel entries.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="card mb-3"><div class="card-header">Other Vehicle Expenses</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Type</th><th>Vendor</th><th>Challan No</th><th class="text-end">Amount</th></tr></thead><tbody>
<?php foreach($exp as $e): ?><tr><td><?= date('d/m/Y',strtotime($e['expense_date'])) ?></td><td><?= htmlspecialchars($e['expense_type']) ?></td><td><?= htmlspecialchars($e['vendor_name']?:'-') ?></td><td><?= $e['trip_no'] ? htmlspecialchars($e['trip_no']) : '-' ?></td><td class="text-end">&#8377;<?= number_format((float)$e['amount'],2) ?></td></tr><?php endforeach; if(!$exp): ?><tr><td colspan="5" class="text-muted p-2">No expense entries.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="card"><div class="card-header">Driver Salary Allocation Basis</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Driver</th><th class="text-end">Net Salary (Month)</th><th class="text-end">Allocated to Vehicle</th></tr></thead><tbody>
<?php foreach($salary as $s): ?><tr><td><?= htmlspecialchars($s['driver_name']) ?></td><td class="text-end">&#8377;<?= number_format((float)$s['net_payable'],2) ?></td><td class="text-end">&#8377;<?= number_format((float)$s['alloc_to_vehicle'],2) ?></td></tr><?php endforeach; if(!$salary): ?><tr><td colspan="3" class="text-muted p-2">No salary allocation rows for this vehicle/month.</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php include '../includes/footer.php'; ?>

