<?php
require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/AudioStreamer.php';
require_once __DIR__ . '/../includes/toeic_asset_storage.php';

$token = $_GET['token'] ?? '';
$type = $_GET['type'] ?? 'toeic';

if (!FEATURE_SECURE_AUDIO) {
    header("HTTP/1.1 404 Not Found");
    die("Secure audio disabled");
}

// Gate TOEIC audio streaming
if ($type === 'toeic' && (!defined('FEATURE_TOEIC') || !FEATURE_TOEIC)) {
    writeSecureAudioLog("STREAM DENY type=toeic FEATURE_TOEIC disabled");
    header("HTTP/1.1 404 Not Found");
    die("TOEIC audio streaming is disabled");
}

if (empty($token)) {
    header("HTTP/1.1 400 Bad Request");
    die("Missing token");
}

$streamer = new AudioStreamer($conn);
$validation = $streamer->validateAndMarkStarted($token);
if (isset($validation['error'])) {
    writeSecureAudioLog("STREAM DENY token=$token err=" . $validation['error']);
    header("HTTP/1.1 403 Forbidden");
    die($validation['error']);
}

$stmt = $conn->prepare("SELECT audio_id FROM audio_playback_log WHERE token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header("HTTP/1.1 403 Forbidden");
    die("Invalid or Expired Token");
}

$audio_id = $res->fetch_assoc()['audio_id'];

$audioSource = ['mode' => 'missing'];

if (is_numeric($audio_id)) {
    $audioInt = (int)$audio_id;
    $stmt = $conn->prepare("SELECT file_path FROM toeic_audio WHERE id_audio = ?");
    $stmt->bind_param("i", $audioInt);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $audioSource = toeicAudioSource($res->fetch_assoc()['file_path']);
    }
}

if (($audioSource['mode'] ?? 'missing') === 'missing') {
    writeSecureAudioLog("STREAM 404 token=$token audio_id=$audio_id type=$type");
    header("HTTP/1.1 404 Not Found");
    die("Audio file not found on server");
}

if (($audioSource['mode'] ?? '') === 'remote') {
    toeicStreamRemoteFile($audioSource['url']);
    exit();
}

if (!file_exists($audioSource['path'])) {
    writeSecureAudioLog("STREAM 404 token=$token audio_id=$audio_id type=$type path=" . $audioSource['path']);
    header("HTTP/1.1 404 Not Found");
    die("Audio file not found on server");
}

$streamer->streamFile($audioSource['path']);
?>
