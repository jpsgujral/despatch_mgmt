<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('company_settings', 'update'); // Admin only

$confirmed = ($_POST['confirm_cleanup'] ?? '') === 'YES_DELETE_NOW';

/* ── Tables to CLEAN (DELETE all rows) ── */
$tables_to_clean = [
    'despatch_orders'            => 'Despatch Orders',
    'despatch_items'             => 'Despatch Items',
    'agent_commissions'          => 'Agent Commissions',
    'agent_commission_payments'  => 'Agent Commission Payments',
    'agent_payment_commissions'  => 'Agent Payment Commission Links',
    'sales_invoices'             => 'Sales Invoices',
    'sales_invoice_payments'     => 'Sales Invoice Payments',
    'transporter_payments'       => 'Transporter Payments',
    'doc_sequences'              => 'Challan Sequences (will reset numbering)',
];

/* ── Tables to KEEP (not touched) ── */
$tables_to_keep = [
    'items'                => 'Item Master',
    'purchase_orders'      => 'Purchase Orders',
    'po_items'             => 'PO Items',
    'transporters'         => 'Transporter Master',
    'transporter_rates'    => 'Transporter Rate Cards',
    'vendors'              => 'Vendor Master',
    'companies'            => 'Companies',
    'company_settings'     => 'Company Settings',
    'app_users'            => 'Users & Roles',
    'source_of_material'   => 'Source of Material',
];

