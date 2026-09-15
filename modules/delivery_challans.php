<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
/* ── Page-level view permission check ── */
requirePerm('delivery_challans', 'view');


include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-receipt me-2"></i>Delivery Challans';</script>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">All Delivery Challans</h5>
    <a href="despatch.php?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New Despatch</a>
</div>

<div class="card mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label mb-1">From Date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['from_date'] ?? date('Y-m-01')) ?>">
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label mb-1">To Date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['to_date'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            <?php foreach(['Draft','Despatched','In Transit','Delivered','Cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= ($_GET['status']??'')==$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
        <a href="delivery_challans.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
    </div>
</form>
</div>
</div>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0" id="challanTable">
    <thead><tr>
        <th>#</th><th>Challan No</th><th>Date</th>
        <th>Consignee</th><th>Transporter</th>
        <th>Weight</th><th>Amount</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php
    $where = "1=1";
    $where .= despatchFilter('d');
    if (!empty($_GET['from_date'])) $where .= " AND d.despatch_date >= '" . sanitize($_GET['from_date']) . "'";
    if (!empty($_GET['to_date'])) $where .= " AND d.despatch_date <= '" . sanitize($_GET['to_date']) . "'";
    if (!empty($_GET['status'])) $where .= " AND d.status = '" . sanitize($_GET['status']) . "'";

    $list = $db->query("
        SELECT d.*, t.transporter_name
        FROM despatch_orders d
        LEFT JOIN transporters t ON d.transporter_id = t.id
        WHERE $where
        ORDER BY d.consignee_name ASC, d.despatch_date DESC, d.id DESC
    ");
    $rows = $list->fetch_all(MYSQLI_ASSOC);

    // Group by consignee
    $groups = [];
    foreach ($rows as $v) {
        $groups[trim($v['consignee_name'])][] = $v;
    }
    $gi = 0; $rowNum = 1;
    foreach ($groups as $consignee => $orders):
        $gi++;
        $groupId  = 'grp_c_' . $gi;
        $totalAmt = array_sum(array_column($orders, 'total_amount'));
        $totalWt  = array_sum(array_column($orders, 'total_weight'));
        $count    = count($orders);
        $city     = $orders[0]['consignee_city'] ?? '';
        // Get UOM for weight display from first order's primary item
        $wt_uom_row = $db->query("SELECT di.uom AS di_uom, i.uom AS i_uom FROM despatch_items di LEFT JOIN items i ON di.item_id=i.id WHERE di.despatch_id={$orders[0]['id']} ORDER BY di.id LIMIT 1")->fetch_assoc();
        $grp_uom = !empty($wt_uom_row['di_uom']) ? $wt_uom_row['di_uom'] : (!empty($wt_uom_row['i_uom']) ? $wt_uom_row['i_uom'] : 'Kg');
        $grp_dp  = uomDecimals($grp_uom);
    ?>
    <!-- Consignee group header -->
    <tr class="consignee-group-header" style="cursor:pointer" onclick="toggleGroup('<?= $groupId ?>', this)">
        <td colspan="5">
            <span class="group-toggle-icon me-2">▾</span>
            <strong><?= htmlspecialchars($consignee) ?></strong>
            <?php if ($city): ?><small class="text-muted ms-2"><?= htmlspecialchars($city) ?></small><?php endif; ?>
            <span class="badge bg-secondary ms-2"><?= $count ?> order<?= $count>1?'s':'' ?></span>
        </td>
        <td><?= number_format($totalWt, $grp_dp) ?> <?= htmlspecialchars($grp_uom) ?></td>
        <td><strong>₹<?= number_format($totalAmt,2) ?></strong></td>
        <td colspan="2"></td>
    </tr>
    <?php foreach ($orders as $v):
        $b=['Draft'=>'secondary','Despatched'=>'primary','In Transit'=>'warning','Delivered'=>'success','Cancelled'=>'danger'][$v['status']] ?? 'secondary';
        $row_uom_r = $db->query("SELECT di.uom AS di_uom, i.uom AS i_uom FROM despatch_items di LEFT JOIN items i ON di.item_id=i.id WHERE di.despatch_id={$v['id']} ORDER BY di.id LIMIT 1")->fetch_assoc();
        $row_uom   = !empty($row_uom_r['di_uom']) ? $row_uom_r['di_uom'] : (!empty($row_uom_r['i_uom']) ? $row_uom_r['i_uom'] : 'Kg');
        $row_dp    = uomDecimals($row_uom);
    ?>
    <tr class="group-row <?= $groupId ?>">
        <td class="ps-4 text-muted"><?= $rowNum++ ?></td>
        <td><strong class="text-primary"><?= htmlspecialchars($v['challan_no']) ?></strong></td>
        <td><?= date('d/m/Y', strtotime($v['despatch_date'])) ?></td>
        <td><small class="text-muted"><?= htmlspecialchars($v['consignee_city']) ?></small></td>
        <td><?= htmlspecialchars($v['transporter_name'] ?? 'Direct') ?></td>
        <td><?= number_format($v['total_weight'], $row_dp) ?> <?= htmlspecialchars($row_uom) ?></td>
        <td>₹<?= number_format($v['total_amount'],2) ?></td>
        <td><span class="badge bg-<?= $b ?>"><?= $v['status'] ?></span></td>
        <td>
            <a href="print_challan.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-action btn-success me-1" title="Print Challan">
                <i class="bi bi-printer"></i> Print
            </a>
            <a href="despatch.php?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<style>
.consignee-group-header td { background: #e8eef5 !important; border-top: 2px solid #1e3a5f !important; }
.consignee-group-header:hover td { background: #d5e3f0 !important; }
.group-row td { background: #fff; }
.group-row:hover td { background: #f8f9fa !important; }

/* ── Mobile improvements ── */
@media (max-width: 575.98px) {
    /* Stack filter buttons full width */
    .btn-primary.btn-sm, .btn-outline-secondary.btn-sm {
        width: 100%; margin-left: 0 !important; margin-top: 4px;
    }
    /* Larger print/edit tap targets */
    .btn-action {
        min-height: 38px; min-width: 38px;
        display: inline-flex; align-items: center; justify-content: center;
    }
    /* Hide less important columns on small screens */
    #challanTable thead tr th:nth-child(5),
    #challanTable tbody tr td:nth-child(5) { display: none; } /* Transporter */
    #challanTable thead tr th:nth-child(7),
    #challanTable tbody tr td:nth-child(7) { display: none; } /* Amount */
    /* Group header adjust */
    .consignee-group-header td:first-child { font-size: .8rem; }
}
</style>
<script>
function toggleGroup(id, headerRow) {
    var rows   = document.querySelectorAll('.' + id);
    var icon   = headerRow.querySelector('.group-toggle-icon');
    var hidden = rows.length && rows[0].style.display === 'none';
    rows.forEach(function(r){ r.style.display = hidden ? '' : 'none'; });
    icon.textContent = hidden ? '▾' : '▸';
}
document.addEventListener('DOMContentLoaded', function(){
    // Add search box inside card
    var card = document.querySelector('#challanTable').closest('.card');
    var bar = document.createElement('div');
    bar.className = 'px-3 py-2 border-bottom bg-light';
    bar.innerHTML = '<input type="text" id="challanSearch" class="form-control form-control-sm" placeholder="🔍  Search consignee, challan no, transporter…" style="max-width:360px">';
    card.querySelector('.card-body').insertBefore(bar, card.querySelector('.table-responsive'));
    document.getElementById('challanSearch').addEventListener('input', function(){
        var q = this.value.toLowerCase();
        document.querySelectorAll('.consignee-group-header').forEach(function(hdr){
            var sibs = [], next = hdr.nextElementSibling;
            while (next && next.classList.contains('group-row')) { sibs.push(next); next = next.nextElementSibling; }
            var anyMatch = false;
            sibs.forEach(function(r){
                var match = !q || r.textContent.toLowerCase().includes(q);
                r.style.display = match ? '' : 'none';
                if (match) anyMatch = true;
            });
            hdr.style.display = (!q || anyMatch || hdr.textContent.toLowerCase().includes(q)) ? '' : 'none';
        });
    });
});
</script>
</div>
</div>
</div>

<?php include '../includes/footer.php'; ?>
