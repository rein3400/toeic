<?php

/**
 * Import generated TOEIC C2 packages into the existing TOEIC item-bank tables.
 *
 * The importer is intentionally deterministic: it fixes common generated-text
 * defects that can be repaired safely at import time, then rejects packages that
 * still contain placeholder content or invalid answers.
 */

function toeicC2ReadJsonStrict(string $path): array {
    if (!file_exists($path)) {
        throw new RuntimeException("Missing JSON file: $path");
    }

    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Unable to read JSON file: $path");
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON file: $path");
    }

    return $data;
}

function toeicC2MonthMap(): array {
    return [
        'January' => 31,
        'February' => 29,
        'March' => 31,
        'April' => 30,
        'May' => 31,
        'June' => 30,
        'July' => 31,
        'August' => 31,
        'September' => 30,
        'October' => 31,
        'November' => 30,
        'December' => 31,
    ];
}

function toeicC2NextMonthName(string $month): string {
    $months = array_keys(toeicC2MonthMap());
    $idx = array_search($month, $months, true);
    if ($idx === false) {
        return $month;
    }
    return $months[($idx + 1) % count($months)];
}

function toeicC2RepairCalendarDate(string $month, int $day, array &$stats, array &$logs, string $context): string {
    $monthMap = toeicC2MonthMap();
    if (!isset($monthMap[$month]) || $day <= $monthMap[$month]) {
        return $month . ' ' . $day;
    }

    $original = $month . ' ' . $day;
    while (isset($monthMap[$month]) && $day > $monthMap[$month]) {
        $day -= $monthMap[$month];
        $month = toeicC2NextMonthName($month);
    }

    $replacement = $month . ' ' . $day;
    $stats['quality_fixes']++;
    $logs[] = "Quality fix [$context]: normalized invalid date '$original' to '$replacement'.";
    return $replacement;
}

function toeicC2UpgradeText(string $text, array &$stats, array &$logs, string $context): string {
    $original = $text;

    $text = preg_replace_callback(
        '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+([0-9]{1,2})\b/',
        function (array $matches) use (&$stats, &$logs, $context): string {
            return toeicC2RepairCalendarDate($matches[1], (int)$matches[2], $stats, $logs, $context);
        },
        $text
    ) ?? $text;

    $replacements = [
        'has become more complicated because a ' => 'has become more complicated because of a ',
        'has become more complicated because an ' => 'has become more complicated because of an ',
        'has become more complicated because the ' => 'has become more complicated because of the ',
    ];
    foreach ($replacements as $needle => $replacement) {
        if (strpos($text, $needle) !== false) {
            $text = str_replace($needle, $replacement, $text);
            $stats['quality_fixes']++;
            $logs[] = "Quality fix [$context]: repaired causal phrasing '$needle'.";
        }
    }

    $text = preg_replace_callback(
        '/\bBecause (a|an) ([^,]+),/',
        function (array $matches) use (&$stats, &$logs, $context): string {
            $stats['quality_fixes']++;
            $logs[] = "Quality fix [$context]: changed 'Because {$matches[1]} ...' to 'Because of {$matches[1]} ...'.";
            return 'Because of ' . $matches[1] . ' ' . $matches[2] . ',';
        },
        $text
    ) ?? $text;

    $text = preg_replace('/[ \t]+\R/u', "\n", $text) ?? $text;
    $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

    if ($text !== $original) {
        $stats['quality_strings_touched']++;
    }

    return $text;
}

function toeicC2UpgradeValue($value, array &$stats, array &$logs, string $context) {
    if (is_string($value)) {
        return toeicC2UpgradeText($value, $stats, $logs, $context);
    }

    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $child) {
        $value[$key] = toeicC2UpgradeValue($child, $stats, $logs, $context . '.' . (string)$key);
    }

    return $value;
}

function toeicC2CollectStrings($value, array &$strings): void {
    if (is_string($value)) {
        $strings[] = $value;
        return;
    }
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $child) {
        toeicC2CollectStrings($child, $strings);
    }
}

