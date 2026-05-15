<?php
/**
 * Text-only TOEIC SW scoring fixtures for production QA.
 *
 * These helpers seed realistic-but-not-identical transcripts/answers into a
 * completed session, then let the normal subjective scorer evaluate them.
 */

if (!function_exists('toeicSwFixtureCleanText')) {
    function toeicSwFixtureCleanText($value): string {
        return trim(preg_replace('/\s+/', ' ', (string)$value) ?? '');
    }
}

if (!function_exists('toeicSwFixtureRequiredWords')) {
    function toeicSwFixtureRequiredWords(array $content): array {
        $raw = $content['required_words_json'] ?? $content['required_words'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        $words = [];
        foreach ((array)$raw as $word) {
            $word = toeicSwFixtureCleanText($word);
            if ($word !== '') {
                $words[] = $word;
            }
        }
        return $words;
    }
}

if (!function_exists('toeicSwFixtureDifferentEnough')) {
    function toeicSwFixtureDifferentEnough(string $fixture, array $content): bool {
        $normalizedFixture = strtolower(toeicSwFixtureCleanText($fixture));
        $prompt = strtolower(toeicSwFixtureCleanText($content['prompt_text'] ?? ''));
        $sample = strtolower(toeicSwFixtureCleanText($content['sample_response'] ?? ''));

        return $normalizedFixture !== ''
            && ($prompt === '' || $normalizedFixture !== $prompt)
            && ($sample === '' || $normalizedFixture !== $sample);
    }
}

if (!function_exists('toeicSwFixtureSpeakingTranscript')) {
    function toeicSwFixtureSpeakingTranscript(array $question): string {
        $content = $question['content'] ?? [];
        $type = (string)($question['question_type'] ?? '');
        $order = (int)($question['question_order'] ?? 0);
        $prompt = toeicSwFixtureCleanText($content['prompt_text'] ?? '');
        $lowerPrompt = strtolower($prompt);

        switch ($type) {
            case 'read_text_aloud':
                return 'Good morning. I would announce this notice clearly: ' . $prompt
                    . ' I would keep a steady pace, stress the date and location, and avoid rushing the final instruction.';

            case 'describe_picture':
                return 'The picture appears to show a tightly framed professional operations area with arranged containers, cabinets, and separated materials. Several objects look grouped by priority, so I would describe a workplace review where the team must compare preparation status, notice a possible delay, and decide which item needs attention first.';

            case 'respond_to_questions':
                if (strpos($lowerPrompt, 'unfocused') !== false) {
                    return 'I would first require a short agenda with one decision owner for each topic. That change keeps the meeting from becoming a general conversation, and it makes people prepare evidence before they speak. As a result, the team can finish with clearer actions and less repeated discussion.';
                }
                if (strpos($lowerPrompt, 'instruction') !== false) {
                    return 'I normally confirm complicated instructions by asking the person to restate the main steps, deadline, and expected result in their own words. Then I summarize the agreement in writing. This prevents polite misunderstanding and gives both sides a concrete reference.';
                }
                return 'I would usually delay the announcement until the critical details are reliable. Moving first can help a company, but an inaccurate public message damages trust and creates extra corrections. A brief delay is better when operational risk is still unclear.';

            case 'respond_using_information':
                if (strpos($lowerPrompt, 'secure badges') !== false) {
                    return 'Visitors should pick up their secure badges during registration at the west entrance. The schedule shows that registration and badge pickup take place before the morning briefing.';
                }
                if (strpos($lowerPrompt, 'immediately after') !== false || strpos($lowerPrompt, 'data protection') !== false) {
                    return 'Immediately after the data protection briefing, the schedule lists the contract exception review with procurement. It begins later in the morning after a short break.';
                }
                return 'If the vendor arrives only after lunch, they can still attend the system access demonstration and the final approval checklist. However, they will miss the morning registration, the data protection briefing, and the contract exception review.';

            case 'express_opinion':
                return 'In a complex workplace, I would promote employees who document decisions carefully and reduce future risk. Fast results matter, but speed without a clear record often creates hidden costs. For example, a product team may launch quickly, yet later discover that no one captured the compliance exception or the customer approval. A careful employee protects the company by making decisions traceable, helping other departments understand the rationale, and preventing the same problem from returning. I would still value speed, but I would treat disciplined documentation as the stronger promotion signal.';
        }

        return 'I would answer the task directly, give a specific workplace reason, and add one concrete example related to the prompt for question ' . $order . '.';
    }
}

if (!function_exists('toeicSwFixtureWrittenAnswer')) {
    function toeicSwFixtureWrittenAnswer(array $question): string {
        $content = $question['content'] ?? [];
        $type = (string)($question['question_type'] ?? '');
        $words = toeicSwFixtureRequiredWords($content);
        $wordOne = $words[0] ?? 'review';
        $wordTwo = $words[1] ?? 'schedule';

        switch ($type) {
            case 'write_sentence_based_on_picture':
                return 'The workplace scene shows staff deciding how to ' . $wordOne . ' the ' . $wordTwo . ' while keeping the vendor risk review organized.';

            case 'respond_to_written_request':
                return 'Dear colleague, thank you for explaining the revised request. I can support the change, but I need to confirm the final timeline with operations before we notify outside participants. I will also check whether the compliance review affects the demonstration materials, speaker availability, or room setup. As a next step, I will send a concise update by tomorrow afternoon with any conflicts and a practical recommendation. Please let me know if there is a fixed deadline that cannot move.';

            case 'write_opinion_essay':
                return "I believe employees learn best when experienced coworkers guide them through real work, although formal training should still define the basic standard. A course can explain the official policy, but it cannot show every judgment call that appears in a busy workplace. When a new employee sits beside a skilled colleague, the person can see how priorities are balanced, how customers are handled, and how small mistakes are corrected before they become larger problems.\n\nFirst, experienced coworkers provide context. For example, a procurement course may explain how to evaluate a supplier, but a senior employee can show why a minor delivery delay matters more when the client is launching a seasonal campaign. That kind of practical reasoning is difficult to learn from slides alone. It also helps newer staff ask questions immediately instead of waiting for a later review.\n\nSecond, coworker learning builds professional habits. Employees observe tone, documentation style, meeting discipline, and follow-up behavior. These habits are often the difference between average performance and reliable performance. If the mentor gives feedback respectfully, the learner improves faster and becomes more confident.\n\nHowever, companies should not depend only on informal mentoring. Without formal courses, different teams may teach inconsistent procedures. The strongest approach is to combine both: use training courses for rules, compliance, and shared vocabulary, then use experienced coworkers to translate those standards into realistic decisions. This combination is especially effective in complex organizations because it gives employees both consistency and judgment.";
        }

        return 'The response addresses the task with a clear workplace point, relevant detail, and a professional tone.';
    }
}

if (!function_exists('toeicSwFixtureAllQuestions')) {
    function toeicSwFixtureAllQuestions(mysqli $conn, string $testSession): array {
        return array_merge(
            getToeicSwQuestionsForSection($conn, $testSession, 'speaking'),
            getToeicSwQuestionsForSection($conn, $testSession, 'writing')
        );
    }
}

if (!function_exists('toeicSwFixtureStatus')) {
    function toeicSwFixtureStatus(mysqli $conn, string $testSession): array {
        $counts = [
            'scored' => 0,
            'needs_rescore' => 0,
            'fallback' => 0,
            'pending' => 0,
            'total' => 0,
        ];

        $stmt = $conn->prepare("
            SELECT status, COUNT(*) AS total
            FROM toeic_sw_subjective_scores
            WHERE test_session = ?
            GROUP BY status
        ");
        $stmt->bind_param("s", $testSession);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $status = (string)($row['status'] ?? 'pending');
            $total = (int)($row['total'] ?? 0);
            $counts[$status] = $total;
            $counts['total'] += $total;
        }
        $stmt->close();

        $resultRow = null;
        $stmt = $conn->prepare("
            SELECT speaking_scaled, writing_scaled, total_score, cefr_level
            FROM toeic_sw_test_results
            WHERE test_session = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $testSession);
        $stmt->execute();
        $resultRow = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return [
            'counts' => $counts,
            'result' => $resultRow,
        ];
    }
}

if (!function_exists('toeicSwFixtureSession')) {
    function toeicSwFixtureSession(mysqli $conn, string $testSession): ?array {
        $stmt = $conn->prepare("SELECT * FROM toeic_sw_test_sessions WHERE test_session = ? LIMIT 1");
        $stmt->bind_param("s", $testSession);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('toeicSwFixtureSeedSession')) {
    function toeicSwFixtureSeedSession(mysqli $conn, string $testSession): array {
        ensureToeicSwSchema($conn);
        $session = toeicSwFixtureSession($conn, $testSession);
        if (!$session) {
            throw new RuntimeException('TOEIC SW session not found.');
        }
        if (($session['status'] ?? '') !== 'completed') {
            throw new RuntimeException('Text fixture scoring is only allowed for completed TOEIC SW sessions.');
        }

        $questions = toeicSwFixtureAllQuestions($conn, $testSession);
        if (count($questions) !== 19) {
            throw new RuntimeException('Expected 19 TOEIC SW questions, found ' . count($questions) . '.');
        }

        $conn->begin_transaction();
        try {
            $seeded = ['speaking' => 0, 'writing' => 0, 'different' => 0];
            $fixtureConfig = ['provider' => 'fixture', 'llm' => 'text-only-scoring-fixture'];

            $stmt = $conn->prepare("UPDATE toeic_sw_test_questions SET is_correct = NULL WHERE test_session = ?");
            $stmt->bind_param("s", $testSession);
            $stmt->execute();
            $stmt->close();

            foreach ($questions as $question) {
                $section = (string)$question['section'];
                $content = $question['content'] ?? [];
                $rowId = (int)$question['id'];

                if ($section === 'speaking') {
                    $transcript = toeicSwFixtureSpeakingTranscript($question);
                    if (toeicSwFixtureDifferentEnough($transcript, $content)) {
                        $seeded['different']++;
                    }
                    $sourcePath = trim((string)($question['source_path'] ?? ''));
                    if ($sourcePath === '') {
                        $sourcePath = 'text-fixture://speaking/' . $rowId;
                    }
                    storeToeicSwSubjectiveScore(
                        $conn,
                        $question,
                        $sourcePath,
                        $transcript,
                        0.0,
                        0.0,
                        ['fallback_reason' => 'Text-only scoring QA fixture; transcript seeded without audio transcription.'],
                        $fixtureConfig,
                        'needs_rescore'
                    );
                    $seeded['speaking']++;
                } else {
                    $answer = toeicSwFixtureWrittenAnswer($question);
                    if (toeicSwFixtureDifferentEnough($answer, $content)) {
                        $seeded['different']++;
                    }
                    $stmt = $conn->prepare("UPDATE toeic_sw_test_questions SET user_answer = ?, is_correct = NULL WHERE id = ?");
                    $stmt->bind_param("si", $answer, $rowId);
                    $stmt->execute();
                    $stmt->close();

                    storeToeicSwSubjectiveScore(
                        $conn,
                        $question,
                        null,
                        null,
                        0.0,
                        0.0,
                        ['fallback_reason' => 'Text-only scoring QA fixture; written answer seeded for AI scoring.'],
                        $fixtureConfig,
                        'needs_rescore'
                    );
                    $seeded['writing']++;
                }
            }

            $conn->commit();
            return $seeded + toeicSwFixtureStatus($conn, $testSession);
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('toeicSwFixtureScoreNext')) {
    function toeicSwFixtureScoreNext(mysqli $conn, string $testSession): array {
        ensureToeicSwSchema($conn);
        $session = toeicSwFixtureSession($conn, $testSession);
        if (!$session) {
            throw new RuntimeException('TOEIC SW session not found.');
        }

        $stmt = $conn->prepare("
            SELECT q.id, q.section, q.question_order
            FROM toeic_sw_test_questions q
            JOIN toeic_sw_subjective_scores s
              ON s.test_session = q.test_session
             AND s.question_row_id = q.id
            WHERE q.test_session = ?
              AND s.status = 'needs_rescore'
            ORDER BY FIELD(q.section, 'speaking', 'writing'), q.question_order
            LIMIT 1
        ");
        $stmt->bind_param("s", $testSession);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            return ['processed' => false] + toeicSwFixtureStatus($conn, $testSession);
        }

        $question = getToeicSwQuestionRow($conn, $testSession, (string)$target['section'], (int)$target['question_order']);
        if (!$question) {
            throw new RuntimeException('Question row not found for scoring fixture.');
        }

        $normalized = scoreToeicSwSubjectiveQuestion($conn, $question);
        $rowId = (int)$question['id'];
        $stmt = $conn->prepare("UPDATE toeic_sw_test_questions SET is_correct = ? WHERE id = ?");
        $stmt->bind_param("di", $normalized, $rowId);
        $stmt->execute();
        $stmt->close();

        $scorer = new ToeicSwScorer($conn);
        $final = $scorer->saveResults($testSession, (int)$session['user_id']);

        $scoreRow = null;
        $stmt = $conn->prepare("
            SELECT status, fallback_reason, ai_provider, ai_model
            FROM toeic_sw_subjective_scores
            WHERE test_session = ? AND question_row_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("si", $testSession, $rowId);
        $stmt->execute();
        $scoreRow = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return [
            'processed' => true,
            'question_row_id' => $rowId,
            'section' => (string)$question['section'],
            'question_order' => (int)$question['question_order'],
            'normalized_score' => $normalized,
            'scored_status' => $scoreRow['status'] ?? null,
            'fallback_reason' => $scoreRow['fallback_reason'] ?? null,
            'ai_provider' => $scoreRow['ai_provider'] ?? null,
            'ai_model' => $scoreRow['ai_model'] ?? null,
            'final_score' => $final,
        ] + toeicSwFixtureStatus($conn, $testSession);
    }
}
?>
