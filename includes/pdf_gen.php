<?php
if (!function_exists('r2_url')) require_once __DIR__ . '/r2_helper.php';
/**
 * DespatchPDF  —  Pure-PHP PDF generator
 * All heights derived from print_challan.php CSS (1px = 0.75pt, 1mm = 2.8346pt)
 *
 * CHALLAN SECTIONS (fixed heights matching CSS exactly):
 *   HDR_TOP  = 70pt   padding:10px + 18pt name + 8pt×3 taglines
 *   HDR_INFO = 61pt   4 meta-rows × (3px+8.5pt+3px) + 8px padding
 *   BANNER   = 15pt   padding:4px + 9pt font
 *   ADDR     = 75pt   box-title 15pt + name 9.5pt + 4 lines×8.5pt×1.5
 *   TH/TR/TF = 16pt   padding:5px + 8.5pt font
 *   TOTALS   = 56pt   3 rows×13pt + grand-total 17.5pt
 *   TERMS    = 30pt   padding:6px + 2 wrapped lines at 8pt
 *   SIG      = 60pt   padding-top:30px + min-height:70px content (FIXED)
 *   FOOTER   = 20pt   padding:8px + 7.5pt font
 *
 * MTC SECTIONS (on same page as Original, 20pt gap):
 *   HDR      = 42pt   td padding:8px + 14px title + 10px×2 sub-lines
 *   INFO ROW = 16pt   td padding:5px + 11px font  (×3 rows)
 *   SOURCE   = 19pt   td padding:7px + 10.5px font
 *   RES ROW  = 17pt   th/td padding:6px + 11px font  (×5 = hdr+4 tests)
 *   REMARKS  = 18pt   margin-top:8px + 11px font  (optional)
 *   SIG      = 81pt   margin-top:20px + min-height:60px box + 6px + 10px×2 labels
 *   FOOTER   = 22pt   margin-top:10px + padding-top:5px + 9px font
 */

/* ── Resolve image to local file path for PDF embedding ──
   Local uploads/ → absolute server path
   R2 key → fetch via HTTP to a temp file               */
function pdf_resolve_image(string $path): string {
    if (empty($path)) return '';
    // Local file
    if (strpos($path, 'uploads/') === 0) {
        $abs = dirname(__DIR__) . '/' . $path;
        return file_exists($abs) ? $abs : '';
    }
    // R2 key — build URL directly to avoid stub r2_url() issue
    $pub = defined('R2_PUBLIC_URL') ? R2_PUBLIC_URL : 'https://pub-5721570094064d529f1527519424c77b.r2.dev/dms_uploads';
    $url = $pub . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || empty($data)) return '';
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
    $tmp  = tempnam(sys_get_temp_dir(), 'pdfsig_') . '.' . $ext;
    file_put_contents($tmp, $data);
    return $tmp;
}

function pdf_default_logo_path(): string {
    $candidates = [
        dirname(__DIR__) . '/assets/icons/icon-192x192.png',
        dirname(__DIR__) . '/assets/icons/apple-touch-icon.png',
    ];
    foreach ($candidates as $candidate) {
        if (file_exists($candidate) && filesize($candidate) > 0) {
            return $candidate;
        }
    }
    return '';
}

class DespatchPDF
{
    /* ── page geometry (A4) ─────────────────────────── */
    private float $W  = 595.28;
    private float $H  = 841.89;
    private float $ML = 22.68;   // 8mm left margin
    private float $MR = 22.68;   // 8mm right margin
    private float $MT = 14.17;   // 5mm top margin
    private float $MB = 14.17;   // 5mm bottom margin

    /* ── challan section heights ────────────────────── */
    private const CH_HDR_TOP  = 70;
    private const CH_HDR_INFO = 61;
    private const CH_BANNER   = 15;
    private const CH_ADDR     = 105;  // increased for address word-wrap: bt(15)+gap(15)+name(10)+4×lh(9.5)+city+gstin
    private const CH_TH       = 16;
    private const CH_TR       = 16;
    private const CH_TF       = 16;
    private const CH_TOTALS   = 56;
    private const CH_TERMS    = 30;
    private const CH_SIG      = 60;   // FIXED — never stretches
    private const CH_FOOTER   = 20;

    /* ── MTC section heights ────────────────────────── */
    private const MTC_HDR     = 42;
    private const MTC_IR      = 16;   // info row
    private const MTC_SRC     = 19;   // source row
    private const MTC_RR      = 17;   // results row (header + each test)
    private const MTC_REM     = 18;   // remarks (optional)
    private const MTC_SIG_GAP = 15;   // margin-top:20px -> 15pt
    private const MTC_BOX     = 45;   // min-height:60px -> 45pt  (dashed box)
    private const MTC_LBL     = 20;   // margin-bottom:6px(4.5) + font:10px(7.5) × 2 lines
    private const MTC_FOOT    = 22;

    /* ── PDF build state ────────────────────────────── */
    private array  $objs     = [];
    private array  $objTypes = [];
    private int    $nextId   = 1;
    private array  $pageIds  = [];
    private string $buf      = '';
    private array  $sigImages = [];   // xname => ['raw'=>..,'w'=>..,'h'=>..] collected during draw
    private float  $PW;   // printable width = W - ML - MR
    private float  $cy;   // current Y position (counts DOWN from top)

    /* ══════════════════════════════════════════════════
       PUBLIC ENTRY POINT
    ══════════════════════════════════════════════════ */
    public function build(array $d, array $items): string
    {
        $this->PW = $this->W - $this->ML - $this->MR;  // 549.92pt

        $copies = [
            ['Original (Consignee)',    true,  '1a5632'], // Green  — Consignee
            ['Duplicate (Transporter)', false, '1a3a6b'], // Blue   — Transporter
        ];

        foreach ($copies as [$label, $withMTC, $copyColor]) {
            $this->startPage();
            $bottomY = $this->drawChallan($d, $items, $label, $copyColor);
            if ($withMTC && ($d['mtc_required'] ?? 'No') === 'Yes') {
                $this->cy = $bottomY - 20;   // 20pt gap between challan and MTC
                $this->drawMTC($d);
            }
            $this->commitPage();
        }

        return $this->renderPDF();
    }

