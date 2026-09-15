<?php
/**
 * fleet_fuel_payment_advice.php
 * Generates and streams a Payment Advice PDF for a fuel payment.
 *
 * Usage: fleet_fuel_payment_advice.php?id=123
 *
 * Place this file alongside fleet_fuel_payments.php (fleet/ folder).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf_gen.php';

// Auth check
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied.');
}

$db  = getDB();
$pid = (int)($_GET['id'] ?? 0);
if (!$pid) {
    http_response_code(400);
    exit('Payment ID required.');
}

/* ── Load payment ── */
$r = $db->query("SELECT fp.*, fc.company_name, fc.contact_person,
                        fc.phone AS fc_phone, fc.email AS fc_email, fc.gstin AS fc_gstin
                 FROM fleet_fuel_payments fp
                 JOIN fleet_fuel_companies fc ON fc.id = fp.fuel_company_id
                 WHERE fp.id = $pid
                 LIMIT 1");
if (!$r || !($pay = $r->fetch_assoc())) {
    http_response_code(404);
    exit('Payment not found.');
}

/* ── Fuel company row (separate array for clarity) ── */
$fc = [
    'company_name'   => $pay['company_name'],
    'contact_person' => $pay['contact_person'] ?? '',
    'phone'          => $pay['fc_phone']        ?? '',
    'email'          => $pay['fc_email']         ?? '',
    'gstin'          => $pay['fc_gstin']         ?? '',
];

/* ── Paying company selected on payment, fallback for old records ── */
$paying_company_id = (int)($pay['company_id'] ?? 0);
if ($paying_company_id > 0) {
    $co = $db->query("SELECT * FROM companies WHERE id=$paying_company_id LIMIT 1")->fetch_assoc() ?? [];
}
if (empty($co)) {
    $co = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc() ?? [];
}

/* ── Logged-in user (name + signature) ──
   Tries common column names gracefully.   */
$uid = (int)($_SESSION['user_id'] ?? 0);
$ur  = $uid ? $db->query("SELECT full_name, username, signature_path FROM app_users WHERE id = $uid LIMIT 1") : false;
$u   = ($ur && $ur->num_rows) ? $ur->fetch_assoc() : [];
// Ensure full_name falls back to username
if (empty($u['full_name'])) $u['full_name'] = $u['username'] ?? '';

/* ── Debug: show signature_path raw value (append ?debug=1 to URL) ── */
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo "signature_path: [" . ($u['signature_path'] ?? 'NULL') . "]\n";
    echo "full_name: ["      . ($u['full_name'] ?? ($u['name'] ?? ($u['username'] ?? 'NULL'))) . "]\n";
    $sp = trim($u['signature_path'] ?? '');
    if ($sp) {
        $resolved = pdf_resolve_image($sp);
        echo "resolved path: [" . $resolved . "]\n";
        echo "file_exists: "    . (($resolved && file_exists($resolved)) ? 'YES' : 'NO') . "\n";
    }
    exit;
}

/* ── Generate PDF ── */
ob_start();
try {
    $pdf = new DespatchPDF();
    $out = $pdf->buildFuelPaymentAdvice($pay, $fc, $co, $u);
    ob_end_clean();
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    exit('PDF generation failed: '.htmlspecialchars($e->getMessage()));
}

/* ── Stream to browser ── */
$filename = 'PaymentAdvice_FPA-'.$pid.'_'.date('Ymd').'.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.strlen($out));
header('Cache-Control: no-store');
echo $out;
exit;
