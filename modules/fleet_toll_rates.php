<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_trips', 'view');

function normDateForSql(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    return '';
}

$db->query("CREATE TABLE IF NOT EXISTS fleet_vendor_destination_toll_rates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id   INT NOT NULL,
    to_location VARCHAR(255) NOT NULL,
    toll_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status      ENUM('Active','Inactive') DEFAULT 'Active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vendor_destination (vendor_id, to_location),
    KEY idx_vendor_status (vendor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_toll_rate'])) {
    requirePerm('fleet_trips', 'update');
    $id = (int)($_POST['id'] ?? 0);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $to_location = trim((string)($_POST['to_location'] ?? ''));
    $toll_amount = (float)($_POST['toll_amount'] ?? 0);
    $status = (($_POST['status'] ?? 'Active') === 'Inactive') ? 'Inactive' : 'Active';

    if ($vendor_id <= 0 || $to_location === '') {
        showAlert('danger', 'Vendor and To Location are required.');
        redirect('fleet_toll_rates.php');
    }
    $vendor_sql = (int)$vendor_id;
    $to_sql = $db->real_escape_string($to_location);
    $amt_sql = round($toll_amount, 2);
    $status_sql = $db->real_escape_string($status);

    if ($id > 0) {
        $db->query("UPDATE fleet_vendor_destination_toll_rates
            SET vendor_id=$vendor_sql, to_location='$to_sql', toll_amount=$amt_sql, status='$status_sql'
            WHERE id=$id");
    } else {
        $db->query("INSERT INTO fleet_vendor_destination_toll_rates (vendor_id, to_location, toll_amount, status)
            VALUES ($vendor_sql, '$to_sql', $amt_sql, '$status_sql')
            ON DUPLICATE KEY UPDATE
                toll_amount=VALUES(toll_amount),
                status=VALUES(status)");
    }
    showAlert('success', 'Toll rate saved.');
    redirect('fleet_toll_rates.php');
}

$backfill_preview_count = null;
$backfill_preview_error = '';
$backfill_debug = null;
$backfill_filters = [
    'vendor_id' => 0,
    'from_date' => '',
    'to_date' => '',
    'only_zero' => 1,
    'ignore_destination' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_backfill_toll'])) {
    requirePerm('fleet_trips', 'update');
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $from_date_raw = trim((string)($_POST['from_date'] ?? ''));
    $to_date_raw = trim((string)($_POST['to_date'] ?? ''));
    $from_date = normDateForSql($from_date_raw);
    $to_date = normDateForSql($to_date_raw);
    $only_zero = isset($_POST['only_zero']) ? 1 : 0;
    $ignore_destination = isset($_POST['ignore_destination']) ? 1 : 0;

    $backfill_filters = [
        'vendor_id' => $vendor_id,
        'from_date' => ($from_date !== '' ? $from_date : $from_date_raw),
        'to_date' => ($to_date !== '' ? $to_date : $to_date_raw),
        'only_zero' => $only_zero,
        'ignore_destination' => $ignore_destination,
    ];

    if ($vendor_id <= 0 || $from_date === '' || $to_date === '') {
        showAlert('danger', 'Vendor, From Date, and To Date are required for backfill.');
    } else {
        $where = "t.vendor_id=$vendor_id
            AND t.trip_date BETWEEN '" . $db->real_escape_string($from_date) . "' AND '" . $db->real_escape_string($to_date) . "'
            AND t.status <> 'Cancelled'";
        if ($only_zero) $where .= " AND COALESCE(t.toll_amount,0)=0";
        $join_cond = "r.vendor_id=t.vendor_id AND r.status='Active'";
        if (!$ignore_destination) {
            $join_cond .= " AND (
                LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(r.to_location),' ',''),',',''),'.',''),'-',''),'/','')) =
                LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(t.to_location),' ',''),',',''),'.',''),'-',''),'/',''))
                OR
                INSTR(
                    LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(t.to_location),' ',''),',',''),'.',''),'-',''),'/','')),
                    LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(r.to_location),' ',''),',',''),'.',''),'-',''),'/',''))
                ) > 0
                OR
                INSTR(
                    LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(r.to_location),' ',''),',',''),'.',''),'-',''),'/','')),
                    LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(t.to_location),' ',''),',',''),'.',''),'-',''),'/',''))
                ) > 0
            )";
        }
        $sql = "SELECT COUNT(*) AS c
            FROM fleet_trips t
            INNER JOIN fleet_vendor_destination_toll_rates r
                ON $join_cond
            WHERE $where";
        $res = $db->query($sql);
        if ($res) {
            $row = $res->fetch_assoc();
            $backfill_preview_count = (int)($row['c'] ?? 0);
            $trip_where = "vendor_id=$vendor_id
                AND trip_date BETWEEN '" . $db->real_escape_string($from_date) . "' AND '" . $db->real_escape_string($to_date) . "'
                AND status <> 'Cancelled'";
            $total_trips = (int)(($db->query("SELECT COUNT(*) c FROM fleet_trips WHERE $trip_where")->fetch_assoc())['c'] ?? 0);
            $zero_toll_trips = (int)(($db->query("SELECT COUNT(*) c FROM fleet_trips WHERE $trip_where AND COALESCE(toll_amount,0)=0")->fetch_assoc())['c'] ?? 0);
            $active_rates = (int)(($db->query("SELECT COUNT(*) c FROM fleet_vendor_destination_toll_rates WHERE vendor_id=$vendor_id AND status='Active'")->fetch_assoc())['c'] ?? 0);
            $backfill_debug = [
                'total_trips' => $total_trips,
                'zero_toll_trips' => $zero_toll_trips,
                'active_rates' => $active_rates,
            ];
            showAlert('info', 'Preview result: ' . $backfill_preview_count . ' trip(s) match current filters.');
        } else {
            $backfill_preview_error = (string)$db->error;
            $backfill_preview_count = 0;
            showAlert('danger', 'Preview query failed: ' . $backfill_preview_error);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_backfill_toll'])) {
    requirePerm('fleet_trips', 'update');
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $from_date = normDateForSql((string)($_POST['from_date'] ?? ''));
    $to_date = normDateForSql((string)($_POST['to_date'] ?? ''));
    $only_zero = isset($_POST['only_zero']) ? 1 : 0;
    $ignore_destination = isset($_POST['ignore_destination']) ? 1 : 0;

    if ($vendor_id <= 0 || $from_date === '' || $to_date === '') {
        showAlert('danger', 'Invalid backfill filters.');
        redirect('fleet_toll_rates.php');
    }

    $where = "t.vendor_id=$vendor_id
        AND t.trip_date BETWEEN '" . $db->real_escape_string($from_date) . "' AND '" . $db->real_escape_string($to_date) . "'
        AND t.status <> 'Cancelled'";
    if ($only_zero) $where .= " AND COALESCE(t.toll_amount,0)=0";

    $join_cond = "r.vendor_id=t.vendor_id AND r.status='Active'";
    if (!$ignore_destination) {
        $join_cond .= " AND (
            LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(r.to_location),' ',''),',',''),'.',''),'-',''),'/','')) =
            LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(t.to_location),' ',''),',',''),'.',''),'-',''),'/',''))
            OR
            INSTR(
                LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(t.to_location),' ',''),',',''),'.',''),'-',''),'/','')),
                LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(r.to_location),' ',''),',',''),'.',''),'-',''),'/',''))
            ) > 0
            OR
            INSTR(
                LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(r.to_location),' ',''),',',''),'.',''),'-',''),'/','')),
                LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(t.to_location),' ',''),',',''),'.',''),'-',''),'/',''))
            ) > 0
        )";
    }
    $sql = "UPDATE fleet_trips t
        INNER JOIN fleet_vendor_destination_toll_rates r
            ON $join_cond
        SET t.toll_amount = r.toll_amount
        WHERE $where";
    $ok = $db->query($sql);
    if (!$ok) {
        showAlert('danger', 'Backfill failed: ' . $db->error);
        redirect('fleet_toll_rates.php');
    }
    $affected = (int)$db->affected_rows;
    showAlert('success', "Toll backfill completed. Updated $affected trip(s).");
    redirect('fleet_toll_rates.php');
}

