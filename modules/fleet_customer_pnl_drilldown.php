<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_overhead_helper.php';

$db = getDB();
requirePerm('reports', 'view');

$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_trip_end_date = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' AND COLUMN_NAME='end_date' LIMIT 1")->num_rows > 0;
$trip_period_expr = $has_trip_end_date
    ? "CASE WHEN end_date IS NOT NULL AND CAST(end_date AS CHAR) <> '0000-00-00' THEN end_date ELSE trip_date END"
    : "trip_date";

$month = sanitize($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$month_start = $month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));
$customer_id = (int)($_GET['customer_id'] ?? 0);
$unassigned  = ($customer_id === -1);

if ($customer_id <= 0 && !$unassigned) {
    showAlert('danger', 'Customer required.');
    redirect('fleet_customer_pnl.php?month=' . urlencode($month));
}

$customer = null;
if ($customer_id > 0) {
    $customer = $db->query("SELECT id, vendor_code, vendor_name, status
        FROM fleet_customers_master
        WHERE id = $customer_id LIMIT 1")->fetch_assoc();
    if (!$customer) {
        showAlert('danger', 'Customer not found.');
        redirect('fleet_customer_pnl.php?month=' . urlencode($month));
    }
}
$label = $unassigned ? 'Unassigned / Other' : (string)($customer['vendor_name'] ?? 'Customer #' . $customer_id);

fleetEnsureOverheadRates($db);

// ── Step 1: Trips for this customer ──────────────────────────
$trip_cond = $customer_id > 0
    ? "AND t.vendor_id = $customer_id"
    : "AND COALESCE(t.vendor_id, 0) = 0";

// Detect optional route columns
$has_route_from = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' AND COLUMN_NAME='route_from' LIMIT 1")->num_rows > 0;
$has_route_to = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' AND COLUMN_NAME='route_to' LIMIT 1")->num_rows > 0;
$route_cols = ($has_route_from ? "t.route_from," : "NULL AS route_from,")
            . ($has_route_to   ? "t.route_to,"   : "NULL AS route_to,");

$trips_q = $db->query("SELECT
        t.id, t.trip_no, t.vehicle_id, t.driver_id,
        COALESCE(v.reg_no, CONCAT('Vehicle #', t.vehicle_id)) AS vehicle_no,
        COALESCE(t.total_weight, 0) AS total_weight,
        COALESCE(t.subtotal, 0) AS subtotal,
        COALESCE(t.freight_amount, 0) AS freight_amount,
        COALESCE(t.toll_amount, 0) AS toll_amount,
        COALESCE(t.loading_charges, 0) AS loading_charges,
        COALESCE(t.unloading_charges, 0) AS unloading_charges,
        COALESCE(t.other_expenses, 0) AS other_expenses,
        $route_cols
        $trip_period_expr AS pnl_date
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id = v.id
    WHERE t.status = 'Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'
      $trip_cond
    ORDER BY pnl_date, t.id");
if (!$trips_q) throw new Exception('Customer P&L drilldown query failed: ' . $db->error);
$trips = $trips_q->fetch_all(MYSQLI_ASSOC);

// ── Step 2: Per-vehicle trip totals for allocation ratio ──────
// For each vehicle this customer used, how many total trips did that vehicle do?
$vehicle_ids = array_unique(array_column($trips, 'vehicle_id'));
$veh_total_trips = [];
$veh_cust_trips  = [];
if ($vehicle_ids) {
    $vid_in = implode(',', array_map('intval', $vehicle_ids));
    $veh_count_rows = $db->query("SELECT vehicle_id,
            SUM(CASE WHEN COALESCE(vendor_id,0) = " . ($customer_id > 0 ? $customer_id : 0) . " THEN 1 ELSE 0 END) AS cust_trips,
            COUNT(*) AS total_trips
        FROM fleet_trips
        WHERE status='Completed'
          AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'
          AND vehicle_id IN ($vid_in)
        GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
    foreach ($veh_count_rows as $r) {
        $vid = (int)$r['vehicle_id'];
        $veh_total_trips[$vid] = (int)$r['total_trips'];
        $veh_cust_trips[$vid]  = (int)$r['cust_trips'];
    }
}

// ── Step 3: Vehicle-level costs ───────────────────────────────
$fuel_map = [];
if ($vehicle_ids) {
    $vid_in = implode(',', array_map('intval', $vehicle_ids));
    $fuel_rows = $db->query("SELECT vehicle_id, SUM(COALESCE(amount,0)+COALESCE(driver_advance,0)) AS amt
        FROM fleet_fuel_log
        WHERE fuel_date BETWEEN '$month_start' AND '$month_end'
          AND vehicle_id IN ($vid_in)
        GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
    foreach ($fuel_rows as $r) $fuel_map[(int)$r['vehicle_id']] = (float)$r['amt'];

    $exp_rows = $db->query("SELECT vehicle_id, SUM(amount) AS amt
        FROM fleet_expenses
        WHERE expense_date BETWEEN '$month_start' AND '$month_end'
          AND vehicle_id IN ($vid_in)
        GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
    $vexp_map = [];
    foreach ($exp_rows as $r) $vexp_map[(int)$r['vehicle_id']] = (float)$r['amt'];
}

// ── Step 4: Driver salary allocation per vehicle ──────────────
$driver_ids = array_unique(array_filter(array_column($trips, 'driver_id'), fn($d) => (int)$d > 0));
$salary_veh_map = [];
if ($driver_ids && $vehicle_ids) {
    $did_in = implode(',', array_map('intval', $driver_ids));
    $salary_rows = $db->query("SELECT driver_id, net_payable
        FROM fleet_driver_salary
        WHERE salary_month='$month'
          AND driver_id IN ($did_in)")->fetch_all(MYSQLI_ASSOC);

    // Driver trip distribution across vehicles (full month, not just this customer)
    $vid_in2 = implode(',', array_map('intval', $vehicle_ids));
    $dv_rows = $db->query("SELECT driver_id, vehicle_id, COUNT(*) AS c
        FROM fleet_trips
        WHERE status='Completed'
          AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'
          AND driver_id IN ($did_in)
        GROUP BY driver_id, vehicle_id")->fetch_all(MYSQLI_ASSOC);
    $driver_total   = [];
    $driver_veh_cnt = [];
    foreach ($dv_rows as $r) {
        $did = (int)$r['driver_id'];
        $vid = (int)$r['vehicle_id'];
        $driver_total[$did]           = ($driver_total[$did] ?? 0) + (int)$r['c'];
        $driver_veh_cnt[$did][$vid]   = (int)$r['c'];
    }
    foreach ($salary_rows as $s) {
        $did = (int)$s['driver_id'];
        $net = (float)$s['net_payable'];
        $tot = (int)($driver_total[$did] ?? 0);
        if ($net <= 0 || $tot <= 0) continue;
        foreach (($driver_veh_cnt[$did] ?? []) as $vid => $cnt) {
            $salary_veh_map[$vid] = ($salary_veh_map[$vid] ?? 0.0) + $net * ((float)$cnt / (float)$tot);
        }
    }
}

// ── Step 5: Per-trip cost allocation ──────────────────────────
// Fuel, salary, veh_exp allocated to this customer per vehicle =
//   vehicle_total_cost * (customer_trips_on_vehicle / vehicle_total_trips)
// Then split equally among the trips this customer has on that vehicle.
$vehicle_cust_trips_count = []; // [vid] => count of customer trips on that vehicle
foreach ($trips as $t) {
    $vid = (int)$t['vehicle_id'];
    $vehicle_cust_trips_count[$vid] = ($vehicle_cust_trips_count[$vid] ?? 0) + 1;
}

// Per vehicle: customer's share of vehicle-level costs
$veh_cust_fuel_share    = [];
$veh_cust_salary_share  = [];
$veh_cust_vexp_share    = [];
foreach ($vehicle_cust_trips_count as $vid => $cust_cnt) {
    $veh_total = (int)($veh_total_trips[$vid] ?? 0);
    $ratio = $veh_total > 0 ? (float)$cust_cnt / (float)$veh_total : 0;
    $veh_cust_fuel_share[$vid]   = (float)($fuel_map[$vid]    ?? 0) * $ratio;
    $veh_cust_salary_share[$vid] = (float)($salary_veh_map[$vid] ?? 0) * $ratio;
    $veh_cust_vexp_share[$vid]   = (float)($vexp_map[$vid]    ?? 0) * $ratio;
}

// Now distribute vehicle's customer-share equally among that customer's trips on the vehicle
$enriched = [];
$summary = [
    'trip_count'       => 0,
    'total_weight'     => 0.0,
    'revenue'          => 0.0,
    'trip_expenses'    => 0.0,
    'fuel_alloc'       => 0.0,
    'salary_alloc'     => 0.0,
    'veh_exp_alloc'    => 0.0,
    'overhead_alloc'   => 0.0,
    'total_expenses'   => 0.0,
    'profit'           => 0.0,
];
foreach ($trips as $t) {
    $vid       = (int)$t['vehicle_id'];
    $cust_cnt  = (int)($vehicle_cust_trips_count[$vid] ?? 1);
    $revenue   = (float)$t['subtotal'] > 0 ? (float)$t['subtotal'] : (float)$t['freight_amount'];
    $inline    = (float)$t['toll_amount'] + (float)$t['loading_charges']
               + (float)$t['unloading_charges'] + (float)$t['other_expenses'];
    $overhead  = fleetTripOverheadAmount($db, (string)($t['pnl_date'] ?? $month_start), (float)$t['total_weight']);
    $fuel_per_trip   = $cust_cnt > 0 ? (float)($veh_cust_fuel_share[$vid]   ?? 0) / $cust_cnt : 0;
    $salary_per_trip = $cust_cnt > 0 ? (float)($veh_cust_salary_share[$vid] ?? 0) / $cust_cnt : 0;
    $vexp_per_trip   = $cust_cnt > 0 ? (float)($veh_cust_vexp_share[$vid]   ?? 0) / $cust_cnt : 0;
    $total_exp = $inline + $fuel_per_trip + $salary_per_trip + $vexp_per_trip + (float)$overhead['amount'];
    $profit    = $revenue - $total_exp;
    $margin    = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

    $enriched[] = array_merge($t, [
        'revenue'          => $revenue,
        'trip_expenses'    => $inline,
        'fuel_alloc'       => $fuel_per_trip,
        'salary_alloc'     => $salary_per_trip,
        'veh_exp_alloc'    => $vexp_per_trip,
        'overhead_alloc'   => (float)$overhead['amount'],
        'total_expenses'   => $total_exp,
        'profit'           => $profit,
        'margin'           => $margin,
    ]);

    $summary['trip_count']     += 1;
    $summary['total_weight']   += (float)$t['total_weight'];
    $summary['revenue']        += $revenue;
    $summary['trip_expenses']  += $inline;
    $summary['fuel_alloc']     += $fuel_per_trip;
    $summary['salary_alloc']   += $salary_per_trip;
    $summary['veh_exp_alloc']  += $vexp_per_trip;
    $summary['overhead_alloc'] += (float)$overhead['amount'];
    $summary['total_expenses'] += $total_exp;
    $summary['profit']         += $profit;
}
$summary['margin'] = $summary['revenue'] > 0
    ? ($summary['profit'] / $summary['revenue']) * 100 : 0;

// ── Chart data: daily profit trend ────────────────────────────
$daily = [];
foreach ($enriched as $t) {
    $d = (string)($t['pnl_date'] ?? '');
    if (!$d) continue;
    $daily[$d]['revenue']  = ($daily[$d]['revenue'] ?? 0) + $t['revenue'];
    $daily[$d]['expenses'] = ($daily[$d]['expenses'] ?? 0) + $t['total_expenses'];
    $daily[$d]['profit']   = ($daily[$d]['profit'] ?? 0) + $t['profit'];
}
ksort($daily);
$chart_days     = array_map(fn($d) => date('d/m', strtotime($d)), array_keys($daily));
$chart_rev      = array_column(array_values($daily), 'revenue');
$chart_exp      = array_column(array_values($daily), 'expenses');
$chart_profit   = array_column(array_values($daily), 'profit');

include '../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-person-lines-fill me-2"></i>Customer P&amp;L Detail';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">
        P&amp;L Drill-down: <?= htmlspecialchars($label) ?>
        <small class="text-muted fw-normal"><?= htmlspecialchars($month) ?></small>
    </h5>
    <a href="fleet_customer_pnl.php?month=<?= urlencode($month) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<!-- KPI Cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Revenue</small>
            <div class="fw-bold text-success">&#8377;<?= number_format($summary['revenue'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Total Expenses</small>
            <div class="fw-bold text-danger">&#8377;<?= number_format($summary['total_expenses'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Net Profit</small>
            <div class="fw-bold <?= $summary['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                &#8377;<?= number_format($summary['profit'], 2) ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Margin</small>
            <div class="fw-bold <?= $summary['margin'] >= 10 ? 'text-success' : ($summary['margin'] >= 0 ? 'text-warning' : 'text-danger') ?>">
                <?= number_format($summary['margin'], 1) ?>%
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Trips</small>
            <div class="fw-bold"><?= number_format($summary['trip_count'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Avg/Trip</small>
            <div class="fw-bold <?= ($summary['profit'] / max(1, $summary['trip_count'])) >= 0 ? 'text-success' : 'text-danger' ?>">
                &#8377;<?= number_format($summary['trip_count'] > 0 ? $summary['profit'] / $summary['trip_count'] : 0, 2) ?>
            </div>
        </div>
    </div>
</div>

<!-- Expense breakdown -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #dc3545">
            <small class="text-muted">Trip Expenses (Direct)</small>
            <div class="fw-semibold text-danger">&#8377;<?= number_format($summary['trip_expenses'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #fd7e14">
            <small class="text-muted">Fuel (Allocated)</small>
            <div class="fw-semibold" style="color:#fd7e14">&#8377;<?= number_format($summary['fuel_alloc'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #6c757d">
            <small class="text-muted">Salary + Veh. Exp.</small>
            <div class="fw-semibold text-secondary">&#8377;<?= number_format($summary['salary_alloc'] + $summary['veh_exp_alloc'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #6f42c1">
            <small class="text-muted">Statutory Overhead</small>
            <div class="fw-semibold" style="color:#6f42c1">&#8377;<?= number_format($summary['overhead_alloc'], 2) ?></div>
        </div>
    </div>
</div>

<!-- Daily Trend Chart -->
<?php if (count($daily) > 1): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold">
        <i class="bi bi-graph-up me-2 text-primary"></i>Daily Revenue &amp; Profit Trend
    </div>
    <div class="card-body">
        <div style="position:relative;height:240px">
            <canvas id="dailyTrendChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Per-trip table -->
<div class="card mb-3">
    <div class="card-header fw-semibold">
        <i class="bi bi-table me-2 text-primary"></i>Trip-level P&amp;L
        <small class="text-muted fw-normal ms-2">Vehicle-level costs split equally across this customer's trips on each vehicle</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle table-hover">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Trip</th>
                    <th>Vehicle</th>
                    <th class="text-end">Wt (MT)</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end" title="Toll + Loading + Unloading + other">Trip Exp.</th>
                    <th class="text-end" title="Fuel allocated for this trip">Fuel</th>
                    <th class="text-end" title="Salary + vehicle exp. allocated">Sal.+Veh.</th>
                    <th class="text-end" title="Statutory overhead per MT">Overhead</th>
                    <th class="text-end">Total Exp.</th>
                    <th class="text-end fw-bold">Profit</th>
                    <th class="text-end">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enriched as $t): ?>
                <?php
                    $profit_class = (float)$t['profit'] >= 0 ? 'text-success' : 'text-danger';
                    $margin_class = (float)$t['margin'] >= 20 ? 'text-success'
                        : ((float)$t['margin'] >= 0 ? 'text-warning' : 'text-danger');
                    $route = trim(($t['route_from'] ?? '') . ' → ' . ($t['route_to'] ?? ''));
                ?>
                <tr>
                    <td><?= !empty($t['pnl_date']) ? date('d/m/Y', strtotime($t['pnl_date'])) : '-' ?></td>
                    <td>
<a href="fleet_trips.php?action=view&id=<?= (int)$t['id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_customer_pnl_drilldown.php') ?>">
                            <?= htmlspecialchars($t['trip_no']) ?>
                        </a>
                        <?php if ($route !== ' → ' && $route !== ''): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($route) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($t['vehicle_no']) ?></td>
                    <td class="text-end"><?= number_format((float)$t['total_weight'], 3) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format((float)$t['revenue'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format((float)$t['trip_expenses'], 2) ?></td>
                    <td class="text-end" style="color:#fd7e14">&#8377;<?= number_format((float)$t['fuel_alloc'], 2) ?></td>
                    <td class="text-end text-secondary">&#8377;<?= number_format((float)$t['salary_alloc'] + (float)$t['veh_exp_alloc'], 2) ?></td>
                    <td class="text-end" style="color:#6f42c1">&#8377;<?= number_format((float)$t['overhead_alloc'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format((float)$t['total_expenses'], 2) ?></td>
                    <td class="text-end fw-bold <?= $profit_class ?>">&#8377;<?= number_format((float)$t['profit'], 2) ?></td>
                    <td class="text-end fw-semibold <?= $margin_class ?>">
                        <?= number_format((float)$t['margin'], 1) ?>%
                        <?php if ((float)$t['margin'] < 0): ?>
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$enriched): ?>
                <tr>
                    <td colspan="12" class="text-muted p-3">No completed trips found for this customer.</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <?php if ($enriched): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">Total</td>
                    <td class="text-end"><?= number_format($summary['total_weight'], 3) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format($summary['revenue'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format($summary['trip_expenses'], 2) ?></td>
                    <td class="text-end" style="color:#fd7e14">&#8377;<?= number_format($summary['fuel_alloc'], 2) ?></td>
                    <td class="text-end text-secondary">&#8377;<?= number_format($summary['salary_alloc'] + $summary['veh_exp_alloc'], 2) ?></td>
                    <td class="text-end" style="color:#6f42c1">&#8377;<?= number_format($summary['overhead_alloc'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format($summary['total_expenses'], 2) ?></td>
                    <td class="text-end <?= $summary['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">&#8377;<?= number_format($summary['profit'], 2) ?></td>
                    <td class="text-end <?= $summary['margin'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($summary['margin'], 1) ?>%</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Insights for this customer -->
<?php if ($enriched): ?>
<?php
$loss_trips    = array_filter($enriched, fn($t) => (float)$t['profit'] < 0);
$low_wt_trips  = array_filter($enriched, fn($t) => (float)$t['total_weight'] < 5 && $t['total_weight'] > 0);
$high_toll     = array_filter($enriched, fn($t) => (float)$t['toll_amount'] > 0.15 * (float)$t['revenue'] && (float)$t['revenue'] > 0);
$best_trip     = count($enriched) ? array_reduce($enriched, fn($carry, $t) => ((float)$t['margin'] > (float)($carry['margin'] ?? -PHP_INT_MAX) ? $t : $carry), $enriched[0]) : null;
$worst_trip    = count($enriched) ? array_reduce($enriched, fn($carry, $t) => ((float)$t['margin'] < (float)($carry['margin'] ?? PHP_INT_MAX) ? $t : $carry), $enriched[0]) : null;
?>
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-lightbulb-fill me-2 text-warning"></i>Trip-level Insights</div>
    <div class="card-body">
        <?php if ($best_trip && $worst_trip && $best_trip['id'] !== $worst_trip['id']): ?>
        <div class="alert alert-light border mb-2 py-2">
            <strong><i class="bi bi-trophy-fill text-success me-1"></i>Best trip:</strong>
            <?= htmlspecialchars($best_trip['trip_no']) ?> on <?= date('d/m/Y', strtotime($best_trip['pnl_date'])) ?>
            — &#8377;<?= number_format($best_trip['profit'], 2) ?> profit (<?= number_format($best_trip['margin'], 1) ?>% margin).
            &nbsp;&nbsp;
            <strong><i class="bi bi-arrow-down-circle-fill text-danger me-1"></i>Worst trip:</strong>
            <?= htmlspecialchars($worst_trip['trip_no']) ?>
            — &#8377;<?= number_format($worst_trip['profit'], 2) ?> (<?= number_format($worst_trip['margin'], 1) ?>% margin).
        </div>
        <?php endif; ?>

        <?php if ($loss_trips): ?>
        <div class="alert alert-danger mb-2 py-2">
            <strong><i class="bi bi-exclamation-triangle-fill me-1"></i><?= count($loss_trips) ?> loss-making trip(s):</strong>
            <?= implode(', ', array_map(fn($t) => htmlspecialchars($t['trip_no']) . ' (₹' . number_format($t['profit'], 0) . ')', $loss_trips)) ?>.
            Check if freight rate covers actual cost per MT on these trips.
        </div>
        <?php endif; ?>

        <?php if ($low_wt_trips): ?>
        <div class="alert alert-warning mb-2 py-2">
            <strong><i class="bi bi-boxes me-1"></i>Under-loaded trips:</strong>
            <?= implode(', ', array_map(fn($t) => htmlspecialchars($t['trip_no']) . ' (' . number_format($t['total_weight'], 2) . ' MT)', $low_wt_trips)) ?>.
            Maximising load weight will reduce cost-per-MT and improve margins.
        </div>
        <?php endif; ?>

        <?php if ($high_toll): ?>
        <div class="alert alert-warning mb-2 py-2">
            <strong><i class="bi bi-signpost-2 me-1"></i>High toll routes:</strong>
            <?= implode(', ', array_map(fn($t) => htmlspecialchars($t['trip_no']) . ' (toll is ' . number_format(($t['toll_amount'] / $t['revenue']) * 100, 1) . '% of revenue)', $high_toll)) ?>.
            Review if an alternate route or toll reimbursement clause can be added to the rate contract.
        </div>
        <?php endif; ?>

        <?php
        $avg_revenue_per_mt = $summary['total_weight'] > 0 ? $summary['revenue'] / $summary['total_weight'] : 0;
        $avg_exp_per_mt     = $summary['total_weight'] > 0 ? $summary['total_expenses'] / $summary['total_weight'] : 0;
        ?>
        <div class="alert alert-light border mb-0 py-2">
            <strong><i class="bi bi-calculator me-1"></i>Efficiency summary:</strong>
            Revenue/MT = &#8377;<?= number_format($avg_revenue_per_mt, 2) ?> &nbsp;|&nbsp;
            Cost/MT = &#8377;<?= number_format($avg_exp_per_mt, 2) ?> &nbsp;|&nbsp;
            Contribution/MT = &#8377;<?= number_format($avg_revenue_per_mt - $avg_exp_per_mt, 2) ?>.
            <?php if ($avg_revenue_per_mt > 0 && $avg_exp_per_mt > $avg_revenue_per_mt * 0.85): ?>
                <span class="text-danger fw-semibold"> Cost is &gt;85% of revenue — operating margins are thin. Consider rate revision.</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (count($daily) > 1): ?>
<script>
(function() {
    const labels   = <?= json_encode($chart_days) ?>;
    const revenue  = <?= json_encode(array_map(fn($v) => round($v, 2), $chart_rev)) ?>;
    const expenses = <?= json_encode(array_map(fn($v) => round($v, 2), $chart_exp)) ?>;
    const profit   = <?= json_encode(array_map(fn($v) => round($v, 2), $chart_profit)) ?>;

    const ctx = document.getElementById('dailyTrendChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: revenue,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.08)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 4,
                },
                {
                    label: 'Expenses',
                    data: expenses,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.06)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 4,
                },
                {
                    label: 'Net Profit',
                    data: profit,
                    borderColor: '#0d6efd',
                    backgroundColor: 'transparent',
                    borderDash: [5, 3],
                    tension: 0.3,
                    pointRadius: 4,
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₹' + ctx.parsed.y.toLocaleString('en-IN', {minimumFractionDigits: 2})
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: v => '₹' + (v/1000).toFixed(0) + 'k' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
