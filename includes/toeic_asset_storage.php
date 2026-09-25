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
            if (strtolower((string)$kind) === 'photo') {
                foreach (toeicPhotoExtensionVariants($basename) as $variant) {
                    $paths[] = $variant;
                }
            }
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

    if (strtolower((string)$kind) === 'photo') {
        $withVariants = $candidates;
        foreach ($candidates as $candidate) {
            if (preg_match('#^https?://#i', $candidate)) {
                continue;
            }

            foreach (toeicPhotoExtensionVariants($candidate) as $variant) {
                $withVariants[] = $variant;
            }
        }
        $candidates = $withVariants;
    }

    return toeicUniqueAssetValues($candidates);
}

function toeicPhotoExtensionVariants($path) {
    $extension = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return [(string)$path];
    }

    $stem = substr((string)$path, 0, -(strlen($extension) + 1));
    $variants = [(string)$path];
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $variantExtension) {
        if ($variantExtension !== $extension) {
            $variants[] = $stem . '.' . $variantExtension;
        }
    }

    return $variants;
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

function toeicAssetLocalPathCandidates($filePath, $kind) {
    $paths = toeicAssetPathCandidates($filePath, $kind);
    if (empty($paths)) {
        return [];
    }

    $directory = toeicAssetDirectory($kind);
    $directoryPrefix = $directory . '/';
    $localPaths = [];

    foreach ($paths as $path) {
        if (preg_match('#^https?://#i', $path)) {
            continue;
        }

        $path = ltrim($path, '/');
        $relativePath = strpos($path, $directoryPrefix) === 0 ? $path : $directoryPrefix . ltrim($path, '/');
        $localPaths[] = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    return toeicUniqueAssetValues($localPaths);
}

function toeicAssetExistingLocalPathCandidates($filePath, $kind) {
    $existingPaths = [];
    foreach (toeicAssetLocalPathCandidates($filePath, $kind) as $path) {
        if (is_file($path)) {
            $existingPaths[] = $path;
        }
    }

    return toeicUniqueAssetValues($existingPaths);
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
    $paths = toeicAssetPathCandidates($filePath, 'audio');
    if (empty($paths)) {
        return '';
    }

    $existingLocalCandidates = toeicAssetLocalFileCandidates($filePath, 'audio');
    if (!empty($existingLocalCandidates)) {
        return $existingLocalCandidates[0];
    }

    if (toeicAssetDriver('audio') === 'r2') {
        $remoteCandidates = toeicAssetRemoteUrlCandidates($filePath, 'audio');
        if (!empty($remoteCandidates)) {
            return $remoteCandidates[0];
        }
    }

    $localCandidates = toeicAssetLocalUrlCandidates($filePath, 'audio');
    if (!empty($localCandidates)) {
        return $localCandidates[count($localCandidates) - 1];
    }

    $remoteCandidates = toeicAssetRemoteUrlCandidates($filePath, 'audio');
    return $remoteCandidates[0] ?? '';
}

function toeicAudioSource($filePath) {
    $paths = toeicAssetPathCandidates($filePath, 'audio');
    if (empty($paths)) {
        return ['mode' => 'missing'];
    }

    $existingLocalPaths = toeicAssetExistingLocalPathCandidates($filePath, 'audio');
    if (!empty($existingLocalPaths)) {
        return ['mode' => 'local', 'path' => $existingLocalPaths[0]];
    }

    if (preg_match('#^https?://#i', $paths[0])) {
        return ['mode' => 'remote', 'url' => $paths[0]];
    }

    if (toeicAssetDriver('audio') === 'r2') {
        $remoteCandidates = toeicAssetRemoteUrlCandidates($filePath, 'audio');
        if (!empty($remoteCandidates)) {
            return ['mode' => 'remote', 'url' => $remoteCandidates[0]];
        }
    }

    $localPaths = toeicAssetLocalPathCandidates($filePath, 'audio');
    if (!empty($localPaths)) {
        return ['mode' => 'local', 'path' => $localPaths[count($localPaths) - 1]];
    }

    return ['mode' => 'missing'];
}

function toeicStreamRemoteFile($url, $localFallbackPath = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'audio/mpeg';
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    $body = $raw !== false ? substr($raw, $headerSize) : '';
    $isHtml = stripos($contentType, 'text/html') !== false || stripos($contentType, 'text/plain') !== false;

    if ($raw === false || $status >= 400 || $isHtml) {
        if ($localFallbackPath && is_file($localFallbackPath)) {
            $fSize = filesize($localFallbackPath);
            $fMime = mime_content_type($localFallbackPath) ?: 'audio/mpeg';
            header("Content-Type: $fMime");
            header("Content-Length: $fSize");
            header("Accept-Ranges: bytes");
            header("Cache-Control: no-cache, no-store, must-revalidate");
            readfile($localFallbackPath);
            exit();
        }
        header("HTTP/1.1 404 Not Found");
        die($error ?: "Remote audio fetch failed");
    }

    header("Content-Type: $contentType");
    header("Content-Length: " . strlen($body));
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    echo $body;
}

// PHOTO HEALTH GATE (Part 1 selection + render guard)
// ============================================================
//
// toeicPhotoIsUsable(string $filePath): bool is the frozen integration API.
// The parent builder calls it only for candidate Part 1 photo rows.
//
// Decision rules (fail closed):
// - Absolute http(s) URL input: fail-closed safety screen FIRST (scheme must
//   be https; URLs with credentials, empty hosts, localhost, single-label
//   hosts, malformed hosts, or literal non-public IPs are rejected before
//   any fetch, DNS lookup, or local matching, so a dangerous URL can never
//   be laundered through a coincidental local basename). A URL that passes
//   the screen then reuses the same legitimate local fallback candidates as
//   the renderer (toeicAssetExistingLocalPathCandidates with extension
//   variants, so an absolute legacy URL whose basename matches an existing
//   decodable local photo counts) and returns usable with NO remote fetch.
//   Only when no local candidate decodes is the exact URL validated remotely.
//   Guessed extension variants are never probed remotely (no guessed asset
//   matches; query string is preserved as-is).
// - Bare/relative input: only existing local candidates (resolved through
//   toeicAssetLocalPathCandidates, extension variants included) count, and a
//   candidate counts only when its bytes decode as an image. Bare names are
//   NEVER fetched remotely, so one bad row cannot trigger a fan-out of
//   remote guesses.
// - Remote fetch: hostname is DNS-resolved and EVERY answer must be a public
//   IP (private/reserved/loopback/link-local answers reject the URL before
//   any fetch, including DNS rebinding and split-horizon DNS); the actual
//   HTTPS connection is pinned to the validated IPs via CURLOPT_RESOLVE so
//   the bytes come from the address that was vetted. No redirects (any 3xx
//   is unusable), TLS verification kept, timeout
//   TOEIC_PHOTO_PROBE_TIMEOUT_SEC, body capped at
//   TOEIC_PHOTO_PROBE_MAX_BYTES, HTTP 200 required, present non-image
//   content-type rejected, bytes must decode via getimagesizefromstring.
//   Missing DNS answers reject before transport; no unpinned fallback.
// - Health verdicts for remote URLs persist in a repo-local JSON cache so a
//   60-row Part 1 pool is not re-fetched on every request. The cache stores
//   only sha256(URL) -> {ok, at}; no URLs, credentials, or image bytes.
//   DNS safety is re-checked on EVERY call before the cache is consulted, so
//   a cached positive is never honored once the host resolves private.
// - Local verdicts are memoized per request only (files can be uploaded or
//   repaired at any time, so they are never persisted).
//
// Limits (override only by editing the constants below):
// - TOEIC_PHOTO_PROBE_TIMEOUT_SEC = 3 (connect + total, each)
// - TOEIC_PHOTO_PROBE_MAX_BYTES = 8 MiB
// - Positive cache TTL = 300s, negative cache TTL = 60s
// - Cache dir default: <repo>/storage/tmp/toeic_photo_health
//
// Test hooks: toeicPhotoSetRemoteProbe() swaps ONLY the byte transport and
// toeicPhotoSetDnsResolver() swaps ONLY hostname resolution, so tests are
// deterministic offline. URL safety checks, DNS validation, cache policy,
// and decode validation still run. Neither hook may be used at runtime to
// bypass validation.
//
// Known limitations (see lane report):
// - Public IPv4 DNS uses a pinned, TLS-verified Cloudflare DoH request,
//   bounded to 2 seconds and memoized per host/request. Outages and IPv6-only
//   names fail closed; local files do not depend on this service.
// - Non-standard ports on public hosts are allowed (still DNS-vetted and
//   pinned to host:port).
// - SVG never decodes via getimagesize, so SVG photos report unusable.

const TOEIC_PHOTO_PROBE_TIMEOUT_SEC = 3;
const TOEIC_PHOTO_PROBE_MAX_BYTES = 8388608;
const TOEIC_PHOTO_HEALTH_POSITIVE_TTL_SEC = 300;
const TOEIC_PHOTO_HEALTH_NEGATIVE_TTL_SEC = 60;

/**
 * Repo-local directory holding the remote photo health cache.
 */
function toeicPhotoHealthCacheDir() {
    if (!empty($GLOBALS['toeic_photo_health_cache_dir'])) {
        return (string)$GLOBALS['toeic_photo_health_cache_dir'];
    }
    $env = getenv('TOEIC_PHOTO_HEALTH_CACHE_DIR');
    if ($env !== false && trim((string)$env) !== '') {
        return rtrim(trim((string)$env), '/\\');
    }
    return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'toeic_photo_health';
}

/**
 * Override the health cache directory (tests use an isolated temp dir).
 * Clears the per-request memo so stale verdicts cannot leak across dirs.
 */
function toeicPhotoSetHealthCacheDir($dir) {
    $GLOBALS['toeic_photo_health_cache_dir'] = $dir === null ? null : (string)$dir;
    $GLOBALS['toeic_photo_health_memo'] = [];
}

/**
 * Inject a low-level remote transport for deterministic tests.
 * The callable receives (string $url) and must return
 * ['status' => int, 'content_type' => string, 'body' => string].
 * Safety checks, caching, and decode validation still apply.
 */
function toeicPhotoSetRemoteProbe($probe) {
    $GLOBALS['toeic_photo_remote_probe'] = $probe;
    $GLOBALS['toeic_photo_health_memo'] = [];
}

/**
 * Frozen API: is this candidate Part 1 photo actually usable?
 */
function toeicPhotoIsUsable(string $filePath): bool {
    if (!isset($GLOBALS['toeic_photo_health_memo']) || !is_array($GLOBALS['toeic_photo_health_memo'])) {
        $GLOBALS['toeic_photo_health_memo'] = [];
    }
    $key = trim($filePath);
    if ($key === '') {
        return false;
    }
    if (array_key_exists($key, $GLOBALS['toeic_photo_health_memo'])) {
        return $GLOBALS['toeic_photo_health_memo'][$key];
    }
    if (toeicPhotoHasBlockedScheme($key)) {
        $result = false;
    } elseif (preg_match('#^https?://#i', $key)) {
        $result = toeicPhotoAbsoluteUrlUsable($key);
    } else {
        $result = toeicPhotoLocalUsable($key);
    }
    $GLOBALS['toeic_photo_health_memo'][$key] = $result;
    return $result;
}

/**
 * Reject non-HTTP(S) absolute URIs (ftp://, file://, ...) and dangerous
 * schemes (data:, javascript:, ...) before local basename resolution can
 * turn them into coincidental local matches. Protocol-relative URLs
 * (//host/...) are rejected as well: no scheme means no safe fetch.
 */
function toeicPhotoHasBlockedScheme($value) {
    $value = trim((string)$value);
    if (strpos($value, '//') === 0) {
        return true;
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $value)) {
        return !preg_match('#^https?://#i', $value);
    }
    if (preg_match('#^(data|file|php|phar|expect|javascript|vbscript):#i', $value)) {
        return true;
    }
    return false;
}

