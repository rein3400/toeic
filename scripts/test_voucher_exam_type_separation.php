<?php
declare(strict_types=1);

/**
 * Local HTTP scenario for voucher package separation.
 *
 * It exercises the same student AJAX endpoint used by the browser:
 * - SW voucher rejected from LR redeem context.
 * - LR voucher rejected from SW redeem context.
 * - LR voucher grants only toeic credit.
 * - SW voucher grants only toeic_sw credit.
 */

$root = dirname(__DIR__);
$baseUrl = rtrim((string)($argv[1] ?? getenv('TOEIC_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$username = getenv('TOEIC_VOUCHER_SCENARIO_USER') ?: 'voucher_sep_20260511';
$password = getenv('TOEIC_VOUCHER_SCENARIO_PASS') ?: 'voucherSepPass123';

require_once $root . '/includes/config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
require_once $root . '/includes/db_utils.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP cURL extension is required for this scenario.\n");
    exit(1);
}

$failures = [];

function voucher_sep_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function voucher_sep_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function voucher_sep_ensure_vouchers_table(mysqli $conn): void {
    $ok = $conn->query("
        CREATE TABLE IF NOT EXISTS vouchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            exam_type VARCHAR(50) NOT NULL,
            status ENUM('active','used','disabled','expired') DEFAULT 'active',
            created_by INT NOT NULL,
            redeemed_by INT NULL,
            redeemed_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            batch_id VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code (code),
            INDEX idx_status (status),
            INDEX idx_batch (batch_id),
            INDEX idx_redeemed_by (redeemed_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!$ok) {
        voucher_sep_fail('Unable to ensure vouchers table: ' . $conn->error);
    }
}

function voucher_sep_upsert_user(mysqli $conn, string $username, string $password): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = 'Voucher Separation Scenario';
    $email = $username . '@example.test';

    $stmt = $conn->prepare("
        INSERT INTO users (username, password, full_name, email, role)
        VALUES (?, ?, ?, ?, 'student')
        ON DUPLICATE KEY UPDATE
            password = VALUES(password),
            full_name = VALUES(full_name),
            email = VALUES(email),
            role = 'student'
    ");
    if (!$stmt) {
        voucher_sep_fail('Unable to prepare scenario user upsert: ' . $conn->error);
    }
    $stmt->bind_param('ssss', $username, $hash, $fullName, $email);
    $stmt->execute();
    $stmt->close();

    $idCol = getUsersIdColumn($conn);
    $stmt = $conn->prepare("SELECT {$idCol} AS user_id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['user_id'] ?? 0);
}

function voucher_sep_code(string $prefix): string {
    return 'OSGLI-' . $prefix . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function voucher_sep_insert_voucher(mysqli $conn, string $code, string $examType, int $createdBy): void {
    $batch = 'VSEP-' . date('Ymd-His');
    $stmt = $conn->prepare("
        INSERT INTO vouchers (code, exam_type, status, created_by, batch_id)
        VALUES (?, ?, 'active', ?, ?)
        ON DUPLICATE KEY UPDATE
            exam_type = VALUES(exam_type),
            status = 'active',
            redeemed_by = NULL,
            redeemed_at = NULL,
            batch_id = VALUES(batch_id)
    ");
    if (!$stmt) {
        voucher_sep_fail('Unable to prepare voucher insert: ' . $conn->error);
    }
    $stmt->bind_param('ssis', $code, $examType, $createdBy, $batch);
    $stmt->execute();
    $stmt->close();
}

function voucher_sep_voucher_status(mysqli $conn, string $code): array {
    $stmt = $conn->prepare("SELECT status, exam_type, redeemed_by FROM vouchers WHERE code = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: [];
}

final class VoucherSepHttpClient {
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl) {
        $this->baseUrl = $baseUrl;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'toeic_voucher_cookie_') ?: '';
        if ($this->cookieFile === '') {
            voucher_sep_fail('Unable to create temporary cookie file.');
        }
    }

    public function request(string $method, string $path, $body = null, array $headers = []): array {
        $url = preg_match('#^https?://#i', $path) ? $path : $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($error !== '') {
            voucher_sep_fail('HTTP request failed for ' . $url . ': ' . $error);
        }

        return [
            'status' => $status,
            'body' => (string)$response,
            'url' => $effectiveUrl,
        ];
    }

    public function postForm(string $path, array $fields): array {
        return $this->request('POST', $path, http_build_query($fields), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    public function postJson(string $path, array $payload, string $csrfToken): array {
        return $this->request('POST', $path, json_encode($payload, JSON_UNESCAPED_SLASHES), [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $csrfToken,
        ]);
    }
}

function voucher_sep_extract_csrf(string $html): string {
    if (!preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $matches)) {
        voucher_sep_fail('Unable to find csrf-token meta tag. Snippet: ' . substr(strip_tags($html), 0, 500));
    }
    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function voucher_sep_decode_json(array $response, string $label): array {
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        voucher_sep_fail($label . ' did not return JSON. HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 250));
    }
    return $decoded;
}

voucher_sep_ensure_vouchers_table($conn);
$userId = voucher_sep_upsert_user($conn, $username, $password);
if ($userId <= 0) {
    voucher_sep_fail('Unable to create scenario user.');
}

_ensureStatusColumnSupportsUsed($conn);
$stmt = $conn->prepare("UPDATE user_purchases SET status = 'used', used_at = NOW() WHERE user_id = ? AND exam_type IN ('toeic', 'toeic_sw') AND status = 'active'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->close();

$swWrongCode = voucher_sep_code('SW');
$lrWrongCode = voucher_sep_code('LR');
$lrOkCode = voucher_sep_code('LA');
$swOkCode = voucher_sep_code('SA');

voucher_sep_insert_voucher($conn, $swWrongCode, 'toeic_sw', $userId);
voucher_sep_insert_voucher($conn, $lrWrongCode, 'toeic', $userId);
voucher_sep_insert_voucher($conn, $lrOkCode, 'toeic', $userId);
voucher_sep_insert_voucher($conn, $swOkCode, 'toeic_sw', $userId);

$client = new VoucherSepHttpClient($baseUrl);
$login = $client->postForm('/login.php', [
    'username' => $username,
    'password' => $password,
]);
voucher_sep_assert($login['status'] === 200, 'Login should return HTTP 200 after redirects.');
voucher_sep_assert(strpos($login['url'], '/user/') !== false, 'Login should redirect into /user/.');

$buyPage = $client->request('GET', '/user/buy_exam.php');
voucher_sep_assert($buyPage['status'] === 200, 'Buy exam page should render.');
voucher_sep_assert(strpos($buyPage['body'], 'voucherCodeToeic') !== false, 'Buy page should expose the TOEIC LR voucher input.');
voucher_sep_assert(strpos($buyPage['body'], 'voucherCodeToeicSw') !== false, 'Buy page should expose the TOEIC SW voucher input.');
$csrf = voucher_sep_extract_csrf($buyPage['body']);

// The legacy LR access helper can create a defensive free-trial credit while
// rendering the buy page. Reset after CSRF extraction so this scenario measures
// only voucher redemption behavior.
$stmt = $conn->prepare("UPDATE user_purchases SET status = 'used', used_at = NOW() WHERE user_id = ? AND exam_type IN ('toeic', 'toeic_sw') AND status = 'active'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->close();

$swIntoLr = voucher_sep_decode_json(
    $client->postJson('/user/ajax_redeem_voucher.php', ['code' => $swWrongCode, 'expected_exam_type' => 'toeic'], $csrf),
    'SW voucher in LR context'
);
voucher_sep_assert(empty($swIntoLr['success']), 'SW voucher should be rejected from LR context.');
voucher_sep_assert(strpos((string)($swIntoLr['error'] ?? ''), 'tidak bisa dipakai') !== false, 'SW/LR mismatch should return a package mismatch error.');

$lrIntoSw = voucher_sep_decode_json(
    $client->postJson('/user/ajax_redeem_voucher.php', ['code' => $lrWrongCode, 'expected_exam_type' => 'toeic_sw'], $csrf),
    'LR voucher in SW context'
);
voucher_sep_assert(empty($lrIntoSw['success']), 'LR voucher should be rejected from SW context.');
voucher_sep_assert(strpos((string)($lrIntoSw['error'] ?? ''), 'tidak bisa dipakai') !== false, 'LR/SW mismatch should return a package mismatch error.');

$swWrongStatus = voucher_sep_voucher_status($conn, $swWrongCode);
$lrWrongStatus = voucher_sep_voucher_status($conn, $lrWrongCode);
voucher_sep_assert(($swWrongStatus['status'] ?? '') === 'active', 'Rejected SW voucher should remain active.');
voucher_sep_assert(($lrWrongStatus['status'] ?? '') === 'active', 'Rejected LR voucher should remain active.');
voucher_sep_assert(countStrictTestCredits($conn, $userId, 'toeic') === 0, 'Rejected vouchers should not grant LR credit.');
voucher_sep_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 0, 'Rejected vouchers should not grant SW credit.');

$lrOk = voucher_sep_decode_json(
    $client->postJson('/user/ajax_redeem_voucher.php', ['code' => $lrOkCode, 'expected_exam_type' => 'toeic'], $csrf),
    'LR voucher in LR context'
);
voucher_sep_assert(!empty($lrOk['success']), 'LR voucher should redeem in LR context.');
voucher_sep_assert(($lrOk['exam_type'] ?? '') === 'toeic', 'LR redemption response should report exam_type toeic.');
voucher_sep_assert(countStrictTestCredits($conn, $userId, 'toeic') === 1, 'LR voucher should grant exactly one LR credit.');
voucher_sep_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 0, 'LR voucher should not grant SW credit.');

$swOk = voucher_sep_decode_json(
    $client->postJson('/user/ajax_redeem_voucher.php', ['code' => $swOkCode, 'expected_exam_type' => 'toeic_sw'], $csrf),
    'SW voucher in SW context'
);
voucher_sep_assert(!empty($swOk['success']), 'SW voucher should redeem in SW context.');
voucher_sep_assert(($swOk['exam_type'] ?? '') === 'toeic_sw', 'SW redemption response should report exam_type toeic_sw.');
voucher_sep_assert(countStrictTestCredits($conn, $userId, 'toeic') === 1, 'SW voucher should not change LR credit count.');
voucher_sep_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 1, 'SW voucher should grant exactly one SW credit.');

$lrOkStatus = voucher_sep_voucher_status($conn, $lrOkCode);
$swOkStatus = voucher_sep_voucher_status($conn, $swOkCode);
voucher_sep_assert(($lrOkStatus['status'] ?? '') === 'used', 'Redeemed LR voucher should be marked used.');
voucher_sep_assert(($swOkStatus['status'] ?? '') === 'used', 'Redeemed SW voucher should be marked used.');

if ($failures) {
    fwrite(STDERR, "Voucher package separation scenario failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Voucher package separation scenario passed.\n";
echo "LR mismatch code stayed active: {$lrWrongCode}\n";
echo "SW mismatch code stayed active: {$swWrongCode}\n";
echo "LR success code used: {$lrOkCode}\n";
echo "SW success code used: {$swOkCode}\n";
