<?php
// api/log_anomaly.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id'])) exit;

$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? 'unknown';
$details = $input['details'] ?? '';

$allowed = [
    'tab_switch',
    'clipboard_copy',
    'clipboard_paste',
    'right_click',
    'window_blur',
    'audio_replay_attempt',
    'devtools_open',
    'window_resize',
    'multi_click'
];

if (!in_array($type, $allowed, true)) {
    $type = 'tab_switch';
}

$stmt = $conn->prepare("INSERT INTO exam_anomalies (user_id, test_session, event_type, details) VALUES (?, ?, ?, ?)");
$session = $_SESSION['test_session'] ?? ($_SESSION['toeic_test_session'] ?? 'unknown');
$stmt->bind_param("isss", $_SESSION['user_id'], $session, $type, $details);
$stmt->execute();
?>