    /* ══════════════════════════════════════════════════
       CHALLAN DRAWING
    ══════════════════════════════════════════════════ */
    private function drawChallan(array $d, array $items, string $lbl, string $copyColor = '1a5632'): float
    {
        $is_draft   = isset($d['status']) && $d['status'] === 'Draft';
        $is_transit = isset($d['status']) && $d['status'] === 'In Transit';
        $L = $this->ML;
        $W = $this->PW;
        $this->cy = $this->H - $this->MT;

        /* ─── 1. HEADER TOP (70pt) ───────────────────────
           Dark-blue band. padding:10px->7.5pt each side.
           Left: company-name 18pt + 3 tagline lines 8pt
           Right: "DELIVERY CHALLAN" 16pt + meta 8pt        */
        $y = $this->cy - self::CH_HDR_TOP;
        $this->fillRect($L, $y, $W, self::CH_HDR_TOP, $copyColor);

        $logo_path = pdf_default_logo_path();
        $text_x = $L + 7;
        if ($logo_path) {
            $this->embedCompanyImage($logo_path, 'HeaderLogo', $L + 7, $y + 12, 40, 40, 0);
            $text_x = $L + 54;
        }
        $left_text_w = max(120, ($L + $W - 190) - $text_x - 8);

        // company name  18pt bold
        $this->txt($text_x, $y+47, $this->fitW($this->s($d['company_name']), $left_text_w, 18), 18, true, 'ffffff');
        // address tagline 8pt
        $adr = $this->s($d['co_address']).', '.$this->s($d['co_city'])
              .($d['co_state']   ? ', '.$d['co_state']   : '')
              .($d['co_pincode'] ? ' - '.$d['co_pincode']: '');
        $this->txt($text_x, $y+34, $this->fitW($adr, $left_text_w, 8), 8, false, 'b8e6c8');
        // phone/email
        $con = trim(($d['co_phone'] ? $d['co_phone'].'   ' : '').($d['co_email'] ?? ''));
        if ($con) $this->txt($text_x, $y+23, $this->fitW($con, $left_text_w, 8), 8, false, 'b8e6c8');
        // gstin/pan
        $gst = 'GSTIN: '.$this->s($d['co_gstin']).($d['co_pan'] ? '   PAN: '.$d['co_pan'] : '');
        $this->txt($text_x, $y+12, $this->fitW($gst, $left_text_w, 8), 8, false, 'b8e6c8');

        // right: title
        $rx = $L + $W - 190;
        $this->txt($rx, $y+50, 'DELIVERY CHALLAN', 16, true, 'ffffff');
        $dt = $d['despatch_date'] ? date('d/m/Y', strtotime($d['despatch_date'])) : '-';
        $this->txt($rx, $y+39, 'Challan No: '.$this->s($d['challan_no']),  11, true, 'ffffff');
        $this->txt($rx, $y+24, 'Date: '.$dt,                               11, true, 'ffffff');
        $this->cy -= self::CH_HDR_TOP;

        /* ─── 2. HEADER INFO (61pt) ──────────────────────
           Left panel (flex:1): despatch + vendor text 8.5pt
           Right panel (200px->150pt): meta rows            */
        $mw = 150;   // challan-meta width
        $dw = $W - $mw;
        $y  = $this->cy - self::CH_HDR_INFO;
        $this->strokeRect($L, $y, $W, self::CH_HDR_INFO, $copyColor, 2.0);
        $this->vLine($L+$dw, $y, self::CH_HDR_INFO, $copyColor, 1.5);

        // left: vendor & source
        $dl = '';
        if (!empty($d['vendor_name'])) $dl = 'Vendor/Consignor: '.$d['vendor_name'];
        $this->txt($L+6, $y+self::CH_HDR_INFO-15, $this->fitW($dl, $dw-12, 8.5), 8.5, false, '222222');

        $src_disp = trim((string)($d['source_name'] ?? ''));
        if ($src_disp === '') $src_disp = trim((string)($d['mtc_source'] ?? ''));
        if ($src_disp !== '') {
            $this->txt($L+6, $y+self::CH_HDR_INFO-28, $this->fitW('Source: '.$src_disp, $dw-12, 8.5), 8.5, true, '222222');
        }

        // right: meta rows  (padding:3px each, font:8.5pt)
        $wt_uom = !empty($items[0]['uom']) ? $items[0]['uom']
                : (!empty($items[0]['i_uom']) ? $items[0]['i_uom'] : 'Kg');
        $wt_dp  = $this->uomDecimals($wt_uom);
        $mrows = [
            ['Challan Date:',  $dt],
            ['No. of Pkgs:',  (string)($d['no_of_packages'] ?? '-')],
            ['Total Weight:', $is_draft ? '—' : number_format((float)($d['total_weight']??0), $wt_dp).' '.$wt_uom],

        ];
        $mrh = self::CH_HDR_INFO / count($mrows);
        foreach ($mrows as $ri => [$ml, $mv]) {
            $ry = $y + self::CH_HDR_INFO - ($ri+1)*$mrh;
            if ($ri > 0) $this->hLine($L+$dw, $ry+$mrh, $mw, $copyColor, 0.5);
            $this->txt($L+$dw+5, $ry+$mrh/2-4, $ml, 8, true,  '555555');
            $this->txtR($L+$dw,  $ry+$mrh/2-4, $mw-5, $mv, 8.5, true, '222222');
        }
        $this->cy -= self::CH_HDR_INFO;

        /* ─── 3. COPY BANNER (15pt) ──────────────────── */
        $y = $this->cy - self::CH_BANNER;
        $this->fillRect($L, $y, $W, self::CH_BANNER, 'f0f8f3');
        $this->strokeRect($L, $y, $W, self::CH_BANNER, $copyColor, 2.0);
        $this->txtC($L, $y, $W, self::CH_BANNER, strtoupper($lbl), 9, true, $copyColor);
        $this->cy -= self::CH_BANNER;

        /* ─── 4. ADDRESS (90pt) ──────────────────────────
           3 equal columns. box-title: 7pt bold, bg:#e5f5eb, 15pt tall.
           Content: name 9.5pt + 4 lines × 8.5pt × line-height 1.5     */
        $col = $W / 3;   // 183.31pt each
        $y   = $this->cy - self::CH_ADDR;

        // box title bars (15pt tall, at top of each column) — draw fills FIRST
        $bt = 15;
        foreach ([0,1,2] as $ci) {
            $this->fillRect($L+$ci*$col, $y+self::CH_ADDR-$bt, $col, $bt, 'e5f5eb');
        }
        // Draw borders AFTER fills so they appear on top
        $this->strokeRect($L, $y, $W, self::CH_ADDR, $copyColor, 2.0);
        $this->vLine($L+$col,   $y, self::CH_ADDR, $copyColor, 1.5);
        $this->vLine($L+$col*2, $y, self::CH_ADDR, $copyColor, 1.5);
        $this->txt($L+5,        $y+self::CH_ADDR-$bt+4, 'CONSIGNEE (SHIP TO)', 7, true, $copyColor);
        $this->txt($L+$col+5,   $y+self::CH_ADDR-$bt+4, 'PO & TRANSPORTER DETAILS', 7, true, $copyColor);
        $this->txt($L+$col*2+5, $y+self::CH_ADDR-$bt+4, 'VEHICLE & DRIVER',    7, true, $copyColor);

        // content: line-height 1.5 × 8.5pt = 12.75pt spacing
        $lh = 12.75;
        $yc = $y + self::CH_ADDR - $bt - 15;

        // Each column clips its content to its own width using PDF clipping paths.
        // Helper: draw clipped text line — clips to column rect before rendering
        $clipH  = $y + self::CH_ADDR - $bt;   // top of clipping region (below title bar)
        $clipBot = $y;                          // bottom of clipping region

        // Col 1 — Consignee with word-wrap (clipped to $L .. $L+$col)
        $pad   = 5;
        $colW  = $col - $pad * 2;   // usable text width inside column
        $fsz   = 7.5;               // font size for address lines
        $nlh   = 9.5;               // line height for wrapped lines
        $curY  = $yc;               // current Y cursor

        // Name (bold, slightly larger)
        $this->txtInCol($L, $y, $col, $curY, $this->cleanAddr($d['consignee_name']), 8.5, true, '111111');
        $curY -= $lh;

        // Optional camp/site marker for same ship-to addresses
        if (!empty($d['consignee_camp']) && $curY >= $y + 10) {
            $this->txtInCol($L, $y, $col, $curY, 'Camp / Site: '.$this->cleanAddr($d['consignee_camp']), $fsz, true, '333333');
            $curY -= $nlh;
        }

        // Address — word-wrap into multiple lines
        $addr = $this->cleanAddr($d['consignee_address']);
        if ($addr !== '-') {
            $addrLines = $this->wrap($addr, $colW, $fsz);
            foreach ($addrLines as $aline) {
                if ($curY < $y + 10) break;  // don't overflow below box
                $this->txtInCol($L, $y, $col, $curY, $aline, $fsz, false, '333333');
                $curY -= $nlh;
            }
        }

        // City, State - Pincode
        $ct = $this->cleanAddr($d['consignee_city'])
             .($d['consignee_state']   ? ', '.$this->cleanAddr($d['consignee_state'])   : '')
             .($d['consignee_pincode'] ? ' - '.$this->cleanAddr($d['consignee_pincode']): '');
        if ($curY >= $y + 10) {
            $this->txtInCol($L, $y, $col, $curY, $ct, $fsz, false, '333333');
            $curY -= $nlh;
        }

        // GSTIN
        if (!empty($d['consignee_gstin']) && $curY >= $y + 10) {
            $this->txtInCol($L, $y, $col, $curY, 'GSTIN: '.$this->cleanAddr($d['consignee_gstin']), $fsz, false, '333333');
        }

        // Col 2 — PO & Transporter Details (clipped to $L+$col .. $L+$col*2)
        $x2 = $L+$col;
        if (!empty($d['po_number']))
            $this->txtInCol($x2, $y, $col, $yc, 'PO No: '.$d['po_number'], 9.5, true, '111111');
        $tc = !empty($d['transporter_code']) ? $d['transporter_code'] : 'Self / Direct';
        $this->txtInCol($x2, $y, $col, $yc-$lh,   'Trans: '.$tc, 8.5, false, '333333');
        if (!empty($d['lr_number']))
            $this->txtInCol($x2, $y, $col, $yc-$lh*2, 'LR No: '.$d['lr_number'], 8.5, false, '333333');
        if (!empty($d['lr_date']))
            $this->txtInCol($x2, $y, $col, $yc-$lh*3, 'LR Date: '.date('d/m/Y',strtotime($d['lr_date'])), 8.5, false, '333333');

        // Col 3 — Vehicle & Driver (clipped to $L+$col*2 .. $L+$W)
        $x3 = $L+$col*2;
        if (!empty($d['vehicle_no']))
            $this->txtInCol($x3, $y, $col, $yc,       'Vehicle No: '.$d['vehicle_no'],  8.5, true,  '111111');
        if (!empty($d['driver_name']))
            $this->txtInCol($x3, $y, $col, $yc-$lh,   'Driver: '.$d['driver_name'],     8.5, false, '333333');
        if (!empty($d['driver_mobile']))
            $this->txtInCol($x3, $y, $col, $yc-$lh*2, 'Mobile: '.$d['driver_mobile'],   8.5, false, '333333');
        $fr_text = 'Freight: '.$this->s($d['freight_paid_by']).' Pay';
        $misc_val = (float)($d['transporter_misc_charges'] ?? 0);
        $misc_rem = trim((string)($d['transporter_misc_remarks'] ?? ''));
        if ($misc_val > 0 || $misc_rem !== '') {
            $misc_str = ' | Misc: ' . ($misc_val > 0 ? ('Rs.' . number_format($misc_val, 2)) : '') . ($misc_rem !== '' ? (' (' . $misc_rem . ')') : '');
            $fr_text .= $misc_str;
        }
        $this->txtInCol($x3, $y, $col, $yc-$lh*3, $fr_text, 8.5, false, '555555');
        $this->cy -= self::CH_ADDR;

        /* ─── 5. ITEMS TABLE ─────────────────────────────
           Column widths mirror print_challan percentages:
           4%  8%  28%  8%  6%  8%  10%  6%  8%  10% = 96%  (remaining 4% distributed)
           Adjusted to sum to 100%: +0.4% to each of 10 cols  */
        $cdef = [
          //  label              width%   align
            ['S.No',              3,      'c'],
            ['Item Code',         7,      'c'],
            ['Item Description', 21,      'l'],
            ['HSN Code',          6,      'c'],
            ['UOM',               5,      'c'],
            ['Desp Qty',          7,      'c'],
            ['Rcvd Wt',           7,      'c'],
            ['Unit Price',        9,      'r'],
            ['GST%',              5,      'c'],
            ['GST Amt',           8,      'r'],
            ['Total Value',      10,      'r'],
        ];
        // Convert % to pt
        $cw = array_map(fn($c) => [$c[0], $W*$c[1]/100, $c[2]], $cdef);

        // Table header row
        $y = $this->cy - self::CH_TH;
        $this->fillRect($L, $y, $W, self::CH_TH, $copyColor);
        $cx = $L;
        foreach ($cw as [$cl, $w, $al]) {
            $this->txtA($cx, $y+4.5, $w, $cl, 7, true, 'ffffff', $al);
            $cx += $w;
        }
        $this->cy -= self::CH_TH;

        // Item rows
        $sub = 0; $gst = 0; $qty = 0; $wt = 0;
        foreach ($items as $ri => $row) {
            $y = $this->cy - self::CH_TR;
            $this->fillRect($L, $y, $W, self::CH_TR, $ri%2===1 ? 'f9f9f9' : 'ffffff');
            $this->strokeRect($L, $y, $W, self::CH_TR, $copyColor, 1.5);  // border after fill
            $sub += (float)$row['qty'] * (float)$row['unit_price'];
            $gst += (float)$row['gst_amount'];
            $qty += (float)$row['qty'];
            $wt  += (float)($row['weight'] ?? 0);
            $row_uom = (string)($row['uom'] ?: ($row['i_uom']??''));
            $row_dp  = $this->uomDecimals($row_uom);
            $rv = [
                (string)($ri+1),
                $this->fitW((string)($row['item_code']??''), $cw[1][1]-6, 8.5),
                $this->fitW((string)($row['item_name']??''), $cw[2][1]-6, 8.5),
                $this->fitW((string)($row['hsn_code']??''),  $cw[3][1]-6, 8.5),
                $row_uom,
                ((float)($row['qty']??0) > 0) ? number_format((float)$row['qty'], $row_dp) : '-',
                $is_draft ? "\xe2\x80\x94" : (((float)($row['weight']??0) > 0) ? number_format((float)$row['weight'], 3) : '-'),
                $is_draft ? "\xe2\x80\x94" : 'Rs.'.number_format((float)$row['unit_price'],2),
                $is_draft ? "\xe2\x80\x94" : $row['gst_rate'].'%',
                $is_draft ? "\xe2\x80\x94" : 'Rs.'.number_format((float)$row['gst_amount'],2),
                $is_draft ? "\xe2\x80\x94" : 'Rs.'.number_format((float)$row['total_price'],2),
            ];
            $cx = $L;
            foreach ($cw as $ci => [$_, $w, $al]) {
                $this->txtA($cx, $y+4.5, $w, $rv[$ci], 8.5, false, '222222', $al);
                $cx += $w;
            }
            $this->cy -= self::CH_TR;
        }

        // Table footer
        $y = $this->cy - self::CH_TF;
        $this->fillRect($L, $y, $W, self::CH_TF, 'f0f8f3');
        $this->strokeRect($L, $y, $W, self::CH_TF, $copyColor, 2.0);
        $fv = ['','','Total:','','',
               $qty > 0 ? number_format($qty, 3) : '-',
               $is_draft ? "\xe2\x80\x94" : ($wt > 0 ? number_format($wt, 3) : '-'),
               '','',
               $is_draft ? "\xe2\x80\x94" : 'Rs.'.number_format($gst,2),
               $is_draft ? "\xe2\x80\x94" : 'Rs.'.number_format((float)($d['total_amount']??0),2)];
        $cx = $L;
        foreach ($cw as $ci => [$_, $w, $al]) {
            $this->txtA($cx, $y+4.5, $w, $fv[$ci], 8.5, true, $copyColor, $al);
            $cx += $w;
        }
        $this->cy -= self::CH_TF;

        /* ─── 6. TOTALS (56pt) ───────────────────────────
           Left (flex:1): amount-in-words + remarks
           Right (200px->150pt): 3 sub-rows + grand-total bar  */
        $tw = 150;   // totals table width
        $aw = $W - $tw;
        $y  = $this->cy - self::CH_TOTALS;
        $this->strokeRect($L, $y, $W, self::CH_TOTALS, $copyColor, 2.0);
        $this->vLine($L+$aw, $y, self::CH_TOTALS, $copyColor, 1.5);

        // Amount in words (left)
        $words = $is_draft ? 'Draft — Amount Not Applicable' : $this->n2w((int)round((float)($d['total_amount']??0)));
        $this->txt($L+6, $y+self::CH_TOTALS-12, 'Amount in Words:', 7, true,  '555555');
        $this->txt($L+6, $y+self::CH_TOTALS-22, $this->fitW($words.' Only', $aw-12, 8.5), 8.5, true, '111111');
        if (!empty($d['remarks'])) {
            $this->txt($L+6, $y+self::CH_TOTALS-34, 'Remarks:', 7, true, '555555');
            $this->txt($L+6, $y+self::CH_TOTALS-44, $this->fitW($d['remarks'], $aw-12, 8.5), 8.5, false, '333333');
        } elseif ($misc_rem !== '') {
            $this->txt($L+6, $y+self::CH_TOTALS-34, 'Misc Remarks:', 7, true, '555555');
            $this->txt($L+6, $y+self::CH_TOTALS-44, $this->fitW($misc_rem, $aw-12, 8.5), 8.5, false, '333333');
        }

        // Grand total bar (bottom 17.5pt of right panel)
        $gr_h = 17.5;
        $this->fillRect($L+$aw, $y, $tw, $gr_h, $copyColor);
        $this->txt($L+$aw+6, $y+5, 'GRAND TOTAL:', 10, true, 'ffffff');
        $this->txtR($L+$aw, $y+4, $tw-6, ($is_draft ? '—' : 'Rs.'.number_format((float)($d['total_amount']??0),2)), 10, true, 'ffffff');

        // Sub-rows above grand total
        $trows = [
            ['Sub Total:',  $is_draft ? '—' : 'Rs.'.number_format((float)($d['subtotal']??0),2)],
            ['GST Amount:', $is_draft ? '—' : 'Rs.'.number_format((float)$d['gst_amount'],2)],
            ['Freight:',    ((float)($d['freight_amount']??0) > 0) ? ('Rs.'.number_format((float)$d['freight_amount'],2)) : '—'],
        ];
        if ($misc_val > 0) {
            $trows[] = ['Misc Charges:', 'Rs.'.number_format($misc_val, 2)];
        }
        $nr_h = (self::CH_TOTALS - $gr_h) / count($trows);
        $ty2 = $y + self::CH_TOTALS;
        foreach ($trows as [$tl, $tv]) {
            $ty2 -= $nr_h;
            $this->hLine($L+$aw, $ty2+$nr_h, $tw, $copyColor, 0.5);
            $this->txt($L+$aw+6, $ty2+$nr_h/2-4, $tl, 8.5, false, '555555');
            $this->txtR($L+$aw,  $ty2+$nr_h/2-4, $tw-6, $tv, 8.5, false, '333333');
        }
        $this->cy -= self::CH_TOTALS;

        /* ─── 7. TERMS (30pt) ────────────────────────────
           Full-width, word-wrapped text at 8pt.
           Exact text from print_challan.php remarks section   */
        $terms = '1. Goods once sold will not be taken back.  |  '
                .'2. Interest @18% p.a. will be charged if payment is not made within due date.  |  '
                .'3. All disputes subject to local jurisdiction only.  |  4. E. & O.E.';
        $y = $this->cy - self::CH_TERMS;
        $this->strokeRect($L, $y, $W, self::CH_TERMS, $copyColor, 2.0);
        $lines = $this->wrap($terms, $W-10, 8);
        $ly = $y + self::CH_TERMS - 8;
        foreach ($lines as $line) {
            $this->txt($L+5, $ly, $line, 8, false, '333333');
            $ly -= 11;  // 8pt font × ~1.35 line-height
        }
        $this->cy -= self::CH_TERMS;

        /* ─── 8. SIGNATURES (60pt FIXED) ────────────────
           padding-top:30px->22.5pt, padding-bottom:10px->7.5pt
           min-height:70px->52.5pt content area
           4 equal columns, signature line near bottom         */
        $y  = $this->cy - self::CH_SIG;
        $sc = $W / 4;
        $this->strokeRect($L, $y, $W, self::CH_SIG, $copyColor, 2.0);

        // Signature line at 20pt from bottom (sig-title area)
        $line_y = $y + 20;
        $sigs = [
            // [label, sub-label]
            ['Prepared By',         ''],
            ['Checked By',          ''],
            ['Consignee Signature', '(Goods Received in Good Condition)'],
            ['Authorised Signatory','For '.$this->fitW($this->s($d['company_name']), $sc-10, 7)],
        ];
        for ($i = 0; $i < 4; $i++) {
            $sx = $L + $i*$sc;
            if ($i > 0) $this->vLine($sx, $y, self::CH_SIG, $copyColor, 1.5);
            // Horizontal signature line
            $this->hLine($sx, $line_y, $sc, '555555', 1.0);
            [$st1, $st2] = $sigs[$i];
            // sig-title label (just below sig line)
            $this->txtC($sx, $y+12, $sc, 10, $st1, 7, true, '555555');
            // sub-label / name below title
            if ($i === 0) {
                // Prepared By — signature image above line, name below sig-title
                $ps = !empty($d['prepared_by_sig']) ? $d['prepared_by_sig'] : ($d['auth_sig_path'] ?? '');
                if ($ps) {
                    $pf = pdf_resolve_image($ps);
                    if ($pf) $this->embedCompanyImage($pf, 'PreparedBy', $sx, $y, $sc, self::CH_SIG, $line_y);
                }
                if (!empty($d['prepared_by_name'])) {
                    $nm = $this->fitW($d['prepared_by_name'], $sc-6, 7);
                    // y+4 places name below the sig-title at y+12 (PDF coords bottom-up)
                    $this->txt($sx + ($sc - $this->tw($nm, 7)) / 2, $y + 3.5, $nm, 7, true, $copyColor);
                }
            } elseif ($i === 1) {
                // Checked By — show checked_by_sig_path from company
                $cb = $d['checked_by_sig_path'] ?? '';
                if ($cb) {
                    $f = pdf_resolve_image($cb);
                    if ($f) $this->embedCompanyImage($f, 'CheckedBy', $sx, $y, $sc, self::CH_SIG, $line_y);
                }
            } elseif ($i === 3) {
                // Authorised Signatory — show company seal
                $seal = $d['seal_path'] ?? '';
                if ($seal) {
                    $sf = pdf_resolve_image($seal);
                    if ($sf) $this->embedCompanyImage($sf, 'AuthSeal', $sx, $y, $sc, self::CH_SIG, $line_y);
                }
            } elseif ($st2) {
                $this->txtC($sx, $y+3, $sc, 8, $this->fitW($st2, $sc-6, 7), 7, false, '666666');
            }
        }
        $this->cy -= self::CH_SIG;

        /* ─── 9. FOOTER (20pt) ───────────────────────────
           Exact text from print_challan.php              */
        $y = $this->cy - self::CH_FOOTER;
        $this->fillRect($L, $y, $W, self::CH_FOOTER, 'f8f9fa');
        $this->strokeRect($L, $y, $W, self::CH_FOOTER, $copyColor, 2.0);
        $foot = 'This is a computer generated Delivery Challan  |  '
               .$this->s($d['company_name']).'  |  '
               .'GSTIN: '.$this->s($d['co_gstin']).'  |  '
               .'Generated on: '.date('d/m/Y H:i:s');
        $this->txtC($L, $y, $W, self::CH_FOOTER, $this->fitW($foot, $W-12, 7.5), 7.5, false, '666666');
        $this->cy -= self::CH_FOOTER;

        return $this->cy;
    }

