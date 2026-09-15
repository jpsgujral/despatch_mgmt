<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
/* ── Page-level view permission check ── */
// Allow users with transporter_payments view, OR bulk-payment/authorise rights
if (!canDo('transporter_payments', 'view') && !canAuthorizeTransporterPayments()) {
    requirePerm('transporter_payments', 'view'); // will redirect with access denied
}

// ── Agent filter ────────────────────────────────────────────────────
$__uid     = (int)($_SESSION['user_id'] ?? 0);
$__arow    = $db->query("SELECT is_agent, view_all_despatch FROM app_users WHERE id=$__uid LIMIT 1")->fetch_assoc();
$is_agent  = !isAdmin() && !empty($__arow['is_agent']) && empty($__arow['view_all_despatch']);
$agent_uid = $__uid;
// ────────────────────────────────────────────────────────────────────
$tp_af = $is_agent ? " AND (d.created_by=$agent_uid OR d.agent_id=$agent_uid)" : "";

// Override old agent-only visibility logic: use Transaction View Access from User Management.
$tp_can_view_all = canViewAll('transporter_payments');
// Authorisers (including bulk payment recorders) must see all payments to authorise them
if (!$tp_can_view_all && canAuthorizeTransporterPayments()) {
    $tp_can_view_all = true;
}
$tp_af = $tp_can_view_all ? "" : " AND (d.created_by=$__uid OR d.agent_id=$__uid)";
$tp_payment_scope = $tp_can_view_all ? "1=1" : "tp.created_by=$__uid";
$tp_payment_delete_scope = $tp_can_view_all ? "1=1" : "transporter_payments.created_by=$__uid";
$tp_vendor_name_expr = "COALESCE(NULLIF(v.vendor_name,''), d.consignee_name)";

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Safe ALTER ── */
function safeAddColumn($db, $table, $column, $definition) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$column'
        LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
safeAddColumn($db, 'transporter_payments', 'base_amount',    'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'gst_type',       "VARCHAR(20) DEFAULT ''");
safeAddColumn($db, 'transporter_payments', 'gst_rate',       'DECIMAL(5,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'gst_amount',     'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'gst_held',       "ENUM('No','Yes') DEFAULT 'No'");
safeAddColumn($db, 'transporter_payments', 'tds_rate',       'DECIMAL(5,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'tds_amount',     'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'net_payable',    'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'misc_charges',   'DECIMAL(10,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'is_gst_release', "ENUM('No','Yes') DEFAULT 'No'");
safeAddColumn($db, 'transporter_payments', 'created_by',     'INT DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'created_at',     'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
safeAddColumn($db, 'despatch_orders', 'transporter_due_hold', "TINYINT(1) DEFAULT 0");
safeAddColumn($db, 'despatch_orders', 'transporter_due_hold_reason', "TEXT DEFAULT NULL");
safeAddColumn($db, 'despatch_orders', 'transporter_misc_charges', "DECIMAL(10,2) DEFAULT 0");
safeAddColumn($db, 'despatch_orders', 'transporter_misc_remarks', "VARCHAR(255) DEFAULT ''");

/* ── Ensure payment_type is VARCHAR (not ENUM) so 'GST Release' is accepted ── */
(function() use ($db) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $col = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='transporter_payments'
        AND COLUMN_NAME='payment_type' LIMIT 1")->fetch_row();
    if ($col && strtolower($col[0]) === 'enum') {
        $db->query("ALTER TABLE transporter_payments
            MODIFY COLUMN payment_type VARCHAR(50) DEFAULT ''");
    }
})();

(function() use ($db) {
    $col = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transporter_payments'
        AND COLUMN_NAME='payment_mode' LIMIT 1")->fetch_row();
    if ($col && strtolower($col[0]) === 'enum') {
        $db->query("ALTER TABLE transporter_payments
            MODIFY COLUMN payment_mode VARCHAR(40) DEFAULT ''");
    }
})();

function generatePaymentNo($db) {
    $year = date('Y'); $month = date('m');
    $c = $db->query("SELECT COUNT(*) c FROM transporter_payments
                     WHERE YEAR(payment_date)=$year AND MONTH(payment_date)=$month")
            ->fetch_assoc()['c'] + 1;
    return "TP/{$year}/{$month}/" . str_pad($c, 4, '0', STR_PAD_LEFT);
}

/* ── DELETE ── */
if (isset($_GET['delete'])) {
    requirePerm('transporter_payments', 'delete');
    $del_id = (int)$_GET['delete'];
    $db->query("DELETE FROM transporter_payments WHERE id=$del_id AND $tp_payment_delete_scope");
    showAlert($db->affected_rows > 0 ? 'success' : 'danger', $db->affected_rows > 0 ? 'Payment deleted.' : 'You cannot delete another user\'s transaction.');
    redirect('transporter_payments.php');
}

/* ── ADMIN AJAX: hold/release outstanding due ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_due_hold'])) {
    header('Content-Type: application/json');
    if (!isAdmin()) {
        echo json_encode(['ok' => false, 'msg' => 'Only admin can manage hold status.']);
        exit;
    }
    $despatch_id = (int)($_POST['despatch_id'] ?? 0);
    $hold        = (int)($_POST['hold'] ?? 0) ? 1 : 0;
    $reason      = trim(sanitize($_POST['reason'] ?? ''));
    if ($despatch_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid challan.']);
        exit;
    }
    if ($hold && $reason === '') {
        echo json_encode(['ok' => false, 'msg' => 'Hold reason is required.']);
        exit;
    }
    $reason_sql = $hold ? ("'" . $db->real_escape_string($reason) . "'") : "NULL";
    $db->query("UPDATE despatch_orders
        SET transporter_due_hold=$hold,
            transporter_due_hold_reason=$reason_sql
        WHERE id=$despatch_id");
    echo json_encode(['ok' => true]);
    exit;
}

/* ── SAVE / UPDATE ── */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Allow users with authorize right to update (authorise) pending payments
    if ($id > 0) {
        if (!canDo('transporter_payments', 'update') && !canAuthorizeTransporterPayments()) {
            requirePerm('transporter_payments', 'update'); // will redirect with access denied
        }
    } else {
        requirePerm('transporter_payments', 'create');
    }
    $payment_no      = sanitize($_POST['payment_no']      ?? generatePaymentNo($db));
    $payment_date    = sanitize($_POST['payment_date']    ?? date('Y-m-d'));
    $transporter_id  = (int)($_POST['transporter_id']     ?? 0);
    $despatch_id_raw = (int)($_POST['despatch_id']        ?? 0);
    $despatch_val    = $despatch_id_raw > 0 ? $despatch_id_raw : 'NULL';
    $payment_type    = sanitize($_POST['payment_type']    ?? '');
    $payment_mode    = sanitize($_POST['payment_mode']    ?? '');
    $reference_no    = sanitize($_POST['reference_no']    ?? '');
    $bank_name       = sanitize($_POST['bank_name']       ?? '');
    $remarks         = sanitize($_POST['remarks']         ?? '');
    $status          = sanitize($_POST['status']          ?? 'Pending');
    $is_gst_release  = sanitize($_POST['is_gst_release']  ?? 'No');

    // All tax figures come from hidden computed fields (read-only display, server recomputes)
    $gst_type  = sanitize($_POST['gst_type']  ?? '');
    $gst_rate  = (float)($_POST['gst_rate']   ?? 0);
    $gst_held  = sanitize($_POST['gst_held']  ?? 'No');
    $tds_rate  = (float)($_POST['tds_rate']   ?? 0);

    // Amount being paid this transaction (user-entered)
    $amount_this_payment = (float)($_POST['amount_this_payment'] ?? 0);

    $errors = [];
    if ($transporter_id < 1)      $errors[] = 'Transporter is required.';
    if ($despatch_id_raw < 1)     $errors[] = 'Despatch / Challan must be selected.';
    if ($amount_this_payment <= 0) $errors[] = 'Amount being paid must be greater than 0.';

    if ($despatch_id_raw > 0 && empty($errors)) {
        $excl = $id > 0 ? "AND tp.id != $id" : '';
        $bal = $db->query("
            SELECT d.freight_amount,
                   t.gst_type, t.gst_rate, t.tds_applicable, t.tds_rate,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' $excl THEN tp.base_amount ELSE 0 END),0) paid_base,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' $excl THEN tp.gst_amount  ELSE 0 END),0) paid_gst,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.gst_held='Yes' $excl THEN tp.gst_amount ELSE 0 END),0) gst_on_hold,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.is_gst_release='Yes' $excl THEN tp.gst_amount ELSE 0 END),0) gst_released
            FROM despatch_orders d
            LEFT JOIN transporters t ON d.transporter_id=t.id
            LEFT JOIN transporter_payments tp ON tp.despatch_id=d.id
            WHERE d.id=$despatch_id_raw $tp_af GROUP BY d.id
        ")->fetch_assoc();

        if ($bal) {
            $freight      = (float)$bal['freight_amount'];
            $t_gst_type   = $bal['gst_type']   ?? $gst_type;
            $t_gst_rate   = (float)($bal['gst_rate']   ?? $gst_rate);
            $t_tds_rate   = (float)($bal['tds_rate']   ?? $tds_rate);
            $t_tds_ok     = ($bal['tds_applicable'] ?? 'No') === 'Yes';
            $paid_base    = (float)$bal['paid_base'];
            $gst_on_hold  = (float)$bal['gst_on_hold'];

            // Total GST on full freight
            $full_gst = ($t_gst_type !== 'RCM') ? round($freight * $t_gst_rate / 100, 2) : 0;
            $full_tds = $t_tds_ok ? round($freight * $t_tds_rate / 100, 2) : 0;
            $rem_base = round($freight - $paid_base, 2);

            $gst_released_amt = (float)$bal['gst_released'];
            $net_gst_hold = max(0, round($gst_on_hold - $gst_released_amt, 2));
            if ($is_gst_release === 'Yes') {
                // GST release: paying only net remaining held GST
                if ($net_gst_hold < 0.01) $errors[] = 'No GST is currently on hold for this challan (already released).';
                elseif ($amount_this_payment > $net_gst_hold + 0.005)
                    $errors[] = 'GST release amount ₹'.number_format($amount_this_payment,2).' exceeds remaining held GST ₹'.number_format($net_gst_hold,2).'.';
                // For GST release: base=0, gst_amount = amount_this_payment, tds=0
                $base_amount = 0;
                $gst_amount  = $amount_this_payment; // actual amount being released
                $tds_amount  = 0;
                $net_payable = $amount_this_payment;
                $gst_held    = 'No'; // releasing, so not held
                $gst_type    = $t_gst_type;
                $gst_rate    = $t_gst_rate;
            } else {
                // Normal payment: paying freight base (+ GST optionally, - TDS)
                if ($rem_base < 0.01) $errors[] = 'Freight base is already fully paid for this challan.';
                elseif ($amount_this_payment > $rem_base + 0.005)
                    $errors[] = 'Amount ₹'.number_format($amount_this_payment,2).' exceeds remaining freight ₹'.number_format($rem_base,2).'.';

                $base_amount = $amount_this_payment;
                $tds_amount  = $t_tds_ok ? round($base_amount * $t_tds_rate / 100, 2) : 0;
                $gst_type    = $t_gst_type;
                $gst_rate    = $t_gst_rate;
                $tds_rate    = $t_tds_rate;

                if ($gst_held === 'Yes') {
                    $gst_amount  = ($t_gst_type !== 'RCM') ? round($base_amount * $t_gst_rate / 100, 2) : 0;
                    $net_payable = round($base_amount - $tds_amount, 2);
                } elseif (in_array($payment_type, ['Partial', 'Advance', 'Partial Settlement', 'Against LR'], true)) {
                    // On-account partial freight payment: no GST withheld, GST remains due on balance
                    $gst_amount  = 0;
                    $net_payable = round($base_amount - $tds_amount, 2);
                } else {
                    // Full settlement: include GST
                    $gst_amount  = ($t_gst_type !== 'RCM') ? round($base_amount * $t_gst_rate / 100, 2) : 0;
                    $net_payable = round($base_amount + $gst_amount - $tds_amount, 2);
                }
            }
            $amount = $net_payable;
        } else {
            $errors[] = 'Despatch record not found.';
        }
    } else {
        $base_amount = $gst_amount = $tds_amount = $net_payable = $amount = 0;
    }

    if (!empty($errors)) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        $esc = fn($v) => $db->real_escape_string($v);
        if ($id > 0) {
            $db->query("UPDATE transporter_payments SET
                payment_no='{$esc($payment_no)}', payment_date='{$esc($payment_date)}',
                transporter_id=$transporter_id, despatch_id=$despatch_val,
                payment_type='{$esc($payment_type)}', amount=$amount,
                base_amount=$base_amount, gst_type='{$esc($gst_type)}',
                gst_rate=$gst_rate, gst_amount=$gst_amount, gst_held='{$esc($gst_held)}',
                tds_rate=$tds_rate, tds_amount=$tds_amount, net_payable=$net_payable,
                is_gst_release='{$esc($is_gst_release)}',
                payment_mode='{$esc($payment_mode)}', reference_no='{$esc($reference_no)}',
                bank_name='{$esc($bank_name)}', remarks='{$esc($remarks)}', status='{$esc($status)}'
                WHERE id=$id AND $tp_payment_delete_scope");
            showAlert('success', 'Payment updated.');
        } else {
            $created_by = (int)($_SESSION['user_id'] ?? 0);
            $db->query("INSERT INTO transporter_payments
                (payment_no,payment_date,transporter_id,despatch_id,payment_type,
                 amount,base_amount,gst_type,gst_rate,gst_amount,gst_held,is_gst_release,
                 tds_rate,tds_amount,net_payable,payment_mode,reference_no,bank_name,remarks,status,created_by)
                VALUES
                ('{$esc($payment_no)}','{$esc($payment_date)}',$transporter_id,$despatch_val,
                 '{$esc($payment_type)}',$amount,$base_amount,'{$esc($gst_type)}',
                 $gst_rate,$gst_amount,'{$esc($gst_held)}','{$esc($is_gst_release)}',
                 $tds_rate,$tds_amount,$net_payable,
                 '{$esc($payment_mode)}','{$esc($reference_no)}',
                 '{$esc($bank_name)}','{$esc($remarks)}','{$esc($status)}',$created_by)");
            showAlert('success', 'Payment recorded.');
        }
        redirect('transporter_payments.php');
    }
}

