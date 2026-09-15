<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();

$id  = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid invoice ID');

$inv = $db->query("SELECT si.*, c.company_name, c.address AS co_address, c.city AS co_city,
    c.state AS co_state, c.gstin AS co_gstin, c.pan AS co_pan,
    c.phone AS co_phone, c.email AS co_email,
    c.bank_name, c.account_no, c.ifsc_code, c.seal_path
    FROM sales_invoices si LEFT JOIN companies c ON si.company_id=c.id
    WHERE si.id=$id LIMIT 1")->fetch_assoc();
if (!$inv) die('Invoice not found');

$items = $db->query("SELECT * FROM sales_invoice_items WHERE invoice_id=$id")->fetch_all(MYSQLI_ASSOC);
$is_split = ($inv['gst_type'] === 'CGST+SGST');

// Challan details
$challan = null;
if ($inv['challan_id']) {
    $challan = $db->query("SELECT challan_no, despatch_date FROM despatch_orders WHERE id=".(int)$inv['challan_id']." LIMIT 1")->fetch_assoc();
}

// Number to words (simple)
function numToWords($num) {
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
             'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
             'Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $num  = round($num, 2);
    $int  = (int)$num;
    $dec  = round(($num - $int) * 100);
    $words = '';
    if ($int >= 10000000) { $words .= numToWords((int)($int/10000000)).' Crore '; $int %= 10000000; }
    if ($int >= 100000)   { $words .= numToWords((int)($int/100000)).' Lakh '; $int %= 100000; }
    if ($int >= 1000)     { $words .= numToWords((int)($int/1000)).' Thousand '; $int %= 1000; }
    if ($int >= 100)      { $words .= $ones[(int)($int/100)].' Hundred '; $int %= 100; }
    if ($int >= 20)       { $words .= $tens[(int)($int/10)].' '; $int %= 10; }
    if ($int > 0)         { $words .= $ones[$int].' '; }
    $words = trim($words);
    if ($dec > 0) $words .= ' and '.numToWords($dec).' Paise';
    return $words ?: 'Zero';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice - <?= htmlspecialchars($inv['invoice_number']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
.page { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 8mm; border: 1px solid #000; }
h1 { text-align:center; font-size:15px; letter-spacing:2px; margin-bottom:4px; }
.sub-title { text-align:center; font-size:10px; margin-bottom:6px; }
table { width:100%; border-collapse:collapse; }
td, th { border:1px solid #000; padding:3px 5px; vertical-align:top; }
.no-border td, .no-border th { border:none; }
.hdr { background:#f0f0f0; font-weight:bold; text-align:center; }
.r { text-align:right; }
.c { text-align:center; }
.co-name { font-size:16px; font-weight:bold; }
.label { font-size:9px; color:#555; }
.total-row { background:#eee; font-weight:bold; }
@media print {
    body { margin:0; }
    .no-print { display:none; }
    .page { border:none; }
}
</style>
</head>
<body>
<div class="no-print" style="padding:10px;background:#f0f0f0;margin-bottom:10px">
    <button onclick="window.print()" style="padding:8px 20px;background:#1e3a5f;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px">
        🖨 Print Invoice
    </button>
    <button onclick="window.close()" style="margin-left:10px;padding:8px 20px;background:#666;color:#fff;border:none;border-radius:4px;cursor:pointer">
        Close
    </button>
</div>

<div class="page">
<!-- Header -->
<table class="no-border" style="margin-bottom:4px">
<tr>
    <td style="width:70%;border:none">
        <div class="co-name"><?= htmlspecialchars($inv['company_name']) ?></div>
        <div><?= nl2br(htmlspecialchars($inv['co_address'] ?? '')) ?>, <?= htmlspecialchars($inv['co_city'] ?? '') ?></div>
        <div><?= htmlspecialchars($inv['co_state'] ?? '') ?><?= $inv['co_pincode'] ?? '' ? ' - '.$inv['co_pincode'] ?? '' : '' ?></div>
        <div>Ph: <?= htmlspecialchars($inv['co_phone'] ?? '') ?> &nbsp;|&nbsp; Email: <?= htmlspecialchars($inv['co_email'] ?? '') ?></div>
        <div><strong>GSTIN:</strong> <?= htmlspecialchars($inv['co_gstin'] ?? '') ?> &nbsp;|&nbsp; <strong>PAN:</strong> <?= htmlspecialchars($inv['co_pan'] ?? '') ?></div>
    </td>
    <td style="width:30%;border:none;text-align:right;vertical-align:top">
        <?php if (!empty($inv['seal_path'])): ?>
        <img src="../<?= htmlspecialchars($inv['seal_path']) ?>" style="max-height:70px;max-width:120px;object-fit:contain">
        <?php endif; ?>
    </td>
</tr>
</table>

<h1>TAX INVOICE</h1>
<div class="sub-title">(Original for Recipient)</div>

<!-- Invoice & Consignee Info -->
<table style="margin-bottom:0">
<tr>
    <td style="width:50%">
        <div class="label">Invoice No.</div>
        <div><strong><?= htmlspecialchars($inv['invoice_number']) ?></strong></div>
    </td>
    <td style="width:50%">
        <div class="label">Invoice Date</div>
        <div><strong><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></strong></div>
    </td>
</tr>
<tr>
    <td>
        <div class="label">Delivery Challan No.</div>
        <div><?= $challan ? htmlspecialchars($challan['challan_no']).' ('.date('d/m/Y',strtotime($challan['despatch_date'])).')' : '-' ?></div>
    </td>
    <td>
        <div class="label">Payment Terms / Due Date</div>
        <div><?= htmlspecialchars($inv['payment_terms'] ?? '-') ?><?= $inv['due_date'] ? ' / '.date('d/m/Y',strtotime($inv['due_date'])) : '' ?></div>
    </td>
</tr>
<?php if ($inv['mrn_number'] || $inv['invoice_reg_number']): ?>
<tr>
    <td>
        <div class="label">MRN Number <?= $inv['mrn_date'] ? '('.date('d/m/Y',strtotime($inv['mrn_date'])).')' : '' ?></div>
        <div><?= htmlspecialchars($inv['mrn_number'] ?? '-') ?></div>
    </td>
    <td>
        <div class="label">Invoice Reg. Number <?= $inv['invoice_reg_date'] ? '('.date('d/m/Y',strtotime($inv['invoice_reg_date'])).')' : '' ?></div>
        <div><?= htmlspecialchars($inv['invoice_reg_number'] ?? '-') ?></div>
    </td>
</tr>
<?php endif; ?>
<tr>
    <td colspan="2">
        <div class="label">Bill To / Consignee</div>
        <div><strong><?= htmlspecialchars($inv['consignee_name']) ?></strong></div>
        <div><?= htmlspecialchars($inv['consignee_address'] ?? '') ?>, <?= htmlspecialchars($inv['consignee_city'] ?? '') ?>, <?= htmlspecialchars($inv['consignee_state'] ?? '') ?></div>
        <div><strong>GSTIN:</strong> <?= htmlspecialchars($inv['consignee_gstin'] ?? 'N/A') ?></div>
    </td>
</tr>
</table>

<!-- Items Table -->
<table style="margin-top:0">
<thead>
<tr class="hdr">
    <th style="width:4%">#</th>
    <th style="width:<?= $is_split?'25%':'30%' ?>">Description</th>
    <th style="width:8%">HSN</th>
    <th style="width:6%">UOM</th>
    <th style="width:7%">Qty</th>
    <th style="width:8%">Rate</th>
    <th style="width:8%">Amount</th>
    <?php if ($is_split): ?>
    <th style="width:5%">CGST%</th><th style="width:7%">CGST</th>
    <th style="width:5%">SGST%</th><th style="width:7%">SGST</th>
    <?php else: ?>
    <th style="width:6%">IGST%</th><th style="width:8%">IGST</th>
    <?php endif; ?>
    <th style="width:9%">Total</th>
</tr>
</thead>
<tbody>
<?php $ri=1; foreach ($items as $it): $dp = uomDecimals($it['uom']); ?>
<tr>
    <td class="c"><?= $ri++ ?></td>
    <td><?= htmlspecialchars($it['item_name']) ?></td>
    <td class="c"><?= htmlspecialchars($it['hsn_code']) ?></td>
    <td class="c"><?= htmlspecialchars($it['uom']) ?></td>
    <td class="r"><?= number_format($it['qty'], $dp) ?></td>
    <td class="r">₹<?= number_format($it['unit_price'], 2) ?></td>
    <td class="r">₹<?= number_format($it['amount'], 2) ?></td>
    <?php if ($is_split): ?>
    <td class="c"><?= $it['cgst_rate'] ?>%</td><td class="r">₹<?= number_format($it['cgst_amount'], 2) ?></td>
    <td class="c"><?= $it['sgst_rate'] ?>%</td><td class="r">₹<?= number_format($it['sgst_amount'], 2) ?></td>
    <?php else: ?>
    <td class="c"><?= $it['igst_rate'] ?>%</td><td class="r">₹<?= number_format($it['igst_amount'], 2) ?></td>
    <?php endif; ?>
    <td class="r"><strong>₹<?= number_format($it['total_amount'], 2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr class="total-row">
    <td colspan="6" class="r">Subtotal</td>
    <td class="r">₹<?= number_format($inv['subtotal'], 2) ?></td>
    <?php if ($is_split): ?>
    <td></td><td class="r">₹<?= number_format($inv['cgst_amount'], 2) ?></td>
    <td></td><td class="r">₹<?= number_format($inv['sgst_amount'], 2) ?></td>
    <?php else: ?>
    <td></td><td class="r">₹<?= number_format($inv['igst_amount'], 2) ?></td>
    <?php endif; ?>
    <td class="r">₹<?= number_format($inv['total_amount'], 2) ?></td>
</tr>
<tr>
    <td colspan="<?= $is_split ? 12 : 10 ?>" class="r"><strong>Grand Total</strong></td>
    <td class="r"><strong>₹<?= number_format($inv['total_amount'], 2) ?></strong></td>
</tr>
<tr>
    <td colspan="<?= $is_split ? 13 : 11 ?>">
        <strong>Amount in Words:</strong> Rupees <?= numToWords($inv['total_amount']) ?> Only
    </td>
</tr>
</tfoot>
</table>

<!-- Bank Details & Signature -->
<table style="margin-top:0">
<tr>
    <td style="width:55%">
        <strong>Bank Details for Payment:</strong><br>
        Bank: <?= htmlspecialchars($inv['bank_name'] ?? '-') ?><br>
        A/C No: <?= htmlspecialchars($inv['account_no'] ?? '-') ?><br>
        IFSC: <?= htmlspecialchars($inv['ifsc_code'] ?? '-') ?>
    </td>
    <td style="width:45%;text-align:center;vertical-align:bottom;height:60px">
        <div style="border-top:1px solid #000;margin-top:40px;padding-top:3px">
            For <?= htmlspecialchars($inv['company_name']) ?><br>
            Authorised Signatory
        </div>
    </td>
</tr>
<tr>
    <td colspan="2" style="font-size:9px;text-align:center;background:#f9f9f9">
        This is a computer generated invoice. <?= $inv['remarks'] ? 'Remarks: '.htmlspecialchars($inv['remarks']) : '' ?>
    </td>
</tr>
</table>

</div>
</body>
</html>
