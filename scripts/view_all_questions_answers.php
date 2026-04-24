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
require_once __DIR__ . '/../includes/toeic_asset_storage.php';

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
                ta.judul AS stimulus_title,
                ta.file_path AS audio_path,
                ta.id_photo,
                tp.file_path AS photo_path,
                tp.description AS photo_description,
                NULL AS id_teks,
                NULL AS text_title,
                NULL AS text_body,
                NULL AS text_body_2,
                NULL AS text_body_3
            FROM toeic_soal_listening sl
            LEFT JOIN toeic_audio ta ON sl.id_audio = ta.id_audio
            LEFT JOIN toeic_photos tp ON ta.id_photo = tp.id_photo
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
                NULL AS id_audio,
                NULL AS stimulus_title,
                NULL AS audio_path,
                NULL AS id_photo,
                NULL AS photo_path,
                NULL AS photo_description,
                sr.id_teks,
                tt.judul AS text_title,
                tt.isi_teks AS text_body,
                tt.isi_teks_2 AS text_body_2,
                tt.isi_teks_3 AS text_body_3
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

function expectedLettersForPart(string $part): array
{
    return $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
}

function isAudioOnlyChoicePart(string $part): bool
{
    return in_array($part, ['1', '2'], true);
}

function buildDisplayOptions(array $row): array
{
    $part = (string)($row['part'] ?? '');
    $storedOptions = buildOptions($row);
    $options = [];

    foreach (expectedLettersForPart($part) as $letter) {
        $stored = $storedOptions[$letter] ?? '';
        $options[$letter] = [
            'text' => $stored !== '' ? $stored : 'Choice ' . $letter,
            'synthetic' => $stored === '',
        ];
    }

    return $options;
}

function buildQuestionIssues(array $row): array
{
    $issues = [];
    $section = (string)($row['section'] ?? '');
    $part = (string)($row['part'] ?? '');
    $storedOptions = buildOptions($row);
    $expectedLetters = expectedLettersForPart($part);
    $correct = strtoupper(trim((string)($row['correct_answer'] ?? '')));

    if (!in_array($correct, $expectedLetters, true)) {
        $issues[] = ['level' => 'danger', 'text' => 'Jawaban benar di luar opsi valid (' . implode('/', $expectedLetters) . ').'];
    }

    if (!isAudioOnlyChoicePart($part)) {
        foreach ($expectedLetters as $letter) {
            if (!isset($storedOptions[$letter])) {
                $issues[] = ['level' => 'danger', 'text' => "Opsi {$letter} kosong."];
            }
        }
    }

    if ($part === '2' && trim((string)($row['opsi_d'] ?? '')) !== '') {
        $issues[] = ['level' => 'warning', 'text' => 'Part 2 seharusnya hanya A/B/C, tetapi opsi D terisi.'];
    }

    if ($section === 'listening') {
        if (empty($row['id_audio'])) {
            $issues[] = ['level' => 'danger', 'text' => 'Listening question belum terhubung ke audio.'];
        } elseif (trim((string)($row['audio_path'] ?? '')) === '') {
            $issues[] = ['level' => 'danger', 'text' => 'Audio terhubung, tetapi file_path audio kosong.'];
        }

        if ($part === '1') {
            if (empty($row['id_photo'])) {
                $issues[] = ['level' => 'danger', 'text' => 'Part 1 belum terhubung ke gambar.'];
            } elseif (trim((string)($row['photo_path'] ?? '')) === '') {
                $issues[] = ['level' => 'danger', 'text' => 'Gambar terhubung, tetapi file_path gambar kosong.'];
            }
        }
    }

    if ($section === 'reading' && in_array($part, ['6', '7'], true)) {
        if (empty($row['id_teks'])) {
            $issues[] = ['level' => 'danger', 'text' => 'Reading Part ' . $part . ' belum terhubung ke teks/passage.'];
        } elseif (trim((string)($row['text_body'] ?? '')) === '') {
            $issues[] = ['level' => 'danger', 'text' => 'Passage terhubung, tetapi isi teks utama kosong.'];
        }
    }

    if (trim((string)($row['question_text'] ?? '')) === '' && !isAudioOnlyChoicePart($part)) {
        $issues[] = ['level' => 'warning', 'text' => 'Teks pertanyaan kosong.'];
    }

    return $issues;
}

