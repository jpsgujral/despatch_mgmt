<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_lease_agent_helper.php';

$db = getDB();
if (!canDo('fleet_lease_agent_payments', 'view') && !canDo('fleet_lease_agents', 'view')) {
    requirePerm('fleet_lease_agents', 'view');
}
fleetEnsureLeaseAgents($db);
fleetEnsureLeaseTripFields($db);
fleetEnsureLeaseAgentPayments($db);
$isAdminUser = isAdmin();

// Auto-sync company_id, total_payable & balance_amount for existing payment records based on linked trips
$db->query("UPDATE fleet_lease_agent_payments p
    INNER JOIN (
        SELECT pt.payment_id,
            COALESCE(NULLIF(t.lease_agent_bill_to_company_id, 0), t.company_id) AS resolved_co_id,
            ROUND(SUM(CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount, 0)) END * (1 + (CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END) / 100) - COALESCE(t.lease_agent_misc_deduction, 0)), 2) AS calc_total_payable
        FROM fleet_lease_agent_payments p2
        JOIN fleet_lease_agents la ON la.id = p2.lease_agent_id
        JOIN fleet_lease_agent_payment_trips pt ON pt.payment_id = p2.id
        JOIN fleet_trips t ON t.id = pt.trip_id
        LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
        GROUP BY pt.payment_id
    ) calc ON calc.payment_id = p.id
    SET
        p.company_id = calc.resolved_co_id,
        p.total_payable = calc.calc_total_payable,
        p.balance_amount = GREATEST(0, calc.calc_total_payable - p.amount)
");

$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_trip_end_date = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_trips' AND COLUMN_NAME='end_date' LIMIT 1")->num_rows > 0;
$trip_period_expr = $has_trip_end_date
    ? "CASE WHEN end_date IS NOT NULL AND CAST(end_date AS CHAR) <> '0000-00-00' THEN end_date ELSE trip_date END"
    : "trip_date";

function leasePaymentEsc($db, $value): string {
    return $db->real_escape_string(trim((string)$value));
}

function leasePaymentMoney($value): string {
    return number_format((float)$value, 2);
}

function leasePaymentLoadTrips($db, int $agentId, string $dateFrom, string $dateTo, string $tripPeriodExpr): array {
    $agentId = (int)$agentId;
    $from = $db->real_escape_string($dateFrom);
    $to = $db->real_escape_string($dateTo);
    $agentRow = $db->query("SELECT tax_rate FROM fleet_lease_agents WHERE id=$agentId LIMIT 1")->fetch_assoc() ?: [];
    $taxRate = (float)($agentRow['tax_rate'] ?? 0);
    $sql = "
        SELECT t.id, t.trip_no, t.trip_date, t.total_weight, t.freight_amount,
               t.lease_agent_billing_model, la.billing_model, t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
               t.lease_agent_misc_deduction, t.lease_agent_misc_deduction_remarks,
               t.lease_agent_invoice_no, t.lease_agent_invoice_date,
               CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN $taxRate ELSE COALESCE(NULLIF(pi.gst_rate, 0), $taxRate, 0) END AS trip_tax_rate,
               COALESCE(vn.vendor_name, '') AS vendor_name,
               COALESCE(btco.company_name, co.company_name, '') AS company_name,
               v.reg_no
        FROM fleet_trips t
        LEFT JOIN fleet_lease_agents la ON la.id=t.lease_agent_id
        LEFT JOIN fleet_vehicles v ON v.id=t.vehicle_id
        LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
        LEFT JOIN fleet_customers_master vn ON vn.id=t.vendor_id
        LEFT JOIN companies co ON co.id=t.company_id
        LEFT JOIN companies btco ON btco.id=t.lease_agent_bill_to_company_id
        LEFT JOIN fleet_lease_agent_payment_trips pt ON pt.trip_id=t.id
        WHERE t.status='Completed'
          AND t.lease_agent_id=$agentId
          AND $tripPeriodExpr BETWEEN '$from' AND '$to'
          AND pt.trip_id IS NULL
        ORDER BY $tripPeriodExpr, t.id";
    $queryRes = $db->query($sql);
    $rows = ($queryRes && $queryRes !== false) ? $queryRes->fetch_all(MYSQLI_ASSOC) : [];
    $totals = [
        'trip_count' => 0,
        'total_weight' => 0.0,
        'gross_freight' => 0.0,
        'lease_deduction' => 0.0,
        'misc_deduction' => 0.0,
        'net_payable' => 0.0,
    ];
    foreach ($rows as $row) {
        $totals['trip_count']++;
        $totals['total_weight'] += (float)($row['total_weight'] ?? 0);
        $totals['gross_freight'] += (float)($row['freight_amount'] ?? 0);
        $totals['lease_deduction'] += (float)($row['lease_agent_amount'] ?? 0);
        $miscDed = (float)($row['lease_agent_misc_deduction'] ?? 0);
        $totals['misc_deduction'] += $miscDed;
        $net = fleetLeaseAgentTripBasePayable($row);
        $tripTaxRate = (float)($row['trip_tax_rate'] ?? $taxRate);
        $totals['net_payable'] += ($net * (1 + ($tripTaxRate / 100))) - $miscDed;
    }
    return ['rows' => $rows, 'totals' => $totals];
}

