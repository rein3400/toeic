<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/csrf_helper.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit;
}
if (!validateCsrfToken()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
$id_teks = (int)($_POST['id_teks'] ?? 0);
$pertanyaan = $_POST['pertanyaan'] ?? [];
$opsi_a = $_POST['opsi_a'] ?? [];
$opsi_b = $_POST['opsi_b'] ?? [];
$opsi_c = $_POST['opsi_c'] ?? [];
$opsi_d = $_POST['opsi_d'] ?? [];
$jawaban_benar = $_POST['jawaban_benar'] ?? [];
if (!$id_teks || !count($pertanyaan)) {
    echo json_encode(['success' => false, 'error' => 'Incomplete data']);
    exit;
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("INSERT INTO soal_reading (id_teks, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar) VALUES (?, ?, ?, ?, ?, ?, ?)");

    for ($i = 0; $i < count($pertanyaan); $i++) {
        $stmt->bind_param("issssss", $id_teks, $pertanyaan[$i], $opsi_a[$i], $opsi_b[$i], $opsi_c[$i], $opsi_d[$i], $jawaban_benar[$i]);
        if (!$stmt->execute()) {
            throw new Exception('Database error: ' . $stmt->error);
        }
    }

    $stmt->close();
    $conn->commit();

    echo json_encode(['success' => true, 'message' => count($pertanyaan) . ' questions saved successfully!']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} 
