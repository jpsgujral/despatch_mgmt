<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('company_settings', 'view');

$db->query("CREATE TABLE IF NOT EXISTS app_month_locks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    module_key      VARCHAR(50) NOT NULL,
    lock_month      VARCHAR(7) NOT NULL,
    is_locked       TINYINT(1) NOT NULL DEFAULT 0,
    remarks         VARCHAR(255) DEFAULT '',
    updated_by      INT DEFAULT 0,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mod_month (module_key, lock_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$module_options = [
    'sales_invoices' => 'Sales Invoices',
    'vendor_receipts' => 'Vendor Receipts',
    'transporter_payments' => 'Transporter Payments',
    'fleet_trips' => 'Fleet Trips',
    'fleet_expenses' => 'Fleet Expenses',
    'fleet_fuel' => 'Fleet Fuel',
    'fleet_salary' => 'Fleet Salary',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lock'])) {
    requirePerm('company_settings', 'update');
    $module_key = sanitize($_POST['module_key'] ?? '');
    $lock_month = sanitize($_POST['lock_month'] ?? date('Y-m'));
    $is_locked = !empty($_POST['is_locked']) ? 1 : 0;
    $remarks = sanitize($_POST['remarks'] ?? '');
    if (!isset($module_options[$module_key]) || !preg_match('/^\d{4}-\d{2}$/', $lock_month)) {
        showAlert('danger', 'Invalid module/month.');
        redirect('month_close_lock.php');
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $db->query("INSERT INTO app_month_locks (module_key,lock_month,is_locked,remarks,updated_by)
        VALUES ('$module_key','$lock_month',$is_locked,'$remarks',$uid)
        ON DUPLICATE KEY UPDATE is_locked=VALUES(is_locked), remarks=VALUES(remarks), updated_by=VALUES(updated_by)");
    showAlert('success', 'Month lock updated.');
    redirect('month_close_lock.php?month=' . urlencode($lock_month));
}

$filter_month = sanitize($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $filter_month)) $filter_month = date('Y-m');
$locks = $db->query("SELECT l.*, u.full_name
    FROM app_month_locks l
    LEFT JOIN app_users u ON u.id=l.updated_by
    WHERE l.lock_month='$filter_month'
    ORDER BY l.module_key")->fetch_all(MYSQLI_ASSOC);
$lock_map = [];
foreach ($locks as $l) $lock_map[$l['module_key']] = $l;

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-calendar-check me-2"></i>Month Close Lock';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Month Close Lock</h5>
</div>

<div class="alert alert-warning py-2">
    This is control-layer setup only. Existing transaction logic is not altered.
</div>

<div class="card mb-3"><div class="card-body">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-12 col-md-3">
        <label class="form-label">Month</label>
        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($filter_month) ?>">
    </div>
    <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary w-100">Load</button></div>
</form>
</div></div>

<div class="row g-3">
<?php foreach ($module_options as $k => $lbl): $cur = $lock_map[$k] ?? null; ?>
<div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= htmlspecialchars($lbl) ?></span>
            <span class="badge bg-<?= ($cur && (int)$cur['is_locked']===1) ? 'danger' : 'success' ?>">
                <?= ($cur && (int)$cur['is_locked']===1) ? 'Locked' : 'Open' ?>
            </span>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="save_lock" value="1">
                <input type="hidden" name="module_key" value="<?= htmlspecialchars($k) ?>">
                <input type="hidden" name="lock_month" value="<?= htmlspecialchars($filter_month) ?>">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="is_locked" value="1" id="l_<?= htmlspecialchars($k) ?>" <?= ($cur && (int)$cur['is_locked']===1)?'checked':'' ?>>
                    <label class="form-check-label" for="l_<?= htmlspecialchars($k) ?>">Lock this month</label>
                </div>
                <label class="form-label">Remarks</label>
                <input type="text" class="form-control form-control-sm mb-2" name="remarks" value="<?= htmlspecialchars($cur['remarks'] ?? '') ?>" placeholder="Optional">
                <button class="btn btn-sm btn-primary">Save</button>
            </form>
        </div>
        <?php if ($cur): ?>
        <div class="card-footer text-muted small">By <?= htmlspecialchars($cur['full_name'] ?? ('User#'.$cur['updated_by'])) ?> on <?= date('d/m/Y H:i', strtotime($cur['updated_at'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php include '../includes/footer.php'; ?>
