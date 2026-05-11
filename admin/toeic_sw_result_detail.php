<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_sw_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
ensureToeicSwSchema($conn);

$test_session = trim((string)($_GET['session'] ?? ''));
if ($test_session === '') {
    header("Location: toeic_sw_results.php");
    exit();
}

$session = null;
$users_id_col = 'id';
$users_id_check = $conn->query("SHOW COLUMNS FROM users LIKE 'id_user'");
if ($users_id_check && $users_id_check->num_rows > 0) {
    $users_id_col = 'id_user';
}
$stmt = $conn->prepare("
    SELECT s.*, u.full_name
    FROM toeic_sw_test_sessions s
    LEFT JOIN users u ON u.{$users_id_col} = s.user_id
    WHERE s.test_session = ?
    LIMIT 1
");
$stmt->bind_param("s", $test_session);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$session) {
    http_response_code(404);
    echo 'TOEIC SW session not found.';
    exit();
}

$scores = [];
$stmt = $conn->prepare("SELECT * FROM toeic_sw_subjective_scores WHERE test_session = ? ORDER BY id ASC");
$stmt->bind_param("s", $test_session);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $scores[(int)$row['question_row_id']] = $row;
}
$stmt->close();

$questions = [];
$stmt = $conn->prepare("SELECT * FROM toeic_sw_test_questions WHERE test_session = ? ORDER BY section ASC, question_order ASC");
$stmt->bind_param("s", $test_session);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $content = getToeicSwQuestionRow($conn, $test_session, (string)$row['section'], (int)$row['question_order']);
    $row['content'] = $content['content'] ?? [];
    $questions[] = $row;
}
$stmt->close();

function toeicSwAdminDetailH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function toeicSwPrettyJson(?string $json): string {
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return (string)$json;
    }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string)$json;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC SW Review - <?php echo toeicSwAdminDetailH($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .review-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        .review-row { border: 1px solid rgba(255,255,255,.12); border-radius: 8px; padding: 1rem; }
        .review-meta { display: flex; flex-wrap: wrap; gap: .5rem; }
        .review-pre { max-height: 340px; overflow: auto; white-space: pre-wrap; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 admin-content">
            <div class="admin-header">
                <h1><i class="fas fa-clipboard-check me-3"></i>TOEIC SW Item Review</h1>
                <p class="admin-subtitle mb-0">Second-analysis view for transcripts, raw scoring, feedback JSON, and recordings.</p>
            </div>

            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <a class="btn btn-outline-light btn-sm" href="toeic_sw_results.php">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                    <code><?php echo toeicSwAdminDetailH($test_session); ?></code>
                </div>

                <div class="content-card mb-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="text-muted small text-uppercase">Student</div>
                            <div class="fw-bold"><?php echo toeicSwAdminDetailH($session['full_name'] ?? ('User ' . $session['user_id'])); ?></div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-muted small text-uppercase">Package</div>
                            <div class="fw-bold"><?php echo (int)$session['package_number']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small text-uppercase">Score</div>
                            <div class="fw-bold">
                                S <?php echo (int)($session['speaking_scaled'] ?? 0); ?> /
                                W <?php echo (int)($session['writing_scaled'] ?? 0); ?> /
                                Total <?php echo (int)($session['total_score'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-muted small text-uppercase">Status</div>
                            <div class="fw-bold"><?php echo toeicSwAdminDetailH($session['status']); ?></div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-muted small text-uppercase">Completed</div>
                            <div class="fw-bold"><?php echo toeicSwAdminDetailH($session['completed_at'] ?? '-'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="review-grid">
                    <?php foreach ($questions as $question): ?>
                        <?php
                        $score = $scores[(int)$question['id']] ?? [];
                        $content = $question['content'] ?? [];
                        $promptAudio = toeicSwMediaUrl($content['audio_path'] ?? '');
                        $imageUrl = toeicSwMediaUrl($content['image_path'] ?? '');
                        $answerAudio = toeicSwMediaUrl($question['source_path'] ?? '');
                        ?>
                        <div class="content-card review-row">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <div class="review-meta mb-2">
                                        <span class="badge bg-primary"><?php echo toeicSwAdminDetailH(strtoupper((string)$question['section'])); ?> Q<?php echo (int)$question['question_order']; ?></span>
                                        <span class="badge bg-secondary"><?php echo toeicSwAdminDetailH($question['question_type']); ?></span>
                                        <span class="badge bg-<?php echo (($score['status'] ?? '') === 'scored') ? 'success' : ((($score['status'] ?? '') === 'needs_rescore') ? 'warning text-dark' : 'secondary'); ?>">
                                            <?php echo toeicSwAdminDetailH($score['status'] ?? 'pending'); ?>
                                        </span>
                                    </div>
                                    <h5 class="mb-0"><?php echo toeicSwAdminDetailH($content['title'] ?? ('Question ' . $question['question_order'])); ?></h5>
                                </div>
                                <div class="text-end small">
                                    <div>Raw Score: <strong><?php echo toeicSwAdminDetailH($score['raw_score'] ?? '-'); ?></strong></div>
                                    <div>Normalized Score: <strong><?php echo toeicSwAdminDetailH($score['normalized_score'] ?? '-'); ?></strong></div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <div class="text-muted small text-uppercase">Prompt</div>
                                    <p><?php echo nl2br(toeicSwAdminDetailH($content['prompt_text'] ?? '')); ?></p>

                                    <?php if ($imageUrl): ?>
                                        <img src="<?php echo toeicSwAdminDetailH($imageUrl); ?>" alt="" class="img-fluid rounded border mb-3">
                                    <?php endif; ?>

                                    <?php if ($promptAudio): ?>
                                        <div class="mb-3">
                                            <div class="text-muted small text-uppercase">Prompt Audio</div>
                                            <audio controls preload="metadata" src="<?php echo toeicSwAdminDetailH($promptAudio); ?>"></audio>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($answerAudio): ?>
                                        <div class="mb-3">
                                            <div class="text-muted small text-uppercase">Student Recording</div>
                                            <audio controls preload="metadata" src="<?php echo toeicSwAdminDetailH($answerAudio); ?>"></audio>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-lg-6">
                                    <div class="text-muted small text-uppercase">Student Answer / Transcript</div>
                                    <pre class="review-pre p-3 bg-dark text-light rounded"><?php echo toeicSwAdminDetailH(trim((string)($score['transcript_text'] ?? $question['user_answer'] ?? '')) ?: '-'); ?></pre>

                                    <div class="review-meta mb-3">
                                        <span class="badge bg-info text-dark">Provider: <?php echo toeicSwAdminDetailH($score['ai_provider'] ?? '-'); ?></span>
                                        <span class="badge bg-info text-dark">Model: <?php echo toeicSwAdminDetailH($score['ai_model'] ?? '-'); ?></span>
                                    </div>

                                    <?php if (!empty($score['fallback_reason'])): ?>
                                        <div class="alert alert-warning py-2">
                                            <strong>Fallback Reason:</strong>
                                            <?php echo toeicSwAdminDetailH($score['fallback_reason']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <details>
                                        <summary class="fw-bold">Feedback JSON</summary>
                                        <pre class="review-pre p-3 bg-dark text-light rounded mt-2"><?php echo toeicSwAdminDetailH(toeicSwPrettyJson($score['feedback_json'] ?? '')); ?></pre>
                                    </details>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
