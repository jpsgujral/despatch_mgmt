<?php
$rootDir = dirname(__DIR__);
$includesDir = $rootDir . '/includes';

if (is_file($includesDir . '/config.php')) {
    require_once $includesDir . '/config.php';
} else {
    require_once $rootDir . '/config.php';
}

if (is_file($includesDir . '/r2_helper.php')) {
    require_once $includesDir . '/r2_helper.php';
}

if (is_file($includesDir . '/auth.php')) {
    require_once $includesDir . '/auth.php';
} else {
    require_once __DIR__ . '/auth.php';
}

// Direct R2 URL — bypasses stub r2_url() issue
function direct_r2_url(string $path): string {
    if (empty($path)) return '';
    $pub = defined('R2_PUBLIC_URL') ? R2_PUBLIC_URL : 'https://pub-5721570094064d529f1527519424c77b.r2.dev/dms_uploads';
    return $pub . '/' . ltrim($path, '/');
}

if (!isAdmin()) {
    $_SESSION['alert'] = ['type'=>'danger','message'=>'<i class="bi bi-shield-lock me-2"></i>Only Administrators can manage users.'];
    header('Location: ../index.php'); exit;
}

$db = getDB();

/* ── Auto-migrate: add view_all columns ── */
$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
foreach ([
    'view_all_despatch' => "TINYINT(1) DEFAULT 0 COMMENT 'Can view all despatch orders'",
    'view_all_trips'    => "TINYINT(1) DEFAULT 0 COMMENT 'Can view all trip orders'",
    'view_all_challans' => "TINYINT(1) DEFAULT 0 COMMENT 'Can view all delivery challans'",
    'view_all_transporter_payments' => "TINYINT(1) DEFAULT 0 COMMENT 'Can view all transporter payments'",
    'view_all_transporter_bills'    => "TINYINT(1) DEFAULT 0 COMMENT 'Can view all transporter pending bills'",
    'view_all_agent_commissions'    => "TINYINT(1) DEFAULT 0 COMMENT 'Can view all agent commissions'",
    'can_authorize_transporter_payments' => "TINYINT(1) DEFAULT 0 COMMENT 'Can authorise pending transporter payments'",
] as $col => $def) {
    $ex = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='app_users' AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
    if (!$ex) $db->query("ALTER TABLE app_users ADD COLUMN `$col` $def");
}

