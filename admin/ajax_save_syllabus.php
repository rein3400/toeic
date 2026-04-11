<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$syllabus_id = $_POST['syllabus_id'] ?? 0;
if (!$syllabus_id) {
    echo json_encode(['success' => false, 'error' => 'ID required']);
    exit;
}

// Construct the JSON structure from POST data
$syllabus_data = [
    'analysis' => $_POST['analysis'] ?? '',
    'weeks' => [],
    'recommendations' => $_POST['recommendations'] ?? []
];

// Process weeks
if (isset($_POST['weeks']) && is_array($_POST['weeks'])) {
    foreach ($_POST['weeks'] as $week) {
        $weekData = [
            'week' => $week['week'],
            'theme' => $week['theme'],
            'activities' => []
        ];
        
        if (isset($week['activities']) && is_array($week['activities'])) {
            foreach ($week['activities'] as $activity) {
                $weekData['activities'][] = [
                    'day' => $activity['day'],
                    'task' => $activity['task']
                ];
            }
        }
        $syllabus_data['weeks'][] = $weekData;
    }
}

$json_content = json_encode($syllabus_data);

// Update database
$stmt = $conn->prepare("UPDATE user_syllabus SET syllabus_content = ? WHERE id = ?");
$stmt->bind_param("si", $json_content, $syllabus_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>