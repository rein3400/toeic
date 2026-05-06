<?php
/**
 * Learning Pathway - Personalized Curriculum Page
 * AI-generated curriculum based on student weaknesses
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

// Get existing curriculum
$curriculum = null;
$modules = [];
$progress = [];

$stmt = $conn->prepare("SELECT * FROM learning_curriculum WHERE user_id = ? AND test_session = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("is", $user_id, $test_session);
$stmt->execute();
$curriculum = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($curriculum && $curriculum['status'] === 'ready') {
    // Get modules
    $stmt = $conn->prepare("SELECT * FROM learning_modules WHERE curriculum_id = ? ORDER BY module_order");
    $stmt->bind_param("i", $curriculum['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }
    $stmt->close();

    // Get progress
    $stmt = $conn->prepare("SELECT * FROM learning_progress WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $progress[$row['module_id']] = $row;
    }
    $stmt->close();
}

$activeModuleId = (int)($_GET['module'] ?? 0);
$activeModule = null;
$activeExercises = [];

if ($activeModuleId > 0) {
    foreach ($modules as $m) {
        if ((int)$m['id'] === $activeModuleId) {
            $activeModule = $m;
            break;
        }
    }
    if ($activeModule) {
        $stmt = $conn->prepare("SELECT * FROM learning_exercises WHERE module_id = ? ORDER BY exercise_order");
        $stmt->bind_param("i", $activeModuleId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $activeExercises[] = $row;
        }
        $stmt->close();
    }
}

// Section icons
$sectionIcons = [
    'reading' => 'fa-book', 'listening' => 'fa-headphones', 'writing' => 'fa-pen',
    'speaking' => 'fa-microphone', 'grammar' => 'fa-pencil-alt', 'vocabulary' => 'fa-language',
];
$sectionColors = [
    'reading' => '#34d399', 'listening' => '#f472b6', 'writing' => '#60a5fa',
    'speaking' => '#fbbf24', 'grammar' => '#a78bfa', 'vocabulary' => '#fb923c',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Pathway - <?php echo htmlspecialchars($website_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT,WONK@9..144,650..800,35,0&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #ffedcb;
            --bg-card: #ffffff;
            --bg-card-hover: #fff9ec;
            --text-main: #263f78;
            --text-muted: #64748b;
            --accent: #436cac;
            --accent-glow: rgba(67,108,172,0.18);
            --success: #487fb5;
            --warning: #ffe77f;
            --danger: #b42318;
        }
        * { box-sizing: border-box; }
        body { background: var(--bg-dark); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; }
        
        /* Layout */
        .lp-layout { display: flex; min-height: 100vh; }
        .lp-sidebar { 
            width: 320px; min-width: 320px; background: #f3ead8; border-right: 1px solid rgba(23,38,63,0.08);
            overflow-y: auto; position: sticky; top: 0; height: 100vh;
        }
        .lp-main { flex: 1; padding: 2rem 3rem; max-width: 900px; }
        
        /* Sidebar */
        .lp-sidebar-header { padding: 1.5rem; border-bottom: 1px solid #1e293b; }
        .lp-sidebar-header h3 { font-family: 'Fraunces', serif; font-size: 1.1rem; margin: 0; color: var(--text-main); }
        .lp-sidebar-header p { font-size: 0.75rem; color: var(--text-muted); margin: 0.3rem 0 0; }
        
        .lp-module-list { padding: 0.5rem; }
        .lp-module-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 4px;
            cursor: pointer; transition: all 0.2s; text-decoration: none; color: var(--text-main);
        }
        .lp-module-item:hover { background: var(--bg-card-hover); color: var(--text-main); }
        .lp-module-item.active { background: var(--accent); color: #fff; }
        .lp-module-item.locked { opacity: 0.4; cursor: not-allowed; }
        .lp-module-item.completed .lp-module-icon { background: var(--success); }
        
        .lp-module-icon {
            width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center;
            justify-content: center; font-size: 0.8rem; flex-shrink: 0; background: var(--bg-card-hover);
        }
        .lp-module-info { flex: 1; min-width: 0; }
        .lp-module-title { font-size: 0.82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lp-module-meta { font-size: 0.68rem; color: var(--text-muted); }
        .lp-module-item.active .lp-module-meta { color: rgba(255,255,255,0.7); }
        
        .lp-module-status { font-size: 0.7rem; flex-shrink: 0; }
        .lp-module-status .badge { font-size: 0.65rem; }
        
        /* Progress bar in sidebar */
        .lp-progress-bar { padding: 1rem 1.5rem; border-bottom: 1px solid #1e293b; }
        .lp-progress-fill { height: 6px; border-radius: 3px; background: rgba(23,38,63,0.08); overflow: hidden; }
        .lp-progress-fill-inner { height: 100%; background: linear-gradient(90deg, var(--accent), #487fb5); border-radius: 3px; transition: width 0.5s; }
        .lp-progress-text { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.3rem; }
        
        /* Main content */
        .lp-welcome {
            text-align: center; padding: 4rem 2rem;
            background: linear-gradient(135deg, rgba(67,108,172,0.08), rgba(255,231,127,0.22));
            border-radius: 12px; border: 2px solid rgba(67,108,172,0.14);
        }
        .lp-welcome h2 { font-family: 'Fraunces', serif; font-size: 1.8rem; margin-bottom: 1rem; }
        .lp-welcome p { color: var(--text-muted); max-width: 500px; margin: 0 auto 1.5rem; }
        
        .btn-generate {
            background: linear-gradient(180deg, #487fb5 0%, #436cac 52%, #263f78 100%); border: none;
            padding: 1rem 2.5rem; font-size: 1.1rem; font-weight: 700; border-radius: 12px;
            color: #fff; cursor: pointer; transition: transform 0.2s;
        }
        .btn-generate:hover { transform: scale(1.05); color: #fff; }
        .btn-generate:disabled { opacity: 0.5; transform: none; }
        
        /* Module content */
        .module-content { line-height: 1.8; font-size: 0.95rem; }
        .module-content h2 { font-family: 'Fraunces', serif; color: var(--text-main); margin-top: 2rem; font-size: 1.5rem; border-bottom: 2px solid var(--accent); padding-bottom: 0.5rem; }
        .module-content h3 { color: var(--accent); margin-top: 1.5rem; font-size: 1.2rem; }
        .module-content h4 { color: #487fb5; margin-top: 1.2rem; font-size: 1.05rem; }
        .module-content p { color: var(--text-main); margin-bottom: 1rem; }
        .module-content ul, .module-content ol { color: var(--text-main); padding-left: 1.5rem; }
        .module-content li { margin-bottom: 0.5rem; }
        .module-content table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        .module-content th { background: var(--accent); color: #fff; padding: 0.6rem 1rem; text-align: left; font-size: 0.85rem; }
        .module-content td { padding: 0.5rem 1rem; border-bottom: 1px solid #334155; font-size: 0.85rem; }
        .module-content tr:hover td { background: rgba(99,102,241,0.05); }
        .module-content code { background: rgba(255,231,127,0.22); padding: 2px 6px; border-radius: 4px; font-size: 0.85em; color: var(--focus-blue, #436cac); }
        .module-content blockquote { border-left: 3px solid var(--accent); padding: 0.5rem 1rem; margin: 1rem 0; background: rgba(21,39,66,0.04); border-radius: 0 8px 8px 0; }
        .module-content .example { background: rgba(34,197,94,0.05); border: 1px solid rgba(34,197,94,0.2); border-radius: 8px; padding: 1rem; margin: 0.5rem 0; }
        .module-content .warning { background: rgba(245,158,11,0.05); border: 1px solid rgba(245,158,11,0.2); border-radius: 8px; padding: 1rem; margin: 0.5rem 0; }
        
        /* Exercise section */
        .exercise-section { margin-top: 3rem; border-top: 2px solid var(--accent); padding-top: 2rem; }
        .exercise-card {
            background: var(--bg-card); border-radius: 12px; padding: 1.5rem;
            margin-bottom: 1rem; border: 1px solid #334155; transition: border-color 0.3s;
        }
        .exercise-card.correct { border-color: var(--success); }
        .exercise-card.wrong { border-color: var(--danger); }
        .exercise-card .question { font-size: 0.95rem; margin-bottom: 1rem; }
        
        .exercise-options { display: flex; flex-direction: column; gap: 0.5rem; }
        .exercise-option {
            display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem;
            background: rgba(15,23,42,0.5); border: 1px solid #334155; border-radius: 8px;
            cursor: pointer; transition: all 0.2s; font-size: 0.9rem;
        }
        .exercise-option:hover { border-color: var(--accent); background: rgba(99,102,241,0.05); }
        .exercise-option.selected { border-color: var(--accent); background: rgba(99,102,241,0.1); }
        .exercise-option.correct-answer { border-color: var(--success); background: rgba(34,197,94,0.1); }
        .exercise-option.wrong-answer { border-color: var(--danger); background: rgba(239,68,68,0.1); }
        .exercise-option input[type="radio"] { display: none; }
        
        .exercise-input {
            width: 100%; padding: 0.75rem 1rem; background: rgba(15,23,42,0.5);
            border: 1px solid #334155; border-radius: 8px; color: var(--text-main);
            font-size: 0.95rem; outline: none;
        }
        .exercise-input:focus { border-color: var(--accent); }
        
        .exercise-feedback {
            margin-top: 0.75rem; padding: 0.75rem 1rem; border-radius: 8px;
            font-size: 0.85rem; display: none;
        }
        .exercise-feedback.show { display: block; }
        .exercise-feedback.correct { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #86efac; }
        .exercise-feedback.wrong { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
        
        .btn-check-answer {
            background: var(--accent); border: none; padding: 0.5rem 1.5rem;
            border-radius: 8px; color: #fff; font-weight: 600; cursor: pointer;
            margin-top: 0.75rem; font-size: 0.85rem;
        }
        .btn-check-answer:disabled { opacity: 0.5; }
        
        .btn-complete-module {
            background: var(--warning); border: 2px solid rgba(38,63,120,0.16);
            padding: 1rem 2rem; border-radius: 12px; color: var(--accent); font-weight: 700;
            box-shadow: 0 4px 0 #d7bd58;
            font-size: 1rem; cursor: pointer; width: 100%; margin-top: 2rem;
        }
        .btn-complete-module:disabled { opacity: 0.5; }
        
        .score-summary {
            background: linear-gradient(135deg, rgba(72,127,181,0.10), rgba(255,231,127,0.18));
            border: 2px solid rgba(67,108,172,0.18); border-radius: 12px;
            padding: 1.5rem; text-align: center; margin-top: 1.5rem;
        }
        .score-summary h3 { color: var(--success); font-family: 'Fraunces', serif; }
        .score-big { font-size: 3rem; font-weight: 800; color: var(--focus-blue, #436cac); }
        
        /* Loading */
        .generating-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15,23,42,0.95); z-index: 1000;
            display: flex; align-items: center; justify-content: center; flex-direction: column;
        }
        .generating-overlay.hidden { display: none; }
        .spinner { width: 60px; height: 60px; border: 4px solid #334155; border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Responsive */
        @media (max-width: 768px) {
            .lp-layout { flex-direction: column; }
            .lp-sidebar { width: 100%; min-width: 100%; height: auto; position: relative; border-right: none; border-bottom: 1px solid #1e293b; }
            .lp-main { padding: 1.5rem; }
            .lp-module-list { display: flex; overflow-x: auto; gap: 0.5rem; padding: 0.5rem; }
            .lp-module-item { min-width: 200px; flex-shrink: 0; }
        }
    </style>
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body>
    <div class="generating-overlay hidden" id="generatingOverlay">
        <div class="spinner mb-4"></div>
        <h3 style="color:#fff;font-family:'DM Sans',sans-serif;">Membuat Kurikulum Personal...</h3>
        <p style="color:var(--text-muted);max-width:400px;text-align:center;">AI sedang menganalisis kelemahan kamu dan membuat silabus pembelajaran yang disesuaikan. Konten setiap modul akan dibuat saat kamu membukanya.</p>
        <div id="genProgress" style="color:var(--text-muted);font-size:0.85rem;margin-top:1rem;"></div>
    </div>

    <div class="lp-layout">
        <!-- Sidebar -->
        <div class="lp-sidebar">
            <div class="lp-sidebar-header">
                <h3><i class="fas fa-graduation-cap me-2"></i>Learning Pathway</h3>
                <p>Kurikulum personal berdasarkan hasil tes kamu</p>
            </div>
            
            <?php if (!empty($modules)): ?>
            <?php
                $completed = count(array_filter($modules, fn($m) => $m['status'] === 'completed'));
                $total = count($modules);
                $pct = $total > 0 ? round($completed / $total * 100) : 0;
            ?>
            <div class="lp-progress-bar">
                <div class="lp-progress-fill">
                    <div class="lp-progress-fill-inner" style="width: <?php echo $pct; ?>%"></div>
                </div>
                <div class="lp-progress-text"><?php echo $completed; ?>/<?php echo $total; ?> modul selesai (<?php echo $pct; ?>%)</div>
            </div>
            
            <div class="lp-module-list">
                <?php foreach ($modules as $m): 
                    $icon = $sectionIcons[$m['section']] ?? 'fa-book';
                    $color = $sectionColors[$m['section']] ?? '#6366f1';
                    $isActive = (int)$m['id'] === $activeModuleId;
                    $isLocked = $m['status'] === 'locked';
                    $isCompleted = $m['status'] === 'completed';
                    $hasProgress = isset($progress[$m['id']]);
                    $cls = $isActive ? 'active' : ($isLocked ? 'locked' : ($isCompleted ? 'completed' : ''));
                    $href = $isLocked ? '#' : "learning_pathway.php?session=" . urlencode($test_session) . "&module=" . $m['id'];
                ?>
                <a href="<?php echo $href; ?>" class="lp-module-item <?php echo $cls; ?>" <?php echo $isLocked ? 'onclick="return false;"' : ''; ?>>
                    <div class="lp-module-icon" style="<?php echo !$isLocked && !$isActive ? "background:$color;color:#fff;" : ''; ?>">
                        <?php if ($isLocked): ?>
                            <i class="fas fa-lock"></i>
                        <?php elseif ($isCompleted): ?>
                            <i class="fas fa-check"></i>
                        <?php else: ?>
                            <i class="fas <?php echo $icon; ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="lp-module-info">
                        <div class="lp-module-title"><?php echo htmlspecialchars($m['title']); ?></div>
                        <div class="lp-module-meta">
                            <?php echo ucfirst($m['section']); ?> · <?php echo $m['cefr_level']; ?> · <?php echo $m['estimated_minutes']; ?> min
                        </div>
                    </div>
                    <div class="lp-module-status">
                        <?php if ($isCompleted && $hasProgress): ?>
                            <span class="badge bg-success"><?php echo round($progress[$m['id']]['score']); ?>%</span>
                        <?php elseif (!$isLocked && !$isCompleted): ?>
                            <span class="badge bg-primary">Mulai</span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div style="padding: 1rem 1.5rem; border-top: 1px solid #1e293b;">
                <a href="ai_analysis.php?format=toeic&session=<?php echo urlencode($test_session); ?>" style="color:var(--text-muted);font-size:0.8rem;text-decoration:none;">
                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Analisis
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="lp-main">
            <?php if (!$curriculum || $curriculum['status'] !== 'ready'): ?>
                <!-- No curriculum yet -->
                <div class="lp-welcome">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">🎓</div>
                    <h2>Kurikulum Pembelajaran Personal</h2>
                    <p>AI akan menganalisis jawaban tes kamu, mengidentifikasi kelemahan, dan membuat kurikulum belajar yang disesuaikan dengan kebutuhan kamu.</p>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Materi yang dihasilkan bukan sekadar tips — ini adalah konten belajar nyata setara buku teks, lengkap dengan penjelasan, contoh, dan latihan soal.</p>
                    <?php if ($curriculum && $curriculum['status'] === 'failed'): ?>
                        <div class="alert alert-danger mt-3" style="max-width:400px;margin:1rem auto;">
                            <i class="fas fa-exclamation-triangle me-1"></i> Pembuatan kurikulum sebelumnya gagal. Silakan coba lagi.
                        </div>
                    <?php endif; ?>
                    <button class="btn-generate mt-3" onclick="generateCurriculum()">
                        <i class="fas fa-magic me-2"></i>Buat Kurikulum Saya
                    </button>
                </div>
                
            <?php elseif ($activeModule): ?>
                <!-- Show module content -->
                <div style="margin-bottom: 1rem;">
                    <span class="badge" style="background: <?php echo $sectionColors[$activeModule['section']] ?? '#6366f1'; ?>; font-size: 0.75rem;">
                        <i class="fas <?php echo $sectionIcons[$activeModule['section']] ?? 'fa-book'; ?> me-1"></i>
                        <?php echo ucfirst($activeModule['section']); ?>
                    </span>
                    <span class="badge bg-secondary" style="font-size: 0.75rem;"><?php echo $activeModule['cefr_level']; ?></span>
                    <span class="badge bg-dark" style="font-size: 0.75rem;"><i class="fas fa-clock me-1"></i><?php echo $activeModule['estimated_minutes']; ?> menit</span>
                </div>
                
                <h1 style="font-family: 'DM Sans', sans-serif; font-size: 1.8rem; margin-bottom: 1.5rem;">
                    <?php echo htmlspecialchars($activeModule['title']); ?>
                </h1>
                
                <?php 
                $isPlaceholder = strpos($activeModule['content_html'] ?? '', 'fa-spinner') !== false || empty(trim($activeModule['content_html'] ?? ''));
                ?>
                
                <?php if ($isPlaceholder): ?>
                <div id="moduleGenerating" style="text-align:center;padding:3rem;">
                    <div class="spinner mb-3" style="margin:0 auto;"></div>
                    <h3 style="color:#fff;">Membuat Konten Modul...</h3>
                    <p style="color:var(--text-muted);">AI sedang membuat materi pembelajaran untuk modul ini. Tunggu 30-60 detik.</p>
                    <div id="genStatus" style="color:var(--text-muted);font-size:0.85rem;margin-top:1rem;"></div>
                </div>
                <div class="module-content" id="moduleContent" style="display:none;"></div>
                <script>
                (async function() {
                    const status = document.getElementById('genStatus');
                    status.textContent = 'Menghubungi AI...';
                    try {
                        const res = await fetch('ajax_generate_module.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ module_id: <?php echo $activeModuleId; ?> })
                        });
                        const data = await res.json();
                        if (data.success) {
                            status.textContent = 'Konten berhasil dibuat! Memuat ulang...';
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            document.getElementById('moduleGenerating').innerHTML = 
                                '<div style="text-align:center;padding:2rem;"><i class="fas fa-exclamation-triangle" style="font-size:2rem;color:#f59e0b;"></i>' +
                                '<h3 style="color:#fff;margin-top:1rem;">Gagal Generate Konten</h3>' +
                                '<p style="color:var(--text-muted);">' + (data.error || 'Unknown error') + '</p>' +
                                '<button onclick="window.location.reload()" class="btn mt-2" style="background:var(--accent);color:#fff;padding:0.5rem 1.5rem;border:none;border-radius:8px;cursor:pointer;">Coba Lagi</button></div>';
                        }
                    } catch (e) {
                        document.getElementById('moduleGenerating').innerHTML = 
                            '<div style="text-align:center;padding:2rem;"><p style="color:#fca5a5;">Error: ' + e.message + '</p>' +
                            '<button onclick="window.location.reload()" class="btn mt-2" style="background:var(--accent);color:#fff;padding:0.5rem 1.5rem;border:none;border-radius:8px;cursor:pointer;">Coba Lagi</button></div>';
                    }
                })();
                </script>
                <?php else: ?>
                <div class="module-content">
                    <?php echo $activeModule['content_html']; ?>
                </div>
                <?php endif; ?>

                <?php if (!$isPlaceholder && !empty($activeExercises)): ?>
                <div class="exercise-section">
                    <h2 style="font-family: 'DM Sans', sans-serif; color: #fff;">
                        <i class="fas fa-tasks me-2"></i>Latihan (<?php echo count($activeExercises); ?> soal)
                    </h2>
                    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">Jawab semua soal untuk menyelesaikan modul ini.</p>

                    <?php foreach ($activeExercises as $i => $ex): ?>
                    <div class="exercise-card" id="exercise-<?php echo $ex['id']; ?>" data-exercise-id="<?php echo $ex['id']; ?>" data-type="<?php echo $ex['type']; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span style="font-size: 0.75rem; color: var(--text-muted);">Soal <?php echo $i + 1; ?></span>
                            <span class="badge bg-secondary" style="font-size: 0.65rem;"><?php echo $ex['points']; ?> poin</span>
                        </div>
                        <div class="question"><?php echo $ex['question_html']; ?></div>
                        
                        <?php if ($ex['type'] === 'multiple_choice' && !empty($ex['options_json'])): 
                            $options = json_decode($ex['options_json'], true) ?: [];
                        ?>
                        <div class="exercise-options">
                            <?php foreach ($options as $j => $opt): ?>
                            <label class="exercise-option" onclick="selectOption(this, <?php echo $ex['id']; ?>)">
                                <input type="radio" name="ex_<?php echo $ex['id']; ?>" value="<?php echo htmlspecialchars($opt); ?>" data-letter="<?php echo chr(65 + $j); ?>">
                                <span><?php echo htmlspecialchars($opt); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php elseif ($ex['type'] === 'fill_blank'): ?>
                        <input type="text" class="exercise-input" id="input-<?php echo $ex['id']; ?>" 
                               placeholder="Ketik jawaban kamu..." 
                               onkeypress="if(event.key==='Enter')checkAnswer(<?php echo $ex['id']; ?>)">
                        <?php endif; ?>
                        
                        <button class="btn-check-answer" onclick="checkAnswer(<?php echo $ex['id']; ?>)">
                            <i class="fas fa-check me-1"></i> Cek Jawaban
                        </button>
                        <div class="exercise-feedback" id="feedback-<?php echo $ex['id']; ?>"></div>
                    </div>
                    <?php endforeach; ?>

                    <div id="scoreSummary" style="display:none;"></div>

                    <button class="btn-complete-module" id="btnComplete" onclick="completeModule()" disabled>
                        <i class="fas fa-check-circle me-2"></i>Selesaikan Modul & Lanjut
                    </button>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Curriculum ready but no module selected -->
                <div class="lp-welcome">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">📚</div>
                    <h2>Kurikulum Siap!</h2>
                    <p>Pilih modul di sidebar untuk mulai belajar. Modul akan terbuka secara bertahap setelah kamu menyelesaikan modul sebelumnya.</p>
                    <?php if (!empty($modules)): ?>
                    <?php 
                        $firstAvailable = null;
                        foreach ($modules as $m) {
                            if ($m['status'] === 'available') { $firstAvailable = $m; break; }
                        }
                    ?>
                    <?php if ($firstAvailable): ?>
                    <a href="learning_pathway.php?session=<?php echo urlencode($test_session); ?>&module=<?php echo $firstAvailable['id']; ?>" 
                       class="btn-generate mt-3" style="display:inline-block;text-decoration:none;">
                        <i class="fas fa-play me-2"></i>Mulai Belajar
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    let answeredExercises = {};
    let totalExercises = <?php echo count($activeExercises); ?>;
    let totalPoints = 0;
    let maxPoints = <?php echo array_sum(array_column($activeExercises, 'points')); ?>;

    function selectOption(label, exerciseId) {
        // Clear previous selection
        const card = document.getElementById('exercise-' + exerciseId);
        card.querySelectorAll('.exercise-option').forEach(o => o.classList.remove('selected'));
        label.classList.add('selected');
        label.querySelector('input').checked = true;
    }

    async function checkAnswer(exerciseId) {
        if (answeredExercises[exerciseId]) return;

        const card = document.getElementById('exercise-' + exerciseId);
        const type = card.dataset.type;
        let answer = '';

        if (type === 'multiple_choice') {
            const selected = card.querySelector('input[type="radio"]:checked');
            if (!selected) { alert('Pilih jawaban dulu!'); return; }
            answer = selected.value;
        } else {
            const input = document.getElementById('input-' + exerciseId);
            answer = input.value.trim();
            if (!answer) { alert('Ketik jawaban dulu!'); return; }
        }

        // Disable button
        const btn = card.querySelector('.btn-check-answer');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Memeriksa...';

        try {
            const res = await fetch('ajax_check_exercise.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ exercise_id: exerciseId, answer: answer })
            });
            const data = await res.json();

            answeredExercises[exerciseId] = true;
            const feedback = document.getElementById('feedback-' + exerciseId);

            if (data.correct) {
                card.classList.add('correct');
                totalPoints += data.points;
                feedback.className = 'exercise-feedback show correct';
                feedback.innerHTML = '<i class="fas fa-check-circle me-1"></i> <strong>Benar!</strong> ' + (data.explanation || '');
                
                if (type === 'multiple_choice') {
                    const selected = card.querySelector('.exercise-option.selected');
                    if (selected) selected.classList.add('correct-answer');
                }
            } else {
                card.classList.add('wrong');
                feedback.className = 'exercise-feedback show wrong';
                feedback.innerHTML = '<i class="fas fa-times-circle me-1"></i> <strong>Salah.</strong> Jawaban benar: <strong>' + 
                    (data.correct_answer || '') + '</strong><br>' + (data.explanation || '');
                
                if (type === 'multiple_choice') {
                    const selected = card.querySelector('.exercise-option.selected');
                    if (selected) selected.classList.add('wrong-answer');
                    // Highlight correct
                    card.querySelectorAll('.exercise-option').forEach(opt => {
                        const input = opt.querySelector('input');
                        const val = input.value;
                        const correctLetter = (data.correct_letter || '').toLowerCase();
                        const answerText = (data.correct_answer || '').toLowerCase();
                        const valueText = val.toLowerCase();
                        const optionLetter = (input.dataset.letter || '').toLowerCase();
                        const valueMatchesAnswer = valueText === answerText;
                        if ((correctLetter && optionLetter === correctLetter) || valueMatchesAnswer) {
                            opt.classList.add('correct-answer');
                        }
                    });
                }
            }

            btn.style.display = 'none';

            // Check if all answered
            if (Object.keys(answeredExercises).length >= totalExercises) {
                showScoreSummary();
            }
        } catch (e) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Cek Jawaban';
            alert('Gagal memeriksa jawaban: ' + e.message);
        }
    }

    function showScoreSummary() {
        const pct = maxPoints > 0 ? Math.round(totalPoints / maxPoints * 100) : 0;
        const emoji = pct >= 80 ? '🎉' : pct >= 60 ? '👍' : pct >= 40 ? '💪' : '📚';
        
        document.getElementById('scoreSummary').innerHTML = `
            <div class="score-summary">
                <div style="font-size:3rem;">${emoji}</div>
                <h3>Skor Latihan</h3>
                <div class="score-big">${pct}%</div>
                <p style="color:var(--text-muted);">${totalPoints}/${maxPoints} poin</p>
            </div>
        `;
        document.getElementById('scoreSummary').style.display = 'block';
        document.getElementById('btnComplete').disabled = false;
    }

    async function completeModule() {
        const moduleId = <?php echo $activeModuleId ?: 0; ?>;
        if (!moduleId) return;

        const btn = document.getElementById('btnComplete');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';

        const pct = maxPoints > 0 ? Math.round(totalPoints / maxPoints * 100) : 0;

        try {
            const res = await fetch('ajax_module_progress.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    module_id: moduleId,
                    score: pct,
                    answers: answeredExercises,
                    action: 'complete'
                })
            });
            const data = await res.json();
            if (data.success) {
                if (data.next_unlocked) {
                    alert('Modul selesai! Modul berikutnya telah terbuka.');
                } else {
                    alert('Modul selesai!');
                }
                window.location.href = 'learning_pathway.php?session=<?php echo urlencode($test_session); ?>';
            } else {
                throw new Error(data.error || 'Failed');
            }
        } catch (e) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Selesaikan Modul & Lanjut';
            alert('Error: ' + e.message);
        }
    }

    async function generateCurriculum() {
        const btn = document.querySelector('.btn-generate');
        btn.disabled = true;
        document.getElementById('generatingOverlay').classList.remove('hidden');
        const prog = document.getElementById('genProgress');

        prog.textContent = 'Menganalisis kelemahan...';

        try {
            const res = await fetch('ajax_generate_curriculum.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ test_session: '<?php echo addslashes($test_session); ?>' })
            });
            const data = await res.json();

            if (data.success) {
                prog.textContent = 'Kurikulum berhasil dibuat! Memuat halaman...';
                setTimeout(() => window.location.reload(), 1000);
            } else {
                throw new Error(data.error || 'Gagal membuat kurikulum');
            }
        } catch (e) {
            document.getElementById('generatingOverlay').classList.add('hidden');
            btn.disabled = false;
            alert('Error: ' + e.message);
        }
    }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
