<?php
/**
 * Idempotent schema guard for TOEIC learning pathway tables.
 */

if (!function_exists('toeicEnsureLearningSchema')) {
    function toeicEnsureLearningSchema(mysqli $conn): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        toeicLearningExec($conn, "
            CREATE TABLE IF NOT EXISTS learning_curriculum (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                test_session VARCHAR(120) NOT NULL,
                weakness_analysis LONGTEXT NULL,
                syllabus LONGTEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'generating',
                ai_provider VARCHAR(120) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_session (user_id, test_session),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        toeicLearningExec($conn, "
            CREATE TABLE IF NOT EXISTS learning_modules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                curriculum_id INT NOT NULL,
                module_order INT NOT NULL DEFAULT 1,
                title VARCHAR(255) NOT NULL,
                section VARCHAR(40) NOT NULL DEFAULT 'reading',
                skill_category VARCHAR(160) NULL,
                cefr_level VARCHAR(20) NULL,
                content_html LONGTEXT NULL,
                exercises_json LONGTEXT NULL,
                estimated_minutes INT NOT NULL DEFAULT 45,
                status VARCHAR(30) NOT NULL DEFAULT 'locked',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_curriculum_order (curriculum_id, module_order),
                INDEX idx_curriculum (curriculum_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        toeicLearningExec($conn, "
            CREATE TABLE IF NOT EXISTS learning_exercises (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id INT NOT NULL,
                exercise_order INT NOT NULL DEFAULT 1,
                type VARCHAR(60) NOT NULL DEFAULT 'multiple_choice',
                question_html LONGTEXT NOT NULL,
                options_json LONGTEXT NULL,
                correct_answer TEXT NULL,
                explanation_html LONGTEXT NULL,
                points INT NOT NULL DEFAULT 10,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_module_order (module_id, exercise_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        toeicLearningExec($conn, "
            CREATE TABLE IF NOT EXISTS learning_progress (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                module_id INT NOT NULL,
                score DECIMAL(6,2) NOT NULL DEFAULT 0,
                attempts INT NOT NULL DEFAULT 0,
                answers_json LONGTEXT NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_module (user_id, module_id),
                INDEX idx_user (user_id),
                INDEX idx_module (module_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        toeicLearningEnsureColumn($conn, 'learning_curriculum', 'weakness_analysis', 'LONGTEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_curriculum', 'syllabus', 'LONGTEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_curriculum', 'status', "VARCHAR(30) NOT NULL DEFAULT 'generating'");
        toeicLearningEnsureColumn($conn, 'learning_curriculum', 'ai_provider', 'VARCHAR(120) NULL');

        toeicLearningEnsureColumn($conn, 'learning_modules', 'skill_category', 'VARCHAR(160) NULL');
        toeicLearningEnsureColumn($conn, 'learning_modules', 'cefr_level', 'VARCHAR(20) NULL');
        toeicLearningEnsureColumn($conn, 'learning_modules', 'content_html', 'LONGTEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_modules', 'exercises_json', 'LONGTEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_modules', 'estimated_minutes', 'INT NOT NULL DEFAULT 45');
        toeicLearningEnsureColumn($conn, 'learning_modules', 'status', "VARCHAR(30) NOT NULL DEFAULT 'locked'");

        toeicLearningEnsureColumn($conn, 'learning_exercises', 'options_json', 'LONGTEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_exercises', 'correct_answer', 'TEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_exercises', 'explanation_html', 'LONGTEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_exercises', 'points', 'INT NOT NULL DEFAULT 10');

        toeicLearningEnsureColumn($conn, 'learning_progress', 'score', 'DECIMAL(6,2) NOT NULL DEFAULT 0');
        toeicLearningEnsureColumn($conn, 'learning_progress', 'attempts', 'INT NOT NULL DEFAULT 0');
        toeicLearningEnsureColumn($conn, 'learning_progress', 'answers_json', 'LONGTEXT NULL');
        toeicLearningEnsureColumn($conn, 'learning_progress', 'completed_at', 'TIMESTAMP NULL DEFAULT NULL');
    }
}

if (!function_exists('toeicLearningEnsureColumn')) {
    function toeicLearningEnsureColumn(mysqli $conn, string $table, string $column, string $definition): void {
        $safeTable = str_replace('`', '``', $table);
        $safeColumn = str_replace('`', '``', $column);
        $safeColumnLike = $conn->real_escape_string($column);
        try {
            $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumnLike}'");
            if ($result && $result->num_rows > 0) {
                return;
            }
            toeicLearningExec($conn, "ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeColumn}` {$definition}");
        } catch (Throwable $e) {
            // Best effort only. The caller will surface query errors if a required column is still missing.
        }
    }
}

if (!function_exists('toeicLearningExec')) {
    function toeicLearningExec(mysqli $conn, string $sql): void {
        try {
            $conn->query($sql);
        } catch (Throwable $e) {
            // Keep page responses controlled; downstream prepared statements report any hard failure.
        }
    }
}
