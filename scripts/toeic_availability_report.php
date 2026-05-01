<?php
/**
 * Browser report for TOEIC question-bank asset availability.
 *
 * Usage:
 *   /scripts/toeic_availability_report.php
 *   /scripts/toeic_availability_report.php?section=listening&part=1
 *   /scripts/toeic_availability_report.php?mode=issues
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/../includes/session_handler.php';
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header('Location: ../admin/login.php');
        exit();
    }
} else {
    require_once __DIR__ . '/../includes/config.php';
}

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/toeic_asset_storage.php';

function availabilityNormalizeSection(?string $value): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['all', 'listening', 'reading'], true) ? $value : 'all';
}

function availabilityNormalizePart(?string $value): string
{
    $value = preg_replace('/[^1-7]/', '', (string)$value);
    return $value !== '' ? $value : '';
}

function availabilityNormalizeMode(?string $value): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['all', 'issues'], true) ? $value : 'all';
}

function availabilityH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function availabilityTableExists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function availabilityTargets(): array
{
    return [
        '1' => ['section' => 'listening', 'name' => 'Photographs', 'target' => 6],
        '2' => ['section' => 'listening', 'name' => 'Question-Response', 'target' => 25],
        '3' => ['section' => 'listening', 'name' => 'Conversations', 'target' => 39],
        '4' => ['section' => 'listening', 'name' => 'Talks', 'target' => 30],
        '5' => ['section' => 'reading', 'name' => 'Incomplete Sentences', 'target' => 30],
        '6' => ['section' => 'reading', 'name' => 'Text Completion', 'target' => 16],
        '7' => ['section' => 'reading', 'name' => 'Reading Comprehension', 'target' => 54],
    ];
}

function availabilityExpectedLetters(string $part): array
{
    return $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
}

function availabilityIsAudioOnlyChoicePart(string $part): bool
{
    return in_array($part, ['1', '2'], true);
}

function availabilityAudioState(array $row): array
{
    if (empty($row['id_audio'])) {
        return ['level' => 'bad', 'label' => 'Belum link audio', 'url' => ''];
    }

    if (empty($row['audio_record_found'])) {
        return ['level' => 'bad', 'label' => 'Record audio tidak ada', 'url' => ''];
    }

    $path = trim((string)($row['audio_path'] ?? ''));
    if ($path === '') {
        return ['level' => 'bad', 'label' => 'Path audio kosong', 'url' => ''];
    }

    $source = toeicAudioSource($path);
    $url = toeicAudioUrl($path);

    if (($source['mode'] ?? '') === 'local' && !is_file((string)($source['path'] ?? ''))) {
        return ['level' => 'warn', 'label' => 'Path ada, file lokal tidak ditemukan', 'url' => $url];
    }

    if (($source['mode'] ?? '') === 'remote') {
        return ['level' => 'ok', 'label' => 'Remote URL tersedia', 'url' => $url];
    }

    return ['level' => 'ok', 'label' => 'Audio tersedia', 'url' => $url];
}

function availabilityPhotoState(array $row): array
{
    if (empty($row['id_photo'])) {
        return ['level' => 'bad', 'label' => 'Belum link gambar', 'url' => ''];
    }

    if (empty($row['photo_record_found'])) {
        return ['level' => 'bad', 'label' => 'Record gambar tidak ada', 'url' => ''];
    }

    $path = trim((string)($row['photo_path'] ?? ''));
    if ($path === '') {
        return ['level' => 'bad', 'label' => 'Path gambar kosong', 'url' => ''];
    }

    $urls = toeicPhotoUrlCandidates($path);
    $url = $urls[0] ?? '';

    if (preg_match('#^https?://#i', $path) || toeicAssetDriver('photo') === 'r2') {
        return ['level' => 'ok', 'label' => 'Gambar path tersedia', 'url' => $url];
    }

    $localMatches = toeicAssetLocalFileCandidates($path, 'photo');
    if (empty($localMatches)) {
        return ['level' => 'warn', 'label' => 'Path ada, file lokal tidak ditemukan', 'url' => $url];
    }

    return ['level' => 'ok', 'label' => 'Gambar tersedia', 'url' => $url];
}

function availabilityPassageState(array $row): array
{
    $part = (string)($row['part'] ?? '');
    if (!in_array($part, ['6', '7'], true)) {
        return ['level' => 'neutral', 'label' => 'Tidak wajib', 'preview' => ''];
    }

    if (empty($row['id_teks'])) {
        return ['level' => 'bad', 'label' => 'Belum link passage', 'preview' => ''];
    }

    if (empty($row['text_record_found'])) {
        return ['level' => 'bad', 'label' => 'Record passage tidak ada', 'preview' => ''];
    }

    $body = trim((string)($row['text_body'] ?? ''));
    if ($body === '') {
        return ['level' => 'bad', 'label' => 'Isi passage kosong', 'preview' => ''];
    }

    return ['level' => 'ok', 'label' => 'Passage tersedia', 'preview' => availabilityTextPreview($body, 160)];
}

function availabilityQuestionIssues(array $row): array
{
    $issues = [];
    $section = (string)($row['section'] ?? '');
    $part = (string)($row['part'] ?? '');
    $correct = strtoupper(trim((string)($row['jawaban_benar'] ?? '')));
    $expectedLetters = availabilityExpectedLetters($part);
    $questionText = trim((string)($row['pertanyaan'] ?? ''));

    if (!in_array($correct, $expectedLetters, true)) {
        $issues[] = ['level' => 'bad', 'text' => 'Jawaban benar di luar opsi valid'];
    }

    if (!availabilityIsAudioOnlyChoicePart($part)) {
        foreach ($expectedLetters as $letter) {
            $optionKey = 'opsi_' . strtolower($letter);
            if (trim((string)($row[$optionKey] ?? '')) === '') {
                $issues[] = ['level' => 'bad', 'text' => "Opsi {$letter} kosong"];
            }
        }
    }

    if ($part === '2' && trim((string)($row['opsi_d'] ?? '')) !== '') {
        $issues[] = ['level' => 'warn', 'text' => 'Part 2 punya opsi D'];
    }

    if ($questionText === '' && !availabilityIsAudioOnlyChoicePart($part)) {
        $issues[] = ['level' => 'warn', 'text' => 'Teks pertanyaan kosong'];
    }

    if ($section === 'listening') {
        $audio = availabilityAudioState($row);
        if ($audio['level'] === 'bad') {
            $issues[] = ['level' => 'bad', 'text' => $audio['label']];
        } elseif ($audio['level'] === 'warn') {
            $issues[] = ['level' => 'warn', 'text' => $audio['label']];
        }

        if ($part === '1') {
            $photo = availabilityPhotoState($row);
            if ($photo['level'] === 'bad') {
                $issues[] = ['level' => 'bad', 'text' => $photo['label']];
            } elseif ($photo['level'] === 'warn') {
                $issues[] = ['level' => 'warn', 'text' => $photo['label']];
            }
        }
    }

    if ($section === 'reading') {
        $passage = availabilityPassageState($row);
        if ($passage['level'] === 'bad') {
            $issues[] = ['level' => 'bad', 'text' => $passage['label']];
        }
    }

    return $issues;
}

function availabilityHasIssues(array $row): bool
{
    return !empty(availabilityQuestionIssues($row));
}

function availabilityFetchQuestions(mysqli $conn, string $section, string $part): array
{
    $rows = [];

    if ($section === 'all' || $section === 'listening') {
        $sql = "
            SELECT
                'listening' AS section,
                sl.part,
                sl.nomor_soal,
                sl.id_soal,
                sl.question_type,
                sl.pertanyaan,
                sl.opsi_a,
                sl.opsi_b,
                sl.opsi_c,
                sl.opsi_d,
                sl.jawaban_benar,
                sl.explanation,
                sl.id_audio,
                ta.id_audio IS NOT NULL AS audio_record_found,
                ta.judul AS audio_title,
                ta.file_path AS audio_path,
                ta.transcript,
                ta.context AS audio_context,
                ta.id_photo,
                tp.id_photo IS NOT NULL AS photo_record_found,
                tp.file_path AS photo_path,
                tp.description AS photo_description,
                NULL AS id_teks,
                0 AS text_record_found,
                NULL AS text_title,
                NULL AS text_type,
                NULL AS text_body,
                NULL AS text_body_2,
                NULL AS text_body_3
            FROM toeic_soal_listening sl
            LEFT JOIN toeic_audio ta ON ta.id_audio = sl.id_audio
            LEFT JOIN toeic_photos tp ON tp.id_photo = ta.id_photo
        ";
        $params = [];
        $types = '';
        if ($part !== '') {
            $sql .= ' WHERE sl.part = ?';
            $params[] = $part;
            $types .= 's';
        }
        $sql .= ' ORDER BY CAST(sl.part AS UNSIGNED), sl.nomor_soal ASC, sl.id_soal ASC';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare listening query: ' . $conn->error);
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = array_merge($rows, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }

    if ($section === 'all' || $section === 'reading') {
        $sql = "
            SELECT
                'reading' AS section,
                sr.part,
                sr.nomor_soal,
                sr.id_soal,
                sr.question_type,
                sr.pertanyaan,
                sr.opsi_a,
                sr.opsi_b,
                sr.opsi_c,
                sr.opsi_d,
                sr.jawaban_benar,
                sr.explanation,
                NULL AS id_audio,
                0 AS audio_record_found,
                NULL AS audio_title,
                NULL AS audio_path,
                NULL AS transcript,
                NULL AS audio_context,
                NULL AS id_photo,
                0 AS photo_record_found,
                NULL AS photo_path,
                NULL AS photo_description,
                sr.id_teks,
                tt.id_teks IS NOT NULL AS text_record_found,
                tt.judul AS text_title,
                tt.text_type,
                tt.isi_teks AS text_body,
                tt.isi_teks_2 AS text_body_2,
                tt.isi_teks_3 AS text_body_3
            FROM toeic_soal_reading sr
            LEFT JOIN toeic_teks tt ON tt.id_teks = sr.id_teks
        ";
        $params = [];
        $types = '';
        if ($part !== '') {
            $sql .= ' WHERE sr.part = ?';
            $params[] = $part;
            $types .= 's';
        }
        $sql .= ' ORDER BY CAST(sr.part AS UNSIGNED), sr.nomor_soal ASC, sr.id_soal ASC';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare reading query: ' . $conn->error);
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = array_merge($rows, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }

    usort($rows, function (array $a, array $b): int {
        $sectionOrder = ['listening' => 1, 'reading' => 2];
        $sectionCompare = ($sectionOrder[$a['section']] ?? 99) <=> ($sectionOrder[$b['section']] ?? 99);
        if ($sectionCompare !== 0) {
            return $sectionCompare;
        }
        $partCompare = ((int)$a['part']) <=> ((int)$b['part']);
        if ($partCompare !== 0) {
            return $partCompare;
        }
        $numberCompare = ((int)$a['nomor_soal']) <=> ((int)$b['nomor_soal']);
        if ($numberCompare !== 0) {
            return $numberCompare;
        }
        return ((int)$a['id_soal']) <=> ((int)$b['id_soal']);
    });

    return $rows;
}

function availabilityBuildSummary(array $rows): array
{
    $summary = [];
    foreach (availabilityTargets() as $part => $target) {
        $summary[$part] = [
            'section' => $target['section'],
            'name' => $target['name'],
            'target' => $target['target'],
            'total' => 0,
            'issues' => 0,
            'audio_ok' => 0,
            'audio_required' => in_array($part, ['1', '2', '3', '4'], true),
            'photo_ok' => 0,
            'photo_required' => $part === '1',
            'passage_ok' => 0,
            'passage_required' => in_array($part, ['6', '7'], true),
        ];
    }

    foreach ($rows as $row) {
        $part = (string)$row['part'];
        if (!isset($summary[$part])) {
            continue;
        }

        $summary[$part]['total']++;
        if (availabilityHasIssues($row)) {
            $summary[$part]['issues']++;
        }

        if ($row['section'] === 'listening' && availabilityAudioState($row)['level'] === 'ok') {
            $summary[$part]['audio_ok']++;
        }

        if ($part === '1' && availabilityPhotoState($row)['level'] === 'ok') {
            $summary[$part]['photo_ok']++;
        }

        if (in_array($part, ['6', '7'], true) && availabilityPassageState($row)['level'] === 'ok') {
            $summary[$part]['passage_ok']++;
        }
    }

    return $summary;
}

function availabilityStatusBadge(array $state): string
{
    $level = $state['level'] ?? 'neutral';
    $class = match ($level) {
        'ok' => 'badge-ok',
        'warn' => 'badge-warn',
        'bad' => 'badge-bad',
        default => 'badge-neutral',
    };

    return '<span class="badge ' . $class . '">' . availabilityH($state['label'] ?? '') . '</span>';
}

function availabilityTextPreview($value, int $length): string
{
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}

function availabilityFilterUrl(string $section, string $part, string $mode): string
{
    $params = [];
    if ($section !== 'all') {
        $params['section'] = $section;
    }
    if ($part !== '') {
        $params['part'] = $part;
    }
    if ($mode !== 'all') {
        $params['mode'] = $mode;
    }
    $query = http_build_query($params);
    return $query === '' ? 'toeic_availability_report.php' : 'toeic_availability_report.php?' . $query;
}

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    echo 'Database connection is unavailable.';
    exit();
}

$requiredTables = [
    'toeic_photos',
    'toeic_audio',
    'toeic_teks',
    'toeic_soal_listening',
    'toeic_soal_reading',
];
$missingTables = [];
foreach ($requiredTables as $table) {
    if (!availabilityTableExists($conn, $table)) {
        $missingTables[] = $table;
    }
}

$section = availabilityNormalizeSection($isCli ? ($argv[1] ?? 'all') : ($_GET['section'] ?? 'all'));
$part = availabilityNormalizePart($isCli ? ($argv[2] ?? '') : ($_GET['part'] ?? ''));
$mode = availabilityNormalizeMode($isCli ? ($argv[3] ?? 'all') : ($_GET['mode'] ?? 'all'));

$allRows = empty($missingTables) ? availabilityFetchQuestions($conn, 'all', '') : [];
$summaryRows = availabilityBuildSummary($allRows);
$rows = empty($missingTables) ? availabilityFetchQuestions($conn, $section, $part) : [];
if ($mode === 'issues') {
    $rows = array_values(array_filter($rows, 'availabilityHasIssues'));
}

$totalQuestions = count($allRows);
$totalIssues = count(array_filter($allRows, 'availabilityHasIssues'));
$websiteTitle = function_exists('getWebsiteTitle') ? getWebsiteTitle() : 'TOEIC';

if ($isCli) {
    echo "TOEIC availability report\n";
    echo "Total questions: {$totalQuestions}\n";
    echo "Rows with issues: {$totalIssues}\n";
    foreach ($summaryRows as $partNo => $item) {
        $gap = max(0, (int)$item['target'] - (int)$item['total']);
        echo "Part {$partNo}: total={$item['total']} target={$item['target']} gap={$gap} issues={$item['issues']}\n";
    }
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ketersediaan Soal TOEIC - <?php echo availabilityH($websiteTitle); ?></title>
    <?php echo function_exists('getFaviconHTML') ? getFaviconHTML() : ''; ?>
    <style>
        :root {
            --bg: #f5f7fb;
            --panel: #ffffff;
            --ink: #17202a;
            --muted: #667085;
            --line: #d9e1ec;
            --ok: #0f766e;
            --ok-bg: #dff7f2;
            --warn: #9a5b00;
            --warn-bg: #fff1cf;
            --bad: #b42318;
            --bad-bg: #ffe4e0;
            --neutral: #475467;
            --neutral-bg: #edf2f7;
            --accent: #1d4ed8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.5;
        }

        .shell {
            width: min(1440px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 48px;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.5rem, 3vw, 2.2rem);
            line-height: 1.15;
            letter-spacing: 0;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 1.05rem;
            letter-spacing: 0;
        }

        .muted { color: var(--muted); }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
        }

        .button,
        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--ink);
            padding: 8px 12px;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .button.primary,
        button.primary {
            border-color: var(--accent);
            background: var(--accent);
            color: #fff;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 18px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .metric {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
        }

        .metric .value {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }

        .filters {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        label {
            display: block;
            color: var(--muted);
            font-size: 0.84rem;
            margin-bottom: 5px;
        }

        select,
        input {
            width: 100%;
            min-height: 38px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            padding: 8px 10px;
            font: inherit;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 980px;
        }

        th,
        td {
            border-bottom: 1px solid var(--line);
            padding: 11px 10px;
            text-align: left;
            vertical-align: top;
        }

        th {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0;
            background: #f8fafc;
        }

        tbody tr:hover { background: #f8fbff; }

        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-ok { background: var(--ok-bg); color: var(--ok); }
        .badge-warn { background: var(--warn-bg); color: var(--warn); }
        .badge-bad { background: var(--bad-bg); color: var(--bad); }
        .badge-neutral { background: var(--neutral-bg); color: var(--neutral); }

        .stack {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .path {
            max-width: 260px;
            color: var(--muted);
            font-size: 0.82rem;
            overflow-wrap: anywhere;
        }

        .question {
            max-width: 340px;
            white-space: pre-wrap;
        }

        .thumb {
            width: 120px;
            max-height: 90px;
            object-fit: contain;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
        }

        audio {
            width: 210px;
            max-width: 100%;
            height: 34px;
        }

        .issue-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .empty,
        .error {
            border: 1px dashed var(--line);
            border-radius: 8px;
            padding: 22px;
            color: var(--muted);
            background: #fbfcff;
        }

        .error {
            border-color: #f4ada7;
            color: var(--bad);
            background: #fff8f7;
        }

        @media (max-width: 860px) {
            .shell {
                width: min(100% - 20px, 1440px);
                padding-top: 18px;
            }

            .topbar {
                flex-direction: column;
            }

            .actions {
                justify-content: flex-start;
            }

            .metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filters {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 520px) {
            .metrics {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 14px;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <div class="topbar">
            <div>
                <div class="muted">TOEIC question bank</div>
                <h1>Ketersediaan Soal, Audio, Gambar, dan Passage</h1>
                <div class="muted">
                    Filter: <?php echo availabilityH($section); ?> |
                    Part: <?php echo $part !== '' ? availabilityH($part) : 'semua'; ?> |
                    Mode: <?php echo availabilityH($mode); ?>
                </div>
            </div>
            <div class="actions">
                <a class="button" href="view_all_questions_answers.php">Lihat soal lengkap</a>
                <a class="button" href="../admin/manage_toeic.php">Admin TOEIC</a>
            </div>
        </div>

        <?php if (!empty($missingTables)): ?>
            <div class="error">
                Tabel belum lengkap: <?php echo availabilityH(implode(', ', $missingTables)); ?>
            </div>
        <?php else: ?>
            <section class="metrics" aria-label="Ringkasan ketersediaan">
                <div class="metric">
                    <div class="muted">Total soal</div>
                    <div class="value"><?php echo (int)$totalQuestions; ?></div>
                </div>
                <div class="metric">
                    <div class="muted">Baris perlu dicek</div>
                    <div class="value"><?php echo (int)$totalIssues; ?></div>
                </div>
                <div class="metric">
                    <div class="muted">Audio storage</div>
                    <div class="value" style="font-size:1.25rem;"><?php echo availabilityH(toeicAssetDriver('audio')); ?></div>
                </div>
                <div class="metric">
                    <div class="muted">Photo storage</div>
                    <div class="value" style="font-size:1.25rem;"><?php echo availabilityH(toeicAssetDriver('photo')); ?></div>
                </div>
            </section>

            <section class="panel">
                <h2>Filter</h2>
                <form method="get" class="filters">
                    <div>
                        <label for="section">Section</label>
                        <select id="section" name="section">
                            <option value="all" <?php echo $section === 'all' ? 'selected' : ''; ?>>Semua</option>
                            <option value="listening" <?php echo $section === 'listening' ? 'selected' : ''; ?>>Listening</option>
                            <option value="reading" <?php echo $section === 'reading' ? 'selected' : ''; ?>>Reading</option>
                        </select>
                    </div>
                    <div>
                        <label for="part">Part</label>
                        <input id="part" name="part" value="<?php echo availabilityH($part); ?>" placeholder="1-7 atau kosong">
                    </div>
                    <div>
                        <label for="mode">Mode</label>
                        <select id="mode" name="mode">
                            <option value="all" <?php echo $mode === 'all' ? 'selected' : ''; ?>>Semua soal</option>
                            <option value="issues" <?php echo $mode === 'issues' ? 'selected' : ''; ?>>Hanya yang bermasalah</option>
                        </select>
                    </div>
                    <button class="primary" type="submit">Terapkan</button>
                </form>
            </section>

            <section class="panel">
                <h2>Ringkasan per Part</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Part</th>
                                <th>Section</th>
                                <th>Target</th>
                                <th>Total</th>
                                <th>Gap</th>
                                <th>Issue</th>
                                <th>Audio</th>
                                <th>Gambar</th>
                                <th>Passage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summaryRows as $partNo => $item): ?>
                                <?php
                                $gap = max(0, (int)$item['target'] - (int)$item['total']);
                                $partUrl = availabilityFilterUrl((string)$item['section'], (string)$partNo, $mode);
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo availabilityH($partUrl); ?>">
                                            Part <?php echo availabilityH($partNo); ?>
                                        </a>
                                        <div class="muted"><?php echo availabilityH($item['name']); ?></div>
                                    </td>
                                    <td><?php echo availabilityH($item['section']); ?></td>
                                    <td><?php echo (int)$item['target']; ?></td>
                                    <td><?php echo (int)$item['total']; ?></td>
                                    <td>
                                        <?php echo $gap === 0 ? availabilityStatusBadge(['level' => 'ok', 'label' => 'OK']) : availabilityStatusBadge(['level' => 'warn', 'label' => (string)$gap]); ?>
                                    </td>
                                    <td>
                                        <?php echo (int)$item['issues'] === 0 ? availabilityStatusBadge(['level' => 'ok', 'label' => 'Bersih']) : availabilityStatusBadge(['level' => 'bad', 'label' => (string)$item['issues']]); ?>
                                    </td>
                                    <td>
                                        <?php echo $item['audio_required'] ? ((int)$item['audio_ok'] . '/' . (int)$item['total']) : availabilityStatusBadge(['level' => 'neutral', 'label' => 'Tidak wajib']); ?>
                                    </td>
                                    <td>
                                        <?php echo $item['photo_required'] ? ((int)$item['photo_ok'] . '/' . (int)$item['total']) : availabilityStatusBadge(['level' => 'neutral', 'label' => 'Tidak wajib']); ?>
                                    </td>
                                    <td>
                                        <?php echo $item['passage_required'] ? ((int)$item['passage_ok'] . '/' . (int)$item['total']) : availabilityStatusBadge(['level' => 'neutral', 'label' => 'Tidak wajib']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2>Detail ketersediaan soal</h2>
                <?php if (empty($rows)): ?>
                    <div class="empty">Tidak ada baris untuk filter ini.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Soal</th>
                                    <th>Pertanyaan</th>
                                    <th>Audio</th>
                                    <th>Gambar</th>
                                    <th>Passage</th>
                                    <th>Issue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $audio = availabilityAudioState($row);
                                    $photo = ($row['section'] === 'listening' && (string)$row['part'] === '1')
                                        ? availabilityPhotoState($row)
                                        : ['level' => 'neutral', 'label' => 'Tidak wajib', 'url' => ''];
                                    $passage = availabilityPassageState($row);
                                    $issues = availabilityQuestionIssues($row);
                                    $question = trim((string)$row['pertanyaan']);
                                    if ($question === '' && availabilityIsAudioOnlyChoicePart((string)$row['part'])) {
                                        $question = '(Pertanyaan dibacakan di audio)';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo availabilityH(strtoupper((string)$row['section'])); ?> P<?php echo availabilityH($row['part']); ?> #<?php echo (int)$row['nomor_soal']; ?></strong>
                                            <div class="muted">QID <?php echo (int)$row['id_soal']; ?></div>
                                            <div class="muted">Jawaban: <?php echo availabilityH(strtoupper(trim((string)$row['jawaban_benar']))); ?></div>
                                        </td>
                                        <td>
                                            <div class="question"><?php echo availabilityH(availabilityTextPreview($question, 300)); ?></div>
                                            <?php if (!empty($row['question_type'])): ?>
                                                <div class="muted"><?php echo availabilityH($row['question_type']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['section'] === 'listening'): ?>
                                                <div class="stack">
                                                    <?php echo availabilityStatusBadge($audio); ?>
                                                    <?php if (!empty($audio['url']) && $audio['level'] !== 'bad'): ?>
                                                        <audio controls preload="none">
                                                            <source src="<?php echo availabilityH($audio['url']); ?>">
                                                        </audio>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['audio_title'])): ?>
                                                        <div><?php echo availabilityH($row['audio_title']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['audio_path'])): ?>
                                                        <div class="path"><?php echo availabilityH($row['audio_path']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <?php echo availabilityStatusBadge(['level' => 'neutral', 'label' => 'Tidak wajib']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['section'] === 'listening'): ?>
                                                <div class="stack">
                                                    <?php echo availabilityStatusBadge($photo); ?>
                                                    <?php if (!empty($photo['url']) && $photo['level'] !== 'bad'): ?>
                                                        <img class="thumb" src="<?php echo availabilityH($photo['url']); ?>" alt="Preview gambar TOEIC">
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['photo_path'])): ?>
                                                        <div class="path"><?php echo availabilityH($row['photo_path']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['photo_description'])): ?>
                                                        <div class="muted"><?php echo availabilityH(availabilityTextPreview((string)$row['photo_description'], 120)); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <?php echo availabilityStatusBadge(['level' => 'neutral', 'label' => 'Tidak wajib']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="stack">
                                                <?php echo availabilityStatusBadge($passage); ?>
                                                <?php if (!empty($row['text_title'])): ?>
                                                    <div><?php echo availabilityH($row['text_title']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($passage['preview'])): ?>
                                                    <div class="path"><?php echo availabilityH($passage['preview']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (empty($issues)): ?>
                                                <?php echo availabilityStatusBadge(['level' => 'ok', 'label' => 'OK']); ?>
                                            <?php else: ?>
                                                <div class="issue-list">
                                                    <?php foreach ($issues as $issue): ?>
                                                        <?php echo availabilityStatusBadge(['level' => $issue['level'], 'label' => $issue['text']]); ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
