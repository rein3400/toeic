<?php
/**
 * TOEIC Speaking & Writing subjective scoring.
 */

require_once __DIR__ . '/toeic_sw_helper.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ai_helper.php';

function getToeicSwOpenAiKey(mysqli $conn): ?string {
    $envKey = getenv('OPENAI_API_KEY');
    if ($envKey) {
        return $envKey;
    }

    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'ai_api_openai' LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $config = json_decode((string)$row['setting_value'], true);
    if (!is_array($config) || empty($config['api_key'])) {
        return null;
    }

    return (string)$config['api_key'];
}

function getToeicSwActiveScoringConfig(mysqli $conn): ?array {
    $config = getActiveAIProvider();
    if (!$config) {
        $apiKey = getToeicSwOpenAiKey($conn);
        if (!$apiKey) {
            return null;
        }
        $config = [
            'provider' => 'OpenAI',
            'api_key' => $apiKey,
            'llm' => getSiteSetting('toeic_sw_scoring_model', 'gpt-5.5'),
            'reasoning_effort' => 'high',
        ];
    }

    if (strtolower((string)($config['provider'] ?? '')) === 'openai') {
        $config['llm'] = getSiteSetting('toeic_sw_scoring_model', 'gpt-5.5');
        $config['reasoning_effort'] = $config['reasoning_effort'] ?? 'high';
    }

    return $config;
}

function toeicSwScoringRequestTimeoutMs(int $maxMs = 30000, int $reserveMs = 5000): int {
    $deadline = $GLOBALS['TOEIC_SW_INTERACTIVE_SCORING_DEADLINE'] ?? null;
    if (!is_numeric($deadline)) {
        return $maxMs;
    }

    $remainingMs = (int)floor(((float)$deadline - microtime(true)) * 1000) - $reserveMs;
    if ($remainingMs <= 0) {
        return 0;
    }

    return max(1000, min($maxMs, $remainingMs));
}

function toeicSwScoringBudgetExhausted(int $reserveMs = 5000): bool {
    return toeicSwScoringRequestTimeoutMs(30000, $reserveMs) < 5000;
}

function transcribeToeicSwAudio(mysqli $conn, string $relativePath): array {
    $apiKey = getToeicSwOpenAiKey($conn);
    if (!$apiKey) {
        return ['success' => false, 'error' => 'OpenAI transcription is not configured'];
    }

    $absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
    if (!$absolutePath || !is_file($absolutePath)) {
        return ['success' => false, 'error' => 'Audio file not found'];
    }

    $timeoutMs = toeicSwScoringRequestTimeoutMs(30000);
    if ($timeoutMs < 5000) {
        return ['success' => false, 'error' => 'Interactive scoring time budget exhausted; queued for admin rescore'];
    }

    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mimeMap = [
        'webm' => 'audio/webm',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'mp4' => 'audio/mp4',
        'mpeg' => 'audio/mpeg',
        'mpga' => 'audio/mpeg',
        'flac' => 'audio/flac',
    ];
    $mimeType = $mimeMap[$ext] ?? 'audio/webm';
    $uploadName = $ext === 'opus'
        ? pathinfo($absolutePath, PATHINFO_FILENAME) . '.ogg'
        : basename($absolutePath);

    $postFields = [
        'file' => new CURLFile($absolutePath, $mimeType, $uploadName),
        'model' => getSiteSetting('toeic_sw_transcription_model', 'gpt-4o-transcribe'),
        'response_format' => 'json',
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        CURLOPT_NOSIGNAL => 1,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Transcription network error: ' . $curlError];
    }

    $decoded = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $message = $decoded['error']['message'] ?? substr((string)$response, 0, 200);
        return ['success' => false, 'error' => 'Transcription failed: ' . $message];
    }

    $text = trim((string)($decoded['text'] ?? ''));
    if ($text === '') {
        return ['success' => false, 'error' => 'Empty transcription result'];
    }

    return ['success' => true, 'text' => $text];
}

