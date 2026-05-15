<?php
require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_utils.php';
require_once __DIR__ . '/../includes/toeic_sw_package_importer.php';

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    echo "Database connection is unavailable.";
    exit();
}

$root = dirname(__DIR__);
$defaultPackageRoot = $root . '/content/generated/toeic_sw';
$defaultR2BaseUrl = getenv('R2_PUBLIC_BASE_URL');
if ($defaultR2BaseUrl === false || trim($defaultR2BaseUrl) === '') {
    $defaultR2BaseUrl = 'https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev';
}

function toeicSwImportH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function toeicSwImportEnvValue(string $name): string {
    $value = getenv($name);
    return $value === false ? '' : trim($value);
}

function toeicSwImportBootstrapToken(): string {
    $token = toeicSwImportEnvValue('TOEIC_SETUP_TOKEN');
    if ($token !== '') {
        return $token;
    }
    return toeicSwImportEnvValue('SETUP_BOOTSTRAP_TOKEN');
}

function toeicSwImportSafeCount(mysqli $conn, string $table): int {
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$result || $result->num_rows === 0) {
        return 0;
    }
    $count = $conn->query("SELECT COUNT(*) AS total FROM `{$table}`");
    return $count ? (int)($count->fetch_assoc()['total'] ?? 0) : 0;
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

$usersTableExists = checkTableExists($conn, 'users');
$bootstrapMode = !$usersTableExists;
$providedBootstrapToken = isset($_REQUEST['bootstrap_token']) ? trim((string)$_REQUEST['bootstrap_token']) : trim((string)($_GET['token'] ?? ''));
$configuredBootstrapToken = toeicSwImportBootstrapToken();
$hasAdminSession = isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (!$hasAdminSession) {
    if ($bootstrapMode) {
        if ($configuredBootstrapToken === '' || !hash_equals($configuredBootstrapToken, $providedBootstrapToken)) {
            http_response_code(403);
            echo "Bootstrap token required. Set TOEIC_SETUP_TOKEN in .env and open this page with ?token=YOUR_TOKEN.";
            exit();
        }
    } else {
        header('Location: login.php');
        exit();
    }
}

