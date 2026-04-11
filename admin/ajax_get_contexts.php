<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

// Check admin authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';

try {
    $data = [];

    if ($type === 'reading') {
        $result = $conn->query("SELECT id_teks, judul, substring(isi_teks, 1, 50) as preview FROM teks_bacaan ORDER BY id_teks DESC");
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'id' => $row['id_teks'],
                'title' => $row['judul'] . ' (' . strip_tags($row['preview']) . '...)'
            ];
        }
    } elseif ($type === 'listening') {
        $result = $conn->query("SELECT id_audio, judul, tipe_part FROM audio_listening ORDER BY id_audio DESC");
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'id' => $row['id_audio'],
                'title' => '[Part ' . $row['tipe_part'] . '] ' . $row['judul']
            ];
        }
    } else {
        throw new Exception('Invalid context type');
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>