function toeicC2HasInvalidCalendarDate(string $text): bool {
    if (!preg_match_all('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+([0-9]{1,2})\b/', $text, $matches, PREG_SET_ORDER)) {
        return false;
    }

    $monthMap = toeicC2MonthMap();
    foreach ($matches as $match) {
        if ((int)$match[2] > ($monthMap[$match[1]] ?? 31)) {
            return true;
        }
    }

    return false;
}

function toeicC2NormalizeChoiceMap($raw): array {
    if (is_array($raw) && array_keys($raw) !== range(0, count($raw) - 1)) {
        $normalized = [];
        foreach ($raw as $letter => $text) {
            $letter = strtoupper(trim((string)$letter));
            if (in_array($letter, ['A', 'B', 'C', 'D'], true)) {
                $normalized[$letter] = trim((string)$text);
            }
        }
        return $normalized;
    }

    $normalized = [];
    $letters = ['A', 'B', 'C', 'D'];
    foreach ((array)$raw as $idx => $entry) {
        if (is_array($entry)) {
            $entry = $entry['text'] ?? reset($entry) ?? '';
        }
        if (is_string($entry) && preg_match('/^\s*([A-D])[\.\):\-]\s*(.+)$/', $entry, $m)) {
            $normalized[strtoupper($m[1])] = trim($m[2]);
            continue;
        }
        if (isset($letters[$idx])) {
            $normalized[$letters[$idx]] = trim((string)$entry);
        }
    }

    return $normalized;
}

function toeicC2NormalizeAnswerComparable(string $value): string {
    $value = preg_replace('/^\s*[A-D]\s*[\.\):\-]\s*/i', '', trim($value)) ?? trim($value);
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function toeicC2NormalizeCorrectAnswer($raw, array $options): string {
    $value = trim((string)$raw);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\s*([A-D])(?:\s*[\.\):\-]|\s*$)/i', $value, $m)) {
        return strtoupper($m[1]);
    }

    $needle = toeicC2NormalizeAnswerComparable($value);
    foreach ($options as $letter => $text) {
        $letter = strtoupper((string)$letter);
        if (!in_array($letter, ['A', 'B', 'C', 'D'], true)) {
            continue;
        }
        $optionText = trim((string)$text);
        if ($optionText !== '' && $needle === toeicC2NormalizeAnswerComparable($optionText)) {
            return $letter;
        }
    }

    return strtoupper($value);
}

function toeicC2NormalizeQuestionItem(array $item): array {
    $item['options'] = toeicC2NormalizeChoiceMap($item['options'] ?? []);
    if (!isset($item['question_text']) && isset($item['question'])) {
        $item['question_text'] = $item['question'];
    }
    if (!isset($item['correct_answer']) && isset($item['correct_option'])) {
        $item['correct_answer'] = $item['correct_option'];
    }
    if (!isset($item['correct_answer']) && array_key_exists('correct_index', $item)) {
        $letters = ['A', 'B', 'C', 'D'];
        $index = (int)$item['correct_index'];
        if (isset($letters[$index])) {
            $item['correct_answer'] = $letters[$index];
        }
    }
    if (!isset($item['correct_answer']) && isset($item['answer'])) {
        $item['correct_answer'] = $item['answer'];
    }
    $item['correct_answer'] = toeicC2NormalizeCorrectAnswer($item['correct_answer'] ?? '', $item['options']);
    $item['explanation'] = trim((string)($item['explanation'] ?? ''));
    return $item;
}

function toeicC2ValidateQuestion(array $item, string $context, bool $allowThreeOptions, array &$errors): void {
    $item = toeicC2NormalizeQuestionItem($item);
    $letters = $allowThreeOptions ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
    foreach ($letters as $letter) {
        if (!isset($item['options'][$letter]) || trim((string)$item['options'][$letter]) === '') {
            $errors[] = "$context is missing option $letter.";
        }
    }
    if (!$allowThreeOptions && count(array_unique(array_map('trim', $item['options']))) < 4) {
        $errors[] = "$context has duplicate answer options.";
    }
    if ($allowThreeOptions && count(array_unique(array_map('trim', array_intersect_key($item['options'], array_flip($letters))))) < 3) {
        $errors[] = "$context has duplicate answer options.";
    }
    if (!in_array($item['correct_answer'], $letters, true)) {
        $errors[] = "$context has invalid correct answer '{$item['correct_answer']}'.";
    }
    if (strlen($item['explanation']) < 35) {
        $errors[] = "$context has an explanation that is too short for a C2-quality import.";
    }
}

