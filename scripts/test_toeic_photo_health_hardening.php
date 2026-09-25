<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Hardening: TOEIC Part 1 photo-health gate.
 *
 * Covers the gaps left by the first media-lane pass:
 *  1. Renderer agreement: an absolute legacy URL whose basename (+ extension
 *     variants) matches an existing decodable local photo is usable WITHOUT
 *     any remote fetch (toeicPhotoUrlCandidates() already prefers that local
 *     file, so eligibility must agree with the renderer).
 *  2. DNS-to-private + DNS rebinding: hostnames resolving to private/
 *     reserved/loopback/link-local addresses are rejected before any fetch,
 *     and the actual HTTPS connection is pinned (CURLOPT_RESOLVE) to the
 *     validated public IPs. Verified via safe injected resolver/transport
 *     probes -- no private endpoint is ever really fetched.
 *  3. Cache cannot bypass safety: a cached positive verdict is not honored
 *     once the hostname resolves private.
 *  4. Frozen surface preserved: toeicPhotoIsUsable(string):bool signature
 *     and existing audio helpers untouched.
 *
 * Run: C:/xampp/php/php.exe scripts/test_toeic_photo_health_hardening.php
 * No DB, no network, no credentials. Isolated temp cache dir only.
 */

require_once __DIR__ . '/../includes/toeic_asset_storage.php';

$hard_pass = 0;
$hard_fail = [];

function hard_check(bool $condition, string $label, string $detail = ''): void {
    global $hard_pass, $hard_fail;
    if ($condition) {
        $hard_pass++;
        echo "[PASS] {$label}\n";
    } else {
        $hard_fail[] = $label . ($detail !== '' ? " :: {$detail}" : '');
        fwrite(STDERR, "[FAIL] {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n");
    }
}

$hard_probe_calls = [];
$hard_probe_ips = [];
$hard_probe_handler = null;
$hard_dns_calls = [];
$hard_dns_handler = null;

function hard_probe_stub(string $url, array $ips = []): array {
    global $hard_probe_calls, $hard_probe_ips, $hard_probe_handler;
    $hard_probe_calls[] = $url;
    $hard_probe_ips[] = $ips;
    if (is_callable($hard_probe_handler)) {
        return call_user_func($hard_probe_handler, $url, $ips);
    }
    return ['status' => 0, 'content_type' => '', 'body' => ''];
}

function hard_dns_stub(string $host) {
    global $hard_dns_calls, $hard_dns_handler;
    $hard_dns_calls[] = strtolower($host);
    if (is_callable($hard_dns_handler)) {
        return call_user_func($hard_dns_handler, $host);
    }
    return false;
}

$hard_cache_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toeic_photo_hardening_' . getmypid();
@mkdir($hard_cache_dir, 0777, true);
toeicPhotoSetHealthCacheDir($hard_cache_dir);

$hard_png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

// --- frozen public API shape (must never drift) ---
$ref = new ReflectionFunction('toeicPhotoIsUsable');
$params = $ref->getParameters();
hard_check(count($params) === 1, 'toeicPhotoIsUsable takes exactly one parameter');
hard_check((string)$params[0]->getType() === 'string', 'toeicPhotoIsUsable parameter stays string');
hard_check((string)$ref->getReturnType() === 'bool', 'toeicPhotoIsUsable still returns bool');
hard_check(function_exists('toeicAudioUrl') && function_exists('toeicAudioSource'), 'audio helpers still exist');

// --- audio behavior preserved (real local fixtures, no mocks) ---
hard_check(toeicAudioSource('')['mode'] === 'missing', 'audio: empty path still missing');
$audioSrc = toeicAudioSource('toeic_p1_01.mp3');
hard_check($audioSrc['mode'] === 'local' && is_file($audioSrc['path'] ?? ''), 'audio: existing mp3 still resolves local');
hard_check(toeicAudioUrl('toeic_p1_01.mp3') !== '', 'audio: existing mp3 still yields a URL');

// --- 1. renderer agreement: absolute legacy URL + decodable local match ---
$rendered = toeicPhotoUrlCandidates('https://static.example.com/toeic_p1_03.png');
hard_check(($rendered[0] ?? null) === '../uploads/toeic_photos/toeic_p1_03.jpg', 'renderer prefers existing local variant over absolute remote URL');

$hard_probe_calls = [];
$hard_probe_handler = function (string $url, array $ips = []): array {
    return ['status' => 404, 'content_type' => 'text/html', 'body' => '<h1>gone</h1>'];
};
toeicPhotoSetRemoteProbe('hard_probe_stub');
hard_check(toeicPhotoIsUsable('https://static.example.com/toeic_p1_03.png') === true, 'absolute legacy URL with decodable local match is usable');
hard_check(count($hard_probe_calls) === 0, 'local match short-circuits: no remote fetch for absolute URL', 'calls=' . count($hard_probe_calls));

// --- 2/3. DNS safety seam + pinning + cache discipline ---
hard_check(function_exists('toeicPhotoSetDnsResolver'), 'DNS resolver seam exists for deterministic tests');
toeicPhotoSetDnsResolver('hard_dns_stub');

