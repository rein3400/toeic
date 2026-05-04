<?php
require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_utils.php';
require_once __DIR__ . '/../includes/toeic_c2_package_importer.php';

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    echo "Database connection is unavailable.";
    exit();
}

$root = dirname(__DIR__);
$contentRoot = $root . '/content/generated/toeic_packages';
$defaultR2BaseUrl = getenv('R2_PUBLIC_BASE_URL');
if ($defaultR2BaseUrl === false || trim($defaultR2BaseUrl) === '') {
    $defaultR2BaseUrl = 'https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev';
}

function toeicC2SetupEnvValue(string $name): string {
    $value = getenv($name);
    return $value === false ? '' : trim($value);
}

function toeicC2SetupBootstrapToken(): string {
    $token = toeicC2SetupEnvValue('TOEIC_SETUP_TOKEN');
    if ($token !== '') {
        return $token;
    }
    return toeicC2SetupEnvValue('SETUP_BOOTSTRAP_TOKEN');
}

function toeicC2SetupSafeCount(mysqli $conn, string $table): int {
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$result || $result->num_rows === 0) {
        return 0;
    }
    $count = $conn->query("SELECT COUNT(*) AS total FROM `$table`");
    return $count ? (int)($count->fetch_assoc()['total'] ?? 0) : 0;
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

$usersTableExists = checkTableExists($conn, 'users');
$bootstrapMode = !$usersTableExists;
$providedBootstrapToken = isset($_REQUEST['bootstrap_token']) ? trim((string)$_REQUEST['bootstrap_token']) : trim((string)($_GET['token'] ?? ''));
$configuredBootstrapToken = toeicC2SetupBootstrapToken();
$hasAdminSession = isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (!$hasAdminSession) {
    if ($bootstrapMode) {
        if ($configuredBootstrapToken === '' || !hash_equals($configuredBootstrapToken, $providedBootstrapToken)) {
            http_response_code(403);
            echo "Bootstrap token required. Set TOEIC_SETUP_TOKEN in .env and open this page with ?token=YOUR_TOKEN.";
            exit();
        }
    } else {
        header('Location: ../login.php');
        exit();
    }
}

$form = [
    'from' => isset($_POST['from']) ? (int)$_POST['from'] : 2,
    'to' => isset($_POST['to']) ? (int)$_POST['to'] : 10,
    'r2_base_url' => isset($_POST['r2_base_url']) ? trim((string)$_POST['r2_base_url']) : rtrim((string)$defaultR2BaseUrl, '/'),
    'dry_run' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['dry_run']) : true,
    'verify_media' => isset($_POST['verify_media']),
];

$result = null;
$error = null;
$migrationLog = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        $error = 'Invalid CSRF token.';
    } else {
        try {
            if ($bootstrapMode && ($configuredBootstrapToken === '' || !hash_equals($configuredBootstrapToken, $providedBootstrapToken))) {
                throw new RuntimeException('Bootstrap token mismatch.');
            }
            if (!is_dir($contentRoot)) {
                throw new RuntimeException("Content root not found: $contentRoot");
            }

            ob_start();
            require __DIR__ . '/../scripts/migrate_toeic_standalone.php';
            $migrationOutput = trim((string)ob_get_clean());
            foreach (preg_split('/\R+/', $migrationOutput) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $migrationLog[] = $line;
                }
            }

            $result = toeicC2ImportPackages($conn, $contentRoot, [
                'from' => $form['from'],
                'to' => $form['to'],
                'r2_base_url' => $form['r2_base_url'],
                'dry_run' => $form['dry_run'],
                'verify_media' => $form['verify_media'],
            ]);
            $result['logs'] = array_merge($migrationLog, $result['logs']);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $result = ['logs' => $migrationLog];
        }
    }
}

$snapshot = [
    'toeic_photos' => toeicC2SetupSafeCount($conn, 'toeic_photos'),
    'toeic_audio' => toeicC2SetupSafeCount($conn, 'toeic_audio'),
    'toeic_teks' => toeicC2SetupSafeCount($conn, 'toeic_teks'),
    'toeic_soal_listening' => toeicC2SetupSafeCount($conn, 'toeic_soal_listening'),
    'toeic_soal_reading' => toeicC2SetupSafeCount($conn, 'toeic_soal_reading'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC C2 R2 Package Import</title>
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
                    <h1 class="h3 mb-2">TOEIC C2 R2 Package Import</h1>
                    <p class="muted mb-0">
                        Imports generated C2 packages with Cloudflare R2 media URLs, package-aware numbering, and deterministic quality cleanup.
                    </p>
                </div>
                <a href="<?php echo $bootstrapMode ? '../index.php' : 'index.php'; ?>" class="btn btn-outline-secondary">
                    <?php echo $bootstrapMode ? 'Back to Site' : 'Back to Admin'; ?>
                </a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="panel h-100">
                    <h2 class="h5 mb-3">Import Settings</h2>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <?php if ($bootstrapMode): ?>
                            <input type="hidden" name="bootstrap_token" value="<?php echo htmlspecialchars($providedBootstrapToken); ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-sm-3">
                                <label class="form-label" for="from">From</label>
                                <input class="form-control" id="from" name="from" type="number" min="1" max="99" value="<?php echo (int)$form['from']; ?>">
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label" for="to">To</label>
                                <input class="form-control" id="to" name="to" type="number" min="1" max="99" value="<?php echo (int)$form['to']; ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="r2_base_url">R2 Public Base URL</label>
                                <input class="form-control" id="r2_base_url" name="r2_base_url" value="<?php echo htmlspecialchars($form['r2_base_url']); ?>">
                            </div>
                        </div>

                        <div class="small mt-3">
                            <strong>Content root:</strong> <code><?php echo htmlspecialchars($contentRoot); ?></code>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="dry_run" name="dry_run" value="1" <?php echo $form['dry_run'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="dry_run">Dry run only</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="verify_media" name="verify_media" value="1" <?php echo $form['verify_media'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="verify_media">Verify each media URL with HEAD before import</label>
                        </div>
                        <button type="submit" class="btn btn-primary mt-3" onclick="return confirm('Run TOEIC C2 package import now?');">Run Import</button>
                    </form>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel h-100">
                    <h2 class="h5 mb-3">Current DB Snapshot</h2>
                    <?php foreach ($snapshot as $table => $count): ?>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span><?php echo htmlspecialchars($table); ?></span>
                            <strong><?php echo (int)$count; ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error)); ?></div>
        <?php endif; ?>

        <?php if ($result && isset($result['stats'])): ?>
            <div class="panel mb-4">
                <h2 class="h5 mb-3">Run Summary</h2>
                <div class="row g-3">
                    <?php foreach ($result['stats'] as $key => $value): ?>
                        <div class="col-md-3">
                            <div class="border rounded-2 p-3 h-100">
                                <div class="small text-uppercase muted"><?php echo htmlspecialchars(str_replace('_', ' ', $key)); ?></div>
                                <div class="h4 mb-0"><?php echo (int)$value; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($result && !empty($result['logs'])): ?>
            <div class="panel mb-4">
                <h2 class="h5 mb-3">Execution Log</h2>
                <pre><?php echo htmlspecialchars(implode(PHP_EOL, $result['logs'])); ?></pre>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
