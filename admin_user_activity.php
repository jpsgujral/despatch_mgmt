<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isAdmin()) {
    $_SESSION['alert'] = ['type'=>'danger','message'=>'<i class="bi bi-shield-lock me-2"></i>Only Administrators can access the Admin Panel.'];
    header('Location: ../index.php');
    exit;
}

$db = getDB();

$db->query("CREATE TABLE IF NOT EXISTS app_user_login_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(60) NOT NULL DEFAULT '',
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    INDEX(user_id),
    INDEX(login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function adminTableExists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function adminColumnExists(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

if (adminTableExists($db, 'despatch_orders') && !adminColumnExists($db, 'despatch_orders', 'actual_created_by')) {
    @ $db->query("ALTER TABLE despatch_orders ADD COLUMN actual_created_by INT DEFAULT 0 COMMENT 'Actual logged-in user who entered despatch'");
}
// Ensure created_at and created_by exist on key tables so activity filtering works by entry date
if (adminTableExists($db, 'despatch_orders') && !adminColumnExists($db, 'despatch_orders', 'created_at')) {
    @ $db->query("ALTER TABLE despatch_orders ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}
if (adminTableExists($db, 'fleet_trips') && !adminColumnExists($db, 'fleet_trips', 'created_at')) {
    @ $db->query("ALTER TABLE fleet_trips ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}
if (adminTableExists($db, 'fleet_trips') && !adminColumnExists($db, 'fleet_trips', 'created_by')) {
    @ $db->query("ALTER TABLE fleet_trips ADD COLUMN created_by INT DEFAULT 0");
}

$despatch_user_expr = adminColumnExists($db, 'despatch_orders', 'actual_created_by')
    ? 'COALESCE(t.actual_created_by, t.created_by)'
    : 't.created_by';

$report_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$report_date)) {
    $report_date = date('Y-m-d');
}
$report_fmt = date('d M Y', strtotime($report_date));

$activity_sources = [
    [
        'table' => 'despatch_orders',
        'user_expr' => $despatch_user_expr,
        'user_join_expr' => $despatch_user_expr,
        'date_col' => 'created_at',
        'event_time_expr' => "COALESCE(t.created_at, CONCAT(t.despatch_date, ' 00:00:00'))",
        'filter_expr' => "(DATE(t.created_at) = '%s' OR (t.created_at IS NULL AND DATE(t.despatch_date) = '%s'))",
        'count_key' => 'despatch_count',
        'label' => 'Despatch Orders',
        'detail_expr' => "CONCAT('Challan ', COALESCE(t.challan_no, CONCAT('#', t.id)), ' / ', COALESCE(t.status, ''), ' / Dt ', COALESCE(DATE_FORMAT(t.despatch_date, '%d/%m/%Y'), ''))",
    ],
    [
        'table' => 'sales_invoices',
        'user_expr' => 't.created_by',
        'user_join_expr' => 't.created_by',
        'date_col' => 'created_at',
        'event_time_expr' => "COALESCE(t.created_at, CONCAT(t.invoice_date, ' 00:00:00'))",
        'filter_expr' => "(DATE(t.created_at) = '%s' OR DATE(t.invoice_date) = '%s')",
        'count_key' => 'invoice_count',
        'label' => 'Sales Invoices',
        'detail_expr' => "CONCAT('Invoice ', COALESCE(t.invoice_number, CONCAT('#', t.id)), ' / ', COALESCE(t.status, ''), ' / Dt ', COALESCE(DATE_FORMAT(t.invoice_date, '%d/%m/%Y'), ''))",
    ],
    [
        'table' => 'transporter_payments',
        'user_expr' => 't.created_by',
        'user_join_expr' => 't.created_by',
        'date_col' => 'created_at',
        'event_time_expr' => 't.created_at',
        'filter_expr' => "DATE(t.created_at) = '%s'",
        'count_key' => 'transporter_payment_count',
        'label' => 'Transporter Payments',
        'detail_expr' => "CONCAT('Payment ', COALESCE(t.payment_no, CONCAT('#', t.id)), ' / ', COALESCE(t.payment_type, ''), ' / Rs ', COALESCE(CAST(t.amount AS CHAR), '0'))",
    ],
    [
        'table' => 'agent_commission_payments',
        'user_expr' => 't.created_by',
        'user_join_expr' => 't.created_by',
        'date_col' => 'created_at',
        'event_time_expr' => 't.created_at',
        'filter_expr' => "DATE(t.created_at) = '%s'",
        'count_key' => 'agent_payment_count',
        'label' => 'Agent Commission Payments',
        'detail_expr' => "CONCAT('Agent payment #', t.id, ' / Rs ', COALESCE(CAST(t.amount AS CHAR), '0'))",
    ],
    [
        'table' => 'fleet_trips',
        'user_expr' => 't.created_by',
        'user_join_expr' => 't.created_by',
        'date_col' => 'created_at',
        'event_time_expr' => "COALESCE(t.created_at, CONCAT(t.trip_date, ' 00:00:00'))",
        'filter_expr' => "(DATE(t.created_at) = '%s' OR (t.created_at IS NULL AND DATE(t.trip_date) = '%s'))",
        'count_key' => 'trip_count',
        'label' => 'Trip Orders',
        'detail_expr' => "CONCAT('Trip ', COALESCE(t.trip_no, CONCAT('#', t.id)), ' / ', COALESCE(t.status, ''), ' / Dt ', COALESCE(DATE_FORMAT(t.trip_date, '%d/%m/%Y'), ''))",
    ],
    [
        'table' => 'fleet_fuel_log',
        'user_expr' => 't.created_by',
        'user_join_expr' => 't.created_by',
        'date_col' => 'created_at',
        'event_time_expr' => "COALESCE(t.created_at, CONCAT(t.fuel_date, ' 00:00:00'))",
        'filter_expr' => "(DATE(t.created_at) = '%s' OR DATE(t.fuel_date) = '%s')",
        'count_key' => 'fuel_count',
        'label' => 'Fuel Entries',
        'detail_expr' => "CONCAT('Fuel ', COALESCE(t.bill_no, CONCAT('#', t.id)), ' / ', COALESCE(CAST(t.litres AS CHAR), '0'), ' L')",
    ],
    [
        'table' => 'fleet_expenses',
        'user_expr' => 't.created_by',
        'user_join_expr' => 't.created_by',
        'date_col' => 'created_at',
        'event_time_expr' => "COALESCE(t.created_at, CONCAT(t.expense_date, ' 00:00:00'))",
        'filter_expr' => "(DATE(t.created_at) = '%s' OR DATE(t.expense_date) = '%s')",
        'count_key' => 'expense_count',
        'label' => 'Vehicle Expenses',
        'detail_expr' => "CONCAT(COALESCE(t.expense_type, 'Expense'), ' / ', COALESCE(t.vendor_name, ''))",
    ],
    [
        'table' => 'fleet_purchase_orders',
        'user_expr' => 't.created_by',
        'user_join_expr' => 't.created_by',
        'date_col' => 'created_at',
        'event_time_expr' => "COALESCE(t.created_at, CONCAT(t.po_date, ' 00:00:00'))",
        'filter_expr' => "(DATE(t.created_at) = '%s' OR DATE(t.po_date) = '%s')",
        'count_key' => 'fleet_po_count',
        'label' => 'Fleet Customer POs',
        'detail_expr' => "CONCAT('PO ', COALESCE(t.po_number, CONCAT('#', t.id)), ' / ', COALESCE(t.status, ''))",
    ],
    [
        'table' => 'dms_messages',
        'user_expr' => 't.sender_id',
        'user_join_expr' => 't.sender_id',
        'date_col' => 'created_at',
        'event_time_expr' => 't.created_at',
        'filter_expr' => "DATE(t.created_at) = '%s'",
        'count_key' => 'message_count',
        'label' => 'Messages Sent',
        'detail_expr' => "CONCAT('Message: ', COALESCE(t.subject, '(No Subject)'))",
    ],
    [
        'table' => 'app_activity_log',
        'user_expr' => 't.user_id',
        'user_join_expr' => 't.user_id',
        'date_col' => 'created_at',
        'event_time_expr' => 't.created_at',
        'filter_expr' => "(DATE(t.created_at) = '%s' OR DATE(t.activity_date) = '%s')",
        'count_key' => 'delete_count',
        'label' => 'Deleted Records',
        'detail_expr' => "CONCAT(t.activity_type, ' / ', COALESCE(t.details, CONCAT(t.entity_type, ' #', t.entity_id)))",
    ],
];

$summary = [];
$users = $db->query("SELECT id, username, full_name, role, status, last_login FROM app_users ORDER BY role ASC, full_name ASC, username ASC");
while ($u = $users->fetch_assoc()) {
    $uid = (int)$u['id'];
    $summary[$uid] = [
        'id' => $uid,
        'username' => $u['username'] ?? '',
        'full_name' => $u['full_name'] ?? '',
        'role' => $u['role'] ?? '',
        'status' => $u['status'] ?? '',
        'last_login' => $u['last_login'] ?? '',
        'despatch_count' => 0,
        'invoice_count' => 0,
        'trip_count' => 0,
        'fuel_count' => 0,
        'expense_count' => 0,
        'fleet_po_count' => 0,
        'transporter_payment_count' => 0,
        'agent_payment_count' => 0,
        'message_count' => 0,
        'delete_count' => 0,
        'total_actions' => 0,
    ];
}

$timeline = [];
$query_errors = [];
foreach ($activity_sources as $source) {
    if (!adminTableExists($db, $source['table'])) {
        continue;
    }
    $event_time_expr = $source['event_time_expr'] ?? $source['date_col'];
    $filter_expr = isset($source['filter_expr'])
        ? sprintf($source['filter_expr'], $report_date, $report_date)
        : "DATE(t.{$source['date_col']}) = '$report_date'";
    $user_expr = $source['user_expr'] ?? '0';
    $user_join_expr = $source['user_join_expr'] ?? $user_expr;
    $sql = "
        SELECT t.id, {$user_expr} AS user_id, {$event_time_expr} AS event_time,
               '{$source['label']}' AS activity_type,
               {$source['detail_expr']} AS activity_detail,
               COALESCE(u.full_name, u.username, 'Admin Entry') AS display_name,
               COALESCE(u.username, '') AS username
        FROM {$source['table']} t
        LEFT JOIN app_users u ON u.id = {$user_join_expr}
        WHERE {$filter_expr}
        ORDER BY {$event_time_expr} DESC, t.id DESC";
    $res = $db->query($sql);
    if (!$res) {
        $query_errors[] = $source['label'] . ': ' . $db->error;
        continue;
    }
    while ($row = $res->fetch_assoc()) {
        $uid = (int)$row['user_id'];
        if (!isset($summary[$uid])) {
            $summary[$uid] = [
                'id' => $uid,
                'username' => $row['username'] ?: 'legacy',
                'full_name' => $row['display_name'] ?: 'Admin Entry',
                'role' => '',
                'status' => 'Active',
                'last_login' => '',
                'despatch_count' => 0,
                'invoice_count' => 0,
                'trip_count' => 0,
                'fuel_count' => 0,
                'expense_count' => 0,
                'fleet_po_count' => 0,
                'transporter_payment_count' => 0,
                'agent_payment_count' => 0,
                'message_count' => 0,
                'delete_count' => 0,
                'total_actions' => 0,
            ];
        }
        if (isset($summary[$uid])) {
            $summary[$uid][$source['count_key']]++;
        }
        $timeline[] = $row;
    }
}

foreach ($summary as &$row) {
    $row['total_actions'] =
        $row['despatch_count'] +
        $row['invoice_count'] +
        $row['trip_count'] +
        $row['fuel_count'] +
        $row['expense_count'] +
        $row['fleet_po_count'] +
        $row['transporter_payment_count'] +
        $row['agent_payment_count'] +
        $row['message_count'] +
        $row['delete_count'];
}
unset($row);

usort($timeline, function($a, $b) {
    return strcmp((string)($b['event_time'] ?? ''), (string)($a['event_time'] ?? ''));
});

$timeline_grouped = [];
foreach ($timeline as $row) {
    $group = $row['activity_type'] ?? 'Other';
    if (!isset($timeline_grouped[$group])) {
        $timeline_grouped[$group] = [];
    }
    $timeline_grouped[$group][] = $row;
}

uasort($summary, function($a, $b) {
    if ($a['total_actions'] === $b['total_actions']) {
        return strcmp(($a['full_name'] ?: $a['username']), ($b['full_name'] ?: $b['username']));
    }
    return $b['total_actions'] <=> $a['total_actions'];
});

$totals = [
    'despatches' => 0,
    'invoices' => 0,
    'trips' => 0,
    'fuel' => 0,
    'expenses' => 0,
    'fleet_po' => 0,
    'transporter_payments' => 0,
    'agent_payments' => 0,
    'messages' => 0,
    'deletes' => 0,
];
foreach ($summary as $row) {
    $totals['despatches'] += (int)$row['despatch_count'];
    $totals['invoices'] += (int)$row['invoice_count'];
    $totals['trips'] += (int)$row['trip_count'];
    $totals['fuel'] += (int)$row['fuel_count'];
    $totals['expenses'] += (int)$row['expense_count'];
    $totals['fleet_po'] += (int)$row['fleet_po_count'];
    $totals['transporter_payments'] += (int)$row['transporter_payment_count'];
    $totals['agent_payments'] += (int)$row['agent_payment_count'];
    $totals['messages'] += (int)$row['message_count'];
    $totals['deletes'] += (int)$row['delete_count'];
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-activity me-2"></i>Daily User Activity';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Daily User Activity - <?= htmlspecialchars($report_fmt) ?></h5>
    <form method="GET" class="d-flex gap-2">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($report_date) ?>" max="<?= date('Y-m-d') ?>">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>View</button>
    </form>
</div>

<?php if (!empty($query_errors)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Some activity sources could not be loaded:
    <?= htmlspecialchars(implode(' | ', $query_errors)) ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl">
        <div class="card text-center p-3 border-start border-4 border-primary">
            <div class="text-muted small">Despatches</div>
            <div class="fs-4 fw-bold text-primary"><?= $totals['despatches'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card text-center p-3 border-start border-4 border-warning">
            <div class="text-muted small">Trips</div>
            <div class="fs-4 fw-bold text-warning"><?= $totals['trips'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card text-center p-3 border-start border-4 border-info">
            <div class="text-muted small">Invoices</div>
            <div class="fs-4 fw-bold text-info"><?= $totals['invoices'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card text-center p-3 border-start border-4 border-danger">
            <div class="text-muted small">Finance Txns</div>
            <div class="fs-4 fw-bold text-danger"><?= $totals['transporter_payments'] + $totals['agent_payments'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card text-center p-3 border-start border-4 border-secondary">
            <div class="text-muted small">Messages</div>
            <div class="fs-4 fw-bold text-secondary"><?= $totals['messages'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card text-center p-3 border-start border-4 border-dark">
            <div class="text-muted small">Deletes</div>
            <div class="fs-4 fw-bold text-dark"><?= $totals['deletes'] ?></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-people me-2"></i>User-wise Summary</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th class="text-center">Despatch</th>
                        <th class="text-center">Trips</th>
                        <th class="text-center">Invoices</th>
                        <th class="text-center">Transport Pay</th>
                        <th class="text-center">Agent Pay</th>
                        <th class="text-center">Fuel</th>
                        <th class="text-center">Expenses</th>
                        <th class="text-center">POs</th>
                        <th class="text-center">Messages</th>
                        <th class="text-center">Deletes</th>
                        <th class="text-center">Total Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($summary as $row): ?>
                    <?php if ($row['total_actions'] === 0) continue; ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($row['full_name'] ?: $row['username']) ?></div>
                            <?php if (!empty($row['username']) && $row['username'] !== 'legacy'): ?>
                            <div class="text-muted small">@<?= htmlspecialchars($row['username']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?= ($row['role'] === 'Admin' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars($row['role'] ?: 'User') ?></span></td>
                        <td class="text-center"><?= (int)$row['despatch_count'] ?></td>
                        <td class="text-center"><?= (int)$row['trip_count'] ?></td>
                        <td class="text-center"><?= (int)$row['invoice_count'] ?></td>
                        <td class="text-center"><?= (int)$row['transporter_payment_count'] ?></td>
                        <td class="text-center"><?= (int)$row['agent_payment_count'] ?></td>
                        <td class="text-center"><?= (int)$row['fuel_count'] ?></td>
                        <td class="text-center"><?= (int)$row['expense_count'] ?></td>
                        <td class="text-center"><?= (int)$row['fleet_po_count'] ?></td>
                        <td class="text-center"><?= (int)$row['message_count'] ?></td>
                        <td class="text-center"><?= (int)$row['delete_count'] ?></td>
                        <td class="text-center fw-bold text-success"><?= (int)$row['total_actions'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Activity Timeline</span>
                <?php if ($timeline): ?>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary py-0" onclick="toggleAllTimeline(true)"><i class="bi bi-arrows-expand me-1"></i>All</button>
                    <button class="btn btn-sm btn-outline-secondary py-0" onclick="toggleAllTimeline(false)"><i class="bi bi-arrows-collapse me-1"></i>All</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!$timeline): ?>
                    <div class="p-3 text-muted">No user-created activity found for this date.</div>
                <?php else: ?>
                    <?php foreach ($timeline_grouped as $activity_name => $rows):
                        $collapse_id = 'tl_' . preg_replace('/[^a-z0-9]/i', '_', $activity_name);
                    ?>
                    <div class="border-top">
                        <div class="px-3 py-2 bg-light fw-semibold text-dark d-flex justify-content-between align-items-center"
                             style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>" aria-expanded="true">
                            <span><?= htmlspecialchars($activity_name) ?></span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-secondary"><?= count($rows) ?></span>
                                <i class="bi bi-chevron-up timeline-chevron" style="transition:transform .2s"></i>
                            </div>
                        </div>
                        <div class="collapse show" id="<?= $collapse_id ?>">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>User</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td style="white-space:nowrap"><?= date('d/m/Y h:i A', strtotime($row['event_time'])) ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= htmlspecialchars($row['display_name']) ?></div>
                                                <?php if (!empty($row['username']) && $row['username'] !== 'legacy'): ?>
                                                <div class="text-muted small">@<?= htmlspecialchars($row['username']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($row['activity_detail']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(trigger) {
    var target = document.querySelector(trigger.getAttribute('data-bs-target'));
    if (!target) return;
    target.addEventListener('hide.bs.collapse', function() {
        trigger.querySelector('.timeline-chevron').style.transform = 'rotate(180deg)';
    });
    target.addEventListener('show.bs.collapse', function() {
        trigger.querySelector('.timeline-chevron').style.transform = 'rotate(0deg)';
    });
});
function toggleAllTimeline(expand) {
    document.querySelectorAll('.card-body .collapse').forEach(function(el) {
        bootstrap.Collapse.getOrCreateInstance(el, {toggle: false})[expand ? 'show' : 'hide']();
    });
}
</script>

<?php include '../includes/footer.php'; ?>
