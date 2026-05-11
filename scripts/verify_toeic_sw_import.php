<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
require_once __DIR__ . '/../includes/toeic_sw_helper.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

ensureToeicSwSchema($conn);

$tables = [
    'toeic_sw_read_aloud',
    'toeic_sw_describe_picture',
    'toeic_sw_respond_questions',
    'toeic_sw_respond_information',
    'toeic_sw_express_opinion',
    'toeic_sw_picture_sentence',
    'toeic_sw_written_request',
    'toeic_sw_opinion_essay',
];

$summary = [];
foreach ($tables as $table) {
    $columnResult = $conn->query("SHOW COLUMNS FROM {$table}");
    $columns = [];
    while ($column = $columnResult->fetch_assoc()) {
        $columns[$column['Field']] = true;
    }
    $audioExpr = isset($columns['audio_path']) ? "SUM(CASE WHEN audio_path IS NOT NULL AND audio_path <> '' THEN 1 ELSE 0 END)" : "0";
    $remoteAudioExpr = isset($columns['audio_path']) ? "SUM(CASE WHEN audio_path LIKE 'http://%' OR audio_path LIKE 'https://%' THEN 1 ELSE 0 END)" : "0";
    $audioTranscriptExpr = isset($columns['audio_transcript']) ? "SUM(CASE WHEN audio_transcript IS NOT NULL AND audio_transcript <> '' THEN 1 ELSE 0 END)" : "0";
    $imageExpr = isset($columns['image_path']) ? "SUM(CASE WHEN image_path IS NOT NULL AND image_path <> '' THEN 1 ELSE 0 END)" : "0";
    $remoteImageExpr = isset($columns['image_path']) ? "SUM(CASE WHEN image_path LIKE 'http://%' OR image_path LIKE 'https://%' THEN 1 ELSE 0 END)" : "0";
    $row = $conn->query("
        SELECT
            COUNT(*) AS total,
            {$audioExpr} AS audio,
            {$remoteAudioExpr} AS remote_audio,
            {$audioTranscriptExpr} AS audio_transcripts,
            {$imageExpr} AS images,
            {$remoteImageExpr} AS remote_images,
            SUM(CASE WHEN difficulty = 'C2' AND cefr_level = 'C2' THEN 1 ELSE 0 END) AS c2
        FROM {$table}
    ")->fetch_assoc();
    $summary[$table] = [
        'total' => (int)($row['total'] ?? 0),
        'audio' => (int)($row['audio'] ?? 0),
        'remote_audio' => (int)($row['remote_audio'] ?? 0),
        'audio_transcripts' => (int)($row['audio_transcripts'] ?? 0),
        'images' => (int)($row['images'] ?? 0),
        'remote_images' => (int)($row['remote_images'] ?? 0),
        'c2' => (int)($row['c2'] ?? 0),
    ];
}

$readiness = getToeicSwContentReadiness($conn);
$packages = $readiness['packages'];
$readyCount = 0;
foreach ($packages as $package) {
    if (!empty($package['ready'])) {
        $readyCount++;
    }
}

$firstAudio = '';
$firstAudioTranscript = '';
$row = $conn->query("SELECT audio_path, audio_transcript FROM toeic_sw_respond_questions WHERE package_number = 1 AND question_number = 5 LIMIT 1")->fetch_assoc();
if ($row) {
    $firstAudio = (string)$row['audio_path'];
    $firstAudioTranscript = (string)$row['audio_transcript'];
}

$remoteAudioRows = 0;
$remoteImageRows = 0;
$audioTranscriptRows = 0;
foreach ($summary as $tableSummary) {
    $remoteAudioRows += (int)($tableSummary['remote_audio'] ?? 0);
    $remoteImageRows += (int)($tableSummary['remote_images'] ?? 0);
    $audioTranscriptRows += (int)($tableSummary['audio_transcripts'] ?? 0);
}

$report = [
    'ready' => (bool)$readiness['ready'],
    'ready_packages' => $readyCount,
    'packages' => $packages,
    'tables' => $summary,
    'media_summary' => [
        'remote_audio_rows' => $remoteAudioRows,
        'remote_image_rows' => $remoteImageRows,
        'audio_transcript_rows' => $audioTranscriptRows,
    ],
    'first_audio_path' => $firstAudio,
    'first_audio_transcript' => $firstAudioTranscript,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($readiness['ready'] ? 0 : 1);
