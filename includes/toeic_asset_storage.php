<?php

function toeicNormalizeAssetKey($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    return basename(str_replace('\\', '/', $value));
}

function toeicAssetDriver($kind) {
    $kind = strtolower((string)$kind);
    $specific = getenv('TOEIC_' . strtoupper($kind) . '_STORAGE_DRIVER');
    if ($specific !== false && $specific !== '') {
        return strtolower($specific);
    }
    $generic = getenv('TOEIC_STORAGE_DRIVER');
    if ($generic !== false && $generic !== '') {
        return strtolower($generic);
    }
    return 'local';
}

function toeicAssetBaseUrl($kind) {
    $kind = strtolower((string)$kind);
    $specific = getenv('R2_' . strtoupper($kind) . '_PUBLIC_BASE_URL');
    if ($specific !== false && trim($specific) !== '') {
        return rtrim(trim($specific), '/');
    }
    $generic = getenv('R2_PUBLIC_BASE_URL');
    if ($generic !== false && trim($generic) !== '') {
        return rtrim(trim($generic), '/');
    }
    return '';
}

function toeicPhotoUrl($filePath) {
    $key = toeicNormalizeAssetKey($filePath);
    if ($key === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $key)) {
        return $key;
    }
    if (toeicAssetDriver('photo') === 'r2') {
        $base = toeicAssetBaseUrl('photo');
        if ($base !== '') {
            return $base . '/' . rawurlencode($key);
        }
    }
    return '../uploads/toeic_photos/' . rawurlencode($key);
}

function toeicAudioUrl($filePath) {
    $key = toeicNormalizeAssetKey($filePath);
    if ($key === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $key)) {
        return $key;
    }
    if (toeicAssetDriver('audio') === 'r2') {
        $base = toeicAssetBaseUrl('audio');
        if ($base !== '') {
            return $base . '/' . rawurlencode($key);
        }
    }
    return '../uploads/toeic_audio/' . rawurlencode($key);
}

function toeicAudioSource($filePath) {
    $key = toeicNormalizeAssetKey($filePath);
    if ($key === '') {
        return ['mode' => 'missing'];
    }
    if (preg_match('#^https?://#i', $key)) {
        return ['mode' => 'remote', 'url' => $key];
    }
    if (toeicAssetDriver('audio') === 'r2') {
        $base = toeicAssetBaseUrl('audio');
        if ($base !== '') {
            return ['mode' => 'remote', 'url' => $base . '/' . rawurlencode($key)];
        }
    }
    return ['mode' => 'local', 'path' => __DIR__ . '/../uploads/toeic_audio/' . $key];
}

function toeicStreamRemoteFile($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'audio/mpeg';
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status >= 400) {
        header("HTTP/1.1 404 Not Found");
        die($error ?: "Remote audio fetch failed");
    }

    $body = substr($raw, $headerSize);
    header("Content-Type: $contentType");
    header("Content-Length: " . strlen($body));
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    echo $body;
}
