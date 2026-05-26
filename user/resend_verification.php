<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/email_verification_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: ../login.php");
    exit();
}

$redirect = trim((string)($_POST['redirect'] ?? 'index.php'));
if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || str_contains($redirect, "\r") || str_contains($redirect, "\n")) {
    $redirect = 'index.php';
}

$sent = toeicCreateEmailVerification($conn, (int)$_SESSION['user_id']);
$query = $sent ? 'sent=1' : 'limited=1';

header("Location: verify_email.php?{$query}&redirect=" . urlencode($redirect));
exit();
?>
