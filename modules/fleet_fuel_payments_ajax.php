<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

ob_start();
try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    $action = $_GET['action'] ?? '';

    if ($action === 'period_outstanding') {
        $db    = getDB();
        $fc_id = (int)($_GET['fc_id'] ?? 0);
        $from  = sanitize($_GET['from'] ?? '');
        $to    = sanitize($_GET['to'] ?? '');

        if (!$fc_id || !$from || !$to) {
            echo json_encode(['error' => 'Missing parameters']);
            exit;
        }

        /* Bills (fuel log entries on Credit) within the selected period */
        $r = $db->query("SELECT COALESCE(SUM(amount + COALESCE(driver_advance,0)),0) AS s
                         FROM fleet_fuel_log
                         WHERE fuel_company_id = $fc_id
                           AND payment_mode = 'Credit'
                           AND fuel_date BETWEEN '$from' AND '$to'");
        $period_bills = $r ? (float)$r->fetch_assoc()['s'] : 0;

        /* Payments made within the same period (payment_date falls in range) */
        $r2 = $db->query("SELECT COALESCE(SUM(amount),0) AS s
                          FROM fleet_fuel_payments
                          WHERE fuel_company_id = $fc_id
                            AND payment_date BETWEEN '$from' AND '$to'");
        $period_paid = $r2 ? (float)$r2->fetch_assoc()['s'] : 0;

        /* Period outstanding = bills in period minus payments made in same period */
        $outstanding = round($period_bills - $period_paid, 2);

        ob_clean();
        echo json_encode([
            'period_bills' => $period_bills,
            'period_paid'  => $period_paid,
            'outstanding'  => $outstanding,
        ]);
    } else {
        ob_clean();
        echo json_encode(['error' => 'Unknown action']);
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['error' => $e->getMessage()]);
}
