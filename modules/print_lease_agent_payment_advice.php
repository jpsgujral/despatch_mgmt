<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fleet_lease_agent_helper.php';

$db = getDB();
if (!canDo('fleet_lease_agent_payments', 'view') && !canDo('fleet_lease_agents', 'view') && !canDo('reports', 'view')) {
    requirePerm('fleet_lease_agents', 'view');
}

$payment_id = (int)($_GET['id'] ?? $_GET['payment_id'] ?? 0);
if ($payment_id <= 0) die('Invalid payment ID.');

$lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
if ($lang !== 'hi') $lang = 'en';

$dict = [
    'en' => [
        'doc_title'     => 'Lease Agent Payment Advice',
        'title'         => 'LEASE AGENT PAYMENT ADVICE',
        'advice_no'     => 'Payment Advice No:',
        'date'          => 'Payment Date:',
        'lease_agent'   => 'Lease Agent',
        'bill_to'       => 'Bill To Company',
        'period'        => 'Billing Period',
        'payment_mode'  => 'Payment Mode',
        'reference_no'  => 'Reference / Cheque No',
        'utr_no'        => 'UTR No',
        'bank_name'     => 'Bank Name',
        'agent_inv'     => 'Agent Invoice No',
        'status'        => 'Status',
        'notes'         => 'Remarks / Notes',
        'section_title' => 'Included Trip Orders Details',
        'col_sno'       => '#',
        'col_trip'      => 'Trip No',
        'col_inv'       => 'Agent Inv No',
        'col_date'      => 'Trip Date',
        'col_vehicle'   => 'Vehicle No',
        'col_company'   => 'Bill To Company',
        'col_vendor'    => 'Customer / Route',
        'col_weight'    => 'Weight (MT)',
        'col_rate'      => 'Payable / MT',
        'col_net'       => 'Net Freight',
        'col_tax'       => 'Tax Amount',
        'col_misc_ded'  => 'Misc Deduction',
        'col_gross'     => 'Gross Payable',
        'totals'        => 'Totals',
        'trip_count'    => 'Total Trips',
        'total_payable' => 'Total Payable Amount',
        'paid_amount'   => 'Amount Paid',
        'balance_due'   => 'Balance Due',
        'prepared_by'   => 'Prepared By',
        'authorised_by' => 'Authorised Signature',
        'receiver_sig'  => 'Receiver\'s Signature',
        'footer_note'   => 'This is a system-generated payment advice for Lease Agent Payment: ',
        'btn_print'     => 'Print Advice',
        'btn_close'     => 'Close',
        'lang_label'    => 'Language:',
    ],
    'hi' => [
        'doc_title'     => 'लीज एजेंट भुगतान सूचना (Lease Agent Payment Advice)',
        'title'         => 'लीज एजेंट भुगतान सूचना',
        'advice_no'     => 'भुगतान सूचना क्रमांक:',
        'date'          => 'भुगतान दिनांक:',
        'lease_agent'   => 'लीज एजेंट (Lease Agent)',
        'bill_to'       => 'बिल टू कंपनी',
        'period'        => 'बिलिंग अवधि',
        'payment_mode'  => 'भुगतान का प्रकार',
        'reference_no'  => 'संदर्भ / चेक संख्या (Ref/Cheque)',
        'utr_no'        => 'यूटीआर नंबर (UTR No)',
        'bank_name'     => 'बैंक का नाम',
        'agent_inv'     => 'एजेंट चालान नंबर (Invoice No)',
        'status'        => 'भुगतान स्थिति',
        'notes'         => 'विवरण / टिप्पणी',
        'section_title' => 'शामिल ट्रिप ऑर्डर का विवरण',
        'col_sno'       => 'क्र.',
        'col_trip'      => 'ट्रिप नंबर',
        'col_inv'       => 'एजेंट चालान सं.',
        'col_date'      => 'ट्रिप दिनांक',
        'col_vehicle'   => 'वाहन नंबर',
        'col_company'   => 'बिल टू कंपनी',
        'col_vendor'    => 'ग्राहक / रूट',
        'col_weight'    => 'वजन (MT)',
        'col_rate'      => 'देय दर / MT',
        'col_net'       => 'शुद्ध भाड़ा',
        'col_tax'       => 'कर राशि (Tax)',
        'col_misc_ded'  => 'अन्य कटौती (Misc Ded)',
        'col_gross'     => 'कुल देय',
        'totals'        => 'कुल योग',
        'trip_count'    => 'कुल ट्रिप्स',
        'total_payable' => 'कुल देय राशि',
        'paid_amount'   => 'भुगतान की गई राशि (Paid)',
        'balance_due'   => 'बकाया राशि (Balance Due)',
        'prepared_by'   => 'द्वारा तैयार',
        'authorised_by' => 'अधिकृत हस्ताक्षर',
        'receiver_sig'  => 'प्राप्तकर्ता के हस्ताक्षर',
        'footer_note'   => 'यह लीज एजेंट भुगतान के लिए एक सिस्टम-जनरेटेड सूचना पत्र है: ',
        'btn_print'     => 'प्रिंट सूचना (Print)',
        'btn_close'     => 'बंद करें (Close)',
        'lang_label'    => 'आउटपुट भाषा (Language):',
    ]
];
$t = $dict[$lang];

