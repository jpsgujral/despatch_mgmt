<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_vehicles', 'view');
$is_lease_agent_user = isLeaseAgentUser();
$current_lease_agent_id = currentLeaseAgentId();

/* ── Auto-create tables ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_vehicles (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    reg_no                  VARCHAR(20) NOT NULL UNIQUE,
    make                    VARCHAR(80),
    model                   VARCHAR(80),
    year                    YEAR,
    capacity_tons           DECIMAL(8,2) DEFAULT 0,
    fuel_type               VARCHAR(20) DEFAULT 'Diesel',
    default_driver_id       INT DEFAULT NULL,
    lease_agent_id          INT DEFAULT NULL,
    status                  ENUM('Active','In Repair','Idle','Disposed') DEFAULT 'Active',
    insurance_no            VARCHAR(80),
    insurance_expiry        DATE DEFAULT NULL,
    fitness_expiry          DATE DEFAULT NULL,
    permit_expiry           DATE DEFAULT NULL,
    puc_expiry              DATE DEFAULT NULL,
    national_permit_expiry  DATE DEFAULT NULL,
    road_tax_expiry         DATE DEFAULT NULL,
    chassis_no              VARCHAR(80),
    engine_no               VARCHAR(80),
    has_jack                TINYINT(1) DEFAULT 0,
    has_wheel_rod           TINYINT(1) DEFAULT 0,
    tyre_count              INT DEFAULT 0,
    rim_count               INT DEFAULT 0,
    spare_tyre_count        INT DEFAULT 0,
    spare_tyre_with_rim_count INT DEFAULT 0,
    tool_kit                TINYINT(1) DEFAULT 0,
    fire_extinguisher       TINYINT(1) DEFAULT 0,
    first_aid_kit           TINYINT(1) DEFAULT 0,
    warning_triangle        TINYINT(1) DEFAULT 0,
    wheel_chocks            TINYINT(1) DEFAULT 0,
    spare_key_count         INT DEFAULT 0,
    accessories             TEXT,
    notes                   TEXT,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Auto-migrate ── */
