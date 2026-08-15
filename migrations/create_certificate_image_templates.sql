CREATE TABLE IF NOT EXISTS certificate_image_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    institution_key VARCHAR(40) NOT NULL DEFAULT 'halokak',
    image_path VARCHAR(500) NOT NULL,
    layout_json TEXT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE certificate_image_templates
    ADD COLUMN IF NOT EXISTS layout_json TEXT NULL AFTER image_path;

ALTER TABLE certificate_image_templates
    ADD COLUMN IF NOT EXISTS institution_key VARCHAR(40) NOT NULL DEFAULT 'halokak' AFTER name;

UPDATE certificate_image_templates SET institution_key = 'halokak' WHERE institution_key IS NULL OR institution_key = '';

UPDATE certificate_image_templates
SET name = 'Instansi Halokak'
WHERE institution_key = 'halokak'
  AND (name = 'Uploaded Certificate' OR name LIKE 'Certificate Image %');
