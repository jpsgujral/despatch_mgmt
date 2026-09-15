<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (file_exists('../includes/r2_helper.php')) require_once '../includes/r2_helper.php';
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid trip ID.');

/* ── Fix double-encoded HTML entities from sanitize() ── */
function esc($val) {
    if ($val === null || $val === '') return '';
    return htmlspecialchars(html_entity_decode((string)$val, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

$t = $db->query("SELECT t.*, v.reg_no, v.make, v.model, v.chassis_no, v.engine_no, v.lease_agent_id AS vehicle_lease_agent_id,
    d.full_name AS driver_name, d.license_no AS driver_license, d.phone AS driver_phone,
    s.full_name AS supervisor_name,
    p.po_number, vn.vendor_name,
    COALESCE(NULLIF(TRIM(la.agent_short_name),''), NULLIF(TRIM(t.lease_agent_name),''), NULLIF(TRIM(la.agent_name),''), '') AS lease_agent_short_name,
    COALESCE(la.agent_name, t.lease_agent_name, '') AS lease_agent_full_name,
    COALESCE(btco.company_name, co.company_name, '') AS lease_bill_to_company_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v  ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d   ON t.driver_id=d.id
    LEFT JOIN fleet_drivers s   ON t.supervisor_id=s.id
    LEFT JOIN fleet_purchase_orders p ON t.po_id=p.id
    LEFT JOIN fleet_customers_master vn ON t.vendor_id=vn.id
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
    LEFT JOIN companies co ON co.id=t.company_id
    LEFT JOIN companies btco ON btco.id=t.lease_agent_bill_to_company_id
    WHERE t.id=$id LIMIT 1")->fetch_assoc();
if (!$t) die('Trip not found.');

$trip_items = $db->query("SELECT * FROM fleet_trip_items WHERE trip_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$company    = getCompany((int)($t['company_id'] ?? 0));
$challan_logo_rel = '../assets/icons/icon-192x192.png';
$challan_logo_abs = dirname(__DIR__) . '/assets/icons/icon-192x192.png';
$challan_logo_url = file_exists($challan_logo_abs) ? $challan_logo_rel : '';

$auth_sig_path = '';
$auth_sig_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$auth_uid = (int)($_SESSION['user_id'] ?? 0);
if ($auth_uid > 0) {
    $auth_row = $db->query("SELECT full_name, username, signature_path FROM app_users WHERE id=$auth_uid LIMIT 1")->fetch_assoc();
    if ($auth_row) {
        $auth_sig_path = (string)($auth_row['signature_path'] ?? '');
        $auth_sig_name = trim((string)($auth_row['full_name'] ?? ''));
        if ($auth_sig_name === '') $auth_sig_name = trim((string)($auth_row['username'] ?? ''));
    }
}
if ($auth_sig_name === '') $auth_sig_name = 'Authorised User';

function fleetImgUrl(string $path): string {
    if (empty($path)) return '';
    if (strpos($path, 'uploads/') === 0) {
        $rel = substr($path, strlen('uploads/'));
        return '../modules/img.php?f=' . urlencode($rel);
    }
    if (function_exists('r2_url')) return r2_url($path);
    return '../uploads/' . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery Challan — <?= esc($t['trip_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: Arial, sans-serif;
    font-size: 9.5pt;
    color: #222;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}
:root {
    --line-strong: 2.5px solid #1a5632;
    --line-mid: 1.8px solid #1a5632;
    --line-soft: 1.4px solid #7d9f8a;
    --line-soft-dark: 1.4px solid #8e989d;
}

@page {
    size: A4 portrait;
    margin: 0;
}

@media screen {
    body { background: #6b7280; }
    .print-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 20px 0 40px;
        gap: 16px;
    }
    .page {
        width: 210mm;
        box-shadow: 0 4px 28px rgba(0,0,0,.45);
        background: #fff;
        padding: 6mm 10mm;
        box-sizing: border-box;
    }
}

.page {
    width: 210mm;
    box-sizing: border-box;
    padding: 6mm 10mm;
    background: #fff;
    position: relative;
}

.header-top {
    background: #1a5632;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 12px;
    border: var(--line-strong);
}
.header-company {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    min-width: 0;
    flex: 1;
}
.header-logo {
    width: 46px;
    height: 46px;
    object-fit: contain;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.22);
    border-radius: 8px;
    padding: 3px;
    flex-shrink: 0;
}
.company-name {
    font-size: 14pt;
    font-weight: bold;
    letter-spacing: 0;
    line-height: 1.1;
    white-space: nowrap;
}
.company-sub  { font-size: 7.5pt; opacity: 0.85; margin-top: 2px; line-height: 1.35; }
.challan-ref  { text-align: right; }
.challan-ref h2 { font-size: 13.5pt; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; }
.challan-ref p  { font-size: 7.5pt; opacity: 0.92; margin-top: 2px; line-height: 1.3; }
.challan-ref .doc-meta-row {
    display: block;
    font-size: 9.5pt;
    font-weight: bold;
    color: #fff;
}
.challan-ref .doc-meta-label,
.challan-ref .doc-meta-value {
    color: #fff;
    font-weight: bold;
}

.header-top, .copy-banner, .section-title,
table.info td.lbl, table.items th, table.items tfoot td,
table.items tr:nth-child(even) td, .challan-footer,
.sign-section, .sign-box {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}

.copy-banner {
    background: #f0f8f3;
    border: var(--line-mid);
    border-top: none;
    border-bottom: var(--line-strong);
    text-align: center;
    padding: 3px;
    font-size: 8.5pt;
    font-weight: bold;
    color: #1a5632;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.section-title {
    font-size: 7pt;
    font-weight: bold;
    text-transform: uppercase;
    color: #1a5632;
    background: #e5f5eb;
    padding: 2px 8px;
    letter-spacing: 0.3px;
    border: var(--line-mid);
    border-bottom: none;
    margin-top: 4px;
}

table.info {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
    border: var(--line-mid);
    border-top: none;
}
table.info td {
    padding: 3px 7px;
    border: var(--line-soft);
    vertical-align: top;
    line-height: 1.35;
}
table.info td.lbl {
    background: #e5f5eb;
    font-weight: bold;
    width: 18%;
    color: #1a5632;
    font-size: 8pt;
    white-space: nowrap;
}

.items-wrap {
    border: var(--line-mid);
    border-top: none;
}
table.items {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
}
table.items th {
    background: #1a5632;
    color: #fff;
    padding: 3.5px 5px;
    text-align: center;
    font-size: 7.5pt;
    font-weight: bold;
    letter-spacing: 0.3px;
}
table.items th.r, table.items td.r { text-align: right; }
table.items th.l, table.items td.l { text-align: left; }
table.items td {
    padding: 3px 5px;
    border-bottom: var(--line-soft);
    vertical-align: middle;
}
table.items tr:nth-child(even) td { background: #f9f9f9; }
table.items tfoot td {
    background: #f0f8f3;
    font-weight: bold;
    border-top: var(--line-strong);
    padding: 3.5px 5px;
}

.sign-section {
    border: var(--line-mid);
    border-top: none;
    display: flex;
}
.sign-box {
    flex: 1;
    padding: 5px 8px;
    border-right: var(--line-mid);
    text-align: center;
    font-size: 7.5pt;
    min-height: 60px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
}
.sign-box:last-child { border-right: none; }
.sign-box .sig-title {
    font-size: 7.5pt;
    color: #333;
    font-weight: bold;
    border-top: var(--line-soft-dark);
    padding-top: 3px;
    width: 100%;
}
.sign-box small { font-weight: normal; color: #555; display: block; margin-top: 1px; }

.challan-footer {
    border: var(--line-mid);
    border-top: none;
    text-align: center;
    padding: 3px;
    font-size: 7pt;
    color: #555;
    background: #f0f8f3;
}

.draft-stamp {
    position: absolute; top: 50mm; left: 50%;
    transform: translateX(-50%) rotate(-30deg);
    font-size: 55px; font-weight: bold;
    color: rgba(200,0,0,0.08);
    white-space: nowrap; pointer-events: none;
}

.print-controls {
    position: fixed;
    top: 0; left: 0; right: 0;
    background: #1a5632;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    z-index: 1000;
}
.print-controls .info-text {
    color: rgba(255,255,255,.8);
    font-size: .82rem;
    margin-right: 10px;
}
.print-controls button {
    color: #fff;
    border: none;
    padding: 8px 20px;
    font-size: .85rem;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-print  { background: #27ae60; }
.btn-print:hover  { background: #2ecc71; }
.btn-back   { background: #546e7a; }
.btn-back:hover   { background: #607d8b; }

@media print {
    html, body {
        width: 210mm;
        height: auto;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }
    .print-controls { display: none !important; }
    .print-wrapper {
        display: block !important;
        padding: 0 !important;
        margin: 0 !important;
        gap: 0 !important;
    }
    .page {
        width: 210mm !important;
        max-width: 210mm !important;
        min-height: 0 !important;
        box-sizing: border-box !important;
        padding: 5mm 8mm !important;
        margin: 0 auto !important;
        box-shadow: none !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: always !important;
        break-after: page !important;
        overflow: hidden !important;
    }
    .page:last-child {
        page-break-after: auto !important;
        break-after: auto !important;
    }
}
</style>
</head>
<body>

<div class="print-controls">
    <span class="info-text">
        🚛 <strong><?= esc($t['trip_no']) ?></strong>
        &nbsp;·&nbsp; <?= esc($t['driver_name']) ?>
        &nbsp;·&nbsp; <?= esc($t['reg_no']) ?>
    </span>
    <button class="btn-print" onclick="window.print()">
        🖨️ Print 2 Copies
    </button>
    <button class="btn-back" onclick="window.history.back()">
        ← Back
    </button>
</div>

<div class="print-wrapper">

<?php
$copies = [
    ['label' => 'Delivery Challan — Original Copy'],
    ['label' => 'Delivery Challan — Transporter Copy']
];
$is_lease_agent_account = (!empty($t['lease_agent_id']) && (int)$t['lease_agent_id'] > 0)
    || (!empty($t['vehicle_lease_agent_id']) && (int)$t['vehicle_lease_agent_id'] > 0)
    || !empty($t['lease_agent_short_name'])
    || !empty($t['lease_agent_full_name']);

foreach ($copies as $copy):
?>

<div class="page">
<?php if ($t['status'] === 'Planned'): ?>
<div class="draft-stamp">DRAFT</div>
<?php endif; ?>

<!-- Header -->
<div class="header-top">
    <div class="header-company">
        <?php if ($challan_logo_url): ?>
        <img src="<?= esc($challan_logo_url) ?>" alt="Company Logo" class="header-logo">
        <?php endif; ?>
        <div>
            <div class="company-name"><?= esc($company['company_name'] ?? '') ?></div>
            <div class="company-sub">
                <?= esc($company['address'] ?? '') ?><?= ($company['city']??'') ? ', '.esc($company['city']) : '' ?><br>
                GSTIN: <?= esc($company['gstin'] ?? '') ?> | Ph: <?= esc($company['phone'] ?? '') ?>
            </div>
        </div>
    </div>
    <div class="challan-ref">
        <h2>Delivery Challan</h2>
        <p>
            <span class="doc-meta-row"><span class="doc-meta-label">Trip No:</span> <span class="doc-meta-value"><?= esc($t['trip_no']) ?></span></span>
            <span class="doc-meta-row"><span class="doc-meta-label">Date:</span> <span class="doc-meta-value"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></span></span>
            <?php if ($t['po_number']): ?><span>PO Ref: <strong><?= esc($t['po_number']) ?></strong></span><?php endif; ?>
        </p>
    </div>
</div>

<div class="copy-banner"><?= esc($copy['label']) ?></div>

<!-- Vehicle & Driver -->
<div class="section-title">Vehicle &amp; Driver Details</div>
<table class="info">
<tr>
    <td class="lbl">Vehicle Reg No</td><td><strong><?= esc($t['reg_no']) ?></strong></td>
    <td class="lbl">Make / Model</td><td><?= esc($t['make'].' '.$t['model']) ?></td>
</tr>
<tr>
    <td class="lbl">Driver Name</td><td><strong><?= esc($t['driver_name']) ?></strong></td>
    <td class="lbl">License No</td><td><?= esc($t['driver_license'] ?? '—') ?></td>
</tr>
<tr>
    <td class="lbl">Driver Mobile</td><td><?= esc($t['driver_phone'] ?? '—') ?></td>
    <td class="lbl">Agent</td><td><?= esc($t['lease_agent_short_name'] ?: '—') ?></td>
</tr>
<?php if ($t['supervisor_name']): ?>
<tr><td class="lbl">Supervisor</td><td colspan="3"><?= esc($t['supervisor_name']) ?></td></tr>
<?php endif; ?>
<?php if ($t['vendor_name']): ?>
<tr><td class="lbl">Customer / Buyer</td><td colspan="3"><strong><?= esc($t['vendor_name']) ?></strong></td></tr>
<?php endif; ?>
<?php if (!empty($t['customer_camp'])): ?>
<tr><td class="lbl">Camp / Site / Unit</td><td colspan="3"><strong><?= esc($t['customer_camp']) ?></strong></td></tr>
<?php endif; ?>
</table>

<!-- Route -->
<div class="section-title">Route Details</div>
<table class="info">
<tr>
    <td class="lbl">From (Source)</td><td><strong><?= esc($t['from_location']) ?></strong></td>
    <td class="lbl">To (Destination)</td><td><strong><?= esc($t['to_location']) ?></strong></td>
</tr>
<?php if ($t['customer_name']): ?>
<tr>
    <td class="lbl">Consignee</td><td colspan="3"><strong><?= esc($t['customer_name']) ?></strong>
    <?= ($t['customer_city']??'') ? ' — '.esc($t['customer_city']) : '' ?>
    <?= ($t['customer_gstin']??'') ? ' | GSTIN: '.esc($t['customer_gstin']) : '' ?>
    </td>
</tr>
<?php endif; ?>
</table>

<!-- Items -->
<?php if ($trip_items): ?>
<div class="section-title">Material / Items</div>
<div class="items-wrap">
<table class="items">
<thead><tr>
    <th style="width:5%">#</th>
    <th style="width:30%">Item / Material</th>
    <th style="width:8%">UOM</th>
    <th class="r" style="width:10%">Qty</th>
    <th class="r" style="width:12%">Weight (MT)</th>
    <th class="r" style="width:12%">Rate (&#8377;)</th>
    <th class="r" style="width:13%">Amount (&#8377;)</th>
</tr></thead>
<tbody>
<?php $ri=1; $tw=0; foreach ($trip_items as $ti): $tw += (float)($ti['weight']??0); ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><strong><?= esc($ti['item_name']) ?></strong></td>
    <td><?= esc($ti['uom']) ?></td>
    <td class="r"><?= number_format((float)($ti['qty']??0),3) ?></td>
    <td class="r"><?= number_format((float)($ti['weight']??0),3) ?></td>
    <td class="r">&#8377;<?= number_format((float)($ti['unit_price']??0),2) ?></td>
    <td class="r">&#8377;<?= number_format((float)($ti['amount']??0),2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
    <td colspan="4" class="r">Total</td>
    <td class="r"><?= number_format($tw,3) ?> MT</td>
    <td></td>
    <td class="r">&#8377;<?= number_format((float)($t['subtotal']??0),2) ?></td>
</tr></tfoot>
</table>
</div>
<?php else: ?>
<div class="section-title">Material Details</div>
<table class="info">
<tr>
    <td class="lbl">Total Weight</td><td><strong><?= number_format((float)($t['total_weight']??0),3) ?> MT</strong></td>
    <td class="lbl">UOM</td><td><?= esc($t['uom']??'MT') ?></td>
</tr>
</table>
<?php endif; ?>

<!-- Freight -->
<div class="section-title">Freight Details</div>
<table class="info">
<tr>
    <td class="lbl">Freight Amount</td><td><strong>&#8377;<?= number_format((float)($t['freight_amount']??0),2) ?></strong></td>
    <td class="lbl">Driver Advance</td><td>&#8377;<?= number_format((float)($t['driver_advance']??0),2) ?></td>
</tr>
<?php if (!$is_lease_agent_account && (float)($t['toll_amount'] ?? 0) > 0): ?>
<tr>
    <td class="lbl">Auto Toll / Misc</td><td colspan="3">&#8377;<?= number_format((float)($t['toll_amount']??0),2) ?></td>
</tr>
<?php endif; ?>
</table>

<?php if (!empty($t['remarks'])): ?>
<div class="section-title">Remarks</div>
<table class="info">
<tr><td style="padding:4px 7px"><?= esc($t['remarks']) ?></td></tr>
</table>
<?php endif; ?>

<!-- Signatures -->
<div class="sign-section">
    <div class="sign-box">
        <?php if (!empty($company['seal_path'])): ?>
        <img src="<?= fleetImgUrl($company['seal_path']) ?>" alt="Company Seal"
             style="max-height:42px;max-width:90px;object-fit:contain;display:block;margin:0 auto 3px;opacity:.9">
        <?php endif; ?>
        <div class="sig-title">Company Seal</div>
        <small><?= esc($company['company_name'] ?? '') ?></small>
    </div>
    <div class="sign-box">
        <?php if (!empty($auth_sig_path)): ?>
        <img src="<?= fleetImgUrl($auth_sig_path) ?>" alt="Authorised User Signature"
             style="max-height:38px;max-width:110px;object-fit:contain;display:block;margin:0 auto 3px">
        <?php endif; ?>
        <div class="sig-title">Supervisor / Authorised By</div>
        <small><?= esc($auth_sig_name) ?></small>
    </div>
    <div class="sign-box">
        <div class="sig-title">Consignee Signature</div>
        <small>(Goods Received in Good Condition)</small>
    </div>
</div>

    <div class="challan-footer">
        This is a computer generated Trip Challan | <?= esc($company['company_name'] ?? '') ?> | Generated on: <?= date('d/m/Y H:i') ?>
    </div>
</div><!-- /page -->

<?php endforeach; ?>

<?php if ($t['mtc_required'] === 'Yes'): ?>
<!-- ══ MTC PAGE (page break before) ══ -->
<div class="page" style="page-break-before:always">

<!-- MTC Header -->
<table style="width:100%;border-collapse:collapse;margin-bottom:0">
<tr>
    <td style="width:25%;border:2px solid #1a5632;padding:8px;text-align:center;vertical-align:middle;background:#1a5632">
        <div style="font-size:18px;font-weight:900;color:#fff;letter-spacing:1px"><?= strtoupper(substr(esc($company['company_name']??''),0,3)) ?></div>
    </td>
    <td style="border:2px solid #1a5632;border-left:none;padding:8px;text-align:center;vertical-align:middle">
        <div style="font-size:14px;font-weight:700;letter-spacing:1px;color:#1a5632">MATERIAL TEST CERTIFICATE (MTC)</div>
        <div style="font-size:10px;color:#555;margin-top:3px"><?= esc($company['company_name']??'') ?></div>
        <div style="font-size:10px;color:#555"><?= esc($company['address']??'') ?><?= ($company['city']??'') ? ', '.esc($company['city']) : '' ?> | GSTIN: <?= esc($company['gstin']??'') ?></div>
    </td>
</tr>
</table>

<!-- Info Block -->
<table style="width:100%;border-collapse:collapse;border:2px solid #1a5632;border-top:none;font-size:11px">
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632;width:20%">Challan No &amp; Vehicle No</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><?= esc($t['trip_no']) ?> &nbsp;|&nbsp; <?= esc($t['reg_no']) ?></td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632;width:15%">Trip Date</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
</tr>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632">Item Name</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><strong><?= esc($t['mtc_item_name']??'—') ?></strong></td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632">Test Date</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><?= $t['mtc_test_date'] ? date('d/m/Y',strtotime($t['mtc_test_date'])) : '—' ?></td>
</tr>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632">Customer / Buyer</td>
    <td colspan="3" style="border:1px solid #a8c8b0;padding:5px 8px"><?= esc($t['vendor_name']??'—') ?></td>
</tr>
<?php if (!empty($t['customer_camp'])): ?>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632">Camp / Site / Unit</td>
    <td colspan="3" style="border:1px solid #a8c8b0;padding:5px 8px"><?= esc($t['customer_camp']) ?></td>
</tr>
<?php endif; ?>
</table>

<!-- Source note -->
<table style="width:100%;border-collapse:collapse;border:2px solid #1a5632;border-top:none;font-size:11px">
<tr>
    <td style="background:#fff8e1;padding:6px 8px;border:1px solid #a8c8b0">
        Six random samples of Fly Ash were collected at one hour interval &amp; average results are as under: &nbsp;&nbsp;
        <strong>Source: <?= esc($t['mtc_source']??'—') ?></strong>
    </td>
</tr>
</table>

<!-- Test Results Table -->
<table style="width:100%;border-collapse:collapse;border:2px solid #1a5632;border-top:none;font-size:11px">
<thead>
<tr>
    <th style="background:#1a5632;color:#fff;padding:6px 8px;text-align:left;width:50%;border-right:1px solid #27ae60">TEST</th>
    <th style="background:#1a5632;color:#fff;padding:6px 8px;text-align:center;width:25%;border-right:1px solid #27ae60">RESULTS %</th>
    <th style="background:#1a5632;color:#fff;padding:6px 8px;text-align:center;width:25%">Requirements as per IS 3812</th>
</tr>
</thead>
<tbody>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">ROS 45 Micron Sieve</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_ros_45']??'—') ?>%</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&lt; 34%</td>
</tr>
<tr style="background:#f5fbf7">
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">Moisture</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_moisture']??'—') ?>%</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&lt; 2%</td>
</tr>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">Loss on Ignition</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_loi']??'—') ?>%</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&lt; 5%</td>
</tr>
<tr style="background:#f5fbf7">
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">Fineness – Specific Surface Area by Blaine's Permeability Method</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_fineness']??'—') ?> m²/kg</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&gt; 320 m²/kg</td>
</tr>
</tbody>
</table>

<?php if (!empty($t['mtc_remarks'])): ?>
<div style="border:2px solid #1a5632;border-top:none;padding:6px 8px;font-size:11px;background:#fff8e1">
    <strong>Remarks:</strong> <?= esc($t['mtc_remarks']) ?>
</div>
<?php endif; ?>

<!-- MTC Signatures -->
<div style="display:flex;justify-content:space-between;margin-top:20mm">
    <div style="text-align:center;width:45%">
        <?php if ($auth_sig_path): ?>
        <img src="<?= fleetImgUrl($auth_sig_path) ?>" alt="Signature"
             style="max-height:50px;max-width:130px;object-fit:contain;display:block;margin:0 auto 6px">
        <?php else: ?>
        <div style="border:1px dashed #aaa;min-height:55px;background:#fafafa;margin-bottom:6px"></div>
        <?php endif; ?>
        <div style="font-size:10px;font-weight:600">For <?= esc($company['company_name']??'') ?></div>
        <div style="font-size:10px;color:#555">(Manager Technical)</div>
    </div>
    <div style="text-align:center;width:45%">
        <?php if (!empty($company['seal_path'])): ?>
        <img src="<?= fleetImgUrl($company['seal_path']) ?>" alt="Company Seal"
             style="max-height:60px;max-width:90px;object-fit:contain;display:block;margin:0 auto 4px;opacity:.85">
        <?php else: ?>
        <div style="border:1px dashed #aaa;min-height:55px;background:#fafafa;margin-bottom:6px"></div>
        <?php endif; ?>
        <div style="font-size:10px;color:#555">Company Seal</div>
    </div>
</div>

<div style="text-align:center;margin-top:10px;font-size:9px;color:#888;border-top:1px solid #ddd;padding-top:5px">
    This MTC is issued as per IS 3812 requirements | Attached to Delivery Challan: <strong><?= esc($t['trip_no']) ?></strong> | Original – Consignee Copy
</div>

</div><!-- /MTC page -->
<?php endif; ?>

</div><!-- /print-wrapper -->
</body>
</html>
