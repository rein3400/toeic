<?php
/**
 * TOEIC Listening & Reading Test Builder
 *
 * Builds a realistic TOEIC simulator session using the assigned-question model
 * in toeic_test_questions. Runtime state lives in toeic_test_sessions; source
 * content stays in toeic_soal_listening, toeic_soal_reading, toeic_audio, and
 * toeic_teks.
 *
 * Anti-duplicate (since 2026-06-11):
 *   - Per-user rolling seen-window (default 10 sessions).
 *   - Context-aware logical identity across duplicate bank row IDs.
 *   - Complete contiguous stimulus groups and exact eligible-unique quotas.
 *   - Staged selection and atomic assignment replacement.
 *   - 3-tier fallback (unseen → least-seen → least-seen-all) with structured
 *     observability (toeic_pool_exhausted_events + fallback_count on session).
 *   - Per-user buildTest lock (GET_LOCK) to prevent concurrent double-tab race.
 *   - Snapshot columns populated at insertAssignment so the scorer never has
 *     to re-read the source table.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/toeic_question_identity.php';
require_once __DIR__ . '/toeic_asset_storage.php';
require_once __DIR__ . '/toeic_question_selector.php';
require_once __DIR__ . '/toeic_transaction.php';

class ToeicTestBuilder {
    private mysqli $conn;
    private int $userId = 0;
    private string $currentTestSession = '';
    private array $pendingAssignments = [];
    private const FREE_TRIAL_QUESTION_LIMIT = 15;
    private const SEEN_WINDOW_SESSIONS = 10;
    private const SEEN_WINDOW_DEFAULT_LIMIT = 200;
    private const BUILD_LOCK_TIMEOUT_SECONDS = 5;
    private const FOOTPRINT_FALLBACK_TIER_UNSEEN = 'unseen';
    private const FOOTPRINT_FALLBACK_TIER_LEAST_SEEN = 'least_seen';
    private const FOOTPRINT_FALLBACK_TIER_LEAST_SEEN_ALL = 'least_seen_all';

    private const PART_TARGETS = [
        '1' => ['section' => 'listening', 'questions' => 6,  'grouped' => false],
        '2' => ['section' => 'listening', 'questions' => 25, 'grouped' => false],
        '3' => ['section' => 'listening', 'questions' => 39, 'grouped' => true,  'group_column' => 'id_audio'],
        '4' => ['section' => 'listening', 'questions' => 30, 'grouped' => true,  'group_column' => 'id_audio'],
        '5' => ['section' => 'reading',   'questions' => 30, 'grouped' => false],
        '6' => ['section' => 'reading',   'questions' => 16, 'grouped' => true,  'group_column' => 'id_teks'],
        '7' => ['section' => 'reading',   'questions' => 54, 'grouped' => true, 'group_column' => 'id_teks'],
    ];

    /** Use an existing connection; callers own authentication and credit policy. */
    public function __construct($conn) {
        $this->conn = $conn;
    }

    /** Run legacy schema checks before a caller starts its credit/session transaction. */
    public function prepareSessionSchema(): void {
        if (toeicConnectionInTransaction($this->conn)) {
            throw new RuntimeException('TOEIC schema preflight must run before the caller transaction.');
        }
        $this->ensureModeColumns();
        $this->ensureQuestionUniqueConstraint();
    }

    /** Create a session record without consuming credits or assigning incomplete content. */
    public function createSession($testSession, $userId, array $options = []): bool {
        // Never run DDL inside caller work: missing schema must fail, not implicitly commit.
        if (!toeicConnectionInTransaction($this->conn)) { $this->prepareSessionSchema(); }

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

    /** Stage a complete plan and replace assignments atomically after every part succeeds. */
    public function buildTest($testSession, $userId, array $options = []): bool {
        $this->userId = (int)$userId;
        $this->currentTestSession = (string)$testSession;
        $this->pendingAssignments = [];

        $targetPart = isset($options['target_part']) ? (string)$options['target_part'] : null;
        $targetSection = $options['target_section'] ?? null;
        $practiceMode = !empty($options['practice_mode']) ? 1 : 0;

        if ($this->userId < 1 || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', (string)$testSession)
            || ($targetPart !== null && !isset(self::PART_TARGETS[$targetPart]))
            || ($targetSection !== null && !in_array($targetSection, ['listening', 'reading'], true))) {
            throw new InvalidArgumentException('Invalid TOEIC session selection request.');
        }
        if ($targetPart !== null && $targetSection !== null && self::PART_TARGETS[$targetPart]['section'] !== $targetSection) {
            throw new InvalidArgumentException('TOEIC part does not belong to the requested section.');
        }

        $lockName = 'toeic_build_' . $this->userId;
        $lockAcquired = false;
        try {
            $lockAcquired = $this->acquireLock($lockName, self::BUILD_LOCK_TIMEOUT_SECONDS);
            if (!$lockAcquired) {
                error_log('toeic_pool_exhausted: buildTest lock timeout user=' . $this->userId . ' session=' . $testSession);
                throw new RuntimeException('Another test is being built for this user. Please retry in a moment.');
            }
            $owner = $this->conn->prepare('SELECT user_id, status FROM toeic_test_sessions WHERE test_session = ?');
            $owner->bind_param('s', $testSession);
            $owner->execute();
            $session = $owner->get_result()->fetch_assoc();
            $owner->close();
            if (!$session || (int)$session['user_id'] !== $this->userId || $session['status'] !== 'active') {
                throw new RuntimeException('TOEIC session is not active or is not owned by this user.');
            }

            $fallbackAccumulator = [
                'sections' => [],
                'total_fallback' => 0,
                'total_short' => 0,
            ];

            if (!empty($options['free_trial'])) {
                $this->assignFreeTrialQuestions($testSession, $fallbackAccumulator);
                $this->persistPlannedAssignments($testSession, $fallbackAccumulator);
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
                    $partInfo = [
                        'section' => $config['section'],
                        'part' => $part,
                        'practice_mode' => $practiceMode,
                    ];
                    if ($config['grouped']) {
                        $order = $this->assignGroupedPart(
                            $testSession,
                            $table,
                            $config['section'],
                            $part,
                            $config['group_column'],
                            $config['questions'],
                            $order,
                            $fallbackAccumulator,
                            $partInfo
                        );
                    } else {
                        $order = $this->assignIndividualPart(
                            $testSession,
                            $table,
                            $config['section'],
                            $part,
                            $config['questions'],
                            $order,
                            $fallbackAccumulator,
                            $partInfo
                        );
                    }
                }
            }

            $this->persistPlannedAssignments($testSession, $fallbackAccumulator);
            return true;
        } finally {
            $this->pendingAssignments = [];
            $this->currentTestSession = '';
            if ($lockAcquired) {
                $this->releaseLock($lockName);
            }
        }
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

    private function assignFreeTrialQuestions(string $testSession, array &$fallbackAccumulator): void {
        $targets = [
            ['table' => 'toeic_soal_listening', 'section' => 'listening', 'part' => '1', 'questions' => 4],
            ['table' => 'toeic_soal_listening', 'section' => 'listening', 'part' => '2', 'questions' => 4],
            ['table' => 'toeic_soal_reading', 'section' => 'reading', 'part' => '5', 'questions' => 7],
        ];

        $sectionOrders = ['listening' => 1, 'reading' => 1];
        $assigned = 0;
        foreach ($targets as $target) {
            $before = $sectionOrders[$target['section']];
            $sectionOrders[$target['section']] = $this->assignPlannedPart(
                $testSession, $target['table'], $target['section'], $target['part'], $target['questions'],
                $before, $fallbackAccumulator,
                ['section' => $target['section'], 'part' => $target['part'], 'practice_mode' => 1], null
            );
            $assigned += $sectionOrders[$target['section']] - $before;
        }
        if ($assigned !== self::FREE_TRIAL_QUESTION_LIMIT) {
            throw new RuntimeException('Bank soal belum mendukung paket free trial yang lengkap.');
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

    /** Build and stage one complete logical part, without changing database assignments. */
    private function assignPlannedPart(string $testSession, string $table, string $section, string $part, int $targetCount, int $startOrder, array &$fallbackAccumulator, array $partInfo, ?string $groupColumn): int {
        $rows = $this->loadPartCandidates($table, $part);
        $history = $this->loadContentHistory($table, $part, $rows);
        $plan = (new ToeicQuestionSelector())->select($rows, $part, $targetCount, $history);
        $this->recordFallbackIfAny($partInfo, $plan['tier'], $targetCount, $plan['drawn'], $fallbackAccumulator, $plan['reused']);
        $order = $startOrder;
        $groupOrders = [];
        foreach ($plan['rows'] as $row) {
            $groupId = $this->normalizeGroupId($part, $row, $groupColumn, 0);
            $groupOrders[$groupId] = ($groupOrders[$groupId] ?? 0) + 1;
            $this->insertAssignment($testSession, $section, $part, $row, $order++, $groupId, $groupOrders[$groupId]);
        }
        return $order;
    }

    /** Read the complete part pool with real context so LIMIT cannot hide eligible unique items. */
    private function loadPartCandidates(string $table, string $part): array {
        if (!in_array($table, ['toeic_soal_listening', 'toeic_soal_reading'], true)) {
            throw new InvalidArgumentException('Unsupported TOEIC bank table.');
        }
        $conditions = ['src.part = ?'];
        if ($table === 'toeic_soal_listening') {
            $joins = ' JOIN toeic_audio ta ON ta.id_audio = src.id_audio';
            $fields = 'ta.file_path AS _audio_path, ta.transcript AS _audio_transcript';
            $conditions[] = "TRIM(COALESCE(ta.file_path, '')) <> ''";
            if ($part === '1') {
                $joins .= ' JOIN toeic_photos tp ON tp.id_photo = ta.id_photo';
                $fields .= ', tp.file_path AS _photo_path';
                $conditions[] = "TRIM(COALESCE(tp.file_path, '')) <> ''";
            }
        } else {
            $joins = ' LEFT JOIN toeic_teks tx ON tx.id_teks = src.id_teks';
            $fields = 'tx.isi_teks AS _passage_1, tx.isi_teks_2 AS _passage_2, tx.isi_teks_3 AS _passage_3, tx.text_type AS _text_type';
        }
        $sql = "SELECT src.*, {$fields} FROM {$table} src{$joins} WHERE " . implode(' AND ', $conditions) . ' ORDER BY src.nomor_soal, src.id_soal';
        $statement = $this->conn->prepare($sql);
        $statement->bind_param('s', $part);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return $rows;
    }

    /** Convert section-scoped history IDs into current logical question and stimulus identities. */
    private function loadContentHistory(string $table, string $part, array $rows): array {
        $history = ['question_counts' => [], 'recent_questions' => [], 'stimulus_counts' => [], 'recent_stimuli' => []];
        $byId = [];
        foreach ($rows as $row) { $byId[(int)$row['id_soal']] = $row; }
        $section = $table === 'toeic_soal_listening' ? 'listening' : 'reading';
        $statement = $this->conn->prepare('SELECT question_id, COUNT(*) AS seen_count FROM toeic_test_questions WHERE user_id = ? AND section = ? AND part = ? AND test_session <> ? GROUP BY question_id');
        $statement->bind_param('isss', $this->userId, $section, $part, $this->currentTestSession);
        $statement->execute();
        $result = $statement->get_result();
        while ($item = $result->fetch_assoc()) {
            $row = $byId[(int)$item['question_id']] ?? null;
            if ($row === null) { continue; }
            $key = $this->rowSignature($row, $part);
            $stimulus = toeicStimulusContentSignature($row, $part);
            $history['question_counts'][$key] = ($history['question_counts'][$key] ?? 0) + (int)$item['seen_count'];
            $history['stimulus_counts'][$stimulus] = ($history['stimulus_counts'][$stimulus] ?? 0) + (int)$item['seen_count'];
        }
        $statement->close();
        foreach ($this->getMostRecentSeenQuestionIds($table, $part, self::SEEN_WINDOW_SESSIONS) as $id) {
            if (!isset($byId[$id])) { continue; }
            $history['recent_questions'][$this->rowSignature($byId[$id], $part)] = true;
            $history['recent_stimuli'][toeicStimulusContentSignature($byId[$id], $part)] = true;
        }
        if (in_array($part, ['3', '4', '6', '7'], true)) {
            $column = in_array($part, ['3', '4'], true) ? 'id_audio' : 'id_teks';
            $recentGroups = array_fill_keys($this->getMostRecentSeenGroupIds($table, $part, $column, self::SEEN_WINDOW_SESSIONS), true);
            foreach ($rows as $row) {
                if (!empty($recentGroups[(int)($row[$column] ?? 0)])) {
                    $history['recent_stimuli'][toeicStimulusContentSignature($row, $part)] = true;
                }
            }
        }
        return $history;
    }

    /** Persist the already complete plan without committing a caller's credit transaction. */
    private function persistPlannedAssignments(string $testSession, array $fallbackAccumulator): void {
        if ($this->pendingAssignments === []) { throw new RuntimeException('TOEIC selection produced no assignments.'); }
        $insideTransaction = toeicConnectionInTransaction($this->conn);
        if ($insideTransaction) { $this->conn->query('SAVEPOINT toeic_builder_replace'); }
        else { $this->conn->begin_transaction(); }
        try {
            $this->clearExistingAssignments($testSession);
            foreach ($this->pendingAssignments as $assignment) { $this->writeAssignment(...$assignment); }
            $this->persistFallbackAccounting($testSession, $fallbackAccumulator);
            if ($insideTransaction) { $this->conn->query('RELEASE SAVEPOINT toeic_builder_replace'); }
            else { $this->conn->commit(); }
        } catch (Throwable $error) {
            if ($insideTransaction) { $this->conn->query('ROLLBACK TO SAVEPOINT toeic_builder_replace'); }
            else { $this->conn->rollback(); }
            throw $error;
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
        int $startOrder,
        array &$fallbackAccumulator,
        array $partInfo
    ): int {
        return $this->assignPlannedPart($testSession, $table, $section, $part, $targetCount, $startOrder, $fallbackAccumulator, $partInfo, null);
    }

    private function assignGroupedPart(
        string $testSession,
        string $table,
        string $section,
        string $part,
        string $groupColumn,
        int $targetCount,
        int $startOrder,
        array &$fallbackAccumulator,
        array $partInfo
    ): int {
        return $this->assignPlannedPart($testSession, $table, $section, $part, $targetCount, $startOrder, $fallbackAccumulator, $partInfo, $groupColumn);
    }

    private function pickIndividualRowsWithFallback(
        string $table,
        string $part,
        int $limit,
        array $excludeQuestionIds,
        array &$fallbackAccumulator,
        array $partInfo
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->fetchRandomQuestionRows(
            $table,
            $part,
            $limit,
            true,
            $excludeQuestionIds
        );
        $tier = self::FOOTPRINT_FALLBACK_TIER_UNSEEN;
        if (count($rows) < $limit) {
            $already = array_merge($excludeQuestionIds, array_map(fn($row) => (int)$row['id_soal'], $rows));
            $rows = array_merge(
                $rows,
                $this->fetchRandomQuestionRows($table, $part, $limit - count($rows), false, $already, true)
            );
            $tier = self::FOOTPRINT_FALLBACK_TIER_LEAST_SEEN;
        }
        if (count($rows) < $limit) {
            $tier = self::FOOTPRINT_FALLBACK_TIER_LEAST_SEEN_ALL;
        }

        $this->recordFallbackIfAny($partInfo, $tier, $limit, count($rows), $fallbackAccumulator);

        return $rows;
    }

    private function pickGroupedRowsWithFallback(
        string $table,
        string $part,
        string $groupColumn,
        array &$fallbackAccumulator,
        array $partInfo
    ): array {
        $groupIds = $this->fetchRandomGroupIds($table, $part, $groupColumn, true);
        if (empty($groupIds)) {
            $groupIds = $this->fetchRandomGroupIds($table, $part, $groupColumn, false, true);
            $this->recordFallbackIfAny($partInfo, self::FOOTPRINT_FALLBACK_TIER_LEAST_SEEN, count($groupIds), count($groupIds), $fallbackAccumulator);
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

    private function fetchRandomQuestionRows(
        string $table,
        string $part,
        int $limit,
        bool $preferUnseen,
        array $excludeQuestionIds = [],
        bool $leastSeen = false
    ): array {
        $excludeIds = $excludeQuestionIds;
        if ($preferUnseen) {
            $excludeIds = array_merge($excludeIds, $this->getSeenQuestionIds($table, $part, $leastSeen ? self::SEEN_WINDOW_SESSIONS : null));
        } elseif ($leastSeen) {
            // When falling back to least-seen, we exclude only those that
            // have appeared in the last 10 sessions for this user.
            $excludeIds = array_merge($excludeIds, $this->getMostRecentSeenQuestionIds($table, $part, self::SEEN_WINDOW_SESSIONS));
        }
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));

        $joins = '';
        $conditions = ['src.part = ?'];

        if ($this->requiresAudioAsset($table, $part)) {
            $joins .= ' JOIN toeic_audio ta ON ta.id_audio = src.id_audio';
            $conditions[] = 'src.id_audio IS NOT NULL';
            $conditions[] = "TRIM(COALESCE(ta.file_path, '')) <> ''";
        }

        if ($this->requiresPhotoAsset($table, $part)) {
            $joins .= ' JOIN toeic_photos tp ON tp.id_photo = ta.id_photo';
            $conditions[] = 'ta.id_photo IS NOT NULL';
            $conditions[] = "TRIM(COALESCE(tp.file_path, '')) <> ''";
        }

        if (!empty($excludeIds)) {
            $conditions[] = "src.id_soal NOT IN (" . implode(',', $excludeIds) . ")";
        }
        $sql = "SELECT src.* FROM {$table} src{$joins} WHERE " . implode(' AND ', $conditions);
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

    private function fetchRandomGroupIds(
        string $table,
        string $part,
        string $groupColumn,
        bool $preferUnseen,
        bool $leastSeen = false
    ): array {
        $excludeGroupIds = [];
        if ($preferUnseen) {
            $excludeGroupIds = $this->getSeenGroupIds($table, $part, $groupColumn, $leastSeen ? self::SEEN_WINDOW_SESSIONS : null);
        } elseif ($leastSeen) {
            $excludeGroupIds = $this->getMostRecentSeenGroupIds($table, $part, $groupColumn, self::SEEN_WINDOW_SESSIONS);
        }
        $joins = '';
        $conditions = ["src.part = ?", "src.{$groupColumn} IS NOT NULL"];

        if ($this->requiresAudioAsset($table, $part)) {
            $joins .= " JOIN toeic_audio ta ON ta.id_audio = src.{$groupColumn}";
            $conditions[] = "TRIM(COALESCE(ta.file_path, '')) <> ''";
        }

        if (!empty($excludeGroupIds)) {
            $safeIds = implode(',', array_map('intval', $excludeGroupIds));
            $conditions[] = "src.{$groupColumn} NOT IN ({$safeIds})";
        }
        $sql = "SELECT DISTINCT src.{$groupColumn} AS group_id
                FROM {$table} src{$joins}
                WHERE " . implode(' AND ', $conditions);
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

    private function requiresAudioAsset(string $table, string $part): bool {
        return $table === 'toeic_soal_listening' && in_array($part, ['1', '2', '3', '4'], true);
    }

    private function requiresPhotoAsset(string $table, string $part): bool {
        return $table === 'toeic_soal_listening' && $part === '1';
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

    /** Stage an assignment so a later failure cannot leave a partial or emptied session. */
    private function insertAssignment(string $testSession, string $section, string $part, array $sourceRow, int $questionOrder, string $stimulusGroupId, int $groupOrder): void {
        $this->pendingAssignments[] = [$testSession, $section, $part, $sourceRow, $questionOrder, $stimulusGroupId, $groupOrder];
    }

    /** Write a previously validated assignment and immutable question/answer snapshot. */
    private function writeAssignment(
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
        $snapJawaban = isset($sourceRow['jawaban_benar']) ? strtoupper(trim((string)$sourceRow['jawaban_benar'])) : null;
        $snapPertanyaan = isset($sourceRow['pertanyaan']) ? (string)$sourceRow['pertanyaan'] : null;
        $snapOpsiA = isset($sourceRow['opsi_a']) ? (string)$sourceRow['opsi_a'] : null;
        $snapOpsiB = isset($sourceRow['opsi_b']) ? (string)$sourceRow['opsi_b'] : null;
        $snapOpsiC = isset($sourceRow['opsi_c']) ? (string)$sourceRow['opsi_c'] : null;
        $snapOpsiD = isset($sourceRow['opsi_d']) ? (string)$sourceRow['opsi_d'] : null;

        $stmt = $this->conn->prepare("
            INSERT INTO toeic_test_questions
            (test_session, user_id, question_id, question_type, section, part, question_order, stimulus_group_id, group_order,
             snap_jawaban_benar, snap_pertanyaan, snap_opsi_a, snap_opsi_b, snap_opsi_c, snap_opsi_d, original_question_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "siisssissssssssi",
            $testSession,
            $this->userId,
            $questionId,
            $questionType,
            $section,
            $part,
            $questionOrder,
            $stimulusGroupId,
            $groupOrder,
            $snapJawaban,
            $snapPertanyaan,
            $snapOpsiA,
            $snapOpsiB,
            $snapOpsiC,
            $snapOpsiD,
            $questionId
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

    /**
     * Get questions the user has seen, optionally bounded to a recent
     * N-session window. When $windowSessions is null, returns everything
     * (legacy forever-seen behaviour for the strict-unseen tier).
     *
     * @return int[]
     */
    private function getSeenQuestionIds(string $table, string $part, ?int $windowSessions = null): array {
        $stmt = $this->conn->prepare("
            SELECT tq.question_id, ts.started_at
            FROM toeic_test_questions tq
            JOIN toeic_test_sessions ts ON ts.test_session = tq.test_session
            JOIN {$table} src ON tq.question_id = src.id_soal
            WHERE tq.user_id = ? AND src.part = ?
            ORDER BY ts.started_at DESC, tq.id DESC
        ");
        $stmt->bind_param("is", $this->userId, $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        $sessionCount = 0;
        $currentSession = null;
        while ($row = $result->fetch_assoc()) {
            $session = $row['started_at'] ?? '__no_session';
            if ($session !== $currentSession) {
                $sessionCount++;
                $currentSession = $session;
            }
            if ($windowSessions !== null && $sessionCount > $windowSessions * 10) {
                // ~10 questions per part per session — bail early.
                break;
            }
            $ids[] = (int)$row['question_id'];
        }
        $stmt->close();
        return $ids;
    }

    /**
     * Get questions the user has seen most recently, scoped to last N sessions.
     * Used to keep the seen-window finite.
     */
    private function getMostRecentSeenQuestionIds(string $table, string $part, int $windowSessions): array {
        $stmt = $this->conn->prepare("
            SELECT ts.test_session, ts.started_at
            FROM toeic_test_sessions ts
            WHERE ts.user_id = ? AND ts.test_session <> ?
            ORDER BY ts.started_at DESC, ts.test_session DESC
            LIMIT " . (int)$windowSessions . "
        ");
        $stmt->bind_param("is", $this->userId, $this->currentTestSession);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($sessions)) {
            return [];
        }

        $oldestStartedAt = min(array_column($sessions, 'started_at'));
        $sessionIds = array_column($sessions, 'test_session');
        $sessionPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));

        $section = $table === 'toeic_soal_listening' ? 'listening' : 'reading';
        $types = 'isss' . str_repeat('s', count($sessionIds));
        $params = array_merge([$this->userId, $part, $section, $oldestStartedAt], $sessionIds);
        $stmt = $this->conn->prepare("
            SELECT DISTINCT tq.question_id
            FROM toeic_test_questions tq
            JOIN {$table} src ON tq.question_id = src.id_soal
            JOIN toeic_test_sessions ts ON ts.test_session = tq.test_session
            WHERE tq.user_id = ?
              AND src.part = ?
              AND tq.section = ?
              AND ts.started_at >= ?
              AND tq.test_session IN ({$sessionPlaceholders})
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['question_id'];
        }
        $stmt->close();
        return $ids;
    }

    private function getSeenGroupIds(string $table, string $part, string $groupColumn, ?int $windowSessions = null): array {
        $sql = "SELECT DISTINCT src.{$groupColumn} AS group_id, ts.started_at
                FROM toeic_test_questions tq
                JOIN {$table} src ON tq.question_id = src.id_soal
                JOIN toeic_test_sessions ts ON ts.test_session = tq.test_session
                WHERE tq.user_id = ? AND src.part = ? AND src.{$groupColumn} IS NOT NULL
                ORDER BY ts.started_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $this->userId, $part);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        $sessionCount = 0;
        $currentSession = null;
        while ($row = $result->fetch_assoc()) {
            $session = $row['started_at'] ?? '__no_session';
            if ($session !== $currentSession) {
                $sessionCount++;
                $currentSession = $session;
            }
            if ($windowSessions !== null && $sessionCount > $windowSessions * 10) {
                break;
            }
            $ids[] = (int)$row['group_id'];
        }
        $stmt->close();
        return $ids;
    }

    private function getMostRecentSeenGroupIds(string $table, string $part, string $groupColumn, int $windowSessions): array {
        $stmt = $this->conn->prepare("
            SELECT ts.test_session, ts.started_at
            FROM toeic_test_sessions ts
            WHERE ts.user_id = ? AND ts.test_session <> ?
            ORDER BY ts.started_at DESC, ts.test_session DESC
            LIMIT " . (int)$windowSessions . "
        ");
        $stmt->bind_param("is", $this->userId, $this->currentTestSession);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($sessions)) {
            return [];
        }
        $oldestStartedAt = min(array_column($sessions, 'started_at'));
        $sessionIds = array_column($sessions, 'test_session');
        $sessionPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));

        $section = $table === 'toeic_soal_listening' ? 'listening' : 'reading';
        $types = 'isss' . str_repeat('s', count($sessionIds));
        $params = array_merge([$this->userId, $part, $section, $oldestStartedAt], $sessionIds);
        $stmt = $this->conn->prepare("
            SELECT DISTINCT src.{$groupColumn} AS group_id
            FROM toeic_test_questions tq
            JOIN {$table} src ON tq.question_id = src.id_soal
            JOIN toeic_test_sessions ts ON ts.test_session = tq.test_session
            WHERE tq.user_id = ?
              AND src.part = ?
              AND tq.section = ?
              AND src.{$groupColumn} IS NOT NULL
              AND ts.started_at >= ?
              AND tq.test_session IN ({$sessionPlaceholders})
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['group_id'];
        }
        $stmt->close();
        return $ids;
    }

    private function rowSignature(array $row, string $part): string {
        return toeicQuestionContentSignature($row, $part);
    }

    private function recordFallbackIfAny(array $partInfo, string $tier, int $target, int $drawn, array &$fallbackAccumulator, int $reused = 0): void {
        $key = $partInfo['section'] . '|P' . $partInfo['part'];
        if (!isset($fallbackAccumulator['sections'][$key])) {
            $fallbackAccumulator['sections'][$key] = [
                'section' => $partInfo['section'],
                'part' => (string)$partInfo['part'],
                'target' => 0,
                'drawn' => 0,
                'reused' => 0,
                'tier' => self::FOOTPRINT_FALLBACK_TIER_UNSEEN,
                'exhausted' => 0,
            ];
        }
        $entry = &$fallbackAccumulator['sections'][$key];
        $entry['target'] += $target;
        $entry['drawn'] += $drawn;
        $entry['reused'] += $reused;
        if ($drawn < $target) {
            $entry['exhausted']++;
        }
        // Promote tier to the worst observed.
        $tierRank = [
            self::FOOTPRINT_FALLBACK_TIER_UNSEEN => 0,
            self::FOOTPRINT_FALLBACK_TIER_LEAST_SEEN => 1,
            self::FOOTPRINT_FALLBACK_TIER_LEAST_SEEN_ALL => 2,
        ];
        if ($tierRank[$tier] > $tierRank[$entry['tier']]) {
            $entry['tier'] = $tier;
        }
        unset($entry);
    }

    private function persistFallbackAccounting(string $testSession, array $fallbackAccumulator): void {
        $totalFallback = 0;
        foreach ($fallbackAccumulator['sections'] as $entry) {
            $totalFallback += (int)($entry['reused'] ?? 0);
        }

        $breakdown = $fallbackAccumulator['sections'];
        $json = json_encode($breakdown, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        $stmt = $this->conn->prepare("
            UPDATE toeic_test_sessions
            SET fallback_count = ?, fallback_breakdown = ?, short_filled_count = ?
            WHERE test_session = ?
        ");
        $stmt->bind_param('isis', $totalFallback, $json, $fallbackAccumulator['total_short'], $testSession);
        $stmt->execute();
        $stmt->close();

        // Emit observability rows.
        if (!empty($fallbackAccumulator['sections'])) {
            $insertStmt = $this->conn->prepare("
                INSERT INTO toeic_pool_exhausted_events
                (test_session, user_id, section, part, target_count, drawn_count, fallback_tier, seen_window_size)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($fallbackAccumulator['sections'] as $entry) {
                $missing = max(0, $entry['target'] - $entry['drawn']);
                if ($missing === 0 && $entry['tier'] === self::FOOTPRINT_FALLBACK_TIER_UNSEEN) {
                    continue;
                }
                $seenWindow = self::SEEN_WINDOW_SESSIONS;
                $insertStmt->bind_param(
                    'sissiisi',
                    $testSession,
                    $this->userId,
                    $entry['section'],
                    $entry['part'],
                    $entry['target'],
                    $entry['drawn'],
                    $entry['tier'],
                    $seenWindow
                );
                $insertStmt->execute();
            }
            $insertStmt->close();
        }
    }

    private function acquireLock(string $name, int $timeout): bool {
        $stmt = $this->conn->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        $stmt->bind_param('si', $name, $timeout);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !empty($row['acquired']);
    }

    private function releaseLock(string $name): void {
        try {
            $stmt = $this->conn->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('toeic_pool_exhausted: release lock failed: ' . $e->getMessage());
        }
    }
}
