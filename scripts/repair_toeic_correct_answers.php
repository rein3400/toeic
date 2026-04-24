<?php
/**
 * Normalize TOEIC correct-answer fields that were imported as option text.
 *
 * Usage (web):
 *   /scripts/repair_toeic_correct_answers.php
 *
 * Usage (CLI):
 *   php scripts/repair_toeic_correct_answers.php
 *   php scripts/repair_toeic_correct_answers.php --apply
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/../includes/session_handler.php';
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../admin/login.php");
        exit();
    }
    require_once __DIR__ . '/../includes/csrf_helper.php';
}

require_once __DIR__ . '/../includes/config.php';

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    echo "Database connection is unavailable.";
    exit();
}

function repairExpectedLetters(string $part): array
{
    return $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
}

function repairOptions(array $row): array
{
    $options = [];
    foreach (['A', 'B', 'C', 'D'] as $letter) {
        $value = trim((string)($row['opsi_' . strtolower($letter)] ?? ''));
        if ($value !== '') {
            $options[$letter] = $value;
        }
    }
    return $options;
}

function repairComparableAnswer(string $value): string
{
    $value = preg_replace('/^\s*[A-D]\s*[\.\):\-]\s*/i', '', trim($value)) ?? trim($value);
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function repairNormalizeCorrectAnswer($raw, array $options, array $expectedLetters): string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\s*([A-D])(?:\s*[\.\):\-]|\s*$)/i', $value, $m)) {
        $letter = strtoupper($m[1]);
        return in_array($letter, $expectedLetters, true) ? $letter : '';
    }

    $needle = repairComparableAnswer($value);
    foreach ($options as $letter => $text) {
        $letter = strtoupper((string)$letter);
        if (!in_array($letter, $expectedLetters, true)) {
            continue;
        }
        $optionNeedle = repairComparableAnswer((string)$text);
        if ($needle === $optionNeedle) {
            return $letter;
        }
        if (strlen($needle) >= 8 && strpos($optionNeedle, $needle) === 0) {
            return $letter;
        }
    }

    return '';
}

function fetchToeicAnswerRows(mysqli $conn): array
{
    $sql = "
        SELECT 'listening' AS section_name, part, nomor_soal, id_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar
        FROM toeic_soal_listening
        UNION ALL
        SELECT 'reading' AS section_name, part, nomor_soal, id_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar
        FROM toeic_soal_reading
        ORDER BY section_name, CAST(part AS UNSIGNED), nomor_soal, id_soal
    ";
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException($conn->error);
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function buildToeicAnswerRepairPlan(array $rows): array
{
    $repairable = [];
    $unresolved = [];
    $validCount = 0;

    foreach ($rows as $row) {
        $part = (string)$row['part'];
        $expectedLetters = repairExpectedLetters($part);
        $current = strtoupper(trim((string)($row['jawaban_benar'] ?? '')));
        if (in_array($current, $expectedLetters, true)) {
            $validCount++;
            continue;
        }

        $normalized = repairNormalizeCorrectAnswer($row['jawaban_benar'] ?? '', repairOptions($row), $expectedLetters);
        $entry = [
            'section' => (string)$row['section_name'],
            'part' => $part,
            'number' => (int)$row['nomor_soal'],
            'id' => (int)$row['id_soal'],
            'question' => trim((string)$row['pertanyaan']),
            'current' => trim((string)($row['jawaban_benar'] ?? '')),
            'normalized' => $normalized,
        ];

        if ($normalized !== '') {
            $repairable[] = $entry;
        } else {
            $unresolved[] = $entry;
        }
    }

    return [
        'total' => count($rows),
        'valid' => $validCount,
        'repairable' => $repairable,
        'unresolved' => $unresolved,
    ];
}

function applyToeicAnswerRepairs(mysqli $conn, array $repairable): int
{
    if (empty($repairable)) {
        return 0;
    }

    $listeningStmt = $conn->prepare("UPDATE toeic_soal_listening SET jawaban_benar = ? WHERE id_soal = ?");
    $readingStmt = $conn->prepare("UPDATE toeic_soal_reading SET jawaban_benar = ? WHERE id_soal = ?");
    if (!$listeningStmt || !$readingStmt) {
        throw new RuntimeException($conn->error);
    }

    $answer = '';
    $id = 0;
    $listeningStmt->bind_param("si", $answer, $id);
    $readingStmt->bind_param("si", $answer, $id);

    $updated = 0;
    $conn->begin_transaction();
    try {
        foreach ($repairable as $entry) {
            $answer = $entry['normalized'];
            $id = (int)$entry['id'];
            $stmt = $entry['section'] === 'listening' ? $listeningStmt : $readingStmt;
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }
            $updated += max(0, $stmt->affected_rows);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    } finally {
        $listeningStmt->close();
        $readingStmt->close();
    }

    return $updated;
}

function shortPreview(string $value, int $limit = 100): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') . '...' : $value;
    }
    return strlen($value) > $limit ? substr($value, 0, $limit) . '...' : $value;
}

