<?php
/**
 * GST Verification Utility
 * Path: modules/gst_checker.php
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('gst_checker', 'view');

$pageTitle = 'GST Verification';

// AJAX handler — return JSON and exit
if (isset($_GET['ajax']) && isset($_GET['gstin'])) {
    header('Content-Type: application/json');

    $gstin = strtoupper(trim($_GET['gstin']));

    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin)) {
        echo json_encode(['success' => false, 'error' => 'Invalid GSTIN format. Must be 15 alphanumeric characters.']);
        exit;
    }

    $api_key = '3cb4eab4eae9750a3e73aa062084deca';
    $url     = 'https://sheet.gstincheck.co.in/check/' . $api_key . '/' . urlencode($gstin);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
        ],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curl_err]);
        exit;
    }
    if ($http_code !== 200) {
        echo json_encode(['success' => false, 'error' => 'API returned HTTP ' . $http_code]);
        exit;
    }

    $data = json_decode($response, true);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid API response. Raw: ' . substr($response, 0, 300)]);
        exit;
    }
    // Debug: show raw structure temporarily
    if (!empty($data['flag']) && $data['flag'] == 0) {
        echo json_encode(['success' => false, 'error' => $data['message'] ?? 'GSTIN not found.', 'raw' => $data]);
        exit;
    }
    if (!empty($data['message']) && empty($data['data'])) {
        echo json_encode(['success' => false, 'error' => $data['message'], 'raw' => $data]);
        exit;
    }

    // Build address — gstincheck.co.in stores address in data->pradr
    $d       = $data['data'] ?? $data;
    $address = '';
    if (!empty($d['pradr']['adr'])) {
        $address = $d['pradr']['adr'];
    } elseif (!empty($d['pradr'])) {
        $a = $d['pradr'];
        $address = implode(', ', array_filter([
            $a['bno']  ?? '', $a['bname'] ?? '', $a['flno'] ?? '',
            $a['st']   ?? '', $a['loc']   ?? '', $a['dst']  ?? '',
            $a['stcd'] ?? '', $a['pncd']  ?? '',
        ]));
    }

    // gstincheck.co.in returns data inside $data['data']
    $d = $data['data'] ?? $data;

    echo json_encode([
        'success'     => true,
        'gstin'       => $d['gstin']     ?? $gstin,
        'legal_name'  => $d['lgnm']      ?? ($d['legal_name']  ?? ''),
        'trade_name'  => $d['tradeNam']  ?? ($d['trade_name']  ?? ''),
        'status'      => $d['sts']       ?? ($d['status']      ?? ''),
        'reg_date'    => $d['rgdt']      ?? ($d['reg_date']    ?? ''),
        'dealer_type' => $d['dty']       ?? ($d['dealer_type'] ?? ''),
        'entity_type' => $d['ctb']       ?? ($d['entity_type'] ?? ''),
        'pan'         => $d['pan']       ?? '',
        'business'    => $d['nba']       ?? '',
        'address'     => $address,
    ]);
    exit;
}

require_once '../includes/header.php';
?>

<style>
.gst-card     { max-width: 680px; margin: 0 auto; }
.result-table td:first-child { width: 40%; color: #666; font-size: .82rem; }
.result-table td:last-child  { font-size: .9rem; }
.status-active   { background:#d4edda; color:#155724; border-radius:6px; padding:8px 14px; }
.status-cancelled{ background:#f8d7da; color:#721c24; border-radius:6px; padding:8px 14px; }
.status-other    { background:#fff3cd; color:#856404; border-radius:6px; padding:8px 14px; }
.history-item { border-left: 3px solid #dee2e6; padding: 6px 12px; margin-bottom: 8px; cursor:pointer; }
.history-item:hover { border-color: #1a6b2f; background:#f8fff9; }
.history-item .gstin-no { font-family: monospace; font-size:.85rem; font-weight:600; }
</style>

<div class="container-fluid py-3">
  <div class="gst-card">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="mb-0 fw-bold"><i class="bi bi-patch-check-fill me-2" style="color:#1a6b2f"></i>GST Verification</h5>
        <small class="text-muted">Verify GSTIN status and details instantly</small>
      </div>
    </div>

    <!-- Search Box -->
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-body">
        <label class="form-label small fw-semibold">Enter GSTIN</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" id="gstinInput" class="form-control text-uppercase"
                 placeholder="e.g. 07AAECT1379A1ZL"
                 maxlength="15" autocomplete="off"
                 style="font-family:monospace;letter-spacing:.08em;font-size:1rem;">
          <button class="btn btn-success px-4" id="verifyBtn" onclick="doVerify()">
            <i class="bi bi-patch-check me-1"></i>Verify
          </button>
        </div>
        <div class="form-text">15-character GSTIN — letters are auto-uppercased</div>
      </div>
    </div>

    <!-- Result Area -->
    <div id="resultArea" class="d-none">

      <!-- Status Banner -->
      <div id="statusBanner" class="mb-3 fw-semibold small"></div>

      <!-- Details Card -->
      <div class="card shadow-sm border-0 mb-3">
        <div class="card-body pb-2">
          <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.06em">Taxpayer Details</div>
          <table class="table table-sm table-borderless result-table mb-0">
            <tbody>
              <tr><td>GSTIN</td>          <td id="r_gstin" class="fw-bold font-monospace"></td></tr>
              <tr><td>Legal Name</td>     <td id="r_legal" class="fw-semibold"></td></tr>
              <tr><td>Trade Name</td>     <td id="r_trade"></td></tr>
              <tr><td>PAN</td>            <td id="r_pan" class="font-monospace"></td></tr>
              <tr><td>Entity Type</td>    <td id="r_entity"></td></tr>
              <tr><td>Dealer Type</td>    <td id="r_dealer"></td></tr>
              <tr><td>Business Type</td>  <td id="r_business"></td></tr>
              <tr><td>Registered On</td>  <td id="r_regdate"></td></tr>
              <tr><td>Address</td>        <td id="r_address"></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Copy button -->
      <div class="d-flex gap-2 mb-3">
        <button class="btn btn-sm btn-outline-secondary" onclick="copyResult()">
          <i class="bi bi-clipboard me-1"></i>Copy Details
        </button>
        <button class="btn btn-sm btn-outline-primary" onclick="resetForm()">
          <i class="bi bi-arrow-repeat me-1"></i>Verify Another
        </button>
      </div>
    </div>

    <!-- Error Area -->
    <div id="errorArea" class="alert alert-danger small py-2 d-none"></div>

    <!-- Loading -->
    <div id="loadingArea" class="text-center py-4 d-none">
      <div class="spinner-border text-success"></div>
      <div class="mt-2 small text-muted">Fetching GST details from GSTN...</div>
    </div>

    <!-- Search History -->
    <div id="historySection" class="d-none">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <small class="fw-semibold text-muted text-uppercase" style="letter-spacing:.06em">Recent Lookups</small>
        <button class="btn btn-sm btn-link text-muted p-0" onclick="clearHistory()">Clear</button>
      </div>
      <div id="historyList"></div>
    </div>

  </div>
</div>

<script>
const HISTORY_KEY = 'gst_checker_history';

function doVerify(gstin) {
    gstin = (gstin || document.getElementById('gstinInput').value).trim().toUpperCase();
    if (!gstin) { document.getElementById('gstinInput').focus(); return; }

    // Validate format
    const re = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
    if (!re.test(gstin)) {
        showError('Invalid GSTIN format. Must be exactly 15 characters (e.g. 07AAECT1379A1ZL).');
        return;
    }

    document.getElementById('gstinInput').value = gstin;
    showLoading();

    fetch('gst_checker.php?ajax=1&gstin=' + encodeURIComponent(gstin))
        .then(r => r.json())
        .then(data => {
            hideLoading();
            if (!data.success) { showError(data.error + (data.raw ? ' | Raw: ' + JSON.stringify(data.raw) : '')); return; }
            showResult(data);
            saveHistory(gstin, data.legal_name, data.status);
        })
        .catch(() => { hideLoading(); showError('Network error. Please try again.'); });
}

function showResult(d) {
    document.getElementById('errorArea').classList.add('d-none');
    const sts = (d.status || '').toLowerCase();
    const banner = document.getElementById('statusBanner');
    if (sts === 'active') {
        banner.className = 'mb-3 fw-semibold small status-active';
        banner.innerHTML = '✅ GSTIN is <strong>Active</strong>';
    } else if (sts.includes('cancel')) {
        banner.className = 'mb-3 fw-semibold small status-cancelled';
        banner.innerHTML = '❌ GSTIN is <strong>' + d.status + '</strong>';
    } else {
        banner.className = 'mb-3 fw-semibold small status-other';
        banner.innerHTML = '⚠️ Status: <strong>' + (d.status || 'Unknown') + '</strong>';
    }

    document.getElementById('r_gstin').textContent    = d.gstin       || '-';
    document.getElementById('r_legal').textContent    = d.legal_name  || '-';
    document.getElementById('r_trade').textContent    = d.trade_name  || '-';
    document.getElementById('r_pan').textContent      = d.pan         || '-';
    document.getElementById('r_entity').textContent   = d.entity_type || '-';
    document.getElementById('r_dealer').textContent   = d.dealer_type || '-';
    document.getElementById('r_business').textContent = d.business    || '-';
    document.getElementById('r_regdate').textContent  = d.reg_date    || '-';
    document.getElementById('r_address').textContent  = d.address     || '-';

    document.getElementById('resultArea').classList.remove('d-none');
}

function showError(msg) {
    document.getElementById('resultArea').classList.add('d-none');
    const el = document.getElementById('errorArea');
    el.textContent = '❌ ' + msg;
    el.classList.remove('d-none');
}

function showLoading() {
    document.getElementById('resultArea').classList.add('d-none');
    document.getElementById('errorArea').classList.add('d-none');
    document.getElementById('loadingArea').classList.remove('d-none');
    document.getElementById('verifyBtn').disabled = true;
}

function hideLoading() {
    document.getElementById('loadingArea').classList.add('d-none');
    document.getElementById('verifyBtn').disabled = false;
}

function resetForm() {
    document.getElementById('gstinInput').value = '';
    document.getElementById('resultArea').classList.add('d-none');
    document.getElementById('errorArea').classList.add('d-none');
    document.getElementById('gstinInput').focus();
}

function copyResult() {
    const rows = document.querySelectorAll('.result-table tr');
    let text = 'GST Verification Result\n' + '='.repeat(30) + '\n';
    rows.forEach(tr => {
        const cells = tr.querySelectorAll('td');
        if (cells.length === 2) text += cells[0].textContent.trim() + ': ' + cells[1].textContent.trim() + '\n';
    });
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target.closest('button');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check me-1"></i>Copied!';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
}

// History
function saveHistory(gstin, name, status) {
    let h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
    h = h.filter(x => x.gstin !== gstin); // remove duplicate
    h.unshift({ gstin, name, status, time: new Date().toLocaleString('en-IN') });
    if (h.length > 10) h = h.slice(0, 10);
    localStorage.setItem(HISTORY_KEY, JSON.stringify(h));
    renderHistory();
}

function renderHistory() {
    const h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
    const sec = document.getElementById('historySection');
    const list = document.getElementById('historyList');
    if (!h.length) { sec.classList.add('d-none'); return; }
    sec.classList.remove('d-none');
    list.innerHTML = h.map(x => {
        const sts = (x.status || '').toLowerCase();
        const badge = sts === 'active'
            ? '<span class="badge bg-success ms-1">Active</span>'
            : '<span class="badge bg-danger ms-1">' + (x.status||'?') + '</span>';
        return `<div class="history-item" onclick="doVerify('${x.gstin}')">
            <div class="d-flex justify-content-between align-items-center">
              <span class="gstin-no">${x.gstin}</span>${badge}
            </div>
            <div class="small text-muted">${x.name || ''} &nbsp;·&nbsp; ${x.time}</div>
          </div>`;
    }).join('');
}

function clearHistory() {
    localStorage.removeItem(HISTORY_KEY);
    renderHistory();
}

// Allow Enter key
document.getElementById('gstinInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') doVerify();
});

// Auto-uppercase as you type
document.getElementById('gstinInput').addEventListener('input', e => {
    const pos = e.target.selectionStart;
    e.target.value = e.target.value.toUpperCase();
    e.target.setSelectionRange(pos, pos);
});

// Load history on page load
document.addEventListener('DOMContentLoaded', renderHistory);
</script>

<?php require_once '../includes/footer.php'; ?>
