<?php
require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_utils.php';

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    echo "Database connection is unavailable.";
    exit();
}

$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

$root = dirname(__DIR__);
$contentDir = $root . '/content/generated/toeic';
$transcriptPath = $root . '/uploads/toeic_audio/transcripts.json';

function envValue(string $name): string {
    $value = getenv($name);
    return $value === false ? '' : trim($value);
}

function bootstrapToken(): string {
    $token = envValue('TOEIC_SETUP_TOKEN');
    if ($token !== '') {
        return $token;
    }
    return envValue('SETUP_BOOTSTRAP_TOKEN');
}

function audioPublicBaseUrl(): string {
    $value = envValue('R2_AUDIO_PUBLIC_BASE_URL');
    if ($value !== '') {
        return rtrim($value, '/');
    }
    $value = envValue('R2_PUBLIC_BASE_URL');
    return $value !== '' ? rtrim($value, '/') : '';
}

function photoPublicBaseUrl(): string {
    $value = envValue('R2_PHOTO_PUBLIC_BASE_URL');
    if ($value !== '') {
        return rtrim($value, '/');
    }
    $value = envValue('R2_PUBLIC_BASE_URL');
    return $value !== '' ? rtrim($value, '/') : '';
}

function appendLog(array &$logs, string $message): void {
    $logs[] = $message;
}

function readJsonFileStrict(string $path): array {
    if (!file_exists($path)) {
        throw new RuntimeException("Missing JSON file: $path");
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON: $path");
    }
    return $data;
}

function normalizeChoiceMapWeb($raw): array {
    if (is_array($raw) && array_keys($raw) !== range(0, count($raw) - 1)) {
        return $raw;
    }
    $normalized = [];
    foreach ((array)$raw as $entry) {
        if (is_string($entry) && preg_match('/^\s*([A-D])[\.\):\-]\s*(.+)$/', $entry, $m)) {
            $normalized[$m[1]] = trim($m[2]);
        }
    }
    return $normalized;
}

function normalizeQuestionItemWeb(array $questionItem): array {
    $rawOptions = $questionItem['options'] ?? [];
    $questionItem['options'] = normalizeChoiceMapWeb($rawOptions);
    if (empty($questionItem['options']) && is_array($rawOptions)) {
        $letters = ['A', 'B', 'C', 'D'];
        foreach ($rawOptions as $idx => $value) {
            if (isset($letters[$idx])) {
                if (is_array($value)) {
                    $value = $value['text'] ?? reset($value) ?? '';
                }
                $questionItem['options'][$letters[$idx]] = trim((string)$value);
            }
        }
    }
    if (!isset($questionItem['question_text']) && isset($questionItem['question'])) {
        $questionItem['question_text'] = $questionItem['question'];
    }
    if (!isset($questionItem['correct_answer']) && isset($questionItem['correct_option'])) {
        $questionItem['correct_answer'] = strtoupper(trim((string)$questionItem['correct_option']));
    }
    if (!isset($questionItem['correct_answer']) && array_key_exists('correct_index', $questionItem)) {
        $letters = ['A', 'B', 'C', 'D'];
        $index = (int)$questionItem['correct_index'];
        if (isset($letters[$index])) {
            $questionItem['correct_answer'] = $letters[$index];
        }
    }
    if (!isset($questionItem['correct_answer']) && isset($questionItem['answer'])) {
        $candidate = strtoupper(trim((string)$questionItem['answer']));
        if (in_array($candidate, ['A', 'B', 'C', 'D'], true)) {
            $questionItem['correct_answer'] = $candidate;
        }
    }
    if (!isset($questionItem['explanation'])) {
        $questionItem['explanation'] = '';
    }
    return $questionItem;
}

function safeCountTable(mysqli $conn, string $table): int {
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$result || $result->num_rows === 0) {
        return 0;
    }
    $count = $conn->query("SELECT COUNT(*) AS total FROM `$table`");
    return $count ? (int)($count->fetch_assoc()['total'] ?? 0) : 0;
}

function loadTranscriptManifestFiles(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data['files'] ?? null) ? $data['files'] : [];
}

