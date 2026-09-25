<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Regression: TOEIC bank media health gate + multi-document rendering + safe photo UI.
 *
 * Slice 1 (this file, first): toeicPhotoIsUsable() local + safety behavior.
 * Run: C:/xampp/php/php.exe scripts/test_toeic_bank_media_regression.php
 */

require_once __DIR__ . '/../includes/toeic_asset_storage.php';

$toeic_media_pass = 0;
$toeic_media_fail = [];
$toeic_media_probe_calls = [];
$toeic_media_probe_handler = null;

function toeic_media_check(bool $condition, string $label, string $detail = ''): void {
    global $toeic_media_pass, $toeic_media_fail;
    if ($condition) {
        $toeic_media_pass++;
        echo "[PASS] {$label}\n";
    } else {
        $toeic_media_fail[] = $label . ($detail !== '' ? " :: {$detail}" : '');
        fwrite(STDERR, "[FAIL] {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n");
    }
}

function toeic_media_probe_stub(string $url): array {
    global $toeic_media_probe_calls, $toeic_media_probe_handler;
    $toeic_media_probe_calls[] = $url;
    if (is_callable($toeic_media_probe_handler)) {
        return call_user_func($toeic_media_probe_handler, $url);
    }
    return ['status' => 0, 'content_type' => '', 'body' => ''];
}

// Isolated cache dir so tests never touch the production health cache.
$toeic_media_cache_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toeic_photo_health_test_' . getmypid();
@mkdir($toeic_media_cache_dir, 0777, true);
toeicPhotoSetHealthCacheDir($toeic_media_cache_dir);
// Public test hostname resolution is explicit; no real DNS/network in this suite.
toeicPhotoSetDnsResolver(static fn(string $host): array => ['93.184.216.34']);
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');

// --- Slice 1: frozen public API shape ---
$ref = new ReflectionFunction('toeicPhotoIsUsable');
$params = $ref->getParameters();
toeic_media_check(count($params) === 1, 'toeicPhotoIsUsable takes exactly one parameter');
toeic_media_check((string)$params[0]->getType() === 'string', 'toeicPhotoIsUsable parameter is string');
toeic_media_check((string)$ref->getReturnType() === 'bool', 'toeicPhotoIsUsable returns bool');

// --- Slice 1: empty / missing input fails closed ---
toeic_media_check(toeicPhotoIsUsable('') === false, 'empty photo path is unusable');
toeic_media_check(toeicPhotoIsUsable('   ') === false, 'blank photo path is unusable');
toeic_media_check(toeicPhotoIsUsable('no-such-photo-xyz-12345.jpg') === false, 'nonexistent bare photo name is unusable');

// --- Slice 1: existing local decodable photo is usable ---
toeic_media_check(toeicPhotoIsUsable('toeic_p1_01.jpg') === true, 'existing local decodable photo is usable');

// --- Slice 1: existing local file with non-image bytes is unusable ---
$corruptFixture = dirname(__DIR__) . '/uploads/toeic_photos/.test_corrupt_fixture_media_regression.jpg';
@file_put_contents($corruptFixture, "this is not image data, just plain text");
toeic_media_check(toeicPhotoIsUsable('.test_corrupt_fixture_media_regression.jpg') === false, 'local non-image bytes are unusable');
@unlink($corruptFixture);

// --- Slice 1: unsafe / malformed remote input fails closed without any fetch ---
$toeic_media_probe_calls = [];
toeic_media_check(toeicPhotoIsUsable('http://cdn.example.com/toeic_p1_01.jpg') === false, 'plain http photo URL is unusable');
toeic_media_check(toeicPhotoIsUsable('ftp://cdn.example.com/toeic_p1_01.jpg') === false, 'ftp photo URL is unusable');
toeic_media_check(toeicPhotoIsUsable('data:image/png;base64,iVBORw0KGgo=') === false, 'data URL photo is unusable');
toeic_media_check(toeicPhotoIsUsable('https://user:secret@cdn.example.com/toeic_p1_01.jpg') === false, 'URL with credentials is unusable');
toeic_media_check(toeicPhotoIsUsable('https://127.0.0.1/toeic_p1_01.jpg') === false, 'loopback IP photo URL is unusable');
toeic_media_check(toeicPhotoIsUsable('https://10.0.0.5/toeic_p1_01.jpg') === false, 'private 10/8 photo URL is unusable');
toeic_media_check(toeicPhotoIsUsable('https://192.168.1.10/toeic_p1_01.jpg') === false, 'private 192.168 photo URL is unusable');
toeic_media_check(toeicPhotoIsUsable('https://localhost/toeic_p1_01.jpg') === false, 'localhost photo URL is unusable');
toeic_media_check(count($toeic_media_probe_calls) === 0, 'unsafe URLs never trigger a remote fetch', 'calls=' . count($toeic_media_probe_calls));

