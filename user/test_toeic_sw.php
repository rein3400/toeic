<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_quality_helpers.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/toeic_sw_test_builder.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: ../login.php");
    exit();
}

if (!defined('FEATURE_TOEIC') || !FEATURE_TOEIC) {
    toeicRedirectWithFlash('index.php', 'info', 'TOEIC sedang tidak tersedia.');
}

$website_title = getWebsiteTitle();
$user_id = (int)$_SESSION['user_id'];
$valid_sections = getToeicSwSectionOrder();
$requested_section = (string)($_GET['section'] ?? 'speaking');
$section = in_array($requested_section, $valid_sections, true) ? $requested_section : 'speaking';
$session_key = 'toeic_sw_test_session';
$requested_session = trim((string)($_GET['test_session'] ?? $_SESSION[$session_key] ?? ''));
$requested_mode = (($_GET['mode'] ?? 'full') === 'prep') ? 'prep' : 'full';
$mode = $requested_mode;

ensureToeicSwSchema($conn);

if (isset($_GET['start_new'])) {
    $practice_mode = $requested_mode === 'prep' || !empty($_SESSION['practice_mode_toeic_sw']);
    $mode = $practice_mode ? 'prep' : 'full';

    if (!hasStrictTestCredit($conn, $user_id, 'toeic_sw')) {
        toeicRedirectWithFlash('buy_exam.php', 'info', 'Aktifkan paket TOEIC Speaking & Writing dulu sebelum mulai simulasi.');
    }

    if (empty($_SESSION['instructions_confirmed_toeic_sw'])) {
        header("Location: test_instructions.php?test_format=toeic_sw&mode=" . urlencode($mode));
        exit();
    }

    $readiness = getToeicSwContentReadiness($conn);
    if (empty($readiness['ready'])) {
        toeicRedirectWithFlash('buy_exam.php', 'error', 'Konten TOEIC Speaking & Writing belum lengkap. Jalankan import paket SW terlebih dahulu.');
    }

    if (!consumeTestCredit($conn, $user_id, 'toeic_sw')) {
        toeicRedirectWithFlash('buy_exam.php', 'error', 'Kredit TOEIC Speaking & Writing gagal dipakai. Coba lagi atau hubungi admin.');
    }

    $test_session = generateToeicSwTestSession();
    $builder = new ToeicSwTestBuilder($conn);
    $builder->createSession($test_session, $user_id, [
        'practice_mode' => $practice_mode ? 1 : 0,
    ]);
    $builder->buildTest($test_session, $user_id);

    unset($_SESSION['instructions_confirmed_toeic_sw'], $_SESSION['practice_mode_toeic_sw']);
    $_SESSION[$session_key] = $test_session;
    $_SESSION['test_session'] = $test_session;
    $_SESSION['test_format'] = 'toeic_sw';
    $_SESSION['current_section'] = 'speaking';
    $_SESSION['practice_mode_toeic_sw'] = $practice_mode ? 1 : 0;
    $_SESSION['toeic_sw_section_start_times'] = [$test_session . ':speaking' => time()];

    header("Location: test_toeic_sw.php?section=speaking&test_session=" . urlencode($test_session) . "&setup_complete=1&mode=" . urlencode($mode));
    exit();
}

if (isset($_GET['resume'])) {
    if ($requested_session === '') {
        toeicRedirectWithFlash('index.php', 'info', 'Tidak ada sesi TOEIC Speaking & Writing aktif untuk dilanjutkan.');
    }
}

if ($requested_session === '') {
    toeicRedirectWithFlash('index.php', 'info', 'Buka simulasi TOEIC Speaking & Writing dari dashboard atau halaman instruksi.');
}

$session_info = getToeicSwSessionInfo($conn, $user_id, $requested_session);
if (!$session_info) {
    toeicRedirectWithFlash('index.php', 'error', 'Sesi TOEIC Speaking & Writing tidak ditemukan.');
}

if (($session_info['status'] ?? '') === 'completed') {
    header("Location: result_toeic_sw.php?session=" . urlencode($requested_session));
    exit();
}

