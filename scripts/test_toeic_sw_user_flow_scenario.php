<?php
declare(strict_types=1);

/**
 * End-to-end local HTTP scenario for TOEIC Speaking & Writing.
 *
 * This script uses the same browser-facing routes and AJAX endpoints that a
 * student uses. It creates isolated scenario users, starts a paid SW session,
 * verifies ETS task invariants, confirms Speaking cannot submit without
 * recordings, uploads valid WAV answers, saves Writing answers, submits both
 * sections, and checks the admin second-analysis detail page.
 */

$root = dirname(__DIR__);
$baseUrl = rtrim((string)($argv[1] ?? getenv('TOEIC_SW_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$studentUsername = getenv('TOEIC_SW_SCENARIO_USER') ?: 'sw_scenario_20260511';
$studentPassword = getenv('TOEIC_SW_SCENARIO_PASS') ?: 'scenarioPass123';
$adminUsername = getenv('TOEIC_SW_SCENARIO_ADMIN_USER') ?: 'sw_scenario_admin';
$adminPassword = getenv('TOEIC_SW_SCENARIO_ADMIN_PASS') ?: 'adminScenario123';

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

function sw_scenario_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function sw_scenario_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sw_scenario_upsert_user(mysqli $conn, string $username, string $password, string $role): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = $role === 'admin' ? 'SW Scenario Admin' : 'SW Scenario Student';
    $email = $username . '@example.test';

    $stmt = $conn->prepare("
        INSERT INTO users (username, password, full_name, email, role)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            password = VALUES(password),
            full_name = VALUES(full_name),
            email = VALUES(email),
            role = VALUES(role)
    ");
    if (!$stmt) {
        sw_scenario_fail('Unable to prepare scenario user upsert: ' . $conn->error);
    }
    $stmt->bind_param('sssss', $username, $hash, $fullName, $email, $role);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['id'] ?? 0);
}

final class ToeicSwScenarioHttpClient {
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl) {
        $this->baseUrl = $baseUrl;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'toeic_sw_cookie_') ?: '';
        if ($this->cookieFile === '') {
            sw_scenario_fail('Unable to create temporary cookie file.');
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
            CURLOPT_TIMEOUT => 240,
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
            sw_scenario_fail('HTTP request failed for ' . $url . ': ' . $error);
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

    public function postMultipart(string $path, array $fields): array {
        return $this->request('POST', $path, $fields);
    }
}

function sw_scenario_extract_config(string $html): array {
    if (!preg_match('/window\.TOEIC_SW_CONFIG\s*=\s*(\{.*?\});/s', $html, $matches)) {
        $pos = strpos($html, 'TOEIC_SW_CONFIG');
        $snippet = $pos === false ? substr(strip_tags($html), 0, 500) : substr($html, max(0, $pos - 120), 500);
        sw_scenario_fail('Unable to find TOEIC_SW_CONFIG in rendered test page. Snippet: ' . $snippet);
    }
    $config = json_decode($matches[1], true);
    if (!is_array($config)) {
        sw_scenario_fail('TOEIC_SW_CONFIG is not valid JSON.');
    }
    return $config;
}

function sw_scenario_decode_json_response(array $response, string $label): array {
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        sw_scenario_fail($label . ' did not return JSON. HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 200));
    }
    return $decoded;
}

function sw_scenario_write_wav(string $path, float $seconds = 1.2): void {
    $sampleRate = 16000;
    $samples = (int)round($sampleRate * $seconds);
    $data = '';
    for ($i = 0; $i < $samples; $i++) {
        $amplitude = (int)round(sin(2 * M_PI * 440 * ($i / $sampleRate)) * 9000);
        $data .= pack('v', $amplitude & 0xffff);
    }

    $dataSize = strlen($data);
    $header = 'RIFF'
        . pack('V', 36 + $dataSize)
        . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
        . 'data'
        . pack('V', $dataSize);
    file_put_contents($path, $header . $data);
}

function sw_scenario_count_words(string $text): int {
    return count(array_filter(preg_split('/\s+/', trim($text)) ?: []));
}

function sw_scenario_get_questions(mysqli $conn, string $session, string $section): array {
    $questions = getToeicSwQuestionsForSection($conn, $session, $section);
    usort($questions, static fn($a, $b) => (int)$a['question_order'] <=> (int)$b['question_order']);
    return $questions;
}

$readiness = getToeicSwContentReadiness($conn);
if (empty($readiness['ready'])) {
    sw_scenario_fail('TOEIC SW content is not ready. Import packages before running the scenario.');
}

$studentId = sw_scenario_upsert_user($conn, $studentUsername, $studentPassword, 'student');
$adminId = sw_scenario_upsert_user($conn, $adminUsername, $adminPassword, 'admin');
if ($studentId <= 0 || $adminId <= 0) {
    sw_scenario_fail('Unable to create scenario users.');
}

$conn->query("UPDATE toeic_sw_test_sessions SET status = 'cancelled' WHERE user_id = {$studentId} AND status = 'active'");
$stmt = $conn->prepare("UPDATE user_purchases SET status = 'used', used_at = NOW() WHERE user_id = ? AND exam_type = 'toeic_sw' AND status = 'active'");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->close();
grantTestCredit($conn, $studentId, 'toeic_sw', 'SW_SCENARIO_' . time());
$activeBefore = countStrictTestCredits($conn, $studentId, 'toeic_sw');

$student = new ToeicSwScenarioHttpClient($baseUrl);
$login = $student->postForm('/login.php', [
    'username' => $studentUsername,
    'password' => $studentPassword,
]);
sw_scenario_assert($login['status'] === 200, 'Student login should return HTTP 200 after redirects.');
sw_scenario_assert(strpos($login['url'], '/user/') !== false, 'Student login should redirect into /user/.');

$student->request('GET', '/user/test_instructions.php?test_format=toeic_sw&mode=full');
$launch = $student->postForm('/user/test_instructions.php?test_format=toeic_sw&mode=full', [
    'mode' => 'full',
    'test_format' => 'toeic_sw',
    'confirm_instructions' => '1',
]);
sw_scenario_assert($launch['status'] === 200, 'Launching SW session should render the Speaking page.');
sw_scenario_assert(strpos($launch['url'], '/user/test_toeic_sw.php') !== false, 'Launch should land on test_toeic_sw.php.');
if (strpos($launch['body'], 'TOEIC_SW_CONFIG') === false) {
    sw_scenario_fail('Launch did not render the SW test config. Final URL: ' . $launch['url'] . "\nSnippet: " . substr(strip_tags($launch['body']), 0, 500));
}

$speakingConfig = sw_scenario_extract_config($launch['body']);
$testSession = (string)($speakingConfig['testSession'] ?? '');
$speakingCsrf = (string)($speakingConfig['csrfToken'] ?? '');
sw_scenario_assert(strpos($testSession, 'toeic_sw_') === 0, 'Started session key should use toeic_sw_ prefix.');
sw_scenario_assert($speakingCsrf !== '', 'Speaking page should expose a CSRF token.');

$activeAfterStart = countStrictTestCredits($conn, $studentId, 'toeic_sw');
$speakingQuestions = sw_scenario_get_questions($conn, $testSession, 'speaking');
$writingQuestions = sw_scenario_get_questions($conn, $testSession, 'writing');
sw_scenario_assert($activeBefore === 1, 'Scenario student should begin with exactly one active toeic_sw credit.');
sw_scenario_assert($activeAfterStart === 0, 'Starting SW should consume the toeic_sw credit.');
sw_scenario_assert(count($speakingQuestions) === 11, 'Speaking section should contain exactly 11 questions.');
sw_scenario_assert(count($writingQuestions) === 8, 'Writing section should contain exactly 8 questions.');

$promptAudioCount = 0;
$imageCount = 0;
$infoStimulusGroups = [];
foreach ($speakingQuestions as $question) {
    $order = (int)$question['question_order'];
    $type = (string)$question['question_type'];
    $content = $question['content'] ?? [];
    if ($order <= 2) {
        sw_scenario_assert($type === 'read_text_aloud', "Speaking Q{$order} should be read_text_aloud.");
        sw_scenario_assert(empty($content['audio_path']), "Speaking Q{$order} should not use prompt audio.");
    }
    if ($order >= 3 && $order <= 4) {
        sw_scenario_assert($type === 'describe_picture', "Speaking Q{$order} should be describe_picture.");
        sw_scenario_assert(!empty($content['image_path']), "Speaking Q{$order} should have an image.");
        sw_scenario_assert(empty($content['audio_path']), "Speaking Q{$order} should not use prompt audio.");
        $imageCount++;
    }
    if ($order >= 5) {
        sw_scenario_assert(!empty($content['audio_path']), "Speaking Q{$order} should have prompt audio.");
        $promptAudioCount++;
    }
    if ($order >= 8 && $order <= 10) {
        $infoStimulusGroups[] = (string)$question['stimulus_group_id'];
        sw_scenario_assert((int)$question['read_seconds'] === 45, "Speaking Q{$order} should have 45s information-card reading time.");
    }
    if ($order === 10) {
        sw_scenario_assert((int)$question['repeat_question'] === 1, 'Speaking Q10 should repeat the question.');
        sw_scenario_assert((int)$question['response_seconds'] === 30, 'Speaking Q10 response time should be 30s.');
    }
}
sw_scenario_assert($promptAudioCount === 7, 'Speaking should reference exactly 7 prompt audio files.');
sw_scenario_assert(count(array_unique($infoStimulusGroups)) === 1, 'Speaking Q8-Q10 should share one information card.');

foreach ($writingQuestions as $question) {
    $order = (int)$question['question_order'];
    $content = $question['content'] ?? [];
    if ($order <= 5) {
        $words = json_decode((string)($content['required_words_json'] ?? '[]'), true);
        sw_scenario_assert((string)$question['question_type'] === 'write_sentence_based_on_picture', "Writing Q{$order} should be picture sentence.");
        sw_scenario_assert(is_array($words) && count($words) === 2, "Writing Q{$order} should have exactly two required words or phrases.");
        sw_scenario_assert(!empty($content['image_path']), "Writing Q{$order} should have an image.");
        $imageCount++;
    }
    if ($order >= 6 && $order <= 7) {
        sw_scenario_assert((int)$question['task_minutes'] === 10, "Writing Q{$order} should have a 10-minute task timer.");
    }
    if ($order === 8) {
        sw_scenario_assert((int)($content['minimum_words'] ?? 0) === 300, 'Writing Q8 should use a 300-word quality target.');
    }
}
sw_scenario_assert($imageCount === 7, 'Package should expose 7 images across Speaking and Writing.');

$blocked = $student->postJson('/user/ajax_submit_section_toeic_sw.php', [
    'csrf_token' => $speakingCsrf,
    'test_session' => $testSession,
    'section' => 'speaking',
], $speakingCsrf);
$blockedJson = sw_scenario_decode_json_response($blocked, 'Speaking blocked-submit check');
sw_scenario_assert(empty($blockedJson['success']), 'Speaking submit should fail before recordings are uploaded.');
sw_scenario_assert(stripos((string)($blockedJson['error'] ?? ''), 'Speaking') !== false, 'Blocked Speaking submit should explain missing recordings.');

$tmpWav = tempnam(sys_get_temp_dir(), 'toeic_sw_answer_') . '.wav';
sw_scenario_write_wav($tmpWav);
sw_scenario_assert(substr((string)file_get_contents($tmpWav, false, null, 0, 12), 0, 4) === 'RIFF', 'Scenario recording WAV should have a RIFF header.');

$uploaded = 0;
foreach ($speakingQuestions as $question) {
    $rowId = (int)$question['id'];
    $upload = $student->postMultipart('/user/ajax_save_toeic_sw_recording.php', [
        'csrf_token' => $speakingCsrf,
        'test_session' => $testSession,
        'section' => 'speaking',
        'question_row_id' => (string)$rowId,
        'recording' => new CURLFile($tmpWav, 'audio/wav', 'scenario-speaking-answer.wav'),
    ]);
    $uploadJson = sw_scenario_decode_json_response($upload, "Recording upload row {$rowId}");
    sw_scenario_assert(!empty($uploadJson['success']), "Recording upload should succeed for row {$rowId}.");
    if (!empty($uploadJson['success'])) {
        $uploaded++;
    }
}

$missingAfterUpload = 0;
$stmt = $conn->prepare("
    SELECT COUNT(*) AS missing
    FROM toeic_sw_test_questions
    WHERE test_session = ? AND user_id = ? AND section = 'speaking'
      AND (source_path IS NULL OR source_path = '')
");
$stmt->bind_param('si', $testSession, $studentId);
$stmt->execute();
$missingAfterUpload = (int)($stmt->get_result()->fetch_assoc()['missing'] ?? 0);
$stmt->close();
sw_scenario_assert($missingAfterUpload === 0, 'All Speaking rows should have uploaded source_path values.');

$submitSpeaking = $student->postJson('/user/ajax_submit_section_toeic_sw.php', [
    'csrf_token' => $speakingCsrf,
    'test_session' => $testSession,
    'section' => 'speaking',
], $speakingCsrf);
$submitSpeakingJson = sw_scenario_decode_json_response($submitSpeaking, 'Speaking submit');
sw_scenario_assert(!empty($submitSpeakingJson['success']), 'Speaking submit should succeed after all recordings are uploaded.');
sw_scenario_assert(strpos((string)($submitSpeakingJson['redirect'] ?? ''), 'section=writing') !== false, 'Speaking submit should redirect to Writing.');

$writingPage = $student->request('GET', '/user/' . ltrim((string)$submitSpeakingJson['redirect'], '/'));
$writingConfig = sw_scenario_extract_config($writingPage['body']);
$writingCsrf = (string)($writingConfig['csrfToken'] ?? '');
sw_scenario_assert((string)($writingConfig['section'] ?? '') === 'writing', 'Writing page config should identify the writing section.');
sw_scenario_assert(strpos($writingPage['body'], 'Task 10m') !== false, 'Writing page should display 10-minute task timers for Q6-Q7.');
sw_scenario_assert(strpos($writingPage['body'], '300 words is a quality target, not a submit blocker.') !== false, 'Writing page should show Q8 target as a warning, not a blocker.');

$q8Words = 0;
foreach ($writingQuestions as $question) {
    $rowId = (int)$question['id'];
    $order = (int)$question['question_order'];
    $content = $question['content'] ?? [];
    $answer = '';
    if ($order <= 5) {
        $words = json_decode((string)($content['required_words_json'] ?? '[]'), true) ?: ['required term one', 'required term two'];
        $answer = 'The workplace scene clearly includes "' . (string)$words[0] . '" while "' . (string)$words[1] . '" helps explain the main professional action.';
    } elseif ($order <= 7) {
        $answer = 'Dear colleague, thank you for the detailed request. I can support the revised arrangement, but I recommend confirming the final timeline with operations before we notify the external participants. I will review the attached materials, identify any compliance concerns, and send a concise update by tomorrow afternoon. If the venue or speaker list changes again, please let me know immediately so that the team can avoid duplicate communication and unnecessary preparation work.';
    } else {
        $answer = 'Companies should allow experienced employees to choose hybrid schedules when their roles can be measured by outcomes rather than physical presence. A flexible policy improves retention, widens the hiring pool, and gives focused contributors more control over deep work. However, it should not become an excuse for weak coordination. Managers need clear availability windows, documented decisions, and regular team rituals so that collaboration remains visible. In my view, hybrid work is strongest when it is earned through accountability and supported by transparent communication standards.';
        $q8Words = sw_scenario_count_words($answer);
    }

    $save = $student->postJson('/user/ajax_save_toeic_sw_answer.php', [
        'csrf_token' => $writingCsrf,
        'test_session' => $testSession,
        'section' => 'writing',
        'question_row_id' => $rowId,
        'answer' => $answer,
    ], $writingCsrf);
    $saveJson = sw_scenario_decode_json_response($save, "Writing save row {$rowId}");
    sw_scenario_assert(!empty($saveJson['success']), "Writing answer should save for row {$rowId}.");
}
sw_scenario_assert($q8Words > 0 && $q8Words < 300, 'Writing Q8 scenario answer should remain below 300 words to prove submit is not hard-blocked.');

$submitWriting = $student->postJson('/user/ajax_submit_section_toeic_sw.php', [
    'csrf_token' => $writingCsrf,
    'test_session' => $testSession,
    'section' => 'writing',
], $writingCsrf);
$submitWritingJson = sw_scenario_decode_json_response($submitWriting, 'Writing submit');
sw_scenario_assert(!empty($submitWritingJson['success']), 'Writing submit should succeed even when Q8 is below 300 words.');
sw_scenario_assert(strpos((string)($submitWritingJson['redirect'] ?? ''), 'result_toeic_sw.php') !== false, 'Writing submit should redirect to SW result.');

$resultPage = $student->request('GET', '/user/' . ltrim((string)$submitWritingJson['redirect'], '/'));
sw_scenario_assert(strpos($resultPage['body'], 'Speaking') !== false, 'Result page should show Speaking score context.');
sw_scenario_assert(strpos($resultPage['body'], 'Writing') !== false, 'Result page should show Writing score context.');
sw_scenario_assert(strpos($resultPage['body'], '/400') !== false, 'Result page should show total /400 summary.');

$scoreRows = [];
$result = $conn->query("
    SELECT status,
           COUNT(*) AS total,
           SUM(CASE WHEN raw_score IS NOT NULL AND normalized_score IS NOT NULL AND feedback_json IS NOT NULL AND feedback_json <> '' THEN 1 ELSE 0 END) AS transparent,
           SUM(CASE WHEN status = 'needs_rescore' AND fallback_reason IS NOT NULL AND fallback_reason <> '' THEN 1 ELSE 0 END) AS fallback_explained
    FROM toeic_sw_subjective_scores
    WHERE test_session = '" . $conn->real_escape_string($testSession) . "'
    GROUP BY status
");
while ($row = $result->fetch_assoc()) {
    $scoreRows[(string)$row['status']] = [
        'total' => (int)$row['total'],
        'transparent' => (int)$row['transparent'],
        'fallback_explained' => (int)$row['fallback_explained'],
    ];
}

$scoreCount = (int)$conn->query("SELECT COUNT(*) AS total FROM toeic_sw_subjective_scores WHERE test_session = '" . $conn->real_escape_string($testSession) . "'")->fetch_assoc()['total'];
$finalResult = $conn->query("SELECT speaking_scaled, writing_scaled, total_score, cefr_level FROM toeic_sw_test_results WHERE test_session = '" . $conn->real_escape_string($testSession) . "' LIMIT 1")->fetch_assoc();
sw_scenario_assert($scoreCount === 19, 'Scenario should produce 19 subjective score rows.');
sw_scenario_assert(is_array($finalResult), 'Scenario should create a final SW result row.');
sw_scenario_assert((int)($finalResult['total_score'] ?? -1) >= 0 && (int)($finalResult['total_score'] ?? -1) <= 400, 'Total score should be on the /400 scale.');

$admin = new ToeicSwScenarioHttpClient($baseUrl);
$adminLogin = $admin->postForm('/login.php', [
    'username' => $adminUsername,
    'password' => $adminPassword,
]);
sw_scenario_assert($adminLogin['status'] === 200, 'Admin login should return HTTP 200 after redirects.');
sw_scenario_assert(strpos($adminLogin['url'], '/admin/') !== false, 'Admin login should redirect into /admin/.');
$adminDetail = $admin->request('GET', '/admin/toeic_sw_result_detail.php?session=' . urlencode($testSession));
$adminChecks = [
    'Second-analysis view',
    'Raw Score',
    'Normalized Score',
    'Student Recording',
    'Student Answer / Transcript',
    'Provider:',
    'Model:',
    'Feedback JSON',
];
foreach ($adminChecks as $needle) {
    sw_scenario_assert(strpos($adminDetail['body'], $needle) !== false, "Admin detail should expose {$needle}.");
}
if (isset($scoreRows['needs_rescore'])) {
    sw_scenario_assert(strpos($adminDetail['body'], 'Fallback Reason') !== false, 'Admin detail should show fallback reasons for needs_rescore items.');
}

$report = [
    'base_url' => $baseUrl,
    'student_user_id' => $studentId,
    'admin_user_id' => $adminId,
    'session' => $testSession,
    'credits' => [
        'active_before_start' => $activeBefore,
        'active_after_start' => $activeAfterStart,
    ],
    'counts' => [
        'speaking_questions' => count($speakingQuestions),
        'writing_questions' => count($writingQuestions),
        'speaking_prompt_audio' => $promptAudioCount,
        'package_images' => $imageCount,
        'recordings_uploaded' => $uploaded,
        'subjective_scores' => $scoreCount,
    ],
    'guards' => [
        'speaking_blocked_before_recordings' => empty($blockedJson['success']),
        'missing_recordings_after_upload' => $missingAfterUpload,
        'writing_q8_words_under_300' => $q8Words,
    ],
    'scores' => $finalResult,
    'score_statuses' => $scoreRows,
    'browser_urls' => [
        'student_result' => $baseUrl . '/user/result_toeic_sw.php?session=' . rawurlencode($testSession),
        'admin_detail' => $baseUrl . '/admin/toeic_sw_result_detail.php?session=' . rawurlencode($testSession),
    ],
];

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC SW user-flow scenario failed:\n- " . implode("\n- ", $failures) . "\n");
    fwrite(STDERR, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo "TOEIC SW user-flow scenario passed.\n";