// --- Slice 2: bounded remote validation + cached health (injectable transport) ---
toeic_media_check(defined('TOEIC_PHOTO_PROBE_TIMEOUT_SEC') && TOEIC_PHOTO_PROBE_TIMEOUT_SEC <= 3, 'remote probe timeout is bounded to <=3s');
toeic_media_check(defined('TOEIC_PHOTO_PROBE_MAX_BYTES') && TOEIC_PHOTO_PROBE_MAX_BYTES <= 8 * 1024 * 1024, 'remote probe body is capped at <=8MiB');
toeic_media_check(defined('TOEIC_PHOTO_HEALTH_POSITIVE_TTL_SEC') && TOEIC_PHOTO_HEALTH_POSITIVE_TTL_SEC === 300, 'positive health cache TTL is 300s');
toeic_media_check(defined('TOEIC_PHOTO_HEALTH_NEGATIVE_TTL_SEC') && TOEIC_PHOTO_HEALTH_NEGATIVE_TTL_SEC === 60, 'negative health cache TTL is 60s');

$toeic_png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

$toeic_media_probe_calls = [];
$toeic_media_probe_handler = function (string $url) use ($toeic_png_1x1): array {
    return ['status' => 200, 'content_type' => 'image/png', 'body' => $toeic_png_1x1];
};
$goodUrl = 'https://cdn.example.com/toeic/photo_ok.png';
toeic_media_check(toeicPhotoIsUsable($goodUrl) === true, 'remote decodable image is usable');
toeic_media_check(count($toeic_media_probe_calls) === 1, 'remote fetch happens once', 'calls=' . count($toeic_media_probe_calls));
toeic_media_check(toeicPhotoIsUsable($goodUrl) === true, 'repeated remote check stays usable');
toeic_media_check(count($toeic_media_probe_calls) === 1, 'repeated check is served from cache, not refetched', 'calls=' . count($toeic_media_probe_calls));

$cacheRaw = @file_get_contents($toeic_media_cache_dir . DIRECTORY_SEPARATOR . 'photo_health.json');
toeic_media_check($cacheRaw !== false && $cacheRaw !== '', 'health cache file is written');
toeic_media_check(strpos((string)$cacheRaw, 'cdn.example.com') === false, 'health cache stores no plaintext URLs');
toeic_media_check(strpos((string)$cacheRaw, 'secret') === false, 'health cache stores no credentials');

// TTL expiry forces a refetch: age the positive entry, reset memo via setter, expect a second call.
$cacheData = json_decode((string)$cacheRaw, true);
$cacheKey = hash('sha256', $goodUrl);
$cacheData[$cacheKey]['at'] = time() - 301;
@file_put_contents($toeic_media_cache_dir . DIRECTORY_SEPARATOR . 'photo_health.json', json_encode($cacheData));
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
toeic_media_check(toeicPhotoIsUsable($goodUrl) === true, 'expired positive entry still validates usable');
toeic_media_check(count($toeic_media_probe_calls) === 2, 'expired entry triggers exactly one refetch', 'calls=' . count($toeic_media_probe_calls));

// Negative verdicts cache too: 404 is unusable and not refetched within 60s.
$toeic_media_probe_calls = [];
$toeic_media_probe_handler = function (string $url): array {
    return ['status' => 404, 'content_type' => 'text/html', 'body' => '<h1>not found</h1>'];
};
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
$missingUrl = 'https://cdn.example.com/toeic/photo_gone.png';
toeic_media_check(toeicPhotoIsUsable($missingUrl) === false, 'remote 404 photo is unusable');
toeic_media_check(toeicPhotoIsUsable($missingUrl) === false, 'remote 404 stays unusable');
toeic_media_check(count($toeic_media_probe_calls) === 1, 'negative verdict is cached, not refetched', 'calls=' . count($toeic_media_probe_calls));

// Redirects are never followed: 301 is unusable even with a Location header.
$toeic_media_probe_handler = function (string $url): array {
    return ['status' => 301, 'content_type' => 'image/png', 'body' => ''];
};
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
toeic_media_check(toeicPhotoIsUsable('https://cdn.example.com/toeic/photo_moved.png') === false, 'redirect response is unusable without following');

