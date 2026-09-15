<?php
// ============================================================
//  TSGImpex — Main Dashboard (Sync + Outage combined)
// ============================================================
session_start();
require_once __DIR__ . '/../sync_config.php';
require_once __DIR__ . '/sync_engine.php';
require_once __DIR__ . '/outage_manager.php';

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'run_sync':
            if (THIS_SITE === 'local' && !OutageManager::isLiveUp()) {
                echo json_encode(['ok' => false, 'error' => 'Live server is DOWN. Changes will queue automatically.']);
            } else {
                echo json_encode(['ok' => true, 'result' => SyncEngine::fullSync()]);
            }
            break;
        case 'ping_remote':
            $up = OutageManager::isLiveUp();
            $last = OutageManager::getLastStatus();
            echo json_encode(['ok' => $up, 'live_up' => $up, 'last' => $last]);
            break;
        case 'run_watchdog':
            $r = OutageManager::watchdog();
            echo json_encode(['ok' => true, 'result' => $r]);
            break;
        case 'replay_queue':
            if (!OutageManager::isLiveUp()) {
                echo json_encode(['ok' => false, 'error' => 'Live server still unreachable']);
            } else {
                echo json_encode(['ok' => true, 'result' => OutageManager::replayQueue()]);
            }
            break;
        case 'get_status':
            try {
                echo json_encode([
                    'ok'     => true,
                    'stats'  => SyncEngine::getStats(),
                    'queue'  => OutageManager::getQueueStats(),
                    'locked' => SyncLock::isLocked(),
                    'last'   => OutageManager::getLastStatus(),
                    'logs'   => SyncLogger::getLogs(60),
                    'pending'=> OutageManager::getPendingQueue(30),
                    'health' => OutageManager::getHealthLog(20),
                ]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;
    }
    exit;
}

// ── Page data ─────────────────────────────────────────────────
try {
    $stats   = SyncEngine::getStats();
    $qStats  = OutageManager::getQueueStats();
    $lastH   = OutageManager::getLastStatus();
    $logs    = SyncLogger::getLogs(50);
    $pending = OutageManager::getPendingQueue(30);
    $health  = OutageManager::getHealthLog(20);
    $locked  = SyncLock::isLocked();
    $dbOk    = true;
} catch (Exception $e) {
    $dbOk = false; $dbError = $e->getMessage();
    $stats = $qStats = []; $lastH = null; $logs = $pending = $health = []; $locked = false;
}

$isDown   = $lastH && $lastH['status'] === 'DOWN';
$isLocal  = THIS_SITE === 'local';
$thisSite = $isLocal ? '🖥 Local XAMPP' : '🌐 Live Server';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TSGImpex — Sync & Outage Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:      #0b0e14;
  --s1:      #111720;
  --s2:      #161d28;
  --s3:      #1c2535;
  --border:  #1e2d3d;
  --text:    #cdd8e3;
  --muted:   #4d6880;
  --green:   #22c55e;
  --red:     #ef4444;
  --amber:   #f59e0b;
  --blue:    #60a5fa;
  --purple:  #a78bfa;
  --mono:    'IBM Plex Mono', monospace;
  --sans:    'IBM Plex Sans', sans-serif;
}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:14px;min-height:100vh}

/* Offline banner */
#offlineBanner{
  display:none;position:fixed;top:0;left:0;right:0;z-index:999;
  background:rgba(239,68,68,0.9);backdrop-filter:blur(4px);
  color:#fff;font-family:var(--mono);font-size:12px;
  padding:8px 20px;display:flex;align-items:center;justify-content:space-between;
}
#offlineBanner.visible{display:flex}

/* Header */
.hdr{
  background:var(--s1);border-bottom:1px solid var(--border);
  padding:14px 22px;display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:100;
}
.logo{font-family:var(--mono);font-size:17px;font-weight:600;color:var(--blue);letter-spacing:-0.5px}
.logo span{color:var(--muted);font-weight:400}
.hdr-right{display:flex;align-items:center;gap:10px}
.site-tag{
  font-family:var(--mono);font-size:11px;padding:3px 9px;
  background:rgba(96,165,250,0.1);color:var(--blue);
  border:1px solid rgba(96,165,250,0.2);border-radius:4px;
}

/* Layout */
.wrap{padding:20px 22px;max-width:1120px}

