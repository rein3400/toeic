<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_sw_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
ensureToeicSwSchema($conn);

function toeicSwBankH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function toeicSwBankExcerpt($value, int $limit = 220): string {
    $text = trim(preg_replace('/\s+/', ' ', (string)$value));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 3) . '...' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function toeicSwBankTaskMetadata(): array {
    $byTableQuestion = [];
    $types = [];
    $tables = [];

    foreach (getToeicSwTaskBlueprint() as $section => $tasks) {
        foreach ($tasks as $questionNumber => $task) {
            $type = (string)($task['type'] ?? '');
            $table = getToeicSwContentTableForType($type);
            if (!$table) {
                continue;
            }
            if (!in_array($table, $tables, true)) {
                $tables[] = $table;
            }
            $label = (string)($task['label'] ?? $type);
            $byTableQuestion[$table][(int)$questionNumber] = [
                'section' => $section,
                'type' => $type,
                'label' => $label,
                'part' => (string)($task['part'] ?? ''),
            ];
            $types[$type] = $label;
        }
    }

    asort($types);
    return [
        'by_table_question' => $byTableQuestion,
        'types' => $types,
        'tables' => $tables,
    ];
}

function toeicSwBankFallbackTypeForTable(string $table): string {
    $map = [
        'toeic_sw_read_aloud' => 'read_text_aloud',
        'toeic_sw_describe_picture' => 'describe_picture',
        'toeic_sw_respond_questions' => 'respond_to_questions',
        'toeic_sw_respond_information' => 'respond_using_information',
        'toeic_sw_express_opinion' => 'express_opinion',
        'toeic_sw_picture_sentence' => 'write_sentence_based_on_picture',
        'toeic_sw_written_request' => 'respond_to_written_request',
        'toeic_sw_opinion_essay' => 'write_opinion_essay',
    ];
    return $map[$table] ?? $table;
}

function toeicSwBankFallbackSectionForTable(string $table): string {
    return in_array($table, ['toeic_sw_picture_sentence', 'toeic_sw_written_request', 'toeic_sw_opinion_essay'], true)
        ? 'writing'
        : 'speaking';
}

function toeicSwBankFetchContentRows(mysqli $conn, int $packageFilter, string $sectionFilter, string $typeFilter): array {
    $metadata = toeicSwBankTaskMetadata();
    $rows = [];

    foreach ($metadata['tables'] as $table) {
        $sql = "SELECT * FROM {$table}";
        if ($packageFilter > 0) {
            $sql .= " WHERE package_number = " . $packageFilter;
        }
        $sql .= " ORDER BY package_number ASC, question_number ASC, id ASC";

        $result = $conn->query($sql);
        if (!$result) {
            continue;
        }

        while ($row = $result->fetch_assoc()) {
            $questionNumber = (int)($row['question_number'] ?? 0);
            $task = $metadata['by_table_question'][$table][$questionNumber] ?? [
                'section' => toeicSwBankFallbackSectionForTable($table),
                'type' => toeicSwBankFallbackTypeForTable($table),
                'label' => toeicSwBankFallbackTypeForTable($table),
                'part' => '',
            ];

            if ($sectionFilter !== 'all' && $task['section'] !== $sectionFilter) {
                continue;
            }
            if ($typeFilter !== 'all' && $task['type'] !== $typeFilter) {
                continue;
            }

            $rows[] = [
                'table' => $table,
                'id' => (int)($row['id'] ?? 0),
                'package_number' => (int)($row['package_number'] ?? 0),
                'question_number' => $questionNumber,
                'section' => $task['section'],
                'type' => $task['type'],
                'label' => $task['label'],
                'part' => $task['part'],
                'title' => (string)($row['title'] ?? ''),
                'prompt_text' => (string)($row['prompt_text'] ?? ''),
                'sample_response' => (string)($row['sample_response'] ?? ''),
                'scoring_rubric' => (string)($row['scoring_rubric'] ?? ''),
                'difficulty' => (string)($row['difficulty'] ?? ''),
                'cefr_level' => (string)($row['cefr_level'] ?? ''),
                'audio_path' => (string)($row['audio_path'] ?? ''),
                'audio_transcript' => (string)($row['audio_transcript'] ?? ''),
                'image_path' => (string)($row['image_path'] ?? ''),
                'information_card' => (string)($row['information_card'] ?? ''),
                'stimulus_group_id' => (string)($row['stimulus_group_id'] ?? ''),
                'repeat_question' => (int)($row['repeat_question'] ?? 0),
                'required_words_json' => (string)($row['required_words_json'] ?? ''),
                'recipient_type' => (string)($row['recipient_type'] ?? ''),
                'word_limit_min' => (int)($row['word_limit_min'] ?? 0),
                'word_limit_max' => (int)($row['word_limit_max'] ?? 0),
                'minimum_words' => (int)($row['minimum_words'] ?? 0),
            ];
        }
    }

    usort($rows, function (array $a, array $b): int {
        $sectionOrder = ['speaking' => 1, 'writing' => 2];
        return [$a['package_number'], $sectionOrder[$a['section']] ?? 99, $a['question_number'], $a['id']]
            <=> [$b['package_number'], $sectionOrder[$b['section']] ?? 99, $b['question_number'], $b['id']];
    });

    return $rows;
}

