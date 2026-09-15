<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('vendor_receipts', 'view');

$company_id = (int)activeCompanyId();

$db->query("CREATE TABLE IF NOT EXISTS sales_vendor_receipts (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    company_id          INT NOT NULL DEFAULT 1,
    vendor_id           INT NOT NULL,
    receipt_date        DATE NOT NULL,
    amount_received     DECIMAL(12,2) NOT NULL DEFAULT 0,
    unallocated_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_mode        VARCHAR(40) DEFAULT 'NEFT',
    reference_no        VARCHAR(120) DEFAULT NULL,
    remarks             TEXT,
    created_by          INT DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS sales_vendor_receipt_allocations (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id          INT NOT NULL,
    invoice_id          INT NOT NULL,
    allocated_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_receipt (receipt_id),
    KEY idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function vendorOpenInvoices(mysqli $db, int $vendor_id, int $company_id): array {
    $rows = $db->query("SELECT
            si.id, si.invoice_number, si.invoice_date, si.total_amount,
            COALESCE((SELECT SUM(p.amount) FROM sales_invoice_payments p WHERE p.invoice_id=si.id),0) AS paid_amount
        FROM sales_invoices si
        LEFT JOIN despatch_orders d ON d.id = si.challan_id
        WHERE si.company_id = $company_id AND d.vendor_id = $vendor_id
        ORDER BY si.invoice_date ASC, si.id ASC")->fetch_all(MYSQLI_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $balance = (float)$r['total_amount'] - (float)$r['paid_amount'];
        if ($balance > 0.0001) {
            $r['balance_due'] = $balance;
            $out[] = $r;
        }
    }
    return $out;
}

function allocateReceiptFIFO(mysqli $db, int $receipt_id): void {
    $rc = $db->query("SELECT * FROM sales_vendor_receipts WHERE id=$receipt_id LIMIT 1")->fetch_assoc();
    if (!$rc) return;
    $remaining = (float)$rc['unallocated_amount'];
    if ($remaining <= 0) return;

    $vendor_id = (int)$rc['vendor_id'];
    $company_id = (int)$rc['company_id'];
    $open = vendorOpenInvoices($db, $vendor_id, $company_id);

    foreach ($open as $inv) {
        if ($remaining <= 0) break;
        $alloc = min($remaining, (float)$inv['balance_due']);
        if ($alloc <= 0) continue;

        $db->query("INSERT INTO sales_vendor_receipt_allocations (receipt_id,invoice_id,allocated_amount)
            VALUES ($receipt_id,{$inv['id']},$alloc)");

        $mode = sanitize($rc['payment_mode'] ?? 'NEFT');
        $ref = sanitize($rc['reference_no'] ?? '');
        $rmark = sanitize(trim(($rc['remarks'] ?? '') . ' [Receipt #' . $receipt_id . ']'));
        $rdate = sanitize($rc['receipt_date']);
        $db->query("INSERT INTO sales_invoice_payments (invoice_id,payment_date,amount,payment_mode,reference_no,remarks)
            VALUES ({$inv['id']},'$rdate',$alloc,'$mode','$ref','$rmark')");

        $remaining -= $alloc;
    }

    $remaining = max(0, $remaining);
    $db->query("UPDATE sales_vendor_receipts SET unallocated_amount=$remaining WHERE id=$receipt_id");
}

function allocateReceiptManualMap(mysqli $db, int $receipt_id, array $allocs): void {
    $rc = $db->query("SELECT * FROM sales_vendor_receipts WHERE id=$receipt_id LIMIT 1")->fetch_assoc();
    if (!$rc) return;
    $remaining = (float)$rc['unallocated_amount'];
    if ($remaining <= 0) return;

    $open = vendorOpenInvoices($db, (int)$rc['vendor_id'], (int)$rc['company_id']);
    $openMap = [];
    foreach ($open as $o) $openMap[(int)$o['id']] = (float)$o['balance_due'];

    $mode = sanitize($rc['payment_mode'] ?? 'NEFT');
    $ref = sanitize($rc['reference_no'] ?? '');
    $rmark = sanitize(trim(($rc['remarks'] ?? '') . ' [Receipt #' . $receipt_id . ']'));
    $rdate = sanitize($rc['receipt_date']);

    foreach ($allocs as $invoice_id => $raw_amount) {
        $invoice_id = (int)$invoice_id;
        $amt = (float)$raw_amount;
        if ($amt <= 0 || $remaining <= 0) continue;
        if (!isset($openMap[$invoice_id])) continue;
        $allowed = min($remaining, $openMap[$invoice_id]);
        $alloc = min($amt, $allowed);
        if ($alloc <= 0) continue;

        $db->query("INSERT INTO sales_vendor_receipt_allocations (receipt_id,invoice_id,allocated_amount)
            VALUES ($receipt_id,$invoice_id,$alloc)");
        $db->query("INSERT INTO sales_invoice_payments (invoice_id,payment_date,amount,payment_mode,reference_no,remarks)
            VALUES ($invoice_id,'$rdate',$alloc,'$mode','$ref','$rmark')");

        $remaining -= $alloc;
        $openMap[$invoice_id] -= $alloc;
    }

    $remaining = max(0, $remaining);
    $db->query("UPDATE sales_vendor_receipts SET unallocated_amount=$remaining WHERE id=$receipt_id");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_receipt'])) {
    requirePerm('vendor_receipts', 'create');
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $receipt_date = sanitize($_POST['receipt_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount_received'] ?? 0);
    $mode = sanitize($_POST['payment_mode'] ?? 'NEFT');
    $ref = sanitize($_POST['reference_no'] ?? '');
    $remarks = sanitize($_POST['remarks'] ?? '');
    $auto_fifo = !empty($_POST['auto_fifo']);
    $allocs = $_POST['alloc'] ?? [];
    if ($vendor_id <= 0 || $amount <= 0) {
        showAlert('danger', 'Vendor and amount are required.');
        redirect('vendor_receipts.php');
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $db->query("INSERT INTO sales_vendor_receipts
        (company_id,vendor_id,receipt_date,amount_received,unallocated_amount,payment_mode,reference_no,remarks,created_by)
        VALUES ($company_id,$vendor_id,'$receipt_date',$amount,$amount,'$mode','$ref','$remarks',$uid)");
    $receipt_id = (int)$db->insert_id;

    if (is_array($allocs) && count($allocs) > 0) {
        allocateReceiptManualMap($db, $receipt_id, $allocs);
    }
    if ($auto_fifo) {
        allocateReceiptFIFO($db, $receipt_id);
    }

    showAlert('success', 'Vendor receipt recorded.');
    redirect('vendor_receipts.php?receipt_id=' . $receipt_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_fifo'])) {
    requirePerm('vendor_receipts', 'update');
    $receipt_id = (int)($_POST['receipt_id'] ?? 0);
    if ($receipt_id > 0) {
        allocateReceiptFIFO($db, $receipt_id);
        showAlert('success', 'FIFO allocation completed.');
    }
    redirect('vendor_receipts.php?receipt_id=' . $receipt_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_manual'])) {
    requirePerm('vendor_receipts', 'update');
    $receipt_id = (int)($_POST['receipt_id'] ?? 0);
    $allocs = $_POST['alloc'] ?? [];
    allocateReceiptManualMap($db, $receipt_id, $allocs);
    showAlert('success', 'Manual allocation saved.');
    redirect('vendor_receipts.php?receipt_id=' . $receipt_id);
}

$vendors = $db->query("SELECT DISTINCT v.id, v.vendor_name
    FROM sales_invoices si
    JOIN despatch_orders d ON d.id = si.challan_id
    JOIN vendors v ON v.id = d.vendor_id
    WHERE si.company_id = $company_id
    ORDER BY v.vendor_name ASC")->fetch_all(MYSQLI_ASSOC);

$selected_vendor_id = (int)($_GET['vendor_id'] ?? 0);
$selected_vendor_open_for_new = [];
if ($selected_vendor_id > 0) {
    $selected_vendor_open_for_new = vendorOpenInvoices($db, $selected_vendor_id, $company_id);
}

$selected_receipt_id = (int)($_GET['receipt_id'] ?? 0);
$selected_receipt = null;
$selected_vendor_open = [];
$selected_receipt_allocs = [];
if ($selected_receipt_id > 0) {
    $selected_receipt = $db->query("SELECT r.*, v.vendor_name
        FROM sales_vendor_receipts r
        JOIN vendors v ON v.id = r.vendor_id
        WHERE r.id = $selected_receipt_id AND r.company_id = $company_id
        LIMIT 1")->fetch_assoc();
    if ($selected_receipt) {
        $selected_vendor_open = vendorOpenInvoices($db, (int)$selected_receipt['vendor_id'], $company_id);
        $selected_receipt_allocs = $db->query("SELECT a.invoice_id, a.allocated_amount, si.invoice_number, si.invoice_date
            FROM sales_vendor_receipt_allocations a
            JOIN sales_invoices si ON si.id = a.invoice_id
            WHERE a.receipt_id = $selected_receipt_id
            ORDER BY a.id ASC")->fetch_all(MYSQLI_ASSOC);
    }
}

$receipts = $db->query("SELECT r.*, v.vendor_name,
    COALESCE((SELECT SUM(a.allocated_amount) FROM sales_vendor_receipt_allocations a WHERE a.receipt_id=r.id),0) AS allocated_total
    FROM sales_vendor_receipts r
    JOIN vendors v ON v.id = r.vendor_id
    WHERE r.company_id = $company_id
    ORDER BY r.receipt_date DESC, r.id DESC
    LIMIT 200")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-wallet2 me-2"></i>Vendor Receipts';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Vendor Receipts</h5>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="card border-success">
            <div class="card-header bg-success text-white"><i class="bi bi-plus-circle me-2"></i>Record Receipt</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="add_receipt" value="1">
                    <div class="mb-2">
                        <label class="form-label">Vendor</label>
                        <select id="vendor_id_select" name="vendor_id" class="form-select" required>
                            <option value="">Select vendor</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= $selected_vendor_id === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['vendor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="var v=document.getElementById('vendor_id_select').value; if(v){ window.location='?vendor_id='+v; }">
                            <i class="bi bi-arrow-repeat me-1"></i>Load Invoices
                        </button>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Date</label>
                            <input type="date" name="receipt_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount_received" class="form-control" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Mode</label>
                            <select name="payment_mode" class="form-select">
                                <option>NEFT</option><option>RTGS</option><option>IMPS</option>
                                <option>Cheque</option><option>Cash</option><option>UPI</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Reference</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="UTR / Cheque No">
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Remarks</label>
                        <input type="text" name="remarks" class="form-control" placeholder="Optional">
                    </div>
                    <?php if ($selected_vendor_id > 0): ?>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Bill-wise Allocation (optional)</label>
                        <div class="table-responsive border rounded" style="max-height:220px;">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Invoice</th><th>Bal Due</th><th>Allocate</th></tr></thead>
                                <tbody>
                                <?php foreach ($selected_vendor_open_for_new as $inv): ?>
                                <tr>
                                    <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                                    <td class="text-danger">&#8377;<?= number_format((float)$inv['balance_due'], 2) ?></td>
                                    <td><input type="number" step="0.01" min="0" max="<?= htmlspecialchars((string)$inv['balance_due']) ?>" name="alloc[<?= (int)$inv['id'] ?>]" class="form-control form-control-sm" placeholder="0.00"></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$selected_vendor_open_for_new): ?>
                                <tr><td colspan="3" class="text-muted">No open invoices for this vendor.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-light border mt-3 mb-0 py-2">
                        Select vendor and click <strong>Load Invoices</strong> to enter bill-wise allocation.
                    </div>
                    <?php endif; ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" value="1" id="auto_fifo" name="auto_fifo">
                        <label class="form-check-label" for="auto_fifo">Allocate remaining amount by FIFO</label>
                    </div>
                    <button type="submit" class="btn btn-success w-100 mt-3"><i class="bi bi-check2 me-1"></i>Save Receipt</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>Recent Receipts</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Date</th><th>Vendor</th><th>Amount</th><th>Allocated</th><th>On Account</th><th>Invoice Ref</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($receipts as $r): ?>
                    <?php
                        $refs = $db->query("SELECT si.invoice_number
                            FROM sales_vendor_receipt_allocations a
                            JOIN sales_invoices si ON si.id=a.invoice_id
                            WHERE a.receipt_id=".(int)$r['id']." LIMIT 3")->fetch_all(MYSQLI_ASSOC);
                        $refTxt = [];
                        foreach ($refs as $rf) $refTxt[] = $rf['invoice_number'];
                    ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($r['receipt_date'])) ?></td>
                        <td><?= htmlspecialchars($r['vendor_name']) ?></td>
                        <td class="fw-semibold">&#8377;<?= number_format((float)$r['amount_received'], 2) ?></td>
                        <td class="text-success">&#8377;<?= number_format((float)$r['allocated_total'], 2) ?></td>
                        <td class="<?= (float)$r['unallocated_amount'] > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">&#8377;<?= number_format((float)$r['unallocated_amount'], 2) ?></td>
                        <td><?= htmlspecialchars($refTxt ? implode(', ', $refTxt) : '-') ?></td>
                        <td><a href="?receipt_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$receipts): ?>
                    <tr><td colspan="7" class="text-muted p-3">No receipts yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($selected_receipt): ?>
<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-2"></i>Allocate Receipt #<?= (int)$selected_receipt['id'] ?> - <?= htmlspecialchars($selected_receipt['vendor_name']) ?></span>
        <span class="badge bg-warning text-dark">On Account: &#8377;<?= number_format((float)$selected_receipt['unallocated_amount'], 2) ?></span>
    </div>
    <div class="card-body">
        <?php if ($selected_receipt_allocs): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm">
                <thead><tr><th>Allocated Invoices</th><th>Date</th><th>Amount</th></tr></thead>
                <tbody>
                <?php foreach ($selected_receipt_allocs as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['invoice_number']) ?></td>
                    <td><?= date('d/m/Y', strtotime($a['invoice_date'])) ?></td>
                    <td class="text-success">&#8377;<?= number_format((float)$a['allocated_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="allocate_fifo" value="1">
            <input type="hidden" name="receipt_id" value="<?= (int)$selected_receipt['id'] ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Run FIFO Allocation</button>
        </form>

        <form method="POST" class="mt-3">
            <input type="hidden" name="allocate_manual" value="1">
            <input type="hidden" name="receipt_id" value="<?= (int)$selected_receipt['id'] ?>">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Invoice No</th><th>Date</th><th>Total</th><th>Balance Due</th><th>Allocate Now</th></tr></thead>
                    <tbody>
                    <?php foreach ($selected_vendor_open as $inv): ?>
                    <tr>
                        <td><a href="sales_invoices.php?action=view&id=<?= (int)$inv['id'] ?>" target="_blank"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                        <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                        <td>&#8377;<?= number_format((float)$inv['total_amount'], 2) ?></td>
                        <td class="text-danger fw-semibold">&#8377;<?= number_format((float)$inv['balance_due'], 2) ?></td>
                        <td><input type="number" step="0.01" min="0" max="<?= htmlspecialchars((string)$inv['balance_due']) ?>" name="alloc[<?= (int)$inv['id'] ?>]" class="form-control form-control-sm" placeholder="0.00"></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$selected_vendor_open): ?>
                    <tr><td colspan="5" class="text-muted">No open invoices for this vendor.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Manual Allocation</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
