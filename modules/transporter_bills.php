<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('transporter_bills', 'view');
$__uid = (int)($_SESSION['user_id'] ?? 0);
$tb_can_view_all = canViewAll('transporter_bills');
$tb_filter = $tb_can_view_all ? '' : " AND (d.created_by=$__uid OR d.agent_id=$__uid)";

function transporterBillFileUrl(string $value): string {
    if ($value === '') return '';
    if (preg_match('#^https?://#i', $value)) return $value;
    if (strpos($value, 'uploads/') === 0) return '../' . ltrim($value, '/');
    if (strpos($value, '/') === false) return '../uploads/freight_invoices/' . rawurlencode($value);
    return function_exists('r2_url') ? r2_url($value) : '';
}

/* ── AJAX: mark hard copy received ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_mark_received'])) {
    header('Content-Type: application/json');
    $did = (int)($_POST['despatch_id'] ?? 0);
    if ($did) {
        $db->query("UPDATE despatch_orders d SET freight_inv_hardcopy=1 WHERE d.id=$did" . $tb_filter);
        echo json_encode(['ok' => $db->affected_rows > 0]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

/* ── Fetch ALL delivered challans with freight + payment status ── */
$rows = $db->query("
    SELECT d.id, d.challan_no, d.despatch_date, d.consignee_name, d.consignee_city,
           d.freight_amount,
           d.freight_inv_no, d.freight_inv_date, d.freight_inv_amount,
           d.freight_inv_type, d.freight_inv_file, d.freight_inv_hardcopy,
           t.transporter_name,
           v.vendor_name,
           DATEDIFF(CURDATE(), d.despatch_date) AS days_since_despatch,
           COALESCE(SUM(CASE WHEN tp.status != 'Cancelled' THEN tp.base_amount ELSE 0 END), 0) AS paid_amount,
           COALESCE(SUM(CASE WHEN tp.status != 'Cancelled' THEN tp.net_payable ELSE 0 END), 0) AS net_paid
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN transporter_payments tp ON tp.despatch_id = d.id
    WHERE d.status = 'Delivered'
      AND d.freight_amount > 0
      $tb_filter
    GROUP BY d.id
    ORDER BY t.transporter_name, d.despatch_date DESC
")->fetch_all(MYSQLI_ASSOC);

/* ── Categorise each row ── */
// Bill status:
// 0 = Invoice not received
// 1 = Invoice received (Scan - hard copy pending)
// 2 = Invoice fully cleared (Digital OR Scan + hard copy received)
foreach ($rows as &$r) {
    if (empty($r['freight_inv_type'])) {
        $r['bill_status'] = 0; // Not received
    } elseif ($r['freight_inv_type'] === 'Digital' || $r['freight_inv_hardcopy']) {
        $r['bill_status'] = 2; // Cleared
    } else {
        $r['bill_status'] = 1; // Scan - hard copy pending
    }
    // Payment status
    $r['payment_balance'] = max(0, (float)$r['freight_amount'] - (float)$r['paid_amount']);
}
unset($r);

$not_received = array_filter($rows, fn($r) => $r['bill_status'] == 0);
$hardcopy_pending = array_filter($rows, fn($r) => $r['bill_status'] == 1);
$cleared = array_filter($rows, fn($r) => $r['bill_status'] == 2);

// Group not_received and hardcopy_pending by transporter
$grp_not_received = [];
foreach ($not_received as $r) {
    $key = $r['transporter_name'] ?: '—';
    $grp_not_received[$key][] = $r;
}
$grp_hardcopy = [];
foreach ($hardcopy_pending as $r) {
    $key = $r['transporter_name'] ?: '—';
    $grp_hardcopy[$key][] = $r;
}

/* ── Totals ── */
$total_not_received = array_sum(array_column(iterator_to_array((function() use ($not_received) { yield from $not_received; })()), 'freight_amount'));
$total_hardcopy     = array_sum(array_column(iterator_to_array((function() use ($hardcopy_pending) { yield from $hardcopy_pending; })()), 'freight_inv_amount'));
$total_cleared      = array_sum(array_column(iterator_to_array((function() use ($cleared) { yield from $cleared; })()), 'freight_inv_amount'));

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-receipt-cutoff me-2"></i>Transporter Pending Bills';</script>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-danger h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-danger"><?= count($not_received) ?></div>
                <div class="small text-muted">Invoice Not Received</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-warning h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-warning"><?= count($hardcopy_pending) ?></div>
                <div class="small text-muted">Hard Copy Pending</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-success h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-success"><?= count($cleared) ?></div>
                <div class="small text-muted">Invoices Cleared</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-primary h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-primary"><?= count($rows) ?></div>
                <div class="small text-muted">Total Delivered</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SECTION 1: Invoice Not Received ═══ -->
<div class="card mb-4 border-danger">
    <div class="card-header fw-bold text-white" style="background:#c0392b">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>Invoice Not Yet Received
        <span class="badge bg-light text-danger ms-2"><?= count($not_received) ?></span>
    </div>
    <div class="card-body p-0">
    <?php if (empty($grp_not_received)): ?>
        <div class="p-3 text-center text-muted">All invoices received.</div>
    <?php else: foreach ($grp_not_received as $transporter => $orders):
        $grp_id = 'nr_'.md5($transporter);
        $grp_freight = array_sum(array_column($orders, 'freight_amount'));
    ?>
    <div class="border-bottom">
        <div class="d-flex justify-content-between align-items-center p-3 bg-light"
             style="cursor:pointer" onclick="toggleGrp('<?= $grp_id ?>', this)">
            <div>
                <i class="bi bi-chevron-down me-2"></i>
                <strong><?= htmlspecialchars($transporter) ?></strong>
                <span class="badge bg-danger ms-2"><?= count($orders) ?></span>
            </div>
            <span class="fw-bold text-danger">₹<?= number_format($grp_freight,2) ?></span>
        </div>
        <div id="<?= $grp_id ?>">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>Challan No cum LR No</th><th>Date</th><th>Vendor</th><th class="text-end">Freight ₹</th><th class="text-end">Paid ₹</th><th class="text-end">Balance ₹</th><th>Days</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o):
                $days = (int)$o['days_since_despatch'];
                $bal  = (float)$o['payment_balance'];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($o['challan_no']) ?></strong></td>
                <td><?= date('d/m/Y', strtotime($o['despatch_date'])) ?></td>
                <td><small><?= htmlspecialchars($o['vendor_name'] ?: $o['consignee_name']) ?></small></td>
                <td class="text-end">₹<?= number_format($o['freight_amount'],2) ?></td>
                <td class="text-end text-success">₹<?= number_format($o['paid_amount'],2) ?></td>
                <td class="text-end fw-bold <?= $bal>0?'text-danger':'' ?>">₹<?= number_format($bal,2) ?></td>
                <td><span class="badge bg-<?= $days>30?'danger':($days>15?'warning text-dark':'secondary') ?>"><?= $days ?>d</span></td>
                <td>
                    <a href="despatch.php?action=edit&id=<?= $o['id'] ?>" class="btn btn-sm btn-warning">
                        <i class="bi bi-upload me-1"></i>Enter Invoice
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<!-- ═══ SECTION 2: Hard Copy Pending ═══ -->
<div class="card mb-4 border-warning">
    <div class="card-header fw-bold" style="background:#fffbeb">
        <i class="bi bi-file-earmark-image me-2 text-warning"></i>Scan Received — Hard Copy Pending
        <span class="badge bg-warning text-dark ms-2"><?= count($hardcopy_pending) ?></span>
    </div>
    <div class="card-body p-0">
    <?php if (empty($grp_hardcopy)): ?>
        <div class="p-3 text-center text-muted">No hard copies pending.</div>
    <?php else: foreach ($grp_hardcopy as $transporter => $orders):
        $grp_id = 'hc_'.md5($transporter);
        $grp_inv = array_sum(array_column($orders, 'freight_inv_amount'));
    ?>
    <div class="border-bottom">
        <div class="d-flex justify-content-between align-items-center p-3 bg-light"
             style="cursor:pointer" onclick="toggleGrp('<?= $grp_id ?>', this)">
            <div>
                <i class="bi bi-chevron-down me-2"></i>
                <strong><?= htmlspecialchars($transporter) ?></strong>
                <span class="badge bg-warning text-dark ms-2"><?= count($orders) ?></span>
            </div>
            <span class="fw-bold text-warning">₹<?= number_format($grp_inv,2) ?></span>
        </div>
        <div id="<?= $grp_id ?>">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>Challan No cum LR No</th><th>Date</th><th>Vendor</th><th>Inv No</th><th>Inv Date</th><th class="text-end">Inv Amount ₹</th><th>Days</th><th>File</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o):
                $days = (int)$o['days_since_despatch'];
            ?>
            <tr id="row_<?= $o['id'] ?>">
                <td><strong><?= htmlspecialchars($o['challan_no']) ?></strong></td>
                <td><?= date('d/m/Y', strtotime($o['despatch_date'])) ?></td>
                <td><small><?= htmlspecialchars($o['vendor_name'] ?: $o['consignee_name']) ?></small></td>
                <td><?= htmlspecialchars($o['freight_inv_no'] ?: '—') ?></td>
                <td><?= $o['freight_inv_date'] ? date('d/m/Y', strtotime($o['freight_inv_date'])) : '—' ?></td>
                <td class="text-end fw-semibold">₹<?= number_format($o['freight_inv_amount'],2) ?></td>
                <td><span class="badge bg-<?= $days>30?'danger':($days>15?'warning text-dark':'secondary') ?>"><?= $days ?>d</span></td>
                <td>
                    <?php if (!empty($o['freight_inv_file'])): ?>
                    <a href="<?= htmlspecialchars(transporterBillFileUrl($o['freight_inv_file'])) ?>"
                       target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="markReceived(<?= $o['id'] ?>, this)">
                        <i class="bi bi-check2-circle me-1"></i>Received
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<!-- ═══ SECTION 3: Cleared Invoices ═══ -->
<div class="card border-success">
    <div class="card-header fw-bold d-flex justify-content-between align-items-center" style="background:#eafaf1">
        <span><i class="bi bi-check-circle-fill me-2 text-success"></i>Cleared Invoices
        <span class="badge bg-success ms-2"><?= count($cleared) ?></span></span>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleCleared()">
            <span id="clearBtn">Show</span>
        </button>
    </div>
    <div id="clearedSection" style="display:none">
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead class="table-light">
            <tr><th>Challan No cum LR No</th><th>Date</th><th>Transporter</th><th>Vendor</th><th>Inv No</th><th class="text-end">Inv Amount ₹</th><th>Type</th><th>File</th></tr>
        </thead>
        <tbody>
        <?php foreach ($cleared as $o): ?>
        <tr>
            <td><strong><?= htmlspecialchars($o['challan_no']) ?></strong></td>
            <td><?= date('d/m/Y', strtotime($o['despatch_date'])) ?></td>
            <td><?= htmlspecialchars($o['transporter_name'] ?? '—') ?></td>
            <td><small><?= htmlspecialchars($o['vendor_name'] ?: $o['consignee_name']) ?></small></td>
            <td><?= htmlspecialchars($o['freight_inv_no'] ?: '—') ?></td>
            <td class="text-end">₹<?= number_format($o['freight_inv_amount'],2) ?></td>
            <td>
                <span class="badge bg-success">
                    <?= $o['freight_inv_type'] === 'Digital' ? '<i class="bi bi-patch-check me-1"></i>Digital' : '<i class="bi bi-check-circle me-1"></i>Hard Copy ✓' ?>
                </span>
            </td>
            <td>
                <?php if (!empty($o['freight_inv_file'])): ?>
                <a href="<?= htmlspecialchars(transporterBillFileUrl($o['freight_inv_file'])) ?>"
                   target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    </div>
</div>

<script>
function toggleGrp(id, hdr) {
    var body = document.getElementById(id);
    var icon = hdr.querySelector('.bi-chevron-down, .bi-chevron-up');
    if (!body) return;
    var hidden = body.style.display === 'none';
    body.style.display = hidden ? '' : 'none';
    if (icon) icon.className = icon.className.replace(hidden ? 'bi-chevron-down' : 'bi-chevron-up', hidden ? 'bi-chevron-up' : 'bi-chevron-down');
}
function toggleCleared() {
    var sec = document.getElementById('clearedSection');
    var btn = document.getElementById('clearBtn');
    var hidden = sec.style.display === 'none';
    sec.style.display = hidden ? 'block' : 'none';
    btn.textContent = hidden ? 'Hide' : 'Show';
}
function markReceived(id, btn) {
    if (!confirm('Mark hard copy invoice as received?')) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    fetch('transporter_bills.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_mark_received=1&despatch_id=' + id
    })
    .then(r => r.json())
    .then(function(data) {
        if (data.ok) {
            var row = document.getElementById('row_' + id);
            if (row) { row.style.opacity = '0'; setTimeout(function(){ location.reload(); }, 400); }
        }
    });
}
</script>
<?php include '../includes/footer.php'; ?>
