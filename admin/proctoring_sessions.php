<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/db_utils.php';
require_once '../includes/proctor_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$filter_format = $_GET['format'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$uid = getUsersIdColumn($conn);
$integrity_threshold = getProctoringIntegrityThreshold();

$where_clauses = ["1=1"];
$params = [];
$types = '';

if ($filter_format !== 'all') {
    $where_clauses[] = "ps.test_format = ?";
    $params[] = $filter_format;
    $types .= 's';
}

if ($filter_status !== 'all') {
    if ($filter_status === 'terminated') {
        $where_clauses[] = "ps.status = 'terminated'";
    } elseif ($filter_status === 'active') {
        $where_clauses[] = "ps.status = 'active' AND ps.camera_granted = 1 AND ps.microphone_granted = 1";
    } elseif ($filter_status === 'reviewed') {
        $where_clauses[] = "ps.review_status IS NOT NULL";
    }
}

if ($search !== '') {
    $where_clauses[] = "(u.full_name LIKE ? OR ps.test_session LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

$where_sql = implode(' AND ', $where_clauses);

$count_query = "SELECT COUNT(*) as total FROM proctoring_sessions ps JOIN users u ON ps.user_id = u.{$uid} WHERE $where_sql";
$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_rows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$total_pages = max(1, (int)ceil($total_rows / $limit));

$query = "
    SELECT
        ps.id,
        u.full_name,
        ps.test_session,
        ps.test_format,
        ps.integrity_score,
        ps.status,
        ps.started_at,
        ps.review_status,
        ps.camera_granted,
        ps.microphone_granted,
        ps.sync_failures,
        (SELECT COUNT(*) FROM proctoring_events pe WHERE pe.session_id = ps.id) as event_count
    FROM proctoring_sessions ps
    JOIN users u ON ps.user_id = u.{$uid}
    WHERE $where_sql
    ORDER BY ps.started_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Proctoring Sessions | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .score-high { color: #10b981; }
        .score-med  { color: #f59e0b; }
        .score-low  { color: #ef4444; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">TOEIC Admin</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link active" href="proctoring_sessions.php">Proctoring</a>
                <a class="nav-link" href="proctoring_analytics.php">Analytics</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Proctoring Sessions</h2>
                <div class="text-muted small">Integrity threshold aktif: <?php echo $integrity_threshold; ?></div>
            </div>
            <a href="proctoring_analytics.php" class="btn btn-outline-primary">
                <i class="fas fa-chart-line me-2"></i>Analytics
            </a>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Format</label>
                        <select name="format" class="form-select">
                            <option value="all">All Formats</option>
                            <option value="toeic" <?php echo $filter_format === 'toeic' ? 'selected' : ''; ?>>TOEIC</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all">All Status</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active (Setup Ready)</option>
                            <option value="terminated" <?php echo $filter_status === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                            <option value="reviewed" <?php echo $filter_status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Student Name or Session ID" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Student</th>
                            <th>Format</th>
                            <th>Setup</th>
                            <th>Score</th>
                            <th>Violations</th>
                            <th>Status</th>
                            <th>Started At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No sessions found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $s): ?>
                                <?php
                                $score_class = 'score-high';
                                if ($s['status'] === 'terminated' || (int)$s['integrity_score'] < $integrity_threshold) {
                                    $score_class = 'score-low';
                                } elseif ((int)$s['integrity_score'] < 70) {
                                    $score_class = 'score-med';
                                }
                                $format_label = strtoupper((string)$s['test_format']);
                                ?>
                                <tr>
                                    <td>#<?php echo (int)$s['id']; ?></td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($s['full_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($s['test_session']); ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo $format_label; ?></span></td>
                                    <td>
                                        <?php if ((int)$s['camera_granted'] === 1 && (int)$s['microphone_granted'] === 1): ?>
                                            <span class="badge bg-success">Ready</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Incomplete</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="<?php echo $score_class; ?> fs-5"><?php echo (int)$s['integrity_score']; ?>%</span></td>
                                    <td><?php echo (int)$s['event_count']; ?></td>
                                    <td>
                                        <?php if ($s['status'] === 'terminated'): ?>
                                            <span class="badge bg-danger">TERMINATED</span>
                                        <?php elseif ($s['review_status'] === 'cleared'): ?>
                                            <span class="badge bg-success">CLEARED</span>
                                        <?php elseif ((int)$s['camera_granted'] !== 1 || (int)$s['microphone_granted'] !== 1): ?>
                                            <span class="badge bg-secondary">SETUP</span>
                                        <?php elseif (!empty($s['review_status'])): ?>
                                            <span class="badge bg-info text-dark"><?php echo strtoupper((string)$s['review_status']); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">ACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, H:i', strtotime($s['started_at'])); ?></td>
                                    <td>
                                        <a href="proctoring_review.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-outline-primary">Review</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav>
                        <ul class="pagination justify-content-center mb-0">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&format=<?php echo urlencode($filter_format); ?>&status=<?php echo urlencode($filter_status); ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
