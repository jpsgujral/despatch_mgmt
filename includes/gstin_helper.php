<?php

function dmsNormalizeGstin(string $gstin): string {
    return strtoupper(preg_replace('/[^0-9A-Z]/i', '', trim($gstin)));
}

function dmsGstinField(array $data, array $keys): string {
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_scalar($data[$key]) && trim((string)$data[$key]) !== '') {
            return trim((string)$data[$key]);
        }
    }
    return '';
}

function dmsVerifyGstin(string $gstin): array {
    $gstin = dmsNormalizeGstin($gstin);
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
        return ['ok' => false, 'message' => 'Invalid GSTIN format.', 'gstin' => $gstin];
    }

    $api_key = defined('GSTINCHECK_API_KEY') ? GSTINCHECK_API_KEY : '';
    if ($api_key === '') {
        return ['ok' => false, 'message' => 'GSTINCheck API key is not configured.', 'gstin' => $gstin];
    }

    $url = 'https://sheet.gstincheck.co.in/check/' . rawurlencode($api_key) . '/' . rawurlencode($gstin);
    $body = false;
    $http_code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'message' => 'GSTIN API connection failed: ' . $err, 'gstin' => $gstin];
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 20]]);
        $body = @file_get_contents($url, false, $ctx);
        $http_code = 200;
        if ($body === false) {
            return ['ok' => false, 'message' => 'GSTIN API connection failed.', 'gstin' => $gstin];
        }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'GSTIN API returned an unreadable response.', 'gstin' => $gstin, 'raw' => $body];
    }

    $payload = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    $status = dmsGstinField($payload, ['sts', 'status', 'gstinStatus', 'taxpayer_status', 'registrationStatus']);
    $legal = dmsGstinField($payload, ['lgnm', 'legal_name', 'legalName', 'tradeNam', 'name']);
    $trade = dmsGstinField($payload, ['tradeNam', 'trade_name', 'tradeName']);
    $state = dmsGstinField($payload, ['stj', 'state', 'stateJurisdiction']);

    $success_flag = $decoded['success'] ?? $decoded['status'] ?? $decoded['valid'] ?? true;
    $ok = !in_array($success_flag, [false, 'false', 'error', 'ERROR', 0, '0'], true);

    return [
        'ok' => $ok && $http_code < 400,
        'message' => $ok ? 'GSTIN verified successfully.' : dmsGstinField($decoded, ['message', 'error']) ?: 'GSTIN verification failed.',
        'gstin' => $gstin,
        'legal_name' => $legal,
        'trade_name' => $trade,
        'status' => $status,
        'state' => $state,
        'raw' => $decoded,
    ];
}
