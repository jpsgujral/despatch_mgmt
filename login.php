<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in - go home
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db       = getDB();
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
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $u = htmlspecialchars_decode($username);
        $stmt = $db->prepare("SELECT * FROM app_users WHERE username = ? AND status = 'Active' LIMIT 1");
        $stmt->bind_param('s', $u);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['full_name']  = $user['full_name'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['perms']      = json_decode($user['permissions'] ?? '{}', true) ?: [];
            $db->query("UPDATE app_users SET last_login=NOW() WHERE id={$user['id']}");
            $ip = $db->real_escape_string(substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
            $ua = $db->real_escape_string(substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
            $un = $db->real_escape_string((string)($user['username'] ?? ''));
            $db->query("INSERT INTO app_user_login_log (user_id, username, ip_address, user_agent)
                        VALUES ({$user['id']}, '$un', '$ip', '$ua')");
            $display_name = trim((string)($user['full_name'] ?? '')) ?: (string)($user['username'] ?? 'there');
            showAlert('success', '<i class="bi bi-stars me-2"></i>Welcome back, <strong>' . htmlspecialchars($display_name) . '</strong>. Glad to see you.');
            redirect('index.php');
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$db = getDB();
$company = $db->query("SELECT company_name FROM company_settings LIMIT 1")->fetch_assoc();
$app_name = $company['company_name'] ?? APP_NAME;
$asset_version = '20260703-bms';
$login_logo_url = 'assets/icons/bms-logo.png?v=' . $asset_version;
?>
<!DOCTYPE html>
<html lang="en" style="background:#0b3a68;">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0b3a68">
<meta name="msapplication-navbutton-color" content="#0b3a68">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Login - <?= htmlspecialchars($app_name) ?></title>
<link rel="manifest" href="manifest.json?v=<?= htmlspecialchars($asset_version) ?>">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png?v=<?= htmlspecialchars($asset_version) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192x192.png?v=<?= htmlspecialchars($asset_version) ?>">
<link rel="icon" type="image/png" sizes="512x512" href="assets/icons/icon-512x512.png?v=<?= htmlspecialchars($asset_version) ?>">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    min-height: 100vh;
    background: #0b3a68;
}
body {
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background:
        radial-gradient(circle at 18% 18%, rgba(56, 189, 248, .22), transparent 30%),
        radial-gradient(circle at 82% 14%, rgba(245, 158, 11, .18), transparent 26%),
        linear-gradient(135deg, #07172c 0%, #0f3769 48%, #062b4e 100%);
    padding: 18px;
    overflow-x: hidden;
}
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        linear-gradient(110deg, transparent 0 55%, rgba(255,255,255,.06) 55% 56%, transparent 56%),
        repeating-linear-gradient(90deg, rgba(255,255,255,.035) 0 1px, transparent 1px 82px);
    pointer-events: none;
}
.login-shell {
    width: min(980px, 100%);
    display: grid;
    grid-template-columns: minmax(280px, 1fr) 410px;
    align-items: stretch;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 34px 90px rgba(0,0,0,.42);
    background: rgba(255,255,255,.94);
    animation: cardIn .45s cubic-bezier(.22,.68,0,1.2) both;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(28px) scale(.97); }
    to   { opacity: 1; transform: none; }
}
.login-visual {
    position: relative;
    min-height: 560px;
    background: linear-gradient(160deg, #0b3a68 0%, #145997 100%);
    color: #fff;
    padding: 42px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 34px;
}
.login-visual::after {
    content: '';
    position: absolute;
    inset: auto -18% -26% -18%;
    height: 58%;
    background: rgba(255,255,255,.1);
    border-radius: 999px 999px 0 0;
}
.hero-logo {
    position: relative;
    z-index: 1;
    width: min(360px, 88%);
    aspect-ratio: 1 / 1;
    border-radius: 32px;
    box-shadow: 0 24px 58px rgba(0,0,0,.35);
    background: #fff;
    object-fit: cover;
    align-self: center;
}
.visual-copy {
    position: relative;
    z-index: 1;
    max-width: 430px;
}
.visual-copy h1 {
    font-size: clamp(2rem, 4vw, 3.4rem);
    line-height: 1.02;
    font-weight: 850;
    margin: 0 0 14px;
}
.login-card {
    width: 100%;
    padding: 46px 40px 34px;
    background: #fff;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.login-brand {
    text-align: left;
    margin-bottom: 28px;
}
.brand-mini {
    width: 74px;
    height: 74px;
    border-radius: 20px;
    object-fit: cover;
    box-shadow: 0 12px 28px rgba(20,89,151,.25);
    margin-bottom: 16px;
}
.brand-title {
    font-size: 1.45rem;
    font-weight: 800;
    color: #09294a;
    margin-bottom: 6px;
}
.brand-sub {
    font-size: 0.86rem;
    color: #64748b;
    font-weight: 500;
}
.form-label {
    font-weight: 600;
    font-size: 0.82rem;
    color: #475569;
    margin-bottom: 6px;
    display: block;
}
.input-group-text {
    background: #eff6ff;
    border: 1.5px solid #cbd5e1;
    border-right: none;
    border-radius: 12px 0 0 12px;
    color: #0b3a68;
    padding: 0 14px;
    min-height: 46px;
}
.form-control {
    border: 1.5px solid #cbd5e1;
    border-left: none;
    border-radius: 0 12px 12px 0;
    font-size: 0.9rem;
    padding: 10px 14px;
    min-height: 46px;
    color: #1e293b;
    background: #fff;
    transition: border-color .2s, box-shadow .2s;
}
.form-control:focus {
    border-color: #1478bd;
    box-shadow: 0 0 0 3px rgba(20,120,189,0.15);
    outline: none;
}
.input-group .form-control:focus { border-left: none; }
.toggle-pw {
    cursor: pointer;
    background: #eff6ff;
    border: 1.5px solid #cbd5e1;
    border-left: none;
    border-radius: 0 12px 12px 0;
    color: #0b3a68;
    padding: 0 14px;
    min-height: 46px;
    transition: color .15s;
}
.toggle-pw:hover { color: #1478bd; }
.btn-login {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #0b3a68 0%, #1478bd 100%);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-weight: 700;
    font-size: 0.96rem;
    letter-spacing: 0.2px;
    transition: opacity .2s, transform .15s, box-shadow .2s;
    box-shadow: 0 8px 22px rgba(20,120,189,0.32);
    min-height: 48px;
    cursor: pointer;
}
.btn-login:hover {
    opacity: .92;
    transform: translateY(-1px);
    box-shadow: 0 10px 28px rgba(20,120,189,0.42);
}
.btn-login:active { transform: none; }
.footer-note {
    text-align: center;
    color: #94a3b8;
    font-size: 0.74rem;
    margin-top: 22px;
}
@media (max-width: 820px) {
    .login-shell { grid-template-columns: 1fr; max-width: 440px; }
    .login-visual { min-height: auto; padding: 24px 24px 18px; }
    .hero-logo { width: 148px; border-radius: 24px; }
    .visual-copy h1 { font-size: 1.65rem; }
}
@media (max-width: 480px) {
    body { padding: 12px; align-items: flex-start; }
    .login-shell { margin-top: 10px; }
    .login-card { padding: 28px 22px 24px; }
    .brand-mini { width: 64px; height: 64px; border-radius: 18px; }
}
</style>
</head>
<body style="background:#0b3a68;">
<main class="login-shell">
    <section class="login-visual" aria-label="Fly Ash BMS">
        <div class="visual-copy">
            <h1>Fly Ash BMS</h1>
        </div>
        <img class="hero-logo" src="<?= htmlspecialchars($login_logo_url) ?>" alt="Fly Ash BMS">
    </section>

    <div class="login-card">
        <div class="login-brand">
            <img class="brand-mini" src="<?= htmlspecialchars($login_logo_url) ?>" alt="<?= htmlspecialchars($app_name) ?>">
            <div class="brand-title"><?= htmlspecialchars($app_name) ?></div>
            <div class="brand-sub">Sign in to continue to your operations dashboard.</div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3" style="border-radius:12px;font-size:.87rem">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php displayAlert(); ?>

        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Enter username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="pwField" class="form-control" placeholder="Enter password" required>
                    <button type="button" class="toggle-pw" onclick="togglePw()" tabindex="-1">
                        <i class="bi bi-eye" id="pwEye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

        <div class="footer-note">&copy; <?= date('Y') ?> <?= htmlspecialchars($app_name) ?></div>
    </div>
</main>
<script>
function togglePw() {
    var f = document.getElementById('pwField');
    var e = document.getElementById('pwEye');
    if (f.type === 'password') { f.type = 'text'; e.className = 'bi bi-eye-slash'; }
    else { f.type = 'password'; e.className = 'bi bi-eye'; }
}
</script>
</body>
</html>
