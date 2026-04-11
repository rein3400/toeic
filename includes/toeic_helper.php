<?php
/**
 * TOEIC Helper Functions
 * Handles all TOEIC-specific test logic including:
 * - Question generation and retrieval (Parts 1-7)
 * - Scoring calculations (5-495 per section, 10-990 total)
 * - Part-specific handling (Photos for Part 1, etc.)
 */

if (file_exists(__DIR__ . '/toeic_scorer.php')) {
    require_once __DIR__ . '/toeic_scorer.php';
}

// ============================================================
// SESSION & QUESTION MANAGEMENT
// ============================================================

if (!function_exists('generateTOEICTestSession')) {
    /**
     * Generate a new TOEIC test session ID
     */
    function generateTOEICTestSession() {
        return 'toeic_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('getSeenTOEICQuestionIds')) {
    /**
     * Return question IDs (id_soal) already assigned to a user for a
     * specific TOEIC part across all previous test sessions.
     */
    function getSeenTOEICQuestionIds($conn, $user_id, $part)
    {
        $stmt = $conn->prepare(
            "SELECT DISTINCT question_id FROM toeic_test_questions WHERE user_id = ? AND part = ?"
        );
        if (!$stmt) return [];
        $stmt->bind_param("is", $user_id, $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['question_id'];
        }
        $stmt->close();
        return $ids;
    }
}

if (!function_exists('getTOEICContentReadiness')) {
    /**
     * Return TOEIC item-bank readiness counts per part.
     *
     * @return array{ready: bool, parts: array<string, array{label: string, target: int, actual: int, gap: int}>}
     */
    function getTOEICContentReadiness($conn)
    {
        $targets = [
            '1' => ['label' => 'Part 1', 'target' => 6, 'table' => 'toeic_soal_listening'],
            '2' => ['label' => 'Part 2', 'target' => 25, 'table' => 'toeic_soal_listening'],
            '3' => ['label' => 'Part 3', 'target' => 39, 'table' => 'toeic_soal_listening'],
            '4' => ['label' => 'Part 4', 'target' => 30, 'table' => 'toeic_soal_listening'],
            '5' => ['label' => 'Part 5', 'target' => 30, 'table' => 'toeic_soal_reading'],
            '6' => ['label' => 'Part 6', 'target' => 16, 'table' => 'toeic_soal_reading'],
            '7' => ['label' => 'Part 7', 'target' => 54, 'table' => 'toeic_soal_reading'],
        ];

        $summary = [];
        $ready = true;

        foreach ($targets as $part => $meta) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM {$meta['table']} WHERE part = ?");
            $stmt->bind_param('s', $part);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $actual = (int)($row['total'] ?? 0);
            $target = (int)$meta['target'];
            $gap = max(0, $target - $actual);

            if ($gap > 0) {
                $ready = false;
            }

            $summary[$part] = [
                'label' => $meta['label'],
                'target' => $target,
                'actual' => $actual,
                'gap' => $gap,
            ];
        }

        return [
            'ready' => $ready,
            'parts' => $summary,
        ];
    }
}

if (!function_exists('getSeenTOEICAudioIds')) {
    /**
     * Return audio group IDs already seen by a user for a specific TOEIC
     * listening part (parts 3 & 4). Uses the question IDs in
     * toeic_test_questions to look up their id_audio in the source table.
     */
    function getSeenTOEICAudioIds($conn, $user_id, $part)
    {
        $stmt = $conn->prepare(
            "SELECT DISTINCT sl.id_audio
             FROM toeic_test_questions ttq
             JOIN toeic_soal_listening sl ON ttq.question_id = sl.id_soal
             WHERE ttq.user_id = ? AND ttq.part = ? AND sl.id_audio IS NOT NULL"
        );
        if (!$stmt) return [];
        $stmt->bind_param("is", $user_id, $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id_audio'];
        }
        $stmt->close();
        return $ids;
    }
}

if (!function_exists('getSeenTOEICTextIds')) {
    /**
     * Return text group IDs already seen by a user for TOEIC Part 6.
     */
    function getSeenTOEICTextIds($conn, $user_id, $part)
    {
        $stmt = $conn->prepare(
            "SELECT DISTINCT sr.id_teks
             FROM toeic_test_questions ttq
             JOIN toeic_soal_reading sr ON ttq.question_id = sr.id_soal
             WHERE ttq.user_id = ? AND ttq.part = ? AND sr.id_teks IS NOT NULL"
        );
        if (!$stmt) return [];
        $stmt->bind_param("is", $user_id, $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id_teks'];
        }
        $stmt->close();
        return $ids;
    }
}

