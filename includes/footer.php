    </div><!-- /content-area -->
</div><!-- /main-content -->
<?php
$dmsPushPublicKey = '';
if (!empty($_SESSION['user_id']) && function_exists('getDB')) {
    require_once __DIR__ . '/webpush_helper.php';
    $keys = dmsEnsureVapidKeys(getDB());
    $dmsPushPublicKey = (string)($keys['public'] ?? '');
}
?>

<!-- Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<!-- DataTables core + Responsive extension -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
/* Global submit loading state for POST forms */
(function () {
    if (window.__dmsSubmitGuardInit) return;
    window.__dmsSubmitGuardInit = true;
    // Disabled globally on request: the generic Saving overlay blocks normal form flows.
    return;

    var activeSubmitter = null;
    var overlay = null;

    function ensureOverlay() {
        if (overlay) return overlay;

        var style = document.createElement('style');
        style.id = 'dms-submit-overlay-style';
        style.textContent = ''
            + '.dms-submit-overlay{position:fixed;inset:0;background:rgba(15,23,42,.22);display:none;align-items:center;justify-content:center;z-index:2000;padding:1rem;}'
            + '.dms-submit-overlay.show{display:flex;}'
            + '.dms-submit-card{min-width:280px;max-width:92vw;background:#fff;border-radius:12px;box-shadow:0 20px 45px rgba(15,23,42,.18);border:1px solid rgba(15,23,42,.08);padding:1rem 1.1rem;display:flex;align-items:center;gap:.85rem;}'
            + '.dms-submit-spinner{width:2rem;height:2rem;border:.22rem solid #d7e7dc;border-top-color:#1E3A8A;border-radius:50%;animation:dmsSubmitSpin .8s linear infinite;flex:0 0 auto;}'
            + '.dms-submit-title{font-weight:700;color:#173225;line-height:1.2;margin:0 0 .15rem 0;}'
            + '.dms-submit-text{font-size:.84rem;color:#5b6573;line-height:1.35;margin:0;}'
            + 'body.dark-mode .dms-submit-card{background:#1e1f24;border-color:#2f323a;box-shadow:0 20px 45px rgba(0,0,0,.35);}'
            + 'body.dark-mode .dms-submit-title{color:#f3f4f6;}'
            + 'body.dark-mode .dms-submit-text{color:#c7ced8;}'
            + '@keyframes dmsSubmitSpin{to{transform:rotate(360deg)}}';
        if (!document.getElementById(style.id)) document.head.appendChild(style);

        overlay = document.createElement('div');
        overlay.className = 'dms-submit-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML =
            '<div class="dms-submit-card" role="status" aria-live="polite">' +
                '<div class="dms-submit-spinner"></div>' +
                '<div>' +
                    '<div class="dms-submit-title">Saving...</div>' +
                    '<div class="dms-submit-text">Please wait. Do not close or go back from this page.</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    document.addEventListener('click', function (event) {
        var submitter = event.target.closest('button[type="submit"],input[type="submit"]');
        if (submitter) activeSubmitter = submitter;
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        if (method === 'GET') return;
        // Skip global guard for complex upload forms with their own submit handlers.
        if ((form.enctype || '').toLowerCase() === 'multipart/form-data') return;
        if (form.id === 'despatchForm') return;
        if (form.hasAttribute('data-skip-saving-overlay')) return;
        if (form.target && form.target.toLowerCase() === '_blank') return;
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }

        form.dataset.submitting = '1';

        var submitButtons = form.querySelectorAll('button[type="submit"],input[type="submit"]');
        submitButtons.forEach(function (btn) {
            btn.disabled = true;
            if (!btn.dataset.originalHtml && btn.tagName === 'BUTTON') btn.dataset.originalHtml = btn.innerHTML;
            if (!btn.dataset.originalValue && btn.tagName === 'INPUT') btn.dataset.originalValue = btn.value;
        });

        if (activeSubmitter && form.contains(activeSubmitter)) {
            if (activeSubmitter.tagName === 'BUTTON') {
                activeSubmitter.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
            } else if (activeSubmitter.tagName === 'INPUT') {
                activeSubmitter.value = 'Saving...';
            }
        }

        ensureOverlay().classList.add('show');
    }, true);

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitting="1"]').forEach(function (form) {
            delete form.dataset.submitting;
            form.querySelectorAll('button[type="submit"],input[type="submit"]').forEach(function (btn) {
                btn.disabled = false;
                if (btn.tagName === 'BUTTON' && btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
                if (btn.tagName === 'INPUT' && btn.dataset.originalValue) btn.value = btn.dataset.originalValue;
            });
        });
        if (overlay) overlay.classList.remove('show');
        activeSubmitter = null;
    });
})();
/* ── DataTables ─────────────────────────────────────────── */
$(document).ready(function () {
    if ($('.datatable').length) {
        $('.datatable').DataTable({
            responsive: true,
            pageLength: 25,
            language: {
                search:     '<i class="bi bi-search me-1"></i>',
                searchPlaceholder: 'Search...',
                emptyTable: 'No records found',
                paginate: {
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next:     '<i class="bi bi-chevron-right"></i>'
                }
            },
            dom: "<'row mb-2'<'col-sm-6'l><'col-sm-6'f>>" +
                 "<'row'<'col-12'tr>>" +
                 "<'row mt-2'<'col-sm-5'i><'col-sm-7'p>>",
        });
    }

    /* Bootstrap tooltips */
    $('[data-bs-toggle="tooltip"]').each(function () {
        new bootstrap.Tooltip(this);
    });
});

