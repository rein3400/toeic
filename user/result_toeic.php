<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/toeic_quality_helpers.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();
$user_id = (int)$_SESSION['user_id'];
$test_session = $_GET['session'] ?? $_SESSION['toeic_test_session'] ?? $_SESSION['test_session'] ?? null;
if (!$test_session || strpos($test_session, 'toeic_') !== 0) {
    toeicRedirectWithFlash('index.php', 'info', 'Hasil TOEIC akan tersedia setelah sesi selesai.');
}

$session_info = getTOEICSessionInfo($user_id, $test_session);
if (!$session_info) {
    toeicRedirectWithFlash('index.php', 'error', 'Hasil TOEIC tidak ditemukan untuk akun ini.');
}

$is_practice = !empty($session_info['practice_mode']);
$practice_part = preg_replace('/[^1-7]/', '', (string)($session_info['target_part'] ?? ''));
$practice_summary = ($is_practice && $practice_part !== '') ? getTOEICPracticeSummary($user_id, $test_session) : null;
$part_stats = getTOEICPartStatistics($user_id, $test_session);
$question_review_rows = [];
$stmt = $conn->prepare("
    SELECT
        tq.question_order,
        tq.section,
        tq.part,
        tq.question_id,
        tq.user_answer,
        tq.is_correct,
        COALESCE(sl.jawaban_benar, sr.jawaban_benar) AS correct_answer
    FROM toeic_test_questions tq
    LEFT JOIN toeic_soal_listening sl
      ON tq.section = 'listening'
     AND tq.question_id = sl.id_soal
    LEFT JOIN toeic_soal_reading sr
      ON tq.section = 'reading'
     AND tq.question_id = sr.id_soal
    WHERE tq.test_session = ?
      AND tq.user_id = ?
    ORDER BY CASE tq.section WHEN 'listening' THEN 1 WHEN 'reading' THEN 2 ELSE 3 END,
             tq.question_order ASC
");
$stmt->bind_param("si", $test_session, $user_id);
$stmt->execute();
$question_review_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function toeicResultAnswerStatus(array $row): array {
    $stored = $row['is_correct'];
    if ($stored !== null) {
        return ((float)$stored > 0)
            ? ['label' => 'Correct', 'class' => 'is-correct']
            : ['label' => 'Wrong', 'class' => 'is-wrong'];
    }

    $answer = strtoupper(trim((string)($row['user_answer'] ?? '')));
    $correct = strtoupper(trim((string)($row['correct_answer'] ?? '')));
    if ($answer !== '' && $correct !== '') {
        return $answer === $correct
            ? ['label' => 'Correct', 'class' => 'is-correct']
            : ['label' => 'Wrong', 'class' => 'is-wrong'];
    }

    return ['label' => 'Pending', 'class' => 'is-pending'];
}

if ($is_practice && $practice_summary) {
    $hero_title = $practice_summary['part_info']['name'];
    $hero_label = 'Practice Summary';
    $hero_copy = 'Focused practice session completed.';
    $hero_value = (int)round($practice_summary['accuracy']) . '%';
    $hero_subvalue = 'Accuracy Rate';
} else {
    if (!$is_practice) {
        $results = getTOEICTestResults($user_id, $test_session);
        if (!$results) {
            $results = calculateTOEICResults($user_id, $test_session);
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
    $hero_title = $is_practice ? 'Practice Simulation' : 'Full Test Results';
    $hero_label = 'Score Report';
    $hero_copy = $is_practice ? 'Practice simulation session completed.' : 'TOEIC Listening and Reading results.';
    $hero_value = (int)$results['total_score'];
    $hero_subvalue = 'CEFR ' . htmlspecialchars($level[1] ?? 'A1');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-user-page tc-result-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <a href="index.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">
                Dashboard
            </a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <section class="tc-report-hero mb-5">
            <article class="tc-report-card p-4 p-lg-5">
                <span class="tc-tag"><?php echo htmlspecialchars($hero_label); ?></span>
                <h1 class="display-5 mt-3 mb-2"><?php echo htmlspecialchars($hero_title); ?></h1>
                <p class="lead text-muted mb-0"><?php echo htmlspecialchars($hero_copy); ?></p>
                <div class="tc-big-score">
                    <strong><?php echo htmlspecialchars((string)$hero_value); ?></strong>
                    <span><?php echo $is_practice ? $hero_subvalue : '/ 990'; ?></span>
                </div>

                <?php if (!$is_practice): ?>
                    <div class="tc-score-pair">
                        <div class="tc-score-box">
                            <span>Listening</span>
                            <strong><?php echo (int)$results['listening_scaled']; ?></strong>
                        </div>
                        <div class="tc-score-box">
                            <span>Reading</span>
                            <strong><?php echo (int)$results['reading_scaled']; ?></strong>
                        </div>
                    </div>
                <?php elseif ($practice_summary): ?>
                    <div class="tc-score-pair">
                        <div class="tc-score-box">
                            <span>Correct</span>
                            <strong><?php echo (int)$practice_summary['correct']; ?></strong>
                        </div>
                        <div class="tc-score-box">
                            <span>Total</span>
                            <strong><?php echo (int)$practice_summary['total']; ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
            </article>

            <article class="tc-report-card p-4 p-lg-5">
                <span class="tc-tag amber">Session report</span>
                <h2 class="h3 mt-3 mb-3">Part accuracy</h2>
                <p class="text-muted mb-4">Bagian dengan persentase rendah bisa langsung diteruskan ke focused practice.</p>
                <?php foreach ($part_stats as $key => $stat): ?>
                    <?php if ($is_practice && $practice_summary && $practice_summary['part'] !== str_replace('part_', '', $key)) continue; ?>
                    <div class="tc-part-row">
                        <span><?php echo htmlspecialchars($stat['name']); ?></span>
                        <div class="tc-bar <?php echo (int)$stat['percentage'] < 70 ? 'amber' : ''; ?>">
                            <span style="width: <?php echo (int)$stat['percentage']; ?>%;"></span>
                        </div>
                        <span><?php echo (int)$stat['percentage']; ?>%</span>
                    </div>
                <?php endforeach; ?>
            </article>
        </section>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="study-card h-100">
                    <span class="study-kicker">Detailed Analysis</span>
                    <h2 class="h4 mb-4">Part Breakdown</h2>

                    <div class="table-responsive">
                        <table class="table table-borderless align-middle">
                            <tbody>
                                <?php foreach ($part_stats as $key => $stat): ?>
                                    <?php if ($is_practice && $practice_summary && $practice_summary['part'] !== str_replace('part_', '', $key)) continue; ?>
                                    <tr class="border-bottom-faint">
                                        <td class="py-3">
                                            <div class="fw-bold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                            <div class="small text-muted"><?php echo (int)$stat['correct']; ?> of <?php echo (int)$stat['total']; ?> correct</div>
                                        </td>
                                        <td class="py-3" style="width: 200px;">
                                            <div class="progress" style="height: 10px; background: rgba(0,0,0,0.05);">
                                                <div class="progress-bar rounded-pill" style="width: <?php echo (int)$stat['percentage']; ?>%; background: var(--academy-blue);"></div>
                                            </div>
                                        </td>
                                        <td class="py-3 text-end">
                                            <span class="h5 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo (int)$stat['percentage']; ?>%</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="study-card h-100">
                    <span class="study-kicker">Summary</span>
                    <h2 class="h4 mb-4">Section Scores</h2>

                    <?php if ($is_practice && $practice_summary): ?>
                        <div class="p-4 rounded-4 mb-4" style="background: rgba(72, 127, 181, 0.05);">
                            <div class="d-flex justify-content-between mb-3">
                                <span class="fw-bold">Correct Answers</span>
                                <span class="badge bg-success rounded-pill px-3"><?php echo (int)$practice_summary['correct']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span class="fw-bold">Incorrect Answers</span>
                                <span class="badge bg-danger rounded-pill px-3"><?php echo (int)$practice_summary['incorrect']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">Total Questions</span>
                                <span class="badge bg-dark rounded-pill px-3"><?php echo (int)$practice_summary['total']; ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-4 rounded-4 mb-4" style="background: rgba(72, 127, 181, 0.05);">
                            <div class="d-flex justify-content-between mb-3">
                                <span class="fw-bold">Listening Section</span>
                                <span class="h5 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo (int)$results['listening_scaled']; ?> / 495</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">Reading Section</span>
                                <span class="h5 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo (int)$results['reading_scaled']; ?> / 495</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-auto">
                        <span class="study-kicker d-block mb-3">Next Step</span>
                        <div class="d-grid gap-3">
                            <?php if ($is_practice): ?>
                                <a href="test_instructions.php?test_format=toeic&mode=full" class="study-button w-100">Try Full Simulation</a>
                                <a href="test_instructions.php?test_format=toeic&mode=prep" class="study-button study-button-secondary w-100">Practice Another Part</a>
                            <?php else: ?>
                                <a href="test_instructions.php?test_format=toeic&mode=prep" class="study-button w-100">Focused Practice</a>
                                <a href="analytics.php" class="study-button study-button-secondary w-100">Explore Analytics</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <section class="study-card mt-4">
            <span class="study-kicker">Question Review</span>
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                <div>
                    <h2 class="h4 mb-2">Listening & Reading Answers</h2>
                    <p class="text-muted mb-0">Lihat jawaban sendiri, kunci jawaban, dan status benar/salah untuk setiap nomor.</p>
                </div>
                <a href="analytics.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">All Reports</a>
            </div>

            <?php if (empty($question_review_rows)): ?>
                <div class="text-center py-5 opacity-50">
                    <i class="fas fa-list-check fa-3x mb-3"></i>
                    <p class="mb-0">Question review belum tersedia untuk sesi ini.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle tc-question-review-table">
                        <thead>
                            <tr class="small text-muted uppercase fw-bold">
                                <th>Question</th>
                                <th>Section</th>
                                <th>Part</th>
                                <th>Your Answer</th>
                                <th>Correct Answer</th>
                                <th class="text-end">Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($question_review_rows as $row): ?>
                                <?php $status = toeicResultAnswerStatus($row); ?>
                                <tr class="border-bottom-faint">
                                    <td class="py-3 fw-bold">#<?php echo (int)$row['question_order']; ?></td>
                                    <td class="py-3"><?php echo htmlspecialchars(ucfirst((string)$row['section'])); ?></td>
                                    <td class="py-3">Part <?php echo htmlspecialchars((string)$row['part']); ?></td>
                                    <td class="py-3">
                                        <span class="tc-answer-pill"><?php echo htmlspecialchars((string)($row['user_answer'] ?: '-')); ?></span>
                                    </td>
                                    <td class="py-3">
                                        <span class="tc-answer-pill is-key"><?php echo htmlspecialchars((string)($row['correct_answer'] ?: '-')); ?></span>
                                    </td>
                                    <td class="py-3 text-end">
                                        <span class="tc-result-status <?php echo htmlspecialchars($status['class']); ?>"><?php echo htmlspecialchars($status['label']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <style>
        .border-bottom-faint {
            border-bottom: 1px solid rgba(0,0,0,0.03) !important;
        }
        .tc-question-review-table {
            min-width: 760px;
        }
        .tc-answer-pill,
        .tc-result-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.25rem;
            min-height: 2rem;
            border-radius: 999px;
            padding: 0.25rem 0.75rem;
            font-weight: 800;
            background: #eef2f7;
            color: #162033;
        }
        .tc-answer-pill.is-key {
            background: #ecfdf5;
            color: #047857;
        }
        .tc-result-status.is-correct {
            background: #10b981;
            color: #fff;
        }
        .tc-result-status.is-wrong {
            background: #ef4444;
            color: #fff;
        }
        .tc-result-status.is-pending {
            background: #64748b;
            color: #fff;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