// Non-image content is rejected even with HTTP 200.
$toeic_media_probe_handler = function (string $url): array {
    return ['status' => 200, 'content_type' => 'text/html', 'body' => '<html>fake</html>'];
};
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
toeic_media_check(toeicPhotoIsUsable('https://cdn.example.com/toeic/photo_html.png') === false, 'HTML body with 200 is unusable');

// Corrupt bytes with an image content-type are rejected.
$toeic_media_probe_handler = function (string $url): array {
    return ['status' => 200, 'content_type' => 'image/jpeg', 'body' => 'not image bytes at all'];
};
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
toeic_media_check(toeicPhotoIsUsable('https://cdn.example.com/toeic/photo_corrupt.jpg') === false, 'corrupt image bytes are unusable');

// Exact-URL discipline: query string preserved, extension variants never guessed.
$toeic_media_probe_calls = [];
$toeic_media_probe_handler = function (string $url) use ($toeic_png_1x1): array {
    return ['status' => 200, 'content_type' => 'image/png', 'body' => $toeic_png_1x1];
};
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
$queryUrl = 'https://cdn.example.com/toeic/photo_q.png?Expires=123&Signature=abc';
toeic_media_check(toeicPhotoIsUsable($queryUrl) === true, 'absolute URL with query string validates');
toeic_media_check($toeic_media_probe_calls === [$queryUrl], 'only the exact URL is probed, no variant guesses');

// Bare names are never fetched remotely, even under the r2 driver.
$toeic_media_probe_calls = [];
putenv('TOEIC_STORAGE_DRIVER=r2');
putenv('TOEIC_PHOTO_STORAGE_DRIVER=r2');
putenv('R2_PHOTO_PUBLIC_BASE_URL=https://cdn.example.com/toeic-photo');
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
toeic_media_check(toeicPhotoIsUsable('no-such-remote-guess-xyz.png') === false, 'bare name with no local file is unusable under r2');
toeic_media_check(count($toeic_media_probe_calls) === 0, 'bare names never trigger remote guesses', 'calls=' . count($toeic_media_probe_calls));
putenv('TOEIC_STORAGE_DRIVER=local');
putenv('TOEIC_PHOTO_STORAGE_DRIVER=local');
putenv('R2_PHOTO_PUBLIC_BASE_URL');

// Extra safety: link-local IP and uppercase scheme handling.
toeic_media_check(toeicPhotoIsUsable('https://169.254.169.254/latest/meta-data/') === false, 'link-local IP photo URL is unusable');
toeic_media_check(count($toeic_media_probe_calls) === 0, 'link-local URL never triggers a fetch', 'calls=' . count($toeic_media_probe_calls));
$toeic_media_probe_handler = function (string $url) use ($toeic_png_1x1): array {
    return ['status' => 200, 'content_type' => 'image/png', 'body' => $toeic_png_1x1];
};
toeicPhotoSetRemoteProbe('toeic_media_probe_stub');
toeic_media_check(toeicPhotoIsUsable('HTTPS://cdn.example.com/toeic/photo_upper.png') === true, 'uppercase HTTPS scheme validates');

// --- Slice 3: multi-document reading rendering (exact production function) ---
$toeic_test_page_source = (string)@file_get_contents(__DIR__ . '/../user/test_toeic.php');
toeic_media_check($toeic_test_page_source !== '', 'user/test_toeic.php is readable');
$toeic_doc_render = '';
if (preg_match('#// <toeic-doc-render>(.*?)// </toeic-doc-render>#s', $toeic_test_page_source, $toeic_m)) {
    $toeic_doc_render = trim($toeic_m[1]);
}
toeic_media_check($toeic_doc_render !== '' && strpos($toeic_doc_render, 'function toeicRenderReadingDocuments') !== false, 'test page defines extractable toeicRenderReadingDocuments()');
if ($toeic_doc_render !== '' && !function_exists('toeicRenderReadingDocuments')) {
    eval($toeic_doc_render);
}
toeic_media_check(function_exists('toeicRenderReadingDocuments'), 'toeicRenderReadingDocuments() loads from production source');