function toeicSwBankRequiredWords(string $json): array {
    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), 'strlen')) : [];
}

function toeicSwBankExtraNotes(array $row): array {
    $notes = [];
    if ($row['type'] === 'respond_using_information') {
        if ($row['stimulus_group_id'] !== '') {
            $notes[] = 'Group: ' . $row['stimulus_group_id'];
        }
        if ($row['repeat_question']) {
            $notes[] = 'Question repeats';
        }
    }
    if ($row['type'] === 'write_sentence_based_on_picture') {
        $words = toeicSwBankRequiredWords($row['required_words_json']);
        if (!empty($words)) {
            $notes[] = 'Required words: ' . implode(', ', $words);
        }
    }
    if ($row['type'] === 'respond_to_written_request') {
        $range = trim((string)$row['word_limit_min'] . '-' . (string)$row['word_limit_max'], '-');
        $notes[] = 'Recipient: ' . ($row['recipient_type'] !== '' ? $row['recipient_type'] : 'request');
        if ($range !== '0-0') {
            $notes[] = 'Words: ' . $range;
        }
    }
    if ($row['type'] === 'write_opinion_essay' && $row['minimum_words'] > 0) {
        $notes[] = 'Minimum words: ' . $row['minimum_words'];
    }
    return $notes;
}

function toeicSwBankSummarize(array $rows): array {
    $summary = [
        'total' => count($rows),
        'speaking' => 0,
        'writing' => 0,
        'audio' => 0,
        'images' => 0,
        'c2' => 0,
    ];

    foreach ($rows as $row) {
        if ($row['section'] === 'speaking') {
            $summary['speaking']++;
        }
        if ($row['section'] === 'writing') {
            $summary['writing']++;
        }
        if ($row['audio_path'] !== '') {
            $summary['audio']++;
        }
        if ($row['image_path'] !== '') {
            $summary['images']++;
        }
        if ($row['difficulty'] === 'C2' && $row['cefr_level'] === 'C2') {
            $summary['c2']++;
        }
    }

    return $summary;
}

$requirements = getToeicSwPackageRequirements();
$readiness = getToeicSwContentReadiness($conn);
$metadata = toeicSwBankTaskMetadata();

$package_filter = (int)($_GET['package'] ?? 0);
if ($package_filter < 0 || $package_filter > (int)$requirements['packages']) {
    $package_filter = 0;
}

$section_filter = strtolower(trim((string)($_GET['section'] ?? 'all')));
if (!in_array($section_filter, ['all', 'speaking', 'writing'], true)) {
    $section_filter = 'all';
}

$type_filter = trim((string)($_GET['type'] ?? 'all'));
if ($type_filter !== 'all' && !isset($metadata['types'][$type_filter])) {
    $type_filter = 'all';
}

