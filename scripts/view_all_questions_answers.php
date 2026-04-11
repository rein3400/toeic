<?php
/**
 * View all TOEIC question-bank items in the database.
 *
 * Scope:
 * - Listening bank: toeic_soal_listening
 * - Reading bank: toeic_soal_reading
 *
 * Usage (web):
 *   /scripts/view_all_questions_answers.php
 *   /scripts/view_all_questions_answers.php?section=listening
 *   /scripts/view_all_questions_answers.php?section=reading&part=7
 *
 * Usage (CLI):
 *   php scripts/view_all_questions_answers.php
 *   php scripts/view_all_questions_answers.php listening
 *   php scripts/view_all_questions_answers.php reading 7
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/../includes/session_handler.php';
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../admin/login.php");
        exit();
    }
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/settings.php';

function normalizeSection(?string $value): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['all', 'listening', 'reading'], true) ? $value : 'all';
}

function normalizePart(?string $value): string
{
    $value = preg_replace('/[^1-7]/', '', (string)$value);
    return $value !== '' ? $value : '';
}

function fetchBankQuestions(mysqli $conn, string $section, string $part = ''): array
{
    $datasets = [];

    if ($section === 'all' || $section === 'listening') {
        $sql = "
            SELECT
                'listening' AS section,
                sl.part,
                sl.nomor_soal,
                sl.id_soal AS question_id,
                sl.pertanyaan AS question_text,
                sl.opsi_a,
                sl.opsi_b,
                sl.opsi_c,
                sl.opsi_d,
                sl.jawaban_benar AS correct_answer,
                sl.explanation,
                sl.question_type,
                sl.id_audio,
                ta.judul AS stimulus_title
            FROM toeic_soal_listening sl
            LEFT JOIN toeic_audio ta ON sl.id_audio = ta.id_audio
        ";
        $params = [];
        $types = '';
        if ($part !== '') {
            $sql .= " WHERE sl.part = ?";
            $params[] = $part;
            $types .= 's';
        }
        $sql .= " ORDER BY CAST(sl.part AS UNSIGNED), sl.nomor_soal ASC, sl.id_soal ASC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $datasets = array_merge($datasets, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }

    if ($section === 'all' || $section === 'reading') {
        $sql = "
            SELECT
                'reading' AS section,
                sr.part,
                sr.nomor_soal,
                sr.id_soal AS question_id,
                sr.pertanyaan AS question_text,
                sr.opsi_a,
                sr.opsi_b,
                sr.opsi_c,
                sr.opsi_d,
                sr.jawaban_benar AS correct_answer,
                sr.explanation,
                sr.question_type,
                sr.id_teks,
                tt.judul AS stimulus_title
            FROM toeic_soal_reading sr
            LEFT JOIN toeic_teks tt ON sr.id_teks = tt.id_teks
        ";
        $params = [];
        $types = '';
        if ($part !== '') {
            $sql .= " WHERE sr.part = ?";
            $params[] = $part;
            $types .= 's';
        }
        $sql .= " ORDER BY CAST(sr.part AS UNSIGNED), sr.nomor_soal ASC, sr.id_soal ASC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $datasets = array_merge($datasets, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }

    usort($datasets, function (array $a, array $b): int {
        $sectionOrder = ['listening' => 1, 'reading' => 2];
        $aSection = $sectionOrder[$a['section']] ?? 99;
        $bSection = $sectionOrder[$b['section']] ?? 99;
        if ($aSection !== $bSection) {
            return $aSection <=> $bSection;
        }
        $aPart = (int)($a['part'] ?? 0);
        $bPart = (int)($b['part'] ?? 0);
        if ($aPart !== $bPart) {
            return $aPart <=> $bPart;
        }
        $aNumber = (int)($a['nomor_soal'] ?? 0);
        $bNumber = (int)($b['nomor_soal'] ?? 0);
        if ($aNumber !== $bNumber) {
            return $aNumber <=> $bNumber;
        }
        return ((int)$a['question_id']) <=> ((int)$b['question_id']);
    });

    return $datasets;
}

function buildOptions(array $row): array
{
    $options = [];
    foreach (['A', 'B', 'C', 'D'] as $letter) {
        $key = 'opsi_' . strtolower($letter);
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            $options[$letter] = $value;
        }
    }
    return $options;
}

$requestedSection = $isCli ? ($argv[1] ?? 'all') : ($_GET['section'] ?? 'all');
$requestedPart = $isCli ? ($argv[2] ?? '') : ($_GET['part'] ?? '');

$section = normalizeSection($requestedSection);
$part = normalizePart($requestedPart);
$questions = fetchBankQuestions($conn, $section, $part);
$websiteTitle = function_exists('getWebsiteTitle') ? getWebsiteTitle() : 'TOEIC';

if ($isCli) {
    echo "Section: {$section}" . PHP_EOL;
    echo "Part: " . ($part !== '' ? $part : 'all') . PHP_EOL;
    echo "Total: " . count($questions) . PHP_EOL;
    echo str_repeat('=', 100) . PHP_EOL;

    $currentSection = '';
    $currentPart = '';
    foreach ($questions as $row) {
        if ($currentSection !== $row['section'] || $currentPart !== (string)$row['part']) {
            $currentSection = $row['section'];
            $currentPart = (string)$row['part'];
            echo PHP_EOL . strtoupper($currentSection) . ' · PART ' . $currentPart . PHP_EOL;
            echo str_repeat('-', 100) . PHP_EOL;
        }

        echo "#{$row['nomor_soal']} | QID {$row['question_id']}" . PHP_EOL;
        echo "Question: " . trim((string)$row['question_text']) . PHP_EOL;
        foreach (buildOptions($row) as $letter => $value) {
            echo "  {$letter}. {$value}" . PHP_EOL;
        }
        echo "Correct: " . strtoupper(trim((string)$row['correct_answer'])) . PHP_EOL;
        if (!empty($row['stimulus_title'])) {
            echo "Stimulus: {$row['stimulus_title']}" . PHP_EOL;
        }
        echo str_repeat('-', 100) . PHP_EOL;
    }
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Question Bank - <?php echo htmlspecialchars($websiteTitle); ?></title>
    <?php echo function_exists('getFaviconHTML') ? getFaviconHTML() : ''; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0f172a; color: #e5e7eb; }
        .shell { max-width: 1280px; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .panel {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            padding: 1.5rem;
        }
        .question-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }
        .option-list { margin: 0.75rem 0 0; padding-left: 1.2rem; }
        .option-list li { margin-bottom: 0.3rem; }
        .meta { color: #94a3b8; font-size: 0.9rem; }
        .section-title { margin-top: 2rem; margin-bottom: 1rem; }
        code { color: #fbbf24; }
        .filter-form .form-select,
        .filter-form .form-control {
            background: rgba(255,255,255,0.06);
            color: #e5e7eb;
            border-color: rgba(255,255,255,0.14);
        }
        .filter-form .form-select option {
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <div class="meta">TOEIC Question Bank Inspector</div>
                <h1 class="h3 fw-bold mb-1">Semua Soal di Database</h1>
                <div class="meta">
                    Section <code><?php echo htmlspecialchars($section); ?></code> ·
                    Part <code><?php echo $part !== '' ? htmlspecialchars($part) : 'all'; ?></code> ·
                    Total <code><?php echo count($questions); ?></code>
                </div>
            </div>
            <a href="../admin/manage_toeic.php" class="btn btn-outline-light">Kembali ke Admin</a>
        </div>

        <div class="panel mb-4">
            <form method="get" class="filter-form row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label meta">Section</label>
                    <select name="section" class="form-select">
                        <option value="all" <?php echo $section === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="listening" <?php echo $section === 'listening' ? 'selected' : ''; ?>>Listening</option>
                        <option value="reading" <?php echo $section === 'reading' ? 'selected' : ''; ?>>Reading</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label meta">Part</label>
                    <input type="text" name="part" value="<?php echo htmlspecialchars($part); ?>" class="form-control" placeholder="Kosongkan untuk semua part">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-warning">Filter</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <?php
            $currentSection = null;
            $currentPart = null;
            foreach ($questions as $row):
                if ($currentSection !== $row['section'] || $currentPart !== (string)$row['part']):
                    $currentSection = $row['section'];
                    $currentPart = (string)$row['part'];
                    echo '<h2 class="section-title h4 fw-bold">' . htmlspecialchars(strtoupper($currentSection)) . ' · PART ' . htmlspecialchars($currentPart) . '</h2>';
                endif;
                $options = buildOptions($row);
            ?>
                <div class="question-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="fw-semibold">#<?php echo (int)$row['nomor_soal']; ?> · QID <?php echo (int)$row['question_id']; ?></div>
                            <div class="meta">
                                <?php if (!empty($row['stimulus_title'])): ?>
                                    Stimulus: <?php echo htmlspecialchars($row['stimulus_title']); ?>
                                <?php else: ?>
                                    No linked stimulus metadata
                                <?php endif; ?>
                                <?php if (!empty($row['question_type'])): ?>
                                    · Type: <?php echo htmlspecialchars($row['question_type']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="fw-bold text-warning">Answer: <?php echo htmlspecialchars(strtoupper(trim((string)$row['correct_answer']))); ?></div>
                    </div>

                    <div class="mt-3">
                        <div class="fw-semibold mb-2">Question</div>
                        <div><?php echo nl2br(htmlspecialchars(trim((string)$row['question_text']))); ?></div>
                    </div>

                    <?php if (!empty($options)): ?>
                        <div class="mt-3">
                            <div class="fw-semibold mb-2">Options</div>
                            <ol class="option-list">
                                <?php foreach ($options as $letter => $value): ?>
                                    <li><strong><?php echo $letter; ?>.</strong> <?php echo htmlspecialchars($value); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($row['explanation'])): ?>
                        <div class="mt-3">
                            <div class="meta">Explanation</div>
                            <div><?php echo nl2br(htmlspecialchars(trim((string)$row['explanation']))); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($questions)): ?>
                <p class="mb-0 text-muted">Tidak ada soal untuk filter yang dipilih.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
