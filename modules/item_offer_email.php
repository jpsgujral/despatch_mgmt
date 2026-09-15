<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/phpmailer/src/Exception.php';
require_once __DIR__ . '/../includes/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../includes/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$db = getDB();
requirePerm('items', 'view');

function offerPlainMail(string $value): string {
    $value = trim((string)$value);
    for ($i = 0; $i < 5; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) {
            break;
        }
        $value = $decoded;
    }
    return $value;
}

function offerSocketCheck(string $host, int $port, int $timeout = 8): array {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return ['ok' => true];
    }
    return ['ok' => false, 'msg' => trim($errstr ?: ('Socket error ' . $errno))];
}

function buildOfferMailer(
    bool $use_smtp,
    string $smtp_host,
    int $smtp_port,
    string $smtp_secure,
    string $smtp_user,
    string $smtp_pass,
    string $from_name,
    string $from_email,
    string $subject,
    string $html,
    string $alt_body,
    array $to_list,
    array $cc_list
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
            ],
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
    $mail->Body = $html;
    $mail->AltBody = $alt_body;

    foreach ($to_list as $r) {
        $mail->addAddress($r['email'], $r['name']);
    }

    foreach ($cc_list as $r) {
        $mail->addCC($r['email'], $r['name']);
    }

    return $mail;
}

function offerEmailLog(string $line): void {
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/item_offer_email.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
}

function offerMailConfig(mysqli $db): array {
    $smtp_row = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_name,
        company_name, address, city, state, pincode, phone, email
        FROM company_settings LIMIT 1")->fetch_assoc();

    $smtp_host = offerPlainMail($smtp_row['smtp_host'] ?? '');
    $smtp_port = (int)($smtp_row['smtp_port'] ?? 587);
    $smtp_user = offerPlainMail($smtp_row['smtp_user'] ?? '');
    $smtp_pass = offerPlainMail($smtp_row['smtp_pass'] ?? '');
    $smtp_secure = strtolower(offerPlainMail($smtp_row['smtp_secure'] ?? 'tls'));
    $from_name = offerPlainMail($smtp_row['smtp_from_name'] ?? '');
    $company_name = offerPlainMail($smtp_row['company_name'] ?? 'TSG Impex India Pvt Ltd');
    $company_email = offerPlainMail($smtp_row['email'] ?? '');
    $from_email = filter_var($smtp_user, FILTER_VALIDATE_EMAIL) ? $smtp_user : $company_email;

    return [
        'smtp_host' => $smtp_host,
        'smtp_port' => $smtp_port,
        'smtp_user' => $smtp_user,
        'smtp_pass' => $smtp_pass,
        'smtp_secure' => $smtp_secure,
        'from_name' => ($from_name !== '' ? $from_name : $company_name),
        'from_email' => $from_email,
        'company_name' => $company_name,
        'company_address' => trim(implode(', ', array_filter([
            offerPlainMail($smtp_row['address'] ?? ''),
            offerPlainMail($smtp_row['city'] ?? ''),
            offerPlainMail($smtp_row['state'] ?? ''),
            offerPlainMail($smtp_row['pincode'] ?? ''),
        ]))),
        'company_phone' => offerPlainMail($smtp_row['phone'] ?? ''),
        'company_email' => $company_email,
    ];
}

