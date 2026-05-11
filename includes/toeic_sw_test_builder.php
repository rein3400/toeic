<?php
/**
 * TOEIC Speaking & Writing session builder.
 */

require_once __DIR__ . '/toeic_sw_helper.php';

class ToeicSwTestBuilder {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        ensureToeicSwSchema($this->conn);
    }

    public function createSession(string $testSession, int $userId, array $options = []): bool {
        $packageNumber = (int)($options['package_number'] ?? 0);
        if ($packageNumber <= 0) {
            $packageNumber = $this->pickReadyPackage($userId);
        }

        $currentSection = $options['current_section'] ?? getToeicSwSectionOrder()[0];
        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO toeic_sw_test_sessions
            (test_session, user_id, package_number, current_section, status)
            VALUES (?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param("siis", $testSession, $userId, $packageNumber, $currentSection);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function buildTest(string $testSession, int $userId, array $options = []): bool {
        $packageNumber = (int)($options['package_number'] ?? $this->getSessionPackage($testSession));
        if ($packageNumber <= 0) {
            $packageNumber = $this->pickReadyPackage($userId);
        }

        $this->clearExistingAssignments($testSession);

        foreach (getToeicSwTaskBlueprint() as $section => $tasks) {
            foreach ($tasks as $questionNumber => $task) {
                $table = getToeicSwContentTableForType($task['type']);
                if (!$table) {
                    throw new RuntimeException('Unknown TOEIC SW task type: ' . $task['type']);
                }

                $content = $this->fetchContentRow($table, $packageNumber, (int)$questionNumber);
                if (!$content) {
                    throw new RuntimeException("Missing TOEIC SW content for package {$packageNumber}, {$section} question {$questionNumber}.");
                }

                $this->insertAssignment(
                    $testSession,
                    $userId,
                    $packageNumber,
                    $section,
                    (int)$questionNumber,
                    $task,
                    $table,
                    $content
                );
            }
        }

        return true;
    }

    public function pickReadyPackage(int $userId = 0): int {
        $readiness = getToeicSwContentReadiness($this->conn);
        $readyPackages = [];
        foreach ($readiness['packages'] as $packageNumber => $summary) {
            if (!empty($summary['ready'])) {
                $readyPackages[] = (int)$packageNumber;
            }
        }

        if (empty($readyPackages)) {
            throw new RuntimeException('Bank soal TOEIC Speaking & Writing belum lengkap.');
        }

        if ($userId > 0) {
            $seen = $this->getSeenPackages($userId);
            $fresh = array_values(array_diff($readyPackages, $seen));
            if (!empty($fresh)) {
                $readyPackages = $fresh;
            }
        }

        return $readyPackages[array_rand($readyPackages)];
    }

    private function getSessionPackage(string $testSession): int {
        $stmt = $this->conn->prepare("SELECT package_number FROM toeic_sw_test_sessions WHERE test_session = ? LIMIT 1");
        $stmt->bind_param("s", $testSession);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['package_number'] ?? 0);
    }

    private function getSeenPackages(int $userId): array {
        $stmt = $this->conn->prepare("SELECT DISTINCT package_number FROM toeic_sw_test_sessions WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $packages = [];
        while ($row = $result->fetch_assoc()) {
            $packages[] = (int)$row['package_number'];
        }
        $stmt->close();
        return $packages;
    }

    private function clearExistingAssignments(string $testSession): void {
        $stmt = $this->conn->prepare("DELETE FROM toeic_sw_test_questions WHERE test_session = ?");
        $stmt->bind_param("s", $testSession);
        $stmt->execute();
        $stmt->close();
    }

    private function fetchContentRow(string $table, int $packageNumber, int $questionNumber): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM {$table} WHERE package_number = ? AND question_number = ? LIMIT 1");
        $stmt->bind_param("ii", $packageNumber, $questionNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function insertAssignment(
        string $testSession,
        int $userId,
        int $packageNumber,
        string $section,
        int $questionNumber,
        array $task,
        string $table,
        array $content
    ): void {
        $stimulusGroupId = null;
        if ($task['type'] === 'respond_using_information') {
            $stimulusGroupId = (string)($content['stimulus_group_id'] ?? sprintf('pkg%02d-info', $packageNumber));
        }

        $questionId = (int)$content['id'];
        $questionType = (string)$task['type'];
        $part = (string)($task['part'] ?? '');
        $prepareSeconds = (int)($task['prepare_seconds'] ?? 0);
        $responseSeconds = (int)($task['response_seconds'] ?? 0);
        $readSeconds = (int)($task['read_seconds'] ?? 0);
        $taskMinutes = (int)($task['task_minutes'] ?? 0);
        $repeatQuestion = !empty($task['repeat_question']) ? 1 : 0;
        $groupOrder = $stimulusGroupId ? max(1, $questionNumber - 7) : 1;

        $stmt = $this->conn->prepare("
            INSERT INTO toeic_sw_test_questions
            (test_session, user_id, package_number, question_id, source_table, question_type, section, part,
             question_order, stimulus_group_id, group_order, prepare_seconds, response_seconds, read_seconds,
             task_minutes, repeat_question)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "siiissssisiiiiii",
            $testSession,
            $userId,
            $packageNumber,
            $questionId,
            $table,
            $questionType,
            $section,
            $part,
            $questionNumber,
            $stimulusGroupId,
            $groupOrder,
            $prepareSeconds,
            $responseSeconds,
            $readSeconds,
            $taskMinutes,
            $repeatQuestion
        );
        $stmt->execute();
        $stmt->close();
    }
}
?>
