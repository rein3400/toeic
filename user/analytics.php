<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/toeic_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();
$user_id = (int)$_SESSION['user_id'];
$results = [];

if (checkTableExists($conn, 'toeic_test_results')) {
    $stmt = $conn->prepare("
        SELECT test_session, listening_scaled, reading_scaled, total_score, completed_at
        FROM toeic_test_results
        WHERE user_id = ?
        ORDER BY completed_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$latest_stats = [];
if (!empty($results)) {
    $latest_stats = getTOEICPartStatistics($user_id, $results[0]['test_session']);
}

$total_attempts = count($results);
$best_score = $total_attempts ? max(array_map(fn($row) => (int)$row['total_score'], $results)) : 0;
$avg_score = $total_attempts ? (int)round(array_sum(array_map(fn($row) => (int)$row['total_score'], $results)) / $total_attempts) : 0;
$latest_score = $total_attempts ? (int)$results[0]['total_score'] : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Analytics - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT,WONK@9..144,650..800,35,0&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/ruangguru-theme.css', '../assets/css/ruangguru-theme.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body>
    <main class="toeic-page-shell">
        <div class="toeic-page-header">
            <div>
                <div class="toeic-kicker mb-3">TOEIC analytics</div>
                <h1 class="display-6 mb-3">Score trend and weakness map for every TOEIC attempt.</h1>
                <p class="toeic-subcopy">Track the score movement behind your Listening and Reading preparation, then convert the latest report into the next simulator action.</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>

        <section class="toeic-card-grid mb-4">
            <div class="toeic-stat h-100">
                <div class="toeic-stat-value"><?php echo $total_attempts; ?></div>
                <div class="toeic-stat-label">Attempts</div>
            </div>
            <div class="toeic-stat h-100">
                <div class="toeic-stat-value"><?php echo $latest_score; ?></div>
                <div class="toeic-stat-label">Latest</div>
            </div>
            <div class="toeic-stat h-100">
                <div class="toeic-stat-value"><?php echo $avg_score; ?></div>
                <div class="toeic-stat-label">Average</div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-6">
                <section class="toeic-panel p-4 h-100">
                    <div class="toeic-eyebrow mb-3">Latest full simulation breakdown</div>
                    <h2 class="h4 mb-3">Part-level performance</h2>
                    <?php if (empty($latest_stats)): ?>
                        <p class="toeic-copy mb-0">No completed full simulation is available yet.</p>
                    <?php else: ?>
                        <?php foreach ($latest_stats as $stat): ?>
                            <div class="toeic-table-row">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                    <div class="small text-muted"><?php echo (int)$stat['correct']; ?> correct of <?php echo (int)$stat['total']; ?></div>
                                </div>
                                <div class="fw-bold"><?php echo (int)$stat['percentage']; ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </div>
            <div class="col-lg-6">
                <section class="toeic-panel p-4 h-100">
                    <div class="toeic-eyebrow mb-3">Recent TOEIC sessions</div>
                    <h2 class="h4 mb-3">Report history</h2>
                    <?php if (empty($results)): ?>
                        <p class="toeic-copy mb-0">No TOEIC score history is available yet.</p>
                    <?php else: ?>
                        <?php foreach (array_slice($results, 0, 8) as $row): ?>
                            <div class="toeic-table-row">
                                <div>
                                    <div class="fw-semibold">Score <?php echo (int)$row['total_score']; ?></div>
                                    <div class="small text-muted"><?php echo date('d M Y H:i', strtotime($row['completed_at'])); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="small text-muted">L <?php echo (int)$row['listening_scaled']; ?> - R <?php echo (int)$row['reading_scaled']; ?></div>
                                    <a href="result_toeic.php?session=<?php echo urlencode($row['test_session']); ?>" class="fw-semibold text-decoration-none">View report</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
