<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_overhead_helper.php';
$db = getDB();
requirePerm('fleet_vehicle_pnl', 'view');

$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_trip_end_date = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' AND COLUMN_NAME='end_date' LIMIT 1")->num_rows > 0;
$trip_period_expr = $has_trip_end_date
    ? "CASE WHEN end_date IS NOT NULL AND CAST(end_date AS CHAR) <> '0000-00-00' THEN end_date ELSE trip_date END"
    : "trip_date";

$month = sanitize($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$vehicle_filter = (int)($_GET['vehicle_id'] ?? 0);

fleetEnsureOverheadRates($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_overhead_rate'])) {
    if (!isAdmin()) {
        showAlert('danger', 'Only Admin can change vehicle overhead rates.');
        redirect('fleet_vehicle_pnl.php?month=' . urlencode($month) . '&vehicle_id=' . $vehicle_filter);
    }

    $rate_per_mt = (float)($_POST['rate_per_mt'] ?? 0);
    $effective_from = trim((string)($_POST['effective_from'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($rate_per_mt < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective_from)) {
        showAlert('danger', 'Enter a valid rate and effective date.');
        redirect('fleet_vehicle_pnl.php?month=' . urlencode($month) . '&vehicle_id=' . $vehicle_filter);
    }

    $safe_notes = $db->real_escape_string(substr($notes, 0, 255));
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $db->query("INSERT INTO fleet_trip_overhead_rates (rate_per_mt, effective_from, notes, created_by)
        VALUES ($rate_per_mt, '$effective_from', '$safe_notes', $user_id)
        ON DUPLICATE KEY UPDATE rate_per_mt=VALUES(rate_per_mt), notes=VALUES(notes), created_by=VALUES(created_by)");
    showAlert('success', 'Vehicle overhead rate saved. Old trips keep using the rate effective on their trip date.');
    redirect('fleet_vehicle_pnl.php?month=' . urlencode($month) . '&vehicle_id=' . $vehicle_filter);
}

$overhead_rates = $db->query("SELECT * FROM fleet_trip_overhead_rates ORDER BY effective_from DESC, id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$vehicles = $db->query("SELECT id, reg_no, make, model, current_status
    FROM fleet_vehicles
    ORDER BY reg_no ASC")->fetch_all(MYSQLI_ASSOC);

$vehicle_map = [];
foreach ($vehicles as $v) {
    $live_status = (string)($v['current_status'] ?? '');
    $vehicle_map[(int)$v['id']] = [
        'vehicle_id' => (int)$v['id'],
        'reg_no' => (string)$v['reg_no'],
        'vehicle_name' => trim(($v['reg_no'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? '')),
        'live_status' => $live_status,
        'trip_count' => 0,
        'total_weight' => 0.0,
        'revenue' => 0.0,
        'trip_inline_expenses' => 0.0,
        'fuel_expenses' => 0.0,
        'other_expenses' => 0.0,
        'fixed_overhead_expenses' => 0.0,
        'salary_alloc' => 0.0,
    ];
}

// Keep this card aligned with Trip Orders workflow status (not vehicle board status).
$in_transit_sql = "SELECT COUNT(DISTINCT vehicle_id) AS c FROM fleet_trips WHERE status='In Transit'";
if ($vehicle_filter > 0) {
    $in_transit_sql .= " AND vehicle_id=$vehicle_filter";
}
$in_transit_row = $db->query($in_transit_sql)->fetch_assoc();
$in_transit_count = (int)($in_transit_row['c'] ?? 0);

$transit_trip_rows = $db->query("SELECT vehicle_id, COUNT(*) AS c
    FROM fleet_trips
    WHERE status='In Transit'
    GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
$transit_trip_map = [];
foreach ($transit_trip_rows as $tr) {
    $transit_trip_map[(int)$tr['vehicle_id']] = (int)$tr['c'];
}

$trips_sql = "SELECT id, vehicle_id, driver_id, total_weight, freight_amount, subtotal,
        $trip_period_expr AS pnl_trip_date,
        driver_advance, toll_amount, loading_charges, unloading_charges, other_expenses
    FROM fleet_trips
    WHERE status='Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'";
$trips_q = $db->query($trips_sql);
if (!$trips_q) {
    throw new Exception('Fleet Vehicle P&L trip query failed: ' . $db->error);
}
$trips = $trips_q->fetch_all(MYSQLI_ASSOC);

$driver_trip_total = [];
$driver_vehicle_trip = [];
foreach ($trips as $t) {
    $vid = (int)$t['vehicle_id'];
    $did = (int)$t['driver_id'];
    if ($vehicle_filter > 0 && $vid !== $vehicle_filter) continue;
    if (!isset($vehicle_map[$vid])) {
        $vehicle_map[$vid] = [
            'vehicle_id' => $vid,
            'reg_no' => 'Vehicle #' . $vid,
            'vehicle_name' => 'Vehicle #' . $vid,
            'live_status' => 'Idle',
            'trip_count' => 0,
            'total_weight' => 0.0,
            'revenue' => 0.0,
            'trip_inline_expenses' => 0.0,
            'fuel_expenses' => 0.0,
            'other_expenses' => 0.0,
            'fixed_overhead_expenses' => 0.0,
            'salary_alloc' => 0.0,
        ];
    }
    $revenue = (float)$t['subtotal'] > 0 ? (float)$t['subtotal'] : (float)$t['freight_amount'];
    $inline_exp = (float)$t['toll_amount']
        + (float)$t['loading_charges']
        + (float)$t['unloading_charges']
        + (float)$t['other_expenses'];

    $vehicle_map[$vid]['trip_count'] += 1;
    $vehicle_map[$vid]['total_weight'] += (float)$t['total_weight'];
    $vehicle_map[$vid]['revenue'] += $revenue;
    $vehicle_map[$vid]['trip_inline_expenses'] += $inline_exp;
    $overhead = fleetTripOverheadAmount($db, (string)($t['pnl_trip_date'] ?? $month_start), (float)$t['total_weight']);
    $vehicle_map[$vid]['fixed_overhead_expenses'] += (float)$overhead['amount'];

    if ($did > 0) {
        $driver_trip_total[$did] = ($driver_trip_total[$did] ?? 0) + 1;
        $driver_vehicle_trip[$did][$vid] = ($driver_vehicle_trip[$did][$vid] ?? 0) + 1;
    }
}

$fuel_rows = $db->query("SELECT vehicle_id, SUM(COALESCE(amount,0) + COALESCE(driver_advance,0)) AS amt
    FROM fleet_fuel_log
    WHERE fuel_date BETWEEN '$month_start' AND '$month_end'
    GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
foreach ($fuel_rows as $r) {
    $vid = (int)$r['vehicle_id'];
    if ($vehicle_filter > 0 && $vid !== $vehicle_filter) continue;
    if (isset($vehicle_map[$vid])) {
        $vehicle_map[$vid]['fuel_expenses'] += (float)$r['amt'];
    }
}

$exp_rows = $db->query("SELECT vehicle_id, SUM(amount) AS amt
    FROM fleet_expenses
    WHERE expense_date BETWEEN '$month_start' AND '$month_end'
    GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
foreach ($exp_rows as $r) {
    $vid = (int)$r['vehicle_id'];
    if ($vehicle_filter > 0 && $vid !== $vehicle_filter) continue;
    if (isset($vehicle_map[$vid])) $vehicle_map[$vid]['other_expenses'] += (float)$r['amt'];
}

$salary_rows = $db->query("SELECT driver_id, net_payable
    FROM fleet_driver_salary
    WHERE salary_month='$month'")->fetch_all(MYSQLI_ASSOC);
foreach ($salary_rows as $s) {
    $did = (int)$s['driver_id'];
    $net = (float)$s['net_payable'];
    $total_trips = (int)($driver_trip_total[$did] ?? 0);
    if ($net <= 0 || $total_trips <= 0) continue;
    foreach (($driver_vehicle_trip[$did] ?? []) as $vid => $trip_cnt) {
        if ($vehicle_filter > 0 && (int)$vid !== $vehicle_filter) continue;
        if (!isset($vehicle_map[$vid])) continue;
        $vehicle_map[$vid]['salary_alloc'] += $net * ((float)$trip_cnt / (float)$total_trips);
    }
}

$rows = [];
foreach ($vehicle_map as $vid => $row) {
    if ($vehicle_filter > 0 && $vid !== $vehicle_filter) continue;
    // Keep zero-activity vehicles visible (Idle / Breakdown / etc.) for a complete monthly P&L board.
    $total_exp = (float)$row['trip_inline_expenses'] + (float)$row['fuel_expenses'] + (float)$row['other_expenses'] + (float)$row['fixed_overhead_expenses'] + (float)$row['salary_alloc'];
    $row['monthly_expenses'] = (float)$row['other_expenses'] + (float)$row['salary_alloc'];
    $row['transit_count'] = (int)($transit_trip_map[(int)$vid] ?? 0);
    $row['total_expenses'] = $total_exp;
    $row['profit_loss'] = (float)$row['revenue'] - $total_exp;
    $rows[] = $row;
}

usort($rows, function($a, $b) { return $b['profit_loss'] <=> $a['profit_loss']; });

$tot = ['revenue'=>0,'trip_inline_expenses'=>0,'fuel_expenses'=>0,'other_expenses'=>0,'fixed_overhead_expenses'=>0,'salary_alloc'=>0,'total_expenses'=>0,'profit_loss'=>0,'trip_count'=>0,'total_weight'=>0];
foreach ($rows as $r) {
    foreach ($tot as $k => $v) $tot[$k] += (float)$r[$k];
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-graph-up-arrow me-2"></i>Fleet Vehicle P&L';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Monthly Vehicle P&amp;L</h5>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label">Month</label>
                <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Vehicle</label>
                <select name="vehicle_id" class="form-select">
                    <option value="0">All Vehicles</option>
                    <?php foreach ($vehicles as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= $vehicle_filter === (int)$v['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(($v['reg_no'] ?? '') . ' - ' . trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? ''))) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
        </form>
    </div>
</div>

<?php if (isAdmin()): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold">Vehicle Statutory Overhead Rate</div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="save_overhead_rate" value="1">
            <div class="col-12 col-md-2">
                <label class="form-label">Rate / MT</label>
                <input type="number" step="0.01" min="0" name="rate_per_mt" class="form-control" value="<?= htmlspecialchars((string)($overhead_rates[0]['rate_per_mt'] ?? '30.00')) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Effective From</label>
                <input type="date" name="effective_from" class="form-control" value="<?= htmlspecialchars((string)($overhead_rates[0]['effective_from'] ?? '2026-04-01')) ?>" required>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" maxlength="255" class="form-control" placeholder="Insurance, tax, fitness, challans etc." value="<?= htmlspecialchars((string)($overhead_rates[0]['notes'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-save me-1"></i>Save Rate</button>
            </div>
        </form>
        <?php if ($overhead_rates): ?>
        <div class="table-responsive mt-3">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Effective From</th><th class="text-end">Rate / MT</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($overhead_rates as $rate): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($rate['effective_from'])) ?></td>
                        <td class="text-end">&#8377;<?= number_format((float)$rate['rate_per_mt'], 2) ?></td>
                        <td class="text-muted"><?= htmlspecialchars((string)($rate['notes'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-2"><div class="card p-2 text-center"><small class="text-muted">Revenue</small><div class="fw-bold text-success">&#8377;<?= number_format($tot['revenue'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-2 text-center"><small class="text-muted">Total Expenses</small><div class="fw-bold text-danger">&#8377;<?= number_format($tot['total_expenses'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-2 text-center"><small class="text-muted">Statutory / MT</small><div class="fw-bold text-danger">&#8377;<?= number_format($tot['fixed_overhead_expenses'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-2 text-center"><small class="text-muted">P&amp;L</small><div class="fw-bold <?= $tot['profit_loss'] >= 0 ? 'text-success' : 'text-danger' ?>">&#8377;<?= number_format($tot['profit_loss'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-2 text-center"><small class="text-muted">Trips</small><div class="fw-bold"><?= number_format($tot['trip_count'],0) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-2 text-center"><small class="text-muted">Vehicles In Transit</small><div class="fw-bold text-primary"><?= number_format($in_transit_count,0) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-2 text-center"><small class="text-muted">Total Weight</small><div class="fw-bold"><?= number_format($tot['total_weight'],3) ?> MT</div></div></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Vehicle</th>
                    <th class="text-end">Trips</th>
                    <th class="text-end">Transit</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Trip Expenses</th>
                    <th class="text-end">Fuel</th>
                    <th class="text-end">Monthly Expenses</th>
                    <th class="text-end">Statutory / MT</th>
                    <th class="text-end">Total Expenses</th>
                    <th class="text-end">P&amp;L</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold">
                        <a href="fleet_vehicle_pnl_drilldown.php?month=<?= urlencode($month) ?>&vehicle_id=<?= (int)$r['vehicle_id'] ?>"><?= htmlspecialchars($r['reg_no']) ?></a>
                    </td>
                    <td class="text-end"><?= number_format((float)$r['trip_count'],0) ?></td>
                    <td class="text-end"><?= number_format((float)$r['transit_count'],0) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_weight'],3) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format((float)$r['revenue'],2) ?></td>
                    <td class="text-end">&#8377;<?= number_format((float)$r['trip_inline_expenses'],2) ?></td>
                    <td class="text-end">&#8377;<?= number_format((float)$r['fuel_expenses'],2) ?></td>
                    <td class="text-end">&#8377;<?= number_format((float)$r['monthly_expenses'],2) ?></td>
                    <td class="text-end">&#8377;<?= number_format((float)$r['fixed_overhead_expenses'],2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format((float)$r['total_expenses'],2) ?></td>
                    <td class="text-end fw-bold <?= (float)$r['profit_loss'] >= 0 ? 'text-success' : 'text-danger' ?>">&#8377;<?= number_format((float)$r['profit_loss'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="11" class="text-muted p-3">No data found for selected month.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

