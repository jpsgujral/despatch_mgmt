<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
requirePerm('transporter_bulk_payments', 'view');

function safeAddColumnBulkAdvice(mysqli $db, string $tbl, string $col, string $def): void {
    $r = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetch_row();
    if (empty($r[0])) {
        $db->query("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
    }
}
safeAddColumnBulkAdvice($db, 'transporter_payments', 'misc_charges', 'DECIMAL(10,2) DEFAULT 0');
safeAddColumnBulkAdvice($db, 'despatch_orders', 'transporter_misc_charges', 'DECIMAL(10,2) DEFAULT 0');
safeAddColumnBulkAdvice($db, 'despatch_orders', 'transporter_misc_remarks', 'VARCHAR(255) DEFAULT \'\'');

$uid = (int)($_SESSION['user_id'] ?? 0);
$can_view_all = canViewAll('transporter_payments');
$payment_scope = $can_view_all ? "1=1" : "tp.created_by=$uid";

$batch_no = trim((string)($_GET['batch'] ?? ''));
if ($batch_no === '') die('Invalid batch.');

$lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
if ($lang !== 'hi') $lang = 'en';

$dict = [
    'en' => [
        'doc_title'     => 'Payment Advice',
        'title'         => 'Payment Advice',
        'advice_no'     => 'Advice No:',
        'batch_no'      => 'Batch No:',
        'date'          => 'Date:',
        'transporter'   => 'Transporter',
        'payment_mode'  => 'Payment Mode',
        'reference_no'  => 'Reference No / UTR',
        'bank_name'     => 'Bank Name',
        'gst_setup'     => 'GST Setup',
        'rows'          => 'Rows',
        'remarks'       => 'Remarks',
        'section_title' => 'Challan-wise Advice',
        'col_num'       => '#',
        'col_pay_no'    => 'Payment No',
        'col_challan'   => 'Challan',
        'col_vendor'    => 'Vendor',
        'col_freight'   => 'Freight Amount',
        'col_gst'       => 'GST',
        'col_tds'       => 'TDS',
        'col_net'       => 'Net Payable',
        'col_misc'      => 'Misc (No GST)',
        'col_status'    => 'Status',
        'totals'        => 'Totals',
        'tot_taxable'   => 'Taxable Freight',
        'tot_misc'      => 'Misc Expenses (Weigh Bridge / Labour - No GST)',
        'grand_total'   => 'Grand Total (Net Payable)',
        'prepared_on'   => 'Prepared On',
        'prepared_by'   => 'Prepared By',
        'accounts_dept' => 'Accounts Dept',
        'authorised_by' => 'Authorised By',
        'footer_note'   => 'This is a system-generated payment advice for bulk transporter payment batch ',
        'gst_on_hold'   => 'GST on Hold',
        'challans'      => 'challan(s)',
        'btn_print'     => 'Print Advice',
        'btn_close'     => 'Close',
        'lang_label'    => 'Output Language:',
    ],
    'hi' => [
        'doc_title'     => 'भुगतान सूचना (Payment Advice)',
        'title'         => 'भुगतान सूचना',
        'advice_no'     => 'सूचना क्रमांक:',
        'batch_no'      => 'बैच क्रमांक:',
        'date'          => 'दिनांक:',
        'transporter'   => 'परिवहनकर्ता (Transporter)',
        'payment_mode'  => 'भुगतान का प्रकार',
        'reference_no'  => 'संदर्भ संख्या (Ref / UTR)',
        'bank_name'     => 'बैंक का नाम',
        'gst_setup'     => 'जीएसटी विवरण',
        'rows'          => 'कुल चालान',
        'remarks'       => 'विवरण / टिप्पणी',
        'section_title' => 'चालान अनुसार भुगतान विवरण',
        'col_num'       => 'क्र.',
        'col_pay_no'    => 'भुगतान सं.',
        'col_challan'   => 'चालान सं.',
        'col_vendor'    => 'विक्रेता (Vendor)',
        'col_freight'   => 'भाड़ा राशि (Freight)',
        'col_gst'       => 'जीएसटी (GST)',
        'col_tds'       => 'टीडीएस (TDS)',
        'col_net'       => 'शुद्ध देय राशि (Net Payable)',
        'col_misc'      => 'अन्य व्यय (बिना GST)',
        'col_status'    => 'स्थिति',
        'totals'        => 'कुल योग',
        'tot_taxable'   => 'कर योग्य भाड़ा (Taxable Freight)',
        'tot_misc'      => 'अन्य व्यय (कांटा / लेबर - बिना GST)',
        'grand_total'   => 'कुल देय राशि (Grand Total)',
        'prepared_on'   => 'तैयार किया गया',
        'prepared_by'   => 'द्वारा तैयार',
        'accounts_dept' => 'लेखा विभाग (Accounts)',
        'authorised_by' => 'अधिकृतकर्ता (Authorised By)',
        'footer_note'   => 'यह बल्क ट्रांसपोर्टर भुगतान बैच के लिए एक सिस्टम-जनरेटेड भुगतान सूचना है: ',
        'gst_on_hold'   => 'जीएसटी रोका गया (On Hold)',
        'challans'      => 'चालान',
        'btn_print'     => 'प्रिंट सूचना (Print)',
        'btn_close'     => 'बंद करें (Close)',
        'lang_label'    => 'आउटपुट भाषा (Language):',
    ]
];
$t = $dict[$lang];

$batch_sql = $db->real_escape_string($batch_no);
$rows = $db->query("
    SELECT tp.payment_batch_no, tp.payment_no, tp.payment_date, tp.payment_mode, tp.reference_no,
           tp.bank_name, tp.remarks, tp.status, tp.base_amount, tp.gst_amount, tp.gst_held,
           tp.tds_amount, tp.net_payable, tp.created_at,
           tp.misc_charges AS tp_misc_charges,
           GREATEST(COALESCE(d.transporter_misc_charges, 0), COALESCE(tp.misc_charges, 0)) AS misc_charges,
           COALESCE(NULLIF(d.transporter_misc_remarks, ''), '') AS misc_remarks,
           t.transporter_name, t.gst_type AS transporter_gst_type, t.gst_rate AS transporter_gst_rate,
           d.challan_no, d.despatch_date, COALESCE(NULLIF(v.vendor_name,''), d.consignee_name) AS vendor_name
    FROM transporter_payments tp
    LEFT JOIN transporters t ON tp.transporter_id = t.id
    LEFT JOIN despatch_orders d ON d.id = tp.despatch_id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    WHERE tp.payment_batch_no = '$batch_sql' AND $payment_scope
    ORDER BY tp.id ASC
")->fetch_all(MYSQLI_ASSOC);

if (empty($rows)) die('Payment batch not found.');

function companyDocImageUrl(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('#^https?://#i', $value)) return $value;
    if (strpos($value, 'uploads/') === 0) return '../' . ltrim($value, '/');
    return function_exists('r2_url') ? (string)r2_url($value) : '';
}

function bulkAdviceBaseAmount(array $row): float {
    $base = (float)($row['base_amount'] ?? 0);
    $gst = (float)($row['gst_amount'] ?? 0);
    $tds = (float)($row['tds_amount'] ?? 0);
    if (abs($base) < 0.005 && abs($gst) < 0.005 && abs($tds) < 0.005 && isset($row['amount'])) {
        return (float)$row['amount'];
    }
    return $base;
}

function bulkAdviceFreightAmount(array $row): float {
    return round(bulkAdviceBaseAmount($row) + (float)($row['gst_amount'] ?? 0), 2);
}

function bulkAdviceNetPayable(array $row): float {
    $net = (float)($row['net_payable'] ?? 0);
    $misc = (float)($row['misc_charges'] ?? 0);
    $tp_misc = (float)($row['tp_misc_charges'] ?? 0);
    if ($misc > 0 && abs($tp_misc) < 0.005) {
        $base = bulkAdviceBaseAmount($row);
        $gst = (($row['gst_held'] ?? 'No') === 'Yes') ? 0 : (float)($row['gst_amount'] ?? 0);
        $tds = (float)($row['tds_amount'] ?? 0);
        return round($base + $gst - $tds + $misc, 2);
    }
    if (abs($net) < 0.005 && isset($row['amount'])) {
        return (float)$row['amount'];
    }
    return $net;
}

$company = $db->query("SELECT company_name, address, city, state, gstin, phone, email
    FROM company_settings LIMIT 1")->fetch_assoc() ?: [];
$company_name = $company['company_name'] ?? 'Despatch Management System';
$logged_in_user_id = (int)($_SESSION['user_id'] ?? 0);
$logged_in_user = $logged_in_user_id > 0
    ? ($db->query("SELECT full_name, username, signature_path FROM app_users WHERE id=$logged_in_user_id LIMIT 1")->fetch_assoc() ?: [])
    : [];
$authorised_by_name = trim((string)($logged_in_user['full_name'] ?? ''));
if ($authorised_by_name === '') {
    $authorised_by_name = trim((string)($logged_in_user['username'] ?? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')));
}
$authorised_sig = companyDocImageUrl((string)($logged_in_user['signature_path'] ?? ''));
$advice_no = preg_replace('/^TPB\//', 'TPA/', $batch_no);
$payment_date = $rows[0]['payment_date'] ?? '';
$payment_mode = $rows[0]['payment_mode'] ?? '';
$reference_no = $rows[0]['reference_no'] ?? '';
$bank_name = $rows[0]['bank_name'] ?? '';
$remarks = $rows[0]['remarks'] ?? '';
$transporter_name = $rows[0]['transporter_name'] ?? 'Unknown Transporter';
$transporter_gst_type = $rows[0]['transporter_gst_type'] ?? '';
$transporter_gst_rate = (float)($rows[0]['transporter_gst_rate'] ?? 0);

$tot_base    = array_sum(array_map(fn($r) => bulkAdviceBaseAmount($r), $rows));
$tot_freight = array_sum(array_map(fn($r) => bulkAdviceFreightAmount($r), $rows));
$tot_gst     = array_sum(array_map(fn($r) => (float)($r['gst_amount'] ?? 0), $rows));
$tot_tds     = array_sum(array_map(fn($r) => (float)($r['tds_amount'] ?? 0), $rows));
$tot_misc    = array_sum(array_map(fn($r) => (float)($r['misc_charges'] ?? 0), $rows));
$tot_net     = array_sum(array_map(fn($r) => bulkAdviceNetPayable($r), $rows));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t['doc_title']) ?> - <?= htmlspecialchars($advice_no) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, 'Noto Sans Devanagari', 'Mangal', sans-serif; font-size: 11px; color: #111; background: #eef2f1; }
.print-bar {
    position: sticky;
    top: 0;
    z-index: 20;
    background: #14532d;
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
    color: #14532d;
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
    background: rgba(255,255,255,.15);
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
.lang-btn.active { background: #fff; color: #14532d; font-weight: 700; }
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 16px auto 24px;
    background: #fff;
    padding: 10mm;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.header {
    border: 2px solid #14532d;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    gap: 16px;
}
.company-name { font-size: 18px; font-weight: 700; color: #14532d; }
.company-sub { margin-top: 4px; color: #475569; line-height: 1.45; }
.title-box {
    min-width: 220px;
    text-align: right;
}
.title-box h1 {
    font-size: 20px;
    color: #14532d;
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
    background: #f3f8f5;
    color: #14532d;
    font-weight: 700;
}
.section-title {
    margin-top: 14px;
    background: #14532d;
    color: #fff;
    padding: 7px 10px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
}
table.items {
    width: 100%;
    border-collapse: collapse;
}
table.items th, table.items td {
    border: 1px solid #d6dfdb;
    padding: 7px 8px;
}
table.items thead th {
    background: #eef6f1;
    color: #14532d;
    font-size: 11px;
    text-align: left;
}
table.items td.r, table.items th.r { text-align: right; }
table.items tfoot td {
    background: #f3f8f5;
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
    margin-top: 18px;
    display: flex;
    justify-content: flex-end;
}
.sign-box {
    width: 220px;
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
    color: #14532d;
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
        <a href="?batch=<?= urlencode($batch_no) ?>&lang=en" class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>">English</a>
        <a href="?batch=<?= urlencode($batch_no) ?>&lang=hi" class="lang-btn <?= $lang === 'hi' ? 'active' : '' ?>">हिंदी (Hindi)</a>
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
            <div><strong><?= htmlspecialchars($t['advice_no']) ?></strong> <?= htmlspecialchars($advice_no) ?></div>
            <div><strong><?= htmlspecialchars($t['batch_no']) ?></strong> <?= htmlspecialchars($batch_no) ?></div>
            <div><strong><?= htmlspecialchars($t['date']) ?></strong> <?= $payment_date ? date('d/m/Y', strtotime($payment_date)) : '—' ?></div>
        </div>
    </div>

    <table class="meta-grid">
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['transporter']) ?></td>
            <td><?= htmlspecialchars($transporter_name) ?></td>
            <td class="meta-label"><?= htmlspecialchars($t['payment_mode']) ?></td>
            <td><?= htmlspecialchars($payment_mode ?: '—') ?></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['reference_no']) ?></td>
            <td><?= htmlspecialchars($reference_no ?: '—') ?></td>
            <td class="meta-label"><?= htmlspecialchars($t['bank_name']) ?></td>
            <td><?= htmlspecialchars($bank_name ?: '—') ?></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['gst_setup']) ?></td>
            <td><?= htmlspecialchars($transporter_gst_type ?: '—') ?><?= $transporter_gst_rate > 0 ? ' @ ' . number_format($transporter_gst_rate, 2) . '%' : '' ?></td>
            <td class="meta-label"><?= htmlspecialchars($t['rows']) ?></td>
            <td><?= number_format(count($rows)) ?> <?= htmlspecialchars($t['challans']) ?></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['remarks']) ?></td>
            <td colspan="3"><?= nl2br(htmlspecialchars($remarks ?: '—')) ?></td>
        </tr>
    </table>

    <div class="section-title"><?= htmlspecialchars($t['section_title']) ?></div>
    <table class="items">
        <thead>
            <tr>
                <th style="width:4%"><?= htmlspecialchars($t['col_num']) ?></th>
                <th style="width:13%"><?= htmlspecialchars($t['col_pay_no']) ?></th>
                <th style="width:14%"><?= htmlspecialchars($t['col_challan']) ?></th>
                <th><?= htmlspecialchars($t['col_vendor']) ?></th>
                <th class="r" style="width:12%"><?= htmlspecialchars($t['col_freight']) ?></th>
                <th class="r" style="width:9%"><?= htmlspecialchars($t['col_gst']) ?></th>
                <th class="r" style="width:9%"><?= htmlspecialchars($t['col_tds']) ?></th>
                <th class="r" style="width:11%"><?= htmlspecialchars($t['col_misc']) ?></th>
                <th class="r" style="width:12%"><?= htmlspecialchars($t['col_net']) ?></th>
                <th style="width:7%"><?= htmlspecialchars($t['col_status']) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $idx => $row): ?>
            <tr>
                <td><?= $idx + 1 ?></td>
                <td><?= htmlspecialchars($row['payment_no']) ?></td>
                <td>
                    <strong><?= htmlspecialchars($row['challan_no'] ?: '—') ?></strong>
                    <?php if (!empty($row['despatch_date'])): ?><div style="color:#64748b"><?= date('d/m/Y', strtotime($row['despatch_date'])) ?></div><?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($row['vendor_name'] ?: '—') ?>
                </td>
                <td class="r">₹<?= number_format(bulkAdviceFreightAmount($row), 2) ?></td>
                <td class="r">₹<?= number_format((float)$row['gst_amount'], 2) ?><?php if (($row['gst_held'] ?? 'No') === 'Yes'): ?><div style="color:#b45309;font-size:10px"><?= htmlspecialchars($t['gst_on_hold']) ?></div><?php endif; ?></td>
                <td class="r">₹<?= number_format((float)$row['tds_amount'], 2) ?></td>
                <td class="r">
                    ₹<?= number_format((float)($row['misc_charges'] ?? 0), 2) ?>
                    <?php if (!empty($row['misc_remarks'])): ?>
                        <div style="color:#64748b;font-size:9.5px"><?= htmlspecialchars($row['misc_remarks']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="r"><strong>₹<?= number_format(bulkAdviceNetPayable($row), 2) ?></strong></td>
                <td><?= htmlspecialchars($row['status'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="r"><?= htmlspecialchars($t['totals']) ?></td>
                <td class="r">₹<?= number_format($tot_freight, 2) ?></td>
                <td class="r">₹<?= number_format($tot_gst, 2) ?></td>
                <td class="r">₹<?= number_format($tot_tds, 2) ?></td>
                <td class="r">₹<?= number_format($tot_misc, 2) ?></td>
                <td class="r">₹<?= number_format($tot_net, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <table class="summary-grid">
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['tot_taxable'] ?? 'Taxable Freight') ?></td>
            <td><strong>₹<?= number_format($tot_base, 2) ?></strong></td>
            <td class="meta-label"><?= htmlspecialchars($t['col_gst']) ?></td>
            <td><strong>₹<?= number_format($tot_gst, 2) ?></strong></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['col_tds']) ?></td>
            <td><strong>₹<?= number_format($tot_tds, 2) ?></strong></td>
            <td class="meta-label" style="background:#fef3c7;color:#92400e;font-weight:700"><?= htmlspecialchars($t['tot_misc'] ?? 'Misc Expenses (No GST)') ?></td>
            <td style="background:#fef3c7"><strong style="color:#92400e">₹<?= number_format($tot_misc, 2) ?></strong></td>
        </tr>
        <tr>
            <td class="meta-label" style="background:#eef6f1;font-size:12px"><?= htmlspecialchars($t['grand_total'] ?? 'Grand Total') ?></td>
            <td colspan="3" style="background:#eef6f1;font-size:13px"><strong style="color:#14532d">₹<?= number_format($tot_net, 2) ?></strong></td>
        </tr>
        <tr>
            <td class="meta-label"><?= htmlspecialchars($t['prepared_on']) ?></td>
            <td><?= date('d/m/Y h:i A') ?></td>
            <td class="meta-label"><?= htmlspecialchars($t['prepared_by']) ?></td>
            <td><?= htmlspecialchars($t['accounts_dept']) ?></td>
        </tr>
    </table>

    <div class="sign-row">
        <div class="sign-box">
            <?php if ($authorised_sig !== ''): ?>
            <img src="<?= htmlspecialchars($authorised_sig) ?>" alt="Authorised Signature" class="sign-image">
            <?php else: ?>
            <div style="height:58px"></div>
            <?php endif; ?>
            <div class="sign-line"><?= htmlspecialchars($t['authorised_by']) ?></div>
            <div style="margin-top:4px;color:#64748b"><?= htmlspecialchars($authorised_by_name) ?></div>
        </div>
    </div>

    <div class="footer-note">
        <?= htmlspecialchars($t['footer_note']) ?><?= htmlspecialchars($batch_no) ?>.
    </div>
</div>
</body>
</html>
