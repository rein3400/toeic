<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** No real network: demonstrate that unresolved/reserved hosts cannot reach the byte transport. */
require_once dirname(__DIR__) . '/includes/toeic_asset_storage.php';
$cache = sys_get_temp_dir() . '/toeic_photo_closed_' . bin2hex(random_bytes(5));
toeicPhotoSetHealthCacheDir($cache);
$body = file_get_contents(dirname(__DIR__) . '/uploads/toeic_photos/toeic_p1_01.jpg');
$calls = 0;
$probe = static function (string $url, array $ips = []) use (&$calls, $body): array {
    $calls++;
    return ['status'=>200, 'content_type'=>'image/jpeg', 'body'=>$body];
};
$failures = [];
/** Assert explicit security outcomes, preserving all failures for one run. */
function closedCheck(bool $condition, string $name): void {
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) { $failures[] = $name; }
}
try {
    toeicPhotoSetRemoteProbe($probe);
    toeicPhotoSetDnsResolver(static fn(string $host): array => ['93.184.216.34']);
    closedCheck(toeicPhotoIsUsable('https://cdn.example.com/closed-positive.jpg'), 'positive control reaches valid public pinned transport');
    closedCheck($calls === 1, 'positive control performs one fetch');
    toeicPhotoSetDnsResolver(static fn(string $host): array => []);
    closedCheck(!toeicPhotoIsUsable('https://cdn.example.com/closed-empty-dns.jpg') && $calls === 1, 'empty DNS rejects before transport');
    closedCheck(!toeicPhotoIsUsable('https://cdn.example.com/closed-positive.jpg') && $calls === 1, 'empty DNS cannot reuse a positive persistent verdict');
    toeicPhotoSetDnsResolver(static fn(string $host): array => ['100.100.100.200']);
    $before = $calls;
    closedCheck(!toeicPhotoIsUsable('https://cdn.example.com/closed-cgn.jpg') && $calls === $before, 'carrier-grade NAT metadata address rejected');
    toeicPhotoSetDnsResolver(static fn(string $host): array => ['93.184.216.34']);
    $before = $calls;
    closedCheck(!toeicPhotoIsUsable('https://@cdn.example.com/closed-userinfo.jpg') && $calls === $before, 'even empty userinfo is rejected');
    $before = $calls;
    closedCheck(toeicPhotoFetchRemote('https://cdn.example.com/closed-no-pin.jpg', [])['status'] === 0 && $calls === $before, 'low-level transport cannot run unpinned');
    foreach (['0.0.0.0','100.64.0.1','192.0.0.8','192.0.2.1','198.18.0.1','198.51.100.2','203.0.113.2','224.0.0.1','240.0.0.1','::ffff:127.0.0.1','64:ff9b::7f00:1','2001:db8::1','2002:7f00:1::1'] as $ip) {
        toeicPhotoSetDnsResolver(static fn(string $host): array => [$ip]);
        $before = $calls;
        closedCheck(!toeicPhotoIsUsable('https://cdn.example.com/closed-reserved-' . rawurlencode($ip) . '.jpg') && $calls === $before, 'reserved/special address denied: ' . $ip);
    }
} finally {
    toeicPhotoSetRemoteProbe(null); toeicPhotoSetDnsResolver(null); toeicPhotoSetHealthCacheDir(null);
    foreach (glob($cache . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($cache)) { rmdir($cache); }
}
exit($failures === [] ? 0 : 1);
