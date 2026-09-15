<?php
/**
 * Monthly Activity Report — Cron Script
 *
 * Sends a detailed monthly summary email with PDF attachment covering:
 *   1. Despatch summary (vendor-wise, status breakdown)
 *   2. Sales invoices summary
 *   3. Fleet summary (trips, fuel, expenses)
 *
 * SETUP:
 * 1. Upload to: /despatch_mgmt/cron/monthly_report.php
 * 2. Add cron job in cPanel (runs on last day of month at 11:55 PM):
 *    55 23 28-31 * * [ "$(date +\%d)" = "$(cal | awk 'NF{print $NF}' | tail -1)" ] && /usr/local/bin/php /home/tsgimpex/public_html/despatch_mgmt/cron/monthly_report.php >> /home/tsgimpex/logs/monthly_report.log 2>&1
 *
 * OR simpler (runs on the last day of every month):
 *    55 23 * * * [ $(date -d tomorrow +\%d) -eq 1 ] && /usr/local/bin/php /home/tsgimpex/public_html/despatch_mgmt/cron/monthly_report.php >> /home/tsgimpex/logs/monthly_report.log 2>&1
 */

/* ── CONFIGURATION ─────────────────────────────────────── */
$REPORT_RECIPIENTS = [
    'support@tsgimpex.com',
    'tsgaccounts@tsgimpex.com',
];

/* ── BOOTSTRAP ─────────────────────────────────────────── */
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
            throw new RuntimeException('Monthly report failed.');
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

function buildMonthlyReportMailer(
    array $smtp,
    string $company,
    string $subject,
    string $html,
    string $month_label,
    array $recipients,
    string $html_path,
    bool $use_smtp
): PHPMailer\PHPMailer\PHPMailer {
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
    $mail->AltBody = "Monthly Business Report - $company - $month_label\n\nPlease view this email in an HTML-capable email client.";

    foreach ($recipients as $email) {
        $mail->addAddress(trim($email));
    }

    if (file_exists($html_path)) {
        $mail->addAttachment($html_path, 'Monthly_Report_' . date('Y_m') . '.html');
    }

    return $mail;
}

/* ── DATE RANGE: current calendar month ─────────────────── */
$report_month = $GLOBALS['REPORT_FORCE_MONTH']
    ?? ($_GET['month'] ?? reportCliArg('month') ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', (string)$report_month)) {
    $report_month = date('Y-m');
}
$month_start = $report_month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));
$month_label = date('F Y', strtotime($month_start));
$month_short = date('M Y', strtotime($month_start));
$report_date = date('d M Y');

/* ── SMTP ────────────────────────────────────────────────── */
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
    echo date('Y-m-d H:i:s') . " ERROR: SMTP not configured\n";
    reportStop(1);
}

$smtp['smtp_host'] = reportSmtpPlain($smtp['smtp_host'] ?? '');
$smtp['smtp_user'] = reportSmtpPlain($smtp['smtp_user'] ?? '');
$smtp['smtp_pass'] = reportSmtpPlain($smtp['smtp_pass'] ?? '');
$smtp['smtp_secure'] = strtolower(reportSmtpPlain($smtp['smtp_secure'] ?? 'tls'));
$smtp['smtp_from_name'] = reportSmtpPlain($smtp['smtp_from_name'] ?? '');
$smtp['company_name'] = reportSmtpPlain($smtp['company_name'] ?? '');


$company = $smtp['company_name'] ?: 'TSG Impex';

echo date('Y-m-d H:i:s') . " Starting monthly report for $month_label...\n";

/* ════════════════════════════════════════════════════════════
   DATA COLLECTION
   ════════════════════════════════════════════════════════════ */

