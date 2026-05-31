<?php
/**
 * Stream speaking recording for authenticated admin.
 * Debug mode adds JSON error responses for troubleshooting.
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/toeic_sw_helper.php';

$debug = isset($_GET['debug']);

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'Forbidden', 'session_role' => $_SESSION['role'] ?? 'none']); }
    else { echo 'Forbidden'; }
    exit();
}

$test_session = trim((string)($_GET['session'] ?? ''));
$question_row_id = (int)($_GET['question_id'] ?? 0);

if ($test_session === '' || $question_row_id <= 0) {
    http_response_code(400);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'Invalid request', 'session' => $test_session, 'question_id' => $question_row_id]); }
    else { echo 'Invalid recording request.'; }
    exit();
}

ensureToeicSwSchema($conn);

$stmt = $conn->prepare("
    SELECT source_path, test_session, section
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
    if ($debug) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Recording not found in database', 'question_id' => $question_row_id, 'test_session' => $test_session]);
    } else {
        echo 'Recording not found.';
    }
    exit();
}

$normalized = ltrim(str_replace('\\', '/', $source_path), '/');
if (!preg_match('#^storage/toeic_sw_recordings/[0-9]+/[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+\.(webm|ogg|oga|opus|wav|mp3|m4a|mp4)$#i', $normalized)) {
    http_response_code(403);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'Path not allowed', 'path' => $normalized]); }
    else { echo 'Recording path is not allowed.'; }
    exit();
}

$base_dir = realpath(__DIR__ . '/../storage/toeic_sw_recordings');
$file_path = realpath(__DIR__ . '/../' . $normalized);
if (!$base_dir) {
    http_response_code(500);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'Base dir not found', 'base_dir' => $base_dir]); }
    else { echo 'Storage directory error.'; }
    exit();
}
if (!$file_path) {
    http_response_code(404);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'File not found', 'file_path' => $normalized, 'full_path' => __DIR__ . '/../' . $normalized]); }
    else { echo 'Recording file is missing.'; }
    exit();
}

$base_norm = rtrim(str_replace('\\', '/', $base_dir), '/');
$file_norm = str_replace('\\', '/', $file_path);
if (strpos($file_norm, $base_norm . '/') !== 0) {
    http_response_code(403);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'Path traversal blocked', 'base' => $base_norm, 'file' => $file_norm]); }
    else { echo 'Path not allowed.'; }
    exit();
}
if (!is_file($file_path)) {
    http_response_code(404);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'Not a file', 'path' => $file_path]); }
    else { echo 'Recording file is missing.'; }
    exit();
}
if (!is_readable($file_path)) {
    http_response_code(403);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'File not readable', 'path' => $file_path]); }
    else { echo 'Recording file is not readable.'; }
    exit();
}

$extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$mime_map = [
    'webm' => 'audio/webm',
    'ogg' => 'audio/ogg; codecs=opus',
    'oga' => 'audio/ogg; codecs=vorbis',
    'opus' => 'audio/opus',
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
    'm4a' => 'audio/mp4',
    'mp4' => 'audio/mp4',
];
$mime = $mime_map[$extension] ?? 'application/octet-stream';
$size = filesize($file_path);
if ($size === false || $size <= 0) {
    http_response_code(404);
    if ($debug) { header('Content-Type: application/json'); echo json_encode(['error' => 'File empty', 'size' => $size]); }
    else { echo 'Recording file is empty.'; }
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
header('Access-Control-Allow-Origin: *');

if ($debug) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'file' => basename($file_path),
        'mime' => $mime,
        'size' => $size,
        'path' => $normalized
    ]);
    exit();
}

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
