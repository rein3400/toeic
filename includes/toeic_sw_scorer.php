<?php
/**
 * TOEIC Speaking & Writing scorer.
 */

require_once __DIR__ . '/toeic_sw_helper.php';
require_once __DIR__ . '/toeic_sw_subjective_scorer.php';

class ToeicSwScorer {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        ensureToeicSwSchema($this->conn);
    }

    public function scoreSection(string $testSession, string $section): array {
        if (!in_array($section, getToeicSwSectionOrder(), true)) {
            throw new RuntimeException('Invalid TOEIC SW section: ' . $section);
        }

        $questions = getToeicSwQuestionsForSection($this->conn, $testSession, $section);
        if (empty($questions)) {
            throw new RuntimeException('No TOEIC SW questions found for section: ' . $section);
        }

        $sum = 0.0;
        $total = 0;
        foreach ($questions as $question) {
            $score = scoreToeicSwSubjectiveQuestion($this->conn, $question);
            $score = toeicSwClamp01((float)$score);
            $sum += $score;
            $total++;

            $stmt = $this->conn->prepare("UPDATE toeic_sw_test_questions SET is_correct = ? WHERE id = ?");
            $rowId = (int)$question['id'];
            $stmt->bind_param("di", $score, $rowId);
            $stmt->execute();
            $stmt->close();
        }

        $average = $total > 0 ? $sum / $total : 0.0;
        $raw = round($sum * 30, 2);
        $scaled = toeicSwScaleSectionScore($average);

        $rawColumn = $section === 'speaking' ? 'speaking_raw' : 'writing_raw';
        $scaledColumn = $section === 'speaking' ? 'speaking_scaled' : 'writing_scaled';
        $stmt = $this->conn->prepare("UPDATE toeic_sw_test_sessions SET {$rawColumn} = ?, {$scaledColumn} = ? WHERE test_session = ?");
        $stmt->bind_param("dis", $raw, $scaled, $testSession);
        $stmt->execute();
        $stmt->close();

        return [
            'raw' => $raw,
            'total' => $total,
            'average_normalized' => round($average, 4),
            'scaled' => $scaled,
        ];
    }

    public function saveResults(string $testSession, int $userId): array {
        $speaking = $this->getSectionScore($testSession, 'speaking');
        $writing = $this->getSectionScore($testSession, 'writing');
        $totalScore = (int)$speaking['scaled'] + (int)$writing['scaled'];
        $level = getToeicSwLevel($totalScore);

        $stmt = $this->conn->prepare("SELECT package_number FROM toeic_sw_test_sessions WHERE test_session = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("si", $testSession, $userId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $packageNumber = (int)($session['package_number'] ?? 0);

        $stmt = $this->conn->prepare("
            INSERT INTO toeic_sw_test_results
            (test_session, user_id, package_number, speaking_raw, speaking_scaled, writing_raw, writing_scaled, total_score, cefr_level, completed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                speaking_raw = VALUES(speaking_raw),
                speaking_scaled = VALUES(speaking_scaled),
                writing_raw = VALUES(writing_raw),
                writing_scaled = VALUES(writing_scaled),
                total_score = VALUES(total_score),
                cefr_level = VALUES(cefr_level),
                completed_at = NOW()
        ");
        $cefr = $level[1];
        $stmt->bind_param(
            "siididiis",
            $testSession,
            $userId,
            $packageNumber,
            $speaking['raw'],
            $speaking['scaled'],
            $writing['raw'],
            $writing['scaled'],
            $totalScore,
            $cefr
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare("
            UPDATE toeic_sw_test_sessions
            SET status = 'completed',
                completed_at = NOW(),
                current_section = 'writing',
                speaking_raw = ?,
                speaking_scaled = ?,
                writing_raw = ?,
                writing_scaled = ?,
                total_score = ?,
                cefr_level = ?
            WHERE test_session = ? AND user_id = ?
        ");
        $stmt->bind_param(
            "didiissi",
            $speaking['raw'],
            $speaking['scaled'],
            $writing['raw'],
            $writing['scaled'],
            $totalScore,
            $cefr,
            $testSession,
            $userId
        );
        $stmt->execute();
        $stmt->close();

        return [
            'speaking_raw' => $speaking['raw'],
            'speaking_scaled' => $speaking['scaled'],
            'writing_raw' => $writing['raw'],
            'writing_scaled' => $writing['scaled'],
            'total_score' => $totalScore,
            'cefr_level' => $cefr,
            'level' => $level,
        ];
    }

    private function getSectionScore(string $testSession, string $section): array {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(COALESCE(is_correct, 0)), 0) AS normalized_sum
            FROM toeic_sw_test_questions
            WHERE test_session = ? AND section = ?
        ");
        $stmt->bind_param("ss", $testSession, $section);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $total = (int)($row['total'] ?? 0);
        $sum = (float)($row['normalized_sum'] ?? 0);
        $average = $total > 0 ? $sum / $total : 0.0;

        return [
            'raw' => round($sum * 30, 2),
            'scaled' => toeicSwScaleSectionScore($average),
            'total' => $total,
            'average_normalized' => round($average, 4),
        ];
    }
}
?>