/**
 * Inject hostname resolution for deterministic tests.
 * The callable receives (string $host) and must return a list of IP
 * strings, or false/null when the host has no answers. Every returned
 * address is still validated as public; injection cannot whitelist.
 * Test-only: never a runtime bypass.
 */
function toeicPhotoSetDnsResolver($resolver) {
    $GLOBALS['toeic_photo_dns_resolver'] = $resolver;
    $GLOBALS['toeic_photo_dns_memo'] = [];
    $GLOBALS['toeic_photo_health_memo'] = [];
}

/**
 * Bounded, pinned public DNS-over-HTTPS lookup. No OS-resolver fallback.
 * Only public IPv4-capable names can be fetched; missing answers fail closed.
 */
function toeicPhotoDefaultDnsResolve($host) {
    $host = strtolower(rtrim((string)$host, '.'));
    if (!isset($GLOBALS['toeic_photo_dns_memo'])) { $GLOBALS['toeic_photo_dns_memo'] = []; }
    $memo = &$GLOBALS['toeic_photo_dns_memo'];
    if (array_key_exists($host, $memo)) { return $memo[$host]; }
    $memo[$host] = [];
    if (!function_exists('curl_init')) { return []; }
    $body = '';
    $handle = curl_init('https://cloudflare-dns.com/dns-query?name=' . rawurlencode($host) . '&type=A');
    if ($handle === false) { return []; }
    curl_setopt_array($handle, [
        CURLOPT_RESOLVE => ['cloudflare-dns.com:443:1.1.1.1'],
        CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 2000,
        CURLOPT_HTTPHEADER => ['Accept: application/dns-json'], CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 65536) { return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $result = json_decode($body, true);
    if ($success === false || $status !== 200 || !is_array($result) || ($result['Status'] ?? -1) !== 0) { return []; }
    foreach (($result['Answer'] ?? []) as $answer) {
        if (($answer['type'] ?? null) === 1 && is_string($answer['data'] ?? null)) {
            $memo[$host][] = $answer['data'];
        }
    }
    return $memo[$host];
}

/** Reject non-global and translation/documentation ranges beyond PHP's private-range flags. */
function toeicPhotoIsPublicIp(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
    $packed = inet_pton($ip);
    if ($packed === false) { return false; }
    if (strlen($packed) === 16 && (ord($packed[0]) & 0xe0) !== 0x20) { return false; }
    $special = ['100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24',
        '198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4',
        '2001::/23','2001:db8::/32','2002::/16','3fff::/20'];
    foreach ($special as $range) {
        [$network, $bitsText] = explode('/', $range);
        $prefix = inet_pton($network);
        if ($prefix === false || strlen($prefix) !== strlen($packed)) { continue; }
        $bits = (int)$bitsText; $bytes = intdiv($bits, 8); $remaining = $bits % 8;
        if (substr($packed, 0, $bytes) !== substr($prefix, 0, $bytes)) { continue; }
        if ($remaining === 0 || ((ord($packed[$bytes]) ^ ord($prefix[$bytes])) >> (8 - $remaining)) === 0) { return false; }
    }
    return true;
}

/**
 * Resolve a remote-photo hostname to vetted public IPs.
 *
 * Returns false when the host is dangerous (any answer is a non-public IP,
 * or the host value itself is unusable): the caller must reject without any
 * fetch. Missing/invalid answers also return false. Otherwise returns the
 * vetted public IP list the actual connection must be pinned to.
 */
function toeicPhotoResolvePublicIps($host) {
    $host = strtolower(rtrim(trim((string)$host), '.'));
    if ($host === '') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!toeicPhotoIsPublicIp($host)) {
            return false;
        }
        return [$host];
    }
    $resolver = $GLOBALS['toeic_photo_dns_resolver'] ?? null;
    if (is_callable($resolver)) {
        $answers = call_user_func($resolver, $host);
    } else {
        $answers = toeicPhotoDefaultDnsResolve($host);
    }
    if (!is_array($answers) || empty($answers)) {
        return false;
    }
    $public = [];
    foreach ($answers as $ip) {
        $ip = trim((string)$ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!toeicPhotoIsPublicIp($ip)) {
            return false;
        }
        if (!in_array($ip, $public, true)) {
            $public[] = $ip;
        }
    }
    if (empty($public)) {
        return false;
    }
    return $public;
}

