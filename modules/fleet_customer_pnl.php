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
$customer_filter = (int)($_GET['customer_id'] ?? 0);

fleetEnsureOverheadRates($db);

// ── Customer list for filter dropdown ─────────────────────────
$customer_rows = $db->query("SELECT id, vendor_code, vendor_name, status
    FROM fleet_customers_master
    ORDER BY status ASC, vendor_name ASC")->fetch_all(MYSQLI_ASSOC);

// ── Step 1: Pull all completed trips for the month ─────────────
$trips_sql = "SELECT
        t.id,
        t.vehicle_id,
        t.driver_id,
        COALESCE(t.vendor_id, 0) AS customer_id,
        COALESCE(cm.vendor_name, NULLIF(TRIM(t.customer_name),''), 'Unassigned') AS customer_name,
        COALESCE(cm.vendor_code, '') AS customer_code,
        COALESCE(cm.status, CASE WHEN COALESCE(t.vendor_id,0)=0 THEN 'Unassigned' ELSE 'Unknown' END) AS customer_status,
        COALESCE(t.total_weight, 0) AS total_weight,
        COALESCE(t.subtotal, 0) AS subtotal,
        COALESCE(t.freight_amount, 0) AS freight_amount,
        COALESCE(t.toll_amount, 0) AS toll_amount,
        COALESCE(t.loading_charges, 0) AS loading_charges,
        COALESCE(t.unloading_charges, 0) AS unloading_charges,
        COALESCE(t.other_expenses, 0) AS other_expenses,
        $trip_period_expr AS pnl_date
    FROM fleet_trips t
    LEFT JOIN fleet_customers_master cm ON t.vendor_id = cm.id
    WHERE t.status = 'Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'";

if ($customer_filter > 0) {
    $trips_sql .= " AND t.vendor_id = $customer_filter";
} elseif ($customer_filter === -1) {
    $trips_sql .= " AND COALESCE(t.vendor_id, 0) = 0";
}

$all_trips = $db->query($trips_sql)->fetch_all(MYSQLI_ASSOC);

// ── Step 2: Per-vehicle trip counts (for allocation ratios) ────
// We need ALL trips on each vehicle in the month (not just filtered customer)
// so ratios are correct.
$all_veh_trips_sql = "SELECT
        vehicle_id,
        COALESCE(vendor_id, 0) AS customer_id,
        COUNT(*) AS trip_count
    FROM fleet_trips
    WHERE status = 'Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'
    GROUP BY vehicle_id, customer_id";
$veh_trip_rows = $db->query($all_veh_trips_sql)->fetch_all(MYSQLI_ASSOC);

// $veh_total_trips[vehicle_id] = total completed trips that vehicle did
// $veh_cust_trips[vehicle_id][customer_id] = trips that vehicle did for customer
$veh_total_trips = [];
$veh_cust_trips  = [];
foreach ($veh_trip_rows as $r) {
    $vid = (int)$r['vehicle_id'];
    $cid = (int)$r['customer_id'];
    $cnt = (int)$r['trip_count'];
    $veh_total_trips[$vid] = ($veh_total_trips[$vid] ?? 0) + $cnt;
    $veh_cust_trips[$vid][$cid] = $cnt;
}

// ── Step 3: Vehicle-level expenses ────────────────────────────
// Fuel log
$fuel_map = []; // [vehicle_id] => amount
$fuel_rows = $db->query("SELECT vehicle_id, SUM(COALESCE(amount,0) + COALESCE(driver_advance,0)) AS amt
    FROM fleet_fuel_log
    WHERE fuel_date BETWEEN '$month_start' AND '$month_end'
    GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
foreach ($fuel_rows as $r) {
    $fuel_map[(int)$r['vehicle_id']] = (float)$r['amt'];
}

// Fleet expenses (vehicle-level: insurance, maintenance, challan etc.)
$vexp_map = []; // [vehicle_id] => amount
$exp_rows = $db->query("SELECT vehicle_id, SUM(amount) AS amt
    FROM fleet_expenses
    WHERE expense_date BETWEEN '$month_start' AND '$month_end'
    GROUP BY vehicle_id")->fetch_all(MYSQLI_ASSOC);
foreach ($exp_rows as $r) {
    $vexp_map[(int)$r['vehicle_id']] = (float)$r['amt'];
}

// Driver salary — allocate proportionally to vehicles by trip share
$salary_rows = $db->query("SELECT driver_id, net_payable
    FROM fleet_driver_salary
    WHERE salary_month = '$month'")->fetch_all(MYSQLI_ASSOC);

// Build driver→vehicle trip map from the full (unfiltered) set
$driver_total_trips   = [];
$driver_vehicle_trips = [];
$all_trips_unfiltered_sql = "SELECT vehicle_id, driver_id
    FROM fleet_trips
    WHERE status='Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'
      AND driver_id > 0";
$dt_rows = $db->query($all_trips_unfiltered_sql)->fetch_all(MYSQLI_ASSOC);
foreach ($dt_rows as $r) {
    $vid = (int)$r['vehicle_id'];
    $did = (int)$r['driver_id'];
    $driver_total_trips[$did]           = ($driver_total_trips[$did] ?? 0) + 1;
    $driver_vehicle_trips[$did][$vid]   = ($driver_vehicle_trips[$did][$vid] ?? 0) + 1;
}

// salary_veh_map[vehicle_id] => allocated salary for that vehicle this month
$salary_veh_map = [];
foreach ($salary_rows as $s) {
    $did = (int)$s['driver_id'];
    $net = (float)$s['net_payable'];
    $total = (int)($driver_total_trips[$did] ?? 0);
    if ($net <= 0 || $total <= 0) continue;
    foreach (($driver_vehicle_trips[$did] ?? []) as $vid => $trip_cnt) {
        $salary_veh_map[$vid] = ($salary_veh_map[$vid] ?? 0.0) + $net * ((float)$trip_cnt / (float)$total);
    }
}

// ── Step 4: Aggregate by customer ─────────────────────────────
$customer_map = []; // [customer_id] => []

foreach ($all_trips as $t) {
    $cid = (int)$t['customer_id'];
    $vid = (int)$t['vehicle_id'];

    if (!isset($customer_map[$cid])) {
        $customer_map[$cid] = [
            'customer_id'      => $cid,
            'customer_name'    => $t['customer_name'],
            'customer_code'    => $t['customer_code'],
            'customer_status'  => $t['customer_status'],
            'trip_count'       => 0,
            'total_weight'     => 0.0,
            'revenue'          => 0.0,
            'trip_expenses'    => 0.0, // inline: toll + loading + unloading + other_exp on trip
            'fuel_alloc'       => 0.0,
            'veh_exp_alloc'    => 0.0,
            'salary_alloc'     => 0.0,
            'overhead_alloc'   => 0.0,
            '_vehicles'        => [],   // track which vehicles served this customer
        ];
    }

    $revenue = (float)$t['subtotal'] > 0 ? (float)$t['subtotal'] : (float)$t['freight_amount'];
    $inline  = (float)$t['toll_amount'] + (float)$t['loading_charges']
             + (float)$t['unloading_charges'] + (float)$t['other_expenses'];
    $overhead = fleetTripOverheadAmount($db, (string)($t['pnl_date'] ?? $month_start), (float)$t['total_weight']);

    $customer_map[$cid]['trip_count']   += 1;
    $customer_map[$cid]['total_weight'] += (float)$t['total_weight'];
    $customer_map[$cid]['revenue']      += $revenue;
    $customer_map[$cid]['trip_expenses']+= $inline;
    $customer_map[$cid]['overhead_alloc']+= (float)$overhead['amount'];

    // Track this vehicle so we can allocate vehicle-level expenses once per vehicle
    if (!isset($customer_map[$cid]['_vehicles'][$vid])) {
        $customer_map[$cid]['_vehicles'][$vid] = true;
    }
}

// ── Allocate vehicle-level costs by customer's trip share per vehicle ──
foreach ($customer_map as $cid => &$cdata) {
    foreach (array_keys($cdata['_vehicles']) as $vid) {
        $veh_total = (int)($veh_total_trips[$vid] ?? 0);
        $cust_on_veh = (int)($veh_cust_trips[$vid][$cid] ?? 0);
        if ($veh_total <= 0 || $cust_on_veh <= 0) continue;
        $ratio = (float)$cust_on_veh / (float)$veh_total;

        $cdata['fuel_alloc']    += (float)($fuel_map[$vid] ?? 0) * $ratio;
        $cdata['veh_exp_alloc'] += (float)($vexp_map[$vid] ?? 0) * $ratio;
        $cdata['salary_alloc']  += (float)($salary_veh_map[$vid] ?? 0) * $ratio;
    }
    unset($cdata['_vehicles']); // clean up temp key
}
unset($cdata);

// ── Compute derived fields and sort ───────────────────────────
$rows = [];
foreach ($customer_map as $cid => $cdata) {
    $total_exp = $cdata['trip_expenses'] + $cdata['fuel_alloc']
               + $cdata['veh_exp_alloc'] + $cdata['salary_alloc']
               + $cdata['overhead_alloc'];
    $profit    = $cdata['revenue'] - $total_exp;
    $margin    = $cdata['revenue'] > 0 ? ($profit / $cdata['revenue']) * 100 : 0;
    $rows[] = array_merge($cdata, [
        'total_expenses' => $total_exp,
        'profit'         => $profit,
        'margin'         => $margin,
    ]);
}
usort($rows, fn($a, $b) => $b['profit'] <=> $a['profit']);

// ── Totals ─────────────────────────────────────────────────────
$tot = [
    'trip_count'    => 0,
    'total_weight'  => 0.0,
    'revenue'       => 0.0,
    'trip_expenses' => 0.0,
    'fuel_alloc'    => 0.0,
    'veh_exp_alloc' => 0.0,
    'salary_alloc'  => 0.0,
    'overhead_alloc'=> 0.0,
    'total_expenses'=> 0.0,
    'profit'        => 0.0,
];
foreach ($rows as $r) {
    foreach ($tot as $k => $_) $tot[$k] += (float)$r[$k];
}
$tot_margin = $tot['revenue'] > 0 ? ($tot['profit'] / $tot['revenue']) * 100 : 0;

// ── Chart data (top 10 customers by revenue) ──────────────────
$chart_rows = array_slice($rows, 0, 10);
$chart_labels   = [];
$chart_revenue  = [];
$chart_expenses = [];
$chart_profit   = [];
foreach ($chart_rows as $r) {
    $label = mb_strlen($r['customer_name']) > 18
        ? mb_substr($r['customer_name'], 0, 16) . '…'
        : $r['customer_name'];
    $chart_labels[]   = $label;
    $chart_revenue[]  = round($r['revenue'], 2);
    $chart_expenses[] = round($r['total_expenses'], 2);
    $chart_profit[]   = round($r['profit'], 2);
}

include '../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-graph-up-arrow me-2"></i>Customer P&amp;L';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Customer Profitability &amp; P&amp;L</h5>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label">Month</label>
                <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="0">All Customers</option>
                    <option value="-1" <?= $customer_filter === -1 ? 'selected' : '' ?>>Unassigned / Other</option>
                    <?php foreach ($customer_rows as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $customer_filter === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['vendor_name'] . ' (' . $c['vendor_code'] . ')') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
            <div class="col-12 col-md-3 text-muted small">
                Vehicle-level costs are split by each customer's proportional trip share per vehicle.
            </div>
        </form>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Revenue</small>
            <div class="fw-bold text-success">&#8377;<?= number_format($tot['revenue'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Total Expenses</small>
            <div class="fw-bold text-danger">&#8377;<?= number_format($tot['total_expenses'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Net Profit</small>
            <div class="fw-bold <?= $tot['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                &#8377;<?= number_format($tot['profit'], 2) ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Avg Margin</small>
            <div class="fw-bold <?= $tot_margin >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= number_format($tot_margin, 1) ?>%
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Trips</small>
            <div class="fw-bold"><?= number_format($tot['trip_count'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Customers</small>
            <div class="fw-bold"><?= count($rows) ?></div>
        </div>
    </div>
</div>

<!-- Expense breakdown summary cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #dc3545">
            <small class="text-muted">Trip Expenses (Direct)</small>
            <div class="fw-semibold text-danger">&#8377;<?= number_format($tot['trip_expenses'], 2) ?></div>
            <small class="text-muted" style="font-size:.7rem">Toll · Loading · Unloading</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #fd7e14">
            <small class="text-muted">Fuel (Allocated)</small>
            <div class="fw-semibold text-warning">&#8377;<?= number_format($tot['fuel_alloc'], 2) ?></div>
            <small class="text-muted" style="font-size:.7rem">By trip share per vehicle</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #6c757d">
            <small class="text-muted">Salary + Veh. Exp.</small>
            <div class="fw-semibold text-secondary">&#8377;<?= number_format($tot['salary_alloc'] + $tot['veh_exp_alloc'], 2) ?></div>
            <small class="text-muted" style="font-size:.7rem">Allocated by trip share</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100" style="border-left:3px solid #6f42c1">
            <small class="text-muted">Statutory / MT</small>
            <div class="fw-semibold" style="color:#6f42c1">&#8377;<?= number_format($tot['overhead_alloc'], 2) ?></div>
            <small class="text-muted" style="font-size:.7rem">Insurance · Tax · Fitness</small>
        </div>
    </div>
</div>

<!-- Chart -->
<?php if (count($chart_rows) > 0): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Revenue vs Expenses vs Profit</span>
        <small class="text-muted">Top <?= count($chart_rows) ?> customers by revenue</small>
    </div>
    <div class="card-body">
        <div style="position:relative;height:320px">
            <canvas id="customerPnlChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Table -->
<div class="card">
    <div class="card-header fw-semibold">
        <i class="bi bi-table me-2 text-primary"></i>Customer-wise P&amp;L Breakdown
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle table-hover">
            <thead class="table-light">
                <tr>
                    <th>Customer</th>
                    <th class="text-end">Trips</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end" title="Toll + Loading + Unloading + Trip other expenses">Trip Exp.</th>
                    <th class="text-end" title="Fuel allocated by trip share on each vehicle">Fuel</th>
                    <th class="text-end" title="Salary + vehicle expenses allocated by trip share">Salary+Veh.</th>
                    <th class="text-end" title="Statutory overhead per MT (insurance, tax etc.)">Overhead</th>
                    <th class="text-end">Total Exp.</th>
                    <th class="text-end fw-bold">Net Profit</th>
                    <th class="text-end">Margin</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php
                    $margin = (float)$row['margin'];
                    $profit = (float)$row['profit'];
                    $margin_class = $margin >= 20 ? 'text-success' : ($margin >= 0 ? 'text-warning' : 'text-danger');
                    $profit_class = $profit >= 0 ? 'text-success' : 'text-danger';
                    $status_class = $row['customer_status'] === 'Active' ? 'success'
                        : ($row['customer_status'] === 'Inactive' ? 'secondary' : 'warning');
                    $cid = (int)$row['customer_id'];
                    $drilldown_url = 'fleet_customer_pnl_drilldown.php?month=' . urlencode($month)
                        . '&customer_id=' . ($cid > 0 ? $cid : -1);
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($row['customer_name']) ?></div>
                        <?php if (!empty($row['customer_code'])): ?>
                            <small class="text-muted"><?= htmlspecialchars($row['customer_code']) ?></small>
                        <?php endif; ?>
                        <span class="badge bg-<?= $status_class ?> ms-1" style="font-size:.65rem">
                            <?= htmlspecialchars($row['customer_status'] ?: 'Unknown') ?>
                        </span>
                    </td>
                    <td class="text-end"><?= number_format((int)$row['trip_count'], 0) ?></td>
                    <td class="text-end"><?= number_format((float)$row['total_weight'], 3) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format((float)$row['revenue'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format((float)$row['trip_expenses'], 2) ?></td>
                    <td class="text-end" style="color:#fd7e14">&#8377;<?= number_format((float)$row['fuel_alloc'], 2) ?></td>
                    <td class="text-end text-secondary">&#8377;<?= number_format((float)($row['salary_alloc'] + $row['veh_exp_alloc']), 2) ?></td>
                    <td class="text-end" style="color:#6f42c1">&#8377;<?= number_format((float)$row['overhead_alloc'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format((float)$row['total_expenses'], 2) ?></td>
                    <td class="text-end fw-bold <?= $profit_class ?>">&#8377;<?= number_format($profit, 2) ?></td>
                    <td class="text-end fw-semibold <?= $margin_class ?>">
                        <?= number_format($margin, 1) ?>%
                        <?php if ($margin < 0): ?>
                            <i class="bi bi-exclamation-triangle-fill" title="Loss-making customer"></i>
                        <?php elseif ($margin < 10): ?>
                            <i class="bi bi-exclamation-circle text-warning" title="Low margin"></i>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="<?= htmlspecialchars($drilldown_url) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-zoom-in me-1"></i>Analyse
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr>
                    <td colspan="12" class="text-muted p-3">No completed trips found for the selected period.</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td>Total</td>
                    <td class="text-end"><?= number_format($tot['trip_count'], 0) ?></td>
                    <td class="text-end"><?= number_format($tot['total_weight'], 3) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format($tot['revenue'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format($tot['trip_expenses'], 2) ?></td>
                    <td class="text-end" style="color:#fd7e14">&#8377;<?= number_format($tot['fuel_alloc'], 2) ?></td>
                    <td class="text-end text-secondary">&#8377;<?= number_format($tot['salary_alloc'] + $tot['veh_exp_alloc'], 2) ?></td>
                    <td class="text-end" style="color:#6f42c1">&#8377;<?= number_format($tot['overhead_alloc'], 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format($tot['total_expenses'], 2) ?></td>
                    <td class="text-end <?= $tot['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">&#8377;<?= number_format($tot['profit'], 2) ?></td>
                    <td class="text-end <?= $tot_margin >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($tot_margin, 1) ?>%</td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Improvement Insights -->
<?php
$loss_customers = array_filter($rows, fn($r) => (float)$r['profit'] < 0);
$low_margin     = array_filter($rows, fn($r) => (float)$r['margin'] >= 0 && (float)$r['margin'] < 10);
$high_margin    = array_filter($rows, fn($r) => (float)$r['margin'] >= 25);
?>
<?php if ($rows): ?>
<div class="card mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-lightbulb-fill me-2 text-warning"></i>Insights &amp; Recommendations</div>
    <div class="card-body">
        <?php if ($loss_customers): ?>
        <div class="alert alert-danger mb-2 py-2">
            <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Loss-making customers:</strong>
            <?= implode(', ', array_map(fn($r) => htmlspecialchars($r['customer_name']) . ' (₹' . number_format($r['profit'], 0) . ')', $loss_customers)) ?>.
            Review freight rates or reduce vehicle-level costs on these routes.
        </div>
        <?php endif; ?>
        <?php if ($low_margin): ?>
        <div class="alert alert-warning mb-2 py-2">
            <strong><i class="bi bi-exclamation-circle me-1"></i>Low-margin customers (&lt;10%):</strong>
            <?= implode(', ', array_map(fn($r) => htmlspecialchars($r['customer_name']) . ' (' . number_format($r['margin'], 1) . '%)', $low_margin)) ?>.
            Consider renegotiating rates or optimising load weight per trip.
        </div>
        <?php endif; ?>
        <?php if ($high_margin): ?>
        <div class="alert alert-success mb-2 py-2">
            <strong><i class="bi bi-star-fill me-1"></i>High-margin customers (&ge;25%):</strong>
            <?= implode(', ', array_map(fn($r) => htmlspecialchars($r['customer_name']) . ' (' . number_format($r['margin'], 1) . '%)', $high_margin)) ?>.
            Prioritise capacity allocation here &mdash; these are your most profitable routes.
        </div>
        <?php endif; ?>
        <?php
        // Tip: avg weight efficiency
        $low_wt = array_filter($rows, fn($r) => $r['trip_count'] > 0 && ($r['total_weight'] / $r['trip_count']) < 5);
        if ($low_wt):
        ?>
        <div class="alert alert-light border mb-2 py-2">
            <strong><i class="bi bi-boxes me-1"></i>Low load utilisation:</strong>
            <?= implode(', ', array_map(fn($r) => htmlspecialchars($r['customer_name']) . ' (' . number_format($r['total_weight'] / $r['trip_count'], 2) . ' MT/trip)', $low_wt)) ?>.
            These routes are under-loaded. Consolidating trips or pushing for larger consignments will improve per-trip margins.
        </div>
        <?php endif; ?>
        <?php if (!$loss_customers && !$low_margin): ?>
        <div class="alert alert-success mb-0 py-2">
            <i class="bi bi-check-circle-fill me-1"></i>All customers are profitable with healthy margins this month. Keep optimising load weights and fuel efficiency.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (count($chart_rows) > 0): ?>
<script>
(function() {
    const labels   = <?= json_encode($chart_labels) ?>;
    const revenue  = <?= json_encode($chart_revenue) ?>;
    const expenses = <?= json_encode($chart_expenses) ?>;
    const profit   = <?= json_encode($chart_profit) ?>;

    const ctx = document.getElementById('customerPnlChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: revenue,
                    backgroundColor: 'rgba(25, 135, 84, 0.75)',
                    borderColor: '#198754',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Total Expenses',
                    data: expenses,
                    backgroundColor: 'rgba(220, 53, 69, 0.70)',
                    borderColor: '#dc3545',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Net Profit',
                    data: profit,
                    backgroundColor: profit.map(v => v >= 0 ? 'rgba(13, 110, 253, 0.75)' : 'rgba(255, 193, 7, 0.85)'),
                    borderColor: profit.map(v => v >= 0 ? '#0d6efd' : '#ffc107'),
                    borderWidth: 1,
                    borderRadius: 4,
                    type: 'bar',
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 12 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₹' + ctx.parsed.y.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                    }
                }
            },
            scales: {
                x: { ticks: { font: { size: 11 } } },
                y: {
                    ticks: {
                        callback: v => '₹' + (v/1000).toFixed(0) + 'k',
                        font: { size: 11 }
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
