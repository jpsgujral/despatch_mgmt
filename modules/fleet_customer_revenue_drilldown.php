<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

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
$month_end = date('Y-m-t', strtotime($month_start));
$customer_id = (int)($_GET['customer_id'] ?? 0);
$unassigned = $customer_id === -1;

if ($customer_id <= 0 && !$unassigned) {
    showAlert('danger', 'Customer required.');
    redirect('fleet_customer_revenue.php?month=' . urlencode($month));
}

$customer = null;
if ($customer_id > 0) {
    $customer = $db->query("SELECT id, vendor_code, vendor_name, status
        FROM fleet_customers_master
        WHERE id = $customer_id
        LIMIT 1")->fetch_assoc();
    if (!$customer) {
        showAlert('danger', 'Customer not found.');
        redirect('fleet_customer_revenue.php?month=' . urlencode($month));
    }
}

$label = $unassigned
    ? 'Unassigned / Other'
    : (string)($customer['vendor_name'] ?? 'Customer #' . $customer_id);

$trip_sql = "SELECT t.id, t.trip_no, t.trip_date, t.end_date, t.vehicle_id, t.total_weight,
        t.freight_amount, t.subtotal, t.customer_name,
        COALESCE(v.reg_no, CONCAT('Vehicle #', t.vehicle_id)) AS vehicle_no,
        COALESCE(t.subtotal, 0) AS subtotal_val,
        COALESCE(t.freight_amount, 0) AS freight_val,
        $trip_period_expr AS pnl_trip_date
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id = v.id
    WHERE t.status = 'Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'";

if ($customer_id > 0) {
    $trip_sql .= " AND t.vendor_id = $customer_id";
} else {
    $trip_sql .= " AND COALESCE(t.vendor_id, 0) = 0";
}

$trip_sql .= " ORDER BY $trip_period_expr, t.id";

$trips = $db->query($trip_sql)->fetch_all(MYSQLI_ASSOC);

$summary = [
    'revenue' => 0.0,
    'trip_count' => 0,
    'total_weight' => 0.0,
];

foreach ($trips as $trip) {
    $summary['trip_count'] += 1;
    $summary['total_weight'] += (float)($trip['total_weight'] ?? 0);
    $summary['revenue'] += ((float)$trip['subtotal_val'] > 0 ? (float)$trip['subtotal_val'] : (float)$trip['freight_val']);
}

$avg_per_trip = $summary['trip_count'] > 0 ? $summary['revenue'] / $summary['trip_count'] : 0;

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-person-lines-fill me-2"></i>Customer Revenue Drill-down';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Customer Revenue Drill-down: <?= htmlspecialchars($label) ?> (<?= htmlspecialchars($month) ?>)</h5>
    <a href="fleet_customer_revenue.php?month=<?= urlencode($month) ?>" class="btn btn-outline-secondary btn-sm">Back</a>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Revenue</small>
            <div class="fw-bold text-success">&#8377;<?= number_format($summary['revenue'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Trips</small>
            <div class="fw-bold"><?= number_format($summary['trip_count'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Weight (MT)</small>
            <div class="fw-bold"><?= number_format($summary['total_weight'], 3) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Avg / Trip</small>
            <div class="fw-bold">&#8377;<?= number_format($avg_per_trip, 2) ?></div>
        </div>
    </div>
</div>

<div class="alert alert-light border mb-3">
    This view shows revenue only. It does not include fuel, vehicle expenses, salary, or statutory overhead.
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Trip</th>
                    <th>Vehicle</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Source</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trips as $trip): ?>
                <?php
                    $revenue = (float)$trip['subtotal_val'] > 0 ? (float)$trip['subtotal_val'] : (float)$trip['freight_val'];
                    $source = (float)$trip['subtotal_val'] > 0 ? 'Trip subtotal' : 'Freight amount';
                ?>
                <tr>
                    <td><?= !empty($trip['pnl_trip_date']) ? date('d/m/Y', strtotime($trip['pnl_trip_date'])) : '-' ?></td>
<td><a href="fleet_trips.php?action=view&id=<?= (int)$trip['id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_customer_revenue_drilldown.php') ?>"><?= htmlspecialchars($trip['trip_no']) ?></a></td>
                    <td><?= htmlspecialchars($trip['vehicle_no']) ?></td>
                    <td class="text-end"><?= number_format((float)$trip['total_weight'], 3) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format($revenue, 2) ?></td>
                    <td class="text-end"><?= htmlspecialchars($source) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$trips): ?>
                <tr>
                    <td colspan="6" class="text-muted p-3">No completed trips found for this customer in the selected month.</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <?php if ($trips): ?>
            <tfoot class="table-light">
                <tr>
                    <th colspan="3" class="text-end">Total</th>
                    <th class="text-end"><?= number_format($summary['total_weight'], 3) ?></th>
                    <th class="text-end text-success">&#8377;<?= number_format($summary['revenue'], 2) ?></th>
                    <th></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
