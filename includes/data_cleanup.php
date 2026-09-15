<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
if (!isAdmin()) { showAlert('danger', 'Admin access required.'); redirect('index.php'); }

/* ════════════════════════════════════════════════
   DATA CLEANUP — Admin Only
   Allows selective deletion of data by module/table
   All actions require double confirmation
════════════════════════════════════════════════ */

$action = $_POST['action'] ?? '';
$result_msg = '';

/* ── Table groups definition ── */
$table_groups = [

    'Fleet Management' => [
        'icon'   => 'bi-truck',
        'color'  => 'success',
        'tables' => [
            'fleet_trip_documents' => [
                'label'   => 'Trip Documents',
                'desc'    => 'Uploaded documents attached to trip orders (R2 keys only — delete files from R2 separately)',
                'depends' => [],
            ],
            'fleet_trip_items' => [
                'label'   => 'Trip Items / Materials',
                'desc'    => 'Line items (fly ash weight, rate, amount) inside each trip order',
                'depends' => [],
            ],
            'fleet_fuel_log' => [
                'label'   => 'Fuel Entries',
                'desc'    => 'All fuel fill-up records including trip-linked entries and bills',
                'depends' => [],
            ],
            'fleet_expenses' => [
                'label'   => 'Vehicle Expenses',
                'desc'    => 'Repair, tyre, service and other vehicle expense records',
                'depends' => [],
            ],
            'fleet_driver_salary' => [
                'label'   => 'Driver Regular Salary',
                'desc'    => 'Monthly salary records for all drivers',
                'depends' => [],
            ],
            'fleet_driver_retro_salary' => [
                'label'   => 'Driver Retro Salary',
                'desc'    => 'Retro/held salary records and release history',
                'depends' => [],
            ],
            'fleet_trips' => [
                'label'   => 'Trip Orders',
                'desc'    => 'All trip orders — also clears trip items, fuel entries, vehicle expenses and documents',
                'depends' => ['fleet_trip_items','fleet_fuel_log','fleet_expenses','fleet_trip_documents'],
                'cascade' => true,
            ],
            'fleet_po_items' => [
                'label'   => 'Customer PO Items',
                'desc'    => 'Line items inside fleet customer purchase orders',
                'depends' => [],
            ],
            'fleet_purchase_orders' => [
                'label'   => 'Customer POs (Fleet)',
                'desc'    => 'Fleet sales / customer purchase orders — also clears PO items',
                'depends' => ['fleet_po_items'],
                'cascade' => true,
            ],
        ],
    ],

    'Despatch Management' => [
        'icon'   => 'bi-box-seam',
        'color'  => 'primary',
        'tables' => [
            'despatch_items' => [
                'label'   => 'Despatch Items',
                'desc'    => 'Line items inside despatch orders',
                'depends' => [],
            ],
            'agent_commissions' => [
                'label'   => 'Agent Commissions',
                'desc'    => 'Commission records linked to despatch orders',
                'depends' => ['agent_payment_commissions'],
                'cascade' => true,
            ],
            'agent_commission_payments' => [
                'label'   => 'Agent Commission Payments',
                'desc'    => 'Payment records for agent commissions',
                'depends' => ['agent_payment_commissions'],
                'cascade' => true,
            ],
            'despatch_orders' => [
                'label'   => 'Despatch Orders',
                'desc'    => 'All despatch orders — also clears items and commissions',
                'depends' => ['despatch_items','agent_commissions','agent_payment_commissions','agent_payment_commissions'],
                'cascade' => true,
            ],
        ],
    ],

    'System' => [
        'icon'   => 'bi-gear',
        'color'  => 'danger',
        'tables' => [
            'app_error_log' => [
                'label'   => 'Error Log',
                'desc'    => 'Application error and exception log',
                'depends' => [],
            ],
            'doc_sequences' => [
                'label'   => 'Document Sequences',
                'desc'    => 'Challan / Despatch number sequence counters — WARNING: resets numbering',
                'depends' => [],
                'warn'    => true,
            ],
        ],
    ],
];