    /* ══════════════════════════════════════════════════
       MTC DRAWING  — exact CSS px->pt from print_challan
       All sections use fitW() so nothing is truncated
    ══════════════════════════════════════════════════ */
    private function drawMTC(array $d): void
    {
        $L  = $this->ML;
        $W  = $this->PW;

        /* Column widths from CSS:
           info table: lbl width:28%  value=22%  (4-col row)
           results:    c1=50%  c2=25%  c3=25%
           sig boxes:  each 45%  gap=10%            */
        $lbl_w = $W * 0.28;   // 153.97pt  label col
        $val_w = $W * 0.22;   // 120.98pt  value col
        $rc1   = $W * 0.50;   // 274.96pt
        $rc2   = $W * 0.25;   // 137.48pt
        $rc3   = $W * 0.25;   // 137.48pt
        $bw    = $W * 0.45;   // 247.46pt  each sig box
        $bgap  = $W - 2*$bw;  //  55.00pt  centre gap

        /* ── MTC HEADER (42pt) ────────────────────────
           <table>: td[0] width:25% | td[1] width:75%
           td padding:8px->6pt. border:2px solid #333   */
        $lo_w = $W * 0.25;   // 137.48pt  logo cell
        $ti_w = $W * 0.75;   // 412.44pt  title cell
        $y    = $this->cy - self::MTC_HDR;

        // Logo cell
        $this->fillRect($L, $y, $lo_w, self::MTC_HDR, 'ffffff');
        $this->strokeRect($L, $y, $lo_w, self::MTC_HDR, '1a5632', 2.0);
        $abbr = strtoupper(substr($this->s($d['company_name']), 0, 3));
        $this->txtC($L, $y, $lo_w, self::MTC_HDR, $abbr, 18, true, '1a5632');

        // Title cell
        $this->fillRect($L+$lo_w, $y, $ti_w, self::MTC_HDR, 'ffffff');
        $this->strokeRect($L+$lo_w, $y, $ti_w, self::MTC_HDR, '1a5632', 2.0);
        // "MATERIAL TEST CERTIFICATE (MTC)"  font:14px->10.5pt bold
        $this->txtC($L+$lo_w, $y+26, $ti_w, 12,
            'MATERIAL TEST CERTIFICATE (MTC)', 10.5, true, '111111');
        // company name  font:10px->7.5pt  margin-top:3px->2.25pt
        $this->txtC($L+$lo_w, $y+15, $ti_w, 10,
            $this->fitW($this->s($d['company_name']), $ti_w-12, 7.5), 7.5, false, '555555');
        // address | GSTIN  font:10px->7.5pt
        $coinfo = $this->s($d['co_address']).', '.$this->s($d['co_city'])
                 .($d['co_state'] ? ', '.$d['co_state'] : '')
                 .' | GSTIN: '.$this->s($d['co_gstin']);
        $this->txtC($L+$lo_w, $y+5, $ti_w, 10,
            $this->fitW($coinfo, $ti_w-12, 7.5), 7.5, false, '555555');
        $this->cy -= self::MTC_HDR;

        /* ── INFO TABLE (3 rows × 16pt) ──────────────
           .mtc-info-table td { border:1px solid #999; padding:5px 8px }
           .lbl { background:#fffbea; width:28%; font-weight:700 }

           Row 1: Challan No & Vehicle No. | Despatch Date
           Row 2: Item Name               | Test Date
           Row 3: Vendor Name             colspan=3           */

        $dt = $d['despatch_date'] ? date('d/m/Y', strtotime($d['despatch_date'])) : '-';
        $td = $d['mtc_test_date'] ? date('d/m/Y', strtotime($d['mtc_test_date'])) : '-';

        // Row 1
        $this->mtcRow4(
            'Challan No & Vehicle No.',
            $this->s($d['challan_no']).' | '.$this->s($d['vehicle_no']),
            'Despatch Date', $dt,
            $lbl_w, $val_w, self::MTC_IR, false
        );
        // Row 2
        $this->mtcRow4(
            'Item Name',
            $this->s($d['mtc_item_name']),
            'Test Date', $td,
            $lbl_w, $val_w, self::MTC_IR, true
        );
        // Row 3  (vendor colspan=3)
        $this->mtcRow2(
            'Vendor Name',
            $this->s($d['vendor_name']),
            $lbl_w, $W-$lbl_w, self::MTC_IR, false
        );

        /* ── SOURCE ROW (19pt) ────────────────────────
           colspan=4, padding:7px 8px, bg:#fff8e1, font:10.5px->7.88pt  */
        $y = $this->cy - self::MTC_SRC;
        $this->fillRect($L, $y, $W, self::MTC_SRC, 'fff8e1');
        $this->strokeRect($L, $y, $W, self::MTC_SRC, '1a5632', 2.0);  // border after fill
        $src = 'Six random samples of Fly Ash were collected at one hour interval'
              .' & average results are as under:   Source: '.$this->s($d['mtc_source']);
        // font 7.88pt, padding 5.25pt left
        $this->txt($L+6, $y+6, $this->fitW($src, $W-12, 7.88), 7.88, false, '333333');
        $this->cy -= self::MTC_SRC;

        /* ── RESULTS TABLE HEADER (17pt) ─────────────
           th { padding:6px 8px; bg:#f5e642; font:11px->8.25pt; border:1px solid #555 }
           col widths: TEST=50%  RESULTS=25%  REQUIREMENTS=25%              */
        $y = $this->cy - self::MTC_RR;
        $this->fillRect($L, $y, $W, self::MTC_RR, 'f5e642');
        $this->strokeRect($L, $y, $W, self::MTC_RR, '1a5632', 2.0);
        $this->vLine($L+$rc1,      $y, self::MTC_RR, '1a5632', 2.0);
        $this->vLine($L+$rc1+$rc2, $y, self::MTC_RR, '1a5632', 2.0);
        $this->txtC($L,            $y, $rc1, self::MTC_RR, 'TEST',                         8.25, true, '111111');
        $this->txtC($L+$rc1,       $y, $rc2, self::MTC_RR, 'RESULTS %',                    8.25, true, '111111');
        $this->txtC($L+$rc1+$rc2,  $y, $rc3, self::MTC_RR, 'Requirements as per IS 3812',  8.25, true, '111111');
        $this->cy -= self::MTC_RR;

        /* ── TEST RESULT ROWS (17pt each) ────────────
           td { padding:6px 8px; font:11px->8.25pt; border:1px solid #999 }
           td.test-name { text-align:left; font-weight:600 }               */
        $tests = [
            ['ROS 45 Micron Sieve',
             $this->s($d['mtc_ros_45']).'%',          '< 34%',        false],
            ['Moisture',
             $this->s($d['mtc_moisture']).'%',        '< 2%',         true ],
            ['Loss on Ignition',
             $this->s($d['mtc_loi']).'%',             '< 5%',         false],
            ["Fineness - Specific Surface Area by Blaine's Permeability Method",
             $this->s($d['mtc_fineness']).' m2/kg',   '> 320 m2/kg',  true ],
        ];
        foreach ($tests as [$test, $res, $req, $shade]) {
            $y = $this->cy - self::MTC_RR;
            $this->fillRect($L, $y, $W, self::MTC_RR, $shade ? 'f9f9f9' : 'ffffff');
            $this->strokeRect($L, $y, $W, self::MTC_RR, '1a5632', 1.5);
            $this->vLine($L+$rc1,      $y, self::MTC_RR, '1a5632', 1.5);
            $this->vLine($L+$rc1+$rc2, $y, self::MTC_RR, '1a5632', 1.5);
            // test-name: left-aligned bold (td.test-name)
            // Available width = rc1 - padding(6pt each side) = 262.96pt
            $this->txt($L+6, $y+5, $this->fitW($test, $rc1-12, 8.25), 8.25, true, '222222');
            // results + requirements: centred
            $this->txtC($L+$rc1,      $y, $rc2, self::MTC_RR, $res, 8.25, false, '111111');
            $this->txtC($L+$rc1+$rc2, $y, $rc3, self::MTC_RR, $req, 8.25, false, '444444');
            $this->cy -= self::MTC_RR;
        }

        /* ── REMARKS (18pt, optional) ─────────────────
           margin-top:8px->6pt, font:11px->8.25pt        */
        if (!empty($d['mtc_remarks'])) {
            $this->cy -= 6;
            $rmk = 'Remarks: '.$this->s($d['mtc_remarks']);
            $this->txt($L, $this->cy, $this->fitW($rmk, $W, 8.25), 8.25, false, '444444');
            $this->cy -= (self::MTC_REM - 6);
        }

        /* ── SIGNATURE SECTION ────────────────────────
           .mtc-sig { display:flex; justify-content:space-between; margin-top:20px }
           .mtc-sig-box { width:45%; text-align:center }

           Structure of each box (top to bottom):
             ┌─────────────────────────┐  ← dashed border (min-height:60px->45pt)
             │                         │
             │                         │
             └─────────────────────────┘
             [6px->4.5pt margin-bottom]
             For TSG Impex India Pvt Ltd    ← font:10px->7.5pt  bold
             (Manager Technical)            ← font:10px->7.5pt

           Total sig section height:
             gap = margin-top:20px -> 15pt
             box area: 45pt (dashed box) + 4.5pt + 7.5pt + 7.5pt = 64.5pt
             Total drawn: 15 + 64.5 = 79.5pt -> 81pt (MTC_SIG_GAP + MTC_BOX + MTC_LBL) */

        $this->cy -= self::MTC_SIG_GAP;  // margin-top:20px -> 15pt gap

        $box_h  = self::MTC_BOX;   // 45pt  (min-height:60px)
        $lbl_h  = 20;              // 4.5pt margin + 7.5pt line1 + 7.5pt line2 = ~20pt
        $box2_x = $L + $bw + $bgap;

        /* Left sig box — Manager Technical signature */
        $this->fillRect($L, $this->cy-$box_h, $bw, $box_h, 'ffffff');
        $this->strokeRect($L, $this->cy-$box_h, $bw, $box_h, '1a5632', 1.5);
        if (!empty($d['mtc_sig_path'])) {
            $mf = pdf_resolve_image($d['mtc_sig_path']);
            if ($mf) $this->embedCompanyImage($mf, 'MtcSig', $L, $this->cy-$box_h, $bw, $box_h, 0);
        }

        /* Right sig box — Company Seal */
        $this->fillRect($box2_x, $this->cy-$box_h, $bw, $box_h, 'ffffff');
        $this->strokeRect($box2_x, $this->cy-$box_h, $bw, $box_h, '1a5632', 1.5);
        if (!empty($d['seal_path'])) {
            $sf = pdf_resolve_image($d['seal_path']);
            if ($sf) $this->embedCompanyImage($sf, 'Seal', $box2_x, $this->cy-$box_h, $bw, $box_h, 0);
        } else {
            $this->txtC($box2_x, $this->cy-$box_h, $bw, $box_h, 'SEAL', 10, false, 'cccccc');
        }

        $this->cy -= $box_h;   // cy now = bottom edge of dashed boxes
        $this->cy -= 5;        // margin-bottom:6px gap below boxes

        // Labels BELOW the dashed boxes — draw at current cy level then step down
        // Line 1: "For Company Name" (left) | "Company Seal" (right)
        $cn = $this->fitW('For '.$this->s($d['company_name']), $bw-6, 7.5);
        $ly1 = $this->cy - 8;   // baseline of first label line
        $this->txt($L  + ($bw - $this->tw($cn, 7.5)) / 2, $ly1, $cn,            7.5, true,  '222222');
        $this->txt($box2_x + ($bw - $this->tw('Company Seal', 7.5)) / 2, $ly1, 'Company Seal', 7.5, false, '555555');
        $this->cy -= 11;       // step down for second label line

        // Line 2: "(Manager Technical)" (left only)
        $ly2 = $this->cy - 8;
        $this->txt($L + ($bw - $this->tw('(Manager Technical)', 7.5)) / 2, $ly2, '(Manager Technical)', 7.5, false, '555555');
        $this->cy -= 11;

        /* ── MTC FOOTER (22pt) ────────────────────────
           margin-top:10px->7.5pt
           border-top:1px solid #ddd
           padding-top:5px->3.75pt
           font:9px->6.75pt  text-align:center  color:#888  */
        $this->cy -= 8;    // margin-top
        $this->hLine($L, $this->cy, $W, '1a5632', 1.0);
        $this->cy -= 4;    // padding-top
        $mfoot = 'This MTC is issued as per IS 3812 requirements | Attached to Delivery Challan: '
                .$this->s($d['challan_no']).' | Original - Consignee Copy';
        $this->txtC($L, $this->cy-8, $W, 10,
            $this->fitW($mfoot, $W-10, 6.75), 6.75, false, '888888');
        $this->cy -= 14;
    }

