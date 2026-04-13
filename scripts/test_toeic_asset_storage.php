<?php

require_once __DIR__ . '/../includes/toeic_asset_storage.php';

function assertSame($expected, $actual, $label)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$label}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrue($condition, $label)
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
}

putenv('TOEIC_STORAGE_DRIVER=local');
putenv('TOEIC_PHOTO_STORAGE_DRIVER=local');
putenv('R2_PUBLIC_BASE_URL');
putenv('R2_PHOTO_PUBLIC_BASE_URL');

$localCandidates = toeicPhotoUrlCandidates('uploads/toeic_photos/toeic_p1_01.png');
assertSame('../uploads/toeic_photos/toeic_p1_01.png', $localCandidates[0] ?? null, 'local path keeps uploads-based candidate first');
assertTrue(
    in_array('../uploads/toeic_photos/toeic_p1_01.png', $localCandidates, true),
    'local candidates include uploads/toeic_photos fallback'
);

putenv('TOEIC_STORAGE_DRIVER=r2');
putenv('TOEIC_PHOTO_STORAGE_DRIVER=r2');
putenv('R2_PHOTO_PUBLIC_BASE_URL=https://cdn.example.com/toeic-photo');

$remoteCandidates = toeicPhotoUrlCandidates('photos/set-a/toeic_p1_02.png');
assertSame(
    'https://cdn.example.com/toeic-photo/photos/set-a/toeic_p1_02.png',
    $remoteCandidates[0] ?? null,
    'remote path preserves nested object key for primary candidate'
);
assertTrue(
    in_array('../uploads/toeic_photos/toeic_p1_02.png', $remoteCandidates, true),
    'remote candidates still include local uploads fallback'
);

$absoluteUrl = toeicPhotoUrlCandidates('https://static.example.com/toeic_p1_03.png');
assertSame(
    ['https://static.example.com/toeic_p1_03.png'],
    $absoluteUrl,
    'absolute URLs are returned unchanged'
);

echo "toeic asset storage tests passed\n";
