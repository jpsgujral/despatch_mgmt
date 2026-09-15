<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_fuel_payments', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    fuel_company_id INT NOT NULL,
    company_id      INT DEFAULT NULL,
    payment_date    DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_mode    VARCHAR(40) DEFAULT 'NEFT',
    reference_no    VARCHAR(80),
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_company_id = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_fuel_payments'
    AND COLUMN_NAME='company_id' LIMIT 1")->num_rows;
if (!$has_company_id) {
    $db->query("ALTER TABLE fleet_fuel_payments ADD COLUMN company_id INT DEFAULT NULL AFTER fuel_company_id");
}

$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_log (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    fuel_company_id   INT NOT NULL,
    vehicle_id        INT NOT NULL,
    driver_id         INT DEFAULT NULL,
    fuel_date         DATE NOT NULL,
    litres            DECIMAL(10,2) DEFAULT 0,
    rate_per_litre    DECIMAL(8,2) DEFAULT 0,
    amount            DECIMAL(12,2) DEFAULT 0,
    odometer          INT DEFAULT 0,
    payment_mode      ENUM('Credit','Cash') DEFAULT 'Credit',
    bill_no           VARCHAR(60),
    notes             TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Save payment ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    requirePerm('fleet_fuel_payments','create');
    $fc_id       = (int)$_POST['fuel_company_id'];
    $company_id  = (int)($_POST['company_id'] ?? 0);
    $date        = sanitize($_POST['payment_date']);
    $amount      = (float)$_POST['amount'];
    $mode        = sanitize($_POST['payment_mode'] ?? 'NEFT');
    $ref         = sanitize($_POST['reference_no'] ?? '');
    $rem         = sanitize($_POST['remarks'] ?? '');
    $period_from = sanitize($_POST['period_from'] ?? '');
    $period_to   = sanitize($_POST['period_to'] ?? '');

    // Append period info to remarks if provided
    if ($period_from && $period_to) {
        $period_label = date('d/m/Y', strtotime($period_from)).' to '.date('d/m/Y', strtotime($period_to));
        $rem = $rem ? $rem.' [Period: '.$period_label.']' : 'Period: '.$period_label;
    }

    if (!$fc_id || !$company_id || !$date || $amount <= 0) {
        showAlert('danger','Company, Fuel Company, Date and Amount are required.');
        redirect('fleet_fuel_payments.php?action=add');
    }
    $db->query("INSERT INTO fleet_fuel_payments (fuel_company_id,company_id,payment_date,amount,payment_mode,reference_no,remarks)
        VALUES ($fc_id,$company_id,'$date',$amount,'$mode','$ref','$rem')");
    showAlert('success','Payment recorded.');
    redirect('fleet_fuel_payments.php');
}

/* ── Update payment ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    requirePerm('fleet_fuel_payments','update');
    $eid  = (int)$_POST['edit_id'];
    $date = sanitize($_POST['payment_date']);
    $mode = sanitize($_POST['payment_mode'] ?? 'NEFT');
    $ref  = sanitize($_POST['reference_no'] ?? '');
    $rem  = sanitize($_POST['remarks'] ?? '');
    if (!$eid || !$date) {
        showAlert('danger','Invalid payment.');
        redirect('fleet_fuel_payments.php');
    }
    $db->query("UPDATE fleet_fuel_payments
                SET payment_date='$date', payment_mode='$mode', reference_no='$ref', remarks='$rem'
                WHERE id=$eid");
    showAlert('success','Payment updated.');
    redirect('fleet_fuel_payments.php');
}

$fuel_companies = $db->query("SELECT id,company_name,credit_terms FROM fleet_fuel_companies WHERE status='Active' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);
$paying_companies = $db->query("SELECT id,company_name FROM companies WHERE is_active=1 ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-cash-coin me-2"></i>Fuel Payments';</script>
<?php

/* ── LIST ── */
if ($action === 'list'):
$companies_ledger = $db->query("SELECT fc.id, fc.company_name, fc.credit_terms,
    COALESCE((SELECT SUM(fl.amount + COALESCE(fl.driver_advance,0)) FROM fleet_fuel_log fl WHERE fl.fuel_company_id=fc.id AND fl.payment_mode='Credit'),0) AS total_credit,
    COALESCE((SELECT SUM(fp.amount) FROM fleet_fuel_payments fp WHERE fp.fuel_company_id=fc.id),0) AS total_paid
    FROM fleet_fuel_companies fc
    WHERE fc.status='Active'
    ORDER BY fc.company_name")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fuel Payments</h5>
    <?php if (canDo('fleet_fuel_payments','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Record Payment</a>
    <?php endif; ?>
</div>

<!-- Ledger per fuel company -->
<?php foreach ($companies_ledger as $cl):
    $outstanding = $cl['total_credit'] - $cl['total_paid'];
    $payments = $db->query("SELECT * FROM fleet_fuel_payments WHERE fuel_company_id=".$cl['id']." ORDER BY payment_date DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card mb-3">
<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#e5f5eb;border-top:2px solid #1a5632">
    <span>
        <i class="bi bi-fuel-pump me-1 text-success"></i>
        <strong><?= htmlspecialchars($cl['company_name']) ?></strong>
        <span class="badge bg-info ms-2"><?= $cl['credit_terms'] ?></span>
    </span>
    <span class="d-flex gap-3 flex-wrap">
        <span class="text-muted" style="font-size:.85rem">Total Bills: <strong>₹<?= number_format($cl['total_credit'],2) ?></strong></span>
        <span class="text-success" style="font-size:.85rem">Paid: <strong>₹<?= number_format($cl['total_paid'],2) ?></strong></span>
        <span class="<?= $outstanding > 0 ? 'text-danger fw-bold' : 'text-success' ?>" style="font-size:.85rem">Outstanding: <strong>₹<?= number_format($outstanding,2) ?></strong></span>
    </span>
</div>
<div class="card-body p-0">
<?php if ($payments): ?>
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead><tr><th>Date</th><th>Amount</th><th>Mode</th><th>Reference</th><th>Remarks</th><th></th></tr></thead>
<tbody>
<?php foreach ($payments as $p): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($p['payment_date'])) ?></td>
    <td class="text-success fw-bold">₹<?= number_format($p['amount'],2) ?></td>
    <td><?= htmlspecialchars($p['payment_mode']) ?></td>
    <td><?= htmlspecialchars($p['reference_no']??'—') ?></td>
    <td><?= htmlspecialchars($p['remarks']??'') ?></td>
    <td class="text-nowrap">
        <a href="fleet_fuel_payment_advice.php?id=<?= $p['id'] ?>" target="_blank"
           class="btn btn-action btn-outline-secondary" title="Download Payment Advice">
            <i class="bi bi-file-earmark-pdf"></i>
        </a>
        <?php if (canDo('fleet_fuel_payments','update')): ?>
        <button type="button" class="btn btn-action btn-outline-primary ms-1"
                title="Edit Payment"
                onclick="openEditModal(<?= $p['id'] ?>,
                    '<?= $p['payment_date'] ?>',
                    '<?= htmlspecialchars($p['payment_mode'],ENT_QUOTES) ?>',
                    '<?= htmlspecialchars($p['reference_no']??'',ENT_QUOTES) ?>',
                    '<?= htmlspecialchars($p['remarks']??'',ENT_QUOTES) ?>')">
            <i class="bi bi-pencil"></i>
        </button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p class="text-muted p-3 mb-0">No payments recorded yet.</p>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>

<?php
/* ── ADD ── */
else:
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Record Fuel Payment</h5>
    <a href="fleet_fuel_payments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_payment" value="1">
<div class="card"><div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Payment From Company *</label>
        <select name="company_id" class="form-select" required>
            <option value="">&mdash; Select Company &mdash;</option>
            <?php foreach ($paying_companies as $co): ?>
            <option value="<?= $co['id'] ?>" <?= ((int)$co['id'] === activeCompanyId()) ? 'selected' : '' ?>>
                <?= htmlspecialchars($co['company_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Fuel Company *</label>
        <select name="fuel_company_id" id="fuel_company_id" class="form-select" required onchange="refreshOutstanding()">
            <option value="">— Select Company —</option>
            <?php foreach ($fuel_companies as $fc): ?>
            <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['company_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Period From</label>
        <input type="date" name="period_from" id="period_from" class="form-control" onchange="refreshOutstanding()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Period To</label>
        <input type="date" name="period_to" id="period_to" class="form-control" onchange="refreshOutstanding()">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Outstanding Balance</label>
        <div class="input-group">
            <input type="text" id="outstanding" class="form-control bg-light fw-bold" readonly placeholder="Select company first">
            <span class="input-group-text text-muted small" id="outstanding_label" style="font-size:.78rem;min-width:80px">Total Due</span>
        </div>
        <div id="outstanding_note" class="form-text text-muted" style="font-size:.75rem"></div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Payment Date *</label>
        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount (₹) *</label>
        <input type="number" name="amount" class="form-control" step="0.01" required placeholder="0.00">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['NEFT','RTGS','IMPS','Cheque','Cash','UPI'] as $m): ?>
            <option><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Reference No</label>
        <input type="text" name="reference_no" class="form-control" placeholder="UTR / Cheque No">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Remarks</label>
        <input type="text" name="remarks" class="form-control">
    </div>
    <div class="col-12 text-end">
        <a href="fleet_fuel_payments.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Record Payment</button>
    </div>
</div></div></div>
</form>
<script>
/* Total outstanding per company (all-time) */
var ledger = <?php
$ld = [];
foreach ($fuel_companies as $fc) {
    $cred = $db->query("SELECT COALESCE(SUM(amount + COALESCE(driver_advance,0)),0) s FROM fleet_fuel_log WHERE fuel_company_id=".$fc['id']." AND payment_mode='Credit'")->fetch_assoc()['s'];
    $paid = $db->query("SELECT COALESCE(SUM(amount),0) s FROM fleet_fuel_payments WHERE fuel_company_id=".$fc['id'])->fetch_assoc()['s'];
    $ld[$fc['id']] = round($cred - $paid, 2);
}
echo json_encode($ld);
?>;

function formatINR(val) {
    return '₹' + parseFloat(val).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function refreshOutstanding() {
    var fcId  = document.getElementById('fuel_company_id').value;
    var from  = document.getElementById('period_from').value;
    var to    = document.getElementById('period_to').value;
    var el    = document.getElementById('outstanding');
    var lbl   = document.getElementById('outstanding_label');
    var note  = document.getElementById('outstanding_note');

    if (!fcId) { el.value = ''; lbl.textContent = 'Total Due'; note.textContent = ''; return; }

    /* If both period dates given, fetch period-specific outstanding via AJAX */
    if (from && to) {
        el.value = 'Loading…';
        lbl.textContent = 'Period Due';
        note.textContent = '';
        fetch('fleet_fuel_payments_ajax.php?action=period_outstanding&fc_id='+encodeURIComponent(fcId)
              +'&from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to))
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.error) { el.value = 'Error'; note.textContent = d.error; return; }
                var color = d.outstanding > 0 ? '#dc3545' : '#198754';
                el.value = formatINR(d.outstanding);
                el.style.color = color;
                lbl.textContent = 'Period Due';
                note.textContent = 'Bills in period: '+formatINR(d.period_bills)
                    +' | Paid in period: '+formatINR(d.period_paid);
            })
            .catch(function(){ el.value = 'Error'; });
    } else {
        /* Show total outstanding */
        var val = ledger[fcId] !== undefined ? ledger[fcId] : 0;
        el.value = formatINR(val);
        el.style.color = val > 0 ? '#dc3545' : '#198754';
        lbl.textContent = 'Total Due';
        note.textContent = from || to ? 'Select both From and To dates for period view.' : '';
    }
}
</script>
<?php endif; ?>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" aria-labelledby="editPaymentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-md">
    <div class="modal-content">
      <div class="modal-header" style="background:#1a5632;color:#fff;">
        <h6 class="modal-title" id="editPaymentModalLabel"><i class="bi bi-pencil me-2"></i>Edit Payment</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="update_payment" value="1">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-bold">Payment Date *</label>
              <input type="date" name="payment_date" id="edit_payment_date" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Payment Mode</label>
              <select name="payment_mode" id="edit_payment_mode" class="form-select">
                <?php foreach (['NEFT','RTGS','IMPS','Cheque','Cash','UPI'] as $m): ?>
                <option><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Reference No</label>
              <input type="text" name="reference_no" id="edit_reference_no" class="form-control" placeholder="UTR / Cheque No">
            </div>
            <div class="col-12">
              <label class="form-label">Remarks</label>
              <input type="text" name="remarks" id="edit_remarks" class="form-control">
            </div>
          </div>
          <div class="alert alert-warning mt-3 mb-0 py-2" style="font-size:.82rem">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Note:</strong> Amount and Fuel Company cannot be changed after recording.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check2 me-1"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function openEditModal(id, date, mode, ref, rem) {
    document.getElementById('edit_id').value           = id;
    document.getElementById('edit_payment_date').value = date;
    document.getElementById('edit_reference_no').value = ref;
    document.getElementById('edit_remarks').value      = rem;
    var sel = document.getElementById('edit_payment_mode');
    for (var i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = (sel.options[i].value === mode);
    }
    var modal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
    modal.show();
}
</script>
<?php include '../includes/footer.php'; ?>
