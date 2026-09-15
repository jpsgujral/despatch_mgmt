<?php
/**
 * Quotations Module — Create New Quotation
 * Path: modules/quotations/create.php
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('quotations', 'create');
require_once 'quot_helpers.php';

$db = getDB();
$pageTitle = 'New Quotation';
$errors = [];

// Pre-fill defaults
$defaults = defaultTerms();
$today    = date('Y-m-d');
$validTill = date('Y-m-d', strtotime('+30 days'));
$quotNo   = ''; // generated on save

// --- HANDLE POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Generate quotation number
    $quotNo = generateQuotationNo($db);

    // Collect & validate
    $qDate     = sanitize($_POST['quotation_date'] ?? $today);
    $vTill     = sanitize($_POST['valid_till'] ?? $validTill);
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
    $userId    = $_SESSION['user_id'] ?? null;

    // Particulars rows
    $particulars = $_POST['particular'] ?? [];
    $details     = $_POST['detail'] ?? [];

    if (!$cName)    $errors[] = 'Client Name is required.';
    if (!$cCompany) $errors[] = 'Client Company is required.';
    if (!$qDate)    $errors[] = 'Quotation Date is required.';

    if (empty($errors)) {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("INSERT INTO quotations
                (quotation_no, quotation_date, valid_till, quotation_for,
                 client_name, client_company, client_addr1, client_addr2,
                 client_city, client_state, client_pin, client_mobile, client_email,
                 ref_person, ref_mobile, ref_email,
                 payment_terms, delivery_schedule, offer_validity, rate_revision, other_terms,
                 status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param(
                "sssssssssssssssssssssssi",
                $quotNo, $qDate, $vTill, $quotFor,
                $cName, $cCompany, $cAddr1, $cAddr2,
                $cCity, $cState, $cPin, $cMobile, $cEmail,
                $refPerson, $refMobile, $refEmail,
                $payTerms, $delSched, $offerVal, $rateRev, $otherT,
                $status, $notes, $userId
            );
            $stmt->execute();
            $qId = $db->insert_id;
            $stmt->close();

            // Insert particulars
            $istmt = $db->prepare("INSERT INTO quotation_items (quotation_id, sort_order, particular, detail) VALUES (?,?,?,?)");
            foreach ($particulars as $i => $part) {
                $part   = sanitize($part);
                $detail = sanitize($details[$i] ?? '');
                $order  = $i + 1;
                $istmt->bind_param("iiss", $qId, $order, $part, $detail);
                $istmt->execute();
            }
            $istmt->close();

            $db->commit();
            redirect("quot_view.php?id=$qId&created=1");
        } catch (Exception $e) {
            $db->rollback();
            $errors[] = 'DB Error: ' . $e->getMessage();
        }
    }
}

// For form display — use POSTed data or defaults
$formData = array_merge([
    'quotation_date'    => $today,
    'valid_till'        => $validTill,
    'quotation_for'     => 'Supply of Ashcrete Fly Ash',
    'client_name'       => '',
    'client_company'    => '',
    'client_addr1'      => '',
    'client_addr2'      => '',
    'client_city'       => '',
    'client_state'      => '',
    'client_pin'        => '',
    'client_mobile'     => '',
    'client_email'      => '',
    'ref_person'        => '',
    'ref_mobile'        => '',
    'ref_email'         => '',
    'status'            => 'draft',
    'notes'             => '',
], $defaults, array_map('htmlspecialchars', $_POST ?: []));

$particulars = $_POST['particular'] ?? array_column(defaultParticulars(), 'particular');
$details     = $_POST['detail']     ?? array_column(defaultParticulars(), 'detail');

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
.drag-handle { cursor:grab; color:#aaa; }
</style>

<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h5 class="mb-0 fw-bold"><i class="bi bi-file-earmark-plus text-purple me-2"></i>New Quotation</h5>
      <small class="text-muted">Number will be auto-generated on save</small>
    </div>
    <a href="quot_index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger small py-2">
    <?php foreach($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST">

    <!-- Row 1: Quotation Meta + Client -->
    <div class="row g-3 mb-3">

      <!-- Quotation Details -->
      <div class="col-12 col-md-4">
        <div class="card shadow-sm border-0 card-section h-100">
          <div class="card-body">
            <div class="section-heading">Quotation Details</div>
            <div class="mb-2">
              <label class="form-label small mb-1">Date <span class="text-danger">*</span></label>
              <input type="date" name="quotation_date" class="form-control form-control-sm" required value="<?= $formData['quotation_date'] ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Valid Till</label>
              <input type="date" name="valid_till" class="form-control form-control-sm" value="<?= $formData['valid_till'] ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Quotation For</label>
              <input type="text" name="quotation_for" class="form-control form-control-sm" value="<?= $formData['quotation_for'] ?>">
            </div>
            <div class="mb-0">
              <label class="form-label small mb-1">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php foreach (['draft','sent','accepted','rejected','expired'] as $s): ?>
                <option value="<?= $s ?>" <?= $formData['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Client Info -->
      <div class="col-12 col-md-5">
        <div class="card shadow-sm border-0 card-section h-100">
          <div class="card-body">
            <div class="section-heading">Client Information</div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small mb-1">Name <span class="text-danger">*</span></label>
                <input type="text" name="client_name" class="form-control form-control-sm" required value="<?= $formData['client_name'] ?>">
              </div>
              <div class="col-6">
                <label class="form-label small mb-1">Company <span class="text-danger">*</span></label>
                <input type="text" name="client_company" class="form-control form-control-sm" required value="<?= $formData['client_company'] ?>">
              </div>
              <div class="col-12">
                <label class="form-label small mb-1">Address Line 1</label>
                <input type="text" name="client_addr1" class="form-control form-control-sm" value="<?= $formData['client_addr1'] ?>">
              </div>
              <div class="col-12">
                <label class="form-label small mb-1">Address Line 2</label>
                <input type="text" name="client_addr2" class="form-control form-control-sm" value="<?= $formData['client_addr2'] ?>">
              </div>
              <div class="col-4">
                <label class="form-label small mb-1">City</label>
                <input type="text" name="client_city" class="form-control form-control-sm" value="<?= $formData['client_city'] ?>">
              </div>
              <div class="col-4">
                <label class="form-label small mb-1">State</label>
                <input type="text" name="client_state" class="form-control form-control-sm" value="<?= $formData['client_state'] ?>">
              </div>
              <div class="col-4">
                <label class="form-label small mb-1">PIN</label>
                <input type="text" name="client_pin" class="form-control form-control-sm" value="<?= $formData['client_pin'] ?>">
              </div>
              <div class="col-6">
                <label class="form-label small mb-1">Mobile</label>
                <input type="text" name="client_mobile" class="form-control form-control-sm" value="<?= $formData['client_mobile'] ?>">
              </div>
              <div class="col-6">
                <label class="form-label small mb-1">Email</label>
                <input type="email" name="client_email" class="form-control form-control-sm" value="<?= $formData['client_email'] ?>">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Reference -->
      <div class="col-12 col-md-3">
        <div class="card shadow-sm border-0 card-section h-100">
          <div class="card-body">
            <div class="section-heading">Reference (If Any)</div>
            <div class="mb-2">
              <label class="form-label small mb-1">Reference Person</label>
              <input type="text" name="ref_person" class="form-control form-control-sm" value="<?= $formData['ref_person'] ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Mobile</label>
              <input type="text" name="ref_mobile" class="form-control form-control-sm" value="<?= $formData['ref_mobile'] ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Email</label>
              <input type="email" name="ref_email" class="form-control form-control-sm" value="<?= $formData['ref_email'] ?>">
            </div>
            <div class="mb-0">
              <label class="form-label small mb-1">Internal Notes</label>
              <textarea name="notes" class="form-control form-control-sm" rows="3"><?= $formData['notes'] ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Particulars Table -->
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="section-heading mb-0">Particulars</div>
          <button type="button" class="btn btn-sm btn-outline-purple" onclick="addRow()">
            <i class="bi bi-plus-circle me-1"></i>Add Row
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-sm align-middle table-particulars mb-0" id="particularsTable">
            <thead>
              <tr>
                <th style="width:32px">#</th>
                <th>Particular</th>
                <th>Details</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody id="particularsBody">
              <?php foreach ($particulars as $i => $part): ?>
              <tr>
                <td class="text-center text-muted small"><?= $i+1 ?></td>
                <td><input type="text" name="particular[]" class="form-control form-control-sm" value="<?= htmlspecialchars($part) ?>"></td>
                <td><input type="text" name="detail[]" class="form-control form-control-sm" value="<?= htmlspecialchars($details[$i] ?? '') ?>"></td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeRow(this)" title="Remove">
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Terms & Conditions -->
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-body">
        <div class="section-heading mb-2">Terms &amp; Conditions</div>
        <div class="row g-3">
          <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label small mb-1 fw-semibold">Payment Terms</label>
            <textarea name="payment_terms" class="form-control form-control-sm" rows="3"><?= $formData['payment_terms'] ?></textarea>
          </div>
          <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label small mb-1 fw-semibold">Delivery Schedule</label>
            <textarea name="delivery_schedule" class="form-control form-control-sm" rows="3"><?= $formData['delivery_schedule'] ?></textarea>
          </div>
          <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label small mb-1 fw-semibold">Offer Validity</label>
            <textarea name="offer_validity" class="form-control form-control-sm" rows="3"><?= $formData['offer_validity'] ?></textarea>
          </div>
          <div class="col-12 col-sm-6 col-md-6">
            <label class="form-label small mb-1 fw-semibold">Rate Revision Clause</label>
            <textarea name="rate_revision" class="form-control form-control-sm" rows="3"><?= $formData['rate_revision'] ?></textarea>
          </div>
          <div class="col-12 col-sm-6 col-md-6">
            <label class="form-label small mb-1 fw-semibold">Other Terms</label>
            <textarea name="other_terms" class="form-control form-control-sm" rows="3"><?= $formData['other_terms'] ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="d-flex flex-wrap gap-2 mb-4">
      <button type="submit" class="btn btn-purple">
        <i class="bi bi-save me-1"></i> Save Quotation
      </button>
      <button type="button" class="btn btn-outline-purple" onclick="openPreview()">
        <i class="bi bi-eye me-1"></i> Preview
      </button>
      <a href="quot_index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#6f1f8a;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-file-earmark-text me-2"></i>Quotation Preview</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="previewFrame" style="width:100%;height:80vh;border:none;"></iframe>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-sm btn-purple" id="saveFromPreview">
          <i class="bi bi-save me-1"></i> Looks Good — Save
        </button>
      </div>
    </div>
  </div>
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
        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeRow(this)">
          <i class="bi bi-trash"></i>
        </button>
      </td>`;
    tbody.appendChild(tr);
    renumberRows();
}

function removeRow(btn) {
    if (document.querySelectorAll('#particularsBody tr').length <= 1) {
        alert('At least one row required.');
        return;
    }
    btn.closest('tr').remove();
    renumberRows();
}

function renumberRows() {
    document.querySelectorAll('#particularsBody tr').forEach((tr, i) => {
        tr.cells[0].textContent = i + 1;
    });
}

function g(name) {
    const el = document.querySelector('[name="' + name + '"]');
    return el ? el.value.trim() : '';
}
function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function openPreview() {
    const icons = ['📦','✅','🚛','📋','₹','📍','🏭'];
    const parts  = [...document.querySelectorAll('[name="particular[]"]')].map(e => e.value.trim());
    const dets   = [...document.querySelectorAll('[name="detail[]"]')].map(e => e.value.trim());

    let itemRows = '';
    parts.forEach((p, i) => {
        itemRows += `<tr>
          <td style="text-align:center;width:32px">${i+1}</td>
          <td style="text-align:center;width:28px">${icons[i]||'•'}</td>
          <td style="font-weight:600;color:#6f1f8a">${esc(p)}</td>
          <td>${esc(dets[i]||'')}</td>
        </tr>`;
    });

    const termsBlocks = [
        ['💳','PAYMENT TERMS',        g('payment_terms')],
        ['⚖️','RATE REVISION CLAUSE', g('rate_revision')],
        ['🚚','DELIVERY SCHEDULE',    g('delivery_schedule')],
        ['📝','OTHER TERMS',          g('other_terms')],
        ['📅','OFFER VALIDITY',       g('offer_validity')],
    ];
    let termsHtml = '';
    termsBlocks.forEach(([ico, title, body]) => {
        if (!body) return;
        termsHtml += `<div style="border:1px solid #eee;border-radius:3px;padding:8px;">
          <div style="font-size:7.5pt;font-weight:800;color:#6f1f8a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">${ico} ${title}</div>
          <div style="font-size:7.5pt;line-height:1.6;color:#333;white-space:pre-line">${esc(body)}</div>
        </div>`;
    });

    const qDate   = g('quotation_date') ? new Date(g('quotation_date')).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
    const vTill   = g('valid_till')     ? new Date(g('valid_till')).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';

    const cityLine = [g('client_city'), g('client_state'), g('client_pin')].filter(Boolean).join(' - ');

    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;font-size:10pt;color:#1a1a1a;background:#fff;padding:10mm 12mm}
    .qh{display:flex;align-items:stretch;border-bottom:2px solid #6f1f8a;padding-bottom:8px;margin-bottom:8px;gap:12px}
    .qh-logo{width:80px;flex-shrink:0;display:flex;flex-direction:column;align-items:center}
    .qh-logo .box{width:80px;height:80px;background:#6f1f8a;display:flex;align-items:center;justify-content:center;border-radius:4px}
    .qh-logo .box span{color:#fff;font-size:22pt;font-weight:900}
    .qh-co{flex:1}
    .qh-co h1{font-size:18pt;font-weight:900;color:#6f1f8a;line-height:1;margin-bottom:2px}
    .qh-co .tag{font-size:8pt;font-style:italic;color:#555;margin-bottom:6px}
    .qh-co .addr{font-size:7.5pt;color:#333;line-height:1.7}
    .qh-div{width:1px;background:#ddd}
    .qh-meta{min-width:160px;text-align:right}
    .qh-meta .qt{font-size:18pt;font-weight:900;color:#6f1f8a;margin-bottom:8px}
    .qh-meta table{width:100%;font-size:8pt;border-collapse:collapse}
    .qh-meta td{padding:2px 0}
    .qh-meta td:first-child{color:#555;text-align:left;padding-right:6px}
    .qh-meta td:last-child{font-weight:600}
    .to-ref{display:flex;gap:16px;margin-bottom:10px}
    .to-box{flex:1}
    .to-label{background:#6f1f8a;color:#fff;font-size:7.5pt;font-weight:700;padding:2px 8px;display:inline-block;margin-bottom:6px}
    .ref-box{width:190px;border:1px solid #ddd;padding:8px;border-radius:3px}
    .ref-title{background:#6f1f8a;color:#fff;font-size:7.5pt;font-weight:700;padding:2px 8px;display:inline-block;margin-bottom:8px}
    .ref-row{display:flex;font-size:8pt;margin-bottom:10px}
    .ref-row .rl{width:80px;color:#555}
    .ref-row .rv{border-bottom:1px solid #999;flex:1;min-height:14px}
    .greeting{font-size:9pt;margin-bottom:8px;line-height:1.6}
    .greeting strong{color:#6f1f8a}
    table.pt{width:100%;border-collapse:collapse;margin-bottom:10px;font-size:8.5pt}
    table.pt th{background:#6f1f8a;color:#fff;padding:5px 8px;font-weight:700}
    table.pt td{border:1px solid #ddd;padding:5px 8px;vertical-align:middle}
    table.pt tr:nth-child(even) td{background:#faf5fc}
    .terms-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}
    .sign-footer{display:flex;align-items:flex-end;margin-bottom:10px}
    .sign-left{flex:1;font-size:8.5pt;line-height:1.7}
    .sign-logo{text-align:center;flex:1}
    .sign-right{flex:1;font-size:7.5pt;text-align:right;line-height:1.8}
    .sign-line{border-top:1px solid #333;width:140px;margin-top:24px;margin-bottom:2px}
    .page-footer{border-top:2px solid #6f1f8a;padding-top:4px;text-align:center;font-size:7pt;color:#6f1f8a}
    .draft-banner{background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:6px 12px;font-size:8pt;font-weight:600;color:#856404;margin-bottom:10px;text-align:center}
    </style></head><body>
    <div class="draft-banner">⚠️ PREVIEW — Not yet saved</div>
    <div class="qh">
      <div class="qh-logo">
        <div class="box"><span>TSG</span></div>
        <div style="font-size:5pt;text-align:center;color:#888;margin-top:2px">ISO 9001:2008 CERTIFIED</div>
      </div>
      <div class="qh-co">
        <h1>TSG IMPEX INDIA PVT LTD</h1>
        <div class="tag">The Fly Ash People</div>
        <div class="addr">📍 245B, 2nd Floor, Sant Nagar, East of Kailash, New Delhi - 110065, India</div>
        <div class="addr">📞 +91-9810016022 &nbsp;&nbsp; ✉️ support@tsgimpex.com &nbsp;&nbsp; 🌐 www.tsgimpex.com</div>
      </div>
      <div class="qh-div"></div>
      <div class="qh-meta">
        <div class="qt">QUOTATION</div>
        <table>
          <tr><td>Quotation No.</td><td>: <em>Auto-generated</em></td></tr>
          <tr><td>Date</td><td>: ${esc(qDate)}</td></tr>
          <tr><td>Valid Till</td><td>: ${esc(vTill)}</td></tr>
          <tr><td>Quotation For</td><td>: ${esc(g('quotation_for'))}</td></tr>
        </table>
      </div>
    </div>

    <div class="to-ref">
      <div class="to-box">
        <div><span class="to-label">TO,</span></div>
        <div style="font-size:10pt;font-weight:700">${esc(g('client_name'))}</div>
        <div style="font-size:9pt;font-weight:700;margin-bottom:2px">${esc(g('client_company'))}</div>
        <div style="font-size:8pt;line-height:1.6">
          ${g('client_addr1') ? esc(g('client_addr1'))+'<br>' : ''}
          ${g('client_addr2') ? esc(g('client_addr2'))+'<br>' : ''}
          ${cityLine ? esc(cityLine)+'<br>' : ''}
          ${g('client_mobile') ? 'Mobile: '+esc(g('client_mobile'))+'<br>' : ''}
          ${g('client_email') ? 'Email: '+esc(g('client_email')) : ''}
        </div>
      </div>
      <div class="ref-box">
        <div><span class="ref-title">REFERENCE (If Any)</span></div>
        <div class="ref-row"><span class="rl">Reference Person</span><span style="margin:0 4px">:</span><span class="rv">${esc(g('ref_person'))}</span></div>
        <div class="ref-row"><span class="rl">Mobile</span><span style="margin:0 4px">:</span><span class="rv">${esc(g('ref_mobile'))}</span></div>
        <div class="ref-row"><span class="rl">Email</span><span style="margin:0 4px">:</span><span class="rv">${esc(g('ref_email'))}</span></div>
      </div>
    </div>

    <div class="greeting">
      Dear Sir/Madam,<br>
      Greetings from <strong>TSG Impex India Pvt Ltd</strong>.<br>
      We are pleased to submit our quotation for the supply of <strong>${esc(g('quotation_for'))}</strong> as per the following terms and conditions:
    </div>

    <table class="pt">
      <thead><tr><th style="width:32px">S. No.</th><th style="width:28px"></th><th>Particulars</th><th>Details</th></tr></thead>
      <tbody>${itemRows}</tbody>
    </table>

    <div class="terms-grid">${termsHtml}</div>

    <div class="greeting">We look forward to your valuable order and assure you of our best services at all times.<br><br>Thanking you,</div>

    <div class="sign-footer">
      <div class="sign-left">
        <strong>For TSG Impex India Pvt Ltd</strong>
        <div class="sign-line"></div>
        <div>Authorized Signatory</div>
      </div>
      <div class="sign-logo">
        <div style="width:60px;height:60px;background:#6f1f8a;display:flex;align-items:center;justify-content:center;border-radius:4px;margin:0 auto">
          <span style="color:#fff;font-size:14pt;font-weight:900">TSG</span>
        </div>
        <div style="font-size:7pt;color:#555;margin-top:2px">(ISO 9001:2015 Certified)</div>
      </div>
      <div class="sign-right">
        ✉️ support@tsgimpex.com<br>📞 +91-9810016022<br>🏢 GSTIN: 07AAECT1379A1ZL<br>🏦 CIN: U51101DL2007PTC171102
      </div>
    </div>
    <div class="page-footer">
      <div style="color:#555">Fly Ash · Aggregate · Steel · GGBS · Micro Silica · Cement</div>
      <div>🌐 www.tsgimpex.com</div>
    </div>
    </body></html>`;

    const frame = document.getElementById('previewFrame');
    frame.srcdoc = html;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('saveFromPreview').addEventListener('click', function () {
        bootstrap.Modal.getInstance(document.getElementById('previewModal')).hide();
        document.querySelector('form').requestSubmit();
    });
});
</script>

<?php require_once '../includes/footer.php'; $db->close(); ?>
