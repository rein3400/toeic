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
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="css/mobile-responsive.css" rel="stylesheet">
    <style>
        body { color: #10233d; }
        .page-shell { max-width: 1180px; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .hero, .card-panel { position: relative; border-radius: 28px; }
        .hero {
            background: linear-gradient(135deg, #10233d, #1f3a61);
            color: #fff;
            padding: 2.5rem;
        }
        .action-card { height: 100%; border-radius: 24px; padding: 1.5rem; }
        .stat-chip {
            border-radius: 18px;
            background: rgba(255,255,255,0.12);
            padding: 0.85rem 1rem;
        }
        .part-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 0;
            border-bottom: 1px solid #eef3f8;
        }
        .part-row:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="page-shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div class="d-flex align-items-center gap-3">
                <?php if (!empty($website_logo) && file_exists('../' . $website_logo)): ?>
                    <img src="../<?php echo htmlspecialchars($website_logo); ?>" alt="Logo" style="height:40px; width:auto;">
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center rounded-4" style="width:44px;height:44px;background:#10233d;color:#fff;">
                        <i class="fas fa-briefcase"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="small text-muted text-uppercase fw-semibold">TOEIC Command Center</div>
                    <h1 class="h3 fw-bold mb-0"><?php echo htmlspecialchars($user_name); ?></h1>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="profile.php" class="btn btn-outline-secondary rounded-pill px-4">Profil</a>
                <a href="../logout.php" class="btn btn-outline-danger rounded-pill px-4">Logout</a>
            </div>
        </div>

        <section class="hero mb-4">
            <div class="row g-4 align-items-center toeic-grid-lines">
                <div class="col-lg-7">
                    <div class="small text-uppercase fw-semibold opacity-75 mb-2">TOEIC Listening & Reading</div>
                    <h2 class="display-6 fw-bold mb-3">Satu dashboard untuk full simulation, practice simulation, dan weakness map.</h2>
                    <p class="mb-4 opacity-75">
                        Full simulation memakai paket TOEIC aktif dan proctoring. Practice simulation menjalankan 200 soal yang sama tanpa proctoring dan tanpa menghabiskan paket aktif.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if ($has_full_credit): ?>
                            <a href="test_instructions.php?test_format=toeic&mode=full" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold">Start Full Simulation</a>
                        <?php else: ?>
                            <a href="buy_exam.php" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold">Beli Paket TOEIC</a>
                        <?php endif; ?>
                        <a href="test_instructions.php?test_format=toeic&mode=prep" class="btn btn-outline-light btn-lg rounded-pill px-4 fw-bold">Start Practice Simulation</a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="stat-chip">
                                <div class="small opacity-75">Latest Avg</div>
                                <div class="h2 fw-bold mb-0"><?php echo $avg_total ?: '0'; ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-chip">
                                <div class="small opacity-75">Best Score</div>
                                <div class="h2 fw-bold mb-0"><?php echo $best_total ?: '0'; ?></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="stat-chip">
                                <div class="small opacity-75">Paket Test Aktif</div>
                                <div class="h4 fw-bold mb-0"><?php echo $full_credit_count; ?> paket</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($active_session): ?>
            <?php $resume_mode = !empty($active_session['practice_mode']) ? 'prep' : 'full'; ?>
            <?php $resume_part = preg_replace('/[^1-7]/', '', (string)($active_session['target_part'] ?? '')); ?>
            <div class="card-panel toeic-panel p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="small text-uppercase text-muted fw-semibold mb-1">Active Session</div>
                        <div class="h5 fw-bold mb-1"><?php echo $resume_mode === 'prep' ? 'Practice simulation ready to resume' : 'Full simulation in progress'; ?></div>
                        <div class="text-muted">Current section: <?php echo htmlspecialchars(ucfirst($active_session['current_section'])); ?><?php echo $resume_part !== '' ? ' · Part ' . htmlspecialchars($resume_part) : ''; ?></div>
                    </div>
                    <a href="test_toeic.php?resume=1&test_session=<?php echo urlencode($active_session['test_session']); ?>&mode=<?php echo $resume_mode; ?><?php echo $resume_part !== '' ? '&part=' . urlencode($resume_part) : ''; ?>" class="btn btn-warning rounded-pill px-4 fw-bold">Resume Session</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="action-card toeic-panel">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Full Test</div>
                    <h3 class="h4 fw-bold">Official Section Flow</h3>
                    <p class="text-muted">Listening 45 menit lalu Reading 75 menit. Menggunakan proctoring dan satu paket TOEIC aktif.</p>
                    <a href="<?php echo $has_full_credit ? 'test_instructions.php?test_format=toeic&mode=full' : 'buy_exam.php'; ?>" class="btn btn-outline-warning rounded-pill px-4 fw-bold">Open Full Simulation</a>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="action-card toeic-panel">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Practice Mode</div>
                    <h3 class="h4 fw-bold">Full Test Without Proctor</h3>
                    <p class="text-muted">Menjalankan 200 soal TOEIC yang sama seperti full simulation, tetapi tanpa proctoring dan tanpa memakai paket aktif.</p>
                    <a href="test_instructions.php?test_format=toeic&mode=prep" class="btn btn-outline-warning rounded-pill px-4 fw-bold">Open Practice Simulation</a>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="action-card toeic-panel">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Analytics</div>
                    <h3 class="h4 fw-bold">Score Trend & Weakness Map</h3>
                    <p class="text-muted">Lihat tren score TOEIC terbaru dan part breakdown dari full simulation yang sudah selesai.</p>
                    <a href="analytics.php" class="btn btn-outline-warning rounded-pill px-4 fw-bold">Open Analytics</a>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card-panel toeic-panel p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 fw-bold mb-0">Recent Full TOEIC Reports</h2>
                        <a href="buy_exam.php" class="text-decoration-none small fw-semibold">Paket TOEIC</a>
                    </div>
                    <?php if (empty($recent_results)): ?>
                        <p class="text-muted mb-0">Belum ada hasil TOEIC penuh. Mulai full simulation pertama Anda dari dashboard ini.</p>
                    <?php else: ?>
                        <?php foreach ($recent_results as $row): ?>
                            <div class="part-row">
                                <div>
                                    <div class="fw-semibold">Score <?php echo (int)$row['total_score']; ?></div>
                                    <div class="text-muted small"><?php echo date('d M Y H:i', strtotime($row['completed_at'])); ?> · Listening <?php echo (int)$row['listening_scaled']; ?> · Reading <?php echo (int)$row['reading_scaled']; ?></div>
                                </div>
                                <a href="result_toeic.php?session=<?php echo urlencode($row['test_session']); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">View</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card-panel toeic-panel p-4 h-100">
                    <h2 class="h5 fw-bold mb-3">Latest Part Breakdown</h2>
                    <?php if (empty($part_stats)): ?>
                        <p class="text-muted mb-0">Part breakdown akan muncul setelah Anda menyelesaikan full simulation TOEIC.</p>
                    <?php else: ?>
                        <?php foreach ($part_stats as $stat): ?>
                            <div class="part-row">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                    <div class="text-muted small"><?php echo (int)$stat['correct']; ?> / <?php echo (int)$stat['total']; ?> correct</div>
                                </div>
                                <div class="fw-bold"><?php echo (int)$stat['percentage']; ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