/* ── Sidebar toggle ─────────────────────────────────────── */
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';   // prevent body scroll on mobile
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.style.overflow = '';
}
function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    sb.classList.contains('open') ? closeSidebar() : openSidebar();
}

/* Close sidebar when a nav link is clicked on mobile */
document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
        if (window.innerWidth < 992) closeSidebar();
    });
});

/* Close sidebar on resize to desktop */
window.addEventListener('resize', function () {
    if (window.innerWidth >= 992) closeSidebar();
});

/* ── Mobile table enhancement ───────────────────────────── */
if (window.innerWidth < 576) {
    // Add data-label to all td's based on thead
    document.querySelectorAll('table.table:not(.no-mobile-stack)').forEach(function(tbl) {
        var headers = [];
        tbl.querySelectorAll('thead th').forEach(function(th) {
            headers.push(th.innerText.trim());
        });
        if (headers.length === 0) return;
        tbl.querySelectorAll('tbody tr').forEach(function(tr) {
            tr.querySelectorAll('td').forEach(function(td, i) {
                if (headers[i]) td.setAttribute('data-label', headers[i]);
            });
        });
    });
}

/* ── Auto-refresh on list pages ────────────────────────── */
(function () {
    var INTERVAL = 60000; // 60 seconds
    var addPages  = ['despatch.php','fleet_trips.php','index.php'];
    var skipPages = ['action=add','action=edit','action=view'];
    var page = window.location.pathname.split('/').pop();
    var qs   = window.location.search;
    var isListPage = addPages.some(function(p){ return page === p; });
    var isFormPage = skipPages.some(function(s){ return qs.indexOf(s) !== -1; });
    if (!isListPage || isFormPage) return;

    var timer, paused = false;

    // Pause if user is actively typing
    document.addEventListener('focusin',  function(e){ if (e.target.tagName==='INPUT'||e.target.tagName==='SELECT'||e.target.tagName==='TEXTAREA') paused=true; });
    document.addEventListener('focusout', function(e){ if (e.target.tagName==='INPUT'||e.target.tagName==='SELECT'||e.target.tagName==='TEXTAREA') paused=false; });

    // Countdown badge
    // Inject into topbar actions if available, else fixed top-right
    var badge = document.createElement('div');
    badge.id  = 'refresh-badge';
    badge.title = 'Click to refresh now';
    badge.onclick = function(){ window.location.reload(); };

    var topbarRight = document.querySelector('.topbar-right');
    if (topbarRight) {
        badge.style.cssText = 'display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.18);color:#fff;font-size:.75rem;font-weight:600;padding:4px 11px;border-radius:20px;cursor:pointer;user-select:none;border:1px solid rgba(255,255,255,.3);flex-shrink:0';
        topbarRight.appendChild(badge);
    } else {
        badge.style.cssText = 'position:fixed;top:12px;right:70px;background:rgba(30,58,138,.92);color:#fff;font-size:.75rem;font-weight:600;padding:4px 11px;border-radius:20px;z-index:1050;cursor:pointer;user-select:none;box-shadow:0 2px 8px rgba(0,0,0,.2)';
        document.body.appendChild(badge);
    }

    function applyRefreshBadgeVisibility() {
        if (!badge) return;
        var onTopbar = !!(badge.parentElement && badge.parentElement.classList.contains('topbar-right'));
        if (onTopbar && window.innerWidth <= 1400) {
            badge.style.display = 'none';
        } else {
            badge.style.display = 'inline-flex';
        }
    }
    applyRefreshBadgeVisibility();
    window.addEventListener('resize', applyRefreshBadgeVisibility);

    var secs = INTERVAL / 1000;
    function tick() {
        if (!paused) secs--;
        if (secs <= 0) { badge.innerHTML = '<span style="animation:spin 1s linear infinite;display:inline-block">↻</span> Refreshing...'; window.location.reload(); return; }
        var pct = Math.round((secs / (INTERVAL/1000)) * 100);
        var col = secs <= 10 ? 'rgba(255,180,0,.9)' : 'rgba(255,255,255,.18)';
        if (badge.parentElement && badge.parentElement.classList.contains('topbar-right')) badge.style.background = col;
        badge.innerHTML = '↻ ' + secs + 's';
        timer = setTimeout(tick, 1000);
    }
    // Add spin keyframe
    if (!document.getElementById('refresh-spin-style')) {
        var s = document.createElement('style');
        s.id = 'refresh-spin-style';
        s.textContent = '@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
        document.head.appendChild(s);
    }
    tick();
})();

