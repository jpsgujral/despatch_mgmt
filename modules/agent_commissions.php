<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('agent_commissions', 'view');
$db = getDB();
// ── Agent visibility filter ─────────────────────────────────────────
$__uid     = (int)($_SESSION['user_id'] ?? 0);
$ac_can_view_all = canViewAll('agent_commissions');
$is_agent  = !isAdmin() && !$ac_can_view_all;
$agent_uid = $__uid;

// Fetch current user's name and signature for Payment Advice
$__user_row = $db->query("SELECT full_name, signature_path FROM app_users WHERE id=$__uid LIMIT 1")->fetch_assoc();
$__user_name = htmlspecialchars($__user_row['full_name'] ?? 'Authorised Signatory', ENT_QUOTES);
$__sig_path  = $__user_row['signature_path'] ?? '';
$__r2_pub    = defined('R2_PUBLIC_URL') ? R2_PUBLIC_URL : 'https://pub-5721570094064d529f1527519424c77b.r2.dev/dms_uploads';
$__sig_url   = $__sig_path ? ($__r2_pub . '/' . ltrim($__sig_path, '/')) : '';
// ────────────────────────────────────────────────────────────────────
// Ensure notes column exists in agent_commissions
if (!function_exists('safeAddColumn')) {
    function safeAddColumn($db, $table, $col, $def) {
        if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->num_rows)
            $db->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    }
}
safeAddColumn($db, 'agent_commissions', 'notes', "TEXT DEFAULT NULL");
safeAddColumn($db, 'agent_commission_payments', 'created_by',  "INT DEFAULT 0");
safeAddColumn($db, 'agent_commission_payments', 'created_at',  "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
safeAddColumn($db, 'agent_commission_payments', 'utr_no',      "VARCHAR(100) DEFAULT NULL");
safeAddColumn($db, 'agent_commission_payments', 'bank_name',   "VARCHAR(100) DEFAULT NULL");
safeAddColumn($db, 'agent_commission_payments', 'updated_by',  "INT DEFAULT NULL");
safeAddColumn($db, 'agent_commission_payments', 'updated_at',  "TIMESTAMP NULL DEFAULT NULL");

/* ── AJAX: Get monthly commission total for an agent ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_month_total'])) {
    header('Content-Type: application/json');
    $agent_id = (int)($_POST['agent_id'] ?? 0);
    $month    = $db->real_escape_string($_POST['month'] ?? ''); // format: YYYY-MM
    if (!$agent_id || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
    }
    $agent_filter = ($is_agent && $agent_id !== $agent_uid) ? " AND 1=0" : ($is_agent ? " AND ac.agent_id=$agent_uid" : "");
    $res = $db->query("
        SELECT ac.id, ac.commission_amt, ac.challan_no, ac.despatch_date,
               COALESCE(v.vendor_name, ac.vendor_name) AS vendor_name,
               ac.received_weight, ac.profit_per_mt, ac.commission_pct
        FROM agent_commissions ac
        JOIN despatch_orders d ON d.id = ac.despatch_id
        LEFT JOIN vendors v ON v.id = d.vendor_id
        WHERE ac.agent_id=$agent_id
          AND ac.status='Pending'
          AND DATE_FORMAT(ac.despatch_date,'%Y-%m')='$month'
          $agent_filter
        ORDER BY ac.despatch_date
    ");
    $rows = [];
    $total = 0;
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
        $total += (float)$r['commission_amt'];
    }
    echo json_encode(['ok'=>true,'total'=>$total,'rows'=>$rows,'count'=>count($rows)]);
    exit;
}

/* ── AJAX: Get single payment details for edit/reprint ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_get_payment'])) {
    header('Content-Type: application/json');
    $pay_id = (int)($_POST['pay_id'] ?? 0);
    if (!$pay_id) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }

    // Step 1: Get payment record directly — no joins to avoid view issues
    $pres = $db->query("SELECT * FROM agent_commission_payments WHERE id=$pay_id");
    if (!$pres) { echo json_encode(['ok'=>false,'msg'=>'Payment query failed: '.$db->error]); exit; }
    $p = $pres->fetch_assoc();
    if (!$p) { echo json_encode(['ok'=>false,'msg'=>'Payment not found']); exit; }

    // Step 2: Get agent name separately
    $ares = $db->query("SELECT full_name FROM app_users WHERE id=".(int)$p['agent_id']);
    $p['agent_name'] = $ares ? ($ares->fetch_assoc()['full_name'] ?? '—') : '—';

    // Step 3: created_by name
    $p['created_by_name'] = '';
    if (!empty($p['created_by'])) {
        $cr = $db->query("SELECT full_name FROM app_users WHERE id=".(int)$p['created_by']);
        if ($cr) $p['created_by_name'] = $cr->fetch_assoc()['full_name'] ?? '';
    }

    // Step 4: updated_by name
    $p['updated_by_name'] = '';
    if (!empty($p['updated_by'])) {
        $ur = $db->query("SELECT full_name FROM app_users WHERE id=".(int)$p['updated_by']);
        if ($ur) $p['updated_by_name'] = $ur->fetch_assoc()['full_name'] ?? '';
    }

    // Step 5: linked challans — direct join, no views
    $challans = [];
    $cres = $db->query("
        SELECT ac.challan_no, ac.despatch_date,
               COALESCE(v.vendor_name, ac.vendor_name) AS vendor_name,
               ac.received_weight, ac.profit_per_mt, ac.commission_pct, ac.commission_amt
        FROM agent_payment_commissions apc
        JOIN agent_commissions ac ON ac.id = apc.commission_id
        JOIN despatch_orders d ON d.id = ac.despatch_id
        LEFT JOIN vendors v ON v.id = d.vendor_id
        WHERE apc.payment_id = $pay_id
        ORDER BY ac.despatch_date
    ");
    if ($cres) $challans = $cres->fetch_all(MYSQLI_ASSOC);
    else $p['challan_query_error'] = $db->error;
    $p['challans'] = $challans;

    // Ensure new fields have defaults if columns don't exist yet
    $p['utr_no']    = $p['utr_no']    ?? '';
    $p['bank_name'] = $p['bank_name'] ?? '';
    $p['notes']     = $p['notes']     ?? '';
    $p['updated_at']= $p['updated_at']?? '';

    echo json_encode(['ok'=>true,'payment'=>$p]);
    exit;
}

/* ── AJAX: Update payment details ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_edit_payment'])) {
    header('Content-Type: application/json');
    $pay_id    = (int)($_POST['pay_id'] ?? 0);
    $paid_date = $db->real_escape_string($_POST['paid_date'] ?? '');
    $amount    = (float)($_POST['amount'] ?? 0);
    $utr_no    = $db->real_escape_string($_POST['utr_no']    ?? '');
    $bank_name = $db->real_escape_string($_POST['bank_name'] ?? '');
    $notes     = $db->real_escape_string($_POST['notes']     ?? '');
    $updated_by = (int)($_SESSION['user_id'] ?? 0);
    if (!$pay_id) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }
    $db->query("UPDATE agent_commission_payments SET
        paid_date='$paid_date', amount=$amount, utr_no='$utr_no',
        bank_name='$bank_name', notes='$notes',
        updated_by=$updated_by, updated_at=NOW()
        WHERE id=$pay_id");
    echo json_encode(['ok'=>true]);
    exit;
}

/* ── AJAX: Mark commissions as Paid ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_pay'])) {
    header('Content-Type: application/json');
    $agent_id  = (int)($_POST['agent_id'] ?? 0);
    $ids       = array_map('intval', $_POST['commission_ids'] ?? []);
    $paid_date = $db->real_escape_string($_POST['paid_date']  ?? date('Y-m-d'));
    $reference = $db->real_escape_string($_POST['reference']  ?? '');
    $notes     = $db->real_escape_string($_POST['notes']      ?? '');
    $amount    = (float)($_POST['amount'] ?? 0);

    if (!$agent_id || empty($ids) || ($is_agent && $agent_id !== $agent_uid)) { echo json_encode(['ok'=>false,'msg'=>'Invalid data']); exit; }

    // Calculate total commission for selected ids
    $id_list   = implode(',', $ids);
    $total_row = $db->query("SELECT COALESCE(SUM(commission_amt),0) AS tot FROM agent_commissions WHERE id IN ($id_list)" . ($is_agent ? " AND agent_id=$agent_uid" : ""))->fetch_assoc();
    $total_due = (float)$total_row['tot'];

    $created_by = (int)($_SESSION['user_id'] ?? 0);
    $db->query("INSERT INTO agent_commission_payments (agent_id,amount,paid_date,reference,notes,created_by)
        VALUES ($agent_id,$amount,'$paid_date','$reference','$notes',$created_by)");
    $pay_id = $db->insert_id;
    foreach ($ids as $cid)
        $db->query("INSERT IGNORE INTO agent_payment_commissions (payment_id,commission_id) VALUES ($pay_id,$cid)");

    // Only mark as Paid if full amount paid (within ₹1 tolerance)
    if ($amount >= ($total_due - 1)) {
        $db->query("UPDATE agent_commissions SET status='Paid' WHERE id IN ($id_list)" . ($is_agent ? " AND agent_id=$agent_uid" : ""));
        $status = 'full';
    } else {
        // Partial payment — keep Pending, note in commission record
        $db->query("UPDATE agent_commissions SET notes=CONCAT(IFNULL(notes,''),' | Part paid ₹$amount on $paid_date') WHERE id IN ($id_list)");
        $status = 'partial';
    }
    echo json_encode(['ok'=>true,'pay_id'=>$pay_id,'status'=>$status,'total_due'=>$total_due,'paid'=>$amount]);
    exit;
}

/* ── Agents with pending commissions ── */
$agents = $db->query("
    SELECT u.id, u.full_name,
           COUNT(ac.id)            AS pending_count,
           SUM(ac.commission_amt)  AS pending_amt
    FROM app_users u
    JOIN agent_commissions ac ON ac.agent_id=u.id AND ac.status='Pending'" . ($is_agent ? " AND u.id=$agent_uid" : "") . "
    GROUP BY u.id, u.full_name
    ORDER BY u.full_name
")->fetch_all(MYSQLI_ASSOC);

/* ── Pending rows per agent, grouped by month ── */
$pending = [];
$res = $db->query("
    SELECT ac.*, d.despatch_no,
           COALESCE(v.vendor_name, ac.vendor_name) AS vendor_name
    FROM agent_commissions ac
    JOIN despatch_orders d ON ac.despatch_id=d.id
    LEFT JOIN vendors v ON v.id = d.vendor_id
    WHERE ac.status='Pending'" . ($is_agent ? " AND ac.agent_id=$agent_uid" : "") . "
    ORDER BY ac.agent_id, ac.despatch_date DESC
");
while ($row = $res->fetch_assoc()) {
    $aid  = (int)$row['agent_id'];
    $mkey = $row['despatch_date'] ? date('Y-m', strtotime($row['despatch_date'])) : '0000-00';
    $pending[$aid][$mkey][] = $row;
}

/* ── Summary cards ── */
$totals = $db->query("SELECT
    COUNT(CASE WHEN status='Pending' THEN 1 END)                              AS pend_count,
    COALESCE(SUM(CASE WHEN status='Pending' THEN commission_amt END), 0)      AS pend_amt,
    COUNT(CASE WHEN status='Paid'    THEN 1 END)                              AS paid_count,
    COALESCE(SUM(CASE WHEN status='Paid'    THEN commission_amt END), 0)      AS paid_amt
    FROM agent_commissions" . ($is_agent ? " WHERE agent_id=$agent_uid" : "") . "")->fetch_assoc();

/* ── Payment history — grouped by agent ── */
$pay_rows = [];
$hist_res = $db->query("
    SELECT p.id AS pay_id, p.agent_id, p.amount, p.paid_date,
           COALESCE(p.reference,'')         AS reference,
           COALESCE(p.notes,'')             AS notes,
           COALESCE(p.utr_no,'')            AS utr_no,
           COALESCE(p.bank_name,'')         AS bank_name,
           COALESCE(p.created_at,'')        AS created_at,
           COALESCE(p.updated_at,'')        AS updated_at,
           COALESCE(cu.full_name,'')        AS created_by_name,
           COALESCE(uu.full_name,'')        AS updated_by_name,
           u.full_name,
           ac.id AS comm_id, ac.challan_no, ac.despatch_date, ac.commission_amt,
           COALESCE(v.vendor_name, ac.vendor_name) AS vendor_name,
           ac.received_weight, ac.profit_per_mt, ac.commission_pct
    FROM agent_commission_payments p
    JOIN app_users u ON p.agent_id = u.id
    LEFT JOIN app_users cu ON cu.id = p.created_by
    LEFT JOIN app_users uu ON uu.id = p.updated_by
    LEFT JOIN agent_payment_commissions apc ON apc.payment_id = p.id
    LEFT JOIN agent_commissions ac ON ac.id = apc.commission_id
    LEFT JOIN despatch_orders d ON d.id = ac.despatch_id
    LEFT JOIN vendors v ON v.id = d.vendor_id
    WHERE 1=1" . ($is_agent ? " AND p.agent_id=$agent_uid" : "") . "
    ORDER BY u.full_name, p.paid_date DESC, p.id DESC, ac.despatch_date
");
if (!$hist_res) {
    error_log('[agent_commissions] pay_rows query failed: '.$db->error);
} else {
    $pay_rows = $hist_res->fetch_all(MYSQLI_ASSOC);
}

// Group: agent_id -> payment_id -> payment + challans
$pay_by_agent = [];
foreach ($pay_rows as $r) {
    $aid = (int)$r['agent_id'];
    $pid = (int)$r['pay_id'];
    if (!isset($pay_by_agent[$aid])) {
        $pay_by_agent[$aid] = ['full_name'=>$r['full_name'], 'total'=>0, 'payments'=>[]];
    }
    if (!isset($pay_by_agent[$aid]['payments'][$pid])) {
        $pay_by_agent[$aid]['payments'][$pid] = [
            'pay_id'           => $pid,
            'amount'           => (float)$r['amount'],
            'paid_date'        => $r['paid_date'],
            'reference'        => $r['reference'],
            'utr_no'           => $r['utr_no'],
            'bank_name'        => $r['bank_name'],
            'notes'            => $r['notes'],
            'created_at'       => $r['created_at'],
            'updated_at'       => $r['updated_at'],
            'created_by_name'  => $r['created_by_name'],
            'updated_by_name'  => $r['updated_by_name'],
            'challans'         => [],
        ];
        $pay_by_agent[$aid]['total'] += (float)$r['amount'];
    }
    if ($r['comm_id']) {
        $pay_by_agent[$aid]['payments'][$pid]['challans'][] = [
            'challan_no'     => $r['challan_no'],
            'despatch_date'  => $r['despatch_date'],
            'commission_amt' => (float)$r['commission_amt'],
            'vendor_name'    => $r['vendor_name'],
            'weight'         => (float)$r['received_weight'],
            'profit'         => (float)$r['profit_per_mt'],
            'pct'            => (float)$r['commission_pct'],
        ];
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-percent me-2"></i>Agent Commissions';</script>
<?php if ($is_agent): ?>
<div class="alert alert-info py-2 mb-3"><i class="bi bi-person-lock me-2"></i>Showing <strong>your commissions only</strong>.</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-warning h-100">
            <div class="card-body text-center py-3">
                <div class="fs-1 fw-bold text-warning"><?= number_format($totals['pend_count']) ?></div>
                <div class="text-muted small">Pending Challans</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-danger h-100">
            <div class="card-body text-center py-3">
                <div class="fs-4 fw-bold text-danger">₹<?= number_format($totals['pend_amt'],2) ?></div>
                <div class="text-muted small">Pending Amount</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-success h-100">
            <div class="card-body text-center py-3">
                <div class="fs-1 fw-bold text-success"><?= number_format($totals['paid_count']) ?></div>
                <div class="text-muted small">Paid Challans</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-success h-100">
            <div class="card-body text-center py-3">
                <div class="fs-4 fw-bold text-success">₹<?= number_format($totals['paid_amt'],2) ?></div>
                <div class="text-muted small">Total Paid</div>
            </div>
        </div>
    </div>
</div>

<!-- Pending Commissions -->
<div class="card mb-4">
    <div class="card-header fw-bold">
        <i class="bi bi-clock-history me-2 text-warning"></i>Pending Commissions
    </div>
    <div class="card-body p-0">
    <?php if (empty($agents)): ?>
        <div class="p-4 text-center text-muted">No pending commissions.</div>
    <?php else: foreach ($agents as $ag):
        $ag_id   = (int)$ag['id'];
        $ag_rows = $pending[$ag_id] ?? [];
    ?>
    <div class="border-bottom">
        <!-- Agent header row -->
        <div class="d-flex justify-content-between align-items-center p-3 bg-light"
             style="cursor:pointer" onclick="toggleAgent(<?= $ag_id ?>)">
            <div>
                <i class="bi bi-person-circle me-2 text-primary"></i>
                <strong><?= htmlspecialchars($ag['full_name']) ?></strong>
                <span class="badge bg-warning text-dark ms-2"><?= $ag['pending_count'] ?> challan(s)</span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-danger fs-6">₹<?= number_format((float)$ag['pending_amt'],2) ?></span>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="event.stopPropagation(); printAgentCommission(<?= $ag_id ?>, '<?= htmlspecialchars($ag['full_name'], ENT_QUOTES) ?>')">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
<?php
                    $months_js = [];
                    foreach ($pending[$ag_id] ?? [] as $mk => $mrows) {
                        $months_js[] = json_encode([
                            'mkey'  => $mk,
                            'label' => $mk !== '0000-00' ? date('F Y', strtotime($mk.'-01')) : 'Unknown',
                            'amt'   => array_sum(array_column($mrows,'commission_amt')),
                            'count' => count($mrows),
                        ]);
                    }
                    $months_json = htmlspecialchars('['.implode(',',$months_js).']', ENT_QUOTES);
                    $ag_name_js  = htmlspecialchars($ag['full_name'], ENT_QUOTES);
                ?>
                <button type="button" class="btn btn-sm btn-success"
                    onclick="event.stopPropagation(); openPayModal(<?= $ag_id ?>,'<?= $ag_name_js ?>',JSON.parse(this.dataset.months))" data-months="<?= $months_json ?>">
                    <i class="bi bi-cash-coin me-1"></i>Record Payment
                </button>
                <i class="bi bi-chevron-down" id="chevron-<?= $ag_id ?>"></i>
            </div>
        </div>
        <!-- Detail rows — grouped by month -->
        <div id="agent-<?= $ag_id ?>" style="display:none">
        <div class="table-responsive">
        <table class="table table-sm mb-0" id="comm-table-<?= $ag_id ?>">
            <thead style="background:#1a5632;color:#fff">
                <tr>
                    <th><input type="checkbox" onclick="toggleAllAgent(<?= $ag_id ?>, this)" title="Select all"></th>
                    <th>Challan No</th>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Vendor Rate</th>
                    <th class="text-end">Trans Rate</th>
                    <th class="text-end">Profit/MT</th>
                    <th class="text-center">Slab</th>
                    <th class="text-end">Comm %</th>
                    <th class="text-end">Commission ₹</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $ag_months   = $pending[$ag_id] ?? [];
            $grand_total = 0;
            $grand_wt    = 0;
            foreach ($ag_months as $mkey => $mrows):
                $month_label = $mkey !== '0000-00' ? date('F Y', strtotime($mkey.'-01')) : 'Unknown Date';
                $month_comm  = array_sum(array_column($mrows, 'commission_amt'));
                $month_wt    = array_sum(array_column($mrows, 'received_weight'));
                $grand_total += $month_comm;
                $grand_wt    += $month_wt;
            ?>
            <tr class="month-header-row" style="background:#e8f5e9;border-top:2px solid #1a5632">
                <td colspan="4" class="fw-bold ps-3 py-2" style="color:#1a5632">
                    <i class="bi bi-calendar3 me-2"></i><?= $month_label ?>
                    <span class="badge bg-secondary ms-2"><?= count($mrows) ?> challan(s)</span>
                    <span class="ms-2 text-muted fw-normal" style="font-size:.82rem"><?= number_format($month_wt,3) ?> MT</span>
                </td>
                <td colspan="7" class="text-end fw-bold pe-3 py-2" style="color:#1a5632">
                    Month Total: ₹<?= number_format($month_comm,2) ?>
                </td>
            </tr>
            <?php foreach ($mrows as $r): ?>
            <tr>
                <td><input type="checkbox" class="agent-chk-<?= $ag_id ?>"
                    value="<?= $r['id'] ?>" data-amt="<?= $r['commission_amt'] ?>"
                    onchange="updateSelAmt(<?= $ag_id ?>)"></td>
                <td>
                    <a href="despatch.php?action=edit&id=<?= $r['despatch_id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'agent_commissions.php') ?>" target="_blank">
                        <?= htmlspecialchars($r['challan_no']) ?>
                    </a>
                </td>
                <td><?= $r['despatch_date'] ? date('d-m-Y', strtotime($r['despatch_date'])) : '-' ?></td>
                <td><small><?= htmlspecialchars($r['vendor_name']) ?></small></td>
                <td class="text-end"><?= number_format((float)$r['received_weight'],3) ?></td>
                <td class="text-end">₹<?= number_format((float)$r['vendor_rate'],2) ?></td>
                <td class="text-end">₹<?= number_format((float)$r['transporter_rate'],2) ?></td>
                <td class="text-end">₹<?= number_format((float)$r['profit_per_mt'],2) ?></td>
                <td class="text-center">
                    <span class="badge <?= $r['slab_applied']==1 ? 'bg-info' : 'bg-primary' ?>">
                        Slab <?= $r['slab_applied'] ?>
                </span>
                </td>
                <td class="text-end"><?= $r['commission_pct'] ?>%</td>
                <td class="text-end fw-bold text-danger">₹<?= number_format((float)$r['commission_amt'],2) ?></td>
            </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
            <tfoot style="background:#fff3cd">
                <tr>
                    <td colspan="4" class="fw-bold ps-3">Grand Total</td>
                    <td class="text-end fw-bold"><?= number_format($grand_wt,3) ?> MT</td>
                    <td colspan="5"></td>
                    <td class="text-end fw-bold text-danger fs-6">₹<?= number_format($grand_total,2) ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<!-- Payment History -->
<div class="card">
    <div class="card-header fw-bold">
        <i class="bi bi-receipt me-2 text-success"></i>Payment History
    </div>
    <div class="card-body p-0">
    <?php if (empty($pay_by_agent)): ?>
        <div class="p-4 text-center text-muted">No payments recorded yet.</div>
    <?php else: foreach ($pay_by_agent as $aid => $ag): ?>
    <div class="border-bottom">
        <!-- Agent header -->
        <div class="d-flex justify-content-between align-items-center p-3 bg-light"
             style="cursor:pointer" onclick="togglePayHistory(<?= $aid ?>)">
            <div>
                <i class="bi bi-person-circle me-2 text-success"></i>
                <strong><?= htmlspecialchars($ag['full_name']) ?></strong>
                <span class="badge bg-secondary ms-2"><?= count($ag['payments']) ?> payment(s)</span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-success">₹<?= number_format($ag['total'],2) ?> total paid</span>
                <i class="bi bi-chevron-down" id="ph-chevron-<?= $aid ?>"></i>
            </div>
        </div>
        <!-- Payments for this agent -->
        <div id="ph-agent-<?= $aid ?>" style="display:none">
        <?php foreach ($ag['payments'] as $p): ?>
        <div class="border-top ms-3 me-3 mt-2 mb-3">
            <!-- Payment header -->
            <div class="d-flex justify-content-between align-items-start py-2 px-2 rounded" style="background:#f8f9fa">
                <div>
                    <div class="mb-1">
                        <i class="bi bi-cash-coin me-1 text-success"></i>
                        <strong>₹<?= number_format($p['amount'],2) ?></strong>
                        <span class="text-muted ms-2" style="font-size:0.85rem">
                            <?= date('d M Y', strtotime($p['paid_date'])) ?>
                        </span>
                        <?php if ($p['utr_no']): ?>
                        <span class="badge bg-success ms-2">UTR: <?= htmlspecialchars($p['utr_no']) ?></span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark ms-2">UTR Pending</span>
                        <?php endif; ?>
                        <?php if ($p['bank_name']): ?>
                        <span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars($p['bank_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($p['reference']): ?>
                        <span class="badge bg-light text-secondary border ms-1">Ref: <?= htmlspecialchars($p['reference']) ?></span>
                        <?php endif; ?>
                    </div>
                    <!-- Audit trail -->
                    <div style="font-size:0.74rem;color:#888">
                        Recorded by <strong><?= htmlspecialchars($p['created_by_name'] ?? '—') ?></strong>
                        on <?= $p['created_at'] ? date('d M Y H:i', strtotime($p['created_at'])) : '—' ?>
                        <?php if ($p['updated_at'] && $p['updated_by_name']): ?>
                        &nbsp;·&nbsp; Last edited by <strong><?= htmlspecialchars($p['updated_by_name']) ?></strong>
                        on <?= date('d M Y H:i', strtotime($p['updated_at'])) ?>
                        <?php endif; ?>
                        &nbsp;·&nbsp; PA-<?= $p['pay_id'] ?>
                    </div>
                    <?php if ($p['notes']): ?>
                    <div style="font-size:0.8rem;color:#555" class="mt-1"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($p['notes']) ?></div>
                    <?php endif; ?>
                </div>
                <!-- Action buttons -->
                <div class="d-flex gap-2 ms-3 flex-shrink-0">
                    <button class="btn btn-sm btn-outline-primary" style="font-size:0.78rem"
                        onclick="openEditPayment(<?= $p['pay_id'] ?>, this)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:0.78rem"
                        onclick="reprintAdvice(<?= $p['pay_id'] ?>)">
                        <i class="bi bi-printer me-1"></i>Reprint
                    </button>
                </div>
            </div>
            <!-- Challan breakdown -->
            <?php if (!empty($p['challans'])): ?>
            <div class="table-responsive ms-2">
            <table class="table table-sm table-borderless mb-1">
                <thead><tr class="text-muted" style="font-size:0.78rem">
                    <th>Challan No</th><th>Date</th><th>Vendor</th>
                    <th class="text-end">Weight</th><th class="text-end">Profit/MT</th>
                    <th class="text-end">Comm%</th><th class="text-end">Commission</th>
                </tr></thead>
                <tbody>
                <?php foreach ($p['challans'] as $ch): ?>
                <tr style="font-size:0.82rem">
                    <td><span class="text-primary fw-semibold"><?= htmlspecialchars($ch['challan_no']) ?></span></td>
                    <td><?= $ch['despatch_date'] ? date('d-m-Y', strtotime($ch['despatch_date'])) : '-' ?></td>
                    <td><small><?= htmlspecialchars($ch['vendor_name']) ?></small></td>
                    <td class="text-end"><?= number_format($ch['weight'],3) ?> MT</td>
                    <td class="text-end">₹<?= number_format($ch['profit'],2) ?></td>
                    <td class="text-end"><?= $ch['pct'] ?>%</td>
                    <td class="text-end fw-semibold text-success">₹<?= number_format($ch['commission_amt'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="border-top">
                    <td colspan="6" class="text-end fw-bold text-muted" style="font-size:0.82rem">Total Commission:</td>
                    <td class="text-end fw-bold text-success">₹<?= number_format(array_sum(array_column($p['challans'],'commission_amt')),2) ?></td>
                </tr></tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a5632;color:#fff">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Record Commission Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="payAgentId">
                <input type="hidden" id="payMonthIds"> <!-- comma-separated commission ids for selected month -->

                <!-- Agent -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Agent</label>
                    <div id="payAgentName" class="form-control bg-light fw-bold"></div>
                </div>

                <!-- Month selector -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Select Month <span class="text-danger">*</span></label>
                    <select id="payMonth" class="form-select" onchange="onMonthChange()">
                        <option value="">— Choose a month —</option>
                    </select>
                    <div class="form-text">Only pending challans for the selected month will be included.</div>
                </div>

                <!-- Monthly commission summary box -->
                <div id="monthSummaryBox" class="alert alert-info py-2 mb-3" style="display:none">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-calendar3 me-2"></i>
                            <span id="monthSummaryLabel" class="fw-semibold"></span>
                            <span class="ms-2 text-muted" id="monthSummaryCount"></span>
                        </div>
                        <div class="fs-5 fw-bold text-success" id="monthSummaryAmt"></div>
                    </div>
                    <!-- Mini challan table -->
                    <div class="mt-2" id="monthChallanList" style="max-height:180px;overflow-y:auto;font-size:0.82rem"></div>
                </div>
                <div id="monthLoadingBox" class="text-center text-muted py-2" style="display:none">
                    <span class="spinner-border spinner-border-sm me-2"></span>Loading month data...
                </div>

                <!-- Payment details — always visible -->
                <div id="payDetailsSection">
                <!-- Amount -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Amount to Pay (₹)</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" id="payAmount" class="form-control fw-bold fs-5" step="0.01" placeholder="Select month above to auto-fill">
                    </div>
                    <div class="form-text">Auto-filled from month total. Edit for partial payment.</div>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" id="payDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Reference / Cheque No</label>
                        <input type="text" id="payRef" class="form-control" placeholder="Optional">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Notes</label>
                    <textarea id="payNotes" class="form-control" rows="2" placeholder="Optional"></textarea>
                </div>
                </div><!-- /payDetailsSection -->

                <div class="alert alert-warning mt-3 py-2 mb-0" id="payNoSelect" style="display:none">
                    <i class="bi bi-exclamation-triangle me-1"></i><span id="payNoSelectMsg">Please select a month first.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btnRecordPay" onclick="submitPayment()">
                    <i class="bi bi-check-circle me-1"></i>Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script>
var CURRENT_USER_NAME = <?= json_encode($__user_name) ?>;
var CURRENT_USER_SIG  = <?= json_encode($__sig_url) ?>;
</script>
<script>
function toggleAgent(id) {
    var el  = document.getElementById('agent-' + id);
    var chv = document.getElementById('chevron-' + id);
    var open = el.style.display === 'none';
    el.style.display = open ? 'block' : 'none';
    chv.className    = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}
function toggleAllAgent(agId, master) {
    document.querySelectorAll('.agent-chk-' + agId).forEach(function(cb) {
        cb.checked = master.checked;
    });
    updateSelAmt(agId);
}
function updateSelAmt(agId) {
    var openId = parseInt(document.getElementById('payAgentId').value || '0');
    if (openId !== agId) return;
    var total = 0, ids = [];
    document.querySelectorAll('.agent-chk-' + agId + ':checked').forEach(function(cb) {
        total += parseFloat(cb.dataset.amt || 0);
        ids.push(cb.value);
    });
    document.getElementById('payAmount').value = total.toFixed(2);
}
function togglePayHistory(aid) {
    var el  = document.getElementById('ph-agent-' + aid);
    var chv = document.getElementById('ph-chevron-' + aid);
    var open = el.style.display === 'none';
    el.style.display = open ? 'block' : 'none';
    chv.className    = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}
function toggleHistory() {
    var sec = document.getElementById('historySection');
    var btn = document.getElementById('histBtn');
    var open = sec.style.display === 'none';
    sec.style.display = open ? 'block' : 'none';
    btn.textContent   = open ? 'Hide History' : 'Show History';
}

// ── Month-aware Pay Modal ─────────────────────────────────────────────────
var _payMonthRows = []; // rows returned for selected month

function openPayModal(agId, agName, pendingMonths) {
    // pendingMonths: array of {mkey:'YYYY-MM', label:'Month Year', amt:1234, count:5}
    document.getElementById('payAgentId').value          = agId;
    document.getElementById('payAgentName').textContent  = agName;
    document.getElementById('payAmount').value           = '';
    document.getElementById('payNoSelect').style.display = 'none';
    document.getElementById('monthSummaryBox').style.display  = 'none';
    document.getElementById('monthLoadingBox').style.display  = 'none';
    document.getElementById('payMonthIds').value         = '';
    _payMonthRows = [];

    // Populate month dropdown from pending months
    var sel = document.getElementById('payMonth');
    sel.innerHTML = '<option value="">— Choose a month —</option>';
    if (pendingMonths && pendingMonths.length) {
        pendingMonths.forEach(function(m) {
            var opt = document.createElement('option');
            opt.value = m.mkey;
            opt.textContent = m.label + '  (' + m.count + ' challan' + (m.count>1?'s':'') + '  ₹' + parseFloat(m.amt).toLocaleString('en-IN',{minimumFractionDigits:2}) + ')';
            sel.appendChild(opt);
        });
        // Auto-select if only one month
        if (pendingMonths.length === 1) {
            sel.value = pendingMonths[0].mkey;
            onMonthChange();
        }
    }
    if (window.bootstrap) new bootstrap.Modal(document.getElementById('payModal')).show();
}

function onMonthChange() {
    var agId  = parseInt(document.getElementById('payAgentId').value);
    var month = document.getElementById('payMonth').value;
    document.getElementById('monthSummaryBox').style.display  = 'none';
    document.getElementById('monthLoadingBox').style.display  = 'none';
    document.getElementById('payAmount').value = '';
    document.getElementById('payMonthIds').value = '';
    _payMonthRows = [];
    if (!month) return;

    document.getElementById('monthLoadingBox').style.display = 'block';
    var fd = new FormData();
    fd.append('ajax_month_total','1');
    fd.append('agent_id', agId);
    fd.append('month', month);

    fetch('agent_commissions.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(function(res) {
            document.getElementById('monthLoadingBox').style.display = 'none';
            if (!res.ok) { alert('Error: ' + (res.msg||'Unknown')); return; }
            _payMonthRows = res.rows;
            document.getElementById('payMonthIds').value = res.rows.map(r=>r.id).join(',');
            document.getElementById('payAmount').value   = parseFloat(res.total).toFixed(2);

            // Month label
            var d = new Date(month + '-01');
            var lbl = d.toLocaleString('en-IN',{month:'long',year:'numeric'});
            document.getElementById('monthSummaryLabel').textContent = lbl;
            document.getElementById('monthSummaryCount').textContent = '(' + res.count + ' challan' + (res.count!==1?'s':'') + ')';
            document.getElementById('monthSummaryAmt').textContent   = '₹' + parseFloat(res.total).toLocaleString('en-IN',{minimumFractionDigits:2});

            // Mini table
            var html = '<table class="table table-sm table-bordered mb-0" style="font-size:0.78rem"><thead class="table-dark"><tr>' +
                '<th>Challan</th><th>Date</th><th>Vendor</th><th class="text-end">Wt(MT)</th><th class="text-end">Commission ₹</th></tr></thead><tbody>';
            res.rows.forEach(function(r) {
                html += '<tr><td>' + (r.challan_no||'-') + '</td>' +
                    '<td>' + (r.despatch_date ? r.despatch_date.substr(0,10) : '-') + '</td>' +
                    '<td>' + (r.vendor_name||'') + '</td>' +
                    '<td class="text-end">' + parseFloat(r.received_weight).toFixed(3) + '</td>' +
                    '<td class="text-end fw-semibold">₹' + parseFloat(r.commission_amt).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</td></tr>';
            });
            html += '</tbody><tfoot><tr><td colspan="4" class="text-end fw-bold">Total</td>' +
                '<td class="text-end fw-bold text-success">₹' + parseFloat(res.total).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</td></tr></tfoot></table>';
            document.getElementById('monthChallanList').innerHTML = html;
            document.getElementById('monthSummaryBox').style.display = 'block';
        })
        .catch(function(err) {
            document.getElementById('monthLoadingBox').style.display = 'none';
            alert('Network error: ' + err.message);
        });
}

// ── Submit Payment ────────────────────────────────────────────────────────
function submitPayment() {
    var agId   = parseInt(document.getElementById('payAgentId').value);
    var month  = document.getElementById('payMonth').value;
    var idsStr = document.getElementById('payMonthIds').value.trim();
    var amount = parseFloat(document.getElementById('payAmount').value) || 0;
    var date   = document.getElementById('payDate').value;
    var ref    = document.getElementById('payRef').value;
    var notes  = document.getElementById('payNotes').value;
    var noSel  = document.getElementById('payNoSelect');
    var noSelMsg = document.getElementById('payNoSelectMsg');

    noSel.style.display = 'none';

    if (!month) {
        noSelMsg.textContent = 'Please select a month first.';
        noSel.style.display = 'block';
        return;
    }
    if (!date) {
        alert('Please enter payment date.');
        return;
    }
    if (amount <= 0) {
        alert('Please enter a valid amount.');
        return;
    }

    // If IDs not yet loaded (AJAX still pending), fetch them now then submit
    if (!idsStr) {
        var btn = document.getElementById('btnRecordPay');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';
        var fd2 = new FormData();
        fd2.append('ajax_month_total','1');
        fd2.append('agent_id', agId);
        fd2.append('month', month);
        fetch('agent_commissions.php', {method:'POST', body:fd2})
            .then(r => r.json())
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Record Payment';
                if (!res.ok || !res.rows.length) {
                    noSelMsg.textContent = 'No pending challans found for selected month.';
                    noSel.style.display = 'block';
                    return;
                }
                _payMonthRows = res.rows;
                document.getElementById('payMonthIds').value = res.rows.map(function(r){return r.id;}).join(',');
                if (!document.getElementById('payAmount').value) {
                    document.getElementById('payAmount').value = parseFloat(res.total).toFixed(2);
                    amount = parseFloat(res.total);
                }
                doSubmit(agId, month, res.rows.map(function(r){return parseInt(r.id);}), amount, date, ref, notes);
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Record Payment';
                alert('Network error: ' + err.message);
            });
        return;
    }

    var ids = idsStr.split(',').map(Number).filter(Boolean);
    if (!ids.length) {
        noSelMsg.textContent = 'No challans found for selected month. Please re-select.';
        noSel.style.display = 'block';
        return;
    }
    doSubmit(agId, month, ids, amount, date, ref, notes);
}

function doSubmit(agId, month, ids, amount, date, ref, notes) {
    var btn = document.getElementById('btnRecordPay');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    var agentName  = document.getElementById('payAgentName').textContent;
    var monthLabel = document.getElementById('monthSummaryLabel').textContent ||
                     new Date(month+'-01').toLocaleString('en-IN',{month:'long',year:'numeric'});
    var totalDue   = parseFloat((document.getElementById('monthSummaryAmt').textContent||'0').replace(/[₹,]/g,'')) || amount;

    var fd = new FormData();
    fd.append('ajax_pay', '1');
    fd.append('agent_id', agId);
    fd.append('amount',   amount);
    fd.append('paid_date', date);
    fd.append('reference', ref);
    fd.append('notes',     notes);
    ids.forEach(function(id) { fd.append('commission_ids[]', id); });

    fetch('agent_commissions.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Record Payment';
            if (res.ok) {
                var modalEl   = document.getElementById('payModal');
                var modalInst = window.bootstrap ? bootstrap.Modal.getInstance(modalEl) : null;
                if (modalInst) modalInst.hide();
                setTimeout(function() {
                    printPaymentAdvice({
                        agentName  : agentName,
                        monthLabel : monthLabel,
                        payDate    : date,
                        reference  : ref,
                        notes      : notes,
                        amount     : amount,
                        totalDue   : totalDue,
                        rows       : JSON.parse(JSON.stringify(_payMonthRows)),
                        status     : res.status,
                        payId      : res.pay_id
                    });
                    if (res.status === 'partial') {
                        var bal = (parseFloat(res.total_due) - parseFloat(res.paid)).toFixed(2);
                        alert('Partial payment of ₹' + parseFloat(res.paid).toFixed(2) + ' recorded.\nBalance ₹' + bal + ' remains pending.');
                    }
                    location.reload();
                }, 300);
            } else {
                alert('Error: ' + (res.msg || 'Unknown error. Check server logs.'));
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Record Payment';
            alert('Network error: ' + err.message);
        });
}

// ── Payment Advice Print ──────────────────────────────────────────────────
function printPaymentAdvice(d) {
    var printDate = new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'long',year:'numeric'});
    var payDateFmt = d.payDate ? new Date(d.payDate).toLocaleDateString('en-IN',{day:'2-digit',month:'long',year:'numeric'}) : '-';
    var isPartial  = d.status === 'partial';
    var balance    = (d.totalDue - d.amount).toFixed(2);

    var challanRows = '';
    var sno = 1;
    d.rows.forEach(function(r) {
        challanRows += '<tr>' +
            '<td class="c">' + (sno++) + '</td>' +
            '<td>' + (r.challan_no||'-') + '</td>' +
            '<td>' + (r.despatch_date ? r.despatch_date.substr(0,10) : '-') + '</td>' +
            '<td>' + (r.vendor_name||'-') + '</td>' +
            '<td class="r">' + parseFloat(r.received_weight).toFixed(3) + '</td>' +
            '<td class="r">' + parseFloat(r.profit_per_mt||0).toFixed(2) + '</td>' +
            '<td class="r">' + parseFloat(r.commission_pct||0).toFixed(2) + '%</td>' +
            '<td class="r fw">₹' + parseFloat(r.commission_amt).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</td>' +
            '</tr>';
    });

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payment Advice</title>' +
    '<style>' +
    'body{font-family:Arial,sans-serif;font-size:11px;margin:0;padding:20px;color:#222}' +
    '.header{border-bottom:3px solid #1a5632;padding-bottom:10px;margin-bottom:16px}' +
    '.company{font-size:18px;font-weight:bold;color:#1a5632;letter-spacing:.5px}' +
    '.doc-title{font-size:15px;font-weight:bold;color:#fff;background:#1a5632;padding:6px 14px;display:inline-block;border-radius:3px;margin:8px 0 4px}' +
    '.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 20px;margin-bottom:14px;background:#f5f5f5;padding:10px 14px;border-radius:4px}' +
    '.meta-grid .lbl{color:#555;font-size:10px}' +
    '.meta-grid .val{font-weight:bold;font-size:11px}' +
    'table{width:100%;border-collapse:collapse;margin-bottom:12px}' +
    'th{background:#1a5632;color:#fff;padding:5px 7px;text-align:left;font-size:10px}' +
    'th.r,td.r{text-align:right} th.c,td.c{text-align:center}' +
    'td{padding:4px 7px;border-bottom:1px solid #e0e0e0;font-size:10px}' +
    'tr:nth-child(even) td{background:#f9f9f9}' +
    '.tfoot-row td{background:#e8f5e9;font-weight:bold;border-top:2px solid #1a5632}' +
    '.summary-box{border:2px solid #1a5632;border-radius:5px;padding:12px 16px;margin-top:10px}' +
    '.summary-box .row{display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px dotted #ccc}' +
    '.summary-box .row:last-child{border-bottom:none;font-size:13px;font-weight:bold;color:#1a5632;padding-top:7px}' +
    '.partial-badge{background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:2px 8px;border-radius:3px;font-size:10px;font-weight:bold}' +
    '.paid-badge{background:#d1e7dd;color:#0f5132;border:1px solid #198754;padding:2px 8px;border-radius:3px;font-size:10px;font-weight:bold}' +
    '.sig-block{margin-top:40px;display:flex;justify-content:space-between}' +
    '.sig-block .sig{text-align:center;width:200px}' +
    '.sig-block .sig .line{border-top:1px solid #333;margin-bottom:4px}' +
    '.fw{font-weight:bold}' +
    '@media print{@page{size:A4;margin:12mm}body{padding:0}}' +
    '</style></head><body>' +

    '<div class="header">' +
    '<div class="company">TSG Impex</div>' +
    '<div class="doc-title">PAYMENT ADVICE</div>' +
    '<div style="float:right;text-align:right;margin-top:-50px">' +
    '<div style="font-size:10px;color:#555">Printed: ' + printDate + '</div>' +
    '<div style="font-size:10px;color:#555">Ref: PA-' + (d.payId||'') + '</div>' +
    '</div><div style="clear:both"></div>' +
    '</div>' +

    '<div class="meta-grid">' +
    '<div><div class="lbl">AGENT NAME</div><div class="val">' + d.agentName + '</div></div>' +
    '<div><div class="lbl">PAYMENT DATE</div><div class="val">' + payDateFmt + '</div></div>' +
    '<div><div class="lbl">COMMISSION MONTH</div><div class="val">' + d.monthLabel + '</div></div>' +
    '<div><div class="lbl">REFERENCE / CHEQUE NO</div><div class="val">' + (d.reference||'—') + '</div></div>' +
    '<div><div class="lbl">UTR / TRANSACTION NO</div><div class="val ' + (d.utr_no?'':'text-danger') + '">' + (d.utr_no||'NOT YET PROVIDED') + '</div></div>' +
    '<div><div class="lbl">BANK NAME</div><div class="val">' + (d.bank_name||'—') + '</div></div>' +
    (d.notes ? '<div style="grid-column:1/-1"><div class="lbl">NOTES</div><div class="val">' + d.notes + '</div></div>' : '') +
    '<div><div class="lbl">STATUS</div><div class="val">' +
        (isPartial ? '<span class="partial-badge">PARTIAL PAYMENT</span>' : '<span class="paid-badge">FULLY PAID</span>') +
    '</div></div>' +
    '</div>' +

    '<table><thead><tr>' +
    '<th class="c">#</th><th>Challan No</th><th>Date</th><th>Vendor</th>' +
    '<th class="r">Weight (MT)</th><th class="r">Profit/MT (₹)</th><th class="r">Comm%</th><th class="r">Commission (₹)</th>' +
    '</tr></thead><tbody>' + challanRows + '</tbody>' +
    '<tfoot><tr class="tfoot-row">' +
    '<td colspan="4" class="fw">Total (' + d.rows.length + ' challan' + (d.rows.length!==1?'s':'') + ')</td>' +
    '<td class="r">' + d.rows.reduce(function(s,r){return s+parseFloat(r.received_weight||0);},0).toFixed(3) + ' MT</td>' +
    '<td colspan="2"></td>' +
    '<td class="r fw">₹' + parseFloat(d.totalDue).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</td>' +
    '</tr></tfoot></table>' +

    '<div class="summary-box">' +
    '<div class="row"><span>Total Commission for ' + d.monthLabel + '</span><span>₹' + parseFloat(d.totalDue).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</span></div>' +
    '<div class="row"><span>Amount Paid Now</span><span>₹' + parseFloat(d.amount).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</span></div>' +
    (isPartial ? '<div class="row" style="color:#856404"><span>Balance Remaining</span><span>₹' + parseFloat(balance).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</span></div>' : '') +
    '<div class="row"><span>NET AMOUNT PAID</span><span>₹' + parseFloat(d.amount).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</span></div>' +
    '</div>' +

    '<div class="sig-block">' +
    '<div class="sig"><div class="line"></div><div>Agent Signature</div><div style="font-size:10px;color:#555">' + d.agentName + '</div></div>' +
    '<div class="sig">' +
        (CURRENT_USER_SIG ? '<img src="' + CURRENT_USER_SIG + '" style="max-height:48px;max-width:120px;object-fit:contain;display:block;margin:0 auto 4px">' : '') +
        '<div class="line"></div>' +
        '<div>Authorised Signatory</div>' +
        '<div style="font-size:10px;color:#555">' + CURRENT_USER_NAME + ' — TSG Impex</div>' +
    '</div>' +
    '</div>' +

    '<div style="text-align:center;margin-top:20px;font-size:9px;color:#aaa">This is a computer-generated payment advice. No signature required if authorised electronically.</div>' +
    '</body></html>';

    var w = window.open('', '_blank', 'width=850,height=700');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(function(){ w.print(); }, 500);
}

// ── Existing commission statement print ───────────────────────────────────
function printAgentCommission(agId, agName) {
    var sec = document.getElementById('agent-' + agId);
    if (sec && sec.style.display === 'none') sec.style.display = 'block';
    var tbl = document.getElementById('comm-table-' + agId);
    if (!tbl) { alert('No data to print.'); return; }
    var printDate = new Date().toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8">' +
        '<title>Commission Statement — ' + agName + '</title>' +
        '<style>' +
        'body{font-family:Arial,sans-serif;font-size:11px;margin:20px}' +
        'h2{margin:0 0 4px;font-size:15px;color:#1a5632}' +
        '.sub{font-size:11px;color:#555;margin-bottom:12px}' +
        'table{width:100%;border-collapse:collapse}' +
        'th{background:#1a5632;color:#fff;padding:5px 7px;text-align:left;font-size:10px}' +
        'th.r,td.r{text-align:right}' +
        'td{padding:4px 7px;border-bottom:1px solid #ddd;font-size:10px}' +
        '.mhdr td{background:#e8f5e9;color:#1a5632;font-weight:bold;border-top:2px solid #1a5632}' +
        '.mhdr td.r{text-align:right}' +
        '.ft td{background:#fff3cd;font-weight:bold}' +
        '.ft td.r{text-align:right}' +
        '@media print{@page{size:A4 landscape;margin:10mm}}' +
        '</style></head><body>' +
        '<h2><i>Agent Commission Statement</i></h2>' +
        '<div class="sub">Agent: <strong>' + agName + '</strong> &nbsp;|&nbsp; Printed: ' + printDate + ' &nbsp;|&nbsp; Status: Pending</div>';
    var clone = tbl.cloneNode(true);
    clone.querySelectorAll('tr').forEach(function(tr) {
        if (tr.cells.length > 1) tr.deleteCell(0);
    });
    clone.querySelectorAll('a').forEach(function(a) { a.outerHTML = a.textContent; });
    clone.querySelectorAll('.badge').forEach(function(b) { b.outerHTML = b.textContent; });
    clone.querySelectorAll('tr').forEach(function(tr) {
        if (tr.style && tr.style.background && tr.style.background.indexOf('e8f5e9') >= 0) {
            tr.className = 'mhdr'; tr.removeAttribute('style');
        }
    });
    html += clone.outerHTML + '</body></html>';
    var w = window.open('', '_blank', 'width=900,height=600');
    w.document.write(html); w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 400);
}

// ── Edit Payment ──────────────────────────────────────────────────────────
function openEditPayment(payId, btnEl) {
    btnEl.disabled = true;
    btnEl.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    var fd = new FormData();
    fd.append('ajax_get_payment','1');
    fd.append('pay_id', payId);
    fetch('agent_commissions.php',{method:'POST',body:fd})
        .then(function(r){
            if (!r.ok) throw new Error('HTTP '+r.status);
            return r.text();
        })
        .then(function(txt){
            var res;
            try { res = JSON.parse(txt); }
            catch(e) { throw new Error('Non-JSON: '+txt.substr(0,300)); }
            btnEl.disabled = false;
            btnEl.innerHTML = '<i class="bi bi-pencil me-1"></i>Edit';
            if (!res.ok) { alert('Server error: '+(res.msg||JSON.stringify(res))); return; }
            var p = res.payment;
            document.getElementById('editPayId').value            = p.id;
            document.getElementById('editPayAgent').textContent   = p.agent_name;
            document.getElementById('editPayDate').value          = p.paid_date;
            document.getElementById('editPayAmount').value        = parseFloat(p.amount).toFixed(2);
            document.getElementById('editPayUTR').value           = p.utr_no    || '';
            document.getElementById('editPayBank').value          = p.bank_name || '';
            document.getElementById('editPayNotes').value         = p.notes     || '';
            var audit = 'PA-'+p.id+' · Recorded by '+(p.created_by_name||'—')+' on '+(p.created_at||'—');
            if (p.updated_at && p.updated_by_name) audit += ' · Last edited by '+p.updated_by_name+' on '+p.updated_at;
            document.getElementById('editPayAudit').textContent   = audit;
            document.getElementById('editPayId').dataset.challans  = JSON.stringify(p.challans||[]);
            document.getElementById('editPayId').dataset.agentName = p.agent_name;
            if (window.bootstrap) {
                var editModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editPayModal'));
                editModal.show();
            }
        })
        .catch(function(e){
            btnEl.disabled = false;
            btnEl.innerHTML = '<i class="bi bi-pencil me-1"></i>Edit';
            alert('Edit failed: '+e.message);
        });
}

function saveEditPayment() {
    var payId  = document.getElementById('editPayId').value;
    var btn    = document.getElementById('btnSaveEdit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    var fd = new FormData();
    fd.append('ajax_edit_payment','1');
    fd.append('pay_id',    payId);
    fd.append('paid_date', document.getElementById('editPayDate').value);
    fd.append('amount',    document.getElementById('editPayAmount').value);
    fd.append('utr_no',    document.getElementById('editPayUTR').value);
    fd.append('bank_name', document.getElementById('editPayBank').value);
    fd.append('notes',     document.getElementById('editPayNotes').value);
    fetch('agent_commissions.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(function(res){
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Changes';
            if (res.ok) { location.reload(); }
            else { alert('Error: '+(res.msg||'Unknown')); }
        })
        .catch(function(e){
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Changes';
            alert('Network error: '+e.message);
        });
}

function reprintAdvice(payId) {
    var fd = new FormData();
    fd.append('ajax_get_payment','1');
    fd.append('pay_id', payId);
    fetch('agent_commissions.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(function(res){
            if (!res.ok) { alert('Error: '+(res.msg||'')); return; }
            var p = res.payment;
            // Map challans to the shape printPaymentAdvice expects
            var rows = (p.challans||[]).map(function(c){return{
                challan_no      : c.challan_no,
                despatch_date   : c.despatch_date,
                vendor_name     : c.vendor_name,
                received_weight : c.received_weight,
                profit_per_mt   : c.profit_per_mt,
                commission_pct  : c.commission_pct,
                commission_amt  : c.commission_amt
            };});
            var total = rows.reduce(function(s,r){return s+parseFloat(r.commission_amt||0);},0);
            // Build month label from first challan date
            var monthLabel = '';
            if (rows.length && rows[0].despatch_date) {
                var d = new Date(rows[0].despatch_date);
                monthLabel = d.toLocaleString('en-IN',{month:'long',year:'numeric'});
            }
            printPaymentAdvice({
                agentName : p.agent_name,
                monthLabel: monthLabel,
                payDate   : p.paid_date,
                reference : p.reference || '',
                utr_no    : p.utr_no    || '',
                bank_name : p.bank_name || '',
                notes     : p.notes     || '',
                amount    : parseFloat(p.amount),
                totalDue  : total || parseFloat(p.amount),
                rows      : rows,
                status    : 'full',
                payId     : p.id
            });
        })
        .catch(function(e){ alert('Network error: '+e.message); });
}
</script>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPayModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a5632;color:#fff">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Payment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPayId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Agent</label>
                    <div id="editPayAgent" class="form-control bg-light fw-bold"></div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" id="editPayDate" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Amount (₹) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" id="editPayAmount" class="form-control" step="0.01">
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">UTR / Transaction No</label>
                    <input type="text" id="editPayUTR" class="form-control" placeholder="e.g. SBIN026374829374">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Bank Name</label>
                    <input type="text" id="editPayBank" class="form-control" placeholder="e.g. SBI, HDFC">
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea id="editPayNotes" class="form-control" rows="2"></textarea>
                </div>
                <!-- Audit trail -->
                <div id="editPayAudit" class="text-muted" style="font-size:0.74rem;border-top:1px solid #eee;padding-top:8px"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-secondary" onclick="reprintFromEdit()">
                    <i class="bi bi-printer me-1"></i>Reprint Advice
                </button>
                <button type="button" class="btn btn-success" id="btnSaveEdit" onclick="saveEditPayment()">
                    <i class="bi bi-check-circle me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function reprintFromEdit() {
    reprintAdvice(parseInt(document.getElementById('editPayId').value));
}
</script>
<?php include '../includes/footer.php'; ?>
