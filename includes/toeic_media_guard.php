<?php
declare(strict_types=1);
require_once __DIR__ . '/toeic_asset_storage.php';

/** Return owned Part1 assignments whose photo cannot currently be used, without changing answers or scores. */
function toeicUnavailablePhotoQuestions(mysqli $conn, string $testSession, int $userId, ?int $questionId = null, bool $unansweredOnly = false): array {
    $sql = "SELECT tq.question_id, p.file_path
        FROM toeic_test_questions tq
        LEFT JOIN toeic_soal_listening q ON q.id_soal = tq.question_id
        LEFT JOIN toeic_audio a ON a.id_audio = q.id_audio
        LEFT JOIN toeic_photos p ON p.id_photo = a.id_photo
        WHERE tq.test_session = ? AND tq.user_id = ? AND tq.section = 'listening' AND tq.part = '1'";
    $types = 'si'; $params = [$testSession, $userId];
    if ($questionId !== null) { $sql .= ' AND tq.question_id = ?'; $types .= 'i'; $params[] = $questionId; }
    if ($unansweredOnly) { $sql .= " AND (tq.user_answer IS NULL OR tq.user_answer = '')"; }
    $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $missing = [];
    foreach ($rows as $row) {
        try { $usable = toeicPhotoIsUsable((string)($row['file_path'] ?? '')); }
        catch (Throwable $error) {
            error_log('TOEIC assigned photo validation failed: ' . get_class($error));
            $usable = false;
        }
        if (!$usable) { $missing[] = (int)$row['question_id']; }
    }
    return $missing;
}
