<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/toeic_quality_helpers.php';
require_once '../includes/toeic_sw_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Student';
if (($_GET['payment'] ?? '') === 'success') {
    toeicSetFlash('success', 'Pembayaran berhasil. Kredit TOEIC Anda sudah aktif.');
}
if (($_GET['error'] ?? '') === 'access_denied') {
    toeicSetFlash('error', 'Halaman yang diminta bukan milik akun Anda.');
}
$flash_messages = toeicConsumeFlashes();
$has_full_credit = hasStrictTestCredit($conn, $user_id, 'toeic');
$full_credit_count = countStrictTestCredits($conn, $user_id, 'toeic');
$has_sw_credit = hasStrictTestCredit($conn, $user_id, 'toeic_sw');
$sw_credit_count = countStrictTestCredits($conn, $user_id, 'toeic_sw');

try {
    ensureToeicSwSchema($conn);
} catch (Throwable $e) {
    error_log('TOEIC SW dashboard schema check failed: ' . $e->getMessage());
}

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

$recent_sw_results = [];
if (checkTableExists($conn, 'toeic_sw_test_results')) {
    $stmt = $conn->prepare("
        SELECT r.test_session, r.speaking_scaled, r.writing_scaled, r.total_score, r.completed_at,
               COALESCE(s.practice_mode, 0) AS practice_mode
        FROM toeic_sw_test_results r
        LEFT JOIN toeic_sw_test_sessions s
          ON s.test_session = r.test_session
         AND s.user_id = r.user_id
        WHERE r.user_id = ?
        ORDER BY r.completed_at DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $recent_sw_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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

$active_sw_session = null;
if (checkTableExists($conn, 'toeic_sw_test_sessions')) {
    $stmt = $conn->prepare("
        SELECT test_session, current_section, started_at, practice_mode
        FROM toeic_sw_test_sessions
        WHERE user_id = ?
          AND status = 'active'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $active_sw_session = $stmt->get_result()->fetch_assoc();
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

$weakest_part = null;
foreach ($part_stats as $stat) {
    if ($weakest_part === null || (int)$stat['percentage'] < (int)$weakest_part['percentage']) {
        $weakest_part = $stat;
    }
}
$next_focus_name = $weakest_part ? $weakest_part['name'] : 'Part 5 Grammar';
$next_focus_detail = $weakest_part ? ((int)$weakest_part['percentage'] . '% accuracy on latest attempt') : 'Start with a short Reading drill before full simulation.';

$initials = strtoupper(substr($user_name, 0, 1));
if (strpos($user_name, ' ') !== false) {
    $parts = explode(' ', $user_name, 2);
    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-user-page tc-dashboard-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <div class="avatar-circle" data-bs-toggle="dropdown" role="button"><?php echo htmlspecialchars($initials); ?></div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 p-2 rounded-3">
                        <li><a class="dropdown-item rounded-2 py-2" href="profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                        <li><a class="dropdown-item rounded-2 py-2" href="analytics.php"><i class="fas fa-chart-pie me-2"></i> Analytics</a></li>
                        <li><a class="dropdown-item rounded-2 py-2" href="buy_exam.php"><i class="fas fa-shopping-cart me-2"></i> Packages</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item rounded-2 py-2 text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main class="toeic-page-shell">
        <?php foreach ($flash_messages as $flash): ?>
            <div class="alert tc-page-alert <?php echo htmlspecialchars($flash['type']); ?> mb-4" role="alert">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endforeach; ?>

        <section class="tc-dashboard-hero mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <span class="study-kicker" style="color:var(--tc-amber) !important;">TOEIC Score Cockpit</span>
                    <h1 class="display-5 text-white mb-3">Selamat datang, <?php echo htmlspecialchars($user_name); ?>.</h1>
                    <p class="text-white-50 mb-4" style="font-size: 1.08rem; color: rgba(255,255,255,0.8) !important;">
                        Skor terakhir, paket aktif, dan latihan berikutnya diringkas dalam satu layar.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if ($has_full_credit): ?>
                            <a href="test_instructions.php?test_format=toeic&mode=full" class="tc-button">Mulai Simulasi Full</a>
                        <?php else: ?>
                            <a href="buy_exam.php" class="tc-button">Aktifkan Paket TOEIC</a>
                        <?php endif; ?>
                        <?php if ($has_sw_credit): ?>
                            <a href="test_instructions.php?test_format=toeic_sw&mode=full" class="tc-button-outline">SW Full</a>
                            <a href="test_instructions.php?test_format=toeic_sw&mode=prep" class="tc-button-outline">SW Practice</a>
                        <?php else: ?>
                            <a href="buy_exam.php" class="tc-button-outline">Aktifkan SW</a>
                        <?php endif; ?>
                        <a href="<?php echo $has_full_credit ? 'test_instructions.php?test_format=toeic&mode=prep' : 'buy_exam.php'; ?>" class="tc-button-outline">
                            <?php echo $has_full_credit ? 'Buka Practice' : 'Beli Paket Practice'; ?>
                        </a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="tc-stat-grid">
                        <div class="tc-stat-card toeic-stat">
                            <div class="toeic-stat-value"><?php echo $avg_total ?: 0; ?></div>
                            <div class="toeic-stat-label text-white-50">Avg Score</div>
                        </div>
                        <div class="tc-stat-card toeic-stat">
                            <div class="toeic-stat-value"><?php echo $best_total ?: 0; ?></div>
                            <div class="toeic-stat-label text-white-50">Best Score</div>
                        </div>
                        <div class="tc-stat-card toeic-stat">
                            <div class="toeic-stat-value"><?php echo $full_credit_count + $sw_credit_count; ?></div>
                            <div class="toeic-stat-label text-white-50">Active Packages</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="tc-next-action mb-4">
            <div>
                <h2 class="h4 mb-0">Langkah berikutnya: <?php echo htmlspecialchars($next_focus_name); ?></h2>
                <p><?php echo htmlspecialchars($next_focus_detail); ?></p>
            </div>
            <a href="<?php echo $has_full_credit ? 'test_instructions.php?test_format=toeic&mode=prep' : 'buy_exam.php'; ?>" class="tc-button-outline">
                Mulai latihan
            </a>
        </section>

        <?php if ($active_session): ?>
            <?php $resume_mode = !empty($active_session['practice_mode']) ? 'prep' : 'full'; ?>
            <?php $resume_part = preg_replace('/[^1-7]/', '', (string)($active_session['target_part'] ?? '')); ?>
            <section class="study-card mb-4" style="background: #fff9e6 !important; border-color: var(--sunbeam-yellow) !important;">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-8 text-dark">
                        <span class="study-kicker">Resume Attempt</span>
                        <h2 class="h3 mb-2"><?php echo $resume_mode === 'prep' ? 'Practice session ready to resume.' : 'Full simulation in progress.'; ?></h2>
                        <p class="mb-0 text-muted">Current section: <strong><?php echo htmlspecialchars(ucfirst($active_session['current_section'])); ?></strong><?php echo $resume_part !== '' ? ' - Part ' . htmlspecialchars($resume_part) : ''; ?>.</p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <a href="test_toeic.php?resume=1&test_session=<?php echo urlencode($active_session['test_session']); ?>&mode=<?php echo $resume_mode; ?><?php echo $resume_part !== '' ? '&part=' . urlencode($resume_part) : ''; ?>" class="study-button">Resume Now</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($active_sw_session): ?>
            <?php $active_sw_mode = !empty($active_sw_session['practice_mode']) ? 'prep' : 'full'; ?>
            <section class="study-card mb-4" style="background: #eef6ff !important; border-color: rgba(72,127,181,0.35) !important;">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-8 text-dark">
                        <span class="study-kicker">Resume SW <?php echo $active_sw_mode === 'prep' ? 'Practice' : 'Full Simulation'; ?></span>
                        <h2 class="h3 mb-2">TOEIC Speaking & Writing in progress.</h2>
                        <p class="mb-0 text-muted">Current section: <strong><?php echo htmlspecialchars(ucfirst($active_sw_session['current_section'])); ?></strong>.</p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <a href="test_toeic_sw.php?resume=1&test_session=<?php echo urlencode($active_sw_session['test_session']); ?>&mode=<?php echo urlencode($active_sw_mode); ?>" class="study-button">Resume SW</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="study-card h-100">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="h4 mb-0"><i class="fas fa-history me-2 opacity-50"></i> Recent Reports</h2>
                        <a href="analytics.php" class="text-decoration-none fw-bold" style="color:var(--focus-blue);">View All</a>
                    </div>

                    <?php if (empty($recent_results)): ?>
                        <div class="text-center py-5 opacity-50">
                            <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                            <p>No test reports available yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_results as $row): ?>
                            <div class="d-flex justify-content-between align-items-center p-3 mb-2 rounded-3 border bg-light-subtle">
                                <div>
                                    <div class="fw-bold h5 mb-1" style="color:var(--focus-blue);">Score <?php echo (int)$row['total_score']; ?></div>
                                    <div class="small text-muted"><?php echo date('d M Y', strtotime($row['completed_at'])); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="small fw-bold mb-2">L <?php echo (int)$row['listening_scaled']; ?> &middot; R <?php echo (int)$row['reading_scaled']; ?></div>
                                    <a href="result_toeic.php?session=<?php echo urlencode($row['test_session']); ?>" class="study-button py-1 px-3" style="min-height: 36px; font-size: 13px;">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($recent_sw_results)): ?>
                        <div class="mt-4 pt-4 border-top">
                            <h3 class="h6 fw-bold mb-3">Speaking & Writing Reports</h3>
                            <?php foreach ($recent_sw_results as $row): ?>
                                <div class="d-flex justify-content-between align-items-center p-3 mb-2 rounded-3 border bg-light-subtle">
                                    <div>
                                        <div class="fw-bold h5 mb-1" style="color:var(--focus-blue);">SW <?php echo (int)$row['total_score']; ?>/400</div>
                                        <div class="small text-muted">
                                            <?php echo !empty($row['practice_mode']) ? 'Practice' : 'Full Simulation'; ?> -
                                            <?php echo date('d M Y', strtotime($row['completed_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small fw-bold mb-2">S <?php echo (int)$row['speaking_scaled']; ?> &middot; W <?php echo (int)$row['writing_scaled']; ?></div>
                                        <a href="result_toeic_sw.php?session=<?php echo urlencode($row['test_session']); ?>" class="study-button py-1 px-3" style="min-height: 36px; font-size: 13px;">View</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <div class="col-lg-5">
                <section class="study-card h-100">
                    <h2 class="h4 mb-4"><i class="fas fa-chart-bar me-2 opacity-50"></i> Latest Breakdown</h2>
                    <?php if (empty($part_stats)): ?>
                        <div class="text-center py-5 opacity-50">
                            <p>Part-level breakdown will appear here after your first test.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($part_stats as $stat): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-bold small"><?php echo htmlspecialchars($stat['name']); ?></span>
                                    <span class="fw-bold small" style="color:var(--focus-blue);"><?php echo (int)$stat['percentage']; ?>%</span>
                                </div>
                                <div class="progress" style="height: 10px; background: rgba(0,0,0,0.05);">
                                    <div class="progress-bar rounded-pill" style="width: <?php echo (int)$stat['percentage']; ?>%; background: var(--academy-blue);"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="mt-4 p-3 rounded-3 bg-light text-center">
                            <p class="small text-muted mb-0">Based on your latest attempt in section <strong><?php echo htmlspecialchars($latest_session); ?></strong></p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
