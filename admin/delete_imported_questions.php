<?php
require_once '../includes/config.php';

// Check admin
require_once '../includes/session_handler.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized");
}

// IDs to delete: 177 to 186
$ids = range(177, 186); // Generates array [177, 178, ..., 186]
$ids_str = implode(',', $ids);

try {
    // Delete from soal_structure
    $conn->query("DELETE FROM soal_structure WHERE id_soal IN ($ids_str)");
    $affected = $conn->affected_rows;

    // Also cleanup any orphaned test_questions or jawaban_user (good practice)
    $conn->query("DELETE FROM test_questions WHERE question_id IN ($ids_str) AND section = 'structure'");
    $conn->query("DELETE FROM jawaban_user WHERE id_soal IN ($ids_str) AND section = 'structure'");

    echo "Successfully deleted $affected questions (IDs: $ids_str).";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>