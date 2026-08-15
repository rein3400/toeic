<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/certificate_templates.php';
require_once '../includes/ai_helper.php';
require_once '../includes/csrf_helper.php';

// Check admin login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Generate CSRF token
if (empty($_SESSION['certificate_csrf'])) {
    $_SESSION['certificate_csrf'] = bin2hex(random_bytes(16));
}

function generateCertificateLayoutWithAI(string $templateName, array $imageInfo): array
{
    $fallback = getDefaultCertificateImageLayout();
    $config = getActiveAIProvider();
    if (!$config) {
        return $fallback;
    }

    $width = (int)($imageInfo[0] ?? 0);
    $height = (int)($imageInfo[1] ?? 0);
    $ratio = $height > 0 ? round($width / $height, 3) : 1.414;
    $prompt = "You are designing an HTML overlay layout for a certificate background image.\n"
        . "The uploaded background image is the visual certificate design. The application will render safe HTML blocks on top of it using dynamic program data: student name, score, level, issued by, date, certificate ID, and QR code.\n"
        . "Return ONLY valid JSON with numeric percentage values. Do not include markdown.\n\n"
        . "Template name: {$templateName}\n"
        . "Image size: {$width}x{$height}, ratio {$ratio}\n\n"
        . "Required keys: name_top, name_left, name_right, name_size, score_top, score_left, score_right, score_size, meta_left, meta_bottom, qr_right, qr_bottom, notice_bottom, panel_opacity.\n"
        . "Use percentages for HTML block positions. Use font sizes in pixels. Keep panels readable and centered for a landscape certificate. Example: "
        . json_encode($fallback);

    try {
        $response = callAI($prompt, $config, 800);
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return normalizeCertificateImageLayout($decoded);
            }
        }
    } catch (Exception $e) {
        error_log('Certificate AI layout failed: ' . $e->getMessage());
    }

    return $fallback;
}

