<?php
declare(strict_types=1);

/**
 * Local HTTP scenario for admin TOEIC SW second-analysis media.
 *
 * Creates a small SW review fixture, then verifies an admin can:
 * - open the SW detail page,
 * - see the student's written answer and speaking transcript,
 * - receive the speaking recording bytes from the secure admin stream endpoint.
 */

$root = dirname(__DIR__);
$baseUrl = rtrim((string)($argv[1] ?? getenv('TOEIC_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$adminUsername = getenv('TOEIC_SW_REVIEW_ADMIN_USER') ?: 'sw_review_admin_20260511';
$adminPassword = getenv('TOEIC_SW_REVIEW_ADMIN_PASS') ?: 'reviewAdminPass123';
$studentUsername = getenv('TOEIC_SW_REVIEW_STUDENT_USER') ?: 'sw_review_student_20260511';
$studentPassword = getenv('TOEIC_SW_REVIEW_STUDENT_PASS') ?: 'reviewStudentPass123';
$testSession = getenv('TOEIC_SW_REVIEW_SESSION') ?: 'toeic_sw_review_fixture_20260511';

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

$failures = [];

function sw_review_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function sw_review_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sw_review_upsert_user(mysqli $conn, string $username, string $password, string $role): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = $role === 'admin' ? 'SW Review Admin' : 'SW Review Student';
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
        sw_review_fail('Unable to prepare user upsert: ' . $conn->error);
    }
    $stmt->bind_param('sssss', $username, $hash, $fullName, $email, $role);
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

function sw_review_write_wav(string $path, float $seconds = 0.8): void {
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

function sw_review_question_id(mysqli $conn, string $testSession, string $section, int $order): int {
    $stmt = $conn->prepare("SELECT id FROM toeic_sw_test_questions WHERE test_session = ? AND section = ? AND question_order = ? LIMIT 1");
    $stmt->bind_param('ssi', $testSession, $section, $order);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['id'] ?? 0);
}

function sw_review_prepare_fixture(mysqli $conn, int $studentId, string $testSession): array {
    ensureToeicSwSchema($conn);

    $safeSession = preg_replace('/[^A-Za-z0-9_-]/', '_', $testSession);
    $baseDir = dirname(__DIR__) . '/storage/toeic_sw_recordings/' . $studentId . '/' . $safeSession;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
        sw_review_fail('Unable to create fixture recording directory.');
    }
    $recordingPath = $baseDir . '/admin_review_fixture.wav';
    sw_review_write_wav($recordingPath);
    $relativeRecording = 'storage/toeic_sw_recordings/' . $studentId . '/' . $safeSession . '/admin_review_fixture.wav';
    $writtenAnswer = 'Dear Facilities Team, I am writing to confirm that the revised room assignment has been received. The updated plan is clear, and I will notify the participants before the end of the day.';
    $speakingTranscript = 'The speaker clearly explains that the revised schedule should be confirmed with the facilities team before notifying participants.';
    $feedbackJson = json_encode(['summary' => 'Fixture feedback visible to admin.', 'rubric' => ['clarity' => 0.8]], JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("
        INSERT INTO toeic_sw_test_sessions
            (test_session, user_id, package_number, current_section, status, speaking_scaled, writing_scaled, total_score, completed_at)
        VALUES (?, ?, 1, 'writing', 'completed', 160, 170, 330, NOW())
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            package_number = VALUES(package_number),
            current_section = VALUES(current_section),
            status = VALUES(status),
            speaking_scaled = VALUES(speaking_scaled),
            writing_scaled = VALUES(writing_scaled),
            total_score = VALUES(total_score),
            completed_at = VALUES(completed_at)
    ");
    $stmt->bind_param('si', $testSession, $studentId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO toeic_sw_test_questions
            (test_session, user_id, package_number, question_id, source_table, question_type, section, part, question_order, source_path, user_answer)
        VALUES (?, ?, 1, 1, 'toeic_sw_read_aloud', 'read_text_aloud', 'speaking', 'S1', 1, ?, ?)
        ON DUPLICATE KEY UPDATE
            source_path = VALUES(source_path),
            user_answer = VALUES(user_answer)
    ");
    $stmt->bind_param('siss', $testSession, $studentId, $relativeRecording, $relativeRecording);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO toeic_sw_test_questions
            (test_session, user_id, package_number, question_id, source_table, question_type, section, part, question_order, user_answer)
        VALUES (?, ?, 1, 1, 'toeic_sw_written_request', 'respond_to_written_request', 'writing', 'W2', 6, ?)
        ON DUPLICATE KEY UPDATE
            user_answer = VALUES(user_answer)
    ");
    $stmt->bind_param('sis', $testSession, $studentId, $writtenAnswer);
    $stmt->execute();
    $stmt->close();

    $speakingRowId = sw_review_question_id($conn, $testSession, 'speaking', 1);
    $writingRowId = sw_review_question_id($conn, $testSession, 'writing', 6);
    if ($speakingRowId <= 0 || $writingRowId <= 0) {
        sw_review_fail('Unable to locate fixture question rows.');
    }

    $status = 'scored';
    $provider = 'fixture';
    $model = 'fixture-review-model';
    $section = 'speaking';
    $questionType = 'read_text_aloud';
    $raw = 4.0;
    $normalized = 0.8;
    $stmt = $conn->prepare("
        INSERT INTO toeic_sw_subjective_scores
            (test_session, user_id, question_row_id, question_id, question_type, section, source_path, transcript_text, raw_score, normalized_score, feedback_json, ai_provider, ai_model, status)
        VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            source_path = VALUES(source_path),
            transcript_text = VALUES(transcript_text),
            raw_score = VALUES(raw_score),
            normalized_score = VALUES(normalized_score),
            feedback_json = VALUES(feedback_json),
            ai_provider = VALUES(ai_provider),
            ai_model = VALUES(ai_model),
            status = VALUES(status)
    ");
    $stmt->bind_param('siissssddssss', $testSession, $studentId, $speakingRowId, $questionType, $section, $relativeRecording, $speakingTranscript, $raw, $normalized, $feedbackJson, $provider, $model, $status);
    $stmt->execute();
    $stmt->close();

    $section = 'writing';
    $questionType = 'respond_to_written_request';
    $raw = 4.5;
    $normalized = 0.9;
    $stmt = $conn->prepare("
        INSERT INTO toeic_sw_subjective_scores
            (test_session, user_id, question_row_id, question_id, question_type, section, source_path, transcript_text, raw_score, normalized_score, feedback_json, ai_provider, ai_model, status)
        VALUES (?, ?, ?, 1, ?, ?, '', '', ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            raw_score = VALUES(raw_score),
            normalized_score = VALUES(normalized_score),
            feedback_json = VALUES(feedback_json),
            ai_provider = VALUES(ai_provider),
            ai_model = VALUES(ai_model),
            status = VALUES(status)
    ");
    $stmt->bind_param('siissddssss', $testSession, $studentId, $writingRowId, $questionType, $section, $raw, $normalized, $feedbackJson, $provider, $model, $status);
    $stmt->execute();
    $stmt->close();

    return [
        'speaking_row_id' => $speakingRowId,
        'written_answer' => $writtenAnswer,
        'speaking_transcript' => $speakingTranscript,
    ];
}

final class SwReviewHttpClient {
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl) {
        $this->baseUrl = $baseUrl;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'toeic_sw_review_cookie_') ?: '';
        if ($this->cookieFile === '') {
            sw_review_fail('Unable to create temporary cookie file.');
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
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($error !== '') {
            sw_review_fail('HTTP request failed for ' . $url . ': ' . $error);
        }

        return [
            'status' => $status,
            'body' => (string)$response,
            'content_type' => $contentType,
            'url' => $effectiveUrl,
        ];
    }

    public function postForm(string $path, array $fields): array {
        return $this->request('POST', $path, http_build_query($fields), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }
}

$adminId = sw_review_upsert_user($conn, $adminUsername, $adminPassword, 'admin');
$studentId = sw_review_upsert_user($conn, $studentUsername, $studentPassword, 'student');
if ($adminId <= 0 || $studentId <= 0) {
    sw_review_fail('Unable to create fixture users.');
}
$fixture = sw_review_prepare_fixture($conn, $studentId, $testSession);

$client = new SwReviewHttpClient($baseUrl);
$login = $client->postForm('/login.php', [
    'username' => $adminUsername,
    'password' => $adminPassword,
]);
sw_review_assert($login['status'] === 200, 'Admin login should return HTTP 200 after redirects.');
sw_review_assert(strpos($login['url'], '/admin/') !== false, 'Admin login should redirect into /admin/.');

$detail = $client->request('GET', '/admin/toeic_sw_result_detail.php?session=' . rawurlencode($testSession));
sw_review_assert($detail['status'] === 200, 'Admin SW detail should render.');
sw_review_assert(strpos($detail['body'], 'stream_toeic_sw_recording.php') !== false, 'Admin detail should include the secure recording stream URL.');
sw_review_assert(strpos($detail['body'], htmlspecialchars($fixture['written_answer'], ENT_QUOTES, 'UTF-8')) !== false, 'Admin detail should show the writing answer.');
sw_review_assert(strpos($detail['body'], htmlspecialchars($fixture['speaking_transcript'], ENT_QUOTES, 'UTF-8')) !== false, 'Admin detail should show the speaking transcript.');

$stream = $client->request(
    'GET',
    '/admin/stream_toeic_sw_recording.php?session=' . rawurlencode($testSession) . '&question_id=' . (int)$fixture['speaking_row_id'],
    null,
    ['Range: bytes=0-15']
);
sw_review_assert($stream['status'] === 206, 'Recording stream should support byte ranges for the audio player.');
sw_review_assert(stripos($stream['content_type'], 'audio/wav') !== false, 'Recording stream should return audio/wav content type.');
sw_review_assert(substr($stream['body'], 0, 4) === 'RIFF', 'Recording stream should return WAV bytes.');

if ($failures) {
    fwrite(STDERR, "Admin TOEIC SW review media scenario failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin TOEIC SW review media scenario passed.\n";
echo "Detail page shows writing answer and speaking transcript.\n";
echo "Secure admin recording stream returned WAV bytes.\n";
