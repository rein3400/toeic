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
    $hero_copy = 'Practice part selesai. Gunakan ringkasan ini untuk melihat kekuatan part yang baru Anda kerjakan sebelum beralih ke full simulation.';
    $hero_value = (int)round($practice_summary['accuracy']) . '%';
    $hero_subvalue = $practice_summary['part_info']['name'];
} else {
    if (!$is_practice) {
        $results = getTOEICTestResults($_SESSION['user_id'], $test_session);
        if (!$results) {
            $results = calculateTOEICResults($_SESSION['user_id'], $test_session);
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
    $hero_title = $is_practice ? 'Practice Simulation Score Report' : 'TOEIC Score Report';
    $hero_label = $is_practice ? 'Practice Simulation' : 'TOEIC Listening and Reading';
    $hero_copy = $is_practice
        ? 'Practice simulation selesai. Anda telah menjalankan alur TOEIC yang sama tanpa proctoring, dan sekarang Anda bisa meninjau hasilnya seperti report penuh.'
        : 'Full simulation selesai. Review score report ini untuk melihat performa Listening, Reading, dan weakness map yang akan membentuk langkah berikutnya.';
    $hero_value = (int)$results['total_score'];
    $hero_subvalue = 'CEFR ' . htmlspecialchars($level[1] ?? 'A1') . ' - ' . htmlspecialchars($level[0] ?? 'TOEIC');
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
                <div class="toeic-kicker mb-3"><?php echo $hero_label; ?></div>
                <h1 class="display-6 mb-3"><?php echo htmlspecialchars($hero_title); ?></h1>
                <p class="toeic-subcopy"><?php echo htmlspecialchars($hero_copy); ?></p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>

        <section class="toeic-hero-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <div class="toeic-kicker mb-3">Session report</div>
                    <h2 class="display-6 text-white mb-3">Review the score report that follows your TOEIC simulation.</h2>
                    <p class="text-white-50 mb-0">Session: <?php echo htmlspecialchars($test_session); ?></p>
                </div>
                <div class="col-lg-5">
                    <div class="toeic-band text-center">
                        <div class="toeic-eyebrow mb-3">Total result</div>
                        <div class="display-2 mb-2"><?php echo htmlspecialchars((string)$hero_value); ?></div>
                        <div class="small text-muted"><?php echo $hero_subvalue; ?></div>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="toeic-panel p-4 h-100">
                    <div class="toeic-eyebrow mb-3">Part breakdown</div>
                    <h2 class="h4 mb-3"><?php echo ($is_practice && $practice_summary) ? 'Single-part performance' : 'Listening and Reading analysis'; ?></h2>
                    <?php foreach ($part_stats as $key => $stat): ?>
                        <?php if ($is_practice && $practice_summary && $practice_summary['part'] !== str_replace('part_', '', $key)) continue; ?>
                        <div class="toeic-table-row">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                <div class="small text-muted"><?php echo (int)$stat['correct']; ?> correct of <?php echo (int)$stat['total']; ?></div>
                            </div>
                            <div class="fw-bold"><?php echo (int)$stat['percentage']; ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </section>
            </div>
            <div class="col-lg-5">
                <section class="toeic-panel p-4 h-100">
                    <div class="toeic-eyebrow mb-3">Score view</div>
                    <h2 class="h4 mb-3">Section summary</h2>
                    <?php if ($is_practice && $practice_summary): ?>
                        <div class="toeic-table-row">
                            <div class="fw-semibold">Correct</div>
                            <div class="fw-bold"><?php echo (int)$practice_summary['correct']; ?></div>
                        </div>
                        <div class="toeic-table-row">
                            <div class="fw-semibold">Incorrect</div>
                            <div class="fw-bold"><?php echo (int)$practice_summary['incorrect']; ?></div>
                        </div>
                        <div class="toeic-table-row">
                            <div class="fw-semibold">Questions</div>
                            <div class="fw-bold"><?php echo (int)$practice_summary['total']; ?></div>
                        </div>
                    <?php else: ?>
                        <div class="toeic-table-row">
                            <div class="fw-semibold">Listening</div>
                            <div class="fw-bold"><?php echo (int)$results['listening_scaled']; ?> / 495</div>
                        </div>
                        <div class="toeic-table-row">
                            <div class="fw-semibold">Reading</div>
                            <div class="fw-bold"><?php echo (int)$results['reading_scaled']; ?> / 495</div>
                        </div>
                        <div class="toeic-table-row">
                            <div class="fw-semibold">Total</div>
                            <div class="fw-bold"><?php echo (int)$results['total_score']; ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="toeic-band mt-4">
                        <div class="toeic-eyebrow mb-3">Next best action</div>
                        <div class="d-grid gap-3">
                            <?php if ($is_practice): ?>
                                <a href="test_instructions.php?test_format=toeic&mode=prep" class="btn btn-outline-warning">Repeat Practice Simulation</a>
                                <a href="test_instructions.php?test_format=toeic&mode=full" class="btn btn-warning">Start Full Simulation</a>
                            <?php else: ?>
                                <a href="analytics.php" class="btn btn-outline-warning">Open TOEIC Analytics</a>
                                <a href="test_instructions.php?test_format=toeic&mode=prep" class="btn btn-warning">Start Practice Simulation</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