function toeicC2ValidatePackage(array $package, int $packageNumber): array {
    $errors = [];
    $packageLabel = sprintf('package_%02d', $packageNumber);

    if (($package['manifest']['target_cefr'] ?? '') !== 'C2') {
        $errors[] = "$packageLabel manifest target_cefr is not C2.";
    }

    $strings = [];
    toeicC2CollectStrings($package, $strings);
    foreach ($strings as $text) {
        if (preg_match('/\b(audio-only|Choice [A-D]|No linked stimulus metadata|Jawaban benar di luar opsi)\b/i', $text)) {
            $errors[] = "$packageLabel contains placeholder/import-error text.";
            break;
        }
        if (toeicC2HasInvalidCalendarDate($text)) {
            $errors[] = "$packageLabel still contains an invalid calendar date after repair.";
            break;
        }
    }

    foreach (($package['part1']['items'] ?? []) as $idx => $item) {
        toeicC2ValidateQuestion($item, "$packageLabel part1 item " . ($idx + 1), false, $errors);
        if (empty($item['audio_file']) || empty($item['image_file'])) {
            $errors[] = "$packageLabel part1 item " . ($idx + 1) . " is missing media references.";
        }
    }

    foreach (($package['part2']['items'] ?? []) as $idx => $item) {
        toeicC2ValidateQuestion($item, "$packageLabel part2 item " . ($idx + 1), true, $errors);
        if (empty($item['audio_file'])) {
            $errors[] = "$packageLabel part2 item " . ($idx + 1) . " is missing audio reference.";
        }
    }

    foreach (['part3' => false, 'part4' => false] as $partKey => $allowThree) {
        foreach (($package[$partKey]['sets'] ?? []) as $setIdx => $set) {
            if (empty($set['audio_file'])) {
                $errors[] = "$packageLabel $partKey set " . ($setIdx + 1) . " is missing audio reference.";
            }
            if (count($set['questions'] ?? []) !== 3) {
                $errors[] = "$packageLabel $partKey set " . ($setIdx + 1) . " must contain exactly 3 questions.";
            }
            foreach (($set['questions'] ?? []) as $idx => $item) {
                toeicC2ValidateQuestion($item, "$packageLabel $partKey set " . ($setIdx + 1) . ' question ' . ($idx + 1), $allowThree, $errors);
            }
        }
    }

    foreach (($package['part5']['items'] ?? []) as $idx => $item) {
        toeicC2ValidateQuestion($item, "$packageLabel part5 item " . ($idx + 1), false, $errors);
    }

    foreach (($package['part6']['sets'] ?? []) as $setIdx => $set) {
        if (count($set['questions'] ?? []) !== 4) {
            $errors[] = "$packageLabel part6 set " . ($setIdx + 1) . " must contain exactly 4 questions.";
        }
        foreach (($set['questions'] ?? []) as $idx => $item) {
            toeicC2ValidateQuestion($item, "$packageLabel part6 set " . ($setIdx + 1) . ' question ' . ($idx + 1), false, $errors);
        }
    }

    foreach (['single_sets', 'double_sets', 'triple_sets'] as $setKey) {
        foreach (($package['part7'][$setKey] ?? []) as $setIdx => $set) {
            foreach (($set['questions'] ?? []) as $idx => $item) {
                toeicC2ValidateQuestion($item, "$packageLabel part7 $setKey set " . ($setIdx + 1) . ' question ' . ($idx + 1), false, $errors);
            }
        }
    }

    return $errors;
}

