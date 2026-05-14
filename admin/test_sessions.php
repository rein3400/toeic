<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/proctor_helper.php';
require_once '../includes/toeic_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$uid = getUsersIdColumn($conn);
ensureTOEICSessionModeColumns($conn);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');
$mode_filter = $_GET['mode'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.username LIKE ? OR s.test_session LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}

if ($mode_filter === 'full') {
    $where[] = "COALESCE(s.practice_mode, 0) = 0";
} elseif ($mode_filter === 'practice') {
    $where[] = "COALESCE(s.practice_mode, 0) = 1";
}

if ($status_filter === 'active') {
    $where[] = "s.status = 'active' AND (p.status IS NULL OR p.status <> 'terminated')";
} elseif ($status_filter === 'completed') {
    $where[] = "s.status = 'completed'";
} elseif ($status_filter === 'terminated') {
    $where[] = "p.status = 'terminated'";
} elseif ($status_filter === 'cleared') {
    $where[] = "p.review_status = 'cleared'";
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "
    SELECT COUNT(*) AS total
    FROM toeic_test_sessions s
    JOIN users u ON s.user_id = u.{$uid}
    LEFT JOIN proctoring_sessions p ON p.test_session = s.test_session
    $whereSql
";
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_rows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$sql = "
    SELECT
        s.*,
        u.full_name,
        u.username,
        r.total_score,
        r.cefr_level,
        r.completed_at AS report_completed_at,
        p.integrity_score,
        p.status AS proctor_status,
        p.review_status,
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
    $whereSql
    ORDER BY COALESCE(s.completed_at, s.started_at) DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary = [
    'full_reports' => 0,
    'active_full' => 0,
    'active_practice' => 0,
    'completed_practice' => 0,
    'terminated_proctor' => 0,
];

$summary['full_reports'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_results")->fetch_assoc()['total'] ?? 0);
$summary['active_full'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'active' AND COALESCE(practice_mode, 0) = 0")->fetch_assoc()['total'] ?? 0);
$summary['active_practice'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'active' AND COALESCE(practice_mode, 0) = 1")->fetch_assoc()['total'] ?? 0);
$summary['completed_practice'] = (int)($conn->query("SELECT COUNT(*) AS total FROM toeic_test_sessions WHERE status = 'completed' AND COALESCE(practice_mode, 0) = 1")->fetch_assoc()['total'] ?? 0);
$summary['terminated_proctor'] = (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_sessions WHERE status = 'terminated'")->fetch_assoc()['total'] ?? 0);

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
        'voucher' => ['label' => 'Voucher', 'class' => 'bg-info text-dark'],
        'free_trial' => ['label' => 'Free Trial', 'class' => 'bg-warning text-dark'],
        'direct_bank' => ['label' => 'Direct Bank', 'class' => 'bg-success'],
        'direct_checkout' => ['label' => 'Direct Checkout', 'class' => 'bg-primary'],
        default => ['label' => 'Unknown', 'class' => 'bg-secondary'],
    };
}

function adminSessionStatusLabel(array $row): array {
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
                        <p class="text-muted mb-0">Pantau full simulation, practice mode, dan status runtime sesi TOEIC.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="test_results.php" class="btn btn-outline-secondary">Full Results</a>
                        <a href="proctoring_sessions.php" class="btn btn-outline-primary">Proctoring</a>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-2"><div class="stats-card"><h6>Full Reports</h6><h3><?php echo $summary['full_reports']; ?></h3></div></div>
                    <div class="col-md-2"><div class="stats-card"><h6>Active Full</h6><h3><?php echo $summary['active_full']; ?></h3></div></div>
                    <div class="col-md-2"><div class="stats-card"><h6>Active Practice</h6><h3><?php echo $summary['active_practice']; ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Completed Practice</h6><h3><?php echo $summary['completed_practice']; ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Proctor Terminated</h6><h3><?php echo $summary['terminated_proctor']; ?></h3></div></div>
                </div>

                <div class="content-card mb-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-5">
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nama, username, atau session">
                        </div>
                        <div class="col-md-2">
                            <select name="mode" class="form-select">
                                <option value="all" <?php echo $mode_filter === 'all' ? 'selected' : ''; ?>>All Modes</option>
                                <option value="full" <?php echo $mode_filter === 'full' ? 'selected' : ''; ?>>Full</option>
                                <option value="practice" <?php echo $mode_filter === 'practice' ? 'selected' : ''; ?>>Practice</option>
                            </select>
                        </div>
                        <div class="col-md-3">
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
                                    <tr><td colspan="10" class="text-center text-muted py-4">Belum ada sesi TOEIC yang cocok dengan filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sessions as $row): ?>
                                        <?php $statusBadge = adminSessionStatusLabel($row); ?>
                                        <?php $checkoutBadge = adminSessionCheckoutLabel($row); ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($row['username']); ?></div>
                                                <div class="small text-muted"><code><?php echo htmlspecialchars($row['test_session']); ?></code></div>
                                            </td>
                                            <td><span class="badge bg-dark"><?php echo adminSessionModeLabel($row); ?></span></td>
                                            <td>
                                                <span class="badge <?php echo $checkoutBadge['class']; ?>"><?php echo htmlspecialchars($checkoutBadge['label']); ?></span>
                                                <?php if (!empty($row['checkout_reference'])): ?>
                                                    <div class="small text-muted mt-1"><code><?php echo htmlspecialchars((string)$row['checkout_reference']); ?></code></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['practice_mode'])): ?>
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
                                                <?php if (!empty($row['practice_mode'])): ?>
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
                                                <?php if (!empty($row['practice_mode'])): ?>
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
                                            <td><a href="view_result.php?session=<?php echo urlencode($row['test_session']); ?>" class="btn btn-sm btn-outline-primary">View</a></td>
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
                                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&mode=<?php echo urlencode($mode_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
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
