<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/proctor_helper.php';
require_once '../includes/toeic_helper.php';
require_once '../includes/toeic_sw_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$uid = getUsersIdColumn($conn);
ensureTOEICSessionModeColumns($conn);
ensureToeicSwSchema($conn);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');
$format_filter = $_GET['format'] ?? 'all';
$mode_filter = $_GET['mode'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

if (!in_array($format_filter, ['all', 'toeic', 'toeic_sw'], true)) {
    $format_filter = 'all';
}

$include_toeic = in_array($format_filter, ['all', 'toeic'], true);
$include_toeic_sw = in_array($format_filter, ['all', 'toeic_sw'], true);

$lr_where = [];
$lr_params = [];
$lr_types = '';
$sw_where = [];
$sw_params = [];
$sw_types = '';

if ($search !== '') {
    $lr_where[] = "(u.full_name LIKE ? OR u.username LIKE ? OR s.test_session LIKE ?)";
    $sw_where[] = "(u.full_name LIKE ? OR u.username LIKE ? OR s.test_session LIKE ?)";
    $like = '%' . $search . '%';
    array_push($lr_params, $like, $like, $like);
    array_push($sw_params, $like, $like, $like);
    $lr_types .= 'sss';
    $sw_types .= 'sss';
}

if ($mode_filter === 'full') {
    $lr_where[] = "COALESCE(s.practice_mode, 0) = 0";
    $sw_where[] = "COALESCE(s.practice_mode, 0) = 0";
} elseif ($mode_filter === 'practice') {
    $lr_where[] = "COALESCE(s.practice_mode, 0) = 1";
    $sw_where[] = "COALESCE(s.practice_mode, 0) = 1";
}

if ($status_filter === 'active') {
    $lr_where[] = "s.status = 'active' AND (p.status IS NULL OR p.status <> 'terminated')";
    $sw_where[] = "s.status = 'active'";
} elseif ($status_filter === 'completed') {
    $lr_where[] = "s.status = 'completed'";
    $sw_where[] = "s.status = 'completed'";
} elseif ($status_filter === 'terminated') {
    $lr_where[] = "p.status = 'terminated'";
    $include_toeic_sw = false;
} elseif ($status_filter === 'cleared') {
    $lr_where[] = "p.review_status = 'cleared'";
    $include_toeic_sw = false;
}

$lr_where_sql = !empty($lr_where) ? 'WHERE ' . implode(' AND ', $lr_where) : '';
$sw_where_sql = !empty($sw_where) ? 'WHERE ' . implode(' AND ', $sw_where) : '';

function adminTestSessionCount(mysqli $conn, string $sql, string $types, array $params): int {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total;
}

$total_rows = 0;
if ($include_toeic) {
    $total_rows += adminTestSessionCount($conn, "
        SELECT COUNT(*) AS total
        FROM toeic_test_sessions s
        JOIN users u ON s.user_id = u.{$uid}
        LEFT JOIN proctoring_sessions p ON p.test_session = s.test_session
        $lr_where_sql
    ", $lr_types, $lr_params);
}
if ($include_toeic_sw) {
    $total_rows += adminTestSessionCount($conn, "
        SELECT COUNT(*) AS total
        FROM toeic_sw_test_sessions s
        JOIN users u ON s.user_id = u.{$uid}
        $sw_where_sql
    ", $sw_types, $sw_params);
}
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$select_parts = [];
$select_params = [];
$select_types = '';

if ($include_toeic) {
    $select_parts[] = "
        SELECT
            'toeic' COLLATE utf8mb4_general_ci AS test_format,
            NULL AS package_number,
            CONVERT(s.test_session USING utf8mb4) COLLATE utf8mb4_general_ci AS test_session,
            s.user_id,
            s.practice_mode,
            CONVERT(s.target_part USING utf8mb4) COLLATE utf8mb4_general_ci AS target_part,
            CONVERT(s.checkout_source USING utf8mb4) COLLATE utf8mb4_general_ci AS checkout_source,
            CONVERT(s.checkout_reference USING utf8mb4) COLLATE utf8mb4_general_ci AS checkout_reference,
            CONVERT(s.current_section USING utf8mb4) COLLATE utf8mb4_general_ci AS current_section,
            CONVERT(s.status USING utf8mb4) COLLATE utf8mb4_general_ci AS status,
            s.started_at,
            s.completed_at,
            COALESCE(s.completed_at, s.started_at) AS sort_at,
            CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci AS full_name,
            CONVERT(u.username USING utf8mb4) COLLATE utf8mb4_general_ci AS username,
            r.total_score,
            CONVERT(r.cefr_level USING utf8mb4) COLLATE utf8mb4_general_ci AS cefr_level,
            NULL AS speaking_scaled,
            NULL AS writing_scaled,
            p.integrity_score,
            CONVERT(p.status USING utf8mb4) COLLATE utf8mb4_general_ci AS proctor_status,
            CONVERT(p.review_status USING utf8mb4) COLLATE utf8mb4_general_ci AS review_status,
            p.camera_granted,
            p.microphone_granted,
            qa.accuracy
        FROM toeic_test_sessions s
        JOIN users u ON s.user_id = u.{$uid}
        LEFT JOIN toeic_test_results r ON r.test_session = s.test_session
        LEFT JOIN proctoring_sessions p ON p.test_session = s.test_session
        LEFT JOIN (
            SELECT
                test_session,
                ROUND(100 * SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 1) AS accuracy
            FROM toeic_test_questions
            GROUP BY test_session
        ) qa ON qa.test_session = s.test_session
        $lr_where_sql
    ";
    $select_params = array_merge($select_params, $lr_params);
    $select_types .= $lr_types;
}

if ($include_toeic_sw) {
    $select_parts[] = "
        SELECT
            'toeic_sw' COLLATE utf8mb4_general_ci AS test_format,
            s.package_number,
            CONVERT(s.test_session USING utf8mb4) COLLATE utf8mb4_general_ci AS test_session,
            s.user_id,
            s.practice_mode,
            CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS target_part,
            'toeic_sw' COLLATE utf8mb4_general_ci AS checkout_source,
            CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS checkout_reference,
            CONVERT(s.current_section USING utf8mb4) COLLATE utf8mb4_general_ci AS current_section,
            CONVERT(s.status USING utf8mb4) COLLATE utf8mb4_general_ci AS status,
            s.started_at,
            s.completed_at,
            COALESCE(s.completed_at, s.started_at) AS sort_at,
            CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci AS full_name,
            CONVERT(u.username USING utf8mb4) COLLATE utf8mb4_general_ci AS username,
            COALESCE(r.total_score, s.total_score) AS total_score,
            CONVERT(COALESCE(r.cefr_level, s.cefr_level) USING utf8mb4) COLLATE utf8mb4_general_ci AS cefr_level,
            COALESCE(r.speaking_scaled, s.speaking_scaled) AS speaking_scaled,
            COALESCE(r.writing_scaled, s.writing_scaled) AS writing_scaled,
            NULL AS integrity_score,
            CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS proctor_status,
            CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS review_status,
            NULL AS camera_granted,
            NULL AS microphone_granted,
            NULL AS accuracy
        FROM toeic_sw_test_sessions s
        JOIN users u ON s.user_id = u.{$uid}
        LEFT JOIN toeic_sw_test_results r ON r.test_session = s.test_session
        $sw_where_sql
    ";
    $select_params = array_merge($select_params, $sw_params);
    $select_types .= $sw_types;
}

$sessions = [];
if (!empty($select_parts)) {
    $sql = "
        SELECT *
        FROM (
            " . implode("\nUNION ALL\n", $select_parts) . "
        ) combined_sessions
        ORDER BY sort_at DESC
        LIMIT $per_page OFFSET $offset
    ";
    $stmt = $conn->prepare($sql);
    if ($select_types !== '') {
        $stmt->bind_param($select_types, ...$select_params);
    }
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$summary = [
    'full_reports' => 0,
    'active_full' => 0,
    'active_practice' => 0,
    'completed_practice' => 0,
    'sw_sessions' => 0,
    'terminated_proctor' => 0,
];

$summary['full_reports'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_results")->fetch_assoc()['total'] ?? 0);
$summary['active_full'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'active' AND COALESCE(practice_mode, 0) = 0")->fetch_assoc()['total'] ?? 0);
$summary['active_practice'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'active' AND COALESCE(practice_mode, 0) = 1")->fetch_assoc()['total'] ?? 0);
$summary['completed_practice'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'completed' AND COALESCE(practice_mode, 0) = 1")->fetch_assoc()['total'] ?? 0);
$summary['sw_sessions'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_sw_test_sessions")->fetch_assoc()['total'] ?? 0);
$summary['terminated_proctor'] = (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_sessions WHERE status = 'terminated'")->fetch_assoc()['total'] ?? 0);

function adminSessionFormatLabel(array $row): array {
    return (($row['test_format'] ?? 'toeic') === 'toeic_sw')
        ? ['label' => 'TOEIC SW', 'class' => 'bg-info text-dark']
        : ['label' => 'TOEIC LR', 'class' => 'bg-secondary'];
}

function adminSessionModeLabel(array $row): string {
    return !empty($row['practice_mode']) ? 'Practice' : 'Full';
}

function adminSessionCheckoutLabel(array $row): array {
    $source = (string)($row['checkout_source'] ?? '');
    $reference = (string)($row['checkout_reference'] ?? '');
    if ($source === '' && $reference !== '') {
        $source = toeicCreditCheckoutSource($reference)['source'];
    }

    return match ($source) {
        'toeic_sw' => ['label' => 'TOEIC SW', 'class' => 'bg-info text-dark'],
        'voucher' => ['label' => 'Voucher', 'class' => 'bg-info text-dark'],
        'free_trial' => ['label' => 'Free Trial', 'class' => 'bg-warning text-dark'],
        'direct_bank' => ['label' => 'Direct Bank', 'class' => 'bg-success'],
        'direct_checkout' => ['label' => 'Direct Checkout', 'class' => 'bg-primary'],
        default => ['label' => 'Unknown', 'class' => 'bg-secondary'],
    };
}

function adminSessionStatusLabel(array $row): array {
    if (($row['test_format'] ?? 'toeic') === 'toeic_sw') {
        if ($row['status'] === 'completed') {
            return ['label' => 'SW Completed', 'class' => 'bg-primary'];
        }
        if ($row['status'] === 'cancelled') {
            return ['label' => 'SW Cancelled', 'class' => 'bg-secondary'];
        }
        $section = ucfirst((string)($row['current_section'] ?? 'speaking'));
        return ['label' => 'SW ' . $section . ' Active', 'class' => 'bg-warning text-dark'];
    }
    if (($row['proctor_status'] ?? '') === 'terminated') {
        return ['label' => 'Proctor Terminated', 'class' => 'bg-danger'];
    }
    if (($row['review_status'] ?? '') === 'cleared') {
        return ['label' => 'Cleared', 'class' => 'bg-success'];
    }
    if (!empty($row['practice_mode'])) {
        return [
            'label' => ($row['status'] === 'completed') ? 'Practice Completed' : 'Practice Active',
            'class' => ($row['status'] === 'completed') ? 'bg-primary' : 'bg-warning text-dark'
        ];
    }
    if ($row['status'] === 'completed' && !empty($row['total_score'])) {
        return ['label' => 'Full Completed', 'class' => 'bg-primary'];
    }
    if (($row['camera_granted'] ?? 0) && ($row['microphone_granted'] ?? 0)) {
        return ['label' => 'Full Active', 'class' => 'bg-warning text-dark'];
    }
    return ['label' => 'Setup Incomplete', 'class' => 'bg-secondary'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Sessions - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1">TOEIC Sessions</h1>
                        <p class="text-muted mb-0">Pantau sesi TOEIC LR dan TOEIC SW dari satu daftar operasional.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="test_results.php" class="btn btn-outline-secondary">Full Results</a>
                        <a href="toeic_sw_results.php" class="btn btn-outline-secondary">SW Results</a>
                        <a href="proctoring_sessions.php" class="btn btn-outline-primary">Proctoring</a>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-2"><div class="stats-card"><h6>LR Reports</h6><h3><?php echo $summary['full_reports']; ?></h3></div></div>
                    <div class="col-md-2"><div class="stats-card"><h6>LR Active Full</h6><h3><?php echo $summary['active_full']; ?></h3></div></div>
                    <div class="col-md-2"><div class="stats-card"><h6>LR Active Practice</h6><h3><?php echo $summary['active_practice']; ?></h3></div></div>
                    <div class="col-md-2"><div class="stats-card"><h6>LR Completed Practice</h6><h3><?php echo $summary['completed_practice']; ?></h3></div></div>
                    <div class="col-md-2"><div class="stats-card"><h6>SW Sessions</h6><h3><?php echo $summary['sw_sessions']; ?></h3></div></div>
                    <div class="col-md-2"><div class="stats-card"><h6>Proctor Terminated</h6><h3><?php echo $summary['terminated_proctor']; ?></h3></div></div>
                </div>

                <div class="content-card mb-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nama, username, atau session">
                        </div>
                        <div class="col-md-2">
                            <select name="format" class="form-select">
                                <option value="all" <?php echo $format_filter === 'all' ? 'selected' : ''; ?>>All Formats</option>
                                <option value="toeic" <?php echo $format_filter === 'toeic' ? 'selected' : ''; ?>>TOEIC LR</option>
                                <option value="toeic_sw" <?php echo $format_filter === 'toeic_sw' ? 'selected' : ''; ?>>TOEIC SW</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="mode" class="form-select">
                                <option value="all" <?php echo $mode_filter === 'all' ? 'selected' : ''; ?>>All Modes</option>
                                <option value="full" <?php echo $mode_filter === 'full' ? 'selected' : ''; ?>>Full</option>
                                <option value="practice" <?php echo $mode_filter === 'practice' ? 'selected' : ''; ?>>Practice</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="terminated" <?php echo $status_filter === 'terminated' ? 'selected' : ''; ?>>Proctor Terminated</option>
                                <option value="cleared" <?php echo $status_filter === 'cleared' ? 'selected' : ''; ?>>Cleared</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>

                <div class="content-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Siswa</th>
                                    <th>Format</th>
                                    <th>Mode</th>
                                    <th>Checkout Source</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th>Current Section</th>
                                    <th>Score / Accuracy</th>
                                    <th>Proctor</th>
                                    <th>Started</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sessions)): ?>
                                    <tr><td colspan="11" class="text-center text-muted py-4">Belum ada sesi TOEIC yang cocok dengan filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sessions as $row): ?>
                                        <?php $statusBadge = adminSessionStatusLabel($row); ?>
                                        <?php $checkoutBadge = adminSessionCheckoutLabel($row); ?>
                                        <?php $formatBadge = adminSessionFormatLabel($row); ?>
                                        <?php $isSwSession = ($row['test_format'] ?? 'toeic') === 'toeic_sw'; ?>
                                        <?php $detailUrl = $isSwSession ? 'toeic_sw_result_detail.php?session=' . urlencode($row['test_session']) : 'view_result.php?session=' . urlencode($row['test_session']); ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($row['username']); ?></div>
                                                <div class="small text-muted"><code><?php echo htmlspecialchars($row['test_session']); ?></code></div>
                                            </td>
                                            <td><span class="badge <?php echo $formatBadge['class']; ?>"><?php echo htmlspecialchars($formatBadge['label']); ?></span></td>
                                            <td><span class="badge bg-dark"><?php echo adminSessionModeLabel($row); ?></span></td>
                                            <td>
                                                <span class="badge <?php echo $checkoutBadge['class']; ?>"><?php echo htmlspecialchars($checkoutBadge['label']); ?></span>
                                                <?php if (!empty($row['checkout_reference'])): ?>
                                                    <div class="small text-muted mt-1"><code><?php echo htmlspecialchars((string)$row['checkout_reference']); ?></code></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isSwSession): ?>
                                                    <div class="fw-semibold">Package <?php echo (int)($row['package_number'] ?? 0); ?></div>
                                                    <div class="small text-muted">Speaking &amp; Writing</div>
                                                <?php elseif (!empty($row['practice_mode'])): ?>
                                                    <?php if (!empty($row['target_part'])): ?>
                                                        <div class="fw-semibold">Part <?php echo htmlspecialchars((string)$row['target_part']); ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Full 200 soal</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Full 200 soal</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?php echo $statusBadge['class']; ?>"><?php echo htmlspecialchars($statusBadge['label']); ?></span></td>
                                            <td><?php echo htmlspecialchars(ucfirst((string)$row['current_section'])); ?></td>
                                            <td>
                                                <?php if ($isSwSession): ?>
                                                    <?php if ($row['speaking_scaled'] !== null || $row['writing_scaled'] !== null || $row['total_score'] !== null): ?>
                                                        <div>S <?php echo $row['speaking_scaled'] !== null ? (int)$row['speaking_scaled'] : '-'; ?> / W <?php echo $row['writing_scaled'] !== null ? (int)$row['writing_scaled'] : '-'; ?></div>
                                                        <div class="small text-muted">Total <?php echo $row['total_score'] !== null ? (int)$row['total_score'] : '-'; ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                <?php elseif (!empty($row['practice_mode'])): ?>
                                                    <?php if (!empty($row['target_part'])): ?>
                                                        <?php echo $row['accuracy'] !== null ? htmlspecialchars((string)$row['accuracy']) . '%' : '<span class="text-muted">-</span>'; ?>
                                                    <?php else: ?>
                                                        <?php echo $row['total_score'] !== null ? (int)$row['total_score'] : ($row['accuracy'] !== null ? htmlspecialchars((string)$row['accuracy']) . '%' : '<span class="text-muted">-</span>'); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php echo $row['total_score'] !== null ? (int)$row['total_score'] : '<span class="text-muted">-</span>'; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isSwSession): ?>
                                                    <span class="text-muted">No Proctor</span>
                                                <?php elseif (!empty($row['practice_mode'])): ?>
                                                    <span class="text-muted">No Proctor</span>
                                                <?php elseif ($row['proctor_status'] === null): ?>
                                                    <span class="text-muted">Not Started</span>
                                                <?php elseif ((int)$row['camera_granted'] === 1 && (int)$row['microphone_granted'] === 1): ?>
                                                    <span class="text-success">Ready</span>
                                                <?php else: ?>
                                                    <span class="text-warning">Incomplete</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M Y H:i', strtotime($row['started_at'])); ?></td>
                                            <td><a href="<?php echo htmlspecialchars($detailUrl); ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination justify-content-center mb-0">
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&format=<?php echo urlencode($format_filter); ?>&mode=<?php echo urlencode($mode_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
