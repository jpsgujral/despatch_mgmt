<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('exception_dashboard', 'view');

$month = sanitize($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$today = date('Y-m-d');

$held_gst = $db->query("SELECT tp.id, tp.payment_no, tp.payment_date, tp.transporter_id, t.transporter_name, tp.gst_amount, tp.gst_held
    FROM transporter_payments tp
    LEFT JOIN transporters t ON t.id=tp.transporter_id
    WHERE tp.gst_held='Yes' AND (tp.is_gst_release IS NULL OR tp.is_gst_release='No')
    ORDER BY tp.payment_date DESC")->fetch_all(MYSQLI_ASSOC);

$short_receipts = $db->query("SELECT si.id, si.invoice_number, si.invoice_date, si.due_date, si.total_amount,
        COALESCE((SELECT SUM(p.amount) FROM sales_invoice_payments p WHERE p.invoice_id=si.id),0) AS paid_amount,
        COALESCE(v.vendor_name,'-') AS vendor_name
    FROM sales_invoices si
    LEFT JOIN despatch_orders d ON d.id=si.challan_id
    LEFT JOIN vendors v ON v.id=d.vendor_id
    WHERE si.invoice_date BETWEEN '$start' AND '$end'
    HAVING paid_amount < total_amount
    ORDER BY si.invoice_date DESC")->fetch_all(MYSQLI_ASSOC);

$over_alloc = $db->query("SELECT r.id, r.receipt_date, r.amount_received,
        COALESCE(SUM(a.allocated_amount),0) AS allocated_total, v.vendor_name
    FROM sales_vendor_receipts r
    LEFT JOIN sales_vendor_receipt_allocations a ON a.receipt_id=r.id
    LEFT JOIN vendors v ON v.id=r.vendor_id
    GROUP BY r.id
    HAVING allocated_total > amount_received
    ORDER BY r.receipt_date DESC")->fetch_all(MYSQLI_ASSOC);

$trip_map = $db->query("SELECT t.id, t.trip_no, t.trip_date, v.reg_no, t.total_weight,
        (SELECT COUNT(*) FROM fleet_fuel_log fl WHERE fl.trip_id=t.id) AS fuel_cnt,
        (SELECT COUNT(*) FROM fleet_expenses fe WHERE fe.trip_id=t.id) AS exp_cnt
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON v.id=t.vehicle_id
    WHERE t.status='Completed' AND t.trip_date BETWEEN '$start' AND '$end'
    HAVING fuel_cnt = 0 OR exp_cnt = 0
    ORDER BY t.trip_date DESC")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Exception Dashboard';</script>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Exception Dashboard</h5>
</div>

<div class="card mb-3"><div class="card-body">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-12 col-md-3">
        <label class="form-label">Month</label>
        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>">
    </div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Apply</button></div>
</form>
</div></div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Held GST Pending</small><div class="fw-bold text-danger"><?= count($held_gst) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Short/Outstanding Invoices</small><div class="fw-bold text-warning"><?= count($short_receipts) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Over-Allocated Receipts</small><div class="fw-bold text-danger"><?= count($over_alloc) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-center"><small class="text-muted">Trips Missing Mapping</small><div class="fw-bold text-info"><?= count($trip_map) ?></div></div></div>
</div>

<div class="card mb-3"><div class="card-header">Held GST Pending Release</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Payment No</th><th>Transporter</th><th class="text-end">GST</th></tr></thead><tbody>
<?php foreach($held_gst as $r): ?><tr><td><?= date('d/m/Y',strtotime($r['payment_date'])) ?></td><td><?= htmlspecialchars($r['payment_no']) ?></td><td><?= htmlspecialchars($r['transporter_name']??'-') ?></td><td class="text-end">₹<?= number_format((float)$r['gst_amount'],2) ?></td></tr><?php endforeach; ?>
<?php if(!$held_gst): ?><tr><td colspan="4" class="text-muted p-2">No held GST pending.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="card mb-3"><div class="card-header">Short / Outstanding Invoices</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Vendor</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead><tbody>
<?php foreach($short_receipts as $r): $bal=(float)$r['total_amount']-(float)$r['paid_amount']; ?><tr><td><a href="sales_invoices.php?action=view&id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['invoice_number']) ?></a></td><td><?= htmlspecialchars($r['vendor_name']) ?></td><td><?= !empty($r['due_date'])?date('d/m/Y',strtotime($r['due_date'])):'-' ?></td><td class="text-end">₹<?= number_format((float)$r['total_amount'],2) ?></td><td class="text-end">₹<?= number_format((float)$r['paid_amount'],2) ?></td><td class="text-end text-danger">₹<?= number_format($bal,2) ?></td></tr><?php endforeach; ?>
<?php if(!$short_receipts): ?><tr><td colspan="6" class="text-muted p-2">No outstanding invoices for selected month.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="card mb-3"><div class="card-header">Over-Allocated Vendor Receipts</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Receipt Date</th><th>Vendor</th><th class="text-end">Received</th><th class="text-end">Allocated</th><th class="text-end">Excess</th></tr></thead><tbody>
<?php foreach($over_alloc as $r): ?><tr><td><?= date('d/m/Y',strtotime($r['receipt_date'])) ?></td><td><?= htmlspecialchars($r['vendor_name']??'-') ?></td><td class="text-end">₹<?= number_format((float)$r['amount_received'],2) ?></td><td class="text-end">₹<?= number_format((float)$r['allocated_total'],2) ?></td><td class="text-end text-danger">₹<?= number_format((float)$r['allocated_total']-(float)$r['amount_received'],2) ?></td></tr><?php endforeach; ?>
<?php if(!$over_alloc): ?><tr><td colspan="5" class="text-muted p-2">No over-allocation found.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="card"><div class="card-header">Trips Without Fuel/Expense Mapping</div><div class="table-responsive">
<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Trip</th><th>Vehicle</th><th class="text-end">Fuel Rows</th><th class="text-end">Expense Rows</th></tr></thead><tbody>
<?php foreach($trip_map as $r): ?><tr><td><?= date('d/m/Y',strtotime($r['trip_date'])) ?></td><td><a href="fleet_trips.php?action=view&id=<?= (int)$r['id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'exception_dashboard.php') ?>"><?= htmlspecialchars($r['trip_no']) ?></a></td><td><?= htmlspecialchars($r['reg_no']??'-') ?></td><td class="text-end"><?= (int)$r['fuel_cnt'] ?></td><td class="text-end"><?= (int)$r['exp_cnt'] ?></td></tr><?php endforeach; ?>
<?php if(!$trip_map): ?><tr><td colspan="5" class="text-muted p-2">All completed trips mapped.</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php include '../includes/footer.php'; ?>
