<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';

$db       = getDB();
$company  = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc();
$app_name = $company ? $company['company_name'] : APP_NAME;
$current_page = basename($_SERVER['PHP_SELF']);
$script_dir   = dirname($_SERVER['PHP_SELF']);
$in_modules   = (basename($script_dir) === 'modules');
$in_backup    = (basename($script_dir) === 'backup');
$base         = ($in_modules || $in_backup) ? '../' : '';
$modules_base = $in_modules ? '' : ($in_backup ? '../modules/' : 'modules/');
$app_logo     = $base . 'assets/icons/apple-touch-icon.png';
$show_smart_back = !in_array($current_page, ['index.php', 'login.php'], true);
$smart_back_fallback = $base . 'index.php';
$desktop_nav_mode_source = $_SESSION['desktop_nav_mode_preview'] ?? ($_SESSION['desktop_nav_mode'] ?? ($company['desktop_nav_mode'] ?? 'classic'));
$desktop_nav_mode = in_array($desktop_nav_mode_source, ['classic', 'wide_burger'], true) ? $desktop_nav_mode_source : 'classic';
$nav_mode_redirect = $_SERVER['REQUEST_URI'] ?? (($in_modules || $in_backup) ? '../index.php' : 'index.php');
$nav_mode_options = [
    'classic' => ['label' => 'Classic Sidebar', 'short' => 'Classic', 'icon' => 'bi-layout-sidebar-inset'],
    'wide_burger' => ['label' => 'Wide + Burger', 'short' => 'Wide', 'icon' => 'bi-arrows-fullscreen'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- viewport-fit=cover handles iPhone notch / safe areas -->
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <!-- PWA manifest -->
    <link rel="manifest" href="<?= $base ?>manifest.json">

    <!-- Android / Chrome PWA -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1E3A8A">

    <!-- iOS "Add to Home Screen" -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TSG DMS">
    <link rel="apple-touch-icon" href="<?= $base ?>assets/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>assets/icons/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>assets/icons/icon-512x512.png">

    <title><?= htmlspecialchars($app_name) ?> &mdash; DMS</title>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- DataTables + Responsive extension -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

    <style>
    /* ═══════════════════════ VARIABLES ════════════════════════ */
    :root {
        --dm-bg:         #0f172a;
        --dm-surface:    #172033;
        --dm-surface2:   #1e293b;
        --dm-border:     #334155;
        --dm-text:       #e5e7eb;
        --dm-text-muted: #94a3b8;
        --dm-card:       #111827;
        --dm-input:      #1e293b;
        --dm-input-text: #e5e7eb;
        --primary:       #1E3A8A;
        --secondary:     #334155;
        --accent:        #F59E0B;
        --sidebar-w:     220px;
        --topbar-h:      58px;
        --topbar-gap:    10px;
        --topbar-offset: calc(var(--topbar-h) + (var(--topbar-gap) * 2));
        --light-bg:      #F8FAFC;
        --transition:    0.28s cubic-bezier(.4,0,.2,1);
    }

    /* ═══════════════════════ RESET / BASE ═════════════════════ */
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
        background: var(--light-bg);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        font-size: 14px;
        overflow-x: hidden;
    }

    /* ═══════════════════════ SIDEBAR ══════════════════════════ */
    .sidebar {
        position: fixed;
        top: 0; left: 0;
        width: var(--sidebar-w);
        height: 100dvh;          /* dynamic viewport height for mobile */
        background: linear-gradient(180deg, #172554 0%, #334155 100%);
        color: #fff;
        overflow-y: auto;
        overflow-x: hidden;
        z-index: 1050;
        transition: transform var(--transition), box-shadow var(--transition);
        box-shadow: 4px 0 20px rgba(0,0,0,0.18);
        display: flex;
        flex-direction: column;
        -webkit-overflow-scrolling: touch;
    }
    /* Scrollbar inside sidebar – thin */
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

    .sidebar-brand {
        padding: 18px 16px 14px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        background: rgba(0,0,0,0.18);
        flex-shrink: 0;
    }
    .sidebar-brand-main {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .sidebar-brand-logo {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        object-fit: contain;
        background: rgba(255,255,255,.12);
        padding: 4px;
        flex-shrink: 0;
    }
    .sidebar-brand-text {
        min-width: 0;
    }
    .sidebar-brand .brand-tag  { font-size: 0.65rem; opacity: .65; letter-spacing: 1.5px; text-transform: uppercase; }
    .sidebar-brand .brand-name { font-size: 0.95rem; font-weight: 700; margin-top: 3px; line-height: 1.25; }

    .nav-section {
        padding: 16px 16px 4px;
        font-size: 0.6rem;
        text-transform: uppercase;
        letter-spacing: 1.8px;
        opacity: .45;
        font-weight: 700;
        flex-shrink: 0;
    }
    .nav-section-toggle {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        opacity: 1;
        padding: 10px 16px;
        margin: 2px 6px;
        border-radius: 7px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        color: rgba(255,255,255,.65);
        background: rgba(255,255,255,.05);
        transition: background .18s, color .18s;
        user-select: none;
        flex-shrink: 0;
    }
    .nav-section-toggle:hover {
        background: rgba(255,255,255,.12);
        color: rgba(255,255,255,.9);
    }
    .nav-section-toggle i.bi-chevron-down,
    .nav-section-toggle i.bi-chevron-up {
        font-size: 0.65rem;
        opacity: .7;
        transition: transform .2s;
    }
    .nav-group { flex-shrink: 0; }
    /* Sub-menu for nested items */
    .nav-sub-toggle {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        padding: 7px 16px 7px 32px;
        margin: 1px 6px;
        border-radius: 7px;
        font-size: 0.78rem;
        font-weight: 600;
        color: rgba(255,255,255,.65);
        transition: background .15s, color .15s;
        user-select: none;
    }
    .nav-sub-toggle:hover { background: rgba(255,255,255,.08); color: rgba(255,255,255,.9); }
    .nav-sub-toggle i.bi-chevron-down, .nav-sub-toggle i.bi-chevron-up { font-size: 0.6rem; opacity: .7; }
    .nav-sub-items .nav-link { padding-left: 44px !important; font-size: 0.8rem; }
    .nav-group > div[id^="nav-"] {
        overflow: hidden;
        transition: none;
    }
    .sidebar .nav-link {
        color: rgba(255,255,255,.8);
        padding: 10px 20px;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 11px;
        border-left: 3px solid transparent;
        transition: background .18s, border-color .18s, color .18s;
        border-radius: 0;
        white-space: nowrap;
    }
    .sidebar .nav-link i { font-size: 1.05rem; width: 20px; flex-shrink: 0; }
    .sidebar .nav-link:hover  { color: #fff; background: rgba(255,255,255,.1); border-left-color: rgba(255,255,255,.4); }
    .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.15); border-left-color: var(--accent); }
    .sidebar .nav-link[href$="messages.php"] { display: none; }

    /* ═══════════════════════ OVERLAY ══════════════════════════ */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        z-index: 1040;
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
        transition: opacity var(--transition);
    }
    .sidebar-overlay.show { display: block; }

    /* ═══════════════════════ MAIN CONTENT ═════════════════════ */
    .main-content {
        margin-left: var(--sidebar-w);
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        transition: margin var(--transition);
        overflow-x: hidden;
        padding-top: var(--topbar-offset);
    }

    /* ═══════════════════════ TOPBAR ═══════════════════════════ */
    .topbar {
        background: linear-gradient(135deg, var(--primary), #172554);
        height: var(--topbar-h);
        padding: 0 20px;
        box-shadow: 0 2px 12px rgba(0,0,0,.2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: fixed;
        top: var(--topbar-gap);
        left: calc(var(--sidebar-w) + var(--topbar-gap));
        right: var(--topbar-gap);
        z-index: 1100;
        flex-shrink: 0;
        border-radius: 20px;
    }
    .topbar-left { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .topbar-center {
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 1 1 auto;
        min-width: 0;
    }
    .topbar-back-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,.28);
        background: rgba(255,255,255,.14);
        color: #fff;
        flex-shrink: 0;
        transition: background .18s, border-color .18s, transform .18s;
    }
    .topbar-back-btn:hover {
        background: rgba(255,255,255,.22);
        border-color: rgba(255,255,255,.42);
        color: #fff;
        transform: translateY(-1px);
    }
    .topbar-home-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 34px;
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid rgba(255,255,255,.26);
        background: rgba(255,255,255,.14);
        color: #fff;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.82rem;
        line-height: 1;
        transition: background .18s, border-color .18s, transform .18s, color .18s;
        flex-shrink: 0;
    }
    .topbar-home-btn:hover {
        background: rgba(255,255,255,.24);
        border-color: rgba(255,255,255,.4);
        color: #fff;
        transform: translateY(-1px);
    }
    .topbar-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .topbar-logo {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        object-fit: contain;
        background: rgba(255,255,255,.14);
        padding: 3px;
        flex-shrink: 0;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.12);
    }
    .topbar-titles {
        min-width: 0;
        display: flex;
        align-items: center;
        flex: 1 1 auto;
    }
    .topbar-app-name {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 1.4px;
        color: rgba(255,255,255,.72);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 48vw;
    }
    .topbar h4 {
        margin: 0;
        font-size: 1rem;
        color: #fff;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: inline-flex;
        align-items: center;
    }

    /* Hamburger toggle &mdash; hidden on desktop, visible on mobile */
    .sidebar-toggle {
        display: none;
        background: rgba(255,255,255,.15);
        border: none;
        color: #fff;
        font-size: 1.4rem;
        padding: 4px 6px;
        border-radius: 6px;
        cursor: pointer;
        line-height: 1;
        flex-shrink: 0;
    }
    .sidebar-toggle:hover { background: rgba(255,255,255,.25); }
    body.nav-mode-wide-burger .sidebar-toggle {
        display: inline-flex !important;
        align-items: center;
    }

    .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; min-width: 0; justify-content: flex-end; }
    .topbar-right > * { flex-shrink: 0; }
    .topbar-right .dropdown-menu {
        z-index: 1200;
    }
    .topbar-search-form {
        display: flex;
        align-items: center;
        gap: 0;
        min-width: 0;
        width: min(360px, 32vw);
    }
    .topbar-search-input {
        border-radius: 999px 0 0 999px;
        border: 1px solid rgba(255,255,255,.28);
        border-right: none;
        background: rgba(255,255,255,.14);
        color: #fff;
        min-height: 36px;
        padding: 7px 12px;
        font-size: 0.82rem;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    }
    .topbar-search-input::placeholder {
        color: rgba(255,255,255,.72);
    }
    .topbar-search-input:focus {
        background: rgba(255,255,255,.2);
        color: #fff;
        border-color: rgba(255,255,255,.42);
        box-shadow: none;
    }
    .topbar-search-btn {
        border-radius: 0 999px 999px 0;
        border: 1px solid rgba(255,255,255,.28);
        background: rgba(255,255,255,.2);
        color: #fff;
        min-height: 36px;
        min-width: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .topbar-search-btn:hover {
        background: rgba(255,255,255,.28);
        color: #fff;
        border-color: rgba(255,255,255,.42);
    }
    .topbar-date  { font-size: 0.75rem; color: rgba(255,255,255,.75); }
    .topbar-messages-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 34px;
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid rgba(255,255,255,.26);
        background: rgba(255,255,255,.14);
        color: #fff;
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    }
    .topbar-messages-btn:hover {
        background: rgba(255,255,255,.22);
        border-color: rgba(255,255,255,.4);
        color: #fff;
    }
    .topbar-messages-btn.active {
        background: rgba(255,255,255,.24);
        border-color: rgba(255,255,255,.46);
    }
    .topbar-messages-badge {
        min-width: 18px;
        height: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 5px;
        border-radius: 999px;
        font-size: 0.64rem;
        line-height: 1;
    }
    .topbar-user-btn {
        border-radius: 20px;
        padding: 4px 12px;
        background: rgba(255,255,255,.15);
        border: 1px solid rgba(255,255,255,.3);
        color: #fff;
        max-width: 190px;
    }
    .topbar-user-btn .topbar-user-name {
        max-width: 120px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .topbar-mode-form { margin: 0; }
    .topbar-mode-switch {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 34px;
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid rgba(255,255,255,.26);
        background: rgba(255,255,255,.14);
        color: #fff;
        font-size: 0.78rem;
        font-weight: 600;
        line-height: 1;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    }
    .topbar-mode-switch:hover {
        background: rgba(255,255,255,.22);
        border-color: rgba(255,255,255,.4);
        color: #fff;
    }
    .topbar-mode-menu {
        min-width: 210px;
        border-radius: 14px;
        overflow: hidden;
    }
    .topbar-mode-menu .dropdown-item {
        color: #1e293b;
        font-weight: 600;
    }
    .topbar-mode-menu .dropdown-item:hover {
        background: #EFF6FF;
        color: var(--primary);
    }
    .topbar-mode-menu .dropdown-item.active,
    .topbar-mode-menu .dropdown-item:active {
        background: rgba(30,58,138,.12);
        color: var(--primary);
    }

    /* ═══════════════════════ CONTENT AREA ═════════════════════ */
    .content-area {
        padding: 22px 24px;
        flex: 1;
        overflow-x: hidden;
    }

    @media (min-width: 992px) {
        body.nav-mode-wide-burger .sidebar {
            transform: translateX(-100%);
            box-shadow: none;
        }
        body.nav-mode-wide-burger .sidebar.open {
            transform: translateX(0);
            box-shadow: 6px 0 24px rgba(0,0,0,.28);
        }
        body.nav-mode-wide-burger .main-content {
            margin-left: 0;
        }
        body.nav-mode-wide-burger .topbar {
            left: 12px;
            right: 12px;
            width: auto;
            max-width: 1480px;
            margin: 10px auto 0;
            border-radius: 20px;
            overflow: visible;
        }
        body.nav-mode-wide-burger .content-area {
            width: min(100%, 1480px);
            margin: 0 auto;
            padding: 22px 20px 30px;
        }
        body.nav-mode-wide-burger .sidebar-toggle {
            display: inline-flex;
            align-items: center;
        }
        body.nav-mode-wide-burger .sidebar-overlay.show {
            display: block;
        }

    }

    /* ═══════════════════════ CARDS ════════════════════════════ */
    .card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 2px 14px rgba(0,0,0,.06);
    }
    .card-header {
        background: linear-gradient(135deg, var(--primary), #334155);
        color: #fff;
        border-radius: 12px 12px 0 0 !important;
        padding: 13px 18px;
        font-weight: 600;
        font-size: 0.9rem;
    }

    /* ═══════════════════════ STAT CARDS ═══════════════════════ */
    .stat-card {
        border-radius: 12px;
        padding: 18px;
        color: #fff;
        position: relative;
        overflow: hidden;
    }
    .stat-card .icon { position: absolute; right: 12px; top: 12px; font-size: 2.8rem; opacity: .18; }
    .stat-card h3  { font-size: 1.7rem; font-weight: 700; margin: 0; }
    .stat-card p   { margin: 0; opacity: .85; font-size: 0.8rem; }

    /* ═══════════════════════ BUTTONS ══════════════════════════ */
    .btn-primary       { background: var(--primary); border-color: var(--primary); }
    .btn-primary:hover { background: #172554; border-color: #172554; }
    .btn-success       { background: var(--accent); border-color: var(--accent); color: #111827; }
    .btn-success:hover,
    .btn-success:focus { background: #d97706; border-color: #d97706; color: #fff; }
    .btn-outline-success { color: var(--primary); border-color: var(--primary); }
    .btn-outline-success:hover,
    .btn-outline-success:focus { background: var(--primary); border-color: var(--primary); color: #fff; }
    .btn-action        { padding: 4px 9px; font-size: 0.76rem; border-radius: 6px; }

    /* ═══════════════════════ TABLES ═══════════════════════════ */
    .table th { background: #EFF6FF; color: var(--primary); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: .4px; }
    .table td { vertical-align: middle; font-size: 0.85rem; }
    /* Ensure tables scroll horizontally on small screens */
    .table-responsive { -webkit-overflow-scrolling: touch; }

    /* ═══════════════════════ FORMS ════════════════════════════ */
    .form-label      { font-weight: 600; font-size: 0.82rem; color: #444; }
    .form-control, .form-select {
        border-radius: 8px;
        border: 1.5px solid #cbd5e1;
        font-size: 0.875rem;
        /* larger touch target on mobile */
        min-height: 40px;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--secondary);
        box-shadow: 0 0 0 3px rgba(41,128,185,.15);
    }
    textarea.form-control { min-height: unset; }

    /* ═══════════════════════ ALERTS ═══════════════════════════ */
    .alert { border-radius: 10px; font-size: 0.875rem; }
    .alert-success {
        background: #e8f1ff;
        border-color: #bfdbfe;
        color: #1e3a8a;
    }

    /* ═══════════════════════ DESKTOP COMPACT (992px - 1600px) ═════════════════ */
    @media (min-width: 992px) and (max-width: 1600px) {
        .topbar { padding: 0 14px; }
        .topbar-left { min-width: 0; flex: 0 1 auto; gap: 10px; }
        .topbar-titles { min-width: 0; flex: 0 1 auto; }
        .topbar-search-form {
            width: min(280px, 24vw);
        }
        .topbar-search-input { padding: 7px 10px; }
        .topbar-search-input::placeholder { font-size: 0.78rem; }
        .topbar-right { gap: 8px; }
        .topbar h4 {
            max-width: none !important;
            font-size: 0.96rem;
        }
        .topbar-date { display: none !important; }
        .topbar-messages-btn {
            min-width: 34px;
            padding: 6px 10px;
        }
        .topbar-messages-btn .topbar-messages-label { display: none; }
        .topbar-user-btn {
            padding: 4px 8px;
            max-width: 44px;
            min-width: 44px;
        }
        .topbar-user-btn .topbar-user-name,
        .topbar-user-btn .topbar-user-role,
        .topbar-user-btn .bi-chevron-down { display: none !important; }
    }

    @media (max-width: 991.98px) {

        /* Hide sidebar off-screen by default */
        .sidebar {
            transform: translateX(-100%);
            box-shadow: none;
        }
        .sidebar.open {
            transform: translateX(0);
            box-shadow: 6px 0 24px rgba(0,0,0,.28);
        }

        /* Main content takes full width */
        .main-content { margin-left: 0; }

        /* Align topbar width with mobile content/cards */
        .topbar {
            left: 8px;
            right: 8px;
            width: auto;
            max-width: none;
            margin: 0;
            top: 6px;
            padding: 0 10px;
            border-radius: 18px;
            overflow: visible;
        }
        .topbar-mode-switch,
        .topbar-mode-switch + .dropdown-menu,
        .topbar-mode-menu {
            display: none !important;
        }
        .topbar-left {
            gap: 8px;
            min-width: 0;
            flex: 1;
        }
        .topbar-back-btn {
            width: 32px;
            height: 32px;
            border-radius: 9px;
        }
        .topbar-home-btn {
            min-width: 32px;
            padding: 6px 9px;
        }
        .topbar-home-btn .topbar-home-label {
            display: none;
        }
        .topbar-brand {
            gap: 8px;
            min-width: 0;
        }
        .topbar-app-name {
            display: none;
        }
        .topbar-logo {
            width: 28px;
            height: 28px;
        }
        .topbar-right {
            gap: 8px;
            margin-left: auto;
            flex-shrink: 0;
        }
        .topbar-center,
        .topbar-search-form { display: none !important; }

        /* Show hamburger */
        .sidebar-toggle { display: inline-flex; align-items: center; }

        /* Tighter content padding */
        .content-area { padding: 14px 12px; }

        /* Page title */
        .topbar-titles {
            min-width: 0;
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            overflow: hidden;
        }
        .topbar h4 {
            font-size: 0.95rem;
            max-width: none !important;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .topbar-app-name { font-size: 0.62rem; max-width: 38vw; }
        .topbar-messages-btn {
            min-width: 36px;
            padding: 6px 10px;
        }
        .topbar-messages-btn .topbar-messages-label {
            display: none;
        }
        .topbar-user-btn {
            padding: 3px 6px;
            max-width: 42px;
            min-width: 42px;
        }
        .topbar-user-btn .topbar-user-name,
        .topbar-user-btn .topbar-user-role {
            display: none !important;
        }
        .topbar-user-btn .bi-chevron-down {
            display: none;
        }
        .topbar-mode-switch {
            min-width: 34px;
            padding: 6px 9px;
            justify-content: center;
        }
        .topbar-mode-switch .topbar-mode-label,
        .topbar-mode-switch .bi-chevron-down {
            display: none;
        }

        /* Hide date on mobile */
        .topbar-date { display: none !important; }

        /* Stat cards: 2 per row on phones */
        .stat-card h3 { font-size: 1.35rem; }

        /* Action buttons stacked on tiny screens */
        .btn-action { padding: 5px 8px; font-size: 0.8rem; }

        /* DataTable search + info on mobile */
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_length { text-align: left; }
    }

    /* ═══════════════════════ SMALL PHONES (≤ 575px) ═══════════ */
    @media (max-width: 575.98px) {
        .content-area { padding: 10px 8px; }
        .topbar {
            width: calc(100vw - 16px);
            max-width: calc(100vw - 16px);
            margin: 6px auto 0;
            padding: 0 8px;
            border-radius: 16px;
            overflow: visible;
        }
        .topbar-left {
            gap: 6px;
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
        }
        .topbar-brand {
            gap: 6px;
        }
        .topbar-logo {
            width: 26px;
            height: 26px;
        }
        .topbar-right {
            gap: 4px;
            margin-left: auto;
            flex-shrink: 0;
        }
        .topbar-center,
        .topbar-search-form { display: none !important; }
        .topbar-search-input {
            padding: 7px 10px;
        }
        .topbar-home-btn .topbar-home-label {
            display: none;
        }
        .topbar-home-btn {
            min-width: 32px;
            padding: 6px 8px;
        }
        .topbar-titles {
            min-width: 0;
            flex: 1 1 auto;
            overflow: hidden;
        }
        .topbar h4 {
            font-size: 0.88rem !important;
            max-width: none !important;
        }
        .topbar-messages-btn {
            min-width: 34px;
            padding: 6px 9px;
        }
        .topbar-messages-badge {
            min-width: 16px;
            height: 16px;
            font-size: 0.58rem;
            padding: 0 4px;
        }
        .card-header  { padding: 10px 14px; font-size: 0.82rem; }
        .stat-card    { padding: 14px; }
        .stat-card h3 { font-size: 1.2rem; }
        .table td, .table th { font-size: 0.78rem; padding: 6px 8px; }
        /* iOS Safari auto-zoom fix &mdash; inputs must be >= 16px on mobile */
        .form-control, .form-select, textarea.form-control,
        input[type="text"], input[type="number"], input[type="date"],
        input[type="email"], input[type="tel"], input[type="search"] {
            font-size: 16px !important;
        }
        /* Extra bottom padding so content clears bottom nav bar */
        .content-area { padding-bottom: 104px; }

        /* Stack form cols */
        .row > [class*="col-md-"] { margin-bottom: 0; }
    }

    /* ═══════════════════════ DATATABLE MOBILE ═════════════════ */
    .dataTables_wrapper .dataTables_filter input {
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        padding: 5px 10px;
        font-size: 0.85rem;
    }
    .dataTables_wrapper .dataTables_length select {
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        padding: 4px 8px;
        font-size: 0.85rem;
    }

    /* ═══════════════════════ TOUCH TARGETS ════════════════════ */
    /* All interactive elements have at least 44×44px touch area */
    .btn { min-height: 38px; }
    .btn-sm { min-height: 32px; }
    .btn-action { min-height: 34px; min-width: 34px; display: inline-flex; align-items: center; justify-content: center; }
    .form-select, .form-control { touch-action: manipulation; }

    /* ═══════════════════════ ITEMS TABLE SCROLL ═══════════════ */
    /* Line-item tables (purchase orders, despatch) scroll horizontally */
    #itemsTable, #dItemsTable {
        min-width: 700px;
    }

    /* ═══════════════════════ CARD MOBILE ══════════════════════ */
    .card { margin-bottom: 14px; }

    /* ═══════════════════════ MOBILE ENHANCEMENTS ══════════════ */
    @media (max-width: 575.98px) {
        /* Badges */
        .badge { font-size: 0.68rem; }

        /* Card headers wrap */
        .card-header { flex-wrap: wrap; gap: 6px; }

        /* Form buttons full-width on mobile */
        .col-12.text-end .btn { margin-top: 4px; width: 100%; }
        .col-12.text-end .btn + .btn { margin-left: 0 !important; }
        form .col-12.text-end { display: flex; flex-direction: column-reverse; gap: 8px; }
        .btn-action { padding: 5px 7px; font-size: 0.75rem; }

        /* Page header: stack title + button vertically */
        .d-flex.justify-content-between.align-items-center.mb-3 {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 8px;
        }
        .d-flex.justify-content-between.align-items-center.mb-3 .btn,
        .d-flex.justify-content-between.align-items-center.mb-3 a.btn {
            text-align: center;
            width: 100%;
        }
        .d-flex.justify-content-between.align-items-center.mb-3 .d-flex.gap-2 {
            flex-direction: column;
            width: 100%;
        }
        .d-flex.justify-content-between.align-items-center.mb-3 .d-flex.gap-2 .btn {
            width: 100%;
        }

        /* Status filter buttons compact */
        .d-flex.flex-wrap.gap-2.mb-3 .btn { font-size: 0.72rem; padding: 4px 8px; }

        /* Hide less important table columns */
        .hide-mobile { display: none !important; }

        /* KPI cards compact */
        .card .fs-3 { font-size: 1.3rem !important; }
        .card .fs-5 { font-size: 0.95rem !important; }

        /* DataTables search compact */
        .dataTables_wrapper .dataTables_filter { text-align: left !important; }
        .dataTables_wrapper .dataTables_filter input { width: 130px !important; }

        /* Topbar compact */
        .topbar { padding: 0 10px; }

        /* Stat card rows */
        .row.g-3 .col-6.col-md-3 .card,
        .row.g-3 .col-6.col-md-4 .card { padding: 10px 8px; }
    }

    /* Tablet */
    @media (min-width: 576px) and (max-width: 767.98px) {
        .hide-tablet { display: none !important; }
    }



    /* ═══════════════════════ BOTTOM NAV BAR (mobile only) ═════ */
    .bottom-nav {
        display: none;
    }
    @media (max-width: 991.98px) {
        .bottom-nav {
            display: grid;
            grid-template-columns: repeat(var(--mobile-nav-count, 4), minmax(0, 1fr));
            position: fixed;
            width: calc(100vw - 16px);
            max-width: calc(100vw - 16px);
            left: 50%;
            right: auto;
            transform: translateX(-50%);
            bottom: calc(4px + env(safe-area-inset-bottom, 0px));
            height: 84px;
            background:
                linear-gradient(180deg, rgba(255,255,255,.16) 0%, rgba(255,255,255,.03) 16%, rgba(0,0,0,0) 17%),
                linear-gradient(145deg, #1E3A8A 0%, #172554 56%, #0f172a 100%);
            border: 1px solid rgba(255,255,255,.09);
            border-radius: 24px;
            box-shadow:
                0 18px 34px rgba(0,0,0,.34),
                0 6px 14px rgba(0,0,0,.28),
                inset 0 1px 0 rgba(255,255,255,.12),
                inset 0 -8px 18px rgba(0,0,0,.22);
            z-index: 1030;
            align-items: center;
            gap: 2px;
            padding: 7px 5px 9px;
            overflow: hidden;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }
        .bottom-nav a {
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            color: rgba(255,255,255,.62);
            text-decoration: none;
            font-size: .56rem;
            font-weight: 700;
            letter-spacing: .01em;
            text-transform: uppercase;
            padding: 4px 2px 2px;
            transition: transform .18s ease, color .18s ease, opacity .18s ease;
        }
        .bottom-nav a .nav-icon-wrap {
            width: 36px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.01));
            border: 1px solid transparent;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
            transition: transform .18s ease, background .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .bottom-nav a .nav-label {
            display: block;
            width: 100%;
            max-width: 100%;
            min-height: 1.9em;
            line-height: .95;
            white-space: normal;
            overflow-wrap: anywhere;
            text-align: center;
            display: block;
        }
        .bottom-nav a i { font-size: 1.16rem; }
        .bottom-nav a:hover { color: rgba(255,255,255,.9); }
        .bottom-nav a:hover .nav-icon-wrap {
            background: linear-gradient(180deg, rgba(255,255,255,.11), rgba(255,255,255,.04));
            border-color: rgba(255,255,255,.1);
        }
        .bottom-nav a.active {
            color: #DBEAFE;
            transform: translateY(-2px);
        }
        .bottom-nav a.active .nav-icon-wrap {
            background: linear-gradient(180deg, rgba(96,165,250,.34), rgba(30,58,138,.22));
            border-color: rgba(147,197,253,.32);
            box-shadow:
                0 10px 18px rgba(15, 23, 42, .38),
                0 2px 8px rgba(96,165,250,.28),
                inset 0 1px 0 rgba(255,255,255,.16);
        }
        .bottom-nav a.add-btn {
            color: #fff;
        }
        .bottom-nav a.add-btn .nav-icon-wrap {
            width: 38px;
            height: 32px;
            background: linear-gradient(145deg, #2563EB 0%, #1E3A8A 58%, #172554 100%);
            border-radius: 16px;
            border-color: rgba(255,255,255,.18);
            box-shadow:
                0 12px 20px rgba(37,99,235,.34),
                0 4px 10px rgba(0,0,0,.32),
                inset 0 1px 0 rgba(255,255,255,.24),
                inset 0 -6px 10px rgba(0,0,0,.14);
            transform: translateY(-4px);
        }
        .bottom-nav a.add-btn:active .nav-icon-wrap {
            box-shadow: 0 4px 10px rgba(37,99,235,.28), inset 0 2px 4px rgba(0,0,0,.2);
            transform: translateY(1px);
        }
        .bottom-nav a.add-btn i { font-size: 1.35rem; }
        .bottom-nav a.add-btn:hover .nav-icon-wrap { background: linear-gradient(145deg,#3b82f6,#1E3A8A); }
    }
    @media (max-width: 420px) {
        .bottom-nav {
            width: calc(100vw - 16px);
            max-width: calc(100vw - 16px);
            gap: 0;
            padding-left: 4px;
            padding-right: 4px;
            height: 80px;
            padding-top: 6px;
            padding-bottom: 6px;
        }
        .bottom-nav a {
            font-size: .5rem;
            letter-spacing: 0;
            gap: 3px;
            padding-top: 2px;
            padding-bottom: 1px;
        }
        .bottom-nav a .nav-icon-wrap {
            width: 30px;
            height: 24px;
        }
        .bottom-nav a.add-btn .nav-icon-wrap {
            width: 32px;
            height: 26px;
            transform: translateY(-2px);
        }
        .bottom-nav a .nav-label {
            display: block;
        }
    }
    @media (max-width: 360px) {
        .bottom-nav {
            width: calc(100vw - 16px);
            max-width: calc(100vw - 16px);
            bottom: calc(4px + env(safe-area-inset-bottom, 0px));
            border-radius: 20px;
        }
        .bottom-nav a .nav-icon-wrap {
            width: 28px;
            height: 22px;
        }
        .bottom-nav a.add-btn .nav-icon-wrap {
            width: 30px;
            height: 24px;
        }
        .bottom-nav a {
            font-size: .44rem;
        }
    }

    /* ═══════════════════════ DARK MODE ════════════════════════ */
    html.dark-mode, body.dark-mode {
        background: var(--dm-bg) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode .main-content { background: var(--dm-bg) !important; }
    body.dark-mode .card { background: var(--dm-card) !important; border-color: var(--dm-border) !important; color: var(--dm-text) !important; }
    body.dark-mode .card-header { background: var(--dm-surface2) !important; border-color: var(--dm-border) !important; color: var(--dm-text) !important; }
    body.dark-mode .card-body { color: var(--dm-text) !important; }
    body.dark-mode .table { color: var(--dm-text) !important; --bs-table-bg: var(--dm-card); --bs-table-border-color: var(--dm-border); }
    body.dark-mode .table-light { --bs-table-bg: var(--dm-surface2) !important; color: var(--dm-text) !important; }
    body.dark-mode .table-hover tbody tr:hover { background: var(--dm-surface2) !important; }
    body.dark-mode .form-control, body.dark-mode .form-select {
        background: var(--dm-input) !important;
        color: var(--dm-input-text) !important;
        border-color: var(--dm-border) !important;
    }
    body.dark-mode .form-control:focus, body.dark-mode .form-select:focus {
        background: var(--dm-input) !important;
        color: var(--dm-input-text) !important;
        border-color: #60A5FA !important;
        box-shadow: 0 0 0 .2rem rgba(96,165,250,.25) !important;
    }
    body.dark-mode .form-control[readonly], body.dark-mode .form-control.bg-light {
        background: var(--dm-surface2) !important;
        color: var(--dm-text-muted) !important;
    }
    body.dark-mode .modal-content { background: var(--dm-surface) !important; color: var(--dm-text) !important; border-color: var(--dm-border) !important; }
    body.dark-mode .modal-header, body.dark-mode .modal-footer { border-color: var(--dm-border) !important; }
    body.dark-mode .dropdown-menu { background: var(--dm-surface) !important; border-color: var(--dm-border) !important; }
    body.dark-mode .dropdown-item { color: var(--dm-text) !important; }
    body.dark-mode .dropdown-item:hover { background: var(--dm-surface2) !important; }
    body.dark-mode .topbar-mode-menu {
        background: var(--dm-surface) !important;
        border: 1px solid var(--dm-border) !important;
    }
    body.dark-mode .topbar-mode-menu .dropdown-item {
        color: var(--dm-text) !important;
    }
    body.dark-mode .topbar-mode-menu .dropdown-item:hover {
        background: var(--dm-surface2) !important;
        color: #fff !important;
    }
    body.dark-mode .topbar-mode-menu .dropdown-item.active,
    body.dark-mode .topbar-mode-menu .dropdown-item:active {
        background: var(--primary) !important;
        color: #fff !important;
    }
    body.dark-mode .topbar-search-input {
        background: rgba(255,255,255,.1);
        border-color: rgba(255,255,255,.18);
        color: var(--dm-text) !important;
    }
    body.dark-mode .topbar-search-input::placeholder {
        color: rgba(224,224,224,.66);
    }
    body.dark-mode .topbar-search-btn {
        background: rgba(255,255,255,.14);
        border-color: rgba(255,255,255,.18);
        color: var(--dm-text) !important;
    }
    body.dark-mode .dropdown-divider { border-color: var(--dm-border) !important; }
    body.dark-mode .text-muted { color: var(--dm-text-muted) !important; }
    body.dark-mode .border { border-color: var(--dm-border) !important; }
    body.dark-mode .border-bottom, body.dark-mode .border-top { border-color: var(--dm-border) !important; }
    body.dark-mode .bg-light { background: var(--dm-surface2) !important; }
    body.dark-mode .bg-white { background: var(--dm-card) !important; }
    body.dark-mode .text-white { color: #f8fafc !important; }
    body.dark-mode .text-secondary,
    body.dark-mode .text-body-secondary,
    body.dark-mode .text-body-tertiary { color: #cbd5e1 !important; }
    body.dark-mode .text-body,
    body.dark-mode .text-dark,
    body.dark-mode .text-black { color: var(--dm-text) !important; }
    body.dark-mode .bg-body,
    body.dark-mode .bg-body-secondary,
    body.dark-mode .bg-body-tertiary { background: var(--dm-card) !important; color: var(--dm-text) !important; }
    body.dark-mode .bg-white .text-muted,
    body.dark-mode .bg-light .text-muted,
    body.dark-mode .card .text-muted { color: #aebed2 !important; }
    body.dark-mode .table td.bg-white,
    body.dark-mode .table th.bg-white,
    body.dark-mode .table td.bg-light,
    body.dark-mode .table th.bg-light { background: var(--dm-surface2) !important; color: var(--dm-text) !important; }
    body.dark-mode .btn-light,
    body.dark-mode .btn-outline-light { color: var(--dm-text) !important; }
    body.dark-mode .alert-secondary { background: var(--dm-surface2) !important; color: var(--dm-text) !important; border-color: var(--dm-border) !important; }
    body.dark-mode .nav-tabs .nav-link { color: var(--dm-text-muted) !important; }
    body.dark-mode .nav-tabs .nav-link.active { color: var(--dm-text) !important; background: var(--dm-surface2) !important; border-color: var(--dm-border) !important; }
    body.dark-mode .badge.bg-light { background: var(--dm-surface2) !important; color: var(--dm-text) !important; }
    body.dark-mode .input-group-text { background: var(--dm-surface2) !important; color: var(--dm-text) !important; border-color: var(--dm-border) !important; }
    body.dark-mode hr { border-color: var(--dm-border) !important; }
    body.dark-mode .content-area { background: var(--dm-bg) !important; }
    /* Dark mode toggle button */
    .dark-toggle {
        background: rgba(255,255,255,.15);
        border: 1px solid rgba(255,255,255,.3);
        border-radius: 20px;
        color: #fff;
        padding: 4px 10px;
        cursor: pointer;
        font-size: 1rem;
        display: flex;
        align-items: center;
        transition: background .2s;
    }
    .dark-toggle:hover { background: rgba(255,255,255,.25); }

    /* ═══ DARK MODE &mdash; DataTables & inline color overrides ═══════ */
    body.dark-mode table.dataTable thead th,
    body.dark-mode table.dataTable thead td {
        background: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    body.dark-mode table.dataTable thead th.sorting,
    body.dark-mode table.dataTable thead th.sorting_asc,
    body.dark-mode table.dataTable thead th.sorting_desc {
        background: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode table.dataTable tbody tr {
        background: var(--dm-card) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode table.dataTable tbody tr:hover td {
        background: var(--dm-surface2) !important;
    }
    body.dark-mode table.dataTable tbody td {
        border-color: var(--dm-border) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode .dataTables_wrapper .dataTables_length,
    body.dark-mode .dataTables_wrapper .dataTables_filter,
    body.dark-mode .dataTables_wrapper .dataTables_info,
    body.dark-mode .dataTables_wrapper .dataTables_paginate {
        color: var(--dm-text) !important;
    }
    body.dark-mode .dataTables_wrapper .dataTables_paginate .paginate_button {
        color: var(--dm-text) !important;
    }
    body.dark-mode .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    body.dark-mode .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: var(--primary) !important;
        color: #fff !important;
        border-color: var(--primary) !important;
    }
    /* Override inline style="color:#..." on text elements */
    body.dark-mode td[style*="color:#000"],
    body.dark-mode td[style*="color: #000"],
    body.dark-mode td[style*="color:black"],
    body.dark-mode span[style*="color:#000"],
    body.dark-mode span[style*="color: #000"],
    body.dark-mode div[style*="color:#000"],
    body.dark-mode div[style*="color: #000"] {
        color: var(--dm-text) !important;
    }
    body.dark-mode .text-dark { color: var(--dm-text) !important; }
    body.dark-mode .text-black { color: var(--dm-text) !important; }
    body.dark-mode a:not(.btn):not(.nav-link):not(.dropdown-item) { color: #93C5FD; }
    body.dark-mode .fw-bold, body.dark-mode strong, body.dark-mode b { color: inherit; }
    body.dark-mode .page-link { background: var(--dm-surface2) !important; border-color: var(--dm-border) !important; color: var(--dm-text) !important; }
    body.dark-mode .page-item.active .page-link { background: var(--primary) !important; border-color: var(--primary) !important; }
    body.dark-mode .list-group-item { background: var(--dm-card) !important; border-color: var(--dm-border) !important; color: var(--dm-text) !important; }

    /* ═══ DARK MODE &mdash; additional table & element fixes ══════════ */
    /* All thead rows */
    body.dark-mode thead tr th,
    body.dark-mode thead tr td,
    body.dark-mode .table thead th,
    body.dark-mode .table thead td {
        background-color: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    /* All tbody rows */
    body.dark-mode tbody tr td,
    body.dark-mode tbody tr th,
    body.dark-mode .table tbody tr td {
        background-color: var(--dm-card) !important;
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    body.dark-mode tbody tr:nth-child(even) td {
        background-color: var(--dm-surface2) !important;
    }
    body.dark-mode tfoot tr td,
    body.dark-mode tfoot tr th {
        background-color: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    /* Stat/summary cards with inline bg */
    body.dark-mode [style*="background:#fff"],
    body.dark-mode [style*="background: #fff"],
    body.dark-mode [style*="background:#f"],
    body.dark-mode [style*="background:white"],
    body.dark-mode [style*="background: white"],
    body.dark-mode [style*="background-color:#fff"],
    body.dark-mode [style*="background-color: #fff"],
    body.dark-mode [style*="background-color:white"],
    body.dark-mode [style*="background-color: white"],
    body.dark-mode [style*="background:rgb(255,255,255"],
    body.dark-mode [style*="background: rgb(255,255,255"],
    body.dark-mode [style*="background-color:rgb(255,255,255"],
    body.dark-mode [style*="background-color: rgb(255,255,255"] {
        background: var(--dm-card) !important;
    }
    /* Inline color overrides &mdash; dark text on dark bg */
    body.dark-mode [style*="color:#1"],
    body.dark-mode [style*="color:#2"],
    body.dark-mode [style*="color:#3"],
    body.dark-mode [style*="color:#4"],
    body.dark-mode [style*="color:#5"],
    body.dark-mode [style*="color:#6"],
    body.dark-mode [style*="color:#7"],
    body.dark-mode [style*="color:#8"],
    body.dark-mode [style*="color:#9"] {
        color: var(--dm-text) !important;
    }
    /* Keep coloured badges/status readable */
    body.dark-mode .badge { opacity: 0.92; }
    /* Accordion / collapse headers */
    body.dark-mode .accordion-button {
        background: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode .accordion-body {
        background: var(--dm-card) !important;
        color: var(--dm-text) !important;
    }
    /* Small muted text */
    body.dark-mode small, body.dark-mode .small { color: var(--dm-text-muted) !important; }
    /* Table striped */
    body.dark-mode .table-striped tbody tr:nth-of-type(odd) td {
        background-color: var(--dm-surface2) !important;
    }
    /* Table bordered */
    body.dark-mode .table-bordered, body.dark-mode .table-bordered td, body.dark-mode .table-bordered th {
        border-color: var(--dm-border) !important;
    }

    /* ═══ DARK MODE &mdash; stat cards & vehicle cards ═══════════════ */
    body.dark-mode .card,
    body.dark-mode [class*="card"] {
        background: var(--dm-card) !important;
        border-color: var(--dm-border) !important;
    }
    /* Stat cards with gradient/white bg */
    body.dark-mode [style*="background:white"],
    body.dark-mode [style*="background: white"],
    body.dark-mode [style*="background:#fff"],
    body.dark-mode [style*="background: #fff"],
    body.dark-mode [style*="background:#FFF"],
    body.dark-mode [style*="background: #FFF"] {
        background: var(--dm-card) !important;
        color: var(--dm-text) !important;
    }
    /* Icon boxes inside stat cards */
    body.dark-mode [style*="background:rgba(255,255,255"],
    body.dark-mode [style*="background: rgba(255,255,255"] {
        background: rgba(255,255,255,0.1) !important;
    }
    /* Any element with white/light inline color */
    body.dark-mode [style*="color:#333"],
    body.dark-mode [style*="color: #333"],
    body.dark-mode [style*="color:#444"],
    body.dark-mode [style*="color: #444"],
    body.dark-mode [style*="color:#555"],
    body.dark-mode [style*="color: #555"],
    body.dark-mode [style*="color:#666"],
    body.dark-mode [style*="color: #666"],
    body.dark-mode [style*="color:#777"],
    body.dark-mode [style*="color: #777"],
    body.dark-mode [style*="color:#888"],
    body.dark-mode [style*="color: #888"],
    body.dark-mode [style*="color:#999"],
    body.dark-mode [style*="color: #999"],
    body.dark-mode [style*="color:#aaa"],
    body.dark-mode [style*="color:#bbb"],
    body.dark-mode [style*="color:#ccc"],
    body.dark-mode [style*="color:#ddd"],
    body.dark-mode [style*="color:#eee"],
    body.dark-mode [style*="color:#212"],
    body.dark-mode [style*="color:#343"],
    body.dark-mode [style*="color:#495"] {
        color: var(--dm-text) !important;
    }
    /* Button outline variants in dark */
    body.dark-mode .btn-outline-secondary {
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    body.dark-mode .btn-outline-secondary:hover {
        background: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode .btn-light {
        background: var(--dm-surface2) !important;
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    /* Select2 / chosen dropdowns if used */
    body.dark-mode .select2-container--default .select2-selection--single {
        background: var(--dm-input) !important;
        border-color: var(--dm-border) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode .select2-dropdown {
        background: var(--dm-surface) !important;
        border-color: var(--dm-border) !important;
        color: var(--dm-text) !important;
    }

    /* ═══ DARK MODE &mdash; fleet dashboard specific ═════════════════ */
    body.dark-mode .vehicle-card {
        background: var(--dm-card) !important;
        border-color: var(--dm-border) !important;
        color: var(--dm-text) !important;
    }
    body.dark-mode .vehicle-card * { color: var(--dm-text) !important; }
    body.dark-mode .vehicle-card .expiry-danger  { color: #f87171 !important; }
    body.dark-mode .vehicle-card .expiry-warning { color: #fbbf24 !important; }
    body.dark-mode .kpi-card {
        background: var(--dm-card) !important;
        color: var(--dm-text) !important;
        box-shadow: 0 2px 12px rgba(0,0,0,.4) !important;
    }
    body.dark-mode .kpi-card * { color: var(--dm-text) !important; }
    body.dark-mode .section-title { color: var(--dm-text) !important; }
    body.dark-mode .doc-pill { color: var(--dm-text) !important; border-color: var(--dm-border) !important; }
    /* KPI icon boxes &mdash; keep their coloured bg but darken */
    body.dark-mode .kpi-icon {
        filter: brightness(0.75) !important;
    }
    /* Catch all remaining white/light bg inline styles */
    body.dark-mode [style*="background:#eff"],
    body.dark-mode [style*="background:#fef"],
    body.dark-mode [style*="background:#ecf"],
    body.dark-mode [style*="background:#f0f"],
    body.dark-mode [style*="background:#fdf"],
    body.dark-mode [style*="background:#e8f"],
    body.dark-mode [style*="background:#f8f"],
    body.dark-mode [style*="background:#faf"],
    body.dark-mode [style*="background:#f5f"],
    body.dark-mode [style*="background:#f1f"] {
        background: var(--dm-surface2) !important;
    }
    /* Force all remaining dark text colors to light */
    body.dark-mode [style*="color:#1e"],
    body.dark-mode [style*="color:#0f"],
    body.dark-mode [style*="color:#0e"],
    body.dark-mode [style*="color:#15"],
    body.dark-mode [style*="color:#1a"],
    body.dark-mode [style*="color:#2d"],
    body.dark-mode [style*="color:#37"] {
        color: var(--dm-text) !important;
    }

    /* ═══════════════════════ PRINT OVERRIDE ═══════════════════ */
    @media print {
        .sidebar, .topbar, .sidebar-overlay, .bottom-nav { display: none !important; }
        .main-content { margin-left: 0 !important; }
        .content-area { padding: 0 !important; }
    }
    </style>
<script>
var DMS_NAV_SECTIONS = ['despatch_mgmt','finance','fleet','lease_agents','reports','utilities','settings'];
var DMS_NAV_SUBS = ['fuel','fleet_masters','fleet_expenses','desp_masters'];
var DMS_SUB_MENU_MAP = {
    'despatch_mgmt': ['desp_masters'],
    'fleet': ['fleet_masters','fuel','fleet_expenses']
};

function dmsCloseSubNav(id) {
    var panel = document.getElementById('subnav-' + id);
    var chev = document.getElementById('subchev-' + id);
    if (panel) panel.style.display = 'none';
    if (chev) chev.className = 'bi bi-chevron-down';
    try { sessionStorage.setItem('subnav_' + id, '0'); } catch(e) {}
}

function dmsCloseNav(id) {
    var panel = document.getElementById('nav-' + id);
    var chev = document.getElementById('chev-' + id);
    if (panel) panel.style.display = 'none';
    if (chev) chev.className = 'bi bi-chevron-down';
    try { sessionStorage.setItem('nav_' + id, '0'); } catch(e) {}
    (DMS_SUB_MENU_MAP[id] || []).forEach(dmsCloseSubNav);
}

function toggleNav(id) {
    var panel = document.getElementById('nav-' + id);
    var chev  = document.getElementById('chev-' + id);
    if (!panel || !chev) return;
    var open  = panel.style.display !== 'none';
    DMS_NAV_SECTIONS.forEach(function(sid) {
        if (sid === id) return;
        dmsCloseNav(sid);
    });
    if (open) {
        (DMS_SUB_MENU_MAP[id] || []).forEach(dmsCloseSubNav);
    }
    panel.style.display = open ? 'none' : '';
    chev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    try { sessionStorage.setItem('nav_' + id, open ? '0' : '1'); } catch(e) {}
}
function toggleSubNav(id) {
    var panel = document.getElementById('subnav-' + id);
    var chev  = document.getElementById('subchev-' + id);
    if (!panel || !chev) return;
    var open  = panel.style.display !== 'none';
    DMS_NAV_SUBS.forEach(function(sid) {
        if (sid === id) return;
        dmsCloseSubNav(sid);
    });
    panel.style.display = open ? 'none' : '';
    chev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    try { sessionStorage.setItem('subnav_' + id, open ? '0' : '1'); } catch(e) {}
}
document.addEventListener('DOMContentLoaded', function() {
    var activeNav = null;
    DMS_NAV_SECTIONS.forEach(function(id) {
        var panel = document.getElementById('nav-' + id);
        if (!panel) return;
        if (panel.style.display !== 'none' && activeNav === null) {
            activeNav = id;
            return;
        }
        try {
            if (activeNav === null && sessionStorage.getItem('nav_' + id) === '1') {
                activeNav = id;
            }
        } catch(e) {}
    });
    DMS_NAV_SECTIONS.forEach(function(id) {
        if (id === activeNav) {
            var panel = document.getElementById('nav-' + id);
            var chev = document.getElementById('chev-' + id);
            if (panel) panel.style.display = '';
            if (chev) chev.className = 'bi bi-chevron-up';
            return;
        }
        dmsCloseNav(id);
    });

    var activeSub = null;
    DMS_NAV_SUBS.forEach(function(id) {
        var panel = document.getElementById('subnav-' + id);
        if (!panel) return;
        if (panel.style.display !== 'none' && activeSub === null) {
            activeSub = id;
            return;
        }
        try {
            if (activeSub === null && sessionStorage.getItem('subnav_' + id) === '1') {
                activeSub = id;
            }
        } catch(e) {}
    });
    DMS_NAV_SUBS.forEach(function(id) {
        var panel = document.getElementById('subnav-' + id);
        var chev = document.getElementById('subchev-' + id);
        if (!panel) return;
        if (id === activeSub) {
            panel.style.display = '';
            if (chev) chev.className = 'bi bi-chevron-up';
            return;
        }
        dmsCloseSubNav(id);
    });
});

function toggleDarkMode() {
    var body = document.body;
    var icon = document.getElementById('darkIcon');
    var isDark = body.classList.toggle('dark-mode');
    icon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
    try { localStorage.setItem('dms_dark_mode', isDark ? '1' : '0'); } catch(e) {}
}
// Apply saved preference immediately on every page load
(function() {
    try {
        if (localStorage.getItem('dms_dark_mode') === '1') {
            document.documentElement.classList.add('dark-mode');
            document.body.classList.add('dark-mode');
        }
    } catch(e) {}
})();
document.addEventListener('DOMContentLoaded', function() {
    try {
        if (localStorage.getItem('dms_dark_mode') === '1') {
            document.body.classList.add('dark-mode');
            var icon = document.getElementById('darkIcon');
            if (icon) icon.className = 'bi bi-sun-fill';
        }
    } catch(e) {}
});

function toggleSidebar() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sidebarOverlay');
    s.classList.toggle('open');
    o.classList.toggle('show');
}
function closeSidebar() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sidebarOverlay');
    s.classList.remove('open');
    o.classList.remove('show');
}

function dmsSmartBack(fallbackUrl) {
    try {
        var ref = document.referrer || '';
        var sameOriginRef = ref && ref.indexOf(window.location.origin) === 0;
        var currentUrl = window.location.href;
        var lastInAppUrl = sessionStorage.getItem('dms_last_in_app_url') || '';
        if (sameOriginRef && ref !== currentUrl && window.history.length > 1) {
            window.history.back();
            return false;
        }
        if (lastInAppUrl && lastInAppUrl !== currentUrl) {
            window.location.href = lastInAppUrl;
            return false;
        }
    } catch (e) {}
    window.location.href = fallbackUrl;
    return false;
}
function dmsBlueOut() {
    try {
        var ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;background:#1E3A8A;z-index:99999;pointer-events:none;';
        document.body.appendChild(ov);
        document.documentElement.style.background = '#1E3A8A';
        document.body.style.background = '#1E3A8A';
    } catch (e) {}
    return true;
}
try {
    var dmsCurrentUrl = window.location.href;
    var dmsLastSeenUrl = sessionStorage.getItem('dms_current_in_app_url') || '';
    if (dmsLastSeenUrl && dmsLastSeenUrl !== dmsCurrentUrl) {
        sessionStorage.setItem('dms_last_in_app_url', dmsLastSeenUrl);
    }
    sessionStorage.setItem('dms_current_in_app_url', dmsCurrentUrl);
} catch (e) {}
</script>
<?php include_once __DIR__ . '/delivery_weather_widget.php'; ?>
</head>
<body class="nav-mode-<?= $desktop_nav_mode === 'wide_burger' ? 'wide-burger' : 'classic' ?>">
<script>try{if(localStorage.getItem('dms_dark_mode')==='1'){document.body.classList.add('dark-mode');document.documentElement.classList.add('dark-mode');}}catch(e){}</script>

<!-- ── Sidebar Overlay (mobile backdrop) ───────────────────── -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ── Sidebar ─────────────────────────────────────────────── -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-main">
            <img src="<?= htmlspecialchars($app_logo) ?>" alt="<?= htmlspecialchars($app_name) ?> logo" class="sidebar-brand-logo">
            <div class="sidebar-brand-text">
                <div class="brand-tag"><i class="bi bi-truck me-1"></i> Despatch Management</div>
                <div class="brand-name"><?= htmlspecialchars($app_name) ?></div>
            </div>
        </div>
    </div>

    <?php
    // Messages - unread count for badge
    $db->query("CREATE TABLE IF NOT EXISTS `dms_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `sender_id` INT NOT NULL, `recipient_id` INT NOT NULL,
        `subject` VARCHAR(255) NOT NULL DEFAULT '(No Subject)', `body` TEXT NOT NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0, `deleted_by_sender` TINYINT(1) NOT NULL DEFAULT 0,
        `deleted_by_recipient` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(`recipient_id`), INDEX(`sender_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_msg_uid = (int)($_SESSION['user_id'] ?? 0);
    $_msg_res = $db->query("SELECT COUNT(*) c FROM dms_messages WHERE recipient_id=$_msg_uid AND is_read=0 AND deleted_by_recipient=0");
    $_msg_unread = $_msg_res ? (int)$_msg_res->fetch_assoc()['c'] : 0;

    // Determine which section the current page belongs to
    $section_map = [
        'index.php'                => 'dashboard',
        'vendors.php'              => 'despatch_mgmt',
        'items.php'                => 'despatch_mgmt',
        'transporters.php'         => 'despatch_mgmt',
        'source_of_material.php'   => 'despatch_mgmt',
        'purchase_orders.php'      => 'despatch_mgmt',
        'despatch.php'             => 'despatch_mgmt',
        'delivery_challans.php'    => 'despatch_mgmt',
        'trading_dashboard.php'    => 'dashboard',
        'trading_register.php'     => 'dashboard',
        'aggregate_suppliers.php'  => 'dashboard',
        'aggregate_items.php'      => 'dashboard',
        'aggregate_sources.php'    => 'dashboard',
        'transporter_payments.php' => 'finance',
        'transporter_bulk_payments.php' => 'finance',
        'payment_register.php'     => 'finance',
        'transporter_bills.php'    => 'finance',
        'agent_commissions.php'    => 'finance',
        'sales_invoices.php'       => 'finance',
        'vendor_receipts.php'      => 'finance',
        'party_ledger.php'         => 'finance',
        'global_search.php'        => 'dashboard',
        'exception_dashboard.php'  => 'finance',
        'export_excel.php'         => 'finance',
        'fleet_vehicles.php'       => 'fleet',
        'fleet_drivers.php'        => 'fleet',
        'fleet_customers_master.php'=> 'fleet',
        'fleet_lease_agent_dashboard.php' => 'lease_agents',
        'fleet_lease_agents.php'   => 'lease_agents',
        'fleet_lease_agent_payments.php' => 'lease_agents',
        'fleet_lease_agent_report.php'   => 'lease_agents',
        'fleet_purchase_orders.php'=> 'fleet',
        'fleet_fuel_companies.php' => 'fleet',
        'fleet_trips.php'          => 'fleet',
        'fleet_fuel.php'           => 'fleet',
        'fleet_fuel_payments.php'  => 'fleet',
        'fleet_expenses.php'       => 'fleet',
        'fleet_misc_expenses.php'  => 'fleet',
        'fleet_vehicle_pnl.php'    => 'settings',
        'fleet_vehicle_pnl_drilldown.php' => 'settings',
        'fleet_tyres.php'          => 'fleet',
        'fleet_salary.php'         => 'fleet',
        'companies.php'            => 'settings',
        'report_daily.php'         => 'reports',
        'report_monthly.php'       => 'reports',
        'report_yearly.php'        => 'reports',
        'image2pdf.php'            => 'utilities',
        'quot_index.php'           => 'utilities',
        'gst_checker.php'          => 'utilities',
        'quot_create.php'          => 'utilities',
        'quot_edit.php'            => 'utilities',
        'quot_view.php'            => 'utilities',
        'company_settings.php'     => 'settings',
        'admin_user_activity.php'  => 'settings',
        'users.php'                => 'settings',
        'error_log.php'               => 'settings',
        'data_cleanup.php'            => 'settings',
        'month_close_lock.php'        => 'settings',
    ];
    // backup/index.php maps to settings section
    if ($in_backup && $current_page === 'index.php') {
        $active_section = 'settings';
    } else {
        $active_section = $section_map[$current_page] ?? 'dashboard';
    }
    $backup_base = $in_backup ? '' : 'backup/';
    $can_use_global_search = isAdmin() || canDo('global_search', 'view');
    ?>

    <a href="<?= $base ?>messages.php" class="nav-link <?= $current_page=='messages.php'?'active':'' ?>">
        <i class="bi bi-chat-dots-fill"></i>
        <span>Messages<?php if ($_msg_unread > 0): ?> <span class="badge bg-danger ms-1 msg-unread-badge" style="font-size:.65rem"><?= $_msg_unread ?></span><?php endif; ?></span>
    </a>

    <?php
    // Helper to output a collapsible section
    function navSection($id, $label, $icon, $active_section, $has_items) {
        if (!$has_items) return;
        $open = ($active_section === $id);
        echo '<div class="nav-group">';
        echo '<div class="nav-section nav-section-toggle" onclick="toggleNav(\''.$id.'\')" data-section="'.$id.'">';
        echo '<i class="bi '.$icon.' nav-toggle-icon"></i>';
        echo '<span class="nav-toggle-label">'.$label.'</span>';
        echo '<i class="bi bi-chevron-'.($open?'up':'down').'" id="chev-'.$id.'"></i>';
        echo '</div>';
        echo '<div id="nav-'.$id.'" style="'.($open?'':'display:none').'">';
    }
    function navSectionEnd() {
        echo '</div></div>';
    }

    $has_masters     = isAdmin() || canDo('vendors','view') || canDo('items','view') || canDo('transporters','view') || canDo('source_of_material','view');
    $has_procurement = isAdmin() || canDo('purchase_orders','view');
    $has_despatch    = isAdmin() || canDo('despatch','view') || canDo('despatch_register','view');
    $has_desp_mgmt   = $current_page === 'index.php' || $has_masters || $has_procurement || $has_despatch
                       || in_array($active_section, ['masters','procurement','despatch']);
    $desp_pages      = ['index.php','vendors.php','items.php','transporters.php','source_of_material.php',
                        'purchase_orders.php','despatch.php','delivery_challans.php'];
    $desp_open       = in_array($current_page, $desp_pages) || in_array($active_section, ['masters','procurement','despatch']);
    ?>

    <!-- Despatch Management (collapsible, contains Dashboard + Masters + Procurement + Despatch) -->
    <div class="nav-group">
    <div class="nav-section nav-section-toggle" onclick="toggleNav('despatch_mgmt')" data-section="despatch_mgmt">
        <i class="bi bi-send-check nav-toggle-icon"></i>
        <span class="nav-toggle-label">Despatch Management</span>
        <i class="bi bi-chevron-<?= $desp_open ? 'up' : 'down' ?>" id="chev-despatch_mgmt"></i>
    </div>
    <div id="nav-despatch_mgmt" style="<?= $desp_open ? '' : 'display:none' ?>">

    <!-- Despatch Dashboard -->
    <a href="<?= $base ?>index.php" class="nav-link <?= $current_page=='index.php'?'active':'' ?>">
        <i class="bi bi-speedometer2"></i><span>Despatch Dashboard</span>
    </a>

    <!-- Masters sub-menu -->
    <?php if ($has_masters):
    $masters_pages = ['vendors.php','items.php','transporters.php','source_of_material.php'];
    $masters_active = in_array($current_page, $masters_pages);
    ?>
    <div class="nav-sub-toggle" onclick="toggleSubNav('desp_masters')">
        <span><i class="bi bi-grid me-2"></i>Masters</span>
        <i class="bi bi-chevron-<?= $masters_active ? 'up' : 'down' ?>" id="subchev-desp_masters"></i>
    </div>
    <div id="subnav-desp_masters" class="nav-sub-items" style="<?= $masters_active ? '' : 'display:none' ?>">
    <?php if (isAdmin() || canDo('vendors','view')): ?>
    <a href="<?= $modules_base ?>vendors.php" class="nav-link <?= $current_page=='vendors.php'?'active':'' ?>">
        <i class="bi bi-building"></i><span>Vendor Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('items','view')): ?>
    <a href="<?= $modules_base ?>items.php" class="nav-link <?= $current_page=='items.php'?'active':'' ?>">
        <i class="bi bi-box-seam"></i><span>Item Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('transporters','view')): ?>
    <a href="<?= $modules_base ?>transporters.php" class="nav-link <?= $current_page=='transporters.php'?'active':'' ?>">
        <i class="bi bi-truck-front"></i><span>Transporter Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('source_of_material','view')): ?>
    <a href="<?= $modules_base ?>source_of_material.php" class="nav-link <?= $current_page=='source_of_material.php'?'active':'' ?>">
        <i class="bi bi-geo-alt"></i><span>Source of Material</span>
    </a>
    <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Procurement -->
    <?php if ($has_procurement): ?>
    <a href="<?= $modules_base ?>purchase_orders.php" class="nav-link <?= $current_page=='purchase_orders.php'?'active':'' ?>">
        <i class="bi bi-file-earmark-text"></i><span>Purchase Orders</span>
    </a>
    <?php endif; ?>

    <!-- Despatch Orders -->
    <?php if (isAdmin() || canDo('despatch','view')): ?>
    <a href="<?= $modules_base ?>despatch.php?action=list" class="nav-link <?= ($current_page=='despatch.php' && (($_GET['view'] ?? '') !== 'register'))?'active':'' ?>">
        <i class="bi bi-send-check"></i><span>Despatch Orders</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('despatch_register','view')): ?>
    <a href="<?= $modules_base ?>despatch.php?action=list&view=register" class="nav-link <?= ($current_page=='despatch.php' && (($_GET['view'] ?? '') === 'register'))?'active':'' ?>">
        <i class="bi bi-journal-text"></i><span>Despatch Register</span>
    </a>
    <?php endif; ?>

    </div></div><!-- /Despatch Management -->

    <?php
    ?>

    <?php
    $has_finance = isAdmin() || canDo('transporter_payments','view') || canDo('transporter_bulk_payments','view') || canDo('payment_register','view') || canDo('transporter_bills','view') || canDo('agent_commissions','view') || canDo('sales_invoices','view') || canDo('vendor_receipts','view') || canDo('party_ledger','view') || canDo('exception_dashboard','view') || canDo('export_excel','view');
    navSection('finance','Finance','bi-cash-coin', $active_section, $has_finance);
    ?>
    <?php if (isAdmin() || canDo('transporter_payments','view')): ?>
    <a href="<?= $modules_base ?>transporter_payments.php?action=list" class="nav-link <?= ($current_page=='transporter_payments.php' && (($_GET['view'] ?? '') !== 'register'))?'active':'' ?>">
        <i class="bi bi-cash-coin"></i><span>Transporter Payments</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('transporter_bulk_payments','view')): ?>
    <a href="<?= $modules_base ?>transporter_bulk_payments.php" class="nav-link <?= $current_page=='transporter_bulk_payments.php'?'active':'' ?>">
        <i class="bi bi-collection"></i><span>Bulk Transporter Payments</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('payment_register','view')): ?>
    <a href="<?= $modules_base ?>payment_register.php" class="nav-link <?= $current_page=='payment_register.php'?'active':'' ?>">
        <i class="bi bi-journal-text"></i><span>Payment Register</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('transporter_bills','view')): ?>
    <a href="<?= $modules_base ?>transporter_bills.php" class="nav-link <?= $current_page=='transporter_bills.php'?'active':'' ?>">
        <i class="bi bi-receipt-cutoff"></i><span>Transporter Pending Bills</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('agent_commissions','view')): ?>
    <a href="<?= $modules_base ?>agent_commissions.php" class="nav-link <?= $current_page=='agent_commissions.php'?'active':'' ?>">
        <i class="bi bi-percent"></i><span>Agent Commissions</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('sales_invoices','view')): ?>
    <a href="<?= $modules_base ?>sales_invoices.php" class="nav-link <?= $current_page=='sales_invoices.php'?'active':'' ?>">
        <i class="bi bi-receipt-cutoff"></i><span>Sales Invoices</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('vendor_receipts','view')): ?>
    <a href="<?= $modules_base ?>vendor_receipts.php" class="nav-link <?= $current_page=='vendor_receipts.php'?'active':'' ?>">
        <i class="bi bi-wallet2"></i><span>Vendor Receipts</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('party_ledger','view')): ?>
    <a href="<?= $modules_base ?>party_ledger.php" class="nav-link <?= $current_page=='party_ledger.php'?'active':'' ?>">
        <i class="bi bi-journal-text"></i><span>Party Ledger</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('exception_dashboard','view')): ?>
    <a href="<?= $modules_base ?>exception_dashboard.php" class="nav-link <?= $current_page=='exception_dashboard.php'?'active':'' ?>">
        <i class="bi bi-exclamation-triangle"></i><span>Exception Dashboard</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('export_excel','view')): ?>
    <a href="<?= $modules_base ?>export_excel.php" class="nav-link <?= $current_page=='export_excel.php'?'active':'' ?>">
        <i class="bi bi-file-earmark-excel"></i><span>Export to Excel</span>
    </a>
    <?php endif; ?>
    <?php if ($has_finance) navSectionEnd(); ?>

    <?php
    $has_fleet = isAdmin() || canDo('fleet_dashboard','view') || canDo('fleet_vehicles','view') || canDo('fleet_drivers','view') || canDo('fleet_customers_master','view') || canDo('fleet_lease_agents','view') || canDo('fleet_purchase_orders','view') || canDo('fleet_fuel_companies','view') || canDo('fleet_trips','view') || canDo('fleet_trip_register','view') || canDo('fleet_status','view') || canDo('fleet_fuel','view') || canDo('fleet_fuel_payments','view') || canDo('fleet_expenses','view') || canDo('fleet_tyres','view') || canDo('fleet_salary','view');
    navSection('fleet','Fleet Management','bi-truck', $active_section, $has_fleet);
    ?>
    <?php if (isAdmin() || canDo('fleet_dashboard','view')): ?>
    <a href="<?= $modules_base ?>fleet_dashboard.php" class="nav-link <?= $current_page=='fleet_dashboard.php'?'active':'' ?>">
        <i class="bi bi-speedometer2"></i><span>Fleet Dashboard</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_trips','view')): ?>
    <a href="<?= $modules_base ?>fleet_trips.php?action=list" class="nav-link <?= ($current_page=='fleet_trips.php' && (($_GET['view'] ?? '') !== 'register'))?'active':'' ?>">
        <i class="bi bi-signpost-split"></i><span>Trip Orders</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_trip_register','view')): ?>
    <a href="<?= $modules_base ?>fleet_trips.php?action=list&view=register" class="nav-link <?= ($current_page=='fleet_trips.php' && (($_GET['view'] ?? '') === 'register'))?'active':'' ?>">
        <i class="bi bi-journal-text"></i><span>Trip Order Register</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_trip_register','view')): ?>
    <a href="<?= $modules_base ?>fleet_pending_billing.php" class="nav-link <?= $current_page=='fleet_pending_billing.php'?'active':'' ?>">
        <i class="bi bi-receipt-cutoff"></i><span>Pending Billing Status</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_status','view')): ?>
    <a href="<?= $modules_base ?>fleet_status.php" class="nav-link <?= $current_page=='fleet_status.php'?'active':'' ?>">
        <i class="bi bi-map"></i><span>Vehicle Status</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_trips','view')): ?>
    <a href="<?= $modules_base ?>fleet_toll_rates.php" class="nav-link <?= $current_page=='fleet_toll_rates.php'?'active':'' ?>">
        <i class="bi bi-cash-coin"></i><span>Toll Rate Master</span>
    </a>
    <?php endif; ?>
    <?php
    $masters_pages = ['fleet_vehicles.php','fleet_drivers.php','fleet_customers_master.php','fleet_purchase_orders.php'];
    $masters_active = in_array($current_page, $masters_pages);
    $has_masters_sub = isAdmin() || canDo('fleet_vehicles','view') || canDo('fleet_drivers','view') || canDo('fleet_customers_master','view') || canDo('fleet_purchase_orders','view');
    if ($has_masters_sub):
    ?>
    <div class="nav-sub-toggle" onclick="toggleSubNav('fleet_masters')">
        <span><i class="bi bi-grid me-2"></i>Masters</span>
        <i class="bi bi-chevron-<?= $masters_active ? 'up' : 'down' ?>" id="subchev-fleet_masters"></i>
    </div>
    <div id="subnav-fleet_masters" class="nav-sub-items" style="<?= $masters_active ? '' : 'display:none' ?>">
    <?php if (isAdmin() || canDo('fleet_vehicles','view')): ?>
    <a href="<?= $modules_base ?>fleet_vehicles.php" class="nav-link <?= $current_page=='fleet_vehicles.php'?'active':'' ?>">
        <i class="bi bi-truck"></i><span>Vehicle Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_drivers','view')): ?>
    <a href="<?= $modules_base ?>fleet_drivers.php" class="nav-link <?= $current_page=='fleet_drivers.php'?'active':'' ?>">
        <i class="bi bi-person-badge"></i><span>Driver Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_customers_master','view')): ?>
    <a href="<?= $modules_base ?>fleet_customers_master.php" class="nav-link <?= $current_page=='fleet_customers_master.php'?'active':'' ?>">
        <i class="bi bi-person-lines-fill"></i><span>Customer Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_purchase_orders','view')): ?>
    <a href="<?= $modules_base ?>fleet_purchase_orders.php" class="nav-link <?= $current_page=='fleet_purchase_orders.php'?'active':'' ?>">
        <i class="bi bi-file-earmark-text"></i><span>Customer POs</span>
    </a>
    <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
    $fuel_pages = ['fleet_fuel_companies.php','fleet_fuel.php','fleet_fuel_payments.php'];
    $fuel_active = in_array($current_page, $fuel_pages);
    $has_fuel_sub = isAdmin() || canDo('fleet_fuel_companies','view') || canDo('fleet_fuel','view') || canDo('fleet_fuel_payments','view');
    if ($has_fuel_sub):
    ?>
    <div class="nav-sub-toggle" onclick="toggleSubNav('fuel')">
        <span><i class="bi bi-droplet-fill me-2"></i>Fuel</span>
        <i class="bi bi-chevron-<?= $fuel_active ? 'up' : 'down' ?>" id="subchev-fuel"></i>
    </div>
    <div id="subnav-fuel" class="nav-sub-items" style="<?= $fuel_active ? '' : 'display:none' ?>">
    <?php if (isAdmin() || canDo('fleet_fuel_companies','view')): ?>
    <a href="<?= $modules_base ?>fleet_fuel_companies.php" class="nav-link <?= $current_page=='fleet_fuel_companies.php'?'active':'' ?>">
        <i class="bi bi-fuel-pump"></i><span>Fuel Companies</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_fuel','view')): ?>
    <a href="<?= $modules_base ?>fleet_fuel.php" class="nav-link <?= $current_page=='fleet_fuel.php'?'active':'' ?>">
        <i class="bi bi-droplet-fill"></i><span>Fuel Management</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_fuel_payments','view')): ?>
    <a href="<?= $modules_base ?>fleet_fuel_payments.php" class="nav-link <?= $current_page=='fleet_fuel_payments.php'?'active':'' ?>">
        <i class="bi bi-cash-coin"></i><span>Fuel Payments</span>
    </a>
    <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
    $exp_pages = ['fleet_expenses.php','fleet_tyres.php','fleet_salary.php','fleet_misc_expenses.php'];
    $exp_active = in_array($current_page, $exp_pages);
    $has_exp_sub = isAdmin() || canDo('fleet_expenses','view') || canDo('fleet_tyres','view') || canDo('fleet_salary','view');
    if ($has_exp_sub):
    ?>
    <div class="nav-sub-toggle" onclick="toggleSubNav('fleet_expenses')">
        <span><i class="bi bi-tools me-2"></i>Expenses & Payroll</span>
        <i class="bi bi-chevron-<?= $exp_active ? 'up' : 'down' ?>" id="subchev-fleet_expenses"></i>
    </div>
    <div id="subnav-fleet_expenses" class="nav-sub-items" style="<?= $exp_active ? '' : 'display:none' ?>">
    <?php if (isAdmin() || canDo('fleet_expenses','view')): ?>
    <a href="<?= $modules_base ?>fleet_expenses.php" class="nav-link <?= $current_page=='fleet_expenses.php'?'active':'' ?>">
        <i class="bi bi-tools"></i><span>Vehicle Expenses</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_expenses','view')): ?>
    <a href="<?= $modules_base ?>fleet_misc_expenses.php" class="nav-link <?= $current_page=='fleet_misc_expenses.php'?'active':'' ?>">
        <i class="bi bi-clipboard2-plus"></i><span>Misc Expenses</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_tyres','view')): ?>
    <a href="<?= $modules_base ?>fleet_tyres.php" class="nav-link <?= $current_page=='fleet_tyres.php'?'active':'' ?>">
        <i class="bi bi-circle"></i><span>Tyre Tracking</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_salary','view')): ?>
    <a href="<?= $modules_base ?>fleet_salary.php" class="nav-link <?= $current_page=='fleet_salary.php'?'active':'' ?>">
        <i class="bi bi-wallet2"></i><span>Driver Salary</span>
    </a>
    <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($has_fleet) navSectionEnd(); ?>

    <?php
    $has_lease_agents = isAdmin() || canDo('fleet_lease_agents','view') || canDo('fleet_trips','view') || canDo('reports','view') || isLeaseAgentUser();
    navSection('lease_agents','Lease Agent','bi-person-badge', $active_section, $has_lease_agents);
    ?>
    <?php if (isAdmin() || canDo('fleet_lease_agents','view') || canDo('fleet_trips','view') || isLeaseAgentUser()): ?>
    <a href="<?= $modules_base ?>fleet_lease_agent_dashboard.php" class="nav-link <?= $current_page=='fleet_lease_agent_dashboard.php'?'active':'' ?>">
        <i class="bi bi-speedometer2"></i><span>Lease Dashboard</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_lease_agents','view')): ?>
    <a href="<?= $modules_base ?>fleet_lease_agents.php" class="nav-link <?= $current_page=='fleet_lease_agents.php'?'active':'' ?>">
        <i class="bi bi-person-badge"></i><span>Lease Agent Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_trips','view')): ?>
    <a href="<?= $modules_base ?>fleet_lease_agent_payments.php" class="nav-link <?= $current_page=='fleet_lease_agent_payments.php'?'active':'' ?>">
        <i class="bi bi-cash-stack"></i><span>Lease Agent Payments</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('reports','view')): ?>
    <a href="<?= $modules_base ?>fleet_lease_agent_report.php" class="nav-link <?= $current_page=='fleet_lease_agent_report.php'?'active':'' ?>">
        <i class="bi bi-file-earmark-bar-graph"></i><span>Lease Agent Report</span>
    </a>
    <?php endif; ?>
    <?php if ($has_lease_agents) navSectionEnd(); ?>

    <?php
    $has_reports = isAdmin() || canDo('reports','view');
    navSection('reports','Reports','bi-bar-chart-line', $active_section, $has_reports);
    if ($has_reports):
    ?>
    <a href="<?= $modules_base ?>report_daily.php" class="nav-link <?= $current_page=='report_daily.php'?'active':'' ?>">
        <i class="bi bi-calendar-day"></i><span>Daily Report</span>
    </a>
    <a href="<?= $modules_base ?>report_monthly.php" class="nav-link <?= $current_page=='report_monthly.php'?'active':'' ?>">
        <i class="bi bi-calendar-month"></i><span>Monthly Report</span>
    </a>
    <a href="<?= $modules_base ?>fleet_customer_revenue.php" class="nav-link <?= in_array($current_page, ['fleet_customer_revenue.php','fleet_customer_revenue_drilldown.php'])?'active':'' ?>">
        <i class="bi bi-people"></i><span>Customer Revenue</span>
    </a>
    <a href="<?= $modules_base ?>fleet_customer_pnl.php" class="nav-link <?= in_array($current_page, ['fleet_customer_pnl.php','fleet_customer_pnl_drilldown.php'])?'active':'' ?>">
        <i class="bi bi-graph-up-arrow"></i><span>Customer P&amp;L</span>
    </a>
    <a href="<?= $modules_base ?>report_yearly.php" class="nav-link <?= $current_page=='report_yearly.php'?'active':'' ?>">
        <i class="bi bi-calendar-range"></i><span>Yearly Report</span>
    </a>
    <?php navSectionEnd(); endif; ?>

    <?php if (isAdmin() || canDo('quotations','view') || canDo('gst_checker','view')): ?>
    <?php
    $has_utilities = true;
    navSection('utilities','Utilities','bi-magic', $active_section, $has_utilities);
    ?>
    <?php if (isAdmin() || canDo('quotations','view') || canDo('gst_checker','view')): ?>
    <a href="<?= $modules_base ?>quot_index.php" class="nav-link <?= ($current_page==='quot_index.php')?'active':'' ?>">
        <i class="bi bi-file-earmark-text-fill"></i><span>Quotations</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('gst_checker','view')): ?>
    <a href="<?= $modules_base ?>gst_checker.php" class="nav-link <?= ($current_page==='gst_checker.php')?'active':'' ?>">
        <i class="bi bi-patch-check-fill"></i><span>GST Checker</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin()): ?>
    <a href="http://tsgimpex.com/image2pdf" class="nav-link" target="_blank" rel="noopener noreferrer">
        <i class="bi bi-file-earmark-image"></i><span>Image2PDF</span>
    </a>
    <a href="https://tsgimpex.com/image2pdf/pdf-editor.html" class="nav-link" target="_blank" rel="noopener noreferrer">
        <i class="bi bi-file-earmark-pdf"></i><span>PDF Editor</span>
    </a>
    <a href="http://tsgimpex.com/familywealth" class="nav-link" target="_blank" rel="noopener noreferrer">
        <i class="bi bi-piggy-bank"></i><span>Family Wealth</span>
    </a>
    <?php endif; ?>
    <?php navSectionEnd(); ?>
    <?php endif; ?>

    <?php if (isAdmin()): ?>
    <?php navSection('settings','Admin Panel','bi-shield-lock', $active_section, true); ?>
    <a href="<?= $modules_base ?>companies.php" class="nav-link <?= $current_page=='companies.php'?'active':'' ?>">
        <i class="bi bi-buildings"></i><span>Companies</span>
    </a>
    <a href="<?= $modules_base ?>company_settings.php" class="nav-link <?= $current_page=='company_settings.php'?'active':'' ?>">
        <i class="bi bi-gear"></i><span>Company Settings</span>
    </a>
    <a href="<?= $modules_base ?>users.php" class="nav-link <?= $current_page=='users.php'?'active':'' ?>">
        <i class="bi bi-people"></i><span>User Management</span>
    </a>
    <a href="<?= $modules_base ?>admin_user_activity.php" class="nav-link <?= $current_page=='admin_user_activity.php'?'active':'' ?>">
        <i class="bi bi-activity"></i><span>Daily User Activity</span>
    </a>
    <a href="<?= $modules_base ?>fleet_vehicle_pnl.php" class="nav-link <?= in_array($current_page, ['fleet_vehicle_pnl.php','fleet_vehicle_pnl_drilldown.php'])?'active':'' ?>">
        <i class="bi bi-graph-up-arrow"></i><span>Vehicle P&amp;L</span>
    </a>
    <a href="<?= $base ?><?= $backup_base ?>index.php" class="nav-link <?= ($in_backup && $current_page=='index.php')?'active':'' ?>">
        <i class="bi bi-cloud-arrow-up"></i><span>Backup &amp; Restore</span>
    </a>
    <a href="<?= $modules_base ?>error_log.php" class="nav-link <?= $current_page=='error_log.php'?'active':'' ?>">
        <i class="bi bi-bug"></i><span>Error Log
        <?php
        $db->query("CREATE TABLE IF NOT EXISTS app_error_log (
            id INT AUTO_INCREMENT PRIMARY KEY, logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            level VARCHAR(20), message VARCHAR(1000), file VARCHAR(255), line INT DEFAULT 0,
            url VARCHAR(500), user_id INT DEFAULT 0, trace TEXT, resolved TINYINT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $err_count = $db->query("SELECT COUNT(*) c FROM app_error_log WHERE resolved=0 LIMIT 1");
        if ($err_count) {
            $ec = (int)$err_count->fetch_assoc()['c'];
            if ($ec > 0) echo '<span class="badge bg-danger ms-1" style="font-size:.65rem">'.$ec.'</span>';
        }
        ?>
        </span>
    </a>
    <a href="<?= $modules_base ?>data_cleanup.php" class="nav-link <?= $current_page=='data_cleanup.php'?'active':'' ?>">
        <i class="bi bi-trash3"></i><span>Data Cleanup</span>
    </a>
    <a href="<?= $modules_base ?>month_close_lock.php" class="nav-link <?= $current_page=='month_close_lock.php'?'active':'' ?>">
        <i class="bi bi-calendar-check"></i><span>Month Close Lock</span>
    </a>
    <?php navSectionEnd(); ?>
    <?php endif; ?>

    <!-- Bottom spacer so content doesn't get hidden under iOS home bar -->
    <div style="height: env(safe-area-inset-bottom, 16px); flex-shrink:0"></div>
</div>

<!-- ── Main Content ────────────────────────────────────────── -->
<div class="main-content" id="mainContent">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <?php if ($show_smart_back): ?>
            <a href="<?= htmlspecialchars($smart_back_fallback) ?>"
               class="topbar-back-btn"
               onclick="return dmsSmartBack('<?= htmlspecialchars($smart_back_fallback, ENT_QUOTES) ?>')"
               title="Go back">
                <i class="bi bi-arrow-left"></i>
            </a>
            <?php endif; ?>
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle menu">
                <i class="bi bi-list"></i>
            </button>
            <?php if ($current_page !== 'index.php'): ?>
            <a href="<?= $base ?>index.php"
               class="topbar-home-btn"
               title="Home"
               aria-label="Home">
                <i class="bi bi-house-door-fill"></i>
                <span class="topbar-home-label">Home</span>
            </a>
            <?php endif; ?>
            <div class="topbar-titles">
                <h4 id="page-title"><i class="bi bi-grid me-1"></i>Dashboard</h4>
            </div>
        </div>
        <?php if ($can_use_global_search): ?>
        <div class="topbar-center">
            <form method="GET" action="<?= $modules_base ?>global_search.php" class="topbar-search-form">
                <input type="text"
                       name="q"
                       class="form-control topbar-search-input"
                       value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                       placeholder="Search challan, trip, vehicle, payment..."
                       autocomplete="off">
                <button type="submit" class="btn topbar-search-btn" aria-label="Search">
                    <i class="bi bi-search"></i>
                </button>
            </form>
        </div>
        <?php endif; ?>
        <div class="topbar-right">
            <div class="dropdown">
                <button type="button" class="btn topbar-mode-switch dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Change menu style">
                    <i class="bi <?= htmlspecialchars($nav_mode_options[$desktop_nav_mode]['icon']) ?>"></i>
                    <span class="topbar-mode-label"><?= htmlspecialchars($nav_mode_options[$desktop_nav_mode]['short']) ?></span>
                    <i class="bi bi-chevron-down" style="font-size:.65rem"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow topbar-mode-menu">
                    <?php foreach ($nav_mode_options as $mode_key => $mode_meta): ?>
                    <form method="post" action="<?= $modules_base ?>set_nav_mode.php" class="topbar-mode-form">
                        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode_key) ?>">
                        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($nav_mode_redirect) ?>">
                        <button type="submit" class="dropdown-item py-2 <?= $desktop_nav_mode === $mode_key ? 'active' : '' ?>">
                            <i class="bi <?= htmlspecialchars($mode_meta['icon']) ?> me-2 text-success"></i><?= htmlspecialchars($mode_meta['label']) ?>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="<?= $base ?>messages.php"
               class="topbar-messages-btn <?= $current_page === 'messages.php' ? 'active' : '' ?>"
               title="Messages"
               aria-label="Messages">
                <i class="bi bi-chat-dots-fill"></i>
                <span class="topbar-messages-label">Messages</span>
                <?php if (!empty($_msg_unread) && $_msg_unread > 0): ?>
                <span class="badge bg-danger topbar-messages-badge"><?= $_msg_unread ?></span>
                <?php endif; ?>
            </a>
            <button class="dark-toggle" id="darkToggle" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                <i class="bi bi-moon-fill" id="darkIcon"></i>
            </button>
            <div class="dropdown">
                <button class="btn btn-sm d-flex align-items-center gap-2 topbar-user-btn"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="d-flex align-items-center justify-content-center rounded-circle text-white fw-bold"
                          style="width:26px;height:26px;font-size:.75rem;background:linear-gradient(135deg,#1E3A8A,#334155)">
                        <?= strtoupper(substr($_SESSION['full_name']??$_SESSION['username']??'U',0,1)) ?>
                    </span>
                    <span class="topbar-user-name d-none d-md-inline" style="font-size:.82rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff">
                        <?= htmlspecialchars(currentDisplayName()) ?>
                    </span>
                    <?php if (isAdmin()): ?><span class="badge bg-danger topbar-user-role" style="font-size:.6rem">Admin</span><?php endif; ?>
                    <i class="bi bi-chevron-down" style="font-size:.65rem"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:200px;border-radius:12px">
                    <li><div class="px-3 py-2 border-bottom">
                        <div class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars(currentDisplayName()) ?></div>
                        <div class="text-muted" style="font-size:.76rem">@<?= htmlspecialchars($_SESSION['username'] ?? '') ?></div>
                    </div></li>
                    <?php if (isAdmin()): ?>
                    <li><a class="dropdown-item py-2" href="<?= $modules_base ?>users.php">
                        <i class="bi bi-people me-2 text-primary"></i>User Management
                    </a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="<?= $base ?>logout.php" onclick="return dmsBlueOut()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a></li>
                </ul>
            </div>
            <span class="topbar-date d-none d-md-inline"><?= date('d M Y') ?></span>
        </div>
    </div>

    <div class="content-area">
<?php displayAlert(); ?>

<!-- ── Bottom Nav Bar (mobile only) ───────────────────────── -->
<?php
$mobile_nav_count = 1;
if (isAdmin() || canDo('despatch','view')) $mobile_nav_count++;
if (isAdmin() || canDo('despatch','add')) $mobile_nav_count++;
if (isAdmin() || canDo('fleet_trips','view')) $mobile_nav_count++;
?>
<nav class="bottom-nav" style="--mobile-nav-count: <?= (int)$mobile_nav_count ?>;">
    <a href="<?= $base ?>index.php" class="<?= $current_page=='index.php'?'active':'' ?>">
        <div class="nav-icon-wrap"><i class="bi bi-speedometer2"></i></div>
        <span class="nav-label">Home</span>
    </a>
    <?php if (isAdmin() || canDo('despatch','view')): ?>
    <a href="<?= $modules_base ?>despatch.php?action=list" class="<?= ($current_page=='despatch.php' && (($_GET['view'] ?? '') !== 'register'))?'active':'' ?>">
        <div class="nav-icon-wrap"><i class="bi bi-send-check"></i></div>
        <span class="nav-label">Despatch</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('despatch','add')): ?>
    <a href="<?= $modules_base ?>despatch.php?action=add" class="add-btn">
        <div class="nav-icon-wrap"><i class="bi bi-plus-lg"></i></div>
        <span class="nav-label">New</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_trips','view')): ?>
    <a href="<?= $modules_base ?>fleet_trips.php?action=list" class="<?= ($current_page=='fleet_trips.php' && (($_GET['view'] ?? '') !== 'register'))?'active':'' ?>">
        <div class="nav-icon-wrap"><i class="bi bi-signpost-split"></i></div>
        <span class="nav-label">Trips</span>
    </a>
    <?php endif; ?>
</nav>

<script>
/* Auto-dismiss alerts after 4 seconds */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.alert.alert-dismissible').forEach(function(el) {
        setTimeout(function() {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            if (bsAlert) bsAlert.close();
        }, 4000);
    });
});
</script>

