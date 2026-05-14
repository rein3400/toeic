<?php
// user/ajax_payment_status.php — Payment status polling endpoint
header('Content-Type: application/json');
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/db_utils.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$order_id = trim($_GET['order_id'] ?? '');
if (empty($order_id)) {
    echo json_encode(['success' => false, 'error' => 'Missing order_id']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Detect which column name the table uses (schema drift: transaction_id vs order_id)
$hasTransactionId = $conn->query("SHOW COLUMNS FROM payment_transactions LIKE 'transaction_id'")->num_rows > 0;
$idCol = $hasTransactionId ? 'transaction_id' : 'order_id';

$testTypeSelect = $conn->query("SHOW COLUMNS FROM payment_transactions LIKE 'test_type'")->num_rows > 0 ? ', test_type' : ", NULL AS test_type";
$stmt = $conn->prepare("SELECT status $testTypeSelect FROM payment_transactions WHERE $idCol = ? AND user_id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}
$stmt->bind_param('si', $order_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

if (($row['status'] ?? '') === 'settlement' && !empty($row['test_type'])) {
    grantSettledPaymentCredit($conn, $user_id, (string)$row['test_type'], $order_id);
}

echo json_encode(['success' => true, 'status' => $row['status']]);