/* ── Hard whitelist — ONLY these transaction tables may ever be truncated ── */
$allowed_tables = [
    'fleet_trip_documents','fleet_trip_items','fleet_fuel_log','fleet_expenses',
    'fleet_driver_salary','fleet_driver_retro_salary','fleet_trips',
    'fleet_po_items','fleet_purchase_orders',
    'despatch_items','agent_commissions','agent_commission_payments',
    'agent_payment_commissions','despatch_orders',
    'app_error_log','doc_sequences',
];

/* ── Process DELETE action ── */
if ($action === 'truncate' && isset($_POST['table']) && isset($_POST['confirm_token'])) {
    $table = $_POST['table'];
    $token = $_POST['confirm_token'];

    // Hard whitelist check — NEVER allow master tables
    if (!in_array($table, $allowed_tables)) {
        showAlert('danger', 'This table is protected and cannot be cleared from Data Cleanup.');
        redirect('data_cleanup.php');
    }

    // Validate token = md5(table + date + secret)
    $expected_token = md5($table . date('Y-m-d') . 'dms_cleanup_2024');
    if ($token !== $expected_token) {
        showAlert('danger', 'Invalid confirmation token. Action aborted.');
        redirect('data_cleanup.php');
    }

    // Find the table definition
    $table_def = null;
    foreach ($table_groups as $group) {
        if (isset($group['tables'][$table])) {
            $table_def = $group['tables'][$table];
            break;
        }
    }

    if (!$table_def) {
        showAlert('danger', 'Unknown table. Action aborted.');
        redirect('data_cleanup.php');
    }

    // Check if table exists
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' LIMIT 1")->num_rows;

    if (!$exists) {
        showAlert('warning', "Table `$table` does not exist in this database — nothing to clear.");
        redirect('data_cleanup.php');
    }

    // Disable FK checks, truncate cascade tables, re-enable
    $db->query("SET FOREIGN_KEY_CHECKS=0");
    $deleted = 0;

    // Delete cascade tables first
    if (!empty($table_def['depends'])) {
        foreach ($table_def['depends'] as $dep) {
            $dep_exists = $db->query("SELECT 1 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$dep' LIMIT 1")->num_rows;
            if ($dep_exists) {
                $db->query("TRUNCATE TABLE `$dep`");
            }
        }
    }

    // Truncate main table
    $db->query("TRUNCATE TABLE `$table`");
    $db->query("SET FOREIGN_KEY_CHECKS=1");

    // Log it
    dmsLogError('NOTICE', "Data Cleanup: Table `$table` truncated by admin user #".($_SESSION['user_id']??0), __FILE__, __LINE__);

    $dep_msg = !empty($table_def['depends']) ? ' (+ '.implode(', ', $table_def['depends']).')' : '';
    showAlert('success', "✓ Table `{$table_def['label']}`{$dep_msg} cleared successfully.");
    redirect('data_cleanup.php');
}

