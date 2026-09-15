<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
requirePerm('transporter_bulk_payments', 'view');

$__uid = (int)($_SESSION['user_id'] ?? 0);
$tp_can_view_all = canViewAll('transporter_payments');
if (!$tp_can_view_all && canAuthorizeTransporterPayments()) {
    $tp_can_view_all = true;
}
$tp_af = $tp_can_view_all ? "" : " AND (d.created_by=$__uid OR d.agent_id=$__uid)";
$tp_bulk_payment_scope = $tp_can_view_all ? "1=1" : "tp.created_by=$__uid";
$tp_bulk_vendor_name_expr = "COALESCE(NULLIF(v.vendor_name,''), d.consignee_name)";

function safeAddColumnBulk($db, $table, $column, $definition) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$column'
        LIMIT 1");
    if (!$exists) {
        throw new RuntimeException($db->error ?: "Could not inspect `$table`.`$column`.");
    }
    if (!$exists->num_rows && !$db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        throw new RuntimeException($db->error ?: "Could not add `$table`.`$column`.");
    }
}

safeAddColumnBulk($db, 'transporter_payments', 'payment_batch_no', "VARCHAR(40) DEFAULT NULL");
safeAddColumnBulk($db, 'transporter_payments', 'base_amount', 'DECIMAL(12,2) DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'gst_type', "VARCHAR(20) DEFAULT ''");
safeAddColumnBulk($db, 'transporter_payments', 'gst_rate', 'DECIMAL(5,2) DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'gst_amount', 'DECIMAL(12,2) DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'gst_held', "ENUM('No','Yes') DEFAULT 'No'");
safeAddColumnBulk($db, 'transporter_payments', 'tds_rate', 'DECIMAL(5,2) DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'tds_amount', 'DECIMAL(12,2) DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'net_payable', 'DECIMAL(12,2) DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'misc_charges', 'DECIMAL(10,2) DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'is_gst_release', "ENUM('No','Yes') DEFAULT 'No'");
safeAddColumnBulk($db, 'transporter_payments', 'created_by', 'INT DEFAULT 0');
safeAddColumnBulk($db, 'transporter_payments', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
safeAddColumnBulk($db, 'despatch_orders', 'transporter_misc_charges', 'DECIMAL(10,2) DEFAULT 0');
safeAddColumnBulk($db, 'despatch_orders', 'transporter_misc_remarks', "VARCHAR(255) DEFAULT ''");
safeAddColumnBulk($db, 'despatch_orders', 'transporter_due_hold', "TINYINT(1) DEFAULT 0");
safeAddColumnBulk($db, 'despatch_orders', 'transporter_due_hold_reason', "TEXT DEFAULT NULL");

(function() use ($db) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $col = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='transporter_payments'
        AND COLUMN_NAME='payment_type' LIMIT 1")->fetch_row();
    if ($col && strtolower($col[0]) === 'enum') {
        if (!$db->query("ALTER TABLE transporter_payments
            MODIFY COLUMN payment_type VARCHAR(50) DEFAULT ''")) {
            throw new RuntimeException($db->error ?: 'Could not update transporter payment type column.');
        }
    }
})();

(function() use ($db) {
    $dbname = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transporter_payments'
        AND COLUMN_NAME='payment_mode' LIMIT 1")->fetch_row();
    if ($dbname && strtolower($dbname[0]) === 'enum') {
        if (!$db->query("ALTER TABLE transporter_payments
            MODIFY COLUMN payment_mode VARCHAR(40) DEFAULT ''")) {
            throw new RuntimeException($db->error ?: 'Could not update transporter payment mode column.');
        }
    }
})();

function generatePaymentBatchNoBulk($db, $payment_date = null) {
    $ts = !empty($payment_date) ? strtotime($payment_date) : time();
    if (!$ts) $ts = time();
    $prefix = 'TPB/' . date('Y/m/', $ts);
    $row = $db->query("SELECT COUNT(DISTINCT payment_batch_no) AS c FROM transporter_payments WHERE payment_batch_no LIKE '{$prefix}%'")->fetch_assoc();
    $next = ((int)($row['c'] ?? 0)) + 1;
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function generatePaymentBatchNo($db, $payment_date = null) {
    return generatePaymentBatchNoBulk($db, $payment_date);
}

function generatePaymentNoBulk($db, $payment_date = null) {
    $ts = !empty($payment_date) ? strtotime((string)$payment_date) : time();
    if (!$ts) $ts = time();
    $year = date('Y', $ts);
    $month = date('m', $ts);
    $prefix = "TP/{$year}/{$month}/";
    $prefix_sql = $db->real_escape_string($prefix);
    $row = $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(payment_no, " . (strlen($prefix) + 1) . ") AS UNSIGNED)),0) AS c
        FROM transporter_payments
        WHERE payment_no LIKE '{$prefix_sql}%'")->fetch_assoc();
    $next = ((int)($row['c'] ?? 0)) + 1;
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function bulkPaymentBaseAmount(array $row): float {
    $base = (float)($row['base_amount'] ?? 0);
    $gst = (float)($row['gst_amount'] ?? 0);
    $tds = (float)($row['tds_amount'] ?? 0);
    if (abs($base) < 0.005 && abs($gst) < 0.005 && abs($tds) < 0.005 && isset($row['amount'])) {
        return (float)$row['amount'];
    }
    return $base;
}

function bulkPaymentFreightAmount(array $row): float {
    return round(bulkPaymentBaseAmount($row) + (float)($row['gst_amount'] ?? 0), 2);
}

function bulkPaymentNetPayable(array $row): float {
    $net = (float)($row['net_payable'] ?? 0);
    $misc = (float)($row['misc_charges'] ?? 0);
    $tp_misc = (float)($row['tp_misc_charges'] ?? 0);
    if ($misc > 0 && abs($tp_misc) < 0.005) {
        $base = bulkPaymentBaseAmount($row);
        $gst = (($row['gst_held'] ?? 'No') === 'Yes') ? 0 : (float)($row['gst_amount'] ?? 0);
        $tds = (float)($row['tds_amount'] ?? 0);
        return round($base + $gst - $tds + $misc, 2);
    }
    if (abs($net) < 0.005 && isset($row['amount'])) {
        return (float)$row['amount'];
    }
    return $net;
}

function fetchBulkDueRows($db, $tp_af) {
    global $tp_bulk_vendor_name_expr;
    $res = $db->query("
        SELECT d.id, d.challan_no, d.despatch_no, d.despatch_date,
            d.freight_inv_no,
            {$tp_bulk_vendor_name_expr} AS vendor_name, d.consignee_city, d.status AS despatch_status,
            d.transporter_due_hold, d.transporter_due_hold_reason,
            d.lr_number, d.freight_amount, d.freight_paid_by,
            COALESCE(d.transporter_misc_charges, 0) AS transporter_misc_charges,
            COALESCE(d.transporter_misc_remarks, '') AS transporter_misc_remarks,
            d.agent_id, au.full_name AS agent_name,
            t.transporter_name, t.id AS transporter_id,
            t.gst_type, t.gst_rate, t.tds_applicable, t.tds_rate,
            COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.base_amount ELSE 0 END),0) AS paid_base,
            COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN COALESCE(tp.misc_charges, 0) ELSE 0 END),0) AS paid_misc,
            COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.gst_amount ELSE 0 END),0) AS paid_gst_total,
            COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.gst_held='Yes' THEN tp.gst_amount ELSE 0 END),0) AS gst_on_hold,
            COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.is_gst_release='Yes' THEN tp.gst_amount ELSE 0 END),0) AS gst_released
        FROM despatch_orders d
        LEFT JOIN transporters t ON d.transporter_id = t.id
        LEFT JOIN vendors v ON d.vendor_id = v.id
        LEFT JOIN transporter_payments tp ON tp.despatch_id = d.id
        LEFT JOIN app_users au ON au.id = d.agent_id
        WHERE d.transporter_id IS NOT NULL
          AND d.status = 'Delivered' $tp_af
        GROUP BY d.id
        ORDER BY t.transporter_name ASC, d.despatch_date ASC, d.id ASC
    ");
    if (!$res) {
        error_log("fetchBulkDueRows query failed: " . $db->error);
        return [];
    }
    return $res->fetch_all(MYSQLI_ASSOC);
}

