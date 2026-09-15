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
$customer_filter = (int)($_GET['customer_id'] ?? 0);

$customer_rows = $db->query("SELECT id, vendor_code, vendor_name, status
    FROM fleet_customers_master
    ORDER BY status ASC, vendor_name ASC")->fetch_all(MYSQLI_ASSOC);

$customer_label_expr = "COALESCE(cm.vendor_name, NULLIF(TRIM(t.customer_name), ''), 'Unassigned')";
$customer_key_expr = "COALESCE(t.vendor_id, 0)";

$summary_sql = "SELECT
        $customer_key_expr AS customer_id,
        $customer_label_expr AS customer_name,
        COALESCE(cm.vendor_code, '') AS customer_code,
        COALESCE(cm.status, CASE WHEN COALESCE(t.vendor_id,0)=0 THEN 'Unassigned' ELSE 'Unknown' END) AS customer_status,
        COUNT(*) AS trip_count,
        COALESCE(SUM(t.total_weight),0) AS total_weight,
        COALESCE(SUM(CASE WHEN t.subtotal > 0 THEN t.subtotal ELSE t.freight_amount END),0) AS revenue
    FROM fleet_trips t
    LEFT JOIN fleet_customers_master cm ON t.vendor_id = cm.id
    WHERE t.status = 'Completed'
      AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'";

if ($customer_filter > 0) {
    $summary_sql .= " AND t.vendor_id = $customer_filter";
} elseif ($customer_filter === -1) {
    $summary_sql .= " AND COALESCE(t.vendor_id, 0) = 0";
}

$summary_sql .= " GROUP BY customer_id, customer_name, customer_code, customer_status
    ORDER BY revenue DESC, trip_count DESC, customer_name ASC";

$rows = $db->query($summary_sql)->fetch_all(MYSQLI_ASSOC);

$totals = [
    'revenue' => 0.0,
    'trip_count' => 0,
    'total_weight' => 0.0,
];

foreach ($rows as $row) {
    $totals['revenue'] += (float)($row['revenue'] ?? 0);
    $totals['trip_count'] += (int)($row['trip_count'] ?? 0);
    $totals['total_weight'] += (float)($row['total_weight'] ?? 0);
}

$active_customers = count(array_filter($rows, fn($row) => (int)$row['customer_id'] > 0));
$avg_per_trip = $totals['trip_count'] > 0 ? $totals['revenue'] / $totals['trip_count'] : 0;
$top_customer = $rows[0] ?? null;

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-person-lines-fill me-2"></i>Fleet Customer Revenue';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Monthly Customer Revenue</h5>
</div>

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
                    <?php foreach ($customer_rows as $customer): ?>
                    <option value="<?= (int)$customer['id'] ?>" <?= $customer_filter === (int)$customer['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($customer['vendor_name'] . ' (' . $customer['vendor_code'] . ')') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
            <div class="col-12 col-md-3 text-muted small">
                Revenue only. Expenses are intentionally excluded from this view.
            </div>
        </form>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Revenue</small>
            <div class="fw-bold text-success">&#8377;<?= number_format($totals['revenue'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Trips</small>
            <div class="fw-bold"><?= number_format($totals['trip_count'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Active Customers</small>
            <div class="fw-bold"><?= number_format($active_customers, 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-2 text-center h-100">
            <small class="text-muted">Avg / Trip</small>
            <div class="fw-bold">&#8377;<?= number_format($avg_per_trip, 2) ?></div>
        </div>
    </div>
</div>

<?php if ($top_customer): ?>
<div class="alert alert-light border mb-3">
    <strong>Top customer:</strong>
    <?= htmlspecialchars($top_customer['customer_name']) ?>
    with &#8377;<?= number_format((float)$top_customer['revenue'], 2) ?> this month.
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Customer</th>
                    <th>Status</th>
                    <th class="text-end">Trips</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Avg / Trip</th>
                    <th class="text-end">Share</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php
                    $revenue = (float)$row['revenue'];
                    $share = $totals['revenue'] > 0 ? ($revenue / $totals['revenue']) * 100 : 0;
                    $status = (string)($row['customer_status'] ?? '');
                    $status_class = $status === 'Active' ? 'success' : ($status === 'Inactive' ? 'secondary' : 'warning');
                    $customer_id = (int)$row['customer_id'];
                    $detail_url = $customer_id > 0
                        ? 'fleet_customer_revenue_drilldown.php?month=' . urlencode($month) . '&customer_id=' . $customer_id
                        : 'fleet_customer_revenue_drilldown.php?month=' . urlencode($month) . '&customer_id=-1';
                ?>
                <tr>
                    <td class="fw-semibold">
                        <?= htmlspecialchars($row['customer_name']) ?>
                        <?php if (!empty($row['customer_code'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($row['customer_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= $status_class ?>"><?= htmlspecialchars($status ?: 'Unknown') ?></span></td>
                    <td class="text-end"><?= number_format((int)$row['trip_count'], 0) ?></td>
                    <td class="text-end"><?= number_format((float)$row['total_weight'], 3) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format($revenue, 2) ?></td>
                    <td class="text-end">&#8377;<?= number_format($row['trip_count'] > 0 ? $revenue / (int)$row['trip_count'] : 0, 2) ?></td>
                    <td class="text-end"><?= number_format($share, 1) ?>%</td>
                    <td class="text-end">
                        <a href="<?= htmlspecialchars($detail_url) ?>" class="btn btn-sm btn-outline-primary">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr>
                    <td colspan="8" class="text-muted p-3">No completed trips found for the selected month.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
