<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/toeic_sw_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit();
}

$test_session = trim((string)($_GET['session'] ?? ''));
$question_row_id = (int)($_GET['question_id'] ?? 0);

if ($test_session === '' || $question_row_id <= 0) {
    http_response_code(400);
    echo 'Invalid recording request.';
    exit();
}

ensureToeicSwSchema($conn);

$stmt = $conn->prepare("
    SELECT source_path
    FROM toeic_sw_test_questions
    WHERE id = ?
      AND test_session = ?
      AND section = 'speaking'
    LIMIT 1
");
$stmt->bind_param('is', $question_row_id, $test_session);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$source_path = trim((string)($row['source_path'] ?? ''));
if ($source_path === '') {
    http_response_code(404);
    echo 'Recording not found.';
    exit();
}

$normalized = ltrim(str_replace('\\', '/', $source_path), '/');
if (!preg_match('#^storage/toeic_sw_recordings/[0-9]+/[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+\.(webm|ogg|oga|opus|wav|mp3|m4a|mp4)$#i', $normalized)) {
    http_response_code(403);
    echo 'Recording path is not allowed.';
    exit();
}

$base_dir = realpath(__DIR__ . '/../storage/toeic_sw_recordings');
$file_path = realpath(__DIR__ . '/../' . $normalized);
if (!$base_dir || !$file_path) {
    http_response_code(404);
    echo 'Recording file is missing.';
    exit();
}

$base_norm = rtrim(str_replace('\\', '/', $base_dir), '/');
$file_norm = str_replace('\\', '/', $file_path);
if (strpos($file_norm, $base_norm . '/') !== 0 || !is_file($file_path) || !is_readable($file_path)) {
    http_response_code(403);
    echo 'Recording file is not readable.';
    exit();
}

$extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$mime_map = [
    'webm' => 'audio/webm',
    'ogg' => 'audio/ogg',
    'oga' => 'audio/ogg',
    'opus' => 'audio/ogg',
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
    'm4a' => 'audio/mp4',
    'mp4' => 'audio/mp4',
];
$mime = $mime_map[$extension] ?? 'application/octet-stream';
$size = filesize($file_path);
if ($size === false || $size <= 0) {
    http_response_code(404);
    echo 'Recording file is empty.';
    exit();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$start = 0;
$end = $size - 1;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if (preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
    if ($matches[1] !== '') {
        $start = max(0, (int)$matches[1]);
    }
    if ($matches[2] !== '') {
        $end = min($end, (int)$matches[2]);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit();
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$handle = fopen($file_path, 'rb');
if (!$handle) {
    http_response_code(500);
    exit();
}

fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk_size = min(8192, $remaining);
    $chunk = fread($handle, $chunk_size);
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($handle);
exit();
