<?php
/**
 * Admin-friendly entry point for the TOEIC question-bank preview.
 *
 * The inspector implementation lives in scripts/view_all_questions_answers.php
 * because it also supports CLI usage.
 */

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '../scripts/view_all_questions_answers.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target);
exit();
