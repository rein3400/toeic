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

function toeicEncodeAssetPath($path) {
    $segments = array_values(array_filter(explode('/', str_replace('\\', '/', (string)$path)), 'strlen'));
    if (empty($segments)) {
        return '';
    }
    return implode('/', array_map('rawurlencode', $segments));
}

function toeicUniqueAssetValues(array $values) {
    $unique = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '' || in_array($value, $unique, true)) {
            continue;
        }
        $unique[] = $value;
    }
    return $unique;
}

function toeicAssetDirectory($kind) {
    return strtolower((string)$kind) === 'audio' ? 'uploads/toeic_audio' : 'uploads/toeic_photos';
}

function toeicAssetPathCandidates($filePath, $kind) {
    $value = trim((string)$filePath);
    if ($value === '') {
        return [];
    }

    $normalized = str_replace('\\', '/', $value);
    if (preg_match('#^https?://#i', $normalized)) {
        $paths = [$normalized];
        $parsedPath = parse_url($normalized, PHP_URL_PATH);
        $basename = basename((string)$parsedPath);
        if ($basename !== '' && $basename !== '/' && $basename !== '.') {
            $paths[] = $basename;
        }
        return toeicUniqueAssetValues($paths);
    }
    $normalized = preg_replace('#/+#', '/', $normalized);

    $normalized = ltrim($normalized, '/');
    $directory = toeicAssetDirectory($kind);
    $candidates = [$normalized];

    $directoryPrefix = $directory . '/';
    if (strpos($normalized, $directoryPrefix) === 0) {
        $candidates[] = substr($normalized, strlen($directoryPrefix));
    } else {
        $directoryPos = strpos($normalized, '/' . $directoryPrefix);
        if ($directoryPos !== false) {
            $candidates[] = substr($normalized, $directoryPos + strlen($directoryPrefix) + 1);
        }
    }

    $basename = basename($normalized);
    if ($basename !== '' && $basename !== $normalized) {
        $candidates[] = $basename;
    }

    return toeicUniqueAssetValues($candidates);
}

function toeicAssetRemoteUrlCandidates($filePath, $kind) {
    $paths = toeicAssetPathCandidates($filePath, $kind);
    if (empty($paths)) {
        return [];
    }

    if (preg_match('#^https?://#i', $paths[0])) {
        return [$paths[0]];
    }

    if (toeicAssetDriver($kind) !== 'r2') {
        return [];
    }

    $base = toeicAssetBaseUrl($kind);
    if ($base === '') {
        return [];
    }

    $urls = [];
    foreach ($paths as $path) {
        $encodedPath = toeicEncodeAssetPath($path);
        if ($encodedPath !== '') {
            $urls[] = $base . '/' . $encodedPath;
        }
    }

    return toeicUniqueAssetValues($urls);
}

function toeicAssetLocalUrlCandidates($filePath, $kind) {
    $paths = toeicAssetPathCandidates($filePath, $kind);
    if (empty($paths)) {
        return [];
    }

    $directory = toeicAssetDirectory($kind);
    $directoryPrefix = $directory . '/';
    $urls = [];

    foreach ($paths as $path) {
        if (preg_match('#^https?://#i', $path)) {
            continue;
        }
        $path = ltrim($path, '/');
        $relativePath = strpos($path, $directoryPrefix) === 0 ? $path : $directoryPrefix . ltrim($path, '/');
        $encodedPath = toeicEncodeAssetPath($relativePath);
        if ($encodedPath !== '') {
            $urls[] = '../' . $encodedPath;
        }
    }

    return toeicUniqueAssetValues($urls);
}

function toeicAssetLocalFileCandidates($filePath, $kind) {
    $paths = toeicAssetPathCandidates($filePath, $kind);
    if (empty($paths)) {
        return [];
    }

    $directory = toeicAssetDirectory($kind);
    $directoryPrefix = $directory . '/';
    $existingUrls = [];

    foreach ($paths as $path) {
        if (preg_match('#^https?://#i', $path)) {
            continue;
        }
        $path = ltrim($path, '/');
        $relativePath = strpos($path, $directoryPrefix) === 0 ? $path : $directoryPrefix . ltrim($path, '/');
        $absolutePath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($absolutePath)) {
            continue;
        }

        $encodedPath = toeicEncodeAssetPath($relativePath);
        if ($encodedPath !== '') {
            $existingUrls[] = '../' . $encodedPath;
        }
    }

    return toeicUniqueAssetValues($existingUrls);
}

function toeicPhotoUrlCandidates($filePath) {
    $paths = toeicAssetPathCandidates($filePath, 'photo');
    if (empty($paths)) {
        return [];
    }

    $remoteCandidates = toeicAssetRemoteUrlCandidates($filePath, 'photo');
    $localCandidates = toeicAssetLocalUrlCandidates($filePath, 'photo');
    $existingLocalCandidates = toeicAssetLocalFileCandidates($filePath, 'photo');

    if (!empty($existingLocalCandidates)) {
        return toeicUniqueAssetValues(array_merge($existingLocalCandidates, $remoteCandidates, $localCandidates));
    }

    if (toeicAssetDriver('photo') === 'r2') {
        return toeicUniqueAssetValues(array_merge($remoteCandidates, $localCandidates));
    }

    return toeicUniqueAssetValues(array_merge($localCandidates, $remoteCandidates));
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
    $candidates = toeicPhotoUrlCandidates($filePath);
    return $candidates[0] ?? '';
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
