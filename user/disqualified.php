<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_quality_helpers.php';

$test_session = $_GET['session'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $test_session)) $test_session = '';

$website_title = getWebsiteTitle();

if ($test_session !== '' && isset($_SESSION['user_id'])) {
    $session_summary = toeicGetSessionSummary($conn, (int)$_SESSION['user_id'], $test_session);
    if ($session_summary && !empty($session_summary['practice_mode'])) {
        $_SESSION['toeic_test_session'] = $test_session;
        $_SESSION['test_session'] = $test_session;
        $_SESSION['test_format'] = 'toeic';

        $part = preg_replace('/[^1-7]/', '', (string)($session_summary['target_part'] ?? ''));
        if (($session_summary['status'] ?? '') === 'completed') {
            $target = 'result_toeic.php?session=' . urlencode($test_session);
        } else {
            $section = $session_summary['current_section'] ?: 'listening';
            $target = 'test_toeic.php?resume=1&test_session=' . urlencode($test_session) . '&section=' . urlencode($section) . '&setup_complete=1&mode=prep';
            if ($part !== '') {
                $target .= '&part=' . urlencode($part);
            }
        }

        toeicRedirectWithFlash($target, 'info', 'Mode practice tidak memakai proctoring. Sesi latihan Anda bisa dilanjutkan.');
    }
}

unset($_SESSION['test_session'], $_SESSION['test_session_2026'], $_SESSION['toeic_test_session']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Terminated - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .disqualified-box { max-width: 540px; width: 100%; }
        .danger-icon {
            width: 80px; height: 80px; background: #fff1f2; border: 4px solid #fecdd3;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem; color: #be123c; margin: 0 auto 1.5rem;
        }
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center tc-disqualified-page" style="min-height: 100dvh;">
    <main class="toeic-page-shell d-flex justify-content-center">
        <div class="study-card disqualified-box text-center p-5">
            <div class="danger-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <span class="study-kicker" style="color:#be123c !important;">Session Terminated</span>
            <h1 class="h2 fw-bold mb-3">Integrity Violation</h1>
            <p class="text-muted mb-4">
                Your exam session was stopped by the proctoring system due to detected integrity issues. This incident has been logged for review.
            </p>

            <?php if ($test_session): ?>
                <div id="statusBox" class="p-4 rounded-4 mb-4" style="background: rgba(0,0,0,0.03);">
                    <div id="statusText" class="fw-bold small pulse">
                        <i class="fas fa-search me-2"></i> Waiting for administrative review...
                    </div>
                </div>

                <div id="resumeSection" style="display:none;" class="mb-4">
                    <div class="alert alert-success border-0 rounded-4 mb-4">
                        <i class="fas fa-check-circle me-2"></i> Access restored by administrator.
                    </div>
                    <a href="test_toeic.php?test_session=<?php echo htmlspecialchars($test_session); ?>&resume=1" class="study-button w-100">
                        Resume Exam Now
                    </a>
                </div>
            <?php endif; ?>

            <div class="d-grid gap-2">
                <a href="index.php" class="study-button study-button-secondary w-100">Back to Dashboard</a>
                <p class="small text-muted mt-3">If you believe this was an error, please contact support with your Session ID: <code><?php echo htmlspecialchars($test_session); ?></code></p>
            </div>
        </div>
    </main>

    <?php if ($test_session): ?>
    <script>
    const TEST_SESSION = <?php echo json_encode($test_session); ?>;
    function checkClearance() {
        fetch('ajax_check_proctor_status.php?test_session=' + encodeURIComponent(TEST_SESSION))
            .then(r => r.json())
            .then(data => {
                if (data.cleared) {
                    const statusBox = document.getElementById('statusBox');
                    const statusText = document.getElementById('statusText');
                    statusBox.style.background = '#dcfce7';
                    statusBox.style.border = '2px solid #bcf0da';
                    statusText.classList.remove('pulse');
                    statusText.style.color = '#15803d';
                    statusText.innerHTML = '<i class="fas fa-check-circle me-2"></i> Status: Cleared for Resume';
                    document.getElementById('resumeSection').style.display = 'block';
                    clearInterval(pollInterval);
                }
            }).catch(() => {});
    }
    const pollInterval = setInterval(checkClearance, 10000);
    checkClearance();
    </script>
    <?php endif; ?>
</body>
</html>
