<?php
/**
 * Admin Proctoring Settings
 * Configure AI proctoring behavior and thresholds
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Create proctoring_settings table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS proctoring_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    data_type VARCHAR(20) DEFAULT 'string',
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Default settings
$defaults = [
    'proctoring_enabled' => ['value' => '1', 'type' => 'boolean', 'desc' => 'Enable/disable proctoring globally'],
    'proctoring_ai_provider' => ['value' => 'openrouter', 'type' => 'string', 'desc' => 'AI provider for proctoring (uses active_ai_api if empty)'],
    'proctoring_ai_model' => ['value' => 'meta-llama/llama-2-70b-chat', 'type' => 'string', 'desc' => 'AI model for proctoring analysis'],
    'integrity_threshold' => ['value' => '40', 'type' => 'integer', 'desc' => 'Minimum integrity score (0-100) before auto-termination'],
    'ai_timeout_ms' => ['value' => '5000', 'type' => 'integer', 'desc' => 'Timeout for AI analysis requests (milliseconds)'],
    'snapshot_on_high_severity' => ['value' => '1', 'type' => 'boolean', 'desc' => 'Capture snapshot on high-severity events'],
    'retention_days' => ['value' => '7', 'type' => 'integer', 'desc' => 'Days to retain proctoring recordings'],
];

// Seed defaults if missing
foreach ($defaults as $key => $def) {
    $conn->query("INSERT IGNORE INTO proctoring_settings (setting_key, setting_value, data_type, description) 
                  VALUES ('$key', '{$def['value']}', '{$def['type']}', '{$def['desc']}')");
}

// Load current settings
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value, data_type FROM proctoring_settings");
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = [
        'value' => $row['setting_value'],
        'type' => $row['data_type']
    ];
}

$website_title = getWebsiteTitle();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo csrfMeta(); ?>
    <title><?php echo htmlspecialchars($website_title); ?> - Proctoring Settings</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .settings-card { border-radius: 12px; }
        .form-switch .form-check-input { width: 3em; }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 1100; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row g-0">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 admin-content p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1"><i class="fas fa-shield-alt text-primary me-2"></i>Proctoring Settings</h4>
                    <p class="text-muted mb-0 small">Configure AI-powered proctoring behavior and security thresholds</p>
                </div>
                <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveSettings()">
                    <i class="fas fa-save me-1"></i> Save Settings
                </button>
            </div>

            <!-- Status Toast -->
            <div class="toast-container">
                <div class="toast align-items-center border-0" id="statusToast" role="alert">
                    <div class="d-flex">
                        <div class="toast-body" id="toastMessage"></div>
                        <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>

            <form id="settingsForm">
                <div class="row">
                    <!-- General Settings -->
                    <div class="col-md-6 mb-4">
                        <div class="card settings-card h-100">
                            <div class="card-header border-bottom">
                                <h6 class="mb-0"><i class="fas fa-cog text-secondary me-2"></i>General</h6>
                            </div>
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="proctoring_enabled" 
                                           <?php echo ($settings['proctoring_enabled']['value'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="proctoring_enabled">
                                        <strong>Enable Proctoring</strong>
                                        <br><small class="text-muted">Globally enable/disable proctoring for all tests</small>
                                    </label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><strong>Integrity Threshold</strong></label>
                                    <input type="number" class="form-control" id="integrity_threshold" 
                                           value="<?php echo htmlspecialchars($settings['integrity_threshold']['value'] ?? '40'); ?>" 
                                           min="0" max="100">
                                    <small class="text-muted">Auto-terminate if integrity score drops below this (0-100)</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><strong>Recording Retention (days)</strong></label>
                                    <input type="number" class="form-control" id="retention_days" 
                                           value="<?php echo htmlspecialchars($settings['retention_days']['value'] ?? '7'); ?>" 
                                           min="1" max="90">
                                    <small class="text-muted">How long to keep proctoring video recordings</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI Settings -->
                    <div class="col-md-6 mb-4">
                        <div class="card settings-card h-100">
                            <div class="card-header border-bottom">
                                <h6 class="mb-0"><i class="fas fa-robot text-info me-2"></i>AI Configuration</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label"><strong>AI Provider Override</strong></label>
                                    <input type="text" class="form-control" id="proctoring_ai_provider" 
                                           value="<?php echo htmlspecialchars($settings['proctoring_ai_provider']['value'] ?? ''); ?>" 
                                           placeholder="Leave empty to use active AI API setting">
                                    <small class="text-muted">Optional: openrouter, openai, groq, gemini</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><strong>AI Model</strong></label>
                                    <input type="text" class="form-control" id="proctoring_ai_model" 
                                           value="<?php echo htmlspecialchars($settings['proctoring_ai_model']['value'] ?? ''); ?>">
                                    <small class="text-muted">Model to use for proctoring analysis</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><strong>AI Timeout (ms)</strong></label>
                                    <input type="number" class="form-control" id="ai_timeout_ms" 
                                           value="<?php echo htmlspecialchars($settings['ai_timeout_ms']['value'] ?? '5000'); ?>" 
                                           min="1000" max="30000" step="500">
                                    <small class="text-muted">Timeout for AI analysis requests (1000-30000ms)</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Behavior Settings -->
                    <div class="col-md-6 mb-4">
                        <div class="card settings-card h-100">
                            <div class="card-header border-bottom">
                                <h6 class="mb-0"><i class="fas fa-camera text-warning me-2"></i>Behavior</h6>
                            </div>
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="snapshot_on_high_severity" 
                                           <?php echo ($settings['snapshot_on_high_severity']['value'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="snapshot_on_high_severity">
                                        <strong>Snapshot on High Severity</strong>
                                        <br><small class="text-muted">Capture snapshot when high-severity events occur</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Info Card -->
            <div class="card settings-card mt-2">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-info-circle text-info me-3 mt-1"></i>
                        <div>
                            <strong>How Proctoring Works</strong>
                            <p class="mb-0 small text-muted">
                                AI proctoring monitors user behavior during tests. When events occur (tab switches, blur, etc.), 
                                they are logged and periodically sent to the AI for analysis. The AI returns a risk score and 
                                recommended action. If the integrity score drops below the threshold, the exam is automatically terminated.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
function saveSettings() {
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

    const data = new FormData();
    data.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    data.append('proctoring_enabled', document.getElementById('proctoring_enabled').checked ? '1' : '0');
    data.append('integrity_threshold', document.getElementById('integrity_threshold').value);
    data.append('retention_days', document.getElementById('retention_days').value);
    data.append('proctoring_ai_provider', document.getElementById('proctoring_ai_provider').value);
    data.append('proctoring_ai_model', document.getElementById('proctoring_ai_model').value);
    data.append('ai_timeout_ms', document.getElementById('ai_timeout_ms').value);
    data.append('snapshot_on_high_severity', document.getElementById('snapshot_on_high_severity').checked ? '1' : '0');

    fetch('ajax_save_proctoring_settings.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i> Save Settings';
        
        const toast = document.getElementById('statusToast');
        const msg = document.getElementById('toastMessage');
        
        if (res.success) {
            toast.classList.remove('bg-danger');
            toast.classList.add('bg-success', 'text-white');
            msg.textContent = res.message || 'Settings saved successfully!';
        } else {
            toast.classList.remove('bg-success');
            toast.classList.add('bg-danger', 'text-white');
            msg.textContent = res.error || 'Failed to save settings';
        }
        
        new bootstrap.Toast(toast).show();
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i> Save Settings';
        
        const toast = document.getElementById('statusToast');
        toast.classList.add('bg-danger', 'text-white');
        document.getElementById('toastMessage').textContent = 'Network error: ' + err.message;
        new bootstrap.Toast(toast).show();
    });
}
</script>
</body>
</html>