// DNS-to-private: hostname resolving to 10/8 is rejected, transport never runs.
$hard_probe_calls = [];
$hard_dns_calls = [];
$hard_dns_handler = function (string $host) { return ['10.0.0.5']; };
$hard_probe_handler = function (string $url, array $ips = []) use ($hard_png_1x1): array {
    return ['status' => 200, 'content_type' => 'image/png', 'body' => $hard_png_1x1];
};
toeicPhotoSetRemoteProbe('hard_probe_stub');
toeicPhotoSetDnsResolver('hard_dns_stub');
hard_check(toeicPhotoIsUsable('https://cdn.example.com/toeic/photo_priv.png') === false, 'hostname resolving to 10/8 is unusable');
hard_check(count($hard_probe_calls) === 0, 'DNS-private host never reaches the transport', 'calls=' . count($hard_probe_calls));

// Mixed public+private answers fail closed too (no partial trust in DNS).
$hard_probe_calls = [];
$hard_dns_handler = function (string $host) { return ['93.184.216.34', '192.168.1.10']; };
toeicPhotoSetDnsResolver('hard_dns_stub');
hard_check(toeicPhotoIsUsable('https://cdn.example.com/toeic/photo_mixed.png') === false, 'mixed public+private DNS answers are unusable');
hard_check(count($hard_probe_calls) === 0, 'mixed DNS answers never reach the transport', 'calls=' . count($hard_probe_calls));

// Pinning: public IPs flow to the transport so production can CURLOPT_RESOLVE them.
$hard_probe_calls = [];
$hard_probe_ips = [];
$hard_dns_handler = function (string $host) { return ['93.184.216.34']; };
toeicPhotoSetDnsResolver('hard_dns_stub');
hard_check(toeicPhotoIsUsable('https://cdn.example.com/toeic/photo_pin.png') === true, 'public hostname with decodable bytes is usable');
hard_check(count($hard_probe_calls) === 1, 'public host is fetched exactly once', 'calls=' . count($hard_probe_calls));
hard_check(($hard_probe_ips[0] ?? null) === ['93.184.216.34'], 'validated public IPs are handed to the transport for pinning');

// Credential-bearing URLs still fail before DNS or transport.
$hard_probe_calls = [];
$hard_dns_calls = [];
hard_check(toeicPhotoIsUsable('https://user:secret@cdn.example.com/toeic/photo_cred.png') === false, 'credential-bearing URL is unusable');
hard_check(count($hard_probe_calls) === 0 && count($hard_dns_calls) === 0, 'credential URL triggers neither DNS nor fetch');

// Safety screen runs BEFORE local matching: even a basename that coincides
// with an existing decodable local file must not launder a dangerous URL.
$hard_probe_calls = [];
$hard_dns_calls = [];
hard_check(toeicPhotoIsUsable('https://127.0.0.1/toeic_p1_01.jpg') === false, 'loopback URL with coincidental local basename is unusable');
hard_check(count($hard_probe_calls) === 0 && count($hard_dns_calls) === 0, 'loopback URL triggers neither DNS nor fetch');

// Cache cannot bypass safety: poison DNS after a positive verdict.
$hard_probe_calls = [];
$hard_dns_handler = function (string $host) { return ['93.184.216.34']; };
toeicPhotoSetDnsResolver('hard_dns_stub');
$rebindUrl = 'https://cdn.example.com/toeic/photo_rebind.png';
hard_check(toeicPhotoIsUsable($rebindUrl) === true, 'positive verdict is cached for rebind host');
hard_check(count($hard_probe_calls) === 1, 'first rebind check fetches once', 'calls=' . count($hard_probe_calls));
$hard_dns_handler = function (string $host) { return ['169.254.169.254']; };
toeicPhotoSetDnsResolver('hard_dns_stub');
hard_check(toeicPhotoIsUsable($rebindUrl) === false, 'cached positive is not honored once DNS resolves link-local');
hard_check(count($hard_probe_calls) === 1, 'rebound host is rejected without a refetch', 'calls=' . count($hard_probe_calls));

// --- bounded time/bytes still hold ---
hard_check(defined('TOEIC_PHOTO_PROBE_TIMEOUT_SEC') && TOEIC_PHOTO_PROBE_TIMEOUT_SEC <= 3, 'probe timeout still bounded to <=3s');
hard_check(defined('TOEIC_PHOTO_PROBE_MAX_BYTES') && TOEIC_PHOTO_PROBE_MAX_BYTES <= 8 * 1024 * 1024, 'probe body still capped at <=8MiB');
hard_check(defined('TOEIC_PHOTO_HEALTH_POSITIVE_TTL_SEC') && TOEIC_PHOTO_HEALTH_POSITIVE_TTL_SEC === 300, 'positive TTL still 300s');
hard_check(defined('TOEIC_PHOTO_HEALTH_NEGATIVE_TTL_SEC') && TOEIC_PHOTO_HEALTH_NEGATIVE_TTL_SEC === 60, 'negative TTL still 60s');

// --- cleanup: leave no globals, fixtures, or temp dirs behind ---
toeicPhotoSetRemoteProbe(null);
toeicPhotoSetDnsResolver(null);
toeicPhotoSetHealthCacheDir(null);
foreach (glob($hard_cache_dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) { @unlink($f); }
@rmdir($hard_cache_dir);

echo "photo hardening: {$hard_pass} passed, " . count($hard_fail) . " failed\n";
if (!empty($hard_fail)) {
    exit(1);
}
echo "toeic photo health hardening passed\n";
