<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_lease_agent_helper.php';

$db = getDB();

// Access check: allow admins, users with lease/trip/report permissions, or lease agent users
if (!canDo('fleet_lease_agents', 'view') && !canDo('fleet_trips', 'view') && !canDo('reports', 'view') && !isLeaseAgentUser()) {
    requirePerm('fleet_lease_agents', 'view');
}

fleetEnsureLeaseAgents($db);
fleetEnsureLeaseTripFields($db);
fleetEnsureLeaseAgentPayments($db);

$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_trip_end_date = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' AND COLUMN_NAME='end_date' LIMIT 1")->num_rows > 0;
$trip_period_expr = $has_trip_end_date
    ? "CASE WHEN t.end_date IS NOT NULL AND CAST(t.end_date AS CHAR) <> '0000-00-00' THEN t.end_date ELSE t.trip_date END"
    : "t.trip_date";

$is_lease_user = isLeaseAgentUser();
$current_agent_id = currentLeaseAgentId();

// Period Filter
$period = $_GET['period'] ?? 'mtd';
$custom_month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $custom_month)) $custom_month = date('Y-m');

$currMonth   = (int)date('n');
$currYear    = (int)date('Y');
$fyStartYear = ($currMonth >= 4) ? $currYear : ($currYear - 1);
$fyStartDate = "{$fyStartYear}-04-01";
$fyEndDate   = date('Y-m-d');

switch ($period) {
    case 'prev_month':
        $date_start   = date('Y-m-01', strtotime('first day of last month'));
        $date_end     = date('Y-m-t', strtotime('last day of last month'));
        $period_label = date('F Y', strtotime('first day of last month'));
        break;
    case 'last3m':
        $date_start   = date('Y-m-01', strtotime('-2 months'));
        $date_end     = date('Y-m-t');
        $period_label = 'Last 3 Months (' . date('M Y', strtotime($date_start)) . ' &ndash; ' . date('M Y') . ')';
        break;
    case 'ytd':
        $date_start   = $fyStartDate;
        $date_end     = date('Y-m-d');
        $period_label = 'FY ' . substr((string)$fyStartYear, 2) . '-' . substr((string)($fyStartYear + 1), 2) . ' (YTD)';
        break;
    case 'custom_month':
        $date_start   = $custom_month . '-01';
        $date_end     = date('Y-m-t', strtotime($date_start));
        $period_label = date('F Y', strtotime($date_start));
        break;
    case 'all':
        $date_start   = '2020-01-01';
        $date_end     = '2099-12-31';
        $period_label = 'All Time';
        break;
    case 'mtd':
    default:
        $period       = 'mtd';
        $date_start   = date('Y-m-01');
        $date_end     = date('Y-m-t');
        $period_label = date('F Y') . ' (MTD)';
        break;
}

// Agent Filter
$agent_filter = (int)($_GET['lease_agent_id'] ?? 0);
if ($is_lease_user) {
    $agent_filter = $current_agent_id;
}

// Load all agents for selector dropdown
$all_agents_sql = "SELECT id, agent_code, agent_name, default_margin_per_mt, tax_type, tax_rate, status
                   FROM fleet_lease_agents
                   ORDER BY status ASC, agent_name ASC";
$all_agents = $db->query($all_agents_sql)->fetch_all(MYSQLI_ASSOC);

// Base trip WHERE clause
$trip_where = "WHERE t.status != 'Cancelled' AND $trip_period_expr BETWEEN '$date_start' AND '$date_end'";
if ($agent_filter > 0) {
    $trip_where .= " AND t.lease_agent_id = $agent_filter";
} else {
    $trip_where .= " AND COALESCE(t.lease_agent_id, 0) > 0";
}

// ── 1. Overall Summary Stats ──
$stats_query = "SELECT
    COUNT(*) AS total_trips,
    SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_trips,
    SUM(CASE WHEN t.status = 'In Transit' THEN 1 ELSE 0 END) AS transit_trips,
    SUM(CASE WHEN t.status = 'Planned' THEN 1 ELSE 0 END) AS planned_trips,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN t.total_weight ELSE 0 END), 0) AS total_weight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN t.freight_amount ELSE 0 END), 0) AS gross_freight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN t.lease_agent_amount ELSE 0 END), 0) AS lease_margin,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) ELSE 0 END), 0) AS net_freight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN COALESCE(t.lease_agent_misc_deduction, 0) ELSE 0 END), 0) AS total_misc_deduction,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN (COALESCE(t.lease_agent_fuel,0) + COALESCE(t.lease_agent_driver_advance,0) + COALESCE(t.lease_agent_driver_fooding,0) + COALESCE(t.lease_agent_toll,0) + COALESCE(t.lease_agent_misc_expense,0)) ELSE 0 END), 0) AS total_agent_expenses,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN COALESCE(t.lease_agent_profit, 0) ELSE 0 END), 0) AS total_agent_profit,
    SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN 1 ELSE 0 END) AS billed_trips_count,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) ELSE 0 END), 0) AS billed_net_freight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(t.lease_agent_misc_deduction, 0) ELSE 0 END), 0) AS billed_misc_deduction,
    SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN 1 ELSE 0 END) AS unbilled_trips_count,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) ELSE 0 END), 0) AS unbilled_net_freight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN COALESCE(t.lease_agent_misc_deduction, 0) ELSE 0 END), 0) AS unbilled_misc_deduction,
    COUNT(DISTINCT t.lease_agent_id) AS distinct_agents_count,
    COUNT(DISTINCT t.vehicle_id) AS distinct_vehicles_count
FROM fleet_trips t
LEFT JOIN fleet_lease_agents la ON t.lease_agent_id = la.id
$trip_where";
$overview_stats = $db->query($stats_query)->fetch_assoc();

// Calculate Tax & Gross Payable (Total, Billed, and Unbilled)
$tax_calc_query = "SELECT
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) * ((CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END) / 100) ELSE 0 END), 0) AS total_tax_amount,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) * (COALESCE(la.tax_rate, 0) / 100) ELSE 0 END), 0) AS billed_tax_amount,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) * (COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) / 100) ELSE 0 END), 0) AS unbilled_tax_amount
FROM fleet_trips t
LEFT JOIN fleet_lease_agents la ON t.lease_agent_id = la.id
LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
$trip_where";
$tax_calc = $db->query($tax_calc_query)->fetch_assoc();

