<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$uid = getUsersIdColumn($conn);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.username LIKE ? OR r.test_session LIKE ?)";
    $like = "%{$search}%";
    $params = [$like, $like, $like];
    $types = 'sss';
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "
    SELECT COUNT(*) AS total
    FROM toeic_test_results r
    JOIN users u ON r.user_id = u.{$uid}
    LEFT JOIN toeic_test_sessions s ON s.test_session = r.test_session
    $whereSql
";
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_rows = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$sql = "
    SELECT r.*, u.full_name, u.username, s.practice_mode, s.target_part
    FROM toeic_test_results r
    JOIN users u ON r.user_id = u.{$uid}
    LEFT JOIN toeic_test_sessions s ON s.test_session = r.test_session
    $whereSql
    ORDER BY r.completed_at DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stats = $conn->query("
    SELECT
        COUNT(*) AS total_tests,
        AVG(total_score) AS avg_score,
        MAX(total_score) AS best_score,
        COUNT(DISTINCT user_id) AS unique_users
    FROM toeic_test_results
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Results - <?php echo htmlspecialchars($website_title); ?></title>
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
                        <h1 class="fw-bold mb-1">TOEIC Results</h1>
                        <p class="text-muted mb-0">Riwayat laporan TOEIC yang sudah selesai, termasuk run CLI MiniMax.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="test_sessions.php" class="btn btn-outline-secondary">TOEIC Sessions</a>
                        <a href="proctoring_sessions.php" class="btn btn-outline-primary">Proctoring</a>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3"><div class="stats-card"><h6>Total Reports</h6><h3><?php echo (int)$stats['total_tests']; ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Average Score</h6><h3><?php echo (int)round($stats['avg_score'] ?? 0); ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Best Score</h6><h3><?php echo (int)($stats['best_score'] ?? 0); ?></h3></div></div>
                    <div class="col-md-3"><div class="stats-card"><h6>Unique Users</h6><h3><?php echo (int)$stats['unique_users']; ?></h3></div></div>
                </div>

                <div class="content-card mb-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-10">
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nama siswa, username, atau session">
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
                                    <th>Session</th>
                                    <th>Listening</th>
                                    <th>Reading</th>
                                    <th>Total</th>
                                    <th>CEFR</th>
                                    <th>Selesai</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada hasil TOEIC.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($results as $row): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($row['username']); ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['practice_mode'])): ?>
                                                    <span class="badge bg-info text-dark">Practice<?php echo !empty($row['target_part']) ? ' · Part ' . htmlspecialchars((string)$row['target_part']) : ''; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary">Full</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($row['test_session']); ?></code></td>
                                            <td><?php echo (int)$row['listening_scaled']; ?></td>
                                            <td><?php echo (int)$row['reading_scaled']; ?></td>
                                            <td><strong><?php echo (int)$row['total_score']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['cefr_level']); ?></td>
                                            <td><?php echo date('d M Y H:i', strtotime($row['completed_at'])); ?></td>
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
                                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
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
