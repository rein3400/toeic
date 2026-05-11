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

    $builder = new ToeicSwTestBuilder($conn);
    try {
        $package_number = $builder->pickReadyPackage($user_id);
    } catch (RuntimeException $e) {
        toeicRedirectWithFlash('buy_exam.php', 'error', 'Belum ada paket TOEIC Speaking & Writing yang lengkap. Jalankan import paket SW terlebih dahulu.');
    }

    if (!consumeTestCredit($conn, $user_id, 'toeic_sw')) {
        toeicRedirectWithFlash('buy_exam.php', 'error', 'Kredit TOEIC Speaking & Writing gagal dipakai. Coba lagi atau hubungi admin.');
    }

    $test_session = generateToeicSwTestSession();
    $builder->createSession($test_session, $user_id, [
        'package_number' => $package_number,
        'practice_mode' => $practice_mode ? 1 : 0,
    ]);
    $builder->buildTest($test_session, $user_id, [
        'package_number' => $package_number,
    ]);

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
$answered_count = count(array_filter($progress));
$total_questions = $question_count;
$remaining_time = max(0, $section_deadline - time());
$page_title = $practice_mode ? 'TOEIC SW Practice' : 'TOEIC SW Full Simulation';
$context_title = $section === 'speaking' ? 'Speaking Context' : 'Writing Context';

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
<html class="light" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <?= csrfMeta() ?>
    <title><?php echo toeicSwH($page_title); ?> - <?php echo toeicSwH($section_label); ?> - <?php echo toeicSwH($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo toeicSwH(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#00A68C",
                        "primary-hover": "#008F78",
                        "sunbeam": "#F26722",
                        "background-light": "#F8FAFC",
                        "background-dark": "#101622",
                        "surface-light": "#ffffff",
                        "surface-dark": "#1e293b",
                    },
                    fontFamily: {
                        display: ["Instrument Sans", "sans-serif"],
                        serif: ["Instrument Sans", "sans-serif"],
                        mono: ["JetBrains Mono", "monospace"],
                    },
                }
            }
        };
    </script>
    <style>
        .toeic-test-statusbar {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 1rem;
            align-items: center;
            padding: 1rem 2rem;
            background: white;
            border-bottom: 2px solid var(--cloud-line);
        }
        .toeic-test-map {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: 42px;
            gap: 8px;
            overflow-x: auto;
            padding: 2px 2px 8px;
            scroll-snap-type: x proximity;
            scrollbar-gutter: stable;
        }
        .toeic-test-map button {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid var(--cloud-line);
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
            color: var(--muted-slate);
            background: white;
            scroll-snap-align: center;
            line-height: 1;
            transition: 0.15s ease;
        }
        .toeic-test-map button.done {
            background: var(--academy-blue);
            color: white;
            border-color: var(--academy-blue);
        }
        .toeic-test-map button.active {
            border: 2px solid var(--focus-blue);
            color: var(--focus-blue);
            background: var(--sunbeam-yellow);
            outline: 3px solid rgba(242, 103, 34, 0.18);
        }
        .toeic-test-map button:disabled {
            opacity: 0.48;
            cursor: not-allowed;
        }

        .tc-test-loading {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.18);
            backdrop-filter: blur(2px);
        }
        body.tc-saving .tc-test-loading { display: flex; }
        .tc-test-loading span {
            padding: 0.9rem 1.2rem;
            border-radius: 999px;
            background: #ffffff;
            color: var(--focus-blue);
            font-weight: 800;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.14);
        }

        .sw-question[hidden] { display: none !important; }
        .sw-question.active { display: block; }
        .sw-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 0.9rem 0 1.1rem;
        }
        .sw-pill {
            border: 1px solid rgba(72,127,181,0.2);
            background: rgba(72,127,181,0.08);
            color: var(--focus-blue);
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .sw-prompt-text,
        .sw-info-card,
        .sw-repeat-box {
            background: rgba(72,127,181,0.05);
            border: 1px solid rgba(72,127,181,0.14);
            border-radius: 12px;
            padding: 1rem;
            color: #1f2937;
            line-height: 1.7;
        }
        .sw-info-card {
            background: #fff9e6;
            border-color: rgba(245,158,11,0.32);
        }
        .sw-mini-label {
            color: var(--muted-slate);
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
            letter-spacing: 0.02em;
        }
        .sw-image-frame {
            width: 100%;
            max-width: 680px;
            background: #f8fafc;
            border: 1px solid var(--cloud-line);
            border-radius: 16px;
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
            min-height: 170px;
            width: 100%;
            resize: vertical;
            border: 2px solid var(--cloud-line);
            border-radius: 16px;
            padding: 1rem;
            font: inherit;
            color: #0f172a;
            background: white;
        }
        .sw-answer-box:focus {
            border-color: var(--focus-blue);
            outline: 3px solid rgba(72, 127, 181, 0.14);
        }
        .sw-status {
            min-height: 1.25rem;
            font-size: 0.82rem;
            color: var(--muted-slate);
            text-align: right;
        }
        .sw-status.saved { color: #059669; }
        .sw-status.pending { color: #f59e0b; }
        .sw-status.error { color: #dc2626; font-weight: 800; }
        .sw-record-panel {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            border-top: 2px solid var(--cloud-line);
            margin-top: 1.3rem;
            padding-top: 1.1rem;
        }
        .sw-record-panel audio {
            max-width: 360px;
            width: min(360px, 100%);
        }
        .sw-context-list {
            display: grid;
            gap: 0.65rem;
        }
        .sw-context-list li {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.24);
            padding-bottom: 0.65rem;
            font-size: 0.92rem;
        }
        .sw-context-list li:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .sw-context-list strong {
            color: #0f172a;
        }
        .sw-action-footer {
            display: grid;
            grid-template-columns: auto minmax(70px, 1fr) auto auto;
            grid-template-areas:
                "previous counter next submit"
                "message message message message";
            gap: 0.85rem;
            align-items: center;
        }
        #sw-prev-question { grid-area: previous; }
        .sw-current-counter { grid-area: counter; }
        #sw-next-question { grid-area: next; }
        #sw-submit-section { grid-area: submit; }
        .sw-current-counter {
            text-align: center;
            font-weight: 900;
            color: var(--focus-blue);
            white-space: nowrap;
        }
        #sw-submit-message {
            grid-area: message;
            min-height: 1.2rem;
            text-align: right;
            color: var(--muted-slate);
        }
        #sw-submit-section:disabled,
        #sw-prev-question:disabled,
        #sw-next-question:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .sw-scoring-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(15, 23, 42, 0.72);
            color: white;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 1.5rem;
        }
        .sw-scoring-overlay.active { display: flex; }
        .sw-scoring-box { max-width: 420px; width: 100%; }
        .sw-scoring-spinner {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            border: 5px solid rgba(255,255,255,0.3);
            border-top-color: #ffffff;
            animation: swSpin 0.9s linear infinite;
            margin: 0 auto 1rem;
        }
        @keyframes swSpin { to { transform: rotate(360deg); } }

        aside { background: white !important; }
        main { background: var(--study-cream) !important; }

        @media (max-width: 900px) {
            body.tc-test-page {
                min-height: 100vh;
                height: auto;
                overflow: auto;
                padding-bottom: 210px;
            }
            .sw-main-shell {
                display: block;
                overflow: visible;
            }
            .sw-context-pane {
                width: 100%;
                min-width: 0;
                border-right: 0;
                border-bottom: 4px solid #e2e8f0;
            }
            .sw-context-pane .sw-context-scroll {
                padding: 1rem;
            }
            .sw-question-pane {
                min-height: 72vh;
                overflow: visible;
            }
            .sw-question-scroll {
                padding: 1rem;
                overflow: visible;
            }
            .sw-action-footer {
                grid-template-columns: 1fr 1fr;
                grid-template-areas:
                    "counter counter"
                    "previous next"
                    "message message"
                    "submit submit";
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 20;
                padding: 0.85rem 1rem;
                background: #ffffff;
                border-top: 4px solid #e2e8f0;
                box-shadow: 0 -18px 42px rgba(15, 23, 42, 0.12);
            }
            .sw-current-counter,
            #sw-submit-message {
                grid-column: 1 / -1;
                text-align: center;
            }
            #sw-submit-section {
                grid-column: 1 / -1;
                width: 100%;
            }
            .toeic-test-statusbar {
                grid-template-columns: 1fr;
                padding: 0.85rem 1rem;
            }
        }
    </style>