    /* ══════════════════════════════════════════════════
       MTC TABLE ROW HELPERS
    ══════════════════════════════════════════════════ */

    // 4-column info row: lbl | val | lbl | val
    private function mtcRow4(
        string $l1, string $v1, string $l2, string $v2,
        float $lbl_w, float $val_w, float $rh, bool $shade
    ): void {
        $L  = $this->ML;
        $W  = $this->PW;
        $ry = $this->cy - $rh;
        $ty = $ry + ($rh - 8.25) / 2;   // vertically centre text in row

        // Fills first, borders on top
        if ($shade) $this->fillRect($L, $ry, $W, $rh, 'f9f9f9');
        $this->fillRect($L,              $ry, $lbl_w, $rh, 'fffbea');
        $this->fillRect($L+$lbl_w+$val_w,$ry, $lbl_w, $rh, 'fffbea');

        // Borders on top of fills
        $this->strokeRect($L, $ry, $W, $rh, '1a5632', 1.5);
        $this->vLine($L+$lbl_w,              $ry, $rh, '1a5632', 1.5);
        $this->vLine($L+$lbl_w+$val_w,       $ry, $rh, '1a5632', 1.5);
        $this->vLine($L+$lbl_w+$val_w+$lbl_w,$ry, $rh, '1a5632', 1.5);

        // Text — padding:5px 8px -> text starts 6pt from left edge
        $this->txt($L+6,                     $ty, $l1, 8.25, true,  '333333');
        $this->txt($L+$lbl_w+6,              $ty, $this->fitW($v1, $val_w-12, 8.25), 8.25, false, '111111');
        $this->txt($L+$lbl_w+$val_w+6,       $ty, $l2, 8.25, true,  '333333');
        $this->txt($L+$lbl_w+$val_w+$lbl_w+6,$ty, $this->fitW($v2, $val_w-12, 8.25), 8.25, false, '111111');

        $this->cy -= $rh;
    }

