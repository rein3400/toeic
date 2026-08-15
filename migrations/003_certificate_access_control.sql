-- Certificate access control
-- Adds per-result approval/revocation override + default release mode.
-- Mirrors osee migration: nullable override columns; default mode = automatic.
-- Applies to BOTH R+L (toeic_test_results) and S+W (toeic_sw_test_results) tables.

-- ============================================================
-- R+L results
-- ============================================================
ALTER TABLE toeic_test_results
    ADD COLUMN IF NOT EXISTS certificate_status ENUM('approved','revoked') NULL AFTER completed_at,
    ADD COLUMN IF NOT EXISTS certificate_reviewed_by INT NULL AFTER certificate_status,
    ADD COLUMN IF NOT EXISTS certificate_reviewed_at DATETIME NULL AFTER certificate_reviewed_by,
    ADD INDEX IF NOT EXISTS idx_certificate_status (certificate_status);

-- ============================================================
-- S+W results
-- ============================================================
ALTER TABLE toeic_sw_test_results
    ADD COLUMN IF NOT EXISTS certificate_status ENUM('approved','revoked') NULL AFTER completed_at,
    ADD COLUMN IF NOT EXISTS certificate_reviewed_by INT NULL AFTER certificate_status,
    ADD COLUMN IF NOT EXISTS certificate_reviewed_at DATETIME NULL AFTER certificate_reviewed_by,
    ADD INDEX IF NOT EXISTS idx_certificate_status (certificate_status);

-- ============================================================
-- Default site setting: release mode
-- ============================================================
INSERT INTO site_settings (setting_key, setting_value)
VALUES ('cert_release_mode', 'automatic')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
