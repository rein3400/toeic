<?php
/**
 * Static contract checks for admin TOEIC score explanation generation.
 *
 * The production page can show practice question logs even when a practice
 * session has no target_part, so explanation generation must fall back to the
 * saved question log instead of rejecting the session.
 */

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/admin/ajax_generate_score_explanation.php');
$failures = [];

function admin_score_explanation_check(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

admin_score_explanation_check(
    strpos($endpoint, 'function collectToeicQuestionLogSummary') !== false,
    'Score explanation endpoint must collect a reusable question-log summary.'
);

admin_score_explanation_check(
    strpos($endpoint, 'Practice TOEIC berdasarkan log soal') !== false,
    'Practice explanation must support mixed/no-target-part sessions from question logs.'
);

admin_score_explanation_check(
    strpos($endpoint, "throw new Exception('Data practice TOEIC belum cukup untuk dijelaskan')") === false,
    'Practice sessions with question logs must not be rejected before log fallback.'
);

admin_score_explanation_check(
    strpos($endpoint, 'target_part') !== false && strpos($endpoint, 'Mixed practice') !== false,
    'Practice explanation must distinguish target-part practice from mixed practice.'
);

if (!empty($failures)) {
    fwrite(STDERR, "Admin score explanation contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin score explanation contract passed.\n";