/**
 * Absolute URL branch: fail-closed safety screen first (scheme, credentials,
 * host), so a dangerous URL can never be laundered through a coincidental
 * local basename match; then reuse the renderer's own local fallback
 * candidates (an absolute legacy URL whose basename matches an existing
 * decodable local photo agrees with toeicPhotoUrlCandidates, which already
 * prefers that local file), and only then validate the exact URL remotely.
 */
function toeicPhotoAbsoluteUrlUsable($url) {
    $safe = toeicPhotoSafeRemoteUrl($url);
    if ($safe === false) {
        return false;
    }
    if (toeicPhotoLocalUsable($safe)) {
        return true;
    }
    return toeicPhotoCachedRemoteUsable($safe);
}

/**
 * Bare/relative branch: an existing local candidate must decode as an image.
 */
function toeicPhotoLocalUsable($filePath) {
    foreach (toeicAssetExistingLocalPathCandidates($filePath, 'photo') as $path) {
        $info = @getimagesize($path);
        if (is_array($info) && (int)($info[0] ?? 0) > 0 && (int)($info[1] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * Fail-closed remote URL screen. Returns the trimmed URL when safe, else false.
 * No DNS resolution is performed (documented limitation).
 */
function toeicPhotoSafeRemoteUrl($url) {
    $url = trim((string)$url);
    if (!preg_match('#^https://#i', $url)) {
        return false;
    }
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if ($host === '') {
        return false;
    }
    if ($host === 'localhost' || substr($host, -10) === '.localhost') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!toeicPhotoIsPublicIp($host)) {
            return false;
        }
        return $url;
    }
    if (strpos($host, '.') === false) {
        return false;
    }
    if (!preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
        return false;
    }
    return $url;
}

function toeicPhotoHealthCacheFile() {
    $dir = toeicPhotoHealthCacheDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'photo_health.json';
}

function toeicPhotoHealthCacheRead() {
    $file = toeicPhotoHealthCacheFile();
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function toeicPhotoHealthCacheWrite(array $data) {
    @file_put_contents(toeicPhotoHealthCacheFile(), json_encode($data), LOCK_EX);
}

/**
 * Cached remote verdict. Positive entries live 300s, negative entries 60s.
 * DNS safety is re-checked on EVERY call before the cache is consulted, so
 * the cache can never launder a host that now resolves private.
 */
function toeicPhotoCachedRemoteUsable($url) {
    $parts = @parse_url((string)$url);
    $host = strtolower(rtrim((string)(is_array($parts) ? ($parts['host'] ?? '') : ''), '.'));
    $pinned = toeicPhotoResolvePublicIps($host);
    $now = time();
    if ($pinned === false) {
        $cache = toeicPhotoHealthCacheRead();
        $cache[hash('sha256', (string)$url)] = ['ok' => false, 'at' => $now];
        toeicPhotoHealthCacheWrite($cache);
        return false;
    }
    $cache = toeicPhotoHealthCacheRead();
    $cacheKey = hash('sha256', (string)$url);
    if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey]) && isset($cache[$cacheKey]['ok'], $cache[$cacheKey]['at'])) {
        $ttl = !empty($cache[$cacheKey]['ok']) ? TOEIC_PHOTO_HEALTH_POSITIVE_TTL_SEC : TOEIC_PHOTO_HEALTH_NEGATIVE_TTL_SEC;
        if (($now - (int)$cache[$cacheKey]['at']) <= $ttl) {
            return (bool)$cache[$cacheKey]['ok'];
        }
    }
    $fetched = toeicPhotoFetchRemote((string)$url, $pinned);
    $ok = toeicPhotoRemoteBodyUsable($fetched);
    $cache[$cacheKey] = ['ok' => $ok, 'at' => $now];
    toeicPhotoHealthCacheWrite($cache);
    return $ok;
}

