<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('reports', 'view');

// Financial Year: 1 Apr to 31 Mar
// Determine current FY
$cur_month = (int)date('n');
$cur_year  = (int)date('Y');
$cur_fy    = $cur_month >= 4 ? $cur_year : $cur_year - 1; // FY starts April

// Selected FY
$sel_fy = (int)($_GET['fy'] ?? $cur_fy);
$fy_start  = $sel_fy . '-04-01';
$fy_end    = ($sel_fy + 1) . '-03-31';
$fy_label  = 'FY ' . $sel_fy . '-' . substr($sel_fy+1, 2);

// Build FY options (last 5 years)
$fy_options = [];
for ($y = $cur_fy; $y >= $cur_fy - 4; $y--) {
    $fy_options[] = $y;
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-calendar-range me-2"></i>Yearly Report';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Yearly Report — <?= $fy_label ?> <small class="text-muted fw-normal">(<?= date('d M Y', strtotime($fy_start)) ?> to <?= date('d M Y', strtotime($fy_end)) ?>)</small></h5>
    <form class="d-flex gap-2" method="GET">
        <select name="fy" class="form-select form-select-sm">
            <?php foreach ($fy_options as $y): ?>
            <option value="<?= $y ?>" <?= $y===$sel_fy?'selected':'' ?>>FY <?= $y ?>-<?= substr($y+1,2) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>View</button>
    </form>
</div>

<?php
// ── DATA ──
// Month-wise despatch for chart
$monthly_desp = $db->query("SELECT DATE_FORMAT(despatch_date,'%Y-%m') ym,
    COUNT(*) c, SUM(total_weight) mt, SUM(freight_amount) frt
    FROM despatch_orders
    WHERE despatch_date BETWEEN '$fy_start' AND '$fy_end' AND status!='Cancelled'
    GROUP BY ym ORDER BY ym")->fetch_all(MYSQLI_ASSOC);

// Summary totals
$desp = $db->query("SELECT COUNT(*) c, SUM(total_weight) mt, SUM(freight_amount) frt,
    SUM(total_amount) val,
    SUM(status='Delivered') delivered, SUM(status='Cancelled') cancelled
    FROM despatch_orders
    WHERE despatch_date BETWEEN '$fy_start' AND '$fy_end'")->fetch_assoc();

// Vendor-wise
$desp_vendors = $db->query("SELECT COALESCE(v.vendor_name,'Unknown') vn,
    COUNT(*) c, SUM(d.total_weight) mt, SUM(d.freight_amount) frt, SUM(d.total_amount) val
    FROM despatch_orders d LEFT JOIN vendors v ON d.vendor_id=v.id
    WHERE d.despatch_date BETWEEN '$fy_start' AND '$fy_end' AND d.status!='Cancelled'
    GROUP BY d.vendor_id ORDER BY mt DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);

// Sales
$sales = $db->query("SELECT COUNT(*) c, SUM(total_amount) val,
    SUM(cgst_amount+sgst_amount+igst_amount) gst,
    SUM(IF(status='Paid',total_amount,0)) paid,
    SUM(IF(status!='Paid',total_amount,0)) outstanding
    FROM sales_invoices
    WHERE invoice_date BETWEEN '$fy_start' AND '$fy_end'")->fetch_assoc();

// Month-wise sales
$monthly_sales = $db->query("SELECT DATE_FORMAT(invoice_date,'%Y-%m') ym,
    COUNT(*) c, SUM(total_amount) val
    FROM sales_invoices
    WHERE invoice_date BETWEEN '$fy_start' AND '$fy_end'
    GROUP BY ym ORDER BY ym")->fetch_all(MYSQLI_ASSOC);

// Fleet
$fleet = $db->query("SELECT COUNT(*) c, SUM(total_weight) mt, SUM(freight_amount) frt,
    SUM(status='Completed') completed
    FROM fleet_trips WHERE trip_date BETWEEN '$fy_start' AND '$fy_end'")->fetch_assoc();

// Vehicle-wise
$veh_trips = $db->query("SELECT v.reg_no, CONCAT(v.make,' ',v.model) vehicle,
    COUNT(*) c, SUM(t.total_weight) mt, SUM(t.freight_amount) frt
    FROM fleet_trips t LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    WHERE t.trip_date BETWEEN '$fy_start' AND '$fy_end' AND t.status!='Cancelled'
    GROUP BY t.vehicle_id ORDER BY frt DESC")->fetch_all(MYSQLI_ASSOC);

// Fuel annual
$fuel = $db->query("SELECT SUM(litres) litres, SUM(amount) cost,
    SUM(COALESCE(driver_advance,0)) advance
    FROM fleet_fuel_log
    WHERE fuel_date BETWEEN '$fy_start' AND '$fy_end' AND payment_mode='Credit'")->fetch_assoc();

// Expenses annual
$expenses = $db->query("SELECT expense_type, SUM(amount) total
    FROM fleet_expenses WHERE expense_date BETWEEN '$fy_start' AND '$fy_end'
    GROUP BY expense_type ORDER BY total DESC")->fetch_all(MYSQLI_ASSOC);

// Transporter payments
$trans_pay = $db->query("SELECT SUM(amount) total, COUNT(*) c FROM transporter_payments
    WHERE payment_date BETWEEN '$fy_start' AND '$fy_end' AND status='Paid'")->fetch_assoc();

// Driver salary
$salary = $db->query("SELECT SUM(net_payable) payable, SUM(paid_amount) paid, COUNT(*) c
    FROM fleet_driver_salary
    WHERE salary_month BETWEEN '".date('Y-m',strtotime($fy_start))."' AND '".date('Y-m',strtotime($fy_end))."'")->fetch_assoc();

// Build month labels for chart
$months_in_fy = [];
for ($m = 4; $m <= 15; $m++) {
    $actual_month = (($m - 1) % 12) + 1;
    $actual_year  = $sel_fy + ($m > 12 ? 1 : 0);
    $months_in_fy[] = sprintf('%d-%02d', $actual_year, $actual_month);
}

// Index monthly data
$desp_by_month  = array_column($monthly_desp, null, 'ym');
$sales_by_month = array_column($monthly_sales, null, 'ym');

function rCard($title, $val, $sub='', $color='#1a5632') {
    return '<div class="col-6 col-md-3"><div class="card p-3 text-center h-100" style="border-top:3px solid '.$color.'">
        <div class="text-muted small mb-1">'.htmlspecialchars($title).'</div>
        <div class="fw-bold fs-5" style="color:'.$color.'">'.htmlspecialchars($val).'</div>
        '.($sub ? '<div class="text-muted" style="font-size:.75rem">'.htmlspecialchars($sub).'</div>' : '').'
    </div></div>';
}
?>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <?= rCard('Total Challans', number_format((int)$desp['c']), 'Delivered: '.(int)$desp['delivered']) ?>
    <?= rCard('Total MT Despatched', number_format((float)$desp['mt'],3), '', '#0d6efd') ?>
    <?= rCard('Total Freight', '₹'.number_format((float)$desp['frt']), '', '#27ae60') ?>
    <?= rCard('Sales Value', '₹'.number_format((float)$sales['val']), 'GST: ₹'.number_format((float)$sales['gst']), '#8e44ad') ?>
    <?= rCard('Fleet Trips', (int)$fleet['c'], 'MT: '.number_format((float)$fleet['mt'],3)) ?>
    <?= rCard('Fleet Freight', '₹'.number_format((float)$fleet['frt']), '', '#e67e22') ?>
    <?= rCard('Fuel Cost', '₹'.number_format((float)($fuel['cost']??0)), number_format((float)($fuel['litres']??0),2).' L', '#e74c3c') ?>
    <?= rCard('Outstanding Sales', '₹'.number_format((float)$sales['outstanding']), '', '#c0392b') ?>
</div>

<!-- Month-wise chart -->
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-bar-chart me-2"></i>Month-wise Performance — <?= $fy_label ?></div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead style="background:#1a5632;color:#fff">
                <tr><th>Month</th><th class="text-end">Challans</th><th class="text-end">MT Despatched</th><th class="text-end">Desp Freight</th><th class="text-end">Invoices</th><th class="text-end">Sales Value</th></tr>
            </thead>
            <tbody>
            <?php
            $tot_c=$tot_mt=$tot_frt=$tot_inv=$tot_sal=0;
            foreach ($months_in_fy as $i => $ym):
                $d = $desp_by_month[$ym] ?? null;
                $s = $sales_by_month[$ym] ?? null;
                $bg = $i%2 ? 'background:#f9f9f9' : '';
                $tot_c+=$d['c']??0; $tot_mt+=$d['mt']??0; $tot_frt+=$d['frt']??0;
                $tot_inv+=$s['c']??0; $tot_sal+=$s['val']??0;
            ?>
            <tr style="<?= $bg ?>">
                <td class="fw-semibold"><?= date('M Y', strtotime($ym.'-01')) ?></td>
                <td class="text-end"><?= $d ? (int)$d['c'] : '—' ?></td>
                <td class="text-end text-primary"><?= $d ? number_format((float)$d['mt'],3) : '—' ?></td>
                <td class="text-end text-success"><?= $d ? '₹'.number_format((float)$d['frt']) : '—' ?></td>
                <td class="text-end"><?= $s ? (int)$s['c'] : '—' ?></td>
                <td class="text-end text-info fw-semibold"><?= $s ? '₹'.number_format((float)$s['val']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot style="background:#e5f5eb;font-weight:bold">
                <tr>
                    <td>TOTAL</td>
                    <td class="text-end"><?= number_format($tot_c) ?></td>
                    <td class="text-end text-primary"><?= number_format($tot_mt,3) ?></td>
                    <td class="text-end text-success">₹<?= number_format($tot_frt) ?></td>
                    <td class="text-end"><?= number_format($tot_inv) ?></td>
                    <td class="text-end text-info">₹<?= number_format($tot_sal) ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<!-- Vendor-wise -->
<?php if ($desp_vendors): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-building me-2"></i>Vendor-wise Despatch — <?= $fy_label ?></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead style="background:#1a5632;color:#fff"><tr><th>Vendor</th><th class="text-end">Challans</th><th class="text-end">MT</th><th class="text-end">Freight</th><th class="text-end">Value</th></tr></thead>
        <tbody>
        <?php $tt_mt=$tt_frt=$tt_val=0; foreach ($desp_vendors as $i => $r): $tt_mt+=$r['mt']; $tt_frt+=$r['frt']; $tt_val+=$r['val']; ?>
        <tr style="<?= $i%2?'background:#f9f9f9':'' ?>">
            <td class="fw-semibold"><?= htmlspecialchars($r['vn']) ?></td>
            <td class="text-end"><?= (int)$r['c'] ?></td>
            <td class="text-end text-primary"><?= number_format((float)$r['mt'],3) ?></td>
            <td class="text-end text-success">₹<?= number_format((float)$r['frt']) ?></td>
            <td class="text-end">₹<?= number_format((float)$r['val']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot style="background:#e5f5eb;font-weight:bold"><tr>
            <td>TOTAL</td><td></td>
            <td class="text-end text-primary"><?= number_format($tt_mt,3) ?></td>
            <td class="text-end text-success">₹<?= number_format($tt_frt) ?></td>
            <td class="text-end">₹<?= number_format($tt_val) ?></td>
        </tr></tfoot>
    </table></div>
</div>
<?php endif; ?>

<!-- Fleet Vehicle-wise -->
<?php if ($veh_trips): ?>
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-truck me-2"></i>Vehicle-wise Performance — <?= $fy_label ?></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead style="background:#1a5632;color:#fff"><tr><th>Reg No</th><th>Vehicle</th><th class="text-end">Trips</th><th class="text-end">MT</th><th class="text-end">Freight</th></tr></thead>
        <tbody>
        <?php foreach ($veh_trips as $i => $r): ?>
        <tr style="<?= $i%2?'background:#f9f9f9':'' ?>">
            <td class="fw-bold text-primary"><?= htmlspecialchars($r['reg_no']) ?></td>
            <td class="text-muted small"><?= htmlspecialchars($r['vehicle']) ?></td>
            <td class="text-end"><?= (int)$r['c'] ?></td>
            <td class="text-end text-primary"><?= number_format((float)$r['mt'],3) ?></td>
            <td class="text-end text-success fw-semibold">₹<?= number_format((float)$r['frt']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<!-- Financial Summary -->
<div class="card mb-3">
    <div class="card-header fw-bold" style="background:#1a5632;color:#fff"><i class="bi bi-cash-coin me-2"></i>Financial Summary — <?= $fy_label ?></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead><tr><th colspan="2" class="text-muted">Revenue</th></tr></thead>
                    <tr><td>Despatch Freight Earned</td><td class="text-end fw-bold text-success">₹<?= number_format((float)$desp['frt']) ?></td></tr>
                    <tr class="table-light"><td>Sales Invoice Value</td><td class="text-end fw-bold text-success">₹<?= number_format((float)$sales['val']) ?></td></tr>
                    <tr><td>Sales GST Collected</td><td class="text-end fw-bold text-info">₹<?= number_format((float)$sales['gst']) ?></td></tr>
                    <tr class="table-light"><td>Fleet Freight Earned</td><td class="text-end fw-bold text-success">₹<?= number_format((float)$fleet['frt']) ?></td></tr>
                    <tr style="background:#e5f5eb"><td class="fw-bold">Sales Outstanding</td><td class="text-end fw-bold text-danger">₹<?= number_format((float)$sales['outstanding']) ?></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead><tr><th colspan="2" class="text-muted">Expenses</th></tr></thead>
                    <tr><td>Transporter Payments</td><td class="text-end fw-bold text-danger">₹<?= number_format((float)$trans_pay['total']) ?></td></tr>
                    <tr class="table-light"><td>Fuel Cost (Credit)</td><td class="text-end fw-bold text-danger">₹<?= number_format((float)($fuel['cost']??0)) ?></td></tr>
                    <tr><td>Driver Advances</td><td class="text-end fw-bold text-warning">₹<?= number_format((float)($fuel['advance']??0)) ?></td></tr>
                    <?php $total_exp = array_sum(array_column($expenses,'total')); ?>
                    <tr class="table-light"><td>Vehicle Expenses</td><td class="text-end fw-bold text-danger">₹<?= number_format($total_exp) ?></td></tr>
                    <?php if ($salary && $salary['c']): ?>
                    <tr><td>Driver Salary Paid</td><td class="text-end fw-bold text-danger">₹<?= number_format((float)$salary['paid']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
