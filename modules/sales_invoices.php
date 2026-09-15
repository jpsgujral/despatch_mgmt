<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('sales_invoices', 'view');

$all_companies = getAllCompanies();

// ── Auto-migrate tables ──────────────────────────────────────
// Ensure payment_terms column is large enough
$db->query("ALTER TABLE sales_invoices MODIFY COLUMN payment_terms VARCHAR(500)");

$db->query("CREATE TABLE IF NOT EXISTS sales_invoices (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    company_id          INT DEFAULT 1,
    invoice_number      VARCHAR(60) NOT NULL,
    invoice_date        DATE NOT NULL,
    challan_id          INT DEFAULT NULL,
    consignee_name      VARCHAR(200),
    consignee_address   TEXT,
    consignee_city      VARCHAR(80),
    consignee_state     VARCHAR(80),
    consignee_gstin     VARCHAR(20),
    gst_type            VARCHAR(20) DEFAULT 'IGST',
    subtotal            DECIMAL(12,2) DEFAULT 0,
    cgst_amount         DECIMAL(12,2) DEFAULT 0,
    sgst_amount         DECIMAL(12,2) DEFAULT 0,
    igst_amount         DECIMAL(12,2) DEFAULT 0,
    total_amount        DECIMAL(12,2) DEFAULT 0,
    payment_terms       VARCHAR(500),
    due_date            DATE DEFAULT NULL,
    mrn_number          VARCHAR(80) DEFAULT NULL,
    mrn_date            DATE DEFAULT NULL,
    invoice_reg_number  VARCHAR(80) DEFAULT NULL,
    invoice_reg_date    DATE DEFAULT NULL,
    status              VARCHAR(30) DEFAULT 'Draft',
    remarks             TEXT,
    created_by          INT DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS sales_invoice_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    item_name       VARCHAR(200),
    hsn_code        VARCHAR(20),
    uom             VARCHAR(20),
    qty             DECIMAL(12,3) DEFAULT 0,
    unit_price      DECIMAL(12,2) DEFAULT 0,
    amount          DECIMAL(12,2) DEFAULT 0,
    gst_rate        DECIMAL(5,2) DEFAULT 0,
    gst_amount      DECIMAL(12,2) DEFAULT 0,
    cgst_rate       DECIMAL(5,2) DEFAULT 0,
    cgst_amount     DECIMAL(12,2) DEFAULT 0,
    sgst_rate       DECIMAL(5,2) DEFAULT 0,
    sgst_amount     DECIMAL(12,2) DEFAULT 0,
    igst_rate       DECIMAL(5,2) DEFAULT 0,
    igst_amount     DECIMAL(12,2) DEFAULT 0,
    total_amount    DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS sales_invoice_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    payment_date    DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_mode    VARCHAR(40) DEFAULT 'NEFT',
    reference_no    VARCHAR(80),
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Actions ──────────────────────────────────────────────────
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// Mark as Billed
if (isset($_GET['mark_billed'])) {
    $bid = (int)$_GET['mark_billed'];
    $db->query("UPDATE sales_invoices SET status='Sent' WHERE id=$bid AND status='Draft'");
    showAlert('success', 'Invoice marked as Billed.');
    redirect('sales_invoices.php');
}

// Delete invoice
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $db->query("DELETE FROM sales_invoice_items WHERE invoice_id=$did");
    $db->query("DELETE FROM sales_invoice_payments WHERE invoice_id=$did");
    $db->query("DELETE FROM sales_invoices WHERE id=$did");
    showAlert('success', 'Invoice deleted.');
    redirect('sales_invoices.php');
}

// Delete payment
if (isset($_GET['del_payment'])) {
    $pid = (int)$_GET['del_payment'];
    $inv = $db->query("SELECT invoice_id FROM sales_invoice_payments WHERE id=$pid")->fetch_assoc();
    $db->query("DELETE FROM sales_invoice_payments WHERE id=$pid");
    showAlert('success', 'Payment deleted.');
    redirect('sales_invoices.php?action=view&id='.($inv['invoice_id'] ?? $id));
}

// Add payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $inv_id  = (int)$_POST['invoice_id'];
    $pdate   = sanitize($_POST['payment_date']);
    $pamt    = (float)$_POST['payment_amount'];
    $pmode   = sanitize($_POST['payment_mode'] ?? 'NEFT');
    $pref    = sanitize($_POST['reference_no'] ?? '');
    $premark = sanitize($_POST['payment_remarks'] ?? '');
    $db->query("INSERT INTO sales_invoice_payments (invoice_id,payment_date,amount,payment_mode,reference_no,remarks)
        VALUES ($inv_id,'$pdate',$pamt,'$pmode','$pref','$premark')");
    // Auto-mark paid if fully paid
    $inv     = $db->query("SELECT total_amount FROM sales_invoices WHERE id=$inv_id")->fetch_assoc();
    $paid    = $db->query("SELECT SUM(amount) s FROM sales_invoice_payments WHERE invoice_id=$inv_id")->fetch_assoc()['s'] ?? 0;
    if ((float)$paid >= (float)$inv['total_amount'])
        $db->query("UPDATE sales_invoices SET status='Paid' WHERE id=$inv_id");
    showAlert('success', 'Payment recorded.');
    redirect('sales_invoices.php?action=view&id='.$inv_id);
}

// Update MRN / Reg No / Status / Linked Challan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fields'])) {
    $fid        = (int)$_POST['invoice_id'];
    $mrn        = sanitize($_POST['mrn_number'] ?? '');
    $mrndt      = sanitize($_POST['mrn_date'] ?? '');
    $reg        = sanitize($_POST['invoice_reg_number'] ?? '');
    $regdt      = sanitize($_POST['invoice_reg_date'] ?? '');
    $status     = sanitize($_POST['status'] ?? '');
    $challan_id = (int)($_POST['challan_id'] ?? 0);
    $mrndt      = $mrndt ?: 'NULL'; if ($mrndt !== 'NULL') $mrndt = "'$mrndt'";
    $regdt      = $regdt ?: 'NULL'; if ($regdt !== 'NULL') $regdt = "'$regdt'";
    $ch_sql     = $challan_id > 0 ? $challan_id : 'NULL';
    $db->query("UPDATE sales_invoices SET
        mrn_number='$mrn', mrn_date=$mrndt,
        invoice_reg_number='$reg', invoice_reg_date=$regdt,
        status='$status',
        challan_id=$ch_sql
        WHERE id=$fid");
    showAlert('success', 'Invoice updated.');
    redirect('sales_invoices.php?action=view&id='.$fid);
}

