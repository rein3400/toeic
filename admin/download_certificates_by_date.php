<?php
require_once '../includes/session_handler.php';

require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/CertificateAccess.php';
require_once '../includes/certificate_templates.php';
require_once '../includes/CertificatePdfTemplate.php';
require_once '../includes/PdfHelper.php';

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$website_title = getWebsiteTitle();
$uid = getUsersIdColumn($conn);

$date = trim($_GET['date'] ?? '');
$search = trim($_GET['search'] ?? '');
$filter_score = $_GET['filter_score'] ?? '';

$dateIsValid = false;
if ($date !== '') {
    $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
    $dateIsValid = $parsedDate && $parsedDate->format('Y-m-d') === $date;
}

if (!$dateIsValid) {
    http_response_code(400);
    echo 'Invalid or missing date. Please open this page from Test Results after selecting a date.';
    exit();
}

if (isset($_GET['progress']) && $_GET['progress'] === '1') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    sendCertificateZipProgress($_GET['download_token'] ?? '');
}

$where_conditions = ["DATE(tr.completed_at) = ?"];
$params = [$date];
$param_types = 's';

if ($search !== '') {
    $where_conditions[] = "(u.full_name LIKE ? OR u.username LIKE ? OR tr.test_session LIKE ?)";
    $searchTerm = '%' . $search . '%';
    array_push($params, $searchTerm, $searchTerm, $searchTerm);
    $param_types .= 'sss';
}

if ($filter_score !== '') {
    switch ($filter_score) {
        case 'proficient':
            $where_conditions[] = "tr.total_score >= 945";
            break;
        case 'advanced':
            $where_conditions[] = "tr.total_score >= 785 AND tr.total_score < 945";
            break;
        case 'upper':
            $where_conditions[] = "tr.total_score >= 605 AND tr.total_score < 785";
            break;
        case 'intermediate':
            $where_conditions[] = "tr.total_score >= 405 AND tr.total_score < 605";
            break;
        case 'elementary':
            $where_conditions[] = "tr.total_score < 405";
            break;
    }
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$query = "
    SELECT tr.*, u.full_name, u.username, s.status AS session_status
    FROM toeic_test_results tr
    JOIN users u ON tr.user_id = u.{$uid}
    LEFT JOIN toeic_test_sessions s ON s.test_session = tr.test_session
    $where_clause
    ORDER BY tr.completed_at ASC, u.full_name ASC
";

$rows = [];
$stmt = $conn->prepare($query);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$resultSet = $stmt->get_result();

while ($resultSet && $row = $resultSet->fetch_assoc()) {
    $row['certificate_access'] = getCertificateAccessState(
        $row,
        $row['session_status'] ?? null,
        'rl'
    );
    $rows[] = $row;
}

$eligibleRows = array_values(array_filter($rows, static function ($row) {
    return !empty($row['certificate_access']['allowed']);
}));

function getBatchCertificateSettings(mysqli $conn): array
{
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'cert_%'");
    while ($result && $row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $defaults = [
        'cert_template' => 'professional_toeic',
        'cert_title' => 'TOEIC Listening &amp; Reading Score Report',
        'cert_subtitle' => 'English Language Practice Assessment<br><strong>Full Simulation Result</strong>',
        'cert_signature_name' => 'Practice Platform',
        'cert_signature_title' => 'Authorized TOEIC Center',
        'cert_institution' => 'Practice Assessment Center',
        'cert_location' => 'Indonesia',
        'cert_image_institution' => 'halokak',
        'cert_primary_color' => '#0a2540',
        'cert_secondary_color' => '#1e63d6',
    ];

    $settings = array_merge($defaults, $settings);
    $settings['cert_template'] = normalizeCertificateTemplate($settings['cert_template']);
    $settings['cert_image_institution'] = normalizeCertificateImageInstitution($settings['cert_image_institution']);
    $settings['cert_primary_color'] = normalizeCertificateColor($settings['cert_primary_color'], $defaults['cert_primary_color']);
    $settings['cert_secondary_color'] = normalizeCertificateColor($settings['cert_secondary_color'], $defaults['cert_secondary_color']);
    $imageTemplate = getDefaultCertificateImageTemplate($settings['cert_image_institution']);
    $settings['cert_image_path'] = $imageTemplate['image_path'] ?? '';
    $settings['cert_image_layout'] = normalizeCertificateImageLayout($imageTemplate['layout_json'] ?? null);

    return $settings;
}

function safeCertificateZipName(array $row, array &$usedNames): string
{
    $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $row['full_name'] ?? 'student');
    $name = trim((string)$name, '_') ?: 'student';
    $session = preg_replace('/[^a-zA-Z0-9_-]+/', '_', substr((string)($row['test_session'] ?? ''), -8));
    $baseName = $name . '_' . ($session ?: (int)($row['id'] ?? 0));
    $filename = $baseName . '.pdf';
    $counter = 2;

    while (isset($usedNames[$filename])) {
        $filename = $baseName . '_' . $counter . '.pdf';
        $counter++;
    }

    $usedNames[$filename] = true;
    return $filename;
}

function getBatchVerifyBaseUrl(): string
{
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $appBasePath = preg_replace('#/admin$#', '', rtrim($scriptDir, '/'));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return rtrim($scheme . '://' . $host . $appBasePath, '/') . '/verify.php?session=';
}

function createCertificateZipPath(): string
{
    $tmpDir = getCertificateZipTmpDir();

    return $tmpDir . '/certificates_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.zip';
}

function getCertificateZipTmpDir(): string
{
    $tmpDir = dirname(__DIR__) . '/storage/tmp';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Could not create temporary folder for certificate ZIP.');
    }

    @chmod($tmpDir, 0777);

    if (!is_writable($tmpDir)) {
        throw new RuntimeException('Temporary ZIP folder is not writable: storage/tmp.');
    }

    return $tmpDir;
}

