<?php
require_once 'includes/session_handler.php';

$examType = 'toeic';

$target = 'user/payment.php?exam_type=' . urlencode($examType);
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($target));
    exit();
}

header('Location: ' . $target);
exit();
