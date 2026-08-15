<?php

/**
 * Certificate templates available to administrators.
 *
 * Adapted from osee (rein3400/itp branch osee, includes/certificate_templates.php)
 * with brand text retitled from "TOEFL ITP" to "TOEIC". Template keys are suffixed
 * with "_toeic" to avoid namespace collision if osee is ever merged in.
 */
function getCertificateTemplates(): array
{
    return [
        'professional_toeic' => [
            'name' => 'Professional TOEIC',
            'description' => 'Current score-report style with a formal academic layout.',
            'icon' => 'fa-graduation-cap',
        ],
        'classic_achievement_toeic' => [
            'name' => 'Image Certificate',
            'description' => 'Upload a certificate image and overlay student/result data as HTML.',
            'icon' => 'fa-image',
        ],
    ];
}

function getCertificateImageInstitutions(): array
{
    return [
        'halokak' => [
            'name' => 'Instansi Halokak',
        ],
        'osee' => [
            'name' => 'Osee',
        ],
    ];
}

function normalizeCertificateImageInstitution(?string $institution): string
{
    $institutions = getCertificateImageInstitutions();
    $institution = strtolower(trim((string)$institution));

    return isset($institutions[$institution]) ? $institution : 'halokak';
}

function getCertificateImageInstitutionName(?string $institution): string
{
    $institution = normalizeCertificateImageInstitution($institution);
    $institutions = getCertificateImageInstitutions();

    return $institutions[$institution]['name'];
}

/**
 * Only return template keys that are implemented by both browser and PDF renderers.
 */
function normalizeCertificateTemplate(?string $template): string
{
    $templates = getCertificateTemplates();

    return isset($templates[$template]) ? $template : 'professional_toeic';
}

/**
 * Keep administrator-provided colors safe for use in inline CSS.
 */
function normalizeCertificateColor(?string $color, string $fallback): string
{
    return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $fallback;
}

/**
 * Allow only the simple formatting advertised by the certificate settings form.
 */
function formatCertificateRichText(?string $value): string
{
    $escaped = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/&lt;br\s*\/?&gt;/i', '<br>', $escaped);

    return str_ireplace(
        ['&lt;strong&gt;', '&lt;/strong&gt;'],
        ['<strong>', '</strong>'],
        $escaped
    );
}

function ensureCertificateImageTemplatesTable(): void
{
    global $conn;
    if (!$conn instanceof mysqli) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS certificate_image_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            institution_key VARCHAR(40) NOT NULL DEFAULT 'halokak',
            image_path VARCHAR(500) NOT NULL,
            layout_json TEXT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $col = $conn->query("SHOW COLUMNS FROM certificate_image_templates LIKE 'layout_json'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE certificate_image_templates ADD COLUMN layout_json TEXT NULL AFTER image_path");
    }

    $col = $conn->query("SHOW COLUMNS FROM certificate_image_templates LIKE 'institution_key'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE certificate_image_templates ADD COLUMN institution_key VARCHAR(40) NOT NULL DEFAULT 'halokak' AFTER name");
    }

    $conn->query("UPDATE certificate_image_templates SET institution_key = 'halokak' WHERE institution_key IS NULL OR institution_key = ''");
    $conn->query("
        UPDATE certificate_image_templates
        SET name = 'Instansi Halokak'
        WHERE institution_key = 'halokak'
          AND (name = 'Uploaded Certificate' OR name LIKE 'Certificate Image %')
    ");
}

function getDefaultCertificateImageLayout(): array
{
    return [
        'name_top' => 34,
        'name_left' => 14,
        'name_right' => 14,
        'name_size' => 44,
        'score_top' => 51,
        'score_left' => 20,
        'score_right' => 20,
        'score_size' => 16,
        'meta_left' => 9,
        'meta_bottom' => 8,
        'qr_right' => 9,
        'qr_bottom' => 8,
        'notice_bottom' => 1.8,
        'panel_opacity' => 0.72,
    ];
}

function normalizeCertificateImageLayout($layout): array
{
    $defaults = getDefaultCertificateImageLayout();
    if (is_string($layout) && trim($layout) !== '') {
        $decoded = json_decode($layout, true);
        $layout = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($layout)) {
        $layout = [];
    }

    $normalized = $defaults;
    foreach ($defaults as $key => $fallback) {
        if (!array_key_exists($key, $layout) || !is_numeric($layout[$key])) {
            continue;
        }
        $value = (float)$layout[$key];
        if ($key === 'panel_opacity') {
            $normalized[$key] = max(0, min(1, $value));
        } elseif (substr($key, -5) === '_size') {
            $normalized[$key] = max(8, min(90, $value));
        } else {
            $normalized[$key] = max(0, min(100, $value));
        }
    }

    return $normalized;
}

function buildCertificateImageLayoutStyle(array $layout): string
{
    $layout = normalizeCertificateImageLayout($layout);
    $pairs = [];
    foreach ($layout as $key => $value) {
        $cssKey = '--img-cert-' . str_replace('_', '-', $key);
        $pairs[] = $cssKey . ':' . rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.') . ';';
    }
    return implode('', $pairs);
}

