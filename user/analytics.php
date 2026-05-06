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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-user-page tc-analytics-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <a href="index.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">
                <i class="fas fa-arrow-left me-2"></i> Dashboard
            </a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <div class="mb-5">
            <span class="study-kicker">Performance Insights</span>
            <h1 class="display-5 mb-3">Score Analytics</h1>
            <p class="lead text-muted">Track your progress across all Listening and Reading attempts.</p>
        </div>

        <section class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="study-card toeic-stat h-100">
                    <div class="toeic-stat-value"><?php echo $total_attempts; ?></div>
                    <div class="toeic-stat-label">Total Attempts</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="study-card toeic-stat h-100">
                    <div class="toeic-stat-value"><?php echo $latest_score ?: '-'; ?></div>
                    <div class="toeic-stat-label">Latest Score</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="study-card toeic-stat h-100">
                    <div class="toeic-stat-value"><?php echo $avg_score ?: '-'; ?></div>
                    <div class="toeic-stat-label">Average Score</div>
                </div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-6">
                <section class="study-card h-100">
                    <span class="study-kicker">Latest Session</span>
                    <h2 class="h4 mb-4">Part-Level Accuracy</h2>

                    <?php if (empty($latest_stats)): ?>
                        <div class="text-center py-5 opacity-50">
                            <i class="fas fa-chart-line fa-3x mb-3"></i>
                            <p>Complete a full test to see part analysis.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($latest_stats as $stat): ?>
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-2">
                                        <div>
                                            <span class="fw-bold d-block"><?php echo htmlspecialchars($stat['name']); ?></span>
                                            <small class="text-muted"><?php echo (int)$stat['correct']; ?> / <?php echo (int)$stat['total']; ?> correct</small>
                                        </div>
                                        <span class="h5 fw-bold" style="color:var(--focus-blue);"><?php echo (int)$stat['percentage']; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 12px; background: rgba(0,0,0,0.05); border-radius: 6px;">
                                        <div class="progress-bar rounded-pill" style="width: <?php echo (int)$stat['percentage']; ?>%; background: var(--academy-blue);"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-lg-6">
                <section class="study-card h-100">
                    <span class="study-kicker">History</span>
                    <h2 class="h4 mb-4">Report Timeline</h2>

                    <?php if (empty($results)): ?>
                        <div class="text-center py-5 opacity-50">
                            <i class="fas fa-history fa-3x mb-3"></i>
                            <p>No history available.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-borderless align-middle">
                                <thead>
                                    <tr class="small text-muted uppercase fw-bold">
                                        <th>Date</th>
                                        <th>Score</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($results, 0, 10) as $row): ?>
                                        <tr class="border-bottom-faint">
                                            <td class="py-3">
                                                <div class="fw-bold"><?php echo date('d M Y', strtotime($row['completed_at'])); ?></div>
                                                <div class="small text-muted"><?php echo date('H:i', strtotime($row['completed_at'])); ?></div>
                                            </td>
                                            <td class="py-3">
                                                <div class="h5 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo (int)$row['total_score']; ?></div>
                                                <div class="small text-muted">L: <?php echo (int)$row['listening_scaled']; ?> · R: <?php echo (int)$row['reading_scaled']; ?></div>
                                            </td>
                                            <td class="py-3 text-end">
                                                <a href="result_toeic.php?session=<?php echo urlencode($row['test_session']); ?>" class="study-button py-1 px-3" style="min-height: 36px; font-size: 13px;">Details</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    <style>
        .border-bottom-faint {
            border-bottom: 1px solid rgba(0,0,0,0.03) !important;
        }
        .space-y-4 > * + * {
            margin-top: 1rem;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
