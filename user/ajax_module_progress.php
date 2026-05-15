<?php
/**
 * AJAX: Save/update module progress and unlock next module
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/learning_schema.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}
toeicEnsureLearningSchema($conn);

$input = json_decode(file_get_contents('php://input'), true);
$module_id = (int)($input['module_id'] ?? 0);
$score = (float)($input['score'] ?? 0);
$answers = $input['answers'] ?? [];
$action = $input['action'] ?? 'complete'; // start|complete

if ($module_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid module_id']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Verify user has access to this module
$stmt = $conn->prepare("
    SELECT m.id, m.curriculum_id, m.module_order, c.user_id 
    FROM learning_modules m 
    JOIN learning_curriculum c ON m.curriculum_id = c.id 
    WHERE m.id = ? AND c.user_id = ?
");
$stmt->bind_param("ii", $module_id, $user_id);
$stmt->execute();
$module = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$module) {
    echo json_encode(['success' => false, 'error' => 'Module not found or access denied']);
    exit();
}

if ($action === 'start') {
    // Check if progress exists
    $stmt = $conn->prepare("SELECT id FROM learning_progress WHERE user_id = ? AND module_id = ?");
    $stmt->bind_param("ii", $user_id, $module_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        $stmt = $conn->prepare("INSERT INTO learning_progress (user_id, module_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $module_id);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true]);
    exit();
}

// Complete module
$answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE);

$stmt = $conn->prepare("
    INSERT INTO learning_progress (user_id, module_id, score, attempts, answers_json, completed_at)
    VALUES (?, ?, ?, 1, ?, NOW())
    ON DUPLICATE KEY UPDATE 
        score = GREATEST(score, VALUES(score)),
        attempts = attempts + 1,
        answers_json = VALUES(answers_json),
        completed_at = NOW()
");
$stmt->bind_param("iids", $user_id, $module_id, $score, $answersJson);
$stmt->execute();
$stmt->close();

// Mark module as completed
$stmt = $conn->prepare("UPDATE learning_modules SET status = 'completed' WHERE id = ?");
$stmt->bind_param("i", $module_id);
$stmt->execute();
$stmt->close();

// Unlock next module
$stmt = $conn->prepare("
    UPDATE learning_modules SET status = 'available' 
    WHERE curriculum_id = ? AND module_order = ? + 1 AND status = 'locked'
");
$nextOrder = $module['module_order'];
$stmt->bind_param("ii", $module['curriculum_id'], $nextOrder);
$stmt->execute();
$unlocked = $stmt->affected_rows > 0;
$stmt->close();

echo json_encode([
    'success' => true,
    'score' => $score,
    'next_unlocked' => $unlocked,
]);