function toeicC2LoadPackage(string $contentRoot, int $packageNumber, array &$stats, array &$logs): array {
    $packageName = sprintf('package_%02d', $packageNumber);
    $dir = rtrim($contentRoot, '/\\') . DIRECTORY_SEPARATOR . $packageName;
    if (!is_dir($dir)) {
        throw new RuntimeException("Package directory not found: $dir");
    }

    $package = [
        'manifest' => toeicC2ReadJsonStrict($dir . '/manifest.json'),
        'part1' => toeicC2ReadJsonStrict($dir . '/part1.json'),
        'part2' => toeicC2ReadJsonStrict($dir . '/part2.json'),
        'part3' => toeicC2ReadJsonStrict($dir . '/part3.json'),
        'part4' => toeicC2ReadJsonStrict($dir . '/part4.json'),
        'part5' => toeicC2ReadJsonStrict($dir . '/part5.json'),
        'part6' => toeicC2ReadJsonStrict($dir . '/part6.json'),
        'part7' => toeicC2ReadJsonStrict($dir . '/part7.json'),
        'transcripts' => toeicC2ReadJsonStrict($dir . '/media/transcripts.json'),
    ];

    $package = toeicC2UpgradeValue($package, $stats, $logs, $packageName);
    $errors = toeicC2ValidatePackage($package, $packageNumber);
    if (!empty($errors)) {
        $stats['quality_errors'] += count($errors);
        throw new RuntimeException("Quality gate failed for $packageName:\n- " . implode("\n- ", array_slice($errors, 0, 20)));
    }

    return $package;
}

function toeicC2SafeBasename(string $filename): string {
    return basename(str_replace('\\', '/', $filename));
}

function toeicC2MediaUrl(string $baseUrl, string $kind, int $packageNumber, string $filename): string {
    $baseUrl = rtrim($baseUrl, '/');
    $packageName = sprintf('package_%02d', $packageNumber);
    $prefix = $kind === 'photo' ? 'toeic/photos' : 'toeic/audio';
    return $baseUrl . '/' . $prefix . '/' . $packageName . '/' . rawurlencode(toeicC2SafeBasename($filename));
}

function toeicC2TranscriptText(array $transcripts, string $audioFile, $fallback): string {
    $files = $transcripts['files'] ?? [];
    $entry = is_array($files) ? ($files[$audioFile] ?? []) : [];
    $spoken = is_array($entry) ? ($entry['spoken_transcript'] ?? []) : [];
    if (isset($spoken['statements']) && is_array($spoken['statements'])) {
        return trim(implode(' ', array_map('strval', $spoken['statements'])));
    }
    if (isset($spoken['prompt'])) {
        return trim(implode(' ', array_filter([
            (string)($spoken['prompt'] ?? ''),
            (string)($spoken['response_1'] ?? ''),
            (string)($spoken['response_2'] ?? ''),
            (string)($spoken['response_3'] ?? ''),
        ])));
    }
    if (isset($spoken['segments']) && is_array($spoken['segments'])) {
        $parts = [];
        foreach ($spoken['segments'] as $segment) {
            $parts[] = trim((string)($segment['text'] ?? ''));
        }
        return trim(implode(' ', array_filter($parts)));
    }
    if (isset($spoken['talk'])) {
        return trim((string)$spoken['talk']);
    }
    if (is_string($fallback)) {
        return trim($fallback);
    }
    return '';
}

function toeicC2FindPhotoId(mysqli $conn, string $fileUrl): ?int {
    $stmt = $conn->prepare("SELECT id_photo FROM toeic_photos WHERE file_path = ? LIMIT 1");
    $stmt->bind_param("s", $fileUrl);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id_photo'] : null;
}

function toeicC2FindAudioId(mysqli $conn, string $fileUrl): ?int {
    $stmt = $conn->prepare("SELECT id_audio FROM toeic_audio WHERE file_path = ? LIMIT 1");
    $stmt->bind_param("s", $fileUrl);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id_audio'] : null;
}

