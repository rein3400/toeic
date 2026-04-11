<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['cleared' => false]);
    exit;
}

$test_session = $_GET['test_session'] ?? '';
if (!$test_session || !preg_match('/^[a-zA-Z0-9_-]+$/', $test_session)) {
    echo json_encode(['cleared' => false]);
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT integrity_score, status, review_status
    FROM proctoring_sessions
    WHERE test_session = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param("si", $test_session, $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['cleared' => false]);
    exit;
}

$cleared = ($row['review_status'] === 'cleared' && $row['status'] === 'active');

echo json_encode([
    'cleared' => $cleared,
    'integrity_score' => (int)$row['integrity_score'],
]);
