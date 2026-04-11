<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_asset_storage.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function requireHttpUrl(string $value, string $field): string
{
    $value = trim($value);
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
        throw new InvalidArgumentException($field . ' must be a valid http/https URL');
    }
    return $value;
}

function requireNonEmpty(string $value, string $field): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException($field . ' is required');
    }
    return $value;
}

function questionTableForSection(string $section): string
{
    return match ($section) {
        'listening' => 'toeic_soal_listening',
        'reading' => 'toeic_soal_reading',
        default => throw new InvalidArgumentException('Invalid question section'),
    };
}

function validateQuestionPayload(mysqli $conn, array $input): array
{
    $section = strtolower(trim((string)($input['section'] ?? '')));
    $part = trim((string)($input['part'] ?? ''));
    $nomorSoal = (int)($input['nomor_soal'] ?? 0);
    $question = requireNonEmpty((string)($input['pertanyaan'] ?? ''), 'Question');
    $correct = strtoupper(trim((string)($input['jawaban_benar'] ?? '')));
    $explanation = requireNonEmpty((string)($input['explanation'] ?? ''), 'Explanation');
    $questionType = trim((string)($input['question_type'] ?? ''));

    if (!in_array($section, ['listening', 'reading'], true)) {
        throw new InvalidArgumentException('Section must be listening or reading');
    }

    if (!preg_match('/^[1-7]$/', $part)) {
        throw new InvalidArgumentException('Part must be between 1 and 7');
    }

    if ($section === 'listening' && !in_array($part, ['1', '2', '3', '4'], true)) {
        throw new InvalidArgumentException('Listening questions must belong to parts 1-4');
    }

    if ($section === 'reading' && !in_array($part, ['5', '6', '7'], true)) {
        throw new InvalidArgumentException('Reading questions must belong to parts 5-7');
    }

    if ($nomorSoal <= 0) {
        throw new InvalidArgumentException('Question number must be positive');
    }

    $validLetters = $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
    if (!in_array($correct, $validLetters, true)) {
        throw new InvalidArgumentException('Correct answer does not match the valid option set for this part');
    }

    $options = [
        'A' => requireNonEmpty((string)($input['opsi_a'] ?? ''), 'Option A'),
        'B' => requireNonEmpty((string)($input['opsi_b'] ?? ''), 'Option B'),
        'C' => requireNonEmpty((string)($input['opsi_c'] ?? ''), 'Option C'),
        'D' => $part === '2' ? null : requireNonEmpty((string)($input['opsi_d'] ?? ''), 'Option D'),
    ];

    $defaultTypes = [
        '1' => 'part1_photograph',
        '2' => 'part2_question_response',
        '3' => 'part3_conversation',
        '4' => 'part4_talk',
        '5' => 'part5_incomplete_sentence',
        '6' => 'part6_text_completion',
        '7' => 'part7_single_passage',
    ];
    if ($questionType === '') {
        $questionType = $defaultTypes[$part];
    }

    $idAudio = null;
    $idTeks = null;

    if ($section === 'listening') {
        $idAudio = (int)($input['id_audio'] ?? 0);
        if ($idAudio <= 0) {
            throw new InvalidArgumentException('Linked audio is required for listening questions');
        }
        $stmt = $conn->prepare("SELECT id_audio FROM toeic_audio WHERE id_audio = ?");
        $stmt->bind_param("i", $idAudio);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            throw new InvalidArgumentException('Linked audio not found');
        }
        $stmt->close();
    }

    if ($section === 'reading' && in_array($part, ['6', '7'], true)) {
        $idTeks = (int)($input['id_teks'] ?? 0);
        if ($idTeks <= 0) {
            throw new InvalidArgumentException('Linked text is required for reading parts 6 and 7');
        }
        $stmt = $conn->prepare("SELECT id_teks FROM toeic_teks WHERE id_teks = ?");
        $stmt->bind_param("i", $idTeks);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            throw new InvalidArgumentException('Linked text not found');
        }
        $stmt->close();
    }

    return [
        'section' => $section,
        'part' => $part,
        'nomor_soal' => $nomorSoal,
        'pertanyaan' => $question,
        'opsi_a' => $options['A'],
        'opsi_b' => $options['B'],
        'opsi_c' => $options['C'],
        'opsi_d' => $options['D'],
        'jawaban_benar' => $correct,
        'explanation' => $explanation,
        'question_type' => $questionType,
        'id_audio' => $idAudio,
        'id_teks' => $idTeks,
    ];
}

