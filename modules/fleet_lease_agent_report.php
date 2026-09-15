<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_lease_agent_helper.php';

$db = getDB();
if (!canDo('fleet_lease_agent_report', 'view') && !canDo('fleet_lease_agents', 'view') && !canDo('reports', 'view')) {
    requirePerm('reports', 'view');
}
fleetEnsureLeaseAgents($db);
fleetEnsureLeaseTripFields($db);

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
$agent_filter = (int)($_GET['lease_agent_id'] ?? 0);
$is_lease_agent_user = isLeaseAgentUser();
if ($is_lease_agent_user) {
    $agent_filter = currentLeaseAgentId();
}

$billing_filter = $_GET['billing_status'] ?? 'all';
if (!in_array($billing_filter, ['all', 'billed', 'unbilled'], true)) $billing_filter = 'all';

$payment_filter = $_GET['payment_status'] ?? 'all';
if (!in_array($payment_filter, ['all', 'paid', 'unpaid', 'partial'], true)) $payment_filter = 'all';

$agents_sql = $is_lease_agent_user && $agent_filter > 0
    ? "SELECT id, agent_code, agent_name, default_margin_per_mt, status
       FROM fleet_lease_agents
       WHERE id=$agent_filter
       ORDER BY status ASC, agent_name ASC"
    : "SELECT id, agent_code, agent_name, default_margin_per_mt, status
       FROM fleet_lease_agents
       ORDER BY status ASC, agent_name ASC";
$agents = $db->query($agents_sql)->fetch_all(MYSQLI_ASSOC);

$where = "WHERE t.status='Completed' AND $trip_period_expr BETWEEN '$month_start' AND '$month_end'";
if ($agent_filter > 0) {
    $where .= " AND t.lease_agent_id=$agent_filter";
} else {
    $where .= " AND COALESCE(t.lease_agent_id,0) > 0";
}

