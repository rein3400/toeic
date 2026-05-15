<?php
header('Content-Type: application/json');

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/toeic_sw_scorer.php';

if (function_exists('set_time_limit')) {
    set_time_limit(120);
}

try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
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
    if ($test_session === '' || strpos($test_session, 'toeic_sw_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid TOEIC SW session']);
        exit();
    }

    $session = getToeicSwSessionInfo($conn, $user_id, $test_session);
    if (!$session || ($session['status'] ?? '') !== 'completed') {
        echo json_encode(['success' => false, 'error' => 'Completed TOEIC SW session not found']);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT q.id, q.section
        FROM toeic_sw_test_questions q
        JOIN toeic_sw_subjective_scores s
          ON s.test_session = q.test_session
         AND s.question_row_id = q.id
        WHERE q.user_id = ?
          AND q.test_session = ?
          AND s.status = 'needs_rescore'
        ORDER BY FIELD(q.section, 'speaking', 'writing'), q.question_order
        LIMIT 1
    ");
    $stmt->bind_param("is", $user_id, $test_session);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        echo json_encode(['success' => true, 'processed' => false, 'remaining' => 0]);
        exit();
    }

    $questions = getToeicSwQuestionsForSection($conn, $test_session, (string)$target['section']);
    $question = null;
    foreach ($questions as $row) {
        if ((int)$row['id'] === (int)$target['id']) {
            $question = $row;
            break;
        }
    }

    if (!$question) {
        echo json_encode(['success' => false, 'error' => 'Question not found']);
        exit();
    }

    $normalized = scoreToeicSwSubjectiveQuestion($conn, $question);
    $stmt = $conn->prepare("UPDATE toeic_sw_test_questions SET is_correct = ? WHERE id = ?");
    $row_id = (int)$question['id'];
    $stmt->bind_param("di", $normalized, $row_id);
    $stmt->execute();
    $stmt->close();

    $scorer = new ToeicSwScorer($conn);
    $final = $scorer->saveResults($test_session, $user_id);

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS remaining
        FROM toeic_sw_subjective_scores
        WHERE user_id = ? AND test_session = ? AND status = 'needs_rescore'
    ");
    $stmt->bind_param("is", $user_id, $test_session);
    $stmt->execute();
    $remaining = (int)($stmt->get_result()->fetch_assoc()['remaining'] ?? 0);
    $stmt->close();

    echo json_encode([
        'success' => true,
        'processed' => true,
        'question_row_id' => $row_id,
        'section' => $question['section'],
        'remaining' => $remaining,
        'final_score' => $final,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
