<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$judul = trim($_POST['judul'] ?? '');
$isi_teks = trim($_POST['isi_teks'] ?? '');
$topik = trim($_POST['topik'] ?? '');

if (empty($judul) || empty($isi_teks)) {
    echo json_encode(['success' => false, 'error' => 'Title and content are required.']);
    exit;
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("INSERT INTO teks_bacaan (judul, isi_teks, topik) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $judul, $isi_teks, $topik);

    if (!$stmt->execute()) {
        throw new Exception('Database error: ' . $stmt->error);
    }

    $stmt->close();
    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Passage saved successfully!']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>