$total_tax_amount    = (float)($tax_calc['total_tax_amount'] ?? 0);
$billed_tax_amount   = (float)($tax_calc['billed_tax_amount'] ?? 0);
$unbilled_tax_amount = (float)($tax_calc['unbilled_tax_amount'] ?? 0);
$total_net_freight      = (float)($overview_stats['net_freight'] ?? 0);
$total_misc_ded         = (float)($overview_stats['total_misc_deduction'] ?? 0);
$total_gross_payable    = max(0, $total_net_freight + $total_tax_amount - $total_misc_ded);

$billed_net_freight     = (float)($overview_stats['billed_net_freight'] ?? 0);
$billed_misc_ded        = (float)($overview_stats['billed_misc_deduction'] ?? 0);
$billed_gross_payable   = max(0, $billed_net_freight + $billed_tax_amount - $billed_misc_ded);

$unbilled_net_freight   = (float)($overview_stats['unbilled_net_freight'] ?? 0);
$unbilled_misc_ded      = (float)($overview_stats['unbilled_misc_deduction'] ?? 0);
$unbilled_gross_payable = max(0, $unbilled_net_freight + $unbilled_tax_amount - $unbilled_misc_ded);

// ── 2. Payment Stats in Period ──
$pmt_where = "WHERE p.payment_date BETWEEN '$date_start' AND '$date_end' AND p.status != 'Cancelled'";
if ($agent_filter > 0) {
    $pmt_where .= " AND p.lease_agent_id = $agent_filter";
}
$pmt_stats = $db->query("SELECT
    COALESCE(SUM(p.amount), 0) AS total_paid,
    COUNT(*) AS payments_count
FROM fleet_lease_agent_payments p
$pmt_where")->fetch_assoc();
$total_paid = (float)($pmt_stats['total_paid'] ?? 0);
$total_balance_due  = $total_gross_payable - $total_paid;
$billed_balance_due = $billed_gross_payable - $total_paid;

// ── 3. Agent-wise Matrix / Performance Breakdown ──
$agent_matrix_sql = "SELECT
    la.id AS agent_id,
    la.agent_code,
    la.agent_name,
    la.contact_person,
    la.mobile,
    la.billing_model,
    la.tax_type,
    la.tax_rate,
    la.status AS agent_status,
    COUNT(t.id) AS total_trips,
    SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_trips,
    SUM(CASE WHEN t.status = 'In Transit' THEN 1 ELSE 0 END) AS transit_trips,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN t.total_weight ELSE 0 END), 0) AS total_weight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN t.freight_amount ELSE 0 END), 0) AS gross_freight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN t.lease_agent_amount ELSE 0 END), 0) AS lease_margin,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) ELSE 0 END), 0) AS net_freight,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END) * ((CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END) / 100) ELSE 0 END), 0) AS tax_amount,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN COALESCE(t.lease_agent_misc_deduction, 0) ELSE 0 END), 0) AS misc_deduction,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN (COALESCE(t.lease_agent_fuel,0) + COALESCE(t.lease_agent_driver_advance,0) + COALESCE(t.lease_agent_driver_fooding,0) + COALESCE(t.lease_agent_toll,0) + COALESCE(t.lease_agent_misc_expense,0)) ELSE 0 END), 0) AS total_expenses,
    COALESCE(SUM(CASE WHEN t.status = 'Completed' THEN COALESCE(t.lease_agent_profit, 0) ELSE 0 END), 0) AS total_profit,
    SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN 1 ELSE 0 END) AS billed_count,
    SUM(CASE WHEN t.status = 'Completed' AND COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN 1 ELSE 0 END) AS unbilled_count,
    COUNT(DISTINCT t.vehicle_id) AS linked_vehicles
FROM fleet_lease_agents la
LEFT JOIN fleet_trips t ON la.id = t.lease_agent_id AND t.status != 'Cancelled' AND $trip_period_expr BETWEEN '$date_start' AND '$date_end'
LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
" . ($agent_filter > 0 ? "WHERE la.id = $agent_filter" : "") . "
GROUP BY la.id, la.agent_code, la.agent_name, la.contact_person, la.mobile, la.billing_model, la.tax_type, la.tax_rate, la.status
ORDER BY total_trips DESC, la.agent_name ASC";
$agent_matrix = $db->query($agent_matrix_sql)->fetch_all(MYSQLI_ASSOC);

// Map payments per agent in period for matrix table
$agent_payments_map = [];
$agent_pmt_query = "SELECT p.lease_agent_id, COALESCE(SUM(p.amount), 0) AS paid_amt
                    FROM fleet_lease_agent_payments p
                    $pmt_where
                    GROUP BY p.lease_agent_id";
$agent_pmt_res = $db->query($agent_pmt_query);
if ($agent_pmt_res) {
    while ($pr = $agent_pmt_res->fetch_assoc()) {
        $agent_payments_map[(int)$pr['lease_agent_id']] = (float)$pr['paid_amt'];
    }
}