$practice_mode = !empty($session_info['practice_mode']);
$mode = $practice_mode ? 'prep' : 'full';
if ($requested_mode === 'prep' && !$practice_mode) {
    unset($_SESSION[$session_key], $_SESSION['test_session']);
    toeicRedirectWithFlash('index.php', 'error', 'Link practice tidak cocok dengan data sesi SW. Silakan mulai practice baru dari dashboard.');
}

$current_section = in_array(($session_info['current_section'] ?? 'speaking'), $valid_sections, true)
    ? $session_info['current_section']
    : 'speaking';
if ($section !== $current_section) {
    $section = $current_section;
}

$_SESSION[$session_key] = $requested_session;
$_SESSION['test_session'] = $requested_session;
$_SESSION['test_format'] = 'toeic_sw';
$_SESSION['current_section'] = $section;
$_SESSION['practice_mode_toeic_sw'] = $practice_mode ? 1 : 0;

if (!isset($_SESSION['toeic_sw_section_start_times']) || !is_array($_SESSION['toeic_sw_section_start_times'])) {
    $_SESSION['toeic_sw_section_start_times'] = [];
}
$timer_key = $requested_session . ':' . $section;
if (empty($_SESSION['toeic_sw_section_start_times'][$timer_key])) {
    $_SESSION['toeic_sw_section_start_times'][$timer_key] = time();
}
$section_start_time = (int)$_SESSION['toeic_sw_section_start_times'][$timer_key];
$section_seconds = getToeicSwSectionSeconds($section);
$section_deadline = $section_start_time + $section_seconds;

$questions = getToeicSwQuestionsForSection($conn, $requested_session, $section);
$progress = getToeicSwProgressMap($conn, $requested_session, $section);
$question_count = count($questions);
$section_label = $section === 'speaking' ? 'Speaking' : 'Writing';
$section_detail = $section === 'speaking'
    ? '11 questions, ETS-style prepare and record timing'
    : '8 questions, autosave, word count, and writing timers';
$mode_label = $practice_mode ? 'Practice' : 'Full Simulation';

