<?php
require_once '../includes/config.php';
if (file_exists(__DIR__ . '/../includes/r2_helper.php') && !function_exists('r2_upload')) {
    require_once __DIR__ . '/../includes/r2_helper.php';
}
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
requirePerm('company_settings', 'update');

if (!function_exists('r2_upload')) {
    showAlert('danger', 'Cloudflare R2 helper is not available.');
    redirect('despatch.php');
}

function migrateLegacyUploadToR2(mysqli $db, array $row, string $column, string $legacyFolder, string $r2Folder): array {
    $current = trim((string)($row[$column] ?? ''));
    if ($current === '') {
        return ['status' => 'empty', 'message' => 'No file'];
    }
    if (strpos($current, '/') !== false && strpos($current, 'uploads/') !== 0) {
        return ['status' => 'skipped', 'message' => 'Already using R2'];
    }

    $filename = strpos($current, 'uploads/') === 0 ? basename($current) : $current;
    $localPath = dirname(__DIR__) . '/uploads/' . trim($legacyFolder, '/') . '/' . $filename;
    if (!is_file($localPath)) {
        return ['status' => 'missing', 'message' => 'Local file not found'];
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $newKey = trim($r2Folder, '/') . '/D' . (int)$row['id'] . '_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $column)) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
    if (!r2_upload($localPath, $newKey, r2_mime($filename))) {
        return ['status' => 'failed', 'message' => 'R2 upload failed'];
    }

    $escapedKey = $db->real_escape_string($newKey);
    $updated = $db->query("UPDATE despatch_orders SET `$column`='$escapedKey' WHERE id=" . (int)$row['id']);
    if (!$updated) {
        r2_delete($newKey);
        return ['status' => 'failed', 'message' => 'Database update failed: ' . $db->error];
    }

    return ['status' => 'migrated', 'message' => $newKey];
}

$fileColumns = [
    'doc_delivery_challan' => ['legacy' => 'delivery_docs', 'r2' => 'despatch/delivery_docs'],
    'doc_vendor_receipt'   => ['legacy' => 'delivery_docs', 'r2' => 'despatch/delivery_docs'],
    'doc_weightbridge'     => ['legacy' => 'delivery_docs', 'r2' => 'despatch/delivery_docs'],
    'doc_mtc'              => ['legacy' => 'mtc_docs', 'r2' => 'despatch/mtc_docs'],
    'freight_inv_file'     => ['legacy' => 'freight_invoices', 'r2' => 'despatch/freight_invoices'],
];

$ran = false;
$results = [];
$summary = ['migrated' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0, 'empty' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run_migration'])) {
    $ran = true;
    $rows = $db->query("SELECT id, challan_no, doc_delivery_challan, doc_vendor_receipt, doc_weightbridge, doc_mtc, freight_inv_file FROM despatch_orders ORDER BY id ASC");
    while ($row = $rows->fetch_assoc()) {
        foreach ($fileColumns as $column => $cfg) {
            $result = migrateLegacyUploadToR2($db, $row, $column, $cfg['legacy'], $cfg['r2']);
            $results[] = [
                'id' => (int)$row['id'],
                'challan_no' => $row['challan_no'] ?: ('ID ' . $row['id']),
                'column' => $column,
                'status' => $result['status'],
                'message' => $result['message'],
            ];
            $summary[$result['status']]++;
        }
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-cloud-arrow-up me-2"></i>Migrate Despatch Uploads to R2';</script>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <div class="card border-primary mb-4">
            <div class="card-header bg-primary text-white fw-semibold">
                <i class="bi bi-cloud-check me-2"></i>Despatch Upload Migration
            </div>
            <div class="card-body">
                <p class="mb-3">This tool uploads legacy despatch files from the local server to Cloudflare R2 and updates the corresponding file keys in <code>despatch_orders</code>.</p>
                <div class="alert alert-warning py-2">
                    It checks these columns: <code>doc_delivery_challan</code>, <code>doc_vendor_receipt</code>, <code>doc_weightbridge</code>, <code>doc_mtc</code>, and <code>freight_inv_file</code>. This migration does not delete local files.
                </div>
                <form method="POST">
                    <input type="hidden" name="run_migration" value="1">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Start migrating existing despatch uploads to Cloudflare R2?');">
                        <i class="bi bi-cloud-upload me-1"></i>Run Migration
                    </button>
                </form>
            </div>
        </div>

        <?php if ($ran): ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2"><div class="card border-success"><div class="card-body text-center"><div class="fs-4 fw-bold text-success"><?= $summary['migrated'] ?></div><div class="small text-muted">Migrated</div></div></div></div>
            <div class="col-6 col-md-2"><div class="card border-secondary"><div class="card-body text-center"><div class="fs-4 fw-bold text-secondary"><?= $summary['skipped'] ?></div><div class="small text-muted">Already R2</div></div></div></div>
            <div class="col-6 col-md-2"><div class="card border-warning"><div class="card-body text-center"><div class="fs-4 fw-bold text-warning"><?= $summary['missing'] ?></div><div class="small text-muted">Missing Local</div></div></div></div>
            <div class="col-6 col-md-2"><div class="card border-danger"><div class="card-body text-center"><div class="fs-4 fw-bold text-danger"><?= $summary['failed'] ?></div><div class="small text-muted">Failed</div></div></div></div>
            <div class="col-6 col-md-2"><div class="card border-light"><div class="card-body text-center"><div class="fs-4 fw-bold"><?= $summary['empty'] ?></div><div class="small text-muted">Empty</div></div></div></div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Migration Log</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Despatch</th>
                                <th>Column</th>
                                <th>Status</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['challan_no']) ?></td>
                                <td><code><?= htmlspecialchars($row['column']) ?></code></td>
                                <td>
                                    <span class="badge bg-<?= $row['status'] === 'migrated' ? 'success' : ($row['status'] === 'failed' ? 'danger' : ($row['status'] === 'missing' ? 'warning text-dark' : 'secondary')) ?>">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td><small><?= htmlspecialchars($row['message']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
