<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_dashboard', 'view');

/* ── Status log table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_vehicle_status_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id  INT NOT NULL,
    old_status  VARCHAR(50) DEFAULT '',
    new_status  VARCHAR(50) NOT NULL,
    changed_by  INT DEFAULT 0,
    notes       TEXT,
    changed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Status Update POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    requirePerm('fleet_vehicles', 'update');
    $vid = (int)$_POST['vehicle_id'];
    $new_status = sanitize($_POST['new_status']);
    $notes = sanitize($_POST['notes'] ?? '');
    $cur_v = $db->query("SELECT status FROM fleet_vehicles WHERE id=$vid LIMIT 1")->fetch_assoc();
    $old_status = $cur_v ? $cur_v['status'] : '';
    if ($new_status && $new_status !== $old_status) {
        $db->query("UPDATE fleet_vehicles SET status='$new_status' WHERE id=$vid");
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $db->query("INSERT INTO fleet_vehicle_status_log (vehicle_id, old_status, new_status, changed_by, notes)
                    VALUES ($vid, '$old_status', '$new_status', $uid, '$notes')");
        showAlert('success', "Vehicle status updated to $new_status.");
    }
    redirect('fleet_dashboard.php');
}

/* ═══════════════════════════════════════
   PERIOD FILTER SETUP
═══════════════════════════════════════ */
$period = $_GET['period'] ?? 'mtd';
switch ($period) {
    case 'prev_month':
        $date_filter_trips = "trip_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND trip_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $date_filter_fuel  = "fuel_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND fuel_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $date_filter_exp   = "expense_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND expense_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $period_label      = date('F Y', strtotime('first day of last month'));
        break;
    case 'ytd':
        $currMonth   = (int)date('n');
        $currYear    = (int)date('Y');
        $fyStartYear = ($currMonth >= 4) ? $currYear : ($currYear - 1);
        $fyStartDate = "{$fyStartYear}-04-01";
        $date_filter_trips = "trip_date >= '{$fyStartDate}'";
        $date_filter_fuel  = "fuel_date >= '{$fyStartDate}'";
        $date_filter_exp   = "expense_date >= '{$fyStartDate}'";
        $period_label      = "FY " . substr((string)$fyStartYear, 2) . "-" . substr((string)($fyStartYear + 1), 2);
        break;
    case 'all':
        $date_filter_trips = "1=1";
        $date_filter_fuel  = "1=1";
        $date_filter_exp   = "1=1";
        $period_label      = "All Time";
        break;
    case 'mtd':
    default:
        $date_filter_trips = "trip_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $date_filter_fuel  = "fuel_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $date_filter_exp   = "expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $period_label      = date('F Y');
        break;
}

/* ═══════════════════════════════════════
   DATA FETCHING
═══════════════════════════════════════ */

/* 1. Vehicles */
$vehicles = $db->query("SELECT * FROM fleet_vehicles ORDER BY status, reg_no")->fetch_all(MYSQLI_ASSOC);
$status_counts = ['Active' => 0, 'In Repair' => 0, 'Idle' => 0, 'Disposed' => 0];
$total_capacity = 0;
foreach ($vehicles as $v) {
    $st = $v['status'] ?? 'Idle';
    $status_counts[$st] = ($status_counts[$st] ?? 0) + 1;
    if ($st === 'Active') {
        $total_capacity += (float)($v['capacity_tons'] ?? 0);
    }
}
$total_vehicles = count($vehicles);
$active_vehicles = $status_counts['Active'] ?? 0;
$utilization_rate = $total_vehicles > 0 ? round(($active_vehicles / $total_vehicles) * 100, 1) : 0;

/* 2. Trip Stats in Period */
$trip_stats = $db->query("SELECT
    COUNT(*) as total_trips,
    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed_trips,
    SUM(CASE WHEN status='Planned' THEN 1 ELSE 0 END) as planned_trips,
    SUM(CASE WHEN status='In Transit' THEN 1 ELSE 0 END) as transit_trips,
    SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) as cancelled_trips,
    COALESCE(SUM(CASE WHEN status='Completed' THEN total_weight ELSE 0 END), 0) as total_weight,
    COALESCE(SUM(CASE WHEN status='Completed' THEN freight_amount ELSE 0 END), 0) as gross_freight,
    COALESCE(SUM(CASE WHEN status='Completed' THEN net_freight_amount ELSE 0 END), 0) as net_freight,
    COALESCE(SUM(CASE WHEN status='Completed' AND billing_status='Billed' THEN 1 ELSE 0 END), 0) as billed_trips,
    COALESCE(SUM(CASE WHEN status='Completed' AND (billing_status='Pending' OR billing_status IS NULL) THEN 1 ELSE 0 END), 0) as unbilled_trips
    FROM fleet_trips WHERE $date_filter_trips")->fetch_assoc();

$completed_trips = (int)($trip_stats['completed_trips'] ?? 0);
$total_weight_mt = (float)($trip_stats['total_weight'] ?? 0);
$gross_freight_amt = (float)($trip_stats['gross_freight'] ?? 0);
$avg_freight_per_mt = $total_weight_mt > 0 ? ($gross_freight_amt / $total_weight_mt) : 0;
$avg_weight_per_trip = $completed_trips > 0 ? ($total_weight_mt / $completed_trips) : 0;

