<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('reports', 'view');

// Date filter
$date = $_GET['date'] ?? date('Y-m-d');
$date_fmt = date('d M Y', strtotime($date));

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-calendar-day me-2"></i>Daily Report';</script>
<style>
.daily-report-filter {
    width: auto;
}
.daily-report-filter .daily-report-date {
    width: 170px;
    min-width: 170px;
}
@media (max-width: 575.98px) {
    .daily-report-filter {
        width: 100%;
    }
    .daily-report-filter .daily-report-date {
        width: 100%;
        min-width: 0;
    }
}
</style>

<div class="d-flex justify-content-between align-items-md-center mb-3 flex-column flex-md-row gap-2">
    <h5 class="mb-0 fw-bold">Daily Report — <?= $date_fmt ?></h5>
    <div class="d-flex flex-column flex-md-row gap-2 w-100">
        <form class="d-flex flex-column flex-sm-row gap-2 w-100 daily-report-filter" method="GET">
            <input type="date" name="date" class="form-control form-control-sm daily-report-date" value="<?= $date ?>" max="<?= date('Y-m-d') ?>">
            <button class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-search me-1"></i>View</button>
        </form>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <a href="?date=<?= date('Y-m-d', strtotime($date.' -1 day')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
            <a href="?date=<?= date('Y-m-d', strtotime($date.' +1 day')) ?>" class="btn btn-outline-secondary btn-sm" <?= $date >= date('Y-m-d') ? 'disabled' : '' ?>><i class="bi bi-chevron-right"></i></a>
            <button class="btn btn-outline-success btn-sm flex-grow-1 flex-sm-grow-0 text-nowrap" id="btnEmail" onclick="sendEmail()"><i class="bi bi-envelope me-1"></i>Email Now</button>
        </div>
        <div id="stEmail" class="small"></div>
    </div>
</div>

<?php

