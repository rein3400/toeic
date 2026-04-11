<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/proctor_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$integrity_threshold = getProctoringIntegrityThreshold();

$metrics = [];
$metrics['total_sessions'] = (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_sessions")->fetch_assoc()['total'] ?? 0);
$metrics['terminated'] = (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_sessions WHERE status = 'terminated'")->fetch_assoc()['total'] ?? 0);
$metrics['cleared'] = (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_sessions WHERE review_status = 'cleared'")->fetch_assoc()['total'] ?? 0);
$metrics['avg_score'] = round((float)($conn->query("SELECT AVG(integrity_score) AS avg_score FROM proctoring_sessions")->fetch_assoc()['avg_score'] ?? 0), 1);
$metrics['total_violations'] = (int)($conn->query("SELECT COUNT(*) AS total FROM proctoring_events")->fetch_assoc()['total'] ?? 0);

$violations_data = [];
$violations_res = $conn->query("SELECT event_type, COUNT(*) as count FROM proctoring_events GROUP BY event_type ORDER BY count DESC");
while ($violations_res && ($row = $violations_res->fetch_assoc())) {
    $violations_data[] = $row;
}

$scores_data = [
    '90-100' => 0,
    '70-89' => 0,
    $integrity_threshold . '-69' => 0,
    '0-' . max(0, $integrity_threshold - 1) => 0,
];
$score_rows = $conn->query("SELECT integrity_score, status FROM proctoring_sessions");
while ($score_rows && ($row = $score_rows->fetch_assoc())) {
    $score = (int)$row['integrity_score'];
    if ($row['status'] === 'terminated' || $score < $integrity_threshold) {
        $scores_data['0-' . max(0, $integrity_threshold - 1)]++;
    } elseif ($score >= 90) {
        $scores_data['90-100']++;
    } elseif ($score >= 70) {
        $scores_data['70-89']++;
    } else {
        $scores_data[$integrity_threshold . '-69']++;
    }
}

$trends_data = [];
$trends_res = $conn->query("
    SELECT DATE(created_at) as date, COUNT(*) as count
    FROM proctoring_events
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
while ($trends_res && ($row = $trends_res->fetch_assoc())) {
    $trends_data[] = $row;
}

$common_res = $conn->query("
    SELECT event_type, severity, COUNT(*) as count,
    (COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM proctoring_events), 0)) as percentage
    FROM proctoring_events
    GROUP BY event_type, severity
    ORDER BY count DESC
    LIMIT 10
");
$common_data = $common_res ? $common_res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Proctoring Analytics | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">TOEIC Admin</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="proctoring_sessions.php">Proctoring</a>
                <a class="nav-link active" href="proctoring_analytics.php">Analytics</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Proctoring Analytics</h2>
                <div class="text-muted small">Integrity threshold aktif: <?php echo $integrity_threshold; ?></div>
            </div>
            <a href="proctoring_sessions.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Sessions
            </a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center h-100 border-primary">
                    <div class="card-body">
                        <h6 class="text-muted">Total Sessions</h6>
                        <h2 class="display-6 fw-bold text-primary"><?php echo $metrics['total_sessions']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100 border-danger">
                    <div class="card-body">
                        <h6 class="text-muted">Terminated</h6>
                        <h2 class="display-6 fw-bold text-danger"><?php echo $metrics['terminated']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100 border-success">
                    <div class="card-body">
                        <h6 class="text-muted">Cleared</h6>
                        <h2 class="display-6 fw-bold text-success"><?php echo $metrics['cleared']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100 border-warning">
                    <div class="card-body">
                        <h6 class="text-muted">Avg Score</h6>
                        <h2 class="display-6 fw-bold text-warning"><?php echo $metrics['avg_score']; ?>%</h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">Violation Types</div>
                    <div class="card-body">
                        <canvas id="violationChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">Integrity Score Distribution</div>
                    <div class="card-body">
                        <canvas id="scoreChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">Violations Trend (Last 7 Days)</div>
                    <div class="card-body">
                        <canvas id="trendChart" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Top Violations</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Violation Type</th>
                            <th>Severity</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($common_data as $row): ?>
                            <tr>
                                <td><?php echo ucfirst(str_replace('_', ' ', (string)$row['event_type'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $row['severity'] === 'critical' ? 'danger' :
                                            ($row['severity'] === 'high' ? 'danger' :
                                            ($row['severity'] === 'medium' ? 'warning text-dark' : 'info text-dark'));
                                    ?>">
                                        <?php echo strtoupper((string)$row['severity']); ?>
                                    </span>
                                </td>
                                <td><?php echo (int)$row['count']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                            <div class="progress-bar" style="width: <?php echo (float)$row['percentage']; ?>%"></div>
                                        </div>
                                        <small><?php echo round((float)$row['percentage'], 1); ?>%</small>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const violations = <?php echo json_encode($violations_data); ?>;
        const scores = <?php echo json_encode($scores_data); ?>;
        const trends = <?php echo json_encode($trends_data); ?>;

        new Chart(document.getElementById('violationChart'), {
            type: 'pie',
            data: {
                labels: violations.map(v => v.event_type),
                datasets: [{
                    data: violations.map(v => Number(v.count)),
                    backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#7c3aed', '#0ea5e9']
                }]
            }
        });

        new Chart(document.getElementById('scoreChart'), {
            type: 'bar',
            data: {
                labels: Object.keys(scores),
                datasets: [{
                    label: 'Sessions',
                    data: Object.values(scores).map(v => Number(v)),
                    backgroundColor: ['#10b981', '#22c55e', '#f59e0b', '#ef4444']
                }]
            },
            options: {
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });

        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: trends.map(t => t.date),
                datasets: [{
                    label: 'Violations',
                    data: trends.map(t => Number(t.count)),
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.15)',
                    fill: true,
                    tension: 0.2
                }]
            },
            options: {
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    </script>
</body>
</html>
