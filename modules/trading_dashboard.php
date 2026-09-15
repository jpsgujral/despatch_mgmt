<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/trading_common.php';

$db = getDB();
requirePerm('trading_dashboard', 'view');
tradingEnsureTables($db);

$today = tradingFetchRow($db, "SELECT
    COUNT(*) AS cnt,
    COALESCE(SUM(final_qty),0) AS final_qty,
    COALESCE(SUM(royalty_mt),0) AS royalty_mt,
    COALESCE(SUM(amount),0) AS amount
    FROM trading_receipts
    WHERE receipt_date=CURDATE() AND status<>'Cancelled'");

$month = tradingFetchRow($db, "SELECT
    COUNT(*) AS cnt,
    COALESCE(SUM(quantity_received),0) AS quantity_received,
    COALESCE(SUM(deduction_qty),0) AS deduction_qty,
    COALESCE(SUM(final_qty),0) AS final_qty,
    COALESCE(SUM(royalty_mt),0) AS royalty_mt,
    COALESCE(SUM(amount),0) AS amount
    FROM trading_receipts
    WHERE MONTH(receipt_date)=MONTH(CURDATE())
      AND YEAR(receipt_date)=YEAR(CURDATE())
      AND status<>'Cancelled'");

$recent = tradingFetchAll($db, "SELECT tr.*, v.supplier_name AS vendor_name, i.item_name
    FROM trading_receipts tr
    LEFT JOIN aggregate_suppliers v ON v.id=tr.vendor_id
    LEFT JOIN aggregate_items i ON i.id=tr.item_id
    ORDER BY tr.receipt_date DESC, tr.id DESC
    LIMIT 10");

$status_rows = tradingFetchAll($db, "SELECT status, COUNT(*) AS cnt
    FROM trading_receipts
    GROUP BY status");
$status_counts = ['Draft' => 0, 'Confirmed' => 0, 'Cancelled' => 0];
foreach ($status_rows as $row) {
    $status_counts[$row['status']] = (int)$row['cnt'];
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-minecart-loaded me-2"></i>Aggregate Dashboard';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Aggregate Management</h5>
        <div class="text-muted small">Separate inward workflow using aggregate-only suppliers, items, and sources.</div>
    </div>
    <?php if (canDo('trading_register', 'create')): ?>
    <a href="trading_register.php?action=add" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>New Receipt
    </a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Today Receipts</div><div class="fs-4 fw-bold text-primary"><?= (int)($today['cnt'] ?? 0) ?></div><div class="small text-muted"><?= number_format((float)($today['final_qty'] ?? 0), 3) ?> final qty</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Month Received</div><div class="fs-4 fw-bold text-success"><?= number_format((float)($month['quantity_received'] ?? 0), 3) ?></div><div class="small text-muted"><?= number_format((float)($month['deduction_qty'] ?? 0), 3) ?> deducted</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Month Final Qty</div><div class="fs-4 fw-bold text-warning"><?= number_format((float)($month['final_qty'] ?? 0), 3) ?></div><div class="small text-muted"><?= number_format((float)($month['royalty_mt'] ?? 0), 3) ?> royalty MT</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Month Value</div><div class="fs-4 fw-bold text-danger">Rs.<?= number_format((float)($month['amount'] ?? 0), 2) ?></div><div class="small text-muted"><?= (int)($month['cnt'] ?? 0) ?> entries</div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-pie-chart me-2"></i>Status Summary</div>
            <div class="card-body">
                <?php foreach ($status_counts as $status => $count): ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span><?= htmlspecialchars($status) ?></span>
                    <span class="badge bg-<?= $status === 'Confirmed' ? 'success' : ($status === 'Cancelled' ? 'danger' : 'secondary') ?>"><?= (int)$count ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff">
                <span class="fw-bold"><i class="bi bi-clock-history me-2"></i>Recent Aggregate Receipts</span>
                <a href="trading_register.php" class="btn btn-sm btn-light fw-bold">Open Aggregate Receipt</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Receipt No</th><th>Date</th><th>Supplier</th><th>Item</th><th class="text-end">Final Qty</th><th class="text-end">Royalty MT</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No trading receipts yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent as $row): ?>
                            <tr>
                                <td><a href="trading_register.php?action=edit&id=<?= (int)$row['id'] ?>" class="text-decoration-none fw-bold"><?= htmlspecialchars($row['receipt_no']) ?></a></td>
                                <td><?= !empty($row['receipt_date']) ? date('d/m/Y', strtotime($row['receipt_date'])) : '-' ?></td>
                                <td><?= htmlspecialchars($row['vendor_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
                                <td class="text-end"><?= number_format((float)$row['final_qty'], 3) ?></td>
                                <td class="text-end"><?= number_format((float)$row['royalty_mt'], 3) ?></td>
                                <td><span class="badge bg-<?= $row['status'] === 'Confirmed' ? 'success' : ($row['status'] === 'Cancelled' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
