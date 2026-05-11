<?php
// api/tripay_callback.php - Tripay Payment Gateway Callback
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_utils.php';
require_once __DIR__ . '/../includes/TripayHandler.php';

// 1. Capture Raw Input
$json = file_get_contents('php://input');

// Log incoming callback
$tripay = new TripayHandler();
$tripay->log("CALLBACK RECEIVED: " . $json);

// 2. Get Signature from Header
$callbackSignature = $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] ?? '';

if (empty($callbackSignature)) {
    $tripay->log("CALLBACK ERROR: Missing X-Callback-Signature header");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing signature']);
    exit;
}

// 3. Verify Signature
if (!$tripay->verifyCallbackSignature($callbackSignature, $json)) {
    $tripay->log("CALLBACK ERROR: Invalid signature");
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

// 4. Parse Callback Data
$callbackData = $tripay->parseCallback($json);

if (!$callbackData) {
    $tripay->log("CALLBACK ERROR: Failed to parse callback data");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
    exit;
}

$merchantRef = $callbackData['merchant_ref'];
$status = $callbackData['status'];
$reference = $callbackData['reference'];

$tripay->log("Processing callback: $merchantRef, Status: $status, Reference: $reference");

// 5. Check Idempotency & Update Status
$conn->begin_transaction();

$hasTransactionId = checkColumnExists($conn, 'payment_transactions', 'transaction_id');
$idColumn = $hasTransactionId ? 'transaction_id' : 'order_id';

$stmt = $conn->prepare("SELECT id, user_id, status, amount FROM payment_transactions WHERE $idColumn = ? FOR UPDATE");
$stmt->bind_param("s", $merchantRef);
$stmt->execute();
$result = $stmt->get_result();
$transaction = $result->fetch_assoc();

if (!$transaction) {
    $tripay->log("CALLBACK ERROR: Transaction not found for Order ID: $merchantRef");
    $conn->rollback();
    http_response_code(200); // Return 200 to prevent retries
    echo json_encode(['status' => 'ok', 'message' => 'Transaction not found']);
    exit;
}

// 6. Map Tripay status to our status
$new_status = 'pending';
switch ($status) {
    case 'PAID':
        $new_status = 'settlement';
        break;
    case 'UNPAID':
        $new_status = 'pending';
        break;
    case 'EXPIRED':
        $new_status = 'expire';
        break;
    case 'FAILED':
        $new_status = 'deny';
        break;
}

// 7. Check if already processed (terminal state)
$current_status = $transaction['status'];
$terminal_statuses = ['settlement', 'deny', 'expire', 'cancel'];

if (in_array($current_status, $terminal_statuses, true) && $current_status !== $new_status) {
    $tripay->log("CALLBACK INFO: Ignoring status transition $current_status -> $new_status for $merchantRef");
    $conn->commit();
    echo json_encode(['status' => 'ok']);
    exit;
}

// 8. Update transaction status
$updateStmt = $conn->prepare("UPDATE payment_transactions SET status = ?, raw_response = ? WHERE $idColumn = ?");
$updateStmt->bind_param("sss", $new_status, $json, $merchantRef);
if (!$updateStmt->execute()) {
    $tripay->log("CALLBACK ERROR: Failed updating transaction $merchantRef: " . $conn->error);
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error']);
    exit;
}

// 9. Grant access if payment successful
if ($new_status === 'settlement') {
    if (strpos($merchantRef, 'TOEICSW-') === 0) {
        $exam_type = 'toeic_sw';
    } elseif (strpos($merchantRef, 'TOEIC-') === 0) {
        $exam_type = 'toeic';
    } else {
        $exam_type = 'unknown';
    }

    if ($exam_type === 'unknown') {
        $tripay->log("CALLBACK WARNING: Unsupported merchant ref in TOEIC-only repo: $merchantRef");
        $conn->commit();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if (in_array($exam_type, ['toeic', 'toeic_sw'], true) && (!defined('FEATURE_TOEIC') || !FEATURE_TOEIC)) {
        $tripay->log("CALLBACK INFO: TOEIC is disabled. Access not granted for User " . $transaction['user_id'] . " ($merchantRef)");
        $conn->commit();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Grant one test credit per successful payment
    if (!grantTestCredit($conn, $transaction['user_id'], $exam_type, $merchantRef)) {
        $tripay->log("CALLBACK ERROR: Failed granting credit for $merchantRef: " . $conn->error);
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['status' => 'error']);
        exit;
    }

    $tripay->log("ACCESS GRANTED: User " . $transaction['user_id'] . " -> $exam_type ($merchantRef)");
}

$conn->commit();
echo json_encode(['status' => 'ok']);
?>
