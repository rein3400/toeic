<?php
/**
 * Static contract checks for student-owned TOEIC result transparency.
 */

$root = dirname(__DIR__);
$failures = [];

function user_result_visibility_check(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$lrPath = $root . '/user/result_toeic.php';
$swPath = $root . '/user/result_toeic_sw.php';

user_result_visibility_check(file_exists($lrPath), 'user/result_toeic.php must exist.');
user_result_visibility_check(file_exists($swPath), 'user/result_toeic_sw.php must exist.');

$lr = file_exists($lrPath) ? (string)file_get_contents($lrPath) : '';
$sw = file_exists($swPath) ? (string)file_get_contents($swPath) : '';

user_result_visibility_check(strpos($lr, 'toeic_test_questions') !== false, 'LR result page must read the answered question log.');
user_result_visibility_check(strpos($lr, 'tq.user_id = ?') !== false, 'LR question log must be scoped to the logged-in student.');
user_result_visibility_check(strpos($lr, 'toeic_soal_listening') !== false, 'LR result page must join listening source answers.');
user_result_visibility_check(strpos($lr, 'toeic_soal_reading') !== false, 'LR result page must join reading source answers.');
user_result_visibility_check(strpos($lr, 'Correct Answer') !== false, 'LR result page must show the correct answer for review.');
user_result_visibility_check(strpos($lr, 'Your Answer') !== false, 'LR result page must show the student answer for review.');
user_result_visibility_check(strpos($lr, 'Question Review') !== false, 'LR result page must include a visible question review section.');
user_result_visibility_check(strpos($lr, 'Listening') !== false && strpos($lr, 'Reading') !== false, 'LR result page must distinguish Listening and Reading rows.');

user_result_visibility_check(strpos($sw, 'toeic_sw_subjective_scores') !== false, 'SW result page must read AI subjective scores.');
user_result_visibility_check(strpos($sw, 'q.user_id = ?') !== false, 'SW feedback rows must be scoped to the logged-in student.');
user_result_visibility_check(strpos($sw, 'AI Score') !== false, 'SW result page must label item scores as AI scores.');
user_result_visibility_check(strpos($sw, 'Raw:') !== false, 'SW result page must show raw AI scores.');
user_result_visibility_check(strpos($sw, 'Normalized:') !== false, 'SW result page must show normalized AI scores.');
user_result_visibility_check(strpos($sw, 'Speaking') !== false && strpos($sw, 'Writing') !== false, 'SW result page must show Speaking and Writing score contexts.');

if (!empty($failures)) {
    fwrite(STDERR, "User result visibility contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "User result visibility contract passed.\n";
