<?php
declare(strict_types=1);

/**
 * Local HTTP scenario for package-matched test starts.
 *
 * It verifies that:
 * - A user with only TOEIC LR credit cannot start TOEIC SW.
 * - A user with only TOEIC SW credit cannot start TOEIC LR.
 * - Mismatched start attempts do not consume the user's valid package.
 */

$root = dirname(__DIR__);
$baseUrl = rtrim((string)($argv[1] ?? getenv('TOEIC_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$username = getenv('TOEIC_START_MATCH_USER') ?: 'start_match_20260511';
$password = getenv('TOEIC_START_MATCH_PASS') ?: 'startMatchPass123';

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

function start_match_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function start_match_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function start_match_upsert_user(mysqli $conn, string $username, string $password): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = 'Start Package Match Scenario';
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
        start_match_fail('Unable to prepare scenario user upsert: ' . $conn->error);
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

function start_match_reset_user_state(mysqli $conn, int $userId): void {
    _ensureStatusColumnSupportsUsed($conn);

    $targetCol = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';
    $usedAt = checkColumnExists($conn, 'user_purchases', 'used_at') ? ', used_at = NOW()' : '';
    $stmt = $conn->prepare("UPDATE user_purchases SET status = 'used'{$usedAt} WHERE user_id = ? AND {$targetCol} IN ('toeic', 'toeic_sw') AND status = 'active'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    if (checkTableExists($conn, 'toeic_test_sessions')) {
        $stmt = $conn->prepare("UPDATE toeic_test_sessions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    if (checkTableExists($conn, 'toeic_sw_test_sessions')) {
        $stmt = $conn->prepare("UPDATE toeic_sw_test_sessions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function start_match_active_sessions(mysqli $conn, int $userId, string $examType): int {
    $table = $examType === 'toeic_sw' ? 'toeic_sw_test_sessions' : 'toeic_test_sessions';
    if (!checkTableExists($conn, $table)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM {$table} WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

final class StartMatchHttpClient {
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl) {
        $this->baseUrl = $baseUrl;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'toeic_start_match_cookie_') ?: '';
        if ($this->cookieFile === '') {
            start_match_fail('Unable to create temporary cookie file.');
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
            start_match_fail('HTTP request failed for ' . $url . ': ' . $error);
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
}

function start_match_login(StartMatchHttpClient $client, string $username, string $password): void {
    $login = $client->postForm('/login.php', [
        'username' => $username,
        'password' => $password,
    ]);
    start_match_assert($login['status'] === 200, 'Login should return HTTP 200 after redirects.');
    start_match_assert(strpos($login['url'], '/user/') !== false, 'Login should redirect into /user/.');
}

function start_match_grant_only(mysqli $conn, int $userId, string $examType): void {
    start_match_reset_user_state($conn, $userId);
    if (!grantTestCredit($conn, $userId, $examType, 'START_MATCH_' . strtoupper($examType) . '_' . time() . '_' . random_int(1000, 9999))) {
        start_match_fail('Unable to grant ' . $examType . ' credit.');
    }
}

$userId = start_match_upsert_user($conn, $username, $password);
if ($userId <= 0) {
    start_match_fail('Unable to create scenario user.');
}

start_match_grant_only($conn, $userId, 'toeic');
$lrOnlyClient = new StartMatchHttpClient($baseUrl);
start_match_login($lrOnlyClient, $username, $password);
$swFromInstructions = $lrOnlyClient->postForm('/user/test_instructions.php?test_format=toeic_sw&mode=full', [
    'mode' => 'full',
    'test_format' => 'toeic_sw',
    'confirm_instructions' => '1',
]);
start_match_assert(strpos($swFromInstructions['url'], '/user/buy_exam.php') !== false, 'LR-only user should be redirected to buy page when posting SW instructions.');
start_match_assert(start_match_active_sessions($conn, $userId, 'toeic_sw') === 0, 'LR-only user should not create an SW session from instructions.');
start_match_assert(countStrictTestCredits($conn, $userId, 'toeic') === 1, 'Rejected SW start should not consume LR credit.');
start_match_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 0, 'Rejected SW start should not create SW credit.');

$swDirectStart = $lrOnlyClient->request('GET', '/user/test_toeic_sw.php?start_new=1&mode=full');
start_match_assert(strpos($swDirectStart['url'], '/user/buy_exam.php') !== false, 'LR-only user should be redirected to buy page when directly starting SW.');
start_match_assert(start_match_active_sessions($conn, $userId, 'toeic_sw') === 0, 'LR-only user should not create an SW session from direct start.');
start_match_assert(countStrictTestCredits($conn, $userId, 'toeic') === 1, 'Rejected direct SW start should not consume LR credit.');

$swPracticeDirectStart = $lrOnlyClient->request('GET', '/user/test_toeic_sw.php?start_new=1&mode=prep');
start_match_assert(strpos($swPracticeDirectStart['url'], '/user/buy_exam.php') !== false, 'LR-only user should be redirected to buy page when directly starting SW practice.');
start_match_assert(start_match_active_sessions($conn, $userId, 'toeic_sw') === 0, 'LR-only user should not create an SW practice session from direct start.');
start_match_assert(countStrictTestCredits($conn, $userId, 'toeic') === 1, 'Rejected direct SW practice start should not consume LR credit.');

start_match_grant_only($conn, $userId, 'toeic_sw');
$swOnlyClient = new StartMatchHttpClient($baseUrl);
start_match_login($swOnlyClient, $username, $password);
$lrFromInstructions = $swOnlyClient->postForm('/user/test_instructions.php?test_format=toeic&mode=full', [
    'mode' => 'full',
    'test_format' => 'toeic',
    'confirm_instructions' => '1',
]);
start_match_assert(strpos($lrFromInstructions['url'], '/user/buy_exam.php') !== false, 'SW-only user should be redirected to buy page when posting LR instructions.');
start_match_assert(start_match_active_sessions($conn, $userId, 'toeic') === 0, 'SW-only user should not create an LR session from instructions.');
start_match_assert(countStrictTestCredits($conn, $userId, 'toeic') === 0, 'Rejected LR start should not create LR credit.');
start_match_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 1, 'Rejected LR start should not consume SW credit.');

$lrDirectStart = $swOnlyClient->request('GET', '/user/test_toeic.php?start_new=1&mode=full');
start_match_assert(strpos($lrDirectStart['url'], '/user/buy_exam.php') !== false, 'SW-only user should be redirected to buy page when directly starting LR.');
start_match_assert(start_match_active_sessions($conn, $userId, 'toeic') === 0, 'SW-only user should not create an LR session from direct start.');
start_match_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 1, 'Rejected direct LR start should not consume SW credit.');

if ($failures) {
    fwrite(STDERR, "Package-matched start scenario failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Package-matched start scenario passed.\n";
echo "LR-only user was blocked from SW start.\n";
echo "SW-only user was blocked from LR start.\n";
