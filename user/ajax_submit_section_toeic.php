<?php
/**
 * AJAX: Submit section and calculate score for TOEIC
 * Mirrors ajax_submit_section_2026.php pattern
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/toeic_scorer.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_helper.php';

header('Content-Type: application/json');

try {

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

$input        = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit();
}

$test_session = trim($input['test_session'] ?? '');
$section      = trim($input['section'] ?? '');

if (!$test_session || !$section) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

$valid_sections = ['listening', 'reading'];
if (!in_array($section, $valid_sections, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid section']);
    exit();
}

ensureTOEICSessionModeColumns($conn);

// Verify session ownership
$stmt = $conn->prepare("SELECT id, status, current_section, practice_mode, target_part FROM toeic_test_sessions WHERE test_session = ? AND user_id = ?");
$stmt->bind_param("si", $test_session, $_SESSION['user_id']);
$stmt->execute();
$sessionRow = $stmt->get_result()->fetch_assoc();
if (!$sessionRow) {
    echo json_encode(['success' => false, 'error' => 'Session not found']);
    exit();
}
$stmt->close();

if (($sessionRow['status'] ?? '') !== 'active') {
    echo json_encode(['success' => false, 'error' => 'Session is no longer active']);
    exit();
}

$isPracticeMode = !empty($sessionRow['practice_mode']);
$targetPart = preg_replace('/[^1-7]/', '', (string)($sessionRow['target_part'] ?? ''));
$isLegacyPartPractice = $isPracticeMode && $targetPart !== '';
$redirect_suffix = $isPracticeMode
    ? ('&mode=prep' . ($targetPart !== '' ? '&part=' . urlencode($targetPart) : ''))
    : '&mode=full';

$expectedSection = in_array(($sessionRow['current_section'] ?? ''), $valid_sections, true)
    ? $sessionRow['current_section']
    : 'listening';
if ($isLegacyPartPractice) {
    $practiceConfig = getTOEICPracticeConfig($targetPart);
    if (!$practiceConfig || empty($practiceConfig['section'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid practice part']);
        exit();
    }
    $expectedSection = $practiceConfig['section'];
}

if ($section !== $expectedSection) {
    echo json_encode(['success' => false, 'error' => 'Section is no longer active']);
    exit();
}

if ($isLegacyPartPractice) {
    $sourceTable = $section === 'listening' ? 'toeic_soal_listening' : 'toeic_soal_reading';
    $stmt = $conn->prepare("
        SELECT tq.question_id, tq.user_answer, src.jawaban_benar
        FROM toeic_test_questions tq
        JOIN {$sourceTable} src ON tq.question_id = src.id_soal
        WHERE tq.test_session = ? AND tq.section = ?
    ");
    $stmt->bind_param("ss", $test_session, $section);
    $stmt->execute();
    $result = $stmt->get_result();

    $correct = 0;
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $total++;
        $isCorrect = (!empty($row['user_answer']) && strtoupper(trim($row['user_answer'])) === strtoupper(trim($row['jawaban_benar']))) ? 1 : 0;
        if ($isCorrect) {
            $correct++;
        }
        $update = $conn->prepare("
            UPDATE toeic_test_questions
            SET is_correct = ?
            WHERE test_session = ? AND section = ? AND question_id = ?
        ");
        $update->bind_param("issi", $isCorrect, $test_session, $section, $row['question_id']);
        $update->execute();
        $update->close();
    }
    $stmt->close();

    $scoreData = [
        'raw' => $correct,
        'total' => $total,
        'percentage' => $total > 0 ? round(($correct / $total) * 100, 1) : 0,
    ];

    $updateStmt = $conn->prepare("UPDATE toeic_test_sessions SET status = 'completed', completed_at = NOW() WHERE test_session = ?");
    $updateStmt->bind_param("s", $test_session);
    $updateStmt->execute();
    $updateStmt->close();

    if (($_SESSION['toeic_test_session'] ?? null) === $test_session || ($_SESSION['test_session'] ?? null) === $test_session) {
        unset($_SESSION['toeic_test_session'], $_SESSION['test_session'], $_SESSION['current_section'], $_SESSION['section_start_time']);
        if (isset($_SESSION['toeic_section_start_times']) && is_array($_SESSION['toeic_section_start_times'])) {
            unset($_SESSION['toeic_section_start_times'][$test_session . ':listening'], $_SESSION['toeic_section_start_times'][$test_session . ':reading']);
        }
    }

    echo json_encode([
        'success' => true,
        'redirect' => 'result_toeic.php?session=' . urlencode($test_session) . $redirect_suffix,
        'section_score' => $scoreData,
    ]);
    exit();
}

$scorer = new ToeicScorer($conn);
$scoreData = $scorer->scoreAndSaveSection($test_session, $section);

// Determine next section
$sectionIndex = array_search($section, $valid_sections);
$isLastSection = ($sectionIndex === count($valid_sections) - 1);

if ($isLastSection) {
    // Final section (reading) - save complete results
    $results = $scorer->saveResults($test_session, $_SESSION['user_id'], !$isPracticeMode);

    // Update session status to completed
    $updateStmt = $conn->prepare("UPDATE toeic_test_sessions SET status = 'completed', completed_at = NOW() WHERE test_session = ?");
    $updateStmt->bind_param("s", $test_session);
    $updateStmt->execute();
    $updateStmt->close();

    if (($_SESSION['toeic_test_session'] ?? null) === $test_session || ($_SESSION['test_session'] ?? null) === $test_session) {
        unset($_SESSION['toeic_test_session'], $_SESSION['test_session'], $_SESSION['current_section'], $_SESSION['section_start_time']);
        if (isset($_SESSION['toeic_section_start_times']) && is_array($_SESSION['toeic_section_start_times'])) {
            unset($_SESSION['toeic_section_start_times'][$test_session . ':listening'], $_SESSION['toeic_section_start_times'][$test_session . ':reading']);
        }
    }

        echo json_encode([
            'success'       => true,
            'redirect'      => 'result_toeic.php?session=' . urlencode($test_session) . $redirect_suffix,
            'section_score' => $scoreData,
            'final_score'   => $results,
        ]);
} else {
    // Not the last section - advance to next section
    $nextSection = $valid_sections[$sectionIndex + 1];

    // Update current_section in session
    $updateStmt = $conn->prepare("UPDATE toeic_test_sessions SET current_section = ? WHERE test_session = ?");
    $updateStmt->bind_param("ss", $nextSection, $test_session);
    $updateStmt->execute();
    $updateStmt->close();

    $_SESSION['current_section'] = $nextSection;
    $_SESSION['section_start_time'] = time();
    if (!isset($_SESSION['toeic_section_start_times']) || !is_array($_SESSION['toeic_section_start_times'])) {
        $_SESSION['toeic_section_start_times'] = [];
    }
    $_SESSION['toeic_section_start_times'][$test_session . ':' . $nextSection] = $_SESSION['section_start_time'];

        echo json_encode([
            'success'       => true,
            'redirect'      => 'test_toeic.php?section=' . $nextSection . '&test_session=' . urlencode($test_session) . '&setup_complete=1' . $redirect_suffix,
            'section_score' => $scoreData,
        ]);
}

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
    exit();
}
