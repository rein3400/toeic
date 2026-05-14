<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_pricing_helper.php';

// Get website settings
$website_title = getWebsiteTitle();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$error = '';
$success = '';

// Create settings table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Function to get setting value
function getSetting($key, $default = '')
{
    global $conn;
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }
    return $default;
}

// Function to save setting
function saveSetting($key, $value)
{
    global $conn;
    $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("ss", $key, $value);
    return $stmt->execute();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'update_settings') {
            $website_title = trim($_POST['website_title']);

            if (empty($website_title)) {
                $error = "Website title is required.";
            } else {
                // Save website title
                if (saveSetting('website_title', $website_title)) {
                    $success = "Website title updated successfully!";
                } else {
                    $error = "Failed to update website title.";
                }
            }
        } elseif ($_POST['action'] == 'upload_logo') {
            if (isset($_FILES['logo'])) {
                $upload_error = $_FILES['logo']['error'];

                if ($upload_error == 0) {
                    $upload_dir = '../uploads/settings/';

                    // Create directory if it doesn't exist
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg'];

                    if (in_array($file_extension, $allowed_extensions)) {
                        // Check file size (max 2MB)
                        if ($_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                            $file_name = 'logo.' . $file_extension;
                            $file_path = $upload_dir . $file_name;

                            // Delete old logo if exists
                            $old_logo = getSetting('website_logo');
                            if (!empty($old_logo) && file_exists('../' . $old_logo)) {
                                unlink('../' . $old_logo);
                            }

                            if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
                                $relative_path = 'uploads/settings/' . $file_name;
                                if (saveSetting('website_logo', $relative_path)) {
                                    $success = "Logo uploaded successfully! File: " . $file_name;
                                } else {
                                    $error = "Failed to save logo path to database.";
                                }
                            } else {
                                $error = "Failed to upload logo file. Check directory permissions.";
                            }
                        } else {
                            $error = "Logo file size must be less than 2MB. Current size: " . round($_FILES['logo']['size'] / 1024 / 1024, 2) . "MB";
                        }
                    } else {
                        $error = "Invalid file type: .$file_extension. Only JPG, JPEG, PNG, GIF, and SVG files are allowed.";
                    }
                } else {
                    $upload_errors = [
                        1 => 'File too large (exceeds upload_max_filesize)',
                        2 => 'File too large (exceeds MAX_FILE_SIZE)',
                        3 => 'File only partially uploaded',
                        4 => 'No file was uploaded',
                        6 => 'Missing temporary folder',
                        7 => 'Failed to write file to disk',
                        8 => 'File upload stopped by extension'
                    ];
                    $error = "Upload error: " . ($upload_errors[$upload_error] ?? "Unknown error ($upload_error)");
                }
            } else {
                $error = "No file selected for upload.";
            }
        } elseif ($_POST['action'] == 'upload_favicon') {
            if (isset($_FILES['favicon'])) {
                $upload_error = $_FILES['favicon']['error'];

                if ($upload_error == 0) {
                    $upload_dir = '../uploads/settings/';

                    // Create directory if it doesn't exist
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $file_extension = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['ico', 'png', 'jpg', 'jpeg', 'gif'];

                    if (in_array($file_extension, $allowed_extensions)) {
                        // Check file size (max 1MB)
                        if ($_FILES['favicon']['size'] <= 1024 * 1024) {
                            $file_name = 'favicon.' . $file_extension;
                            $file_path = $upload_dir . $file_name;

                            // Delete old favicon if exists
                            $old_favicon = getSetting('website_favicon');
                            if (!empty($old_favicon) && file_exists('../' . $old_favicon)) {
                                unlink('../' . $old_favicon);
                            }

                            if (move_uploaded_file($_FILES['favicon']['tmp_name'], $file_path)) {
                                $relative_path = 'uploads/settings/' . $file_name;
                                if (saveSetting('website_favicon', $relative_path)) {
                                    $success = "Favicon uploaded successfully! File: " . $file_name;
                                } else {
                                    $error = "Failed to save favicon path to database.";
                                }
                            } else {
                                $error = "Failed to upload favicon file. Check directory permissions.";
                            }
                        } else {
                            $error = "Favicon file size must be less than 1MB. Current size: " . round($_FILES['favicon']['size'] / 1024 / 1024, 2) . "MB";
                        }
                    } else {
                        $error = "Invalid file type: .$file_extension. Only ICO, PNG, JPG, JPEG, and GIF files are allowed.";
                    }
                } else {
                    $upload_errors = [
                        1 => 'File too large (exceeds upload_max_filesize)',
                        2 => 'File too large (exceeds MAX_FILE_SIZE)',
                        3 => 'File only partially uploaded',
                        4 => 'No file was uploaded',
                        6 => 'Missing temporary folder',
                        7 => 'Failed to write file to disk',
                        8 => 'File upload stopped by extension'
                    ];
                    $error = "Upload error: " . ($upload_errors[$upload_error] ?? "Unknown error ($upload_error)");
                }
            } else {
                $error = "No file selected for upload.";
            }

        } elseif ($_POST['action'] == 'update_tripay') {
            // Payment routing plus optional Tripay gateway settings.
            $payment_mode = ($_POST['payment_mode'] ?? 'direct_bank') === 'tripay' ? 'tripay' : 'direct_bank';
            $bank_name = trim($_POST['bank_name'] ?? '');
            $bank_account_number = trim($_POST['bank_account_number'] ?? '');
            $bank_account_holder = trim($_POST['bank_account_holder'] ?? '');
            $bank_transfer_instructions = trim($_POST['bank_transfer_instructions'] ?? '');
            $tripay_api_key = trim($_POST['tripay_api_key']);
            $tripay_private_key = trim($_POST['tripay_private_key']);
            $tripay_merchant_code = trim($_POST['tripay_merchant_code']);
            $tripay_is_production = isset($_POST['tripay_is_production']) ? '1' : '0';

            saveSetting('payment_mode', $payment_mode);
            saveSetting('bank_name', $bank_name);
            saveSetting('bank_account_number', $bank_account_number);
            saveSetting('bank_account_holder', $bank_account_holder);
            saveSetting('bank_transfer_instructions', $bank_transfer_instructions);
            saveSetting('tripay_api_key', $tripay_api_key);
            saveSetting('tripay_private_key', $tripay_private_key);
            saveSetting('tripay_merchant_code', $tripay_merchant_code);
            saveSetting('tripay_is_production', $tripay_is_production);

            $success = "Pengaturan pembayaran berhasil disimpan. Checkout aktif: " . ($payment_mode === 'direct_bank' ? 'transfer bank langsung' : 'Tripay');
        } elseif ($_POST['action'] == 'update_auth_settings') {
            $forgot_password_enabled = isset($_POST['forgot_password_enabled']) ? '1' : '0';
            $password_reset_expiry_minutes = max(10, min(1440, (int)($_POST['password_reset_expiry_minutes'] ?? 60)));
            $password_reset_from_email = trim($_POST['password_reset_from_email'] ?? '');
            $password_reset_from_name = trim($_POST['password_reset_from_name'] ?? 'TOEIC Support');

            saveSetting('forgot_password_enabled', $forgot_password_enabled);
            saveSetting('password_reset_expiry_minutes', (string)$password_reset_expiry_minutes);
            saveSetting('password_reset_from_email', $password_reset_from_email);
            saveSetting('password_reset_from_name', $password_reset_from_name);

            $success = "Pengaturan lupa password berhasil disimpan.";
        } elseif ($_POST['action'] == 'update_pricing') {
            $exam_types = ['toeic', 'toeic_sw'];
            $tiers = ['retail', 'partner', 'bulk'];
            foreach ($exam_types as $type) {
                $name  = trim($_POST['name_' . $type] ?? '');
                $raw_features = trim($_POST['features_' . $type] ?? '');

                $features_arr = array_values(array_filter(
                    array_map('trim', explode("\n", $raw_features)),
                    fn($line) => $line !== ''
                ));

                saveSetting('name_' . $type, $name);
                saveSetting('features_' . $type, json_encode($features_arr, JSON_UNESCAPED_UNICODE));

                foreach ($tiers as $tier) {
                    $key = 'price_' . $type . '_' . $tier;
                    $price = (int)($_POST[$key] ?? 0);
                    if ($price < 0) {
                        $error = "Harga tidak boleh negatif.";
                        break 2;
                    }
                    saveSetting($key, (string)$price);
                    if ($tier === 'retail') {
                        saveSetting('price_' . $type, (string)$price);
                    }
                }
            }
            saveSetting('toeic_sw_scoring_model', trim($_POST['toeic_sw_scoring_model'] ?? 'gpt-5.5'));
            saveSetting('toeic_sw_transcription_model', trim($_POST['toeic_sw_transcription_model'] ?? 'gpt-4o-transcribe'));
            saveSetting('toeic_sw_tts_model', trim($_POST['toeic_sw_tts_model'] ?? 'speech-2.8-hd'));
            if (empty($error)) {
                $success = "Harga produk berhasil disimpan!";
            }
        }
    }
}

