<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/proctor_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!validateCsrfToken()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$session_id = (int)($_POST['session_id'] ?? 0);
$restore_score = (int)($_POST['restore_score'] ?? 60);
$notes = trim($_POST['notes'] ?? 'Admin mengizinkan peserta untuk melanjutkan tes');

if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Session ID tidak valid']);
    exit;
}

$threshold = getProctoringIntegrityThreshold();
$restore_score = max($threshold + 1, min(100, $restore_score));

$stmt = $conn->prepare("
    UPDATE proctoring_sessions
    SET integrity_score = ?,
        status = 'active',
        termination_reason = NULL,
        review_status = 'cleared',
        notes = ?,
        ended_at = NULL,
        last_heartbeat_at = NOW(),
        sync_failures = 0
    WHERE id = ?
");
$stmt->bind_param("isi", $restore_score, $notes, $session_id);
$success = $stmt->execute();

if ($success && $conn->affected_rows > 0) {
    syncToeicTestSessionStatusForProctoringSession($session_id, 'active');
    echo json_encode(['success' => true, 'new_score' => $restore_score]);
} else {
    echo json_encode(['success' => false, 'error' => 'Gagal update atau session tidak ditemukan']);
}
