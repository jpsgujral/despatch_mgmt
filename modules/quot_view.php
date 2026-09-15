<?php
/**
 * Quotations Module — View & Printable Quotation
 * Path: modules/quotations/view.php
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('quotations', 'view');
require_once 'quot_helpers.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('quot_index.php'); }

$q = getQuotation($db, $id);
if (!$q) { redirect('quot_index.php'); }

$pageTitle = 'Quotation — ' . $q['quotation_no'];

// --- Status update via POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_status'])) {
    requirePerm('quotations', 'edit');
    $ns = sanitize($_POST['new_status']);
    $stmt = $db->prepare("UPDATE quotations SET status=? WHERE id=?");
    $stmt->bind_param("si", $ns, $id);
    $stmt->execute();
    $stmt->close();
    redirect("quot_view.php?id=$id");
}

// --- Email send via POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    requirePerm('quotations', 'view');

    $to_email   = sanitize($_POST['to_email'] ?? '');
    $to_name    = sanitize($_POST['to_name']  ?? '');
    $cc_email   = sanitize($_POST['cc_email'] ?? '');
    $subject    = sanitize($_POST['subject']  ?? '');
    $body_note  = sanitize($_POST['body_note'] ?? '');

    // Pull SMTP from company_settings
    $smtp_row = $db->query("SELECT smtp_host, smtp_user, smtp_pass FROM company_settings LIMIT 1");
    $smtp = ($smtp_row && $smtp_row->num_rows > 0) ? $smtp_row->fetch_assoc() : [];
    $smtp['smtp_port']      = 587;
    $smtp['mail_from_name'] = 'TSG Impex India Pvt Ltd';
    $smtp_ready = !empty(trim($smtp['smtp_host'] ?? '')) && !empty(trim($smtp['smtp_user'] ?? '')) && !empty(trim($smtp['smtp_pass'] ?? ''));

    $email_error = '';
    $email_sent  = false;

    if (!$to_email) {
        $email_error = 'Recipient email is required.';
    } elseif (!$smtp_ready) {
        $email_error = 'SMTP not configured. Please complete SMTP settings in Company Settings.';
    } else {
        require_once '../includes/phpmailer/src/Exception.php';
        require_once '../includes/phpmailer/src/PHPMailer.php';
        require_once '../includes/phpmailer/src/SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'localhost';
            $mail->SMTPAuth   = false;
            $mail->SMTPAutoTLS = false;
            $mail->Port       = 25;
            $mail->setFrom($smtp['smtp_user'] ?: 'support@tsgimpex.com', $smtp['mail_from_name'] ?: 'TSG Impex India Pvt Ltd');
            $mail->addAddress($to_email, $to_name);
            if ($cc_email) $mail->addCC($cc_email);
            $mail->addReplyTo($smtp['smtp_user'], $smtp['mail_from_name'] ?: 'TSG Impex India Pvt Ltd');
            $mail->CharSet  = 'UTF-8';
            $mail->isHTML(true);
            $mail->Subject = $subject;
            // Fetch logged-in user details + signature
        $sig_inline  = '';
        $sender_name = '';
        $sender_email= '';
        $sender_mob  = '';
        $uid = $_SESSION['user_id'] ?? 0;
        if ($uid) {
            $sig_row = $db->query("SELECT full_name, email, mobile, signature_path FROM app_users WHERE id=$uid")->fetch_assoc();
            $sender_name  = $sig_row['full_name'] ?? '';
            $sender_email = $sig_row['email']     ?? '';
            $sender_mob   = $sig_row['mobile']    ?? '';
            if (!empty($sig_row['signature_path'])) {
                $sig_key  = $sig_row['signature_path'];
                $sig_ext  = strtolower(pathinfo($sig_key, PATHINFO_EXTENSION));
                $mime_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
                $mime     = $mime_map[$sig_ext] ?? 'image/png';

                // Try reading from local disk first (cPanel file path)
                $local_path = '/home/tsgimpex/public_html/despatch_mgmt/uploads/' . $sig_key;
                if (file_exists($local_path)) {
                    $sig_data = file_get_contents($local_path);
                } else {
                    // Fallback: fetch from R2 via cURL
                    $sig_url = r2_url($sig_key);
                    $ch = curl_init($sig_url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_FOLLOWLOCATION => true,
                    ]);
                    $sig_data = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($http_code !== 200) $sig_data = false;
                }

                if ($sig_data) {
                    $sig_inline = 'data:' . $mime . ';base64,' . base64_encode($sig_data);
                }
            }
        }
        $mail->Body    = buildQuotationEmailHtml($q, $body_note, $sig_inline, $sender_name, $sender_email, $sender_mob);
            $mail->AltBody = buildQuotationEmailText($q, $body_note);
            $mail->send();
            $email_sent = true;

            // Auto-update status to 'sent' if still draft
            if ($q['status'] === 'draft') {
                $stmt = $db->prepare("UPDATE quotations SET status='sent' WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                $q['status'] = 'sent';
            }
        } catch (Exception $e) {
            $email_error = 'Mail error: ' . $mail->ErrorInfo;
        }
    }
}

// Flash messages
$flash = '';
if (isset($_GET['created']))  $flash = '<div class="alert alert-success py-2 small">✅ Quotation created successfully.</div>';
if (isset($_GET['updated']))  $flash = '<div class="alert alert-success py-2 small">✅ Quotation updated successfully.</div>';
if (!empty($email_sent))      $flash = '<div class="alert alert-success py-2 small">✅ Quotation emailed successfully to ' . htmlspecialchars($to_email) . '. Status updated to <strong>Sent</strong>.</div>';
if (!empty($email_error))     $flash = '<div class="alert alert-danger py-2 small">❌ ' . htmlspecialchars($email_error) . '</div>';

$isPrint = isset($_GET['print']);

if ($isPrint) {
    // Minimal print output — no header/footer includes
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($q['quotation_no']) ?> — TSG Impex India Pvt Ltd</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Arial', sans-serif; font-size: 10pt; color: #1a1a1a; background: #fff; }
.page { width: 210mm; margin: 0 auto; padding: 10mm 12mm; }

/* Header */
.qh-wrap { display: flex; align-items: stretch; border-bottom: 2px solid #6f1f8a; padding-bottom: 8px; margin-bottom: 8px; gap: 12px; }
.qh-logo { width: 80px; flex-shrink: 0; }
.qh-logo img { width: 80px; }
.qh-company { flex: 1; }
.qh-company h1 { font-size: 18pt; font-weight: 900; color: #6f1f8a; line-height: 1; margin-bottom: 2px; }
.qh-company .tagline { font-size: 8pt; font-style: italic; color: #555; margin-bottom: 6px; }
.qh-company .addr-row { font-size: 7.5pt; color: #333; line-height: 1.7; }
.qh-company .addr-row span { margin-right: 4px; }
.qh-divider { width: 1px; background: #ddd; }
.qh-meta { min-width: 160px; text-align: right; }
.qh-meta .quot-title { font-size: 18pt; font-weight: 900; color: #6f1f8a; margin-bottom: 8px; }
.qh-meta table { width: 100%; font-size: 8pt; border-collapse: collapse; }
.qh-meta td { padding: 2px 0; }
.qh-meta td:first-child { color: #555; text-align: left; padding-right: 6px; }
.qh-meta td:last-child { font-weight: 600; }

/* To / Reference */
.to-ref-wrap { display: flex; gap: 16px; margin-bottom: 10px; }
.to-box { flex: 1; }
.to-box .to-label { background: #6f1f8a; color: #fff; font-size: 7.5pt; font-weight: 700; padding: 2px 8px; display: inline-block; margin-bottom: 6px; }
.to-box .client-name { font-size: 10pt; font-weight: 700; }
.to-box .client-company { font-size: 9pt; font-weight: 700; margin-bottom: 2px; }
.to-box .small-line { font-size: 8pt; line-height: 1.6; }
.ref-box { width: 190px; border: 1px solid #ddd; padding: 8px; border-radius: 3px; }
.ref-box .ref-title { background: #6f1f8a; color: #fff; font-size: 7.5pt; font-weight: 700; padding: 2px 8px; display: inline-block; margin-bottom: 8px; }
.ref-box .ref-row { display: flex; font-size: 8pt; margin-bottom: 10px; }
.ref-box .ref-row .rl { width: 80px; color: #555; }
.ref-box .ref-row .rv { border-bottom: 1px solid #999; flex: 1; min-height: 14px; }

/* Greeting */
.greeting { font-size: 9pt; margin-bottom: 8px; line-height: 1.6; }
.greeting strong { color: #6f1f8a; }

/* Particulars Table */
.part-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8.5pt; }
.part-table th { background: #6f1f8a; color: #fff; padding: 5px 8px; font-weight: 700; }
.part-table td { border: 1px solid #ddd; padding: 5px 8px; vertical-align: middle; }
.part-table tr:nth-child(even) td { background: #faf5fc; }
.part-table .icon-cell { text-align: center; width: 28px; }
.part-table .sno { text-align: center; width: 32px; }
.part-table td.highlight { font-weight: 600; color: #6f1f8a; }

/* Terms boxes */
.terms-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
.terms-box { border: 1px solid #eee; border-radius: 3px; padding: 8px; }
.terms-box .t-title { font-size: 7.5pt; font-weight: 800; color: #6f1f8a; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; display: flex; align-items: center; gap: 4px; }
.terms-box .t-body { font-size: 7.5pt; line-height: 1.6; color: #333; white-space: pre-line; }

/* Footer */
.sign-footer { display: flex; align-items: flex-end; margin-bottom: 10px; }
.sign-left { flex: 1; font-size: 8.5pt; line-height: 1.7; }
.sign-logo { text-align: center; flex: 1; }
.sign-logo img { width: 70px; }
.sign-logo .cert { font-size: 7pt; color: #555; margin-top: 2px; }
.sign-right { flex: 1; font-size: 7.5pt; text-align: right; line-height: 1.8; }
.sign-line { border-top: 1px solid #333; width: 140px; margin-top: 24px; margin-bottom: 2px; }
.page-footer { border-top: 2px solid #6f1f8a; padding-top: 4px; text-align: center; font-size: 7pt; color: #6f1f8a; }
.page-footer .products { color: #555; }

@media print {
  body { margin: 0; }
  .page { width: 100%; padding: 8mm; }
  .no-print { display: none !important; }
}
</style>
</head>
<body>
<div class="page">

  <!-- Print controls (hidden on print) -->
  <div class="no-print" style="margin-bottom:12px; display:flex; gap:8px;">
    <button onclick="window.print()" style="background:#6f1f8a;color:#fff;border:none;padding:6px 16px;border-radius:4px;cursor:pointer;font-size:9pt;">
      🖨️ Print / Save as PDF
    </button>
    <a href="quot_view.php?id=<?= $id ?>" style="background:#eee;color:#333;border:none;padding:6px 16px;border-radius:4px;cursor:pointer;font-size:9pt;text-decoration:none;">
      ← Back
    </a>
  </div>

  <!-- Company Header -->
  <div class="qh-wrap">
    <div class="qh-logo">
      <!-- Placeholder for TSG logo — replace src with actual logo path -->
      <div style="width:80px;height:80px;background:#6f1f8a;display:flex;align-items:center;justify-content:center;border-radius:4px;">
        <span style="color:#fff;font-size:22pt;font-weight:900;">TSG</span>
      </div>
      <div style="font-size:5pt;text-align:center;color:#888;margin-top:2px;">ISO 9001:2008 CERTIFIED</div>
    </div>
    <div class="qh-company">
      <h1>TSG IMPEX INDIA PVT LTD</h1>
      <div class="tagline">The Fly Ash People</div>
      <div class="addr-row">
        <span>📍</span> 245B, 2nd Floor, Sant Nagar, East of Kailash, New Delhi - 110065, India
      </div>
      <div class="addr-row">
        <span>📞</span> +91-9810016022 &nbsp;&nbsp;
        <span>✉️</span> support@tsgimpex.com &nbsp;&nbsp;
        <span>🌐</span> www.tsgimpex.com
      </div>
    </div>
    <div class="qh-divider"></div>
    <div class="qh-meta">
      <div class="quot-title">QUOTATION</div>
      <table>
        <tr><td>Quotation No.</td><td>: <?= htmlspecialchars($q['quotation_no']) ?></td></tr>
        <tr><td>Date</td><td>: <?= date('d M Y', strtotime($q['quotation_date'])) ?></td></tr>
        <tr><td>Valid Till</td><td>: <?= date('d M Y', strtotime($q['valid_till'])) ?></td></tr>
        <tr><td>Quotation For</td><td>: <?= htmlspecialchars($q['quotation_for']) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- To / Reference -->
  <div class="to-ref-wrap">
    <div class="to-box">
      <div><span class="to-label">TO,</span></div>
      <div class="client-name"><?= htmlspecialchars($q['client_name']) ?></div>
      <div class="client-company"><?= htmlspecialchars($q['client_company']) ?></div>
      <div class="small-line">
        <?php if ($q['client_addr1']): ?><?= htmlspecialchars($q['client_addr1']) ?><br><?php endif; ?>
        <?php if ($q['client_addr2']): ?><?= htmlspecialchars($q['client_addr2']) ?><br><?php endif; ?>
        <?php
        $city_line = array_filter([$q['client_city'], $q['client_state'], $q['client_pin']]);
        if ($city_line): ?><?= htmlspecialchars(implode(' - ', $city_line)) ?><br><?php endif; ?>
        <?php if ($q['client_mobile']): ?>Mobile: <?= htmlspecialchars($q['client_mobile']) ?><br><?php endif; ?>
        <?php if ($q['client_email']): ?>Email: <?= htmlspecialchars($q['client_email']) ?><?php endif; ?>
      </div>
    </div>
    <div class="ref-box">
      <div><span class="ref-title">REFERENCE (If Any)</span></div>
      <div class="ref-row"><span class="rl">Reference Person</span><span style="margin:0 4px">:</span><span class="rv"><?= htmlspecialchars($q['ref_person'] ?? '') ?></span></div>
      <div class="ref-row"><span class="rl">Mobile</span><span style="margin:0 4px">:</span><span class="rv"><?= htmlspecialchars($q['ref_mobile'] ?? '') ?></span></div>
      <div class="ref-row"><span class="rl">Email</span><span style="margin:0 4px">:</span><span class="rv"><?= htmlspecialchars($q['ref_email'] ?? '') ?></span></div>
    </div>
  </div>

  <!-- Greeting -->
  <div class="greeting">
    Dear Sir/Madam,<br>
    Greetings from <strong>TSG Impex India Pvt Ltd</strong>.<br>
    We are pleased to submit our quotation for the supply of <strong><?= htmlspecialchars($q['quotation_for']) ?></strong> as per the following terms and conditions:
  </div>

  <!-- Particulars Table -->
  <table class="part-table">
    <thead>
      <tr>
        <th class="sno">S. No.</th>
        <th style="width:28px"></th>
        <th>Particulars</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $icons = ['📦','✅','🚛','📋','₹','📍','🏭'];
      foreach ($q['items'] as $i => $item):
        $icon = $icons[$i] ?? '•';
      ?>
      <tr>
        <td class="sno"><?= $i+1 ?></td>
        <td class="icon-cell"><?= $icon ?></td>
        <td class="highlight"><?= htmlspecialchars($item['particular']) ?></td>
        <td><?= htmlspecialchars($item['detail']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Terms Grid -->
  <div class="terms-grid">
    <?php
    $termsBlocks = [
      ['💳', 'PAYMENT TERMS',       $q['payment_terms']],
      ['⚖️', 'RATE REVISION CLAUSE', $q['rate_revision']],
      ['🚚', 'DELIVERY SCHEDULE',   $q['delivery_schedule']],
      ['📝', 'OTHER TERMS',         $q['other_terms']],
      ['📅', 'OFFER VALIDITY',      $q['offer_validity']],
    ];
    foreach ($termsBlocks as [$ico, $title, $body]):
      if (!trim($body ?? '')) continue;
    ?>
    <div class="terms-box">
      <div class="t-title"><?= $ico ?> <?= $title ?></div>
      <div class="t-body"><?= htmlspecialchars($body) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Closing -->
  <div class="greeting">
    We look forward to your valuable order and assure you of our best services at all times.<br><br>
    Thanking you,
  </div>

  <!-- Sign Footer -->
  <div class="sign-footer">
    <div class="sign-left">
      <strong>For TSG Impex India Pvt Ltd</strong>
      <div class="sign-line"></div>
      <div>Authorized Signatory</div>
    </div>
    <div class="sign-logo">
      <div style="width:60px;height:60px;background:#6f1f8a;display:flex;align-items:center;justify-content:center;border-radius:4px;margin:0 auto;">
        <span style="color:#fff;font-size:14pt;font-weight:900;">TSG</span>
      </div>
      <div class="cert">(ISO 9001:2015 Certified)</div>
    </div>
    <div class="sign-right">
      ✉️ support@tsgimpex.com<br>
      📞 +91-9810016022<br>
      🏢 GSTIN: 07AAECT1379A1ZL<br>
      🏦 CIN No: U51101DL2007PTC171102
    </div>
  </div>

  <div class="page-footer">
    <div class="products">Fly Ash &nbsp;·&nbsp; Aggregate &nbsp;·&nbsp; Steel &nbsp;·&nbsp; GGBS &nbsp;·&nbsp; Micro Silica &nbsp;·&nbsp; Cement</div>
    <div>🌐 www.tsgimpex.com</div>
  </div>
</div>
</body>
</html>
<?php
    $db->close();
    exit;
}

// --- NORMAL VIEW (with app header/footer) ---
require_once '../includes/header.php';
?>

<style>
.text-purple { color: #6f1f8a !important; }
.btn-purple { background:#6f1f8a; color:#fff; border-color:#6f1f8a; }
.btn-purple:hover { background:#5a1870; color:#fff; }
.section-heading { font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#6f1f8a; margin-bottom:.5rem; }
.info-label { font-size:.72rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
.info-value { font-size:.9rem; }
.part-table th { background:#6f1f8a !important; color:#fff; font-size:.8rem; }
.terms-card { border-left: 3px solid #6f1f8a; }
</style>

<div class="container-fluid py-3">
  <?= $flash ?>

  <!-- Page Header -->
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <h5 class="mb-0 fw-bold">
        <i class="bi bi-file-earmark-text text-purple me-2"></i>
        <?= htmlspecialchars($q['quotation_no']) ?>
        &nbsp;<?= quotationStatusBadge($q['status']) ?>
      </h5>
      <small class="text-muted">
        Dated <?= date('d M Y', strtotime($q['quotation_date'])) ?> &nbsp;·&nbsp;
        Valid till <?= date('d M Y', strtotime($q['valid_till'])) ?>
      </small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="quot_view.php?id=<?= $id ?>&print=1" target="_blank" class="btn btn-sm btn-purple">
        <i class="bi bi-printer me-1"></i>Print / PDF
      </a>
      <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#emailModal">
        <i class="bi bi-envelope me-1"></i>Send Email
      </button>
      <?php if (canDo('quotations', 'edit') && in_array($q['status'], ['draft','sent'])): ?>
      <a href="quot_edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-pencil me-1"></i>Edit
      </a>
      <?php endif; ?>
      <?php if (canDo('quotations', 'edit')): ?>
      <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#statusModal">
        <i class="bi bi-arrow-repeat me-1"></i>Change Status
      </button>
      <?php endif; ?>
      <a href="quot_index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="row g-3">
    <!-- Quotation Meta -->
    <div class="col-12 col-md-4">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="section-heading">Quotation Details</div>
          <div class="mb-2"><div class="info-label">Quotation No.</div><div class="info-value fw-bold text-purple"><?= htmlspecialchars($q['quotation_no']) ?></div></div>
          <div class="mb-2"><div class="info-label">Date</div><div class="info-value"><?= date('d M Y', strtotime($q['quotation_date'])) ?></div></div>
          <div class="mb-2"><div class="info-label">Valid Till</div><div class="info-value"><?= date('d M Y', strtotime($q['valid_till'])) ?></div></div>
          <div class="mb-2"><div class="info-label">Quotation For</div><div class="info-value"><?= htmlspecialchars($q['quotation_for']) ?></div></div>
          <div class="mb-2"><div class="info-label">Status</div><?= quotationStatusBadge($q['status']) ?></div>
          <?php if ($q['notes']): ?>
          <div class="mb-0"><div class="info-label">Notes</div><div class="info-value text-muted small"><?= nl2br(htmlspecialchars($q['notes'])) ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Client -->
    <div class="col-12 col-md-5">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="section-heading">Client</div>
          <div class="fw-bold fs-6"><?= htmlspecialchars($q['client_name']) ?></div>
          <div class="fw-semibold text-muted mb-2"><?= htmlspecialchars($q['client_company']) ?></div>
          <div class="small text-muted">
            <?php foreach ([$q['client_addr1'], $q['client_addr2']] as $a): if ($a): ?><?= htmlspecialchars($a) ?><br><?php endif; endforeach; ?>
            <?php $cl = array_filter([$q['client_city'], $q['client_state'], $q['client_pin']]); if ($cl): ?><?= htmlspecialchars(implode(' - ', $cl)) ?><br><?php endif; ?>
            <?php if ($q['client_mobile']): ?>📞 <?= htmlspecialchars($q['client_mobile']) ?><br><?php endif; ?>
            <?php if ($q['client_email']): ?>✉️ <?= htmlspecialchars($q['client_email']) ?><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Reference -->
    <div class="col-12 col-md-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="section-heading">Reference</div>
          <?php if ($q['ref_person'] || $q['ref_mobile'] || $q['ref_email']): ?>
          <div class="small">
            <?php if ($q['ref_person']): ?><div class="info-label">Person</div><div class="mb-2"><?= htmlspecialchars($q['ref_person']) ?></div><?php endif; ?>
            <?php if ($q['ref_mobile']): ?><div class="info-label">Mobile</div><div class="mb-2"><?= htmlspecialchars($q['ref_mobile']) ?></div><?php endif; ?>
            <?php if ($q['ref_email']): ?><div class="info-label">Email</div><div><?= htmlspecialchars($q['ref_email']) ?></div><?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted small">No reference provided.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Particulars -->
  <div class="card shadow-sm border-0 mt-3">
    <div class="card-body">
      <div class="section-heading">Particulars</div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle small mb-0">
          <thead><tr><th style="width:50px">S. No.</th><th>Particular</th><th>Details</th></tr></thead>
          <tbody>
            <?php foreach ($q['items'] as $i => $item): ?>
            <tr>
              <td class="text-center"><?= $i+1 ?></td>
              <td class="fw-semibold"><?= htmlspecialchars($item['particular']) ?></td>
              <td><?= htmlspecialchars($item['detail']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Terms -->
  <div class="row g-3 mt-1">
    <?php
    $termsBlocks = [
      ['bi-credit-card',  'Payment Terms',        $q['payment_terms'],     'col-md-4'],
      ['bi-truck',        'Delivery Schedule',    $q['delivery_schedule'], 'col-md-4'],
      ['bi-calendar-check','Offer Validity',      $q['offer_validity'],    'col-md-4'],
      ['bi-arrow-repeat', 'Rate Revision Clause', $q['rate_revision'],     'col-md-6'],
      ['bi-info-circle',  'Other Terms',          $q['other_terms'],       'col-md-6'],
    ];
    foreach ($termsBlocks as [$ico, $title, $body, $col]):
      if (!trim($body ?? '')) continue;
    ?>
    <div class="<?= $col ?>">
      <div class="card shadow-sm border-0 terms-card h-100">
        <div class="card-body py-2">
          <div class="section-heading"><i class="bi <?= $ico ?> me-1"></i><?= $title ?></div>
          <div class="small text-muted" style="white-space:pre-line"><?= htmlspecialchars($body) ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-3 text-muted small">
    Created: <?= date('d M Y H:i', strtotime($q['created_at'])) ?>
    &nbsp;·&nbsp; Last Updated: <?= date('d M Y H:i', strtotime($q['updated_at'])) ?>
  </div>
</div>

<!-- Status Change Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#6f1f8a; color:#fff;">
        <h6 class="modal-title mb-0">Change Status</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <label class="form-label small">New Status</label>
          <select name="new_status" class="form-select form-select-sm">
            <?php foreach (['draft','sent','accepted','rejected','expired'] as $s): ?>
            <option value="<?= $s ?>" <?= $q['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-purple">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#6f1f8a;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-envelope me-2"></i>Send Quotation by Email</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="send_email" value="1">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">To (Email) <span class="text-danger">*</span></label>
              <input type="email" name="to_email" class="form-control form-control-sm" required
                     value="<?= htmlspecialchars($q['client_email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">To (Name)</label>
              <input type="text" name="to_name" class="form-control form-control-sm"
                     value="<?= htmlspecialchars($q['client_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">CC (optional)</label>
              <input type="email" name="cc_email" class="form-control form-control-sm" placeholder="cc@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Subject</label>
              <input type="text" name="subject" class="form-control form-control-sm"
                     value="Quotation <?= htmlspecialchars($q['quotation_no']) ?> — TSG Impex India Pvt Ltd">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Additional Note <span class="text-muted fw-normal">(optional — appears above the quotation)</span></label>
              <textarea name="body_note" class="form-control form-control-sm" rows="3"
                        placeholder="e.g. Please find attached our quotation for your kind consideration..."></textarea>
            </div>
            <div class="col-12">
              <div class="alert alert-info py-2 small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                The full quotation letterhead will be sent as the email body.
                <?php if ($q['status'] === 'draft'): ?>
                Status will automatically update to <strong>Sent</strong> on successful delivery.
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success">
            <i class="bi bi-send me-1"></i>Send Email
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php

/**
 * Build full HTML email body matching TSG letterhead
 */
function buildQuotationEmailHtml($q, $note = '', $sig_inline = '', $sender_name = '', $sender_email = '', $sender_mob = '') {
    $icons    = ['','','','','','',''];
    $qNo      = htmlspecialchars($q['quotation_no']);
    $qDate    = date('d M Y', strtotime($q['quotation_date']));
    $vTill    = date('d M Y', strtotime($q['valid_till']));
    $quotFor  = htmlspecialchars($q['quotation_for']);
    $cName    = htmlspecialchars($q['client_name']);
    $cCompany = htmlspecialchars($q['client_company']);
    $cAddr1   = htmlspecialchars($q['client_addr1'] ?? '');
    $cAddr2   = htmlspecialchars($q['client_addr2'] ?? '');
    $cCity    = htmlspecialchars(implode(' - ', array_filter([$q['client_city'] ?? '', $q['client_state'] ?? '', $q['client_pin'] ?? ''])));
    $cMob     = htmlspecialchars($q['client_mobile'] ?? '');
    $cEmail   = htmlspecialchars($q['client_email'] ?? '');
    $refP     = htmlspecialchars($q['ref_person'] ?? '');
    $refM     = htmlspecialchars($q['ref_mobile'] ?? '');
    $refE     = htmlspecialchars($q['ref_email'] ?? '');
    $noteHtml = $note ? '<div style="background:#f0f7ff;border-left:4px solid #6f1f8a;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#333;border-radius:0 4px 4px 0;">' . nl2br(htmlspecialchars($note)) . '</div>' : '';

    // Particulars rows
    $itemRows = '';
    foreach ($q['items'] as $i => $item) {
        $bg  = ($i % 2 === 1) ? 'background:#faf5fc;' : '';
        $ico = $icons[$i] ?? '•';
        $p   = htmlspecialchars($item['particular']);
        $d   = htmlspecialchars($item['detail']);
        $n   = $i + 1;
        $itemRows .= "<tr>
          <td style=\"border:1px solid #ddd;padding:6px 10px;text-align:center;width:36px;{$bg}\">{$n}</td>
          <td style=\"border:1px solid #ddd;padding:6px 10px;font-weight:600;color:#6f1f8a;{$bg}\">{$p}</td>
          <td style=\"border:1px solid #ddd;padding:6px 10px;{$bg}\">{$d}</td>
        </tr>";
    }

    // Terms blocks in 2-col table
    $termsBlocks = [
        ['', 'PAYMENT TERMS',        $q['payment_terms']],
        ['', 'RATE REVISION CLAUSE', $q['rate_revision']],
        ['', 'DELIVERY SCHEDULE',    $q['delivery_schedule']],
        ['', 'OTHER TERMS',          $q['other_terms']],
        ['', 'OFFER VALIDITY',       $q['offer_validity']],
    ];
    $termsTableHtml = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px;">';
    $col = 0;
    foreach ($termsBlocks as [$ico, $title, $body]) {
        if (!trim($body ?? '')) continue;
        $bodyClean = str_replace(['\\r\\n','\\n','\\r',"\r\n","\r","\n"], "\n", $body);
        $bodyEsc   = nl2br(htmlspecialchars($bodyClean));
        if ($col % 2 === 0) $termsTableHtml .= '<tr>';
        $termsTableHtml .= "<td style=\"width:50%;vertical-align:top;padding:4px;\">
          <div style=\"border:1px solid #eee;border-radius:4px;padding:10px;\">
            <div style=\"font-size:11px;font-weight:800;color:#6f1f8a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;\">{$ico} {$title}</div>
            <div style=\"font-size:11px;line-height:1.6;color:#333;\">{$bodyEsc}</div>
          </div>
        </td>";
        if ($col % 2 === 1) $termsTableHtml .= '</tr>';
        $col++;
    }
    if ($col % 2 === 1) $termsTableHtml .= '<td></td></tr>';
    $termsTableHtml .= '</table>';

    $h  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>';
    $h .= '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">';
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;"><tr><td align="center">';
    $h .= '<table width="680" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">';

    // Header bar
    $h .= '<tr style="background:#6f1f8a;"><td style="padding:18px 24px;">';
    $h .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
    $h .= '<td><div style="font-size:22px;font-weight:900;color:#fff;">TSG IMPEX INDIA PVT LTD</div>';
    $h .= '<div style="font-size:11px;color:#e0c8f0;font-style:italic;">The Fly Ash People</div>';
    $h .= '<div style="font-size:10px;color:#d0b0e8;margin-top:4px;">245B, 2nd Floor, Sant Nagar, East of Kailash, New Delhi - 110065<br>';
    $h .= '+91-9810016022 &nbsp; support@tsgimpex.com &nbsp; www.tsgimpex.com</div></td>';
    $h .= '<td align="right" style="vertical-align:top;"><div style="font-size:18px;font-weight:900;color:#fff;border:2px solid rgba(255,255,255,.4);padding:6px 16px;border-radius:4px;">QUOTATION</div></td>';
    $h .= '</tr></table></td></tr>';

    // Meta bar
    $h .= '<tr style="background:#f9f0ff;border-bottom:1px solid #e8d5f5;"><td style="padding:10px 24px;">';
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:11px;color:#555;"><tr>';
    $h .= "<td><strong>Quotation No.</strong> : {$qNo}</td>";
    $h .= "<td><strong>Date</strong> : {$qDate}</td>";
    $h .= "<td><strong>Valid Till</strong> : {$vTill}</td>";
    $h .= "<td><strong>For</strong> : {$quotFor}</td>";
    $h .= '</tr></table></td></tr>';

    // Body
    $h .= '<tr><td style="padding:20px 24px;">';
    $h .= $noteHtml;

    // To / Reference
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr>';
    $h .= '<td style="vertical-align:top;width:55%;">';
    $h .= '<div style="background:#6f1f8a;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;display:inline-block;margin-bottom:6px;">TO,</div><br>';
    $h .= "<strong style=\"font-size:14px;\">{$cName}</strong><br>";
    $h .= "<strong style=\"font-size:12px;color:#555;\">{$cCompany}</strong><br>";
    $h .= "<span style=\"font-size:11px;color:#666;line-height:1.8;\">{$cAddr1}<br>{$cAddr2}<br>{$cCity}<br>{$cMob} &nbsp; {$cEmail}</span>";
    $h .= '</td><td style="width:5%;"></td>';
    $h .= '<td style="vertical-align:top;width:40%;border:1px solid #ddd;padding:10px;border-radius:4px;">';
    $h .= '<div style="background:#6f1f8a;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;display:inline-block;margin-bottom:8px;">REFERENCE (If Any)</div><br>';
    $h .= "<table cellpadding=\"3\" style=\"font-size:11px;color:#555;\">";
    $h .= "<tr><td>Reference Person</td><td>: {$refP}</td></tr>";
    $h .= "<tr><td>Mobile</td><td>: {$refM}</td></tr>";
    $h .= "<tr><td>Email</td><td>: {$refE}</td></tr>";
    $h .= '</table></td></tr></table>';

    // Greeting
    $h .= "<p style=\"font-size:13px;line-height:1.7;margin-bottom:16px;\">Dear Sir/Madam,<br>";
    $h .= "Greetings from <strong style=\"color:#6f1f8a;\">TSG Impex India Pvt Ltd</strong>.<br>";
    $h .= "We are pleased to submit our quotation for the supply of <strong style=\"color:#6f1f8a;\">{$quotFor}</strong> as per the following terms and conditions:</p>";

    // Particulars
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px;font-size:12px;">';
    $h .= '<thead><tr style="background:#6f1f8a;color:#fff;">';
    $h .= '<th style="padding:8px 10px;width:36px;">S.No.</th>';
    $h .= '<th style="padding:8px 10px;text-align:left;">Particulars</th><th style="padding:8px 10px;text-align:left;">Details</th>';
    $h .= '</tr></thead><tbody>' . $itemRows . '</tbody></table>';

    // Terms
    $h .= $termsTableHtml;

    // Closing
    $h .= '<p style="font-size:13px;line-height:1.7;margin-bottom:20px;">We look forward to your valuable order and assure you of our best services at all times.<br><br>Thanking you,</p>';

    // Sign
    $sigImg = $sig_inline ? '<img src="' . $sig_inline . '" height="50" style="height:50px;width:auto;max-width:180px;display:block;margin:6px 0 4px 0;" alt="Signature">' : '<br><br>';
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:12px;border-top:1px solid #eee;padding-top:16px;"><tr>';
    $senderLine  = $sender_name  ? '<strong>' . htmlspecialchars($sender_name)  . '</strong><br>' : '';
    $senderEmail = $sender_email ? htmlspecialchars($sender_email) . '<br>' : 'support@tsgimpex.com<br>';
    $senderMob   = $sender_mob   ? htmlspecialchars($sender_mob)   . '<br>' : '+91-9810016022<br>';
    $h .= '<td style="vertical-align:bottom;"><strong>For TSG Impex India Pvt Ltd</strong><br>' . $sigImg;
    $h .= '<div style="border-top:1px solid #333;width:150px;margin-bottom:4px;"></div>';
    $h .= $senderLine . 'Authorized Signatory</td>';
    $h .= '<td align="right" style="vertical-align:bottom;font-size:11px;color:#555;line-height:1.8;">';
    $h .= $senderEmail . $senderMob . 'GSTIN: 07AAECT1379A1ZL<br>CIN: U51101DL2007PTC171102</td>';
    $h .= '</tr></table>';

    $h .= '</td></tr>';

    // Footer bar
    $h .= '<tr style="background:#6f1f8a;"><td style="padding:10px 24px;text-align:center;font-size:10px;color:#e0c8f0;">';
    $h .= 'Fly Ash | Aggregate | Steel | GGBS | Micro Silica | Cement<br>';
    $h .= 'www.tsgimpex.com | ISO 9001:2015 Certified</td></tr>';

    $h .= '</table></td></tr></table></body></html>';
    return $h;
}

/**
 * Plain-text fallback
 */
function buildQuotationEmailText($q, $note = '') {
    $lines = [];
    $lines[] = "TSG IMPEX INDIA PVT LTD — QUOTATION";
    $lines[] = str_repeat('-', 50);
    $lines[] = "Quotation No. : " . $q['quotation_no'];
    $lines[] = "Date          : " . date('d M Y', strtotime($q['quotation_date']));
    $lines[] = "Valid Till    : " . date('d M Y', strtotime($q['valid_till']));
    $lines[] = "For           : " . $q['quotation_for'];
    $lines[] = "";
    $lines[] = "TO: " . $q['client_name'] . " / " . $q['client_company'];
    if ($note) { $lines[] = ""; $lines[] = $note; }
    $lines[] = "";
    $lines[] = "PARTICULARS:";
    foreach ($q['items'] as $i => $item) {
        $lines[] = ($i+1) . ". " . $item['particular'] . " : " . $item['detail'];
    }
    $lines[] = "";
    $lines[] = "For details, please view the HTML version of this email.";
    $lines[] = "";
    $lines[] = "For TSG Impex India Pvt Ltd";
    $lines[] = "+91-9810016022 | support@tsgimpex.com | www.tsgimpex.com";
    return implode("
", $lines);
}
?>

<?php require_once '../includes/footer.php'; $db->close(); ?>
