<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requirePerm('export_excel', 'view');

$db = getDB();

$date_from = sanitize($_POST['date_from'] ?? '');
$date_to   = sanitize($_POST['date_to']   ?? '');

// ── Show form if not POSTed ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    include '../includes/header.php';
    ?>
    <script>document.getElementById('page-title').innerHTML='<i class="bi bi-file-earmark-excel me-2"></i>Export to Excel';</script>
    <div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
    <div class="card">
    <div class="card-header"><i class="bi bi-file-earmark-excel me-2"></i>Export Database to Excel</div>
    <div class="card-body">
        <p class="text-muted mb-3">Exports all data into a single Excel file with separate sheets for each module.</p>
        <form method="POST">
        <div class="mb-3">
            <label class="form-label fw-bold">Date Range <small class="text-muted fw-normal">(applies to PO, Despatch, Invoices, Payments, Fleet Trips)</small></label>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="">
                </div>
                <div class="col-6">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="">
                </div>
            </div>
            <div class="form-text">Leave blank to export all records.</div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold">Sheets to include</label>
            <div class="row g-1">
                <?php
                $sheets = [
                    'purchase_orders'          => 'Purchase Orders',
                    'despatch_orders'          => 'Despatch Orders',
                    'delivery_challans'        => 'Delivery Challans (Items)',
                    'sales_invoices'           => 'Sales Invoices',
                    'transporter_payments'     => 'Transporter Payments',
                    'agent_commissions'        => 'Agent Commissions',
                    'vendors'                  => 'Vendor Master',
                    'items'                    => 'Item Master',
                    'transporters'             => 'Transporter Master',
                    'companies'                => 'Companies',
                    'company_settings'         => 'Company Settings',
                    'aggregate_items'          => 'Aggregate Items',
                    'aggregate_sources'        => 'Aggregate Sources',
                    'aggregate_suppliers'      => 'Aggregate Suppliers',
                    'fleet_purchase_orders'    => 'Fleet: Sales Orders (Customer POs)',
                    'fleet_trips'              => 'Fleet: Trip Orders',
                    'fleet_trip_items'         => 'Fleet: Trip Items',
                    'fleet_fuel_log'           => 'Fleet: Fuel Log',
                    'fleet_expenses'           => 'Fleet: Vehicle Expenses',
                    'fleet_salary'             => 'Fleet: Driver Salary',
                    'fleet_retro_salary'       => 'Fleet: Driver Retro Salary',
                    'fleet_vehicles'           => 'Fleet: Vehicles',
                    'fleet_drivers'            => 'Fleet: Drivers',
                    'fleet_customers'          => 'Fleet: Customers Master',
                    'fleet_fuel_payments'      => 'Fleet: Fuel Payments',
                    'fleet_tyres'              => 'Fleet: Tyres',
                    'fleet_vendors'            => 'Fleet: Vendors',
                    'fleet_lease_agents'       => 'Fleet: Lease Agents',
                    'po_items'                 => 'PO Line Items',
                    'fleet_po_items'           => 'Fleet PO Line Items',
                    'trading_receipts'         => 'Trading Receipts',
                    'quotations'               => 'Quotations',
                    'sales_invoice_payments'   => 'Sales Invoice Payments',
                    'sales_vendor_receipts'    => 'Vendor Receipts',
                    'agent_commission_payments'=> 'Agent Commission Payments',
                    'transporter_rates'        => 'Transporter Rate Cards',
                    'raw_database_tables'      => 'Raw Database Tables (Auto include every table/column)',
                ];
                foreach ($sheets as $k => $label):
                ?>
                <div class="col-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sheets[]" value="<?= $k ?>" id="sh_<?= $k ?>" checked>
                        <label class="form-check-label" for="sh_<?= $k ?>"><?= $label ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-success w-100 py-2">
            <i class="bi bi-download me-2"></i>Download Excel File
        </button>
        </form>
    </div></div>
    </div></div>
    <?php
    include '../includes/footer.php';
    exit;
}

// ── Build Excel ───────────────────────────────────────────────
$selected = $_POST['sheets'] ?? array_keys([
    'purchase_orders'=>1,'despatch_orders'=>1,'delivery_challans'=>1,
    'sales_invoices'=>1,'transporter_payments'=>1,'agent_commissions'=>1,
    'vendors'=>1,'items'=>1,'transporters'=>1,
    'companies'=>1,'company_settings'=>1,
    'aggregate_items'=>1,'aggregate_sources'=>1,'aggregate_suppliers'=>1,
    'fleet_purchase_orders'=>1,'fleet_trips'=>1,'fleet_trip_items'=>1,
    'fleet_fuel_log'=>1,'fleet_expenses'=>1,
    'fleet_salary'=>1,'fleet_retro_salary'=>1,
    'fleet_vehicles'=>1,'fleet_drivers'=>1,'fleet_customers'=>1,
    'fleet_fuel_payments'=>1,'fleet_tyres'=>1,'fleet_vendors'=>1,'fleet_lease_agents'=>1,
    'po_items'=>1,'fleet_po_items'=>1,'trading_receipts'=>1,
    'quotations'=>1,'sales_invoice_payments'=>1,'sales_vendor_receipts'=>1,
    'agent_commission_payments'=>1,'transporter_rates'=>1,
    'raw_database_tables'=>1
]);

function dWhere($col, $df, $dt) {
    $w = '';
    if ($df) $w .= " AND DATE($col) >= '$df'";
    if ($dt) $w .= " AND DATE($col) <= '$dt'";
    return $w;
}
$dw = function($col) use ($date_from, $date_to) { return dWhere($col, $date_from, $date_to); };

// Safe query: returns [] instead of crashing when a column/table doesn't exist yet
function safeQuery($db, $sql) {
    $res = $db->query($sql);
    if ($res === false) return [];
    return $res->fetch_all(MYSQLI_ASSOC);
}

// ── Collect all sheet data ────────────────────────────────────
$workbook = [];

// ── Purchase Orders ──────────────────────────────────────────
if (in_array('purchase_orders', $selected)) {
    $po_sql = "SELECT p.id, p.po_number, p.po_date, c.company_name,
        v.vendor_name, v.gstin AS vendor_gstin,
        p.gst_type, p.payment_terms, p.validity_date,
        p.subtotal, p.cgst_amount, p.sgst_amount, p.igst_amount,
        p.gst_amount, p.discount, p.total_amount, p.status, p.remarks
        FROM purchase_orders p
        LEFT JOIN vendors v ON p.vendor_id=v.id
        LEFT JOIN companies c ON p.company_id=c.id
        WHERE 1=1 " . $dw('p.po_date') . "
        ORDER BY p.po_date DESC";
    $rows = safeQuery($db, $po_sql);
    $workbook['Purchase Orders'] = [
        'headers' => ['ID','PO Number','PO Date','Company','Vendor','Vendor GSTIN',
                      'GST Type','Payment Terms','Validity Date',
                      'Subtotal','CGST','SGST','IGST','GST Total','Discount','Total Amount','Status','Remarks'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['po_number'],$r['po_date'],$r['company_name'],$r['vendor_name'],$r['vendor_gstin'],
            $r['gst_type'],$r['payment_terms'],$r['validity_date'],
            $r['subtotal'],$r['cgst_amount'],$r['sgst_amount'],$r['igst_amount'],
            $r['gst_amount'],$r['discount'],$r['total_amount'],$r['status'],$r['remarks']
        ], $rows)
    ];
}