function sanitizeCertificateZipToken(string $token): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
}

function getCertificateZipProgressPath(string $token): string
{
    $token = sanitizeCertificateZipToken($token);
    if ($token === '') {
        throw new RuntimeException('Invalid download token.');
    }

    return getCertificateZipTmpDir() . '/cert_zip_progress_' . $token . '.json';
}

function writeCertificateZipProgress(string $token, array $data): void
{
    $token = sanitizeCertificateZipToken($token);
    if ($token === '') {
        return;
    }

    $processed = max(0, (int)($data['processed'] ?? 0));
    $total = max(0, (int)($data['total'] ?? 0));
    $percent = $total > 0 ? (int)floor(($processed / $total) * 100) : 0;
    if (($data['status'] ?? '') !== 'complete') {
        $percent = min(99, $percent);
    }

    $payload = array_merge([
        'status' => 'processing',
        'processed' => $processed,
        'total' => $total,
        'percent' => $percent,
        'current' => '',
        'message' => '',
        'updated_at' => time(),
    ], $data);
    $payload['percent'] = max(0, min(100, (int)($payload['percent'] ?? $percent)));

    try {
        file_put_contents(getCertificateZipProgressPath($token), json_encode($payload));
    } catch (Throwable $e) {
        error_log('Certificate ZIP progress write failed: ' . $e->getMessage());
    }
}

function sendCertificateZipProgress(string $token): void
{
    $token = sanitizeCertificateZipToken($token);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if ($token === '') {
        echo json_encode([
            'status' => 'queued',
            'processed' => 0,
            'total' => 0,
            'percent' => 0,
            'current' => '',
            'message' => '',
        ]);
        exit();
    }

    try {
        $path = getCertificateZipProgressPath($token);
        if (is_file($path)) {
            $payload = json_decode((string)file_get_contents($path), true);
            echo json_encode(is_array($payload) ? $payload : ['status' => 'queued', 'percent' => 0]);
        } else {
            echo json_encode([
                'status' => 'queued',
                'processed' => 0,
                'total' => 0,
                'percent' => 0,
                'current' => '',
                'message' => 'Waiting for ZIP generation to start.',
            ]);
        }
    } catch (Throwable $e) {
        echo json_encode([
            'status' => 'error',
            'processed' => 0,
            'total' => 0,
            'percent' => 0,
            'current' => '',
            'message' => $e->getMessage(),
        ]);
    }

    exit();
}

