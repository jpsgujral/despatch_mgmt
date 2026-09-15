<?php
// ============================================================
//  TSGImpex Outage Dashboard — outage.php
//  LOCAL XAMPP ONLY — shows live status, queue, health log
// ============================================================
require_once __DIR__ . '/../sync_config.php';
require_once __DIR__ . '/sync_engine.php';
require_once __DIR__ . '/outage_manager.php';

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'check_live':
            $up = OutageManager::isLiveUp();
            echo json_encode(['ok' => true, 'live_up' => $up]);
            break;
        case 'replay_queue':
            if (OutageManager::isLiveUp()) {
                $r = OutageManager::replayQueue();
                echo json_encode(['ok' => true, 'result' => $r]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Live server still unreachable']);
            }
            break;
        case 'get_queue':
            echo json_encode([
                'ok'    => true,
                'stats' => OutageManager::getQueueStats(),
                'items' => OutageManager::getPendingQueue(50),
            ]);
            break;
        case 'get_health':
            echo json_encode(['ok' => true, 'log' => OutageManager::getHealthLog(40)]);
            break;
        case 'run_watchdog':
            $r = OutageManager::watchdog();
            echo json_encode(['ok' => true, 'result' => $r]);
            break;
    }
    exit;
}

// ── Page data ─────────────────────────────────────────────────
try {
    $qStats  = OutageManager::getQueueStats();
    $queue   = OutageManager::getPendingQueue(50);
    $health  = OutageManager::getHealthLog(30);
    $last    = OutageManager::getLastStatus();
    $dbOk    = true;
} catch (Exception $e) {
    $qStats = ['pending'=>0,'replayed'=>0,'failed'=>0,'total'=>0];
    $queue  = []; $health = []; $last = null;
    $dbOk   = false; $dbErr = $e->getMessage();
}