/* ── 1. DESPATCH SUMMARY ──────────────────────────────── */
$desp_summary = $db->query("
    SELECT
        COUNT(*) AS total_challans,
        SUM(total_weight) AS total_mt,
        SUM(freight_amount) AS total_freight,
        SUM(total_amount) AS total_value,
        SUM(CASE WHEN status='Delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN status='In Transit' THEN 1 ELSE 0 END) AS in_transit,
        SUM(CASE WHEN status='Despatched' THEN 1 ELSE 0 END) AS despatched,
        SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM despatch_orders
    WHERE despatch_date BETWEEN '$month_start' AND '$month_end'
")->fetch_assoc();

// Vendor-wise despatch
$desp_vendors = $db->query("
    SELECT v.vendor_name,
        COUNT(*) AS challans,
        SUM(d.total_weight) AS total_mt,
        SUM(d.freight_amount) AS total_freight,
        SUM(d.total_amount) AS total_value
    FROM despatch_orders d
    LEFT JOIN vendors v ON d.vendor_id = v.id
    WHERE d.despatch_date BETWEEN '$month_start' AND '$month_end'
    AND d.status != 'Cancelled'
    GROUP BY d.vendor_id, v.vendor_name
    ORDER BY total_mt DESC
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

// Transporter payments this month
$trans_payments = $db->query("
    SELECT SUM(amount) AS total_paid, COUNT(*) AS payment_count
    FROM transporter_payments
    WHERE payment_date BETWEEN '$month_start' AND '$month_end'
    AND status = 'Paid'
")->fetch_assoc();

/* ── 2. SALES INVOICES ─────────────────────────────────── */
$sales_summary = $db->query("
    SELECT
        COUNT(*) AS total_invoices,
        SUM(subtotal) AS total_subtotal,
        SUM(cgst_amount + sgst_amount + igst_amount) AS total_gst,
        SUM(total_amount) AS total_value,
        SUM(CASE WHEN status='Paid' THEN total_amount ELSE 0 END) AS total_paid,
        SUM(CASE WHEN status!='Paid' THEN total_amount ELSE 0 END) AS total_outstanding
    FROM sales_invoices
    WHERE invoice_date BETWEEN '$month_start' AND '$month_end'
")->fetch_assoc();

// Outstanding invoices
$outstanding_invoices = $db->query("
    SELECT invoice_number, invoice_date, consignee_name, total_amount, due_date, status
    FROM sales_invoices
    WHERE status != 'Paid'
    AND invoice_date <= '$month_end'
    ORDER BY due_date ASC
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

/* ── 3. FLEET SUMMARY ──────────────────────────────────── */
$fleet_trips = $db->query("
    SELECT
        COUNT(*) AS total_trips,
        SUM(total_weight) AS total_mt,
        SUM(freight_amount) AS total_freight,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status='In Transit' THEN 1 ELSE 0 END) AS in_transit,
        SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM fleet_trips
    WHERE trip_date BETWEEN '$month_start' AND '$month_end'
")->fetch_assoc();

// Vehicle-wise trips
$vehicle_trips = $db->query("
    SELECT v.reg_no, v.make, v.model,
        COUNT(t.id) AS trips,
        SUM(t.total_weight) AS total_mt,
        SUM(t.freight_amount) AS total_freight
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id = v.id
    WHERE t.trip_date BETWEEN '$month_start' AND '$month_end'
    AND t.status != 'Cancelled'
    GROUP BY t.vehicle_id
    ORDER BY trips DESC
")->fetch_all(MYSQLI_ASSOC);

// Fuel summary
$fuel_summary = $db->query("
    SELECT
        COUNT(*) AS entries,
        SUM(litres) AS total_litres,
        SUM(amount) AS fuel_cost,
        SUM(COALESCE(driver_advance,0)) AS total_advance,
        SUM(amount + COALESCE(driver_advance,0)) AS total_billed
    FROM fleet_fuel_log
    WHERE fuel_date BETWEEN '$month_start' AND '$month_end'
    AND payment_mode = 'Credit'
")->fetch_assoc();

// Fuel payments made
$fuel_payments = $db->query("
    SELECT SUM(amount) AS total_paid, COUNT(*) AS payment_count
    FROM fleet_fuel_payments
    WHERE payment_date BETWEEN '$month_start' AND '$month_end'
")->fetch_assoc();

// Vehicle expenses
$vehicle_expenses = $db->query("
    SELECT expense_type, COUNT(*) AS cnt, SUM(amount) AS total
    FROM fleet_expenses
    WHERE expense_date BETWEEN '$month_start' AND '$month_end'
    GROUP BY expense_type
    ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

$total_veh_exp = array_sum(array_column($vehicle_expenses, 'total'));

// Driver salary
$salary_summary = $db->query("
    SELECT
        COUNT(*) AS drivers,
        SUM(net_payable) AS total_payable,
        SUM(paid_amount) AS total_paid,
        SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid_count
    FROM fleet_driver_salary
    WHERE salary_month = '".date('Y-m')."'
")->fetch_assoc();

/* ════════════════════════════════════════════════════════════
   BUILD HTML EMAIL
   ════════════════════════════════════════════════════════════ */
$G  = '#1a5632';
$GL = '#27ae60';

function mFmt($n, $dec=2) { return '&#8377;' . number_format((float)$n, $dec); }
function mMT($n)  { return number_format((float)$n, 3) . ' MT'; }
function mNum($n) { return number_format((float)$n); }

function mStat($label, $value, $color='#333', $sub='') {
    return '<td style="padding:10px 14px;border:1px solid #e0e0e0;text-align:center">
        <div style="font-size:11px;color:#888;margin-bottom:3px">'.htmlspecialchars($label).'</div>
        <div style="font-size:18px;font-weight:700;color:'.$color.'">'.$value.'</div>
        '.($sub ? '<div style="font-size:10px;color:#aaa">'.$sub.'</div>' : '').'
    </td>';
}

function mSection($title, $G) {
    return '<h3 style="color:'.$G.';border-bottom:2px solid '.$G.';padding-bottom:6px;margin:24px 0 12px">'.$title.'</h3>';
}

function mTable($headers, $rows, $G, $footer_row=null) {
    $html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">';
    $html .= '<tr style="background:'.$G.';color:#fff">';
    foreach ($headers as $h) {
        $align = in_array($h[0] ?? '', ['₹','0','1','2','3','4','5','6','7','8','9']) ? 'right' : 'left';
        $html .= '<th style="padding:7px 9px;text-align:'.($h['right']??false?'right':'left').'">'.htmlspecialchars($h['label']??$h).'</th>';
    }
    $html .= '</tr>';
    foreach ($rows as $i => $row) {
        $bg = $i % 2 ? '#f9f9f9' : '#fff';
        $html .= '<tr style="background:'.$bg.'">';
        foreach ($row as $cell) {
            $right = isset($cell['right']) ? $cell['right'] : false;
            $val   = isset($cell['value']) ? $cell['value'] : $cell;
            $bold  = isset($cell['bold']) ? 'font-weight:bold;' : '';
            $color = isset($cell['color']) ? 'color:'.$cell['color'].';' : '';
            $html .= '<td style="padding:5px 9px;border-bottom:1px solid #eee;text-align:'.($right?'right':'left').';'.$bold.$color.'">'.htmlspecialchars((string)$val).'</td>';
        }
        $html .= '</tr>';
    }
    if ($footer_row) {
        $html .= '<tr style="background:#e5f5eb;font-weight:bold">';
        foreach ($footer_row as $cell) {
            $right = isset($cell['right']) ? $cell['right'] : false;
            $val   = isset($cell['value']) ? $cell['value'] : $cell;
            $html .= '<td style="padding:6px 9px;text-align:'.($right?'right':'left').'">'.htmlspecialchars((string)$val).'</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table></div>';
    return $html;
}

$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
$html .= '<body style="font-family:Arial,sans-serif;font-size:13px;color:#333;background:#f0f0f0;margin:0;padding:0">';
$html .= '<div style="max-width:750px;margin:20px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.1)">';

// Header
$html .= '<div style="background:linear-gradient(135deg,'.$G.','.$GL.');color:#fff;padding:26px 32px">';
$html .= '<h2 style="margin:0;font-size:22px">&#128200; Monthly Business Report</h2>';
$html .= '<p style="margin:6px 0 0;opacity:.85;font-size:14px">'.$company.' &nbsp;|&nbsp; '.$month_label.'</p>';
$html .= '</div>';

$html .= '<div style="padding:24px 32px">';

// ── DESPATCH SECTION ──
$html .= mSection('&#128230; Despatch Operations &mdash; '.$month_short, $G);

// KPI row
$html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px"><tr>';
$html .= mStat('Total Challans', mNum($desp_summary['total_challans']), $G);
$html .= mStat('Total MT', number_format((float)$desp_summary['total_mt'],3), '#0d6efd');
$html .= mStat('Total Freight', mFmt($desp_summary['total_freight']), $GL);
$html .= mStat('Delivered', mNum($desp_summary['delivered']), '#27ae60', 'challans');
$html .= mStat('In Transit', mNum($desp_summary['in_transit']), '#f39c12', 'challans');
$html .= '</tr></table>';

// Vendor-wise table
if ($desp_vendors) {
    $html .= '<p style="font-weight:bold;color:#555;margin-bottom:6px">Vendor-wise Despatch Summary</p>';
    $rows = [];
    $tot_mt = $tot_frt = $tot_val = 0;
    foreach ($desp_vendors as $r) {
        $rows[] = [
            ['value' => $r['vendor_name'] ?: 'Unknown'],
            ['value' => $r['challans'], 'right'=>true],
            ['value' => number_format((float)$r['total_mt'],3), 'right'=>true],
            ['value' => '₹'.number_format((float)$r['total_freight']), 'right'=>true],
            ['value' => '₹'.number_format((float)$r['total_value']), 'right'=>true],
        ];
        $tot_mt += $r['total_mt']; $tot_frt += $r['total_freight']; $tot_val += $r['total_value'];
    }
    $html .= mTable(
        [['label'=>'Vendor'],['label'=>'Challans','right'=>true],['label'=>'MT','right'=>true],['label'=>'Freight','right'=>true],['label'=>'Value','right'=>true]],
        $rows, $G,
        [['value'=>'TOTAL'],['value'=>'','right'=>true],['value'=>number_format($tot_mt,3),'right'=>true],['value'=>'₹'.number_format($tot_frt),'right'=>true],['value'=>'₹'.number_format($tot_val),'right'=>true]]
    );
}

// Transporter payments
$html .= '<p style="font-size:12px;color:#555">Transporter Payments This Month: <strong>'.mFmt($trans_payments['total_paid']).'</strong> ('.(int)($trans_payments['payment_count']).' payments)</p>';

// ── SALES SECTION ──
$html .= mSection('&#128203; Sales Invoices &mdash; '.$month_short, $G);

$html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px"><tr>';
$html .= mStat('Invoices Raised', mNum($sales_summary['total_invoices']), $G);
$html .= mStat('Total Value', mFmt($sales_summary['total_value']), '#0d6efd');
$html .= mStat('GST Collected', mFmt($sales_summary['total_gst']), '#8e44ad');
$html .= mStat('Received', mFmt($sales_summary['total_paid']), '#27ae60');
$html .= mStat('Outstanding', mFmt($sales_summary['total_outstanding']), '#e74c3c');
$html .= '</tr></table>';

if ($outstanding_invoices) {
    $html .= '<p style="font-weight:bold;color:#e74c3c;margin-bottom:6px">Outstanding Invoices</p>';
    $rows = [];
    foreach ($outstanding_invoices as $r) {
        $overdue = $r['due_date'] && $r['due_date'] < date('Y-m-d') ? ' (OVERDUE)' : '';
        $rows[] = [
            ['value' => $r['invoice_number']],
            ['value' => date('d/m/Y', strtotime($r['invoice_date']))],
            ['value' => $r['consignee_name']],
            ['value' => '₹'.number_format((float)$r['total_amount']), 'right'=>true],
            ['value' => $r['due_date'] ? date('d/m/Y', strtotime($r['due_date'])).$overdue : '—'],
            ['value' => $r['status']],
        ];
    }
    $html .= mTable(
        [['label'=>'Invoice No'],['label'=>'Date'],['label'=>'Consignee'],['label'=>'Amount','right'=>true],['label'=>'Due Date'],['label'=>'Status']],
        $rows, $G
    );
}

// ── FLEET SECTION ──
$html .= mSection('&#128666; Fleet Operations &mdash; '.$month_short, $G);

$html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px"><tr>';
$html .= mStat('Total Trips', mNum($fleet_trips['total_trips']), $G);
$html .= mStat('Total MT', number_format((float)$fleet_trips['total_mt'],3), '#0d6efd');
$html .= mStat('Freight Earned', mFmt($fleet_trips['total_freight']), $GL);
$html .= mStat('Completed', mNum($fleet_trips['completed']), '#27ae60', 'trips');
$html .= mStat('In Transit', mNum($fleet_trips['in_transit']), '#f39c12', 'trips');
$html .= '</tr></table>';

// Vehicle-wise
if ($vehicle_trips) {
    $html .= '<p style="font-weight:bold;color:#555;margin-bottom:6px">Vehicle-wise Performance</p>';
    $rows = [];
    foreach ($vehicle_trips as $r) {
        $rows[] = [
            ['value' => $r['reg_no']],
            ['value' => trim($r['make'].' '.$r['model'])],
            ['value' => $r['trips'], 'right'=>true],
            ['value' => number_format((float)$r['total_mt'],3), 'right'=>true],
            ['value' => '₹'.number_format((float)$r['total_freight']), 'right'=>true],
        ];
    }
    $html .= mTable(
        [['label'=>'Reg No'],['label'=>'Vehicle'],['label'=>'Trips','right'=>true],['label'=>'MT','right'=>true],['label'=>'Freight','right'=>true]],
        $rows, $G
    );
}

// Fuel summary
$html .= '<p style="font-weight:bold;color:#555;margin:16px 0 6px">Fuel Summary</p>';
$html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px">';
$fuel_rows = [
    ['Fuel Entries (Credit)',     (int)($fuel_summary['entries']??0).' entries'],
    ['Total Litres',              number_format((float)($fuel_summary['total_litres']??0),2).' L'],
    ['Fuel Cost',                 '₹'.number_format((float)($fuel_summary['fuel_cost']??0),2)],
    ['Driver Advances',           '₹'.number_format((float)($fuel_summary['total_advance']??0),2)],
    ['Total Billed to Companies', '₹'.number_format((float)($fuel_summary['total_billed']??0),2)],
    ['Payments Made',             '₹'.number_format((float)($fuel_payments['total_paid']??0),2).' ('.(int)($fuel_payments['payment_count']??0).' payments)'],
];
foreach ($fuel_rows as $i => $r) {
    $bg = $i%2 ? '#f9f9f9' : '#fff';
    $html .= '<tr style="background:'.$bg.'"><td style="padding:5px 9px;border-bottom:1px solid #eee;width:55%">'.$r[0].'</td><td style="padding:5px 9px;border-bottom:1px solid #eee;font-weight:bold;text-align:right">'.$r[1].'</td></tr>';
}
$html .= '</table>';

// Vehicle expenses
if ($vehicle_expenses) {
    $html .= '<p style="font-weight:bold;color:#555;margin:16px 0 6px">Vehicle Expenses &mdash; Total: ₹'.number_format($total_veh_exp,2).'</p>';
    $rows = [];
    foreach ($vehicle_expenses as $r) {
        $rows[] = [
            ['value' => $r['expense_type']],
            ['value' => $r['cnt'], 'right'=>true],
            ['value' => '₹'.number_format((float)$r['total'],2), 'right'=>true],
        ];
    }
    $html .= mTable(
        [['label'=>'Expense Type'],['label'=>'Count','right'=>true],['label'=>'Total','right'=>true]],
        $rows, $G
    );
}

// Driver salary
if ((int)($salary_summary['drivers']??0) > 0) {
    $html .= '<p style="font-size:12px;color:#555">Driver Salary ('.$month_short.'): Payable <strong>₹'.number_format((float)$salary_summary['total_payable'],2).'</strong> | Paid <strong>₹'.number_format((float)$salary_summary['total_paid'],2).'</strong> ('.(int)$salary_summary['paid_count'].' of '.(int)$salary_summary['drivers'].' drivers)</p>';
}

// Footer
$html .= '</div>';
$html .= '<div style="background:#f5f5f5;padding:14px 32px;text-align:center;font-size:11px;color:#888;border-top:1px solid #eee">';
$html .= 'Automated Monthly Report &mdash; '.$company.' Despatch Management System<br>';
$html .= 'Generated on '.date('d/m/Y').' at '.date('h:i A').' | Report Period: '.$month_label;
$html .= '</div></div></body></html>';

/* ════════════════════════════════════════════════════════════
   BUILD PDF ATTACHMENT (using FPDF — same as app)
   ════════════════════════════════════════════════════════════ */

// Attach HTML report (shell_exec/FPDF not available on shared hosting)
$html_path = sys_get_temp_dir() . '/monthly_report_' . date('Y_m') . '.html';
$has_fpdf  = false;
file_put_contents($html_path, $html);

if (false) { // placeholder

    class MonthlyReportPDF extends FPDF {
        public $G = [26, 86, 50];
        public $GL = [39, 174, 96];
        public $company = '';
        public $month_label = '';

        function Header() {
            $this->SetFillColor(...$this->G);
            $this->Rect(0, 0, 210, 22, 'F');
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 13);
            $this->SetXY(10, 6);
            $this->Cell(130, 10, 'Monthly Business Report - ' . $this->month_label, 0, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->Cell(0, 10, $this->company, 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
            $this->SetY(26);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(150);
            $this->Cell(0, 10, 'Monthly Report | ' . date('d/m/Y h:i A') . ' | Page ' . $this->PageNo(), 0, 0, 'C');
        }

        function sectionTitle($title) {
            $this->Ln(4);
            $this->SetFillColor(...$this->G);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 7, $title, 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(2);
        }

        function kpiRow($items) {
            $w = 190 / count($items);
            $this->SetFont('Arial', '', 8);
            $this->SetFillColor(245, 255, 248);
            foreach ($items as $item) {
                $this->SetXY($this->GetX(), $this->GetY());
                $x = $this->GetX();
                $y = $this->GetY();
                $this->SetFillColor(240, 248, 243);
                $this->Rect($x, $y, $w-1, 14, 'F');
                $this->SetFont('Arial', '', 7);
                $this->SetTextColor(120);
                $this->SetXY($x, $y+1);
                $this->Cell($w-1, 5, $item[0], 0, 0, 'C');
                $this->SetFont('Arial', 'B', 10);
                $this->SetTextColor(26, 86, 50);
                $this->SetXY($x, $y+6);
                $this->Cell($w-1, 7, $item[1], 0, 0, 'C');
                $this->SetXY($x+$w, $y);
                $this->SetTextColor(0,0,0);
            }
            $this->Ln(16);
        }

        function tableHeader($cols) {
            $this->SetFillColor(...$this->G);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 8);
            foreach ($cols as $col) {
                $this->Cell($col[0], 6, $col[1], 0, 0, $col[2]??'L', true);
            }
            $this->Ln();
            $this->SetTextColor(0, 0, 0);
        }

        function tableRow($cols, $shade) {
            if ($shade) $this->SetFillColor(249, 249, 249);
            else $this->SetFillColor(255, 255, 255);
            $this->SetFont('Arial', '', 8);
            foreach ($cols as $col) {
                $this->Cell($col[0], 5.5, $col[1], 0, 0, $col[2]??'L', true);
            }
            $this->Ln();
        }

        function tableFooter($cols) {
            $this->SetFillColor(229, 245, 235);
            $this->SetFont('Arial', 'B', 8);
            foreach ($cols as $col) {
                $this->Cell($col[0], 6, $col[1], 0, 0, $col[2]??'L', true);
            }
            $this->Ln(3);
        }

        function kvRow($label, $value, $shade) {
            if ($shade) $this->SetFillColor(249,249,249);
            else $this->SetFillColor(255,255,255);
            $this->SetFont('Arial', '', 8);
            $this->Cell(95, 5.5, $label, 0, 0, 'L', true);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(95, 5.5, $value, 0, 1, 'R', true);
        }
    }

    $pdf = new MonthlyReportPDF('P', 'mm', 'A4');
    $pdf->company = $company;
    $pdf->month_label = $month_label;
    $pdf->SetMargins(10, 28, 10);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    /* DESPATCH */
    $pdf->sectionTitle('DESPATCH OPERATIONS');
    $pdf->kpiRow([
        ['Challans', (string)(int)$desp_summary['total_challans']],
        ['Total MT', number_format((float)$desp_summary['total_mt'],3)],
        ['Freight', 'Rs.'.number_format((float)$desp_summary['total_freight'])],
        ['Delivered', (string)(int)$desp_summary['delivered']],
        ['In Transit', (string)(int)$desp_summary['in_transit']],
    ]);

    if ($desp_vendors) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(80);
        $pdf->Cell(0, 5, 'Vendor-wise Despatch Summary', 0, 1);
        $pdf->SetTextColor(0);
        $pdf->tableHeader([[60,'Vendor'],[20,'Challans','R'],[35,'MT','R'],[37,'Freight','R'],[38,'Value','R']]);
        $tot_mt=$tot_frt=$tot_val=0;
        foreach ($desp_vendors as $i => $r) {
            $pdf->tableRow([
                [60, substr($r['vendor_name']??'Unknown',0,35)],
                [20, (string)(int)$r['challans'], 'R'],
                [35, number_format((float)$r['total_mt'],3), 'R'],
                [37, 'Rs.'.number_format((float)$r['total_freight']), 'R'],
                [38, 'Rs.'.number_format((float)$r['total_value']), 'R'],
            ], $i%2);
            $tot_mt+=$r['total_mt']; $tot_frt+=$r['total_freight']; $tot_val+=$r['total_value'];
        }
        $pdf->tableFooter([[60,'TOTAL'],[20,'','R'],[35,number_format($tot_mt,3),'R'],[37,'Rs.'.number_format($tot_frt),'R'],[38,'Rs.'.number_format($tot_val),'R']]);
    }

    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 5, 'Transporter Payments This Month: Rs.'.number_format((float)$trans_payments['total_paid'],2).' ('.(int)($trans_payments['payment_count']).' payments)', 0, 1);
    $pdf->Ln(2);

    /* SALES */
    $pdf->sectionTitle('SALES INVOICES');
    $pdf->kpiRow([
        ['Invoices', (string)(int)$sales_summary['total_invoices']],
        ['Total Value', 'Rs.'.number_format((float)$sales_summary['total_value'])],
        ['GST', 'Rs.'.number_format((float)$sales_summary['total_gst'])],
        ['Received', 'Rs.'.number_format((float)$sales_summary['total_paid'])],
        ['Outstanding', 'Rs.'.number_format((float)$sales_summary['total_outstanding'])],
    ]);

    if ($outstanding_invoices) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->Cell(0, 5, 'Outstanding Invoices', 0, 1);
        $pdf->SetTextColor(0);
        $pdf->tableHeader([[30,'Invoice No'],[20,'Date'],[65,'Consignee'],[30,'Amount','R'],[25,'Due Date'],[20,'Status']]);
        foreach ($outstanding_invoices as $i => $r) {
            $overdue = $r['due_date'] && $r['due_date'] < date('Y-m-d') ? '*' : '';
            $pdf->tableRow([
                [30, $r['invoice_number']],
                [20, date('d/m/Y', strtotime($r['invoice_date']))],
                [65, substr($r['consignee_name'],0,38)],
                [30, 'Rs.'.number_format((float)$r['total_amount']), 'R'],
                [25, $r['due_date'] ? date('d/m/Y', strtotime($r['due_date'])).$overdue : '-'],
                [20, $r['status']],
            ], $i%2);
        }
        $pdf->Ln(2);
    }

    /* FLEET */
    $pdf->sectionTitle('FLEET OPERATIONS');
    $pdf->kpiRow([
        ['Trips', (string)(int)$fleet_trips['total_trips']],
        ['Total MT', number_format((float)$fleet_trips['total_mt'],3)],
        ['Freight', 'Rs.'.number_format((float)$fleet_trips['total_freight'])],
        ['Completed', (string)(int)$fleet_trips['completed']],
        ['In Transit', (string)(int)$fleet_trips['in_transit']],
    ]);

    if ($vehicle_trips) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(80);
        $pdf->Cell(0, 5, 'Vehicle-wise Performance', 0, 1);
        $pdf->SetTextColor(0);
        $pdf->tableHeader([[30,'Reg No'],[50,'Vehicle'],[30,'Trips','R'],[45,'MT','R'],[35,'Freight','R']]);
        foreach ($vehicle_trips as $i => $r) {
            $pdf->tableRow([
                [30, $r['reg_no']],
                [50, substr(trim($r['make'].' '.$r['model']),0,28)],
                [30, (string)(int)$r['trips'], 'R'],
                [45, number_format((float)$r['total_mt'],3), 'R'],
                [35, 'Rs.'.number_format((float)$r['total_freight']), 'R'],
            ], $i%2);
        }
        $pdf->Ln(2);
    }

    // Fuel
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(80);
    $pdf->Cell(0, 5, 'Fuel Summary', 0, 1);
    $pdf->SetTextColor(0);
    $fuel_kv = [
        ['Fuel Entries (Credit)', (int)($fuel_summary['entries']??0).' entries'],
        ['Total Litres', number_format((float)($fuel_summary['total_litres']??0),2).' L'],
        ['Fuel Cost', 'Rs.'.number_format((float)($fuel_summary['fuel_cost']??0),2)],
        ['Driver Advances', 'Rs.'.number_format((float)($fuel_summary['total_advance']??0),2)],
        ['Total Billed to Companies', 'Rs.'.number_format((float)($fuel_summary['total_billed']??0),2)],
        ['Payments Made', 'Rs.'.number_format((float)($fuel_payments['total_paid']??0),2).' ('.(int)($fuel_payments['payment_count']??0).' payments)'],
    ];
    foreach ($fuel_kv as $i => $r) $pdf->kvRow($r[0], $r[1], $i%2);
    $pdf->Ln(3);

    // Expenses
    if ($vehicle_expenses) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(80);
        $pdf->Cell(0, 5, 'Vehicle Expenses — Total: Rs.'.number_format($total_veh_exp,2), 0, 1);
        $pdf->SetTextColor(0);
        $pdf->tableHeader([[100,'Expense Type'],[45,'Count','R'],[45,'Total','R']]);
        foreach ($vehicle_expenses as $i => $r) {
            $pdf->tableRow([
                [100, $r['expense_type']],
                [45, (string)(int)$r['cnt'], 'R'],
                [45, 'Rs.'.number_format((float)$r['total'],2), 'R'],
            ], $i%2);
        }
    }

    // Driver salary
    if ((int)($salary_summary['drivers']??0) > 0) {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 5, 'Driver Salary: Payable Rs.'.number_format((float)$salary_summary['total_payable'],2).' | Paid Rs.'.number_format((float)$salary_summary['total_paid'],2).' ('.(int)$salary_summary['paid_count'].'/'.(int)$salary_summary['drivers'].' drivers)', 0, 1);
    }

} // end placeholder

if (!$has_fpdf) {
    echo date('Y-m-d H:i:s') . " INFO: Attaching HTML report (PDF not available)\n";
}

/* ════════════════════════════════════════════════════════════
   SEND EMAIL
   ════════════════════════════════════════════════════════════ */
$subject = "Monthly Report — $company — $month_label";

$phpmailer_path = $base_path . '/includes/phpmailer/src/PHPMailer.php';
$phpmailer_smtp = $base_path . '/includes/phpmailer/src/SMTP.php';
$phpmailer_exc  = $base_path . '/includes/phpmailer/src/Exception.php';

if (!file_exists($phpmailer_path)) {
    $phpmailer_path = $base_path . '/includes/PHPMailer/PHPMailer.php';
    $phpmailer_smtp = $base_path . '/includes/PHPMailer/SMTP.php';
    $phpmailer_exc  = $base_path . '/includes/PHPMailer/Exception.php';
}

if (file_exists($phpmailer_path)) {
    require_once $phpmailer_exc;
    require_once $phpmailer_path;
    require_once $phpmailer_smtp;

    $target_port = (int)($smtp['smtp_port'] ?: ($smtp['smtp_secure'] === 'ssl' ? 465 : 587));
    $socket_check = reportSocketCheck($smtp['smtp_host'], $target_port);
    $mail = null;
    $used_mail_transport_fallback = !$socket_check['ok'];
    $primary_error = '';
    $fallback_error = '';

    if (file_exists($html_path)) {
        echo date('Y-m-d H:i:s') . " HTML report attached\n";
    }

    try {
        $mail = buildMonthlyReportMailer($smtp, $company, $subject, $html, $month_label, $REPORT_RECIPIENTS, $html_path, $socket_check['ok']);
        $mail->send();
        echo date('Y-m-d H:i:s') . " OK: Monthly report sent" . ($used_mail_transport_fallback ? " via mail transport" : " via SMTP") . " to " . implode(', ', $REPORT_RECIPIENTS) . "\n";

        if (file_exists($html_path)) unlink($html_path);

    } catch (Throwable $e) {
        $primary_error = trim(($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage());

        if ($socket_check['ok']) {
            try {
                $mail = buildMonthlyReportMailer($smtp, $company, $subject, $html, $month_label, $REPORT_RECIPIENTS, $html_path, false);
                $mail->send();
                $used_mail_transport_fallback = true;
                echo date('Y-m-d H:i:s') . " OK: Monthly report sent via mail transport after SMTP fallback to " . implode(', ', $REPORT_RECIPIENTS) . " | SMTP issue was: " . $primary_error . "\n";
                if (file_exists($html_path)) unlink($html_path);
                return;
            } catch (Throwable $fallback_exception) {
                $fallback_error = trim(($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $fallback_exception->getMessage());
            }
        }

        if (file_exists($html_path)) unlink($html_path);

        $extra = !$socket_check['ok'] ? " | SMTP unreachable: " . $socket_check['msg'] : '';
        if ($fallback_error !== '') {
            $extra .= " | Mail transport error: " . $fallback_error;
        }
        echo date('Y-m-d H:i:s') . " ERROR: " . $primary_error . $extra . "\n";
        reportStop(1);
    }
} else {
    // Fallback: mail()
    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$smtp['smtp_from_name']} <{$smtp['smtp_user']}>\r\n";
    $to = implode(',', $REPORT_RECIPIENTS);
    if (mail($to, $subject, $html, $headers)) {
        echo date('Y-m-d H:i:s') . " OK: Monthly report sent via mail() to $to\n";
    } else {
        echo date('Y-m-d H:i:s') . " ERROR: mail() failed\n";
        reportStop(1);
    }
}
