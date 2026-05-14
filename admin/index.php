<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/proctor_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();

function safeCount($conn, $table) {
    if (!checkTableExists($conn, $table)) {
        return 0;
    }
    $result = $conn->query("SELECT COUNT(*) AS total FROM `$table`");
    return $result ? (int)$result->fetch_assoc()['total'] : 0;
}

$readiness = getTOEICContentReadiness($conn);
$toeic_audio = safeCount($conn, 'toeic_audio');
$toeic_texts = safeCount($conn, 'toeic_teks');
$toeic_results = safeCount($conn, 'toeic_test_results');
$active_full_sessions = safeCount($conn, 'toeic_test_sessions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'active' AND COALESCE(practice_mode, 0) = 0")->fetch_assoc()['total'] ?? 0) : 0;
$active_practice_sessions = safeCount($conn, 'toeic_test_sessions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'active' AND COALESCE(practice_mode, 0) = 1")->fetch_assoc()['total'] ?? 0) : 0;
$completed_practice_sessions = safeCount($conn, 'toeic_test_sessions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'completed' AND COALESCE(practice_mode, 0) = 1")->fetch_assoc()['total'] ?? 0) : 0;
$terminated_proctor_sessions = safeCount($conn, 'proctoring_sessions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_sessions WHERE status = 'terminated'")->fetch_assoc()['total'] ?? 0) : 0;
$cleared_proctor_sessions = safeCount($conn, 'proctoring_sessions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_sessions WHERE review_status = 'cleared'")->fetch_assoc()['total'] ?? 0) : 0;
$students = 0;
$users_id_col = getUsersIdColumn($conn);
$result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student'");
if ($result) {
    $students = (int)$result->fetch_assoc()['total'];
}

$recent_results = [];
if (checkTableExists($conn, 'toeic_test_results')) {
    $recent_results = $conn->query("
        SELECT r.test_session, r.total_score, r.listening_scaled, r.reading_scaled, r.completed_at, u.full_name
        FROM toeic_test_results r
        JOIN users u ON u.{$users_id_col} = r.user_id
        ORDER BY r.completed_at DESC
        LIMIT 10
    ");
}

$recent_sessions = [];
if (checkTableExists($conn, 'toeic_test_sessions')) {
    $recent_sessions = $conn->query("
        SELECT
            s.test_session,
            s.status,
            s.practice_mode,
            s.target_part,
            s.current_section,
            s.started_at,
            s.completed_at,
            u.full_name,
            p.status AS proctor_status,
            p.review_status
        FROM toeic_test_sessions s
        JOIN users u ON u.{$users_id_col} = s.user_id
        LEFT JOIN proctoring_sessions p ON p.test_session = s.test_session
        ORDER BY COALESCE(s.completed_at, s.started_at) DESC
        LIMIT 10
    ");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin TOEIC - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .card-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 1.5rem;
        }
        .metric-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 1.35rem;
        }
        .row-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .row-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div>
                        <div class="small text-uppercase text-muted fw-semibold">TOEIC Admin</div>
                        <h1 class="fw-bold mb-1">Dashboard TOEIC-Only</h1>
                        <p class="text-muted mb-0">Semua statistik dan shortcut di halaman ini difokuskan untuk produk TOEIC.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="manage_toeic.php" class="btn btn-warning rounded-pill px-4 fw-bold">Open TOEIC Bank</a>
                        <a href="toeic_sw_bank.php" class="btn btn-outline-warning rounded-pill px-4 fw-bold">Open SW Bank</a>
                        <a href="test_sessions.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Open Sessions</a>
                        <a href="proctoring_sessions.php" class="btn btn-outline-primary rounded-pill px-4 fw-bold">Open Proctoring</a>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-3 col-md-6"><div class="metric-card"><div class="small text-muted mb-2">TOEIC Listening Items</div><div class="h2 fw-bold mb-0"><?php echo (int)($readiness['parts']['1']['actual'] + $readiness['parts']['2']['actual'] + $readiness['parts']['3']['actual'] + $readiness['parts']['4']['actual']); ?></div></div></div>
                    <div class="col-lg-3 col-md-6"><div class="metric-card"><div class="small text-muted mb-2">TOEIC Reading Items</div><div class="h2 fw-bold mb-0"><?php echo (int)($readiness['parts']['5']['actual'] + $readiness['parts']['6']['actual'] + $readiness['parts']['7']['actual']); ?></div></div></div>
                    <div class="col-lg-3 col-md-6"><div class="metric-card"><div class="small text-muted mb-2">Students</div><div class="h2 fw-bold mb-0"><?php echo $students; ?></div></div></div>
                    <div class="col-lg-3 col-md-6"><div class="metric-card"><div class="small text-muted mb-2">Completed TOEIC Reports</div><div class="h2 fw-bold mb-0"><?php echo $toeic_results; ?></div></div></div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-2 col-md-4"><div class="metric-card"><div class="small text-muted mb-2">Active Full</div><div class="h2 fw-bold mb-0"><?php echo $active_full_sessions; ?></div></div></div>
                    <div class="col-lg-2 col-md-4"><div class="metric-card"><div class="small text-muted mb-2">Active Practice</div><div class="h2 fw-bold mb-0"><?php echo $active_practice_sessions; ?></div></div></div>
                    <div class="col-lg-3 col-md-4"><div class="metric-card"><div class="small text-muted mb-2">Completed Practice</div><div class="h2 fw-bold mb-0"><?php echo $completed_practice_sessions; ?></div></div></div>
                    <div class="col-lg-2 col-md-6"><div class="metric-card"><div class="small text-muted mb-2">Terminated Proctor</div><div class="h2 fw-bold mb-0"><?php echo $terminated_proctor_sessions; ?></div></div></div>
                    <div class="col-lg-3 col-md-6"><div class="metric-card"><div class="small text-muted mb-2">Cleared Proctor</div><div class="h2 fw-bold mb-0"><?php echo $cleared_proctor_sessions; ?></div></div></div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card-panel h-100">
                            <h2 class="h5 fw-bold mb-3">Readiness by Part</h2>
                            <?php foreach ($readiness['parts'] as $part => $item): ?>
                                <div class="row-item">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($item['label']); ?></div>
                                        <div class="small text-muted">Target <?php echo (int)$item['target']; ?> soal</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold"><?php echo (int)$item['actual']; ?></div>
                                        <div class="small <?php echo $item['gap'] > 0 ? 'text-warning' : 'text-success'; ?>">
                                            <?php echo $item['gap'] > 0 ? 'Gap ' . (int)$item['gap'] : 'Ready'; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card-panel h-100">
                            <h2 class="h5 fw-bold mb-3">Recent Full Results</h2>
                            <?php if (!$recent_results || $recent_results->num_rows === 0): ?>
                                <p class="text-muted mb-0">Belum ada result TOEIC yang selesai.</p>
                            <?php else: ?>
                                <?php while ($row = $recent_results->fetch_assoc()): ?>
                                    <div class="row-item">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                            <div class="small text-muted"><?php echo date('d M Y H:i', strtotime($row['completed_at'])); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold"><?php echo (int)$row['total_score']; ?></div>
                                            <div class="small text-muted">L <?php echo (int)$row['listening_scaled']; ?> · R <?php echo (int)$row['reading_scaled']; ?></div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-lg-12">
                        <div class="card-panel h-100">
                            <h2 class="h5 fw-bold mb-3">Recent Runtime Sessions</h2>
                            <?php if (!$recent_sessions || $recent_sessions->num_rows === 0): ?>
                                <p class="text-muted mb-0">Belum ada sesi TOEIC aktif atau riwayat runtime yang tersimpan.</p>
                            <?php else: ?>
                                <?php while ($row = $recent_sessions->fetch_assoc()): ?>
                                    <?php
                                    $modeLabel = !empty($row['practice_mode']) ? 'Practice' : 'Full';
                                    if (($row['proctor_status'] ?? '') === 'terminated') {
                                        $statusText = 'Proctor Terminated';
                                        $statusClass = 'text-danger';
                                    } elseif (($row['review_status'] ?? '') === 'cleared') {
                                        $statusText = 'Cleared';
                                        $statusClass = 'text-success';
                                    } elseif (!empty($row['practice_mode'])) {
                                        $statusText = $row['status'] === 'completed' ? 'Practice Completed' : 'Practice Active';
                                        $statusClass = 'text-primary';
                                    } else {
                                        $statusText = $row['status'] === 'completed' ? 'Full Completed' : 'Full Active';
                                        $statusClass = 'text-warning';
                                    }
                                    ?>
                                    <div class="row-item">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                            <div class="small text-muted">
                                                <?php echo $modeLabel; ?>
                                                <?php if (!empty($row['practice_mode'])): ?>
                                                    · Part <?php echo htmlspecialchars((string)$row['target_part']); ?>
                                                <?php endif; ?>
                                                · <?php echo htmlspecialchars(ucfirst((string)$row['current_section'])); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusText); ?></div>
                                            <div class="small text-muted"><?php echo date('d M Y H:i', strtotime($row['completed_at'] ?: $row['started_at'])); ?></div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
