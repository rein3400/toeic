<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
require_once __DIR__ . '/../includes/toeic_sw_package_importer.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'local-media', 'package-root:', 'r2-base-url:']);
$dryRun = array_key_exists('dry-run', $options);
$packageRoot = (string)($options['package-root'] ?? (__DIR__ . '/../content/generated/toeic_sw'));
$r2BaseUrl = (string)($options['r2-base-url'] ?? (getenv('R2_PUBLIC_BASE_URL') ?: 'https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev'));
$useRemoteMedia = !array_key_exists('local-media', $options);

$importer = new ToeicSwPackageImporter($conn);
$result = $importer->import($packageRoot, $dryRun, [
    'use_remote_media' => $useRemoteMedia,
    'media_base_url' => $r2BaseUrl,
]);

echo json_encode([
    'dry_run' => $result['dry_run'],
    'remote_media' => $result['remote_media'],
    'media_base_url' => $result['media_base_url'],
    'packages' => $result['packages'],
    'validated' => $result['validated'],
    'inserted' => $result['inserted'],
    'updated' => $result['updated'],
    'skipped' => $result['skipped'],
    'audio_files' => $result['audio_files'],
    'image_files' => $result['image_files'],
    'errors' => $result['errors'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(empty($result['errors']) ? 0 : 1);
