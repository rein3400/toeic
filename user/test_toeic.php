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
require_once '../includes/toeic_quality_helpers.php';
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

    if (!hasStrictTestCredit($conn, $_SESSION['user_id'], 'toeic')) {
        toeicRedirectWithFlash('buy_exam.php', 'info', 'Aktifkan paket TOEIC dulu sebelum mulai simulasi.');
    }

    $credit_preview = peekNextTestCredit($conn, $_SESSION['user_id'], 'toeic');
    $checkout_source = toeicCreditCheckoutSource($credit_preview ?: null);
    $is_free_trial = toeicIsFreeTrialCredit($credit_preview ?: null);
    if ($is_free_trial) {
        $practice_mode = true;
        $practice_part = '';
        $practice_config = null;
        $requires_proctoring = false;
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

    if (!consumeTestCredit($conn, $_SESSION['user_id'], 'toeic')) {
        $_SESSION['error'] = 'Paket TOEIC aktif tidak dapat dipakai. Silakan cek kembali paket Anda.';
        header("Location: buy_exam.php");
        exit();
    }

    $test_session = 'toeic_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
    $start_section = ($practice_mode && $practice_config) ? $practice_config['section'] : 'listening';
    $builder_options = [
        'current_section' => $start_section,
        'practice_mode' => $practice_mode ? 1 : 0,
        'target_part' => $practice_part !== '' ? $practice_part : null,
        'target_section' => $practice_config['section'] ?? null,
        'free_trial' => $is_free_trial ? 1 : 0,
        'checkout_source' => $checkout_source['source'],
        'checkout_reference' => $checkout_source['reference'],
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
    $_SESSION['toeic_section_start_times'] = [
        $test_session . ':' . $start_section => $_SESSION['section_start_time'],
    ];

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
        toeicRedirectWithFlash('index.php', 'info', 'Tidak ada sesi TOEIC aktif untuk dilanjutkan.');
    }
    exit();
}

if (!isset($_SESSION[$session_key]) && !isset($_SESSION['test_session']) && $requested_test_session === '') {
    toeicRedirectWithFlash('index.php', 'info', 'Buka simulasi TOEIC dari dashboard atau halaman instruksi.');
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
    toeicRedirectWithFlash('index.php', 'error', 'Sesi TOEIC tidak ditemukan atau sudah tidak bisa diakses.');
}

if (($session_info['status'] ?? '') === 'completed') {
    header("Location: result_toeic.php?session=" . urlencode($test_session));
    exit();
}

$practice_mode = !empty($session_info['practice_mode']);
if ($requested_mode === 'prep' && !$practice_mode) {
    unset($_SESSION[$session_key], $_SESSION['test_session']);
    toeicRedirectWithFlash('index.php', 'error', 'Link practice tidak cocok dengan data sesi. Silakan mulai practice baru dari dashboard.');
}
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

$timer_key = $test_session . ':' . $section;
if (!isset($_SESSION['toeic_section_start_times']) || !is_array($_SESSION['toeic_section_start_times'])) {
    $_SESSION['toeic_section_start_times'] = [];
}
if (empty($_SESSION['toeic_section_start_times'][$timer_key])) {
    $_SESSION['toeic_section_start_times'][$timer_key] = time();
}
$_SESSION['section_start_time'] = (int)$_SESSION['toeic_section_start_times'][$timer_key];

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
    return filterToeicBatchContextRows($rows, (int)$current['question_order']);
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
    return filterToeicBatchContextRows($rows, (int)$current['question_order']);
}

