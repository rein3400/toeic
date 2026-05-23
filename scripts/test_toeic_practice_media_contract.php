<?php
/**
 * Static contract for TOEIC LR practice media readiness.
 *
 * Part 1 Photographs must not be assigned unless the question has a usable
 * audio row and a linked photo row. Otherwise the test page renders only
 * answer choices plus the "Foto soal belum tersedia" placeholder.
 */

$root = dirname(__DIR__);
$failures = [];

function practice_media_check(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function practice_media_source(string $relativePath): string {
    global $root;
    $path = $root . '/' . $relativePath;
    return is_file($path) ? (string)file_get_contents($path) : '';
}

$builder = practice_media_source('includes/toeic_test_builder.php');
$helper = practice_media_source('includes/toeic_helper.php');

practice_media_check($builder !== '', 'includes/toeic_test_builder.php must be readable.');
practice_media_check($helper !== '', 'includes/toeic_helper.php must be readable.');

practice_media_check(
    strpos($builder, 'JOIN toeic_audio ta ON ta.id_audio = src.id_audio') !== false,
    'Individual listening selection must verify the linked toeic_audio row.'
);
practice_media_check(
    strpos($builder, 'JOIN toeic_photos tp ON tp.id_photo = ta.id_photo') !== false,
    'Part 1 selection must verify the linked toeic_photos row.'
);
practice_media_check(
    strpos($builder, "TRIM(COALESCE(ta.file_path, '')) <> ''") !== false,
    'Listening selection must reject empty audio file_path values.'
);
practice_media_check(
    strpos($builder, "TRIM(COALESCE(tp.file_path, '')) <> ''") !== false,
    'Part 1 selection must reject empty photo file_path values.'
);
practice_media_check(
    strpos($builder, 'JOIN toeic_audio ta ON ta.id_audio = src.{$groupColumn}') !== false,
    'Grouped listening selection must verify the linked toeic_audio row before choosing a group.'
);

practice_media_check(
    strpos($helper, 'JOIN toeic_audio ta ON ta.id_audio = sl.id_audio') !== false,
    'Readiness must count listening questions through the linked audio row.'
);
practice_media_check(
    strpos($helper, 'JOIN toeic_photos tp ON tp.id_photo = ta.id_photo') !== false,
    'Readiness must count Part 1 questions through the linked photo row.'
);
practice_media_check(
    strpos($helper, "TRIM(COALESCE(tp.file_path, '')) <> ''") !== false,
    'Readiness must reject Part 1 rows whose photo path is empty.'
);

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC practice media contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TOEIC practice media contract passed.\n";