// ── 4. 6-Month Trend Data for Chart.js ──
$chart_months = [];
for ($i = 5; $i >= 0; $i--) {
    $m_key = date('Y-m', strtotime("-$i months"));
    $m_start = $m_key . '-01';
    $m_end = date('Y-m-t', strtotime($m_start));
    $m_label = date('M Y', strtotime($m_start));

    $m_trip_where = "WHERE t.status = 'Completed' AND $trip_period_expr BETWEEN '$m_start' AND '$m_end'";
    if ($agent_filter > 0) {
        $m_trip_where .= " AND t.lease_agent_id = $agent_filter";
    } else {
        $m_trip_where .= " AND COALESCE(t.lease_agent_id, 0) > 0";
    }

    $m_stats = $db->query("SELECT
        COUNT(*) AS trips,
        COALESCE(SUM(t.total_weight), 0) AS weight,
        COALESCE(SUM(t.freight_amount), 0) AS gross_freight,
        COALESCE(SUM(t.lease_agent_amount), 0) AS margin,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, (t.freight_amount - COALESCE(t.lease_agent_amount, 0))) END), 0) AS net_freight,
        COALESCE(SUM(COALESCE(t.lease_agent_fuel, 0)), 0) AS fuel,
        COALESCE(SUM(COALESCE(t.lease_agent_driver_advance, 0)), 0) AS driver_advance,
        COALESCE(SUM(COALESCE(t.lease_agent_driver_fooding, 0)), 0) AS fooding,
        COALESCE(SUM(COALESCE(t.lease_agent_toll, 0)), 0) AS toll,
        COALESCE(SUM(COALESCE(t.lease_agent_misc_expense, 0)), 0) AS misc_expense,
        COALESCE(SUM(COALESCE(t.lease_agent_fuel,0) + COALESCE(t.lease_agent_driver_advance,0) + COALESCE(t.lease_agent_driver_fooding,0) + COALESCE(t.lease_agent_toll,0) + COALESCE(t.lease_agent_misc_expense,0)), 0) AS total_expenses,
        COALESCE(SUM(COALESCE(t.lease_agent_profit, 0)), 0) AS profit
    FROM fleet_trips t
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id = la.id
    $m_trip_where")->fetch_assoc();

    $m_pmt_where = "WHERE p.payment_date BETWEEN '$m_start' AND '$m_end' AND p.status != 'Cancelled'";
    if ($agent_filter > 0) {
        $m_pmt_where .= " AND p.lease_agent_id = $agent_filter";
    }
    $m_pmt = $db->query("SELECT COALESCE(SUM(p.amount), 0) AS paid FROM fleet_lease_agent_payments p $m_pmt_where")->fetch_assoc();

    $chart_months[] = [
        'month'          => $m_label,
        'trips'          => (int)($m_stats['trips'] ?? 0),
        'weight'         => round((float)($m_stats['weight'] ?? 0), 2),
        'gross_freight'  => round((float)($m_stats['gross_freight'] ?? 0), 2),
        'margin'         => round((float)($m_stats['margin'] ?? 0), 2),
        'net_freight'    => round((float)($m_stats['net_freight'] ?? 0), 2),
        'fuel'           => round((float)($m_stats['fuel'] ?? 0), 2),
        'driver_advance' => round((float)($m_stats['driver_advance'] ?? 0), 2),
        'fooding'        => round((float)($m_stats['fooding'] ?? 0), 2),
        'toll'           => round((float)($m_stats['toll'] ?? 0), 2),
        'misc_expense'   => round((float)($m_stats['misc_expense'] ?? 0), 2),
        'total_expenses' => round((float)($m_stats['total_expenses'] ?? 0), 2),
        'profit'         => round((float)($m_stats['profit'] ?? 0), 2),
        'paid'           => round((float)($m_pmt['paid'] ?? 0), 2),
    ];
}

// ── 5. Active & Recent Trips Feed (Top 10) ──
$recent_trips_sql = "SELECT
    t.id, t.trip_no, t.trip_date, t.status, t.total_weight, t.freight_amount,
    t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
    t.lease_agent_billing_status, t.lease_agent_invoice_no,
    t.customer_name,
    v.reg_no,
    COALESCE(NULLIF(TRIM(t.lease_agent_name), ''), la.agent_name, CONCAT('Agent #', t.lease_agent_id)) AS agent_name,
    co.company_name
FROM fleet_trips t
LEFT JOIN fleet_vehicles v ON t.vehicle_id = v.id
LEFT JOIN fleet_lease_agents la ON t.lease_agent_id = la.id
LEFT JOIN companies co ON t.company_id = co.id
$trip_where
ORDER BY $trip_period_expr DESC, t.id DESC
LIMIT 10";
$recent_trips = $db->query($recent_trips_sql)->fetch_all(MYSQLI_ASSOC);

// ── 6. Recent Payments Feed (Top 6) ──
$recent_pmts_sql = "SELECT
    p.id, p.payment_no, p.payment_date, p.amount, p.total_payable, p.balance_amount,
    p.payment_mode, p.reference_no, p.utr_no, p.bank_name, p.status,
    la.agent_name, la.agent_code
FROM fleet_lease_agent_payments p
LEFT JOIN fleet_lease_agents la ON p.lease_agent_id = la.id
$pmt_where
ORDER BY p.payment_date DESC, p.id DESC
LIMIT 6";
$recent_payments = $db->query($recent_pmts_sql)->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-speedometer2 me-2"></i>Lease Agent Dashboard';</script>

