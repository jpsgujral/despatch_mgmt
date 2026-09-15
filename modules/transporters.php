<?php
require_once '../includes/config.php';
require_once '../includes/r2_helper.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
/* ── Page-level view permission check ── */
requirePerm('transporters', 'view');


/* ── Safe ALTER: works on MySQL 5.6+ ── */
function safeAddColumn($db, $table, $column, $definition) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$column'
        LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

/* Ensure new columns exist */
safeAddColumn($db, 'transporters', 'gst_type',       "VARCHAR(20) DEFAULT 'Central'");
safeAddColumn($db, 'transporters', 'gst_rate',        'DECIMAL(5,2) DEFAULT 0');
safeAddColumn($db, 'transporters', 'tds_applicable',  "ENUM('No','Yes') DEFAULT 'No'");
safeAddColumn($db, 'transporters', 'tds_rate',        'DECIMAL(5,2) DEFAULT 0');
safeAddColumn($db, 'transporters', 'despatched_qty',  'DECIMAL(12,2) DEFAULT NULL COMMENT \'Reference only — not used in calculations\'');

/* ── Document columns ── */
safeAddColumn($db, 'transporters', 'doc_pan',        "VARCHAR(255) DEFAULT ''");
safeAddColumn($db, 'transporters', 'doc_gst_cert',   "VARCHAR(255) DEFAULT ''");
safeAddColumn($db, 'transporters', 'doc_agreement',  "VARCHAR(255) DEFAULT ''");
safeAddColumn($db, 'transporters', 'doc_other',      "VARCHAR(255) DEFAULT ''");

