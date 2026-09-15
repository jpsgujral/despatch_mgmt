<?php
// Always return JSON — catch any fatal error
ini_set('display_errors', 0);
set_exception_handler(function($e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'msg'=>'Server error: '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine()]);
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'msg'=>'Fatal error: '.$err['message'].' in '.basename($err['file']).':'.$err['line']]);
    }
});

require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/phpmailer/src/Exception.php';
require_once __DIR__ . '/../includes/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../includes/phpmailer/src/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

function smtpPlainMail($value) {
    $value = trim((string)($value ?? ''));
    for ($i = 0; $i < 5; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return $value;
}

function smtpSocketCheckMail(string $host, int $port, int $timeout = 8): array {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return ['ok' => true];
    }
    return ['ok' => false, 'msg' => trim($errstr ?: ('Socket error ' . $errno))];
}

function buildDespatchMailer(
    bool $use_smtp,
    string $smtp_host,
    int $smtp_port,
    string $smtp_secure,
    string $smtp_user,
    string $smtp_pass,
    string $from_name,
    string $subject,
    string $body,
    string $alt_body,
    array $to_list,
    array $cc_list,
    string $pdf_path,
    string $pdf_filename
): PHPMailer {
    $mail = new PHPMailer(true);

    if ($use_smtp) {
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->Port = $smtp_port;
        if ($mail->Port === 465 || strtolower($smtp_secure) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (strtolower($smtp_secure) === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_user;
        $mail->Password = $smtp_pass;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->AuthType = 'LOGIN';
        $mail->Timeout = 30;
    } else {
        $mail->isMail();
    }

    $mail->CharSet = 'UTF-8';
    $mail->ContentType = 'text/html';
    $mail->From = $smtp_user;
    $mail->FromName = $from_name;
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->AltBody = $alt_body;

    foreach ($to_list as $r) {
        $mail->addAddress($r['email'], $r['name']);
    }

    foreach ($cc_list as $r) {
        $mail->addCC($r['email'], $r['name']);
    }

    $mail->addAttachment($pdf_path, $pdf_filename, 'base64', 'application/pdf');

    return $mail;
}

$db = getDB();
requirePerm('despatch', 'view');

header('Content-Type: application/json');

$id            = (int)($_POST['despatch_id']    ?? 0);
$recipient_ids = $_POST['recipient_ids']        ?? [];
$extra_email   = trim($_POST['extra_email']     ?? '');
$custom_note   = trim($_POST['custom_note']     ?? '');

if ($id < 1) { echo json_encode(['ok'=>false,'msg'=>'Invalid Despatch ID']); exit; }
if (empty($recipient_ids) && empty($extra_email)) {
    echo json_encode(['ok'=>false,'msg'=>'Please select at least one recipient.']); exit;
}

/* ══════════════════════════════════════════════
   1. LOAD DESPATCH DATA
══════════════════════════════════════════════ */
$d = $db->query("
    SELECT do.*,
           t.transporter_name, t.transporter_code, t.phone AS t_phone, t.gstin AS t_gstin,
           v.vendor_name, v.address AS v_address, v.city AS v_city, v.gstin AS v_gstin2,
           s.source_name,
           u.full_name AS prepared_by_name,
           po.po_number,
           au.signature_path AS auth_sig_path,
           cs.company_name, cs.address AS co_address, cs.city AS co_city,
           cs.state AS co_state, cs.gstin AS co_gstin, cs.phone AS co_phone,
           cs.email AS co_email, cs.pincode AS co_pincode, cs.pan AS co_pan,
           cs.smtp_host, cs.smtp_port, cs.smtp_user, cs.smtp_pass,
           cs.smtp_secure, cs.smtp_from_name,
           cs.seal_path, cs.mtc_sig_path, cs.checked_by_sig_path
    FROM despatch_orders do
    LEFT JOIN transporters t         ON do.transporter_id        = t.id
    LEFT JOIN vendors v              ON do.vendor_id             = v.id
    LEFT JOIN source_of_material s   ON do.source_of_material_id = s.id
    LEFT JOIN app_users u            ON do.created_by            = u.id
    LEFT JOIN purchase_orders po     ON do.po_id                 = po.id
    LEFT JOIN app_users au           ON au.id = (
                    SELECT id FROM app_users
                    WHERE status='Active' AND signature_path IS NOT NULL AND signature_path != ''
                    ORDER BY (id = do.created_by) DESC, id ASC
                    LIMIT 1)
    LEFT JOIN companies cs           ON cs.id = COALESCE(do.company_id, 1)
    WHERE do.id = $id
    LIMIT 1
")->fetch_assoc();

if (!$d) { echo json_encode(['ok'=>false,'msg'=>'Despatch order not found.']); exit; }

$items = $db->query("
    SELECT di.*, i.item_name, i.item_code, i.hsn_code, i.uom AS i_uom
    FROM despatch_items di
    JOIN items i ON di.item_id = i.id
    WHERE di.despatch_id = $id
    ORDER BY di.id
")->fetch_all(MYSQLI_ASSOC);

/* ══════════════════════════════════════════════
   2. SMTP CONFIG CHECK
══════════════════════════════════════════════ */
// Always read SMTP from company_settings (not companies table)
$smtp_row = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_name FROM company_settings LIMIT 1")->fetch_assoc();
$smtp_host   = smtpPlainMail($smtp_row['smtp_host']   ?? '');
$smtp_user   = smtpPlainMail($smtp_row['smtp_user']   ?? '');
$smtp_pass   = smtpPlainMail($smtp_row['smtp_pass']   ?? '');
$smtp_port   = (int)($smtp_row['smtp_port']  ?? 587);
$smtp_secure = smtpPlainMail($smtp_row['smtp_secure'] ?? 'tls');
$from_name   = !empty($smtp_row['smtp_from_name']) ? smtpPlainMail($smtp_row['smtp_from_name']) : smtpPlainMail($d['company_name']);

if (empty($smtp_host) || empty($smtp_user) || empty($smtp_pass)) {
    echo json_encode(['ok'=>false,'msg'=>'SMTP not fully configured. Please fill Host, Username and Password in Company Settings → Email / SMTP Settings.']);
    exit;
}

/* ══════════════════════════════════════════════
   3. BUILD RECIPIENT LIST (To)
══════════════════════════════════════════════ */
$to_list = [];   // ['email'=>..., 'name'=>...]

// Selected registered users
if (!empty($recipient_ids)) {
    $ids_safe = implode(',', array_map('intval', $recipient_ids));
    $res = $db->query("
        SELECT full_name, email FROM app_users
        WHERE id IN ($ids_safe)
          AND status = 'Active'
          AND email  != ''
          AND email IS NOT NULL
    ");
    while ($u = $res->fetch_assoc()) {
        $email = trim($u['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $to_list[] = ['email' => $email, 'name' => $u['full_name']];
        }
    }
}

// Extra one-off email
if ($extra_email && filter_var($extra_email, FILTER_VALIDATE_EMAIL)) {
    $to_list[] = ['email' => $extra_email, 'name' => ''];
}

if (empty($to_list)) {
    echo json_encode(['ok'=>false,'msg'=>'No valid recipient email addresses found. Check that selected users have email addresses set in User Management.']);
    exit;
}

/* ══════════════════════════════════════════════
   4. ADMIN CC
   Note: Gmail will NOT deliver to sender's own address.
   Admin is added to CC only if their email differs from smtp_user.
   All selected users go into To regardless.
══════════════════════════════════════════════ */
$admin_row = $db->query("
    SELECT full_name, email FROM app_users
    WHERE role='Admin' AND status='Active' AND email != '' AND email IS NOT NULL
    LIMIT 1
")->fetch_assoc();

$cc_list = [];
if ($admin_row) {
    $admin_email = strtolower(trim($admin_row['email']));
    $sender      = strtolower(trim($smtp_user));
    // Only CC admin if their email is different from the SMTP sender
    // AND not already in To list
    $in_to = array_filter($to_list, fn($r) => strtolower($r['email']) === $admin_email);
    if ($admin_email !== $sender && empty($in_to)) {
        $cc_list[] = ['email' => trim($admin_row['email']), 'name' => $admin_row['full_name']];
    }
}

/* ══════════════════════════════════════════════
   5. GENERATE PDF (pure PHP — no external binaries)
══════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/pdf_gen.php';

$pdf_path     = sys_get_temp_dir() . '/dsp_' . $id . '_' . time() . '.pdf';
$pdf_filename = 'Challan_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $d['challan_no']) . '.pdf';

// Ensure prepared_by_name is set — fall back to current session user for old orders
if (empty($d['prepared_by_name']) && !empty($_SESSION['user_id'])) {
    $uid_row = $db->query("SELECT full_name FROM app_users WHERE id=".(int)$_SESSION['user_id'])->fetch_assoc();
    if ($uid_row) $d['prepared_by_name'] = $uid_row['full_name'];
}

$gen     = new DespatchPDF();
$pdf_raw = $gen->build($d, $items);
file_put_contents($pdf_path, $pdf_raw);

if (!file_exists($pdf_path) || filesize($pdf_path) < 200) {
    echo json_encode(['ok'=>false,'msg'=>'PDF generation failed. Temp dir: '.sys_get_temp_dir()]);
    exit;
}

/* ══════════════════════════════════════════════
   6. BUILD EMAIL BODY
══════════════════════════════════════════════ */
$date_fmt     = date('d M Y', strtotime($d['despatch_date']));
$exp_del      = $d['expected_delivery'] ? date('d M Y', strtotime($d['expected_delivery'])) : 'N/A';
$items_summ   = implode(', ', array_map(fn($i) => htmlspecialchars($i['item_name']), $items));
$to_names     = implode(', ', array_map(fn($r) => $r['name'] ?: $r['email'], $to_list));

$email_body = '<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:0;background:#f4f6fb">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)">

  <tr><td style="background:linear-gradient(135deg,#1e3a5f,#2563a8);padding:22px 30px">
    <div style="color:#fff;font-size:20px;font-weight:700">' . htmlspecialchars($d['company_name']) . '</div>
    <div style="color:#a8c4e8;font-size:11px;margin-top:3px">Despatch Notification — Delivery Challan</div>
  </td></tr>

  <tr><td style="background:#2563a8;padding:9px 30px">
    <span style="color:#fff;font-size:13px;font-weight:600">
      ' . htmlspecialchars($d['challan_no']) . ' &nbsp;|&nbsp; ' . $date_fmt . '
    </span>
    <span style="float:right;background:#fff;color:#1e3a5f;padding:2px 12px;border-radius:12px;font-size:11px;font-weight:700">' . htmlspecialchars($d['status']) . '</span>
  </td></tr>

  <tr><td style="padding:26px 30px">
    <p style="margin:0 0 14px">Dear ' . htmlspecialchars($to_names) . ',</p>
    <p style="margin:0 0 18px">Please find the Delivery Challan attached as a PDF. Key details are summarised below.</p>
    ' . (!empty($custom_note) ? '<div style="background:#fff8e1;border-left:4px solid #f59e0b;padding:10px 14px;border-radius:4px;margin-bottom:18px"><strong>Note:</strong> ' . htmlspecialchars($custom_note) . '</div>' : '') . '

    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:18px">
      <tr style="background:#1e3a5f">
        <td colspan="4" style="color:#fff;font-weight:700;padding:7px 10px;font-size:11px">DESPATCH DETAILS</td>
      </tr>
      <tr style="background:#f0f4ff">
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600;width:25%">Challan No.</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0;width:25%">' . htmlspecialchars($d['challan_no']) . '</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600;width:25%">Despatch No.</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0;width:25%">' . htmlspecialchars($d['despatch_no']) . '</td>
      </tr>
      <tr>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Date</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . $date_fmt . '</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Expected Delivery</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . $exp_del . '</td>
      </tr>
      <tr style="background:#f0f4ff">
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Consignee</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . htmlspecialchars($d['consignee_name']) . ', ' . htmlspecialchars($d['consignee_city']) . '</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Vendor</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . htmlspecialchars($d['vendor_name'] ?? '-') . '</td>
      </tr>
      <tr>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Transporter</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . htmlspecialchars($d['transporter_name'] ?? '-') . '</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Vehicle No.</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . htmlspecialchars($d['vehicle_no'] ?? '-') . '</td>
      </tr>
      <tr style="background:#f0f4ff">
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">LR Number</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . htmlspecialchars($d['challan_no'] ?? '-') . '</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Source of Material</td>
        <td style="padding:6px 10px;border:1px solid #dde3f0">' . htmlspecialchars($d['source_name'] ?? '-') . '</td>
      </tr>
      <tr>
        <td style="padding:6px 10px;border:1px solid #dde3f0;font-weight:600">Items</td>
        <td colspan="3" style="padding:6px 10px;border:1px solid #dde3f0">' . $items_summ . '</td>
      </tr>
      <tr style="background:#e8f4ee">
        <td style="padding:8px 10px;border:1px solid #dde3f0;font-weight:700">Grand Total</td>
        <td style="padding:8px 10px;border:1px solid #dde3f0;font-weight:700;font-size:14px;color:#1e3a5f">&#8377;' . number_format((float)$d['total_amount'],2) . '</td>
        <td style="padding:8px 10px;border:1px solid #dde3f0;font-weight:600">Total Weight</td>
        <td style="padding:8px 10px;border:1px solid #dde3f0">' . number_format((float)$d['total_weight'],3) . ' kg</td>
      </tr>
    </table>

    ' . (($d['mtc_required'] ?? 'No') === 'Yes' ? '<div style="background:#fff8e1;border:1px solid #b8860b;border-radius:4px;padding:9px 14px;margin-bottom:16px;font-size:12px"><strong style="color:#856404">&#10003; Material Test Certificate (MTC) Required</strong> — details included in the attached PDF.</div>' : '') . '

    <p style="font-size:12px;color:#555;margin:0">Please review the attached PDF for the complete Delivery Challan. For queries, contact the despatch team.</p>
  </td></tr>

  <tr><td style="background:#f0f4ff;padding:14px 30px;border-top:1px solid #dde3f0">
    <table width="100%"><tr>
      <td style="font-size:10px;color:#888">' . htmlspecialchars($d['company_name']) . ' | GSTIN: ' . htmlspecialchars($d['co_gstin']) . '<br>This is a system-generated email.</td>
      <td align="right" style="font-size:10px;color:#888">Generated: ' . date('d M Y H:i') . '</td>
    </tr></table>
  </td></tr>

</table>
</td></tr></table>
</body></html>';

/* ══════════════════════════════════════════════
   7. SEND EMAIL
══════════════════════════════════════════════ */
$target_port = $smtp_port ?: 587;
$socket_check = smtpSocketCheckMail($smtp_host, $target_port);
$subject = 'Delivery Challan: ' . $d['challan_no']
         . ' | ' . $d['consignee_name']
         . ' | ' . $date_fmt;
$alt_body = 'Delivery Challan: ' . $d['challan_no']
          . "\nDate: " . $date_fmt
          . "\nConsignee: " . $d['consignee_name']
          . "\nTransporter: " . ($d['transporter_name'] ?? '-')
          . "\nVehicle: " . ($d['vehicle_no'] ?? '-')
          . "\nTotal: Rs." . number_format((float)$d['total_amount'], 2);

$mail = null;
$used_mail_transport_fallback = !$socket_check['ok'];
$primary_error = '';
$fallback_error = '';

try {
    $mail = buildDespatchMailer(
        $socket_check['ok'],
        $smtp_host,
        $target_port,
        $smtp_secure,
        $smtp_user,
        $smtp_pass,
        $from_name,
        $subject,
        $email_body,
        $alt_body,
        $to_list,
        $cc_list,
        $pdf_path,
        $pdf_filename
    );
    $mail->send();
} catch (Throwable $e) {
    $primary_error = trim(($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage());

    if (!$socket_check['ok']) {
        @unlink($pdf_path);
        echo json_encode(['ok'=>false,'msg'=>'Email failed: ' . $primary_error . ' SMTP unreachable: ' . $socket_check['msg'] . '.']); exit;
    }

    try {
        $mail = buildDespatchMailer(
            false,
            $smtp_host,
            $target_port,
            $smtp_secure,
            $smtp_user,
            $smtp_pass,
            $from_name,
            $subject,
            $email_body,
            $alt_body,
            $to_list,
            $cc_list,
            $pdf_path,
            $pdf_filename
        );
        $mail->send();
        $used_mail_transport_fallback = true;
    } catch (Throwable $fallback_exception) {
        $fallback_error = trim(($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $fallback_exception->getMessage());
        @unlink($pdf_path);
        echo json_encode(['ok'=>false,'msg'=>'Email failed: SMTP error: ' . $primary_error . '. Mail transport error: ' . $fallback_error]); exit;
    }
}

@unlink($pdf_path);   // clean up temp PDF regardless of outcome

/* ── Log to DB ── */
$db->query("CREATE TABLE IF NOT EXISTS despatch_email_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    despatch_id INT NOT NULL,
    sent_by     INT NOT NULL,
    sent_to     TEXT,
    cc_to       TEXT,
    sent_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    subject     VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add cc_to column if table existed before this update
$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='despatch_email_log' AND COLUMN_NAME='cc_to' LIMIT 1")->num_rows) {
    $db->query("ALTER TABLE despatch_email_log ADD COLUMN cc_to TEXT AFTER sent_to");
}

$log_to  = $db->real_escape_string(implode(', ', array_column($to_list,  'email')));
$log_cc  = $db->real_escape_string(implode(', ', array_column($cc_list,  'email')));
$log_sub = $db->real_escape_string($subject);
$by      = (int)($_SESSION['user_id'] ?? 0);
$db->query("INSERT INTO despatch_email_log (despatch_id, sent_by, sent_to, cc_to, subject)
            VALUES ($id, $by, '$log_to', '$log_cc', '$log_sub')");

$msg = 'Email sent' . ($used_mail_transport_fallback ? ' via server mail transport' : ' via SMTP') . '! To: ' . implode(', ', array_column($to_list, 'email'));
if (!empty($cc_list)) $msg .= ' | CC: ' . implode(', ', array_column($cc_list, 'email'));
$msg .= ' | PDF: ' . $pdf_filename;
echo json_encode(['ok'=>true, 'msg'=>$msg]);
