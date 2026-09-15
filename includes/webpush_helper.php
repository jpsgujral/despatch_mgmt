<?php

if (!function_exists('dmsB64UrlEncode')) {
function dmsB64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
}

if (!function_exists('dmsB64UrlDecode')) {
function dmsB64UrlDecode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad > 0) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}
}

if (!function_exists('dmsEnsurePushTables')) {
function dmsEnsurePushTables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS dms_push_settings (
        setting_key   VARCHAR(80) PRIMARY KEY,
        setting_value TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS dms_push_subscriptions (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        user_id         INT NOT NULL,
        endpoint_hash   CHAR(64) NOT NULL UNIQUE,
        endpoint        TEXT NOT NULL,
        p256dh          VARCHAR(255) NOT NULL DEFAULT '',
        auth            VARCHAR(255) NOT NULL DEFAULT '',
        user_agent      VARCHAR(255) NOT NULL DEFAULT '',
        status          ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_notified_at DATETIME DEFAULT NULL,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
}

if (!function_exists('dmsGetPushSetting')) {
function dmsGetPushSetting(mysqli $db, string $key): string {
    $k = $db->real_escape_string($key);
    $r = $db->query("SELECT setting_value FROM dms_push_settings WHERE setting_key='$k' LIMIT 1");
    if (!$r) return '';
    $row = $r->fetch_assoc();
    return (string)($row['setting_value'] ?? '');
}
}

if (!function_exists('dmsSetPushSetting')) {
function dmsSetPushSetting(mysqli $db, string $key, string $value): void {
    $k = $db->real_escape_string($key);
    $v = $db->real_escape_string($value);
    $db->query("INSERT INTO dms_push_settings (setting_key, setting_value)
        VALUES ('$k','$v')
        ON DUPLICATE KEY UPDATE setting_value='$v'");
}
}

if (!function_exists('dmsDerToJose')) {
function dmsDerToJose(string $der, int $partLength = 32): string {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) return '';
    $len = ord($der[$offset++]);
    if ($len & 0x80) $offset += ($len & 0x7F);
    if (ord($der[$offset++]) !== 0x02) return '';
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    if (ord($der[$offset++]) !== 0x02) return '';
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, $partLength, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $partLength, "\x00", STR_PAD_LEFT);
    return $r . $s;
}
}

if (!function_exists('dmsEnsureVapidKeys')) {
function dmsEnsureVapidKeys(mysqli $db): array {
    dmsEnsurePushTables($db);

    $public = dmsGetPushSetting($db, 'vapid_public_key');
    $privatePem = dmsGetPushSetting($db, 'vapid_private_pem');
    $subject = dmsGetPushSetting($db, 'vapid_subject');
    if ($subject === '') $subject = 'mailto:admin@localhost';

    if ($public !== '' && $privatePem !== '') {
        return ['public' => $public, 'private_pem' => $privatePem, 'subject' => $subject];
    }

    if (!function_exists('openssl_pkey_new')) {
        return ['public' => '', 'private_pem' => '', 'subject' => $subject];
    }

    $res = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if (!$res) return ['public' => '', 'private_pem' => '', 'subject' => $subject];

    $privateOut = '';
    openssl_pkey_export($res, $privateOut);
    $details = openssl_pkey_get_details($res);
    $x = $details['ec']['x'] ?? '';
    $y = $details['ec']['y'] ?? '';
    if ($x === '' || $y === '') return ['public' => '', 'private_pem' => '', 'subject' => $subject];

    $publicRaw = "\x04" . $x . $y;
    $publicB64 = dmsB64UrlEncode($publicRaw);

    dmsSetPushSetting($db, 'vapid_public_key', $publicB64);
    dmsSetPushSetting($db, 'vapid_private_pem', $privateOut);
    dmsSetPushSetting($db, 'vapid_subject', $subject);

    return ['public' => $publicB64, 'private_pem' => $privateOut, 'subject' => $subject];
}
}

if (!function_exists('dmsWebPushJwt')) {
function dmsWebPushJwt(string $aud, string $subject, string $privatePem): string {
    $header = dmsB64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = dmsB64UrlEncode(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 60 * 60,
        'sub' => $subject,
    ]));
    $signingInput = $header . '.' . $payload;
    $sigDer = '';
    $ok = openssl_sign($signingInput, $sigDer, $privatePem, OPENSSL_ALGO_SHA256);
    if (!$ok) return '';
    $sigJose = dmsDerToJose($sigDer, 32);
    if ($sigJose === '') return '';
    return $signingInput . '.' . dmsB64UrlEncode($sigJose);
}
}

if (!function_exists('dmsSendWebPushToUser')) {
function dmsSendWebPushToUser(mysqli $db, int $userId): void {
    if ($userId <= 0) return;
    $keys = dmsEnsureVapidKeys($db);
    $public = (string)($keys['public'] ?? '');
    $privatePem = (string)($keys['private_pem'] ?? '');
    $subject = (string)($keys['subject'] ?? 'mailto:admin@localhost');
    if ($public === '' || $privatePem === '') return;

    $uid = (int)$userId;
    $subs = $db->query("SELECT id, endpoint FROM dms_push_subscriptions
        WHERE user_id=$uid AND status='Active' ORDER BY id DESC");
    if (!$subs) return;

    while ($s = $subs->fetch_assoc()) {
        $sid = (int)$s['id'];
        $endpoint = (string)($s['endpoint'] ?? '');
        if ($endpoint === '') continue;

        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) continue;
        $aud = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) $aud .= ':' . (int)$parts['port'];

        $jwt = dmsWebPushJwt($aud, $subject, $privatePem);
        if ($jwt === '') continue;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'TTL: 120',
                'Urgency: high',
                'Authorization: WebPush t=' . $jwt . ', k=' . $public,
                'Content-Length: 0',
            ],
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (in_array($http, [201, 202], true)) {
            $db->query("UPDATE dms_push_subscriptions SET last_notified_at=NOW() WHERE id=$sid");
        } elseif (in_array($http, [404, 410], true)) {
            $db->query("UPDATE dms_push_subscriptions SET status='Inactive' WHERE id=$sid");
        }
    }
}
}

