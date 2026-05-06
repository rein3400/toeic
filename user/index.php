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
$website_logo = getWebsiteLogo();
$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Student';
$has_full_credit = hasStrictTestCredit($conn, $user_id, 'toeic');
$full_credit_count = countStrictTestCredits($conn, $user_id, 'toeic');

$recent_results = [];
if (checkTableExists($conn, 'toeic_test_results')) {
    $stmt = $conn->prepare("
        SELECT test_session, listening_scaled, reading_scaled, total_score, completed_at
        FROM toeic_test_results
        WHERE user_id = ?
        ORDER BY completed_at DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $recent_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$active_session = null;
if (checkTableExists($conn, 'toeic_test_sessions')) {
    ensureTOEICSessionModeColumns($conn);
    $hasProctoringSessions = checkTableExists($conn, 'proctoring_sessions');
    $query = "
        SELECT s.test_session, s.current_section, s.started_at, s.practice_mode, s.target_part
        FROM toeic_test_sessions s
    ";
    if ($hasProctoringSessions) {
        $query .= "
        LEFT JOIN proctoring_sessions p
            ON p.test_session = s.test_session
           AND p.user_id = s.user_id
        ";
    }
    $query .= "
        WHERE s.user_id = ?
          AND s.status = 'active'
    ";
    if ($hasProctoringSessions) {
        $query .= "
          AND (
                COALESCE(s.practice_mode, 0) = 1
                OR p.id IS NULL
                OR p.review_status = 'cleared'
                OR p.status <> 'terminated'
          )
        ";
    }
    $query .= "
        ORDER BY s.id DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $active_session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$avg_total = 0;
$best_total = 0;
if (!empty($recent_results)) {
    $scores = array_map(fn($row) => (int)$row['total_score'], $recent_results);
    $avg_total = (int)round(array_sum($scores) / count($scores));
    $best_total = max($scores);
}

$part_stats = [];
if (!empty($recent_results)) {
    $latest_session = $recent_results[0]['test_session'];
    $part_stats = getTOEICPartStatistics($user_id, $latest_session);
}

$initials = strtoupper(substr($user_name, 0, 1));
if (strpos($user_name, ' ') !== false) {
    $parts = explode(' ', $user_name, 2);
    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Dashboard - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT,WONK@9..144,650..800,35,0&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/ruangguru-theme.css', '../assets/css/ruangguru-theme.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/dark-user.css', 'css/dark-user.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body>
    <div class="bg-orbs"></div>

    <nav class="navbar navbar-expand-lg">
        <div class="container py-2">
            <a class="navbar-brand" href="index.php">
                <?php if (!empty($website_logo) && file_exists('../' . $website_logo)): ?>
                    <img src="../<?php echo htmlspecialchars($website_logo); ?>" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-briefcase"></i>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($website_title); ?></span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary" href="profile.php">Profile</a>
                <div class="dropdown">
                    <div class="avatar-circle" data-bs-toggle="dropdown" role="button"><?php echo htmlspecialchars($initials); ?></div>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark-custom mt-2">
                        <li><a class="dropdown-item" href="analytics.php">Analytics</a></li>
                        <li><a class="dropdown-item" href="buy_exam.php">Packages</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <main class="toeic-page-shell main-content">
        <section class="toeic-hero-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <div class="toeic-kicker mb-3">Powering global communication</div>
                    <h1 class="display-5 text-white mb-3">Welcome back, <?php echo htmlspecialchars($user_name); ?>.</h1>
                    <p class="text-white-50 mb-4">
                        Continue building your TOEIC global English skills report through a full simulator, a practice-first workflow, and a reporting system built around Listening and Reading performance.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if ($has_full_credit): ?>
                            <a href="test_instructions.php?test_format=toeic&mode=full" class="btn btn-warning">Launch Full Simulation</a>
                        <?php else: ?>
                            <a href="buy_exam.php" class="btn btn-warning">Activate TOEIC Package</a>
                        <?php endif; ?>
                        <a href="<?php echo $has_full_credit ? 'test_instructions.php?test_format=toeic&mode=prep' : 'buy_exam.php'; ?>" class="btn btn-outline-light">
                            <?php echo $has_full_credit ? 'Open Practice Simulation' : 'Activate Practice Package'; ?>
                        </a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="toeic-stat h-100">
                                <div class="toeic-stat-value"><?php echo $avg_total ?: 0; ?></div>
                                <div class="toeic-stat-label">Average</div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="toeic-stat h-100">
                                <div class="toeic-stat-value"><?php echo $best_total ?: 0; ?></div>
                                <div class="toeic-stat-label">Best Score</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="toeic-stat h-100">
                                <div class="toeic-stat-value"><?php echo $full_credit_count; ?></div>
                                <div class="toeic-stat-label">Active TOEIC Packages</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($active_session): ?>
            <?php $resume_mode = !empty($active_session['practice_mode']) ? 'prep' : 'full'; ?>
            <?php $resume_part = preg_replace('/[^1-7]/', '', (string)($active_session['target_part'] ?? '')); ?>
            <section class="toeic-band mb-4">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-8">
                        <div class="toeic-eyebrow mb-3">Resume current attempt</div>
                        <h2 class="h2 mb-2"><?php echo $resume_mode === 'prep' ? 'Practice simulation ready to resume.' : 'Full simulation in progress.'; ?></h2>
                        <p class="toeic-copy mb-0">
                            Current section: <?php echo htmlspecialchars(ucfirst($active_session['current_section'])); ?><?php echo $resume_part !== '' ? ' - Part ' . htmlspecialchars($resume_part) : ''; ?>.
                        </p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <a href="test_toeic.php?resume=1&test_session=<?php echo urlencode($active_session['test_session']); ?>&mode=<?php echo $resume_mode; ?><?php echo $resume_part !== '' ? '&part=' . urlencode($resume_part) : ''; ?>" class="btn btn-warning">Resume Session</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="toeic-card-grid mb-4">
            <div class="toeic-display-panel toeic-surface h-100">
                <div class="toeic-eyebrow mb-3">Primary route</div>
                <h2 class="h3 mb-3">TOEIC Listening and Reading Test</h2>
                <p class="toeic-copy mb-4">Run a complete 200-question simulation with official section order, timer discipline, and package-based activation.</p>
                <a href="<?php echo $has_full_credit ? 'test_instructions.php?test_format=toeic&mode=full' : 'buy_exam.php'; ?>" class="btn btn-warning">Open Full Simulation</a>
            </div>
            <div class="toeic-display-panel toeic-surface h-100">
                <div class="toeic-eyebrow mb-3">Practice route</div>
                <h2 class="h3 mb-3">Practice Simulation</h2>
                <p class="toeic-copy mb-4">Use the same TOEIC flow without proctoring while spending one active package for each new practice attempt.</p>
                <a href="<?php echo $has_full_credit ? 'test_instructions.php?test_format=toeic&mode=prep' : 'buy_exam.php'; ?>" class="btn btn-outline-warning">
                    <?php echo $has_full_credit ? 'Open Practice' : 'Activate Package'; ?>
                </a>
            </div>
            <div class="toeic-display-panel toeic-surface h-100">
                <div class="toeic-eyebrow mb-3">Review route</div>
                <h2 class="h3 mb-3">Analytics and Reporting</h2>
                <p class="toeic-copy mb-4">Explore latest score trends, part breakdown, and the next best action after each attempt.</p>
                <a href="analytics.php" class="btn btn-outline-secondary">Open Analytics</a>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="toeic-panel p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div>
                            <div class="toeic-eyebrow mb-2">Trusted score history</div>
                            <h2 class="h4 mb-0">Recent TOEIC reports</h2>
                        </div>
                        <a href="buy_exam.php" class="btn btn-outline-warning">Packages</a>
                    </div>
                    <?php if (empty($recent_results)): ?>
                        <p class="toeic-copy mb-0">No full simulation report is available yet. Start your first TOEIC simulation from this dashboard.</p>
                    <?php else: ?>
                        <?php foreach ($recent_results as $row): ?>
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
            <div class="col-lg-5">
                <section class="toeic-panel p-4 h-100">
                    <div class="toeic-eyebrow mb-3">Latest full simulation breakdown</div>
                    <h2 class="h4 mb-3">Part accuracy snapshot</h2>
                    <?php if (empty($part_stats)): ?>
                        <p class="toeic-copy mb-0">Part-level performance will appear after you complete a full TOEIC simulation.</p>
                    <?php else: ?>
                        <?php foreach ($part_stats as $stat): ?>
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
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
