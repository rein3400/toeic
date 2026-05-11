<?php
/**
 * TOEIC Speaking & Writing helpers.
 *
 * Keeps the TOEIC SW product separate from the existing Listening & Reading
 * implementation while sharing the same TOEIC-only product conventions.
 */

if (!function_exists('getToeicSwSectionOrder')) {
    function getToeicSwSectionOrder(): array {
        return ['speaking', 'writing'];
    }
}

if (!function_exists('getToeicSwTaskBlueprint')) {
    function getToeicSwTaskBlueprint(): array {
        return [
            'speaking' => [
                1 => ['type' => 'read_text_aloud', 'label' => 'Read a text aloud', 'prepare_seconds' => 45, 'response_seconds' => 45, 'part' => 'S1'],
                2 => ['type' => 'read_text_aloud', 'label' => 'Read a text aloud', 'prepare_seconds' => 45, 'response_seconds' => 45, 'part' => 'S1'],
                3 => ['type' => 'describe_picture', 'label' => 'Describe a picture', 'prepare_seconds' => 45, 'response_seconds' => 30, 'part' => 'S2'],
                4 => ['type' => 'describe_picture', 'label' => 'Describe a picture', 'prepare_seconds' => 45, 'response_seconds' => 30, 'part' => 'S2'],
                5 => ['type' => 'respond_to_questions', 'label' => 'Respond to questions', 'prepare_seconds' => 3, 'response_seconds' => 15, 'part' => 'S3'],
                6 => ['type' => 'respond_to_questions', 'label' => 'Respond to questions', 'prepare_seconds' => 3, 'response_seconds' => 15, 'part' => 'S3'],
                7 => ['type' => 'respond_to_questions', 'label' => 'Respond to questions', 'prepare_seconds' => 3, 'response_seconds' => 30, 'part' => 'S3'],
                8 => ['type' => 'respond_using_information', 'label' => 'Respond using information provided', 'prepare_seconds' => 3, 'response_seconds' => 15, 'read_seconds' => 45, 'part' => 'S4'],
                9 => ['type' => 'respond_using_information', 'label' => 'Respond using information provided', 'prepare_seconds' => 3, 'response_seconds' => 15, 'read_seconds' => 45, 'part' => 'S4'],
                10 => ['type' => 'respond_using_information', 'label' => 'Respond using information provided', 'prepare_seconds' => 3, 'response_seconds' => 30, 'read_seconds' => 45, 'repeat_question' => true, 'part' => 'S4'],
                11 => ['type' => 'express_opinion', 'label' => 'Express an opinion', 'prepare_seconds' => 45, 'response_seconds' => 60, 'part' => 'S5'],
            ],
            'writing' => [
                1 => ['type' => 'write_sentence_based_on_picture', 'label' => 'Write a sentence based on a picture', 'required_words_count' => 2, 'part' => 'W1'],
                2 => ['type' => 'write_sentence_based_on_picture', 'label' => 'Write a sentence based on a picture', 'required_words_count' => 2, 'part' => 'W1'],
                3 => ['type' => 'write_sentence_based_on_picture', 'label' => 'Write a sentence based on a picture', 'required_words_count' => 2, 'part' => 'W1'],
                4 => ['type' => 'write_sentence_based_on_picture', 'label' => 'Write a sentence based on a picture', 'required_words_count' => 2, 'part' => 'W1'],
                5 => ['type' => 'write_sentence_based_on_picture', 'label' => 'Write a sentence based on a picture', 'required_words_count' => 2, 'part' => 'W1'],
                6 => ['type' => 'respond_to_written_request', 'label' => 'Respond to a written request', 'task_minutes' => 10, 'part' => 'W2'],
                7 => ['type' => 'respond_to_written_request', 'label' => 'Respond to a written request', 'task_minutes' => 10, 'part' => 'W2'],
                8 => ['type' => 'write_opinion_essay', 'label' => 'Write an opinion essay', 'minimum_words' => 300, 'part' => 'W3'],
            ],
        ];
    }
}

if (!function_exists('getToeicSwPackageRequirements')) {
    function getToeicSwPackageRequirements(): array {
        return [
            'packages' => 10,
            'speaking_per_package' => 11,
            'writing_per_package' => 8,
            'images_per_package' => 7,
            'speaking_audio_per_package' => 7,
            'items_per_package' => 19,
            'speaking_score_scale' => 200,
            'writing_score_scale' => 200,
        ];
    }
}

if (!function_exists('toeicSwSpeakingUsesPromptAudio')) {
    function toeicSwSpeakingUsesPromptAudio(string $taskType): bool {
        return in_array($taskType, [
            'respond_to_questions',
            'respond_using_information',
            'express_opinion',
        ], true);
    }
}

