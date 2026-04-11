<?php
/**
 * TOEIC Scoring System
 * Binary scoring for multiple choice questions (all Parts 1-7)
 * Scaled scores: 5-495 per section, 10-990 total
 */

require_once __DIR__ . '/config.php';

class ToeicScorer {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Score all questions for a section.
     * Updates is_correct in toeic_test_questions for each question.
     *
     * @param string $testSession
     * @param string $section 'listening' or 'reading'
     * @return array ['raw' => int, 'total' => int, 'percentage' => float]
     */
    public function scoreSection($testSession, $section) {
        $sourceTable = ($section === 'listening') ? 'toeic_soal_listening' : 'toeic_soal_reading';
        $answerCol = 'jawaban_benar'; // correct answer column in source table

        // Fetch all questions for this section with their correct answers
        $stmt = $this->conn->prepare("
            SELECT tq.id as tq_id, tq.question_id, tq.user_answer, s.$answerCol as correct_answer
            FROM toeic_test_questions tq
            JOIN $sourceTable s ON tq.question_id = s.id_soal
            WHERE tq.test_session = ? AND tq.section = ?
        ");
        $stmt->bind_param("ss", $testSession, $section);
        $stmt->execute();
        $result = $stmt->get_result();

        $raw = 0;
        $total = 0;

        while ($row = $result->fetch_assoc()) {
            $total++;
            $isCorrect = null;

            if (!empty($row['user_answer']) && strtoupper(trim($row['user_answer'])) === strtoupper(trim($row['correct_answer']))) {
                $isCorrect = 1;
                $raw++;
            } else {
                $isCorrect = 0;
            }

            // Update is_correct in toeic_test_questions
            $updateStmt = $this->conn->prepare("
                UPDATE toeic_test_questions
                SET is_correct = ?
                WHERE test_session = ? AND section = ? AND question_id = ?
            ");
            $updateStmt->bind_param("issi", $isCorrect, $testSession, $section, $row['question_id']);
            $updateStmt->execute();
            $updateStmt->close();
        }
        $stmt->close();

        $percentage = $total > 0 ? ($raw / $total) * 100 : 0;

        return [
            'raw' => $raw,
            'total' => $total,
            'percentage' => round($percentage, 1)
        ];
    }

    /**
     * Get scaled score from raw score using conversion table.
     *
     * @param int $raw Raw correct count (0-100)
     * @param string $section 'listening' or 'reading'
     * @return int Scaled score (5-495)
     */
    public function getScaledScore($raw, $section) {
        $stmt = $this->conn->prepare("SELECT scaled_score FROM toeic_score_conversion WHERE section = ? AND raw_score = ?");
        $stmt->bind_param("si", $section, $raw);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $scaled = $result->fetch_assoc()['scaled_score'];
            $stmt->close();
            return (int)$scaled;
        }
        $stmt->close();

        // Fallback: formula-based calculation
        return (int)min(495, max(5, round(5 + ($raw * 490 / 100))));
    }

    /**
     * Get CEFR level from total score.
     *
     * @param int $totalScore Total score (10-990)
     * @return string CEFR level (A1, A2, B1, B2, B2+, C1)
     */
    public function getCEFRLevel($totalScore) {
        if ($totalScore >= 945) return 'C1';
        if ($totalScore >= 785) return 'B2+';
        if ($totalScore >= 605) return 'B2';
        if ($totalScore >= 405) return 'B1';
        if ($totalScore >= 255) return 'A2';
        return 'A1';
    }

    /**
     * Save final results to toeic_test_results and update toeic_test_sessions.
     *
     * @param string $testSession
     * @param int $userId
     * @param bool $persistReport Whether to persist toeic_test_results row
     * @return array Results data
     */
    public function saveResults($testSession, $userId, $persistReport = true) {
        // Get raw scores from scored questions
        $listeningRaw = $this->getRawScore($testSession, 'listening');
        $readingRaw = $this->getRawScore($testSession, 'reading');

        $listeningScaled = $this->getScaledScore($listeningRaw['raw'], 'listening');
        $readingScaled = $this->getScaledScore($readingRaw['raw'], 'reading');
        $totalScore = $listeningScaled + $readingScaled;
        $cefrLevel = $this->getCEFRLevel($totalScore);

        if ($persistReport) {
            $stmt = $this->conn->prepare("
                INSERT INTO toeic_test_results
                (test_session, user_id, listening_raw, listening_scaled, reading_raw, reading_scaled, total_score, cefr_level, completed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    listening_raw = VALUES(listening_raw),
                    listening_scaled = VALUES(listening_scaled),
                    reading_raw = VALUES(reading_raw),
                    reading_scaled = VALUES(reading_scaled),
                    total_score = VALUES(total_score),
                    cefr_level = VALUES(cefr_level),
                    completed_at = NOW()
            ");
            $stmt->bind_param("siiiiiis",
                $testSession, $userId,
                $listeningRaw['raw'], $listeningScaled,
                $readingRaw['raw'], $readingScaled,
                $totalScore, $cefrLevel
            );
            $stmt->execute();
            $stmt->close();
        }

        // Update toeic_test_sessions
        $updateStmt = $this->conn->prepare("
            UPDATE toeic_test_sessions
            SET status = 'completed',
                completed_at = NOW(),
                current_section = 'reading',
                listening_raw = ?,
                listening_scaled = ?,
                reading_raw = ?,
                reading_scaled = ?,
                total_score = ?,
                cefr_level = ?
            WHERE test_session = ?
        ");
        $updateStmt->bind_param("iiiiiss",
            $listeningRaw['raw'], $listeningScaled,
            $readingRaw['raw'], $readingScaled,
            $totalScore, $cefrLevel, $testSession
        );
        $updateStmt->execute();
        $updateStmt->close();

        return [
            'listening_raw' => $listeningRaw['raw'],
            'listening_scaled' => $listeningScaled,
            'reading_raw' => $readingRaw['raw'],
            'reading_scaled' => $readingScaled,
            'total_score' => $totalScore,
            'cefr_level' => $cefrLevel
        ];
    }

    /**
     * Get raw score for a section from toeic_test_questions.
     *
     * @param string $testSession
     * @param string $section
     * @return array ['raw' => int, 'total' => int]
     */
    public function getRawScore($testSession, $section) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total, SUM(is_correct) as correct
            FROM toeic_test_questions
            WHERE test_session = ? AND section = ?
        ");
        $stmt->bind_param("ss", $testSession, $section);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'raw' => (int)($result['correct'] ?? 0),
            'total' => (int)($result['total'] ?? 0)
        ];
    }

    /**
     * Score a single section and save intermediate results to toeic_test_sessions.
     * Called when a section is submitted (not when test is fully completed).
     *
     * @param string $testSession
     * @param string $section
     * @return array Score data
     */
    public function scoreAndSaveSection($testSession, $section) {
        $scoreData = $this->scoreSection($testSession, $section);
        $scaled = $this->getScaledScore($scoreData['raw'], $section);

        // Update toeic_test_sessions with section score
        $colRaw = ($section === 'listening') ? 'listening_raw' : 'reading_raw';
        $colScaled = ($section === 'listening') ? 'listening_scaled' : 'reading_scaled';

        $stmt = $this->conn->prepare("
            UPDATE toeic_test_sessions
            SET $colRaw = ?, $colScaled = ?
            WHERE test_session = ?
        ");
        $stmt->bind_param("iis", $scoreData['raw'], $scaled, $testSession);
        $stmt->execute();
        $stmt->close();

        return [
            'raw' => $scoreData['raw'],
            'total' => $scoreData['total'],
            'percentage' => $scoreData['percentage'],
            'scaled' => $scaled
        ];
    }
}
