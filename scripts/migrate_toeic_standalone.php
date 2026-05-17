<?php
require_once __DIR__ . '/../includes/config.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable. Configure local DB credentials in .env or local environment variables before running TOEIC standalone migration.\n");
    exit(1);
}

function runSql(mysqli $conn, string $sql, string $label, bool $dryRun): void {
    echo ($dryRun ? '[DRY] ' : '[RUN] ') . $label . PHP_EOL;
    if ($dryRun) {
        return;
    }
    if (!$conn->query($sql)) {
        echo "  ERROR: " . $conn->error . PHP_EOL;
    }
}

echo "=== TOEIC STANDALONE MIGRATION ===\n";
echo $dryRun ? "Mode: DRY RUN\n" : "Mode: APPLY\n";

$statements = [
    'users' => "
        CREATE TABLE IF NOT EXISTS users (
            id_user INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(191) NOT NULL UNIQUE,
            email VARCHAR(191) NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(191) NOT NULL,
            role ENUM('admin','student') NOT NULL DEFAULT 'student',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'sessions' => "
        CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            access INT(10) UNSIGNED NULL,
            data TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'site_settings defaults' => "
        CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(191) PRIMARY KEY,
            setting_value TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'user_purchases' => "
        CREATE TABLE IF NOT EXISTS user_purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            exam_type VARCHAR(50) NOT NULL,
            status ENUM('active','expired','revoked','used') NOT NULL DEFAULT 'active',
            transaction_ref VARCHAR(100) NULL,
            purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_user_exam_status (user_id, exam_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'payment_transactions' => "
        CREATE TABLE IF NOT EXISTS payment_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            transaction_id VARCHAR(100) NULL,
            order_id VARCHAR(100) NULL,
            test_type VARCHAR(50) NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            payment_type VARCHAR(50) NULL,
            snap_token VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            raw_response LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_status (user_id, status),
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'password_reset_tokens' => "
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(191) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_password_reset_token_hash (token_hash),
            INDEX idx_password_reset_user (user_id),
            INDEX idx_password_reset_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_photos' => "
        CREATE TABLE IF NOT EXISTS toeic_photos (
            id_photo INT AUTO_INCREMENT PRIMARY KEY,
            file_path VARCHAR(255) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_audio' => "
        CREATE TABLE IF NOT EXISTS toeic_audio (
            id_audio INT AUTO_INCREMENT PRIMARY KEY,
            judul VARCHAR(255) NOT NULL,
            part VARCHAR(2) NOT NULL,
            file_path VARCHAR(255) NULL,
            transcript LONGTEXT NULL,
            context TEXT NULL,
            id_photo INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_part (part),
            INDEX idx_photo (id_photo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_teks' => "
        CREATE TABLE IF NOT EXISTS toeic_teks (
            id_teks INT AUTO_INCREMENT PRIMARY KEY,
            judul VARCHAR(255) NOT NULL,
            part VARCHAR(2) NOT NULL,
            text_type VARCHAR(50) NULL,
            isi_teks LONGTEXT NOT NULL,
            isi_teks_2 LONGTEXT NULL,
            isi_teks_3 LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_part (part)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_soal_listening' => "
        CREATE TABLE IF NOT EXISTS toeic_soal_listening (
            id_soal INT AUTO_INCREMENT PRIMARY KEY,
            part VARCHAR(2) NOT NULL,
            nomor_soal INT NOT NULL,
            pertanyaan TEXT NOT NULL,
            opsi_a TEXT NULL,
            opsi_b TEXT NULL,
            opsi_c TEXT NULL,
            opsi_d TEXT NULL,
            jawaban_benar VARCHAR(20) NOT NULL,
            explanation LONGTEXT NULL,
            id_audio INT NULL,
            question_type VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_part_nomor (part, nomor_soal),
            INDEX idx_audio (id_audio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_soal_reading' => "
        CREATE TABLE IF NOT EXISTS toeic_soal_reading (
            id_soal INT AUTO_INCREMENT PRIMARY KEY,
            part VARCHAR(2) NOT NULL,
            nomor_soal INT NOT NULL,
            pertanyaan TEXT NOT NULL,
            opsi_a TEXT NULL,
            opsi_b TEXT NULL,
            opsi_c TEXT NULL,
            opsi_d TEXT NULL,
            jawaban_benar VARCHAR(20) NOT NULL,
            explanation LONGTEXT NULL,
            id_teks INT NULL,
            question_type VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_part_nomor (part, nomor_soal),
            INDEX idx_teks (id_teks)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_test_sessions' => "
        CREATE TABLE IF NOT EXISTS toeic_test_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_session VARCHAR(191) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            current_section VARCHAR(30) NOT NULL DEFAULT 'listening',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            practice_mode TINYINT(1) NOT NULL DEFAULT 0,
            target_part VARCHAR(2) NULL,
            checkout_source VARCHAR(40) NULL,
            checkout_reference VARCHAR(120) NULL,
            listening_raw INT NULL,
            listening_scaled INT NULL,
            reading_raw INT NULL,
            reading_scaled INT NULL,
            total_score INT NULL,
            cefr_level VARCHAR(20) NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_test_questions' => "
        CREATE TABLE IF NOT EXISTS toeic_test_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_session VARCHAR(191) NOT NULL,
            user_id INT NOT NULL,
            question_id INT NOT NULL,
            question_type VARCHAR(50) NULL,
            section VARCHAR(30) NOT NULL,
            part VARCHAR(2) NOT NULL,
            question_order INT NOT NULL,
            stimulus_group_id VARCHAR(100) NULL,
            group_order INT NULL,
            user_answer VARCHAR(255) NULL,
            is_correct DECIMAL(5,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_session_question (test_session, question_id),
            INDEX idx_session_section_order (test_session, section, question_order),
            INDEX idx_user_part (user_id, part)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_test_results' => "
        CREATE TABLE IF NOT EXISTS toeic_test_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_session VARCHAR(191) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            listening_raw INT NOT NULL DEFAULT 0,
            listening_scaled INT NOT NULL DEFAULT 5,
            reading_raw INT NOT NULL DEFAULT 0,
            reading_scaled INT NOT NULL DEFAULT 5,
            total_score INT NOT NULL DEFAULT 10,
            cefr_level VARCHAR(20) NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_completed (user_id, completed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'toeic_score_conversion' => "
        CREATE TABLE IF NOT EXISTS toeic_score_conversion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section VARCHAR(30) NOT NULL,
            raw_score INT NOT NULL,
            scaled_score INT NOT NULL,
            UNIQUE KEY uniq_section_raw (section, raw_score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'vouchers' => "
        CREATE TABLE IF NOT EXISTS vouchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            exam_type VARCHAR(50) NOT NULL,
            status ENUM('active','used','disabled','expired') DEFAULT 'active',
            created_by INT NOT NULL,
            redeemed_by INT NULL,
            redeemed_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            batch_id VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code (code),
            INDEX idx_status (status),
            INDEX idx_exam_type (exam_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'audio_playback_log' => "
        CREATE TABLE IF NOT EXISTS audio_playback_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            test_session VARCHAR(191) NOT NULL,
            audio_id VARCHAR(191) NOT NULL,
            token VARCHAR(128) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            request_count INT NOT NULL DEFAULT 0,
            played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_token_at TIMESTAMP NULL DEFAULT NULL,
            token_expires_at TIMESTAMP NULL DEFAULT NULL,
            started_at TIMESTAMP NULL DEFAULT NULL,
            completed_at TIMESTAMP NULL DEFAULT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            INDEX idx_user_audio (user_id, test_session, audio_id),
            INDEX idx_token (token),
            INDEX idx_played_at (played_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'proctoring_settings' => "
        CREATE TABLE IF NOT EXISTS proctoring_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(191) NOT NULL UNIQUE,
            setting_value TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'proctoring_sessions' => "
        CREATE TABLE IF NOT EXISTS proctoring_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            test_session VARCHAR(191) NOT NULL UNIQUE,
            test_format VARCHAR(50) NOT NULL,
            voucher_code VARCHAR(50) NULL,
            camera_granted TINYINT(1) NOT NULL DEFAULT 0,
            microphone_granted TINYINT(1) NOT NULL DEFAULT 0,
            integrity_score INT NOT NULL DEFAULT 100,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            review_status VARCHAR(30) NULL DEFAULT NULL,
            termination_reason VARCHAR(100) NULL,
            ended_by VARCHAR(30) NULL,
            notes TEXT NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ended_at TIMESTAMP NULL DEFAULT NULL,
            last_heartbeat_at TIMESTAMP NULL DEFAULT NULL,
            sync_failures INT NOT NULL DEFAULT 0,
            INDEX idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'proctoring_events' => "
        CREATE TABLE IF NOT EXISTS proctoring_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'low',
            event_time INT NOT NULL DEFAULT 0,
            metadata LONGTEXT NULL,
            snapshot_path VARCHAR(255) NULL,
            ai_score_impact INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_created (session_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'proctoring_ai_logs' => "
        CREATE TABLE IF NOT EXISTS proctoring_ai_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            request_payload LONGTEXT NULL,
            response_payload LONGTEXT NULL,
            response_time_ms INT NOT NULL DEFAULT 0,
            window_score INT NOT NULL DEFAULT 0,
            action_taken VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_created (session_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'exam_anomalies' => "
        CREATE TABLE IF NOT EXISTS exam_anomalies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            test_session VARCHAR(191) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            details LONGTEXT NULL,
            occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_occurred (test_session, occurred_at),
            INDEX idx_user_occurred (user_id, occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

foreach ($statements as $label => $sql) {
    runSql($conn, $sql, $label, $dryRun);
}

$alters = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(191) NULL",
    "ALTER TABLE toeic_audio ADD COLUMN IF NOT EXISTS id_photo INT NULL",
    "ALTER TABLE toeic_audio ADD COLUMN IF NOT EXISTS transcript LONGTEXT NULL",
    "ALTER TABLE toeic_teks ADD COLUMN IF NOT EXISTS part VARCHAR(2) NOT NULL DEFAULT '7'",
    "ALTER TABLE toeic_teks ADD COLUMN IF NOT EXISTS text_type VARCHAR(50) NULL",
    "ALTER TABLE toeic_teks ADD COLUMN IF NOT EXISTS isi_teks_2 LONGTEXT NULL",
    "ALTER TABLE toeic_teks ADD COLUMN IF NOT EXISTS isi_teks_3 LONGTEXT NULL",
    "ALTER TABLE toeic_soal_listening ADD COLUMN IF NOT EXISTS question_type VARCHAR(50) NULL",
    "ALTER TABLE toeic_soal_reading ADD COLUMN IF NOT EXISTS question_type VARCHAR(50) NULL",
    "ALTER TABLE toeic_test_sessions ADD COLUMN IF NOT EXISTS practice_mode TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE toeic_test_sessions ADD COLUMN IF NOT EXISTS target_part VARCHAR(2) NULL",
    "ALTER TABLE toeic_test_sessions ADD COLUMN IF NOT EXISTS checkout_source VARCHAR(40) NULL",
    "ALTER TABLE toeic_test_sessions ADD COLUMN IF NOT EXISTS checkout_reference VARCHAR(120) NULL",
    "ALTER TABLE toeic_test_questions ADD COLUMN IF NOT EXISTS question_type VARCHAR(50) NULL",
    "ALTER TABLE toeic_test_questions ADD COLUMN IF NOT EXISTS stimulus_group_id VARCHAR(100) NULL",
    "ALTER TABLE toeic_test_questions ADD COLUMN IF NOT EXISTS group_order INT NULL",
    "ALTER TABLE toeic_test_questions ADD COLUMN IF NOT EXISTS is_correct DECIMAL(5,2) NULL",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS token VARCHAR(128) NULL",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'pending'",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS request_count INT NOT NULL DEFAULT 0",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS last_token_at TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS token_expires_at TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS started_at TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL",
    "ALTER TABLE audio_playback_log ADD COLUMN IF NOT EXISTS user_agent TEXT NULL",
];

foreach ($alters as $sql) {
    runSql($conn, $sql, $sql, $dryRun);
}

$settingStatements = [
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('website_title', 'OSGLI TOEIC') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('name_toeic', 'TOEIC Listening & Reading') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('price_toeic', '175000') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('price_toeic_retail', '175000') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('price_toeic_partner', '175000') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('price_toeic_bulk', '175000') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('price_toeic_sw_retail', '175000') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('price_toeic_sw_partner', '175000') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('price_toeic_sw_bulk', '175000') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('payment_mode', 'direct_bank') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('bank_name', 'GOPAY') ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '', VALUES(setting_value), setting_value)",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('bank_account_number', '+62856-4359-7072') ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '', VALUES(setting_value), setting_value)",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('bank_account_holder', 'Leonardus Bayu') ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '', VALUES(setting_value), setting_value)",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('bank_transfer_instructions', 'Transfer sesuai nominal invoice ke GOPAY +62856-4359-7072 a.n. Leonardus Bayu, lalu kirim bukti pembayaran ke admin untuk aktivasi paket.') ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '', VALUES(setting_value), setting_value)",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('forgot_password_enabled', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('features_toeic', '[\"Full simulation 200 soal\",\"Practice mode Part 1-7\",\"Score report TOEIC\",\"Weakness map per part\"]') ON DUPLICATE KEY UPDATE setting_value = setting_value",
];

foreach ($settingStatements as $sql) {
    runSql($conn, $sql, 'site setting seed', $dryRun);
}

if (!$dryRun) {
    $adminCount = $conn->query("SELECT COUNT(*) AS total FROM users WHERE username = 'admin'")->fetch_assoc();
    if ((int)($adminCount['total'] ?? 0) === 0) {
        $password = password_hash('password', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES ('admin', ?, 'System Admin', 'admin')");
        $stmt->bind_param("s", $password);
        $stmt->execute();
        $stmt->close();
    }

    $studentCount = $conn->query("SELECT COUNT(*) AS total FROM users WHERE username = 'student'")->fetch_assoc();
    if ((int)($studentCount['total'] ?? 0) === 0) {
        $password = password_hash('password', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES ('student', ?, 'Demo Student', 'student')");
        $stmt->bind_param("s", $password);
        $stmt->execute();
        $stmt->close();
    }

    $rows = $conn->query("SELECT COUNT(*) AS total FROM toeic_score_conversion")->fetch_assoc();
    if ((int)($rows['total'] ?? 0) === 0) {
        echo "[RUN] seeding toeic_score_conversion fallback rows\n";
        foreach (['listening', 'reading'] as $section) {
            for ($raw = 0; $raw <= 100; $raw++) {
                $scaled = (int)min(495, max(5, round(5 + ($raw * 490 / 100))));
                $stmt = $conn->prepare("INSERT INTO toeic_score_conversion (section, raw_score, scaled_score) VALUES (?, ?, ?)");
                $stmt->bind_param("sii", $section, $raw, $scaled);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

echo "Migration complete.\n";
