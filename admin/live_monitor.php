<?php
// admin/live_monitor.php
require_once '../includes/session_handler.php';
require_once '../includes/db_utils.php';

// Auth Check (Admin Only)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch Active Sessions (Last 30 mins)
$userIdCol = getUsersIdColumn($conn);
$active_sessions = [];
$stmt = $conn->prepare("SELECT p.test_session, u.username, p.started_at, p.status FROM proctoring_sessions p JOIN users u ON p.user_id = u.$userIdCol WHERE p.status = 'active' ORDER BY p.started_at DESC LIMIT 50");
if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $active_sessions[] = $row;
}

$risk_map = [];
$stmt = $conn->prepare("SELECT test_session, COUNT(*) AS cnt FROM exam_anomalies WHERE occurred_at > NOW() - INTERVAL 10 MINUTE GROUP BY test_session");
if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $risk_map[$row['test_session']] = (int)$row['cnt'];
    }
}

// Fetch Anomalies (Last 24h)
$anomalies = [];
$stmt = $conn->prepare("SELECT a.*, u.username FROM exam_anomalies a JOIN users u ON a.user_id = u.$userIdCol WHERE a.occurred_at > NOW() - INTERVAL 24 HOUR ORDER BY a.occurred_at DESC LIMIT 50");
if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $anomalies[] = $row;
}

// Fetch Audio Logs (Last 50)
$audio_logs = [];
$stmt = $conn->prepare("SELECT l.*, u.username FROM audio_playback_log l JOIN users u ON l.user_id = u.$userIdCol ORDER BY l.played_at DESC LIMIT 50");
if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $audio_logs[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Live Exam Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta http-equiv="refresh" content="30"> <!-- Auto refresh every 30s -->
</head>
<body class="bg-light">
    <div class="container-fluid mt-4">
        <h2 class="mb-4"><i class="fas fa-desktop"></i> Live Exam Monitor</h2>
        
        <div class="row">
            <!-- Active Sessions -->
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-success text-white">Active Sessions</div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <thead><tr><th>User</th><th>Started</th><th>Status</th><th>Risk</th></tr></thead>
                            <tbody>
                                <?php foreach ($active_sessions as $s): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['username']); ?></td>
                                    <td><?php echo date('H:i', strtotime($s['started_at'])); ?></td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td>
                                        <?php
                                            $cnt = $risk_map[$s['test_session']] ?? 0;
                                            if ($cnt >= 10) {
                                                echo "<span class='badge bg-danger'>HIGH ($cnt)</span>";
                                            } elseif ($cnt >= 5) {
                                                echo "<span class='badge bg-warning text-dark'>MED ($cnt)</span>";
                                            } else {
                                                echo "<span class='badge bg-secondary'>LOW ($cnt)</span>";
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($active_sessions)) echo "<tr><td colspan='4' class='text-center p-3'>No active sessions</td></tr>"; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Security Anomalies -->
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">Security Anomalies (24h)</div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0 text-sm">
                            <thead><tr><th>User</th><th>Event</th><th>Time</th></tr></thead>
                            <tbody>
                                <?php foreach ($anomalies as $a): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($a['username']); ?></td>
                                    <td>
                                        <span class="badge bg-danger"><?php echo $a['event_type']; ?></span>
                                    </td>
                                    <td><?php echo date('H:i:s', strtotime($a['occurred_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Audio Playback Log -->
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">Audio Playback Stream</div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <thead><tr><th>User</th><th>Audio ID</th><th>Time</th></tr></thead>
                            <tbody>
                                <?php foreach ($audio_logs as $l): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($l['username']); ?></td>
                                    <td><?php echo htmlspecialchars($l['audio_id']); ?></td>
                                    <td><?php echo date('H:i:s', strtotime($l['played_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
