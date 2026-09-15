self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function(event) {
    var payload = {};
    try {
        if (event.data) payload = event.data.json();
    } catch (e) {}

    var title = payload.title || 'New message in DMS';
    var options = {
        body: payload.body || 'You have a new message.',
        icon: payload.icon || 'assets/icons/icon-192x192.png',
        badge: payload.badge || 'assets/icons/icon-96x96.png',
        tag: payload.tag || ('dms-msg-' + Date.now()),
        data: { url: (payload.url || 'messages.php?action=inbox') }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var targetUrl = 'messages.php?action=inbox';
    if (event.notification && event.notification.data && event.notification.data.url) {
        targetUrl = event.notification.data.url;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if ('focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })
    );
});
