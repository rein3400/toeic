<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

// Check admin authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$section = $input['section'] ?? '';
$questions = $input['questions'] ?? [];

if (empty($section) || empty($questions)) {
    die(json_encode(['success' => false, 'error' => 'Invalid input']));
}

$context_id = $input['context_id'] ?? null;

$imported = 0;
$failed = 0;
$errors = [];

try {
    $part = $input['part'] ?? 'A';  // Get part from input, default to 'A'

    switch ($section) {
        case 'structure':
            $result = importStructureQuestions($questions, $part);
            break;
        case 'reading':
            if (empty($context_id))
                throw new Exception('Reading passage ID required');
            $result = importReadingQuestions($questions, $context_id);
            break;
        case 'listening':
            if (empty($context_id))
                throw new Exception('Audio ID required');
            $result = importListeningQuestions($questions, $context_id);
            break;
        default:
            throw new Exception('Invalid section');
    }

    $imported = $result['imported'];
    $failed = $result['failed'];
    $errors = $result['errors'];

    echo json_encode([
        'success' => true,
        'imported_count' => $imported,
        'failed_count' => $failed,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function importStructureQuestions($questions, $part = 'A')
{
    global $conn;
    $imported = 0;
    $failed = 0;
    $errors = [];

    // Get current max nomor_soal
    $result = $conn->query("SELECT MAX(nomor_soal) as max_num FROM soal_structure");
    $row = $result->fetch_assoc();
    $next_num = ($row['max_num'] ?? 0) + 1;

    foreach ($questions as $q) {
        // Skip if error exists
        if (isset($q['error'])) {
            $failed++;
            $errors[] = $q['error'];
            continue;
        }

        try {
            // Use the part parameter passed from frontend
            // No need for auto-detection anymore

            $stmt = $conn->prepare("
                INSERT INTO soal_structure 
                (pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, part, difficulty, nomor_soal) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'medium', ?)
            ");

            $stmt->bind_param(
                "sssssssi",
                $q['question'],
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d'],
                $q['correct_answer'],
                $part,
                $next_num
            );

            if ($stmt->execute()) {
                $imported++;
                $next_num++; // Increment for next question
            } else {
                // If duplicate key error on nomor_soal, try auto-repairing the sequence
                if ($conn->errno == 1062 && strpos($conn->error, 'nomor_soal') !== false) {
                    // Re-fetch max num and retry once
                    $result = $conn->query("SELECT MAX(nomor_soal) as max_num FROM soal_structure");
                    $next_num = ($result->fetch_assoc()['max_num'] ?? 0) + 1;

                    // Re-bind with new number
                    $stmt->bind_param(
                        "sssssssi",
                        $q['question'],
                        $q['option_a'],
                        $q['option_b'],
                        $q['option_c'],
                        $q['option_d'],
                        $q['correct_answer'],
                        $part,
                        $next_num
                    );

                    if ($stmt->execute()) {
                        $imported++;
                        $next_num++;
                        continue;
                    }
                }

                $failed++;
                $errors[] = "Failed to insert: " . $stmt->error;
            }

        } catch (Exception $e) {
            // Auto-fix for missing AUTO_INCREMENT (Error 1364)
            if ($conn->errno == 1364 && strpos($conn->error, "id_soal") !== false) {
                $conn->query("ALTER TABLE soal_structure MODIFY COLUMN id_soal INT AUTO_INCREMENT PRIMARY KEY");
                // Retry
                if ($stmt->execute()) {
                    $imported++;
                    continue;
                }
            }

            $failed++;
            $errors[] = $e->getMessage();
        }
    }

    return ['imported' => $imported, 'failed' => $failed, 'errors' => $errors];
}

function importReadingQuestions($questions, $passage_id)
{
    global $conn;
    $imported = 0;
    $failed = 0;
    $errors = [];

    foreach ($questions as $q) {
        if (isset($q['error'])) {
            $failed++;
            $errors[] = $q['error'];
            continue;
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO soal_reading 
                (id_teks, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, difficulty) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'medium')
            ");

            $stmt->bind_param(
                "issssss",
                $passage_id,
                $q['question'],
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d'],
                $q['correct_answer']
            );

            if ($stmt->execute()) {
                $imported++;
            } else {
                $failed++;
                $errors[] = "Failed to insert: " . $stmt->error;
            }

        } catch (Exception $e) {
            // Auto-fix for missing AUTO_INCREMENT
            if ($conn->errno == 1364 && strpos($conn->error, "id_soal") !== false) {
                $conn->query("ALTER TABLE soal_reading MODIFY COLUMN id_soal INT AUTO_INCREMENT PRIMARY KEY");
                if ($stmt->execute()) {
                    $imported++;
                    continue;
                }
            }

            $failed++;
            $errors[] = $e->getMessage();
        }
    }

    return ['imported' => $imported, 'failed' => $failed, 'errors' => $errors];
}

function importListeningQuestions($questions, $audio_id)
{
    global $conn;
    $imported = 0;
    $failed = 0;
    $errors = [];

    foreach ($questions as $q) {
        if (isset($q['error'])) {
            $failed++;
            $errors[] = $q['error'];
            continue;
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO soal_listening 
                (id_audio, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, difficulty) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'medium')
            ");

            $stmt->bind_param(
                "issssss",
                $audio_id,
                $q['question'],
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d'],
                $q['correct_answer']
            );

            if ($stmt->execute()) {
                $imported++;
            } else {
                $failed++;
                $errors[] = "Failed to insert: " . $stmt->error;
            }

        } catch (Exception $e) {
            // Auto-fix for missing AUTO_INCREMENT
            if ($conn->errno == 1364 && strpos($conn->error, "id_soal") !== false) {
                $conn->query("ALTER TABLE soal_listening MODIFY COLUMN id_soal INT AUTO_INCREMENT PRIMARY KEY");
                if ($stmt->execute()) {
                    $imported++;
                    continue;
                }
            }

            $failed++;
            $errors[] = $e->getMessage();
        }
    }

    return ['imported' => $imported, 'failed' => $failed, 'errors' => $errors];
}

function detectStructurePart($question)
{
    // Part A: Sentence completion (has blanks or incomplete sentences)
    // Part B: Error identification (usually asks to find the error)

    $question_lower = strtolower($question);

    // Keywords that suggest Part B (error identification)
    $part_b_keywords = ['underlined', 'incorrect', 'error', 'wrong', 'identify'];

    foreach ($part_b_keywords as $keyword) {
        if (strpos($question_lower, $keyword) !== false) {
            return 'B';
        }
    }

    // Check for blanks/incomplete sentences (Part A indicators)
    if (strpos($question, '____') !== false || strpos($question, '...') !== false) {
        return 'A';
    }

    // Default to Part A
    return 'A';
}
?>