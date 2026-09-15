<?php
/**
 * Pending Billing Status
 * ------------------------------------------------------------------
 * Read-only list of Completed trips whose Sales Billing is still
 * "Pending", pulled straight out of fleet_trips (billing_status column).
 *
 * The "Update Sales Billing" button on this page submits to the SAME
 * fleet_trips.php POST handler (save_trip_billing) that the Trip Order
 * Register already uses — no logic in fleet_trips.php was touched.
 * One side-effect of that: after saving, fleet_trips.php redirects to
 * "Trip Order Register" (that redirect is hard-coded there). If you'd
 * rather it return here instead, that's a 1-line change in
 * fleet_trips.php that I did not make since you asked for no logic
 * changes to that file.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();

/* Reuses the Trip Order Register permission, since this is the same
   underlying data set (Completed trips + their billing status). */
requirePerm('fleet_trip_register', 'view');

$_uid = (int)($_SESSION['user_id'] ?? 0);
$_user_filter = canViewAll('trips') ? "" : " AND t.created_by=$_uid";

$trips = $db->query("SELECT t.*, v.reg_no, d.full_name AS driver_name,
    p.po_number, vn.vendor_name, co.company_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON t.driver_id=d.id
    LEFT JOIN fleet_purchase_orders p ON t.po_id=p.id
    LEFT JOIN fleet_customers_master vn ON t.vendor_id=vn.id
    LEFT JOIN companies co ON t.company_id=co.id
    WHERE t.status='Completed'
      AND (t.billing_status='Pending' OR t.billing_status IS NULL OR t.billing_status='')
      $_user_filter
    ORDER BY t.id DESC")->fetch_all(MYSQLI_ASSOC);

$billing_colors = ['Pending' => 'warning', 'Billed' => 'primary'];
$can_manage_trip_billing = canDo('fleet_trips', 'view');

$grouped = [];
foreach ($trips as $t) {
    $co = $t['company_name'] ?? 'General';
    $grouped[$co][] = $t;
}

$all_companies = getAllCompanies();
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-receipt-cutoff me-2"></i>Pending Billing Status';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Pending Billing Status</h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="fleet_trips.php?action=list&view=register" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-journal-text me-1"></i>Trip Order Register
        </a>
    </div>
</div>

<div class="card mb-3">
<div class="card-body py-2 d-flex align-items-center gap-2">
    <span class="badge bg-warning text-dark"><?= count($trips) ?></span>
    <span class="text-muted small">Completed trip<?= count($trips) === 1 ? '' : 's' ?> awaiting Sales Billing</span>
</div>
</div>

<?php if (empty($trips)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
        No trips pending sales billing.
    </div>
</div>
<?php else: ?>

<?php $gi = 0; foreach ($grouped as $co_name => $co_trips): $gi++;
    $grp_id = 'pbgrp_' . $gi;
?>
<div class="card mb-3 company-group" id="<?= $grp_id ?>">
    <div class="card-header d-flex align-items-center gap-2 py-2"
         style="cursor:pointer;background:linear-gradient(135deg,#B45309,#78350F);border-left:4px solid #78350F;color:#fff"
         onclick="toggleCoGroup('<?= $grp_id ?>')">
        <i class="bi bi-chevron-down text-white" id="chev_<?= $grp_id ?>"></i>
        <i class="bi bi-building me-1 text-white"></i>
        <strong class="text-white"><?= htmlspecialchars($co_name) ?></strong>
        <span class="badge bg-white text-warning ms-1"><?= count($co_trips) ?> Trip<?= count($co_trips) > 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0" id="body_<?= $grp_id ?>">
    <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.88rem">
    <thead class="table-light"><tr>
        <th>Trip No</th><th>Date</th>
        <th class="hide-mobile">Customer PO</th><th>Customer</th><th>Vehicle</th>
        <th>Billing Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($co_trips as $t):
        $billing_status  = (($t['billing_status'] ?? 'Pending') === 'Billed') ? 'Billed' : 'Pending';
        $bc              = $billing_colors[$billing_status] ?? 'secondary';
        $sales_bill_no   = trim((string)($t['sales_bill_no'] ?? ''));
        $sales_bill_date = trim((string)($t['sales_bill_date'] ?? ''));
    ?>
    <tr>
        <td><strong><?= htmlspecialchars($t['trip_no']) ?></strong></td>
        <td><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
        <td class="hide-mobile"><?= $t['po_number'] ? htmlspecialchars($t['po_number']) : '—' ?></td>
        <td>
            <?= htmlspecialchars($t['vendor_name'] ?? $t['customer_name'] ?? '—') ?>
            <?php if (!empty($t['customer_camp'])): ?>
            <div class="text-muted small"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($t['customer_camp']) ?></div>
            <?php endif; ?>
        </td>
        <td><span class="badge bg-dark"><?= htmlspecialchars($t['reg_no']) ?></span></td>
        <td><span class="badge bg-<?= $bc ?>"><?= htmlspecialchars($billing_status) ?></span></td>
        <td>
            <a href="fleet_trips.php?action=view&id=<?= $t['id'] ?>&back=<?= urlencode('fleet_pending_billing.php') ?>"
               class="btn btn-action btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
            <?php if ($can_manage_trip_billing): ?>
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
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($can_manage_trip_billing): ?>
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
function toggleCoGroup(id) {
    var body = document.getElementById('body_' + id);
    var chev = document.getElementById('chev_' + id);
    if (body.style.display === 'none') {
        body.style.display = '';
        chev.className = 'bi bi-chevron-down';
    } else {
        body.style.display = 'none';
        chev.className = 'bi bi-chevron-right';
    }
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
    var status = document.getElementById('tripBillingStatus').value;
    var wrap = document.getElementById('tripBillingFields');
    if (!wrap) return;
    wrap.style.display = status === 'Billed' ? '' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