if (!function_exists('generateTOEICRandomizedQuestions')) {
    /**
     * Generate randomized questions for a TOEIC test session.
     * Prioritises questions the user has not seen in previous sessions.
     *
     * @param string $test_session
     * @param int    $user_id
     */
    function generateTOEICRandomizedQuestions($test_session, $user_id) {
        global $conn;

        if (file_exists(__DIR__ . '/toeic_test_builder.php')) {
            require_once __DIR__ . '/toeic_test_builder.php';
        }

        if (($conn instanceof mysqli) && class_exists('ToeicTestBuilder')) {
            $builder = new ToeicTestBuilder($conn);
            $builder->createSession($test_session, $user_id);
            $builder->buildTest($test_session, $user_id);
            return;
        }

        $sql         = "INSERT INTO toeic_test_questions (test_session, user_id, question_id, section, part, question_order) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql);

        // ─────────────────────────────────────────────────────
        // LISTENING SECTION (100 questions)
        // ─────────────────────────────────────────────────────

        // Part 1: Photographs (6 individual questions)
        $seenP1 = getSeenTOEICQuestionIds($conn, $user_id, '1');
        $excludeP1 = empty($seenP1) ? '0' : implode(',', $seenP1);
        $part1 = [];
        $res = $conn->query("SELECT id_soal FROM toeic_soal_listening WHERE part = '1' AND id_soal NOT IN ($excludeP1) ORDER BY RAND() LIMIT 6");
        if ($res) $part1 = $res->fetch_all(MYSQLI_ASSOC);
        if (count($part1) < 6) {
            $have = empty($part1) ? '0' : implode(',', array_column($part1, 'id_soal'));
            $res  = $conn->query("SELECT id_soal FROM toeic_soal_listening WHERE part = '1' AND id_soal NOT IN ($have) ORDER BY RAND() LIMIT " . (6 - count($part1)));
            if ($res) $part1 = array_merge($part1, $res->fetch_all(MYSQLI_ASSOC));
        }

        // Part 2: Question-Response (25 individual questions)
        $seenP2 = getSeenTOEICQuestionIds($conn, $user_id, '2');
        $excludeP2 = empty($seenP2) ? '0' : implode(',', $seenP2);
        $part2 = [];
        $res = $conn->query("SELECT id_soal FROM toeic_soal_listening WHERE part = '2' AND id_soal NOT IN ($excludeP2) ORDER BY RAND() LIMIT 25");
        if ($res) $part2 = $res->fetch_all(MYSQLI_ASSOC);
        if (count($part2) < 25) {
            $have = empty($part2) ? '0' : implode(',', array_column($part2, 'id_soal'));
            $res  = $conn->query("SELECT id_soal FROM toeic_soal_listening WHERE part = '2' AND id_soal NOT IN ($have) ORDER BY RAND() LIMIT " . (25 - count($part2)));
            if ($res) $part2 = array_merge($part2, $res->fetch_all(MYSQLI_ASSOC));
        }

        // Part 3: Conversations (39 questions — grouped by id_audio)
        $seenP3Audio = getSeenTOEICAudioIds($conn, $user_id, '3');
        $excludeP3   = empty($seenP3Audio) ? '0' : implode(',', $seenP3Audio);
        $part3_audios = [];
        $res = $conn->query("SELECT DISTINCT id_audio FROM toeic_soal_listening WHERE part = '3' AND id_audio IS NOT NULL AND id_audio NOT IN ($excludeP3) ORDER BY RAND() LIMIT 13");
        if ($res) $part3_audios = $res->fetch_all(MYSQLI_ASSOC);
        if (count($part3_audios) < 13) {
            $have = empty($part3_audios) ? '0' : implode(',', array_column($part3_audios, 'id_audio'));
            $res  = $conn->query("SELECT DISTINCT id_audio FROM toeic_soal_listening WHERE part = '3' AND id_audio IS NOT NULL AND id_audio NOT IN ($have) ORDER BY RAND() LIMIT " . (13 - count($part3_audios)));
            if ($res) $part3_audios = array_merge($part3_audios, $res->fetch_all(MYSQLI_ASSOC));
        }
        $part3 = [];
        foreach ($part3_audios as $audio) {
            $stmt = $conn->prepare("SELECT id_soal FROM toeic_soal_listening WHERE part = '3' AND id_audio = ? ORDER BY nomor_soal LIMIT 3");
            $stmt->bind_param("i", $audio['id_audio']);
            $stmt->execute();
            $part3 = array_merge($part3, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();
        }

        // Part 4: Talks (30 questions — grouped by id_audio)
        $seenP4Audio = getSeenTOEICAudioIds($conn, $user_id, '4');
        $excludeP4   = empty($seenP4Audio) ? '0' : implode(',', $seenP4Audio);
        $part4_audios = [];
        $res = $conn->query("SELECT DISTINCT id_audio FROM toeic_soal_listening WHERE part = '4' AND id_audio IS NOT NULL AND id_audio NOT IN ($excludeP4) ORDER BY RAND() LIMIT 10");
        if ($res) $part4_audios = $res->fetch_all(MYSQLI_ASSOC);
        if (count($part4_audios) < 10) {
            $have = empty($part4_audios) ? '0' : implode(',', array_column($part4_audios, 'id_audio'));
            $res  = $conn->query("SELECT DISTINCT id_audio FROM toeic_soal_listening WHERE part = '4' AND id_audio IS NOT NULL AND id_audio NOT IN ($have) ORDER BY RAND() LIMIT " . (10 - count($part4_audios)));
            if ($res) $part4_audios = array_merge($part4_audios, $res->fetch_all(MYSQLI_ASSOC));
        }
        $part4 = [];
        foreach ($part4_audios as $audio) {
            $stmt = $conn->prepare("SELECT id_soal FROM toeic_soal_listening WHERE part = '4' AND id_audio = ? ORDER BY nomor_soal LIMIT 3");
            $stmt->bind_param("i", $audio['id_audio']);
            $stmt->execute();
            $part4 = array_merge($part4, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();
        }

        // Insert Listening questions in order
        $order   = 1;
        $section = 'listening';
        foreach (['1' => $part1, '2' => $part2, '3' => $part3, '4' => $part4] as $part => $questions) {
            foreach ($questions as $q) {
                $stmt_insert->bind_param("siissi", $test_session, $user_id, $q['id_soal'], $section, $part, $order);
                $stmt_insert->execute();
                $order++;
            }
        }

        // ─────────────────────────────────────────────────────
        // READING SECTION (100 questions)
        // ─────────────────────────────────────────────────────

        // Part 5: Incomplete Sentences (30 individual questions)
        $seenP5 = getSeenTOEICQuestionIds($conn, $user_id, '5');
        $excludeP5 = empty($seenP5) ? '0' : implode(',', $seenP5);
        $part5 = [];
        $res = $conn->query("SELECT id_soal FROM toeic_soal_reading WHERE part = '5' AND id_soal NOT IN ($excludeP5) ORDER BY RAND() LIMIT 30");
        if ($res) $part5 = $res->fetch_all(MYSQLI_ASSOC);
        if (count($part5) < 30) {
            $have = empty($part5) ? '0' : implode(',', array_column($part5, 'id_soal'));
            $res  = $conn->query("SELECT id_soal FROM toeic_soal_reading WHERE part = '5' AND id_soal NOT IN ($have) ORDER BY RAND() LIMIT " . (30 - count($part5)));
            if ($res) $part5 = array_merge($part5, $res->fetch_all(MYSQLI_ASSOC));
        }

        // Part 6: Text Completion (16 questions — grouped by id_teks)
        $seenP6Texts = getSeenTOEICTextIds($conn, $user_id, '6');
        $excludeP6   = empty($seenP6Texts) ? '0' : implode(',', $seenP6Texts);
        $part6_texts = [];
        $res = $conn->query("SELECT DISTINCT id_teks FROM toeic_soal_reading WHERE part = '6' AND id_teks IS NOT NULL AND id_teks NOT IN ($excludeP6) ORDER BY RAND() LIMIT 4");
        if ($res) $part6_texts = $res->fetch_all(MYSQLI_ASSOC);
        if (count($part6_texts) < 4) {
            $have = empty($part6_texts) ? '0' : implode(',', array_column($part6_texts, 'id_teks'));
            $res  = $conn->query("SELECT DISTINCT id_teks FROM toeic_soal_reading WHERE part = '6' AND id_teks IS NOT NULL AND id_teks NOT IN ($have) ORDER BY RAND() LIMIT " . (4 - count($part6_texts)));
            if ($res) $part6_texts = array_merge($part6_texts, $res->fetch_all(MYSQLI_ASSOC));
        }
        $part6 = [];
        foreach ($part6_texts as $text) {
            $stmt = $conn->prepare("SELECT id_soal FROM toeic_soal_reading WHERE part = '6' AND id_teks = ? ORDER BY nomor_soal LIMIT 4");
            $stmt->bind_param("i", $text['id_teks']);
            $stmt->execute();
            $part6 = array_merge($part6, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();
        }

        // Part 7: Reading Comprehension (54 individual questions)
        $seenP7 = getSeenTOEICQuestionIds($conn, $user_id, '7');
        $excludeP7 = empty($seenP7) ? '0' : implode(',', $seenP7);
        $part7 = [];
        $res = $conn->query("SELECT id_soal FROM toeic_soal_reading WHERE part = '7' AND id_soal NOT IN ($excludeP7) ORDER BY RAND() LIMIT 54");
        if ($res) $part7 = $res->fetch_all(MYSQLI_ASSOC);
        if (count($part7) < 54) {
            $have = empty($part7) ? '0' : implode(',', array_column($part7, 'id_soal'));
            $res  = $conn->query("SELECT id_soal FROM toeic_soal_reading WHERE part = '7' AND id_soal NOT IN ($have) ORDER BY RAND() LIMIT " . (54 - count($part7)));
            if ($res) $part7 = array_merge($part7, $res->fetch_all(MYSQLI_ASSOC));
        }

        // Insert Reading questions in order
        $order   = 1;
        $section = 'reading';
        foreach (['5' => $part5, '6' => $part6, '7' => $part7] as $part => $questions) {
            foreach ($questions as $q) {
                $stmt_insert->bind_param("siissi", $test_session, $user_id, $q['id_soal'], $section, $part, $order);
                $stmt_insert->execute();
                $order++;
            }
        }
    }
}

if (!function_exists('getTOEICRandomizedQuestion')) {
    /**
     * Get a specific question for a TOEIC test session
     * @param string $test_session
     * @param string $section 'listening' or 'reading'
     * @param int $order Question order within section
     * @return array|null
     */
    function getTOEICRandomizedQuestion($test_session, $section, $order) {
        global $conn;

        $table = ($section === 'listening') ? 'toeic_soal_listening' : 'toeic_soal_reading';

        $sql = "
            SELECT s.*, tq.question_order, tq.question_id, tq.part
            FROM toeic_test_questions tq
            JOIN $table s ON tq.question_id = s.id_soal
            WHERE tq.test_session = ? AND tq.section = ? AND tq.question_order = ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;

        $stmt->bind_param("ssi", $test_session, $section, $order);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getTOEICTotalQuestions')) {
    /**
     * Get total questions for a section in a TOEIC test
     * @param string $test_session
     * @param string $section
     * @return int
     */
    function getTOEICTotalQuestions($test_session, $section) {
        global $conn;
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM toeic_test_questions WHERE test_session = ? AND section = ?");
        $stmt->bind_param("ss", $test_session, $section);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        return $res['count'] ?? 0;
    }
}

// ============================================================
// CONTENT RETRIEVAL
// ============================================================

if (!function_exists('getTOEICPhoto')) {
    /**
     * Get photo for Part 1 questions
     * @param int $id_photo
     * @return array|null
     */
    function getTOEICPhoto($id_photo) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM toeic_photos WHERE id_photo = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id_photo);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getTOEICAudio')) {
    /**
     * Get audio for listening questions
     * @param int $id_audio
     * @return array|null
     */
    function getTOEICAudio($id_audio) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM toeic_audio WHERE id_audio = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id_audio);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getTOEICText')) {
    /**
     * Get reading text for Parts 6 & 7
     * @param int $id_teks
     * @return array|null
     */
    function getTOEICText($id_teks) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM toeic_teks WHERE id_teks = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id_teks);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getTOEICPhotoForAudio')) {
    /**
     * Get photo associated with an audio (for Part 1)
     * @param int $id_audio
     * @return array|null
     */
    function getTOEICPhotoForAudio($id_audio) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT p.* FROM toeic_photos p
            JOIN toeic_audio a ON a.id_photo = p.id_photo
            WHERE a.id_audio = ?
        ");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id_audio);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

// ============================================================
// ANSWER SAVING
// ============================================================

if (!function_exists('saveTOEICUserAnswer')) {
    /**
     * Save user's answer for TOEIC questions
     * @param int $user_id
     * @param string $test_session
     * @param int $question_id
     * @param string $section
     * @param string $part
     * @param string $answer
     */
    function saveTOEICUserAnswer($user_id, $test_session, $question_id, $section, $part, $answer) {
        global $conn;

        $stmt = $conn->prepare("
            UPDATE toeic_test_questions
            SET user_answer = ?
            WHERE user_id = ? AND test_session = ? AND question_id = ? AND section = ? AND part = ?
        ");
        $stmt->bind_param("sissss", $answer, $user_id, $test_session, $question_id, $section, $part);
        $stmt->execute();
    }
}

// ============================================================
// SCORING FUNCTIONS
// ============================================================

if (!function_exists('calculateTOEICListeningScore')) {
    /**
     * Calculate TOEIC Listening scaled score
     * @param int $correct Raw correct answers (0-100)
     * @return int Scaled score (5-495)
     */
    function calculateTOEICListeningScore($correct) {
        global $conn;
        
        $stmt = $conn->prepare("SELECT scaled_score FROM toeic_score_conversion WHERE section = 'listening' AND raw_score = ?");
        $stmt->bind_param("i", $correct);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            return $result['scaled_score'];
        }
        
        // Fallback calculation if conversion table not available
        // Approximate: 5 + (correct * 490 / 100)
        return min(495, max(5, round(5 + ($correct * 490 / 100))));
    }
}

if (!function_exists('calculateTOEICReadingScore')) {
    /**
     * Calculate TOEIC Reading scaled score
     * @param int $correct Raw correct answers (0-100)
     * @return int Scaled score (5-495)
     */
    function calculateTOEICReadingScore($correct) {
        global $conn;
        
        $stmt = $conn->prepare("SELECT scaled_score FROM toeic_score_conversion WHERE section = 'reading' AND raw_score = ?");
        $stmt->bind_param("i", $correct);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            return $result['scaled_score'];
        }
        
        // Fallback calculation
        return min(495, max(5, round(5 + ($correct * 490 / 100))));
    }
}

if (!function_exists('calculateTOEICTotalScore')) {
    /**
     * Calculate total TOEIC score
     * @param int $listening Scaled listening score (5-495)
     * @param int $reading Scaled reading score (5-495)
     * @return int Total score (10-990)
     */
    function calculateTOEICTotalScore($listening, $reading) {
        return $listening + $reading;
    }
}

if (!function_exists('getTOEICScoreLevel')) {
    /**
     * Get proficiency level based on TOEIC total score
     * @param int $score Total score (10-990)
     * @return array [level_name, CEFR_level, color_class]
     */
    function getTOEICScoreLevel($score) {
        if ($score >= 945) return ['Proficient', 'C1', 'success'];
        if ($score >= 785) return ['Advanced', 'B2+', 'primary'];
        if ($score >= 605) return ['Upper Intermediate', 'B2', 'info'];
        if ($score >= 405) return ['Intermediate', 'B1', 'warning'];
        if ($score >= 255) return ['Elementary', 'A2', 'secondary'];
        return ['Novice', 'A1', 'danger'];
    }
}

// ============================================================
// RESULT CALCULATION
// ============================================================

if (!function_exists('calculateTOEICResults')) {
    /**
     * Calculate and save complete TOEIC test results
     * @param int $user_id
     * @param string $test_session
     * @return array Result data
     */
    function calculateTOEICResults($user_id, $test_session) {
        global $conn;

        if (!($conn instanceof mysqli) || !class_exists('ToeicScorer')) {
            return null;
        }

        $scorer = new ToeicScorer($conn);
        $results = $scorer->saveResults($test_session, $user_id);
        $results['level'] = getTOEICScoreLevel($results['total_score']);

        return $results;
    }
}

if (!function_exists('getTOEICTestResults')) {
    /**
     * Get TOEIC test results for a session
     * @param int $user_id
     * @param string $test_session
     * @return array|null
     */
    function getTOEICTestResults($user_id, $test_session) {
        global $conn;

        $stmt = $conn->prepare("SELECT * FROM toeic_test_results WHERE user_id = ? AND test_session = ?");
        $stmt->bind_param("is", $user_id, $test_session);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            $result['level'] = getTOEICScoreLevel($result['total_score']);
        }
        
        return $result;
    }
}

