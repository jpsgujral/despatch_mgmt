-- Despatch Rate-Card Health Check (Read-Only)
-- Safe for daily/weekly operations checks.
-- No UPDATE/DELETE/INSERT statements in this file.

/* =========================================================
   1) Active mismatch check (non-delivered operational rows)
   Expectation: 0 rows
   ========================================================= */
SELECT
  d.id,
  d.challan_no,
  d.status,
  d.vendor_id,
  d.transporter_id,
  d.rate_card_id,
  tr.id             AS linked_rate_id,
  tr.vendor_id      AS linked_vendor_id,
  tr.transporter_id AS linked_transporter_id
FROM despatch_orders d
LEFT JOIN transporter_rates tr ON tr.id = d.rate_card_id
WHERE d.status NOT IN ('Delivered','Cancelled','Draft')
  AND (
    d.rate_card_id IS NULL
    OR tr.id IS NULL
    OR tr.vendor_id <> d.vendor_id
    OR tr.transporter_id <> d.transporter_id
  )
ORDER BY d.id DESC;

/* =========================================================
   2) Missing transporter rate snapshot check
   Expectation: 0 rows
   ========================================================= */
SELECT
  d.id,
  d.challan_no,
  d.status,
  d.vendor_id,
  d.transporter_id,
  d.transporter_rate_per_mt,
  ar.id   AS active_rate_id,
  ar.rate AS active_rate
FROM despatch_orders d
JOIN (
  SELECT tr1.*
  FROM transporter_rates tr1
  JOIN (
    SELECT transporter_id, vendor_id, MAX(id) AS max_id
    FROM transporter_rates
    WHERE status='Active'
    GROUP BY transporter_id, vendor_id
  ) x ON x.max_id = tr1.id
) ar
  ON ar.transporter_id = d.transporter_id
 AND ar.vendor_id = d.vendor_id
WHERE d.status NOT IN ('Delivered','Cancelled','Draft')
  AND (d.transporter_rate_per_mt IS NULL OR d.transporter_rate_per_mt = 0)
ORDER BY d.id DESC;

/* =========================================================
   3) Delivered rows where linked card differs from current active card
   Note: This is informational. Historical delivered records may differ by design.
   ========================================================= */
SELECT
  d.id,
  d.challan_no,
  d.status,
  d.rate_card_id AS delivered_rate_card_id,
  ar.id          AS current_active_rate_card_id,
  d.transporter_id,
  d.vendor_id
FROM despatch_orders d
JOIN (
  SELECT transporter_id, vendor_id, MAX(id) AS id
  FROM transporter_rates
  WHERE status='Active'
  GROUP BY transporter_id, vendor_id
) ar
  ON ar.transporter_id = d.transporter_id
 AND ar.vendor_id = d.vendor_id
WHERE d.status = 'Delivered'
  AND COALESCE(d.rate_card_id,0) <> ar.id
ORDER BY d.id DESC;

