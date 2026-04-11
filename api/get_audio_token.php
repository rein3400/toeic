<?php
// api/get_audio_token.php
header('Content-Type: application/json');

// Include session handler which starts the session with correct configuration
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

$user_id = $_SESSION['user_id'];
$test_session = $_SESSION['test_session'];
$audio_id = $_POST['audio_id'] ?? '';

if (empty($audio_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing audio_id']);
    exit;
}

$streamer = new AudioStreamer($conn);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
$result = $streamer->generateToken($user_id, $test_session, $audio_id, $ip, $ua);

if (isset($result['error'])) {
    if ($result['error'] === 'Too many token requests') {
        http_response_code(429);
    } else {
        http_response_code(403);
    }
    writeSecureAudioLog("TOKEN ERROR user_id=$user_id session=$test_session audio_id=$audio_id err=" . $result['error']);
    echo json_encode($result);
} else {
    echo json_encode($result);
}
?>