$isDown = $last && $last['status'] === 'DOWN';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TSGImpex — Outage Monitor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:      #0b0f14;
    --surface: #131920;
    --surface2:#1a2230;
    --border:  #1e2d3d;
    --text:    #d4dde8;
    --muted:   #5a7a96;
    --green:   #22c55e;
    --red:     #ef4444;
    --amber:   #f59e0b;
    --blue:    #3b82f6;
    --mono:    'IBM Plex Mono', monospace;
    --sans:    'IBM Plex Sans', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--bg); color:var(--text); font-family:var(--sans); font-size:14px; }

  /* Alert bar */
  .alert-bar {
    padding:12px 24px; font-size:13px; font-family:var(--mono);
    display:flex; align-items:center; justify-content:space-between;
    border-bottom:1px solid var(--border);
  }
  .alert-bar.down { background:rgba(239,68,68,0.12); color:var(--red); border-color:rgba(239,68,68,0.25); }
  .alert-bar.up   { background:rgba(34,197,94,0.08);  color:var(--green); border-color:rgba(34,197,94,0.2); }

  /* Header */
  .header {
    background:var(--surface); border-bottom:1px solid var(--border);
    padding:14px 24px; display:flex; align-items:center; justify-content:space-between;
  }
  .logo { font-family:var(--mono); font-size:16px; font-weight:600; color:#64b5f6; }
  .logo em { color:var(--muted); font-style:normal; font-weight:400; }
  .nav-link {
    font-size:12px; color:var(--muted); text-decoration:none; padding:5px 10px;
    border:1px solid var(--border); border-radius:4px;
  }
  .nav-link:hover { color:var(--text); border-color:var(--muted); }

  /* Main */
  .main { padding:20px 24px; max-width:1060px; }

  /* Big status */
  .big-status {
    border-radius:10px; padding:22px 26px; margin-bottom:20px;
    border:1px solid; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;
  }
  .big-status.down { background:rgba(239,68,68,0.08); border-color:rgba(239,68,68,0.25); }
  .big-status.up   { background:rgba(34,197,94,0.06);  border-color:rgba(34,197,94,0.2); }
  .big-status .main-label { font-family:var(--mono); font-size:22px; font-weight:600; }
  .big-status.down .main-label { color:var(--red); }
  .big-status.up   .main-label { color:var(--green); }
  .big-status .sub  { color:var(--muted); font-size:13px; margin-top:4px; }
  .big-status .actions { display:flex; gap:10px; flex-wrap:wrap; }

  /* Stat cards */
  .cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:20px; }
  .card  { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:14px 16px; }
  .card .lbl  { font-size:11px; text-transform:uppercase; letter-spacing:0.7px; color:var(--muted); margin-bottom:6px; }
  .card .val  { font-family:var(--mono); font-size:20px; font-weight:600; }
  .card .val.red    { color:var(--red); }
  .card .val.green  { color:var(--green); }
  .card .val.amber  { color:var(--amber); }
  .card .val.blue   { color:var(--blue); }

  /* Buttons */
  .btn {
    font-family:var(--sans); font-size:13px; font-weight:500;
    padding:8px 16px; border-radius:6px; border:none; cursor:pointer;
    transition:opacity 0.15s; display:inline-flex; align-items:center; gap:6px;
  }
  .btn:hover:not(:disabled) { opacity:0.8; }
  .btn:disabled { opacity:0.35; cursor:not-allowed; }
  .btn-green  { background:var(--green); color:#000; }
  .btn-blue   { background:rgba(59,130,246,0.15); color:var(--blue); border:1px solid rgba(59,130,246,0.3); }
  .btn-ghost  { background:transparent; color:var(--muted); border:1px solid var(--border); }
  .btn-red    { background:rgba(239,68,68,0.12); color:var(--red); border:1px solid rgba(239,68,68,0.25); }

  /* Section */
  .section { margin-bottom:22px; }
  .sec-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
  .sec-title { font-family:var(--mono); font-size:11px; text-transform:uppercase; letter-spacing:0.8px; color:var(--muted); }

  /* Table */
  .tbl { width:100%; border-collapse:collapse; background:var(--surface); border:1px solid var(--border); border-radius:8px; overflow:hidden; }
  .tbl th { background:var(--surface2); font-family:var(--mono); font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted); padding:9px 12px; text-align:left; border-bottom:1px solid var(--border); }
  .tbl td { padding:9px 12px; border-bottom:1px solid rgba(30,45,61,0.7); font-family:var(--mono); font-size:12px; }
  .tbl tr:last-child td { border-bottom:none; }
  .tbl tr:hover td { background:rgba(255,255,255,0.015); }
  .tbl td.action-insert { color:var(--green); }
  .tbl td.action-update { color:var(--blue); }
  .tbl td.action-delete { color:var(--red); }
  .empty-row td { text-align:center; color:var(--muted); padding:20px; }

  /* Health dots */
  .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:5px; vertical-align:middle; }
  .dot-green { background:var(--green); box-shadow:0 0 5px var(--green); }
  .dot-red   { background:var(--red);   box-shadow:0 0 5px var(--red); }

  /* Pulse animation for DOWN state */
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
  .pulse { animation:pulse 1.8s ease-in-out infinite; }

  /* Result */
  .result-box {
    background:var(--surface2); border:1px solid var(--border); border-radius:6px;
    padding:12px; margin-top:12px; font-family:var(--mono); font-size:12px; line-height:1.8;
    display:none;
  }

  /* Spinner */
  .spin { display:inline-block; width:13px; height:13px; border:2px solid rgba(255,255,255,0.2); border-top-color:currentColor; border-radius:50%; animation:_spin 0.7s linear infinite; }
  @keyframes _spin { to { transform:rotate(360deg); } }

  /* Integration tip box */
  .tip-box { background:var(--surface); border:1px solid var(--border); border-left:3px solid var(--amber); border-radius:6px; padding:14px 16px; font-size:13px; line-height:1.8; }
  .tip-box code { background:rgba(255,255,255,0.07); padding:1px 5px; border-radius:3px; font-family:var(--mono); font-size:12px; color:var(--amber); }
  .tip-box .tip-title { font-weight:600; color:var(--amber); margin-bottom:8px; }

  @media(max-width:640px) {
    .main { padding:12px; }
    .big-status { flex-direction:column; }
  }
</style>
</head>
<body>

<?php if (!$dbOk): ?>
<div class="alert-bar down">⚠ Database error: <?= htmlspecialchars($dbErr) ?></div>
<?php elseif ($isDown): ?>
<div class="alert-bar down pulse">🔴 OFFLINE MODE ACTIVE — Live server is unreachable. All local changes are being queued.</div>
<?php else: ?>
<div class="alert-bar up">🟢 ONLINE — Live server is reachable. <?= $qStats['pending'] > 0 ? $qStats['pending'].' changes pending replay.' : 'Queue is clear.' ?></div>
<?php endif; ?>

<div class="header">
  <div class="logo">TSG<em>impex</em> · Outage Monitor</div>
  <div style="display:flex;gap:8px">
    <a href="index.php" class="nav-link">← Sync Dashboard</a>
  </div>
</div>