function buildOfferHtml(array $company, array $offer, array $item, string $sender_name): string {
    $customer_line = trim($offer['attention_name']) !== '' ? htmlspecialchars($offer['attention_name']) : 'Sir / Madam';
    $item_name = htmlspecialchars($item['item_name']);
    $uom = htmlspecialchars($offer['uom']);
    $qty = number_format((float)$offer['required_qty'], 3);
    $rate = number_format((float)$offer['rate'], 2);
    $gst_rate = number_format((float)$offer['gst_rate'], 2);
    $validity = $offer['validity_until'] !== '' ? date('d M Y', strtotime($offer['validity_until'])) : 'Till further notice';
    $source = htmlspecialchars($offer['source_of_material']);
    $payment = nl2br(htmlspecialchars($offer['payment_terms']));
    $dispatch = nl2br(htmlspecialchars($offer['dispatch_mode']));
    $delivery = nl2br(htmlspecialchars($offer['delivery_schedule']));
    $destination = nl2br(htmlspecialchars($offer['destination']));
    $quality = nl2br(htmlspecialchars($offer['quality_note']));
    $remarks = trim($offer['remarks']) !== '' ? nl2br(htmlspecialchars($offer['remarks'])) : '';
    $company_name = htmlspecialchars($company['company_name']);
    $company_address = htmlspecialchars($company['company_address']);
    $company_phone = htmlspecialchars($company['company_phone']);
    $company_email = htmlspecialchars($company['company_email']);
    $subject_item = htmlspecialchars($offer['offer_title']);

    return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Commercial Offer</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#1f2937;">
  <div style="max-width:780px;margin:0 auto;padding:24px 14px;">
    <div style="background:linear-gradient(135deg,#0f5132,#198754);padding:26px 30px;border-radius:18px 18px 0 0;color:#fff;">
      <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;opacity:.85;">Commercial Offer</div>
      <div style="font-size:28px;font-weight:700;margin-top:8px;">{$subject_item}</div>
      <div style="font-size:14px;opacity:.92;margin-top:8px;">{$company_name}</div>
    </div>

    <div style="background:#fff;border:1px solid #dbe5ef;border-top:none;border-radius:0 0 18px 18px;padding:28px 30px;">
      <p style="margin:0 0 18px 0;font-size:15px;">Dear {$customer_line},</p>
      <p style="margin:0 0 18px 0;line-height:1.7;font-size:15px;">
        We are pleased to share our commercial offer for the supply of <strong>{$item_name}</strong>.
        Please find the proposed supply terms below.
      </p>

      <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:22px 0;">
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;width:36%;font-weight:700;">Product</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$item_name}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Required Quantity</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$qty} {$uom}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Rate</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">Rs. {$rate} per {$uom} + {$gst_rate}% GST</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Source of Material</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$source}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Destination / Project</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$destination}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Dispatch / Packing</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$dispatch}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Payment Terms</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$payment}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Delivery Schedule</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$delivery}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Offer Validity</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">Valid up to {$validity}</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;background:#f8fbfd;border:1px solid #dbe5ef;font-weight:700;">Quality / Specification</td>
          <td style="padding:14px 16px;border:1px solid #dbe5ef;">{$quality}</td>
        </tr>
      </table>
HTML
    . ($remarks !== '' ? '<div style="margin-top:16px;padding:14px 16px;background:#fff8e1;border:1px solid #f2d38b;border-radius:12px;"><div style="font-weight:700;margin-bottom:6px;">Additional Notes</div><div style="line-height:1.7;">' . $remarks . '</div></div>' : '') .
<<<HTML

      <p style="margin:24px 0 0 0;line-height:1.7;font-size:15px;">
        We would be glad to support this requirement and can align dispatches as per your purchase order
        and delivery plan. Please feel free to share any clarification or revision needed.
      </p>

      <div style="margin-top:28px;padding-top:20px;border-top:1px solid #dbe5ef;">
        <div style="font-size:15px;font-weight:700;">Thanks &amp; Regards</div>
        <div style="font-size:15px;margin-top:6px;">{$sender_name}</div>
        <div style="font-size:14px;color:#4b5563;margin-top:4px;">{$company_name}</div>
        <div style="font-size:13px;color:#6b7280;margin-top:8px;line-height:1.7;">
          {$company_address}<br>
          {$company_phone} | {$company_email}
        </div>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
}

