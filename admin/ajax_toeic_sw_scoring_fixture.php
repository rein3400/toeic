<?php
header('Content-Type: application/json');

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/toeic_sw_scorer.php';
require_once '../includes/toeic_sw_scoring_fixture.php';

if (function_exists('set_time_limit')) {
    set_time_limit(120);
}

try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
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

    $testSession = trim((string)($input['test_session'] ?? ''));
    if ($testSession === '' || strpos($testSession, 'toeic_sw_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid TOEIC SW session']);
        exit();
    }

    $action = (string)($input['action'] ?? 'status');
    if ($action === 'seed') {
        $result = toeicSwFixtureSeedSession($conn, $testSession);
    } elseif ($action === 'score_next') {
        $result = toeicSwFixtureScoreNext($conn, $testSession);
    } elseif ($action === 'status') {
        $result = toeicSwFixtureStatus($conn, $testSession);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unsupported action']);
        exit();
    }

    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
