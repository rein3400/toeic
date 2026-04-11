<?php
/**
 * AJAX: Generate content for a single learning module
 * Called when user opens a module that hasn't been generated yet
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/curriculum_generator.php';

header('Content-Type: application/json');
set_time_limit(120);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$module_id = (int)($input['module_id'] ?? 0);

if ($module_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid module_id']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Verify ownership
$stmt = $conn->prepare("
    SELECT m.id FROM learning_modules m 
    JOIN learning_curriculum c ON m.curriculum_id = c.id 
    WHERE m.id = ? AND c.user_id = ?
");
$stmt->bind_param("ii", $module_id, $user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}
$stmt->close();

try {
    $generator = new CurriculumGenerator($conn);
    $result = $generator->fillModuleContent($module_id);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
