<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ══════════════════════════════════════════════════════════
// ERROR HANDLING CONFIGURATION
// ══════════════════════════════════════════════════════════
define('APP_DEBUG', false); // Set true only on local dev — never on production
define('APP_LOG_ERRORS', true);
define('APP_ERROR_LOG_FILE', __DIR__ . '/../logs/app_errors.log');

// Suppress raw PHP errors from displaying to users
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ERROR_LOG_FILE);

// Ensure logs directory exists
if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0755, true);
}

// ── Global error handler ──
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ignore suppressed errors (@function calls)
    if (error_reporting() === 0) return false;

    $levels = [
        E_ERROR => 'ERROR', E_WARNING => 'WARNING', E_NOTICE => 'NOTICE',
        E_DEPRECATED => 'DEPRECATED', E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING', E_USER_NOTICE => 'USER_NOTICE',
    ];
    $level = $levels[$errno] ?? 'UNKNOWN';

    // Skip non-critical notices/deprecations in production
    if (!APP_DEBUG && in_array($errno, [E_NOTICE, E_DEPRECATED, E_USER_NOTICE])) return false;

    dmsLogError($level, $errstr, $errfile, $errline);

    // For fatal-level errors show friendly page
    if (in_array($errno, [E_ERROR, E_USER_ERROR])) {
        dmsShowErrorPage($errstr);
    }

    return true;
});

// ── Global exception handler ──
set_exception_handler(function(Throwable $e) {
    dmsLogError('EXCEPTION', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    dmsShowErrorPage($e->getMessage());
});

// ── Log error to file + DB ──
function dmsLogError(string $level, string $message, string $file = '', int $line = 0, string $trace = '') {
    // Sanitise file path — strip server root for brevity
    $file = str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', $file);

    // Write to log file
    if (APP_LOG_ERRORS) {
        $entry = sprintf("[%s] [%s] %s in %s on line %d\n", date('Y-m-d H:i:s'), $level, $message, $file, $line);
        @error_log($entry, 3, APP_ERROR_LOG_FILE);
    }

    // Write to DB (non-fatal only — avoid loop if DB itself has an error)
    if ($level !== 'DB_CONNECTION') {
        try {
            $db = getDB();
            // Always ensure table exists before inserting
            $db->query("CREATE TABLE IF NOT EXISTS app_error_log (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                logged_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                level       VARCHAR(20),
                message     VARCHAR(1000),
                file        VARCHAR(255),
                line        INT DEFAULT 0,
                url         VARCHAR(500),
                user_id     INT DEFAULT 0,
                trace       TEXT,
                resolved    TINYINT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $url    = $db->real_escape_string(substr($_SERVER['REQUEST_URI'] ?? '', 0, 500));
            $user   = (int)($_SESSION['user_id'] ?? 0);
            $msg    = $db->real_escape_string(substr($message, 0, 1000));
            $fil    = $db->real_escape_string(substr($file, 0, 255));
            $trc    = $db->real_escape_string(substr($trace, 0, 2000));
            $lv     = $db->real_escape_string($level);
            $db->query("INSERT INTO app_error_log (level,message,file,line,url,user_id,trace)
                VALUES ('$lv','$msg','$fil',$line,'$url',$user,'$trc')");
        } catch (Throwable $ex) {
            // DB logging failed — already written to file, silently ignore
        }
    }
}

// ── Friendly error page ──
function dmsShowErrorPage(string $detail = '') {
    // Don't show page for AJAX / JSON requests
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
              (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
              isset($_GET['ajax']);
    if ($isAjax) {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => APP_DEBUG ? $detail : 'A server error occurred.']);
        exit;
    }

    // For print/challan pages — simple text
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, 'challan') !== false || strpos($uri, 'print') !== false) {
        if (ob_get_level() > 0) ob_clean();
        echo '<div style="font-family:Arial;padding:40px;color:#c00"><h2>Error</h2><p>' .
             (APP_DEBUG ? htmlspecialchars($detail) : 'A server error occurred. Please go back and try again.') .
             '</p></div>';
        exit;
    }

    if (ob_get_level() > 0) ob_clean();
    $show = APP_DEBUG ? htmlspecialchars($detail) : '';
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Error — ' . APP_NAME . '</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head><body style="background:#f0f8f3">
<div class="d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow" style="max-width:520px;width:100%">
<div class="card-header text-white text-center py-3" style="background:#1a5632">
    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
    <h5 class="mt-2 mb-0">Something went wrong</h5>
</div>
<div class="card-body text-center py-4">
    <p class="text-muted mb-3">An unexpected error occurred. Our team has been notified.</p>
    ' . ($show ? '<div class="alert alert-danger text-start small"><strong>Details:</strong><br>' . $show . '</div>' : '') . '
    <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
        <i class="bi bi-arrow-left me-1"></i>Go Back
    </a>
    <a href="../modules/index.php" class="btn" style="background:#1a5632;color:#fff">
        <i class="bi bi-house me-1"></i>Dashboard
    </a>
</div>
<div class="card-footer text-center text-muted small py-2">' . APP_NAME . ' &bull; Error has been logged</div>
</div></div></body></html>';
    exit;
}

