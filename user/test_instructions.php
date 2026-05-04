<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

if (!FEATURE_TOEIC) {
    $_SESSION['error'] = 'TOEIC sedang tidak tersedia.';
    header("Location: index.php");
    exit();
}

$website_title = getWebsiteTitle();
$mode = (($_GET['mode'] ?? 'full') === 'prep') ? 'prep' : 'full';
$has_full_credit = hasStrictTestCredit($conn, (int)$_SESSION['user_id'], 'toeic');
$full_credit_count = countStrictTestCredits($conn, (int)$_SESSION['user_id'], 'toeic');
$full_test_parts = [
    ['label' => 'Listening', 'detail' => 'Part 1-4 - 100 questions - 45 minutes'],
    ['label' => 'Reading', 'detail' => 'Part 5-7 - 100 questions - 75 minutes'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_instructions'])) {
    $postedMode = (($_POST['mode'] ?? 'full') === 'prep') ? 'prep' : 'full';

    $_SESSION['instructions_confirmed_toeic'] = time();
    $_SESSION['practice_mode_toeic'] = $postedMode === 'prep' ? 1 : 0;
    $_SESSION['practice_part_toeic'] = null;

    header("Location: test_toeic.php?start_new=1&mode=" . urlencode($postedMode));
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instruksi TOEIC - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/ruangguru-theme.css', '../assets/css/ruangguru-theme.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">
</head>
<body class="toeic-redesign-body toeic-student-page">
    <main class="toeic-page-shell">
        <div class="toeic-page-header">
            <div>
                <div class="toeic-kicker mb-3">TOEIC instructions</div>
                <h1 class="display-6 mb-3"><?php echo $mode === 'prep' ? 'Practice simulation instructions' : 'Full simulation instructions'; ?></h1>
                <p class="toeic-subcopy">
                    <?php if ($mode === 'prep'): ?>
                        Practice simulation runs the complete TOEIC structure without proctoring and uses one active TOEIC package.
                    <?php else: ?>
                        Full simulation runs the complete TOEIC structure with proctoring enabled and uses one active TOEIC package.
                    <?php endif; ?>
                </p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>

        <section class="toeic-hero-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <div class="toeic-kicker mb-3">TOEIC Listening and Reading</div>
                    <h2 class="display-6 text-white mb-3"><?php echo $mode === 'prep' ? 'Practice with the same structure before the monitored run.' : 'Launch a standardized TOEIC simulation with the official sequence.'; ?></h2>
                    <p class="text-white-50 mb-0">
                        <?php if ($mode === 'prep'): ?>
                            Practice simulation preserves the same order, timing, and answer flow used by the full TOEIC experience so you can rehearse the complete test architecture.
                        <?php else: ?>
                            Full simulation follows the official Listening-then-Reading order, with equipment checks and proctoring-ready flow before the session starts.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-lg-4">
                    <div class="toeic-band h-100">
                        <div class="toeic-eyebrow mb-3">Access product</div>
                        <div class="h3 mb-2"><?php echo $has_full_credit ? 'Active TOEIC package detected' : 'Active TOEIC package required'; ?></div>
                        <div class="small text-muted mb-2">Active packages: <strong><?php echo $full_credit_count; ?></strong></div>
                        <p class="small text-muted mb-0">
                            <?php if ($mode === 'prep'): ?>
                                Practice simulation uses one active TOEIC package while keeping proctoring off.
                            <?php elseif ($has_full_credit): ?>
                                Your account is ready to launch the full TOEIC simulation.
                            <?php else: ?>
                                Activate one TOEIC package before running the full simulation.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-8">
                <section class="toeic-panel p-4 p-lg-5 mb-4">
                    <div class="toeic-eyebrow mb-3">The TOEIC tests</div>
                    <h2 class="h3 mb-4">Format that follows your simulator session.</h2>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="toeic-surface p-4 h-100 <?php echo $mode !== 'prep' ? 'border border-warning' : ''; ?>">
                                <div class="toeic-eyebrow mb-3">Package + Proctor</div>
                                <h3 class="h4 mb-2">Full Simulation</h3>
                                <p class="toeic-copy mb-0">Listening 45 minutes, then Reading 75 minutes, with proctoring and one active TOEIC package.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="toeic-surface p-4 h-100 <?php echo $mode === 'prep' ? 'border border-warning' : ''; ?>">
                                <div class="toeic-eyebrow mb-3">Package + No Proctor</div>
                                <h3 class="h4 mb-2">Practice Simulation</h3>
                                <p class="toeic-copy mb-0">The same TOEIC flow without proctoring, using one active package at launch.</p>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($full_test_parts as $item): ?>
                            <div class="col-md-6">
                                <div class="toeic-stat h-100">
                                    <div class="toeic-stat-value"><?php echo htmlspecialchars($item['label']); ?></div>
                                    <div class="toeic-stat-label"><?php echo htmlspecialchars($item['detail']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="toeic-panel p-4 p-lg-5">
                    <div class="toeic-eyebrow mb-3">Ready check</div>
                    <h2 class="h3 mb-4">Rules before you begin.</h2>
                    <ul class="toeic-list-check">
                        <li>Use headphones or earphones so the Listening audio can be heard clearly.</li>
                        <li>The timer runs continuously while a section is active. There is no pause during the session.</li>
                        <li>Both modes keep the TOEIC order: Listening first, then Reading.</li>
                        <?php if ($mode === 'prep'): ?>
                            <li>Practice simulation keeps the same layout, timer, and question flow without proctoring, and uses one active package at launch.</li>
                        <?php else: ?>
                            <li>Full simulation uses proctoring. Do not switch tabs, excessively resize the window, or replay audio by other means.</li>
                        <?php endif; ?>
                        <li>Both modes save answers per question and end in a TOEIC score or summary view.</li>
                    </ul>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="toeic-band position-sticky" style="top: 2rem;">
                    <div class="toeic-eyebrow mb-3">Launch</div>
                    <h2 class="h3 mb-3">Start your TOEIC session.</h2>
                    <p class="toeic-copy mb-4">Confirm the route you want to run, then continue to the simulator flow.</p>
                    <form method="post">
                        <input type="hidden" name="mode" value="<?php echo htmlspecialchars($mode); ?>">
                        <button type="submit" name="confirm_instructions" class="btn btn-warning w-100">
                            <?php echo $mode === 'prep' ? 'Start Practice Simulation' : 'Start Full Simulation'; ?>
                        </button>
                    </form>
                    <?php if (!$has_full_credit): ?>
                        <div class="small text-muted mt-3">TOEIC simulation requires one active package for each new attempt.</div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
