<?php
/**
 * TOEIC Listening & Reading Test Builder
 *
 * Builds a realistic TOEIC simulator session using the assigned-question model
 * in toeic_test_questions. Runtime state lives in toeic_test_sessions; source
 * content stays in toeic_soal_listening, toeic_soal_reading, toeic_audio, and
 * toeic_teks.
 */

require_once __DIR__ . '/config.php';

class ToeicTestBuilder {
    private mysqli $conn;
    private int $userId = 0;
    private const FREE_TRIAL_QUESTION_LIMIT = 15;

    private const PART_TARGETS = [
        '1' => ['section' => 'listening', 'questions' => 6,  'grouped' => false],
        '2' => ['section' => 'listening', 'questions' => 25, 'grouped' => false],
        '3' => ['section' => 'listening', 'questions' => 39, 'grouped' => true,  'group_column' => 'id_audio'],
        '4' => ['section' => 'listening', 'questions' => 30, 'grouped' => true,  'group_column' => 'id_audio'],
        '5' => ['section' => 'reading',   'questions' => 30, 'grouped' => false],
        '6' => ['section' => 'reading',   'questions' => 16, 'grouped' => true,  'group_column' => 'id_teks'],
        '7' => ['section' => 'reading',   'questions' => 54, 'grouped' => true,  'group_column' => 'id_teks'],
    ];

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function createSession($testSession, $userId, array $options = []): bool {
        $this->ensureModeColumns();
        $this->ensureQuestionUniqueConstraint();

        $currentSection = $options['current_section'] ?? 'listening';
        $practiceMode = !empty($options['practice_mode']) ? 1 : 0;
        $targetPart = isset($options['target_part']) ? (string)$options['target_part'] : null;
        $checkoutSource = isset($options['checkout_source']) ? (string)$options['checkout_source'] : null;
        $checkoutReference = isset($options['checkout_reference']) ? (string)$options['checkout_reference'] : null;

        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO toeic_test_sessions
            (test_session, user_id, current_section, status, practice_mode, target_part, checkout_source, checkout_reference)
            VALUES (?, ?, ?, 'active', ?, ?, ?, ?)
        ");
        $stmt->bind_param("sisisss", $testSession, $userId, $currentSection, $practiceMode, $targetPart, $checkoutSource, $checkoutReference);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function buildTest($testSession, $userId, array $options = []): bool {
        $this->userId = (int)$userId;
        $this->clearExistingAssignments($testSession);

        $targetPart = isset($options['target_part']) ? (string)$options['target_part'] : null;
        $targetSection = $options['target_section'] ?? null;

        if (!empty($options['free_trial'])) {
            $this->assignFreeTrialQuestions($testSession);
            return true;
        }

        foreach (['listening', 'reading'] as $sectionName) {
            if ($targetSection && $targetSection !== $sectionName) {
                continue;
            }
            $order = 1;
            foreach (self::PART_TARGETS as $partKey => $config) {
                $part = (string)$partKey;
                if ($config['section'] !== $sectionName) {
                    continue;
                }
                if ($targetPart && $targetPart !== $part) {
                    continue;
                }

                $table = $config['section'] === 'listening' ? 'toeic_soal_listening' : 'toeic_soal_reading';
                if ($config['grouped']) {
                    $order = $this->assignGroupedPart(
                        $testSession,
                        $table,
                        $config['section'],
                        $part,
                        $config['group_column'],
                        $config['questions'],
                        $order
                    );
                } else {
                    $order = $this->assignIndividualPart(
                        $testSession,
                        $table,
                        $config['section'],
                        $part,
                        $config['questions'],
                        $order
                    );
                }
            }
        }

        return true;
    }

    private function ensureModeColumns(): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $hasPracticeMode = $this->conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'practice_mode'");
            if (!$hasPracticeMode || $hasPracticeMode->num_rows === 0) {
                $this->conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN practice_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            }

            $hasTargetPart = $this->conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'target_part'");
            if (!$hasTargetPart || $hasTargetPart->num_rows === 0) {
                $this->conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN target_part VARCHAR(2) NULL DEFAULT NULL AFTER practice_mode");
            }

            $hasCheckoutSource = $this->conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'checkout_source'");
            if (!$hasCheckoutSource || $hasCheckoutSource->num_rows === 0) {
                $this->conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN checkout_source VARCHAR(40) NULL DEFAULT NULL AFTER target_part");
            }

            $hasCheckoutReference = $this->conn->query("SHOW COLUMNS FROM toeic_test_sessions LIKE 'checkout_reference'");
            if (!$hasCheckoutReference || $hasCheckoutReference->num_rows === 0) {
                $this->conn->query("ALTER TABLE toeic_test_sessions ADD COLUMN checkout_reference VARCHAR(120) NULL DEFAULT NULL AFTER checkout_source");
            }
        } catch (\Throwable $e) {
            error_log('TOEIC builder column check failed: ' . $e->getMessage());
        }
    }

    private function assignFreeTrialQuestions(string $testSession): void {
        $targets = [
            ['table' => 'toeic_soal_listening', 'section' => 'listening', 'part' => '1', 'questions' => 4],
            ['table' => 'toeic_soal_listening', 'section' => 'listening', 'part' => '2', 'questions' => 4],
            ['table' => 'toeic_soal_reading', 'section' => 'reading', 'part' => '5', 'questions' => 7],
        ];

        $assigned = 0;
        $sectionOrders = ['listening' => 1, 'reading' => 1];
        foreach ($targets as $target) {
            $remaining = self::FREE_TRIAL_QUESTION_LIMIT - $assigned;
            if ($remaining <= 0) {
                break;
            }

            $limit = min((int)$target['questions'], $remaining);
            $rows = $this->pickIndividualRows($target['table'], $target['part'], $limit);
            foreach ($rows as $row) {
                $section = $target['section'];
                $part = $target['part'];
                $groupId = $this->normalizeGroupId($part, $row, null, 0);
                $this->insertAssignment($testSession, $section, $part, $row, $sectionOrders[$section]++, $groupId, 1);
                $assigned++;
            }
        }

        if ($assigned < self::FREE_TRIAL_QUESTION_LIMIT) {
            error_log("TOEIC builder warning: free trial assigned only {$assigned} of " . self::FREE_TRIAL_QUESTION_LIMIT . ' questions.');
        }
    }

    private function ensureQuestionUniqueConstraint(): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $result = $this->conn->query("SHOW INDEX FROM toeic_test_questions WHERE Key_name = 'uniq_session_question'");
            if (!$result) {
                return;
            }

            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[(int)$row['Seq_in_index']] = $row['Column_name'];
            }
            ksort($columns);
            $columnList = array_values($columns);

            if ($columnList === ['test_session', 'question_id']) {
                $this->conn->query("ALTER TABLE toeic_test_questions DROP INDEX uniq_session_question");
                $this->conn->query("ALTER TABLE toeic_test_questions ADD UNIQUE KEY uniq_session_question (test_session, section, question_id)");
            }
        } catch (\Throwable $e) {
            error_log('TOEIC builder unique constraint check failed: ' . $e->getMessage());
        }
    }

    private function clearExistingAssignments(string $testSession): void {
        $stmt = $this->conn->prepare("DELETE FROM toeic_test_questions WHERE test_session = ?");
        $stmt->bind_param("s", $testSession);
        $stmt->execute();
        $stmt->close();
    }

    private function assignIndividualPart(
        string $testSession,
        string $table,
        string $section,
        string $part,
        int $targetCount,
        int $startOrder
    ): int {
        $rows = $this->pickIndividualRows($table, $part, $targetCount);
        $order = $startOrder;

        foreach ($rows as $row) {
            $groupId = $this->normalizeGroupId($part, $row, null, 0);
            $this->insertAssignment($testSession, $section, $part, $row, $order++, $groupId, 1);
        }

        if (count($rows) < $targetCount) {
            error_log("TOEIC builder warning: part {$part} assigned only " . count($rows) . " of {$targetCount} questions.");
        }

        return $order;
    }

    private function assignGroupedPart(
        string $testSession,
        string $table,
        string $section,
        string $part,
        string $groupColumn,
        int $targetCount,
        int $startOrder
    ): int {
        $rows = [];
        $assignedQuestionIds = [];
        $assignedCount = 0;

        foreach ($this->pickGroupedRows($table, $part, $groupColumn) as $groupId => $groupRows) {
            $groupRows = array_values(array_filter($groupRows, function ($row) use ($assignedQuestionIds) {
                return !in_array((int)$row['id_soal'], $assignedQuestionIds, true);
            }));

            if (empty($groupRows)) {
                continue;
            }

            if ($assignedCount + count($groupRows) > $targetCount && $assignedCount > 0) {
                continue;
            }

            foreach ($groupRows as $row) {
                $assignedQuestionIds[] = (int)$row['id_soal'];
                $rows[] = [$groupId, $row];
                $assignedCount++;
            }

            if ($assignedCount >= $targetCount) {
                break;
            }
        }

        if ($assignedCount < $targetCount) {
            $fallbackRows = $this->pickIndividualRows($table, $part, $targetCount - $assignedCount, $assignedQuestionIds);
            $fallbackGroupIndex = 9000;
            foreach ($fallbackRows as $row) {
                $rows[] = [$this->normalizeGroupId($part, $row, null, $fallbackGroupIndex++), $row];
                $assignedCount++;
            }
        }

        $order = $startOrder;
        $groupOrderMap = [];
        foreach ($rows as [$groupId, $row]) {
            if (!isset($groupOrderMap[$groupId])) {
                $groupOrderMap[$groupId] = 0;
            }
            $groupOrderMap[$groupId]++;
            $this->insertAssignment($testSession, $section, $part, $row, $order++, $groupId, $groupOrderMap[$groupId]);
        }

        if ($assignedCount < $targetCount) {
            error_log("TOEIC builder warning: grouped part {$part} assigned only {$assignedCount} of {$targetCount} questions.");
        }

        return $order;
    }

    private function pickIndividualRows(string $table, string $part, int $limit, array $excludeQuestionIds = []): array {
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->fetchRandomQuestionRows($table, $part, $limit, true, $excludeQuestionIds);
        if (count($rows) < $limit) {
            $already = array_merge($excludeQuestionIds, array_map(fn($row) => (int)$row['id_soal'], $rows));
            $rows = array_merge($rows, $this->fetchRandomQuestionRows($table, $part, $limit - count($rows), false, $already));
        }

        return $rows;
    }

    private function fetchRandomQuestionRows(string $table, string $part, int $limit, bool $preferUnseen, array $excludeQuestionIds = []): array {
        $excludeIds = $excludeQuestionIds;
        if ($preferUnseen) {
            $excludeIds = array_merge($excludeIds, $this->getSeenQuestionIds($table, $part));
        }
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));

        $sql = "SELECT * FROM {$table} WHERE part = ?";
        if (!empty($excludeIds)) {
            $sql .= " AND id_soal NOT IN (" . implode(',', $excludeIds) . ")";
        }
        $sql .= " ORDER BY RAND() LIMIT " . (int)$limit;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $part);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    private function pickGroupedRows(string $table, string $part, string $groupColumn): array {
        $groupIds = $this->fetchRandomGroupIds($table, $part, $groupColumn, true);
        if (empty($groupIds)) {
            $groupIds = $this->fetchRandomGroupIds($table, $part, $groupColumn, false);
        }

        $groups = [];
        foreach ($groupIds as $groupId) {
            $rows = $this->fetchRowsForGroup($table, $part, $groupColumn, $groupId);
            if (!empty($rows)) {
                $groups[$groupId] = $rows;
            }
        }

        return $groups;
    }

    private function fetchRandomGroupIds(string $table, string $part, string $groupColumn, bool $preferUnseen): array {
        $excludeGroupIds = $preferUnseen ? $this->getSeenGroupIds($table, $part, $groupColumn) : [];
        $sql = "SELECT DISTINCT {$groupColumn} AS group_id
                FROM {$table}
                WHERE part = ? AND {$groupColumn} IS NOT NULL";
        if (!empty($excludeGroupIds)) {
            $safeIds = implode(',', array_map('intval', $excludeGroupIds));
            $sql .= " AND {$groupColumn} NOT IN ({$safeIds})";
        }
        $sql .= " ORDER BY RAND()";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $groupIds = [];
        while ($row = $result->fetch_assoc()) {
            $groupIds[] = (int)$row['group_id'];
        }
        $stmt->close();

        return $groupIds;
    }

    private function fetchRowsForGroup(string $table, string $part, string $groupColumn, int $groupId): array {
        $sql = "SELECT * FROM {$table} WHERE part = ? AND {$groupColumn} = ? ORDER BY nomor_soal ASC, id_soal ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $part, $groupId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function insertAssignment(
        string $testSession,
        string $section,
        string $part,
        array $sourceRow,
        int $questionOrder,
        string $stimulusGroupId,
        int $groupOrder
    ): void {
        $questionId = (int)$sourceRow['id_soal'];
        $questionType = !empty($sourceRow['question_type']) ? (string)$sourceRow['question_type'] : 'part_' . $part;

        $stmt = $this->conn->prepare("
            INSERT INTO toeic_test_questions
            (test_session, user_id, question_id, question_type, section, part, question_order, stimulus_group_id, group_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "siisssisi",
            $testSession,
            $this->userId,
            $questionId,
            $questionType,
            $section,
            $part,
            $questionOrder,
            $stimulusGroupId,
            $groupOrder
        );
        $stmt->execute();
        $stmt->close();
    }

    private function normalizeGroupId(string $part, array $row, ?string $groupColumn, int $fallbackIndex): string {
        if ($groupColumn && !empty($row[$groupColumn])) {
            return $part . ':' . (int)$row[$groupColumn];
        }

        if (!empty($row['id_audio'])) {
            return $part . ':a' . (int)$row['id_audio'];
        }

        if (!empty($row['id_teks'])) {
            return $part . ':t' . (int)$row['id_teks'];
        }

        return $part . ':q' . (int)$row['id_soal'] . ':' . $fallbackIndex;
    }

    private function getSeenQuestionIds(string $table, string $part): array {
        $sql = "SELECT DISTINCT tq.question_id
                FROM toeic_test_questions tq
                JOIN {$table} src ON tq.question_id = src.id_soal
                WHERE tq.user_id = ? AND src.part = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $this->userId, $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['question_id'];
        }
        $stmt->close();
        return $ids;
    }

    private function getSeenGroupIds(string $table, string $part, string $groupColumn): array {
        $sql = "SELECT DISTINCT src.{$groupColumn} AS group_id
                FROM toeic_test_questions tq
                JOIN {$table} src ON tq.question_id = src.id_soal
                WHERE tq.user_id = ? AND src.part = ? AND src.{$groupColumn} IS NOT NULL";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $this->userId, $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['group_id'];
        }
        $stmt->close();
        return $ids;
    }
}
