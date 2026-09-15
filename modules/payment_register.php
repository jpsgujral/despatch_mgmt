<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
requirePerm('payment_register', 'view');

$uid = (int)($_SESSION['user_id'] ?? 0);
$can_view_all = canViewAll('transporter_payments');
$payment_scope = $can_view_all ? "1=1" : "tp.created_by=$uid";
$payment_delete_scope = $can_view_all ? "1=1" : "transporter_payments.created_by=$uid";

function paymentRegisterAddColumn($db, $table, $column, $definition) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$column'
        LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
paymentRegisterAddColumn($db, 'transporter_payments', 'authorised_by', 'INT DEFAULT NULL');
paymentRegisterAddColumn($db, 'transporter_payments', 'authorised_at', 'DATETIME DEFAULT NULL');
paymentRegisterAddColumn($db, 'transporter_payments', 'payment_batch_no', 'VARCHAR(40) DEFAULT NULL');

function batchAdviceRedirectUrl(string $batchNo): string {
    return 'payment_register.php?advice_batch=' . urlencode($batchNo);
}

function paymentModesList() {
    return ['Cash', 'NEFT', 'RTGS', 'Cheque', 'UPI', 'Bank Transfer'];
}

function readPaymentAuthDetails() {
    $mode = sanitize($_POST['payment_mode'] ?? '');
    $reference = sanitize($_POST['reference_no'] ?? '');
    $bank = sanitize($_POST['bank_name'] ?? '');
    $errors = [];
    if ($mode === '' || !in_array($mode, paymentModesList(), true)) {
        $errors[] = 'Please select a valid payment mode before authorising payment.';
    }
    return [$mode, $reference, $bank, $errors];
}

function paymentRegisterBaseAmount(array $row): float {
    $base = (float)($row['base_amount'] ?? 0);
    $gst = (float)($row['gst_amount'] ?? 0);
    $tds = (float)($row['tds_amount'] ?? 0);
    if (abs($base) < 0.005 && abs($gst) < 0.005 && abs($tds) < 0.005 && isset($row['amount'])) {
        return (float)$row['amount'];
    }
    return $base;
}

function paymentRegisterNetPayable(array $row): float {
    $base = paymentRegisterBaseAmount($row);
    $tds = (float)($row['tds_amount'] ?? 0);
    $misc = (float)($row['misc_charges'] ?? 0);
    $is_rel = (($row['is_gst_release'] ?? 'No') === 'Yes') || (($row['payment_type'] ?? '') === 'GST Release') || (($row['payment_type'] ?? '') === 'GST Balance');
    if ($is_rel && abs($base) < 0.005) {
        return (float)($row['gst_amount'] ?? $row['net_payable'] ?? $row['amount'] ?? 0);
    }
    if (abs($base) < 0.005 && isset($row['net_payable']) && (float)$row['net_payable'] > 0) {
        return (float)$row['net_payable'];
    }
    return round($base - $tds + $misc, 2);
}

function paymentRegisterFreightAmount(array $row): float {
    $base = paymentRegisterBaseAmount($row);
    $gst = (float)($row['gst_amount'] ?? 0);
    $misc = (float)($row['misc_charges'] ?? 0);
    $held = ($row['gst_held'] ?? 'No') === 'Yes';
    $is_rel = (($row['is_gst_release'] ?? 'No') === 'Yes') || (($row['payment_type'] ?? '') === 'GST Release') || (($row['payment_type'] ?? '') === 'GST Balance');
    if ($is_rel && abs($base) < 0.005) {
        return (float)($row['gst_amount'] ?? $row['net_payable'] ?? $row['amount'] ?? 0);
    }
    if (abs($base) < 0.005 && abs($gst) < 0.005 && isset($row['amount'])) {
        return (float)$row['amount'];
    }
    return round($base + ($held ? 0 : $gst) + $misc, 2);
}

$status_filter = $_GET['status'] ?? 'All';
$allowed_statuses = ['All', 'Paid', 'Pending', 'Cancelled'];
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'All';
}

$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$search    = trim((string)($_GET['search'] ?? ''));

$where = ["$payment_scope"];
if ($status_filter !== 'All') {
    $status_esc = $db->real_escape_string($status_filter);
    $where[] = "tp.status='$status_esc'";
}
if ($date_from !== '') {
    $date_from_esc = $db->real_escape_string($date_from);
    $where[] = "tp.payment_date >= '$date_from_esc'";
}
if ($date_to !== '') {
    $date_to_esc = $db->real_escape_string($date_to);
    $where[] = "tp.payment_date <= '$date_to_esc'";
}
if ($search !== '') {
    $search_esc = $db->real_escape_string($search);
    $where[] = "(
        tp.payment_no LIKE '%$search_esc%' OR
        COALESCE(d.challan_no,'') LIKE '%$search_esc%' OR
        COALESCE(d.consignee_name,'') LIKE '%$search_esc%' OR
        COALESCE(t.transporter_name,'') LIKE '%$search_esc%' OR
        COALESCE(tp.payment_batch_no,'') LIKE '%$search_esc%' OR
        COALESCE(tp.reference_no,'') LIKE '%$search_esc%' OR
        COALESCE(tp.bank_name,'') LIKE '%$search_esc%'
    )";
}

