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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Analysis - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-user-page tc-ai-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="d-flex gap-2">
                <a href="result_toeic.php?session=<?php echo urlencode($test_session); ?>" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">Result</a>
                <?php if (!$analysis): ?>
                    <a href="ai_analysis.php?format=toeic&session=<?php echo urlencode($test_session); ?>&generate=1" class="study-button py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">Generate</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="toeic-page-shell">
        <div class="mb-5">
            <span class="study-kicker">Personalized Feedback</span>
            <h1 class="display-5 mb-2">AI Performance Analysis</h1>
            <p class="lead text-muted">Deep dive into your TOEIC performance using advanced AI reasoning.</p>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-lg-4">
                <section class="study-card h-100">
                    <span class="study-kicker">Summary</span>
                    <h2 class="h4 mb-4">Core Scores</h2>
                    <div class="d-flex justify-content-between p-3 mb-2 rounded-3 bg-light">
                        <span class="fw-bold">Listening</span>
                        <span class="fw-bold" style="color:var(--focus-blue);"><?php echo (int)$test_data['listening_scaled']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between p-3 mb-2 rounded-3 bg-light">
                        <span class="fw-bold">Reading</span>
                        <span class="fw-bold" style="color:var(--focus-blue);"><?php echo (int)$test_data['reading_scaled']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between p-3 mb-2 rounded-3 bg-light">
                        <span class="fw-bold">Total</span>
                        <span class="h4 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo (int)$test_data['total_score']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between p-3 rounded-3 bg-light">
                        <span class="fw-bold">CEFR</span>
                        <span class="badge bg-dark rounded-pill px-3"><?php echo htmlspecialchars($test_data['cefr_level']); ?></span>
                    </div>
                </section>
            </div>
            <div class="col-lg-8">
                <section class="study-card h-100">
                    <span class="study-kicker">Breakdown</span>
                    <h2 class="h4 mb-4">Accuracy per Part</h2>
                    <div class="row g-3">
                        <?php foreach ($part_stats as $stat): ?>
                            <div class="col-md-6">
                                <div class="p-3 border rounded-3 h-100">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="small fw-bold"><?php echo htmlspecialchars($stat['name']); ?></span>
                                        <span class="small fw-bold"><?php echo (int)$stat['percentage']; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar rounded-pill" style="width: <?php echo (int)$stat['percentage']; ?>%; background: var(--academy-blue);"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>

        <section class="study-card">
            <span class="study-kicker">AI Recommendation</span>
            <h2 class="h3 mb-4">Coach Insights</h2>

            <?php if ($ai_not_configured): ?>
                <div class="alert alert-warning border-0 rounded-4">AI provider not configured. Please contact administrator.</div>
            <?php elseif ($analysis_error): ?>
                <div class="alert alert-danger border-0 rounded-4"><?php echo htmlspecialchars($analysis_error); ?></div>
            <?php elseif (!$analysis): ?>
                <div class="text-center py-5">
                    <i class="fas fa-robot fa-3x mb-3 opacity-25"></i>
                    <p class="text-muted">No analysis generated yet. Click 'Generate' above to start.</p>
                </div>
            <?php else: ?>
                <div class="p-4 rounded-4 mb-5" style="background: rgba(72, 127, 181, 0.05);">
                    <p class="lead mb-0 fw-bold" style="color:var(--focus-blue);"><?php echo htmlspecialchars($analysis['summary'] ?? ''); ?></p>
                </div>

                <div class="row g-4 mb-5">
                    <div class="col-md-6">
                        <div class="h-100 p-4 border rounded-4">
                            <h3 class="h5 fw-bold mb-4"><i class="fas fa-star me-2 text-warning"></i> Key Strengths</h3>
                            <ul class="list-unstyled">
                                <?php foreach (($analysis['strengths'] ?? []) as $item): ?>
                                    <li class="mb-3 d-flex gap-3">
                                        <i class="fas fa-check text-success mt-1"></i>
                                        <span class="fw-medium"><?php echo htmlspecialchars($item); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="h-100 p-4 border rounded-4">
                            <h3 class="h5 fw-bold mb-4"><i class="fas fa-bullseye me-2 text-danger"></i> Areas to Improve</h3>
                            <ul class="list-unstyled">
                                <?php foreach (($analysis['weaknesses'] ?? []) as $item): ?>
                                    <li class="mb-3 d-flex gap-3">
                                        <i class="fas fa-arrow-right text-danger mt-1"></i>
                                        <span class="fw-medium"><?php echo htmlspecialchars($item); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php if (!empty($analysis['recommended_focus'])): ?>
                    <div class="mb-5">
                        <h3 class="h5 fw-bold mb-4">Recommended Training Focus</h3>
                        <?php foreach ($analysis['recommended_focus'] as $focus): ?>
                            <div class="study-card mb-3 border-0 bg-light p-4">
                                <div class="row align-items-center">
                                    <div class="col-md-3">
                                        <span class="badge bg-primary px-3 py-2 rounded-pill uppercase fw-bold"><?php echo htmlspecialchars($focus['part'] ?? 'Part'); ?></span>
                                    </div>
                                    <div class="col-md-9 mt-3 mt-md-0">
                                        <div class="fw-bold mb-1"><?php echo htmlspecialchars($focus['reason'] ?? ''); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($focus['action'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($analysis['study_plan'])): ?>
                    <div>
                        <h3 class="h5 fw-bold mb-4">Step-by-Step Study Plan</h3>
                        <div class="p-4 border rounded-4 bg-white">
                            <ol class="mb-0">
                                <?php foreach (($analysis['study_plan'] ?? []) as $item): ?>
                                    <li class="mb-3 ps-2 fw-medium"><?php echo htmlspecialchars($item); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