// ── Despatch Orders ──────────────────────────────────────────
if (in_array('despatch_orders', $selected)) {
    $rows = safeQuery($db, "SELECT d.id, d.challan_no, d.despatch_date, c.company_name,
        d.consignee_name, d.consignee_city, d.consignee_state, d.consignee_gstin,
        COALESCE(d.consignee_contact,'') AS consignee_contact,
        COALESCE(d.consignee_camp,'') AS consignee_camp,
        t.transporter_name, d.vehicle_no, d.lr_number,
        d.freight_paid_by, d.freight_amount,
        COALESCE(d.vendor_freight_amount,0) AS vendor_freight_amount,
        COALESCE(d.transporter_misc_charges,0) AS transporter_misc_charges,
        COALESCE(d.transporter_misc_remarks,'') AS transporter_misc_remarks,
        d.total_weight,
        d.subtotal, d.gst_amount, d.total_amount, d.status,
        po.po_number, v.vendor_name, s.source_name,
        d.agent_id, ag.full_name AS agent_name,
        COALESCE(d.mtc_required,'No') AS mtc_required,
        COALESCE(d.mtc_source,'') AS mtc_source,
        COALESCE(d.mtc_item_name,'') AS mtc_item_name,
        COALESCE(d.mtc_test_date,'') AS mtc_test_date,
        COALESCE(d.mtc_ros_45,'') AS mtc_ros_45,
        COALESCE(d.mtc_moisture,'') AS mtc_moisture,
        COALESCE(d.mtc_loi,'') AS mtc_loi,
        COALESCE(d.mtc_fineness,'') AS mtc_fineness,
        COALESCE(d.mtc_remarks,'') AS mtc_remarks,
        COALESCE(d.freight_inv_no,'') AS freight_inv_no,
        COALESCE(d.freight_inv_date,'') AS freight_inv_date,
        COALESCE(d.freight_inv_amount,0) AS freight_inv_amount,
        COALESCE(d.freight_inv_type,'') AS freight_inv_type,
        COALESCE(d.freight_inv_hardcopy,0) AS freight_inv_hardcopy
        FROM despatch_orders d
        LEFT JOIN transporters t ON d.transporter_id=t.id
        LEFT JOIN companies c ON d.company_id=c.id
        LEFT JOIN purchase_orders po ON d.po_id=po.id
        LEFT JOIN vendors v ON d.vendor_id=v.id
        LEFT JOIN source_of_material s ON d.source_of_material_id=s.id
        LEFT JOIN app_users ag ON d.agent_id=ag.id
        WHERE 1=1 " . $dw('d.despatch_date') . "
        ORDER BY d.despatch_date DESC");
    $workbook['Despatch Orders'] = [
        'headers' => ['ID','Challan No','Date','Company','Vendor','Source',
                      'Consignee','City','State','Consignee GSTIN','Consignee Contact','Consignee Camp',
                      'Transporter','Vehicle No','LR Number','Freight By','Freight Amt (Trans)','Freight Amt (Vendor)',
                      'Misc Charges','Misc Remarks',
                      'Total Weight','Subtotal','GST Amount','Total Amount','Status','PO Number','Agent',
                      'MTC Required','MTC Source','MTC Item','MTC Test Date',
                      'MTC ROS45','MTC Moisture','MTC LOI','MTC Fineness','MTC Remarks',
                      'Freight Inv No','Freight Inv Date','Freight Inv Amt','Freight Inv Type','Freight HardCopy'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['challan_no'],$r['despatch_date'],$r['company_name'],
            $r['vendor_name'],$r['source_name'],
            $r['consignee_name'],$r['consignee_city'],$r['consignee_state'],$r['consignee_gstin'],
            $r['consignee_contact']??'',$r['consignee_camp']??'',
            $r['transporter_name'],$r['vehicle_no'],$r['lr_number'],
            $r['freight_paid_by'],$r['freight_amount'],$r['vendor_freight_amount']??0,
            $r['transporter_misc_charges']??0,$r['transporter_misc_remarks']??'',
            $r['total_weight'],
            $r['subtotal'],$r['gst_amount'],$r['total_amount'],$r['status'],$r['po_number'],$r['agent_name']??'',
            $r['mtc_required']??'',$r['mtc_source']??'',$r['mtc_item_name']??'',$r['mtc_test_date']??'',
            $r['mtc_ros_45']??'',$r['mtc_moisture']??'',$r['mtc_loi']??'',$r['mtc_fineness']??'',$r['mtc_remarks']??'',
            $r['freight_inv_no']??'',$r['freight_inv_date']??'',$r['freight_inv_amount']??0,
            $r['freight_inv_type']??'',$r['freight_inv_hardcopy']??0
        ], $rows)
    ];
}