    // 2-column info row: lbl | val colspan=3  (Vendor Name row)
    private function mtcRow2(
        string $l1, string $v1,
        float $lbl_w, float $val_w, float $rh, bool $shade
    ): void {
        $L  = $this->ML;
        $ry = $this->cy - $rh;
        $ty = $ry + ($rh - 8.25) / 2;

        if ($shade) $this->fillRect($L, $ry, $this->PW, $rh, 'f9f9f9');
        $this->fillRect($L, $ry, $lbl_w, $rh, 'fffbea');
        $this->strokeRect($L, $ry, $this->PW, $rh, '1a5632', 1.5);
        $this->vLine($L+$lbl_w, $ry, $rh, '1a5632', 1.5);

        $this->txt($L+6,        $ty, $l1, 8.25, true,  '333333');
        $this->txt($L+$lbl_w+6, $ty, $this->fitW($v1, $val_w-12, 8.25), 8.25, false, '111111');

        $this->cy -= $rh;
    }

    /* ══════════════════════════════════════════════════
       DRAWING PRIMITIVES
    ══════════════════════════════════════════════════ */

    /** Return correct decimal places for a UOM (mirrors config.php uomDecimals()). */
    private function uomDecimals(string $uom): int {
        $three = ['MT','Kg','Gm','Litre','Mtr','Cm'];
        $zero  = ['Nos','Set','Box','Carton','Pair','Dozen'];
        if (in_array($uom, $three)) return 3;
        if (in_array($uom, $zero))  return 0;
        return 2;
    }

