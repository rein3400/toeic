<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session_handler.php';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/AudioStreamer.php';

if (!FEATURE_SECURE_AUDIO) {
    http_response_code(404);
    echo json_encode(['error' => 'Secure audio disabled']);
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['test_session'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$audio_id = $input['audio_id'] ?? '';

if ($audio_id === '' || $audio_id === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing audio_id']);
    exit;
}

$streamer = new AudioStreamer($conn);
$ok = $streamer->markCompleted($_SESSION['user_id'], $_SESSION['test_session'], (string)$audio_id);
if ($ok) {
    echo json_encode(['status' => 'ok']);
} else {
    writeSecureAudioLog("MARK COMPLETE FAIL user_id=" . $_SESSION['user_id'] . " session=" . $_SESSION['test_session'] . " audio_id=" . $audio_id);
    http_response_code(500);
    echo json_encode(['error' => 'Failed']);
}

