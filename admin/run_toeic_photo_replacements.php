<?php
require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/settings.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$website_title = function_exists('getWebsiteTitle') ? getWebsiteTitle() : 'TOEIC Admin';
$csrf_token = generateCsrfToken();
$manifest_path = __DIR__ . '/../content/generated/toeic_photo_replacements.json';
$manifest_error = '';
$manifest = [];
$replacements = [];
$photo_rows = [];
$db_error = '';
$result = null;

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function loadReplacementManifest(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException('Replacement manifest not found: ' . $path);
    }

    $json = file_get_contents($path);
    $manifest = json_decode((string)$json, true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Replacement manifest is not valid JSON.');
    }

    $replacements = $manifest['replacements'] ?? null;
    if (!is_array($replacements)) {
        throw new RuntimeException('Replacement manifest must contain a replacements array.');
    }

    foreach ($replacements as $index => $row) {
        foreach (['old_image_file', 'new_image_file', 'public_url'] as $key) {
            if (empty($row[$key]) || !is_string($row[$key])) {
                throw new RuntimeException('Replacement row ' . ($index + 1) . ' is missing ' . $key . '.');
            }
        }

        if (!filter_var($row['public_url'], FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $row['public_url'])) {
            throw new RuntimeException('Replacement row ' . ($index + 1) . ' has an invalid public_url.');
        }
    }

    return $manifest;
}

function pathBasename(string $value): string {
    $value = trim(str_replace('\\', '/', $value));
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value)) {
        $path = parse_url($value, PHP_URL_PATH);
        $value = is_string($path) ? $path : $value;
    }

    return basename($value);
}

function photoPathMatches(string $filePath, string $expectedFile): bool {
    return strcasecmp(pathBasename($filePath), $expectedFile) === 0;
}

function photoAlreadyUsesReplacement(string $filePath, array $replacement): bool {
    $normalized = trim($filePath);
    if ($normalized === trim((string)$replacement['public_url'])) {
        return true;
    }

    return photoPathMatches($filePath, (string)$replacement['new_image_file']);
}