function toeicSwH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function toeicSwRenderPrompt(array $question): void {
    $content = $question['content'] ?? [];
    $type = (string)$question['question_type'];

    if (!empty($content['image_path'])) {
        echo '<div class="sw-image-frame mb-3"><img src="' . toeicSwH(toeicSwMediaUrl($content['image_path'])) . '" alt="TOEIC SW prompt image" loading="lazy"></div>';
    }

    if ($type === 'respond_using_information' && !empty($content['information_card'])) {
        echo '<div class="sw-info-card mb-3"><div class="sw-mini-label">Information Card</div><div>' . nl2br(toeicSwH($content['information_card'])) . '</div></div>';
    }

    if (!empty($content['audio_path'])) {
        $audioLabel = (!empty($question['repeat_question'])) ? 'Prompt audio includes repeated question' : 'Prompt audio';
        echo '<div class="sw-audio-prompt mb-3"><div class="sw-mini-label">' . toeicSwH($audioLabel) . '</div><audio controls preload="metadata" src="' . toeicSwH(toeicSwMediaUrl($content['audio_path'])) . '"></audio></div>';
    }

    if (!empty($content['prompt_text'])) {
        echo '<div class="sw-prompt-text">' . nl2br(toeicSwH($content['prompt_text'])) . '</div>';
    }

    if ($type === 'respond_using_information' && !empty($question['repeat_question'])) {
        echo '<div class="sw-repeat-box mt-3"><div class="sw-mini-label">Question repeats twice</div><div>' . nl2br(toeicSwH($content['prompt_text'] ?? '')) . '</div><div class="mt-2">' . nl2br(toeicSwH($content['prompt_text'] ?? '')) . '</div></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>TOEIC SW <?php echo toeicSwH($section_label); ?> - <?php echo toeicSwH($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo toeicSwH(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .sw-topbar {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: center;
            background: white;
            border: 1px solid var(--cloud-line);
            border-radius: 12px;
            padding: 1rem;
        }
        .sw-timer {
            min-width: 120px;
            text-align: center;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--focus-blue);
        }
        .sw-question {
            border: 1px solid var(--cloud-line);
            border-radius: 12px;
            background: white;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        .sw-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .sw-pill {
            border: 1px solid rgba(72,127,181,0.2);
            background: rgba(72,127,181,0.08);
            color: var(--focus-blue);
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .sw-prompt-text, .sw-info-card, .sw-repeat-box {
            background: rgba(72,127,181,0.05);
            border: 1px solid rgba(72,127,181,0.14);
            border-radius: 10px;
            padding: 1rem;
            color: #1f2937;
            line-height: 1.65;
        }
        .sw-info-card {
            background: #fff9e6;
            border-color: rgba(245,158,11,0.32);
        }
        .sw-mini-label {
            color: var(--muted-slate);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
        }
        .sw-image-frame {
            width: 100%;
            max-width: 620px;
            background: #f8fafc;
            border: 1px solid var(--cloud-line);
            border-radius: 10px;
            overflow: hidden;
        }
        .sw-image-frame img {
            width: 100%;
            display: block;
        }
        .sw-audio-prompt audio {
            width: 100%;
            max-width: 560px;
        }
        .sw-answer-box {
            min-height: 130px;
            resize: vertical;
        }
        .sw-status {
            min-height: 1.25rem;
            font-size: 0.82rem;
            color: var(--muted-slate);
        }
        .sw-status.saved { color: #059669; }
        .sw-status.pending { color: #f59e0b; }
        .sw-status.error { color: #dc2626; font-weight: 700; }
        .sw-section-map {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .sw-section-map span {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--cloud-line);
            font-weight: 800;
            font-size: 0.8rem;
            color: var(--muted-slate);
            background: white;
        }
        .sw-section-map span.done {
            background: var(--academy-blue);
            color: white;
            border-color: var(--academy-blue);
        }
        @media (max-width: 768px) {
            .sw-topbar { grid-template-columns: 1fr; }
            .sw-timer { text-align: left; }
        }
    </style>
</head>
<body class="tc-user-page tc-test-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo toeicSwH($website_title); ?>
            </a>
            <a href="index.php" class="study-button study-button-secondary py-2 px-3" style="min-height:40px;font-size:13px;">Dashboard</a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <section class="sw-topbar mb-4">
            <div>
                <span class="study-kicker">TOEIC SW <?php echo toeicSwH($mode_label); ?></span>
                <h1 class="h3 mb-1"><?php echo toeicSwH($section_label); ?> Section</h1>
                <p class="mb-0 text-muted"><?php echo toeicSwH($section_detail); ?></p>
            </div>
            <div class="sw-timer" id="sw-section-timer">--:--</div>
        </section>

        <section class="study-card mb-4">
            <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
                <div>
                    <span class="study-kicker">Progress</span>
                    <div class="fw-bold"><?php echo $question_count; ?> questions in this section</div>
                </div>
                <div class="sw-section-map">
                    <?php foreach ($progress as $number => $done): ?>
                        <span class="<?php echo $done ? 'done' : ''; ?>"><?php echo (int)$number; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php foreach ($questions as $question): ?>
            <?php
            $row_id = (int)$question['id'];
            $number = (int)$question['question_order'];
            $type = (string)$question['question_type'];
            $task_info = getToeicSwTaskInfo($section, $number) ?: [];
            $content = $question['content'] ?? [];
            $required_words = json_decode((string)($content['required_words_json'] ?? '[]'), true) ?: [];
            $has_answer = trim((string)($question['user_answer'] ?? $question['source_path'] ?? '')) !== '';
            $playback_src = $section === 'speaking' ? toeicSwMediaUrl($question['source_path'] ?? '') : '';
            ?>
            <section class="sw-question" id="question-<?php echo $row_id; ?>" data-row-id="<?php echo $row_id; ?>" data-section="<?php echo toeicSwH($section); ?>" data-has-answer="<?php echo $has_answer ? '1' : '0'; ?>">
                <div class="d-flex flex-wrap justify-content-between gap-3 mb-2">
                    <div>
                        <span class="study-kicker">Question <?php echo $number; ?></span>
                        <h2 class="h5 mb-0"><?php echo toeicSwH($task_info['label'] ?? $type); ?></h2>
                    </div>
                    <div class="sw-status" id="sw-status-<?php echo $row_id; ?>"></div>
                </div>

                <div class="sw-meta">
                    <span class="sw-pill"><?php echo toeicSwH($task_info['part'] ?? 'SW'); ?></span>
                    <?php if (!empty($question['prepare_seconds'])): ?>
                        <span class="sw-pill">Prepare <?php echo (int)$question['prepare_seconds']; ?>s</span>
                    <?php endif; ?>
                    <?php if (!empty($question['read_seconds'])): ?>
                        <span class="sw-pill">Read <?php echo (int)$question['read_seconds']; ?>s</span>
                    <?php endif; ?>
                    <?php if (!empty($question['response_seconds'])): ?>
                        <span class="sw-pill">Response <?php echo (int)$question['response_seconds']; ?>s</span>
                    <?php endif; ?>
                    <?php if (!empty($question['task_minutes'])): ?>
                        <span class="sw-pill">Task <?php echo (int)$question['task_minutes']; ?>m</span>
                    <?php endif; ?>
                    <?php if ($type === 'write_opinion_essay'): ?>
                        <span class="sw-pill">Target 300+ words</span>
                    <?php endif; ?>
                </div>

                <?php toeicSwRenderPrompt($question); ?>

                <?php if ($section === 'speaking'): ?>
                    <div class="mt-4 d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" id="record-btn-<?php echo $row_id; ?>" class="study-button py-2 px-3" onclick="startToeicSwPrepare(<?php echo $row_id; ?>, <?php echo (int)$question['prepare_seconds']; ?>, <?php echo (int)$question['response_seconds']; ?>)">
                            <i class="fas fa-hourglass-start me-2"></i>Start Prepare
                        </button>
                        <span class="fw-bold text-muted" id="sw-record-timer-<?php echo $row_id; ?>"></span>
                        <audio id="sw-playback-<?php echo $row_id; ?>" controls <?php echo $playback_src ? 'src="' . toeicSwH($playback_src) . '"' : 'hidden'; ?>></audio>
                    </div>
                <?php else: ?>
                    <?php if ($required_words): ?>
                        <div class="mt-3">
                            <div class="sw-mini-label">Required words or phrases</div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($required_words as $word): ?>
                                    <span class="sw-pill"><?php echo toeicSwH($word); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <textarea class="form-control sw-answer-box" data-row-id="<?php echo $row_id; ?>" placeholder="Type your answer here..."><?php echo toeicSwH($question['user_answer'] ?? ''); ?></textarea>
                        <div class="d-flex justify-content-between small text-muted mt-2">
                            <span id="sw-word-count-<?php echo $row_id; ?>">0 words</span>
                            <?php if ($type === 'write_opinion_essay'): ?>
                                <span>300 words is a quality target, not a submit blocker.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <section class="study-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <span class="study-kicker">Submit</span>
                    <div class="fw-bold"><?php echo $section === 'speaking' ? 'Make sure every recording is uploaded.' : 'Your writing responses autosave while you type.'; ?></div>
                    <div id="sw-submit-message" class="small text-muted"></div>
                </div>
                <button type="button" id="sw-submit-section" class="study-button" onclick="submitToeicSwSection()">
                    <?php echo $section === 'speaking' ? 'Submit Speaking' : 'Submit Writing'; ?>
                </button>
            </div>
        </section>
    </main>

    <script>
        window.TOEIC_SW_CONFIG = <?php echo toeicSwJson([
            'testSession' => $requested_session,
            'section' => $section,
            'mode' => $mode,
            'practiceMode' => $practice_mode,
            'csrfToken' => generateCsrfToken(),
            'sectionDeadline' => $section_deadline,
        ]); ?>;
    </script>
    <script src="js/test_toeic_sw.js"></script>
</body>
</html>