/* ── Transporter Vendor Rate Card table ── */
$db->query("CREATE TABLE IF NOT EXISTS `transporter_rates` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `transporter_id` INT NOT NULL,
    `vendor_id`      INT NOT NULL,
    `rate`           DECIMAL(10,4) NOT NULL DEFAULT 0,
    `uom`            VARCHAR(30)  NOT NULL DEFAULT '',
    `notes`          VARCHAR(255) NOT NULL DEFAULT '',
    `status`         ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `effective_from` DATE DEFAULT NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `fk_tr_transporter` (`transporter_id`),
    KEY `fk_tr_vendor` (`vendor_id`)
)");
// Drop old unique constraint if exists (allows multiple rates per pair with history)
if ($db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transporter_rates' AND INDEX_NAME='uniq_tr_vendor'")->num_rows) $db->query("ALTER TABLE transporter_rates DROP INDEX uniq_tr_vendor");
// Add new columns if not exist
safeAddColumn($db, 'transporter_rates', 'status',         "ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'");
safeAddColumn($db, 'transporter_rates', 'effective_from', "DATE DEFAULT NULL");

/* ── Link despatch orders to specific rate card ── */
safeAddColumn($db, 'despatch_orders', 'rate_card_id', "INT DEFAULT NULL COMMENT 'FK to transporter_rates.id'");
// Backfill: assign unlinked orders to the newest active rate card for their pair
// (newest = highest id = the most recent rate)
$db->query("UPDATE despatch_orders d
    JOIN (
        SELECT transporter_id, vendor_id, MAX(id) AS newest_rate_id
        FROM transporter_rates WHERE status='Active'
        GROUP BY transporter_id, vendor_id
    ) tr ON tr.transporter_id = d.transporter_id AND tr.vendor_id = d.vendor_id
    SET d.rate_card_id = tr.newest_rate_id
    WHERE d.rate_card_id IS NULL");

/* ── Uploads directory ── */
/* ── Transporter doc upload handled via Cloudflare R2 ── */

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

/* ── Auto-generate Transporter Code ── */
function generateTransporterCode($db) {
    $row = $db->query("SELECT MAX(CAST(SUBSTRING(transporter_code, 5) AS UNSIGNED)) AS mx
                       FROM transporters WHERE transporter_code LIKE 'TPT-%'")->fetch_assoc();
    $next = (int)($row['mx'] ?? 0) + 1;
    return 'TPT-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

if (isset($_GET['delete'])) {
    requirePerm('transporters', 'delete');
    $db->query("DELETE FROM transporters WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Transporter deleted successfully.');
    redirect('transporters.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax_rate_action'])) {
    requirePerm('transporters', $id > 0 ? 'update' : 'create');
    $fields = ['transporter_name','contact_person','phone','mobile','email',
               'address','city','state','pincode','gstin','pan','vehicle_types',
               'bank_name','account_no','ifsc_code','status','gst_type','tds_applicable'];
    $data = [];
    foreach ($fields as $f) $data[$f] = sanitize($_POST[$f] ?? '');

    /* Auto-generate code for new, keep existing for edit */
    if ($id > 0) {
        $data['transporter_code'] = sanitize($_POST['transporter_code'] ?? '');
    } else {
        $data['transporter_code'] = generateTransporterCode($db);
    }

    /* Numeric fields */
    $data['gst_rate']      = ($data['gst_type'] === 'RCM') ? 0 : (float)($_POST['gst_rate']  ?? 0);
    $data['tds_rate']      = ($data['tds_applicable'] === 'Yes') ? (float)($_POST['tds_rate'] ?? 0) : 0;
    /* Despatched Qty — reference only, nullable */
    $raw_dq = trim($_POST['despatched_qty'] ?? '');
    $data['despatched_qty'] = ($raw_dq === '') ? null : (float)$raw_dq;

    $errors = [];
    if (empty($data['transporter_name']))   $errors[] = 'Transporter Name is required.';
    if (empty($data['gst_type']))            $errors[] = 'GST Type is required.';
    if ($data['gst_type'] !== 'RCM' && $data['gst_rate'] <= 0)
                                             $errors[] = 'GST Rate is required and must be greater than 0.';
    if (!empty($errors)) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        if ($id > 0) {
            $set = [];
            foreach ($data as $k => $v) {
                if (is_null($v))              $set[] = "$k=NULL";
                elseif (is_int($v)||is_float($v)) $set[] = "$k=$v";
                else $set[] = "$k='" . $db->real_escape_string($v) . "'";
            }
            $db->query("UPDATE transporters SET " . implode(',', $set) . " WHERE id=$id");
            showAlert('success', 'Transporter updated successfully.');
        } else {
            $cols = implode(',', array_keys($data));
            $vals = [];
            foreach ($data as $v) {
                if (is_null($v))              $vals[] = 'NULL';
                elseif (is_int($v)||is_float($v)) $vals[] = $v;
                else $vals[] = "'" . $db->real_escape_string($v) . "'";
            }
            $db->query("INSERT INTO transporters ($cols) VALUES (" . implode(',', $vals) . ")");
            $id = $db->insert_id;
            showAlert('success', 'Transporter added successfully.');
        }
        // Handle document uploads & removals via R2
        // Load current values fresh from DB
        $tr_row  = $db->query("SELECT doc_pan,doc_gst_cert,doc_agreement,doc_other FROM transporters WHERE id=$id")->fetch_assoc();
        $cur_pan = $tr_row['doc_pan']       ?? '';
        $cur_gst = $tr_row['doc_gst_cert']  ?? '';
        $cur_agr = $tr_row['doc_agreement'] ?? '';
        $cur_oth = $tr_row['doc_other']     ?? '';
        // Remove first
        foreach (['doc_pan'=>&$cur_pan,'doc_gst_cert'=>&$cur_gst,'doc_agreement'=>&$cur_agr,'doc_other'=>&$cur_oth] as $field=>$val) {
            if (!empty($_POST['remove_'.$field]) && !empty($$field === $val ? $val : '')) {
                r2_delete($val); $$field = ''; $val = '';
            }
        }
        // Re-read after loop workaround
        if (!empty($_POST['remove_doc_pan'])       && !empty($cur_pan))  { r2_delete($cur_pan);  $cur_pan  = ''; }
        if (!empty($_POST['remove_doc_gst_cert'])  && !empty($cur_gst))  { r2_delete($cur_gst);  $cur_gst  = ''; }
        if (!empty($_POST['remove_doc_agreement']) && !empty($cur_agr))  { r2_delete($cur_agr);  $cur_agr  = ''; }
        if (!empty($_POST['remove_doc_other'])     && !empty($cur_oth))  { r2_delete($cur_oth);  $cur_oth  = ''; }
        // Upload new
        $doc_pan = r2_handle_upload('doc_pan',       'transporter_docs/T'.$id.'_PAN', $cur_pan) ?: $cur_pan;
        $doc_gst = r2_handle_upload('doc_gst_cert',  'transporter_docs/T'.$id.'_GST', $cur_gst) ?: $cur_gst;
        $doc_agr = r2_handle_upload('doc_agreement', 'transporter_docs/T'.$id.'_AGR', $cur_agr) ?: $cur_agr;
        $doc_oth = r2_handle_upload('doc_other',     'transporter_docs/T'.$id.'_OTH', $cur_oth) ?: $cur_oth;
        $esc = fn($v) => $db->real_escape_string($v);
        $db->query("UPDATE transporters SET
            doc_pan='{$esc($doc_pan)}', doc_gst_cert='{$esc($doc_gst)}',
            doc_agreement='{$esc($doc_agr)}', doc_other='{$esc($doc_oth)}'
            WHERE id=$id");
        redirect('transporters.php');
    }
}

/* ── Rate Card AJAX handlers ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_rate_action'])) {
    header('Content-Type: application/json');
    $tid = (int)($_POST['transporter_id'] ?? 0);
    $act = $_POST['ajax_rate_action'];

    if ($act === 'save') {
        $vid  = (int)($_POST['vendor_id'] ?? 0);
        $rate = (float)($_POST['rate'] ?? 0);
        $uom  = $db->real_escape_string(sanitize($_POST['uom'] ?? ''));
        $note = $db->real_escape_string(sanitize($_POST['notes'] ?? ''));
        $eff  = $db->real_escape_string(sanitize($_POST['effective_from'] ?? date('Y-m-d')));
        if ($tid < 1 || $vid < 1 || $rate <= 0) {
            echo json_encode(['ok'=>false,'msg'=>'Transporter, Vendor and Rate are required.']); exit;
        }
        $rid = (int)($_POST['rate_id'] ?? 0);
        if ($rid > 0) {
            // EDIT — block if active despatch orders exist for THIS specific rate card
            $active = $db->query("SELECT COUNT(*) c FROM despatch_orders
                WHERE rate_card_id=$rid
                AND status NOT IN ('Cancelled','Draft')")->fetch_assoc()['c'];
            if ($active > 0) {
                echo json_encode(['ok'=>false,'msg'=>"Cannot edit: $active active despatch order(s) use this rate. This rate is LOCKED. Click 'Add Rate' to create a new rate — the old rate stays locked for existing orders."]); exit;
            }
            $db->query("UPDATE transporter_rates SET rate=$rate, uom='$uom', notes='$note', effective_from='$eff' WHERE id=$rid AND transporter_id=$tid");
        } else {
            // NEW RATE — deactivate ALL old Active rates for this vendor+transporter pair.
            // Historical link is preserved via despatch_orders.rate_card_id so this is safe.
            $db->query("UPDATE transporter_rates SET status='Inactive'
                WHERE transporter_id=$tid AND vendor_id=$vid AND status='Active'");
            $db->query("INSERT INTO transporter_rates (transporter_id,vendor_id,rate,uom,notes,status,effective_from)
                        VALUES ($tid,$vid,$rate,'$uom','$note','Active','$eff')");
        }
        echo json_encode(['ok'=>true,'msg'=>'Rate saved.']); exit;
    }

    if ($act === 'delete') {
        $rid = (int)($_POST['rate_id'] ?? 0);
        // Block delete if active despatch orders reference THIS specific rate card
        $active = (int)$db->query("SELECT COUNT(*) c FROM despatch_orders
            WHERE rate_card_id=$rid
            AND status NOT IN ('Cancelled','Draft')")->fetch_assoc()['c'];
        if ($active > 0) {
            echo json_encode(['ok'=>false,'msg'=>"Cannot delete: $active active despatch order(s) use this rate."]); exit;
        }
        $db->query("DELETE FROM transporter_rates WHERE id=$rid AND transporter_id=$tid");
        echo json_encode(['ok'=>true]); exit;
    }

    if ($act === 'list') {
        $rows = $db->query("SELECT tr.*, v.vendor_name, v.city,
            (SELECT COUNT(*) FROM transporter_rates tr2
             WHERE tr2.transporter_id=tr.transporter_id AND tr2.vendor_id=tr.vendor_id AND tr2.status='Inactive') AS history_count,
            (SELECT COUNT(*) FROM despatch_orders d
             WHERE d.rate_card_id = tr.id
             AND d.status NOT IN ('Cancelled','Draft')) AS active_orders
            FROM transporter_rates tr
            JOIN vendors v ON tr.vendor_id = v.id
            WHERE tr.transporter_id=$tid
              AND (tr.status='Active'
                   OR (tr.status='Inactive' AND EXISTS (
                       SELECT 1 FROM despatch_orders d2
                       WHERE d2.rate_card_id = tr.id
                       AND d2.status NOT IN ('Cancelled','Draft')
                   )))
            ORDER BY tr.status ASC, v.vendor_name")->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$t = [];
if ($action == 'edit' && $id > 0) {
    $t = $db->query("SELECT * FROM transporters WHERE id=$id")->fetch_assoc();
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-truck-front me-2"></i>Transporter Master';</script>

<?php if ($action == 'list'): ?>
<div class="card shadow-sm">
<div class="card-header d-flex justify-content-between align-items-center py-2"
     style="background:linear-gradient(135deg,#1E3A8A,#334155);border-left:4px solid #172554;color:#fff">
    <span class="fw-bold"><i class="bi bi-truck me-2"></i>Transporters</span>
    <a href="?action=add" class="btn btn-sm btn-light fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Transporter</a>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
    <thead class="table-light"><tr>
        <th>#</th><th>Transporter Name</th>
        <th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php
    $list = $db->query("SELECT * FROM transporters ORDER BY transporter_name");
    $i = 1;
    while ($v = $list->fetch_assoc()):
    ?>
    <tr>
        <td class="text-muted"><?= $i++ ?></td>
        <td class="fw-bold"><?= htmlspecialchars($v['transporter_name']) ?></td>
        <td><span class="badge bg-<?= $v['status']=='Active'?'success':'secondary' ?>"><?= $v['status'] ?></span></td>
        <td>
            <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
            <button onclick="confirmDelete(<?= $v['id'] ?>,'transporters.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>
</div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $action=='edit'?'Edit':'Add New' ?> Transporter</h5>
    <a href="transporters.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST" id="transporterForm" enctype="multipart/form-data">
<div class="row g-3">

    <!-- Transporter Information -->
    <div class="col-12"><div class="card shadow-sm"><div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#334155);color:#fff"><i class="bi bi-truck-front me-2"></i>Transporter Information</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Transporter Code</label>
            <?php if ($action === 'edit'): ?>
            <input type="hidden" name="transporter_code" value="<?= htmlspecialchars($t['transporter_code']??'') ?>">
            <input type="text" class="form-control bg-light fw-bold text-primary" value="<?= htmlspecialchars($t['transporter_code']??'') ?>" readonly tabindex="-1">
            <?php else: ?>
            <input type="text" class="form-control bg-light fw-bold text-muted" value="<?= generateTransporterCode($db) ?>" readonly tabindex="-1">
            <?php endif; ?>
        </div>
        <div class="col-12 col-sm-6 col-md-5">
            <label class="form-label">Transporter Name *</label>
            <input type="text" name="transporter_name" class="form-control" required value="<?= htmlspecialchars($t['transporter_name']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Contact Person</label>
            <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($t['contact_person']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($t['phone']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Mobile</label>
            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($t['mobile']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($t['email']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="Active"   <?= ($t['status']??'Active')==='Active'  ?'selected':'' ?>>Active</option>
                <option value="Inactive" <?= ($t['status']??'')==='Inactive'      ?'selected':'' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($t['address']??'') ?></textarea>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($t['city']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">State</label>
            <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($t['state']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Pincode</label>
            <input type="text" name="pincode" class="form-control" value="<?= htmlspecialchars($t['pincode']??'') ?>">
        </div>
    </div></div></div></div>

    <!-- Tax & Rates -->
    <div class="col-12 col-md-6"><div class="card shadow-sm"><div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#334155);color:#fff"><i class="bi bi-receipt me-2"></i>Tax &amp; Rates</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label">GSTIN</label>
            <input type="text" name="gstin" class="form-control" value="<?= htmlspecialchars($t['gstin']??'') ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label">PAN</label>
            <input type="text" name="pan" class="form-control" value="<?= htmlspecialchars($t['pan']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Vehicle Types</label>
            <input type="text" name="vehicle_types" class="form-control" placeholder="e.g. Truck, Tempo" value="<?= htmlspecialchars($t['vehicle_types']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Despatched Qty</label>
            <input type="number" name="despatched_qty" step="0.01" min="0" class="form-control" placeholder="Reference only" value="<?= $t['despatched_qty'] ?? '' ?>">
            <div class="form-text text-muted">Reference only — not used in calculations</div>
        </div>

        <!-- GST Type -->
        <div class="col-12"><hr class="my-1"></div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">GST Type *
                <span class="ms-1" data-bs-toggle="tooltip"
                    title="Central = Inter-state (IGST) | Regular = Intra-state (CGST+SGST) | RCM = Reverse Charge (GST paid by receiver, rate=0)">
                    <i class="bi bi-info-circle text-primary"></i>
                </span>
            </label>
            <select name="gst_type" id="gstType" class="form-select" required onchange="handleGSTChange()">
                <option value="Central" <?= ($t['gst_type']??'Central')==='Central'?'selected':'' ?>>Central (IGST)</option>
                <option value="Regular" <?= ($t['gst_type']??'')==='Regular'?'selected':'' ?>>Regular (CGST + SGST)</option>
                <option value="RCM"     <?= ($t['gst_type']??'')==='RCM'    ?'selected':'' ?>>RCM (Reverse Charge)</option>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-md-4" id="gstRateWrap">
            <label class="form-label" id="gstRateLabel">GST Rate (%) *</label>
            <div class="input-group">
                <input type="number" name="gst_rate" id="gstRate" step="0.01" min="0.01" max="100"
                       class="form-control" required value="<?= $t['gst_rate']??0 ?>">
                <span class="input-group-text">%</span>
            </div>
            <div class="form-text" id="gstRateHint"></div>
        </div>
        <div class="col-12 col-sm-6 col-md-4" id="gstRcmInfo" style="display:none">
            <label class="form-label">GST under RCM</label>
            <div class="form-control bg-light text-muted d-flex align-items-center" style="height:38px">
                <i class="bi bi-info-circle me-2 text-secondary"></i>GST = 0% (paid by receiver)
            </div>
        </div>

        <!-- TDS -->
        <div class="col-12"><hr class="my-1"></div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">TDS Applicable</label>
            <select name="tds_applicable" id="tdsApplicable" class="form-select" onchange="handleTDSChange()">
                <option value="No"  <?= ($t['tds_applicable']??'No')==='No' ?'selected':'' ?>>No</option>
                <option value="Yes" <?= ($t['tds_applicable']??'')==='Yes'  ?'selected':'' ?>>Yes</option>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-md-4" id="tdsRateWrap" style="display:none">
            <label class="form-label">TDS Rate (%)</label>
            <div class="input-group">
                <input type="number" name="tds_rate" id="tdsRate" step="0.01" min="0" max="100"
                       class="form-control" value="<?= $t['tds_rate']??0 ?>">
                <span class="input-group-text">%</span>
            </div>
            <div class="form-text text-muted">e.g. 2% under Section 194C</div>
        </div>
    </div></div></div></div>

    <!-- Bank Details & Transporter Documents -->
    <div class="col-12 col-md-6"><div class="card border-primary h-100">
        <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#334155);color:#fff"><i class="bi bi-folder2-open me-2"></i>Bank Details &amp; Documents</div>
        <div class="card-body">
        <div class="row g-2">

            <!-- Bank Fields -->
            <div class="col-12 col-sm-5">
                <label class="form-label fw-semibold mb-1"><i class="bi bi-bank me-1 text-primary"></i>Bank Name</label>
                <input type="text" name="bank_name" class="form-control form-control-sm" value="<?= htmlspecialchars($t['bank_name']??'') ?>">
            </div>
            <div class="col-12 col-sm-4">
                <label class="form-label fw-semibold mb-1"><i class="bi bi-credit-card me-1 text-primary"></i>Account No.</label>
                <input type="text" name="account_no" class="form-control form-control-sm" value="<?= htmlspecialchars($t['account_no']??'') ?>">
            </div>
            <div class="col-12 col-sm-3">
                <label class="form-label fw-semibold mb-1"><i class="bi bi-hash me-1 text-primary"></i>IFSC</label>
                <input type="text" name="ifsc_code" class="form-control form-control-sm" value="<?= htmlspecialchars($t['ifsc_code']??'') ?>">
            </div>

            <div class="col-12"><hr class="my-2"></div>

            <!-- Document Slots - 2x2 grid -->
        <?php
        $doc_slots = [
            ['field'=>'doc_pan',       'label'=>'PAN Card',            'icon'=>'bi-person-vcard',      'color'=>'text-warning'],
            ['field'=>'doc_gst_cert',  'label'=>'GST Certificate',     'icon'=>'bi-file-earmark-text', 'color'=>'text-success'],
            ['field'=>'doc_agreement', 'label'=>'Agreement / Contract', 'icon'=>'bi-file-earmark-ruled','color'=>'text-primary'],
            ['field'=>'doc_other',     'label'=>'Other Document',      'icon'=>'bi-paperclip',         'color'=>'text-secondary'],
        ];
        foreach ($doc_slots as $slot):
            $fname = $t[$slot['field']] ?? '';
        ?>
        <div class="col-6">
            <div class="border rounded p-2 h-100" style="background:#f8f9fa">
                <div class="d-flex align-items-center gap-1 mb-1">
                    <i class="bi <?= $slot['icon'] ?> <?= $slot['color'] ?>"></i>
                    <span class="fw-semibold small"><?= $slot['label'] ?></span>
                    <?php if (!empty($fname)): ?>
                    <a href="<?= htmlspecialchars(r2_url($fname)) ?>"
                       target="_blank" class="ms-auto btn btn-outline-success btn-sm py-0 px-1" style="font-size:0.7rem">
                        <i class="bi bi-eye"></i> View
                    </a>
                    <?php endif; ?>
                </div>
                <?php if (!empty($fname)): ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary" style="font-size:0.65rem"><i class="bi bi-check2"></i> Uploaded</span>
                    <div class="form-check mb-0">
                        <input class="form-check-input border-danger" type="checkbox"
                               name="remove_<?= $slot['field'] ?>" value="1"
                               id="rm_<?= $slot['field'] ?>"
                               onchange="toggleRemoveTrDoc(this,'trupload_<?= $slot['field'] ?>')">
                        <label class="form-check-label text-danger small" for="rm_<?= $slot['field'] ?>">Remove</label>
                    </div>
                </div>
                <?php endif; ?>
                <div id="trupload_<?= $slot['field'] ?>">
                    <input type="file" name="<?= $slot['field'] ?>" class="form-control form-control-sm"
                           accept=".pdf,.jpg,.jpeg,.png">
                    <div class="form-text" style="font-size:0.65rem"><?= empty($fname) ? 'PDF/image, max 10MB' : 'Upload to replace' ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        </div>
    </div></div>

    <div class="col-12 text-end">
        <a href="transporters.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $action=='edit'?'Update':'Save' ?> Transporter</button>
    </div>
</div>
</form>

<?php if ($action === 'edit' && $id > 0):
    /* Load vendors for rate card */
    $rc_vendors = $db->query("SELECT id, vendor_name, city FROM vendors WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
?>
<!-- ── Vendor Rate Card ── -->
<div class="card mt-4 border-primary">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>Vendor Rate Card
            <small class="opacity-75 fw-normal ms-2">— Freight rate per UOM for each vendor</span>
        </span>
        <button type="button" class="btn btn-sm btn-light" onclick="rcOpenAdd()">
            <i class="bi bi-plus-circle me-1"></i>Add Rate
        </button>
    </div>
    <div class="card-body p-0">
        <div id="rcTableWrap">
            <div class="text-center p-3 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</div>
        </div>
    </div>
</div>

<!-- Rate Card Modal -->
<div class="modal fade" id="rcModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-table me-2"></i><span id="rcModalTitle">Add Rate</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <input type="hidden" id="rcRateId" value="0">
        <div class="mb-3">
            <label class="form-label fw-semibold">Vendor (Consignee) *</label>
            <select id="rcVendorId" class="form-select">
                <option value="">-- Select Vendor --</option>
                <?php foreach ($rc_vendors as $rv): ?>
                <option value="<?= $rv['id'] ?>"><?= htmlspecialchars($rv['vendor_name']) ?><?= $rv['city'] ? ' — '.$rv['city'] : '' ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text text-muted" id="rcVendorNote" style="display:none">
                <i class="bi bi-lock-fill me-1"></i>Vendor locked on edit
            </div>
        </div>
        <div class="row g-3">
            <div class="col-6">
                <label class="form-label fw-semibold">Rate *</label>
                <div class="input-group">
                    <span class="input-group-text">₹</span>
                    <input type="number" id="rcRate" class="form-control" step="0.0001" min="0.0001" placeholder="0.00">
                </div>
            </div>
            <div class="col-6">
                <label class="form-label fw-semibold">UOM</label>
                <input type="text" id="rcUom" class="form-control" placeholder="e.g. MT, KG, BAG">
                <div class="form-text">Taken from Item Master</div>
            </div>
        </div>
        <div class="row g-3 mt-1">
            <div class="col-6">
                <label class="form-label">Effective From</label>
                <input type="date" id="rcEffectiveFrom" class="form-control" value="<?= date('Y-m-d') ?>">
                <div class="form-text">Date this rate is effective from</div>
            </div>
            <div class="col-6">
                <label class="form-label">Notes</label>
                <input type="text" id="rcNotes" class="form-control" placeholder="Optional notes">
            </div>
        </div>
        <div id="rcSaveMsg" class="mt-2" style="display:none"></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="rcSave()"><i class="bi bi-check2 me-1"></i>Save Rate</button>
    </div>
</div>
</div>
</div>

<script>
var RC_TID = <?= $id ?>;
var rcModalEl = document.getElementById('rcModal');
if (rcModalEl && rcModalEl.parentNode !== document.body) {
    document.body.appendChild(rcModalEl);
}

function getRcModal() {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById('rcModal'));
}

function rcLoad() {
    fetch('transporters.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_rate_action=list&transporter_id=' + RC_TID
    })
    .then(r => r.json())
    .then(data => {
        var wrap = document.getElementById('rcTableWrap');
        if (!data.ok || !data.rows.length) {
            wrap.innerHTML = '<div class="text-center text-muted p-3"><i class="bi bi-info-circle me-1"></i>No rates defined yet. Click <strong>Add Rate</strong> to add vendor-wise freight rates.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr>'
            + '<th>#</th><th>Vendor</th><th>City</th><th class="text-end">Rate (₹)</th><th>UOM</th><th>Effective From</th><th>Status</th><th>Notes</th><th></th>'
            + '</tr></thead><tbody>';
        data.rows.forEach(function(r, i) {
            var hasOrders = parseInt(r.active_orders) > 0;
            var isInactive = r.status === 'Inactive';
            var rowClass = isInactive ? ' class="table-secondary"' : (hasOrders ? ' class="table-warning"' : '');
            html += '<tr' + rowClass + '>'
                + '<td>' + (i+1) + '</td>'
                + '<td class="fw-bold">' + r.vendor_name + '</td>'
                + '<td class="fw-semibold text-dark">' + (r.city||'') + '</td>'
                + '<td class="text-end fw-bold ' + (isInactive ? 'text-muted text-decoration-line-through' : 'text-primary') + '">₹' + parseFloat(r.rate).toFixed(4) + '</td>'
                + '<td>' + (r.uom||'—') + '</td>'
                + '<td><span class="text-secondary fw-semibold" style="font-size:.82rem">' + (r.effective_from||'—') + '</small></td>'
                + '<td>'
                + (isInactive
                    ? '<span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Inactive</span>'
                      + (hasOrders ? ' <span class="badge bg-warning text-dark">' + r.active_orders + ' orders</span>' : '')
                    : '<span class="badge bg-primary">Active</span>')
                + '</td>'
                + '<td><span class="text-secondary fw-semibold" style="font-size:.82rem">' + (r.notes||'') + '</small></td>'
                + '<td>'
                + (isInactive
                    ? '<span class="text-muted small"><i class="bi bi-lock-fill me-1"></i>Locked</span>'
                    : (parseInt(r.active_orders) > 0
                        ? '<span class="badge bg-warning text-dark me-1" title="' + r.active_orders + ' active despatch orders — rate is LOCKED (cannot edit/delete)"><i class="bi bi-lock-fill"></i> ' + r.active_orders + ' orders</span>'
                        : '<button class="btn btn-action btn-outline-primary me-1" onclick="rcEdit(' + JSON.stringify(r) + ')"><i class="bi bi-pencil"></i></button>'
                          + '<button class="btn btn-action btn-outline-danger" onclick="rcDelete(' + r.id + ')"><i class="bi bi-trash"></i></button>'))
                + (parseInt(r.history_count) > 0 ? '<span class="badge bg-secondary ms-1" title="Previous rates">' + r.history_count + ' prev</span>' : '')
                + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
    });
}

function rcOpenAdd() {
    document.getElementById('rcModalTitle').textContent = 'Add Rate';
    document.getElementById('rcRateId').value = '0';
    document.getElementById('rcVendorId').value = '';
    document.getElementById('rcVendorId').style.pointerEvents = '';
    document.getElementById('rcVendorId').style.opacity = '';
    document.getElementById('rcVendorNote').style.display = 'none';
    document.getElementById('rcRate').value = '';
    document.getElementById('rcUom').value = '';
    document.getElementById('rcNotes').value = '';
    document.getElementById('rcEffectiveFrom').value = new Date().toISOString().slice(0,10);
    document.getElementById('rcSaveMsg').style.display = 'none';
    getRcModal().show();
}

function rcEdit(row) {
    // If active orders exist, block edit entirely — user must add new rate
    if (parseInt(row.active_orders) > 0) {
        alert('Cannot edit: ' + row.active_orders + ' active despatch order(s) use this rate.\n\nThis rate is LOCKED. To set a new rate for this vendor, click "Add Rate" — the old rate will remain locked for existing orders and the new rate will apply to future despatch orders.');
        return;
    }
    document.getElementById('rcModalTitle').textContent = 'Edit Rate';
    document.getElementById('rcRateId').value  = row.id;
    document.getElementById('rcVendorId').value = row.vendor_id;
    document.getElementById('rcVendorId').style.pointerEvents = 'none';
    document.getElementById('rcVendorId').style.opacity = '0.6';
    document.getElementById('rcVendorNote').style.display = 'block';
    document.getElementById('rcRate').value    = parseFloat(row.rate).toFixed(4);
    document.getElementById('rcUom').value     = row.uom || '';
    document.getElementById('rcNotes').value   = row.notes || '';
    document.getElementById('rcEffectiveFrom').value = row.effective_from || new Date().toISOString().slice(0,10);
    document.getElementById('rcSaveMsg').style.display = 'none';
    getRcModal().show();
}

function rcSave() {
    var vid  = document.getElementById('rcVendorId').value;
    var rate = document.getElementById('rcRate').value;
    var uom  = document.getElementById('rcUom').value;
    var note = document.getElementById('rcNotes').value;
    var rid  = document.getElementById('rcRateId').value;
    if (!vid || !rate || parseFloat(rate) <= 0) {
        rcMsg('danger','Vendor and Rate are required.'); return;
    }
    fetch('transporters.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_rate_action=save&transporter_id='+RC_TID+'&vendor_id='+vid+'&rate='+rate+'&uom='+encodeURIComponent(uom)+'&notes='+encodeURIComponent(note)+'&rate_id='+rid+'&effective_from='+encodeURIComponent(document.getElementById('rcEffectiveFrom').value)
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            getRcModal().hide();
            rcLoad();
        } else {
            rcMsg('danger', data.msg);
        }
    });
}

function rcDelete(rid) {
    if (!confirm('Remove this vendor rate?')) return;
    fetch('transporters.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_rate_action=delete&transporter_id='+RC_TID+'&rate_id='+rid
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            rcLoad();
        } else {
            alert(data.msg || 'Cannot delete this rate card.');
            rcLoad();
        }
    })
    .catch(() => rcLoad());
}

function rcMsg(type, msg) {
    var el = document.getElementById('rcSaveMsg');
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-'+type+' py-2 mb-0">'+msg+'</div>';
}

document.addEventListener('DOMContentLoaded', rcLoad);
</script>
<?php endif; ?>

<script>
function toggleRemoveTrDoc(chk, uploadWrapperId) {
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

function handleGSTChange() {
    var gstType    = document.getElementById('gstType').value;
    var rateWrap   = document.getElementById('gstRateWrap');
    var rcmInfo    = document.getElementById('gstRcmInfo');
    var rateLabel  = document.getElementById('gstRateLabel');
    var rateHint   = document.getElementById('gstRateHint');
    var rateInput  = document.getElementById('gstRate');

    if (gstType === 'RCM') {
        rateWrap.style.display  = 'none';
        rcmInfo.style.display   = 'block';
        rateInput.value = 0;
        rateInput.removeAttribute('required');
        rateInput.disabled = true;
    } else {
        rateWrap.style.display  = 'block';
        rcmInfo.style.display   = 'none';
        rateInput.setAttribute('required', 'required');
        rateInput.disabled = false;
        if (gstType === 'Central') {
            rateLabel.textContent = 'IGST Rate (%)';
            rateHint.textContent  = 'IGST applicable (inter-state supply)';
            rateHint.className    = 'form-text text-warning fw-semibold';
        } else {
            rateLabel.textContent = 'GST Rate (%)';
            rateHint.textContent  = 'Split equally as CGST + SGST (intra-state)';
            rateHint.className    = 'form-text text-info fw-semibold';
        }
    }
}

function handleTDSChange() {
    var tdsApplicable = document.getElementById('tdsApplicable').value;
    var tdsWrap       = document.getElementById('tdsRateWrap');
    tdsWrap.style.display = tdsApplicable === 'Yes' ? 'block' : 'none';
    if (tdsApplicable !== 'Yes') {
        document.getElementById('tdsRate').value = 0;
    }
}

// Initialise on page load
document.addEventListener('DOMContentLoaded', function() {
    handleGSTChange();
    handleTDSChange();
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

