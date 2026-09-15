<?php

require_once '../includes/config.php';

require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
$__despatch_req_start = microtime(true);
$__despatch_marks = [];

function despatchPerfMark(string $label): void {
    global $__despatch_req_start, $__despatch_marks;
    $__despatch_marks[] = [$label, microtime(true) - $__despatch_req_start];
}

function despatchPerfLogIfSlow(string $context, float $threshold = 5.0): void {
    global $__despatch_req_start, $__despatch_marks;
    $elapsed = microtime(true) - $__despatch_req_start;
    if ($elapsed < $threshold) return;
    $parts = [];
    $prev = 0.0;
    foreach ($__despatch_marks as [$label, $at]) {
        $parts[] = sprintf('%s=%.3fs(+%.3fs)', $label, $at, $at - $prev);
        $prev = $at;
    }
    $line = sprintf("[%s] %s total=%.3fs %s\n", date('Y-m-d H:i:s'), $context, $elapsed, implode(' ', $parts));
    @error_log($line, 3, dirname(__DIR__) . '/logs/despatch_save_perf.log');
}

$despatch_view = $_GET['view'] ?? '';
$is_register_view = $despatch_view === 'register';
$__uid     = (int)($_SESSION['user_id'] ?? 0);

$__arow    = $db->query("SELECT is_agent, view_all_despatch FROM app_users WHERE id=$__uid LIMIT 1")->fetch_assoc();

$is_agent  = !isAdmin() && !empty($__arow['is_agent']) && empty($__arow['view_all_despatch']);

$agent_uid = $__uid;

/* ── Page-level view permission check ── */

requirePerm($is_register_view ? 'despatch_register' : 'despatch', 'view');


/* ── AJAX: get POs for vendor ── */

if (isset($_GET['ajax_get_pos'])) {

    if (ob_get_level() > 0) ob_clean();

    header('Content-Type: application/json');

    $vid = (int)($_GET['vendor_id'] ?? 0);

    $current_po_id = (int)($_GET['current_po_id'] ?? 0);

    $pos_ajax = [];

    if ($vid) {

        $extra_po_sql = $current_po_id > 0 ? " OR id=$current_po_id" : '';

        $res = $db->query("SELECT id, po_number FROM purchase_orders WHERE vendor_id=$vid AND (status='Approved'$extra_po_sql) ORDER BY po_date DESC");

        while ($row = $res->fetch_assoc()) $pos_ajax[] = $row;

    }

    echo json_encode($pos_ajax);

    exit;

}





/* ── AJAX: Update weight on Delivered order ── */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_weight') {

    if (ob_get_level() > 0) ob_clean();

    header('Content-Type: application/json');

    requirePerm('despatch', 'update');

    $tid     = (int)($_POST['id'] ?? 0);

    $weights = $_POST['weight'] ?? [];

    if (!$tid || empty($weights)) { echo json_encode(['ok'=>false,'msg'=>'Invalid data']); exit; }

    $db2 = getDB();

    $desp = $db2->query("SELECT * FROM despatch_orders WHERE id=$tid AND status='Delivered' LIMIT 1")->fetch_assoc();

    if (!$desp) { echo json_encode(['ok'=>false,'msg'=>'Order not found or not Delivered']); exit; }

    $item_rows = $db2->query("SELECT id FROM despatch_items WHERE despatch_id=$tid ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

    $new_total_w = 0;

    foreach ($item_rows as $idx => $ir) {

        $w    = (float)($weights[$idx] ?? 0);

        $new_total_w += $w;

        $iid  = (int)$ir['id'];

        $irow = $db2->query("SELECT unit_price, gst_rate FROM despatch_items WHERE id=$iid LIMIT 1")->fetch_assoc();

        $up   = (float)($irow['unit_price'] ?? 0);

        $gr   = (float)($irow['gst_rate']   ?? 0);

        $base = round($w * $up, 2);

        $gst  = round($base * $gr / 100, 2);

        $tot  = round($base + $gst, 2);

        $db2->query("UPDATE despatch_items SET weight=$w, gst_amount=$gst, total_price=$tot WHERE id=$iid");

    }

    $tot_row  = $db2->query("SELECT SUM(total_price) AS sub, SUM(gst_amount) AS gst FROM despatch_items WHERE despatch_id=$tid")->fetch_assoc();

    $new_sub  = round((float)($tot_row['sub'] ?? 0), 2);

    $new_gst  = round((float)($tot_row['gst'] ?? 0), 2);

    $misc_sql = "";
    if (isset($_POST['transporter_misc_charges'])) {
        $misc_c = (float)($_POST['transporter_misc_charges'] ?? 0);
        $misc_r = sanitize($_POST['transporter_misc_remarks'] ?? '');
        $misc_sql = ", transporter_misc_charges=$misc_c, transporter_misc_remarks='" . $db2->real_escape_string($misc_r) . "'";
    }

    $db2->query("UPDATE despatch_orders SET total_weight=$new_total_w, subtotal=$new_sub, gst_amount=$new_gst, total_amount=" . ($new_sub + $new_gst) . "$misc_sql WHERE id=$tid");

    // Recalc freight

    $stored_rate = (float)($desp['transporter_rate_per_mt'] ?? 0);

    if ($stored_rate <= 0) {

        $fr = $db2->query("SELECT rate FROM transporter_rates WHERE transporter_id={$desp['transporter_id']} AND vendor_id={$desp['vendor_id']} AND status='Active' ORDER BY id DESC LIMIT 1")->fetch_assoc();

        $stored_rate = (float)($fr['rate'] ?? 0);

    }

    if ($stored_rate > 0 && $new_total_w > 0) {

        $new_freight = round($stored_rate * $new_total_w, 2);

        $db2->query("UPDATE despatch_orders SET freight_amount=$new_freight WHERE id=$tid");

    }

    // Recalc commission
    $agent_id_ac = (int)($desp['agent_id'] ?? 0);
    if ($agent_id_ac > 0) {
        $agent_ac = $db2->query("SELECT slab1_upto, slab1_pct, slab2_pct FROM app_users WHERE id=$agent_id_ac AND is_agent=1 LIMIT 1")->fetch_assoc();
        if ($agent_ac) {
            $po_id_ac = (int)($desp['po_id'] ?? 0);

            $v_id_ac  = (int)($desp['vendor_id'] ?? 0);

            $t_id_ac  = (int)($desp['transporter_id'] ?? 0);

            $vr_ac = 0;

            if ($po_id_ac > 0) {

                $pr = $db2->query("SELECT unit_price FROM po_items WHERE po_id=$po_id_ac LIMIT 1")->fetch_assoc();

                $vr_ac = (float)($pr['unit_price'] ?? 0);

            }

            $tr_ac = 0;

            if ($t_id_ac && $v_id_ac) {

                $tr = $db2->query("SELECT rate FROM transporter_rates WHERE transporter_id=$t_id_ac AND vendor_id=$v_id_ac AND status='Active' ORDER BY id DESC LIMIT 1")->fetch_assoc();

                $tr_ac = (float)($tr['rate'] ?? 0);

            }
            $profit_ac = round($vr_ac - $tr_ac, 4);
            $slab_ac   = ($profit_ac <= (float)$agent_ac['slab1_upto']) ? 1 : 2;
            $pct_ac    = $slab_ac === 1 ? (float)$agent_ac['slab1_pct'] : (float)$agent_ac['slab2_pct'];
            $comm_ac   = round($profit_ac * ($pct_ac / 100) * $new_total_w, 2);
            $challan_no_ac = $db2->real_escape_string((string)($desp['challan_no'] ?? ''));
            $vendor_name_ac = $db2->real_escape_string((string)($desp['consignee_name'] ?? ''));
            $desp_date_ac = !empty($desp['despatch_date']) ? "'" . $db2->real_escape_string($desp['despatch_date']) . "'" : 'NULL';
            $db2->query("DELETE FROM agent_commissions WHERE despatch_id=$tid AND agent_id<>$agent_id_ac AND status='Pending'");
            $db2->query("INSERT INTO agent_commissions
                (despatch_id, agent_id, challan_no, despatch_date, vendor_name,
                 received_weight, vendor_rate, transporter_rate, profit_per_mt,
                 slab_applied, commission_pct, commission_amt, status)
                VALUES ($tid, $agent_id_ac, '$challan_no_ac', $desp_date_ac, '$vendor_name_ac',
                        $new_total_w, $vr_ac, $tr_ac, $profit_ac,
                        $slab_ac, $pct_ac, $comm_ac, 'Pending')
                ON DUPLICATE KEY UPDATE
                    challan_no='$challan_no_ac',
                    despatch_date=$desp_date_ac,
                    vendor_name='$vendor_name_ac',
                    received_weight=$new_total_w,
                    vendor_rate=$vr_ac,
                    transporter_rate=$tr_ac,
                    profit_per_mt=$profit_ac,
                    slab_applied=$slab_ac,
                    commission_pct=$pct_ac,
                    commission_amt=$comm_ac,
                    status=IF(status='Paid','Paid','Pending')");
        }
    }
    echo json_encode(['ok'=>true,'total_weight'=>$new_total_w,'subtotal'=>$new_sub]);

    exit;

}



/* ── Safe ALTER: works on MySQL 5.6+ (no IF NOT EXISTS support) ── */

function safeAddColumn($db, $table, $column, $definition) {

    static $dbname = null;
    if ($dbname === null) $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];

    $exists = $db->query("

        SELECT 1 FROM information_schema.COLUMNS

        WHERE TABLE_SCHEMA = '$dbname'

          AND TABLE_NAME   = '$table'

          AND COLUMN_NAME  = '$column'

        LIMIT 1

    ")->num_rows;

    if (!$exists) {

        $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");

    }

}

function safeAddIndex($db, $table, $index, $definition) {

    static $dbname = null;
    if ($dbname === null) $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];

    $exists = $db->query("

        SELECT 1 FROM information_schema.STATISTICS

        WHERE TABLE_SCHEMA = '$dbname'

          AND TABLE_NAME   = '$table'

          AND INDEX_NAME   = '$index'

        LIMIT 1

    ")->num_rows;

    if (!$exists) {

        $db->query("ALTER TABLE `$table` ADD INDEX `$index` $definition");

    }

}

function safeModifyDespatchQtyNullable($db) {

    static $checked = false;
    if ($checked) return;
    $checked = true;

    $row = $db->query("
        SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'despatch_items'
          AND COLUMN_NAME = 'qty'
        LIMIT 1
    ")->fetch_assoc();

    if (!$row) return;

    $needs_modify = strtolower($row['DATA_TYPE'] ?? '') !== 'decimal'
        || (int)($row['NUMERIC_PRECISION'] ?? 0) !== 10
        || (int)($row['NUMERIC_SCALE'] ?? -1) !== 2
        || strtoupper($row['IS_NULLABLE'] ?? '') !== 'YES';

    if ($needs_modify) {
        $db->query("ALTER TABLE despatch_items MODIFY COLUMN qty DECIMAL(10,2) DEFAULT NULL");
    }

}



safeAddColumn($db, 'despatch_orders', 'transporter_misc_charges', "DECIMAL(10,2) DEFAULT 0");
safeAddColumn($db, 'despatch_orders', 'transporter_misc_remarks', "VARCHAR(255) DEFAULT ''");

$action = $_GET['action'] ?? 'list';

$id = (int)($_GET['id'] ?? 0);

$current_despatch_po_id = 0;

$is_form_action = in_array($action, ['add', 'edit'], true);



/* ── Auto-number generators (MAX-based — safe if records deleted) ── */

/* Peek — returns next number WITHOUT incrementing sequence (safe for display) */

/* ── FY-based Challan Number Generator ── */

function currentFY() {

    $m = (int)date('m'); $y = (int)date('Y');

    $fy_start = $m >= 4 ? $y : $y - 1;

    $fy_end   = $fy_start + 1;

    return str_pad($fy_start % 100, 2, '0', STR_PAD_LEFT) . str_pad($fy_end % 100, 2, '0', STR_PAD_LEFT);

}



/* Ensure sequence table and row exist (safe to call multiple times) */

function _fySeqEnsure($db, $key) {

    static $done = [];

    if (isset($done[$key])) return;

    $db->query("CREATE TABLE IF NOT EXISTS doc_sequences (seq_key VARCHAR(50) PRIMARY KEY, last_val INT UNSIGNED NOT NULL DEFAULT 0)");

    // Only insert if missing — no UPDATE on duplicate

    $db->query("INSERT IGNORE INTO doc_sequences (seq_key, last_val) VALUES ('$key', 0)");

    // Seed from company_settings if still 0

    $cur = (int)$db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$key'")->fetch_assoc()['last_val'];

    if ($cur === 0) {

        $res = $db->query("SELECT fy_start_no FROM company_settings LIMIT 1");

        $seed = $res ? (int)($res->fetch_assoc()['fy_start_no'] ?? 0) : 0;

        if ($seed > 1) {

            $db->query("UPDATE doc_sequences SET last_val=" . ($seed - 1) . " WHERE seq_key='$key' AND last_val=0");

        }

    }

    $sync_flag = 'despatch_seq_synced_' . $key;
    if (empty($_SESSION[$sync_flag])) {
        $fy = preg_replace('/^challan_fy/', '', $key);
        $prefix = "DC/{$fy}/";
        $max_row = $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(challan_no,'/',-1) AS UNSIGNED)) AS mx FROM despatch_orders WHERE challan_no LIKE '" . $db->real_escape_string($prefix) . "%'")->fetch_assoc();
        $db_max = (int)($max_row['mx'] ?? 0);
        if ($db_max > $cur) {
            $db->query("UPDATE doc_sequences SET last_val=$db_max WHERE seq_key='$key'");
        }
        $_SESSION[$sync_flag] = 1;
    }

    $done[$key] = true;
}



/* Peek — returns next number WITHOUT incrementing (safe for form display) */

function peekChallanNo($db) {
    $fy  = currentFY();
    $key = "challan_fy{$fy}";
    _fySeqEnsure($db, $key);
    $seq_val = (int)$db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$key'")->fetch_assoc()['last_val'];
    return "DC/{$fy}/" . str_pad($seq_val + 1, 4, '0', STR_PAD_LEFT);
}



/* Generate — atomic increment, returns BOTH challan_no and despatch_no in one call.

   This is the ONLY function that increments the sequence. Called ONCE per INSERT. */

function generateChallanAndDespatchNo($db) {

    $fy  = currentFY();

    $key = "challan_fy{$fy}";

    _fySeqEnsure($db, $key);

    // Atomic increment

    $db->query("UPDATE doc_sequences SET last_val = last_val + 1 WHERE seq_key='$key'");

    $next = (int)$db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$key'")->fetch_assoc()['last_val'];

    $num  = str_pad($next, 4, '0', STR_PAD_LEFT);

    return [

        'challan_no'  => "DC/{$fy}/{$num}",

        'despatch_no' => "DSP/{$fy}/{$num}",

    ];
}



/* Peek despatch no (display only) */

function peekDespatchNo($db) { return str_replace('DC/', 'DSP/', peekChallanNo($db)); }







$run_despatch_bootstrap = empty($_SESSION['despatch_runtime_bootstrap_v1']);
if ($run_despatch_bootstrap) {
// Ensure consignee_contact column exists (added in this update)
safeAddColumn($db, 'despatch_orders', 'consignee_contact', 'VARCHAR(150) AFTER consignee_gstin');
// Optional vendor camp / site marker when ship-to address is common
safeAddColumn($db, 'despatch_orders', 'consignee_camp', "VARCHAR(120) DEFAULT '' AFTER consignee_contact");

// Allow NULL for despatch qty (reference only, not used in calculations)

safeModifyDespatchQtyNullable($db);



// Source of material + MTC columns

safeAddColumn($db, 'despatch_orders', 'source_of_material_id', 'INT DEFAULT 0');

safeAddColumn($db, 'despatch_orders', 'mtc_required',       "ENUM('No','Yes') NOT NULL DEFAULT 'No'");

safeAddColumn($db, 'despatch_orders', 'mtc_source',          "VARCHAR(120) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'mtc_item_name',       "VARCHAR(120) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'mtc_test_date',       "DATE DEFAULT NULL");

safeAddColumn($db, 'despatch_orders', 'mtc_ros_45',          "VARCHAR(30) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'mtc_moisture',        "VARCHAR(30) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'mtc_loi',             "VARCHAR(30) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'mtc_fineness',        "VARCHAR(30) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'mtc_remarks',         "VARCHAR(255) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'doc_mtc',             "VARCHAR(255) DEFAULT ''");



// Delivery document upload columns

safeAddColumn($db, 'despatch_orders', 'doc_delivery_challan',   "VARCHAR(255) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'doc_vendor_receipt',     "VARCHAR(255) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'doc_weightbridge',       "VARCHAR(255) DEFAULT ''");

// Transporter Freight Invoice columns

safeAddColumn($db, 'despatch_orders', 'freight_inv_no',       "VARCHAR(60) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'freight_inv_date',     "DATE DEFAULT NULL");

safeAddColumn($db, 'despatch_orders', 'freight_inv_amount',   "DECIMAL(10,2) DEFAULT 0");

safeAddColumn($db, 'despatch_orders', 'freight_inv_type',     "ENUM('','Scan','Digital') DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'freight_inv_file',     "VARCHAR(255) DEFAULT ''");

safeAddColumn($db, 'despatch_orders', 'freight_inv_hardcopy', "TINYINT(1) DEFAULT 0");

safeAddColumn($db, 'despatch_orders', 'agent_id',              "INT DEFAULT NULL COMMENT 'FK app_users.id'");

safeAddColumn($db, 'despatch_orders', 'actual_created_by',     "INT DEFAULT 0 COMMENT 'Actual logged-in user who entered despatch'");

safeAddColumn($db, 'despatch_orders', 'vendor_freight_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Freight charged to vendor'");

safeAddColumn($db, 'despatch_orders', 'transporter_rate_per_mt', "DECIMAL(10,4) DEFAULT 0 COMMENT 'Rate card rate per MT/Kg stored at despatch time'");

// Save/list performance indexes. Existing installs may not have FK-created indexes.
safeAddIndex($db, 'despatch_items', 'idx_despatch_items_despatch', '(despatch_id)');
safeAddIndex($db, 'despatch_items', 'idx_despatch_items_item_despatch', '(item_id, despatch_id)');
safeAddIndex($db, 'despatch_orders', 'idx_despatch_po_status', '(po_id, status)');
safeAddIndex($db, 'despatch_orders', 'idx_despatch_status_date', '(status, despatch_date)');

// Fix any NULL vendor_freight_amount from before NOT NULL was set

$db->query("UPDATE despatch_orders SET vendor_freight_amount=0 WHERE vendor_freight_amount IS NULL");



/* ── Commission ledger tables ── */

$db->query("CREATE TABLE IF NOT EXISTS agent_commissions (

    id              INT AUTO_INCREMENT PRIMARY KEY,

    despatch_id     INT NOT NULL,

    agent_id        INT NOT NULL,

    challan_no      VARCHAR(60) DEFAULT '',

    despatch_date   DATE DEFAULT NULL,

    vendor_name     VARCHAR(150) DEFAULT '',

    received_weight DECIMAL(10,3) DEFAULT 0,

    vendor_rate     DECIMAL(10,4) DEFAULT 0  COMMENT 'freight_amount / weight',

    transporter_rate DECIMAL(10,4) DEFAULT 0,

    profit_per_mt   DECIMAL(10,4) DEFAULT 0,

    slab_applied    TINYINT(1) DEFAULT 1 COMMENT '1=Slab1, 2=Slab2',

    commission_pct  DECIMAL(5,2) DEFAULT 0,

    commission_amt  DECIMAL(10,2) DEFAULT 0,

    status          ENUM('Pending','Paid') DEFAULT 'Pending',

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_despatch_agent (despatch_id, agent_id)

)");
safeAddIndex($db, 'agent_commissions', 'idx_agent_comm_despatch_status', '(despatch_id, status)');


$db->query("CREATE TABLE IF NOT EXISTS agent_commission_payments (

    id            INT AUTO_INCREMENT PRIMARY KEY,

    agent_id      INT NOT NULL,

    amount        DECIMAL(10,2) NOT NULL,

    paid_date     DATE NOT NULL,

    reference     VARCHAR(100) DEFAULT '',

    notes         TEXT,

    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP

)");



$db->query("CREATE TABLE IF NOT EXISTS agent_payment_commissions (

    payment_id    INT NOT NULL,

    commission_id INT NOT NULL,

    PRIMARY KEY (payment_id, commission_id)

)");



$db->query("CREATE TABLE IF NOT EXISTS app_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT DEFAULT 0,

    activity_type VARCHAR(80) NOT NULL,

    entity_type VARCHAR(80) NOT NULL,

    entity_id INT DEFAULT 0,

    activity_date DATE DEFAULT NULL,

    details VARCHAR(255) DEFAULT '',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX (user_id),

    INDEX (created_at),

    INDEX (activity_type),

    INDEX (entity_type)

)"); 
$_SESSION['despatch_runtime_bootstrap_v1'] = 1;
}


function despatchLegacyUploadDir(string $folder): string {

    return dirname(__DIR__) . '/uploads/' . trim($folder, '/');

}



function despatchIsLegacyUpload(string $value): bool {

    if ($value === '' || preg_match('#^https?://#i', $value)) return false;

    return strpos($value, '/') === false || strpos($value, 'uploads/') === 0;

}



function despatchStoredLabel(string $value): string {

    return basename(str_replace('\\', '/', $value));

}



function despatchStoredUrl(string $value, string $legacyFolder): string {

    if ($value === '') return '';

    if (preg_match('#^https?://#i', $value)) return $value;

    if (strpos($value, 'uploads/') === 0) return '../' . ltrim($value, '/');

    if (despatchIsLegacyUpload($value)) return '../uploads/' . trim($legacyFolder, '/') . '/' . rawurlencode($value);

    return function_exists('r2_url') ? r2_url($value) : '';

}



function deleteDespatchStoredFile(string $value, string $legacyFolder): bool {

    if ($value === '') return true;

    if (!despatchIsLegacyUpload($value)) {

        return function_exists('r2_delete') ? r2_delete($value) : false;

    }

    $relative = strpos($value, 'uploads/') === 0 ? substr($value, strlen('uploads/')) : trim($legacyFolder, '/') . '/' . $value;

    $path = dirname(__DIR__) . '/uploads/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

    return !file_exists($path) || unlink($path);

}



/* ── Helper: upload to R2 and return stored key or existing value ── */

function despatchR2UploadQuick(string $tmpPath, string $r2Key): bool {
    if (!file_exists($tmpPath)) return false;
    $body = file_get_contents($tmpPath);
    if ($body === false) return false;
    $url = (defined('R2_WORKER_URL') ? R2_WORKER_URL : 'https://dms-r2-upload.jpsgujral.workers.dev') . '/' . ltrim($r2Key, '/');
    $token = defined('R2_WORKER_TOKEN') ? R2_WORKER_TOKEN : 'dms_worker_s3cur3_t0k3n_2024';
    $ch = curl_init($url);
    if (!$ch) return false;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'X-DMS-Token: ' . $token,
            'Content-Type: ' . r2_mime($r2Key),
            'Content-Length: ' . strlen($body),
        ],
    ]);
    curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode === 200;
}

function handleDocUpload(string $field, string $existing, int $despatch_id, string $suffix, string $r2Folder, string $legacyFolder): string {

    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {

        return $existing;

    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) return $existing;

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) return $existing;

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];

    if (!in_array($ext, $allowed_ext, true)) return $existing;



    try {
        $nonce = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $nonce = substr(md5(uniqid((string)$despatch_id, true)), 0, 8);
    }
    $newKey = trim($r2Folder, '/') . '/D' . $despatch_id . '_' . $suffix . '_' . date('Ymd_His') . '_' . $nonce . '.' . $ext;

    if (despatchR2UploadQuick($file['tmp_name'], $newKey)) {

        if ($existing !== '') deleteDespatchStoredFile($existing, $legacyFolder);

        return $newKey;

    }

    // Fast local fallback (keeps save flow non-blocking if R2 is slow/unreachable)
    $legacyDir = despatchLegacyUploadDir($legacyFolder);
    if (!is_dir($legacyDir)) @mkdir($legacyDir, 0775, true);
    $localName = 'D' . $despatch_id . '_' . $suffix . '_' . date('Ymd_His') . '_' . $nonce . '.' . $ext;
    $localPath = rtrim($legacyDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $localName;
    if (@move_uploaded_file($file['tmp_name'], $localPath)) {
        if ($existing !== '') deleteDespatchStoredFile($existing, $legacyFolder);
        return 'uploads/' . trim($legacyFolder, '/') . '/' . $localName;
    }

    return $existing;

}