function transcriptTextForFile(array $entry): string {
    $spoken = $entry['spoken_transcript'] ?? [];
    if (isset($spoken['statements']) && is_array($spoken['statements'])) {
        return trim(implode(' ', array_map('strval', $spoken['statements'])));
    }
    if (isset($spoken['prompt'])) {
        return trim(implode(' ', array_filter([
            (string)($spoken['prompt'] ?? ''),
            (string)($spoken['response_1'] ?? ''),
            (string)($spoken['response_2'] ?? ''),
            (string)($spoken['response_3'] ?? ''),
        ])));
    }
    if (isset($spoken['segments']) && is_array($spoken['segments'])) {
        $parts = [];
        foreach ($spoken['segments'] as $segment) {
            $parts[] = trim((string)($segment['text'] ?? ''));
        }
        return trim(implode(' ', array_filter($parts)));
    }
    if (isset($spoken['talk'])) {
        return trim((string)$spoken['talk']);
    }
    return '';
}

function fallbackTranscriptText($raw): string {
    if (is_string($raw)) {
        return trim($raw);
    }
    if (is_array($raw)) {
        $parts = [];
        foreach ($raw as $value) {
            if (is_scalar($value)) {
                $parts[] = trim((string)$value);
            }
        }
        return trim(implode(' ', array_filter($parts)));
    }
    return '';
}

function audioUrlForFilename(string $baseUrl, string $filename): string {
    if (preg_match('/^toeic_p([1-4])_\d+\.mp3$/i', $filename, $matches)) {
        return $baseUrl . '/toeic/audio/part' . $matches[1] . '/' . rawurlencode($filename);
    }
    return $baseUrl . '/toeic/audio/misc/' . rawurlencode($filename);
}

function photoUrlForFilename(string $baseUrl, string $filename): string {
    return $baseUrl . '/toeic/photos/' . rawurlencode($filename);
}

