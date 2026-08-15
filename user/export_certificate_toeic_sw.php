<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/certificate_templates.php';
require_once '../includes/CertificateAccess.php';
require_once '../includes/PdfHelper.php';
require_once '../includes/CertificatePdfTemplate.php';

$public_access = false;
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['role']) && ($_SESSION['role'] === 'student' || $_SESSION['role'] === 'admin');
$allow_public_certificates = getenv('ALLOW_PUBLIC_CERTIFICATES') === '1';
$previewMode = !empty($_GET['preview_mode']);

function denyCertificateAccess(string $message, int $statusCode = 403): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Certificate unavailable</title></head>';
    echo '<body style="font-family:Arial,sans-serif;background:#f8fafc;padding:40px;color:#334155;">';
    echo '<div style="max-width:560px;margin:60px auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;">';
    echo '<h2 style="margin-top:0;color:#0f172a;">Certificate unavailable</h2>';
    echo '<p style="line-height:1.6;">' . htmlspecialchars($message) . '</p>';
    echo '</div></body></html>';
    exit();
}

if ($previewMode && (!$is_logged_in || ($_SESSION['role'] ?? '') !== 'admin')) {
    denyCertificateAccess('Certificate previews are restricted to administrators.');
}

if (!$is_logged_in && $allow_public_certificates) {
    $public_session = $_GET['session'] ?? '';
    if ($public_session) {
        ensureToeicSwSchema($conn);
        $stmt = $conn->prepare('SELECT id FROM toeic_sw_test_results WHERE test_session = ?');
        $stmt->bind_param('s', $public_session);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $public_access = true;
        }
        $stmt->close();
    }
    if (!$public_access) {
        header("Location: ../login.php");
        exit();
    }
} elseif (!$is_logged_in) {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();

// Fetch certificate settings (SW can use cert_template_toeic_sw, fall back to cert_template)
$cert_settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'cert_%'");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cert_settings[$row['setting_key']] = $row['setting_value'];
    }
    $res->free();
}

$defaults = [
    'cert_template'             => 'professional_toeic',
    'cert_template_toeic_sw'    => 'professional_toeic',
    'cert_title'                => 'TOEIC Speaking &amp; Writing Score Report',
    'cert_subtitle'             => 'English Language Practice Assessment<br><strong>Full Simulation Result</strong>',
    'cert_signature_name'       => 'Practice Platform',
    'cert_signature_title'      => 'Authorized TOEIC Center',
    'cert_institution'          => 'Practice Assessment Center',
    'cert_location'             => 'Indonesia',
    'cert_primary_color'        => '#0a2540',
    'cert_secondary_color'      => '#1e63d6',
    'cert_image_institution'    => 'halokak',
];
$cert_settings = array_merge($defaults, $cert_settings);
$cert_settings['cert_template']        = normalizeCertificateTemplate($cert_settings['cert_template_toeic_sw'] ?? $cert_settings['cert_template']);
$cert_settings['cert_primary_color']   = normalizeCertificateColor($cert_settings['cert_primary_color'],   $defaults['cert_primary_color']);
$cert_settings['cert_secondary_color'] = normalizeCertificateColor($cert_settings['cert_secondary_color'], $defaults['cert_secondary_color']);
$cert_settings['cert_image_institution'] = normalizeCertificateImageInstitution($cert_settings['cert_image_institution']);

$test_session = $_GET['session'] ?? '';

if ($previewMode && ($_SESSION['role'] ?? '') === 'admin' && isset($_GET['template'])) {
    $cert_settings['cert_template'] = normalizeCertificateTemplate($_GET['template']);
}

$certificate_template = $cert_settings['cert_template'];