function toeicC2NextDryId(array &$stats): int {
    $stats['_dry_id']--;
    return $stats['_dry_id'];
}

function toeicC2GetOrCreatePhoto(mysqli $conn, string $fileUrl, string $description, array &$stats, bool $dryRun): int {
    $existing = toeicC2FindPhotoId($conn, $fileUrl);
    if ($existing !== null) {
        $stats['photos_skipped']++;
        return $existing;
    }
    if ($dryRun) {
        $stats['photos_inserted']++;
        return toeicC2NextDryId($stats);
    }

    $stmt = $conn->prepare("INSERT INTO toeic_photos (file_path, description) VALUES (?, ?)");
    $stmt->bind_param("ss", $fileUrl, $description);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    $stats['photos_inserted']++;
    return $id;
}

function toeicC2GetOrCreateAudio(mysqli $conn, string $title, string $part, string $fileUrl, string $transcript, string $context, ?int $photoId, array &$stats, bool $dryRun): int {
    $existing = toeicC2FindAudioId($conn, $fileUrl);
    if ($existing !== null) {
        $stats['audio_skipped']++;
        return $existing;
    }
    if ($dryRun) {
        $stats['audio_inserted']++;
        return toeicC2NextDryId($stats);
    }

    if ($photoId === null || $photoId < 1) {
        $stmt = $conn->prepare("INSERT INTO toeic_audio (judul, part, file_path, transcript, context, id_photo) VALUES (?, ?, ?, ?, ?, NULL)");
        $stmt->bind_param("sssss", $title, $part, $fileUrl, $transcript, $context);
    } else {
        $stmt = $conn->prepare("INSERT INTO toeic_audio (judul, part, file_path, transcript, context, id_photo) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $title, $part, $fileUrl, $transcript, $context, $photoId);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    $stats['audio_inserted']++;
    return $id;
}

function toeicC2GetOrCreateText(mysqli $conn, string $title, string $part, string $textType, string $text1, ?string $text2, ?string $text3, array &$stats, bool $dryRun): int {
    $stmt = $conn->prepare("SELECT id_teks FROM toeic_teks WHERE judul = ? AND part = ? AND COALESCE(text_type, '') = ? LIMIT 1");
    $stmt->bind_param("sss", $title, $part, $textType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $stats['texts_skipped']++;
        return (int)$row['id_teks'];
    }
    if ($dryRun) {
        $stats['texts_inserted']++;
        return toeicC2NextDryId($stats);
    }

    $stmt = $conn->prepare("INSERT INTO toeic_teks (judul, part, text_type, isi_teks, isi_teks_2, isi_teks_3) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $title, $part, $textType, $text1, $text2, $text3);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    $stats['texts_inserted']++;
    return $id;
}

function toeicC2ListeningQuestionExists(mysqli $conn, string $part, int $number): bool {
    $stmt = $conn->prepare("SELECT id_soal FROM toeic_soal_listening WHERE part = ? AND nomor_soal = ? LIMIT 1");
    $stmt->bind_param("si", $part, $number);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function toeicC2ReadingQuestionExists(mysqli $conn, string $part, int $number): bool {
    $stmt = $conn->prepare("SELECT id_soal FROM toeic_soal_reading WHERE part = ? AND nomor_soal = ? LIMIT 1");
    $stmt->bind_param("si", $part, $number);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function toeicC2InsertListeningQuestion(mysqli $conn, string $part, int $number, string $question, ?string $a, ?string $b, ?string $c, ?string $d, string $correct, string $explanation, int $audioId, string $questionType, array &$stats, bool $dryRun): void {
    if (toeicC2ListeningQuestionExists($conn, $part, $number)) {
        $stats['listening_skipped']++;
        return;
    }
    if ($dryRun) {
        $stats['listening_inserted']++;
        return;
    }

    $stmt = $conn->prepare("INSERT INTO toeic_soal_listening (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_audio, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisssssssis", $part, $number, $question, $a, $b, $c, $d, $correct, $explanation, $audioId, $questionType);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    $stats['listening_inserted']++;
}

function toeicC2InsertReadingQuestion(mysqli $conn, string $part, int $number, string $question, ?string $a, ?string $b, ?string $c, ?string $d, string $correct, string $explanation, ?int $textId, string $questionType, array &$stats, bool $dryRun): void {
    if (toeicC2ReadingQuestionExists($conn, $part, $number)) {
        $stats['reading_skipped']++;
        return;
    }
    if ($dryRun) {
        $stats['reading_inserted']++;
        return;
    }

    if ($textId === null || $textId < 1) {
        $stmt = $conn->prepare("INSERT INTO toeic_soal_reading (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_teks, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)");
        $stmt->bind_param("sissssssss", $part, $number, $question, $a, $b, $c, $d, $correct, $explanation, $questionType);
    } else {
        $stmt = $conn->prepare("INSERT INTO toeic_soal_reading (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, id_teks, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisssssssis", $part, $number, $question, $a, $b, $c, $d, $correct, $explanation, $textId, $questionType);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    $stats['reading_inserted']++;
}

function toeicC2VerifyMediaUrl(string $url, array &$stats, array &$logs): void {
    $headers = @get_headers($url, true);
    $statusLine = is_array($headers) ? (string)($headers[0] ?? '') : '';
    if (strpos($statusLine, '200') !== false) {
        $stats['media_verified']++;
        return;
    }
    $stats['media_verify_failed']++;
    $logs[] = "Media HEAD verification failed: $url ($statusLine)";
}

function toeicC2ImportPackages(mysqli $conn, string $contentRoot, array $options = []): array {
    $from = max(1, (int)($options['from'] ?? 2));
    $to = max($from, (int)($options['to'] ?? 10));
    $r2BaseUrl = rtrim((string)($options['r2_base_url'] ?? ''), '/');
    $dryRun = !empty($options['dry_run']);
    $verifyMedia = !empty($options['verify_media']);

    if ($r2BaseUrl === '') {
        throw new RuntimeException('R2 public base URL is required.');
    }

    $stats = [
        '_dry_id' => 0,
        'packages_processed' => 0,
        'quality_fixes' => 0,
        'quality_strings_touched' => 0,
        'quality_errors' => 0,
        'media_verified' => 0,
        'media_verify_failed' => 0,
        'photos_inserted' => 0,
        'photos_skipped' => 0,
        'audio_inserted' => 0,
        'audio_skipped' => 0,
        'texts_inserted' => 0,
        'texts_skipped' => 0,
        'listening_inserted' => 0,
        'listening_skipped' => 0,
        'reading_inserted' => 0,
        'reading_skipped' => 0,
    ];
    $logs = [];

    if (!$dryRun) {
        $conn->begin_transaction();
    }

    try {
        for ($packageNumber = $from; $packageNumber <= $to; $packageNumber++) {
            $package = toeicC2LoadPackage($contentRoot, $packageNumber, $stats, $logs);
            $packageLabel = sprintf('C2 Package %02d', $packageNumber);
            $listeningNumber = (($packageNumber - 1) * 100) + 1;
            $readingNumber = (($packageNumber - 1) * 100) + 101;

            foreach (($package['part1']['items'] ?? []) as $idx => $item) {
                $item = toeicC2NormalizeQuestionItem($item);
                $imageFile = toeicC2SafeBasename((string)($item['image_file'] ?? ''));
                $audioFile = toeicC2SafeBasename((string)($item['audio_file'] ?? ''));
                $photoUrl = toeicC2MediaUrl($r2BaseUrl, 'photo', $packageNumber, $imageFile);
                $audioUrl = toeicC2MediaUrl($r2BaseUrl, 'audio', $packageNumber, $audioFile);
                if ($verifyMedia) {
                    toeicC2VerifyMediaUrl($photoUrl, $stats, $logs);
                    toeicC2VerifyMediaUrl($audioUrl, $stats, $logs);
                }
                $photoId = toeicC2GetOrCreatePhoto($conn, $photoUrl, (string)($item['photo_description'] ?? ''), $stats, $dryRun);
                $audioId = toeicC2GetOrCreateAudio(
                    $conn,
                    $packageLabel . ' - ' . (string)($item['title'] ?? ('Part 1 Item ' . ($idx + 1))),
                    '1',
                    $audioUrl,
                    toeicC2TranscriptText($package['transcripts'], $audioFile, $item['audio_script'] ?? ''),
                    (string)($item['photo_description'] ?? ''),
                    $photoId,
                    $stats,
                    $dryRun
                );
                toeicC2InsertListeningQuestion(
                    $conn,
                    '1',
                    $listeningNumber++,
                    'Choose the statement that best describes the photo.',
                    $item['options']['A'] ?? null,
                    $item['options']['B'] ?? null,
                    $item['options']['C'] ?? null,
                    $item['options']['D'] ?? null,
                    $item['correct_answer'],
                    $item['explanation'],
                    $audioId,
                    'part1_photograph_c2',
                    $stats,
                    $dryRun
                );
            }

            foreach (($package['part2']['items'] ?? []) as $idx => $item) {
                $item = toeicC2NormalizeQuestionItem($item);
                $audioFile = toeicC2SafeBasename((string)($item['audio_file'] ?? ''));
                $audioUrl = toeicC2MediaUrl($r2BaseUrl, 'audio', $packageNumber, $audioFile);
                if ($verifyMedia) {
                    toeicC2VerifyMediaUrl($audioUrl, $stats, $logs);
                }
                $audioId = toeicC2GetOrCreateAudio(
                    $conn,
                    $packageLabel . ' - ' . (string)($item['title'] ?? ('Part 2 Item ' . ($idx + 1))),
                    '2',
                    $audioUrl,
                    toeicC2TranscriptText($package['transcripts'], $audioFile, $item['audio_script'] ?? ''),
                    (string)($item['prompt_text'] ?? ''),
                    null,
                    $stats,
                    $dryRun
                );
                toeicC2InsertListeningQuestion(
                    $conn,
                    '2',
                    $listeningNumber++,
                    (string)($item['prompt_text'] ?? 'Listen to the question and choose the best response.'),
                    $item['options']['A'] ?? null,
                    $item['options']['B'] ?? null,
                    $item['options']['C'] ?? null,
                    null,
                    $item['correct_answer'],
                    $item['explanation'],
                    $audioId,
                    'part2_question_response_c2',
                    $stats,
                    $dryRun
                );
            }

            foreach (['3' => $package['part3']['sets'] ?? [], '4' => $package['part4']['sets'] ?? []] as $part => $sets) {
                foreach ($sets as $idx => $set) {
                    $audioFile = toeicC2SafeBasename((string)($set['audio_file'] ?? ''));
                    $audioUrl = toeicC2MediaUrl($r2BaseUrl, 'audio', $packageNumber, $audioFile);
                    if ($verifyMedia) {
                        toeicC2VerifyMediaUrl($audioUrl, $stats, $logs);
                    }
                    $audioId = toeicC2GetOrCreateAudio(
                        $conn,
                        $packageLabel . ' - ' . (string)($set['title'] ?? ('Part ' . $part . ' Set ' . ($idx + 1))),
                        $part,
                        $audioUrl,
                        toeicC2TranscriptText($package['transcripts'], $audioFile, $set['audio_script'] ?? ''),
                        (string)($set['context'] ?? ''),
                        null,
                        $stats,
                        $dryRun
                    );
                    $questionType = $part === '3' ? 'part3_conversation_c2' : 'part4_talk_c2';
                    foreach (($set['questions'] ?? []) as $questionItem) {
                        $questionItem = toeicC2NormalizeQuestionItem($questionItem);
                        toeicC2InsertListeningQuestion(
                            $conn,
                            $part,
                            $listeningNumber++,
                            (string)($questionItem['question_text'] ?? ''),
                            $questionItem['options']['A'] ?? null,
                            $questionItem['options']['B'] ?? null,
                            $questionItem['options']['C'] ?? null,
                            $questionItem['options']['D'] ?? null,
                            $questionItem['correct_answer'],
                            $questionItem['explanation'],
                            $audioId,
                            $questionType,
                            $stats,
                            $dryRun
                        );
                    }
                }
            }

            foreach (($package['part5']['items'] ?? []) as $item) {
                $item = toeicC2NormalizeQuestionItem($item);
                toeicC2InsertReadingQuestion(
                    $conn,
                    '5',
                    $readingNumber++,
                    (string)($item['sentence'] ?? ''),
                    $item['options']['A'] ?? null,
                    $item['options']['B'] ?? null,
                    $item['options']['C'] ?? null,
                    $item['options']['D'] ?? null,
                    $item['correct_answer'],
                    $item['explanation'],
                    null,
                    'part5_incomplete_sentence_c2',
                    $stats,
                    $dryRun
                );
            }

            foreach (($package['part6']['sets'] ?? []) as $idx => $set) {
                $textId = toeicC2GetOrCreateText(
                    $conn,
                    $packageLabel . ' - ' . (string)($set['set_id'] ?? ('P6-' . ($idx + 1))) . ' - ' . (string)($set['title'] ?? 'Part 6 Text'),
                    '6',
                    (string)($set['text_type'] ?? 'single'),
                    (string)($set['passage_with_blanks'] ?? ''),
                    null,
                    null,
                    $stats,
                    $dryRun
                );
                foreach (($set['questions'] ?? []) as $questionItem) {
                    $questionItem = toeicC2NormalizeQuestionItem($questionItem);
                    toeicC2InsertReadingQuestion(
                        $conn,
                        '6',
                        $readingNumber++,
                        (string)($questionItem['question_text'] ?? ('Blank ' . ($questionItem['blank_number'] ?? '?'))),
                        $questionItem['options']['A'] ?? null,
                        $questionItem['options']['B'] ?? null,
                        $questionItem['options']['C'] ?? null,
                        $questionItem['options']['D'] ?? null,
                        $questionItem['correct_answer'],
                        $questionItem['explanation'],
                        $textId,
                        'part6_text_completion_c2',
                        $stats,
                        $dryRun
                    );
                }
            }

            foreach (['single' => $package['part7']['single_sets'] ?? [], 'double' => $package['part7']['double_sets'] ?? [], 'triple' => $package['part7']['triple_sets'] ?? []] as $textType => $sets) {
                foreach ($sets as $idx => $set) {
                    $textId = toeicC2GetOrCreateText(
                        $conn,
                        $packageLabel . ' - ' . (string)($set['set_id'] ?? ('P7-' . ($idx + 1))) . ' - ' . (string)($set['title'] ?? 'Part 7 Text'),
                        '7',
                        $textType,
                        (string)($set['passage_1'] ?? ''),
                        isset($set['passage_2']) ? (string)$set['passage_2'] : null,
                        isset($set['passage_3']) ? (string)$set['passage_3'] : null,
                        $stats,
                        $dryRun
                    );
                    foreach (($set['questions'] ?? []) as $questionItem) {
                        $questionItem = toeicC2NormalizeQuestionItem($questionItem);
                        toeicC2InsertReadingQuestion(
                            $conn,
                            '7',
                            $readingNumber++,
                            (string)($questionItem['question_text'] ?? ''),
                            $questionItem['options']['A'] ?? null,
                            $questionItem['options']['B'] ?? null,
                            $questionItem['options']['C'] ?? null,
                            $questionItem['options']['D'] ?? null,
                            $questionItem['correct_answer'],
                            $questionItem['explanation'],
                            $textId,
                            'part7_' . $textType . '_passage_c2',
                            $stats,
                            $dryRun
                        );
                    }
                }
            }

            $stats['packages_processed']++;
            $logs[] = "$packageLabel passed quality gate and import mapping.";
        }

        if (!$dryRun) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if (!$dryRun) {
            $conn->rollback();
        }
        throw $e;
    }

    unset($stats['_dry_id']);
    return [
        'stats' => $stats,
        'logs' => $logs,
    ];
}