// Save new / edit invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice'])) {
    requirePerm('sales_invoices', $id > 0 ? 'update' : 'create');

    $inv_no     = sanitize($_POST['invoice_number']);
    $inv_date   = sanitize($_POST['invoice_date']);
    $cid        = (int)($_POST['company_id'] ?? activeCompanyId());
    $challan_id = (int)($_POST['challan_id'] ?? 0);
    $gst_type   = sanitize($_POST['gst_type'] ?? 'IGST');
    $con_name   = sanitize($_POST['consignee_name'] ?? '');
    $con_addr   = sanitize($_POST['consignee_address'] ?? '');
    $con_city   = sanitize($_POST['consignee_city'] ?? '');
    $con_state  = sanitize($_POST['consignee_state'] ?? '');
    $con_gstin  = sanitize($_POST['consignee_gstin'] ?? '');
    $pay_terms  = substr(sanitize($_POST['payment_terms'] ?? ''), 0, 500);
    $due_date   = sanitize($_POST['due_date'] ?? '');
    $remarks    = sanitize($_POST['remarks'] ?? '');
    $due_sql    = $due_date ? "'$due_date'" : 'NULL';

    // Items
    $item_names  = $_POST['item_name'] ?? [];
    $hsn_codes   = $_POST['hsn_code'] ?? [];
    $uoms        = $_POST['item_uom'] ?? [];
    $qtys        = $_POST['qty'] ?? [];
    $prices      = $_POST['unit_price'] ?? [];
    $gst_rates   = $_POST['gst_rate'] ?? [];

    $subtotal = $cgst_total = $sgst_total = $igst_total = 0;
    $valid_items = [];
    $is_split = ($gst_type === 'CGST+SGST');

    foreach ($item_names as $idx => $iname) {
        $iname = trim($iname);
        if (!$iname) continue;
        $qty   = (float)($qtys[$idx] ?? 0);
        $price = (float)($prices[$idx] ?? 0);
        $grate = (float)($gst_rates[$idx] ?? 0);
        $amt   = round($qty * $price, 2);
        $gamt  = round($amt * $grate / 100, 2);
        $subtotal += $amt;
        $row = [
            'item_name'   => $db->real_escape_string($iname),
            'hsn_code'    => $db->real_escape_string($hsn_codes[$idx] ?? ''),
            'uom'         => $db->real_escape_string($uoms[$idx] ?? ''),
            'qty'         => $qty, 'unit_price' => $price, 'amount' => $amt,
            'gst_rate'    => $grate, 'gst_amount' => $gamt,
            'cgst_rate'   => 0, 'cgst_amount' => 0,
            'sgst_rate'   => 0, 'sgst_amount' => 0,
            'igst_rate'   => 0, 'igst_amount' => 0,
            'total_amount'=> 0,
        ];
        if ($is_split) {
            $half = round($grate / 2, 2);
            $hamt = round($amt * $half / 100, 2);
            $row['cgst_rate'] = $half; $row['cgst_amount'] = $hamt;
            $row['sgst_rate'] = $half; $row['sgst_amount'] = $hamt;
            $cgst_total += $hamt; $sgst_total += $hamt;
        } else {
            $row['igst_rate'] = $grate; $row['igst_amount'] = $gamt;
            $igst_total += $gamt;
        }
        $row['total_amount'] = $amt + $gamt;
        $valid_items[] = $row;
    }

    $gst_total   = $cgst_total + $sgst_total + $igst_total;
    $total_amount = round($subtotal + $gst_total, 2);

    if (!$inv_no || !$inv_date) {
        showAlert('danger', 'Invoice Number and Date are required.');
        redirect('sales_invoices.php?action='.($id>0?'edit&id='.$id:'add'));
    }

    if ($id > 0) {
        $db->query("UPDATE sales_invoices SET
            invoice_number='$inv_no', invoice_date='$inv_date', company_id=$cid,
            challan_id=" . ($challan_id ?: 'NULL') . ", gst_type='$gst_type',
            consignee_name='$con_name', consignee_address='$con_addr',
            consignee_city='$con_city', consignee_state='$con_state', consignee_gstin='$con_gstin',
            payment_terms='$pay_terms', due_date=$due_sql, remarks='$remarks',
            subtotal=$subtotal, cgst_amount=$cgst_total, sgst_amount=$sgst_total,
            igst_amount=$igst_total, total_amount=$total_amount
            WHERE id=$id");
        $db->query("DELETE FROM sales_invoice_items WHERE invoice_id=$id");
    } else {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $db->query("INSERT INTO sales_invoices
            (invoice_number,invoice_date,company_id,challan_id,gst_type,
             consignee_name,consignee_address,consignee_city,consignee_state,consignee_gstin,
             payment_terms,due_date,remarks,subtotal,cgst_amount,sgst_amount,igst_amount,
             total_amount,created_by)
            VALUES ('$inv_no','$inv_date',$cid," . ($challan_id ?: 'NULL') . ",'$gst_type',
            '$con_name','$con_addr','$con_city','$con_state','$con_gstin',
            '$pay_terms',$due_sql,'$remarks',$subtotal,$cgst_total,$sgst_total,$igst_total,
            $total_amount,$uid)");
        $id = $db->insert_id;
    }

    foreach ($valid_items as $row) {
        $db->query("INSERT INTO sales_invoice_items
            (invoice_id,item_name,hsn_code,uom,qty,unit_price,amount,gst_rate,gst_amount,
             cgst_rate,cgst_amount,sgst_rate,sgst_amount,igst_rate,igst_amount,total_amount)
            VALUES ($id,'{$row['item_name']}','{$row['hsn_code']}','{$row['uom']}',
            {$row['qty']},{$row['unit_price']},{$row['amount']},{$row['gst_rate']},{$row['gst_amount']},
            {$row['cgst_rate']},{$row['cgst_amount']},{$row['sgst_rate']},{$row['sgst_amount']},
            {$row['igst_rate']},{$row['igst_amount']},{$row['total_amount']})");
    }

    showAlert('success', 'Invoice saved successfully.');
    redirect('sales_invoices.php?action=view&id='.$id);
}

// ── Fetch challans for dropdown (exclude already billed except current invoice) ─────
$edit_inv_id = ($id > 0) ? (int)$id : 0;
$challan_exclude_sql = $edit_inv_id > 0
    ? "SELECT challan_id FROM sales_invoices WHERE challan_id IS NOT NULL AND id != $edit_inv_id"
    : "SELECT challan_id FROM sales_invoices WHERE challan_id IS NOT NULL";

$challans = $db->query("SELECT d.id, d.challan_no, d.despatch_date, d.company_id,
    d.total_weight, d.freight_amount,
    COALESCE(v.vendor_name, d.consignee_name, '') AS consignee_name,
    COALESCE(NULLIF(v.bill_address,''), v.address, d.consignee_address, '') AS consignee_address,
    COALESCE(NULLIF(v.bill_city,''), v.city, d.consignee_city, '') AS consignee_city,
    COALESCE(NULLIF(v.bill_state,''), v.state, d.consignee_state, '') AS consignee_state,
    COALESCE(NULLIF(v.bill_gstin,''), v.gstin, d.consignee_gstin, '') AS consignee_gstin,
    COALESCE(v.vendor_name,'') AS vendor_name,
    po.payment_terms
    FROM despatch_orders d
    LEFT JOIN purchase_orders po ON d.po_id = po.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    WHERE d.id NOT IN ($challan_exclude_sql)
    AND d.status NOT IN ('Cancelled','Draft')
    ORDER BY d.despatch_date DESC, d.id DESC")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-5-theme/1.3.0/select2-bootstrap-5-theme.min.css">
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-receipt-cutoff me-2"></i>Sales Invoices';</script>

<?php
$status_colors = [
    'Draft'=>'secondary','Sent'=>'info','MRN Received'=>'primary',
    'Registered'=>'warning','Paid'=>'success'
];
$status_labels = [
    'Draft'=>'Not Billed','Sent'=>'Billed',
    'MRN Received'=>'MRN Received','Registered'=>'Registered','Paid'=>'Paid'
];

// ════════════════════════════════════════════════════════════
// LIST VIEW
// ════════════════════════════════════════════════════════════
if ($action === 'list'):
$list = $db->query("SELECT si.*, c.company_name AS co_name,
    d.challan_no,
    (SELECT SUM(amount) FROM sales_invoice_payments WHERE invoice_id=si.id) AS paid_amount
    FROM sales_invoices si
    LEFT JOIN companies c ON si.company_id = c.id
    LEFT JOIN despatch_orders d ON si.challan_id = d.id
    ORDER BY si.company_id ASC, si.invoice_date DESC, si.id DESC")->fetch_all(MYSQLI_ASSOC);

// Group by company
$inv_groups = [];
$all_statuses_found = [];
foreach ($list as $v) {
    $co = $v['co_name'] ?: 'Unassigned';
    $inv_groups[$co][] = $v;
    $all_statuses_found[$v['status']] = true;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Sales Invoices</h5>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>New Invoice</a>
</div>

<!-- Status Filter Bar -->
<div class="card mb-3">
<div class="card-body py-2 d-flex gap-1 flex-wrap align-items-center">
    <span class="text-muted me-1" style="font-size:.85rem">Filter:</span>
    <button class="btn btn-sm btn-dark si-status-btn active" data-status="All" onclick="siFilterStatus('All',this)">All <span class="badge bg-secondary ms-1"><?= count($list) ?></span></button>
    <?php foreach (array_keys($all_statuses_found) as $st):
        $cnt = count(array_filter($list, fn($v) => $v['status'] === $st));
        $sc  = $status_colors[$st] ?? 'secondary';
        $lbl = $status_labels[$st] ?? $st;
    ?>
    <button class="btn btn-sm btn-outline-<?= $sc ?> si-status-btn" data-status="<?= $st ?>" onclick="siFilterStatus('<?= $st ?>',this)">
        <?= $lbl ?> <span class="badge bg-<?= $sc ?> ms-1"><?= $cnt ?></span>
    </button>
    <?php endforeach; ?>
</div>
</div>

<?php if (empty($inv_groups)): ?>
<div class="card"><div class="card-body text-center text-muted p-4"><i class="bi bi-inbox me-2"></i>No invoices found.</div></div>
<?php else: ?>
<?php $gi = 0; foreach ($inv_groups as $coName => $invs): $gi++; $grpId = 'sigrp_'.$gi;
    $grpAmt  = array_sum(array_column($invs, 'total_amount'));
    $grpPaid = array_sum(array_column($invs, 'paid_amount'));
    $grpOut  = $grpAmt - $grpPaid;
    $grpStatuses = [];
    foreach ($invs as $v) $grpStatuses[$v['status']] = ($grpStatuses[$v['status']] ?? 0) + 1;
    $grpStatusList = implode(',', array_keys($grpStatuses));
?>
<div class="card mb-3 si-company-card" data-statuses="<?= htmlspecialchars($grpStatusList) ?>">
<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"
     style="cursor:pointer;background:#e5f5eb;border-top:2px solid #1a5632"
     onclick="siToggle('<?= $grpId ?>',this)">
    <span>
        <span class="si-toggle-icon me-1">▾</span>
        <i class="bi bi-building me-1 text-success"></i>
        <strong><?= htmlspecialchars($coName) ?></strong>
        <span class="badge bg-secondary ms-2"><?= count($invs) ?></span>
        <?php foreach ($grpStatuses as $st => $sc2):
            $sc = $status_colors[$st] ?? 'secondary';
            $lbl = $status_labels[$st] ?? $st;
        ?>
        <span class="badge bg-<?= $sc ?> ms-1" style="font-size:.6rem"><?= $sc2 ?> <?= $lbl ?></span>
        <?php endforeach; ?>
    </span>
    <span class="d-flex gap-3 align-items-center">
        <span class="text-muted" style="font-size:.8rem">Total: <strong>₹<?= number_format($grpAmt,2) ?></strong></span>
        <span class="text-success" style="font-size:.8rem">Paid: <strong>₹<?= number_format($grpPaid,2) ?></strong></span>
        <span class="<?= $grpOut > 0 ? 'text-danger' : 'text-success' ?>" style="font-size:.8rem">Outstanding: <strong>₹<?= number_format($grpOut,2) ?></strong></span>
    </span>
</div>
<div class="card-body p-0 <?= $grpId ?>-body">
<div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr>
    <th>#</th><th>Invoice No</th><th>Date</th><th>Challan No</th><th>Consignee</th>
    <th>Amount</th><th>Paid</th><th>Outstanding</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php $i = 1; foreach ($invs as $v):
    $paid  = (float)($v['paid_amount'] ?? 0);
    $outst = (float)$v['total_amount'] - $paid;
    $sc    = $status_colors[$v['status']] ?? 'secondary';
    $lbl   = $status_labels[$v['status']] ?? $v['status'];
?>
<tr class="si-inv-row" data-status="<?= htmlspecialchars($v['status']) ?>">
    <td><?= $i++ ?></td>
    <td><strong><?= htmlspecialchars($v['invoice_number']) ?></strong></td>
    <td><?= date('d/m/Y', strtotime($v['invoice_date'])) ?></td>
    <td>
        <?php if (!empty($v['challan_no'])): ?>
            <a href="despatch.php?action=edit&id=<?= (int)$v['challan_id'] ?>" class="text-primary text-decoration-none fw-semibold">
                <?= htmlspecialchars($v['challan_no']) ?>
            </a>
        <?php else: ?>
            <span class="text-muted">—</span>
        <?php endif; ?>
    </td>
    <td><?= htmlspecialchars($v['consignee_name']) ?></td>
    <td>₹<?= number_format($v['total_amount'], 2) ?></td>
    <td class="text-success">₹<?= number_format($paid, 2) ?></td>
    <td class="<?= $outst > 0 ? 'text-danger fw-bold' : 'text-success' ?>">₹<?= number_format($outst, 2) ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $lbl ?></span></td>
    <td>
        <a href="?action=view&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-info me-1"><i class="bi bi-eye"></i></a>
        <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <a href="print_invoice.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-action btn-outline-secondary me-1"><i class="bi bi-printer"></i></a>
        <?php if ($v['status'] === 'Draft'): ?>
        <a href="?mark_billed=<?= $v['id'] ?>" class="btn btn-action btn-outline-success me-1" title="Mark as Billed" onclick="return confirm('Mark this invoice as Billed?')"><i class="bi bi-check2-circle"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <button onclick="confirmDelete(<?= $v['id'] ?>,'sales_invoices.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>
<?php endforeach; ?>
<?php endif; // end empty check ?>

<?php endif; // end list ?>

<style>
.si-status-btn.active { box-shadow: 0 0 0 2px rgba(30,58,95,0.5); }
</style>
<script>
function siToggle(id, headerEl) {
    var body = document.querySelector('.' + id + '-body');
    var icon = headerEl.querySelector('.si-toggle-icon');
    var hidden = body.style.display === 'none';
    body.style.display = hidden ? '' : 'none';
    icon.textContent = hidden ? '▾' : '▸';
}
function siFilterStatus(status, btn) {
    document.querySelectorAll('.si-status-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.si-company-card').forEach(function(card) {
        var rows = card.querySelectorAll('.si-inv-row');
        if (status === 'All') {
            card.style.display = '';
            rows.forEach(function(r) { r.style.display = ''; });
            return;
        }
        var hasVisible = false;
        rows.forEach(function(r) {
            if (r.dataset.status === status) { r.style.display = ''; hasVisible = true; }
            else r.style.display = 'none';
        });
        card.style.display = hasVisible ? '' : 'none';
    });
}
</script>

<?php if ($action === 'view' && $id > 0):
$inv   = $db->query("SELECT si.*, c.company_name AS co_name, c.address AS co_address,
    c.city AS co_city, c.state AS co_state, c.gstin AS co_gstin, c.pan AS co_pan,
    c.phone AS co_phone, c.email AS co_email,
    c.bank_name, c.account_no, c.ifsc_code
    FROM sales_invoices si LEFT JOIN companies c ON si.company_id=c.id
    WHERE si.id=$id LIMIT 1")->fetch_assoc();
if (!$inv) { echo '<div class="alert alert-danger">Invoice not found.</div>'; include '../includes/footer.php'; exit; }
$items    = $db->query("SELECT * FROM sales_invoice_items WHERE invoice_id=$id")->fetch_all(MYSQLI_ASSOC);
$payments = $db->query("SELECT * FROM sales_invoice_payments WHERE invoice_id=$id ORDER BY payment_date")->fetch_all(MYSQLI_ASSOC);
$paid_total = array_sum(array_column($payments, 'amount'));
$outstanding = (float)$inv['total_amount'] - (float)$paid_total;
$is_split = ($inv['gst_type'] === 'CGST+SGST');
$sc = $status_colors[$inv['status']] ?? 'secondary';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Invoice: <?= htmlspecialchars($inv['invoice_number']) ?>
        <span class="badge bg-<?= $sc ?> ms-2"><?= $status_labels[$inv['status']] ?? $inv['status'] ?></span>
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="print_invoice.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
        <a href="?action=edit&id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <a href="sales_invoices.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-3">
<!-- Invoice Details -->
<div class="col-12 col-md-6">
<div class="card h-100"><div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>Invoice Details</div>
<div class="card-body">
    <table class="table table-sm mb-0">
        <tr><td class="text-muted" style="width:45%">Invoice No</td><td><strong><?= htmlspecialchars($inv['invoice_number']) ?></strong></td></tr>
        <tr><td class="text-muted">Invoice Date</td><td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td></tr>
        <tr><td class="text-muted">Company</td><td><?= htmlspecialchars($inv['co_name'] ?? '-') ?></td></tr>
        <tr><td class="text-muted">Linked Challan</td><td><?php
            if (!empty($inv['challan_id'])) {
                $chl = $db->query("SELECT id, challan_no FROM despatch_orders WHERE id=".(int)$inv['challan_id']." LIMIT 1")->fetch_assoc();
                if ($chl) {
                    echo '<a href="despatch.php?action=edit&id='.$chl['id'].'" target="_blank" class="fw-bold text-primary text-decoration-none"><i class="bi bi-file-earmark-text me-1"></i>'.htmlspecialchars($chl['challan_no']).'</a>';
                } else {
                    echo '<span class="text-muted">-</span>';
                }
            } else {
                echo '<span class="text-muted">-</span>';
            }
        ?></td></tr>
        <tr><td class="text-muted">GST Type</td><td><?= htmlspecialchars($inv['gst_type']) ?></td></tr>
        <tr><td class="text-muted">Payment Terms</td><td><?= htmlspecialchars($inv['payment_terms'] ?? '-') ?></td></tr>
        <tr><td class="text-muted">Due Date</td><td><?= $inv['due_date'] ? date('d/m/Y', strtotime($inv['due_date'])) : '-' ?></td></tr>
    </table>
</div></div></div>

<!-- Consignee -->
<div class="col-12 col-md-6">
<div class="card h-100"><div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Consignee</div>
<div class="card-body">
    <table class="table table-sm mb-0">
        <tr><td class="text-muted" style="width:45%">Name</td><td><strong><?= htmlspecialchars($inv['consignee_name']) ?></strong></td></tr>
        <tr><td class="text-muted">Address</td><td><?= htmlspecialchars($inv['consignee_address'] ?? '-') ?></td></tr>
        <tr><td class="text-muted">City / State</td><td><?= htmlspecialchars(($inv['consignee_city'] ?? '') . ' ' . ($inv['consignee_state'] ?? '')) ?></td></tr>
        <tr><td class="text-muted">GSTIN</td><td><code><?= htmlspecialchars($inv['consignee_gstin'] ?? '-') ?></code></td></tr>
    </table>
</div></div></div>

<!-- MRN & Registration — Update Panel -->
<div class="col-12">
<div class="card border-warning">
<div class="card-header bg-warning text-dark"><i class="bi bi-pencil-square me-2"></i>Link Challan, MRN &amp; Registration — Quick Update</div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="update_fields" value="1">
<input type="hidden" name="invoice_id" value="<?= $id ?>">
<div class="row g-3 align-items-end">
    <div class="col-12 col-md-3">
        <label class="form-label fw-bold"><i class="bi bi-link-45deg me-1"></i>Linked Challan</label>
        <select name="challan_id" class="form-select">
            <option value="">-- None / Select Challan --</option>
            <?php foreach ($challans as $ch): ?>
            <option value="<?= $ch['id'] ?>" <?= (($inv['challan_id'] ?? 0) == $ch['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($ch['challan_no'] . ' — ' . $ch['vendor_name'] . ' (' . date('d/m/Y', strtotime($ch['despatch_date'])) . ')') ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">MRN Number <small class="text-muted fw-normal">(consignee)</small></label>
        <input type="text" name="mrn_number" class="form-control" value="<?= htmlspecialchars($inv['mrn_number'] ?? '') ?>" placeholder="Enter MRN">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">MRN Date</label>
        <input type="date" name="mrn_date" class="form-control" value="<?= $inv['mrn_date'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Reg Number</label>
        <input type="text" name="invoice_reg_number" class="form-control" value="<?= htmlspecialchars($inv['invoice_reg_number'] ?? '') ?>" placeholder="Enter Reg No">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Reg Date</label>
        <input type="date" name="invoice_reg_date" class="form-control" value="<?= $inv['invoice_reg_date'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <?php foreach (['Draft'=>'Not Billed','Sent'=>'Billed','MRN Received'=>'MRN Received','Registered'=>'Registered','Paid'=>'Paid'] as $st => $lbl2): ?>
            <option value="<?= $st ?>" <?= $inv['status']===$st?'selected':'' ?>><?= $lbl2 ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-1">
        <button type="submit" class="btn btn-warning w-100"><i class="bi bi-check2"></i> Save</button>
    </div>
</div>
</form>
</div></div></div>

<!-- Items -->
<div class="col-12">
<div class="card"><div class="card-header"><i class="bi bi-list-ul me-2"></i>Invoice Items</div>
<div class="card-body p-0">
<table class="table table-sm mb-0">
<thead><tr>
    <th>#</th><th>Item</th><th>HSN</th><th>UOM</th><th>Qty</th><th>Rate</th><th>Amount</th>
    <?php if ($is_split): ?>
    <th>CGST%</th><th>CGST Amt</th><th>SGST%</th><th>SGST Amt</th>
    <?php else: ?>
    <th>IGST%</th><th>IGST Amt</th>
    <?php endif; ?>
    <th>Total</th>
</tr></thead>
<tbody>
<?php $ri=1; foreach ($items as $it): ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><?= htmlspecialchars($it['item_name']) ?></td>
    <td><?= htmlspecialchars($it['hsn_code']) ?></td>
    <td><?= htmlspecialchars($it['uom']) ?></td>
    <td><?= number_format($it['qty'], uomDecimals($it['uom'])) ?></td>
    <td>₹<?= number_format($it['unit_price'], 2) ?></td>
    <td>₹<?= number_format($it['amount'], 2) ?></td>
    <?php if ($is_split): ?>
    <td><?= $it['cgst_rate'] ?>%</td><td>₹<?= number_format($it['cgst_amount'], 2) ?></td>
    <td><?= $it['sgst_rate'] ?>%</td><td>₹<?= number_format($it['sgst_amount'], 2) ?></td>
    <?php else: ?>
    <td><?= $it['igst_rate'] ?>%</td><td>₹<?= number_format($it['igst_amount'], 2) ?></td>
    <?php endif; ?>
    <td><strong>₹<?= number_format($it['total_amount'], 2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light">
    <tr>
        <td colspan="<?= $is_split ? 6 : 6 ?>" class="text-end fw-bold">Subtotal</td>
        <td>₹<?= number_format($inv['subtotal'], 2) ?></td>
        <?php if ($is_split): ?>
        <td></td><td>₹<?= number_format($inv['cgst_amount'], 2) ?></td>
        <td></td><td>₹<?= number_format($inv['sgst_amount'], 2) ?></td>
        <?php else: ?>
        <td></td><td>₹<?= number_format($inv['igst_amount'], 2) ?></td>
        <?php endif; ?>
        <td><strong>₹<?= number_format($inv['total_amount'], 2) ?></strong></td>
    </tr>
</tfoot>
</table>
</div></div></div>

<!-- Payments -->
<div class="col-12 col-md-7">
<div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-cash-coin me-2"></i>Payments Received</span>
    <div>
        <span class="badge bg-success me-2">Paid: ₹<?= number_format($paid_total, 2) ?></span>
        <span class="badge bg-<?= $outstanding > 0 ? 'danger' : 'success' ?>">Outstanding: ₹<?= number_format($outstanding, 2) ?></span>
    </div>
</div>
<div class="card-body p-0">
<?php if ($payments): ?>
<table class="table table-sm mb-0">
<thead><tr><th>Date</th><th>Amount</th><th>Mode</th><th>Reference</th><th></th></tr></thead>
<tbody>
<?php foreach ($payments as $p): ?>
<tr>
    <td><?= date('d/m/Y', strtotime($p['payment_date'])) ?></td>
    <td class="text-success fw-bold">₹<?= number_format($p['amount'], 2) ?></td>
    <td><?= htmlspecialchars($p['payment_mode']) ?></td>
    <td><?= htmlspecialchars($p['reference_no'] ?? '-') ?></td>
    <td><a href="?del_payment=<?= $p['id'] ?>&id=<?= $id ?>" onclick="return confirm('Delete this payment?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p class="text-muted p-3 mb-0">No payments recorded yet.</p>
<?php endif; ?>
</div></div></div>

<!-- Add Payment -->
<div class="col-12 col-md-5">
<div class="card border-success"><div class="card-header bg-success text-white"><i class="bi bi-plus-circle me-2"></i>Record Payment</div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="add_payment" value="1">
<input type="hidden" name="invoice_id" value="<?= $id ?>">
<div class="row g-2">
    <div class="col-6">
        <label class="form-label">Date</label>
        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-6">
        <label class="form-label">Amount (₹)</label>
        <input type="number" name="payment_amount" class="form-control" step="0.01" placeholder="0.00" required>
    </div>
    <div class="col-6">
        <label class="form-label">Mode</label>
        <select name="payment_mode" class="form-select">
            <option>NEFT</option><option>RTGS</option><option>IMPS</option>
            <option>Cheque</option><option>Cash</option><option>UPI</option>
        </select>
    </div>
    <div class="col-6">
        <label class="form-label">Reference No</label>
        <input type="text" name="reference_no" class="form-control" placeholder="UTR / Cheque No">
    </div>
    <div class="col-12">
        <label class="form-label">Remarks</label>
        <input type="text" name="payment_remarks" class="form-control" placeholder="Optional">
    </div>
    <div class="col-12">
        <button type="submit" class="btn btn-success w-100"><i class="bi bi-check2 me-1"></i>Record Payment</button>
    </div>
</div>
</form>
</div></div></div>

</div><!-- end row -->

<?php endif; // end view ?>

<?php
// ════════════════════════════════════════════════════════════
// ADD / EDIT FORM
// ════════════════════════════════════════════════════════════
if ($action === 'add' || ($action === 'edit' && $id > 0)):
$inv = [];
if ($id > 0) {
    $inv   = $db->query("SELECT * FROM sales_invoices WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
    $inv_items = $db->query("SELECT * FROM sales_invoice_items WHERE invoice_id=$id")->fetch_all(MYSQLI_ASSOC);
} else {
    $inv_items = [];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'New' ?> Sales Invoice</h5>
    <a href="sales_invoices.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST" id="invForm">
<input type="hidden" name="save_invoice" value="1">
<div class="row g-3">

<!-- Invoice Info -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Invoice Information</div>
<div class="card-body"><div class="row g-3">

    <?php if (count($all_companies) > 1): ?>
    <div class="col-12 col-md-3">
        <label class="form-label">Company *</label>
        <select name="company_id" class="form-select" required>
            <?php foreach ($all_companies as $co): ?>
            <option value="<?= $co['id'] ?>" <?= ($inv['company_id'] ?? activeCompanyId())==$co['id']?'selected':'' ?>>
                <?= htmlspecialchars($co['company_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" name="company_id" value="<?= activeCompanyId() ?>">
    <?php endif; ?>

    <div class="col-6 col-md-3">
        <label class="form-label">Invoice Number *</label>
        <input type="text" name="invoice_number" class="form-control" required
               value="<?= htmlspecialchars($inv['invoice_number'] ?? '') ?>" placeholder="e.g. INV/2025/001">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Invoice Date *</label>
        <input type="date" name="invoice_date" class="form-control" required value="<?= $inv['invoice_date'] ?? date('Y-m-d') ?>">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Link to Challan <small class="text-muted">(auto-fills consignee &amp; items)</small></label>
        <select name="challan_id" id="challanSelect" class="form-select" style="width:100%">
            <option value="">-- Search by Challan No / Vendor / Date --</option>
            <?php foreach ($challans as $ch):
                $label = $ch['challan_no'] . ' — ' . $ch['vendor_name'] . ' (' . date('d/m/Y', strtotime($ch['despatch_date'])) . ')';
            ?>
            <option value="<?= $ch['id'] ?>"
                data-name="<?= htmlspecialchars($ch['consignee_name']) ?>"
                data-addr="<?= htmlspecialchars($ch['consignee_address'] ?? '') ?>"
                data-city="<?= htmlspecialchars($ch['consignee_city'] ?? '') ?>"
                data-state="<?= htmlspecialchars($ch['consignee_state'] ?? '') ?>"
                data-gstin="<?= htmlspecialchars($ch['consignee_gstin'] ?? '') ?>"
                data-company="<?= $ch['company_id'] ?>"
                <?= ($inv['challan_id'] ?? 0) == $ch['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">GST Type *</label>
        <select name="gst_type" id="gstType" class="form-select" onchange="updateGSTCols()" required>
            <option value="IGST"      <?= ($inv['gst_type']??'IGST')==='IGST'?'selected':'' ?>>IGST</option>
            <option value="CGST+SGST" <?= ($inv['gst_type']??'')==='CGST+SGST'?'selected':'' ?>>CGST + SGST</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Payment Terms</label>
        <input type="text" name="payment_terms" id="payment_terms" class="form-control" value="<?= htmlspecialchars($inv['payment_terms'] ?? '') ?>" placeholder="e.g. Net 30 days">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Due Date</label>
        <input type="date" name="due_date" id="due_date" class="form-control" value="<?= $inv['due_date'] ?? '' ?>">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Remarks</label>
        <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($inv['remarks'] ?? '') ?>">
    </div>
</div></div></div></div>

<!-- Consignee -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Consignee Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label">Consignee Name *</label>
        <input type="text" name="consignee_name" id="con_name" class="form-control" required value="<?= htmlspecialchars($inv['consignee_name'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Address</label>
        <input type="text" name="consignee_address" id="con_addr" class="form-control" value="<?= htmlspecialchars($inv['consignee_address'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="consignee_city" id="con_city" class="form-control" value="<?= htmlspecialchars($inv['consignee_city'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">State</label>
        <input type="text" name="consignee_state" id="con_state" class="form-control" value="<?= htmlspecialchars($inv['consignee_state'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">GSTIN</label>
        <input type="text" name="consignee_gstin" id="con_gstin" class="form-control" value="<?= htmlspecialchars($inv['consignee_gstin'] ?? '') ?>">
    </div>
</div></div></div></div>

<!-- Items -->
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2"></i>Invoice Items</span>
    <button type="button" class="btn btn-sm btn-outline-light" onclick="addRow()"><i class="bi bi-plus me-1"></i>Add Row</button>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0" id="invItemsTable" style="min-width:900px">
<thead><tr>
    <th style="width:30%">Item Name</th>
    <th style="width:8%">HSN</th>
    <th style="width:7%">UOM</th>
    <th style="width:8%">Qty</th>
    <th style="width:9%">Rate (₹)</th>
    <th style="width:9%">Amount</th>
    <th style="width:7%" class="igst-col">IGST%</th>
    <th style="width:9%" class="igst-col">IGST Amt</th>
    <th style="width:6%" class="split-col" style="display:none">CGST%</th>
    <th style="width:8%" class="split-col" style="display:none">CGST Amt</th>
    <th style="width:6%" class="split-col" style="display:none">SGST%</th>
    <th style="width:8%" class="split-col" style="display:none">SGST Amt</th>
    <th style="width:7%">Total</th>
    <th style="width:4%"></th>
</tr></thead>
<tbody id="invItemsBody">
<?php
$existing_rows = $inv_items ?: [[]];
foreach ($existing_rows as $ri => $it):
?>
<tr class="item-row">
    <td><input type="text" name="item_name[]" class="form-control form-control-sm" value="<?= htmlspecialchars($it['item_name'] ?? '') ?>"></td>
    <td><input type="text" name="hsn_code[]" class="form-control form-control-sm" value="<?= htmlspecialchars($it['hsn_code'] ?? '') ?>"></td>
    <td>
        <select name="item_uom[]" class="form-select form-select-sm">
            <?php foreach (['MT','Kg','Gm','Litre','Mtr','Cm','Nos','Set','Box','Carton','Pair','Dozen','Bundle','Bag'] as $u): ?>
            <option <?= ($it['uom'] ?? '') === $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
        </select>
    </td>
    <td><input type="number" name="qty[]" class="form-control form-control-sm row-qty" step="0.001" value="<?= $it['qty'] ?? '' ?>" onchange="calcRow(this)"></td>
    <td><input type="number" name="unit_price[]" class="form-control form-control-sm row-price" step="0.01" value="<?= $it['unit_price'] ?? '' ?>" onchange="calcRow(this)"></td>
    <td><input type="text" name="row_amount[]" class="form-control form-control-sm row-amount bg-light" readonly value="<?= isset($it['amount']) ? number_format($it['amount'],2,'.','') : '' ?>"></td>
    <td class="igst-col"><input type="number" name="gst_rate[]" class="form-control form-control-sm row-gst" step="0.01" value="<?= $it['gst_rate'] ?? $it['igst_rate'] ?? '' ?>" onchange="calcRow(this)"></td>
    <td class="igst-col"><input type="text" class="form-control form-control-sm row-igst-amt bg-light" readonly value="<?= isset($it['igst_amount']) ? number_format($it['igst_amount'],2,'.','') : '' ?>"></td>
    <td class="split-col"><input type="text" class="form-control form-control-sm row-cgst-rate bg-light" readonly value="<?= isset($it['cgst_rate']) ? $it['cgst_rate'] : '' ?>"></td>
    <td class="split-col"><input type="text" class="form-control form-control-sm row-cgst-amt bg-light" readonly value="<?= isset($it['cgst_amount']) ? number_format($it['cgst_amount'],2,'.','') : '' ?>"></td>
    <td class="split-col"><input type="text" class="form-control form-control-sm row-sgst-rate bg-light" readonly value="<?= isset($it['sgst_rate']) ? $it['sgst_rate'] : '' ?>"></td>
    <td class="split-col"><input type="text" class="form-control form-control-sm row-sgst-amt bg-light" readonly value="<?= isset($it['sgst_amount']) ? number_format($it['sgst_amount'],2,'.','') : '' ?>"></td>
    <td><input type="text" class="form-control form-control-sm row-total bg-light fw-bold" readonly value="<?= isset($it['total_amount']) ? number_format($it['total_amount'],2,'.','') : '' ?>"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light">
<tr>
    <td colspan="5" class="text-end fw-bold">Totals</td>
    <td><strong id="footSubtotal">0.00</strong></td>
    <td class="igst-col"></td>
    <td class="igst-col"><strong id="footIGST">0.00</strong></td>
    <td class="split-col"></td>
    <td class="split-col"><strong id="footCGST">0.00</strong></td>
    <td class="split-col"></td>
    <td class="split-col"><strong id="footSGST">0.00</strong></td>
    <td><strong id="footTotal">0.00</strong></td>
    <td></td>
</tr>
</tfoot>
</table>
</div></div></div></div>

<div class="col-12 text-end">
    <a href="sales_invoices.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Invoice</button>
</div>
</div>
</form>

<style>
.split-col { display: none; }
</style>
<script>
// Challan fill
// challanSelect change handler (fallback if Select2 not loaded)
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('challanSelect');
    if (sel && typeof jQuery === 'undefined') {
        sel.addEventListener('change', function() { fillFromChallan(this.value); });
    }
});
const challanData = <?php
    $cd = [];
    foreach ($challans as $ch) {
        // consignee_name kept as-is from despatch_orders (bill-to details)
        $cd[$ch['id']] = $ch;
    }
    echo json_encode($cd);
?>;

function fillFromChallan(cid) {
    if (!cid) return;
    const ch = challanData[cid];
    if (!ch) return;
    // Consignee details
    document.getElementById('con_name').value  = ch.consignee_name || ch.vendor_name || '';
    document.getElementById('con_addr').value  = ch.consignee_address || '';
    document.getElementById('con_city').value  = ch.consignee_city  || '';
    document.getElementById('con_state').value = ch.consignee_state || '';
    document.getElementById('con_gstin').value = ch.consignee_gstin || '';
    // Payment terms from PO
    var ptEl = document.getElementById('payment_terms');
    if (ptEl && ch.payment_terms) ptEl.value = ch.payment_terms;
    // Auto-calculate due date if payment terms has a number of days
    if (ch.payment_terms && ch.despatch_date) {
        var match = ch.payment_terms.match(/(\d+)/);
        if (match) {
            var days = parseInt(match[1]);
            var base = new Date(ch.despatch_date);
            base.setDate(base.getDate() + days);
            var ddEl = document.getElementById('due_date');
            if (ddEl) ddEl.value = base.toISOString().split('T')[0];
        }
    }
    // Fetch challan items via AJAX
    fetch('get_challan_items.php?id=' + cid)
        .then(r => r.json())
        .then(items => {
            const tbody = document.getElementById('invItemsBody');
            tbody.innerHTML = '';
            items.forEach(it => addRow(it));
            updateGSTCols();
            recalcFooter();
        }).catch(() => {});
}

function addRow(data) {
    data = data || {};
    const tbody = document.getElementById('invItemsBody');
    const isSplit = document.getElementById('gstType').value === 'CGST+SGST';
    const uoms = ['MT','Kg','Gm','Litre','Mtr','Cm','Nos','Set','Box','Carton','Pair','Dozen','Bundle','Bag'];
    const uomOpts = uoms.map(u => `<option${u===(data.uom||'MT')?' selected':''}>${u}</option>`).join('');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td><input type="text" name="item_name[]" class="form-control form-control-sm" value="${data.item_name||''}"></td>
        <td><input type="text" name="hsn_code[]" class="form-control form-control-sm" value="${data.hsn_code||''}"></td>
        <td><select name="item_uom[]" class="form-select form-select-sm">${uomOpts}</select></td>
        <td><input type="number" name="qty[]" class="form-control form-control-sm row-qty" step="0.001" value="${data.qty||''}" onchange="calcRow(this)"></td>
        <td><input type="number" name="unit_price[]" class="form-control form-control-sm row-price" step="0.01" value="${data.unit_price||''}" onchange="calcRow(this)"></td>
        <td><input type="text" name="row_amount[]" class="form-control form-control-sm row-amount bg-light" readonly value="${data.amount||''}"></td>
        <td class="igst-col"><input type="number" name="gst_rate[]" class="form-control form-control-sm row-gst" step="0.01" value="${data.gst_rate||''}" onchange="calcRow(this)"></td>
        <td class="igst-col"><input type="text" class="form-control form-control-sm row-igst-amt bg-light" readonly></td>
        <td class="split-col"><input type="text" class="form-control form-control-sm row-cgst-rate bg-light" readonly></td>
        <td class="split-col"><input type="text" class="form-control form-control-sm row-cgst-amt bg-light" readonly></td>
        <td class="split-col"><input type="text" class="form-control form-control-sm row-sgst-rate bg-light" readonly></td>
        <td class="split-col"><input type="text" class="form-control form-control-sm row-sgst-amt bg-light" readonly></td>
        <td><input type="text" class="form-control form-control-sm row-total bg-light fw-bold" readonly></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>`;
    tbody.appendChild(tr);
    updateGSTCols();
    if (data.qty && data.unit_price) calcRow(tr.querySelector('.row-qty'));
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) { btn.closest('tr').remove(); recalcFooter(); }
}

function calcRow(el) {
    const tr = el.closest('tr');
    const qty   = parseFloat(tr.querySelector('.row-qty').value)   || 0;
    const price = parseFloat(tr.querySelector('.row-price').value) || 0;
    const grate = parseFloat(tr.querySelector('.row-gst').value)   || 0;
    const amt   = +(qty * price).toFixed(2);
    const isSplit = document.getElementById('gstType').value === 'CGST+SGST';
    tr.querySelector('.row-amount').value = amt.toFixed(2);
    if (isSplit) {
        const half = +(grate / 2).toFixed(2);
        const hamt = +(amt * half / 100).toFixed(2);
        tr.querySelector('.row-cgst-rate').value = half;
        tr.querySelector('.row-cgst-amt').value  = hamt.toFixed(2);
        tr.querySelector('.row-sgst-rate').value = half;
        tr.querySelector('.row-sgst-amt').value  = hamt.toFixed(2);
        tr.querySelector('.row-igst-amt').value  = '';
        tr.querySelector('.row-total').value = (amt + hamt*2).toFixed(2);
    } else {
        const gamt = +(amt * grate / 100).toFixed(2);
        tr.querySelector('.row-igst-amt').value  = gamt.toFixed(2);
        tr.querySelector('.row-cgst-rate').value = '';
        tr.querySelector('.row-cgst-amt').value  = '';
        tr.querySelector('.row-sgst-rate').value = '';
        tr.querySelector('.row-sgst-amt').value  = '';
        tr.querySelector('.row-total').value = (amt + gamt).toFixed(2);
    }
    recalcFooter();
}

function recalcFooter() {
    let sub=0, igst=0, cgst=0, sgst=0, tot=0;
    document.querySelectorAll('.item-row').forEach(tr => {
        sub  += parseFloat(tr.querySelector('.row-amount').value)   || 0;
        igst += parseFloat(tr.querySelector('.row-igst-amt').value) || 0;
        cgst += parseFloat(tr.querySelector('.row-cgst-amt').value) || 0;
        sgst += parseFloat(tr.querySelector('.row-sgst-amt').value) || 0;
        tot  += parseFloat(tr.querySelector('.row-total').value)    || 0;
    });
    document.getElementById('footSubtotal').textContent = sub.toFixed(2);
    document.getElementById('footIGST').textContent = igst.toFixed(2);
    document.getElementById('footCGST').textContent = cgst.toFixed(2);
    document.getElementById('footSGST').textContent = sgst.toFixed(2);
    document.getElementById('footTotal').textContent = tot.toFixed(2);
}

function updateGSTCols() {
    const isSplit = document.getElementById('gstType').value === 'CGST+SGST';
    document.querySelectorAll('.igst-col').forEach(el => el.style.display = isSplit ? 'none' : '');
    document.querySelectorAll('.split-col').forEach(el => el.style.display = isSplit ? '' : 'none');
    document.querySelectorAll('.item-row').forEach(tr => calcRow(tr.querySelector('.row-qty')));
}

// Init
updateGSTCols();
recalcFooter();
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script>
jQuery(document).ready(function() {
    if (document.getElementById('challanSelect')) {
        jQuery('#challanSelect').select2({
            placeholder: 'Search Challan No / Vendor / Date…',
            allowClear: true,
            width: '100%',
            theme: 'bootstrap-5'
        }).on('change', function() {
            fillFromChallan(this.value);
        });
    }
});
</script>