/* Live status hero */
.hero{
  border-radius:10px;border:1px solid;padding:20px 22px;margin-bottom:18px;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
  transition:all 0.3s;
}
.hero.online{background:rgba(34,197,94,0.06);border-color:rgba(34,197,94,0.2)}
.hero.offline{background:rgba(239,68,68,0.08);border-color:rgba(239,68,68,0.25)}
.hero.unknown{background:rgba(96,165,250,0.05);border-color:rgba(96,165,250,0.15)}
.hero-label{font-family:var(--mono);font-size:20px;font-weight:600}
.hero.online  .hero-label{color:var(--green)}
.hero.offline .hero-label{color:var(--red)}
.hero.unknown .hero-label{color:var(--blue)}
.hero-sub{color:var(--muted);font-size:12px;margin-top:3px}
.hero-actions{display:flex;gap:8px;flex-wrap:wrap}

/* Cards row */
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-bottom:18px}
.card{background:var(--s1);border:1px solid var(--border);border-radius:8px;padding:13px 15px}
.card .lbl{font-size:10px;text-transform:uppercase;letter-spacing:0.8px;color:var(--muted);margin-bottom:5px}
.card .val{font-family:var(--mono);font-size:18px;font-weight:600}
.v-green{color:var(--green)} .v-red{color:var(--red)} .v-amber{color:var(--amber)} .v-blue{color:var(--blue)} .v-purple{color:var(--purple)}

/* Grid */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
@media(max-width:760px){.grid2{grid-template-columns:1fr}}

/* Panel */
.panel{background:var(--s1);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.panel-head{
  background:var(--s2);padding:10px 14px;
  display:flex;align-items:center;justify-content:space-between;
  border-bottom:1px solid var(--border);
}
.panel-title{font-family:var(--mono);font-size:11px;text-transform:uppercase;letter-spacing:0.7px;color:var(--muted)}
.panel-body{padding:14px}

/* Buttons */
.btn{
  font-family:var(--sans);font-size:12px;font-weight:500;
  padding:7px 14px;border-radius:5px;border:none;cursor:pointer;
  transition:opacity 0.15s;display:inline-flex;align-items:center;gap:5px;
}
.btn:hover:not(:disabled){opacity:0.8}
.btn:disabled{opacity:0.35;cursor:not-allowed}
.btn-green{background:var(--green);color:#000}
.btn-blue{background:rgba(96,165,250,0.12);color:var(--blue);border:1px solid rgba(96,165,250,0.25)}
.btn-amber{background:rgba(245,158,11,0.12);color:var(--amber);border:1px solid rgba(245,158,11,0.25)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
.btn-red{background:rgba(239,68,68,0.1);color:var(--red);border:1px solid rgba(239,68,68,0.25)}
.btn-sm{font-size:11px;padding:4px 9px}

/* Table */
.tbl{width:100%;border-collapse:collapse}
.tbl th{font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:0.6px;color:var(--muted);padding:7px 10px;text-align:left;border-bottom:1px solid var(--border)}
.tbl td{padding:7px 10px;border-bottom:1px solid rgba(30,45,61,0.5);font-family:var(--mono);font-size:11px}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,0.015)}
.empty{text-align:center;color:var(--muted);padding:18px!important;font-family:var(--sans)!important;font-size:13px!important}
.pill{display:inline-block;font-size:10px;padding:2px 7px;border-radius:10px;font-weight:600;font-family:var(--mono)}
.pill-g{background:rgba(34,197,94,0.12);color:var(--green)}
.pill-r{background:rgba(239,68,68,0.12);color:var(--red)}
.pill-a{background:rgba(245,158,11,0.12);color:var(--amber)}

/* Log */
.log-box{
  height:220px;overflow-y:auto;font-family:var(--mono);font-size:11px;
  line-height:1.7;padding:10px 12px;background:var(--s2);border-radius:6px;
}
.log-line{white-space:pre-wrap;word-break:break-all;padding:1px 0}
.log-line.err{color:var(--red)} .log-line.warn{color:var(--amber)} .log-line.info{color:var(--text)}

/* Health mini */
.health-item{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(30,45,61,0.4);font-size:11px;font-family:var(--mono)}
.health-item:last-child{border-bottom:none}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot-g{background:var(--green);box-shadow:0 0 4px var(--green)}
.dot-r{background:var(--red);box-shadow:0 0 4px var(--red)}
.dot-grey{background:var(--muted)}

/* Queue action pill */
.qa-insert{color:var(--green)} .qa-update{color:var(--blue)} .qa-delete{color:var(--red)}

/* Result flash */
.flash{
  border-radius:6px;padding:10px 13px;margin-top:12px;
  font-family:var(--mono);font-size:12px;line-height:1.8;display:none;
  border:1px solid var(--border);background:var(--s2);
}

/* Spinner */
.spin{display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,0.15);border-top-color:currentColor;border-radius:50%;animation:_s 0.7s linear infinite}
@keyframes _s{to{transform:rotate(360deg)}}