function filterToeicBatchContextRows(array $rows, int $currentOrder): array {
    if (count($rows) <= 1) {
        return $rows;
    }

    usort($rows, static function (array $left, array $right): int {
        return ((int)$left['question_order']) <=> ((int)$right['question_order']);
    });

    $groups = [];
    $currentGroup = [];
    $previousOrder = null;

    foreach ($rows as $row) {
        $order = (int)($row['question_order'] ?? 0);
        if ($previousOrder !== null && $order !== ($previousOrder + 1)) {
            if (!empty($currentGroup)) {
                $groups[] = $currentGroup;
            }
            $currentGroup = [];
        }

        $currentGroup[] = $row;
        $previousOrder = $order;
    }

    if (!empty($currentGroup)) {
        $groups[] = $currentGroup;
    }

    foreach ($groups as $group) {
        foreach ($group as $row) {
            if ((int)($row['question_order'] ?? 0) === $currentOrder) {
                return $group;
            }
        }
    }

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
$active_question_orders = [$question_num => true];
if ($is_batch) {
    foreach ($batch_questions as $batch_row) {
        $active_question_orders[(int)($batch_row['question_order'] ?? $question_num)] = true;
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo ucfirst($section); ?> - <?php echo htmlspecialchars($website_title); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
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

        function handleToeicPhotoFailure(img) {
            if (!img) return;
            let fallbacks = [];
            try {
                fallbacks = JSON.parse(img.dataset.fallbacks || '[]');
            } catch (error) {
                fallbacks = [];
            }

            const next = fallbacks.shift();
            if (next) {
                img.dataset.fallbacks = JSON.stringify(fallbacks);
                img.src = next;
                return;
            }

            const frame = img.closest('.toeic-photo-frame') || img.parentElement;
            if (frame) {
                frame.innerHTML = '<div class="toeic-photo-placeholder"><span class="material-symbols-outlined">image_not_supported</span><span>Foto soal belum tersedia</span></div>';
            }
        }
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
        .toeic-test-map a {
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
            scroll-snap-align: center;
            line-height: 1;
        }
        .toeic-test-map a.answered { background: var(--academy-blue); color: white; border-color: var(--academy-blue); }
        .toeic-test-map a.active { border: 2px solid var(--focus-blue); color: var(--focus-blue); background: var(--sunbeam-yellow); outline: 3px solid rgba(242, 103, 34, 0.18); }

        .toeic-photo-frame {
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
        }
        .toeic-photo-image {
            width: 100%;
            max-height: 460px;
            object-fit: contain;
            display: block;
        }
        .toeic-photo-placeholder {
            width: 100%;
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            color: #64748b;
            font-weight: 700;
            background: #f8fafc;
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

        .answer-choice {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-radius: 12px;
            border: 2px solid var(--cloud-line);
            cursor: pointer;
            transition: all 0.2s;
        }
        .answer-choice:hover { border-color: var(--focus-blue); background: rgba(72, 127, 181, 0.05); }
        .answer-choice.selected { border-color: var(--focus-blue); background: rgba(72, 127, 181, 0.05); }
        .answer-choice input:checked + .choice-label { color: var(--focus-blue); font-weight: 800; }

        aside { background: white !important; }
        main { background: var(--study-cream) !important; }
    </style>
</head>
<body class="font-display h-screen flex flex-col overflow-hidden tc-test-page">
    <header class="bg-white border-b-4 border-slate-200 z-10 shrink-0">
        <div class="px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
            <span class="avatar-circle" style="width:40px; height:40px;">T</span>
                <div>
                    <h1 class="text-base font-extrabold text-primary leading-tight"><?php echo htmlspecialchars($page_title); ?></h1>
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">
                        Part <?php echo htmlspecialchars($part); ?> · <?php echo ucfirst($section); ?>
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-4 bg-slate-50 px-4 py-2 rounded-xl border-2 border-slate-100">
                <span class="material-symbols-outlined text-slate-400">timer</span>
                <div class="font-mono font-bold text-xl text-primary" id="timerDisplay"><?php echo gmdate('i:s', $remaining_time); ?></div>
            </div>
            <a href="index.php" onclick="return confirm('Exit exam? Progress is saved.');" class="study-button study-button-secondary" style="min-height:40px; font-size:12px; padding: 8px 16px !important;">
                Quit
            </a>
        </div>
        <div class="toeic-test-statusbar">
            <div class="shrink-0">
                <div class="text-xs font-black text-slate-400 uppercase mb-1">Progres</div>
                <div class="text-sm font-bold text-primary"><?php echo $answered_count; ?> / <?php echo $total_questions; ?></div>
            </div>
            <div class="toeic-test-map">
                <?php for ($map_i = 1; $map_i <= $total_questions; $map_i++): ?>
                    <?php
                        $map_classes = [];
                        if (!empty($progress_map[$map_i])) $map_classes[] = 'answered';
                        if (!empty($active_question_orders[$map_i])) $map_classes[] = 'active';
                        $map_href = "test_toeic.php?section=" . urlencode($section) . "&test_session=" . urlencode($test_session) . "&q={$map_i}&setup_complete=1{$mode_query}";
                    ?>
                    <a class="<?php echo htmlspecialchars(implode(' ', $map_classes)); ?>" data-question-map-link="true" href="<?php echo htmlspecialchars($map_href); ?>"><?php echo $map_i; ?></a>
                <?php endfor; ?>
            </div>
        </div>
    </header>

    <main class="flex-1 flex overflow-hidden">
        <aside class="w-1/2 min-w-[500px] border-r-4 border-slate-200 flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b-2 border-slate-100 bg-white shrink-0">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest"><?php echo $context_title; ?></h3>
                <span class="study-pill" style="font-size:10px; background:var(--sunbeam-yellow); border-radius:8px; padding: 4px 8px;"><?php echo htmlspecialchars($part_info['name'] ?? 'Part'); ?></span>
            </div>

            <div class="flex-1 overflow-y-auto p-8 pb-20">
                <?php if ($part === '1' && $primary_photo_url !== ''): ?>
                    <div class="toeic-photo-frame rounded-2xl overflow-hidden border-4 border-slate-100 shadow-sm mb-6 bg-white" id="photo-container-<?php echo (int)$question_num; ?>">
                        <img src="<?php echo htmlspecialchars($primary_photo_url); ?>" alt="TOEIC Part 1 photo" class="toeic-photo-image" data-fallbacks="<?php echo htmlspecialchars(json_encode($fallback_photo_urls), ENT_QUOTES); ?>" loading="eager" decoding="async" onload="this.dataset.loaded='1';" onerror="handleToeicPhotoFailure(this);">
                    </div>
                <?php elseif ($part === '1'): ?>
                    <div class="toeic-photo-frame rounded-2xl overflow-hidden border-4 border-slate-100 shadow-sm mb-6 bg-white">
                        <div class="toeic-photo-placeholder">
                            <span class="material-symbols-outlined">image_not_supported</span>
                            <span>Foto soal belum tersedia</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($section === 'listening' && $audio): ?>
                    <div class="study-card mb-6" style="background:var(--academy-blue); color:white; border:none;">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-black uppercase opacity-75">Audio Playback</span>
                            <i class="fas fa-volume-up"></i>
                        </div>
                        <?php if (FEATURE_SECURE_AUDIO): ?>
                            <div id="secure-player-container-<?php echo (int)$audio['id_audio']; ?>" class="w-full"></div>
                            <script src="<?php echo htmlspecialchars(getVersionedAssetUrl('user/js/SecureAudioPlayer.js', 'js/SecureAudioPlayer.js')); ?>"></script>
                            <script>document.addEventListener('DOMContentLoaded', () => { new SecureAudioPlayer('secure-player-container-<?php echo (int)$audio['id_audio']; ?>', '<?php echo (int)$audio['id_audio']; ?>', 'toeic'); });</script>
                        <?php else: ?>
                            <audio id="mainAudio" controls class="w-full"><source src="<?php echo htmlspecialchars(toeicAudioUrl($audio['file_path'])); ?>" type="audio/mpeg"></audio>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($section === 'reading' && $text): ?>
                    <div class="study-card font-serif text-lg leading-relaxed text-slate-800 space-y-6">
                        <?php echo nl2br(preg_replace('/___(\d+)___/', '<strong class="text-primary underline">[$1]</strong>', (string)$text['isi_teks'])); ?>
                    </div>
                <?php endif; ?>

                <div class="mt-6 p-4 bg-blue-50 text-blue-800 rounded-xl flex gap-3 text-sm border-2 border-blue-100">
                    <i class="fas fa-info-circle mt-1"></i>
                    <div>
                        <div class="fw-bold mb-1">Instructions</div>
                        <p class="mb-0 opacity-75"><?php echo htmlspecialchars($part_info['description'] ?? ''); ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <section class="flex-1 flex flex-col relative overflow-hidden">
            <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" id="testSession" value="<?php echo htmlspecialchars($test_session); ?>">
            <input type="hidden" id="currentSection" value="<?php echo htmlspecialchars($section); ?>">
            <input type="hidden" id="currentOrder" value="<?php echo (int)$question_num; ?>">
            <input type="hidden" id="totalQuestions" value="<?php echo (int)$total_questions; ?>">
            <input type="hidden" id="mode" value="<?php echo $practice_mode ? 'prep' : 'full'; ?>">
            <input type="hidden" id="targetPart" value="<?php echo htmlspecialchars($practice_part); ?>">

            <div class="flex-1 overflow-y-auto p-8 space-y-6">
                <?php if ($is_batch): ?>
                    <input type="hidden" id="isBatch" value="1">
                    <input type="hidden" id="lastOrder" value="<?php echo (int)(end($batch_questions)['question_order'] ?? $question_num); ?>">
                    <?php foreach ($batch_questions as $row): ?>
                        <?php $selected = $batch_answers[(int)$row['question_id']] ?? ''; ?>
                        <div class="study-card mb-4" data-question-id="<?php echo (int)$row['question_id']; ?>">
                            <span class="study-kicker">Question <?php echo (int)$row['question_order']; ?></span>
                            <h4 class="h5 fw-bold mb-4"><?php echo html_entity_decode((string)$row['pertanyaan']); ?></h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 batch-answer-group" data-question-id="<?php echo (int)$row['question_id']; ?>">
                                <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                    <?php $val = $row['opsi_' . strtolower($opt)] ?? ''; if ($val === '') continue; ?>
                                    <label class="answer-choice <?php echo $selected === $opt ? 'selected' : ''; ?>">
                                        <input type="radio" name="batch_<?php echo (int)$row['question_id']; ?>" value="<?php echo $opt; ?>" class="mt-1 batch-answer" data-question-id="<?php echo (int)$row['question_id']; ?>" <?php echo $selected === $opt ? 'checked' : ''; ?> hidden>
                                        <span class="flex-shrink-0 w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center font-black text-xs"><?php echo $opt; ?></span>
                                        <span class="choice-label text-sm"><?php echo htmlspecialchars((string)$val); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <input type="hidden" id="isBatch" value="0">
                    <input type="hidden" id="questionId" value="<?php echo (int)$question['question_id']; ?>">
                    <div class="max-w-2xl mx-auto pt-10">
                        <span class="study-kicker">Question <?php echo (int)$question_num; ?></span>
                        <h3 class="h3 fw-bold mb-8"><?php echo $part === '2' ? '<em>(Question in audio)</em>' : html_entity_decode((string)$question['pertanyaan']); ?></h3>
                        <div class="space-y-4" id="singleAnswerContainer">
                            <?php $options = $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D']; ?>
                            <?php foreach ($options as $opt): ?>
                                <?php $val = trim((string)($question['opsi_' . strtolower($opt)] ?? '')); ?>
                                <label class="answer-choice p-5 <?php echo $user_answer === $opt ? 'selected' : ''; ?>">
                                    <input type="radio" name="answer" value="<?php echo $opt; ?>" class="single-answer" data-question-id="<?php echo (int)$question['question_id']; ?>" <?php echo $user_answer === $opt ? 'checked' : ''; ?> hidden>
                                    <span class="flex-shrink-0 w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center font-black text-sm"><?php echo $opt; ?></span>
                                    <span class="choice-label text-lg"><?php echo $val ? htmlspecialchars($val) : "Choice $opt"; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="p-6 border-t-4 border-slate-200 bg-white shrink-0 flex justify-between">
                <a href="<?php echo $prev_link; ?>" id="prevBtn" class="study-button study-button-secondary <?php echo $prev_q <= 0 ? 'opacity-50 pointer-events-none' : ''; ?>">
                    <i class="fas fa-arrow-left me-2"></i> Sebelumnya
                </a>
                <button type="button" id="nextBtn" class="study-button" onclick="handleNext()">
                    <?php echo $is_last_question ? 'Selesai Section' : 'Berikutnya'; ?> <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </section>
    </main>

    <div class="tc-test-loading" aria-live="polite" aria-hidden="true"><span>Menyimpan jawaban...</span></div>

    <script>
        const testSession = document.getElementById('testSession').value, currentSection = document.getElementById('currentSection').value;
        const csrfToken = document.getElementById('csrfToken').value, mode = document.getElementById('mode').value, targetPart = document.getElementById('targetPart').value;
        const nextBtn = document.getElementById('nextBtn'), prevBtn = document.getElementById('prevBtn');
        const nextBtnDefault = nextBtn.innerHTML;
        let isNavigating = false, isSubmitting = false;

        function setTestBusy(message) {
            const loadingText = document.querySelector('.tc-test-loading span');
            if (loadingText) loadingText.textContent = message;
            document.body.classList.add('tc-saving');
        }

        function clearTestBusy() {
            document.body.classList.remove('tc-saving');
        }

        function saveAnswer(id, ans) {
            return fetch('ajax_save_toeic_answer.php', {
                method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify({ test_session: testSession, section: currentSection, question_id: parseInt(id), answer: ans })
            }).then(r => r.json());
        }

        function collectAnswers() {
            const list = [];
            if (document.getElementById('isBatch').value === '1') {
                document.querySelectorAll('.batch-answer-group').forEach(g => {
                    const chk = g.querySelector('input:checked');
                    if (chk) list.push(saveAnswer(g.dataset.questionId, chk.value));
                });
            } else {
                const chk = document.querySelector('.single-answer:checked');
                if (chk) list.push(saveAnswer(chk.dataset.questionId, chk.value));
            }
            return list;
        }

        async function handleNext() {
            if (isNavigating || isSubmitting) return;
            isNavigating = true; nextBtn.disabled = true; nextBtn.innerHTML = 'Menyimpan...'; setTestBusy('Menyimpan jawaban...');
            try {
                await Promise.all(collectAnswers());
                const nextQ = parseInt(document.getElementById('isBatch').value === '1' ? document.getElementById('lastOrder').value : document.getElementById('currentOrder').value) + 1;
                if (nextQ <= parseInt(document.getElementById('totalQuestions').value)) {
                    window.location.href = `test_toeic.php?section=${currentSection}&test_session=${testSession}&q=${nextQ}&setup_complete=1&mode=${mode}${targetPart ? '&part='+targetPart : ''}`;
                } else { submitSection(); }
            } catch (e) { alert('Save failed: ' + e.message); isNavigating = false; nextBtn.disabled = false; nextBtn.innerHTML = nextBtnDefault; clearTestBusy(); }
        }

        function submitSection() {
            isSubmitting = true; nextBtn.innerHTML = 'Mengirim...'; setTestBusy('Mengirim section...');
            fetch('ajax_submit_section_toeic.php', {
                method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify({ test_session: testSession, section: currentSection, mode: mode, target_part: targetPart })
            }).then(r => r.json()).then(d => { if (d.redirect) window.location.href = d.redirect; else throw new Error(d.error); })
            .catch(e => { alert('Submit failed: '+e.message); isSubmitting = false; nextBtn.disabled = false; nextBtn.innerHTML = nextBtnDefault; clearTestBusy(); });
        }

        document.querySelectorAll('input[type="radio"]').forEach(i => {
            i.addEventListener('change', () => {
                i.closest('.study-card, #singleAnswerContainer').querySelectorAll('.answer-choice').forEach(l => l.classList.remove('selected'));
                i.closest('.answer-choice').classList.add('selected');
                saveAnswer(i.dataset.questionId, i.value);
            });
        });

        document.querySelectorAll('[data-question-map-link="true"]').forEach(link => {
            link.addEventListener('click', async (event) => {
                if (isNavigating || isSubmitting) {
                    event.preventDefault();
                    return;
                }

                event.preventDefault();
                isNavigating = true;
                setTestBusy('Membuka nomor soal...');
                try {
                    await Promise.all(collectAnswers());
                    window.location.href = link.href;
                } catch (error) {
                    alert('Save failed: ' + error.message);
                    isNavigating = false;
                    clearTestBusy();
                }
            });
        });

        const activeMapLink = document.querySelector('.toeic-test-map a.active');
        if (activeMapLink) {
            setTimeout(() => activeMapLink.scrollIntoView({ block: 'nearest', inline: 'center' }), 80);
        }

        document.querySelectorAll('.toeic-photo-image').forEach(img => {
            setTimeout(() => {
                if (!img.dataset.loaded && (!img.complete || img.naturalWidth === 0)) {
                    handleToeicPhotoFailure(img);
                }
            }, 2500);
        });

        let timeLeft = <?php echo (int)$remaining_time; ?>;
        setInterval(() => {
            if (timeLeft <= 0) { if (!isSubmitting) submitSection(); return; }
            timeLeft--;
            document.getElementById('timerDisplay').textContent = `${Math.floor(timeLeft/60).toString().padStart(2,'0')}:${(timeLeft%60).toString().padStart(2,'0')}`;
        }, 1000);
    </script>

    <?php if ($requires_proctoring): ?>
        <script src="<?php echo htmlspecialchars(getVersionedAssetUrl('user/js/proctor.js', 'js/proctor.js')); ?>"></script>
        <script>document.addEventListener('DOMContentLoaded', () => { if (typeof ProctorSDK !== 'undefined') { window.proctorSDK = new ProctorSDK({ testSession: '<?php echo $test_session; ?>', ajaxUrl: '../api/ajax_proctor.php', microphoneGranted: true }).start(); } });</script>
    <?php endif; ?>
</body>
</html>
