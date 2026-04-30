<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/proctor_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$uid = getUsersIdColumn($conn);
$integrity_threshold = getProctoringIntegrityThreshold();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: proctoring_sessions.php"); exit(); }

$stmt = $conn->prepare("SELECT ps.*, u.full_name, u.username FROM proctoring_sessions ps JOIN users u ON ps.user_id = u.{$uid} WHERE ps.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
if (!$session) { header("Location: proctoring_sessions.php"); exit(); }

$stmt = $conn->prepare("SELECT * FROM proctoring_events WHERE session_id = ? ORDER BY event_time ASC");
$stmt->bind_param("i", $id);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle Review Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        header("Location: proctoring_review.php?id=$id&msg=csrf_error");
        exit;
    }
    $status = $_POST['status'];
    $notes  = $_POST['notes'];

    $stmt = $conn->prepare("UPDATE proctoring_sessions SET review_status = ?, notes = ? WHERE id = ?");
    $stmt->bind_param("ssi", $status, $notes, $id);
    $stmt->execute();

    if ($status === 'cleared') {
        $restore_score = max($integrity_threshold + 1, 60);
        $conn->query("UPDATE proctoring_sessions SET integrity_score = $restore_score, status = 'active', termination_reason = NULL, ended_at = NULL, last_heartbeat_at = NOW(), sync_failures = 0 WHERE id = $id");
        syncToeicTestSessionStatusForProctoringSession($id, 'active');
    }

    header("Location: proctoring_review.php?id=$id&msg=saved");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Review Sesi #<?php echo $id; ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <?php echo csrfMeta(); ?>
    <style>
        .timeline { max-height: 480px; overflow-y: auto; }
        .event-row {
            border-left: 4px solid var(--border-color);
            margin-bottom: 8px;
            padding: 8px 12px;
            background: var(--surface);
            border-radius: 4px;
        }
        .severity-low      { border-color: #3b82f6; }
        .severity-medium   { border-color: #f59e0b; }
        .severity-high     { border-color: #ef4444; background: var(--danger-light); }
        .severity-critical { border-color: #7c3aed; background: rgba(139,92,246,0.15); }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 admin-content">
            <div class="admin-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h1><i class="fas fa-search me-3"></i>Review Sesi #<?php echo $id; ?></h1>
                    <a href="proctoring_sessions.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Kembali
                    </a>
                </div>
            </div>

            <div class="p-4">

                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>Review tersimpan.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'csrf_error'): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i>CSRF token tidak valid. Silakan coba lagi.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">

                    <!-- LEFT: Info + Actions -->
                    <div class="col-md-4">

                        <!-- Session Info -->
                        <div class="card shadow-sm mb-3">
                            <div class="card-header fw-semibold">Info Sesi</div>
                            <div class="card-body">
                                <h5 class="mb-1"><?php echo htmlspecialchars($session['full_name']); ?></h5>
                                <div class="text-muted small mb-2">@<?php echo htmlspecialchars($session['username']); ?></div>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="small text-muted">Status Sesi</span>
                                    <?php if ($session['status'] === 'terminated'): ?>
                                        <span class="badge bg-danger">Terminated</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="small text-muted">Integrity Score</span>
                                    <span class="fw-bold <?php echo $session['integrity_score'] < $integrity_threshold ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $session['integrity_score']; ?>/100
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="small text-muted">Format</span>
                                    <span class="small"><?php echo ucfirst(str_replace('_', ' ', $session['test_format'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="small text-muted">Mulai</span>
                                    <span class="small"><?php echo date('d M Y H:i', strtotime($session['started_at'])); ?></span>
                                </div>
                                <?php if ($session['termination_reason']): ?>
                                <hr class="my-2">
                                <div class="small text-danger"><i class="fas fa-ban me-1"></i><?php echo htmlspecialchars($session['termination_reason']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- IZINKAN LANJUTKAN (only when flagged/terminated) -->
                        <?php if ($session['integrity_score'] < $integrity_threshold || $session['status'] === 'terminated'): ?>
                        <div class="card shadow-sm mb-3 border-warning">
                            <div class="card-header bg-warning text-dark fw-bold">
                                <i class="fas fa-unlock me-1"></i>Izinkan Peserta Lanjutkan
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-3">
                                    Reset integrity score dan aktifkan kembali sesi. Peserta dapat melanjutkan tes setelah dikonfirmasi.
                                </p>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Restore ke Score</label>
                                    <input type="number" id="restoreScore" class="form-control form-control-sm" value="<?php echo max($integrity_threshold + 1, 60); ?>" min="<?php echo $integrity_threshold + 1; ?>" max="100">
                                    <div class="form-text">Minimal <?php echo $integrity_threshold + 1; ?> (di atas batas terminasi aktif)</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Catatan Admin</label>
                                    <input type="text" id="grantNotes" class="form-control form-control-sm"
                                           value="Admin mengizinkan peserta melanjutkan tes">
                                </div>
                                <button class="btn btn-warning w-100 fw-bold" onclick="grantContinue(this)">
                                    <i class="fas fa-play-circle me-1"></i>Izinkan Lanjutkan Test
                                </button>
                                <div id="grantResult" class="mt-2"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Verdik Form -->
                        <div class="card shadow-sm">
                            <div class="card-header fw-semibold">Verdik Review</div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php echo csrfField(); ?>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Status</label>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="pending"  <?php echo $session['review_status']==='pending'?'selected':''; ?>>Pending</option>
                                            <option value="reviewed" <?php echo $session['review_status']==='reviewed'?'selected':''; ?>>Reviewed (Konfirmasi Pelanggaran)</option>
                                            <option value="cleared"  <?php echo $session['review_status']==='cleared'?'selected':''; ?>>✅ Cleared (Pulihkan Akses)</option>
                                            <option value="flagged"  <?php echo $session['review_status']==='flagged'?'selected':''; ?>>❌ Flagged (Konfirmasi Kecurangan)</option>
                                        </select>
                                        <div class="form-text">"Cleared" memulihkan score di atas threshold aktif dan mengaktifkan kembali sesi.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Catatan</label>
                                        <textarea name="notes" class="form-control form-control-sm" rows="3"><?php echo htmlspecialchars($session['notes'] ?? ''); ?></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">Simpan Verdik</button>
                                </form>
                            </div>
                        </div>

                    </div><!-- /col-4 -->

                    <!-- RIGHT: Timeline + Video -->
                    <div class="col-md-8">

                        <!-- Event Timeline -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span class="fw-semibold">Timeline Peristiwa</span>
                                <span class="badge bg-secondary"><?php echo count($events); ?> event</span>
                            </div>
                            <div class="card-body timeline bg-light p-3">
                                <?php if (empty($events)): ?>
                                    <p class="text-muted text-center py-3">Belum ada event tercatat.</p>
                                <?php endif; ?>
                                <?php foreach ($events as $event): ?>
                                    <div class="event-row severity-<?php echo htmlspecialchars($event['severity']); ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <strong class="small"><?php echo ucfirst(str_replace('_', ' ', $event['event_type'])); ?></strong>
                                            <div class="text-end ms-2 flex-shrink-0">
                                                <span class="badge bg-<?php echo match($event['severity']) {
                                                    'critical' => 'purple',
                                                    'high'     => 'danger',
                                                    'medium'   => 'warning text-dark',
                                                    default    => 'info text-dark',
                                                }; ?> small">
                                                    <?php echo $event['severity']; ?>
                                                </span>
                                                <span class="text-muted small ms-1"><?php echo date("H:i:s", (int)$event['event_time']); ?></span>
                                            </div>
                                        </div>
                                        <?php
                                            $meta = json_decode($event['metadata'], true);
                                            if ($meta && is_array($meta)):
                                        ?>
                                        <div class="small text-muted mt-1">
                                            <?php foreach ($meta as $k => $v): ?>
                                                <span class="me-2"><?php echo htmlspecialchars((string)$k); ?>: <?php echo htmlspecialchars(is_array($v) ? json_encode($v) : (string)($v ?? '')); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($event['snapshot_path']):
                                            $filename = basename($event['snapshot_path']);
                                            $webPath  = "../uploads/proctoring/snapshots/" . htmlspecialchars($session['test_session']) . "/" . $filename;
                                        ?>
                                            <div class="mt-2">
                                                <a href="<?php echo $webPath; ?>" target="_blank">
                                                    <img src="<?php echo $webPath; ?>" class="img-thumbnail" style="height:90px;">
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>


                    </div><!-- /col-8 -->
                </div><!-- /row -->
            </div><!-- /p-4 -->
        </div><!-- /admin-content -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
function grantContinue(btn) {
    const score = document.getElementById('restoreScore').value;
    const notes = document.getElementById('grantNotes').value;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Memproses...';

    fetch('ajax_grant_proctor_continue.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'session_id=<?php echo $id; ?>&restore_score=' + encodeURIComponent(score) + '&notes=' + encodeURIComponent(notes) + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('grantResult');
        if (data.success) {
            el.innerHTML = '<div class="alert alert-success py-2 mb-0 small"><i class="fas fa-check-circle me-1"></i>Berhasil! Score dipulihkan ke ' + data.new_score + '. Informasikan ke peserta untuk refresh halaman.</div>';
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Sudah Diizinkan';
        } else {
            el.innerHTML = '<div class="alert alert-danger py-2 mb-0 small">' + (data.error || 'Gagal') + '</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-play-circle me-1"></i>Izinkan Lanjutkan Test';
        }
    })
    .catch(() => {
        document.getElementById('grantResult').innerHTML = '<div class="alert alert-danger py-2 mb-0 small">Error koneksi</div>';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play-circle me-1"></i>Izinkan Lanjutkan Test';
    });
}
</script>
</body>
</html>