$error = null;
$applied = false;
$updated = 0;

try {
    $plan = buildToeicAnswerRepairPlan(fetchToeicAnswerRows($conn));

    $applyRequested = $isCli
        ? in_array('--apply', $argv ?? [], true)
        : ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['apply'] ?? '') === '1');

    if ($applyRequested) {
        if (!$isCli && !validateCsrfToken()) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $updated = applyToeicAnswerRepairs($conn, $plan['repairable']);
        $applied = true;
        $plan = buildToeicAnswerRepairPlan(fetchToeicAnswerRows($conn));
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $plan = $plan ?? ['total' => 0, 'valid' => 0, 'repairable' => [], 'unresolved' => []];
}

if ($isCli) {
    echo json_encode([
        'applied' => $applied,
        'updated' => $updated,
        'total' => $plan['total'],
        'valid' => $plan['valid'],
        'repairable' => count($plan['repairable']),
        'unresolved' => count($plan['unresolved']),
        'error' => $error,
        'repairable_rows' => $plan['repairable'],
        'unresolved_rows' => $plan['unresolved'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit($error === null ? 0 : 1);
}

$csrfToken = generateCsrfToken();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TOEIC Correct Answer Repair</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">TOEIC Correct Answer Repair</h1>
            <p class="text-muted mb-0">Preview dan perbaiki jawaban benar yang tersimpan sebagai teks opsi.</p>
        </div>
        <a href="view_all_questions_answers.php" class="btn btn-outline-secondary">Kembali ke Inspector</a>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($applied): ?>
        <div class="alert alert-success">Updated <?php echo (int)$updated; ?> TOEIC answer rows.</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="border rounded bg-white p-3"><div class="text-muted">Total</div><strong><?php echo (int)$plan['total']; ?></strong></div></div>
        <div class="col-md-3"><div class="border rounded bg-white p-3"><div class="text-muted">Valid</div><strong><?php echo (int)$plan['valid']; ?></strong></div></div>
        <div class="col-md-3"><div class="border rounded bg-white p-3"><div class="text-muted">Repairable</div><strong><?php echo count($plan['repairable']); ?></strong></div></div>
        <div class="col-md-3"><div class="border rounded bg-white p-3"><div class="text-muted">Unresolved</div><strong><?php echo count($plan['unresolved']); ?></strong></div></div>
    </div>

    <?php if (!$applied && count($plan['repairable']) > 0): ?>
        <form method="post" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="apply" value="1">
            <button class="btn btn-primary" type="submit">Apply <?php echo count($plan['repairable']); ?> Repairs</button>
        </form>
    <?php endif; ?>

    <h2 class="h5">Repairable Rows</h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-striped align-middle bg-white">
            <thead>
                <tr>
                    <th>Section</th>
                    <th>Part</th>
                    <th>No.</th>
                    <th>ID</th>
                    <th>Current</th>
                    <th>New</th>
                    <th>Question</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plan['repairable'] as $entry): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($entry['section']); ?></td>
                        <td><?php echo htmlspecialchars($entry['part']); ?></td>
                        <td><?php echo (int)$entry['number']; ?></td>
                        <td><?php echo (int)$entry['id']; ?></td>
                        <td><code><?php echo htmlspecialchars($entry['current']); ?></code></td>
                        <td><code><?php echo htmlspecialchars($entry['normalized']); ?></code></td>
                        <td><?php echo htmlspecialchars(shortPreview($entry['question'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($plan['repairable'])): ?>
                    <tr><td colspan="7" class="text-muted">No repairable rows found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($plan['unresolved'])): ?>
        <h2 class="h5">Unresolved Rows</h2>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle bg-white">
                <thead>
                    <tr>
                        <th>Section</th>
                        <th>Part</th>
                        <th>No.</th>
                        <th>ID</th>
                        <th>Current</th>
                        <th>Question</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plan['unresolved'] as $entry): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($entry['section']); ?></td>
                            <td><?php echo htmlspecialchars($entry['part']); ?></td>
                            <td><?php echo (int)$entry['number']; ?></td>
                            <td><?php echo (int)$entry['id']; ?></td>
                            <td><code><?php echo htmlspecialchars($entry['current']); ?></code></td>
                            <td><?php echo htmlspecialchars(shortPreview($entry['question'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