/* Pulse */
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.4}}
.pulse{animation:pulse 1.8s ease-in-out infinite}

/* Tip */
.tip{background:var(--s2);border:1px solid var(--border);border-left:3px solid var(--amber);border-radius:6px;padding:13px 15px;font-size:12px;line-height:1.9;margin-bottom:14px}
.tip code{background:rgba(245,158,11,0.1);color:var(--amber);padding:1px 5px;border-radius:3px;font-family:var(--mono);font-size:11px}
.tip-title{font-weight:600;color:var(--amber);font-size:13px;margin-bottom:8px}

/* Section full */
.sec-full{margin-bottom:14px}
.sec-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--s2);border-bottom:1px solid var(--border)}
</style>
</head>
<body>

<!-- Offline banner (shown via JS when live is down) -->
<div id="offlineBanner">
  <span class="pulse">⚡ OFFLINE MODE — Live server unreachable. All changes are queued locally.</span>
  <span id="bannerQueue" style="opacity:0.8"></span>
</div>

<!-- Header -->
<div class="hdr" id="mainHdr">
  <div style="display:flex;align-items:center;gap:10px">
    <div class="logo">TSG<span>impex</span> · Sync Dashboard</div>
    <span class="site-tag"><?= $thisSite ?></span>
  </div>
  <div class="hdr-right">
    <span style="font-size:11px;color:var(--muted);font-family:var(--mono)">v<?= SYNC_VERSION ?></span>
    <button class="btn btn-ghost btn-sm" onclick="fullRefresh()">↺ Refresh</button>
  </div>
</div>

