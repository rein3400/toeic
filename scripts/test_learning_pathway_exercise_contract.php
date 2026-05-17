<?php
/**
 * Static contract checks for Learning Pathway exercise feedback.
 */

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/user/learning_pathway.php');
$endpoint = (string)file_get_contents($root . '/user/ajax_check_exercise.php');
$failures = [];

function pathway_exercise_check(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

pathway_exercise_check(
    strpos($endpoint, '$is_admin') !== false && strpos($endpoint, 'c.user_id = ? OR ? = 1') !== false,
    'Exercise answer endpoint must allow admins who can view the pathway page.'
);

pathway_exercise_check(
    strpos($page, '$pathway_user_id') !== false && strpos($page, 'SELECT user_id FROM {$table} WHERE test_session = ?') !== false,
    'Learning pathway must resolve the session owner when an admin opens a user pathway.'
);

pathway_exercise_check(
    strpos($page, '$stmt->bind_param("is", $pathway_user_id, $test_session)') !== false
        && strpos($page, '$stmt->bind_param("i", $pathway_user_id)') !== false,
    'Learning pathway curriculum/progress queries must use the pathway owner, not the admin session user.'
);

pathway_exercise_check(
    strpos($page, 'if (!data.success) throw new Error') !== false,
    'Learning pathway JS must stop on failed AJAX responses instead of rendering undefined feedback.'
);

pathway_exercise_check(
    strpos($page, 'formatCorrectAnswer') !== false && strpos($page, 'Correct answer: \' + formatCorrectAnswer(data)') !== false,
    'Incorrect feedback must format a safe correct-answer fallback.'
);

if (!empty($failures)) {
    fwrite(STDERR, "Learning pathway exercise contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Learning pathway exercise contract passed.\n";
