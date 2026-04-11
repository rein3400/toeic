<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/toeic_helper.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable. Configure local DB credentials before running TOEIC content import precheck.\n");
    exit(1);
}

echo "=== TOEIC CONTENT IMPORT PRECHECK ===\n";

$expected_dirs = [
    'uploads/toeic_audio',
    'uploads/toeic_photos',
];

foreach ($expected_dirs as $dir) {
    $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($absolute)) {
        mkdir($absolute, 0777, true);
        echo "[CREATED] $dir\n";
    } else {
        echo "[OK] $dir\n";
    }
}

$source_candidates = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'toeic',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'toeic',
];

$found_source = null;
foreach ($source_candidates as $candidate) {
    if (is_dir($candidate)) {
        $found_source = $candidate;
        break;
    }
}

if ($found_source) {
    echo "[OK] Source content directory found: $found_source\n";
    echo "This repository still needs a dedicated TOEIC bulk import pipeline for the full audio/photo/text/question pack.\n";
    echo "Use admin TOEIC import tooling or extend includes/bulk_importer.php against this source directory.\n";
} else {
    echo "[WARN] No TOEIC source content directory found.\n";
    echo "Expected one of:\n";
    foreach ($source_candidates as $candidate) {
        echo "  - $candidate\n";
    }
}

echo PHP_EOL . "=== TOEIC READINESS SNAPSHOT ===\n";
$readiness = getTOEICContentReadiness($conn);
foreach ($readiness['parts'] as $part => $row) {
    echo "Part {$part}: {$row['actual']} / {$row['target']} (gap {$row['gap']})\n";
}

echo PHP_EOL . "Import precheck complete.\n";
