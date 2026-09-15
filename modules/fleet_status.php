<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_status', 'view');

// Live fleet statuses used by the vehicle status board.
$statusOptions = [
    'Loading' => ['short' => 'Loading', 'icon' => 'bi-box-arrow-in-down', 'pill' => 'pill-loading', 'card' => 'vc-status-loading', 'color' => '#ffc107', 'text' => '#212529'],
    'Despatched to Customer after Loading' => ['short' => 'Despatched', 'icon' => 'bi-truck', 'pill' => 'pill-transit', 'card' => 'vc-status-transit', 'color' => '#0dcaf0', 'text' => '#212529'],
    'Reached Customer Location' => ['short' => 'Reached Customer', 'icon' => 'bi-geo-alt-fill', 'pill' => 'pill-waiting', 'card' => 'vc-status-waiting', 'color' => '#dc3545', 'text' => '#fff'],
    'Returning for Loading after Unloading at Customer Point' => ['short' => 'Returning', 'icon' => 'bi-arrow-return-left', 'pill' => 'pill-returning', 'card' => 'vc-status-returning', 'color' => '#fd7e14', 'text' => '#212529'],
    'Breakdown' => ['short' => 'Breakdown', 'icon' => 'bi-tools', 'pill' => 'pill-repair', 'card' => 'vc-status-repair', 'color' => '#6f42c1', 'text' => '#fff'],
    'Idle' => ['short' => 'Idle', 'icon' => 'bi-house', 'pill' => 'pill-idle', 'card' => 'vc-status-idle', 'color' => '#198754', 'text' => '#fff'],
];
$legacyStatusMap = [
    'In Transit' => 'Despatched to Customer after Loading',
    'Reached Customer Point' => 'Reached Customer Location',
    'Under Repair / Breakdown' => 'Breakdown',
];
function fleetBoardStatus(string $status, array $legacyStatusMap): string {
    return $legacyStatusMap[$status] ?? $status;
}

function fleetBoardPlain($value) {
    $value = trim((string)($value ?? ''));
    for ($i = 0; $i < 5; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return $value;
}

// ── Ensure status ENUM has all values ───────────────────────────
$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
foreach ([
    'current_status' => "VARCHAR(120) DEFAULT 'Idle'",
    'current_customer_id' => "INT DEFAULT NULL",
    'current_customer_name' => "VARCHAR(150) DEFAULT ''",
    'current_customer_city' => "VARCHAR(80) DEFAULT ''",
    'breakdown_reason' => "TEXT",
    'status_remark' => "TEXT",
] as $col => $def) {
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_vehicles'
        AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE fleet_vehicles ADD COLUMN `$col` $def");
}

// ── AJAX: Update status + optional customer ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'setstatus') {
    header('Content-Type: application/json');
    if (!canDo('fleet_status','update') && !canDo('fleet_vehicles','update') && !isAdmin()) {
        echo json_encode(['ok'=>false,'msg'=>'Permission denied']); exit;
    }
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $ns      = fleetBoardStatus(sanitize($_POST['status'] ?? ''), $legacyStatusMap);
    $raw_cust = trim(sanitize($_POST['customer_id'] ?? ''));
    $cust_id = (int)$raw_cust;
    $breakdown_reason = trim(sanitize($_POST['breakdown_reason'] ?? ''));
    $status_remark = trim(sanitize($_POST['status_remark'] ?? ''));
    $allowed = array_keys($statusOptions);
    if (!$vehicle_id || !in_array($ns, $allowed, true)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
    }
    if ($ns === 'Breakdown' && $breakdown_reason === '') {
        echo json_encode(['ok'=>false,'msg'=>'Breakdown reason is required']); exit;
    }
    if ($ns === 'Idle' && $status_remark === '') {
        echo json_encode(['ok'=>false,'msg'=>'Please enter idle remark']); exit;
    }

    $customer_sql = '';
    if ($raw_cust === 'none' || $raw_cust === '0' || $ns === 'Idle') {
        $customer_sql = ", current_customer_id=NULL, current_customer_name='', current_customer_city=''";
    } elseif ($cust_id > 0) {
        $cr = $db->query("SELECT vendor_name, ship_city FROM fleet_customers_master WHERE id=$cust_id LIMIT 1")->fetch_assoc();
        if ($cr) {
            $cn = mysqli_real_escape_string($db, fleetBoardPlain($cr['vendor_name']));
            $cc = mysqli_real_escape_string($db, fleetBoardPlain($cr['ship_city'] ?? ''));
            $customer_sql = ", current_customer_id=$cust_id, current_customer_name='$cn', current_customer_city='$cc'";
        }
    }
    $ns_esc = mysqli_real_escape_string($db, $ns);
    $reason_esc = mysqli_real_escape_string($db, $breakdown_reason);
    $remark_esc = mysqli_real_escape_string($db, $status_remark);
    $db->query("UPDATE fleet_vehicles SET current_status='$ns_esc'$customer_sql, breakdown_reason=" . ($ns === 'Breakdown' ? "'$reason_esc'" : "''") . ", status_remark=" . ($ns === 'Idle' ? "'$remark_esc'" : "''") . " WHERE id=$vehicle_id");
    echo json_encode(['ok'=>true]); exit;
}

