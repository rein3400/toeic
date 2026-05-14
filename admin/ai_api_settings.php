<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$success = '';
$error = '';

$providers = [
    'Groq' => ['llms' => ['llama3-8b-8192', 'llama3-70b-8192', 'mixtral-8x7b-32768', 'gemma-7b-it', 'gemma2-9b-it']],
    'Gemini' => ['llms' => ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-pro']],
    'OpenAI' => ['llms' => ['gpt-5.4', 'gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4o']],
    'OpenRouter' => [
        'llms' => [
            'meta-llama/llama-3.1-8b-instruct:free',
            'mistralai/mistral-7b-instruct:free',
            'openai/gpt-4o-mini',
            'openai/gpt-5',
            'google/gemini-3-pro',
            'google/gemini-pro-1.5',
            'anthropic/claude-3.5-sonnet'
        ]
    ],
];

$transcriptionProviders = [
    'OpenAI' => ['models' => ['gpt-4o-transcribe', 'whisper-1']],
    'Groq' => ['models' => ['whisper-large-v3', 'whisper-large-v3-turbo', 'distil-whisper-large-v3-en']],
    'Gemini' => ['models' => ['gemini-3-flash-preview', 'gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']],
];

$scoringModelSuggestions = [];
foreach ($providers as $meta) {
    foreach ($meta['llms'] as $llm) {
        $scoringModelSuggestions[$llm] = true;
    }
}
$transcriptionModelSuggestions = [];
foreach ($transcriptionProviders as $meta) {
    foreach ($meta['models'] as $model) {
        $transcriptionModelSuggestions[$model] = true;
    }
}

function saveAiApiSetting(mysqli $conn, string $key, string $value): void {
    $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $stmt->close();
}

function normalizeAiProviderSetting(string $value, array $allowedKeys, bool $allowEmpty = true): string {
    $value = trim($value);
    if ($allowEmpty && $value === '') {
        return '';
    }
    return in_array($value, $allowedKeys, true) ? $value : '';
}

// Create settings table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);");

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_api_settings') {
    $active = $_POST['active_api'] ?? '';
    $providerSettingKeys = array_map(fn($prov) => 'ai_api_' . strtolower($prov), array_keys($providers));
    $transcriptionProviderKeys = array_map(fn($prov) => 'ai_api_' . strtolower($prov), array_keys($transcriptionProviders));

    foreach ($providers as $prov => $meta) {
        $key = $_POST[strtolower($prov) . '_key'] ?? '';
        $llm = $_POST[strtolower($prov) . '_llm'] ?? '';
        $reasoning_effort = $_POST[strtolower($prov) . '_reasoning_effort'] ?? 'none';
        $setting_key = 'ai_api_' . strtolower($prov);
        if ($prov === 'OpenAI' && $reasoning_effort === '') {
            $reasoning_effort = 'high';
        }
        $value = json_encode([
            'provider' => $prov,
            'api_key' => $key,
            'llm' => $llm,
            'reasoning_effort' => $reasoning_effort
        ]);
        saveAiApiSetting($conn, $setting_key, $value);
    }
    // Set active
    saveAiApiSetting($conn, 'active_ai_api', normalizeAiProviderSetting($active, $providerSettingKeys, false));

    // Save curriculum-specific API setting
    $curriculum_api = normalizeAiProviderSetting($_POST['curriculum_api'] ?? '', $providerSettingKeys);
    saveAiApiSetting($conn, 'curriculum_ai_api', $curriculum_api);

    // Save curriculum custom model override (for OpenRouter free-text model)
    $curriculum_custom_model = trim($_POST['curriculum_custom_model'] ?? '');
    if (!empty($curriculum_custom_model) && !empty($curriculum_api)) {
        // Store as a separate setting so curriculum can use a different model from the same provider
        saveAiApiSetting($conn, 'curriculum_ai_model_override', $curriculum_custom_model);
    } else {
        // Clear override if empty
        $conn->query("DELETE FROM site_settings WHERE setting_key = 'curriculum_ai_model_override'");
    }

    $toeic_sw_scoring_api = normalizeAiProviderSetting($_POST['toeic_sw_scoring_ai_api'] ?? '', $providerSettingKeys);
    $toeic_sw_scoring_model = trim($_POST['toeic_sw_scoring_model'] ?? 'gpt-5.5');
    $toeic_sw_transcription_api = normalizeAiProviderSetting($_POST['toeic_sw_transcription_ai_api'] ?? 'ai_api_openai', $transcriptionProviderKeys, false);
    if ($toeic_sw_transcription_api === '') {
        $toeic_sw_transcription_api = 'ai_api_openai';
    }
    $toeic_sw_transcription_model = trim($_POST['toeic_sw_transcription_model'] ?? 'gpt-4o-transcribe');
    saveAiApiSetting($conn, 'toeic_sw_scoring_ai_api', $toeic_sw_scoring_api);
    saveAiApiSetting($conn, 'toeic_sw_scoring_model', $toeic_sw_scoring_model);
    saveAiApiSetting($conn, 'toeic_sw_transcription_ai_api', $toeic_sw_transcription_api);
    saveAiApiSetting($conn, 'toeic_sw_transcription_model', $toeic_sw_transcription_model);

    $success = 'Settings saved!';
}

