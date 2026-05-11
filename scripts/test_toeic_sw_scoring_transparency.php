<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
require_once __DIR__ . '/../includes/toeic_sw_helper.php';
require_once __DIR__ . '/../includes/toeic_sw_test_builder.php';
require_once __DIR__ . '/../includes/toeic_sw_scorer.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

ensureToeicSwSchema($conn);

$readiness = getToeicSwContentReadiness($conn);
if (empty($readiness['ready'])) {
    fwrite(STDERR, "TOEIC SW content is not ready. Import packages first.\n");
    exit(1);
}

$userId = 1;
$session = generateToeicSwTestSession();
$builder = new ToeicSwTestBuilder($conn);
$builder->createSession($session, $userId, ['package_number' => 1]);
$builder->buildTest($session, $userId, ['package_number' => 1]);

$speakingAnswer = 'content/generated/toeic_sw/package_01/audio/pkg01_speaking_q05_loud.wav';
$stmt = $conn->prepare("UPDATE toeic_sw_test_questions SET source_path = ?, user_answer = ? WHERE test_session = ? AND section = 'speaking'");
$stmt->bind_param("sss", $speakingAnswer, $speakingAnswer, $session);
$stmt->execute();
$stmt->close();

$writingAnswer = "Thank you for your message. I understand the revised schedule and will check the team's availability before confirming the final arrangement. I will also review the required documents, note any compliance concerns, and send you a concise update with the next practical step by tomorrow afternoon.";
$stmt = $conn->prepare("UPDATE toeic_sw_test_questions SET user_answer = ? WHERE test_session = ? AND section = 'writing'");
$stmt->bind_param("ss", $writingAnswer, $session);
$stmt->execute();
$stmt->close();

$scorer = new ToeicSwScorer($conn);
$speakingAverage = $scorer->scoreSection($session, 'speaking');
$writingAverage = $scorer->scoreSection($session, 'writing');
$scorer->saveResults($session, $userId);

$statusRows = [];
$result = $conn->query("SELECT status, COUNT(*) AS total, SUM(CASE WHEN fallback_reason IS NOT NULL AND fallback_reason <> '' THEN 1 ELSE 0 END) AS transparent FROM toeic_sw_subjective_scores WHERE test_session = '{$conn->real_escape_string($session)}' GROUP BY status");
while ($row = $result->fetch_assoc()) {
    $statusRows[$row['status']] = [
        'total' => (int)$row['total'],
        'transparent' => (int)$row['transparent'],
    ];
}

$score = $conn->query("SELECT speaking_scaled, writing_scaled, total_score FROM toeic_sw_test_results WHERE test_session = '{$conn->real_escape_string($session)}' LIMIT 1")->fetch_assoc();
$questionCount = (int)$conn->query("SELECT COUNT(*) AS total FROM toeic_sw_test_questions WHERE test_session = '{$conn->real_escape_string($session)}'")->fetch_assoc()['total'];
$feedbackCount = (int)$conn->query("SELECT COUNT(*) AS total FROM toeic_sw_subjective_scores WHERE test_session = '{$conn->real_escape_string($session)}'")->fetch_assoc()['total'];

$report = [
    'session' => $session,
    'question_rows' => $questionCount,
    'feedback_rows' => $feedbackCount,
    'status_counts' => $statusRows,
    'speaking_average' => $speakingAverage,
    'writing_average' => $writingAverage,
    'result' => $score,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($questionCount !== 19 || $feedbackCount !== 19 || empty($score)) {
    exit(1);
}
