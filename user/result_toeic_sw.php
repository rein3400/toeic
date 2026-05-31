<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_quality_helpers.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/csrf_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();
$user_id = (int)$_SESSION['user_id'];
$test_session = trim((string)($_GET['session'] ?? $_SESSION['toeic_sw_test_session'] ?? ''));
if ($test_session === '' || strpos($test_session, 'toeic_sw_') !== 0) {
    toeicRedirectWithFlash('index.php', 'info', 'Hasil TOEIC Speaking & Writing akan tersedia setelah sesi selesai.');
}

$result = getToeicSwTestResults($conn, $user_id, $test_session);
if (!$result) {
    toeicRedirectWithFlash('index.php', 'error', 'Hasil TOEIC Speaking & Writing tidak ditemukan untuk akun ini.');
}

$level = $result['level'] ?? getToeicSwLevel((int)$result['total_score']);
$is_practice = !empty($result['practice_mode']);
$mode_label = $is_practice ? 'Practice' : 'Full Simulation';
$mode_param = $is_practice ? 'prep' : 'full';

$stmt = $conn->prepare("
    SELECT q.section, q.question_order, q.question_type, q.source_path,
           s.transcript_text, s.raw_score, s.normalized_score, s.feedback_json,
           s.ai_provider, s.ai_model, s.status
    FROM toeic_sw_test_questions q
    LEFT JOIN toeic_sw_subjective_scores s
      ON s.test_session = q.test_session
     AND s.question_row_id = q.id
    WHERE q.test_session = ? AND q.user_id = ?
    ORDER BY FIELD(q.section, 'speaking', 'writing'), q.question_order ASC
");
$stmt->bind_param("si", $test_session, $user_id);
$stmt->execute();
$feedback_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$needs_rescore_count = count(array_filter(
    $feedback_rows,
    static fn($row) => ($row['status'] ?? '') === 'needs_rescore'
));

function toeicSwResultH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function toeicSwFeedbackSummary(?string $json): string {
    if (!$json) {
        return 'Feedback belum tersedia.';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return 'Feedback belum tersedia.';
    }
    return (string)($data['feedback_summary'] ?? $data['fallback_reason'] ?? 'Feedback belum tersedia.');
}

function toeicSwFormatScore($value): string {
    return $value !== null ? number_format((float)$value, 2) : '-';
}

function toeicSwUserRecordingUrl(array $question): string {
    $source = trim((string)($question['source_path'] ?? ''));
    if ($source === '' || ($question['section'] ?? '') !== 'speaking') {
        return '';
    }
    return 'stream_toeic_sw_recording.php?session=' . rawurlencode((string)$question['test_session'])
        . '&question_id=' . (int)$question['id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC SW Result - <?php echo toeicSwResultH($website_title); ?></title>
    <?php echo csrfMeta(); ?>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo toeicSwResultH(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .sw-score-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }
        .sw-score {
            border: 1px solid var(--cloud-line);
            border-radius: 12px;
            background: white;
            padding: 1.25rem;
        }
        .sw-score-value {
            font-size: 2.4rem;
            font-weight: 900;
            color: var(--focus-blue);
            line-height: 1;
        }
        .sw-feedback {
            border: 1px solid var(--cloud-line);
            border-radius: 10px;
            padding: 1rem;
            background: white;
            margin-bottom: 0.75rem;
        }
        .sw-status-badge {
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            font-size: 0.75rem;
            font-weight: 800;
            background: rgba(72,127,181,0.1);
            color: var(--focus-blue);
        }
        .sw-status-badge.needs_rescore, .sw-status-badge.fallback {
            background: #fff9e6;
            color: #b45309;
        }
        @media (max-width: 768px) {
            .sw-score-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="tc-user-page tc-result-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo toeicSwResultH($website_title); ?>
            </a>
            <a href="index.php" class="study-button study-button-secondary py-2 px-3" style="min-height:40px;font-size:13px;">Dashboard</a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <section class="mb-4">
            <span class="study-kicker">TOEIC SW <?php echo toeicSwResultH($mode_label); ?> Result</span>
            <h1 class="display-5 mb-2"><?php echo toeicSwResultH($mode_label); ?> Score Report</h1>
            <p class="lead text-muted mb-0">Speaking and Writing are reported separately on a 0-200 scale, with a combined summary out of 400.</p>
        </section>

        <section class="sw-score-grid mb-4">
            <div class="sw-score">
                <div class="study-kicker">Speaking</div>
                <div class="sw-score-value"><?php echo (int)$result['speaking_scaled']; ?></div>
                <div class="text-muted">/200</div>
            </div>
            <div class="sw-score">
                <div class="study-kicker">Writing</div>
                <div class="sw-score-value"><?php echo (int)$result['writing_scaled']; ?></div>
                <div class="text-muted">/200</div>
            </div>
            <div class="sw-score">
                <div class="study-kicker">Total</div>
                <div class="sw-score-value"><?php echo (int)$result['total_score']; ?></div>
                <div class="text-muted">/400 - <?php echo toeicSwResultH($level[0] ?? ''); ?> (<?php echo toeicSwResultH($level[1] ?? ''); ?>)</div>
            </div>
        </section>

        <section class="study-card mb-4">
            <span class="study-kicker">Package</span>
            <div class="d-flex flex-wrap justify-content-between gap-3">
                <div>
                    <h2 class="h4 mb-1">Package <?php echo (int)$result['package_number']; ?></h2>
                    <p class="text-muted mb-0"><?php echo toeicSwResultH($mode_label); ?> completed <?php echo toeicSwResultH(date('d M Y H:i', strtotime((string)$result['completed_at']))); ?></p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="learning_pathway.php?format=toeic_sw&session=<?php echo urlencode($test_session); ?>" class="study-button">Build SW Pathway</a>
                    <?php if ($needs_rescore_count > 0): ?>
                        <button type="button" class="study-button study-button-secondary" id="swRescoreBtn" data-session="<?php echo toeicSwResultH($test_session); ?>">
                            Retry AI Feedback (<?php echo (int)$needs_rescore_count; ?>)
                        </button>
                    <?php endif; ?>
                    <a href="test_instructions.php?test_format=toeic_sw&mode=<?php echo toeicSwResultH($mode_param); ?>" class="study-button study-button-secondary">Try Another SW <?php echo toeicSwResultH($mode_label); ?></a>
                </div>
            </div>
        </section>

        <section class="study-card">
            <span class="study-kicker">Task Feedback</span>
            <h2 class="h4 mb-4">Item-Level Notes</h2>

            <?php foreach ($feedback_rows as $row): ?>
                <?php
                $status = (string)($row['status'] ?? 'pending');
                $summary = toeicSwFeedbackSummary($row['feedback_json'] ?? null);
                ?>
                <div class="sw-feedback">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div class="fw-bold">
                            <?php echo toeicSwResultH(ucfirst((string)$row['section'])); ?>
                            Q<?php echo (int)$row['question_order']; ?>
                            <span class="text-muted fw-normal">(<?php echo toeicSwResultH(str_replace('_', ' ', (string)$row['question_type'])); ?>)</span>
                        </div>
                        <span class="sw-status-badge <?php echo toeicSwResultH($status); ?>"><?php echo toeicSwResultH($status); ?></span>
                    </div>
                    <p class="mb-2 text-muted"><?php echo toeicSwResultH($summary); ?></p>
                    <div class="small text-muted">
                        <strong>AI Score</strong>
                        - Raw: <?php echo toeicSwResultH(toeicSwFormatScore($row['raw_score'])); ?>
                        - Normalized: <?php echo toeicSwResultH(toeicSwFormatScore($row['normalized_score'])); ?>
                        <?php if (!empty($row['ai_model'])): ?>
                            · Model: <?php echo toeicSwResultH($row['ai_model']); ?>
                        <?php endif; ?>
                        <?php if (!empty($row['transcript_text'])): ?>
                            · Transcript available
                        <?php endif; ?>
                        <?php
                        $recordingUrl = toeicSwUserRecordingUrl($row);
                        if ($recordingUrl && ($row['section'] ?? '') === 'speaking'):
                            ?>
                            · <a href="<?php echo toeicSwResultH($recordingUrl); ?>" target="_blank" class="text-decoration-none"><i class="fas fa-headphones me-1"></i> Listen to Recording</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($recordingUrl && ($row['section'] ?? '') === 'speaking'): ?>
                    <div class="mt-2">
                        <audio controls preload="metadata" style="width:100%; max-width:400px;">
                            <source src="<?php echo toeicSwResultH($recordingUrl); ?>" type="audio/webm">
                            <source src="<?php echo toeicSwResultH($recordingUrl); ?>" type="audio/ogg">
                            Your browser does not support the audio element.
                        </audio>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    </main>
    <script>
        const swRescoreBtn = document.getElementById('swRescoreBtn');
        if (swRescoreBtn) {
            swRescoreBtn.addEventListener('click', async () => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                swRescoreBtn.disabled = true;
                swRescoreBtn.textContent = 'Retrying AI feedback...';
                try {
                    for (let i = 0; i < 20; i++) {
                        const response = await fetch('ajax_rescore_toeic_sw.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                            body: JSON.stringify({
                                csrf_token: csrfToken,
                                test_session: swRescoreBtn.dataset.session
                            })
                        });
                        const data = await response.json();
                        if (!data.success) {
                            throw new Error(data.error || 'AI feedback retry failed');
                        }
                        if (!data.remaining) {
                            window.location.reload();
                            return;
                        }
                        swRescoreBtn.textContent = `Retrying AI feedback... ${data.remaining} left`;
                    }
                    window.location.reload();
                } catch (error) {
                    swRescoreBtn.disabled = false;
                    swRescoreBtn.textContent = error.message;
                }
            });
        }
    </script>
</body>
</html>
