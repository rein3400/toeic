<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/toeic_sw_scorer.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$message = '';
$error = '';
ensureToeicSwSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rescore') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token.';
    } else {
        $target_session = trim((string)($_POST['test_session'] ?? ''));
        $target_user = (int)($_POST['user_id'] ?? 0);
        try {
            $scorer = new ToeicSwScorer($conn);
            $scorer->scoreSection($target_session, 'speaking');
            $scorer->scoreSection($target_session, 'writing');
            $scorer->saveResults($target_session, $target_user);
            $message = 'Rescore selesai untuk sesi ' . $target_session . '.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$rows = [];
$users_id_col = 'id';
$users_id_check = $conn->query("SHOW COLUMNS FROM users LIKE 'id_user'");
if ($users_id_check && $users_id_check->num_rows > 0) {
    $users_id_col = 'id_user';
}
$result = $conn->query("
    SELECT s.test_session, s.user_id, s.package_number, s.status, s.current_section, s.practice_mode,
           s.speaking_scaled, s.writing_scaled, s.total_score, s.started_at, s.completed_at,
           u.full_name,
           SUM(CASE WHEN sc.status = 'scored' THEN 1 ELSE 0 END) AS scored_count,
           SUM(CASE WHEN sc.status = 'fallback' THEN 1 ELSE 0 END) AS fallback_count,
           SUM(CASE WHEN sc.status = 'needs_rescore' THEN 1 ELSE 0 END) AS needs_rescore_count,
           COUNT(sc.id) AS feedback_items
    FROM toeic_sw_test_sessions s
    LEFT JOIN users u ON u.{$users_id_col} = s.user_id
    LEFT JOIN toeic_sw_subjective_scores sc ON sc.test_session = s.test_session
    GROUP BY s.id
    ORDER BY s.id DESC
    LIMIT 100
");
if ($result) {
    $rows = $result->fetch_all(MYSQLI_ASSOC);
}

function toeicSwAdminResultH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>TOEIC SW Results - <?php echo toeicSwAdminResultH($website_title); ?></title>
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
                <h1><i class="fas fa-microphone me-3"></i>TOEIC SW Results</h1>
                <p class="admin-subtitle mb-0">Review Speaking & Writing sessions, feedback status, and rescoring.</p>
            </div>

            <div class="p-4">
                <?php if ($message): ?><div class="alert alert-success"><?php echo toeicSwAdminResultH($message); ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo toeicSwAdminResultH($error); ?></div><?php endif; ?>

                <div class="content-card">
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Session</th>
                                    <th>Package</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>AI Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada sesi TOEIC SW.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo toeicSwAdminResultH($row['full_name'] ?? ('User ' . $row['user_id'])); ?></td>
                                            <td><code><?php echo toeicSwAdminResultH($row['test_session']); ?></code></td>
                                            <td>
                                                <?php echo (int)$row['package_number']; ?>
                                                <div class="small text-muted"><?php echo !empty($row['practice_mode']) ? 'Practice' : 'Full Simulation'; ?></div>
                                            </td>
                                            <td><?php echo toeicSwAdminResultH($row['status']); ?> / <?php echo toeicSwAdminResultH($row['current_section']); ?></td>
                                            <td>
                                                S <?php echo (int)($row['speaking_scaled'] ?? 0); ?> -
                                                W <?php echo (int)($row['writing_scaled'] ?? 0); ?> -
                                                Total <?php echo (int)($row['total_score'] ?? 0); ?>
                                            </td>
                                            <td>
                                                <?php echo (int)$row['feedback_items']; ?> feedback
                                                <span class="badge bg-success ms-1"><?php echo (int)$row['scored_count']; ?> scored</span>
                                                <?php if ((int)$row['fallback_count'] > 0): ?>
                                                    <span class="badge bg-secondary ms-1"><?php echo (int)$row['fallback_count']; ?> fallback</span>
                                                <?php endif; ?>
                                                <?php if ((int)$row['needs_rescore_count'] > 0): ?>
                                                    <span class="badge bg-warning text-dark ms-1"><?php echo (int)$row['needs_rescore_count']; ?> needs rescore</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-light me-1" href="toeic_sw_result_detail.php?session=<?php echo urlencode((string)$row['test_session']); ?>">
                                                    Detail
                                                </a>
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="rescore">
                                                    <input type="hidden" name="test_session" value="<?php echo toeicSwAdminResultH($row['test_session']); ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">Rescore</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