<div class="wrap">

  <?php if (!$dbOk): ?>
  <div class="tip" style="border-left-color:var(--red)">
    <div class="tip-title">⚠ Database connection failed</div>
    <?= htmlspecialchars($dbError) ?><br>
    Check <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> in <code>sync_config.php</code>
  </div>
  <?php endif; ?>

  <!-- Hero status -->
  <div class="hero <?= !$dbOk ? 'unknown' : ($isDown ? 'offline' : 'online') ?>" id="heroBox">
    <div>
      <div class="hero-label" id="heroLabel">
        <?php if (!$dbOk): ?>⚙ DB Error
        <?php elseif ($isDown): ?>⚡ OFFLINE MODE
        <?php else: ?>✓ ONLINE<?php endif; ?>
      </div>
      <div class="hero-sub" id="heroSub">
        <?php if ($lastH): ?>
          Last checked: <?= $lastH['checked_at'] ?> — <?= htmlspecialchars($lastH['response']) ?>
        <?php else: ?>Click "Check Now" to verify live server<?php endif; ?>
      </div>
    </div>
    <div class="hero-actions">
      <button class="btn btn-blue" onclick="pingRemote()">↺ Check Now</button>
      <?php if ($isLocal): ?>
      <button class="btn btn-green" id="btnSync" onclick="runSync()" <?= ($locked||!$dbOk) ? 'disabled':'' ?>>⇄ Sync Now</button>
      <button class="btn btn-amber" id="btnReplay" onclick="replayQueue()" <?= ($isDown||$qStats['pending']===0||!$isLocal) ? 'disabled':'' ?>>
        ↑ Replay Queue (<?= $qStats['pending'] ?>)
      </button>
      <button class="btn btn-ghost" onclick="runWatchdog()">▶ Watchdog</button>
      <?php else: ?>
      <button class="btn btn-green" id="btnSync" onclick="runSync()" <?= ($locked||!$dbOk) ? 'disabled':'' ?>>⇄ Sync Now</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="flash" id="flash"></div>

  <!-- Stat cards -->
  <div class="cards">
    <div class="card">
      <div class="lbl">Sync Lock</div>
      <div class="val <?= $locked ? 'v-amber' : 'v-green' ?>" id="cLock"><?= $locked ? '⏳ Running' : '● Free' ?></div>
    </div>
    <div class="card">
      <div class="lbl">Tables Synced</div>
      <div class="val v-blue"><?= count(SYNC_TABLES) ?></div>
    </div>
    <?php if ($isLocal): ?>
    <div class="card">
      <div class="lbl">Queued Changes</div>
      <div class="val <?= ($qStats['pending']??0) > 0 ? 'v-amber' : 'v-green' ?>" id="cPending"><?= $qStats['pending'] ?? 0 ?></div>
    </div>
    <div class="card">
      <div class="lbl">Replayed OK</div>
      <div class="val v-green" id="cReplayed"><?= $qStats['replayed'] ?? 0 ?></div>
    </div>
    <div class="card">
      <div class="lbl">Replay Errors</div>
      <div class="val <?= ($qStats['failed']??0) > 0 ? 'v-red' : 'v-green' ?>" id="cFailed"><?= $qStats['failed'] ?? 0 ?></div>
    </div>
    <?php endif; ?>
    <div class="card">
      <div class="lbl">DB Connection</div>
      <div class="val <?= $dbOk ? 'v-green' : 'v-red' ?>"><?= $dbOk ? '● OK' : '● Error' ?></div>
    </div>
  </div>

  <!-- Main content grid -->
  <div class="grid2">

    <!-- Left: Table status -->
    <div class="panel">
      <div class="panel-head">
        <span class="panel-title">Table Status</span>
        <button class="btn btn-ghost btn-sm" onclick="fullRefresh()">↺</button>
      </div>
      <table class="tbl" id="tblStats">
        <thead><tr><th>Table</th><th>Rows</th><th>Last Modified</th><th>Status</th></tr></thead>
        <tbody id="tblStatsBody">
          <?php if ($dbOk): foreach (SYNC_TABLES as $t => $pk): $s = $stats[$t] ?? []; ?>
          <tr>
            <td><?= htmlspecialchars($t) ?></td>
            <td><?= $s['rows'] ?? '?' ?></td>
            <td><?= $s['last_modified'] ?? 'N/A' ?></td>
            <td>
              <?php if (($s['rows']??'?') === '?'): ?>
                <span class="pill pill-r">Missing cols</span>
              <?php else: ?>
                <span class="pill pill-g">Ready</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Right: Health log -->
    <div class="panel">
      <div class="panel-head">
        <span class="panel-title">Live Server Health</span>
        <button class="btn btn-ghost btn-sm" onclick="fullRefresh()">↺</button>
      </div>
      <div class="panel-body" id="healthList" style="max-height:220px;overflow-y:auto">
        <?php if (empty($health)): ?>
          <div style="color:var(--muted);font-size:13px;text-align:center;padding:20px">No health checks yet — run watchdog</div>
        <?php else: foreach ($health as $h): ?>
          <div class="health-item">
            <span class="dot <?= $h['status']==='UP' ? 'dot-g' : 'dot-r' ?>"></span>
            <span style="color:var(--muted)"><?= $h['checked_at'] ?></span>
            <span><?= $h['status'] ?> — <?= htmlspecialchars($h['response']??'') ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <?php if ($isLocal): ?>
  <!-- Queue panel (local only) -->
  <div class="panel sec-full">
    <div class="sec-head">
      <span class="panel-title">Outage Queue — Changes Pending Replay</span>
      <button class="btn btn-ghost btn-sm" onclick="fullRefresh()">↺</button>
    </div>
    <table class="tbl" id="queueTbl">
      <thead><tr><th>#</th><th>Table</th><th>Record ID</th><th>Action</th><th>Queued At</th><th>Error</th></tr></thead>
      <tbody id="queueBody">
        <?php if (empty($pending)): ?>
        <tr><td colspan="6" class="empty">Queue is clear — no pending changes ✓</td></tr>
        <?php else: foreach ($pending as $q): ?>
        <tr>
          <td><?= $q['id'] ?></td>
          <td><?= htmlspecialchars($q['table_name']) ?></td>
          <td><?= htmlspecialchars($q['pk_value']) ?></td>
          <td class="qa-<?= strtolower($q['action']) ?>"><?= $q['action'] ?></td>
          <td><?= $q['queued_at'] ?></td>
          <td style="color:var(--red)"><?= htmlspecialchars($q['error']??'') ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Sync log -->
  <div class="panel sec-full">
    <div class="sec-head">
      <span class="panel-title">Sync Log</span>
      <button class="btn btn-ghost btn-sm" onclick="fullRefresh()">↺</button>
    </div>
    <div class="panel-body" style="padding:10px">
      <div class="log-box" id="logBox">
        <?php if (empty($logs)): ?>
          <span style="color:var(--muted)">No log entries yet.</span>
        <?php else: foreach ($logs as $l): ?>
          <div class="log-line <?= str_contains($l,'[ERROR]')?'err':(str_contains($l,'[WARN]')?'warn':'info') ?>"><?= htmlspecialchars($l) ?></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Integration tip -->
  <div class="tip">
    <div class="tip-title">📌 How to make your app queue changes during outage</div>
    Add to every page that writes to the database:<br>
    <code>require_once '/path/to/tsgimpex/app_sync.php';</code><br>
    <code>echo AppSync::offlineBanner();</code> — shows orange offline bar when live is down<br><br>
    Then replace DB writes:<br>
    <code>AppSync::insert('orders', 'id', $dataArray);</code> — instead of INSERT query<br>
    <code>AppSync::update('orders', 'id', $id, $dataArray);</code> — instead of UPDATE query<br>
    <code>AppSync::delete('orders', 'id', $id);</code> — instead of DELETE query<br><br>
    Watchdog auto-replays all queued changes when live comes back. Schedule <code>run_watchdog.bat</code> every 5 minutes via Windows Task Scheduler.
  </div>