function buildOfferAltBody(array $offer, array $item, array $company): string {
    $validity = $offer['validity_until'] !== '' ? date('d M Y', strtotime($offer['validity_until'])) : 'Till further notice';
    return "Commercial Offer\n"
        . "Company: {$company['company_name']}\n"
        . "Item: {$item['item_name']}\n"
        . "Quantity: {$offer['required_qty']} {$offer['uom']}\n"
        . "Rate: Rs. {$offer['rate']} per {$offer['uom']} + {$offer['gst_rate']}% GST\n"
        . "Source: {$offer['source_of_material']}\n"
        . "Destination: {$offer['destination']}\n"
        . "Payment Terms: {$offer['payment_terms']}\n"
        . "Delivery Schedule: {$offer['delivery_schedule']}\n"
        . "Offer Validity: {$validity}\n";
}

$db->query("CREATE TABLE IF NOT EXISTS item_offer_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    customer_company VARCHAR(200) DEFAULT '',
    attention_name VARCHAR(150) DEFAULT '',
    recipient_email VARCHAR(190) NOT NULL,
    cc_email VARCHAR(190) DEFAULT '',
    offer_title VARCHAR(255) DEFAULT '',
    destination VARCHAR(255) DEFAULT '',
    source_of_material VARCHAR(255) DEFAULT '',
    required_qty DECIMAL(12,3) DEFAULT 0,
    uom VARCHAR(20) DEFAULT 'MT',
    rate DECIMAL(12,2) DEFAULT 0,
    gst_rate DECIMAL(5,2) DEFAULT 5.00,
    dispatch_mode VARCHAR(255) DEFAULT '',
    payment_terms VARCHAR(255) DEFAULT '',
    delivery_schedule VARCHAR(255) DEFAULT '',
    quality_note VARCHAR(255) DEFAULT '',
    validity_until DATE DEFAULT NULL,
    remarks TEXT,
    email_subject VARCHAR(255) DEFAULT '',
    email_body MEDIUMTEXT,
    send_status VARCHAR(20) DEFAULT 'Sent',
    error_message TEXT,
    sent_by INT DEFAULT 0,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_item_offer_item (item_id),
    KEY idx_item_offer_email (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    showAlert('danger', 'Invalid item selected.');
    redirect('items.php');
}

$item = $db->query("SELECT * FROM items WHERE id=$id LIMIT 1")->fetch_assoc();
if (!$item) {
    showAlert('danger', 'Item not found.');
    redirect('items.php');
}

$sources = $db->query("SELECT source_name FROM source_of_material ORDER BY source_name ASC")->fetch_all(MYSQLI_ASSOC);
$company = offerMailConfig($db);
$current_user_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Sales Team'));

$offer_defaults = [
    'customer_company' => '',
    'attention_name' => '',
    'recipient_email' => '',
    'cc_email' => '',
    'offer_title' => ($item['item_name'] ?? '') . ' Supply Offer',
    'destination' => '',
    'source_of_material' => '',
    'required_qty' => '',
    'uom' => ($item['uom'] ?? 'MT'),
    'rate' => '',
    'gst_rate' => '5.00',
    'dispatch_mode' => 'Bulkers (without compressor)',
    'payment_terms' => 'Payment due within 30 days from delivery date.',
    'delivery_schedule' => 'Dispatch as per mutually agreed schedule after receipt of Purchase Order.',
    'quality_note' => 'Material quality/specification as mutually agreed / applicable standard.',
    'validity_until' => date('Y-m-d', strtotime('+30 days')),
    'remarks' => "Rates are subject to revision in case of major diesel price movement or source-side price revision.",
];

$offer = $offer_defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('items', 'create');

    foreach ($offer_defaults as $key => $default) {
        $offer[$key] = sanitize($_POST[$key] ?? $default);
    }
    $offer['required_qty'] = (float)($offer['required_qty'] ?: 0);
    $offer['rate'] = (float)($offer['rate'] ?: 0);
    $offer['gst_rate'] = (float)($offer['gst_rate'] ?: 0);

    $errors = [];
    if ($offer['attention_name'] === '') $errors[] = 'Attention / contact person is required.';
    if ($offer['recipient_email'] === '' || !filter_var($offer['recipient_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid recipient email is required.';
    if ($offer['required_qty'] <= 0) $errors[] = 'Required quantity must be greater than zero.';
    if ($offer['rate'] <= 0) $errors[] = 'Rate must be greater than zero.';
    if ($offer['source_of_material'] === '') $errors[] = 'Source of material is required.';
    if ($offer['payment_terms'] === '') $errors[] = 'Payment terms are required.';
    if ($offer['validity_until'] === '') $errors[] = 'Validity date is required.';

    if (!$errors) {
        $subject = 'Commercial Offer - ' . $item['item_name'] . ' | ' . $company['company_name'];
        $html = buildOfferHtml($company, $offer, $item, $current_user_name);
        $alt_body = buildOfferAltBody($offer, $item, $company);

        $send_status = 'Sent';
        $error_message = '';
        try {
            if ($company['smtp_host'] === '' || $company['smtp_user'] === '' || $company['smtp_pass'] === '') {
                throw new Exception('SMTP is not fully configured in Company Settings.');
            }
            if (empty($company['from_email']) || !filter_var($company['from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Valid sender email not found. Set Company Email or SMTP Username as valid email.');
            }

            $to_list = [[
                'email' => $offer['recipient_email'],
                'name' => $offer['attention_name'],
            ]];
            $cc_list = [];
            if ($offer['cc_email'] !== '' && filter_var($offer['cc_email'], FILTER_VALIDATE_EMAIL)) {
                $cc_list[] = ['email' => $offer['cc_email'], 'name' => ''];
            }

            $target_port = (int)($company['smtp_port'] ?: ($company['smtp_secure'] === 'ssl' ? 465 : 587));
            $socket_check = offerSocketCheck($company['smtp_host'], $target_port);
            $used_mail_transport_fallback = !$socket_check['ok'];
            $primary_error = '';
            $fallback_error = '';
            $mail = null;

            try {
                $mail = buildOfferMailer(
                    $socket_check['ok'],
                    $company['smtp_host'],
                    $target_port,
                    $company['smtp_secure'],
                    $company['smtp_user'],
                    $company['smtp_pass'],
                    $company['from_name'],
                    $company['from_email'],
                    $subject,
                    $html,
                    $alt_body,
                    $to_list,
                    $cc_list
                );
                $mail->send();
            } catch (Throwable $smtp_ex) {
                $primary_error = trim(($mail && !empty($mail->ErrorInfo)) ? $mail->ErrorInfo : $smtp_ex->getMessage());

                if (!$socket_check['ok']) {
                    // SMTP not reachable, try server mail transport fallback directly
                    try {
                        $mail = buildOfferMailer(
                            false,
                            $company['smtp_host'],
                            $target_port,
                            $company['smtp_secure'],
                            $company['smtp_user'],
                            $company['smtp_pass'],
                            $company['from_name'],
                            $company['from_email'],
                            $subject,
                            $html,
                            $alt_body,
                            $to_list,
                            $cc_list
                        );
                        $mail->send();
                        $used_mail_transport_fallback = true;
                    } catch (Throwable $fallback_ex) {
                        $fallback_error = trim(($mail && !empty($mail->ErrorInfo)) ? $mail->ErrorInfo : $fallback_ex->getMessage());
                        throw new Exception('Email failed: SMTP unreachable (' . ($socket_check['msg'] ?? 'unknown') . '), and mail transport failed: ' . $fallback_error);
                    }
                } else {
                    // SMTP reachable but auth/send failed, try fallback for business continuity
                    try {
                        $mail = buildOfferMailer(
                            false,
                            $company['smtp_host'],
                            $target_port,
                            $company['smtp_secure'],
                            $company['smtp_user'],
                            $company['smtp_pass'],
                            $company['from_name'],
                            $company['from_email'],
                            $subject,
                            $html,
                            $alt_body,
                            $to_list,
                            $cc_list
                        );
                        $mail->send();
                        $used_mail_transport_fallback = true;
                    } catch (Throwable $fallback_ex) {
                        $fallback_error = trim(($mail && !empty($mail->ErrorInfo)) ? $mail->ErrorInfo : $fallback_ex->getMessage());
                        throw new Exception('Email failed: SMTP error: ' . $primary_error . '. Mail transport error: ' . $fallback_error);
                    }
                }
            }

            $error_message = $used_mail_transport_fallback ? 'Sent via server mail transport fallback.' : 'Sent via SMTP.';
            offerEmailLog('SUCCESS item_id=' . $id . ' to=' . $offer['recipient_email'] . ' mode=' . ($used_mail_transport_fallback ? 'mail' : 'smtp'));
        } catch (Throwable $e) {
            $send_status = 'Failed';
            $error_message = $e->getMessage();
            offerEmailLog('FAILED item_id=' . $id . ' to=' . ($offer['recipient_email'] ?? '') . ' err=' . $error_message);
        }

        $esc = fn($v) => $db->real_escape_string((string)$v);
        $validity_sql = $offer['validity_until'] !== '' ? "'" . $esc($offer['validity_until']) . "'" : 'NULL';
        $db->query("INSERT INTO item_offer_letters
            (item_id, customer_company, attention_name, recipient_email, cc_email, offer_title, destination,
             source_of_material, required_qty, uom, rate, gst_rate, dispatch_mode, payment_terms,
             delivery_schedule, quality_note, validity_until, remarks, email_subject, email_body,
             send_status, error_message, sent_by)
            VALUES
            ($id, '{$esc($offer['customer_company'])}', '{$esc($offer['attention_name'])}', '{$esc($offer['recipient_email'])}',
             '{$esc($offer['cc_email'])}', '{$esc($offer['offer_title'])}', '{$esc($offer['destination'])}',
             '{$esc($offer['source_of_material'])}', {$offer['required_qty']}, '{$esc($offer['uom'])}', {$offer['rate']},
             {$offer['gst_rate']}, '{$esc($offer['dispatch_mode'])}', '{$esc($offer['payment_terms'])}',
             '{$esc($offer['delivery_schedule'])}', '{$esc($offer['quality_note'])}', $validity_sql,
             '{$esc($offer['remarks'])}', '{$esc($subject)}', '{$esc($html)}', '{$esc($send_status)}',
             '{$esc($error_message)}', " . (int)($_SESSION['user_id'] ?? 0) . ")");

        if ($send_status === 'Sent') {
            showAlert('success', 'Offer email sent successfully. ' . htmlspecialchars($error_message));
            redirect('item_offer_email.php?id=' . $id);
        }
        showAlert('danger', 'Offer email could not be sent: ' . $error_message);
    } else {
        showAlert('danger', implode(' ', $errors));
    }
}

$history = $db->query("SELECT * FROM item_offer_letters WHERE item_id=$id ORDER BY id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-envelope-paper me-2"></i>Item Offer Email';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1 fw-bold">Send Commercial Offer</h5>
        <div class="text-muted small"><?= htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']) ?></div>
    </div>
    <a href="items.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Item Master</a>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <form method="post" class="card shadow-sm">
            <div class="card-header fw-bold" style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff">
                <i class="bi bi-send me-2"></i>Offer Details
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Customer Company</label>
                        <input type="text" name="customer_company" class="form-control" value="<?= htmlspecialchars($offer['customer_company']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Attention Person *</label>
                        <input type="text" name="attention_name" class="form-control" required value="<?= htmlspecialchars($offer['attention_name']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Recipient Email *</label>
                        <input type="email" name="recipient_email" class="form-control" required value="<?= htmlspecialchars($offer['recipient_email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">CC Email</label>
                        <input type="email" name="cc_email" class="form-control" value="<?= htmlspecialchars($offer['cc_email']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Offer Heading</label>
                        <input type="text" name="offer_title" class="form-control" value="<?= htmlspecialchars($offer['offer_title']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Required Quantity *</label>
                        <input type="number" name="required_qty" step="0.001" min="0" class="form-control" required value="<?= htmlspecialchars((string)$offer['required_qty']) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">UOM</label>
                        <input type="text" name="uom" class="form-control" value="<?= htmlspecialchars($offer['uom']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Rate *</label>
                        <input type="number" name="rate" step="0.01" min="0" class="form-control" required value="<?= htmlspecialchars((string)$offer['rate']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">GST %</label>
                        <input type="number" name="gst_rate" step="0.01" min="0" class="form-control" value="<?= htmlspecialchars((string)$offer['gst_rate']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Source of Material *</label>
                        <div class="input-group">
                            <select name="source_of_material" class="form-select" required>
                                <option value="">Select Source</option>
                                <?php foreach ($sources as $src): ?>
                                <option value="<?= htmlspecialchars($src['source_name']) ?>" <?= $offer['source_of_material'] === $src['source_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($src['source_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="input-group-text bg-light"><i class="bi bi-geo-alt"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Destination / Project</label>
                        <input type="text" name="destination" class="form-control" value="<?= htmlspecialchars($offer['destination']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Dispatch / Packing</label>
                        <input type="text" name="dispatch_mode" class="form-control" value="<?= htmlspecialchars($offer['dispatch_mode']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Validity Till *</label>
                        <input type="date" name="validity_until" class="form-control" required value="<?= htmlspecialchars($offer['validity_until']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Payment Terms *</label>
                        <textarea name="payment_terms" class="form-control" rows="2" required><?= htmlspecialchars($offer['payment_terms']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Delivery Schedule</label>
                        <textarea name="delivery_schedule" class="form-control" rows="2"><?= htmlspecialchars($offer['delivery_schedule']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Quality / Specification</label>
                        <textarea name="quality_note" class="form-control" rows="2"><?= htmlspecialchars($offer['quality_note']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="remarks" class="form-control" rows="3"><?= htmlspecialchars($offer['remarks']) ?></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small text-muted">Email will be sent using Company Settings SMTP.</div>
                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send-check me-1"></i>Send Offer Email</button>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-bold" style="background:#f8fafc">
                <i class="bi bi-eye me-2"></i>Format Summary
            </div>
            <div class="card-body">
                <div class="small text-muted mb-2">Based on your attached format, the built-in offer now includes:</div>
                <ul class="small mb-0">
                    <li>professional offer heading for the selected item</li>
                    <li>quantity, rate, GST, source, destination</li>
                    <li>payment terms and delivery schedule</li>
                    <li>validity date and specification note</li>
                    <li>company closing block for email use</li>
                </ul>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header fw-bold" style="background:#f8fafc">
                <i class="bi bi-clock-history me-2"></i>Recent Offer History
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Sent On</th>
                            <th>Recipient</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Qty</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($h['sent_at'])) ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($h['attention_name']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($h['recipient_email']) ?></div>
                            </td>
                            <td class="text-end">Rs.<?= number_format((float)$h['rate'], 2) ?></td>
                            <td class="text-end"><?= number_format((float)$h['required_qty'], 3) ?></td>
                            <td>
                                <span class="badge bg-<?= $h['send_status'] === 'Sent' ? 'success' : 'danger' ?>">
                                    <?= htmlspecialchars($h['send_status']) ?>
                                </span>
                                <?php if (!empty($h['error_message'])): ?>
                                <div class="small text-muted mt-1" style="max-width:220px;white-space:normal;">
                                    <?= htmlspecialchars($h['error_message']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?>
                        <tr><td colspan="5" class="text-muted p-3">No offer emails sent for this item yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