function getCertificateImageTemplates(?string $institutionKey = null): array
{
    global $conn;
    ensureCertificateImageTemplatesTable();

    if ($institutionKey !== null) {
        $institutionKey = normalizeCertificateImageInstitution($institutionKey);
        $stmt = $conn->prepare("SELECT * FROM certificate_image_templates WHERE institution_key = ? ORDER BY is_default DESC, created_at DESC, id DESC");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("s", $institutionKey);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    $result = $conn->query("SELECT * FROM certificate_image_templates ORDER BY institution_key ASC, is_default DESC, created_at DESC, id DESC");
    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function getDefaultCertificateImageTemplate(?string $institutionKey = null): ?array
{
    global $conn;
    ensureCertificateImageTemplatesTable();

    if ($institutionKey !== null) {
        $institutionKey = normalizeCertificateImageInstitution($institutionKey);
        $stmt = $conn->prepare("SELECT * FROM certificate_image_templates WHERE institution_key = ? ORDER BY is_default DESC, created_at DESC, id DESC LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("s", $institutionKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    $result = $conn->query("SELECT * FROM certificate_image_templates ORDER BY is_default DESC, created_at DESC, id DESC LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc();
}

function getCertificateImageTemplateById(int $id): ?array
{
    global $conn;
    ensureCertificateImageTemplatesTable();

    $stmt = $conn->prepare("SELECT * FROM certificate_image_templates WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function saveCertificateImageTemplate(string $name, string $imagePath, bool $makeDefault, ?array $layout = null, string $institutionKey = 'halokak'): bool
{
    global $conn;
    ensureCertificateImageTemplatesTable();

    $institutionKey = normalizeCertificateImageInstitution($institutionKey);
    if (trim($name) === '') {
        $name = getCertificateImageInstitutionName($institutionKey);
    }

    if ($makeDefault) {
        $stmt = $conn->prepare("UPDATE certificate_image_templates SET is_default = 0 WHERE institution_key = ?");
        if ($stmt) {
            $stmt->bind_param("s", $institutionKey);
            $stmt->execute();
            $stmt->close();
        }
    }

    $isDefault = $makeDefault ? 1 : 0;
    $layoutJson = json_encode(normalizeCertificateImageLayout($layout ?? []));
    $stmt = $conn->prepare("INSERT INTO certificate_image_templates (name, institution_key, image_path, layout_json, is_default) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssssi", $name, $institutionKey, $imagePath, $layoutJson, $isDefault);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && !$makeDefault) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM certificate_image_templates WHERE institution_key = ? AND is_default = 1");
        $countRow = ['c' => 0];
        if ($stmt) {
            $stmt->bind_param("s", $institutionKey);
            $stmt->execute();
            $countRow = $stmt->get_result()->fetch_assoc() ?: ['c' => 0];
            $stmt->close();
        }
        if ((int)($countRow['c'] ?? 0) === 0) {
            $stmt = $conn->prepare("UPDATE certificate_image_templates SET is_default = 1 WHERE institution_key = ? AND image_path = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ss", $institutionKey, $imagePath);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    return $ok;
}

function setDefaultCertificateImageTemplate(int $id): bool
{
    global $conn;
    ensureCertificateImageTemplatesTable();

    $template = getCertificateImageTemplateById($id);
    if (!$template) {
        return false;
    }
    $institutionKey = normalizeCertificateImageInstitution($template['institution_key'] ?? 'halokak');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE certificate_image_templates SET is_default = 0 WHERE institution_key = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed');
        }
        $stmt->bind_param("s", $institutionKey);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE certificate_image_templates SET is_default = 1 WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed');
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        $conn->commit();
        return $ok;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

function deleteCertificateImageTemplate(int $id, string $baseDir): bool
{
    global $conn;
    ensureCertificateImageTemplatesTable();

    $template = getCertificateImageTemplateById($id);
    if (!$template) {
        return false;
    }

    $imagePath = (string)($template['image_path'] ?? '');
    $wasDefault = (int)($template['is_default'] ?? 0) === 1;
    $institutionKey = normalizeCertificateImageInstitution($template['institution_key'] ?? 'halokak');

    $stmt = $conn->prepare("DELETE FROM certificate_image_templates WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return false;
    }

    if ($imagePath !== '') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM certificate_image_templates WHERE image_path = ?");
        if ($stmt) {
            $stmt->bind_param("s", $imagePath);
            $stmt->execute();
            $countRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int)($countRow['c'] ?? 0) === 0) {
                $absolutePath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($imagePath, '/\\');
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }
    }

    if ($wasDefault) {
        $fallback = getDefaultCertificateImageTemplate($institutionKey);
        if ($fallback && (int)($fallback['is_default'] ?? 0) !== 1) {
            setDefaultCertificateImageTemplate((int)$fallback['id']);
        }
    }

    return true;
}

/**
 * Generate SVG polygon points for a scalloped seal/medal shape.
 */
function generateSealPolygonPoints(int $bumps = 24, float $outerR = 90, float $innerR = 78, float $cx = 100, float $cy = 100): string
{
    $points = '';
    $total = $bumps * 2;
    for ($i = 0; $i < $total; $i++) {
        $angle = deg2rad($i * 360 / $total - 90);
        $r = ($i % 2 === 0) ? $outerR : $innerR;
        $x = round($cx + $r * cos($angle), 1);
        $y = round($cy + $r * sin($angle), 1);
        $points .= $x . ',' . $y . ' ';
    }
    return trim($points);
}