function findPhotoIdByFileUrl(mysqli $conn, string $targetUrl, string $filename): ?int {
    $stmt = $conn->prepare("SELECT id_photo FROM toeic_photos WHERE file_path = ? OR file_path LIKE ? LIMIT 1");
    $like = '%/' . $filename;
    $stmt->bind_param("ss", $targetUrl, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id_photo'] : null;
}

function findAudioIdByFileUrl(mysqli $conn, string $targetUrl, string $filename): ?int {
    $stmt = $conn->prepare("SELECT id_audio FROM toeic_audio WHERE file_path = ? OR file_path LIKE ? LIMIT 1");
    $like = '%/' . $filename;
    $stmt->bind_param("ss", $targetUrl, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id_audio'] : null;
}

function getOrCreatePhoto(mysqli $conn, string $fileUrl, string $description, array &$stats): int {
    $filename = basename(parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl);
    $existing = findPhotoIdByFileUrl($conn, $fileUrl, $filename);
    if ($existing !== null) {
        $stats['photos_skipped']++;
        return $existing;
    }
    $stmt = $conn->prepare("INSERT INTO toeic_photos (file_path, description) VALUES (?, ?)");
    $stmt->bind_param("ss", $fileUrl, $description);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    $stats['photos_inserted']++;
    return $id;
}

function getOrCreateAudio(mysqli $conn, string $title, string $part, string $fileUrl, string $transcript, string $context, ?int $photoId, array &$stats): int {
    $filename = basename(parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl);
    $existing = findAudioIdByFileUrl($conn, $fileUrl, $filename);
    if ($existing !== null) {
        $stats['audio_skipped']++;
        return $existing;
    }
    if ($photoId === null) {
        $stmt = $conn->prepare("INSERT INTO toeic_audio (judul, part, file_path, transcript, context, id_photo) VALUES (?, ?, ?, ?, ?, NULL)");
        $stmt->bind_param("sssss", $title, $part, $fileUrl, $transcript, $context);
    } else {
        $stmt = $conn->prepare("INSERT INTO toeic_audio (judul, part, file_path, transcript, context, id_photo) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $title, $part, $fileUrl, $transcript, $context, $photoId);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    $stats['audio_inserted']++;
    return $id;
}

function listeningQuestionExists(mysqli $conn, string $part, int $number): bool {
    $stmt = $conn->prepare("SELECT id_soal FROM toeic_soal_listening WHERE part = ? AND nomor_soal = ? LIMIT 1");
    $stmt->bind_param("si", $part, $number);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function readingQuestionExists(mysqli $conn, string $part, int $number): bool {
    $stmt = $conn->prepare("SELECT id_soal FROM toeic_soal_reading WHERE part = ? AND nomor_soal = ? LIMIT 1");
    $stmt->bind_param("si", $part, $number);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function getOrCreateText(mysqli $conn, string $title, string $part, string $textType, string $text1, ?string $text2, ?string $text3, array &$stats): int {
    $stmt = $conn->prepare("SELECT id_teks FROM toeic_teks WHERE judul = ? AND part = ? AND COALESCE(text_type, '') = ? LIMIT 1");
    $stmt->bind_param("sss", $title, $part, $textType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $stats['texts_skipped']++;
        return (int)$row['id_teks'];
    }
    $stmt = $conn->prepare("INSERT INTO toeic_teks (judul, part, text_type, isi_teks, isi_teks_2, isi_teks_3) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $title, $part, $textType, $text1, $text2, $text3);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    $stats['texts_inserted']++;
    return $id;
}

function insertListeningQuestion(mysqli $conn, string $part, int $number, string $question, ?string $a, ?string $b, ?string $c, ?string $d, string $correct, string $explanation, int $audioId, string $questionType, array &$stats): void {
    if (listeningQuestionExists($conn, $part, $number)) {
        $stats['listening_skipped']++;
        return;
    }
    $stmt = $conn->prepare("INSERT INTO toeic_soal_listening (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_audio, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisssssssis", $part, $number, $question, $a, $b, $c, $d, $correct, $explanation, $audioId, $questionType);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    $stats['listening_inserted']++;
}

function insertReadingQuestion(mysqli $conn, string $part, int $number, string $question, ?string $a, ?string $b, ?string $c, ?string $d, string $correct, string $explanation, ?int $textId, string $questionType, array &$stats): void {
    if (readingQuestionExists($conn, $part, $number)) {
        $stats['reading_skipped']++;
        return;
    }
    if ($textId === null) {
        $stmt = $conn->prepare("INSERT INTO toeic_soal_reading (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_teks, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)");
        $stmt->bind_param("sissssssss", $part, $number, $question, $a, $b, $c, $d, $correct, $explanation, $questionType);
    } else {
        $stmt = $conn->prepare("INSERT INTO toeic_soal_reading (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_teks, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisssssssis", $part, $number, $question, $a, $b, $c, $d, $correct, $explanation, $textId, $questionType);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    $stats['reading_inserted']++;
}

function importToeicProductionContent(mysqli $conn, string $contentDir, string $audioBaseUrl, string $photoBaseUrl, string $transcriptPath, array &$logs): array {
    $available = [];
    foreach (['part1', 'part2', 'part3', 'part4', 'part5', 'part6', 'part7'] as $name) {
        $path = $contentDir . '/' . $name . '.json';
        if (file_exists($path)) {
            $available[$name] = readJsonFileStrict($path);
        }
    }
    if (empty($available)) {
        throw new RuntimeException("No TOEIC generated JSON files found in $contentDir");
    }

    $transcriptFiles = loadTranscriptManifestFiles($transcriptPath);
    $stats = [
        'photos_inserted' => 0,
        'photos_skipped' => 0,
        'audio_inserted' => 0,
        'audio_skipped' => 0,
        'texts_inserted' => 0,
        'texts_skipped' => 0,
        'listening_inserted' => 0,
        'listening_skipped' => 0,
        'reading_inserted' => 0,
        'reading_skipped' => 0,
    ];

    $conn->begin_transaction();
    try {
        $listeningNumber = 1;

        foreach (($available['part1']['items'] ?? []) as $index => $item) {
            $item['options'] = normalizeChoiceMapWeb($item['options'] ?? []);
            $imageFile = basename((string)($item['image_file'] ?? ''));
            $audioFile = basename((string)($item['audio_file'] ?? ''));
            if ($imageFile === '' || $audioFile === '') {
                $listeningNumber++;
                continue;
            }
            $photoId = getOrCreatePhoto($conn, photoUrlForFilename($photoBaseUrl, $imageFile), (string)($item['photo_description'] ?? ''), $stats);
            $transcript = transcriptTextForFile($transcriptFiles[$audioFile] ?? []);
            if ($transcript === '') {
                $transcript = fallbackTranscriptText($item['audio_script'] ?? '');
            }
            $audioId = getOrCreateAudio(
                $conn,
                (string)($item['title'] ?? ('Part 1 Item ' . ($index + 1))),
                '1',
                audioUrlForFilename($audioBaseUrl, $audioFile),
                $transcript,
                (string)($item['photo_description'] ?? ''),
                $photoId,
                $stats
            );
            insertListeningQuestion(
                $conn,
                '1',
                $listeningNumber,
                "Choose the statement that best describes the photo.",
                $item['options']['A'] ?? null,
                $item['options']['B'] ?? null,
                $item['options']['C'] ?? null,
                $item['options']['D'] ?? null,
                (string)($item['correct_answer'] ?? ''),
                (string)($item['explanation'] ?? ''),
                $audioId,
                'part1_photograph',
                $stats
            );
            $listeningNumber++;
        }

        foreach (($available['part2']['items'] ?? []) as $index => $item) {
            $item['options'] = normalizeChoiceMapWeb($item['options'] ?? []);
            $audioFile = basename((string)($item['audio_file'] ?? ''));
            if ($audioFile === '') {
                $listeningNumber++;
                continue;
            }
            $transcript = transcriptTextForFile($transcriptFiles[$audioFile] ?? []);
            if ($transcript === '') {
                $transcript = fallbackTranscriptText($item['audio_script'] ?? '');
            }
            $audioId = getOrCreateAudio(
                $conn,
                (string)($item['title'] ?? ('Part 2 Item ' . ($index + 1))),
                '2',
                audioUrlForFilename($audioBaseUrl, $audioFile),
                $transcript,
                (string)($item['prompt_text'] ?? ''),
                null,
                $stats
            );
            insertListeningQuestion(
                $conn,
                '2',
                $listeningNumber,
                (string)($item['prompt_text'] ?? 'Listen to the question and choose the best response.'),
                $item['options']['A'] ?? null,
                $item['options']['B'] ?? null,
                $item['options']['C'] ?? null,
                null,
                (string)($item['correct_answer'] ?? ''),
                (string)($item['explanation'] ?? ''),
                $audioId,
                'part2_question_response',
                $stats
            );
            $listeningNumber++;
        }

        foreach (['3' => ($available['part3']['sets'] ?? []), '4' => ($available['part4']['sets'] ?? [])] as $part => $sets) {
            foreach ($sets as $index => $set) {
                $audioFile = basename((string)($set['audio_file'] ?? ''));
                if ($audioFile === '') {
                    $listeningNumber += count($set['questions'] ?? []);
                    continue;
                }
                $transcript = transcriptTextForFile($transcriptFiles[$audioFile] ?? []);
                if ($transcript === '') {
                    $transcript = fallbackTranscriptText($set['audio_script'] ?? '');
                }
                $audioId = getOrCreateAudio(
                    $conn,
                    (string)($set['title'] ?? ('Part ' . $part . ' Set ' . ($index + 1))),
                    $part,
                    audioUrlForFilename($audioBaseUrl, $audioFile),
                    $transcript,
                    (string)($set['context'] ?? ''),
                    null,
                    $stats
                );
                $questionType = $part === '3' ? 'part3_conversation' : 'part4_talk';
                foreach (($set['questions'] ?? []) as $questionItem) {
                    $questionItem = normalizeQuestionItemWeb($questionItem);
                    insertListeningQuestion(
                        $conn,
                        $part,
                        $listeningNumber,
                        (string)($questionItem['question_text'] ?? ''),
                        $questionItem['options']['A'] ?? null,
                        $questionItem['options']['B'] ?? null,
                        $questionItem['options']['C'] ?? null,
                        $questionItem['options']['D'] ?? null,
                        (string)($questionItem['correct_answer'] ?? ''),
                        (string)($questionItem['explanation'] ?? ''),
                        $audioId,
                        $questionType,
                        $stats
                    );
                    $listeningNumber++;
                }
            }
        }

        $readingNumber = 101;
        foreach (($available['part5']['items'] ?? []) as $item) {
            $item = normalizeQuestionItemWeb($item);
            insertReadingQuestion(
                $conn,
                '5',
                $readingNumber,
                (string)($item['sentence'] ?? ''),
                $item['options']['A'] ?? null,
                $item['options']['B'] ?? null,
                $item['options']['C'] ?? null,
                $item['options']['D'] ?? null,
                (string)($item['correct_answer'] ?? ''),
                (string)($item['explanation'] ?? ''),
                null,
                'part5_incomplete_sentence',
                $stats
            );
            $readingNumber++;
        }

        foreach (($available['part6']['sets'] ?? []) as $set) {
            $textId = getOrCreateText(
                $conn,
                (string)($set['title'] ?? 'Part 6 Text'),
                '6',
                (string)($set['text_type'] ?? 'single'),
                (string)($set['passage_with_blanks'] ?? ''),
                null,
                null,
                $stats
            );
            foreach (($set['questions'] ?? []) as $questionItem) {
                $questionItem = normalizeQuestionItemWeb($questionItem);
                $questionText = (string)($questionItem['question_text'] ?? ('Blank ' . ($questionItem['blank_number'] ?? '?')));
                insertReadingQuestion(
                    $conn,
                    '6',
                    $readingNumber,
                    $questionText,
                    $questionItem['options']['A'] ?? null,
                    $questionItem['options']['B'] ?? null,
                    $questionItem['options']['C'] ?? null,
                    $questionItem['options']['D'] ?? null,
                    (string)($questionItem['correct_answer'] ?? ''),
                    (string)($questionItem['explanation'] ?? ''),
                    $textId,
                    'part6_text_completion',
                    $stats
                );
                $readingNumber++;
            }
        }

        foreach (['single' => ($available['part7']['single_sets'] ?? []), 'double' => ($available['part7']['double_sets'] ?? []), 'triple' => ($available['part7']['triple_sets'] ?? [])] as $textType => $sets) {
            foreach ($sets as $set) {
                $textId = getOrCreateText(
                    $conn,
                    (string)($set['title'] ?? 'Part 7 Text'),
                    '7',
                    $textType,
                    (string)($set['passage_1'] ?? ''),
                    isset($set['passage_2']) ? (string)$set['passage_2'] : null,
                    isset($set['passage_3']) ? (string)$set['passage_3'] : null,
                    $stats
                );
                foreach (($set['questions'] ?? []) as $questionItem) {
                    $questionItem = normalizeQuestionItemWeb($questionItem);
                    insertReadingQuestion(
                        $conn,
                        '7',
                        $readingNumber,
                        (string)($questionItem['question_text'] ?? ''),
                        $questionItem['options']['A'] ?? null,
                        $questionItem['options']['B'] ?? null,
                        $questionItem['options']['C'] ?? null,
                        $questionItem['options']['D'] ?? null,
                        (string)($questionItem['correct_answer'] ?? ''),
                        (string)($questionItem['explanation'] ?? ''),
                        $textId,
                        'part7_' . $textType . '_passage',
                        $stats
                    );
                    $readingNumber++;
                }
            }
        }

        $conn->commit();
        appendLog($logs, 'TOEIC production import finished.');
        return $stats;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

$audioBaseUrl = audioPublicBaseUrl();
$photoBaseUrl = photoPublicBaseUrl();
$usersTableExists = checkTableExists($conn, 'users');
$bootstrapMode = !$usersTableExists;
$providedBootstrapToken = isset($_REQUEST['bootstrap_token']) ? trim((string)$_REQUEST['bootstrap_token']) : trim((string)($_GET['token'] ?? ''));
$configuredBootstrapToken = bootstrapToken();
$hasAdminSession = isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (!$hasAdminSession) {
    if ($bootstrapMode) {
        if ($configuredBootstrapToken === '' || !hash_equals($configuredBootstrapToken, $providedBootstrapToken)) {
            http_response_code(403);
            echo "Bootstrap token required. Set TOEIC_SETUP_TOKEN in .env and open this page with ?token=YOUR_TOKEN.";
            exit();
        }
    } else {
        header("Location: login.php");
        exit();
    }
}

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
        $error = 'Invalid CSRF token.';
    } else {
        $logs = [];
        try {
            if ($bootstrapMode && ($configuredBootstrapToken === '' || !hash_equals($configuredBootstrapToken, $providedBootstrapToken))) {
                throw new RuntimeException('Bootstrap token mismatch.');
            }
            if ($audioBaseUrl === '' || $photoBaseUrl === '') {
                throw new RuntimeException('R2 audio/photo public base URL is not configured. Set R2_AUDIO_PUBLIC_BASE_URL and R2_PHOTO_PUBLIC_BASE_URL (or R2_PUBLIC_BASE_URL).');
            }
            if (!is_dir($contentDir)) {
                throw new RuntimeException("Content directory not found: $contentDir");
            }

            appendLog($logs, 'Running TOEIC standalone schema bootstrap...');
            ob_start();
            require __DIR__ . '/../scripts/migrate_toeic_standalone.php';
            $migrationOutput = trim(ob_get_clean());
            foreach (preg_split('/\R+/', $migrationOutput) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    appendLog($logs, $line);
                }
            }

            appendLog($logs, 'Importing TOEIC production content from JSON files...');
            $stats = importToeicProductionContent($conn, $contentDir, $audioBaseUrl, $photoBaseUrl, $transcriptPath, $logs);
            $result = [
                'logs' => $logs,
                'stats' => $stats,
            ];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if (!empty($logs)) {
                $result = ['logs' => $logs];
            }
        }
    }
}

$snapshot = [
    'toeic_photos' => safeCountTable($conn, 'toeic_photos'),
    'toeic_audio' => safeCountTable($conn, 'toeic_audio'),
    'toeic_teks' => safeCountTable($conn, 'toeic_teks'),
    'toeic_soal_listening' => safeCountTable($conn, 'toeic_soal_listening'),
    'toeic_soal_reading' => safeCountTable($conn, 'toeic_soal_reading'),
    'toeic_score_conversion' => safeCountTable($conn, 'toeic_score_conversion'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Production Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; color: #12233d; }
        .panel { background: #fff; border: 1px solid #dfe7f1; border-radius: 18px; padding: 24px; box-shadow: 0 16px 40px rgba(18,35,61,0.06); }
        pre { white-space: pre-wrap; word-break: break-word; background: #0f172a; color: #e2e8f0; padding: 18px; border-radius: 12px; }
        .muted { color: #5f7089; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="panel mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h1 class="h3 mb-2">TOEIC Production Setup</h1>
                    <p class="muted mb-0">
                        <?php echo $bootstrapMode
                            ? 'Bootstrap mode is active because the users table does not exist yet. Access is currently guarded by TOEIC_SETUP_TOKEN.'
                            : 'Admin-only all-in-one setup page for schema bootstrap, seed defaults, and TOEIC content import using R2 URLs.'; ?>
                    </p>
                </div>
                <a href="<?php echo $bootstrapMode ? '../index.php' : 'index.php'; ?>" class="btn btn-outline-secondary">
                    <?php echo $bootstrapMode ? 'Back to Site' : 'Back to Admin'; ?>
                </a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="panel h-100">
                    <h2 class="h5 mb-3">Preflight</h2>
                    <div class="small mb-2"><strong>Content directory:</strong> <code><?php echo htmlspecialchars($contentDir); ?></code></div>
                    <div class="small mb-2"><strong>Transcript manifest:</strong> <code><?php echo htmlspecialchars($transcriptPath); ?></code></div>
                    <div class="small mb-2"><strong>Audio base URL:</strong> <code><?php echo htmlspecialchars($audioBaseUrl ?: '[missing]'); ?></code></div>
                    <div class="small mb-0"><strong>Photo base URL:</strong> <code><?php echo htmlspecialchars($photoBaseUrl ?: '[missing]'); ?></code></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="panel h-100">
                    <h2 class="h5 mb-3">Current DB Snapshot</h2>
                    <?php foreach ($snapshot as $table => $count): ?>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span><?php echo htmlspecialchars($table); ?></span>
                            <strong><?php echo (int)$count; ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($result && isset($result['stats'])): ?>
            <div class="panel mb-4">
                <h2 class="h5 mb-3">Run Summary</h2>
                <div class="row g-3">
                    <?php foreach ($result['stats'] as $key => $value): ?>
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-uppercase muted"><?php echo htmlspecialchars(str_replace('_', ' ', $key)); ?></div>
                                <div class="h4 mb-0"><?php echo (int)$value; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($result && !empty($result['logs'])): ?>
            <div class="panel mb-4">
                <h2 class="h5 mb-3">Execution Log</h2>
                <pre><?php echo htmlspecialchars(implode(PHP_EOL, $result['logs'])); ?></pre>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2 class="h5 mb-3">Run Setup</h2>
            <p class="muted">This page will run schema bootstrap, seed defaults, and import TOEIC content from local JSON files. Existing content is skipped if it already exists.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <?php if ($bootstrapMode): ?>
                    <input type="hidden" name="bootstrap_token" value="<?php echo htmlspecialchars($providedBootstrapToken); ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" onclick="return confirm('Run TOEIC production setup now?');">Run TOEIC Production Setup</button>
            </form>
        </div>
    </div>
</body>
</html>