// Handle form submission
$message = '';
$msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['certificate_csrf'] ?? '', $token)) {
        $message = 'Invalid CSRF token. Please try again.';
        $msg_type = 'danger';
    } else {
        $certAction = $_POST['cert_action'] ?? 'save_settings';

        if ($certAction === 'set_default_image') {
            $imageTemplateId = max(0, (int)($_POST['image_template_id'] ?? 0));
            if ($imageTemplateId > 0 && setDefaultCertificateImageTemplate($imageTemplateId)) {
                $selectedTemplate = getCertificateImageTemplateById($imageTemplateId);
                if ($selectedTemplate) {
                    $selectedInstitution = normalizeCertificateImageInstitution($selectedTemplate['institution_key'] ?? 'halokak');
                    $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('cert_image_institution', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    if ($stmt) {
                        $stmt->bind_param("s", $selectedInstitution);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $message = 'Default image certificate updated successfully!';
                $msg_type = 'success';
            } else {
                $message = 'Default image certificate could not be updated.';
                $msg_type = 'danger';
            }
        } elseif ($certAction === 'delete_image') {
            $imageTemplateId = max(0, (int)($_POST['image_template_id'] ?? 0));
            if ($imageTemplateId > 0 && deleteCertificateImageTemplate($imageTemplateId, dirname(__DIR__))) {
                $message = 'Certificate image template deleted successfully!';
                $msg_type = 'success';
            } else {
                $message = 'Certificate image template could not be deleted.';
                $msg_type = 'danger';
            }
        } else {
            $template       = normalizeCertificateTemplate($_POST['cert_template'] ?? '');
            $templateSw     = normalizeCertificateTemplate($_POST['cert_template_toeic_sw'] ?? $template);
            $releaseMode    = ($_POST['cert_release_mode'] ?? '') === 'manual' ? 'manual' : 'automatic';
            $primaryColor   = normalizeCertificateColor($_POST['cert_primary_color']   ?? null, '#0a2540');
            $secondaryColor = normalizeCertificateColor($_POST['cert_secondary_color'] ?? null, '#1e63d6');
            $institution    = trim((string)($_POST['cert_institution']     ?? 'Practice Assessment Center'));
            $location       = trim((string)($_POST['cert_location']        ?? 'Indonesia'));
            $signerName     = trim((string)($_POST['cert_signature_name']  ?? 'Practice Platform'));
            $signerTitle    = trim((string)($_POST['cert_signature_title'] ?? 'Authorized TOEIC Center'));
            $imageInstitution = normalizeCertificateImageInstitution($_POST['cert_image_institution'] ?? 'halokak');

            $settings = [
                'cert_template'            => $template,
                'cert_template_toeic_sw'   => $templateSw,
                'cert_release_mode'        => $releaseMode,
                'cert_primary_color'       => $primaryColor,
                'cert_secondary_color'     => $secondaryColor,
                'cert_institution'         => $institution,
                'cert_location'            => $location,
                'cert_signature_name'      => $signerName,
                'cert_signature_title'     => $signerTitle,
                'cert_image_institution'   => $imageInstitution,
            ];

            $newImageTemplatePath = '';
            $uploadFileInfo = null;
            if (isset($_FILES['cert_background_image']) && ($_FILES['cert_background_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploadError = $_FILES['cert_background_image']['error'];
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $message = 'Certificate image upload failed. Please try another file.';
                    $msg_type = 'danger';
                } else {
                    $tmpPath = $_FILES['cert_background_image']['tmp_name'];
                    $fileInfo = @getimagesize($tmpPath);
                    $allowedTypes = [
                        IMAGETYPE_JPEG => 'jpg',
                        IMAGETYPE_PNG => 'png',
                        IMAGETYPE_WEBP => 'webp',
                    ];

                    if (!$fileInfo || !isset($allowedTypes[$fileInfo[2]])) {
                        $message = 'Certificate image must be JPG, PNG, or WEBP.';
                        $msg_type = 'danger';
                    } elseif ((int)($_FILES['cert_background_image']['size'] ?? 0) > 5 * 1024 * 1024) {
                        $message = 'Certificate image must be 5 MB or smaller.';
                        $msg_type = 'danger';
                    } else {
                        $uploadDir = __DIR__ . '/../uploads/certificates';
                        if (!is_dir($uploadDir)) {
                            if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                                $message = 'Certificate upload folder could not be created. Please check uploads folder permissions.';
                                $msg_type = 'danger';
                            }
                        }
                        if ($msg_type !== 'danger') {
                            @chmod($uploadDir, 0777);
                        }
                        if ($msg_type !== 'danger' && !is_writable($uploadDir)) {
                            $message = 'Certificate upload folder is not writable. Please check uploads/certificates permissions.';
                            $msg_type = 'danger';
                        }
                        if ($msg_type !== 'danger') {
                            $extension = $allowedTypes[$fileInfo[2]];
                            $filename = 'certificate_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                            $targetPath = $uploadDir . '/' . $filename;
                            if (move_uploaded_file($tmpPath, $targetPath)) {
                                @chmod($targetPath, 0664);
                                $newImageTemplatePath = 'uploads/certificates/' . $filename;
                                $uploadFileInfo = $fileInfo;
                            } else {
                                $message = 'Certificate image could not be saved. Please check uploads/certificates permissions.';
                                $msg_type = 'danger';
                            }
                        }
                    }
                }
            }

            if ($msg_type !== 'danger') {
                try {
                    $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    foreach ($settings as $key => $value) {
                        $stmt->bind_param('ss', $key, $value);
                        $stmt->execute();
                    }
                    if ($newImageTemplatePath !== '') {
                        $templateName = trim($_POST['cert_background_name'] ?? '');
                        if ($templateName === '') {
                            $templateName = getCertificateImageInstitutionName($imageInstitution);
                        }
                        $makeDefault = !empty($_POST['cert_make_default_image']);
                        $layout = getDefaultCertificateImageLayout();
                        if (!empty($_POST['cert_use_ai_layout']) && is_array($uploadFileInfo)) {
                            $layout = generateCertificateLayoutWithAI($templateName, $uploadFileInfo);
                        }
                        if (!saveCertificateImageTemplate($templateName, $newImageTemplatePath, $makeDefault, $layout, $imageInstitution)) {
                            throw new Exception('Image template could not be saved.');
                        }
                    }
                    $message = 'Certificate settings updated successfully!';
                    $msg_type = 'success';
                    $_SESSION['certificate_csrf'] = bin2hex(random_bytes(16));
                } catch (Exception $e) {
                    $message = 'Error saving settings: ' . $e->getMessage();
                    $msg_type = 'danger';
                }
            }
        }
    }
}

// Fetch current settings
$current_settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'cert_%'");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
    $result->free();
}

$defaults = [
    'cert_template'             => 'professional_toeic',
    'cert_template_toeic_sw'    => 'professional_toeic',
    'cert_release_mode'         => 'automatic',
    'cert_primary_color'        => '#0a2540',
    'cert_secondary_color'      => '#1e63d6',
    'cert_institution'          => 'Practice Assessment Center',
    'cert_location'             => 'Indonesia',
    'cert_signature_name'       => 'Practice Platform',
    'cert_signature_title'      => 'Authorized TOEIC Center',
    'cert_image_institution'    => 'halokak',
];
$settings = array_merge($defaults, $current_settings);
$settings['cert_template']             = normalizeCertificateTemplate($settings['cert_template']);
$settings['cert_template_toeic_sw']    = normalizeCertificateTemplate($settings['cert_template_toeic_sw']);
$settings['cert_release_mode']         = $settings['cert_release_mode'] === 'manual' ? 'manual' : 'automatic';
$settings['cert_primary_color']        = normalizeCertificateColor($settings['cert_primary_color'],   $defaults['cert_primary_color']);
$settings['cert_secondary_color']      = normalizeCertificateColor($settings['cert_secondary_color'], $defaults['cert_secondary_color']);
$settings['cert_image_institution']    = normalizeCertificateImageInstitution($settings['cert_image_institution']);
$certificate_templates = getCertificateTemplates();
$certificate_image_institutions = getCertificateImageInstitutions();
$image_templates = getCertificateImageTemplates();
$website_title = getWebsiteTitle();
$csrf_token = $_SESSION['certificate_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Settings - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
    body { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
    .main-content { margin-left: 16.66667%; padding: 2rem; min-height: 100vh; }
    @media (max-width: 768px) { .main-content { margin-left: 0; padding: 1rem; padding-top: 5rem; } }
    .content-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); }
    .section-title { font-size: 1rem; font-weight: 700; color: #1e293b; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 0.5rem; }
    .section-title .icon-box { width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
    .config-field { margin-bottom: 1.25rem; }
    .config-field label { font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.4rem; }
    .config-field .form-text { font-size: 0.72rem; color: #94a3b8; }
    .template-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
    .template-option { position: relative; }
    .template-option input { position: absolute; opacity: 0; pointer-events: none; }
    .template-card { display: block; height: 100%; padding: 1rem; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; transition: all 0.2s ease; background: #fff; }
    .template-card:hover { border-color: #94a3b8; transform: translateY(-1px); }
    .template-option input:checked + .template-card { border-color: #2563eb; background: #eff6ff; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
    .template-card-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: #f1f5f9; color: #334155; margin-bottom: 0.75rem; }
    .template-card strong { display: block; color: #0f172a; font-size: 0.88rem; margin-bottom: 0.3rem; }
    .template-card small { display: block; color: #475569; font-size: 0.72rem; line-height: 1.45; }
    .color-picker-group { display: flex; align-items: center; gap: 0.75rem; }
    .color-picker-group input[type="color"] { width: 44px; height: 44px; border-radius: 10px; border: 2px solid #e2e8f0; padding: 2px; cursor: pointer; flex-shrink: 0; }
    .color-picker-group input[type="text"] { font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.85rem; letter-spacing: 0.03em; }
    .preview-frame { border: 1px solid #e2e8f0; width: 100%; height: 580px; border-radius: 12px; background: white; }
    .btn-save { background: #0f172a; color: white; border: none; padding: 0.75rem 2rem; border-radius: 12px; font-weight: 600; font-size: 0.9rem; transition: all 0.2s; width: 100%; }
    .btn-save:hover { background: #1e293b; color: white; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2); }
    .preview-tabs { display: flex; gap: 0.5rem; margin-bottom: 0.75rem; }
    .preview-tab { padding: 0.4rem 0.9rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; border: 1px solid #e2e8f0; background: #fff; color: #475569; cursor: pointer; }
    .preview-tab.active { background: #0a2540; color: #fff; border-color: #0a2540; }
    .uploaded-img-card { border: 1px solid #e2e8f0; border-radius: 16px; padding: 1rem; background: #ffffff; height: 100%; transition: all 0.2s; }
    .uploaded-img-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    .uploaded-img-preview { width: 100%; height: 130px; object-fit: cover; border-radius: 10px; background: #f8fafc; border: 1px solid #e2e8f0; margin-bottom: 0.75rem; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 offset-md-2 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1" style="color: #0f172a; font-size: 1.6rem;">
                            <i class="fas fa-certificate me-2" style="color: #64748b;"></i>Certificate Configuration
                        </h1>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">Choose the certificate template, colors, and release policy</p>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>

                <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo htmlspecialchars($msg_type); ?> py-3 rounded-3 border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
                    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> fs-4 me-3"></i>
                    <div><?php echo htmlspecialchars($message); ?></div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <form method="POST" id="certForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                            <div class="content-card">
                                <div class="section-title">
                                    <span class="icon-box" style="background: #fff7ed; color: #c2410c;">
                                        <i class="fas fa-palette"></i>
                                    </span>
                                    R+L Certificate Template
                                </div>
                                <div class="template-options">
                                    <?php foreach ($certificate_templates as $template_key => $template): ?>
                                    <div class="template-option">
                                        <input type="radio" name="cert_template"
                                            id="template_rl_<?php echo htmlspecialchars($template_key); ?>"
                                            value="<?php echo htmlspecialchars($template_key); ?>"
                                            <?php echo $settings['cert_template'] === $template_key ? 'checked' : ''; ?>
                                            data-template-key="<?php echo htmlspecialchars($template_key); ?>"
                                            data-domain="rl">
                                        <label class="template-card" for="template_rl_<?php echo htmlspecialchars($template_key); ?>">
                                            <span class="template-card-icon">
                                                <i class="fas <?php echo htmlspecialchars($template['icon']); ?>"></i>
                                            </span>
                                            <strong><?php echo htmlspecialchars($template['name']); ?></strong>
                                            <small><?php echo htmlspecialchars($template['description']); ?></small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="content-card">
                                <div class="section-title">
                                    <span class="icon-box" style="background: #ede9fe; color: #7c3aed;">
                                        <i class="fas fa-microphone"></i>
                                    </span>
                                    S+W Certificate Template
                                </div>
                                <div class="template-options">
                                    <?php foreach ($certificate_templates as $template_key => $template): ?>
                                    <div class="template-option">
                                        <input type="radio" name="cert_template_toeic_sw"
                                            id="template_sw_<?php echo htmlspecialchars($template_key); ?>"
                                            value="<?php echo htmlspecialchars($template_key); ?>"
                                            <?php echo $settings['cert_template_toeic_sw'] === $template_key ? 'checked' : ''; ?>
                                            data-template-key="<?php echo htmlspecialchars($template_key); ?>"
                                            data-domain="sw">
                                        <label class="template-card" for="template_sw_<?php echo htmlspecialchars($template_key); ?>">
                                            <span class="template-card-icon">
                                                <i class="fas <?php echo htmlspecialchars($template['icon']); ?>"></i>
                                            </span>
                                            <strong><?php echo htmlspecialchars($template['name']); ?></strong>
                                            <small><?php echo htmlspecialchars($template['description']); ?></small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="content-card" id="imageCertificateDesignsSection">
                                <div class="section-title">
                                    <span class="icon-box" style="background: #eef2ff; color: #4338ca;">
                                        <i class="fas fa-image"></i>
                                    </span>
                                    Image Certificate Designs
                                </div>

                                <div class="config-field">
                                    <label class="form-label">Instansi</label>
                                    <select class="form-select" name="cert_image_institution" id="certImageInstitution">
                                        <?php foreach ($certificate_image_institutions as $institution_key => $institution): ?>
                                        <option value="<?php echo htmlspecialchars($institution_key); ?>"
                                            <?php echo $settings['cert_image_institution'] === $institution_key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($institution['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text mt-2">
                                        Pilih instansi untuk desain Image Certificate.
                                    </div>
                                </div>

                                <div class="config-field">
                                    <label class="form-label">Template Name</label>
                                    <input type="text" class="form-control" name="cert_background_name" placeholder="Example: Instansi Halokak">
                                    <div class="form-text mt-2">
                                        Leave empty to use the selected institution name as the certificate design name.
                                    </div>
                                </div>

                                <div class="config-field">
                                    <label class="form-label">Upload New Certificate Image</label>
                                    <input type="file" class="form-control" name="cert_background_image" accept="image/jpeg,image/png,image/webp">
                                    <div class="form-text mt-2">
                                        The uploaded photo becomes the certificate design/background. Recommended ratio: A4 landscape or 297:210. Maximum 5 MB.
                                    </div>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="cert_make_default_image" id="certMakeDefaultImage" checked>
                                    <label class="form-check-label" for="certMakeDefaultImage">
                                        Make uploaded image the default Image Certificate template
                                    </label>
                                </div>

                                <div class="form-check mb-3 d-none">
                                    <input class="form-check-input" type="checkbox" name="cert_use_ai_layout" id="certUseAiLayout" checked>
                                    <label class="form-check-label" for="certUseAiLayout">
                                        Use AI to generate the HTML overlay layout
                                    </label>
                                </div>

                                <?php if (!empty($image_templates)): ?>
                                    <div class="mt-3">
                                        <label class="form-label d-block">Uploaded Image Templates</label>
                                        <div class="row g-3">
                                            <?php foreach ($image_templates as $image_template): ?>
                                                <?php
                                                $templateInstitutionKey = normalizeCertificateImageInstitution($image_template['institution_key'] ?? 'halokak');
                                                $templateInstitutionName = getCertificateImageInstitutionName($templateInstitutionKey);
                                                ?>
                                                <div class="col-md-6">
                                                    <div class="uploaded-img-card d-flex flex-column">
                                                        <img src="../<?php echo htmlspecialchars($image_template['image_path']); ?>?v=<?php echo file_exists(__DIR__ . '/../' . $image_template['image_path']) ? filemtime(__DIR__ . '/../' . $image_template['image_path']) : time(); ?>"
                                                            alt="<?php echo htmlspecialchars($image_template['name']); ?>"
                                                            class="uploaded-img-preview">
                                                        <div class="d-flex align-items-start justify-content-between gap-2 mt-auto">
                                                            <div style="min-width:0;">
                                                                <div class="fw-bold text-truncate text-dark" style="font-size:0.9rem;"><?php echo htmlspecialchars($image_template['name']); ?></div>
                                                                <div class="mt-1 mb-2">
                                                                    <span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($templateInstitutionName); ?></span>
                                                                    <?php if ((int)$image_template['is_default'] === 1): ?>
                                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Default</span>
                                                                    <?php endif; ?>
                                                                    <?php if ((int)$image_template['is_default'] === 1 && $settings['cert_image_institution'] === $templateInstitutionKey): ?>
                                                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex gap-2 flex-wrap mt-2 pt-2 border-top">
                                                            <?php if ((int)$image_template['is_default'] !== 1 || $settings['cert_image_institution'] !== $templateInstitutionKey): ?>
                                                                <button type="submit" class="btn btn-sm btn-light text-primary flex-grow-1 border fw-medium"
                                                                    name="cert_action" value="set_default_image"
                                                                    onclick="document.getElementById('imageTemplateId').value='<?php echo (int)$image_template['id']; ?>'">
                                                                    <i class="fas fa-check-circle me-1"></i><?php echo (int)$image_template['is_default'] === 1 ? 'Use This' : 'Set Default'; ?>
                                                                </button>
                                                            <?php endif; ?>
                                                            <button type="submit" class="btn btn-sm btn-light text-danger flex-grow-1 border fw-medium"
                                                                name="cert_action" value="delete_image"
                                                                onclick="document.getElementById('imageTemplateId').value='<?php echo (int)$image_template['id']; ?>'; return confirm('Delete this certificate image template?');">
                                                                <i class="fas fa-trash-alt me-1"></i>Delete
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info border-0 mt-3 mb-0" style="font-size:0.85rem;">
                                        No image templates uploaded yet. Image Certificate will show a placeholder until you upload a design.
                                    </div>
                                <?php endif; ?>
                                <input type="hidden" name="image_template_id" id="imageTemplateId" value="">
                            </div>

                            <div class="content-card">
                                <div class="section-title">
                                    <span class="icon-box" style="background: #ecfdf5; color: #047857;">
                                        <i class="fas fa-user-shield"></i>
                                    </span>
                                    Certificate Release Policy
                                </div>
                                <div class="config-field">
                                    <label class="form-label">Release Mode</label>
                                    <select class="form-select" name="cert_release_mode">
                                        <option value="automatic" <?php echo $settings['cert_release_mode'] === 'automatic' ? 'selected' : ''; ?>>
                                            Automatic after completed full simulation
                                        </option>
                                        <option value="manual" <?php echo $settings['cert_release_mode'] === 'manual' ? 'selected' : ''; ?>>
                                            Manual administrator approval
                                        </option>
                                    </select>
                                    <div class="form-text mt-2">
                                        Recommended: automatic. Practice and incomplete sessions never receive certificates. Administrators can still approve or revoke individual results.
                                    </div>
                                </div>
                            </div>

                            <div class="content-card">
                                <div class="section-title">
                                    <span class="icon-box" style="background: #faf5ff; color: #7c3aed;">
                                        <i class="fas fa-droplet"></i>
                                    </span>
                                    Certificate Colors
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="config-field">
                                            <label class="form-label">Primary Color</label>
                                            <div class="color-picker-group">
                                                <input type="color" name="cert_primary_color" id="primaryColor"
                                                    value="<?php echo htmlspecialchars($settings['cert_primary_color']); ?>"
                                                    onchange="document.getElementById('primaryColorText').value = this.value">
                                                <input type="text" class="form-control" id="primaryColorText"
                                                    value="<?php echo htmlspecialchars($settings['cert_primary_color']); ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="config-field">
                                            <label class="form-label">Secondary Color</label>
                                            <div class="color-picker-group">
                                                <input type="color" name="cert_secondary_color" id="secondaryColor"
                                                    value="<?php echo htmlspecialchars($settings['cert_secondary_color']); ?>"
                                                    onchange="document.getElementById('secondaryColorText').value = this.value">
                                                <input type="text" class="form-control" id="secondaryColorText"
                                                    value="<?php echo htmlspecialchars($settings['cert_secondary_color']); ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="content-card">
                                <div class="section-title">
                                    <span class="icon-box" style="background: #fef3c7; color: #d97706;">
                                        <i class="fas fa-id-card"></i>
                                    </span>
                                    Issuer Details
                                </div>
                                <div class="config-field">
                                    <label class="form-label">Institution</label>
                                    <input type="text" class="form-control" name="cert_institution"
                                        value="<?php echo htmlspecialchars($settings['cert_institution']); ?>" maxlength="120">
                                </div>
                                <div class="config-field">
                                    <label class="form-label">Location</label>
                                    <input type="text" class="form-control" name="cert_location"
                                        value="<?php echo htmlspecialchars($settings['cert_location']); ?>" maxlength="80">
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="config-field">
                                            <label class="form-label">Signature Name</label>
                                            <input type="text" class="form-control" name="cert_signature_name"
                                                value="<?php echo htmlspecialchars($settings['cert_signature_name']); ?>" maxlength="80">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="config-field">
                                            <label class="form-label">Signature Title</label>
                                            <input type="text" class="form-control" name="cert_signature_title"
                                                value="<?php echo htmlspecialchars($settings['cert_signature_title']); ?>" maxlength="80">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text mb-3">
                                    Save the configuration to apply color & template changes to previews and downloaded PDFs.
                                </div>
                                <button type="submit" class="btn-save mt-3" name="cert_action" value="save_settings">
                                    <i class="fas fa-save me-2"></i>Save Configuration
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-lg-6">
                        <div class="content-card sticky-top" style="top: 1rem;">
                            <div class="section-title">
                                <span class="icon-box" style="background: #dcfce7; color: #16a34a;">
                                    <i class="fas fa-eye"></i>
                                </span>
                                <span class="flex-grow-1">Live Preview</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="updatePreview()">
                                    <i class="fas fa-sync-alt me-1"></i>Refresh
                                </button>
                            </div>
                            <div class="preview-tabs">
                                <button type="button" class="preview-tab active" data-domain="rl">R+L</button>
                                <button type="button" class="preview-tab" data-domain="sw">S+W</button>
                            </div>
                            <iframe id="previewFrame" class="preview-frame"
                                src="../user/export_certificate_toeic.php?session=preview&preview_mode=1"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function getSelectedTemplate(domain) {
        const sel = document.querySelector('input[name="cert_template' + (domain === 'sw' ? '_toeic_sw' : '') + '"]:checked');
        return sel ? sel.value : '';
    }
    function updatePreview() {
        const activeTab = document.querySelector('.preview-tab.active');
        const domain = activeTab ? activeTab.dataset.domain : 'rl';
        const template = getSelectedTemplate(domain);
        const institution = document.getElementById('certImageInstitution');
        const previewFrame = document.getElementById('previewFrame');
        const endpoint = domain === 'sw'
            ? '../user/export_certificate_toeic_sw.php'
            : '../user/export_certificate_toeic.php';
        const params = new URLSearchParams({
            session: 'preview',
            preview_mode: '1',
            template: template,
            image_institution: institution ? institution.value : '',
            v: Date.now().toString()
        });
        previewFrame.src = endpoint + '?' + params.toString();
    }
    document.querySelectorAll('input[name="cert_template"], input[name="cert_template_toeic_sw"]').forEach(input => {
        input.addEventListener('change', updatePreview);
    });
    document.querySelectorAll('.preview-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.preview-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            updatePreview();
        });
    });
    const imageInstitution = document.getElementById('certImageInstitution');
    if (imageInstitution) {
        imageInstitution.addEventListener('change', updatePreview);
    }
    document.addEventListener('DOMContentLoaded', updatePreview);
    setTimeout(() => {
        document.querySelectorAll('.alert-dismissible .btn-close').forEach(btn => btn.click());
    }, 5000);
    </script>
</body>
</html>