if ($confirmed) {
    $results = [];
    $r2_keys_to_delete = [];
    if (function_exists('r2_delete')) {
        $r2_keys = $db->query("
            SELECT doc_delivery_challan, doc_vendor_receipt, doc_weightbridge, doc_mtc, freight_inv_file
            FROM despatch_orders
        ");
        if ($r2_keys) {
            while ($row = $r2_keys->fetch_assoc()) {
                foreach ($row as $key) {
                    $key = trim((string)$key);
                    if ($key !== '' && strpos($key, '/') !== false && strpos($key, 'uploads/') !== 0) {
                        $r2_keys_to_delete[$key] = true;
                    }
                }
            }
        }
    }

    // Disable FK checks temporarily
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables_to_clean as $table => $label) {
        // Check if table exists before truncating
        $exists = $db->query("SHOW TABLES LIKE '$table'")->num_rows;
        if ($exists) {
            $count = (int)$db->query("SELECT COUNT(*) c FROM `$table`")->fetch_assoc()['c'];
            $db->query("TRUNCATE TABLE `$table`");
            $results[] = ['table' => $label, 'count' => $count, 'ok' => true];
        } else {
            $results[] = ['table' => $label, 'count' => 0, 'ok' => true, 'skip' => true];
        }
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");

    // Also clean uploaded delivery docs and freight invoices
    $clean_dirs = [
        dirname(__DIR__) . '/uploads/delivery_docs',
        dirname(__DIR__) . '/uploads/freight_invoices',
        dirname(__DIR__) . '/uploads/mtc_docs',
    ];
    $files_deleted = 0;
    foreach ($clean_dirs as $dir) {
        if (is_dir($dir)) {
            foreach (glob("$dir/*") as $f) {
                if (is_file($f)) { unlink($f); $files_deleted++; }
            }
        }
    }

    if (function_exists('r2_delete')) {
        $r2_deleted = 0;
        foreach (array_keys($r2_keys_to_delete) as $key) {
            if (r2_delete($key)) $r2_deleted++;
        }
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-trash3 me-2"></i>Cleanup Data';</script>

<div class="row justify-content-center">
<div class="col-12 col-lg-8">

<?php if ($confirmed): ?>
<!-- ── Results ── -->
<div class="card border-success">
    <div class="card-header bg-success text-white"><i class="bi bi-check-circle me-2"></i>Cleanup Complete</div>
    <div class="card-body">
        <table class="table table-sm mb-3">
            <thead><tr><th>Table</th><th class="text-end">Records Deleted</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($results as $r): ?>
            <tr>
                <td><?= $r['table'] ?></td>
                <td class="text-end"><?= $r['count'] ?></td>
                <td>
                    <?php if (!empty($r['skip'])): ?>
                    <span class="badge bg-secondary">Skipped (table not found)</span>
                    <?php else: ?>
                    <span class="badge bg-success"><i class="bi bi-check2"></i> Cleared</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($files_deleted > 0): ?>
        <div class="alert alert-info py-2 mb-3">
            <i class="bi bi-folder-x me-1"></i> <?= $files_deleted ?> uploaded file(s) deleted from delivery_docs, freight_invoices, mtc_docs folders.
        </div>
        <?php endif; ?>
        <?php if (!empty($r2_deleted)): ?>
        <div class="alert alert-info py-2 mb-3">
            <i class="bi bi-cloud-x me-1"></i> <?= (int)$r2_deleted ?> despatch upload(s) deleted from Cloudflare R2.
        </div>
        <?php endif; ?>
        <div class="alert alert-success py-2 mb-0">
            <i class="bi bi-info-circle me-1"></i> Challan numbering has been reset. Set your starting number in
            <a href="company_settings.php" class="alert-link">Company Settings → FY Challan Starting Number</a>.
        </div>
        <div class="mt-3">
            <a href="../index.php" class="btn btn-primary"><i class="bi bi-speedometer2 me-1"></i>Go to Dashboard</a>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── Confirmation Form ── -->
<div class="card border-danger">
    <div class="card-header bg-danger text-white"><i class="bi bi-exclamation-triangle me-2"></i>Data Cleanup — Confirm Action</div>
    <div class="card-body">
        <div class="alert alert-danger py-2">
            <strong>WARNING:</strong> This action is <strong>irreversible</strong>. All data in the tables below will be permanently deleted.
        </div>

        <div class="row g-4">
            <div class="col-12 col-md-6">
                <h6 class="fw-bold text-danger"><i class="bi bi-trash3 me-1"></i>Will be DELETED:</h6>
                <table class="table table-sm table-bordered">
                <?php foreach ($tables_to_clean as $table => $label):
                    $exists = $db->query("SHOW TABLES LIKE '$table'")->num_rows;
                    $count = $exists ? (int)$db->query("SELECT COUNT(*) c FROM `$table`")->fetch_assoc()['c'] : 0;
                ?>
                    <tr>
                        <td><?= $label ?></td>
                        <td class="text-end fw-bold text-danger"><?= $count ?> rows</td>
                    </tr>
                <?php endforeach; ?>
                </table>
                <p class="text-muted small">Also deletes uploaded files in delivery_docs, freight_invoices, mtc_docs.</p>
            </div>
            <div class="col-12 col-md-6">
                <h6 class="fw-bold text-success"><i class="bi bi-shield-check me-1"></i>Will be KEPT (untouched):</h6>
                <table class="table table-sm table-bordered">
                <?php foreach ($tables_to_keep as $table => $label):
                    $exists = $db->query("SHOW TABLES LIKE '$table'")->num_rows;
                    $count = $exists ? (int)$db->query("SELECT COUNT(*) c FROM `$table`")->fetch_assoc()['c'] : 0;
                ?>
                    <tr>
                        <td><?= $label ?></td>
                        <td class="text-end fw-bold text-success"><?= $count ?> rows</td>
                    </tr>
                <?php endforeach; ?>
                </table>
            </div>
        </div>

        <hr>
        <form method="POST" onsubmit="return confirmCleanup()">
            <input type="hidden" name="confirm_cleanup" value="YES_DELETE_NOW">
            <div class="d-flex align-items-center gap-3">
                <a href="company_settings.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-danger px-4" id="cleanupBtn">
                    <i class="bi bi-trash3 me-1"></i>Delete All Data Listed Above
                </button>
            </div>
        </form>
        <script>
        function confirmCleanup() {
            return confirm('FINAL WARNING!\n\nThis will permanently delete ALL despatch orders, invoices, payments, commissions, and uploaded documents.\n\nItems, POs, Transporters, and Vendors will NOT be touched.\n\nAre you absolutely sure?');
        }
        </script>
    </div>
</div>
<?php endif; ?>

</div>
</div>

<?php include '../includes/footer.php'; ?>
