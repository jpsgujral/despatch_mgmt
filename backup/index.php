<?php
/* ═══════════════════════════════════════════════════════════════
   index.php — Backup & Restore Web UI
   File: backup/index.php
   ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/BackupManager.php';

if (!isAdmin()) { header('Location: ../index.php'); exit; }

set_time_limit(600);
ini_set('memory_limit', '256M');

$mgr    = new BackupManager();
$action = $_POST['action'] ?? '';

// ── Handle POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {

    if (($_POST['csrf'] ?? '') !== ($_SESSION['backup_csrf'] ?? '')) {
        die('Invalid CSRF token.');
    }

    switch ($action) {
        case 'manual_backup':
            if ($mgr->isPaused()) {
                $_SESSION['backup_log'] = [[
                    'time'  => date('H:i:s'),
                    'level' => 'info',
                    'msg'   => 'Backup is paused. Resume backup before running a manual backup.',
                ]];
                $_SESSION['backup_result'] = 'skipped';
            } else {
                $_SESSION['backup_log']    = $mgr->runFullBackup();
                $_SESSION['backup_result'] = 'backup';
            }
            break;

        case 'pause_backup':
            $changedBy = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'admin';
            $mgr->setPaused(true, (string)$changedBy);
            $_SESSION['backup_result'] = 'paused';
            break;

        case 'resume_backup':
            $mgr->setPaused(false);
            $_SESSION['backup_result'] = 'resumed';
            break;

        case 'restore_db':
            $key = $_POST['r2_key'] ?? '';
            if ($key) {
                $_SESSION['backup_log']    = $mgr->restoreDatabase($key);
                $_SESSION['backup_result'] = 'restore_db';
            }
            break;

        case 'restore_app':
            $key = $_POST['r2_key'] ?? '';
            if ($key) {
                $_SESSION['backup_log']    = $mgr->restoreAppFiles($key);
                $_SESSION['backup_result'] = 'restore_app';
            }
            break;

        case 'delete_backup':
            $prefix = $_POST['prefix'] ?? '';
            if ($prefix) {
                $_SESSION['backup_log']    = $mgr->deleteBackup($prefix);
                $_SESSION['backup_result'] = 'deleted';
            }
            break;
    }

    header('Location: index.php');
    exit;
}

// ── Consume session data ─────────────────────────────────────────
$resultLog  = $_SESSION['backup_log']    ?? [];
$resultKey  = $_SESSION['backup_result'] ?? '';
unset($_SESSION['backup_log'], $_SESSION['backup_result']);

// Refresh CSRF
$_SESSION['backup_csrf'] = $csrf = bin2hex(random_bytes(32));

// Load backup list
$backups   = [];
$listError = '';
try {
    $backups = $mgr->listBackups();
} catch (Exception $e) {
    $listError = $e->getMessage();
}

$backupPaused = $mgr->isPaused();
$pauseInfo    = $mgr->getPauseInfo();

// Result labels
$resultMeta = [
    'backup'     => ['success', 'Manual backup completed successfully.'],
    'skipped'    => ['warning', 'Backup is paused. Manual backup was not started.'],
    'paused'     => ['warning', 'Automatic and manual backups are now paused.'],
    'resumed'    => ['success', 'Backups resumed successfully.'],
    'restore_db' => ['warning', 'Database restore completed. Please verify the application.'],
    'restore_app'=> ['warning', 'App files restore completed. Please verify the application.'],
    'deleted'    => ['info',    'Backup deleted.'],
];
[$alertType, $alertText] = $resultMeta[$resultKey] ?? ['', ''];

function fmtBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}

$pageTitle = 'Backup & Restore';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h4 class="mb-0 fw-semibold">
      <i class="bi bi-cloud-arrow-up-fill me-2 text-primary"></i>Backup &amp; Restore
    </h4>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge <?= $backupPaused ? 'bg-warning text-dark' : 'bg-primary' ?> px-3 py-2">
        <i class="bi <?= $backupPaused ? 'bi-pause-circle' : 'bi-check-circle' ?> me-1"></i>
        <?= $backupPaused ? 'Backup Paused' : 'Backup Active' ?>
      </span>

      <?php if ($backupPaused): ?>
      <form method="post" class="d-inline"
            onsubmit="return confirm('Resume automatic and manual backups?');">
        <input type="hidden" name="csrf"   value="<?= $csrf ?>">
        <input type="hidden" name="action" value="resume_backup">
        <button class="btn btn-primary">
          <i class="bi bi-play-fill me-1"></i> Resume Backup
        </button>
      </form>
      <?php else: ?>
      <form method="post" class="d-inline"
            onsubmit="return confirm('Pause automatic and manual backups?\nNo backup will run until resumed.');">
        <input type="hidden" name="csrf"   value="<?= $csrf ?>">
        <input type="hidden" name="action" value="pause_backup">
        <button class="btn btn-warning">
          <i class="bi bi-pause-fill me-1"></i> Pause Backup
        </button>
      </form>
      <?php endif; ?>

      <form method="post" class="d-inline"
            onsubmit="return confirm('Start a full backup now?\nThis may take a few minutes.');">
        <input type="hidden" name="csrf"   value="<?= $csrf ?>">
        <input type="hidden" name="action" value="manual_backup">
        <button class="btn btn-primary" <?= $backupPaused ? 'disabled' : '' ?>>
          <i class="bi bi-cloud-upload me-1"></i> Run Backup Now
        </button>
      </form>
    </div>
  </div>

  <?php if ($backupPaused): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-pause-circle-fill fs-5"></i>
    <div>
      <strong>Backups are paused.</strong>
      Automatic cron backup and manual backup will be skipped until backup is resumed.
      <?php if (!empty($pauseInfo['paused_at'])): ?>
        <div class="small mt-1">
          Paused on <?= htmlspecialchars(date('d M Y H:i', strtotime($pauseInfo['paused_at']))) ?>
          <?php if (!empty($pauseInfo['paused_by'])): ?>
            by <?= htmlspecialchars($pauseInfo['paused_by']) ?>
          <?php endif; ?>.
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Alert -->
  <?php if ($alertType): ?>
  <div class="alert alert-<?= $alertType ?> alert-dismissible fade show">
    <strong><?= htmlspecialchars($alertText) ?></strong>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Operation log -->
  <?php if (!empty($resultLog)): ?>
  <div class="card mb-4 border-0 shadow-sm">
    <div class="card-header bg-dark text-white py-2 small fw-semibold">
      <i class="bi bi-terminal me-1"></i> Operation Log
    </div>
    <pre class="m-0 p-3 small"
         style="background:#1e1e1e;color:#d4d4d4;max-height:220px;overflow-y:auto;border-radius:0 0 .375rem .375rem;"><?php
      foreach ($resultLog as $e) {
          $icon = $e['level'] === 'error' ? '❌' : '✅';
          echo htmlspecialchars("[{$e['time']}] {$icon} {$e['msg']}") . "\n";
      }
    ?></pre>
  </div>
  <?php endif; ?>

  <!-- List error -->
  <?php if ($listError): ?>
  <div class="alert alert-danger">
    <strong>Could not load backups:</strong> <?= htmlspecialchars($listError) ?>
  </div>
  <?php endif; ?>

  <!-- Stats row -->
  <?php
    $totalSize = array_sum(array_column($backups, 'size'));
    $latest    = !empty($backups) ? array_key_first($backups) : null;
    $latestDate = $latest ? date('d M Y H:i', strtotime($backups[$latest]['date'] ?? '')) : '—';
  ?>
  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="card border-success text-center py-3">
        <div class="fs-3 fw-bold text-success"><?= count($backups) ?></div>
        <div class="small text-muted">Backups in R2</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="card border-primary text-center py-3">
        <div class="fs-3 fw-bold text-primary"><?= fmtBytes($totalSize) ?></div>
        <div class="small text-muted">Total stored</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="card border-warning text-center py-3">
        <div class="fs-6 fw-bold text-warning"><?= htmlspecialchars($latestDate) ?></div>
        <div class="small text-muted">Latest backup</div>
      </div>
    </div>
  </div>

  <!-- Backup table -->
  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white fw-semibold">
      <i class="bi bi-list-ul me-1"></i> Backup List
    </div>
    <div class="card-body p-0">
      <?php if (empty($backups) && !$listError): ?>
        <div class="text-center text-muted py-5">
          <i class="bi bi-cloud-slash fs-1 d-block mb-2"></i>
          No backups yet. Click <strong>Run Backup Now</strong> to create the first one.
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
          <thead class="table-dark small">
            <tr>
              <th>Backup ID</th>
              <th>Date / Time</th>
              <th>Total Size</th>
              <th>DB</th>
              <th>App Files</th>
              <th class="text-end pe-3">Actions</th>
            </tr>
          </thead>
          <tbody class="small">
          <?php foreach ($backups as $prefix => $data): ?>
            <?php
              $dateStr = !empty($data['date'])
                ? date('d M Y, H:i', strtotime($data['date']))
                : '—';
              $dbKey  = $data['db']['key']  ?? '';
              $appKey = $data['app']['key'] ?? '';
              $safePrefix = htmlspecialchars($prefix);
            ?>
            <tr>
              <td><code><?= $safePrefix ?></code></td>
              <td><?= htmlspecialchars($dateStr) ?></td>
              <td><?= fmtBytes((int)($data['size'] ?? 0)) ?></td>
              <td>
                <?= $dbKey
                    ? '<span class="badge bg-success">✓ ' . fmtBytes((int)($data['db']['size'] ?? 0)) . '</span>'
                    : '<span class="badge bg-secondary">—</span>' ?>
              </td>
              <td>
                <?= $appKey
                    ? '<span class="badge bg-primary">✓ ' . fmtBytes((int)($data['app']['size'] ?? 0)) . '</span>'
                    : '<span class="badge bg-secondary">—</span>' ?>
              </td>
              <td class="text-end pe-3">

                <?php if ($dbKey): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('⚠️ OVERWRITE current database with:\n<?= $safePrefix ?>\n\nThis cannot be undone. Continue?');">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="restore_db">
                  <input type="hidden" name="r2_key" value="<?= htmlspecialchars($dbKey) ?>">
                  <button class="btn btn-sm btn-warning" title="Restore Database">
                    <i class="bi bi-database-fill-up"></i> DB
                  </button>
                </form>
                <?php endif; ?>

                <?php if ($appKey): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('⚠️ OVERWRITE app files with:\n<?= $safePrefix ?>\n\nThis cannot be undone. Continue?');">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="restore_app">
                  <input type="hidden" name="r2_key" value="<?= htmlspecialchars($appKey) ?>">
                  <button class="btn btn-sm btn-primary" title="Restore App Files">
                    <i class="bi bi-folder-fill"></i> App
                  </button>
                </form>
                <?php endif; ?>

                <form method="post" class="d-inline"
                      onsubmit="return confirm('Delete backup:\n<?= $safePrefix ?>\n\nAre you sure?');">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_backup">
                  <input type="hidden" name="prefix" value="<?= $safePrefix ?>">
                  <button class="btn btn-sm btn-danger" title="Delete">
                    <i class="bi bi-trash3"></i>
                  </button>
                </form>

              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Cron setup reminder -->
  <div class="card mt-4 border-info">
    <div class="card-header bg-info bg-opacity-10 text-info fw-semibold border-info">
      <i class="bi bi-clock me-1"></i> Automatic Daily Backup — Cron Setup
    </div>
    <div class="card-body">
      <p class="mb-2 small">Add in cPanel → <strong>Cron Jobs</strong> (runs daily at 2:00 AM):</p>
      <pre class="bg-dark text-success p-3 rounded small mb-2">0 2 * * * /usr/local/bin/ea-php81 /home/tsgimpex/public_html/despatch_mgmt/backup/backup.php >> /home/tsgimpex/backup_tmp/backup.log 2>&1</pre>
      <p class="mb-0 small text-muted">Log file: <code>/home/tsgimpex/backup_tmp/backup.log</code></p>
    </div>
  </div>

</div>

<!-- Working overlay -->
<div id="workingOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9999;flex-direction:column;align-items:center;justify-content:center;">
  <div class="text-center text-white">
    <div class="spinner-border text-light mb-3" style="width:3.5rem;height:3.5rem;" role="status"></div>
    <div id="overlayMsg" class="fw-semibold fs-5 mb-1">Please wait…</div>
    <div class="small text-white-50">Do not close or refresh this page</div>
  </div>
</div>

<script>
(function () {
    var overlay   = document.getElementById('workingOverlay');
    var overlayMsg = document.getElementById('overlayMsg');

    var messages = {
        manual_backup  : 'Running full backup… this may take a few minutes',
        restore_db     : 'Restoring database… please wait',
        restore_app    : 'Restoring app files… please wait',
        delete_backup  : 'Deleting backup…',
        pause_backup   : 'Pausing backups…',
        resume_backup  : 'Resuming backups…',
    };

    document.querySelectorAll('form[method="post"]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            // Only show overlay if the confirm() was accepted (form wasn't cancelled)
            // submit fires after confirm returns true, so we're safe here
            var actionInput = form.querySelector('input[name="action"]');
            var action = actionInput ? actionInput.value : '';
            var msg = messages[action] || 'Processing… please wait';
            overlayMsg.textContent = msg;
            overlay.style.display = 'flex';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