function leasePaymentLoadPayment($db, int $paymentId): array {
    $payment = $db->query("SELECT p.*, la.agent_name, la.agent_code, COALESCE(la.tax_rate, 0) AS tax_rate
        FROM fleet_lease_agent_payments p
        LEFT JOIN fleet_lease_agents la ON la.id=p.lease_agent_id
        WHERE p.id=$paymentId
        LIMIT 1")->fetch_assoc() ?: [];
    if (!$payment) {
        return ['payment' => [], 'trips' => [], 'totals' => ['trip_count' => 0, 'total_weight' => 0, 'gross_freight' => 0, 'lease_deduction' => 0, 'misc_deduction' => 0, 'net_payable' => 0]];
    }
    $taxRate = (float)($payment['tax_rate'] ?? 0);
    $tripsRes = $db->query("SELECT t.id, t.trip_no, t.trip_date, t.total_weight, t.freight_amount,
            t.lease_agent_billing_model, la.billing_model, t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
            t.lease_agent_misc_deduction, t.lease_agent_misc_deduction_remarks,
            t.lease_agent_invoice_no, t.lease_agent_invoice_date,
            CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN $taxRate ELSE COALESCE(NULLIF(pi.gst_rate, 0), $taxRate, 0) END AS trip_tax_rate,
            COALESCE(vn.vendor_name, '') AS vendor_name,
            COALESCE(btco.company_name, co.company_name, '') AS company_name,
            v.reg_no
        FROM fleet_lease_agent_payment_trips pt
        JOIN fleet_trips t ON t.id=pt.trip_id
        LEFT JOIN fleet_lease_agents la ON la.id=t.lease_agent_id
        LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
        LEFT JOIN fleet_vehicles v ON v.id=t.vehicle_id
        LEFT JOIN fleet_customers_master vn ON vn.id=t.vendor_id
        LEFT JOIN companies co ON co.id=t.company_id
        LEFT JOIN companies btco ON btco.id=t.lease_agent_bill_to_company_id
        WHERE pt.payment_id=$paymentId
        ORDER BY t.trip_date, t.id");
    $trips = ($tripsRes && $tripsRes !== false) ? $tripsRes->fetch_all(MYSQLI_ASSOC) : [];
    $totals = ['trip_count' => 0, 'total_weight' => 0.0, 'gross_freight' => 0.0, 'lease_deduction' => 0.0, 'misc_deduction' => 0.0, 'net_payable' => 0.0];
    foreach ($trips as $row) {
        $totals['trip_count']++;
        $totals['total_weight'] += (float)($row['total_weight'] ?? 0);
        $totals['gross_freight'] += (float)($row['freight_amount'] ?? 0);
        $totals['lease_deduction'] += (float)($row['lease_agent_amount'] ?? 0);
        $miscDed = (float)($row['lease_agent_misc_deduction'] ?? 0);
        $totals['misc_deduction'] += $miscDed;
        $net = fleetLeaseAgentTripBasePayable($row);
        $tripTaxRate = (float)($row['trip_tax_rate'] ?? $taxRate);
        $totals['net_payable'] += ($net * (1 + ($tripTaxRate / 100))) - $miscDed;
    }
    return ['payment' => $payment, 'trips' => $trips, 'totals' => $totals];
}

function leasePaymentLoadAgentSummary($db, int $agentId): array {
    $agentId = (int)$agentId;
    if ($agentId <= 0) {
        return ['total_payable' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'trip_count' => 0, 'payment_count' => 0, 'paid_trip_count' => 0];
    }
    $agentRow = $db->query("SELECT tax_rate FROM fleet_lease_agents WHERE id=$agentId LIMIT 1")->fetch_assoc() ?: [];
    $taxRate = (float)($agentRow['tax_rate'] ?? 0);

    $tripRow = $db->query("SELECT
            COALESCE(COUNT(*), 0) AS trip_count,
            COALESCE(SUM(
                CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount, 0)) END * (1 + ((CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END) / 100))
                - COALESCE(t.lease_agent_misc_deduction, 0)
            ), 0) AS total_payable
        FROM fleet_trips t
        LEFT JOIN fleet_lease_agents la ON la.id=t.lease_agent_id
        LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
        WHERE t.status='Completed' AND t.lease_agent_id=$agentId")->fetch_assoc() ?: [];
    $totalPayable = round((float)($tripRow['total_payable'] ?? 0), 2);

    $paymentRow = $db->query("SELECT
            COALESCE(SUM(p.amount), 0) AS paid,
            COUNT(*) AS payment_count
        FROM fleet_lease_agent_payments p
        WHERE p.lease_agent_id=$agentId")->fetch_assoc() ?: [];
    $paidTripRow = $db->query("SELECT
            COALESCE(COUNT(DISTINCT pt.trip_id), 0) AS paid_trip_count
        FROM fleet_lease_agent_payment_trips pt
        INNER JOIN fleet_lease_agent_payments p ON p.id=pt.payment_id
        WHERE p.lease_agent_id=$agentId")->fetch_assoc() ?: [];
    $paid = (float)($paymentRow['paid'] ?? 0);
    return [
        'total_payable' => $totalPayable,
        'paid' => $paid,
        'balance' => max(0, $totalPayable - $paid),
        'trip_count' => (int)($tripRow['trip_count'] ?? 0),
        'payment_count' => (int)($paymentRow['payment_count'] ?? 0),
        'paid_trip_count' => (int)($paidTripRow['paid_trip_count'] ?? 0),
    ];
}

$action = $_GET['action'] ?? 'list';
$paymentId = (int)($_GET['id'] ?? 0);
$agentId = (int)($_GET['lease_agent_id'] ?? ($_POST['lease_agent_id'] ?? 0));
$is_lease_agent_user = isLeaseAgentUser();
if ($is_lease_agent_user) {
    $agentId = currentLeaseAgentId();
    if ($action === 'edit') {
        showAlert('danger', 'Lease agent users can only view payment data.');
        redirect('fleet_lease_agent_payments.php');
    }
}
$dateFrom = trim((string)($_GET['date_from'] ?? ($_POST['date_from'] ?? date('Y-m-01'))));
$dateTo = trim((string)($_GET['date_to'] ?? ($_POST['date_to'] ?? date('Y-m-t'))));
$paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
$amountPaid = trim((string)($_POST['amount_paid'] ?? ($_POST['amount'] ?? '')));
$referenceNo = trim((string)($_POST['reference_no'] ?? ''));
$utrNo = trim((string)($_POST['utr_no'] ?? ''));
$bankName = trim((string)($_POST['bank_name'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$manualPaymentStatus = trim((string)($_POST['payment_status_manual'] ?? ''));
$validPaymentStatuses = ['Paid', 'Unpaid', 'Partial'];

if ($action === 'print' && $paymentId > 0) {
    $paymentView = leasePaymentLoadPayment($db, $paymentId);
    if (empty($paymentView['payment'])) {
        showAlert('danger', 'Payment record not found.');
        redirect('fleet_lease_agent_payments.php');
    }
    if ($is_lease_agent_user && (int)($paymentView['payment']['lease_agent_id'] ?? 0) !== $agentId) {
        showAlert('danger', 'You can only view your own lease agent payments.');
        redirect('fleet_lease_agent_payments.php');
    }
    $payment = $paymentView['payment'];
    $tripRows = $paymentView['trips'];
    $totals = $paymentView['totals'];
    $taxRate = (float)($payment['tax_rate'] ?? 0);

    $totalNetFreight = 0;
    $totalGst = 0;
    foreach ($tripRows as $t) {
        $net = fleetLeaseAgentTripBasePayable($t);
        $tripTaxRate = (float)($t['trip_tax_rate'] ?? $taxRate);
        $gst = $net * ($tripTaxRate / 100);
        $totalNetFreight += $net;
        $totalGst += $gst;
    }
    $paymentTotalPayable = (float)($payment['total_payable'] ?? ($totalNetFreight + $totalGst));
    $paymentAmountPaid = (float)($payment['amount'] ?? 0);
    $paymentBalance = (float)($payment['balance_amount'] ?? max(0, $paymentTotalPayable - $paymentAmountPaid));
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Lease Agent Payment Advice</title>
        <style>
            body{font-family:Arial,sans-serif;font-size:11px;margin:18px;color:#222}
            .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #0f766e;padding-bottom:8px;margin-bottom:12px}
            .title{font-size:18px;font-weight:bold;color:#0f766e}
            .sub{font-size:10px;color:#666}
            .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
            .box{border:1px solid #ddd;border-radius:4px;padding:8px}
            .lbl{font-size:9px;color:#666}
            .val{font-size:11px;font-weight:bold;margin-top:2px}
            table{width:100%;border-collapse:collapse;margin-top:8px}
            th,td{border:1px solid #ccc;padding:4px 6px}
            th{background:#f5f5f5}
            .r{text-align:right}
            .c{text-align:center}
            .tot{font-weight:bold;background:#f8fafc}
            @media print{@page{size:A4 landscape;margin:8mm} body{margin:0}}
        </style>
    </head>
    <body onload="window.print()">
        <div class="head">
            <div>
                <div class="title">Lease Agent Payment Advice</div>
                <div class="sub">Payment No: <?= htmlspecialchars($payment['payment_no'] ?: ('LAP-' . (int)$payment['id'])) ?><?php if (!empty($payment['agent_invoice_no'])): ?> &nbsp;|&nbsp; Agent Invoice No: <strong><?= htmlspecialchars($payment['agent_invoice_no']) ?></strong><?php endif; ?></div>
            </div>
            <div class="sub" style="text-align:right">
                <div>Payment Date: <?= htmlspecialchars(date('d/m/Y', strtotime($payment['payment_date']))) ?></div>
                <div>Period: <?= htmlspecialchars(date('d/m/Y', strtotime($payment['date_from'])) . ' to ' . date('d/m/Y', strtotime($payment['date_to']))) ?></div>
            </div>
        </div>
        <div class="grid" style="grid-template-columns:repeat(5,1fr)">
            <div class="box"><div class="lbl">Lease Agent</div><div class="val"><?= htmlspecialchars(($payment['agent_name'] ?? '') . (!empty($payment['agent_code']) ? ' (' . $payment['agent_code'] . ')' : '')) ?></div></div>
            <div class="box"><div class="lbl">Agent Invoice No</div><div class="val"><?= htmlspecialchars($payment['agent_invoice_no'] ?: '-') ?></div></div>
            <div class="box"><div class="lbl">Trips</div><div class="val"><?= number_format($totals['trip_count'], 0) ?></div></div>
            <div class="box"><div class="lbl">Total Payable</div><div class="val">₹<?= number_format($paymentTotalPayable, 2) ?></div></div>
            <div class="box"><div class="lbl">Balance Due</div><div class="val">₹<?= number_format($paymentBalance, 2) ?></div></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="c">#</th><th>Trip No</th><th>Agent Invoice No</th><th>Date</th><th>Vehicle</th><th>Bill To Company</th><th>Vendor Name</th>
                    <th class="r">Weight (MT)</th><th class="r">Lease Agent Rate</th><th class="r">Net Freight</th><th class="r">GST (<?= number_format($taxRate, 2) ?>%)</th><th class="r">Net Payable</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sno = 1;
                foreach ($tripRows as $trip):
                    $weight = (float)($trip['total_weight'] ?? 0);
                    $net = fleetLeaseAgentTripBasePayable($trip);
                    $rate = $weight > 0 ? ($net / $weight) : 0;
                    $gst = $net * ($taxRate / 100);
                    $netPayable = $net + $gst;
                ?>
                <tr>
                    <td class="c"><?= $sno++ ?></td>
                    <td><?= htmlspecialchars($trip['trip_no']) ?></td>
                    <td><?= htmlspecialchars($trip['lease_agent_invoice_no'] ?: '-') ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($trip['trip_date']))) ?></td>
                    <td><?= htmlspecialchars($trip['reg_no'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($trip['company_name'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($trip['vendor_name'] ?: '-') ?></td>
                    <td class="r"><?= number_format($weight, 3) ?></td>
                    <td class="r">₹<?= number_format($rate, 2) ?></td>
                    <td class="r">₹<?= number_format($net, 2) ?></td>
                    <td class="r">₹<?= number_format($gst, 2) ?></td>
                    <td class="r">₹<?= number_format($netPayable, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="tot">
                    <td colspan="7" class="r">Total</td>
                    <td class="r"><?= number_format($totals['total_weight'], 3) ?></td>
                    <td></td>
                    <td class="r">₹<?= number_format($totalNetFreight, 2) ?></td>
                    <td class="r">₹<?= number_format($totalGst, 2) ?></td>
                    <td class="r">₹<?= number_format($paymentTotalPayable, 2) ?></td>
                </tr>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'print_preview' && $agentId > 0) {
    if ($is_lease_agent_user && $agentId !== currentLeaseAgentId()) {
        showAlert('danger', 'You can only preview your own lease agent data.');
        redirect('fleet_lease_agent_payments.php');
    }
    $previewPack = leasePaymentLoadTrips($db, $agentId, $dateFrom, $dateTo, $trip_period_expr);
    $agentRow = $db->query("SELECT agent_name, agent_code, tax_rate FROM fleet_lease_agents WHERE id=$agentId LIMIT 1")->fetch_assoc() ?: [];
    $previewAgent = ($agentRow['agent_name'] ?? '') . (!empty($agentRow['agent_code']) ? ' (' . $agentRow['agent_code'] . ')' : '');
    $taxRate = (float)($agentRow['tax_rate'] ?? 0);

    $totalNetFreight = 0;
    $totalGst = 0;
    foreach ($previewPack['rows'] as $t) {
        $net = fleetLeaseAgentTripBasePayable($t);
        $gst = $net * ($taxRate / 100);
        $totalNetFreight += $net;
        $totalGst += $gst;
    }
    $paymentTotalPayable = (float)($previewPack['totals']['net_payable'] ?? ($totalNetFreight + $totalGst));
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Lease Agent Payment Preview</title>
        <style>
            body{font-family:Arial,sans-serif;font-size:11px;margin:18px;color:#222}
            .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #0f766e;padding-bottom:8px;margin-bottom:12px}
            .title{font-size:18px;font-weight:bold;color:#0f766e}
            .sub{font-size:10px;color:#666}
            .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
            .box{border:1px solid #ddd;border-radius:4px;padding:8px}
            .lbl{font-size:9px;color:#666}
            .val{font-size:11px;font-weight:bold;margin-top:2px}
            table{width:100%;border-collapse:collapse;margin-top:8px}
            th,td{border:1px solid #ccc;padding:4px 6px}
            th{background:#f5f5f5}
            .r{text-align:right}
            .c{text-align:center}
            .tot{font-weight:bold;background:#f8fafc}
            @media print{@page{size:A4 landscape;margin:8mm} body{margin:0}}
        </style>
    </head>
    <body onload="window.print()">
        <div class="head">
            <div>
                <div class="title">Lease Agent Payment Preview</div>
                <div class="sub"><?= htmlspecialchars($previewAgent) ?></div>
            </div>
            <div class="sub" style="text-align:right">
                <div>From: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?></div>
                <div>To: <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?></div>
            </div>
        </div>
        <div class="grid">
            <div class="box"><div class="lbl">Trips</div><div class="val"><?= number_format($previewPack['totals']['trip_count'], 0) ?></div></div>
            <div class="box"><div class="lbl">Weight (MT)</div><div class="val"><?= number_format($previewPack['totals']['total_weight'], 3) ?></div></div>
            <div class="box"><div class="lbl">Total Payable</div><div class="val">₹<?= number_format($paymentTotalPayable, 2) ?></div></div>
            <div class="box"><div class="lbl">Balance After Payment</div><div class="val">₹<?= number_format($paymentTotalPayable, 2) ?></div></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="c">#</th><th>Trip No</th><th>Agent Invoice No</th><th>Date</th><th>Vehicle</th><th>Bill To Company</th><th>Vendor Name</th>
                    <th class="r">Weight (MT)</th><th class="r">Lease Agent Rate</th><th class="r">Net Freight</th><th class="r">GST (<?= number_format($taxRate, 2) ?>%)</th><th class="r">Net Payable</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sno = 1;
                foreach ($previewPack['rows'] as $trip):
                    $weight = (float)($trip['total_weight'] ?? 0);
                    $net = fleetLeaseAgentTripBasePayable($trip);
                    $rate = $weight > 0 ? ($net / $weight) : 0;
                    $gst = $net * ($taxRate / 100);
                    $netPayable = $net + $gst;
                ?>
                <tr>
                    <td class="c"><?= $sno++ ?></td>
                    <td><?= htmlspecialchars($trip['trip_no']) ?></td>
                    <td><?= htmlspecialchars($trip['lease_agent_invoice_no'] ?: '-') ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($trip['trip_date']))) ?></td>
                    <td><?= htmlspecialchars($trip['reg_no'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($trip['company_name'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($trip['vendor_name'] ?: '-') ?></td>
                    <td class="r"><?= number_format($weight, 3) ?></td>
                    <td class="r">₹<?= number_format($rate, 2) ?></td>
                    <td class="r">₹<?= number_format($net, 2) ?></td>
                    <td class="r">₹<?= number_format($gst, 2) ?></td>
                    <td class="r">₹<?= number_format($netPayable, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="tot">
                    <td colspan="7" class="r">Total</td>
                    <td class="r"><?= number_format($totals['total_weight'], 3) ?></td>
                    <td></td>
                    <td class="r">₹<?= number_format($totalNetFreight, 2) ?></td>
                    <td class="r">₹<?= number_format($totalGst, 2) ?></td>
                    <td class="r">₹<?= number_format($paymentTotalPayable, 2) ?></td>
                </tr>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment'])) {
    if (!$isAdminUser || $is_lease_agent_user) {
        showAlert('danger', 'Only admins can delete lease agent payments.');
        redirect('fleet_lease_agent_payments.php');
    }
    $delId = (int)($_POST['payment_id'] ?? 0);
    if ($delId <= 0) {
        showAlert('danger', 'Invalid payment record.');
        redirect('fleet_lease_agent_payments.php');
    }
    $db->begin_transaction();
    try {
        $db->query("DELETE FROM fleet_lease_agent_payment_trips WHERE payment_id=$delId");
        $db->query("DELETE FROM fleet_lease_agent_payments WHERE id=$delId");
        $db->commit();
        showAlert('success', 'Payment record deleted successfully.');
    } catch (Throwable $e) {
        $db->rollback();
        showAlert('danger', 'Could not delete payment: ' . $e->getMessage());
    }
    redirect('fleet_lease_agent_payments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    if (!$isAdminUser || $is_lease_agent_user) {
        showAlert('danger', 'Only admins can edit lease agent payments.');
        redirect('fleet_lease_agent_payments.php');
    }
    $editPaymentId = (int)($_POST['payment_id'] ?? 0);
    if ($editPaymentId <= 0) {
        showAlert('danger', 'Invalid payment record.');
        redirect('fleet_lease_agent_payments.php');
    }
    $existingPayment = leasePaymentLoadPayment($db, $editPaymentId);
    if (empty($existingPayment['payment'])) {
        showAlert('danger', 'Payment record not found.');
        redirect('fleet_lease_agent_payments.php');
    }
    $existingPaymentRow = $existingPayment['payment'];
    $totalPayable = round((float)($existingPayment['totals']['net_payable'] ?? 0), 2);
    $amount = $amountPaid !== '' ? round((float)$amountPaid, 2) : (float)($existingPaymentRow['amount'] ?? 0);
    if ($amount < 0) $amount = 0;
    if ($amount > $totalPayable) $amount = $totalPayable;
    $balanceAmount = round(max(0, $totalPayable - $amount), 2);
    $paymentStatus = in_array($manualPaymentStatus, $validPaymentStatuses) ? $manualPaymentStatus : ($balanceAmount > 0 ? 'Partial' : 'Paid');
    $paymentDateSql = leasePaymentEsc($db, $paymentDate);
    $referenceSql = leasePaymentEsc($db, $referenceNo);
    $agentInvoiceSql = leasePaymentEsc($db, $_POST['agent_invoice_no'] ?? '');
    $utrSql = leasePaymentEsc($db, $utrNo);
    $bankSql = leasePaymentEsc($db, $bankName);
    $notesSql = leasePaymentEsc($db, $notes);
    $updatedBy = (int)($_SESSION['user_id'] ?? 0);
    $db->query("UPDATE fleet_lease_agent_payments SET
        payment_date='$paymentDateSql',
        amount=$amount,
        total_payable=$totalPayable,
        balance_amount=$balanceAmount,
        reference_no='$referenceSql',
        agent_invoice_no='$agentInvoiceSql',
        utr_no='$utrSql',
        bank_name='$bankSql',
        notes='$notesSql',
        status='$paymentStatus',
        updated_by=$updatedBy,
        updated_at=NOW()
        WHERE id=$editPaymentId");
    showAlert('success', 'Lease agent payment updated successfully.');
    redirect('fleet_lease_agent_payments.php?action=view&id=' . $editPaymentId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    if ($is_lease_agent_user) {
        showAlert('danger', 'Lease agent users can only view payment data.');
        redirect('fleet_lease_agent_payments.php');
    }
    if ($agentId <= 0) {
        showAlert('danger', 'Please select a lease agent.');
        redirect('fleet_lease_agent_payments.php');
    }
    // Bill-wise: collect selected trip IDs from checkboxes
    $tripIds = array_values(array_unique(array_filter(array_map('intval', $_POST['trip_ids'] ?? []))));
    if (empty($tripIds)) {
        showAlert('danger', 'Please select at least one bill to record payment.');
        redirect('fleet_lease_agent_payments.php?action=advice&lease_agent_id=' . $agentId);
    }
    $tripIdsStr = implode(',', $tripIds);
    // Load only the selected unpaid BILLED trips belonging to this agent
    $selectedTrips = $db->query("
        SELECT t.id, t.trip_date, t.freight_amount, t.lease_agent_amount, t.net_freight_amount,
               t.company_id, t.lease_agent_bill_to_company_id
        FROM fleet_trips t
        LEFT JOIN fleet_lease_agent_payment_trips pt ON pt.trip_id = t.id
        WHERE t.id IN ($tripIdsStr)
          AND t.lease_agent_id = $agentId
          AND t.status = 'Completed'
          AND t.lease_agent_billing_status = 'Billed'
          AND pt.trip_id IS NULL
    ")->fetch_all(MYSQLI_ASSOC);
    if (empty($selectedTrips)) {
        showAlert('danger', 'No valid billed unpaid trips found. Only trips with Agent Billing Status = Billed can be paid.');
        redirect('fleet_lease_agent_payments.php?action=advice&lease_agent_id=' . $agentId);
    }
    $agentRow = $db->query("SELECT tax_rate FROM fleet_lease_agents WHERE id=$agentId LIMIT 1")->fetch_assoc() ?: [];
    $taxRate = (float)($agentRow['tax_rate'] ?? 0);
    $totalPayable = 0;
    $paymentCompanyId = 0;
    $tripDates = [];
    foreach ($selectedTrips as $st) {
        $cId = (int)($st['lease_agent_bill_to_company_id'] ?? 0);
        if ($cId <= 0) $cId = (int)($st['company_id'] ?? 0);
        if ($cId > 0 && $paymentCompanyId <= 0) {
            $paymentCompanyId = $cId;
        }
        $net = fleetLeaseAgentTripBasePayable($st);
        $tax = $net * ($taxRate / 100);
        $totalPayable += ($net + $tax);
        $tripDates[] = $st['trip_date'];
    }
    $totalPayable = round($totalPayable, 2);
    sort($tripDates);
    $dateFromAuto = $tripDates[0];
    $dateToAuto   = end($tripDates);
    // At record time amount = 0; user fills UTR/bank/amount via Edit after recording
    $amount = 0;
    $balanceAmount = $totalPayable;
    $paymentStatus = in_array($manualPaymentStatus, $validPaymentStatuses) ? $manualPaymentStatus : 'Unpaid';
    $paymentDateSql  = leasePaymentEsc($db, $paymentDate);
    $dateFromSql     = leasePaymentEsc($db, $dateFromAuto);
    $dateToSql       = leasePaymentEsc($db, $dateToAuto);
    $referenceSql    = leasePaymentEsc($db, $referenceNo);
    $agentInvoiceSql = leasePaymentEsc($db, $_POST['agent_invoice_no'] ?? '');
    $utrSql          = leasePaymentEsc($db, $utrNo);
    $bankSql         = leasePaymentEsc($db, $bankName);
    $notesSql        = leasePaymentEsc($db, $notes);
    $createdBy       = (int)($_SESSION['user_id'] ?? 0);
    $db->begin_transaction();
    try {
        $db->query("INSERT INTO fleet_lease_agent_payments
            (payment_no, lease_agent_id, company_id, payment_date, date_from, date_to, amount, total_payable, balance_amount, reference_no, agent_invoice_no, utr_no, bank_name, notes, status, created_by)
            VALUES ('', $agentId, " . ($paymentCompanyId > 0 ? $paymentCompanyId : 'NULL') . ", '$paymentDateSql', '$dateFromSql', '$dateToSql', $amount, $totalPayable, $balanceAmount, '$referenceSql', '$agentInvoiceSql', '$utrSql', '$bankSql', '$notesSql', '$paymentStatus', $createdBy)");
        $newPaymentId = (int)$db->insert_id;
        $paymentNo    = 'LAP-' . date('Ym', strtotime($paymentDateSql)) . '-' . str_pad((string)$newPaymentId, 4, '0', STR_PAD_LEFT);
        $paymentNoSql = leasePaymentEsc($db, $paymentNo);
        $db->query("UPDATE fleet_lease_agent_payments SET payment_no='$paymentNoSql' WHERE id=$newPaymentId");
        foreach ($selectedTrips as $st) {
            $tid = (int)$st['id'];
            $db->query("INSERT INTO fleet_lease_agent_payment_trips (payment_id, trip_id) VALUES ($newPaymentId, $tid)");
        }
        $db->commit();
        showAlert('success', count($selectedTrips) . ' bill(s) recorded successfully. Please fill in payment details below.');
        redirect('fleet_lease_agent_payments.php?action=edit&id=' . $newPaymentId);
    } catch (Throwable $e) {
        $db->rollback();
        showAlert('danger', 'Could not save payment: ' . $e->getMessage());
        redirect('fleet_lease_agent_payments.php?action=advice&lease_agent_id=' . $agentId);
    }
}

$agents = $db->query($is_lease_agent_user && $agentId > 0
    ? "SELECT id, agent_code, agent_name, default_margin_per_mt, status
       FROM fleet_lease_agents
       WHERE id=$agentId
       ORDER BY status ASC, agent_name ASC"
    : "SELECT id, agent_code, agent_name, default_margin_per_mt, status
       FROM fleet_lease_agents
       ORDER BY status ASC, agent_name ASC")->fetch_all(MYSQLI_ASSOC);

$preview = []; // Bills are loaded directly inside the advice section HTML

$paymentView = [];
if (($action === 'view' || $action === 'edit') && $paymentId > 0) {
    $paymentView = leasePaymentLoadPayment($db, $paymentId);
    if ($is_lease_agent_user && !empty($paymentView['payment']) && (int)($paymentView['payment']['lease_agent_id'] ?? 0) !== $agentId) {
        showAlert('danger', 'You can only view your own lease agent payments.');
        redirect('fleet_lease_agent_payments.php');
    }
}

if ($action === 'edit' && $paymentId > 0 && !$isAdminUser) {
    showAlert('danger', 'Only admins can edit lease agent payments.');
    redirect('fleet_lease_agent_payments.php?action=view&id=' . $paymentId);
}

$payments = $db->query($is_lease_agent_user && $agentId > 0
    ? "SELECT p.*, la.agent_name, la.agent_code, COALESCE(la.tax_rate, 0) AS tax_rate,
        COALESCE(btco.company_name, pco.company_name, co.company_name, 'General') AS company_name,
        (SELECT COUNT(*) FROM fleet_lease_agent_payment_trips pt WHERE pt.payment_id=p.id) AS trip_count,
        (SELECT GROUP_CONCAT(DISTINCT NULLIF(t.lease_agent_invoice_no, '') SEPARATOR ', ')
         FROM fleet_lease_agent_payment_trips pt JOIN fleet_trips t ON t.id=pt.trip_id WHERE pt.payment_id=p.id) AS trip_invoice_nos
        FROM fleet_lease_agent_payments p
        LEFT JOIN fleet_lease_agents la ON la.id=p.lease_agent_id
        LEFT JOIN companies pco ON pco.id=p.company_id
        LEFT JOIN fleet_lease_agent_payment_trips lpt ON lpt.payment_id=p.id
        LEFT JOIN fleet_trips ft ON ft.id=lpt.trip_id
        LEFT JOIN companies co ON co.id=ft.company_id
        LEFT JOIN companies btco ON btco.id=ft.lease_agent_bill_to_company_id
        WHERE p.lease_agent_id=$agentId
        GROUP BY p.id
        ORDER BY COALESCE(btco.company_name, pco.company_name, co.company_name, 'General') ASC, p.payment_date DESC, p.id DESC"
    : "SELECT p.*, la.agent_name, la.agent_code, COALESCE(la.tax_rate, 0) AS tax_rate,
        COALESCE(btco.company_name, pco.company_name, co.company_name, 'General') AS company_name,
        (SELECT COUNT(*) FROM fleet_lease_agent_payment_trips pt WHERE pt.payment_id=p.id) AS trip_count,
        (SELECT GROUP_CONCAT(DISTINCT NULLIF(t.lease_agent_invoice_no, '') SEPARATOR ', ')
         FROM fleet_lease_agent_payment_trips pt JOIN fleet_trips t ON t.id=pt.trip_id WHERE pt.payment_id=p.id) AS trip_invoice_nos
        FROM fleet_lease_agent_payments p
        LEFT JOIN fleet_lease_agents la ON la.id=p.lease_agent_id
        LEFT JOIN companies pco ON pco.id=p.company_id
        LEFT JOIN fleet_lease_agent_payment_trips lpt ON lpt.payment_id=p.id
        LEFT JOIN fleet_trips ft ON ft.id=lpt.trip_id
        LEFT JOIN companies co ON co.id=ft.company_id
        LEFT JOIN companies btco ON btco.id=ft.lease_agent_bill_to_company_id
        GROUP BY p.id
        ORDER BY COALESCE(btco.company_name, pco.company_name, co.company_name, 'General') ASC, p.payment_date DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC);

// Pre-fetch all trips for inline expansion in payment register
$paymentTripsMap = [];
if (!empty($payments)) {
    $payIds = array_column($payments, 'id');
    if (!empty($payIds)) {
        $payIdsStr = implode(',', array_map('intval', $payIds));
        $allPaymentTripsRes = $db->query("
            SELECT pt.payment_id, t.id, t.trip_no, t.trip_date, t.total_weight, t.freight_amount,
                   t.lease_agent_billing_model, la.billing_model, t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
                   t.lease_agent_invoice_no, t.lease_agent_invoice_date,
                   COALESCE(vn.vendor_name, '') AS vendor_name,
                   COALESCE(btco.company_name, co.company_name, '') AS company_name,
                   v.reg_no
            FROM fleet_lease_agent_payment_trips pt
            JOIN fleet_trips t ON t.id=pt.trip_id
            LEFT JOIN fleet_lease_agents la ON la.id=t.lease_agent_id
            LEFT JOIN fleet_vehicles v ON v.id=t.vehicle_id
            LEFT JOIN fleet_customers_master vn ON vn.id=t.vendor_id
            LEFT JOIN companies co ON co.id=t.company_id
            LEFT JOIN companies btco ON btco.id=t.lease_agent_bill_to_company_id
            WHERE pt.payment_id IN ($payIdsStr)
            ORDER BY t.trip_date, t.id
        ");
        $allPaymentTrips = ($allPaymentTripsRes && $allPaymentTripsRes !== false) ? $allPaymentTripsRes->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($allPaymentTrips as $ptRow) {
            $paymentTripsMap[(int)$ptRow['payment_id']][] = $ptRow;
        }
    }
}

$selectedAgentSummary = $agentId > 0 ? leasePaymentLoadAgentSummary($db, $agentId) : ['total_payable' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'trip_count' => 0, 'payment_count' => 0];
$registerTotals = ['total_payable' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'trip_count' => 0];
foreach ($payments as $paymentRow) {
    $rowTotal = (float)($paymentRow['total_payable'] ?? $paymentRow['amount'] ?? 0);
    $rowPaid = (float)($paymentRow['amount'] ?? 0);
    $rowBalance = (float)($paymentRow['balance_amount'] ?? max(0, $rowTotal - $rowPaid));
    $registerTotals['total_payable'] += $rowTotal;
    $registerTotals['paid'] += $rowPaid;
    $registerTotals['balance'] += $rowBalance;
    $registerTotals['trip_count'] += (int)($paymentRow['trip_count'] ?? 0);
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-cash-stack me-2"></i>Lease Agent Payments';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="fleet_lease_agent_dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <?php if (!isLeaseAgentUser() && canDo('fleet_lease_agents', 'view')): ?>
        <a href="fleet_lease_agents.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-badge me-1"></i>Agent Master</a>
        <?php endif; ?>
        <a href="fleet_lease_agent_payments.php" class="btn btn-sm btn-primary active"><i class="bi bi-cash-stack me-1"></i>Payments</a>
        <a href="fleet_lease_agent_report.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-bar-graph me-1"></i>Report</a>
    </div>
    <h5 class="mb-0 fw-bold">Lease Agent Payment Advice &amp; Register</h5>
</div>

<div class="card mb-3 no-print">
    <div class="card-header fw-semibold">Create Payment Advice</div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="advice">
            <div class="col-12 col-md-4">
                <label class="form-label">Lease Agent</label>
                <select name="lease_agent_id" class="form-select" required <?= $is_lease_agent_user ? 'disabled' : '' ?>>
                    <option value="">— Select —</option>
                    <?php foreach ($agents as $agent): ?>
                    <option value="<?= (int)$agent['id'] ?>" <?= $agentId === (int)$agent['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($agent['agent_name'] . ($agent['agent_code'] ? ' (' . $agent['agent_code'] . ')' : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($is_lease_agent_user): ?>
                <input type="hidden" name="lease_agent_id" value="<?= (int)$agentId ?>">
                <?php endif; ?>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>" required>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Preview Details</button>
            </div>
            <div class="col-12 col-md-2">
                <a href="fleet_lease_agent_payments.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
        <?php if ($agentId > 0): ?>
        <div class="row g-2 mt-3">
            <div class="col-6 col-md-3">
                <div class="card p-2 text-center h-100">
                    <small class="text-muted">From Start Payable</small>
                    <div class="fw-bold text-success">&#8377;<?= number_format($selectedAgentSummary['total_payable'], 2) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card p-2 text-center h-100">
                    <small class="text-muted">Paid</small>
                    <div class="fw-bold text-primary">&#8377;<?= number_format($selectedAgentSummary['paid'], 2) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card p-2 text-center h-100">
                    <small class="text-muted">Balance</small>
                    <div class="fw-bold text-danger">&#8377;<?= number_format($selectedAgentSummary['balance'], 2) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card p-2 text-center h-100">
                    <small class="text-muted">Trips Covered</small>
                    <div class="fw-bold"><?= number_format($selectedAgentSummary['trip_count'], 0) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Total Payable</small><div class="fw-bold text-success">&#8377;<?= number_format($registerTotals['total_payable'], 2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Paid</small><div class="fw-bold text-primary">&#8377;<?= number_format($registerTotals['paid'], 2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Balance</small><div class="fw-bold text-danger">&#8377;<?= number_format($registerTotals['balance'], 2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Trips Covered</small><div class="fw-bold"><?= number_format($registerTotals['trip_count'], 0) ?></div></div></div>
</div>

<?php if ($action === 'view' && !empty($paymentView['payment'])): ?>
<?php
    $payment = $paymentView['payment'];
    $tripRows = $paymentView['trips'];
    $totals = $paymentView['totals'];
    $paymentTotalPayable = (float)($payment['total_payable'] ?? $totals['net_payable']);
    $paymentAmountPaid = (float)($payment['amount'] ?? 0);
    $paymentBalance = (float)($payment['balance_amount'] ?? max(0, $paymentTotalPayable - $paymentAmountPaid));
?>
<div class="card mb-3 advice-area">
    <div class="card-header text-white" style="background:linear-gradient(135deg,#0f766e,#0e7490)">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold">Lease Agent Payment Advice</div>
            <div class="no-print">
                <a href="?action=print&id=<?= (int)$payment['id'] ?>" target="_blank" class="btn btn-light btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
                <a href="fleet_lease_agent_payments.php" class="btn btn-outline-light btn-sm">Back to Register</a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-3"><div class="p-2 border rounded h-100"><small class="text-muted">Payment No</small><div class="fw-bold"><?= htmlspecialchars($payment['payment_no'] ?: ('LAP-' . (int)$payment['id'])) ?></div></div></div>
            <div class="col-12 col-md-3"><div class="p-2 border rounded h-100"><small class="text-muted">Lease Agent</small><div class="fw-bold"><?= htmlspecialchars(($payment['agent_name'] ?? '') . (!empty($payment['agent_code']) ? ' (' . $payment['agent_code'] . ')' : '')) ?></div></div></div>
            <div class="col-12 col-md-2"><div class="p-2 border rounded h-100"><small class="text-muted">Agent Invoice No</small><div class="fw-bold"><?= htmlspecialchars($payment['agent_invoice_no'] ?: '—') ?></div></div></div>
            <div class="col-12 col-md-2"><div class="p-2 border rounded h-100"><small class="text-muted">Period</small><div class="fw-bold"><?= htmlspecialchars(date('d/m/Y', strtotime($payment['date_from'])) . ' to ' . date('d/m/Y', strtotime($payment['date_to']))) ?></div></div></div>
            <div class="col-12 col-md-2"><div class="p-2 border rounded h-100"><small class="text-muted">Payment Date</small><div class="fw-bold"><?= htmlspecialchars(date('d/m/Y', strtotime($payment['payment_date']))) ?></div></div></div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Trips</small><div class="fw-bold"><?= number_format($totals['trip_count'], 0) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Weight (MT)</small><div class="fw-bold"><?= number_format($totals['total_weight'], 3) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Amount Paid</small><div class="fw-bold text-success">&#8377;<?= number_format($paymentAmountPaid, 2) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Balance Due</small><div class="fw-bold text-danger">&#8377;<?= number_format($paymentBalance, 2) ?></div></div></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>S.No</th>
                        <th>Trip No</th>
                        <th>Date</th>
                        <th>Vehicle</th>
                        <th class="text-end">Weight (MT)</th>
                        <th class="text-end">Customer Rate / MT</th>
                        <th class="text-end">Gross Freight</th>
                        <th class="text-end">Margin / MT</th>
                        <th class="text-end">Deduction</th>
                        <th class="text-end">Payable / MT</th>
                        <th class="text-end">Net Freight Payable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sno = 1; foreach ($tripRows as $trip): ?>
                    <?php
                        $weight = (float)($trip['total_weight'] ?? 0);
                        $gross = (float)($trip['freight_amount'] ?? 0);
                        $deduction = (float)($trip['lease_agent_amount'] ?? 0);
                        $net = fleetLeaseAgentTripBasePayable($trip);
                        $customerRate = $weight > 0 ? ($gross / $weight) : 0;
                        $payableRate = $weight > 0 ? ($net / $weight) : 0;
                    ?>
                    <tr>
                        <td><?= $sno++ ?></td>
                        <td><?= htmlspecialchars($trip['trip_no']) ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($trip['trip_date']))) ?></td>
                        <td><?= htmlspecialchars($trip['reg_no'] ?: '-') ?></td>
                        <td class="text-end"><?= number_format($weight, 3) ?></td>
                        <td class="text-end text-secondary">&#8377;<?= number_format($customerRate, 2) ?></td>
                        <td class="text-end text-success">&#8377;<?= number_format($gross, 2) ?></td>
                        <td class="text-end">&#8377;<?= number_format((float)($trip['lease_agent_margin_per_mt'] ?? 0), 2) ?></td>
                        <td class="text-end text-danger">&#8377;<?= number_format($deduction, 2) ?></td>
                        <td class="text-end text-primary">&#8377;<?= number_format($payableRate, 2) ?></td>
                        <td class="text-end fw-semibold">&#8377;<?= number_format($net, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$tripRows): ?>
                    <tr><td colspan="11" class="text-muted p-3">No trips linked to this payment.</td></tr>
                    <?php endif; ?>
                </tbody>
                    <tfoot class="table-light">
                    <tr>
                        <th colspan="4" class="text-end">Total</th>
                        <th class="text-end"><?= number_format($totals['total_weight'], 3) ?></th>
                        <th></th>
                        <th class="text-end text-success">&#8377;<?= number_format($totals['gross_freight'], 2) ?></th>
                        <th></th>
                        <th class="text-end text-danger">&#8377;<?= number_format($totals['lease_deduction'], 2) ?></th>
                        <th></th>
                        <th class="text-end text-primary">&#8377;<?= number_format($paymentTotalPayable, 2) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
</div>
</div>
<?php elseif ($action === 'edit' && !empty($paymentView['payment'])): ?>
<?php
    $payment = $paymentView['payment'];
    $tripRows = $paymentView['trips'];
    $totals = $paymentView['totals'];
    $paymentTotalPayable = (float)($payment['total_payable'] ?? $totals['net_payable']);
    $paymentAmountPaid = (float)($payment['amount'] ?? 0);
    $paymentBalance = (float)($payment['balance_amount'] ?? max(0, $paymentTotalPayable - $paymentAmountPaid));
?>
<div class="card mb-3 advice-area">
    <div class="card-header text-white" style="background:linear-gradient(135deg,#b45309,#92400e)">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold">Edit Lease Agent Payment</div>
            <div class="no-print">
                <a href="?action=print&id=<?= (int)$payment['id'] ?>" target="_blank" class="btn btn-light btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
                <a href="fleet_lease_agent_payments.php?action=view&id=<?= (int)$payment['id'] ?>" class="btn btn-outline-light btn-sm">Back</a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="update_payment" value="1">
            <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-3"><div class="p-2 border rounded h-100"><small class="text-muted">Payment No</small><div class="fw-bold"><?= htmlspecialchars($payment['payment_no'] ?: ('LAP-' . (int)$payment['id'])) ?></div></div></div>
                <div class="col-12 col-md-3"><div class="p-2 border rounded h-100"><small class="text-muted">Lease Agent</small><div class="fw-bold"><?= htmlspecialchars(($payment['agent_name'] ?? '') . (!empty($payment['agent_code']) ? ' (' . $payment['agent_code'] . ')' : '')) ?></div></div></div>
                <div class="col-6 col-md-2"><div class="p-2 border rounded h-100"><small class="text-muted">From</small><div class="fw-bold"><?= htmlspecialchars(date('d/m/Y', strtotime($payment['date_from']))) ?></div></div></div>
                <div class="col-6 col-md-2"><div class="p-2 border rounded h-100"><small class="text-muted">To</small><div class="fw-bold"><?= htmlspecialchars(date('d/m/Y', strtotime($payment['date_to']))) ?></div></div></div>
                <div class="col-6 col-md-2"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($payment['payment_date']) ?>" required></div>
                <div class="col-6 col-md-3"><label class="form-label">Lease Agent Invoice No</label><input type="text" name="agent_invoice_no" class="form-control" value="<?= htmlspecialchars($payment['agent_invoice_no'] ?? '') ?>" placeholder="e.g. INV-2026-001"></div>
                <div class="col-6 col-md-3"><label class="form-label">Amount Paid Now</label><input type="number" step="0.01" min="0" name="amount_paid" class="form-control" value="<?= htmlspecialchars((string)$paymentAmountPaid) ?>" required></div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status_manual" class="form-select" required>
                        <?php $curStatus = $payment['status'] ?? 'Paid'; ?>
                        <option value="Paid" <?= $curStatus === 'Paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="Unpaid" <?= $curStatus === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="Partial" <?= $curStatus === 'Partial' ? 'selected' : '' ?>>Partial</option>
                    </select>
                </div>
                <div class="col-12 col-md-3"><label class="form-label">Reference / Cheque No</label><input type="text" name="reference_no" class="form-control" value="<?= htmlspecialchars($payment['reference_no'] ?? '') ?>"></div>
                <div class="col-12 col-md-3"><label class="form-label">UTR / Transaction No</label><input type="text" name="utr_no" class="form-control" value="<?= htmlspecialchars($payment['utr_no'] ?? '') ?>"></div>
                <div class="col-12 col-md-3"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($payment['bank_name'] ?? '') ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($payment['notes'] ?? '') ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Trips</small><div class="fw-bold"><?= number_format($totals['trip_count'], 0) ?></div></div></div>
                <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Total Payable</small><div class="fw-bold text-success">&#8377;<?= number_format($paymentTotalPayable, 2) ?></div></div></div>
                <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Amount Paid</small><div class="fw-bold text-primary">&#8377;<?= number_format($paymentAmountPaid, 2) ?></div></div></div>
                <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><small class="text-muted">Balance Due</small><div class="fw-bold text-danger">&#8377;<?= number_format($paymentBalance, 2) ?></div></div></div>
            </div>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>S.No</th>
                            <th>Trip No</th>
                            <th>Date</th>
                            <th>Vehicle</th>
                            <th class="text-end">Weight (MT)</th>
                            <th class="text-end">Customer Rate / MT</th>
                            <th class="text-end">Gross Freight</th>
                            <th class="text-end">Margin / MT</th>
                            <th class="text-end">Deduction</th>
                            <th class="text-end">Payable / MT</th>
                            <th class="text-end">Net Freight Payable</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($tripRows as $trip): ?>
                        <?php
                            $weight = (float)($trip['total_weight'] ?? 0);
                            $gross = (float)($trip['freight_amount'] ?? 0);
                            $deduction = (float)($trip['lease_agent_amount'] ?? 0);
                            $net = fleetLeaseAgentTripBasePayable($trip);
                            $customerRate = $weight > 0 ? ($gross / $weight) : 0;
                            $payableRate = $weight > 0 ? ($net / $weight) : 0;
                        ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= htmlspecialchars($trip['trip_no']) ?></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($trip['trip_date']))) ?></td>
                            <td><?= htmlspecialchars($trip['reg_no'] ?: '-') ?></td>
                            <td class="text-end"><?= number_format($weight, 3) ?></td>
                            <td class="text-end text-secondary">&#8377;<?= number_format($customerRate, 2) ?></td>
                            <td class="text-end text-success">&#8377;<?= number_format($gross, 2) ?></td>
                            <td class="text-end">&#8377;<?= number_format((float)($trip['lease_agent_margin_per_mt'] ?? 0), 2) ?></td>
                            <td class="text-end text-danger">&#8377;<?= number_format($deduction, 2) ?></td>
                            <td class="text-end text-primary">&#8377;<?= number_format($payableRate, 2) ?></td>
                            <td class="text-end fw-semibold">&#8377;<?= number_format($net, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="4" class="text-end">Total</th>
                            <th class="text-end"><?= number_format($totals['total_weight'], 3) ?></th>
                            <th></th>
                            <th class="text-end text-success">&#8377;<?= number_format($totals['gross_freight'], 2) ?></th>
                            <th></th>
                            <th class="text-end text-danger">&#8377;<?= number_format($totals['lease_deduction'], 2) ?></th>
                            <th></th>
                            <th class="text-end text-primary">&#8377;<?= number_format($paymentTotalPayable, 2) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="d-flex gap-2 no-print">
                <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>Update Payment</button>
                <a href="fleet_lease_agent_payments.php?action=view&id=<?= (int)$payment['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php elseif ($action === 'advice' && $agentId > 0): ?>
<?php
    $agentRow = $db->query("SELECT agent_name, agent_code, tax_type, tax_rate FROM fleet_lease_agents WHERE id=$agentId LIMIT 1")->fetch_assoc() ?: [];
    $previewAgent = ($agentRow['agent_name'] ?? '') . (!empty($agentRow['agent_code']) ? ' (' . $agentRow['agent_code'] . ')' : '');
    $agentTaxType = $agentRow['tax_type'] ?? 'None';
    $agentTaxRate = (float)($agentRow['tax_rate'] ?? 0);
    // Load ALL unpaid BILLED completed trips for this agent (bill-wise, no date filter)
    $billRows = $db->query("
        SELECT t.id, t.trip_no, t.trip_date, t.total_weight,
               t.freight_amount, t.lease_agent_billing_model, la.billing_model, t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
               t.lease_agent_billing_status, t.lease_agent_invoice_no, t.lease_agent_invoice_date,
               v.reg_no,
               COALESCE(btco.company_name, co.company_name, laco.company_name, 'General') AS company_name
        FROM fleet_trips t
        LEFT JOIN fleet_vehicles v ON v.id = t.vehicle_id
        LEFT JOIN fleet_lease_agents la ON la.id = t.lease_agent_id
        LEFT JOIN companies laco ON laco.id = la.company_id
        LEFT JOIN companies co ON co.id = t.company_id
        LEFT JOIN companies btco ON btco.id = t.lease_agent_bill_to_company_id
        LEFT JOIN fleet_lease_agent_payment_trips pt ON pt.trip_id = t.id
        WHERE t.status = 'Completed'
          AND t.lease_agent_id = $agentId
          AND t.lease_agent_billing_status = 'Billed'
          AND pt.trip_id IS NULL
        ORDER BY COALESCE(btco.company_name, co.company_name, laco.company_name, 'General') ASC, t.trip_date ASC, t.id ASC
    ")->fetch_all(MYSQLI_ASSOC);
    $hasTax = $agentTaxRate > 0;
?>
<div class="card mb-3">
    <div class="card-header text-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:linear-gradient(135deg,#0f766e,#0e7490)">
        <div>
            <div class="fw-semibold"><i class="bi bi-receipt me-2"></i>Select Bills to Record Payment</div>
            <div style="font-size:.85rem;opacity:.85"><?= htmlspecialchars($previewAgent) ?><?= $hasTax ? ' &mdash; ' . htmlspecialchars($agentTaxType) . ' ' . number_format($agentTaxRate, 2) . '%' : '' ?></div>
        </div>
        <a href="fleet_lease_agent_payments.php" class="btn btn-outline-light btn-sm">Back to Register</a>
    </div>
    <div class="card-body">
        <?php if (empty($billRows)): ?>
        <div class="alert alert-warning mb-0">
            <i class="bi bi-exclamation-triangle me-2"></i>
            No bills ready for payment. Only trips with <strong>Agent Billing Status = Billed</strong> can be paid.
            Please bill the trips first before recording payment.
        </div>
        <?php else: ?>
        <form method="POST" id="billPayForm">
            <input type="hidden" name="save_payment" value="1">
            <input type="hidden" name="lease_agent_id" value="<?= (int)$agentId ?>">
            <div class="row g-3 mb-3 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($paymentDate) ?>" required>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold">Agent Invoice No</label>
                    <input type="text" name="agent_invoice_no" class="form-control" placeholder="e.g. INV-2026-001">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold">Initial Status</label>
                    <select name="payment_status_manual" class="form-select">
                        <option value="Unpaid" <?= ($manualPaymentStatus === '' || $manualPaymentStatus === 'Unpaid') ? 'selected' : '' ?>>Unpaid</option>
                        <option value="Paid"    <?= $manualPaymentStatus === 'Paid'    ? 'selected' : '' ?>>Paid</option>
                        <option value="Partial" <?= $manualPaymentStatus === 'Partial' ? 'selected' : '' ?>>Partial</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <div class="alert alert-primary mb-0 py-2 px-3" id="selectionSummary">
                        <i class="bi bi-info-circle me-1"></i>
                        <span id="selSummaryText">Select bills using checkboxes or click a row.</span>
                    </div>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-success" id="recordBtn" disabled>
                        <i class="bi bi-check-circle me-1"></i>Record Payment
                    </button>
                    <a href="fleet_lease_agent_payments.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-bordered align-middle mb-0" id="billTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:38px"><input type="checkbox" id="selectAllBills" class="form-check-input" title="Select / Deselect All"></th>
                            <th>#</th>
                            <th>Trip No</th>
                            <th>Trip Date</th>
                            <th>Invoice No</th>
                            <th>Invoice Date</th>
                            <th>Vehicle</th>
                            <th class="text-end">Weight (MT)</th>
                            <th class="text-end">Net Payable</th>
                            <?php if ($hasTax): ?><th class="text-end">Tax (<?= number_format($agentTaxRate, 2) ?>%)</th><?php endif; ?>
                            <th class="text-end">Gross Payable</th>
                            <th>Billing</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $bsno = 1;
                        $bTotalNet = 0; $bTotalTax = 0; $bTotalGross = 0;
                        // Group bills by company
                        $billsByCompany = [];
                        foreach ($billRows as $bill) {
                            $coName = $bill['company_name'] ?? 'General';
                            $billsByCompany[$coName][] = $bill;
                        }
                        $numCols = $hasTax ? 12 : 11;
                        foreach ($billsByCompany as $coName => $coBills):
                            $coNet = 0; $coTax = 0; $coGross = 0;
                    ?>
                        <tr class="table-secondary">
                            <th colspan="<?= $numCols ?>" class="px-3 py-1" style="font-size:.82rem;letter-spacing:.04em">
                                <i class="bi bi-building me-1"></i><?= htmlspecialchars($coName) ?>
                                <span class="badge bg-dark ms-2"><?= count($coBills) ?> bill<?= count($coBills) > 1 ? 's' : '' ?></span>
                            </th>
                        </tr>
                        <?php foreach ($coBills as $bill):
                            $b_net = fleetLeaseAgentTripBasePayable($bill);
                            $b_tax   = $b_net * ($agentTaxRate / 100);
                            $b_gross = $b_net + $b_tax;
                            $bTotalNet += $b_net; $bTotalTax += $b_tax; $bTotalGross += $b_gross;
                            $coNet += $b_net; $coTax += $b_tax; $coGross += $b_gross;
                        ?>
                        <tr class="bill-row" data-net="<?= round($b_net,4) ?>" data-tax="<?= round($b_tax,4) ?>" data-gross="<?= round($b_gross,4) ?>" style="cursor:pointer">
                            <td onclick="event.stopPropagation()"><input type="checkbox" name="trip_ids[]" value="<?= (int)$bill['id'] ?>" class="form-check-input bill-chk"></td>
                            <td><?= $bsno++ ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($bill['trip_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($bill['trip_date'])) ?></td>
                            <td><?= htmlspecialchars($bill['lease_agent_invoice_no'] ?: '&mdash;') ?></td>
                            <td><?= ($bill['lease_agent_invoice_date'] && $bill['lease_agent_invoice_date'] !== '0000-00-00') ? date('d/m/Y', strtotime($bill['lease_agent_invoice_date'])) : '&mdash;' ?></td>
                            <td><?= htmlspecialchars($bill['reg_no'] ?: '-') ?></td>
                            <td class="text-end"><?= number_format((float)($bill['total_weight'] ?? 0), 3) ?></td>
                            <td class="text-end">&#8377;<?= number_format($b_net, 2) ?></td>
                            <?php if ($hasTax): ?><td class="text-end">&#8377;<?= number_format($b_tax, 2) ?></td><?php endif; ?>
                            <td class="text-end fw-semibold" style="color:#0f766e">&#8377;<?= number_format($b_gross, 2) ?></td>
                            <td><?= $bill['lease_agent_billing_status'] === 'Billed' ? '<span class="badge bg-primary">Billed</span>' : '<span class="badge bg-secondary">Pending</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-info" style="font-size:.82rem">
                            <th colspan="8" class="text-end text-muted fst-italic ps-3">Subtotal &mdash; <?= htmlspecialchars($coName) ?></th>
                            <th class="text-end">&#8377;<?= number_format($coNet, 2) ?></th>
                            <?php if ($hasTax): ?><th class="text-end">&#8377;<?= number_format($coTax, 2) ?></th><?php endif; ?>
                            <th class="text-end" style="color:#0f766e">&#8377;<?= number_format($coGross, 2) ?></th>
                            <th></th>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr>
                            <th colspan="8" class="text-end text-muted"><?= count($billRows) ?> bills total (<?= count($billsByCompany) ?> company<?= count($billsByCompany) > 1 ? 'ies' : '' ?>)</th>
                            <th class="text-end">&#8377;<?= number_format($bTotalNet, 2) ?></th>
                            <?php if ($hasTax): ?><th class="text-end">&#8377;<?= number_format($bTotalTax, 2) ?></th><?php endif; ?>
                            <th class="text-end" style="color:#0f766e">&#8377;<?= number_format($bTotalGross, 2) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    const chks      = document.querySelectorAll('.bill-chk');
    const selAll    = document.getElementById('selectAllBills');
    const recBtn    = document.getElementById('recordBtn');
    const summTxt   = document.getElementById('selSummaryText');
    const summBox   = document.getElementById('selectionSummary');
    const hasTax    = <?= $hasTax ? 'true' : 'false' ?>;

    function fmt(n) { return '\u20B9' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function updateSummary() {
        let count = 0, net = 0, tax = 0, gross = 0;
        chks.forEach(c => {
            if (c.checked) {
                const row = c.closest('tr');
                count++;
                net   += parseFloat(row.dataset.net   || 0);
                tax   += parseFloat(row.dataset.tax   || 0);
                gross += parseFloat(row.dataset.gross || 0);
            }
        });
        recBtn.disabled = count === 0;
        if (count === 0) {
            summTxt.textContent = 'Select bills using checkboxes or click a row.';
            summBox.className = 'alert alert-primary mb-0 py-2 px-3';
        } else {
            let txt = count + ' bill' + (count > 1 ? 's' : '') + ' selected  \u2014  Net: ' + fmt(net);
            if (hasTax) txt += '  |  Tax: ' + fmt(tax);
            txt += '  |  Gross Payable: ' + fmt(gross);
            summTxt.textContent = txt;
            summBox.className = 'alert alert-success mb-0 py-2 px-3';
        }
        const allChecked = count === chks.length;
        selAll.checked       = allChecked;
        selAll.indeterminate = count > 0 && !allChecked;
    }

    chks.forEach(c => c.addEventListener('change', updateSummary));
    selAll.addEventListener('change', function () {
        chks.forEach(c => { c.checked = this.checked; });
        updateSummary();
    });
    document.querySelectorAll('.bill-row').forEach(row => {
        row.addEventListener('click', function (e) {
            if (e.target.closest('td:first-child')) return;
            const chk = this.querySelector('.bill-chk');
            chk.checked = !chk.checked;
            updateSummary();
        });
    });
    updateSummary();
})();
</script>
<?php endif; ?>

<h5 class="mb-3 fw-bold">Lease Agent Payment Register</h5>

<?php
    // Group payments by company
    $paymentsByCompany = [];
    foreach ($payments as $payment) {
        $co = $payment['company_name'] ?? 'General';
        $paymentsByCompany[$co][] = $payment;
    }

    if (empty($paymentsByCompany)):
?>
<div class="card mb-3">
    <div class="card-body text-muted text-center py-4">No lease agent payments found.</div>
</div>
<?php else:
    $gi = 0;
    foreach ($paymentsByCompany as $coName => $coPays):
        $gi++;
        $grp_id = 'lap_cogrp_' . $gi;
        $coTotalPayable = 0; $coPaid = 0; $coBalance = 0;
        foreach ($coPays as $p) {
            $coTotalPayable += (float)($p['total_payable'] ?? $p['amount'] ?? 0);
            $coPaid += (float)($p['amount'] ?? 0);
            $coBalance += (float)($p['balance_amount'] ?? max(0, (float)($p['total_payable'] ?? 0) - (float)($p['amount'] ?? 0)));
        }
?>
<div class="card mb-3 company-group shadow-sm" id="<?= $grp_id ?>">
    <div class="card-header d-flex align-items-center justify-content-between py-2.5 company-group-header"
         style="cursor:pointer;background:linear-gradient(135deg,#1E3A8A,#334155);border-left:4px solid #172554;color:#fff"
         onclick="toggleLapCoGroup('<?= $grp_id ?>')">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-chevron-down text-white" id="chev_<?= $grp_id ?>"></i>
            <i class="bi bi-building text-white"></i>
            <strong class="text-white" style="font-size:1rem"><?= htmlspecialchars($coName) ?></strong>
            <span class="badge bg-white text-dark ms-2"><?= count($coPays) ?> Payment<?= count($coPays) > 1 ? 's' : '' ?></span>
        </div>
        <div class="d-flex align-items-center gap-3 text-white" style="font-size:.88rem">
            <span>Payable: <strong style="color:#6ee7b7">&#8377;<?= number_format($coTotalPayable, 2) ?></strong></span>
            <span>Paid: <strong style="color:#7dd3fc">&#8377;<?= number_format($coPaid, 2) ?></strong></span>
            <span>Balance: <strong style="color:#fca5a5">&#8377;<?= number_format($coBalance, 2) ?></strong></span>
        </div>
    </div>
    <div class="table-responsive" id="tbl_<?= $grp_id ?>">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Payment No</th>
                    <th>Lease Agent</th>
                    <th>Agent Invoice No</th>
                    <th>Period</th>
                    <th class="text-end">Trips</th>
                    <th class="text-end">Total Payable</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th>Payment Date</th>
                    <th>Reference</th>
                    <th>UTR</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($coPays as $payment):
                        $pid = (int)$payment['id'];
                        $rowTotalPayable = (float)($payment['total_payable'] ?? $payment['amount'] ?? 0);
                        $rowPaid = (float)($payment['amount'] ?? 0);
                        $rowBalance = (float)($payment['balance_amount'] ?? max(0, $rowTotalPayable - $rowPaid));
                        $rowStatus = $payment['status'] ?: ($rowBalance > 0 ? 'Partial' : 'Paid');
                        $statusClass = $rowStatus === 'Paid' ? 'success' : ($rowStatus === 'Partial' ? 'warning text-dark fw-bold' : 'danger fw-bold');
                        $statusIcon  = $rowStatus === 'Paid' ? 'check-circle' : ($rowStatus === 'Partial' ? 'hourglass-split' : 'x-circle');
                        $linkedTrips = $paymentTripsMap[$pid] ?? [];
                        $taxRate = (float)($payment['tax_rate'] ?? 0);
                        $displayInvoiceNo = !empty($payment['agent_invoice_no'])
                            ? $payment['agent_invoice_no']
                            : (!empty($payment['trip_invoice_nos']) ? $payment['trip_invoice_nos'] : '—');
                ?>
                <tr style="cursor:pointer" onclick="togglePaymentDetail(<?= $pid ?>)">
                    <td class="fw-semibold">
                        <i class="bi bi-chevron-right text-primary me-1" id="chev_pay_<?= $pid ?>"></i>
                        <?= htmlspecialchars($payment['payment_no'] ?: ('LAP-' . $pid)) ?>
                    </td>
                    <td><?= htmlspecialchars($payment['agent_name'] . (!empty($payment['agent_code']) ? ' (' . $payment['agent_code'] . ')' : '')) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($displayInvoiceNo) ?></span></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($payment['date_from'])) . ' to ' . date('d/m/Y', strtotime($payment['date_to']))) ?></td>
                    <td class="text-end"><?= number_format((int)$payment['trip_count'], 0) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format($rowTotalPayable, 2) ?></td>
                    <td class="text-end text-primary">&#8377;<?= number_format($rowPaid, 2) ?></td>
                    <td class="text-end text-danger">&#8377;<?= number_format($rowBalance, 2) ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($payment['payment_date']))) ?></td>
                    <td><?= htmlspecialchars($payment['reference_no'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($payment['utr_no'] ?: '—') ?></td>
                    <td><span class="badge bg-<?= $statusClass ?>"><i class="bi bi-<?= $statusIcon ?> me-1"></i><?= htmlspecialchars($rowStatus) ?></span></td>
                    <td class="text-end" onclick="event.stopPropagation()">
                        <a href="?action=view&id=<?= $pid ?>" class="btn btn-outline-primary btn-sm" title="View Detail"><i class="bi bi-eye"></i></a>
                        <a href="print_lease_agent_payment_advice.php?id=<?= $pid ?>&lang=en" target="_blank" class="btn btn-outline-secondary btn-sm" title="Print Advice (English)"><i class="bi bi-printer me-1"></i>EN</a>
                        <a href="print_lease_agent_payment_advice.php?id=<?= $pid ?>&lang=hi" target="_blank" class="btn btn-outline-success btn-sm" title="प्रिंट सूचना (Hindi)"><i class="bi bi-printer me-1"></i>HI</a>
                        <?php if ($isAdminUser): ?>
                        <a href="?action=edit&id=<?= $pid ?>" class="btn btn-outline-warning btn-sm" title="Edit Payment"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-outline-danger btn-sm"
                            onclick="confirmDeletePayment(<?= $pid ?>, '<?= htmlspecialchars(addslashes($payment['payment_no'] ?: ('LAP-' . $pid))) ?>')"
                            title="Delete payment">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr id="pay_detail_<?= $pid ?>" style="display:none" class="bg-light">
                    <td colspan="13" class="p-3">
                        <div class="card card-body bg-white border shadow-sm p-3 mb-0">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-journal-text me-1 text-primary"></i>Included Trip Details for <?= htmlspecialchars($payment['payment_no'] ?: ('LAP-' . $pid)) ?></h6>
                                <span class="badge bg-primary"><?= count($linkedTrips) ?> Trip<?= count($linkedTrips) > 1 ? 's' : '' ?></span>
                            </div>
                            <?php if (empty($linkedTrips)): ?>
                                <div class="text-muted small">No trip details found for this payment record.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.84rem">
                                        <thead class="table-dark">
                                            <tr>
                                                <th class="text-center">#</th>
                                                <th>Trip No</th>
                                                <th>Agent Invoice No</th>
                                                <th>Date</th>
                                                <th>Vehicle</th>
                                                <th>Bill To Company</th>
                                                <th>Vendor Name</th>
                                                <th class="text-end">Weight (MT)</th>
                                                <th class="text-end">Lease Agent Rate</th>
                                                <th class="text-end">Net Freight</th>
                                                <th class="text-end">GST (<?= number_format($taxRate, 2) ?>%)</th>
                                                <th class="text-end">Net Payable</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $sno = 1;
                                            $totW = 0; $totNet = 0; $totGst = 0; $totNp = 0;
                                            foreach ($linkedTrips as $t):
                                                $w = (float)($t['total_weight'] ?? 0);
                                                $net = fleetLeaseAgentTripBasePayable($t);
                                                $r = $w > 0 ? ($net / $w) : 0;
                                                $gst = $net * ($taxRate / 100);
                                                $np = $net + $gst;
                                                $totW += $w; $totNet += $net; $totGst += $gst; $totNp += $np;
                                            ?>
                                            <tr>
                                                <td class="text-center"><?= $sno++ ?></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($t['trip_no']) ?></td>
                                                <td><?= htmlspecialchars($t['lease_agent_invoice_no'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars(date('d/m/Y', strtotime($t['trip_date']))) ?></td>
                                                <td><?= htmlspecialchars($t['reg_no'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($t['company_name'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($t['vendor_name'] ?: '-') ?></td>
                                                <td class="text-end"><?= number_format($w, 3) ?></td>
                                                <td class="text-end">&#8377;<?= number_format($r, 2) ?></td>
                                                <td class="text-end">&#8377;<?= number_format($net, 2) ?></td>
                                                <td class="text-end">&#8377;<?= number_format($gst, 2) ?></td>
                                                <td class="text-end fw-bold text-success">&#8377;<?= number_format($np, 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-light fw-bold">
                                                <td colspan="7" class="text-end">Total</td>
                                                <td class="text-end"><?= number_format($totW, 3) ?></td>
                                                <td></td>
                                                <td class="text-end">&#8377;<?= number_format($totNet, 2) ?></td>
                                                <td class="text-end">&#8377;<?= number_format($totGst, 2) ?></td>
                                                <td class="text-end text-success">&#8377;<?= number_format($totNp, 2) ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; endif; ?>

<script>
function toggleLapCoGroup(id) {
    const tbl = document.getElementById('tbl_' + id);
    const chev = document.getElementById('chev_' + id);
    if (!tbl) return;
    if (tbl.style.display === 'none') {
        tbl.style.display = '';
        if (chev) chev.className = 'bi bi-chevron-down text-white';
    } else {
        tbl.style.display = 'none';
        if (chev) chev.className = 'bi bi-chevron-right text-white';
    }
}
function togglePaymentDetail(id) {
    const row = document.getElementById('pay_detail_' + id);
    const chev = document.getElementById('chev_pay_' + id);
    if (!row) return;
    if (row.style.display === 'none') {
        row.style.display = '';
        if (chev) chev.className = 'bi bi-chevron-down text-primary me-1';
    } else {
        row.style.display = 'none';
        if (chev) chev.className = 'bi bi-chevron-right text-primary me-1';
    }
}
</script>

<style>
@media print {
    .no-print, .navbar, .sidebar, footer { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { background: #fff !important; }
}
</style>

<!-- Delete Payment Confirm Modal -->
<div class="modal fade" id="deletePaymentModal" tabindex="-1" aria-labelledby="deletePaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deletePaymentModalLabel"><i class="bi bi-exclamation-triangle me-2"></i>Delete Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete payment <strong id="delPaymentNo"></strong>?</p>
                <p class="text-danger mb-0"><small><i class="bi bi-info-circle me-1"></i>This will also unlink all trips from this payment, making them available for payment again. This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="deletePaymentForm" style="display:inline">
                    <input type="hidden" name="delete_payment" value="1">
                    <input type="hidden" name="payment_id" id="delPaymentId" value="">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function confirmDeletePayment(id, payNo) {
    document.getElementById('delPaymentId').value = id;
    document.getElementById('delPaymentNo').textContent = payNo;
    new bootstrap.Modal(document.getElementById('deletePaymentModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
