<?php
header('Content-Type: application/json');

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/toeic_sw_scorer.php';

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
    $valid_sections = getToeicSwSectionOrder();

    if ($test_session === '' || !in_array($section, $valid_sections, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit();
    }

    $session = getToeicSwSessionInfo($conn, $user_id, $test_session);
    if (!$session || ($session['status'] ?? '') !== 'active') {
        echo json_encode(['success' => false, 'error' => 'Session is no longer active']);
        exit();
    }

    if (($session['current_section'] ?? '') !== $section) {
        echo json_encode(['success' => false, 'error' => 'Section is no longer active']);
        exit();
    }
    $mode = !empty($session['practice_mode']) ? 'prep' : 'full';

    if ($section === 'speaking') {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS missing
            FROM toeic_sw_test_questions
            WHERE test_session = ? AND user_id = ? AND section = 'speaking'
              AND (source_path IS NULL OR source_path = '')
        ");
        $stmt->bind_param("si", $test_session, $user_id);
        $stmt->execute();
        $missing = (int)($stmt->get_result()->fetch_assoc()['missing'] ?? 0);
        $stmt->close();
        if ($missing > 0) {
            echo json_encode(['success' => false, 'error' => 'Semua jawaban Speaking harus direkam dan berhasil diupload sebelum submit.']);
            exit();
        }
    }

    $scorer = new ToeicSwScorer($conn);
    $section_score = $scorer->scoreSection($test_session, $section);
    $section_index = array_search($section, $valid_sections, true);
    $is_last = $section_index === count($valid_sections) - 1;

    if ($is_last) {
        $results = $scorer->saveResults($test_session, $user_id);
        unset($_SESSION['toeic_sw_test_session'], $_SESSION['test_session'], $_SESSION['test_format'], $_SESSION['current_section'], $_SESSION['practice_mode_toeic_sw']);
        if (isset($_SESSION['toeic_sw_section_start_times']) && is_array($_SESSION['toeic_sw_section_start_times'])) {
            unset($_SESSION['toeic_sw_section_start_times'][$test_session . ':speaking'], $_SESSION['toeic_sw_section_start_times'][$test_session . ':writing']);
        }

        echo json_encode([
            'success' => true,
            'redirect' => 'result_toeic_sw.php?session=' . urlencode($test_session),
            'section_score' => $section_score,
            'final_score' => $results,
        ]);
        exit();
    }

    $next_section = $valid_sections[$section_index + 1];
    $stmt = $conn->prepare("UPDATE toeic_sw_test_sessions SET current_section = ? WHERE test_session = ? AND user_id = ?");
    $stmt->bind_param("ssi", $next_section, $test_session, $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['current_section'] = $next_section;
    if (!isset($_SESSION['toeic_sw_section_start_times']) || !is_array($_SESSION['toeic_sw_section_start_times'])) {
        $_SESSION['toeic_sw_section_start_times'] = [];
    }
    $_SESSION['toeic_sw_section_start_times'][$test_session . ':' . $next_section] = time();

    echo json_encode([
        'success' => true,
        'redirect' => 'test_toeic_sw.php?section=' . urlencode($next_section) . '&test_session=' . urlencode($test_session) . '&setup_complete=1&mode=' . urlencode($mode),
        'section_score' => $section_score,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