<!-- Top Hero Header & Navigation Switcher -->
<div class="tp-hero-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-white bg-opacity-25 text-white"><i class="bi bi-person-badge me-1"></i>Fleet Logistics</span>
            <span class="badge bg-teal-subtle text-white border border-white border-opacity-25">Operations &amp; Settlements</span>
        </div>
        <h4 class="mb-0 fw-bold text-white"><i class="bi bi-speedometer2 me-2"></i>Lease Agent Dashboard</h4>
        <small class="text-white text-opacity-75">
            <?= $is_lease_user ? 'Real-time overview of your trips, tonnage, margins, and settlement status' : 'Comprehensive executive overview of lease agent fleet operations, earnings, and dues' ?>
        </small>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="fleet_lease_agent_dashboard.php" class="tp-module-pill active">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>
        <?php if (!$is_lease_user && canDo('fleet_lease_agents', 'view')): ?>
        <a href="fleet_lease_agents.php" class="tp-module-pill">
            <i class="bi bi-person-badge"></i>
            <span>Agent Master</span>
        </a>
        <?php endif; ?>
        <?php if (canDo('fleet_trips', 'view') || $is_lease_user): ?>
        <a href="fleet_lease_agent_payments.php" class="tp-module-pill">
            <i class="bi bi-cash-stack"></i>
            <span>Payments</span>
        </a>
        <?php endif; ?>
        <a href="fleet_lease_agent_report.php" class="tp-module-pill">
            <i class="bi bi-file-earmark-bar-graph"></i>
            <span>Report</span>
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="card mb-4 border-0 shadow-sm rounded-3">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-center" id="dashFilterForm">
            <!-- Period Selector -->
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label small fw-bold text-secondary text-uppercase mb-1">Period</label>
                <select name="period" class="form-select form-select-sm" onchange="toggleCustomMonth(this.value); this.form.submit();">
                    <option value="mtd" <?= $period === 'mtd' ? 'selected' : '' ?>>Current Month (MTD)</option>
                    <option value="prev_month" <?= $period === 'prev_month' ? 'selected' : '' ?>>Previous Month</option>
                    <option value="last3m" <?= $period === 'last3m' ? 'selected' : '' ?>>Last 3 Months</option>
                    <option value="ytd" <?= $period === 'ytd' ? 'selected' : '' ?>>FY <?= substr((string)$fyStartYear, 2) ?>-<?= substr((string)($fyStartYear + 1), 2) ?> (YTD)</option>
                    <option value="custom_month" <?= $period === 'custom_month' ? 'selected' : '' ?>>Specific Month...</option>
                    <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All Time</option>
                </select>
            </div>

            <!-- Custom Month Input (Conditional) -->
            <div class="col-12 col-sm-6 col-md-2" id="customMonthWrap" style="<?= $period === 'custom_month' ? '' : 'display:none;' ?>">
                <label class="form-label small fw-bold text-secondary text-uppercase mb-1">Month</label>
                <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($custom_month) ?>" onchange="this.form.submit();">
            </div>

            <!-- Lease Agent Selector -->
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label small fw-bold text-secondary text-uppercase mb-1">Lease Agent</label>
                <?php if ($is_lease_user): ?>
                    <input type="hidden" name="lease_agent_id" value="<?= (int)$agent_filter ?>">
                    <div class="form-control form-control-sm bg-light fw-bold text-dark d-flex align-items-center">
                        <i class="bi bi-lock-fill text-muted me-2"></i>
                        <?php
                        $my_name = 'My Agent Profile';
                        foreach ($all_agents as $a) {
                            if ($a['id'] == $agent_filter) {
                                $my_name = $a['agent_name'] . ' (' . $a['agent_code'] . ')';
                                break;
                            }
                        }
                        echo htmlspecialchars($my_name);
                        ?>
                    </div>
                <?php else: ?>
                    <select name="lease_agent_id" class="form-select form-select-sm" onchange="this.form.submit();">
                        <option value="0" <?= $agent_filter === 0 ? 'selected' : '' ?>>All Lease Agents</option>
                        <?php foreach ($all_agents as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $agent_filter === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['agent_name'] . ' (' . $a['agent_code'] . ')') ?>
                            <?= $a['status'] === 'Inactive' ? ' [Inactive]' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <!-- Quick Action Links -->
            <div class="col-12 col-md-<?= $period === 'custom_month' ? '3' : '5' ?> text-md-end pt-3 pt-md-0">
                <div class="d-inline-flex align-items-center gap-2 flex-wrap justify-content-end">
                    <span class="badge bg-light text-dark border px-2 py-1">
                        <i class="bi bi-calendar-event me-1"></i><?= $period_label ?>
                    </span>
                    <a href="fleet_lease_agent_report.php?action=print_unbilled<?= $agent_filter > 0 ? '&lease_agent_id=' . $agent_filter : '' ?>&month=<?= urlencode($period === 'custom_month' ? $custom_month : date('Y-m')) ?>"
                       target="_blank" class="btn btn-sm btn-outline-danger fw-semibold">
                        <i class="bi bi-printer me-1"></i>Unbilled Statement
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EXECUTIVE STAT KPI TILES
══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <!-- Total Trips & Weight -->
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-month p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Completed Trips / Weight</div>
                    <h4 class="fw-bold text-primary mb-0 mt-1">
                        <?= number_format((int)$overview_stats['completed_trips']) ?> <span class="fs-6 fw-normal text-muted">Trips</span>
                    </h4>
                    <div class="text-secondary fw-semibold small mt-1">
                        <i class="bi bi-truck me-1"></i><?= number_format((float)$overview_stats['total_weight'], 3) ?> MT
                    </div>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-signpost-split-fill"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-primary border-opacity-10 d-flex align-items-center justify-content-between small text-muted">
                <span>In Transit: <strong><?= number_format((int)$overview_stats['transit_trips']) ?></strong></span>
                <span>Planned: <strong><?= number_format((int)$overview_stats['planned_trips']) ?></strong></span>
            </div>
        </div>
    </div>

    <!-- Gross Freight & Lease Margin -->
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-hold p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Commission</div>
                    <h4 class="fw-bold text-warning mb-0 mt-1" style="color:#b45309 !important">
                        ₹<?= number_format((float)$overview_stats['lease_margin'], 2) ?>
                    </h4>
                    <div class="text-secondary small mt-1">
                        Gross: ₹<?= number_format((float)$overview_stats['gross_freight'], 2) ?>
                    </div>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-coin"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-warning border-opacity-10 d-flex align-items-center justify-content-between small text-muted">
                <span>Margin Share:</span>
                <span class="fw-bold text-dark">
                    <?php
                        $selected_agent_margin = null;
                        if ($agent_filter > 0) {
                            foreach ($all_agents as $ag) {
                                if ((int)$ag['id'] === $agent_filter) {
                                    $selected_agent_margin = (float)($ag['default_margin_per_mt'] ?? 0);
                                    break;
                                }
                            }
                        }
                        $display_margin_per_mt = ($selected_agent_margin !== null)
                            ? $selected_agent_margin
                            : ((float)$overview_stats['total_weight'] > 0 ? ((float)$overview_stats['lease_margin'] / (float)$overview_stats['total_weight']) : 0.0);
                    ?>
                    ₹<?= number_format((float)$display_margin_per_mt, 2) ?> / MT
                </span>
            </div>
        </div>
    </div>

    <!-- Total Net Payable (Net Freight + Tax) -->
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-paid p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Total Gross Payable</div>
                    <h4 class="fw-bold text-success mb-0 mt-1">
                        ₹<?= number_format($total_gross_payable, 2) ?>
                    </h4>
                    <div class="text-secondary small mt-1">
                        Net: ₹<?= number_format($total_net_freight, 2) ?>
                        <?php if ($total_tax_amount > 0): ?>
                        <span class="badge badge-soft-success ms-1">+₹<?= number_format($total_tax_amount, 2) ?> Tax</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-cash-stack"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-success border-opacity-10 d-flex align-items-center justify-content-between small text-muted">
                <span>Paid: <strong class="text-success">₹<?= number_format($total_paid, 2) ?></strong></span>
                <span>(<?= $pmt_stats['payments_count'] ?> pmt<?= $pmt_stats['payments_count'] != 1 ? 's' : '' ?>)</span>
            </div>
        </div>
    </div>

    <!-- Outstanding Balance / Settlement Status -->
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-outstanding p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Balance Due / Outstanding</div>
                    <h4 class="fw-bold <?= $total_balance_due > 0 ? 'text-danger' : 'text-success' ?> mb-0 mt-1">
                        <?= $total_balance_due < 0 ? '(Credit) ' : '' ?>₹<?= number_format(abs($total_balance_due), 2) ?>
                    </h4>
                    <div class="text-secondary small mt-1">
                        Unbilled: <strong><?= number_format((int)$overview_stats['unbilled_trips_count']) ?></strong> trips (₹<?= number_format($unbilled_gross_payable, 2) ?>)
                    </div>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-danger border-opacity-10 d-flex align-items-center justify-content-between small">
                <span class="text-muted">Billed Due: <strong>₹<?= number_format(abs($billed_balance_due), 2) ?></strong></span>
                <span class="badge badge-soft-primary"><?= number_format((int)$overview_stats['billed_trips_count']) ?> Billed</span>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ANALYTICS: 6-MONTH EVOLUTION & BILLING BREAKDOWN