function loadPhotoRows(mysqli $conn): array {
    $sql = "
        SELECT tp.id_photo, tp.file_path, tp.description, COUNT(ta.id_audio) AS usage_count
        FROM toeic_photos tp
        LEFT JOIN toeic_audio ta ON ta.id_photo = tp.id_photo
        GROUP BY tp.id_photo, tp.file_path, tp.description
        ORDER BY tp.id_photo
    ";

    $rows = [];
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Unable to read toeic_photos: ' . $conn->error);
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function evaluateReplacement(array $replacement, array $photoRows): array {
    $oldMatches = [];
    $newMatches = [];

    foreach ($photoRows as $photo) {
        $path = (string)($photo['file_path'] ?? '');
        if (photoPathMatches($path, (string)$replacement['old_image_file'])) {
            $oldMatches[] = $photo;
            continue;
        }
        if (photoAlreadyUsesReplacement($path, $replacement)) {
            $newMatches[] = $photo;
        }
    }

    if (!empty($oldMatches)) {
        return [
            'status' => 'pending',
            'old_matches' => $oldMatches,
            'new_matches' => $newMatches,
            'message' => count($oldMatches) . ' row(s) still point to old image.',
        ];
    }

    if (!empty($newMatches)) {
        return [
            'status' => 'already_updated',
            'old_matches' => [],
            'new_matches' => $newMatches,
            'message' => count($newMatches) . ' row(s) already use replacement.',
        ];
    }

    return [
        'status' => 'not_found',
        'old_matches' => [],
        'new_matches' => [],
        'message' => 'No matching toeic_photos row found.',
    ];
}

function applyReplacements(mysqli $conn, array $replacements, array $photoRows, bool $dryRun): array {
    $summary = [
        'mode' => $dryRun ? 'dry_run' : 'apply',
        'checked' => count($replacements),
        'updated' => 0,
        'would_update' => 0,
        'already_updated' => 0,
        'not_found' => 0,
        'errors' => 0,
    ];
    $items = [];
    $update = null;

    if (!$dryRun) {
        $update = $conn->prepare('UPDATE toeic_photos SET file_path = ? WHERE id_photo = ?');
        if (!$update) {
            throw new RuntimeException('Unable to prepare photo update: ' . $conn->error);
        }
    }

    foreach ($replacements as $replacement) {
        $evaluation = evaluateReplacement($replacement, $photoRows);
        $item = [
            'replacement' => $replacement,
            'status' => $evaluation['status'],
            'message' => $evaluation['message'],
            'old_matches' => $evaluation['old_matches'],
            'new_matches' => $evaluation['new_matches'],
        ];

        if ($evaluation['status'] === 'already_updated') {
            $summary['already_updated']++;
        } elseif ($evaluation['status'] === 'not_found') {
            $summary['not_found']++;
        } elseif ($dryRun) {
            $summary['would_update'] += count($evaluation['old_matches']);
            $item['status'] = 'would_update';
            $item['message'] = count($evaluation['old_matches']) . ' row(s) would be updated.';
        } else {
            foreach ($evaluation['old_matches'] as $photo) {
                $publicUrl = (string)$replacement['public_url'];
                $idPhoto = (int)$photo['id_photo'];
                $update->bind_param('si', $publicUrl, $idPhoto);

                if ($update->execute()) {
                    $summary['updated']++;
                } else {
                    $summary['errors']++;
                    $item['status'] = 'error';
                    $item['message'] = 'Failed to update id_photo ' . $idPhoto . ': ' . $update->error;
                }
            }

            if ($item['status'] !== 'error') {
                $item['status'] = 'updated';
                $item['message'] = count($evaluation['old_matches']) . ' row(s) updated.';
            }
        }

        $items[] = $item;
    }

    return ['summary' => $summary, 'items' => $items];
}

try {
    $manifest = loadReplacementManifest($manifest_path);
    $replacements = $manifest['replacements'];
} catch (Throwable $e) {
    $manifest_error = $e->getMessage();
}

if ($conn instanceof mysqli && empty($manifest_error)) {
    try {
        $photo_rows = loadPhotoRows($conn);
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
} elseif (!($conn instanceof mysqli)) {
    $db_error = 'Database connection is unavailable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $result = ['error' => 'Invalid CSRF token. Refresh the page and try again.'];
    } elseif (!($conn instanceof mysqli)) {
        $result = ['error' => $db_error ?: 'Database connection is unavailable.'];
    } elseif ($manifest_error !== '') {
        $result = ['error' => $manifest_error];
    } elseif ($db_error !== '') {
        $result = ['error' => $db_error];
    } else {
        $dryRun = ($_POST['mode'] ?? 'dry_run') !== 'apply';
        try {
            $result = applyReplacements($conn, $replacements, $photo_rows, $dryRun);
            $photo_rows = loadPhotoRows($conn);
        } catch (Throwable $e) {
            $result = ['error' => $e->getMessage()];
        }
    }
}

$evaluations = [];
if (!empty($replacements) && empty($db_error)) {
    foreach ($replacements as $replacement) {
        $evaluations[] = ['replacement' => $replacement] + evaluateReplacement($replacement, $photo_rows);
    }
}

function badgeClass(string $status): string {
    return match ($status) {
        'pending', 'would_update' => 'bg-warning text-dark',
        'updated', 'already_updated' => 'bg-success',
        'not_found' => 'bg-secondary',
        'error' => 'bg-danger',
        default => 'bg-info text-dark',
    };
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Photo Replacements - <?php echo h($website_title); ?></title>
    <?php echo function_exists('getFaviconHTML') ? getFaviconHTML() : ''; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .runner-card { background: var(--glass-bg, #fff); border: 1px solid var(--glass-border, rgba(15,23,42,.12)); border-radius: 16px; padding: 1.25rem; }
        .photo-thumb { width: 160px; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 10px; border: 1px solid rgba(148,163,184,.35); background: #f8fafc; }
        .path-cell { max-width: 360px; overflow-wrap: anywhere; font-size: .82rem; }
        .table td, .table th { vertical-align: middle; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <div class="small text-uppercase text-muted fw-semibold">TOEIC Asset Repair</div>
                        <h1 class="fw-bold mb-1">Photo Replacement Runner</h1>
                        <p class="text-muted mb-0">Update imported Part 1 photo rows from artifact-heavy images to verified R2 replacements.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="manage_toeic.php?tab=images" class="btn btn-outline-light">Image Manager</a>
                        <a href="../scripts/toeic_availability_report.php" target="_blank" class="btn btn-outline-light">Availability Report</a>
                    </div>
                </div>

                <?php if ($manifest_error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($manifest_error); ?></div>
                <?php endif; ?>

                <?php if ($db_error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($db_error); ?></div>
                <?php endif; ?>

                <?php if (is_array($result) && isset($result['error'])): ?>
                    <div class="alert alert-danger"><?php echo h($result['error']); ?></div>
                <?php elseif (is_array($result) && isset($result['summary'])): ?>
                    <?php $summary = $result['summary']; ?>
                    <div class="alert alert-<?php echo $summary['errors'] > 0 ? 'danger' : 'success'; ?>">
                        <strong><?php echo $summary['mode'] === 'apply' ? 'Apply complete.' : 'Dry run complete.'; ?></strong>
                        Checked <?php echo (int)$summary['checked']; ?> replacement(s).
                        Updated <?php echo (int)$summary['updated']; ?> row(s),
                        would update <?php echo (int)$summary['would_update']; ?> row(s),
                        already updated <?php echo (int)$summary['already_updated']; ?> item(s),
                        not found <?php echo (int)$summary['not_found']; ?> item(s).
                    </div>
                <?php endif; ?>

                <div class="runner-card mb-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-lg-8">
                            <div class="small text-muted mb-1">Manifest</div>
                            <div class="fw-semibold path-cell"><?php echo h(str_replace('\\', '/', $manifest_path)); ?></div>
                            <div class="small text-muted mt-2">
                                Bucket <?php echo h($manifest['bucket'] ?? '-'); ?> ·
                                <?php echo count($replacements); ?> replacement(s) ·
                                generated <?php echo h($manifest['generated_at'] ?? '-'); ?>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <form method="POST" class="d-flex gap-2 justify-content-lg-end flex-wrap">
                                <?php echo csrfField(); ?>
                                <button type="submit" name="mode" value="dry_run" class="btn btn-outline-light" <?php echo $db_error || $manifest_error ? 'disabled' : ''; ?>>Dry Run</button>
                                <button type="submit" name="mode" value="apply" class="btn btn-primary" <?php echo $db_error || $manifest_error ? 'disabled' : ''; ?> onclick="return confirm('Apply TOEIC photo replacements to the database?')">Apply Replacements</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="runner-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Replacement</th>
                                    <th>New Photo</th>
                                    <th>Database Match</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluations as $evaluation): ?>
                                    <?php $replacement = $evaluation['replacement']; ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo h($replacement['package']); ?> · <?php echo h($replacement['item_id']); ?></div>
                                            <div class="small text-muted"><?php echo h($replacement['title'] ?? ''); ?></div>
                                            <div class="small mt-2">
                                                <span class="text-muted">Old:</span> <code><?php echo h($replacement['old_image_file']); ?></code><br>
                                                <span class="text-muted">New:</span> <code><?php echo h($replacement['new_image_file']); ?></code>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="<?php echo h($replacement['public_url']); ?>" target="_blank" rel="noopener">
                                                <img src="<?php echo h($replacement['public_url']); ?>" class="photo-thumb" alt="<?php echo h($replacement['new_image_file']); ?>">
                                            </a>
                                            <div class="path-cell mt-2"><?php echo h($replacement['public_url']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo badgeClass((string)$evaluation['status']); ?>"><?php echo h($evaluation['status']); ?></span>
                                            <div class="small text-muted mt-2"><?php echo h($evaluation['message']); ?></div>
                                            <?php foreach ($evaluation['old_matches'] as $match): ?>
                                                <div class="small mt-2">
                                                    <span class="fw-semibold">id_photo <?php echo (int)$match['id_photo']; ?></span>
                                                    · used by <?php echo (int)$match['usage_count']; ?> audio row(s)
                                                    <div class="path-cell text-muted"><?php echo h($match['file_path']); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php foreach ($evaluation['new_matches'] as $match): ?>
                                                <div class="small mt-2">
                                                    <span class="fw-semibold">id_photo <?php echo (int)$match['id_photo']; ?></span>
                                                    · already replacement
                                                    <div class="path-cell text-muted"><?php echo h($match['file_path']); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="path-cell"><?php echo h($replacement['reason'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($evaluations)): ?>
                                    <tr>
                                        <td colspan="4" class="text-muted">No replacement evaluation available.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