if (function_exists('toeicRenderReadingDocuments')) {
    $three = toeicRenderReadingDocuments(['isi_teks' => 'First doc ___1___', 'isi_teks_2' => 'Second doc', 'isi_teks_3' => 'Third doc']);
    toeic_media_check(strpos($three, 'First doc') !== false && strpos($three, 'Second doc') !== false && strpos($three, 'Third doc') !== false, 'all three nonempty documents render');
    toeic_media_check(substr_count($three, 'study-card') === 3, 'three nonempty documents render three cards');
    toeic_media_check(strpos($three, '[1]') !== false, 'blank markers still convert to [n]');

    $single = toeicRenderReadingDocuments(['isi_teks' => 'Only doc', 'isi_teks_2' => '   ', 'isi_teks_3' => '']);
    toeic_media_check(substr_count($single, 'study-card') === 1 && strpos($single, 'Only doc') !== false, 'empty second/third documents render no extra cards');
    toeic_media_check(strpos($single, 'Document') === false, 'single document renders without a document label');

    $xss = toeicRenderReadingDocuments(['isi_teks' => '<script>alert(1)</script><img src=x onerror=alert(2)>']);
    toeic_media_check(strpos($xss, '<script>') === false && strpos($xss, '&lt;script&gt;') !== false, 'document HTML is escaped, no raw script tag');
    toeic_media_check(strpos($xss, '<img') === false && strpos($xss, '&lt;img') !== false, 'img tags are escaped to inert text');

    toeic_media_check(toeicRenderReadingDocuments(['isi_teks' => '', 'isi_teks_2' => '', 'isi_teks_3' => '']) === '', 'all-empty documents render blank markup');
    toeic_media_check(toeicRenderReadingDocuments([]) === '', 'missing document fields render blank markup');

    $toeic_raw_needle = "echo nl2br(preg_replace('/___(\\d+)___/', '<strong class=\"text-primary underline\">[$1]</strong>', (string)\$text['isi_teks']))";
    $toeic_known_bad = "x <?php echo nl2br(preg_replace('/___(\\d+)___/', '<strong class=\"text-primary underline\">[$1]</strong>', (string)\$text['isi_teks'])); ?> y";
    toeic_media_check(strpos($toeic_known_bad, $toeic_raw_needle) !== false, 'raw-echo needle matches the known-bad snippet (positive control)');
    toeic_media_check(strpos($toeic_raw_needle, chr(92) . 'd') !== false && strpos($toeic_raw_needle, chr(92) . chr(92) . 'd') === false, 'raw-echo needle carries exactly one regex backslash');
    toeic_media_check(strpos($toeic_test_page_source, $toeic_raw_needle) === false, 'raw unescaped isi_teks echo is gone from the test page');
    toeic_media_check(strpos($toeic_test_page_source, 'toeicRenderReadingDocuments($text)') !== false, 'test page renders reading passage through toeicRenderReadingDocuments()');
}

// --- Slice 4: safe missing-photo UI (browser guard, no charge claims) ---
toeic_media_check(strpos($toeic_test_page_source, 'toeicPhotoIsUsable(') !== false, 'test page gates Part 1 render through toeicPhotoIsUsable()');
toeic_media_check(strpos($toeic_test_page_source, 'data-photo-required') !== false, 'photo image carries a data-photo-required hook');
toeic_media_check(strpos($toeic_test_page_source, 'toeicPhotoMarkLoaded') !== false, 'loaded photos enable answers through toeicPhotoMarkLoaded()');
toeic_media_check(strpos($toeic_test_page_source, 'toeicPhotoSetAnswersEnabled') !== false, 'answer inputs toggle through toeicPhotoSetAnswersEnabled()');
toeic_media_check(strpos($toeic_test_page_source, 'toeicPhotoNotice') !== false, 'missing-photo notice element exists');
toeic_media_check(strpos($toeic_test_page_source, 'Foto soal belum tersedia') !== false, 'missing-photo placeholder text is preserved');
toeic_media_check(preg_match('/singleAnswerContainer[^>]*data-photo-required/', $toeic_test_page_source) === 1, 'single answer container exposes the photo requirement');
toeic_media_check(strpos($toeic_test_page_source, 'chk.disabled') !== false, 'answer collection respects disabled inputs');
// Existing contracts preserved on the test page.
toeic_media_check(strpos($toeic_test_page_source, 'toeicAudioUrl(') !== false, 'audio URL resolution is preserved');
toeic_media_check(strpos($toeic_test_page_source, 'SecureAudioPlayer') !== false, 'secure audio player wiring is preserved');
toeic_media_check(strpos($toeic_test_page_source, 'ajax_submit_section_toeic.php') !== false, 'section submit endpoint wiring is preserved');
toeic_media_check(strpos($toeic_test_page_source, 'proctorSDK') !== false, 'proctor wiring is preserved');

// --- summary ---
echo "media regression: {$toeic_media_pass} passed, " . count($toeic_media_fail) . " failed\n";
if (!empty($toeic_media_fail)) {
    exit(1);
}
echo "toeic bank media regression passed\n";
