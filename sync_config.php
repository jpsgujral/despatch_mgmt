<?php
// ============================================================
//  TSGImpex Sync Configuration — despatch_mgmt
//  !! TWO VERSIONS OF THIS FILE ARE NEEDED !!
//  Read the comments carefully before saving.
// ============================================================

define('SYNC_VERSION', '2.0.0');

// ── SHARED SECRET — MUST be identical on both servers ────────
define('SYNC_SECRET', 'TSG@2026#SecretKey!XamppLive');

// ════════════════════════════════════════════════════════════
//  SECTION A: CHANGE THIS depending on which server you are on
// ════════════════════════════════════════════════════════════

// On LOCAL XAMPP  → 'local'
// On LIVE SERVER  → 'live'
define('THIS_SITE', 'live');

// ── LOCAL XAMPP database credentials ─────────────────────────
// (Change to your live DB credentials on the live server copy)
define('DB_HOST', 'localhost');
define('DB_NAME', 'tsgimpex_despatch_mgmt');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── REMOTE URL ────────────────────────────────────────────────
// On LOCAL XAMPP  → point to live server:
// define('REMOTE_SYNC_URL', 'https://tsgimpex.com/despatch_mgmt/sync/sync_api.php');
define('REMOTE_SYNC_URL', 'http://192.168.1.10/despatch_mgmt/sync/sync_api.php');
// On LIVE SERVER  → point to local XAMPP (replace with your office IP):
// define('REMOTE_SYNC_URL', 'http://YOUR_OFFICE_IP/despatch_mgmt/sync/sync_api.php');

// ════════════════════════════════════════════════════════════
//  SECTION B: SAME on both servers — your actual table list
// ════════════════════════════════════════════════════════════
define('SYNC_TABLES', [
    // Core despatch tables
    'despatch_orders'                      => 'id',
    'despatch_items'                       => 'id',
    'despatch_admin_edit_log'              => 'id',
    'despatch_email_log'                   => 'id',

    // Sales
    'sales_invoices'                       => 'id',
    'sales_invoice_items'                  => 'id',
    'sales_invoice_payments'               => 'id',
    'sales_vendor_receipts'                => 'id',
    'sales_vendor_receipt_allocations'     => 'id',

    // Purchase
    'purchase_orders'                      => 'id',
    'po_items'                             => 'id',

    // Quotations
    'quotations'                           => 'id',
    'quotation_items'                      => 'id',
    'quotation_sequence'                   => 'id',

    // Items / Products
    'items'                                => 'id',
    'item_offer_letters'                   => 'id',

    // Fleet
    'fleet_vehicles'                       => 'id',
    'fleet_drivers'                        => 'id',
    'fleet_trips'                          => 'id',
    'fleet_trip_items'                     => 'id',
    'fleet_trip_documents'                 => 'id',
    'fleet_trip_overhead_rates'            => 'id',
    'fleet_customers'                      => 'id',
    'fleet_customers_master'               => 'id',
    'fleet_lease_agents'                   => 'id',
    'fleet_lease_agent_payments'           => 'id',
    'fleet_lease_agent_payment_trips'      => 'payment_id',
    'fleet_expenses'                       => 'id',
    'fleet_expense_vendors'                => 'id',
    'fleet_fuel_log'                       => 'id',
    'fleet_fuel_companies'                 => 'id',
    'fleet_fuel_payments'                  => 'id',
    'fleet_purchase_orders'                => 'id',
    'fleet_po_items'                       => 'id',
    'fleet_vendors'                        => 'id',
    'fleet_vendor_destination_toll_rates'  => 'id',
    'fleet_tyres'                          => 'id',
    'fleet_tyre_history'                   => 'id',
    'fleet_driver_salary'                  => 'id',
    'fleet_driver_retro_salary'            => 'id',
    'fleet_vehicle_status_log'             => 'id',

    // Agents / Commissions
    'agent_commissions'                    => 'id',
    'agent_commission_payments'            => 'id',
    'agent_payment_commissions'            => 'payment_id',  // ← different PK

    // Aggregates
    'aggregate_items'                      => 'id',
    'aggregate_sources'                    => 'id',
    'aggregate_suppliers'                  => 'id',

    // Masters
    'companies'                            => 'id',
    'company_settings'                     => 'id',
    'vendors'                              => 'id',
    'transporters'                         => 'id',
    'transporter_payments'                 => 'id',
    'transporter_rates'                    => 'id',
    'trading_receipts'                     => 'id',
    'source_of_material'                   => 'id',

    // App / Users
    'app_users'                            => 'id',
    'app_activity_log'                     => 'id',
    'app_error_log'                        => 'id',
    'app_month_locks'                      => 'id',
    'app_user_login_log'                   => 'id',

    // DMS Messaging
    'dms_messages'                         => 'id',
    'dms_push_subscriptions'               => 'id',

    // Special PK tables
    'doc_sequences'                        => 'seq_key',     // ← different PK
    'dms_push_settings'                    => 'setting_key', // ← different PK

    // NOTE: despatch_orders_fix_snapshot_20260513 is a one-time snapshot — excluded from sync
]);

// ════════════════════════════════════════════════════════════
//  SECTION C: SETTINGS — same on both servers
// ════════════════════════════════════════════════════════════
define('SYNC_BATCH_SIZE',         200);
define('SYNC_LOG_FILE',           __DIR__ . '/sync/logs/sync.log');
define('SYNC_LOCK_FILE',          __DIR__ . '/sync/logs/sync.lock');
define('SYNC_TIMEOUT',             60);
define('MAX_LOG_SIZE_MB',           5);
define('WATCHDOG_CHECK_TIMEOUT',    8);
define('QUEUE_REPLAY_BATCH',       50);
define('HEALTH_LOG_KEEP_DAYS',      7);

// Auto-create logs directory
if (!is_dir(__DIR__ . '/sync/logs')) {
    mkdir(__DIR__ . '/sync/logs', 0755, true);
}
