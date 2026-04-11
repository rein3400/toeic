<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$title = trim($_POST['title'] ?? '');
$transcript = trim($_POST['transcript'] ?? '');

if (empty($title) || empty($transcript)) {
    echo json_encode(['success' => false, 'error' => 'Title and transcript are required.']);
    exit;
}

if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Audio file is required and must be uploaded successfully.']);
    exit;
}

$upload_dir = '../uploads/audio/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$file_extension = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
$allowed_extensions = ['mp3', 'wav', 'ogg'];

if (!in_array($file_extension, $allowed_extensions)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only MP3, WAV, and OGG are allowed.']);
    exit;
}

$file_name = uniqid() . '.' . $file_extension;
$target_file = $upload_dir . $file_name;

if (!move_uploaded_file($_FILES['audio_file']['tmp_name'], $target_file)) {
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
    exit;
}

try {
    $tipe_part = $_POST['listening_type'] ?? 'A'; // Default to Part A
    if (!in_array($tipe_part, ['A', 'B', 'C'])) {
        $tipe_part = 'A'; // Sanitize input
    }

    $stmt = $conn->prepare("INSERT INTO audio_listening (judul, tipe_part, file_path, transcript) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $title, $tipe_part, $file_name, $transcript);
    
    if ($stmt->execute()) {
        $new_audio_id = $conn->insert_id;
        echo json_encode(['success' => true, 'message' => 'Audio saved successfully!', 'id_audio' => $new_audio_id]);
    } else {
        throw new Exception('Database error: ' . $stmt->error);
    }
    $stmt->close();
} catch (Exception $e) {
    // Clean up uploaded file if database insert fails
    if (file_exists($target_file)) {
        unlink($target_file);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