$payment = $db->query("
    SELECT p.*, la.agent_name, la.agent_code, COALESCE(la.tax_type, 'None') AS tax_type, COALESCE(la.tax_rate, 0) AS tax_rate,
           COALESCE(btco.company_name, pco.company_name, co.company_name, 'General') AS company_name,
           au.full_name AS created_by_name, au.username AS created_by_username
    FROM fleet_lease_agent_payments p
    LEFT JOIN fleet_lease_agents la ON la.id = p.lease_agent_id
    LEFT JOIN companies pco ON pco.id = p.company_id
    LEFT JOIN fleet_lease_agent_payment_trips lpt ON lpt.payment_id = p.id
    LEFT JOIN fleet_trips ft ON ft.id = lpt.trip_id
    LEFT JOIN companies co ON co.id = ft.company_id
    LEFT JOIN companies btco ON btco.id = ft.lease_agent_bill_to_company_id
    LEFT JOIN app_users au ON au.id = p.created_by
    WHERE p.id = $payment_id
    GROUP BY p.id
")->fetch_assoc();

if (!$payment) die('Payment record not found.');

if (isLeaseAgentUser() && (int)$payment['lease_agent_id'] !== currentLeaseAgentId()) {
    die('Unauthorized access to lease agent payment advice.');
}

$taxRate = (float)($payment['tax_rate'] ?? 0);

$trips = $db->query("
    SELECT pt.payment_id, t.id, t.trip_no, t.trip_date, t.total_weight, t.freight_amount,
           t.lease_agent_margin_per_mt, t.lease_agent_amount, t.net_freight_amount,
           COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') AS lease_agent_billing_model,
           t.lease_agent_misc_deduction, t.lease_agent_misc_deduction_remarks,
           t.lease_agent_invoice_no, t.lease_agent_invoice_date,
           CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, $taxRate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, $taxRate, 0) END AS trip_tax_rate,
           COALESCE(vn.vendor_name, t.customer_name, '') AS vendor_name,
           COALESCE(btco.company_name, co.company_name, '') AS company_name,
           v.reg_no
    FROM fleet_lease_agent_payment_trips pt
    JOIN fleet_trips t ON t.id = pt.trip_id
    LEFT JOIN fleet_lease_agents la ON la.id = t.lease_agent_id
    LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id = t.po_id
    LEFT JOIN fleet_vehicles v ON v.id = t.vehicle_id
    LEFT JOIN fleet_customers_master vn ON vn.id = t.vendor_id
    LEFT JOIN companies co ON co.id = t.company_id
    LEFT JOIN companies btco ON btco.id = t.lease_agent_bill_to_company_id
    WHERE pt.payment_id = $payment_id
    ORDER BY t.trip_date ASC, t.id ASC
")->fetch_all(MYSQLI_ASSOC);

function companyDocImageUrl(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('#^https?://#i', $value)) return $value;
    if (strpos($value, 'uploads/') === 0) return '../' . ltrim($value, '/');
    return function_exists('r2_url') ? (string)r2_url($value) : '';
}