$report_action = $_GET['action'] ?? 'list';
if ($report_action === 'print_unbilled') {
    $unbilled_where = $where . " AND COALESCE(t.lease_agent_billing_status,'Pending') <> 'Billed'";
    $group_by_agent = $agent_filter <= 0;
    $order_by = $group_by_agent
        ? "lease_agent_name ASC, tax_rate ASC, co.company_name ASC, $trip_period_expr ASC, t.id ASC"
        : "tax_rate ASC, co.company_name ASC, $trip_period_expr ASC, t.id ASC";
    $unbilled_rows = $db->query("SELECT
            t.id, t.trip_no, t.trip_date, t.lease_agent_id,
            COALESCE(NULLIF(TRIM(t.lease_agent_name),''), la.agent_name, CONCAT('Agent #', t.lease_agent_id)) AS lease_agent_name,
            COALESCE(la.agent_code, '') AS lease_agent_code,
            COALESCE(la.tax_type, 'None') AS tax_type,
            CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END AS tax_rate,
            t.customer_name, t.company_id, COALESCE(co.company_name, 'No Company') AS company_name,
            t.lease_agent_bill_to_company_id, COALESCE(btco.company_name, co.company_name, 'No Company') AS bill_to_company_name,
            t.total_weight, t.freight_amount, t.lease_agent_billing_model, la.billing_model, t.lease_agent_billing_model, la.billing_model, t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
            t.lease_agent_misc_deduction, t.lease_agent_misc_deduction_remarks,
            v.reg_no
        FROM fleet_trips t
        LEFT JOIN fleet_vehicles v ON v.id=t.vehicle_id
        LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
        LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
        LEFT JOIN companies co ON co.id=t.company_id
        LEFT JOIN companies btco ON btco.id=t.lease_agent_bill_to_company_id
        $unbilled_where
        ORDER BY $order_by")->fetch_all(MYSQLI_ASSOC);

    $unbilled_totals = ['trip_count' => 0, 'total_weight' => 0.0, 'gross_freight' => 0.0, 'lease_deduction' => 0.0, 'misc_deduction' => 0.0, 'net_freight' => 0.0, 'tax_amount' => 0.0, 'gross_payable' => 0.0];
    foreach ($unbilled_rows as $row) {
        $unbilled_totals['trip_count']++;
        $unbilled_totals['total_weight']  += (float)($row['total_weight'] ?? 0);
        $unbilled_totals['gross_freight'] += (float)($row['freight_amount'] ?? 0);
        $unbilled_totals['lease_deduction'] += (float)($row['lease_agent_amount'] ?? 0);
        $row_misc_ded = (float)($row['lease_agent_misc_deduction'] ?? 0);
        $unbilled_totals['misc_deduction'] += $row_misc_ded;
        $row_net = fleetLeaseAgentTripBasePayable($row);
        $row_tax = $row_net * ((float)($row['tax_rate'] ?? 0) / 100);
        $unbilled_totals['net_freight'] += $row_net;
        $unbilled_totals['tax_amount'] += $row_tax;
        $unbilled_totals['gross_payable'] += $row_net + $row_tax - $row_misc_ded;
    }

    $print_payments_where = "WHERE p.payment_date BETWEEN '$month_start' AND '$month_end'"
        . ($agent_filter > 0 ? " AND p.lease_agent_id=$agent_filter" : "");
    $print_payment_totals = $db->query("
        SELECT COALESCE(SUM(p.amount),0) AS total_paid
        FROM fleet_lease_agent_payments p
        $print_payments_where
    ")->fetch_assoc();
    $print_paid = (float)($print_payment_totals['total_paid'] ?? 0);
    // Balance Due here = what's still outstanding on these UNBILLED trips specifically —
    // Gross Payable minus whatever's been paid to this agent this month. (Not the balance_amount
    // from existing payment records, since unbilled trips by definition have no invoice/payment
    // of their own yet — that field would always read 0 and wrongly imply nothing is owed.)
    $print_balance = $unbilled_totals['gross_payable'] - $print_paid;

    function leaseTaxLabel(string $type, float $rate): string {
        if ($type === 'None' || $type === '') return 'No Tax';
        $label = $type;
        if ($rate > 0) $label .= ' ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
        return $label;
    }

    // Group rows agent-wise and GST rate-wise, then by Bill To Company
    $agent_groups = [];
    foreach ($unbilled_rows as $row) {
        $uTaxRateVal = (float)($row['tax_rate'] ?? 0);
        $uTaxRateKey = number_format($uTaxRateVal, 2, '.', '');
        $uTaxTypeKey = (string)($row['tax_type'] ?? 'None');
        $groupKey = ((int)($row['lease_agent_id'] ?? 0)) . '_' . $uTaxTypeKey . '_' . $uTaxRateKey;
        if (!isset($agent_groups[$groupKey])) {
            $label = $row['lease_agent_name'] . (!empty($row['lease_agent_code']) ? ' (' . $row['lease_agent_code'] . ')' : '');
            $agent_groups[$groupKey] = [
                'label' => $label,
                'tax_label' => leaseTaxLabel($uTaxTypeKey, $uTaxRateVal),
                'tax_type' => $uTaxTypeKey,
                'tax_rate' => $uTaxRateVal,
                'companies' => [],
                'totals' => ['trip_count' => 0, 'total_weight' => 0.0, 'net_freight' => 0.0, 'tax_amount' => 0.0, 'misc_deduction' => 0.0, 'gross_payable' => 0.0],
            ];
        }
        $coKey = (int)($row['lease_agent_bill_to_company_id'] ?: $row['company_id'] ?? 0);
        if (!isset($agent_groups[$groupKey]['companies'][$coKey])) {
            $agent_groups[$groupKey]['companies'][$coKey] = [
                'label' => $row['bill_to_company_name'] ?? $row['company_name'] ?? 'No Company',
                'rows' => [],
                'totals' => ['trip_count' => 0, 'total_weight' => 0.0, 'net_freight' => 0.0, 'tax_amount' => 0.0, 'misc_deduction' => 0.0, 'gross_payable' => 0.0],
            ];
        }
        $u_weight = (float)($row['total_weight'] ?? 0);
        $u_net = fleetLeaseAgentTripBasePayable($row);
        $u_misc_ded = (float)($row['lease_agent_misc_deduction'] ?? 0);
        $u_tax_amt = $u_net * ((float)($row['tax_rate'] ?? 0) / 100);
        $u_gross = max(0, $u_net + $u_tax_amt - $u_misc_ded);
        $agent_groups[$groupKey]['companies'][$coKey]['rows'][] = $row;
        $agent_groups[$groupKey]['companies'][$coKey]['totals']['trip_count']++;
        $agent_groups[$groupKey]['companies'][$coKey]['totals']['total_weight'] += $u_weight;
        $agent_groups[$groupKey]['companies'][$coKey]['totals']['net_freight'] += $u_net;
        $agent_groups[$groupKey]['companies'][$coKey]['totals']['tax_amount'] += $u_tax_amt;
        $agent_groups[$groupKey]['companies'][$coKey]['totals']['misc_deduction'] = ($agent_groups[$groupKey]['companies'][$coKey]['totals']['misc_deduction'] ?? 0) + $u_misc_ded;
        $agent_groups[$groupKey]['companies'][$coKey]['totals']['gross_payable'] += $u_gross;
        $agent_groups[$groupKey]['totals']['trip_count']++;
        $agent_groups[$groupKey]['totals']['total_weight'] += $u_weight;
        $agent_groups[$groupKey]['totals']['net_freight'] += $u_net;
        $agent_groups[$groupKey]['totals']['tax_amount'] += $u_tax_amt;
        $agent_groups[$groupKey]['totals']['misc_deduction'] = ($agent_groups[$groupKey]['totals']['misc_deduction'] ?? 0) + $u_misc_ded;
        $agent_groups[$groupKey]['totals']['gross_payable'] += $u_gross;
    }

    $print_agent_label = 'All Agents';
    $print_agent_tax_label = '';
    if ($agent_filter > 0) {
        $agentRow = $db->query("SELECT agent_name, agent_code, tax_type, tax_rate FROM fleet_lease_agents WHERE id=$agent_filter LIMIT 1")->fetch_assoc() ?: [];
        $print_agent_label = trim(($agentRow['agent_name'] ?? '') . (!empty($agentRow['agent_code']) ? ' (' . $agentRow['agent_code'] . ')' : ''));
        if ($print_agent_label === '') $print_agent_label = 'Agent #' . $agent_filter;
        $print_agent_tax_label = leaseTaxLabel((string)($agentRow['tax_type'] ?? 'None'), (float)($agentRow['tax_rate'] ?? 0));
    }

    $lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
    if ($lang !== 'hi') $lang = 'en';

    $ub_dict = [
        'en' => [
            'doc_title'      => 'Lease Agent Trip Orders Details',
            'title'          => 'Lease Agent Trip Orders Details for the Month of ',
            'month'          => 'Month:',
            'printed'        => 'Printed:',
            'unbilled_trips' => 'Unbilled Trips',
            'weight'         => 'Weight (MT)',
            'net_payable'    => 'Net Freight',
            'tax_amount'     => 'Tax Amount',
            'misc_deduction' => 'Misc Deduction',
            'gross_payable'  => 'Gross Payable',
            'paid'           => 'Paid (this month)',
            'balance_due'    => 'Balance Due',
            'col_sno'        => '#',
            'col_date'       => 'Date',
            'col_trip_no'    => 'Trip No',
            'col_tax'        => 'Tax',
            'col_vehicle'    => 'Vehicle',
            'col_bill_to'    => 'Bill To Company',
            'col_customer'   => 'Customer',
            'col_weight'     => 'Weight (MT)',
            'col_rate'       => 'Payable / MT',
            'col_net'        => 'Net Freight',
            'col_tax_amt'    => 'Tax Amount',
            'col_misc_ded'   => 'Misc Ded',
            'col_gross'      => 'Gross Payable',
            'no_trips'       => 'No unbilled trips for the selected month.',
            'agent_subtotal' => 'Agent Subtotal',
            'company_subtotal' => 'Subtotal',
            'grand_total'    => 'Grand Total',
            'credit'         => '(Credit) ',
            'btn_print'      => 'Print Statement',
            'btn_close'      => 'Close',
            'lang_label'     => 'Language:',
        ],
        'hi' => [
            'doc_title'      => 'लीज एजेंट ट्रिप विवरण (Unbilled Trips)',
            'title'          => 'लीज एजेंट ट्रिप विवरण - माह: ',
            'month'          => 'माह:',
            'printed'        => 'प्रिंट दिनांक:',
            'unbilled_trips' => 'गैर-बिल योग्य ट्रिप्स',
            'weight'         => 'वजन (मीट्रिक टन)',
            'net_payable'    => 'शुद्ध भाड़ा',
            'tax_amount'     => 'कर राशि (Tax)',
            'misc_deduction' => 'अन्य कटौती (Misc)',
            'gross_payable'  => 'कुल देय राशि',
            'paid'           => 'भुगतान (इस माह)',
            'balance_due'    => 'बकाया राशि (Balance)',
            'col_sno'        => 'क्र.',
            'col_date'       => 'दिनांक',
            'col_trip_no'    => 'ट्रिप नंबर',
            'col_tax'        => 'कर',
            'col_vehicle'    => 'वाहन',
            'col_bill_to'    => 'बिल टू कंपनी',
            'col_customer'   => 'ग्राहक (Customer)',
            'col_weight'     => 'वजन (MT)',
            'col_rate'       => 'देय दर / MT',
            'col_net'        => 'शुद्ध भाड़ा',
            'col_tax_amt'    => 'कर राशि',
            'col_misc_ded'   => 'अन्य कटौती',
            'col_gross'      => 'कुल देय',
            'no_trips'       => 'चयनित माह के लिए कोई गैर-बिल योग्य ट्रिप नहीं है।',
            'agent_subtotal' => 'एजेंट उप-योग',
            'company_subtotal' => 'कंपनी उप-योग',
            'grand_total'    => 'कुल योग (Grand Total)',
            'credit'         => '(जमा/Credit) ',
            'btn_print'      => 'प्रिंट विवरण (Print)',
            'btn_close'      => 'बंद करें (Close)',
            'lang_label'     => 'आउटपुट भाषा (Language):',
        ]
    ];
    $ub_t = $ub_dict[$lang];
    ?>
    <!doctype html>
    <html lang="<?= htmlspecialchars($lang) ?>">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($ub_t['doc_title']) ?> - <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?></title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
            body{font-family:'Segoe UI',Arial,'Noto Sans Devanagari','Mangal',sans-serif;font-size:11px;margin:18px;color:#222}
            .print-bar {
                position: sticky;
                top: 0;
                z-index: 20;
                background: #0f766e;
                color: #fff;
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 16px;
                padding: 8px 16px;
                margin: -18px -18px 16px -18px;
            }
            .print-bar button {
                border: none;
                border-radius: 4px;
                background: #fff;
                color: #0f766e;
                padding: 6px 14px;
                font-weight: 700;
                cursor: pointer;
                font-size: 12px;
            }
            .print-bar button:hover { background: #f0fdf4; }
            .lang-switch {
                display: flex;
                align-items: center;
                gap: 6px;
                background: rgba(255,255,255,.18);
                padding: 3px 8px;
                border-radius: 4px;
            }
            .lang-switch span { font-size: 11px; font-weight: 600; }
            .lang-btn {
                color: #fff;
                text-decoration: none;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
            }
            .lang-btn.active { background: #fff; color: #0f766e; font-weight: 700; }
            .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #0f766e;padding-bottom:10px;margin-bottom:14px}
            .title{font-size:22px;font-weight:bold;color:#0f766e;line-height:1.2}
            .sub{font-size:12px;color:#444;margin-top:4px}
            .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
            .box{border:1px solid #ddd;border-radius:4px;padding:8px}
            .lbl{font-size:9px;color:#666}
            .val{font-size:11px;font-weight:bold;margin-top:2px}
            table{width:100%;border-collapse:collapse;margin-top:8px}
            th,td{border:1px solid #ccc;padding:4px 6px;text-align:center !important}
            th{background:#f5f5f5}
            .r{text-align:center !important}
            .c{text-align:center}
            .al-left{text-align:left !important}
            .tot{font-weight:bold;background:#f8fafc}
            .grp td{background:#e0f2f1;font-weight:bold;color:#0f766e;padding:6px}
            .co-grp td{background:#e8f5e9;font-weight:bold;color:#2e7d32;padding:4px 6px;font-size:10px}
            .co-sub{font-style:italic;font-size:10px;background:#f1f8e9}
            .sub-tot{font-weight:bold;background:#f8fafc}
            @media print{
                .print-bar { display: none !important; }
                body { margin: 0; }
                @page { size: A4 landscape; margin: 8mm; }
            }
        </style>
    </head>
    <body onload="window.print()">
        <div class="print-bar">
            <button type="button" onclick="window.print()"><?= htmlspecialchars($ub_t['btn_print']) ?></button>
            <div class="lang-switch">
                <span><?= htmlspecialchars($ub_t['lang_label']) ?></span>
                <a href="?action=print_unbilled&lease_agent_id=<?= (int)$agent_filter ?>&month=<?= urlencode($month) ?>&lang=en" class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>">English</a>
                <a href="?action=print_unbilled&lease_agent_id=<?= (int)$agent_filter ?>&month=<?= urlencode($month) ?>&lang=hi" class="lang-btn <?= $lang === 'hi' ? 'active' : '' ?>">हिंदी (Hindi)</a>
            </div>
            <button type="button" onclick="window.close()"><?= htmlspecialchars($ub_t['btn_close']) ?></button>
        </div>

        <div class="head">
            <div>
                <div class="title"><?= htmlspecialchars($ub_t['title']) ?><?= htmlspecialchars(date('F Y', strtotime($month_start))) ?></div>
                <div class="sub"><?= htmlspecialchars($print_agent_label) ?><?= $print_agent_tax_label !== '' ? ' &mdash; ' . htmlspecialchars($print_agent_tax_label) : '' ?></div>
            </div>
            <div class="sub" style="text-align:right">
                <div><?= htmlspecialchars($ub_t['month']) ?> <?= htmlspecialchars(date('M Y', strtotime($month_start))) ?></div>
                <div><?= htmlspecialchars($ub_t['printed']) ?> <?= htmlspecialchars(date('d/m/Y')) ?></div>
            </div>
        </div>
        <div class="grid" style="grid-template-columns:repeat(8,1fr)">
            <div class="box"><div class="lbl"><?= htmlspecialchars($ub_t['unbilled_trips']) ?></div><div class="val"><?= number_format($unbilled_totals['trip_count'], 0) ?></div></div>
            <div class="box"><div class="lbl"><?= htmlspecialchars($ub_t['weight']) ?></div><div class="val"><?= number_format($unbilled_totals['total_weight'], 3) ?></div></div>
            <div class="box"><div class="lbl"><?= htmlspecialchars($ub_t['net_payable']) ?></div><div class="val">₹<?= number_format($unbilled_totals['net_freight'], 2) ?></div></div>
            <div class="box"><div class="lbl"><?= htmlspecialchars($ub_t['tax_amount']) ?></div><div class="val">₹<?= number_format($unbilled_totals['tax_amount'], 2) ?></div></div>
            <div class="box"><div class="lbl"><?= htmlspecialchars($ub_t['misc_deduction']) ?></div><div class="val" style="color:<?= $unbilled_totals['misc_deduction'] > 0 ? '#dc3545' : 'inherit' ?>"><?= $unbilled_totals['misc_deduction'] > 0 ? '-₹' . number_format($unbilled_totals['misc_deduction'], 2) : '—' ?></div></div>
            <div class="box" style="border-color:#0f766e"><div class="lbl"><?= htmlspecialchars($ub_t['gross_payable']) ?></div><div class="val" style="color:#0f766e">₹<?= number_format($unbilled_totals['gross_payable'], 2) ?></div></div>
            <div class="box" style="border-color:#198754"><div class="lbl"><?= htmlspecialchars($ub_t['paid']) ?></div><div class="val" style="color:#198754">₹<?= number_format($print_paid, 2) ?></div></div>
            <div class="box" style="border-color:<?= $print_balance >= 0 ? '#dc3545' : '#198754' ?>">
                <div class="lbl"><?= htmlspecialchars($ub_t['balance_due']) ?></div>
                <div class="val" style="color:<?= $print_balance >= 0 ? '#dc3545' : '#198754' ?>">
                    <?= $print_balance >= 0 ? '' : htmlspecialchars($ub_t['credit']) ?>₹<?= number_format(abs($print_balance), 2) ?>
                </div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="c"><?= htmlspecialchars($ub_t['col_sno']) ?></th>
                    <th><?= htmlspecialchars($ub_t['col_date']) ?></th>
                    <th><?= htmlspecialchars($ub_t['col_trip_no']) ?></th>
                    <th><?= htmlspecialchars($ub_t['col_tax']) ?></th>
                    <th><?= htmlspecialchars($ub_t['col_vehicle']) ?></th>
                    <th><?= htmlspecialchars($ub_t['col_customer']) ?></th>
                    <th class="r"><?= htmlspecialchars($ub_t['col_weight']) ?></th>
                    <th class="r"><?= htmlspecialchars($ub_t['col_rate']) ?></th>
                    <th class="r"><?= htmlspecialchars($ub_t['col_net']) ?></th>
                    <th class="r"><?= htmlspecialchars($ub_t['col_tax_amt']) ?></th>
                    <th class="r"><?= htmlspecialchars($ub_t['col_misc_ded']) ?></th>
                    <th class="r"><?= htmlspecialchars($ub_t['col_gross']) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$unbilled_rows): ?>
                <tr><td colspan="12" class="c"><?= htmlspecialchars($ub_t['no_trips']) ?></td></tr>
                <?php elseif ($group_by_agent): ?>
                    <?php foreach ($agent_groups as $group): ?>
                    <tr class="grp"><td colspan="12" class="al-left"><?= htmlspecialchars($group['label']) ?> &mdash; <?= htmlspecialchars($group['tax_label']) ?></td></tr>
                    <?php foreach ($group['companies'] as $coGrp): ?>
                    <tr class="co-grp"><td colspan="12" class="al-left" style="padding-left:16px">⬥ <?= htmlspecialchars($coGrp['label']) ?></td></tr>
                    <?php $sno = 1; foreach ($coGrp['rows'] as $trip): ?>
                    <?php
                        $u_weight = (float)($trip['total_weight'] ?? 0);
                        $u_net = fleetLeaseAgentTripBasePayable($trip);
                        $u_misc_ded = (float)($trip['lease_agent_misc_deduction'] ?? 0);
                        $u_misc_remarks = trim((string)($trip['lease_agent_misc_deduction_remarks'] ?? ''));
                        $u_rate = $u_weight > 0 ? ($u_net / $u_weight) : 0;
                        $u_tax = leaseTaxLabel((string)($trip['tax_type'] ?? 'None'), (float)($trip['tax_rate'] ?? 0));
                        $u_tax_amt = $u_net * ((float)($trip['tax_rate'] ?? 0) / 100);
                        $u_gross = max(0, $u_net + $u_tax_amt - $u_misc_ded);
                    ?>
                    <tr>
                        <td class="c"><?= $sno++ ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($trip['trip_date']))) ?></td>
                        <td><?= htmlspecialchars($trip['trip_no']) ?></td>
                        <td><?= htmlspecialchars($u_tax) ?></td>
                        <td><?= htmlspecialchars($trip['reg_no'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($trip['customer_name'] ?? '-') ?></td>
                        <td class="r"><?= number_format($u_weight, 3) ?></td>
                        <td class="r">₹<?= number_format($u_rate, 2) ?></td>
                        <td class="r">₹<?= number_format($u_net, 2) ?></td>
                        <td class="r">₹<?= number_format($u_tax_amt, 2) ?></td>
                        <td class="r">
                            <?= $u_misc_ded > 0 ? ('-₹' . number_format($u_misc_ded, 2)) : '—' ?>
                            <?php if ($u_misc_ded > 0 && $u_misc_remarks !== ''): ?>
                                <div style="font-size:8.5px;color:#64748b;font-weight:normal;line-height:1.2;margin-top:2px"><?= htmlspecialchars($u_misc_remarks) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="r"><strong>₹<?= number_format($u_gross, 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($group['companies']) > 1): ?>
                    <tr class="co-sub">
                        <td colspan="6" class="r"><?= htmlspecialchars($coGrp['label']) ?> (<?= number_format($coGrp['totals']['trip_count'], 0) ?> trips)</td>
                        <td class="r"><?= number_format($coGrp['totals']['total_weight'], 3) ?></td>
                        <td></td>
                        <td class="r">₹<?= number_format($coGrp['totals']['net_freight'], 2) ?></td>
                        <td class="r">₹<?= number_format($coGrp['totals']['tax_amount'], 2) ?></td>
                        <td class="r"><?= ($coGrp['totals']['misc_deduction'] ?? 0) > 0 ? ('-₹' . number_format($coGrp['totals']['misc_deduction'], 2)) : '—' ?></td>
                        <td class="r">₹<?= number_format($coGrp['totals']['gross_payable'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <tr class="sub-tot">
                        <td colspan="6" class="r"><?= htmlspecialchars($ub_t['agent_subtotal']) ?> (<?= number_format($group['totals']['trip_count'], 0) ?> trips)</td>
                        <td class="r"><?= number_format($group['totals']['total_weight'], 3) ?></td>
                        <td></td>
                        <td class="r">₹<?= number_format($group['totals']['net_freight'], 2) ?></td>
                        <td class="r">₹<?= number_format($group['totals']['tax_amount'], 2) ?></td>
                        <td class="r"><?= ($group['totals']['misc_deduction'] ?? 0) > 0 ? ('-₹' . number_format($group['totals']['misc_deduction'], 2)) : '—' ?></td>
                        <td class="r"><strong>₹<?= number_format($group['totals']['gross_payable'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($agent_groups as $group): ?>
                    <?php foreach ($group['companies'] as $coGrp): ?>
                    <tr class="co-grp"><td colspan="12" class="al-left" style="padding-left:8px">⬥ <?= htmlspecialchars($coGrp['label']) ?></td></tr>
                    <?php $sno = 1; foreach ($coGrp['rows'] as $trip): ?>
                    <?php
                        $u_weight = (float)($trip['total_weight'] ?? 0);
                        $u_net = fleetLeaseAgentTripBasePayable($trip);
                        $u_misc_ded = (float)($trip['lease_agent_misc_deduction'] ?? 0);
                        $u_misc_remarks = trim((string)($trip['lease_agent_misc_deduction_remarks'] ?? ''));
                        $u_rate = $u_weight > 0 ? ($u_net / $u_weight) : 0;
                        $u_tax = leaseTaxLabel((string)($trip['tax_type'] ?? 'None'), (float)($trip['tax_rate'] ?? 0));
                        $u_tax_amt = $u_net * ((float)($trip['tax_rate'] ?? 0) / 100);
                        $u_gross = max(0, $u_net + $u_tax_amt - $u_misc_ded);
                    ?>
                    <tr>
                        <td class="c"><?= $sno++ ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($trip['trip_date']))) ?></td>
                        <td><?= htmlspecialchars($trip['trip_no']) ?></td>
                        <td><?= htmlspecialchars($u_tax) ?></td>
                        <td><?= htmlspecialchars($trip['reg_no'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($trip['customer_name'] ?? '-') ?></td>
                        <td class="r"><?= number_format($u_weight, 3) ?></td>
                        <td class="r">₹<?= number_format($u_rate, 2) ?></td>
                        <td class="r">₹<?= number_format($u_net, 2) ?></td>
                        <td class="r">₹<?= number_format($u_tax_amt, 2) ?></td>
                        <td class="r">
                            <?= $u_misc_ded > 0 ? ('-₹' . number_format($u_misc_ded, 2)) : '—' ?>
                            <?php if ($u_misc_ded > 0 && $u_misc_remarks !== ''): ?>
                                <div style="font-size:8.5px;color:#64748b;font-weight:normal;line-height:1.2;margin-top:2px"><?= htmlspecialchars($u_misc_remarks) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="r"><strong>₹<?= number_format($u_gross, 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($group['companies']) > 1): ?>
                    <tr class="co-sub">
                        <td colspan="6" class="r"><?= htmlspecialchars($coGrp['label']) ?> (<?= number_format($coGrp['totals']['trip_count'], 0) ?> trips)</td>
                        <td class="r"><?= number_format($coGrp['totals']['total_weight'], 3) ?></td>
                        <td></td>
                        <td class="r">₹<?= number_format($coGrp['totals']['net_freight'], 2) ?></td>
                        <td class="r">₹<?= number_format($coGrp['totals']['tax_amount'], 2) ?></td>
                        <td class="r"><?= ($coGrp['totals']['misc_deduction'] ?? 0) > 0 ? ('-₹' . number_format($coGrp['totals']['misc_deduction'], 2)) : '—' ?></td>
                        <td class="r">₹<?= number_format($coGrp['totals']['gross_payable'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($unbilled_rows): ?>
                <tr class="tot">
                    <td colspan="6" class="r"><?= htmlspecialchars($ub_t['grand_total']) ?></td>
                    <td class="r"><?= number_format($unbilled_totals['total_weight'], 3) ?></td>
                    <td></td>
                    <td class="r">₹<?= number_format($unbilled_totals['net_freight'], 2) ?></td>
                    <td class="r">₹<?= number_format($unbilled_totals['tax_amount'], 2) ?></td>
                    <td class="r"><?= ($unbilled_totals['misc_deduction'] ?? 0) > 0 ? ('-₹' . number_format($unbilled_totals['misc_deduction'], 2)) : '—' ?></td>
                    <td class="r"><strong>₹<?= number_format($unbilled_totals['gross_payable'], 2) ?></strong></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

// -----------------------------------------------------------------------
// PRINT: Payment Ledger
// -----------------------------------------------------------------------
if ($report_action === 'print_ledger') {
    $plWhere = "WHERE 1=1" . ($agent_filter > 0 ? " AND p.lease_agent_id=$agent_filter" : "");
    $pl_rows = $db->query("
        SELECT p.id AS payment_id, p.payment_no, p.agent_invoice_no, p.payment_date, p.date_from, p.date_to, p.lease_agent_id,
               COALESCE(la.agent_name,'') AS agent_name, COALESCE(la.agent_code,'') AS agent_code,
               p.total_payable, p.amount AS paid_amount, p.balance_amount,
               p.status, p.reference_no, p.utr_no, p.bank_name, p.notes,
               (SELECT COUNT(*) FROM fleet_lease_agent_payment_trips pt WHERE pt.payment_id=p.id) AS trip_count
        FROM fleet_lease_agent_payments p
        LEFT JOIN fleet_lease_agents la ON la.id=p.lease_agent_id
        $plWhere
        ORDER BY p.lease_agent_id ASC, p.payment_date ASC, p.id ASC
    ")->fetch_all(MYSQLI_ASSOC);
    $pl_by_agent = [];
    foreach ($pl_rows as $pr) {
        $aid = (int)$pr['lease_agent_id'];
        if (!isset($pl_by_agent[$aid])) {
            $pl_by_agent[$aid] = [
                'label'  => $pr['agent_name'] . (!empty($pr['agent_code']) ? ' (' . $pr['agent_code'] . ')' : ''),
                'rows'   => [],
                'total_payable' => 0.0, 'total_paid' => 0.0, 'total_balance' => 0.0,
            ];
        }
        $pl_by_agent[$aid]['rows'][] = $pr;
        $pl_by_agent[$aid]['total_payable'] += (float)$pr['total_payable'];
        $pl_by_agent[$aid]['total_paid']    += (float)$pr['paid_amount'];
        $pl_by_agent[$aid]['total_balance'] += (float)$pr['balance_amount'];
    }
    $pl_grand_payable = array_sum(array_column($pl_by_agent, 'total_payable'));
    $pl_grand_paid    = array_sum(array_column($pl_by_agent, 'total_paid'));
    $pl_grand_balance = array_sum(array_column($pl_by_agent, 'total_balance'));
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <title>Payment Ledger &mdash; <?= htmlspecialchars($month) ?></title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; font-family:Arial,sans-serif; font-size:11px; }
        body { padding:12px; }
        h2 { font-size:14px; margin-bottom:4px; }
        p.sub { color:#555; margin-bottom:10px; }
        table { width:100%; border-collapse:collapse; margin-bottom:16px; }
        th, td { border:1px solid #ccc; padding:4px 6px; vertical-align:middle; text-align:center !important; }
        thead th { background:#1e3a5f; color:#fff; text-align:center; }
        .r { text-align:center !important; }
        .al-left { text-align:left !important; }
        .agent-hdr { background:#dbeafe; font-weight:bold; }
        .subtot { background:#f0fdf4; font-weight:bold; }
        .grandtot { background:#1e3a5f; color:#fff; font-weight:bold; }
        .badge-paid { color:#16a34a; font-weight:bold; } .badge-partial { color:#ea580c; font-weight:bold; } .badge-unpaid { color:#dc2626; font-weight:bold; }
        @media print { @page { margin:10mm; } }
    </style></head><body>
    <h2>&#128197; Lease Agent &mdash; Payment Ledger</h2>
    <p class="sub">Printed: <?= date('d/m/Y H:i') ?><?= $agent_filter > 0 ? ' &nbsp;|&nbsp; Agent filtered' : ' &nbsp;|&nbsp; All Agents' ?></p>
    <table>
        <thead><tr>
            <th>Payment No</th><th>Agent Inv No</th><th>Payment Date</th><th>Period</th><th class="al-left">Lease Agent</th>
            <th>Trips</th><th class="r">Total Payable</th>
            <th class="r">Paid Amount</th><th class="r">Balance</th>
            <th>Status</th><th>Reference</th><th>UTR No</th><th>Bank</th>
        </tr></thead>
        <tbody>
        <?php if (empty($pl_by_agent)): ?>
        <tr><td colspan="13" style="text-align:center;color:#888">No payment records.</td></tr>
        <?php endif; ?>
        <?php foreach ($pl_by_agent as $aid => $ag): ?>
        <tr class="agent-hdr"><td colspan="13" class="al-left"><?= htmlspecialchars($ag['label']) ?></td></tr>
        <?php foreach ($ag['rows'] as $pr):
            $prS = $pr['status'] ?: 'Unpaid';
            $prC = $prS === 'Paid' ? 'badge-paid' : ($prS === 'Partial' ? 'badge-partial' : 'badge-unpaid');
            $prPeriod = (!empty($pr['date_from']) && !empty($pr['date_to']))
                ? date('d/m/y', strtotime($pr['date_from'])) . ' - ' . date('d/m/y', strtotime($pr['date_to']))
                : '—';
        ?>
        <tr>
            <td><?= htmlspecialchars($pr['payment_no'] ?: ('LAP-' . (int)$pr['payment_id'])) ?></td>
            <td><?= htmlspecialchars($pr['agent_invoice_no'] ?: '—') ?></td>
            <td><?= date('d/m/Y', strtotime($pr['payment_date'])) ?></td>
            <td><?= htmlspecialchars($prPeriod) ?></td>
            <td class="al-left"><?= htmlspecialchars($pr['agent_name'] . (!empty($pr['agent_code']) ? ' (' . $pr['agent_code'] . ')' : '')) ?></td>
            <td class="r"><?= (int)$pr['trip_count'] ?></td>
            <td class="r">&#8377;<?= number_format((float)$pr['total_payable'], 2) ?></td>
            <td class="r">&#8377;<?= number_format((float)$pr['paid_amount'], 2) ?></td>
            <td class="r">&#8377;<?= number_format((float)$pr['balance_amount'], 2) ?></td>
            <td class="<?= $prC ?>"><?= htmlspecialchars($prS) ?></td>
            <td><?= htmlspecialchars($pr['reference_no'] ?: '—') ?></td>
            <td><?= htmlspecialchars($pr['utr_no'] ?: '—') ?></td>
            <td><?= htmlspecialchars($pr['bank_name'] ?: '—') ?></td>
        </tr>
        <?php if (!empty(trim((string)($pr['notes'] ?? '')))): ?>
        <tr><td colspan="13" style="font-style:italic;color:#666;padding:2px 6px 4px 6px;border-top:none">Note: <?= htmlspecialchars(trim($pr['notes'])) ?></td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <tr class="subtot">
            <td colspan="6" class="r">Agent Total (<?= count($ag['rows']) ?> payments)</td>
            <td class="r">&#8377;<?= number_format($ag['total_payable'], 2) ?></td>
            <td class="r">&#8377;<?= number_format($ag['total_paid'], 2) ?></td>
            <td class="r">&#8377;<?= number_format($ag['total_balance'], 2) ?></td>
            <td colspan="4"></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!empty($pl_by_agent)): ?>
        <tr class="grandtot">
            <td colspan="6" class="r">Grand Total</td>
            <td class="r">&#8377;<?= number_format($pl_grand_payable, 2) ?></td>
            <td class="r">&#8377;<?= number_format($pl_grand_paid, 2) ?></td>
            <td class="r">&#8377;<?= number_format($pl_grand_balance, 2) ?></td>
            <td colspan="4"></td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <script>window.onload=function(){window.print();}</script>
    </body></html>
    <?php
    exit;
}

$summary_rows = $db->query("SELECT
        COALESCE(t.lease_agent_id,0) AS lease_agent_id,
        COALESCE(la.agent_name, NULLIF(TRIM(t.lease_agent_name),''), CONCAT('Agent #', t.lease_agent_id)) AS lease_agent_name,
        COALESCE(la.agent_code, '') AS lease_agent_code,
        COALESCE(la.tax_type, 'None') AS tax_type,
        COALESCE(la.tax_rate, 0) AS tax_rate,
        la.tax_applicable_from,
        COUNT(*) AS trip_count,
        COALESCE(SUM(t.total_weight),0) AS total_weight,
        COALESCE(SUM(t.freight_amount),0) AS gross_freight,
        COALESCE(SUM(t.lease_agent_amount),0) AS lease_deduction,
        COALESCE(SUM(t.lease_agent_misc_deduction),0) AS misc_deduction,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END),0) AS net_freight,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END * ((CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END) / 100)),0) AS tax_amount,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' AND (pt.payment_id IS NULL OR COALESCE(lap.status,'') != 'Paid') THEN CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END ELSE 0 END), 0) AS billed_unpaid_net_freight,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' AND (pt.payment_id IS NULL OR COALESCE(lap.status,'') != 'Paid') THEN COALESCE(t.lease_agent_misc_deduction, 0) ELSE 0 END), 0) AS billed_unpaid_misc_deduction,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' AND (pt.payment_id IS NULL OR COALESCE(lap.status,'') != 'Paid') THEN CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END * (COALESCE(la.tax_rate, 0) / 100) ELSE 0 END), 0) AS billed_unpaid_tax_amount,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END ELSE 0 END), 0) AS billed_net_freight,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(t.lease_agent_misc_deduction, 0) ELSE 0 END), 0) AS billed_misc_deduction,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END * (COALESCE(la.tax_rate, 0) / 100) ELSE 0 END), 0) AS billed_tax_amount,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END ELSE 0 END), 0) AS unbilled_net_freight,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN COALESCE(t.lease_agent_misc_deduction, 0) ELSE 0 END), 0) AS unbilled_misc_deduction,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END * (COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) / 100) ELSE 0 END), 0) AS unbilled_tax_amount,
        SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' AND (pt.payment_id IS NULL OR COALESCE(lap.status,'') != 'Paid') THEN 1 ELSE 0 END) AS billed_unpaid_trip_count,
        SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN 1 ELSE 0 END) AS billed_trip_count,
        SUM(CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') != 'Billed' THEN 1 ELSE 0 END) AS unbilled_trip_count
    FROM fleet_trips t
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
    LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
    LEFT JOIN fleet_lease_agent_payment_trips pt ON pt.trip_id=t.id
    LEFT JOIN fleet_lease_agent_payments lap ON lap.id=pt.payment_id
    $where
    GROUP BY COALESCE(t.lease_agent_id,0), COALESCE(la.agent_name, NULLIF(TRIM(t.lease_agent_name),''), CONCAT('Agent #', t.lease_agent_id)), COALESCE(la.agent_code, ''), COALESCE(la.tax_type, 'None'), COALESCE(la.tax_rate, 0), la.tax_applicable_from
    ORDER BY lease_deduction DESC, gross_freight DESC, lease_agent_name ASC")->fetch_all(MYSQLI_ASSOC);

$settlement_till_date_rows = $db->query("SELECT
        COALESCE(t.lease_agent_id,0) AS lease_agent_id,
        COALESCE(SUM(CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee' THEN COALESCE(t.lease_agent_amount, 0) ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount,0)) END - COALESCE(t.lease_agent_misc_deduction,0)),0) AS settlement_till_date
    FROM fleet_trips t
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
    WHERE t.status='Completed' AND $trip_period_expr <= '$month_end'
      AND COALESCE(t.lease_agent_id,0) > 0" . ($agent_filter > 0 ? " AND t.lease_agent_id=$agent_filter" : "") . "
    GROUP BY lease_agent_id")->fetch_all(MYSQLI_ASSOC);

$settlement_till_date_map = [];
foreach ($settlement_till_date_rows as $row) {
    $settlement_till_date_map[(int)$row['lease_agent_id']] = (float)$row['settlement_till_date'];
}

$totals = [
    'trip_count' => 0, 'total_weight' => 0.0, 'gross_freight' => 0.0, 'lease_deduction' => 0.0,
    'misc_deduction' => 0.0, 'net_freight' => 0.0, 'tax_amount' => 0.0,
    'billed_unpaid_gross' => 0.0, 'billed_gross' => 0.0, 'unbilled_gross' => 0.0
];
foreach ($summary_rows as $row) {
    $r_net = (float)$row['net_freight'];
    $r_misc_ded = (float)($row['misc_deduction'] ?? 0);
    $r_tax = (float)($row['tax_amount'] ?? 0);

    $r_bu_net = (float)($row['billed_unpaid_net_freight'] ?? 0);
    $r_bu_misc_ded = (float)($row['billed_unpaid_misc_deduction'] ?? 0);
    $r_bu_tax = (float)($row['billed_unpaid_tax_amount'] ?? 0);
    $r_bu_gross = max(0, $r_bu_net + $r_bu_tax - $r_bu_misc_ded);

    $r_b_net = (float)($row['billed_net_freight'] ?? 0);
    $r_b_misc_ded = (float)($row['billed_misc_deduction'] ?? 0);
    $r_b_tax = (float)($row['billed_tax_amount'] ?? 0);
    $r_b_gross = max(0, $r_b_net + $r_b_tax - $r_b_misc_ded);

    $r_ub_net = (float)($row['unbilled_net_freight'] ?? 0);
    $r_ub_misc_ded = (float)($row['unbilled_misc_deduction'] ?? 0);
    $r_ub_tax = (float)($row['unbilled_tax_amount'] ?? 0);
    $r_ub_gross = max(0, $r_ub_net + $r_ub_tax - $r_ub_misc_ded);

    $totals['trip_count']          += (int)$row['trip_count'];
    $totals['total_weight']        += (float)$row['total_weight'];
    $totals['gross_freight']       += (float)$row['gross_freight'];
    $totals['lease_deduction']     += (float)$row['lease_deduction'];
    $totals['misc_deduction']      += $r_misc_ded;
    $totals['net_freight']         += $r_net;
    $totals['tax_amount']          += $r_tax;
    $totals['billed_unpaid_gross'] += $r_bu_gross;
    $totals['billed_gross']        += $r_b_gross;
    $totals['unbilled_gross']      += $r_ub_gross;
}

// --- Payment ledger: paid amount and balance per agent ---
$agentPaymentsWhere = "WHERE 1=1" . ($agent_filter > 0 ? " AND p.lease_agent_id=$agent_filter" : "");
$payment_ledger_rows = $db->query("
    SELECT
        p.id AS payment_id,
        p.payment_no,
        p.agent_invoice_no,
        p.payment_date,
        p.date_from,
        p.date_to,
        p.lease_agent_id,
        COALESCE(la.agent_name,'') AS agent_name,
        COALESCE(la.agent_code,'') AS agent_code,
        p.total_payable,
        p.amount      AS paid_amount,
        p.balance_amount,
        p.status,
        p.reference_no,
        p.utr_no,
        p.bank_name,
        p.notes,
        (SELECT COUNT(*) FROM fleet_lease_agent_payment_trips pt WHERE pt.payment_id=p.id) AS trip_count
    FROM fleet_lease_agent_payments p
    LEFT JOIN fleet_lease_agents la ON la.id=p.lease_agent_id
    $agentPaymentsWhere
    ORDER BY p.lease_agent_id ASC, p.payment_date DESC, p.id DESC
")->fetch_all(MYSQLI_ASSOC);

// Group payment ledger by agent
$payment_ledger_by_agent = [];
foreach ($payment_ledger_rows as $pr) {
    $aid = (int)$pr['lease_agent_id'];
    if (!isset($payment_ledger_by_agent[$aid])) {
        $payment_ledger_by_agent[$aid] = [
            'label'        => $pr['agent_name'] . (!empty($pr['agent_code']) ? ' (' . $pr['agent_code'] . ')' : ''),
            'payments'     => [],
            'total_payable'=> 0.0,
            'total_paid'   => 0.0,
            'total_balance'=> 0.0,
        ];
    }
    $payment_ledger_by_agent[$aid]['payments'][]      = $pr;
    $payment_ledger_by_agent[$aid]['total_payable']  += (float)$pr['total_payable'];
    $payment_ledger_by_agent[$aid]['total_paid']     += (float)$pr['paid_amount'];
    $payment_ledger_by_agent[$aid]['total_balance']  += (float)$pr['balance_amount'];
}
$ledger_grand_payable = array_sum(array_column($payment_ledger_by_agent, 'total_payable'));
$ledger_grand_paid    = array_sum(array_column($payment_ledger_by_agent, 'total_paid'));
$ledger_grand_balance = array_sum(array_column($payment_ledger_by_agent, 'total_balance'));

// --- Payment ledger scoped to the selected month only (for the Agent Summary table / stat cards,
//     which are already filtered to $month — keeps Paid/Balance shown there consistent with
//     the Trips/Net Freight figures next to them). The Payment Ledger tab itself stays all-time. ---
$agentPaymentsMonthWhere = "WHERE p.payment_date BETWEEN '$month_start' AND '$month_end'"
    . ($agent_filter > 0 ? " AND p.lease_agent_id=$agent_filter" : "");
$payment_ledger_month_rows = $db->query("
    SELECT p.lease_agent_id, p.amount AS paid_amount
    FROM fleet_lease_agent_payments p
    $agentPaymentsMonthWhere
")->fetch_all(MYSQLI_ASSOC);

$payment_month_by_agent = [];
foreach ($payment_ledger_month_rows as $pr) {
    $aid = (int)$pr['lease_agent_id'];
    if (!isset($payment_month_by_agent[$aid])) {
        $payment_month_by_agent[$aid] = ['total_paid' => 0.0];
    }
    $payment_month_by_agent[$aid]['total_paid'] += (float)$pr['paid_amount'];
}
$month_grand_paid       = array_sum(array_column($payment_month_by_agent, 'total_paid'));
$month_grand_payable    = (float)(max(0, $totals['net_freight'] + $totals['tax_amount'] - $totals['misc_deduction']));
$month_grand_not_billed = (float)($totals['unbilled_gross'] ?? 0);

$grand_paid_month        = $month_grand_paid;
$grand_total_balance     = $month_grand_payable - $month_grand_paid;
$grand_billed_balance    = (float)$totals['billed_unpaid_gross'];
$grand_balance_till_date = $grand_total_balance;

// --- Vehicles in Transit for this agent filter ---
$transitAgentWhere = "COALESCE(t.lease_agent_id,0) > 0";
if ($agent_filter > 0) $transitAgentWhere .= " AND t.lease_agent_id=$agent_filter";
$transit_rows = $db->query("
    SELECT t.id, t.trip_no, t.trip_date, t.start_date,
           t.lease_agent_id,
           COALESCE(la.agent_name, CONCAT('Agent #', t.lease_agent_id)) AS lease_agent_name,
           COALESCE(la.agent_code,'') AS lease_agent_code,
           t.customer_name, COALESCE(co.company_name,'No Company') AS company_name,
           t.lease_agent_bill_to_company_id, COALESCE(btco.company_name, co.company_name, 'No Company') AS bill_to_company_name,
           v.reg_no, t.total_weight, t.freight_amount, t.lease_agent_amount, t.net_freight_amount,
           t.lease_agent_billing_status,
           COALESCE(t.from_location,'') AS from_location,
           COALESCE(t.to_location,'') AS to_location
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON v.id=t.vehicle_id
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
    LEFT JOIN companies co ON co.id=t.company_id
    LEFT JOIN companies btco ON btco.id=t.lease_agent_bill_to_company_id
    WHERE t.status='In Transit' AND $transitAgentWhere
    ORDER BY lease_agent_name ASC, t.trip_date ASC, t.id ASC
")->fetch_all(MYSQLI_ASSOC);

// Group transit by agent ID (use la.agent_name, not the trip-level short name)
$transit_by_agent = [];
foreach ($transit_rows as $tr) {
    $ak = (int)($tr['lease_agent_id'] ?? 0);
    if (!isset($transit_by_agent[$ak])) {
        $transit_by_agent[$ak] = [
            'label'  => $tr['lease_agent_name'] . (!empty($tr['lease_agent_code']) ? ' (' . $tr['lease_agent_code'] . ')' : ''),
            'rows'   => [],
            'weight' => 0.0,
        ];
    }
    $transit_by_agent[$ak]['rows'][] = $tr;
    $transit_by_agent[$ak]['weight'] += (float)($tr['total_weight'] ?? 0);
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-person-badge me-2"></i>Lease Agent Account';</script>
<style>
.badge-billed { background-color: #0d6efd; color: #fff; }
.badge-not-billed { background-color: #fd7e14; color: #fff; }
.badge-paid { background-color: #198754; color: #fff; }
.badge-unpaid { background-color: #dc3545; color: #fff; }
.badge-fixed-billing {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 82px;
    font-size: .72rem;
    padding: .32em .45em;
    vertical-align: middle;
}
.badge-inv-no {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    font-size: .72rem;
    padding: .32em .45em;
    vertical-align: middle;
}
.badge-fixed-pay {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    font-size: .72rem;
    padding: .32em .45em;
    vertical-align: middle;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="fleet_lease_agent_dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <?php if (!isLeaseAgentUser() && canDo('fleet_lease_agents', 'view')): ?>
        <a href="fleet_lease_agents.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-badge me-1"></i>Agent Master</a>
        <?php endif; ?>
        <?php if (canDo('fleet_trips', 'view') || isLeaseAgentUser()): ?>
        <a href="fleet_lease_agent_payments.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-cash-stack me-1"></i>Payments</a>
        <?php endif; ?>
        <a href="fleet_lease_agent_report.php" class="btn btn-sm btn-primary active"><i class="bi bi-file-earmark-bar-graph me-1"></i>Report</a>
    </div>
    <h5 class="mb-0 fw-bold">Lease Agent Account &amp; Billing Report</h5>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label">Month</label>
                <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Lease Agent</label>
                <select name="lease_agent_id" class="form-select" <?= $is_lease_agent_user ? 'disabled' : '' ?>>
                    <option value="0">All Agents</option>
                    <?php foreach ($agents as $agent): ?>
                    <option value="<?= (int)$agent['id'] ?>" <?= $agent_filter === (int)$agent['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($agent['agent_name'] . ' (' . $agent['agent_code'] . ')') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($is_lease_agent_user): ?>
                <input type="hidden" name="lease_agent_id" value="<?= (int)$agent_filter ?>">
                <?php endif; ?>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Billing Status <small class="text-muted">(Trips tab)</small></label>
                <select name="billing_status" class="form-select">
                    <option value="all"      <?= $billing_filter === 'all'      ? 'selected' : '' ?>>All</option>
                    <option value="billed"   <?= $billing_filter === 'billed'   ? 'selected' : '' ?>>Billed</option>
                    <option value="unbilled" <?= $billing_filter === 'unbilled' ? 'selected' : '' ?>>Unbilled</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Payment Status <small class="text-muted">(Trips tab)</small></label>
                <select name="payment_status" class="form-select">
                    <option value="all"     <?= $payment_filter === 'all'     ? 'selected' : '' ?>>All</option>
                    <option value="paid"    <?= $payment_filter === 'paid'    ? 'selected' : '' ?>>Paid</option>
                    <option value="unpaid"  <?= $payment_filter === 'unpaid'  ? 'selected' : '' ?>>Unpaid</option>
                    <option value="partial" <?= $payment_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
            <div class="col-12 col-md-2 text-muted small">
                Gross freight minus lease deduction. Billing &amp; Payment filters apply to the Trips tab.
            </div>
        </form>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100"><small class="text-muted">Net Freight</small><div class="fw-bold text-primary">&#8377;<?= number_format($totals['net_freight'], 2) ?></div></div></div>
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100"><small class="text-muted">Total Tax</small><div class="fw-bold text-warning">&#8377;<?= number_format($totals['tax_amount'], 2) ?></div></div></div>
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100"><small class="text-muted">Misc Deduction</small><div class="fw-bold text-danger"><?= $totals['misc_deduction'] > 0 ? '-&#8377;' . number_format($totals['misc_deduction'], 2) : '—' ?></div></div></div>
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100" style="border-color:#0f766e"><small class="text-muted">Total Payable</small><div class="fw-bold" style="color:#0f766e">&#8377;<?= number_format(max(0, $totals['net_freight'] + $totals['tax_amount'] - $totals['misc_deduction']), 2) ?></div></div></div>
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100" style="border-color:#198754"><small class="text-muted d-block">Paid</small><div class="fw-bold text-success">&#8377;<?= number_format($month_grand_paid, 2) ?></div></div></div>
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100" style="border-color:<?= $grand_billed_balance < 0 ? '#198754' : '#dc3545' ?>"><small class="text-muted d-block">Balance Due for Billed</small><small class="text-muted d-block" style="font-size:.68rem">(Billed Only)</small><div class="fw-bold <?= $grand_billed_balance < 0 ? 'text-success' : 'text-danger' ?>"><?= $grand_billed_balance < 0 ? '(Credit) ' : '' ?>&#8377;<?= number_format(abs($grand_billed_balance), 2) ?></div></div></div>
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100" style="border-color:<?= $grand_total_balance < 0 ? '#198754' : '#dc3545' ?>"><small class="text-muted d-block">Total Balance Due</small><small class="text-muted d-block" style="font-size:.68rem">(Incl. Unbilled)</small><div class="fw-bold <?= $grand_total_balance < 0 ? 'text-success' : 'text-danger' ?>"><?= $grand_total_balance < 0 ? '(Credit) ' : '' ?>&#8377;<?= number_format(abs($grand_total_balance), 2) ?></div></div></div>
    <div class="col-6 col-sm-4 col-md-3 col-xl"><div class="card p-2 text-center h-100"><small class="text-muted">Trips</small><div class="fw-bold"><?= number_format($totals['trip_count'], 0) ?></div></div></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="al-left">Lease Agent</th>
                    <th>Status</th>
                    <th>Tax</th>
                    <th class="text-end">Trips</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance Due for Billed</th>
                    <th class="text-end">Total Balance Due</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Net Freight</th>
                    <th class="text-end">Tax Amount</th>
                    <th class="text-end">Misc Deduction</th>
                    <th class="text-end">Gross Payable</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $lease_tax_badge_map = [
                        'GST' => ['label' => 'GST', 'class' => 'bg-info text-dark'],
                        'RCM' => ['label' => 'RCM', 'class' => 'bg-warning text-dark'],
                        'TDS' => ['label' => 'TDS', 'class' => 'bg-secondary'],
                    ];
                ?>
                <?php foreach ($summary_rows as $row): ?>
                <?php
                    $agentId = (int)$row['lease_agent_id'];
                    $status = 'Linked';
                    $statusClass = 'success';
                    $detailUrl = 'fleet_lease_agent_report.php?month=' . urlencode($month) . '&lease_agent_id=' . $agentId . '&billing_status=' . urlencode($billing_filter) . '&payment_status=' . urlencode($payment_filter);
                    $row_tax_type = $row['tax_type'] ?? 'None';
                    $row_tax_rate = (float)($row['tax_rate'] ?? 0);
                    $agent_net   = (float)($row['net_freight'] ?? 0);
                    $agent_misc_ded = (float)($row['misc_deduction'] ?? 0);
                    $agent_tax_amt = (float)($row['tax_amount'] ?? 0);
                    $agent_gross = max(0, $agent_net + $agent_tax_amt - $agent_misc_ded);
                    $agent_bu_net = (float)($row['billed_unpaid_net_freight'] ?? 0);
                    $agent_bu_misc_ded = (float)($row['billed_unpaid_misc_deduction'] ?? 0);
                    $agent_bu_tax_amt = (float)($row['billed_unpaid_tax_amount'] ?? 0);
                    $agent_bu_gross = max(0, $agent_bu_net + $agent_bu_tax_amt - $agent_bu_misc_ded);
                    $agent_paid  = (float)($payment_month_by_agent[$agentId]['total_paid'] ?? 0);
                    $agent_total_balance  = $agent_gross - $agent_paid;
                    $agent_billed_balance = $agent_bu_gross;
                ?>
                <tr>
                    <td class="fw-semibold al-left">
                        <a href="<?= htmlspecialchars($detailUrl) ?>" class="text-decoration-none"><?= htmlspecialchars($row['lease_agent_name']) ?></a>
                        <?php if (!empty($row['lease_agent_code'])): ?><br><small class="text-muted"><?= htmlspecialchars($row['lease_agent_code']) ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td>
                    <td>
                        <?php if ($row_tax_type !== 'None' && isset($lease_tax_badge_map[$row_tax_type])): ?>
                            <span class="badge <?= $lease_tax_badge_map[$row_tax_type]['class'] ?>"><?= $lease_tax_badge_map[$row_tax_type]['label'] ?></span>
                            <?php if ($row_tax_rate > 0): ?>
                            <span class="ms-1 fw-semibold" style="font-size:.82rem"><?= number_format($row_tax_rate, 2) ?>%</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= number_format((int)$row['trip_count'], 0) ?></td>
                    <td class="text-end text-success">&#8377;<?= number_format($agent_paid, 2) ?></td>
                    <td class="text-end <?= $agent_billed_balance < 0 ? 'text-success' : 'text-danger' ?>"><?= $agent_billed_balance < 0 ? '(Credit) ' : '' ?>&#8377;<?= number_format(abs($agent_billed_balance), 2) ?></td>
                    <td class="text-end <?= $agent_total_balance < 0 ? 'text-success' : 'text-danger' ?>"><?= $agent_total_balance < 0 ? '(Credit) ' : '' ?>&#8377;<?= number_format(abs($agent_total_balance), 2) ?></td>
                    <td class="text-end"><?= number_format((float)$row['total_weight'], 3) ?></td>
                    <td class="text-end text-primary">&#8377;<?= number_format($agent_net, 2) ?></td>
                    <td class="text-end">&#8377;<?= number_format($agent_tax_amt, 2) ?></td>
                    <td class="text-end <?= $agent_misc_ded > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $agent_misc_ded > 0 ? '-&#8377;' . number_format($agent_misc_ded, 2) : '—' ?></td>
                    <td class="text-end fw-semibold" style="color:#0f766e">&#8377;<?= number_format($agent_gross, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$summary_rows): ?>
                <tr><td colspan="12" class="text-muted p-3">No completed trips found for the selected month.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($summary_rows): ?>
            <tfoot class="table-light">
                <tr>
                    <th colspan="3" class="text-end">Total</th>
                    <th class="text-end"><?= number_format($totals['trip_count'], 0) ?></th>
                    <th class="text-end text-success">&#8377;<?= number_format($month_grand_paid, 2) ?></th>
                    <th class="text-end <?= $grand_billed_balance < 0 ? 'text-success' : 'text-danger' ?>"><?= $grand_billed_balance < 0 ? '(Credit) ' : '' ?>&#8377;<?= number_format(abs($grand_billed_balance), 2) ?></th>
                    <th class="text-end <?= $grand_total_balance < 0 ? 'text-success' : 'text-danger' ?>"><?= $grand_total_balance < 0 ? '(Credit) ' : '' ?>&#8377;<?= number_format(abs($grand_total_balance), 2) ?></th>
                    <th class="text-end"><?= number_format($totals['total_weight'], 3) ?></th>
                    <th class="text-end text-primary">&#8377;<?= number_format($totals['net_freight'], 2) ?></th>
                    <th class="text-end">&#8377;<?= number_format($totals['tax_amount'], 2) ?></th>
                    <th class="text-end <?= $totals['misc_deduction'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= $totals['misc_deduction'] > 0 ? '-&#8377;' . number_format($totals['misc_deduction'], 2) : '—' ?></th>
                    <th class="text-end fw-semibold" style="color:#0f766e">&#8377;<?= number_format(max(0, $totals['net_freight'] + $totals['tax_amount'] - $totals['misc_deduction']), 2) ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php
$trip_where = $where;
if ($billing_filter === 'billed') {
    $trip_where .= " AND t.lease_agent_billing_status = 'Billed'";
} elseif ($billing_filter === 'unbilled') {
    $trip_where .= " AND COALESCE(t.lease_agent_billing_status,'Pending') <> 'Billed'";
}

if ($payment_filter === 'paid') {
    $trip_where .= " AND pt.payment_id IS NOT NULL AND COALESCE(lap.status,'') = 'Paid'";
} elseif ($payment_filter === 'unpaid') {
    $trip_where .= " AND (pt.payment_id IS NULL OR COALESCE(lap.status,'') = 'Unpaid')";
} elseif ($payment_filter === 'partial') {
    $trip_where .= " AND pt.payment_id IS NOT NULL AND COALESCE(lap.status,'') = 'Partial'";
}

$trip_rows = $db->query("SELECT
        t.id, t.trip_no, t.trip_date, t.status AS trip_order_status,
        t.lease_agent_id,
        COALESCE(NULLIF(TRIM(t.lease_agent_name),''), la.agent_name, CONCAT('Agent #', t.lease_agent_id)) AS lease_agent_name,
        COALESCE(la.agent_code, '') AS lease_agent_code,
        COALESCE(la.tax_type, 'None') AS tax_type,
        CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END AS tax_rate,
        t.vehicle_id, t.customer_name, t.company_id, COALESCE(co.company_name, 'No Company') AS company_name,
        t.lease_agent_bill_to_company_id, COALESCE(btco.company_name, co.company_name, 'No Company') AS bill_to_company_name,
        t.total_weight, t.freight_amount,
        t.lease_agent_billing_model, la.billing_model, t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
        t.lease_agent_misc_deduction, t.lease_agent_misc_deduction_remarks,
        t.lease_agent_billing_status, t.lease_agent_invoice_no, t.lease_agent_invoice_date,
        v.reg_no,
        pt.payment_id AS lap_payment_id,
        COALESCE(lap.status, '') AS lap_payment_status,
        COALESCE(lap.agent_invoice_no, '') AS lap_agent_invoice_no
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON v.id=t.vehicle_id
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
    LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
    LEFT JOIN companies co ON co.id=t.company_id
    LEFT JOIN companies btco ON btco.id=t.lease_agent_bill_to_company_id
    LEFT JOIN fleet_lease_agent_payment_trips pt ON pt.trip_id=t.id
    LEFT JOIN fleet_lease_agent_payments lap ON lap.id=pt.payment_id
    $trip_where
    ORDER BY lease_agent_name ASC, tax_rate ASC, COALESCE(btco.company_name, co.company_name, 'No Company') ASC, CAST(SUBSTRING_INDEX(t.trip_no, '/', -1) AS UNSIGNED), t.id")->fetch_all(MYSQLI_ASSOC);

// Group trip_rows by agent and GST tax rate, then sub-group by Bill To Company
$trip_agent_groups = [];
foreach ($trip_rows as $tr) {
    $tr_tax_rate = (float)($tr['tax_rate'] ?? 0);
    $taxRateKey = number_format($tr_tax_rate, 2, '.', '');
    $taxTypeKey = (string)($tr['tax_type'] ?? 'None');
    $agKey = ((int)($tr['lease_agent_id'] ?? 0)) . '_' . $taxTypeKey . '_' . $taxRateKey;
    if (!isset($trip_agent_groups[$agKey])) {
        $agLabel = $tr['lease_agent_name'] . (!empty($tr['lease_agent_code']) ? ' (' . $tr['lease_agent_code'] . ')' : '');
        $trip_agent_groups[$agKey] = [
            'label'    => $agLabel,
            'tax_type' => $taxTypeKey,
            'tax_rate' => $tr_tax_rate,
            'companies' => [],
            'totals'   => ['weight' => 0.0, 'net' => 0.0, 'tax' => 0.0, 'misc_ded' => 0.0, 'gross' => 0.0, 'trip_count' => 0],
        ];
    }
    $coKey = (int)($tr['lease_agent_bill_to_company_id'] ?: $tr['company_id'] ?? 0);
    if (!isset($trip_agent_groups[$agKey]['companies'][$coKey])) {
        $trip_agent_groups[$agKey]['companies'][$coKey] = [
            'label'  => $tr['bill_to_company_name'] ?? $tr['company_name'] ?? 'No Company',
            'rows'   => [],
            'totals' => ['weight' => 0.0, 'net' => 0.0, 'tax' => 0.0, 'misc_ded' => 0.0, 'gross' => 0.0],
        ];
    }
    $tr_weight = (float)($tr['total_weight'] ?? 0);
    $tr_net = fleetLeaseAgentTripBasePayable($tr);
    $tr_misc_ded = (float)($tr['lease_agent_misc_deduction'] ?? 0);
    $tr_tax    = $tr_net * ((float)$tr['tax_rate'] / 100);
    $tr_gross  = max(0, $tr_net + $tr_tax - $tr_misc_ded);
    $trip_agent_groups[$agKey]['companies'][$coKey]['rows'][] = $tr;
    $trip_agent_groups[$agKey]['companies'][$coKey]['totals']['weight']   += $tr_weight;
    $trip_agent_groups[$agKey]['companies'][$coKey]['totals']['net']      += $tr_net;
    $trip_agent_groups[$agKey]['companies'][$coKey]['totals']['tax']      += $tr_tax;
    $trip_agent_groups[$agKey]['companies'][$coKey]['totals']['misc_ded'] += $tr_misc_ded;
    $trip_agent_groups[$agKey]['companies'][$coKey]['totals']['gross']    += $tr_gross;
    $trip_agent_groups[$agKey]['totals']['weight']   += $tr_weight;
    $trip_agent_groups[$agKey]['totals']['net']      += $tr_net;
    $trip_agent_groups[$agKey]['totals']['tax']      += $tr_tax;
    $trip_agent_groups[$agKey]['totals']['misc_ded'] += $tr_misc_ded;
    $trip_agent_groups[$agKey]['totals']['gross']    += $tr_gross;
    $trip_agent_groups[$agKey]['totals']['trip_count']++;
}
if (!function_exists('leaseTaxLabel')) {
    function leaseTaxLabel(string $type, float $rate): string {
        if ($type === 'None' || $type === '') return 'No Tax';
        $label = $type;
        if ($rate > 0) $label .= ' ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
        return $label;
    }
}
?>

<div class="card mt-3">
    <!-- Tab Nav -->
    <div class="card-header p-0 border-bottom">
        <ul class="nav nav-tabs card-header-tabs ms-0" id="reportTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-semibold px-4 py-2" id="tab-trips" data-bs-toggle="tab"
                    data-bs-target="#pane-trips" type="button" role="tab">
                    <i class="bi bi-truck me-1"></i>Trips &mdash; Agent Wise
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold px-4 py-2" id="tab-ledger" data-bs-toggle="tab"
                    data-bs-target="#pane-ledger" type="button" role="tab">
                    <i class="bi bi-cash-stack me-1"></i>Payment Ledger
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold px-4 py-2" id="tab-print-ledger" data-bs-toggle="tab"
                    data-bs-target="#pane-print-ledger" type="button" role="tab">
                    <i class="bi bi-printer me-1"></i>Print Payment Ledger
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold px-4 py-2" id="tab-transit" data-bs-toggle="tab"
                    data-bs-target="#pane-transit" type="button" role="tab">
                    <i class="bi bi-truck me-1"></i>Vehicles in Transit
                    <?php if (!empty($transit_rows)): ?>
                    <span class="badge bg-warning text-dark ms-1"><?= count($transit_rows) ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item ms-auto d-flex align-items-center gap-2 pe-2">
                <a href="fleet_lease_agent_report.php?action=print_ledger&lease_agent_id=<?= (int)$agent_filter ?>"
                   target="_blank" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-printer me-1"></i>Print Ledger
                </a>
                <a href="fleet_lease_agent_report.php?action=print_unbilled&month=<?= urlencode($month) ?>&lease_agent_id=<?= (int)$agent_filter ?>&lang=en"
                   target="_blank" class="btn btn-outline-light btn-sm" title="Print Unbilled Trips (English)">
                    <i class="bi bi-printer me-1"></i>Unbilled (EN)
                </a>
                <a href="fleet_lease_agent_report.php?action=print_unbilled&month=<?= urlencode($month) ?>&lease_agent_id=<?= (int)$agent_filter ?>&lang=hi"
                   target="_blank" class="btn btn-warning btn-sm fw-semibold" title="गैर-बिल योग्य ट्रिप प्रिंट करें (हिंदी)">
                    <i class="bi bi-printer me-1"></i>प्रिंट गैर-बिल ट्रिप (HI)
                </a>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="reportTabContent">
        <!-- ============================================================
             TAB 1 — TRIPS AGENT WISE
             ============================================================ -->
        <div class="tab-pane fade show active" id="pane-trips" role="tabpanel">
            <?php if ($billing_filter !== 'all' || $payment_filter !== 'all'): ?>
            <div class="px-3 pt-2 small text-muted">
                Showing:
                <?php if ($billing_filter !== 'all'): ?>
                    <?= $billing_filter === 'billed' ? '<span class="badge badge-billed"><i class="bi bi-check2-circle me-1"></i>Billed</span>' : '<span class="badge badge-not-billed"><i class="bi bi-receipt-cutoff me-1"></i>Not Billed</span>' ?>
                <?php endif; ?>
                <?php if ($payment_filter !== 'all'): ?>
                    <?php
                        $pfBadge = $payment_filter === 'paid' ? 'badge-paid' : ($payment_filter === 'partial' ? 'bg-warning text-dark' : 'badge-unpaid');
                    ?>
                    <span class="badge <?= $pfBadge ?>"><?= htmlspecialchars(ucfirst($payment_filter)) ?></span>
                <?php endif; ?>
                trips only
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle text-nowrap" style="font-size:.84rem">
                    <thead class="table-light">
                        <tr>
                            <th class="text-nowrap">Date</th>
                            <th class="text-nowrap">Trip No</th>
                            <th class="text-nowrap">Vehicle</th>
                            <th class="text-nowrap">Customer</th>
                            <th class="text-end text-nowrap">Weight (MT)</th>
                            <th class="text-end text-nowrap">Rate/MT</th>
                            <th class="text-end text-nowrap">Net Freight</th>
                            <th class="text-end text-nowrap">Tax</th>
                            <th class="text-end text-nowrap">Tax Amt</th>
                            <th class="text-end text-nowrap">Misc Ded</th>
                            <th class="text-end text-nowrap">Gross Payable</th>
                            <th class="text-nowrap">Agent Invoice</th>
                            <th class="text-nowrap">Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$trip_rows): ?>
                        <tr><td colspan="13" class="text-muted p-3">No trips.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($trip_agent_groups as $agGrpKey => $agGrp): ?>
                        <tr class="table-info">
                            <td colspan="13" class="fw-semibold al-left py-1">
                                <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($agGrp['label']) ?>
                                <?php if ($agGrp['tax_type'] !== 'None' && $agGrp['tax_type'] !== ''): ?>
                                &mdash; <span class="badge bg-warning text-dark py-0" style="font-size:.75rem"><?= htmlspecialchars(leaseTaxLabel($agGrp['tax_type'], $agGrp['tax_rate'])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php foreach ($agGrp['companies'] as $coGrpKey => $coGrp): ?>
                        <tr style="background:#e8f5e9">
                            <td colspan="13" class="fw-semibold ps-4 al-left py-1" style="font-size:.85rem;color:#2e7d32">
                                <i class="bi bi-building me-1"></i><?= htmlspecialchars($coGrp['label']) ?>
                            </td>
                        </tr>
                        <?php foreach ($coGrp['rows'] as $trip): ?>
                        <?php
                            $trip_weight = (float)($trip['total_weight'] ?? 0);
                            $trip_payable_total = fleetLeaseAgentTripBasePayable($trip);
                            $trip_misc_ded = (float)($trip['lease_agent_misc_deduction'] ?? 0);
                            $trip_misc_remarks = trim((string)($trip['lease_agent_misc_deduction_remarks'] ?? ''));
                            $trip_payable_rate = $trip_weight > 0 ? ($trip_payable_total / $trip_weight) : 0;
                            $trip_tax_rate = (float)($trip['tax_rate'] ?? 0);
                            $trip_tax_label = leaseTaxLabel((string)($trip['tax_type'] ?? 'None'), $trip_tax_rate);
                            $trip_tax_amt = $trip_payable_total * ($trip_tax_rate / 100);
                            $trip_gross = max(0, $trip_payable_total + $trip_tax_amt - $trip_misc_ded);
                            $trip_agent_billing_status = (($trip['lease_agent_billing_status'] ?? 'Pending') === 'Billed') ? 'Billed' : 'Pending';
                            $trip_agent_invoice_no = trim((string)($trip['lease_agent_invoice_no'] ?? ''));
                            if ($trip_agent_invoice_no === '' && !empty($trip['lap_agent_invoice_no'])) {
                                $trip_agent_invoice_no = trim((string)$trip['lap_agent_invoice_no']);
                            }
                            $trip_agent_invoice_date = trim((string)($trip['lease_agent_invoice_date'] ?? ''));
                            $trip_order_status = trim((string)($trip['trip_order_status'] ?? ''));
                            $trip_payment_status = !empty($trip['lap_payment_id'])
                                ? (($trip['lap_payment_status'] !== '') ? $trip['lap_payment_status'] : 'Paid')
                                : 'Unpaid';
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= date('d/m/Y', strtotime($trip['trip_date'])) ?></td>
                            <td class="text-nowrap"><a href="fleet_trips.php?action=view&id=<?= (int)$trip['id'] ?>"><?= htmlspecialchars($trip['trip_no']) ?></a></td>
                            <td class="text-nowrap"><?= htmlspecialchars($trip['reg_no'] ?: '-') ?></td>
                            <td class="text-truncate text-nowrap" style="max-width:180px" title="<?= htmlspecialchars($trip['customer_name'] ?? '') ?>"><?= htmlspecialchars($trip['customer_name'] ?? '-') ?></td>
                            <td class="text-end text-nowrap"><?= number_format($trip_weight, 3) ?></td>
                            <td class="text-end text-nowrap text-primary">&#8377;<?= number_format($trip_payable_rate, 2) ?></td>
                            <td class="text-end text-nowrap">&#8377;<?= number_format($trip_payable_total, 2) ?></td>
                            <td class="text-end text-nowrap"><small class="text-muted"><?= htmlspecialchars($trip_tax_label) ?></small></td>
                            <td class="text-end text-nowrap">&#8377;<?= number_format($trip_tax_amt, 2) ?></td>
                            <td class="text-end text-nowrap <?= $trip_misc_ded > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>" <?= $trip_misc_remarks !== '' ? 'title="' . htmlspecialchars($trip_misc_remarks) . '"' : '' ?>>
                                <?= $trip_misc_ded > 0 ? '-&#8377;' . number_format($trip_misc_ded, 2) : '—' ?>
                                <?php if ($trip_misc_remarks !== ''): ?>
                                    <i class="bi bi-info-circle text-muted ms-1" style="font-size:.72rem"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap fw-semibold text-success">&#8377;<?= number_format($trip_gross, 2) ?></td>
                            <td class="text-nowrap">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <span class="badge <?= $trip_agent_billing_status === 'Billed' ? 'badge-billed' : 'badge-not-billed' ?> badge-fixed-billing">
                                        <i class="bi bi-<?= $trip_agent_billing_status === 'Billed' ? 'check2-circle' : 'receipt-cutoff' ?> me-1"></i><?= $trip_agent_billing_status === 'Billed' ? 'Billed' : 'Not Billed' ?>
                                    </span>
                                    <?php if ($trip_agent_invoice_no !== ''): ?>
                                        <span class="badge bg-light text-dark border badge-inv-no" title="Agent Inv No: <?= htmlspecialchars($trip_agent_invoice_no) ?><?= ($trip_agent_invoice_date && $trip_agent_invoice_date !== '0000-00-00') ? ' (' . date('d/m/Y', strtotime($trip_agent_invoice_date)) . ')' : '' ?>">
                                            <i class="bi bi-receipt me-1 text-primary"></i><?= htmlspecialchars($trip_agent_invoice_no) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-inv-no" style="visibility:hidden">#--</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-nowrap">
                                <?php
                                    $psBadge = $trip_payment_status === 'Paid'    ? 'badge-paid'
                                             : ($trip_payment_status === 'Partial' ? 'bg-warning text-dark fw-bold'
                                             : 'badge-unpaid');
                                    $psIcon  = $trip_payment_status === 'Paid'    ? 'check-circle'
                                             : ($trip_payment_status === 'Partial' ? 'hourglass-split'
                                             : 'x-circle');
                                ?>
                                <span class="badge <?= $psBadge ?> badge-fixed-pay"><i class="bi bi-<?= $psIcon ?> me-1"></i><?= htmlspecialchars($trip_payment_status) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($agGrp['companies']) > 1): ?>
                        <tr style="background:#f1f8e9;font-size:.82rem">
                            <td colspan="4" class="text-end ps-4 fst-italic text-nowrap"><?= htmlspecialchars($coGrp['label']) ?> (<?= count($coGrp['rows']) ?> trips)</td>
                            <td class="text-end text-nowrap"><?= number_format($coGrp['totals']['weight'], 3) ?></td>
                            <td></td>
                            <td class="text-end text-nowrap">&#8377;<?= number_format($coGrp['totals']['net'], 2) ?></td>
                            <td></td>
                            <td class="text-end text-nowrap">&#8377;<?= number_format($coGrp['totals']['tax'], 2) ?></td>
                            <td class="text-end text-nowrap <?= $coGrp['totals']['misc_ded'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= $coGrp['totals']['misc_ded'] > 0 ? '-&#8377;' . number_format($coGrp['totals']['misc_ded'], 2) : '—' ?></td>
                            <td class="text-end text-nowrap text-success">&#8377;<?= number_format($coGrp['totals']['gross'], 2) ?></td>
                            <td></td><td></td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <tr class="table-light fw-semibold" style="font-size:.82rem">
                            <td colspan="4" class="text-end text-nowrap">Subtotal &mdash; <?= htmlspecialchars(leaseTaxLabel($agGrp['tax_type'], $agGrp['tax_rate'])) ?> (<?= $agGrp['totals']['trip_count'] ?> trips)</td>
                            <td class="text-end text-nowrap"><?= number_format($agGrp['totals']['weight'], 3) ?></td>
                            <td></td>
                            <td class="text-end text-nowrap">&#8377;<?= number_format($agGrp['totals']['net'], 2) ?></td>
                            <td></td>
                            <td class="text-end text-nowrap">&#8377;<?= number_format($agGrp['totals']['tax'], 2) ?></td>
                            <td class="text-end text-nowrap <?= $agGrp['totals']['misc_ded'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= $agGrp['totals']['misc_ded'] > 0 ? '-&#8377;' . number_format($agGrp['totals']['misc_ded'], 2) : '—' ?></td>
                            <td class="text-end text-nowrap text-success">&#8377;<?= number_format($agGrp['totals']['gross'], 2) ?></td>
                            <td></td><td></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div><!-- /pane-trips -->

        <!-- ============================================================
             TAB 2 — PAYMENT LEDGER
             ============================================================ -->
        <div class="tab-pane fade" id="pane-ledger" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle" id="ledgerTable">
                    <thead class="table-light">
                        <tr>
                            <th>Payment No</th>
                            <th>Agent Inv No</th>
                            <th>Payment Date</th>
                            <th class="al-left">Lease Agent</th>
                            <th class="text-end">Trips</th>
                            <th class="text-end">Total Payable</th>
                            <th class="text-end">Paid Amount</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>UTR No</th>
                            <th>Bank</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payment_ledger_by_agent)): ?>
                        <tr><td colspan="12" class="text-muted p-3">No payment records found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($payment_ledger_by_agent as $aid => $agLedger): ?>
                        <tr class="table-info">
                            <td colspan="12" class="fw-semibold al-left">
                                <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($agLedger['label']) ?>
                            </td>
                        </tr>
                        <?php foreach ($agLedger['payments'] as $pr):
                            $prStatus = $pr['status'] ?: 'Unpaid';
                            $prClass  = $prStatus === 'Paid' ? 'badge-paid' : ($prStatus === 'Partial' ? 'bg-warning text-dark fw-bold' : 'badge-unpaid');
                            $prIcon   = $prStatus === 'Paid' ? 'check-circle' : ($prStatus === 'Partial' ? 'hourglass-split' : 'x-circle');
                        ?>
                        <tr>
                            <td class="fw-semibold">
                                <a href="fleet_lease_agent_payments.php?action=view&id=<?= (int)$pr['payment_id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($pr['payment_no'] ?: ('LAP-' . (int)$pr['payment_id'])) ?>
                                </a>
                                <a href="print_lease_agent_payment_advice.php?id=<?= (int)$pr['payment_id'] ?>&lang=en" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1" title="Print Advice (English)">EN</a>
                                <a href="print_lease_agent_payment_advice.php?id=<?= (int)$pr['payment_id'] ?>&lang=hi" target="_blank" class="btn btn-sm btn-outline-success py-0 px-1" title="प्रिंट सूचना (Hindi)">HI</a>
                            </td>
                            <td>
                                <?= !empty($pr['agent_invoice_no']) ? '<span class="badge bg-light text-dark border"><i class="bi bi-receipt me-1 text-primary"></i>' . htmlspecialchars($pr['agent_invoice_no']) . '</span>' : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($pr['payment_date'])) ?></td>
                            <td class="al-left"><?= htmlspecialchars($pr['agent_name'] . (!empty($pr['agent_code']) ? ' (' . $pr['agent_code'] . ')' : '')) ?></td>
                            <td class="text-end"><?= (int)$pr['trip_count'] ?></td>
                            <td class="text-end">&#8377;<?= number_format((float)$pr['total_payable'], 2) ?></td>
                            <td class="text-end text-success fw-semibold">&#8377;<?= number_format((float)$pr['paid_amount'], 2) ?></td>
                            <td class="text-end text-danger">&#8377;<?= number_format((float)$pr['balance_amount'], 2) ?></td>
                            <td><span class="badge <?= $prClass ?>"><i class="bi bi-<?= $prIcon ?> me-1"></i><?= htmlspecialchars($prStatus) ?></span></td>
                            <td><?= htmlspecialchars($pr['reference_no'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($pr['utr_no'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($pr['bank_name'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Agent subtotal row -->
                        <tr class="table-light fw-semibold">
                            <td colspan="5" class="text-end">Agent Total (<?= count($agLedger['payments']) ?> payments)</td>
                            <td class="text-end">&#8377;<?= number_format($agLedger['total_payable'], 2) ?></td>
                            <td class="text-end text-success">&#8377;<?= number_format($agLedger['total_paid'], 2) ?></td>
                            <td class="text-end text-danger">&#8377;<?= number_format($agLedger['total_balance'], 2) ?></td>
                            <td colspan="4"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($payment_ledger_by_agent)): ?>
                    <tfoot class="table-dark">
                        <tr>
                            <th colspan="5" class="text-end">Grand Total</th>
                            <th class="text-end">&#8377;<?= number_format($ledger_grand_payable, 2) ?></th>
                            <th class="text-end text-success">&#8377;<?= number_format($ledger_grand_paid, 2) ?></th>
                            <th class="text-end" style="color:#f87171">&#8377;<?= number_format($ledger_grand_balance, 2) ?></th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div><!-- /pane-ledger -->

        <!-- ============================================================
             TAB — PRINT PAYMENT LEDGER (inline print-ready view)
             ============================================================ -->
        <div class="tab-pane fade" id="pane-print-ledger" role="tabpanel">
            <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
                <div class="text-muted small">
                    Print-ready view of the payment ledger<?= $agent_filter > 0 ? ' (filtered to the selected agent)' : ' — all agents' ?>.
                </div>
                <button type="button" class="btn btn-success btn-sm" onclick="printPaymentLedgerTab()">
                    <i class="bi bi-printer me-1"></i>Print This Ledger
                </button>
            </div>
            <div id="printLedgerSheet" class="px-3 pb-3">
                <div class="d-flex justify-content-between align-items-start border-bottom border-2 pb-2 mb-3" style="border-color:#0f766e !important">
                    <div>
                        <div class="fw-bold" style="font-size:1.1rem;color:#0f766e">Lease Agent &mdash; Payment Ledger</div>
                        <div class="text-muted small"><?= $agent_filter > 0 ? htmlspecialchars($payment_ledger_by_agent[$agent_filter]['label'] ?? ('Agent #' . $agent_filter)) : 'All Agents' ?></div>
                    </div>
                    <div class="text-muted small text-end">
                        <div>Printed: <?= date('d/m/Y H:i') ?></div>
                    </div>
                </div>
                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.82rem">
                    <thead class="table-light">
                        <tr>
                            <th>Payment No</th>
                            <th>Agent Inv No</th>
                            <th>Payment Date</th>
                            <th>Period</th>
                            <th class="al-left">Lease Agent</th>
                            <th class="text-end">Trips</th>
                            <th class="text-end">Total Payable</th>
                            <th class="text-end">Paid Amount</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>UTR No</th>
                            <th>Bank</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payment_ledger_by_agent)): ?>
                        <tr><td colspan="13" class="text-muted p-3 text-center">No payment records found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($payment_ledger_by_agent as $aid => $agLedger): ?>
                        <tr style="background:#e0f2f1">
                            <td colspan="13" class="fw-semibold al-left" style="color:#0f766e">
                                <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($agLedger['label']) ?>
                            </td>
                        </tr>
                        <?php foreach ($agLedger['payments'] as $pr):
                            $prStatus = $pr['status'] ?: 'Unpaid';
                            $prClass  = $prStatus === 'Paid' ? 'badge-paid' : ($prStatus === 'Partial' ? 'bg-warning text-dark fw-bold' : 'badge-unpaid');
                            $prIcon   = $prStatus === 'Paid' ? 'check-circle' : ($prStatus === 'Partial' ? 'hourglass-split' : 'x-circle');
                            $prPeriod = (!empty($pr['date_from']) && !empty($pr['date_to']))
                                ? date('d/m/y', strtotime($pr['date_from'])) . ' - ' . date('d/m/y', strtotime($pr['date_to']))
                                : '—';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($pr['payment_no'] ?: ('LAP-' . (int)$pr['payment_id'])) ?></td>
                            <td><?= htmlspecialchars($pr['agent_invoice_no'] ?: '—') ?></td>
                            <td><?= date('d/m/Y', strtotime($pr['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($prPeriod) ?></td>
                            <td class="al-left"><?= htmlspecialchars($pr['agent_name'] . (!empty($pr['agent_code']) ? ' (' . $pr['agent_code'] . ')' : '')) ?></td>
                            <td class="text-end"><?= (int)$pr['trip_count'] ?></td>
                            <td class="text-end">&#8377;<?= number_format((float)$pr['total_payable'], 2) ?></td>
                            <td class="text-end text-success">&#8377;<?= number_format((float)$pr['paid_amount'], 2) ?></td>
                            <td class="text-end text-danger">&#8377;<?= number_format((float)$pr['balance_amount'], 2) ?></td>
                            <td><span class="badge <?= $prClass ?>"><i class="bi bi-<?= $prIcon ?> me-1"></i><?= htmlspecialchars($prStatus) ?></span></td>
                            <td><?= htmlspecialchars($pr['reference_no'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($pr['utr_no'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($pr['bank_name'] ?: '—') ?></td>
                        </tr>
                        <?php if (trim((string)($pr['notes'] ?? '')) !== ''): ?>
                        <tr><td colspan="13" class="text-muted fst-italic small py-1">Note: <?= htmlspecialchars(trim($pr['notes'])) ?></td></tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <tr class="table-light fw-semibold">
                            <td colspan="6" class="text-end">Agent Total (<?= count($agLedger['payments']) ?> payments)</td>
                            <td class="text-end">&#8377;<?= number_format($agLedger['total_payable'], 2) ?></td>
                            <td class="text-end text-success">&#8377;<?= number_format($agLedger['total_paid'], 2) ?></td>
                            <td class="text-end text-danger">&#8377;<?= number_format($agLedger['total_balance'], 2) ?></td>
                            <td colspan="4"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($payment_ledger_by_agent)): ?>
                    <tfoot>
                        <tr class="fw-bold" style="background:#0f766e;color:#fff">
                            <td colspan="6" class="text-end">Grand Total</td>
                            <td class="text-end">&#8377;<?= number_format($ledger_grand_payable, 2) ?></td>
                            <td class="text-end">&#8377;<?= number_format($ledger_grand_paid, 2) ?></td>
                            <td class="text-end">&#8377;<?= number_format($ledger_grand_balance, 2) ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div><!-- /pane-print-ledger -->

        <!-- ============================================================
             TAB 3 — VEHICLES IN TRANSIT
             ============================================================ -->
        <div class="tab-pane fade" id="pane-transit" role="tabpanel">
            <?php if (empty($transit_rows)): ?>
            <div class="alert alert-success m-3 mb-0"><i class="bi bi-check-circle me-2"></i>No lease agent vehicles currently in transit.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Trip No</th>
                            <th>Trip Date</th>
                            <th>Vehicle</th>
                            <th>Customer</th>
                            <th class="al-left">Bill To Company</th>
                            <th class="al-left">Operating Company</th>
                            <th class="text-end">Days in Transit</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transit_by_agent as $agKey => $agGrp): ?>
                    <tr class="table-warning">
                        <td colspan="7" class="fw-semibold al-left">
                            <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($agGrp['label']) ?>
                            <span class="badge bg-dark ms-2"><?= count($agGrp['rows']) ?> vehicle<?= count($agGrp['rows']) > 1 ? 's' : '' ?></span>
                        </td>
                    </tr>
                    <?php foreach ($agGrp['rows'] as $tr): ?>
                    <?php
                        $transit_since = !empty($tr['start_date']) && $tr['start_date'] !== '0000-00-00' ? $tr['start_date'] : $tr['trip_date'];
                        $days_in_transit = max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime($transit_since)) / 86400));
                        $days_badge = $days_in_transit >= 3 ? 'danger' : ($days_in_transit >= 1 ? 'warning text-dark' : 'success');
                    ?>
                    <tr>
                        <td><a href="fleet_trips.php?action=view&id=<?= (int)$tr['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($tr['trip_no']) ?></a></td>
                        <td><?= date('d/m/Y', strtotime($tr['trip_date'])) ?></td>
                        <td><?= htmlspecialchars($tr['reg_no'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($tr['customer_name'] ?? '-') ?></td>
                        <td class="al-left"><span class="badge bg-info text-dark"><?= htmlspecialchars($tr['bill_to_company_name'] ?? $tr['company_name'] ?? '-') ?></span></td>
                        <td class="al-left"><?= htmlspecialchars($tr['company_name']) ?></td>
                        <td class="text-end">
                            <span class="badge bg-<?= $days_badge ?>"><?= $days_in_transit ?> day<?= $days_in_transit !== 1 ? 's' : '' ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-light fw-semibold" style="font-size:.85rem">
                        <td colspan="7" class="text-end fst-italic"><?= htmlspecialchars($agGrp['label']) ?> — <?= count($agGrp['rows']) ?> trip<?= count($agGrp['rows']) > 1 ? 's' : '' ?> in transit</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th colspan="7" class="text-end">Grand Total &mdash; <?= count($transit_rows) ?> vehicle<?= count($transit_rows) > 1 ? 's' : '' ?> in transit</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div><!-- /pane-transit -->


    </div><!-- /tab-content -->
</div><!-- /card -->

<style>
/* Tab text: white on blue header */
#reportTabs .nav-link {
    color: rgba(255,255,255,0.75);
    border-color: transparent;
}
#reportTabs .nav-link:hover {
    color: #fff;
    border-color: rgba(255,255,255,0.3) rgba(255,255,255,0.3) transparent;
    background: rgba(255,255,255,0.1);
}
#reportTabs .nav-link.active {
    color: #0f766e;
    background: #fff;
    border-color: rgba(255,255,255,0.4) rgba(255,255,255,0.4) #fff;
    font-weight: 700;
}
#reportTabs .nav-link .badge {
    vertical-align: middle;
}
/* Center-align all headings and data across every table on this page */
.card .table th,
.card .table td {
    text-align: center !important;
}
/* ...except Lease Agent name / Company name columns, which stay left-aligned */
.card .table th.al-left,
.card .table td.al-left {
    text-align: left !important;
}
/* Print: show all tab panes one after another */
@media print {
    .nav-tabs, .nav-item { display: none !important; }
    .tab-pane { display: block !important; opacity: 1 !important; }
    #pane-ledger  { page-break-before: always; }
    #pane-print-ledger { page-break-before: always; }
    #pane-transit { page-break-before: always; }
    .no-print { display: none !important; }
}
/* When printing just the Print Payment Ledger tab, hide everything else */
@media print {
    body.print-ledger-only .card-header,
    body.print-ledger-only .tab-pane:not(#pane-print-ledger) {
        display: none !important;
    }
}

/* Bright vibrant badges for Not Billed, Unpaid, Billed, Paid */
.badge-not-billed {
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%) !important; /* Bright Electric Orange */
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 0.38em 0.7em !important;
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
    padding: 0.38em 0.7em !important;
    border-radius: 6px !important;
    letter-spacing: 0.02em;
    box-shadow: 0 2px 5px rgba(220, 38, 38, 0.35);
    display: inline-flex;
    align-items: center;
}
.badge-billed {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important; /* Sapphire Blue */
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 0.38em 0.7em !important;
    border-radius: 6px !important;
    display: inline-flex;
    align-items: center;
}
.badge-paid {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important; /* Emerald Green */
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 0.38em 0.7em !important;
    border-radius: 6px !important;
    display: inline-flex;
    align-items: center;
}
</style>
<script>
function printPaymentLedgerTab() {
    document.body.classList.add('print-ledger-only');
    window.print();
}
window.addEventListener('afterprint', function () {
    document.body.classList.remove('print-ledger-only');
});
</script>

<?php include '../includes/footer.php'; ?>