/* ── Edit fetch ── */
$payment = [];
if ($action == 'edit' && $id > 0) {
    $payment = $db->query("
        SELECT tp.*
        FROM transporter_payments tp
        LEFT JOIN despatch_orders d ON d.id=tp.despatch_id
        WHERE tp.id=$id AND $tp_payment_scope
        LIMIT 1
    ")->fetch_assoc();
    if (!$payment) {
        showAlert('danger', 'You do not have permission to view this payment.');
        redirect('transporter_payments.php');
    }
}

/* ── Auto-correct legacy partial payments where gst_held was mistakenly set to Yes ── */
$db->query("UPDATE transporter_payments
    SET gst_held = 'No', gst_amount = 0.00
    WHERE payment_type IN ('Partial', 'Advance', 'Partial Settlement', 'Against LR')
      AND gst_held = 'Yes'
      AND (is_gst_release IS NULL OR is_gst_release != 'Yes')
      AND amount = base_amount");

/* ── Transporters ── */
$transporters = $db->query("
    SELECT id, transporter_name, gst_type, gst_rate, tds_applicable, tds_rate
    FROM transporters WHERE status='Active' ORDER BY transporter_name
")->fetch_all(MYSQLI_ASSOC);

/* ── Outstanding due rows for due table ── */
$due_rows = $db->query("
    SELECT d.id, d.challan_no, d.despatch_no, d.despatch_date,
        d.freight_inv_no,
        COALESCE(d.total_weight, 0) AS total_weight,
        d.transporter_rate_per_mt,
        {$tp_vendor_name_expr} AS vendor_name, d.consignee_city, d.status AS despatch_status,
        d.transporter_due_hold, d.transporter_due_hold_reason,
        d.lr_number, d.freight_amount, d.freight_paid_by,
        d.agent_id, au.full_name AS agent_name,
        t.transporter_name, t.id AS transporter_id,
        t.gst_type, t.gst_rate, t.tds_applicable, t.tds_rate,
        COALESCE((SELECT tr.rate FROM transporter_rates tr
                  WHERE tr.transporter_id=d.transporter_id AND tr.vendor_id=d.vendor_id
                  AND tr.status='Active' LIMIT 1), 0) AS rate_card_rate,
        COALESCE((SELECT tr.uom FROM transporter_rates tr
                  WHERE tr.transporter_id=d.transporter_id AND tr.vendor_id=d.vendor_id
                  AND tr.status='Active' LIMIT 1), '') AS rate_card_uom,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.base_amount ELSE 0 END),0) AS paid_base,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.gst_amount  ELSE 0 END),0) AS paid_gst_total,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.gst_held='Yes' THEN tp.gst_amount ELSE 0 END),0) AS gst_on_hold,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.is_gst_release='Yes' THEN tp.gst_amount ELSE 0 END),0) AS gst_released,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.net_payable  ELSE 0 END),0) AS paid_net
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN transporter_payments tp ON tp.despatch_id = d.id
    LEFT JOIN app_users au ON au.id = d.agent_id
    WHERE d.freight_amount > 0 AND d.transporter_id IS NOT NULL AND d.status = 'Delivered'" . $tp_af . "
    GROUP BY d.id
    HAVING (d.freight_amount - paid_base) > 0.009
        OR (gst_on_hold - gst_released) > 0.009
        OR ((CASE WHEN t.gst_type!='RCM' THEN (d.freight_amount * COALESCE(t.gst_rate,0) / 100) ELSE 0 END) - paid_gst_total) > 0.009
    ORDER BY d.despatch_date ASC
")->fetch_all(MYSQLI_ASSOC);

/* ── All despatches for dropdown (all with freight) ── */
$all_despatches = $db->query("
    SELECT d.id, d.challan_no, d.freight_inv_no, {$tp_vendor_name_expr} AS vendor_name, d.freight_amount, d.despatch_date, d.transporter_id,
           COALESCE(t.rate_per_kg, 0) rate_per_kg,
           COALESCE(d.total_weight, 0) total_weight,
           t.gst_type, t.gst_rate, t.tds_applicable, t.tds_rate,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.base_amount ELSE 0 END),0) paid_base,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.gst_amount  ELSE 0 END),0) paid_gst,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.gst_held='Yes' THEN tp.gst_amount ELSE 0 END),0) gst_on_hold,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.is_gst_release='Yes' THEN tp.gst_amount ELSE 0 END),0) gst_released
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id=t.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN transporter_payments tp ON tp.despatch_id=d.id
    WHERE d.freight_amount > 0 AND d.status = 'Delivered'" . $tp_af . "
    GROUP BY d.id
    ORDER BY d.despatch_date DESC LIMIT 400
")->fetch_all(MYSQLI_ASSOC);