$form = [
    'package_root' => isset($_POST['package_root']) ? trim((string)$_POST['package_root']) : $defaultPackageRoot,
    'r2_base_url' => isset($_POST['r2_base_url']) ? trim((string)$_POST['r2_base_url']) : rtrim((string)$defaultR2BaseUrl, '/'),
    'use_remote_media' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['use_remote_media']) : true,
    'dry_run' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['dry_run']) : true,
    'verify_remote_media' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['verify_remote_media']) : true,
    'import_mode' => isset($_POST['import_mode']) && $_POST['import_mode'] === 'full' ? 'full' : 'images_only',
];

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        $error = 'Invalid CSRF token.';
    } else {
        try {
            if ($bootstrapMode && ($configuredBootstrapToken === '' || !hash_equals($configuredBootstrapToken, $providedBootstrapToken))) {
                throw new RuntimeException('Bootstrap token mismatch.');
            }
            if (!is_dir($form['package_root'])) {
                throw new RuntimeException("Package root not found: {$form['package_root']}");
            }

            $importer = new ToeicSwPackageImporter($conn);
            $result = $importer->import($form['package_root'], $form['dry_run'], [
                'use_remote_media' => $form['use_remote_media'],
                'media_base_url' => $form['r2_base_url'],
                'verify_remote_media' => $form['verify_remote_media'],
                'import_mode' => $form['import_mode'],
            ]);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$snapshot = [
    'toeic_sw_read_aloud' => toeicSwImportSafeCount($conn, 'toeic_sw_read_aloud'),
    'toeic_sw_describe_picture' => toeicSwImportSafeCount($conn, 'toeic_sw_describe_picture'),
    'toeic_sw_respond_questions' => toeicSwImportSafeCount($conn, 'toeic_sw_respond_questions'),
    'toeic_sw_respond_information' => toeicSwImportSafeCount($conn, 'toeic_sw_respond_information'),
    'toeic_sw_express_opinion' => toeicSwImportSafeCount($conn, 'toeic_sw_express_opinion'),
    'toeic_sw_picture_sentence' => toeicSwImportSafeCount($conn, 'toeic_sw_picture_sentence'),
    'toeic_sw_written_request' => toeicSwImportSafeCount($conn, 'toeic_sw_written_request'),
    'toeic_sw_opinion_essay' => toeicSwImportSafeCount($conn, 'toeic_sw_opinion_essay'),
];

$summaryKeys = [
    'packages',
    'validated',
    'inserted',
    'updated',
    'skipped',
    'removed_stale',
    'audio_files',
    'audio_transcripts',
    'image_files',
    'verified_media_urls',
    'errors',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC SW R2 Package Import</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; color: #12233d; }
        .panel { background: #fff; border: 1px solid #dfe7f1; border-radius: 8px; padding: 24px; box-shadow: 0 12px 28px rgba(18,35,61,0.06); }
        pre { white-space: pre-wrap; word-break: break-word; background: #0f172a; color: #e2e8f0; padding: 18px; border-radius: 8px; }
        .muted { color: #5f7089; }
        code { color: #29446f; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="panel mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h1 class="h3 mb-2">TOEIC SW R2 Package Import</h1>
                    <p class="muted mb-0">Updates TOEIC Speaking and Writing package media URLs from Cloudflare R2.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if (!$bootstrapMode): ?>
                        <a href="toeic_sw_bank.php" class="btn btn-outline-primary">Open SW Bank</a>
                    <?php endif; ?>
                    <a href="<?php echo $bootstrapMode ? '../index.php' : 'index.php'; ?>" class="btn btn-outline-secondary">
                        <?php echo $bootstrapMode ? 'Back to Site' : 'Back to Admin'; ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="panel h-100">
                    <h2 class="h5 mb-3">Import Settings</h2>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo toeicSwImportH($csrfToken); ?>">
                        <?php if ($bootstrapMode): ?>
                            <input type="hidden" name="bootstrap_token" value="<?php echo toeicSwImportH($providedBootstrapToken); ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label" for="package_root">Package root</label>
                            <input class="form-control" id="package_root" name="package_root" value="<?php echo toeicSwImportH($form['package_root']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="r2_base_url">R2 public base URL</label>
                            <input class="form-control" id="r2_base_url" name="r2_base_url" value="<?php echo toeicSwImportH($form['r2_base_url']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Import mode</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="mode_images_only" name="import_mode" value="images_only" <?php echo $form['import_mode'] === 'images_only' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="mode_images_only">Update image URLs only</label>
                                <div class="form-text">Preserves existing prompts, rubrics, answers, speaking audio, and transcripts.</div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="mode_full" name="import_mode" value="full" <?php echo $form['import_mode'] === 'full' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="mode_full">Full content import</label>
                                <div class="form-text">Use only when the manifest content should replace existing bank content.</div>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="use_remote_media" name="use_remote_media" value="1" <?php echo $form['use_remote_media'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="use_remote_media">Store imported media as R2 URLs</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="verify_remote_media" name="verify_remote_media" value="1" <?php echo $form['verify_remote_media'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="verify_remote_media">Verify each public media URL with HEAD before import</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="dry_run" name="dry_run" value="1" <?php echo $form['dry_run'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="dry_run">Dry run only</label>
                        </div>
                        <button type="submit" class="btn btn-primary mt-3" onclick="return confirm('Run TOEIC SW package import now?');">Run Import</button>
                    </form>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel h-100">
                    <h2 class="h5 mb-3">Current SW DB Snapshot</h2>
                    <?php foreach ($snapshot as $table => $count): ?>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span><?php echo toeicSwImportH($table); ?></span>
                            <strong><?php echo (int)$count; ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo nl2br(toeicSwImportH($error)); ?></div>
        <?php endif; ?>

        <?php if ($result && empty($result['errors']) && !$result['dry_run']): ?>
            <div class="alert alert-success">Import transaction committed without errors.</div>
        <?php elseif ($result && !empty($result['errors'])): ?>
            <div class="alert alert-danger">Import found errors. Database writes were rolled back when dry run was disabled.</div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="panel mb-4">
                <h2 class="h5 mb-3">Run Summary</h2>
                <div class="row g-3">
                    <?php foreach ($summaryKeys as $key): ?>
                        <div class="col-md-3">
                            <div class="border rounded-2 p-3 h-100">
                                <div class="small text-uppercase muted"><?php echo toeicSwImportH(str_replace('_', ' ', $key)); ?></div>
                                <div class="h4 mb-0">
                                    <?php
                                    $value = $result[$key] ?? 0;
                                    echo is_array($value) ? count($value) : (int)$value;
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3">
                    <span class="badge bg-<?php echo !empty($result['remote_media']) ? 'success' : 'secondary'; ?>">
                        <?php echo !empty($result['remote_media']) ? 'R2 media URLs' : 'Local media paths'; ?>
                    </span>
                    <span class="badge bg-info text-dark">
                        <?php echo ($result['import_mode'] ?? '') === 'images_only' ? 'Image URLs only' : 'Full content import'; ?>
                    </span>
                    <span class="badge bg-<?php echo !empty($result['dry_run']) ? 'warning text-dark' : 'primary'; ?>">
                        <?php echo !empty($result['dry_run']) ? 'Dry run' : 'Writes enabled'; ?>
                    </span>
                    <?php if (!empty($result['media_base_url'])): ?>
                        <code class="ms-2"><?php echo toeicSwImportH($result['media_base_url']); ?></code>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($result['messages'])): ?>
                <div class="panel mb-4">
                    <h2 class="h5 mb-3">Messages</h2>
                    <pre><?php echo toeicSwImportH(implode(PHP_EOL, $result['messages'])); ?></pre>
                </div>
            <?php endif; ?>

            <?php if (!empty($result['error_messages'])): ?>
                <div class="panel mb-4">
                    <h2 class="h5 mb-3 text-danger">Errors</h2>
                    <pre><?php echo toeicSwImportH(implode(PHP_EOL, $result['error_messages'])); ?></pre>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
