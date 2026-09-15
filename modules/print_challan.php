<?php
require_once '../includes/config.php';
require_once '../includes/r2_helper.php';

/* ── Resolve image path: local uploads/ → relative URL, R2 key → r2_url() ── */
/* Fix double-encoded entities from sanitize() */
function esc($v) { return htmlspecialchars(html_entity_decode((string)($v??''), ENT_QUOTES|ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8'); }

function img_display_url(string $path): string {
    if (empty($path)) return '';
    if (strpos($path, 'uploads/') === 0) {
        $rel = substr($path, strlen('uploads/'));
        return '../modules/img.php?f=' . urlencode($rel);
    }
    // R2 key — build URL directly to avoid stub fallback issues
    $pub = defined('R2_PUBLIC_URL') ? R2_PUBLIC_URL : 'https://pub-5721570094064d529f1527519424c77b.r2.dev/dms_uploads';
    return $pub . '/' . ltrim($path, '/');
}
$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) die('Invalid request');

$despatch = $db->query("
    SELECT d.*, 
           t.transporter_name, t.transporter_code, t.phone as t_phone, t.gstin as t_gstin,
           v.vendor_name, v.address as v_address, v.city as v_city, v.gstin as v_gstin2,
           s.source_name,
           u.full_name as prepared_by_name,
           u.signature_path as prepared_by_sig,
           po.po_number,
           au.signature_path as auth_sig_path
    FROM despatch_orders d
    LEFT JOIN transporters t          ON d.transporter_id = t.id
    LEFT JOIN vendors v               ON d.vendor_id = v.id
    LEFT JOIN source_of_material s    ON d.source_of_material_id = s.id
    LEFT JOIN app_users u             ON d.created_by = u.id
    LEFT JOIN purchase_orders po      ON d.po_id = po.id
    LEFT JOIN app_users au        ON au.id = (
                    SELECT id FROM app_users
                    WHERE status='Active' AND signature_path IS NOT NULL AND signature_path != ''
                    ORDER BY (id = d.created_by) DESC, id ASC
                    LIMIT 1)
    WHERE d.id = $id
")->fetch_assoc();

if (!$despatch) die('Despatch order not found');
$is_draft   = ($despatch['status'] === 'Draft');
$is_transit = ($despatch['status'] === 'In Transit');

$items = $db->query("
    SELECT di.*, i.item_name, i.item_code, i.hsn_code, i.uom as i_uom
    FROM despatch_items di
    JOIN items i ON di.item_id = i.id
    WHERE di.despatch_id = $id
")->fetch_all(MYSQLI_ASSOC);

$company = getCompany((int)($despatch['company_id'] ?? 0));
$challan_logo_rel = '../assets/icons/icon-192x192.png';
$challan_logo_abs = dirname(__DIR__) . '/assets/icons/icon-192x192.png';
$challan_logo_url = file_exists($challan_logo_abs) ? $challan_logo_rel : '';

/* ── Logged-in user for Checked By ── */
$logged_user_name_ch = '';
$logged_user_sig_ch  = '';
$uid_ch = (int)($_SESSION['user_id'] ?? 0);
if ($uid_ch) {
    $lu_ch = $db->query("SELECT full_name, signature_path FROM app_users WHERE id=$uid_ch LIMIT 1")->fetch_assoc();
    if ($lu_ch) {
        $logged_user_name_ch = $lu_ch['full_name'] ?? '';
        $logged_user_sig_ch  = $lu_ch['signature_path'] ?? '';
    }
}

/* ── Clean address text: strip ALL backslashes, literal \n, normalize whitespace ── */
function cleanAddress($str) {
    if (empty($str)) return '';
    // Remove ALL backslash characters (never valid in addresses)
    $str = str_replace('\\', '', $str);
    // Remove literal \r\n or \n text
    $str = str_replace(['\r\n', '\n', '\r'], ' ', $str);
    // Remove actual newlines/carriage returns
    $str = str_replace(["\r\n", "\n", "\r"], ' ', $str);
    // Collapse multiple spaces/whitespace to single space
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Challan - <?= esc($despatch['challan_no']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            color: #222;
            background: #fff;
        }
        .challan-wrapper {
            max-width: 210mm;
            margin: 0 auto;
            padding: 8mm 10mm;
            --copy-color: #1a5632;
            --copy-soft: #f0f8f3;
            --copy-soft-2: #e5f5eb;
        }
        /* Header */
        .challan-header {
            border: 2px solid var(--copy-color);
            border-bottom: none;
        }
        .header-top {
            background: var(--copy-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
        }
        .header-company {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }
        .header-logo {
            width: 52px;
            height: 52px;
            object-fit: contain;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.22);
            border-radius: 10px;
            padding: 4px;
            flex-shrink: 0;
        }
        .company-name { font-size: 18pt; font-weight: bold; letter-spacing: 1px; }
        .company-tagline { font-size: 8pt; opacity: 0.8; }
        .challan-title {
            text-align: right;
        }
        .challan-title h2 {
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .challan-title p { font-size: 8pt; opacity: 0.92; }
        .challan-title .doc-meta-row {
            display: block;
            font-size: 11pt;
            font-weight: bold;
            color: #fff;
            line-height: 1.35;
        }
        .challan-title .doc-meta-label,
        .challan-title .doc-meta-value {
            color: #fff;
            font-weight: bold;
        }
        
        .header-info {
            display: flex;
            border-bottom: 2px solid var(--copy-color);
        }
        .company-details {
            flex: 1;
            padding: 8px 15px;
            border-right: 2px solid var(--copy-color);
            font-size: 8.5pt;
            line-height: 1.6;
        }
        .company-details strong { font-size: 9pt; }
        .challan-meta {
            width: 200px;
            padding: 8px 12px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid var(--copy-color);
            padding: 3px 0;
            font-size: 8.5pt;
        }
        .meta-row:last-child { border-bottom: none; }
        .meta-label { color: #555; font-weight: bold; }
        .meta-value { text-align: right; font-weight: 600; }
        
        /* Copy Banner */
        .copy-banner {
            background: var(--copy-soft);
            border: 2px solid var(--copy-color);
            border-top: none;
            border-bottom: 2px solid var(--copy-color);
            text-align: center;
            padding: 4px;
            font-size: 9pt;
            font-weight: bold;
            color: var(--copy-color);
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        /* Address Section */
        .address-section {
            display: flex;
            border: 2px solid var(--copy-color);
            border-top: none;
        }
        .address-box {
            flex: 1;
            padding: 8px 12px;
            border-right: 2px solid var(--copy-color);
            font-size: 8.5pt;
            line-height: 1.5;
        }
        .address-box:last-child { border-right: none; }
        .address-box .box-title {
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            color: var(--copy-color);
            background: var(--copy-soft-2);
            margin: -8px -12px 6px;
            padding: 3px 12px;
            letter-spacing: 0.5px;
        }
        .address-box strong { font-size: 9.5pt; display: block; margin-bottom: 2px; }

        /* Transport Section */
        .transport-section {
            border: 2px solid var(--copy-color);
            border-top: none;
            display: flex;
        }
        .transport-field {
            flex: 1;
            padding: 5px 10px;
            border-right: 2px solid var(--copy-color);
            font-size: 8pt;
        }
        .transport-field:last-child { border-right: none; }
        .transport-field .t-label { 
            font-size: 7pt; color: #555; font-weight: bold; 
            text-transform: uppercase; letter-spacing: 0.3px; 
        }
        .transport-field .t-value { font-weight: 600; font-size: 9pt; margin-top: 1px; }

        /* Items Table */
        .items-section {
            border: 2px solid var(--copy-color);
            border-top: none;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .items-table th {
            background: var(--copy-color);
            color: white;
            padding: 5px 8px;
            text-align: center;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 0.3px;
        }
        .items-table td {
            padding: 5px 8px;
            border-bottom: 1px solid var(--copy-color);
            font-size: 8.5pt;
            vertical-align: middle;
        }
        .items-table tr:nth-child(even) td { background: #f9f9f9; }
        .items-table .num { text-align: center; }
        .items-table .right { text-align: right; }
        .items-table tfoot td {
            font-weight: bold;
            background: var(--copy-soft);
            border-top: 2px solid var(--copy-color);
            padding: 5px 8px;
        }

        /* Totals Section */
        .totals-section {
            display: flex;
            border: 2px solid var(--copy-color);
            border-top: none;
        }
        .amount-words {
            flex: 1;
            padding: 8px 12px;
            border-right: 2px solid var(--copy-color);
            font-size: 8.5pt;
        }
        .amount-words .label { font-size: 7pt; color: #555; font-weight: bold; text-transform: uppercase; }
        .amount-words .words { font-weight: 600; font-style: italic; }
        .totals-table {
            width: 200px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 12px;
            border-bottom: 1px solid var(--copy-color);
            font-size: 8.5pt;
        }
        .total-row.grand {
            background: var(--copy-color);
            color: white;
            font-weight: bold;
            font-size: 10pt;
            padding: 5px 12px;
        }

        /* Remarks */
        .remarks-section {
            border: 2px solid var(--copy-color);
            border-top: none;
            padding: 6px 12px;
            font-size: 8pt;
        }

        /* Signatures */
        .signature-section {
            border: 2px solid var(--copy-color);
            border-top: none;
            display: flex;
        }
        .sig-box {
            flex: 1;
            padding: 10px 15px 10px;
            border-right: 2px solid var(--copy-color);
            text-align: center;
            font-size: 8pt;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
        }
        .sig-box:last-child { border-right: none; }
        .sig-box .sig-title {
            font-size: 7pt;
            color: #555;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1.5px solid #555;
            padding-top: 5px;
            width: 100%;
        }

        /* Footer note */
        .challan-footer {
            text-align: center;
            padding: 8px;
            font-size: 7.5pt;
            color: #666;
            border: 2px solid var(--copy-color);
            border-top: 2px solid var(--copy-color);
            background: #f8f9fa;
        }

        /* Print Controls */
        .print-controls {
            padding: 12px 16px;
            text-align: center;
            background: #1a5632;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .print-controls span {
            color: rgba(255,255,255,0.7);
            font-size: 0.85rem;
            margin-right: 6px;
        }
        .print-controls button {
            background: #27ae60;
            color: white;
            border: none;
            padding: 9px 22px;
            font-size: 0.9rem;
            border-radius: 7px;
            cursor: pointer;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .print-controls button:hover { background: #2ecc71; }
        .print-controls .close-btn  { background: #7f8c8d; }
        .print-controls .pdf-btn   { background: #c0392b; }
        .print-controls .pdf-btn:hover { background: #e74c3c; }
        .print-controls .dl-btn    { background: #27ae60; }
        .print-controls .dl-btn:hover  { background: #2ecc71; }

        .copy-separator {
            margin: 20px 0;
            border: none;
            border-top: 2px dashed #aaa;
            page-break-after: always;
        }
        .challan-wrapper {
            max-width: 210mm;
            margin: 0 auto;
            padding: 8mm 10mm;
            page-break-inside: avoid;
        }

        /* Screen: scroll horizontally if challan wider than viewport */
        @media screen and (max-width: 800px) {
            body { background: #e5f5eb; }
            .challan-wrapper {
                padding: 4mm 4mm;
                max-width: 100%;
                overflow-x: auto;
            }
            .print-controls { padding: 10px 12px; }
            .print-controls button { padding: 8px 14px; font-size: 0.82rem; }
        }

        @media print {
            .print-controls { display: none !important; }
            body { margin: 0; background: white; }
            .challan-wrapper {
                padding: 5mm 8mm;
                max-width: 100%;
                page-break-inside: avoid;
            }
            .copy-separator {
                border: none;
                page-break-after: always;
                margin: 0;
                height: 0;
            }
        }
    </style>
</head>
<body>

<div class="print-controls">
    <span>📄 Challan: <strong><?= esc($despatch['challan_no']) ?></strong></span>
    <button onclick="window.print()">🖨️ Print All 3 Copies</button>
    <button class="pdf-btn" onclick="window.open('export_challan_pdf.php?id=<?= $id ?>', '_blank')">📄 View PDF</button>
    <button class="dl-btn"  onclick="window.location='export_challan_pdf.php?id=<?= $id ?>&download'">⬇️ Download PDF</button>
    <button class="close-btn" onclick="window.history.back()">← Back</button>
</div>

<?php
$copies = [
    ['label' => 'Original (Consignee)', 'color' => '#1a5632', 'soft' => '#f0f8f3', 'soft2' => '#e5f5eb'],
    ['label' => 'Duplicate (Transporter)', 'color' => '#1a3a6b', 'soft' => '#eef4fb', 'soft2' => '#dfeaf8'],
];
foreach ($copies as $idx => $copy):
    $copyLabel = $copy['label'];
?>

<div class="challan-wrapper" style="--copy-color: <?= esc($copy['color']) ?>; --copy-soft: <?= esc($copy['soft']) ?>; --copy-soft-2: <?= esc($copy['soft2']) ?>;">
    <!-- Header -->
        <div class="challan-header">
        <div class="header-top">
            <div class="header-company">
                <?php if ($challan_logo_url): ?>
                <img src="<?= esc($challan_logo_url) ?>" alt="Company Logo" class="header-logo">
                <?php endif; ?>
                <div>
                    <div class="company-name"><?= esc($company['company_name']) ?></div>
                    <div class="company-tagline">
                        <?= esc($company['address']) ?>, <?= esc($company['city']) ?>
                        <?= $company['state'] ? ', '.$company['state'] : '' ?> - <?= esc($company['pincode']) ?><br>
                        📞 <?= esc($company['phone']) ?> | ✉ <?= esc($company['email']) ?><br>
                        GSTIN: <?= esc($company['gstin']) ?> | PAN: <?= esc($company['pan']) ?>
                    </div>
                </div>
            </div>
            <div class="challan-title">
                <h2>Delivery Challan</h2>
                <p>
                    <span class="doc-meta-row"><span class="doc-meta-label">Challan No:</span> <span class="doc-meta-value"><?= esc($despatch['challan_no']) ?></span></span>
                    <span class="doc-meta-row"><span class="doc-meta-label">Date:</span> <span class="doc-meta-value"><?= date('d/m/Y', strtotime($despatch['despatch_date'])) ?></span></span>
                </p>
            </div>
        </div>
        <div class="header-info">
            <div class="company-details">
                <?php if (!empty($despatch['vendor_name'])): ?>
                Vendor/Consignor: <strong><?= esc($despatch['vendor_name']) ?></strong><br>
                <?php endif; ?>
                <?php
                $src_disp = trim((string)($despatch['source_name'] ?? ''));
                if ($src_disp === '') $src_disp = trim((string)($despatch['mtc_source'] ?? ''));
                if ($src_disp !== ''):
                ?>
                Source: <strong><?= esc($src_disp) ?></strong>
                <?php endif; ?>
            </div>
            <div class="challan-meta">
                <div class="meta-row">
                    <span class="meta-label">Despatch Date:</span>
                    <span class="meta-value"><?= date('d/m/Y', strtotime($despatch['despatch_date'])) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">No. of Pkgs:</span>
                    <span class="meta-value"><?= $despatch['no_of_packages'] ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Total Weight:</span>
                    <?php
                    $wt_uom = !empty($items[0]['uom']) ? $items[0]['uom'] : (!empty($items[0]['i_uom']) ? $items[0]['i_uom'] : 'Kg');
                    $wt_dp  = uomDecimals($wt_uom);
                    ?>
                    <span class="meta-value"><?= $is_draft ? '—' : number_format((float)($despatch['total_weight']??0), $wt_dp).' '.esc($wt_uom) ?></span>
                </div>

                <?php if (!empty($despatch['expected_delivery'])): ?>
                <div class="meta-row">
                    <span class="meta-label">Exp. Delivery:</span>
                    <span class="meta-value"><?= date('d/m/Y', strtotime($despatch['expected_delivery'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="copy-banner"><?= $copyLabel ?></div>

    <!-- Address Section -->
    <div class="address-section">
        <div class="address-box">
            <div class="box-title">Consignee (Ship To)</div>
            <strong><?= esc(cleanAddress($despatch['consignee_name'])) ?></strong>
            <?php if (!empty($despatch['consignee_camp'])): ?>
            <div><strong>Camp / Site:</strong> <?= esc(cleanAddress($despatch['consignee_camp'])) ?></div>
            <?php endif; ?>
            <?= esc(cleanAddress($despatch['consignee_address'])) ?><br>
            <?= esc(cleanAddress($despatch['consignee_city'])) ?>
            <?= $despatch['consignee_state'] ? ', '.esc(cleanAddress($despatch['consignee_state'])) : '' ?>
            <?= $despatch['consignee_pincode'] ? ' - '.esc(cleanAddress($despatch['consignee_pincode'])) : '' ?><br>
            <?php if ($despatch['consignee_gstin']): ?>GSTIN: <strong><?= esc(cleanAddress($despatch['consignee_gstin'])) ?></strong><?php endif; ?>
        </div>
        <div class="address-box">
            <div class="box-title">PO &amp; Transporter Details</div>
            <?php if ($despatch['po_number']): ?><strong>PO No: <?= esc($despatch['po_number']) ?></strong><br><?php endif; ?>
            <?php if ($despatch['transporter_code']): ?>
            Transporter: <strong><?= esc($despatch['transporter_code']) ?></strong><br>
            <?php else: ?>
            <em style="color:#999">Self Transport / Direct</em><br>
            <?php endif; ?>
            <?php if ($despatch['lr_number']): ?>LR No: <strong><?= esc($despatch['lr_number']) ?></strong><?php endif; ?>
            <?php if ($despatch['lr_date']): ?> | LR Date: <?= date('d/m/Y', strtotime($despatch['lr_date'])) ?><?php endif; ?>
            <?php if ((float)($despatch['freight_amount'] ?? 0) > 0): ?>
            <br>Freight: <strong>₹<?= number_format((float)$despatch['freight_amount'], 2) ?></strong> (<?= esc($despatch['freight_paid_by']) ?> Pay)
            <?php endif; ?>
            <?php if ((float)($despatch['transporter_misc_charges'] ?? 0) > 0 || !empty($despatch['transporter_misc_remarks'])): ?>
            <br>Misc Charges: <strong><?= ((float)($despatch['transporter_misc_charges'] ?? 0) > 0) ? '₹' . number_format((float)$despatch['transporter_misc_charges'], 2) : '' ?></strong><?php if (!empty($despatch['transporter_misc_remarks'])): ?><?= ((float)($despatch['transporter_misc_charges'] ?? 0) > 0) ? ' (' : '' ?><?= esc($despatch['transporter_misc_remarks']) ?><?= ((float)($despatch['transporter_misc_charges'] ?? 0) > 0) ? ')' : '' ?><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="address-box" style="border-right:none">
            <div class="box-title">Vehicle & Driver</div>
            <?php if ($despatch['vehicle_no']): ?>
            Vehicle No: <strong><?= esc($despatch['vehicle_no']) ?></strong><br>
            <?php endif; ?>
            <?php if ($despatch['driver_name']): ?>
            Driver: <strong><?= esc($despatch['driver_name']) ?></strong><br>
            <?php endif; ?>
            <?php if ($despatch['driver_mobile']): ?>
            Mobile: <?= esc($despatch['driver_mobile']) ?><br>
            <?php endif; ?>
            Freight: <strong><?= esc($despatch['freight_paid_by']) ?> Pay</strong>
            <?php if ((float)($despatch['transporter_misc_charges'] ?? 0) > 0 || !empty($despatch['transporter_misc_remarks'])): ?>
            <br>Misc Charges: <strong><?= ((float)($despatch['transporter_misc_charges'] ?? 0) > 0) ? '₹' . number_format((float)$despatch['transporter_misc_charges'], 2) : '' ?></strong><?php if (!empty($despatch['transporter_misc_remarks'])): ?><?= ((float)($despatch['transporter_misc_charges'] ?? 0) > 0) ? ' (' : '' ?><?= esc($despatch['transporter_misc_remarks']) ?><?= ((float)($despatch['transporter_misc_charges'] ?? 0) > 0) ? ')' : '' ?><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Items Table -->
    <div class="items-section">
    <table class="items-table">
        <thead>
            <tr>
                <th width="3%" class="num">S.No</th>
                <th width="7%">Item Code</th>
                <th width="21%">Item Description</th>
                <th width="6%">HSN Code</th>
                <th width="5%">UOM</th>
                <th width="7%">Desp Qty</th>
                <th width="7%">Rcvd Wt</th>
                <th width="9%">Unit Price</th>
                <th width="5%">GST%</th>
                <th width="8%">GST Amt</th>
                <th width="10%">Total Value</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $subtotal = 0; $gst_total = 0; $total_qty = 0; $total_weight = 0;
        foreach ($items as $idx2 => $item):
            $subtotal += ($item['qty'] * $item['unit_price']);
            $gst_total += $item['gst_amount'];
            $total_qty += (float)($item['qty'] ?? 0);
            $total_weight += (float)($item['weight'] ?? 0);
            $item_uom  = $item['uom'] ?: ($item['i_uom'] ?? '');
            $item_dp   = uomDecimals($item_uom);
        ?>
        <tr>
            <td class="num"><?= $idx2+1 ?></td>
            <td><?= esc($item['item_code']) ?></td>
            <td>
                <strong><?= esc($item['item_name']) ?></strong>
                <?php if ($item['description']): ?><br><span style="font-size:7.5pt;color:#666"><?= esc($item['description']) ?></span><?php endif; ?>
            </td>
            <td class="num"><?= esc($item['hsn_code']) ?></td>
            <td class="num"><?= esc($item['uom'] ?: $item['i_uom']) ?></td>
            <td class="num"><?= ((float)($item['qty']??0) > 0) ? number_format((float)$item['qty'], $item_dp) : '—' ?></td>
            <td class="num"><strong><?= $is_draft ? '—' : (((float)($item['weight']??0) > 0) ? number_format((float)$item['weight'], 3) : '—') ?></strong></td>
            <td class="right"><?= $is_draft ? '—' : '₹'.number_format((float)($item['unit_price']??0),2) ?></td>
            <td class="num"><?= $is_draft ? '—' : $item['gst_rate'].'%' ?></td>
            <td class="right"><?= $is_draft ? '—' : '₹'.number_format((float)($item['gst_amount']??0),2) ?></td>
            <td class="right"><strong><?= $is_draft ? '—' : '₹'.number_format((float)($item['total_price']??0),2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="right">Total:</td>
                <td class="num"><?= $total_qty > 0 ? number_format($total_qty, 3) : '—' ?></td>
                <td class="num"><strong><?= $is_draft ? '—' : ($total_weight > 0 ? number_format($total_weight, 3) : '—') ?></strong></td>
                <td></td>
                <td></td>
                <td class="right"><strong><?= $is_draft ? '—' : '₹'.number_format($gst_total,2) ?></strong></td>
                <td class="right"><strong><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['total_amount']??0),2) ?></strong></td>
            </tr>
        </tfoot>
    </table>
    </div>

    <!-- Totals -->
    <div class="totals-section">
        <div class="amount-words">
            <div class="label">Amount in Words:</div>
            <div class="words"><?= $is_draft ? '—' : numberToWords((float)($despatch['total_amount']??0)).' Only' ?></div>
            <?php if (!empty($despatch['remarks'])): ?>
            <div class="label" style="margin-top:4px">Remarks:</div>
            <div style="font-size:8pt;color:#444"><?= esc($despatch['remarks']) ?></div>
            <?php endif; ?>
            <?php if (!empty($despatch['transporter_misc_remarks'])): ?>
            <div class="label" style="margin-top:4px">Misc Remarks:</div>
            <div style="font-size:8pt;color:#444"><?= esc($despatch['transporter_misc_remarks']) ?></div>
            <?php endif; ?>
        </div>
        <div class="totals-table">
            <div class="total-row">
                <span>Sub Total:</span>
                <span><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['subtotal']??0),2) ?></span>
            </div>
            <div class="total-row">
                <span>GST Amount:</span>
                <span><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['gst_amount']??0),2) ?></span>
            </div>
            <div class="total-row">
                <span>Freight:</span>
                <span><?= ((float)($despatch['freight_amount']??0) > 0) ? ('₹'.number_format((float)$despatch['freight_amount'],2)) : '—' ?></span>
            </div>
            <?php if ((float)($despatch['transporter_misc_charges'] ?? 0) > 0): ?>
            <div class="total-row">
                <span>Misc Charges:</span>
                <span>₹<?= number_format((float)$despatch['transporter_misc_charges'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($despatch['transporter_misc_remarks'])): ?>
            <div class="total-row" style="font-size:7.5pt;color:#666">
                <span>Misc Remarks:</span>
                <span><?= esc($despatch['transporter_misc_remarks']) ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row grand">
                <span>GRAND TOTAL:</span>
                <span><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['total_amount']??0),2) ?></span>
            </div>
        </div>
    </div>

    <!-- Terms -->
    <div class="remarks-section">
        <strong>Terms & Conditions:</strong> 1. Goods once sold will not be taken back. &nbsp;|&nbsp;
        2. Interest @18% p.a. will be charged if payment is not made within due date. &nbsp;|&nbsp;
        3. All disputes subject to local jurisdiction only. &nbsp;|&nbsp;
        4. E. & O.E.
    </div>

    <!-- Signatures -->
    <div class="signature-section">
        <div class="sig-box">
            <?php $prep_sig = !empty($despatch['prepared_by_sig']) ? $despatch['prepared_by_sig'] : ($despatch['auth_sig_path'] ?? ''); ?>
            <?php if (!empty($prep_sig)): ?>
            <div style="text-align:center;padding:3px 0">
                <img src="<?= img_display_url($prep_sig) ?>"
                     alt="Prepared By Signature"
                     style="max-height:40px;max-width:90%;object-fit:contain">
            </div>
            <?php endif; ?>
            <div class="sig-title">Prepared By</div>
            <?php if (!empty($despatch['prepared_by_name'])): ?>
<div style="font-size:7.5pt;font-weight:600;color:var(--copy-color);margin-top:2px"><?= esc($despatch['prepared_by_name']) ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <?php
            $cb_img = !empty($company['checked_by_sig_path']) ? $company['checked_by_sig_path'] : ($logged_user_sig_ch ?: '');
            if ($cb_img): ?>
            <div style="text-align:center;padding:3px 0">
                <img src="<?= img_display_url($cb_img) ?>" alt="Checked By"
                     style="max-height:40px;max-width:90%;object-fit:contain">
            </div>
            <?php endif; ?>
            <div class="sig-title">Checked By</div>
            <?php if ($logged_user_name_ch): ?>
<div style="font-size:7.5pt;font-weight:600;color:var(--copy-color);margin-top:2px"><?= esc($logged_user_name_ch) ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <div class="sig-title">Consignee Signature<br>(Goods Received in Good Condition)</div>
        </div>
        <div class="sig-box">
            <?php if (!empty($company['seal_path'])): ?>
            <div style="text-align:center;padding:4px 0">
                <img src="<?= img_display_url($company['seal_path']) ?>"
                     alt="Company Seal"
                     style="max-height:45px;max-width:90%;object-fit:contain">
            </div>
            <?php endif; ?>
            <div class="sig-title">Authorised Signatory<br>For <?= esc($company['company_name']) ?></div>
        </div>
    </div>

    <div class="challan-footer">
        This is a computer generated Delivery Challan | <?= esc($company['company_name']) ?> | 
        GSTIN: <?= esc($company['gstin']) ?> | Generated on: <?= date('d/m/Y H:i:s') ?>
    </div>
</div>


<?php if ($copyLabel === 'Original (Consignee)' && ($despatch['mtc_required'] ?? 'No') === 'Yes'): ?>
<div class="challan-wrapper" style="page-break-before:always">
<style>
.mtc-wrap { font-family: Arial, sans-serif; font-size: 11px; }
.mtc-title { text-align:center; font-size:15px; font-weight:700; border:2px solid #1a5632; padding:8px; background:#fff8e1; letter-spacing:1px; margin-bottom:0; }
.mtc-info-table { width:100%; border-collapse:collapse; }
.mtc-info-table td { border:1px solid #1a5632; padding:5px 8px; vertical-align:top; }
.mtc-info-table .lbl { font-weight:700; background:#fffbea; width:28%; }
.mtc-results-table { width:100%; border-collapse:collapse; margin-top:0; }
.mtc-results-table th { border:2px solid #1a5632; padding:6px 8px; background:#f5e642; font-weight:700; text-align:center; font-size:11px; }
.mtc-results-table td { border:1px solid #1a5632; padding:6px 8px; text-align:center; font-size:11px; }
.mtc-results-table td.test-name { text-align:left; font-weight:600; }
.mtc-sig { display:flex; justify-content:space-between; margin-top:20px; }
.mtc-sig-box { text-align:center; width:45%; }
.mtc-sig-line { border-top:1.5px solid #1a5632; margin-top:40px; padding-top:5px; font-size:10px; }
</style>
<div class="mtc-wrap">
    <!-- MTC Header -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:0">
        <tr>
            <td style="width:25%;border:2px solid #1a5632;padding:8px;text-align:center;vertical-align:middle">
                <?php if (!empty($company['logo'])): ?>
                <img src="<?= esc($company['logo']) ?>" style="max-height:50px">
                <?php else: ?>
                <div style="font-size:18px;font-weight:900;color:#1a5632"><?= strtoupper(substr($company['company_name'],0,3)) ?></div>
                <?php endif; ?>
            </td>
            <td style="border:2px solid #1a5632;padding:8px;text-align:center;vertical-align:middle">
                <div style="font-size:14px;font-weight:700;letter-spacing:1px">MATERIAL TEST CERTIFICATE (MTC)</div>
                <div style="font-size:10px;color:#555;margin-top:3px"><?= esc($company['company_name']) ?></div>
                <div style="font-size:10px;color:#555"><?= esc($company['address']) ?>, <?= esc($company['city']) ?><?= $company['state']?', '.$company['state']:'' ?> | GSTIN: <?= esc($company['gstin']) ?></div>
            </td>
        </tr>
    </table>

    <!-- Info Block -->
    <table class="mtc-info-table" style="margin-top:0">
        <tr>
            <td class="lbl">Challan No &amp; Vehicle No.</td>
            <td><?= esc($despatch['challan_no']) ?> &nbsp;|&nbsp; <?= esc($despatch['vehicle_no']) ?></td>
            <td class="lbl">Despatch Date</td>
            <td><?= date('d/m/Y', strtotime($despatch['despatch_date'])) ?></td>
        </tr>
        <tr>
            <td class="lbl">Item Name</td>
            <td><?= esc($despatch['mtc_item_name'] ?: ($despatch['consignee_name'] ?? '-')) ?></td>
            <td class="lbl">Test Date</td>
            <td><?= $despatch['mtc_test_date'] ? date('d/m/Y', strtotime($despatch['mtc_test_date'])) : '-' ?></td>
        </tr>
        <tr>
            <td class="lbl">Vendor Name</td>
            <td colspan="3"><?= esc($despatch['vendor_name'] ?? '-') ?></td>
        </tr>
    </table>

    <!-- Results section -->
    <table class="mtc-info-table" style="margin-top:0">
        <tr>
            <td colspan="4" style="background:#fff8e1;padding:7px 8px;border:1px solid #999;font-size:10.5px">
                Six random samples of Fly Ash were collected at one hour interval &amp; average results are as under:&nbsp;&nbsp;
                <strong>Source: <?= esc($despatch['mtc_source'] ?? '-') ?></strong>
            </td>
        </tr>
    </table>

    <!-- Test Results Table -->
    <table class="mtc-results-table">
        <thead>
        <tr>
            <th style="width:50%;text-align:left">TEST</th>
            <th style="width:25%">RESULTS %</th>
            <th style="width:25%">Requirements as per IS 3812</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="test-name">ROS 45 Micron Sieve</td>
            <td><?= esc($despatch['mtc_ros_45'] ?: '-') ?>%</td>
            <td>&lt; 34%</td>
        </tr>
        <tr>
            <td class="test-name">Moisture</td>
            <td><?= esc($despatch['mtc_moisture'] ?: '-') ?>%</td>
            <td>&lt; 2%</td>
        </tr>
        <tr>
            <td class="test-name">Loss on Ignition</td>
            <td><?= esc($despatch['mtc_loi'] ?: '-') ?>%</td>
            <td>&lt; 5%</td>
        </tr>
        <tr>
            <td class="test-name">Fineness – Specific Surface Area by Blaine's Permeability Method</td>
            <td><?= esc($despatch['mtc_fineness'] ?: '-') ?> m²/kg</td>
            <td>&gt; 320 m²/kg</td>
        </tr>
        </tbody>
    </table>

    <?php if (!empty($despatch['mtc_remarks'])): ?>
    <div style="margin-top:8px;font-size:11px"><strong>Remarks:</strong> <?= esc($despatch['mtc_remarks']) ?></div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="mtc-sig">
        <div class="mtc-sig-box">
            <div style="border:1px dashed #aaa;min-height:60px;margin-bottom:6px;background:#fafafa;
                        display:flex;align-items:center;justify-content:center;padding:4px">
                <?php if (!empty($company['mtc_sig_path'])): ?>
                <img src="<?= img_display_url($company['mtc_sig_path']) ?>" alt="MTC Signature"
                     style="max-height:52px;max-width:100%;object-fit:contain">
                <?php endif; ?>
            </div>
            <div style="font-size:10px;font-weight:600">For <?= esc($company['company_name']) ?></div>
            <div style="font-size:10px;color:#555">(Manager Technical)</div>
        </div>
        <div class="mtc-sig-box">
            <div style="border:1px dashed #aaa;min-height:60px;margin-bottom:6px;background:#fafafa;
                        display:flex;align-items:center;justify-content:center;padding:4px">
                <?php if (!empty($company['seal_path'])): ?>
                <img src="<?= img_display_url($company['seal_path']) ?>" alt="Company Seal"
                     style="max-height:52px;max-width:100%;object-fit:contain">
                <?php else: ?>
                <span style="color:#ccc;font-size:10px">SEAL</span>
                <?php endif; ?>
            </div>
            <div style="font-size:10px;color:#555;text-align:center">Company Seal</div>
        </div>
    </div>

    <div style="text-align:center;margin-top:10px;font-size:9px;color:#888;border-top:1px solid #1a5632;padding-top:5px">
        This MTC is issued as per IS 3812 requirements | Attached to Delivery Challan: <strong><?= esc($despatch['challan_no']) ?></strong> | Original – Consignee Copy
    </div>
</div>
</div>
<?php endif; ?>
<?php if ($idx < count($copies) - 1): ?>
<div class="copy-separator"></div>
<?php endif; ?>

<?php endforeach; ?>

</body>
</html>

<?php
function numberToWords($num) {
    $num = (int)round($num);
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
             'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
             'Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

    if ($num == 0) return 'Zero Rupees';

    // Use closure so PHP does not globally declare helper() - avoids "Cannot redeclare" on 3 copies
    $helper = null;
    $helper = function($n) use (&$helper, $ones, $tens) {
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[(int)($n/10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
        return $ones[(int)($n/100)] . ' Hundred' . ($n % 100 ? ' And ' . $helper($n % 100) : '');
    };

    $result = '';
    if ($num >= 10000000) { $result .= $helper((int)($num/10000000)) . ' Crore ';    $num %= 10000000; }
    if ($num >= 100000)   { $result .= $helper((int)($num/100000))   . ' Lakh ';     $num %= 100000;   }
    if ($num >= 1000)     { $result .= $helper((int)($num/1000))     . ' Thousand '; $num %= 1000;     }
    if ($num > 0)         { $result .= $helper($num); }
    return trim($result) . ' Rupees';
}
?>
