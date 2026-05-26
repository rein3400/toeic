<?php
require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';
require_once 'includes/email_verification_helper.php';

$token = trim((string)($_GET['token'] ?? ''));
$verified = false;

if ($conn instanceof mysqli && $token !== '') {
    $verified = toeicConsumeEmailVerification($conn, $token);
}

if ($verified) {
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student') {
        header("Location: user/index.php?email_verified=1");
        exit();
    }

    header("Location: login.php?message=" . urlencode('Email berhasil diverifikasi. Silakan masuk.'));
    exit();
}

header("Location: login.php?message=" . urlencode('Link verifikasi email tidak valid atau sudah kedaluwarsa.'));
exit();
?>