══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <!-- 6-Month Visual Trend Chart -->
    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm h-100 rounded-3 overflow-hidden">
            <div class="card-header d-flex justify-content-between align-items-center py-2 px-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff;">
                <span class="fw-bold text-white fs-6">
                    <i class="bi bi-graph-up-arrow me-2 text-warning"></i>6-Month Performance Trend
                </span>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-white bg-opacity-20 text-white">Trips</span>
                    <span class="badge bg-success text-white">Net Freight</span>
                    <span class="badge bg-warning text-dark fw-semibold">Margin</span>
                </div>
            </div>
            <div class="card-body p-3">
                <div style="position:relative; height:270px; width:100%">
                    <canvas id="leaseTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Operational & Settlement Health Donuts -->
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100 rounded-3 overflow-hidden">
            <div class="card-header py-2 px-3 fw-bold text-white fs-6 d-flex align-items-center" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff;">
                <i class="bi bi-pie-chart-fill me-2 text-info"></i>Billing &amp; Settlement Health
            </div>
            <div class="card-body p-3 d-flex flex-column justify-content-around">
                <!-- Billing Status Gauge -->
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-secondary">Trip Billing Progress</span>
                        <?php
                        $total_comp = (int)$overview_stats['completed_trips'];
                        $billed_c   = (int)$overview_stats['billed_trips_count'];
                        $bill_pct   = $total_comp > 0 ? round(($billed_c / $total_comp) * 100, 1) : 0;
                        ?>
                        <span class="small fw-bold text-primary"><?= $bill_pct ?>% Billed</span>
                    </div>
                    <div class="progress mb-2" style="height: 10px; border-radius: 5px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $bill_pct ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span><i class="bi bi-check2-circle text-primary me-1"></i>Billed: <strong><?= $billed_c ?></strong></span>
                        <span><i class="bi bi-hourglass-split text-warning me-1"></i>Pending: <strong><?= (int)$overview_stats['unbilled_trips_count'] ?></strong></span>
                    </div>
                </div>

                <hr class="my-2 text-muted">

                <!-- Settlement Status Gauge -->
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-secondary">Settlement Ratio</span>
                        <?php
                        $settle_pct = $total_gross_payable > 0 ? min(100, round(($total_paid / $total_gross_payable) * 100, 1)) : 0;
                        ?>
                        <span class="small fw-bold text-success"><?= $settle_pct ?>% Settled</span>
                    </div>
                    <div class="progress mb-2" style="height: 10px; border-radius: 5px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $settle_pct ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span><i class="bi bi-wallet2 text-success me-1"></i>Paid: <strong>₹<?= number_format($total_paid, 2) ?></strong></span>
                        <span><i class="bi bi-exclamation-octagon text-danger me-1"></i>Due: <strong>₹<?= number_format(max(0, $total_balance_due), 2) ?></strong></span>
                    </div>
                </div>

                <div class="mt-3 p-2 bg-light rounded-3 d-flex justify-content-between align-items-center small">
                    <span class="text-secondary"><i class="bi bi-truck me-1"></i>Active Fleet: <strong><?= (int)$overview_stats['distinct_vehicles_count'] ?></strong> vehicles</span>
                    <span class="text-secondary"><i class="bi bi-people me-1"></i>Active Agents: <strong><?= (int)$overview_stats['distinct_agents_count'] ?></strong></span>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     MONTHLY LEASE AGENT P&L STATEMENT (LAST 6 MONTHS)
