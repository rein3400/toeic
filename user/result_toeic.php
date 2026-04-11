<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();
$test_session = $_GET['session'] ?? $_SESSION['toeic_test_session'] ?? $_SESSION['test_session'] ?? null;
if (!$test_session || strpos($test_session, 'toeic_') !== 0) {
    header("Location: index.php");
    exit();
}

$session_info = getTOEICSessionInfo($_SESSION['user_id'], $test_session);
if (!$session_info) {
    header("Location: index.php");
    exit();
}

$is_practice = !empty($session_info['practice_mode']);
$practice_part = preg_replace('/[^1-7]/', '', (string)($session_info['target_part'] ?? ''));
$practice_summary = ($is_practice && $practice_part !== '') ? getTOEICPracticeSummary($_SESSION['user_id'], $test_session) : null;
$part_stats = getTOEICPartStatistics($_SESSION['user_id'], $test_session);

if ($is_practice && $practice_summary) {
    $hero_title = $practice_summary['part_info']['name'];
    $hero_label = 'TOEIC Practice Summary';
    $hero_copy = 'Practice part selesai. Gunakan hasil ini untuk melihat kekuatan Anda pada part yang baru dikerjakan sebelum masuk ke full simulation.';
    $hero_value = (int)round($practice_summary['accuracy']) . '%';
    $hero_subvalue = $practice_summary['part_info']['name'];
} else {
    if (!$is_practice) {
        $results = calculateTOEICResults($_SESSION['user_id'], $test_session);
        if (!$results) {
            $results = getTOEICTestResults($_SESSION['user_id'], $test_session);
        }
    } else {
        $results = [
            'listening_scaled' => (int)($session_info['listening_scaled'] ?? 5),
            'reading_scaled' => (int)($session_info['reading_scaled'] ?? 5),
            'total_score' => (int)($session_info['total_score'] ?? 10),
            'cefr_level' => $session_info['cefr_level'] ?? null,
        ];
    }

    if (!$results) {
        $results = [
            'listening_scaled' => 5,
            'reading_scaled' => 5,
            'total_score' => 10,
            'level' => getTOEICScoreLevel(10),
            'cefr_level' => 'A1',
        ];
    }

    $level = $results['level'] ?? getTOEICScoreLevel($results['total_score']);
    $hero_title = $is_practice ? 'Practice Simulation Score Report' : 'Score Report';
    $hero_label = $is_practice ? 'TOEIC Practice Simulation' : 'TOEIC Listening & Reading';
    $hero_copy = $is_practice
        ? 'Practice simulation selesai. Anda telah mengerjakan 200 soal TOEIC penuh tanpa proctoring, dengan hasil score report yang sama formatnya seperti full simulation.'
        : 'Simulasi TOEIC penuh selesai. Gunakan score report ini untuk melihat performa Listening, Reading, dan weakness map per part.';
    $hero_value = (int)$results['total_score'];
    $hero_subvalue = 'CEFR ' . htmlspecialchars($level[1] ?? 'A1') . ' · ' . htmlspecialchars($level[0] ?? 'TOEIC');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil TOEIC - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="css/mobile-responsive.css" rel="stylesheet">
    <style>
        body { color: #10233d; }
        .result-shell { max-width: 1080px; margin: 0 auto; padding: 3rem 1rem 4rem; }
        .hero-card, .metric-card, .panel-card { border-radius: 28px; }
        .hero-card {
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
        }
        .hero-left { padding: 3rem; }
        .hero-right {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #fff;
            padding: 3rem 2.25rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .score-display { font-size: 5rem; font-weight: 800; line-height: 1; }
        .metric-card, .panel-card { padding: 1.5rem; }
        .part-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.9rem 0;
            border-bottom: 1px solid #eef3f8;
        }
        .part-row:last-child { border-bottom: none; }
        @media (max-width: 991px) {
            .hero-card { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="result-shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard
            </a>
            <div class="text-muted small">Session: <?php echo htmlspecialchars($test_session); ?></div>
        </div>

        <section class="hero-card toeic-panel toeic-grid-lines mb-4">
            <div class="hero-left">
                <div class="text-uppercase text-muted fw-semibold small mb-2"><?php echo $hero_label; ?></div>
                <h1 class="display-6 fw-bold mb-3"><?php echo htmlspecialchars($hero_title); ?></h1>
                <p class="text-muted mb-4"><?php echo htmlspecialchars($hero_copy); ?></p>

                <?php if ($is_practice && $practice_summary): ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="metric-card toeic-stat h-100">
                                <div class="text-muted small mb-2">Correct</div>
                                <div class="h2 fw-bold mb-0"><?php echo (int)$practice_summary['correct']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric-card toeic-stat h-100">
                                <div class="text-muted small mb-2">Incorrect</div>
                                <div class="h2 fw-bold mb-0"><?php echo (int)$practice_summary['incorrect']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric-card toeic-stat h-100">
                                <div class="text-muted small mb-2">Questions</div>
                                <div class="h2 fw-bold mb-0"><?php echo (int)$practice_summary['total']; ?></div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="metric-card toeic-stat h-100">
                                <div class="text-muted small mb-2">Listening</div>
                                <div class="h2 fw-bold mb-0"><?php echo (int)$results['listening_scaled']; ?> <span class="fs-6 text-muted">/ 495</span></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="metric-card toeic-stat h-100">
                                <div class="text-muted small mb-2">Reading</div>
                                <div class="h2 fw-bold mb-0"><?php echo (int)$results['reading_scaled']; ?> <span class="fs-6 text-muted">/ 495</span></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="hero-right">
                <?php if ($is_practice && $practice_summary): ?>
                    <div class="text-uppercase small fw-semibold mb-2 opacity-75">Accuracy</div>
                <?php else: ?>
                    <div class="text-uppercase small fw-semibold mb-2 opacity-75">Total Score</div>
                <?php endif; ?>
                <div class="score-display"><?php echo htmlspecialchars((string)$hero_value); ?></div>
                <div class="mt-3 opacity-75"><?php echo $hero_subvalue; ?></div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="panel-card toeic-panel h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 fw-bold mb-0">Part Breakdown</h2>
                        <span class="text-muted small"><?php echo ($is_practice && $practice_summary) ? 'Single part analysis' : 'Listening + Reading analysis'; ?></span>
                    </div>
                    <?php foreach ($part_stats as $key => $stat): ?>
                        <?php if ($is_practice && $practice_summary && $practice_summary['part'] !== str_replace('part_', '', $key)) continue; ?>
                        <div class="part-row">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                <div class="text-muted small"><?php echo (int)$stat['correct']; ?> benar dari <?php echo (int)$stat['total']; ?> soal</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?php echo (int)$stat['percentage']; ?>%</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="panel-card toeic-panel h-100">
                    <h2 class="h5 fw-bold mb-3">Next Best Action</h2>
                    <div class="d-grid gap-3">
                        <?php if ($is_practice): ?>
                            <a href="test_instructions.php?test_format=toeic&mode=prep" class="btn btn-outline-warning py-3 fw-bold">Ulangi Practice Simulation</a>
                            <a href="test_instructions.php?test_format=toeic&mode=full" class="btn btn-warning py-3 fw-bold">Mulai Full Simulation</a>
                        <?php else: ?>
                            <a href="analytics.php" class="btn btn-outline-warning py-3 fw-bold">Buka TOEIC Analytics</a>
                            <a href="test_instructions.php?test_format=toeic&mode=prep" class="btn btn-warning py-3 fw-bold">Mulai Practice Simulation</a>
                        <?php endif; ?>
                    </div>
                    <div class="mt-4 text-muted small">
                        <?php if ($is_practice): ?>
                            Practice simulation memakai soal dan alur TOEIC yang sama seperti full simulation, hanya tanpa proctoring dan tanpa memakai paket aktif.
                        <?php else: ?>
                            Full simulation memakai proctoring dan satu paket TOEIC aktif untuk menghasilkan score report TOEIC penuh.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
