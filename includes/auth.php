<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('isAdmin')) {

    function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'Admin';
    }

    function displayRoleName($role) {
        return $role;
    }

    function currentDisplayName() {
        if (isAdmin()) return 'DMS_Admin';
        return $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
    }

    function userPerms() {
        return isset($_SESSION['perms']) && is_array($_SESSION['perms']) ? $_SESSION['perms'] : [];
    }

    function canDo($module, $action) {
        if (isAdmin()) return true;
        $perms = userPerms();
        return isset($perms[$module][$action]) && $perms[$module][$action] == 1;
    }

    function canAuthorizeTransporterPayments() {
        if (isAdmin()) return true;
        if (!empty($_SESSION['can_authorize_transporter_payments'])) return true;
        // Users who can record bulk payments should also be able to authorise them
        if (function_exists('canDo') && canDo('transporter_bulk_payments', 'create')) return true;
        return false;
    }

    function currentLeaseAgentId(): int {
        return (int)($_SESSION['lease_agent_id'] ?? 0);
    }

    function isLeaseAgentUser(): bool {
        return !isAdmin() && !empty($_SESSION['is_lease_agent']) && currentLeaseAgentId() > 0;
    }

    function leaseAgentAllowedPage(string $page): bool {
        return in_array($page, [
            'fleet_lease_agent_dashboard.php',
            'fleet_vehicles.php',
            'fleet_drivers.php',
            'fleet_lease_agents.php',
            'fleet_trips.php',
            'fleet_trip_challan.php',
            'fleet_trip_challan1.php',
            'export_trip_pdf.php',
            'export_trip_pdf1.php',
            'fleet_lease_agent_report.php',
            'fleet_lease_agent_payments.php',
            'img.php',
        ], true);
    }

    function requirePerm($module, $action) {
        if (!canDo($module, $action)) {
            $_SESSION['alert'] = [
                'type'    => 'danger',
                'message' => '<i class="bi bi-shield-lock me-2"></i>You do not have permission to access this section.'
            ];
            $back = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false || strpos($_SERVER['PHP_SELF'], '/backup/') !== false) ? '../index.php' : 'index.php';
            header('Location: ' . $back);
            exit;
        }
    }

    function ensureUsersTable($db) {
    $db->query("CREATE TABLE IF NOT EXISTS app_users (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            username    VARCHAR(60)  NOT NULL UNIQUE,
            full_name   VARCHAR(120) NOT NULL DEFAULT '',
            email       VARCHAR(120) DEFAULT '',
            role        ENUM('Admin','User') NOT NULL DEFAULT 'User',
            password    VARCHAR(255) NOT NULL,
            permissions TEXT,
            status      ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            last_login  DATETIME DEFAULT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $res = $db->query("SELECT COUNT(*) AS c FROM app_users");
        $row = ($res && $res !== false) ? $res->fetch_assoc() : null;
        if ($row && (int)$row['c'] === 0) {
            $hash = password_hash('@Summer97', PASSWORD_DEFAULT);
            $safe = $db->real_escape_string($hash);
            $db->query("INSERT INTO app_users (username, full_name, role, password, permissions, status)
                        VALUES ('admin', 'Administrator', 'Admin', '$safe', '{}', 'Active')");
        }
    }

} // end if !function_exists

/* Ensure table exists */
ensureUsersTable(getDB());

/* Auto-migrate: add signature_path column if not present */
(function($db){
    $cols = $db->query("SHOW COLUMNS FROM app_users LIKE 'signature_path'");
    if ($cols && $cols->num_rows === 0) {
        $db->query("ALTER TABLE app_users ADD COLUMN signature_path VARCHAR(255) DEFAULT NULL");
    }
    $authCols = $db->query("SHOW COLUMNS FROM app_users LIKE 'can_authorize_transporter_payments'");
    if ($authCols && $authCols->num_rows === 0) {
        $db->query("ALTER TABLE app_users ADD COLUMN can_authorize_transporter_payments TINYINT(1) DEFAULT 0");
    }
    $navCols = $db->query("SHOW COLUMNS FROM app_users LIKE 'desktop_nav_mode'");
    if ($navCols && $navCols->num_rows === 0) {
        $db->query("ALTER TABLE app_users ADD COLUMN desktop_nav_mode VARCHAR(20) DEFAULT NULL");
    }
    $leaseCols = $db->query("SHOW COLUMNS FROM app_users LIKE 'is_lease_agent'");
    if ($leaseCols && $leaseCols->num_rows === 0) {
        $db->query("ALTER TABLE app_users ADD COLUMN is_lease_agent TINYINT(1) DEFAULT 0");
    }
    $leaseMapCols = $db->query("SHOW COLUMNS FROM app_users LIKE 'lease_agent_id'");
    if ($leaseMapCols && $leaseMapCols->num_rows === 0) {
        $db->query("ALTER TABLE app_users ADD COLUMN lease_agent_id INT DEFAULT NULL");
    }
})(getDB());

/* Redirect unauthenticated — skip on login.php */
if (basename($_SERVER['PHP_SELF']) !== 'login.php' && empty($_SESSION['user_id'])) {
    $loginUrl = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false || strpos($_SERVER['PHP_SELF'], '/backup/') !== false) ? '../login.php' : 'login.php';
    header('Location: ' . $loginUrl);
    exit;
}

/* ALWAYS reload role + permissions fresh from DB every request */
if (!empty($_SESSION['user_id'])) {
    $__uid = (int)$_SESSION['user_id'];
    $__row = getDB()->query("SELECT role, permissions, status, can_authorize_transporter_payments, desktop_nav_mode, is_lease_agent, lease_agent_id FROM app_users WHERE id=$__uid LIMIT 1")->fetch_assoc();

    if (!$__row || $__row['status'] !== 'Active') {
        session_destroy();
        $loginUrl = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false || strpos($_SERVER['PHP_SELF'], '/backup/') !== false) ? '../login.php' : 'login.php';
        header('Location: ' . $loginUrl);
        exit;
    }

    $_SESSION['role']  = $__row['role'];
    $decoded = json_decode($__row['permissions'], true);
    $_SESSION['perms'] = (is_array($decoded)) ? $decoded : [];
    $_SESSION['can_authorize_transporter_payments'] = (int)($__row['can_authorize_transporter_payments'] ?? 0);
    $_SESSION['desktop_nav_mode'] = $__row['desktop_nav_mode'] ?? null;
    $_SESSION['is_lease_agent'] = (int)($__row['is_lease_agent'] ?? 0);
    $_SESSION['lease_agent_id'] = (int)($__row['lease_agent_id'] ?? 0);

    if (isLeaseAgentUser()) {
        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
        if (strpos($_SERVER['PHP_SELF'] ?? '', '/modules/') !== false && str_starts_with($currentPage, 'fleet_') && !leaseAgentAllowedPage($currentPage)) {
            $_SESSION['alert'] = [
                'type'    => 'danger',
                'message' => '<i class="bi bi-shield-lock me-2"></i>This user can only access lease agent fleet data.'
            ];
            header('Location: ../index.php');
            exit;
        }
    }

    unset($__uid, $__row, $decoded);
}
