<?php
declare(strict_types=1);

/**
 * Local HTTP scenario for TOEIC SW practice/full mode support.
 *
 * It verifies that both SW launch modes exist, consume only toeic_sw credit,
 * create the same ETS-sized SW attempt, and persist practice/full mode on the
 * SW session so resume/report surfaces can label it correctly.
 */

$root = dirname(__DIR__);
$baseUrl = rtrim((string)($argv[1] ?? getenv('TOEIC_SW_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$username = getenv('TOEIC_SW_MODE_USER') ?: 'sw_modes_20260511';
$password = getenv('TOEIC_SW_MODE_PASS') ?: 'swModesPass123';

require_once $root . '/includes/config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
require_once $root . '/includes/db_utils.php';
require_once $root . '/includes/toeic_sw_helper.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP cURL extension is required for this scenario.\n");
    exit(1);
}

ensureToeicSwSchema($conn);

$failures = [];

function sw_mode_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function sw_mode_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sw_mode_upsert_user(mysqli $conn, string $username, string $password): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = 'SW Practice Full Mode Scenario';
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
        sw_mode_fail('Unable to prepare scenario user upsert: ' . $conn->error);
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

function sw_mode_reset_user_state(mysqli $conn, int $userId): void {
    _ensureStatusColumnSupportsUsed($conn);

    $purchaseTypeColumn = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';
    $usedAt = checkColumnExists($conn, 'user_purchases', 'used_at') ? ', used_at = NOW()' : '';
    $stmt = $conn->prepare("UPDATE user_purchases SET status = 'used'{$usedAt} WHERE user_id = ? AND {$purchaseTypeColumn} IN ('toeic', 'toeic_sw') AND status = 'active'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    if (checkTableExists($conn, 'toeic_sw_test_sessions')) {
        $stmt = $conn->prepare("UPDATE toeic_sw_test_sessions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function sw_mode_grant_one_sw_credit(mysqli $conn, int $userId, string $suffix): void {
    sw_mode_reset_user_state($conn, $userId);
    if (!grantTestCredit($conn, $userId, 'toeic_sw', 'SW_MODE_' . strtoupper($suffix) . '_' . time() . '_' . random_int(1000, 9999))) {
        sw_mode_fail('Unable to grant toeic_sw credit for ' . $suffix . '.');
    }
}

function sw_mode_session_row(mysqli $conn, string $testSession): array {
    $stmt = $conn->prepare("SELECT * FROM toeic_sw_test_sessions WHERE test_session = ? LIMIT 1");
    $stmt->bind_param('s', $testSession);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function sw_mode_question_count(mysqli $conn, string $testSession, string $section): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM toeic_sw_test_questions WHERE test_session = ? AND section = ?");
    $stmt->bind_param('ss', $testSession, $section);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

final class ToeicSwModeHttpClient {
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl) {
        $this->baseUrl = $baseUrl;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'toeic_sw_mode_cookie_') ?: '';
        if ($this->cookieFile === '') {
            sw_mode_fail('Unable to create temporary cookie file.');
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
            sw_mode_fail('HTTP request failed for ' . $url . ': ' . $error);
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

function sw_mode_login(ToeicSwModeHttpClient $client, string $username, string $password): void {
    $login = $client->postForm('/login.php', [
        'username' => $username,
        'password' => $password,
    ]);
    sw_mode_assert($login['status'] === 200, 'Login should return HTTP 200 after redirects.');
    sw_mode_assert(strpos($login['url'], '/user/') !== false, 'Login should redirect into /user/.');
}

function sw_mode_extract_config(string $html): array {
    if (!preg_match('/window\.TOEIC_SW_CONFIG\s*=\s*(\{.*?\});/s', $html, $matches)) {
        $pos = strpos($html, 'TOEIC_SW_CONFIG');
        $snippet = $pos === false ? substr(strip_tags($html), 0, 500) : substr($html, max(0, $pos - 120), 500);
        sw_mode_fail('Unable to find TOEIC_SW_CONFIG in rendered test page. Snippet: ' . $snippet);
    }
    $config = json_decode($matches[1], true);
    if (!is_array($config)) {
        sw_mode_fail('TOEIC_SW_CONFIG is not valid JSON.');
    }
    return $config;
}

function sw_mode_launch(ToeicSwModeHttpClient $client, string $mode): array {
    $client->request('GET', '/user/test_instructions.php?test_format=toeic_sw&mode=' . rawurlencode($mode));
    $launch = $client->postForm('/user/test_instructions.php?test_format=toeic_sw&mode=' . rawurlencode($mode), [
        'mode' => $mode,
        'test_format' => 'toeic_sw',
        'confirm_instructions' => '1',
    ]);

    sw_mode_assert($launch['status'] === 200, strtoupper($mode) . ' launch should render the Speaking page.');
    sw_mode_assert(strpos($launch['url'], '/user/test_toeic_sw.php') !== false, strtoupper($mode) . ' launch should land on test_toeic_sw.php.');
    return $launch;
}

sw_mode_assert(checkColumnExists($conn, 'toeic_sw_test_sessions', 'practice_mode'), 'toeic_sw_test_sessions must include practice_mode.');

$readiness = getToeicSwContentReadiness($conn);
if (empty($readiness['ready'])) {
    sw_mode_fail('TOEIC SW content is not ready. Import packages before running the scenario.');
}

$userId = sw_mode_upsert_user($conn, $username, $password);
if ($userId <= 0) {
    sw_mode_fail('Unable to create scenario user.');
}

sw_mode_grant_one_sw_credit($conn, $userId, 'practice');
$practiceCreditBefore = countStrictTestCredits($conn, $userId, 'toeic_sw');
$practiceClient = new ToeicSwModeHttpClient($baseUrl);
sw_mode_login($practiceClient, $username, $password);
$practiceLaunch = sw_mode_launch($practiceClient, 'prep');
$practiceConfig = sw_mode_extract_config($practiceLaunch['body']);
$practiceSession = (string)($practiceConfig['testSession'] ?? '');
$practiceRow = sw_mode_session_row($conn, $practiceSession);
$practiceDashboard = $practiceClient->request('GET', '/user/index.php');

sw_mode_assert($practiceCreditBefore === 1, 'Practice scenario should begin with one active SW credit.');
sw_mode_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 0, 'Starting SW practice should consume one toeic_sw credit.');
sw_mode_assert(strpos($practiceSession, 'toeic_sw_') === 0, 'Practice session key should use toeic_sw_ prefix.');
sw_mode_assert((int)($practiceRow['practice_mode'] ?? 0) === 1, 'Practice launch must persist practice_mode = 1.');
sw_mode_assert(($practiceConfig['mode'] ?? '') === 'prep', 'Practice test page config must expose mode=prep.');
sw_mode_assert(sw_mode_question_count($conn, $practiceSession, 'speaking') === 11, 'SW practice Speaking should contain 11 questions.');
sw_mode_assert(sw_mode_question_count($conn, $practiceSession, 'writing') === 8, 'SW practice Writing should contain 8 questions.');
sw_mode_assert(strpos($practiceDashboard['body'], 'mode=prep') !== false, 'Dashboard resume link should preserve SW practice mode.');

sw_mode_grant_one_sw_credit($conn, $userId, 'full');
$fullCreditBefore = countStrictTestCredits($conn, $userId, 'toeic_sw');
$fullClient = new ToeicSwModeHttpClient($baseUrl);
sw_mode_login($fullClient, $username, $password);
$fullLaunch = sw_mode_launch($fullClient, 'full');
$fullConfig = sw_mode_extract_config($fullLaunch['body']);
$fullSession = (string)($fullConfig['testSession'] ?? '');
$fullRow = sw_mode_session_row($conn, $fullSession);
$fullDashboard = $fullClient->request('GET', '/user/index.php');

sw_mode_assert($fullCreditBefore === 1, 'Full scenario should begin with one active SW credit.');
sw_mode_assert(countStrictTestCredits($conn, $userId, 'toeic_sw') === 0, 'Starting SW full simulation should consume one toeic_sw credit.');
sw_mode_assert(strpos($fullSession, 'toeic_sw_') === 0, 'Full session key should use toeic_sw_ prefix.');
sw_mode_assert((int)($fullRow['practice_mode'] ?? 1) === 0, 'Full launch must persist practice_mode = 0.');
sw_mode_assert(($fullConfig['mode'] ?? '') === 'full', 'Full test page config must expose mode=full.');
sw_mode_assert(sw_mode_question_count($conn, $fullSession, 'speaking') === 11, 'SW full Speaking should contain 11 questions.');
sw_mode_assert(sw_mode_question_count($conn, $fullSession, 'writing') === 8, 'SW full Writing should contain 8 questions.');
sw_mode_assert(strpos($fullDashboard['body'], 'mode=full') !== false, 'Dashboard resume link should preserve SW full mode.');
sw_mode_assert(countStrictTestCredits($conn, $userId, 'toeic') === 0, 'SW launches should not create or consume LR credit.');

if ($failures) {
    fwrite(STDERR, "TOEIC SW practice/full mode scenario failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TOEIC SW practice/full mode scenario passed.\n";
echo "Practice and full sessions both start through SW routes, consume toeic_sw credit, and persist their mode.\n";