</div><!-- /wrap -->

<script>
const isLocal = <?= $isLocal ? 'true' : 'false' ?>;

async function post(action) {
  const fd = new FormData(); fd.append('action', action);
  const r = await fetch('', { method:'POST', body:fd });
  return r.json();
}

function flash(html, isErr = false) {
  const el = document.getElementById('flash');
  el.style.display = 'block';
  el.style.borderColor = isErr ? 'rgba(239,68,68,0.3)' : 'rgba(34,197,94,0.2)';
  el.innerHTML = html;
  setTimeout(() => el.style.display = 'none', 8000);
}

function setHero(state, label, sub) {
  const h = document.getElementById('heroBox');
  h.className = 'hero ' + state;
  document.getElementById('heroLabel').textContent = label;
  document.getElementById('heroSub').textContent = sub;
  // Offline banner
  const banner = document.getElementById('offlineBanner');
  if (state === 'offline' && isLocal) {
    banner.classList.add('visible');
    document.getElementById('mainHdr').style.marginTop = '38px';
  } else {
    banner.classList.remove('visible');
    document.getElementById('mainHdr').style.marginTop = '0';
  }
}

async function pingRemote() {
  setHero('unknown', '↺ Checking…', 'Pinging live server...');
  const d = await post('ping_remote');
  const up = d.live_up;
  const now = new Date().toLocaleString();
  if (up) {
    setHero('online', '✓ ONLINE', 'Checked: ' + now);
    document.getElementById('btnSync').disabled = false;
  } else {
    setHero('offline', '⚡ OFFLINE MODE', 'Live unreachable as of ' + now);
    if (isLocal) document.getElementById('btnSync').disabled = true;
  }
  flash(up
    ? '<span style="color:var(--green)">✓ Live server responded OK</span>'
    : '<span style="color:var(--red)">✗ Live server is DOWN — offline mode active. Changes will queue.</span>',
    !up
  );
}

async function runSync() {
  const btn = document.getElementById('btnSync');
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Syncing…';
  const d = await post('run_sync');
  if (d.ok && d.result?.tables) {
    let html = '<span style="color:var(--green)">✓ Sync complete</span> — ' + d.result.started_at + '<br>';
    for (const [t, s] of Object.entries(d.result.tables)) {
      const e = s.errors.length ? ' <span style="color:var(--red)">⚠ ' + s.errors.join(', ') + '</span>' : '';
      html += `<br>${t}: pushed <b>${s.pushed}</b>  pulled <b>${s.pulled}</b>${e}`;
    }
    flash(html);
  } else {
    flash('<span style="color:var(--red)">✗ ' + (d.error || JSON.stringify(d)) + '</span>', true);
  }
  btn.disabled = false; btn.innerHTML = '⇄ Sync Now';
  fullRefresh();
}