if (!function_exists('generateToeicSwTestSession')) {
    function generateToeicSwTestSession(): string {
        return 'toeic_sw_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('getToeicSwContentTableForType')) {
    function getToeicSwContentTableForType(string $type): ?string {
        $map = [
            'read_text_aloud' => 'toeic_sw_read_aloud',
            'describe_picture' => 'toeic_sw_describe_picture',
            'respond_to_questions' => 'toeic_sw_respond_questions',
            'respond_using_information' => 'toeic_sw_respond_information',
            'express_opinion' => 'toeic_sw_express_opinion',
            'write_sentence_based_on_picture' => 'toeic_sw_picture_sentence',
            'respond_to_written_request' => 'toeic_sw_written_request',
            'write_opinion_essay' => 'toeic_sw_opinion_essay',
        ];
        return $map[$type] ?? null;
    }
}

if (!function_exists('getToeicSwTaskInfo')) {
    function getToeicSwTaskInfo(string $section, int $questionNumber): ?array {
        $blueprint = getToeicSwTaskBlueprint();
        return $blueprint[$section][$questionNumber] ?? null;
    }
}

if (!function_exists('getToeicSwSectionSeconds')) {
    function getToeicSwSectionSeconds(string $section): int {
        return $section === 'writing' ? 60 * 60 : 20 * 60;
    }
}

if (!function_exists('getToeicSwLevel')) {
    function getToeicSwLevel(int $totalScore): array {
        if ($totalScore >= 360) return ['Advanced Professional', 'C1', 'success'];
        if ($totalScore >= 310) return ['Advanced', 'B2+', 'primary'];
        if ($totalScore >= 240) return ['Upper Intermediate', 'B2', 'info'];
        if ($totalScore >= 160) return ['Intermediate', 'B1', 'warning'];
        if ($totalScore >= 80) return ['Elementary', 'A2', 'secondary'];
        return ['Novice', 'A1', 'danger'];
    }
}

if (!function_exists('toeicSwClamp01')) {
    function toeicSwClamp01(float $value): float {
        return max(0.0, min(1.0, $value));
    }
}

if (!function_exists('toeicSwScaleSectionScore')) {
    function toeicSwScaleSectionScore(float $averageNormalized): int {
        $scaled = (int)round(toeicSwClamp01($averageNormalized) * 200);
        return max(0, min(200, (int)(round($scaled / 10) * 10)));
    }
}

if (!function_exists('ensureToeicSwSchema')) {
    function ensureToeicSwSchema(mysqli $conn): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $conn->query("
            CREATE TABLE IF NOT EXISTS site_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS toeic_sw_test_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                test_session VARCHAR(120) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                package_number INT NOT NULL,
                current_section VARCHAR(20) NOT NULL DEFAULT 'speaking',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                speaking_raw DECIMAL(8,2) DEFAULT NULL,
                speaking_scaled INT DEFAULT NULL,
                writing_raw DECIMAL(8,2) DEFAULT NULL,
                writing_scaled INT DEFAULT NULL,
                total_score INT DEFAULT NULL,
                cefr_level VARCHAR(10) DEFAULT NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                INDEX idx_user_status (user_id, status),
                INDEX idx_package_number (package_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS toeic_sw_test_questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                test_session VARCHAR(120) NOT NULL,
                user_id INT NOT NULL,
                package_number INT NOT NULL,
                question_id INT NOT NULL,
                source_table VARCHAR(80) NOT NULL,
                question_type VARCHAR(80) NOT NULL,
                section VARCHAR(20) NOT NULL,
                part VARCHAR(10) DEFAULT NULL,
                question_order INT NOT NULL,
                stimulus_group_id VARCHAR(120) DEFAULT NULL,
                group_order INT DEFAULT 1,
                prepare_seconds INT DEFAULT 0,
                response_seconds INT DEFAULT 0,
                read_seconds INT DEFAULT 0,
                task_minutes INT DEFAULT 0,
                repeat_question TINYINT(1) NOT NULL DEFAULT 0,
                user_answer LONGTEXT DEFAULT NULL,
                source_path VARCHAR(500) DEFAULT NULL,
                is_correct DECIMAL(8,4) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_session_section_order (test_session, section, question_order),
                INDEX idx_session_section (test_session, section),
                INDEX idx_user_session (user_id, test_session)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS toeic_sw_test_results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                test_session VARCHAR(120) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                package_number INT NOT NULL,
                speaking_raw DECIMAL(8,2) NOT NULL DEFAULT 0,
                speaking_scaled INT NOT NULL DEFAULT 0,
                writing_raw DECIMAL(8,2) NOT NULL DEFAULT 0,
                writing_scaled INT NOT NULL DEFAULT 0,
                total_score INT NOT NULL DEFAULT 0,
                cefr_level VARCHAR(10) DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                INDEX idx_user_completed (user_id, completed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS toeic_sw_subjective_scores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                test_session VARCHAR(120) NOT NULL,
                user_id INT NOT NULL,
                question_row_id INT NOT NULL,
                question_id INT NOT NULL,
                question_type VARCHAR(80) NOT NULL,
                section VARCHAR(20) NOT NULL,
                source_path VARCHAR(500) DEFAULT NULL,
                transcript_text LONGTEXT DEFAULT NULL,
                raw_score DECIMAL(8,2) DEFAULT NULL,
                normalized_score DECIMAL(8,4) DEFAULT NULL,
                feedback_json LONGTEXT DEFAULT NULL,
                ai_provider VARCHAR(50) DEFAULT NULL,
                ai_model VARCHAR(100) DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                fallback_reason TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_session_question_row (test_session, question_row_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        toeicSwEnsureContentTables($conn);
        toeicSwEnsureColumn($conn, 'toeic_sw_subjective_scores', 'fallback_reason', 'TEXT DEFAULT NULL');
        toeicSwEnsureDefaultSettings($conn);
    }
}

if (!function_exists('toeicSwEnsureColumn')) {
    function toeicSwEnsureColumn(mysqli $conn, string $table, string $column, string $definition): void {
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return;
        }

        $result = $conn->query("SHOW COLUMNS FROM {$safeTable} LIKE '{$conn->real_escape_string($safeColumn)}'");
        if ($result && $result->num_rows > 0) {
            return;
        }
        $conn->query("ALTER TABLE {$safeTable} ADD COLUMN {$safeColumn} {$definition}");
    }
}

if (!function_exists('toeicSwEnsureContentTables')) {
    function toeicSwEnsureContentTables(mysqli $conn): void {
        $common = "
            id INT AUTO_INCREMENT PRIMARY KEY,
            package_number INT NOT NULL,
            question_number INT NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            prompt_text LONGTEXT DEFAULT NULL,
            sample_response LONGTEXT DEFAULT NULL,
            scoring_rubric LONGTEXT DEFAULT NULL,
            difficulty VARCHAR(20) DEFAULT 'C2',
            cefr_level VARCHAR(10) DEFAULT 'C2',
            audio_path VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ";
        $unique = "UNIQUE KEY uniq_package_question (package_number, question_number)";

        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_read_aloud ($common, $unique) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_describe_picture ($common, image_path VARCHAR(500) NOT NULL, $unique) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_respond_questions ($common, scenario_title VARCHAR(255) DEFAULT NULL, $unique) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_respond_information ($common, stimulus_group_id VARCHAR(120) NOT NULL, information_card LONGTEXT NOT NULL, repeat_question TINYINT(1) NOT NULL DEFAULT 0, $unique, INDEX idx_stimulus (package_number, stimulus_group_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_express_opinion ($common, $unique) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_picture_sentence ($common, image_path VARCHAR(500) NOT NULL, required_words_json LONGTEXT NOT NULL, $unique) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_written_request ($common, recipient_type VARCHAR(120) DEFAULT NULL, word_limit_min INT DEFAULT 80, word_limit_max INT DEFAULT 120, $unique) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS toeic_sw_opinion_essay ($common, minimum_words INT DEFAULT 300, $unique) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach (array_unique(array_filter(array_map('getToeicSwContentTableForType', [
            'read_text_aloud',
            'describe_picture',
            'respond_to_questions',
            'respond_using_information',
            'express_opinion',
            'write_sentence_based_on_picture',
            'respond_to_written_request',
            'write_opinion_essay',
        ]))) as $table) {
            toeicSwEnsureColumn($conn, $table, 'audio_path', 'VARCHAR(500) DEFAULT NULL');
        }
    }
}

if (!function_exists('toeicSwEnsureDefaultSettings')) {
    function toeicSwEnsureDefaultSettings(mysqli $conn): void {
        $defaults = [
            'name_toeic_sw' => 'TOEIC Speaking & Writing',
            'price_toeic_sw' => '175000',
            'toeic_sw_scoring_model' => 'gpt-5.5',
            'toeic_sw_transcription_model' => 'gpt-4o-transcribe',
            'toeic_sw_tts_model' => 'gpt-realtime-1.5',
            'features_toeic_sw' => json_encode([
                'Speaking 11 questions',
                'Writing 8 questions',
                'Score report Speaking 0-200',
                'Score report Writing 0-200',
                'AI-assisted transcript and feedback',
            ]),
        ];

        foreach ($defaults as $key => $value) {
            $stmt = $conn->prepare("
                INSERT INTO site_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = setting_value
            ");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param("ss", $key, $value);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('getToeicSwContentReadiness')) {
    function getToeicSwContentReadiness(mysqli $conn): array {
        ensureToeicSwSchema($conn);
        $requirements = getToeicSwPackageRequirements();
        $packages = [];
        $ready = true;

        for ($package = 1; $package <= $requirements['packages']; $package++) {
            $speaking = 0;
            $writing = 0;
            $speakingAudio = 0;
            $images = [];
            foreach (getToeicSwTaskBlueprint() as $section => $tasks) {
                foreach ($tasks as $questionNumber => $task) {
                    $table = getToeicSwContentTableForType($task['type']);
                    if (!$table) {
                        continue;
                    }
                    $stmt = $conn->prepare("SELECT * FROM {$table} WHERE package_number = ? AND question_number = ? LIMIT 1");
                    $stmt->bind_param("ii", $package, $questionNumber);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        if ($section === 'speaking') {
                            $speaking++;
                            if (!empty($row['audio_path'])) {
                                $speakingAudio++;
                            }
                        } else {
                            $writing++;
                        }
                        if (!empty($row['image_path'])) {
                            $images[$row['image_path']] = true;
                        }
                    }
                }
            }
            $packageReady = $speaking === $requirements['speaking_per_package']
                && $writing === $requirements['writing_per_package']
                && count($images) === $requirements['images_per_package']
                && $speakingAudio === $requirements['speaking_audio_per_package'];
            if (!$packageReady) {
                $ready = false;
            }
            $packages[$package] = [
                'speaking' => $speaking,
                'writing' => $writing,
                'speaking_audio' => $speakingAudio,
                'images' => count($images),
                'ready' => $packageReady,
            ];
        }

        return ['ready' => $ready, 'packages' => $packages];
    }
}

if (!function_exists('getToeicSwQuestionRow')) {
    function getToeicSwQuestionRow(mysqli $conn, string $testSession, string $section, int $order): ?array {
        ensureToeicSwSchema($conn);
        $stmt = $conn->prepare("SELECT * FROM toeic_sw_test_questions WHERE test_session = ? AND section = ? AND question_order = ? LIMIT 1");
        $stmt->bind_param("ssi", $testSession, $section, $order);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$assignment) {
            return null;
        }

        $table = getToeicSwContentTableForType((string)$assignment['question_type']);
        if (!$table) {
            return $assignment;
        }

        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $questionId = (int)$assignment['question_id'];
        $stmt->bind_param("i", $questionId);
        $stmt->execute();
        $content = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return array_merge($content, $assignment, ['content' => $content]);
    }
}

if (!function_exists('getToeicSwQuestionsForSection')) {
    function getToeicSwQuestionsForSection(mysqli $conn, string $testSession, string $section): array {
        ensureToeicSwSchema($conn);
        $stmt = $conn->prepare("SELECT question_order FROM toeic_sw_test_questions WHERE test_session = ? AND section = ? ORDER BY question_order ASC");
        $stmt->bind_param("ss", $testSession, $section);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $question = getToeicSwQuestionRow($conn, $testSession, $section, (int)$row['question_order']);
            if ($question) {
                $rows[] = $question;
            }
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('getToeicSwSessionInfo')) {
    function getToeicSwSessionInfo(mysqli $conn, int $userId, string $testSession): ?array {
        ensureToeicSwSchema($conn);
        $stmt = $conn->prepare("SELECT * FROM toeic_sw_test_sessions WHERE user_id = ? AND test_session = ? LIMIT 1");
        $stmt->bind_param("is", $userId, $testSession);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('getToeicSwTestResults')) {
    function getToeicSwTestResults(mysqli $conn, int $userId, string $testSession): ?array {
        ensureToeicSwSchema($conn);
        $stmt = $conn->prepare("SELECT * FROM toeic_sw_test_results WHERE user_id = ? AND test_session = ? LIMIT 1");
        $stmt->bind_param("is", $userId, $testSession);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            $row['level'] = getToeicSwLevel((int)$row['total_score']);
        }
        return $row;
    }
}

if (!function_exists('getToeicSwProgressMap')) {
    function getToeicSwProgressMap(mysqli $conn, string $testSession, string $section): array {
        ensureToeicSwSchema($conn);
        $stmt = $conn->prepare("SELECT question_order, user_answer, source_path FROM toeic_sw_test_questions WHERE test_session = ? AND section = ? ORDER BY question_order ASC");
        $stmt->bind_param("ss", $testSession, $section);
        $stmt->execute();
        $result = $stmt->get_result();
        $progress = [];
        while ($row = $result->fetch_assoc()) {
            $progress[(int)$row['question_order']] = trim((string)($row['user_answer'] ?? $row['source_path'] ?? '')) !== '';
        }
        $stmt->close();
        return $progress;
    }
}

if (!function_exists('toeicSwJson')) {
    function toeicSwJson($value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('toeicSwMediaUrl')) {
    function toeicSwMediaUrl(?string $path): string {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $segments = array_values(array_filter(explode('/', $normalized), 'strlen'));
        return '../' . implode('/', array_map('rawurlencode', $segments));
    }
}
?>
