<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_salary', 'view');

/* ── Regular salary table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_driver_salary (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    driver_id       INT NOT NULL,
    salary_month    VARCHAR(7) NOT NULL,
    salary_from     DATE DEFAULT NULL,
    salary_to       DATE DEFAULT NULL,
    working_days    INT DEFAULT NULL,
    per_day_rate    DECIMAL(10,2) DEFAULT NULL,
    basic_salary    DECIMAL(10,2) DEFAULT 0,
    trip_count      INT DEFAULT 0,
    other_deductions DECIMAL(10,2) DEFAULT 0,
    other_allowances DECIMAL(10,2) DEFAULT 0,
    deduction_notes TEXT,
    allowance_notes TEXT,
    net_payable     DECIMAL(10,2) DEFAULT 0,
    paid_amount     DECIMAL(10,2) DEFAULT 0,
    payment_date    DATE DEFAULT NULL,
    payment_mode    VARCHAR(40) DEFAULT 'Cash',
    reference_no    VARCHAR(80),
    status          ENUM('Draft','Paid') DEFAULT 'Draft',
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_driver_month (driver_id, salary_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

(function($db){
    $c = $db->query("SHOW COLUMNS FROM fleet_driver_salary LIKE 'total_advance'");
    if ($c && $c->num_rows > 0)
        $db->query("ALTER TABLE fleet_driver_salary DROP COLUMN total_advance");
})($db);

/* ── Retro salary table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_driver_retro_salary (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    driver_id       INT NOT NULL,
    salary_month    VARCHAR(7) NOT NULL,
    held_amount     DECIMAL(10,2) DEFAULT 0,
    deduction_amount DECIMAL(10,2) DEFAULT 0,
    deduction_notes TEXT,
    released_amount DECIMAL(10,2) DEFAULT 0,
    release_date    DATE DEFAULT NULL,
    release_mode    VARCHAR(40) DEFAULT 'Cash',
    release_ref     VARCHAR(80) DEFAULT '',
    balance         DECIMAL(10,2) DEFAULT 0,
    status          ENUM('Holding','Partially Released','Released','Forfeited') DEFAULT 'Holding',
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_retro_driver_month (driver_id, salary_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Auto-migrate new columns
(function($db) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    foreach ([
        'salary_from'  => "DATE DEFAULT NULL",
        'salary_to'    => "DATE DEFAULT NULL",
        'working_days' => "INT DEFAULT NULL",
        'per_day_rate' => "DECIMAL(10,2) DEFAULT NULL",
    ] as $col => $def) {
        if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_driver_salary' AND COLUMN_NAME='$col' LIMIT 1")->num_rows)
            $db->query("ALTER TABLE fleet_driver_salary ADD COLUMN `$col` $def");
    }
})($db);

$action = $_GET['action'] ?? 'list';
$tab    = $_GET['tab']    ?? 'regular';
// FIX: read id from POST first so form submissions work correctly
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ── Fix duplicate/null driver codes ── */
(function($db) {
    $drivers = $db->query("SELECT id, driver_code FROM fleet_drivers ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $used = [];
    foreach ($drivers as $d) {
        $code = trim($d['driver_code'] ?? '');
        // Regenerate if null, empty, or duplicate
        if (!$code || in_array($code, $used)) {
            $newcode = 'D' . str_pad($d['id'], 3, '0', STR_PAD_LEFT);
            // ensure uniqueness
            $i = 1;
            $try = $newcode;
            while (in_array($try, $used)) { $try = $newcode . '_' . $i++; }
            $db->query("UPDATE fleet_drivers SET driver_code='".$db->real_escape_string($try)."' WHERE id=".$d['id']);
            $used[] = $try;
        } else {
            $used[] = $code;
        }
    }
})($db);

/* ── Regular: Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $db->query("DELETE FROM fleet_driver_salary WHERE id=".(int)$_GET['delete']." AND status='Draft'");
    showAlert('success','Salary record deleted.');
    redirect('fleet_salary.php?tab=regular');
}

/* ── Regular: Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_salary'])) {
    requirePerm('fleet_salary', $id > 0 ? 'update' : 'create');
    $drv_id  = (int)$_POST['driver_id'];
    $month       = sanitize($_POST['salary_month']);
    $sal_from    = sanitize($_POST['salary_from'] ?? '');
    $sal_to      = sanitize($_POST['salary_to'] ?? '');
    $working_days= (int)($_POST['working_days'] ?? 0);
    $per_day_rate= (float)($_POST['per_day_rate'] ?? 0);
    $sal_from_sql= $sal_from ? "'$sal_from'" : 'NULL';
    $sal_to_sql  = $sal_to   ? "'$sal_to'"   : 'NULL';
    $wd_sql      = $working_days > 0 ? $working_days : 'NULL';
    $pdr_sql     = $per_day_rate > 0 ? $per_day_rate : 'NULL';
    $basic   = (float)($_POST['basic_salary'] ?? 0);
    $ded     = (float)($_POST['other_deductions'] ?? 0);
    $allow   = (float)($_POST['other_allowances'] ?? 0);
    $ded_n   = sanitize($_POST['deduction_notes'] ?? '');
    $allow_n = sanitize($_POST['allowance_notes'] ?? '');
    $net     = $basic + $allow - $ded;
    $paid    = (float)($_POST['paid_amount'] ?? 0);
    $pdate   = sanitize($_POST['payment_date'] ?? '');
    $pmode   = sanitize($_POST['payment_mode'] ?? 'Cash');
    $pref    = sanitize($_POST['reference_no'] ?? '');
    $status  = sanitize($_POST['status'] ?? 'Draft');
    $remarks = sanitize($_POST['remarks'] ?? '');
    $pdate_sql = $pdate ? "'$pdate'" : 'NULL';

    if (!$drv_id || !$month) {
        showAlert('danger','Driver and Month are required.');
        redirect('fleet_salary.php?action='.($id>0?'edit&id='.$id:'add').'&tab=regular');
    }

    $trip_count = (int)($db->query("SELECT COUNT(*) c FROM fleet_trips
        WHERE driver_id=$drv_id AND DATE_FORMAT(trip_date,'%Y-%m')='$month'
        AND status='Completed'")->fetch_assoc()['c'] ?? 0);

    if ($id > 0) {
        // Check if another record already exists for this driver+month (excluding current)
        $dup = $db->query("SELECT id FROM fleet_driver_salary
            WHERE driver_id=$drv_id AND salary_month='$month' AND id!=$id LIMIT 1")->fetch_assoc();
        if ($dup) {
            showAlert('danger','Salary already exists for this driver and month.');
            redirect('fleet_salary.php?action=edit&id='.$id.'&tab=regular');
        }
        $res = $db->query("UPDATE fleet_driver_salary SET
            driver_id=$drv_id, salary_month='$month',
            salary_from=$sal_from_sql, salary_to=$sal_to_sql,
            working_days=$wd_sql, per_day_rate=$pdr_sql,
            basic_salary=$basic, trip_count=$trip_count,
            other_deductions=$ded, other_allowances=$allow,
            deduction_notes='$ded_n', allowance_notes='$allow_n', net_payable=$net,
            paid_amount=$paid, payment_date=$pdate_sql, payment_mode='$pmode',
            reference_no='$pref', status='$status', remarks='$remarks'
            WHERE id=$id");
        if (!$res) dmsLogError('DB_QUERY','Salary UPDATE failed: '.$db->error,__FILE__,__LINE__);
        showAlert('success','Salary updated.');
    } else {
        $check = $db->query("SELECT id FROM fleet_driver_salary
            WHERE driver_id=$drv_id AND salary_month='$month' LIMIT 1")->fetch_assoc();
        if ($check) {
            showAlert('danger','Salary already exists for this driver and month.');
            redirect('fleet_salary.php?action=add&tab=regular');
        }
        $res = $db->query("INSERT INTO fleet_driver_salary
            (driver_id,salary_month,salary_from,salary_to,working_days,per_day_rate,
             basic_salary,trip_count,other_deductions,other_allowances,
             deduction_notes,allowance_notes,net_payable,paid_amount,payment_date,
             payment_mode,reference_no,status,remarks)
            VALUES ($drv_id,'$month',$sal_from_sql,$sal_to_sql,$wd_sql,$pdr_sql,
            $basic,$trip_count,$ded,$allow,
            '$ded_n','$allow_n',$net,$paid,$pdate_sql,'$pmode','$pref','$status','$remarks')");
        if (!$res) dmsLogError('DB_QUERY','Salary INSERT failed: '.$db->error,__FILE__,__LINE__);
        showAlert('success','Salary record created.');
    }
    redirect('fleet_salary.php?tab=regular');
}

/* ── Retro: Delete ── */
if (isset($_GET['delete_retro']) && isAdmin()) {
    $db->query("DELETE FROM fleet_driver_retro_salary WHERE id=".(int)$_GET['delete_retro']." AND status='Holding'");
    showAlert('success','Retro record deleted.');
    redirect('fleet_salary.php?tab=retro');
}

/* ── Retro: Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retro'])) {
    requirePerm('fleet_salary', 'create');
    $rid      = (int)($_POST['retro_id'] ?? 0);
    $drv_id   = (int)$_POST['driver_id'];
    $month    = sanitize($_POST['retro_month']);
    $held     = (float)($_POST['held_amount'] ?? 0);
    $ded      = (float)($_POST['deduction_amount'] ?? 0);
    $ded_n    = sanitize($_POST['deduction_notes'] ?? '');
    $released = (float)($_POST['released_amount'] ?? 0);
    $rdate    = sanitize($_POST['release_date'] ?? '');
    $rmode    = sanitize($_POST['release_mode'] ?? 'Cash');
    $rref     = sanitize($_POST['release_ref'] ?? '');
    $remarks  = sanitize($_POST['remarks'] ?? '');
    $rdate_sql = $rdate ? "'$rdate'" : 'NULL';
    $balance  = $held - $ded - $released;

    if ($balance <= 0 && $held > 0)     $rstatus = 'Released';
    elseif ($released > 0)              $rstatus = 'Partially Released';
    elseif ($ded >= $held && $held > 0) $rstatus = 'Forfeited';
    else                                $rstatus = 'Holding';

    if ($rid > 0) {
        $res = $db->query("UPDATE fleet_driver_retro_salary SET
            driver_id=$drv_id, salary_month='$month', held_amount=$held,
            deduction_amount=$ded, deduction_notes='$ded_n',
            released_amount=$released, release_date=$rdate_sql,
            release_mode='$rmode', release_ref='$rref',
            balance=$balance, status='$rstatus', remarks='$remarks'
            WHERE id=$rid");
        if (!$res) dmsLogError('DB_QUERY','Retro UPDATE failed: '.$db->error,__FILE__,__LINE__);
        showAlert('success','Retro record updated.');
    } else {
        $check = $db->query("SELECT id FROM fleet_driver_retro_salary
            WHERE driver_id=$drv_id AND salary_month='$month' LIMIT 1")->fetch_assoc();
        if ($check) {
            showAlert('danger','Retro record already exists for this driver and month.');
            redirect('fleet_salary.php?action=add_retro&tab=retro');
        }
        $res = $db->query("INSERT INTO fleet_driver_retro_salary
            (driver_id,salary_month,held_amount,deduction_amount,deduction_notes,
             released_amount,release_date,release_mode,release_ref,balance,status,remarks)
            VALUES ($drv_id,'$month',$held,$ded,'$ded_n',
            $released,$rdate_sql,'$rmode','$rref',$balance,'$rstatus','$remarks')");
        if (!$res) dmsLogError('DB_QUERY','Retro INSERT failed: '.$db->error,__FILE__,__LINE__);
        showAlert('success','Retro record created.');
    }
    redirect('fleet_salary.php?tab=retro');
}

$drivers = $db->query("SELECT id,full_name,role,basic_salary FROM fleet_drivers
    WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-wallet2 me-2"></i>Driver Salary';</script>
<?php

$retro_status_colors = ['Holding'=>'warning','Partially Released'=>'info','Released'=>'success','Forfeited'=>'danger'];

/* ── LIST ── */
if ($action === 'list'):
$filter_mo  = sanitize($_GET['month'] ?? '');
$filter_drv = (int)($_GET['driver'] ?? 0);

$where = "WHERE 1";
if ($filter_mo) $where .= " AND s.salary_month='$filter_mo'";
if ($filter_drv) $where .= " AND s.driver_id=$filter_drv";
$records = $db->query("SELECT s.*, d.full_name, d.role
    FROM fleet_driver_salary s LEFT JOIN fleet_drivers d ON s.driver_id=d.id
    $where ORDER BY s.salary_month DESC, d.full_name")->fetch_all(MYSQLI_ASSOC);
$total_net  = array_sum(array_column($records,'net_payable'));
$total_paid = array_sum(array_column($records,'paid_amount'));

$rwhere = "WHERE 1";
if ($filter_mo) $rwhere .= " AND r.salary_month='$filter_mo'";
if ($filter_drv) $rwhere .= " AND r.driver_id=$filter_drv";
$retro_records = $db->query("SELECT r.*, d.full_name, d.role
    FROM fleet_driver_retro_salary r LEFT JOIN fleet_drivers d ON r.driver_id=d.id
    $rwhere ORDER BY r.salary_month DESC, d.full_name")->fetch_all(MYSQLI_ASSOC);
$total_held     = array_sum(array_column($retro_records,'held_amount'));
$total_deducted = array_sum(array_column($retro_records,'deduction_amount'));
$total_released = array_sum(array_column($retro_records,'released_amount'));
$total_balance  = array_sum(array_column($retro_records,'balance'));
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Driver Salary</h5>
    <div class="d-flex gap-2">
        <?php if (canDo('fleet_salary','create')): ?>
        <a href="?action=add&tab=regular" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Salary</a>
        <a href="?action=add_retro&tab=retro" class="btn btn-outline-warning btn-sm"><i class="bi bi-piggy-bank me-1"></i>Add Retro</a>
        <?php endif; ?>
    </div>
</div>
<div class="card mb-3"><div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
<input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $filter_mo ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Driver</label>
        <select name="driver" class="form-select form-select-sm">
            <option value="">All Drivers</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $filter_drv==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div></div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='regular'?'active':'' ?>" href="?tab=regular&month=<?= $filter_mo ?>&driver=<?= $filter_drv ?>">
            <i class="bi bi-wallet2 me-1"></i>Regular Salary <span class="badge bg-primary ms-1"><?= count($records) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='retro'?'active':'' ?>" href="?tab=retro&month=<?= $filter_mo ?>&driver=<?= $filter_drv ?>">
            <i class="bi bi-piggy-bank me-1"></i>Retro Salary <span class="badge bg-warning text-dark ms-1"><?= count($retro_records) ?></span>
        </a>
    </li>
</ul>

<?php if ($tab === 'regular'): ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Records</small><div class="fw-bold"><?= count($records) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Net Payable</small><div class="fw-bold text-primary">₹<?= number_format($total_net,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Paid</small><div class="fw-bold text-success">₹<?= number_format($total_paid,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Pending</small><div class="fw-bold text-danger">₹<?= number_format($total_net-$total_paid,2) ?></div></div></div>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr>
    <th>Driver</th><th>Role</th><th>Pending Months</th>
    <th class="text-end">Total Net</th><th class="text-end">Total Paid</th>
    <th class="text-end">Pending</th><th></th>
</tr></thead>
<tbody>
<?php
// Group records by driver
$grouped = [];
foreach ($records as $r) {
    $did = $r['driver_id'];
    if (!isset($grouped[$did])) {
        $grouped[$did] = ['full_name'=>$r['full_name'],'role'=>$r['role'],'rows'=>[],'net'=>0,'paid'=>0,'pending_months'=>0];
    }
    $grouped[$did]['rows'][]  = $r;
    $grouped[$did]['net']    += $r['net_payable'];
    $grouped[$did]['paid']   += $r['paid_amount'];
    if (((float)$r['net_payable'] - (float)$r['paid_amount']) > 0.009) {
        $grouped[$did]['pending_months']++;
    }
}
foreach ($grouped as $did => $g):
    $pending  = $g['net'] - $g['paid'];
    $pending_months = (int)($g['pending_months'] ?? 0);
?>
<tr class="table-light fw-semibold" style="cursor:pointer" onclick="toggleDriver(<?= $did ?>)">
    <td><i class="bi bi-chevron-down me-1 toggle-icon-<?= $did ?>"></i><?= htmlspecialchars($g['full_name']) ?></td>
    <td><span class="badge bg-secondary"><?= htmlspecialchars($g['role']) ?></span></td>
    <td><span class="badge bg-<?= $pending_months > 0 ? 'primary' : 'success' ?>"><?= $pending_months ?> month<?= $pending_months!==1?'s':'' ?></span></td>
    <td class="text-end">₹<?= number_format($g['net'],0) ?></td>
    <td class="text-end text-success">₹<?= number_format($g['paid'],0) ?></td>
    <td class="text-end <?= $pending>0?'text-danger':'text-muted' ?>">₹<?= number_format($pending,0) ?></td>
    <td></td>
</tr>
<?php foreach ($g['rows'] as $r): ?>
<tr class="driver-rows-<?= $did ?>" style="display:none;background:#f9fffe">
    <td class="ps-4 text-muted" style="font-size:.85rem">
        <?= $r['salary_month'] ?>
        <?php if (!empty($r['salary_from']) && !empty($r['salary_to'])): ?>
        <br><small class="text-info"><?= date('d/m',strtotime($r['salary_from'])) ?> – <?= date('d/m',strtotime($r['salary_to'])) ?></small>
        <?php if (!empty($r['working_days'])): ?>
        <span class="badge bg-light text-dark ms-1"><?= $r['working_days'] ?>d</span>
        <?php endif; ?>
        <?php endif; ?>
    </td>
    <td></td>
    <td><span class="badge bg-<?= $r['status']==='Paid'?'success':'warning text-dark' ?>"><?= $r['status'] ?></span></td>
    <td class="text-end">₹<?= number_format($r['net_payable'],0) ?></td>
    <td class="text-end text-success">₹<?= number_format($r['paid_amount'],0) ?></td>
    <td class="text-end <?= ($r['net_payable']-$r['paid_amount'])>0?'text-danger':'text-muted' ?>">₹<?= number_format($r['net_payable']-$r['paid_amount'],0) ?></td>
    <td>
        <?php if (canDo('fleet_salary','update')): ?>
        <a href="?action=edit&id=<?= $r['id'] ?>&tab=regular" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin() && $r['status']==='Draft'): ?>
        <a href="?delete=<?= $r['id'] ?>" onclick="return confirm('Delete salary record?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if (empty($grouped)): ?>
<tr><td colspan="7" class="text-center text-muted py-4">No salary records found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div></div>
<script>
function toggleDriver(did) {
    var rows = document.querySelectorAll('.driver-rows-' + did);
    var icon = document.querySelector('.toggle-icon-' + did);
    var hidden = rows[0] && rows[0].style.display === 'none';
    rows.forEach(function(r) { r.style.display = hidden ? '' : 'none'; });
    if (icon) { icon.className = hidden ? 'bi bi-chevron-up me-1 toggle-icon-'+did : 'bi bi-chevron-down me-1 toggle-icon-'+did; }
}
</script>

<?php else: /* RETRO */ ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Held</small><div class="fw-bold text-warning">₹<?= number_format($total_held,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Deducted</small><div class="fw-bold text-danger">₹<?= number_format($total_deducted,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Released</small><div class="fw-bold text-success">₹<?= number_format($total_released,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Balance Held</small><div class="fw-bold text-primary">₹<?= number_format($total_balance,2) ?></div></div></div>
</div>
<div class="alert alert-warning py-2 mb-3"><i class="bi bi-info-circle me-1"></i>
    <strong>Retro Salary</strong> — Monthly salary amounts held back as security against traffic challans, accidents, or other liabilities.
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr>
    <th>Driver</th><th>Month</th><th class="text-end">Held</th><th class="text-end">Deducted</th>
    <th>Deduction Reason</th><th class="text-end">Released</th><th class="text-end">Balance</th>
    <th>Release Date</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($retro_records as $r): ?>
<tr>
    <td class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= $r['salary_month'] ?></td>
    <td class="text-end text-warning fw-bold">₹<?= number_format($r['held_amount'],0) ?></td>
    <td class="text-end text-danger">₹<?= number_format($r['deduction_amount'],0) ?></td>
    <td><small><?= htmlspecialchars($r['deduction_notes']??'—') ?></small></td>
    <td class="text-end text-success">₹<?= number_format($r['released_amount'],0) ?></td>
    <td class="text-end fw-bold <?= $r['balance']>0?'text-primary':'text-muted' ?>">₹<?= number_format($r['balance'],0) ?></td>
    <td><?= $r['release_date'] ? date('d/m/Y',strtotime($r['release_date'])) : '—' ?></td>
    <td><span class="badge bg-<?= $retro_status_colors[$r['status']]??'secondary' ?>"><?= $r['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_salary','update')): ?>
        <a href="?action=edit_retro&id=<?= $r['id'] ?>&tab=retro" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin() && $r['status']==='Holding'): ?>
        <a href="?delete_retro=<?= $r['id'] ?>" onclick="return confirm('Delete retro record?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($retro_records)): ?>
<tr><td colspan="10" class="text-center text-muted py-4">No retro records for this month.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div></div>

<?php
$retro_summary = $db->query("SELECT d.full_name, d.role,
    COUNT(r.id) months_count, SUM(r.held_amount) total_held,
    SUM(r.deduction_amount) total_deducted, SUM(r.released_amount) total_released,
    SUM(r.balance) total_balance
    FROM fleet_driver_retro_salary r LEFT JOIN fleet_drivers d ON r.driver_id=d.id
    WHERE r.status IN ('Holding','Partially Released')
    GROUP BY r.driver_id ORDER BY total_balance DESC")->fetch_all(MYSQLI_ASSOC);
if (!empty($retro_summary)):
?>
<div class="card mt-3">
<div class="card-header fw-semibold"><i class="bi bi-piggy-bank me-2"></i>All-Time Retro Balance (Active Holdings)</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
<thead class="table-light"><tr>
    <th>Driver</th><th class="text-end">Months</th><th class="text-end">Total Held</th>
    <th class="text-end">Deducted</th><th class="text-end">Released</th><th class="text-end">Balance</th>
</tr></thead>
<tbody>
<?php foreach ($retro_summary as $rs): ?>
<tr>
    <td><?= htmlspecialchars($rs['full_name']) ?> <span class="badge bg-secondary ms-1"><?= $rs['role'] ?></span></td>
    <td class="text-end"><?= $rs['months_count'] ?></td>
    <td class="text-end text-warning">₹<?= number_format($rs['total_held'],0) ?></td>
    <td class="text-end text-danger">₹<?= number_format($rs['total_deducted'],0) ?></td>
    <td class="text-end text-success">₹<?= number_format($rs['total_released'],0) ?></td>
    <td class="text-end fw-bold text-primary">₹<?= number_format($rs['total_balance'],0) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>
<?php endif; ?>

<?php
/* ── ADD/EDIT REGULAR ── */
elseif ($action === 'add' || $action === 'edit'):
$r = [];
if ($id > 0) $r = $db->query("SELECT * FROM fleet_driver_salary WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Salary Record</h5>
    <a href="fleet_salary.php?tab=regular" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_salary" value="1">
<input type="hidden" name="id" value="<?= $id ?>">
<div class="row g-3">
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-wallet2 me-2"></i>Salary Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-4">
        <label class="form-label fw-bold">Driver *</label>
        <select name="driver_id" class="form-select" required onchange="fillBasic(this)">
            <option value="">— Select Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" data-salary="<?= $d['basic_salary'] ?>"
                <?= ($r['driver_id']??0)==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= $d['role'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Month *</label>
        <input type="month" name="salary_month" id="salaryMonth" class="form-control" value="<?= $r['salary_month']??date('Y-m') ?>" required onchange="fillFromTo()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">From Date</label>
        <input type="date" name="salary_from" id="salaryFrom" class="form-control" value="<?= $r['salary_from']??'' ?>" onchange="calcWorkingDays()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">To Date</label>
        <input type="date" name="salary_to" id="salaryTo" class="form-control" value="<?= $r['salary_to']??'' ?>" onchange="calcWorkingDays()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Working Days</label>
        <input type="number" name="working_days" id="workingDays" class="form-control" min="1" max="31"
               value="<?= $r['working_days']??'' ?>" placeholder="Auto" oninput="calcBasicFromDays()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Per Day Rate (₹)</label>
        <input type="number" name="per_day_rate" id="perDayRate" class="form-control" step="0.01"
               value="<?= $r['per_day_rate']??'' ?>" placeholder="Auto from basic" oninput="calcBasicFromDays()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Basic Salary (₹)</label>
        <input type="number" name="basic_salary" id="basicSalary" class="form-control" step="0.01"
               value="<?= $r['basic_salary']??'' ?>" oninput="calcNet()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Other Deductions (₹)</label>
        <input type="number" name="other_deductions" id="otherDed" class="form-control" step="0.01"
               value="<?= $r['other_deductions']??0 ?>" oninput="calcNet()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Other Allowances (₹)</label>
        <input type="number" name="other_allowances" id="otherAllow" class="form-control" step="0.01"
               value="<?= $r['other_allowances']??0 ?>" oninput="calcNet()">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Deduction Notes</label>
        <input type="text" name="deduction_notes" class="form-control" value="<?= htmlspecialchars($r['deduction_notes']??'') ?>">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Allowance Notes</label>
        <input type="text" name="allowance_notes" class="form-control" value="<?= htmlspecialchars($r['allowance_notes']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Net Payable (₹)</label>
        <input type="number" id="netPayable" class="form-control bg-light fw-bold" readonly value="<?= $r['net_payable']??0 ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-cash me-2"></i>Payment Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Paid Amount (₹)</label>
        <input type="number" name="paid_amount" class="form-control" step="0.01" value="<?= $r['paid_amount']??0 ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Date</label>
        <input type="date" name="payment_date" class="form-control" value="<?= $r['payment_date']??'' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['Cash','NEFT','RTGS','Cheque','UPI'] as $m): ?>
            <option <?= ($r['payment_mode']??'Cash')===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Reference No</label>
        <input type="text" name="reference_no" class="form-control" value="<?= htmlspecialchars($r['reference_no']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="Draft" <?= ($r['status']??'Draft')==='Draft'?'selected':'' ?>>Draft</option>
            <option value="Paid"  <?= ($r['status']??'')==='Paid'?'selected':'' ?>>Paid</option>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Remarks</label>
        <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($r['remarks']??'') ?></textarea>
    </div>
    <div class="col-12 text-end">
        <a href="fleet_salary.php?tab=regular" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Salary</button>
    </div>
</div></div></div></div>
</div>
</form>
<script>
function fillBasic(sel) {
    var sal = parseFloat(sel.options[sel.selectedIndex].getAttribute('data-salary')) || 0;
    if (sal > 0) {
        document.getElementById('basicSalary').value = sal.toFixed(2);
        // Set per_day_rate = basic / days_in_month
        var mon = document.getElementById('salaryMonth').value;
        if (mon) {
            var d = new Date(mon + '-01');
            var daysInMonth = new Date(d.getFullYear(), d.getMonth()+1, 0).getDate();
            document.getElementById('perDayRate').value = (sal / daysInMonth).toFixed(2);
        }
        calcNet();
    }
}
function fillFromTo() {
    var mon = document.getElementById('salaryMonth').value;
    if (!mon) return;
    var d = new Date(mon + '-01');
    var lastDay = new Date(d.getFullYear(), d.getMonth()+1, 0).getDate();
    document.getElementById('salaryFrom').value = mon + '-01';
    document.getElementById('salaryTo').value   = mon + '-' + String(lastDay).padStart(2,'0');
    calcWorkingDays();
}
function calcWorkingDays() {
    var from = document.getElementById('salaryFrom').value;
    var to   = document.getElementById('salaryTo').value;
    if (!from || !to) return;
    var f = new Date(from), t = new Date(to);
    if (t < f) return;
    var days = Math.round((t - f) / 86400000) + 1;
    document.getElementById('workingDays').value = days;
    calcBasicFromDays();
}
function calcBasicFromDays() {
    var days = parseFloat(document.getElementById('workingDays').value) || 0;
    var pdr  = parseFloat(document.getElementById('perDayRate').value)  || 0;
    if (days > 0 && pdr > 0) {
        document.getElementById('basicSalary').value = (days * pdr).toFixed(2);
    }
    calcNet();
}
function calcNet() {
    var basic = parseFloat(document.getElementById('basicSalary').value) || 0;
    var ded   = parseFloat(document.getElementById('otherDed').value)    || 0;
    var allow = parseFloat(document.getElementById('otherAllow').value)  || 0;
    document.getElementById('netPayable').value = (basic + allow - ded).toFixed(2);
}
</script>

<?php
/* ── ADD/EDIT RETRO ── */
elseif ($action === 'add_retro' || $action === 'edit_retro'):
$r = []; $retro_id = 0;
if ($action === 'edit_retro' && $id > 0)
    $r = $db->query("SELECT * FROM fleet_driver_retro_salary WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
if (!empty($r)) $retro_id = $r['id'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $retro_id>0?'Edit':'Add' ?> Retro Salary</h5>
    <a href="fleet_salary.php?tab=retro" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_retro" value="1">
<input type="hidden" name="retro_id" value="<?= $retro_id ?>">
<div class="row g-3">
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-piggy-bank me-2"></i>Retro Salary Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-4">
        <label class="form-label fw-bold">Driver *</label>
        <select name="driver_id" class="form-select" required>
            <option value="">— Select Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($r['driver_id']??0)==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= $d['role'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Month *</label>
        <input type="month" name="retro_month" class="form-control" value="<?= $r['salary_month']??date('Y-m') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount Held (₹)</label>
        <input type="number" name="held_amount" id="retroHeld" class="form-control" step="0.01"
               value="<?= $r['held_amount']??0 ?>" oninput="calcRetroBalance()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Deduction (₹)</label>
        <input type="number" name="deduction_amount" id="retroDed" class="form-control" step="0.01"
               value="<?= $r['deduction_amount']??0 ?>" oninput="calcRetroBalance()">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Deduction Notes</label>
        <input type="text" name="deduction_notes" class="form-control" value="<?= htmlspecialchars($r['deduction_notes']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Released Amount (₹)</label>
        <input type="number" name="released_amount" id="retroRel" class="form-control" step="0.01"
               value="<?= $r['released_amount']??0 ?>" oninput="calcRetroBalance()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Balance (₹)</label>
        <input type="number" id="retroBal" class="form-control bg-light fw-bold" readonly value="<?= $r['balance']??0 ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Release Date</label>
        <input type="date" name="release_date" class="form-control" value="<?= $r['release_date']??'' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Release Mode</label>
        <select name="release_mode" class="form-select">
            <?php foreach (['Cash','NEFT','RTGS','Cheque','UPI'] as $m): ?>
            <option <?= ($r['release_mode']??'Cash')===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Release Reference</label>
        <input type="text" name="release_ref" class="form-control" value="<?= htmlspecialchars($r['release_ref']??'') ?>">
    </div>
    <div class="col-12">
        <label class="form-label">Remarks</label>
        <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($r['remarks']??'') ?></textarea>
    </div>
    <div class="col-12 text-end">
        <a href="fleet_salary.php?tab=retro" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-warning px-4"><i class="bi bi-check2 me-1"></i>Save Retro</button>
    </div>
</div></div></div></div>
</div>
</form>
<script>
function calcRetroBalance() {
    var held = parseFloat(document.getElementById('retroHeld').value) || 0;
    var ded  = parseFloat(document.getElementById('retroDed').value)  || 0;
    var rel  = parseFloat(document.getElementById('retroRel').value)  || 0;
    document.getElementById('retroBal').value = (held - ded - rel).toFixed(2);
}
</script>

<?php endif; ?>
<?php include '../includes/footer.php'; ?>

