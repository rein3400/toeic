<?php
require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';
require_once 'includes/email_verification_helper.php';

$examType = 'toeic';

$target = 'user/payment.php?exam_type=' . urlencode($examType);
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($target));
    exit();
}

toeicRequireVerifiedEmail($conn);

header('Location: ' . $target);
exit();