function parseToeicSwScoreResponse(string $response): ?array {
    $response = trim($response);
    if ($response === '') {
        return null;
    }
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    if (preg_match('/\{.*\}/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);
        return is_array($decoded) ? $decoded : null;
    }
    return null;
}

function buildToeicSwContentPrompt(array $questionRow, ?string $transcript = null): string {
    $content = $questionRow['content'] ?? [];
    $type = (string)$questionRow['question_type'];
    $prompt = "TOEIC Speaking & Writing task type: {$type}\n";
    $prompt .= "Question number: " . (int)$questionRow['question_order'] . "\n";
    $prompt .= "Prompt: " . trim((string)($content['prompt_text'] ?? '')) . "\n";

    if (!empty($content['information_card'])) {
        $prompt .= "Information card:\n" . trim((string)$content['information_card']) . "\n";
    }
    if (!empty($content['required_words_json'])) {
        $words = json_decode((string)$content['required_words_json'], true) ?: [];
        $prompt .= "Required words or phrases: " . implode(', ', array_map('strval', $words)) . "\n";
    }
    if (!empty($content['scoring_rubric'])) {
        $prompt .= "Rubric:\n" . trim((string)$content['scoring_rubric']) . "\n";
    }
    if ($transcript !== null) {
        $prompt .= "Student transcript:\n{$transcript}\n";
    } else {
        $prompt .= "Student response:\n" . trim((string)($questionRow['user_answer'] ?? '')) . "\n";
    }

    return $prompt;
}

function requestToeicSwSubjectiveScore(array $activeConfig, string $section, string $questionType, string $prompt): ?array {
    $systemPrompt = <<<PROMPT
You are a strict TOEIC Speaking and Writing examiner.
Score the student response for section "{$section}" and task "{$questionType}".

Return JSON only with this exact shape:
{
  "score_0_to_30": 0,
  "band_label": "Needs Work",
  "feedback_summary": "short paragraph",
  "strengths": ["item 1", "item 2"],
  "weaknesses": ["item 1", "item 2"],
  "evidence": ["specific observation 1", "specific observation 2"]
}

Rules:
- score_0_to_30 must be a number between 0 and 30.
- For speaking, evaluate task completion, intelligibility, pronunciation evidence, fluency, grammar, vocabulary, and coherence from the transcript.
- For writing, evaluate task completion, grammar, vocabulary, organization, clarity, and required content.
- If the response is empty, off-topic, too short, or not assessable, score it low.
PROMPT;

    $timeoutMs = toeicSwScoringRequestTimeoutMs(30000);
    if ($timeoutMs < 5000) {
        return null;
    }

    $response = callAI($systemPrompt . "\n\n" . $prompt, $activeConfig, 1600, [], $timeoutMs);
    return parseToeicSwScoreResponse($response);
}

function storeToeicSwSubjectiveScore(
    mysqli $conn,
    array $questionRow,
    ?string $sourcePath,
    ?string $transcriptText,
    float $rawScore,
    float $normalizedScore,
    array $feedback,
    array $activeConfig,
    string $status
): void {
    ensureToeicSwSchema($conn);
    $provider = $activeConfig['provider'] ?? null;
    $model = $activeConfig['llm'] ?? null;
    $feedbackJson = json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fallbackReason = isset($feedback['fallback_reason']) ? (string)$feedback['fallback_reason'] : null;
    $testSession = (string)$questionRow['test_session'];
    $userId = (int)$questionRow['user_id'];
    $questionRowId = (int)$questionRow['id'];
    $questionId = (int)$questionRow['question_id'];
    $questionType = (string)$questionRow['question_type'];
    $section = (string)$questionRow['section'];

    $stmt = $conn->prepare("
        INSERT INTO toeic_sw_subjective_scores
        (test_session, user_id, question_row_id, question_id, question_type, section, source_path,
         transcript_text, raw_score, normalized_score, feedback_json, ai_provider, ai_model, status, fallback_reason)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            source_path = VALUES(source_path),
            transcript_text = VALUES(transcript_text),
            raw_score = VALUES(raw_score),
            normalized_score = VALUES(normalized_score),
            feedback_json = VALUES(feedback_json),
            ai_provider = VALUES(ai_provider),
            ai_model = VALUES(ai_model),
            status = VALUES(status),
            fallback_reason = VALUES(fallback_reason)
    ");
    $stmt->bind_param(
        "siiissssddsssss",
        $testSession,
        $userId,
        $questionRowId,
        $questionId,
        $questionType,
        $section,
        $sourcePath,
        $transcriptText,
        $rawScore,
        $normalizedScore,
        $feedbackJson,
        $provider,
        $model,
        $status,
        $fallbackReason
    );
    $stmt->execute();
    $stmt->close();
}

function fallbackToeicSwWritingScore(string $answer, int $targetWords): float {
    $answer = trim($answer);
    if ($answer === '') {
        return 0.0;
    }
    $words = count(array_filter(preg_split('/\s+/', $answer)));
    return round(min(0.65, $words / max(1, $targetWords)), 4);
}

function fallbackToeicSwSpeakingScore(string $audioPath, ?string $transcript = null): float {
    if (trim($audioPath) === '') {
        return 0.0;
    }
    if ($transcript !== null && trim($transcript) !== '') {
        $words = count(array_filter(preg_split('/\s+/', trim($transcript))));
        return round(min(0.55, $words / 45), 4);
    }
    return 0.1;
}

function toeicSwStoreFallbackScore(mysqli $conn, array $questionRow, array $activeConfig, string $reason): float {
    $section = (string)$questionRow['section'];
    $questionType = (string)$questionRow['question_type'];
    $userAnswer = (string)($questionRow['user_answer'] ?? '');
    $sourcePath = (string)($questionRow['source_path'] ?? '');
    $fallback = $section === 'speaking'
        ? fallbackToeicSwSpeakingScore($sourcePath ?: $userAnswer)
        : fallbackToeicSwWritingScore($userAnswer, $questionType === 'write_opinion_essay' ? 300 : 40);

    storeToeicSwSubjectiveScore(
        $conn,
        $questionRow,
        $section === 'speaking' ? ($sourcePath ?: $userAnswer) : null,
        null,
        round($fallback * 30, 2),
        $fallback,
        ['fallback_reason' => $reason],
        $activeConfig,
        'needs_rescore'
    );

    return $fallback;
}

function scoreToeicSwSubjectiveQuestion(mysqli $conn, array $questionRow): float {
    $section = (string)$questionRow['section'];
    $questionType = (string)$questionRow['question_type'];
    $userAnswer = (string)($questionRow['user_answer'] ?? '');
    $sourcePath = (string)($questionRow['source_path'] ?? '');
    $activeConfig = getToeicSwActiveScoringConfig($conn);

    if (!$activeConfig) {
        $fallback = $section === 'speaking'
            ? fallbackToeicSwSpeakingScore($sourcePath ?: $userAnswer)
            : fallbackToeicSwWritingScore($userAnswer, $questionType === 'write_opinion_essay' ? 300 : 40);
        $fallbackConfig = [
            'provider' => 'OpenAI',
            'llm' => getSiteSetting('toeic_sw_scoring_model', 'gpt-5.5'),
        ];
        storeToeicSwSubjectiveScore(
            $conn,
            $questionRow,
            $section === 'speaking' ? ($sourcePath ?: $userAnswer) : null,
            null,
            round($fallback * 30, 2),
            $fallback,
            ['fallback_reason' => 'Active AI provider is not configured'],
            $fallbackConfig,
            'needs_rescore'
        );
        return $fallback;
    }

    if (toeicSwScoringBudgetExhausted()) {
        return toeicSwStoreFallbackScore($conn, $questionRow, $activeConfig, 'Interactive scoring time budget exhausted; queued for admin rescore');
    }

    try {
        if ($section === 'speaking') {
            $path = $sourcePath ?: $userAnswer;
            if (trim($path) === '') {
                return 0.0;
            }

            $transcription = transcribeToeicSwAudio($conn, $path);
            if (!$transcription['success']) {
                $fallback = fallbackToeicSwSpeakingScore($path);
                storeToeicSwSubjectiveScore(
                    $conn,
                    $questionRow,
                    $path,
                    null,
                    round($fallback * 30, 2),
                    $fallback,
                    ['fallback_reason' => $transcription['error']],
                    $activeConfig,
                    'needs_rescore'
                );
                return $fallback;
            }

            $prompt = buildToeicSwContentPrompt($questionRow, $transcription['text']);
            if (toeicSwScoringBudgetExhausted()) {
                return toeicSwStoreFallbackScore($conn, $questionRow, $activeConfig, 'Interactive scoring time budget exhausted after transcription; queued for admin rescore');
            }
            $scoreData = requestToeicSwSubjectiveScore($activeConfig, $section, $questionType, $prompt);
            if (!$scoreData || !isset($scoreData['score_0_to_30'])) {
                $fallback = fallbackToeicSwSpeakingScore($path, $transcription['text']);
                storeToeicSwSubjectiveScore(
                    $conn,
                    $questionRow,
                    $path,
                    $transcription['text'],
                    round($fallback * 30, 2),
                    $fallback,
                    ['fallback_reason' => 'Scoring response invalid'],
                    $activeConfig,
                    'needs_rescore'
                );
                return $fallback;
            }

            $raw = max(0, min(30, (float)$scoreData['score_0_to_30']));
            $normalized = round($raw / 30, 4);
            storeToeicSwSubjectiveScore($conn, $questionRow, $path, $transcription['text'], $raw, $normalized, $scoreData, $activeConfig, 'scored');
            return $normalized;
        }

        if (trim($userAnswer) === '') {
            return 0.0;
        }

        $prompt = buildToeicSwContentPrompt($questionRow);
        if (toeicSwScoringBudgetExhausted()) {
            return toeicSwStoreFallbackScore($conn, $questionRow, $activeConfig, 'Interactive scoring time budget exhausted; queued for admin rescore');
        }
        $scoreData = requestToeicSwSubjectiveScore($activeConfig, $section, $questionType, $prompt);
        if (!$scoreData || !isset($scoreData['score_0_to_30'])) {
            $fallback = fallbackToeicSwWritingScore($userAnswer, $questionType === 'write_opinion_essay' ? 300 : 40);
            storeToeicSwSubjectiveScore(
                $conn,
                $questionRow,
                null,
                null,
                round($fallback * 30, 2),
                $fallback,
                ['fallback_reason' => 'Scoring response invalid'],
                $activeConfig,
                'needs_rescore'
            );
            return $fallback;
        }

        $raw = max(0, min(30, (float)$scoreData['score_0_to_30']));
        $normalized = round($raw / 30, 4);
        storeToeicSwSubjectiveScore($conn, $questionRow, null, null, $raw, $normalized, $scoreData, $activeConfig, 'scored');
        return $normalized;
    } catch (Throwable $e) {
        error_log('TOEIC SW subjective scoring fallback: ' . $e->getMessage());
        $fallback = $section === 'speaking'
            ? fallbackToeicSwSpeakingScore($sourcePath ?: $userAnswer)
            : fallbackToeicSwWritingScore($userAnswer, $questionType === 'write_opinion_essay' ? 300 : 40);
        storeToeicSwSubjectiveScore(
            $conn,
            $questionRow,
            $section === 'speaking' ? ($sourcePath ?: $userAnswer) : null,
            null,
            round($fallback * 30, 2),
            $fallback,
            ['fallback_reason' => $e->getMessage()],
            $activeConfig,
            'needs_rescore'
        );
        return $fallback;
    }
}
?>
