<?php
/**
 * Audit TOEIC question bank for structural/content anomalies.
 *
 * Usage:
 *   php scripts/audit_toeic_question_bank.php
 *   /scripts/audit_toeic_question_bank.php   (admin only)
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/../includes/session_handler.php';
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../admin/login.php");
        exit();
    }
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../includes/config.php';

$sql = "
    SELECT 'listening' AS section, part, nomor_soal, id_soal, question_type, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation
    FROM toeic_soal_listening
    UNION ALL
    SELECT 'reading' AS section, part, nomor_soal, id_soal, question_type, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation
    FROM toeic_soal_reading
    ORDER BY section, CAST(part AS UNSIGNED), nomor_soal, id_soal
";

$res = $conn->query($sql);
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}

$expectedTypes = [
    '1' => 'part1_photograph',
    '2' => 'part2_question_response',
    '3' => 'part3_conversation',
    '4' => 'part4_talk',
    '5' => 'part5_incomplete_sentence',
    '6' => 'part6_text_completion',
    '7' => 'part7_single_passage',
];

$answerDist = [];
$issues = [
    'type_mismatch' => [],
    'missing_options' => [],
    'bad_correct_answer' => [],
    'placeholder_options' => [],
    'empty_explanation' => [],
    'duplicate_question' => [],
];
$questionIndex = [];

foreach ($rows as $row) {
    $part = (string)$row['part'];
    $id = (int)$row['id_soal'];
    $correct = trim((string)$row['jawaban_benar']);

    if (!isset($answerDist[$part])) {
        $answerDist[$part] = [];
    }
    $answerDist[$part][$correct] = ($answerDist[$part][$correct] ?? 0) + 1;

    if (($expectedTypes[$part] ?? null) !== null && $row['question_type'] !== $expectedTypes[$part]) {
        $issues['type_mismatch'][] = [
            'id' => $id,
            'part' => $part,
            'type' => $row['question_type'],
            'expected' => $expectedTypes[$part],
        ];
    }

    $options = [];
    foreach (['A' => 'opsi_a', 'B' => 'opsi_b', 'C' => 'opsi_c', 'D' => 'opsi_d'] as $letter => $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            $options[$letter] = $value;
        }
    }

    $expectedOptionCount = $part === '2' ? 3 : 4;
    if (count($options) !== $expectedOptionCount) {
        $issues['missing_options'][] = [
            'id' => $id,
            'part' => $part,
            'count' => count($options),
            'correct' => $correct,
        ];
    }

    $validLetters = $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
    if (!in_array($correct, $validLetters, true)) {
        $issues['bad_correct_answer'][] = [
            'id' => $id,
            'part' => $part,
            'correct' => $correct,
        ];
    }

    $placeholder = true;
    foreach ($validLetters as $letter) {
        if (!isset($options[$letter]) || strtoupper(trim($options[$letter])) !== $letter) {
            $placeholder = false;
            break;
        }
    }
    if ($placeholder) {
        $issues['placeholder_options'][] = [
            'id' => $id,
            'part' => $part,
            'question' => mb_substr(trim((string)$row['pertanyaan']), 0, 120),
        ];
    }

    if (trim((string)$row['explanation']) === '') {
        $issues['empty_explanation'][] = [
            'id' => $id,
            'part' => $part,
        ];
    }

    $normalizedQuestion = trim(preg_replace('/\s+/', ' ', mb_strtolower((string)$row['pertanyaan'])));
    $qKey = $part . '|' . $normalizedQuestion;
    $questionIndex[$qKey][] = $id;
}

foreach ($questionIndex as $key => $ids) {
    if (count($ids) > 1) {
        [$part, $question] = explode('|', $key, 2);
        $issues['duplicate_question'][] = [
            'part' => $part,
            'ids' => $ids,
            'question' => mb_substr($question, 0, 140),
        ];
    }
}

$output = [
    'total' => count($rows),
    'answer_distribution' => $answerDist,
    'issue_counts' => array_map('count', $issues),
    'issues' => $issues,
];

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
