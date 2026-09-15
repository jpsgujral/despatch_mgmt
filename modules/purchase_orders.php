<?php

require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();

/* ── Page-level view permission check ── */

requirePerm('purchase_orders', 'view');





/* ── Safe ALTER: works on MySQL 5.6+ (no IF NOT EXISTS support) ── */

function safeAddColumn($db, $table, $column, $definition) {

    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];

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



$action = $_GET['action'] ?? 'list';

$id = (int)($_GET['id'] ?? 0);

$po_status_options = ['Draft', 'Approved', 'Close PO'];

$db->query("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Draft','Approved','Close PO','Partially Received','Received','Cancelled') NOT NULL DEFAULT 'Draft'");

$po_status_labels = ['Draft' => 'Draft', 'Approved' => 'Approved', 'Close PO' => 'PO Closed'];

safeAddColumn($db, 'vendors', 'ship_name', 'VARCHAR(120) NULL');
safeAddColumn($db, 'vendors', 'ship_address', 'TEXT NULL');
safeAddColumn($db, 'vendors', 'ship_city', 'VARCHAR(60) NULL');
safeAddColumn($db, 'vendors', 'ship_state', 'VARCHAR(60) NULL');
safeAddColumn($db, 'vendors', 'ship_pincode', 'VARCHAR(10) NULL');
safeAddColumn($db, 'vendors', 'ship_gstin', 'VARCHAR(20) NULL');
safeAddColumn($db, 'vendors', 'bill_address', 'TEXT NULL');
safeAddColumn($db, 'vendors', 'bill_city', 'VARCHAR(60) NULL');
safeAddColumn($db, 'vendors', 'bill_state', 'VARCHAR(60) NULL');
safeAddColumn($db, 'vendors', 'bill_pincode', 'VARCHAR(10) NULL');



function generatePONumber($db) {

    $year = date('Y'); $month = date('m');

    $c = $db->query("SELECT COUNT(*) c FROM purchase_orders WHERE YEAR(po_date)=$year AND MONTH(po_date)=$month")->fetch_assoc()['c'] + 1;

    return "PO/{$year}/{$month}/" . str_pad($c, 4, '0', STR_PAD_LEFT);

}



if (isset($_GET['delete'])) {

    requirePerm('purchase_orders', 'delete');

    $db->query("DELETE FROM purchase_orders WHERE id=" . (int)$_GET['delete']);

    showAlert('success', 'Purchase Order deleted.');

    redirect('purchase_orders.php');

}