// ── Safe DB query wrapper — logs SQL errors automatically ──
function dbQuery(string $sql) {
    $db = getDB();
    $result = $db->query($sql);
    if ($result === false) {
        dmsLogError('DB_QUERY', $db->error . ' | SQL: ' . substr($sql, 0, 500), '', 0);
        if (APP_DEBUG) {
            throw new RuntimeException('DB Error: ' . $db->error);
        }
    }
    return $result;
}

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'tsgimpex_tsg');
define('DB_PASS', ';l%r07dDBIgeUBrr');
define('DB_NAME', 'tsgimpex_despatch_mgmt');

define('APP_NAME', 'Despatch Management System');
define('APP_VERSION', '1.0');

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            dmsLogError('DB_CONNECTION', $conn->connect_error, __FILE__, __LINE__);
            dmsShowErrorPage('Database connection failed. Please try again later.');
        }
        $conn->set_charset("utf8mb4");
        mysqli_report(MYSQLI_REPORT_OFF); // Errors handled manually via dmsLogError/exception handler

        $db_name = DB_NAME;

        // Auto-migrate despatch_orders
        $cols = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='despatch_orders'");
        if ($cols) {
            $existing = [];
            while ($r = $cols->fetch_row()) $existing[] = $r[0];
            if (!in_array('created_by', $existing))
                $conn->query("ALTER TABLE despatch_orders ADD COLUMN created_by INT DEFAULT 0 AFTER id");
            if (!in_array('company_id', $existing))
                $conn->query("ALTER TABLE despatch_orders ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        }

        // Auto-migrate purchase_orders
        $pocols = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='purchase_orders'");
        if ($pocols) {
            $po_existing = [];
            while ($r = $pocols->fetch_row()) $po_existing[] = $r[0];
            if (!in_array('company_id', $po_existing))
                $conn->query("ALTER TABLE purchase_orders ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        }

        // Create companies table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS companies (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            company_name        VARCHAR(200) NOT NULL,
            address             TEXT,
            city                VARCHAR(60),
            state               VARCHAR(60),
            pincode             VARCHAR(10),
            phone               VARCHAR(30),
            email               VARCHAR(120),
            gstin               VARCHAR(20),
            pan                 VARCHAR(15),
            bank_name           VARCHAR(120),
            account_no          VARCHAR(30),
            ifsc_code           VARCHAR(20),
            smtp_host           VARCHAR(120) DEFAULT '',
            smtp_port           SMALLINT DEFAULT 587,
            smtp_user           VARCHAR(120) DEFAULT '',
            smtp_pass           VARCHAR(255) DEFAULT '',
            smtp_secure         VARCHAR(10) DEFAULT 'tls',
            smtp_from_name      VARCHAR(120) DEFAULT '',
            seal_path           VARCHAR(255) DEFAULT NULL,
            mtc_sig_path        VARCHAR(255) DEFAULT NULL,
            checked_by_sig_path VARCHAR(255) DEFAULT NULL,
            is_active           TINYINT DEFAULT 1,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed companies from company_settings if table is empty
        $cc_res = $conn->query("SELECT COUNT(*) AS c FROM companies");
        $cc = ($cc_res && $cc_res !== false) ? $cc_res->fetch_assoc() : null;
        if ($cc && (int)$cc['c'] === 0) {
            $cs_res = $conn->query("SELECT * FROM company_settings LIMIT 1");
            $cs = ($cs_res && $cs_res !== false) ? $cs_res->fetch_assoc() : null;
            if ($cs) {
                $e     = function($v) use ($conn) { return $conn->real_escape_string($v ?? ''); };
                $seal  = !empty($cs['seal_path'])           ? "'" . $e($cs['seal_path'])           . "'" : 'NULL';
                $mtcs  = !empty($cs['mtc_sig_path'])        ? "'" . $e($cs['mtc_sig_path'])        . "'" : 'NULL';
                $chks  = !empty($cs['checked_by_sig_path']) ? "'" . $e($cs['checked_by_sig_path']) . "'" : 'NULL';
                $port  = (int)($cs['smtp_port'] ?? 587);
                $sql   = "INSERT INTO companies
                    (company_name, address, city, state, pincode, phone, email, gstin, pan,
                     bank_name, account_no, ifsc_code, smtp_host, smtp_port, smtp_user, smtp_pass,
                     smtp_secure, smtp_from_name, seal_path, mtc_sig_path, checked_by_sig_path)
                    VALUES (
                     '" . $e($cs['company_name'])   . "',
                     '" . $e($cs['address'])         . "',
                     '" . $e($cs['city'])            . "',
                     '" . $e($cs['state'])           . "',
                     '" . $e($cs['pincode'])         . "',
                     '" . $e($cs['phone'])           . "',
                     '" . $e($cs['email'])           . "',
                     '" . $e($cs['gstin'])           . "',
                     '" . $e($cs['pan'])             . "',
                     '" . $e($cs['bank_name'])       . "',
                     '" . $e($cs['account_no'])      . "',
                     '" . $e($cs['ifsc_code'])       . "',
                     '" . $e($cs['smtp_host'])       . "',
                     $port,
                     '" . $e($cs['smtp_user'])       . "',
                     '" . $e($cs['smtp_pass'])       . "',
                     '" . $e($cs['smtp_secure'])     . "',
                     '" . $e($cs['smtp_from_name'])  . "',
                     $seal, $mtcs, $chks
                    )";
                $conn->query($sql);
            }
        }
    }
    return $conn;
}

/* ── Load R2 helper (provides r2_url, r2_delete, r2_handle_upload, r2_upload, r2_mime) ── */
$_r2_path = __DIR__ . '/r2_helper.php';
if (file_exists($_r2_path) && !function_exists('r2_url')) {
    require_once $_r2_path;
}
unset($_r2_path);
/* ── R2 fallback stubs — only if r2_helper.php not available ── */
if (!function_exists('r2_url')) {
    function r2_url(string $key): string {
    if (empty($key)) return '';
    return 'https://pub-5721570094064d529f1527519424c77b.r2.dev/dms_uploads/' . ltrim($key, '/');
}
}
if (!function_exists('r2_delete')) {
    function r2_delete(string $key): bool { return true; }
}
if (!function_exists('r2_handle_upload')) {
    function r2_handle_upload(string $field, string $prefix, string $oldKey = ''): string { return ''; }
}

/* ── despatchFilter: return SQL WHERE fragment to restrict despatch rows by agent ── */
function despatchFilter(string $alias = ''): string {
    if (function_exists('isAdmin') && isAdmin()) return '';
    if (function_exists('canViewAll') && canViewAll('despatch')) return '';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) return '';
    $col = $alias ? $alias.'.created_by' : 'created_by';
    return " AND $col=$uid";
}

/* ── canViewAll: check if user can see all users' transactions for a module ── */
function canViewAll(string $module): bool {
    if (function_exists('isAdmin') && isAdmin()) return true;
    $db  = getDB();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) return false;
    $col = match($module) {
        'trips'    => 'view_all_trips',
        'challans' => 'view_all_challans',
        default    => 'view_all_despatch',
    };
    $row = $db->query("SELECT `$col` FROM app_users WHERE id=$uid LIMIT 1")->fetch_assoc();
    return (bool)($row[$col] ?? false);
}

