<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

$db = getDB();

$__uid = (int) ($_SESSION['user_id'] ?? 0);
$__arow = $db->query("SELECT is_agent, view_all_despatch FROM app_users WHERE id=$__uid LIMIT 1")->fetch_assoc();
$is_agent = !isAdmin() && !empty($__arow['is_agent']) && empty($__arow['view_all_despatch']);
$agent_uid = $__uid;

// AJAX Status Change handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_status_change'])) {
    header('Content-Type: application/json');

    $id = (int) ($_POST['id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $allowed = ['In Transit', 'Delivered'];

    if (!$id || !in_array($new_status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    $row = $db->query("SELECT status, total_weight FROM despatch_orders WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    $valid = ($row['status'] === 'Despatched' && $new_status === 'In Transit')
        || ($row['status'] === 'In Transit' && $new_status === 'Delivered');
    if (!$valid) {
        echo json_encode(['success' => false, 'message' => 'Invalid transition']);
        exit;
    }

    if ($new_status === 'Delivered' && (float) ($row['total_weight'] ?? 0) <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please open the challan and enter delivered quantity before marking Delivered.']);
        exit;
    }

    $db->query("UPDATE despatch_orders SET status='" . $db->real_escape_string($new_status) . "' WHERE id=$id");
    echo json_encode(['success' => true, 'new_status' => $new_status]);
    exit;
}

$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_updated_at = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='despatch_orders'
    AND COLUMN_NAME='updated_at' LIMIT 1")->num_rows > 0;

$_dash_desp_filter = '';
if (!canViewAll('despatch')) {
    $_dash_desp_filter = $is_agent
        ? " AND (created_by=$agent_uid OR agent_id=$agent_uid)"
        : " AND created_by=$__uid";
}

// ─── Primary KPIs ───
$today_count = (int)($db->query("SELECT COUNT(*) c FROM despatch_orders WHERE DATE(despatch_date)=CURDATE() AND status!='Cancelled'" . $_dash_desp_filter)->fetch_assoc()['c'] ?? 0);
$today_mt = (float)($db->query("SELECT COALESCE(SUM(total_weight),0) w FROM despatch_orders WHERE DATE(despatch_date)=CURDATE() AND status!='Cancelled'" . $_dash_desp_filter)->fetch_assoc()['w'] ?? 0);

$transit_count = (int)($db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='In Transit'" . $_dash_desp_filter)->fetch_assoc()['c'] ?? 0);
$transit_mt = (float)($db->query("SELECT COALESCE(SUM(total_weight),0) w FROM despatch_orders WHERE status='In Transit'" . $_dash_desp_filter)->fetch_assoc()['w'] ?? 0);

$po_pending = (int)($db->query("SELECT COUNT(*) c FROM purchase_orders WHERE status IN ('Draft','Approved')")->fetch_assoc()['c'] ?? 0);

$delivered_month_freight = (float)($db->query("SELECT COALESCE(SUM(freight_amount),0) f FROM despatch_orders WHERE status='Delivered' AND total_weight > 0 AND MONTH(despatch_date)=MONTH(CURDATE()) AND YEAR(despatch_date)=YEAR(CURDATE())" . $_dash_desp_filter)->fetch_assoc()['f'] ?? 0);
$delivered_month_sales_ex_gst = (float)($db->query("SELECT COALESCE(SUM(subtotal),0) s FROM despatch_orders WHERE status='Delivered' AND total_weight > 0 AND MONTH(despatch_date)=MONTH(CURDATE()) AND YEAR(despatch_date)=YEAR(CURDATE())" . $_dash_desp_filter)->fetch_assoc()['s'] ?? 0);

if ($has_updated_at) {
    $delivered_today = (int)($db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Delivered' AND DATE(updated_at)=CURDATE()" . $_dash_desp_filter)->fetch_assoc()['c'] ?? 0);
} else {
    $delivered_today = (int)($db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Delivered' AND DATE(despatch_date)=CURDATE()" . $_dash_desp_filter)->fetch_assoc()['c'] ?? 0);
}

// ─── Attention / Exceptions ───
$missing_weight_count = (int)($db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Delivered' AND (total_weight <= 0 OR total_weight IS NULL) AND despatch_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $_dash_desp_filter)->fetch_assoc()['c'] ?? 0);
$delayed_transit_count = (int)($db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='In Transit' AND despatch_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)" . $_dash_desp_filter)->fetch_assoc()['c'] ?? 0);
$draft_challan_count = (int)($db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Draft'" . $_dash_desp_filter)->fetch_assoc()['c'] ?? 0);

$pending_batch_count = 0;
$pending_batch_amount = 0.0;
$has_tp = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='transporter_payments' LIMIT 1")->num_rows > 0;
if ($has_tp && canDo('transporter_payments', 'view')) {
    $pb_res = $db->query("SELECT COUNT(DISTINCT payment_batch_no) c, COALESCE(SUM(net_payable),0) s FROM transporter_payments WHERE status='Pending' AND payment_batch_no IS NOT NULL AND payment_batch_no != ''")->fetch_assoc();
    $pending_batch_count = (int)($pb_res['c'] ?? 0);
    $pending_batch_amount = (float)($pb_res['s'] ?? 0);
}

// ─── 15-Day Trend for Chart.js ───
$chart_days = [];
for ($i = 14; $i >= 0; $i--) {
    $dt = date('Y-m-d', strtotime("-$i days"));
    $chart_days[$dt] = [
        'label' => date('j M', strtotime($dt)),
        'desp_mt' => 0.0,
        'deliv_mt' => 0.0,
        'loads' => 0,
    ];
}

$trend_sql = "
    SELECT DATE(despatch_date) AS d_date,
           SUM(CASE WHEN status != 'Cancelled' THEN total_weight ELSE 0 END) AS desp_mt,
           SUM(CASE WHEN status = 'Delivered' THEN total_weight ELSE 0 END) AS deliv_mt,
           COUNT(CASE WHEN status != 'Cancelled' THEN 1 END) AS load_count
    FROM despatch_orders
    WHERE despatch_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
      AND despatch_date <= CURDATE()
      " . $_dash_desp_filter . "
    GROUP BY DATE(despatch_date)
";
$trend_res = $db->query($trend_sql);
if ($trend_res) {
    while ($tr = $trend_res->fetch_assoc()) {
        $d = $tr['d_date'];
        if (isset($chart_days[$d])) {
            $chart_days[$d]['desp_mt'] = round((float)$tr['desp_mt'], 2);
            $chart_days[$d]['deliv_mt'] = round((float)$tr['deliv_mt'], 2);
            $chart_days[$d]['loads'] = (int)$tr['load_count'];
        }
    }
}

$chart_labels = [];
$chart_desp_mt = [];
$chart_deliv_mt = [];
$chart_loads = [];
foreach ($chart_days as $cd) {
    $chart_labels[] = $cd['label'];
    $chart_desp_mt[] = $cd['desp_mt'];
    $chart_deliv_mt[] = $cd['deliv_mt'];
    $chart_loads[] = $cd['loads'];
}

// ─── Fleet Trip Status Pulse ───
$fleet_pulse = ['On Road' => 0, 'Loading' => 0, 'Completed' => 0, 'Planned' => 0];
$has_fleet_trips = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' LIMIT 1")->num_rows > 0;
if ($has_fleet_trips && canDo('fleet_trips', 'view')) {
    $ft_rows = $db->query("SELECT status, COUNT(*) c FROM fleet_trips WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY status")->fetch_all(MYSQLI_ASSOC);
    foreach ($ft_rows as $ft) {
        $st = $ft['status'] ?? '';
        if (isset($fleet_pulse[$st])) $fleet_pulse[$st] = (int)$ft['c'];
        elseif ($st === 'Draft' || $st === 'Created') $fleet_pulse['Planned'] += (int)$ft['c'];
    }
}

// ─── Recent Despatches (Last 7 Days) ───
$recent_despatches = $db->query("
    SELECT d.*, t.transporter_name,
           COALESCE(v.vendor_name, d.consignee_name) AS display_consignee,
           si.sales_inv_no,
           si.sales_inv_status,
           tp_info.tp_payment_no,
           tp_info.tp_batch_no
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN (
        SELECT challan_id,
               GROUP_CONCAT(DISTINCT invoice_number SEPARATOR ', ') AS sales_inv_no,
               MAX(status) AS sales_inv_status
        FROM sales_invoices
        WHERE challan_id IS NOT NULL AND status NOT IN ('Cancelled', 'Draft')
        GROUP BY challan_id
    ) si ON si.challan_id = d.id
    LEFT JOIN (
        SELECT despatch_id,
               GROUP_CONCAT(DISTINCT payment_no SEPARATOR ', ') AS tp_payment_no,
               GROUP_CONCAT(DISTINCT payment_batch_no SEPARATOR ', ') AS tp_batch_no
        FROM transporter_payments
        WHERE despatch_id IS NOT NULL AND status != 'Cancelled'
        GROUP BY despatch_id
    ) tp_info ON tp_info.despatch_id = d.id
    WHERE d.despatch_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      AND d.status != 'Cancelled'" . $_dash_desp_filter . "
    ORDER BY d.despatch_date DESC, d.id DESC
")->fetch_all(MYSQLI_ASSOC);

$badges = ['Draft' => 'secondary', 'Despatched' => 'primary', 'In Transit' => 'warning', 'Delivered' => 'success', 'Cancelled' => 'danger'];
$statusCounts = ['All' => count($recent_despatches), 'In Transit' => 0, 'Despatched' => 0, 'Delivered' => 0, 'Draft' => 0];
foreach ($recent_despatches as $r) {
    $st = $r['status'];
    if (isset($statusCounts[$st])) $statusCounts[$st]++;
    else $statusCounts[$st] = 1;
}

$smtp_settings = $db->query("SELECT smtp_host, smtp_user, smtp_pass FROM company_settings LIMIT 1")->fetch_assoc() ?: [];
$smtp_ready = !empty(trim($smtp_settings['smtp_host'] ?? ''))
    && !empty(trim($smtp_settings['smtp_user'] ?? ''))
    && !empty(trim($smtp_settings['smtp_pass'] ?? ''));

include 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-speedometer2 me-1"></i>Dashboard';</script>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold text-dark"><i class="bi bi-grid-1x2-fill text-primary me-2"></i>Operations Command Center</h4>
        <small class="text-muted d-none d-sm-inline">Real-time overview of daily despatches, fleet movement, and active challans</small>
    </div>
</div>

<!-- Top Command & Action Bar -->
<div class="dms-command-bar mb-3">
    <div class="dms-command-search">
        <i class="bi bi-search dms-search-icon"></i>
        <input type="text" id="dmsQuickSearch" class="form-control dms-search-input"
               placeholder="Universal Search: Challan #, Vehicle #, Transporter, Consignee, City..."
               autocomplete="off">
        <span class="dms-search-badge" id="searchMatchCount" style="display:none">0 matches</span>
    </div>
    <div class="dms-command-actions">
        <div class="dropdown">
            <button class="btn btn-primary dms-btn-create dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-plus-lg me-1"></i>Quick Create
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                <?php if (canDo('despatch', 'add')): ?>
                <li>
                    <a class="dropdown-item py-2" href="modules/despatch.php?action=add">
                        <i class="bi bi-send-plus text-primary me-2 fs-6"></i><strong>New Despatch Challan</strong>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (canDo('fleet_trips', 'add')): ?>
                <li>
                    <a class="dropdown-item py-2" href="modules/fleet_trips.php?action=add">
                        <i class="bi bi-truck-front text-success me-2 fs-6"></i><strong>New Fleet Trip Order</strong>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (canDo('purchase_orders', 'add')): ?>
                <li>
                    <a class="dropdown-item py-2" href="modules/purchase_orders.php?action=add">
                        <i class="bi bi-cart-plus text-warning me-2 fs-6"></i><strong>New Purchase Order</strong>
                    </a>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item py-2" href="modules/payment_register.php">
                        <i class="bi bi-journal-check text-info me-2 fs-6"></i>Payment Register & Advice
                    </a>
                </li>
                <li>
                    <a class="dropdown-item py-2" href="modules/fleet_status.php">
                        <i class="bi bi-geo-alt-fill text-danger me-2 fs-6"></i>Vehicle Live Board
                    </a>
                </li>
            </ul>
        </div>
        <a href="modules/despatch.php" class="btn btn-outline-primary fw-semibold">
            <i class="bi bi-collection me-1"></i>All Despatches
        </a>
    </div>
</div>

<!-- Attention Required Smart Exception Bar -->
<?php
$has_attention = ($missing_weight_count > 0 || $pending_batch_count > 0 || $delayed_transit_count > 0 || $draft_challan_count > 0 || !$smtp_ready);
?>
<?php if ($has_attention): ?>
<div class="dms-attention-card mb-4">
    <div class="dms-attention-title">
        <i class="bi bi-lightning-charge-fill text-warning me-2"></i>
        <strong>Action Center (Attention Needed)</strong>
    </div>
    <div class="dms-attention-pills">
        <?php if ($missing_weight_count > 0): ?>
        <a href="modules/exception_dashboard.php" class="dms-pill dms-pill-danger" title="Delivered challans missing delivered weight confirmation">
            <i class="bi bi-exclamation-octagon-fill me-1"></i>
            <strong><?= $missing_weight_count ?></strong> Missing Delivered Weight
        </a>
        <?php endif; ?>

        <?php if ($pending_batch_count > 0): ?>
        <a href="modules/payment_register.php" class="dms-pill dms-pill-warning" title="Payment batches waiting for authorization">
            <i class="bi bi-hourglass-split me-1"></i>
            <strong><?= $pending_batch_count ?></strong> Batches Pending Authorisation (₹<?= number_format($pending_batch_amount, 0) ?>)
        </a>
        <?php endif; ?>

        <?php if ($delayed_transit_count > 0): ?>
        <a href="modules/despatch.php?status=In+Transit" class="dms-pill dms-pill-amber" title="Trucks in transit for more than 3 days">
            <i class="bi bi-clock-history me-1"></i>
            <strong><?= $delayed_transit_count ?></strong> In-Transit &gt; 3 Days
        </a>
        <?php endif; ?>

        <?php if ($draft_challan_count > 0): ?>
        <a href="modules/despatch.php?status=Draft" class="dms-pill dms-pill-slate" title="Draft orders waiting for dispatch">
            <i class="bi bi-file-earmark-text me-1"></i>
            <strong><?= $draft_challan_count ?></strong> Draft Challans
        </a>
        <?php endif; ?>

        <?php if (!$smtp_ready && canDo('company_settings', 'view')): ?>
        <a href="modules/company_settings.php" class="dms-pill dms-pill-info" title="Configure email for sending automatic reports">
            <i class="bi bi-envelope-exclamation me-1"></i>
            Setup Report SMTP
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Bento Grid Top Row: Key Metrics -->
<div class="row g-3 mb-4">
    <!-- Today's Movement -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="dms-bento-card dms-bento-today h-100">
            <div class="dms-bento-card__head">
                <span class="dms-bento-card__label">Today's Despatches</span>
                <span class="dms-bento-badge bg-primary-subtle text-primary fw-bold"><i class="bi bi-calendar-check me-1"></i>Today</span>
            </div>
            <div class="dms-bento-card__metric">
                <span class="dms-bento-card__value text-primary"><?= number_format($today_count) ?></span>
                <span class="dms-bento-card__unit">loads</span>
            </div>
            <div class="dms-bento-card__footer">
                <span class="dms-bento-chip"><strong><?= number_format($today_mt, 3) ?></strong> MT Dispatched</span>
                <span class="text-muted small ms-auto"><?= $delivered_today ?> delivered today</span>
            </div>
        </div>
    </div>

    <!-- Live In-Transit -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="dms-bento-card dms-bento-transit h-100">
            <div class="dms-bento-card__head">
                <span class="dms-bento-card__label">Active On-Road</span>
                <span class="dms-bento-badge bg-warning-subtle text-warning fw-bold"><i class="bi bi-truck me-1"></i>Moving</span>
            </div>
            <div class="dms-bento-card__metric">
                <span class="dms-bento-card__value text-warning-emphasis"><?= number_format($transit_count) ?></span>
                <span class="dms-bento-card__unit">trucks</span>
            </div>
            <div class="dms-bento-card__footer">
                <span class="dms-bento-chip"><strong><?= number_format($transit_mt, 3) ?></strong> MT In-Transit</span>
                <a href="modules/despatch.php?status=In+Transit" class="text-decoration-none small text-warning-emphasis ms-auto fw-bold">View Map &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Delivered Month Freight -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="dms-bento-card dms-bento-finance h-100">
            <div class="dms-bento-card__head">
                <span class="dms-bento-card__label">Month Freight (Delivered)</span>
                <span class="dms-bento-badge bg-success-subtle text-success fw-bold"><i class="bi bi-currency-rupee me-1"></i><?= date('M Y') ?></span>
            </div>
            <div class="dms-bento-card__metric">
                <span class="dms-bento-card__value text-success fs-3">₹<?= number_format($delivered_month_freight, 0) ?></span>
            </div>
            <div class="dms-bento-card__footer">
                <span class="text-muted small">Sales (ex-GST): <strong>₹<?= number_format($delivered_month_sales_ex_gst, 0) ?></strong></span>
            </div>
        </div>
    </div>

    <!-- Fleet & Approvals Pulse -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="dms-bento-card dms-bento-fleet h-100">
            <div class="dms-bento-card__head">
                <span class="dms-bento-card__label">Fleet &amp; Workflow Pulse</span>
                <span class="dms-bento-badge bg-info-subtle text-info fw-bold"><i class="bi bi-speedometer2 me-1"></i>Live</span>
            </div>
            <div class="dms-fleet-pulse-grid mt-2">
                <div class="dms-fleet-stat">
                    <span class="dms-fleet-stat__num text-primary"><?= (int)$fleet_pulse['On Road'] ?></span>
                    <span class="dms-fleet-stat__lbl">Trips On Road</span>
                </div>
                <div class="dms-fleet-stat">
                    <span class="dms-fleet-stat__num text-success"><?= (int)$fleet_pulse['Completed'] ?></span>
                    <span class="dms-fleet-stat__lbl">Trips Completed</span>
                </div>
                <div class="dms-fleet-stat">
                    <span class="dms-fleet-stat__num text-danger"><?= (int)$po_pending ?></span>
                    <span class="dms-fleet-stat__lbl">Open POs</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bento Grid Middle Row: 15-Day Movement Trend & Quick Launch -->
<div class="row g-3 mb-4">
    <!-- 15-Day Chart -->
    <div class="col-12 col-lg-8">
        <div class="dms-bento-panel h-100">
            <div class="dms-bento-panel__head d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-bar-chart-fill text-primary me-2"></i>15-Day Despatch &amp; Delivered Movement (MT)</h6>
                    <small class="text-muted">Daily tonnage dispatched vs delivery completions (Last 15 Days)</small>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-circle-fill me-1" style="font-size:8px"></i>Dispatched MT</span>
                    <span class="badge bg-success-subtle text-success border"><i class="bi bi-circle-fill me-1" style="font-size:8px"></i>Delivered MT</span>
                </div>
            </div>
            <div class="dms-bento-panel__body p-3">
                <div style="position:relative; height: 210px; width:100%;">
                    <canvas id="dmsMovementChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Operations Quick Hub -->
    <div class="col-12 col-lg-4">
        <div class="dms-bento-panel h-100 d-flex flex-column">
            <div class="dms-bento-panel__head">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-grid-fill text-primary me-2"></i>Operations Quick Launch</h6>
                <small class="text-muted">Most frequent management shortcuts</small>
            </div>
            <div class="dms-bento-panel__body p-3 d-flex flex-column gap-2 flex-grow-1 justify-content-between">
                <a href="modules/despatch.php" class="dms-quick-hub-item">
                    <div class="dms-quick-icon bg-primary text-white"><i class="bi bi-send-check"></i></div>
                    <div class="dms-quick-info">
                        <strong>Despatch Orders</strong>
                        <span>Create challans, track weights, and print MTC</span>
                    </div>
                    <i class="bi bi-chevron-right text-muted ms-auto"></i>
                </a>

                <a href="modules/fleet_status.php" class="dms-quick-hub-item">
                    <div class="dms-quick-icon bg-success text-white"><i class="bi bi-geo-alt"></i></div>
                    <div class="dms-quick-info">
                        <strong>Vehicle Live Status</strong>
                        <span>Track moving fleet, loading, and idle status</span>
                    </div>
                    <i class="bi bi-chevron-right text-muted ms-auto"></i>
                </a>

                <a href="modules/payment_register.php" class="dms-quick-hub-item">
                    <div class="dms-quick-icon bg-warning text-dark"><i class="bi bi-cash-coin"></i></div>
                    <div class="dms-quick-info">
                        <strong>Payment Register</strong>
                        <span>Authorise bulk batches &amp; print advice (EN/HI)</span>
                    </div>
                    <i class="bi bi-chevron-right text-muted ms-auto"></i>
                </a>

                <a href="modules/fleet_trips.php?action=list" class="dms-quick-hub-item">
                    <div class="dms-quick-icon bg-info text-white"><i class="bi bi-signpost-split"></i></div>
                    <div class="dms-quick-info">
                        <strong>Fleet Trip Orders</strong>
                        <span>Plan and dispatch multi-point fleet trips</span>
                    </div>
                    <i class="bi bi-chevron-right text-muted ms-auto"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Tabbed Operations Feed -->
<section class="dms-feed-card shadow-sm">
    <div class="dms-feed-head">
        <div class="dms-feed-title-wrap">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-activity text-primary me-2"></i>Active Operations Feed</h5>
            <small class="text-muted">Real-time status updates and order movement</small>
        </div>

        <!-- Filter Segmented Tabs -->
        <div class="dms-feed-tabs" id="feedTabs">
            <button class="dms-feed-tab active" data-tab="All">
                All <span class="badge bg-secondary ms-1"><?= (int)$statusCounts['All'] ?></span>
            </button>
            <button class="dms-feed-tab" data-tab="In Transit">
                <i class="bi bi-truck me-1"></i>In Transit <span class="badge bg-warning text-dark ms-1"><?= (int)($statusCounts['In Transit'] ?? 0) ?></span>
            </button>
            <button class="dms-feed-tab" data-tab="Today">
                <i class="bi bi-calendar-event me-1"></i>Today <span class="badge bg-primary ms-1"><?= (int)$today_count ?></span>
            </button>
            <button class="dms-feed-tab" data-tab="Attention">
                <i class="bi bi-exclamation-triangle me-1"></i>Attention <span class="badge bg-danger ms-1"><?= ($missing_weight_count + $delayed_transit_count) ?></span>
            </button>
            <button class="dms-feed-tab" data-tab="Delivered">
                <i class="bi bi-check2-circle me-1"></i>Delivered <span class="badge bg-success ms-1"><?= (int)($statusCounts['Delivered'] ?? 0) ?></span>
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle dms-feed-table" id="dmsFeedTable">
            <thead>
                <tr>
                    <th style="width:13%">Challan No</th>
                    <th style="width:10%">Date</th>
                    <th style="width:20%">Consignee &amp; Destination</th>
                    <th style="width:16%">Transporter</th>
                    <th style="width:12%">Vehicle No</th>
                    <th style="width:9%" class="text-end">Weight (MT)</th>
                    <th style="width:10%">Status</th>
                    <th style="width:10%" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_despatches)): ?>
                    <tr id="emptyFeedRow">
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary opacity-50"></i>
                            No despatches recorded in the last 7 days.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_despatches as $row):
                        $st = $row['status'];
                        $id = (int)$row['id'];
                        $is_today = (date('Y-m-d', strtotime($row['despatch_date'])) === date('Y-m-d'));
                        $weight = (float)$row['total_weight'];
                        $is_missing_weight = ($st === 'Delivered' && $weight <= 0);
                        $is_delayed = ($st === 'In Transit' && strtotime($row['despatch_date']) <= strtotime('-3 days'));
                        $needs_attention = ($is_missing_weight || $is_delayed);
                        $sales_inv   = trim($row['sales_inv_no'] ?? '');
                        $is_billed   = ($sales_inv !== '');
                        $inv_title   = $sales_inv !== '' ? ('Sales Invoice: ' . $sales_inv) : 'Not Billed';
                    ?>
                    <tr class="dms-row"
                        data-id="<?= $id ?>"
                        data-status="<?= htmlspecialchars($st) ?>"
                        data-today="<?= $is_today ? '1' : '0' ?>"
                        data-attention="<?= $needs_attention ? '1' : '0' ?>"
                        data-search="<?= htmlspecialchars(strtolower($row['challan_no'] . ' ' . $row['display_consignee'] . ' ' . ($row['consignee_city'] ?? '') . ' ' . ($row['transporter_name'] ?? '') . ' ' . ($row['vehicle_no'] ?? '') . ' ' . $sales_inv . ' ' . ($is_billed ? 'billed' : 'not billed unbilled') . ' ' . $st)) ?>">
                        <td>
                            <a href="modules/despatch.php?action=edit&id=<?= $id ?>" class="fw-bold text-primary text-decoration-none">
                                <?= htmlspecialchars($row['challan_no']) ?>
                            </a>
                        </td>
                        <td class="text-muted fw-semibold">
                            <?= date('d/m/Y', strtotime($row['despatch_date'])) ?>
                            <?php if ($is_today): ?><span class="badge bg-primary-subtle text-primary ms-1" style="font-size:9px">Today</span><?php endif; ?>
                        </td>
                        <td>
                            <strong class="text-dark d-block text-truncate" style="max-width: 220px;"><?= htmlspecialchars($row['display_consignee']) ?></strong>
                            <?php if (!empty($row['consignee_city'])): ?>
                                <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($row['consignee_city']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="text-secondary fw-semibold"><?= htmlspecialchars($row['transporter_name'] ?: '—') ?></span>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($row['vehicle_no'] ?: '—') ?></span>
                        </td>
                        <td class="text-end fw-bold">
                            <?php if ($weight > 0): ?>
                                <span class="text-dark"><?= number_format($weight, 3) ?> MT</span>
                            <?php elseif ($st === 'Delivered'): ?>
                                <span class="badge bg-danger-subtle text-danger">Missing MT</span>
                            <?php else: ?>
                                <span class="text-muted">0.000</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex flex-column gap-1 align-items-start">
                                <span class="badge bg-<?= $badges[$st] ?? 'secondary' ?> status-badge"><?= htmlspecialchars($st) ?></span>
                                <?php if ($is_billed): ?>
                                    <span class="badge badge-billed" title="<?= htmlspecialchars($inv_title) ?>">
                                        <i class="bi bi-check2-circle me-1"></i>Billed
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-not-billed">
                                        <i class="bi bi-receipt-cutoff me-1"></i>Not Billed
                                    </span>
                                <?php endif; ?>
                                <?php if ($is_delayed): ?>
                                    <small class="badge bg-danger-subtle text-danger">&gt;3 Days Transit</small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-end">
                            <?php if ($st === 'Despatched'): ?>
                                <button class="btn btn-warning btn-sm dms-act-btn" onclick="changeStatus(<?= $id ?>,'In Transit',this)" title="Mark truck as In Transit">
                                    <i class="bi bi-truck"></i> In Transit
                                </button>
                            <?php elseif ($st === 'In Transit'): ?>
                                <a href="modules/despatch.php?action=edit&id=<?= $id ?>" class="btn btn-info btn-sm text-dark dms-act-btn" title="Enter actual delivered weight">
                                    <i class="bi bi-check-circle me-1"></i>Deliver
                                </a>
                            <?php else: ?>
                                <a href="modules/despatch.php?action=edit&id=<?= $id ?>" class="btn btn-outline-secondary btn-sm dms-act-btn">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Confirm Status Change Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-2 bg-primary text-white">
                <h6 class="modal-title mb-0" id="statusModalTitle"><i class="bi bi-check-circle me-1"></i>Confirm Status</h6>
                <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3" id="statusModalBody"></div>
            <div class="modal-footer py-2 bg-light">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm fw-bold" id="statusModalConfirm">Yes, Confirm</button>
            </div>
        </div>
    </div>
</div>

<style>
/* ─── Bento Grid & Modern Dashboard Styling ─── */
.dms-command-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.dms-command-search {
    position: relative;
    flex: 1;
    min-width: 280px;
    max-width: 680px;
}

.dms-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 1rem;
}

.dms-search-input {
    padding-left: 40px;
    padding-right: 90px;
    height: 44px;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    background: #fff;
    font-size: 0.92rem;
    transition: all 0.2s;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}

.dms-search-input:focus {
    border-color: #1E3A8A;
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15);
    background: #fff;
}

.dms-search-badge {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.75rem;
    background: #e2e8f0;
    color: #334155;
    padding: 2px 8px;
    border-radius: 6px;
    font-weight: 600;
}

.dms-command-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.dms-btn-create {
    height: 44px;
    padding: 0 18px;
    border-radius: 10px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
}

/* Attention Action Card */
.dms-attention-card {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.08);
}

.dms-attention-title {
    font-size: 0.9rem;
    color: #92400e;
    font-weight: 700;
}

.dms-attention-pills {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.dms-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 0.8rem;
    text-decoration: none;
    font-weight: 500;
    transition: transform 0.15s, box-shadow 0.15s;
}

.dms-pill:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.dms-pill-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.dms-pill-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.dms-pill-amber { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
.dms-pill-slate { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
.dms-pill-info { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }

/* Bento Cards */
.dms-bento-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 18px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
    transition: transform 0.2s, box-shadow 0.2s;
}

.dms-bento-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
}

.dms-bento-card__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.dms-bento-card__label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.dms-bento-badge {
    font-size: 0.72rem;
    padding: 3px 8px;
    border-radius: 6px;
}

.dms-bento-card__metric {
    display: flex;
    align-items: baseline;
    gap: 6px;
    margin: 4px 0 10px;
}

.dms-bento-card__value {
    font-size: 2.2rem;
    font-weight: 800;
    line-height: 1;
}

.dms-bento-card__unit {
    font-size: 0.95rem;
    color: #64748b;
    font-weight: 600;
}

.dms-bento-card__footer {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-top: 10px;
    border-top: 1px solid #f1f5f9;
}

.dms-bento-chip {
    font-size: 0.78rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 2px 8px;
    border-radius: 6px;
    color: #334155;
}

/* Fleet Pulse mini-grid */
.dms-fleet-pulse-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}

.dms-fleet-stat {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px;
    text-align: center;
}

.dms-fleet-stat__num {
    display: block;
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1.1;
}

.dms-fleet-stat__lbl {
    font-size: 0.7rem;
    color: #64748b;
    font-weight: 600;
}

/* Bento Panel */
.dms-bento-panel {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}

.dms-bento-panel__head {
    padding: 14px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

/* Quick Hub Items */
.dms-quick-hub-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    text-decoration: none;
    color: #1e293b;
    transition: all 0.18s;
}

.dms-quick-hub-item:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateX(3px);
    color: #1E3A8A;
}

.dms-quick-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.dms-quick-info {
    display: flex;
    flex-direction: column;
}

.dms-quick-info strong { font-size: 0.88rem; line-height: 1.2; }
.dms-quick-info span { font-size: 0.75rem; color: #64748b; }

/* Tabbed Operations Feed */
.dms-feed-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.dms-feed-head {
    padding: 16px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.dms-feed-tabs {
    display: flex;
    gap: 6px;
    background: #e2e8f0;
    padding: 4px;
    border-radius: 8px;
    flex-wrap: wrap;
}

.dms-feed-tab {
    border: none;
    background: transparent;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s;
}

.dms-feed-tab.active {
    background: #fff;
    color: #1E3A8A;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

.dms-feed-table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
}

.dms-feed-table tbody td {
    padding: 10px 14px;
    font-size: 0.88rem;
    border-bottom: 1px solid #f1f5f9;
}

.dms-act-btn {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 600;
}

/* Dark mode compatibility */
body.dark-mode .dms-bento-card,
body.dark-mode .dms-bento-panel,
body.dark-mode .dms-feed-card {
    background: #1e293b;
    border-color: #334155;
    color: #f1f5f9;
}

body.dark-mode .dms-bento-panel__head,
body.dark-mode .dms-feed-head,
body.dark-mode .dms-feed-table thead th {
    background: #0f172a;
    border-color: #334155;
    color: #94a3b8;
}

body.dark-mode .dms-search-input {
    background: #0f172a;
    border-color: #334155;
    color: #f1f5f9;
}

/* Vibrant badges for Billed and Not Billed */
.badge-billed {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 0.24em 0.55em !important;
    border-radius: 5px !important;
    letter-spacing: 0.02em;
    font-size: 0.72rem !important;
    display: inline-flex;
    align-items: center;
}
.badge-not-billed {
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%) !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 0.24em 0.55em !important;
    border-radius: 5px !important;
    letter-spacing: 0.02em;
    font-size: 0.72rem !important;
    box-shadow: 0 1px 4px rgba(234, 88, 12, 0.35);
    display: inline-flex;
    align-items: center;
}
</style>

<script>
// ─── Initialize Chart.js Trend ───
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('dmsMovementChart');
    if (ctx) {
        const labels = <?= json_encode($chart_labels) ?>;
        const despData = <?= json_encode($chart_desp_mt) ?>;
        const delivData = <?= json_encode($chart_deliv_mt) ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Dispatched (MT)',
                        data: despData,
                        backgroundColor: 'rgba(30, 58, 138, 0.75)',
                        borderColor: '#1E3A8A',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.65,
                    },
                    {
                        label: 'Delivered (MT)',
                        data: delivData,
                        backgroundColor: 'rgba(22, 163, 74, 0.75)',
                        borderColor: '#16a34a',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.65,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 10,
                        titleFont: { size: 12, weight: 'bold' },
                        bodyFont: { size: 12 },
                        cornerRadius: 6,
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + Number(ctx.raw).toFixed(3) + ' MT';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 }, color: '#64748b' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 11 },
                            color: '#64748b',
                            callback: function(val) { return val + ' MT'; }
                        }
                    }
                }
            }
        });
    }

    // ─── Tab & Universal Search Filter Logic ───
    let currentTab = 'All';
    const searchInput = document.getElementById('dmsQuickSearch');
    const matchBadge = document.getElementById('searchMatchCount');
    const tabs = document.querySelectorAll('.dms-feed-tab');
    const rows = document.querySelectorAll('#dmsFeedTable tbody tr.dms-row');

    function applyFilters() {
        const query = (searchInput.value || '').trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach(function (row) {
            const status = row.dataset.status;
            const isToday = row.dataset.today === '1';
            const isAttention = row.dataset.attention === '1';
            const searchData = row.dataset.search || '';

            let tabMatch = false;
            if (currentTab === 'All') tabMatch = true;
            else if (currentTab === 'In Transit' && status === 'In Transit') tabMatch = true;
            else if (currentTab === 'Today' && isToday) tabMatch = true;
            else if (currentTab === 'Attention' && isAttention) tabMatch = true;
            else if (currentTab === 'Delivered' && status === 'Delivered') tabMatch = true;

            const queryMatch = (query === '' || searchData.indexOf(query) !== -1);

            if (tabMatch && queryMatch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (query !== '') {
            matchBadge.style.display = 'inline-block';
            matchBadge.textContent = visibleCount + ' match' + (visibleCount === 1 ? '' : 'es');
        } else {
            matchBadge.style.display = 'none';
        }
    }

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            currentTab = btn.dataset.tab;
            applyFilters();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }
});