if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requirePerm('purchase_orders', $id > 0 ? 'update' : 'create');

    $po_number      = sanitize($_POST['po_number'] ?? generatePONumber($db));

    $po_date        = sanitize($_POST['po_date'] ?? date('Y-m-d'));

    $vendor_id      = (int)($_POST['vendor_id'] ?? 0);

    $po_company_id  = (int)($_POST['company_id'] ?? activeCompanyId());

    $validity_date  = sanitize($_POST['validity_date'] ?? '');

    $delivery_address = sanitize($_POST['delivery_address'] ?? '');

    $delivery_city    = sanitize($_POST['delivery_city']    ?? '');

    $delivery_state   = sanitize($_POST['delivery_state']   ?? '');

    $delivery_pincode = sanitize($_POST['delivery_pincode'] ?? '');

    $payment_terms  = sanitize($_POST['payment_terms'] ?? '');

    $remarks        = sanitize($_POST['remarks'] ?? '');

    $status         = sanitize($_POST['status'] ?? 'Draft');

    if (!in_array($status, $po_status_options, true)) $status = 'Draft';

    $gst_type       = sanitize($_POST['gst_type'] ?? 'IGST');



    $item_ids  = $_POST['item_id']    ?? [];

    $qtys      = $_POST['qty']        ?? [];

    $prices    = $_POST['unit_price'] ?? [];

    $gst_rates = $_POST['gst_rate']   ?? [];

    $discounts = $_POST['discount']   ?? [];



    $subtotal = 0; $cgst_total = 0; $sgst_total = 0; $igst_total = 0; $discount_total = 0;

    $valid_items = [];



    foreach ($item_ids as $idx => $iid) {

        $iid      = (int)$iid;

        $qty      = (float)($qtys[$idx]      ?? 0);

        $price    = (float)($prices[$idx]    ?? 0);

        $gst_rate = (float)($gst_rates[$idx] ?? 0);

        $disc     = (float)($discounts[$idx] ?? 0);



        if ($iid > 0 && $qty > 0) {

            $taxable  = ($qty * $price) - $disc;

            $gst_amt  = $taxable * ($gst_rate / 100);



            if ($gst_type === 'CGST+SGST') {

                $cgst = $gst_amt / 2;

                $sgst = $gst_amt / 2;

                $igst = 0;

            } else {

                $cgst = 0; $sgst = 0;

                $igst = $gst_amt;

            }

            $line_total = $taxable + $gst_amt;



            $subtotal       += $taxable;

            $cgst_total     += $cgst;

            $sgst_total     += $sgst;

            $igst_total     += $igst;

            $discount_total += $disc;

            $valid_items[]  = compact('iid','qty','price','gst_rate','gst_amt','cgst','sgst','igst','disc','taxable','line_total');

        }

    }

    $gst_total = $cgst_total + $sgst_total + $igst_total;

    $total     = $subtotal + $gst_total;



    if ($vendor_id < 1) {

        showAlert('danger', 'Please select a vendor.');

    } else {

        // Ensure columns exist (MySQL 5.6+ safe)

        safeAddColumn($db, 'purchase_orders', 'gst_type',      "VARCHAR(20) DEFAULT 'IGST'");

        safeAddColumn($db, 'purchase_orders', 'cgst_amount',   'DECIMAL(12,2) DEFAULT 0');

        safeAddColumn($db, 'purchase_orders', 'sgst_amount',   'DECIMAL(12,2) DEFAULT 0');

        safeAddColumn($db, 'purchase_orders', 'igst_amount',   'DECIMAL(12,2) DEFAULT 0');

        safeAddColumn($db, 'purchase_orders', 'validity_date',    'DATE NULL');

        safeAddColumn($db, 'purchase_orders', 'delivery_city',    'VARCHAR(60) NULL');

        safeAddColumn($db, 'purchase_orders', 'delivery_state',   'VARCHAR(60) NULL');

        safeAddColumn($db, 'purchase_orders', 'delivery_pincode', 'VARCHAR(10) NULL');

        safeAddColumn($db, 'purchase_orders', 'po_scan', "VARCHAR(255) DEFAULT ''");

        safeAddColumn($db, 'po_items', 'cgst_rate',     'DECIMAL(5,2) DEFAULT 0');

        safeAddColumn($db, 'po_items', 'cgst_amount',   'DECIMAL(12,2) DEFAULT 0');

        safeAddColumn($db, 'po_items', 'sgst_rate',     'DECIMAL(5,2) DEFAULT 0');

        safeAddColumn($db, 'po_items', 'sgst_amount',   'DECIMAL(12,2) DEFAULT 0');

        safeAddColumn($db, 'po_items', 'igst_rate',     'DECIMAL(5,2) DEFAULT 0');

        safeAddColumn($db, 'po_items', 'igst_amount',   'DECIMAL(12,2) DEFAULT 0');

        safeAddColumn($db, 'po_items', 'taxable_amount','DECIMAL(12,2) DEFAULT 0');



        $vd_sql = $validity_date ? "'$validity_date'" : 'NULL';

        if ($id > 0) {

            $db->query("UPDATE purchase_orders SET

                po_number='$po_number', po_date='$po_date', vendor_id=$vendor_id,

                company_id=$po_company_id,

                validity_date=$vd_sql,

                delivery_address='$delivery_address',

                delivery_city='$delivery_city', delivery_state='$delivery_state',

                delivery_pincode='$delivery_pincode',

                payment_terms='$payment_terms', subtotal=$subtotal,

                gst_type='$gst_type', cgst_amount=$cgst_total, sgst_amount=$sgst_total,

                igst_amount=$igst_total, gst_amount=$gst_total,

                discount=$discount_total, total_amount=$total, status='$status', remarks='$remarks'

                WHERE id=$id");

            $db->query("DELETE FROM po_items WHERE po_id=$id");

        } else {

            $db->query("INSERT INTO purchase_orders

                (po_number,po_date,vendor_id,company_id,validity_date,delivery_address,

                 delivery_city,delivery_state,delivery_pincode,payment_terms,

                 subtotal,gst_type,cgst_amount,sgst_amount,igst_amount,gst_amount,

                 discount,total_amount,status,remarks)

                VALUES

                ('$po_number','$po_date',$vendor_id,$po_company_id,$vd_sql,

                 '$delivery_address','$delivery_city','$delivery_state','$delivery_pincode',

                 '$payment_terms',$subtotal,'$gst_type',$cgst_total,$sgst_total,$igst_total,

                 $gst_total,$discount_total,$total,'$status','$remarks')");

            $id = $db->insert_id;

        }



        foreach ($valid_items as $vi) {

            $cr = $vi['cgst'] > 0 ? $vi['gst_rate']/2 : 0;

            $sr = $vi['sgst'] > 0 ? $vi['gst_rate']/2 : 0;

            $ir = $vi['igst'] > 0 ? $vi['gst_rate']   : 0;

            $db->query("INSERT INTO po_items

                (po_id,item_id,qty,unit_price,taxable_amount,gst_rate,gst_amount,

                 cgst_rate,cgst_amount,sgst_rate,sgst_amount,igst_rate,igst_amount,

                 discount,total_price)

                VALUES

                ($id,{$vi['iid']},{$vi['qty']},{$vi['price']},{$vi['taxable']},

                 {$vi['gst_rate']},{$vi['gst_amt']},

                 $cr,{$vi['cgst']},$sr,{$vi['sgst']},$ir,{$vi['igst']},

                 {$vi['disc']},{$vi['line_total']})");

        }

        // Handle PO scan — load current value fresh from DB

        $po_scan_row = $db->query("SELECT po_scan FROM purchase_orders WHERE id=$id")->fetch_assoc();

        $po_scan_val = $po_scan_row['po_scan'] ?? '';

        // Remove first (so upload can replace cleanly)

        if (!empty($_POST['remove_po_scan']) && !empty($po_scan_val)) {

            r2_delete($po_scan_val);

            $db->query("UPDATE purchase_orders SET po_scan='' WHERE id=$id");

            $po_scan_val = '';

        }

        // Upload new scan → Cloudflare R2

        $new_scan = r2_handle_upload('po_scan', 'po_scans/PO_' . $id, $po_scan_val);

        if ($new_scan !== '') {

            $po_scan_val = $db->real_escape_string($new_scan);

            $db->query("UPDATE purchase_orders SET po_scan='$po_scan_val' WHERE id=$id");

        }

        showAlert('success', 'Purchase Order saved successfully.');

        redirect('purchase_orders.php');

    }

}



$po       = [];

$po_items = [];

if (($action == 'edit') && $id > 0) {

    $po       = $db->query("SELECT * FROM purchase_orders WHERE id=$id")->fetch_assoc();

    $po_items = $db->query("SELECT pi.*, i.item_name, i.uom FROM po_items pi JOIN items i ON pi.item_id=i.id WHERE pi.po_id=$id")->fetch_all(MYSQLI_ASSOC);

}



$vendors    = $db->query("SELECT id,vendor_code,vendor_name,

    ship_name,ship_address,ship_city,ship_state,ship_pincode,ship_gstin,

    bill_address,bill_city,bill_state,bill_pincode,

    address,city,state,pincode

    FROM vendors WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);

$items_list = $db->query("SELECT id,item_code,item_name,uom FROM items WHERE status='Active' ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);



$all_companies = $db->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);



include '../includes/header.php';

?>

<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-file-earmark-text me-2"></i>Purchase Orders';</script>



<?php if ($action == 'list'): ?>

<div class="d-flex justify-content-between align-items-center mb-3">

    <h5 class="mb-0 fw-bold">All Purchase Orders</h5>

    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New Purchase Order</a>

</div>

<div class="card">

<div class="card-body p-0">

<?php

$filter_status = sanitize($_GET['status'] ?? 'Approved');

$allowed_po_filters = ['All', 'Draft', 'Approved', 'Close PO'];

if (!in_array($filter_status, $allowed_po_filters, true)) $filter_status = 'Approved';

// Fetch all POs grouped by company

$all_pos = $db->query("SELECT p.*,v.vendor_name,c.company_name AS co_name,
    COALESCE((SELECT SUM(pi.qty) FROM po_items pi WHERE pi.po_id=p.id),0) AS total_qty,
    COALESCE((SELECT SUM(d.total_weight) FROM despatch_orders d WHERE d.po_id=p.id AND d.status NOT IN ('Cancelled','Draft')),0) AS despatched_qty
    FROM purchase_orders p
    LEFT JOIN vendors v ON p.vendor_id=v.id
    LEFT JOIN companies c ON p.company_id=c.id
    ORDER BY c.company_name, p.po_date DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC);

$po_challans = [];
$po_ids = array_map('intval', array_column($all_pos, 'id'));
if (!empty($po_ids)) {
    $challan_rows = $db->query("SELECT d.id, d.po_id, d.challan_no, d.despatch_date, d.consignee_name,
            d.consignee_city, d.total_weight, d.transporter_rate_per_mt, d.freight_amount, d.status, t.transporter_name
        FROM despatch_orders d
        LEFT JOIN transporters t ON d.transporter_id = t.id
        WHERE d.po_id IN (" . implode(',', $po_ids) . ")
          AND COALESCE(d.challan_no, '') <> ''
          AND d.status <> 'Draft'
        ORDER BY d.despatch_date DESC, d.id DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($challan_rows as $cr) {
        $po_challans[(int)$cr['po_id']][] = $cr;
    }
}

// Group by company
$grouped = [];
foreach ($all_pos as $row) {
    if ($filter_status !== 'All' && $row['status'] !== $filter_status) continue;

    $co = $row['co_name'] ?: 'General';

    $grouped[$co][] = $row;

}

$badge_map = ['Draft'=>'secondary','Approved'=>'success','Close PO'=>'dark','Partially Received'=>'warning','Received'=>'info','Cancelled'=>'danger'];

$status_counts = ['All' => count($all_pos), 'Draft' => 0, 'Approved' => 0, 'Close PO' => 0];

foreach ($all_pos as $row) {

    if (isset($status_counts[$row['status']])) $status_counts[$row['status']]++;

}

?>

<div class="d-flex flex-wrap gap-2 px-3 pt-3">
    <?php

    $base_url = 'purchase_orders.php';

    foreach (['All', 'Draft', 'Approved', 'Close PO'] as $st):

        $active = $filter_status === $st ? 'active' : '';

        $bc = $st === 'All' ? 'dark' : ($badge_map[$st] ?? 'secondary');

        $url = $st === 'All' ? $base_url : $base_url . '?status=' . urlencode($st);

        $label = $st === 'Close PO' ? 'Closed PO' : $st;

    ?>

    <a href="<?= $url ?>" class="btn btn-sm btn-<?= $active ? '' : 'outline-' ?><?= $bc ?> <?= $active ?>">

        <?= $label ?> <span class="badge bg-<?= $active ? 'light text-dark' : $bc ?> ms-1"><?= $status_counts[$st] ?? 0 ?></span>

    </a>

    <?php endforeach; ?>

</div>

<!-- Search -->

<div class="mb-3 px-3 pt-3">
    <input type="text" id="poSearch" class="form-control" placeholder="🔍 Search PO number, vendor, status…" onkeyup="filterPOs(this.value)">
</div>
<style>
.po-expand-btn {
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 10px;
}
.po-expand-btn i {
    font-size: .95rem;
    line-height: 1;
}
.po-challan-cell {
    background: #f8fbf9;
    border-top: none !important;
    padding: 0 !important;
}
.po-challan-wrap {
    padding: 12px 14px;
}
.po-challan-meta {
    font-size: .83rem;
    color: #5f6b63;
    margin-bottom: 10px;
}
.po-challan-table {
    margin-bottom: 0;
}
.po-challan-table th {
    font-size: .74rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #58705f;
    background: #edf6f0;
    white-space: nowrap;
}
.po-challan-table td {
    vertical-align: middle;
    font-size: .88rem;
}
@media (max-width: 576px) {
    .po-challan-wrap {
        padding: 10px 10px 12px;
    }
    .po-challan-table th,
    .po-challan-table td {
        font-size: .8rem;
    }
}
</style>
<?php $ci=0; foreach ($grouped as $co_name => $pos): $ci++;
    $co_id    = 'pco_'.$ci;
    $co_total = array_sum(array_column($pos,'total_qty'));
    $co_bal   = array_sum(array_map(fn($p)=>max(0,(float)$p['total_qty']-(float)$p['despatched_qty']),$pos));

?>

<div class="card mb-3 po-co-card" id="<?= $co_id ?>">

    <div class="card-header d-flex align-items-center gap-2 py-2"

         style="cursor:pointer;background:#1E3A8A;color:#fff"

         onclick="togglePOGroup('<?= $co_id ?>')">

        <i class="bi bi-chevron-down" id="pchev_<?= $co_id ?>"></i>

        <i class="bi bi-buildings me-1"></i>

        <strong><?= htmlspecialchars($co_name) ?></strong>

        <span class="badge bg-light text-dark ms-1"><?= count($pos) ?></span>

        <span class="ms-auto small opacity-75"><?= number_format($co_bal,3) ?> MT bal</span>

    </div>

    <div id="pbody_<?= $co_id ?>" class="card-body p-0">

    <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
    <thead class="table-light">
        <tr>
            <th style="width:52px"></th>
            <th>#</th><th>PO Number</th><th>Date</th><th>Vendor</th>
            <th class="text-end">Vendor Rate</th><th>Status</th><th class="text-end">PO Qty</th>
            <th class="text-end">Despatched</th><th class="text-end">Balance</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php $i=1; foreach ($pos as $v):
        $b       = $badge_map[$v['status']] ?? 'secondary';
        $po_qty  = (float)$v['total_qty'];
        $desp    = (float)$v['despatched_qty'];
        $bal     = max(0, $po_qty - $desp);
        $pct     = $po_qty > 0 ? min(100, round(($desp/$po_qty)*100)) : 0;
        $bal_cls = $bal<=0 ? 'text-danger fw-bold' : ($pct>=80 ? 'text-warning fw-bold' : 'text-success fw-bold');
        $linked_challans = $po_challans[(int)$v['id']] ?? [];
        $linked_count = count($linked_challans);
        $vendor_rate = $po_qty > 0 ? ((float)($v['subtotal'] ?? 0) / $po_qty) : 0;
    ?>
    <tr class="po-row">
        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-secondary po-expand-btn"
                    onclick="togglePOChallans(<?= (int)$v['id'] ?>, this)"
                    aria-expanded="false"
                    title="View delivery challans">
                <i class="bi bi-plus-lg"></i>
            </button>
        </td>
        <td class="text-muted small"><?= $i++ ?></td>
        <td><a href="?action=view&id=<?= $v['id'] ?>" class="fw-bold text-primary text-decoration-none"><?= htmlspecialchars($v['po_number']) ?></a></td>
        <td class="text-muted small"><?= date('d/m/Y', strtotime($v['po_date'])) ?></td>
        <td><span class="fw-semibold text-dark"><?= htmlspecialchars($v['vendor_name']) ?></span></td>
        <td class="text-end"><span class="text-dark fw-semibold">₹<?= number_format($vendor_rate,2) ?></span></td>
        <td><span class="badge bg-<?= $b ?>"><?= htmlspecialchars($po_status_labels[$v['status']] ?? $v['status']) ?></span></td>
        <td class="text-end"><span class="text-primary fw-semibold"><?= number_format($po_qty,3) ?></span></td>
        <td class="text-end"><span class="text-info fw-semibold"><?= number_format($desp,3) ?></span></td>
        <td class="text-end">

            <span class="<?= $bal_cls ?>"><?= number_format($bal,3) ?></span>

            <?php if ($po_qty>0): ?>

            <div class="progress mt-1" style="height:4px;min-width:50px">

                <div class="progress-bar bg-<?= $pct>=100?'danger':($pct>=80?'warning':'success') ?>"

                     style="width:<?= $pct ?>%"></div>

            </div>

            <?php endif; ?>

        </td>

        <td>

            <a href="?action=view&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-info me-1"><i class="bi bi-eye"></i></a>

            <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>

            <button onclick="confirmDelete(<?= $v['id'] ?>,'purchase_orders.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
        </td>
    </tr>
    <tr class="po-challan-row d-none" id="po-challans-<?= (int)$v['id'] ?>">
        <td colspan="11" class="po-challan-cell">
            <div class="po-challan-wrap">
                <?php if ($linked_count > 0): ?>
                    <div class="po-challan-meta">
                        <?= $linked_count ?> delivery challan<?= $linked_count === 1 ? '' : 's' ?> issued against this purchase order.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered po-challan-table">
                            <thead>
                                <tr>
                                    <th>Challan No</th>
                                    <th>Date</th>
                                    <th>Consignee</th>
                                    <th>Transporter</th>
                                    <th class="text-end">Freight Rate</th>
                                    <th class="text-end">Weight (MT)</th>
                                    <th>Status</th>
                                    <th style="width:70px">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linked_challans as $ch): ?>
                                <tr>
                                    <td><strong class="text-primary"><?= htmlspecialchars($ch['challan_no']) ?></strong></td>
                                    <td><?= !empty($ch['despatch_date']) ? date('d/m/Y', strtotime($ch['despatch_date'])) : '-' ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($ch['consignee_name'] ?: '-') ?></div>
                                        <?php if (!empty($ch['consignee_city'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($ch['consignee_city']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($ch['transporter_name'] ?: '-') ?></td>
                                    <td class="text-end fw-semibold">₹<?= number_format((float)$ch['transporter_rate_per_mt'], 2) ?>/MT</td>
                                    <td class="text-end fw-semibold"><?= number_format((float)$ch['total_weight'], 3) ?></td>
                                    <td><span class="badge bg-<?= $badge_map[$ch['status']] ?? 'secondary' ?>"><?= htmlspecialchars($ch['status']) ?></span></td>
                                    <td>
                                        <a href="print_challan.php?id=<?= (int)$ch['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Open Challan">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="po-challan-meta mb-0">
                        No delivery challan has been issued against this purchase order yet.
                    </div>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>

    </div>

</div>

<?php endforeach; ?>



<script>
function togglePOGroup(id) {
    var body = document.getElementById('pbody_'+id);

    var chev = document.getElementById('pchev_'+id);

    var open = body.style.display !== 'none';

    body.style.display = open ? 'none' : '';
    chev.className = open ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
}
function togglePOChallans(id, btn) {
    var row = document.getElementById('po-challans-' + id);
    if (!row) return;
    row.classList.toggle('d-none');
    var expanded = !row.classList.contains('d-none');
    btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    var icon = btn.querySelector('i');
    if (icon) {
        icon.className = expanded ? 'bi bi-dash-lg' : 'bi bi-plus-lg';
    }
    btn.classList.toggle('btn-outline-secondary', !expanded);
    btn.classList.toggle('btn-outline-success', expanded);
}
function filterPOs(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.po-row').forEach(function(tr) {
        var txt = tr.innerText.toLowerCase();
        tr.style.display = txt.includes(q) ? '' : 'none';
        var detailRow = tr.nextElementSibling;
        if (detailRow && detailRow.classList.contains('po-challan-row') && !txt.includes(q)) {
            detailRow.classList.add('d-none');
            var btn = tr.querySelector('.po-expand-btn');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-outline-secondary');
                var icon = btn.querySelector('i');
                if (icon) icon.className = 'bi bi-plus-lg';
            }
        }
    });
    // Show all company cards when searching
    document.querySelectorAll('.po-co-card').forEach(function(card) {
        var id = card.id;

        document.getElementById('pbody_'+id).style.display = '';

        document.getElementById('pchev_'+id).className = 'bi bi-chevron-down';

    });

}

</script>

</div></div>



<?php elseif ($action == 'view' && $id > 0): ?>

<?php

$po           = $db->query("SELECT p.*,v.vendor_name,v.address as v_address,v.city as v_city,v.gstin as v_gstin FROM purchase_orders p LEFT JOIN vendors v ON p.vendor_id=v.id WHERE p.id=$id")->fetch_assoc();

$po_items_view = $db->query("SELECT pi.*,i.item_name,i.uom,i.hsn_code FROM po_items pi JOIN items i ON pi.item_id=i.id WHERE pi.po_id=$id")->fetch_all(MYSQLI_ASSOC);

$gst_type     = $po['gst_type'] ?? 'IGST';

$is_split     = ($gst_type === 'CGST+SGST');

?>

<div class="d-flex justify-content-between align-items-center mb-3">

    <h5 class="mb-0 fw-bold">PO Details: <?= htmlspecialchars($po['po_number']) ?></h5>

    <div>

        <a href="?action=edit&id=<?= $id ?>" class="btn btn-outline-primary btn-sm me-2"><i class="bi bi-pencil me-1"></i>Edit</a>

        <a href="purchase_orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>

    </div>

</div>

<div class="card mb-3">

<div class="card-body">

<div class="row">

    <div class="col-12 col-md-6">

        <table class="table table-borderless table-sm">

            <tr><td class="text-muted">PO Number:</td><td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td></tr>

            <tr><td class="text-muted">PO Date:</td><td><?= date('d/m/Y', strtotime($po['po_date'])) ?></td></tr>

            <tr><td class="text-muted">Vendor:</td><td><?= htmlspecialchars($po['vendor_name']) ?></td></tr>

            <tr><td class="text-muted">GSTIN:</td><td><code><?= htmlspecialchars($po['v_gstin']) ?></code></td></tr>

            <tr><td class="text-muted">GST Type:</td>

                <td><span class="badge bg-<?= $is_split?'info':'warning' ?> text-dark fs-6"><?= htmlspecialchars($gst_type) ?></span></td></tr>

        </table>

    </div>

    <div class="col-12 col-md-6">

        <table class="table table-borderless table-sm">

            <tr><td class="text-muted">Status:</td>

                <td><?php $b=['Draft'=>'secondary','Approved'=>'success','Close PO'=>'dark','Partially Received'=>'warning','Received'=>'info','Cancelled'=>'danger']; ?>

                    <span class="badge bg-<?= $b[$po['status']]??'secondary' ?>"><?= htmlspecialchars($po_status_labels[$po['status']] ?? $po['status']) ?></span></td></tr>

            <tr><td class="text-muted">Validity Date:</td><td><?= ($po['validity_date']??'') ? date('d/m/Y', strtotime($po['validity_date'])) : '-' ?></td></tr>

            <tr><td class="text-muted">Payment Terms:</td><td><?= htmlspecialchars($po['payment_terms']) ?></td></tr>

            <tr><td class="text-muted">Subtotal:</td><td>₹<?= number_format($po['subtotal'],2) ?></td></tr>

            <tr><td class="text-muted">Grand Total:</td><td><strong class="text-primary fs-6">&#8377;<?= number_format($po['total_amount'],2) ?></strong></td></tr>

            <?php if (!empty($po['po_scan'])): ?>

            <tr><td class="text-muted">PO Scan:</td><td>

                <a href="<?= htmlspecialchars(r2_url($po['po_scan'])) ?>" target="_blank"

                   class="btn btn-sm btn-outline-primary">

                    <i class="bi bi-eye me-1"></i>View Scan Copy

                </a>

            </td></tr>

            <?php endif; ?>

        </table>

    </div>

</div>

<?php

$da = trim(($po['delivery_address']??'').' '.($po['delivery_city']??'').' '.($po['delivery_state']??'').' '.($po['delivery_pincode']??''));

if ($da): ?>

<div class="row mt-2">

    <div class="col-12">

        <div class="alert alert-success py-2 mb-0 d-flex gap-3 align-items-start">

            <i class="bi bi-truck fs-5 mt-1"></i>

            <div>

                <div class="fw-semibold small text-uppercase mb-1" style="letter-spacing:.5px">Delivery Address</div>

                <?= nl2br(htmlspecialchars($po['delivery_address']??'')) ?>

                <?php if ($po['delivery_city']??''): ?>

                , <?= htmlspecialchars($po['delivery_city']) ?>

                <?php endif; ?>

                <?php if ($po['delivery_state']??''): ?>

                — <?= htmlspecialchars($po['delivery_state']) ?>

                <?php endif; ?>

                <?php if ($po['delivery_pincode']??''): ?>

                &nbsp;<?= htmlspecialchars($po['delivery_pincode']) ?>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

<?php endif; ?>

</div>

</div>



<div class="card">

<div class="card-header"><i class="bi bi-list-ul me-2"></i>Order Items — GST Type: <span class="badge bg-<?= $is_split?'info':'warning' ?> text-dark ms-1"><?= htmlspecialchars($gst_type) ?></span></div>

<div class="card-body p-0">

<div class="table-responsive">

<table class="table mb-0">

    <thead class="table-light"><tr>

        <th>#</th><th>Item</th><th>HSN</th><th>UOM</th><th>Qty</th><th>Unit Price</th><th>Taxable Amt</th>

        <?php if ($is_split): ?>

        <th>CGST %</th><th>CGST Amt</th><th>SGST %</th><th>SGST Amt</th>

        <?php else: ?>

        <th>IGST %</th><th>IGST Amt</th>

        <?php endif; ?>

        <th>Total</th>

    </tr></thead>

    <tbody>

    <?php foreach($po_items_view as $i=>$item): ?>

    <tr>

        <td><?= $i+1 ?></td>

        <td><?= htmlspecialchars($item['item_name']) ?></td>

        <td><?= htmlspecialchars($item['hsn_code']) ?></td>

        <td><?= $item['uom'] ?></td>

        <td><?= number_format((float)$item['qty'], uomDecimals($item['uom'])) ?></td>

        <td>₹<?= number_format($item['unit_price'],2) ?></td>

        <td>₹<?= number_format($item['taxable_amount'] ?? (($item['qty']*$item['unit_price'])-$item['discount']),2) ?></td>

        <?php if ($is_split): ?>

        <td><?= $item['cgst_rate'] ?? $item['gst_rate']/2 ?>%</td>

        <td>₹<?= number_format($item['cgst_amount'] ?? $item['gst_amount']/2,2) ?></td>

        <td><?= $item['sgst_rate'] ?? $item['gst_rate']/2 ?>%</td>

        <td>₹<?= number_format($item['sgst_amount'] ?? $item['gst_amount']/2,2) ?></td>

        <?php else: ?>

        <td><?= $item['igst_rate'] ?? $item['gst_rate'] ?>%</td>

        <td>₹<?= number_format($item['igst_amount'] ?? $item['gst_amount'],2) ?></td>

        <?php endif; ?>

        <td><strong>₹<?= number_format($item['total_price'],2) ?></strong></td>

    </tr>

    <?php endforeach; ?>

    </tbody>

    <tfoot class="table-light fw-bold">

        <tr>

            <td colspan="6" class="text-end">Subtotal:</td>

            <td>₹<?= number_format($po['subtotal'],2) ?></td>

            <?php if ($is_split): ?>

            <td></td><td>₹<?= number_format($po['cgst_amount']??0,2) ?></td>

            <td></td><td>₹<?= number_format($po['sgst_amount']??0,2) ?></td>

            <?php else: ?>

            <td></td><td>₹<?= number_format($po['igst_amount']??$po['gst_amount'],2) ?></td>

            <?php endif; ?>

            <td></td>

        </tr>

        <tr>

            <td colspan="<?= $is_split ? 11 : 9 ?>" class="text-end">

                <?php if ($is_split): ?>

                CGST: ₹<?= number_format($po['cgst_amount']??0,2) ?> &nbsp;|&nbsp;

                SGST: ₹<?= number_format($po['sgst_amount']??0,2) ?> &nbsp;|&nbsp;

                Total Tax: ₹<?= number_format(($po['cgst_amount']??0)+($po['sgst_amount']??0),2) ?>

                <?php else: ?>

                IGST: ₹<?= number_format($po['igst_amount']??$po['gst_amount'],2) ?>

                <?php endif; ?>

            </td>

            <td class="text-primary fs-6">₹<?= number_format($po['total_amount'],2) ?></td>

        </tr>

    </tfoot>

</table>

</div></div></div>



<?php else: // ADD / EDIT FORM ?>

<div class="d-flex justify-content-between align-items-center mb-3">

    <h5 class="mb-0 fw-bold"><?= $action=='edit'?'Edit':'New' ?> Purchase Order</h5>

    <a href="purchase_orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>

</div>

<form method="POST" id="poForm" enctype="multipart/form-data">

<div class="row g-3">



    <!-- Header Info -->

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Order Information</div>

    <div class="card-body"><div class="row g-3">

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label">PO Number *</label>

            <input type="text" name="po_number" class="form-control" required value="<?= htmlspecialchars($po['po_number'] ?? generatePONumber($db)) ?>">

        </div>

        <div class="col-6 col-sm-4 col-md-2">

            <label class="form-label">PO Date *</label>

            <input type="date" name="po_date" class="form-control" required value="<?= $po['po_date'] ?? date('Y-m-d') ?>">

        </div>

        <?php if (count($all_companies) > 1): ?>

        <div class="col-12 col-sm-6 col-md-3">

            <label class="form-label">Company *</label>

            <select name="company_id" class="form-select" required>

                <?php foreach ($all_companies as $co): ?>

                <option value="<?= $co['id'] ?>"

                    <?= ($po['company_id'] ?? activeCompanyId()) == $co['id'] ? 'selected' : '' ?>>

                    <?= htmlspecialchars($co['company_name']) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>

        <?php else: ?>

        <input type="hidden" name="company_id" value="<?= activeCompanyId() ?>">

        <?php endif; ?>

        <div class="col-12 col-sm-6 col-md-4">

            <label class="form-label">Vendor *</label>

            <select name="vendor_id" id="poVendorSelect" class="form-select" required onchange="fillDeliveryFromVendor(this)">

                <option value="">-- Select Vendor --</option>

                <?php foreach($vendors as $v): ?>

                <option value="<?= $v['id'] ?>" <?= ($po['vendor_id']??0)==$v['id']?'selected':'' ?>>

                    <?= htmlspecialchars($v['vendor_code'].' - '.$v['vendor_name']) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label">GST Type *

                <span class="ms-1" data-bs-toggle="tooltip"

                    title="IGST = Inter-state supply | CGST+SGST = Intra-state supply">

                    <i class="bi bi-info-circle text-primary"></i>

                </span>

            </label>

            <select name="gst_type" id="gstType" class="form-select" onchange="updateGSTMode()" required>

                <option value="IGST"      <?= ($po['gst_type']??'IGST')==='IGST'      ?'selected':'' ?>>IGST (Inter-State)</option>

                <option value="CGST+SGST" <?= ($po['gst_type']??'')==='CGST+SGST'     ?'selected':'' ?>>CGST + SGST (Intra-State)</option>

            </select>

        </div>

        <div class="col-6 col-sm-4 col-md-2">

            <label class="form-label">Validity Date</label>

            <input type="date" name="validity_date" class="form-control" value="<?= $po['validity_date'] ?? '' ?>">

        </div>

        <div class="col-6 col-sm-4 col-md-2">

            <label class="form-label">Status</label>

            <select name="status" class="form-select">

                <?php foreach($po_status_options as $s): ?>

                <option value="<?= $s ?>" <?= ($po['status']??'Draft')==$s?'selected':'' ?>><?= $s ?></option>

                <?php endforeach; ?>

                <?php if (!empty($po['status']) && !in_array($po['status'], $po_status_options, true)): ?>

                <option value="<?= htmlspecialchars($po['status']) ?>" selected hidden><?= htmlspecialchars($po['status']) ?></option>

                <?php endif; ?>

            </select>

        </div>

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label">Payment Terms</label>

            <input type="text" name="payment_terms" class="form-control" placeholder="e.g. Net 30 Days" value="<?= htmlspecialchars($po['payment_terms']??'') ?>">

        </div>

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label">Remarks</label>

            <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($po['remarks']??'') ?>">

        </div>

        <div class="col-6 col-sm-4 col-md-3">

            <label class="form-label"><i class="bi bi-file-earmark-image me-1 text-primary"></i>PO Scan Copy</label>

            <?php $po_scan = $po['po_scan'] ?? ''; ?>

            <?php if (!empty($po_scan)): ?>

            <div class="d-flex align-items-center gap-1 mb-1 flex-wrap">

                <span class="badge bg-primary"><i class="bi bi-check2"></i> Uploaded</span>

                <a href="<?= htmlspecialchars(r2_url($po_scan)) ?>" target="_blank"

                   class="btn btn-sm btn-outline-primary py-0">

                    <i class="bi bi-eye me-1"></i>View

                </a>

                <div class="form-check mb-0 ms-auto">

                    <input class="form-check-input border-danger" type="checkbox"

                           name="remove_po_scan" value="1" id="rmPoScan">

                    <label class="form-check-label text-danger small fw-semibold" for="rmPoScan">

                        <i class="bi bi-trash"></i> Remove

                    </label>

                </div>

            </div>

            <?php endif; ?>

            <input type="file" name="po_scan" class="form-control form-control-sm"

                   accept=".pdf,.jpg,.jpeg,.png">

            <div class="form-text"><?= empty($po_scan) ? 'PDF/image, max 10MB' : 'Upload to replace' ?></div>

        </div>

    </div></div></div></div>



    <!-- Delivery Address -->

    <div class="col-12"><div class="card border-primary">

    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">

        <span><i class="bi bi-truck me-2"></i>Delivery Address

            <small class="ms-2 opacity-75">(Ship-To / Delivery Location)</small>

        </span>

        <span id="deliveryAutofillBadge" class="badge bg-white text-primary d-none">

            <i class="bi bi-magic me-1"></i>Auto-filled from Vendor

        </span>

    </div>

    <div class="card-body">

        <div id="deliveryAutofillNotice" style="display:none" class="alert alert-success alert-dismissible py-2 mb-3 d-flex align-items-center gap-2">

            <i class="bi bi-check-circle-fill"></i>

            <span>Delivery address auto-filled from <strong id="deliveryVendorName"></strong>'s Ship-To address. You can edit any field.</span>

            <button type="button" class="btn-close ms-auto" onclick="document.getElementById('deliveryAutofillNotice').style.display='none'"></button>

        </div>

        <div class="row g-2">

            <div class="col-12">

                <label class="form-label">Street / Area</label>

                <textarea name="delivery_address" id="da_address" class="form-control" rows="2"><?= htmlspecialchars($po['delivery_address']??'') ?></textarea>

            </div>

            <div class="col-12 col-sm-5">

                <label class="form-label">City</label>

                <input type="text" name="delivery_city" id="da_city" class="form-control" value="<?= htmlspecialchars($po['delivery_city']??'') ?>">

            </div>

            <div class="col-12 col-sm-4">

                <label class="form-label">State</label>

                <input type="text" name="delivery_state" id="da_state" class="form-control" value="<?= htmlspecialchars($po['delivery_state']??'') ?>">

            </div>

            <div class="col-6 col-sm-3">

                <label class="form-label">Pincode</label>

                <input type="text" name="delivery_pincode" id="da_pincode" class="form-control" value="<?= htmlspecialchars($po['delivery_pincode']??'') ?>">

            </div>

            <div class="col-12 text-end">

                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearDeliveryAddr()">

                    <i class="bi bi-eraser me-1"></i>Clear

                </button>

            </div>

        </div>

    </div>

    </div></div>



    <!-- Items Table -->

    <div class="col-12"><div class="card">

    <div class="card-header d-flex justify-content-between align-items-center">

        <span><i class="bi bi-list-ul me-2"></i>Order Items

            <span id="gstTypeBadge" class="badge ms-2 bg-warning text-dark">IGST</span>

        </span>

        <button type="button" class="btn btn-sm btn-light" onclick="addItemRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>

    </div>

    <div class="card-body p-0"><div class="table-responsive">

    <table class="table mb-0" id="itemsTable">

        <thead class="table-light" id="itemsHead">

            <tr>

                <th style="min-width:200px">Item</th>

                <th style="width:70px">UOM</th>

                <th style="width:80px">Qty</th>

                <th style="width:100px">Unit Price</th>

                <th style="width:75px">GST %</th>

                <!-- IGST mode: 2 cols -->

                <th class="gst-col igst-col text-warning" style="width:75px">IGST %</th>

                <th class="gst-col igst-col text-warning" style="width:90px">IGST Amt</th>

                <!-- CGST+SGST mode: 4 cols -->

                <th class="gst-col split-col text-info" style="width:70px">CGST %</th>

                <th class="gst-col split-col text-info" style="width:90px">CGST Amt</th>

                <th class="gst-col split-col text-info" style="width:70px">SGST %</th>

                <th class="gst-col split-col text-info" style="width:90px">SGST Amt</th>

                <th style="width:90px">Discount</th>

                <th style="width:100px">Line Total</th>

                <th style="width:36px"></th>

            </tr>

        </thead>

        <tbody id="itemsBody">

        <?php

        $edit_rows = !empty($po_items) ? $po_items : [null];

        foreach($edit_rows as $pi):

            $pi_igst_rate  = $pi ? number_format($pi['igst_rate']  ?? $pi['gst_rate'],    2) : '';

            $pi_igst_amt   = $pi ? number_format($pi['igst_amount']?? $pi['gst_amount'],  2) : '';

            $pi_cgst_rate  = $pi ? number_format($pi['cgst_rate']  ?? $pi['gst_rate']/2,  2) : '';

            $pi_cgst_amt   = $pi ? number_format($pi['cgst_amount']?? $pi['gst_amount']/2,2) : '';

            $pi_sgst_rate  = $pi ? number_format($pi['sgst_rate']  ?? $pi['gst_rate']/2,  2) : '';

            $pi_sgst_amt   = $pi ? number_format($pi['sgst_amount']?? $pi['gst_amount']/2,2) : '';

        ?>

        <tr>

            <td>

                <select name="item_id[]" class="form-select form-select-sm item-select" onchange="fillItemDetails(this)">

                    <option value="">-- Select Item --</option>

                    <?php foreach($items_list as $il): ?>

                    <option value="<?= $il['id'] ?>"

                        data-uom="<?= $il['uom'] ?>"



                        <?= $pi && $pi['item_id']==$il['id']?'selected':'' ?>>

                        <?= htmlspecialchars($il['item_code'].' - '.$il['item_name']) ?>

                    </option>

                    <?php endforeach; ?>

                </select>

            </td>

            <td><input type="text"   name="uom[]"        class="form-control form-control-sm" value="<?= htmlspecialchars($pi['uom']??'') ?>" readonly tabindex="-1"></td>

            <td><input type="number" name="qty[]"        class="form-control form-control-sm calc-field" value="<?= $pi['qty']??'' ?>"       step="0.001" min="0.001" onchange="calcRow(this)" <?= !$pi?'':'required' ?>></td>

            <td><input type="number" name="unit_price[]" class="form-control form-control-sm calc-field" value="<?= $pi['unit_price']??'' ?>" step="0.01"  onchange="calcRow(this)"></td>

            <td><input type="number" name="gst_rate[]"   class="form-control form-control-sm calc-field" value="<?= $pi['gst_rate']??'' ?>"  step="0.01"  onchange="calcRow(this)"></td>

            <!-- IGST cols -->

            <td class="gst-col igst-col"><input type="text" class="form-control form-control-sm bg-light text-warning fw-semibold row-igst-rate" value="<?= $pi_igst_rate ?>" readonly tabindex="-1"></td>

            <td class="gst-col igst-col"><input type="text" class="form-control form-control-sm bg-light text-warning fw-semibold row-igst-amt"  value="<?= $pi_igst_amt  ?>" readonly tabindex="-1"></td>

            <!-- CGST cols -->

            <td class="gst-col split-col"><input type="text" class="form-control form-control-sm bg-light text-info fw-semibold row-cgst-rate" value="<?= $pi_cgst_rate ?>" readonly tabindex="-1"></td>

            <td class="gst-col split-col"><input type="text" class="form-control form-control-sm bg-light text-info fw-semibold row-cgst-amt"  value="<?= $pi_cgst_amt  ?>" readonly tabindex="-1"></td>

            <!-- SGST cols -->

            <td class="gst-col split-col"><input type="text" class="form-control form-control-sm bg-light text-info fw-semibold row-sgst-rate" value="<?= $pi_sgst_rate ?>" readonly tabindex="-1"></td>

            <td class="gst-col split-col"><input type="text" class="form-control form-control-sm bg-light text-info fw-semibold row-sgst-amt"  value="<?= $pi_sgst_amt  ?>" readonly tabindex="-1"></td>

            <td><input type="number" name="discount[]"    class="form-control form-control-sm calc-field" value="<?= $pi['discount']??0 ?>" step="0.01" onchange="calcRow(this)"></td>

            <td><input type="text"   name="total_price[]" class="form-control form-control-sm fw-bold row-total" value="<?= $pi?number_format($pi['total_price'],2):'' ?>" readonly tabindex="-1"></td>

            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>

        </tr>

        <?php endforeach; ?>

        </tbody>

        <tfoot class="table-light fw-bold">

            <tr>

                <td colspan="5" class="text-end">Subtotal:</td>

                <!-- IGST totals -->

                <td class="gst-col igst-col"></td>

                <td class="gst-col igst-col text-warning" id="totalIGST">₹0.00</td>

                <!-- CGST+SGST totals -->

                <td class="gst-col split-col"></td>

                <td class="gst-col split-col text-info" id="totalCGST">₹0.00</td>

                <td class="gst-col split-col"></td>

                <td class="gst-col split-col text-info" id="totalSGST">₹0.00</td>

                <td></td>

                <td id="grandTotal">₹0.00</td>

                <td></td>

            </tr>

        </tfoot>

    </table>

    </div></div></div></div>



    <!-- Tax Summary Panel -->

    <div class="col-12">

    <div class="card border-0" style="background:#f8fafc">

    <div class="card-body py-2">

    <div class="row text-center g-2" id="taxSummary">

        <div class="col-6 col-sm-3">

            <div class="card p-2">

                <div class="text-muted small">Subtotal (Taxable)</div>

                <div class="fw-bold" id="summSubtotal">₹0.00</div>

            </div>

        </div>

        <div class="col-6 col-sm-3 igst-sum">

            <div class="card p-2 border-warning">

                <div class="text-muted small">IGST</div>

                <div class="fw-bold text-warning" id="summIGST">₹0.00</div>

            </div>

        </div>

        <div class="col-6 col-sm-3 split-sum">

            <div class="card p-2 border-info">

                <div class="text-muted small">CGST</div>

                <div class="fw-bold text-info" id="summCGST">₹0.00</div>

            </div>

        </div>

        <div class="col-6 col-sm-3 split-sum">

            <div class="card p-2 border-info">

                <div class="text-muted small">SGST</div>

                <div class="fw-bold text-info" id="summSGST">₹0.00</div>

            </div>

        </div>

        <div class="col-6 col-sm-3">

            <div class="card p-2 border-primary">

                <div class="text-muted small">Grand Total</div>

                <div class="fw-bold text-primary fs-5" id="summTotal">₹0.00</div>

            </div>

        </div>

    </div>

    </div></div></div>



    <div class="col-12 text-end">

        <a href="purchase_orders.php" class="btn btn-outline-secondary me-2">Cancel</a>

        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Purchase Order</button>

    </div>

</div>

</form>



<style>

.gst-col { transition: opacity 0.15s; }

</style>



<script>

const itemsListData = <?= json_encode($items_list) ?>;



// ── GST Type toggle ──────────────────────────────────────────────────

function updateGSTMode() {

    const isSplit = document.getElementById('gstType').value === 'CGST+SGST';



    // Table columns

    document.querySelectorAll('.igst-col' ).forEach(el => el.style.display = isSplit ? 'none' : '');

    document.querySelectorAll('.split-col').forEach(el => el.style.display = isSplit ? '' : 'none');



    // Summary cards

    document.querySelectorAll('.igst-sum' ).forEach(el => el.style.display = isSplit ? 'none' : '');

    document.querySelectorAll('.split-sum').forEach(el => el.style.display = isSplit ? '' : 'none');



    // Badge

    const badge = document.getElementById('gstTypeBadge');

    if (badge) {

        badge.textContent = isSplit ? 'CGST + SGST' : 'IGST';

        badge.className   = isSplit

            ? 'badge ms-2 bg-info text-white'

            : 'badge ms-2 bg-warning text-dark';

    }



    // Recalculate all rows

    document.querySelectorAll('#itemsBody tr').forEach(row => {

        const qtyEl = row.querySelector('[name="qty[]"]');

        if (qtyEl) calcRow(qtyEl);

    });

}



// ── Row management ───────────────────────────────────────────────────

function addItemRow() {

    const tbody  = document.getElementById('itemsBody');

    const newRow = tbody.rows[0].cloneNode(true);

    newRow.querySelectorAll('input').forEach(inp => inp.value = '');

    newRow.querySelector('select').selectedIndex = 0;

    newRow.querySelector('.item-select').onchange = function() { fillItemDetails(this); };

    newRow.querySelectorAll('.calc-field').forEach(f => f.onchange = function() { calcRow(this); });

    newRow.querySelector('button').onclick = function() { removeRow(this); };

    tbody.appendChild(newRow);

    updateGSTMode();

}



function removeRow(btn) {

    if (document.getElementById('itemsBody').rows.length > 1) {

        btn.closest('tr').remove();

        calcTotal();

    }

}



function fillItemDetails(sel) {

    const row = sel.closest('tr');

    const opt = sel.selectedOptions[0];

    if (opt && opt.value) {

        row.querySelector('[name="uom[]"]').value        = opt.dataset.uom   || '';

        row.querySelector('[name="unit_price[]"]').value = '';

        row.querySelector('[name="gst_rate[]"]').value   = '';

        calcRow(row.querySelector('[name="qty[]"]'));

    }

}



// ── Per-row calculation ──────────────────────────────────────────────

function calcRow(el) {

    const row     = el.closest('tr');

    const qty     = parseFloat(row.querySelector('[name="qty[]"]').value)        || 0;

    const price   = parseFloat(row.querySelector('[name="unit_price[]"]').value) || 0;

    const gstRate = parseFloat(row.querySelector('[name="gst_rate[]"]').value)   || 0;

    const disc    = parseFloat(row.querySelector('[name="discount[]"]').value)   || 0;

    const isSplit = document.getElementById('gstType').value === 'CGST+SGST';



    const taxable = (qty * price) - disc;

    const gstAmt  = taxable * gstRate / 100;



    if (isSplit) {

        const half = gstAmt / 2;

        // IGST → blank

        const irEl = row.querySelector('.row-igst-rate'); if (irEl) irEl.value = '';

        const iaEl = row.querySelector('.row-igst-amt');  if (iaEl) iaEl.value = '';

        // CGST

        const crEl = row.querySelector('.row-cgst-rate'); if (crEl) crEl.value = (gstRate/2).toFixed(2);

        const caEl = row.querySelector('.row-cgst-amt');  if (caEl) caEl.value = half.toFixed(2);

        // SGST

        const srEl = row.querySelector('.row-sgst-rate'); if (srEl) srEl.value = (gstRate/2).toFixed(2);

        const saEl = row.querySelector('.row-sgst-amt');  if (saEl) saEl.value = half.toFixed(2);

    } else {

        // IGST

        const irEl = row.querySelector('.row-igst-rate'); if (irEl) irEl.value = gstRate.toFixed(2);

        const iaEl = row.querySelector('.row-igst-amt');  if (iaEl) iaEl.value = gstAmt.toFixed(2);

        // CGST+SGST → blank

        const crEl = row.querySelector('.row-cgst-rate'); if (crEl) crEl.value = '';

        const caEl = row.querySelector('.row-cgst-amt');  if (caEl) caEl.value = '';

        const srEl = row.querySelector('.row-sgst-rate'); if (srEl) srEl.value = '';

        const saEl = row.querySelector('.row-sgst-amt');  if (saEl) saEl.value = '';

    }



    row.querySelector('.row-total').value = (taxable + gstAmt).toFixed(2);

    calcTotal();

}



// ── Grand totals ─────────────────────────────────────────────────────

function calcTotal() {

    let subtotal = 0, cgst = 0, sgst = 0, igst = 0, grand = 0;

    const isSplit = document.getElementById('gstType').value === 'CGST+SGST';



    document.querySelectorAll('#itemsBody tr').forEach(row => {

        const qty   = parseFloat(row.querySelector('[name="qty[]"]')?.value)        || 0;

        const price = parseFloat(row.querySelector('[name="unit_price[]"]')?.value) || 0;

        const gst   = parseFloat(row.querySelector('[name="gst_rate[]"]')?.value)   || 0;

        const disc  = parseFloat(row.querySelector('[name="discount[]"]')?.value)   || 0;

        const tax   = (qty * price) - disc;

        const gstA  = tax * gst / 100;

        subtotal += tax;

        if (isSplit) { cgst += gstA/2; sgst += gstA/2; }

        else         { igst += gstA; }

        grand += tax + gstA;

    });



    const fmt = v => '₹' + v.toFixed(2);

    document.getElementById('totalIGST').textContent    = fmt(igst);

    document.getElementById('totalCGST').textContent    = fmt(cgst);

    document.getElementById('totalSGST').textContent    = fmt(sgst);

    document.getElementById('grandTotal').textContent   = fmt(grand);

    document.getElementById('summSubtotal').textContent = fmt(subtotal);

    document.getElementById('summIGST').textContent     = fmt(igst);

    document.getElementById('summCGST').textContent     = fmt(cgst);

    document.getElementById('summSGST').textContent     = fmt(sgst);

    document.getElementById('summTotal').textContent    = fmt(grand);

}



/* ── Vendor Ship-To lookup map (PHP → JS) ── */

const poVendorShipData = <?php

    $vmap = [];

    foreach ($vendors as $v) {

        $addr  = ($v['ship_address'] ?? '') ?: ($v['bill_address'] ?? '') ?: ($v['address'] ?? '');

        $city  = ($v['ship_city']    ?? '') ?: ($v['bill_city']    ?? '') ?: ($v['city']    ?? '');

        $state = ($v['ship_state']   ?? '') ?: ($v['bill_state']   ?? '') ?: ($v['state']   ?? '');

        $pin   = ($v['ship_pincode'] ?? '') ?: ($v['bill_pincode'] ?? '') ?: ($v['pincode'] ?? '');

        $vmap[(int)$v['id']] = [

            'vendor_name' => $v['vendor_name'],

            'address'     => $addr,

            'city'        => $city,

            'state'       => $state,

            'pincode'     => $pin,

        ];

    }

    echo json_encode($vmap, JSON_HEX_APOS | JSON_HEX_QUOT);

?>;



function fillDeliveryFromVendor(sel) {

    var vid  = parseInt(sel.value, 10);

    var data = poVendorShipData[vid];

    if (!data) return;

    var addrEl = document.getElementById('da_address');

    var hasAddr = addrEl && addrEl.value.trim() !== '';

    var isEdit  = <?= ($action === 'edit') ? 'true' : 'false' ?>;

    if (hasAddr && isEdit) {

        if (!confirm('Replace current delivery address with ' + data.vendor_name + ' Ship-To address?')) return;

    }

    applyDeliveryAddr(data);

}



function applyDeliveryAddr(data) {

    var fields = { 'da_address':data.address||'', 'da_city':data.city||'', 'da_state':data.state||'', 'da_pincode':data.pincode||'' };

    Object.keys(fields).forEach(function(id) {

        var el = document.getElementById(id);

        if (el) {

            el.value = fields[id];

            if (fields[id]) { el.classList.add('border-success'); setTimeout(function(){ el.classList.remove('border-success'); }, 2500); }

        }

    });

    var nameEl = document.getElementById('deliveryVendorName');

    if (nameEl) nameEl.textContent = data.vendor_name;

    var notice = document.getElementById('deliveryAutofillNotice');

    if (notice) notice.style.display = 'flex';

    var badge = document.getElementById('deliveryAutofillBadge');

    if (badge) badge.classList.remove('d-none');

}



function clearDeliveryAddr() {

    ['da_address','da_city','da_state','da_pincode'].forEach(function(id) {

        var el = document.getElementById(id); if (el) el.value = '';

    });

    var notice = document.getElementById('deliveryAutofillNotice');

    if (notice) notice.style.display = 'none';

    var badge = document.getElementById('deliveryAutofillBadge');

    if (badge) badge.classList.add('d-none');

}



document.addEventListener('DOMContentLoaded', function() {

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { new bootstrap.Tooltip(el); });

    updateGSTMode();



    var sel = document.getElementById('poVendorSelect');

    if (sel && sel.value) {

        var vid  = parseInt(sel.value, 10);

        var data = poVendorShipData[vid];

        var addrEl = document.getElementById('da_address');

        if (data && addrEl && !addrEl.value.trim()) applyDeliveryAddr(data);

    }

});

</script>

<?php endif; ?>



<?php include '../includes/footer.php'; ?>