function sanitize($data) {
    $db = getDB();
    return $db->real_escape_string(trim(htmlspecialchars($data)));
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function showAlert($type, $message) {
    $_SESSION['alert'] = ['type' => $type, 'message' => $message];
}

function displayAlert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        echo "<div class='alert alert-{$alert['type']} alert-dismissible fade show' role='alert'>
                {$alert['message']}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
        unset($_SESSION['alert']);
    }
}

/**
 * Return the appropriate decimal places for a given UOM.
 *  3 dp  — MT, Kg, Gm, Litre, Mtr, Cm
 *  2 dp  — Bundle, Bag
 *  0 dp  — Nos, Set, Box, Carton, Pair, Dozen
 */
function uomDecimals(string $uom): int {
    $three = ['MT','Kg','Gm','Litre','Mtr','Cm'];
    $zero  = ['Nos','Set','Box','Carton','Pair','Dozen'];
    if (in_array($uom, $three)) return 3;
    if (in_array($uom, $zero))  return 0;
    return 2;
}

/** Format a quantity value with the correct decimals for its UOM. */
function fmtQty($qty, string $uom): string {
    return number_format((float)$qty, uomDecimals($uom));
}

/** Return active company id from session (default 1). */
function activeCompanyId(): int {
    return (int)($_SESSION['active_company_id'] ?? 1);
}

/** Fetch one company row by id (or active company). */
function getCompany(int $id = 0): array {
    $db = getDB();
    $id = $id > 0 ? $id : activeCompanyId();
    $row = $db->query("SELECT * FROM companies WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$row) $row = $db->query("SELECT * FROM companies ORDER BY id LIMIT 1")->fetch_assoc();
    return $row ?? [];
}

/** Get all active companies. */
function getAllCompanies(): array {
    return getDB()->query("SELECT * FROM companies WHERE is_active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}