if (isset($_GET['delete'])) {

    requirePerm('despatch', 'delete');

    $del_id = (int)$_GET['delete'];

    $del_uid = (int)($_SESSION['user_id'] ?? 0);

    $del_row = $db->query("SELECT challan_no, despatch_date, status, COALESCE(actual_created_by, created_by) AS activity_user_id

                           FROM despatch_orders WHERE id=$del_id LIMIT 1")->fetch_assoc();

    if ($del_row) {

        $log_uid  = (int)($del_row['activity_user_id'] ?? $del_uid);

        $log_date = !empty($del_row['despatch_date']) ? "'" . $db->real_escape_string($del_row['despatch_date']) . "'" : 'NULL';

        $log_det  = $db->real_escape_string('Deleted challan ' . ($del_row['challan_no'] ?: ('#' . $del_id)) . ' / ' . ($del_row['status'] ?? ''));

        $db->query("INSERT INTO app_activity_log (user_id, activity_type, entity_type, entity_id, activity_date, details)

                    VALUES ($log_uid, 'Despatch Deleted', 'despatch_order', $del_id, $log_date, '$log_det')");

    }

    $db->query("DELETE FROM despatch_orders WHERE id=$del_id");

    $db->query("DELETE FROM despatch_items WHERE despatch_id=$del_id");

    $db->query("DELETE FROM agent_commissions WHERE despatch_id=$del_id AND status='Pending'");

    showAlert('success', 'Despatch Order deleted.');

    redirect('despatch.php');

}



if (isset($_GET['cancel_id'])) {

    if (!isAdmin()) {
        showAlert('danger', 'Admin rights required to cancel a despatch order.');
        redirect('despatch.php');
    }

    requirePerm('despatch', 'update');

    $cid = (int)$_GET['cancel_id'];

    $db->query("UPDATE despatch_orders SET status='Cancelled' WHERE id=$cid AND status NOT IN ('Delivered','Cancelled')");

    showAlert('success', 'Despatch Order cancelled.');

    redirect('despatch.php');

}



if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    despatchPerfMark('post_start');

    requirePerm('despatch', $id > 0 ? 'update' : 'create');

    // Delivered orders: only allow document updates (delivery docs + freight invoice)

    if ($id > 0) {

        $existing_status = $db->query("SELECT status FROM despatch_orders WHERE id=$id")->fetch_assoc()['status'] ?? '';

        if ($existing_status === 'Delivered' && !isAdmin()) {

            if (!empty($_POST['docs_only'])) {

                // ── Process ONLY delivery documents and freight invoice ──

                $despatch = $db->query("SELECT * FROM despatch_orders WHERE id=$id")->fetch_assoc();

                // Delivery document uploads/removals

                $removeDoc = function($field, $existing, $legacyFolder) {

                    if (!empty($_POST['remove_'.$field]) && $existing) {

                        deleteDespatchStoredFile($existing, $legacyFolder);

                        return '';

                    }

                    return $existing;

                };

                $cur_dc = $removeDoc('doc_delivery_challan', $despatch['doc_delivery_challan'] ?? '', 'delivery_docs');

                $cur_vr = $removeDoc('doc_vendor_receipt',   $despatch['doc_vendor_receipt']   ?? '', 'delivery_docs');

                $cur_wb = $removeDoc('doc_weightbridge',     $despatch['doc_weightbridge']     ?? '', 'delivery_docs');

                $doc_dc = handleDocUpload('doc_delivery_challan', $cur_dc, $id, 'DC', 'despatch/delivery_docs', 'delivery_docs');

                $doc_vr = handleDocUpload('doc_vendor_receipt',   $cur_vr, $id, 'VR', 'despatch/delivery_docs', 'delivery_docs');

                $doc_wb = handleDocUpload('doc_weightbridge',     $cur_wb, $id, 'WB', 'despatch/delivery_docs', 'delivery_docs');

                $esc = fn($v) => $db->real_escape_string($v);

                $db->query("UPDATE despatch_orders SET

                    doc_delivery_challan='{$esc($doc_dc)}',

                    doc_vendor_receipt='{$esc($doc_vr)}',

                    doc_weightbridge='{$esc($doc_wb)}'

                    WHERE id=$id");



                // Freight invoice fields

                $fi_no     = $db->real_escape_string(sanitize($_POST['freight_inv_no']     ?? ''));

                $fi_date   = sanitize($_POST['freight_inv_date']   ?? ''); if ($fi_date === '') $fi_date = null;

                $fi_amount = (float)($_POST['freight_inv_amount']  ?? 0);

                $fi_type   = in_array($_POST['freight_inv_type'] ?? '', ['Scan','Digital']) ? $_POST['freight_inv_type'] : '';

                $fi_hc     = ($fi_type === 'Scan') ? (int)(!empty($_POST['freight_inv_hardcopy'])) : 0;

                if ($fi_type === 'Digital') $fi_hc = 1;

                $fi_existing = $despatch['freight_inv_file'] ?? '';

                if (!empty($_POST['remove_freight_inv_file']) && $fi_existing) {

                    deleteDespatchStoredFile($fi_existing, 'freight_invoices');

                    $fi_existing = '';

                }

                $fi_file = handleDocUpload('freight_inv_file', $fi_existing, $id, 'FI', 'despatch/freight_invoices', 'freight_invoices');

                $fi_date_sql = $fi_date ? "'".$db->real_escape_string($fi_date)."'" : 'NULL';

                $misc_c = (float)($_POST['transporter_misc_charges'] ?? 0);
                $misc_r = $db->real_escape_string(sanitize($_POST['transporter_misc_remarks'] ?? ''));

                $db->query("UPDATE despatch_orders SET
                    freight_inv_no='$fi_no', freight_inv_date=$fi_date_sql,
                    freight_inv_amount=$fi_amount, freight_inv_type='$fi_type',
                    freight_inv_file='".$db->real_escape_string($fi_file)."',
                    freight_inv_hardcopy=$fi_hc,
                    transporter_misc_charges=$misc_c,
                    transporter_misc_remarks='$misc_r'
                    WHERE id=$id");



                showAlert('success', 'Documents updated successfully.');

                redirect("despatch.php?action=edit&id=$id");

            } else {

                showAlert('danger', 'This Despatch Order is Delivered and locked for editing.');

                redirect('despatch.php');

            }

        }

    }

    $process_docs_on_main_save = !empty($_POST['process_docs']);
    $f = [

        'despatch_date', 'consignee_name', 'consignee_address',

        'consignee_city', 'consignee_state', 'consignee_pincode', 'consignee_gstin', 'consignee_contact', 'consignee_camp',

        'vehicle_no', 'driver_name', 'driver_mobile',

        'freight_paid_by', 'status'

    ];

    $data = [];

    foreach ($f as $key) $data[$key] = sanitize($_POST[$key] ?? '');

    if (($data['status'] ?? '') === 'Cancelled' && !isAdmin()) {
        if ($id > 0) {
            $orig_status = $db->query("SELECT status FROM despatch_orders WHERE id=$id LIMIT 1")->fetch_assoc()['status'] ?? 'In Transit';
            $data['status'] = $orig_status;
        } else {
            $data['status'] = 'In Transit';
        }
    }

    // Strip backslashes from address fields (prevent DB corruption from over-escaping)

    foreach (['consignee_name','consignee_address','consignee_city','consignee_state','consignee_pincode','consignee_gstin','consignee_contact','consignee_camp'] as $af) {

        $data[$af] = str_replace('\\', '', $data[$af]);

    }

    // lr_date always equals despatch_date (removed from form)

    $data['lr_date'] = $data['despatch_date'];

    if ($id > 0 && !isAdmin()) {
        $orig_desp = $db->query("SELECT vendor_id, po_id, transporter_id FROM despatch_orders WHERE id=$id LIMIT 1")->fetch_assoc();
        $data['vendor_id']      = (int)($orig_desp['vendor_id'] ?? 0);
        $data['po_id']          = (int)($orig_desp['po_id'] ?? 0);
        $data['transporter_id'] = (int)($orig_desp['transporter_id'] ?? 0);
    } else {
        $data['transporter_id'] = (int)($_POST['transporter_id'] ?? 0);
        $data['vendor_id']      = (int)($_POST['vendor_id']      ?? 0);
        $data['po_id']          = (int)($_POST['po_id']          ?? 0);
    }

    $data['source_of_material_id']  = (int)($_POST['source_of_material_id']  ?? 0);

    $data['mtc_required']  = in_array($_POST['mtc_required']??'No',['Yes','No']) ? $_POST['mtc_required'] : 'No';

    $data['mtc_source']    = sanitize($_POST['mtc_source']    ?? '');

    $data['mtc_item_name'] = sanitize($_POST['mtc_item_name'] ?? '');

    $data['mtc_test_date'] = sanitize($_POST['mtc_test_date'] ?? '');

    $data['mtc_ros_45']    = sanitize($_POST['mtc_ros_45']    ?? '');

    $data['mtc_moisture']  = sanitize($_POST['mtc_moisture']  ?? '');

    $data['mtc_loi']       = sanitize($_POST['mtc_loi']       ?? '');

    $data['mtc_fineness']  = sanitize($_POST['mtc_fineness']  ?? '');

    $data['mtc_remarks']   = sanitize($_POST['mtc_remarks']   ?? '');

    $data['po_id']          = (int)($_POST['po_id']          ?? 0);

    // po_id and transporter_id are already in $data for the SET/INSERT loop

    $data['total_weight'] = (float)($_POST['total_weight'] ?? 0);

    $data['freight_amount']        = (float)($_POST['freight_amount']        ?? 0);

    $data['vendor_freight_amount'] = (float)($_POST['vendor_freight_amount'] ?? 0);

    $data['transporter_rate_per_mt'] = (float)($_POST['transporter_rate_per_mt'] ?? 0);
    $data['transporter_misc_charges'] = (float)($_POST['transporter_misc_charges'] ?? 0);
    $data['transporter_misc_remarks'] = sanitize($_POST['transporter_misc_remarks'] ?? '');

    $data['company_id']   = (int)($_POST['company_id'] ?? activeCompanyId());

    $data['agent_id'] = (int)($_POST['agent_id'] ?? 0) ?: null;



    // Items

    $item_ids = $_POST['item_id'] ?? [];

    $descs = $_POST['desc'] ?? [];

    $qtys = $_POST['qty'] ?? [];

    $uoms = $_POST['uom'] ?? [];

    $prices = $_POST['unit_price'] ?? [];

    $gst_rates = $_POST['gst_rate'] ?? [];

    $weights = $_POST['weight'] ?? [];



    $subtotal = 0; $gst_total = 0;

    $valid_items = [];

    foreach ($item_ids as $idx => $iid) {

        $iid = (int)$iid;

        $raw_qty = trim($qtys[$idx] ?? '');

        $qty = ($raw_qty === '') ? null : (float)$raw_qty;

        $price = (float)($prices[$idx] ?? 0);

        $gst_rate = (float)($gst_rates[$idx] ?? 0);

        $weight = (float)($weights[$idx] ?? 0);

        $desc = sanitize($descs[$idx] ?? '');

        $uom = sanitize($uoms[$idx] ?? '');

        if ($iid > 0) {

            // Total = PO Unit Rate × Received Weight (+ GST if applicable)

            $line    = $price * $weight;

            $gst_amt = $line * ($gst_rate / 100);

            $total   = $line + $gst_amt;

            $subtotal   += $line;

            $gst_total  += $gst_amt;

            $valid_items[] = compact('iid','desc','qty','uom','price','gst_rate','gst_amt','total','weight');

        }

    }

    if ($data['status'] !== 'Delivered') {
        $data['total_weight'] = 0;
        $data['freight_amount'] = 0;
        $data['vendor_freight_amount'] = 0;
        $subtotal = 0;
        $gst_total = 0;
        foreach ($valid_items as &$vi) {
            $vi['price'] = 0;
            $vi['gst_rate'] = 0;
            $vi['gst_amt'] = 0;
            $vi['total'] = 0;
            $vi['weight'] = 0;
        }
        unset($vi);
    }

    $grand_total = $subtotal + $gst_total;


    $errors = [];

    // Always auto-fill consignee from vendor (consignee card is hidden)

    if ($data['vendor_id'] > 0) {

        $vrow = $db->query("SELECT vendor_name, ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_gstin FROM vendors WHERE id=".(int)$data['vendor_id']." LIMIT 1")->fetch_assoc();

        if ($vrow) {

            $data['consignee_name']    = $vrow['ship_name'] ?: $vrow['vendor_name'];

            if (empty($data['consignee_address'])) $data['consignee_address'] = $vrow['ship_address'] ?? '';

            if (empty($data['consignee_city']))    $data['consignee_city']    = $vrow['ship_city']    ?? '';

            if (empty($data['consignee_state']))   $data['consignee_state']   = $vrow['ship_state']   ?? '';

            if (empty($data['consignee_pincode'])) $data['consignee_pincode'] = $vrow['ship_pincode'] ?? '';

            if (empty($data['consignee_gstin']))   $data['consignee_gstin']   = $vrow['ship_gstin']   ?? '';

        }

    }

    if (empty($data['consignee_name'])) $errors[] = 'Consignee Name is required. Please select a Vendor.';

    if ($data['source_of_material_id'] < 1)   $errors[] = 'Source of Material is required.';

    if ($data['po_id'] < 1)                                      $errors[] = 'PO Reference is required.';

    if ($data['transporter_id'] < 1)                             $errors[] = 'Transporter is required.';

    if (empty($data['vehicle_no']))           $errors[] = 'Vehicle No is required.';

    if (empty($valid_items))                  $errors[] = 'At least one Despatch Item is required.';

    if ($data['status'] === 'Delivered') {

        $total_weight_entered = array_sum(array_column($valid_items, 'weight'));

        if ($total_weight_entered <= 0) $errors[] = 'Received Weight is required when status is Delivered.';

        // Each item must have Received Weight

        foreach ($valid_items as $vi_idx => $vi) {

            if ((float)$vi['weight'] <= 0) {

                $errors[] = 'Item #'.($vi_idx+1).': Received Weight is required for every item when status is Delivered.';

                break;

            }

        }

    }



    if (!empty($errors)) {

        showAlert('danger', implode('<br>', $errors));
        despatchPerfMark('validation_failed');
        despatchPerfLogIfSlow('despatch_save_validation_failed');

    } else {

        // Convert empty DATE fields to NULL to avoid MySQL strict mode errors

        $date_fields = ['mtc_test_date', 'lr_date', 'despatch_date'];

        foreach ($date_fields as $df) {

            if (isset($data[$df]) && $data[$df] === '') $data[$df] = null;

        }

        // Resolve active rate-card mapping for selected transporter+vendor on every save.
        // This keeps despatch_orders.rate_card_id consistent after edits.
        $active_rate_row = null;
        $resolved_rate_card_id = 0;
        $rc_tid = (int)$data['transporter_id'];
        $rc_vid = (int)($data['vendor_id'] ?? 0);
        if ($rc_tid && $rc_vid) {
            $active_rate_row = $db->query("SELECT id, rate FROM transporter_rates
                WHERE transporter_id=$rc_tid AND vendor_id=$rc_vid AND status='Active'
                ORDER BY id DESC LIMIT 1")->fetch_assoc();
            if ($active_rate_row) {
                $resolved_rate_card_id = (int)$active_rate_row['id'];
                $data['transporter_rate_per_mt'] = (float)($active_rate_row['rate'] ?? 0);
            } else {
                // No active card for the pair: avoid carrying stale values.
                $data['transporter_rate_per_mt'] = 0;
            }
        }
        despatchPerfMark('rate_resolved');



        if ($id > 0) {

            // Set lr_number = challan_no (Challan No cum LR No)

        $data['lr_number'] = $despatch['challan_no'] ?? '';

        // UPDATE — never change despatch_no or challan_no

            $set = [];

            foreach ($data as $k => $v) {

                if (is_null($v))             $set[] = "$k=NULL";

                elseif (is_int($v) || is_float($v)) $set[] = "$k=$v";

                else $set[] = "$k='" . $db->real_escape_string($v) . "'";

            }

            $set[] = "subtotal=$subtotal";

            $set[] = "gst_amount=$gst_total";

            $set[] = "total_amount=$grand_total";

            $db->query("UPDATE despatch_orders SET " . implode(',', $set) . " WHERE id=$id");

            $db->query("DELETE FROM despatch_items WHERE despatch_id=$id");
            despatchPerfMark('order_updated_items_deleted');

        } else {

            // INSERT

            $manual_ch = trim($_POST['manual_challan_no'] ?? '');

            // Generate auto number first

            $nums = generateChallanAndDespatchNo($db);

            $new_challan_no  = $nums['challan_no'];

            $new_despatch_no = $nums['despatch_no'];

            // Override with manual if different from auto-generated

            if ($manual_ch && $manual_ch !== $new_challan_no) {

                $dup = $db->query("SELECT id FROM despatch_orders WHERE challan_no='".$db->real_escape_string($manual_ch)."' LIMIT 1")->num_rows;

                if ($dup) {

                    showAlert('danger', "Challan No '$manual_ch' already exists.");

                    redirect('despatch.php?action=add');

                }

                $new_challan_no  = $manual_ch;

                $new_despatch_no = str_replace('DC/', 'DSP/', $manual_ch);

                // Roll back the sequence increment since we used manual number

                $fy  = substr($manual_ch, 3, 4);

                $key = "challan_fy{$fy}";

                $db->query("UPDATE doc_sequences SET last_val = last_val - 1 WHERE seq_key='$key'");

            }

            $data['lr_number'] = $new_challan_no; // Challan No cum LR No



            $cols = 'despatch_no,challan_no,created_by,actual_created_by,' . implode(',', array_keys($data)) . ',subtotal,gst_amount,total_amount';

            $vals = [];

            $vals[] = "'" . $db->real_escape_string($new_despatch_no) . "'";

            $vals[] = "'" . $db->real_escape_string($new_challan_no)  . "'";

            // Admin can assign to another user

            $override_uid = (isAdmin() && !empty($_POST['override_created_by'])) ? (int)$_POST['override_created_by'] : 0;

            $actual_creator_uid = (int)($_SESSION['user_id'] ?? 0);

            $vals[] = $override_uid ?: $actual_creator_uid;

            $vals[] = $actual_creator_uid;

            foreach ($data as $v) {

                if (is_null($v))             $vals[] = 'NULL';

                elseif (is_int($v) || is_float($v)) $vals[] = $v;

                else $vals[] = "'" . $db->real_escape_string($v) . "'";

            }

            $vals[] = $subtotal;

            $vals[] = $gst_total;

            $vals[] = $grand_total;

            $result = $db->query("INSERT INTO despatch_orders ($cols) VALUES (" . implode(',', $vals) . ")");

            $id = $db->insert_id;

            if (!$result || !$id) {

                showAlert('danger', 'Failed to save Despatch Order. DB Error: ' . $db->error . ' Please try again.');
                despatchPerfMark('insert_failed');
                despatchPerfLogIfSlow('despatch_save_insert_failed');

                goto skip_redirect;

            }

        }

        if ($resolved_rate_card_id > 0) {
            $db->query("UPDATE despatch_orders SET rate_card_id=$resolved_rate_card_id WHERE id=$id");
        } else {
            $db->query("UPDATE despatch_orders SET rate_card_id=NULL WHERE id=$id");
        }
        despatchPerfMark('rate_card_saved');
        foreach ($valid_items as $vi) {

            $qty_sql = is_null($vi['qty']) ? 'NULL' : $vi['qty'];

            $db->query("INSERT INTO despatch_items (despatch_id,item_id,description,qty,uom,unit_price,gst_rate,gst_amount,total_price,weight)

                VALUES ($id,{$vi['iid']},'{$vi['desc']}',$qty_sql,'{$vi['uom']}',{$vi['price']},{$vi['gst_rate']},{$vi['gst_amt']},{$vi['total']},{$vi['weight']})");

        }
        despatchPerfMark('items_inserted');

        if ($data['status'] === 'Delivered') {
            if (!empty($_POST['remove_doc_mtc']) && !empty($despatch['doc_mtc'])) {
                deleteDespatchStoredFile($despatch['doc_mtc'], 'mtc_docs');
                $doc_mtc = '';
            } else {
                $doc_mtc = handleDocUpload('doc_mtc', $despatch['doc_mtc'] ?? '', $id, 'MTC', 'despatch/mtc_docs', 'mtc_docs');
            }

            $esc2 = fn($v) => $db->real_escape_string($v);
            $db->query("UPDATE despatch_orders SET doc_mtc='{$esc2($doc_mtc)}' WHERE id=$id");
        }
        despatchPerfMark('mtc_docs_checked');



        // Handle document uploads/removals (only meaningful when status=Delivered)

        if ($data['status'] === 'Delivered') {

            // Helper: if remove checkbox ticked, delete file and return ''

            $removeDoc = function($field, $existing, $legacyFolder) {

                if (!empty($_POST['remove_'.$field]) && $existing) {

                    deleteDespatchStoredFile($existing, $legacyFolder);

                    return '';

                }

                return $existing;

            };

            $cur_dc = $removeDoc('doc_delivery_challan', $despatch['doc_delivery_challan'] ?? '', 'delivery_docs');

            $cur_vr = $removeDoc('doc_vendor_receipt',   $despatch['doc_vendor_receipt']   ?? '', 'delivery_docs');

            $cur_wb = $removeDoc('doc_weightbridge',     $despatch['doc_weightbridge']     ?? '', 'delivery_docs');

            $doc_dc = handleDocUpload('doc_delivery_challan', $cur_dc, $id, 'DC', 'despatch/delivery_docs', 'delivery_docs');

            $doc_vr = handleDocUpload('doc_vendor_receipt',   $cur_vr, $id, 'VR', 'despatch/delivery_docs', 'delivery_docs');

            $doc_wb = handleDocUpload('doc_weightbridge',     $cur_wb, $id, 'WB', 'despatch/delivery_docs', 'delivery_docs');

            $esc = fn($v) => $db->real_escape_string($v);

            $db->query("UPDATE despatch_orders SET

                doc_delivery_challan='{$esc($doc_dc)}',

                doc_vendor_receipt='{$esc($doc_vr)}',

                doc_weightbridge='{$esc($doc_wb)}'

                WHERE id=$id");

        }
        despatchPerfMark('delivery_docs_checked');

        // Handle Transporter Freight Invoice

        if ($data['status'] === 'Delivered') {

            $fi_no     = $db->real_escape_string(sanitize($_POST['freight_inv_no']     ?? ''));

            $fi_date   = sanitize($_POST['freight_inv_date']   ?? ''); if ($fi_date === '') $fi_date = null;

            $fi_amount = (float)($_POST['freight_inv_amount']  ?? 0);

            $fi_type   = in_array($_POST['freight_inv_type'] ?? '', ['Scan','Digital']) ? $_POST['freight_inv_type'] : '';

            $fi_hc     = ($fi_type === 'Scan') ? (int)(!empty($_POST['freight_inv_hardcopy'])) : 0;

            if ($fi_type === 'Digital') $fi_hc = 1; // digital = no hard copy needed

            // Upload invoice file

            $fi_existing = $despatch['freight_inv_file'] ?? '';

            if (!empty($_POST['remove_freight_inv_file']) && $fi_existing) {

                deleteDespatchStoredFile($fi_existing, 'freight_invoices');

                $fi_existing = '';

            }

            $fi_file = handleDocUpload('freight_inv_file', $fi_existing, $id, 'FI', 'despatch/freight_invoices', 'freight_invoices');

            $fi_date_sql = $fi_date ? "'".$db->real_escape_string($fi_date)."'" : 'NULL';

            $db->query("UPDATE despatch_orders SET

                freight_inv_no='$fi_no', freight_inv_date=$fi_date_sql,

                freight_inv_amount=$fi_amount, freight_inv_type='$fi_type',

                freight_inv_file='".$db->real_escape_string($fi_file)."',

                freight_inv_hardcopy=$fi_hc

                WHERE id=$id");

        }

        // ── Commission Calculation on Delivered ──

        if ($data['status'] === 'Delivered') {

            $agent_id = (int)($data['agent_id'] ?? ($_POST['agent_id'] ?? 0));

            if ($agent_id > 0) {

                // Get agent slab settings

                $agent = $db->query("SELECT full_name, is_agent, slab1_upto, slab1_pct, slab2_pct

                    FROM app_users WHERE id=$agent_id AND is_agent=1")->fetch_assoc();

                if ($agent) {

                    $weight = (float)$data['total_weight'];



                    // ── Recalculate freight_amount using stored rate × actual weight ──

                    $stored_rate = (float)($data['transporter_rate_per_mt'] ?? 0);
                    if ($stored_rate <= 0) {
                        $stored_rate = (float)($active_rate_row['rate'] ?? 0);
                    }
                    if ($stored_rate > 0 && $weight > 0) {
                        $recalc_freight = round($stored_rate * $weight, 2);
                        $data['freight_amount'] = $recalc_freight;

                        $db->query("UPDATE despatch_orders SET freight_amount=$recalc_freight WHERE id=$id");

                    }

                    $vendor_rate = 0;

                    $po_id_comm  = (int)($data['po_id'] ?? 0);

                    $v_id        = (int)$data['vendor_id'];

                    if ($po_id_comm > 0) {

                        // Get unit_price from PO items (first item's price as rate)

                        $po_rate_row = $db->query("SELECT unit_price FROM po_items

                            WHERE po_id=$po_id_comm LIMIT 1")->fetch_assoc();

                        $vendor_rate = (float)($po_rate_row['unit_price'] ?? 0);

                    }
                    // Transporter rate from rate card
                    $trans_rate = (float)($active_rate_row['rate'] ?? 0);
                    $profit_per_mt = round($vendor_rate - $trans_rate, 4);

                    $threshold     = (float)$agent['slab1_upto'];

                    $slab          = ($profit_per_mt <= $threshold) ? 1 : 2;

                    $pct           = $slab === 1 ? (float)$agent['slab1_pct'] : (float)$agent['slab2_pct'];

                    $comm_amt      = round($profit_per_mt * ($pct / 100) * $weight, 2);

                    $vendor_name   = $db->real_escape_string($data['consignee_name'] ?? '');

                    $challan_no    = $db->real_escape_string($data['challan_no'] ?? '');

                    if (empty($challan_no) && $id > 0) {

                        // For existing records, fetch challan_no from DB

                        $cn_row = $db->query("SELECT challan_no, despatch_date FROM despatch_orders WHERE id=$id")->fetch_assoc();

                        $challan_no = $db->real_escape_string($cn_row['challan_no'] ?? '');

                        $data['despatch_date'] = $data['despatch_date'] ?: ($cn_row['despatch_date'] ?? '');

                    }

                    $desp_date     = $data['despatch_date'] ?? null;
                    $desp_date_sql = $desp_date ? "'".$db->real_escape_string($desp_date)."'" : 'NULL';
                    $db->query("DELETE FROM agent_commissions WHERE despatch_id=$id AND agent_id<>$agent_id AND status='Pending'");
                    // Upsert commission record
                    $db->query("INSERT INTO agent_commissions
                        (despatch_id, agent_id, challan_no, despatch_date, vendor_name,
                         received_weight, vendor_rate, transporter_rate, profit_per_mt,
                         slab_applied, commission_pct, commission_amt, status)

                        VALUES ($id, $agent_id, '$challan_no', $desp_date_sql, '$vendor_name',

                                $weight, $vendor_rate, $trans_rate, $profit_per_mt,

                                $slab, $pct, $comm_amt, 'Pending')

                        ON DUPLICATE KEY UPDATE

                            agent_id=$agent_id,

                            challan_no='$challan_no', despatch_date=$desp_date_sql,

                            vendor_name='$vendor_name', received_weight=$weight,

                            vendor_rate=$vendor_rate, transporter_rate=$trans_rate,

                            profit_per_mt=$profit_per_mt, slab_applied=$slab,

                            commission_pct=$pct, commission_amt=$comm_amt,

                            status=IF(status='Paid','Paid','Pending')");

                }

            } else {

                // Agent removed or not set — remove any pending commission for this despatch

                $db->query("DELETE FROM agent_commissions WHERE despatch_id=$id AND status='Pending'");

            }

        } else {

            // Status is NOT Delivered - clean up any pending commissions only

            $db->query("DELETE FROM agent_commissions WHERE despatch_id=$id AND status='Pending'");


        }
        despatchPerfMark('commission_checked');



        showAlert('success', 'Despatch Order saved successfully.');

        skip_redirect:
        despatchPerfMark('before_redirect');
        despatchPerfLogIfSlow('despatch_save_post');

        redirect('despatch.php');

    }

}



/* ── AJAX: fetch transporter-vendor rate ── */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_get_rate'])) {

    header('Content-Type: application/json');

    $tid = (int)($_POST['transporter_id'] ?? 0);

    $vid = (int)($_POST['vendor_id']      ?? 0);

    if ($tid && $vid) {

        $row = $db->query("SELECT tr.rate, tr.uom, v.vendor_name

            FROM transporter_rates tr

            JOIN vendors v ON tr.vendor_id = v.id

            WHERE tr.transporter_id=$tid AND tr.vendor_id=$vid AND tr.status='Active'

            ORDER BY tr.id DESC LIMIT 1")->fetch_assoc();

        if ($row) {

            echo json_encode(['ok'=>true, 'rate'=>(float)$row['rate'], 'uom'=>$row['uom'], 'vendor'=>$row['vendor_name']]);

        } else {

            echo json_encode(['ok'=>false, 'msg'=>'No rate defined for this transporter-vendor combination.']);

        }

    } else {

        echo json_encode(['ok'=>false, 'msg'=>'Invalid parameters.']);

    }

    exit;

}



$despatch = [];

$despatch_items = [];

if (($action == 'edit') && $id > 0) {

    $despatch = $db->query("SELECT * FROM despatch_orders WHERE id=$id")->fetch_assoc();

    $despatch_items = $db->query("SELECT di.*, i.item_name FROM despatch_items di JOIN items i ON di.item_id=i.id WHERE di.despatch_id=$id")->fetch_all(MYSQLI_ASSOC);

    $current_despatch_po_id = (int)($despatch['po_id'] ?? 0);

}

// Lock form if existing record is Delivered

$is_admin_user = isAdmin();
$is_delivered = ($action === 'edit' && !empty($despatch) && ($despatch['status'] ?? '') === 'Delivered');
// Non-admins are locked when status is Delivered; Admins have full edit permission
$is_locked = ($is_delivered && !$is_admin_user);
$can_edit_vendor_po_transporter = ($action === 'add' || $is_admin_user);



/* ── Sources of material ── */

$sources_list = [];
$email_users = [];
$po_balance_map = [];
$vendors = [];
$transporters = [];
$pos = [];
$po_vendor_map = [];
$po_items_map = [];
$po_gst_map = [];
$po_items_detail = [];
$items_list = [];
$agents_list = [];
$chl_no = $despatch['challan_no'] ?? '';
$dsp_no = $despatch['despatch_no'] ?? '';
$despatch_date_val = $despatch['despatch_date'] ?? date('Y-m-d');
$all_companies = [];

if ($action === 'list') {
    $email_users = $db->query("SELECT id, full_name, email, role FROM app_users WHERE status='Active' AND email != '' ORDER BY role ASC, full_name ASC")->fetch_all(MYSQLI_ASSOC);
} elseif ($is_form_action) {
    $sources_list = $db->query("SELECT id, source_name FROM source_of_material WHERE status='Active' ORDER BY source_name")->fetch_all(MYSQLI_ASSOC);

    $po_bal_res = $db->query("
        SELECT pi.po_id, pi.item_id, i.item_name, pi.qty AS po_qty,
               COALESCE(SUM(di.weight),0) AS despatched_qty
        FROM po_items pi
        JOIN items i ON pi.item_id = i.id
        LEFT JOIN despatch_items di ON di.item_id = pi.item_id
            AND di.despatch_id IN (
                SELECT id FROM despatch_orders
                WHERE po_id = pi.po_id AND status NOT IN ('Cancelled','Draft')
            )
        GROUP BY pi.po_id, pi.item_id
    ");
    if ($po_bal_res) {
        while ($pbr = $po_bal_res->fetch_assoc()) {
            $po_balance_map[(int)$pbr['po_id']][(int)$pbr['item_id']] = [
                'item_name'  => $pbr['item_name'],
                'po_qty'     => (float)$pbr['po_qty'],
                'despatched' => (float)$pbr['despatched_qty'],
                'balance'    => max(0, (float)$pbr['po_qty'] - (float)$pbr['despatched_qty']),
            ];
        }
    }

    $vendors = $db->query("
        SELECT id, vendor_code, vendor_name,
               ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_country, ship_gstin,
               ship_contact, ship_phone,
               bill_address, bill_city, bill_state, bill_pincode, bill_country, bill_gstin,
               address, city, state, pincode, country, gstin
        FROM vendors WHERE status='Active' ORDER BY vendor_name
    ")->fetch_all(MYSQLI_ASSOC);

    $transporters = $db->query("SELECT id, transporter_code, transporter_name FROM transporters WHERE status='Active' ORDER BY transporter_name")->fetch_all(MYSQLI_ASSOC);

    $po_extra_sql = $current_despatch_po_id > 0 ? " OR id=$current_despatch_po_id" : '';
    $pos = $db->query("SELECT id, po_number, vendor_id FROM purchase_orders WHERE status='Approved'$po_extra_sql ORDER BY po_date DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($pos as $p) {
        $po_vendor_map[(int)$p['id']] = (int)$p['vendor_id'];
    }

    $po_items_res = $db->query("SELECT pi.po_id, pi.item_id, pi.unit_price, pi.gst_rate, pi.qty,
        i.item_name, i.item_code, i.uom
        FROM po_items pi JOIN items i ON pi.item_id=i.id");
    if ($po_items_res) {
        while ($pirow = $po_items_res->fetch_assoc()) {
            $pid = (int)$pirow['po_id'];
            $iid = (int)$pirow['item_id'];
            $po_items_map[$pid][$iid] = (float)$pirow['unit_price'];
            $po_gst_map[$pid][$iid] = (float)$pirow['gst_rate'];
            $po_items_detail[$pid][] = [
                'item_id'    => $iid,
                'item_name'  => $pirow['item_name'],
                'item_code'  => $pirow['item_code'],
                'uom'        => $pirow['uom'],
                'unit_price' => (float)$pirow['unit_price'],
                'gst_rate'   => (float)$pirow['gst_rate'],
                'qty'        => (float)$pirow['qty'],
            ];
        }
    }

    $items_list = $db->query("SELECT id, item_code, item_name, uom FROM items WHERE status='Active' ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);
    $agents_list = $db->query("SELECT id, full_name FROM app_users WHERE is_agent=1 AND status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

    $chl_no = $despatch['challan_no'] ?? peekChallanNo($db);
    $dsp_no = $despatch['despatch_no'] ?? peekDespatchNo($db);
    $all_companies = $db->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);
}



include '../includes/header.php';

?>

<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-send-check me-2"></i>Despatch Orders';</script>



<?php if ($action == 'list'): ?>

<?php

$despatch_view = (($_GET['view'] ?? '') === 'register') ? 'register' : 'ops';

$is_register_view = $despatch_view === 'register';

?>

<div class="d-flex justify-content-between align-items-center mb-3">

    <h5 class="mb-0 fw-bold"><?= $is_register_view ? 'Despatch Register' : 'All Despatch Orders' ?></h5>

    <div class="d-flex gap-2">

        <?php if (isAdmin() || canDo('despatch','view')): ?>
        <a href="?action=list"
           class="btn btn-sm border-0 text-white fw-bold"

           style="<?= $is_register_view

               ? 'background:#60a5fa;box-shadow:inset 0 0 0 1px #2563eb;'

               : 'background:linear-gradient(135deg,#2563eb,#1d4ed8);box-shadow:0 8px 18px rgba(37,99,235,.28);' ?>">

            <i class="bi bi-list-task me-1"></i>Working List

        </a>

        <?php endif; ?>

        <?php if (isAdmin() || canDo('despatch_register','view')): ?>
        <a href="?action=list&view=register"
           class="btn btn-sm border-0 text-white fw-bold"

           style="<?= $is_register_view

               ? 'background:linear-gradient(135deg,#2563EB,#1E3A8A);box-shadow:0 8px 18px rgba(37,99,235,.28);'

               : 'background:#DBEAFE;box-shadow:inset 0 0 0 1px #1E3A8A;color:#172554 !important;' ?>">

            <i class="bi bi-journal-text me-1"></i>Despatch Register

        </a>

        <?php endif; ?>
        <?php if (!$is_register_view): ?>
        <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New Despatch</a>

        <?php endif; ?>

    </div>

</div>

<?php

// Fetch and group by Company -> Vendor

$filter_status = $_GET['status'] ?? 'All';

$status_where  = '';

$allowed_statuses = ['Draft','Despatched','In Transit','Delivered','Cancelled'];

if (!$is_register_view && in_array($filter_status, ['Delivered','Cancelled'], true)) {

    $filter_status = 'All';

}

if ($is_register_view && in_array($filter_status, ['Draft','Despatched','In Transit'], true)) {

    $filter_status = 'All';

}

if ($filter_status !== 'All' && in_array($filter_status, $allowed_statuses)) {

    $filter_status_esc = $db->real_escape_string($filter_status);

    $status_where = " AND d.status='$filter_status_esc'";

}

$despatch_user_filter = '';
if (!canViewAll('despatch')) {
    $despatch_user_filter = $is_agent
        ? " AND (d.created_by=$agent_uid OR d.agent_id=$agent_uid)"
        : " AND d.created_by=$__uid";
}

$list_scope_where = $is_register_view

    ? " AND d.status IN ('Delivered','Cancelled')"

    : " AND d.status NOT IN ('Delivered','Cancelled')";

$list = $db->query("SELECT d.*,t.transporter_name,v.vendor_name,c.company_name AS co_name,

        COALESCE(u.full_name, u.username, 'Unknown') AS created_by_name

    FROM despatch_orders d

    LEFT JOIN transporters t ON d.transporter_id=t.id

    LEFT JOIN vendors v ON d.vendor_id=v.id

    LEFT JOIN companies c ON d.company_id=c.id

    LEFT JOIN app_users u ON d.created_by=u.id

    WHERE 1=1" . $despatch_user_filter . $status_where . $list_scope_where . "

    ORDER BY co_name ASC, v.vendor_name ASC, d.despatch_date DESC, d.id DESC");

$rows = $list->fetch_all(MYSQLI_ASSOC);



// Group: company -> vendor -> orders

$grouped = [];

foreach ($rows as $v) {

    $co  = $v['co_name'] ?: 'General';

    $vnd = $v['vendor_name'] ?: 'Unknown Vendor';

    $grouped[$co][$vnd][] = $v;

}



// Count by status for badges

$status_counts = ['All' => 0];

foreach ($db->query("SELECT status, COUNT(*) c FROM despatch_orders d WHERE 1=1" . $despatch_user_filter . " GROUP BY status")->fetch_all(MYSQLI_ASSOC) as $sc) {

    $is_register_status = in_array($sc['status'], ['Delivered','Cancelled'], true);

    if ($is_register_view !== $is_register_status) continue;

    $status_counts[$sc['status']] = (int)$sc['c'];

    $status_counts['All'] += (int)$sc['c'];

}

$status_badge = ['Draft'=>'secondary','Despatched'=>'primary','In Transit'=>'warning','Delivered'=>'success','Cancelled'=>'danger'];

$status_tabs = $is_register_view

    ? ['All', 'Delivered', 'Cancelled']

    : ['All', 'Despatched', 'In Transit'];

?>

<!-- Status Filter -->

<div class="d-flex flex-wrap gap-2 mb-3">

    <?php

    $base_url = '?action=list' . ($is_register_view ? '&view=register' : '');

    foreach ($status_tabs as $st):

        $active  = $filter_status === $st ? 'active' : '';

        $bc      = $st === 'All' ? 'dark' : ($status_badge[$st] ?? 'secondary');

        $url     = $st === 'All' ? $base_url : $base_url.'&status='.urlencode($st);

        $cnt     = $status_counts[$st] ?? 0;

    ?>

    <a href="<?= $url ?>" class="btn btn-sm btn-<?= $active ? '' : 'outline-' ?><?= $bc ?> <?= $active ?>">

        <?= $st ?> <span class="badge bg-<?= $active ? 'light text-dark' : $bc ?> ms-1"><?= $cnt ?></span>

    </a>

    <?php endforeach; ?>

</div>

<!-- Search bar -->

<div class="px-0 mb-3">

    <input type="text" id="despatchSearch" class="form-control" placeholder="🔍 Search challan, vendor, transporter, status…">

</div>

<?php

$ci = 0;

foreach ($grouped as $co_name => $vendors):

    $ci++;

    $co_id    = 'co_'.$ci;

    $co_total = array_sum(array_column(array_merge(...array_values($vendors)), 'total_amount'));

    $co_count = array_sum(array_map('count', $vendors));

?>

<!-- Company Group -->

<div class="card mb-3 despatch-co-card" id="<?= $co_id ?>">

    <div class="card-header d-flex align-items-center gap-2 py-2"

         style="cursor:pointer;background:#1E3A8A;color:#fff"

         onclick="toggleDGroup('<?= $co_id ?>')">

        <i class="bi bi-chevron-down" id="chev_<?= $co_id ?>"></i>

        <i class="bi bi-buildings me-1"></i>

        <strong><?= htmlspecialchars($co_name) ?></strong>

        <span class="badge bg-light text-dark ms-1"><?= $co_count ?></span>

        <span class="ms-auto small opacity-75">₹<?= number_format($co_total,2) ?></span>

    </div>

    <div id="body_<?= $co_id ?>" class="card-body p-0">

    <?php

    $vi = 0;

    foreach ($vendors as $vnd_name => $orders):

        $vi++;

        $vnd_id    = $co_id.'_v'.$vi;

        $vnd_total = array_sum(array_column($orders, 'total_amount'));

        $vnd_wt    = array_sum(array_column($orders, 'total_weight'));

    ?>

    <!-- Vendor Sub-Group -->

    <div class="despatch-vnd-card border-bottom" id="<?= $vnd_id ?>">

        <div class="d-flex align-items-center gap-2 px-3 py-2 despatch-vnd-head"

             style="cursor:pointer;background:#EFF6FF;border-left:4px solid #334155"

             onclick="toggleDGroup('<?= $vnd_id ?>')">

            <i class="bi bi-chevron-down" id="chev_<?= $vnd_id ?>" style="font-size:.75rem"></i>

            <i class="bi bi-building text-primary"></i>

            <span class="fw-semibold"><?= htmlspecialchars($vnd_name) ?></span>

            <span class="badge bg-secondary ms-1"><?= count($orders) ?></span>

            <span class="ms-auto text-muted small"><?= number_format($vnd_wt,2) ?> MT &nbsp;|&nbsp; ₹<?= number_format($vnd_total,2) ?></span>

        </div>

        <div id="body_<?= $vnd_id ?>">

        <?php foreach ($orders as $v):

            $b=['Draft'=>'secondary','Despatched'=>'primary','In Transit'=>'warning','Delivered'=>'success','Cancelled'=>'danger'][$v['status']] ?? 'secondary';

        ?>

        <div class="despatch-row px-3 py-2 border-bottom d-flex align-items-start gap-2 flex-wrap" style="background:#fff">

            <!-- Main info -->

            <div class="flex-grow-1" style="min-width:180px">

                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">

                    <strong><?= htmlspecialchars($v['challan_no']) ?></strong>

                    <span class="badge bg-<?= $b ?>"><?= $v['status'] ?></span>

                    <span class="text-muted small"><?= date('d/m/Y', strtotime($v['despatch_date'])) ?></span>

                </div>

                <div class="text-muted small"><?= htmlspecialchars($v['transporter_name'] ?? '-') ?> &nbsp;|&nbsp; ₹<?= number_format((float)$v['total_amount'],2) ?></div>

                <div class="text-muted small"><i class="bi bi-person me-1"></i>Created by: <?= htmlspecialchars($v['created_by_name'] ?? 'Unknown') ?></div>

            </div>

            <!-- Action buttons — dropdown on mobile, inline on desktop -->

            <div class="d-flex gap-1 flex-nowrap align-items-center">

                <!-- Desktop: show all buttons -->

                <div class="d-none d-sm-flex gap-1">

                    <a href="print_challan.php?id=<?= $v['id'] ?>" target="_blank"

                       class="btn btn-sm btn-outline-success" title="Print"><i class="bi bi-printer"></i></a>

                    <a href="export_challan_pdf.php?id=<?= $v['id'] ?>&download"

                       class="btn btn-sm btn-outline-danger" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>

                    <button onclick="openEmailModal(<?= $v['id'] ?>,<?= htmlspecialchars(json_encode($v['challan_no'])) ?>,<?= htmlspecialchars(json_encode($v['consignee_name'])) ?>)"

                            class="btn btn-sm btn-outline-info" title="Email"><i class="bi bi-envelope"></i></button>

                    <a href="?action=edit&id=<?= $v['id'] ?>"

                       class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>

                    <?php if (isAdmin() && !in_array($v['status'],['Cancelled','Delivered'])): ?>

                    <a href="?cancel_id=<?= $v['id'] ?>" onclick="return confirm('Cancel this despatch order?')"

                       class="btn btn-sm btn-outline-warning" title="Cancel"><i class="bi bi-x-circle"></i></a>

                    <?php endif; ?>

                    <?php if (isAdmin()): ?>

                    <button onclick="confirmDelete(<?= $v['id'] ?>,'despatch.php')"

                            class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>

                    <?php endif; ?>

                </div>

                <!-- Mobile: dropdown -->

                <div class="d-sm-none dropdown">

                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">

                        <i class="bi bi-three-dots-vertical"></i>

                    </button>

                    <ul class="dropdown-menu dropdown-menu-end">

                        <li><a class="dropdown-item" href="print_challan.php?id=<?= $v['id'] ?>" target="_blank"><i class="bi bi-printer me-2 text-primary"></i>Print</a></li>

                        <li><a class="dropdown-item" href="export_challan_pdf.php?id=<?= $v['id'] ?>&download"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Download PDF</a></li>

                        <li><a class="dropdown-item" href="#" onclick="openEmailModal(<?= $v['id'] ?>,<?= htmlspecialchars(json_encode($v['challan_no'])) ?>,<?= htmlspecialchars(json_encode($v['consignee_name'])) ?>);return false"><i class="bi bi-envelope me-2 text-info"></i>Email</a></li>

                        <li><a class="dropdown-item" href="?action=edit&id=<?= $v['id'] ?>"><i class="bi bi-pencil me-2 text-primary"></i>Edit</a></li>

                        <?php if (isAdmin() && !in_array($v['status'],['Cancelled','Delivered'])): ?>

                        <li><a class="dropdown-item text-warning" href="?cancel_id=<?= $v['id'] ?>" onclick="return confirm('Cancel this order?')"><i class="bi bi-x-circle me-2"></i>Cancel</a></li>

                        <?php endif; ?>

                        <?php if (isAdmin()): ?>

                        <li><hr class="dropdown-divider"></li>

                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $v['id'] ?>,'despatch.php');return false"><i class="bi bi-trash me-2"></i>Delete</a></li>

                        <?php endif; ?>

                    </ul>

                </div>

            </div>

        </div>

        <?php endforeach; ?>

        </div>

    </div>

    <?php endforeach; ?>

    </div>

</div>

<?php endforeach; ?>



<style>

#despatchSearch { max-width:400px; }

.despatch-row:hover { background:#f8fff8 !important; }

/* Dark mode fixes for despatch list grouping rows */
body.dark-mode .despatch-vnd-head {
    background: #1e293b !important;
    border-left-color: #60a5fa !important;
    color: #e5e7eb !important;
}
body.dark-mode .despatch-vnd-head .fw-semibold,
body.dark-mode .despatch-vnd-head .text-muted,
body.dark-mode .despatch-vnd-head .small,
body.dark-mode .despatch-vnd-head i {
    color: #e5e7eb !important;
}
body.dark-mode .despatch-row[style*="background:#fff"],
body.dark-mode .despatch-row {
    background: #0f172a !important;
    color: #e5e7eb !important;
}
body.dark-mode .despatch-row .text-muted,
body.dark-mode .despatch-row .small {
    color: #cbd5e1 !important;
}
body.dark-mode .despatch-row:hover {
    background: #1e293b !important;
}

@media(max-width:576px){

    .despatch-row { font-size:.85rem; }

}

</style>

<script>

function toggleDGroup(id) {

    var body = document.getElementById('body_' + id);

    var chev = document.getElementById('chev_' + id);

    if (!body) return;

    var hidden = body.style.display === 'none';

    body.style.display = hidden ? '' : 'none';

    chev.className = hidden ? 'bi bi-chevron-down' : 'bi bi-chevron-right';

    if (id.indexOf('_v') === -1) chev.style.fontSize = '';

    else chev.style.fontSize = '.75rem';

}

document.getElementById('despatchSearch').addEventListener('input', function(){

    var q = this.value.toLowerCase();

    document.querySelectorAll('.despatch-vnd-card').forEach(function(vcard){

        var rows = vcard.querySelectorAll('.despatch-row');

        var anyMatch = false;

        rows.forEach(function(r){

            var match = !q || r.textContent.toLowerCase().includes(q);

            r.style.display = match ? '' : 'none';

            if (match) anyMatch = true;

        });

        vcard.style.display = (!q || anyMatch) ? '' : 'none';

        if (anyMatch && q) {

            var vbody = vcard.querySelector('[id^="body_"]');

            if (vbody) vbody.style.display = '';

        }

    });

    document.querySelectorAll('.despatch-co-card').forEach(function(cocard){

        var vis = Array.from(cocard.querySelectorAll('.despatch-vnd-card')).some(function(v){ return v.style.display !== 'none'; });

        cocard.style.display = (!q || vis) ? '' : 'none';

        if (vis && q) {

            var cobody = document.getElementById('body_' + cocard.id);

            if (cobody) cobody.style.display = '';

        }

    });

});

</script>



<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">

    <h5 class="mb-0 fw-bold"><?= $action=='edit'?'Edit':'New' ?> Despatch Order</h5>

    <a href="despatch.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>

</div>

<style>
.despatch-saving-banner {
    display: none;
    position: sticky;
    top: 78px;
    z-index: 1020;
    margin-bottom: 14px;
}
.despatch-saving-banner.show {
    display: block;
}
</style>

<style>
.dms-weather-card {
    background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%);
    border: 1px solid #bae6fd;
    border-left: 4px solid #0284c7;
    border-radius: 8px;
    padding: 10px 14px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    font-size: 0.85rem;
    transition: all 0.2s ease;
}
.dms-weather-card:hover {
    box-shadow: 0 4px 12px rgba(2,132,199,0.12);
}
.dms-weather-temp {
    font-size: 1.35rem;
    font-weight: 700;
    color: #0369a1;
    line-height: 1;
}
.dms-weather-icon {
    font-size: 1.6rem;
    line-height: 1;
}
.dms-weather-risk-low {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.dms-weather-risk-med {
    background: #fef9c3;
    color: #a16207;
    border: 1px solid #fde047;
}
.dms-weather-risk-high {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fca5a5;
    animation: dmsPulse 2s infinite;
}
@keyframes dmsPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}
.dms-forecast-day {
    background: rgba(255,255,255,0.7);
    border: 1px solid #e0f2fe;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 0.76rem;
    text-align: center;
    min-width: 95px;
}
</style>

<script>
if (!window.DMSWeather) {
window.DMSWeather = (function() {
    var cache = {};

    var WMO_CODES = {
        0:  { label: 'Clear Sky', icon: '☀️', risk: 'Low' },
        1:  { label: 'Mainly Clear', icon: '🌤️', risk: 'Low' },
        2:  { label: 'Partly Cloudy', icon: '⛅', risk: 'Low' },
        3:  { label: 'Overcast / Cloudy', icon: '☁️', risk: 'Low' },
        45: { label: 'Foggy Conditions', icon: '🌫️', risk: 'Caution (Fog)' },
        48: { label: 'Depositing Rime Fog', icon: '🌫️', risk: 'Caution (Fog)' },
        51: { label: 'Light Drizzle', icon: '🌦️', risk: 'Drizzle' },
        53: { label: 'Moderate Drizzle', icon: '🌦️', risk: 'Drizzle' },
        55: { label: 'Dense Drizzle', icon: '🌧️', risk: 'Wet Transit' },
        61: { label: 'Slight Rain', icon: '🌧️', risk: 'Rain Alert' },
        63: { label: 'Moderate Rain', icon: '🌧️', risk: 'Rain Alert' },
        65: { label: 'Heavy Rain', icon: '⛈️', risk: 'Heavy Rain Alert' },
        71: { label: 'Slight Snow', icon: '🌨️', risk: 'Snow' },
        73: { label: 'Moderate Snow', icon: '🌨️', risk: 'Snow' },
        75: { label: 'Heavy Snow', icon: '❄️', risk: 'Heavy Snow' },
        80: { label: 'Slight Showers', icon: '🌦️', risk: 'Rain Showers' },
        81: { label: 'Moderate Showers', icon: '🌧️', risk: 'Rain Showers' },
        82: { label: 'Violent Showers', icon: '⛈️', risk: 'Heavy Showers' },
        95: { label: 'Thunderstorm', icon: '⛈️', risk: 'Thunderstorm Alert' },
        96: { label: 'Thunderstorm with Hail', icon: '⛈️', risk: 'Severe Weather' },
        99: { label: 'Heavy Thunderstorm', icon: '⚡', risk: 'Severe Thunderstorm' }
    };

    function getWeatherInfo(code) {
        return WMO_CODES[code] || { label: 'Variable Weather', icon: '⛅', risk: 'Normal' };
    }

    function cleanLocationString(raw) {
        if (!raw || typeof raw !== 'string') return [];
        var s = raw.replace(/\b\d{6}\b/g, '').replace(/[\(\)\[\]\{\}]/g, ' ');
        s = s.replace(/\b(Pvt|Ltd|Limited|Unit|Site|Camp|Plant|Near|Opposite|Road|Nagar|Colony|Lane|Phase|Sector)\b/gi, ' ');
        var parts = s.split(/[,/\n\-]+/).map(function(p) { return p.trim(); }).filter(function(p) { return p.length >= 3; });
        var candidates = [];
        for (var i = parts.length - 1; i >= 0; i--) {
            var token = parts[i];
            if (!/^(india|up|mp|bihar|uttar pradesh|madhya pradesh)$/i.test(token)) {
                candidates.push(token);
            }
        }
        if (parts.length > 0) candidates.push(parts[0]);
        candidates.push(s.trim());
        return Array.from(new Set(candidates));
    }

    async function geocode(query) {
        var cleanQuery = query.trim();
        var cacheKey = 'geo_' + cleanQuery.toLowerCase();
        if (cache[cacheKey]) return cache[cacheKey];

        var candidates = cleanLocationString(cleanQuery);
        for (var i = 0; i < candidates.length; i++) {
            var cand = candidates[i];
            if (!cand || cand.length < 2) continue;
            try {
                var url = 'https://geocoding-api.open-meteo.com/v1/search?name=' + encodeURIComponent(cand) + '&count=1&language=en&format=json';
                var res = await fetch(url);
                if (!res.ok) continue;
                var data = await res.json();
                if (data && data.results && data.results.length > 0) {
                    var match = data.results[0];
                    cache[cacheKey] = match;
                    return match;
                }
            } catch (e) {
                // Continue to next candidate
            }
        }
        return null;
    }

    async function fetchForecast(lat, lon) {
        var cacheKey = 'w_' + lat.toFixed(2) + '_' + lon.toFixed(2);
        if (cache[cacheKey] && (Date.now() - cache[cacheKey].ts < 15 * 60 * 1000)) {
            return cache[cacheKey].data;
        }
        var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + lat + '&longitude=' + lon +
                  '&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m' +
                  '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=auto';
        var res = await fetch(url);
        if (!res.ok) throw new Error('Weather fetch failed');
        var data = await res.json();
        cache[cacheKey] = { ts: Date.now(), data: data };
        return data;
    }

    function renderWidgetHtml(geo, weather) {
        var current = weather.current || {};
        var daily = weather.daily || {};
        var wCode = current.weather_code !== undefined ? current.weather_code : 0;
        var wInfo = getWeatherInfo(wCode);
        var temp = Math.round(current.temperature_2m || 0);
        var feels = Math.round(current.apparent_temperature || temp);
        var humidity = current.relative_humidity_2m || 0;
        var wind = Math.round(current.wind_speed_10m || 0);
        
        var rainProbToday = (daily.precipitation_probability_max && daily.precipitation_probability_max[0] !== undefined)
            ? daily.precipitation_probability_max[0] : 0;
        var rainProbTomorrow = (daily.precipitation_probability_max && daily.precipitation_probability_max[1] !== undefined)
            ? daily.precipitation_probability_max[1] : 0;

        var riskClass = 'dms-weather-risk-low';
        var riskIcon = 'bi-check-circle-fill';
        var riskText = 'Low Rain Risk (' + rainProbToday + '%)';

        if (rainProbToday > 50 || wCode >= 61) {
            riskClass = 'dms-weather-risk-high';
            riskIcon = 'bi-exclamation-triangle-fill';
            riskText = 'High Rain Alert (' + rainProbToday + '%) — Ensure Tarping';
        } else if (rainProbToday >= 20 || (wCode >= 51 && wCode <= 55)) {
            riskClass = 'dms-weather-risk-med';
            riskIcon = 'bi-cloud-rain-fill';
            riskText = 'Moderate Rain Risk (' + rainProbToday + '%)';
        }

        var locName = geo.name;
        if (geo.admin1 && geo.admin1 !== geo.name) locName += ', ' + geo.admin1;

        var forecastHtml = '';
        if (daily.time && daily.time.length >= 2) {
            var days = ['Today', 'Tomorrow'];
            for (var d = 0; d < 2; d++) {
                var dCode = daily.weather_code ? daily.weather_code[d] : 0;
                var dInfo = getWeatherInfo(dCode);
                var dMax = Math.round(daily.temperature_2m_max ? daily.temperature_2m_max[d] : 0);
                var dMin = Math.round(daily.temperature_2m_min ? daily.temperature_2m_min[d] : 0);
                var dRain = daily.precipitation_probability_max ? daily.precipitation_probability_max[d] : 0;

                forecastHtml += '<div class="dms-forecast-day">' +
                    '<div class="fw-semibold text-muted" style="font-size:0.7rem">' + days[d] + '</div>' +
                    '<div class="my-1">' + dInfo.icon + ' <span class="fw-bold">' + dMax + '°</span> <small class="text-muted">' + dMin + '°</small></div>' +
                    '<div class="' + (dRain > 40 ? 'text-danger fw-bold' : 'text-muted') + '" style="font-size:0.68rem"><i class="bi bi-droplet-fill text-info"></i> ' + dRain + '% Rain</div>' +
                '</div>';
            }
        }

        return '<div class="dms-weather-card">' +
            '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">' +
                '<div class="d-flex align-items-center gap-3">' +
                    '<div class="dms-weather-icon">' + wInfo.icon + '</div>' +
                    '<div>' +
                        '<div class="d-flex align-items-baseline gap-2">' +
                            '<span class="dms-weather-temp">' + temp + '°C</span>' +
                            '<span class="text-muted small">Feels ' + feels + '°C &bull; ' + wInfo.label + '</span>' +
                        '</div>' +
                        '<div class="small fw-semibold text-dark">' +
                            '<i class="bi bi-geo-alt-fill text-danger me-1"></i>' + locName +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                    '<div class="badge ' + riskClass + ' px-2 py-1" style="font-size:0.75rem">' +
                        '<i class="bi ' + riskIcon + ' me-1"></i>' + riskText +
                    '</div>' +
                    '<div class="small text-muted border-start ps-2 d-none d-sm-block">' +
                        '<div><i class="bi bi-wind me-1 text-secondary"></i>' + wind + ' km/h</div>' +
                        '<div><i class="bi bi-moisture me-1 text-info"></i>' + humidity + '% Hum.</div>' +
                    '</div>' +
                    '<div class="d-flex gap-1 ms-1 d-none d-md-flex">' +
                        forecastHtml +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    async function updateWeatherForLocation(targetContainerId, locationText) {
        var container = document.getElementById(targetContainerId);
        if (!container) return;
        var clean = (locationText || '').trim();
        if (!clean || clean.length < 2) {
            container.innerHTML = '';
            container.classList.add('d-none');
            return;
        }

        container.classList.remove('d-none');
        container.innerHTML = '<div class="dms-weather-card py-2 text-muted small"><i class="bi bi-arrow-repeat spin me-1"></i> Fetching live delivery weather for ' + clean + '...</div>';

        try {
            var geo = await geocode(clean);
            if (!geo) {
                container.innerHTML = '<div class="dms-weather-card py-1 text-muted small" style="border-left-color:#94a3b8"><i class="bi bi-cloud-slash me-1"></i> Delivery weather unavailable for "' + clean + '"</div>';
                return;
            }
            var forecast = await fetchForecast(geo.latitude, geo.longitude);
            container.innerHTML = renderWidgetHtml(geo, forecast);
        } catch (err) {
            container.innerHTML = '<div class="dms-weather-card py-1 text-muted small" style="border-left-color:#f87171"><i class="bi bi-exclamation-circle text-danger me-1"></i> Could not load live weather. <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="window.DMSWeather.load(\'' + targetContainerId + '\', \'' + clean.replace(/'/g, "\\'") + '\')">Retry</button></div>';
        }
    }

    var debounceTimers = {};

    return {
        load: function(containerId, locationText) {
            updateWeatherForLocation(containerId, locationText);
        },
        attach: function(containerId, inputElementOrSelector) {
            var inputEl = (typeof inputElementOrSelector === 'string')
                ? document.querySelector(inputElementOrSelector)
                : inputElementOrSelector;
            if (!inputEl) return;

            function trigger() {
                var val = inputEl.value;
                if (debounceTimers[containerId]) clearTimeout(debounceTimers[containerId]);
                debounceTimers[containerId] = setTimeout(function() {
                    updateWeatherForLocation(containerId, val);
                }, 400);
            }

            inputEl.addEventListener('input', trigger);
            inputEl.addEventListener('change', trigger);
            if (inputEl.value) trigger();
        }
    };
})();
}
</script>

<form method="POST" id="despatchForm" enctype="multipart/form-data" data-skip-saving-overlay>
<?php if (!$is_locked): ?>
<div id="despatchSavingBanner" class="alert alert-info border-0 shadow-sm despatch-saving-banner" role="status" aria-live="polite">
    <div class="d-flex align-items-center gap-2">
        <span class="spinner-border spinner-border-sm flex-shrink-0"></span>
        <div>
            <div class="fw-semibold">Saving despatch order...</div>
            <div class="small text-muted">Please wait while challan items, freight, and related calculations are updated.</div>
        </div>
    </div>
</div>
<?php else: ?>
<div id="despatchSavingBanner" class="alert alert-info border-0 shadow-sm despatch-saving-banner" role="status" aria-live="polite">
    <div class="d-flex align-items-center gap-2">
        <span class="spinner-border spinner-border-sm flex-shrink-0"></span>
        <div>
            <div class="fw-semibold">Updating documents...</div>
            <div class="small text-muted">Please wait while files are uploaded and linked to this despatch order.</div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($is_locked): ?>
<input type="hidden" name="docs_only" value="1">
<?php endif; ?>
<input type="hidden" name="process_docs" value="0">

<?php if ($is_locked): ?>

<div class="alert alert-warning d-flex align-items-center gap-2 mb-3 py-2 border-warning" style="background:linear-gradient(135deg,#fff3cd,#fff8e1)">

    <i class="bi bi-lock-fill fs-5 text-warning"></i>

    <div>

        <strong>This Despatch Order is Delivered — main details are locked.</strong><br>

        <small class="text-muted">You can correct <strong>Weight (MT)</strong> in Despatch Items, update <strong>Misc Expenses</strong> in Transport Details, and upload/edit <strong>Delivery Documents</strong> and <strong>Freight Invoice</strong> below. Commission and freight are recalculated automatically on save.</small>

    </div>

</div>

<?php endif; ?>

<div class="row g-3">

    <!-- Basic Info -->

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Despatch Information</div>

    <div class="card-body"><div class="row g-3">

        <?php if (count($all_companies) > 1): ?>

        <div class="col-12 col-sm-6 col-md-3">

            <label class="form-label">Company *</label>

            <select name="company_id" class="form-select" required>

                <?php foreach ($all_companies as $co): ?>

                <option value="<?= $co['id'] ?>"

                    <?= ($despatch['company_id'] ?? activeCompanyId()) == $co['id'] ? 'selected' : '' ?>>

                    <?= htmlspecialchars($co['company_name']) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>

        <?php else: ?>

        <input type="hidden" name="company_id" value="<?= activeCompanyId() ?>">

        <?php endif; ?>

        <div class="col-8 col-sm-5 col-md-3">

            <label class="form-label">Challan No cum LR No</label>

            <input type="text" name="manual_challan_no" class="form-control fw-bold text-primary"

                   value="<?= htmlspecialchars($chl_no) ?>"

                   style="letter-spacing:0.5px">

            <div class="form-text text-muted"><i class="bi bi-pencil-fill me-1"></i>Edit if needed</div>

        </div>

        <div class="col-4 col-sm-3 col-md-2">

            <label class="form-label">Despatch Date *</label>

            <input type="date" name="despatch_date" id="despatch_date" class="form-control" value="<?= $despatch_date_val ?>" required>

        </div>

        <div class="col-6 col-sm-4 col-md-2">

            <label class="form-label">Status</label>

            <select name="status" class="form-select" onchange="onStatusChange(this)">

                <?php foreach(['In Transit','Delivered','Cancelled'] as $s): ?>
                <?php if ($s === 'Cancelled' && !isAdmin() && ($despatch['status'] ?? 'Draft') !== 'Cancelled') continue; ?>
                <option value="<?= $s ?>" <?= ($despatch['status']??'Draft')==$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>

            </select>

        </div>

        <div class="col-12 col-sm-6 col-md-3">

            <label class="form-label d-flex justify-content-between align-items-center">
                <span>Vendor</span>
                <?php if ($action === 'edit' && !isAdmin()): ?>
                <span class="badge bg-secondary" style="font-size:10px"><i class="bi bi-lock-fill me-1"></i>Admin only</span>
                <?php endif; ?>
            </label>

            <?php if ($can_edit_vendor_po_transporter): ?>
            <select name="vendor_id" id="vendorSelect" class="form-select" onchange="fillConsigneeFromVendor(this); filterPOByVendor(this.value); filterTransportersByVendor(this.value); triggerDespatchWeatherFromVendor(this.value);">

                <option value="">-- Select Vendor --</option>

                <?php foreach($vendors as $v): ?>

                <option value="<?= $v['id'] ?>" <?= ($despatch['vendor_id']??0)==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['vendor_name']) ?></option>

                <?php endforeach; ?>

            </select>
            <?php else: ?>
            <select id="vendorSelect" class="form-select bg-light" disabled>
                <option value="">-- Select Vendor --</option>
                <?php foreach($vendors as $v): ?>
                <option value="<?= $v['id'] ?>" <?= ($despatch['vendor_id']??0)==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['vendor_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="vendor_id" value="<?= (int)($despatch['vendor_id']??0) ?>">
            <?php endif; ?>

        </div>

        <div class="col-6 col-sm-4 col-md-2">

            <label class="form-label d-flex justify-content-between align-items-center">
                <span>PO Reference</span>
                <?php if ($action === 'edit' && !isAdmin()): ?>
                <span class="badge bg-secondary" style="font-size:10px"><i class="bi bi-lock-fill me-1"></i>Admin only</span>
                <?php endif; ?>
            </label>

            <?php if ($can_edit_vendor_po_transporter): ?>
            <select name="po_id" id="poRefSelect" class="form-select" onchange="fillVendorFromPO(this); renderPOBalance(parseInt(this.value)||0);">

                <option value="">-- None --</option>

                <?php foreach($pos as $p): ?>

                <option value="<?= $p['id'] ?>" data-vendor="<?= $p['vendor_id'] ?>" <?= ($despatch['po_id']??0)==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['po_number']) ?></option>

                <?php endforeach; ?>

            </select>
            <?php else: ?>
            <select id="poRefSelect" class="form-select bg-light" disabled>
                <option value="">-- None --</option>
                <?php foreach($pos as $p): ?>
                <option value="<?= $p['id'] ?>" data-vendor="<?= $p['vendor_id'] ?>" <?= ($despatch['po_id']??0)==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['po_number']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="po_id" value="<?= (int)($despatch['po_id']??0) ?>">
            <?php endif; ?>

        </div>

        <div class="col-12 col-sm-8 col-md-4">

            <label class="form-label">Camp / Site / Unit</label>

            <input type="text" name="consignee_camp" id="consignee_camp" class="form-control"

                   placeholder="e.g. Camp 1, Camp 2" value="<?= htmlspecialchars($despatch['consignee_camp']??'') ?>">

        </div>

        <div class="col-12 col-sm-6 col-md-3">

            <label class="form-label fw-semibold">Source of Material *

                <a href="source_of_material.php?action=add" target="_blank" class="ms-1 text-primary small" title="Add new source">

                    <i class="bi bi-plus-circle"></i>

                </a>

            </label>

            <select name="source_of_material_id" id="sourceOfMaterial" class="form-select" required>

                <option value="">-- Select Source --</option>

                <?php foreach($sources_list as $src): ?>

                <option value="<?= $src['id'] ?>" <?= ($despatch['source_of_material_id']??0)==$src['id']?'selected':'' ?>>

                    <?= htmlspecialchars($src['source_name']) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>



        <!-- PO Balance Quantity -->

        <div class="col-12" id="poBalanceWrap" style="display:none">

            <label class="form-label fw-semibold text-primary">

                <i class="bi bi-bar-chart-steps me-1"></i>PO Balance Quantities

            </label>

            <div id="poBalanceTable"></div>

        </div>



    </div></div></div></div>







    <!-- Consignee -->

    <div class="col-12 col-md-6 d-flex flex-column d-none"><div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center">

        <span><i class="bi bi-geo-alt me-2"></i>Consignee Details</span>

        <span id="autofillBadge" class="badge bg-primary d-none">

            <i class="bi bi-magic me-1"></i>Auto-filled from Vendor Ship-To

        </span>

    </div>

    <div class="card-body"><div class="row g-2">

        <!-- Auto-fill notice strip (hidden until triggered) -->

        <div class="col-12" id="autofillNotice" style="display:none">

            <div class="alert alert-success alert-dismissible py-2 mb-2 d-flex align-items-center gap-2">

                <i class="bi bi-check-circle-fill"></i>

                <span>Consignee details auto-populated from <strong id="autofillVendorName"></strong>'s Ship-To address.

                You can edit any field below.</span>

                <button type="button" class="btn-close ms-auto" onclick="document.getElementById('autofillNotice').style.display='none'"></button>

            </div>

        </div>

        <div class="col-12">

            <label class="form-label">Consignee Name *</label>

            <input type="text" name="consignee_name" id="consignee_name" class="form-control" value="<?= htmlspecialchars($despatch['consignee_name']??'') ?>">

        </div>

        <div class="col-12">

            <label class="form-label">Address</label>

            <textarea name="consignee_address" id="consignee_address" class="form-control" rows="1"><?= htmlspecialchars($despatch['consignee_address']??'') ?></textarea>

        </div>

        <div class="col-12 col-sm-6 col-md-4">

            <label class="form-label">City</label>

            <input type="text" name="consignee_city" id="consignee_city" class="form-control" value="<?= htmlspecialchars($despatch['consignee_city']??'') ?>" oninput="if(window.DMSWeather) window.DMSWeather.load('despatchDeliveryWeatherWrap', this.value)" onchange="if(window.DMSWeather) window.DMSWeather.load('despatchDeliveryWeatherWrap', this.value)">

        </div>

        <div class="col-12 col-sm-6 col-md-4">

            <label class="form-label">State</label>

            <input type="text" name="consignee_state" id="consignee_state" class="form-control" value="<?= htmlspecialchars($despatch['consignee_state']??'') ?>">

        </div>

        <div class="col-12 col-sm-6 col-md-4">

            <label class="form-label">GSTIN</label>

            <input type="text" name="consignee_gstin" id="consignee_gstin" class="form-control" value="<?= htmlspecialchars($despatch['consignee_gstin']??'') ?>">

        </div>

        <div class="col-12 col-md-6" id="consignee_pincode_wrap">

            <label class="form-label">Pincode</label>

            <input type="text" name="consignee_pincode" id="consignee_pincode" class="form-control" value="<?= htmlspecialchars($despatch['consignee_pincode']??'') ?>">

        </div>

        <div class="col-12 col-md-6" id="consignee_contact_wrap">

            <label class="form-label">Contact at Delivery</label>

            <input type="text" name="consignee_contact" id="consignee_contact" class="form-control"

                   placeholder="Name / Phone" value="<?= htmlspecialchars($despatch['consignee_contact']??'') ?>">

        </div>

        <div class="col-12 mt-2" id="despatchDeliveryWeatherWrap"></div>
        <script>
        (function() {
            function initDespatchWeather() {
                var cityEl = document.getElementById('consignee_city');
                var addrEl = document.getElementById('consignee_address');
                var loc = (cityEl && cityEl.value ? cityEl.value : '') || (addrEl && addrEl.value ? addrEl.value : '');
                if (window.DMSWeather && loc) {
                    window.DMSWeather.load('despatchDeliveryWeatherWrap', loc);
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initDespatchWeather);
            } else {
                setTimeout(initDespatchWeather, 250);
            }
        })();
        </script>

        <div class="col-12 text-end">

            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearConsignee()" title="Clear all consignee fields">

                <i class="bi bi-eraser me-1"></i>Clear

            </button>

        </div>

    </div></div></div></div>



    <!-- Transport -->

    <div class="col-12 col-md-6 d-flex flex-column"><div class="card h-100"><div class="card-header"><i class="bi bi-truck me-2"></i>Transport Details</div>

    <div class="card-body"><div class="row g-2">

        <div class="col-12 col-md-6">

            <label class="form-label d-flex justify-content-between align-items-center">
                <span>Transporter *</span>
                <?php if ($action === 'edit' && !isAdmin()): ?>
                <span class="badge bg-secondary" style="font-size:10px"><i class="bi bi-lock-fill me-1"></i>Admin only</span>
                <?php endif; ?>
            </label>

            <?php if ($can_edit_vendor_po_transporter): ?>
            <select name="transporter_id" id="transporterSelect" class="form-select" required onchange="updateFreightCalc()">

                <option value="">-- Select Transporter --</option>

                <?php foreach($transporters as $t): ?>

                <option value="<?= $t['id'] ?>" <?= ($despatch['transporter_id']??0)==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['transporter_name']) ?></option>

                <?php endforeach; ?>

            </select>
            <?php else: ?>
            <select id="transporterSelect" class="form-select bg-light" disabled required>
                <option value="">-- Select Transporter --</option>
                <?php foreach($transporters as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ($despatch['transporter_id']??0)==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['transporter_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="transporter_id" value="<?= (int)($despatch['transporter_id']??0) ?>">
            <?php endif; ?>

        </div>



        <div class="col-12 col-sm-6 col-md-4">

            <label class="form-label">Vehicle No *</label>

            <input type="text" name="vehicle_no" class="form-control" placeholder="MH-01-XX-1234" required value="<?= htmlspecialchars($despatch['vehicle_no']??'') ?>">

        </div>

        <div class="col-12 col-sm-6 col-md-4 d-none">

            <label class="form-label">Driver Name</label>

            <input type="text" name="driver_name" class="form-control" value="<?= htmlspecialchars($despatch['driver_name']??'') ?>">

        </div>

        <div class="col-12 col-sm-6 col-md-4 d-none">

            <label class="form-label">Driver Mobile</label>

            <input type="text" name="driver_mobile" class="form-control" value="<?= htmlspecialchars($despatch['driver_mobile']??'') ?>">

        </div>

        <div class="col-6 col-sm-4 col-md-4 d-none">

            <label class="form-label">Received Weight</label>

            <input type="number" name="total_weight" id="totalWeightDisplay" step="0.001" class="form-control bg-light"

                   value="<?= $despatch['total_weight']??0 ?>" readonly tabindex="-1">

            <div class="form-text text-muted">Auto-summed from items</div>

        </div>

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label">Freight Amount (₹)

                <span id="freightRateCardBadge" class="badge bg-primary ms-1" style="font-size:.65rem;display:none">

                    <i class="bi bi-lock-fill me-1"></i>Rate Card

                </span>

            </label>

            <div class="input-group">

                <span class="input-group-text bg-light">₹</span>

                <input type="number" name="freight_amount" id="freightAmount" step="0.01" class="form-control"

                       value="<?= $despatch['freight_amount']??0 ?>">

            </div>

            <div class="form-text" id="freightFormula"><span class="text-muted">Auto from rate card</span></div>

        </div>

        <input type="hidden" name="transporter_rate_per_mt" id="transporterRatePerMt" value="<?= $despatch['transporter_rate_per_mt'] ?? 0 ?>">

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label">Misc. Charges (₹)
                <span class="badge bg-secondary ms-1" style="font-size:.65rem">No GST</span>
            </label>

            <div class="input-group">

                <span class="input-group-text bg-light">₹</span>

                <input type="number" name="transporter_misc_charges" id="transporterMiscCharges" step="0.01" min="0" class="form-control"
                       value="<?= $despatch['transporter_misc_charges'] ?? 0 ?>" placeholder="0.00">

            </div>

            <div class="form-text text-muted">Weighbridge / Labour (Net)</div>

        </div>

        <div class="col-12 col-sm-4 col-md-3">

            <label class="form-label">Misc. Remarks</label>

            <input type="text" name="transporter_misc_remarks" id="transporterMiscRemarks" class="form-control"
                   value="<?= htmlspecialchars($despatch['transporter_misc_remarks'] ?? '') ?>" placeholder="e.g. Weigh Bridge Charges" maxlength="255">

            <div class="form-text text-muted">Reason / description</div>

        </div>



        <div class="col-6 col-sm-4 col-md-3 d-none">

            <label class="form-label">Freight Paid By</label>

            <select name="freight_paid_by" class="form-select">

                <option value="Consignee" <?= ($despatch['freight_paid_by']??'Consignee')=='Consignee'?'selected':'' ?>>Consignee</option>

                <option value="Consignor" <?= ($despatch['freight_paid_by']??'')=='Consignor'?'selected':'' ?>>Consignor</option>

            </select>

        </div>

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label">Agent / Salesman</label>

            <select name="agent_id" class="form-select">

                <option value="">-- None --</option>

                <?php foreach($agents_list as $ag): ?>

                <option value="<?= $ag['id'] ?>" <?= ($despatch['agent_id']??0)==$ag['id']?'selected':'' ?>>

                    <?= htmlspecialchars($ag['full_name']) ?>

                </option>

                <?php endforeach; ?>

            </select>

            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Commission calculated on Delivered</div>

        </div>

        <?php /* DISABLED: Admin override Created By — temporarily hidden to fix user activity daily report

        if (isAdmin() && $action === 'add'): ?>

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label fw-semibold text-danger"><i class="bi bi-person-fill-gear me-1"></i>Created By <small class="text-muted fw-normal">(Admin override)</small></label>

            <select name="override_created_by" class="form-select border-danger">

                <option value="">-- Self (<?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?>) --</option>

                <?php

                $all_users = $db->query("SELECT id, full_name, username FROM app_users WHERE status='Active' AND id != ".(int)($_SESSION['user_id']??0)." ORDER BY full_name");

                while ($au = $all_users->fetch_assoc()):

                ?>

                <option value="<?= $au['id'] ?>"><?= htmlspecialchars($au['full_name']) ?> (<?= htmlspecialchars($au['username']) ?>)</option>

                <?php endwhile; ?>

            </select>

            <div class="form-text text-danger">Assign this challan to another user</div>

        </div>

        <?php endif;

        */ ?>





    </div></div></div></div>



    <!-- Items -->

    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-header d-flex justify-content-between">

        <span><i class="bi bi-list-ul me-2"></i>Despatch Items</span>

        <button type="button" class="btn btn-sm btn-light" onclick="addDRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>

    </div>

    <div class="card-body">

    <div id="dItemsBody">

        <?php if (!empty($despatch_items)): foreach($despatch_items as $di):

            $po_rate = $despatch['po_id'] ? ($po_items_map[(int)$despatch['po_id']][(int)$di['item_id']] ?? '') : '';

        ?>

        <div class="d-item-card border rounded p-3 mb-3 position-relative" style="background:#fafafa">

            <button type="button" class="btn btn-sm btn-outline-danger position-absolute" style="top:8px;right:8px" onclick="dRemove(this)"><i class="bi bi-x"></i></button>

            <div class="row g-2">

                <div class="col-12">

                    <label class="form-label fw-semibold mb-1">Item</label>

                    <select name="item_id[]" class="form-select d-item-select" required onchange="dFillItem(this)">

                        <option value="">-- Select Item --</option>

                        <?php foreach($items_list as $il): ?>

                        <option value="<?= $il['id'] ?>" data-uom="<?= $il['uom'] ?>"

                            <?= $di['item_id']==$il['id']?'selected':'' ?>><?= htmlspecialchars($il['item_code'].' - '.$il['item_name']) ?></option>

                        <?php endforeach; ?>

                    </select>

                    <input type="hidden" name="desc[]" value="<?= htmlspecialchars($di['description']??'') ?>">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">UOM</label>

                    <input type="hidden" name="uom[]" class="d-uom-val" value="<?= htmlspecialchars($di['uom']) ?>">

                    <input type="text" class="form-control bg-light d-uom-display" value="<?= htmlspecialchars($di['uom']) ?>" readonly tabindex="-1">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">Qty <small class="text-muted">(Ref)</small></label>

                    <input type="number" name="qty[]" class="form-control" value="<?= ($di['qty'] > 0) ? $di['qty'] : '' ?>" step="0.01" placeholder="Optional">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">PO Rate</label>

                    <input type="text" class="form-control bg-light text-primary fw-semibold d-po-rate" value="<?= $po_rate !== '' ? '₹'.number_format((float)$po_rate,2) : '-' ?>" readonly tabindex="-1">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">Unit Price</label>

                    <input type="number" name="unit_price[]" class="form-control d-unit-price" value="<?= $di['unit_price'] ?>" step="0.01" onchange="dCalcRow(this)">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">GST %</label>

                    <input type="number" name="gst_rate[]" class="form-control" value="<?= $di['gst_rate'] ?>" step="0.01" onchange="dCalcRow(this)">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">Weight (MT)</label>

                    <input type="number" name="weight[]" class="form-control d-weight" value="<?= $di['weight'] ?>" step="0.001" onchange="dCalcRow(this)">

                </div>

                <div class="col-12">

                    <label class="form-label mb-1">Total ₹</label>

                    <input type="text" name="total_price[]" class="form-control bg-light fw-bold text-primary d-row-total" value="<?= number_format((float)$di['total_price'],2) ?>" readonly tabindex="-1">

                </div>

            </div>

        </div>

        <?php endforeach; else: ?>

        <div class="d-item-card border rounded p-3 mb-3 position-relative" style="background:#fafafa">

            <button type="button" class="btn btn-sm btn-outline-danger position-absolute" style="top:8px;right:8px" onclick="dRemove(this)"><i class="bi bi-x"></i></button>

            <div class="row g-2">

                <div class="col-12">

                    <label class="form-label fw-semibold mb-1">Item</label>

                    <select name="item_id[]" class="form-select d-item-select" onchange="dFillItem(this)">

                        <option value="">-- Select Item --</option>

                        <?php foreach($items_list as $il): ?>

                        <option value="<?= $il['id'] ?>" data-uom="<?= $il['uom'] ?>"><?= htmlspecialchars($il['item_code'].' - '.$il['item_name']) ?></option>

                        <?php endforeach; ?>

                    </select>

                    <input type="hidden" name="desc[]" value="">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">UOM</label>

                    <input type="hidden" name="uom[]" class="d-uom-val">

                    <input type="text" class="form-control bg-light d-uom-display" readonly tabindex="-1">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">Qty <small class="text-muted">(Ref)</small></label>

                    <input type="number" name="qty[]" class="form-control" step="0.01" placeholder="Optional">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">PO Rate</label>

                    <input type="text" class="form-control bg-light text-primary fw-semibold d-po-rate" value="-" readonly tabindex="-1">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">Unit Price</label>

                    <input type="number" name="unit_price[]" class="form-control d-unit-price" step="0.01" onchange="dCalcRow(this)">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">GST %</label>

                    <input type="number" name="gst_rate[]" class="form-control" step="0.01" onchange="dCalcRow(this)">

                </div>

                <div class="col-4">

                    <label class="form-label mb-1">Weight (MT)</label>

                    <input type="number" name="weight[]" class="form-control d-weight" step="0.001" value="0" onchange="dCalcRow(this)">

                </div>

                <div class="col-12">

                    <label class="form-label mb-1">Total ₹</label>

                    <input type="text" name="total_price[]" class="form-control bg-light fw-bold text-primary d-row-total" value="0.00" readonly tabindex="-1">

                </div>

            </div>

        </div>

        <?php endif; ?>

    </div>

    <div class="d-flex justify-content-between align-items-center mt-3 px-1">

        <strong>Grand Total (incl. GST):</strong>

        <strong id="dGrandTotal" class="text-primary fs-5">₹0.00</strong>

    </div>

    <!-- Hidden template for JS cloning -->

    <div id="dRowTemplate" style="display:none">

    <div class="d-item-card border rounded p-3 mb-3 position-relative" style="background:#fafafa">

        <button type="button" class="btn btn-sm btn-outline-danger position-absolute" style="top:8px;right:8px"><i class="bi bi-x"></i></button>

        <div class="row g-2">

            <div class="col-12">

                <label class="form-label fw-semibold mb-1">Item</label>

                <select class="form-select d-item-select" disabled>

                    <option value="">-- Select Item --</option>

                    <?php foreach($items_list as $il): ?>

                    <option value="<?= $il['id'] ?>" data-uom="<?= $il['uom'] ?>"><?= htmlspecialchars($il['item_code'].' - '.$il['item_name']) ?></option>

                    <?php endforeach; ?>

                </select>

                <input type="hidden" class="d-desc-val" disabled>

            </div>

            <div class="col-4">

                <label class="form-label mb-1">UOM</label>

                <input type="hidden" class="d-uom-val" disabled>

                <input type="text" class="form-control bg-light d-uom-display" readonly tabindex="-1" disabled>

            </div>

            <div class="col-4">

                <label class="form-label mb-1">Qty <small class="text-muted">(Ref)</small></label>

                <input type="number" class="form-control" step="0.01" placeholder="Optional" disabled>

            </div>

            <div class="col-4">

                <label class="form-label mb-1">PO Rate</label>

                <input type="text" class="form-control bg-light text-primary fw-semibold d-po-rate" value="-" readonly tabindex="-1" disabled>

            </div>

            <div class="col-4">

                <label class="form-label mb-1">Unit Price</label>

                <input type="number" class="form-control d-unit-price" step="0.01" disabled>

            </div>

            <div class="col-4">

                <label class="form-label mb-1">GST %</label>

                <input type="number" class="form-control" step="0.01" disabled>

            </div>

            <div class="col-4">

                <label class="form-label mb-1">Weight (MT)</label>

                <input type="number" class="form-control d-weight" step="0.001" value="0" disabled>

            </div>

            <div class="col-12">

                <label class="form-label mb-1">Total ₹</label>

                <input type="text" class="form-control bg-light fw-bold text-primary d-row-total" value="0.00" readonly tabindex="-1" disabled>

            </div>

        </div>

    </div>

    </div>

    </div></div></div>



<style>

@media (min-width: 768px) {

    #despatchForm .form-control,

    #despatchForm .form-select {

        font-size: .82rem;

        padding: .25rem .5rem;

    }

    #despatchForm .form-label {

        font-size: .78rem;

        margin-bottom: .15rem;

    }

    #despatchForm .input-group-text {

        font-size: .82rem;

        padding: .25rem .5rem;

    }

    #despatchForm .d-item-card {

        padding: .6rem !important;

    }

    #despatchForm .d-item-card .row {

        --bs-gutter-y: .3rem;

    }

}

</style>

    <!-- MTC Section -->

    <div class="col-12">

    <div class="card border-warning">

        <div class="card-header" style="background:linear-gradient(135deg,#856404,#b8860b);color:#fff">

            <i class="bi bi-patch-check me-2"></i>Material Test Certificate (MTC)

        </div>

        <div class="card-body">

        <div class="row g-3 align-items-center">

            <div class="col-12 col-md-3">

                <label class="form-label fw-bold">MTC Required?</label>

                <div class="d-flex gap-3 mt-1">

                    <div class="form-check">

                        <input class="form-check-input" type="radio" name="mtc_required" id="mtcNo" value="No"

                               <?= ($despatch['mtc_required']??'No')==='No'?'checked':'' ?> onchange="toggleMTC()">

                        <label class="form-check-label fw-semibold text-secondary" for="mtcNo">No</label>

                    </div>

                    <div class="form-check">

                        <input class="form-check-input" type="radio" name="mtc_required" id="mtcYes" value="Yes"

                               <?= ($despatch['mtc_required']??'')=='Yes'?'checked':'' ?> onchange="toggleMTC()">

                        <label class="form-check-label fw-semibold text-primary" for="mtcYes">Yes</label>

                    </div>

                </div>

                <div class="form-text text-warning fw-semibold mt-1" id="mtcChallanNote" style="display:none">

                    <i class="bi bi-info-circle me-1"></i>MTC copy will be attached to Original (Consignee) challan

                </div>

            </div>

        </div>



        <!-- MTC Details — shown only when Yes -->

        <div id="mtcDetails" style="display:none">

        <hr class="my-3">



        <!-- Preview of MTC format header -->

        <div class="alert alert-warning py-2 mb-3 d-flex align-items-center gap-2">

            <i class="bi bi-info-circle-fill"></i>

            <span>Fill in the test results below. These will print as a <strong>Material Test Certificate</strong> attached to the Original (Consignee) copy of the Delivery Challan.</span>

        </div>



        <div class="row g-3">

            <div class="col-12 col-md-4">

                <label class="form-label">

                    Source of Material

                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">

                        <i class="bi bi-arrow-up-circle me-1"></i>Auto from Despatch Info

                    </span>

                </label>

                <input type="text" name="mtc_source" id="mtc_source" class="form-control bg-light"

                       readonly tabindex="-1"

                       value="<?= htmlspecialchars($despatch['mtc_source']??'') ?>">

            </div>

            <div class="col-12 col-md-4">

                <label class="form-label">

                    Item Name

                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">

                        <i class="bi bi-arrow-up-circle me-1"></i>Auto from Despatch Items

                    </span>

                </label>

                <input type="text" name="mtc_item_name" id="mtc_item_name" class="form-control bg-light"

                       readonly tabindex="-1"

                       value="<?= htmlspecialchars($despatch['mtc_item_name']??'') ?>">

            </div>

            <div class="col-12 col-md-2">

                <label class="form-label">

                    Test Date

                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">

                        <i class="bi bi-arrow-up-circle me-1"></i>= Despatch Date

                    </span>

                </label>

                <input type="date" name="mtc_test_date" id="mtc_test_date" class="form-control bg-light"

                       readonly tabindex="-1"

                       value="<?= htmlspecialchars(($despatch['mtc_test_date'] ?? '') ?: ($despatch['despatch_date'] ?? date('Y-m-d'))) ?>">

            </div>

        </div>



        <h6 class="fw-bold mt-3 mb-2 text-warning">

            <i class="bi bi-table me-1"></i>Test Results

            <small class="text-muted fw-normal ms-2" style="font-size:.75rem">Six random samples — average results</small>

        </h6>



        <!-- Results table matching IS 3812 format -->

        <div class="table-responsive">

        <table class="table table-bordered align-middle mb-0" style="font-size:.88rem">

            <thead class="table-warning">

            <tr>

                <th style="width:40%">TEST</th>

                <th style="width:25%">RESULTS (%)</th>

                <th style="width:25%">Requirements as per IS 3812</th>

            </tr>

            </thead>

            <tbody>

            <tr>

                <td class="fw-semibold">ROS 45 Micron Sieve</td>

                <td>

                    <div class="input-group input-group-sm">

                        <input type="text" name="mtc_ros_45" class="form-control" placeholder="e.g. 28.5"

                               value="<?= htmlspecialchars($despatch['mtc_ros_45']??'') ?>">

                        <span class="input-group-text">%</span>

                    </div>

                </td>

                <td class="text-muted">&lt; 34%</td>

            </tr>

            <tr>

                <td class="fw-semibold">Moisture</td>

                <td>

                    <div class="input-group input-group-sm">

                        <input type="text" name="mtc_moisture" class="form-control" placeholder="e.g. 0.8"

                               value="<?= htmlspecialchars($despatch['mtc_moisture']??'') ?>">

                        <span class="input-group-text">%</span>

                    </div>

                </td>

                <td class="text-muted">&lt; 2%</td>

            </tr>

            <tr>

                <td class="fw-semibold">Loss on Ignition</td>

                <td>

                    <div class="input-group input-group-sm">

                        <input type="text" name="mtc_loi" class="form-control" placeholder="e.g. 3.2"

                               value="<?= htmlspecialchars($despatch['mtc_loi']??'') ?>">

                        <span class="input-group-text">%</span>

                    </div>

                </td>

                <td class="text-muted">&lt; 5%</td>

            </tr>

            <tr>

                <td class="fw-semibold">Fineness – Specific Surface Area<br><small class="text-muted">by Blaine's Permeability Method</small></td>

                <td>

                    <div class="input-group input-group-sm">

                        <input type="text" name="mtc_fineness" class="form-control" placeholder="e.g. 380"

                               value="<?= htmlspecialchars($despatch['mtc_fineness']??'') ?>">

                        <span class="input-group-text">m²/kg</span>

                    </div>

                </td>

                <td class="text-muted">&gt; 320 m²/kg</td>

            </tr>

            </tbody>

        </table>

        </div>



        <div class="row g-3 mt-2">

            <div class="col-12 col-md-6">

                <label class="form-label">Remarks / Observations</label>

                <input type="text" name="mtc_remarks" class="form-control"

                       value="<?= htmlspecialchars($despatch['mtc_remarks']??'') ?>">

            </div>

            <div class="col-12 col-md-6">

                <label class="form-label fw-semibold">

                    <i class="bi bi-paperclip me-1 text-warning"></i>Upload MTC Document (optional)

                </label>

                <?php $mtc_file = $despatch['doc_mtc'] ?? ''; ?>

                <?php if (!empty($mtc_file)): ?>

                <div class="card border-warning mb-2 p-2">

                    <div class="d-flex align-items-center gap-2 flex-wrap">

                        <span class="badge bg-warning text-dark"><i class="bi bi-check2 me-1"></i>Uploaded</span>

                        <a href="<?= htmlspecialchars(despatchStoredUrl($mtc_file, 'mtc_docs')) ?>" target="_blank"

                           class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View</a>

                        <small class="text-muted text-truncate" style="max-width:120px"><?= htmlspecialchars(despatchStoredLabel($mtc_file)) ?></small>

                    </div>

                    <div class="form-check mt-2">

                        <input class="form-check-input border-danger" type="checkbox" name="remove_doc_mtc" value="1"

                               id="rm_doc_mtc" onchange="toggleRemoveDoc(this,'upload_doc_mtc')">

                        <label class="form-check-label text-danger fw-semibold" for="rm_doc_mtc">

                            <i class="bi bi-trash me-1"></i>Remove this document

                        </label>

                    </div>

                </div>

                <?php endif; ?>

                <div id="upload_doc_mtc">

                    <input type="file" name="doc_mtc" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">

                    <div class="form-text"><?= empty($mtc_file)?'PDF or image, max 10MB':'Upload new to replace'?></div>

                </div>

            </div>

        </div>

        </div><!-- /mtcDetails -->

        </div>

    </div>

    </div>









    <!-- Delivery Documents — shown only when status = Delivered -->

    <div class="col-12" id="deliveryDocsSection" style="display:none">

    <div class="card border-primary">

        <div class="card-header bg-primary text-white">

            <i class="bi bi-paperclip me-2"></i>Delivery Documents

            <small class="ms-2 opacity-75">Upload scan copies after delivery confirmation</small>

        </div>

        <div class="card-body">

        <div class="row g-3">



            <?php

            $doc_slots = [

                ['field'=>'doc_delivery_challan', 'label'=>'Delivery Challan',  'icon'=>'bi-file-earmark-text', 'color'=>'text-primary'],

                ['field'=>'doc_vendor_receipt',   'label'=>'Vendor Receipt',    'icon'=>'bi-receipt',           'color'=>'text-warning'],

                ['field'=>'doc_weightbridge',     'label'=>'Weightbridge Slip', 'icon'=>'bi-speedometer',       'color'=>'text-danger'],

            ];

            foreach ($doc_slots as $slot):

                $fname = $despatch[$slot['field']] ?? '';

            ?>

            <div class="col-12 col-md-4">

                <label class="form-label fw-semibold">

                    <i class="bi <?= $slot['icon'] ?> me-1 <?= $slot['color'] ?>"></i><?= $slot['label'] ?>

                </label>

                <?php if (!empty($fname)): ?>

                <div class="card border-primary mb-2 p-2">

                    <div class="d-flex align-items-center gap-2 flex-wrap">

                        <span class="badge bg-primary"><i class="bi bi-check2 me-1"></i>Uploaded</span>

                        <a href="<?= htmlspecialchars(despatchStoredUrl($fname, 'delivery_docs')) ?>"

                           target="_blank" class="btn btn-sm btn-outline-primary">

                            <i class="bi bi-eye me-1"></i>View

                        </a>

                        <small class="text-muted text-truncate" style="max-width:120px" title="<?= htmlspecialchars(despatchStoredLabel($fname)) ?>"><?= htmlspecialchars(despatchStoredLabel($fname)) ?></small>

                    </div>

                    <div class="form-check mt-2">

                        <input class="form-check-input border-danger" type="checkbox"

                               name="remove_<?= $slot['field'] ?>" value="1"

                               id="rm_<?= $slot['field'] ?>"

                               onchange="toggleRemoveDoc(this,'upload_<?= $slot['field'] ?>')">

                        <label class="form-check-label text-danger fw-semibold" for="rm_<?= $slot['field'] ?>">

                            <i class="bi bi-trash me-1"></i>Remove this document

                        </label>

                    </div>

                </div>

                <?php endif; ?>

                <div id="upload_<?= $slot['field'] ?>">

                    <input type="file" name="<?= $slot['field'] ?>" class="form-control form-control-sm"

                           accept=".pdf,.jpg,.jpeg,.png">

                    <div class="form-text"><?= empty($fname) ? 'PDF or image, max 10MB' : 'Upload a new file to replace existing' ?></div>

                </div>

            </div>

            <?php endforeach; ?>



        </div>

        </div>

    </div>

    </div>



    <!-- Transporter Freight Invoice — shown only when status = Delivered -->

    <div class="col-12" id="freightInvoiceSection" style="display:none">

    <div class="card border-warning">

        <div class="card-header" style="background:linear-gradient(135deg,#92400e,#b45309);color:#fff">

            <i class="bi bi-receipt-cutoff me-2"></i>Transporter Freight Invoice

            <small class="ms-2 opacity-75">Record transporter's freight bill for this delivery</small>

        </div>

        <div class="card-body">

        <div class="row g-3 align-items-end">

            <div class="col-6 col-sm-4 col-md-2">

                <label class="form-label fw-semibold">Invoice No</label>

                <input type="text" name="freight_inv_no" class="form-control"

                       placeholder="e.g. FI/2526/001"

                       value="<?= htmlspecialchars($despatch['freight_inv_no'] ?? '') ?>">

            </div>

            <div class="col-6 col-sm-4 col-md-2">

                <label class="form-label fw-semibold">Invoice Date</label>

                <input type="date" name="freight_inv_date" class="form-control"

                       value="<?= htmlspecialchars($despatch['freight_inv_date'] ?? '') ?>">

            </div>

            <div class="col-6 col-sm-4 col-md-2">

                <label class="form-label fw-semibold">Invoice Amount (₹)</label>

                <div class="input-group">

                    <span class="input-group-text">₹</span>

                    <input type="number" name="freight_inv_amount" step="0.01" class="form-control"

                           value="<?= $despatch['freight_inv_amount'] ?? 0 ?>">

                </div>

            </div>

            <div class="col-6 col-sm-4 col-md-2">

                <label class="form-label fw-semibold">Invoice Type</label>

                <select name="freight_inv_type" id="freightInvType" class="form-select"

                        onchange="toggleHardCopy()">

                    <option value="" <?= ($despatch['freight_inv_type']??'')==''?'selected':'' ?>>-- Select --</option>

                    <option value="Scan"    <?= ($despatch['freight_inv_type']??'')==='Scan'   ?'selected':'' ?>>Scan Copy</option>

                    <option value="Digital" <?= ($despatch['freight_inv_type']??'')==='Digital'?'selected':'' ?>>Digitally Signed</option>

                </select>

            </div>

            <div class="col-6 col-sm-4 col-md-2">

                <label class="form-label fw-semibold">Upload Invoice</label>

                <?php $fi_file = $despatch['freight_inv_file'] ?? ''; ?>

                <?php if (!empty($fi_file)): ?>

                <div class="mb-1">

                    <a href="<?= htmlspecialchars(despatchStoredUrl($fi_file, 'freight_invoices')) ?>"

                       target="_blank" class="btn btn-sm btn-outline-success w-100">

                        <i class="bi bi-eye me-1"></i>View Uploaded

                    </a>

                </div>

                <?php endif; ?>

                <?php if (!empty($fi_file)): ?>

                <div class="form-check mt-2">

                    <input class="form-check-input border-danger" type="checkbox" name="remove_freight_inv_file" value="1"

                           id="rm_freight_inv_file" onchange="toggleRemoveDoc(this,'freightInvUploadWrap')">

                    <label class="form-check-label text-danger fw-semibold" for="rm_freight_inv_file">

                        <i class="bi bi-trash me-1"></i>Remove this invoice file

                    </label>

                </div>

                <?php endif; ?>

                <div id="freightInvUploadWrap">

                <input type="file" name="freight_inv_file" class="form-control form-control-sm"

                       accept=".pdf,.jpg,.jpeg,.png">

                </div>

            </div>

            <div class="col-6 col-sm-4 col-md-2" id="hardCopyWrap" style="display:none">

                <label class="form-label fw-semibold">Hard Copy</label>

                <div class="form-check mt-1 p-3 border rounded <?= ($despatch['freight_inv_hardcopy']??0) ? 'border-primary bg-primary bg-opacity-10' : 'border-warning bg-warning bg-opacity-10' ?>">

                    <input class="form-check-input" type="checkbox" name="freight_inv_hardcopy"

                           value="1" id="freightHardcopy"

                           <?= ($despatch['freight_inv_hardcopy']??0) ? 'checked' : '' ?>>

                    <label class="form-check-label fw-semibold" for="freightHardcopy">

                        <?php if ($despatch['freight_inv_hardcopy']??0): ?>

                        <span class="text-primary"><i class="bi bi-check-circle me-1"></i>Received</span>

                        <?php else: ?>

                        <span class="text-warning"><i class="bi bi-clock me-1"></i>Pending</span>

                        <?php endif; ?>

                    </label>

                </div>

            </div>

        </div>

        <!-- Status badge for existing record -->

        <?php if (!empty($despatch['freight_inv_type'])): ?>

        <div class="mt-3">

            <?php if ($despatch['freight_inv_type'] === 'Digital'): ?>

            <span class="badge bg-primary fs-6"><i class="bi bi-patch-check me-1"></i>Digitally Signed Invoice — No hard copy required</span>

            <?php elseif ($despatch['freight_inv_hardcopy']): ?>

            <span class="badge bg-primary fs-6"><i class="bi bi-check-circle me-1"></i>Hard Copy Received</span>

            <?php else: ?>

            <span class="badge bg-warning text-dark fs-6"><i class="bi bi-clock me-1"></i>Hard Copy Pending</span>

            <?php endif; ?>

        </div>

        <?php endif; ?>

        </div>

    </div>

    </div>



    <div class="col-12 text-end">

        <a href="despatch.php" class="btn btn-outline-secondary me-2">Cancel</a>

        <?php if ($action === 'edit' && ($despatch['status'] ?? '') === 'Delivered'): ?>

        <button type="button" class="btn btn-primary px-3 me-2" id="saveWeightBtn" onclick="saveWeightOnly()">
            <i class="bi bi-box-arrow-in-down me-1"></i>Save Weight, Misc Expenses &amp; Recalc Commission
        </button>

        <button type="submit" class="btn btn-primary px-3 me-2" id="updateDocsBtn" name="docs_only" value="1">
            <i class="bi bi-cloud-upload me-1"></i>Update Documents
        </button>

        <?php if (isAdmin()): ?>
        <button type="submit" class="btn btn-success px-4" id="saveDespatchBtn">
            <i class="bi bi-check2-circle me-1"></i>Save Despatch Order
        </button>
        <?php endif; ?>

        <?php else: ?>

        <button type="submit" class="btn btn-primary px-4" id="saveDespatchBtn">
            <i class="bi bi-send-check me-1"></i>Save Despatch Order
        </button>

        <?php endif; ?>

    </div>

</div>

</form>



<?php if ($action === 'edit' && ($despatch['status'] ?? '') === 'Delivered'): ?>

<script>

function saveWeightOnly() {

    var despatchId = <?= (int)$id ?>;

    var wInputs = document.querySelectorAll('#dItemsBody .d-weight');

    var wData = [];

    wInputs.forEach(function(el) { wData.push(parseFloat(el.value) || 0); });

    if (wData.length === 0) { alert('No weight fields found.'); return; }

    if (!wData.some(function(v){ return v > 0; })) { alert('Please enter at least one weight value.'); return; }

    var btn = document.getElementById('saveWeightBtn');

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...'; }

    var fd = new FormData();

    fd.append('id', despatchId);

    wData.forEach(function(w) { fd.append('weight[]', w); });

    var miscEl = document.getElementById('transporterMiscCharges');
    var miscRemEl = document.getElementById('transporterMiscRemarks');
    if (miscEl) fd.append('transporter_misc_charges', miscEl.value || 0);
    if (miscRemEl) fd.append('transporter_misc_remarks', miscRemEl.value || '');

    fetch('despatch.php?ajax=update_weight', { method: 'POST', body: fd })

        .then(function(r) { return r.json(); })

        .then(function(d) {

            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save me-1"></i>Save Weight, Misc Expenses & Recalc Commission'; }

            if (d.ok) {

                var existing = document.getElementById('weightSaveBanner');

                if (existing) existing.remove();

                var banner = document.createElement('div');

                banner.id = 'weightSaveBanner';

                banner.className = 'alert alert-success alert-dismissible py-2 mb-3';

                banner.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i><strong>Weight and Misc Expenses saved & commission recalculated.</strong> Total: ' + parseFloat(d.total_weight).toFixed(3) + ' MT <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

                var ref = document.getElementById('despatchForm') || document.querySelector('.row.g-3');

                if (ref) ref.parentNode.insertBefore(banner, ref);

                window.scrollTo(0, 0);

            } else {

                alert('Save failed: ' + (d.msg || 'Unknown error'));

            }

        })

        .catch(function(err) {

            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save me-1"></i>Save Weight, Misc Expenses & Recalc Commission'; }

            alert('Network error: ' + err);

        });

}

</script>

<?php endif; ?>



<script>

// Client-side validation: Delivered requires Received Weight on every item row

(function() {
    var form = document.getElementById('despatchForm');
    if (!form) return;
    var savingBanner = document.getElementById('despatchSavingBanner');
    var submitWatchdog = null;

    function restoreSubmitUi() {
        form.dataset.submitting = '0';
        if (savingBanner) savingBanner.classList.remove('show');
        form.querySelectorAll('button[type="submit"]').forEach(function(btn) {
            btn.disabled = false;
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
                delete btn.dataset.originalHtml;
            }
        });
    }

    // Safety: if page returns from bfcache or partial navigation, clear stuck submit state
    window.addEventListener('pageshow', function() {
        restoreSubmitUi();
    });

    form.addEventListener('submit', function(e) {
        // Skip validation when saving weight-only (weight_only flag injected by saveWeightOnly())
        if (form.dataset.weightOnly === '1') return;
        var statusSel = form.querySelector('[name="status"]');
        if (statusSel && statusSel.value === 'Delivered') {
            var weights = form.querySelectorAll('#dItemsBody [name="weight[]"]');
            var missing = false;
            weights.forEach(function(w) {
                w.classList.remove('is-invalid');
                if ((parseFloat(w.value) || 0) <= 0) {
                    w.classList.add('is-invalid');
                    missing = true;
                }
            });
            if (missing) {
                e.preventDefault();
                alert('Received Weight is required for every item when status is Delivered.');
                var first = form.querySelector('#dItemsBody .is-invalid');
                if (first) first.focus();
                return;
            }
        }

        if (form.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }
        form.dataset.submitting = '1';

        if (savingBanner) {
            savingBanner.classList.add('show');
            var strong = savingBanner.querySelector('.fw-semibold');
            var note = savingBanner.querySelector('.small');
            var actionText = 'Saving despatch order...';
            if (e.submitter && e.submitter.id === 'updateDocsBtn') {
                actionText = 'Updating documents...';
            }
            if (strong) strong.textContent = actionText;
            if (note) note.textContent = 'Please wait while your changes are being saved.';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        form.querySelectorAll('button[type="submit"]').forEach(function(btn) {
            btn.disabled = true;
            if (btn === e.submitter) {
                btn.dataset.originalHtml = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
            }
        });

        // Watchdog: never let UI remain stuck forever if submit aborts or hangs
        if (submitWatchdog) clearTimeout(submitWatchdog);
        submitWatchdog = setTimeout(function() {
            restoreSubmitUi();
            alert('Save request timed out. Please try again.');
        }, 90000);
    });
})();
</script>


<script>

/* ── PO -> Vendor map ── */

const poVendorMap = <?php echo json_encode($po_vendor_map, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;



/* ── PO balance map ── */

const poBalanceMap = <?php echo json_encode($po_balance_map, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;



/* ── PO -> Item -> unit_price and gst_rate maps ── */

const poItemsMap = <?php echo json_encode($po_items_map, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

const poGstMap      = <?php echo json_encode($po_gst_map,      JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

const poItemsDetail = <?php echo json_encode($po_items_detail, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;



/* ── Transporter-Vendor Rate Card map (vendor_id -> [{tid, name, rate, uom}]) ── */

const vendorTransporterRates = <?php

    $vtmap = [];

    $vtSeen = []; // track vendor+transporter combos to keep only latest

    $rc = $db->query("SELECT tr.vendor_id, tr.transporter_id, tr.rate, tr.uom, t.transporter_name

        FROM transporter_rates tr

        JOIN transporters t ON tr.transporter_id = t.id

        WHERE tr.status='Active' AND t.status='Active'

        ORDER BY tr.id DESC");

    if ($rc) while ($rr = $rc->fetch_assoc()) {

        $combo = (int)$rr['vendor_id'].'-'.(int)$rr['transporter_id'];

        if (isset($vtSeen[$combo])) continue; // skip older duplicate

        $vtSeen[$combo] = true;

        $vtmap[(int)$rr['vendor_id']][] = [

            'tid'  => (int)$rr['transporter_id'],

            'name' => $rr['transporter_name'],

            'rate' => (float)$rr['rate'],

            'uom'  => $rr['uom'],

        ];

    }

    echo json_encode($vtmap);

?>;



var currentTransporterRate = 0;

var currentRateUom = '';



/* ── Vendor lookup map (ship-to data embedded server-side) ── */

const vendorShipData = <?php

    $cleanV = function($s) { return trim(str_replace('\\', '', preg_replace('/\s+/', ' ', (string)($s ?? '')))); };

    $map = [];

    foreach ($vendors as $v) {

        // Prefer ship_address; fall back to bill then legacy address

        $name    = $cleanV($v['vendor_name']);

        $addr    = $cleanV(($v['ship_name'] ? $v['ship_name'].', ' : '') . ($v['ship_address'] ?: ($v['bill_address'] ?: $v['address'])));

        $city    = $cleanV($v['ship_city']    ?: ($v['bill_city']    ?: $v['city']));

        $state   = $cleanV($v['ship_state']   ?: ($v['bill_state']   ?: $v['state']));

        $pin     = $cleanV($v['ship_pincode'] ?: ($v['bill_pincode'] ?: $v['pincode']));

        $gstin   = $cleanV($v['ship_gstin']   ?: ($v['bill_gstin']   ?: $v['gstin']));

        $contact = $cleanV(($v['ship_contact'] ?? '') . ($v['ship_phone'] ? ' / '.$v['ship_phone'] : ''));

        $map[$v['id']] = [

            'name'    => $name,

            'address' => $addr,

            'city'    => $city,

            'state'   => $state,

            'pincode' => $pin,

            'gstin'   => $gstin,

            'contact' => $contact,

            'vendor_name' => $cleanV($v['vendor_name']),

        ];

    }

    echo json_encode($map);

?>;



/* ── Filter PO dropdown by selected vendor — AJAX ── */

function filterPOByVendor(vendorId) {

    vendorId = parseInt(vendorId, 10) || 0;

    var poSel = document.getElementById('poRefSelect');

    if (!poSel) return;

    var currentPoId = parseInt(poSel.value, 10) || <?= (int)$current_despatch_po_id ?>;

    poSel.innerHTML = '<option value="">-- Select PO --</option>';

    renderPOBalance(0);

    if (!vendorId) return;

    fetch('despatch.php?ajax_get_pos=1&vendor_id=' + vendorId + '&current_po_id=' + currentPoId)

        .then(function(r) { return r.json(); })

        .then(function(pos) {

            pos.forEach(function(p) {

                var opt = document.createElement('option');

                opt.value = p.id;

                opt.textContent = p.po_number;

                opt.setAttribute('data-vendor', vendorId);

                poSel.appendChild(opt);

            });

            if (currentPoId) {

                poSel.value = String(currentPoId);

            }

            if (pos.length === 1) {

                poSel.value = pos[0].id;

                renderPOBalance(parseInt(pos[0].id));

                populatePOItems(parseInt(pos[0].id));

            } else if (poSel.value) {

                renderPOBalance(parseInt(poSel.value, 10) || 0);

                populatePOItems(parseInt(poSel.value, 10) || 0);

            }

        });

}



/* ── Auto-populate item rows from PO ── */

function populatePOItems(poId) {

    if (!poId) return;

    var items = poItemsDetail[poId];

    if (!items || !items.length) return;

    var tbody     = document.getElementById('dItemsBody');

    var statusSel = document.querySelector('[name="status"]');

    var isDeliv   = statusSel && statusSel.value === 'Delivered';



    // Check if rows already have items selected

    var hasItems = false;

    tbody.querySelectorAll('[name="item_id[]"]').forEach(function(s) { if (s.value) hasItems = true; });

    if (hasItems) {

        applyPOItemPrices(poId);

        return;

    }



    // Clear all existing rows

    tbody.innerHTML = '';



    // Build fresh rows from template

    items.forEach(function(it) {

        var row = makeNewRow();



        // Select the matching item

        var itemSel = row.querySelector('[name="item_id[]"]');

        if (itemSel) {

            for (var i = 0; i < itemSel.options.length; i++) {

                if (parseInt(itemSel.options[i].value) === it.item_id) {

                    itemSel.selectedIndex = i;

                    break;

                }

            }

        }



        // UOM

        var uomVal  = row.querySelector('.d-uom-val');

        var uomDisp = row.querySelector('.d-uom-display');

        if (uomVal)  uomVal.value  = it.uom || '';

        if (uomDisp) uomDisp.value = it.uom || '';



        // PO Rate (always visible reference)

        var poRateEl = row.querySelector('.d-po-rate');

        if (poRateEl) poRateEl.value = it.unit_price ? '₹' + parseFloat(it.unit_price).toFixed(2) : '-';



        // Despatched Qty — leave empty (reference only, user fills manually if needed)

        var qtyEl = row.querySelector('[name="qty[]"]');

        if (qtyEl) qtyEl.value = '';



        // Unit price + GST only when Delivered

        if (isDeliv) {

            var priceEl = row.querySelector('[name="unit_price[]"]');

            var gstEl   = row.querySelector('[name="gst_rate[]"]');

            if (priceEl) priceEl.value = parseFloat(it.unit_price || 0).toFixed(2);

            if (gstEl)   gstEl.value   = parseFloat(it.gst_rate  || 0).toFixed(2);

        }



        tbody.appendChild(row);

    });



    dCalcTotal();

    dUpdateWeightAndFreight();

}



/* ── Fill vendor dropdown from PO selection ── */

function fillVendorFromPO(sel) {
    var poId = parseInt(sel.value, 10) || 0;
    var vendorId = poVendorMap[poId];
    if (vendorId) {
        var vSel = document.getElementById('vendorSelect');
        if (vSel && (!vSel.value || vSel.value != vendorId)) {
            vSel.value = vendorId;
            fillConsigneeFromVendor(vSel);
            filterTransportersByVendor(vendorId);
            if (typeof triggerDespatchWeatherFromVendor === 'function') triggerDespatchWeatherFromVendor(vendorId);
        }
    }
    renderPOBalance(poId);
    applyPOChangesToItems(poId);
}



function renderPOBalance(poId) {

    var wrap  = document.getElementById('poBalanceWrap');

    var table = document.getElementById('poBalanceTable');

    if (!wrap || !table) return;

    var items = poBalanceMap[poId];

    if (!items || Object.keys(items).length === 0) {

        wrap.style.display = 'none';

        return;

    }

    var allFulfilled = true;

    var rows = '';

    var cards = '';

    Object.values(items).forEach(function(it) {

        var bal = parseFloat(it.balance) || 0;

        var pct = it.po_qty > 0 ? Math.min(100, Math.round((it.despatched / it.po_qty) * 100)) : 0;

        var badgeCls = bal <= 0 ? 'bg-primary' : (pct >= 50 ? 'bg-warning text-dark' : 'bg-danger');

        var balLbl   = bal <= 0 ? 'Fulfilled' : bal.toFixed(3) + ' pending';

        if (bal > 0) allFulfilled = false;

        // Table row (desktop)

        rows += '<tr>'

            + '<td class="py-1">' + it.item_name + '</td>'

            + '<td class="text-end py-1">' + parseFloat(it.po_qty).toFixed(3) + '</td>'

            + '<td class="text-end py-1 text-primary">' + parseFloat(it.despatched).toFixed(3) + '</td>'

            + '<td class="text-end py-1 fw-bold ' + (bal > 0 ? 'text-danger' : 'text-primary') + '">' + (bal > 0 ? bal.toFixed(3) : '0.000') + '</td>'

            + '<td class="py-1" style="min-width:120px"><div class="progress" style="height:14px"><div class="progress-bar ' + badgeCls + '" style="width:' + pct + '%">' + (pct > 15 ? pct + '%' : '') + '</div></div></td>'

            + '<td class="py-1"><span class="badge ' + badgeCls + '">' + balLbl + '</span></td>'

            + '</tr>';

        // Card (mobile)

        cards += '<div class="border rounded p-2 mb-2">'

            + '<div class="fw-semibold mb-1">' + it.item_name + '</div>'

            + '<div class="d-flex justify-content-between mb-1"><span class="text-muted">PO Qty</span><span>' + parseFloat(it.po_qty).toFixed(3) + '</span></div>'

            + '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Despatched</span><span class="text-primary">' + parseFloat(it.despatched).toFixed(3) + '</span></div>'

            + '<div class="d-flex justify-content-between mb-2"><span class="text-muted">Balance</span><span class="fw-bold ' + (bal > 0 ? 'text-danger' : 'text-primary') + '">' + (bal > 0 ? bal.toFixed(3) : '0.000') + '</span></div>'

            + '<div class="progress mb-1" style="height:10px"><div class="progress-bar ' + badgeCls + '" style="width:' + pct + '%"></div></div>'

            + '<span class="badge ' + badgeCls + '">' + balLbl + '</span>'

            + '</div>';

    });

    var fulfilled_row = allFulfilled ? '<div class="text-center text-primary py-1 fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>All PO quantities fulfilled</div>' : '';

    table.innerHTML =

        // Desktop table

        '<div class="d-none d-md-block"><table class="table table-sm table-bordered mb-0">'

        + '<thead class="table-primary"><tr>'

        + '<th>Item</th><th class="text-end">PO Qty</th><th class="text-end">Despatched</th>'

        + '<th class="text-end">Balance</th><th>Progress</th><th>Status</th>'

        + '</tr></thead><tbody>' + rows + '</tbody></table>' + fulfilled_row + '</div>'

        // Mobile cards

        + '<div class="d-md-none">' + cards + fulfilled_row + '</div>';

    wrap.style.display = 'block';

}



/* Fill unit_price (and UOM) in each despatch item row from the linked PO */

function applyPOItemPrices(poId) {

    var priceMap    = poItemsMap[poId] || {};

    var gstMap      = poGstMap[poId]   || {};

    var statusSel   = document.querySelector('[name="status"]');

    var isDelivered = statusSel && statusSel.value === 'Delivered';

    document.querySelectorAll('#dItemsBody .d-item-card').forEach(function(row) {

        var selEl   = row.querySelector('[name="item_id[]"]');

        var priceEl = row.querySelector('[name="unit_price[]"]');

        var gstEl   = row.querySelector('[name="gst_rate[]"]');

        if (!selEl || !priceEl) return;

        var itemId = parseInt(selEl.value, 10);

        if (!itemId) return;

        // Always update PO Rate reference column

        var poRateEl = row.querySelector('.d-po-rate');

        if (poRateEl) {

            var pr = priceMap[itemId];

            poRateEl.value = pr !== undefined ? '₹' + parseFloat(pr).toFixed(2) : '-';

        }

        if (priceMap[itemId] !== undefined && priceEl) {
            priceEl.value = parseFloat(priceMap[itemId]).toFixed(2);
        }
        if (gstMap[itemId] !== undefined && gstEl) {
            gstEl.value = parseFloat(gstMap[itemId]).toFixed(2);
        }

        // Also ensure UOM is set from the selected option data

        var opt = selEl.options[selEl.selectedIndex];

        if (opt && opt.dataset.uom) {

            var uomVal  = row.querySelector('.d-uom-val');

            var uomDisp = row.querySelector('.d-uom-display');

            if (uomVal)  uomVal.value  = opt.dataset.uom;

            if (uomDisp) uomDisp.value = opt.dataset.uom;

        }

        dCalcRow(row.querySelector('[name="weight[]"]'));

    });

}







/* ── Filter transporter dropdown based on selected vendor ── */

function filterTransportersByVendor(vendorId) {

    vendorId = parseInt(vendorId, 10) || 0;

    var tSel = document.getElementById('transporterSelect');

    if (!tSel) return;



    var rates = vendorId ? (vendorTransporterRates[vendorId] || []) : [];

    var allowedTids = rates.map(function(r) { return r.tid; });



    // Reset transporter selection

    tSel.value = '';

    currentTransporterRate = 0;

    currentRateUom = '';



    // Show/hide options based on rate card

    Array.from(tSel.options).forEach(function(opt) {

        if (!opt.value) return; // keep placeholder

        var tid = parseInt(opt.value, 10);

        if (!vendorId || allowedTids.includes(tid)) {

            opt.style.display = '';

            opt.disabled = false;

        } else {

            opt.style.display = 'none';

            opt.disabled = true;

        }

    });



    // Update formula hint

    var formulaEl = document.getElementById('freightFormula');

    if (formulaEl) {

        if (!vendorId) {

            formulaEl.innerHTML = '<span class="text-muted">Select Vendor first to filter transporters</span>';

        } else if (allowedTids.length === 0) {

            formulaEl.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>'

                + 'No transporters with rate card for this vendor. '

                + '<a href="transporters.php" target="_blank" class="text-warning fw-semibold">Set up Rate Card <i class="bi bi-box-arrow-up-right"></i></a></span>';

        } else {

            formulaEl.innerHTML = '<span class="text-info"><i class="bi bi-funnel me-1"></i>'

                + allowedTids.length + ' transporter(s) available for this vendor</span>';

        }

    }



    dUpdateWeightAndFreight();

}



/* ── Set freight rate when transporter is selected ── */

function updateFreightCalc() {

    var tid = parseInt((document.getElementById('transporterSelect')||{}).value, 10) || 0;

    var vid = parseInt((document.getElementById('vendorSelect')||{}).value, 10) || 0;



    if (!tid || !vid) {

        currentTransporterRate = 0;

        currentRateUom = '';

        dUpdateWeightAndFreight();

        return;

    }



    // Look up rate from pre-loaded map (no AJAX needed)

    var rates = vendorTransporterRates[vid] || [];

    var match = rates.find(function(r) { return r.tid === tid; });



    if (match) {

        currentTransporterRate = match.rate;

        currentRateUom = match.uom || '';

    } else {

        currentTransporterRate = 0;

        currentRateUom = '';

    }

    dUpdateWeightAndFreight();

}



function getTotalWeight() {

    var total = 0;

    document.querySelectorAll('#dItemsBody [name="weight[]"]').forEach(function(el) {

        total += parseFloat(el.value) || 0;

    });

    return total;

}



/* ── Auto-fill consignee from selected vendor's ship-to ── */


function triggerDespatchWeatherFromVendor(vid) {
    if (!vid || typeof vendorShipData === 'undefined' || !vendorShipData[vid]) {
        var cityEl = document.getElementById('consignee_city');
        if (cityEl && cityEl.value && window.DMSWeather) {
            window.DMSWeather.load('despatchDeliveryWeatherWrap', cityEl.value);
        }
        return;
    }
    var d = vendorShipData[vid];
    var loc = d.city || d.address || '';
    if (window.DMSWeather && loc) {
        window.DMSWeather.load('despatchDeliveryWeatherWrap', loc);
    }
}

function fillConsigneeFromVendor(sel) {

    const vid  = sel.value;

    const data = vendorShipData[vid];

    if (!data) return;



    // Only auto-fill if consignee name is empty (new form) OR user explicitly selected a vendor

    // On edit mode, still fill so user can refresh from vendor data

    const isNew = !document.getElementById('consignee_name').value.trim();

    const isEdit = <?= $action === 'edit' ? 'true' : 'false' ?>;



    if (isNew || (!isEdit)) {

        applyConsigneeData(data);

    } else {

        // On edit, ask before overwriting

        if (confirm('Replace current consignee details with vendor\'s Ship-To address?\n\nVendor: ' + data.vendor_name)) {

            applyConsigneeData(data);

        }

    }

}



function applyConsigneeData(data) {

    var f = {

        'consignee_name':    data.name    || '',

        'consignee_address': data.address || '',

        'consignee_city':    data.city    || '',

        'consignee_state':   data.state   || '',

        'consignee_pincode': data.pincode || '',

        'consignee_gstin':   data.gstin   || '',

        'consignee_contact': data.contact || ''

    };

    Object.keys(f).forEach(function(id) {

        var el = document.getElementById(id);

        if (el) el.value = f[id];

    });

    var vn = document.getElementById('autofillVendorName');

    if (vn) vn.textContent = data.vendor_name;

    var an = document.getElementById('autofillNotice');

    if (an) an.style.display = 'block';

    var ab = document.getElementById('autofillBadge');

    if (ab) ab.classList.remove('d-none');

    ['consignee_name','consignee_address','consignee_city',
     'consignee_state','consignee_pincode','consignee_gstin','consignee_contact','consignee_camp'].forEach(function(fid) {

        var el = document.getElementById(fid);

        if (el && el.value) {

            el.classList.add('border-success');

            setTimeout(function() { el.classList.remove('border-success'); }, 2500);

        }

    });

    if (window.DMSWeather) {
        var wCity = data.city || data.address || data.ship_city || data.ship_address || '';
        if (wCity) window.DMSWeather.load('despatchDeliveryWeatherWrap', wCity);
    }

    if (window.DMSWeather) {
        var wCity = data.consignee_city || data.city || data.ship_city || data.consignee_address || '';
        if (wCity) window.DMSWeather.load('despatchDeliveryWeatherWrap', wCity);
    }

}



function clearConsignee() {

    ['consignee_name','consignee_address','consignee_city',
     'consignee_state','consignee_pincode','consignee_gstin','consignee_contact','consignee_camp'].forEach(fid => {

        const el = document.getElementById(fid);

        if (el) { el.tagName === 'TEXTAREA' ? el.value = '' : el.value = ''; }

    });

    document.getElementById('autofillNotice').style.display = 'none';

    document.getElementById('autofillBadge').classList.add('d-none');

    if (window.DMSWeather) window.DMSWeather.load('despatchDeliveryWeatherWrap', '');

}



/* ── On page load: if vendor already selected (add form with pre-fill or edit),

      auto-fill only if consignee is empty ─────────────────────────────────── */

/* ── Toggle upload field when Remove checkbox is ticked ── */

function toggleRemoveDoc(chk, uploadWrapperId) {

    var wrap = document.getElementById(uploadWrapperId);

    if (!wrap) return;

    if (chk.checked) {

        wrap.style.opacity = '0.4';

        wrap.style.pointerEvents = 'none';

        chk.closest('.card').style.borderColor = '#dc3545';

        chk.closest('.card').style.background  = '#fff5f5';

    } else {

        wrap.style.opacity = '';

        wrap.style.pointerEvents = '';

        chk.closest('.card').style.borderColor = '';

        chk.closest('.card').style.background  = '';

    }

}



/* ── Sources of material map (id → name) ── */

const sourcesMap = <?php

    $sm = [];

    foreach ($sources_list as $s) $sm[(int)$s['id']] = $s['source_name'];

    echo json_encode($sm);

?>;



/* ── MTC toggle ── */

function toggleMTC() {

    var yes   = document.getElementById('mtcYes').checked;

    var det   = document.getElementById('mtcDetails');

    var note  = document.getElementById('mtcChallanNote');

    if (det)  det.style.display  = yes ? 'block' : 'none';

    if (note) note.style.display = yes ? 'block' : 'none';

    if (yes)  syncMTCFields();   // populate whenever opened

}



/* ── Sync MTC auto-populated fields ── */

function syncMTCFields() {

    // 1. Source — from Source of Material dropdown

    var srcSel = document.getElementById('sourceOfMaterial');

    var srcFld = document.getElementById('mtc_source');

    if (srcSel && srcFld) {

        var sid = parseInt(srcSel.value) || 0;

        srcFld.value = (sid && sourcesMap[sid]) ? sourcesMap[sid] : '';

    }



    // 2. Item Name — first item selected in Despatch Items table

    var itemFld = document.getElementById('mtc_item_name');

    if (itemFld) {

        var firstSel = document.querySelector('select.d-item-select');

        if (firstSel && firstSel.selectedOptions[0] && firstSel.selectedOptions[0].value) {

            itemFld.value = firstSel.selectedOptions[0].text.trim();

        } else {

            itemFld.value = '';

        }

    }



    // 3. Test Date — same as Despatch Date

    var dDate = document.getElementById('despatch_date');

    var mDate = document.getElementById('mtc_test_date');

    if (dDate && mDate) mDate.value = dDate.value || '';

}



/* ── Bind Source dropdown change to sync MTC source field ── */

function bindMTCSyncListeners() {

    var srcSel = document.getElementById('sourceOfMaterial');

    if (srcSel) srcSel.addEventListener('change', syncMTCFields);



    var dDate = document.getElementById('despatch_date');

    if (dDate) dDate.addEventListener('change', syncMTCFields);

}



/* ── Show/hide delivery docs section based on status ── */

function onStatusChange(sel) {

    var delivered = sel.value === 'Delivered';

    var sec = document.getElementById('deliveryDocsSection');

    if (sec) sec.style.display = delivered ? 'block' : 'none';

    var fiSec = document.getElementById('freightInvoiceSection');

    if (fiSec) fiSec.style.display = delivered ? 'block' : 'none';

    if (delivered) toggleHardCopy();

    // Card layout: all fields always visible - no column toggling needed

    // Auto-populate price fields when switching to Delivered

    if (delivered) {

        var poSel = document.getElementById('poRefSelect');

        var poId  = poSel ? parseInt(poSel.value, 10) : 0;

        if (poId) applyPOItemPrices(poId);

    }

    // Clear unit prices AND weights when moving away from Delivered

    if (!delivered) {

        document.querySelectorAll('[name="unit_price[]"]').forEach(function(el) { el.value = ''; });

        document.querySelectorAll('[name="gst_rate[]"]').forEach(function(el) { el.value = ''; });

        document.querySelectorAll('[name="weight[]"]').forEach(function(el) { el.value = '0'; });

        // Reset total weight display

        var twEl = document.getElementById('totalWeightDisplay');

        if (twEl) twEl.value = '0';

        // Recalculate totals

        dCalcTotal();

    }

}



function toggleHardCopy() {

    var type = (document.getElementById('freightInvType')||{}).value;

    var wrap = document.getElementById('hardCopyWrap');

    if (wrap) wrap.style.display = type === 'Scan' ? 'block' : 'none';

}



document.addEventListener('DOMContentLoaded', function() {

    // ── Partial lock if Delivered: lock main form, keep doc sections editable ──

    <?php if ($is_locked): ?>

    (function() {

        var form = document.getElementById('despatchForm');

        if (!form) return;

        // Sections that stay editable

        var docSec = document.getElementById('deliveryDocsSection');

        var fiSec  = document.getElementById('freightInvoiceSection');

        function isInEditableSection(el) {

            return (docSec && docSec.contains(el)) || (fiSec && fiSec.contains(el));

        }

        // Disable all inputs EXCEPT those inside delivery docs / freight invoice

        // AND except weight fields (d-weight) which admins can correct on Delivered orders

        form.querySelectorAll('input, select, textarea').forEach(function(el) {

            if (isInEditableSection(el)) return;

            if (el.classList.contains('d-weight') || el.id === 'transporterMiscCharges' || el.id === 'transporterMiscRemarks') return; // weight and misc charges always editable

            el.disabled = true;

            el.style.pointerEvents = 'none';

        });

        // Keep docs_only hidden field enabled

        var docsOnly = form.querySelector('input[name="docs_only"]');

        if (docsOnly) { docsOnly.disabled = false; }

        // Disable action buttons outside editable sections

        // BUT keep the Save Weight button active

        form.querySelectorAll('button[onclick]').forEach(function(el) {

            if (isInEditableSection(el)) return;

            if (el.id === 'saveWeightBtn') return; // keep Save Weight button active

            el.disabled = true;

            el.style.opacity = '0.5';

            el.style.pointerEvents = 'none';

        });

        // Hide add-item and remove buttons in items table

        var itemsCard = document.getElementById('dItemsTable');

        if (itemsCard) {

            itemsCard.closest('.card').querySelectorAll('.btn-outline-danger, [onclick*="addDRow"]').forEach(function(el) {

                el.style.display = 'none';

            });

        }

        // Force show delivery docs + freight invoice sections (they start hidden until status=Delivered)

        if (docSec) docSec.style.display = 'block';

        if (fiSec)  fiSec.style.display  = 'block';

    })();



    <?php endif; ?>



    // Wire all pre-rendered item rows (edit mode)

    document.querySelectorAll('#dItemsBody .d-item-card').forEach(function(row) { wireRow(row); });



    var vSel = document.getElementById('vendorSelect');

    if (vSel && vSel.value) filterPOByVendor(vSel.value);

    toggleMTC();

    bindMTCSyncListeners();

    syncMTCFields();   // populate on page load (edit mode)

    // Show delivery docs if status already Delivered (edit mode)

    var statusSel = document.querySelector('[name="status"]');

    if (statusSel) {

        statusSel.addEventListener('change', function() { onStatusChange(this); });

        onStatusChange(statusSel); // run on load

    }

    toggleHardCopy();

    // Vendor consignee autofill

    var vSel = document.getElementById('vendorSelect');

    if (vSel && vSel.value) {

        var data = vendorShipData[vSel.value];

        var hasConsignee = document.getElementById('consignee_name').value.trim();

        if (data && !hasConsignee) applyConsigneeData(data);

    }



    // Sync despatch date field → LR date on change

    var despatchDateEl = document.querySelector('[name="despatch_date"]');

    if (despatchDateEl) {

        despatchDateEl.addEventListener('change', function() {

            var lrDate = document.getElementById('lr_date');

            if (lrDate) lrDate.value = this.value;

        });

    }



    // Apply PO unit prices and balance on load if PO already selected

    var poSelEl = document.getElementById('poRefSelect');

    if (poSelEl && poSelEl.value) {

        var poId = parseInt(poSelEl.value, 10);

        applyPOItemPrices(poId);

        renderPOBalance(poId);

    }



    // Filter transporters and set freight rate on load (edit mode)

    var vSelInit = document.getElementById('vendorSelect');

    var tSelInit = document.getElementById('transporterSelect');

    if (vSelInit && vSelInit.value) {

        var vid = parseInt(vSelInit.value, 10);

        var rates = vendorTransporterRates[vid] || [];

        var allowedTids = rates.map(function(r) { return r.tid; });

        // Filter transporter options silently (keep current selection if valid)

        var currentTid = tSelInit ? parseInt(tSelInit.value, 10) : 0;

        Array.from(tSelInit ? tSelInit.options : []).forEach(function(opt) {

            if (!opt.value) return;

            var tid = parseInt(opt.value, 10);

            if (!allowedTids.includes(tid)) {

                opt.style.display = 'none';

                opt.disabled = true;

            }

        });

        // Set rate if transporter already selected

        if (currentTid) {

            var match = rates.find(function(r) { return r.tid === currentTid; });

            if (match) {

                currentTransporterRate = match.rate;

                currentRateUom = match.uom || '';

            }

        }

        dUpdateWeightAndFreight();

    }

});



/* ── Items table functions ──────────────────────────────── */

function makeNewRow() {

    var tpl = document.querySelector('#dRowTemplate .d-item-card');

    var row = tpl.cloneNode(true);



    // Re-enable all inputs and assign proper name attributes

    var itemSel = row.querySelector('.d-item-select');

    if (itemSel) { itemSel.removeAttribute('disabled'); itemSel.name = 'item_id[]'; }



    row.querySelectorAll('.d-uom-val').forEach(function(el) {

        el.removeAttribute('disabled'); el.name = 'uom[]';

    });

    row.querySelectorAll('.d-uom-display').forEach(function(el) { el.removeAttribute('disabled'); });

    row.querySelectorAll('.d-unit-price').forEach(function(el) { el.removeAttribute('disabled'); el.name = 'unit_price[]'; });

    row.querySelectorAll('.d-weight').forEach(function(el) { el.removeAttribute('disabled'); el.name = 'weight[]'; });

    row.querySelectorAll('.d-row-total').forEach(function(el) { el.removeAttribute('disabled'); el.name = 'total_price[]'; });

    row.querySelectorAll('.d-po-rate').forEach(function(el) { el.removeAttribute('disabled'); });



    // desc hidden, qty, gst_rate

    var descInp = row.querySelector('.d-desc-val');

    if (descInp) { descInp.removeAttribute('disabled'); descInp.name = 'desc[]'; }

    row.querySelectorAll('input[type="number"]').forEach(function(el) {

        if (!el.name && !el.disabled) return;

        el.removeAttribute('disabled');

        if (el.classList.contains('d-unit-price')) el.name = 'unit_price[]';

        else if (el.classList.contains('d-weight')) el.name = 'weight[]';

    });

    // qty and gst by label

    row.querySelectorAll('input[type="number"]').forEach(function(el) {

        var lbl = el.closest('.col-4, .col-12');

        if (!lbl) return;

        var ltext = lbl.querySelector('label') ? lbl.querySelector('label').textContent : '';

        if (ltext.includes('Qty')) { el.removeAttribute('disabled'); el.name = 'qty[]'; }

        if (ltext.includes('GST')) { el.removeAttribute('disabled'); el.name = 'gst_rate[]'; }

    });



    wireRow(row);

    return row;

}

function wireRow(row) {

    var itemSel = row.querySelector('.d-item-select');

    if (itemSel) itemSel.onchange = function() { dFillItem(this); };

    row.querySelectorAll('.d-weight, .d-unit-price, [name="gst_rate[]"]').forEach(function(f) {

        f.onchange = function() { dCalcRow(this); };

    });

    var btn = row.querySelector('button');

    if (btn) btn.onclick = function() { dRemove(this); };

}

function addDRow() {

    var body = document.getElementById('dItemsBody');

    // Insert before grand total line (last 2 children are total + template)

    body.insertBefore(makeNewRow(), body.lastElementChild.previousElementSibling || body.lastElementChild);

    dCalcTotal();

}

function dRemove(btn) {

    var body = document.getElementById('dItemsBody');

    if (body.querySelectorAll('.d-item-card').length > 1) btn.closest('.d-item-card').remove();

    dCalcTotal();

    dUpdateWeightAndFreight();

}

function dFillItem(sel) {

    var row = sel.closest('.d-item-card');

    var opt = sel.selectedOptions[0];

    if (!opt || !opt.value) return;

    var uomVal  = row.querySelector('.d-uom-val');

    var uomDisp = row.querySelector('.d-uom-display');

    if (uomVal)  uomVal.value  = opt.dataset.uom || '';

    if (uomDisp) uomDisp.value = opt.dataset.uom || '';



    var itemId   = parseInt(opt.value, 10);

    var poSel    = document.getElementById('poRefSelect');

    var poId     = poSel ? parseInt(poSel.value, 10) : 0;

    var priceMap = (poId && poItemsMap[poId]) ? poItemsMap[poId] : {};

    var gstMap   = (poId && poGstMap[poId])   ? poGstMap[poId]   : {};



    // Always show PO Rate as reference

    var poRateEl = row.querySelector('.d-po-rate');

    if (poRateEl) {

        var pr = priceMap[itemId];

        poRateEl.value = pr !== undefined ? '₹' + parseFloat(pr).toFixed(2) : '-';

    }



    // Unit price + GST only on Delivered

    var statusSel   = document.querySelector('[name="status"]');

    var isDelivered = statusSel && statusSel.value === 'Delivered';

    if (isDelivered) {

        row.querySelector('[name="unit_price[]"]').value = priceMap[itemId] !== undefined ? parseFloat(priceMap[itemId]).toFixed(2) : '';

        row.querySelector('[name="gst_rate[]"]').value   = gstMap[itemId]   !== undefined ? parseFloat(gstMap[itemId]).toFixed(2)   : '';

    }



    dCalcRow(row.querySelector('[name="weight[]"]'));

    syncMTCFields();

}

function dCalcRow(el) {

    if (!el) return;

    var row    = el.closest('.d-item-card');

    var price  = parseFloat(row.querySelector('[name="unit_price[]"]')?.value) || 0;

    var weight = parseFloat(row.querySelector('[name="weight[]"]')?.value)     || 0;

    var gst    = parseFloat(row.querySelector('[name="gst_rate[]"]')?.value)   || 0;

    var totalEl = row.querySelector('.d-row-total');

    // Total = PO Unit Rate × Received Weight + GST

    if (price > 0 && weight > 0) {

        var base  = price * weight;

        var total = base + (base * gst / 100);

        if (totalEl) totalEl.value = total.toFixed(2);

    } else {

        if (totalEl) totalEl.value = '0.00';

    }

    dCalcTotal();

    dUpdateWeightAndFreight();

}

function dCalcTotal() {

    let t = 0;

    document.querySelectorAll('#dItemsBody .d-row-total').forEach(f => t += parseFloat(f.value) || 0);

    document.getElementById('dGrandTotal').textContent = '₹' + t.toFixed(2);

}

function dUpdateWeightAndFreight() {

    var totalW  = getTotalWeight();

    var wDisp   = document.getElementById('totalWeightDisplay');

    if (wDisp) wDisp.value = totalW.toFixed(3);



    var rate    = currentTransporterRate || 0;

    var freight = rate * totalW;



    var fEl = document.getElementById('freightAmount');

    var formulaEl = document.getElementById('freightFormula');



    var badge = document.getElementById('freightRateCardBadge');

    if (rate > 0) {

        // Always store the rate per MT in the hidden field

        var rateHidden = document.getElementById('transporterRatePerMt');

        if (rateHidden) rateHidden.value = rate;



        if (fEl) {

            // If weight > 0, show rate × weight; if weight = 0, show the rate itself as a placeholder

            fEl.value = totalW > 0 ? freight.toFixed(2) : rate.toFixed(2);

            fEl.readOnly = true;

            fEl.classList.add('bg-light');

            fEl.title = 'Auto-filled from transporter rate card. Will be recalculated on delivery.';

            fEl.style.cursor = 'not-allowed';

        }

        if (badge) badge.style.display = '';

        var uomLabel = currentRateUom ? '/' + currentRateUom : '/Kg';

        if (formulaEl) formulaEl.innerHTML = '<span class="text-primary fw-semibold">'

            + '<i class="bi bi-check-circle me-1"></i>Rate Card: ₹'

            + rate.toFixed(4) + uomLabel + ' × ' + totalW.toFixed(3) + ' = ₹' + freight.toFixed(2) + '</span>';

    } else {

        var rateHiddenReset = document.getElementById('transporterRatePerMt');
        if (rateHiddenReset) rateHiddenReset.value = 0;

        if (fEl) {

            fEl.readOnly = false;

            fEl.classList.remove('bg-light');

            fEl.title = '';

            fEl.style.cursor = '';

        }

        if (badge) badge.style.display = 'none';

        if (formulaEl) formulaEl.innerHTML = '<span class="text-muted">Select Transporter &amp; Vendor to auto-fill rate</span>';

    }

}



// Run after DOM fully loaded so all weight fields exist

document.addEventListener('DOMContentLoaded', function() {

    dCalcTotal();

    dUpdateWeightAndFreight();

});

</script>

<?php endif; ?>



<!-- ═══════════════════════════════════════════════════════════

     EMAIL MODAL

═══════════════════════════════════════════════════════════ -->

<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

    <div class="modal-header" style="background:linear-gradient(135deg,#1E3A8A,#2563a8);color:#fff">

        <h5 class="modal-title" id="emailModalLabel">

            <i class="bi bi-envelope-fill me-2"></i>Send Despatch Email

        </h5>

        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

    </div>

    <div class="modal-body">

        <!-- Challan reference banner -->

        <div class="alert alert-primary py-2 mb-3 d-flex align-items-center gap-2">

            <i class="bi bi-receipt"></i>

            <span>Challan: <strong id="emailChallanNo"></strong> &nbsp;|&nbsp; Consignee: <strong id="emailConsignee"></strong></span>

            <span class="badge bg-primary ms-auto"><i class="bi bi-paperclip me-1"></i>PDF attached</span>

        </div>



        <div class="row g-3">

            <!-- Recipients -->

            <div class="col-12">

                <label class="form-label fw-semibold">

                    <i class="bi bi-people me-1"></i>Send To (Registered Users)

                    <small class="text-muted fw-normal ms-1">— Admin will always receive a CC copy</small>

                </label>

                <div class="border rounded p-2" style="max-height:180px;overflow-y:auto;background:#f8f9fa">

                <?php if (empty($email_users)): ?>

                    <div class="text-muted small p-2">No active users with email addresses found. Please add email addresses in User Management.</div>

                <?php else: ?>

                    <?php foreach ($email_users as $eu): ?>

                    <div class="form-check py-1 border-bottom">

                        <input class="form-check-input email-user-check" type="checkbox"

                               value="<?= $eu['id'] ?>"

                               id="eu_<?= $eu['id'] ?>"

                               data-email="<?= htmlspecialchars($eu['email']) ?>">

                        <label class="form-check-label d-flex align-items-center gap-2" for="eu_<?= $eu['id'] ?>">

                            <span class="fw-semibold"><?= htmlspecialchars($eu['full_name']) ?></span>

                            <span class="text-muted small"><?= htmlspecialchars($eu['email']) ?></span>

                            <span class="badge bg-<?= $eu['role']==='Admin'?'danger':'secondary' ?> ms-auto" style="font-size:.65rem"><?= $eu['role'] ?></span>

                        </label>

                    </div>

                    <?php endforeach; ?>

                <?php endif; ?>

                </div>

                <div class="mt-1">

                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllEmailUsers(true)">Select All</button>

                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="toggleAllEmailUsers(false)">Clear All</button>

                </div>

            </div>



            <!-- Extra email -->

            <div class="col-12 col-md-6">

                <label class="form-label fw-semibold"><i class="bi bi-at me-1"></i>Additional Email Address</label>

                <input type="email" id="extraEmailInput" class="form-control" placeholder="other@email.com (optional)">

            </div>



            <!-- Custom note -->

            <div class="col-12 col-md-6">

                <label class="form-label fw-semibold"><i class="bi bi-sticky me-1"></i>Custom Note (shown in email body)</label>

                <input type="text" id="emailCustomNote" class="form-control" placeholder="e.g. Please arrange unloading crew (optional)">

            </div>

        </div>



        <!-- Preview of selected emails -->

        <div class="mt-3" id="selectedEmailsPreview" style="display:none">

            <label class="form-label text-muted small">Will be sent to:</label>

            <div id="selectedEmailsList" class="d-flex flex-wrap gap-1"></div>

        </div>



        <!-- Status / result -->

        <div id="emailSendStatus" class="mt-3" style="display:none"></div>

    </div>

    <div class="modal-footer">

        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>

        <button type="button" class="btn btn-primary px-4" id="emailSendBtn" onclick="sendDespatchEmail()">

            <i class="bi bi-send me-1"></i>Send Email with PDF

        </button>

    </div>

</div>

</div>

</div>



<script>

var emailDespatchId = 0;



function openEmailModal(id, challanNo, consignee) {

    emailDespatchId = id;

    document.getElementById('emailChallanNo').textContent  = challanNo;

    document.getElementById('emailConsignee').textContent  = consignee;

    document.getElementById('emailSendStatus').style.display = 'none';

    document.getElementById('emailSendStatus').innerHTML   = '';

    document.getElementById('extraEmailInput').value       = '';

    document.getElementById('emailCustomNote').value       = '';

    // Uncheck all

    document.querySelectorAll('.email-user-check').forEach(cb => cb.checked = false);

    updateSelectedEmailsPreview();

    var modal = new bootstrap.Modal(document.getElementById('emailModal'));

    modal.show();

}



function toggleAllEmailUsers(state) {

    document.querySelectorAll('.email-user-check').forEach(cb => cb.checked = state);

    updateSelectedEmailsPreview();

}



document.addEventListener('DOMContentLoaded', function() {

    document.querySelectorAll('.email-user-check').forEach(cb => {

        cb.addEventListener('change', updateSelectedEmailsPreview);

    });

    var extraInp = document.getElementById('extraEmailInput');

    if (extraInp) extraInp.addEventListener('input', updateSelectedEmailsPreview);

});



function updateSelectedEmailsPreview() {

    var list    = document.getElementById('selectedEmailsList');

    var preview = document.getElementById('selectedEmailsPreview');

    if (!list) return;

    list.innerHTML = '';

    var any = false;

    document.querySelectorAll('.email-user-check:checked').forEach(cb => {

        list.innerHTML += '<span class="badge bg-primary">' + cb.dataset.email + '</span>';

        any = true;

    });

    var extra = document.getElementById('extraEmailInput');

    if (extra && extra.value.includes('@')) {

        list.innerHTML += '<span class="badge bg-secondary">' + extra.value + '</span>';

        any = true;

    }

    preview.style.display = any ? 'block' : 'none';

}



function sendDespatchEmail() {

    var checked = document.querySelectorAll('.email-user-check:checked');

    var extra   = document.getElementById('extraEmailInput').value.trim();

    var note    = document.getElementById('emailCustomNote').value.trim();



    if (checked.length === 0 && extra === '') {

        showEmailStatus('warning','<i class="bi bi-exclamation-triangle me-2"></i>Please select at least one recipient or enter an email address.');

        return;

    }



    var recipIds = [];

    checked.forEach(cb => recipIds.push(cb.value));



    var btn = document.getElementById('emailSendBtn');

    btn.disabled = true;

    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating PDF & Sending...';

    showEmailStatus('info','<span class="spinner-border spinner-border-sm me-2"></span>Generating PDF and sending email, please wait...');



    var fd = new FormData();

    fd.append('despatch_id', emailDespatchId);

    recipIds.forEach(id => fd.append('recipient_ids[]', id));

    if (extra) fd.append('extra_email', extra);

    if (note)  fd.append('custom_note', note);



    fetch('send_despatch_email.php', { method:'POST', body:fd })

    .then(r => {

        // Capture raw text first — if PHP crashes it returns HTML not JSON

        return r.text().then(text => {

            try {

                return JSON.parse(text);

            } catch(e) {

                // PHP returned an error page — show it

                throw new Error('PHP error: ' + text.replace(/<[^>]+>/g,'').substring(0,300));

            }

        });

    })

    .then(data => {

        btn.disabled = false;

        btn.innerHTML = '<i class="bi bi-send me-1"></i>Send Email with PDF';

        if (data.ok) {

            showEmailStatus('success','<i class="bi bi-check-circle-fill me-2"></i>' + data.msg);

            setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide(); }, 3000);

        } else {

            showEmailStatus('danger','<i class="bi bi-x-circle-fill me-2"></i>' + data.msg);

        }

    })

    .catch(err => {

        btn.disabled = false;

        btn.innerHTML = '<i class="bi bi-send me-1"></i>Send Email with PDF';

        showEmailStatus('danger','<i class="bi bi-x-circle-fill me-2"></i>' + err.message);

    });

}



function showEmailStatus(type, html) {

    var el = document.getElementById('emailSendStatus');

    el.style.display = 'block';

    el.innerHTML = '<div class="alert alert-' + type + ' py-2 mb-0">' + html + '</div>';

}

</script>



<?php include '../includes/footer.php'; ?>







