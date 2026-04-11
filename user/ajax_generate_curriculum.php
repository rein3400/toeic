<?php
/**
 * AJAX: Generate personalized learning curriculum
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/curriculum_generator.php';

header('Content-Type: application/json');
set_time_limit(120); // 2 minutes - generates syllabus only, modules are generated on-demand
ini_set('memory_limit', '512M');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$test_session = $input['test_session'] ?? '';

if (empty($test_session)) {
    echo json_encode(['success' => false, 'error' => 'Missing test_session']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Verify user owns this test session (or is admin)
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
if (!$is_admin) {
    $stmt = $conn->prepare("SELECT 1 FROM toeic_test_sessions WHERE user_id = ? AND test_session = ?");
    $stmt->bind_param("is", $user_id, $test_session);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit();
    }
    $stmt->close();
}

try {
    $generator = new CurriculumGenerator($conn);
    $result = $generator->generate($user_id, $test_session);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