function checkQuestionNumberConflict(mysqli $conn, string $table, string $part, int $nomorSoal, int $excludeId = 0): void
{
    $sql = "SELECT id_soal FROM {$table} WHERE part = ? AND nomor_soal = ?";
    if ($excludeId > 0) {
        $sql .= " AND id_soal != ?";
    }
    $stmt = $conn->prepare($sql);
    if ($excludeId > 0) {
        $stmt->bind_param("sii", $part, $nomorSoal, $excludeId);
    } else {
        $stmt->bind_param("si", $part, $nomorSoal);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) {
        throw new InvalidArgumentException('Question number already exists for this part');
    }
}

function audioUsageCount(mysqli $conn, int $idAudio): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM toeic_soal_listening WHERE id_audio = ?");
    $stmt->bind_param("i", $idAudio);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count;
}

function imageUsageCount(mysqli $conn, int $idPhoto): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM toeic_audio WHERE id_photo = ?");
    $stmt->bind_param("i", $idPhoto);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count;
}

switch ($action) {
    case 'question_save':
        try {
            $questionId = (int)($_POST['id_soal'] ?? 0);
            $payload = validateQuestionPayload($conn, $_POST);
            $table = questionTableForSection($payload['section']);
            checkQuestionNumberConflict($conn, $table, $payload['part'], $payload['nomor_soal'], $questionId);

            if ($table === 'toeic_soal_listening') {
                if ($questionId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE toeic_soal_listening
                        SET part = ?, nomor_soal = ?, pertanyaan = ?, opsi_a = ?, opsi_b = ?, opsi_c = ?, opsi_d = ?, jawaban_benar = ?, explanation = ?, id_audio = ?, question_type = ?
                        WHERE id_soal = ?
                    ");
                    $stmt->bind_param(
                        "sisssssssisi",
                        $payload['part'],
                        $payload['nomor_soal'],
                        $payload['pertanyaan'],
                        $payload['opsi_a'],
                        $payload['opsi_b'],
                        $payload['opsi_c'],
                        $payload['opsi_d'],
                        $payload['jawaban_benar'],
                        $payload['explanation'],
                        $payload['id_audio'],
                        $payload['question_type'],
                        $questionId
                    );
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO toeic_soal_listening
                        (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_audio, question_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "sisssssssis",
                        $payload['part'],
                        $payload['nomor_soal'],
                        $payload['pertanyaan'],
                        $payload['opsi_a'],
                        $payload['opsi_b'],
                        $payload['opsi_c'],
                        $payload['opsi_d'],
                        $payload['jawaban_benar'],
                        $payload['explanation'],
                        $payload['id_audio'],
                        $payload['question_type']
                    );
                }
            } else {
                if ($questionId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE toeic_soal_reading
                        SET part = ?, nomor_soal = ?, pertanyaan = ?, opsi_a = ?, opsi_b = ?, opsi_c = ?, opsi_d = ?, jawaban_benar = ?, explanation = ?, id_teks = ?, question_type = ?
                        WHERE id_soal = ?
                    ");
                    $stmt->bind_param(
                        "sisssssssisi",
                        $payload['part'],
                        $payload['nomor_soal'],
                        $payload['pertanyaan'],
                        $payload['opsi_a'],
                        $payload['opsi_b'],
                        $payload['opsi_c'],
                        $payload['opsi_d'],
                        $payload['jawaban_benar'],
                        $payload['explanation'],
                        $payload['id_teks'],
                        $payload['question_type'],
                        $questionId
                    );
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO toeic_soal_reading
                        (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_teks, question_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "sisssssssis",
                        $payload['part'],
                        $payload['nomor_soal'],
                        $payload['pertanyaan'],
                        $payload['opsi_a'],
                        $payload['opsi_b'],
                        $payload['opsi_c'],
                        $payload['opsi_d'],
                        $payload['jawaban_benar'],
                        $payload['explanation'],
                        $payload['id_teks'],
                        $payload['question_type']
                    );
                }
            }

            $stmt->execute();
            $savedId = $questionId > 0 ? $questionId : (int)$conn->insert_id;
            $stmt->close();

            jsonResponse(['success' => true, 'id' => $savedId]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        break;

    case 'question_delete':
        try {
            $id = (int)($_POST['id_soal'] ?? 0);
            $section = strtolower(trim((string)($_POST['section'] ?? '')));
            if ($id <= 0) {
                throw new InvalidArgumentException('Question id is required');
            }
            $table = questionTableForSection($section);
            $stmt = $conn->prepare("DELETE FROM {$table} WHERE id_soal = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        break;

    case 'audio_save':
        try {
            $id = (int)($_POST['id_audio'] ?? 0);
            $judul = requireNonEmpty((string)($_POST['judul'] ?? ''), 'Audio title');
            $part = trim((string)($_POST['part'] ?? ''));
            if (!in_array($part, ['1', '2', '3', '4'], true)) {
                throw new InvalidArgumentException('Audio part must be between 1 and 4');
            }
            $filePath = requireHttpUrl((string)($_POST['file_path'] ?? ''), 'Audio URL');
            $transcript = requireNonEmpty((string)($_POST['transcript'] ?? ''), 'Transcript');
            $context = trim((string)($_POST['context'] ?? ''));
            $idPhoto = (int)($_POST['id_photo'] ?? 0);
            if ($idPhoto > 0) {
                $stmt = $conn->prepare("SELECT id_photo FROM toeic_photos WHERE id_photo = ?");
                $stmt->bind_param("i", $idPhoto);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) {
                    $stmt->close();
                    throw new InvalidArgumentException('Linked image not found');
                }
                $stmt->close();
            } else {
                $idPhoto = null;
            }

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE toeic_audio
                    SET judul = ?, part = ?, file_path = ?, transcript = ?, context = ?, id_photo = ?
                    WHERE id_audio = ?
                ");
                $stmt->bind_param("sssssii", $judul, $part, $filePath, $transcript, $context, $idPhoto, $id);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO toeic_audio (judul, part, file_path, transcript, context, id_photo)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssssi", $judul, $part, $filePath, $transcript, $context, $idPhoto);
            }

            $stmt->execute();
            $savedId = $id > 0 ? $id : (int)$conn->insert_id;
            $stmt->close();
            jsonResponse(['success' => true, 'id' => $savedId]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        break;

    case 'audio_delete':
        try {
            $id = (int)($_POST['id_audio'] ?? 0);
            $force = !empty($_POST['force_unlink']);
            if ($id <= 0) {
                throw new InvalidArgumentException('Audio id is required');
            }
            $usageCount = audioUsageCount($conn, $id);
            if ($usageCount > 0 && !$force) {
                throw new InvalidArgumentException("Audio is still linked to {$usageCount} question(s)");
            }
            if ($usageCount > 0 && $force) {
                $stmt = $conn->prepare("UPDATE toeic_soal_listening SET id_audio = NULL WHERE id_audio = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("DELETE FROM toeic_audio WHERE id_audio = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        break;

    case 'image_save':
        try {
            $id = (int)($_POST['id_photo'] ?? 0);
            $filePath = requireHttpUrl((string)($_POST['file_path'] ?? ''), 'Image URL');
            $description = requireNonEmpty((string)($_POST['description'] ?? ''), 'Image description');

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE toeic_photos SET file_path = ?, description = ? WHERE id_photo = ?");
                $stmt->bind_param("ssi", $filePath, $description, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO toeic_photos (file_path, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $filePath, $description);
            }
            $stmt->execute();
            $savedId = $id > 0 ? $id : (int)$conn->insert_id;
            $stmt->close();
            jsonResponse(['success' => true, 'id' => $savedId]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        break;

    case 'image_delete':
        try {
            $id = (int)($_POST['id_photo'] ?? 0);
            $force = !empty($_POST['force_unlink']);
            if ($id <= 0) {
                throw new InvalidArgumentException('Image id is required');
            }
            $usageCount = imageUsageCount($conn, $id);
            if ($usageCount > 0 && !$force) {
                throw new InvalidArgumentException("Image is still linked to {$usageCount} audio row(s)");
            }
            if ($usageCount > 0 && $force) {
                $stmt = $conn->prepare("UPDATE toeic_audio SET id_photo = NULL WHERE id_photo = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("DELETE FROM toeic_photos WHERE id_photo = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        break;

    case 'text_save':
        try {
            $id = (int)($_POST['id_teks'] ?? 0);
            $judul = requireNonEmpty((string)($_POST['judul'] ?? ''), 'Text title');
            $part = trim((string)($_POST['part'] ?? ''));
            if (!in_array($part, ['6', '7'], true)) {
                throw new InvalidArgumentException('Text part must be 6 or 7');
            }
            $textType = trim((string)($_POST['text_type'] ?? ''));
            $isiTeks = requireNonEmpty((string)($_POST['isi_teks'] ?? ''), 'Primary text');
            $isiTeks2 = trim((string)($_POST['isi_teks_2'] ?? ''));
            $isiTeks3 = trim((string)($_POST['isi_teks_3'] ?? ''));

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE toeic_teks
                    SET judul = ?, part = ?, text_type = ?, isi_teks = ?, isi_teks_2 = ?, isi_teks_3 = ?
                    WHERE id_teks = ?
                ");
                $stmt->bind_param("ssssssi", $judul, $part, $textType, $isiTeks, $isiTeks2, $isiTeks3, $id);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO toeic_teks (judul, part, text_type, isi_teks, isi_teks_2, isi_teks_3)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ssssss", $judul, $part, $textType, $isiTeks, $isiTeks2, $isiTeks3);
            }
            $stmt->execute();
            $savedId = $id > 0 ? $id : (int)$conn->insert_id;
            $stmt->close();
            jsonResponse(['success' => true, 'id' => $savedId]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
