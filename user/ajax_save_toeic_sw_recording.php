<?php
header('Content-Type: application/json');

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_sw_helper.php';

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }

    if (!defined('FEATURE_TOEIC') || !FEATURE_TOEIC) {
        echo json_encode(['success' => false, 'error' => 'TOEIC is currently unavailable']);
        exit();
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit();
    }

    $user_id = (int)$_SESSION['user_id'];
    $test_session = trim((string)($_POST['test_session'] ?? ''));
    $section = trim((string)($_POST['section'] ?? ''));
    $question_row_id = (int)($_POST['question_row_id'] ?? 0);

    if ($test_session === '' || $section !== 'speaking' || $question_row_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit();
    }

    $session = getToeicSwSessionInfo($conn, $user_id, $test_session);
    if (!$session || ($session['status'] ?? '') !== 'active' || ($session['current_section'] ?? '') !== 'speaking') {
        echo json_encode(['success' => false, 'error' => 'Speaking section is no longer active']);
        exit();
    }

    if (empty($_FILES['recording']) || ($_FILES['recording']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Recording upload failed']);
        exit();
    }

    $file = $_FILES['recording'];
    $maxBytes = 25 * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'error' => 'Recording is too large']);
        exit();
    }

    $original = (string)($file['name'] ?? 'recording.webm');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['webm', 'ogg', 'oga', 'opus', 'wav', 'mp3', 'm4a', 'mp4'];
    if (!in_array($ext, $allowed, true)) {
        $ext = 'webm';
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM toeic_sw_test_questions
        WHERE id = ? AND test_session = ? AND user_id = ? AND section = 'speaking'
        LIMIT 1
    ");
    $stmt->bind_param("isi", $question_row_id, $test_session, $user_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$exists) {
        echo json_encode(['success' => false, 'error' => 'Question not found']);
        exit();
    }

    $baseDir = __DIR__ . '/../storage/toeic_sw_recordings/' . $user_id . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $test_session);
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
        echo json_encode(['success' => false, 'error' => 'Recording directory could not be created']);
        exit();
    }

    $filename = 'sw_' . $question_row_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absolutePath = $baseDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        echo json_encode(['success' => false, 'error' => 'Recording could not be stored']);
        exit();
    }

    $relativePath = 'storage/toeic_sw_recordings/' . $user_id . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $test_session) . '/' . $filename;
    $stmt = $conn->prepare("
        UPDATE toeic_sw_test_questions
        SET source_path = ?, user_answer = ?
        WHERE id = ? AND test_session = ? AND user_id = ?
    ");
    $stmt->bind_param("ssisi", $relativePath, $relativePath, $question_row_id, $test_session, $user_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'source_path' => $relativePath]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