══════════════════════════════════════════════════════════ -->
<div class="card mb-4 border-0 shadow-sm rounded-3 overflow-hidden">
    <div class="card-header d-flex justify-content-between align-items-center py-2 px-3" style="background: linear-gradient(135deg, #0f766e 0%, #0e7490 100%); color: #ffffff;">
        <span class="fw-bold text-white fs-6">
            <i class="bi bi-calculator me-2 text-warning"></i>Monthly Lease Agent P&amp;L Statement
        </span>
        <small class="text-white text-opacity-75">Formula: Gross Payable &minus; (Fuel + Driver Adv + Fooding + Toll + Misc Exp)</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Month</th>
                    <th class="text-center">Trips</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Gross Payable</th>
                    <th class="text-end">Fuel</th>
                    <th class="text-end">Driver Adv.</th>
                    <th class="text-end">Fooding</th>
                    <th class="text-end">Toll</th>
                    <th class="text-end">Misc Exp.</th>
                    <th class="text-end text-danger fw-semibold">Total Expenses</th>
                    <th class="text-end text-success fw-bold pe-3">Net P&amp;L (Profit)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tot_m_trips = 0;
                $tot_m_weight = 0;
                $tot_m_payable = 0;
                $tot_m_fuel = 0;
                $tot_m_adv = 0;
                $tot_m_food = 0;
                $tot_m_toll = 0;
                $tot_m_misc = 0;
                $tot_m_exp = 0;
                $tot_m_profit = 0;

                foreach (array_reverse($chart_months) as $m):
                    $m_trips   = (int)$m['trips'];
                    $m_weight  = (float)$m['weight'];
                    $m_payable = (float)$m['net_freight'];
                    $m_fuel    = (float)$m['fuel'];
                    $m_adv     = (float)$m['driver_advance'];
                    $m_food    = (float)$m['fooding'];
                    $m_toll    = (float)$m['toll'];
                    $m_misc    = (float)$m['misc_expense'];
                    $m_exp     = (float)$m['total_expenses'];
                    $m_profit  = (float)$m['profit'];
                    if ($m_profit == 0 && $m_exp > 0) {
                        $m_profit = $m_payable - $m_exp;
                    }

                    $tot_m_trips   += $m_trips;
                    $tot_m_weight  += $m_weight;
                    $tot_m_payable += $m_payable;
                    $tot_m_fuel    += $m_fuel;
                    $tot_m_adv     += $m_adv;
                    $tot_m_food    += $m_food;
                    $tot_m_toll    += $m_toll;
                    $tot_m_misc    += $m_misc;
                    $tot_m_exp     += $m_exp;
                    $tot_m_profit  += $m_profit;
                ?>
                <tr>
                    <td class="ps-3 fw-bold text-dark">
                        <i class="bi bi-calendar3 me-1 text-secondary"></i><?= htmlspecialchars($m['month']) ?>
                    </td>
                    <td class="text-center"><span class="badge bg-secondary"><?= number_format($m_trips) ?></span></td>
                    <td class="text-end"><?= number_format($m_weight, 3) ?></td>
                    <td class="text-end fw-semibold">₹<?= number_format($m_payable, 2) ?></td>
                    <td class="text-end text-muted small">₹<?= number_format($m_fuel, 2) ?></td>
                    <td class="text-end text-muted small">₹<?= number_format($m_adv, 2) ?></td>
                    <td class="text-end text-muted small">₹<?= number_format($m_food, 2) ?></td>
                    <td class="text-end text-muted small">₹<?= number_format($m_toll, 2) ?></td>
                    <td class="text-end text-muted small">₹<?= number_format($m_misc, 2) ?></td>
                    <td class="text-end text-danger fw-semibold">₹<?= number_format($m_exp, 2) ?></td>
                    <td class="text-end fw-bold pe-3 <?= $m_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                        <span class="badge <?= $m_profit >= 0 ? 'badge-soft-success' : 'badge-soft-danger' ?> py-1 px-2" style="font-size:.85rem">
                            <i class="bi <?= $m_profit >= 0 ? 'bi-graph-up' : 'bi-graph-down' ?> me-1"></i>
                            <?= $m_profit < 0 ? '-₹' . number_format(abs($m_profit), 2) : '₹' . number_format($m_profit, 2) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold border-top">
                <tr>
                    <td class="ps-3">Totals:</td>
                    <td class="text-center"><?= number_format($tot_m_trips) ?></td>
                    <td class="text-end"><?= number_format($tot_m_weight, 3) ?></td>
                    <td class="text-end">₹<?= number_format($tot_m_payable, 2) ?></td>
                    <td class="text-end">₹<?= number_format($tot_m_fuel, 2) ?></td>
                    <td class="text-end">₹<?= number_format($tot_m_adv, 2) ?></td>
                    <td class="text-end">₹<?= number_format($tot_m_food, 2) ?></td>
                    <td class="text-end">₹<?= number_format($tot_m_toll, 2) ?></td>
                    <td class="text-end">₹<?= number_format($tot_m_misc, 2) ?></td>
                    <td class="text-end text-danger">₹<?= number_format($tot_m_exp, 2) ?></td>
                    <td class="text-end pe-3 <?= $tot_m_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= $tot_m_profit < 0 ? '-₹' . number_format(abs($tot_m_profit), 2) : '₹' . number_format($tot_m_profit, 2) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     AGENT PERFORMANCE MATRIX / LEADERBOARD