$due_rows = fetchBulkDueRows($db, $tp_af);
$payable_due_rows = [];
$transporter_groups = [];
foreach ($due_rows as $row) {
    $tid = (int)$row['transporter_id'];
    $name = $row['transporter_name'] ?: 'Unknown Transporter';
    $freight = (float)$row['freight_amount'];
    $paid_base = (float)$row['paid_base'];
    $misc_charges = (float)($row['transporter_misc_charges'] ?? 0);
    $paid_misc = (float)($row['paid_misc'] ?? 0);
    $rem_misc = max(0, round($misc_charges - $paid_misc, 2));
    $full_gst = (($row['gst_type'] ?? '') !== 'RCM') ? round($freight * (float)($row['gst_rate'] ?? 0) / 100, 2) : 0;
    $net_gst_hold = max(0, round((float)$row['gst_on_hold'] - (float)$row['gst_released'], 2));
    $paid_gst_excl_hold = max(0, round((float)$row['paid_gst_total'] - (float)$row['gst_on_hold'], 2));
    $gst_due = max(0, round($full_gst - $paid_gst_excl_hold - $net_gst_hold, 2));
    $rem_base = max(0, round($freight - $paid_base, 2));
    $bal_total = $rem_base + $net_gst_hold + $gst_due + $rem_misc;
    $release_only = $rem_base <= 0.009 && $rem_misc <= 0.009 && $net_gst_hold > 0.009;
    $gst_balance_only = $rem_base <= 0.009 && $rem_misc <= 0.009 && !$release_only && $gst_due > 0.009;
    $selectable = $bal_total > 0.009;
    if (!$selectable) {
        continue;
    }

    $row['_full_gst'] = $full_gst;
    $row['_net_gst_hold'] = $net_gst_hold;
    $row['_paid_gst_excl_hold'] = $paid_gst_excl_hold;
    $row['_gst_due'] = $gst_due;
    $row['_rem_base'] = $rem_base;
    $row['_rem_misc'] = $rem_misc;
    $row['_misc_remarks'] = (string)($row['transporter_misc_remarks'] ?? '');
    $row['_bal_total'] = $bal_total;
    $row['_release_only'] = $release_only;
    $row['_gst_balance_only'] = $gst_balance_only;
    $row['_selectable'] = $selectable;
    $payable_due_rows[] = $row;

    if (!isset($transporter_groups[$tid])) {
        $transporter_groups[$tid] = [
            'id' => $tid,
            'name' => $name,
            'rows' => [],
            'selectable_count' => 0,
            'total_balance' => 0,
            'total_rem_base' => 0,
        ];
    }
    $transporter_groups[$tid]['rows'][] = $row;
    $transporter_groups[$tid]['total_balance'] += $bal_total;
    $transporter_groups[$tid]['total_rem_base'] += $rem_base;
    if ($selectable) $transporter_groups[$tid]['selectable_count']++;
}
$due_rows = $payable_due_rows;

