<?php
/**
 * Learning Pathway - Personalized Curriculum Page
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
$test_session = $_GET['session'] ?? '';
$website_title = getWebsiteTitle();

if (empty($test_session)) {
    header("Location: index.php");
    exit();
}

// Verify access
if (!$is_admin) {
    $stmt = $conn->prepare("SELECT 1 FROM toeic_test_sessions WHERE user_id = ? AND test_session = ?");
    $stmt->bind_param("is", $user_id, $test_session);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        header("Location: index.php?error=access_denied");
        exit();
    }
    $stmt->close();
}

$curriculum = null;
$modules = [];
$progress = [];

$stmt = $conn->prepare("SELECT * FROM learning_curriculum WHERE user_id = ? AND test_session = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("is", $user_id, $test_session);
$stmt->execute();
$curriculum = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($curriculum && $curriculum['status'] === 'ready') {
    $stmt = $conn->prepare("SELECT * FROM learning_modules WHERE curriculum_id = ? ORDER BY module_order");
    $stmt->bind_param("i", $curriculum['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $modules[] = $row;
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM learning_progress WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $progress[$row['module_id']] = $row;
    $stmt->close();
}

$activeModuleId = (int)($_GET['module'] ?? 0);
$activeModule = null;
$activeExercises = [];

if ($activeModuleId > 0) {
    foreach ($modules as $m) {
        if ((int)$m['id'] === $activeModuleId) { $activeModule = $m; break; }
    }
    if ($activeModule) {
        $stmt = $conn->prepare("SELECT * FROM learning_exercises WHERE module_id = ? ORDER BY exercise_order");
        $stmt->bind_param("i", $activeModuleId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $activeExercises[] = $row;
        $stmt->close();
    }
}

$sectionIcons = [
    'reading' => 'fa-book', 'listening' => 'fa-headphones', 'writing' => 'fa-pen',
    'speaking' => 'fa-microphone', 'grammar' => 'fa-pencil-alt', 'vocabulary' => 'fa-language',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pathway - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .lp-layout { display: grid; grid-template-columns: 320px 1fr; min-height: 100vh; }
        .lp-sidebar { background: #f3ead8; border-right: 2px solid var(--cloud-line); padding: 1.5rem; }
        .lp-main { padding: 3rem; max-width: 1000px; }
        .module-item {
            display: flex; gap: 1rem; padding: 1rem; border-radius: 12px; margin-bottom: 0.5rem;
            text-decoration: none; color: inherit; transition: all 0.2s; border: 2px solid transparent;
        }
        .module-item:hover { background: white; border-color: var(--cloud-line); }
        .module-item.active { background: var(--focus-blue); color: white; border-color: var(--focus-blue); }
        .module-item.locked { opacity: 0.5; pointer-events: none; }
        .module-item.completed { background: rgba(16, 185, 129, 0.05); }

        .lp-progress { height: 10px; background: rgba(0,0,0,0.05); border-radius: 5px; overflow: hidden; margin: 1.5rem 0; }
        .lp-progress-fill { height: 100%; background: var(--focus-blue); transition: width 0.3s; }

        .exercise-opt {
            display: block; padding: 1rem; border: 2px solid var(--cloud-line);
            border-radius: 12px; margin-bottom: 0.5rem; cursor: pointer; transition: all 0.2s;
        }
        .exercise-opt:hover { border-color: var(--focus-blue); }
        .exercise-opt.selected { border-color: var(--focus-blue); background: rgba(72, 127, 181, 0.05); }
        .exercise-opt.correct { border-color: #10b981; background: #ecfdf5; }
        .exercise-opt.wrong { border-color: #ef4444; background: #fef2f2; }

        @media (max-width: 992px) {
            .lp-layout { grid-template-columns: 1fr; }
            .lp-sidebar { border-right: none; border-bottom: 2px solid var(--cloud-line); }
        }
    </style>
</head>
<body class="tc-user-page tc-learning-page">
    <div class="lp-layout">
        <!-- Sidebar -->
        <aside class="lp-sidebar">
            <div class="mb-4">
            <a href="index.php" class="study-headline text-decoration-none h4 d-block mb-1">TOEIC</a>
                <p class="small text-muted fw-bold uppercase">Learning Pathway</p>
            </div>

            <?php if (!empty($modules)): ?>
                <?php
                    $done = count(array_filter($modules, fn($m) => $m['status'] === 'completed'));
                    $total = count($modules);
                    $pct = $total > 0 ? round($done / $total * 100) : 0;
                ?>
                <div class="mb-4">
                    <div class="lp-progress"><div class="lp-progress-fill" style="width: <?php echo $pct; ?>%"></div></div>
                    <div class="small fw-bold text-muted"><?php echo $done; ?> of <?php echo $total; ?> modules completed</div>
                </div>

                <nav class="module-list">
                    <?php foreach ($modules as $m):
                        $isActive = (int)$m['id'] === $activeModuleId;
                        $isLocked = $m['status'] === 'locked';
                        $isCompleted = $m['status'] === 'completed';
                    ?>
                        <a href="<?php echo $isLocked ? '#' : "learning_pathway.php?session=$test_session&module=".$m['id']; ?>"
                           class="module-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isLocked ? 'locked' : ''; ?> <?php echo $isCompleted ? 'completed' : ''; ?>">
                            <div class="avatar-circle flex-shrink-0" style="width:32px; height:32px; font-size:12px; <?php echo $isActive ? 'background:white !important; color:var(--focus-blue) !important;' : ''; ?>">
                                <?php if ($isLocked) echo '<i class="fas fa-lock"></i>'; else if ($isCompleted) echo '<i class="fas fa-check"></i>'; else echo $m['module_order']; ?>
                            </div>
                            <div class="min-w-0">
                                <div class="fw-bold small text-truncate"><?php echo htmlspecialchars($m['title']); ?></div>
                                <div class="text-xs opacity-75"><?php echo ucfirst($m['section']); ?> · <?php echo $m['estimated_minutes']; ?>m</div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="mt-5 pt-4 border-top">
                <a href="ai_analysis.php?format=toeic&session=<?php echo urlencode($test_session); ?>" class="study-button study-button-secondary w-100 py-2 min-vh-0" style="min-height:40px; font-size:12px;">
                    <i class="fas fa-arrow-left me-2"></i> Back to Analysis
                </a>
            </div>
        </aside>

        <!-- Main -->
        <main class="lp-main">
            <?php if (!$curriculum || $curriculum['status'] !== 'ready'): ?>
                <div class="study-card text-center p-5">
                    <div class="avatar-circle mx-auto mb-4" style="width:80px; height:80px; background:rgba(72,127,181,0.1) !important; border:none;">
                        <i class="fas fa-graduation-cap fa-2x text-primary"></i>
                    </div>
                    <h2 class="h3 mb-3">Your Personalized Pathway</h2>
                    <p class="text-muted mb-4">Our AI will analyze your test performance and build a customized curriculum to target your specific weaknesses.</p>
                    <button class="study-button px-5" onclick="generateCurriculum()" id="btnGen">Build My Pathway</button>
                    <div id="genStatus" class="mt-4 small fw-bold text-primary" style="display:none;">
                        <i class="fas fa-robot fa-spin me-2"></i> Thinking... This may take up to 60 seconds.
                    </div>
                </div>

            <?php elseif ($activeModule): ?>
                <div class="mb-4">
                    <span class="study-kicker"><?php echo ucfirst($activeModule['section']); ?> · <?php echo $activeModule['cefr_level']; ?></span>
                    <h1 class="display-5 mb-4"><?php echo htmlspecialchars($activeModule['title']); ?></h1>
                </div>

                <div class="study-card mb-5">
                    <div class="module-content">
                        <?php echo $activeModule['content_html']; ?>
                    </div>
                </div>

                <?php if (!empty($activeExercises)): ?>
                    <h3 class="h4 mb-4 fw-bold"><i class="fas fa-tasks me-2"></i> Practice Exercises</h3>
                    <?php foreach ($activeExercises as $i => $ex): ?>
                        <div class="study-card mb-4 exercise-box" id="ex-<?php echo $ex['id']; ?>" data-id="<?php echo $ex['id']; ?>" data-type="<?php echo $ex['type']; ?>">
                            <div class="d-flex justify-content-between mb-3">
                                <span class="small fw-bold text-muted uppercase">Question <?php echo $i+1; ?></span>
                                <span class="badge bg-light text-dark"><?php echo $ex['points']; ?> pts</span>
                            </div>
                            <div class="mb-4 fw-bold"><?php echo $ex['question_html']; ?></div>

                            <?php if ($ex['type'] === 'multiple_choice'): ?>
                                <div class="options-list">
                                    <?php foreach (json_decode($ex['options_json'], true) ?: [] as $opt): ?>
                                        <label class="exercise-opt">
                                            <input type="radio" name="opt_<?php echo $ex['id']; ?>" value="<?php echo htmlspecialchars($opt); ?>" class="d-none">
                                            <span><?php echo htmlspecialchars($opt); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <input type="text" class="form-control mb-3" id="input-<?php echo $ex['id']; ?>" placeholder="Your answer...">
                            <?php endif; ?>

                            <div class="feedback-area mt-3 p-3 rounded-3 small fw-bold" style="display:none;"></div>
                            <button class="study-button py-2 px-4 min-vh-0 mt-3" style="min-height:40px; font-size:13px;" onclick="checkEx(<?php echo $ex['id']; ?>)">Check Answer</button>
                        </div>
                    <?php endforeach; ?>

                    <div id="finishArea" style="display:none;" class="text-center mt-5">
                        <div class="study-card bg-light border-0 p-4 mb-4">
                            <h4 class="fw-bold mb-1">Module Completed!</h4>
                            <p class="text-muted mb-0">You've finished all exercises for this module.</p>
                        </div>
                        <button class="study-button w-100" id="btnFinish" onclick="finishModule()">Complete & Continue</button>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="study-card text-center p-5">
                    <h2 class="h3 mb-3">Curriculum Ready</h2>
                    <p class="text-muted mb-4">Please select a module from the sidebar to start your learning journey.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        async function generateCurriculum() {
            const btn = document.getElementById('btnGen');
            const status = document.getElementById('genStatus');
            btn.disabled = true; status.style.display = 'block';
            try {
                const res = await fetch('ajax_generate_curriculum.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ test_session: '<?php echo $test_session; ?>' })
                });
                const data = await res.json();
                if (data.success) window.location.reload(); else throw new Error(data.error);
            } catch(e) { alert(e.message); btn.disabled = false; status.style.display = 'none'; }
        }

        const answered = {};
        document.querySelectorAll('.exercise-opt').forEach(opt => {
            opt.addEventListener('click', () => {
                opt.parentElement.querySelectorAll('.exercise-opt').forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                opt.querySelector('input').checked = true;
            });
        });

        async function checkEx(id) {
            if (answered[id]) return;
            const box = document.getElementById('ex-'+id);
            const btn = box.querySelector('button');
            const feedback = box.querySelector('.feedback-area');
            let ans = '';
            if (box.dataset.type === 'multiple_choice') {
                const sel = box.querySelector('input:checked');
                if (!sel) return alert('Select an option');
                ans = sel.value;
            } else {
                ans = box.querySelector('input').value.trim();
                if (!ans) return alert('Type an answer');
            }
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            try {
                const res = await fetch('ajax_check_exercise.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ exercise_id: id, answer: ans })
                });
                const data = await res.json();
                answered[id] = true; btn.style.display = 'none';
                feedback.style.display = 'block';
                if (data.correct) {
                    feedback.innerHTML = '<i class="fas fa-check-circle me-2"></i> Correct! ' + (data.explanation || '');
                    feedback.className += ' text-success bg-success-subtle';
                    if (box.dataset.type === 'multiple_choice') box.querySelector('.exercise-opt.selected').classList.add('correct');
                } else {
                    feedback.innerHTML = '<i class="fas fa-times-circle me-2"></i> Incorrect. Correct answer: ' + data.correct_answer;
                    feedback.className += ' text-danger bg-danger-subtle';
                    if (box.dataset.type === 'multiple_choice') box.querySelector('.exercise-opt.selected').classList.add('wrong');
                }
                if (Object.keys(answered).length >= <?php echo count($activeExercises); ?>) document.getElementById('finishArea').style.display = 'block';
            } catch(e) { alert(e.message); btn.disabled = false; btn.innerHTML = 'Check Answer'; }
        }

        async function finishModule() {
            const btn = document.getElementById('btnFinish');
            btn.disabled = true; btn.innerHTML = 'Saving...';
            try {
                const res = await fetch('ajax_module_progress.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ module_id: <?php echo $activeModuleId ?: 0; ?>, action: 'complete', score: 100 })
                });
                const data = await res.json();
                if (data.success) window.location.href = 'learning_pathway.php?session=<?php echo urlencode($test_session); ?>';
                else throw new Error(data.error);
            } catch(e) { alert(e.message); btn.disabled = false; btn.innerHTML = 'Complete & Continue'; }
        }
    </script>
</body>
</html>