$where_sql = implode(' AND ', $where);

if (isset($_GET['delete'])) {
    requirePerm('transporter_payments', 'delete');
    $del_id = (int)$_GET['delete'];
    $db->query("DELETE FROM transporter_payments WHERE id=$del_id AND $payment_delete_scope");
    showAlert($db->affected_rows > 0 ? 'success' : 'danger', $db->affected_rows > 0 ? 'Payment deleted.' : 'You cannot delete another user\'s transaction.');
    redirect('payment_register.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['authorize_payment'])) {
    if (!canAuthorizeTransporterPayments()) {
        showAlert('danger', 'You are not allowed to authorise transporter payments.');
        redirect('payment_register.php');
    }
    $pay_id = (int)($_POST['payment_id'] ?? 0);
    [$payment_mode, $reference_no, $bank_name, $auth_errors] = readPaymentAuthDetails();
    if (!empty($auth_errors)) {
        showAlert('danger', implode('<br>', $auth_errors));
        redirect('payment_register.php');
    }
    if ($pay_id > 0) {
        $auth_uid = (int)($_SESSION['user_id'] ?? 0);
        $payment_mode_sql = $db->real_escape_string($payment_mode);
        $reference_sql = $db->real_escape_string($reference_no);
        $bank_sql = $db->real_escape_string($bank_name);
        $db->query("UPDATE transporter_payments tp
            LEFT JOIN despatch_orders d ON d.id = tp.despatch_id
            SET tp.status='Paid',
                tp.payment_mode='$payment_mode_sql',
                tp.reference_no=CASE WHEN '$reference_sql'<>'' THEN '$reference_sql' ELSE tp.reference_no END,
                tp.bank_name=CASE WHEN '$bank_sql'<>'' THEN '$bank_sql' ELSE tp.bank_name END,
                tp.authorised_by=$auth_uid,
                tp.authorised_at=NOW()
            WHERE tp.id=$pay_id AND tp.status='Pending' AND $payment_scope");
        showAlert($db->affected_rows > 0 ? 'success' : 'danger',
            $db->affected_rows > 0 ? 'Payment authorised and marked as Paid.' : 'Payment could not be authorised.');
    }
    redirect('payment_register.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['authorize_batch'])) {
    if (!canAuthorizeTransporterPayments()) {
        showAlert('danger', 'You are not allowed to authorise transporter payments.');
        redirect('payment_register.php');
    }
    $batch_no = trim((string)($_POST['payment_batch_no'] ?? ''));
    [$payment_mode, $reference_no, $bank_name, $auth_errors] = readPaymentAuthDetails();
    if (!empty($auth_errors)) {
        showAlert('danger', implode('<br>', $auth_errors));
        redirect('payment_register.php');
    }
    if ($batch_no !== '') {
        $auth_uid = (int)($_SESSION['user_id'] ?? 0);
        $batch_sql = $db->real_escape_string($batch_no);
        $payment_mode_sql = $db->real_escape_string($payment_mode);
        $reference_sql = $db->real_escape_string($reference_no);
        $bank_sql = $db->real_escape_string($bank_name);
        $db->query("UPDATE transporter_payments tp
            LEFT JOIN despatch_orders d ON d.id = tp.despatch_id
            SET tp.status='Paid',
                tp.payment_mode='$payment_mode_sql',
                tp.reference_no=CASE WHEN '$reference_sql'<>'' THEN '$reference_sql' ELSE tp.reference_no END,
                tp.bank_name=CASE WHEN '$bank_sql'<>'' THEN '$bank_sql' ELSE tp.bank_name END,
                tp.authorised_by=$auth_uid,
                tp.authorised_at=NOW()
            WHERE tp.payment_batch_no='$batch_sql' AND tp.status='Pending' AND $payment_scope");
        if ($db->affected_rows > 0) {
            showAlert('success', "Batch $batch_no authorised and marked as Paid.");
            redirect(batchAdviceRedirectUrl($batch_no));
        }
        showAlert('danger', 'Batch could not be authorised.');
    }
    redirect('payment_register.php');
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_batch'])) {
    if (!canAuthorizeTransporterPayments()) {
        showAlert('danger', 'You are not allowed to reject transporter payments.');
        redirect('payment_register.php');
    }
    $batch_no = trim((string)($_POST['payment_batch_no'] ?? ''));
    $reason = trim((string)($_POST['reject_reason'] ?? 'Rejected from Payment Register'));
    if ($batch_no !== '') {
        $auth_uid = (int)($_SESSION['user_id'] ?? 0);
        $batch_sql = $db->real_escape_string($batch_no);
        $reason_sql = $db->real_escape_string($reason !== '' ? $reason : 'Rejected from Payment Register');
        $db->query("UPDATE transporter_payments tp
            LEFT JOIN despatch_orders d ON d.id = tp.despatch_id
            SET tp.status='Cancelled',
                tp.authorised_by=$auth_uid,
                tp.authorised_at=NOW(),
                tp.remarks=TRIM(CONCAT(COALESCE(tp.remarks,''), IF(COALESCE(tp.remarks,'')='', '', '\n'), 'Rejected: $reason_sql'))
            WHERE tp.payment_batch_no='$batch_sql' AND tp.status='Pending' AND $payment_scope");
        showAlert($db->affected_rows > 0 ? 'success' : 'danger',
            $db->affected_rows > 0 ? "Batch $batch_no rejected and marked as Cancelled." : 'Batch could not be rejected.');
    }
    redirect('payment_register.php');
}

$rows = $db->query("
    SELECT tp.*, t.transporter_name, t.gst_type AS tr_gst_type, t.gst_rate AS tr_gst_rate,
           d.challan_no, d.freight_inv_no, d.consignee_name, d.despatch_date, d.company_id,
           COALESCE(NULLIF(TRIM(co.company_name),''), (SELECT company_name FROM companies LIMIT 1)) AS company_name,
           au.full_name AS authorised_by_name
    FROM transporter_payments tp
    LEFT JOIN transporters t ON tp.transporter_id = t.id
    LEFT JOIN despatch_orders d ON tp.despatch_id = d.id
    LEFT JOIN companies co ON d.company_id = co.id
    LEFT JOIN app_users au ON au.id = tp.authorised_by
    WHERE $where_sql
    ORDER BY t.transporter_name ASC, tp.payment_date DESC, tp.id DESC
")->fetch_all(MYSQLI_ASSOC);

$counts = ['All' => 0, 'Paid' => 0, 'Cancelled' => 0];
$grouped = [];
$summary_net = 0.0;
$summary_count = 0;
$pending_batches = [];

foreach ($rows as $row) {
    $status = (string)($row['status'] ?? '');
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
    $counts['All']++;

    $tid = (int)($row['transporter_id'] ?? 0);
    $name = trim((string)($row['transporter_name'] ?? ''));
    if ($name === '') $name = '(Unknown)';

    if (!isset($grouped[$tid])) {
        $grouped[$tid] = [
            'name' => $name,
            'gst_type' => $row['tr_gst_type'] ?? '',
            'gst_rate' => $row['tr_gst_rate'] ?? 0,
            'rows' => [],
            'tot_base' => 0.0,
            'tot_freight' => 0.0,
            'tot_gst' => 0.0,
            'tot_tds' => 0.0,
            'tot_net' => 0.0,
            'cnt' => 0,
        ];
    }

    $grouped[$tid]['rows'][] = $row;
    $batch_no = trim((string)($row['payment_batch_no'] ?? ''));
    if ($batch_no !== '' && ($row['status'] ?? '') === 'Pending') {
        if (!isset($pending_batches[$batch_no])) {
            $pending_batches[$batch_no] = [
                'batch_no' => $batch_no,
                'transporter_name' => $name,
                'company_name' => $row['company_name'] ?? '',
                'company_names' => [],
                'payment_date' => $row['payment_date'] ?? '',
                'payment_mode' => $row['payment_mode'] ?? '',
                'reference_no' => $row['reference_no'] ?? '',
                'bank_name' => $row['bank_name'] ?? '',
                'rows' => 0,
                'tot_base' => 0.0,
                'tot_freight' => 0.0,
                'tot_gst' => 0.0,
                'tot_tds' => 0.0,
                'tot_net' => 0.0,
                'challan_rows' => [],
            ];
        }
        $c_name = trim((string)($row['company_name'] ?? ''));
        if ($c_name !== '' && !in_array($c_name, $pending_batches[$batch_no]['company_names'], true)) {
            $pending_batches[$batch_no]['company_names'][] = $c_name;
        }
        $pending_batches[$batch_no]['rows']++;
        $pending_batches[$batch_no]['tot_base'] += paymentRegisterBaseAmount($row);
        $pending_batches[$batch_no]['tot_freight'] += paymentRegisterFreightAmount($row);
        $pending_batches[$batch_no]['tot_gst'] += (float)($row['gst_amount'] ?? 0);
        $pending_batches[$batch_no]['tot_tds'] += (float)($row['tds_amount'] ?? 0);
        $pending_batches[$batch_no]['tot_net'] += paymentRegisterNetPayable($row);
        $pending_batches[$batch_no]['challan_rows'][] = $row;
    }
    if (($row['status'] ?? '') !== 'Cancelled') {
        $grouped[$tid]['tot_base'] += paymentRegisterBaseAmount($row);
        $grouped[$tid]['tot_freight'] += paymentRegisterFreightAmount($row);
        $grouped[$tid]['tot_gst'] += (float)($row['gst_amount'] ?? 0);
        $grouped[$tid]['tot_tds'] += (float)($row['tds_amount'] ?? 0);
        $grouped[$tid]['tot_net'] += paymentRegisterNetPayable($row);
        $grouped[$tid]['cnt']++;
        $summary_net += paymentRegisterNetPayable($row);
        $summary_count++;
    }
}

$total_pending_net            = array_sum(array_column($pending_batches, 'tot_net'));
$total_pending_freight        = array_sum(array_column($pending_batches, 'tot_freight'));
$total_pending_batches_amount = $total_pending_freight;
$total_pending_challans_count = array_sum(array_column($pending_batches, 'rows'));
$total_pending_gst            = array_sum(array_column($pending_batches, 'tot_gst'));
$total_pending_tds            = array_sum(array_column($pending_batches, 'tot_tds'));

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-journal-text me-2"></i>Payment Register';</script>

<?php $advice_batch = trim((string)($_GET['advice_batch'] ?? '')); ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Payment Register</h5>
    <a href="transporter_payments.php?action=list" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Transporter Payments
    </a>
</div>

<?php if ($advice_batch !== ''): ?>
<div class="card border-success shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-bold text-success">Payment advice is ready for authorised batch <?= htmlspecialchars($advice_batch) ?></div>
            <div class="small text-muted">Use this to print the final advice with payment mode, reference, and bank details.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="print_bulk_payment_advice.php?batch=<?= urlencode($advice_batch) ?>&lang=en" target="_blank" class="btn btn-success">
                <i class="bi bi-printer me-1"></i>Print Advice (EN)
            </a>
            <a href="print_bulk_payment_advice.php?batch=<?= urlencode($advice_batch) ?>&lang=hi" target="_blank" class="btn btn-outline-success">
                <i class="bi bi-printer me-1"></i>प्रिंट सूचना (HI)
            </a>
            <a href="payment_register.php" class="btn btn-outline-secondary">Close</a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-primary fs-3"><i class="bi bi-journal-check"></i></div>
            <h5 class="text-primary mb-0"><?= $counts['All'] ?></h5>
            <small class="text-muted fw-semibold">Register Entries</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-success fs-3"><i class="bi bi-check-circle"></i></div>
            <h5 class="text-success mb-0"><?= $counts['Paid'] ?></h5>
            <small class="text-muted fw-semibold">Paid</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-danger fs-3"><i class="bi bi-x-circle"></i></div>
            <h5 class="text-danger mb-0"><?= $counts['Cancelled'] ?></h5>
            <small class="text-muted fw-semibold">Cancelled</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-info fs-3"><i class="bi bi-currency-rupee"></i></div>
            <h5 class="text-info mb-0">₹<?= number_format($summary_net, 2) ?></h5>
            <small class="text-muted fw-semibold">Net Paid Total</small>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="list">
            <div class="col-12 col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach ($allowed_statuses as $st): ?>
                    <option value="<?= htmlspecialchars($st) ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($search) ?>" placeholder="Payment no, batch no, challan, consignee, transporter">
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a href="payment_register.php" class="btn btn-sm btn-outline-secondary flex-fill">Clear</a>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($pending_batches)): ?>
<div class="card mb-3 border-warning shadow-sm">
    <div class="card-header fw-bold text-dark d-flex justify-content-between align-items-center flex-wrap gap-2 py-2" style="background:#fef08a">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-collection-check me-1 fs-5" style="color:#b45309"></i>
            <span>Pending Bulk Batches for Authorisation</span>
            <span class="badge text-white" style="background:#b45309"><?= count($pending_batches) ?> batch<?= count($pending_batches) != 1 ? 'es' : '' ?></span>
            <span class="badge bg-warning text-dark border border-dark border-opacity-25"><?= number_format($total_pending_challans_count) ?> challan<?= $total_pending_challans_count != 1 ? 's' : '' ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small fw-bold text-uppercase" style="color:#92400e">Total Pending Payment:</span>
            <span class="fs-6 fw-bold px-2 py-1 rounded" style="background:#fffbeb; color:#b45309; border: 1px solid #fde047; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                ₹<?= number_format($total_pending_batches_amount, 2) ?>
            </span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Batch No</th>
                    <th>Paying Company</th>
                    <th>Transporter</th>
                    <th>Date</th>
                    <th class="text-end">Challans</th>
                    <th class="text-end">GST</th>
                    <th class="text-end">TDS</th>
                    <th class="text-end">Net Payable</th>
                    <th class="text-end">Freight Amount</th>
                    <th>Mode / Ref</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_batches as $batch):
                    $batch_key = preg_replace('/[^a-zA-Z0-9]/', '_', $batch['batch_no']);
                    $batch_detail_id = 'batchdetail_' . $batch_key;
                    $batch_authorise_id = 'batchauth_' . $batch_key;
                    $batch_company = !empty($batch['company_names']) ? implode(', ', $batch['company_names']) : (!empty($batch['company_name']) ? $batch['company_name'] : '—');
                ?>
                <tr>
                    <td>
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary me-2 px-2"
                                onclick="toggleBatchDetail('<?= $batch_detail_id ?>', this)"
                                data-open-text="+"
                                data-close-text="-"
                                title="Expand challan-wise details">+</button>
                        <strong class="text-primary"><?= htmlspecialchars($batch['batch_no']) ?></strong>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark">
                            <i class="bi bi-building text-secondary me-1"></i><?= htmlspecialchars($batch_company) ?>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($batch['transporter_name']) ?></td>
                    <td><?= !empty($batch['payment_date']) ? date('d/m/Y', strtotime($batch['payment_date'])) : '—' ?></td>
                    <td class="text-end"><?= number_format($batch['rows']) ?></td>
                    <td class="text-end text-success">₹<?= number_format($batch['tot_gst'], 2) ?></td>
                    <td class="text-end text-danger">₹<?= number_format($batch['tot_tds'], 2) ?></td>
                    <td class="text-end fw-bold text-primary">₹<?= number_format($batch['tot_net'], 2) ?></td>
                    <td class="text-end">₹<?= number_format($batch['tot_freight'], 2) ?></td>
                    <td>
                        <?= htmlspecialchars($batch['payment_mode'] ?: '—') ?>
                        <?php if (!empty($batch['reference_no'])): ?><br><small class="text-muted"><?= htmlspecialchars($batch['reference_no']) ?></small><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;min-width:120px">
                        <div class="d-flex flex-column gap-1">
                            <?php if (canAuthorizeTransporterPayments()): ?>
                            <button type="button" class="btn btn-sm btn-success py-1 w-100"
                                     onclick="toggleBatchAuthorise('<?= $batch_authorise_id ?>', this)"
                                     data-open-text="Authorise"
                                     data-close-text="Hide Authorise">
                                <i class="bi bi-shield-check me-1"></i>Authorise
                            </button>
                            <form method="POST" onsubmit="return setRejectReason(this, '<?= htmlspecialchars($batch['batch_no'], ENT_QUOTES) ?>')">
                                <input type="hidden" name="reject_batch" value="1">
                                <input type="hidden" name="payment_batch_no" value="<?= htmlspecialchars($batch['batch_no']) ?>">
                                <input type="hidden" name="reject_reason" value="">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 w-100">
                                    <i class="bi bi-x-circle me-1"></i>Reject
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Pending Authorisation</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <tr id="<?= $batch_detail_id ?>" style="display:none">
                    <td colspan="11" class="p-0 border-top-0">
                        <div class="bg-light border-start border-4 border-info px-3 py-2">
                            <div class="fw-semibold text-info mb-2"><i class="bi bi-list-ul me-1"></i>Challan Details — <?= htmlspecialchars($batch['batch_no']) ?></div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-hover mb-0 align-middle" style="background:#fff">
                                    <thead class="table-info">
                                        <tr>
                                            <th>#</th>
                                            <th>Payment No</th>
                                            <th>Challan</th>
                                            <th>Despatch Date</th>
                                            <th>Consignee</th>
                                            <th>Type</th>
                                            <th class="text-end">GST</th>
                                            <th class="text-end">TDS</th>
                                            <th class="text-end">Net Payable</th>
                                            <th class="text-end">Freight Amount</th>
                                            <th>Mode</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($batch['challan_rows'] as $ci => $cr):
                                        $cr_held = ($cr['gst_held'] ?? 'No') === 'Yes';
                                        $cr_rel  = ($cr['is_gst_release'] ?? 'No') === 'Yes';
                                    ?>
                                        <tr>
                                            <td><?= $ci + 1 ?></td>
                                            <td><span class="fw-semibold text-primary"><?= htmlspecialchars($cr['payment_no']) ?></span></td>
                                            <td>
                                                <?php if (!empty($cr['challan_no'])): ?>
                                                    <strong><?= htmlspecialchars($cr['challan_no']) ?></strong>
                                                    <?php if (!empty($cr['freight_inv_no'])): ?><br><span class="fi-badge">FI: <?= htmlspecialchars($cr['freight_inv_no']) ?></span><?php endif; ?>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td style="white-space:nowrap"><?= !empty($cr['despatch_date']) ? date('d/m/Y', strtotime($cr['despatch_date'])) : '—' ?></td>
                                            <td>
                                                <?= htmlspecialchars($cr['consignee_name'] ?: '—') ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($cr['payment_type'] ?? '') ?></span>
                                                <?php if ($cr_rel): ?><span class="badge bg-success ms-1">GST Release</span><?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ((float)($cr['gst_amount'] ?? 0) > 0): ?>
                                                    <span class="<?= $cr_held ? 'text-warning' : 'text-success' ?>">
                                                        ₹<?= number_format((float)$cr['gst_amount'], 2) ?>
                                                        <?php if ($cr_held): ?><br><small class="badge bg-warning text-dark">GST on Hold</small><?php endif; ?>
                                                    </span>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ((float)($cr['tds_amount'] ?? 0) > 0): ?>
                                                    <span class="text-danger">₹<?= number_format((float)$cr['tds_amount'], 2) ?></span>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold">₹<?= number_format(paymentRegisterNetPayable($cr), 2) ?></td>
                                            <td class="text-end">₹<?= number_format(paymentRegisterFreightAmount($cr), 2) ?></td>
                                            <td><?= htmlspecialchars($cr['payment_mode'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-semibold">
                                        <tr>
                                            <td colspan="6" class="text-end">Totals:</td>
                                            <td class="text-end text-success">₹<?= number_format($batch['tot_gst'], 2) ?></td>
                                            <td class="text-end text-danger">₹<?= number_format($batch['tot_tds'], 2) ?></td>
                                            <td class="text-end text-primary">₹<?= number_format($batch['tot_net'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($batch['tot_freight'], 2) ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php if (canAuthorizeTransporterPayments()): ?>
                <tr id="<?= $batch_authorise_id ?>" style="display:none">
                    <td colspan="11" class="bg-success-subtle border-top-0">
                        <form method="POST" class="row g-2 align-items-end p-2" onsubmit="return confirm('Authorise all pending rows in batch <?= htmlspecialchars($batch['batch_no']) ?> and mark them as Paid?')">
                            <input type="hidden" name="authorize_batch" value="1">
                            <input type="hidden" name="payment_batch_no" value="<?= htmlspecialchars($batch['batch_no']) ?>">
                            <div class="col-12 col-md-3">
                                <label class="form-label form-label-sm mb-1">Payment Mode *</label>
                                <select name="payment_mode" class="form-select form-select-sm" required>
                                    <option value="">Select</option>
                                    <?php foreach (paymentModesList() as $mode): ?>
                                    <option value="<?= htmlspecialchars($mode) ?>" <?= ($batch['payment_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label form-label-sm mb-1">Reference / UTR</label>
                                <input type="text" name="reference_no" class="form-control form-control-sm" value="<?= htmlspecialchars($batch['reference_no'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label form-label-sm mb-1">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control form-control-sm" value="<?= htmlspecialchars($batch['bank_name'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-md-3 d-grid">
                                <button type="submit" class="btn btn-sm btn-success">
                                    <i class="bi bi-shield-check me-1"></i>Confirm Authorise
                                </button>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold border-top border-2">
                <tr>
                    <td colspan="4" class="text-end">Total (<?= count($pending_batches) ?> Batch<?= count($pending_batches) != 1 ? 'es' : '' ?>):</td>
                    <td class="text-end"><?= number_format($total_pending_challans_count) ?></td>
                    <td class="text-end text-success">₹<?= number_format($total_pending_gst, 2) ?></td>
                    <td class="text-end text-danger">₹<?= number_format($total_pending_tds, 2) ?></td>
                    <td class="text-end fw-bold text-primary fs-6">₹<?= number_format($total_pending_net, 2) ?></td>
                    <td class="text-end">₹<?= number_format($total_pending_freight, 2) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="mb-2 d-flex justify-content-between align-items-center">
    <h6 class="fw-bold mb-0"><i class="bi bi-journal-text me-2"></i>Payment Register — By Transporter</h6>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllRegisterGroups(true)">
            <i class="bi bi-chevron-expand me-1"></i>Expand All
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllRegisterGroups(false)">
            <i class="bi bi-chevron-contract me-1"></i>Collapse All
        </button>
    </div>
</div>

<?php if (empty($grouped)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-inbox fs-2 d-block mb-2"></i>No payment register records found.
    </div>
</div>
<?php else: ?>
<?php foreach ($grouped as $tid => $grp):
    $gst_lbl = $grp['gst_type'] === 'Central' ? 'IGST' : ($grp['gst_type'] === 'Regular' ? 'CGST+SGST' : ($grp['gst_type'] === 'RCM' ? 'RCM' : ''));
    $acc_id = 'payreg_' . $tid;
?>
<div class="card mb-2 shadow-sm">
    <div class="card-header p-0">
        <button class="btn w-100 text-start d-flex align-items-center justify-content-between gap-2 px-3 py-2"
                style="background:linear-gradient(90deg,#14532d,#16a34a);border:none;border-radius:inherit"
                onclick="toggleRegisterGroup('<?= $acc_id ?>')">
            <span class="d-flex align-items-center gap-2 flex-wrap">
                <i class="bi bi-truck text-white fs-5"></i>
                <strong class="text-white fs-6"><?= htmlspecialchars($grp['name']) ?></strong>
                <?php if ($gst_lbl): ?>
                <span class="badge bg-light text-dark"><?= htmlspecialchars($gst_lbl) ?><?= $grp['gst_rate'] > 0 ? ' ' . htmlspecialchars((string)$grp['gst_rate']) . '%' : '' ?></span>
                <?php endif; ?>
                <span class="badge bg-white text-success"><?= count($grp['rows']) ?> record<?= count($grp['rows']) !== 1 ? 's' : '' ?></span>
            </span>
            <span class="d-flex gap-2 align-items-center flex-wrap">
                <span class="badge bg-light text-dark">Freight ₹<?= number_format($grp['tot_freight'], 2) ?></span>
                <span class="badge bg-success">Net ₹<?= number_format($grp['tot_net'], 2) ?></span>
                <i class="bi bi-chevron-right text-white" id="<?= $acc_id ?>_icon"></i>
            </span>
        </button>
    </div>
    <div id="<?= $acc_id ?>" style="display:none">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle payreg-sticky-table">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Payment No</th>
                        <th>Date</th>
                        <th class="payreg-sticky-col">Challan</th>
                        <th>Type</th>
                        <th class="text-end">Freight Amount</th>
                        <th class="text-end">GST</th>
                        <th class="text-end">TDS</th>
                        <th class="text-end">Net Paid</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $row_i = 1; foreach ($grp['rows'] as $v):
                    $badge = ['Paid' => 'success', 'Cancelled' => 'danger'][$v['status']] ?? 'secondary';
                    $held = ($v['gst_held'] ?? 'No') === 'Yes';
                    $rel = ($v['is_gst_release'] ?? 'No') === 'Yes';
                    $canc = ($v['status'] ?? '') === 'Cancelled';
                    $row_auth_id = 'payauth_' . (int)$v['id'];
                ?>
                    <tr<?= $canc ? ' class="text-muted opacity-75"' : '' ?>>
                        <td><?= $row_i++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($v['payment_no']) ?></strong>
                            <?php if (!empty($v['payment_batch_no'])): ?><br><span class="badge bg-info text-dark"><?= htmlspecialchars($v['payment_batch_no']) ?></span><?php endif; ?>
                            <?php if ($rel): ?><br><span class="badge bg-success">GST Release</span><?php endif; ?>
                        </td>
                        <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($v['payment_date'])) ?></td>
                        <td class="payreg-sticky-col">
                            <?php if (!empty($v['challan_no'])): ?>
                                <strong class="text-primary"><?= htmlspecialchars($v['challan_no']) ?></strong>
                                <?php if (!empty($v['freight_inv_no'])): ?><br><span class="fi-badge">FI: <?= htmlspecialchars($v['freight_inv_no']) ?></span><?php endif; ?>
                                <?php if (!empty($v['consignee_name'])): ?><br><small class="text-muted"><?= htmlspecialchars($v['consignee_name']) ?></small><?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($v['payment_type']) ?></span></td>
                        <td class="text-end">₹<?= number_format(paymentRegisterFreightAmount($v), 2) ?></td>
                        <td class="text-end">
                            <?php if ((float)$v['gst_amount'] > 0): ?>
                                <span class="<?= $held ? 'text-warning' : 'text-success' ?>">
                                    +₹<?= number_format($v['gst_amount'], 2) ?>
                                    <?php if (!empty($v['gst_type'])): ?><br><small><?= htmlspecialchars($v['gst_type'] === 'Central' ? 'IGST' : ($v['gst_type'] === 'Regular' ? 'CGST+SGST' : 'RCM')) ?> <?= htmlspecialchars((string)$v['gst_rate']) ?>%</small><?php endif; ?>
                                    <?php if ($held): ?><br><span class="badge bg-warning text-dark">GST on Hold</span><?php elseif ($rel): ?><br><span class="badge bg-success">Released</span><?php endif; ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ((float)$v['tds_amount'] > 0): ?>
                                <span class="text-danger">-₹<?= number_format($v['tds_amount'], 2) ?><br><small>TDS <?= htmlspecialchars((string)$v['tds_rate']) ?>%</small></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-bold">₹<?= number_format(paymentRegisterNetPayable($v), 2) ?></td>
                        <td><?= htmlspecialchars($v['payment_mode'] ?? '') ?></td>
                        <td>
                            <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($v['status']) ?></span>
                            <?php if (!empty($v['authorised_at'])): ?>
                                <br><small class="text-muted">Authorised by <?= htmlspecialchars($v['authorised_by_name'] ?: 'User') ?></small>
                                <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($v['authorised_at'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if (($v['status'] ?? '') === 'Pending' && canAuthorizeTransporterPayments()): ?>
                            <button type="button" class="btn btn-action btn-outline-success me-1"
                                    title="Authorise Payment"
                                    onclick="toggleBatchAuthorise('<?= $row_auth_id ?>', this)"
                                    data-open-text=""
                                    data-close-text="">
                                <i class="bi bi-shield-check"></i>
                            </button>
                            <?php if (!empty($v['payment_batch_no'])): ?>
                            <button type="button" class="btn btn-action btn-outline-success me-1"
                                    title="Authorise Batch"
                                    onclick="toggleBatchAuthorise('batchauth_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $v['payment_batch_no']) ?>', this)"
                                    data-open-text=""
                                    data-close-text="">
                                <i class="bi bi-collection-check"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return setRejectReason(this, '<?= htmlspecialchars($v['payment_batch_no'], ENT_QUOTES) ?>')">
                                <input type="hidden" name="reject_batch" value="1">
                                <input type="hidden" name="payment_batch_no" value="<?= htmlspecialchars($v['payment_batch_no']) ?>">
                                <input type="hidden" name="reject_reason" value="">
                                <button type="submit" class="btn btn-action btn-outline-danger me-1" title="Reject Batch"><i class="bi bi-x-circle"></i></button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($v['payment_batch_no']) && ($v['status'] ?? '') === 'Paid'): ?>
                            <a href="print_bulk_payment_advice.php?batch=<?= urlencode($v['payment_batch_no']) ?>&lang=en" target="_blank"
                               class="btn btn-action btn-outline-dark me-1" title="Print Advice (English)">
                                <i class="bi bi-printer"></i>
                            </a>
                            <a href="print_bulk_payment_advice.php?batch=<?= urlencode($v['payment_batch_no']) ?>&lang=hi" target="_blank"
                               class="btn btn-action btn-outline-success me-1" title="प्रिंट सूचना (हिंदी)">
                                <i class="bi bi-printer me-1"></i><small class="fw-bold">हिं</small>
                            </a>
                            <?php endif; ?>
                            <?php if (canDo('transporter_payments', 'update')): ?>
                            <a href="transporter_payments.php?action=edit&id=<?= (int)$v['id'] ?>" class="btn btn-action btn-outline-primary me-1" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (canDo('transporter_payments', 'delete')): ?>
                            <button onclick="confirmDelete(<?= (int)$v['id'] ?>,'payment_register.php')" class="btn btn-action btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (($v['status'] ?? '') === 'Pending' && canAuthorizeTransporterPayments()): ?>
                    <tr id="<?= $row_auth_id ?>" style="display:none">
                        <td colspan="12" class="bg-success-subtle border-top-0">
                            <form method="POST" class="row g-2 align-items-end p-2" onsubmit="return confirm('Authorise this pending payment and mark it as Paid?')">
                                <input type="hidden" name="authorize_payment" value="1">
                                <input type="hidden" name="payment_id" value="<?= (int)$v['id'] ?>">
                                <div class="col-12 col-md-3">
                                    <label class="form-label form-label-sm mb-1">Payment Mode *</label>
                                    <select name="payment_mode" class="form-select form-select-sm" required>
                                        <option value="">Select</option>
                                        <?php foreach (paymentModesList() as $mode): ?>
                                        <option value="<?= htmlspecialchars($mode) ?>" <?= ($v['payment_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label form-label-sm mb-1">Reference / UTR</label>
                                    <input type="text" name="reference_no" class="form-control form-control-sm" value="<?= htmlspecialchars($v['reference_no'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label form-label-sm mb-1">Bank Name</label>
                                    <input type="text" name="bank_name" class="form-control form-control-sm" value="<?= htmlspecialchars($v['bank_name'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-md-3 d-grid">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-shield-check me-1"></i>Confirm Authorise
                                    </button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-primary fw-semibold">
                    <tr>
                        <td colspan="5" class="text-end">Totals (excl. cancelled):</td>
                        <td class="text-end">₹<?= number_format($grp['tot_freight'], 2) ?></td>
                        <td class="text-end text-success">₹<?= number_format($grp['tot_gst'], 2) ?></td>
                        <td class="text-end text-danger">-₹<?= number_format($grp['tot_tds'], 2) ?></td>
                        <td class="text-end text-primary">₹<?= number_format($grp['tot_net'], 2) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<style>
.payreg-sticky-table .payreg-sticky-col {
    position: sticky;
    left: 0;
    z-index: 2;
    background: #fff;
    box-shadow: 2px 0 4px rgba(0,0,0,.08);
    min-width: 90px;
}
.payreg-sticky-table thead .payreg-sticky-col {
    background: #f8f9fa;
    z-index: 3;
}
.fi-badge {
    display: inline-block;
    margin-top: 4px;
    padding: 2px 7px;
    border-radius: 999px;
    background: #fff3cd;
    color: #a16207;
    border: 1px solid #facc15;
    font-weight: 700;
    font-size: .72rem;
    letter-spacing: .2px;
}
</style>

<script>
function toggleRegisterGroup(id) {
    var body = document.getElementById(id);
    var icon = document.getElementById(id + '_icon');
    if (!body) return;
    var open = body.style.display !== 'none';
    document.querySelectorAll('[id^="payreg_"]:not([id$="_icon"])').forEach(function(el) {
        el.style.display = 'none';
        var ic = document.getElementById(el.id + '_icon');
        if (ic) ic.className = 'bi bi-chevron-right text-white';
    });
    if (!open) {
        body.style.display = 'block';
        if (icon) icon.className = 'bi bi-chevron-down text-white';
    }
}

function toggleAllRegisterGroups(expand) {
    document.querySelectorAll('[id^="payreg_"]:not([id$="_icon"])').forEach(function(el) {
        el.style.display = expand ? 'block' : 'none';
    });
    document.querySelectorAll('[id^="payreg_"][id$="_icon"]').forEach(function(ic) {
        ic.className = expand ? 'bi bi-chevron-down text-white' : 'bi bi-chevron-right text-white';
    });
}

function setRejectReason(form, batchNo) {
    if (!confirm('Reject batch ' + batchNo + ' and mark all pending rows as Cancelled?')) return false;
    var reason = prompt('Reason for rejecting this batch:', 'Test entry rejected');
    if (reason === null) return false;
    var field = form.querySelector('input[name="reject_reason"]');
    if (field) field.value = reason.trim() || 'Rejected from Payment Register';
    return true;
}

function toggleBatchDetail(id, btn) {
    var row = document.getElementById(id);
    if (!row) return;
    var visible = row.style.display !== 'none';
    row.style.display = visible ? 'none' : 'table-row';
    if (btn) {
        var openText = btn.getAttribute('data-open-text') || '<i class="bi bi-eye me-1"></i>View Challans';
        var closeText = btn.getAttribute('data-close-text') || '<i class="bi bi-eye-slash me-1"></i>Hide Challans';
        btn.innerHTML = visible ? openText : closeText;
        if (!btn.hasAttribute('data-open-text')) {
            btn.classList.toggle('btn-outline-secondary', visible);
            btn.classList.toggle('btn-secondary', !visible);
        }
    }
}

function toggleBatchAuthorise(id, btn) {
    var row = document.getElementById(id);
    if (!row) return;
    var visible = row.style.display !== 'none';
    row.style.display = visible ? 'none' : 'table-row';
    if (btn) {
        var openText = btn.getAttribute('data-open-text') || 'Authorise';
        var closeText = btn.getAttribute('data-close-text') || 'Hide Authorise';
        if (openText || closeText) {
            btn.innerHTML = visible
                ? '<i class="bi bi-shield-check me-1"></i>' + openText
                : '<i class="bi bi-eye-slash me-1"></i>' + closeText;
        }
    }
}
</script>

<?php include '../includes/footer.php'; ?>
