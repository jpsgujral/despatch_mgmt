<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_drivers', 'view');

/* ── Auto-create table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_drivers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    driver_code         VARCHAR(20),
    full_name           VARCHAR(120) NOT NULL,
    role                ENUM('Driver','Supervisor','Driver+Supervisor') DEFAULT 'Driver',
    phone               VARCHAR(20),
    phone2              VARCHAR(20),
    license_no          VARCHAR(40),
    license_expiry      DATE DEFAULT NULL,
    license_type        VARCHAR(40),
    assigned_vehicle_id INT DEFAULT NULL,
    basic_salary        DECIMAL(10,2) DEFAULT 0,
    address             TEXT,
    aadhar_no           VARCHAR(20),
    blood_group         VARCHAR(5),
    emergency_contact   VARCHAR(120),
    status              ENUM('Active','Inactive','Left') DEFAULT 'Active',
    join_date           DATE DEFAULT NULL,
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];

    // Check references across all fleet tables
    $refs = [];
    $chk = [
        'fleet_trips'          => ['driver_id',     'Trip Orders (as Driver)'],
        'fleet_trips_sup'      => ['supervisor_id',  'Trip Orders (as Supervisor)'],
        'fleet_fuel_log'       => ['driver_id',      'Fuel Entries'],
        'fleet_driver_salary'  => ['driver_id',      'Salary Records'],
    ];
    // Trips as driver
    $r = $db->query("SELECT COUNT(*) c FROM fleet_trips WHERE driver_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Trip Orders (as Driver)';
    // Trips as supervisor
    $r = $db->query("SELECT COUNT(*) c FROM fleet_trips WHERE supervisor_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Trip Orders (as Supervisor)';
    // Fuel log
    $r = $db->query("SELECT COUNT(*) c FROM fleet_fuel_log WHERE driver_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Fuel Entries';
    // Salary
    $r = $db->query("SELECT COUNT(*) c FROM fleet_driver_salary WHERE driver_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Salary Records';

    if (!empty($refs)) {
        showAlert('danger', 'Cannot delete — this driver has linked records in: ' . implode(', ', $refs) . '. Remove those records first or mark the driver as Left/Inactive instead.');
    } else {
        $db->query("DELETE FROM fleet_drivers WHERE id=$did");
        showAlert('success', 'Driver deleted permanently.');
    }
    redirect('fleet_drivers.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_driver'])) {
    requirePerm('fleet_drivers', $id > 0 ? 'update' : 'create');
    $name    = sanitize($_POST['full_name']);
    $code    = sanitize($_POST['driver_code'] ?? '');
    $role    = sanitize($_POST['role'] ?? 'Driver');
    $phone   = sanitize($_POST['phone'] ?? '');
    $phone2  = sanitize($_POST['phone2'] ?? '');
    $lic     = sanitize($_POST['license_no'] ?? '');
    $lic_exp = sanitize($_POST['license_expiry'] ?? '');
    $lic_typ = sanitize($_POST['license_type'] ?? '');
    $veh_id  = (int)($_POST['assigned_vehicle_id'] ?? 0);
    $salary  = (float)($_POST['basic_salary'] ?? 0);
    $addr    = sanitize($_POST['address'] ?? '');
    $aadhar  = sanitize($_POST['aadhar_no'] ?? '');
    $blood   = sanitize($_POST['blood_group'] ?? '');
    $emerg   = sanitize($_POST['emergency_contact'] ?? '');
    $status  = sanitize($_POST['status'] ?? 'Active');
    $join    = sanitize($_POST['join_date'] ?? '');
    $notes   = sanitize($_POST['notes'] ?? '');

    $lic_sql  = $lic_exp ? "'$lic_exp'" : 'NULL';
    $join_sql = $join    ? "'$join'"    : 'NULL';
    $veh_sql  = $veh_id  ? $veh_id      : 'NULL';

    if (!$name) { showAlert('danger','Full Name is required.'); redirect("fleet_drivers.php?action=$action&id=$id"); }

    if ($id > 0) {
        $db->query("UPDATE fleet_drivers SET
            full_name='$name', driver_code='$code', role='$role', phone='$phone', phone2='$phone2',
            license_no='$lic', license_expiry=$lic_sql, license_type='$lic_typ',
            assigned_vehicle_id=$veh_sql, basic_salary=$salary,
            address='$addr', aadhar_no='$aadhar', blood_group='$blood',
            emergency_contact='$emerg', status='$status', join_date=$join_sql, notes='$notes'
            WHERE id=$id");
        showAlert('success','Driver updated.');
    } else {
        $db->query("INSERT INTO fleet_drivers
            (full_name,driver_code,role,phone,phone2,license_no,license_expiry,license_type,
             assigned_vehicle_id,basic_salary,address,aadhar_no,blood_group,emergency_contact,
             status,join_date,notes)
            VALUES ('$name','$code','$role','$phone','$phone2','$lic',$lic_sql,'$lic_typ',
            $veh_sql,$salary,'$addr','$aadhar','$blood','$emerg','$status',$join_sql,'$notes')");
        showAlert('success','Driver added.');
    }
    redirect('fleet_drivers.php');
}

$vehicles = $db->query("SELECT id, reg_no, make, model FROM fleet_vehicles WHERE status='Active' ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-person-badge me-2"></i>Driver Master';</script>

<?php
$role_colors  = ['Driver'=>'primary','Supervisor'=>'success','Driver+Supervisor'=>'info'];
$status_colors= ['Active'=>'success','Inactive'=>'warning','Left'=>'secondary'];
$today  = date('Y-m-d');
$warn30 = date('Y-m-d', strtotime('+30 days'));

/* ── LIST ── */
if ($action === 'list'):
$drivers = $db->query("SELECT d.*, v.reg_no AS vehicle_reg
    FROM fleet_drivers d
    LEFT JOIN fleet_vehicles v ON d.assigned_vehicle_id = v.id
    ORDER BY d.status ASC, d.full_name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Driver Master</h5>
    <?php if (canDo('fleet_drivers','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Driver</a>
    <?php endif; ?>
</div>

<!-- License expiry alert -->
<?php
$lic_expiring = array_filter($drivers, fn($d) => $d['license_expiry'] && $d['license_expiry'] <= $warn30 && $d['status'] === 'Active');
if ($lic_expiring): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <span><strong><?= count($lic_expiring) ?> driver license(s)</strong> expiring within 30 days.</span>
</div>
<?php endif; ?>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Code</th><th>Name</th><th>Role</th><th>Phone</th>
    <th>License No</th><th>License Expiry</th><th>Vehicle</th>
    <th>Salary</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($drivers as $d):
    $rc = $role_colors[$d['role']] ?? 'secondary';
    $sc = $status_colors[$d['status']] ?? 'secondary';
    $lic_d = $d['license_expiry'];
    $lic_badge = $lic_d
        ? ($lic_d < $today ? "<span class='badge bg-danger'>".date('d/m/Y',strtotime($lic_d))."</span>"
          : ($lic_d <= $warn30 ? "<span class='badge bg-warning text-dark'>".date('d/m/Y',strtotime($lic_d))."</span>"
          : date('d/m/Y',strtotime($lic_d))))
        : '—';
?>
<tr>
    <td><?= htmlspecialchars($d['driver_code'] ?: '—') ?></td>
    <td><strong><?= htmlspecialchars($d['full_name']) ?></strong><br>
        <small class="text-muted"><?= htmlspecialchars($d['blood_group'] ?: '') ?></small></td>
    <td><span class="badge bg-<?= $rc ?>"><?= $d['role'] ?></span></td>
    <td><?= htmlspecialchars($d['phone']) ?></td>
    <td><?= htmlspecialchars($d['license_no'] ?: '—') ?></td>
    <td><?= $lic_badge ?></td>
    <td><?= $d['vehicle_reg'] ? '<span class="badge bg-dark">'.$d['vehicle_reg'].'</span>' : '—' ?></td>
    <td>₹<?= number_format($d['basic_salary'], 0) ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $d['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_drivers','update')): ?>
        <a href="?action=edit&id=<?= $d['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $d['id'] ?>" onclick="return confirm('Permanently delete driver <?= htmlspecialchars(addslashes($d['full_name'])) ?>?\n\nThis will fail if the driver has linked trips, fuel entries or salary records.\nTo retire a driver without deleting, edit and set Status to Left/Inactive.')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php
/* ── ADD / EDIT ── */
else:
$d = [];
if ($id > 0) $d = $db->query("SELECT * FROM fleet_drivers WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'Add' ?> Driver</h5>
    <a href="fleet_drivers.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_driver" value="1">
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-person-badge me-2"></i>Driver Information</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Driver Code</label>
        <input type="text" name="driver_code" class="form-control" value="<?= htmlspecialchars($d['driver_code'] ?? '') ?>" placeholder="D001">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Full Name *</label>
        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($d['full_name'] ?? '') ?>" required>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Role</label>
        <select name="role" class="form-select">
            <?php foreach (['Driver','Supervisor','Driver+Supervisor'] as $r): ?>
            <option <?= ($d['role'] ?? 'Driver') === $r ? 'selected' : '' ?>><?= $r ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <?php foreach (['Active','Inactive','Left'] as $s): ?>
            <option <?= ($d['status'] ?? 'Active') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($d['phone'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Phone 2</label>
        <input type="text" name="phone2" class="form-control" value="<?= htmlspecialchars($d['phone2'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Blood Group</label>
        <select name="blood_group" class="form-select">
            <option value="">—</option>
            <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-'] as $bg): ?>
            <option <?= ($d['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Join Date</label>
        <input type="date" name="join_date" class="form-control" value="<?= $d['join_date'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Basic Salary (₹/month)</label>
        <input type="number" name="basic_salary" class="form-control" step="0.01" value="<?= $d['basic_salary'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Assigned Vehicle</label>
        <select name="assigned_vehicle_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($vehicles as $veh): ?>
            <option value="<?= $veh['id'] ?>" <?= ($d['assigned_vehicle_id'] ?? 0) == $veh['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($veh['reg_no'].' '.$veh['make'].' '.$veh['model']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-card-text me-2"></i>License & Identity</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">License No</label>
        <input type="text" name="license_no" class="form-control" value="<?= htmlspecialchars($d['license_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">License Type</label>
        <input type="text" name="license_type" class="form-control" value="<?= htmlspecialchars($d['license_type'] ?? '') ?>" placeholder="HMV/Transport">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">License Expiry</label>
        <input type="date" name="license_expiry" class="form-control" value="<?= $d['license_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Aadhar No</label>
        <input type="text" name="aadhar_no" class="form-control" value="<?= htmlspecialchars($d['aadhar_no'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($d['address'] ?? '') ?></textarea>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Emergency Contact</label>
        <input type="text" name="emergency_contact" class="form-control" value="<?= htmlspecialchars($d['emergency_contact'] ?? '') ?>" placeholder="Name & Phone">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($d['notes'] ?? '') ?></textarea>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_drivers.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Driver</button>
</div>
</div>
</form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