/* ── Get row counts ── */
$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$counts = [];
foreach ($table_groups as $group) {
    foreach ($group['tables'] as $tbl => $def) {
        $exists = $db->query("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$tbl' LIMIT 1")->num_rows;
        if ($exists) {
            $row = $db->query("SELECT COUNT(*) c FROM `$tbl`")->fetch_assoc();
            $counts[$tbl] = (int)($row['c'] ?? 0);
        } else {
            $counts[$tbl] = null; // table doesn't exist
        }
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-trash3 me-2"></i>Data Cleanup';</script>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Data Cleanup</h5>
    <span class="badge bg-danger">Admin Only</span>
</div>

<div class="alert alert-danger d-flex gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
    <div>
        <strong>Warning — Irreversible Action.</strong> Clearing tables permanently deletes data and cannot be undone.
        Always take a <a href="../backup/index.php" class="alert-link">database backup</a> before proceeding.
        All actions are logged in the Error Log.
    </div>
</div>

<?php foreach ($table_groups as $group_name => $group): ?>
<div class="card mb-4">
<div class="card-header" style="background:#f8f9fa">
    <i class="bi <?= $group['icon'] ?> me-2 text-<?= $group['color'] ?>"></i>
    <strong><?= $group_name ?></strong>
</div>
<div class="card-body p-0">
<table class="table table-hover mb-0">
<thead class="table-light"><tr>
    <th style="width:25%">Table</th>
    <th>Description</th>
    <th class="text-center" style="width:90px">Records</th>
    <th class="text-center" style="width:110px">Action</th>
</tr></thead>
<tbody>
<?php foreach ($group['tables'] as $tbl => $def):
    $count   = $counts[$tbl];
    $missing = $count === null;
    $token   = md5($tbl . date('Y-m-d') . 'dms_cleanup_2024');
    $dep_str = !empty($def['depends']) ? '<br><small class="text-muted">Also clears: '.implode(', ', $def['depends']).'</small>' : '';
    $warn    = !empty($def['warn']);
?>
<tr class="<?= $missing ? 'opacity-50' : '' ?>">
    <td>
        <span class="fw-semibold"><?= htmlspecialchars($def['label']) ?></span>
        <?php if ($warn): ?>
        <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">⚠ Caution</span>
        <?php endif; ?>
        <br><small class="text-muted font-monospace"><?= $tbl ?></small>
    </td>
    <td style="font-size:.85rem">
        <?= htmlspecialchars($def['desc']) ?>
        <?= $dep_str ?>
    </td>
    <td class="text-center">
        <?php if ($missing): ?>
        <span class="badge bg-secondary">N/A</span>
        <?php elseif ($count === 0): ?>
        <span class="badge bg-success">Empty</span>
        <?php else: ?>
        <span class="badge bg-<?= $count > 100 ? 'danger' : 'warning text-dark' ?>"><?= number_format($count) ?></span>
        <?php endif; ?>
    </td>
    <td class="text-center">
        <?php if ($missing): ?>
        <span class="text-muted small">Not created</span>
        <?php elseif ($count === 0): ?>
        <span class="text-muted small">—</span>
        <?php else: ?>
        <button class="btn btn-outline-danger btn-sm"
            onclick="confirmClear('<?= $tbl ?>', '<?= addslashes($def['label']) ?>', '<?= $token ?>', <?= !empty($def['depends']) ? '\''.implode(', ', $def['depends']).'\'' : 'null' ?>)">
            <i class="bi bi-trash3 me-1"></i>Clear
        </button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endforeach; ?>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content border-danger">
<div class="modal-header bg-danger text-white">
    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Data Clear</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <p>You are about to permanently delete all records from:</p>
    <p class="fw-bold fs-5 text-danger" id="modalTableLabel"></p>
    <div id="modalCascadeWarn" class="alert alert-warning py-2 mb-3" style="display:none">
        <i class="bi bi-arrow-down-circle me-1"></i>
        This will also clear: <strong id="modalCascadeTables"></strong>
    </div>
    <p class="mb-1">Type <strong>DELETE</strong> to confirm:</p>
    <input type="text" id="confirmInput" class="form-control border-danger" placeholder="Type DELETE">
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <form method="POST" id="cleanupForm">
        <input type="hidden" name="action" value="truncate">
        <input type="hidden" name="table" id="modalTable">
        <input type="hidden" name="confirm_token" id="modalToken">
        <button type="submit" id="modalSubmit" class="btn btn-danger" disabled>
            <i class="bi bi-trash3 me-1"></i>Clear Data
        </button>
    </form>
</div>
</div>
</div>
</div>

<script>
function confirmClear(table, label, token, cascades) {
    document.getElementById('modalTableLabel').textContent = label;
    document.getElementById('modalTable').value  = table;
    document.getElementById('modalToken').value  = token;
    document.getElementById('confirmInput').value = '';
    document.getElementById('modalSubmit').disabled = true;

    var cascadeWarn = document.getElementById('modalCascadeWarn');
    if (cascades) {
        cascadeWarn.style.display = '';
        document.getElementById('modalCascadeTables').textContent = cascades;
    } else {
        cascadeWarn.style.display = 'none';
    }
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
}

document.getElementById('confirmInput').addEventListener('input', function() {
    document.getElementById('modalSubmit').disabled = this.value !== 'DELETE';
});
</script>

<?php include '../includes/footer.php'; ?>
