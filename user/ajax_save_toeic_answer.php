<?php
/**
 * AJAX: Save answer for TOEIC test
 * Mirrors ajax_save_answer_2026.php pattern
 */

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/csrf_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!defined('FEATURE_TOEIC') || !FEATURE_TOEIC) {
    echo json_encode(['success' => false, 'error' => 'TOEIC is currently unavailable']);
    exit();
}

if (!validateCsrfToken()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$test_session = trim($input['test_session'] ?? '');
$question_id  = (int)($input['question_id'] ?? 0);
$section      = trim((string)($input['section'] ?? ''));
$answer       = strtoupper(trim((string)($input['answer'] ?? '')));

if (!$test_session || !$question_id || !in_array($section, ['listening', 'reading'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

if ($answer === '' || !preg_match('/^[A-D]$/', $answer)) {
    echo json_encode(['success' => false, 'error' => 'Invalid answer']);
    exit();
}

// Verify the session belongs to this user and is still active
$stmt = $conn->prepare("SELECT id, status FROM toeic_test_sessions WHERE test_session = ? AND user_id = ?");
$stmt->bind_param("si", $test_session, $_SESSION['user_id']);
$stmt->execute();
 $session_row = $stmt->get_result()->fetch_assoc();
if (!$session_row) {
    echo json_encode(['success' => false, 'error' => 'Session not found']);
    $stmt->close();
    exit();
}
$stmt->close();

if (($session_row['status'] ?? '') !== 'active') {
    echo json_encode(['success' => false, 'error' => 'Session is no longer active']);
    exit();
}

// Verify the target question exists in the submitted section before saving.
$stmt = $conn->prepare("
    SELECT 1
    FROM toeic_test_questions
    WHERE test_session = ? AND section = ? AND question_id = ?
    LIMIT 1
");
$stmt->bind_param("ssi", $test_session, $section, $question_id);
$stmt->execute();
$question_exists = (bool)$stmt->get_result()->fetch_row();
$stmt->close();

if (!$question_exists) {
    echo json_encode(['success' => false, 'error' => 'Question not found in this section']);
    exit();
}

// Save answer to toeic_test_questions using the current unique key shape.
$stmt = $conn->prepare("
    UPDATE toeic_test_questions
    SET user_answer = ?
    WHERE test_session = ? AND section = ? AND question_id = ?
");
$stmt->bind_param("sssi", $answer, $test_session, $section, $question_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save answer']);
}

$stmt->close();