/* ── All payments keyed by despatch_id ── */
$all_payments_by_despatch = [];
if (!empty($all_despatches)) {
    $dids = implode(',', array_column($all_despatches, 'id'));
    $pmts = $db->query("
        SELECT tp.id, tp.despatch_id, tp.payment_no, tp.payment_date, tp.base_amount,
               tp.gst_amount, tp.gst_held, tp.is_gst_release, tp.tds_amount, tp.net_payable,
               tp.payment_type, tp.payment_mode, tp.status, tp.gst_type, tp.gst_rate, tp.tds_rate, tp.remarks
        FROM transporter_payments tp
        LEFT JOIN despatch_orders d ON d.id=tp.despatch_id
        WHERE tp.despatch_id IN ($dids) AND $tp_payment_scope
        ORDER BY tp.payment_date ASC, tp.id ASC
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($pmts as $pm) {
        $all_payments_by_despatch[(int)$pm['despatch_id']][] = $pm;
    }
}

/* ── Summary stats ── */
$total_paid     = (float)$db->query("SELECT COALESCE(SUM(tp.net_payable),0) s FROM transporter_payments tp LEFT JOIN despatch_orders d ON d.id=tp.despatch_id WHERE $tp_payment_scope AND tp.status='Paid'")->fetch_assoc()['s'];
$total_pending  = (float)$db->query("SELECT COALESCE(SUM(tp.net_payable),0) s FROM transporter_payments tp LEFT JOIN despatch_orders d ON d.id=tp.despatch_id WHERE $tp_payment_scope AND tp.status='Pending'")->fetch_assoc()['s'];
$this_month     = (float)$db->query("SELECT COALESCE(SUM(tp.net_payable),0) s FROM transporter_payments tp LEFT JOIN despatch_orders d ON d.id=tp.despatch_id WHERE $tp_payment_scope AND tp.status='Paid' AND MONTH(tp.payment_date)=MONTH(NOW()) AND YEAR(tp.payment_date)=YEAR(NOW())")->fetch_assoc()['s'];
$gst_held_total = (float)$db->query("SELECT COALESCE(SUM(tp.gst_amount),0) s FROM transporter_payments tp LEFT JOIN despatch_orders d ON d.id=tp.despatch_id WHERE $tp_payment_scope AND tp.gst_held='Yes' AND tp.status!='Cancelled'")->fetch_assoc()['s'];
$total_outstanding = array_sum(array_map(function($r) {
    $full_gst   = ($r['gst_type']!=='RCM') ? round($r['freight_amount']*($r['gst_rate']??0)/100,2) : 0;
    $net_hold   = max(0, $r['gst_on_hold'] - $r['gst_released']);
    $paid_excl  = max(0, ($r['paid_gst_total']??0) - $r['gst_on_hold']);
    $gst_due    = max(0, $full_gst - $paid_excl - $net_hold);
    return max(0, $r['freight_amount'] - $r['paid_base']) + $net_hold + $gst_due;
}, $due_rows));

/* Pre-fill for Pay Now */
$pf_despatch_id = (int)($_GET['pay_despatch'] ?? 0);
$pf_transporter = 0;
if ($pf_despatch_id > 0) {
    foreach ($due_rows as $dr) {
        if ($dr['id'] == $pf_despatch_id) { $pf_transporter = $dr['transporter_id']; break; }
    }
}

/* Edit defaults */
$edit_gst_type      = $payment['gst_type']       ?? '';
$edit_gst_held      = $payment['gst_held']        ?? 'No';
$edit_is_gst_release= $payment['is_gst_release']  ?? 'No';

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-cash-coin me-2"></i>Transporter Payments';</script>

<?php if ($action == 'edit'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Payment — <?= htmlspecialchars($payment['payment_no']) ?></h4>
        <small class="text-muted">Modify payment details, TDS, GST status or payment reference</small>
    </div>
    <a href="transporter_payments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Register</a>
</div>
<?php else: ?>
<!-- Hero / Header & Switcher Bar -->
<div class="tp-hero-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-white bg-opacity-25 text-white"><i class="bi bi-cash-stack me-1"></i>Finance &amp; Freight</span>
            <span class="badge bg-info-subtle text-info fw-semibold">Live Ledger</span>
        </div>
        <h4 class="mb-0 fw-bold text-white"><i class="bi bi-cash-coin me-2"></i>Transporter Payments &amp; Settlement</h4>
        <small class="text-white text-opacity-75">Record payments, manage GST hold/releases, and track transporter outstanding dues</small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="transporter_payments.php" class="tp-module-pill active">
            <i class="bi bi-receipt-cutoff"></i>
            <span>Payment Register</span>
        </a>
        <a href="transporter_bulk_payments.php" class="tp-module-pill">
            <i class="bi bi-collection-fill"></i>
            <span>Bulk Settlement</span>
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-outstanding p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Total Outstanding</div>
                    <h4 class="fw-bold text-danger mb-0 mt-1">₹<?= number_format($total_outstanding,2) ?></h4>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-danger border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-danger"><?= count($due_rows) ?> pending despatch<?= count($due_rows)!=1?'es':'' ?></span>
                <small class="text-muted">Freight + GST</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-paid p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Total Paid (Net)</div>
                    <h4 class="fw-bold text-success mb-0 mt-1">₹<?= number_format($total_paid,2) ?></h4>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-success border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-success">All Time Cleared</span>
                <small class="text-muted">Net Disbursed</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-hold p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">GST On Hold</div>
                    <h4 class="fw-bold text-warning mb-0 mt-1" style="color:#b45309 !important">₹<?= number_format($gst_held_total,2) ?></h4>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-warning border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-warning">Compliance Hold</span>
                <small class="text-muted">Withheld GST</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-month p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Paid This Month</div>
                    <h4 class="fw-bold text-primary mb-0 mt-1">₹<?= number_format($this_month,2) ?></h4>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-calendar2-check-fill"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-primary border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-primary"><?= date('F Y') ?></span>
                <small class="text-muted">MTD Outflow</small>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Card -->
<div class="card tp-record-card mb-4" id="recordPaymentCard">
    <div class="tp-card-header d-flex justify-content-between align-items-center"
         onclick="togglePaymentForm()">
        <div class="d-flex align-items-center gap-2">
            <span class="rounded-circle bg-white bg-opacity-20 d-inline-flex align-items-center justify-content-center" style="width:30px;height:30px">
                <i class="bi bi-plus-circle text-white"></i>
            </span>
            <span class="fw-bold fs-6">Record Transporter Payment</span>
        </div>
        <span id="toggleIcon" class="badge bg-white bg-opacity-20 px-2 py-1"><i class="bi bi-chevron-up"></i></span>
    </div>
    <div id="paymentFormBody">
    <div class="card-body p-3 p-md-4">
<?php endif; ?>

<!-- ══ FORM (shared: list inline + edit page) ══ -->
<form method="POST" id="paymentForm" onsubmit="return validatePayment()">
<input type="hidden" name="gst_type"      id="fGstType"     value="<?= htmlspecialchars($edit_gst_type) ?>">
<input type="hidden" name="gst_rate"      id="fGstRate"     value="0">
<input type="hidden" name="gst_held"      id="fGstHeld"     value="<?= htmlspecialchars($edit_gst_held) ?>">
<input type="hidden" name="tds_rate"      id="fTdsRate"     value="0">
<input type="hidden" name="is_gst_release" id="fIsGstRelease" value="<?= htmlspecialchars($edit_is_gst_release) ?>">

<div class="row g-3">

<!-- ══ Section 1: Reference ══ -->
<div class="col-6 col-sm-3 col-md-2">
    <label class="form-label">Payment No</label>
    <input type="text" name="payment_no" class="form-control form-control-sm"
           value="<?= htmlspecialchars($action=='edit' ? ($payment['payment_no']??'') : generatePaymentNo($db)) ?>">
</div>
<div class="col-6 col-sm-3 col-md-2">
    <label class="form-label">Date *</label>
    <input type="date" name="payment_date" class="form-control form-control-sm" required
           value="<?= $action=='edit' ? ($payment['payment_date']??date('Y-m-d')) : date('Y-m-d') ?>">
</div>
<div class="col-12 col-sm-6 col-md-3">
    <label class="form-label">Transporter *</label>
    <select name="transporter_id" id="transporterSel" class="form-select form-select-sm"
            required onchange="onTransporterChange(this)">
        <option value="">-- Select Transporter --</option>
        <?php foreach($transporters as $tr): ?>
        <option value="<?= $tr['id'] ?>"
            data-gst-type="<?= htmlspecialchars($tr['gst_type']??'') ?>"
            data-gst-rate="<?= (float)($tr['gst_rate']??0) ?>"
            data-tds-applicable="<?= $tr['tds_applicable']??'No' ?>"
            data-tds-rate="<?= (float)($tr['tds_rate']??0) ?>"
            <?= (($action=='edit'?($payment['transporter_id']??0):$pf_transporter)==$tr['id'])?'selected':'' ?>>
            <?= htmlspecialchars($tr['transporter_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-12 col-sm-6 col-md-4">
    <label class="form-label">Despatch / Challan *
        <small class="text-info" id="despatchFilterNote" style="display:none">— filtered by transporter</small>
    </label>
    <select name="despatch_id" id="despatchRef" class="form-select form-select-sm"
            required onchange="onDespatchChange(this)">
        <option value="">-- Select Challan --</option>
        <?php foreach($all_despatches as $d): ?>
        <option value="<?= $d['id'] ?>"
            data-transporter="<?= $d['transporter_id'] ?>"
            data-freight="<?= (float)$d['freight_amount'] ?>"
            data-paid-base="<?= (float)$d['paid_base'] ?>"
            data-gst-on-hold="<?= (float)$d['gst_on_hold'] ?>"
            data-gst-type="<?= htmlspecialchars($d['gst_type']??'') ?>"
            data-gst-rate="<?= (float)($d['gst_rate']??0) ?>"
            data-tds-applicable="<?= $d['tds_applicable']??'No' ?>"
            data-tds-rate="<?= (float)($d['tds_rate']??0) ?>"
            data-rate-per-kg="<?= (float)($d['rate_per_kg']??0) ?>"
            data-total-weight="<?= (float)($d['total_weight']??0) ?>"
            <?= (($action=='edit'?($payment['despatch_id']??0):$pf_despatch_id)==$d['id'])?'selected':'' ?>>
            <?= htmlspecialchars($d['challan_no'].' — '.$d['vendor_name'].(!empty($d['freight_inv_no']) ? ' (FI: '.$d['freight_inv_no'].')' : '')) ?>
            (₹<?= number_format($d['freight_amount'],2) ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-6 col-sm-3 col-md-1">
    <label class="form-label">Status</label>
    <select name="status" class="form-select form-select-sm">
        <?php foreach(['Paid','Pending','Cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= (($action=='edit'?($payment['status']??'Pending'):'Pending')==$s)?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- ══ Section 2: Tax & Amount — READ ONLY display (totals for full challan) ══ -->
<div class="col-12" id="taxAmountSection" style="display:none">
<div class="p-3 bg-light rounded-3 border mb-2">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <small class="text-secondary fw-bold text-uppercase"><i class="bi bi-calculator me-1"></i>Freight &amp; Tax Summary — <span class="text-primary" id="roChallanlabel"></span></small>
        <span class="badge badge-soft-dark">Challan Summary</span>
    </div>

    <!-- Challan info row: Rate & Weight & Freight Breakdown -->
    <div class="row g-2">
        <div class="col-6 col-sm-4 col-md-2">
            <div class="tp-tile">
                <div class="tp-tile-label">Freight Rate</div>
                <div class="tp-tile-val"><span id="roRatePerKg">—</span> <small class="text-muted fw-normal" style="font-size:11px">/kg</small></div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <div class="tp-tile">
                <div class="tp-tile-label">Delivered Weight</div>
                <div class="tp-tile-val"><span id="roTotalWeight">—</span> <small class="text-muted fw-normal" style="font-size:11px">MT</small></div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <div class="tp-tile">
                <div class="tp-tile-label">Total Freight</div>
                <div class="tp-tile-val text-dark" id="roFreight">—</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-md-2" id="roGstWrap">
            <div class="tp-tile">
                <div class="tp-tile-label text-success" id="roGstLabel">+ GST</div>
                <div class="tp-tile-val text-success" id="roGstAmt">0.00</div>
                <div class="text-muted" id="roGstDesc" style="font-size:10px"></div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-md-2" id="roTdsWrap" style="display:none">
            <div class="tp-tile">
                <div class="tp-tile-label text-danger">− TDS</div>
                <div class="tp-tile-val text-danger" id="roTdsAmt">0.00</div>
                <div class="text-danger" id="roTdsDesc" style="font-size:10px"></div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <div class="tp-tile" style="background:#eff6ff; border-color:#bfdbfe">
                <div class="tp-tile-label text-primary">Total Payable</div>
                <div class="tp-tile-val text-primary" id="roTotalDue">—</div>
            </div>
        </div>
        <div class="col-6 col-sm-6 col-md-2">
            <div class="tp-tile" style="background:#f0fdf4; border-color:#bbf7d0">
                <div class="tp-tile-label text-success">Total Paid</div>
                <div class="tp-tile-val text-success" id="roTotalPaid">—</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-2">
            <div class="tp-tile" style="background:#fff1f2; border-color:#fecdd3">
                <div class="tp-tile-label text-danger">Remaining Balance</div>
                <div class="tp-tile-val text-danger" id="roBalance">—</div>
            </div>
        </div>
    </div>
</div>

<!-- GST Hold alert -->
<div class="mt-2" id="roGstHoldWrap" style="display:none">
    <div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-3">
        <i class="bi bi-shield-exclamation fs-5 flex-shrink-0"></i>
        <div class="flex-grow-1">
            <strong>GST On Hold: <span id="roGstOnHold">₹0.00</span></strong>
            — withheld pending transporter GST compliance.
        </div>
        <button type="button" class="btn btn-sm btn-warning flex-shrink-0" onclick="activateGstRelease()">
            <i class="bi bi-unlock me-1"></i>Release GST Now
        </button>
    </div>
</div>

<!-- GST Release mode banner -->
<div class="mt-2" id="gstReleaseNotice" style="display:none">
    <div class="alert alert-success py-2 px-3 mb-0 d-flex align-items-center gap-3">
        <i class="bi bi-check-circle-fill fs-5 flex-shrink-0 text-success"></i>
        <div class="flex-grow-1">
            <strong>Release GST Mode</strong> — Recording payment to release held GST of
            <strong id="roGstReleaseAmt">₹0.00</strong> to the transporter.
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" onclick="deactivateGstRelease()">
            <i class="bi bi-x me-1"></i>Cancel
        </button>
    </div>
</div>

</div><!-- taxAmountSection -->

<!-- ══ Section 3: Payment Amount ══ -->
<div class="col-12" id="paymentAmountSection" style="display:none">
<hr class="my-1">
<small class="text-muted fw-semibold text-uppercase">Payment Details</small>
<div class="row g-3 mt-1">

    <!-- Amount this payment -->
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label fw-semibold" id="amtLabel">Amount Being Paid (₹) *</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text">₹</span>
            <input type="number" name="amount_this_payment" id="amountThisPayment"
                   step="0.01" min="0.01" class="form-control" required
                   oninput="onAmountInput()">
        </div>
        <div class="form-text" id="amtHint"></div>
    </div>

    <!-- GST Hold toggle (only for normal payments, not GST release) -->
    <div class="col-12 col-sm-6 col-md-3" id="gstHoldToggleWrap" style="display:none">
        <label class="form-label fw-semibold">Hold GST on This Payment</label>
        <select id="gstHoldToggle" class="form-select form-select-sm" onchange="onGstHoldChange()">
            <option value="No">No — Pay GST now</option>
            <option value="Yes">Yes — Hold GST until compliance</option>
        </select>
        <div class="form-text text-warning" id="gstHoldNote" style="display:none">
            <i class="bi bi-shield-exclamation me-1"></i>GST withheld pending compliance
        </div>
    </div>

    <!-- Net payable this transaction (computed, read-only) -->
    <div class="col-6 col-sm-4 col-md-2" id="netThisWrap" style="display:none">
        <label class="form-label fw-semibold">Net to Transfer (₹)</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text text-primary fw-bold">₹</span>
            <div class="form-control fw-bold text-primary" style="background:#e8f0fe" id="netThisPayment">0.00</div>
        </div>
        <div class="form-text" id="netThisBreakdown"></div>
    </div>

    <!-- Overpayment warning -->
    <div class="col-12" id="overPayWarn" style="display:none">
        <div class="alert alert-danger py-1 px-2 mb-0 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="overPayMsg"></span>
        </div>
    </div>

    <!-- Payment method fields -->
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">Payment Type</label>
        <select name="payment_type" id="paymentTypeSel" class="form-select form-select-sm" onchange="onPaymentTypeChange()">
            <option value="Full Settlement" <?= (($payment['payment_type']??'')=='Full Settlement')?'selected':'' ?>>Full Settlement</option>
            <option value="Partial"         <?= (($payment['payment_type']??'')=='Partial')?'selected':'' ?>>Partial</option>
            <option value="Advance"         <?= (($payment['payment_type']??'')=='Advance')?'selected':'' ?>>Advance</option>
            <option value="Against LR"      <?= (($payment['payment_type']??'')=='Against LR')?'selected':'' ?>>Against LR</option>
            <option value="Release GST"     <?= in_array($payment['payment_type']??'', ['Release GST','GST Release'], true)?'selected':'' ?>>Release GST</option>
        </select>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select form-select-sm">
            <?php foreach(['Bank Transfer','NEFT','RTGS','UPI','Cheque','Cash'] as $pm): ?>
            <option value="<?= $pm ?>" <?= (($payment['payment_mode']??'Bank Transfer')==$pm)?'selected':'' ?>><?= $pm ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label">Reference / UTR No</label>
        <input type="text" name="reference_no" class="form-control form-control-sm"
               value="<?= htmlspecialchars($payment['reference_no']??'') ?>">
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label">Bank Name</label>
        <input type="text" name="bank_name" class="form-control form-control-sm"
               value="<?= htmlspecialchars($payment['bank_name']??'') ?>">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Remarks</label>
        <input type="text" name="remarks" class="form-control form-control-sm"
               value="<?= htmlspecialchars($payment['remarks']??'') ?>">
    </div>

    <div class="col-12 text-end">
        <?php if ($action=='edit'): ?>
        <a href="transporter_payments.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Update Payment</button>
        <?php else: ?>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Payment</button>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- ══ Section 4: Previous Payments for This Challan ══ -->
<div class="col-12" id="priorPaymentsWrap" style="display:none">
<hr class="my-1">
<small class="text-muted fw-semibold text-uppercase">Previous Payments for This Challan</small>
<div class="table-responsive mt-2">
<table class="table table-sm table-bordered mb-0 align-middle">
    <thead class="table-light"><tr>
        <th>#</th><th>Payment No</th><th>Date</th><th>Type</th>
        <th class="text-end">Freight Paid</th>
        <th class="text-end">GST</th>
        <th class="text-end">TDS</th>
        <th class="text-end">Net Paid</th>
        <th>Mode</th><th>Status</th>
    </tr></thead>
    <tbody id="priorPaymentsBody"></tbody>
    <tfoot class="table-light fw-bold" id="priorPaymentsFoot"></tfoot>
</table>
</div>
</div>

</div><!-- row -->
</form>

<?php if ($action != 'edit'): ?>
    </div><!-- card-body -->
    </div><!-- paymentFormBody -->
</div><!-- recordPaymentCard -->

<!-- ══ OUTSTANDING DUE TABLE ══ -->
<?php
// Build agent list from due_rows
$due_agents = [];
foreach ($due_rows as $r) {
    $aid = (int)($r['agent_id'] ?? 0);
    if ($aid && !isset($due_agents[$aid])) {
        $due_agents[$aid] = $r['agent_name'] ?? 'Agent #'.$aid;
    }
}
$filter_agent = isset($_GET['due_agent']) ? (int)$_GET['due_agent'] : 0;
$due_rows_filtered = $filter_agent
    ? array_filter($due_rows, fn($r) => (int)($r['agent_id']??0) === $filter_agent)
    : $due_rows;
$due_rows_filtered = array_values($due_rows_filtered);
$total_outstanding_filtered = array_sum(array_map(function($r) {
    $full_gst   = ($r['gst_type']!=='RCM') ? round($r['freight_amount']*($r['gst_rate']??0)/100,2) : 0;
    $net_hold   = max(0, $r['gst_on_hold'] - $r['gst_released']);
    $paid_excl  = max(0, ($r['paid_gst_total']??0) - $r['gst_on_hold']);
    $gst_due    = max(0, $full_gst - $paid_excl - $net_hold);
    return max(0, $r['freight_amount'] - $r['paid_base']) + $net_hold + $gst_due;
}, $due_rows_filtered));
?>
<div class="card tp-due-card mb-4">
    <div class="tp-due-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="rounded-circle bg-white bg-opacity-20 d-inline-flex align-items-center justify-content-center" style="width:30px;height:30px">
                <i class="bi bi-exclamation-octagon-fill text-white"></i>
            </span>
            <span class="text-white fw-bold fs-6">
                Outstanding Transporter Dues &mdash; Freight + GST
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($due_agents)): ?>
            <form method="GET" class="d-flex align-items-center gap-1 mb-0" id="agentFilterForm">
                <?php foreach ($_GET as $k=>$v): if($k==='due_agent') continue; ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                <?php endforeach; ?>
                <select name="due_agent" class="form-select form-select-sm bg-white border-0 shadow-sm" style="min-width:160px;font-size:.82rem"
                        onchange="this.form.submit()">
                    <option value="0" <?= !$filter_agent?'selected':'' ?>>All Agents</option>
                    <?php foreach ($due_agents as $aid => $aname): ?>
                    <option value="<?= $aid ?>" <?= $filter_agent===$aid?'selected':'' ?>><?= htmlspecialchars($aname) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <span class="badge bg-white text-danger fw-bold shadow-sm px-2 py-1"><?= count($due_rows_filtered) ?> pending</span>
        </div>
    </div>
    <?php if (empty($due_rows)): ?>
    <div class="card-body text-center py-5 text-muted">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success p-3 mb-2">
            <i class="bi bi-check2-all fs-2"></i>
        </div>
        <h6 class="fw-bold text-dark mt-2">All freight payments fully cleared!</h6>
        <small class="text-muted">There are currently no outstanding freight or GST dues.</small>
    </div>
    <?php else: ?>

    <?php
    // ── Group due_rows_filtered by transporter ──
    $due_by_trans = [];
    foreach ($due_rows_filtered as $r) {
        $tn = $r['transporter_name'] ?? 'Unknown';
        if (!isset($due_by_trans[$tn])) {
            $due_by_trans[$tn] = ['rows'=>[], 'weight'=>0, 'freight'=>0, 'paid'=>0, 'gst_hold'=>0, 'gst_released'=>0, 'bal'=>0];
        }
        $due_by_trans[$tn]['rows'][]      = $r;
        $due_by_trans[$tn]['weight']     += (float)($r['total_weight'] ?? 0);
        $due_by_trans[$tn]['freight']    += $r['freight_amount'];
        $due_by_trans[$tn]['paid']       += $r['paid_base'];
        $due_by_trans[$tn]['gst_hold']   += $r['gst_on_hold'];
        $due_by_trans[$tn]['gst_released']+= $r['gst_released'];
        $_fg   = ($r['gst_type']!=='RCM') ? round($r['freight_amount']*($r['gst_rate']??0)/100,2) : 0;
        $_nh   = max(0, $r['gst_on_hold'] - $r['gst_released']);
        $_pe   = max(0, ($r['paid_gst_total']??0) - $r['gst_on_hold']);
        $_gdue = max(0, $_fg - $_pe - $_nh);
        $due_by_trans[$tn]['bal']        += max(0, $r['freight_amount'] - $r['paid_base']) + $_nh + $_gdue;
    }
    arsort($due_by_trans); // sort by transporter with highest balance first
    uasort($due_by_trans, fn($a,$b) => $b['bal'] <=> $a['bal']);
    ?>

    <!-- Transporter Summary -->
    <div class="card-body pb-0 pt-3 px-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-bold text-secondary small text-uppercase"><i class="bi bi-truck me-1"></i>Summary by Transporter</span>
            <small class="text-muted">Sorted by highest balance</small>
        </div>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-3 align-middle border">
            <thead class="table-dark" style="background:#1e293b; color:#f8fafc">
                <tr>
                    <th class="py-2 ps-3">Transporter</th>
                    <th class="text-end py-2">Challans</th>
                    <th class="text-end py-2">Weight (MT)</th>
                    <th class="text-end py-2">Total Freight</th>
                    <th class="text-end py-2">Paid</th>
                    <th class="text-end py-2">GST Hold</th>
                    <th class="text-end py-2 pe-3 fw-bold">Balance Due</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($due_by_trans as $tname => $tdata): ?>
            <tr>
                <td class="fw-semibold ps-3"><?= htmlspecialchars($tname) ?></td>
                <td class="text-end"><span class="badge badge-soft-dark"><?= count($tdata['rows']) ?></span></td>
                <td class="text-end text-secondary"><?= number_format((float)$tdata['weight'],3) ?></td>
                <td class="text-end fw-semibold">₹<?= number_format($tdata['freight'],2) ?></td>
                <td class="text-end text-success fw-semibold">₹<?= number_format($tdata['paid'],2) ?></td>
                <td class="text-end"><?= $tdata['gst_hold']>0 ? '<span class="badge badge-soft-warning">₹'.number_format(max(0,$tdata['gst_hold']-$tdata['gst_released']),2).'</span>' : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end fw-bold text-danger pe-3">₹<?= number_format($tdata['bal'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold border-top" style="background:#f8fafc">
                <tr>
                    <td class="ps-3">TOTAL</td>
                    <td class="text-end"><span class="badge bg-secondary"><?= count($due_rows_filtered) ?></span></td>
                    <td class="text-end"><?= number_format(array_sum(array_map(fn($r) => (float)($r['total_weight'] ?? 0), $due_rows_filtered)),3) ?></td>
                    <td class="text-end">₹<?= number_format(array_sum(array_column($due_rows_filtered,'freight_amount')),2) ?></td>
                    <td class="text-end text-success">₹<?= number_format(array_sum(array_column($due_rows_filtered,'paid_base')),2) ?></td>
                    <td class="text-end text-warning">₹<?= number_format(array_sum(array_column($due_rows_filtered,'gst_on_hold')),2) ?></td>
                    <td class="text-end text-danger pe-3">₹<?= number_format($total_outstanding_filtered,2) ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
    <hr class="my-0">

    <!-- Outstanding Detail — grouped accordion by transporter -->
    <div class="card-body p-3" id="outstandingAccordion">
    <?php $acc_out_idx = 0; foreach ($due_by_trans as $tname => $tdata):
        $acc_out_id  = 'outAcc_' . preg_replace('/[^a-zA-Z0-9]/', '_', $tname) . '_' . $acc_out_idx;
        $is_out_open = false;
        $acc_out_idx++;
        $t_rows = $tdata['rows'];
    ?>
    <div class="card mb-2 shadow-sm border tp-acc-item">
        <!-- Accordion header -->
        <div class="card-header p-0 bg-transparent border-0">
        <button type="button"
            class="btn w-100 text-start d-flex align-items-center justify-content-between gap-2 px-3 py-2 out-acc-btn tp-acc-btn-due"
            data-acc-target="<?= $acc_out_id ?>">
            <span class="d-flex align-items-center gap-2 flex-wrap">
                <i class="bi bi-truck fs-5 text-danger"></i>
                <strong class="text-dark fs-6"><?= htmlspecialchars($tname) ?></strong>
                <span class="badge badge-soft-danger"><?= count($t_rows) ?> challan<?= count($t_rows)!=1?'s':'' ?></span>
            </span>
            <span class="d-flex gap-2 align-items-center flex-wrap">
                <span class="badge badge-soft-dark">Freight ₹<?= number_format($tdata['freight'],2) ?></span>
                <span class="badge badge-soft-success">Paid ₹<?= number_format($tdata['paid'],2) ?></span>
                <?php if(max(0,$tdata['gst_hold']-$tdata['gst_released'])>0): ?>
                <span class="badge badge-soft-warning">GST Hold ₹<?= number_format(max(0,$tdata['gst_hold']-$tdata['gst_released']),2) ?></span>
                <?php endif; ?>
                <span class="badge bg-danger text-white fw-bold shadow-sm">Due ₹<?= number_format($tdata['bal'],2) ?></span>
                <i class="bi <?= $is_out_open ? 'bi-chevron-down' : 'bi-chevron-right' ?> text-secondary ms-1" id="<?= $acc_out_id ?>_icon"></i>
            </span>
        </button>
        </div>
        <!-- Accordion body -->
        <div id="<?= $acc_out_id ?>" style="display:<?= $is_out_open ? 'block' : 'none' ?>">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle out-sticky-table">
            <thead class="table-light"><tr>
                <th>#</th>
                <th class="out-sticky-col">Challan</th>
                <th>Despatch Date</th><th>Vendor</th>
                <th class="text-end">Weight (MT)</th>
                <th class="text-end text-nowrap">Rate</th>
                <th class="text-end">Freight</th><th class="text-end">Paid</th>
                <th class="text-end">GST Hold</th><th class="text-end text-danger">Balance Due</th>
                <th>Status</th><th>Action</th>
            </tr></thead>
            <tbody>
            <?php $i=1; foreach ($t_rows as $row):
                $full_gst    = ($row['gst_type']!=='RCM') ? round($row['freight_amount']*($row['gst_rate']??0)/100,2) : 0;
                $full_tds    = ($row['tds_applicable']??'No')==='Yes' ? round($row['freight_amount']*($row['tds_rate']??0)/100,2) : 0;
                $bal_freight = $row['freight_amount'] - $row['paid_base'];
                $net_gst_hold= max(0, $row['gst_on_hold'] - $row['gst_released']);
                $paid_gst_excl_hold = max(0, ($row['paid_gst_total'] ?? 0) - $row['gst_on_hold']);
                $gst_due     = max(0, $full_gst - $paid_gst_excl_hold - $net_gst_hold);
                $bal_total   = $bal_freight + $net_gst_hold + $gst_due;
                $sb = ['Delivered'=>'success','In Transit'=>'warning','Despatched'=>'primary','Draft'=>'secondary','Cancelled'=>'danger'][$row['despatch_status']]??'secondary';
                $is_hold = !empty($row['transporter_due_hold']);
                $hold_reason = trim((string)($row['transporter_due_hold_reason'] ?? ''));
                $row_class = $is_hold ? 'table-secondary' : '';
            ?>
            <tr class="<?= $row_class ?>">
                <td><?= $i++ ?></td>
                <td class="out-sticky-col">
                    <strong><?= htmlspecialchars($row['challan_no']) ?></strong>
                    <?php if (!empty($row['freight_inv_no'])): ?><br><span class="fi-badge">FI: <?= htmlspecialchars($row['freight_inv_no']) ?></span><?php endif; ?>
                </td>
                <td><?= date('d/m/Y',strtotime($row['despatch_date'])) ?></td>
                <td><?= htmlspecialchars($row['vendor_name']) ?><?php if($row['consignee_city']): ?><br><small class="text-muted"><?= htmlspecialchars($row['consignee_city']) ?></small><?php endif; ?></td>
                <td class="text-end"><?= number_format((float)($row['total_weight'] ?? 0),3) ?></td>
                <?php
                $tr_rate = (float)($row['transporter_rate_per_mt'] ?? 0);
                if ($tr_rate <= 0 && (float)($row['rate_card_rate'] ?? 0) > 0) {
                    $tr_rate = (float)$row['rate_card_rate'];
                }
                if ($tr_rate <= 0 && (float)($row['total_weight'] ?? 0) > 0 && (float)($row['freight_amount'] ?? 0) > 0) {
                    $tr_rate = round((float)$row['freight_amount'] / (float)$row['total_weight'], 2);
                }
                $tr_uom = trim((string)($row['rate_card_uom'] ?? ''));
                if ($tr_uom === '') $tr_uom = 'MT';
                ?>
                <td class="text-end text-nowrap">
                    <?php if ($tr_rate > 0): ?>
                        <span class="fw-semibold">₹<?= number_format($tr_rate, 2) ?></span> <small class="text-muted">/<?= htmlspecialchars($tr_uom) ?></small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">₹<?= number_format($row['freight_amount'],2) ?><?php if($full_gst>0): ?><br><small class="text-success">+GST ₹<?= number_format($full_gst,2) ?></small><?php endif; ?><?php if($full_tds>0): ?><br><small class="text-danger">-TDS ₹<?= number_format($full_tds,2) ?></small><?php endif; ?></td>
                <td class="text-end text-success">₹<?= number_format($row['paid_base'],2) ?><?php if($row['paid_gst_total']-$row['gst_on_hold']>0): ?><br><small>+GST ₹<?= number_format($row['paid_gst_total']-$row['gst_on_hold'],2) ?></small><?php endif; ?></td>
                <td class="text-end"><?php if($net_gst_hold>0): ?><span class="badge bg-warning text-dark">₹<?= number_format($net_gst_hold,2) ?></span><?php else: ?>—<?php endif; ?></td>
                <td class="text-end fw-bold text-danger">₹<?= number_format($bal_total,2) ?><?php if($net_gst_hold>0&&$bal_freight<=0): ?><br><small class="badge bg-warning text-dark">GST only</small><?php endif; ?></td>
                <td>
                    <span class="badge bg-<?= $sb ?>"><?= htmlspecialchars($row['despatch_status']) ?></span>
                    <?php if ($is_hold): ?>
                    <br><span class="badge bg-dark mt-1">On Hold</span>
                    <?php if ($hold_reason !== ''): ?><br><small class="text-muted"><?= htmlspecialchars($hold_reason) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1 flex-wrap transporter-action-row">
                        <a href="?pay_despatch=<?= $row['id'] ?>"
                           class="btn btn-sm btn-danger transporter-action-btn"
                           onclick="scrollToForm()"><i class="bi bi-cash"></i><span>Pay</span></a>
                        <?php if (isAdmin()): ?>
                        <button type="button"
                                class="btn btn-sm <?= $is_hold ? 'btn-outline-secondary' : 'btn-outline-dark' ?> transporter-action-btn"
                                onclick='openDueHoldModal(<?= (int)$row["id"] ?>, <?= json_encode((string)$row["challan_no"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= $is_hold ? "true" : "false" ?>, <?= json_encode($hold_reason, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                            <i class="bi <?= $is_hold ? 'bi-unlock' : 'bi-pause-circle' ?>"></i><span><?= $is_hold ? 'Release' : 'Hold' ?></span>
                        </button>
                        <?php endif; ?>
                        <?php if ($net_gst_hold>0): ?>
                        <a href="?pay_despatch=<?= $row['id'] ?>&release_gst=1"
                           class="btn btn-sm btn-warning transporter-action-btn"
                           onclick="scrollToForm()"><i class="bi bi-unlock"></i><span>GST</span></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold"><tr>
                <td colspan="4" class="text-end">Totals:</td>
                <td class="text-end"><?= number_format(array_sum(array_map(fn($r) => (float)($r['total_weight'] ?? 0), $t_rows)),3) ?></td>
                <td class="text-end text-muted">—</td>
                <td class="text-end">₹<?= number_format($tdata['freight'],2) ?></td>
                <td class="text-end text-success">₹<?= number_format($tdata['paid'],2) ?></td>
                <td class="text-end text-warning"><?= max(0,$tdata['gst_hold']-$tdata['gst_released'])>0 ? '₹'.number_format(max(0,$tdata['gst_hold']-$tdata['gst_released']),2) : '—' ?></td>
                <td class="text-end text-danger">₹<?= number_format($tdata['bal'],2) ?></td>
                <td colspan="2"></td>
            </tr></tfoot>
        </table>
        </div>
        </div><!-- accordion body -->
    </div><!-- card -->
    <?php endforeach; ?>
    </div><!-- outstandingAccordion -->
    <?php endif; ?>
</div>

<!-- ══ PAYMENT HISTORY — GROUPED BY TRANSPORTER ══ -->
<?php
/* Fetch all payments ordered by transporter then date */
$hist_all = $db->query("
    SELECT tp.*, t.transporter_name, t.gst_type AS tr_gst_type, t.gst_rate AS tr_gst_rate,
           d.challan_no, d.freight_inv_no, {$tp_vendor_name_expr} AS vendor_name, COALESCE(d.total_weight, 0) AS total_weight
    FROM transporter_payments tp
    LEFT JOIN transporters t ON tp.transporter_id = t.id
    LEFT JOIN despatch_orders d ON tp.despatch_id = d.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    WHERE $tp_payment_scope
    ORDER BY t.transporter_name ASC, tp.payment_date DESC, tp.id DESC
")->fetch_all(MYSQLI_ASSOC);

/* Group by transporter */
$grouped = [];   // [ tid => ['name'=>..., 'rows'=>[], 'totals'=>[]] ]
foreach ($hist_all as $v) {
    $tid  = (int)($v['transporter_id'] ?: 0);
    $name = $v['transporter_name'] ?: '(Unknown)';
    if (!isset($grouped[$tid])) {
        $grouped[$tid] = [
            'name'    => $name,
            'gst_type'=> $v['tr_gst_type'] ?? '',
            'gst_rate'=> $v['tr_gst_rate'] ?? 0,
            'rows'    => [],
            'tot_base'=> 0, 'tot_gst'=> 0, 'tot_tds'=> 0, 'tot_net'=> 0,
            'tot_paid'=> 0, 'tot_pend'=> 0, 'tot_held'=> 0, 'cnt'=> 0,
        ];
    }
    $grouped[$tid]['rows'][] = $v;
    if ($v['status'] !== 'Cancelled') {
        $net = (float)($v['net_payable'] ?: $v['amount']);
        $grouped[$tid]['tot_base'] += (float)($v['base_amount'] ?: $v['amount']);
        $grouped[$tid]['tot_gst']  += (float)$v['gst_amount'];
        $grouped[$tid]['tot_tds']  += (float)$v['tds_amount'];
        $grouped[$tid]['tot_net']  += $net;
        $grouped[$tid]['cnt']++;
        if ($v['status'] === 'Paid')    $grouped[$tid]['tot_paid'] += $net;
        if ($v['status'] === 'Pending') $grouped[$tid]['tot_pend'] += $net;
        if ($v['gst_held'] === 'Yes')   $grouped[$tid]['tot_held'] += (float)$v['gst_amount'];
    }
}

/* Expand/collapse state: landing page should start fully collapsed */
$grp_idx = 0;
?>

<div class="mb-2 d-flex justify-content-between align-items-center">
    <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Payment History — By Transporter</h6>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllGroups(true)">
            <i class="bi bi-chevron-expand me-1"></i>Expand All
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllGroups(false)">
            <i class="bi bi-chevron-contract me-1"></i>Collapse All
        </button>
    </div>
</div>

<?php if (empty($grouped)): ?>
<div class="card"><div class="card-body text-center text-muted py-4">
    <i class="bi bi-inbox fs-2 d-block mb-2"></i>No payment records found.
</div></div>
<?php else: ?>

<?php foreach ($grouped as $tid => $grp):
    $gst_lbl = $grp['gst_type']==='Central' ? 'IGST' : ($grp['gst_type']==='Regular' ? 'CGST+SGST' : ($grp['gst_type']==='RCM' ? 'RCM' : ''));
    $acc_id  = 'trGrp_'.$tid;
    $is_open = false;
    $grp_idx++;
?>
<div class="card mb-2 shadow-sm border tp-acc-item">

    <!-- ── Group Header ── -->
    <div class="card-header p-0 bg-transparent border-0">
    <button class="btn w-100 text-start d-flex align-items-center justify-content-between gap-2 px-3 py-2 tp-acc-btn-hist"
            onclick="toggleGroup('<?= $acc_id ?>')">
        <span class="d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-truck fs-5 text-primary"></i>
            <strong class="text-dark fs-6"><?= htmlspecialchars($grp['name']) ?></strong>
            <?php if($gst_lbl): ?>
            <span class="badge badge-soft-primary"><?= $gst_lbl ?><?= $grp['gst_rate']>0?' '.$grp['gst_rate'].'%':'' ?></span>
            <?php endif; ?>
            <span class="badge badge-soft-dark"><?= $grp['cnt'] ?> transaction<?= $grp['cnt']!=1?'s':'' ?></span>
        </span>
        <!-- Mini summary pills -->
        <span class="d-flex gap-2 align-items-center flex-wrap">
            <span class="badge badge-soft-success">Paid ₹<?= number_format($grp['tot_paid'],2) ?></span>
            <?php if($grp['tot_pend']>0): ?>
            <span class="badge badge-soft-warning">Pending ₹<?= number_format($grp['tot_pend'],2) ?></span>
            <?php endif; ?>
            <?php if($grp['tot_held']>0): ?>
            <span class="badge badge-soft-danger">GST Hold ₹<?= number_format($grp['tot_held'],2) ?></span>
            <?php endif; ?>
            <span class="badge bg-primary text-white shadow-sm">Net ₹<?= number_format($grp['tot_net'],2) ?></span>
            <i class="bi bi-chevron-right text-secondary ms-1" id="<?= $acc_id ?>_icon"></i>
        </span>
    </button>
    </div>

    <!-- ── Group Body ── -->
    <div id="<?= $acc_id ?>" style="display:<?= $is_open?'block':'none' ?>">
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle hist-sticky-table">
        <thead class="table-light">
        <tr>
            <th>#</th>
            <th>Payment No</th>
            <th>Date</th>
            <th class="hist-sticky-col">Challan</th>
            <th class="text-end">Weight (MT)</th>
            <th>Type</th>
            <th class="text-end">Freight Base</th>
            <th class="text-end">GST</th>
            <th class="text-end">TDS</th>
            <th class="text-end">Net Paid</th>
            <th>Mode</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php $row_i=1; foreach ($grp['rows'] as $v):
            $b    = ['Paid'=>'success','Pending'=>'warning','Cancelled'=>'danger'][$v['status']]??'secondary';
            $held = ($v['gst_held']??'No')==='Yes';
            $rel  = ($v['is_gst_release']??'No')==='Yes';
            $canc = $v['status']==='Cancelled';
        ?>
        <tr<?= $canc?' class="text-muted opacity-75"':'' ?>>
            <td><?= $row_i++ ?></td>
            <td>
                <strong class="text-dark"><?= htmlspecialchars($v['payment_no']) ?></strong>
                <?php if($rel): ?><br><span class="badge badge-soft-success">Release GST</span><?php endif; ?>
            </td>
            <td style="white-space:nowrap" class="text-secondary"><?= date('d/m/Y',strtotime($v['payment_date'])) ?></td>
            <td class="hist-sticky-col">
                <?php if($v['challan_no']): ?>
                <strong class="text-primary"><?= htmlspecialchars($v['challan_no']) ?></strong>
                <?php if(!empty($v['freight_inv_no'])): ?><br><span class="fi-badge">FI: <?= htmlspecialchars($v['freight_inv_no']) ?></span><?php endif; ?>
                <?php if($v['vendor_name']): ?><br><small class="text-muted"><?= htmlspecialchars($v['vendor_name']) ?></small><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end text-secondary"><?= number_format((float)($v['total_weight'] ?? 0),3) ?></td>
            <td><span class="badge badge-soft-dark"><?= htmlspecialchars($v['payment_type']) ?></span></td>
            <td class="text-end">₹<?= number_format($v['base_amount']?:$v['amount'],2) ?></td>
            <td class="text-end">
                <?php if($v['gst_amount']>0): ?>
                <span class="<?= $held?'text-warning fw-semibold':'text-success fw-semibold' ?>">
                    +₹<?= number_format($v['gst_amount'],2) ?>
                    <?php if($v['gst_type']): ?><br><small class="text-muted"><?= $v['gst_type']==='Central'?'IGST':($v['gst_type']==='Regular'?'CGST+SGST':'RCM') ?> <?= $v['gst_rate'] ?>%</small><?php endif; ?>
                    <?php if($held): ?><br><span class="badge badge-soft-warning">On Hold</span><?php elseif($rel): ?><br><span class="badge badge-soft-success">Released</span><?php endif; ?>
                </span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end">
                <?php if($v['tds_amount']>0): ?>
                <span class="text-danger fw-semibold">-₹<?= number_format($v['tds_amount'],2) ?><br><small class="text-muted">TDS <?= $v['tds_rate'] ?>%</small></span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end fw-bold text-dark">₹<?= number_format($v['net_payable']?:$v['amount'],2) ?></td>
            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($v['payment_mode']??'—') ?></span></td>
            <td><span class="badge bg-<?= $b ?>"><?= $v['status'] ?></span></td>
            <td style="white-space:nowrap">
                <?php if (canAuthorizeTransporterPayments()): ?>
                <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary" title="Authorise / Edit Payment"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <button onclick="confirmDelete(<?= $v['id'] ?>,'transporter_payments.php')" class="btn btn-sm btn-outline-danger" title="Delete Payment"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold border-top">
        <tr>
            <td colspan="6" class="text-end text-secondary">Totals (excl. cancelled):</td>
            <td class="text-end">₹<?= number_format($grp['tot_base'],2) ?></td>
            <td class="text-end text-success">₹<?= number_format($grp['tot_gst'],2) ?><?= $grp['tot_held']>0?' <span class="badge badge-soft-warning ms-1">Hold ₹'.number_format($grp['tot_held'],2).'</span>':'' ?></td>
            <td class="text-end text-danger">-₹<?= number_format($grp['tot_tds'],2) ?></td>
            <td class="text-end text-primary">₹<?= number_format($grp['tot_net'],2) ?></td>
            <td colspan="3"></td>
        </tr>
        </tfoot>
    </table>
    </div>
    </div><!-- group body -->

</div><!-- card -->
<?php endforeach; ?>
<?php endif; // end grouped ?>
<?php endif; // end list ?>

<?php if (isAdmin()): ?>
<div class="modal fade" id="dueHoldModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fs-6"><i class="bi bi-pause-circle me-2"></i>Transporter Due Hold</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dueHoldDespatchId">
                <input type="hidden" id="dueHoldIsRelease" value="0">
                <div class="mb-2 text-muted small fw-semibold">Challan</div>
                <div class="form-control bg-light mb-3 fw-bold" id="dueHoldChallan"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                    <textarea id="dueHoldReason" class="form-control" rows="3" placeholder="Enter non-compliance reason"></textarea>
                    <div class="form-text" id="dueHoldHelp">This will mark the transporter due as on hold for admin reference.</div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-dark btn-sm fw-bold px-3" id="dueHoldSaveBtn" onclick="saveDueHold()">
                    <i class="bi bi-check2 me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* ─── Premium Transporter Payments Theme ─── */
.tp-hero-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #1e3a8a 100%);
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    color: #ffffff;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.15), 0 8px 10px -6px rgba(15, 23, 42, 0.1);
}
.tp-module-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 1.1rem;
    border-radius: 9999px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}
.tp-module-pill.active {
    background: #ffffff;
    color: #1e3a8a;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}
.tp-module-pill:not(.active) {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.tp-module-pill:not(.active):hover {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
    transform: translateY(-1px);
}

/* ─── Executive Stat Cards ─── */
.tp-kpi-card {
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    overflow: hidden;
}
.tp-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}
.tp-kpi-card.kpi-outstanding {
    background: linear-gradient(145deg, #fff1f2 0%, #ffffff 60%);
    border-color: #fecdd3;
    border-left: 4px solid #e11d48;
}
.tp-kpi-card.kpi-paid {
    background: linear-gradient(145deg, #ecfdf5 0%, #ffffff 60%);
    border-color: #a7f3d0;
    border-left: 4px solid #10b981;
}
.tp-kpi-card.kpi-hold {
    background: linear-gradient(145deg, #fffbeb 0%, #ffffff 60%);
    border-color: #fde68a;
    border-left: 4px solid #f59e0b;
}
.tp-kpi-card.kpi-month {
    background: linear-gradient(145deg, #eff6ff 0%, #ffffff 60%);
    border-color: #bfdbfe;
    border-left: 4px solid #3b82f6;
}

.tp-kpi-icon-wrap {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}
.kpi-outstanding .tp-kpi-icon-wrap { background: #ffe4e6; color: #e11d48; }
.kpi-paid .tp-kpi-icon-wrap { background: #d1fae5; color: #059669; }
.kpi-hold .tp-kpi-icon-wrap { background: #fef3c7; color: #d97706; }
.kpi-month .tp-kpi-icon-wrap { background: #dbeafe; color: #2563eb; }

/* ─── Record Payment Card ─── */
.tp-record-card {
    border-radius: 12px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    overflow: hidden;
}
.tp-record-card .tp-card-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
    color: #ffffff;
    padding: 0.85rem 1.25rem;
    cursor: pointer;
    user-select: none;
}

/* ─── Read-only Info Tiles ─── */
.tp-tile {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
}
.tp-tile-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 2px;
}
.tp-tile-val {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1e293b;
}

/* ─── Outstanding Dues Card & Accordion ─── */
.tp-due-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    overflow: hidden;
}
.tp-due-header {
    background: linear-gradient(135deg, #881337 0%, #be123c 50%, #9f1239 100%);
    color: #ffffff;
    padding: 0.85rem 1.25rem;
}
.tp-acc-item {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
    overflow: hidden;
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.tp-acc-item:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
}
.tp-acc-btn-due {
    background: #ffffff !important;
    border: none;
    border-left: 4px solid #e11d48 !important;
    color: #1e293b;
    padding: 0.75rem 1rem;
    text-align: left;
    width: 100%;
    transition: background 0.15s ease;
}
.tp-acc-btn-due:hover {
    background: #fff1f2 !important;
}
.tp-acc-btn-hist {
    background: #ffffff !important;
    border: none;
    border-left: 4px solid #2563eb !important;
    color: #1e293b;
    padding: 0.75rem 1rem;
    text-align: left;
    width: 100%;
    transition: background 0.15s ease;
}
.tp-acc-btn-hist:hover {
    background: #eff6ff !important;
}

/* Soft Badges */
.badge-soft-danger {
    background: #ffe4e6;
    color: #be123c;
    border: 1px solid #fecdd3;
    font-weight: 600;
}
.badge-soft-success {
    background: #d1fae5;
    color: #047857;
    border: 1px solid #a7f3d0;
    font-weight: 600;
}
.badge-soft-warning {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
    font-weight: 600;
}
.badge-soft-primary {
    background: #dbeafe;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    font-weight: 600;
}
.badge-soft-dark {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    font-weight: 600;
}

/* ── Sticky Challan column for mobile horizontal scroll ── */
.out-sticky-table .out-sticky-col,
.hist-sticky-table .hist-sticky-col {
    position: sticky;
    left: 0;
    z-index: 2;
    background: #fff;
    box-shadow: 2px 0 6px rgba(0,0,0,.06);
    min-width: 90px;
}
.out-sticky-table thead .out-sticky-col,
.hist-sticky-table thead .hist-sticky-col {
    background: #f8f9fa; /* match table-light */
    z-index: 3;
}
/* Keep row highlight colours showing through the sticky cell */
.table-danger  .out-sticky-col,
.table-danger  .hist-sticky-col { background: #fee2e2; }
.table-warning .out-sticky-col,
.table-warning .hist-sticky-col { background: #fef3c7; }
.transporter-action-row {
    min-width: 220px;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.transporter-action-btn {
    flex: 0 0 auto;
    min-width: 68px;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .3rem;
    padding: .24rem .55rem;
    font-size: .78rem;
    line-height: 1.1;
    border-radius: .45rem;
}
.transporter-action-btn i {
    margin-right: 0 !important;
    font-size: .8rem;
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
/* ── Outstanding accordion — one open at a time ── */
document.querySelectorAll('.out-acc-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var targetId = btn.getAttribute('data-acc-target');
        var target   = document.getElementById(targetId);
        var icon     = document.getElementById(targetId + '_icon');
        var isOpen   = target && target.style.display !== 'none';

        /* Close all outstanding accordion panels first */
        document.querySelectorAll('#outstandingAccordion [id^="outAcc_"]:not([id$="_icon"])').forEach(function(panel) {
            panel.style.display = 'none';
            var ic = document.getElementById(panel.id + '_icon');
            if (ic) ic.className = 'bi bi-chevron-right text-white';
        });

        /* If it was closed, open it; if it was open, leave it closed (toggle) */
        if (!isOpen && target) {
            target.style.display = 'block';
            if (icon) icon.className = 'bi bi-chevron-down text-white';
        }
    });
});

/* ── Payment history accordion — one open at a time ── */
function toggleGroup(id) {
    var body = document.getElementById(id);
    var icon = document.getElementById(id + '_icon');
    if (!body) return;
    var open = body.style.display !== 'none';

    /* Close all history groups */
    document.querySelectorAll('[id^="trGrp_"]:not([id$="_icon"])').forEach(function(el) {
        el.style.display = 'none';
        var ic = document.getElementById(el.id + '_icon');
        if (ic) ic.className = 'bi bi-chevron-right text-white';
    });

    /* Open clicked one if it was closed */
    if (!open) {
        body.style.display = 'block';
        if (icon) icon.className = 'bi bi-chevron-down text-white';
    }
}
function toggleAllGroups(expand) {
    document.querySelectorAll('[id^="trGrp_"]:not([id$="_icon"])').forEach(function(el) {
        el.style.display = expand ? 'block' : 'none';
    });
    document.querySelectorAll('[id$="_icon"]').forEach(function(ic) {
        ic.className = expand ? 'bi bi-chevron-down text-white' : 'bi bi-chevron-right text-white';
    });
}
</script>

<!-- ══ JS ══ -->
<script>
/* PHP → JS: all payments keyed by despatch id (ALL statuses for display) */
const despatchPayments = <?php echo json_encode($all_payments_by_despatch, JSON_HEX_APOS); ?>;

/* State */
var D = null;          // current challan data object
var gstRelMode = false;
var maxAllowed = 0;    // max amount user may enter

/* ── Transporter change: filter challan dropdown ── */
function onTransporterChange(sel) {
    var tid = (sel && sel.value) ? sel.value : '';
    filterDespatchDropdown(tid);
    var dSel = document.getElementById('despatchRef');
    if (dSel) { dSel.value = ''; onDespatchChange(dSel); }
}

function filterDespatchDropdown(tid) {
    var dSel = document.getElementById('despatchRef');
    var note = document.getElementById('despatchFilterNote');
    if (!dSel) return;
    dSel.querySelectorAll('option').forEach(function(o) {
        if (!o.value) return;
        var match = !tid || o.dataset.transporter == tid;
        o.style.display = match ? '' : 'none';
        o.disabled = !match;
    });
    if (note) note.style.display = tid ? 'inline' : 'none';
}

/* ── Despatch / Challan change ── */
function onDespatchChange(sel) {
    var opt = sel ? sel.selectedOptions[0] : null;
    var show = opt && opt.value;

    el('taxAmountSection').style.display   = show ? 'block' : 'none';
    el('paymentAmountSection').style.display = show ? 'block' : 'none';
    el('priorPaymentsWrap').style.display  = show ? 'block' : 'none';

    if (!show) {
        D = null; gstRelMode = false; maxAllowed = 0;
        updateHidden();
        return;
    }

    /* Raw data from option data-attributes */
    var freight    = pf(opt.dataset.freight);
    var paidBase   = pf(opt.dataset.paidBase);   /* sum of non-cancelled base_amount payments */
    var gstOnHold  = pf(opt.dataset.gstOnHold);  /* GST still withheld */
    var gstType    = opt.dataset.gstType  || '';
    var gstRate    = pf(opt.dataset.gstRate);
    var tdsOk      = opt.dataset.tdsApplicable === 'Yes';
    var tdsRate    = pf(opt.dataset.tdsRate);
    var ratePerKg  = pf(opt.dataset.ratePerKg);
    var totalWeight= pf(opt.dataset.totalWeight);
    var isRCM      = gstType === 'RCM';

    /* Show Freight Rate & Weight */
    setText('roRatePerKg',   ratePerKg   > 0 ? fmt(ratePerKg)   : '—');
    setText('roTotalWeight', totalWeight > 0 ? totalWeight.toFixed(3) : '—');

    /* ── TOTAL figures for the full challan ── */
    var fullGst   = (!isRCM && gstRate > 0) ? r2(freight * gstRate / 100) : 0;
    var fullTds   = tdsOk ? r2(freight * tdsRate / 100) : 0;
    var totalDue  = r2(freight + fullGst - fullTds);   /* grand total owed by company */

    /* ── What has already been paid (net, non-cancelled) ── */
    var pmts = despatchPayments[parseInt(opt.value)] || [];
    var alreadyPaidNet = 0;
    pmts.forEach(function(p) {
        if (p.status !== 'Cancelled') alreadyPaidNet += pf(p.net_payable);
    });
    alreadyPaidNet = r2(alreadyPaidNet);

    /* remaining freight base still owed */
    var remBase   = Math.max(0, r2(freight - paidBase));
    /* remaining net balance = totalDue - alreadyPaidNet */
    var balance   = Math.max(0, r2(totalDue - alreadyPaidNet));

    D = { freight, paidBase, gstOnHold, gstType, gstRate, tdsOk, tdsRate,
          isRCM, fullGst, fullTds, totalDue, alreadyPaidNet, remBase, balance,
          ratePerKg, totalWeight };

    /* ── Populate read-only Tax & Amount section ── */
    setText('roFreight',   '₹' + fmt(freight));

    /* GST label + amount */
    var gstLbl = isRCM ? '+ GST (RCM — Nil)' :
                 gstType === 'Central' ? '+ IGST (' + gstRate + '%)' :
                 '+ GST CGST+SGST (' + gstRate + '%)';
    setText('roGstLabel', gstLbl);
    setText('roGstAmt',   isRCM ? '0.00' : fmt(fullGst));
    setText('roGstDesc',  (!isRCM && gstRate > 0) ? gstRate + '% on ₹' + fmt(freight) : (isRCM ? 'Reverse Charge — Nil' : ''));

    /* TDS */
    el('roTdsWrap').style.display = tdsOk ? '' : 'none';
    setText('roTdsAmt',  tdsOk ? fmt(fullTds) : '0.00');
    setText('roTdsDesc', tdsOk ? tdsRate + '% TDS on ₹' + fmt(freight) : '');

    /* Totals */
    setText('roTotalDue',  '₹' + fmt(totalDue));
    setText('roTotalPaid', '₹' + fmt(alreadyPaidNet));
    setText('roBalance',   balance > 0.005 ? '₹' + fmt(balance) : '₹0.00 (Cleared)');
    el('roBalance').style.color = balance > 0.005 ? '#dc3545' : '#198754';

    /* GST on hold banner */
    el('roGstHoldWrap').style.display   = gstOnHold > 0.005 ? 'block' : 'none';
    el('gstReleaseNotice').style.display = 'none';
    setText('roGstOnHold', '₹' + fmt(gstOnHold));

    /* GST hold toggle — show only if there IS GST (non-RCM) on remaining base */
    var remGst = (!isRCM && gstRate > 0) ? r2(remBase * gstRate / 100) : 0;
    el('gstHoldToggleWrap').style.display = (remGst > 0 && !gstRelMode) ? 'block' : 'none';

    /* Default amount = remaining balance */
    maxAllowed = gstRelMode ? gstOnHold : remBase;
    var amtEl = el('amountThisPayment');
    if (amtEl) {
        amtEl.value = gstRelMode ? fmt(gstOnHold) : (remBase > 0.005 ? fmt(remBase) : '');
    }
    setText('amtHint', gstRelMode
        ? 'Max: ₹' + fmt(gstOnHold) + ' (held GST)'
        : (remBase > 0.005 ? 'Max: ₹' + fmt(remBase) + ' (remaining freight)' : 'Freight fully paid'));

    updateHidden();
    onAmountInput();
    renderPriorPayments(parseInt(opt.value));

    <?php if (!empty($_GET['release_gst'])): ?>
    if (!gstRelMode && gstOnHold > 0.005) activateGstRelease();
    <?php endif; ?>
}

/* ── GST Release mode ── */
function activateGstRelease() {
    if (!D) return;
    gstRelMode = true;
    el('fIsGstRelease').value = 'Yes';
    el('gstReleaseNotice').style.display = 'block';
    el('roGstHoldWrap').style.display    = 'none';
    el('gstHoldToggleWrap').style.display = 'none';
    var ptSel = el('paymentTypeSel');
    if (ptSel) ptSel.value = 'Release GST';
    setText('amtLabel', 'Release GST Amount (₹) *');
    setText('roGstReleaseAmt', '₹' + fmt(D.gstOnHold));
    maxAllowed = D.gstOnHold;
    var amtEl = el('amountThisPayment');
    if (amtEl) amtEl.value = fmt(D.gstOnHold);
    setText('amtHint', 'Max: ₹' + fmt(D.gstOnHold) + ' (GST currently on hold)');
    onAmountInput();
}

function deactivateGstRelease() {
    gstRelMode = false;
    el('fIsGstRelease').value = 'No';
    el('gstReleaseNotice').style.display = 'none';
    var ptSel = el('paymentTypeSel');
    if (ptSel && (ptSel.value === 'Release GST' || ptSel.value === 'GST Release')) ptSel.value = 'Full Settlement';
    setText('amtLabel', 'Amount Being Paid (₹) *');
    if (D) {
        el('roGstHoldWrap').style.display = D.gstOnHold > 0.005 ? 'block' : 'none';
        maxAllowed = D.remBase;
        var amtEl = el('amountThisPayment');
        if (amtEl) amtEl.value = D.remBase > 0.005 ? fmt(D.remBase) : '';
        setText('amtHint', D.remBase > 0.005 ? 'Max: ₹' + fmt(D.remBase) + ' (remaining freight)' : 'Freight fully paid');
        var remGst = (!D.isRCM && D.gstRate > 0) ? r2(D.remBase * D.gstRate / 100) : 0;
        el('gstHoldToggleWrap').style.display = remGst > 0 ? 'block' : 'none';
    }
    onAmountInput();
}

/* ── Payment type change ── */
function onPaymentTypeChange() {
    var pt = el('paymentTypeSel') ? el('paymentTypeSel').value : '';
    if (pt === 'Release GST' || pt === 'GST Release') {
        if (!gstRelMode) activateGstRelease();
    } else {
        if (gstRelMode) deactivateGstRelease();
    }
    onAmountInput();
}

/* ── GST Hold toggle ── */
function onGstHoldChange() {
    var held = el('gstHoldToggle') && el('gstHoldToggle').value === 'Yes';
    el('fGstHeld').value = held ? 'Yes' : 'No';
    var note = el('gstHoldNote');
    if (note) note.style.display = held ? 'block' : 'none';
    onAmountInput();
}

/* ── Amount input → compute net this transaction ── */
function onAmountInput() {
    if (!D) return;
    var amtEl   = el('amountThisPayment');
    var netWrap = el('netThisWrap');
    if (!amtEl) return;
    var amt = pf(amtEl.value);

    var netThis, breakdown;
    if (gstRelMode) {
        netThis   = amt;
        breakdown = 'Release GST — no freight, no TDS';
        maxAllowed = D.gstOnHold;
    } else {
        var held    = el('gstHoldToggle') && el('gstHoldToggle').value === 'Yes';
        var pt      = el('paymentTypeSel') ? el('paymentTypeSel').value : '';
        var isPartialMode = ['Partial', 'Advance', 'Against LR'].indexOf(pt) !== -1;
        var tdsThis = D.tdsOk ? r2(amt * D.tdsRate / 100) : 0;

        if (held) {
            var gstThis = (!D.isRCM && D.gstRate > 0) ? r2(amt * D.gstRate / 100) : 0;
            netThis     = r2(amt - tdsThis);
            breakdown   = '₹' + fmt(amt)
                + (gstThis > 0 ? ' + GST ₹' + fmt(gstThis) + ' (held for compliance)' : '')
                + (tdsThis > 0 ? ' − TDS ₹' + fmt(tdsThis) : '');
        } else if (isPartialMode) {
            netThis     = r2(amt - tdsThis);
            breakdown   = '₹' + fmt(amt)
                + (tdsThis > 0 ? ' − TDS ₹' + fmt(tdsThis) : '')
                + ' (On-account freight; GST remains due on balance)';
        } else {
            var gstThis = (!D.isRCM && D.gstRate > 0) ? r2(amt * D.gstRate / 100) : 0;
            netThis     = r2(amt + gstThis - tdsThis);
            breakdown   = '₹' + fmt(amt)
                + (gstThis > 0 ? ' + GST ₹' + fmt(gstThis) : '')
                + (tdsThis > 0 ? ' − TDS ₹' + fmt(tdsThis) : '');
        }
        maxAllowed = D.remBase;
    }

    if (netWrap) netWrap.style.display = amt > 0 ? 'block' : 'none';
    setText('netThisPayment',   fmt(netThis));
    setText('netThisBreakdown', breakdown);

    /* Overpayment guard */
    var warn = el('overPayWarn');
    var msg  = el('overPayMsg');
    if (amt > maxAllowed + 0.005) {
        if (warn) warn.style.display = 'block';
        if (msg)  msg.textContent = 'Amount ₹' + fmt(amt) + ' exceeds maximum allowed ₹' + fmt(maxAllowed) + '. Total payments cannot exceed total due.';
    } else {
        if (warn) warn.style.display = 'none';
    }
}

function updateHidden() {
    if (D) {
        el('fGstType').value = D.gstType || '';
        el('fGstRate').value = D.gstRate || 0;
        el('fTdsRate').value = D.tdsOk ? D.tdsRate : 0;
    }
}

/* ── Render prior payments table (ALL statuses) ── */
function renderPriorPayments(despatchId) {
    var wrap  = el('priorPaymentsWrap');
    var tbody = el('priorPaymentsBody');
    var tfoot = el('priorPaymentsFoot');
    if (!wrap || !tbody) return;
    var pmts = despatchPayments[despatchId] || [];

    if (!pmts.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3"><i class="bi bi-info-circle me-1"></i>No payments recorded yet for this challan.</td></tr>';
        tfoot.innerHTML = '';
        return;
    }

    /* Separate active vs cancelled for totals */
    var totBase=0, totGst=0, totTds=0, totNet=0;
    var rows = pmts.map(function(p, i) {
        var held  = p.gst_held      === 'Yes';
        var rel   = p.is_gst_release === 'Yes';
        var canc  = p.status        === 'Cancelled';
        var gstLbl = p.gst_type==='Central' ? 'IGST' : (p.gst_type==='Regular' ? 'CGST+SGST' : 'RCM');
        if (!canc) {
            totBase += pf(p.base_amount);
            totGst  += pf(p.gst_amount);
            totTds  += pf(p.tds_amount);
            totNet  += pf(p.net_payable);
        }
        var sb = p.status==='Paid' ? 'success' : p.status==='Pending' ? 'warning' : 'danger';
        var gstCell = pf(p.gst_amount) > 0
            ? '<span class="' + (held ? 'text-warning' : 'text-success') + '">'
              + '+₹' + fmt(p.gst_amount)
              + '<br><small>' + gstLbl + ' ' + p.gst_rate + '%</small>'
              + (held ? '<br><span class="badge bg-warning text-dark">On Hold</span>' : '')
              + (rel  ? '<br><span class="badge bg-success text-white">Released</span>' : '')
              + '</span>'
            : '—';
        var tdsCell = pf(p.tds_amount) > 0
            ? '<span class="text-danger">−₹' + fmt(p.tds_amount) + '<br><small>TDS ' + p.tds_rate + '%</small></span>'
            : '—';
        return '<tr' + (canc ? ' class="text-decoration-line-through text-muted opacity-75"' : '') + '>'
            + '<td>' + (i+1) + '</td>'
            + '<td><strong>' + esc(p.payment_no) + '</strong>'
              + (rel  ? '<br><span class="badge bg-success">Release GST</span>' : '')
              + (canc ? '<br><span class="badge bg-danger">Cancelled</span>' : '') + '</td>'
            + '<td>' + fmtDate(p.payment_date) + '</td>'
            + '<td><span class="badge bg-light text-dark border">' + esc(p.payment_type) + '</span></td>'
            + '<td class="text-end">₹' + fmt(p.base_amount) + '</td>'
            + '<td class="text-end">' + gstCell + '</td>'
            + '<td class="text-end">' + tdsCell + '</td>'
            + '<td class="text-end fw-bold' + (canc?' text-muted':'') + '">₹' + fmt(p.net_payable) + '</td>'
            + '<td>' + esc(p.payment_mode||'—') + '</td>'
            + '<td><span class="badge bg-' + sb + '">' + esc(p.status) + '</span></td>'
            + '</tr>';
    }).join('');
    tbody.innerHTML = rows;
    tfoot.innerHTML = '<tr class="table-primary">'
        + '<td colspan="4" class="text-end fw-bold">Totals (excl. cancelled):</td>'
        + '<td class="text-end fw-bold">₹' + fmt(totBase) + '</td>'
        + '<td class="text-end fw-bold text-success">₹' + fmt(totGst) + '</td>'
        + '<td class="text-end fw-bold text-danger">−₹' + fmt(totTds) + '</td>'
        + '<td class="text-end fw-bold text-primary">₹' + fmt(totNet) + '</td>'
        + '<td colspan="2"></td></tr>';
}

/* ── Form submit guard ── */
function validatePayment() {
    var amtEl = el('amountThisPayment');
    if (!amtEl) return true;
    var amt = pf(amtEl.value);
    if (amt <= 0) { alert('Please enter an amount greater than 0.'); amtEl.focus(); return false; }
    if (amt > maxAllowed + 0.005) {
        alert('Amount ₹' + fmt(amt) + ' exceeds the allowed maximum of ₹' + fmt(maxAllowed) + '.\nTotal payments cannot exceed total due.');
        amtEl.focus(); return false;
    }
    return true;
}

/* ── UI helpers ── */
function togglePaymentForm() {
    var body = el('paymentFormBody'), icon = el('toggleIcon');
    if (!body) return;
    var hidden = body.style.display === 'none';
    body.style.display = hidden ? 'block' : 'none';
    if (icon) icon.innerHTML = hidden ? '<i class="bi bi-chevron-up"></i>' : '<i class="bi bi-chevron-down"></i>';
}
function scrollToForm() {
    var card = el('recordPaymentCard');
    if (!card) return;
    var body = el('paymentFormBody');
    if (body) body.style.display = 'block';
    var icon = el('toggleIcon');
    if (icon) icon.innerHTML = '<i class="bi bi-chevron-up"></i>';
    setTimeout(function() { card.scrollIntoView({behavior:'smooth', block:'start'}); }, 50);
}

<?php if (isAdmin()): ?>
function openDueHoldModal(despatchId, challanNo, isHeld, reason) {
    document.getElementById('dueHoldDespatchId').value = despatchId;
    document.getElementById('dueHoldIsRelease').value = isHeld ? '1' : '0';
    document.getElementById('dueHoldChallan').textContent = challanNo || '';
    document.getElementById('dueHoldReason').value = reason || '';
    document.getElementById('dueHoldReason').disabled = !!isHeld;
    document.getElementById('dueHoldHelp').textContent = isHeld
        ? 'Release will remove the hold flag from this transporter due.'
        : 'This will mark the transporter due as on hold for admin reference.';
    document.getElementById('dueHoldSaveBtn').innerHTML = isHeld
        ? '<i class="bi bi-unlock me-1"></i>Release Hold'
        : '<i class="bi bi-check2 me-1"></i>Save Hold';
    new bootstrap.Modal(document.getElementById('dueHoldModal')).show();
}

function saveDueHold() {
    var despatchId = document.getElementById('dueHoldDespatchId').value;
    var isRelease = document.getElementById('dueHoldIsRelease').value === '1';
    var reason = document.getElementById('dueHoldReason').value.trim();
    if (!despatchId) return;
    if (!isRelease && !reason) {
        alert('Please enter hold reason.');
        return;
    }
    var btn = document.getElementById('dueHoldSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    var fd = new FormData();
    fd.append('ajax_due_hold', '1');
    fd.append('despatch_id', despatchId);
    fd.append('hold', isRelease ? '0' : '1');
    fd.append('reason', reason);
    fetch('transporter_payments.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.ok) {
                bootstrap.Modal.getInstance(document.getElementById('dueHoldModal')).hide();
                location.reload();
            } else {
                alert(res.msg || 'Unable to update hold status.');
                btn.disabled = false;
                btn.innerHTML = isRelease
                    ? '<i class="bi bi-unlock me-1"></i>Release Hold'
                    : '<i class="bi bi-check2 me-1"></i>Save Hold';
            }
        })
        .catch(function() {
            alert('Request failed.');
            btn.disabled = false;
            btn.innerHTML = isRelease
                ? '<i class="bi bi-unlock me-1"></i>Release Hold'
                : '<i class="bi bi-check2 me-1"></i>Save Hold';
        });
}
<?php endif; ?>

/* ── Micro utilities ── */
function el(id)    { return document.getElementById(id); }
function setText(id,v) { var e=el(id); if(e) e.textContent=v; }
function pf(v)     { return parseFloat(v) || 0; }
function r2(v)     { return Math.round(v * 100) / 100; }
function fmt(v)    { return pf(v).toFixed(2); }
function fmtDate(d){ if(!d) return '—'; var p=d.split('-'); return p.length===3 ? p[2]+'/'+p[1]+'/'+p[0] : d; }
function esc(s)    { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.addEventListener('DOMContentLoaded', function() {
    var tSel = el('transporterSel');
    var dSel = el('despatchRef');
    if (tSel && tSel.value) filterDespatchDropdown(tSel.value);
    if (dSel && dSel.value) onDespatchChange(dSel);
    <?php if ($pf_despatch_id > 0): ?>scrollToForm();<?php endif; ?>
});
</script>
<?php include '../includes/footer.php'; ?>
