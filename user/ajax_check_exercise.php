<?php
/**
 * AJAX: Check exercise answer and return feedback
 */
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$exercise_id = (int)($input['exercise_id'] ?? 0);
$user_answer = trim($input['answer'] ?? '');

if ($exercise_id <= 0 || $user_answer === '') {
    echo json_encode(['success' => false, 'error' => 'Missing exercise_id or answer']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Get exercise with access check
$stmt = $conn->prepare("
    SELECT e.*, m.curriculum_id
    FROM learning_exercises e
    JOIN learning_modules m ON e.module_id = m.id
    JOIN learning_curriculum c ON m.curriculum_id = c.id
    WHERE e.id = ? AND c.user_id = ?
");
$stmt->bind_param("ii", $exercise_id, $user_id);
$stmt->execute();
$exercise = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exercise) {
    echo json_encode(['success' => false, 'error' => 'Exercise not found']);
    exit();
}

$correct_answer_raw = trim((string)$exercise['correct_answer']);
$correct_answer = normalizeExerciseAnswer($correct_answer_raw);
$normalized_user_answer = normalizeExerciseAnswer($user_answer);
$is_correct = ($normalized_user_answer === $correct_answer);

$options = json_decode($exercise['options_json'] ?? '[]', true) ?: [];
$user_letter = null;
$correct_letter = null;

if ($exercise['type'] === 'multiple_choice') {
    $user_letter = detectExerciseAnswerLetter($user_answer, $options);
    $correct_letter = detectExerciseAnswerLetter($correct_answer_raw, $options);
}

if (!$is_correct && $exercise['type'] === 'multiple_choice') {

    if ($user_letter !== null && $correct_letter !== null) {
        $is_correct = ($user_letter === $correct_letter);
    } elseif ($user_letter !== null && normalizeExerciseAnswer($correct_answer_raw) === normalizeExerciseAnswer($user_letter)) {
        $is_correct = true;
    }

    // Fallback: if correct_answer is a numeric index (0-based or 1-based), convert to letter
    if (!$is_correct && $user_letter !== null && is_numeric($correct_answer_raw)) {
        $idx = (int)$correct_answer_raw;
        // Try 0-based first
        if ($idx >= 0 && $idx < count($options)) {
            $correct_letter = chr(65 + $idx);
            $is_correct = ($user_letter === $correct_letter);
        }
        // Try 1-based if 0-based didn't match
        if (!$is_correct && $idx >= 1 && $idx <= count($options)) {
            $correct_letter = chr(64 + $idx);
            $is_correct = ($user_letter === $correct_letter);
        }
    }
}

echo json_encode([
    'success' => true,
    'correct' => $is_correct,
    'correct_answer' => $exercise['correct_answer'],
    'correct_letter' => $correct_letter,
    'explanation' => $exercise['explanation_html'],
    'points' => $is_correct ? (int)$exercise['points'] : 0,
]);

function normalizeExerciseAnswer($value) {
    $normalized = mb_strtolower(trim((string)$value));
    $normalized = preg_replace('/^[a-z]\s*[\)\.\:\-]\s*/iu', '', $normalized);
    return preg_replace('/\s+/u', ' ', $normalized);
}

function detectExerciseAnswerLetter($value, array $options) {
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }

    if (preg_match('/^[A-Z]$/i', $trimmed)) {
        return strtoupper($trimmed);
    }

    if (preg_match('/^([A-Z])\s*[\)\.\:\-]/i', $trimmed, $matches)) {
        return strtoupper($matches[1]);
    }

    $normalized = normalizeExerciseAnswer($trimmed);
    foreach ($options as $index => $option) {
        if (normalizeExerciseAnswer($option) === $normalized) {
            return chr(65 + $index);
        }
    }

    return null;
}