// ─── Status Confirmation Modal & AJAX Handler ───
let _pendingId = null;
let _pendingStatus = null;
let _pendingBtn = null;

function changeStatus(id, newStatus, btn) {
    _pendingId = id;
    _pendingStatus = newStatus;
    _pendingBtn = btn;

    const row = btn.closest('tr');
    const challanNo = row.querySelector('td:first-child').innerText.trim();

    document.getElementById('statusModalBody').innerHTML =
        '<div class="text-center py-2">' +
            '<div class="fs-1 text-warning mb-2"><i class="bi bi-truck"></i></div>' +
            '<p class="mb-0">Mark Challan <strong>#' + challanNo + '</strong> as <span class="badge bg-warning text-dark">' + newStatus + '</span>?</p>' +
        '</div>';
    document.getElementById('statusModalTitle').innerHTML = '<i class="bi bi-truck me-1"></i>Move to In Transit';
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

document.getElementById('statusModalConfirm').addEventListener('click', function () {
    const modalEl = document.getElementById('statusModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    const btn = _pendingBtn;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    const fd = new FormData();
    fd.append('ajax_status_change', '1');
    fd.append('id', _pendingId);
    fd.append('new_status', _pendingStatus);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                const row = btn.closest('tr');
                const badges = { 'Despatched': 'primary', 'In Transit': 'warning', 'Delivered': 'success' };
                row.querySelector('.status-badge').className =
                    'badge bg-' + (badges[data.new_status] || 'secondary') + ' status-badge';
                row.querySelector('.status-badge').textContent = data.new_status;
                row.dataset.status = data.new_status;

                const td = btn.parentElement;
                if (data.new_status === 'In Transit') {
                    td.innerHTML = '<a href="modules/despatch.php?action=edit&id=' + _pendingId + '" class="btn btn-info btn-sm text-dark dms-act-btn"><i class="bi bi-check-circle me-1"></i>Deliver</a>';
                }
                setTimeout(function () { location.reload(); }, 600);
            } else {
                alert('Error: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-truck"></i> In Transit';
            }
        })
        .catch(function () {
            alert('Network error. Please refresh.');
            btn.disabled = false;
        });
});
</script>

<?php include 'includes/footer.php'; ?>
