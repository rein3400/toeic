<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$new_active_api = $_POST['new_active_api'] ?? '';

if (empty($new_active_api)) {
    echo json_encode(['success' => false, 'error' => 'No API setting specified']);
    exit;
}

// Validate that the new API setting exists and has valid configuration
$api_data = json_decode(getSiteSetting($new_active_api, ''), true);

if (empty($api_data) || empty($api_data['provider']) || empty($api_data['api_key']) || empty($api_data['llm'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid API configuration']);
    exit;
}

// Update the active API setting
try {
    $stmt = $conn->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'active_ai_api'");
    $stmt->bind_param("s", $new_active_api);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Successfully switched to ' . $api_data['provider'],
            'provider' => $api_data['provider'],
            'model' => $api_data['llm']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update database']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>