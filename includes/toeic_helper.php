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
            $table = (string)$meta['table'];
            if ($table === 'toeic_soal_listening') {
                $joins = '';
                $conditions = ['sl.part = ?'];

                if (in_array($part, ['1', '2', '3', '4'], true)) {
                    $joins .= ' JOIN toeic_audio ta ON ta.id_audio = sl.id_audio';
                    $conditions[] = 'sl.id_audio IS NOT NULL';
                    $conditions[] = "TRIM(COALESCE(ta.file_path, '')) <> ''";
                }

                if ($part === '1') {
                    $joins .= ' JOIN toeic_photos tp ON tp.id_photo = ta.id_photo';
                    $conditions[] = 'ta.id_photo IS NOT NULL';
                    $conditions[] = "TRIM(COALESCE(tp.file_path, '')) <> ''";
                }

                $sql = "SELECT COUNT(*) AS total FROM toeic_soal_listening sl{$joins} WHERE " . implode(' AND ', $conditions);
            } else {
                $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE part = ?";
            }

            $stmt = $conn->prepare($sql);
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

if (!function_exists('generateTOEICRandomizedQuestions')) {
    /**
     * Backward-compatible wrapper. Builds a session via the modern
     * ToeicTestBuilder (in includes/toeic_test_builder.php), which is now
     * the single source of truth for randomised TOEIC question selection.
     *
     * @param string $test_session
     * @param int    $user_id
     */
    function generateTOEICRandomizedQuestions($test_session, $user_id) {
        global $conn;

        if (!($conn instanceof mysqli)) {
            throw new RuntimeException('generateTOEICRandomizedQuestions requires an active mysqli $conn.');
        }

        require_once __DIR__ . '/toeic_test_builder.php';
        $builder = new ToeicTestBuilder($conn);
        $builder->createSession($test_session, (int)$user_id);
        $builder->buildTest($test_session, (int)$user_id);
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

        // Prefer snapshot columns from toeic_test_questions so the response
        // is frozen at build time. Fall back to the source row if a legacy
        // session predates the snapshot migration.
        $audioColumn = $section === 'listening' ? 's.id_audio' : 'NULL';
        $textColumn = $section === 'reading' ? 's.id_teks' : 'NULL';
        $sql = "
            SELECT
                tq.id as tq_id,
                tq.question_id,
                tq.question_order,
                tq.part,
                tq.section,
                tq.question_type,
                tq.snap_jawaban_benar,
                tq.snap_pertanyaan,
                tq.snap_opsi_a,
                tq.snap_opsi_b,
                tq.snap_opsi_c,
                tq.snap_opsi_d,
                tq.original_question_id,
                tq.stimulus_group_id,
                tq.group_order,
                {$audioColumn} AS id_audio,
                {$textColumn} AS id_teks,
                s.jawaban_benar,
                s.pertanyaan,
                s.opsi_a,
                s.opsi_b,
                s.opsi_c,
                s.opsi_d,
                s.question_type as source_question_type
            FROM toeic_test_questions tq
            LEFT JOIN $table s ON tq.question_id = s.id_soal
            WHERE tq.test_session = ? AND tq.section = ? AND tq.question_order = ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;

        $stmt->bind_param("ssi", $test_session, $section, $order);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }

        // Prefer snapshot when populated; otherwise source row.
        $row['pertanyaan'] = $row['snap_pertanyaan'] !== null ? $row['snap_pertanyaan'] : $row['pertanyaan'];
        $row['opsi_a'] = $row['snap_opsi_a'] !== null ? $row['snap_opsi_a'] : $row['opsi_a'];
        $row['opsi_b'] = $row['snap_opsi_b'] !== null ? $row['snap_opsi_b'] : $row['opsi_b'];
        $row['opsi_c'] = $row['snap_opsi_c'] !== null ? $row['snap_opsi_c'] : $row['opsi_c'];
        $row['opsi_d'] = $row['snap_opsi_d'] !== null ? $row['snap_opsi_d'] : $row['opsi_d'];
        $row['jawaban_benar'] = $row['snap_jawaban_benar'] !== null ? $row['snap_jawaban_benar'] : $row['jawaban_benar'];

        return $row;
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

            $sourceCol = $conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'checkout_source'");
            if (!$sourceCol || $sourceCol->num_rows === 0) {
                $conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN checkout_source VARCHAR(40) NULL DEFAULT NULL AFTER target_part");
            }

            $referenceCol = $conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'checkout_reference'");
            if (!$referenceCol || $referenceCol->num_rows === 0) {
                $conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN checkout_reference VARCHAR(120) NULL DEFAULT NULL AFTER checkout_source");
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
