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

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit();
    }

    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!validateCsrfToken($token)) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit();
    }

    $user_id = (int)$_SESSION['user_id'];
    $test_session = trim((string)($input['test_session'] ?? ''));
    $section = trim((string)($input['section'] ?? ''));
    $question_row_id = (int)($input['question_row_id'] ?? 0);
    $answer = trim((string)($input['answer'] ?? ''));

    if ($test_session === '' || !in_array($section, getToeicSwSectionOrder(), true) || $question_row_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit();
    }

    $session = getToeicSwSessionInfo($conn, $user_id, $test_session);
    if (!$session || ($session['status'] ?? '') !== 'active' || ($session['current_section'] ?? '') !== $section) {
        echo json_encode(['success' => false, 'error' => 'Section is no longer active']);
        exit();
    }

    $stmt = $conn->prepare("
        UPDATE toeic_sw_test_questions
        SET user_answer = ?
        WHERE id = ? AND test_session = ? AND user_id = ? AND section = ?
    ");
    $stmt->bind_param("sisis", $answer, $question_row_id, $test_session, $user_id, $section);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 0) {
        echo json_encode(['success' => false, 'error' => 'Answer could not be saved']);
        exit();
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
