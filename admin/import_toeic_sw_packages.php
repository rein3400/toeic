<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_sw_package_importer.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$package_root = realpath(__DIR__ . '/../content/generated/toeic_sw') ?: (__DIR__ . '/../content/generated/toeic_sw');
$default_r2_base_url = getenv('R2_PUBLIC_BASE_URL') ?: 'https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev';
$r2_base_url = $default_r2_base_url;
$use_remote_media = true;
$dry_run = true;
$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token.';
    } else {
        $posted_root = trim((string)($_POST['package_root'] ?? ''));
        if ($posted_root !== '') {
            $package_root = $posted_root;
        }
        $dry_run = isset($_POST['dry_run']);
        $use_remote_media = isset($_POST['use_remote_media']);
        $posted_r2_base_url = trim((string)($_POST['r2_base_url'] ?? ''));
        if ($posted_r2_base_url !== '') {
            $r2_base_url = $posted_r2_base_url;
        }

        try {
            $importer = new ToeicSwPackageImporter($conn);
            $result = $importer->import($package_root, $dry_run, [
                'use_remote_media' => $use_remote_media,
                'media_base_url' => $r2_base_url,
            ]);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

function toeicSwAdminH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>Import TOEIC SW Packages - <?php echo toeicSwAdminH($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 admin-content">
            <div class="admin-header">
                <h1><i class="fas fa-file-import me-3"></i>Import TOEIC Speaking & Writing</h1>
                <p class="admin-subtitle mb-0">Validate and import 10 ETS-format SW packages.</p>
            </div>

            <div class="p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo toeicSwAdminH($error); ?></div>
                <?php endif; ?>

                <div class="content-card mb-4">
                    <form method="post">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label">Package root</label>
                            <input type="text" name="package_root" class="form-control" value="<?php echo toeicSwAdminH($package_root); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">R2 public base URL</label>
                            <input type="url" name="r2_base_url" class="form-control" value="<?php echo toeicSwAdminH($r2_base_url); ?>">
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="use_remote_media" id="useRemoteMedia" <?php echo $use_remote_media ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="useRemoteMedia">Store imported media as R2 URLs</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="dry_run" id="dryRun" <?php echo $dry_run ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="dryRun">Dry run only</label>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check me-2"></i>Validate / Import
                        </button>
                    </form>
                </div>

                <?php if ($result): ?>
                    <div class="content-card">
                        <div class="d-flex flex-wrap gap-3 mb-4">
                            <?php foreach (['packages', 'validated', 'inserted', 'updated', 'skipped', 'audio_files', 'audio_transcripts', 'image_files', 'errors'] as $key): ?>
                                <div class="p-3 rounded border" style="min-width:130px;">
                                    <div class="text-muted small text-uppercase"><?php echo toeicSwAdminH($key); ?></div>
                                    <div class="h3 mb-0">
                                        <?php
                                        $value = $result[$key] ?? 0;
                                        echo is_array($value) ? count($value) : (int)$value;
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <span class="badge bg-<?php echo !empty($result['remote_media']) ? 'success' : 'secondary'; ?>">
                                <?php echo !empty($result['remote_media']) ? 'R2 media URLs' : 'Local media paths'; ?>
                            </span>
                            <?php if (!empty($result['media_base_url'])): ?>
                                <code class="ms-2"><?php echo toeicSwAdminH($result['media_base_url']); ?></code>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($result['messages'])): ?>
                            <h5>Messages</h5>
                            <pre class="p-3 bg-dark text-light rounded" style="white-space:pre-wrap;"><?php echo toeicSwAdminH(implode("\n", $result['messages'])); ?></pre>
                        <?php endif; ?>

                        <?php if (!empty($result['error_messages'])): ?>
                            <h5 class="text-danger">Errors</h5>
                            <pre class="p-3 bg-dark text-light rounded" style="white-space:pre-wrap;"><?php echo toeicSwAdminH(implode("\n", $result['error_messages'])); ?></pre>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