// ── Fetch customers for modal dropdown ───────────────────────────
$customers = $db->query("SELECT id, vendor_name, ship_city FROM fleet_customers_master WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
if (!$customers) $customers = [];
foreach ($customers as &$c) {
    $c['vendor_name'] = fleetBoardPlain($c['vendor_name']);
    $c['ship_city'] = fleetBoardPlain($c['ship_city'] ?? '');
}
unset($c);

// ── Fetch active vehicles with their current trip ────────────────
$vehicles = $db->query("
    SELECT
        v.id            AS vehicle_id,
        v.reg_no,
        v.make,
        v.model,
        v.current_status,
        v.current_customer_id,
        v.current_customer_name,
        v.current_customer_city,
        v.breakdown_reason,
        v.status_remark,
        t.id            AS trip_id,
        t.trip_no,
        t.status        AS trip_status,
        t.from_location,
        t.to_location,
        t.customer_name,
        t.customer_city,
        t.trip_date,
        t.total_weight,
        d.full_name     AS driver_name
    FROM fleet_vehicles v
    LEFT JOIN (
        SELECT t1.* FROM fleet_trips t1
        INNER JOIN (
            SELECT vehicle_id, MAX(id) AS max_id
            FROM fleet_trips
            WHERE status NOT IN ('Completed','Cancelled')
            GROUP BY vehicle_id
        ) t2 ON t1.vehicle_id = t2.vehicle_id AND t1.id = t2.max_id
    ) t ON v.id = t.vehicle_id
    LEFT JOIN fleet_drivers d ON t.driver_id = d.id
    ORDER BY
        FIELD(v.current_status,'Loading','Despatched to Customer after Loading','Reached Customer Location','Returning for Loading after Unloading at Customer Point','Breakdown','Idle',NULL),
        v.reg_no
");
if (!$vehicles) {
    $vehicles = $db->query("SELECT id AS vehicle_id, reg_no, make, model, current_status, current_customer_id, current_customer_name, current_customer_city, breakdown_reason, status_remark, NULL AS trip_id, NULL AS trip_no, NULL AS trip_status, NULL AS from_location, NULL AS to_location, NULL AS customer_name, NULL AS customer_city, NULL AS trip_date, NULL AS total_weight, NULL AS driver_name FROM fleet_vehicles ORDER BY reg_no");
}
$vehicles = $vehicles->fetch_all(MYSQLI_ASSOC);

// Count and group by status
$counts = array_fill_keys(array_keys($statusOptions), 0);
$vehiclesByStatus = array_fill_keys(array_keys($statusOptions), []);
foreach ($vehicles as $v) {
    $rawStatus = $v['current_status'] ?? 'Idle';
    $s = fleetBoardStatus($rawStatus ?: 'Idle', $legacyStatusMap);
    if (!isset($statusOptions[$s])) $s = 'Idle';
    $counts[$s]++;
    $vehiclesByStatus[$s][] = $v;
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-map me-2"></i>Vehicle Status Board';</script>

<style>
.status-board { font-family: inherit; }

/* Summary bar */
.status-pills { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.status-pill {
    display:flex; align-items:center; gap:6px;
    padding:3px 9px !important; border-radius:14px !important;
    font-size:.64rem !important; font-weight:600 !important; cursor:pointer;
    border:1px solid transparent; transition:all .15s ease;
    line-height:1.1 !important;
}
.status-pill.active { transform:scale(1.02); box-shadow:0 1px 6px rgba(0,0,0,.08); }
.pill-loading    { background:#fff3cd; border-color:#ffc107; color:#856404; }
.pill-transit    { background:#cff4fc; border-color:#0dcaf0; color:#055160; }
.pill-waiting    { background:#f8d7da; border-color:#dc3545; color:#842029; }
.pill-planned    { background:#e2e3e5; border-color:#adb5bd; color:#383d41; }
.pill-idle       { background:#d1e7dd; border-color:#198754; color:#0a3622; }
.pill-repair     { background:#e2d9f3; border-color:#6f42c1; color:#432874; }
.pill-returning  { background:#ffe5d0; border-color:#fd7e14; color:#7a3800; }

/* Vehicle cards grid - compact and delicate */
.vehicles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 10px;
}

/* Vehicle card */
.vehicle-card {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.vehicle-card:hover {
    transform: translateY(-2px);
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.vc-top-bar {
    padding: 7px 9px 5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
}
.vc-reg {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.2px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    color: #1e293b;
}
.vc-trip-badge {
    display: inline-flex;
    align-items: center;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    padding: 1px 5px !important;
    font-size: 0.52rem !important;
    font-weight: 600 !important;
    color: #0369a1;
    background: #f0f9ff;
    line-height: 1.1 !important;
    text-decoration: none !important;
}
.vc-trip-badge:hover {
    color: #0284c7;
    background: #e0f2fe;
    border-color: #7dd3fc !important;
}

.vc-body {
    padding: 7px 9px 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
}

.vc-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.66rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 4px;
    width: fit-content;
    line-height: 1.2;
    margin-bottom: 2px;
}

.vc-cust-name {
    font-size: 0.72rem;
    font-weight: 600;
    color: #1e40af;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.vc-cust-city {
    font-size: 0.64rem;
    color: #64748b;
    font-weight: 400;
}

.vc-meta-row {
    display: flex;
    gap: 7px;
    font-size: 0.62rem;
    color: #64748b;
    flex-wrap: wrap;
    align-items: center;
}
.vc-meta-row span {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.vc-reason-badge {
    font-size: 0.64rem;
    color: #5c3b00;
    background: #fff8e1;
    border-left: 2px solid #6f42c1;
    padding: 2px 5px;
    border-radius: 3px;
    line-height: 1.2;
}
.vc-remark-badge {
    font-size: 0.64rem;
    color: #1f5131;
    background: #f0fdf4;
    border-left: 2px solid #198754;
    padding: 2px 5px;
    border-radius: 3px;
    line-height: 1.2;
}

.vc-no-trip {
    font-size: 0.64rem;
    color: #94a3b8;
    font-style: italic;
}

.vc-action-row {
    margin-top: auto;
    padding-top: 5px;
    border-top: 1px solid #f1f5f9;
}
.vc-btn-update {
    width: 100%;
    font-size: 0.66rem !important;
    padding: 2px 6px !important;
    line-height: 1.4 !important;
    border-radius: 4px !important;
    font-weight: 500 !important;
}

/* Filter bar */
.filter-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.filter-bar input { max-width:220px; font-size:.78rem; padding:3px 8px; }

.view-toggle .btn {
    min-width: 76px;
    font-size: .74rem;
    padding: 3px 8px;
}
.status-list-wrap { display:none; }

/* Kanban View Styles - Small & Delicate */
.kanban-board-wrap {
    display: none;
    overflow-x: auto;
    padding-bottom: 12px;
}
.kanban-columns {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    min-width: 1200px;
}
.kanban-col {
    flex: 1 1 0;
    min-width: 215px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 210px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.kanban-col-header {
    padding: 7px 10px;
    border-top-left-radius: 7px;
    border-top-right-radius: 7px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 700;
    font-size: 0.74rem;
    position: sticky;
    top: 0;
    z-index: 2;
}
.kanban-col-count {
    font-size: 0.66rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
    background: rgba(0,0,0,0.06);
}
.kanban-cards-list {
    padding: 7px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-height: 80px;
}
.kanban-card {
    background: #ffffff;
    border-radius: 6px;
    border: 1px solid #e9ecef;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    padding: 7px 8px;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    cursor: default;
}
.kanban-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(0,0,0,0.06);
    border-color: #cbd5e1;
}
.kanban-empty {
    text-align: center;
    color: #94a3b8;
    font-size: 0.68rem;
    padding: 16px 6px;
    font-style: italic;
}
</style>

<div class="status-board">

<!-- Header row -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold"><i class="bi bi-map me-2 text-success"></i>Vehicle Status Board</h5>
    <div class="d-flex gap-2">
        <div class="btn-group btn-group-sm view-toggle" role="group" aria-label="View mode">
            <button type="button" class="btn btn-outline-primary active" id="btnCardView" onclick="setViewMode('card')">
                <i class="bi bi-grid-3x3-gap me-1"></i>Cards
            </button>
            <button type="button" class="btn btn-outline-primary" id="btnKanbanView" onclick="setViewMode('kanban')">
                <i class="bi bi-kanban me-1"></i>Kanban
            </button>
            <button type="button" class="btn btn-outline-primary" id="btnListView" onclick="setViewMode('list')">
                <i class="bi bi-list-ul me-1"></i>List
            </button>
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
        <a href="fleet_trips.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list-ul me-1"></i>All Trips
        </a>
    </div>
</div>

<!-- Status pills summary -->
<div class="status-pills">
    <?php foreach ($statusOptions as $statusValue => $meta): ?>
    <div class="status-pill <?= $meta['pill'] ?> <?= ($counts[$statusValue] ?? 0)>0?'active':'' ?>" onclick='filterStatus(<?= json_encode($statusValue) ?>)'>
        <span><i class="bi <?= $meta['icon'] ?> me-1"></i><?= htmlspecialchars($meta['short']) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="status-pill" style="background:#fff;border-color:#dee2e6;color:#6c757d" onclick="filterStatus('')">
        <span><i class="bi bi-grid me-1"></i>All</span>
    </div>
</div>

<!-- Search filter -->
<div class="filter-bar mb-3">
    <input type="text" class="form-control form-control-sm" id="searchBox"
           placeholder="Search vehicle, customer, route..." oninput="filterCards()">
</div>

<!-- Vehicle Cards Grid -->
<div class="vehicles-grid" id="vehicleGrid">
<?php foreach ($vehicles as $v):
    $rawStatus = $v['current_status'] ?? 'Idle';
    $st      = fleetBoardStatus($rawStatus ?: 'Idle', $legacyStatusMap);
    $hasTrip = !empty($v['trip_id']);
    $boardCustomerName = fleetBoardPlain($v['current_customer_name'] ?: ($v['customer_name'] ?? ''));
    $boardCustomerCity = fleetBoardPlain($v['current_customer_city'] ?: ($v['customer_city'] ?? ''));
    $fromLocation = fleetBoardPlain($v['from_location'] ?? '');
    $toLocation = fleetBoardPlain($v['to_location'] ?? '');
    $breakdownReason = fleetBoardPlain($v['breakdown_reason'] ?? '');
    $statusRemark = fleetBoardPlain($v['status_remark'] ?? '');

    $stClass = $statusOptions[$st]['card'] ?? ($st === 'Planned' ? 'vc-status-planned' : 'vc-status-idle');
    $dotColor = $statusOptions[$st]['color'] ?? ($st === 'Planned' ? '#6c757d' : '#198754');
    $stLabel = $st === 'Idle' ? 'Available / Idle' : $st;
    $dataStatus = $st;
    $dataSearch = strtolower(($v['reg_no']??'').' '.$boardCustomerName.' '.$fromLocation.' '.$toLocation.' '.$boardCustomerCity.' '.fleetBoardPlain($v['driver_name']??'').' '.$breakdownReason.' '.$statusRemark);
    $modalArgs = [
        (int)$v['vehicle_id'],
        $v['reg_no'] ?? '',
        $boardCustomerName,
        $st,
        $statusRemark,
        $breakdownReason,
        (int)($v['current_customer_id'] ?? 0),
    ];
?>
<div class="vehicle-card" data-status="<?= htmlspecialchars($dataStatus) ?>" data-search="<?= htmlspecialchars($dataSearch) ?>" style="border-top: 3px solid <?= $dotColor ?>;">

    <!-- Top header row -->
    <div class="vc-top-bar">
        <div class="vc-reg"><?= htmlspecialchars($v['reg_no']) ?></div>
        <?php if ($hasTrip): ?>
        <a href="fleet_trips.php?action=view&id=<?= (int)$v['trip_id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_status.php') ?>" class="vc-trip-badge">
            <?= htmlspecialchars($v['trip_no']) ?>
        </a>
        <?php endif; ?>
    </div>

    <div class="vc-body">

        <!-- Status pill with icon -->
        <div class="vc-status-pill" style="background:<?= $dotColor ?>15; color:<?= $dotColor ?>; border:1px solid <?= $dotColor ?>30;">
            <i class="bi <?= $statusOptions[$st]['icon'] ?? 'bi-circle-fill' ?>"></i>
            <span><?= htmlspecialchars($stLabel) ?></span>
        </div>

        <?php if ($st === 'Breakdown' && $breakdownReason): ?>
        <div class="vc-reason-badge">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($breakdownReason) ?>
        </div>
        <?php endif; ?>

        <?php if ($st === 'Idle' && $statusRemark): ?>
        <div class="vc-remark-badge">
            <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($statusRemark) ?>
        </div>
        <?php endif; ?>

        <!-- Customer info -->
        <?php if ($boardCustomerName): ?>
        <div class="vc-cust-name" title="<?= htmlspecialchars($boardCustomerName . ($boardCustomerCity ? ' (' . $boardCustomerCity . ')' : '')) ?>">
            <i class="bi bi-building me-1 text-muted"></i><?= htmlspecialchars($boardCustomerName) ?>
            <?php if ($boardCustomerCity): ?>
            <span class="vc-cust-city">(<?= htmlspecialchars($boardCustomerCity) ?>)</span>
            <?php endif; ?>
        </div>
        <?php elseif (!$hasTrip && $st === 'Idle'): ?>
        <div class="vc-no-trip">No active trip</div>
        <?php endif; ?>

        <!-- Meta info (driver, weight, date) -->
        <?php if (!empty($v['driver_name']) || !empty($v['total_weight']) || !empty($v['trip_date'])): ?>
        <div class="vc-meta-row">
            <?php if (!empty($v['driver_name'])): ?>
            <span><i class="bi bi-person"></i><?= htmlspecialchars(fleetBoardPlain($v['driver_name'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($v['total_weight'])): ?>
            <span><i class="bi bi-speedometer2"></i><?= number_format((float)$v['total_weight'], 3) ?> MT</span>
            <?php endif; ?>
            <?php if (!empty($v['trip_date'])): ?>
            <span><i class="bi bi-calendar3"></i><?= date('d M', strtotime($v['trip_date'])) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Status update button -->
        <?php if (canDo('fleet_status','update') || canDo('fleet_vehicles','update') || isAdmin()): ?>
        <div class="vc-action-row">
            <button type="button" class="btn btn-sm btn-outline-success vc-btn-update" onclick='openStatusModal.apply(null, <?= htmlspecialchars(json_encode($modalArgs), ENT_QUOTES) ?>)'>
                <i class="bi bi-pencil-square me-1"></i>Update Status
            </button>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Vehicle Kanban Board View -->
<div class="kanban-board-wrap" id="kanbanBoardWrap">
    <div class="kanban-columns">
        <?php foreach ($statusOptions as $statusValue => $meta): 
            $colVehicles = $vehiclesByStatus[$statusValue] ?? [];
            $headerColor = $meta['color'];
            $headerTextColor = ($meta['text'] === '#fff') ? $meta['color'] : '#212529';
        ?>
        <div class="kanban-col" data-col-status="<?= htmlspecialchars($statusValue) ?>">
            <div class="kanban-col-header" style="border-top: 3px solid <?= $headerColor ?>; background: <?= $headerColor ?>18; color: <?= $headerTextColor ?>;">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi <?= $meta['icon'] ?>"></i>
                    <span><?= htmlspecialchars($meta['short']) ?></span>
                </div>
                <span class="kanban-col-count" style="background: <?= $headerColor ?>30; color: <?= $headerTextColor ?>;">
                    <span class="col-count-num"><?= count($colVehicles) ?></span>
                </span>
            </div>
            <div class="kanban-cards-list">
                <div class="kanban-empty" <?= !empty($colVehicles) ? 'style="display:none"' : '' ?>>
                    No vehicles <?= strtolower(htmlspecialchars($meta['short'])) ?>
                </div>
                <?php foreach ($colVehicles as $v):
                    $hasTrip = !empty($v['trip_id']);
                    $boardCustomerName = fleetBoardPlain($v['current_customer_name'] ?: ($v['customer_name'] ?? ''));
                    $boardCustomerCity = fleetBoardPlain($v['current_customer_city'] ?: ($v['customer_city'] ?? ''));
                    $fromLocation = fleetBoardPlain($v['from_location'] ?? '');
                    $toLocation = fleetBoardPlain($v['to_location'] ?? '');
                    $breakdownReason = fleetBoardPlain($v['breakdown_reason'] ?? '');
                    $statusRemark = fleetBoardPlain($v['status_remark'] ?? '');
                    $dataSearch = strtolower(($v['reg_no']??'').' '.$boardCustomerName.' '.$fromLocation.' '.$toLocation.' '.$boardCustomerCity.' '.fleetBoardPlain($v['driver_name']??'').' '.$breakdownReason.' '.$statusRemark.' '.($v['trip_no'] ?? ''));
                    $modalArgs = [
                        (int)$v['vehicle_id'],
                        $v['reg_no'] ?? '',
                        $boardCustomerName,
                        $statusValue,
                        $statusRemark,
                        $breakdownReason,
                        (int)($v['current_customer_id'] ?? 0),
                    ];
                ?>
                <div class="kanban-card" data-status="<?= htmlspecialchars($statusValue) ?>" data-search="<?= htmlspecialchars($dataSearch) ?>">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-bold font-monospace text-dark" style="font-size: 0.78rem;"><?= htmlspecialchars($v['reg_no']) ?></span>
                        <?php if ($hasTrip): ?>
                        <a href="fleet_trips.php?action=view&id=<?= (int)$v['trip_id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_status.php') ?>" class="vc-trip-badge">
                            <?= htmlspecialchars($v['trip_no']) ?>
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($statusValue === 'Breakdown' && $breakdownReason): ?>
                    <div class="vc-breakdown-reason py-1 px-2 mb-1" style="font-size: 0.66rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($breakdownReason) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($statusValue === 'Idle' && $statusRemark): ?>
                    <div class="vc-status-remark py-1 px-2 mb-1" style="font-size: 0.66rem;">
                        <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($statusRemark) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($boardCustomerName): ?>
                    <div class="text-truncate mb-1" style="font-size: 0.70rem; line-height: 1.25;">
                        <span class="text-muted"><i class="bi bi-building me-1"></i></span>
                        <strong class="text-primary"><?= htmlspecialchars($boardCustomerName) ?></strong>
                        <?php if ($boardCustomerCity): ?>
                        <span class="text-muted">(<?= htmlspecialchars($boardCustomerCity) ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2 text-muted mb-1" style="font-size: 0.62rem;">
                        <?php if (!empty($v['driver_name'])): ?>
                        <span><i class="bi bi-person me-1"></i><?= htmlspecialchars(fleetBoardPlain($v['driver_name'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($v['total_weight'])): ?>
                        <span><i class="bi bi-speedometer2 me-1"></i><?= number_format((float)$v['total_weight'], 3) ?> MT</span>
                        <?php endif; ?>
                        <?php if (!empty($v['trip_date'])): ?>
                        <span><i class="bi bi-calendar3 me-1"></i><?= date('d M', strtotime($v['trip_date'])) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (canDo('fleet_status','update') || canDo('fleet_vehicles','update') || isAdmin()): ?>
                    <div class="pt-1 border-top">
                        <button type="button" class="btn btn-sm btn-outline-success w-100 py-0" style="font-size: 0.66rem; line-height: 1.4; padding: 1px 4px;" onclick='openStatusModal.apply(null, <?= htmlspecialchars(json_encode($modalArgs), ENT_QUOTES) ?>)'>
                            <i class="bi bi-pencil-square me-1"></i>Update
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Vehicle List View -->
<div class="status-list-wrap" id="vehicleListWrap">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Vehicle</th>
                    <th>Status</th>
                    <th>Customer</th>
                    <th>Trip No</th>
                    <th>Driver</th>
                    <th class="text-end">Weight (MT)</th>
                    <th>Date</th>
                    <?php if (canDo('fleet_status','update') || canDo('fleet_vehicles','update') || isAdmin()): ?>
                    <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vehicles as $v):
                $rawStatus = $v['current_status'] ?? 'Idle';
                $st = fleetBoardStatus($rawStatus ?: 'Idle', $legacyStatusMap);
                $boardCustomerName = fleetBoardPlain($v['current_customer_name'] ?: ($v['customer_name'] ?? ''));
                $breakdownReason = fleetBoardPlain($v['breakdown_reason'] ?? '');
                $statusRemark = fleetBoardPlain($v['status_remark'] ?? '');
                $stLabel = $st === 'Idle' ? 'Available / Idle' : $st;
                $dataSearch = strtolower(($v['reg_no']??'').' '.$boardCustomerName.' '.fleetBoardPlain($v['driver_name']??'').' '.$breakdownReason.' '.$statusRemark.' '.($v['trip_no'] ?? ''));
                $modalArgs = [
                    (int)$v['vehicle_id'],
                    $v['reg_no'] ?? '',
                    $boardCustomerName,
                    $st,
                    $statusRemark,
                    $breakdownReason,
                    (int)($v['current_customer_id'] ?? 0),
                ];
            ?>
                <tr class="vehicle-row" data-status="<?= htmlspecialchars($st) ?>" data-search="<?= htmlspecialchars($dataSearch) ?>">
                    <td class="fw-semibold"><?= htmlspecialchars($v['reg_no']) ?></td>
                    <td><?= htmlspecialchars($stLabel) ?></td>
                    <td><?= htmlspecialchars($boardCustomerName ?: '-') ?></td>
                    <td>
                        <?php if (!empty($v['trip_id'])): ?>
<a href="fleet_trips.php?action=view&id=<?= (int)$v['trip_id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'fleet_status.php') ?>"><?= htmlspecialchars($v['trip_no']) ?></a>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(fleetBoardPlain($v['driver_name'] ?? '') ?: '-') ?></td>
                    <td class="text-end"><?= !empty($v['total_weight']) ? number_format((float)$v['total_weight'],3) : '-' ?></td>
                    <td><?= !empty($v['trip_date']) ? date('d M Y', strtotime($v['trip_date'])) : '-' ?></td>
                    <?php if (canDo('fleet_status','update') || canDo('fleet_vehicles','update') || isAdmin()): ?>
                    <td>
                        <button class="btn btn-sm btn-outline-success" onclick='openStatusModal.apply(null, <?= htmlspecialchars(json_encode($modalArgs), ENT_QUOTES) ?>)'>
                            <i class="bi bi-pencil-square me-1"></i>Update
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (empty($vehicles)): ?>
<div class="alert alert-info text-center mt-3">
    <i class="bi bi-truck me-2"></i>No active vehicles found. Add vehicles in Fleet Management.
</div>
<?php endif; ?>

</div><!-- /status-board -->

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#1E3A8A;color:#fff">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Update Vehicle Status</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="modal_vehicle_id">
        <div class="mb-3">
          <label class="form-label fw-bold">Vehicle</label>
          <input type="text" id="modal_reg_no" class="form-control" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">New Status <span class="text-danger">*</span></label>
          <div class="d-flex flex-wrap gap-2" id="statusBtnGroup">
            <?php
            foreach ($statusOptions as $val => $meta):
            ?>
            <button type="button"
                class="modal-status-btn btn btn-sm"
                style="background:<?= $meta['color'] ?>;color:<?= $meta['text'] ?>;border:2px solid <?= $meta['color'] ?>;opacity:.6"
                data-status="<?= $val ?>"
                onclick="selectModalStatus(this)">
                <i class="bi <?= $meta['icon'] ?> me-1"></i><?= htmlspecialchars($meta['short']) ?>
            </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="modal_status">
        </div>
        <!-- Customer dropdown — shown when status is Loading/Despatched/Reached/Returning -->
        <div class="mb-3" id="customerSection" style="display:none">
          <label class="form-label fw-bold">Customer</label>
          <select id="modal_customer_id" class="form-select">
            <option value="none">None (No Customer)</option>
            <option value="">— Keep existing customer —</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['vendor_name']) ?><?= $c['ship_city'] ? ' — '.htmlspecialchars($c['ship_city']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3" id="breakdownSection" style="display:none">
          <label class="form-label fw-bold">Breakdown Reason <span class="text-danger">*</span></label>
          <textarea id="modal_breakdown_reason" class="form-control" rows="3" placeholder="Enter reason for breakdown"></textarea>
        </div>
        <div class="mb-3" id="remarkSection" style="display:none">
          <label class="form-label fw-bold">Idle Remark <span class="text-danger">*</span></label>
          <textarea id="modal_status_remark" class="form-control" rows="3" placeholder="Enter idle remark"></textarea>
        </div>
        <div id="modal_current_customer" class="text-muted small"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success px-4" onclick="saveStatus()">
            <i class="bi bi-check2 me-1"></i>Update Status
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Open modal ────────────────────────────────────────────────────
function openStatusModal(vehicleId, regNo, currentCustomer, currentStatus, statusRemark, breakdownReason, currentCustomerId) {
    document.getElementById('modal_vehicle_id').value = vehicleId;
    document.getElementById('modal_reg_no').value    = regNo;
    document.getElementById('modal_status').value    = '';
    document.getElementById('modal_customer_id').value = currentCustomerId ? currentCustomerId : 'none';
    document.getElementById('modal_status_remark').value = statusRemark || '';
    document.getElementById('modal_breakdown_reason').value = breakdownReason || '';
    document.getElementById('customerSection').style.display = 'none';
    document.getElementById('breakdownSection').style.display = 'none';
    document.getElementById('remarkSection').style.display = 'none';
    document.getElementById('modal_current_customer').textContent =
        currentCustomer ? 'Current customer: ' + currentCustomer : 'Current customer: None';

    // Reset all buttons
    document.querySelectorAll('.modal-status-btn').forEach(function(b) {
        b.style.opacity = '0.6';
        b.style.transform = 'scale(1)';
        // Pre-select current
        if (b.dataset.status === currentStatus) {
            b.style.opacity = '1';
            b.style.transform = 'scale(1.05)';
            document.getElementById('modal_status').value = currentStatus;
            toggleCustomerSection(currentStatus);
        }
    });

    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

function selectModalStatus(btn) {
    document.querySelectorAll('.modal-status-btn').forEach(function(b) {
        b.style.opacity = '0.6';
        b.style.transform = 'scale(1)';
    });
    btn.style.opacity = '1';
    btn.style.transform = 'scale(1.05)';
    var st = btn.dataset.status;
    document.getElementById('modal_status').value = st;
    toggleCustomerSection(st);
}

function toggleCustomerSection(st) {
    var showCustomer = st && st !== 'Idle';
    document.getElementById('customerSection').style.display = showCustomer ? '' : 'none';
    document.getElementById('breakdownSection').style.display = st === 'Breakdown' ? '' : 'none';
    document.getElementById('remarkSection').style.display = st === 'Idle' ? '' : 'none';
}

// ── Save status ───────────────────────────────────────────────────
function saveStatus() {
    var vehicleId   = document.getElementById('modal_vehicle_id').value;
    var status     = document.getElementById('modal_status').value;
    var customerId = document.getElementById('modal_customer_id').value;
    var statusRemark = document.getElementById('modal_status_remark').value.trim();
    var breakdownReason = document.getElementById('modal_breakdown_reason').value.trim();

    if (!vehicleId || !status) { alert('Please select a status.'); return; }
    if (status === 'Idle' && !statusRemark) { alert('Please enter idle remark.'); return; }
    if (status === 'Breakdown' && !breakdownReason) { alert('Please enter breakdown reason.'); return; }

    var btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Updating...';

    var fd = new FormData();
    fd.append('vehicle_id', vehicleId);
    fd.append('status', status);
    fd.append('customer_id', customerId);
    fd.append('status_remark', statusRemark);
    fd.append('breakdown_reason', breakdownReason);

    fetch('fleet_status.php?ajax=setstatus', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.ok) {
                bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
                location.reload();
            } else {
                alert('Error: ' + (d.msg || 'Update failed'));
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Update Status';
            }
        }).catch(function() {
            alert('Request failed.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Update Status';
        });
}

// ── View Mode & Persistence ──────────────────────────────────────
var activeFilter = '';
var viewMode = 'card';

function setViewMode(mode) {
    viewMode = ['card', 'kanban', 'list'].includes(mode) ? mode : 'card';
    try {
        localStorage.setItem('fleet_status_board_view', viewMode);
    } catch (e) {}

    var cardGrid = document.getElementById('vehicleGrid');
    var kanbanWrap = document.getElementById('kanbanBoardWrap');
    var listWrap = document.getElementById('vehicleListWrap');

    if (cardGrid) cardGrid.style.display = viewMode === 'card' ? 'grid' : 'none';
    if (kanbanWrap) kanbanWrap.style.display = viewMode === 'kanban' ? 'block' : 'none';
    if (listWrap) listWrap.style.display = viewMode === 'list' ? 'block' : 'none';

    var btnCard = document.getElementById('btnCardView');
    var btnKanban = document.getElementById('btnKanbanView');
    var btnList = document.getElementById('btnListView');

    if (btnCard) btnCard.classList.toggle('active', viewMode === 'card');
    if (btnKanban) btnKanban.classList.toggle('active', viewMode === 'kanban');
    if (btnList) btnList.classList.toggle('active', viewMode === 'list');
}

// ── Filter by status pill ─────────────────────────────────────────
function filterStatus(status) {
    activeFilter = status;
    document.querySelectorAll('.status-pills .status-pill').forEach(function(pill) {
        pill.classList.remove('active');
    });
    // Find pill clicked or match
    if (event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }
    filterCards();
}

function filterCards() {
    var q = (document.getElementById('searchBox').value || '').toLowerCase().trim();

    // 1. Filter Card View
    document.querySelectorAll('.vehicle-card').forEach(function(c) {
        var st = c.dataset.status || '';
        var matchStatus = !activeFilter ||
            st === activeFilter ||
            (activeFilter === 'Idle' && !st);
        var matchSearch = !q || (c.dataset.search || '').indexOf(q) !== -1;
        c.style.display = (matchStatus && matchSearch) ? '' : 'none';
    });

    // 2. Filter List View
    document.querySelectorAll('.vehicle-row').forEach(function(r) {
        var st = r.dataset.status || '';
        var matchStatus = !activeFilter ||
            st === activeFilter ||
            (activeFilter === 'Idle' && !st);
        var matchSearch = !q || (r.dataset.search || '').indexOf(q) !== -1;
        r.style.display = (matchStatus && matchSearch) ? '' : 'none';
    });

    // 3. Filter Kanban View & Update Column Counts
    document.querySelectorAll('.kanban-col').forEach(function(col) {
        var colStatus = col.dataset.colStatus || '';
        var isColVisibleByStatus = !activeFilter || colStatus === activeFilter;
        var visibleCount = 0;

        col.querySelectorAll('.kanban-card').forEach(function(kc) {
            var st = kc.dataset.status || '';
            var matchStatus = !activeFilter || st === activeFilter;
            var matchSearch = !q || (kc.dataset.search || '').indexOf(q) !== -1;
            var showCard = matchStatus && matchSearch;
            kc.style.display = showCard ? '' : 'none';
            if (showCard) visibleCount++;
        });

        // Update count badge
        var countEl = col.querySelector('.col-count-num');
        if (countEl) countEl.textContent = visibleCount;

        // Show/hide empty message
        var emptyEl = col.querySelector('.kanban-empty');
        if (emptyEl) {
            emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
        }

        // If filtering by a specific status, optionally highlight or dim other columns
        if (activeFilter && !isColVisibleByStatus) {
            col.style.opacity = '0.35';
        } else {
            col.style.opacity = '1';
        }
    });
}

// ── Restore saved view preference ─────────────────────────────────
(function() {
    try {
        var saved = localStorage.getItem('fleet_status_board_view');
        if (saved && ['card', 'kanban', 'list'].includes(saved)) {
            setViewMode(saved);
        }
    } catch (e) {}
})();

// ── Auto-refresh every 2 minutes ──────────────────────────────────
setTimeout(function(){ location.reload(); }, 120000);
</script>

<?php include '../includes/footer.php'; ?>