// ============================================================
// SECTION PROGRESS TRACKING
// ============================================================

if (!function_exists('getTOEICSectionProgress')) {
    /**
     * Get section progress for TOEIC test
     * @param string $test_session
     * @param string $section
     * @return array [question_order => answered_bool]
     */
    function getTOEICSectionProgress($test_session, $section) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT tq.question_order, tq.user_answer
            FROM toeic_test_questions tq
            WHERE tq.test_session = ? AND tq.section = ?
            ORDER BY tq.question_order ASC
        ");
        $stmt->bind_param("ss", $test_session, $section);
        $stmt->execute();
        $result = $stmt->get_result();

        $progress = [];
        while ($row = $result->fetch_assoc()) {
            $progress[$row['question_order']] = !empty($row['user_answer']);
        }

        return $progress;
    }
}

if (!function_exists('checkTOEICSectionCompleted')) {
    /**
     * Check if a TOEIC section is completed
     * @param string $test_session
     * @param string $section
     * @return bool
     */
    function checkTOEICSectionCompleted($test_session, $section) {
        if (function_exists('isTestSectionSkipped') && isTestSectionSkipped($_SESSION['user_id'] ?? 0, $test_session, $section)) {
            return true;
        }
        $total = getTOEICTotalQuestions($test_session, $section);
        $progress = getTOEICSectionProgress($test_session, $section);
        $answered = count(array_filter($progress));
        
        return $answered >= $total && $total > 0;
    }
}