<div class="main">

  <!-- Big status -->
  <div class="big-status <?= $isDown ? 'down' : 'up' ?>" id="bigStatus">
    <div>
      <div class="main-label" id="statusLabel">
        <?= $isDown ? '⚡ OFFLINE MODE' : '✓ ONLINE' ?>
      </div>
      <div class="sub" id="statusSub">
        <?php if ($last): ?>
          Last checked: <?= $last['checked_at'] ?> — <?= $last['response'] ?>
        <?php else: ?>
          No health checks recorded yet
        <?php endif; ?>
      </div>
    </div>
    <div class="actions">
      <button class="btn btn-blue" onclick="checkLive()">↺ Check Now</button>
      <button class="btn btn-green" id="btnReplay" onclick="replayQueue()" <?= ($isDown || $qStats['pending'] === 0) ? 'disabled' : '' ?>>
        ⇄ Replay Queue (<?= $qStats['pending'] ?>)
      </button>
      <button class="btn btn-ghost" onclick="runWatchdog()">▶ Run Watchdog</button>
    </div>
  </div>

  <div class="result-box" id="resultBox"></div>

  <!-- Stats -->
  <div class="cards">
    <div class="card">
      <div class="lbl">Pending Changes</div>
      <div class="val <?= $qStats['pending'] > 0 ? 'amber' : 'green' ?>" id="statPending"><?= $qStats['pending'] ?></div>
    </div>
    <div class="card">
      <div class="lbl">Successfully Replayed</div>
      <div class="val green" id="statReplayed"><?= $qStats['replayed'] ?></div>
    </div>
    <div class="card">
      <div class="lbl">Failed Replays</div>
      <div class="val <?= $qStats['failed'] > 0 ? 'red' : 'green' ?>" id="statFailed"><?= $qStats['failed'] ?></div>
    </div>
    <div class="card">
      <div class="lbl">Total Queued Ever</div>
      <div class="val blue" id="statTotal"><?= $qStats['total'] ?></div>
    </div>
  </div>

  <!-- Pending Queue -->
  <div class="section">
    <div class="sec-head">
      <span class="sec-title">Pending Queue (changes waiting to sync)</span>
      <button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="refreshQueue()">↺</button>
    </div>
    <table class="tbl" id="queueTable">
      <thead>
        <tr><th>#</th><th>Table</th><th>PK</th><th>Action</th><th>Queued At</th><th>Error</th></tr>
      </thead>
      <tbody id="queueBody">
        <?php if (empty($queue)): ?>
        <tr class="empty-row"><td colspan="6">No pending changes — queue is clear ✓</td></tr>
        <?php else: foreach ($queue as $item): ?>
        <tr>
          <td><?= $item['id'] ?></td>
          <td><?= htmlspecialchars($item['table_name']) ?></td>
          <td><?= htmlspecialchars($item['pk_value']) ?></td>
          <td class="action-<?= strtolower($item['action']) ?>"><?= $item['action'] ?></td>
          <td><?= $item['queued_at'] ?></td>
          <td style="color:var(--red)"><?= htmlspecialchars($item['error'] ?? '') ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Health log -->
  <div class="section">
    <div class="sec-head">
      <span class="sec-title">Live Server Health Log</span>
      <button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="refreshHealth()">↺</button>
    </div>
    <table class="tbl" id="healthTable">
      <thead>
        <tr><th>Status</th><th>Checked At</th><th>Response</th></tr>
      </thead>
      <tbody id="healthBody">
        <?php if (empty($health)): ?>
        <tr class="empty-row"><td colspan="3">No health checks yet — run watchdog to start</td></tr>
        <?php else: foreach ($health as $h): ?>
        <tr>
          <td><span class="dot <?= $h['status']==='UP' ? 'dot-green' : 'dot-red' ?>"></span><?= $h['status'] ?></td>
          <td><?= $h['checked_at'] ?></td>
          <td><?= htmlspecialchars($h['response'] ?? '') ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Integration guide -->
  <div class="section">
    <div class="sec-head"><span class="sec-title">How to integrate offline mode into your app</span></div>
    <div class="tip-box">
      <div class="tip-title">📌 3 steps to make your app work during outage</div>

      <strong>Step 1 — Add to your page header (shows orange offline banner)</strong><br>
      <code>require_once '/path/to/sync/app_sync.php';</code><br>
      <code>echo AppSync::offlineBanner();</code><br><br>

      <strong>Step 2 — Replace your INSERT queries</strong><br>
      Before: <code>$pdo->query("INSERT INTO orders ...")</code><br>
      After: &nbsp;<code>AppSync::insert('orders', 'id', $dataArray);</code><br><br>

      <strong>Step 3 — Replace your UPDATE queries</strong><br>
      Before: <code>$pdo->query("UPDATE orders SET ... WHERE id=$id")</code><br>
      After: &nbsp;<code>AppSync::update('orders', 'id', $id, $dataArray);</code><br><br>

      <strong>Step 4 — Replace your DELETE queries</strong><br>
      Before: <code>$pdo->query("DELETE FROM orders WHERE id=$id")</code><br>
      After: &nbsp;<code>AppSync::delete('orders', 'id', $id);</code><br><br>

      That's it. When live is down, changes go into the queue automatically. When live returns, the watchdog replays them all.
    </div>
  </div>

