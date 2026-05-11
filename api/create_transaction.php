<?php
// api/create_transaction.php - Tripay Payment Gateway
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session_handler.php';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_utils.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/TripayHandler.php';
// grantTestCredit() is defined in db_utils.php

// 1. Check Login
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {

$user_id = $_SESSION['user_id'];
// Fetch user details for Tripay
$userIdCol = getUsersIdColumn($conn);
$userStmt = $conn->prepare("SELECT full_name, username FROM users WHERE $userIdCol = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userData = $userResult->fetch_assoc();

if (!$userData) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// 2. Validate Input
$input = json_decode(file_get_contents('php://input'), true);
$exam_type = $input['exam_type'] ?? '';

$payment_method = strtoupper(trim($input['payment_method'] ?? 'QRIS'));
$allowed_methods = [
    'QRIS', 'OVO', 'SHOPEEPAY', 'DANA',
    'BCAVA', 'BNIVA', 'BRIVA', 'MANDIRIVA', 'PERMATAVA', 'CIMBVA',
    'BSIVA', 'MUAMALATVA', 'SAMPOERNAVA', 'SMSVA', 'DANAMONVA', 'MYBVA',
];
if (!in_array($payment_method, $allowed_methods)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment method']);
    exit;
}

$products = [
    'toeic' => [
        'name' => getSiteSetting('name_toeic', 'TOEIC Listening & Reading'),
        'price' => (int) getSiteSetting('price_toeic', '175000'),
        'prefix' => 'TOEIC',
        'redirect' => '/user/test_toeic.php?section=listening&start_new=1&mode=full',
    ],
    'toeic_sw' => [
        'name' => getSiteSetting('name_toeic_sw', 'TOEIC Speaking & Writing'),
        'price' => (int) getSiteSetting('price_toeic_sw', '175000'),
        'prefix' => 'TOEICSW',
        'redirect' => '/user/test_toeic_sw.php?section=speaking&start_new=1&mode=full',
    ],
];

if (!isset($products[$exam_type])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Only TOEIC products are supported in this repository']);
    exit;
}

if (!defined('FEATURE_TOEIC') || !FEATURE_TOEIC) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'TOEIC is currently unavailable']);
    exit;
}

$product = $products[$exam_type];
$price = $product['price'];
$prefix = $product['prefix'];

// 3. Generate Order ID (Merchant Reference)
$timestamp = time();
$random = rand(1000, 9999);
$order_id = sprintf("%s-%d-%d", $prefix, $timestamp, $random);

// 4. Determine URLs
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

$callback_url = $base_url . '/api/tripay_callback.php';
$return_url = $base_url . '/user/index.php?payment=success';

// 5. Prepare Tripay Params
$tripayParams = [
    'method' => $payment_method,
    'merchant_ref' => $order_id,
    'amount' => $price,
    'customer_name' => $userData['full_name'] ?: 'Customer',
    'customer_email' => (strpos($userData['username'], '@') !== false)
        ? $userData['username']
        : $userData['username'] . '@student.osgli.id',
    'customer_phone' => '08000000000',
    'order_items' => [[
        'name' => $product['name'],
        'price' => $price,
        'quantity' => 1
    ]],
    'callback_url' => $callback_url,
    'return_url' => $return_url,
    'expired_time' => time() + 86400 // Unix timestamp: 24 hours from now
];

// 6. Create Tripay Transaction
$tripay = new TripayHandler();
$tripayResponse = $tripay->createTransaction($tripayParams);

$test_redirect = $product['redirect'];

if (!$tripayResponse) {
    // Allow bypass only in dev environment (DEV_BYPASS_TOKEN must be set server-side)
    $bypassToken = getenv('DEV_BYPASS_TOKEN');
    $clientToken = $input['dev_token'] ?? '';
    if ($bypassToken && $clientToken && hash_equals($bypassToken, $clientToken)) {
        error_log("DEV BYPASS: granting direct credit for Order ID: $order_id");
        grantTestCredit($conn, $user_id, $exam_type, $order_id);
        echo json_encode([
            'status'       => 'success',
            'order_id'     => $order_id,
            'payment_url'  => '',
            'redirect_url' => $test_redirect,
        ]);
    } else {
        $tripayError = $tripay->getLastError();
        error_log("Tripay Transaction Failed for Order ID: $order_id — $tripayError");
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'Gagal terhubung ke payment gateway: ' . $tripayError]);
    }
    exit;
}

$reference   = $tripayResponse['reference'] ?? '';
// Tripay returns checkout_url (payment page) and/or pay_url (direct pay link)
$payment_url = $tripayResponse['checkout_url'] ?? ($tripayResponse['pay_url'] ?? '');
$qr_url      = $tripayResponse['qr_url'] ?? '';
$qr_string   = $tripayResponse['qr_string'] ?? '';

// 7. Save to DB (use snap_token column for reference for backward compatibility)
$hasTransactionId = checkColumnExists($conn, 'payment_transactions', 'transaction_id');
$idColumn = $hasTransactionId ? 'transaction_id' : 'order_id';

$hasSnapToken     = checkColumnExists($conn, 'payment_transactions', 'snap_token');
$hasPaymentType   = checkColumnExists($conn, 'payment_transactions', 'payment_type');
$hasTestType      = checkColumnExists($conn, 'payment_transactions', 'test_type');
$hasPaymentMethod = checkColumnExists($conn, 'payment_transactions', 'payment_method');

// Build INSERT dynamically based on which columns exist in this DB schema
$cols   = "user_id, $idColumn, amount, status";
$vals   = "?, ?, ?, 'pending'";
$types  = "isd";
$params = [&$user_id, &$order_id, &$price];

if ($hasTestType) {
    $cols  .= ', test_type';
    $vals  .= ', ?';
    $types .= 's';
    $params[] = &$exam_type;
}
if ($hasSnapToken) {
    $cols  .= ', snap_token';
    $vals  .= ', ?';
    $types .= 's';
    $params[] = &$reference;
}
if ($hasPaymentType) {
    $payment_type_val = str_ends_with($payment_method, 'VA') ? 'virtual_account' : strtolower($payment_method);
    $cols  .= ', payment_type';
    $vals  .= ', ?';
    $types .= 's';
    $params[] = &$payment_type_val;
}
if ($hasPaymentMethod) {
    $cols  .= ', payment_method';
    $vals  .= ', ?';
    $types .= 's';
    $params[] = &$payment_method;
}

$stmt = $conn->prepare("INSERT INTO payment_transactions ($cols) VALUES ($vals)");
array_unshift($params, $types);
call_user_func_array([$stmt, 'bind_param'], $params);

$pay_code = $tripayResponse['pay_code'] ?? '';

if ($stmt->execute()) {
    echo json_encode([
        'status'          => 'success',
        'order_id'        => $order_id,
        'reference'       => $reference,
        'payment_url'     => $payment_url,
        'redirect_url'    => $test_redirect,
        'pay_code'        => $pay_code,
        'payment_method'  => $payment_method,
        'qr_url'          => $qr_url,
        'qr_string'       => $qr_string,
    ]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
}

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