$bank_rows = toeicSwBankFetchContentRows($conn, $package_filter, $section_filter, $type_filter);
$summary = toeicSwBankSummarize($bank_rows);
$ready_count = 0;
foreach ($readiness['packages'] as $package) {
    if (!empty($package['ready'])) {
        $ready_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC SW Bank - <?php echo toeicSwBankH($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .bank-panel {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .metric-panel {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 1rem;
            min-height: 104px;
        }
        .sw-preview-image {
            width: 118px;
            height: 78px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.06);
        }
        .sw-path {
            max-width: 360px;
            overflow-wrap: anywhere;
            font-size: 0.78rem;
            color: var(--text-muted);
        }
        .sw-prompt {
            max-width: 560px;
            white-space: normal;
        }
        .sw-table audio {
            max-width: 230px;
        }
        .package-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(178px, 1fr));
            gap: 0.75rem;
        }
        .package-chip {
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 0.8rem;
            background: rgba(255,255,255,0.035);
        }
        .package-chip.ready {
            border-color: rgba(16,185,129,0.45);
        }
        .package-chip.missing {
            border-color: rgba(245,158,11,0.45);
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div>
                        <div class="small text-uppercase text-muted fw-semibold">TOEIC Speaking &amp; Writing</div>
                        <h1 class="fw-bold mb-1">TOEIC SW Bank</h1>
                        <p class="text-muted mb-0">Inspect imported SW packages, prompts, audio, images, and C2 readiness.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="import_toeic_sw_packages.php" class="btn btn-primary">
                            <i class="fas fa-file-import me-2"></i>Import Packages
                        </a>
                        <a href="toeic_sw_results.php" class="btn btn-outline-light">
                            <i class="fas fa-clipboard-check me-2"></i>SW Results
                        </a>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-2 col-md-4"><div class="metric-panel"><div class="small text-muted mb-2">Filtered Items</div><div class="h2 fw-bold mb-0"><?php echo (int)$summary['total']; ?></div></div></div>
                    <div class="col-lg-2 col-md-4"><div class="metric-panel"><div class="small text-muted mb-2">Speaking</div><div class="h2 fw-bold mb-0"><?php echo (int)$summary['speaking']; ?></div></div></div>
                    <div class="col-lg-2 col-md-4"><div class="metric-panel"><div class="small text-muted mb-2">Writing</div><div class="h2 fw-bold mb-0"><?php echo (int)$summary['writing']; ?></div></div></div>
                    <div class="col-lg-2 col-md-4"><div class="metric-panel"><div class="small text-muted mb-2">Prompt Audio</div><div class="h2 fw-bold mb-0"><?php echo (int)$summary['audio']; ?></div></div></div>
                    <div class="col-lg-2 col-md-4"><div class="metric-panel"><div class="small text-muted mb-2">Images</div><div class="h2 fw-bold mb-0"><?php echo (int)$summary['images']; ?></div></div></div>
                    <div class="col-lg-2 col-md-4"><div class="metric-panel"><div class="small text-muted mb-2">Ready Packages</div><div class="h2 fw-bold mb-0"><?php echo $ready_count; ?>/<?php echo (int)$requirements['packages']; ?></div></div></div>
                </div>

                <div class="bank-panel">
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Package</label>
                            <select name="package" class="form-select">
                                <option value="0">All packages</option>
                                <?php for ($package = 1; $package <= (int)$requirements['packages']; $package++): ?>
                                    <option value="<?php echo $package; ?>" <?php echo $package_filter === $package ? 'selected' : ''; ?>>Package <?php echo str_pad((string)$package, 2, '0', STR_PAD_LEFT); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Section</label>
                            <select name="section" class="form-select">
                                <option value="all" <?php echo $section_filter === 'all' ? 'selected' : ''; ?>>All sections</option>
                                <option value="speaking" <?php echo $section_filter === 'speaking' ? 'selected' : ''; ?>>Speaking</option>
                                <option value="writing" <?php echo $section_filter === 'writing' ? 'selected' : ''; ?>>Writing</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Task Type</label>
                            <select name="type" class="form-select">
                                <option value="all">All task types</option>
                                <?php foreach ($metadata['types'] as $type => $label): ?>
                                    <option value="<?php echo toeicSwBankH($type); ?>" <?php echo $type_filter === $type ? 'selected' : ''; ?>>
                                        <?php echo toeicSwBankH($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-outline-light">Apply</button>
                        </div>
                    </form>
                </div>

                <div class="bank-panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                        <div>
                            <h2 class="h5 fw-bold mb-1">Package Readiness</h2>
                            <div class="small text-muted">Each ready package needs 11 Speaking, 8 Writing, 7 images, and 7 speaking prompt audio transcripts.</div>
                        </div>
                        <span class="badge <?php echo !empty($readiness['ready']) ? 'bg-success' : 'bg-warning text-dark'; ?>">
                            <?php echo !empty($readiness['ready']) ? 'All Ready' : 'Incomplete'; ?>
                        </span>
                    </div>
                    <div class="package-grid">
                        <?php foreach ($readiness['packages'] as $package => $item): ?>
                            <div class="package-chip <?php echo !empty($item['ready']) ? 'ready' : 'missing'; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>Package <?php echo str_pad((string)$package, 2, '0', STR_PAD_LEFT); ?></strong>
                                    <span class="badge <?php echo !empty($item['ready']) ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                        <?php echo !empty($item['ready']) ? 'Ready' : 'Check'; ?>
                                    </span>
                                </div>
                                <div class="small text-muted">S <?php echo (int)$item['speaking']; ?>/11, W <?php echo (int)$item['writing']; ?>/8</div>
                                <div class="small text-muted">Audio <?php echo (int)$item['speaking_audio']; ?>/7, transcript <?php echo (int)$item['speaking_audio_transcripts']; ?>/7</div>
                                <div class="small text-muted">Images <?php echo (int)$item['images']; ?>/7</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bank-panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                        <div>
                            <h2 class="h5 fw-bold mb-1">Question Bank</h2>
                            <div class="small text-muted">Read-only view of the imported TOEIC SW content tables.</div>
                        </div>
                        <div class="small text-muted"><?php echo (int)$summary['c2']; ?> C2/C2 rows in current filter</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle sw-table">
                            <thead>
                                <tr>
                                    <th>Package / Task</th>
                                    <th>Prompt</th>
                                    <th>Media</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bank_rows)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No TOEIC SW bank rows found for this filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bank_rows as $row): ?>
                                        <?php
                                            $audio_url = toeicSwMediaUrl($row['audio_path']);
                                            $image_url = toeicSwMediaUrl($row['image_path']);
                                            $extra_notes = toeicSwBankExtraNotes($row);
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">Package <?php echo str_pad((string)$row['package_number'], 2, '0', STR_PAD_LEFT); ?> / Q<?php echo (int)$row['question_number']; ?></div>
                                                <div class="small text-muted"><?php echo toeicSwBankH(ucfirst($row['section'])); ?> / <?php echo toeicSwBankH($row['part'] ?: 'SW'); ?></div>
                                                <div class="small mt-1"><?php echo toeicSwBankH($row['label']); ?></div>
                                                <span class="badge bg-secondary mt-2"><?php echo toeicSwBankH($row['difficulty']); ?> / <?php echo toeicSwBankH($row['cefr_level']); ?></span>
                                            </td>
                                            <td class="sw-prompt">
                                                <div class="fw-semibold mb-1"><?php echo toeicSwBankH($row['title'] !== '' ? $row['title'] : $row['label']); ?></div>
                                                <?php if ($row['prompt_text'] !== ''): ?>
                                                    <div class="small"><?php echo toeicSwBankH(toeicSwBankExcerpt($row['prompt_text'])); ?></div>
                                                <?php endif; ?>
                                                <?php if ($row['information_card'] !== ''): ?>
                                                    <div class="small text-muted mt-2"><?php echo toeicSwBankH(toeicSwBankExcerpt($row['information_card'], 160)); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($image_url !== ''): ?>
                                                    <div class="mb-2"><img src="<?php echo toeicSwBankH($image_url); ?>" class="sw-preview-image" alt="TOEIC SW prompt image" loading="lazy"></div>
                                                    <div class="sw-path mb-2"><?php echo toeicSwBankH($row['image_path']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($audio_url !== ''): ?>
                                                    <audio controls preload="none" src="<?php echo toeicSwBankH($audio_url); ?>"></audio>
                                                    <div class="sw-path mt-2"><?php echo toeicSwBankH($row['audio_path']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($image_url === '' && $audio_url === ''): ?>
                                                    <span class="text-muted small">No prompt media</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small text-muted mb-2"><?php echo toeicSwBankH($row['table']); ?> #<?php echo (int)$row['id']; ?></div>
                                                <?php foreach ($extra_notes as $note): ?>
                                                    <div class="small"><?php echo toeicSwBankH($note); ?></div>
                                                <?php endforeach; ?>
                                                <?php if ($row['audio_transcript'] !== ''): ?>
                                                    <details class="small mt-2">
                                                        <summary>Audio transcript</summary>
                                                        <div class="mt-1"><?php echo nl2br(toeicSwBankH(toeicSwBankExcerpt($row['audio_transcript'], 280))); ?></div>
                                                    </details>
                                                <?php endif; ?>
                                                <?php if ($row['sample_response'] !== ''): ?>
                                                    <details class="small mt-2">
                                                        <summary>Sample response</summary>
                                                        <div class="mt-1"><?php echo nl2br(toeicSwBankH(toeicSwBankExcerpt($row['sample_response'], 280))); ?></div>
                                                    </details>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
