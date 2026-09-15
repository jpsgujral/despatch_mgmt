<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/trading_common.php';

$db = getDB();
requirePerm('trading_register', 'view');
tradingEnsureTables($db);

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if (isset($_GET['delete'])) {
    requirePerm('trading_register', 'delete');
    $del_id = (int)$_GET['delete'];
    $db->query("DELETE FROM trading_receipts WHERE id=$del_id");
    showAlert($db->affected_rows > 0 ? 'success' : 'danger', $db->affected_rows > 0 ? 'Aggregate receipt deleted.' : 'Aggregate receipt not found.');
    redirect('trading_register.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('trading_register', $id > 0 ? 'update' : 'create');

    $receipt_date = sanitize($_POST['receipt_date'] ?? date('Y-m-d'));
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $item_id = (int)($_POST['item_id'] ?? 0);
    $source_id = (int)($_POST['source_id'] ?? 0);
    $vehicle_no = sanitize($_POST['vehicle_no'] ?? '');
    $supplier_invoice_no = sanitize($_POST['supplier_invoice_no'] ?? '');
    $quantity_received = (float)($_POST['quantity_received'] ?? 0);
    $deduction_pct = (float)($_POST['deduction_pct'] ?? 0);
    $royalty_cmtr = (float)($_POST['royalty_cmtr'] ?? 0);
    $rate_per_unit = (float)($_POST['rate_per_unit'] ?? 0);
    $remarks = sanitize($_POST['remarks'] ?? '');
    $status = sanitize($_POST['status'] ?? 'Draft');

    $allowed_status = ['Draft', 'Confirmed', 'Cancelled'];
    if (!in_array($status, $allowed_status, true)) $status = 'Draft';

    $item = tradingFetchRow($db, "SELECT conversion_ratio, rate_per_unit FROM aggregate_items WHERE id=$item_id LIMIT 1");
    $conversion_ratio = (float)($item['conversion_ratio'] ?? 1);
    if ($rate_per_unit <= 0) $rate_per_unit = (float)($item['rate_per_unit'] ?? 0);
    $calc = tradingComputeValues($quantity_received, $deduction_pct, $royalty_cmtr, $conversion_ratio, $rate_per_unit);

    $errors = [];
    if ($vendor_id <= 0) $errors[] = 'Supplier is required.';
    if ($item_id <= 0) $errors[] = 'Item is required.';
    if ($quantity_received <= 0) $errors[] = 'Quantity received must be greater than zero.';

    if (!empty($errors)) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        if ($id > 0) {
            $db->query("UPDATE trading_receipts SET
                receipt_date='{$db->real_escape_string($receipt_date)}',
                vendor_id=$vendor_id,
                item_id=$item_id,
                source_id=" . ($source_id > 0 ? $source_id : 'NULL') . ",
                vehicle_no='{$db->real_escape_string($vehicle_no)}',
                supplier_invoice_no='{$db->real_escape_string($supplier_invoice_no)}',
                quantity_received=$quantity_received,
                deduction_pct=$deduction_pct,
                deduction_qty={$calc['deduction_qty']},
                final_qty={$calc['final_qty']},
                royalty_cmtr=$royalty_cmtr,
                conversion_ratio={$calc['conversion_ratio']},
                royalty_mt={$calc['royalty_mt']},
                rate_per_unit=$rate_per_unit,
                amount={$calc['amount']},
                remarks='{$db->real_escape_string($remarks)}',
                status='{$db->real_escape_string($status)}'
                WHERE id=$id");
            showAlert('success', 'Aggregate receipt updated.');
        } else {
            $receipt_no = tradingNextReceiptNo($db, true);
            $created_by = (int)($_SESSION['user_id'] ?? 0);
            $company_id = activeCompanyId();
            $db->query("INSERT INTO trading_receipts
                (receipt_no, receipt_date, vendor_id, item_id, source_id, vehicle_no, supplier_invoice_no,
                 quantity_received, deduction_pct, deduction_qty, final_qty, royalty_cmtr, conversion_ratio,
                 royalty_mt, rate_per_unit, amount, remarks, status, created_by, company_id)
                VALUES (
                 '{$db->real_escape_string($receipt_no)}',
                 '{$db->real_escape_string($receipt_date)}',
                 $vendor_id,
                 $item_id,
                 " . ($source_id > 0 ? $source_id : 'NULL') . ",
                 '{$db->real_escape_string($vehicle_no)}',
                 '{$db->real_escape_string($supplier_invoice_no)}',
                 $quantity_received,
                 $deduction_pct,
                 {$calc['deduction_qty']},
                 {$calc['final_qty']},
                 $royalty_cmtr,
                 {$calc['conversion_ratio']},
                 {$calc['royalty_mt']},
                 $rate_per_unit,
                 {$calc['amount']},
                 '{$db->real_escape_string($remarks)}',
                 '{$db->real_escape_string($status)}',
                 $created_by,
                 $company_id
                )");
            showAlert('success', 'Aggregate receipt created.');
        }
        redirect('trading_register.php');
    }
}

$vendors = tradingFetchAll($db, "SELECT id, supplier_name AS vendor_name FROM aggregate_suppliers WHERE status='Active' ORDER BY supplier_name");
$items = tradingFetchAll($db, "SELECT id, item_name, rate_per_unit AS unit_price, conversion_ratio FROM aggregate_items WHERE status='Active' ORDER BY item_name");
$sources = tradingFetchAll($db, "SELECT id, source_name FROM aggregate_sources WHERE status='Active' ORDER BY source_name");

$receipt = [
    'receipt_date' => date('Y-m-d'),
    'receipt_no' => tradingNextReceiptNo($db, false),
    'status' => 'Draft',
    'conversion_ratio' => 1,
];
if ($action === 'edit' && $id > 0) {
    $receipt = tradingFetchRow($db, "SELECT * FROM trading_receipts WHERE id=$id LIMIT 1") ?: $receipt;
}

$rows = tradingFetchAll($db, "SELECT tr.*, v.supplier_name AS vendor_name, i.item_name, s.source_name
    FROM trading_receipts tr
    LEFT JOIN aggregate_suppliers v ON v.id=tr.vendor_id
    LEFT JOIN aggregate_items i ON i.id=tr.item_id
    LEFT JOIN aggregate_sources s ON s.id=tr.source_id
    ORDER BY tr.receipt_date DESC, tr.id DESC");

$totals = ['received' => 0.0, 'final' => 0.0, 'royalty' => 0.0, 'amount' => 0.0];
foreach ($rows as $row) {
    if (($row['status'] ?? '') === 'Cancelled') continue;
    $totals['received'] += (float)($row['quantity_received'] ?? 0);
    $totals['final'] += (float)($row['final_qty'] ?? 0);
    $totals['royalty'] += (float)($row['royalty_mt'] ?? 0);
    $totals['amount'] += (float)($row['amount'] ?? 0);
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-journal-richtext me-2"></i>Aggregate Receipt';</script>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Aggregate Receipt</h5>
        <div class="small text-muted">Fully separate aggregate inward receipt using aggregate supplier, item, and source masters.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="trading_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="aggregate_suppliers.php" class="btn btn-outline-secondary"><i class="bi bi-truck-front me-1"></i>Suppliers</a>
        <a href="aggregate_items.php" class="btn btn-outline-secondary"><i class="bi bi-box-seam me-1"></i>Items</a>
        <?php if (canDo('trading_register', 'create')): ?>
        <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>New Receipt</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Received Qty</div><div class="fs-5 fw-bold"><?= number_format($totals['received'], 3) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Final Qty</div><div class="fs-5 fw-bold text-success"><?= number_format($totals['final'], 3) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Royalty MT</div><div class="fs-5 fw-bold text-warning"><?= number_format($totals['royalty'], 3) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Value</div><div class="fs-5 fw-bold text-danger">Rs.<?= number_format($totals['amount'], 2) ?></div></div></div></div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead class="table-light">
                    <tr><th>Receipt No</th><th>Date</th><th>Supplier</th><th>Item</th><th>Source</th><th class="text-end">Received</th><th class="text-end">Deduction %</th><th class="text-end">Final Qty</th><th class="text-end">Royalty MT</th><th class="text-end">Value</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($row['receipt_no']) ?></td>
                        <td><?= !empty($row['receipt_date']) ? date('d/m/Y', strtotime($row['receipt_date'])) : '-' ?></td>
                        <td><?= htmlspecialchars($row['vendor_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['source_name'] ?? '-') ?></td>
                        <td class="text-end"><?= number_format((float)$row['quantity_received'], 3) ?></td>
                        <td class="text-end"><?= number_format((float)$row['deduction_pct'], 3) ?></td>
                        <td class="text-end"><?= number_format((float)$row['final_qty'], 3) ?></td>
                        <td class="text-end"><?= number_format((float)$row['royalty_mt'], 3) ?></td>
                        <td class="text-end">Rs.<?= number_format((float)$row['amount'], 2) ?></td>
                        <td><span class="badge bg-<?= $row['status'] === 'Confirmed' ? 'success' : ($row['status'] === 'Cancelled' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td>
                            <?php if (canDo('trading_register', 'update')): ?>
                            <a href="?action=edit&id=<?= (int)$row['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (canDo('trading_register', 'delete')): ?>
                            <button onclick="confirmDelete(<?= (int)$row['id'] ?>,'trading_register.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold"><?= $action === 'edit' ? 'Edit Aggregate Receipt' : 'New Aggregate Receipt' ?></h5>
    <a href="trading_register.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="POST" id="tradingReceiptForm">
<div class="card shadow-sm mb-3">
    <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff"><i class="bi bi-minecart-loaded me-2"></i>Receipt Details</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-3"><label class="form-label">Receipt No</label><input type="text" class="form-control" value="<?= htmlspecialchars($receipt['receipt_no'] ?? '') ?>" readonly></div>
            <div class="col-6 col-md-3"><label class="form-label">Receipt Date *</label><input type="date" name="receipt_date" class="form-control" required value="<?= htmlspecialchars($receipt['receipt_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label">Supplier *</label><select name="vendor_id" class="form-select" required><option value="">Select Supplier</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int)$vendor['id'] ?>" <?= (int)($receipt['vendor_id'] ?? 0) === (int)$vendor['id'] ? 'selected' : '' ?>><?= htmlspecialchars($vendor['vendor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label">Item *</label><select name="item_id" id="item_id" class="form-select" required><option value="">Select Item</option><?php foreach ($items as $item): ?><option value="<?= (int)$item['id'] ?>" data-conversion="<?= htmlspecialchars((string)($item['conversion_ratio'] ?? 1)) ?>" data-rate="<?= htmlspecialchars((string)($item['unit_price'] ?? 0)) ?>" <?= (int)($receipt['item_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['item_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-4"><label class="form-label">Source of Material</label><select name="source_id" class="form-select"><option value="">Select Source</option><?php foreach ($sources as $source): ?><option value="<?= (int)$source['id'] ?>" <?= (int)($receipt['source_id'] ?? 0) === (int)$source['id'] ? 'selected' : '' ?>><?= htmlspecialchars($source['source_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-4"><label class="form-label">Vehicle No</label><input type="text" name="vehicle_no" class="form-control" value="<?= htmlspecialchars($receipt['vehicle_no'] ?? '') ?>"></div>
            <div class="col-6 col-md-4"><label class="form-label">Supplier Invoice No</label><input type="text" name="supplier_invoice_no" class="form-control" value="<?= htmlspecialchars($receipt['supplier_invoice_no'] ?? '') ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Quantity Received *</label><input type="number" name="quantity_received" id="quantity_received" class="form-control calc-field" step="0.001" min="0" required value="<?= htmlspecialchars((string)($receipt['quantity_received'] ?? '')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Deduction %</label><input type="number" name="deduction_pct" id="deduction_pct" class="form-control calc-field" step="0.001" min="0" max="100" value="<?= htmlspecialchars((string)($receipt['deduction_pct'] ?? '0')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Deduction Qty</label><input type="number" id="deduction_qty" class="form-control" step="0.001" readonly value="<?= htmlspecialchars((string)($receipt['deduction_qty'] ?? '0')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Final Qty</label><input type="number" id="final_qty" class="form-control" step="0.001" readonly value="<?= htmlspecialchars((string)($receipt['final_qty'] ?? '0')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Royalty Cmtr</label><input type="number" name="royalty_cmtr" id="royalty_cmtr" class="form-control calc-field" step="0.001" min="0" value="<?= htmlspecialchars((string)($receipt['royalty_cmtr'] ?? '0')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Conversion Ratio</label><input type="number" id="conversion_ratio" class="form-control" step="0.0001" readonly value="<?= htmlspecialchars((string)($receipt['conversion_ratio'] ?? '1.0000')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Royalty MT</label><input type="number" id="royalty_mt" class="form-control" step="0.001" readonly value="<?= htmlspecialchars((string)($receipt['royalty_mt'] ?? '0')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Rate</label><input type="number" name="rate_per_unit" id="rate_per_unit" class="form-control calc-field" step="0.01" min="0" value="<?= htmlspecialchars((string)($receipt['rate_per_unit'] ?? '0')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Amount</label><input type="number" id="amount" class="form-control" step="0.01" readonly value="<?= htmlspecialchars((string)($receipt['amount'] ?? '0')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['Draft', 'Confirmed', 'Cancelled'] as $st): ?><option value="<?= $st ?>" <?= ($receipt['status'] ?? 'Draft') === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($receipt['remarks'] ?? '') ?></textarea></div>
        </div>
    </div>
</div>
<div class="text-end"><a href="trading_register.php" class="btn btn-outline-secondary me-2">Cancel</a><button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $action === 'edit' ? 'Update' : 'Save' ?> Receipt</button></div>
</form>

<script>
function recalcTradingForm() {
    const qty = parseFloat(document.getElementById('quantity_received').value) || 0;
    const dedPct = Math.max(0, Math.min(100, parseFloat(document.getElementById('deduction_pct').value) || 0));
    const royaltyCmtr = parseFloat(document.getElementById('royalty_cmtr').value) || 0;
    const conversion = Math.max(0.0001, parseFloat(document.getElementById('conversion_ratio').value) || 1);
    const rate = parseFloat(document.getElementById('rate_per_unit').value) || 0;
    const deductionQty = qty * dedPct / 100;
    const finalQty = Math.max(0, qty - deductionQty);
    const royaltyMt = royaltyCmtr / conversion;
    const amount = finalQty * rate;
    document.getElementById('deduction_qty').value = deductionQty.toFixed(3);
    document.getElementById('final_qty').value = finalQty.toFixed(3);
    document.getElementById('royalty_mt').value = royaltyMt.toFixed(3);
    document.getElementById('amount').value = amount.toFixed(2);
}

function syncTradingItemMeta() {
    const sel = document.getElementById('item_id');
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    const conversion = parseFloat(opt.getAttribute('data-conversion') || '1') || 1;
    const rateField = document.getElementById('rate_per_unit');
    document.getElementById('conversion_ratio').value = conversion.toFixed(4);
    if (!rateField.value || parseFloat(rateField.value) === 0) {
        const itemRate = parseFloat(opt.getAttribute('data-rate') || '0') || 0;
        if (itemRate > 0) rateField.value = itemRate.toFixed(2);
    }
    recalcTradingForm();
}

document.getElementById('item_id')?.addEventListener('change', syncTradingItemMeta);
document.querySelectorAll('.calc-field').forEach(function(el) { el.addEventListener('input', recalcTradingForm); });
syncTradingItemMeta();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
