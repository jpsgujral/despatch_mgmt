<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (!isAdmin()) { showAlert('danger','Admin access required.'); redirect('index.php'); }
$db = getDB();

// Ensure error log table exists
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

/* ── Actions ── */
if (isset($_GET['resolve'])) {
    $db->query("UPDATE app_error_log SET resolved=1 WHERE id=".(int)$_GET['resolve']);
    showAlert('success','Marked as resolved.');
    redirect('error_log.php');
}
if (isset($_GET['resolve_all'])) {
    $db->query("UPDATE app_error_log SET resolved=1 WHERE resolved=0");
    showAlert('success','All errors marked as resolved.');
    redirect('error_log.php');
}
if (isset($_GET['delete'])) {
    $db->query("DELETE FROM app_error_log WHERE id=".(int)$_GET['delete']);
    showAlert('success','Entry deleted.');
    redirect('error_log.php');
}
if (isset($_GET['clear_resolved'])) {
    $db->query("DELETE FROM app_error_log WHERE resolved=1");
    showAlert('success','Resolved entries cleared.');
    redirect('error_log.php');
}
if (isset($_GET['clear_all'])) {
    $db->query("TRUNCATE TABLE app_error_log");
    // Also clear the log file
    $logfile = __DIR__ . '/../logs/app_errors.log';
    if (file_exists($logfile)) file_put_contents($logfile, '');
    showAlert('success','All error logs cleared.');
    redirect('error_log.php');
}

/* ── Filters ── */
$filter_level    = $_GET['level']    ?? '';
$filter_resolved = $_GET['resolved'] ?? '0';
$filter_date     = $_GET['date']     ?? '';

$where = 'WHERE 1';
if ($filter_level)              $where .= " AND level='".$db->real_escape_string($filter_level)."'";
if ($filter_resolved !== '')    $where .= " AND resolved=".(int)$filter_resolved;
if ($filter_date)               $where .= " AND DATE(logged_at)='".$db->real_escape_string($filter_date)."'";

