<?php
/**
 * Quotations Module — Edit Quotation
 * Path: modules/quotations/edit.php
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('quotations', 'edit');
require_once 'quot_helpers.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('quot_index.php'); }

$q = getQuotation($db, $id);
if (!$q) { redirect('quot_index.php'); }

$pageTitle = 'Edit Quotation — ' . $q['quotation_no'];
$errors    = [];

// --- HANDLE POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qDate     = sanitize($_POST['quotation_date'] ?? '');
    $vTill     = sanitize($_POST['valid_till'] ?? '');
    $quotFor   = sanitize($_POST['quotation_for'] ?? '');
    $cName     = sanitize($_POST['client_name'] ?? '');
    $cCompany  = sanitize($_POST['client_company'] ?? '');
    $cAddr1    = sanitize($_POST['client_addr1'] ?? '');
    $cAddr2    = sanitize($_POST['client_addr2'] ?? '');
    $cCity     = sanitize($_POST['client_city'] ?? '');
    $cState    = sanitize($_POST['client_state'] ?? '');
    $cPin      = sanitize($_POST['client_pin'] ?? '');
    $cMobile   = sanitize($_POST['client_mobile'] ?? '');
    $cEmail    = sanitize($_POST['client_email'] ?? '');
    $refPerson = sanitize($_POST['ref_person'] ?? '');
    $refMobile = sanitize($_POST['ref_mobile'] ?? '');
    $refEmail  = sanitize($_POST['ref_email'] ?? '');
    $payTerms  = sanitize($_POST['payment_terms'] ?? '');
    $delSched  = sanitize($_POST['delivery_schedule'] ?? '');
    $offerVal  = sanitize($_POST['offer_validity'] ?? '');
    $rateRev   = sanitize($_POST['rate_revision'] ?? '');
    $otherT    = sanitize($_POST['other_terms'] ?? '');
    $status    = sanitize($_POST['status'] ?? 'draft');
    $notes     = sanitize($_POST['notes'] ?? '');

    $particulars = $_POST['particular'] ?? [];
    $details     = $_POST['detail'] ?? [];

    if (!$cName)    $errors[] = 'Client Name is required.';
    if (!$cCompany) $errors[] = 'Client Company is required.';

    if (empty($errors)) {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("UPDATE quotations SET
                quotation_date=?, valid_till=?, quotation_for=?,
                client_name=?, client_company=?, client_addr1=?, client_addr2=?,
                client_city=?, client_state=?, client_pin=?, client_mobile=?, client_email=?,
                ref_person=?, ref_mobile=?, ref_email=?,
                payment_terms=?, delivery_schedule=?, offer_validity=?, rate_revision=?, other_terms=?,
                status=?, notes=?
                WHERE id=?");
            $stmt->bind_param(
                "ssssssssssssssssssssssi",
                $qDate, $vTill, $quotFor,
                $cName, $cCompany, $cAddr1, $cAddr2,
                $cCity, $cState, $cPin, $cMobile, $cEmail,
                $refPerson, $refMobile, $refEmail,
                $payTerms, $delSched, $offerVal, $rateRev, $otherT,
                $status, $notes, $id
            );
            $stmt->execute();
            $stmt->close();

            // Replace items
            $db->query("DELETE FROM quotation_items WHERE quotation_id = $id");
            $istmt = $db->prepare("INSERT INTO quotation_items (quotation_id, sort_order, particular, detail) VALUES (?,?,?,?)");
            foreach ($particulars as $i => $part) {
                $part   = sanitize($part);
                $detail = sanitize($details[$i] ?? '');
                $order  = $i + 1;
                $istmt->bind_param("iiss", $id, $order, $part, $detail);
                $istmt->execute();
            }
            $istmt->close();

            $db->commit();
            redirect("quot_view.php?id=$id&updated=1");
        } catch (Exception $e) {
            $db->rollback();
            $errors[] = 'DB Error: ' . $e->getMessage();
        }
    }

    // Reload with POST data on error
    $q = array_merge($q, $_POST);
    $q['items'] = [];
    foreach ($particulars as $i => $part) {
        $q['items'][] = ['particular' => $part, 'detail' => $details[$i] ?? ''];
    }
}

require_once '../includes/header.php';
?>

<style>
.section-heading { font-size:.75rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#6f1f8a; margin-bottom:.5rem; }
.card-section { border-left: 3px solid #6f1f8a; }
.text-purple { color: #6f1f8a !important; }
.btn-purple { background:#6f1f8a; color:#fff; border-color:#6f1f8a; }
.btn-purple:hover { background:#5a1870; color:#fff; }
.btn-outline-purple { color:#6f1f8a; border-color:#6f1f8a; }
.btn-outline-purple:hover { background:#6f1f8a; color:#fff; }
.table-particulars th { background:#6f1f8a; color:#fff; font-size:.8rem; }
</style>

<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0 fw-bold"><i class="bi bi-pencil-square text-purple me-2"></i>Edit Quotation</h4>
      <small class="text-muted"><?= htmlspecialchars($q['quotation_no']) ?></small>
    </div>
    <div>
      <a href="quot_view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-eye me-1"></i>View</a>
      <a href="quot_index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger small py-2">
    <?php foreach($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST">
    <div class="row g-3 mb-3">

      <div class="col-md-4">
        <div class="card shadow-sm border-0 card-section h-100">
          <div class="card-body">
            <div class="section-heading">Quotation Details</div>
            <div class="mb-2">
              <label class="form-label small mb-1">Quotation No.</label>
              <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($q['quotation_no']) ?>" readonly>
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Date <span class="text-danger">*</span></label>
              <input type="date" name="quotation_date" class="form-control form-control-sm" required value="<?= htmlspecialchars($q['quotation_date']) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Valid Till</label>
              <input type="date" name="valid_till" class="form-control form-control-sm" value="<?= htmlspecialchars($q['valid_till']) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Quotation For</label>
              <input type="text" name="quotation_for" class="form-control form-control-sm" value="<?= htmlspecialchars($q['quotation_for']) ?>">
            </div>
            <div class="mb-0">
              <label class="form-label small mb-1">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php foreach (['draft','sent','accepted','rejected','expired'] as $s): ?>
                <option value="<?= $s ?>" <?= $q['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-5">
        <div class="card shadow-sm border-0 card-section h-100">
          <div class="card-body">
            <div class="section-heading">Client Information</div>
            <div class="row g-2">
              <?php
              $cf = [
                ['client_name','Name','col-6',true], ['client_company','Company','col-6',true],
                ['client_addr1','Address Line 1','col-12',false], ['client_addr2','Address Line 2','col-12',false],
                ['client_city','City','col-4',false], ['client_state','State','col-4',false], ['client_pin','PIN','col-4',false],
                ['client_mobile','Mobile','col-6',false], ['client_email','Email','col-6',false],
              ];
              foreach ($cf as [$name, $label, $col, $req]):
              ?>
              <div class="<?= $col ?>">
                <label class="form-label small mb-1"><?= $label ?><?= $req ? ' <span class="text-danger">*</span>' : '' ?></label>
                <input type="<?= str_contains($name,'email') ? 'email' : 'text' ?>"
                       name="<?= $name ?>" class="form-control form-control-sm"
                       <?= $req ? 'required' : '' ?>
                       value="<?= htmlspecialchars($q[$name] ?? '') ?>">
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-3">
        <div class="card shadow-sm border-0 card-section h-100">
          <div class="card-body">
            <div class="section-heading">Reference</div>
            <?php foreach ([['ref_person','Reference Person'],['ref_mobile','Mobile'],['ref_email','Email']] as [$n,$l]): ?>
            <div class="mb-2">
              <label class="form-label small mb-1"><?= $l ?></label>
              <input type="<?= str_contains($n,'email')?'email':'text' ?>" name="<?= $n ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($q[$n] ?? '') ?>">
            </div>
            <?php endforeach; ?>
            <div class="mb-0">
              <label class="form-label small mb-1">Internal Notes</label>
              <textarea name="notes" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($q['notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Particulars -->
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="section-heading mb-0">Particulars</div>
          <button type="button" class="btn btn-sm btn-outline-purple" onclick="addRow()">
            <i class="bi bi-plus-circle me-1"></i>Add Row
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-particulars mb-0">
            <thead>
              <tr><th style="width:40px">#</th><th>Particular</th><th>Details</th><th style="width:50px"></th></tr>
            </thead>
            <tbody id="particularsBody">
              <?php foreach ($q['items'] as $i => $item): ?>
              <tr>
                <td class="text-center text-muted small"><?= $i+1 ?></td>
                <td><input type="text" name="particular[]" class="form-control form-control-sm" value="<?= htmlspecialchars($item['particular']) ?>"></td>
                <td><input type="text" name="detail[]" class="form-control form-control-sm" value="<?= htmlspecialchars($item['detail']) ?>"></td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Terms -->
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-body">
        <div class="section-heading mb-2">Terms &amp; Conditions</div>
        <div class="row g-3">
          <?php
          $terms = [
            ['payment_terms','Payment Terms'],['delivery_schedule','Delivery Schedule'],
            ['offer_validity','Offer Validity'],['rate_revision','Rate Revision Clause'],['other_terms','Other Terms'],
          ];
          foreach ($terms as [$n, $l]):
          ?>
          <div class="col-md-<?= in_array($n,['rate_revision','other_terms'])?'6':'4' ?>">
            <label class="form-label small mb-1 fw-semibold"><?= $l ?></label>
            <textarea name="<?= $n ?>" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($q[$n] ?? '') ?></textarea>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 mb-4">
      <button type="submit" class="btn btn-purple px-4"><i class="bi bi-save me-1"></i>Update Quotation</button>
      <a href="quot_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<script>
function addRow() {
    const tbody = document.getElementById('particularsBody');
    const idx   = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="text-center text-muted small">${idx}</td>
      <td><input type="text" name="particular[]" class="form-control form-control-sm" placeholder="Particular"></td>
      <td><input type="text" name="detail[]" class="form-control form-control-sm" placeholder="Details"></td>
      <td class="text-center">
        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
      </td>`;
    tbody.appendChild(tr);
    renumberRows();
}
function removeRow(btn) {
    if (document.querySelectorAll('#particularsBody tr').length <= 1) { alert('At least one row required.'); return; }
    btn.closest('tr').remove();
    renumberRows();
}
function renumberRows() {
    document.querySelectorAll('#particularsBody tr').forEach((tr, i) => { tr.cells[0].textContent = i+1; });
}
</script>

<?php require_once '../includes/footer.php'; $db->close(); ?>