$selected_transporter_id = (int)($_POST['transporter_id'] ?? $_GET['transporter_id'] ?? 0);
if ($selected_transporter_id <= 0 && !empty($transporter_groups)) {
    $selected_transporter_id = (int)array_key_first($transporter_groups);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bulk_payment'])) {
    requirePerm('transporter_bulk_payments', 'create');

    $selected_transporter_id = (int)($_POST['transporter_id'] ?? 0);
    $payment_date = sanitize($_POST['payment_date'] ?? date('Y-m-d'));
    $remarks = sanitize($_POST['remarks'] ?? '');
    $status = 'Pending';
    $settlement_mode = (($_POST['settlement_mode'] ?? 'full') === 'partial') ? 'partial' : 'full';
    $settlement_amount_input = (float)($_POST['settlement_amount'] ?? 0);

    $selected_ids = array_map('intval', $_POST['selected_despatch'] ?? []);
    $hold_gst_map = $_POST['hold_gst'] ?? [];

    $errors = [];
    if ($selected_transporter_id <= 0) $errors[] = 'Please select a transporter.';
    if (empty($selected_ids)) $errors[] = 'Please select at least one challan.';
    if ($settlement_mode === 'partial' && $settlement_amount_input <= 0) {
        $errors[] = 'Please enter a valid settlement amount greater than 0 for partial / on-account payment.';
    }

    $row_index = [];
    foreach ($due_rows as $r) $row_index[(int)$r['id']] = $r;

    $selected_rows = [];
    $skipped_no_balance = 0;
    foreach ($selected_ids as $despatch_id) {
        if (empty($row_index[$despatch_id])) {
            $skipped_no_balance++;
            continue;
        }
        $r = $row_index[$despatch_id];
        if ((int)$r['transporter_id'] !== $selected_transporter_id) {
            $errors[] = 'All selected challans must belong to the same transporter.';
            continue;
        }
        if (empty($r['_selectable'])) {
            $skipped_no_balance++;
            continue;
        }
        $selected_rows[] = $r;
    }

    // Sort selected rows strictly in FIFO order (despatch_date ASC, id ASC)
    usort($selected_rows, function($a, $b) {
        $da = (string)($a['despatch_date'] ?? '');
        $db = (string)($b['despatch_date'] ?? '');
        if ($da !== $db) {
            return strcmp($da, $db);
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    if (empty($errors) && empty($selected_rows)) {
        showAlert('info', 'Selected challan(s) already have no payable balance. The outstanding list has been refreshed.');
        redirect('transporter_bulk_payments.php?transporter_id=' . $selected_transporter_id);
    }

    if (empty($errors) && !empty($selected_rows)) {
        // Precompute full net due for each row
        $computed_rows = [];
        $total_selected_net_due = 0.0;
        foreach ($selected_rows as $r) {
            $despatch_id = (int)$r['id'];
            $is_release_only = !empty($r['_release_only']);
            $is_gst_balance_only = !empty($r['_gst_balance_only']);
            $freight = ($is_release_only || $is_gst_balance_only) ? 0.0 : (float)$r['_rem_base'];
            $misc = ($is_release_only || $is_gst_balance_only) ? 0.0 : (float)($r['_rem_misc'] ?? 0);
            $gst_type = (string)($r['gst_type'] ?? '');
            $gst_rate = (float)($r['gst_rate'] ?? 0);
            $tds_rate = ($is_release_only || $is_gst_balance_only) ? 0.0 : ((($r['tds_applicable'] ?? 'No') === 'Yes') ? (float)($r['tds_rate'] ?? 0) : 0.0);
            $gst_hold = (!$is_release_only && !$is_gst_balance_only && !empty($hold_gst_map[$despatch_id]) && $gst_type !== 'RCM' && $gst_rate > 0) ? 'Yes' : 'No';
            $gst_amount = $is_release_only ? (float)$r['_net_gst_hold'] : ($is_gst_balance_only ? (float)$r['_gst_due'] : (($gst_type !== 'RCM') ? round($freight * $gst_rate / 100, 2) : 0.0));
            $tds_amount = $tds_rate > 0 ? round($freight * $tds_rate / 100, 2) : 0.0;
            $net_payable = ($is_release_only || $is_gst_balance_only) ? $gst_amount : round($freight + ($gst_hold === 'Yes' ? 0.0 : $gst_amount) - $tds_amount + $misc, 2);

            $computed_rows[] = [
                'row' => $r,
                'despatch_id' => $despatch_id,
                'is_release_only' => $is_release_only,
                'is_gst_balance_only' => $is_gst_balance_only,
                'freight' => $freight,
                'misc' => $misc,
                'gst_type' => $gst_type,
                'gst_rate' => $gst_rate,
                'tds_rate' => $tds_rate,
                'gst_hold' => $gst_hold,
                'gst_amount' => $gst_amount,
                'tds_amount' => $tds_amount,
                'net_payable' => $net_payable,
            ];
            $total_selected_net_due = round($total_selected_net_due + $net_payable, 2);
        }

        if ($settlement_mode === 'partial') {
            if ($settlement_amount_input > $total_selected_net_due + 0.005) {
                $errors[] = 'Settlement amount (₹' . number_format($settlement_amount_input, 2) . ') exceeds the total selected dues (₹' . number_format($total_selected_net_due, 2) . ').';
            }
        }

        if (empty($errors)) {
            $batch_no = generatePaymentBatchNo($db);
            $created_by = (int)($_SESSION['user_id'] ?? 0);
            $created_count = 0;
            $fully_settled_count = 0;
            $partially_settled_count = 0;
            $standing_balance_total = 0.0;
            $remaining_budget = ($settlement_mode === 'partial') ? $settlement_amount_input : $total_selected_net_due;
            $total_settled_batch = 0.0;

            $db->begin_transaction();
            try {
                foreach ($computed_rows as $c) {
                    $row_net = (float)$c['net_payable'];
                    if ($remaining_budget <= 0.004) {
                        // Budget exhausted: this bill is skipped and remains 100% standing
                        $standing_balance_total = round($standing_balance_total + $row_net, 2);
                        continue;
                    }

                    $despatch_id = $c['despatch_id'];
                    $is_release_only = $c['is_release_only'];
                    $is_gst_balance_only = $c['is_gst_balance_only'];
                    $gst_type = $c['gst_type'];
                    $gst_rate = $c['gst_rate'];
                    $tds_rate = $c['tds_rate'];
                    $gst_hold = $c['gst_hold'];
                    $is_gst_release = $is_release_only ? 'Yes' : 'No';

                    if ($remaining_budget >= $row_net - 0.005) {
                        // Full settlement for this row
                        $alloc_net = $row_net;
                        $remaining_budget = max(0.0, round($remaining_budget - $alloc_net, 2));
                        $alloc_freight = $c['freight'];
                        $alloc_gst = $c['gst_amount'];
                        $alloc_tds = $c['tds_amount'];
                        $alloc_misc = $c['misc'];
                        $payment_type = $is_release_only ? 'GST Release' : ($is_gst_balance_only ? 'GST Balance' : 'Bulk Settlement');
                        $fully_settled_count++;
                    } else {
                        // Partial settlement for this row
                        $alloc_net = round($remaining_budget, 2);
                        $standing_on_row = max(0.0, round($row_net - $alloc_net, 2));
                        $standing_balance_total = round($standing_balance_total + $standing_on_row, 2);
                        $remaining_budget = 0.0;
                        $partially_settled_count++;
                        $payment_type = 'Partial Settlement';

                        if ($is_release_only || $is_gst_balance_only) {
                            $alloc_freight = 0.0;
                            $alloc_misc = 0.0;
                            $alloc_gst = $alloc_net;
                            $alloc_tds = 0.0;
                        } else {
                            $ratio = ($row_net > 0.001) ? ($alloc_net / $row_net) : 0.0;
                            $alloc_misc = ($c['misc'] > 0) ? round($c['misc'] * $ratio, 2) : 0.0;
                            $net_for_freight = max(0.0, round($alloc_net - $alloc_misc, 2));
                            $gst_mult = ($gst_hold === 'Yes' || $gst_type === 'RCM') ? 0.0 : ($gst_rate / 100);
                            $tds_mult = $tds_rate / 100;
                            $eff_mult = 1.0 + $gst_mult - $tds_mult;

                            if ($eff_mult > 0.001) {
                                $alloc_freight = min((float)$c['freight'], max(0.0, round($net_for_freight / $eff_mult, 2)));
                            } else {
                                $alloc_freight = min((float)$c['freight'], $net_for_freight);
                            }
                            $alloc_gst = ($gst_type !== 'RCM' && $gst_rate > 0) ? round($alloc_freight * $gst_rate / 100, 2) : 0.0;
                            $alloc_tds = ($tds_rate > 0) ? round($alloc_freight * $tds_rate / 100, 2) : 0.0;

                            // Reconcile penny difference to match exact alloc_net
                            $calc_net = round($alloc_freight + ($gst_hold === 'Yes' ? 0.0 : $alloc_gst) - $alloc_tds + $alloc_misc, 2);
                            $diff = round($alloc_net - $calc_net, 2);
                            if (abs($diff) > 0.001 && abs($diff) < 1.0) {
                                $alloc_freight = max(0.0, round($alloc_freight + $diff, 2));
                                if ($gst_type !== 'RCM' && $gst_rate > 0) {
                                    $alloc_gst = round($alloc_freight * $gst_rate / 100, 2);
                                }
                                if ($tds_rate > 0) {
                                    $alloc_tds = round($alloc_freight * $tds_rate / 100, 2);
                                }
                            }
                        }
                    }

                    $total_settled_batch = round($total_settled_batch + $alloc_net, 2);
                    $payment_no = generatePaymentNoBulk($db, $payment_date);

                    $esc = fn($v) => $db->real_escape_string($v);
                    $sql = "INSERT INTO transporter_payments
                        (payment_no, payment_batch_no, payment_date, transporter_id, despatch_id, payment_type,
                         amount, base_amount, gst_type, gst_rate, gst_amount, gst_held, is_gst_release,
                         tds_rate, tds_amount, net_payable, misc_charges, payment_mode, reference_no, bank_name,
                         remarks, status, created_by)
                        VALUES
                        ('{$esc($payment_no)}', '{$esc($batch_no)}', '{$esc($payment_date)}', $selected_transporter_id, $despatch_id, '{$esc($payment_type)}',
                         $alloc_net, $alloc_freight, '{$esc($gst_type)}', $gst_rate, $alloc_gst, '{$esc($gst_hold)}', '{$esc($is_gst_release)}',
                         $tds_rate, $alloc_tds, $alloc_net, $alloc_misc, '', '', '',
                         '{$esc($remarks)}', '{$esc($status)}', $created_by)";
                    if (!$db->query($sql)) {
                        throw new RuntimeException($db->error ?: 'Database insert failed.');
                    }
                    $created_count++;
                }

                $db->commit();
                $skip_note = $skipped_no_balance > 0 ? " {$skipped_no_balance} already-settled challan(s) were skipped." : '';
                if ($settlement_mode === 'partial') {
                    $msg = "Bulk payment batch {$batch_no} recorded: ₹" . number_format($total_settled_batch, 2) . " settled across {$created_count} challan(s) ({$fully_settled_count} fully settled, {$partially_settled_count} partially settled). Remaining balance standing: ₹" . number_format($standing_balance_total, 2) . "." . $skip_note;
                } else {
                    $msg = "Bulk payment batch {$batch_no} recorded for {$created_count} challan(s) totaling ₹" . number_format($total_settled_batch, 2) . "." . $skip_note;
                }
                showAlert('success', $msg);
                redirect('transporter_bulk_payments.php?transporter_id=' . $selected_transporter_id . '&batch=' . urlencode($batch_no));
            } catch (Throwable $e) {
                $db->rollback();
                showAlert('danger', 'Bulk payment could not be saved. ' . htmlspecialchars($e->getMessage()));
            }
        } else {
            showAlert('danger', implode('<br>', $errors));
        }
    } else {
        showAlert('danger', implode('<br>', $errors));
    }
}

$selected_group = ($selected_transporter_id > 0 && isset($transporter_groups[$selected_transporter_id]))
    ? $transporter_groups[$selected_transporter_id]
    : null;

$total_transporters = count($transporter_groups);
$total_selectable = 0;
$grand_balance = 0;
foreach ($transporter_groups as $grp) {
    $total_selectable += $grp['selectable_count'];
    $grand_balance += $grp['total_balance'];
}

$active_batch_no = trim((string)($_GET['batch'] ?? ''));
$active_batch = null;
if ($active_batch_no !== '') {
    $batch_sql = $db->real_escape_string($active_batch_no);
    $batch_rows = $db->query("
        SELECT tp.payment_batch_no, tp.payment_no, tp.payment_date, tp.payment_mode, tp.reference_no,
               tp.bank_name, tp.remarks, tp.status, tp.base_amount, tp.gst_amount, tp.gst_held,
               tp.tds_amount, tp.net_payable, tp.created_at,
               tp.misc_charges AS tp_misc_charges,
               GREATEST(COALESCE(d.transporter_misc_charges, 0), COALESCE(tp.misc_charges, 0)) AS misc_charges,
               COALESCE(NULLIF(d.transporter_misc_remarks, ''), '') AS misc_remarks,
               t.transporter_name, d.challan_no, d.freight_inv_no, d.despatch_date, {$tp_bulk_vendor_name_expr} AS vendor_name, d.consignee_city
        FROM transporter_payments tp
        LEFT JOIN transporters t ON tp.transporter_id = t.id
        LEFT JOIN despatch_orders d ON d.id = tp.despatch_id
        LEFT JOIN vendors v ON d.vendor_id = v.id
        WHERE tp.payment_batch_no = '$batch_sql' AND $tp_bulk_payment_scope
        ORDER BY tp.id ASC
    ")->fetch_all(MYSQLI_ASSOC);
    if (!empty($batch_rows)) {
        $active_batch = [
            'batch_no' => $active_batch_no,
            'rows' => $batch_rows,
            'count' => count($batch_rows),
            'transporter_name' => $batch_rows[0]['transporter_name'] ?? 'Unknown Transporter',
            'payment_date' => $batch_rows[0]['payment_date'] ?? '',
            'payment_mode' => $batch_rows[0]['payment_mode'] ?? '',
            'reference_no' => $batch_rows[0]['reference_no'] ?? '',
            'bank_name' => $batch_rows[0]['bank_name'] ?? '',
            'remarks' => $batch_rows[0]['remarks'] ?? '',
            'total_base' => array_sum(array_map(fn($r) => bulkPaymentBaseAmount($r), $batch_rows)),
            'total_freight' => array_sum(array_map(fn($r) => bulkPaymentFreightAmount($r), $batch_rows)),
            'total_gst' => array_sum(array_map(fn($r) => (float)($r['gst_amount'] ?? 0), $batch_rows)),
            'total_tds' => array_sum(array_map(fn($r) => (float)($r['tds_amount'] ?? 0), $batch_rows)),
            'total_misc' => array_sum(array_map(fn($r) => (float)($r['misc_charges'] ?? 0), $batch_rows)),
            'total_net' => array_sum(array_map(fn($r) => bulkPaymentNetPayable($r), $batch_rows)),
        ];
    }
}
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-collection me-2"></i>Bulk Transporter Payments';</script>

<div class="tp-hero-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-white bg-opacity-25 text-white"><i class="bi bi-cash-stack me-1"></i>Finance &amp; Freight</span>
            <span class="badge bg-success-subtle text-success fw-semibold">Batch Processing</span>
        </div>
        <h4 class="mb-0 fw-bold text-white"><i class="bi bi-collection-fill me-2"></i>Bulk Transporter Payments</h4>
        <small class="text-white text-opacity-75">Settle multiple challans in a single batch, create payment batches, and print transporter advice</small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="transporter_payments.php?action=list" class="tp-module-pill">
            <i class="bi bi-receipt-cutoff"></i>
            <span>Payment Register</span>
        </a>
        <a href="transporter_bulk_payments.php" class="tp-module-pill active">
            <i class="bi bi-collection-fill"></i>
            <span>Bulk Settlement</span>
        </a>
    </div>
</div>

<div class="alert alert-info py-2 px-3 mb-4 rounded-3 border d-flex align-items-center gap-2">
    <i class="bi bi-info-circle-fill text-info fs-5 flex-shrink-0"></i>
    <div class="small">
        This module creates <strong>challan-wise pending payment records</strong> under one shared batch number. Payment mode, UTR/reference, and bank details can be filled during authorisation in the Payment Register.
    </div>
</div>

<?php if ($active_batch): ?>
<div class="card border-0 shadow-sm mb-4 overflow-hidden rounded-3">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 px-3"
         style="background: linear-gradient(135deg, #065f46 0%, #059669 100%) !important;">
        <div class="fw-bold fs-6"><i class="bi bi-check2-circle me-2"></i>Saved Batch: <?= htmlspecialchars($active_batch['batch_no']) ?></div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (canAuthorizeTransporterPayments() && trim((string)($active_batch['payment_mode'] ?? '')) !== ''): ?>
            <a href="print_bulk_payment_advice.php?batch=<?= urlencode($active_batch['batch_no']) ?>"
               target="_blank" class="btn btn-sm btn-light fw-semibold">
                <i class="bi bi-printer me-1"></i>Print Advice
            </a>
            <?php endif; ?>
            <a href="transporter_bulk_payments.php?transporter_id=<?= (int)$selected_transporter_id ?>" class="btn btn-sm btn-light bg-opacity-75">
                <i class="bi bi-x-lg me-1"></i>Close
            </a>
        </div>
    </div>
    <div class="card-body p-3">
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-2">
                <div class="small text-muted fw-semibold">Transporter</div>
                <div class="fw-bold text-dark"><?= htmlspecialchars($active_batch['transporter_name']) ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted fw-semibold">Payment Date</div>
                <div class="fw-semibold text-secondary"><?= !empty($active_batch['payment_date']) ? date('d/m/Y', strtotime($active_batch['payment_date'])) : '—' ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted fw-semibold">Payment Mode</div>
                <div class="fw-semibold"><?= htmlspecialchars($active_batch['payment_mode'] ?: '—') ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted fw-semibold">Challans</div>
                <div class="fw-bold text-primary"><?= number_format($active_batch['count']) ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted fw-semibold">Net Payable</div>
                <div class="fw-bold text-success fs-6">₹<?= number_format($active_batch['total_net'], 2) ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted fw-semibold">Reference</div>
                <div class="fw-semibold"><?= htmlspecialchars($active_batch['reference_no'] ?: '—') ?></div>
            </div>
            <?php if (!empty($active_batch['bank_name'])): ?>
            <div class="col-12 col-md-4">
                <div class="small text-muted fw-semibold">Bank</div>
                <div class="fw-semibold"><?= htmlspecialchars($active_batch['bank_name']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($active_batch['remarks'])): ?>
            <div class="col-12 col-md-8">
                <div class="small text-muted fw-semibold">Remarks</div>
                <div class="fw-semibold"><?= htmlspecialchars($active_batch['remarks']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Payment No</th>
                        <th>Challan</th>
                        <th>Vendor</th>
                        <th class="text-end">Freight Amount</th>
                        <th class="text-end">GST</th>
                        <th class="text-end">TDS</th>
                        <th class="text-end">Misc</th>
                        <th class="text-end">Net</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_batch['rows'] as $idx => $row): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><span class="fw-semibold text-primary"><?= htmlspecialchars($row['payment_no']) ?></span></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($row['challan_no'] ?: '—') ?></div>
                            <?php if (!empty($row['freight_inv_no'])): ?><div><span class="fi-badge">FI: <?= htmlspecialchars($row['freight_inv_no']) ?></span></div><?php endif; ?>
                            <?php if (!empty($row['despatch_date'])): ?><div class="small text-muted"><?= date('d/m/Y', strtotime($row['despatch_date'])) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($row['vendor_name'] ?: '—') ?></div>
                            <?php if (!empty($row['consignee_city'])): ?><div class="small text-muted"><?= htmlspecialchars($row['consignee_city']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-end">₹<?= number_format(bulkPaymentFreightAmount($row), 2) ?></td>
                        <td class="text-end">
                            ₹<?= number_format((float)$row['gst_amount'], 2) ?>
                            <?php if (($row['gst_held'] ?? 'No') === 'Yes'): ?><br><small class="text-warning">GST on Hold</small><?php endif; ?>
                        </td>
                        <td class="text-end">₹<?= number_format((float)$row['tds_amount'], 2) ?></td>
                        <td class="text-end">
                            <?php if ((float)$row['misc_charges'] > 0): ?>
                                <span class="fw-semibold text-primary">₹<?= number_format((float)$row['misc_charges'], 2) ?></span>
                                <?php if (!empty($row['misc_remarks'])): ?><br><small class="text-muted"><?= htmlspecialchars($row['misc_remarks']) ?></small><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-bold text-success">₹<?= number_format(bulkPaymentNetPayable($row), 2) ?></td>
                        <td><span class="badge bg-warning"><?= htmlspecialchars($row['status'] ?: 'Pending') ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Totals:</td>
                        <td class="text-end">₹<?= number_format($active_batch['total_freight'], 2) ?></td>
                        <td class="text-end">₹<?= number_format($active_batch['total_gst'], 2) ?></td>
                        <td class="text-end">₹<?= number_format($active_batch['total_tds'], 2) ?></td>
                        <td class="text-end">₹<?= number_format($active_batch['total_misc'], 2) ?></td>
                        <td class="text-end text-success">₹<?= number_format($active_batch['total_net'], 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
            $batch_is_authorised = trim((string)($active_batch['payment_mode'] ?? '')) !== '';
            $can_print_advice = canAuthorizeTransporterPayments();
        ?>
        <?php if ($batch_is_authorised && $can_print_advice): ?>
        <div class="alert alert-success mt-3 mb-0 py-2 d-flex align-items-center gap-3 rounded-3">
            <i class="bi bi-check2-circle fs-5 text-success"></i>
            <div class="flex-grow-1">
                <strong>Batch Authorised</strong> — Payment mode: <span class="badge bg-success"><?= htmlspecialchars($active_batch['payment_mode']) ?></span>
                <?php if (!empty($active_batch['reference_no'])): ?>
                &nbsp;&nbsp;Ref: <strong><?= htmlspecialchars($active_batch['reference_no']) ?></strong>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <a href="print_bulk_payment_advice.php?batch=<?= urlencode($active_batch['batch_no']) ?>&lang=en"
                   target="_blank" class="btn btn-success btn-sm fw-semibold">
                    <i class="bi bi-printer me-1"></i>Print Advice (EN)
                </a>
                <a href="print_bulk_payment_advice.php?batch=<?= urlencode($active_batch['batch_no']) ?>&lang=hi"
                   target="_blank" class="btn btn-outline-success btn-sm fw-semibold">
                    <i class="bi bi-printer me-1"></i>प्रिंट सूचना (HI)
                </a>
            </div>
        </div>
        <?php elseif ($can_print_advice && !$batch_is_authorised): ?>
        <div class="alert alert-warning mt-3 mb-0 py-2 d-flex align-items-center gap-3 rounded-3">
            <i class="bi bi-hourglass-split text-warning fs-5"></i>
            <div class="flex-grow-1">
                <strong>Pending Authorisation</strong> — Enter payment mode, UTR/reference, and bank details in
                <a href="transporter_payments.php?action=list" class="alert-link fw-semibold">Payment Register</a>
                to authorise this batch, then print the advice here.
        <?php else: ?>
        <div class="alert alert-warning mt-3 mb-0 py-2 rounded-3">
            <i class="bi bi-hourglass-split me-2"></i>
            Payment advice will be available after this batch is authorised in Payment Register with payment mode, reference, and bank details.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Summary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-month p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Transporters with Due</div>
                    <h4 class="fw-bold text-primary mb-0 mt-1"><?= number_format($total_transporters) ?></h4>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-truck-front-fill"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-primary border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-primary">Active Vendors</span>
                <small class="text-muted">With Dues</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-paid p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Selectable Challans</div>
                    <h4 class="fw-bold text-success mb-0 mt-1"><?= number_format($total_selectable) ?></h4>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-check2-square"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-success border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-success">Delivered</span>
                <small class="text-muted">Ready to Batch</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-outstanding p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Total Outstanding</div>
                    <h4 class="fw-bold text-danger mb-0 mt-1">₹<?= number_format($grand_balance,2) ?></h4>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-danger border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-danger">Pending Balance</span>
                <small class="text-muted">All Transporters</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card kpi-hold p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Current Transporter</div>
                    <h5 class="fw-bold text-warning text-truncate mb-0 mt-1" style="color:#b45309 !important" title="<?= $selected_group ? htmlspecialchars($selected_group['name']) : 'None' ?>">
                        <?= $selected_group ? htmlspecialchars($selected_group['name']) : '—' ?>
                    </h5>
                </div>
                <div class="tp-kpi-icon-wrap">
                    <i class="bi bi-building-check"></i>
                </div>
            </div>
            <div class="mt-2 pt-1 border-top border-warning border-opacity-10 d-flex align-items-center justify-content-between">
                <span class="badge badge-soft-warning"><?= $selected_group ? count($selected_group['rows']).' Challans' : 'Select Below' ?></span>
                <small class="text-muted">Target</small>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4 border shadow-sm rounded-3 overflow-hidden">
    <div class="card-header d-flex justify-content-between align-items-center py-2 px-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff;">
        <span class="fw-bold text-white fs-6"><i class="bi bi-truck-front me-2 text-info"></i>1. Select Transporter for Settlement</span>
        <small class="text-white text-opacity-75">Click a transporter card to view &amp; select challans</small>
    </div>
    <div class="card-body p-3">
        <div class="row g-3">
            <?php if (empty($transporter_groups)): ?>
            <div class="col-12"><div class="text-center text-muted py-4"><i class="bi bi-check2-all fs-2 text-success d-block mb-2"></i>No outstanding transporter dues found for bulk settlement.</div></div>
            <?php else: foreach ($transporter_groups as $grp):
                $is_selected = $selected_transporter_id === $grp['id'];
            ?>
            <div class="col-12 col-md-6 col-xl-4">
                <a href="?transporter_id=<?= $grp['id'] ?>" class="text-decoration-none">
                    <div class="card h-100 tp-kpi-card <?= $is_selected ? 'border-primary shadow' : '' ?>"
                         style="<?= $is_selected ? 'border-left: 4px solid #2563eb !important; background: linear-gradient(145deg, #eff6ff 0%, #ffffff 60%);' : 'border-left: 4px solid #cbd5e1;' ?>">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-bold <?= $is_selected ? 'text-primary' : 'text-dark' ?> fs-6"><?= htmlspecialchars($grp['name']) ?></div>
                                    <div class="small text-muted mt-1"><i class="bi bi-check2 me-1"></i><?= $grp['selectable_count'] ?> selectable challan<?= $grp['selectable_count']!=1?'s':'' ?></div>
                                </div>
                                <span class="badge <?= $is_selected ? 'bg-danger text-white' : 'badge-soft-danger' ?> fw-bold">
                                    ₹<?= number_format($grp['total_balance'],2) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php if ($selected_group): ?>
<form method="POST" id="bulkPayForm">
    <input type="hidden" name="save_bulk_payment" value="1">
    <input type="hidden" name="transporter_id" value="<?= $selected_group['id'] ?>">

    <div class="card mb-4 border shadow-sm rounded-3 overflow-hidden">
        <div class="card-header fw-bold text-white py-2 px-3 fs-6" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff;"><i class="bi bi-wallet2 me-2 text-warning"></i>2. Bulk Payment Parameters</div>
        <div class="card-body p-3">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold">Payment Date *</label>
                    <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold">Settlement Mode *</label>
                    <div class="d-flex flex-column gap-1 mt-1">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="settlement_mode" id="settleModeFull" value="full" checked onchange="onSettlementModeChange()">
                            <label class="form-check-label fw-semibold small" for="settleModeFull">Full Settlement (100% of Selected)</label>
                        </div>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="settlement_mode" id="settleModePartial" value="partial" onchange="onSettlementModeChange()">
                            <label class="form-check-label fw-semibold small text-primary" for="settleModePartial">Partial / On Account (FIFO Allocation)</label>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3" id="settleAmountWrap">
                    <label class="form-label fw-semibold" id="settleAmountLabel">Payment Amount (₹) *</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light fw-bold">₹</span>
                        <input type="number" step="0.01" min="0.01" name="settlement_amount" id="settlementAmountInput" class="form-control fw-bold text-success" placeholder="0.00" oninput="onSettlementAmountInput()" readonly>
                    </div>
                    <div class="small text-muted mt-1" id="settleAmountHint">Full amount of selected challans.</div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold">Batch Status</label>
                    <div class="border rounded px-3 py-1 bg-light fw-semibold text-warning" style="font-size:0.9rem">Pending Authorisation</div>
                </div>

                <div class="col-12 col-md-5">
                    <label class="form-label fw-semibold">Settlement Summary</label>
                    <div class="border rounded px-3 py-2 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">Challans Selected:</span>
                            <strong id="selCount" class="text-primary fs-6">0</strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">Total Selected Dues:</span>
                            <strong id="selTotalDues" class="text-dark">₹0.00</strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted fw-semibold">Paying Now (This Batch):</span>
                            <strong id="selNet" class="text-success fs-6">₹0.00</strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-1 border-top" id="standingSummaryWrap">
                            <span class="small text-danger fw-semibold">Remaining Standing Balance:</span>
                            <strong id="selStanding" class="text-danger fs-6">₹0.00</strong>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-7">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Remarks</label>
                            <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Common remarks for this batch">
                        </div>
                        <div class="col-12">
                            <div class="alert alert-light border py-2 px-3 mb-0 small text-muted d-flex align-items-start gap-2" id="fifoLiveExplainer">
                                <i class="bi bi-info-circle text-primary mt-1"></i>
                                <span id="fifoNoteText">Select challans to calculate settlement distribution.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border shadow-sm rounded-3 overflow-hidden mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 px-3"
             style="background: linear-gradient(135deg, #1e293b 0%, #1e3a8a 100%); color: #ffffff;">
            <div class="fw-bold"><i class="bi bi-list-check me-2"></i><?= htmlspecialchars($selected_group['name']) ?> — Select Challans for Batch (FIFO by Date)</div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="badge bg-white text-danger fw-bold">Outstanding ₹<?= number_format($selected_group['total_balance'],2) ?></span>
                <button type="button" class="btn btn-sm btn-light bg-opacity-25 text-white" onclick="toggleAllBulk(true)">Select All</button>
                <button type="button" class="btn btn-sm btn-light bg-opacity-25 text-white" onclick="toggleAllBulk(false)">Clear</button>
                <button type="submit" class="btn btn-sm btn-success fw-bold px-3"><i class="bi bi-check2-circle me-1"></i>Record Bulk Payment</button>
            </div>
        </div>
        <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">Pay</th>
                            <th>Challan / LR</th>
                            <th>Date</th>
                            <th>Vendor</th>
                            <th class="text-end">Remaining Freight</th>
                            <th class="text-end">GST</th>
                            <th class="text-end">TDS</th>
                            <th class="text-end">Misc</th>
                            <th>Hold GST</th>
                            <th class="text-end">Net Due</th>
                            <th class="text-end bg-success-subtle fw-bold">Paying Now (FIFO)</th>
                            <th class="text-end bg-warning-subtle fw-bold">Standing Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selected_group['rows'] as $row):
                            $freight = (float)$row['_rem_base'];
                            $release_only = !empty($row['_release_only']);
                            $gst_balance_only = !empty($row['_gst_balance_only']);
                            $misc = ($release_only || $gst_balance_only) ? 0 : (float)($row['_rem_misc'] ?? 0);
                            $gst_amount = $release_only
                                ? (float)$row['_net_gst_hold']
                                : ($gst_balance_only ? (float)$row['_gst_due'] : ((($row['gst_type'] ?? '') !== 'RCM') ? round($freight * (float)($row['gst_rate'] ?? 0) / 100, 2) : 0));
                            $tds_amount = (!$release_only && !$gst_balance_only && ($row['tds_applicable'] ?? 'No') === 'Yes') ? round($freight * (float)($row['tds_rate'] ?? 0) / 100, 2) : 0;
                            $default_net = ($release_only || $gst_balance_only) ? $gst_amount : round($freight + $gst_amount - $tds_amount + $misc, 2);
                            $row_badge = $release_only ? 'info text-dark' : ($gst_balance_only ? 'primary' : (empty($row['_selectable']) ? 'secondary' : 'success'));
                            $row_label = $release_only ? 'GST Release' : ($gst_balance_only ? 'GST Balance' : (empty($row['_selectable']) ? 'No Balance' : 'Ready'));
                        ?>
                        <tr class="<?= $release_only ? 'table-info' : ($gst_balance_only ? 'table-primary' : (empty($row['_selectable']) ? 'table-secondary' : '')) ?>" id="row_<?= (int)$row['id'] ?>">
                            <td>
                                <?php if (!empty($row['_selectable'])): ?>
                                <input type="checkbox"
                                       class="form-check-input bulk-check"
                                       name="selected_despatch[]"
                                       value="<?= (int)$row['id'] ?>"
                                       data-id="<?= (int)$row['id'] ?>"
                                       data-challan="<?= htmlspecialchars($row['challan_no'] ?: $row['despatch_no'], ENT_QUOTES) ?>"
                                       data-date="<?= htmlspecialchars((string)($row['despatch_date'] ?? ''), ENT_QUOTES) ?>"
                                       data-base="<?= number_format($freight, 2, '.', '') ?>"
                                       data-gst="<?= number_format($gst_amount, 2, '.', '') ?>"
                                       data-tds="<?= number_format($tds_amount, 2, '.', '') ?>"
                                       data-misc="<?= number_format($misc, 2, '.', '') ?>"
                                       data-release="<?= $release_only ? '1' : '0' ?>"
                                       data-gst-balance="<?= $gst_balance_only ? '1' : '0' ?>">
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold text-primary"><?= htmlspecialchars($row['challan_no'] ?: $row['despatch_no']) ?></div>
                                <?php if (!empty($row['freight_inv_no'])): ?><div><span class="fi-badge">FI: <?= htmlspecialchars($row['freight_inv_no']) ?></span></div><?php endif; ?>
                                <?php if (!empty($row['lr_number'])): ?><div class="small text-muted">LR: <?= htmlspecialchars($row['lr_number']) ?></div><?php endif; ?>
                            </td>
                            <td><?= !empty($row['despatch_date']) ? date('d/m/Y', strtotime($row['despatch_date'])) : '—' ?></td>
                            <td>
                                <div><?= htmlspecialchars($row['vendor_name'] ?: '—') ?></div>
                                <?php if (!empty($row['consignee_city'])): ?><div class="small text-muted"><?= htmlspecialchars($row['consignee_city']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold">₹<?= number_format($freight,2) ?></td>
                            <td class="text-end">
                                <?php if ($gst_amount > 0): ?>
                                    ₹<?= number_format($gst_amount,2) ?><br><small class="text-muted"><?= htmlspecialchars($row['gst_type']) ?> <?= number_format((float)($row['gst_rate'] ?? 0),2) ?>%</small>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= $tds_amount > 0 ? '₹' . number_format($tds_amount,2) : '—' ?></td>
                            <td class="text-end">
                                <?php if ($misc > 0): ?>
                                    <span class="text-primary fw-semibold">₹<?= number_format($misc,2) ?></span>
                                    <?php if (!empty($row['_misc_remarks'])): ?><br><small class="text-muted"><?= htmlspecialchars($row['_misc_remarks']) ?></small><?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$release_only && !$gst_balance_only && !empty($row['_selectable']) && $gst_amount > 0): ?>
                                <div class="form-check">
                                    <input class="form-check-input bulk-hold-check" type="checkbox" name="hold_gst[<?= (int)$row['id'] ?>]" value="1" id="hold<?= (int)$row['id'] ?>">
                                    <label class="form-check-label small" for="hold<?= (int)$row['id'] ?>">Hold GST</label>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold" id="netCell<?= (int)$row['id'] ?>">₹<?= number_format($default_net,2) ?></td>
                            <td class="text-end fw-bold text-success bg-success-subtle" id="allocCell<?= (int)$row['id'] ?>">—</td>
                            <td class="text-end fw-bold text-danger bg-warning-subtle" id="standingCell<?= (int)$row['id'] ?>">—</td>
                            <td>
                                <span class="badge bg-<?= $row_badge ?>"><?= $row_label ?></span>
                                <?php if ((float)$row['_net_gst_hold'] > 0): ?><br><small class="text-warning">Held GST ₹<?= number_format((float)$row['_net_gst_hold'],2) ?></small><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

<script>
function fmtMoney(v) {
    return '₹' + (parseFloat(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
}

function onSettlementModeChange() {
    var isPartial = document.getElementById('settleModePartial').checked;
    var input = document.getElementById('settlementAmountInput');
    var hint = document.getElementById('settleAmountHint');
    if (isPartial) {
        input.removeAttribute('readonly');
        input.classList.remove('bg-light');
        hint.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-arrow-down-right me-1"></i>Amount will be distributed FIFO to clear oldest bills first.</span>';
        if (parseFloat(input.value || 0) <= 0) {
            input.focus();
        }
    } else {
        input.setAttribute('readonly', 'readonly');
        input.classList.add('bg-light');
        hint.textContent = 'Full amount of selected challans.';
    }
    updateBulkSummary();
}

function onSettlementAmountInput() {
    updateBulkSummary();
}

function updateBulkSummary() {
    var isPartial = document.getElementById('settleModePartial').checked;
    var amountInput = document.getElementById('settlementAmountInput');
    var checkedBoxes = [];
    var totalSelectedNet = 0;

    document.querySelectorAll('.bulk-check').forEach(function(cb) {
        var id = cb.dataset.id;
        var hold = document.getElementById('hold' + id);
        var base = parseFloat(cb.dataset.base || 0);
        var gst = parseFloat(cb.dataset.gst || 0);
        var tds = parseFloat(cb.dataset.tds || 0);
        var misc = parseFloat(cb.dataset.misc || 0);
        var includeGst = !(hold && hold.checked);
        var net = Math.round((base + (includeGst ? gst : 0) - tds + misc) * 100) / 100;
        
        var cell = document.getElementById('netCell' + id);
        if (cell) cell.textContent = fmtMoney(net);

        if (cb.checked) {
            checkedBoxes.push({
                id: id,
                net: net,
                challan: cb.dataset.challan || ('#' + id),
                date: cb.dataset.date || ''
            });
            totalSelectedNet += net;
        } else {
            var allocCell = document.getElementById('allocCell' + id);
            var standingCell = document.getElementById('standingCell' + id);
            if (allocCell) allocCell.innerHTML = '<span class="text-muted">—</span>';
            if (standingCell) standingCell.innerHTML = '<span class="text-muted">—</span>';
        }
    });

    totalSelectedNet = Math.round(totalSelectedNet * 100) / 100;
    var selectedCount = checkedBoxes.length;

    var budget = 0;
    if (isPartial) {
        var rawVal = parseFloat(amountInput.value);
        if (isNaN(rawVal) || rawVal < 0) rawVal = 0;
        budget = Math.round(rawVal * 100) / 100;
        if (budget > totalSelectedNet && totalSelectedNet > 0) {
            budget = totalSelectedNet;
            amountInput.value = budget.toFixed(2);
        }
    } else {
        budget = totalSelectedNet;
        amountInput.value = totalSelectedNet.toFixed(2);
    }

    var remainingBudget = budget;
    var totalAllocated = 0;
    var totalStanding = 0;
    var fullySettledCount = 0;
    var partialChallanInfo = null;

    checkedBoxes.forEach(function(item) {
        var id = item.id;
        var rowNet = item.net;
        var allocCell = document.getElementById('allocCell' + id);
        var standingCell = document.getElementById('standingCell' + id);

        if (remainingBudget <= 0.004) {
            // No budget left for this bill
            if (allocCell) allocCell.innerHTML = '<span class="badge bg-secondary">₹0.00</span>';
            if (standingCell) standingCell.innerHTML = '<span class="badge bg-danger">' + fmtMoney(rowNet) + ' (Unpaid)</span>';
            totalStanding += rowNet;
        } else if (remainingBudget >= rowNet - 0.005) {
            // Full allocation for this bill
            var alloc = rowNet;
            remainingBudget = Math.max(0, Math.round((remainingBudget - alloc) * 100) / 100);
            totalAllocated += alloc;
            fullySettledCount++;
            if (allocCell) allocCell.innerHTML = '<span class="badge bg-success">' + fmtMoney(alloc) + '</span>';
            if (standingCell) standingCell.innerHTML = '<span class="text-success small fw-semibold"><i class="bi bi-check2 me-1"></i>₹0.00 (Cleared)</span>';
        } else {
            // Partial allocation for this bill
            var alloc = Math.round(remainingBudget * 100) / 100;
            var standing = Math.round((rowNet - alloc) * 100) / 100;
            remainingBudget = 0;
            totalAllocated += alloc;
            totalStanding += standing;
            partialChallanInfo = { challan: item.challan, standing: standing, alloc: alloc };
            if (allocCell) allocCell.innerHTML = '<span class="badge bg-warning text-dark">' + fmtMoney(alloc) + ' (Partial)</span>';
            if (standingCell) standingCell.innerHTML = '<span class="badge bg-danger">' + fmtMoney(standing) + ' Standing</span>';
        }
    });

    totalAllocated = Math.round(totalAllocated * 100) / 100;
    totalStanding = Math.round((totalSelectedNet - totalAllocated) * 100) / 100;

    document.getElementById('selCount').textContent = selectedCount;
    document.getElementById('selTotalDues').textContent = fmtMoney(totalSelectedNet);
    document.getElementById('selNet').textContent = fmtMoney(totalAllocated);
    document.getElementById('selStanding').textContent = fmtMoney(totalStanding);

    // Update FIFO Explainer Box
    var noteEl = document.getElementById('fifoNoteText');
    if (selectedCount === 0) {
        noteEl.innerHTML = 'Select challans from the list below to begin bulk or partial FIFO settlement.';
    } else if (!isPartial || totalStanding <= 0.004) {
        noteEl.innerHTML = '<strong>Full Settlement Mode:</strong> All <strong>' + selectedCount + '</strong> selected challan(s) will be fully settled for <strong>' + fmtMoney(totalSelectedNet) + '</strong>.';
    } else {
        var msg = '<strong>FIFO Partial Settlement:</strong> <strong>' + fmtMoney(totalAllocated) + '</strong> allocated across selected challan(s). ';
        if (fullySettledCount > 0) {
            msg += '<strong>' + fullySettledCount + '</strong> challan(s) fully cleared. ';
        }
        if (partialChallanInfo) {
            msg += 'Challan <strong>' + partialChallanInfo.challan + '</strong> receives <strong>' + fmtMoney(partialChallanInfo.alloc) + '</strong> with <strong class="text-danger">' + fmtMoney(partialChallanInfo.standing) + '</strong> remaining standing balance. ';
        }
        msg += 'Total standing balance: <strong class="text-danger">' + fmtMoney(totalStanding) + '</strong>.';
        noteEl.innerHTML = msg;
    }
}

function toggleAllBulk(flag) {
    document.querySelectorAll('.bulk-check').forEach(function(cb) { cb.checked = flag; });
    updateBulkSummary();
}

document.querySelectorAll('.bulk-check, .bulk-hold-check').forEach(function(el) {
    el.addEventListener('change', updateBulkSummary);
});

// Initial run
onSettlementModeChange();
</script>

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
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