$errors = $db->query("SELECT e.*, u.full_name FROM app_error_log e
    LEFT JOIN app_users u ON e.user_id=u.id
    $where ORDER BY e.logged_at DESC LIMIT 500")->fetch_all(MYSQLI_ASSOC);

$counts = $db->query("SELECT level, COUNT(*) c FROM app_error_log WHERE resolved=0 GROUP BY level")->fetch_all(MYSQLI_ASSOC);
$count_map = array_column($counts, 'c', 'level');
$total_unresolved = array_sum(array_column($counts, 'c'));

// Also read last 20 lines from file log
$logfile = __DIR__ . '/../logs/app_errors.log';
$file_log_lines = [];
if (file_exists($logfile)) {
    $lines = file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $file_log_lines = array_slice(array_reverse($lines), 0, 30);
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-bug-fill me-2"></i>Error Log';</script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">
        Application Error Log
        <?php if ($total_unresolved > 0): ?>
        <span class="badge bg-danger ms-2"><?= $total_unresolved ?> Unresolved</span>
        <?php endif; ?>
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?resolve_all=1" class="btn btn-success btn-sm" onclick="return confirm('Mark all as resolved?')">
            <i class="bi bi-check-all me-1"></i>Resolve All
        </a>
        <a href="?clear_resolved=1" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Clear resolved entries?')">
            <i class="bi bi-trash me-1"></i>Clear Resolved
        </a>
        <a href="?clear_all=1" class="btn btn-danger btn-sm" onclick="return confirm('Clear ALL error logs? This cannot be undone.')">
            <i class="bi bi-trash-fill me-1"></i>Clear All
        </a>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-2 mb-3">
    <?php
    $level_colors = ['EXCEPTION'=>'danger','ERROR'=>'danger','DB_QUERY'=>'warning','WARNING'=>'warning','NOTICE'=>'info','DB_CONNECTION'=>'dark'];
    foreach ($level_colors as $lv => $lc): if (!isset($count_map[$lv])) continue; ?>
    <div class="col-6 col-md-2">
        <div class="card text-center p-2 border-<?= $lc ?>">
            <small class="text-muted"><?= $lv ?></small>
            <div class="fw-bold text-<?= $lc ?> fs-5"><?= $count_map[$lv] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Level</label>
        <select name="level" class="form-select form-select-sm">
            <option value="">All Levels</option>
            <?php foreach (array_keys($level_colors) as $lv): ?>
            <option <?= $filter_level===$lv?'selected':'' ?>><?= $lv ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Status</label>
        <select name="resolved" class="form-select form-select-sm">
            <option value="" <?= $filter_resolved===''?'selected':'' ?>>All</option>
            <option value="0" <?= $filter_resolved==='0'?'selected':'' ?>>Unresolved</option>
            <option value="1" <?= $filter_resolved==='1'?'selected':'' ?>>Resolved</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= $filter_date ?>">
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
    <div class="col-6 col-md-2">
        <a href="error_log.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
    </div>
</form>
</div></div>

<!-- Error Table -->
<div class="card mb-4"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0" id="errorTable">
<thead class="table-dark"><tr>
    <th style="width:140px">Time</th>
    <th style="width:100px">Level</th>
    <th>Message</th>
    <th>File : Line</th>
    <th>User</th>
    <th style="width:80px">Status</th>
    <th style="width:80px">Actions</th>
</tr></thead>
<tbody>
<?php if (!$errors): ?>
<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success fs-4 me-2"></i>No errors found</td></tr>
<?php endif; ?>
<?php foreach ($errors as $e):
    $lc = $level_colors[$e['level']] ?? 'secondary';
    $is_resolved = $e['resolved'] == 1;
?>
<tr class="<?= $is_resolved ? 'opacity-50' : '' ?>">
    <td class="text-muted" style="font-size:.8rem;white-space:nowrap"><?= date('d/m/Y H:i:s', strtotime($e['logged_at'])) ?></td>
    <td><span class="badge bg-<?= $lc ?>"><?= htmlspecialchars($e['level']) ?></span></td>
    <td>
        <div class="fw-semibold" style="font-size:.85rem"><?= htmlspecialchars(substr($e['message'],0,200)) ?></div>
        <?php if ($e['url']): ?>
        <small class="text-muted"><?= htmlspecialchars($e['url']) ?></small>
        <?php endif; ?>
        <?php if ($e['trace']): ?>
        <div class="mt-1">
            <button class="btn btn-outline-secondary btn-sm py-0" type="button"
                data-bs-toggle="collapse" data-bs-target="#trace<?= $e['id'] ?>">
                <i class="bi bi-code-slash me-1"></i>Trace
            </button>
            <div class="collapse mt-1" id="trace<?= $e['id'] ?>">
                <pre class="bg-dark text-light p-2 rounded" style="font-size:.7rem;max-height:200px;overflow-y:auto"><?= htmlspecialchars($e['trace']) ?></pre>
            </div>
        </div>
        <?php endif; ?>
    </td>
    <td style="font-size:.78rem;font-family:monospace;color:#555">
        <?= htmlspecialchars($e['file']) ?><?= $e['line'] ? ':'.$e['line'] : '' ?>
    </td>
    <td style="font-size:.82rem"><?= htmlspecialchars($e['full_name'] ?? ($e['user_id'] ? '#'.$e['user_id'] : '—')) ?></td>
    <td>
        <?php if ($is_resolved): ?>
        <span class="badge bg-success"><i class="bi bi-check-lg"></i> Resolved</span>
        <?php else: ?>
        <span class="badge bg-warning text-dark">Open</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!$is_resolved): ?>
        <a href="?resolve=<?= $e['id'] ?>&level=<?= urlencode($filter_level) ?>&resolved=<?= urlencode($filter_resolved) ?>&date=<?= urlencode($filter_date) ?>"
           class="btn btn-action btn-outline-success me-1" title="Mark Resolved"><i class="bi bi-check-lg"></i></a>
        <?php endif; ?>
        <a href="?delete=<?= $e['id'] ?>&level=<?= urlencode($filter_level) ?>&resolved=<?= urlencode($filter_resolved) ?>&date=<?= urlencode($filter_date) ?>"
           onclick="return confirm('Delete this entry?')"
           class="btn btn-action btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<!-- File Log tail -->
<?php if ($file_log_lines): ?>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-file-text me-2"></i>File Log (last 30 lines)</span>
    <small class="text-muted"><?= $logfile ?></small>
</div>
<div class="card-body p-0">
<pre class="bg-dark text-light p-3 mb-0 rounded-bottom" style="font-size:.72rem;max-height:300px;overflow-y:auto;white-space:pre-wrap"><?php
foreach ($file_log_lines as $line) {
    // Colour by level
    if (strpos($line,'[EXCEPTION]')!==false || strpos($line,'[ERROR]')!==false)
        echo '<span style="color:#ff6b6b">'.htmlspecialchars($line).'</span>'."\n";
    elseif (strpos($line,'[WARNING]')!==false || strpos($line,'[DB_QUERY]')!==false)
        echo '<span style="color:#ffd93d">'.htmlspecialchars($line).'</span>'."\n";
    else
        echo '<span style="color:#aaa">'.htmlspecialchars($line).'</span>'."\n";
}
?></pre>
</div></div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