/* 3. Fuel Stats in Period */
$fuel_stats = $db->query("SELECT
    COUNT(*) as fuel_entries,
    COALESCE(SUM(litres), 0) as total_litres,
    COALESCE(SUM(amount), 0) as total_fuel_cost
    FROM fleet_fuel_log WHERE $date_filter_fuel")->fetch_assoc();

$total_fuel_litres = (float)($fuel_stats['total_litres'] ?? 0);
$total_fuel_cost   = (float)($fuel_stats['total_fuel_cost'] ?? 0);

/* 4. Expense Stats in Period */
$exp_stats = $db->query("SELECT
    COUNT(*) as expense_entries,
    COALESCE(SUM(amount), 0) as total_expense_cost
    FROM fleet_expenses WHERE $date_filter_exp")->fetch_assoc();
$total_expense_cost = (float)($exp_stats['total_expense_cost'] ?? 0);

/* 5. 6-Month Monthly Performance Trend (for Chart) */
$monthly_trend_query = $db->query("SELECT
    DATE_FORMAT(trip_date, '%b %y') as m_label,
    DATE_FORMAT(trip_date, '%Y-%m') as m_key,
    COUNT(CASE WHEN status='Completed' THEN 1 END) as trips_count,
    COALESCE(SUM(CASE WHEN status='Completed' THEN total_weight ELSE 0 END), 0) as total_weight,
    COALESCE(SUM(CASE WHEN status='Completed' THEN freight_amount ELSE 0 END), 0) as freight_amt
    FROM fleet_trips
    WHERE trip_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
    GROUP BY m_key, m_label
    ORDER BY m_key ASC");
$trend_labels = [];
$trend_trips = [];
$trend_weight = [];
$trend_freight_lakhs = [];
if ($monthly_trend_query) {
    while ($row = $monthly_trend_query->fetch_assoc()) {
        $trend_labels[] = $row['m_label'];
        $trend_trips[] = (int)$row['trips_count'];
        $trend_weight[] = round((float)$row['total_weight'], 1);
        $trend_freight_lakhs[] = round(((float)$row['freight_amt'] / 100000), 2);
    }
}

/* 6. Top 5 Performing Vehicles */
$top_vehicles = $db->query("SELECT
    v.reg_no, v.make, v.model,
    COUNT(t.id) as trips_count,
    COALESCE(SUM(t.total_weight), 0) as total_weight,
    COALESCE(SUM(t.freight_amount), 0) as total_freight
    FROM fleet_trips t
    JOIN fleet_vehicles v ON t.vehicle_id = v.id
    WHERE t.status='Completed' AND $date_filter_trips
    GROUP BY v.id, v.reg_no, v.make, v.model
    ORDER BY total_freight DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

/* 7. Top 5 Customers by Revenue */
$top_customers = $db->query("SELECT
    COALESCE(t.customer_name, 'Unknown') as cust_name,
    COUNT(t.id) as trips_count,
    COALESCE(SUM(t.total_weight), 0) as total_weight,
    COALESCE(SUM(t.freight_amount), 0) as total_freight
    FROM fleet_trips t
    WHERE t.status='Completed' AND $date_filter_trips
    GROUP BY cust_name
    ORDER BY total_freight DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

/* 8. Recent Trips List */
$recent_trips = $db->query("SELECT
    t.id, t.trip_no, t.trip_date, t.customer_name, t.customer_camp,
    t.total_weight, t.freight_amount, t.status, t.billing_status,
    v.reg_no
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id = v.id
    ORDER BY t.trip_date DESC, t.id DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

/* 9. Expiry alerts (within 30 days or expired) */
$expiry_alerts = $db->query("
    SELECT id, reg_no, make, model,
           insurance_expiry, fitness_expiry, permit_expiry,
           puc_expiry, national_permit_expiry
    FROM fleet_vehicles
    WHERE status != 'Disposed'
      AND (
           (insurance_expiry       IS NOT NULL AND insurance_expiry       <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (fitness_expiry         IS NOT NULL AND fitness_expiry         <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (permit_expiry          IS NOT NULL AND permit_expiry          <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (puc_expiry             IS NOT NULL AND puc_expiry             <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (national_permit_expiry IS NOT NULL AND national_permit_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
      )
    ORDER BY LEAST(
        COALESCE(insurance_expiry,       '9999-12-31'),
        COALESCE(fitness_expiry,         '9999-12-31'),
        COALESCE(permit_expiry,          '9999-12-31'),
        COALESCE(puc_expiry,             '9999-12-31'),
        COALESCE(national_permit_expiry, '9999-12-31')
    )
")->fetch_all(MYSQLI_ASSOC);

/* 10. Recent Fuel Log Entries */
$recent_fuel = $db->query("SELECT f.*, v.reg_no, c.company_name as fuel_company
    FROM fleet_fuel_log f
    LEFT JOIN fleet_vehicles v ON f.vehicle_id = v.id
    LEFT JOIN fleet_fuel_companies c ON f.fuel_company_id = c.id
    ORDER BY f.fuel_date DESC, f.id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

/* 11. PO Stats */
$po_stats = $db->query("SELECT
    COUNT(*) total,
    SUM(CASE WHEN status='Draft'              THEN 1 ELSE 0 END) draft,
    SUM(CASE WHEN status='Approved'           THEN 1 ELSE 0 END) approved,
    SUM(CASE WHEN status='Partially Received' THEN 1 ELSE 0 END) partial,
    SUM(CASE WHEN status='Received'           THEN 1 ELSE 0 END) received,
    SUM(CASE WHEN status='Cancelled'          THEN 1 ELSE 0 END) cancelled,
    COALESCE(SUM(total_amount), 0) total_value,
    COALESCE(SUM(CASE WHEN status='Approved' THEN total_amount ELSE 0 END), 0) approved_value
    FROM fleet_purchase_orders")->fetch_assoc();

include '../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-speedometer2 me-2"></i>Fleet Dashboard';</script>

<style>
/* ── Modern Fleet Dashboard Styling ── */
.fleet-dash-container { padding: 12px 6px; }
.kpi-metric-card {
    border: none;
    border-radius: 14px;
    box-shadow: 0 3px 14px rgba(0,0,0,0.06);
    background: #ffffff;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    overflow: hidden;
    position: relative;
}
.kpi-metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}
.kpi-accent-bar {
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
}
.kpi-icon-bubble {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.kpi-title {
    font-size: 0.76rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 2px;
}
.kpi-main-val {
    font-size: 1.45rem;
    font-weight: 800;
    line-height: 1.2;
    color: #0f172a;
}
.kpi-sub-text {
    font-size: 0.74rem;
    color: #64748b;
    margin-top: 4px;
}
.section-panel {
    border: none;
    border-radius: 14px;
    box-shadow: 0 3px 14px rgba(0,0,0,0.06);
    background: #ffffff;
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.section-panel-header {
    padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.section-panel-title {
    font-weight: 700;
    font-size: 0.92rem;
    letter-spacing: 0.3px;
    margin: 0;
    color: #ffffff !important;
}
.section-panel-title i { margin-right: 6px; }
.vehicle-mini-card {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    transition: box-shadow 0.2s ease;
    display: flex;
    overflow: hidden;
}
.vehicle-mini-card:hover {
    box-shadow: 0 4px 14px rgba(0,0,0,0.08);
}
.v-status-bar { width: 5px; flex-shrink: 0; }
.doc-pill {
    font-size: 0.68rem;
    border-radius: 999px;
    padding: 2px 8px;
    border: 1px solid;
    display: inline-flex;
    align-items: center;
    font-weight: 600;
}
.table-compact th {
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #1e3a8a;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.55rem 0.65rem;
    white-space: nowrap;
}
.table-compact td {
    font-size: 0.82rem;
    vertical-align: middle;
    padding: 0.5rem 0.65rem;
    border-color: #f1f5f9;
}
.period-btn-group .btn {
    font-size: 0.78rem;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 8px;
}
.expiry-urgent { color: #dc2626; font-weight: 700; background: #fef2f2; border-color: #fca5a5; }
.expiry-warning { color: #d97706; font-weight: 600; background: #fffbeb; border-color: #fde68a; }

/* Dark mode adjustments */
body.dark-mode .kpi-metric-card,
body.dark-mode .section-panel,
body.dark-mode .vehicle-mini-card {
    background: var(--dm-surface, #172033);
    border-color: var(--dm-border, #334155);
}
body.dark-mode .kpi-main-val { color: #f8fafc; }
body.dark-mode .table-compact th { background: #1e293b; color: #93c5fd; border-color: #334155; }
body.dark-mode .table-compact td { border-color: #334155; color: #e2e8f0; }
</style>

<?php
function formatLakhs($amount) {
    if ($amount >= 10000000) return '₹' . number_format($amount / 10000000, 2) . ' Cr';
    if ($amount >= 100000)   return '₹' . number_format($amount / 100000, 2) . ' L';
    return '₹' . number_format($amount, 2);
}
function stripColor($s) {
    return match($s) {
        'Active'    => '#2563eb',
        'In Repair' => '#f59e0b',
        'Idle'      => '#6366f1',
        'Disposed'  => '#64748b',
        default     => '#94a3af',
    };
}
function statusBadgeStyle($s) {
    $cfg = [
        'Active'    => ['#eff6ff','#2563eb'],
        'In Repair' => ['#fffbeb','#f59e0b'],
        'Idle'      => ['#eef2ff','#6366f1'],
        'Disposed'  => ['#f8fafc','#64748b'],
    ];
    [$bg,$col] = $cfg[$s] ?? ['#f1f5f9','#64748b'];
    return "background:$bg;color:$col;border-color:{$col}40";
}
function docExpiryTag($date) {
    if (!$date || $date === '0000-00-00') return null;
    $days = (int)round((strtotime($date) - time()) / 86400);
    $fmt  = date('d M y', strtotime($date));
    if ($days < 0)   return ['label' => "$fmt (Expired)", 'class' => 'expiry-urgent'];
    if ($days <= 7)  return ['label' => "$fmt ({$days}d left)", 'class' => 'expiry-urgent'];
    if ($days <= 30) return ['label' => "$fmt ({$days}d left)", 'class' => 'expiry-warning'];
    return null;
}
?>

<div class="container-fluid fleet-dash-container">

    <!-- ══════════ TOP ACTION BAR & PERIOD SWITCHER ══════════ -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-truck-front-fill text-primary me-2"></i>Fleet Operations & Analytics</h5>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><?= htmlspecialchars($period_label) ?></span>
            </div>
            <div class="text-muted small">Real-time fleet performance, revenue metrics, trip logs, and compliance radar.</div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- Period switcher -->
            <div class="btn-group period-btn-group shadow-sm" role="group">
                <a href="?period=mtd" class="btn btn-outline-primary <?= $period === 'mtd' ? 'active' : '' ?>">This Month</a>
                <a href="?period=prev_month" class="btn btn-outline-primary <?= $period === 'prev_month' ? 'active' : '' ?>">Last Month</a>
                <a href="?period=ytd" class="btn btn-outline-primary <?= $period === 'ytd' ? 'active' : '' ?>">This FY</a>
                <a href="?period=all" class="btn btn-outline-primary <?= $period === 'all' ? 'active' : '' ?>">All Time</a>
            </div>

            <!-- Quick Action Hub -->
            <div class="dropdown">
                <button class="btn btn-primary btn-sm dropdown-toggle fw-semibold shadow-sm px-3" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-lightning-charge-fill me-1"></i>Quick Actions
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><a class="dropdown-item py-2" href="fleet_trips.php?action=add"><i class="bi bi-plus-circle-fill text-success me-2"></i>New Trip Order</a></li>
                    <li><a class="dropdown-item py-2" href="fleet_fuel.php?action=add"><i class="bi bi-fuel-pump-fill text-warning me-2"></i>Log Fuel Entry</a></li>
                    <li><a class="dropdown-item py-2" href="fleet_expenses.php?action=add"><i class="bi bi-wrench-adjustable text-danger me-2"></i>Log Vehicle Expense</a></li>
                    <li><a class="dropdown-item py-2" href="fleet_purchase_orders.php?action=add"><i class="bi bi-receipt text-primary me-2"></i>Create Purchase Order</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2" href="fleet_trips.php?view=register"><i class="bi bi-card-checklist me-2"></i>Trip Order Register</a></li>
                    <li><a class="dropdown-item py-2" href="fleet_vehicle_pnl.php"><i class="bi bi-graph-up-arrow me-2"></i>Vehicle P&L Report</a></li>
                    <li><a class="dropdown-item py-2" href="fleet_status.php"><i class="bi bi-geo-alt-fill me-2"></i>Live Vehicle Status</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ══════════ EXECUTIVE KPI METRICS (6 CARDS) ══════════ -->
    <div class="row g-3 mb-4">
        <!-- 1. Trips Completed -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card kpi-metric-card h-100 p-3">
                <div class="kpi-accent-bar" style="background:#2563eb"></div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="kpi-title">Completed Trips</div>
                    <div class="kpi-icon-bubble" style="background:#eff6ff;color:#2563eb">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                </div>
                <div class="kpi-main-val text-primary"><?= number_format($completed_trips) ?></div>
                <div class="kpi-sub-text">
                    <?php if (($trip_stats['transit_trips'] ?? 0) > 0): ?>
                        <span class="badge bg-warning text-dark me-1"><?= (int)$trip_stats['transit_trips'] ?> In Transit</span>
                    <?php endif; ?>
                    <?= (int)($trip_stats['planned_trips'] ?? 0) ?> Planned
                </div>
            </div>
        </div>

        <!-- 2. Total Freight Revenue -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card kpi-metric-card h-100 p-3">
                <div class="kpi-accent-bar" style="background:#059669"></div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="kpi-title">Freight Revenue</div>
                    <div class="kpi-icon-bubble" style="background:#ecfdf5;color:#059669">
                        <i class="bi bi-currency-rupee"></i>
                    </div>
                </div>
                <div class="kpi-main-val text-success"><?= formatLakhs($gross_freight_amt) ?></div>
                <div class="kpi-sub-text">
                    Avg ₹<?= number_format($avg_freight_per_mt, 0) ?> / MT
                </div>
            </div>
        </div>

        <!-- 3. Tonnage Transported -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card kpi-metric-card h-100 p-3">
                <div class="kpi-accent-bar" style="background:#0891b2"></div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="kpi-title">Tonnage Moved</div>
                    <div class="kpi-icon-bubble" style="background:#ecfeff;color:#0891b2">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
                <div class="kpi-main-val" style="color:#0891b2"><?= number_format($total_weight_mt, 1) ?> <span style="font-size:0.85rem">MT</span></div>
                <div class="kpi-sub-text">Avg <?= number_format($avg_weight_per_trip, 1) ?> MT / trip</div>
            </div>
        </div>

        <!-- 4. Fuel Consumption & Cost -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card kpi-metric-card h-100 p-3">
                <div class="kpi-accent-bar" style="background:#d97706"></div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="kpi-title">Fuel Spend</div>
                    <div class="kpi-icon-bubble" style="background:#fffbeb;color:#d97706">
                        <i class="bi bi-fuel-pump"></i>
                    </div>
                </div>
                <div class="kpi-main-val text-warning-emphasis"><?= formatLakhs($total_fuel_cost) ?></div>
                <div class="kpi-sub-text"><?= number_format($total_fuel_litres, 0) ?> Litres</div>
            </div>
        </div>

        <!-- 5. Active Fleet Utilization -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card kpi-metric-card h-100 p-3">
                <div class="kpi-accent-bar" style="background:#6366f1"></div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="kpi-title">Fleet Active</div>
                    <div class="kpi-icon-bubble" style="background:#eef2ff;color:#6366f1">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
                <div class="kpi-main-val" style="color:#6366f1"><?= $active_vehicles ?> / <?= $total_vehicles ?></div>
                <div class="kpi-sub-text"><?= $utilization_rate ?>% active (<?= number_format($total_capacity, 0) ?>T cap)</div>
            </div>
        </div>

        <!-- 6. Doc Compliance Alerts -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card kpi-metric-card h-100 p-3">
                <div class="kpi-accent-bar" style="background:#dc2626"></div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="kpi-title">Doc Alerts</div>
                    <div class="kpi-icon-bubble" style="background:#fef2f2;color:#dc2626">
                        <i class="bi bi-exclamation-octagon"></i>
                    </div>
                </div>
                <div class="kpi-main-val text-danger"><?= count($expiry_alerts) ?></div>
                <div class="kpi-sub-text">Expiring in 30 days</div>
            </div>
        </div>
    </div>

    <!-- ══════════ CHARTS ROW ══════════ -->
    <div class="row g-3 mb-4">
        <!-- 6-Month Monthly Performance Trend -->
        <div class="col-12 col-xl-8">
            <div class="card section-panel h-100">
                <div class="section-panel-header">
                    <h6 class="section-panel-title"><i class="bi bi-bar-chart-line-fill"></i>6-Month Performance & Revenue Trend</h6>
                    <span class="badge bg-light text-primary fw-bold">Trips vs Freight (₹ Lakhs)</span>
                </div>
                <div class="card-body p-3">
                    <div style="height: 240px; position: relative;">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fleet Status Breakdown Donut -->
        <div class="col-12 col-xl-4">
            <div class="card section-panel h-100">
                <div class="section-panel-header">
                    <h6 class="section-panel-title"><i class="bi bi-pie-chart-fill"></i>Fleet Status Breakdown</h6>
                    <span class="badge bg-light text-primary fw-bold"><?= $total_vehicles ?> Vehicles</span>
                </div>
                <div class="card-body p-3 d-flex flex-column align-items-center justify-content-center">
                    <div style="height: 180px; width: 180px; position: relative;">
                        <canvas id="fleetStatusChart"></canvas>
                    </div>
                    <div class="d-flex justify-content-center gap-3 mt-3 flex-wrap small">
                        <span><i class="bi bi-circle-fill me-1" style="color:#2563eb"></i>Active: <strong><?= $status_counts['Active'] ?? 0 ?></strong></span>
                        <span><i class="bi bi-circle-fill me-1" style="color:#f59e0b"></i>Repair: <strong><?= $status_counts['In Repair'] ?? 0 ?></strong></span>
                        <span><i class="bi bi-circle-fill me-1" style="color:#6366f1"></i>Idle: <strong><?= $status_counts['Idle'] ?? 0 ?></strong></span>
                        <span><i class="bi bi-circle-fill me-1" style="color:#64748b"></i>Disposed: <strong><?= $status_counts['Disposed'] ?? 0 ?></strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════ MAIN WORKSPACE (2 COLS) ══════════ -->
    <div class="row g-3">

        <!-- LEFT COLUMN: Live Trips, Top Performers, Vehicle Fleet -->
        <div class="col-12 col-xl-8">

            <!-- 1. Recent Trips Table -->
            <div class="card section-panel">
                <div class="section-panel-header">
                    <h6 class="section-panel-title"><i class="bi bi-clock-history"></i>Recent Trip Orders</h6>
                    <a href="fleet_trips.php?view=register" class="btn btn-sm btn-light py-0 fw-semibold">View Register <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-compact mb-0">
                            <thead>
                                <tr>
                                    <th>Trip No</th>
                                    <th>Date</th>
                                    <th>Vehicle</th>
                                    <th>Customer / Site</th>
                                    <th class="text-end">Weight</th>
                                    <th class="text-end">Freight</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_trips)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No recent trips found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_trips as $rt):
                                        $sc = ($rt['status'] === 'Completed') ? 'success' : (($rt['status'] === 'Planned') ? 'primary' : (($rt['status'] === 'In Transit') ? 'warning text-dark' : 'secondary'));
                                    ?>
                                    <tr>
                                        <td><strong><a href="fleet_trips.php?action=view&id=<?= (int)$rt['id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($rt['trip_no']) ?></a></strong></td>
                                        <td><?= date('d/m/Y', strtotime($rt['trip_date'])) ?></td>
                                        <td><span class="badge bg-dark"><?= htmlspecialchars($rt['reg_no'] ?? '—') ?></span></td>
                                        <td>
                                            <div class="text-truncate" style="max-width: 170px;" title="<?= htmlspecialchars($rt['customer_name'] ?? '—') ?>">
                                                <?= htmlspecialchars($rt['customer_name'] ?? '—') ?>
                                            </div>
                                            <?php if (!empty($rt['customer_camp'])): ?>
                                                <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($rt['customer_camp']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-semibold"><?= number_format((float)$rt['total_weight'], 2) ?> MT</td>
                                        <td class="text-end fw-bold text-primary">₹<?= number_format((float)$rt['freight_amount'], 2) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $sc ?>"><?= htmlspecialchars($rt['status']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <a href="fleet_trip_challan.php?id=<?= (int)$rt['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" title="Print Challan"><i class="bi bi-printer"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 2. Top Performers (Top Vehicles + Top Customers side by side) -->
            <div class="row g-3 mb-4">
                <!-- Top Customers -->
                <div class="col-12 col-md-6">
                    <div class="card section-panel h-100 mb-0">
                        <div class="section-panel-header" style="background:linear-gradient(135deg, #0e7490, #0891b2)">
                            <h6 class="section-panel-title"><i class="bi bi-building"></i>Top Customers (<?= htmlspecialchars($period_label) ?>)</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-compact mb-0">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th class="text-center">Trips</th>
                                            <th class="text-end">Weight</th>
                                            <th class="text-end">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($top_customers)): ?>
                                        <tr><td colspan="4" class="text-center py-3 text-muted">No trip data in period.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($top_customers as $tc): ?>
                                            <tr>
                                                <td><div class="text-truncate fw-semibold" style="max-width: 140px;" title="<?= htmlspecialchars($tc['cust_name']) ?>"><?= htmlspecialchars($tc['cust_name']) ?></div></td>
                                                <td class="text-center"><span class="badge bg-light text-dark border"><?= (int)$tc['trips_count'] ?></span></td>
                                                <td class="text-end"><?= number_format((float)$tc['total_weight'], 1) ?> MT</td>
                                                <td class="text-end fw-bold text-success">₹<?= number_format((float)$tc['total_freight'], 0) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Vehicles -->
                <div class="col-12 col-md-6">
                    <div class="card section-panel h-100 mb-0">
                        <div class="section-panel-header" style="background:linear-gradient(135deg, #0f766e, #0d9488)">
                            <h6 class="section-panel-title"><i class="bi bi-trophy"></i>Top Vehicles (<?= htmlspecialchars($period_label) ?>)</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-compact mb-0">
                                    <thead>
                                        <tr>
                                            <th>Vehicle</th>
                                            <th class="text-center">Trips</th>
                                            <th class="text-end">Weight</th>
                                            <th class="text-end">Earnings</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($top_vehicles)): ?>
                                        <tr><td colspan="4" class="text-center py-3 text-muted">No trip data in period.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($top_vehicles as $tv): ?>
                                            <tr>
                                                <td><span class="badge bg-dark fw-bold"><?= htmlspecialchars($tv['reg_no']) ?></span></td>
                                                <td class="text-center"><span class="badge bg-light text-dark border"><?= (int)$tv['trips_count'] ?></span></td>
                                                <td class="text-end"><?= number_format((float)$tv['total_weight'], 1) ?> MT</td>
                                                <td class="text-end fw-bold text-success">₹<?= number_format((float)$tv['total_freight'], 0) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Vehicle Fleet Quick Grid -->
            <div class="card section-panel">
                <div class="section-panel-header">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="section-panel-title"><i class="bi bi-grid-3x3-gap-fill"></i>Fleet Vehicle Overview</h6>
                        <span class="badge bg-light text-primary"><?= $total_vehicles ?> Vehicles</span>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <div class="btn-group btn-group-sm" id="statusFilter">
                            <button class="btn btn-outline-light active" data-filter="all">All</button>
                            <button class="btn btn-outline-light" data-filter="Active">Active (<?= $status_counts['Active'] ?>)</button>
                            <button class="btn btn-outline-light" data-filter="In Repair">Repair (<?= $status_counts['In Repair'] ?>)</button>
                            <button class="btn btn-outline-light" data-filter="Idle">Idle (<?= $status_counts['Idle'] ?>)</button>
                        </div>
                        <a href="fleet_vehicles.php" class="btn btn-sm btn-light py-0 fw-semibold"><i class="bi bi-pencil me-1"></i>Manage</a>
                    </div>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2" id="vehicleGrid">
                        <?php foreach ($vehicles as $v):
                            $make_model = trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? ''));
                            $doc_map = [
                                'Insur.'     => $v['insurance_expiry'],
                                'Fitness'    => $v['fitness_expiry'],
                                'Permit'     => $v['permit_expiry'],
                                'PUC'        => $v['puc_expiry'],
                                'Nat.Permit' => $v['national_permit_expiry'],
                            ];
                            $expiring_docs = [];
                            foreach ($doc_map as $lbl => $dt) {
                                $info = docExpiryTag($dt);
                                if ($info) $expiring_docs[] = ['label' => $lbl, 'info' => $info];
                            }
                        ?>
                        <div class="col-12 col-md-6" data-vstatus="<?= htmlspecialchars($v['status']) ?>">
                            <div class="vehicle-mini-card">
                                <div class="v-status-bar" style="background:<?= stripColor($v['status']) ?>"></div>
                                <div class="p-2 flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <span class="fw-bold fs-6"><?= htmlspecialchars($v['reg_no']) ?></span>
                                            <span class="doc-pill ms-1" style="<?= statusBadgeStyle($v['status']) ?>"><?= $v['status'] ?></span>
                                            <div class="text-muted small">
                                                <?= $make_model ?: '—' ?>
                                                <?= $v['capacity_tons'] > 0 ? ' · '.$v['capacity_tons'].'T' : '' ?>
                                                <?= $v['fuel_type'] ? ' · '.$v['fuel_type'] : '' ?>
                                            </div>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light py-0 px-2" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li><h6 class="dropdown-header small">Quick Status</h6></li>
                                                <?php foreach (['Active','In Repair','Idle','Disposed'] as $ns):
                                                    if ($ns === $v['status']) continue; ?>
                                                <li><a class="dropdown-item small" href="#"
                                                    onclick="openStatusModal(<?= $v['id'] ?>,'<?= addslashes($v['reg_no']) ?>','<?= addslashes($v['status']) ?>','<?= $ns ?>');return false">
                                                    Mark <?= $ns ?>
                                                </a></li>
                                                <?php endforeach; ?>
                                                <li><hr class="dropdown-divider my-1"></li>
                                                <li><a class="dropdown-item small" href="fleet_vehicles.php?edit=<?= $v['id'] ?>"><i class="bi bi-pencil me-1"></i>Edit Vehicle</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <?php if ($expiring_docs): ?>
                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        <?php foreach ($expiring_docs as $d): ?>
                                        <span class="doc-pill <?= $d['info']['class'] ?>">
                                            <?= $d['label'] ?>: <?= $d['info']['label'] ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /left col -->

        <!-- RIGHT COLUMN: Compliance Alerts, Fuel Log, Purchase Orders -->
        <div class="col-12 col-xl-4">

            <!-- 1. Compliance Alerts -->
            <?php if (!empty($expiry_alerts)): ?>
            <div class="card section-panel">
                <div class="section-panel-header" style="background:linear-gradient(135deg, #b91c1c, #dc2626)">
                    <h6 class="section-panel-title"><i class="bi bi-exclamation-triangle-fill"></i>Compliance Alerts</h6>
                    <span class="badge bg-light text-danger fw-bold"><?= count($expiry_alerts) ?> Urgent</span>
                </div>
                <div class="card-body p-0" style="max-height:260px; overflow-y:auto">
                    <?php foreach ($expiry_alerts as $ea):
                        $doc_map2 = [
                            'Insurance'  => $ea['insurance_expiry'],
                            'Fitness'    => $ea['fitness_expiry'],
                            'Permit'     => $ea['permit_expiry'],
                            'PUC'        => $ea['puc_expiry'],
                            'Nat.Permit' => $ea['national_permit_expiry'],
                        ];
                    ?>
                    <div class="p-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold small"><?= htmlspecialchars($ea['reg_no']) ?></div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                <?php foreach ($doc_map2 as $lbl => $dt):
                                    $info = docExpiryTag($dt);
                                    if (!$info) continue;
                                ?>
                                <span class="doc-pill <?= $info['class'] ?>">
                                    <?= $lbl ?>: <?= $info['label'] ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <a href="fleet_vehicles.php?edit=<?= (int)$ea['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2" title="Renew"><i class="bi bi-arrow-clockwise"></i></a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 2. Recent Fuel Log -->
            <div class="card section-panel">
                <div class="section-panel-header" style="background:linear-gradient(135deg, #d97706, #f59e0b)">
                    <h6 class="section-panel-title"><i class="bi bi-fuel-pump-fill"></i>Recent Fuel Refills</h6>
                    <a href="fleet_fuel.php" class="btn btn-sm btn-light py-0 fw-semibold">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-compact mb-0">
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Station</th>
                                    <th class="text-end">Litres</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_fuel)): ?>
                                <tr><td colspan="4" class="text-center py-3 text-muted">No fuel entries logged.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_fuel as $rf): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark"><?= htmlspecialchars($rf['reg_no'] ?? '—') ?></span>
                                            <div class="text-muted" style="font-size:0.7rem"><?= date('d M', strtotime($rf['fuel_date'])) ?></div>
                                        </td>
                                        <td><div class="text-truncate" style="max-width: 100px;" title="<?= htmlspecialchars($rf['fuel_company'] ?? '—') ?>"><?= htmlspecialchars($rf['fuel_company'] ?? '—') ?></div></td>
                                        <td class="text-end fw-semibold"><?= number_format((float)$rf['litres'], 1) ?> L</td>
                                        <td class="text-end fw-bold text-danger">₹<?= number_format((float)$rf['amount'], 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 3. Purchase Orders Status Card -->
            <div class="card section-panel">
                <div class="section-panel-header">
                    <h6 class="section-panel-title"><i class="bi bi-file-earmark-text-fill"></i>Purchase Orders</h6>
                    <a href="fleet_purchase_orders.php" class="btn btn-sm btn-light py-0 fw-semibold">All POs</a>
                </div>
                <div class="card-body p-3">
                    <?php
                    $po_disp = [
                        ['Draft',     $po_stats['draft']     ?? 0, '#6b7280'],
                        ['Approved',  $po_stats['approved']  ?? 0, '#2563eb'],
                        ['Partial',   $po_stats['partial']   ?? 0, '#3b82f6'],
                        ['Received',  $po_stats['received']  ?? 0, '#7c3aed'],
                        ['Cancelled', $po_stats['cancelled'] ?? 0, '#dc2626'],
                    ];
                    $max_po = max(array_column($po_disp, 1) ?: [1]);
                    ?>
                    <div class="row g-2 text-center mb-3">
                        <?php foreach ($po_disp as [$label, $cnt, $col]): ?>
                        <div class="col">
                            <div style="font-size:1.15rem;font-weight:800;color:<?= $col ?>"><?= $cnt ?></div>
                            <div class="text-muted" style="font-size:.68rem"><?= $label ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($po_disp as [$label, $cnt, $col]): ?>
                    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.8rem">
                        <span style="width:68px;color:#64748b"><?= $label ?></span>
                        <div class="flex-grow-1 rounded" style="height:6px;background:#f1f5f9">
                            <div style="height:6px;border-radius:3px;background:<?= $col ?>;width:<?= $max_po > 0 ? round($cnt/$max_po*100) : 0 ?>%"></div>
                        </div>
                        <span style="width:20px;text-align:right;font-weight:700"><?= $cnt ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="d-flex justify-content-between mt-3 pt-2 border-top small text-muted">
                        <span>Total: <strong><?= $po_stats['total'] ?? 0 ?> POs</strong></span>
                        <span>Approved: <strong class="text-primary"><?= formatLakhs($po_stats['approved_value'] ?? 0) ?></strong></span>
                    </div>
                </div>
            </div>

        </div><!-- /right col -->

    </div><!-- /main workspace row -->

</div><!-- /container -->

<!-- ══════════ STATUS UPDATE MODAL ══════════ -->
<div class="modal fade" id="statusModal" tabindex="-1">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-arrow-repeat me-2"></i>Update Status</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
    <input type="hidden" name="update_status" value="1">
    <input type="hidden" name="vehicle_id" id="sm_vid">
    <div class="modal-body">
        <div class="mb-3">
            <div class="fw-bold fs-6" id="sm_vno"></div>
            <div class="text-muted small" id="sm_cur_status"></div>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">New Status</label>
            <select name="new_status" id="sm_status" class="form-select">
                <option>Active</option>
                <option>In Repair</option>
                <option>Idle</option>
                <option>Disposed</option>
            </select>
        </div>
        <div>
            <label class="form-label small fw-semibold">Notes / Reason</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Optional reason..."></textarea>
        </div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary px-3">Update</button>
    </div>
    </form>
</div>
</div>
</div>

<script>
// Filter vehicles in grid
document.querySelectorAll('#statusFilter .btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#statusFilter .btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const f = this.dataset.filter;
        document.querySelectorAll('#vehicleGrid [data-vstatus]').forEach(c => {
            c.style.display = (f === 'all' || c.dataset.vstatus === f) ? '' : 'none';
        });
    });
});

function openStatusModal(vid, reg_no, cur_status, new_status) {
    document.getElementById('sm_vid').value              = vid;
    document.getElementById('sm_vno').textContent        = reg_no;
    document.getElementById('sm_cur_status').textContent = 'Current: ' + cur_status;
    document.getElementById('sm_status').value           = new_status;
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

// Render Monthly Performance Chart
(function() {
    const ctx = document.getElementById('monthlyTrendChart');
    if (!ctx) return;

    const labels = <?= json_encode($trend_labels) ?>;
    const tripsData = <?= json_encode($trend_trips) ?>;
    const freightData = <?= json_encode($trend_freight_lakhs) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Trips Completed',
                    data: tripsData,
                    backgroundColor: 'rgba(37, 99, 235, 0.75)',
                    borderRadius: 6,
                    yAxisID: 'y'
                },
                {
                    label: 'Freight (₹ Lakhs)',
                    data: freightData,
                    type: 'line',
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.15)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.35,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            if (ctx.dataset.label.includes('Freight')) {
                                return ` Freight: ₹${ctx.parsed.y} L`;
                            }
                            return ` Trips: ${ctx.parsed.y}`;
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: { display: true, text: 'Trips', font: { size: 10 } },
                    grid: { color: 'rgba(226, 232, 240, 0.6)' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: { display: true, text: '₹ in Lakhs', font: { size: 10 } },
                    grid: { drawOnChartArea: false }
                }
            }
        }
    });
})();

// Render Fleet Status Donut Chart
(function() {
    const ctx = document.getElementById('fleetStatusChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Active', 'In Repair', 'Idle', 'Disposed'],
            datasets: [{
                data: [
                    <?= (int)($status_counts['Active'] ?? 0) ?>,
                    <?= (int)($status_counts['In Repair'] ?? 0) ?>,
                    <?= (int)($status_counts['Idle'] ?? 0) ?>,
                    <?= (int)($status_counts['Disposed'] ?? 0) ?>
                ],
                backgroundColor: ['#2563eb', '#f59e0b', '#6366f1', '#64748b'],
                borderWidth: 2,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ` ${ctx.label}: ${ctx.parsed} vehicles`;
                        }
                    }
                }
            }
        }
    });
})();
</script>

<?php include '../includes/footer.php'; ?>