if (isset($_GET['delete'])) {
    requirePerm('fleet_trips', 'delete');
    $did = (int)$_GET['delete'];
    if ($did > 0) {
        $db->query("DELETE FROM fleet_vendor_destination_toll_rates WHERE id=$did");
        showAlert('success', 'Toll rate deleted.');
    }
    redirect('fleet_toll_rates.php');
}

$vendors = $db->query("SELECT id, vendor_name FROM fleet_customers_master WHERE status='Active' ORDER BY vendor_name ASC")->fetch_all(MYSQLI_ASSOC);
$rows = $db->query("SELECT r.*, v.vendor_name
    FROM fleet_vendor_destination_toll_rates r
    LEFT JOIN fleet_customers_master v ON r.vendor_id=v.id
    ORDER BY v.vendor_name ASC, r.to_location ASC")->fetch_all(MYSQLI_ASSOC);

$edit = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    if ($eid > 0) {
        $edit = $db->query("SELECT * FROM fleet_vendor_destination_toll_rates WHERE id=$eid LIMIT 1")->fetch_assoc();
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-cash-coin me-2"></i>Toll Rate Master';</script>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Vendor + Destination Toll Rates</h5>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end" data-skip-saving-overlay>
            <input type="hidden" name="save_toll_rate" value="1">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="col-12 col-md-4">
                <label class="form-label">Vendor *</label>
                <select name="vendor_id" class="form-select" required>
                    <option value="">-- Select Vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= (int)($edit['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($v['vendor_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">To Location *</label>
                <input type="text" name="to_location" class="form-control" required value="<?= htmlspecialchars($edit['to_location'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Toll (₹)</label>
                <input type="number" name="toll_amount" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars((string)($edit['toll_amount'] ?? '0.00')) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="Active" <?= (($edit['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= (($edit['status'] ?? '') === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save Toll Rate</button>
                <?php if ($edit): ?>
                <a href="fleet_toll_rates.php" class="btn btn-outline-secondary ms-1">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3 border-warning">
    <div class="card-header bg-warning-subtle fw-semibold">
        <i class="bi bi-hourglass-split me-1"></i>Selective Backfill for Past Trips
    </div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">Vendor *</label>
                <select name="vendor_id" class="form-select" required>
                    <option value="">-- Select Vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= (int)$backfill_filters['vendor_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($v['vendor_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">From Date *</label>
                <input type="date" name="from_date" class="form-control" required value="<?= htmlspecialchars($backfill_filters['from_date']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">To Date *</label>
                <input type="date" name="to_date" class="form-control" required value="<?= htmlspecialchars($backfill_filters['to_date']) ?>">
            </div>
            <div class="col-12 col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="only_zero" id="onlyZeroChk" <?= $backfill_filters['only_zero'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="onlyZeroChk">Only Toll=0</label>
                </div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="ignore_destination" id="ignoreDestChk" <?= !empty($backfill_filters['ignore_destination']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ignoreDestChk">Ignore Destination</label>
                </div>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" name="preview_backfill_toll" value="1" class="btn btn-outline-primary">
                    <i class="bi bi-search me-1"></i>Preview Count
                </button>
                <?php if ($backfill_preview_count !== null): ?>
                <span class="badge bg-info text-dark align-self-center">Matching Trips: <?= (int)$backfill_preview_count ?></span>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($backfill_preview_count !== null): ?>
        <div class="mt-2 small <?= $backfill_preview_error !== '' ? 'text-danger' : 'text-muted' ?>">
            <?php if ($backfill_preview_error !== ''): ?>
                Preview error: <?= htmlspecialchars($backfill_preview_error) ?>
            <?php else: ?>
                Preview completed. Matching trips found: <strong><?= (int)$backfill_preview_count ?></strong>.
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (is_array($backfill_debug)): ?>
        <div class="mt-1 small text-muted">
            Trips in range: <strong><?= (int)$backfill_debug['total_trips'] ?></strong>,
            Trips with Toll=0: <strong><?= (int)$backfill_debug['zero_toll_trips'] ?></strong>,
            Active Toll Rates for Vendor: <strong><?= (int)$backfill_debug['active_rates'] ?></strong>
        </div>
        <?php endif; ?>

        <?php if ($backfill_preview_count !== null && $backfill_preview_count > 0): ?>
        <form method="post" class="mt-2" data-skip-saving-overlay onsubmit="return confirm('Apply toll backfill to <?= (int)$backfill_preview_count ?> trip(s)?');">
            <input type="hidden" name="apply_backfill_toll" value="1">
            <input type="hidden" name="vendor_id" value="<?= (int)$backfill_filters['vendor_id'] ?>">
            <input type="hidden" name="from_date" value="<?= htmlspecialchars($backfill_filters['from_date']) ?>">
            <input type="hidden" name="to_date" value="<?= htmlspecialchars($backfill_filters['to_date']) ?>">
            <?php if ($backfill_filters['only_zero']): ?><input type="hidden" name="only_zero" value="1"><?php endif; ?>
            <?php if (!empty($backfill_filters['ignore_destination'])): ?><input type="hidden" name="ignore_destination" value="1"><?php endif; ?>
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-lightning-charge me-1"></i>Apply Backfill
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Vendor</th>
                    <th>To Location</th>
                    <th class="text-end">Toll (₹)</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['vendor_name'] ?? ('Vendor #'.$r['vendor_id'])) ?></td>
                    <td><?= htmlspecialchars($r['to_location']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['toll_amount'], 2) ?></td>
                    <td><span class="badge bg-<?= $r['status'] === 'Active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td>
                        <a href="fleet_toll_rates.php?edit=<?= (int)$r['id'] ?>" class="btn btn-action btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <a href="fleet_toll_rates.php?delete=<?= (int)$r['id'] ?>" class="btn btn-action btn-outline-danger" onclick="return confirm('Delete this toll rate?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-muted p-3">No toll rates defined yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
