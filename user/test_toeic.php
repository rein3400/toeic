<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ini_set('session.gc_maxlifetime', 14400);
ini_set('session.cookie_lifetime', 14400);
session_set_cookie_params(14400);

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/proctor_helper.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/toeic_asset_storage.php';

ensureTOEICSessionModeColumns($conn);

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?message=Session expired.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

if (!FEATURE_TOEIC) {
    $_SESSION['error'] = 'TOEIC sedang tidak tersedia.';
    header("Location: index.php");
    exit();
}

$proctoring_enabled = FEATURE_PROCTORING;
$dev_bypass_token = getenv('DEV_BYPASS_TOKEN') ?: '';
$requested_dev_token = $_GET['dev_bypass'] ?? '';
$is_dev_bypass = $dev_bypass_token && $requested_dev_token && hash_equals($dev_bypass_token, $requested_dev_token);
$website_title = getWebsiteTitle();
$session_key = 'toeic_test_session';

$requested_section = $_GET['section'] ?? 'listening';
$question_num = max(1, (int)($_GET['q'] ?? 1));
$start_new = isset($_GET['start_new']) ? (int)($_GET['start_new']) : 0;
$setup_complete = isset($_GET['setup_complete']) ? (int)($_GET['setup_complete']) : 0;
$resume = isset($_GET['resume']) ? (int)($_GET['resume']) : 0;
$requested_mode = (($_GET['mode'] ?? '') === 'prep') ? 'prep' : 'full';
$requested_part = preg_replace('/[^1-7]/', '', (string)($_GET['part'] ?? ''));
$requested_test_session = trim((string)($_GET['test_session'] ?? ''));
if ($requested_test_session !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/', $requested_test_session)) {
    $requested_test_session = '';
}
$valid_sections = ['listening', 'reading'];
$section = in_array($requested_section, $valid_sections, true) ? $requested_section : 'listening';

if ($start_new) {
    $practice_mode = $requested_mode === 'prep' || !empty($_SESSION['practice_mode_toeic']);
    $practice_part = $practice_mode
        ? ($requested_part ?: preg_replace('/[^1-7]/', '', (string)($_SESSION['practice_part_toeic'] ?? '')))
        : '';
    $practice_config = $practice_part !== '' ? getTOEICPracticeConfig($practice_part) : null;
    $requires_proctoring = $proctoring_enabled && FEATURE_ANTI_CHEAT && !$practice_mode;

    if ($practice_part !== '' && !$practice_config) {
        $_SESSION['error'] = 'Part latihan TOEIC tidak valid.';
        header("Location: index.php");
        exit();
    }

    if (!$practice_mode && !hasStrictTestCredit($conn, $_SESSION['user_id'], 'toeic')) {
        header("Location: buy_exam.php");
        exit();
    }

    if (empty($_SESSION['instructions_confirmed_toeic'])) {
        $instruction_query = 'test_format=toeic&mode=' . ($practice_mode ? 'prep' : 'full');
        if ($practice_part !== '') {
            $instruction_query .= '&part=' . urlencode($practice_part);
        }
        header("Location: test_instructions.php?$instruction_query");
        exit();
    }

    unset($_SESSION['instructions_confirmed_toeic'], $_SESSION['practice_mode_toeic'], $_SESSION['practice_part_toeic']);

    $toeic_readiness = getTOEICContentReadiness($conn);
    if (empty($toeic_readiness['ready'])) {
        $_SESSION['error'] = 'Bank soal TOEIC belum lengkap. Silakan hubungi administrator.';
        header("Location: index.php");
        exit();
    }

    if (!$practice_mode) {
        if (!consumeTestCredit($conn, $_SESSION['user_id'], 'toeic')) {
            $_SESSION['error'] = 'Paket TOEIC aktif tidak dapat dipakai. Silakan cek kembali paket Anda.';
            header("Location: buy_exam.php");
            exit();
        }
    }

    $test_session = 'toeic_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
    $start_section = ($practice_mode && $practice_config) ? $practice_config['section'] : 'listening';
    $builder_options = [
        'current_section' => $start_section,
        'practice_mode' => $practice_mode ? 1 : 0,
        'target_part' => $practice_part !== '' ? $practice_part : null,
        'target_section' => $practice_config['section'] ?? null,
    ];

    require_once '../includes/toeic_test_builder.php';
    $builder = new ToeicTestBuilder($conn);
    $builder->createSession($test_session, $_SESSION['user_id'], $builder_options);
    $builder->buildTest($test_session, $_SESSION['user_id'], $builder_options);

    $_SESSION[$session_key] = $test_session;
    $_SESSION['test_session'] = $test_session;
    $_SESSION['test_format'] = 'toeic';
    $_SESSION['current_section'] = $start_section;
    $_SESSION['section_start_time'] = time();

    $redirect = "test_toeic.php?section=" . urlencode($start_section) . "&test_session=" . urlencode($test_session) . "&setup_complete=1&mode=" . ($practice_mode ? 'prep' : 'full');
    if ($practice_part !== '') {
        $redirect .= '&part=' . urlencode($practice_part);
    }

    if ($requires_proctoring) {
        $camera = "camera_setup.php?test_session=" . urlencode($test_session) . "&section=" . urlencode($start_section) . "&test_type=toeic&mode=" . ($practice_mode ? 'prep' : 'full');
        if ($practice_part !== '') {
            $camera .= '&part=' . urlencode($practice_part);
        }
        header("Location: $camera");
    } else {
        header("Location: $redirect");
    }
    exit();
}