// ============================================================
// PART-SPECIFIC HELPERS
// ============================================================

if (!function_exists('getTOEICPartInfo')) {
    /**
     * Get information about a TOEIC part
     * @param string $part
     * @return array
     */
    function getTOEICPartInfo($part) {
        $parts = [
            '1' => [
                'name' => 'Photographs',
                'section' => 'listening',
                'description' => 'Look at the picture and select the statement that best describes it.',
                'questions' => 6,
                'has_photo' => true,
                'options_count' => 4
            ],
            '2' => [
                'name' => 'Question-Response',
                'section' => 'listening',
                'description' => 'Listen to the question and select the best response.',
                'questions' => 25,
                'has_photo' => false,
                'options_count' => 3 // A, B, C only
            ],
            '3' => [
                'name' => 'Conversations',
                'section' => 'listening',
                'description' => 'Listen to the conversation and answer the questions.',
                'questions' => 39,
                'has_photo' => false,
                'options_count' => 4
            ],
            '4' => [
                'name' => 'Talks',
                'section' => 'listening',
                'description' => 'Listen to the talk and answer the questions.',
                'questions' => 30,
                'has_photo' => false,
                'options_count' => 4
            ],
            '5' => [
                'name' => 'Incomplete Sentences',
                'section' => 'reading',
                'description' => 'Choose the word or phrase that best completes the sentence.',
                'questions' => 30,
                'has_text' => false,
                'options_count' => 4
            ],
            '6' => [
                'name' => 'Text Completion',
                'section' => 'reading',
                'description' => 'Read the text and choose the best word or phrase for each blank.',
                'questions' => 16,
                'has_text' => true,
                'options_count' => 4
            ],
            '7' => [
                'name' => 'Reading Comprehension',
                'section' => 'reading',
                'description' => 'Read the passage(s) and answer the questions.',
                'questions' => 54,
                'has_text' => true,
                'options_count' => 4
            ]
        ];
        
        return $parts[$part] ?? null;
    }
}