function sendCertificatesZip(array $eligibleRows, string $date, string $websiteTitle, mysqli $conn): void
{
    if (empty($eligibleRows)) {
        http_response_code(404);
        echo 'No eligible certificates found for this date.';
        exit();
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZIP extension is not available on this PHP installation.';
        exit();
    }

    @set_time_limit(300);

    $downloadToken = sanitizeCertificateZipToken($_GET['download_token'] ?? '');
    $totalCertificates = count($eligibleRows);
    writeCertificateZipProgress($downloadToken, [
        'status' => 'starting',
        'processed' => 0,
        'total' => $totalCertificates,
        'percent' => 0,
        'message' => 'Preparing certificate files.',
    ]);

    $certSettings = getBatchCertificateSettings($conn);
    $verifyBaseUrl = getBatchVerifyBaseUrl();
    try {
        $zipPath = createCertificateZipPath();
    } catch (Throwable $e) {
        http_response_code(500);
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit();
    }

    $zip = new ZipArchive();
    $zipOpen = false;
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        http_response_code(500);
        echo 'Could not open temporary ZIP file.';
        exit();
    }
    $zipOpen = true;

    $usedNames = [];
    $manifestRows = [[
        'file',
        'student',
        'username',
        'score',
        'completed_at',
        'session',
    ]];

    try {
        foreach ($eligibleRows as $index => $row) {
            writeCertificateZipProgress($downloadToken, [
                'status' => 'processing',
                'processed' => $index,
                'total' => $totalCertificates,
                'current' => $row['full_name'] ?? '',
                'message' => 'Generating certificate PDF.',
            ]);

            $totalScore = (int)$row['total_score'];
            $levelData = getTOEICScoreLevel($totalScore);
            $levelLabel = $levelData[0];
            $level = $levelData[1];
            $certDate = date('j M Y', strtotime($row['completed_at']));
            $verifyUrl = $verifyBaseUrl . rawurlencode((string)$row['test_session']);
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . rawurlencode($verifyUrl);

            $pdfHtml = renderCertificatePdfHtml([
                'website_title'   => $websiteTitle,
                'template'        => $certSettings['cert_template'],
                'domain'          => 'rl',
                'title'           => $certSettings['cert_title'],
                'subtitle'        => $certSettings['cert_subtitle'],
                'signature_name'  => $certSettings['cert_signature_name'],
                'signature_title' => $certSettings['cert_signature_title'],
                'primary_color'   => $certSettings['cert_primary_color'],
                'secondary_color' => $certSettings['cert_secondary_color'],
                'full_name'       => $row['full_name'],
                'total_score'     => $totalScore,
                'max_score'       => 990,
                'level'           => $level,
                'level_label'     => $levelLabel,
                'section_a_label' => 'Listening',
                'section_a_score' => (int)($row['listening_scaled'] ?? 0),
                'section_b_label' => 'Reading',
                'section_b_score' => (int)($row['reading_scaled'] ?? 0),
                'cert_date'       => $certDate,
                'institution'     => $certSettings['cert_institution'] ?? $certSettings['cert_signature_name'] ?? 'Practice Platform',
                'location'        => $certSettings['cert_location'] ?? 'Indonesia',
                'qr_url'          => $qrUrl,
                'certificate_id'  => $row['test_session'],
                'image_path'      => $certSettings['cert_image_path'] ?? '',
                'image_layout'    => $certSettings['cert_image_layout'] ?? [],
                'image_institution' => $certSettings['cert_image_institution'] ?? 'halokak',
            ]);

            $filename = safeCertificateZipName($row, $usedNames);
            $zip->addFromString($filename, renderPdfBinary($pdfHtml, 'L'));
            writeCertificateZipProgress($downloadToken, [
                'status' => 'processing',
                'processed' => $index + 1,
                'total' => $totalCertificates,
                'current' => $row['full_name'] ?? '',
                'message' => 'Added certificate to ZIP.',
            ]);
            $manifestRows[] = [
                $filename,
                $row['full_name'],
                $row['username'],
                $row['total_score'],
                $row['completed_at'],
                $row['test_session'],
            ];
        }

        $manifest = fopen('php://memory', 'w+');
        if ($manifest === false) {
            throw new RuntimeException('Could not create certificate ZIP manifest stream.');
        }
        foreach ($manifestRows as $manifestRow) {
            fputcsv($manifest, $manifestRow);
        }
        rewind($manifest);
        $zip->addFromString('manifest.csv', stream_get_contents($manifest));
        fclose($manifest);
        writeCertificateZipProgress($downloadToken, [
            'status' => 'finalizing',
            'processed' => $totalCertificates,
            'total' => $totalCertificates,
            'percent' => 99,
            'current' => '',
            'message' => 'Finalizing ZIP file.',
        ]);
        if (!$zip->close()) {
            throw new RuntimeException('Could not finalize certificate ZIP file.');
        }
        $zipOpen = false;
        writeCertificateZipProgress($downloadToken, [
            'status' => 'complete',
            'processed' => $totalCertificates,
            'total' => $totalCertificates,
            'percent' => 100,
            'current' => '',
            'message' => 'Download is starting.',
        ]);

        while (ob_get_level()) {
            ob_end_clean();
        }

        $downloadName = 'certificates_' . $date . '.zip';
        if ($downloadToken !== '') {
            setcookie('cert_zip_ready_' . $downloadToken, '1', time() + 300, '/', '', false, false);
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($zipPath);
    } catch (Throwable $e) {
        if ($zipOpen) {
            @$zip->close();
        }
        writeCertificateZipProgress(sanitizeCertificateZipToken($_GET['download_token'] ?? ''), [
            'status' => 'error',
            'processed' => 0,
            'total' => count($eligibleRows),
            'percent' => 0,
            'current' => '',
            'message' => $e->getMessage(),
        ]);
        @unlink($zipPath);
        http_response_code(500);
        echo 'Failed to generate certificate ZIP: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit();
    }

    @unlink($zipPath);
    exit();
}