// Get current settings
$current_title = getSetting('website_title', 'FOEM UPY');
$current_logo = getSetting('website_logo');
$current_favicon = getSetting('website_favicon');

// Default features as JSON
$default_features = [
    'toeic' => json_encode(['Listening & Reading', '200 Soal', 'Format Terbaru', 'Sertifikat Digital'], JSON_UNESCAPED_UNICODE),
    'toeic_sw' => json_encode(['Speaking 11 questions', 'Writing 8 questions', 'Score report 0-400', 'AI-assisted feedback'], JSON_UNESCAPED_UNICODE),
];
$pricing = [
    'toeic' => [
        'price'    => (int) getSetting('price_toeic_retail', getSetting('price_toeic', '175000')),
        'tiers'    => [
            'retail' => (int) getSetting('price_toeic_retail', getSetting('price_toeic', '175000')),
            'partner' => (int) getSetting('price_toeic_partner', getSetting('price_toeic', '175000')),
            'bulk' => (int) getSetting('price_toeic_bulk', getSetting('price_toeic', '175000')),
        ],
        'name'     => getSetting('name_toeic', 'TOEIC Prediction'),
        'features' => implode("\n", json_decode(getSetting('features_toeic', $default_features['toeic']), true) ?? []),
    ],
    'toeic_sw' => [
        'price'    => (int) getSetting('price_toeic_sw_retail', getSetting('price_toeic_sw', '175000')),
        'tiers'    => [
            'retail' => (int) getSetting('price_toeic_sw_retail', getSetting('price_toeic_sw', '175000')),
            'partner' => (int) getSetting('price_toeic_sw_partner', getSetting('price_toeic_sw', '175000')),
            'bulk' => (int) getSetting('price_toeic_sw_bulk', getSetting('price_toeic_sw', '175000')),
        ],
        'name'     => getSetting('name_toeic_sw', 'TOEIC Speaking & Writing'),
        'features' => implode("\n", json_decode(getSetting('features_toeic_sw', $default_features['toeic_sw']), true) ?? []),
    ],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Settings</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .setting-section {
            border-left: 4px solid var(--primary);
            padding-left: 1.5rem;
            margin-bottom: 2rem;
        }

        .upload-area {
            border: 3px dashed var(--border-color);
            border-radius: 16px;
            padding: 3rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            background: var(--glass-bg);
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: var(--surface-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .upload-area.dragover {
            border-color: var(--primary-hover);
            background: var(--primary-light);
            transform: scale(1.02);
        }

        .preview-image {
            max-width: 200px;
            max-height: 100px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--glass-border);
        }

        .favicon-preview {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--glass-border);
        }

        .info-card {
            background: var(--primary-light);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .settings-table {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .settings-table th {
            background: rgba(255,255,255,0.03);
            color: var(--text-secondary);
            border: none;
            padding: 1rem;
            font-weight: 600;
        }

        .settings-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .upload-icon {
            color: var(--primary-hover);
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="admin-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1><i class="fas fa-cog me-3"></i>Website Settings</h1>
                        <div class="text-end">
                            <a href="index.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Website Title -->
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="setting-section">
                                <h4><i class="fas fa-heading me-2"></i>Website Title</h4>
                                <p class="text-muted">Set the main title for your website that appears in browser tabs
                                    and headers.</p>

                                <form method="POST">
                                    <input type="hidden" name="action" value="update_settings">
                                    <div class="mb-3">
                                        <label for="website_title" class="form-label">Website Title</label>
                                        <input type="text" class="form-control" id="website_title" name="website_title"
                                            value="<?php echo htmlspecialchars($current_title); ?>" required>
                                        <small class="text-muted">This will appear in browser tabs and page
                                            headers</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Title
                                    </button>
                                </form>

                                <div class="mt-3">
                                    <strong>Current Title:</strong> <?php echo htmlspecialchars($current_title); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Website Logo -->
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="setting-section">
                                <h4><i class="fas fa-image me-2"></i>Website Logo</h4>
                                <p class="text-muted">Upload your website logo. Recommended size: 200x60px or similar
                                    ratio.</p>

                                <?php if (!empty($current_logo) && file_exists('../' . $current_logo)): ?>
                                    <div class="mb-3">
                                        <strong>Current Logo:</strong><br>
                                        <img src="../<?php echo htmlspecialchars($current_logo); ?>" alt="Current Logo"
                                            class="preview-image mt-2">
                                    </div>
                                <?php endif; ?>

                                <form method="POST" enctype="multipart/form-data" id="logoForm">
                                    <input type="hidden" name="action" value="upload_logo">
                                    <div class="upload-area" onclick="document.getElementById('logoFile').click()">
                                        <i class="fas fa-cloud-upload-alt fa-3x upload-icon"></i>
                                        <p class="mb-2 fw-bold">Click to upload logo or drag and drop</p>
                                        <small class="text-muted">JPG, JPEG, PNG, GIF, SVG (Max: 2MB)</small>
                                        <input type="file" id="logoFile" name="logo" accept=".jpg,.jpeg,.png,.gif,.svg"
                                            style="display: none;" onchange="previewLogo(this)">
                                    </div>
                                    <div id="logoPreview" class="mt-3" style="display: none;">
                                        <img id="logoPreviewImg" class="preview-image">
                                        <div class="mt-2">
                                            <button type="submit" class="btn btn-success me-2">
                                                <i class="fas fa-upload me-2"></i>Upload Logo
                                            </button>
                                            <button type="button" class="btn btn-secondary"
                                                onclick="clearLogoPreview()">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Website Favicon -->
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="setting-section">
                                <h4><i class="fas fa-star me-2"></i>Website Favicon</h4>
                                <p class="text-muted">Upload your website favicon (the small icon that appears in
                                    browser tabs).</p>

                                <?php if (!empty($current_favicon) && file_exists('../' . $current_favicon)): ?>
                                    <div class="mb-3">
                                        <strong>Current Favicon:</strong><br>
                                        <img src="../<?php echo htmlspecialchars($current_favicon); ?>"
                                            alt="Current Favicon" class="favicon-preview mt-2">
                                    </div>
                                <?php endif; ?>

                                <form method="POST" enctype="multipart/form-data" id="faviconForm">
                                    <input type="hidden" name="action" value="upload_favicon">
                                    <div class="upload-area" onclick="document.getElementById('faviconFile').click()">
                                        <i class="fas fa-star fa-3x upload-icon"></i>
                                        <p class="mb-2 fw-bold">Click to upload favicon or drag and drop</p>
                                        <small class="text-muted">ICO, PNG, JPG, JPEG, GIF (Max: 1MB, Recommended:
                                            32x32px)</small>
                                        <input type="file" id="faviconFile" name="favicon"
                                            accept=".ico,.png,.jpg,.jpeg,.gif" style="display: none;"
                                            onchange="previewFavicon(this)">
                                    </div>
                                    <div id="faviconPreview" class="mt-3" style="display: none;">
                                        <img id="faviconPreviewImg" class="favicon-preview">
                                        <div class="mt-2">
                                            <button type="submit" class="btn btn-success me-2">
                                                <i class="fas fa-upload me-2"></i>Upload Favicon
                                            </button>
                                            <button type="button" class="btn btn-secondary"
                                                onclick="clearFaviconPreview()">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Settings Info -->
                    <div class="col-lg-6">
                        <div class="content-card">
                            <h4><i class="fas fa-info-circle me-2"></i>Settings Information</h4>

                            <div class="info-card">
                                <h6><i class="fas fa-lightbulb me-2"></i>Tips for Best Results:</h6>
                                <ul class="mb-0">
                                    <li><strong>Logo:</strong> Use PNG or SVG for best quality. Recommended size:
                                        200x60px</li>
                                    <li><strong>Favicon:</strong> Use ICO or PNG format. Size should be 32x32px or
                                        16x16px</li>
                                    <li><strong>File Size:</strong> Keep files small for faster loading</li>
                                    <li><strong>Format:</strong> PNG supports transparency, JPG is smaller</li>
                                </ul>
                            </div>

                            <div class="mt-4">
                                <h6 class="text-maroon mb-3">Current Settings Summary:</h6>
                                <div class="settings-table">
                                    <table class="table table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th>Setting</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><strong>Website Title</strong></td>
                                                <td><?php echo htmlspecialchars($current_title); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Logo</strong></td>
                                                <td>
                                                    <span
                                                        class="badge <?php echo !empty($current_logo) ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo !empty($current_logo) ? 'Uploaded' : 'Not set'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td><strong>Favicon</strong></td>
                                                <td>
                                                    <span
                                                        class="badge <?php echo !empty($current_favicon) ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo !empty($current_favicon) ? 'Uploaded' : 'Not set'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">


                    <!-- Payment Routing Settings -->
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="setting-section">
                                <h4><i class="fas fa-money-check-alt me-2"></i>Payment Routing</h4>
                                <p class="text-muted">Pilih apakah checkout memakai transfer bank langsung atau Tripay. Mode direct bank tidak mengarahkan riwayat pembayaran ke Tripay.</p>
                                
                                <?php 
                                $payment_mode = getSetting('payment_mode', 'direct_bank');
                                $tripay_is_prod = getSetting('tripay_is_production', '0');
                                $is_configured = !empty(getSetting('tripay_api_key')) && !empty(getSetting('tripay_private_key')) && !empty(getSetting('tripay_merchant_code'));
                                ?>
                                
                                <div class="alert alert-<?php echo $is_configured ? 'success' : 'warning'; ?> mb-3">
                                    <i class="fas fa-<?php echo $is_configured ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                    Checkout aktif: <strong><?php echo $payment_mode === 'tripay' ? 'Tripay' : 'Transfer Bank Langsung'; ?></strong> |
                                    Tripay: <?php echo $is_configured ? 'Configured' : 'Not Configured'; ?> |
                                    Mode: <strong><?php echo $tripay_is_prod === '1' ? 'PRODUCTION' : 'SANDBOX'; ?></strong>
                                </div>

                                <form method="POST">
                                    <input type="hidden" name="action" value="update_tripay">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Checkout Mode</label>
                                        <div class="d-flex flex-column gap-2">
                                            <label class="form-check">
                                                <input class="form-check-input" type="radio" name="payment_mode" value="direct_bank" <?php echo $payment_mode !== 'tripay' ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Transfer Bank Langsung</span>
                                            </label>
                                            <label class="form-check">
                                                <input class="form-check-input" type="radio" name="payment_mode" value="tripay" <?php echo $payment_mode === 'tripay' ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Tripay Gateway</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="p-3 border rounded mb-4" style="border-color: rgba(16,185,129,0.35) !important;">
                                        <h6 class="fw-bold mb-3 text-success"><i class="fas fa-building-columns me-2"></i>Rekening Direct Bank</h6>
                                        <div class="mb-2">
                                            <label class="form-label form-label-sm">Nama Bank</label>
                                            <input type="text" class="form-control form-control-sm" name="bank_name" value="<?php echo htmlspecialchars(getSetting('bank_name', '')); ?>" placeholder="BCA / Mandiri / BRI">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label form-label-sm">Nomor Rekening</label>
                                            <input type="text" class="form-control form-control-sm" name="bank_account_number" value="<?php echo htmlspecialchars(getSetting('bank_account_number', '')); ?>" placeholder="1234567890">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label form-label-sm">Atas Nama</label>
                                            <input type="text" class="form-control form-control-sm" name="bank_account_holder" value="<?php echo htmlspecialchars(getSetting('bank_account_holder', '')); ?>" placeholder="Nama pemilik rekening">
                                        </div>
                                        <div class="mb-0">
                                            <label class="form-label form-label-sm">Instruksi Pembayaran</label>
                                            <textarea class="form-control form-control-sm" name="bank_transfer_instructions" rows="3"><?php echo htmlspecialchars(getSetting('bank_transfer_instructions', 'Transfer sesuai nominal invoice, lalu kirim bukti pembayaran ke admin untuk aktivasi paket.')); ?></textarea>
                                        </div>
                                    </div>

                                    <h6 class="fw-bold mb-3"><i class="fas fa-qrcode me-2"></i>Tripay Payment Gateway</h6>
                                    <div class="mb-3">
                                        <label class="form-label">API Key <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" name="tripay_api_key" 
                                               value="<?php echo htmlspecialchars(getSetting('tripay_api_key')); ?>" 
                                               placeholder="DEV-xxxxx (sandbox) or PRODUCTION-xxxxx">
                                        <small class="text-muted">Found in Tripay Dashboard → Settings</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Private Key <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" name="tripay_private_key" 
                                               value="<?php echo htmlspecialchars(getSetting('tripay_private_key')); ?>"
                                               placeholder="Your Tripay Private Key">
                                        <small class="text-muted">Used for signature verification - keep secret!</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Merchant Code <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="tripay_merchant_code" 
                                               value="<?php echo htmlspecialchars(getSetting('tripay_merchant_code')); ?>"
                                               placeholder="Txxxxx">
                                        <small class="text-muted">Your Tripay merchant identifier</small>
                                    </div>
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="tripay_is_production" name="tripay_is_production" 
                                                   <?php echo $tripay_is_prod === '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold" for="tripay_is_production">
                                                <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                                Production Mode
                                            </label>
                                        </div>
                                        <small class="text-muted">Uncheck for Sandbox (testing). Production uses real payments!</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Payment Settings</button>
                                </form>

                                <div class="mt-4 p-3 bg-light rounded">
                                    <h6><i class="fas fa-info-circle me-2"></i>Setup Guide:</h6>
                                    <ol class="mb-0 small">
                                        <li>Go to <a href="https://tripay.co.id/dashboard" target="_blank">Tripay Dashboard</a></li>
                                        <li>Navigate to Settings → API Credentials</li>
                                        <li>Copy API Key, Private Key, and Merchant Code</li>
                                        <li>Paste credentials above and save</li>
                                        <li>Enable Production Mode when ready</li>
                                        <li>Set Callback URL to: <code><?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?>/api/tripay_callback.php</code></li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="setting-section">
                                <h4><i class="fas fa-key me-2"></i>Lupa Password Pengguna</h4>
                                <p class="text-muted">Aktifkan reset password via email terdaftar. Link reset akan kedaluwarsa sesuai durasi di bawah.</p>

                                <form method="POST">
                                    <input type="hidden" name="action" value="update_auth_settings">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="forgot_password_enabled" name="forgot_password_enabled" <?php echo getSetting('forgot_password_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold" for="forgot_password_enabled">Aktifkan fitur lupa password</label>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Reset Expiry (minutes)</label>
                                        <input type="number" class="form-control" name="password_reset_expiry_minutes" value="<?php echo htmlspecialchars(getSetting('password_reset_expiry_minutes', '60')); ?>" min="10" max="1440">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">From Email</label>
                                        <input type="email" class="form-control" name="password_reset_from_email" value="<?php echo htmlspecialchars(getSetting('password_reset_from_email', '')); ?>" placeholder="support@example.com">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">From Name</label>
                                        <input type="text" class="form-control" name="password_reset_from_name" value="<?php echo htmlspecialchars(getSetting('password_reset_from_name', 'TOEIC Support')); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Password Reset</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- ===== HARGA PRODUK ===== -->
                <div class="row">
                    <div class="col-12">
                        <div class="content-card">
                            <div class="setting-section">
                                <h4><i class="fas fa-tags me-2"></i>Harga Produk</h4>
                                <p class="text-muted">Atur nama, harga, dan fitur untuk setiap paket ujian. Satu baris = satu poin fitur.</p>

                                <form method="POST">
                                    <input type="hidden" name="action" value="update_pricing">

                                    <div class="row g-4">
                                        <div class="col-lg-4">
                                            <div class="p-3 border rounded" style="border-color: rgba(245,158,11,0.4) !important; background: rgba(245,158,11,0.04);">
                                                <h6 class="fw-bold mb-3" style="color:#f59e0b;"><i class="fas fa-briefcase me-2"></i>TOEIC</h6>
                                                <div class="mb-2">
                                                    <label class="form-label form-label-sm">Nama Produk</label>
                                                    <input type="text" class="form-control form-control-sm" name="name_toeic"
                                                           value="<?php echo htmlspecialchars($pricing['toeic']['name']); ?>">
                                                </div>
                                                <div class="row g-2 mb-2">
                                                    <?php foreach (['retail' => 'Retail', 'partner' => 'Mitra', 'bulk' => 'Bulking'] as $tier => $label): ?>
                                                        <div class="col-md-4">
                                                            <label class="form-label form-label-sm"><?php echo htmlspecialchars($label); ?> (Rp)</label>
                                                            <input type="number" class="form-control form-control-sm" name="price_toeic_<?php echo $tier; ?>"
                                                                   value="<?php echo (int)$pricing['toeic']['tiers'][$tier]; ?>" min="0" step="1000">
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="mb-0">
                                                    <label class="form-label form-label-sm">Fitur (satu baris = satu poin)</label>
                                                    <textarea class="form-control form-control-sm" name="features_toeic" rows="5"
                                                              placeholder="Listening &amp; Reading&#10;200 Soal"><?php echo htmlspecialchars($pricing['toeic']['features']); ?></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-lg-4">
                                            <div class="p-3 border rounded" style="border-color: rgba(59,130,246,0.4) !important; background: rgba(59,130,246,0.04);">
                                                <h6 class="fw-bold mb-3" style="color:#60a5fa;"><i class="fas fa-microphone-lines me-2"></i>TOEIC Speaking &amp; Writing</h6>
                                                <div class="mb-2">
                                                    <label class="form-label form-label-sm">Nama Produk</label>
                                                    <input type="text" class="form-control form-control-sm" name="name_toeic_sw"
                                                           value="<?php echo htmlspecialchars($pricing['toeic_sw']['name']); ?>">
                                                </div>
                                                <div class="row g-2 mb-2">
                                                    <?php foreach (['retail' => 'Retail', 'partner' => 'Mitra', 'bulk' => 'Bulking'] as $tier => $label): ?>
                                                        <div class="col-md-4">
                                                            <label class="form-label form-label-sm"><?php echo htmlspecialchars($label); ?> (Rp)</label>
                                                            <input type="number" class="form-control form-control-sm" name="price_toeic_sw_<?php echo $tier; ?>"
                                                                   value="<?php echo (int)$pricing['toeic_sw']['tiers'][$tier]; ?>" min="0" step="1000">
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label form-label-sm">Fitur (satu baris = satu poin)</label>
                                                    <textarea class="form-control form-control-sm" name="features_toeic_sw" rows="5"
                                                              placeholder="Speaking 11 questions&#10;Writing 8 questions"><?php echo htmlspecialchars($pricing['toeic_sw']['features']); ?></textarea>
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col-12">
                                                        <label class="form-label form-label-sm">Scoring Model</label>
                                                        <input type="text" class="form-control form-control-sm" name="toeic_sw_scoring_model"
                                                               value="<?php echo htmlspecialchars(getSetting('toeic_sw_scoring_model', 'gpt-5.5')); ?>">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label form-label-sm">Transcription Model</label>
                                                        <input type="text" class="form-control form-control-sm" name="toeic_sw_transcription_model"
                                                               value="<?php echo htmlspecialchars(getSetting('toeic_sw_transcription_model', 'gpt-4o-transcribe')); ?>">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label form-label-sm">Optional TTS Model</label>
                                                        <input type="text" class="form-control form-control-sm" name="toeic_sw_tts_model"
                                                               value="<?php echo htmlspecialchars(getSetting('toeic_sw_tts_model', 'speech-2.8-hd')); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div><!-- /row g-4 -->

                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Simpan Harga Produk
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- ===== /HARGA PRODUK ===== -->
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        // Logo preview function
        function previewLogo(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('logoPreviewImg').src = e.target.result;
                    document.getElementById('logoPreview').style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Clear logo preview
        function clearLogoPreview() {
            document.getElementById('logoFile').value = '';
            document.getElementById('logoPreview').style.display = 'none';
        }

        // Favicon preview function
        function previewFavicon(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('faviconPreviewImg').src = e.target.result;
                    document.getElementById('faviconPreview').style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Clear favicon preview
        function clearFaviconPreview() {
            document.getElementById('faviconFile').value = '';
            document.getElementById('faviconPreview').style.display = 'none';
        }

        // Drag and drop functionality
        function setupDragAndDrop(uploadArea, fileInput) {
            uploadArea.addEventListener('dragover', function (e) {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });

            uploadArea.addEventListener('dragleave', function (e) {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
            });

            uploadArea.addEventListener('drop', function (e) {
                e.preventDefault();
                uploadArea.classList.remove('dragover');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });
        }

        // Initialize drag and drop
        document.addEventListener('DOMContentLoaded', function () {
            const logoUploadArea = document.querySelector('#logoForm .upload-area');
            const logoFileInput = document.getElementById('logoFile');
            setupDragAndDrop(logoUploadArea, logoFileInput);

            const faviconUploadArea = document.querySelector('#faviconForm .upload-area');
            const faviconFileInput = document.getElementById('faviconFile');
            setupDragAndDrop(faviconUploadArea, faviconFileInput);
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                if (alert.querySelector('.btn-close')) {
                    alert.querySelector('.btn-close').click();
                }
            });
        }, 5000);
    </script>
</body>

</html>