/* ── Confirm delete ─────────────────────────────────────── */
function confirmDelete(id, url) {
    if (confirm('Delete this record?\nThis action cannot be undone.')) {
        window.location.href = url + '?delete=' + id;
    }
}
</script>

<!-- ── Live message alerts + unread badge ───────────────── -->
<script>
(function () {
    var base = (function() {
        var s = document.querySelector('link[rel="manifest"]');
        if (!s) return '';
        var href = s.getAttribute('href') || '';
        return href.replace('manifest.json', '');
    })();
    var uid = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
    var currentPage = (window.location.pathname || '').toLowerCase();
    var isMessagesPage = currentPage.indexOf('messages.php') !== -1;
    var storageKey = 'dms_last_msg_alert_id_' + uid;
    var notifiedInPage = {};
    var swReg = null;
    var vapidPublicKey = <?= json_encode($dmsPushPublicKey) ?>;

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
        return outputArray;
    }

    function subscribeForBackgroundPush() {
        if (!uid || !swReg || !('PushManager' in window) || !vapidPublicKey) return;
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        swReg.pushManager.getSubscription()
            .then(function(existing) {
                if (existing) return existing;
                return swReg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                });
            })
            .then(function(sub) {
                if (!sub) return;
                return fetch(base + 'push_subscribe.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(sub)
                });
            })
            .catch(function(){});
    }

    function updateUnreadBadges(count) {
        document.querySelectorAll('.msg-unread-badge').forEach(function(b) {
            b.textContent = count;
            b.style.display = count > 0 ? '' : 'none';
        });
    }

    function getLastAlertId() {
        var x = parseInt(localStorage.getItem(storageKey) || '0', 10);
        return isNaN(x) ? 0 : x;
    }

    function setLastAlertId(id) {
        localStorage.setItem(storageKey, String(id || 0));
    }

    function ensureToastContainer() {
        var el = document.getElementById('dmsMsgToastWrap');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'dmsMsgToastWrap';
        el.className = 'toast-container position-fixed top-0 end-0 p-3';
        el.style.zIndex = '1080';
        document.body.appendChild(el);
        return el;
    }

    function showToast(alert) {
        var wrap = ensureToastContainer();
        var toast = document.createElement('div');
        toast.className = 'toast align-items-center text-bg-dark border-0';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        var detail = (alert.subject || '(No Subject)') + ' - ' + (alert.body_preview || '');
        var readUrl = base + 'messages.php?action=read&id=' + encodeURIComponent(alert.id);
        toast.innerHTML =
            '<div class="d-flex">' +
                '<div class="toast-body">' +
                    '<strong>New message from ' + (alert.sender_name || 'User') + '</strong><br>' +
                    '<span style="font-size:.82rem">' + detail + '</span>' +
                '</div>' +
                '<a href="' + readUrl + '" class="btn btn-sm btn-outline-light me-2 my-auto">Open</a>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div>';
        wrap.appendChild(toast);
        var t = bootstrap.Toast.getOrCreateInstance(toast, { delay: 8000 });
        t.show();
        toast.addEventListener('hidden.bs.toast', function() { toast.remove(); });
    }

    function showMessageFlash(alert, unreadCount) {
        if (isMessagesPage) return;
        var old = document.getElementById('dmsMessageFlash');
        if (old) old.remove();

        var readUrl = alert && alert.id
            ? base + 'messages.php?action=read&id=' + encodeURIComponent(alert.id)
            : base + 'messages.php?action=inbox';

        var flash = document.createElement('a');
        flash.id = 'dmsMessageFlash';
        flash.href = readUrl;
        flash.style.cssText = [
            'position:fixed',
            'left:50%',
            'top:74px',
            'transform:translateX(-50%)',
            'z-index:3000',
            'display:flex',
            'align-items:center',
            'gap:12px',
            'max-width:min(92vw,520px)',
            'padding:14px 18px',
            'border-radius:14px',
            'background:linear-gradient(135deg,#1E3A8A,#2563EB)',
            'color:#fff',
            'text-decoration:none',
            'box-shadow:0 16px 40px rgba(30,58,138,.45)',
            'border:1px solid rgba(255,255,255,.28)',
            'font-weight:700',
            'letter-spacing:.2px',
            'animation:dmsMessageFlashIn .22s ease-out'
        ].join(';');

        var icon = document.createElement('span');
        icon.className = 'bi bi-chat-dots-fill';
        icon.style.cssText = 'font-size:1.35rem;line-height:1';

        var body = document.createElement('span');
        body.style.cssText = 'display:flex;flex-direction:column;min-width:0';

        var title = document.createElement('span');
        title.textContent = unreadCount && unreadCount > 1 ? 'MESSAGES RECEIVED' : 'MESSAGE RECEIVED';
        title.style.cssText = 'font-size:.92rem;line-height:1.1';

        var detail = document.createElement('span');
        detail.style.cssText = 'font-size:.78rem;font-weight:500;opacity:.9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
        if (alert && alert.sender_name) {
            detail.textContent = 'From ' + alert.sender_name + (alert.subject ? ': ' + alert.subject : '');
        } else {
            detail.textContent = unreadCount + ' unread message' + (unreadCount === 1 ? '' : 's') + ' in inbox';
        }

        body.appendChild(title);
        body.appendChild(detail);
        flash.appendChild(icon);
        flash.appendChild(body);
        document.body.appendChild(flash);

        if (!document.getElementById('dmsMessageFlashStyle')) {
            var st = document.createElement('style');
            st.id = 'dmsMessageFlashStyle';
            st.textContent = '@keyframes dmsMessageFlashIn{from{opacity:0;transform:translate(-50%,-10px)}to{opacity:1;transform:translate(-50%,0)}}';
            document.head.appendChild(st);
        }

        setTimeout(function() {
            if (!flash.parentNode) return;
            flash.style.transition = 'opacity .25s ease, transform .25s ease';
            flash.style.opacity = '0';
            flash.style.transform = 'translate(-50%,-10px)';
            setTimeout(function() { if (flash.parentNode) flash.remove(); }, 280);
        }, 6500);
    }

    function playAlertSound() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.0001;
            osc.connect(gain);
            gain.connect(ctx.destination);
            var now = ctx.currentTime;
            gain.gain.exponentialRampToValueAtTime(0.12, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.25);
            osc.start(now);
            osc.stop(now + 0.25);
            setTimeout(function() { if (ctx && ctx.close) ctx.close(); }, 500);
        } catch (e) {}
    }

    function requestNotificationPermissionOnce() {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(function() {
                subscribeForBackgroundPush();
            }).catch(function(){});
        }
        if (Notification.permission === 'granted') subscribeForBackgroundPush();
    }

    function showDesktopNotification(alert) {
        if (!('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        var readUrl = base + 'messages.php?action=read&id=' + encodeURIComponent(alert.id);
        var title = 'New message from ' + (alert.sender_name || 'User');
        var body = (alert.subject || '(No Subject)') + ' - ' + (alert.body_preview || '');
        var options = {
            body: body,
            icon: base + 'assets/icons/icon-192x192.png',
            badge: base + 'assets/icons/icon-96x96.png',
            tag: 'dms-msg-' + alert.id,
            data: { url: readUrl }
        };

        if (swReg && typeof swReg.showNotification === 'function') {
            swReg.showNotification(title, options).catch(function(){});
            return;
        }
        try {
            var n = new Notification(title, options);
            n.onclick = function() {
                window.focus();
                window.location.href = readUrl;
            };
        } catch (e) {}
    }

    function handleAlertResponse(data) {
        if (!data || !data.ok) return;
        updateUnreadBadges(data.unread || 0);

        var alerts = data.alerts || [];
        if (!alerts.length) {
            var openFlashKey = 'dms_open_unread_flash_' + uid;
            if (!isMessagesPage && (data.unread || 0) > 0 && !sessionStorage.getItem(openFlashKey)) {
                sessionStorage.setItem(openFlashKey, '1');
                showMessageFlash(null, data.unread || 0);
            }
            if ((data.max_unread_id || 0) > getLastAlertId()) setLastAlertId(data.max_unread_id || 0);
            return;
        }

        var lastId = getLastAlertId();
        alerts.forEach(function(a) {
            var msgId = parseInt(a.id || 0, 10);
            if (!msgId) return;
            if (notifiedInPage[msgId]) {
                if (msgId > lastId) lastId = msgId;
                return;
            }
            notifiedInPage[msgId] = true;

            if (msgId > lastId) {
                showMessageFlash(a, data.unread || 1);
                if (!isMessagesPage) showToast(a);
                playAlertSound();
                showDesktopNotification(a);
                lastId = msgId;
            }
        });
        setLastAlertId(lastId);
    }

    function pollMessageAlerts() {
        var lastId = getLastAlertId();
        fetch(base + 'get_message_alerts.php?last_id=' + encodeURIComponent(lastId) + '&_=' + Date.now(), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(handleAlertResponse)
            .catch(function(){});
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register(base + 'dms-sw.js', { scope: base })
            .then(function(reg){ swReg = reg; subscribeForBackgroundPush(); })
            .catch(function(){});
    }

    ['click', 'touchstart', 'keydown'].forEach(function(evt) {
        window.addEventListener(evt, requestNotificationPermissionOnce, { once: true });
    });

    pollMessageAlerts();
    setInterval(pollMessageAlerts, 12000);
})();

/* ── Universal Smart Back / Cancel Navigation ── */
(function() {
    document.addEventListener('click', function(e) {
        var target = e.target.closest('a, button');
        if (!target) return;

        // Skip main sidebar navigation, navbar items, modals, dropdown toggles, or explicitly opted-out elements
        if (target.closest('.sidebar, .sidebar-nav, .nav-sidebar, .navbar-nav, .nav-item, #sidebar')
            || target.hasAttribute('data-bs-dismiss') 
            || target.hasAttribute('data-bs-toggle') 
            || target.hasAttribute('data-no-smart-back')) return;

        var text = (target.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        
        // Never intercept menu items like "Backup & Restore"
        if (text.indexOf('backup') !== -1) return;

        var isBackBtn = text === 'back' || text === 'cancel' || text === '← back' || text === 'back to list'
            || text === 'cancel & back' || text === 'go back' || text === 'back to dashboard' || text === 'back to trips'
            || target.classList.contains('btn-back') || target.classList.contains('smart-back');

        // Only handle links or non-submit buttons that serve as Back/Cancel
        if (!isBackBtn) return;
        if (target.tagName === 'BUTTON' && target.getAttribute('type') === 'submit') return;

        // If user came from a previous page on the same domain, go back in history to preserve filters & pagination
        if (window.history.length > 1 && document.referrer) {
            try {
                var refUrl = new URL(document.referrer, window.location.origin);
                if (refUrl.origin === window.location.origin && refUrl.href !== window.location.href) {
                    e.preventDefault();
                    window.history.back();
                    return;
                }
            } catch (err) {}
        }
        // Otherwise allow default href to navigate as fallback
    }, false);
})();
</script>
</body>
</html>


