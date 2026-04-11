<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$id_audio = (int)($_POST['id_audio'] ?? 0);
$questions = $_POST['pertanyaan'] ?? [];
$options_a = $_POST['opsi_a'] ?? [];
$options_b = $_POST['opsi_b'] ?? [];
$options_c = $_POST['opsi_c'] ?? [];
$options_d = $_POST['opsi_d'] ?? [];
$correct_answers = $_POST['jawaban_benar'] ?? [];

if (empty($id_audio) || empty($questions)) {
    echo json_encode(['success' => false, 'error' => 'Missing audio ID or questions.']);
    exit;
}

// Check if audio exists
$stmt = $conn->prepare("SELECT id_audio FROM audio_listening WHERE id_audio = ?");
$stmt->bind_param("i", $id_audio);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid audio ID.']);
    exit;
}

if (count($questions) !== count($options_a) || count($questions) !== count($options_b) || count($questions) !== count($options_c) || count($questions) !== count($options_d) || count($questions) !== count($correct_answers)) {
    echo json_encode(['success' => false, 'error' => 'Data mismatch. Ensure all fields are provided for each question.']);
    exit;
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("INSERT INTO soal_listening (id_audio, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar) VALUES (?, ?, ?, ?, ?, ?, ?)");

    for ($i = 0; $i < count($questions); $i++) {
        $question = trim($questions[$i]);
        $opsi_a = trim($options_a[$i]);
        $opsi_b = trim($options_b[$i]);
        $opsi_c = trim($options_c[$i]);
        $opsi_d = trim($options_d[$i]);
        $jawaban_benar = trim($correct_answers[$i]);

        if (empty($question) || empty($opsi_a) || empty($opsi_b) || empty($opsi_c) || empty($opsi_d) || empty($jawaban_benar)) {
            throw new Exception('All fields for question #' . ($i + 1) . ' are required.');
        }

        $stmt->bind_param("issssss", $id_audio, $question, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar);
        if (!$stmt->execute()) {
            throw new Exception('Database error while saving question #' . ($i + 1) . ': ' . $stmt->error);
        }
    }

    $stmt->close();
    $conn->commit();

    echo json_encode(['success' => true, 'message' => count($questions) . ' questions have been saved successfully!']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
