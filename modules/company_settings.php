<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('company_settings', 'view');

/* Ensure columns exist */
function csAddCol($db, $col, $def) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='company_settings' AND COLUMN_NAME='$col' LIMIT 1")->num_rows)
        $db->query("ALTER TABLE company_settings ADD COLUMN $col $def");
}
csAddCol($db,'smtp_host',      "VARCHAR(120) DEFAULT ''");
csAddCol($db,'smtp_port',      "SMALLINT DEFAULT 587");
csAddCol($db,'smtp_user',      "VARCHAR(120) DEFAULT ''");
csAddCol($db,'smtp_pass',      "VARCHAR(255) DEFAULT ''");
csAddCol($db,'smtp_secure',    "VARCHAR(10) DEFAULT 'tls'");
csAddCol($db,'smtp_from_name', "VARCHAR(120) DEFAULT ''");
csAddCol($db,'fy_start_no',    "INT DEFAULT 1 COMMENT 'FY challan starting number'");
csAddCol($db,'desktop_nav_mode',"VARCHAR(20) DEFAULT 'classic'");

function csPlain($value) {
    $value = trim((string)($value ?? ''));
    for ($i = 0; $i < 5; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return $value;
}

function csSql($value) {
    return getDB()->real_escape_string(csPlain($value));
}

/* ════ POST HANDLER ════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('company_settings', 'update');
    $desktop_nav_mode_post = $_POST['desktop_nav_mode'] ?? 'classic';
    if (!in_array($desktop_nav_mode_post, ['classic', 'wide_burger'], true)) {
        $desktop_nav_mode_post = 'classic';
    }
    $_POST['desktop_nav_mode'] = $desktop_nav_mode_post;
    $_SESSION['desktop_nav_mode_preview'] = $desktop_nav_mode_post;

    $existing = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc();

    $text_fields = ['company_name','address','city','state','pincode','phone','email',
                    'gstin','pan','bank_name','account_no','ifsc_code',
                    'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure','smtp_from_name','desktop_nav_mode'];
    $parts = [];
    foreach ($text_fields as $f) {
        $parts[] = "$f='" . csSql($_POST[$f] ?? '') . "'";
    }
    $parts[] = "fy_start_no=" . (int)($_POST['fy_start_no'] ?? 1);

    if ($existing) {
        $db->query("UPDATE company_settings SET " . implode(',', $parts) . " WHERE id={$existing['id']}");
    } else {
        $cols = implode(',', $text_fields);
        $vals = implode(',', array_map(fn($f) => "'" . csSql($_POST[$f] ?? '') . "'", $text_fields));
        $db->query("INSERT INTO company_settings ($cols) VALUES ($vals)");
    }

    // Sync doc_sequences
    $new_start = (int)($_POST['fy_start_no'] ?? 1);
    if ($new_start >= 1) {
        $m = (int)date('m'); $y = (int)date('Y');
        $fs = $m >= 4 ? $y : $y - 1; $fe = $fs + 1;
        $fy = str_pad($fs % 100, 2, '0', STR_PAD_LEFT) . str_pad($fe % 100, 2, '0', STR_PAD_LEFT);
        $seq_key = "challan_fy{$fy}";
        $set_val = $new_start - 1;
        $db->query("CREATE TABLE IF NOT EXISTS doc_sequences (seq_key VARCHAR(50) PRIMARY KEY, last_val INT UNSIGNED NOT NULL DEFAULT 0)");
        $db->query("INSERT INTO doc_sequences (seq_key, last_val) VALUES ('$seq_key', $set_val)
                    ON DUPLICATE KEY UPDATE last_val = $set_val");
    }

    showAlert('success', 'Company settings saved successfully.');
    redirect('company_settings.php');
}

$company = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc() ?? [];
if (!empty($_SESSION['desktop_nav_mode_preview'])) {
    $company['desktop_nav_mode'] = $_SESSION['desktop_nav_mode_preview'];
}
foreach (['company_name','address','city','state','pincode','phone','email','gstin','pan','bank_name','account_no','ifsc_code','smtp_host','smtp_user','smtp_pass','smtp_secure','smtp_from_name','desktop_nav_mode'] as $field) {
    if (array_key_exists($field, $company)) $company[$field] = csPlain($company[$field]);
}
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-gear me-2"></i>Company Settings';</script>
<style>
@media (max-width: 575.98px) {
    .smtp-test-btn {
        width: 100%;
    }
    .smtp-test-status {
        display: block;
        width: 100%;
        margin-left: 0 !important;
        margin-top: .75rem;
    }
}
</style>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Company Settings</h5>
</div>
<form method="POST">
<div class="row g-3">

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-building me-2"></i>Company Information</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label">Company Name *</label>
            <input type="text" name="company_name" class="form-control" required value="<?= htmlspecialchars($company['company_name']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($company['phone']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($company['email']??'') ?>">
        </div>
        <div class="col-12 col-md-8">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($company['address']??'') ?></textarea>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($company['city']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">State</label>
            <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($company['state']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Pincode</label>
            <input type="text" name="pincode" class="form-control" value="<?= htmlspecialchars($company['pincode']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">GSTIN</label>
            <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= htmlspecialchars($company['gstin']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">PAN</label>
            <input type="text" name="pan" class="form-control" maxlength="10" value="<?= htmlspecialchars($company['pan']??'') ?>">
        </div>
    </div></div></div></div>

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-calendar2-check me-2"></i>Financial Year &amp; Challan Settings</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label fw-semibold">FY Challan Starting Number</label>
            <div class="input-group">
                <span class="input-group-text bg-success text-white">DC/<?php
                    $m=(int)date('m'); $y=(int)date('Y');
                    $fs=$m>=4?$y:$y-1; $fe=$fs+1;
                    echo str_pad($fs%100,2,'0',STR_PAD_LEFT).str_pad($fe%100,2,'0',STR_PAD_LEFT);
                ?>/</span>
                <input type="number" name="fy_start_no" class="form-control" min="1" max="9999"
                       value="<?= (int)($company['fy_start_no'] ?? 1) ?>">
            </div>
            <div class="form-text">Set to <strong>1</strong> for fresh FY start. Set higher if continuing from old system.</div>
        </div>
        <div class="col-12 col-md-8">
            <div class="alert alert-info py-2 mb-0 mt-4">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Note:</strong> Changing this only affects the <em>next new</em> challan if the current FY sequence has not started yet.
            </div>
        </div>
    </div></div></div></div>

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-bank me-2"></i>Bank Details</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-sm-6 col-md-5">
            <label class="form-label">Bank Name</label>
            <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($company['bank_name']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Account Number</label>
            <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($company['account_no']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">IFSC Code</label>
            <input type="text" name="ifsc_code" class="form-control" value="<?= htmlspecialchars($company['ifsc_code']??'') ?>">
        </div>
    </div></div></div></div>

    <div class="col-12"><div class="card">
        <div class="card-header"><i class="bi bi-envelope-at me-2"></i>Email / SMTP Settings
            <small class="ms-2 text-muted fw-normal" style="font-size:.75rem">Used for Despatch and Report emails</small>
        </div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label">SMTP Host</label>
            <input type="text" name="smtp_host" class="form-control" placeholder="e.g. smtp.gmail.com" value="<?= htmlspecialchars($company['smtp_host']??'') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">SMTP Port</label>
            <input type="number" name="smtp_port" class="form-control" placeholder="587" value="<?= htmlspecialchars($company['smtp_port']??'587') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Security</label>
            <select name="smtp_secure" class="form-select">
                <option value="tls" <?= ($company['smtp_secure']??'tls')==='tls'?'selected':'' ?>>STARTTLS (587)</option>
                <option value="ssl" <?= ($company['smtp_secure']??'')==='ssl'?'selected':'' ?>>SSL (465)</option>
                <option value=""    <?= ($company['smtp_secure']??'')==''?'selected':'' ?>>None (25)</option>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">From Name</label>
            <input type="text" name="smtp_from_name" class="form-control" placeholder="e.g. Despatch Team" value="<?= htmlspecialchars($company['smtp_from_name']??'') ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">SMTP Username</label>
            <input type="text" name="smtp_user" class="form-control" placeholder="your@email.com" value="<?= htmlspecialchars($company['smtp_user']??'') ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">SMTP Password</label>
            <div class="input-group">
                <input type="password" id="smtpPassInput" name="smtp_pass" class="form-control" value="<?= htmlspecialchars($company['smtp_pass']??'') ?>" autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" id="toggleSmtpPassBtn" onclick="toggleSmtpPassword()" title="Show/Hide Password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <div class="form-text">For Gmail use an App Password (not your login password)</div>
        </div>
        <div class="col-12">
            <div class="d-grid d-sm-flex align-items-start gap-2">
                <button type="button" class="btn btn-outline-success btn-sm smtp-test-btn" id="btnSmtpTest" onclick="sendSmtpTest()">
                    <i class="bi bi-envelope-check me-1"></i>Send Test Email
                </button>
                <div id="smtpTestStatus" class="smtp-test-status flex-grow-1"></div>
            </div>
            <div class="form-text">Save settings before testing. The test email goes to Company Email, or SMTP Username if Company Email is blank.</div>
        </div>
    </div></div></div></div>

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-layout-sidebar me-2"></i>Interface Settings</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label">Desktop Navigation</label>
            <select name="desktop_nav_mode" class="form-select">
                <option value="classic" <?= ($company['desktop_nav_mode'] ?? 'classic') === 'classic' ? 'selected' : '' ?>>Classic Sidebar</option>
                <option value="wide_burger" <?= ($company['desktop_nav_mode'] ?? '') === 'wide_burger' ? 'selected' : '' ?>>Wide Screen + Burger Menu</option>
            </select>
            <div class="form-text">Choose between the full sidebar and a wider desktop layout with the menu behind the burger button.</div>
        </div>
    </div></div></div></div>

    <div class="col-12 text-end">
        <a href="data_cleanup.php" class="btn btn-outline-danger me-2">
            <i class="bi bi-trash3 me-1"></i>Data Cleanup
        </a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check2 me-1"></i>Save Settings
        </button>
    </div>
</div>
</form>

<script>
function toggleSmtpPassword() {
    var input = document.getElementById('smtpPassInput');
    var btn = document.getElementById('toggleSmtpPassBtn');
    if (!input || !btn) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
}

function sendSmtpTest() {
    var btn = document.getElementById('btnSmtpTest');
    var st = document.getElementById('smtpTestStatus');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';
    st.innerHTML = '';

    fetch('test_smtp_settings.php', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-envelope-check me-1"></i>Send Test Email';
            if (d.ok) {
                st.innerHTML = '<span class="badge bg-success">&#10003; ' + d.msg + '</span>';
            } else {
                st.innerHTML = '<div class="alert alert-danger py-2 mt-2 mb-0" style="font-size:.82rem"><i class="bi bi-x-circle me-1"></i><strong>Failed:</strong> ' + d.msg + '</div>';
            }
        })
        .catch(function(err){
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-envelope-check me-1"></i>Send Test Email';
            st.innerHTML = '<div class="alert alert-danger py-2 mt-2 mb-0" style="font-size:.82rem">Request error — check browser console.</div>';
        });
}
</script>

<?php include '../includes/footer.php'; ?>