// ── DATA ──
$desp_new = $db->query("SELECT d.challan_no, d.consignee_name, d.consignee_city,
    d.total_weight, d.freight_amount, d.status, t.transporter_name, d.vehicle_no
    FROM despatch_orders d LEFT JOIN transporters t ON d.transporter_id=t.id
    WHERE DATE(d.created_at)='$date' ORDER BY d.id DESC")->fetch_all(MYSQLI_ASSOC);

$desp_delivered = $db->query("SELECT d.challan_no, d.consignee_name, d.consignee_city,
    d.total_weight, d.freight_amount, t.transporter_name
    FROM despatch_orders d LEFT JOIN transporters t ON d.transporter_id=t.id
    WHERE d.status='Delivered' AND DATE(d.updated_at)='$date'")->fetch_all(MYSQLI_ASSOC);

$pending = $db->query("SELECT status, COUNT(*) c, SUM(total_weight) wt, SUM(freight_amount) frt
    FROM despatch_orders WHERE status IN ('Despatched','In Transit')
    GROUP BY status")->fetch_all(MYSQLI_ASSOC);

$fuel_today = $db->query("SELECT fl.litres, fl.amount, fl.driver_advance, fc.company_name
    FROM fleet_fuel_log fl LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id=fc.id
    WHERE fl.fuel_date='$date'")->fetch_all(MYSQLI_ASSOC);

$fleet_trips_today = $db->query("SELECT t.trip_no, t.from_location, t.to_location,
    t.total_weight, t.freight_amount, t.status, v.reg_no, d.full_name AS driver
    FROM fleet_trips t LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON t.driver_id=d.id
    WHERE t.trip_date='$date' ORDER BY t.id DESC")->fetch_all(MYSQLI_ASSOC);

$invoices_today = $db->query("SELECT invoice_number, consignee_name, total_amount, status
    FROM sales_invoices WHERE invoice_date='$date' ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

$G = '#1a5632';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 border-start border-4 border-success">
            <div class="text-muted small">New Challans</div>
            <div class="fs-3 fw-bold text-success"><?= count($desp_new) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 border-start border-4 border-primary">
            <div class="text-muted small">Delivered</div>
            <div class="fs-3 fw-bold text-primary"><?= count($desp_delivered) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 border-start border-4 border-warning">
            <div class="text-muted small">Fleet Trips</div>
            <div class="fs-3 fw-bold text-warning"><?= count($fleet_trips_today) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 border-start border-4 border-info">
            <div class="text-muted small">Invoices Raised</div>
            <div class="fs-3 fw-bold text-info"><?= count($invoices_today) ?></div>
        </div>
    </div>
</div>

<?php if ($desp_new): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-send-check me-2"></i>New Despatches (<?= count($desp_new) ?>)</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Challan No</th><th>Consignee</th><th>Transporter</th><th>Vehicle</th><th class="text-end">MT</th><th class="text-end">Freight</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($desp_new as $r): ?>
        <tr>
            <td><a href="despatch.php?action=view&id=<?= $r['id'] ?? '' ?>" class="fw-bold text-primary text-decoration-none"><?= htmlspecialchars($r['challan_no']) ?></a></td>
            <td><?= htmlspecialchars($r['consignee_name']) ?> <small class="text-muted"><?= htmlspecialchars($r['consignee_city']) ?></small></td>
            <td class="text-muted small"><?= htmlspecialchars($r['transporter_name']??'—') ?></td>
            <td class="text-muted small"><?= htmlspecialchars($r['vehicle_no']??'—') ?></td>
            <td class="text-end"><?= number_format((float)$r['total_weight'],3) ?></td>
            <td class="text-end text-success fw-semibold">₹<?= number_format((float)$r['freight_amount']) ?></td>
            <td><span class="badge bg-<?= ['Despatched'=>'primary','In Transit'=>'warning','Delivered'=>'success','Cancelled'=>'danger'][$r['status']]??'secondary' ?>"><?= $r['status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light"><tr>
            <td colspan="4" class="text-end fw-bold">Total</td>
            <td class="text-end fw-bold"><?= number_format(array_sum(array_column($desp_new,'total_weight')),3) ?> MT</td>
            <td class="text-end fw-bold text-success">₹<?= number_format(array_sum(array_column($desp_new,'freight_amount'))) ?></td>
            <td></td>
        </tr></tfoot>
    </table></div>
</div>
<?php endif; ?>

<?php if ($desp_delivered): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#27ae60;color:#fff"><i class="bi bi-check-circle me-2"></i>Deliveries Completed (<?= count($desp_delivered) ?>)</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Challan No</th><th>Consignee</th><th>Transporter</th><th class="text-end">MT</th><th class="text-end">Freight</th></tr></thead>
        <tbody>
        <?php foreach ($desp_delivered as $r): ?>
        <tr>
            <td class="fw-bold text-success"><?= htmlspecialchars($r['challan_no']) ?></td>
            <td><?= htmlspecialchars($r['consignee_name']) ?></td>
            <td class="text-muted small"><?= htmlspecialchars($r['transporter_name']??'—') ?></td>
            <td class="text-end"><?= number_format((float)$r['total_weight'],3) ?></td>
            <td class="text-end text-success fw-semibold">₹<?= number_format((float)$r['freight_amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($fleet_trips_today): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-truck me-2"></i>Fleet Trips Today (<?= count($fleet_trips_today) ?>)</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Trip No</th><th>Vehicle</th><th>Driver</th><th>Route</th><th class="text-end">MT</th><th class="text-end">Freight</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($fleet_trips_today as $r): ?>
        <tr>
            <td class="fw-bold text-primary"><?= htmlspecialchars($r['trip_no']) ?></td>
            <td><?= htmlspecialchars($r['reg_no']??'—') ?></td>
            <td class="small text-muted"><?= htmlspecialchars($r['driver']??'—') ?></td>
            <td class="small"><?= htmlspecialchars($r['from_location']) ?> → <?= htmlspecialchars($r['to_location']) ?></td>
            <td class="text-end"><?= number_format((float)$r['total_weight'],3) ?></td>
            <td class="text-end text-success fw-semibold">₹<?= number_format((float)$r['freight_amount']) ?></td>
            <td><span class="badge bg-<?= ['Completed'=>'success','In Transit'=>'warning','Planned'=>'info','Cancelled'=>'danger'][$r['status']]??'secondary' ?>"><?= $r['status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($invoices_today): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-receipt me-2"></i>Sales Invoices Today (<?= count($invoices_today) ?>)</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Invoice No</th><th>Consignee</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($invoices_today as $r): ?>
        <tr>
            <td class="fw-bold text-primary"><?= htmlspecialchars($r['invoice_number']) ?></td>
            <td><?= htmlspecialchars($r['consignee_name']) ?></td>
            <td class="text-end text-success fw-semibold">₹<?= number_format((float)$r['total_amount'],2) ?></td>
            <td><span class="badge bg-<?= ['Paid'=>'success','Draft'=>'secondary','Sent'=>'info'][$r['status']]??'warning' ?>"><?= $r['status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($fuel_today): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-droplet-fill me-2"></i>Fuel Entries Today (<?= count($fuel_today) ?>)</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-4 text-center"><div class="text-muted small">Total Litres</div><div class="fw-bold"><?= number_format(array_sum(array_column($fuel_today,'litres')),2) ?> L</div></div>
            <div class="col-4 text-center"><div class="text-muted small">Fuel Cost</div><div class="fw-bold text-danger">₹<?= number_format(array_sum(array_column($fuel_today,'amount')),2) ?></div></div>
            <div class="col-4 text-center"><div class="text-muted small">Driver Advance</div><div class="fw-bold text-warning">₹<?= number_format(array_sum(array_column($fuel_today,'driver_advance')),2) ?></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($pending): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#6c757d;color:#fff"><i class="bi bi-clock me-2"></i>Current Pending / In-Transit</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Status</th><th class="text-end">Orders</th><th class="text-end">MT</th><th class="text-end">Freight</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r): ?>
        <tr>
            <td><span class="badge bg-<?= $r['status']==='In Transit'?'warning':'primary' ?>"><?= $r['status'] ?></span></td>
            <td class="text-end fw-bold"><?= $r['c'] ?></td>
            <td class="text-end"><?= number_format((float)$r['wt'],3) ?> MT</td>
            <td class="text-end">₹<?= number_format((float)$r['frt']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if (!$desp_new && !$desp_delivered && !$fleet_trips_today && !$invoices_today): ?>
<div class="alert alert-info text-center"><i class="bi bi-calendar-x me-2"></i>No activity recorded for <?= $date_fmt ?>.</div>
<?php endif; ?>


<script>
function sendEmail() {
    var btn = document.getElementById('btnEmail');
    var st  = document.getElementById('stEmail');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
    st.innerHTML = '';
    var controller = window.AbortController ? new AbortController() : null;
    var timeoutId = null;
    var request = fetch('send_report.php?type=daily&date=<?= urlencode($date) ?>', controller ? { signal: controller.signal } : {});
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
            var msg = String((d && d.msg) || '');
            var safeTitle = msg.replace(/"/g, '&quot;');
            var treatedOk = !!(d && (d.ok || /\bsent\b/i.test(msg) || /\bOK:/i.test(msg)));
            st.innerHTML = treatedOk
                ? '<span class="badge bg-success" title="'+safeTitle+'">&#10003; Sent</span>'
                : '<span class="badge bg-danger" title="'+safeTitle+'">&#10007; Failed</span><div class="text-danger small mt-1" style="max-width:420px;line-height:1.35;">' + msg + '</div>';
        }).catch(function(err){
            clearTimeout(timeoutId);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-envelope me-1"></i>Email Now';
            st.innerHTML = '<span class="badge bg-danger">Error</span><div class="text-danger small mt-1">Email request failed. ' + err.message + '</div>';
        });
}
</script>
<?php include '../includes/footer.php'; ?>