function summarizeIssueCounts(array $questions): array
{
    $counts = ['danger' => 0, 'warning' => 0];
    foreach ($questions as $row) {
        foreach (buildQuestionIssues($row) as $issue) {
            if (isset($counts[$issue['level']])) {
                $counts[$issue['level']]++;
            }
        }
    }
    return $counts;
}

function renderPassagePreview(array $row): string
{
    $parts = [];
    foreach (['text_body', 'text_body_2', 'text_body_3'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode("\n\n---\n\n", $parts);
}

$requestedSection = $isCli ? ($argv[1] ?? 'all') : ($_GET['section'] ?? 'all');
$requestedPart = $isCli ? ($argv[2] ?? '') : ($_GET['part'] ?? '');

$section = normalizeSection($requestedSection);
$part = normalizePart($requestedPart);
$questions = fetchBankQuestions($conn, $section, $part);
$websiteTitle = function_exists('getWebsiteTitle') ? getWebsiteTitle() : 'TOEIC';
$issueCounts = summarizeIssueCounts($questions);

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
            echo PHP_EOL . strtoupper($currentSection) . ' | PART ' . $currentPart . PHP_EOL;
            echo str_repeat('-', 100) . PHP_EOL;
        }

        echo "#{$row['nomor_soal']} | QID {$row['question_id']}" . PHP_EOL;
        echo "Question: " . trim((string)$row['question_text']) . PHP_EOL;
        foreach (buildDisplayOptions($row) as $letter => $option) {
            echo "  {$letter}. {$option['text']}" . ($option['synthetic'] ? ' [audio-only display]' : '') . PHP_EOL;
        }
        echo "Correct: " . strtoupper(trim((string)$row['correct_answer'])) . PHP_EOL;
        if (!empty($row['stimulus_title'])) {
            echo "Audio: {$row['stimulus_title']} | {$row['audio_path']}" . PHP_EOL;
        }
        if (!empty($row['photo_path'])) {
            echo "Photo: {$row['photo_path']}" . PHP_EOL;
        }
        if (!empty($row['text_title'])) {
            echo "Text: {$row['text_title']}" . PHP_EOL;
        }
        foreach (buildQuestionIssues($row) as $issue) {
            echo strtoupper($issue['level']) . ': ' . $issue['text'] . PHP_EOL;
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
        .asset-card {
            background: rgba(15,23,42,0.72);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 1rem;
        }
        .asset-preview {
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            border-radius: 14px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .option-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem; }
        .option-box {
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 0.7rem 0.8rem;
            background: rgba(255,255,255,0.035);
        }
        .option-box.correct { border-color: rgba(34,197,94,0.72); background: rgba(34,197,94,0.12); }
        .passage-preview {
            max-height: 280px;
            overflow: auto;
            white-space: pre-wrap;
            color: #d1d5db;
        }
        .question-text {
            white-space: pre-wrap;
            color: #f8fafc;
        }
        audio { width: 100%; min-width: 240px; }
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
                    Section <code><?php echo htmlspecialchars($section); ?></code> |
                    Part <code><?php echo $part !== '' ? htmlspecialchars($part) : 'all'; ?></code> |
                    Total <code><?php echo count($questions); ?></code> |
                    Errors <code><?php echo (int)$issueCounts['danger']; ?></code> |
                    Warnings <code><?php echo (int)$issueCounts['warning']; ?></code>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ((int)$issueCounts['danger'] > 0): ?>
                    <a href="repair_toeic_correct_answers.php" class="btn btn-warning">Preview Perbaikan</a>
                <?php endif; ?>
                <a href="../admin/manage_toeic.php" class="btn btn-outline-light">Kembali ke Admin</a>
            </div>
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
                    echo '<h2 class="section-title h4 fw-bold">' . htmlspecialchars(strtoupper($currentSection)) . ' | PART ' . htmlspecialchars($currentPart) . '</h2>';
                endif;
                $options = buildDisplayOptions($row);
                $issues = buildQuestionIssues($row);
                $audioUrl = !empty($row['audio_path']) ? toeicAudioUrl((string)$row['audio_path']) : '';
                $photoUrls = !empty($row['photo_path']) ? toeicPhotoUrlCandidates((string)$row['photo_path']) : [];
                $photoUrl = $photoUrls[0] ?? '';
                $passage = renderPassagePreview($row);
                $correctAnswer = strtoupper(trim((string)$row['correct_answer']));
            ?>
                <div class="question-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="fw-semibold">#<?php echo (int)$row['nomor_soal']; ?> | QID <?php echo (int)$row['question_id']; ?></div>
                            <div class="meta">
                                <?php if (!empty($row['stimulus_title'])): ?>
                                    Stimulus: <?php echo htmlspecialchars($row['stimulus_title']); ?>
                                <?php else: ?>
                                    No linked stimulus metadata
                                <?php endif; ?>
                                <?php if (!empty($row['question_type'])): ?>
                                    | Type: <?php echo htmlspecialchars($row['question_type']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="fw-bold text-warning">Answer: <?php echo htmlspecialchars($correctAnswer); ?></div>
                    </div>

                    <?php if (!empty($issues)): ?>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <?php foreach ($issues as $issue): ?>
                                <span class="badge text-bg-<?php echo $issue['level'] === 'danger' ? 'danger' : 'warning'; ?>">
                                    <?php echo htmlspecialchars($issue['text']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($row['section'] === 'listening' || $passage !== ''): ?>
                        <div class="row g-3 mt-2">
                            <?php if ($row['section'] === 'listening'): ?>
                                <div class="col-lg-6">
                                    <div class="asset-card h-100">
                                        <div class="fw-semibold mb-2">Audio</div>
                                        <?php if ($audioUrl !== ''): ?>
                                            <audio controls preload="none">
                                                <source src="<?php echo htmlspecialchars($audioUrl); ?>">
                                            </audio>
                                            <div class="meta mt-2"><?php echo htmlspecialchars((string)($row['audio_path'] ?? '')); ?></div>
                                        <?php else: ?>
                                            <div class="text-danger">Audio tidak tersedia.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ((string)$row['part'] === '1' || $photoUrl !== ''): ?>
                                    <div class="col-lg-6">
                                        <div class="asset-card h-100">
                                            <div class="fw-semibold mb-2">Photo</div>
                                            <?php if ($photoUrl !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($photoUrl); ?>" class="asset-preview" alt="TOEIC photo preview">
                                                <div class="meta mt-2"><?php echo htmlspecialchars((string)($row['photo_path'] ?? '')); ?></div>
                                            <?php else: ?>
                                                <div class="text-danger">Gambar tidak tersedia.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($passage !== ''): ?>
                                <div class="col-12">
                                    <div class="asset-card">
                                        <div class="fw-semibold mb-2">Passage<?php echo !empty($row['text_title']) ? ': ' . htmlspecialchars((string)$row['text_title']) : ''; ?></div>
                                        <div class="passage-preview"><?php echo htmlspecialchars($passage); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <div class="fw-semibold mb-2">Question</div>
                        <div class="question-text">
                            <?php
                            $questionText = trim((string)$row['question_text']);
                            echo htmlspecialchars($questionText !== '' ? $questionText : '(Question is delivered in the audio)');
                            ?>
                        </div>
                    </div>

                    <?php if (!empty($options)): ?>
                        <div class="mt-3">
                            <div class="fw-semibold mb-2">Options</div>
                            <div class="option-grid">
                                <?php foreach ($options as $letter => $option): ?>
                                    <div class="option-box <?php echo $letter === $correctAnswer ? 'correct' : ''; ?>">
                                        <div class="d-flex justify-content-between gap-2">
                                            <strong><?php echo $letter; ?>.</strong>
                                            <?php if ($option['synthetic']): ?>
                                                <span class="badge text-bg-secondary">audio-only</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1"><?php echo htmlspecialchars($option['text']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
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
