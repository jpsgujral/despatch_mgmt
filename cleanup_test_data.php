<?php
/**
 * ============================================================
 *  CLEANUP TEST DATA — Truncate transactional tables
 *  KEEPS: transporters, vendors (master data)
 *  CLEANS: despatch_orders, transporter_rates, agent/commission tables
 * ============================================================
 *  Usage:  Open in browser  →  Review listed tables  →  Click Confirm
 *  Safety: Won't touch transporters or vendors. Ever.
 * ============================================================
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
$db = getDB();

/* ── Tables to ALWAYS protect (never truncate) ── */
$PROTECTED = [
    'transporters',
    'vendors',
    'users',
    'roles',
    'permissions',
    'role_permissions',
    'settings',
    'migrations',
];

/* ── Tables to explicitly truncate ── */
$EXPLICIT_TARGETS = [
    'despatch_orders',
    'transporter_rates',
];

/* ── Auto-detect agent/commission tables by pattern ── */
$all_tables_res = $db->query("SHOW TABLES");
$all_tables = [];
while ($row = $all_tables_res->fetch_row()) {
    $all_tables[] = $row[0];
}

$agent_commission_patterns = ['agent', 'commission', 'comm_'];
$auto_detected = [];
foreach ($all_tables as $tbl) {
    foreach ($agent_commission_patterns as $pat) {
        if (stripos($tbl, $pat) !== false && !in_array($tbl, $PROTECTED)) {
            $auto_detected[] = $tbl;
            break;
        }
    }
}

/* ── Also detect despatch-related child tables (items, attachments, logs etc.) ── */
$despatch_patterns = ['despatch_', 'dispatch_'];
foreach ($all_tables as $tbl) {
    foreach ($despatch_patterns as $pat) {
        if (stripos($tbl, $pat) !== false && !in_array($tbl, $PROTECTED) && !in_array($tbl, $EXPLICIT_TARGETS)) {
            $auto_detected[] = $tbl;
            break;
        }
    }
}

$auto_detected = array_unique($auto_detected);
$all_targets = array_unique(array_merge($EXPLICIT_TARGETS, $auto_detected));

/* ── Get row counts for each target ── */
$table_info = [];
foreach ($all_targets as $tbl) {
    if (in_array($tbl, $all_tables)) {
        $cnt = $db->query("SELECT COUNT(*) c FROM `$tbl`")->fetch_assoc()['c'];
        $table_info[] = ['name' => $tbl, 'rows' => (int)$cnt, 'exists' => true];
    } else {
        $table_info[] = ['name' => $tbl, 'rows' => 0, 'exists' => false];
    }
}