</head>
<body class="font-display h-screen flex flex-col overflow-hidden tc-test-page">
    <header class="bg-white border-b-4 border-slate-200 z-10 shrink-0">
        <div class="px-6 py-3 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <span class="avatar-circle" style="width:40px; height:40px;">T</span>
                <div class="min-w-0">
                    <h1 class="text-base font-extrabold text-primary leading-tight truncate"><?php echo toeicSwH($page_title); ?></h1>
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">
                        <?php echo toeicSwH($section_label); ?> &middot; TOEIC SW
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-4 bg-slate-50 px-4 py-2 rounded-xl border-2 border-slate-100">
                <span class="material-symbols-outlined text-slate-400">timer</span>
                <div class="font-mono font-bold text-xl text-primary" id="sw-section-timer"><?php echo gmdate('i:s', $remaining_time); ?></div>
            </div>
            <a href="index.php" onclick="return confirm('Exit exam? Progress is saved.');" class="study-button study-button-secondary" style="min-height:40px; font-size:12px; padding: 8px 16px !important;">
                Quit
            </a>
        </div>
        <div class="toeic-test-statusbar">
            <div class="shrink-0">
                <div class="text-xs font-black text-slate-400 uppercase mb-1">Progress</div>
                <div class="text-sm font-bold text-primary"><?php echo (int)$answered_count; ?> / <?php echo (int)$total_questions; ?></div>
            </div>
            <div class="toeic-test-map sw-section-map">
                <?php foreach ($questions as $map_index => $map_question): ?>
                    <?php
                    $map_number = (int)$map_question['question_order'];
                    $map_done = !empty($progress[$map_number]);
                    $map_classes = [];
                    if ($map_done) $map_classes[] = 'done';
                    if ($map_index === 0) $map_classes[] = 'active';
                    ?>
                    <button type="button"
                            class="<?php echo toeicSwH(implode(' ', $map_classes)); ?>"
                            data-question-jump="<?php echo (int)$map_index; ?>"
                            aria-label="Go to question <?php echo $map_number; ?>"
                            onclick="showToeicSwQuestion(<?php echo (int)$map_index; ?>)">
                        <?php echo $map_number; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </header>

    <main class="flex-1 flex overflow-hidden sw-main-shell">
        <aside class="w-1/2 min-w-[500px] border-r-4 border-slate-200 flex flex-col sw-context-pane">
            <div class="flex items-center justify-between px-6 py-4 border-b-2 border-slate-100 bg-white shrink-0">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest"><?php echo toeicSwH($context_title); ?></h3>
                <span class="study-pill" style="font-size:10px; background:var(--sunbeam-yellow); border-radius:8px; padding: 4px 8px;"><?php echo toeicSwH($mode_label); ?></span>
            </div>

            <div class="flex-1 overflow-y-auto p-8 pb-20 sw-context-scroll">
                <div class="study-card mb-6">
                    <span class="study-kicker">Current Section</span>
                    <h2 class="text-2xl font-extrabold text-slate-950 mt-2 mb-2"><?php echo toeicSwH($section_label); ?> Section</h2>
                    <p class="text-slate-500 font-semibold mb-0"><?php echo toeicSwH($section_detail); ?></p>
                </div>

                <div class="study-card mb-6">
                    <span class="study-kicker">Section Setup</span>
                    <ul class="sw-context-list mt-4">
                        <li><span>Questions</span><strong><?php echo (int)$question_count; ?></strong></li>
                        <li><span>Mode</span><strong><?php echo toeicSwH($mode_label); ?></strong></li>
                        <li><span>Score scale</span><strong>0-200</strong></li>
                        <li><span>Order</span><strong><?php echo $section === 'speaking' ? 'Speaking first' : 'Writing second'; ?></strong></li>
                    </ul>
                </div>

                <div class="study-card" style="background:var(--academy-blue); color:white; border:none;">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-black uppercase opacity-75"><?php echo $section === 'speaking' ? 'Recording Flow' : 'Writing Flow'; ?></span>
                        <i class="fas <?php echo $section === 'speaking' ? 'fa-microphone' : 'fa-keyboard'; ?>"></i>
                    </div>
                    <p class="mb-0 text-sm leading-relaxed opacity-90">
                        <?php echo $section === 'speaking'
                            ? 'Prepare, record, and wait for the upload status before moving to the next response.'
                            : 'Type your response in the answer box. Responses autosave while you write.'; ?>
                    </p>
                </div>
            </div>
        </aside>

        <section class="flex-1 flex flex-col relative overflow-hidden sw-question-pane">
            <div class="flex-1 overflow-y-auto p-8 space-y-6 sw-question-scroll">
                <?php foreach ($questions as $question_index => $question): ?>
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
                    <section class="sw-question study-card <?php echo $question_index === 0 ? 'active' : ''; ?>"
                             id="question-<?php echo $row_id; ?>"
                             data-question="<?php echo (int)$question_index; ?>"
                             data-row-id="<?php echo $row_id; ?>"
                             data-section="<?php echo toeicSwH($section); ?>"
                             data-type="<?php echo toeicSwH($type); ?>"
                             data-question-number="<?php echo $number; ?>"
                             data-has-answer="<?php echo $has_answer ? '1' : '0'; ?>"
                             <?php echo $question_index === 0 ? '' : 'hidden'; ?>>
                        <div class="flex flex-wrap justify-between gap-3">
                            <div>
                                <span class="study-kicker">Question <?php echo $number; ?></span>
                                <h2 class="text-2xl font-extrabold text-slate-950 mt-2 mb-0"><?php echo toeicSwH($task_info['label'] ?? $type); ?></h2>
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
                            <div class="sw-record-panel">
                                <button type="button" id="record-btn-<?php echo $row_id; ?>" class="study-button py-2 px-3" onclick="startToeicSwPrepare(<?php echo $row_id; ?>, <?php echo (int)$question['prepare_seconds']; ?>, <?php echo (int)$question['response_seconds']; ?>)">
                                    <i class="fas fa-hourglass-start me-2"></i>Start Prepare
                                </button>
                                <span class="font-bold text-slate-500" id="sw-record-timer-<?php echo $row_id; ?>"></span>
                                <audio id="sw-playback-<?php echo $row_id; ?>" controls <?php echo $playback_src ? 'src="' . toeicSwH($playback_src) . '"' : 'hidden'; ?>></audio>
                            </div>
                        <?php else: ?>
                            <?php if ($required_words): ?>
                                <div class="mt-5">
                                    <div class="sw-mini-label">Required words or phrases</div>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($required_words as $word): ?>
                                            <span class="sw-pill"><?php echo toeicSwH($word); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="mt-5">
                                <textarea class="sw-answer-box" data-row-id="<?php echo $row_id; ?>" placeholder="Type your answer here..."><?php echo toeicSwH($question['user_answer'] ?? ''); ?></textarea>
                                <div class="flex flex-wrap justify-between gap-2 text-sm text-slate-500 mt-2">
                                    <span id="sw-word-count-<?php echo $row_id; ?>">0 words</span>
                                    <?php if ($type === 'write_opinion_essay'): ?>
                                        <span>300 words is a quality target, not a submit blocker.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="p-6 border-t-4 border-slate-200 bg-white shrink-0 sw-action-footer">
                <button type="button" id="sw-prev-question" class="study-button study-button-secondary" onclick="prevToeicSwQuestion()">
                    <i class="fas fa-arrow-left me-2"></i> Previous
                </button>
                <div class="sw-current-counter">
                    <span id="sw-current-question">1</span>/<span id="sw-total-questions"><?php echo (int)$question_count; ?></span>
                </div>
                <div id="sw-submit-message" class="text-sm font-semibold">
                    <?php echo $section === 'speaking' ? 'Complete all recordings before submit.' : 'Writing autosaves while you type.'; ?>
                </div>
                <button type="button" id="sw-next-question" class="study-button" onclick="nextToeicSwQuestion()">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
                <button type="button" id="sw-submit-section" class="study-button" onclick="submitToeicSwSection()">
                    <?php echo $section === 'speaking' ? 'Submit Speaking' : 'Submit Writing'; ?>
                </button>
            </div>
        </section>
    </main>

    <div class="tc-test-loading" aria-live="polite" aria-hidden="true"><span>Saving answer...</span></div>

    <div class="sw-scoring-overlay" id="sw-scoring-overlay" role="status" aria-live="polite">
        <div class="sw-scoring-box">
            <div class="sw-scoring-spinner"></div>
            <h2 class="text-xl font-extrabold mb-2">Submitting your <?php echo toeicSwH(strtolower($section_label)); ?> section</h2>
            <p class="mb-0">Please wait while uploads and saved answers are verified.</p>
        </div>
    </div>

    <script>
        window.TOEIC_SW_CONFIG = <?php echo toeicSwJson([
            'testSession' => $requested_session,
            'section' => $section,
            'mode' => $mode,
            'practiceMode' => $practice_mode,
            'csrfToken' => generateCsrfToken(),
            'sectionDeadline' => $section_deadline,
            'questionCount' => $question_count,
        ]); ?>;
    </script>
    <script src="js/test_toeic_sw.js"></script>
</body>
</html>