async function replayQueue() {
  const btn = document.getElementById('btnReplay');
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Replaying…';
  const d = await post('replay_queue');
  if (d.ok) {
    const r = d.result;
    flash(`<span style="color:var(--green)">✓ Replay done</span> — sent: <b>${r.replayed}</b>  failed: <b style="color:${r.failed>0?'var(--red)':'inherit'}">${r.failed}</b>`);
  } else {
    flash('<span style="color:var(--red)">✗ ' + (d.error||'Unknown') + '</span>', true);
  }
  btn.innerHTML = '↑ Replay Queue';
  fullRefresh();
}

async function runWatchdog() {
  flash('<span class="spin"></span> Running watchdog...');
  const d = await post('run_watchdog');
  if (d.ok) {
    const r = d.result;
    const up = r.live_up;
    let html = `<span style="color:${up?'var(--green)':'var(--red)'}">${up?'✓ Live UP':'✗ Live DOWN'}</span>`;
    if (r.replayed > 0) html += `  replayed: <b>${r.replayed}</b>  failed: <b>${r.failed}</b>`;
    if (r.regular_sync) html += `  + regular sync completed`;
    flash(html, !up);
    setHero(up ? 'online':'offline', up ? '✓ ONLINE':'⚡ OFFLINE MODE', new Date().toLocaleString());
  }
  fullRefresh();
}

async function fullRefresh() {
  const d = await post('get_status');
  if (!d.ok) return;

  // Cards
  if (d.queue) {
    const q = d.queue;
    el('cPending')  && (el('cPending').textContent  = q.pending);
    el('cReplayed') && (el('cReplayed').textContent = q.replayed);
    el('cFailed')   && (el('cFailed').textContent   = q.failed);
    el('cPending')  && (el('cPending').className = 'val ' + (q.pending>0?'v-amber':'v-green'));
    el('cFailed')   && (el('cFailed').className  = 'val ' + (q.failed>0?'v-red':'v-green'));
    const btnR = document.getElementById('btnReplay');
    if (btnR) { btnR.innerHTML = `↑ Replay Queue (${q.pending})`; btnR.disabled = !d.live_up||q.pending===0; }
  }
  el('cLock') && (el('cLock').textContent = d.locked ? '⏳ Running' : '● Free');

  // Table stats
  if (d.stats) {
    const tbody = document.getElementById('tblStatsBody');
    const tables = <?= json_encode(SYNC_TABLES) ?>;
    tbody.innerHTML = Object.entries(tables).map(([t,pk]) => {
      const s = d.stats[t] || {};
      const ok = s.rows !== '?';
      return `<tr>
        <td>${t}</td><td>${s.rows??'?'}</td><td>${s.last_modified??'N/A'}</td>
        <td><span class="pill ${ok?'pill-g':'pill-r'}">${ok?'Ready':'Missing cols'}</span></td>
      </tr>`;
    }).join('');
  }

  // Logs
  if (d.logs?.length) {
    document.getElementById('logBox').innerHTML = d.logs.map(l => {
      const c = l.includes('[ERROR]')?'err':l.includes('[WARN]')?'warn':'info';
      return `<div class="log-line ${c}">${l.replace(/</g,'&lt;')}</div>`;
    }).join('');
  }

  // Queue table
  const qb = document.getElementById('queueBody');
  if (qb) {
    if (!d.pending?.length) {
      qb.innerHTML = '<tr><td colspan="6" class="empty">Queue is clear ✓</td></tr>';
    } else {
      qb.innerHTML = d.pending.map(q => `<tr>
        <td>${q.id}</td><td>${q.table_name}</td><td>${q.pk_value}</td>
        <td class="qa-${q.action.toLowerCase()}">${q.action}</td>
        <td>${q.queued_at}</td>
        <td style="color:var(--red)">${q.error||''}</td>
      </tr>`).join('');
    }
  }

  // Health
  const hl = document.getElementById('healthList');
  if (hl && d.health?.length) {
    hl.innerHTML = d.health.map(h =>
      `<div class="health-item">
        <span class="dot ${h.status==='UP'?'dot-g':'dot-r'}"></span>
        <span style="color:var(--muted)">${h.checked_at}</span>
        <span>${h.status} — ${(h.response||'').replace(/</g,'&lt;')}</span>
      </div>`
    ).join('');
  }

  // Banner queue count
  if (d.queue) document.getElementById('bannerQueue').textContent = d.queue.pending + ' change(s) queued';
}

function el(id) { return document.getElementById(id); }

// Auto ping on load, refresh every 60s
pingRemote();
setInterval(fullRefresh, 60000);
</script>
</body>
</html>