    private function fillRect(float $x, float $y, float $w, float $h, string $hex): void
    {
        [$r,$g,$b] = $this->rgb($hex);
        $this->buf .= "q $r $g $b rg $x $y $w $h re f Q\n";
    }

    private function strokeRect(float $x, float $y, float $w, float $h, string $hex, float $lw=0.5): void
    {
        [$r,$g,$b] = $this->rgb($hex);
        $this->buf .= "q $r $g $b RG $lw w $x $y $w $h re S Q\n";
    }

    // Dashed border rectangle (fill must be drawn BEFORE calling this)
    private function dashed(float $x, float $y, float $w, float $h, string $hex): void
    {
        [$r,$g,$b] = $this->rgb($hex);
        $this->buf .= "q $r $g $b RG 0.5 w [3 2] 0 d $x $y $w $h re S Q\n";
    }

    private function vLine(float $x, float $y, float $h, string $hex, float $lw=0.4): void
    {
        [$r,$g,$b] = $this->rgb($hex);
        $this->buf .= "q $r $g $b RG $lw w $x $y m $x ".($y+$h)." l S Q\n";
    }

    private function hLine(float $x, float $y, float $w, string $hex, float $lw=0.4): void
    {
        [$r,$g,$b] = $this->rgb($hex);
        $this->buf .= "q $r $g $b RG $lw w $x $y m ".($x+$w)." $y l S Q\n";
    }

    // Place text at exact (x, y) — y is the baseline
    private function txt(float $x, float $y, string $t, float $sz=8, bool $b=false, string $c='000000'): void
    {
        if (!isset($t[0])) return;
        $t  = $this->esc($t);
        $f  = $b ? 'F2' : 'F1';
        [$r,$g,$b2] = $this->rgb($c);
        $this->buf .= "q $r $g $b2 rg BT /$f $sz Tf $x $y Td ($t) Tj ET Q\n";
    }

    // Text centred inside box (bx,by = bottom-left corner; bw,bh = dimensions)
    private function txtC(float $bx, float $by, float $bw, float $bh,
                          string $t, float $sz, bool $b, string $c): void
    {
        if (!isset($t[0])) return;
        $tx = $bx + ($bw - $this->tw($t,$sz)) / 2;
        $ty = $by + ($bh - $sz) / 2;
        $this->txt($tx, $ty, $t, $sz, $b, $c);
    }

    // Text right-aligned (bx = left edge of available box width bw)
    private function txtR(float $bx, float $by, float $bw,
                          string $t, float $sz, bool $b, string $c): void
    {
        if (!isset($t[0])) return;
        $this->txt($bx + $bw - $this->tw($t,$sz) - 3, $by, $t, $sz, $b, $c);
    }

    // Text in table cell: l=left  c=centre  r=right
    private function txtA(float $cx, float $cy, float $cw, string $t,
                          float $sz, bool $b, string $c, string $a): void
    {
        if (!isset($t[0])) return;
        if     ($a === 'c') $cx += ($cw - $this->tw($t,$sz)) / 2;
        elseif ($a === 'r') $cx += $cw - $this->tw($t,$sz) - 3;
        else                $cx += 3;
        $this->txt($cx, $cy, $t, $sz, $b, $c);
    }

    // Embed a signature image (JPG or PNG) into the PDF signature box.
    // Draws the image centred horizontally in the column, vertically between line_y and box top.
    private function embedSigImage(string $path, float $bx, float $by, float $bw,
                                    float $bh, float $line_y): void
    {
        // Resolve file — try multiple path strategies
        $resolved = null;
        $candidates = [
            $path,                                           // as-is (absolute or relative to cwd)
            dirname(__DIR__) . '/' . $path,                 // despatch_mgmt/ + relative path
            dirname(__DIR__) . '/' . ltrim($path, '/'),     // strip leading slash then prepend root
            $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($path, '/'),  // docroot fallback
        ];
        foreach ($candidates as $c) {
            if ($c && file_exists($c) && filesize($c) > 0) {
                $resolved = $c;
                break;
            }
        }
        if (!$resolved) return;  // file not found — silently skip

        $info = @getimagesize($resolved);
        if (!$info || !$info[0] || !$info[1]) return;
        $iw = $info[0]; $ih = $info[1];

        // Convert ANY image format to JPEG via GD (handles PNG/JPG/GIF/WEBP)
        $gd = @imagecreatefromstring(file_get_contents($resolved));
        if (!$gd) return;

        // For PNG with transparency — fill white background first
        if ($info[2] === IMAGETYPE_PNG) {
            $canvas = imagecreatetruecolor($iw, $ih);
            $white  = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopy($canvas, $gd, 0, 0, 0, 0, $iw, $ih);
            imagedestroy($gd);
            $gd = $canvas;
        }

        ob_start();
        imagejpeg($gd, null, 92);
        $raw = ob_get_clean();
        imagedestroy($gd);
        if (!$raw) return;

        // Image placement: between sig line and box top, with padding
        $area_bot = $line_y + 4;
        $area_top = $by + $bh - 4;
        $area_h   = $area_top - $area_bot;
        $area_w   = $bw - 12;
        if ($area_h <= 2 || $area_w <= 2) return;

        // Scale preserving aspect ratio
        $scale = min($area_w / $iw, $area_h / $ih);
        $dw = round($iw * $scale, 4);
        $dh = round($ih * $scale, 4);
        $dx = round($bx + ($bw - $dw) / 2, 4);
        $dy = round($area_bot + ($area_h - $dh) / 2, 4);

        // Stable XObject name — same image used for all 3 copies
        $xname = 'AuthSig';
        if (!isset($this->sigImages[$xname])) {
            $this->sigImages[$xname] = ['raw' => $raw, 'w' => $iw, 'h' => $ih];
        }

        $this->buf .= "q $dw 0 0 $dh $dx $dy cm /$xname Do Q\n";
    }

    // Embed a company image (seal/signature) centred inside an arbitrary rectangle.
    // $xname = stable XObject key (reused across pages). $line_y = 0 means fill full box.
    private function embedCompanyImage(string $path, string $xname,
                                        float $bx, float $by, float $bw, float $bh,
                                        float $line_y=0): void
    {
        // Resolve path with multiple fallback strategies
        $resolved = null;
        foreach ([$path, dirname(__DIR__).'/'.$path,
                  $_SERVER['DOCUMENT_ROOT'].'/'.ltrim($path,'/')] as $try) {
            if ($try && file_exists($try) && filesize($try) > 0) { $resolved = $try; break; }
        }
        if (!$resolved) return;

        $info = @getimagesize($resolved);
        if (!$info || !$info[0] || !$info[1]) return;
        $iw = $info[0]; $ih = $info[1];

        $gd = @imagecreatefromstring(file_get_contents($resolved));
        if (!$gd) return;
        // Composite PNG transparency onto white
        if ($info[2] === IMAGETYPE_PNG) {
            $canvas = imagecreatetruecolor($iw, $ih);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $gd, 0, 0, 0, 0, $iw, $ih);
            imagedestroy($gd);
            $gd = $canvas;
        }
        ob_start(); imagejpeg($gd, null, 92); $raw = ob_get_clean();
        imagedestroy($gd);
        if (!$raw) return;

        // Placement area — if line_y>0 use area above line, else fill box with 4pt padding
        $pad = 4;
        if ($line_y > 0) {
            $area_bot = $line_y + $pad;
            $area_top = $by + $bh - $pad;
        } else {
            $area_bot = $by + $pad;
            $area_top = $by + $bh - $pad;
        }
        $area_h = $area_top - $area_bot;
        $area_w = $bw - $pad * 2;
        if ($area_h <= 2 || $area_w <= 2) return;

        $scale = min($area_w / $iw, $area_h / $ih);
        $dw = round($iw * $scale, 4);
        $dh = round($ih * $scale, 4);
        $dx = round($bx + ($bw - $dw) / 2, 4);
        $dy = round($area_bot + ($area_h - $dh) / 2, 4);

