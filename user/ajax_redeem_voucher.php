<?php
header('Content-Type: application/json');
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_quality_helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesi tidak valid. Silakan login kembali.']);
    exit;
}

if (!validateCsrfToken()) {
    // Regenerate token so next attempt works after page reload
    generateCsrfToken();
    echo json_encode(['success' => false, 'error' => 'Sesi kedaluwarsa. Silakan muat ulang halaman (Ctrl+Shift+R) dan coba lagi.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
$code = toeicNormalizeVoucherCode($input['code'] ?? '');

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Kode voucher tidak boleh kosong.']);
    exit;
}

// Rate limiting (session-based, 5 attempts per 10 minutes)
$_SESSION['voucher_attempts'] = array_filter(
    $_SESSION['voucher_attempts'] ?? [],
    fn($t) => time() - $t < 600
);
if (count($_SESSION['voucher_attempts']) >= 5) {
    echo json_encode(['success' => false, 'error' => 'Terlalu banyak percobaan. Coba lagi nanti.']);
    exit;
}
$_SESSION['voucher_attempts'][] = time();

// Begin transaction
$conn->begin_transaction();

try {
    // Lock the row for update (prevents race conditions)
    $stmt = $conn->prepare("SELECT * FROM vouchers WHERE code = ? FOR UPDATE");
    if (!$stmt) {
        throw new Exception('Database error');
    }
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Kode voucher tidak valid atau sudah digunakan.']);
        exit;
    }

    // Check if already expired by timestamp (even if status is still 'active')
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        // Mark as expired in DB
        $upd = $conn->prepare("UPDATE vouchers SET status='expired' WHERE id=?");
        $upd->bind_param("i", $row['id']);
        $upd->execute();
        $upd->close();
        $conn->commit();
        echo json_encode(['success' => false, 'error' => 'Voucher sudah kadaluarsa.']);
        exit;
    }

    // Check status (after expiry check)
    if ($row['status'] !== 'active') {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Kode voucher tidak valid atau sudah digunakan.']);
        exit;
    }

    $exam_type = $row['exam_type'];

    if (!in_array($exam_type, ['toeic', 'toeic_sw'], true)) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Voucher ini bukan untuk produk TOEIC di repo ini.']);
        exit;
    }

    if (!(defined('FEATURE_TOEIC') && FEATURE_TOEIC)) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Fitur TOEIC tidak tersedia saat ini.']);
        exit;
    }

    // Mark voucher as used (WITH affected_rows check for race condition safety)
    $upd = $conn->prepare("UPDATE vouchers SET status='used', redeemed_by=?, redeemed_at=NOW() WHERE id=? AND status='active'");
    $upd->bind_param("ii", $user_id, $row['id']);
    $upd->execute();

    if ($upd->affected_rows === 0) {
        $upd->close();
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Kode voucher tidak valid atau sudah digunakan.']);
        exit;
    }
    $upd->close();

    // Grant test credit
    $transaction_ref = 'VOUCHER-' . $code;
    $granted = grantTestCredit($conn, $user_id, $exam_type, $transaction_ref);

    if (!$granted) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Gagal memberikan kredit. Silakan hubungi administrator.']);
        exit;
    }

    $conn->commit();

    $label = $exam_type === 'toeic_sw' ? 'TOEIC Speaking & Writing' : 'TOEIC Listening & Reading';
    echo json_encode([
        'success' => true,
        'exam_type' => $exam_type,
        'message' => 'Voucher berhasil ditukarkan! Kredit ' . $label . ' Anda aktif.'
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan server. Silakan coba lagi.']);
}