(function($db) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    foreach ([
        'state_permit_no'    => "VARCHAR(80) DEFAULT ''",
        'national_permit_no' => "VARCHAR(80) DEFAULT ''",
        'default_driver_id'  => "INT DEFAULT NULL",
        'lease_agent_id'     => "INT DEFAULT NULL",
        'has_jack'           => "TINYINT(1) DEFAULT 0",
        'has_wheel_rod'      => "TINYINT(1) DEFAULT 0",
        'tyre_count'         => "INT DEFAULT 0",
        'rim_count'          => "INT DEFAULT 0",
        'spare_tyre_count'   => "INT DEFAULT 0",
        'spare_tyre_with_rim_count' => "INT DEFAULT 0",
        'tool_kit'           => "TINYINT(1) DEFAULT 0",
        'fire_extinguisher'  => "TINYINT(1) DEFAULT 0",
        'first_aid_kit'      => "TINYINT(1) DEFAULT 0",
        'warning_triangle'   => "TINYINT(1) DEFAULT 0",
        'wheel_chocks'       => "TINYINT(1) DEFAULT 0",
        'spare_key_count'    => "INT DEFAULT 0",
        'accessories'        => "TEXT",
        'road_tax_expiry'    => "DATE DEFAULT NULL",
    ] as $col => $def) {
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_vehicles'
            AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
        if (!$exists) $db->query("ALTER TABLE fleet_vehicles ADD COLUMN `$col` $def");
    }
})($db);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
if ($is_lease_agent_user && $action !== 'list') {
    showAlert('danger', 'Lease agent users can only view vehicle master data.');
    redirect('fleet_vehicles.php');
}

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $refs = [];
    $chk = ['fleet_trips'=>'Trip Orders','fleet_fuel_log'=>'Fuel Entries','fleet_expenses'=>'Vehicle Expenses','fleet_tyres'=>'Tyre Records'];
    foreach ($chk as $table => $label) {
        $res = $db->query("SELECT COUNT(*) c FROM `$table` WHERE vehicle_id=$did");
        if ($res && $res->fetch_assoc()['c'] > 0) $refs[] = $label;
    }
    if (!empty($refs)) {
        showAlert('danger', 'Cannot delete — this vehicle has linked records in: ' . implode(', ', $refs) . '. Remove those records first or mark the vehicle as Disposed instead.');
    } else {
        $db->query("DELETE FROM fleet_vehicles WHERE id=$did");
        showAlert('success', 'Vehicle deleted permanently.');
    }
    redirect('fleet_vehicles.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vehicle'])) {
    if ($is_lease_agent_user) {
        showAlert('danger', 'Lease agent users can only view vehicle master data.');
        redirect('fleet_vehicles.php');
    }
    requirePerm('fleet_vehicles', $id > 0 ? 'update' : 'create');
    $reg     = strtoupper(trim(sanitize($_POST['reg_no'])));
    $make    = sanitize($_POST['make'] ?? '');
    $model   = sanitize($_POST['model'] ?? '');
    $year    = (int)($_POST['year'] ?? 0);
    $cap     = (float)($_POST['capacity_tons'] ?? 0);
    $fuel    = sanitize($_POST['fuel_type'] ?? 'Diesel');
    $def_drv = (int)($_POST['default_driver_id'] ?? 0);
    $lease_agent_id = (int)($_POST['lease_agent_id'] ?? 0);
    $status  = sanitize($_POST['status'] ?? 'Active');
    $ins_no  = sanitize($_POST['insurance_no'] ?? '');
    $ins_exp = sanitize($_POST['insurance_expiry'] ?? '');
    $fit_exp = sanitize($_POST['fitness_expiry'] ?? '');
    $sp_no   = sanitize($_POST['state_permit_no'] ?? '');
    $per_exp = sanitize($_POST['permit_expiry'] ?? '');
    $np_no   = sanitize($_POST['national_permit_no'] ?? '');
    $np_exp  = sanitize($_POST['national_permit_expiry'] ?? '');
    $puc_exp = sanitize($_POST['puc_expiry'] ?? '');
    $rt_exp  = sanitize($_POST['road_tax_expiry'] ?? '');
    $chassis = sanitize($_POST['chassis_no'] ?? '');
    $engine  = sanitize($_POST['engine_no'] ?? '');
    $hasJack = !empty($_POST['has_jack']) ? 1 : 0;
    $hasWheelRod = !empty($_POST['has_wheel_rod']) ? 1 : 0;
    $tyreCount = (int)($_POST['tyre_count'] ?? 0);
    $rimCount = (int)($_POST['rim_count'] ?? 0);
    $spareTyreCount = (int)($_POST['spare_tyre_count'] ?? 0);
    $spareTyreWithRimCount = (int)($_POST['spare_tyre_with_rim_count'] ?? 0);
    $toolKit = !empty($_POST['tool_kit']) ? 1 : 0;
    $fireExt = !empty($_POST['fire_extinguisher']) ? 1 : 0;
    $firstAid = !empty($_POST['first_aid_kit']) ? 1 : 0;
    $warningTriangle = !empty($_POST['warning_triangle']) ? 1 : 0;
    $wheelChocks = !empty($_POST['wheel_chocks']) ? 1 : 0;
    $spareKeyCount = (int)($_POST['spare_key_count'] ?? 0);
    $accessories = sanitize($_POST['accessories'] ?? '');
    $notes   = sanitize($_POST['notes'] ?? '');

    $ins_sql = $ins_exp ? "'$ins_exp'" : 'NULL';
    $fit_sql = $fit_exp ? "'$fit_exp'" : 'NULL';
    $per_sql = $per_exp ? "'$per_exp'" : 'NULL';
    $puc_sql = $puc_exp ? "'$puc_exp'" : 'NULL';
    $np_sql  = $np_exp  ? "'$np_exp'"  : 'NULL';
    $rt_sql  = $rt_exp  ? "'$rt_exp'"  : 'NULL';
    $drv_sql = $def_drv ? $def_drv     : 'NULL';
    $lease_sql = $lease_agent_id ? $lease_agent_id : 'NULL';

    if (!$reg) { showAlert('danger','Registration No is required.'); redirect("fleet_vehicles.php?action=$action&id=$id"); }

    if ($id > 0) {
        $db->query("UPDATE fleet_vehicles SET
            reg_no='$reg', make='$make', model='$model', year=" . ($year ?: 'NULL') . ",
            capacity_tons=$cap, fuel_type='$fuel', default_driver_id=$drv_sql, lease_agent_id=$lease_sql, status='$status',
            insurance_no='$ins_no', insurance_expiry=$ins_sql, fitness_expiry=$fit_sql,
            state_permit_no='$sp_no', permit_expiry=$per_sql,
            national_permit_no='$np_no', national_permit_expiry=$np_sql,
            puc_expiry=$puc_sql, road_tax_expiry=$rt_sql, chassis_no='$chassis', engine_no='$engine',
            has_jack=$hasJack, has_wheel_rod=$hasWheelRod, tyre_count=$tyreCount, rim_count=$rimCount,
            spare_tyre_count=$spareTyreCount, spare_tyre_with_rim_count=$spareTyreWithRimCount,
            tool_kit=$toolKit, fire_extinguisher=$fireExt, first_aid_kit=$firstAid,
            warning_triangle=$warningTriangle, wheel_chocks=$wheelChocks, spare_key_count=$spareKeyCount,
            accessories='$accessories', notes='$notes'
            WHERE id=$id");
        showAlert('success','Vehicle updated.');
    } else {
        $db->query("INSERT INTO fleet_vehicles
            (reg_no,make,model,year,capacity_tons,fuel_type,default_driver_id,lease_agent_id,status,insurance_no,
             insurance_expiry,fitness_expiry,state_permit_no,permit_expiry,
             national_permit_no,national_permit_expiry,puc_expiry,road_tax_expiry,chassis_no,engine_no,
             has_jack,has_wheel_rod,tyre_count,rim_count,spare_tyre_count,spare_tyre_with_rim_count,
             tool_kit,fire_extinguisher,first_aid_kit,warning_triangle,wheel_chocks,spare_key_count,
             accessories,notes)
            VALUES ('$reg','$make','$model'," . ($year ?: 'NULL') . ",$cap,'$fuel',$drv_sql,$lease_sql,'$status','$ins_no',
            $ins_sql,$fit_sql,'$sp_no',$per_sql,'$np_no',$np_sql,$puc_sql,$rt_sql,'$chassis','$engine',
            $hasJack,$hasWheelRod,$tyreCount,$rimCount,$spareTyreCount,$spareTyreWithRimCount,
            $toolKit,$fireExt,$firstAid,$warningTriangle,$wheelChocks,$spareKeyCount,
            '$accessories','$notes')");
        showAlert('success','Vehicle added.');
    }
    redirect('fleet_vehicles.php');
}

$drivers = $db->query("SELECT id,full_name,role FROM fleet_drivers WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$lease_agents = $db->query("SELECT id, agent_name, agent_code FROM fleet_lease_agents WHERE status='Active' ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-truck me-2"></i>Vehicle Master';</script>

<?php
$status_colors = ['Active'=>'success','In Repair'=>'warning','Idle'=>'secondary','Disposed'=>'danger'];
$today  = date('Y-m-d');
$warn30 = date('Y-m-d', strtotime('+30 days'));

function expiryBadge($date, $today, $warn30) {
    if (!$date) return '<span class="badge bg-secondary">—</span>';
    $d = date('d/m/Y', strtotime($date));
    if ($date < $today)   return "<span class='badge bg-danger'>$d</span>";
    if ($date <= $warn30) return "<span class='badge bg-warning text-dark'>$d</span>";
    return "<span class='badge bg-success'>$d</span>";
}

function yesNoBadge($value) {
    return $value ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>';
}

/* ── LIST ── */
if ($action === 'list'):
$vehicle_scope = $is_lease_agent_user && $current_lease_agent_id > 0 ? " WHERE fv.lease_agent_id=" . (int)$current_lease_agent_id : "";
$vehicles = $db->query("SELECT fv.*, fd.full_name AS driver_name, la.agent_name AS lease_agent_name, la.agent_code AS lease_agent_code
    FROM fleet_vehicles fv
    LEFT JOIN fleet_drivers fd ON fv.default_driver_id=fd.id
    LEFT JOIN fleet_lease_agents la ON fv.lease_agent_id=la.id
    $vehicle_scope
    ORDER BY fv.status ASC, fv.reg_no ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Vehicle Master</h5>
    <?php if (canDo('fleet_vehicles','create') && !$is_lease_agent_user): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Vehicle</a>
    <?php endif; ?>
</div>

<?php
$expiring = array_filter($vehicles, fn($v) =>
    ($v['insurance_expiry']       && $v['insurance_expiry']       <= $warn30) ||
    ($v['fitness_expiry']         && $v['fitness_expiry']         <= $warn30) ||
    ($v['permit_expiry']          && $v['permit_expiry']          <= $warn30) ||
    ($v['national_permit_expiry'] && $v['national_permit_expiry'] <= $warn30) ||
    ($v['puc_expiry']             && $v['puc_expiry']             <= $warn30) ||
    ($v['road_tax_expiry']        && $v['road_tax_expiry']        <= $warn30)
);
if ($expiring): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <span><strong><?= count($expiring) ?> vehicle(s)</strong> have documents expiring within 30 days.</span>
</div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Reg No</th><th>Make/Model</th><th>Capacity</th><th>Default Driver</th><th>Lease Agent</th><th>Status</th>
    <th>Insurance</th><th>Fitness</th><th>State Permit</th><th>Nat. Permit</th><th>PUC</th><th>Road Tax</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($vehicles as $v):
    $sc = $status_colors[$v['status']] ?? 'secondary';
    $inventoryParts = [];
    if ((int)($v['tyre_count'] ?? 0) > 0) $inventoryParts[] = 'Tyres: '.(int)$v['tyre_count'];
    if ((int)($v['rim_count'] ?? 0) > 0) $inventoryParts[] = 'Rims: '.(int)$v['rim_count'];
    if ((int)($v['spare_tyre_count'] ?? 0) > 0) $inventoryParts[] = 'Spare: '.(int)$v['spare_tyre_count'];
    if ((int)($v['spare_tyre_with_rim_count'] ?? 0) > 0) $inventoryParts[] = 'Spare w/ rim: '.(int)$v['spare_tyre_with_rim_count'];
    if (empty($inventoryParts)) $inventoryParts[] = 'Not set';
?>
<tr>
    <td><strong><?= htmlspecialchars($v['reg_no']) ?></strong></td>
    <td><?= htmlspecialchars($v['make'].' '.$v['model']) ?><br><small class="text-muted"><?= $v['year'] ?: '' ?></small></td>
    <td><?= $v['capacity_tons'] > 0 ? number_format($v['capacity_tons'],1).' MT' : '—' ?></td>
    <td><?= $v['driver_name'] ? '<span class="badge bg-info text-dark">'.htmlspecialchars($v['driver_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
    <td><?php if (!empty($v['lease_agent_name'])): ?><span class="badge bg-light text-dark border"><?= htmlspecialchars($v['lease_agent_name']) ?><?php if (!empty($v['lease_agent_code'])): ?> (<?= htmlspecialchars($v['lease_agent_code']) ?>)<?php endif; ?></span><?php else: ?>â€”<?php endif; ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $v['status'] ?></span></td>
    <td><?= expiryBadge($v['insurance_expiry'],       $today, $warn30) ?></td>
    <td><?= expiryBadge($v['fitness_expiry'],         $today, $warn30) ?></td>
    <td><?= expiryBadge($v['permit_expiry'],          $today, $warn30) ?></td>
    <td><?= expiryBadge($v['national_permit_expiry'], $today, $warn30) ?></td>
    <td><?= expiryBadge($v['puc_expiry'],             $today, $warn30) ?></td>
    <td><?= expiryBadge($v['road_tax_expiry'],        $today, $warn30) ?></td>
    <td>
        <?php if (canDo('fleet_vehicles','update') && !$is_lease_agent_user): ?>
        <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin() && !$is_lease_agent_user): ?>
        <a href="?delete=<?= $v['id'] ?>" onclick="return confirm('Permanently delete vehicle <?= htmlspecialchars(addslashes($v['reg_no'])) ?>?\n\nThis will fail if the vehicle has linked trips, fuel entries or expenses.\nTo retire a vehicle without deleting, edit it and set Status to Disposed.')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<?php
/* ── ADD / EDIT ── */
else:
$v = [];
if ($id > 0) $v = $db->query("SELECT * FROM fleet_vehicles WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'Add' ?> Vehicle</h5>
    <a href="fleet_vehicles.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_vehicle" value="1">
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-truck me-2"></i>Vehicle Information</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Registration No *</label>
        <input type="text" name="reg_no" class="form-control text-uppercase" value="<?= htmlspecialchars($v['reg_no'] ?? '') ?>" required placeholder="e.g. MH12AB1234">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Make</label>
        <input type="text" name="make" class="form-control" value="<?= htmlspecialchars($v['make'] ?? '') ?>" placeholder="e.g. Tata, Ashok Leyland">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Model</label>
        <input type="text" name="model" class="form-control" value="<?= htmlspecialchars($v['model'] ?? '') ?>" placeholder="e.g. 2518, 4923">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Year</label>
        <input type="number" name="year" class="form-control" value="<?= $v['year'] ?? '' ?>" placeholder="2020" min="1990" max="2099">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Capacity (MT)</label>
        <input type="number" name="capacity_tons" class="form-control" step="0.01" value="<?= $v['capacity_tons'] ?? '' ?>" placeholder="25.00">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Fuel Type</label>
        <select name="fuel_type" class="form-select">
            <?php foreach (['Diesel','CNG','Petrol'] as $ft): ?>
            <option <?= ($v['fuel_type'] ?? 'Diesel') === $ft ? 'selected' : '' ?>><?= $ft ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Default Driver</label>
        <select name="default_driver_id" class="form-select">
            <option value="">— No Default Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($v['default_driver_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= $d['role'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text text-muted">Auto-fills Driver when this vehicle is selected in Trip Order</div>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Lease Agent</label>
        <select name="lease_agent_id" class="form-select">
            <option value="">— No Lease Agent —</option>
            <?php foreach ($lease_agents as $agent): ?>
            <option value="<?= (int)$agent['id'] ?>" <?= (int)($v['lease_agent_id'] ?? 0) === (int)$agent['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($agent['agent_name']) ?><?php if (!empty($agent['agent_code'])): ?> (<?= htmlspecialchars($agent['agent_code']) ?>)<?php endif; ?>
            </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text text-muted">Visible for reference in vehicle, driver and trip order screens.</div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <?php foreach (['Active','In Repair','Idle','Disposed'] as $st): ?>
            <option <?= ($v['status'] ?? 'Active') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Chassis No</label>
        <input type="text" name="chassis_no" class="form-control" value="<?= htmlspecialchars($v['chassis_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Engine No</label>
        <input type="text" name="engine_no" class="form-control" value="<?= htmlspecialchars($v['engine_no'] ?? '') ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-file-earmark-check me-2"></i>Document Expiry Dates</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Insurance No</label>
        <input type="text" name="insurance_no" class="form-control" value="<?= htmlspecialchars($v['insurance_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Insurance Expiry</label>
        <input type="date" name="insurance_expiry" class="form-control" value="<?= $v['insurance_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Fitness Expiry</label>
        <input type="date" name="fitness_expiry" class="form-control" value="<?= $v['fitness_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">PUC Expiry</label>
        <input type="date" name="puc_expiry" class="form-control" value="<?= $v['puc_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">State Permit No</label>
        <input type="text" name="state_permit_no" class="form-control" value="<?= htmlspecialchars($v['state_permit_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">State Permit Expiry</label>
        <input type="date" name="permit_expiry" class="form-control" value="<?= $v['permit_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">National Permit No</label>
        <input type="text" name="national_permit_no" class="form-control" value="<?= htmlspecialchars($v['national_permit_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">National Permit Expiry</label>
        <input type="date" name="national_permit_expiry" class="form-control" value="<?= $v['national_permit_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Road Tax Expiry</label>
        <input type="date" name="road_tax_expiry" class="form-control" value="<?= $v['road_tax_expiry'] ?? '' ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-box-seam me-2"></i>Vehicle Inventory</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Tyre Count</label>
        <input type="number" name="tyre_count" class="form-control" min="0" value="<?= (int)($v['tyre_count'] ?? 0) ?>" placeholder="e.g. 10">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Rim Count</label>
        <input type="number" name="rim_count" class="form-control" min="0" value="<?= (int)($v['rim_count'] ?? 0) ?>" placeholder="e.g. 10">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Spare Tyres</label>
        <input type="number" name="spare_tyre_count" class="form-control" min="0" value="<?= (int)($v['spare_tyre_count'] ?? 0) ?>" placeholder="e.g. 1">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Spare Tyres With Rim</label>
        <input type="number" name="spare_tyre_with_rim_count" class="form-control" min="0" value="<?= (int)($v['spare_tyre_with_rim_count'] ?? 0) ?>" placeholder="e.g. 1">
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="has_jack" id="has_jack" value="1" <?= !empty($v['has_jack']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="has_jack">Jack available</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="has_wheel_rod" id="has_wheel_rod" value="1" <?= !empty($v['has_wheel_rod']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="has_wheel_rod">Wheel rod / cross wrench</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="tool_kit" id="tool_kit" value="1" <?= !empty($v['tool_kit']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="tool_kit">Tool kit</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="fire_extinguisher" id="fire_extinguisher" value="1" <?= !empty($v['fire_extinguisher']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="fire_extinguisher">Fire extinguisher</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="first_aid_kit" id="first_aid_kit" value="1" <?= !empty($v['first_aid_kit']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="first_aid_kit">First aid kit</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="warning_triangle" id="warning_triangle" value="1" <?= !empty($v['warning_triangle']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="warning_triangle">Warning triangle</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="wheel_chocks" id="wheel_chocks" value="1" <?= !empty($v['wheel_chocks']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="wheel_chocks">Wheel chocks</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Spare Key Count</label>
        <input type="number" name="spare_key_count" class="form-control" min="0" value="<?= (int)($v['spare_key_count'] ?? 0) ?>" placeholder="e.g. 1">
    </div>
    <div class="col-12">
        <label class="form-label">Accessories / Other Inventory</label>
        <textarea name="accessories" class="form-control" rows="3" placeholder="Example: GPS device, phone charger, tyre inflator, tow rope, coolant kit, reflectors"><?= htmlspecialchars($v['accessories'] ?? '') ?></textarea>
        <div class="form-text text-muted">Suggested extras: GPS tracker, phone charger, tyre inflator, tow rope, reflectors, coolant kit, mud flaps, tool bag, and spare belts.</div>
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($v['notes'] ?? '') ?></textarea>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_vehicles.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Vehicle</button>
</div>
</div>
</form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
