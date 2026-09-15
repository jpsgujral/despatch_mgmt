<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

$db = getDB();
requirePerm('global_search', 'view');
$q_raw = trim((string)($_GET['q'] ?? ''));
$q_sql = $db->real_escape_string($q_raw);
$q_like = '%' . $q_sql . '%';
$uid = (int)($_SESSION['user_id'] ?? 0);

function gsFetchAll(mysqli $db, string $sql): array {
    $res = $db->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

$groups = [];

if ($q_raw !== '') {
    if (isAdmin() || canDo('despatch', 'view')) {
        $rows = gsFetchAll($db, "SELECT d.id, d.challan_no, d.despatch_no, d.despatch_date, d.status, d.consignee_name
            FROM despatch_orders d
            WHERE (
                d.challan_no LIKE '$q_like'
                OR d.despatch_no LIKE '$q_like'
                OR d.consignee_name LIKE '$q_like'
                OR d.vehicle_no LIKE '$q_like'
            ) " . despatchFilter('d') . "
            ORDER BY d.despatch_date DESC, d.id DESC
            LIMIT 12");
        if ($rows) {
            $groups[] = [
                'label' => 'Despatch Orders',
                'icon'  => 'bi-send-check',
                'rows'  => array_map(function ($r) {
                    return [
                        'title' => trim((string)($r['challan_no'] ?: $r['despatch_no'] ?: ('#' . $r['id']))),
                        'meta'  => trim((string)($r['consignee_name'] ?? '')),
                        'sub'   => trim((string)(($r['despatch_date'] ? date('d/m/Y', strtotime($r['despatch_date'])) : '') . (($r['status'] ?? '') ? ' • ' . $r['status'] : ''))),
                        'link'  => 'despatch.php?action=view&id=' . (int)$r['id'],
                    ];
                }, $rows),
            ];
        }
    }

    if (isAdmin() || canDo('fleet_trips', 'view')) {
        $trip_filter = canViewAll('trips') ? '' : " AND t.created_by=$uid";
        $rows = gsFetchAll($db, "SELECT t.id, t.trip_no, t.trip_date, t.status, t.customer_name, v.reg_no
            FROM fleet_trips t
            LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
            WHERE (
                t.trip_no LIKE '$q_like'
                OR t.customer_name LIKE '$q_like'
                OR v.reg_no LIKE '$q_like'
            )$trip_filter
            ORDER BY t.trip_date DESC, t.id DESC
            LIMIT 12");
        if ($rows) {
            $groups[] = [
                'label' => 'Trip Orders',
                'icon'  => 'bi-signpost-split',
                'rows'  => array_map(function ($r) {
                    return [
                        'title' => trim((string)($r['trip_no'] ?: ('#' . $r['id']))),
                        'meta'  => trim((string)(($r['reg_no'] ?? '') . (($r['customer_name'] ?? '') ? ' • ' . $r['customer_name'] : ''))),
                        'sub'   => trim((string)(($r['trip_date'] ? date('d/m/Y', strtotime($r['trip_date'])) : '') . (($r['status'] ?? '') ? ' • ' . $r['status'] : ''))),
                        'link'  => 'fleet_trips.php?action=view&id=' . (int)$r['id'],
                    ];
                }, $rows),
            ];
        }
    }

    if (isAdmin() || canDo('fleet_vehicles', 'view')) {
        $rows = gsFetchAll($db, "SELECT id, reg_no, make, model, year
            FROM fleet_vehicles
            WHERE (
                reg_no LIKE '$q_like'
                OR make LIKE '$q_like'
                OR model LIKE '$q_like'
            )
            ORDER BY reg_no ASC
            LIMIT 12");
        if ($rows) {
            $groups[] = [
                'label' => 'Vehicles',
                'icon'  => 'bi-truck',
                'rows'  => array_map(function ($r) {
                    return [
                        'title' => trim((string)($r['reg_no'] ?: ('Vehicle #' . $r['id']))),
                        'meta'  => trim((string)(($r['make'] ?? '') . ' ' . ($r['model'] ?? ''))),
                        'sub'   => trim((string)($r['year'] ?? '')),
                        'link'  => 'fleet_vehicles.php?action=view&id=' . (int)$r['id'],
                    ];
                }, $rows),
            ];
        }
    }

    if (isAdmin() || canDo('transporter_payments', 'view')) {
        $tp_filter = canViewAll('transporter_payments') ? '' : " AND tp.created_by=$uid";
        $rows = gsFetchAll($db, "SELECT tp.id, tp.payment_no, tp.payment_date, tp.reference_no, tp.amount, tp.challan_no, tr.transporter_name
            FROM transporter_payments tp
            LEFT JOIN transporters tr ON tp.transporter_id=tr.id
            WHERE (
                tp.payment_no LIKE '$q_like'
                OR tp.reference_no LIKE '$q_like'
                OR tp.challan_no LIKE '$q_like'
                OR tr.transporter_name LIKE '$q_like'
            )$tp_filter
            ORDER BY tp.payment_date DESC, tp.id DESC
            LIMIT 12");
        if ($rows) {
            $groups[] = [
                'label' => 'Transporter Payments',
                'icon'  => 'bi-cash-coin',
                'rows'  => array_map(function ($r) {
                    return [
                        'title' => trim((string)($r['payment_no'] ?: ('#' . $r['id']))),
                        'meta'  => trim((string)(($r['transporter_name'] ?? '') . (($r['challan_no'] ?? '') ? ' • ' . $r['challan_no'] : ''))),
                        'sub'   => trim((string)(($r['payment_date'] ? date('d/m/Y', strtotime($r['payment_date'])) : '') . ' • ₹' . number_format((float)($r['amount'] ?? 0), 2))),
                        'link'  => 'transporter_payments.php',
                    ];
                }, $rows),
            ];
        }
    }

    if (isAdmin() || canDo('fleet_customers_master', 'view')) {
        $rows = gsFetchAll($db, "SELECT id, vendor_name, ship_city, ship_gstin
            FROM fleet_customers_master
            WHERE (
                vendor_name LIKE '$q_like'
                OR ship_city LIKE '$q_like'
                OR ship_gstin LIKE '$q_like'
            )
            ORDER BY vendor_name ASC
            LIMIT 12");
        if ($rows) {
            $groups[] = [
                'label' => 'Fleet Customers',
                'icon'  => 'bi-person-lines-fill',
                'rows'  => array_map(function ($r) {
                    return [
                        'title' => trim((string)($r['vendor_name'] ?: ('Customer #' . $r['id']))),
                        'meta'  => trim((string)($r['ship_city'] ?? '')),
                        'sub'   => trim((string)($r['ship_gstin'] ?? '')),
                        'link'  => 'fleet_customers_master.php?action=edit&id=' . (int)$r['id'],
                    ];
                }, $rows),
            ];
        }
    }

    if (isAdmin() || canDo('transporters', 'view')) {
        $rows = gsFetchAll($db, "SELECT id, transporter_name, phone, gstin
            FROM transporters
            WHERE (
                transporter_name LIKE '$q_like'
                OR phone LIKE '$q_like'
                OR gstin LIKE '$q_like'
            )
            ORDER BY transporter_name ASC
            LIMIT 12");
        if ($rows) {
            $groups[] = [
                'label' => 'Transporters',
                'icon'  => 'bi-truck-front',
                'rows'  => array_map(function ($r) {
                    return [
                        'title' => trim((string)($r['transporter_name'] ?: ('Transporter #' . $r['id']))),
                        'meta'  => trim((string)($r['phone'] ?? '')),
                        'sub'   => trim((string)($r['gstin'] ?? '')),
                        'link'  => 'transporters.php?action=edit&id=' . (int)$r['id'],
                    ];
                }, $rows),
            ];
        }
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-search me-2"></i>Global Search';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Search Results</h5>
    <?php if ($q_raw !== ''): ?>
    <span class="text-muted">Showing results for <strong><?= htmlspecialchars($q_raw) ?></strong></span>
    <?php endif; ?>
</div>

<?php if ($q_raw === ''): ?>
<div class="card">
    <div class="card-body py-4 text-center text-muted">
        <i class="bi bi-search fs-3 d-block mb-2"></i>
        Type a challan no, trip no, vehicle no, payment no, customer, or transporter in the top search bar.
    </div>
</div>
<?php elseif (!$groups): ?>
<div class="card">
    <div class="card-body py-4 text-center text-muted">
        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
        No matching records found for <strong><?= htmlspecialchars($q_raw) ?></strong>.
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($groups as $group): ?>
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="fw-semibold"><i class="bi <?= htmlspecialchars($group['icon']) ?> me-2"></i><?= htmlspecialchars($group['label']) ?></div>
                <span class="badge bg-secondary"><?= count($group['rows']) ?></span>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($group['rows'] as $row): ?>
                <a href="<?= htmlspecialchars($row['link']) ?>" class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($row['title']) ?></div>
                            <?php if ($row['meta'] !== ''): ?><div class="small text-muted"><?= htmlspecialchars($row['meta']) ?></div><?php endif; ?>
                            <?php if ($row['sub'] !== ''): ?><div class="small text-muted"><?= htmlspecialchars($row['sub']) ?></div><?php endif; ?>
                        </div>
                        <span class="text-muted"><i class="bi bi-arrow-up-right-circle"></i></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
