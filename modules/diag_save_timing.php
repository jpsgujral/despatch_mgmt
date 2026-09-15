<?php
/* ── DMS Save Timing Diagnostic ── upload to modules/, run once, then delete ── */
require_once '../includes/config.php';
require_once '../includes/auth.php';

$db = getDB();

header('Content-Type: text/plain');
$log = [];
$t0 = microtime(true);

function elapsed($t0) { return round((microtime(true) - $t0) * 1000) . 'ms'; }

// 1. Simple DB ping
$t = microtime(true);
$db->query("SELECT 1");
$log[] = "[" . elapsed($t0) . "] DB ping: " . round((microtime(true)-$t)*1000) . "ms";

// 2. safeAddColumn (what runs in bootstrap) — simulate one ALTER TABLE
$t = microtime(true);
$db->query("ALTER TABLE despatch_orders ADD COLUMN IF NOT EXISTS _diag_dummy_ TINYINT DEFAULT 0");
$log[] = "[" . elapsed($t0) . "] ALTER TABLE (add column): " . round((microtime(true)-$t)*1000) . "ms";

// 3. Clean it up
$db->query("ALTER TABLE despatch_orders DROP COLUMN IF EXISTS _diag_dummy_");
$log[] = "[" . elapsed($t0) . "] ALTER TABLE (drop column): " . elapsed($t0);

// 4. How many columns in despatch_orders?
$t = microtime(true);
$r = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='despatch_orders'");
$cols = $r->fetch_assoc()['c'];
$log[] = "[" . elapsed($t0) . "] despatch_orders has $cols columns. SHOW COLUMNS: " . round((microtime(true)-$t)*1000) . "ms";

// 5. INSERT into despatch_orders (simulate real save)
$t = microtime(true);
$db->query("INSERT INTO despatch_orders (despatch_no, challan_no, despatch_date, status, created_by, company_id)
            VALUES ('DIAG/TEST/9999','DIAG/TEST/9999','2025-01-01','Dispatched',1,1)");
$diag_id = $db->insert_id;
$log[] = "[" . elapsed($t0) . "] INSERT despatch_orders: " . round((microtime(true)-$t)*1000) . "ms";

// 6. INSERT despatch_items
$t = microtime(true);
$db->query("INSERT INTO despatch_items (despatch_id, item_id, description, unit_price, gst_rate, gst_amount, total_price, weight) VALUES ($diag_id, 1, 'test', 0, 0, 0, 0, 0)");
$log[] = "[" . elapsed($t0) . "] INSERT despatch_items: " . round((microtime(true)-$t)*1000) . "ms";

// 7. UPDATE despatch_orders (rate card link)
$t = microtime(true);
$db->query("UPDATE despatch_orders SET rate_card_id=NULL WHERE id=$diag_id");
$log[] = "[" . elapsed($t0) . "] UPDATE despatch_orders (rate_card): " . round((microtime(true)-$t)*1000) . "ms";

// 8. UPDATE doc_mtc
$t = microtime(true);
$db->query("UPDATE despatch_orders SET doc_mtc='' WHERE id=$diag_id");
$log[] = "[" . elapsed($t0) . "] UPDATE despatch_orders (doc_mtc): " . round((microtime(true)-$t)*1000) . "ms";

// 9. DELETE agent_commissions
$t = microtime(true);
$db->query("DELETE FROM agent_commissions WHERE despatch_id=$diag_id AND status='Pending'");
$log[] = "[" . elapsed($t0) . "] DELETE agent_commissions: " . round((microtime(true)-$t)*1000) . "ms";

// 10. Vendor lookup (like validation does)
$t = microtime(true);
$db->query("SELECT vendor_name, ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_gstin FROM vendors WHERE id=1 LIMIT 1");
$log[] = "[" . elapsed($t0) . "] SELECT vendors: " . round((microtime(true)-$t)*1000) . "ms";

// 11. Sequence operations
$t = microtime(true);
$db->query("SELECT last_val FROM doc_sequences WHERE seq_key='challan_fy2526'");
$log[] = "[" . elapsed($t0) . "] SELECT doc_sequences: " . round((microtime(true)-$t)*1000) . "ms";

$t = microtime(true);
$fy = '2526';
$prefix = "DC/{$fy}/";
$db->query("SELECT MAX(CAST(SUBSTRING_INDEX(challan_no,'/',-1) AS UNSIGNED)) AS mx FROM despatch_orders WHERE challan_no LIKE '" . $db->real_escape_string($prefix) . "%'");
$log[] = "[" . elapsed($t0) . "] MAX(challan_no) sequence sync scan: " . round((microtime(true)-$t)*1000) . "ms";

// 12. curl to R2 worker (no file — just connection test)
$t = microtime(true);
$ch = curl_init('https://dms-r2-upload.jpsgujral.workers.dev/ping');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_CONNECTTIMEOUT  => 10,
    CURLOPT_TIMEOUT         => 15,
    CURLOPT_SSL_VERIFYPEER  => false,
    CURLOPT_CUSTOMREQUEST   => 'GET',
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);
$log[] = "[" . elapsed($t0) . "] curl R2 worker (GET /ping): HTTP $code " . round((microtime(true)-$t)*1000) . "ms" . ($cerr ? " ERR: $cerr" : "");

// 13. Count rows in despatch_orders (affects MAX scan)
$t = microtime(true);
$cnt = $db->query("SELECT COUNT(*) AS c FROM despatch_orders")->fetch_assoc()['c'];
$log[] = "[" . elapsed($t0) . "] despatch_orders row count: $cnt rows";

// Cleanup
$db->query("DELETE FROM despatch_items WHERE despatch_id=$diag_id");
$db->query("DELETE FROM despatch_orders WHERE id=$diag_id");

$log[] = "";
$log[] = "TOTAL elapsed: " . elapsed($t0);
$log[] = "PHP version: " . PHP_VERSION;
$log[] = "MySQL version: " . $db->server_info;

echo implode("\n", $log);