// ── Delivery Challans (Items) ────────────────────────────────
if (in_array('delivery_challans', $selected)) {
    $rows = $db->query("SELECT d.challan_no, d.despatch_date, d.consignee_name,
        i.item_name, i.item_code, i.hsn_code,
        di.description, di.qty, di.weight, di.uom, di.unit_price,
        di.gst_rate, di.gst_amount, di.total_price,
        d.total_weight, t.transporter_name, d.vehicle_no
        FROM despatch_items di
        JOIN despatch_orders d ON di.despatch_id=d.id
        JOIN items i ON di.item_id=i.id
        LEFT JOIN transporters t ON d.transporter_id=t.id
        WHERE 1=1 " . $dw('d.despatch_date') . "
        ORDER BY d.despatch_date DESC, d.id");
    $rows = ($rows && $rows !== false) ? $rows->fetch_all(MYSQLI_ASSOC) : [];
    $workbook['Delivery Challans'] = [
        'headers' => ['Challan No','Date','Consignee','Item Name','Item Code','HSN Code',
                      'Description','Desp Qty','Rcvd Weight','UOM','Unit Price','GST Rate%','GST Amount',
                      'Total Amount','Total Weight','Transporter','Vehicle No'],
        'rows'    => array_map(fn($r) => [
            $r['challan_no'],$r['despatch_date'],$r['consignee_name'],
            $r['item_name'],$r['item_code'],$r['hsn_code'],$r['description'],
            $r['qty'],$r['weight'],$r['uom'],$r['unit_price'],$r['gst_rate'],
            $r['gst_amount'],$r['total_price'],$r['total_weight'],
            $r['transporter_name'],$r['vehicle_no']
        ], $rows)
    ];
}

// ── Sales Invoices ───────────────────────────────────────────
if (in_array('sales_invoices', $selected)) {
    $rows = $db->query("SELECT si.id, si.invoice_number, si.invoice_date,
        c.company_name, si.consignee_name, si.consignee_city, si.consignee_state,
        si.consignee_gstin, si.gst_type,
        si.subtotal, si.cgst_amount, si.sgst_amount, si.igst_amount, si.total_amount,
        si.payment_terms, si.due_date,
        si.mrn_number, si.mrn_date,
        si.invoice_reg_number, si.invoice_reg_date,
        si.status, si.remarks,
        do.challan_no,
        COALESCE((SELECT SUM(amount) FROM sales_invoice_payments WHERE invoice_id=si.id),0) AS paid_amount
        FROM sales_invoices si
        LEFT JOIN companies c ON si.company_id=c.id
        LEFT JOIN despatch_orders do ON si.challan_id=do.id
        WHERE 1=1 " . $dw('si.invoice_date') . "
        ORDER BY si.invoice_date DESC");
    $rows = ($rows && $rows !== false) ? $rows->fetch_all(MYSQLI_ASSOC) : [];
    $workbook['Sales Invoices'] = [
        'headers' => ['ID','Invoice No','Invoice Date','Company','Consignee','City','State',
                      'Consignee GSTIN','GST Type','Subtotal','CGST','SGST','IGST','Total Amount',
                      'Payment Terms','Due Date','MRN Number','MRN Date',
                      'Invoice Reg No','Invoice Reg Date','Status','Remarks',
                      'Linked Challan','Amount Paid','Outstanding'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['invoice_number'],$r['invoice_date'],
            $r['company_name'],$r['consignee_name'],$r['consignee_city'],$r['consignee_state'],
            $r['consignee_gstin'],$r['gst_type'],
            $r['subtotal'],$r['cgst_amount'],$r['sgst_amount'],$r['igst_amount'],$r['total_amount'],
            $r['payment_terms'],$r['due_date'],
            $r['mrn_number'],$r['mrn_date'],
            $r['invoice_reg_number'],$r['invoice_reg_date'],
            $r['status'],$r['remarks'],$r['challan_no'],
            $r['paid_amount'],round($r['total_amount']-$r['paid_amount'],2)
        ], $rows)
    ];
}

// ── Transporter Payments ─────────────────────────────────────
if (in_array('transporter_payments', $selected)) {
    $rows = safeQuery($db, "SELECT tp.id, tp.payment_date, t.transporter_name,
        d.challan_no, d.despatch_date,
        tp.payment_no, tp.payment_type, tp.amount, tp.base_amount,
        tp.gst_type, tp.gst_rate, tp.gst_amount, tp.gst_held,
        tp.tds_rate, tp.tds_amount, tp.net_payable,
        tp.payment_mode, tp.reference_no,
        tp.status, tp.remarks
        FROM transporter_payments tp
        LEFT JOIN transporters t ON tp.transporter_id=t.id
        LEFT JOIN despatch_orders d ON tp.despatch_id=d.id
        WHERE 1=1 " . $dw('tp.payment_date') . "
        ORDER BY tp.payment_date DESC");
    $workbook['Transporter Payments'] = [
        'headers' => ['ID','Payment Date','Transporter','Challan No','Despatch Date',
                      'Payment No','Payment Type','Amount','Base Amount',
                      'GST Type','GST Rate%','GST Amount','GST Held',
                      'TDS Rate%','TDS Amount','Net Payable',
                      'Payment Mode','Reference No','Status','Remarks'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['payment_date'],$r['transporter_name'],
            $r['challan_no'],$r['despatch_date'],
            $r['payment_no'],$r['payment_type'],$r['amount'],$r['base_amount'],
            $r['gst_type'],$r['gst_rate'],$r['gst_amount'],$r['gst_held'],
            $r['tds_rate'],$r['tds_amount'],$r['net_payable'],
            $r['payment_mode'],$r['reference_no'],
            $r['status'],$r['remarks']
        ], $rows)
    ];
}

// ── Agent Commissions ────────────────────────────────────────
if (in_array('agent_commissions', $selected)) {
    $res = safeQuery($db, "SELECT ac.id, ac.despatch_date, ac.challan_no, ac.vendor_name,
        u.full_name AS agent_name, u.email AS agent_email,
        ac.received_weight, ac.vendor_rate, ac.transporter_rate,
        ac.profit_per_mt, ac.slab_applied, ac.commission_pct, ac.commission_amt,
        ac.status, ac.created_at
        FROM agent_commissions ac
        LEFT JOIN app_users u ON ac.agent_id=u.id
        WHERE 1=1 " . $dw('ac.despatch_date') . "
        ORDER BY ac.despatch_date DESC, ac.id DESC");
    $rows = $res;
    $workbook['Agent Commissions'] = [
        'headers' => ['ID','Despatch Date','Challan No','Vendor/Consignee','Agent',
                      'Agent Email','Received Weight (MT)','Vendor Rate','Transporter Rate',
                      'Profit/MT','Slab Applied','Commission %','Commission Amt (₹)',
                      'Status','Created At'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['despatch_date'],$r['challan_no'],$r['vendor_name'],
            $r['agent_name']??'',$r['agent_email']??'',
            $r['received_weight'],$r['vendor_rate'],$r['transporter_rate'],
            $r['profit_per_mt'],$r['slab_applied'],$r['commission_pct'],$r['commission_amt'],
            $r['status'],$r['created_at']
        ], $rows)
    ];
}

// ── PO Line Items ────────────────────────────────────────────
if (in_array('po_items', $selected)) {
    $rows = safeQuery($db, "SELECT p.po_number, p.po_date, v.vendor_name,
        i.item_name, i.item_code, i.hsn_code,
        pi.qty, pi.unit_price, pi.taxable_amount,
        pi.gst_rate, pi.gst_amount,
        pi.cgst_rate, pi.cgst_amount,
        pi.sgst_rate, pi.sgst_amount,
        pi.igst_rate, pi.igst_amount,
        pi.discount, pi.total_price, pi.received_qty, pi.remarks
        FROM po_items pi
        JOIN purchase_orders p ON pi.po_id=p.id
        JOIN items i ON pi.item_id=i.id
        LEFT JOIN vendors v ON p.vendor_id=v.id
        ORDER BY p.po_date DESC, p.id, pi.id");
    $workbook['PO Line Items'] = [
        'headers' => ['PO Number','PO Date','Vendor','Item Name','Item Code','HSN Code',
                      'Qty','Unit Price','Taxable Amt',
                      'GST Rate%','GST Amt','CGST Rate%','CGST Amt',
                      'SGST Rate%','SGST Amt','IGST Rate%','IGST Amt',
                      'Discount','Total Price','Received Qty','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet PO Line Items ───────────────────────────────────────
if (in_array('fleet_po_items', $selected)) {
    $rows = safeQuery($db, "SELECT p.po_number, p.po_date, c.vendor_name AS customer,
        pi.item_name, pi.uom, pi.qty, pi.unit_price,
        pi.gst_rate, pi.gst_amount, pi.amount
        FROM fleet_po_items pi
        JOIN fleet_purchase_orders p ON pi.po_id=p.id
        LEFT JOIN fleet_customers_master c ON p.vendor_id=c.id
        ORDER BY p.po_date DESC, p.id, pi.id");
    $workbook['Fleet PO Line Items'] = [
        'headers' => ['PO Number','PO Date','Customer','Item Name','UOM',
                      'Qty','Unit Price (₹)','GST Rate%','GST Amt (₹)','Amount (₹)'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Trading Receipts ──────────────────────────────────────────
if (in_array('trading_receipts', $selected)) {
    $rows = safeQuery($db, "SELECT tr.id, tr.receipt_no, tr.receipt_date,
        v.vendor_name, i.item_name, s.source_name,
        tr.vehicle_no, tr.supplier_invoice_no,
        tr.quantity_received, tr.deduction_pct, tr.deduction_qty, tr.final_qty,
        tr.royalty_cmtr, tr.conversion_ratio, tr.royalty_mt,
        tr.rate_per_unit, tr.amount,
        tr.status, tr.remarks
        FROM trading_receipts tr
        LEFT JOIN vendors v ON tr.vendor_id=v.id
        LEFT JOIN items i ON tr.item_id=i.id
        LEFT JOIN source_of_material s ON tr.source_id=s.id
        WHERE 1=1 " . $dw('tr.receipt_date') . "
        ORDER BY tr.receipt_date DESC, tr.id DESC");
    $workbook['Trading Receipts'] = [
        'headers' => ['ID','Receipt No','Date','Vendor','Item','Source',
                      'Vehicle No','Supplier Inv No',
                      'Qty Received','Deduction%','Deduction Qty','Final Qty',
                      'Royalty (CMtr)','Conv Ratio','Royalty (MT)',
                      'Rate/Unit (₹)','Amount (₹)',
                      'Status','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Quotations ────────────────────────────────────────────────
if (in_array('quotations', $selected)) {
    $rows = safeQuery($db, "SELECT q.id, q.quotation_no, q.quotation_date, q.valid_till,
        q.quotation_for, q.client_name, q.client_company,
        CONCAT_WS(', ', NULLIF(q.client_city,''), NULLIF(q.client_state,'')) AS client_location,
        q.client_mobile, q.client_email,
        q.ref_person, q.ref_mobile,
        q.payment_terms, q.delivery_schedule, q.offer_validity,
        q.status, q.notes,
        u.full_name AS created_by
        FROM quotations q
        LEFT JOIN app_users u ON q.created_by=u.id
        WHERE 1=1 " . $dw('q.quotation_date') . "
        ORDER BY q.quotation_date DESC, q.id DESC");
    $workbook['Quotations'] = [
        'headers' => ['ID','Quotation No','Date','Valid Till','For',
                      'Client Name','Client Company','Client Location',
                      'Client Mobile','Client Email',
                      'Ref Person','Ref Mobile',
                      'Payment Terms','Delivery Schedule','Offer Validity',
                      'Status','Notes','Created By'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Sales Invoice Payments ────────────────────────────────────
if (in_array('sales_invoice_payments', $selected)) {
    $rows = safeQuery($db, "SELECT sip.id, si.invoice_number, si.invoice_date,
        si.consignee_name, sip.payment_date, sip.amount,
        sip.payment_mode, sip.reference_no, sip.remarks
        FROM sales_invoice_payments sip
        JOIN sales_invoices si ON sip.invoice_id=si.id
        WHERE 1=1 " . $dw('sip.payment_date') . "
        ORDER BY sip.payment_date DESC, sip.id DESC");
    $workbook['Sales Invoice Payments'] = [
        'headers' => ['ID','Invoice No','Invoice Date','Consignee',
                      'Payment Date','Amount (₹)','Payment Mode','Reference No','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Vendor Receipts ───────────────────────────────────────────
if (in_array('sales_vendor_receipts', $selected)) {
    $rows = safeQuery($db, "SELECT svr.id, v.vendor_name, svr.receipt_date,
        svr.amount_received, svr.unallocated_amount,
        svr.payment_mode, svr.reference_no, svr.remarks
        FROM sales_vendor_receipts svr
        LEFT JOIN vendors v ON svr.vendor_id=v.id
        WHERE 1=1 " . $dw('svr.receipt_date') . "
        ORDER BY svr.receipt_date DESC, svr.id DESC");
    $workbook['Vendor Receipts'] = [
        'headers' => ['ID','Vendor','Receipt Date',
                      'Amount Received (₹)','Unallocated (₹)',
                      'Payment Mode','Reference No','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Agent Commission Payments ─────────────────────────────────
if (in_array('agent_commission_payments', $selected)) {
    $rows = safeQuery($db, "SELECT acp.id, u.full_name AS agent_name, u.email AS agent_email,
        acp.paid_date, acp.amount, acp.reference, acp.notes
        FROM agent_commission_payments acp
        LEFT JOIN app_users u ON acp.agent_id=u.id
        WHERE 1=1 " . $dw('acp.paid_date') . "
        ORDER BY acp.paid_date DESC, acp.id DESC");
    $workbook['Agent Comm Payments'] = [
        'headers' => ['ID','Agent','Agent Email',
                      'Paid Date','Amount (₹)','Reference','Notes'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Transporter Rate Cards ────────────────────────────────────
if (in_array('transporter_rates', $selected)) {
    $rows = safeQuery($db, "SELECT tr.id, t.transporter_name, v.vendor_name,
        tr.rate, tr.uom, tr.effective_from, tr.status, tr.notes
        FROM transporter_rates tr
        LEFT JOIN transporters t ON tr.transporter_id=t.id
        LEFT JOIN vendors v ON tr.vendor_id=v.id
        ORDER BY t.transporter_name, v.vendor_name, tr.effective_from DESC");
    $workbook['Transporter Rates'] = [
        'headers' => ['ID','Transporter','Vendor/Customer','Rate','UOM',
                      'Effective From','Status','Notes'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Fuel Payments ──────────────────────────────────────
if (in_array('fleet_fuel_payments', $selected)) {
    $rows = safeQuery($db, "SELECT fp.id, fc.company_name AS fuel_company,
        fp.payment_date, fp.amount, fp.payment_mode, fp.reference_no, fp.remarks
        FROM fleet_fuel_payments fp
        LEFT JOIN fleet_fuel_companies fc ON fp.fuel_company_id=fc.id
        WHERE 1=1 " . $dw('fp.payment_date') . "
        ORDER BY fp.payment_date DESC, fp.id DESC");
    $workbook['Fleet Fuel Payments'] = [
        'headers' => ['ID','Fuel Company','Payment Date',
                      'Amount (₹)','Payment Mode','Reference No','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Tyres ──────────────────────────────────────────────
if (in_array('fleet_tyres', $selected)) {
    $rows = safeQuery($db, "SELECT t.id, t.tyre_serial, t.brand, t.size, t.type,
        t.purchase_date, t.purchase_invoice, t.purchase_amount,
        t.amount_paid, t.payment_status, t.payment_date, t.payment_mode, t.payment_ref,
        t.vendor_name, v.reg_no AS vehicle_reg,
        t.position, t.fit_date, t.fit_odometer,
        t.remove_date, t.remove_odometer,
        t.status, t.notes
        FROM fleet_tyres t
        LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
        ORDER BY t.purchase_date DESC, t.id DESC");
    $workbook['Fleet Tyres'] = [
        'headers' => ['ID','Serial No','Brand','Size','Type',
                      'Purchase Date','Purchase Invoice','Purchase Amt (₹)',
                      'Amt Paid (₹)','Payment Status','Payment Date','Payment Mode','Payment Ref',
                      'Vendor','Vehicle Reg',
                      'Position','Fit Date','Fit Odometer',
                      'Remove Date','Remove Odometer',
                      'Status','Notes'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Vendors ────────────────────────────────────────────
if (in_array('fleet_vendors', $selected)) {
    $rows = safeQuery($db, "SELECT id, vendor_code, vendor_name, contact_person,
        phone, email, address, city, state, gstin, pan,
        bank_name, account_no, ifsc_code,
        vendor_type, status, notes
        FROM fleet_vendors ORDER BY vendor_name");
    $workbook['Fleet Vendors'] = [
        'headers' => ['ID','Code','Vendor Name','Contact Person',
                      'Phone','Email','Address','City','State','GSTIN','PAN',
                      'Bank Name','Account No','IFSC Code',
                      'Vendor Type','Status','Notes'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Lease Agents ───────────────────────────────────────
if (in_array('fleet_lease_agents', $selected)) {
    $rows = safeQuery($db, "SELECT id, agent_code, agent_name, agent_short_name, contact_person,
        phone, mobile, email, default_margin_per_mt, status, company_id, notes, created_at
        FROM fleet_lease_agents ORDER BY agent_name");
    $workbook['Fleet Lease Agents'] = [
        'headers' => ['ID','Code','Agent Name','Short Name','Contact Person',
                      'Phone','Mobile','Email','Default Margin/MT','Status','Company ID','Notes','Created At'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Vendor Master ────────────────────────────────────────────
if (in_array('vendors', $selected)) {
    $rows = $db->query("SELECT id, vendor_code, vendor_name,
        contact_person, email, phone, mobile,
        address, city, state, pincode, gstin, pan,
        ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_gstin,
        bill_address, bill_city, bill_state, bill_pincode, bill_gstin,
        bank_name, account_no, ifsc_code,
        payment_terms, credit_limit, status
        FROM vendors ORDER BY vendor_name");
    $rows = ($rows && $rows !== false) ? $rows->fetch_all(MYSQLI_ASSOC) : [];
    $workbook['Vendors'] = [
        'headers' => ['ID','Code','Vendor Name',
                      'Contact Person','Email','Phone','Mobile',
                      'Address','City','State','Pincode','GSTIN','PAN',
                      'Ship Name','Ship Address','Ship City','Ship State','Ship Pincode','Ship GSTIN',
                      'Bill Address','Bill City','Bill State','Bill Pincode','Bill GSTIN',
                      'Bank Name','Account No','IFSC Code',
                      'Payment Terms','Credit Limit','Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Item Master ───────────────────────────────────────────────
if (in_array('items', $selected)) {
    $rows = $db->query("SELECT id, item_code, item_name, description, category, uom, hsn_code,
        unit_price, gst_rate, reorder_level, stock_qty, weight_per_unit, status
        FROM items ORDER BY item_name");
    $rows = ($rows && $rows !== false) ? $rows->fetch_all(MYSQLI_ASSOC) : [];
    $workbook['Items'] = [
        'headers' => ['ID','Item Code','Item Name','Description','Category','UOM','HSN Code',
                      'Unit Price','GST Rate%','Reorder Level','Stock Qty','Weight/Unit','Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Transporter Master ────────────────────────────────────────
if (in_array('transporters', $selected)) {
    $rows = $db->query("SELECT id, transporter_code, transporter_name, contact_person,
        phone, mobile, email, address, city, state, pincode, gstin, pan,
        lr_prefix, vehicle_types,
        bank_name, account_no, ifsc_code,
        rate_per_kg, rate_per_km,
        gst_type, tds_applicable, status
        FROM transporters ORDER BY transporter_name");
    $rows = ($rows && $rows !== false) ? $rows->fetch_all(MYSQLI_ASSOC) : [];
    $workbook['Transporters'] = [
        'headers' => ['ID','Code','Transporter Name','Contact Person','Phone','Mobile','Email',
                      'Address','City','State','Pincode','GSTIN','PAN',
                      'LR Prefix','Vehicle Types',
                      'Bank Name','Account No','IFSC Code',
                      'Rate/Kg','Rate/Km',
                      'GST Type','TDS Applicable','Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Companies ────────────────────────────────────────────────
if (in_array('companies', $selected)) {
    $rows = safeQuery($db, "SELECT id, company_name, address, city, state, pincode, phone, email,
        gstin, pan, bank_name, account_no, ifsc_code, smtp_host, smtp_port, smtp_user,
        smtp_secure, smtp_from_name, seal_path, mtc_sig_path, checked_by_sig_path, is_active, created_at
        FROM companies ORDER BY id");
    $workbook['Companies'] = [
        'headers' => ['ID','Company Name','Address','City','State','Pincode','Phone','Email',
                      'GSTIN','PAN','Bank Name','Account No','IFSC Code','SMTP Host','SMTP Port','SMTP User',
                      'SMTP Secure','SMTP From Name','Seal Path','MTC Sig Path','Checked By Sig Path','Is Active','Created At'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Company Settings ──────────────────────────────────────────
if (in_array('company_settings', $selected)) {
    $rows = safeQuery($db, "SELECT * FROM company_settings ORDER BY id");
    $workbook['Company Settings'] = [
        'headers' => !empty($rows) ? array_keys($rows[0]) : ['id'],
        'rows'    => $rows
    ];
}

// ── Aggregate Items ───────────────────────────────────────────
if (in_array('aggregate_items', $selected)) {
    $rows = safeQuery($db, "SELECT id, item_code, item_name, description, uom, hsn_code,
        rate_per_unit, conversion_ratio, status, created_at, updated_at
        FROM aggregate_items ORDER BY item_name");
    $workbook['Aggregate Items'] = [
        'headers' => ['ID','Item Code','Item Name','Description','UOM','HSN Code',
                      'Rate/Unit','Conversion Ratio','Status','Created At','Updated At'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Aggregate Sources ─────────────────────────────────────────
if (in_array('aggregate_sources', $selected)) {
    $rows = safeQuery($db, "SELECT id, source_code, source_name, description, status, created_at
        FROM aggregate_sources ORDER BY source_name");
    $workbook['Aggregate Sources'] = [
        'headers' => ['ID','Source Code','Source Name','Description','Status','Created At'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Aggregate Suppliers ───────────────────────────────────────
if (in_array('aggregate_suppliers', $selected)) {
    $rows = safeQuery($db, "SELECT id, supplier_code, supplier_name, contact_person, phone, mobile,
        gstin, address, city, state, status, created_at
        FROM aggregate_suppliers ORDER BY supplier_name");
    $workbook['Aggregate Suppliers'] = [
        'headers' => ['ID','Supplier Code','Supplier Name','Contact Person','Phone','Mobile',
                      'GSTIN','Address','City','State','Status','Created At'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Sales Orders (Customer POs) ───────────────────────
if (in_array('fleet_purchase_orders', $selected)) {
    $res = safeQuery($db, "SELECT p.id, p.po_number, p.po_date, c.company_name,
        v.vendor_name AS customer_name,
        p.validity_date, p.delivery_address, p.payment_terms, p.gst_type,
        p.subtotal, p.gst_amount, p.total_amount, p.status, p.remarks
        FROM fleet_purchase_orders p
        LEFT JOIN fleet_customers_master v ON p.vendor_id=v.id
        LEFT JOIN companies c ON p.company_id=c.id
        WHERE 1=1 " . $dw('p.po_date') . "
        ORDER BY p.po_date DESC");
    $rows = $res;
    $workbook['Fleet Sales Orders'] = [
        'headers' => ['ID','PO Number','PO Date','Company','Customer',
                      'Validity Date','Delivery Address','Payment Terms','GST Type',
                      'Subtotal','GST Amount','Total Amount','Status','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Trip Orders ───────────────────────────────────────
if (in_array('fleet_trips', $selected)) {
    $rows = safeQuery($db, "SELECT t.trip_no, t.trip_date, v.reg_no,
        CONCAT(v.make,' ',v.model) AS vehicle,
        d.full_name AS driver_name, d.phone AS driver_phone,
        c.vendor_name AS customer, po.po_number,
        t.from_location, t.to_location, COALESCE(t.customer_camp,'') AS customer_camp,
        t.total_weight, t.freight_amount,
        t.driver_advance,
        t.loading_charges, t.unloading_charges,
        t.other_expenses, t.subtotal, t.total_amount,
        t.start_odometer, t.end_odometer, t.start_date, t.end_date,
        t.status,
        COALESCE(t.mrn_no,'') AS mrn_no,
        COALESCE(t.mrn_date,'') AS mrn_date,
        COALESCE(t.inv_reg_no,'') AS inv_reg_no,
        COALESCE(t.inv_reg_date,'') AS inv_reg_date,
        COALESCE(t.billing_status,'Pending') AS billing_status,
        COALESCE(t.sales_bill_no,'') AS sales_bill_no,
        COALESCE(t.sales_bill_date,'') AS sales_bill_date,
        COALESCE(t.billing_remarks,'') AS billing_remarks,
        t.remarks,
        COALESCE(t.lease_agent_id, 0) AS trip_lease_agent_id,
        COALESCE(v.lease_agent_id, 0) AS vehicle_lease_agent_id,
        COALESCE(t.lease_agent_name, '') AS lease_agent_name,
        t.toll_amount
        FROM fleet_trips t
        LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
        LEFT JOIN fleet_drivers d ON t.driver_id=d.id
        LEFT JOIN fleet_customers_master c ON t.vendor_id=c.id
        LEFT JOIN fleet_purchase_orders po ON t.po_id=po.id
        WHERE 1=1".$dw('t.trip_date')."
        ORDER BY t.trip_date DESC, t.id DESC");
    $workbook['Fleet Trips'] = [
        'headers' => ['Trip No','Date','Vehicle Reg','Make/Model','Driver','Driver Phone',
                      'Customer','PO No','From','To','Camp / Site / Unit','Weight (MT)','Freight (₹)',
                      'Driver Advance','Toll','Loading','Unloading','Other Exp',
                      'Subtotal','Total Amount',
                      'Start Odometer','End Odometer','Start Date','End Date',
                      'Status',
                      'MRN No','MRN Date','Inv Reg No','Inv Reg Date',
                      'Billing Status','Sales Bill No','Sales Bill Date','Billing Remarks',
                      'Remarks'],
        'rows'    => array_map(function($r) {
            $is_lease = ($r['trip_lease_agent_id'] > 0 || $r['vehicle_lease_agent_id'] > 0 || !empty($r['lease_agent_name']));
            return [
                $r['trip_no'], $r['trip_date'], $r['reg_no'],
                $r['vehicle'],
                $r['driver_name'], $r['driver_phone'],
                $r['customer'], $r['po_number'],
                $r['from_location'], $r['to_location'], $r['customer_camp'],
                $r['total_weight'], $r['freight_amount'],
                $r['driver_advance'],
                $is_lease ? null : $r['toll_amount'],
                $r['loading_charges'], $r['unloading_charges'],
                $r['other_expenses'], $r['subtotal'], $r['total_amount'],
                $r['start_odometer'], $r['end_odometer'], $r['start_date'], $r['end_date'],
                $r['status'],
                $r['mrn_no'], $r['mrn_date'], $r['inv_reg_no'], $r['inv_reg_date'],
                $r['billing_status'], $r['sales_bill_no'], $r['sales_bill_date'], $r['billing_remarks'],
                $r['remarks']
            ];
        }, $rows)
    ];
}

// ── Fleet: Trip Items ────────────────────────────────────────
if (in_array('fleet_trip_items', $selected)) {
    $res = safeQuery($db, "SELECT t.trip_no, t.trip_date,
        ti.item_name, ti.uom, ti.qty, ti.unit_price, ti.weight, ti.amount
        FROM fleet_trip_items ti
        JOIN fleet_trips t ON ti.trip_id=t.id
        WHERE 1=1".$dw('t.trip_date')."
        ORDER BY t.trip_date DESC, t.id DESC, ti.id ASC");
    $rows = $res;
    $workbook['Fleet Trip Items'] = [
        'headers' => ['Trip No','Trip Date','Item Name','UOM','Qty','Unit Price (₹)','Weight (MT)','Amount (₹)'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Fuel Log ───────────────────────────────────────────
if (in_array('fleet_fuel_log', $selected)) {
    $rows = safeQuery($db, "SELECT fl.id, t.trip_no, COALESCE(v.reg_no, fl.manual_vehicle_no, '') AS vehicle_no, d.full_name AS driver,
        fc.company_name AS fuel_company, fl.fuel_date, fl.litres,
        fl.rate_per_litre, fl.amount, fl.driver_advance,
        (fl.amount + COALESCE(fl.driver_advance,0)) AS total,
        fl.payment_mode, fl.bill_no, COALESCE(fl.supervisor_expense_id,'') AS supervisor_expense_id, fl.notes
        FROM fleet_fuel_log fl
        LEFT JOIN fleet_vehicles v ON fl.vehicle_id=v.id
        LEFT JOIN fleet_drivers d ON fl.driver_id=d.id
        LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id=fc.id
        LEFT JOIN fleet_trips t ON fl.trip_id=t.id
        WHERE 1=1".$dw('fl.fuel_date')."
        ORDER BY fl.fuel_date DESC, fl.id DESC");
    $workbook['Fleet Fuel Log'] = [
        'headers' => ['ID','Trip No','Vehicle','Driver','Fuel Company','Date',
                      'Litres','Rate/L (₹)','Fuel Amt (₹)','Driver Advance (₹)','Total (₹)',
                      'Payment Mode','Bill No','Supervisor Expense ID','Notes'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Vehicle Expenses ───────────────────────────────────
if (in_array('fleet_expenses', $selected)) {
    $rows = safeQuery($db, "SELECT e.id, COALESCE(v.reg_no, e.manual_vehicle_no, '') AS vehicle_no, t.trip_no, e.expense_date,
        e.expense_type, COALESCE(fc.company_name, ev.vendor_name, e.vendor_name) AS vendor_display,
        COALESCE(e.fuel_litres,0) AS fuel_litres, COALESCE(e.fuel_rate_per_litre,0) AS fuel_rate_per_litre,
        COALESCE(e.linked_fuel_log_id,'') AS linked_fuel_log_id,
        e.description, e.amount, COALESCE(e.payment_status,'Not Paid') AS payment_status, e.payment_mode,
        e.bill_no, e.odometer
        FROM fleet_expenses e
        LEFT JOIN fleet_vehicles v ON e.vehicle_id=v.id
        LEFT JOIN fleet_trips t ON e.trip_id=t.id
        LEFT JOIN fleet_expense_vendors ev ON e.vendor_id=ev.id
        LEFT JOIN fleet_fuel_companies fc ON e.fuel_company_id=fc.id
        WHERE 1=1".$dw('e.expense_date')."
        ORDER BY e.expense_date DESC, e.id DESC");
    $workbook['Fleet Expenses'] = [
        'headers' => ['ID','Vehicle','Trip No','Date','Type','Vendor / Fuel Company',
                      'Fuel Litres','Fuel Rate/L','Linked Fuel Log ID',
                      'Description','Amount (₹)','Payment Status','Payment Mode','Bill No','Odometer'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Driver Salary ──────────────────────────────────────
if (in_array('fleet_salary', $selected)) {
    $rows = safeQuery($db, "SELECT s.id, d.full_name AS driver, s.salary_month,
        COALESCE(s.salary_from,'') AS salary_from,
        COALESCE(s.salary_to,'') AS salary_to,
        COALESCE(s.working_days,'') AS working_days,
        COALESCE(s.per_day_rate,'') AS per_day_rate,
        s.basic_salary, s.trip_count,
        s.other_allowances, COALESCE(s.allowance_notes,'') AS allowance_notes,
        s.other_deductions, COALESCE(s.deduction_notes,'') AS deduction_notes,
        s.net_payable, s.paid_amount, s.payment_date, s.payment_mode,
        s.reference_no, s.status, s.remarks
        FROM fleet_driver_salary s
        LEFT JOIN fleet_drivers d ON s.driver_id=d.id
        ORDER BY s.salary_month DESC, d.full_name");
    $workbook['Fleet Driver Salary'] = [
        'headers' => ['ID','Driver','Month','From Date','To Date','Working Days','Per Day Rate (₹)',
                      'Basic (₹)','Trips',
                      'Allowances (₹)','Allowance Notes',
                      'Deductions (₹)','Deduction Notes',
                      'Net Payable (₹)','Paid (₹)','Payment Date',
                      'Payment Mode','Ref No','Status','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Driver Retro Salary ────────────────────────────────
if (in_array('fleet_retro_salary', $selected)) {
    $res = safeQuery($db, "SELECT r.id, d.full_name AS driver, r.salary_month,
        r.held_amount, r.deduction_amount, r.deduction_notes,
        r.released_amount, r.release_date, r.release_mode, r.release_ref,
        r.balance, r.status, r.remarks
        FROM fleet_driver_retro_salary r
        LEFT JOIN fleet_drivers d ON r.driver_id=d.id
        ORDER BY r.salary_month DESC, d.full_name");
    $rows = $res;
    $workbook['Fleet Retro Salary'] = [
        'headers' => ['ID','Driver','Month','Amount Held (₹)','Deduction (₹)','Deduction Notes',
                      'Released (₹)','Release Date','Release Mode','Release Ref',
                      'Balance (₹)','Status','Remarks'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Vehicles ───────────────────────────────────────────
if (in_array('fleet_vehicles', $selected)) {
    $rows = safeQuery($db, "SELECT id, reg_no, make, model, year, capacity_tons,
        fuel_type, insurance_no, insurance_expiry, fitness_expiry,
        permit_expiry, puc_expiry, national_permit_expiry,
        chassis_no, engine_no, status, notes
        FROM fleet_vehicles ORDER BY reg_no");
    $workbook['Fleet Vehicles'] = [
        'headers' => ['ID','Reg No','Make','Model','Year','Capacity (T)',
                      'Fuel Type','Insurance No','Insurance Expiry','Fitness Expiry',
                      'Permit Expiry','PUC Expiry','Nat Permit Expiry',
                      'Chassis No','Engine No','Status','Notes'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Drivers ────────────────────────────────────────────
if (in_array('fleet_drivers', $selected)) {
    $rows = safeQuery($db, "SELECT id, driver_code, full_name, role, phone, phone2,
        license_no, license_expiry, license_type, basic_salary,
        aadhar_no, blood_group, emergency_contact, join_date, status
        FROM fleet_drivers ORDER BY full_name");
    $workbook['Fleet Drivers'] = [
        'headers' => ['ID','Code','Full Name','Role','Phone','Phone 2',
                      'License No','License Expiry','License Type','Basic Salary (₹)',
                      'Aadhar No','Blood Group','Emergency Contact','Join Date','Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Fleet: Customers Master ───────────────────────────────────
if (in_array('fleet_customers', $selected)) {
    $res = safeQuery($db, "SELECT id, vendor_code, vendor_name,
        contact_person, email, phone,
        address, city, state, pincode, gstin,
        ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_gstin,
        status
        FROM fleet_customers_master ORDER BY vendor_name");
    $rows = $res;
    $workbook['Fleet Customers'] = [
        'headers' => ['ID','Code','Customer Name',
                      'Contact Person','Email','Phone',
                      'Address','City','State','Pincode','GSTIN',
                      'Ship Name','Ship Address','Ship City','Ship State','Ship Pincode','Ship GSTIN',
                      'Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Raw Database Tables: future-proof export of every actual table/column ──
if (in_array('raw_database_tables', $selected)) {
    $table_res = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if ($table_res) {
        while ($table_row = $table_res->fetch_row()) {
            $table = $table_row[0];
            $escaped_table = str_replace('`', '``', $table);
            $cols_res = $db->query("SHOW COLUMNS FROM `{$escaped_table}`");
            if (!$cols_res) continue;
            $headers = [];
            while ($col = $cols_res->fetch_assoc()) $headers[] = $col['Field'];
            if (empty($headers)) continue;

            $rows_res = $db->query("SELECT * FROM `{$escaped_table}`");
            $rows = [];
            if ($rows_res) {
                while ($row = $rows_res->fetch_assoc()) {
                    $rows[] = array_map(fn($h) => $row[$h] ?? null, $headers);
                }
            }
            $workbook['DB ' . $table] = [
                'headers' => $headers,
                'rows' => $rows,
            ];
        }
    }
}

// ── Generate XLSX ─────────────────────────────────────────────
$company = getCompany();
$fname   = 'DMS_Export_' . date('Y-m-d') . '.xlsx';

function xlsxEsc($v) {
    return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function buildSheet($sheetName, $headers, $rows) {
    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetData>';

    // Header row
    $xml .= '<row r="1">';
    $col = 0;
    foreach ($headers as $h) {
        $col++;
        $cellRef = colLetter($col) . '1';
        $xml .= '<c r="'.$cellRef.'" t="inlineStr" s="1"><is><t>'.xlsxEsc($h).'</t></is></c>';
    }
    $xml .= '</row>';

    // Data rows
    $ri = 2;
    foreach ($rows as $row) {
        $isAlt = ($ri % 2 === 0);
        $xml .= '<row r="'.$ri.'">';
        $col = 0;
        foreach ($row as $val) {
            $col++;
            $cellRef = colLetter($col) . $ri;
            $styleIdx = $isAlt ? 3 : 2;
            if ($val === null || $val === '') {
                $xml .= '<c r="'.$cellRef.'" s="'.$styleIdx.'"/>';
            } elseif (is_numeric($val) && !preg_match('/^0\d/', (string)$val)) {
                $xml .= '<c r="'.$cellRef.'" s="'.$styleIdx.'"><v>'.xlsxEsc($val).'</v></c>';
            } else {
                $xml .= '<c r="'.$cellRef.'" t="inlineStr" s="'.$styleIdx.'"><is><t>'.xlsxEsc($val).'</t></is></c>';
            }
        }
        $xml .= '</row>';
        $ri++;
    }

    $xml .= '</sheetData>';

    // Auto filter
    if (count($headers) > 0 && count($rows) > 0) {
        $lastCol = colLetter(count($headers));
        $lastRow = count($rows) + 1;
        $xml .= '<autoFilter ref="A1:'.$lastCol.$lastRow.'"/>';
    }

    $xml .= '</worksheet>';
    return $xml;
}

function colLetter($n) {
    $letters = '';
    while ($n > 0) {
        $rem = ($n - 1) % 26;
        $letters = chr(65 + $rem) . $letters;
        $n = (int)(($n - 1) / 26);
    }
    return $letters;
}

function xlsxSafeSheetName($name, &$usedNames) {
    $base = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', (string)$name);
    $base = trim(preg_replace('/\s+/', ' ', $base));
    if ($base === '') $base = 'Sheet';
    $base = substr($base, 0, 31);
    $name = $base;
    $i = 2;
    while (isset($usedNames[strtolower($name)])) {
        $suffix = ' ' . $i;
        $name = substr($base, 0, 31 - strlen($suffix)) . $suffix;
        $i++;
    }
    $usedNames[strtolower($name)] = true;
    return $name;
}

// Styles XML
function buildStyles() {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts>
    <font><sz val="10"/><name val="Arial"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    <font><sz val="10"/><name val="Arial"/></font>
    <font><sz val="10"/><name val="Arial"/></font>
  </fonts>
  <fills>
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1A5632"/></patternFill></fill>
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F8F3"/></patternFill></fill>
  </fills>
  <borders>
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFCCCCCC"/></left>
      <right style="thin"><color rgb="FFCCCCCC"/></right>
      <top style="thin"><color rgb="FFCCCCCC"/></top>
      <bottom style="thin"><color rgb="FFCCCCCC"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">
      <alignment horizontal="left" vertical="center" wrapText="0"/>
    </xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1">
      <alignment vertical="center"/>
    </xf>
    <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1">
      <alignment vertical="center"/>
    </xf>
  </cellXfs>
</styleSheet>';
}

// Build ZIP (XLSX is a ZIP file)
$tmpdir = sys_get_temp_dir() . '/dms_xlsx_' . uniqid();
mkdir($tmpdir);
mkdir("$tmpdir/_rels");
mkdir("$tmpdir/xl");
mkdir("$tmpdir/xl/_rels");
mkdir("$tmpdir/xl/worksheets");
mkdir("$tmpdir/docProps");

// Write sheet XMLs and collect references
$sheetMeta = [];
$sheetIdx  = 1;
$usedSheetNames = [];
foreach ($workbook as $sheetName => $sheetData) {
    $xml = buildSheet($sheetName, $sheetData['headers'], $sheetData['rows']);
    file_put_contents("$tmpdir/xl/worksheets/sheet{$sheetIdx}.xml", $xml);
    $sheetMeta[] = ['name' => xlsxSafeSheetName($sheetName, $usedSheetNames), 'idx' => $sheetIdx, 'count' => count($sheetData['rows'])];
    $sheetIdx++;
}

// workbook.xml
$wbXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
$wbXml .= '<sheets>';
foreach ($sheetMeta as $sm) {
    $wbXml .= '<sheet name="'.xlsxEsc($sm['name']).'" sheetId="'.$sm['idx'].'" r:id="rId'.$sm['idx'].'"/>';
}
$wbXml .= '</sheets></workbook>';
file_put_contents("$tmpdir/xl/workbook.xml", $wbXml);

// xl/_rels/workbook.xml.rels
$relsXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$relsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
foreach ($sheetMeta as $sm) {
    $relsXml .= '<Relationship Id="rId'.$sm['idx'].'"
        Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
        Target="worksheets/sheet'.$sm['idx'].'.xml"/>';
}
$relsXml .= '<Relationship Id="rId'.($sheetIdx).'"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>';
$relsXml .= '</Relationships>';
file_put_contents("$tmpdir/xl/_rels/workbook.xml.rels", $relsXml);

// styles.xml
file_put_contents("$tmpdir/xl/styles.xml", buildStyles());

// [Content_Types].xml
$ctXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$ctXml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
$ctXml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
$ctXml .= '<Default Extension="xml" ContentType="application/xml"/>';
$ctXml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
foreach ($sheetMeta as $sm) {
    $ctXml .= '<Override PartName="/xl/worksheets/sheet'.$sm['idx'].'.xml"
        ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}
$ctXml .= '<Override PartName="/xl/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
$ctXml .= '</Types>';
file_put_contents("$tmpdir/[Content_Types].xml", $ctXml);

// _rels/.rels
$rootRels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
$rootRels .= '<Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="xl/workbook.xml"/>';
$rootRels .= '</Relationships>';
file_put_contents("$tmpdir/_rels/.rels", $rootRels);

// Create ZIP
$zipfile = sys_get_temp_dir() . '/' . $fname;
if (file_exists($zipfile)) unlink($zipfile);

$zip = new ZipArchive();
$zip->open($zipfile, ZipArchive::CREATE);

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpdir));
foreach ($files as $file) {
    if ($file->isDir()) continue;
    $localPath = str_replace($tmpdir . '/', '', $file->getPathname());
    $zip->addFile($file->getPathname(), $localPath);
}
$zip->close();

// Cleanup temp dir
function rmdirRecursive($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? rmdirRecursive($path) : unlink($path);
    }
    rmdir($dir);
}
rmdirRecursive($tmpdir);

// Send file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($zipfile));
header('Cache-Control: no-cache');
readfile($zipfile);
unlink($zipfile);
exit;
