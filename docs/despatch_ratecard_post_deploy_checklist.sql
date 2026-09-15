-- Despatch Rate-Card Post-Deploy Checklist
-- Purpose:
-- 1) verify despatch/vendor/transporter/rate_card consistency
-- 2) detect missing transporter_rate_per_mt where active rate exists
-- 3) provide safe snapshot + rollback helpers for non-delivered rows
--
-- Recommended order:
-- A -> B -> C -> D -> E
--
-- Status scope used for operational rows:
-- status NOT IN ('Delivered','Cancelled','Draft')

/* =========================================================
   A) Mismatch check: rate_card_id does not match current pair
   Expectation after fix: 0 rows for non-delivered operational rows
   ========================================================= */
SELECT
  d.id,
  d.challan_no,
  d.status,
  d.vendor_id,
  d.transporter_id,
  d.rate_card_id,
  tr.id              AS linked_rate_id,
  tr.vendor_id       AS linked_vendor_id,
  tr.transporter_id  AS linked_transporter_id,
  tr.status          AS linked_rate_status
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
   B) Missing rate snapshot check
   Expectation after fix: 0 rows for non-delivered operational rows
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
   C) Snapshot for rollback safety (run before any bulk correction)
   ========================================================= */
-- Change suffix as needed for date/time tracking
CREATE TABLE IF NOT EXISTS despatch_orders_fix_snapshot_20260513 AS
SELECT d.*
FROM despatch_orders d
WHERE d.status NOT IN ('Delivered','Cancelled','Draft');

/* =========================================================
   D) Bulk correction (non-delivered only)
   1) align rate_card_id with active vendor+transporter pair
   2) fill transporter_rate_per_mt from linked rate card
   ========================================================= */
UPDATE despatch_orders d
JOIN (
  SELECT transporter_id, vendor_id, MAX(id) AS active_rate_id
  FROM transporter_rates
  WHERE status='Active'
  GROUP BY transporter_id, vendor_id
) ar
  ON ar.transporter_id = d.transporter_id
 AND ar.vendor_id = d.vendor_id
SET d.rate_card_id = ar.active_rate_id
WHERE d.status NOT IN ('Delivered','Cancelled','Draft')
  AND COALESCE(d.rate_card_id,0) <> ar.active_rate_id;

UPDATE despatch_orders d
JOIN transporter_rates tr ON tr.id = d.rate_card_id
SET d.transporter_rate_per_mt = tr.rate
WHERE d.status NOT IN ('Delivered','Cancelled','Draft')
  AND (d.transporter_rate_per_mt IS NULL OR d.transporter_rate_per_mt = 0);

/* Optional cleanup: if no active rate exists for pair, clear stale rate_card_id */
UPDATE despatch_orders d
LEFT JOIN (
  SELECT transporter_id, vendor_id, MAX(id) AS active_rate_id
  FROM transporter_rates
  WHERE status='Active'
  GROUP BY transporter_id, vendor_id
) ar
  ON ar.transporter_id = d.transporter_id
 AND ar.vendor_id = d.vendor_id
SET d.rate_card_id = NULL
WHERE d.status NOT IN ('Delivered','Cancelled','Draft')
  AND ar.active_rate_id IS NULL;

/* =========================================================
   E) Rollback (only if needed)
   ========================================================= */
-- Ensure snapshot table name matches what you created in step C.
-- This rollback restores only rate_card_id and transporter_rate_per_mt.
-- UPDATE despatch_orders d
-- JOIN despatch_orders_fix_snapshot_20260513 s ON s.id = d.id
-- SET d.rate_card_id = s.rate_card_id,
--     d.transporter_rate_per_mt = s.transporter_rate_per_mt
-- WHERE d.status NOT IN ('Delivered','Cancelled','Draft');

