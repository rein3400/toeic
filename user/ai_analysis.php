<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/ai_helper.php';
require_once '../includes/toeic_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
$website_title = getWebsiteTitle();
$test_session = $_GET['session'] ?? null;
$format = $_GET['format'] ?? 'toeic';

if (!$test_session || $format !== 'toeic') {
    header("Location: index.php");
    exit();
}

$stmt = $conn->prepare("SELECT tr.*, u.full_name FROM toeic_test_results tr JOIN users u ON tr.user_id = u.id_user WHERE tr.test_session = ?");
$stmt->bind_param("s", $test_session);
$stmt->execute();
$test_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test_data) {
    header("Location: index.php");
    exit();
}

if (!$is_admin && (int)$test_data['user_id'] !== $user_id) {
    header("Location: index.php?error=access_denied");
    exit();
}

$conn->query("
    CREATE TABLE IF NOT EXISTS ai_analysis_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        test_session VARCHAR(100) NOT NULL,
        test_format VARCHAR(30) NOT NULL DEFAULT 'toeic',
        analysis_content LONGTEXT NOT NULL,
        ai_provider VARCHAR(50),
        ai_model VARCHAR(100),
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_session (user_id, test_session)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$part_stats = getTOEICPartStatistics((int)$test_data['user_id'], $test_session);
$analysis = null;
$analysis_error = null;
$ai_not_configured = false;

$stmt = $conn->prepare("SELECT * FROM ai_analysis_cache WHERE user_id = ? AND test_session = ?");
$stmt->bind_param("is", $test_data['user_id'], $test_session);
$stmt->execute();
$cached = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($cached) {
    $analysis = json_decode($cached['analysis_content'], true);
} elseif (isset($_GET['generate']) && $_GET['generate'] === '1') {
    $config = getActiveAIProvider();
    if (!$config) {
        $ai_not_configured = true;
    } else {
        try {
            $prompt = buildToeicAnalysisPrompt($test_data, $part_stats);
            $response = callAI($prompt, $config, 2500, [], 150000);
            $analysis = parseToeicAnalysisResponse($response);

            if ($analysis) {
                $analysis_json = json_encode($analysis, JSON_UNESCAPED_UNICODE);
                $provider = $config['provider'] ?? '';
                $model = $config['llm'] ?? '';
                $stmt = $conn->prepare("
                    INSERT INTO ai_analysis_cache (user_id, test_session, test_format, analysis_content, ai_provider, ai_model)
                    VALUES (?, ?, 'toeic', ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        analysis_content = VALUES(analysis_content),
                        ai_provider = VALUES(ai_provider),
                        ai_model = VALUES(ai_model),
                        generated_at = CURRENT_TIMESTAMP
                ");
                $stmt->bind_param("issss", $test_data['user_id'], $test_session, $analysis_json, $provider, $model);
                $stmt->execute();
                $stmt->close();
                header("Location: ai_analysis.php?format=toeic&session=" . urlencode($test_session));
                exit();
            }
        } catch (Throwable $e) {
            $analysis_error = $e->getMessage();
        }
    }
}

function buildToeicAnalysisPrompt(array $data, array $part_stats): string {
    $breakdown = [];
    foreach ($part_stats as $key => $stat) {
        $breakdown[] = "- {$stat['name']}: {$stat['correct']}/{$stat['total']} ({$stat['percentage']}%)";
    }

    $breakdownText = implode("\n", $breakdown);
    $name = $data['full_name'] ?? 'Siswa';

    return <<<PROMPT
Kamu adalah coach TOEIC Listening & Reading.

Data siswa:
- Nama: {$name}
- Listening scaled: {$data['listening_scaled']}
- Reading scaled: {$data['reading_scaled']}
- Total score: {$data['total_score']}
- CEFR level: {$data['cefr_level']}

Part breakdown:
{$breakdownText}

Berikan analisis TOEIC dalam JSON valid saja:
{
  "summary": "2-3 kalimat ringkas",
  "strengths": ["kekuatan 1", "kekuatan 2"],
  "weaknesses": ["kelemahan 1", "kelemahan 2"],
  "recommended_focus": [
    {"part": "Part 3", "reason": "alasan fokus", "action": "langkah latihan"}
  ],
  "study_plan": ["langkah 1", "langkah 2", "langkah 3"]
}
PROMPT;
}

function parseToeicAnalysisResponse(string $response): ?array {
    $response = trim($response);
    $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
    $response = preg_replace('/\s*```\s*$/m', '', $response);
    $decoded = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }
    if (preg_match('/\{.*\}/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC AI Analysis - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="css/mobile-responsive.css" rel="stylesheet">
    <style>
        body { color: #10233d; }
        .shell { max-width: 1080px; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .panel { border-radius: 24px; padding: 1.5rem; }
        .row-item { padding: 0.85rem 0; border-bottom: 1px solid #eef3f8; }
        .row-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <div class="small text-uppercase text-muted fw-semibold">TOEIC AI Analysis</div>
                <h1 class="h3 fw-bold mb-0">Analisis belajar berbasis hasil TOEIC</h1>
            </div>
            <div class="d-flex gap-2">
                <a href="result_toeic.php?session=<?php echo urlencode($test_session); ?>" class="btn btn-outline-secondary rounded-pill px-4">Kembali ke Result</a>
                <?php if (!$analysis): ?>
                    <a href="ai_analysis.php?format=toeic&session=<?php echo urlencode($test_session); ?>&generate=1" class="btn btn-warning rounded-pill px-4">Generate Analysis</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="panel toeic-panel h-100">
                    <h2 class="h5 fw-bold mb-3">Score Snapshot</h2>
                    <div class="row-item d-flex justify-content-between"><span>Listening</span><strong><?php echo (int)$test_data['listening_scaled']; ?></strong></div>
                    <div class="row-item d-flex justify-content-between"><span>Reading</span><strong><?php echo (int)$test_data['reading_scaled']; ?></strong></div>
                    <div class="row-item d-flex justify-content-between"><span>Total</span><strong><?php echo (int)$test_data['total_score']; ?></strong></div>
                    <div class="row-item d-flex justify-content-between"><span>CEFR</span><strong><?php echo htmlspecialchars($test_data['cefr_level']); ?></strong></div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="panel toeic-panel h-100">
                    <h2 class="h5 fw-bold mb-3">Part Breakdown</h2>
                    <?php foreach ($part_stats as $stat): ?>
                        <div class="row-item d-flex justify-content-between">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                <div class="small text-muted"><?php echo (int)$stat['correct']; ?> / <?php echo (int)$stat['total']; ?> benar</div>
                            </div>
                            <strong><?php echo (int)$stat['percentage']; ?>%</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="panel toeic-panel mt-4">
            <h2 class="h5 fw-bold mb-3">AI Feedback</h2>
            <?php if ($ai_not_configured): ?>
                <div class="alert alert-warning mb-0">AI provider belum dikonfigurasi.</div>
            <?php elseif ($analysis_error): ?>
                <div class="alert alert-danger mb-0"><?php echo htmlspecialchars($analysis_error); ?></div>
            <?php elseif (!$analysis): ?>
                <p class="text-muted mb-0">Analisis belum dibuat. Jalankan generate untuk membuat feedback TOEIC dari hasil ini.</p>
            <?php else: ?>
                <p class="mb-3"><?php echo htmlspecialchars($analysis['summary'] ?? ''); ?></p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <h3 class="h6 fw-bold">Strengths</h3>
                        <ul class="mb-0">
                            <?php foreach (($analysis['strengths'] ?? []) as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h3 class="h6 fw-bold">Weaknesses</h3>
                        <ul class="mb-0">
                            <?php foreach (($analysis['weaknesses'] ?? []) as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <?php if (!empty($analysis['recommended_focus'])): ?>
                    <div class="mt-4">
                        <h3 class="h6 fw-bold">Recommended Focus</h3>
                        <?php foreach ($analysis['recommended_focus'] as $focus): ?>
                            <div class="row-item">
                                <div class="fw-semibold"><?php echo htmlspecialchars($focus['part'] ?? 'Part'); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($focus['reason'] ?? ''); ?></div>
                                <div><?php echo htmlspecialchars($focus['action'] ?? ''); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($analysis['study_plan'])): ?>
                    <div class="mt-4">
                        <h3 class="h6 fw-bold">Study Plan</h3>
                        <ol class="mb-0">
                            <?php foreach ($analysis['study_plan'] as $step): ?>
                                <li><?php echo htmlspecialchars($step); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
