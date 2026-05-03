<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/ai_helper.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/components/toeic_progress_bar.php';

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
$part_progress_items = [];
foreach ($part_stats as $stat) {
    $percentage = (float)($stat['percentage'] ?? 0);
    $part_progress_items[] = [
        'label' => (string)($stat['name'] ?? ''),
        'meta' => (int)($stat['correct'] ?? 0) . ' / ' . (int)($stat['total'] ?? 0) . ' correct',
        'value' => $percentage,
        'value_label' => (int)round($percentage) . '%',
    ];
}

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
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/ruangguru-theme.css', '../assets/css/ruangguru-theme.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">
</head>
<body>
    <main class="toeic-page-shell">
        <div class="toeic-page-header">
            <div>
                <div class="toeic-kicker mb-3">TOEIC AI analysis</div>
                <h1 class="display-6 mb-3">Turn score data into a study decision.</h1>
                <p class="toeic-subcopy">Use AI-generated feedback to translate Listening and Reading results into strengths, weaknesses, and the next best TOEIC focus.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="result_toeic.php?session=<?php echo urlencode($test_session); ?>" class="btn btn-outline-secondary">Back to Result</a>
                <?php if (!$analysis): ?>
                    <a href="ai_analysis.php?format=toeic&session=<?php echo urlencode($test_session); ?>&generate=1" class="btn btn-warning">Generate Analysis</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <section class="toeic-panel p-4 h-100">
                    <div class="toeic-eyebrow mb-3">Score snapshot</div>
                    <h2 class="h4 mb-3">Current TOEIC result</h2>
                    <div class="toeic-table-row"><span>Listening</span><strong><?php echo (int)$test_data['listening_scaled']; ?></strong></div>
                    <div class="toeic-table-row"><span>Reading</span><strong><?php echo (int)$test_data['reading_scaled']; ?></strong></div>
                    <div class="toeic-table-row"><span>Total</span><strong><?php echo (int)$test_data['total_score']; ?></strong></div>
                    <div class="toeic-table-row"><span>CEFR</span><strong><?php echo htmlspecialchars($test_data['cefr_level']); ?></strong></div>
                </section>
            </div>
            <div class="col-lg-7">
                <section class="toeic-panel p-4 h-100">
                    <div class="toeic-eyebrow mb-3">Part breakdown</div>
                    <h2 class="h4 mb-3">Listening and Reading accuracy</h2>
                    <?php renderToeicProgressRows($part_progress_items, ['aria_label' => 'AI analysis TOEIC part accuracy']); ?>
                </section>
            </div>
        </div>

        <section class="toeic-panel p-4 mt-4">
            <div class="toeic-eyebrow mb-3">AI feedback</div>
            <h2 class="h4 mb-3">Recommended interpretation</h2>
            <?php if ($ai_not_configured): ?>
                <div class="alert alert-warning rounded-4 border-0 mb-0">AI provider belum dikonfigurasi.</div>
            <?php elseif ($analysis_error): ?>
                <div class="alert alert-danger rounded-4 border-0 mb-0"><?php echo htmlspecialchars($analysis_error); ?></div>
            <?php elseif (!$analysis): ?>
                <p class="toeic-copy mb-0">Analisis belum dibuat. Jalankan generate untuk membuat feedback TOEIC dari hasil ini.</p>
            <?php else: ?>
                <p class="toeic-copy mb-4"><?php echo htmlspecialchars($analysis['summary'] ?? ''); ?></p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="toeic-band h-100">
                            <div class="toeic-eyebrow mb-3">Strengths</div>
                            <ul class="toeic-list-check mb-0">
                                <?php foreach (($analysis['strengths'] ?? []) as $item): ?>
                                    <li><?php echo htmlspecialchars($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="toeic-band h-100">
                            <div class="toeic-eyebrow mb-3">Weaknesses</div>
                            <ul class="toeic-list-check mb-0">
                                <?php foreach (($analysis['weaknesses'] ?? []) as $item): ?>
                                    <li><?php echo htmlspecialchars($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php if (!empty($analysis['recommended_focus'])): ?>
                    <div class="mt-4">
                        <div class="toeic-eyebrow mb-3">Recommended focus</div>
                        <?php foreach ($analysis['recommended_focus'] as $focus): ?>
                            <div class="toeic-table-row">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($focus['part'] ?? 'Part'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($focus['reason'] ?? ''); ?></div>
                                </div>
                                <div><?php echo htmlspecialchars($focus['action'] ?? ''); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($analysis['study_plan'])): ?>
                    <div class="mt-4">
                        <div class="toeic-eyebrow mb-3">Study plan</div>
                        <ol class="toeic-copy mb-0">
                            <?php foreach (($analysis['study_plan'] ?? []) as $item): ?>
                                <li class="mb-2"><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
