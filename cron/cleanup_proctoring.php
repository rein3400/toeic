<?php
// cron/cleanup_proctoring.php
// Run via: 0 * * * * php /path/to/cleanup_proctoring.php

require_once __DIR__ . '/../includes/config.php';

// 1. Delete videos scheduled for deletion
$stmt = $conn->prepare("
    SELECT id, video_path 
    FROM proctoring_sessions 
    WHERE video_delete_after IS NOT NULL 
    AND video_delete_after < NOW()
    AND video_status = 'stored'
");
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($sessions as $session) {
    // Delete video file
    if ($session['video_path'] && file_exists($session['video_path'])) {
        unlink($session['video_path']);
    }
    
    // Delete associated chunks
    $chunk_stmt = $conn->prepare("
        SELECT chunk_path FROM proctoring_video_chunks WHERE session_id = ?
    ");
    $chunk_stmt->bind_param("i", $session['id']);
    $chunk_stmt->execute();
    $chunks = $chunk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($chunks as $chunk) {
        if (file_exists($chunk['chunk_path'])) {
            unlink($chunk['chunk_path']);
        }
    }
    
    // Update status
    $update = $conn->prepare("
        UPDATE proctoring_sessions 
        SET video_status = 'deleted', video_path = NULL 
        WHERE id = ?
    ");
    $update->bind_param("i", $session['id']);
    $update->execute();
    
    // Delete chunk records
    $conn->query("DELETE FROM proctoring_video_chunks WHERE session_id = " . $session['id']);
}

echo "Cleaned up " . count($sessions) . " videos\n";

// 2. Auto-delete unreviewed sessions older than 30 days
$conn->query("
    DELETE FROM proctoring_sessions 
    WHERE ended_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND review_status = 'pending'
");

echo "Cleaned up old unreviewed sessions\n";
?>
