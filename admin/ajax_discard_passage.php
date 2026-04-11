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

$mode = $input['mode'] ?? 'selected';
$draftIds = $input['draft_ids'] ?? [];
$exam = $input['exam'] ?? '';
$part = $input['part'] ?? '';

$userId = (int)$_SESSION['user_id'];
$discarded = 0;

try {
    if ($mode === 'selected') {
        if (!is_array($draftIds) || count($draftIds) === 0) {
            echo json_encode(['success' => true, 'discarded' => 0]);
            exit();
        }
        $ids = array_values(array_filter(array_map('intval', $draftIds), fn($v) => $v > 0));
        if (count($ids) === 0) {
            echo json_encode(['success' => true, 'discarded' => 0]);
            exit();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("DELETE FROM ai_passage_drafts WHERE id IN ($placeholders) AND user_id = ?");
        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }
        $types = str_repeat('i', count($ids)) . 'i';
        $bind = [$types];
        foreach ($ids as $id) $bind[] = $id;
        $bind[] = $userId;
        call_user_func_array([$stmt, 'bind_param'], $bind);
        if (!$stmt->execute()) {
            throw new Exception('DB execute error');
        }
        $discarded = $stmt->affected_rows;
        $stmt->close();
    } else {
        $where = "user_id = ?";
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

        $stmt = $conn->prepare("DELETE FROM ai_passage_drafts WHERE $where");
        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }
        $bind = [$types];
        for ($i = 0; $i < count($params); $i++) {
            $bind[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        if (!$stmt->execute()) {
            throw new Exception('DB execute error');
        }
        $discarded = $stmt->affected_rows;
        $stmt->close();
    }

    echo json_encode(['success' => true, 'discarded' => $discarded], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