/* ── Execute truncate if confirmed ── */
$executed = false;
$results  = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'YES_TRUNCATE_NOW') {
    // Disable FK checks temporarily for clean truncation
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($table_info as $t) {
        if (!$t['exists']) {
            $results[] = ['table' => $t['name'], 'status' => 'SKIPPED', 'reason' => 'Table does not exist'];
            continue;
        }
        if (in_array($t['name'], $PROTECTED)) {
            $results[] = ['table' => $t['name'], 'status' => 'PROTECTED', 'reason' => 'Master data — never touched'];
            continue;
        }
        $ok = $db->query("TRUNCATE TABLE `{$t['name']}`");
        $results[] = [
            'table'  => $t['name'],
            'status' => $ok ? 'TRUNCATED' : 'FAILED',
            'reason' => $ok ? "Cleared {$t['rows']} rows" : $db->error,
        ];
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
    $executed = true;
}

include __DIR__ . '/includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-trash3 me-2"></i>Cleanup Test Data';</script>

<div class="row justify-content-center">
<div class="col-12 col-lg-8">

<?php if ($executed): ?>
<!-- ── RESULTS ── -->
<div class="card border-success">
    <div class="card-header bg-success text-white">
        <i class="bi bi-check-circle me-2"></i>Cleanup Complete
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Table</th><th>Status</th><th>Details</th></tr>
            </thead>
            <tbody>
            <?php foreach ($results as $r):
                $badge = match($r['status']) {
                    'TRUNCATED' => 'success',
                    'PROTECTED' => 'primary',
                    'SKIPPED'   => 'secondary',
                    default     => 'danger',
                };
            ?>
                <tr>
                    <td><code><?= htmlspecialchars($r['table']) ?></code></td>
                    <td><span class="badge bg-<?= $badge ?>"><?= $r['status'] ?></span></td>
                    <td class="text-muted small"><?= htmlspecialchars($r['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="text-center mt-3">
    <a href="transporters.php" class="btn btn-primary"><i class="bi bi-truck-front me-1"></i>Go to Transporters</a>
    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary ms-2"><i class="bi bi-arrow-clockwise me-1"></i>Run Again</a>
</div>

<?php else: ?>
<!-- ── PREVIEW & CONFIRM ── -->
<div class="alert alert-danger d-flex align-items-center mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
    <div>
        <strong>Warning:</strong> This will permanently delete ALL rows from the tables listed below.
        <strong>Transporters &amp; Vendors will NOT be touched.</strong>
    </div>
</div>

<div class="card border-danger mb-4">
    <div class="card-header bg-danger text-white d-flex justify-content-between">
        <span><i class="bi bi-table me-2"></i>Tables to Truncate</span>
        <span class="badge bg-light text-danger"><?= count($table_info) ?> tables</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Table Name</th><th>Rows</th><th>Status</th><th>Source</th></tr>
            </thead>
            <tbody>
            <?php foreach ($table_info as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><code class="text-danger"><?= htmlspecialchars($t['name']) ?></code></td>
                    <td>
                        <?php if ($t['exists']): ?>
                            <span class="badge bg-<?= $t['rows'] > 0 ? 'warning text-dark' : 'secondary' ?>">
                                <?= number_format($t['rows']) ?> rows
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$t['exists']): ?>
                            <span class="text-muted"><i class="bi bi-dash-circle me-1"></i>Does not exist — will skip</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="bi bi-trash me-1"></i>Will be TRUNCATED</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= in_array($t['name'], $EXPLICIT_TARGETS) ? 'primary' : 'info text-dark' ?>">
                            <?= in_array($t['name'], $EXPLICIT_TARGETS) ? 'Explicit' : 'Auto-detected' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Protected tables info -->
<div class="card border-success mb-4">
    <div class="card-header bg-success bg-opacity-10 text-success">
        <i class="bi bi-shield-check me-2"></i>Protected Tables (will NEVER be touched)
    </div>
    <div class="card-body py-2">
        <?php foreach ($PROTECTED as $p): ?>
            <span class="badge bg-success me-1 mb-1"><i class="bi bi-lock-fill me-1"></i><?= $p ?></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- All DB tables for reference -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <i class="bi bi-database me-2"></i>All Tables in Database (for reference)
    </div>
    <div class="card-body py-2">
        <?php foreach ($all_tables as $tbl):
            $is_target    = in_array($tbl, $all_targets);
            $is_protected = in_array($tbl, $PROTECTED);
            $badge = $is_protected ? 'success' : ($is_target ? 'danger' : 'secondary');
        ?>
            <span class="badge bg-<?= $badge ?> me-1 mb-1">
                <?= $is_protected ? '<i class="bi bi-lock-fill me-1"></i>' : ($is_target ? '<i class="bi bi-trash me-1"></i>' : '') ?>
                <?= $tbl ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Confirm button -->
<form method="POST" onsubmit="return confirm('⚠️ FINAL CONFIRMATION\n\nThis will DELETE ALL DATA from:\n<?= implode(', ', array_column(array_filter($table_info, fn($t) => $t['exists']), 'name')) ?>\n\nTransporters & Vendors are SAFE.\n\nProceed?');">
    <input type="hidden" name="confirm" value="YES_TRUNCATE_NOW">
    <div class="d-flex justify-content-between">
        <a href="transporters.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Cancel — Go Back
        </a>
        <button type="submit" class="btn btn-danger btn-lg px-5">
            <i class="bi bi-trash3 me-2"></i>Truncate All Listed Tables
        </button>
    </div>
</form>
<?php endif; ?>

</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
