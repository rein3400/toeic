<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

define('TOEIC_R2_ASSET_AUDIT_WEB_INCLUDE', true);
require_once __DIR__ . '/../scripts/audit_toeic_r2_assets.php';

function auditPageH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function auditPageOptionSelected(string $current, string $value): string
{
    return $current === $value ? 'selected' : '';
}

function auditPageChecked(bool $value): string
{
    return $value ? 'checked' : '';
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

$defaultPublicBaseUrl = getenv('R2_PUBLIC_BASE_URL');
if ($defaultPublicBaseUrl === false || trim($defaultPublicBaseUrl) === '') {
    $defaultPublicBaseUrl = 'https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev';
}

$form = [
    'source' => $_POST['source'] ?? 'db',
    'kind' => $_POST['kind'] ?? 'all',
    'account_id' => $_POST['account_id'] ?? (getenv('CF_ACCOUNT_ID') ?: getenv('CLOUDFLARE_ACCOUNT_ID') ?: '81decd820517795683ad5953ce03f570'),
    'bucket' => $_POST['bucket'] ?? (getenv('R2_BUCKET_NAME') ?: 'toeic-assets'),
    'public_base_url' => $_POST['public_base_url'] ?? $defaultPublicBaseUrl,
    'limit' => $_POST['limit'] ?? '',
    'timeout' => $_POST['timeout'] ?? '10',
    'public' => isset($_POST['public']),
];

$report = null;
$error = '';
$hasEnvToken = (getenv('CF_API_TOKEN') ?: getenv('CLOUDFLARE_API_TOKEN')) ? true : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        $error = 'Invalid CSRF token. Refresh halaman lalu coba lagi.';
    } else {
        try {
            $report = auditBuildReport(
                [
                    'source' => (string)$form['source'],
                    'kind' => (string)$form['kind'],
                    'public' => (bool)$form['public'],
                    'limit' => max(0, (int)$form['limit']),
                    'timeout' => max(1, (int)$form['timeout']),
                ],
                $conn instanceof mysqli ? $conn : null,
                [
                    'token' => trim((string)($_POST['cf_api_token'] ?? '')),
                    'account_id' => trim((string)$form['account_id']),
                    'bucket' => trim((string)$form['bucket']),
                    'public_base_url' => trim((string)$form['public_base_url']),
                ]
            );
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$website_title = getWebsiteTitle();
$issueRows = [];
if (is_array($report)) {
    foreach ($report['rows'] as $row) {
        if (($row['severity'] ?? 'ok') !== 'ok') {
            $issueRows[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R2 Asset Audit - <?php echo auditPageH($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .audit-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem;
        }
        .metric-strip {
            display: grid;
            grid-template-columns: repeat(6, minmax(110px, 1fr));
            gap: 0.75rem;
        }
        .metric {
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            background: rgba(255,255,255,0.035);
        }
        .metric-label {
            color: var(--text-muted);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
        }
        .metric-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text);
        }
        .issue-table {
            font-size: 0.85rem;
            vertical-align: middle;
        }
        .issue-path {
            max-width: 360px;
            word-break: break-all;
            color: var(--text-muted);
        }
        .secret-note {
            color: var(--text-muted);
            font-size: 0.82rem;
        }
        @media (max-width: 992px) {
            .metric-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 576px) {
            .metric-strip { grid-template-columns: 1fr; }
            .audit-panel { padding: 1rem; }
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <div class="small text-uppercase text-muted fw-semibold">TOEIC Media</div>
                        <h1 class="fw-bold mb-1">R2 Asset Audit</h1>
                        <p class="text-muted mb-0">Validasi URL audio dan image yang dipakai TOEIC terhadap Cloudflare R2.</p>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger border-0 rounded-3"><?php echo auditPageH($error); ?></div>
                <?php endif; ?>

                <form method="POST" class="audit-panel mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo auditPageH($csrfToken); ?>">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="source">Source</label>
                            <select class="form-select" id="source" name="source">
                                <option value="db" <?php echo auditPageOptionSelected((string)$form['source'], 'db'); ?>>DB records</option>
                                <option value="r2" <?php echo auditPageOptionSelected((string)$form['source'], 'r2'); ?>>R2 bucket</option>
                                <option value="both" <?php echo auditPageOptionSelected((string)$form['source'], 'both'); ?>>DB + R2</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="kind">Kind</label>
                            <select class="form-select" id="kind" name="kind">
                                <option value="all" <?php echo auditPageOptionSelected((string)$form['kind'], 'all'); ?>>Audio + Image</option>
                                <option value="photo" <?php echo auditPageOptionSelected((string)$form['kind'], 'photo'); ?>>Image only</option>
                                <option value="audio" <?php echo auditPageOptionSelected((string)$form['kind'], 'audio'); ?>>Audio only</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="limit">Limit</label>
                            <input class="form-control" id="limit" name="limit" type="number" min="0" placeholder="0 = all" value="<?php echo auditPageH($form['limit']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="timeout">Timeout</label>
                            <input class="form-control" id="timeout" name="timeout" type="number" min="1" max="60" value="<?php echo auditPageH($form['timeout']); ?>">
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label" for="cf_api_token">Cloudflare API Token</label>
                            <input class="form-control" id="cf_api_token" name="cf_api_token" type="password" autocomplete="off" placeholder="<?php echo $hasEnvToken ? 'Env token tersedia; kosongkan untuk pakai env' : 'Tempel token sementara untuk audit'; ?>">
                            <div class="secret-note mt-1">Token hanya dipakai untuk request audit saat submit dan tidak ditampilkan ulang.</div>
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label" for="account_id">Cloudflare Account ID</label>
                            <input class="form-control" id="account_id" name="account_id" value="<?php echo auditPageH($form['account_id']); ?>">
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label" for="bucket">R2 Bucket</label>
                            <input class="form-control" id="bucket" name="bucket" value="<?php echo auditPageH($form['bucket']); ?>">
                        </div>
                        <div class="col-lg-8">
                            <label class="form-label" for="public_base_url">R2 Public Base URL</label>
                            <input class="form-control" id="public_base_url" name="public_base_url" value="<?php echo auditPageH($form['public_base_url']); ?>">
                        </div>
                        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="public" name="public" value="1" <?php echo auditPageChecked((bool)$form['public']); ?>>
                                <label class="form-check-label" for="public">Cek public URL juga</label>
                            </div>
                            <button class="btn btn-warning rounded-pill px-4 fw-bold" type="submit">
                                <i class="fas fa-magnifying-glass-chart me-2"></i>Run Audit
                            </button>
                        </div>
                    </div>
                </form>

                <?php if (is_array($report)): ?>
                    <?php $summary = $report['summary']; ?>
                    <div class="audit-panel mb-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h2 class="h5 mb-1">Audit Result</h2>
                                <div class="text-muted small">
                                    <?php echo auditPageH($report['generated_at']); ?> · Bucket <?php echo auditPageH($report['r2']['bucket']); ?> · Indexed <?php echo (int)$report['r2']['object_index_count']; ?> objects
                                </div>
                            </div>
                            <span class="badge rounded-pill <?php echo ((int)$summary['bad'] > 0) ? 'text-bg-danger' : (((int)$summary['warn'] > 0) ? 'text-bg-warning' : 'text-bg-success'); ?>">
                                <?php echo ((int)$summary['bad'] > 0) ? 'Needs Fix' : (((int)$summary['warn'] > 0) ? 'Check Warnings' : 'All Clear'); ?>
                            </span>
                        </div>
                        <div class="metric-strip">
                            <div class="metric"><div class="metric-label">Total</div><div class="metric-value"><?php echo (int)$summary['total']; ?></div></div>
                            <div class="metric"><div class="metric-label">OK</div><div class="metric-value text-success"><?php echo (int)$summary['ok']; ?></div></div>
                            <div class="metric"><div class="metric-label">Warn</div><div class="metric-value text-warning"><?php echo (int)$summary['warn']; ?></div></div>
                            <div class="metric"><div class="metric-label">Bad</div><div class="metric-value text-danger"><?php echo (int)$summary['bad']; ?></div></div>
                            <div class="metric"><div class="metric-label">Photos</div><div class="metric-value"><?php echo (int)$summary['photo']; ?></div></div>
                            <div class="metric"><div class="metric-label">Audio</div><div class="metric-value"><?php echo (int)$summary['audio']; ?></div></div>
                        </div>
                    </div>

                    <?php if (!empty($summary['issues'])): ?>
                        <div class="audit-panel mb-4">
                            <h2 class="h6 mb-3">Issue Counts</h2>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($summary['issues'] as $issue => $count): ?>
                                    <span class="badge text-bg-secondary rounded-pill px-3 py-2"><?php echo auditPageH($issue); ?>: <?php echo (int)$count; ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="audit-panel">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h2 class="h5 mb-0">Issues</h2>
                            <span class="text-muted small"><?php echo count($issueRows); ?> rows</span>
                        </div>
                        <?php if (empty($issueRows)): ?>
                            <div class="alert alert-success mb-0 rounded-3">Tidak ada issue pada scope audit ini.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-dark table-hover issue-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Severity</th>
                                            <th>Kind</th>
                                            <th>ID</th>
                                            <th>Issues</th>
                                            <th>Path</th>
                                            <th>Matched Key</th>
                                            <th>Public</th>
                                            <th>Refs</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($issueRows, 0, 200) as $row): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?php echo (($row['severity'] ?? '') === 'bad') ? 'text-bg-danger' : 'text-bg-warning'; ?>">
                                                        <?php echo auditPageH($row['severity'] ?? ''); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo auditPageH($row['kind'] ?? ''); ?></td>
                                                <td><?php echo auditPageH($row['id'] ?? '-'); ?></td>
                                                <td><?php echo auditPageH(implode(', ', $row['issues'] ?? [])); ?></td>
                                                <td class="issue-path"><?php echo auditPageH($row['file_path'] ?? ''); ?></td>
                                                <td class="issue-path"><?php echo auditPageH($row['matched_key'] ?: 'no-match'); ?></td>
                                                <td>
                                                    <?php if (!empty($row['public']['checked'])): ?>
                                                        <span class="d-block">HTTP <?php echo auditPageH($row['public']['status'] ?? '-'); ?></span>
                                                        <span class="text-muted"><?php echo auditPageH($row['public']['content_type'] ?? ''); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not checked</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo auditPageH($row['question_refs'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (count($issueRows) > 200): ?>
                                <div class="text-muted small mt-3">Menampilkan 200 issue pertama. Gunakan limit/filter untuk mempersempit hasil.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
