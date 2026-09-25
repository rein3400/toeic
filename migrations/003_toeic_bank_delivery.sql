-- 003_toeic_bank_delivery.sql
-- REQUIRED before activating the bank-delivery code on an existing database.
-- Back up first. Select the TOEIC database. Run explicitly; never on every request.
-- Additive and re-runnable: no question deletion, answer rewrite, history backfill,
-- cohort reservation, signature index, or bulk-dedup/audit tables.
-- The pool-events table IS required: the builder prepares its INSERT each build.

SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_questions ADD COLUMN snap_jawaban_benar CHAR(1) NULL DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_questions' AND COLUMN_NAME = 'snap_jawaban_benar');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_questions ADD COLUMN snap_pertanyaan LONGTEXT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_questions' AND COLUMN_NAME = 'snap_pertanyaan');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_questions ADD COLUMN snap_opsi_a LONGTEXT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_questions' AND COLUMN_NAME = 'snap_opsi_a');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_questions ADD COLUMN snap_opsi_b LONGTEXT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_questions' AND COLUMN_NAME = 'snap_opsi_b');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_questions ADD COLUMN snap_opsi_c LONGTEXT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_questions' AND COLUMN_NAME = 'snap_opsi_c');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_questions ADD COLUMN snap_opsi_d LONGTEXT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_questions' AND COLUMN_NAME = 'snap_opsi_d');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_questions ADD COLUMN original_question_id INT NULL DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_questions' AND COLUMN_NAME = 'original_question_id');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;

SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_sessions ADD COLUMN fallback_count INT NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_sessions' AND COLUMN_NAME = 'fallback_count');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_sessions ADD COLUMN fallback_breakdown LONGTEXT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_sessions' AND COLUMN_NAME = 'fallback_breakdown');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_test_sessions ADD COLUMN short_filled_count INT NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_test_sessions' AND COLUMN_NAME = 'short_filled_count');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;

CREATE TABLE IF NOT EXISTS toeic_pool_exhausted_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_session VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    section VARCHAR(20) NOT NULL,
    part VARCHAR(2) NOT NULL,
    target_count INT NOT NULL,
    drawn_count INT NOT NULL,
    fallback_tier VARCHAR(32) NOT NULL,
    seen_window_size INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pool_user (user_id, created_at),
    INDEX idx_pool_part (section, part, created_at)
) ENGINE=InnoDB;

SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_soal_listening ADD INDEX idx_listening_part_id (part, id_soal)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_soal_listening' AND INDEX_NAME = 'idx_listening_part_id');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
SET @toeic_ddl := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE toeic_soal_reading ADD INDEX idx_reading_part_id (part, id_soal)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toeic_soal_reading' AND INDEX_NAME = 'idx_reading_part_id');
PREPARE toeic_stmt FROM @toeic_ddl; EXECUTE toeic_stmt; DEALLOCATE PREPARE toeic_stmt;