$modules = [
    'vendors'              => ['label'=>'Vendor Master',          'icon'=>'bi-building'],
    'items'                => ['label'=>'Item Master',            'icon'=>'bi-box-seam'],
    'transporters'         => ['label'=>'Transporter Master',     'icon'=>'bi-truck-front'],
    'source_of_material'   => ['label'=>'Source of Material',     'icon'=>'bi-geo-fill'],
    'aggregate_items'      => ['label'=>'Aggregate Items',        'icon'=>'bi-box-seam'],
    'aggregate_sources'    => ['label'=>'Aggregate Sources',      'icon'=>'bi-geo-alt-fill'],
    'aggregate_suppliers'  => ['label'=>'Aggregate Suppliers',    'icon'=>'bi-people-fill'],
    'fleet_vendors'        => ['label'=>'Fleet Vendors',          'icon'=>'bi-building'],
    'purchase_orders'      => ['label'=>'Purchase Orders',        'icon'=>'bi-file-earmark-text'],
    'despatch'             => ['label'=>'Despatch Orders',        'icon'=>'bi-send-check'],
    'despatch_register'    => ['label'=>'Despatch Register',      'icon'=>'bi-journal-text'],
    'delivery_challans'    => ['label'=>'Delivery Challans',      'icon'=>'bi-receipt'],
    'global_search'        => ['label'=>'Global Search',          'icon'=>'bi-search'],
    'reports'              => ['label'=>'Reports',                'icon'=>'bi-bar-chart-line'],
    'sales_invoices'       => ['label'=>'Sales Invoices',         'icon'=>'bi-receipt-cutoff'],
    'vendor_receipts'      => ['label'=>'Vendor Receipts',        'icon'=>'bi-wallet2'],
    'party_ledger'         => ['label'=>'Party Ledger',           'icon'=>'bi-journal-text'],
    'exception_dashboard'  => ['label'=>'Exception Dashboard',    'icon'=>'bi-exclamation-triangle'],
    'transporter_payments' => ['label'=>'Transporter Payments',   'icon'=>'bi-cash-coin'],
    'transporter_bulk_payments' => ['label'=>'Bulk Transporter Payments', 'icon'=>'bi-collection'],
    'payment_register'     => ['label'=>'Payment Register',       'icon'=>'bi-journal-text'],
    'transporter_bills'    => ['label'=>'Transporter Pending Bills', 'icon'=>'bi-receipt-cutoff'],
    'companies'            => ['label'=>'Companies',              'icon'=>'bi-buildings'],
    'company_settings'     => ['label'=>'Company Settings',       'icon'=>'bi-gear'],
    'export_excel'         => ['label'=>'Export to Excel',        'icon'=>'bi-file-earmark-excel'],
    'agent_commissions'    => ['label'=>'Agent Commissions',      'icon'=>'bi-percent'],
    'trading_dashboard'    => ['label'=>'Trading Dashboard',      'icon'=>'bi-speedometer2'],
    'trading_register'     => ['label'=>'Trading Register',       'icon'=>'bi-journal-text'],
    'fleet_dashboard'      => ['label'=>'Fleet — Dashboard',      'icon'=>'bi-speedometer2'],
    'fleet_vehicles'       => ['label'=>'Fleet — Vehicle Master', 'icon'=>'bi-truck'],
    'fleet_drivers'        => ['label'=>'Fleet — Driver Master',  'icon'=>'bi-person-badge'],
    'fleet_customers_master'=> ['label'=>'Fleet — Customer Master','icon'=>'bi-person-lines-fill'],
    'fleet_lease_agents'   => ['label'=>'Lease Agent Master',     'icon'=>'bi-person-badge'],
    'fleet_lease_agent_payments' => ['label'=>'Lease Agent Payments', 'icon'=>'bi-cash-stack'],
    'fleet_lease_agent_report'   => ['label'=>'Lease Agent Report',   'icon'=>'bi-file-earmark-bar-graph'],
    'fleet_pending_billing'=> ['label'=>'Fleet — Pending Billing Status', 'icon'=>'bi-receipt-cutoff'],
    'fleet_toll_rates'     => ['label'=>'Fleet — Toll Rate Master','icon'=>'bi-cash-coin'],
    'fleet_misc_expenses'  => ['label'=>'Fleet — Misc Expenses',  'icon'=>'bi-clipboard2-plus'],
    'fleet_purchase_orders'=> ['label'=>'Fleet — Customer POs',   'icon'=>'bi-file-earmark-text'],
    'fleet_fuel_companies' => ['label'=>'Fleet — Fuel Companies', 'icon'=>'bi-fuel-pump'],
    'fleet_trips'          => ['label'=>'Fleet — Trip Orders',    'icon'=>'bi-signpost-split'],
    'fleet_trip_register'  => ['label'=>'Fleet — Trip Order Register', 'icon'=>'bi-journal-text'],
    'fleet_fuel'           => ['label'=>'Fleet — Fuel Management','icon'=>'bi-droplet-fill'],
    'fleet_fuel_payments'  => ['label'=>'Fleet — Fuel Payments',  'icon'=>'bi-cash-coin'],
    'fleet_expenses'       => ['label'=>'Fleet — Vehicle Expenses','icon'=>'bi-tools'],
    'fleet_tyres'          => ['label'=>'Fleet — Tyre Tracking',  'icon'=>'bi-circle'],
    'fleet_salary'         => ['label'=>'Fleet — Driver Salary',  'icon'=>'bi-wallet2'],
    'fleet_status'         => ['label'=>'Fleet Vehicle Status',    'icon'=>'bi-map'],
    'fleet_vehicle_pnl'    => ['label'=>'Fleet Vehicle P&L',      'icon'=>'bi-graph-up-arrow'],
    'fleet_customer_revenue'=> ['label'=>'Fleet — Customer Revenue','icon'=>'bi-people'],
    'fleet_customer_pnl'   => ['label'=>'Fleet — Customer P&L',    'icon'=>'bi-graph-up-arrow'],
    'month_close_lock'     => ['label'=>'Month Close Lock',       'icon'=>'bi-calendar-check'],
    'quotations'           => ['label'=>'Quotations',              'icon'=>'bi-file-earmark-text-fill'],
    'gst_checker'          => ['label'=>'GST Checker',             'icon'=>'bi-patch-check-fill'],
];
$actions = ['view'=>'View','create'=>'Create','update'=>'Update','delete'=>'Delete'];

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$is_add_agent = ($action === 'add_agent');
if ($is_add_agent) {
    $action = 'add';
}

