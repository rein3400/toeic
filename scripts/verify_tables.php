<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/toeic_helper.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable. Configure local DB credentials before running TOEIC table verification.\n");
    exit(1);
}

echo "=== TOEIC STANDALONE TABLE CHECK ===\n";

$required_tables = [
    'site_settings',
    'user_purchases',
    'payment_transactions',
    'vouchers',
    'audio_playback_log',
    'toeic_photos',
    'toeic_audio',
    'toeic_teks',
    'toeic_soal_listening',
    'toeic_soal_reading',
    'toeic_test_sessions',
    'toeic_test_questions',
    'toeic_test_results',
    'toeic_score_conversion',
    'proctoring_settings',
    'proctoring_sessions',
    'proctoring_events',
    'proctoring_ai_logs',
];

foreach ($required_tables as $table) {
    $safeTable = $conn->real_escape_string($table);
    $existsResult = $conn->query("SHOW TABLES LIKE '$safeTable'");
    $exists = ($existsResult && $existsResult->num_rows > 0);
    echo ($exists ? '[OK] ' : '[MISSING] ') . $table . PHP_EOL;
}

echo PHP_EOL . "=== TOEIC READINESS BY PART ===\n";
if (($conn->query("SHOW TABLES LIKE 'toeic_soal_listening'")->num_rows ?? 0) > 0 && ($conn->query("SHOW TABLES LIKE 'toeic_soal_reading'")->num_rows ?? 0) > 0) {
    $targets = [
        '1' => ['target' => 6, 'table' => 'toeic_soal_listening'],
        '2' => ['target' => 25, 'table' => 'toeic_soal_listening'],
        '3' => ['target' => 39, 'table' => 'toeic_soal_listening'],
        '4' => ['target' => 30, 'table' => 'toeic_soal_listening'],
        '5' => ['target' => 30, 'table' => 'toeic_soal_reading'],
        '6' => ['target' => 16, 'table' => 'toeic_soal_reading'],
        '7' => ['target' => 54, 'table' => 'toeic_soal_reading'],
    ];
    foreach ($targets as $part => $meta) {
        $safePart = $conn->real_escape_string($part);
        $row = $conn->query("SELECT COUNT(*) AS total FROM {$meta['table']} WHERE part = '{$safePart}'")->fetch_assoc();
        $actual = (int)($row['total'] ?? 0);
        $gap = max(0, $meta['target'] - $actual);
        echo sprintf("Part %s | target=%d | actual=%d | gap=%d%s\n", $part, $meta['target'], $actual, $gap, $gap === 0 ? ' | READY' : '');
    }
} else {
    echo "Question bank tables are not ready yet.\n";
}

echo PHP_EOL . "=== TOEIC AUDIO SAMPLE ===\n";
if (($conn->query("SHOW TABLES LIKE 'toeic_audio'")->num_rows ?? 0) > 0) {
    $result = $conn->query("SELECT id_audio, judul, part, file_path FROM toeic_audio ORDER BY id_audio DESC LIMIT 5");
    while ($row = $result->fetch_assoc()) {
        echo "ID {$row['id_audio']} | Part {$row['part']} | {$row['judul']} | {$row['file_path']}\n";
    }
}

echo PHP_EOL . "=== TOEIC VARIATION CHECK ===\n";
if (($conn->query("SHOW TABLES LIKE 'toeic_soal_listening'")->num_rows ?? 0) > 0 && ($conn->query("SHOW TABLES LIKE 'toeic_audio'")->num_rows ?? 0) > 0) {
    $variation = $conn->query("
        SELECT
            COUNT(*) AS total_rows,
            COUNT(DISTINCT ta.id_photo) AS distinct_photos
        FROM toeic_soal_listening sl
        LEFT JOIN toeic_audio ta ON ta.id_audio = sl.id_audio
        WHERE sl.part = '1'
    ")->fetch_assoc();

    $part1Rows = (int)($variation['total_rows'] ?? 0);
    $distinctPhotos = (int)($variation['distinct_photos'] ?? 0);

    echo "Part 1 rows={$part1Rows} | distinct_photos={$distinctPhotos}";
    if ($part1Rows <= 6 || $distinctPhotos <= 6) {
        echo " | WARNING: full simulation Part 1 will have low variation until more photo items are added";
    }
    echo PHP_EOL;
}

echo PHP_EOL . "=== TOEIC TEXT SAMPLE ===\n";
if (($conn->query("SHOW TABLES LIKE 'toeic_teks'")->num_rows ?? 0) > 0) {
    $result = $conn->query("SELECT id_teks, judul, part FROM toeic_teks ORDER BY id_teks DESC LIMIT 5");
    while ($row = $result->fetch_assoc()) {
        echo "ID {$row['id_teks']} | Part {$row['part']} | {$row['judul']}\n";
    }
}

echo PHP_EOL . "=== TOEIC SCORE CONVERSION ===\n";
if (($conn->query("SHOW TABLES LIKE 'toeic_score_conversion'")->num_rows ?? 0) > 0) {
    $result = $conn->query("SELECT section, COUNT(*) AS total FROM toeic_score_conversion GROUP BY section ORDER BY section");
    while ($row = $result->fetch_assoc()) {
        echo "{$row['section']} => {$row['total']} rows\n";
    }
}

echo PHP_EOL . "=== TOEIC TEST SESSION SUMMARY ===\n";
if (($conn->query("SHOW TABLES LIKE 'toeic_test_sessions'")->num_rows ?? 0) > 0) {
    $result = $conn->query("SELECT status, COUNT(*) AS total FROM toeic_test_sessions GROUP BY status ORDER BY status");
    while ($row = $result->fetch_assoc()) {
        echo "{$row['status']} => {$row['total']}\n";
    }
}
