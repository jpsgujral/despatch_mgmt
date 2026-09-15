<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_overhead_helper.php';
require_once '../includes/fleet_lease_agent_helper.php';
if (file_exists('../includes/r2_helper.php')) require_once '../includes/r2_helper.php';
$db = getDB();
$trip_view = $_GET['view'] ?? '';
$is_trip_register_view = $trip_view === 'register';

function fleetTripSafeBackUrl(string $fallback = 'fleet_trips.php'): string {
    $back = trim((string)($_POST['back'] ?? $_GET['back'] ?? ''));
    if ($back === '') return $fallback;
    if (preg_match('/[\r\n]/', $back)) return $fallback;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $back)) return $fallback;
    if (str_starts_with($back, '//')) return $fallback;
    return $back;
}

function fleetLeaseAgentDisplayLabel(?array $agent, bool $withCode = true): string {
    if (!$agent) return '';
    $short = trim((string)($agent['agent_short_name'] ?? ''));
    $name  = trim((string)($agent['agent_name'] ?? ''));
    $label = $short !== '' ? $short : $name;
    if ($label === '') {
        $label = 'Agent #' . (int)($agent['id'] ?? 0);
    }
    return $label;
}

function fleetTripLeaseAgentSummaryMap($db): array {
    $summaryMap = [];
    $tripRows = $db->query("SELECT
            COALESCE(t.lease_agent_id, 0) AS lease_agent_id,
            COUNT(*) AS trip_count,
            COALESCE(SUM(
                (CASE WHEN COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') = 'mgmt_fee'
                      THEN COALESCE(t.lease_agent_amount, 0)
                      ELSE COALESCE(t.net_freight_amount, t.freight_amount - COALESCE(t.lease_agent_amount, 0))
                 END)
                * (1 + (CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END) / 100)
                - COALESCE(t.lease_agent_misc_deduction, 0)
            ), 0) AS total_payable
        FROM fleet_trips t
        LEFT JOIN fleet_lease_agents la ON la.id = t.lease_agent_id
        LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
        WHERE t.status='Completed' AND COALESCE(t.lease_agent_id, 0) > 0
        GROUP BY COALESCE(t.lease_agent_id, 0)")->fetch_all(MYSQLI_ASSOC);
    $paymentRows = $db->query("SELECT
            COALESCE(p.lease_agent_id, 0) AS lease_agent_id,
            COALESCE(SUM(p.amount), 0) AS paid
        FROM fleet_lease_agent_payments p
        WHERE COALESCE(p.lease_agent_id, 0) > 0
        GROUP BY COALESCE(p.lease_agent_id, 0)")->fetch_all(MYSQLI_ASSOC);
    foreach ($tripRows as $row) {
        $aid = (int)($row['lease_agent_id'] ?? 0);
        if ($aid <= 0) continue;
        $summaryMap[$aid] = [
            'total_payable' => (float)($row['total_payable'] ?? 0),
            'paid' => 0.0,
            'balance' => (float)($row['total_payable'] ?? 0),
            'trip_count' => (int)($row['trip_count'] ?? 0),
        ];
    }
    foreach ($paymentRows as $row) {
        $aid = (int)($row['lease_agent_id'] ?? 0);
        if ($aid <= 0) continue;
        if (!isset($summaryMap[$aid])) {
            $summaryMap[$aid] = ['total_payable' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'trip_count' => 0];
        }
        $summaryMap[$aid]['paid'] = (float)($row['paid'] ?? 0);
    }
    foreach ($summaryMap as $aid => $row) {
        $summaryMap[$aid]['balance'] = max(0, (float)$row['total_payable'] - (float)$row['paid']);
    }
    return $summaryMap;
}

/* ── AJAX: Add new source of material ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_source' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level() > 0) ob_clean(); // discard any output already sent
    header('Content-Type: application/json');
    try {
        $source_name = trim($db->real_escape_string($_POST['source_name'] ?? ''));
        if (!$source_name) {
            echo json_encode(['success' => false, 'error' => 'Source name required.']);
            exit;
        }
        // Check if already exists
        $exists = $db->query("SELECT id FROM source_of_material WHERE source_name='$source_name' LIMIT 1")->fetch_assoc();
        if ($exists) {
            echo json_encode(['success' => true, 'source_name' => html_entity_decode($source_name, ENT_QUOTES, 'UTF-8')]);
            exit;
        }
        // Generate a simple source code from name (first 3 chars uppercase + random)
        $source_code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $source_name), 0, 3)) . rand(10,99);
        // Check if status column exists
        $dbname2   = $db->query("SELECT DATABASE()")->fetch_row()[0];
        $has_status= $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname2' AND TABLE_NAME='source_of_material'
            AND COLUMN_NAME='status' LIMIT 1")->num_rows;
        $has_code  = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname2' AND TABLE_NAME='source_of_material'
            AND COLUMN_NAME='source_code' LIMIT 1")->num_rows;
        $esc_code = $db->real_escape_string($source_code);
        if ($has_status && $has_code) {
            $db->query("INSERT INTO source_of_material (source_code, source_name, status) VALUES ('$esc_code', '$source_name', 'Active')");
        } elseif ($has_code) {
            $db->query("INSERT INTO source_of_material (source_code, source_name) VALUES ('$esc_code', '$source_name')");
        } elseif ($has_status) {
            $db->query("INSERT INTO source_of_material (source_name, status) VALUES ('$source_name', 'Active')");
        } else {
            $db->query("INSERT INTO source_of_material (source_name) VALUES ('$source_name')");
        }
        if ($db->insert_id > 0) {
            echo json_encode(['success' => true, 'source_name' => html_entity_decode($source_name, ENT_QUOTES, 'UTF-8')]);
        } else {
            echo json_encode(['success' => false, 'error' => $db->error ?: 'Insert failed.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

requirePerm($is_trip_register_view ? 'fleet_trip_register' : 'fleet_trips', 'view');

/* ── AJAX: Save MRN ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_mrn') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $tid = (int)($_POST['trip_id'] ?? 0);
    if (!$tid) { echo json_encode(['success'=>false]); exit; }
    $db2 = getDB();
    $mrn_no     = $db2->real_escape_string(sanitize($_POST['mrn_no']     ?? ''));
    $mrn_date   = sanitize($_POST['mrn_date']    ?? ''); $md_sql = $mrn_date ? "'$mrn_date'" : 'NULL';
    $inv_reg_no = $db2->real_escape_string(sanitize($_POST['inv_reg_no'] ?? ''));
    $inv_reg_dt = sanitize($_POST['inv_reg_date'] ?? ''); $id_sql = $inv_reg_dt ? "'$inv_reg_dt'" : 'NULL';
    $db2->query("UPDATE fleet_trips SET mrn_no='$mrn_no', mrn_date=$md_sql, inv_reg_no='$inv_reg_no', inv_reg_date=$id_sql WHERE id=$tid");
    echo json_encode(['success'=>true]);
    exit;
}

/* ── AJAX: Upload trip document ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'upload_doc') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    $trip_id = (int)($_POST['trip_id'] ?? 0);
    if (!$trip_id || empty($_FILES['doc_file']['name']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'error'=>'Invalid request or no file.']); exit;
    }
    $db      = getDB();
    // Direct cURL upload with full error detail
    $uf  = $_FILES['doc_file'];
    $ext = strtolower(pathinfo($uf['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf','jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success'=>false,'error'=>'File type not allowed: .'.$ext]); exit;
    }
    if ($uf['size'] > 10*1024*1024) {
        echo json_encode(['success'=>false,'error'=>'File too large: '.round($uf['size']/1048576,1).'MB']); exit;
    }
    $r2_key = '';
    $newKey = 'trip_docs/trip_'.$trip_id.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
    $mime   = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
               'png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'][$ext] ?? 'application/octet-stream';
    $body   = file_get_contents($uf['tmp_name']);
    if ($body === false) {
        echo json_encode(['success'=>false,'error'=>'Cannot read uploaded file from temp storage.']); exit;
    }
    $workerUrl = defined('R2_WORKER_URL') ? R2_WORKER_URL : 'https://dms-r2-upload.jpsgujral.workers.dev';
    $token     = defined('R2_WORKER_TOKEN') ? R2_WORKER_TOKEN : 'dms_worker_s3cur3_t0k3n_2024';
    $ch = curl_init($workerUrl.'/'.ltrim($newKey,'/'));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'X-DMS-Token: '.$token,
            'Content-Type: '.$mime,
            'Content-Length: '.strlen($body),
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($code === 200) {
        $r2_key = $newKey;
    } else {
        echo json_encode(['success'=>false,'error'=>'R2 error — HTTP '.$code.(($cerr)?' | cURL: '.$cerr:'').' | Resp: '.substr($resp,0,300).' | URL: '.$workerUrl.'/'.$newKey]); exit;
    }
    $doc_type = $db->real_escape_string(sanitize($_POST['doc_type'] ?? 'Other'));
    $doc_name = $db->real_escape_string(sanitize($_POST['doc_name'] ?? $_FILES['doc_file']['name']));
    $r2_esc   = $db->real_escape_string($r2_key);
    $uid      = (int)($_SESSION['user_id'] ?? 0);
    $db->query("INSERT INTO fleet_trip_documents (trip_id,doc_type,doc_name,file_path,uploaded_by)
        VALUES ($trip_id,'$doc_type','$doc_name','$r2_esc',$uid)");
    $new_id = $db->insert_id;
    $pub_url = defined('R2_PUBLIC_URL') ? R2_PUBLIC_URL : 'https://pub-5721570094064d529f1527519424c77b.r2.dev/dms_uploads';
    $doc_url = $pub_url . '/' . ltrim($r2_key, '/');
    echo json_encode(['success'=>true,'id'=>$new_id,'r2_key'=>$r2_key,'url'=>$doc_url,'doc_type'=>$doc_type,'doc_name'=>$doc_name]);
    exit;
}

/* ── AJAX: Delete trip document ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_doc') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if (!$doc_id) { echo json_encode(['success'=>false]); exit; }
    $db  = getDB();
    $doc = $db->query("SELECT * FROM fleet_trip_documents WHERE id=$doc_id LIMIT 1")->fetch_assoc();
    if ($doc) {
        r2_delete($doc['file_path']);
        $db->query("DELETE FROM fleet_trip_documents WHERE id=$doc_id");
    }
    echo json_encode(['success'=>true]);
    exit;
}

/* ── AJAX: Admin update weight on completed trip ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_weight') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    if (!isAdmin()) { echo json_encode(['success'=>false,'error'=>'Admin only.']); exit; }
    $tid = (int)($_POST['trip_id'] ?? 0);
    $wt  = (float)($_POST['total_weight'] ?? 0);
    if (!$tid || $wt <= 0) {
        echo json_encode(['success'=>false,'error'=>'Invalid trip or weight must be > 0.']); exit;
    }
    $db2 = getDB();

    // Update total_weight on the trip
    $db2->query("UPDATE fleet_trips SET total_weight=$wt WHERE id=$tid AND status='Completed'");

    // Also update fleet_trip_items:
    // - If only 1 item row: assign full weight to it
    // - If multiple items: distribute weight equally across all rows
    // - Recalculate qty and amount (weight x unit_price) for each row
    $items = $db2->query("SELECT id, unit_price FROM fleet_trip_items WHERE trip_id=$tid ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    $item_count = count($items);
    if ($item_count > 0) {
        $per_item_wt = round($wt / $item_count, 3);
        $remaining   = $wt;
        foreach ($items as $idx => $item) {
            $row_wt    = ($idx === $item_count - 1) ? round($remaining, 3) : $per_item_wt;
            $remaining = round($remaining - $per_item_wt, 3);
            $price     = (float)$item['unit_price'];
            $amount    = round($row_wt * $price, 2);
            $iid       = (int)$item['id'];
            $db2->query("UPDATE fleet_trip_items SET weight=$row_wt, qty=$row_wt, amount=$amount WHERE id=$iid");
        }
        // Recalculate trip subtotal and total_amount from items
        $totals    = $db2->query("SELECT SUM(amount) as ta FROM fleet_trip_items WHERE trip_id=$tid")->fetch_assoc();
        $new_total = round((float)($totals['ta'] ?? 0), 2);
        $db2->query("UPDATE fleet_trips SET subtotal=$new_total, total_amount=$new_total WHERE id=$tid");
    }

    echo json_encode(['success'=>true,'weight'=>$wt,'items_updated'=>$item_count]);
    exit;
}


function normalizeTripWorkflowStatus(?string $status): string {
    $status = trim((string)$status);
    return match ($status) {
        'Planned', 'In Transit', 'Completed', 'Cancelled' => $status,
        'Loading',
        'Despatched to Customer after Loading',
        'Reached Customer Location',
        'Returning for Loading after Unloading at Customer Point',
        'Breakdown' => 'In Transit',
        default => 'Planned',
    };
}

/* ── Tables ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_trips (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    trip_no             VARCHAR(30) NOT NULL UNIQUE,
    trip_date           DATE NOT NULL,
    po_id               INT DEFAULT NULL,
    vendor_id           INT DEFAULT NULL,
    vehicle_id          INT NOT NULL,
    driver_id           INT NOT NULL,
    supervisor_id       INT DEFAULT NULL,
    from_location       VARCHAR(200),
    to_location         VARCHAR(200),
    customer_name       VARCHAR(200),
    customer_camp       VARCHAR(120) DEFAULT '',
    customer_address    TEXT,
    customer_city       VARCHAR(80),
    customer_state      VARCHAR(80),
    customer_gstin      VARCHAR(20),
    total_weight        DECIMAL(10,3) DEFAULT 0,
    uom                 VARCHAR(20) DEFAULT 'MT',
    start_odometer      INT DEFAULT 0,
    end_odometer        INT DEFAULT 0,
    start_date          DATE DEFAULT NULL,
    end_date            DATE DEFAULT NULL,
    freight_amount      DECIMAL(12,2) DEFAULT 0,
    driver_advance      DECIMAL(10,2) DEFAULT 0,
    toll_amount         DECIMAL(10,2) DEFAULT 0,
    loading_charges     DECIMAL(10,2) DEFAULT 0,
    unloading_charges   DECIMAL(10,2) DEFAULT 0,
    other_expenses      DECIMAL(10,2) DEFAULT 0,
    subtotal            DECIMAL(12,2) DEFAULT 0,
    total_amount        DECIMAL(12,2) DEFAULT 0,
    mtc_required        ENUM('No','Yes') DEFAULT 'No',
    mtc_source          VARCHAR(120) DEFAULT '',
    mtc_item_name       VARCHAR(120) DEFAULT '',
    mtc_test_date       DATE DEFAULT NULL,
    mtc_ros_45          VARCHAR(30) DEFAULT '',
    mtc_moisture        VARCHAR(30) DEFAULT '',
    mtc_loi             VARCHAR(30) DEFAULT '',
    mtc_fineness        VARCHAR(30) DEFAULT '',
    mtc_remarks         VARCHAR(255) DEFAULT '',
    status              ENUM('Planned','In Transit','Completed','Cancelled') DEFAULT 'Planned',
    remarks             TEXT,
    company_id          INT DEFAULT 1,
    created_by          INT DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

fleetSafeAddCol($db, 'fleet_trips', 'po_id',        'INT DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'vendor_id',     'INT DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'customer_camp', "VARCHAR(120) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'total_weight',  'DECIMAL(10,3) DEFAULT 0');
fleetSafeAddCol($db, 'fleet_trips', 'subtotal',      'DECIMAL(12,2) DEFAULT 0');
fleetSafeAddCol($db, 'fleet_trips', 'total_amount',  'DECIMAL(12,2) DEFAULT 0');
fleetSafeAddCol($db, 'fleet_trips', 'mtc_required',  "ENUM('No','Yes') DEFAULT 'No'");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_source',    "VARCHAR(120) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_item_name', "VARCHAR(120) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_test_date', 'DATE DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'mtc_ros_45',    "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_moisture',  "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_loi',       "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_fineness',  "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_remarks',   "VARCHAR(255) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'company_id',    'INT DEFAULT 1');
fleetSafeAddCol($db, 'fleet_trips', 'mrn_no',        "VARCHAR(80) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mrn_date',      'DATE DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'inv_reg_no',    "VARCHAR(80) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'inv_reg_date',  'DATE DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'billing_status', "ENUM('Pending','Billed') DEFAULT 'Pending'");
fleetSafeAddCol($db, 'fleet_trips', 'sales_bill_no',  "VARCHAR(80) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'sales_bill_date','DATE DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'billing_remarks',"VARCHAR(255) DEFAULT ''");

fleetEnsureLeaseAgents($db);
fleetEnsureLeaseTripFields($db);

// Clean up any legacy/board-style statuses that may have been saved earlier.
$db->query("UPDATE fleet_trips
    SET status = CASE
        WHEN status IN ('Loading',
            'Despatched to Customer after Loading',
            'Reached Customer Location',
            'Returning for Loading after Unloading at Customer Point',
            'Breakdown') THEN 'In Transit'
        WHEN status IN ('Planned', 'In Transit', 'Completed', 'Cancelled') THEN status
        ELSE 'Planned'
    END");

/* ── Trip Documents table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_trip_documents (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    trip_id     INT NOT NULL,
    doc_type    VARCHAR(80) DEFAULT 'Other',
    doc_name    VARCHAR(255),
    file_path   VARCHAR(255) NOT NULL,
    uploaded_by INT DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_trip (trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS fleet_trip_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    trip_id     INT NOT NULL,
    item_id     INT DEFAULT NULL,
    item_name   VARCHAR(200),
    qty         DECIMAL(12,3) DEFAULT 0,
    uom         VARCHAR(20) DEFAULT 'MT',
    unit_price  DECIMAL(12,2) DEFAULT 0,
    weight      DECIMAL(10,3) DEFAULT 0,
    amount      DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS fleet_vendor_destination_toll_rates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id   INT NOT NULL,
    to_location VARCHAR(255) NOT NULL,
    toll_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status      ENUM('Active','Inactive') DEFAULT 'Active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vendor_destination (vendor_id, to_location),
    KEY idx_vendor_status (vendor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Trip number generator ── */
function generateTripNo($db) {
    $month    = (int)date('m'); $year = (int)date('Y');
    $fy_start = $month >= 4 ? $year : $year - 1;
    $fy_label = ($fy_start % 100) . '-' . str_pad(($fy_start+1) % 100, 2, '0', STR_PAD_LEFT);
    $fy_key   = 'trip_fy' . ($fy_start % 100) . str_pad(($fy_start+1) % 100, 2, '0', STR_PAD_LEFT);
    $prefix   = "TR/$fy_label/";

    $db->query("LOCK TABLES fleet_trips WRITE, doc_sequences WRITE");

    // Check sequence table seed first
    $seq_res = $db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$fy_key' LIMIT 1");
    $seq = ($seq_res && $seq_res !== false) ? $seq_res->fetch_assoc() : null;
    $row_res = $db->query("SELECT trip_no FROM fleet_trips WHERE trip_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1");
    $row = ($row_res && $row_res !== false) ? $row_res->fetch_assoc() : null;

    $from_seq = $seq ? (int)$seq['last_val'] : 0;
    $from_db  = $row ? (int)substr($row['trip_no'], strrpos($row['trip_no'], '/') + 1) : 0;
    $next     = max($from_seq, $from_db) + 1;

    $trip_no = $prefix . $next; // no zero-padding for large numbers
    $db->query("UNLOCK TABLES");
    return $trip_no;
}

function peekNextTripNo($db): string {
    $month    = (int)date('m'); $year = (int)date('Y');
    $fy_start = $month >= 4 ? $year : $year - 1;
    $fy_label = ($fy_start % 100) . '-' . str_pad(($fy_start+1) % 100, 2, '0', STR_PAD_LEFT);
    $fy_key   = 'trip_fy' . ($fy_start % 100) . str_pad(($fy_start+1) % 100, 2, '0', STR_PAD_LEFT);
    $prefix   = "TR/$fy_label/";

    $seq_res = $db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$fy_key' LIMIT 1");
    $seq = ($seq_res && $seq_res !== false) ? $seq_res->fetch_assoc() : null;
    $row_res = $db->query("SELECT trip_no FROM fleet_trips WHERE trip_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1");
    $row = ($row_res && $row_res !== false) ? $row_res->fetch_assoc() : null;

    $from_seq = $seq ? (int)$seq['last_val'] : 0;
    $from_db  = $row ? (int)substr($row['trip_no'], strrpos($row['trip_no'], '/') + 1) : 0;
    $next     = max($from_seq, $from_db) + 1;

    return $prefix . $next;
}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$trip_back_url = fleetTripSafeBackUrl('fleet_trips.php');
$current_trip_po_id = 0;
$is_lease_agent_user = isLeaseAgentUser();
$current_lease_agent_id = currentLeaseAgentId();
$tripLeaseScopeSql = ($is_lease_agent_user && $current_lease_agent_id > 0)
    ? " AND t.lease_agent_id=" . (int)$current_lease_agent_id
    : "";
if ($is_lease_agent_user && !in_array($action, ['list', 'view', 'docs', 'add'], true)) {
    showAlert('danger', 'Lease agent users can view and create their own trip orders.');
    redirect('fleet_trips.php?action=list');
}

/* ── Delete ── */
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    if ($is_lease_agent_user && $current_lease_agent_id > 0) {
        $ownedTrip = $db->query("SELECT id FROM fleet_trips WHERE id=$did AND lease_agent_id=" . (int)$current_lease_agent_id . " LIMIT 1")->fetch_assoc();
        if (!$ownedTrip) {
            showAlert('danger', 'You can only manage your own lease agent trips.');
            redirect($trip_back_url);
        }
    }
    $db->query("DELETE FROM fleet_trip_items WHERE trip_id=$did");
    $db->query("DELETE FROM fleet_trips WHERE id=$did AND status='Planned'");
    showAlert('success', 'Trip deleted.');
    redirect($trip_back_url);
}

if ($id > 0) {
    $trip_po_row = $db->query("SELECT po_id FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc();
    $current_trip_po_id = (int)($trip_po_row['po_id'] ?? 0);
}

/* ── Quick status update ── */
if (isset($_GET['setstatus']) && $id) {
    requirePerm('fleet_trips', 'update');
    if ($is_lease_agent_user && $current_lease_agent_id > 0) {
        $ownedTrip = $db->query("SELECT id FROM fleet_trips WHERE id=$id AND lease_agent_id=" . (int)$current_lease_agent_id . " LIMIT 1")->fetch_assoc();
        if (!$ownedTrip) {
            showAlert('danger', 'You can only manage your own lease agent trips.');
            redirect('fleet_trips.php?action=view&id=' . $id . '&back=' . urlencode($trip_back_url));
        }
    }
    $ns = normalizeTripWorkflowStatus(sanitize($_GET['setstatus']));
    if (in_array($ns, ['Planned','In Transit','Completed','Cancelled'], true)) {
        $extra = '';
        if ($ns === 'In Transit') $extra = ", start_date='" . date('Y-m-d') . "'";
        if ($ns === 'Completed')  $extra = ", end_date='"   . date('Y-m-d') . "'";
        $db->query("UPDATE fleet_trips SET status='$ns'$extra WHERE id=$id");
        if ($ns === 'Completed') {
            $trip = $db->query("SELECT po_id FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc();
            if (!empty($trip['po_id'])) {
                $po_id = (int)$trip['po_id'];
                $db->query("UPDATE fleet_purchase_orders SET status='Partially Received' WHERE id=$po_id AND status='Approved'");
            }
        }
        showAlert('success', "Status updated to $ns.");
    }
    redirect('fleet_trips.php?action=view&id=' . $id . '&back=' . urlencode($trip_back_url));
}

/* -- Quick billing update from Trip Order Register -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_trip_billing']) && $id > 0) {
    requirePerm('fleet_trip_register', 'view');

    $trip_row = $db->query("SELECT status FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc();
    $trip_status = normalizeTripWorkflowStatus($trip_row['status'] ?? '');
    if ($trip_status !== 'Completed') {
        showAlert('danger', 'Sales billing can only be updated for completed trips.');
        redirect('fleet_trips.php?action=list&view=register');
    }

    $billing_status = (($_POST['billing_status'] ?? 'Pending') === 'Billed') ? 'Billed' : 'Pending';
    $sales_bill_no = sanitize($_POST['sales_bill_no'] ?? '');
    $sales_bill_date = sanitize($_POST['sales_bill_date'] ?? '');
    $billing_remarks = sanitize($_POST['billing_remarks'] ?? '');

    if ($billing_status === 'Billed' && $sales_bill_no === '') {
        showAlert('danger', 'Sales Bill No is required when marking billing as Billed.');
        redirect('fleet_trips.php?action=list&view=register');
    }
    if ($billing_status === 'Billed' && $sales_bill_date === '') {
        showAlert('danger', 'Sales Bill Date is required when marking billing as Billed.');
        redirect('fleet_trips.php?action=list&view=register');
    }

    $bill_no_sql = $db->real_escape_string($sales_bill_no);
    $bill_rem_sql = $db->real_escape_string($billing_remarks);
    $bill_date_sql = ($billing_status === 'Billed' && $sales_bill_date !== '') ? "'$sales_bill_date'" : 'NULL';

    if ($billing_status !== 'Billed') {
        $bill_no_sql = '';
        $bill_date_sql = 'NULL';
    }

    $db->query("UPDATE fleet_trips
        SET billing_status='$billing_status',
            sales_bill_no='$bill_no_sql',
            sales_bill_date=$bill_date_sql,
            billing_remarks='$bill_rem_sql'
        WHERE id=$id");

    showAlert('success', 'Trip sales billing updated.');
    redirect('fleet_trips.php?action=list&view=register');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_trip'])) {
    if ($id > 0) {
        requirePerm('fleet_trips', 'update');
        if ($is_lease_agent_user) {
            showAlert('danger', 'Lease agent users can only create new trip orders.');
            redirect('fleet_trips.php?action=list');
        }
    } else {
        if (!canDo('fleet_trips', 'create') && !$is_lease_agent_user) {
            requirePerm('fleet_trips', 'create');
        }
    }

    $toScalar = function($v, string $default = ''): string {
        while (is_array($v)) {
            $v = reset($v);
        }
        return (string)($v ?? $default);
    };

    $trip_no   = $toScalar($id > 0 ? sanitize($_POST['trip_no']) : generateTripNo($db));
    $trip_date = $toScalar(sanitize($_POST['trip_date'] ?? ''));
    $po_id     = (int)($_POST['po_id']    ?? 0);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $veh_id    = (int)($_POST['vehicle_id'] ?? 0);
    $drv_id    = (int)($_POST['driver_id']  ?? 0);
    $sup_id    = (int)($_POST['supervisor_id'] ?? 0);
    $from      = $toScalar(sanitize($_POST['from_location']    ?? ''));
    $to        = $toScalar(sanitize($_POST['to_location']      ?? ''));
    $cust_name = $toScalar(sanitize($_POST['customer_name']    ?? ''));
    $cust_camp = $toScalar(sanitize($_POST['customer_camp']    ?? ''));
    $cust_addr = $toScalar(sanitize($_POST['customer_address'] ?? ''));
    $cust_city = $toScalar(sanitize($_POST['customer_city']    ?? ''));
    $cust_state= $toScalar(sanitize($_POST['customer_state']   ?? ''));
    $cust_gst  = $toScalar(sanitize($_POST['customer_gstin']   ?? ''));
    $uom       = $toScalar(sanitize($_POST['uom']  ?? 'MT'), 'MT');
    $start_odo = (int)($_POST['start_odometer'] ?? 0);
    $end_odo   = (int)($_POST['end_odometer']   ?? 0);
    $start_dt  = $toScalar(sanitize($_POST['start_date']  ?? ''));
    $end_dt    = $toScalar(sanitize($_POST['end_date']    ?? ''));
    $freight   = (float)($_POST['freight_amount']  ?? 0);
    $lease_agent_id = (int)($_POST['lease_agent_id'] ?? 0);
    if ($is_lease_agent_user) {
        $lease_agent_id = $current_lease_agent_id;
    }
    $lease_agent_name = $toScalar(sanitize($_POST['lease_agent_name'] ?? ''));
    $lease_agent_margin_per_mt = (float)($_POST['lease_agent_margin_per_mt'] ?? 0);
    $lease_agent_amount = (float)($_POST['lease_agent_amount'] ?? 0);
    $net_freight_amount = (float)($_POST['net_freight_amount'] ?? 0);
    $lease_agent_misc_deduction = (float)($_POST['lease_agent_misc_deduction'] ?? 0);
    $lease_agent_misc_deduction_remarks = $toScalar(sanitize($_POST['lease_agent_misc_deduction_remarks'] ?? ''));
    $lease_agent_billing_status = (($_POST['lease_agent_billing_status'] ?? 'Pending') === 'Billed') ? 'Billed' : 'Pending';
    $lease_agent_invoice_no = $toScalar(sanitize($_POST['lease_agent_invoice_no'] ?? ''));
    $lease_agent_invoice_date = $toScalar(sanitize($_POST['lease_agent_invoice_date'] ?? ''));
    $lease_agent_billing_remarks = $toScalar(sanitize($_POST['lease_agent_billing_remarks'] ?? ''));
    $lease_agent_bill_to_company_id = (int)($_POST['lease_agent_bill_to_company_id'] ?? 0);
    $lease_agent_fuel = (float)($_POST['lease_agent_fuel'] ?? 0);
    $lease_agent_driver_advance = (float)($_POST['lease_agent_driver_advance'] ?? 0);
    $lease_agent_driver_fooding = (float)($_POST['lease_agent_driver_fooding'] ?? 0);
    $lease_agent_toll = (float)($_POST['lease_agent_toll'] ?? 0);
    $lease_agent_misc_expense = (float)($_POST['lease_agent_misc_expense'] ?? 0);
    $lease_agent_profit = 0;
    if ($is_lease_agent_user && $current_lease_agent_id > 0) {
        $agent_row = $db->query("SELECT agent_name, agent_code, default_margin_per_mt
            FROM fleet_lease_agents
            WHERE id=" . (int)$current_lease_agent_id . "
            LIMIT 1")->fetch_assoc();
        if ($agent_row) {
            $lease_agent_name = (string)($agent_row['agent_name'] ?? '');
            $lease_agent_margin_per_mt = (float)($agent_row['default_margin_per_mt'] ?? 0);
        }
    }
    $advance   = (float)($_POST['driver_advance']  ?? 0);
    $toll      = (float)($_POST['toll_amount']     ?? 0);
    $loading   = (float)($_POST['loading_charges'] ?? 0);
    $unloading = (float)($_POST['unloading_charges'] ?? 0);
    $other     = (float)($_POST['other_expenses']  ?? 0);
    $status    = normalizeTripWorkflowStatus($toScalar(sanitize($_POST['status']  ?? 'Planned'), 'Planned'));
    $remarks   = $toScalar(sanitize($_POST['remarks'] ?? ''));
    $co_id     = (int)($_POST['company_id'] ?? activeCompanyId());

    if ($po_id > 0) {
        $po_ref = $db->query("SELECT vendor_id, company_id, delivery_address FROM fleet_purchase_orders WHERE id=$po_id LIMIT 1")->fetch_assoc();
        if ($po_ref) {
            $vendor_id = (int)($po_ref['vendor_id'] ?? 0);
            $po_company_id = (int)($po_ref['company_id'] ?? 0);
            $co_id = $po_company_id > 0 ? $po_company_id : activeCompanyId();
            if ($to === '' && !empty($po_ref['delivery_address'])) {
                $to = $toScalar(sanitize($po_ref['delivery_address']));
            }
        }
    }

    if ($toll <= 0 && $vendor_id > 0) {
        $toll_row = null;
        if ($to !== '') {
            $to_sql = $db->real_escape_string($to);
            $toll_row = $db->query("SELECT toll_amount FROM fleet_vendor_destination_toll_rates
                WHERE vendor_id=$vendor_id AND status='Active' AND LOWER(TRIM(to_location))=LOWER(TRIM('$to_sql'))
                ORDER BY updated_at DESC, id DESC LIMIT 1")->fetch_assoc();
        }
        if (!$toll_row) {
            $toll_row = $db->query("SELECT toll_amount FROM fleet_vendor_destination_toll_rates
                WHERE vendor_id=$vendor_id AND status='Active'
                ORDER BY updated_at DESC, id DESC LIMIT 1")->fetch_assoc();
        }
        if ($toll_row) {
            $toll = (float)($toll_row['toll_amount'] ?? 0);
        }
    }

    /* MTC */
    $mtc_req   = in_array($_POST['mtc_required'] ?? 'No', ['Yes','No']) ? $_POST['mtc_required'] : 'No';
    $mtc_src   = $toScalar(sanitize($_POST['mtc_source']    ?? ''));
    $mtc_item  = $toScalar(sanitize($_POST['mtc_item_name'] ?? ''));
    $mtc_tdate = $toScalar(sanitize($_POST['mtc_test_date'] ?? ''));
    $mtc_ros   = $toScalar(sanitize($_POST['mtc_ros_45']    ?? ''));
    $mtc_moist = $toScalar(sanitize($_POST['mtc_moisture']  ?? ''));
    $mtc_loi   = $toScalar(sanitize($_POST['mtc_loi']       ?? ''));
    $mtc_fine  = $toScalar(sanitize($_POST['mtc_fineness']  ?? ''));
    $mtc_rem   = $toScalar(sanitize($_POST['mtc_remarks']   ?? ''));
    $mtc_tdate_sql = $mtc_tdate ? "'$mtc_tdate'" : 'NULL';

    /* Items */
    $item_ids   = $_POST['item_id']     ?? [];
    $item_names = $_POST['item_name']   ?? [];
    $item_uoms  = $_POST['uom']          ?? $_POST['item_uom']    ?? [];
    $item_prices= $_POST['unit_price']   ?? $_POST['item_price']  ?? [];
    $item_wts   = $_POST['weight']       ?? $_POST['item_weight'] ?? [];

    $posted_total_weight = (float)($_POST['total_weight'] ?? 0);
    $subtotal = 0; $total_weight = 0; $valid_items = [];
    foreach ($item_names as $idx => $iname) {
        $iname = trim($iname);
        $iid = (int)($item_ids[$idx] ?? 0);
        if (!$iname && $iid > 0) {
            foreach ($items_list as $il) {
                if ((int)$il['id'] === $iid) {
                    $iname = $il['item_name'];
                    break;
                }
            }
        }
        if (!$iname) continue;
        $wt    = (float)($item_wts[$idx]    ?? 0);
        $price = (float)($item_prices[$idx] ?? 0);
        $amt   = round($wt * $price, 2);
        $subtotal     += $amt;
        $total_weight += $wt;
        $uom_val = is_array($item_uoms) ? ($item_uoms[$idx] ?? 'MT') : (string)$item_uoms;
        $valid_items[] = [
            'item_id'   => $iid,
            'item_name' => $db->real_escape_string($iname),
            'qty'       => $wt,
            'uom'       => $db->real_escape_string($uom_val ?: 'MT'),
            'unit_price'=> $price,
            'weight'    => $wt,
            'amount'    => $amt,
        ];
    }

    if ($total_weight <= 0 && $posted_total_weight > 0) {
        $total_weight = $posted_total_weight;
        if (count($valid_items) === 1) {
            $valid_items[0]['weight'] = $total_weight;
            $valid_items[0]['qty'] = $total_weight;
            if ($valid_items[0]['unit_price'] > 0) {
                $valid_items[0]['amount'] = round($total_weight * $valid_items[0]['unit_price'], 2);
                $subtotal = $valid_items[0]['amount'];
            }
        }
    }

    // Protection for Completed trips: if valid_items was empty, preserve existing DB items
    if (empty($valid_items) && $id > 0) {
        $old_items = $db->query("SELECT * FROM fleet_trip_items WHERE trip_id=$id")->fetch_all(MYSQLI_ASSOC);
        if (!empty($old_items)) {
            foreach ($old_items as $oi) {
                $wt = (float)($oi['weight'] ?? 0);
                $amt = (float)($oi['amount'] ?? 0);
                $total_weight += $wt;
                $subtotal += $amt;
                $valid_items[] = [
                    'item_id'    => (int)($oi['item_id'] ?? 0),
                    'item_name'  => $db->real_escape_string($oi['item_name']),
                    'qty'        => $wt,
                    'uom'        => $db->real_escape_string($oi['uom'] ?? 'MT'),
                    'unit_price' => (float)($oi['unit_price'] ?? 0),
                    'weight'     => $wt,
                    'amount'     => $amt,
                ];
            }
        }
    }
    $total_amount = round($subtotal, 2);

    $po_sql   = $po_id    ? $po_id    : 'NULL';
    $vend_sql = $vendor_id? $vendor_id: 'NULL';
    $sup_sql  = $sup_id   ? $sup_id   : 'NULL';
    $start_sql= $start_dt ? "'$start_dt'" : 'NULL';
    $end_sql  = $end_dt   ? "'$end_dt'"   : 'NULL';

    /* ── For Completed trips the Trip Info fieldset is disabled → fields don't POST.
       Pull missing required values from DB instead. ── */
    if ($id > 0 && $status === 'Completed' && (!$trip_date || !$veh_id || !$drv_id)) {
        $db_trip = $db->query("SELECT trip_date, vehicle_id, driver_id, supervisor_id,
            from_location, to_location, customer_name, customer_camp, customer_address,
            customer_city, customer_state, customer_gstin, uom,
            freight_amount, toll_amount, loading_charges, unloading_charges,
            other_expenses, vendor_id, po_id, company_id,
            lease_agent_id, lease_agent_name, lease_agent_margin_per_mt,
            lease_agent_amount, net_freight_amount,
            lease_agent_misc_deduction, lease_agent_misc_deduction_remarks
            FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc();
        if ($db_trip) {
            if (!$trip_date)  $trip_date  = $db_trip['trip_date'];
            if (!$veh_id)     $veh_id     = (int)$db_trip['vehicle_id'];
            if (!$drv_id)     $drv_id     = (int)$db_trip['driver_id'];
            if (!$sup_id)     $sup_id     = (int)$db_trip['supervisor_id'];
            if (!$from)       $from       = $db_trip['from_location'];
            if (!$to)         $to         = $db_trip['to_location'];
            if (!$cust_name)  $cust_name  = $db_trip['customer_name'];
            if (!$cust_camp)  $cust_camp  = $db_trip['customer_camp'];
            if (!$cust_addr)  $cust_addr  = $db_trip['customer_address'];
            if (!$cust_city)  $cust_city  = $db_trip['customer_city'];
            if (!$cust_state) $cust_state = $db_trip['customer_state'];
            if (!$cust_gst)   $cust_gst   = $db_trip['customer_gstin'];
            if (!$uom)        $uom        = $db_trip['uom'];
            if (!$vendor_id)  $vendor_id  = (int)$db_trip['vendor_id'];
            if (!$po_id)      $po_id      = (int)$db_trip['po_id'];
            if (!$co_id)      $co_id      = (int)$db_trip['company_id'];
            if (!$lease_agent_id) $lease_agent_id = (int)($db_trip['lease_agent_id'] ?? 0);
            if (!$lease_agent_name) $lease_agent_name = (string)($db_trip['lease_agent_name'] ?? '');
            if (!$lease_agent_margin_per_mt) $lease_agent_margin_per_mt = (float)($db_trip['lease_agent_margin_per_mt'] ?? 0);
            if (!$lease_agent_amount) $lease_agent_amount = (float)($db_trip['lease_agent_amount'] ?? 0);
            if (!$net_freight_amount) $net_freight_amount = (float)($db_trip['net_freight_amount'] ?? 0);
            if (!$lease_agent_misc_deduction) $lease_agent_misc_deduction = (float)($db_trip['lease_agent_misc_deduction'] ?? 0);
            if (!$lease_agent_misc_deduction_remarks) $lease_agent_misc_deduction_remarks = (string)($db_trip['lease_agent_misc_deduction_remarks'] ?? '');
            if ($lease_agent_billing_status === 'Pending') $lease_agent_billing_status = (string)($db_trip['lease_agent_billing_status'] ?? 'Pending');
            if ($lease_agent_invoice_no === '') $lease_agent_invoice_no = (string)($db_trip['lease_agent_invoice_no'] ?? '');
            if ($lease_agent_invoice_date === '') $lease_agent_invoice_date = (string)($db_trip['lease_agent_invoice_date'] ?? '');
            if ($lease_agent_billing_remarks === '') $lease_agent_billing_remarks = (string)($db_trip['lease_agent_billing_remarks'] ?? '');
            $po_sql   = $po_id    ? $po_id    : 'NULL';
            $vend_sql = $vendor_id ? $vendor_id : 'NULL';
            $sup_sql  = $sup_id   ? $sup_id   : 'NULL';
        }
    }

    if (!$trip_date || !$veh_id || !$drv_id) {
        showAlert('danger', 'Trip Date, Vehicle and Driver are required.');
        redirect("fleet_trips.php?action=" . ($id > 0 ? "edit&id=$id" : 'add') . '&back=' . urlencode($trip_back_url));
    }

    /* ── Block save if Completed with zero/null Weight ── */
    /* Allow editing existing Completed trips even if recalculated weight is 0
       (e.g. when item_name is blank but weight is being updated via form) */
    if ($status === 'Completed' && $total_weight <= 0 && $id == 0) {
        showAlert('danger', '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Weight (MT) is required when status is Completed.</strong> Please enter the Weight before marking this trip as Completed.');
        redirect("fleet_trips.php?action=add&back=" . urlencode($trip_back_url));
    }

    $lease_agent_billing_model = 'margin_deduction';
    if ($lease_agent_id > 0) {
        $agent_row = $db->query("SELECT agent_name, default_margin_per_mt, COALESCE(billing_model, 'margin_deduction') AS billing_model FROM fleet_lease_agents WHERE id=$lease_agent_id LIMIT 1")->fetch_assoc();
        if ($agent_row) {
            $lease_agent_billing_model = (string)($agent_row['billing_model'] ?? 'margin_deduction');
            if ($lease_agent_name === '') $lease_agent_name = (string)($agent_row['agent_name'] ?? '');
            if ($lease_agent_margin_per_mt <= 0) $lease_agent_margin_per_mt = (float)($agent_row['default_margin_per_mt'] ?? 0);
        }
    }

    if ($lease_agent_id <= 0) {
        $lease_agent_billing_model = 'margin_deduction';
        $lease_agent_name = '';
        $lease_agent_margin_per_mt = 0;
        $lease_agent_amount = 0;
        $net_freight_amount = 0;
        $lease_agent_misc_deduction = 0;
        $lease_agent_misc_deduction_remarks = '';
        $lease_agent_billing_status = 'Pending';
        $lease_agent_invoice_no = '';
        $lease_agent_invoice_date = '';
        $lease_agent_billing_remarks = '';
        $lease_agent_bill_to_company_id = 0;
        $lease_agent_fuel = 0;
        $lease_agent_driver_advance = 0;
        $lease_agent_driver_fooding = 0;
        $lease_agent_toll = 0;
        $lease_agent_misc_expense = 0;
        $lease_agent_profit = 0;
    }

    if ($lease_agent_billing_status === 'Billed') {
        if ($lease_agent_invoice_no === '') {
            showAlert('danger', 'Agent Invoice No is required when marking billing as Billed.');
            redirect("fleet_trips.php?action=" . ($id > 0 ? "edit&id=$id" : 'add') . '&back=' . urlencode($trip_back_url));
        }
        if ($lease_agent_invoice_date === '') {
            showAlert('danger', 'Agent Invoice Date is required when marking billing as Billed.');
            redirect("fleet_trips.php?action=" . ($id > 0 ? "edit&id=$id" : 'add') . '&back=' . urlencode($trip_back_url));
        }
    } else {
        $lease_agent_invoice_no = '';
        $lease_agent_invoice_date = '';
        $lease_agent_billing_remarks = '';
    }

    $gross_freight = $freight > 0 ? $freight : (float)$subtotal;
    if ($gross_freight < 0) $gross_freight = 0;
    if ($lease_agent_id > 0) {
        $la_tax_type = 'None';
        $la_tax_rate_val = 0.0;
        $la_row_calc = $db->query("SELECT tax_type, tax_rate, COALESCE(billing_model, 'margin_deduction') AS billing_model FROM fleet_lease_agents WHERE id=$lease_agent_id LIMIT 1")->fetch_assoc();
        if ($la_row_calc) {
            $lease_agent_billing_model = (string)($la_row_calc['billing_model'] ?? $lease_agent_billing_model);
            $la_tax_type = trim((string)($la_row_calc['tax_type'] ?? 'None'));
            $la_tax_rate_val = (float)($la_row_calc['tax_rate'] ?? 0);
            if ($lease_agent_billing_status !== 'Billed' && $po_id > 0) {
                $po_tax_row = $db->query("SELECT MAX(gst_rate) AS gst_rate FROM fleet_po_items WHERE po_id=$po_id")->fetch_assoc();
                if ($po_tax_row && (float)$po_tax_row['gst_rate'] > 0) {
                    $la_tax_rate_val = (float)$po_tax_row['gst_rate'];
                }
            }
        }
        if ($lease_agent_billing_model === 'mgmt_fee') {
            // Model 2: Fixed Management Fee per MT
            $lease_agent_amount = round($lease_agent_margin_per_mt * $total_weight, 2); // Fleet mgmt fee
            $net_freight_amount = round($gross_freight, 2); // Full freight retained by company
            $base_agent_payable = $lease_agent_amount;
        } else {
            // Model 1: Margin Pass-Through
            $lease_agent_amount = round($lease_agent_margin_per_mt * $total_weight, 2); // Retained margin
            $net_freight_amount = round(max(0, $gross_freight - $lease_agent_amount), 2); // Agent freight
            $base_agent_payable = $net_freight_amount;
        }
        $la_tax_amount = ($la_tax_type !== 'None' && $la_tax_rate_val > 0)
            ? round($base_agent_payable * $la_tax_rate_val / 100, 2) : 0;
        $la_total_payable = max(0, $base_agent_payable + ($la_tax_type === 'GST' ? $la_tax_amount : ($la_tax_type === 'TDS' ? -$la_tax_amount : 0)) - $lease_agent_misc_deduction);
        $lease_agent_expenses = $lease_agent_fuel + $lease_agent_driver_advance + $lease_agent_driver_fooding + $lease_agent_toll + $lease_agent_misc_expense;
        $lease_agent_profit = round($la_total_payable - $lease_agent_expenses, 2);
    } else {
        $lease_agent_billing_model = 'margin_deduction';
        $lease_agent_amount = 0;
        $net_freight_amount = $gross_freight;
        $lease_agent_profit = 0;
    }

    if ($id > 0) {
        // For Completed trips: if weight recalculated as 0 (items had no name),
        // fall back to DB values so we don't overwrite weight/amounts with 0.
        if ($status === 'Completed' && $total_weight <= 0) {
            $ex = $db->query("SELECT total_weight, subtotal FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc();
            $total_weight = (float)($ex['total_weight'] ?? 0);
            $subtotal     = (float)($ex['subtotal']     ?? 0);
            $total_amount = $subtotal;
        }
        $db->query("UPDATE fleet_trips SET
            trip_date='$trip_date', po_id=$po_sql, vendor_id=$vend_sql,
            vehicle_id=$veh_id, driver_id=$drv_id, supervisor_id=$sup_sql,
            from_location='$from', to_location='$to',
            customer_name='$cust_name', customer_camp='$cust_camp', customer_address='$cust_addr',
            customer_city='$cust_city', customer_state='$cust_state', customer_gstin='$cust_gst',
            total_weight=$total_weight, uom='$uom',
            freight_amount=$freight, driver_advance=$advance,
            toll_amount=$toll, loading_charges=$loading, unloading_charges=$unloading,
            other_expenses=$other, subtotal=$subtotal, total_amount=$total_amount,
            lease_agent_id=" . ($lease_agent_id > 0 ? $lease_agent_id : 'NULL') . ",
            lease_agent_name='$lease_agent_name', lease_agent_billing_model='$lease_agent_billing_model',
            lease_agent_margin_per_mt=$lease_agent_margin_per_mt,
            lease_agent_amount=$lease_agent_amount, net_freight_amount=$net_freight_amount,
            lease_agent_misc_deduction=$lease_agent_misc_deduction, lease_agent_misc_deduction_remarks='" . $db->real_escape_string($lease_agent_misc_deduction_remarks) . "',
            lease_agent_billing_status='$lease_agent_billing_status', lease_agent_invoice_no='" . $db->real_escape_string($lease_agent_invoice_no) . "',
            lease_agent_invoice_date=" . ($lease_agent_invoice_date !== '' ? "'" . $db->real_escape_string($lease_agent_invoice_date) . "'" : 'NULL') . ",
            lease_agent_billing_remarks='" . $db->real_escape_string($lease_agent_billing_remarks) . "',
            lease_agent_bill_to_company_id=" . ($lease_agent_bill_to_company_id > 0 ? $lease_agent_bill_to_company_id : 'NULL') . ",
            lease_agent_fuel=$lease_agent_fuel, lease_agent_driver_advance=$lease_agent_driver_advance,
            lease_agent_driver_fooding=$lease_agent_driver_fooding, lease_agent_toll=$lease_agent_toll,
            lease_agent_misc_expense=$lease_agent_misc_expense, lease_agent_profit=$lease_agent_profit,
            mtc_required='$mtc_req', mtc_source='$mtc_src', mtc_item_name='$mtc_item',
            mtc_test_date=$mtc_tdate_sql, mtc_ros_45='$mtc_ros', mtc_moisture='$mtc_moist',
            mtc_loi='$mtc_loi', mtc_fineness='$mtc_fine', mtc_remarks='$mtc_rem',
            company_id=$co_id, status='$status', remarks='$remarks'
            WHERE id=$id");
        $db->query("DELETE FROM fleet_trip_items WHERE trip_id=$id");
    } else {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $db->query("INSERT INTO fleet_trips
            (trip_no,trip_date,po_id,vendor_id,vehicle_id,driver_id,supervisor_id,
             from_location,to_location,customer_name,customer_camp,customer_address,customer_city,
             customer_state,customer_gstin,total_weight,uom,
             freight_amount,driver_advance,toll_amount,loading_charges,
             unloading_charges,other_expenses,subtotal,total_amount,
             lease_agent_id,lease_agent_name,lease_agent_billing_model,lease_agent_margin_per_mt,lease_agent_amount,net_freight_amount,
             lease_agent_misc_deduction,lease_agent_misc_deduction_remarks,
             lease_agent_billing_status,lease_agent_invoice_no,lease_agent_invoice_date,lease_agent_billing_remarks,
             lease_agent_bill_to_company_id,
             lease_agent_fuel,lease_agent_driver_advance,lease_agent_driver_fooding,lease_agent_toll,lease_agent_misc_expense,lease_agent_profit,
             mtc_required,mtc_source,mtc_item_name,mtc_test_date,mtc_ros_45,mtc_moisture,
             mtc_loi,mtc_fineness,mtc_remarks,company_id,status,remarks,created_by)
            VALUES ('$trip_no','$trip_date',$po_sql,$vend_sql,$veh_id,$drv_id,$sup_sql,
            '$from','$to','$cust_name','$cust_camp','$cust_addr','$cust_city','$cust_state','$cust_gst',
            $total_weight,'$uom',
            $freight,$advance,$toll,$loading,$unloading,$other,$subtotal,$total_amount,
            " . ($lease_agent_id > 0 ? $lease_agent_id : 'NULL') . ",'$lease_agent_name','$lease_agent_billing_model',$lease_agent_margin_per_mt,$lease_agent_amount,$net_freight_amount,
            $lease_agent_misc_deduction,'" . $db->real_escape_string($lease_agent_misc_deduction_remarks) . "',
            '$lease_agent_billing_status','" . $db->real_escape_string($lease_agent_invoice_no) . "'," . ($lease_agent_invoice_date !== '' ? "'" . $db->real_escape_string($lease_agent_invoice_date) . "'" : 'NULL') . ",'" . $db->real_escape_string($lease_agent_billing_remarks) . "',
            " . ($lease_agent_bill_to_company_id > 0 ? $lease_agent_bill_to_company_id : 'NULL') . ",
            $lease_agent_fuel,$lease_agent_driver_advance,$lease_agent_driver_fooding,$lease_agent_toll,$lease_agent_misc_expense,$lease_agent_profit,
            '$mtc_req','$mtc_src','$mtc_item',$mtc_tdate_sql,'$mtc_ros','$mtc_moist',
            '$mtc_loi','$mtc_fine','$mtc_rem',$co_id,'$status','$remarks',$uid)");
        $id = $db->insert_id;
    }

    foreach ($valid_items as $row) {
        $iid = $row['item_id'] ? $row['item_id'] : 'NULL';
        $db->query("INSERT INTO fleet_trip_items (trip_id,item_id,item_name,qty,uom,unit_price,weight,amount)
            VALUES ($id,$iid,'{$row['item_name']}',{$row['qty']},'{$row['uom']}',
            {$row['unit_price']},{$row['weight']},{$row['amount']})");
    }

    showAlert('success', $id > 0 ? 'Trip updated.' : 'Trip created.');
    redirect('fleet_trips.php?action=view&id=' . $id . '&back=' . urlencode($trip_back_url));
}

/* ── Data for dropdowns ── */
$vehicles_res = $db->query("SELECT v.id, v.reg_no, v.make, v.model,
    COALESCE(v.default_driver_id, 0) AS default_driver_id,
    COALESCE(d.full_name, '') AS driver_name,
    COALESCE(v.lease_agent_id, 0) AS lease_agent_id,
    COALESCE(NULLIF(TRIM(la.agent_short_name),''), la.agent_name, '') AS lease_agent_name,
    COALESCE(la.default_margin_per_mt, 0) AS lease_agent_margin_per_mt
    FROM fleet_vehicles v
    LEFT JOIN fleet_drivers d ON v.default_driver_id = d.id
    LEFT JOIN fleet_lease_agents la ON v.lease_agent_id = la.id
    WHERE v.status='Active' ORDER BY v.reg_no");
$vehicles = ($vehicles_res && $vehicles_res !== false) ? $vehicles_res->fetch_all(MYSQLI_ASSOC) : [];
$drivers_res = $db->query("SELECT id,full_name,role FROM fleet_drivers WHERE status='Active' ORDER BY full_name");
$drivers = ($drivers_res && $drivers_res !== false) ? $drivers_res->fetch_all(MYSQLI_ASSOC) : [];
$supervisors = array_filter($drivers, fn($d) => in_array($d['role'], ['Supervisor','Driver+Supervisor']));
$vendors_res = $db->query("SELECT id,vendor_name,ship_address,ship_city,ship_state,ship_pincode,ship_gstin,ship_name FROM fleet_customers_master WHERE status='Active' ORDER BY vendor_name");
$vendors = ($vendors_res && $vendors_res !== false) ? $vendors_res->fetch_all(MYSQLI_ASSOC) : [];
$items_list_res = $db->query("SELECT id,item_code,item_name,uom FROM items WHERE status='Active' ORDER BY item_name");
$items_list = ($items_list_res && $items_list_res !== false) ? $items_list_res->fetch_all(MYSQLI_ASSOC) : [];
$sources_list_res = $db->query("SELECT id,source_name FROM source_of_material WHERE status='Active' ORDER BY source_name");
$sources_list = ($sources_list_res && $sources_list_res !== false) ? $sources_list_res->fetch_all(MYSQLI_ASSOC) : [];

$lease_agents_res = $db->query("SELECT id, agent_code, agent_name, agent_short_name, default_margin_per_mt, COALESCE(billing_model, 'margin_deduction') AS billing_model, COALESCE(tax_type, 'None') AS tax_type, COALESCE(tax_rate, 0) AS tax_rate FROM fleet_lease_agents WHERE status='Active' ORDER BY agent_name");
$lease_agents = ($lease_agents_res && $lease_agents_res !== false) ? $lease_agents_res->fetch_all(MYSQLI_ASSOC) : [];
$bill_to_companies = getAllCompanies();
$lease_agent_map = [];
foreach ($lease_agents as $agent) {
    $lease_agent_map[(int)$agent['id']] = [
        'name' => (string)$agent['agent_name'],
        'short_name' => (string)($agent['agent_short_name'] ?? ''),
        'margin_per_mt' => (float)$agent['default_margin_per_mt'],
        'billing_model' => (string)($agent['billing_model'] ?? 'margin_deduction'),
        'tax_type' => (string)($agent['tax_type'] ?? 'None'),
        'tax_rate' => (float)($agent['tax_rate'] ?? 0),
    ];
}
$lease_agent_trip_summary_map = fleetTripLeaseAgentSummaryMap($db);

$po_extra_sql = $current_trip_po_id > 0 ? " OR p.id=$current_trip_po_id" : '';
$pos_res   = $db->query("SELECT p.id, p.po_number, p.vendor_id, p.company_id, p.delivery_address, v.vendor_name
    FROM fleet_purchase_orders p
    LEFT JOIN fleet_customers_master v ON p.vendor_id=v.id
    WHERE (p.status='Approved'$po_extra_sql) ORDER BY p.po_date DESC");
$pos       = ($pos_res && $pos_res !== false) ? $pos_res->fetch_all(MYSQLI_ASSOC) : [];

/* Vehicle → Driver map: use default_driver_id from vehicle master first,
   fall back to most recent trip driver if not set */
$veh_driver_map = [];
// Primary: default driver set in vehicle master
$vd_res = $db->query("SELECT id AS vehicle_id, default_driver_id AS driver_id
    FROM fleet_vehicles WHERE default_driver_id IS NOT NULL AND default_driver_id > 0");
if ($vd_res) while ($vdr = $vd_res->fetch_assoc()) {
    $veh_driver_map[(int)$vdr['vehicle_id']] = (int)$vdr['driver_id'];
}
// Fallback: most recent trip driver for vehicles without a default set
$vd_res2 = $db->query("SELECT t1.vehicle_id, t1.driver_id
    FROM fleet_trips t1
    INNER JOIN (
        SELECT vehicle_id, MAX(id) AS max_id FROM fleet_trips
        WHERE driver_id IS NOT NULL AND driver_id > 0
        GROUP BY vehicle_id
    ) t2 ON t1.vehicle_id=t2.vehicle_id AND t1.id=t2.max_id");
if ($vd_res2) while ($vdr = $vd_res2->fetch_assoc()) {
    // Only add if not already set from vehicle master
    if (!isset($veh_driver_map[(int)$vdr['vehicle_id']])) {
        $veh_driver_map[(int)$vdr['vehicle_id']] = (int)$vdr['driver_id'];
    }
}

/* PO → vendor + delivery address + freight rate + GST rate map */
$po_vendor_map  = [];
$po_company_map = [];
$po_addr_map    = [];
$po_rate_map    = [];
$po_gst_map     = [];
$po_items_map   = [];
foreach ($pos as $p) {
    $po_vendor_map[$p['id']] = $p['vendor_id'];
    $po_company_id = (int)($p['company_id'] ?? 0);
    $po_company_map[$p['id']] = $po_company_id > 0 ? $po_company_id : activeCompanyId();
    $po_addr_map[$p['id']]   = $p['delivery_address'] ?? '';
}
// Get all items per PO for auto-populate and GST rate
$po_items_res = $db->query("SELECT po_id, item_name, uom, unit_price, qty, COALESCE(gst_rate, 0) AS gst_rate FROM fleet_po_items ORDER BY po_id, id");
if ($po_items_res) while ($pr = $po_items_res->fetch_assoc()) {
    $pid = (int)$pr['po_id'];
    if (!isset($po_rate_map[$pid])) $po_rate_map[$pid] = (float)$pr['unit_price'];
    if (!isset($po_gst_map[$pid]) || (float)$pr['gst_rate'] > 0) {
        $po_gst_map[$pid] = (float)$pr['gst_rate'];
    }
    $po_items_map[$pid][] = [
        'item_name'  => $pr['item_name'],
        'uom'        => $pr['uom'],
        'unit_price' => (float)$pr['unit_price'],
        'gst_rate'   => (float)$pr['gst_rate'],
    ];
}

/* Vendor → ship address map for JS */
$vendor_data_map = [];
foreach ($vendors as $v) {
    $addr = trim(implode(', ', array_filter([
        $v['ship_name']    ?? '',
        $v['ship_address'] ?? '',
        $v['ship_city']    ?? '',
        $v['ship_state']   ?? '',
        $v['ship_pincode'] ?? '',
    ])));
    $vendor_data_map[$v['id']] = [
        'name'    => $v['vendor_name'],
        'address' => $addr,
        'city'    => $v['ship_city']   ?? '',
        'state'   => $v['ship_state']  ?? '',
        'gstin'   => $v['ship_gstin']  ?? '',
    ];
}

$toll_rate_map = [];
$vendor_toll_map = [];
$toll_rates_res = $db->query("SELECT vendor_id, to_location, toll_amount FROM fleet_toll_rates
    ORDER BY vendor_id, updated_at DESC, id DESC");
if ($toll_rates_res) {
    while ($tr = $toll_rates_res->fetch_assoc()) {
        $vendor_id = (int)$tr['vendor_id'];
        $key = (int)$tr['vendor_id'] . '|' . mb_strtolower(trim((string)$tr['to_location']));
        $toll_rate_map[$key] = (float)$tr['toll_amount'];
        if (!isset($vendor_toll_map[$vendor_id])) {
            $vendor_toll_map[$vendor_id] = (float)$tr['toll_amount'];
        }
    }
}

$all_companies = getAllCompanies();
$default_company_id = activeCompanyId();
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-signpost-split me-2"></i>Trip Orders';</script>
<?php
function fleetTripDisplayStatus(array $trip): string {
    return normalizeTripWorkflowStatus($trip['status'] ?? 'Planned');
}

$status_colors = ['Planned'=>'secondary','In Transit'=>'warning','Completed'=>'success','Cancelled'=>'danger'];
$billing_colors = ['Pending' => 'warning', 'Billed' => 'primary'];
$can_manage_trip_billing = canDo('fleet_trips','view');

/* ══════════════════════════════════ LIST ══════════════════════════════════ */
if ($action === 'list'):
$_uid = (int)($_SESSION['user_id'] ?? 0);
$_user_filter = $is_lease_agent_user && $current_lease_agent_id > 0
    ? " AND t.lease_agent_id=" . (int)$current_lease_agent_id
    : (canViewAll('trips') ? "" : " AND t.created_by=$_uid");
$trip_view = (($_GET['view'] ?? '') === 'register') ? 'register' : 'ops';
$is_trip_register_view = $trip_view === 'register';
$trip_month = sanitize($_GET['month'] ?? '');
$trip_month_filter = '';
if ($trip_month !== '' && preg_match('/^\d{4}-\d{2}$/', $trip_month)) {
    $trip_month_filter = " AND DATE_FORMAT(t.trip_date,'%Y-%m')='" . $db->real_escape_string($trip_month) . "'";
} else {
    $trip_month = '';
}
$trip_list_scope = $is_trip_register_view
    ? " AND t.status IN ('Completed','Cancelled')"
    : " AND t.status IN ('Planned','In Transit')";
$trips = $db->query("SELECT t.*,
    COALESCE(NULLIF(t.total_weight, 0), (SELECT SUM(ti.weight) FROM fleet_trip_items ti WHERE ti.trip_id=t.id), 0) AS total_weight,
    v.reg_no, d.full_name AS driver_name,
    p.po_number, vn.vendor_name, la.agent_name AS lease_agent_master_name, la.agent_short_name AS lease_agent_master_short_name, co.company_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON t.driver_id=d.id
    LEFT JOIN fleet_purchase_orders p ON t.po_id=p.id
    LEFT JOIN fleet_customers_master vn ON t.vendor_id=vn.id
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
    LEFT JOIN companies co ON t.company_id=co.id
    WHERE 1=1 $_user_filter $trip_list_scope $trip_month_filter
    ORDER BY t.id DESC")->fetch_all(MYSQLI_ASSOC);

// Auto-heal missing or corrupted total_weight for trips with freight and PO rate
foreach ($trips as &$t) {
    $wt = (float)($t['total_weight'] ?? 0);
    if ($wt <= 0.01 && (float)($t['freight_amount'] ?? 0) > 0) {
        $calc_wt = 0;
        if (!empty($t['po_id']) && !empty($po_rate_map[(int)$t['po_id']])) {
            $po_r = (float)$po_rate_map[(int)$t['po_id']];
            if ($po_r > 0 && $po_r < 50000) {
                $calc_wt = round((float)$t['freight_amount'] / $po_r, 3);
            }
        }
        if ($calc_wt > 0.01) {
            $t['total_weight'] = $calc_wt;
            $db->query("UPDATE fleet_trips SET total_weight=$calc_wt WHERE id=" . (int)$t['id'] . " AND (total_weight IS NULL OR total_weight <= 0.01)");
            $db->query("UPDATE fleet_trip_items SET weight=$calc_wt, qty=$calc_wt, amount=" . (float)$t['freight_amount'] . " WHERE trip_id=" . (int)$t['id'] . " AND (weight IS NULL OR weight <= 0.01)");
        }
    }
}
unset($t);

$counts = [];
foreach ($trips as $t) {
    $normalized_status = fleetTripDisplayStatus($t);
    $counts[$normalized_status] = ($counts[$normalized_status] ?? 0) + 1;
}

$group_by = in_array(strtolower($_GET['group_by'] ?? ''), ['vendor', 'company']) ? strtolower($_GET['group_by']) : 'company';
$is_vendor_grouped = ($group_by === 'vendor');

// Group trips by selected view (Company or Vendor)
$grouped = [];
foreach ($trips as $t) {
    if ($is_vendor_grouped) {
        $key = trim((string)($t['vendor_name'] ?? $t['customer_name'] ?? '')) ?: 'Direct / General';
    } else {
        $key = trim((string)($t['company_name'] ?? '')) ?: 'General';
    }
    $grouped[$key][] = $t;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold"><?= $is_trip_register_view ? 'Trip Order Register' : 'Trip Orders' ?></h5>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (isAdmin() || canDo('fleet_trips','view')): ?>
        <a href="?action=list<?= $trip_month !== '' ? '&month='.urlencode($trip_month) : '' ?>&group_by=<?= urlencode($group_by) ?>"
           class="btn btn-sm border-0 text-white fw-bold"
           style="<?= $is_trip_register_view
               ? 'background:#60a5fa;box-shadow:inset 0 0 0 1px #2563eb;'
               : 'background:linear-gradient(135deg,#2563eb,#1d4ed8);box-shadow:0 8px 18px rgba(37,99,235,.28);' ?>">
            <i class="bi bi-list-task me-1"></i>Working List
        </a>
        <?php endif; ?>
        <?php if (isAdmin() || canDo('fleet_trip_register','view')): ?>
        <a href="?action=list&view=register<?= $trip_month !== '' ? '&month='.urlencode($trip_month) : '' ?>&group_by=<?= urlencode($group_by) ?>"
           class="btn btn-sm border-0 text-white fw-bold"
           style="<?= $is_trip_register_view
               ? 'background:linear-gradient(135deg,#2563EB,#1E3A8A);box-shadow:0 8px 18px rgba(37,99,235,.28);'
               : 'background:#DBEAFE;box-shadow:inset 0 0 0 1px #1E3A8A;color:#172554 !important;' ?>">
            <i class="bi bi-journal-text me-1"></i>Trip Order Register
        </a>
        <?php endif; ?>
    <?php if (!$is_trip_register_view && (canDo('fleet_trips','create') || $is_lease_agent_user)): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>New Trip</a>
    <?php endif; ?>
    </div>
</div>

<!-- Status filter bar -->
<div class="card mb-3">
<div class="card-body py-2">
    <div class="d-flex gap-3 flex-wrap align-items-center justify-content-between mb-2">
        <div>
            <label class="form-label form-label-sm mb-1 fw-bold">Month</label>
            <form method="get" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="action" value="list">
                <?php if ($is_trip_register_view): ?><input type="hidden" name="view" value="register"><?php endif; ?>
                <input type="hidden" name="group_by" value="<?= htmlspecialchars($group_by) ?>">
                <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($trip_month) ?>" style="min-width:145px">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                <?php if ($trip_month !== ''): ?>
                <a href="?action=list<?= $is_trip_register_view ? '&view=register' : '' ?>&group_by=<?= urlencode($group_by) ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <div>
            <label class="form-label form-label-sm mb-1 fw-bold d-block">Grouping View</label>
            <div class="btn-group btn-group-sm" role="group" aria-label="Group by selector">
                <a href="?action=list<?= $is_trip_register_view ? '&view=register' : '' ?><?= $trip_month !== '' ? '&month='.urlencode($trip_month) : '' ?>&group_by=company"
                   class="btn <?= !$is_vendor_grouped ? 'btn-dark active fw-bold' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-building me-1"></i>By Company
                </a>
                <a href="?action=list<?= $is_trip_register_view ? '&view=register' : '' ?><?= $trip_month !== '' ? '&month='.urlencode($trip_month) : '' ?>&group_by=vendor"
                   class="btn <?= $is_vendor_grouped ? 'btn-dark active fw-bold' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-person-badge me-1"></i>By Vendor
                </a>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-1 border-top">
        <div class="d-flex gap-1 flex-wrap">
            <button class="btn btn-sm btn-dark trip-filter active" data-status="All" onclick="tripFilter('All',this)">All <span class="badge bg-secondary ms-1"><?= count($trips) ?></span></button>
            <?php
            $trip_status_tabs = $is_trip_register_view
                ? ['Completed', 'Cancelled']
                : ['Planned', 'In Transit'];
            foreach ($trip_status_tabs as $st):
                $sc = $status_colors[$st] ?? 'secondary';
                if (!isset($counts[$st])) continue;
            ?>
            <button class="btn btn-sm btn-outline-<?= $sc ?> trip-filter" data-status="<?= $st ?>" onclick="tripFilter('<?= $st ?>',this)">
                <?= $st ?> <span class="badge bg-<?= $sc ?> ms-1"><?= $counts[$st] ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="d-flex gap-1 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="toggleAllCoGroups(true)" title="Expand All">
                <i class="bi bi-arrows-expand me-1"></i>Expand All
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="toggleAllCoGroups(false)" title="Collapse All">
                <i class="bi bi-arrows-collapse me-1"></i>Collapse All
            </button>
        </div>
    </div>
</div>
</div>

<?php if (empty($trips)): ?>
<div class="card p-5 text-center text-muted mb-3">
    <i class="bi bi-inbox fs-1 mb-2"></i>
    <h6 class="mb-0">No trip orders found<?= $trip_month ? ' for ' . htmlspecialchars($trip_month) : '' ?>.</h6>
</div>
<?php endif; ?>

<!-- Grouped Cards (By Company or By Vendor) -->
<?php $gi = 0; foreach ($grouped as $grp_title => $grp_trips): $gi++;
    $grp_id = 'cogrp_'.$gi;
    $g_wt   = array_sum(array_column($grp_trips, 'total_weight'));
    $g_fr   = array_sum(array_column($grp_trips, 'freight_amount'));
    $g_count = count($grp_trips);
    $header_gradient = $is_vendor_grouped
        ? 'background:linear-gradient(135deg,#0369a1,#0f766e);border-left:4px solid #0c4a6e;'
        : 'background:linear-gradient(135deg,#1E3A8A,#334155);border-left:4px solid #172554;';
    $icon_class = $is_vendor_grouped ? 'bi bi-person-badge' : 'bi bi-building';
?>
<div class="card mb-3 company-group" id="<?= $grp_id ?>">
    <div class="card-header d-flex align-items-center gap-2 py-2 company-group-header"
         style="cursor:pointer;<?= $header_gradient ?>color:#fff"
         onclick="toggleCoGroup('<?= $grp_id ?>')">
        <i class="bi bi-chevron-down text-white" id="chev_<?= $grp_id ?>"></i>
        <i class="<?= $icon_class ?> me-1 text-white"></i>
        <strong class="text-white"><?= htmlspecialchars($grp_title) ?></strong>
        <span class="badge bg-white text-success ms-1"><?= $g_count ?> Trip<?= $g_count>1?'s':''?></span>
        <span class="ms-2" style="font-size:.82rem;color:rgba(255,255,255,.85)">
            <?= number_format($g_wt,2) ?> MT &nbsp;|&nbsp; ₹<?= number_format($g_fr,2) ?>
        </span>
    </div>
    <div class="card-body p-0" id="body_<?= $grp_id ?>">
        <div class="table-responsive">
        <table class="table table-hover mb-0 <?= $is_trip_register_view ? 'trip-register-table' : 'trip-working-table' ?>" style="font-size:.88rem">
        <thead class="table-light"><tr>
            <?php if ($is_trip_register_view): ?><th class="trip-expand-col"></th><?php endif; ?>
            <th>Trip No</th><th>Date</th>
            <th><?= $is_vendor_grouped ? 'Company' : 'Customer' ?></th>
            <th>Vehicle</th>
            <th>Agent</th>
            <th class="text-end">Weight (MT)</th>
            <th class="text-end">Freight</th>
            <?php if ($is_trip_register_view): ?>
            <th>Bill To</th>
            <?php endif; ?>
            <th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($grp_trips as $t):
            $trip_status = fleetTripDisplayStatus($t);
            $sc = $status_colors[$trip_status] ?? 'secondary';
            $billing_status = (($t['billing_status'] ?? 'Pending') === 'Billed') ? 'Billed' : 'Pending';
            $bc = $billing_colors[$billing_status] ?? 'secondary';
            $sales_bill_no = trim((string)($t['sales_bill_no'] ?? ''));
            $sales_bill_date = trim((string)($t['sales_bill_date'] ?? ''));
            $lease_agent_billing_status = (($t['lease_agent_billing_status'] ?? 'Pending') === 'Billed') ? 'Billed' : 'Pending';
            $lease_agent_billing_no = trim((string)($t['lease_agent_invoice_no'] ?? ''));
            $lease_agent_billing_date = trim((string)($t['lease_agent_invoice_date'] ?? ''));
            $row_wt = (float)($t['total_weight'] ?? 0);
        ?>
        <tr data-status="<?= htmlspecialchars($trip_status) ?>" class="trip-row<?= ($trip_status==='Completed' && $row_wt <= 0) ? ' table-warning' : '' ?>">
            <?php if ($is_trip_register_view): ?>
            <td class="trip-expand-cell">
                <button type="button" class="btn btn-action btn-outline-secondary trip-expand-btn" onclick="toggleTripDetails(<?= (int)$t['id'] ?>, this)" title="More Details">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </td>
            <?php endif; ?>
            <td class="trip-no-cell"><strong><?= htmlspecialchars($t['trip_no']) ?></strong></td>
            <td class="trip-date-cell"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
            <td class="trip-customer-cell">
                <?php if ($is_vendor_grouped): ?>
                    <span class="badge bg-light text-dark border"><i class="bi bi-building me-1"></i><?= htmlspecialchars($t['company_name'] ?? 'General') ?></span>
                <?php else: ?>
                    <span class="trip-primary-text" title="<?= htmlspecialchars($t['vendor_name'] ?? $t['customer_name'] ?? '—') ?>"><?= htmlspecialchars($t['vendor_name'] ?? $t['customer_name'] ?? '—') ?></span>
                    <?php if (!empty($t['customer_camp'])): ?>
                        <span class="badge bg-light text-secondary border ms-1" style="font-size:.65rem;vertical-align:middle" title="Camp: <?= htmlspecialchars($t['customer_camp'], ENT_QUOTES) ?>"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($t['customer_camp']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td><span class="badge bg-dark trip-pill"><?= htmlspecialchars($t['reg_no']) ?></span></td>
            <?php
                $tripAgentLabel = trim((string)($t['lease_agent_master_short_name'] ?? ''));
                if ($tripAgentLabel === '') $tripAgentLabel = trim((string)($t['lease_agent_name'] ?? ''));
                if ($tripAgentLabel === '') $tripAgentLabel = trim((string)($t['lease_agent_master_name'] ?? ''));
                if ($tripAgentLabel === '') $tripAgentLabel = '—';
            ?>
            <td class="trip-agent-cell"><span class="badge bg-light text-dark border trip-agent-pill"><?= htmlspecialchars($tripAgentLabel) ?></span></td>
            <td class="text-end trip-metric-cell">
                <?php if ($trip_status==='Completed' && $row_wt <= 0): ?>
                    <span class="badge bg-danger">Missing</span>
                    <?php if (isAdmin()): ?>
                    <button type="button" class="btn btn-action btn-warning ms-1"
                        title="Enter Weight"
                        onclick="openWeightModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['trip_no']) ?>')">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                    <?php endif; ?>
                <?php else: ?>
                    <?= number_format($row_wt, 3) ?> MT
                <?php endif; ?>
            </td>
            <td class="text-end trip-metric-cell">₹<?= number_format((float)$t['freight_amount'], 2) ?></td>
            <?php if ($is_trip_register_view): ?>
            <td class="trip-billto-cell"><?php
                $col_bill_to_id = (int)($t['lease_agent_bill_to_company_id'] ?? 0);
                if ($col_bill_to_id > 0) {
                    $col_bill_to_co = getCompany($col_bill_to_id);
                    $col_bill_to_name = $col_bill_to_co['company_name'] ?? '';
                } else {
                    $col_bill_to_name = '';
                }
                echo $col_bill_to_name ? '<span class="badge bg-info text-dark">'.htmlspecialchars($col_bill_to_name).'</span>' : '<span class="text-muted">—</span>';
            ?></td>
            <?php endif; ?>
            <td class="trip-status-cell">
                <div class="trip-status-wrap">
                    <span class="badge bg-<?= $sc ?> trip-status-badge"><?= htmlspecialchars($trip_status) ?></span>
                    <?php if ($trip_status === 'Completed'): ?>
                        <span class="badge bg-<?= $bc ?> trip-billing-badge trip-billing-<?= strtolower($billing_status) ?>"><?= htmlspecialchars($billing_status) ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td class="trip-actions-cell">
                <div class="trip-actions-wrap">
                <a href="?action=view&id=<?= $t['id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_trips.php') ?>" class="btn btn-action btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
                <?php if (!$is_lease_agent_user && canDo('fleet_trips','update') && $trip_status !== 'Cancelled'): ?>
                <a href="?action=edit&id=<?= $t['id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_trips.php') ?>" class="btn btn-action btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <?php if (!$is_lease_agent_user && $trip_status === 'Completed' && $can_manage_trip_billing && $is_trip_register_view): ?>
                <button type="button"
                        class="btn btn-action btn-outline-secondary"
                        title="Update Sales Billing"
                        data-bs-toggle="modal"
                        data-bs-target="#tripBillingModal"
                        data-trip-id="<?= (int)$t['id'] ?>"
                        data-trip-no="<?= htmlspecialchars($t['trip_no'], ENT_QUOTES) ?>"
                        data-billing-status="<?= htmlspecialchars($billing_status, ENT_QUOTES) ?>"
                        data-sales-bill-no="<?= htmlspecialchars($sales_bill_no, ENT_QUOTES) ?>"
                        data-sales-bill-date="<?= htmlspecialchars($sales_bill_date, ENT_QUOTES) ?>"
                        data-billing-remarks="<?= htmlspecialchars((string)($t['billing_remarks'] ?? ''), ENT_QUOTES) ?>">
                    <i class="bi bi-receipt"></i>
                </button>
                <?php endif; ?>
                <?php if (!$is_lease_agent_user && $trip_status === 'Completed'): ?>
                <a href="?action=docs&id=<?= $t['id'] ?>" class="btn btn-action btn-outline-primary" title="Documents"><i class="bi bi-paperclip"></i></a>
                <?php endif; ?>
                <a href="fleet_trip_challan.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-action btn-outline-success" title="Print"><i class="bi bi-printer"></i></a>
                <a href="export_trip_pdf.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-action btn-outline-danger" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                <?php if (isAdmin() && $trip_status === 'Planned'): ?>
                <a href="?delete=<?= $t['id'] ?>" onclick="return confirm('Delete this trip?')" class="btn btn-action btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></a>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php if ($is_trip_register_view): ?>
        <tr class="trip-detail-row" id="trip_detail_<?= (int)$t['id'] ?>" style="display:none">
            <td colspan="8" class="trip-detail-cell">
                <div class="trip-detail-grid">
                    <div class="trip-detail-item">
                        <span class="trip-detail-label">Weight</span>
                        <span class="trip-detail-value"><?php if ($trip_status==='Completed' && (float)($t['total_weight']??0) <= 0): ?>Missing<?php else: ?><?= number_format((float)($t['total_weight']??0),3) ?> MT<?php endif; ?></span>
                    </div>
                    <div class="trip-detail-item">
                        <span class="trip-detail-label">Freight</span>
                        <span class="trip-detail-value">₹<?= number_format((float)$t['freight_amount'],2) ?></span>
                    </div>
                    <div class="trip-detail-item">
                        <span class="trip-detail-label">Lease Agent A/c</span>
                        <?php
                            $leaseAgentDisplay = trim((string)($t['lease_agent_master_short_name'] ?? ''));
                            if ($leaseAgentDisplay === '') $leaseAgentDisplay = trim((string)($t['lease_agent_name'] ?? ''));
                            if ($leaseAgentDisplay === '') $leaseAgentDisplay = trim((string)($t['lease_agent_master_name'] ?? ''));
                            if ($leaseAgentDisplay === '') $leaseAgentDisplay = '—';
                        ?>
                        <span class="trip-detail-value"><?= htmlspecialchars($leaseAgentDisplay) ?></span>
                        <?php if (!empty($t['lease_agent_margin_per_mt'])): ?><span class="trip-detail-subvalue text-muted">Rate / MT: ₹<?= number_format((float)$t['lease_agent_margin_per_mt'],2) ?></span><?php endif; ?>
                    </div>
                    <?php
                        $list_bill_to_id = (int)($t['lease_agent_bill_to_company_id'] ?? 0);
                        if ($list_bill_to_id > 0) {
                            $list_bill_to_co = getCompany($list_bill_to_id);
                            $list_bill_to_name = $list_bill_to_co['company_name'] ?? '';
                        } else {
                            $list_bill_to_name = '';
                        }
                    ?>
                    <?php if ($list_bill_to_name): ?>
                    <div class="trip-detail-item">
                        <span class="trip-detail-label">Bill To</span>
                        <span class="trip-detail-value"><?= htmlspecialchars($list_bill_to_name) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="trip-detail-item">
                        <span class="trip-detail-label">Total</span>
                        <span class="trip-detail-value text-danger">₹<?= number_format((float)($t['lease_agent_amount'] ?? 0),2) ?></span>
                    </div>
                    <div class="trip-detail-item">
                        <span class="trip-detail-label">Agent Billing</span>
                        <span class="trip-detail-value">
                            <?php if ($trip_status === 'Completed'): ?>
                                <span class="badge bg-<?= $lease_agent_billing_status === 'Billed' ? 'primary' : 'secondary' ?> trip-billing-badge trip-billing-<?= strtolower($lease_agent_billing_status) ?>"><?= $lease_agent_billing_status === 'Billed' ? 'Billed' : 'Not Billed' ?></span>
                                <?php if ($lease_agent_billing_status === 'Billed'): ?>
                                    <?php if ($lease_agent_billing_no !== ''): ?><span class="trip-detail-subvalue">Invoice No: <?= htmlspecialchars($lease_agent_billing_no) ?></span><?php endif; ?>
                                    <?php if ($lease_agent_billing_date !== ''): ?><span class="trip-detail-subvalue text-muted">Date: <?= date('d/m/Y', strtotime($lease_agent_billing_date)) ?></span><?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<style>
.trip-filter.active{box-shadow:0 0 0 2px rgba(30,58,95,.5)}
.company-group-header strong{font-size:.98rem}
.trip-register-table thead th,
.trip-working-table thead th{
    font-size:.74rem;
    text-transform:uppercase;
    letter-spacing:.04em;
    color:#1E3A8A;
    border-bottom:1px solid #dbe3ef;
    padding:.55rem .5rem;
    vertical-align:middle;
    white-space:nowrap;
}
.trip-register-table thead th{
    font-size:.72rem;
    padding:.45rem .45rem;
    text-align:center;
    white-space:nowrap;
}
.trip-register-table tbody td{
    padding:.35rem .45rem;
    font-size:.82rem;
    text-align:center;
    vertical-align:middle;
    white-space:nowrap;
    border-color:#edf2ee;
}
.trip-working-table tbody td{
    padding:.65rem .7rem;
    vertical-align:middle;
    border-color:#edf2ee;
}
.trip-register-table tbody tr:hover,
.trip-working-table tbody tr:hover{background:#f8fbf9}
.trip-register-table .trip-no-cell strong,
.trip-working-table .trip-no-cell strong{
    display:inline-block;
    line-height:1.2;
    color:#122b20;
    white-space:nowrap;
    font-size:.86rem;
}
.trip-date-cell,
.trip-metric-cell{white-space:nowrap}
.trip-expand-col,
.trip-expand-cell{
    width:34px;
    min-width:34px;
    text-align:center;
    padding-left:.25rem !important;
    padding-right:.25rem !important;
}
.trip-expand-btn{
    width:1.5rem !important;
    height:1.5rem !important;
    border-radius:.32rem !important;
    font-size:.7rem;
}
.trip-expand-btn i{transition:transform .15s ease}
.trip-expand-btn.expanded i{transform:rotate(45deg)}
.trip-register-table .trip-customer-cell{
    vertical-align:middle;
    text-align:left;
    padding-left:.55rem !important;
    white-space:nowrap;
    max-width:240px;
    overflow:hidden;
    text-overflow:ellipsis;
}
.trip-register-table .trip-no-cell{white-space:nowrap;width:115px}
.trip-register-table .trip-date-cell{white-space:nowrap;width:85px}
.trip-primary-text{
    display:inline-block;
    vertical-align:middle;
    font-weight:600;
    color:#18261f;
    font-size:.8rem;
    line-height:1.2;
    max-width:220px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.trip-pill{
    font-size:.68rem;
    font-weight:600;
    padding:.24rem .4rem;
    white-space:nowrap;
}
.trip-agent-pill{
    font-size:.7rem;
    padding:.2rem .4rem;
    white-space:nowrap;
}
.trip-billto-cell{
    max-width:200px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.trip-billto-cell .badge{
    max-width:190px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    display:inline-block;
    vertical-align:middle;
}
.trip-status-wrap{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    flex-wrap:nowrap;
    white-space:nowrap;
}
.trip-status-badge,
.trip-billing-badge{
    font-size:.66rem;
    padding:.22rem .42rem;
    border-radius:999px;
    white-space:nowrap;
    display:inline-block;
}
.trip-billing-badge{font-weight:700}
.trip-register-table .trip-actions-cell{
    min-width:175px;
    width:175px;
    white-space:nowrap;
    text-align:center;
}
.trip-register-table .trip-actions-wrap{
    display:inline-flex;
    flex-wrap:nowrap;
    gap:3px;
    justify-content:center;
    align-items:center;
    white-space:nowrap;
}
.trip-register-table .btn-action{
    width:1.62rem;
    height:1.62rem;
    font-size:.74rem;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:.35rem;
    flex-shrink:0;
}
.trip-working-table .btn-action{
    width:1.85rem;
    height:1.85rem;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:.4rem;
}
.trip-detail-row td{
    background:#f8fbf9;
    border-top:0;
    padding:.15rem .55rem .5rem .55rem !important;
}
.trip-detail-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:.5rem;
}
.trip-detail-item{
    background:#fff;
    border:1px solid #e4ece6;
    border-radius:.55rem;
    padding:.42rem .55rem;
    min-height:100%;
}
.trip-detail-label{
    display:block;
    font-size:.66rem;
    text-transform:uppercase;
    letter-spacing:.04em;
    color:#6b7280;
    margin-bottom:.18rem;
}
.trip-detail-value{
    display:flex;
    flex-direction:column;
    gap:.14rem;
    font-size:.8rem;
    font-weight:600;
    color:#1f2937;
    line-height:1.2;
}
.trip-detail-subvalue{
    display:block;
    font-size:.7rem;
    font-weight:500;
    line-height:1.15;
}
.trip-billing-pending { color: #1f2937 !important; }
.trip-billing-billed { color: #fff !important; }
body.dark-mode .trip-register-table thead th,
body.dark-mode .trip-working-table thead th{
    color:#dbeafe !important;
    border-bottom-color:#334155 !important;
}
body.dark-mode .trip-register-table tbody td,
body.dark-mode .trip-working-table tbody td{
    border-color:#334155 !important;
    color:#e5e7eb !important;
}
body.dark-mode .trip-register-table .trip-no-cell strong,
body.dark-mode .trip-working-table .trip-no-cell strong,
body.dark-mode .trip-register-table .trip-po-text,
body.dark-mode .trip-primary-text{
    color:#e5e7eb !important;
}
body.dark-mode .trip-date-cell,
body.dark-mode .trip-metric-cell,
body.dark-mode .trip-register-table .text-muted,
body.dark-mode .trip-working-table .text-muted{
    color:#cbd5e1 !important;
}
body.dark-mode .trip-register-table tbody tr:hover,
body.dark-mode .trip-working-table tbody tr:hover{
    background:#1e293b !important;
}
body.dark-mode .trip-detail-row td{
    background:#111827 !important;
}
body.dark-mode .trip-detail-item{
    background:#1e293b !important;
    border-color:#334155 !important;
}
body.dark-mode .trip-detail-label{
    color:#cbd5e1 !important;
}
body.dark-mode .trip-detail-value,
body.dark-mode .trip-detail-subvalue{
    color:#e5e7eb !important;
}
body.dark-mode .trip-billing-pending {
    background: #f2c94c !important;
    color: #161616 !important;
}
body.dark-mode .trip-billing-billed {
    background: #1f9d55 !important;
    color: #fff !important;
}
body.dark-mode .trip-vnd-header{
    background:#1e293b !important;
    border-left-color:#38bdf8 !important;
    color:#e5e7eb !important;
}
body.dark-mode .trip-vnd-header strong{
    color:#e5e7eb !important;
}
body.dark-mode .trip-vnd-header .text-muted{
    color:#cbd5e1 !important;
}
body.dark-mode .trip-vnd-group{
    border-bottom-color:#334155 !important;
}
@media (max-width: 991.98px){
    .trip-register-table,
    .trip-working-table{table-layout:auto}
    .trip-register-table .trip-po-cell,
    .trip-register-table .trip-customer-cell,
    .trip-actions-cell,
    .trip-billing-cell{min-width:unset}
    .trip-detail-grid{grid-template-columns:1fr}
    .trip-register-table th:nth-child(3),
    .trip-register-table td:nth-child(3),
    .trip-register-table th:nth-child(4),
    .trip-register-table td:nth-child(4),
    .trip-register-table th:nth-child(5),
    .trip-register-table td:nth-child(5){
        min-width:unset;
        width:auto;
        max-width:none;
    }
}
</style>
<?php if ($is_trip_register_view && $can_manage_trip_billing): ?>
<div class="modal fade" id="tripBillingModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
    <form method="post" action="fleet_trips.php?action=list&view=register&id=" onsubmit="this.action='fleet_trips.php?action=list&view=register&id='+document.getElementById('tripBillingId').value;">
        <div class="modal-header bg-dark text-white py-2">
            <h6 class="modal-title fw-bold"><i class="bi bi-receipt-cutoff me-2"></i>Update Sales Billing</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="save_trip_billing" value="1">
            <input type="hidden" id="tripBillingId" value="">
            <div class="small text-muted mb-2">Trip No: <strong id="tripBillingTitle"></strong></div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Billing Status</label>
                <select name="billing_status" id="tripBillingStatus" class="form-select" onchange="toggleTripBillingFields()">
                    <option value="Pending">Pending</option>
                    <option value="Billed">Billed</option>
                </select>
            </div>
            <div id="tripBillingFields">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Sales Bill No <span class="text-danger">*</span></label>
                    <input type="text" name="sales_bill_no" id="tripSalesBillNo" class="form-control" maxlength="80">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Sales Bill Date <span class="text-danger">*</span></label>
                    <input type="date" name="sales_bill_date" id="tripSalesBillDate" class="form-control">
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label fw-semibold">Remarks</label>
                <textarea name="billing_remarks" id="tripBillingRemarks" class="form-control" rows="2" maxlength="255"></textarea>
            </div>
        </div>
        <div class="modal-footer py-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-dark btn-sm"><i class="bi bi-check2-circle me-1"></i>Save Billing</button>
        </div>
    </form>
</div>
</div>
</div>
<?php endif; ?>
<script>
function tripFilter(status, btn) {
    document.querySelectorAll('.trip-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.trip-detail-row').forEach(function(r){ r.style.display = 'none'; });
    document.querySelectorAll('.trip-expand-btn').forEach(function(b){ b.classList.remove('expanded'); });
    document.querySelectorAll('.trip-row').forEach(r => {
        r.style.display = (status === 'All' || r.dataset.status === status) ? '' : 'none';
    });
    // Hide empty vendor groups
    document.querySelectorAll('.trip-vnd-group').forEach(function(vgrp) {
        var visible = Array.from(vgrp.querySelectorAll('.trip-row')).some(r => r.style.display !== 'none');
        vgrp.style.display = visible ? '' : 'none';
    });
    // Hide empty company groups
    document.querySelectorAll('.company-group').forEach(function(grp) {
        var visible = Array.from(grp.querySelectorAll('.trip-row')).some(r => r.style.display !== 'none');
        grp.style.display = visible ? '' : 'none';
    });
}
function toggleCoGroup(id) {
    var body = document.getElementById('body_' + id);
    var chev = document.getElementById('chev_' + id);
    if (!body) return;
    var isHidden = (body.style.display === 'none');
    body.style.display = isHidden ? '' : 'none';
    if (chev) {
        chev.className = isHidden ? 'bi bi-chevron-down text-white' : 'bi bi-chevron-right text-white';
    }
}
function toggleVndGroup(id) {
    var body = document.getElementById('body_' + id);
    var chev = document.getElementById('chev_' + id);
    if (!body) return;
    var isHidden = (body.style.display === 'none');
    body.style.display = isHidden ? '' : 'none';
    if (chev) {
        chev.className = isHidden ? 'bi bi-chevron-down text-secondary' : 'bi bi-chevron-right text-secondary';
    }
}
function toggleAllCoGroups(expand) {
    document.querySelectorAll('.company-group').forEach(function(grp) {
        var id = grp.id;
        var body = document.getElementById('body_' + id);
        var chev = document.getElementById('chev_' + id);
        if (body) {
            body.style.display = expand ? '' : 'none';
        }
        if (chev) {
            chev.className = expand ? 'bi bi-chevron-down text-white' : 'bi bi-chevron-right text-white';
        }
    });
    document.querySelectorAll('.trip-vnd-group').forEach(function(vgrp) {
        var id = vgrp.id;
        var body = document.getElementById('body_' + id);
        var chev = document.getElementById('chev_' + id);
        if (body) {
            body.style.display = expand ? '' : 'none';
        }
        if (chev) {
            chev.className = expand ? 'bi bi-chevron-down text-secondary' : 'bi bi-chevron-right text-secondary';
        }
    });
}
function toggleTripDetails(id, btn) {
    var row = document.getElementById('trip_detail_' + id);
    if (!row) return;
    var show = row.style.display === 'none' || row.style.display === '';
    row.style.display = show ? 'table-row' : 'none';
    if (btn) btn.classList.toggle('expanded', show);
}

var tripBillingModalEl = document.getElementById('tripBillingModal');
if (tripBillingModalEl) {
    tripBillingModalEl.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) return;
        document.getElementById('tripBillingId').value = button.getAttribute('data-trip-id') || '';
        document.getElementById('tripBillingTitle').textContent = button.getAttribute('data-trip-no') || '';
        document.getElementById('tripBillingStatus').value = button.getAttribute('data-billing-status') || 'Pending';
        document.getElementById('tripSalesBillNo').value = button.getAttribute('data-sales-bill-no') || '';
        document.getElementById('tripSalesBillDate').value = button.getAttribute('data-sales-bill-date') || '';
        document.getElementById('tripBillingRemarks').value = button.getAttribute('data-billing-remarks') || '';
        toggleTripBillingFields();
    });
}
function toggleTripBillingFields() {
    var statusEl = document.getElementById('tripBillingStatus');
    if (!statusEl) return;
    var status = statusEl.value;
    var wrap = document.getElementById('tripBillingFields');
    if (!wrap) return;
    wrap.style.display = status === 'Billed' ? '' : 'none';
}

<?php if (isAdmin()): ?>
var _weightTripId = 0;
function openWeightModal(tripId, tripNo) {
    _weightTripId = tripId;
    document.getElementById('weightModalTripNo').textContent = tripNo;
    document.getElementById('weightInput').value = '';
    document.getElementById('weightSaveMsg').style.display = 'none';
    var modal = new bootstrap.Modal(document.getElementById('weightModal'));
    modal.show();
    setTimeout(function(){ document.getElementById('weightInput').focus(); }, 400);
}
function saveWeight() {
    var wt = parseFloat(document.getElementById('weightInput').value);
    var msg = document.getElementById('weightSaveMsg');
    if (!wt || wt <= 0) {
        msg.style.display = 'block';
        msg.innerHTML = '<div class="alert alert-danger py-2 mb-0">Weight must be greater than 0.</div>';
        return;
    }
    var btn = document.getElementById('weightSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    var fd = new FormData();
    fd.append('trip_id', _weightTripId);
    fd.append('total_weight', wt);
    fetch('fix_weight.php?ajax=update_weight', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Weight';
            if (d.success) {
                msg.style.display = 'block';
                msg.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle me-1"></i>Weight saved. Reloading...</div>';
                setTimeout(function(){ location.reload(); }, 1200);
            } else {
                msg.style.display = 'block';
                msg.innerHTML = '<div class="alert alert-danger py-2 mb-0">' + (d.error || 'Save failed.') + '</div>';
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Weight';
            msg.style.display = 'block';
            msg.innerHTML = '<div class="alert alert-danger py-2 mb-0">Network error. Try again.</div>';
        });
}
<?php endif; ?>
</script>

<?php if (isAdmin()): ?>
<!-- Admin: Fix Weight Modal -->
<div class="modal fade" id="weightModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content">
    <div class="modal-header bg-warning py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Enter Weight (MT)</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <p class="mb-2 text-muted small">Trip: <strong id="weightModalTripNo"></strong></p>
        <label class="form-label fw-semibold">Weight (MT) <span class="text-danger">*</span></label>
        <div class="input-group">
            <input type="number" id="weightInput" class="form-control" step="0.001" min="0.001"
                   placeholder="e.g. 24.500"
                   onkeydown="if(event.key==='Enter') saveWeight()">
            <span class="input-group-text">MT</span>
        </div>
        <div id="weightSaveMsg" class="mt-2" style="display:none"></div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning btn-sm" id="weightSaveBtn" onclick="saveWeight()">
            <i class="bi bi-check-lg me-1"></i>Save Weight
        </button>
    </div>
</div>
</div>
</div>
<?php endif; ?>

<?php

/* ══════════════════════════════════ VIEW ══════════════════════════════════ */
elseif ($action === 'view' && $id > 0):
$t = $db->query("SELECT t.*, v.reg_no, v.make, v.model,
    d.full_name AS driver_name, d.phone AS driver_phone,
    s.full_name AS supervisor_name,
    p.po_number, vn.vendor_name,
    la.agent_name AS lease_agent_master_name,
    la.agent_short_name AS lease_agent_master_short_name,
    COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') AS lease_agent_billing_model,
    la.tax_type AS lease_agent_tax_type,
    CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END AS lease_agent_tax_rate,
    btc.company_name AS lease_agent_bill_to_company_name,
    co.company_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON t.driver_id=d.id
    LEFT JOIN fleet_drivers s ON t.supervisor_id=s.id
    LEFT JOIN fleet_purchase_orders p ON t.po_id=p.id
    LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
    LEFT JOIN fleet_customers_master vn ON t.vendor_id=vn.id
    LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
    LEFT JOIN companies btc ON t.lease_agent_bill_to_company_id=btc.id
    LEFT JOIN companies co ON t.company_id=co.id
    WHERE t.id=$id $tripLeaseScopeSql LIMIT 1")->fetch_assoc();
if (!$t) { echo '<div class="alert alert-danger">Trip not found.</div>'; include '../includes/footer.php'; exit; }
$trip_items = $db->query("SELECT ti.*, i.item_code FROM fleet_trip_items ti LEFT JOIN items i ON ti.item_id=i.id WHERE ti.trip_id=$id")->fetch_all(MYSQLI_ASSOC);

// If trip_items is empty in View mode, build from PO or header so it's never missing
if (empty($trip_items)) {
    if (!empty($t['po_id']) && !empty($po_items_map[(int)$t['po_id']])) {
        $po_items_rows = $po_items_map[(int)$t['po_id']];
        $trip_wt = (float)($t['total_weight'] ?? 0);
        $cnt = count($po_items_rows);
        foreach ($po_items_rows as $idx => $pi) {
            $row_wt = ($cnt === 1) ? $trip_wt : round($trip_wt / $cnt, 3);
            $up = (float)($pi['unit_price'] ?? 0);
            $trip_items[] = [
                'item_id'    => 0,
                'item_name'  => $pi['item_name'] ?: 'Fly Ash',
                'uom'        => $pi['uom'] ?: ($t['uom'] ?? 'MT'),
                'weight'     => $row_wt,
                'unit_price' => $up,
                'amount'     => round($row_wt * $up, 2),
                'qty'        => $row_wt,
            ];
        }
    }
}

/* ── Auto-fix: if total_weight <= 0.01 or all item weights <= 0.01 ── */
$total_wt_trip = (float)($t['total_weight'] ?? 0);
$v_poid = (int)($t['po_id'] ?? 0);
$v_po_rate = (float)($po_rate_map[$v_poid] ?? 0);
$v_frt = (float)($t['freight_amount'] ?? 0);

if ($total_wt_trip <= 0.01 && $v_frt > 0 && $v_po_rate > 0 && $v_po_rate < 50000) {
    $total_wt_trip = round($v_frt / $v_po_rate, 3);
    $t['total_weight'] = $total_wt_trip;
    $db->query("UPDATE fleet_trips SET total_weight=$total_wt_trip WHERE id=$id AND (total_weight IS NULL OR total_weight <= 0.01)");
}
if ($total_wt_trip <= 0 && !empty($trip_items)) {
    $sum_items_wt = (float)array_sum(array_column($trip_items, 'weight'));
    if ($sum_items_wt > 0.01) {
        $total_wt_trip = $sum_items_wt;
        $t['total_weight'] = $sum_items_wt;
    }
}
if ($total_wt_trip > 0 && count($trip_items) > 0) {
    $all_zero_or_tiny = array_sum(array_column($trip_items, 'weight')) <= 0.01;
    if ($all_zero_or_tiny) {
        $cnt         = count($trip_items);
        $per_wt      = round($total_wt_trip / $cnt, 3);
        $remaining   = $total_wt_trip;
        foreach ($trip_items as $idx => $ti) {
            $row_wt    = ($idx === $cnt - 1) ? round($remaining, 3) : $per_wt;
            $remaining = round($remaining - $per_wt, 3);
            $price     = (float)$ti['unit_price'];
            if ($price <= 0 || $price > 50000) $price = $v_po_rate;
            $amount    = round($row_wt * $price, 2);
            $iid       = (int)$ti['id'];
            $db->query("UPDATE fleet_trip_items SET weight=$row_wt, qty=$row_wt, unit_price=$price, amount=$amount WHERE id=$iid");
        }
        // Reload items after fix
        $trip_items = $db->query("SELECT ti.*, i.item_code FROM fleet_trip_items ti LEFT JOIN items i ON ti.item_id=i.id WHERE ti.trip_id=$id")->fetch_all(MYSQLI_ASSOC);
        $new_subtotal = round(array_sum(array_column($trip_items, 'amount')), 2);
        if ($new_subtotal > 0) {
            $db->query("UPDATE fleet_trips SET subtotal=$new_subtotal, total_amount=$new_subtotal WHERE id=$id");
        }
    }
}

$trip_status = fleetTripDisplayStatus($t);
$sc  = $status_colors[$trip_status] ?? 'secondary';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Trip: <?= htmlspecialchars($t['trip_no']) ?>
        <span class="badge bg-<?= $sc ?> ms-2"><?= htmlspecialchars($trip_status) ?></span>
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (!$is_lease_agent_user && $trip_status === 'Planned'): ?>
        <a href="?setstatus=In+Transit&id=<?= $id ?>&back=<?= urlencode($trip_back_url) ?>" class="btn btn-warning btn-sm" onclick="return confirm('Start trip?')"><i class="bi bi-truck me-1"></i>Start Trip</a>
        <?php elseif (!$is_lease_agent_user && $trip_status === 'In Transit'): ?>
        <a href="?setstatus=Completed&id=<?= $id ?>&back=<?= urlencode($trip_back_url) ?>" class="btn btn-success btn-sm" onclick="return confirm('Mark as Completed?')"><i class="bi bi-check-circle me-1"></i>Complete Trip</a>
        <?php endif; ?>
        <a href="fleet_trip_challan.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
        <a href="export_trip_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        <?php if (!$is_lease_agent_user && canDo('fleet_trips','update') && $trip_status !== 'Cancelled'): ?>
        <a href="?action=edit&id=<?= $id ?>&back=<?= urlencode($trip_back_url) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <?php endif; ?>
        <?php if (!$is_lease_agent_user && $trip_status === 'Completed'): ?>
        <a href="?action=docs&id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-paperclip me-1"></i>Manage Documents</a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($trip_back_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>
<div class="row g-3">
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Trip Details</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-bordered mb-0" style="font-size:.85rem">
<thead class="table-light"><tr>
    <?php if (count($all_companies)>1): ?><th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Company</th><?php endif; ?>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Trip No</th>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Date</th>
    <?php if ($t['po_number']): ?><th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">PO Ref</th><?php endif; ?>
    <?php if ($t['vendor_name']): ?><th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Customer</th><?php endif; ?>
    <?php if (!empty($t['customer_camp'])): ?><th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Camp / Site / Unit</th><?php endif; ?>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Vehicle</th>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Driver</th>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Route</th>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Advance</th>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Freight</th>
    <th class="px-3 py-2 text-muted fw-semibold" style="white-space:nowrap">Weight</th>
</tr></thead>
<tbody><tr>
    <?php if (count($all_companies)>1): ?><td class="px-3 py-2"><span class="badge bg-primary"><?= htmlspecialchars($t['company_name']??'—') ?></span></td><?php endif; ?>
    <td class="px-3 py-2"><strong><?= htmlspecialchars($t['trip_no']) ?></strong></td>
    <td class="px-3 py-2" style="white-space:nowrap"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
    <?php if ($t['po_number']): ?><td class="px-3 py-2"><span class="badge bg-info text-dark"><?= htmlspecialchars($t['po_number']) ?></span></td><?php endif; ?>
    <?php if ($t['vendor_name']): ?><td class="px-3 py-2"><?= htmlspecialchars($t['vendor_name']) ?></td><?php endif; ?>
    <?php if (!empty($t['customer_camp'])): ?><td class="px-3 py-2"><?= htmlspecialchars($t['customer_camp']) ?></td><?php endif; ?>
    <td class="px-3 py-2"><strong><?= htmlspecialchars($t['reg_no']) ?></strong></td>
    <td class="px-3 py-2"><?= htmlspecialchars($t['driver_name']) ?></td>
    <td class="px-3 py-2"><?= htmlspecialchars($t['from_location']) ?> &rarr; <?= htmlspecialchars($t['to_location']) ?></td>
    <td class="px-3 py-2"><strong class="text-warning">₹<?= number_format($t['driver_advance'],2) ?></strong></td>
    <td class="px-3 py-2"><strong class="text-success">₹<?= number_format($t['freight_amount'],2) ?></strong></td>
    <td class="px-3 py-2"><strong><?= number_format((float)($t['total_weight']??0),3) ?> MT</strong></td>
</tr></tbody>
</table></div></div></div></div>

<?php if (!empty($t['to_location']) || !empty($t['customer_city'])): ?>
<div class="col-12" id="tripViewWeatherWrap"></div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.DMSWeather) {
        window.DMSWeather.load('tripViewWeatherWrap', '<?= addslashes($t['to_location'] ?: ($t['customer_city'] ?? '')) ?>');
    }
});
</script>
<?php endif; ?>

<?php if (!empty($t['lease_agent_id'])): ?>
<?php
    $v_calc = fleetCalculateLeaseAgentTripBreakdown($t, (float)$t['freight_amount'], (float)$t['total_weight']);
    $v_is_mgmt_fee = ($v_calc['billing_model'] === 'mgmt_fee');
?>
<div class="col-12">
<div class="card border-info">
    <div class="card-header text-white" style="background:linear-gradient(135deg,#0f766e,#0e7490)">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <i class="bi bi-cash-coin me-2"></i><strong>Lease Agent Details &amp; Settlement</strong>
                <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($t['lease_agent_master_name'] ?? $t['lease_agent_name'] ?? 'Agent') ?></span>
            </div>
            <span class="badge <?= $v_is_mgmt_fee ? 'bg-primary' : 'bg-light text-dark' ?> fw-semibold">
                <?= $v_is_mgmt_fee ? 'Model 2: Fixed Mgmt Fee' : 'Model 1: Retained Margin' ?>
            </span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:.85rem">
                <thead class="table-light"><tr>
                    <th>Agreement Model</th>
                    <th>Customer Rate</th>
                    <th><?= $v_is_mgmt_fee ? 'Fleet Mgmt Fee / MT' : 'Company Margin / MT' ?></th>
                    <th><?= $v_is_mgmt_fee ? 'Agent Mgmt Fee Rate' : 'Agent Net Rate / MT' ?></th>
                    <th>Weight</th>
                    <th><?= $v_is_mgmt_fee ? 'Total Mgmt Fee' : 'Agent Net Freight' ?></th>
                    <th>Misc Deduction</th>
                    <th><?= htmlspecialchars($v_calc['tax_type']) ?><?= $v_calc['tax_rate'] > 0 ? ' (' . $v_calc['tax_rate'] . '%)' : '' ?></th>
                    <th>Net Settlement</th>
                    <th>Billing Status</th>
                </tr></thead>
                <tbody><tr>
                    <td><span class="badge <?= $v_is_mgmt_fee ? 'bg-primary' : 'bg-secondary' ?>"><?= $v_is_mgmt_fee ? 'Fixed Mgmt Fee' : 'Retained Margin' ?></span></td>
                    <td>₹<?= number_format($v_calc['vendor_gross_rate'], 2) ?> / MT</td>
                    <td>₹<?= number_format($v_calc['margin_per_mt'], 2) ?> / MT</td>
                    <td class="fw-bold <?= $v_is_mgmt_fee ? 'text-primary' : 'text-secondary' ?>">₹<?= number_format($v_calc['agent_rate_per_mt'], 2) ?> / MT</td>
                    <td><?= number_format((float)($t['total_weight'] ?? 0), 3) ?> MT</td>
                    <td class="fw-bold">₹<?= number_format($v_calc['base_payable'], 2) ?></td>
                    <td class="text-danger"><?= $v_calc['misc_deduction'] > 0 ? '− ₹' . number_format($v_calc['misc_deduction'], 2) : '—' ?></td>
                    <td><?= $v_calc['tax_amount'] > 0 ? '₹' . number_format($v_calc['tax_amount'], 2) : '—' ?></td>
                    <td class="fw-bold text-success fs-6">₹<?= number_format($v_calc['final_payable'], 2) ?></td>
                    <td>
                        <span class="badge bg-<?= (($t['lease_agent_billing_status'] ?? 'Pending') === 'Billed') ? 'success' : 'warning text-dark' ?>">
                            <?= htmlspecialchars($t['lease_agent_billing_status'] ?? 'Pending') ?>
                        </span>
                        <?php if (!empty($t['lease_agent_invoice_no'])): ?>
                        <div class="small text-muted mt-1">Inv: <?= htmlspecialchars($t['lease_agent_invoice_no']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr></tbody>
            </table>
        </div>
    </div>
</div>
</div>
<?php endif; ?>

<?php if ($trip_items): ?>
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-list-ul me-2"></i>Items / Materials</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
<thead class="table-light"><tr><th>#</th><th>Item</th><th>UOM</th><th class="text-end">Weight (MT)</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr></thead>
<tbody>
<?php $ri=1; foreach ($trip_items as $ti): ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><?= htmlspecialchars($ti['item_name']) ?></td>
    <td><?= htmlspecialchars($ti['uom']) ?></td>
    <td class="text-end"><?= number_format($ti['weight'],3) ?></td>
    <td class="text-end">₹<?= number_format($ti['unit_price'],2) ?></td>
    <td class="text-end"><strong>₹<?= number_format($ti['amount'],2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light"><tr>
    <td colspan="3" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold"><?= number_format((float)($t['total_weight']??0),3) ?> MT</td>
    <td></td>
    <td class="text-end fw-bold">₹<?= number_format($t['subtotal'],2) ?></td>
</tr></tfoot>
</table>
</div></div></div></div>
<?php endif; ?>

<?php if ($t['mtc_required'] === 'Yes'): ?>
<div class="col-12">
<div class="card border-warning">
    <div class="card-header" style="background:linear-gradient(135deg,#856404,#b8860b);color:#fff">
        <i class="bi bi-patch-check me-2"></i>Material Test Certificate (MTC)
    </div>
    <div class="card-body">
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><small class="text-muted d-block">Source</small><strong><?= htmlspecialchars($t['mtc_source']??'—') ?></strong></div>
        <div class="col-6 col-md-3"><small class="text-muted d-block">Item Name</small><strong><?= htmlspecialchars($t['mtc_item_name']??'—') ?></strong></div>
        <div class="col-6 col-md-2"><small class="text-muted d-block">Test Date</small><?= $t['mtc_test_date'] ? date('d/m/Y',strtotime($t['mtc_test_date'])) : '—' ?></div>
    </div>
    <div class="table-responsive">
    <table class="table table-bordered table-sm mb-0" style="font-size:.88rem">
    <thead class="table-warning"><tr><th style="width:40%">TEST</th><th>RESULTS</th><th>IS 3812 Requirement</th></tr></thead>
    <tbody>
        <tr><td>ROS 45 Micron Sieve</td><td><?= htmlspecialchars($t['mtc_ros_45']??'—') ?>%</td><td>&lt; 34%</td></tr>
        <tr><td>Moisture</td><td><?= htmlspecialchars($t['mtc_moisture']??'—') ?>%</td><td>&lt; 2%</td></tr>
        <tr><td>Loss on Ignition</td><td><?= htmlspecialchars($t['mtc_loi']??'—') ?>%</td><td>&lt; 5%</td></tr>
        <tr><td>Fineness (Blaine)</td><td><?= htmlspecialchars($t['mtc_fineness']??'—') ?> m²/kg</td><td>&gt; 320 m²/kg</td></tr>
    </tbody>
    </table>
    </div>
    <?php if ($t['mtc_remarks']): ?><div class="mt-2"><small class="text-muted">Remarks:</small> <?= htmlspecialchars($t['mtc_remarks']) ?></div><?php endif; ?>
    </div>
</div></div>
<?php endif; ?>

<?php if ($t['remarks']): ?>
<div class="col-12"><div class="card"><div class="card-body"><strong>Remarks:</strong> <?= htmlspecialchars($t['remarks']) ?></div></div></div>
<?php endif; ?>

<?php
/* ── Fuel entries for this trip ── */
$fuel_entries = $db->query("SELECT fl.*, fc.company_name AS fuel_company
    FROM fleet_fuel_log fl
    LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id=fc.id
    WHERE fl.trip_id=$id ORDER BY fl.fuel_date ASC, fl.id ASC")->fetch_all(MYSQLI_ASSOC);
$total_fuel_litres = array_sum(array_column($fuel_entries, 'litres'));
$total_fuel_cost   = array_sum(array_column($fuel_entries, 'amount'));

/* ── Vehicle expenses for this trip ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_expenses'
        AND COLUMN_NAME='trip_id' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE fleet_expenses ADD COLUMN trip_id INT DEFAULT NULL AFTER vehicle_id");
})($db);
$veh_expenses = $db->query("SELECT * FROM fleet_expenses WHERE trip_id=$id ORDER BY expense_date ASC, id ASC")->fetch_all(MYSQLI_ASSOC);
$total_veh_exp = array_sum(array_column($veh_expenses, 'amount'));

/* ── P&L ── */
$freight_income  = (float)($t['freight_amount'] ?? 0);
$driver_advance  = (float)($t['driver_advance']  ?? 0);
$trip_items_pl   = $db->query("SELECT SUM(weight) as tw, SUM(amount) as ta FROM fleet_trip_items WHERE trip_id=$id")->fetch_assoc();
$total_weight_pl = (float)($trip_items_pl['tw'] ?? 0);
$items_total     = (float)($trip_items_pl['ta'] ?? 0);
$rate_income     = $items_total > 0 ? $items_total : $freight_income;
$total_expenses  = $total_fuel_cost + $driver_advance + $total_veh_exp;
$net_profit      = $rate_income - $total_expenses;
?>

<!-- Fuel Entries -->
<div class="col-12"><div class="card">
<div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-droplet-fill me-2 text-warning"></i>Fuel Entries
        <?php if ($total_fuel_litres > 0): ?>
        <span class="badge bg-warning text-dark ms-2"><?= number_format($total_fuel_litres,2) ?> L</span>
        <span class="badge bg-danger ms-1">₹<?= number_format($total_fuel_cost,2) ?></span>
        <?php endif; ?>
    </span>
    <?php if (canDo('fleet_fuel','create')): ?>
    <a href="fleet_fuel.php?action=add&trip=<?= $id ?>&back=<?= $id ?>" class="btn btn-warning btn-sm">
        <i class="bi bi-plus-circle me-1"></i>Add Fuel Entry
    </a>
    <?php endif; ?>
</div>
<?php if ($fuel_entries): ?>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
<thead class="table-warning"><tr>
    <th>Date</th><th>Fuel Company</th>
    <th class="text-end">Litres</th><th class="text-end">Rate/L</th>
    <th class="text-end">Amount</th><th>Mode</th><th>Notes</th>
    <?php if (canDo('fleet_fuel','update') || isAdmin()): ?><th></th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($fuel_entries as $fe): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($fe['fuel_date'])) ?></td>
    <td><?= htmlspecialchars($fe['fuel_company'] ?? '—') ?></td>
    <td class="text-end"><?= number_format($fe['litres'],2) ?> L</td>
    <td class="text-end">₹<?= number_format($fe['rate_per_litre'],2) ?></td>
    <td class="text-end fw-bold">₹<?= number_format($fe['amount'],2) ?></td>
    <td><span class="badge bg-<?= $fe['payment_mode']==='Credit'?'warning text-dark':'success' ?>"><?= $fe['payment_mode'] ?></span></td>
    <td><small class="text-muted"><?= htmlspecialchars($fe['notes']??'') ?></small>
        <?php if (!empty($fe['fuel_bill_path'])): ?>
        <a href="<?= htmlspecialchars(r2_url($fe['fuel_bill_path'])) ?>" target="_blank" class="ms-1" title="View Fuel Bill">
            <i class="bi bi-file-earmark-text text-success"></i>
        </a>
        <?php endif; ?>
    </td>
    <?php if (canDo('fleet_fuel','update') || isAdmin()): ?>
    <td>
        <?php if (canDo('fleet_fuel','update')): ?>
        <a href="fleet_fuel.php?action=edit&id=<?= $fe['id'] ?>&back=<?= $id ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="fleet_fuel.php?delete=<?= $fe['id'] ?>&back=<?= $id ?>" onclick="return confirm('Delete this fuel entry?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light"><tr>
    <td colspan="2" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold"><?= number_format($total_fuel_litres,2) ?> L</td>
    <td></td>
    <td class="text-end fw-bold text-danger">₹<?= number_format($total_fuel_cost,2) ?></td>
    <td colspan="<?= (canDo('fleet_fuel','update') || isAdmin()) ? 3 : 2 ?>"></td>
</tr></tfoot>
</table>
</div></div>
<?php else: ?>
<div class="card-body text-muted text-center py-3">
    <i class="bi bi-droplet me-2"></i>No fuel entries yet.
    <?php if (canDo('fleet_fuel','create')): ?>
    <a href="fleet_fuel.php?action=add&trip=<?= $id ?>&back=<?= $id ?>">Add first entry</a>
    <?php endif; ?>
</div>
<?php endif; ?>
</div></div>

<!-- Vehicle Expenses -->
<div class="col-12"><div class="card">
<div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-tools me-2 text-secondary"></i>Vehicle Expenses
        <?php if ($total_veh_exp > 0): ?>
        <span class="badge bg-danger ms-2">₹<?= number_format($total_veh_exp,2) ?></span>
        <?php endif; ?>
    </span>
    <?php if (canDo('fleet_expenses','create')): ?>
    <a href="fleet_expenses.php?action=add&trip=<?= $id ?>&back=<?= $id ?>" class="btn btn-secondary btn-sm">
        <i class="bi bi-plus-circle me-1"></i>Add Expense
    </a>
    <?php endif; ?>
</div>
<?php if ($veh_expenses): ?>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
<thead class="table-secondary"><tr>
    <th>Date</th><th>Type</th><th>Vendor</th><th>Description</th>
    <th class="text-end">Amount</th><th>Mode</th>
    <?php if (canDo('fleet_expenses','update') || isAdmin()): ?><th></th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($veh_expenses as $ve): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($ve['expense_date'])) ?></td>
    <td><span class="badge bg-secondary"><?= htmlspecialchars($ve['expense_type']) ?></span></td>
    <td><?= htmlspecialchars($ve['vendor_name']??'—') ?></td>
    <td><small class="text-muted"><?= htmlspecialchars($ve['description']??'') ?></small></td>
    <td class="text-end fw-bold">₹<?= number_format($ve['amount'],2) ?></td>
    <td><?= htmlspecialchars($ve['payment_mode']) ?></td>
    <?php if (canDo('fleet_expenses','update') || isAdmin()): ?>
    <td>
        <?php if (canDo('fleet_expenses','update')): ?>
        <a href="fleet_expenses.php?action=edit&id=<?= $ve['id'] ?>&back=<?= $id ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="fleet_expenses.php?delete=<?= $ve['id'] ?>&back=<?= $id ?>" onclick="return confirm('Delete this expense?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light"><tr>
    <td colspan="4" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold text-danger">₹<?= number_format($total_veh_exp,2) ?></td>
    <td colspan="<?= (canDo('fleet_expenses','update') || isAdmin()) ? 2 : 1 ?>"></td>
</tr></tfoot>
</table>
</div></div>
<?php else: ?>
<div class="card-body text-muted text-center py-3">
    <i class="bi bi-tools me-2"></i>No vehicle expenses linked to this trip.
    <?php if (canDo('fleet_expenses','create')): ?>
    <a href="fleet_expenses.php?action=add&trip=<?= $id ?>&back=<?= $id ?>">Add expense</a>
    <?php endif; ?>
</div>
<?php endif; ?>
</div></div>

<?php
/* ── Trip Documents ── */
$view_docs = $db->query("SELECT * FROM fleet_trip_documents WHERE trip_id=$id ORDER BY uploaded_at ASC")->fetch_all(MYSQLI_ASSOC);
if ($view_docs):
?>
<div class="col-12"><div class="card">
<div class="card-header"><i class="bi bi-paperclip me-2"></i>Trip Documents
    <span class="badge bg-primary ms-2"><?= count($view_docs) ?></span>
</div>
<div class="card-body">
    <div class="d-flex flex-wrap gap-3">
    <?php foreach ($view_docs as $doc): ?>
    <a href="<?= htmlspecialchars(r2_url($doc['file_path'])) ?>" target="_blank"
       class="d-flex align-items-center gap-2 text-decoration-none border rounded px-3 py-2"
       style="font-size:.85rem">
        <?php $ext = strtolower(pathinfo($doc['file_path'],PATHINFO_EXTENSION)); ?>
        <i class="bi bi-file-earmark-<?= $ext==='pdf'?'pdf text-danger':'image text-primary' ?> fs-4"></i>
        <div>
            <div class="fw-semibold"><?= htmlspecialchars($doc['doc_name']) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($doc['doc_type']) ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    </div>
</div>
</div></div>
<?php endif; ?>

<!-- Trip P&L Summary -->
<div class="col-12"><div class="card border-success">
<div class="card-header" style="background:linear-gradient(135deg,#1a5632,#27ae60);color:#fff">
    <i class="bi bi-graph-up me-2"></i>Trip P&amp;L Summary
</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
<tbody>
    <tr class="table-success">
        <td class="fw-bold ps-3" style="width:65%">
            Freight Income
            <?php if ($total_weight_pl > 0): ?>
            <small class="text-muted fw-normal">(<?= number_format($total_weight_pl,3) ?> MT)</small>
            <?php endif; ?>
        </td>
        <td class="text-end pe-3 fw-bold text-success fs-6">₹<?= number_format($rate_income,2) ?></td>
    </tr>
    <tr>
        <td class="ps-3 text-muted">Fuel Cost <small>(<?= number_format($total_fuel_litres,2) ?> L)</small></td>
        <td class="text-end pe-3 text-danger">− ₹<?= number_format($total_fuel_cost,2) ?></td>
    </tr>
    <tr>
        <td class="ps-3 text-muted">Driver Advance</td>
        <td class="text-end pe-3 text-danger">− ₹<?= number_format($driver_advance,2) ?></td>
    </tr>
    <tr>
        <td class="ps-3 text-muted">Vehicle Expenses</td>
        <td class="text-end pe-3 text-danger">− ₹<?= number_format($total_veh_exp,2) ?></td>
    </tr>
    <tr class="table-light">
        <td class="ps-3 fw-semibold">Total Expenses</td>
        <td class="text-end pe-3 fw-semibold text-danger">₹<?= number_format($total_expenses,2) ?></td>
    </tr>
    <tr class="<?= $net_profit >= 0 ? 'table-success' : 'table-danger' ?>">
        <td class="ps-3 fw-bold fs-6">Net Profit / Loss</td>
        <td class="text-end pe-3 fw-bold fs-6 <?= $net_profit >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= $net_profit >= 0 ? '' : '− ' ?>₹<?= number_format(abs($net_profit),2) ?>
        </td>
    </tr>
</tbody>
</table>
</div></div>
</div></div>

</div><!-- /row -->

<?php
/* ══════════════════════════════════ ADD/EDIT ══════════════════════════════ */
else:
$t = [];
$trip_items = [];
if ($id > 0) {
    $t = $db->query("SELECT t.*,
        la.agent_name AS lease_agent_master_name,
        la.agent_short_name AS lease_agent_master_short_name,
        COALESCE(t.lease_agent_billing_model, la.billing_model, 'margin_deduction') AS lease_agent_billing_model,
        la.tax_type AS lease_agent_tax_type,
        CASE WHEN COALESCE(t.lease_agent_billing_status, 'Pending') = 'Billed' THEN COALESCE(la.tax_rate, 0) ELSE COALESCE(NULLIF(pi.gst_rate, 0), la.tax_rate, 0) END AS lease_agent_tax_rate,
        co.company_name
        FROM fleet_trips t
        LEFT JOIN fleet_lease_agents la ON t.lease_agent_id=la.id
        LEFT JOIN (SELECT po_id, MAX(gst_rate) AS gst_rate FROM fleet_po_items GROUP BY po_id) pi ON pi.po_id=t.po_id
        LEFT JOIN companies co ON t.company_id=co.id
        WHERE t.id=$id $tripLeaseScopeSql LIMIT 1")->fetch_assoc();
    if (!$t) { echo '<div class="alert alert-danger">Trip not found.</div>'; include '../includes/footer.php'; exit; }
    $trip_items = $db->query("SELECT ti.*, i.item_code FROM fleet_trip_items ti LEFT JOIN items i ON ti.item_id=i.id WHERE ti.trip_id=$id")->fetch_all(MYSQLI_ASSOC);

    // If trip_items is empty in Edit mode, auto-populate from PO items or trip totals
    if (empty($trip_items)) {
        if (!empty($t['po_id']) && !empty($po_items_map[(int)$t['po_id']])) {
            $po_items_rows = $po_items_map[(int)$t['po_id']];
            $trip_wt = (float)($t['total_weight'] ?? 0);
            $cnt = count($po_items_rows);
            foreach ($po_items_rows as $idx => $pi) {
                $row_wt = ($cnt === 1) ? $trip_wt : round($trip_wt / $cnt, 3);
                $up = (float)($pi['unit_price'] ?? 0);
                $trip_items[] = [
                    'item_id'    => 0,
                    'item_name'  => $pi['item_name'] ?: 'Fly Ash',
                    'uom'        => $pi['uom'] ?: ($t['uom'] ?? 'MT'),
                    'weight'     => $row_wt,
                    'unit_price' => $up,
                    'amount'     => round($row_wt * $up, 2),
                    'qty'        => $row_wt,
                ];
            }
        }
    }
}
$is_completed = ($id > 0 && ($t['status'] ?? '') === 'Completed');
$trip_back_url = (!empty($_GET['back']) && strpos($_GET['back'], 'view=register') !== false)
    ? 'fleet_trips.php?view=register'
    : 'fleet_trips.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit Trip Order: <span class="text-primary">' . htmlspecialchars($t['trip_no'] ?? '') . '</span>' : 'New Trip Order' ?></h5>
    <a href="<?= htmlspecialchars($trip_back_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="POST" id="tripForm" enctype="multipart/form-data">
<?php if ($id > 0): ?><input type="hidden" name="trip_no" value="<?= htmlspecialchars($t['trip_no']) ?>"><?php endif; ?>
<div class="row g-3">

<!-- Trip Info -->
<div class="col-12"><div class="card">
<div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-truck me-2"></i>Trip Details<?= $id > 0 ? ' — <strong class="text-primary">' . htmlspecialchars($t['trip_no'] ?? '') . '</strong>' : '' ?></span>
    <?php if ($is_completed): ?><span class="ms-2 small"><i class="bi bi-lock-fill me-1"></i>Locked — Trip Completed (Only MTC is editable)</span><?php endif; ?>
</div>
<fieldset <?= $is_completed ? 'disabled' : '' ?>>
<div class="card-body">
<div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Trip No</label>
        <?php if ($id > 0): ?>
        <input type="text" class="form-control bg-light fw-bold text-primary" readonly
               value="<?= htmlspecialchars($t['trip_no'] ?? '') ?>">
        <?php else: ?>
        <input type="text" class="form-control bg-light fw-bold text-success" readonly
               value="<?= htmlspecialchars(peekNextTripNo($db)) ?>">
        <div class="form-text text-muted" style="font-size:11px"><i class="bi bi-lock-fill me-1"></i>Auto-generated on save</div>
        <?php endif; ?>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Trip Date <span class="text-danger">*</span></label>
        <input type="date" name="trip_date" id="tripDate" class="form-control" required
               value="<?= htmlspecialchars($t['trip_date'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Operating Company <span class="text-danger">*</span></label>
        <?php if ($is_lease_agent_user && $current_lease_agent_id > 0): ?>
        <input type="hidden" name="company_id" value="<?= (int)($t['company_id'] ?? ($lease_agent_user_company_id ?: ($default_company_id ?: 1))) ?>">
        <select class="form-select bg-light" disabled>
            <?php foreach ($all_companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ((int)($t['company_id'] ?? ($lease_agent_user_company_id ?: ($default_company_id ?: 1))) === (int)$c['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['company_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <select name="company_id" id="companySelect" class="form-select" required>
            <?php foreach ($all_companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ((int)($t['company_id'] ?? ($default_company_id ?: 0)) === (int)$c['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['company_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">PO Reference</label>
        <select name="po_id" id="poSelect" class="form-select" onchange="fillFromPO(this.value)">
            <option value="">— Select PO —</option>
            <?php foreach ($pos as $po): ?>
            <option value="<?= $po['id'] ?>" data-company-id="<?= (int)($po['company_id'] ?? 0) ?>" <?= ($t['po_id'] ?? 0) == $po['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($po['po_number']) ?><?= !empty($po['vendor_name']) ? ' (' . htmlspecialchars($po['vendor_name']) . ')' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Customer / Buyer</label>
        <select name="vendor_id" id="vendorSelect" class="form-select" onchange="fillFromVendor(this.value)">
            <option value="">— Select Customer —</option>
            <?php foreach ($vendors as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($t['vendor_id'] ?? 0) == $v['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($v['vendor_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Camp / Site / Unit</label>
        <input type="text" name="customer_camp" id="customerCamp" class="form-control"
               placeholder="e.g. Site 4, Unit 2"
               value="<?= htmlspecialchars($t['customer_camp'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Vehicle <span class="text-danger">*</span></label>
        <select name="vehicle_id" id="vehicleSelect" class="form-select" required onchange="fillVehicle(this)">
            <option value="">— Select Vehicle —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>"
                data-driver="<?= (int)($v['default_driver_id'] ?? ($veh_driver_map[(int)$v['id']] ?? 0)) ?>"
                data-driver-name="<?= htmlspecialchars($v['driver_name'] ?? '') ?>"
                data-lease-agent="<?= (int)($v['lease_agent_id'] ?? 0) ?>"
                data-lease-agent-name="<?= htmlspecialchars($v['lease_agent_name'] ?? '') ?>"
                data-lease-agent-margin="<?= number_format((float)($v['lease_agent_margin_per_mt'] ?? 0), 2, '.', '') ?>"
                <?= ($t['vehicle_id'] ?? 0) == $v['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($v['reg_no']) ?> (<?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Driver</label>
        <select name="driver_id" id="driverSelect" class="form-select" onchange="fillDriver(this)">
            <option value="">— Select Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($t['driver_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone'] ?? '') ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Supervisor</label>
        <select name="supervisor_id" id="supervisorSelect" class="form-select">
            <option value="">— Select Supervisor —</option>
            <?php foreach ($supervisors as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($t['supervisor_id'] ?? 0) == $s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Source of Material (From)</label>
        <div class="input-group">
            <select name="from_location" id="fromLocation" class="form-select" onchange="updateMtcAutoFields()">
                <option value="">— Select Source —</option>
                <?php foreach ($sources_list as $src): ?>
                <option value="<?= htmlspecialchars($src['source_name']) ?>"
                    <?= ($t['from_location'] ?? '') === $src['source_name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($src['source_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-success" title="Add new source"
                onclick="showAddSource()" style="white-space:nowrap">
                <i class="bi bi-plus"></i>
            </button>
        </div>
        <!-- Inline add source (hidden by default) -->
        <div id="addSourceBox" class="mt-1 d-none">
            <div class="input-group input-group-sm">
                <input type="text" id="newSourceName" class="form-control" placeholder="New source name...">
                <button type="button" class="btn btn-success" onclick="saveNewSource()">Save</button>
                <button type="button" class="btn btn-outline-secondary" onclick="hideAddSource()">Cancel</button>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">To Location</label>
        <input type="text" name="to_location" id="toLocation" class="form-control"
               placeholder="Delivery destination"
               value="<?= htmlspecialchars($t['to_location'] ?? '') ?>"
               oninput="onDestinationChange()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Driver Advance (₹)</label>
        <input type="number" step="0.01" min="0" name="driver_advance" id="driverAdvance"
               class="form-control"
               value="<?= htmlspecialchars((string)($t['driver_advance'] ?? '0')) ?>"
               oninput="updateLeaseAgentSettlement()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Total Weight (MT) <span class="text-danger">*</span></label>
        <input type="number" step="0.001" min="0" name="total_weight" id="totalWeight"
               class="form-control fw-bold" required
               value="<?= htmlspecialchars((string)($t['total_weight'] ?? '0')) ?>"
               oninput="onWeightChange()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" id="tripStatus" class="form-select">
            <?php foreach (['Planned', 'In Transit', 'Completed', 'Cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= ($t['status'] ?? 'Planned') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
</div>
</fieldset>
</div></div>

<?php if ($id > 0 && (!empty($t['to_location']) || !empty($t['customer_city']))): ?>
<div class="col-12" id="tripWeatherWrap"></div>
<?php endif; ?>

<!-- Lease Agent Details & Settlement Card -->
<div class="col-12">
<div class="card border-info">
    <div class="card-header text-white" style="background:linear-gradient(135deg,#0f766e,#0e7490)">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <i class="bi bi-cash-coin me-2"></i><strong>Lease Agent Details &amp; Settlement</strong>
                <small class="ms-2 opacity-75">Weight, Rates &amp; Billing Calculation</small>
            </div>
            <span id="leaseAgentModelBadge" class="badge bg-light text-dark fw-semibold">
                <?= (($t['lease_agent_billing_model'] ?? '') === 'mgmt_fee') ? 'Model 2: Fixed Mgmt Fee' : 'Model 1: Retained Margin' ?>
            </span>
        </div>
    </div>
    <div>
    <div class="card-body">
        <?php
            $cur_card_model = (string)($t['lease_agent_billing_model'] ?? '');
            if ($cur_card_model === '' && !empty($t['lease_agent_id']) && isset($lease_agent_map[(int)$t['lease_agent_id']])) {
                $cur_card_model = (string)($lease_agent_map[(int)$t['lease_agent_id']]['billing_model'] ?? 'margin_deduction');
            }
            if ($cur_card_model === '') $cur_card_model = 'margin_deduction';
            $is_cur_mgmt_fee = ($cur_card_model === 'mgmt_fee');

            $c_wt = (float)($t['total_weight'] ?? 0);
            $c_gross = (float)($t['freight_amount'] ?? 0);
            $c_margin = (float)($t['lease_agent_margin_per_mt'] ?? 0);
            $c_vend_rate = $c_wt > 0 ? ($c_gross / $c_wt) : (float)($po_rate_map[$t['po_id'] ?? 0] ?? 0);

            if ($is_cur_mgmt_fee) {
                $c_agent_rate = $c_margin;
                $c_base_payable = (float)($t['lease_agent_amount'] ?? ($c_margin * $c_wt));
                $c_margin_label = 'Fleet Mgmt Fee / MT (₹)';
                $c_rate_label = 'Agent Mgmt Fee Rate';
                $c_payable_label = 'Total Mgmt Fee';
            } else {
                $c_agent_rate = max(0, $c_vend_rate - $c_margin);
                $c_base_payable = (float)($t['net_freight_amount'] ?? max(0, $c_gross - ($c_margin * $c_wt)));
                $c_margin_label = 'Company Margin / MT (₹)';
                $c_rate_label = 'Agent Net Rate / MT';
                $c_payable_label = 'Agent Net Freight';
            }
        ?>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Lease Agent</label>
                <?php if ($is_lease_agent_user && $current_lease_agent_id > 0): ?>
                <?php
                    $currentLeaseAgent = null;
                    foreach ($lease_agents as $agent) {
                        if ((int)$agent['id'] === (int)$current_lease_agent_id) {
                            $currentLeaseAgent = $agent;
                            break;
                        }
                    }
                    $currentLeaseAgentLabel = $currentLeaseAgent
                        ? fleetLeaseAgentDisplayLabel($currentLeaseAgent)
                        : ('Lease Agent #' . (int)$current_lease_agent_id);
                    $currentLeaseAgentMargin = (float)($currentLeaseAgent['default_margin_per_mt'] ?? 0);
                    $currentLeaseAgentModel = (string)($currentLeaseAgent['billing_model'] ?? 'margin_deduction');
                ?>
                <input type="hidden" name="lease_agent_id" value="<?= (int)$current_lease_agent_id ?>">
                <select id="leaseAgentSelect" class="form-select bg-light" disabled>
                    <option value="<?= (int)$current_lease_agent_id ?>" selected
                            data-name="<?= htmlspecialchars($currentLeaseAgentLabel, ENT_QUOTES) ?>"
                            data-margin="<?= number_format($currentLeaseAgentMargin, 2, '.', '') ?>"
                            data-billing-model="<?= htmlspecialchars($currentLeaseAgentModel) ?>">
                        <?= htmlspecialchars($currentLeaseAgentLabel) ?>
                    </option>
                </select>
                <input type="hidden" name="lease_agent_name" value="<?= htmlspecialchars($currentLeaseAgentLabel, ENT_QUOTES) ?>">
                <div class="form-text">This trip will be created under your linked lease agent.</div>
                <?php else: ?>
                <select name="lease_agent_id" id="leaseAgentSelect" class="form-select" onchange="fillLeaseAgent(this)">
                    <option value="">— None —</option>
                    <?php foreach ($lease_agents as $agent): ?>
                    <option value="<?= (int)$agent['id'] ?>"
                        data-name="<?= htmlspecialchars(fleetLeaseAgentDisplayLabel($agent), ENT_QUOTES) ?>"
                        data-margin="<?= number_format((float)$agent['default_margin_per_mt'], 2, '.', '') ?>"
                        data-billing-model="<?= htmlspecialchars($agent['billing_model'] ?? 'margin_deduction') ?>"
                        data-tax-type="<?= htmlspecialchars($agent['tax_type'] ?? 'None') ?>"
                        data-tax-rate="<?= number_format((float)($agent['tax_rate'] ?? 0), 2, '.', '') ?>"
                        <?= (int)($t['lease_agent_id'] ?? 0) === (int)$agent['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(fleetLeaseAgentDisplayLabel($agent)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Agreement Model</label>
                <select name="lease_agent_billing_model" id="leaseAgentBillingModel" class="form-select" onchange="onBillingModelChange()">
                    <option value="margin_deduction" <?= $cur_card_model === 'margin_deduction' ? 'selected' : '' ?>>Model 1: Retained Margin</option>
                    <option value="mgmt_fee" <?= $cur_card_model === 'mgmt_fee' ? 'selected' : '' ?>>Model 2: Fixed Mgmt Fee</option>
                </select>
                <div class="form-text">Billing agreement type</div>
            </div>
            <?php if (!($is_lease_agent_user && $current_lease_agent_id > 0)): ?>
            <div class="col-6 col-md-3">
                <label class="form-label">Bill To Company</label>
                <select name="lease_agent_bill_to_company_id" id="billToCompanySelect" class="form-select">
                    <option value="">— Same as Operating Co. —</option>
                    <?php foreach ($bill_to_companies as $btc): ?>
                    <option value="<?= (int)$btc['id'] ?>" <?= (int)($t['lease_agent_bill_to_company_id'] ?? 0) === (int)$btc['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($btc['company_name']) ?><?= !empty($btc['gstin']) ? ' (' . htmlspecialchars($btc['gstin']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Company the lease agent will bill to</div>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-3">
                <label class="form-label">Weight (MT)</label>
                <input type="text" id="leaseAgentWeightDisplay" class="form-control bg-light fw-bold" readonly
                       value="<?= number_format($c_wt, 3, '.', '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Vendor Rate (Gross)</label>
                <input type="text" id="leaseAgentVendorRateDisplay" class="form-control bg-light fw-bold text-dark" readonly
                       value="₹<?= number_format($c_vend_rate, 2) ?> / MT">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" id="leaseAgentMarginLabel"><?= htmlspecialchars($c_margin_label) ?></label>
                <?php if ($is_lease_agent_user && $current_lease_agent_id > 0): ?>
                    <input type="number" step="0.01" min="0" id="leaseAgentMargin"
                           class="form-control bg-light" readonly
                           value="<?= htmlspecialchars(number_format((float)($currentLeaseAgentMargin ?? 0), 2, '.', '')) ?>">
                    <input type="hidden" name="lease_agent_margin_per_mt" value="<?= htmlspecialchars(number_format((float)($currentLeaseAgentMargin ?? 0), 2, '.', ''), ENT_QUOTES) ?>">
                <?php else: ?>
                    <input type="number" step="0.01" min="0" name="lease_agent_margin_per_mt" id="leaseAgentMargin"
                           class="form-control"
                           value="<?= htmlspecialchars((string)($t['lease_agent_margin_per_mt'] ?? '0')) ?>"
                           oninput="updateLeaseAgentSettlement()">
                <?php endif; ?>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" id="leaseAgentRateDisplayLabel"><?= htmlspecialchars($c_rate_label) ?></label>
                <input type="text" id="leaseAgentRateDisplay" class="form-control bg-light fw-bold text-secondary" readonly
                       value="₹<?= number_format($c_agent_rate, 2) ?> / MT">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" id="leaseAgentBasePayableLabel"><?= htmlspecialchars($c_payable_label) ?></label>
                <input type="text" id="leaseAgentPayableDisplay" class="form-control bg-light fw-bold text-primary" readonly
                       value="₹<?= number_format($c_base_payable, 2) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Misc Deduction (₹)</label>
                <input type="number" step="0.01" min="0" name="lease_agent_misc_deduction" id="leaseAgentMiscDeduction"
                       class="form-control" placeholder="0.00"
                       value="<?= htmlspecialchars((string)($t['lease_agent_misc_deduction'] ?? '0')) ?>"
                       oninput="updateLeaseAgentSettlement()">
                <div class="form-text text-muted" style="font-size:.72rem">Deducted from payable (No GST)</div>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Deduction Remarks</label>
                <input type="text" name="lease_agent_misc_deduction_remarks" id="leaseAgentMiscDeductionRemarks"
                       class="form-control" placeholder="Misc deduction reason" maxlength="255"
                       value="<?= htmlspecialchars((string)($t['lease_agent_misc_deduction_remarks'] ?? '')) ?>">
            </div>
            <?php
                $edit_la_base_payable = $c_base_payable;
                $edit_la_tax_type = (string)($t['lease_agent_tax_type'] ?? 'None');
                $edit_la_tax_rate = (float)($t['lease_agent_tax_rate'] ?? 0);
                $edit_la_tax_amount = ($edit_la_tax_type !== 'None' && $edit_la_tax_rate > 0)
                    ? ($edit_la_base_payable * $edit_la_tax_rate / 100)
                    : 0;
                $edit_la_misc_deduction = (float)($t['lease_agent_misc_deduction'] ?? 0);
                $edit_la_total_payable = $edit_la_base_payable;
                if ($edit_la_tax_type === 'GST') {
                    $edit_la_total_payable += $edit_la_tax_amount;
                } elseif ($edit_la_tax_type === 'TDS') {
                    $edit_la_total_payable -= $edit_la_tax_amount;
                }
                $edit_la_total_payable = max(0, $edit_la_total_payable - $edit_la_misc_deduction);
            ?>
            <div class="col-6 col-md-3">
                <label class="form-label">Tax (GST / TDS)</label>
                <input type="text" id="leaseAgentTaxDisplay" class="form-control bg-light fw-bold text-dark" readonly
                       value="₹<?= number_format($edit_la_tax_amount, 2) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label text-success fw-bold">Settlement Payable</label>
                <input type="text" id="leaseAgentTotalPayableDisplay" class="form-control bg-light fw-bold text-success fs-6" readonly
                       value="₹<?= number_format($edit_la_total_payable, 2) ?>">
            </div>
        </div>

        <!-- Trip Expenses & Agent Profit -->
        <hr class="my-3">
        <h6 class="fw-bold text-dark mb-2"><i class="bi bi-wallet2 me-1"></i>Trip Expenses &amp; Agent Net Profit</h6>
        <div class="row g-3 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label">Fuel (₹)</label>
                <input type="number" step="0.01" min="0" name="lease_agent_fuel" id="leaseAgentFuel"
                       class="form-control" placeholder="0.00"
                       value="<?= htmlspecialchars((string)($t['lease_agent_fuel'] ?? '0')) ?>"
                       oninput="updateLeaseAgentSettlement()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Driver Adv (₹)</label>
                <input type="number" step="0.01" min="0" name="lease_agent_driver_advance" id="leaseAgentDriverAdvance"
                       class="form-control" placeholder="0.00"
                       value="<?= htmlspecialchars((string)($t['lease_agent_driver_advance'] ?? '0')) ?>"
                       oninput="updateLeaseAgentSettlement()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Fooding (₹)</label>
                <input type="number" step="0.01" min="0" name="lease_agent_driver_fooding" id="leaseAgentDriverFooding"
                       class="form-control" placeholder="0.00"
                       value="<?= htmlspecialchars((string)($t['lease_agent_driver_fooding'] ?? '0')) ?>"
                       oninput="updateLeaseAgentSettlement()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Toll (₹)</label>
                <input type="number" step="0.01" min="0" name="lease_agent_toll" id="leaseAgentToll"
                       class="form-control" placeholder="0.00"
                       value="<?= htmlspecialchars((string)($t['lease_agent_toll'] ?? '0')) ?>"
                       oninput="updateLeaseAgentSettlement()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Misc Exp (₹)</label>
                <input type="number" step="0.01" min="0" name="lease_agent_misc_expense" id="leaseAgentMiscExpense"
                       class="form-control" placeholder="0.00"
                       value="<?= htmlspecialchars((string)($t['lease_agent_misc_expense'] ?? '0')) ?>"
                       oninput="updateLeaseAgentSettlement()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold">Total Expenses</label>
                <input type="text" id="leaseAgentExpensesTotalDisplay" class="form-control bg-light fw-bold text-danger" readonly
                       value="₹0.00">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold">Agent Trip Profit</label>
                <input type="text" id="leaseAgentProfitDisplay" class="form-control bg-light fw-bold text-success fs-6" readonly
                       value="₹0.00">
            </div>
        </div>

        <!-- Billing Info -->
        <hr class="my-3">
        <h6 class="fw-bold text-dark mb-2"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Agent Billing Status</h6>
        <div class="row g-3">
            <?php
                $leaseAgentBillingStatus = $t['lease_agent_billing_status'] ?? 'Pending';
                $leaseAgentInvoiceNo = $t['lease_agent_invoice_no'] ?? '';
                $leaseAgentInvoiceDate = $t['lease_agent_invoice_date'] ?? '';
                $leaseAgentBillingRemarks = $t['lease_agent_billing_remarks'] ?? '';
            ?>
            <div class="col-12 col-md-4">
                <label class="form-label">Billing Status</label>
                <select name="lease_agent_billing_status" id="leaseAgentBillingStatus" class="form-select" onchange="toggleLeaseAgentBillingFields()">
                    <option value="Pending" <?= $leaseAgentBillingStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Billed" <?= $leaseAgentBillingStatus === 'Billed' ? 'selected' : '' ?>>Billed</option>
                </select>
            </div>
            <div class="col-12 col-md-4" id="leaseAgentInvoiceNoWrap" style="<?= $leaseAgentBillingStatus === 'Billed' ? '' : 'display:none' ?>">
                <label class="form-label">Invoice No</label>
                <input type="text" name="lease_agent_invoice_no" id="leaseAgentInvoiceNo" class="form-control" maxlength="100" value="<?= htmlspecialchars($leaseAgentInvoiceNo) ?>">
            </div>
            <div class="col-12 col-md-4" id="leaseAgentBillingDateWrap" style="<?= $leaseAgentBillingStatus === 'Billed' ? '' : 'display:none' ?>">
                <label class="form-label">Invoice Date</label>
                <input type="date" name="lease_agent_invoice_date" id="leaseAgentInvoiceDate" class="form-control" value="<?= htmlspecialchars($leaseAgentInvoiceDate) ?>">
            </div>
            <div class="col-12" id="leaseAgentBillingRemarksWrap" style="<?= $leaseAgentBillingStatus === 'Billed' ? '' : 'display:none' ?>">
                <label class="form-label">Billing Remarks</label>
                <input type="text" name="lease_agent_billing_remarks" id="leaseAgentBillingRemarks" class="form-control" maxlength="255" value="<?= htmlspecialchars($leaseAgentBillingRemarks) ?>">
            </div>
        </div>
    </div>
</div>
</div>

<input type="hidden" name="lease_agent_name" id="leaseAgentNameHidden" value="<?= $is_lease_agent_user && $current_lease_agent_id > 0 ? htmlspecialchars($currentLeaseAgentLabel) : htmlspecialchars($t['lease_agent_name'] ?? '') ?>">
<input type="hidden" name="lease_agent_amount" id="leaseAgentAmountHidden" value="<?= htmlspecialchars((string)($t['lease_agent_amount'] ?? 0)) ?>">
<input type="hidden" name="net_freight_amount" id="netFreightAmountHidden" value="<?= htmlspecialchars((string)($t['net_freight_amount'] ?? ((float)($t['freight_amount'] ?? 0) - (float)($t['lease_agent_amount'] ?? 0)))) ?>">

<!-- Consignee -->
<!-- Hidden consignee fields - auto-populated from vendor but not shown -->
<?php if ($is_completed): ?>
<!-- For Completed trips: fieldset is disabled so these fields won't POST — send as hidden -->
<input type="hidden" name="status"     value="Completed">
<input type="hidden" name="trip_date"  value="<?= htmlspecialchars($t['trip_date']??'') ?>">
<input type="hidden" name="vehicle_id" value="<?= (int)($t['vehicle_id']??0) ?>">
<input type="hidden" name="driver_id"  value="<?= (int)($t['driver_id']??0) ?>">
<input type="hidden" name="supervisor_id" value="<?= (int)($t['supervisor_id']??0) ?>">
<input type="hidden" name="vendor_id"  value="<?= (int)($t['vendor_id']??0) ?>">
<input type="hidden" name="po_id"      value="<?= (int)($t['po_id']??0) ?>">
<input type="hidden" name="from_location" value="<?= htmlspecialchars($t['from_location']??'') ?>">
<input type="hidden" name="to_location"   value="<?= htmlspecialchars($t['to_location']??'') ?>">
<input type="hidden" name="customer_camp" value="<?= htmlspecialchars($t['customer_camp']??'') ?>">
<input type="hidden" name="uom"        value="<?= htmlspecialchars($t['uom']??'MT') ?>">
<?php endif; ?>
<input type="hidden" name="customer_name" id="custName" value="<?= htmlspecialchars($t['customer_name']??'') ?>">
<input type="hidden" name="customer_gstin" id="custGst" value="<?= htmlspecialchars($t['customer_gstin']??'') ?>">
<input type="hidden" name="customer_city" id="custCity" value="<?= htmlspecialchars($t['customer_city']??'') ?>">
<input type="hidden" name="customer_state" id="custState" value="<?= htmlspecialchars($t['customer_state']??'') ?>">
<input type="hidden" name="customer_address" id="custAddr" value="<?= htmlspecialchars($t['customer_address']??'') ?>">

<!-- Items -->
<div class="col-12"><div class="card">
<div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2"></i>Items / Materials</span>
    <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
</div>
<fieldset>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0" id="itemsTable">
<thead class="table-light"><tr>
    <th>Item</th><th>UOM</th>
    <th style="width:120px">Weight (MT)</th>
    <th style="width:110px">Rate (₹/MT)</th>
    <th style="width:110px">Amount (₹)</th>
    <th style="width:36px"></th>
</tr></thead>
<tbody id="itemsBody">
<?php if ($trip_items): foreach ($trip_items as $ti):
    $cur_item_id = (int)($ti['item_id'] ?? 0);
    $cur_item_name = trim((string)($ti['item_name'] ?? ''));
    $matched_item = false;
    $ti_wt = (float)($ti['weight'] ?? 0);
    if ($ti_wt <= 0.01 && (float)($t['total_weight'] ?? 0) > 0.01) {
        $ti_wt = (float)$t['total_weight'];
    }
    $ti_pr = (float)($ti['unit_price'] ?? 0);
    if ($ti_pr <= 0 || $ti_pr > 50000) {
        if (!empty($t['po_id']) && !empty($po_rate_map[(int)$t['po_id']])) {
            $ti_pr = (float)$po_rate_map[(int)$t['po_id']];
        } elseif ((float)($t['freight_amount'] ?? 0) > 0 && $ti_wt > 0.01) {
            $ti_pr = round((float)$t['freight_amount'] / $ti_wt, 2);
        }
    }
    if ($ti_wt <= 0.01 && $ti_pr > 0 && (float)($t['freight_amount'] ?? 0) > 0) {
        $ti_wt = round((float)$t['freight_amount'] / $ti_pr, 3);
        $t['total_weight'] = $ti_wt;
    }
    $ti_amt = (float)($ti['amount'] ?? 0);
    if (($ti_amt <= 0 || abs($ti_amt - (float)($t['freight_amount'] ?? 0)) > 1) && $ti_wt > 0 && $ti_pr > 0) {
        $ti_amt = round($ti_wt * $ti_pr, 2);
    }
?>
<tr class="item-row">
    <td>
        <select name="item_id[]" class="form-select form-select-sm" onchange="fillItemUom(this)">
            <option value="">— Select —</option>
            <?php foreach ($items_list as $il):
                $is_selected = ($cur_item_id > 0 && $cur_item_id === (int)$il['id'])
                    || ($cur_item_id === 0 && $cur_item_name !== '' && strcasecmp($cur_item_name, trim($il['item_name'])) === 0);
                if ($is_selected) $matched_item = true;
            ?>
            <option value="<?= $il['id'] ?>" data-name="<?= htmlspecialchars($il['item_name']) ?>" data-uom="<?= $il['uom'] ?>"
                    <?= $is_selected ? 'selected' : '' ?>>
                <?= htmlspecialchars($il['item_name']) ?> (<?= $il['uom'] ?>)
            </option>
            <?php endforeach; ?>
            <?php if (!$matched_item && $cur_item_name !== ''): ?>
            <option value="0" data-name="<?= htmlspecialchars($cur_item_name) ?>" data-uom="<?= htmlspecialchars($ti['uom'] ?? 'MT') ?>" selected>
                <?= htmlspecialchars($cur_item_name) ?> (<?= htmlspecialchars($ti['uom'] ?? 'MT') ?>)
            </option>
            <?php endif; ?>
        </select>
        <input type="hidden" name="item_name[]" class="item-name-hidden" value="<?= htmlspecialchars($cur_item_name ?: ($items_list[0]['item_name'] ?? 'Fly Ash')) ?>">
    </td>
    <td><input type="text" name="uom[]" class="form-control form-control-sm item-uom bg-light" value="<?= htmlspecialchars($ti['uom']??'MT') ?>" readonly></td>
    <td><input type="number" step="0.001" min="0" name="weight[]" class="form-control form-control-sm item-weight" value="<?= $ti_wt > 0 ? htmlspecialchars((string)$ti_wt) : '' ?>" oninput="calcItems()"></td>
    <td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm item-price" value="<?= $ti_pr > 0 ? htmlspecialchars((string)$ti_pr) : '' ?>" oninput="calcItems()"></td>
    <td><input type="number" step="0.01" name="amount[]" class="form-control form-control-sm item-amount bg-light" value="<?= $ti_amt > 0 ? htmlspecialchars((string)$ti_amt) : '0' ?>" readonly></td>
    <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm p-0 px-1" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endforeach; else: ?>
<!-- Sourced from PO (if single item) or blank row -->
<tr class="item-row">
    <td>
        <select name="item_id[]" class="form-select form-select-sm" onchange="fillItemUom(this)">
            <option value="">— Select —</option>
            <?php foreach ($items_list as $il): ?>
            <option value="<?= $il['id'] ?>" data-name="<?= htmlspecialchars($il['item_name']) ?>" data-uom="<?= $il['uom'] ?>">
                <?= htmlspecialchars($il['item_name']) ?> (<?= $il['uom'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="item_name[]" class="item-name-hidden" value="">
    </td>
    <td><input type="text" name="uom[]" class="form-control form-control-sm item-uom bg-light" value="MT" readonly></td>
    <td><input type="number" step="0.001" min="0" name="weight[]" class="form-control form-control-sm item-weight" placeholder="0.000" oninput="calcItems()"></td>
    <td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm item-price" placeholder="0.00" oninput="calcItems()"></td>
    <td><input type="number" step="0.01" name="amount[]" class="form-control form-control-sm item-amount bg-light" value="0" readonly></td>
    <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm p-0 px-1" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endif; ?>
</tbody>
<tfoot class="table-light">
    <tr>
        <td colspan="2" class="text-end fw-bold">Total</td>
        <td class="fw-bold"><span id="itemsTotalWeight">0.000</span> MT</td>
        <td></td>
        <td class="fw-bold text-success">₹<span id="itemsTotal">0.00</span></td>
        <td></td>
    </tr>
</tfoot>
</table>
</div></div></div></div>
</fieldset>

<!-- Hidden financial fields — kept for DB compatibility -->
<input type="hidden" name="freight_amount" id="freightAmount" value="<?= $t['freight_amount']??0 ?>">
<input type="hidden" name="toll_amount" id="tollAmountHidden" value="<?= (float)($t['toll_amount'] ?? 0) ?>">
<input type="hidden" name="loading_charges" value="0">
<input type="hidden" name="unloading_charges" value="0">
<input type="hidden" name="other_expenses" value="0">
<div class="small text-muted mt-1">
    <i class="bi bi-cash-coin me-1"></i>Auto toll from Vendor + To Location.
    <a href="fleet_toll_rates.php" class="text-decoration-none">Manage Toll Rates</a>
</div>

<!-- MTC — matching despatch module style -->
<div class="col-12"><div class="card border-warning">
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
                           <?= ($t['mtc_required']??'No')==='No'?'checked':'' ?> onchange="toggleMTC()">
                    <label class="form-check-label fw-semibold text-secondary" for="mtcNo">No</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mtc_required" id="mtcYes" value="Yes"
                           <?= ($t['mtc_required']??'')==='Yes'?'checked':'' ?> onchange="toggleMTC()">
                    <label class="form-check-label fw-semibold text-success" for="mtcYes">Yes</label>
                </div>
            </div>
        </div>
    </div>
    <div id="mtcDetails" style="display:<?= ($t['mtc_required']??'No')==='Yes'?'block':'none' ?>">
    <hr class="my-3">
    <div class="alert alert-warning py-2 mb-3 d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <span>Fill in test results. These will print as a <strong>Material Test Certificate</strong> attached to the Trip Challan.</span>
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label">Source of Material
                <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">
                    <i class="bi bi-arrow-up-circle me-1"></i>Auto from Trip Info
                </span>
            </label>
            <input type="text" name="mtc_source" id="mtcSource" class="form-control bg-light"
                   readonly tabindex="-1" value="<?= htmlspecialchars($t['mtc_source']??'') ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">Item Name
                <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">
                    <i class="bi bi-arrow-up-circle me-1"></i>Auto from Items
                </span>
            </label>
            <input type="text" name="mtc_item_name" id="mtcItemName" class="form-control bg-light"
                   readonly tabindex="-1" value="<?= htmlspecialchars($t['mtc_item_name']??'') ?>">
        </div>
        <div class="col-12 col-md-2">
            <label class="form-label">Test Date
                <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">
                    <i class="bi bi-arrow-up-circle me-1"></i>= Trip Date
                </span>
            </label>
            <input type="date" name="mtc_test_date" id="mtcTestDate" class="form-control bg-light"
                   readonly tabindex="-1" value="<?= htmlspecialchars(($t['mtc_test_date'] ?? '') ?: ($t['trip_date'] ?? date('Y-m-d'))) ?>">
        </div>
    </div>
    <h6 class="fw-bold mt-3 mb-2 text-warning"><i class="bi bi-table me-1"></i>Test Results</h6>
    <div class="table-responsive">
    <table class="table table-bordered align-middle mb-0" style="font-size:.88rem">
    <thead class="table-warning">
    <tr><th style="width:40%">TEST</th><th style="width:25%">RESULTS (%)</th><th>IS 3812 Requirement</th></tr>
    </thead>
    <tbody>
    <tr>
        <td class="fw-semibold">ROS 45 Micron Sieve</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_ros_45" class="form-control" placeholder="e.g. 28.5" value="<?= htmlspecialchars($t['mtc_ros_45']??'') ?>"><span class="input-group-text">%</span></div></td>
        <td class="text-muted">&lt; 34%</td>
    </tr>
    <tr>
        <td class="fw-semibold">Moisture</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_moisture" class="form-control" placeholder="e.g. 0.8" value="<?= htmlspecialchars($t['mtc_moisture']??'') ?>"><span class="input-group-text">%</span></div></td>
        <td class="text-muted">&lt; 2%</td>
    </tr>
    <tr>
        <td class="fw-semibold">Specific Gravity</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_specific_gravity" class="form-control" placeholder="e.g. 2.15" value="<?= htmlspecialchars($t['mtc_specific_gravity']??'') ?>"></div></td>
        <td class="text-muted">&gt; 1.90</td>
    </tr>
    <tr>
        <td class="fw-semibold">Lime Reactivity</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_lime_reactivity" class="form-control" placeholder="e.g. 4.5" value="<?= htmlspecialchars($t['mtc_lime_reactivity']??'') ?>"><span class="input-group-text">N/mm²</span></div></td>
        <td class="text-muted">&gt; 3.5 N/mm²</td>
    </tr>
    <tr>
        <td class="fw-semibold">Soundness (Autoclave Expansion)</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_soundness" class="form-control" placeholder="e.g. 0.05" value="<?= htmlspecialchars($t['mtc_soundness']??'') ?>"><span class="input-group-text">%</span></div></td>
        <td class="text-muted">&lt; 0.8%</td>
    </tr>
    <tr>
        <td class="fw-semibold">Remarks</td>
        <td colspan="2"><input type="text" name="mtc_remarks" class="form-control form-control-sm" placeholder="e.g. Conforms to IS 3812 Part 1 Grade 1" value="<?= htmlspecialchars($t['mtc_remarks']??'') ?>"></td>
    </tr>
    </tbody>
    </table>
    </div>
    </div>
    </div>
</div>
</div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">General Remarks</label>
    <input type="text" name="remarks" class="form-control" placeholder="Optional notes for this trip" value="<?= htmlspecialchars($t['remarks']??'') ?>">
</div></div></div>

<div class="col-12 text-end mt-4">
    <a href="<?= htmlspecialchars($trip_back_url) ?>" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" name="save_trip" value="1" class="btn btn-success px-4 fw-bold">
        <i class="bi bi-check-lg me-1"></i><?= $id > 0 ? 'Update Trip' : 'Save Trip' ?>
    </button>
</div>

</div><!-- /row -->
</form>

<script>
var poVendorMap   = <?= json_encode($po_vendor_map) ?>;
var poCompanyMap  = <?= json_encode($po_company_map) ?>;
var poAddrMap     = <?= json_encode($po_addr_map) ?>;
var poRateMap     = <?= json_encode($po_rate_map) ?>;
var poGstMap      = <?= json_encode($po_gst_map) ?>;
var poItemsMap    = <?= json_encode($po_items_map) ?>;
var vendorDataMap = <?= json_encode($vendor_data_map) ?>;
var vehDriverMap  = <?= json_encode($veh_driver_map) ?>;
var tollRateMap   = <?= json_encode($toll_rate_map) ?>;
var vendorTollMap = <?= json_encode($vendor_toll_map) ?>;
var itemsMasterList = <?= json_encode($items_list) ?>;
window._leaseAgentMap = <?= json_encode($lease_agent_map) ?>;
window._poFreightRate = 0;
window._poGstRate = 0;

/* ── PO selected → auto-fill company, vendor, delivery address, freight rate & items ── */
function fillFromPO(poId) {
    poId = parseInt(poId) || 0;
    if (!poId) return;

    // Auto-fill company
    var compId = poCompanyMap[poId] || 0;
    var compSel = document.getElementById('companySelect');
    if (compId && compSel && !compSel.disabled) {
        compSel.value = compId;
    }

    // Auto-fill vendor
    var vid = poVendorMap[poId] || 0;
    if (vid) fillFromVendor(vid);

    // Auto-fill delivery address from PO
    var addr = poAddrMap[poId] || '';
    if (addr) {
        var toLocEl = document.getElementById('toLocation');
        if (toLocEl) {
            toLocEl.value = addr;
            onDestinationChange();
        }
    }

    // Capture PO Freight Rate & GST
    var poRate = parseFloat(poRateMap[poId]) || 0;
    window._poFreightRate = poRate;
    var poGst = parseFloat(poGstMap[poId]) || 0;
    window._poGstRate = poGst;

    // Auto-populate items table if empty or only 1 empty row
    var itemsRows = poItemsMap[poId] || [];
    var tbody = document.getElementById('itemsBody');
    if (itemsRows.length > 0 && tbody) {
        var currentRows = tbody.querySelectorAll('.item-row');
        var isSingleEmpty = (currentRows.length === 1 && (!currentRows[0].querySelector('.item-weight').value || parseFloat(currentRows[0].querySelector('.item-weight').value) === 0));
        if (currentRows.length === 0 || isSingleEmpty) {
            tbody.innerHTML = '';
            var tripWt = parseFloat(document.getElementById('totalWeight').value) || 0;
            var perWt = itemsRows.length === 1 ? tripWt : (tripWt > 0 ? (tripWt / itemsRows.length) : 0);
            itemsRows.forEach(function(pi) {
                var newTr = document.createElement('tr');
                newTr.className = 'item-row';
                var optsHtml = '<option value="">— Select —</option>';
                var matched = false;
                itemsMasterList.forEach(function(il) {
                    var sel = (il.item_name.toLowerCase() === (pi.item_name || '').toLowerCase());
                    if (sel) matched = true;
                    optsHtml += '<option value="' + il.id + '" data-name="' + escapeHtml(il.item_name) + '" data-uom="' + escapeHtml(il.uom) + '"' + (sel ? ' selected' : '') + '>' + escapeHtml(il.item_name) + ' (' + escapeHtml(il.uom) + ')</option>';
                });
                if (!matched && pi.item_name) {
                    optsHtml += '<option value="0" data-name="' + escapeHtml(pi.item_name) + '" data-uom="' + escapeHtml(pi.uom || 'MT') + '" selected>' + escapeHtml(pi.item_name) + ' (' + escapeHtml(pi.uom || 'MT') + ')</option>';
                }
                newTr.innerHTML = '<td><select name="item_id[]" class="form-select form-select-sm" onchange="fillItemUom(this)">' + optsHtml + '</select>' +
                    '<input type="hidden" name="item_name[]" class="item-name-hidden" value="' + escapeHtml(pi.item_name || 'Fly Ash') + '"></td>' +
                    '<td><input type="text" name="uom[]" class="form-control form-control-sm item-uom bg-light" value="' + escapeHtml(pi.uom || 'MT') + '" readonly></td>' +
                    '<td><input type="number" step="0.001" min="0" name="weight[]" class="form-control form-control-sm item-weight" value="' + (perWt > 0 ? perWt.toFixed(3) : '') + '" placeholder="0.000" oninput="calcItems()"></td>' +
                    '<td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm item-price" value="' + (parseFloat(pi.unit_price) > 0 ? parseFloat(pi.unit_price).toFixed(2) : (poRate > 0 ? poRate.toFixed(2) : '')) + '" placeholder="0.00" oninput="calcItems()"></td>' +
                    '<td><input type="number" step="0.01" name="amount[]" class="form-control form-control-sm item-amount bg-light" value="0" readonly></td>' +
                    '<td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm p-0 px-1" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>';
                tbody.appendChild(newTr);
            });
            calcItems();
        }
    }
    updateLeaseAgentSettlement();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Customer selected → auto-fill consignee fields + toll rate ── */
function fillFromVendor(vid) {
    vid = parseInt(vid) || 0;
    var sel = document.getElementById('vendorSelect');
    if (sel && vid) sel.value = vid;
    var data = vendorDataMap[vid] || {};
    document.getElementById('custName').value  = data.name || '';
    document.getElementById('custGst').value   = data.gstin || '';
    document.getElementById('custCity').value  = data.city || '';
    document.getElementById('custState').value = data.state || '';
    document.getElementById('custAddr').value  = data.address || '';
    if (data.source) {
        var fromLoc = document.getElementById('fromLocation');
        if (fromLoc && !fromLoc.value) fromLoc.value = data.source;
    }
    onDestinationChange();
    updateMtcAutoFields();
}

/* ── Vehicle selected → auto-fill driver & lease agent ── */
function fillVehicle(sel) {
    var opt = sel.options[sel.selectedIndex];
    var driverId = opt.getAttribute('data-driver');
    if (driverId && parseInt(driverId)) {
        var dsel = document.getElementById('driverSelect');
        if (dsel) dsel.value = driverId;
    }
    var agentId = parseInt(opt.getAttribute('data-lease-agent')) || 0;
    var agentSel = document.getElementById('leaseAgentSelect');
    if (agentSel && !agentSel.disabled && agentId) {
        agentSel.value = agentId;
        fillLeaseAgent(agentSel);
    }
}

function fillDriver(sel) {
    // optional hook
}

/* ── Lease Agent change ── */
function fillLeaseAgent(sel) {
    var opt = sel && sel.options ? sel.options[sel.selectedIndex] : null;
    var agentId = parseInt(sel && sel.value ? sel.value : 0) || 0;
    var nameEl = document.getElementById('leaseAgentNameHidden');
    var marginEl = document.getElementById('leaseAgentMargin');
    var modelEl = document.getElementById('leaseAgentBillingModel');
    if (nameEl) nameEl.value = opt ? (opt.getAttribute('data-name') || '') : '';
    if (agentId) {
        var agent = window._leaseAgentMap ? window._leaseAgentMap[agentId] : null;
        var defaultMargin = agent ? parseFloat(agent.margin_per_mt || agent.default_margin_per_mt || 0) : parseFloat((opt && opt.getAttribute('data-margin')) || 0) || 0;
        var billingModel = (opt && opt.getAttribute('data-billing-model')) ? opt.getAttribute('data-billing-model') : (agent ? agent.billing_model : 'margin_deduction');
        if (modelEl) modelEl.value = billingModel || 'margin_deduction';
        if (marginEl) marginEl.value = defaultMargin.toFixed(2);
    }
    updateLeaseAgentSettlement();
}

function onBillingModelChange() {
    var agentSelect = document.getElementById('leaseAgentSelect');
    var agentId = parseInt(agentSelect ? agentSelect.value : 0) || 0;
    var opt = agentSelect && agentSelect.selectedIndex >= 0 ? agentSelect.options[agentSelect.selectedIndex] : null;
    var agent = window._leaseAgentMap ? window._leaseAgentMap[agentId] : null;
    var modelEl = document.getElementById('leaseAgentBillingModel');
    var marginEl = document.getElementById('leaseAgentMargin');
    if (agent && modelEl && marginEl) {
        if (modelEl.value === (agent.billing_model || 'margin_deduction')) {
            marginEl.value = parseFloat(agent.margin_per_mt || agent.default_margin_per_mt || 0).toFixed(2);
        }
    }
    updateLeaseAgentSettlement();
}

function toggleLeaseAgentBillingFields() {
    var status = document.getElementById('leaseAgentBillingStatus').value;
    var isBilled = (status === 'Billed');
    var invWrap = document.getElementById('leaseAgentInvoiceNoWrap');
    var dateWrap = document.getElementById('leaseAgentBillingDateWrap');
    var remWrap = document.getElementById('leaseAgentBillingRemarksWrap');
    if (invWrap) invWrap.style.display = isBilled ? '' : 'none';
    if (dateWrap) dateWrap.style.display = isBilled ? '' : 'none';
    if (remWrap) remWrap.style.display = isBilled ? '' : 'none';
    updateLeaseAgentSettlement();
}

/* ── Destination changed → toll auto-lookup & weather ── */
function onDestinationChange() {
    var vsel = document.getElementById('vendorSelect');
    var vid = parseInt(vsel ? vsel.value : 0) || 0;
    var toLoc = (document.getElementById('toLocation').value || '').trim();
    var tollHiddenEl = document.getElementById('tollAmountHidden');

    if (vid && toLoc && vendorTollMap[vid] && vendorTollMap[vid][toLoc] !== undefined) {
        var toll = parseFloat(vendorTollMap[vid][toLoc]) || 0;
        if (tollHiddenEl) tollHiddenEl.value = toll.toFixed(2);
    } else if (toLoc && tollRateMap[toLoc] !== undefined) {
        var toll = parseFloat(tollRateMap[toLoc]) || 0;
        if (tollHiddenEl) tollHiddenEl.value = toll.toFixed(2);
    } else {
        if (tollHiddenEl) tollHiddenEl.value = '0.00';
    }

    if (window.DMSWeather && toLoc) {
        window.DMSWeather.load('tripWeatherWrap', toLoc);
    }
    updateMtcAutoFields();
}

function onWeightChange() {
    var wt = parseFloat(document.getElementById('totalWeight').value) || 0;
    var rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length === 1) {
        var winput = rows[0].querySelector('.item-weight');
        if (winput) {
            winput.value = wt > 0 ? wt.toFixed(3) : '';
            calcItems();
        }
    } else {
        calcItems();
    }
    updateLeaseAgentSettlement();
}

/* ── Items Table Operations ── */
function fillItemUom(sel) {
    var tr = sel.closest('tr');
    var opt = sel.options[sel.selectedIndex];
    var uom = opt ? opt.getAttribute('data-uom') : 'MT';
    var name = opt ? opt.getAttribute('data-name') : '';
    var uomEl = tr.querySelector('.item-uom');
    var nameEl = tr.querySelector('.item-name-hidden');
    if (uomEl) uomEl.value = uom || 'MT';
    if (nameEl) nameEl.value = name || '';
    updateMtcAutoFields();
}

function addItemRow() {
    var tbody = document.getElementById('itemsBody');
    var tr = document.createElement('tr');
    tr.className = 'item-row';
    var optsHtml = '<option value="">— Select —</option>';
    itemsMasterList.forEach(function(il) {
        optsHtml += '<option value="' + il.id + '" data-name="' + escapeHtml(il.item_name) + '" data-uom="' + escapeHtml(il.uom) + '">' + escapeHtml(il.item_name) + ' (' + escapeHtml(il.uom) + ')</option>';
    });
    tr.innerHTML = '<td><select name="item_id[]" class="form-select form-select-sm" onchange="fillItemUom(this)">' + optsHtml + '</select>' +
        '<input type="hidden" name="item_name[]" class="item-name-hidden" value=""></td>' +
        '<td><input type="text" name="uom[]" class="form-control form-control-sm item-uom bg-light" value="MT" readonly></td>' +
        '<td><input type="number" step="0.001" min="0" name="weight[]" class="form-control form-control-sm item-weight" placeholder="0.000" oninput="calcItems()"></td>' +
        '<td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm item-price" placeholder="0.00" oninput="calcItems()"></td>' +
        '<td><input type="number" step="0.01" name="amount[]" class="form-control form-control-sm item-amount bg-light" value="0" readonly></td>' +
        '<td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm p-0 px-1" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>';
    tbody.appendChild(tr);
}

function removeItemRow(btn) {
    var tbody = document.getElementById('itemsBody');
    if (tbody.querySelectorAll('.item-row').length > 1) {
        btn.closest('tr').remove();
        calcItems();
    }
}

function calcItems() {
    var rows = document.querySelectorAll('#itemsBody .item-row');
    var totalWt = 0;
    var totalAmt = 0;
    rows.forEach(function(r) {
        var w = parseFloat(r.querySelector('.item-weight').value) || 0;
        var p = parseFloat(r.querySelector('.item-price').value) || 0;
        var a = Math.round(w * p * 100) / 100;
        r.querySelector('.item-amount').value = a.toFixed(2);
        totalWt += w;
        totalAmt += a;
    });
    document.getElementById('itemsTotalWeight').textContent = totalWt.toFixed(3);
    document.getElementById('itemsTotal').textContent = totalAmt.toFixed(2);
    var freightEl = document.getElementById('freightAmount');
    if (freightEl) freightEl.value = totalAmt.toFixed(2);
    var totalWtEl = document.getElementById('totalWeight');
    if (totalWt > 0 && totalWtEl) {
        totalWtEl.value = totalWt.toFixed(3);
    }
    updateLeaseAgentSettlement();
}

function updateLeaseAgentSettlement() {
    var freightEl = document.getElementById('freightAmount');
    var weightEl = document.getElementById('totalWeight');
    var marginEl = document.getElementById('leaseAgentMargin');
    var miscDeductionEl = document.getElementById('leaseAgentMiscDeduction');
    var weightDisplay = document.getElementById('leaseAgentWeightDisplay');
    var vendorRateDisplay = document.getElementById('leaseAgentVendorRateDisplay');
    var rateDisplay = document.getElementById('leaseAgentRateDisplay');
    var totalDisplay = document.getElementById('leaseAgentPayableDisplay');
    var taxDisplay = document.getElementById('leaseAgentTaxDisplay');
    var totalPayableDisplay = document.getElementById('leaseAgentTotalPayableDisplay');
    var modelBadge = document.getElementById('leaseAgentModelBadge');
    var leaseAmountHidden = document.getElementById('leaseAgentAmountHidden');
    var netHidden = document.getElementById('netFreightAmountHidden');
    var itemsTotalText = document.getElementById('itemsTotal') ? document.getElementById('itemsTotal').textContent : '';
    var itemsTotal = parseFloat((itemsTotalText || '').replace(/[^0-9.-]/g, '')) || 0;
    var gross = parseFloat(freightEl ? freightEl.value : 0) || itemsTotal;
    var wt = parseFloat(weightEl ? weightEl.value : 0) || 0;
    var margin = parseFloat(marginEl ? marginEl.value : 0) || 0;
    var miscDeduction = parseFloat(miscDeductionEl ? miscDeductionEl.value : 0) || 0;
    var agentSelect = document.getElementById('leaseAgentSelect');
    var agentId = parseInt(agentSelect ? agentSelect.value : 0) || 0;

    var poSel = document.getElementById('poSelect');
    var poRate = (window._poFreightRate > 0) ? window._poFreightRate : ((poSel && typeof poRateMap !== 'undefined' && poRateMap[poSel.value]) ? parseFloat(poRateMap[poSel.value]) : 0);
    var vendorRate = (wt > 0 && gross > 0) ? (gross / wt) : poRate;
    if (gross === 0 && vendorRate > 0 && wt > 0) {
        gross = vendorRate * wt;
        if (freightEl && !(parseFloat(freightEl.value) > 0)) freightEl.value = gross.toFixed(2);
    }

    var opt = agentSelect && agentSelect.selectedIndex >= 0 ? agentSelect.options[agentSelect.selectedIndex] : null;
    var agent = (window._leaseAgentMap && agentId) ? window._leaseAgentMap[agentId] : null;
    var modelEl = document.getElementById('leaseAgentBillingModel');
    var currentModel = modelEl ? modelEl.value : (opt ? opt.getAttribute('data-billing-model') : (agent ? agent.billing_model : 'margin_deduction'));
    var isMgmtFee = (currentModel === 'mgmt_fee');

    if (modelBadge) {
        modelBadge.textContent = isMgmtFee ? 'Model 2: Fixed Mgmt Fee' : 'Model 1: Retained Margin';
        modelBadge.className = 'badge ' + (isMgmtFee ? 'bg-primary' : 'bg-light text-dark') + ' fw-semibold';
    }

    var marginRateLabel = document.getElementById('leaseAgentMarginLabel');
    var rateDisplayLabel = document.getElementById('leaseAgentRateDisplayLabel');
    var basePayableLabel = document.getElementById('leaseAgentBasePayableLabel');

    var deduction = 0;
    var net = 0;
    var basePayable = 0;

    if (weightDisplay) weightDisplay.value = wt.toFixed(3);
    if (vendorRateDisplay) vendorRateDisplay.value = '₹' + vendorRate.toFixed(2) + ' / MT';

    if (isMgmtFee) {
        if (marginRateLabel) marginRateLabel.textContent = 'Fleet Mgmt Fee / MT (₹)';
        if (rateDisplayLabel) rateDisplayLabel.textContent = 'Agent Mgmt Fee Rate';
        if (basePayableLabel) basePayableLabel.textContent = 'Total Mgmt Fee';

        var feeRate = margin;
        var fee = agentId > 0 ? (wt * feeRate) : 0;
        deduction = fee; // lease_agent_amount is fee
        net = gross;     // company retains gross freight
        basePayable = fee;

        if (rateDisplay) rateDisplay.value = '₹' + feeRate.toFixed(2) + ' / MT';
        if (totalDisplay) totalDisplay.value = '₹' + basePayable.toFixed(2);
    } else {
        if (marginRateLabel) marginRateLabel.textContent = 'Company Margin / MT (₹)';
        if (rateDisplayLabel) rateDisplayLabel.textContent = 'Agent Net Rate / MT';
        if (basePayableLabel) basePayableLabel.textContent = 'Agent Net Freight';

        var agentRate = agentId > 0 ? Math.max(0, vendorRate - margin) : vendorRate;
        deduction = agentId > 0 ? (wt * margin) : 0;
        net = agentId > 0 ? (wt > 0 ? (agentRate * wt) : Math.max(0, gross - deduction)) : gross;
        basePayable = net;

        if (rateDisplay) rateDisplay.value = '₹' + agentRate.toFixed(2) + ' / MT';
        if (totalDisplay) totalDisplay.value = '₹' + basePayable.toFixed(2);
    }

    if (leaseAmountHidden) leaseAmountHidden.value = deduction.toFixed(2);
    if (netHidden) netHidden.value = net.toFixed(2);

    var taxType = opt ? (opt.getAttribute('data-tax-type') || (agent ? agent.tax_type : 'None')) : (agent ? agent.tax_type : 'None');
    var billingStatusEl = document.getElementById('leaseAgentBillingStatus');
    var isBilled = billingStatusEl && billingStatusEl.value === 'Billed';
    var taxRate = 0;
    if (isBilled) {
        taxRate = opt ? parseFloat(opt.getAttribute('data-tax-rate') || (agent ? agent.tax_rate : 0)) : (agent ? parseFloat(agent.tax_rate) : 0);
    } else {
        var currentPoId = parseInt(poSel ? poSel.value : 0) || 0;
        var currentPoGst = (currentPoId && typeof poGstMap !== 'undefined' && poGstMap[currentPoId] !== undefined && parseFloat(poGstMap[currentPoId]) > 0)
            ? parseFloat(poGstMap[currentPoId])
            : (window._poGstRate || 0);
        taxRate = (currentPoGst > 0) ? currentPoGst : (opt ? parseFloat(opt.getAttribute('data-tax-rate') || (agent ? agent.tax_rate : 0)) : (agent ? parseFloat(agent.tax_rate) : 0));
    }
    var taxAmount = (taxType !== 'None' && taxRate > 0) ? (basePayable * taxRate / 100) : 0;
    if (taxDisplay) taxDisplay.value = '₹' + taxAmount.toFixed(2);

    var finalPayable = basePayable;
    if (taxType === 'GST') {
        finalPayable += taxAmount;
    } else if (taxType === 'TDS') {
        finalPayable -= taxAmount;
    }
    finalPayable = Math.max(0, finalPayable - miscDeduction);
    if (totalPayableDisplay) totalPayableDisplay.value = '₹' + finalPayable.toFixed(2);

    var fuelEl = document.getElementById('leaseAgentFuel');
    var advanceEl = document.getElementById('leaseAgentDriverAdvance');
    var foodingEl = document.getElementById('leaseAgentDriverFooding');
    var tollEl = document.getElementById('leaseAgentToll');
    var miscExpEl = document.getElementById('leaseAgentMiscExpense');
    var expTotalDisplay = document.getElementById('leaseAgentExpensesTotalDisplay');
    var profitDisplay = document.getElementById('leaseAgentProfitDisplay');

    var fuel = parseFloat(fuelEl ? fuelEl.value : 0) || 0;
    var adv = parseFloat(advanceEl ? advanceEl.value : 0) || 0;
    var food = parseFloat(foodingEl ? foodingEl.value : 0) || 0;
    var toll = parseFloat(tollEl ? tollEl.value : 0) || 0;
    var miscExp = parseFloat(miscExpEl ? miscExpEl.value : 0) || 0;

    var totalExp = fuel + adv + food + toll + miscExp;
    var profit = isMgmtFee ? finalPayable : (finalPayable - totalExp);

    if (expTotalDisplay) expTotalDisplay.value = '₹' + totalExp.toFixed(2);
    if (profitDisplay) {
        profitDisplay.value = (profit < 0 ? '-₹' + Math.abs(profit).toFixed(2) : '₹' + profit.toFixed(2));
        profitDisplay.className = 'form-control bg-light fw-bold ' + (profit >= 0 ? 'text-success' : 'text-danger');
    }
}

/* ── MTC Toggle & Auto-populate ── */
function toggleMTC() {
    var yes = document.getElementById('mtcYes');
    var det = document.getElementById('mtcDetails');
    if (det) det.style.display = (yes && yes.checked) ? 'block' : 'none';
    if (yes && yes.checked) updateMtcAutoFields();
}

function updateMtcAutoFields() {
    var src = document.getElementById('mtcSource');
    var fromLoc = document.getElementById('fromLocation');
    if (src && fromLoc && fromLoc.value) src.value = fromLoc.value;

    var iname = document.getElementById('mtcItemName');
    var firstItemNameEl = document.querySelector('#itemsBody .item-name-hidden');
    if (iname && firstItemNameEl && firstItemNameEl.value) {
        iname.value = firstItemNameEl.value;
    }
}

/* ── Add new source of material inline ── */
function showAddSource() {
    var box = document.getElementById('addSourceBox');
    if (box) {
        box.classList.remove('d-none');
        var input = document.getElementById('newSourceName');
        if (input) input.focus();
    }
}
function hideAddSource() {
    var box = document.getElementById('addSourceBox');
    if (box) box.classList.add('d-none');
    var input = document.getElementById('newSourceName');
    if (input) input.value = '';
}
function saveNewSource() {
    var input = document.getElementById('newSourceName');
    var name = input ? input.value.trim() : '';
    if (!name) { alert('Please enter a source name.'); return; }
    fetch('fleet_trips.php?ajax=add_source', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'source_name=' + encodeURIComponent(name)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var sel = document.getElementById('fromLocation');
            if (sel) {
                var opt = document.createElement('option');
                opt.value = data.source_name;
                opt.text  = data.source_name;
                opt.selected = true;
                sel.appendChild(opt);
            }
            hideAddSource();
            updateMtcAutoFields();
        } else {
            alert(data.error || 'Failed to add source.');
        }
    })
    .catch(function(err) {
        alert('Network error while adding source.');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    calcItems();
    updateLeaseAgentSettlement();
    var toLoc = (document.getElementById('toLocation') ? document.getElementById('toLocation').value : '').trim();
    if (window.DMSWeather && toLoc) {
        window.DMSWeather.load('tripWeatherWrap', toLoc);
    }
});
</script>
<?php endif; ?>

<?php
if ($action === 'docs' && $id > 0):
    $t = $db->query("SELECT * FROM fleet_trips WHERE id=$id $tripLeaseScopeSql LIMIT 1")->fetch_assoc();
    if (!$t || fleetTripDisplayStatus($t) !== 'Completed') {
        showAlert('danger','Documents can only be managed for Completed trips.');
        redirect('fleet_trips.php');
    }
    $trip_docs = $db->query("SELECT * FROM fleet_trip_documents WHERE trip_id=$id ORDER BY uploaded_at ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">
        <i class="bi bi-paperclip me-2"></i>Trip Documents
        <span class="badge bg-success ms-2"><?= htmlspecialchars($t['trip_no']) ?></span>
    </h5>
    <a href="?action=view&id=<?= $id ?>&back=<?= urlencode($trip_back_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Trip</a>
</div>

<div class="card">
<div class="card-body">
    <!-- Existing docs -->
    <div id="docList" class="mb-3">
    <?php foreach ($trip_docs as $doc): ?>
    <div class="d-flex align-items-center gap-2 mb-2 doc-item" id="doc-<?= $doc['id'] ?>">
        <?php $ext = strtolower(pathinfo($doc['file_path'],PATHINFO_EXTENSION)); ?>
        <i class="bi bi-file-earmark-<?= $ext==='pdf'?'pdf text-danger':'image text-primary' ?> fs-4"></i>
        <div class="flex-grow-1">
            <a href="<?= htmlspecialchars(r2_url($doc['file_path'])) ?>" target="_blank" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($doc['doc_name']) ?>
            </a>
            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($doc['doc_type']) ?></span>
            <small class="text-muted ms-2"><?= date('d/m/Y H:i', strtotime($doc['uploaded_at'])) ?></small>
        </div>
        <a href="<?= htmlspecialchars(r2_url($doc['file_path'])) ?>" target="_blank" class="btn btn-action btn-outline-success" title="Download">
            <i class="bi bi-download"></i>
        </a>
        <button type="button" class="btn btn-action btn-outline-danger" onclick="deleteDoc(<?= $doc['id'] ?>)" title="Delete">
            <i class="bi bi-trash"></i>
        </button>
    </div>
    <?php endforeach; ?>
    <?php if (empty($trip_docs)): ?>
    <p class="text-muted" id="noDocsMsg"><i class="bi bi-paperclip me-1"></i>No documents uploaded yet.</p>
    <?php endif; ?>
    </div>

    <hr>
    <!-- Upload -->
    <h6 class="fw-bold mb-3"><i class="bi bi-upload me-2"></i>Upload New Document</h6>
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label form-label-sm">Document Type</label>
            <select id="newDocType" class="form-select">
                <option>Loading Slip</option>
                <option>Weight Slip</option>
                <option>Delivery Receipt</option>
                <option>Permit Copy</option>
                <option>E-Way Bill</option>
                <option>Customer PO Copy</option>
                <option>Invoice</option>
                <option>Other</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label form-label-sm">Document Name</label>
            <input type="text" id="newDocName" class="form-control" placeholder="e.g. Loading Slip #123">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label form-label-sm">File (PDF / JPG / PNG / WEBP)</label>
            <input type="file" id="newDocFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
        </div>
        <div class="col-6 col-md-2">
            <button type="button" class="btn btn-primary w-100" onclick="uploadDoc()">
                <i class="bi bi-upload me-1"></i>Upload
            </button>
        </div>
    </div>
    <div id="uploadMsg" class="mt-3" style="display:none"></div>
</div>
</div>

<script>
function saveMRN(tripId) {
    var btn = document.getElementById('mrnSaveBtn');
    var msg = document.getElementById('mrnMsg');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('trip_id',      tripId);
    fd.append('mrn_no',       document.getElementById('mrn_no').value);
    fd.append('mrn_date',     document.getElementById('mrn_date').value);
    fd.append('inv_reg_no',   document.getElementById('inv_reg_no').value);
    fd.append('inv_reg_date', document.getElementById('inv_reg_date').value);
    fetch('fleet_trips.php?ajax=save_mrn', {method:'POST', body:fd})
        .then(r => r.json())
        .then(function(d) {
            btn.disabled = false;
            msg.style.display = 'block';
            msg.innerHTML = d.success
                ? '<span class="badge bg-success">Saved ✓</span>'
                : '<span class="badge bg-danger">Failed</span>';
            setTimeout(function(){ msg.style.display='none'; }, 3000);
        }).catch(function(){
            btn.disabled = false;
            msg.style.display = 'block';
            msg.innerHTML = '<span class="badge bg-danger">Error</span>';
        });
}

function uploadDoc() {
    var file = document.getElementById('newDocFile').files[0];
    if (!file) { alert('Please select a file.'); return; }
    var type = document.getElementById('newDocType').value;
    var name = document.getElementById('newDocName').value.trim() || file.name;
    var msg  = document.getElementById('uploadMsg');
    msg.style.display = 'block';
    msg.innerHTML = '<div class="alert alert-secondary py-2"><i class="bi bi-hourglass-split me-1"></i>Uploading to cloud storage...</div>';
    var fd = new FormData();
    fd.append('trip_id', <?= $id ?>);
    fd.append('doc_type', type);
    fd.append('doc_name', name);
    fd.append('doc_file', file);
    fetch('fleet_trips.php?ajax=upload_doc', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function(d) {
            if (d.success) {
                msg.innerHTML = '<div class="alert alert-success py-2"><i class="bi bi-check2 me-1"></i>Uploaded successfully.</div>';
                var nd = document.getElementById('noDocsMsg');
                if (nd) nd.remove();
                var ext = d.r2_key ? d.r2_key.split('.').pop().toLowerCase() : '';
                var icon = ext === 'pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary';
                var html = '<div class="d-flex align-items-center gap-2 mb-2 doc-item" id="doc-'+d.id+'">' +
                    '<i class="bi '+icon+' fs-4"></i>' +
                    '<div class="flex-grow-1"><a href="'+d.url+'" target="_blank" class="fw-semibold text-decoration-none">'+d.doc_name+'</a>' +
                    '<span class="badge bg-secondary ms-1">'+d.doc_type+'</span></div>' +
                    '<a href="'+d.url+'" target="_blank" class="btn btn-action btn-outline-success" title="Download"><i class="bi bi-download"></i></a>' +
                    '<button type="button" class="btn btn-action btn-outline-danger" onclick="deleteDoc('+d.id+')" title="Delete"><i class="bi bi-trash"></i></button>' +
                    '</div>';
                document.getElementById('docList').insertAdjacentHTML('beforeend', html);
                document.getElementById('newDocFile').value = '';
                document.getElementById('newDocName').value = '';
            } else {
                msg.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i>' + (d.error||'Upload failed.') + '</div>';
            }
        }).catch(function() {
            msg.innerHTML = '<div class="alert alert-danger py-2">Network error. Please try again.</div>';
        });
}
function deleteDoc(docId) {
    if (!confirm('Delete this document?')) return;
    var fd = new FormData();
    fd.append('doc_id', docId);
    fetch('fleet_trips.php?ajax=delete_doc', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function(d) { if (d.success) { var el = document.getElementById('doc-'+docId); if (el) el.remove(); } });
}
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>

