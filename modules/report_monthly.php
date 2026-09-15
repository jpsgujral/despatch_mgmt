<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('reports', 'view');

// Month filter
$sel_month = $_GET['month'] ?? date('Y-m');
$month_start = $sel_month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));
$month_label = date('F Y', strtotime($month_start));

// Build month dropdown options (last 24 months)
$month_options = [];
for ($i = 0; $i < 24; $i++) {
    $m = date('Y-m', strtotime("-$i months"));
    $month_options[] = $m;
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-calendar-month me-2"></i>Monthly Report';</script>

<div class="d-flex justify-content-between align-items-md-center mb-3 flex-column flex-md-row gap-2">
    <h5 class="mb-0 fw-bold">Monthly Report — <?= $month_label ?></h5>
    <div class="d-flex flex-column flex-md-row gap-2 w-100">
        <form class="d-flex flex-column flex-sm-row gap-2 w-100" method="GET">
            <select name="month" class="form-select form-select-sm">
                <?php foreach ($month_options as $m): ?>
                <option value="<?= $m ?>" <?= $m===$sel_month?'selected':'' ?>><?= date('F Y', strtotime($m.'-01')) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-search me-1"></i>View</button>
        </form>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <button class="btn btn-outline-success btn-sm flex-grow-1 flex-sm-grow-0 text-nowrap" id="btnEmail" onclick="sendEmail()"><i class="bi bi-envelope me-1"></i>Email Now</button>
        </div>
        <div id="stEmail" class="small"></div>
    </div>
</div>

<?php

// ── DATA ──
$desp = $db->query("SELECT COUNT(*) c, SUM(total_weight) mt, SUM(freight_amount) frt, SUM(total_amount) val,
    SUM(status='Delivered') delivered, SUM(status='In Transit') in_transit,
    SUM(status='Despatched') despatched, SUM(status='Cancelled') cancelled
    FROM despatch_orders WHERE despatch_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();

$desp_vendors = $db->query("SELECT COALESCE(v.vendor_name,'Unknown') vendor_name,
    COUNT(*) c, SUM(d.total_weight) mt, SUM(d.freight_amount) frt
    FROM despatch_orders d LEFT JOIN vendors v ON d.vendor_id=v.id
    WHERE d.despatch_date BETWEEN '$month_start' AND '$month_end' AND d.status!='Cancelled'
    GROUP BY d.vendor_id ORDER BY mt DESC")->fetch_all(MYSQLI_ASSOC);

$sales = $db->query("SELECT COUNT(*) c, SUM(total_amount) val, SUM(subtotal) sub,
    SUM(cgst_amount+sgst_amount+igst_amount) gst,
    SUM(status='Paid') paid_c, SUM(IF(status='Paid',total_amount,0)) paid_amt,
    SUM(IF(status!='Paid',total_amount,0)) outstanding
    FROM sales_invoices WHERE invoice_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();

$fleet = $db->query("SELECT COUNT(*) c, SUM(total_weight) mt, SUM(freight_amount) frt,
    SUM(status='Completed') completed, SUM(status='In Transit') in_transit
    FROM fleet_trips WHERE trip_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();

$fuel = $db->query("SELECT SUM(litres) litres, SUM(amount) cost,
    SUM(COALESCE(driver_advance,0)) advance,
    SUM(amount+COALESCE(driver_advance,0)) total_billed
    FROM fleet_fuel_log WHERE fuel_date BETWEEN '$month_start' AND '$month_end' AND payment_mode='Credit'")->fetch_assoc();

$veh_exp = $db->query("SELECT expense_type, COUNT(*) c, SUM(amount) total
    FROM fleet_expenses WHERE expense_date BETWEEN '$month_start' AND '$month_end'
    GROUP BY expense_type ORDER BY total DESC")->fetch_all(MYSQLI_ASSOC);

$trans_pay = $db->query("SELECT SUM(amount) total, COUNT(*) c FROM transporter_payments
    WHERE payment_date BETWEEN '$month_start' AND '$month_end' AND status='Paid'")->fetch_assoc();

function rCard($title, $val, $sub='', $color='#1a5632') {
    return '<div class="col-6 col-md-3"><div class="card p-3 text-center h-100" style="border-top:3px solid '.$color.'">
        <div class="text-muted small mb-1">'.htmlspecialchars($title).'</div>
        <div class="fw-bold fs-5" style="color:'.$color.'">'.htmlspecialchars($val).'</div>
        '.($sub ? '<div class="text-muted" style="font-size:.75rem">'.htmlspecialchars($sub).'</div>' : '').'
    </div></div>';
}
?>

<!-- Despatch -->
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-send-check me-2"></i>Despatch Operations</div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <?= rCard('Total Challans', (int)$desp['c']) ?>
            <?= rCard('Total MT', number_format((float)$desp['mt'],3), '', '#0d6efd') ?>
            <?= rCard('Total Freight', '₹'.number_format((float)$desp['frt']), '', '#27ae60') ?>
            <?= rCard('Delivered', (int)$desp['delivered'].' challans', 'Cancelled: '.(int)$desp['cancelled'], '#27ae60') ?>
        </div>
        <?php if ($desp_vendors): ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead style="background:#1a5632;color:#fff"><tr><th>Vendor</th><th class="text-end">Challans</th><th class="text-end">MT</th><th class="text-end">Freight</th></tr></thead>
            <tbody>
            <?php $tot_mt=$tot_frt=0; foreach ($desp_vendors as $r): $tot_mt+=$r['mt']; $tot_frt+=$r['frt']; ?>
            <tr>
                <td class="fw-semibold"><?= htmlspecialchars($r['vendor_name']) ?></td>
                <td class="text-end"><?= $r['c'] ?></td>
                <td class="text-end text-primary"><?= number_format((float)$r['mt'],3) ?></td>
                <td class="text-end text-success fw-semibold">₹<?= number_format((float)$r['frt']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light"><tr>
                <td class="fw-bold">Total</td><td></td>
                <td class="text-end fw-bold text-primary"><?= number_format($tot_mt,3) ?></td>
                <td class="text-end fw-bold text-success">₹<?= number_format($tot_frt) ?></td>
            </tr></tfoot>
        </table></div>
        <?php endif; ?>
        <div class="mt-2 text-muted small">Transporter Payments: <strong>₹<?= number_format((float)$trans_pay['total'],2) ?></strong> (<?= (int)$trans_pay['c'] ?> payments)</div>
    </div>
</div>

<!-- Sales -->
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-receipt me-2"></i>Sales Invoices</div>
    <div class="card-body">
        <div class="row g-3">
            <?= rCard('Invoices Raised', (int)$sales['c']) ?>
            <?= rCard('Total Value', '₹'.number_format((float)$sales['val']), 'Sub: ₹'.number_format((float)$sales['sub']), '#0d6efd') ?>
            <?= rCard('GST Collected', '₹'.number_format((float)$sales['gst']), '', '#8e44ad') ?>
            <?= rCard('Received', '₹'.number_format((float)$sales['paid_amt']), (int)$sales['paid_c'].' invoices', '#27ae60') ?>
            <?= rCard('Outstanding', '₹'.number_format((float)$sales['outstanding']), '', '#e74c3c') ?>
        </div>
    </div>
</div>

<!-- Fleet -->
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-truck me-2"></i>Fleet Operations</div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <?= rCard('Total Trips', (int)$fleet['c']) ?>
            <?= rCard('Total MT', number_format((float)$fleet['mt'],3), '', '#0d6efd') ?>
            <?= rCard('Freight Earned', '₹'.number_format((float)$fleet['frt']), '', '#27ae60') ?>
            <?= rCard('Completed', (int)$fleet['completed'].' trips', 'In Transit: '.(int)$fleet['in_transit'], '#27ae60') ?>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <p class="fw-bold text-muted mb-2">Fuel Summary (Credit Entries)</p>
                <table class="table table-sm mb-0">
                    <tr><td>Total Litres</td><td class="text-end fw-bold"><?= number_format((float)$fuel['litres'],2) ?> L</td></tr>
                    <tr class="table-light"><td>Fuel Cost</td><td class="text-end fw-bold text-danger">₹<?= number_format((float)$fuel['cost'],2) ?></td></tr>
                    <tr><td>Driver Advances</td><td class="text-end fw-bold text-warning">₹<?= number_format((float)$fuel['advance'],2) ?></td></tr>
                    <tr class="table-light"><td class="fw-bold">Total Billed</td><td class="text-end fw-bold text-primary">₹<?= number_format((float)$fuel['total_billed'],2) ?></td></tr>
                </table>
            </div>
            <?php if ($veh_exp): ?>
            <div class="col-md-6">
                <p class="fw-bold text-muted mb-2">Vehicle Expenses — Total: ₹<?= number_format(array_sum(array_column($veh_exp,'total')),2) ?></p>
                <table class="table table-sm mb-0">
                    <?php foreach ($veh_exp as $e): ?>
                    <tr><td><?= htmlspecialchars($e['expense_type']) ?></td><td class="text-end fw-bold">₹<?= number_format((float)$e['total'],2) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<script>
function sendEmail() {
    var btn = document.getElementById('btnEmail');
    var st  = document.getElementById('stEmail');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
    st.innerHTML = '';
    var controller = window.AbortController ? new AbortController() : null;
    var timeoutId = null;
    var request = fetch('send_report.php?type=monthly&month=<?= urlencode($sel_month) ?>', controller ? { signal: controller.signal } : {});
    var timeout = new Promise(function(_, reject){
        timeoutId = setTimeout(function(){
            if (controller) controller.abort();
            reject(new Error('Email request timed out.'));
        }, 45000);
    });
    Promise.race([request, timeout])
        .then(function(r){ return r.json(); })
        .then(function(d){
            clearTimeout(timeoutId);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-envelope me-1"></i>Email Now';
            st.innerHTML = d.ok ? '<span class="badge bg-success" title="'+d.msg+'">&#10003; Sent</span>'
                                : '<span class="badge bg-danger" title="'+d.msg+'">&#10007; Failed</span>';
        }).catch(function(){
            clearTimeout(timeoutId);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-envelope me-1"></i>Email Now';
            st.innerHTML = '<span class="badge bg-danger">Error — check console</span>';
        });
}
</script>
<?php include '../includes/footer.php'; ?>
