<?php
/**
 * Quotation Module — Helper Functions
 * Include this at top of each quotation page.
 */

/**
 * Generate next quotation number: TSG/YYYY/NNN
 */
function generateQuotationNo($db) {
    $year = (int)date('Y');
    $prefix = 'TSG';

    $stmt = $db->prepare("INSERT INTO quotation_sequence (prefix, year, last_seq)
                          VALUES (?, ?, 1)
                          ON DUPLICATE KEY UPDATE last_seq = last_seq + 1");
    $stmt->bind_param("si", $prefix, $year);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("SELECT last_seq FROM quotation_sequence WHERE prefix=? AND year=?");
    $stmt->bind_param("si", $prefix, $year);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $seq = $result['last_seq'];
    return $prefix . '/' . $year . '/' . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

/**
 * Default particulars rows for a new quotation
 */
function defaultParticulars() {
    return [
        ['particular' => 'Product',                        'detail' => 'Ashcrete Fly Ash'],
        ['particular' => 'Quality Standard',               'detail' => 'As per IS 3812 (Part-1)'],
        ['particular' => 'Packing / Transportation',       'detail' => 'Bulkers (Without Compressor)'],
        ['particular' => 'Minimum Order Quantity (MOQ)',   'detail' => '500 MT'],
        ['particular' => 'Rate',                           'detail' => '₹ 1,950.00 per Metric Ton (MT) + 5% IGST'],
        ['particular' => 'Delivery Basis',                 'detail' => 'F.O.R. Aero city / IGI Airport, Delhi'],
        ['particular' => 'Source',                         'detail' => 'NTPC Dadri'],
    ];
}

/**
 * Default terms text
 */
function defaultTerms() {
    return [
        'payment_terms'     => 'Payment shall be made within 30 days from the date of delivery of material.',
        'delivery_schedule' => 'Material shall be dispatched immediately upon receipt of your Purchase Order (PO) or as per mutually agreed weekly delivery schedules.',
        'offer_validity'    => 'This quotation shall remain valid for a period of 30 days from the date of issue.',
        'rate_revision'     => "Our rates are subject to revision in case of:\n• Increase in diesel prices by the Government of India / Petroleum Ministry.\n• Revision in Fly Ash rates by the Thermal Power Plant.",
        'other_terms'       => "• GST will be charged extra as applicable.\n• All disputes are subject to Delhi jurisdiction only.\n• We assure you of our best quality and timely service at all times.",
    ];
}

/**
 * Get quotation by ID with items
 */
function getQuotation($db, $id) {
    $stmt = $db->prepare("SELECT * FROM quotations WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $q = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$q) return null;

    $stmt = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $q['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $q;
}

/**
 * Status badge HTML
 */
function quotationStatusBadge($status) {
    $map = [
        'draft'    => 'secondary',
        'sent'     => 'primary',
        'accepted' => 'success',
        'rejected' => 'danger',
        'expired'  => 'warning',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . ucfirst(htmlspecialchars($status)) . '</span>';
}
