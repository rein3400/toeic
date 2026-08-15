<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/CertificateAccess.php';

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['certificate_csrf']) || !hash_equals($_SESSION['certificate_csrf'], $token)) {
    http_response_code(403);
    exit('Invalid request token.');
}

$resultId = (int) ($_POST['result_id'] ?? 0);
$domain   = ($_POST['domain'] ?? 'rl') === 'sw' ? 'sw' : 'rl';
$action   = $_POST['action'] ?? '';

$statusMap = [
    'approve'    => 'approved',
    'revoke'     => 'revoked',
    'use_policy' => null,
];

if ($resultId < 1 || !array_key_exists($action, $statusMap)) {
    http_response_code(400);
    exit('Invalid certificate action.');
}

$updated = updateCertificateReviewStatus(
    $resultId,
    $statusMap[$action],
    (int) $_SESSION['user_id'],
    $domain
);

$message = $updated ? $action : 'error';
$redirect = $_POST['redirect'] ?? '';

if ($redirect && strpos($redirect, '://') === false) {
    $separator = (strpos($redirect, '?') === false) ? '?' : '&';
    header('Location: ' . $redirect . $separator . 'certificate=' . urlencode($message) . '&domain=' . urlencode($domain));
} elseif ($domain === 'sw') {
    header('Location: toeic_sw_result_detail.php?session=' . urlencode($_POST['test_session'] ?? '') . '&certificate=' . urlencode($message));
} else {
    header('Location: view_result.php?session=' . urlencode($_POST['test_session'] ?? '') . '&certificate=' . urlencode($message));
}
exit();
