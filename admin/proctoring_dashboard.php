<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/proctor_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$integrity_threshold = getProctoringIntegrityThreshold();

$uid = getUsersIdColumn($conn);

$filter = $_GET['filter'] ?? 'all';
$where = "1=1";
if ($filter === 'flagged') $where .= " AND (status = 'terminated' OR integrity_score < $integrity_threshold)";
if ($filter === 'clean') $where .= " AND status = 'active' AND integrity_score >= $integrity_threshold";
if ($filter === 'terminated') $where .= " AND status = 'terminated'";
if ($filter === 'cleared') $where .= " AND review_status = 'cleared'";

$sql = "
    SELECT ps.*, u.username, u.full_name,
    (SELECT COUNT(*) FROM proctoring_events pe WHERE pe.session_id = ps.id AND severity IN ('high','critical')) as high_severity_count
    FROM proctoring_sessions ps
    JOIN users u ON ps.user_id = u.{$uid}
    WHERE $where
    ORDER BY ps.started_at DESC
    LIMIT 100
";
$result = $conn->query($sql);

// Quick stats
$stats = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(status = 'terminated' OR integrity_score < $integrity_threshold) as flagged,
        SUM(status = 'terminated') as `terminated`,
        SUM((review_status = 'pending' AND (status = 'terminated' OR integrity_score < $integrity_threshold))) as pending_review
    FROM proctoring_sessions
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Proctoring Dashboard</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .score-badge { font-weight: bold; min-width: 48px; text-align: center; }
        .score-high { background-color: var(--success-light); color: var(--success); }
        .score-med  { background-color: var(--warning-light); color: var(--warning); }
        .score-low  { background-color: var(--danger-light); color: var(--danger); }
        .stat-card  { border-radius: 12px; padding: 20px; text-align: center; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 admin-content">
            <div class="admin-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h1><i class="fas fa-user-shield me-3"></i>Proctoring Dashboard</h1>
                    <a href="proctoring_settings.php" class="btn btn-light btn-sm">
                        <i class="fas fa-cog me-1"></i>Pengaturan
                    </a>
                </div>
            </div>

            <div class="p-4">

                <!-- Stats Row -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card shadow-sm border">
                            <div class="h3 mb-1"><?php echo (int)$stats['total']; ?></div>
                            <div class="text-muted small">Total Sesi</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-warning bg-opacity-10 border border-warning shadow-sm">
                            <div class="h3 mb-1 text-warning"><?php echo (int)$stats['flagged']; ?></div>
                            <div class="text-muted small">Below threshold</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-danger bg-opacity-10 border border-danger shadow-sm">
                            <div class="h3 mb-1 text-danger"><?php echo (int)$stats['terminated']; ?></div>
                            <div class="text-muted small">Dihentikan</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-primary bg-opacity-10 border border-primary shadow-sm">
                            <div class="h3 mb-1 text-primary"><?php echo (int)$stats['pending_review']; ?></div>
                            <div class="text-muted small">Perlu Review</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="card shadow-sm mb-3">
                    <div class="card-body py-2">
                        <div class="btn-group">
                            <a href="?filter=all"        class="btn btn-sm btn-outline-secondary <?php echo $filter==='all'?'active':''; ?>">Semua</a>
                            <a href="?filter=flagged"    class="btn btn-sm btn-outline-warning  <?php echo $filter==='flagged'?'active':''; ?>">Pelanggaran</a>
                            <a href="?filter=terminated" class="btn btn-sm btn-outline-danger   <?php echo $filter==='terminated'?'active':''; ?>">Dihentikan</a>
                            <a href="?filter=cleared"    class="btn btn-sm btn-outline-success  <?php echo $filter==='cleared'?'active':''; ?>">Diizinkan</a>
                            <a href="?filter=clean"      class="btn btn-sm btn-outline-success  <?php echo $filter==='clean'?'active':''; ?>">Bersih</a>
                        </div>
                    </div>
                </div>

                <!-- Sessions Table -->
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Status</th>
                                    <th>Peserta</th>
                                    <th>Sesi</th>
                                    <th>Integrity Score</th>
                                    <th>Flags</th>
                                    <th>Waktu</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while ($row = $result->fetch_assoc()):
                                $score = $row['integrity_score'];
                                $scoreClass = $score >= 90 ? 'score-high' : (($row['status'] === 'terminated' || $score < $integrity_threshold) ? 'score-low' : 'score-med');
                                $statusBadge = match($row['review_status']) {
                                    'reviewed' => '<span class="badge bg-primary">Reviewed</span>',
                                    'cleared'  => '<span class="badge bg-success">Diizinkan</span>',
                                    'flagged'  => '<span class="badge bg-danger">Flagged</span>',
                                    default    => '<span class="badge bg-secondary">Pending</span>',
                                };
                                if ($row['status'] === 'terminated') {
                                    $statusBadge .= ' <span class="badge bg-dark">Terminated</span>';
                                }
                            ?>
                            <tr>
                                <td><?php echo $statusBadge; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['username']); ?></small>
                                </td>
                                <td>
                                    <code class="small"><?php echo htmlspecialchars(substr($row['test_session'], 0, 24)); ?>...</code><br>
                                    <small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $row['test_format'])); ?></small>
                                </td>
                                <td>
                                    <span class="badge <?php echo $scoreClass; ?> score-badge"><?php echo $score; ?>%</span>
                                </td>
                                <td>
                                    <?php if ($row['high_severity_count'] > 0): ?>
                                        <span class="text-danger fw-bold"><i class="fas fa-exclamation-triangle"></i> <?php echo $row['high_severity_count']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?php echo date('d M, H:i', strtotime($row['started_at'])); ?></td>
                                <td>
                                    <a href="proctoring_review.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-search me-1"></i>Review
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /p-4 -->
        </div><!-- /admin-content -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
