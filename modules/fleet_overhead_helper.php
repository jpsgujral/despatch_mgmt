<?php
/**
 * fleet_overhead_helper.php
 * Provides fleetTripOverheadAmount() used by fleet_trips.php (P&L view)
 *
 * Statutory Vehicle Overhead = a configurable rate per MT applied to each trip.
 * Rate is stored in fleet_overhead_rates table (date-effective, so rate can change over time).
 * If no table/rate exists, returns zero — safe fallback, no fatal errors.
 */

/* ── Auto-create rates table if it doesn't exist ── */
function fleetOverheadEnsureTable(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS fleet_overhead_rates (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        effective_from DATE NOT NULL,
        rate_per_mt DECIMAL(10,2) NOT NULL DEFAULT 0,
        description VARCHAR(200) DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_effective_from (effective_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Get overhead amount for a trip.
 *
 * @param mysqli $db
 * @param string $trip_date   Date of the trip (Y-m-d)
 * @param float  $weight_mt   Weight in metric tonnes
 * @return array [
 *     'amount'      => float,   // total overhead amount
 *     'rate_per_mt' => float,   // rate used
 *     'weight_mt'   => float,   // weight used
 * ]
 */
function fleetTripOverheadAmount(mysqli $db, string $trip_date, float $weight_mt): array {
    $zero = ['amount' => 0.0, 'rate_per_mt' => 0.0, 'weight_mt' => $weight_mt];

    // Ensure table exists
    fleetOverheadEnsureTable($db);

    if ($weight_mt <= 0) return $zero;

    // Get the most recent rate effective on or before trip_date
    $date = $db->real_escape_string($trip_date);
    $res  = $db->query("
        SELECT rate_per_mt
        FROM fleet_overhead_rates
        WHERE effective_from <= '$date'
        ORDER BY effective_from DESC
        LIMIT 1
    ");

    if (!$res) return $zero;
    $row = $res->fetch_assoc();
    if (!$row) return $zero;

    $rate   = (float)$row['rate_per_mt'];
    $amount = round($rate * $weight_mt, 2);

    return [
        'amount'      => $amount,
        'rate_per_mt' => $rate,
        'weight_mt'   => $weight_mt,
    ];
}
