<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/toeic_sw_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

if (!defined('FEATURE_TOEIC') || !FEATURE_TOEIC) {
    $_SESSION['error'] = 'TOEIC sedang tidak tersedia.';
    header("Location: index.php");
    exit();
}

$website_title = getWebsiteTitle();
$test_format = (($_GET['test_format'] ?? 'toeic') === 'toeic_sw') ? 'toeic_sw' : 'toeic';
$mode = (($_GET['mode'] ?? 'full') === 'prep') ? 'prep' : 'full';
if ($test_format === 'toeic_sw') {
    $mode = 'full';
}

$credit_type = $test_format === 'toeic_sw' ? 'toeic_sw' : 'toeic';
$has_full_credit = hasStrictTestCredit($conn, (int)$_SESSION['user_id'], $credit_type);
$full_credit_count = countStrictTestCredits($conn, (int)$_SESSION['user_id'], $credit_type);
$format_title = $test_format === 'toeic_sw' ? 'TOEIC Speaking & Writing' : 'TOEIC Listening & Reading';
$full_test_parts = $test_format === 'toeic_sw'
    ? [
        ['label' => 'Speaking', 'detail' => '11 Qs - about 20m - score 0-200'],
        ['label' => 'Writing', 'detail' => '8 Qs - about 60m - score 0-200'],
    ]
    : [
        ['label' => 'Listening', 'detail' => 'Part 1-4 - 100 Qs - 45m'],
        ['label' => 'Reading', 'detail' => 'Part 5-7 - 100 Qs - 75m'],
    ];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_instructions'])) {
    $postedMode = (($_POST['mode'] ?? 'full') === 'prep') ? 'prep' : 'full';
    $postedFormat = (($_POST['test_format'] ?? 'toeic') === 'toeic_sw') ? 'toeic_sw' : 'toeic';

    if ($postedFormat === 'toeic_sw') {
        $_SESSION['instructions_confirmed_toeic_sw'] = time();
        header("Location: test_toeic_sw.php?start_new=1&mode=full");
        exit();
    }

    $_SESSION['instructions_confirmed_toeic'] = time();
    $_SESSION['practice_mode_toeic'] = $postedMode === 'prep' ? 1 : 0;
    $_SESSION['practice_part_toeic'] = null;

    header("Location: test_toeic.php?start_new=1&mode=" . urlencode($postedMode));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructions - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-user-page tc-instructions-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <a href="index.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">Dashboard</a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <div class="mb-5">
            <span class="study-kicker">Test Readiness</span>
            <h1 class="display-5 mb-2"><?php echo htmlspecialchars($format_title); ?> <?php echo $mode === 'prep' ? 'Practice' : 'Full Simulation'; ?></h1>
            <p class="lead text-muted">Review the exam structure and rules before starting your session.</p>
        </div>

        <section class="study-card p-4 p-lg-5 mb-5 text-white" style="background: linear-gradient(135deg, var(--academy-blue), var(--focus-blue)); border:none;">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <span class="study-kicker" style="color:var(--sunbeam-yellow) !important;">Exam Format</span>
                    <h2 class="display-4 text-white mb-3"><?php echo htmlspecialchars($format_title); ?></h2>
                    <p class="text-white-50 mb-4" style="font-size: 1.1rem;">
                        <?php echo $test_format === 'toeic_sw'
                            ? 'This session starts with Speaking and continues to Writing, following the TOEIC Speaking & Writing task order.'
                            : 'This session consists of two main sections: Listening Comprehension and Reading. The total duration is approximately 2 hours.'; ?>
                    </p>
                    <div class="row g-3">
                        <?php foreach ($full_test_parts as $item): ?>
                            <div class="col-sm-6">
                                <div class="p-3 rounded-4" style="background: rgba(255,255,255,0.1);">
                                    <div class="fw-bold uppercase small text-warning mb-1"><?php echo htmlspecialchars($item['label']); ?></div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($item['detail']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="study-card text-center bg-white border-0 shadow-lg p-4">
                        <div class="study-kicker">Account Status</div>
                        <div class="h4 fw-bold mb-2" style="color:var(--focus-blue);">
                            <?php echo $has_full_credit ? 'Credit Available' : 'Credit Required'; ?>
                        </div>
                        <p class="small text-muted mb-4">You have <strong><?php echo $full_credit_count; ?></strong> active package(s).</p>

                        <form method="post">
                            <input type="hidden" name="mode" value="<?php echo htmlspecialchars($mode); ?>">
                            <input type="hidden" name="test_format" value="<?php echo htmlspecialchars($test_format); ?>">
                            <button type="submit" name="confirm_instructions" class="study-button w-100" <?php echo !$has_full_credit ? 'disabled' : ''; ?>>
                                Launch Session
                            </button>
                        </form>

                        <?php if (!$has_full_credit): ?>
                            <a href="buy_exam.php" class="study-button study-button-secondary w-100 mt-3">Buy Package</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-8">
                <section class="study-card mb-4">
                    <span class="study-kicker">Important</span>
                    <h2 class="h4 mb-4">Rules & Guidelines</h2>

                    <div class="list-group list-group-flush">
                        <div class="list-group-item bg-transparent border-0 px-0 pb-3 d-flex gap-3">
                            <div class="avatar-circle flex-shrink-0" style="width:36px; height:36px; background:rgba(72,127,181,0.1) !important; border:none;">
                                <i class="fas <?php echo $test_format === 'toeic_sw' ? 'fa-microphone' : 'fa-headphones'; ?> text-primary"></i>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo $test_format === 'toeic_sw' ? 'Microphone Required' : 'Headphones Recommended'; ?></div>
                                <div class="small text-muted"><?php echo $test_format === 'toeic_sw' ? 'Speaking responses are recorded and uploaded before submission.' : 'Listening audio plays once. Ensure your volume is correctly adjusted.'; ?></div>
                            </div>
                        </div>
                        <div class="list-group-item bg-transparent border-0 px-0 py-3 d-flex gap-3">
                            <div class="avatar-circle flex-shrink-0" style="width:36px; height:36px; background:rgba(72,127,181,0.1) !important; border:none;">
                                <i class="fas fa-clock text-primary"></i>
                            </div>
                            <div>
                                <div class="fw-bold">Non-Stop Timer</div>
                                <div class="small text-muted"><?php echo $test_format === 'toeic_sw' ? 'Speaking and Writing timers cannot be paused once the section begins.' : 'The timer cannot be paused. Make sure you have 2 hours of uninterrupted time.'; ?></div>
                            </div>
                        </div>
                        <div class="list-group-item bg-transparent border-0 px-0 py-3 d-flex gap-3">
                            <div class="avatar-circle flex-shrink-0" style="width:36px; height:36px; background:rgba(72,127,181,0.1) !important; border:none;">
                                <i class="fas fa-shield-alt text-primary"></i>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo $test_format === 'toeic_sw' ? 'Submission Rules' : 'Proctoring Rules'; ?></div>
                                <div class="small text-muted"><?php echo $test_format === 'toeic_sw' ? 'Speaking submit is blocked until recordings finish uploading. Writing responses are autosaved.' : 'Do not switch tabs or exit full-screen. Suspicious activity may disqualify your result.'; ?></div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <div class="study-card h-100" style="background: rgba(72, 127, 181, 0.05);">
                    <span class="study-kicker">Environment</span>
                    <h2 class="h4 mb-4">Checklist</h2>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3 d-flex gap-2"><i class="fas fa-check text-success mt-1"></i> <span>Quiet room</span></li>
                        <li class="mb-3 d-flex gap-2"><i class="fas fa-check text-success mt-1"></i> <span>Stable internet</span></li>
                        <li class="mb-3 d-flex gap-2"><i class="fas fa-check text-success mt-1"></i> <span>Charged laptop/PC</span></li>
                        <li class="d-flex gap-2"><i class="fas fa-check text-success mt-1"></i> <span><?php echo $test_format === 'toeic_sw' ? 'Microphone ready' : 'Webcam ready'; ?></span></li>
                    </ul>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