if (!function_exists('safeAddColumn')) {
    function safeAddColumn($db, $table, $column, $definition) {
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($res && $res->num_rows === 0) $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
safeAddColumn($db, 'app_users', 'is_agent',   "TINYINT(1) DEFAULT 0");
safeAddColumn($db, 'app_users', 'slab1_upto', "DECIMAL(10,2) DEFAULT 100.00");
safeAddColumn($db, 'app_users', 'slab1_pct',  "DECIMAL(5,2) DEFAULT 0.00");
safeAddColumn($db, 'app_users', 'slab2_pct',  "DECIMAL(5,2) DEFAULT 0.00");
safeAddColumn($db, 'app_users', 'is_lease_agent', "TINYINT(1) DEFAULT 0");
safeAddColumn($db, 'app_users', 'lease_agent_id', "INT DEFAULT NULL");

$lease_agents = $db->query("SELECT id, agent_name, agent_code
    FROM fleet_lease_agents
    WHERE status='Active'
    ORDER BY agent_name ASC")->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($del_id === (int)$_SESSION['user_id']) { showAlert('danger', 'You cannot delete your own account.'); }
    else { $db->query("DELETE FROM app_users WHERE id=$del_id"); showAlert('success', 'User deleted.'); }
    header('Location: users.php'); exit;
}

if (isset($_GET['toggle'])) {
    $tog_id = (int)$_GET['toggle'];
    if ($tog_id !== (int)$_SESSION['user_id'])
        $db->query("UPDATE app_users SET status=IF(status='Active','Inactive','Active') WHERE id=$tog_id");
    header('Location: users.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $mobile    = trim($_POST['mobile'] ?? '');
    $role      = $_POST['role'] ?? 'User';
    $status    = $_POST['status'] ?? 'Active';
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    $sig_clause = '';
    $cur_sig_row = $id > 0 ? $db->query("SELECT signature_path FROM app_users WHERE id=$id")->fetch_assoc() : [];
    $cur_sig_key = $cur_sig_row['signature_path'] ?? '';

    if (!empty($_POST['delete_signature']) && !empty($cur_sig_key)) {
        r2_delete($cur_sig_key);
        $sig_clause  = ', signature_path=NULL';
        $cur_sig_key = '';
    }

    if (!empty($_FILES['signature']['name'])) {
        $upload_err = $_FILES['signature']['error'];
        if ($upload_err !== UPLOAD_ERR_OK) { showAlert('danger', 'Upload error code: '.$upload_err); goto skip_save; }
        $imgInfo = @getimagesize($_FILES['signature']['tmp_name']);
        if (!$imgInfo) { showAlert('danger', 'Signature must be a valid image.'); goto skip_save; }
        if ($_FILES['signature']['size'] > 2*1024*1024) { showAlert('danger', 'Signature must be under 2MB.'); goto skip_save; }
        // Direct R2 upload — bypasses r2_handle_upload stub issue
        $sig_ext     = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
        $allowed_sig = ['jpg','jpeg','png','webp','gif'];
        if (!in_array($sig_ext, $allowed_sig)) { showAlert('danger', 'Invalid image type.'); goto skip_save; }
        $sig_key  = 'signatures/sig_'.($id ?: 'new').'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$sig_ext;
        $sig_body = file_get_contents($_FILES['signature']['tmp_name']);
        $sig_tok  = defined('R2_WORKER_TOKEN') ? R2_WORKER_TOKEN : 'dms_worker_s3cur3_t0k3n_2024';
        $sig_wurl = defined('R2_WORKER_URL')   ? R2_WORKER_URL   : 'https://dms-r2-upload.jpsgujral.workers.dev';
        $sig_ch   = curl_init($sig_wurl.'/'.$sig_key);
        curl_setopt_array($sig_ch, [CURLOPT_CUSTOMREQUEST=>'PUT', CURLOPT_POSTFIELDS=>$sig_body,
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
            CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false,
            CURLOPT_HTTPHEADER=>['X-DMS-Token: '.$sig_tok, 'Content-Type: image/'.$sig_ext,
                'Content-Length: '.strlen($sig_body), 'Expect: ']]);
        $sig_resp = curl_exec($sig_ch);
        $sig_code = curl_getinfo($sig_ch, CURLINFO_HTTP_CODE);
        curl_close($sig_ch);
        if ($sig_code === 200) {
            // Delete old signature from R2 if exists
            if (!empty($cur_sig_key)) {
                $del_ch = curl_init($sig_wurl.'/'.$cur_sig_key);
                curl_setopt_array($del_ch, [CURLOPT_CUSTOMREQUEST=>'DELETE', CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false,
                    CURLOPT_HTTPHEADER=>['X-DMS-Token: '.$sig_tok]]);
                curl_exec($del_ch); curl_close($del_ch);
            }
            $sig_clause = ", signature_path='".$db->real_escape_string($sig_key)."'";
        } else {
            showAlert('danger', 'Failed to upload signature. Please try again.');
            goto skip_save;
        }
    }

    $perms = [];
    foreach ($modules as $mod => $info) {
        foreach (array_keys($actions) as $act) {
            if (!empty($_POST['perm'][$mod][$act])) $perms[$mod][$act] = 1;
        }
    }

    $errors = [];
    if ($username === '')  $errors[] = 'Username is required.';
    if ($full_name === '') $errors[] = 'Full Name is required.';
    if ($id === 0 && $password === '') $errors[] = 'Password is required for new users.';
    if ($password !== '') {
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';
        if (strlen($password) < 6)  $errors[] = 'Password must be at least 6 characters.';
    }
    $uEsc = $db->real_escape_string($username);
    if ($db->query("SELECT id FROM app_users WHERE username='$uEsc'".($id>0?" AND id!=$id":""))->num_rows > 0)
        $errors[] = 'Username already exists.';

    if (!empty($errors)) { showAlert('danger', implode('<br>',$errors)); }
    else {
        $esc = fn($v) => $db->real_escape_string($v);
        $permsJson  = $db->real_escape_string(json_encode($perms));
        $is_agent   = isset($_POST['is_agent']) ? 1 : 0;
        $is_lease_agent = isset($_POST['is_lease_agent']) ? 1 : 0;
        $lease_agent_id = (int)($_POST['lease_agent_id'] ?? 0);
        if (!$is_lease_agent) $lease_agent_id = 0;
        $slab1_upto = (float)($_POST['slab1_upto'] ?? 100);
        $slab1_pct  = (float)($_POST['slab1_pct']  ?? 0);
        $slab2_pct  = (float)($_POST['slab2_pct']  ?? 0);
        $view_all_despatch = isset($_POST['view_all_despatch']) ? 1 : 0;
        $view_all_trips    = isset($_POST['view_all_trips'])    ? 1 : 0;
        $view_all_challans = isset($_POST['view_all_challans']) ? 1 : 0;
        $view_all_transporter_payments = isset($_POST['view_all_transporter_payments']) ? 1 : 0;
        $view_all_transporter_bills    = isset($_POST['view_all_transporter_bills']) ? 1 : 0;
        $view_all_agent_commissions    = isset($_POST['view_all_agent_commissions']) ? 1 : 0;
        $can_authorize_transporter_payments = isset($_POST['can_authorize_transporter_payments']) ? 1 : 0;

        if ($id > 0) {
            $pwClause = $password !== '' ? ", password='".$db->real_escape_string(password_hash($password,PASSWORD_DEFAULT))."'" : '';
            $db->query("UPDATE app_users SET
                username='{$esc($username)}', full_name='{$esc($full_name)}',
                email='{$esc($email)}', mobile='{$esc($mobile)}', role='{$esc($role)}', status='{$esc($status)}',
                permissions='$permsJson',
                is_agent=$is_agent, slab1_upto=$slab1_upto, slab1_pct=$slab1_pct, slab2_pct=$slab2_pct,
                is_lease_agent=$is_lease_agent, lease_agent_id=" . ($lease_agent_id > 0 ? $lease_agent_id : 'NULL') . ",
                view_all_despatch=$view_all_despatch, view_all_trips=$view_all_trips, view_all_challans=$view_all_challans,
                view_all_transporter_payments=$view_all_transporter_payments, view_all_transporter_bills=$view_all_transporter_bills,
                view_all_agent_commissions=$view_all_agent_commissions,
                can_authorize_transporter_payments=$can_authorize_transporter_payments
                $pwClause $sig_clause WHERE id=$id");
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['full_name'] = $full_name;
                $_SESSION['perms'] = $perms;
                $_SESSION['can_authorize_transporter_payments'] = $can_authorize_transporter_payments;
                $_SESSION['is_lease_agent'] = $is_lease_agent;
                $_SESSION['lease_agent_id'] = $lease_agent_id;
            }
            showAlert('success', 'User updated successfully.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->query("INSERT INTO app_users
                (username,full_name,email,mobile,role,password,permissions,status,
                 is_agent,slab1_upto,slab1_pct,slab2_pct,view_all_despatch,view_all_trips,view_all_challans,
                 view_all_transporter_payments,view_all_transporter_bills,view_all_agent_commissions,
                 can_authorize_transporter_payments,is_lease_agent,lease_agent_id)
                VALUES ('{$esc($username)}','{$esc($full_name)}','{$esc($email)}','{$esc($mobile)}',
                        '{$esc($role)}','".$db->real_escape_string($hash)."',
                        '$permsJson','{$esc($status)}',
                        $is_agent,$slab1_upto,$slab1_pct,$slab2_pct,
                        $view_all_despatch,$view_all_trips,$view_all_challans,
                        $view_all_transporter_payments,$view_all_transporter_bills,$view_all_agent_commissions,
                        $can_authorize_transporter_payments,$is_lease_agent," . ($lease_agent_id > 0 ? $lease_agent_id : 'NULL') . ")");
            showAlert('success', 'User created successfully.');
        }
        header('Location: users.php'); exit;
    }
    skip_save:;
}

$u = []; $u_perms = [];
if ($action === 'edit' && $id > 0) {
    $u = $db->query("SELECT * FROM app_users WHERE id=$id")->fetch_assoc();
    $u_perms = json_decode($u['permissions'] ?? '{}', true) ?: [];
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-people me-2"></i>User Management';</script>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">All Users</h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?action=add_agent" class="btn btn-outline-success">
            <i class="bi bi-person-badge me-1"></i>Add Agent
        </a>
        <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add User</a>
    </div>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover datatable mb-0">
    <thead><tr>
        <th>#</th><th>Username</th><th>Full Name</th><th>Email</th>
        <th>Role</th><th>View All</th><th>Lease Agent</th><th>Signature</th><th>Last Login</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php $list=$db->query("SELECT u.*, la.agent_name AS lease_agent_name, la.agent_code AS lease_agent_code
        FROM app_users u
        LEFT JOIN fleet_lease_agents la ON la.id=u.lease_agent_id
        ORDER BY u.role ASC, u.username ASC"); $i=1;
    while ($v=$list->fetch_assoc()): $isSelf=($v['id']==$_SESSION['user_id']); ?>
    <tr>
        <td><?= $i++ ?></td>
        <td><strong><?= htmlspecialchars($v['username']) ?></strong><?php if($isSelf): ?><span class="badge bg-info ms-1">You</span><?php endif; ?></td>
        <td><?= htmlspecialchars($v['full_name']) ?></td>
        <td><?= htmlspecialchars($v['email']?:'—') ?></td>
        <td><?php if($v['role']==='Admin'): ?><span class="badge bg-danger"><i class="bi bi-shield-fill me-1"></i>Admin</span><?php else: ?><span class="badge bg-secondary"><i class="bi bi-person me-1"></i>User</span><?php endif; ?></td>
        <td>
            <?php if($v['role']==='Admin'): ?>
                <span class="badge bg-success">All Access</span>
            <?php else: ?>
                <?php if($v['view_all_despatch']??0): ?><span class="badge bg-primary me-1">Despatch</span><?php endif; ?>
                <?php if($v['view_all_trips']??0): ?><span class="badge bg-success me-1">Trips</span><?php endif; ?>
                <?php if($v['view_all_challans']??0): ?><span class="badge bg-warning text-dark me-1">Challans</span><?php endif ?>
                <?php if($v['view_all_transporter_payments']??0): ?><span class="badge bg-danger me-1">Transporter Pay</span><?php endif; ?>
                <?php if($v['view_all_transporter_bills']??0): ?><span class="badge bg-dark me-1">Pending Bills</span><?php endif; ?>
                <?php if($v['view_all_agent_commissions']??0): ?><span class="badge bg-success me-1">Agent Comm</span><?php endif; ?>
                <?php if($v['can_authorize_transporter_payments']??0): ?><span class="badge bg-info text-dark me-1">Pay Authoriser</span><?php endif; ?>
                <?php if(!empty($v['is_lease_agent'])): ?><span class="badge bg-secondary me-1">Lease Agent</span><?php endif; ?>
                <?php if(!($v['view_all_despatch']??0) && !($v['view_all_trips']??0) && !($v['view_all_challans']??0) && !($v['view_all_transporter_payments']??0) && !($v['view_all_transporter_bills']??0) && !($v['view_all_agent_commissions']??0) && empty($v['is_lease_agent'])): ?><span class="text-muted small">Own only</span><?php endif; ?>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($v['is_lease_agent']) && !empty($v['lease_agent_name'])): ?>
                <span class="badge bg-light text-dark border">
                    <?= htmlspecialchars($v['lease_agent_name']) ?>
                    <?php if (!empty($v['lease_agent_code'])): ?> (<?= htmlspecialchars($v['lease_agent_code']) ?>)<?php endif; ?>
                </span>
            <?php else: ?>
                <span class="text-muted small">â€”</span>
            <?php endif; ?>
        </td>
        <td class="text-center">
            <?php if(!empty($v['signature_path'])): ?>
            <img src="<?= htmlspecialchars(direct_r2_url($v['signature_path'])) ?>" alt="Signature" style="max-height:36px;max-width:80px;object-fit:contain;border:1px solid #eee;border-radius:4px;padding:2px;background:#fff">
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
        </td>
        <td><?= $v['last_login'] ? date('d/m/Y H:i',strtotime($v['last_login'])) : '<span class="text-muted">Never</span>' ?></td>
        <td>
            <a href="?toggle=<?= $v['id'] ?>" class="badge text-decoration-none bg-<?= $v['status']==='Active'?'success':'secondary' ?>"
               <?= $isSelf ? 'onclick="return false" style="opacity:.6;cursor:not-allowed"' : "onclick=\"return confirm('Toggle status?')\"" ?>><?= $v['status'] ?></a>
        </td>
        <td>
            <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
            <?php if(!$isSelf): ?>
            <button onclick="confirmDelete(<?= $v['id'] ?>,'users.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
            <?php else: ?>
            <button class="btn btn-action btn-outline-secondary" disabled><i class="bi bi-trash"></i></button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div></div></div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">
        <?= $action==='edit'
            ? 'Edit User — '.htmlspecialchars($u['username']??'')
            : ($is_add_agent ? 'Add New Agent' : 'Add New User') ?>
    </h5>
    <a href="users.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST" enctype="multipart/form-data">
<div class="row g-3">

<!-- Account Details -->
<div class="col-12"><div class="card">
<div class="card-header"><i class="bi bi-person-badge me-2"></i>Account Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label">Username *</label>
        <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($u['username']??'') ?>">
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <label class="form-label">Full Name *</label>
        <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($u['full_name']??'') ?>">
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($u['email']??'') ?>">
    </div>
    <div class="col-12 col-sm-6 col-md-2">
        <label class="form-label">Mobile</label>
        <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($u['mobile']??'') ?>">
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">Role</label>
        <select name="role" id="roleSelect" class="form-select" onchange="onRoleChange()">
            <option value="User"  <?= ($u['role']??'User')==='User' ?'selected':'' ?>>User</option>
            <option value="Admin" <?= ($u['role']??'')==='Admin'?'selected':'' ?>>Admin</option>
        </select>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="Active"   <?= ($u['status']??'Active')==='Active'?'selected':'' ?>>Active</option>
            <option value="Inactive" <?= ($u['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label">New Password <?= $action==='edit'?'<small class="text-muted">(leave blank to keep)</small>':'' ?></label>
        <input type="password" name="password" class="form-control" autocomplete="new-password">
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
    </div>
</div></div></div></div>

<!-- ── View All Transactions Access ── -->
<div class="col-12" id="viewAllSection" <?= ($u['role']??'User')==='Admin'?'style="display:none"':'' ?>>
<div class="card border-info">
<div class="card-header bg-info text-white"><i class="bi bi-eye me-2"></i>Transaction View Access
    <small class="ms-2 opacity-75">Grant this user access to view ALL users' transactions (not just their own)</small>
</div>
<div class="card-body">
<div class="row g-3">
    <div class="col-12 col-md-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="view_all_despatch" id="view_all_despatch" value="1"
                   <?= ($u['view_all_despatch']??0) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="view_all_despatch">
                <i class="bi bi-send-check me-1 text-primary"></i>Despatch Orders
                <div class="text-muted fw-normal" style="font-size:.8rem">Can view all users' despatch orders & challans</div>
            </label>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="view_all_trips" id="view_all_trips" value="1"
                   <?= ($u['view_all_trips']??0) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="view_all_trips">
                <i class="bi bi-signpost-split me-1 text-success"></i>Trip Orders
                <div class="text-muted fw-normal" style="font-size:.8rem">Can view all users' fleet trip orders</div>
            </label>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="view_all_challans" id="view_all_challans" value="1"
                   <?= ($u['view_all_challans']??0) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="view_all_challans">
                <i class="bi bi-receipt me-1 text-warning"></i>Delivery Challans
                <div class="text-muted fw-normal" style="font-size:.8rem">Can view all users' delivery challans</div>
            </label>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="view_all_transporter_payments" id="view_all_transporter_payments" value="1"
                   <?= ($u['view_all_transporter_payments']??0) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="view_all_transporter_payments">
                <i class="bi bi-cash-coin me-1 text-danger"></i>Transporter Payments
                <div class="text-muted fw-normal" style="font-size:.8rem">Can view all users' transporter payment transactions</div>
            </label>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="view_all_transporter_bills" id="view_all_transporter_bills" value="1"
                   <?= ($u['view_all_transporter_bills']??0) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="view_all_transporter_bills">
                <i class="bi bi-receipt-cutoff me-1 text-dark"></i>Transporter Pending Bills
                <div class="text-muted fw-normal" style="font-size:.8rem">Can view all users' transporter pending bill transactions</div>
            </label>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="view_all_agent_commissions" id="view_all_agent_commissions" value="1"
                   <?= ($u['view_all_agent_commissions']??0) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="view_all_agent_commissions">
                <i class="bi bi-percent me-1 text-success"></i>Agent Commissions
                <div class="text-muted fw-normal" style="font-size:.8rem">Can view all agents' commission transactions</div>
            </label>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="can_authorize_transporter_payments" id="can_authorize_transporter_payments" value="1"
                   <?= ($u['can_authorize_transporter_payments']??0) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="can_authorize_transporter_payments">
                <i class="bi bi-shield-check me-1 text-info"></i>Authorise Transporter Payments
                <div class="text-muted fw-normal" style="font-size:.8rem">Can remotely authorise pending transporter payment entries and bulk batches</div>
            </label>
        </div>
    </div>
</div>
</div>
</div>
</div>

<!-- Signature -->
<div class="col-12"><div class="card">
<div class="card-header"><i class="bi bi-pen me-2"></i>Signature</div>
<div class="card-body">
<?php if (!empty($u['signature_path'])): ?>
<div class="mb-3 d-flex align-items-center gap-3">
    <img src="<?= htmlspecialchars(direct_r2_url($u['signature_path'])) ?>" alt="Current Signature"
         style="max-height:60px;max-width:200px;object-fit:contain;border:1px solid #ddd;padding:4px;border-radius:6px;background:#fff">
    <div class="form-check">
        <input class="form-check-input border-danger" type="checkbox" name="delete_signature" value="1" id="delSig">
        <label class="form-check-label text-danger fw-semibold" for="delSig"><i class="bi bi-trash me-1"></i>Remove signature</label>
    </div>
</div>
<?php endif; ?>
<input type="file" name="signature" class="form-control" accept="image/*" style="max-width:400px">
<div class="form-text">JPG, PNG or WEBP, max 2MB. Used in Delivery Challan "Checked By" box.</div>
</div></div></div>

<!-- Agent Commission -->
<div class="col-12" id="agentSection">
<div class="card">
<div class="card-header"><i class="bi bi-percent me-2"></i>Agent / Commission Settings</div>
<div class="card-body">
<div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" name="is_agent" id="isAgentChk" value="1"
           <?= (($u['is_agent'] ?? 0) || $is_add_agent) ? 'checked' : '' ?> onchange="toggleAgentSlabs()">
    <label class="form-check-label fw-semibold" for="isAgentChk">This user is an Agent / Salesman</label>
</div>
<div id="agentSlabs" <?= (($u['is_agent'] ?? 0) || $is_add_agent) ? '' : 'style="display:none"' ?>>
<div class="row g-3">
    <div class="col-12 col-md-3">
        <label class="form-label">Slab 1 — Profit up to (₹/MT)</label>
        <input type="number" name="slab1_upto" step="0.01" class="form-control" value="<?= $u['slab1_upto']??100 ?>">
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Slab 1 Commission %</label>
        <input type="number" name="slab1_pct" step="0.01" class="form-control" value="<?= $u['slab1_pct']??0 ?>">
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Slab 2 Commission % (above threshold)</label>
        <input type="number" name="slab2_pct" step="0.01" class="form-control" value="<?= $u['slab2_pct']??0 ?>">
    </div>
</div>
</div>
<hr class="my-4">
<div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" name="is_lease_agent" id="isLeaseAgentChk" value="1"
           <?= !empty($u['is_lease_agent']) ? 'checked' : '' ?> onchange="toggleLeaseAgentMap()">
    <label class="form-check-label fw-semibold" for="isLeaseAgentChk">Enable this user as a Lease Agent</label>
</div>
<div id="leaseAgentMap" <?= !empty($u['is_lease_agent']) ? '' : 'style="display:none"' ?>>
    <label class="form-label">Map to Lease Agent Master</label>
    <select name="lease_agent_id" id="leaseAgentSelect" class="form-select" style="max-width:420px">
        <option value="0">Select Lease Agent</option>
        <?php foreach ($lease_agents as $la): ?>
        <option value="<?= (int)$la['id'] ?>" <?= (int)($u['lease_agent_id'] ?? 0) === (int)$la['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($la['agent_name'] . (!empty($la['agent_code']) ? ' (' . $la['agent_code'] . ')' : '')) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <div class="form-text">Lease-agent users can only view their own linked lease-agent data.</div>
</div>
</div>
</div>
</div>

<!-- Permissions -->
<div class="col-12" id="permSection">
<div class="card">
<div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-shield-check me-2"></i>Module Permissions</span>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-success" onclick="setAllPerms(true)">Grant All</button>
        <button type="button" class="btn btn-sm btn-outline-danger"  onclick="setAllPerms(false)">Revoke All</button>
    </div>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead class="table-light"><tr>
    <th>Module</th>
    <?php foreach ($actions as $act => $lbl): ?><th class="text-center"><?= $lbl ?></th><?php endforeach; ?>
    <th class="text-center">All</th>
</tr></thead>
<tbody>
<?php foreach ($modules as $mod => $info): ?>
<tr>
    <td><i class="bi <?= $info['icon'] ?> me-2 text-muted"></i><?= $info['label'] ?></td>
    <?php foreach ($actions as $act => $lbl): ?>
    <td class="text-center">
        <input type="checkbox" class="form-check-input perm-cb" name="perm[<?= $mod ?>][<?= $act ?>]" value="1"
               data-mod="<?= $mod ?>"
               <?= !empty($u_perms[$mod][$act]) ? 'checked' : '' ?>>
    </td>
    <?php endforeach; ?>
    <td class="text-center">
        <input type="checkbox" class="form-check-input row-all-cb"
               onchange="toggleRowPerms(this,'<?= $mod ?>')"
               <?= (count($u_perms[$mod]??[])===count($actions)) ? 'checked' : '' ?>>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>
</div>

<div class="col-12 text-end">
    <a href="users.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save User</button>
</div>
</div>
</form>

<script>
function onRoleChange() {
    var role = document.getElementById('roleSelect').value;
    var permSec = document.getElementById('permSection');
    var viewAllSec = document.getElementById('viewAllSection');
    var agentSec = document.getElementById('agentSection');
    if (role === 'Admin') {
        if (permSec) permSec.style.display = 'none';
        if (viewAllSec) viewAllSec.style.display = 'none';
    } else {
        if (permSec) permSec.style.display = '';
        if (viewAllSec) viewAllSec.style.display = '';
    }
}
function toggleAgentSlabs() {
    document.getElementById('agentSlabs').style.display =
        document.getElementById('isAgentChk').checked ? '' : 'none';
}
function toggleLeaseAgentMap() {
    var chk = document.getElementById('isLeaseAgentChk');
    var box = document.getElementById('leaseAgentMap');
    var sel = document.getElementById('leaseAgentSelect');
    if (box) box.style.display = (chk && chk.checked) ? '' : 'none';
    if (sel && chk && !chk.checked) sel.value = '0';
}
function setAllPerms(val) {
    document.querySelectorAll('.perm-cb,.row-all-cb').forEach(function(cb){ cb.checked = val; });
}
function toggleRowPerms(cb, mod) {
    document.querySelectorAll('.perm-cb[data-mod="'+mod+'"]').forEach(function(c){ c.checked = cb.checked; });
}
// Apply correct visibility on initial page load
document.addEventListener('DOMContentLoaded', function() {
    onRoleChange();
    toggleLeaseAgentMap();
});
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
