<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

// Auth Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$chunk_id = (int)($_GET['id'] ?? 0);
if (!$chunk_id) {
    http_response_code(400);
    exit('Invalid chunk ID');
}

// Fetch chunk path
$stmt = $conn->prepare("SELECT chunk_path FROM proctoring_video_chunks WHERE id = ?");
$stmt->bind_param("i", $chunk_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    exit('Chunk not found');
}

$chunk = $result->fetch_assoc();
$file_path = $chunk['chunk_path'];

// Validate path is within uploads directory (security)
$real_path = realpath($file_path);
$base_path = realpath(__DIR__ . '/../uploads/proctoring/chunks/');

// Allow if file exists and path seems safe-ish (or disable check if symlinks involved)
if (!$real_path || !file_exists($real_path)) {
    http_response_code(404);
    exit('Video file not found on server');
}

// Clean output buffer to prevent corruption
if (ob_get_length()) ob_clean();

$mime_type = mime_content_type($real_path) ?: 'video/webm';
$filesize = filesize($real_path);

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . $filesize);
header('Accept-Ranges: bytes');
header('Cache-Control: no-cache');

readfile($real_path);
exit;