if (isset($_GET['download_zip']) && $_GET['download_zip'] === '1') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    sendCertificatesZip($eligibleRows, $date, $website_title, $conn);
}

$backFilters = http_build_query([
    'filter_date' => $date,
    'search' => $search,
    'filter_score' => $filter_score,
]);
$zipFilters = http_build_query([
    'date' => $date,
    'search' => $search,
    'filter_score' => $filter_score,
    'download_zip' => '1',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificates by Date - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
        .main-content { margin-left: 16.66667%; min-height: 100vh; padding: 2rem; }
        @media (max-width: 768px) { .main-content { margin-left: 0; padding: 5rem 1rem 1rem; } }
        .content-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; }
        .table-custom th { background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; padding: 0.85rem 1rem; text-transform: uppercase; white-space: nowrap; }
        .table-custom td { border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.85rem; padding: 0.85rem 1rem; vertical-align: middle; }
        .table-custom tr:last-child td { border-bottom: none; }
        .btn-action { align-items: center; border-radius: 999px; display: inline-flex; font-size: 0.82rem; font-weight: 700; gap: 0.45rem; padding: 0.45rem 0.9rem; text-decoration: none; }
        .btn-action.disabled { pointer-events: none; }
        .metric { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1rem; }
        .metric-value { color: #0f172a; font-size: 1.5rem; font-weight: 800; line-height: 1; }
        .metric-label { color: #64748b; font-size: 0.76rem; font-weight: 700; letter-spacing: 0.04em; margin-top: 0.35rem; text-transform: uppercase; }
        .download-overlay { align-items: center; background: rgba(15, 23, 42, 0.55); bottom: 0; display: none; justify-content: center; left: 0; padding: 1rem; position: fixed; right: 0; top: 0; z-index: 2000; }
        .download-overlay.show { display: flex; }
        .download-box { background: #fff; border-radius: 16px; box-shadow: 0 24px 80px rgba(15, 23, 42, 0.25); max-width: 420px; padding: 1.5rem; text-align: center; width: 100%; }
        .download-spinner { animation: spin 0.8s linear infinite; border: 3px solid #dcfce7; border-top-color: #16a34a; border-radius: 999px; height: 42px; margin: 0 auto 1rem; width: 42px; }
        .download-progress-wrap { background: #e2e8f0; border-radius: 999px; height: 10px; margin: 1rem 0 0.65rem; overflow: hidden; }
        .download-progress-bar { background: linear-gradient(135deg, #22c55e, #16a34a); border-radius: inherit; height: 100%; transition: width 0.25s ease; width: 0%; }
        .download-progress-meta { align-items: center; color: #64748b; display: flex; font-size: 0.78rem; font-weight: 600; justify-content: space-between; gap: 0.75rem; }
        .download-current { color: #94a3b8; font-size: 0.75rem; line-height: 1.45; margin-top: 0.6rem; min-height: 1.1rem; word-break: break-word; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="download-overlay" id="downloadOverlay" aria-live="polite" aria-hidden="true">
        <div class="download-box">
            <div class="download-spinner"></div>
            <h6 class="fw-bold mb-2">Preparing certificate ZIP</h6>
            <p class="text-muted mb-0" style="font-size:0.9rem;">
                Generating all eligible certificates. The download will start automatically.
            </p>
            <div class="download-progress-wrap">
                <div class="download-progress-bar" id="downloadProgressBar"></div>
            </div>
            <div class="download-progress-meta">
                <span id="downloadProgressCount">Starting...</span>
                <span id="downloadProgressPercent">0%</span>
            </div>
            <div class="download-current" id="downloadProgressCurrent"></div>
        </div>
    </div>
    <iframe id="zipDownloadFrame" name="zipDownloadFrame" style="display:none;" title="ZIP download"></iframe>

    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>

            <main class="col-md-10 offset-md-2 main-content">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                    <div>
                        <h1 class="fw-bold mb-1" style="color:#0f172a;font-size:1.6rem;">Certificates by Date</h1>
                        <p class="text-muted mb-0" style="font-size:0.9rem;">
                            <?php echo htmlspecialchars(date('F j, Y', strtotime($date))); ?>
                        </p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="test_results.php?<?php echo htmlspecialchars($backFilters, ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-outline-secondary rounded-pill px-3">
                            <i class="fas fa-arrow-left me-1"></i>Back to Results
                        </a>
                        <?php if (!empty($eligibleRows)): ?>
                            <a href="download_certificates_by_date.php?<?php echo htmlspecialchars($zipFilters, ENT_QUOTES, 'UTF-8'); ?>"
                                class="btn btn-success rounded-pill px-3 js-zip-download">
                                <i class="fas fa-file-zipper me-1"></i>Download All ZIP
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="metric">
                            <div class="metric-value"><?php echo count($rows); ?></div>
                            <div class="metric-label">Total Results</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="metric">
                            <div class="metric-value"><?php echo count($eligibleRows); ?></div>
                            <div class="metric-label">Eligible Certificates</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="metric">
                            <div class="metric-value"><?php echo max(0, count($rows) - count($eligibleRows)); ?></div>
                            <div class="metric-label">Unavailable</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="metric">
                            <div class="metric-value"><?php echo htmlspecialchars(date('M j', strtotime($date))); ?></div>
                            <div class="metric-label">Selected Date</div>
                        </div>
                    </div>
                </div>

                <div class="content-card overflow-hidden">
                    <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fas fa-certificate me-2 text-muted"></i>Certificate Download List
                        </h6>
                        <span class="text-muted small">
                            Showing <?php echo count($rows); ?> result<?php echo count($rows) === 1 ? '' : 's'; ?>
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Score</th>
                                    <th>Completed At</th>
                                    <th>Status</th>
                                    <th>Session</th>
                                    <th class="text-end">Certificate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row):
                                    $access = $row['certificate_access'];
                                    $badge = [
                                        'automatic' => 'success',
                                        'approved' => 'success',
                                        'pending' => 'warning',
                                        'revoked' => 'danger',
                                        'incomplete' => 'secondary',
                                        'ineligible' => 'secondary',
                                    ][$access['status']] ?? 'secondary';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                            <div class="text-muted" style="font-size:0.75rem;">@<?php echo htmlspecialchars($row['username']); ?></div>
                                        </td>
                                        <td class="fw-bold"><?php echo (int)$row['total_score']; ?></td>
                                        <td>
                                            <div><?php echo htmlspecialchars(date('M j, Y', strtotime($row['completed_at']))); ?></div>
                                            <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars(date('g:i A', strtotime($row['completed_at']))); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $badge; ?> bg-opacity-10 text-<?php echo $badge; ?>"
                                                style="font-size:0.7rem;">
                                                <?php echo htmlspecialchars($access['label']); ?>
                                            </span>
                                            <?php if (!$access['allowed'] && !empty($access['reason'])): ?>
                                                <div class="text-muted mt-1" style="font-size:0.72rem;max-width:260px;">
                                                    <?php echo htmlspecialchars($access['reason']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code style="font-size:0.75rem;background:#f1f5f9;padding:0.2rem 0.5rem;border-radius:4px;color:#475569;">
                                                <?php echo htmlspecialchars(substr($row['test_session'], -8)); ?>
                                            </code>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($access['allowed']): ?>
                                                <a href="../user/export_certificate_toeic.php?session=<?php echo urlencode($row['test_session']); ?>&amp;download=1"
                                                    target="_blank" class="btn btn-success btn-action certificate-link">
                                                    <i class="fas fa-download"></i>Download
                                                </a>
                                            <?php else: ?>
                                                <span class="btn btn-outline-secondary btn-action disabled">
                                                    <i class="fas fa-lock"></i>Unavailable
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="fas fa-inbox fa-2x mb-3 d-block" style="color:#cbd5e1;"></i>
                                                <p class="mb-0 fw-medium">No test results found for this date.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        const overlay = document.getElementById('downloadOverlay');
        const frame = document.getElementById('zipDownloadFrame');
        const zipButtons = document.querySelectorAll('.js-zip-download');
        const progressBar = document.getElementById('downloadProgressBar');
        const progressCount = document.getElementById('downloadProgressCount');
        const progressPercent = document.getElementById('downloadProgressPercent');
        const progressCurrent = document.getElementById('downloadProgressCurrent');

        function clearCookie(name) {
            document.cookie = name + '=; Max-Age=0; path=/';
        }

        function hasCookie(name) {
            return document.cookie.split(';').some(cookie => cookie.trim().startsWith(name + '='));
        }

        function updateProgress(progress) {
            const percent = Math.max(0, Math.min(100, Number(progress.percent || 0)));
            const processed = Number(progress.processed || 0);
            const total = Number(progress.total || 0);
            progressBar.style.width = percent + '%';
            progressPercent.textContent = percent + '%';
            progressCount.textContent = total > 0 ? `${processed}/${total} certificates` : (progress.message || 'Starting...');
            progressCurrent.textContent = progress.current ? `Processing: ${progress.current}` : (progress.message || '');
        }

        zipButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const token = Date.now().toString(36) + Math.random().toString(36).slice(2);
                const cookieName = 'cert_zip_ready_' + token;
                const url = new URL(button.href, window.location.href);
                url.searchParams.set('download_token', token);
                const progressUrl = new URL(button.href, window.location.href);
                progressUrl.searchParams.set('download_token', token);
                progressUrl.searchParams.set('progress', '1');

                overlay.classList.add('show');
                overlay.setAttribute('aria-hidden', 'false');
                button.classList.add('disabled');
                button.setAttribute('aria-disabled', 'true');
                updateProgress({ percent: 0, processed: 0, total: 0, message: 'Starting ZIP generation...' });
                frame.src = url.toString();

                const startedAt = Date.now();
                const progressPoll = window.setInterval(() => {
                    fetch(progressUrl.toString(), { cache: 'no-store' })
                        .then(response => response.ok ? response.json() : null)
                        .then(progress => {
                            if (progress) {
                                updateProgress(progress);
                                if (progress.status === 'error') {
                                    throw new Error(progress.message || 'Certificate ZIP generation failed.');
                                }
                            }
                        })
                        .catch(error => {
                            window.clearInterval(progressPoll);
                            window.clearInterval(poll);
                            overlay.classList.remove('show');
                            overlay.setAttribute('aria-hidden', 'true');
                            button.classList.remove('disabled');
                            button.removeAttribute('aria-disabled');
                            alert(error.message || 'Certificate ZIP generation failed.');
                        });
                }, 700);
                const poll = window.setInterval(() => {
                    if (hasCookie(cookieName)) {
                        window.clearInterval(poll);
                        window.clearInterval(progressPoll);
                        clearCookie(cookieName);
                        updateProgress({ percent: 100, processed: 1, total: 1, message: 'Download is starting.' });
                        overlay.classList.remove('show');
                        overlay.setAttribute('aria-hidden', 'true');
                        button.classList.remove('disabled');
                        button.removeAttribute('aria-disabled');
                    } else if (Date.now() - startedAt > 600000) {
                        window.clearInterval(poll);
                        window.clearInterval(progressPoll);
                        overlay.classList.remove('show');
                        overlay.setAttribute('aria-hidden', 'true');
                        button.classList.remove('disabled');
                        button.removeAttribute('aria-disabled');
                        alert('Certificate ZIP generation timed out.');
                    }
                }, 500);
            });
        });
    })();
    </script>
</body>
</html>