        if (!isset($this->sigImages[$xname])) {
            $this->sigImages[$xname] = ['raw' => $raw, 'w' => $iw, 'h' => $ih];
        }
        $this->buf .= "q $dw 0 0 $dh $dx $dy cm /$xname Do Q\n";
    }

    // Draw text inside a column with a hard PDF clipping path.
    // NOTHING drawn here can bleed into adjacent columns — the PDF viewer enforces this.
    // $col_x/$col_y = bottom-left of column rectangle, $col_w/$col_h = its dimensions.
    // $text_y = baseline Y for the text line. $pad = left padding inside column.
    private function txtInCol(float $col_x, float $col_y, float $col_w,
                               float $text_y, string $t, float $sz,
                               bool $b, string $c, float $pad=5): void
    {
        if (!isset($t[0])) return;
        $te = $this->esc($t);
        $f  = $b ? 'F2' : 'F1';
        [$r,$g,$b2] = $this->rgb($c);
        $tx = $col_x + $pad;
        // PDF clipping: q [rect] re W n [text] Q
        // The 're W n' sets the clip region; everything drawn until Q is clipped to it.
        $this->buf .= "q {$col_x} {$col_y} {$col_w} " . self::CH_ADDR
                    . " re W n {$r} {$g} {$b2} rg BT /{$f} {$sz} Tf {$tx} {$text_y} Td ({$te}) Tj ET Q\n";
    }

    /* ══════════════════════════════════════════════════
       PDF ASSEMBLY
    ══════════════════════════════════════════════════ */
    private function startPage(): void { $this->buf = ''; }

    private function commitPage(): void
    {
        $sid = $this->nextId++;
        $pid = $this->nextId++;
        $this->objs[$sid]     = $this->buf;
        $this->objTypes[$sid] = 'stream';
        $this->objs[$pid]     = $sid;
        $this->objTypes[$pid] = 'page';
        $this->pageIds[]      = $pid;
    }

    private function renderPDF(): string
    {
        // Assign all PDF object IDs cleanly here — NOT during draw phase.
        // Sequential: F1, F2, [image XObjects], [stream+page pairs], Pages, Catalog
        $id  = 0;
        $off = [];
        $out = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";

        $f1   = ++$id;
        $f2   = ++$id;

        // Image XObjects — one per unique signature (keyed by stable xname)
        $imgObjId = [];
        foreach (array_keys($this->sigImages) as $xname) {
            $imgObjId[$xname] = ++$id;
        }

        // Page stream + page object pairs
        $pagePairs = [];
        foreach ($this->pageIds as $oldPid) {
            $pagePairs[] = [++$id, ++$id, $oldPid];  // [streamId, pageId, oldPid]
        }

        $pgId = ++$id;
        $caId = ++$id;

        // ── Fonts ──
        $off[$f1] = strlen($out);
        $out .= "$f1 0 obj\n<</Type /Font /Subtype /Type1"
               ." /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>\nendobj\n";
        $off[$f2] = strlen($out);
        $out .= "$f2 0 obj\n<</Type /Font /Subtype /Type1"
               ." /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding>>\nendobj\n";

        // ── Image XObjects ──
        $xobjRes = '';
        foreach ($this->sigImages as $xname => $img) {
            $oid = $imgObjId[$xname];
            $raw = $img['raw'];
            $len = strlen($raw);
            $off[$oid] = strlen($out);
            $out .= "$oid 0 obj\n"
                   ."<</Type /XObject /Subtype /Image"
                   ." /Width {$img['w']} /Height {$img['h']}"
                   ." /ColorSpace /DeviceRGB /BitsPerComponent 8"
                   ." /Filter /DCTDecode /Length $len>>\n"
                   ."stream\n".$raw."\nendstream\nendobj\n";
        }
        if (!empty($imgObjId)) {
            $pairs = '';
            foreach ($imgObjId as $xname => $oid) { $pairs .= "/$xname $oid 0 R "; }
            $xobjRes = "/XObject <<$pairs>>";
        }

        // ── Pages ──
        $newPageIds = [];
        foreach ($pagePairs as [$streamId, $pageId, $oldPid]) {
            $oldSid = $this->objs[$oldPid];   // maps oldPid → old stream id
            $sc     = $this->objs[$oldSid];   // actual stream content string
            $sl     = strlen($sc);
            $off[$streamId] = strlen($out);
            $out .= "$streamId 0 obj\n<</Length $sl>>\nstream\n$sc\nendstream\nendobj\n";
            $off[$pageId] = strlen($out);
            $out .= "$pageId 0 obj\n<</Type /Page /Parent $pgId 0 R"
                   ."\n/MediaBox [0 0 {$this->W} {$this->H}]"
                   ."\n/Resources <</Font <</F1 $f1 0 R /F2 $f2 0 R>> $xobjRes>>"
                   ."\n/Contents $streamId 0 R>>\nendobj\n";
            $newPageIds[] = $pageId;
        }

        // ── Pages dictionary ──
        $kids = implode(' 0 R ', $newPageIds).' 0 R';
        $off[$pgId] = strlen($out);
        $out .= "$pgId 0 obj\n<</Type /Pages /Count ".count($newPageIds)
               ." /Kids [$kids]>>\nendobj\n";

        // ── Catalog ──
        $off[$caId] = strlen($out);
        $out .= "$caId 0 obj\n<</Type /Catalog /Pages $pgId 0 R>>\nendobj\n";

        // ── Cross-reference table ──
        $xp = strlen($out);
        $out .= "xref\n0 ".($caId + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $caId; $i++) {
            $out .= isset($off[$i])
                  ? sprintf("%010d 00000 n \n", $off[$i])
                  : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<</Size ".($caId + 1)." /Root $caId 0 R>>\nstartxref\n$xp\n%%EOF\n";
        return $out;
    }


    /* ══════════════════════════════════════════════════
       FUEL PAYMENT ADVICE
       $p  = payment row  (fleet_fuel_payments)
       $fc = fuel company row (fleet_fuel_companies)
       $co = company_settings row
       $u  = users row (logged-in user: name + signature)
    ══════════════════════════════════════════════════ */
    public function buildFuelPaymentAdvice(array $p, array $fc, array $co, array $u): string
    {
        $this->PW = $this->W - $this->ML - $this->MR;
        $this->startPage();
        $this->drawFuelPaymentAdvice($p, $fc, $co, $u);
        $this->commitPage();
        return $this->renderPDF();
    }

    private function drawFuelPaymentAdvice(array $p, array $fc, array $co, array $u): void
    {
        $L  = $this->ML;
        $W  = $this->PW;
        $this->cy = $this->H - $this->MT;

        /* ── 1. HEADER BAND (72pt) ─────────────────────────
           Logo left | Company details | Doc ref right        */
        $HDR = 72;
        $y   = $this->cy - $HDR;
        $this->fillRect($L, $y, $W, $HDR, '2d2d2d');

        $logo   = pdf_default_logo_path();
        $text_x = $L + 7;
        if ($logo) {
            $this->embedCompanyImage($logo, 'FPALogo', $L + 6, $y + 8, 56, 56, 0);
            $text_x = $L + 68;
        }

        $coName = trim($co['company_name'] ?? '');
        $this->txt($text_x, $y + 52, $this->fitW($coName, $W - 200, 14), 14, true, 'ffffff');

        $coAdr = preg_replace('/\s+/', ' ', trim(
            ($co['address'] ?? '').' '.($co['city'] ?? '').
            ($co['state']   ? ', '.($co['state']  ) : '').
            ($co['pincode'] ? ' - '.($co['pincode']) : '')));
        if ($coAdr) $this->txt($text_x, $y + 38, $this->fitW($coAdr, $W - 200, 8), 8, false, 'cccccc');

        $coContact = trim(($co['phone'] ? 'Ph: '.$co['phone'].'   ' : '').($co['email'] ?? ''));
        if ($coContact) $this->txt($text_x, $y + 27, $this->fitW($coContact, $W - 200, 8), 8, false, 'cccccc');

        $coGst = trim(($co['gstin'] ? 'GSTIN: '.$co['gstin'] : '').
                      ($co['pan']   ? '   PAN: '.$co['pan']  : ''));
        if ($coGst) $this->txt($text_x, $y + 16, $this->fitW($coGst, $W - 200, 8), 8, false, 'cccccc');

        $rx = $L + $W - 160;
        $this->txt($rx, $y + 54, 'PAYMENT ADVICE', 13, true,  'ffffff');
        $this->txt($rx, $y + 40, 'Ref No: FPA-'.$p['id'],    8.5, false, 'ffffff');
        $this->txt($rx, $y + 29, 'Date: '.date('d/m/Y', strtotime($p['payment_date'])), 8.5, false, 'ffffff');
        $this->txt($rx, $y + 18, 'Mode: '.($p['payment_mode'] ?? 'NEFT'), 8.5, false, 'ffffff');
        $this->cy -= $HDR;

        /* ── 2. "TO" BAND — Fuel Company (36pt) ────────────*/
        $TO = 36;
        $y  = $this->cy - $TO;
        $this->fillRect($L, $y, $W, $TO, 'f2f2f2');
        $this->strokeRect($L, $y, $W, $TO, '666666', 1.5);
        $this->txt($L + 6, $y + $TO - 11, 'TO', 7, true, '666666');
        $this->txt($L + 6, $y + $TO - 23, $this->fitW($this->s($fc['company_name'] ?? ''), $W / 2, 11), 11, true, '1a1a1a');
        $fcContact = trim(($fc['contact_person'] ?? '').
                          ($fc['phone'] ? '   Ph: '.($fc['phone']) : '').
                          ($fc['email'] ? '   '.($fc['email'])     : ''));
        if ($fcContact) $this->txt($L + $W / 2, $y + $TO - 13, $this->fitW($fcContact, $W / 2 - 6, 8), 8, false, '555555');
        if (!empty($fc['gstin'])) $this->txt($L + $W / 2, $y + $TO - 24, 'GSTIN: '.$fc['gstin'], 8, false, '555555');
        $this->cy -= $TO;

        /* ── 3. PAYMENT DETAILS TABLE ───────────────────────*/
        $ROW = 20;
        $lw  = $W * 0.38;
        $vw  = $W - $lw;
        $rem = trim($p['remarks'] ?? '');
        $rows = [
            ['Payment Date',    date('d/m/Y', strtotime($p['payment_date']))],
            ['Amount',          'Rs. '.number_format((float)$p['amount'], 2)],
            ['Payment Mode',    $p['payment_mode'] ?? 'NEFT'],
            ['Reference / UTR', trim($p['reference_no'] ?? '') ?: '—'],
        ];
        foreach ($rows as $ri => [$lbl, $val]) {
            $y = $this->cy - $ROW;
            $this->fillRect($L,       $y, $lw, $ROW, 'e8e8e8');
            $this->fillRect($L + $lw, $y, $vw, $ROW, $ri % 2 === 0 ? 'f7f7f7' : 'ffffff');
            $this->strokeRect($L, $y, $W, $ROW, '666666', 0.8);
            $this->vLine($L + $lw, $y, $ROW, '666666', 0.8);
            $this->txt($L + 6,       $y + 6, $lbl, 9, true,  '2d2d2d');
            $this->txt($L + $lw + 6, $y + 6, $this->fitW($val, $vw - 12, 9), 9, false, '111111');
            $this->cy -= $ROW;
        }
        if ($rem) {
            $remLines = $this->wrap($rem, $vw - 12, 8.5);
            $remH     = max($ROW, count($remLines) * 13 + 10);
            $y = $this->cy - $remH;
            $this->fillRect($L,       $y, $lw, $remH, 'e8e8e8');
            $this->fillRect($L + $lw, $y, $vw, $remH, 'fffdf0');
            $this->strokeRect($L, $y, $W, $remH, '666666', 0.8);
            $this->vLine($L + $lw, $y, $remH, '666666', 0.8);
            $this->txt($L + 6, $y + $remH - 12, 'Remarks', 9, true, '2d2d2d');
            $ly = $y + $remH - 12;
            foreach ($remLines as $rl) { $this->txt($L + $lw + 6, $ly, $rl, 8.5, false, '333333'); $ly -= 13; }
            $this->cy -= $remH;
        }
        $this->cy -= 10;

        /* ── 4. AMOUNT HIGHLIGHT BOX (34pt) ────────────────*/
        $AMT = 44;
        $y   = $this->cy - $AMT;
        $this->fillRect($L, $y, $W, $AMT, '2d2d2d');
        $this->strokeRect($L, $y, $W, $AMT, '666666', 1.5);
        $amtWords  = $this->n2w((int)round((float)$p['amount'])).' Only';
        $amtStr    = 'Rs. '.number_format((float)$p['amount'], 2);
        $amtStrW   = $this->tw($amtStr, 14) + 14;  // width occupied by big amount on right
        $wordsMaxW = $W - $amtStrW - 16;            // remaining width for words
        $wordLines = $this->wrap($amtWords, $wordsMaxW, 9);
        $this->txt($L + 8, $y + $AMT - 10, 'Amount in Words:', 8, true, 'cccccc');
        $wy = $y + $AMT - 22;
        foreach ($wordLines as $wl) {
            $this->txt($L + 8, $wy, $wl, 9, true, 'ffffff');
            $wy -= 12;
        }
        $this->txt($L + $W - $amtStrW + 2, $y + ($AMT / 2) - 8, $amtStr, 14, true, 'ffffff');
        $this->cy -= $AMT + 12;

        /* ── 5. OUR BANK DETAILS ────────────────────────────*/
        $bk = trim(($co['bank_name']  ? 'Bank: '.$co['bank_name'].'   '  : '').
                   ($co['account_no'] ? 'A/c: ' .$co['account_no'].'   '  : '').
                   ($co['ifsc_code']  ? 'IFSC: '.$co['ifsc_code']         : ''));
        if ($bk) {
            $BK = 18;
            $y  = $this->cy - $BK;
            $this->fillRect($L, $y, $W, $BK, 'f9f9f9');
            $this->strokeRect($L, $y, $W, $BK, 'cccccc', 0.5);
            $this->txt($L + 6, $y + 5, $this->fitW($bk, $W - 12, 8), 8, false, '555555');
            $this->cy -= $BK + 8;
        }

        /* ── 6. SIGNATURE SECTION (70pt) ───────────────────
           Left:  Received By blank
           Right: User name + signature image + Authorised Signatory */
        $SIG  = 70;
        $sigW = $W / 2;
        $y    = $this->cy - $SIG;
        $this->strokeRect($L, $y, $W, $SIG, '666666', 1.0);
        $this->vLine($L + $sigW, $y, $SIG, '666666', 0.5);

        // Left — Received By
        $this->txt($L + 6, $y + $SIG - 12, 'Acknowledged / Received By:', 8, true, '555555');
        $this->hLine($L + 6, $y + 18, $sigW - 12, '999999', 0.5);
        $this->txt($L + 6, $y + 10, 'Signature & Stamp', 7, false, 'aaaaaa');

        // Right — Authorised Signatory
        $sigX    = $L + $sigW;
        $sigPath = trim($u['signature_path'] ?? ($u['sig_path'] ?? ($u['signature'] ?? '')));
        if ($sigPath) {
            $sf = pdf_resolve_image($sigPath);
            if ($sf) $this->embedCompanyImage($sf, 'UserSig', $sigX + 4, $y + 26, $sigW - 8, $SIG - 30, 0);
        }
        $this->hLine($sigX + 6, $y + 22, $sigW - 12, '555555', 0.7);

        $userName = trim($u['full_name'] ?? ($u['name'] ?? ($u['username'] ?? '')));
        if ($userName)
            $this->txtC($sigX, $y + 12, $sigW, 10, $this->fitW($userName, $sigW - 10, 8), 8, true,  '1a1a1a');
        $this->txtC($sigX, $y + 3, $sigW, 8,  'Authorised Signatory', 7.5, false, '555555');
        $this->txtC($sigX, $y - 6, $sigW, 7.5,
            'For '.$this->fitW($this->s($co['company_name'] ?? ''), $sigW - 12, 7.5), 7.5, false, '666666');
        $this->cy -= $SIG;

        /* ── 7. FOOTER (18pt) ───────────────────────────────*/
        $FT = 18;
        $y  = $this->cy - $FT - 6;
        $this->fillRect($L, $y, $W, $FT, 'f2f2f2');
        $this->strokeRect($L, $y, $W, $FT, '666666', 0.5);
        $foot = 'This is a computer-generated Payment Advice  |  '
               .$this->s($co['company_name'] ?? '')
               .'  |  Generated: '.date('d/m/Y H:i');
        $this->txtC($L, $y, $W, $FT, $this->fitW($foot, $W - 12, 7), 7, false, '666666');
    }

    /* ══════════════════════════════════════════════════
       UTILITIES
    ══════════════════════════════════════════════════ */
    private function rgb(string $h): array
    {
        $h = ltrim($h, '#');
        return [
            round(hexdec(substr($h,0,2))/255, 4),
            round(hexdec(substr($h,2,2))/255, 4),
            round(hexdec(substr($h,4,2))/255, 4),
        ];
    }

    private function esc(string $s): string
    {
        $s = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)($s ?? ''));
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
    }

    private function s($v): string
    {
        $t = trim((string)($v ?? ''));
        return $t !== '' ? $t : '-';
    }

    // Clean address text: strip ALL backslashes, literal \n, normalize whitespace
    private function cleanAddr($v): string
    {
        $t = (string)($v ?? '');
        // Remove ALL backslash characters (never valid in addresses)
        $t = str_replace('\\', '', $t);
        // Remove literal \n text
        $t = str_replace(['\r\n', '\n', '\r'], ' ', $t);
        // Remove actual newlines/carriage returns
        $t = str_replace(["\r\n", "\n", "\r"], ' ', $t);
        // Collapse multiple spaces to single space
        $t = preg_replace('/\s+/', ' ', $t);
        $t = trim($t);
        return $t !== '' ? $t : '-';
    }

    // Approximate rendered width in pts (Helvetica avg char width = 0.52 × font-size)
    private function tw(string $t, float $sz): float
    {
        // Helvetica average char width factor: 0.56 (conservative, prevents overflow)
        return strlen($t) * $sz * 0.56;
    }

    // Fit string into maxPt width at given size — trims without marker if too wide
    private function fitW(string $s, float $maxPt, float $sz): string
    {
        if ($this->tw($s, $sz) <= $maxPt) return $s;
        $lo = 0; $hi = mb_strlen($s);
        while ($lo < $hi - 1) {
            $mid = (int)(($lo + $hi) / 2);
            if ($this->tw(mb_substr($s, 0, $mid), $sz) <= $maxPt) $lo = $mid;
            else $hi = $mid;
        }
        return mb_substr($s, 0, $lo);
    }

    // Word-wrap text into lines fitting maxW pts at font size sz
    private function wrap(string $s, float $maxW, float $sz): array
    {
        $words = explode(' ', $s);
        $lines = [];
        $cur   = '';
        foreach ($words as $w) {
            $test = trim("$cur $w");
            if ($this->tw($test, $sz) <= $maxW) {
                $cur = $test;
            } else {
                if ($cur !== '') $lines[] = $cur;
                $cur = $w;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines ?: [$s];
    }

    // Number to Indian words
    private function n2w(int $n): string
    {
        if (!$n) return 'Zero Rupees';
        $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
                 'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                 'Seventeen','Eighteen','Nineteen'];
        $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
        $f = function(int $x) use (&$f, $ones, $tens): string {
            if ($x < 20) return $ones[$x];
            if ($x < 100) return $tens[(int)($x/10)].($x%10 ? ' '.$ones[$x%10] : '');
            return $ones[(int)($x/100)].' Hundred'.($x%100 ? ' And '.$f($x%100) : '');
        };
        $r = '';
        if ($n >= 10000000) { $r .= $f((int)($n/10000000)).' Crore ';  $n %= 10000000; }
        if ($n >= 100000)   { $r .= $f((int)($n/100000)).' Lakh ';     $n %= 100000;   }
        if ($n >= 1000)     { $r .= $f((int)($n/1000)).' Thousand ';   $n %= 1000;     }
        if ($n > 0)         { $r .= $f($n); }
        return trim($r).' Rupees';
    }
}
