<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('party_ledger', 'view');

$fy = sanitize($_GET['fy'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $fy)) {
    $y = (int)date('Y');
    $m = (int)date('m');
    $start = $m >= 4 ? $y : ($y - 1);
    $fy = sprintf('%04d-%02d', $start, ($start + 1) % 100);
}
$fy_start_year = (int)substr($fy, 0, 4);
$fy_end_yy = (int)substr($fy, 5, 2);
$expected_end_yy = ($fy_start_year + 1) % 100;
if ($fy_end_yy !== $expected_end_yy) {
    $fy = sprintf('%04d-%02d', $fy_start_year, $expected_end_yy);
}
$period_start = sprintf('%04d-04-01', $fy_start_year);
$period_end = sprintf('%04d-03-31', $fy_start_year + 1);
$party_type = sanitize($_GET['party_type'] ?? 'vendor');
$party_id = (int)($_GET['party_id'] ?? 0);

$vendors = $db->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$transporters = $db->query("SELECT id, transporter_name FROM transporters ORDER BY transporter_name")->fetch_all(MYSQLI_ASSOC);
$drivers = $db->query("SELECT id, full_name FROM fleet_drivers ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

$rows = [];
$opening = 0.0;
$debit_total = 0.0;
$credit_total = 0.0;

if ($party_type === 'vendor' && $party_id > 0) {
    $opening_bill = $db->query("SELECT COALESCE(SUM(si.total_amount),0) v
        FROM sales_invoices si
        JOIN despatch_orders d ON d.id=si.challan_id
        WHERE d.vendor_id=$party_id AND si.invoice_date < '$period_start'")->fetch_assoc()['v'] ?? 0;
    $opening_rcpt = $db->query("SELECT COALESCE(SUM(r.amount_received),0) v
        FROM sales_vendor_receipts r
        WHERE r.vendor_id=$party_id AND r.receipt_date < '$period_start'")->fetch_assoc()['v'] ?? 0;
    $opening = (float)$opening_bill - (float)$opening_rcpt;

    $inv_rows = $db->query("SELECT si.invoice_date dt, si.invoice_number ref, si.total_amount amt
        FROM sales_invoices si
        JOIN despatch_orders d ON d.id=si.challan_id
        WHERE d.vendor_id=$party_id AND si.invoice_date BETWEEN '$period_start' AND '$period_end'
        ORDER BY si.invoice_date, si.id")->fetch_all(MYSQLI_ASSOC);
    foreach ($inv_rows as $r) {
        $rows[] = ['dt'=>$r['dt'], 'particular'=>'Sales Invoice '.$r['ref'], 'debit'=>(float)$r['amt'], 'credit'=>0.0];
    }

    $pay_rows = $db->query("SELECT r.receipt_date dt, COALESCE(r.reference_no,'') ref, r.amount_received amt
        FROM sales_vendor_receipts r
        WHERE r.vendor_id=$party_id AND r.receipt_date BETWEEN '$period_start' AND '$period_end'
        ORDER BY r.receipt_date, r.id")->fetch_all(MYSQLI_ASSOC);
    foreach ($pay_rows as $r) {
        $rows[] = ['dt'=>$r['dt'], 'particular'=>'Vendor Receipt '.($r['ref'] ?: '-'), 'debit'=>0.0, 'credit'=>(float)$r['amt']];
    }
} elseif ($party_type === 'transporter' && $party_id > 0) {
    $opening_bill = $db->query("SELECT COALESCE(SUM(tp.amount),0) v
        FROM transporter_payments tp
        WHERE tp.transporter_id=$party_id AND tp.payment_date < '$period_start'")->fetch_assoc()['v'] ?? 0;
    $opening_paid = $db->query("SELECT COALESCE(SUM(tp.net_payable),0) v
        FROM transporter_payments tp
        WHERE tp.transporter_id=$party_id AND tp.payment_date < '$period_start' AND tp.status='Paid'")->fetch_assoc()['v'] ?? 0;
    $opening = (float)$opening_bill - (float)$opening_paid;

    $bill_rows = $db->query("SELECT tp.payment_date dt, tp.payment_no ref, tp.amount amt
        FROM transporter_payments tp
        WHERE tp.transporter_id=$party_id AND tp.payment_date BETWEEN '$period_start' AND '$period_end'
        ORDER BY tp.payment_date, tp.id")->fetch_all(MYSQLI_ASSOC);
    foreach ($bill_rows as $r) {
        $rows[] = ['dt'=>$r['dt'], 'particular'=>'Transporter Bill '.$r['ref'], 'debit'=>(float)$r['amt'], 'credit'=>0.0];
    }
    $paid_rows = $db->query("SELECT tp.payment_date dt, tp.reference_no ref, tp.net_payable amt
        FROM transporter_payments tp
        WHERE tp.transporter_id=$party_id AND tp.payment_date BETWEEN '$period_start' AND '$period_end' AND tp.status='Paid'
        ORDER BY tp.payment_date, tp.id")->fetch_all(MYSQLI_ASSOC);
    foreach ($paid_rows as $r) {
        $rows[] = ['dt'=>$r['dt'], 'particular'=>'Payment '.($r['ref'] ?: '-'), 'debit'=>0.0, 'credit'=>(float)$r['amt']];
    }
} elseif ($party_type === 'driver' && $party_id > 0) {
    $opening_sal = $db->query("SELECT COALESCE(SUM(net_payable),0) v FROM fleet_driver_salary
        WHERE driver_id=$party_id AND salary_month < '" . $db->real_escape_string(substr($period_start,0,7)) . "'")->fetch_assoc()['v'] ?? 0;
    $opening_paid = $db->query("SELECT COALESCE(SUM(paid_amount),0) v FROM fleet_driver_salary
        WHERE driver_id=$party_id AND salary_month < '" . $db->real_escape_string(substr($period_start,0,7)) . "'")->fetch_assoc()['v'] ?? 0;
    $opening = (float)$opening_sal - (float)$opening_paid;

    $sal_rows = $db->query("SELECT COALESCE(payment_date, CONCAT(salary_month,'-01')) dt, salary_month ref, net_payable, paid_amount
        FROM fleet_driver_salary
        WHERE driver_id=$party_id AND salary_month BETWEEN '" . $db->real_escape_string(substr($period_start,0,7)) . "' AND '" . $db->real_escape_string(substr($period_end,0,7)) . "'
        ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    foreach ($sal_rows as $r) {
        $rows[] = ['dt'=>$r['dt'], 'particular'=>'Salary '.$r['ref'], 'debit'=>(float)$r['net_payable'], 'credit'=>(float)$r['paid_amount']];
    }
}

usort($rows, fn($a,$b)=>strcmp($a['dt'],$b['dt']));
$running = $opening;
foreach ($rows as &$r) {
    $debit_total += (float)$r['debit'];
    $credit_total += (float)$r['credit'];
    $running += (float)$r['debit'] - (float)$r['credit'];
    $r['balance'] = $running;
}
unset($r);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-journal-text me-2"></i>Party Ledger';</script>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Party Ledger (FY)</h5>
</div>

<div class="card mb-3"><div class="card-body">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-12 col-md-2">
        <label class="form-label">Type</label>
        <select name="party_type" class="form-select" onchange="this.form.submit()">
            <option value="vendor" <?= $party_type==='vendor'?'selected':'' ?>>Vendor</option>
            <option value="transporter" <?= $party_type==='transporter'?'selected':'' ?>>Transporter</option>
            <option value="driver" <?= $party_type==='driver'?'selected':'' ?>>Driver</option>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Party</label>
        <select name="party_id" class="form-select">
            <option value="0">Select</option>
            <?php $src = $party_type==='vendor' ? $vendors : ($party_type==='transporter' ? $transporters : $drivers); foreach ($src as $p): ?>
                <?php $pid=(int)$p['id']; $pn=htmlspecialchars($p['vendor_name'] ?? $p['transporter_name'] ?? $p['full_name'] ?? ''); ?>
                <option value="<?= $pid ?>" <?= $party_id===$pid?'selected':'' ?>><?= $pn ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-2">
        <label class="form-label">Financial Year</label>
        <select name="fy" class="form-select">
            <?php
            $curY = (int)date('Y');
            $startY = $curY - 4;
            $endY = $curY + 1;
            for ($yy = $endY; $yy >= $startY; $yy--):
                $fyOpt = sprintf('%04d-%02d', $yy, ($yy + 1) % 100);
            ?>
                <option value="<?= $fyOpt ?>" <?= $fy === $fyOpt ? 'selected' : '' ?>><?= htmlspecialchars($fyOpt) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit">Load</button></div>
</form>
</div></div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Opening</small><div class="fw-bold">₹<?= number_format($opening,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Debit</small><div class="fw-bold text-danger">₹<?= number_format($debit_total,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Credit</small><div class="fw-bold text-success">₹<?= number_format($credit_total,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Closing</small><div class="fw-bold <?= $running>=0?'text-danger':'text-success' ?>">₹<?= number_format($running,2) ?></div></div></div>
</div>

<div class="card"><div class="table-responsive">
<table class="table table-sm mb-0">
    <thead class="table-light"><tr><th>Date</th><th>Particular</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($r['dt'])) ?></td>
            <td><?= htmlspecialchars($r['particular']) ?></td>
            <td class="text-end"><?= $r['debit']>0?'₹'.number_format($r['debit'],2):'-' ?></td>
            <td class="text-end"><?= $r['credit']>0?'₹'.number_format($r['credit'],2):'-' ?></td>
            <td class="text-end fw-semibold">₹<?= number_format($r['balance'],2) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-muted p-3">No transactions for selected FY filters.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>
<?php include '../includes/footer.php'; ?>