if (!function_exists('getTOEICCurrentPart')) {
    /**
     * Get the current part based on question order
     * @param string $test_session
     * @param string $section
     * @param int $question_order
     * @return string
     */
    function getTOEICCurrentPart($test_session, $section, $question_order) {
        global $conn;
        
        $stmt = $conn->prepare("
            SELECT part FROM toeic_test_questions 
            WHERE test_session = ? AND section = ? AND question_order = ?
        ");
        $stmt->bind_param("ssi", $test_session, $section, $question_order);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['part'] ?? null;
    }
}

if (!function_exists('isTOEICNewPart')) {
    /**
     * Check if we're starting a new part
     * @param string $test_session
     * @param string $section
     * @param int $question_order
     * @return bool
     */
    function isTOEICNewPart($test_session, $section, $question_order) {
        if ($question_order == 1) return true;
        
        $current_part = getTOEICCurrentPart($test_session, $section, $question_order);
        $prev_part = getTOEICCurrentPart($test_session, $section, $question_order - 1);
        
        return $current_part !== $prev_part;
    }
}

if (!function_exists('isTOEICNewAudio')) {
    /**
     * Check if current question has a different audio than previous (for Listening)
     * @param string $test_session
     * @param int $question_order
     * @return bool
     */
    function isTOEICNewAudio($test_session, $question_order) {
        global $conn;
        
        if ($question_order == 1) return true;
        
        // Get current question's audio
        $current = getTOEICRandomizedQuestion($test_session, 'listening', $question_order);
        if (!$current || !isset($current['id_audio'])) return true;
        
        // Get previous question's audio
        $prev = getTOEICRandomizedQuestion($test_session, 'listening', $question_order - 1);
        if (!$prev || !isset($prev['id_audio'])) return true;
        
        return $current['id_audio'] != $prev['id_audio'];
    }
}

if (!function_exists('getTOEICQuestionsForAudio')) {
    /**
     * Get all questions associated with the current audio
     * @param string $test_session
     * @param int $question_order
     * @return array
     */
    function getTOEICQuestionsForAudio($test_session, $question_order) {
        global $conn;
        
        // Get current question's audio ID
        $current = getTOEICRandomizedQuestion($test_session, 'listening', $question_order);
        if (!$current || !isset($current['id_audio'])) return [];
        
        $stmt = $conn->prepare("
            SELECT sl.*, tq.question_order
            FROM toeic_test_questions tq
            JOIN toeic_soal_listening sl ON tq.question_id = sl.id_soal
            WHERE tq.test_session = ? AND tq.section = 'listening' AND sl.id_audio = ?
            ORDER BY tq.question_order
        ");
        $stmt->bind_param("si", $test_session, $current['id_audio']);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('getTOEICQuestionsForText')) {
    /**
     * Get all questions associated with the current text (for Parts 6 & 7)
     * @param string $test_session
     * @param int $question_order
     * @return array
     */
    function getTOEICQuestionsForText($test_session, $question_order) {
        global $conn;
        
        // Get current question's text ID
        $current = getTOEICRandomizedQuestion($test_session, 'reading', $question_order);
        if (!$current || !isset($current['id_teks']) || !$current['id_teks']) return [];
        
        $stmt = $conn->prepare("
            SELECT sr.*, tq.question_order
            FROM toeic_test_questions tq
            JOIN toeic_soal_reading sr ON tq.question_id = sr.id_soal
            WHERE tq.test_session = ? AND tq.section = 'reading' AND sr.id_teks = ?
            ORDER BY tq.question_order
        ");
        $stmt->bind_param("si", $test_session, $current['id_teks']);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// ============================================================
// PART STATISTICS
// ============================================================

if (!function_exists('getTOEICPartStatistics')) {
    /**
     * Get statistics by part for a completed test
     * @param int $user_id
     * @param string $test_session
     * @return array
     */
    function getTOEICPartStatistics($user_id, $test_session) {
        global $conn;
        
        $stats = [];
        
        // Listening parts (1-4)
        for ($part = 1; $part <= 4; $part++) {
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN tq.user_answer IS NOT NULL AND tq.user_answer != '' AND UPPER(TRIM(tq.user_answer)) = UPPER(TRIM(sl.jawaban_benar)) THEN 1 ELSE 0 END) as correct
                FROM toeic_test_questions tq
                JOIN toeic_soal_listening sl ON tq.question_id = sl.id_soal
                WHERE tq.user_id = ? AND tq.test_session = ? AND tq.part = ?
            ");
            $part_str = (string)$part;
            $stmt->bind_param("iss", $user_id, $test_session, $part_str);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            $stats["part_$part"] = [
                'name' => getTOEICPartInfo($part_str)['name'],
                'total' => $result['total'] ?? 0,
                'correct' => $result['correct'] ?? 0,
                'percentage' => $result['total'] > 0 ? round(($result['correct'] / $result['total']) * 100) : 0
            ];
        }
        
        // Reading parts (5-7)
        for ($part = 5; $part <= 7; $part++) {
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN tq.user_answer IS NOT NULL AND tq.user_answer != '' AND UPPER(TRIM(tq.user_answer)) = UPPER(TRIM(sr.jawaban_benar)) THEN 1 ELSE 0 END) as correct
                FROM toeic_test_questions tq
                JOIN toeic_soal_reading sr ON tq.question_id = sr.id_soal
                WHERE tq.user_id = ? AND tq.test_session = ? AND tq.part = ?
            ");
            $part_str = (string)$part;
            $stmt->bind_param("iss", $user_id, $test_session, $part_str);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            $stats["part_$part"] = [
                'name' => getTOEICPartInfo($part_str)['name'],
                'total' => $result['total'] ?? 0,
                'correct' => $result['correct'] ?? 0,
                'percentage' => $result['total'] > 0 ? round(($result['correct'] / $result['total']) * 100) : 0
            ];
        }
        
        return $stats;
    }
}

if (!function_exists('ensureTOEICSessionModeColumns')) {
    function ensureTOEICSessionModeColumns($conn) {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $practiceCol = $conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'practice_mode'");
            if (!$practiceCol || $practiceCol->num_rows === 0) {
                $conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN practice_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            }

            $partCol = $conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'target_part'");
            if (!$partCol || $partCol->num_rows === 0) {
                $conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN target_part VARCHAR(2) NULL DEFAULT NULL AFTER practice_mode");
            }
        } catch (\Throwable $e) {
            error_log('Failed ensuring TOEIC session mode columns: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getTOEICPracticeConfig')) {
    function getTOEICPracticeConfig($part) {
        $configs = [
            '1' => ['label' => 'Part 1 Practice', 'section' => 'listening', 'minutes' => 5],
            '2' => ['label' => 'Part 2 Practice', 'section' => 'listening', 'minutes' => 10],
            '3' => ['label' => 'Part 3 Practice', 'section' => 'listening', 'minutes' => 18],
            '4' => ['label' => 'Part 4 Practice', 'section' => 'listening', 'minutes' => 12],
            '5' => ['label' => 'Part 5 Practice', 'section' => 'reading', 'minutes' => 12],
            '6' => ['label' => 'Part 6 Practice', 'section' => 'reading', 'minutes' => 8],
            '7' => ['label' => 'Part 7 Practice', 'section' => 'reading', 'minutes' => 30],
        ];
        return $configs[(string)$part] ?? null;
    }
}

if (!function_exists('getTOEICSessionInfo')) {
    function getTOEICSessionInfo($user_id, $test_session) {
        global $conn;
        ensureTOEICSessionModeColumns($conn);
        $stmt = $conn->prepare("SELECT * FROM toeic_test_sessions WHERE user_id = ? AND test_session = ?");
        $stmt->bind_param("is", $user_id, $test_session);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $session;
    }
}

if (!function_exists('getTOEICPracticeSummary')) {
    function getTOEICPracticeSummary($user_id, $test_session) {
        global $conn;
        $session = getTOEICSessionInfo($user_id, $test_session);
        if (!$session || empty($session['practice_mode']) || empty($session['target_part'])) {
            return null;
        }

        $part = (string)$session['target_part'];
        $section = ((int)$part <= 4) ? 'listening' : 'reading';
        $sourceTable = $section === 'listening' ? 'toeic_soal_listening' : 'toeic_soal_reading';

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN tq.user_answer IS NOT NULL AND tq.user_answer != '' AND UPPER(TRIM(tq.user_answer)) = UPPER(TRIM(src.jawaban_benar)) THEN 1 ELSE 0 END) AS correct
            FROM toeic_test_questions tq
            JOIN {$sourceTable} src ON tq.question_id = src.id_soal
            WHERE tq.user_id = ? AND tq.test_session = ? AND tq.part = ?
        ");
        $stmt->bind_param("iss", $user_id, $test_session, $part);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['total' => 0, 'correct' => 0];
        $stmt->close();

        $partInfo = getTOEICPartInfo($part);
        $total = (int)($row['total'] ?? 0);
        $correct = (int)($row['correct'] ?? 0);
        $accuracy = $total > 0 ? round(($correct / $total) * 100, 1) : 0.0;

        return [
            'session' => $session,
            'part' => $part,
            'section' => $section,
            'part_info' => $partInfo,
            'total' => $total,
            'correct' => $correct,
            'incorrect' => max(0, $total - $correct),
            'accuracy' => $accuracy,
        ];
    }
}
?>
