<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

$exam = $input['exam'] ?? '';
$section = $input['section'] ?? '';
$part = $input['part'] ?? '';
$genMode = $input['gen_mode'] ?? 'passage';

$userId = (int)$_SESSION['user_id'];

$where = "user_id = ? AND status = 'draft'";
$types = 'i';
$params = [$userId];

if ($exam !== '') {
    $where .= " AND exam = ?";
    $types .= 's';
    $params[] = $exam;
}
if ($part !== '') {
    $where .= " AND part = ?";
    $types .= 's';
    $params[] = $part;
}

$sql = "SELECT id, exam, part, judul, konten, topic, word_count, difficulty_level, text_type, status FROM ai_passage_drafts WHERE $where ORDER BY id DESC LIMIT 50";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error]);
    exit();
}

$bind = [$types];
for ($i = 0; $i < count($params); $i++) {
    $bind[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind);

if (!$stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => false, 'error' => 'DB execute error']);
    exit();
}

$res = $stmt->get_result();
$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'exam' => $row['exam'],
            'part' => $row['part'],
            'judul' => $row['judul'],
            'konten' => $row['konten'],
            'topic' => $row['topic'],
            'word_count' => (int)$row['word_count'],
            'difficulty_level' => $row['difficulty_level'],
            'text_type' => $row['text_type'],
            'status' => $row['status']
        ];
    }
}
$stmt->close();

echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
