<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/ai_helper.php';

// Check admin authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$questions = $input['questions'] ?? [];
$variations = intval($input['variations'] ?? 2);
$difficulty = $input['difficulty'] ?? 'same';

if (empty($questions)) {
    die(json_encode(['success' => false, 'error' => 'No questions provided']));
}

if ($variations < 1 || $variations > 5) {
    die(json_encode(['success' => false, 'error' => 'Variations must be between 1 and 5']));
}

try {
    // Check if AI is configured
    $config = getActiveAIProvider();
    if (!$config) {
        throw new Exception("AI provider not configured. Please set up AI API in admin settings.");
    }

    // Paraphrase questions
    $paraphrased = paraphraseQuestionsBatch($questions, $variations, $difficulty);

    echo json_encode([
        'success' => true,
        'questions' => $paraphrased,
        'original_count' => count($questions),
        'total_count' => count($paraphrased),
        'variations_per_question' => $variations
    ]);

} catch (Exception $e) {
    error_log("Paraphrase Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>