if ($previewMode) {
    $result = [
        'test_session'   => 'PREVIEW_SW_SESSION',
        'user_id'        => 0,
        'speaking_scaled'=> 150,
        'writing_scaled' => 165,
        'total_score'    => 315,
        'cefr_level'     => 'B2',
        'completed_at'   => date('Y-m-d H:i:s'),
        'full_name'      => 'John Doe',
        'practice_mode'  => 0,
        'status'         => 'completed',
    ];
} elseif (empty($test_session)) {
    denyCertificateAccess('Missing test_session parameter.', 400);
} else {
    $users_id_col = 'id';
    $users_id_check = $conn->query("SHOW COLUMNS FROM users LIKE 'id_user'");
    if ($users_id_check && $users_id_check->num_rows > 0) {
        $users_id_col = 'id_user';
    }
    ensureToeicSwSchema($conn);

    if ($public_access || ($_SESSION['role'] ?? '') === 'admin') {
        $stmt = $conn->prepare("
            SELECT r.*, u.full_name, s.status, s.practice_mode
            FROM toeic_sw_test_results r
            LEFT JOIN users u ON u.{$users_id_col} = r.user_id
            LEFT JOIN toeic_sw_test_sessions s ON s.test_session = r.test_session
            WHERE r.test_session = ?
        ");
        $stmt->bind_param('s', $test_session);
    } else {
        $stmt = $conn->prepare("
            SELECT r.*, u.full_name, s.status, s.practice_mode
            FROM toeic_sw_test_results r
            LEFT JOIN users u ON u.{$users_id_col} = r.user_id
            LEFT JOIN toeic_sw_test_sessions s ON s.test_session = r.test_session
            WHERE r.test_session = ? AND r.user_id = ?
        ");
        $stmt->bind_param('si', $test_session, $_SESSION['user_id']);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$result) {
    denyCertificateAccess('Certificate not found for this test session.', 404);
}

if (!$previewMode) {
    $access = getCertificateAccessState($result, $result['status'] ?? null, 'sw');
    if (!$access['allowed']) {
        denyCertificateAccess($access['reason']);
    }
}

$totalScore = (int)($result['total_score'] ?? 0);
$cefr = (string)($result['cefr_level'] ?? '');

function toeicSwScoreLevel(int $total): array {
    if ($total >= 360) return ['Expert', 'C1'];
    if ($total >= 280) return ['Advanced', 'B2'];
    if ($total >= 200) return ['Intermediate', 'B1'];
    if ($total >= 120) return ['Developing', 'A2'];
    return ['Beginner', 'A1'];
}

$levelData = toeicSwScoreLevel($totalScore);
$levelLabel = $levelData[0];
$level      = $levelData[1] ?: $cefr;

$cert_date = !empty($result['completed_at']) ? date('j M Y', strtotime($result['completed_at'])) : date('j M Y');

$format = strtolower($_GET['format'] ?? 'pdf');
$forceHtml = ($format === 'html' || $previewMode);

if ($previewMode && ($_SESSION['role'] ?? '') === 'admin' && isset($_GET['image_institution'])) {
    $cert_settings['cert_image_institution'] = normalizeCertificateImageInstitution($_GET['image_institution']);
}

$requestedImageTemplateId = max(0, (int)($_GET['image_template_id'] ?? 0));
$imageTemplate = $requestedImageTemplateId > 0
    ? getCertificateImageTemplateById($requestedImageTemplateId)
    : getDefaultCertificateImageTemplate($cert_settings['cert_image_institution']);
$certificate_background = $imageTemplate['image_path'] ?? '';
if ($certificate_background !== '' && file_exists(__DIR__ . '/../' . ltrim($certificate_background, '/'))) {
    $certificate_background = realpath(__DIR__ . '/../' . ltrim($certificate_background, '/'));
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$appBasePath = preg_replace('#/user$#', '', rtrim($scriptDir, '/'));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$verifyUrl = rtrim($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $appBasePath, '/') . '/verify.php?session=' . urlencode((string)($result['test_session'] ?? ''));
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($verifyUrl);

$pdfData = [
    'website_title'    => $website_title,
    'template'         => $certificate_template,
    'domain'           => 'sw',
    'title'            => $cert_settings['cert_title'],
    'subtitle'         => $cert_settings['cert_subtitle'],
    'signature_name'   => $cert_settings['cert_signature_name'],
    'signature_title'  => $cert_settings['cert_signature_title'],
    'primary_color'    => $cert_settings['cert_primary_color'],
    'secondary_color'  => $cert_settings['cert_secondary_color'],
    'full_name'        => $result['full_name'] ?? 'Student',
    'total_score'      => $totalScore,
    'max_score'        => 400,
    'level'            => $level,
    'level_label'      => $levelLabel,
    'section_a_label'  => 'Speaking',
    'section_a_score'  => (int)($result['speaking_scaled'] ?? 0),
    'section_b_label'  => 'Writing',
    'section_b_score'  => (int)($result['writing_scaled'] ?? 0),
    'cert_date'        => $cert_date,
    'institution'      => $cert_settings['cert_institution'] ?? $cert_settings['cert_signature_name'] ?? 'Practice Platform',
    'location'         => $cert_settings['cert_location'] ?? 'Indonesia',
    'qr_url'           => $qrUrl,
    'certificate_id'   => $result['test_session'] ?? '',
    'image_path'       => $certificate_background,
    'image_institution' => $cert_settings['cert_image_institution'],
];

if ($forceHtml) {
    $pdfHtml = renderCertificatePdfHtml($pdfData);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($website_title); ?> SW Certificate - <?php echo htmlspecialchars($pdfData['full_name']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Great+Vibes&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>
            body { margin: 0; background: #e8e8e8; padding: 20px; font-family: 'Inter', sans-serif; }
            .actions { max-width: 297mm; margin: 20px auto; text-align: center; }
            .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; text-decoration: none; margin: 0 6px; border: none; cursor: pointer; }
            .btn-print { background: linear-gradient(135deg, #0a2540, #1e63d6); color: white; }
            .btn-back  { background: #fff; color: #0a2540; border: 2px solid #ddd; }
            .actions-bar { display: flex; justify-content: space-between; align-items: center; max-width: 297mm; margin: 0 auto 10px; padding: 0 6px; }
            .actions-bar .left { font-size: 13px; color: #475569; }
            .actions-bar .right a { color: #1e63d6; text-decoration: none; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class="actions-bar">
            <div class="left"><i class="fas fa-certificate me-1"></i> TOEIC S&amp;W Certificate Preview</div>
            <div class="right">
                <a href="?session=<?php echo urlencode($result['test_session']); ?>&format=pdf" target="_blank"><i class="fas fa-download me-1"></i> Download PDF</a>
            </div>
        </div>
        <?php echo $pdfHtml; ?>
        <div class="actions">
            <button onclick="window.print()" class="btn btn-print"><i class="fas fa-print"></i> Print Certificate</button>
            <a href="result_toeic_sw.php?session=<?php echo urlencode($result['test_session']); ?>" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back to Results</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pdfHtml = renderCertificatePdfHtml($pdfData);
$filename = 'TOEIC_SW_Certificate_' . preg_replace('/[^a-zA-Z0-9]/', '_', $pdfData['full_name']) . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $result['test_session']) . '.pdf';
attemptPdfGeneration($pdfHtml, $filename, 'L', !empty($_GET['download']));