// Load current settings
$api_settings = [];
foreach ($providers as $prov => $meta) {
    $setting_key = 'ai_api_' . strtolower($prov);
    $load_stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
    $load_stmt->bind_param("s", $setting_key);
    $load_stmt->execute();
    $row = $load_stmt->get_result()->fetch_assoc();
    $defaultReasoning = $prov === 'OpenAI' ? 'high' : 'none';
    $data = $row ? json_decode($row['setting_value'], true) : [
        'provider' => $prov,
        'api_key' => '',
        'llm' => $meta['llms'][0],
        'reasoning_effort' => $defaultReasoning
    ];
    if (!isset($data['reasoning_effort']) || $data['reasoning_effort'] === '') {
        $data['reasoning_effort'] = $defaultReasoning;
    }
    $api_settings[$prov] = $data;
}
$active_api = getSiteSetting('active_ai_api', '');
$curriculum_api = getSiteSetting('curriculum_ai_api', '');
$curriculum_model_override = getSiteSetting('curriculum_ai_model_override', '');
$toeic_sw_scoring_api = getSiteSetting('toeic_sw_scoring_ai_api', '');
$toeic_sw_scoring_model = getSiteSetting('toeic_sw_scoring_model', 'gpt-5.5');
$toeic_sw_transcription_api = getSiteSetting('toeic_sw_transcription_ai_api', 'ai_api_openai');
$toeic_sw_transcription_model = getSiteSetting('toeic_sw_transcription_model', 'gpt-4o-transcribe');
$website_title = getWebsiteTitle();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - AI API Settings</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
        }

        .btn-test {
            background: var(--success);
            border: none;
            color: white;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-test:hover {
            background: #059669;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(16,185,129,0.3);
        }

        .connection-status {
            font-size: 0.875rem;
            margin-top: 0.5rem;
            max-width: 200px;
            word-wrap: break-word;
            padding: 0.5rem;
            border-radius: 8px;
            font-weight: 500;
        }

        .status-success {
            color: var(--success);
            background: var(--success-light);
            border: 1px solid rgba(16,185,129,0.2);
        }

        .status-error {
            color: var(--danger);
            background: var(--danger-light);
            border: 1px solid rgba(239,68,68,0.2);
        }

        .status-testing {
            color: var(--warning);
            background: var(--warning-light);
            border: 1px solid rgba(245,158,11,0.2);
        }

        .api-info {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            font-style: italic;
        }

        .provider-name {
            color: var(--primary-hover);
            font-weight: bold;
            font-size: 1.1rem;
        }

        .api-settings-table th,
        .api-settings-table td {
            vertical-align: middle;
        }

        .active-provider-cell {
            min-width: 120px;
        }

        .active-provider-option {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.7rem;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-secondary);
            cursor: pointer;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }

        .active-provider-option:hover {
            border-color: var(--primary);
            color: var(--text-primary);
        }

        .active-provider-radio {
            margin-top: 0;
        }

        .active-provider-radio:checked + .active-provider-option {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
        }

        .active-provider-row td {
            background: rgba(59, 130, 246, 0.08);
        }

        .info-card {
            background: var(--primary-light);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-card h6 {
            color: var(--primary-hover);
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="admin-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1><i class="fas fa-robot me-3"></i>AI API Settings</h1>
                        <div class="text-end">
                            <a href="settings.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to Settings
                            </a>
                        </div>
                    </div>
                </div>

                <div class="info-card">
                    <h6><i class="fas fa-info-circle me-2"></i>API Configuration Guide:</h6>
                    <ul class="mb-0">
                        <li><strong>OpenAI:</strong> Get API key from <a href="https://platform.openai.com/api-keys"
                                target="_blank" class="text-maroon">OpenAI Platform</a></li>
                        <li><strong>Gemini:</strong> Get API key from <a href="https://aistudio.google.com/app/apikey"
                                target="_blank" class="text-maroon">Google AI Studio</a></li>
                        <li><strong>Groq:</strong> Get API key from <a href="https://console.groq.com/keys"
                                target="_blank" class="text-maroon">Groq Console</a></li>
                        <li><strong>OpenRouter:</strong> Get API key from <a href="https://openrouter.ai/keys"
                                target="_blank" class="text-maroon">OpenRouter</a></li>
                    </ul>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"> <?php echo $success; ?> </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="save_api_settings">
                    <div class="content-card mb-4">
                        <h4><i class="fas fa-list me-2"></i>API Providers</h4>
                        <div class="table-container">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 api-settings-table">
                                    <thead>
                                        <tr>
                                            <th>Provider</th>
                                            <th>Active</th>
                                            <th>API Key</th>
                                            <th>LLM/Model</th>
                                            <th>Reasoning</th>
                                            <th>Test Connection</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($providers as $prov => $meta): ?>
                                            <?php
                                            $provider_slug = strtolower($prov);
                                            $provider_setting_key = 'ai_api_' . $provider_slug;
                                            $is_active_provider = $active_api === $provider_setting_key;
                                            ?>
                                            <tr class="<?php echo $is_active_provider ? 'active-provider-row' : ''; ?>">
                                                <td>
                                                    <div class="provider-name"><?php echo $prov; ?></div>
                                                    <div class="api-info">
                                                        <?php
                                                        switch ($prov) {
                                                            case 'OpenAI':
                                                                echo 'ChatGPT API';
                                                                break;
                                                            case 'Gemini':
                                                                echo 'Google AI';
                                                                break;
                                                            case 'Groq':
                                                                echo 'Fast AI Inference';
                                                                break;
                                                            case 'OpenRouter':
                                                                echo 'Multi-model API';
                                                                break;
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td class="active-provider-cell">
                                                    <div class="d-inline-flex align-items-center">
                                                        <input class="form-check-input active-provider-radio" type="radio" name="active_api"
                                                            value="<?php echo $provider_setting_key; ?>"
                                                            id="active_<?php echo $provider_slug; ?>" <?php if ($is_active_provider)
                                                                   echo 'checked'; ?>
                                                            required>
                                                        <label class="active-provider-option ms-2"
                                                            for="active_<?php echo $provider_slug; ?>">
                                                            <i class="fas fa-check-circle"></i>
                                                            <span class="active-provider-label-text"><?php echo $is_active_provider ? 'Aktif' : 'Pilih'; ?></span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td style="min-width:200px;">
                                                    <input type="password" class="form-control"
                                                        name="<?php echo strtolower($prov); ?>_key"
                                                        id="<?php echo strtolower($prov); ?>_key"
                                                        value="<?php echo htmlspecialchars($api_settings[$prov]['api_key']); ?>"
                                                        placeholder="Enter API key...">
                                                </td>
                                                <td style="min-width:180px;">
                                                    <?php if ($prov === 'OpenRouter'): ?>
                                                        <input type="text" class="form-control"
                                                            name="<?php echo strtolower($prov); ?>_llm"
                                                            id="<?php echo strtolower($prov); ?>_llm"
                                                            value="<?php echo htmlspecialchars($api_settings[$prov]['llm']); ?>"
                                                            placeholder="e.g. google/gemini-3-flash-preview">
                                                    <?php else: ?>
                                                        <select class="form-select" name="<?php echo strtolower($prov); ?>_llm"
                                                            id="<?php echo strtolower($prov); ?>_llm">
                                                            <?php foreach ($meta['llms'] as $llm): ?>
                                                                <option value="<?php echo $llm; ?>" <?php if ($api_settings[$prov]['llm'] == $llm)
                                                                       echo 'selected'; ?>>
                                                                    <?php echo $llm; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="min-width:140px;">
                                                    <select class="form-select"
                                                        name="<?php echo strtolower($prov); ?>_reasoning_effort"
                                                        id="<?php echo strtolower($prov); ?>_reasoning_effort"
                                                        <?php echo $prov === 'OpenAI' ? '' : 'disabled'; ?>>
                                                        <?php foreach (['none', 'low', 'medium', 'high', 'xhigh'] as $effort): ?>
                                                            <option value="<?php echo $effort; ?>" <?php if (($api_settings[$prov]['reasoning_effort'] ?? 'none') === $effort) echo 'selected'; ?>>
                                                                <?php echo strtoupper($effort); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="api-info">
                                                        <?php echo $prov === 'OpenAI' ? 'Dipakai untuk GPT-5.*' : 'Tidak dipakai untuk provider ini'; ?>
                                                    </div>
                                                </td>
                                                <td style="min-width:150px;">
                                                    <button type="button" class="btn btn-test btn-sm"
                                                        onclick="testConnection('<?php echo strtolower($prov); ?>')"
                                                        id="test_<?php echo strtolower($prov); ?>">
                                                        <i class="fas fa-plug me-1"></i>Test
                                                    </button>
                                                    <div class="connection-status"
                                                        id="status_<?php echo strtolower($prov); ?>"></div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save
                                Settings</button>
                            <div class="api-info mt-2">Pilih provider aktif di kolom Active, lalu klik Save Settings.</div>
                        </div>
                    </div>

                    <div class="content-card mb-4">
                        <h4><i class="fas fa-microphone-lines me-2"></i>TOEIC SW Evaluation Models</h4>
                        <div class="row g-3">
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label fw-bold">Scoring Provider</label>
                                <select class="form-select" name="toeic_sw_scoring_ai_api">
                                    <option value="" <?php if ($toeic_sw_scoring_api === '') echo 'selected'; ?>>Use active provider</option>
                                    <?php foreach ($providers as $prov => $meta): ?>
                                        <?php $provider_key = 'ai_api_' . strtolower($prov); ?>
                                        <option value="<?php echo $provider_key; ?>" <?php if ($toeic_sw_scoring_api === $provider_key) echo 'selected'; ?>>
                                            <?php echo $prov; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="api-info mt-1">Kosong berarti scoring mengikuti provider aktif.</div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label fw-bold">Scoring Model</label>
                                <input type="text" class="form-control" name="toeic_sw_scoring_model"
                                    value="<?php echo htmlspecialchars($toeic_sw_scoring_model); ?>"
                                    list="toeic_sw_scoring_models"
                                    placeholder="e.g. gpt-5.5 or google/gemini-3-pro">
                                <datalist id="toeic_sw_scoring_models">
                                    <?php foreach (array_keys($scoringModelSuggestions) as $model): ?>
                                        <option value="<?php echo htmlspecialchars($model); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <div class="api-info mt-1">Free-text supaya bisa ikut model provider mana pun.</div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label fw-bold">Transcription Provider</label>
                                <select class="form-select" name="toeic_sw_transcription_ai_api">
                                    <?php foreach ($transcriptionProviders as $prov => $meta): ?>
                                        <?php $provider_key = 'ai_api_' . strtolower($prov); ?>
                                        <option value="<?php echo $provider_key; ?>" <?php if ($toeic_sw_transcription_api === $provider_key) echo 'selected'; ?>>
                                            <?php echo $prov; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="api-info mt-1">Audio transcription didukung via OpenAI, Groq, atau Gemini.</div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label fw-bold">Transcription Model</label>
                                <input type="text" class="form-control" name="toeic_sw_transcription_model"
                                    value="<?php echo htmlspecialchars($toeic_sw_transcription_model); ?>"
                                    list="toeic_sw_transcription_models"
                                    placeholder="e.g. whisper-large-v3">
                                <datalist id="toeic_sw_transcription_models">
                                    <?php foreach (array_keys($transcriptionModelSuggestions) as $model): ?>
                                        <option value="<?php echo htmlspecialchars($model); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <div class="api-info mt-1">Model bebas selama provider terpilih mendukung audio.</div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Evaluation Models</button>
                        </div>
                    </div>

                    <!-- Curriculum AI Model Section -->
                    <div class="content-card mb-4">
                        <h4><i class="fas fa-graduation-cap me-2"></i>Curriculum Generation — AI Model</h4>
                        <p class="text-muted mb-3">Pilih model AI khusus untuk generate kurikulum belajar (syllabus, modul, latihan). Jika tidak di-set, akan menggunakan model default di atas.</p>

                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">API Provider untuk Kurikulum</label>
                                <select class="form-select" name="curriculum_api" id="curriculum_api" onchange="toggleCurriculumModel()">
                                    <option value="">— Gunakan Default (sama dengan Content) —</option>
                                    <?php foreach ($providers as $prov => $meta): ?>
                                        <option value="ai_api_<?php echo strtolower($prov); ?>"
                                            <?php if ($curriculum_api === 'ai_api_' . strtolower($prov)) echo 'selected'; ?>>
                                            <?php echo $prov; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="api-info mt-1">Provider yang dipilih harus sudah punya API key yang valid di tabel di atas.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Model Override <small class="text-muted">(opsional)</small></label>
                                <input type="text" class="form-control" name="curriculum_custom_model" id="curriculum_custom_model"
                                    value="<?php echo htmlspecialchars($curriculum_model_override); ?>"
                                    placeholder="e.g. google/gemini-2.5-flash-preview-05-20">
                                <div class="api-info mt-1">Kosongkan untuk pakai model default dari provider. Isi untuk override dengan model lain (berguna untuk OpenRouter).</div>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div id="curriculum_status" class="connection-status w-100 text-center">
                                    <?php if (!empty($curriculum_api)): ?>
                                        <span class="text-success"><i class="fas fa-check-circle"></i> Active</span>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-minus-circle"></i> Using default</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        function testConnection(provider) {
            const btn = document.getElementById('test_' + provider);
            const status = document.getElementById('status_' + provider);
            const apiKey = document.getElementById(provider + '_key').value;
            const llm = document.getElementById(provider + '_llm').value;
            const reasoningField = document.getElementById(provider + '_reasoning_effort');
            const reasoningEffort = reasoningField ? reasoningField.value : 'none';

            if (!apiKey.trim()) {
                status.innerHTML = '<i class="fas fa-exclamation-triangle"></i> API Key required';
                status.className = 'connection-status status-error';
                return;
            }

            // Update button state
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Testing...';
            status.innerHTML = '<i class="fas fa-clock"></i> Testing connection...';
            status.className = 'connection-status status-testing';

            // Make AJAX request to test connection
            fetch('ajax_test_api_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'provider=' + encodeURIComponent(provider) +
                    '&api_key=' + encodeURIComponent(apiKey) +
                    '&llm=' + encodeURIComponent(llm) +
                    '&reasoning_effort=' + encodeURIComponent(reasoningEffort)
            })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-plug me-1"></i>Test';

                    if (data.success) {
                        status.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                        status.className = 'connection-status status-success';
                        if (data.response_preview) {
                            status.innerHTML += '<br><small>' + data.response_preview + '</small>';
                        }
                    } else {
                        status.innerHTML = '<i class="fas fa-times-circle"></i> ' + (data.error || 'Connection failed');
                        status.className = 'connection-status status-error';
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-plug me-1"></i>Test';
                    status.innerHTML = '<i class="fas fa-times-circle"></i> Network error: ' + error.message;
                    status.className = 'connection-status status-error';
                });
        }

        function toggleCurriculumModel() {
            const sel = document.getElementById('curriculum_api');
            const status = document.getElementById('curriculum_status');
            if (sel.value) {
                status.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-circle"></i> Save to apply</span>';
            } else {
                status.innerHTML = '<span class="text-muted"><i class="fas fa-minus-circle"></i> Using default</span>';
            }
        }

        function updateActiveProviderLabels() {
            document.querySelectorAll('input[name="active_api"]').forEach(input => {
                const labelText = document.querySelector('label[for="' + input.id + '"] .active-provider-label-text');
                const row = input.closest('tr');

                if (labelText) {
                    labelText.textContent = input.checked ? 'Aktif' : 'Pilih';
                }

                if (row) {
                    row.classList.toggle('active-provider-row', input.checked);
                }
            });
        }

        // Show/hide API keys
        document.addEventListener('DOMContentLoaded', function () {
            updateActiveProviderLabels();
            document.querySelectorAll('input[name="active_api"]').forEach(input => {
                input.addEventListener('change', updateActiveProviderLabels);
            });

            const apiKeyInputs = document.querySelectorAll('input[type="password"]');
            apiKeyInputs.forEach(input => {
                const toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'btn btn-outline-secondary btn-sm ms-2';
                toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
                toggleBtn.onclick = function () {
                    if (input.type === 'password') {
                        input.type = 'text';
                        toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
                    } else {
                        input.type = 'password';
                        toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
                    }
                };
                input.parentNode.appendChild(toggleBtn);
            });
        });
    </script>
</body>

</html>
