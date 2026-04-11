<?php
/**
 * AJAX Save Proctoring Settings
 * Saves proctoring configuration to database
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!validateCsrfToken()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate required table exists
$conn->query("CREATE TABLE IF NOT EXISTS proctoring_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    data_type VARCHAR(20) DEFAULT 'string',
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Settings to save with validation
$settings = [
    'proctoring_enabled' => [
        'value' => ($_POST['proctoring_enabled'] ?? '0') === '1' ? '1' : '0',
        'type' => 'boolean'
    ],
    'proctoring_ai_provider' => [
        'value' => trim($_POST['proctoring_ai_provider'] ?? ''),
        'type' => 'string'
    ],
    'proctoring_ai_model' => [
        'value' => trim($_POST['proctoring_ai_model'] ?? ''),
        'type' => 'string'
    ],
    'integrity_threshold' => [
        'value' => max(0, min(100, (int)($_POST['integrity_threshold'] ?? 40))),
        'type' => 'integer'
    ],
    'ai_timeout_ms' => [
        'value' => max(1000, min(30000, (int)($_POST['ai_timeout_ms'] ?? 5000))),
        'type' => 'integer'
    ],
    'snapshot_on_high_severity' => [
        'value' => ($_POST['snapshot_on_high_severity'] ?? '1') === '1' ? '1' : '0',
        'type' => 'boolean'
    ],
    'retention_days' => [
        'value' => max(1, min(90, (int)($_POST['retention_days'] ?? 7))),
        'type' => 'integer'
    ],
];

try {
    $stmt = $conn->prepare("INSERT INTO proctoring_settings (setting_key, setting_value, data_type) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), data_type = VALUES(data_type)");
    
    foreach ($settings as $key => $data) {
        $stmt->bind_param("sss", $key, $data['value'], $data['type']);
        $stmt->execute();
    }
    
    echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
} catch (Exception $e) {
    error_log("Failed to save proctoring settings: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