/**
 * Byte transport: injected probe in tests, bounded pinned cURL in production.
 * The probe receives (string $url, array $pinnedIps); legacy one-argument
 * probes keep working because PHP ignores the extra argument.
 */
function toeicPhotoFetchRemote($url, array $pinnedIps = []) {
    $empty = ['status' => 0, 'content_type' => '', 'body' => ''];
    if (toeicPhotoSafeRemoteUrl($url) === false || $pinnedIps === []) { return $empty; }
    foreach ($pinnedIps as $ip) {
        if (!is_string($ip) || !toeicPhotoIsPublicIp($ip)) { return $empty; }
    }
    $probe = $GLOBALS['toeic_photo_remote_probe'] ?? null;
    if (is_callable($probe)) {
        $result = call_user_func($probe, (string)$url, $pinnedIps);
        if (!is_array($result)) {
            return ['status' => 0, 'content_type' => '', 'body' => ''];
        }
        return [
            'status' => (int)($result['status'] ?? 0),
            'content_type' => (string)($result['content_type'] ?? ''),
            'body' => (string)($result['body'] ?? ''),
        ];
    }

    if (!function_exists('curl_init')) { return $empty; }
    $body = '';
    $max = TOEIC_PHOTO_PROBE_MAX_BYTES;
    $ch = curl_init((string)$url);
    if ($ch === false) {
        return ['status' => 0, 'content_type' => '', 'body' => ''];
    }
    $options = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => 0,
        CURLOPT_CONNECTTIMEOUT => TOEIC_PHOTO_PROBE_TIMEOUT_SEC,
        CURLOPT_TIMEOUT => TOEIC_PHOTO_PROBE_TIMEOUT_SEC,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_USERAGENT => 'TOEICPhotoHealth/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/*'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, $max) {
            if ((strlen($body) + strlen($chunk)) > $max) {
                return -1;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if (!empty($pinnedIps)) {
        $parts = @parse_url((string)$url);
        $host = strtolower((string)(is_array($parts) ? ($parts['host'] ?? '') : ''));
        $port = (int)(is_array($parts) ? ($parts['port'] ?? 443) : 443);
        if ($host !== '' && $port > 0) {
            // Prefer IPv4 pins: every stack routes IPv4, while an unreachable
            // IPv6 entry can sink the whole pinned connection (verified: a
            // mixed v4+v6 CURLOPT_RESOLVE set failed where v4-only returned
            // HTTP 200). IPv6-only hosts still pin their vetted addresses.
            $v4 = [];
            foreach ($pinnedIps as $ip) {
                $ip = trim((string)$ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $v4[] = $ip;
                }
            }
            $pins = !empty($v4) ? $v4 : array_values(array_map('trim', array_map('strval', $pinnedIps)));
            $resolve = [];
            foreach ($pins as $ip) {
                if ($ip !== '') {
                    $resolve[] = $host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip);
                }
            }
            if (!empty($resolve)) {
                $options[CURLOPT_RESOLVE] = $resolve;
            }
        }
    }
    curl_setopt_array($ch, $options);
    $execOk = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($execOk === false) {
        return ['status' => 0, 'content_type' => '', 'body' => ''];
    }
    return ['status' => $status, 'content_type' => $contentType, 'body' => $body];
}

/**
 * Verdict on fetched bytes: HTTP 200 (redirects never followed), no
 * non-image content-type, and bytes must decode as an image.
 */
function toeicPhotoRemoteBodyUsable(array $fetched) {
    if ((int)($fetched['status'] ?? 0) !== 200) {
        return false;
    }
    $body = (string)($fetched['body'] ?? '');
    if ($body === '') {
        return false;
    }
    $contentType = strtolower(trim((string)($fetched['content_type'] ?? '')));
    if ($contentType !== '') {
        $mime = trim(explode(';', $contentType)[0]);
        if (strpos($mime, 'image/') !== 0) {
            return false;
        }
    }
    $info = @getimagesizefromstring($body);
    return is_array($info) && (int)($info[0] ?? 0) > 0 && (int)($info[1] ?? 0) > 0;
}
