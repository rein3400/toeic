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
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="css/mobile-responsive.css" rel="stylesheet">
    <style>
        body { color: #10233d; }
        .shell { max-width: 1120px; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .hero, .card-panel { border-radius: 28px; }
        .hero { padding: 2rem; }
        .metric {
            background: #fff7ed;
            border: 1px solid #ffedd5;
            border-radius: 20px;
            padding: 1.2rem;
        }
        .row-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.9rem 0;
            border-bottom: 1px solid #eef3f8;
        }
        .row-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <div class="small text-uppercase text-muted fw-semibold">TOEIC Analytics</div>
                <h1 class="h3 fw-bold mb-0">Score trend dan weakness map TOEIC Anda</h1>
            </div>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">Kembali ke Dashboard</a>
        </div>

        <section class="hero toeic-panel toeic-grid-lines mb-4">
            <div class="row g-3">
                <div class="col-md-3"><div class="metric toeic-stat"><div class="small text-muted mb-1">Attempts</div><div class="h2 fw-bold mb-0"><?php echo $total_attempts; ?></div></div></div>
                <div class="col-md-3"><div class="metric toeic-stat"><div class="small text-muted mb-1">Latest</div><div class="h2 fw-bold mb-0"><?php echo $latest_score; ?></div></div></div>
                <div class="col-md-3"><div class="metric toeic-stat"><div class="small text-muted mb-1">Average</div><div class="h2 fw-bold mb-0"><?php echo $avg_score; ?></div></div></div>
                <div class="col-md-3"><div class="metric toeic-stat"><div class="small text-muted mb-1">Best</div><div class="h2 fw-bold mb-0"><?php echo $best_score; ?></div></div></div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card-panel toeic-panel p-4 h-100">
                    <h2 class="h5 fw-bold mb-3">Latest Full Simulation Breakdown</h2>
                    <?php if (empty($latest_stats)): ?>
                        <p class="text-muted mb-0">Belum ada full simulation TOEIC yang selesai.</p>
                    <?php else: ?>
                        <?php foreach ($latest_stats as $stat): ?>
                            <div class="row-item">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                    <div class="small text-muted"><?php echo (int)$stat['correct']; ?> benar dari <?php echo (int)$stat['total']; ?> soal</div>
                                </div>
                                <div class="fw-bold"><?php echo (int)$stat['percentage']; ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card-panel toeic-panel p-4 h-100">
                    <h2 class="h5 fw-bold mb-3">Recent TOEIC Sessions</h2>
                    <?php if (empty($results)): ?>
                        <p class="text-muted mb-0">Belum ada riwayat score TOEIC.</p>
                    <?php else: ?>
                        <?php foreach (array_slice($results, 0, 8) as $row): ?>
                            <div class="row-item">
                                <div>
                                    <div class="fw-semibold">Score <?php echo (int)$row['total_score']; ?></div>
                                    <div class="small text-muted"><?php echo date('d M Y H:i', strtotime($row['completed_at'])); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="small text-muted">L <?php echo (int)$row['listening_scaled']; ?> · R <?php echo (int)$row['reading_scaled']; ?></div>
                                    <a href="result_toeic.php?session=<?php echo urlencode($row['test_session']); ?>" class="small fw-semibold text-decoration-none">View report</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