$company = $db->query("SELECT company_name, address, city, state, gstin, phone, email FROM company_settings LIMIT 1")->fetch_assoc() ?: [];
$company_name = $company['company_name'] ?? 'Despatch Management System';
$logged_in_user_id = (int)($_SESSION['user_id'] ?? 0);
$logged_in_user = $logged_in_user_id > 0
    ? ($db->query("SELECT full_name, username, signature_path FROM app_users WHERE id=$logged_in_user_id LIMIT 1")->fetch_assoc() ?: [])
    : [];
$prepared_by_name = trim((string)($payment['created_by_name'] ?? $payment['created_by_username'] ?? $logged_in_user['full_name'] ?? 'System User'));
$authorised_sig = companyDocImageUrl((string)($logged_in_user['signature_path'] ?? ''));

$paymentNo = ($payment['payment_no'] ?? '') ?: ('LAP-' . $payment_id);
$agentLabel = ($payment['agent_name'] ?? '') . (!empty($payment['agent_code']) ? ' (' . $payment['agent_code'] . ')' : '');

$totWeight = 0.0; $totNet = 0.0; $totTax = 0.0; $totMiscDed = 0.0; $totGross = 0.0;
$hasMiscDed = false;
foreach ($trips as $tRow) {
    $w = (float)($tRow['total_weight'] ?? 0);
    $is_mgmt_fee = (($tRow['lease_agent_billing_model'] ?? 'margin_deduction') === 'mgmt_fee');
    $net = $is_mgmt_fee
        ? (float)($tRow['lease_agent_amount'] ?? 0)
        : (float)($tRow['net_freight_amount'] ?? ((float)($tRow['freight_amount'] ?? 0) - (float)($tRow['lease_agent_amount'] ?? 0)));
    $misc_ded = (float)($tRow['lease_agent_misc_deduction'] ?? 0);
    if ($misc_ded > 0) $hasMiscDed = true;
    $tTaxRate = (float)($tRow['trip_tax_rate'] ?? $taxRate);
    $tax = $net * ($tTaxRate / 100);
    $gross = $net + $tax - $misc_ded;
    $totWeight += $w; $totNet += $net; $totTax += $tax; $totMiscDed += $misc_ded; $totGross += $gross;
}
$totGross = round($totGross, 2);
$rowPaid = (float)($payment['amount'] ?? 0);
$rowBalance = (float)($payment['balance_amount'] ?? max(0, $totGross - $rowPaid));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t['doc_title']) ?> - <?= htmlspecialchars($paymentNo) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, 'Noto Sans Devanagari', 'Mangal', sans-serif; font-size: 11px; color: #111; background: #eef2f1; }
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
    padding: 10px 16px;
    flex-wrap: wrap;
}
.print-bar button {
    border: none;
    border-radius: 6px;
    background: #fff;
    color: #0f766e;
    padding: 8px 16px;
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
    padding: 4px 10px;
    border-radius: 6px;
}
.lang-switch span { font-size: 12px; font-weight: 600; }
.lang-btn {
    color: #fff;
    text-decoration: none;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    transition: background .15s;
}
.lang-btn:hover { background: rgba(255,255,255,.2); color: #fff; }
.lang-btn.active { background: #fff; color: #0f766e; font-weight: 700; }
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 16px auto 24px;
    background: #fff;
    padding: 10mm;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.header {
    border: 2px solid #0f766e;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    gap: 16px;
}
.company-name { font-size: 22px; font-weight: 700; color: #0f766e; }
.company-sub { margin-top: 4px; color: #475569; line-height: 1.45; font-size: 11.5px; }
.title-box {
    min-width: 250px;
    text-align: right;
}
.title-box h1 {
    font-size: 22px;
    font-weight: 700;
    color: #0f766e;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.meta-grid, .summary-grid {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.meta-grid td, .summary-grid td {
    border: 1px solid #cfd8d4;
    padding: 7px 8px;
    vertical-align: top;
}
.meta-label {
    width: 18%;
    background: #f0fdf4;
    color: #0f766e;
    font-weight: 700;
}
.section-title {
    margin-top: 14px;
    background: #0f766e;
    color: #fff;
    padding: 8px 12px;
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}
table.items {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
}
table.items th, table.items td {
    border: 1px solid #d6dfdb;
    padding: 6px 7px;
}
table.items thead th {
    background: #e6f4f1;
    color: #0f766e;
    font-size: 10.5px;
    text-align: left;
}
table.items td.r, table.items th.r { text-align: right; }
table.items td.c, table.items th.c { text-align: center; }
table.items tfoot td {
    background: #f0fdf4;
    font-weight: 700;
}
.footer-note {
    margin-top: 14px;
    border-top: 1px solid #d6dfdb;
    padding-top: 8px;
    color: #64748b;
    font-size: 10px;
}
.sign-row {
    margin-top: 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}
.sign-box {
    width: 200px;
    text-align: center;
}
.sign-image {
    max-height: 52px;
    max-width: 150px;
    object-fit: contain;
    display: block;
    margin: 0 auto 6px;
}
.sign-line {
    border-top: 1px solid #6b7280;
    padding-top: 6px;
    font-weight: 700;
    color: #0f766e;
}
@media print {
    body { background: #fff; }
    .print-bar { display: none !important; }
    .page {
        margin: 0;
        box-shadow: none;
        width: 210mm;
        min-height: auto;
    }
}
</style>
</head>
<body>
<div class="print-bar">
    <button type="button" onclick="window.print()"><?= htmlspecialchars($t['btn_print']) ?></button>
    <div class="lang-switch">
        <span><?= htmlspecialchars($t['lang_label']) ?></span>
        <a href="?id=<?= $payment_id ?>&lang=en" class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>">English</a>
        <a href="?id=<?= $payment_id ?>&lang=hi" class="lang-btn <?= $lang === 'hi' ? 'active' : '' ?>">हिंदी (Hindi)</a>
    </div>
    <button type="button" onclick="window.close()"><?= htmlspecialchars($t['btn_close']) ?></button>
</div>

<div class="page">
    <div class="header">
        <div>
            <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
            <div class="company-sub">
                <?= htmlspecialchars($company['address'] ?? '') ?><?= !empty($company['city']) ? ', ' . htmlspecialchars($company['city']) : '' ?><?= !empty($company['state']) ? ', ' . htmlspecialchars($company['state']) : '' ?><br>
                GSTIN: <?= htmlspecialchars($company['gstin'] ?? '-') ?> | Ph: <?= htmlspecialchars($company['phone'] ?? '-') ?><br>
                Email: <?= htmlspecialchars($company['email'] ?? '-') ?>
            </div>
        </div>
        <div class="title-box">
            <h1><?= htmlspecialchars($t['title']) ?></h1>
            <div><strong><?= htmlspecialchars($t['advice_no']) ?></strong> <?= htmlspecialchars($paymentNo) ?></div>
            <div><strong><?= htmlspecialchars($t['date']) ?></strong> <?= !empty($payment['payment_date']) ? date('d/m/Y', strtotime($payment['payment_date'])) : '—' ?></div>
            <div><strong><?= htmlspecialchars($t['status']) ?>:</strong> <span style="color:#0f766e"><?= htmlspecialchars(($payment['status'] ?? '') ?: 'Paid') ?></span></div>
        </div>
    </div>

    <table class="meta-grid">
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['lease_agent']) ?></td>
            <td><strong><?= htmlspecialchars($agentLabel) ?></strong></td>
            <td class="meta-label"><?= htmlspecialchars($t['bill_to']) ?></td>
            <td><strong><?= htmlspecialchars(($payment['company_name'] ?? '') ?: 'General') ?></strong></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['period']) ?></td>
            <td><?= !empty($payment['date_from']) ? date('d/m/Y', strtotime($payment['date_from'])) : '—' ?> to <?= !empty($payment['date_to']) ? date('d/m/Y', strtotime($payment['date_to'])) : '—' ?></td>
            <td class="meta-label"><?= htmlspecialchars($t['payment_mode']) ?></td>
            <td><?= htmlspecialchars(($payment['payment_mode'] ?? '') ?: '—') ?></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['reference_no']) ?></td>
            <td><?= htmlspecialchars(($payment['reference_no'] ?? '') ?: '—') ?></td>
            <td class="meta-label"><?= htmlspecialchars($t['utr_no']) ?></td>
            <td><?= htmlspecialchars(($payment['utr_no'] ?? '') ?: '—') ?></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['bank_name']) ?></td>
            <td><?= htmlspecialchars(($payment['bank_name'] ?? '') ?: '—') ?></td>
            <td class="meta-label"><?= htmlspecialchars($t['agent_inv']) ?></td>
            <td><?= htmlspecialchars(($payment['agent_invoice_no'] ?? '') ?: '—') ?></td>
        </tr>
        <?php if (!empty($payment['notes'])): ?>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['notes']) ?></td>
            <td colspan="3"><?= nl2br(htmlspecialchars($payment['notes'])) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <div class="section-title"><?= htmlspecialchars($t['section_title']) ?> (<?= count($trips) ?>)</div>
    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width:4%"><?= htmlspecialchars($t['col_sno']) ?></th>
                <th style="width:12%"><?= htmlspecialchars($t['col_trip']) ?></th>
                <th style="width:12%"><?= htmlspecialchars($t['col_inv']) ?></th>
                <th style="width:9%"><?= htmlspecialchars($t['col_date']) ?></th>
                <th style="width:10%"><?= htmlspecialchars($t['col_vehicle']) ?></th>
                <th><?= htmlspecialchars($t['col_vendor']) ?></th>
                <th class="r" style="width:9%"><?= htmlspecialchars($t['col_weight']) ?></th>
                <th class="r" style="width:9%"><?= htmlspecialchars($t['col_net']) ?></th>
                <th class="r" style="width:9%"><?= htmlspecialchars($t['col_tax']) ?></th>
                <?php if ($hasMiscDed): ?>
                <th class="r" style="width:9%"><?= htmlspecialchars($t['col_misc_ded']) ?></th>
                <?php endif; ?>
                <th class="r" style="width:11%"><?= htmlspecialchars($t['col_gross']) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($trips as $idx => $tr): ?>
            <?php
                $w = (float)($tr['total_weight'] ?? 0);
                $is_mgmt_fee = (($tr['lease_agent_billing_model'] ?? 'margin_deduction') === 'mgmt_fee');
                $net = $is_mgmt_fee
                    ? (float)($tr['lease_agent_amount'] ?? 0)
                    : (float)($tr['net_freight_amount'] ?? ((float)($tr['freight_amount'] ?? 0) - (float)($tr['lease_agent_amount'] ?? 0)));
                $misc_ded = (float)($tr['lease_agent_misc_deduction'] ?? 0);
                $tripTaxRate = (float)($tr['trip_tax_rate'] ?? $taxRate);
                $tax = $net * ($tripTaxRate / 100);
                $gross = $net + $tax - $misc_ded;
            ?>
            <tr>
                <td class="c"><?= $idx + 1 ?></td>
                <td><strong><?= htmlspecialchars($tr['trip_no']) ?></strong></td>
                <td><?= htmlspecialchars($tr['lease_agent_invoice_no'] ?: '—') ?></td>
                <td><?= !empty($tr['trip_date']) ? date('d/m/Y', strtotime($tr['trip_date'])) : '—' ?></td>
                <td><?= htmlspecialchars($tr['reg_no'] ?: '—') ?></td>
                <td><?= htmlspecialchars($tr['vendor_name'] ?: '—') ?></td>
                <td class="r"><?= number_format($w, 3) ?></td>
                <td class="r">&#8377;<?= number_format($net, 2) ?></td>
                <td class="r">&#8377;<?= number_format($tax, 2) ?></td>
                <?php if ($hasMiscDed): ?>
                <td class="r" style="color:<?= $misc_ded > 0 ? '#c00' : 'inherit' ?>">
                    <?= $misc_ded > 0 ? ('-₹' . number_format($misc_ded, 2)) : '—' ?>
                    <?php if ($misc_ded > 0 && !empty($tr['lease_agent_misc_deduction_remarks'])): ?>
                        <div style="font-size:8.5px;color:#64748b;font-weight:normal;line-height:1.2;margin-top:2px"><?= htmlspecialchars(trim((string)$tr['lease_agent_misc_deduction_remarks'])) ?></div>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="r"><strong>&#8377;<?= number_format($gross, 2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="r"><?= htmlspecialchars($t['totals']) ?></td>
                <td class="r"><?= number_format($totWeight, 3) ?></td>
                <td class="r">&#8377;<?= number_format($totNet, 2) ?></td>
                <td class="r">&#8377;<?= number_format($totTax, 2) ?></td>
                <?php if ($hasMiscDed): ?>
                <td class="r" style="color:#c00"><?= $totMiscDed > 0 ? ('-₹' . number_format($totMiscDed, 2)) : '—' ?></td>
                <?php endif; ?>
                <td class="r">&#8377;<?= number_format($totGross, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <table class="summary-grid">
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['total_payable']) ?></td>
            <td><strong>&#8377;<?= number_format($totGross, 2) ?></strong></td>
            <td class="meta-label"><?= htmlspecialchars($t['paid_amount']) ?></td>
            <td><strong style="color:#0f766e">&#8377;<?= number_format($rowPaid, 2) ?></strong></td>
            <td class="meta-label"><?= htmlspecialchars($t['balance_due']) ?></td>
            <td><strong style="color:<?= $rowBalance > 0 ? '#dc3545' : '#0f766e' ?>"><?= $rowBalance < 0 ? '(Credit) ' : '' ?>&#8377;<?= number_format(abs($rowBalance), 2) ?></strong></td>
        </tr>
    </table>

    <div class="sign-row">
        <div class="sign-box">
            <div class="sign-line"><?= htmlspecialchars($t['prepared_by']) ?></div>
            <div style="margin-top:4px;color:#64748b"><?= htmlspecialchars($prepared_by_name) ?></div>
        </div>
        <div class="sign-box">
            <div class="sign-line"><?= htmlspecialchars($t['receiver_sig']) ?></div>
        </div>
        <div class="sign-box">
            <?php if ($authorised_sig !== ''): ?>
            <img src="<?= htmlspecialchars($authorised_sig) ?>" alt="Authorised Signature" class="sign-image">
            <?php else: ?>
            <div style="height:58px"></div>
            <?php endif; ?>
            <div class="sign-line"><?= htmlspecialchars($t['authorised_by']) ?></div>
        </div>
    </div>

    <div class="footer-note">
        <?= htmlspecialchars($t['footer_note']) ?><?= htmlspecialchars($paymentNo) ?>.
    </div>
</div>
</body>
</html>
