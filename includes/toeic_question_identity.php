<?php
declare(strict_types=1);

/** Normalize visible question content without erasing names, numbers or punctuation. */
function toeicNormalizeQuestionContent(string $value): string {
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $decoded)), 'UTF-8');
}

/** Return the valid answer letters for a TOEIC L/R part. */
function toeicQuestionLetters(string $part): array {
    return $part === '2' ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
}

/** Return normalized option text in letter order, including intentional audio-only blanks. */
function toeicQuestionOptionTexts(array $row, string $part): array {
    $options = [];
    foreach (toeicQuestionLetters($part) as $letter) {
        $options[$letter] = toeicNormalizeQuestionContent((string)($row['opsi_' . strtolower($letter)] ?? ''));
    }
    return $options;
}

/** Validate the stored key without guessing truncated answer text or modifying source data. */
function toeicQuestionHasValidAnswer(array $row, string $part): bool {
    $answer = strtoupper(trim((string)($row['jawaban_benar'] ?? '')));
    if (!in_array($answer, toeicQuestionLetters($part), true)) {
        return false;
    }
    if (in_array($part, ['1', '2'], true)) {
        return true;
    }
    foreach (toeicQuestionOptionTexts($row, $part) as $option) {
        if ($option === '') {
            return false;
        }
    }
    return true;
}

/** Resolve the answer by option content so rotating letters does not create a conflict. */
function toeicQuestionAnswerText(array $row, string $part): string {
    $letter = strtoupper(trim((string)($row['jawaban_benar'] ?? '')));
    $options = toeicQuestionOptionTexts($row, $part);
    return ($options[$letter] ?? '') !== '' ? $options[$letter] : 'audio-answer:' . $letter;
}

/**
 * Extract a spoken prompt only when all known responses form a complete transcript suffix.
 * Never strip matching words from the middle of a question: that could merge different prompts.
 */
function toeicQuestionSpokenPrompt(array $row): string {
    $explicit = toeicNormalizeQuestionContent((string)($row['_audio_prompt'] ?? ''));
    if ($explicit !== '') {
        return $explicit;
    }
    $transcript = toeicNormalizeQuestionContent((string)($row['_audio_transcript'] ?? ''));
    $responses = array_values(array_filter(toeicQuestionOptionTexts($row, '2'), static fn(string $value): bool => $value !== ''));
    if (count($responses) !== 3) {
        return $transcript;
    }
    $remaining = $transcript;
    while ($responses !== []) {
        $matched = false;
        foreach ($responses as $index => $response) {
            if (!str_ends_with($remaining, $response)) {
                continue;
            }
            $prefix = substr($remaining, 0, strlen($remaining) - strlen($response));
            if ($prefix !== '' && !preg_match('/[\s.:)]$/u', $prefix)) {
                continue;
            }
            $remaining = trim((string)preg_replace('/(?:^|\s)[a-d][).:]\s*$/u', '', rtrim($prefix)));
            unset($responses[$index]);
            $matched = true;
            break;
        }
        if (!$matched) {
            return $transcript;
        }
    }
    return $remaining !== '' ? $remaining : $transcript;
}

/**
 * Identify the actual stimulus instead of its database foreign key.
 * Missing transcript/content falls back conservatively to a media reference, then to its ID.
 */
function toeicStimulusContentSignature(array $row, string $part): string {
    if (in_array($part, ['6', '7'], true)) {
        $passages = [];
        foreach (['_passage_1', '_passage_2', '_passage_3'] as $field) {
            $passages[] = toeicNormalizeQuestionContent((string)($row[$field] ?? ''));
        }
        $payload = implode('', $passages) !== '' ? ['text', $passages] : ['missing-text', (int)($row['id_teks'] ?? 0)];
    } elseif ($part === '1') {
        $path = trim((string)($row['_photo_path'] ?? ''));
        $payload = $path !== '' ? ['photo', $path] : ['missing-photo', (int)($row['id_audio'] ?? 0)];
    } elseif (in_array($part, ['2', '3', '4'], true)) {
        $transcript = $part === '2'
            ? toeicQuestionSpokenPrompt($row)
            : toeicNormalizeQuestionContent((string)($row['_audio_transcript'] ?? ''));
        $path = trim((string)($row['_audio_path'] ?? ''));
        $payload = $transcript !== '' ? ['spoken', $transcript]
            : ($path !== '' ? ['audio-path', $path] : ['missing-audio', (int)($row['id_audio'] ?? 0)]);
    } else {
        $payload = ['individual'];
    }
    return hash('sha256', json_encode([$part, $payload], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/**
 * Identify a logical question independently of row IDs and option order.
 * The answer key is deliberately separate: conflicting keys must not turn one question into two.
 */
function toeicQuestionContentSignature(array $row, string $part): string {
    $options = array_values(toeicQuestionOptionTexts($row, $part));
    sort($options, SORT_STRING);
    $stem = toeicNormalizeQuestionContent((string)($row['pertanyaan'] ?? ''));
    if ($part === '1' || ($part === '2' && toeicQuestionSpokenPrompt($row) !== '')) {
        $stem = '';
    }
    $payload = [$part, $stem, $options, toeicStimulusContentSignature($row, $part)];
    if ($part === '1' && implode('', $options) === '') {
        $payload[] = toeicNormalizeQuestionContent((string)($row['_audio_transcript'] ?? ''));
    }
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
