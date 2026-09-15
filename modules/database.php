<?php
// ============================================================
// config/database.php — Database connection settings
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'tsgimpex_familywealth');
define('DB_USER', 'tsgimpex_tsg');          // ← Change to your MySQL username
define('DB_PASS', ';l%r07dDBIgeUBrr');              // ← Change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

define('JWT_SECRET', 'change_this_to_a_long_random_secret_key_123!');
define('JWT_EXPIRE', 86400);        // 24 hours in seconds

define('ALERT_EMAIL_FROM', 'noreply@yourfamily.com');
define('ALERT_EMAIL_NAME', 'FamilyWealth Alerts');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