$candidate_session = $requested_test_session ?: ($_SESSION[$session_key] ?? $_SESSION['test_session'] ?? '');

if ($resume && $candidate_session !== '') {
    $stmt = $conn->prepare("SELECT status, current_section, practice_mode, target_part FROM toeic_test_sessions WHERE test_session = ? AND user_id = ?");
    $stmt->bind_param("si", $candidate_session, $_SESSION['user_id']);
    $stmt->execute();
    $session_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($session_row && $session_row['status'] === 'active') {
        $_SESSION[$session_key] = $candidate_session;
        $_SESSION['test_session'] = $candidate_session;
        $_SESSION['test_format'] = 'toeic';
        $_SESSION['current_section'] = $session_row['current_section'] ?: 'listening';
        $resume_section = $session_row['current_section'] ?: 'listening';
        $resume_mode = !empty($session_row['practice_mode']) ? 'prep' : 'full';
        $resume_part = preg_replace('/[^1-7]/', '', (string)($session_row['target_part'] ?? ''));
        $url = "test_toeic.php?section=" . urlencode($resume_section) . "&test_session=" . urlencode($candidate_session) . "&setup_complete=1&mode=$resume_mode";
        if ($resume_mode === 'prep' && $resume_part !== '') {
            $url .= '&part=' . urlencode($resume_part);
        }
        header("Location: $url");
    } else {
        header("Location: index.php");
    }
    exit();
}

if (!isset($_SESSION[$session_key]) && !isset($_SESSION['test_session']) && $requested_test_session === '') {
    header("Location: index.php");
    exit();
}

$test_session = $requested_test_session ?: ($_SESSION[$session_key] ?? $_SESSION['test_session']);
$_SESSION[$session_key] = $test_session;
$_SESSION['test_session'] = $test_session;
$_SESSION['test_format'] = 'toeic';

$stmt = $conn->prepare("SELECT * FROM toeic_test_sessions WHERE test_session = ? AND user_id = ?");
$stmt->bind_param("si", $test_session, $_SESSION['user_id']);
$stmt->execute();
$session_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session_info) {
    header("Location: index.php");
    exit();
}

if (($session_info['status'] ?? '') === 'completed') {
    header("Location: result_toeic.php?session=" . urlencode($test_session));
    exit();
}

$practice_mode = !empty($session_info['practice_mode']);
$practice_part = preg_replace('/[^1-7]/', '', (string)($session_info['target_part'] ?? ''));
$practice_config = $practice_part !== '' ? getTOEICPracticeConfig($practice_part) : null;
if ($practice_part !== '' && !$practice_config) {
    $_SESSION['error'] = 'Mode latihan TOEIC tidak valid.';
    header("Location: index.php");
    exit();
}
$requires_proctoring = $proctoring_enabled && FEATURE_ANTI_CHEAT && !$practice_mode;

$section = ($practice_mode && $practice_config) ? $practice_config['section'] : ($session_info['current_section'] ?: $section);
$_SESSION['current_section'] = $section;

if (!isset($_GET['q']) || $question_num === 1) {
    $_SESSION['section_start_time'] = time();
}

