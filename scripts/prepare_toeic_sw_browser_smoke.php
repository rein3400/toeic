<?php
declare(strict_types=1);

/**
 * Prepares isolated TOEIC SW sessions for the browser smoke test.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
require_once $root . '/includes/db_utils.php';
require_once $root . '/includes/toeic_sw_helper.php';
require_once $root . '/includes/toeic_sw_test_builder.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

ensureToeicSwSchema($conn);

$username = getenv('TOEIC_SW_BROWSER_USER') ?: 'sw_browser_20260511';
$password = getenv('TOEIC_SW_BROWSER_PASS') ?: 'swBrowserPass123';
$baseUrl = rtrim((string)(getenv('TOEIC_SW_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');

$hash = password_hash($password, PASSWORD_DEFAULT);
$fullName = 'SW Browser Smoke Student';
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
    fwrite(STDERR, "Unable to prepare browser user upsert: " . $conn->error . "\n");
    exit(1);
}
$stmt->bind_param('ssss', $username, $hash, $fullName, $email);
$stmt->execute();
$stmt->close();

$idCol = getUsersIdColumn($conn);
$stmt = $conn->prepare("SELECT {$idCol} AS user_id FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param('s', $username);
$stmt->execute();
$userId = (int)($stmt->get_result()->fetch_assoc()['user_id'] ?? 0);
$stmt->close();

if ($userId <= 0) {
    fwrite(STDERR, "Browser smoke user setup failed.\n");
    exit(1);
}

$conn->query("UPDATE toeic_sw_test_sessions SET status = 'cancelled' WHERE user_id = {$userId} AND status = 'active'");

$builder = new ToeicSwTestBuilder($conn);
$payload = [
    'username' => $username,
    'password' => $password,
    'baseUrl' => $baseUrl,
];

foreach (['speaking', 'writing'] as $section) {
    $session = 'toeic_sw_browser_' . $section . '_' . bin2hex(random_bytes(4));
    $builder->createSession($session, $userId, [
        'current_section' => $section,
        'practice_mode' => 1,
    ]);
    $builder->buildTest($session, $userId);

    if ($section === 'speaking') {
        $timerStmt = $conn->prepare("
            UPDATE toeic_sw_test_questions
            SET prepare_seconds = 1, response_seconds = 1
            WHERE test_session = ? AND section = 'speaking'
        ");
        if (!$timerStmt) {
            fwrite(STDERR, "Unable to shorten speaking timers for browser smoke: " . $conn->error . "\n");
            exit(1);
        }
        $timerStmt->bind_param('s', $session);
        $timerStmt->execute();
        $timerStmt->close();
    }

    $payload[$section . 'Session'] = $session;
    $payload[$section . 'Url'] = '/user/test_toeic_sw.php?section=' . $section
        . '&test_session=' . urlencode($session)
        . '&mode=prep';
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
