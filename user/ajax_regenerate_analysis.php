<?php
/**
 * AJAX: Regenerate AI Analysis
 * Deletes cached analysis and redirects to regenerate
 */

header('Content-Type: application/json');

require_once '../includes/session_handler.php';
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Tidak terautentikasi']);
    exit();
}

$is_admin = ($_SESSION['role'] === 'admin');
$user_id = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$test_session = $input['session'] ?? '';
$format = $input['format'] ?? 'toeic';

if (!$test_session || $format !== 'toeic') {
    echo json_encode(['success' => false, 'error' => 'Parameter tidak valid']);
    exit();
}

// Verify ownership
$owner_id = null;
$stmt = $conn->prepare("SELECT user_id FROM toeic_test_results WHERE test_session = ?");
$stmt->bind_param("s", $test_session);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$owner_id = $row['user_id'] ?? null;
$stmt->close();

if (!$owner_id) {
    echo json_encode(['success' => false, 'error' => 'Sesi ujian tidak ditemukan']);
    exit();
}

if (!$is_admin && $owner_id != $user_id) {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
    exit();
}

// Delete cached analysis
try {
    $stmt = $conn->prepare("DELETE FROM ai_analysis_cache WHERE user_id = ? AND test_session = ?");
    $stmt->bind_param("is", $owner_id, $test_session);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'redirect' => 'ai_analysis.php?session=' . urlencode($test_session) . '&format=' . urlencode($format)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Gagal menghapus cache: ' . $e->getMessage()]);
}