if ($requires_proctoring) {
    $stmt = $conn->prepare("
        SELECT status, review_status, camera_granted, microphone_granted
        FROM proctoring_sessions
        WHERE test_session = ? AND user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("si", $test_session, $_SESSION['user_id']);
    $stmt->execute();
    $proctor_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($proctor_row && $proctor_row['status'] === 'terminated' && $proctor_row['review_status'] !== 'cleared') {
        header("Location: disqualified.php?session=" . urlencode($test_session));
        exit();
    }

    if (
        !$proctor_row ||
        $proctor_row['status'] !== 'active' ||
        empty($proctor_row['camera_granted']) ||
        empty($proctor_row['microphone_granted'])
    ) {
        $camera = "camera_setup.php?test_session=" . urlencode($test_session) . "&section=" . urlencode($section) . "&test_type=toeic&mode=" . ($practice_mode ? 'prep' : 'full');
        if ($practice_part !== '') {
            $camera .= '&part=' . urlencode($practice_part);
        }
        header("Location: $camera");
        exit();
    }
}

if (!$setup_complete) {
    if ($requires_proctoring) {
        $stmt = $conn->prepare("SELECT status FROM proctoring_sessions WHERE test_session = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("si", $test_session, $_SESSION['user_id']);
        $stmt->execute();
        $proctor_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$proctor_row || $proctor_row['status'] !== 'active') {
            $camera = "camera_setup.php?test_session=" . urlencode($test_session) . "&section=" . urlencode($section) . "&test_type=toeic&mode=" . ($practice_mode ? 'prep' : 'full');
            if ($practice_part !== '') {
                $camera .= '&part=' . urlencode($practice_part);
            }
            header("Location: $camera");
            exit();
        }
    }

    $reload = "test_toeic.php?section=" . urlencode($section) . "&test_session=" . urlencode($test_session) . "&setup_complete=1&mode=" . ($practice_mode ? 'prep' : 'full');
    if ($practice_part !== '') {
        $reload .= '&part=' . urlencode($practice_part);
    }
    header("Location: $reload");
    exit();
}

function getToeicQuestionRow($conn, $session, $section, $order) {
    $table = $section === 'listening' ? 'toeic_soal_listening' : 'toeic_soal_reading';
    $stmt = $conn->prepare("
        SELECT src.*, tq.question_order, tq.question_id, tq.part, tq.stimulus_group_id, tq.group_order
        FROM toeic_test_questions tq
        JOIN {$table} src ON tq.question_id = src.id_soal
        WHERE tq.test_session = ? AND tq.section = ? AND tq.question_order = ?
    ");
    $stmt->bind_param("ssi", $session, $section, $order);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getToeicTotalQuestionsForSection($conn, $session, $section) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM toeic_test_questions WHERE test_session = ? AND section = ?");
    $stmt->bind_param("ss", $session, $section);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

function getToeicProgressMap($conn, $session, $section) {
    $stmt = $conn->prepare("SELECT question_order, user_answer FROM toeic_test_questions WHERE test_session = ? AND section = ? ORDER BY question_order ASC");
    $stmt->bind_param("ss", $session, $section);
    $stmt->execute();
    $result = $stmt->get_result();
    $progress = [];
    while ($row = $result->fetch_assoc()) {
        $progress[(int)$row['question_order']] = !empty($row['user_answer']);
    }
    $stmt->close();
    return $progress;
}

function getToeicAudioContextQuestions($conn, $session, $order) {
    $current = getToeicQuestionRow($conn, $session, 'listening', $order);
    if (!$current) {
        return [];
    }

    if (!empty($current['stimulus_group_id'])) {
        $stmt = $conn->prepare("
            SELECT src.*, tq.question_order, tq.question_id, tq.group_order
            FROM toeic_test_questions tq
            JOIN toeic_soal_listening src ON tq.question_id = src.id_soal
            WHERE tq.test_session = ? AND tq.stimulus_group_id = ?
            ORDER BY tq.group_order ASC, tq.question_order ASC
        ");
        $stmt->bind_param("ss", $session, $current['stimulus_group_id']);
    } elseif (!empty($current['id_audio'])) {
        $stmt = $conn->prepare("
            SELECT src.*, tq.question_order, tq.question_id, tq.group_order
            FROM toeic_test_questions tq
            JOIN toeic_soal_listening src ON tq.question_id = src.id_soal
            WHERE tq.test_session = ? AND src.id_audio = ?
            ORDER BY tq.group_order ASC, tq.question_order ASC
        ");
        $stmt->bind_param("si", $session, $current['id_audio']);
    } else {
        return [];
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getToeicTextContextQuestions($conn, $session, $order) {
    $current = getToeicQuestionRow($conn, $session, 'reading', $order);
    if (!$current) {
        return [];
    }

    if (!empty($current['stimulus_group_id'])) {
        $stmt = $conn->prepare("
            SELECT src.*, tq.question_order, tq.question_id, tq.group_order
            FROM toeic_test_questions tq
            JOIN toeic_soal_reading src ON tq.question_id = src.id_soal
            WHERE tq.test_session = ? AND tq.stimulus_group_id = ?
            ORDER BY tq.group_order ASC, tq.question_order ASC
        ");
        $stmt->bind_param("ss", $session, $current['stimulus_group_id']);
    } elseif (!empty($current['id_teks'])) {
        $stmt = $conn->prepare("
            SELECT src.*, tq.question_order, tq.question_id, tq.group_order
            FROM toeic_test_questions tq
            JOIN toeic_soal_reading src ON tq.question_id = src.id_soal
            WHERE tq.test_session = ? AND src.id_teks = ?
            ORDER BY tq.group_order ASC, tq.question_order ASC
        ");
        $stmt->bind_param("si", $session, $current['id_teks']);
    } else {
        return [];
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getToeicTimerSeconds($section, $practicePart) {
    if (!empty($practicePart)) {
        $config = getTOEICPracticeConfig($practicePart);
        if ($config) {
            return ((int)$config['minutes']) * 60;
        }
    }
    return $section === 'listening' ? 45 * 60 : 75 * 60;
}

$question = getToeicQuestionRow($conn, $test_session, $section, $question_num);
if (!$question) {
    $_SESSION['error'] = 'Sesi TOEIC tidak memiliki soal yang valid.';
    header("Location: index.php");
    exit();
}

$part = (string)$question['part'];
$part_info = getTOEICPartInfo($part);
$is_batch = in_array($part, ['3', '4', '6', '7'], true);
$audio = null;
$photo = null;
$photo_urls = [];
$text = null;
$batch_questions = [];
$batch_answers = [];
$user_answer = '';

if ($section === 'listening') {
    if (!empty($question['id_audio'])) {
        $audio = getToeicAudio((int)$question['id_audio']);
    }
    if ($part === '1' && $audio && !empty($audio['id_photo'])) {
        $photo = getToeicPhoto((int)$audio['id_photo']);
        if ($photo) {
            $photo_urls = toeicPhotoUrlCandidates($photo['file_path'] ?? '');
        }
    }
    if ($is_batch) {
        $batch_questions = getToeicAudioContextQuestions($conn, $test_session, $question_num);
    }
} else {
    if (!empty($question['id_teks'])) {
        $text = getToeicText((int)$question['id_teks']);
    }
    if ($is_batch) {
        $batch_questions = getToeicTextContextQuestions($conn, $test_session, $question_num);
    }
}

$primary_photo_url = $photo_urls[0] ?? '';
$fallback_photo_urls = array_values(array_slice($photo_urls, 1));
$photo_fallback_json = htmlspecialchars(
    json_encode($fallback_photo_urls, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
    ENT_QUOTES
);

if ($is_batch) {
    foreach ($batch_questions as $row) {
        $stmt = $conn->prepare("SELECT user_answer FROM toeic_test_questions WHERE test_session = ? AND section = ? AND question_id = ?");
        $stmt->bind_param("ssi", $test_session, $section, $row['question_id']);
        $stmt->execute();
        $answer_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $batch_answers[(int)$row['question_id']] = $answer_row['user_answer'] ?? '';
    }
} else {
    $stmt = $conn->prepare("SELECT user_answer FROM toeic_test_questions WHERE test_session = ? AND section = ? AND question_id = ?");
    $stmt->bind_param("ssi", $test_session, $section, $question['question_id']);
    $stmt->execute();
    $answer_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $user_answer = $answer_row['user_answer'] ?? '';
}

$progress_map = getToeicProgressMap($conn, $test_session, $section);
$total_questions = getToeicTotalQuestionsForSection($conn, $test_session, $section);
$answered_count = count(array_filter($progress_map));
$progress_percent = $total_questions > 0 ? ceil(($answered_count / $total_questions) * 100) : 0;
$timer_seconds = getToeicTimerSeconds($section, $practice_mode ? $practice_part : null);
$section_start_time = $_SESSION['section_start_time'] ?? time();
$remaining_time = max(0, $timer_seconds - (time() - $section_start_time));
$csrf_token = generateCsrfToken();
$page_title = $practice_mode ? 'TOEIC Practice Simulation' : 'TOEIC Full Simulation';
$context_title = $section === 'listening' ? 'Audio Context' : 'Reading Passage';
$mode_query = $practice_mode
    ? ('&mode=prep' . ($practice_part !== '' ? '&part=' . urlencode($practice_part) : ''))
    : '&mode=full';
$prev_q = $is_batch ? (($batch_questions[0]['question_order'] ?? $question_num) - 1) : ($question_num - 1);
$prev_link = $prev_q > 0 ? "test_toeic.php?section=" . urlencode($section) . "&test_session=" . urlencode($test_session) . "&q=$prev_q&setup_complete=1$mode_query" : '#';
$is_last_question = $is_batch
    ? (($batch_questions ? end($batch_questions)['question_order'] : $question_num) >= $total_questions)
    : ($question_num >= $total_questions);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo ucfirst($section); ?> - <?php echo htmlspecialchars($website_title); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&family=Noto+Serif:wght@400;700&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="css/mobile-responsive.css" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#f59e0b",
                        "primary-hover": "#d97706",
                        "background-light": "#f5f7fb",
                        "background-dark": "#101622",
                        "surface-light": "#ffffff",
                        "surface-dark": "#1e293b",
                    },
                    fontFamily: {
                        display: ["Lexend", "sans-serif"],
                        serif: ["Noto Serif", "serif"],
                    },
                }
            }
        };
    </script>
    <style>
        .scroll-smooth::-webkit-scrollbar { width: 8px; }
        .scroll-smooth::-webkit-scrollbar-track { background: #eef2f7; }
        .scroll-smooth::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-slate-900 dark:text-slate-100 h-screen flex flex-col overflow-hidden">
    <header class="bg-surface-light dark:bg-surface-dark border-b border-slate-200 dark:border-slate-800 shadow-sm z-10 shrink-0">
        <div class="px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary">
                    <span class="material-symbols-outlined">assignment</span>
                </div>
                <div>
                    <h1 class="text-base font-bold leading-tight"><?php echo htmlspecialchars($page_title); ?></h1>
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        Section: <?php echo ucfirst($section); ?> | Part <?php echo htmlspecialchars($part); ?><?php echo $practice_mode ? ($practice_part !== '' ? ' | Legacy Practice' : ' | Practice Simulation') : ' | Official Order'; ?>
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-4 bg-slate-50 dark:bg-slate-900 px-4 py-2 rounded-lg border border-slate-100 dark:border-slate-700">
                <span class="material-symbols-outlined text-slate-400">timer</span>
                <div class="flex gap-1 items-center font-mono font-medium text-lg text-primary" id="timerDisplay"><?php echo gmdate('i:s', $remaining_time); ?></div>
            </div>
            <a href="index.php" onclick="return confirm('Keluar dari sesi TOEIC ini?');" class="flex items-center gap-2 px-4 h-10 border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-sm font-bold transition-colors">
                <span class="material-symbols-outlined text-[20px]">logout</span>
                <span>Quit Test</span>
            </a>
        </div>
        <div class="px-6 py-3 bg-white dark:bg-surface-dark border-t border-slate-100 dark:border-slate-800 flex items-center gap-6">
            <span class="text-sm font-semibold whitespace-nowrap">
                <?php echo $practice_mode ? 'Practice' : 'Question'; ?> <?php echo $question_num; ?> of <?php echo $total_questions; ?>
            </span>
            <div class="h-2 w-full max-w-md rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden">
                <div class="h-full bg-primary rounded-full" style="width: <?php echo $progress_percent; ?>%;"></div>
            </div>
            <div class="text-sm text-slate-500 dark:text-slate-400"><?php echo $answered_count; ?> answered</div>
        </div>
    </header>

    <main class="flex-1 flex overflow-hidden">
        <aside class="w-1/2 min-w-[500px] border-r border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex flex-col">
            <div class="flex items-center justify-between px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 shrink-0">
                <h3 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide"><?php echo $context_title; ?></h3>
                <?php if ($practice_mode): ?>
                    <span class="text-xs font-semibold rounded-full bg-primary/10 text-primary px-3 py-1">Practice</span>
                <?php endif; ?>
            </div>

            <div class="flex-1 overflow-y-auto scroll-smooth p-8 pb-20">
                <?php if ($part === '1'): ?>
                    <div class="rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 shadow-sm mb-6">
                        <?php if ($primary_photo_url !== ''): ?>
                            <img
                                src="<?php echo htmlspecialchars($primary_photo_url); ?>"
                                alt="TOEIC Photograph"
                                class="w-full h-auto object-contain bg-slate-100 dark:bg-slate-800"
                                style="max-height: 460px;"
                                data-toeic-photo="true"
                                data-fallbacks="<?php echo $photo_fallback_json; ?>"
                                onerror="handleToeicPhotoFallback(this);"
                            >
                            <div class="hidden px-6 py-10 text-center bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300" data-photo-placeholder>
                                <div class="font-semibold text-slate-700 dark:text-slate-100 mb-2">Photograph unavailable</div>
                                <p class="mb-0 text-sm">The Part 1 image could not be loaded. Continue with the audio prompt and answer choices.</p>
                            </div>
                        <?php else: ?>
                            <div class="px-6 py-10 text-center bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300" data-photo-placeholder>
                                <div class="font-semibold text-slate-700 dark:text-slate-100 mb-2">Photograph unavailable</div>
                                <p class="mb-0 text-sm">The Part 1 image source is missing. Continue with the audio prompt and answer choices.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($section === 'listening'): ?>
                    <div class="p-5 rounded-2xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-sm mb-6">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Audio Playback</span>
                            <span class="flex items-center gap-1 text-xs font-medium text-primary bg-primary/10 px-2 py-1 rounded">
                                <span class="material-symbols-outlined text-[14px]">graphic_eq</span> Ready
                            </span>
                        </div>
                        <?php if ($audio && !empty($audio['file_path']) && FEATURE_SECURE_AUDIO): ?>
                            <div id="secure-player-container-<?php echo (int)$audio['id_audio']; ?>" class="w-full"></div>
                            <script src="js/SecureAudioPlayer.js"></script>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    new SecureAudioPlayer('secure-player-container-<?php echo (int)$audio['id_audio']; ?>', '<?php echo (int)$audio['id_audio']; ?>', 'toeic');
                                });
                            </script>
                        <?php elseif ($audio && !empty($audio['file_path'])): ?>
                            <audio id="mainAudio" controls class="w-full">
                                <source src="<?php echo htmlspecialchars(toeicAudioUrl($audio['file_path'])); ?>" type="audio/mpeg">
                            </audio>
                        <?php else: ?>
                            <p class="text-slate-500 text-sm mb-0">Audio TOEIC untuk stimulus ini belum tersedia.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($section === 'reading' && $text): ?>
                    <div class="font-serif text-lg leading-relaxed text-slate-800 dark:text-slate-200 space-y-6">
                        <?php
                        $content = $text['isi_teks'];
                        if ($part === '6') {
                            $content = preg_replace('/___(\d+)___/', '<span class="border-b-2 border-primary font-bold text-primary mx-1">[$1]</span>', $content);
                        }
                        echo nl2br((string)$content);
                        ?>
                    </div>
                <?php endif; ?>

                <div class="mt-6 p-4 bg-amber-50 text-amber-800 rounded-lg flex gap-3 text-sm border border-amber-100">
                    <span class="material-symbols-outlined shrink-0">info</span>
                    <div>
                        <div class="font-semibold mb-1"><?php echo htmlspecialchars($part_info['name'] ?? 'TOEIC'); ?></div>
                        <p class="mb-0"><?php echo htmlspecialchars($part_info['description'] ?? ''); ?></p>
                    </div>
                </div>
            </div>
        </aside>
        <section class="w-1/2 min-w-[400px] bg-slate-50 dark:bg-[#101622] flex flex-col relative">
            <div class="flex-1 flex flex-col h-full overflow-hidden">
                <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" id="testSession" value="<?php echo htmlspecialchars($test_session); ?>">
                <input type="hidden" id="currentSection" value="<?php echo htmlspecialchars($section); ?>">
                <input type="hidden" id="currentOrder" value="<?php echo (int)$question_num; ?>">
                <input type="hidden" id="totalQuestions" value="<?php echo (int)$total_questions; ?>">
                <input type="hidden" id="mode" value="<?php echo $practice_mode ? 'prep' : 'full'; ?>">
                <input type="hidden" id="targetPart" value="<?php echo htmlspecialchars($practice_part); ?>">

                <?php if ($is_batch): ?>
                    <?php $last_batch_order = $batch_questions ? end($batch_questions)['question_order'] : $question_num; ?>
                    <input type="hidden" id="isBatch" value="1">
                    <input type="hidden" id="lastOrder" value="<?php echo (int)$last_batch_order; ?>">
                    <div class="flex-1 overflow-y-auto p-8 space-y-8" id="batchQuestionsContainer">
                        <?php foreach ($batch_questions as $row): ?>
                            <?php $selected = $batch_answers[(int)$row['question_id']] ?? ''; ?>
                            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-100 dark:border-slate-700" data-question-id="<?php echo (int)$row['question_id']; ?>">
                                <span class="text-sm font-bold text-slate-400 uppercase tracking-wide mb-2 block">Question <?php echo (int)$row['question_order']; ?></span>
                                <h4 class="text-lg font-medium text-slate-900 dark:text-white leading-snug mb-4"><?php echo html_entity_decode((string)$row['pertanyaan']); ?></h4>
                                <div class="space-y-3 batch-answer-group" data-question-id="<?php echo (int)$row['question_id']; ?>">
                                    <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                        <?php $opt_value = $row['opsi_' . strtolower($opt)] ?? ''; ?>
                                        <?php if ($opt_value === '') continue; ?>
                                        <?php $is_selected = $selected === $opt; ?>
                                        <label class="group flex items-start gap-4 p-3 rounded-lg border <?php echo $is_selected ? 'border-primary bg-primary/5' : 'border-slate-200 dark:border-slate-700 hover:border-primary/50'; ?> cursor-pointer transition-all">
                                            <input type="radio" name="batch_<?php echo (int)$row['question_id']; ?>" value="<?php echo $opt; ?>" class="mt-1 size-4 text-primary focus:ring-primary bg-transparent batch-answer" data-question-id="<?php echo (int)$row['question_id']; ?>" <?php echo $is_selected ? 'checked' : ''; ?>>
                                            <span class="flex items-center justify-center font-bold text-xs size-6 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 group-hover:bg-primary group-hover:text-white transition-colors"><?php echo $opt; ?></span>
                                            <span class="text-sm text-slate-700 dark:text-slate-200 flex-1"><?php echo htmlspecialchars((string)$opt_value); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <input type="hidden" id="isBatch" value="0">
                    <input type="hidden" id="questionId" value="<?php echo (int)$question['question_id']; ?>">
                    <div class="flex-1 overflow-y-auto p-8 flex flex-col justify-start">
                        <div class="w-full max-w-2xl mx-auto space-y-8">
                            <div>
                                <span class="text-sm font-bold text-slate-400 uppercase tracking-wide">Question <?php echo (int)$question_num; ?></span>
                                <h3 class="text-xl md:text-2xl font-medium text-slate-900 dark:text-white leading-snug mt-2">
                                    <?php echo $part === '2' ? '<em>(Question is delivered in the audio)</em>' : html_entity_decode((string)$question['pertanyaan']); ?>
                                </h3>
                            </div>
                            <div class="space-y-4" id="singleAnswerContainer">
                                <?php $options = $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D']; ?>
                                <?php foreach ($options as $opt): ?>
                                    <?php $option_value = $question['opsi_' . strtolower($opt)] ?? ''; ?>
                                    <?php if ($option_value === '') continue; ?>
                                    <?php $display = htmlspecialchars((string)$option_value); ?>
                                    <?php $is_selected = $user_answer === $opt; ?>
                                    <label class="group relative flex items-start gap-4 p-5 rounded-xl border <?php echo $is_selected ? 'border-2 border-primary bg-primary/5 shadow-sm' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-surface-dark hover:border-primary/50 hover:shadow-md'; ?> cursor-pointer transition-all">
                                        <input class="mt-1 size-5 border-slate-300 text-primary focus:ring-primary focus:ring-offset-0 bg-transparent single-answer" name="answer" value="<?php echo $opt; ?>" type="radio" data-question-id="<?php echo (int)$question['question_id']; ?>" <?php echo $is_selected ? 'checked' : ''; ?>>
                                        <div class="flex-1">
                                            <span class="block text-base <?php echo $is_selected ? 'font-bold text-slate-900 dark:text-white' : 'font-medium text-slate-700 dark:text-slate-200 group-hover:text-primary'; ?> transition-colors"><?php echo $display; ?></span>
                                        </div>
                                        <span class="absolute right-4 top-4 text-xs font-bold <?php echo $is_selected ? 'text-primary' : 'text-slate-300 dark:text-slate-600'; ?>"><?php echo $opt; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="p-6 border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shrink-0 flex justify-between items-center">
                    <a href="<?php echo $prev_link; ?>" class="flex items-center gap-2 px-6 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors <?php echo $prev_q <= 0 ? 'opacity-50 pointer-events-none' : ''; ?>">
                        <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                        Previous
                    </a>

                    <?php if ($is_dev_bypass): ?>
                        <button type="button" id="skipSectionBtn" class="hidden sm:flex items-center gap-2 px-6 py-2.5 rounded-lg border border-amber-200 text-amber-700 font-bold hover:bg-amber-50 transition-colors" onclick="skipSection()">
                            Dev: Skip Section
                        </button>
                    <?php endif; ?>

                    <button type="button" id="nextBtn" class="flex items-center gap-2 px-8 py-2.5 rounded-lg bg-primary hover:bg-primary-hover text-white font-bold shadow-md transition-all transform active:scale-95" onclick="handleNext()">
                        <?php echo $is_last_question ? 'Finish Section' : 'Next'; ?>
                        <span class="material-symbols-outlined text-[20px]">arrow_forward</span>
                    </button>
                </div>
            </div>
        </section>
    </main>

    <script>
        const testSession = document.getElementById('testSession').value;
        const currentSection = document.getElementById('currentSection').value;
        const csrfToken = document.getElementById('csrfToken').value;
        const mode = document.getElementById('mode').value;
        const targetPart = document.getElementById('targetPart').value;

        function handleToeicPhotoFallback(image) {
            if (!image) {
                return;
            }

            let fallbackUrls = [];
            try {
                fallbackUrls = JSON.parse(image.dataset.fallbacks || '[]');
            } catch (error) {
                console.error('[TOEIC] Invalid photo fallback payload', error);
            }

            const nextUrl = fallbackUrls.shift();
            if (nextUrl) {
                image.dataset.fallbacks = JSON.stringify(fallbackUrls);
                image.src = nextUrl;
                return;
            }

            image.classList.add('hidden');
            const placeholder = image.parentElement ? image.parentElement.querySelector('[data-photo-placeholder]') : null;
            if (placeholder) {
                placeholder.classList.remove('hidden');
            }
        }

        function saveAnswer(questionId, answer) {
            fetch('ajax_save_toeic_answer.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify({
                    test_session: testSession,
                    section: currentSection,
                    question_id: parseInt(questionId, 10),
                    answer: answer
                })
            }).then((response) => response.json()).then((data) => {
                if (!data.success) {
                    console.error('Save failed:', data.error);
                }
            }).catch((error) => console.error('Save error:', error));
        }

        document.querySelectorAll('.single-answer').forEach((radio) => {
            radio.addEventListener('change', function () {
                saveAnswer(this.dataset.questionId, this.value);
            });
        });

        document.querySelectorAll('.batch-answer').forEach((radio) => {
            radio.addEventListener('change', function () {
                saveAnswer(this.dataset.questionId, this.value);
            });
        });

        function handleNext() {
            const isBatch = document.getElementById('isBatch').value === '1';
            const currentOrder = parseInt(document.getElementById('currentOrder').value, 10);
            const totalQuestions = parseInt(document.getElementById('totalQuestions').value, 10);
            const lastOrder = isBatch ? parseInt(document.getElementById('lastOrder').value, 10) : currentOrder;

            if (isBatch) {
                document.querySelectorAll('.batch-answer-group').forEach((group) => {
                    const checked = group.querySelector('input[type="radio"]:checked');
                    if (checked) {
                        saveAnswer(group.dataset.questionId, checked.value);
                    }
                });
            }

            const nextOrder = lastOrder + 1;
            if (nextOrder <= totalQuestions) {
                window.location.href = `test_toeic.php?section=${encodeURIComponent(currentSection)}&test_session=${encodeURIComponent(testSession)}&q=${nextOrder}&setup_complete=1&mode=${mode}${targetPart ? `&part=${encodeURIComponent(targetPart)}` : ''}`;
                return;
            }

            submitSection();
        }

        function submitSection() {
            if (window.proctorSDK) {
                window.proctorSDK.pause();
            }

            fetch('ajax_submit_section_toeic.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify({
                    test_session: testSession,
                    section: currentSection,
                    mode: mode,
                    target_part: targetPart
                })
            }).then((response) => response.json()).then((data) => {
                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }
                alert('Error: ' + (data.error || 'Unknown error'));
                if (window.proctorSDK) {
                    window.proctorSDK.resume();
                }
            }).catch((error) => {
                alert('Submit failed: ' + error.message);
                if (window.proctorSDK) {
                    window.proctorSDK.resume();
                }
            });
        }

        function skipSection() {
            if (confirm('Dev bypass only: skip this TOEIC section and continue?')) {
                submitSection();
            }
        }

        let remainingTime = <?php echo (int)$remaining_time; ?>;
        const timerDisplay = document.getElementById('timerDisplay');
        function updateTimer() {
            if (remainingTime <= 0) {
                clearInterval(timerInterval);
                timerDisplay.textContent = '00:00';
                if (!window.isTimeoutSubmission) {
                    window.isTimeoutSubmission = true;
                    alert('Time is up. The section will be submitted automatically.');
                    submitSection();
                }
                return;
            }
            const minutes = Math.floor(remainingTime / 60);
            const seconds = remainingTime % 60;
            timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            remainingTime--;
        }
        const timerInterval = setInterval(updateTimer, 1000);
        updateTimer();

        const mainAudio = document.getElementById('mainAudio');
        if (mainAudio) {
            mainAudio.controls = false;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'w-full py-3 bg-primary hover:bg-primary-hover text-white rounded-lg font-bold transition-colors shadow-sm flex items-center justify-center mb-2';
            button.innerHTML = '<span class="material-symbols-outlined align-middle mr-1">play_circle</span> Play Audio';
            mainAudio.parentNode.insertBefore(button, mainAudio);

            const progressContainer = document.createElement('div');
            progressContainer.className = 'w-full h-2 bg-slate-200 rounded-full overflow-hidden mt-2';
            const progressBar = document.createElement('div');
            progressBar.className = 'h-full bg-primary w-0 transition-all duration-200';
            progressContainer.appendChild(progressBar);
            mainAudio.parentNode.appendChild(progressContainer);

            button.onclick = function () {
                mainAudio.play().then(() => {
                    button.style.display = 'none';
                }).catch((error) => console.error('Audio play failed', error));
            };

            mainAudio.addEventListener('timeupdate', function () {
                if (mainAudio.duration) {
                    progressBar.style.width = `${(mainAudio.currentTime / mainAudio.duration) * 100}%`;
                }
            });

            mainAudio.addEventListener('pause', function () {
                if (!mainAudio.ended) {
                    mainAudio.play();
                }
            });
        }
    </script>

    <?php if ($requires_proctoring): ?>
        <script src="js/proctor.js"></script>
        <script>
            (function () {
                document.addEventListener('DOMContentLoaded', function () {
                    if (typeof ProctorSDK !== 'undefined') {
                        window.proctorSDK = new ProctorSDK({
                            testSession: testSession,
                            ajaxUrl: '../api/ajax_proctor.php',
                            strictness: '<?php echo PROCTORING_MODE ?? 'flexible'; ?>',
                            microphoneGranted: true
                        });
                        window.proctorSDK.start().catch(function (error) {
                            console.error('[Proctor] Failed to start live monitoring', error);
                        });
                    }
                });
            })();
        </script>
    <?php endif; ?>
</body>
</html>