══════════════════════════════════════════════════════════ -->
<div class="card mb-4 border-0 shadow-sm rounded-3 overflow-hidden">
    <div class="card-header d-flex justify-content-between align-items-center py-2 px-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff;">
        <span class="fw-bold text-white fs-6">
            <i class="bi bi-table me-2 text-info"></i>Lease Agent Performance &amp; Settlement Matrix
        </span>
        <small class="text-white text-opacity-75">Showing operations for <?= htmlspecialchars($period_label) ?></small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Agent</th>
                    <th>Contact</th>
                    <th class="text-center">Trips</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Gross Freight</th>
                    <th class="text-end">Margin</th>
                    <th class="text-end">Net Payable</th>
                    <th class="text-end">Expenses</th>
                    <th class="text-end text-success">P&amp;L (Profit)</th>
                    <th class="text-end">Paid (Period)</th>
                    <th class="text-end">Balance Due</th>
                    <th class="text-center">Billing Status</th>
                    <th class="text-center pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($agent_matrix)): ?>
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">No lease agent records found for this period.</td>
                </tr>
                <?php else: foreach ($agent_matrix as $row):
                    $aid = (int)$row['agent_id'];
                    $tax_rate = (float)($row['tax_rate'] ?? 0);
                    $net_freight = (float)$row['net_freight'];
                    $agent_misc_ded = (float)($row['misc_deduction'] ?? 0);
                    $agent_tax = (float)($row['tax_amount'] ?? 0);
                    $agent_gross_payable = max(0, $net_freight + $agent_tax - $agent_misc_ded);
                    $agent_paid = (float)($agent_payments_map[$aid] ?? 0);
                    $agent_bal = $agent_gross_payable - $agent_paid;
                ?>
                <tr>
                    <td class="ps-3">
                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['agent_name']) ?></div>
                        <div class="small text-muted">
                            <span class="badge badge-soft-dark"><?= htmlspecialchars($row['agent_code']) ?></span>
                            <?php if ($row['tax_type'] !== 'None' && $tax_rate > 0): ?>
                            <span class="badge badge-soft-primary"><?= htmlspecialchars($row['tax_type']) ?> <?= $tax_rate ?>%</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="small fw-semibold"><?= htmlspecialchars($row['contact_person'] ?: '—') ?></div>
                        <?php if ($row['mobile']): ?>
                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($row['mobile']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary rounded-pill"><?= number_format((int)$row['completed_trips']) ?></span>
                        <?php if ((int)$row['transit_trips'] > 0): ?>
                        <br><small class="text-warning fw-semibold"><?= (int)$row['transit_trips'] ?> in transit</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-secondary fw-semibold"><?= number_format((float)$row['total_weight'], 3) ?></td>
                    <td class="text-end">₹<?= number_format((float)$row['gross_freight'], 2) ?></td>
                    <td class="text-end text-warning fw-semibold" style="color:#b45309 !important">
                        ₹<?= number_format((float)$row['lease_margin'], 2) ?>
                    </td>
                    <td class="text-end fw-bold text-dark">
                        ₹<?= number_format($agent_gross_payable, 2) ?>
                    </td>
                    <td class="text-end text-danger small">
                        ₹<?= number_format((float)$row['total_expenses'], 2) ?>
                    </td>
                    <?php
                        $ag_profit = (float)$row['total_profit'];
                        if ($ag_profit == 0 && (float)$row['total_expenses'] > 0) {
                            $ag_profit = $agent_gross_payable - (float)$row['total_expenses'];
                        }
                    ?>
                    <td class="text-end fw-bold <?= $ag_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= $ag_profit < 0 ? '-₹' . number_format(abs($ag_profit), 2) : '₹' . number_format($ag_profit, 2) ?>
                    </td>
                    <td class="text-end text-success fw-semibold">
                        ₹<?= number_format($agent_paid, 2) ?>
                    </td>
                    <td class="text-end fw-bold <?= $agent_bal > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $agent_bal < 0 ? '(Credit) ' : '' ?>₹<?= number_format(abs($agent_bal), 2) ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['unbilled_count'] > 0): ?>
                        <span class="badge badge-not-billed"><i class="bi bi-receipt-cutoff me-1"></i><?= (int)$row['unbilled_count'] ?> Unbilled</span>
                        <?php else: ?>
                        <span class="badge badge-soft-success"><i class="bi bi-check2-circle me-1"></i>All Billed</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center pe-3">
                        <div class="btn-group btn-group-sm">
                            <a href="fleet_lease_agent_report.php?lease_agent_id=<?= $aid ?>&month=<?= urlencode($period === 'custom_month' ? $custom_month : date('Y-m')) ?>"
                               class="btn btn-outline-primary" title="View Detailed Report">
                                <i class="bi bi-file-earmark-text"></i>
                            </a>
                            <?php if (canDo('fleet_trips', 'view') || isLeaseAgentUser()): ?>
                            <a href="fleet_lease_agent_payments.php?action=add&lease_agent_id=<?= $aid ?>"
                               class="btn btn-outline-success" title="Record Payment">
                                <i class="bi bi-cash-stack"></i>
                            </a>
                            <?php endif; ?>
                            <a href="fleet_lease_agent_report.php?action=print_unbilled&lease_agent_id=<?= $aid ?>&month=<?= urlencode($period === 'custom_month' ? $custom_month : date('Y-m')) ?>"
                               target="_blank" class="btn btn-outline-danger" title="Print Unbilled Statement">
                                <i class="bi bi-printer"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="table-light fw-bold border-top">
                <tr>
                    <td colspan="2" class="ps-3">Totals:</td>
                    <td class="text-center"><?= number_format((int)$overview_stats['completed_trips']) ?></td>
                    <td class="text-end"><?= number_format((float)$overview_stats['total_weight'], 3) ?></td>
                    <td class="text-end">₹<?= number_format((float)$overview_stats['gross_freight'], 2) ?></td>
                    <td class="text-end text-warning" style="color:#b45309 !important">₹<?= number_format((float)$overview_stats['lease_margin'], 2) ?></td>
                    <td class="text-end">₹<?= number_format($total_gross_payable, 2) ?></td>
                    <td class="text-end text-danger">₹<?= number_format((float)($overview_stats['total_agent_expenses'] ?? 0), 2) ?></td>
                    <?php
                        $tot_ov_profit = (float)($overview_stats['total_agent_profit'] ?? 0);
                        if ($tot_ov_profit == 0 && (float)($overview_stats['total_agent_expenses'] ?? 0) > 0) {
                            $tot_ov_profit = $total_gross_payable - (float)$overview_stats['total_agent_expenses'];
                        }
                    ?>
                    <td class="text-end <?= $tot_ov_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= $tot_ov_profit < 0 ? '-₹' . number_format(abs($tot_ov_profit), 2) : '₹' . number_format($tot_ov_profit, 2) ?>
                    </td>
                    <td class="text-end text-success">₹<?= number_format($total_paid, 2) ?></td>
                    <td class="text-end <?= $total_balance_due > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $total_balance_due < 0 ? '(Credit) ' : '' ?>₹<?= number_format(abs($total_balance_due), 2) ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     RECENT TRIPS & RECENT PAYMENTS FEEDS
══════════════════════════════════════════════════════════ -->
<div class="row g-3">
    <!-- Recent Trips Feed -->
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100 rounded-3 overflow-hidden">
            <div class="card-header d-flex justify-content-between align-items-center py-2 px-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff;">
                <span class="fw-bold text-white fs-6"><i class="bi bi-clock-history me-2 text-info"></i>Recent Lease Fleet Trips</span>
                <a href="fleet_trips.php" class="text-white text-decoration-none small opacity-75">View All Trips &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Trip / Date</th>
                            <th>Vehicle &amp; Route</th>
                            <th>Agent</th>
                            <th class="text-end">Weight</th>
                            <th class="text-end">Net Freight</th>
                            <th class="text-center pe-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_trips)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No trips recorded in this period.</td></tr>
                        <?php else: foreach ($recent_trips as $t):
                            $st_badge = ['Completed'=>'success','In Transit'=>'warning','Planned'=>'primary','Cancelled'=>'danger'][$t['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td class="ps-3">
                                <a href="fleet_trips.php?action=view&id=<?= $t['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                    <?= htmlspecialchars($t['trip_no']) ?>
                                </a>
                                <div class="small text-muted"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($t['reg_no'] ?: '—') ?></div>
                                <div class="small text-muted text-truncate" style="max-width:180px" title="<?= htmlspecialchars($t['customer_name'] ?? '') ?>">
                                    <?= htmlspecialchars($t['customer_name'] ?: '—') ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-soft-dark"><?= htmlspecialchars($t['agent_name']) ?></span>
                            </td>
                            <td class="text-end text-secondary"><?= number_format((float)$t['total_weight'], 3) ?></td>
                            <td class="text-end fw-semibold">₹<?= number_format((float)($t['net_freight_amount'] ?: ($t['freight_amount'] - $t['lease_agent_amount'])), 2) ?></td>
                            <td class="text-center pe-3">
                                <span class="badge bg-<?= $st_badge ?>"><?= htmlspecialchars($t['status']) ?></span>
                                <?php if ($t['lease_agent_billing_status'] === 'Billed'): ?>
                                <br><span class="badge badge-soft-primary mt-1" style="font-size:9px">Billed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Payments Feed -->
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100 rounded-3 overflow-hidden">
            <div class="card-header d-flex justify-content-between align-items-center py-2 px-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff;">
                <span class="fw-bold text-white fs-6"><i class="bi bi-cash-coin me-2 text-success"></i>Recent Payments Disbursed</span>
                <a href="fleet_lease_agent_payments.php" class="text-white text-decoration-none small opacity-75">Payment Register &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Payment No / Date</th>
                            <th>Agent</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center pe-3">Advice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_payments)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No payments recorded in this period.</td></tr>
                        <?php else: foreach ($recent_payments as $p): ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($p['payment_no'] ?: '#' . $p['id']) ?></div>
                                <div class="small text-muted"><?= date('d/m/Y', strtotime($p['payment_date'])) ?> &bull; <?= htmlspecialchars($p['payment_mode'] ?: '—') ?></div>
                            </td>
                            <td>
                                <div class="small fw-semibold"><?= htmlspecialchars($p['agent_name']) ?></div>
                                <?php if ($p['reference_no'] || $p['utr_no']): ?>
                                <div class="small text-muted" style="font-size:10px">Ref: <?= htmlspecialchars($p['reference_no'] ?: $p['utr_no']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold text-success">
                                ₹<?= number_format((float)$p['amount'], 2) ?>
                            </td>
                            <td class="text-center pe-3">
                                <a href="print_lease_agent_payment_advice.php?id=<?= (int)$p['id'] ?>" target="_blank"
                                   class="btn btn-sm btn-outline-secondary" title="Print Advice">
                                    <i class="bi bi-printer"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Script & Styles -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function toggleCustomMonth(val) {
    var wrap = document.getElementById('customMonthWrap');
    if (wrap) {
        wrap.style.display = (val === 'custom_month') ? 'block' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('leaseTrendChart');
    if (!ctx) return;

    var chartData = <?= json_encode($chart_months) ?>;
    var labels = chartData.map(function(d) { return d.month; });
    var trips  = chartData.map(function(d) { return d.trips; });
    var netF   = chartData.map(function(d) { return d.net_freight; });
    var margin = chartData.map(function(d) { return d.margin; });

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Trips Count',
                    data: trips,
                    borderColor: '#2563eb',
                    backgroundColor: '#2563eb',
                    yAxisID: 'y1',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 4,
                },
                {
                    type: 'bar',
                    label: 'Net Freight (₹)',
                    data: netF,
                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                    borderColor: '#10b981',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y',
                },
                {
                    type: 'bar',
                    label: 'Agent Margin (₹)',
                    data: margin,
                    backgroundColor: 'rgba(245, 158, 11, 0.7)',
                    borderColor: '#f59e0b',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { boxWidth: 12, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var label = context.dataset.label || '';
                            if (context.dataset.yAxisID === 'y') {
                                return label + ': ₹' + Number(context.parsed.y).toLocaleString('en-IN', {minimumFractionDigits:2});
                            }
                            return label + ': ' + context.parsed.y;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    ticks: {
                        callback: function(value) {
                            if (value >= 100000) return '₹' + (value / 100000).toFixed(1) + 'L';
                            if (value >= 1000) return '₹' + (value / 1000).toFixed(0) + 'k';
                            return '₹' + value;
                        },
                        font: { size: 10 }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { font: { size: 10 } }
                },
                x: {
                    ticks: { font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });
});
</script>

<style>
/* ─── Premium Lease Agent Theme ─── */
.tp-hero-header {
    background: linear-gradient(135deg, #0f172a 0%, #115e59 55%, #0f766e 100%);
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    color: #ffffff;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.15), 0 8px 10px -6px rgba(15, 23, 42, 0.1);
}
.tp-module-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 1.1rem;
    border-radius: 9999px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}
.tp-module-pill.active {
    background: #ffffff;
    color: #0f766e;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}
.tp-module-pill:not(.active) {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.tp-module-pill:not(.active):hover {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
    transform: translateY(-1px);
}

/* ─── Executive Stat Cards ─── */
.tp-kpi-card {
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    overflow: hidden;
}
.tp-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}
.tp-kpi-card.kpi-outstanding {
    background: linear-gradient(145deg, #fff1f2 0%, #ffffff 60%);
    border-color: #fecdd3;
    border-left: 4px solid #e11d48;
}
.tp-kpi-card.kpi-paid {
    background: linear-gradient(145deg, #ecfdf5 0%, #ffffff 60%);
    border-color: #a7f3d0;
    border-left: 4px solid #10b981;
}
.tp-kpi-card.kpi-hold {
    background: linear-gradient(145deg, #fffbeb 0%, #ffffff 60%);
    border-color: #fde68a;
    border-left: 4px solid #f59e0b;
}
.tp-kpi-card.kpi-month {
    background: linear-gradient(145deg, #eff6ff 0%, #ffffff 60%);
    border-color: #bfdbfe;
    border-left: 4px solid #3b82f6;
}

.tp-kpi-icon-wrap {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}
.kpi-outstanding .tp-kpi-icon-wrap { background: #ffe4e6; color: #e11d48; }
.kpi-paid .tp-kpi-icon-wrap { background: #d1fae5; color: #059669; }
.kpi-hold .tp-kpi-icon-wrap { background: #fef3c7; color: #d97706; }
.kpi-month .tp-kpi-icon-wrap { background: #dbeafe; color: #2563eb; }

/* Soft Badges */
.badge-soft-danger {
    background: #ffe4e6;
    color: #be123c;
    border: 1px solid #fecdd3;
    font-weight: 600;
}
.badge-soft-success {
    background: #d1fae5;
    color: #047857;
    border: 1px solid #a7f3d0;
    font-weight: 600;
}
.badge-soft-warning {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
    font-weight: 600;
}
.badge-soft-primary {
    background: #dbeafe;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    font-weight: 600;
}
.badge-soft-dark {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    font-weight: 600;
}
/* Bright vibrant badges for Not Billed and Unpaid */
.badge-not-billed {
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%) !important; /* Bright Electric Orange */
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 0.35em 0.65em !important;
    border-radius: 6px !important;
    letter-spacing: 0.02em;
    box-shadow: 0 2px 5px rgba(234, 88, 12, 0.35);
    display: inline-flex;
    align-items: center;
}
.badge-unpaid {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important; /* Bright Crimson Red */
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 0.35em 0.65em !important;
    border-radius: 6px !important;
    letter-spacing: 0.02em;
    box-shadow: 0 2px 5px rgba(220, 38, 38, 0.35);
    display: inline-flex;
    align-items: center;
}
</style>

<?php include '../includes/footer.php'; ?>
