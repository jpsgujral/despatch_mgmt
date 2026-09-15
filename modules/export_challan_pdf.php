<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requirePerm('despatch', 'view');

$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
$dl  = isset($_GET['download']);   // ?download to force download, else inline view

if (!$id) { http_response_code(400); die('Invalid request'); }

/* ── Fetch despatch data (same query as send_despatch_email.php) ── */
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

if (!$d) { http_response_code(404); die('Despatch order not found'); }

/* Fallback: if created_by=0, use current session user */
if (empty($d['prepared_by_name']) && !empty($_SESSION['user_id'])) {
    $uid_row = $db->query("SELECT full_name FROM app_users WHERE id=".(int)$_SESSION['user_id'])->fetch_assoc();
    if ($uid_row) $d['prepared_by_name'] = $uid_row['full_name'];
}

/* ── Fetch line items ── */
$items = $db->query("
    SELECT di.*, i.item_name, i.item_code, i.hsn_code, i.uom AS i_uom
    FROM despatch_items di
    JOIN items i ON di.item_id = i.id
    WHERE di.despatch_id = $id
    ORDER BY di.id ASC
")->fetch_all(MYSQLI_ASSOC);

/* ── Generate PDF ── */
require_once __DIR__ . '/../includes/pdf_gen.php';

$gen     = new DespatchPDF();
$pdf_raw = $gen->build($d, $items);

if (!$pdf_raw || strlen($pdf_raw) < 200) {
    http_response_code(500);
    die('PDF generation failed.');
}

$filename = 'Challan_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', ($d['challan_no'] ?: $id)) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf_raw));
if ($dl) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
}
header('Cache-Control: private, max-age=0, must-revalidate');

echo $pdf_raw;
exit;
