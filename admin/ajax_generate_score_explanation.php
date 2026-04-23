<?php
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/db_utils.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/ai_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
    exit();
}

$test_session = trim((string)($_POST['test_session'] ?? ''));
$test_format = (string)($_POST['test_format'] ?? 'toeic');
$regenerate = isset($_POST['regenerate']);
$analysis_type = 'score_explanation';

if ($test_session === '') {
    echo json_encode(['success' => false, 'error' => 'Sesi tes tidak ditemukan']);
    exit();
}

if ($test_format !== 'toeic') {
    echo json_encode(['success' => false, 'error' => 'Endpoint ini hanya untuk TOEIC']);
    exit();
}

function ensureAdminAnalysisCache(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS admin_analysis_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_session VARCHAR(100) NOT NULL,
            analysis_type VARCHAR(80) NOT NULL,
            analysis_content LONGTEXT NOT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_session_analysis_type (test_session, analysis_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [];
    $columnResult = $conn->query("SHOW COLUMNS FROM admin_analysis_cache");
    if ($columnResult) {
        while ($row = $columnResult->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    }

    if (!isset($columns['analysis_type'])) {
        $conn->query("ALTER TABLE admin_analysis_cache ADD COLUMN analysis_type VARCHAR(80) NOT NULL DEFAULT 'ai_insights' AFTER test_session");
        $columns['analysis_type'] = true;
    }
    if (!isset($columns['generated_at'])) {
        $conn->query("ALTER TABLE admin_analysis_cache ADD COLUMN generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $columns['generated_at'] = true;
    }

    $uniqueIndexes = [];
    $indexResult = $conn->query("SHOW INDEX FROM admin_analysis_cache");
    if ($indexResult) {
        while ($row = $indexResult->fetch_assoc()) {
            if ((int)$row['Non_unique'] !== 0 || $row['Key_name'] === 'PRIMARY') {
                continue;
            }
            $uniqueIndexes[$row['Key_name']][(int)$row['Seq_in_index']] = $row['Column_name'];
        }
    }

    $hasCompositeUnique = false;
    foreach ($uniqueIndexes as $indexName => $columnsInIndex) {
        ksort($columnsInIndex);
        $orderedColumns = array_values($columnsInIndex);
        if ($orderedColumns === ['test_session', 'analysis_type']) {
            $hasCompositeUnique = true;
            continue;
        }

        if ($orderedColumns === ['test_session']) {
            $safeIndexName = str_replace('`', '``', $indexName);
            $conn->query("ALTER TABLE admin_analysis_cache DROP INDEX `{$safeIndexName}`");
        }
    }

    if (!$hasCompositeUnique) {
        if (isset($columns['id'])) {
            $conn->query("
                DELETE newer
                FROM admin_analysis_cache newer
                JOIN admin_analysis_cache older
                  ON newer.test_session = older.test_session
                 AND newer.analysis_type = older.analysis_type
                 AND newer.id < older.id
            ");
        }
        $conn->query("ALTER TABLE admin_analysis_cache ADD UNIQUE KEY uq_session_analysis_type (test_session, analysis_type)");
    }
}

function formatPercent(float $earned, float $total): string
{
    if ($total <= 0) {
        return '0%';
    }
    return number_format(($earned / $total) * 100, 1) . '%';
}

function collectToeicScoreExplanationFacts(mysqli $conn, string $test_session): array
{
    $uid = getUsersIdColumn($conn);
    $stmt = $conn->prepare("
        SELECT s.*, u.full_name, u.username
        FROM toeic_test_sessions s
        JOIN users u ON s.user_id = u.{$uid}
        WHERE s.test_session = ?
    ");
    $stmt->bind_param("s", $test_session);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        throw new Exception('Data sesi TOEIC tidak ditemukan');
    }

    $stmt = $conn->prepare("SELECT * FROM toeic_test_results WHERE test_session = ? LIMIT 1");
    $stmt->bind_param("s", $test_session);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $is_practice = !empty($session['practice_mode']);
    $student_name = (string)($session['full_name'] ?: $session['username'] ?: '-');
    $facts = [];
    $formula = '';
    $score_summary = '';

    if ($result) {
        $listening_raw = (int)($result['listening_raw'] ?? 0);
        $reading_raw = (int)($result['reading_raw'] ?? 0);
        $listening_scaled = (int)($result['listening_scaled'] ?? 0);
        $reading_scaled = (int)($result['reading_scaled'] ?? 0);
        $total_score = (int)($result['total_score'] ?? ($listening_scaled + $reading_scaled));
        $cefr_level = (string)($result['cefr_level'] ?? '-');

        $score_summary = "Total {$total_score}/990, CEFR {$cefr_level}";
        $formula = 'TOEIC dihitung dari jumlah skor scaled Listening dan Reading. Raw correct dikonversi ke scaled score per seksi, lalu Listening + Reading menjadi total 10-990.';
        $facts[] = "Listening raw {$listening_raw}/100 dikonversi menjadi {$listening_scaled}/495.";
        $facts[] = "Reading raw {$reading_raw}/100 dikonversi menjadi {$reading_scaled}/495.";
        $facts[] = "Total skor adalah {$listening_scaled} + {$reading_scaled} = {$total_score}/990.";
    } elseif ($is_practice) {
        $practice = getTOEICPracticeSummary((int)$session['user_id'], $test_session);
        if (!$practice) {
            throw new Exception('Data practice TOEIC belum cukup untuk dijelaskan');
        }
        $score_summary = 'Practice Part ' . $practice['part'] . ': ' . $practice['correct'] . '/' . $practice['total'] . ' benar (' . $practice['accuracy'] . '%)';
        $formula = 'Mode practice tidak menghasilkan skor TOEIC 10-990. Nilai yang ditampilkan adalah akurasi latihan pada part yang dipilih.';
        $facts[] = 'Mode practice: Part ' . $practice['part'] . ' ' . ($practice['part_info']['name'] ?? '') . '.';
        $facts[] = 'Benar ' . $practice['correct'] . '/' . $practice['total'] . ', salah ' . $practice['incorrect'] . ', akurasi ' . $practice['accuracy'] . '%.';
    } else {
        $score_summary = 'Sesi TOEIC belum memiliki hasil final.';
        $formula = 'Skor final belum bisa dihitung sampai sesi selesai dan hasil tersimpan.';
    }

    $stmt = $conn->prepare("
        SELECT
            section,
            part,
            COUNT(*) AS total_items,
            COALESCE(SUM(CASE WHEN COALESCE(is_correct, 0) = 1 THEN 1 ELSE 0 END), 0) AS correct_items,
            COALESCE(SUM(CASE WHEN user_answer IS NULL OR TRIM(user_answer) = '' THEN 1 ELSE 0 END), 0) AS unanswered
        FROM toeic_test_questions
        WHERE test_session = ?
        GROUP BY section, part
        ORDER BY CAST(part AS UNSIGNED)
    ");
    $stmt->bind_param("s", $test_session);
    $stmt->execute();
    $res = $stmt->get_result();
    $sectionTotals = [];
    while ($row = $res->fetch_assoc()) {
        $section = (string)$row['section'];
        $part = (string)$row['part'];
        $total = (int)$row['total_items'];
        $correct = (int)$row['correct_items'];
        $unanswered = (int)$row['unanswered'];
        $partInfo = getTOEICPartInfo($part);
        $label = 'Part ' . $part . ($partInfo ? ' - ' . $partInfo['name'] : '');
        $facts[] = $label . ': benar ' . $correct . '/' . $total . ' (' . formatPercent($correct, $total) . '), kosong ' . $unanswered . '.';

        if (!isset($sectionTotals[$section])) {
            $sectionTotals[$section] = ['correct' => 0, 'total' => 0, 'unanswered' => 0];
        }
        $sectionTotals[$section]['correct'] += $correct;
        $sectionTotals[$section]['total'] += $total;
        $sectionTotals[$section]['unanswered'] += $unanswered;
    }
    $stmt->close();

    foreach ($sectionTotals as $section => $data) {
        $facts[] = ucfirst($section) . ' total dari log soal: benar ' . $data['correct'] . '/' . $data['total'] . ' (' . formatPercent((float)$data['correct'], (float)$data['total']) . '), kosong ' . $data['unanswered'] . '.';
    }

    if (empty($facts)) {
        $facts[] = 'Belum ada log soal yang tersimpan untuk sesi ini.';
    }

    return [
        'student_name' => $student_name,
        'format_label' => 'TOEIC',
        'score_summary' => $score_summary,
        'formula' => $formula,
        'facts' => $facts,
    ];
}

try {
    ensureAdminAnalysisCache($conn);
} catch (Throwable $e) {
    error_log('Failed to ensure admin_analysis_cache: ' . $e->getMessage());
}

if (!$regenerate) {
    try {
        $stmt = $conn->prepare("
            SELECT analysis_content
            FROM admin_analysis_cache
            WHERE test_session = ? AND analysis_type = ?
            ORDER BY generated_at DESC
            LIMIT 1
        ");
        $stmt->bind_param("ss", $test_session, $analysis_type);
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($cached) {
            echo json_encode(['success' => true, 'content' => $cached['analysis_content'], 'cached' => true]);
            exit();
        }
    } catch (Throwable $e) {
        error_log('Failed to read cached score explanation: ' . $e->getMessage());
    }
}

$ai_config = getActiveAIProvider();
if (!$ai_config) {
    echo json_encode(['success' => false, 'error' => 'Provider AI belum dikonfigurasi. Silakan konfigurasi di AI API Settings.']);
    exit();
}

try {
    $data = collectToeicScoreExplanationFacts($conn, $test_session);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Gagal mengambil data nilai: ' . $e->getMessage()]);
    exit();
}

$facts_text = '';
foreach ($data['facts'] as $fact) {
    $facts_text .= '- ' . $fact . "\n";
}

$prompt = <<<PROMPT
Kamu adalah analis hasil TOEIC. Jelaskan kenapa siswa mendapat nilai tersebut.

Aturan:
- Gunakan hanya data faktual yang diberikan.
- Jangan mengarang penyebab di luar data.
- Bahasa Indonesia sederhana, singkat, dan mudah dipahami.
- Fokus pada alasan nilai, bukan rencana belajar panjang.
- Jika ada jawaban kosong, sebutkan sebagai fakta.

Data:
Format tes: {$data['format_label']}
Nama siswa: {$data['student_name']}
Skor/ringkasan: {$data['score_summary']}
Rumus/perhitungan: {$data['formula']}

Bukti data:
{$facts_text}

Tulis dengan struktur:
1. Ringkasan nilai (2 kalimat).
2. Alasan utama skor tersebut (3-5 bullet).
3. Bukti paling penting dari data (2-4 bullet).
4. Kesimpulan singkat (1 kalimat).
PROMPT;

try {
    $ai_response = trim((string)callAI($prompt, $ai_config, 1400, [], 120000));
    if ($ai_response === '') {
        echo json_encode(['success' => false, 'error' => 'AI tidak memberikan respons']);
        exit();
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO admin_analysis_cache (test_session, analysis_type, analysis_content, generated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                analysis_content = VALUES(analysis_content),
                generated_at = NOW()
        ");
        $stmt->bind_param("sss", $test_session, $analysis_type, $ai_response);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Failed to cache score explanation: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'content' => $ai_response]);
} catch (Throwable $e) {
    error_log('Score explanation generation error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Gagal menghasilkan penjelasan nilai: ' . $e->getMessage()]);
}
