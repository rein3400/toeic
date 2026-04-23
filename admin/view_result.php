<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/db_utils.php';
require_once '../includes/ai_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$test_session = $_GET['session'] ?? '';
$uid = getUsersIdColumn($conn);

if ($test_session === '') {
    header("Location: test_results.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT r.*, u.full_name, u.username, s.status, s.current_section, s.practice_mode, s.target_part
    FROM toeic_test_results r
    JOIN users u ON r.user_id = u.{$uid}
    LEFT JOIN toeic_test_sessions s ON s.test_session = r.test_session
    WHERE r.test_session = ?
");
$stmt->bind_param("s", $test_session);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

$has_report = (bool)$result;
if (!$has_report) {
    $stmt = $conn->prepare("
        SELECT s.*, u.full_name, u.username
        FROM toeic_test_sessions s
        JOIN users u ON s.user_id = u.{$uid}
        WHERE s.test_session = ?
    ");
    $stmt->bind_param("s", $test_session);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        header("Location: test_sessions.php");
        exit();
    }
}

$is_practice = !empty($result['practice_mode']);
$practice = $is_practice ? getTOEICPracticeSummary((int)$result['user_id'], $test_session) : null;
$part_stats = getTOEICPartStatistics((int)$result['user_id'], $test_session);

if (!$is_practice) {
    $result['listening_scaled'] = (int)($result['listening_scaled'] ?? 0);
    $result['reading_scaled'] = (int)($result['reading_scaled'] ?? 0);
    $result['total_score'] = (int)($result['total_score'] ?? ($result['listening_scaled'] + $result['reading_scaled']));
    $result['cefr_level'] = $result['cefr_level'] ?? (getTOEICScoreLevel($result['total_score'])[1] ?? 'A1');
}

$question_rows = [];
$stmt = $conn->prepare("
    SELECT question_order, section, part, question_id, user_answer, is_correct
    FROM toeic_test_questions
    WHERE test_session = ?
    ORDER BY CASE section WHEN 'listening' THEN 1 WHEN 'reading' THEN 2 ELSE 3 END, question_order
");
$stmt->bind_param("s", $test_session);
$stmt->execute();
$question_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cached_score_explanation = null;
try {
    if (checkTableExists($conn, 'admin_analysis_cache')) {
        $stmt = $conn->prepare("
            SELECT analysis_content, generated_at
            FROM admin_analysis_cache
            WHERE test_session = ? AND analysis_type = 'score_explanation'
            ORDER BY generated_at DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $test_session);
            $stmt->execute();
            $cached_score_explanation = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
} catch (Throwable $e) {
    $cached_score_explanation = null;
}

$ai_configured = false;
try {
    $ai_configured = (bool)getActiveAIProvider();
} catch (Throwable $e) {
    $ai_configured = false;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View TOEIC Session - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .score-explanation-box {
            background: #f8fafc;
            border: 1px solid #dbe4ef;
            border-radius: 12px;
            color: #0f172a;
            line-height: 1.65;
            padding: 1rem;
            white-space: pre-wrap;
        }
        .score-explanation-box.is-error {
            background: #fff5f5;
            border-color: #fecaca;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1"><?php echo $has_report ? 'TOEIC Result Detail' : 'TOEIC Session Detail'; ?></h1>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($result['full_name']); ?> · <?php echo htmlspecialchars($result['username']); ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="test_sessions.php" class="btn btn-outline-secondary">Sessions</a>
                        <a href="test_results.php" class="btn btn-outline-secondary">Full Results</a>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3"><div class="stats-card"><h6>Mode</h6><h3><?php echo $is_practice ? 'Practice' : 'Full'; ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Status</h6><h3><?php echo htmlspecialchars(ucfirst((string)($result['status'] ?? 'completed'))); ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Section</h6><h3><?php echo htmlspecialchars(ucfirst((string)($result['current_section'] ?? 'reading'))); ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Target</h6><h3><?php echo $is_practice ? 'Part ' . htmlspecialchars((string)($result['target_part'] ?? '-')) : '200Q'; ?></h3></div></div>
                </div>

                <div class="content-card mb-4">
                    <?php if ($is_practice && $practice): ?>
                        <h5 class="fw-bold mb-3">Practice Summary</h5>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><div class="stats-card"><h6>Part</h6><h3><?php echo htmlspecialchars((string)$practice['part']); ?></h3></div></div>
                            <div class="col-md-3"><div class="stats-card"><h6>Correct</h6><h3><?php echo (int)$practice['correct']; ?></h3></div></div>
                            <div class="col-md-3"><div class="stats-card"><h6>Incorrect</h6><h3><?php echo (int)$practice['incorrect']; ?></h3></div></div>
                            <div class="col-md-3"><div class="stats-card"><h6>Accuracy</h6><h3><?php echo htmlspecialchars((string)$practice['accuracy']); ?>%</h3></div></div>
                        </div>
                    <?php else: ?>
                        <h5 class="fw-bold mb-3">Full TOEIC Summary</h5>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><div class="stats-card"><h6>Listening</h6><h3><?php echo (int)$result['listening_scaled']; ?></h3></div></div>
                            <div class="col-md-3"><div class="stats-card"><h6>Reading</h6><h3><?php echo (int)$result['reading_scaled']; ?></h3></div></div>
                            <div class="col-md-3"><div class="stats-card"><h6>Total</h6><h3><?php echo (int)$result['total_score']; ?></h3></div></div>
                            <div class="col-md-3"><div class="stats-card"><h6>CEFR</h6><h3><?php echo htmlspecialchars((string)$result['cefr_level']); ?></h3></div></div>
                        </div>
                    <?php endif; ?>

                    <h5 class="fw-bold mb-3">Part Breakdown</h5>
                    <?php if (empty($part_stats)): ?>
                        <p class="text-muted mb-0">Belum ada breakdown part untuk sesi ini.</p>
                    <?php else: ?>
                        <?php foreach ($part_stats as $stat): ?>
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></div>
                                    <div class="small text-muted"><?php echo (int)$stat['correct']; ?> / <?php echo (int)$stat['total']; ?> benar</div>
                                </div>
                                <strong><?php echo (int)$stat['percentage']; ?>%</strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="content-card mb-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <h5 class="fw-bold mb-1"><i class="fas fa-circle-question me-2"></i>Mengapa Skor Ini?</h5>
                            <p class="text-muted mb-0">Penjelasan singkat berbasis data nilai, jawaban benar/salah, dan jawaban kosong.</p>
                        </div>
                        <?php if ($ai_configured): ?>
                            <button type="button" class="btn btn-primary" id="btnGenerateScoreExplanation" onclick="generateScoreExplanation(<?php echo $cached_score_explanation ? 'true' : 'false'; ?>)">
                                <i class="fas fa-magic me-2"></i><?php echo $cached_score_explanation ? 'Regenerasi Penjelasan' : 'Generate Penjelasan'; ?>
                            </button>
                        <?php else: ?>
                            <a href="ai_api_settings.php" class="btn btn-outline-secondary">
                                <i class="fas fa-gear me-2"></i>Atur AI Provider
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($cached_score_explanation): ?>
                        <div class="alert alert-success py-2 mb-3">
                            <i class="fas fa-clock me-1"></i>Penjelasan tersimpan dari <?php echo date('d M Y H:i', strtotime($cached_score_explanation['generated_at'])); ?>.
                        </div>
                    <?php elseif (!$ai_configured): ?>
                        <div class="alert alert-warning py-2 mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>AI belum dikonfigurasi. Pilih provider aktif di AI API Settings untuk membuat penjelasan.
                        </div>
                    <?php endif; ?>

                    <div id="score-explanation-placeholder" class="text-center py-4" style="<?php echo $cached_score_explanation ? 'display:none;' : ''; ?>">
                        <i class="fas fa-robot fa-2x text-muted mb-3"></i>
                        <p class="text-muted mb-0">Belum ada penjelasan AI untuk sesi ini.</p>
                    </div>
                    <div id="score-explanation-content" class="score-explanation-box" style="<?php echo $cached_score_explanation ? '' : 'display:none;'; ?>"><?php echo $cached_score_explanation ? htmlspecialchars($cached_score_explanation['analysis_content']) : ''; ?></div>
                    <div id="score-explanation-loading" class="text-center py-4" style="display:none;">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <p class="text-muted mb-0">Menghasilkan penjelasan nilai berbasis data...</p>
                    </div>
                </div>

                <div class="content-card">
                    <h5 class="fw-bold mb-3">Question Log</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Section</th>
                                    <th>Part</th>
                                    <th>Question ID</th>
                                    <th>User Answer</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($question_rows)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada log soal untuk sesi ini.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($question_rows as $row): ?>
                                        <tr>
                                            <td><?php echo (int)$row['question_order']; ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst((string)$row['section'])); ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['part']); ?></td>
                                            <td><?php echo (int)$row['question_id']; ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['user_answer']); ?></td>
                                            <td>
                                                <?php if ($row['is_correct'] === null): ?>
                                                    <span class="badge bg-secondary">Pending</span>
                                                <?php elseif ((int)$row['is_correct'] === 1): ?>
                                                    <span class="badge bg-success">Correct</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Wrong</span>
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
    <script>
        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value || '';
            return div.innerHTML;
        }

        function generateScoreExplanation(regenerate) {
            const btn = document.getElementById('btnGenerateScoreExplanation');
            const placeholder = document.getElementById('score-explanation-placeholder');
            const content = document.getElementById('score-explanation-content');
            const loading = document.getElementById('score-explanation-loading');

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
            }
            if (placeholder) placeholder.style.display = 'none';
            if (content) {
                content.classList.remove('is-error');
                content.style.display = 'none';
            }
            if (loading) loading.style.display = 'block';

            const formData = new FormData();
            formData.append('test_session', <?php echo json_encode($test_session); ?>);
            formData.append('test_format', 'toeic');
            if (regenerate) formData.append('regenerate', '1');

            fetch('ajax_generate_score_explanation.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (loading) loading.style.display = 'none';
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Regenerasi Penjelasan';
                        btn.setAttribute('onclick', 'generateScoreExplanation(true)');
                    }
                    if (content) {
                        content.style.display = 'block';
                        if (data.success) {
                            content.innerHTML = escapeHtml(data.content);
                        } else {
                            content.classList.add('is-error');
                            content.innerHTML = escapeHtml(data.error || 'Gagal menghasilkan penjelasan.');
                        }
                    }
                })
                .catch(error => {
                    if (loading) loading.style.display = 'none';
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Regenerasi Penjelasan';
                    }
                    if (content) {
                        content.style.display = 'block';
                        content.classList.add('is-error');
                        content.innerHTML = escapeHtml('Gagal menghubungi server: ' + error.message);
                    }
                });
        }
    </script>
</body>
</html>
