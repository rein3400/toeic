<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

try {
    $questions = $_POST['pertanyaan'] ?? [];
    $options_a = $_POST['opsi_a'] ?? [];
    $options_b = $_POST['opsi_b'] ?? [];
    $options_c = $_POST['opsi_c'] ?? [];
    $options_d = $_POST['opsi_d'] ?? [];
    $correct_answers = $_POST['jawaban_benar'] ?? [];
    $explanations = $_POST['penjelasan'] ?? [];

    if (empty($questions)) {
        echo json_encode(['success' => false, 'error' => 'No questions to save.']);
        exit;
    }

    // Validate data consistency
    $count = count($questions);
    if (count($options_a) !== $count || count($options_b) !== $count || 
        count($options_c) !== $count || count($options_d) !== $count || 
        count($correct_answers) !== $count || count($explanations) !== $count) {
        echo json_encode(['success' => false, 'error' => 'Data mismatch. Ensure all fields are provided for each question.']);
        exit;
    }

    // Check if penjelasan column exists, add if needed
    $check_column = $conn->query("SHOW COLUMNS FROM soal_structure LIKE 'penjelasan'");
    if ($check_column->num_rows == 0) {
        $conn->query("ALTER TABLE soal_structure ADD COLUMN penjelasan TEXT");
    }

    // Handle nomor_soal field if it exists
    $check_nomor = $conn->query("SHOW COLUMNS FROM soal_structure LIKE 'nomor_soal'");
    $has_nomor_soal = $check_nomor->num_rows > 0;
    
    $next_nomor = 1;
    if ($has_nomor_soal) {
        $max_result = $conn->query("SELECT COALESCE(MAX(nomor_soal), 0) + 1 as next_nomor FROM soal_structure");
        $next_nomor = $max_result->fetch_assoc()['next_nomor'];
    }

    $conn->begin_transaction();

    // Prepare statement based on table structure
    if ($has_nomor_soal) {
        $stmt = $conn->prepare("INSERT INTO soal_structure (nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, penjelasan) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    } else {
        $stmt = $conn->prepare("INSERT INTO soal_structure (pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, penjelasan) VALUES (?, ?, ?, ?, ?, ?, ?)");
    }

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    for ($i = 0; $i < $count; $i++) {
        $question = trim($questions[$i]);
        $opsi_a = trim($options_a[$i]);
        $opsi_b = trim($options_b[$i]);
        $opsi_c = trim($options_c[$i]);
        $opsi_d = trim($options_d[$i]);
        $jawaban_benar = trim($correct_answers[$i]);
        $penjelasan = trim($explanations[$i]);

        if (empty($question) || empty($jawaban_benar)) {
            throw new Exception('Question and correct answer are required for question #' . ($i + 1));
        }

        if ($has_nomor_soal) {
            $current_nomor = $next_nomor + $i;
            $stmt->bind_param("isssssss", $current_nomor, $question, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar, $penjelasan);
        } else {
            $stmt->bind_param("sssssss", $question, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar, $penjelasan);
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Database error while saving question #' . ($i + 1) . ': ' . $stmt->error);
        }
    }

    $stmt->close();
    $conn->commit();

    echo json_encode(['success' => true, 'message' => "$count questions saved successfully!"]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}