</div>

<script>
const fmt = s => s.replace(/</g,'&lt;');

async function post(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
  const r = await fetch('', { method:'POST', body:fd });
  return r.json();
}

function showResult(html, isError = false) {
  const box = document.getElementById('resultBox');
  box.style.display = 'block';
  box.style.borderColor = isError ? 'rgba(239,68,68,0.3)' : 'rgba(34,197,94,0.25)';
  box.innerHTML = html;
}

async function checkLive() {
  showResult('<span class="spin"></span> Checking live server...');
  const d = await post('check_live');
  const up = d.live_up;
  const bigEl = document.getElementById('bigStatus');
  const lbl   = document.getElementById('statusLabel');
  const sub   = document.getElementById('statusSub');
  bigEl.className = 'big-status ' + (up ? 'up' : 'down');
  lbl.textContent = up ? '✓ ONLINE' : '⚡ OFFLINE MODE';
  sub.textContent = 'Checked: ' + new Date().toLocaleString();
  document.getElementById('btnReplay').disabled = !up;
  showResult(up
    ? '<span style="color:var(--green)">✓ Live server is reachable</span>'
    : '<span style="color:var(--red)">✗ Live server is DOWN — offline mode active</span>',
    !up
  );
  refreshHealth();
}

async function replayQueue() {
  const btn = document.getElementById('btnReplay');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Replaying…';
  showResult('Replaying queued changes to live server...');
  const d = await post('replay_queue');
  if (d.ok) {
    const r = d.result;
    showResult(`<span style="color:var(--green)">✓ Replay complete</span><br>Sent: <b>${r.replayed}</b>  Failed: <b style="color:${r.failed>0?'var(--red)':'inherit'}">${r.failed}</b>  Skipped: <b>${r.skipped}</b>`);
  } else {
    showResult(`<span style="color:var(--red)">✗ ${fmt(d.error)}</span>`, true);
  }
  btn.innerHTML = '⇄ Replay Queue';
  refreshQueue();
}

async function runWatchdog() {
  showResult('<span class="spin"></span> Running watchdog...');
  const d = await post('run_watchdog');
  if (d.ok) {
    const r = d.result;
    const up = r.live_up;
    let html = `<span style="color:${up?'var(--green)':'var(--red)'}">${up?'✓ Live UP':'✗ Live DOWN'}</span>`;
    if (r.replayed > 0) html += `<br>Replayed: <b>${r.replayed}</b>  Failed: <b>${r.failed}</b>`;
    if (r.regular_sync) html += `<br>Regular sync also completed`;
    showResult(html, !up);
  } else {
    showResult(`<span style="color:var(--red)">✗ ${fmt(d.error||'Unknown')}</span>`, true);
  }
  refreshQueue();
  refreshHealth();
}

async function refreshQueue() {
  const d = await post('get_queue');
  if (!d.ok) return;
  const tbody = document.getElementById('queueBody');
  const s = d.stats;
  document.getElementById('statPending').textContent  = s.pending;
  document.getElementById('statReplayed').textContent = s.replayed;
  document.getElementById('statFailed').textContent   = s.failed;
  document.getElementById('statTotal').textContent    = s.total;
  document.getElementById('btnReplay').textContent    = `⇄ Replay Queue (${s.pending})`;

  if (!d.items.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="6">No pending changes — queue is clear ✓</td></tr>';
    return;
  }
  tbody.innerHTML = d.items.map(i => `<tr>
    <td>${i.id}</td>
    <td>${fmt(i.table_name)}</td>
    <td>${fmt(i.pk_value)}</td>
    <td class="action-${i.action.toLowerCase()}">${i.action}</td>
    <td>${i.queued_at}</td>
    <td style="color:var(--red)">${fmt(i.error||'')}</td>
  </tr>`).join('');
}

async function refreshHealth() {
  const d = await post('get_health');
  if (!d.ok) return;
  const tbody = document.getElementById('healthBody');
  if (!d.log.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="3">No health checks yet</td></tr>';
    return;
  }
  tbody.innerHTML = d.log.map(h => `<tr>
    <td><span class="dot ${h.status==='UP'?'dot-green':'dot-red'}"></span>${h.status}</td>
    <td>${h.checked_at}</td>
    <td>${fmt(h.response||'')}</td>
  </tr>`).join('');
}

// Auto-check on load
checkLive();
// Auto-refresh queue every 30s
setInterval(refreshQueue, 30000);
</script>
</body>
</html>
