<?php
/**
 * Daily Activity Report  Cron Script
 * 
 * Sends a summary email at end of day covering:
 * 1. New despatches created today
 * 2. Deliveries completed today
 * 3. Pending / In-Transit summary
 * 4. Freight & payment summary
 *
 * SETUP:
 * 1. Upload to: /despatch_mgmt/cron/daily_report.php
 * 2. Add cron job in cPanel:
 *    59 23 * * * /usr/local/bin/php /home/tsgimpex/public_html/despatch_mgmt/cron/daily_report.php >> /home/tsgimpex/logs/daily_report.log 2>&1
 * 3. Configure recipient emails below
 */

/*  RECIPIENT EMAILS  */
/* Add/remove emails here. The report will be sent to ALL of these. */
$REPORT_RECIPIENTS = [
    'support@tsgimpex.com',
    'tsgaccounts@tsgimpex.com',
];

/*  Bootstrap  */
define('CRON_MODE', true);
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';

$db = getDB();

function reportCliArg(string $name): ?string {
    if (PHP_SAPI !== 'cli' || empty($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
        return null;
    }

    $prefix = '--' . $name . '=';
    foreach ($_SERVER['argv'] as $arg) {
        if (strpos((string)$arg, $prefix) === 0) {
            return substr((string)$arg, strlen($prefix));
        }
    }

    return null;
}

function reportStop(int $code = 0) {
    if (defined('REPORT_INLINE_MODE') && REPORT_INLINE_MODE) {
        if ($code !== 0) {
            throw new RuntimeException('Daily report failed.');
        }
        return;
    }

    exit($code);
}

function reportSmtpPlain($value) {
    $value = trim((string)($value ?? ''));
    for ($i = 0; $i < 5; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return $value;
}

function reportSocketCheck(string $host, int $port, int $timeout = 8): array {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return ['ok' => true];
    }
    return ['ok' => false, 'msg' => trim($errstr ?: ('Socket error ' . $errno))];
}

function buildDailyReportMailer(array $smtp, string $company, string $subject, string $html, array $recipients, bool $use_smtp): PHPMailer\PHPMailer\PHPMailer {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    if ($use_smtp) {
        $target_port = (int)($smtp['smtp_port'] ?: ($smtp['smtp_secure'] === 'ssl' ? 465 : 587));
        $mail->isSMTP();
        $mail->Host = $smtp['smtp_host'];
        $mail->Port = $target_port;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['smtp_user'];
        $mail->Password = $smtp['smtp_pass'];
        if ($smtp['smtp_secure'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtp['smtp_secure'] === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->Timeout = 20;
    } else {
        $mail->isMail();
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($smtp['smtp_user'], $smtp['smtp_from_name'] ?: $company);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $html;
    $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','</tr>','</td>'], ["\n","\n","\n","\t"], $html));

    foreach ($recipients as $email) {
        $mail->addAddress(trim($email));
    }

    return $mail;
}

/*  Validate recipients  */
if (empty($REPORT_RECIPIENTS)) {
    echo date('Y-m-d H:i:s') . " ERROR: No recipient emails configured in daily_report.php\n";
    reportStop(1);
}

/*  Load SMTP settings  */
// SMTP from company_settings
$smtp = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_name, company_name
    FROM company_settings LIMIT 1")->fetch_assoc();
if (empty($smtp['smtp_host']) || empty($smtp['smtp_user'])) {
    // Fallback to companies table
    $smtp = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure,
        smtp_from_name, company_name FROM companies WHERE smtp_host!='' AND smtp_user!=''
        ORDER BY id ASC LIMIT 1")->fetch_assoc();
}

if (empty($smtp['smtp_host']) || empty($smtp['smtp_user'])) {
    echo date('Y-m-d H:i:s') . " ERROR: SMTP not configured in Company Settings\n";
    reportStop(1);
}

$smtp['smtp_host'] = reportSmtpPlain($smtp['smtp_host'] ?? '');
$smtp['smtp_user'] = reportSmtpPlain($smtp['smtp_user'] ?? '');
$smtp['smtp_pass'] = reportSmtpPlain($smtp['smtp_pass'] ?? '');
$smtp['smtp_secure'] = strtolower(reportSmtpPlain($smtp['smtp_secure'] ?? 'tls'));
$smtp['smtp_from_name'] = reportSmtpPlain($smtp['smtp_from_name'] ?? '');
$smtp['company_name'] = reportSmtpPlain($smtp['company_name'] ?? '');

$today = $GLOBALS['REPORT_FORCE_DATE']
    ?? ($_GET['date'] ?? reportCliArg('date') ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$today)) {
    $today = date('Y-m-d');
}
$today_fmt = date('d M Y', strtotime($today));
$company   = $smtp['company_name'] ?: 'DMS';

/*
   SECTION 1: New Despatches Created Today
*/
$new_despatches = $db->query("
    SELECT d.challan_no, d.despatch_date, d.consignee_name, d.consignee_city,
           d.total_weight, d.freight_amount, d.status,
           t.transporter_name, d.vehicle_no
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    WHERE DATE(d.created_at) = '$today'
    ORDER BY d.id DESC
")->fetch_all(MYSQLI_ASSOC);

/*
   SECTION 2: Deliveries Completed Today
*/
$deliveries = $db->query("
    SELECT d.challan_no, d.despatch_date, d.consignee_name, d.consignee_city,
           d.total_weight, d.freight_amount,
           t.transporter_name, d.vehicle_no
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    WHERE d.status = 'Delivered' AND DATE(d.updated_at) = '$today'
    ORDER BY d.id DESC
")->fetch_all(MYSQLI_ASSOC);

/*
   SECTION 3: Pending / In-Transit Summary
*/
$pending = $db->query("
    SELECT d.status, COUNT(*) AS cnt, SUM(d.total_weight) AS total_wt, SUM(d.freight_amount) AS total_frt
    FROM despatch_orders d
    WHERE d.status IN ('Despatched','In Transit')
    GROUP BY d.status
    ORDER BY FIELD(d.status,'Despatched','In Transit')
")->fetch_all(MYSQLI_ASSOC);

/*
   SECTION 4: Fleet Trips / Fuel / Invoices / Payments
*/
$fleet_trips_today = $db->query("
    SELECT t.trip_no, t.from_location, t.to_location, t.total_weight, t.freight_amount, t.status,
           v.reg_no, d.full_name AS driver
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id = v.id
    LEFT JOIN fleet_drivers d ON t.driver_id = d.id
    WHERE t.trip_date = '$today'
    ORDER BY t.id DESC
")->fetch_all(MYSQLI_ASSOC);

$fuel_today = $db->query("
    SELECT fl.litres, fl.amount, fl.driver_advance, fc.company_name
    FROM fleet_fuel_log fl
    LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id = fc.id
    WHERE fl.fuel_date = '$today'
")->fetch_all(MYSQLI_ASSOC);

$invoices_today = $db->query("
    SELECT invoice_number, consignee_name, total_amount, status
    FROM sales_invoices
    WHERE invoice_date = '$today'
    ORDER BY id DESC
")->fetch_all(MYSQLI_ASSOC);

/*
   SECTION 5: Financial Summary (Today)
*/
$freight_today = $db->query("
    SELECT SUM(freight_amount) AS total_freight,
           SUM(vendor_freight_amount) AS total_vendor_freight,
           SUM(total_amount) AS total_despatch_value,
           SUM(total_weight) AS total_weight,
           COUNT(*) AS order_count
    FROM despatch_orders
    WHERE DATE(created_at) = '$today'
")->fetch_assoc();

$payments_today = $db->query("
    SELECT SUM(amount) AS total_paid, COUNT(*) AS payment_count
    FROM transporter_payments
    WHERE DATE(payment_date) = '$today' AND status = 'Paid'
")->fetch_assoc();

/* 
   BUILD HTML EMAIL
    */
$green = '#1a5632';
$green_light = '#27ae60';

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#333;background:#f5f5f5;margin:0;padding:0">
<div style="max-width:700px;margin:20px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08)">';

/*  Header  */
$html .= '<div style="background:linear-gradient(135deg,'.$green.','.$green_light.');color:#fff;padding:22px 28px">
    <h2 style="margin:0;font-size:20px">Daily Activity Report</h2>
    <p style="margin:5px 0 0;opacity:0.85;font-size:13px">'.$company.'  '.$today_fmt.'</p>
</div>';

$html .= '<div style="padding:20px 28px">';

$html .= '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px">';
$summary_cards = [
    ['label' => 'New Challans', 'value' => count($new_despatches), 'bg' => '#eaf7ef', 'fg' => '#198754'],
    ['label' => 'Delivered', 'value' => count($deliveries), 'bg' => '#eaf2ff', 'fg' => '#0d6efd'],
    ['label' => 'Fleet Trips', 'value' => count($fleet_trips_today), 'bg' => '#fff7e6', 'fg' => '#d48a00'],
    ['label' => 'Invoices', 'value' => count($invoices_today), 'bg' => '#eef8fb', 'fg' => '#0f8ca8'],
];
foreach ($summary_cards as $card) {
    $html .= '<div style="flex:1 1 140px;min-width:140px;border:1px solid #e8ecef;border-radius:12px;padding:12px 14px;background:#fff">
        <div style="font-size:12px;color:#72808a;margin-bottom:6px">'.$card['label'].'</div>
        <div style="display:inline-block;padding:2px 10px;border-radius:999px;background:'.$card['bg'].';color:'.$card['fg'].';font-size:24px;font-weight:700">'.$card['value'].'</div>
    </div>';
}
$html .= '</div>';

/*  Section 1: New Despatches  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px;margin-top:0">
    New Despatches Created Today ('.count($new_despatches).')</h3>';

if (empty($new_despatches)) {
    $html .= '<p style="color:#888;font-style:italic">No new despatches created today.</p>';
} else {
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
        <tr style="background:'.$green.';color:#fff">
            <th style="padding:6px 8px;text-align:left">Challan No</th>
            <th style="padding:6px 8px">Consignee</th>
            <th style="padding:6px 8px">Transporter</th>
            <th style="padding:6px 8px">Vehicle</th>
            <th style="padding:6px 8px;text-align:right">Weight</th>
            <th style="padding:6px 8px;text-align:right">Freight</th>
            <th style="padding:6px 8px">Status</th>
        </tr>';
    foreach ($new_despatches as $i => $r) {
        $bg = $i % 2 ? '#f9f9f9' : '#fff';
        $html .= '<tr style="background:'.$bg.'">
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><strong>'.$r['challan_no'].'</strong></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['consignee_name'].' <small style="color:#888">'.$r['consignee_city'].'</small></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['transporter_name'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['vehicle_no'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_weight'],3).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['freight_amount'],2).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['status'].'</td>
        </tr>';
    }
    $html .= '</table>';
}

/*  Section 2: Deliveries Completed  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Deliveries Completed Today ('.count($deliveries).')</h3>';

if (empty($deliveries)) {
    $html .= '<p style="color:#888;font-style:italic">No deliveries completed today.</p>';
} else {
    $del_total_wt  = array_sum(array_column($deliveries, 'total_weight'));
    $del_total_frt = array_sum(array_column($deliveries, 'freight_amount'));
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
        <tr style="background:'.$green.';color:#fff">
            <th style="padding:6px 8px;text-align:left">Challan No</th>
            <th style="padding:6px 8px">Consignee</th>
            <th style="padding:6px 8px">Transporter</th>
            <th style="padding:6px 8px;text-align:right">Weight</th>
            <th style="padding:6px 8px;text-align:right">Freight</th>
        </tr>';
    foreach ($deliveries as $i => $r) {
        $bg = $i % 2 ? '#f9f9f9' : '#fff';
        $html .= '<tr style="background:'.$bg.'">
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><strong>'.$r['challan_no'].'</strong></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['consignee_name'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['transporter_name'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_weight'],3).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['freight_amount'],2).'</td>
        </tr>';
    }
    $html .= '<tr style="background:#e5f5eb;font-weight:bold">
        <td colspan="3" style="padding:6px 8px;text-align:right">Total Delivered:</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($del_total_wt,3).' MT</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($del_total_frt,2).'</td>
    </tr></table>';
}

/*  Section 3: Fleet Trips  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Fleet Trips Today ('.count($fleet_trips_today).')</h3>';

if (empty($fleet_trips_today)) {
    $html .= '<p style="color:#888;font-style:italic">No fleet trips recorded today.</p>';
} else {
    $trip_status_colors = ['Completed'=>'#198754','In Transit'=>'#ffc107','Planned'=>'#0dcaf0','Cancelled'=>'#dc3545'];
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
        <tr style="background:'.$green.';color:#fff">
            <th style="padding:6px 8px;text-align:left">Trip No</th>
            <th style="padding:6px 8px">Vehicle</th>
            <th style="padding:6px 8px">Driver</th>
            <th style="padding:6px 8px">Route</th>
            <th style="padding:6px 8px;text-align:right">Weight</th>
            <th style="padding:6px 8px;text-align:right">Freight</th>
            <th style="padding:6px 8px">Status</th>
        </tr>';
    foreach ($fleet_trips_today as $i => $r) {
        $bg = $i % 2 ? '#f9f9f9' : '#fff';
        $stc = $trip_status_colors[$r['status']] ?? '#6c757d';
        $html .= '<tr style="background:'.$bg.'">
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><strong>'.$r['trip_no'].'</strong></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['reg_no'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['driver'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['from_location'].' &rarr; '.$r['to_location'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_weight'],3).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['freight_amount'],2).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><span style="background:'.$stc.';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px">'.$r['status'].'</span></td>
        </tr>';
    }
    $html .= '</table>';
}

/*  Section 4: Sales Invoices  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Sales Invoices Today ('.count($invoices_today).')</h3>';

if (empty($invoices_today)) {
    $html .= '<p style="color:#888;font-style:italic">No sales invoices raised today.</p>';
} else {
    $invoice_status_colors = ['Paid'=>'#198754','Draft'=>'#6c757d','Sent'=>'#0dcaf0'];
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
        <tr style="background:'.$green.';color:#fff">
            <th style="padding:6px 8px;text-align:left">Invoice No</th>
            <th style="padding:6px 8px">Consignee</th>
            <th style="padding:6px 8px;text-align:right">Amount</th>
            <th style="padding:6px 8px">Status</th>
        </tr>';
    foreach ($invoices_today as $i => $r) {
        $bg = $i % 2 ? '#f9f9f9' : '#fff';
        $stc = $invoice_status_colors[$r['status']] ?? '#ffc107';
        $html .= '<tr style="background:'.$bg.'">
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><strong>'.$r['invoice_number'].'</strong></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['consignee_name'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_amount'],2).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><span style="background:'.$stc.';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px">'.$r['status'].'</span></td>
        </tr>';
    }
    $html .= '</table>';
}

/*  Section 5: Fuel Entries  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Fuel Entries Today ('.count($fuel_today).')</h3>';

if (empty($fuel_today)) {
    $html .= '<p style="color:#888;font-style:italic">No fuel entries recorded today.</p>';
} else {
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">
        <tr><td style="padding:8px;border-bottom:1px solid #eee;width:60%">Total Litres</td>
            <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)array_sum(array_column($fuel_today, 'litres')),2).' L</td></tr>';
    $html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Fuel Cost</td>
            <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)array_sum(array_column($fuel_today, 'amount')),2).'</td></tr>';
    $html .= '<tr style="background:#fff7e6"><td style="padding:8px;font-weight:bold">Driver Advance</td>
            <td style="padding:8px;text-align:right;font-weight:bold">'.number_format((float)array_sum(array_column($fuel_today, 'driver_advance')),2).'</td></tr>';
    $html .= '</table>';
}

/*  Section 6: Pending / In-Transit  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Pending &amp; In-Transit Summary</h3>';

if (empty($pending)) {
    $html .= '<p style="color:#888;font-style:italic">No pending or in-transit orders.</p>';
} else {
    $status_colors = ['Draft'=>'#6c757d','Despatched'=>'#0d6efd','In Transit'=>'#ffc107'];
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">
        <tr style="background:#f5f5f5">
            <th style="padding:8px;text-align:left;border-bottom:2px solid #ddd">Status</th>
            <th style="padding:8px;text-align:center;border-bottom:2px solid #ddd">Orders</th>
            <th style="padding:8px;text-align:right;border-bottom:2px solid #ddd">Total Weight</th>
            <th style="padding:8px;text-align:right;border-bottom:2px solid #ddd">Freight</th>
        </tr>';
    $grand_cnt = 0; $grand_wt = 0; $grand_frt = 0;
    foreach ($pending as $r) {
        $sc = $status_colors[$r['status']] ?? '#333';
        $grand_cnt += $r['cnt']; $grand_wt += $r['total_wt']; $grand_frt += $r['total_frt'];
        $html .= '<tr>
            <td style="padding:6px 8px;border-bottom:1px solid #eee"><span style="background:'.$sc.';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px">'.$r['status'].'</span></td>
            <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;font-weight:bold">'.$r['cnt'].'</td>
            <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_wt'],3).' MT</td>
            <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_frt'],2).'</td>
        </tr>';
    }
    $html .= '<tr style="background:#e5f5eb;font-weight:bold">
        <td style="padding:6px 8px">Total</td>
        <td style="padding:6px 8px;text-align:center">'.$grand_cnt.'</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($grand_wt,3).' MT</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($grand_frt,2).'</td>
    </tr></table>';
}

/*  Section 7: Financial Summary  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Financial Summary (Today)</h3>';

$html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;width:60%">New Orders Today</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.(int)($freight_today['order_count']??0).'</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Total Despatch Weight</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_weight']??0),3).' MT</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Total Despatch Value</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_despatch_value']??0),2).'</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Transporter Freight</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_freight']??0),2).'</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Vendor Freight Charged</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_vendor_freight']??0),2).'</td></tr>';
$html .= '<tr style="background:#e5f5eb"><td style="padding:8px;font-weight:bold">Payments Made Today</td>
    <td style="padding:8px;text-align:right;font-weight:bold">'.number_format((float)($payments_today['total_paid']??0),2).' ('.(int)($payments_today['payment_count']??0).' payments)</td></tr>';
$html .= '</table>';

/*  Footer  */
$html .= '</div>'; // close padding div
$html .= '<div style="background:#f5f5f5;padding:14px 28px;text-align:center;font-size:11px;color:#888;border-top:1px solid #eee">
    This is an automated daily report from '.$company.' Despatch Management System.<br>
    Generated on '.date('d/m/Y').' at '.date('h:i A').'
</div>';
$html .= '</div></body></html>';

/* 
   SEND EMAIL VIA SMTP
    */

// Use PHPMailer if available, else fall back to raw SMTP
$phpmailer_path = $base_path . '/includes/phpmailer/src/PHPMailer.php';
$phpmailer_smtp = $base_path . '/includes/phpmailer/src/SMTP.php';
$phpmailer_exc  = $base_path . '/includes/phpmailer/src/Exception.php';

// Also check alternate paths
if (!file_exists($phpmailer_path)) {
    $phpmailer_path = $base_path . '/includes/phpmailer/src/PHPMailer.php';
    $phpmailer_smtp = $base_path . '/includes/phpmailer/src/SMTP.php';
    $phpmailer_exc  = $base_path . '/includes/phpmailer/src/Exception.php';
}
if (!file_exists($phpmailer_path)) {
    $phpmailer_path = $base_path . '/includes/PHPMailer/PHPMailer.php';
    $phpmailer_smtp = $base_path . '/includes/PHPMailer/SMTP.php';
    $phpmailer_exc  = $base_path . '/includes/PHPMailer/Exception.php';
}

$subject = "Daily Activity Report  $company  $today_fmt";

if (file_exists($phpmailer_path)) {
    /*  PHPMailer  */
    require_once $phpmailer_exc;
    require_once $phpmailer_path;
    require_once $phpmailer_smtp;

    $target_port = (int)($smtp['smtp_port'] ?: ($smtp['smtp_secure'] === 'ssl' ? 465 : 587));
    $socket_check = reportSocketCheck($smtp['smtp_host'], $target_port);
    $mail = null;
    $used_mail_transport_fallback = !$socket_check['ok'];
    $primary_error = '';
    $fallback_error = '';

    try {
        $mail = buildDailyReportMailer($smtp, $company, $subject, $html, $REPORT_RECIPIENTS, $socket_check['ok']);
        $mail->send();
        echo date('Y-m-d H:i:s') . " OK: Daily report sent" . ($used_mail_transport_fallback ? " via mail transport" : " via SMTP") . " to " . implode(', ', $REPORT_RECIPIENTS) . "\n";
    } catch (Throwable $e) {
        $primary_error = trim(($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage());

        if ($socket_check['ok']) {
            try {
                $mail = buildDailyReportMailer($smtp, $company, $subject, $html, $REPORT_RECIPIENTS, false);
                $mail->send();
                $used_mail_transport_fallback = true;
                echo date('Y-m-d H:i:s') . " OK: Daily report sent via mail transport after SMTP fallback to " . implode(', ', $REPORT_RECIPIENTS) . " | SMTP issue was: " . $primary_error . "\n";
                return;
            } catch (Throwable $fallback_exception) {
                $fallback_error = trim(($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $fallback_exception->getMessage());
            }
        }

        $extra = !$socket_check['ok'] ? " | SMTP unreachable: " . $socket_check['msg'] : '';
        if ($fallback_error !== '') {
            $extra .= " | Mail transport error: " . $fallback_error;
        }
        echo date('Y-m-d H:i:s') . " ERROR: " . $primary_error . $extra . "\n";
        reportStop(1);
    }
} else {
    /*  Fallback: PHP mail() with headers  */
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . ($smtp['smtp_from_name'] ?: $company) . " <" . $smtp['smtp_user'] . ">\r\n";

    $to = implode(',', $REPORT_RECIPIENTS);
    if (mail($to, $subject, $html, $headers)) {
        echo date('Y-m-d H:i:s') . " OK: Daily report sent via mail() to $to\n";
    } else {
        echo date('Y-m-d H:i:s') . " ERROR: mail() failed\n";
        reportStop(1);
    }
}
