<?php
/**
 * User password reset helpers for registered-email verification.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db_utils.php';

if (!function_exists('toeicPasswordResetEnabled')) {
    function toeicPasswordResetEnabled(): bool {
        return getSiteSetting('forgot_password_enabled', '1') === '1';
    }
}

if (!function_exists('toeicEnsureUserEmailColumn')) {
    function toeicEnsureUserEmailColumn(mysqli $conn): void {
        try {
            if (!checkColumnExists($conn, 'users', 'email')) {
                $conn->query("ALTER TABLE users ADD COLUMN email VARCHAR(191) NULL AFTER username");
            }
        } catch (Throwable $e) {
            error_log('Unable to ensure users.email: ' . $e->getMessage());
        }
    }
}

if (!function_exists('toeicEnsurePasswordResetSchema')) {
    function toeicEnsurePasswordResetSchema(mysqli $conn): void {
        $conn->query("
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
        ");
    }
}

if (!function_exists('toeicFindUserByRegisteredEmail')) {
    function toeicFindUserByRegisteredEmail(mysqli $conn, string $email): ?array {
        toeicEnsureUserEmailColumn($conn);
        $idCol = getUsersIdColumn($conn);
        $email = strtolower(trim($email));

        $stmt = $conn->prepare("SELECT {$idCol} AS user_id, full_name, username, email FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if ($user) {
            return $user;
        }

        $stmt = $conn->prepare("SELECT {$idCol} AS user_id, full_name, username, email FROM users WHERE LOWER(username) = ? AND username LIKE '%@%' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $user ?: null;
    }
}

if (!function_exists('toeicPasswordResetBaseUrl')) {
    function toeicPasswordResetBaseUrl(): string {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
}

if (!function_exists('toeicPasswordResetSendEmail')) {
    function toeicPasswordResetSendEmail(string $email, string $name, string $resetLink): bool {
        $fromEmail = trim((string)getSiteSetting('password_reset_from_email', ''));
        $fromName = trim((string)getSiteSetting('password_reset_from_name', 'TOEIC Support'));
        $subject = 'Reset password akun TOEIC';
        $body = "Halo {$name},\n\n"
            . "Kami menerima permintaan reset password untuk akun TOEIC Anda.\n\n"
            . "Buka link berikut untuk membuat password baru:\n{$resetLink}\n\n"
            . "Jika Anda tidak meminta reset password, abaikan email ini.";
        $headers = [];
        if ($fromEmail !== '') {
            $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        }
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        if (!function_exists('mail')) {
            error_log("Password reset mail fallback unavailable because PHP mail() is disabled for {$email}.");
            return false;
        }

        $sent = mail($email, $subject, $body, implode("\r\n", $headers));
        if (!$sent) {
            error_log("Password reset mail failed for {$email}. Reset link: {$resetLink}");
        }
        return $sent;
    }
}

if (!function_exists('toeicCreatePasswordReset')) {
    function toeicCreatePasswordReset(mysqli $conn, string $email): bool {
        if (!toeicPasswordResetEnabled()) {
            return false;
        }

        toeicEnsurePasswordResetSchema($conn);
        $user = toeicFindUserByRegisteredEmail($conn, $email);
        if (!$user) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $emailValue = strtolower(trim((string)($user['email'] ?: $user['username'])));
        $expiryMinutes = max(10, min(1440, (int)getSiteSetting('password_reset_expiry_minutes', '60')));
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

        $conn->query("DELETE FROM password_reset_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");

        $stmt = $conn->prepare("
            INSERT INTO password_reset_tokens (user_id, email, token_hash, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $userId = (int)$user['user_id'];
        $stmt->bind_param('isssss', $userId, $emailValue, $tokenHash, $expiresAt, $ip, $agent);
        $stmt->execute();
        $stmt->close();

        $resetLink = toeicPasswordResetBaseUrl() . '/reset_password.php?token=' . urlencode($token);
        return toeicPasswordResetSendEmail($emailValue, (string)($user['full_name'] ?: 'Student'), $resetLink);
    }
}

if (!function_exists('toeicGetValidPasswordReset')) {
    function toeicGetValidPasswordReset(mysqli $conn, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        toeicEnsurePasswordResetSchema($conn);
        $idCol = getUsersIdColumn($conn);
        $tokenHash = hash('sha256', $token);

        $stmt = $conn->prepare("
            SELECT prt.id, prt.user_id, prt.email, u.full_name, u.username
            FROM password_reset_tokens prt
            JOIN users u ON u.{$idCol} = prt.user_id
            WHERE prt.token_hash = ?
              AND prt.used_at IS NULL
              AND prt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('toeicConsumePasswordReset')) {
    function toeicConsumePasswordReset(mysqli $conn, string $token, string $newPassword): bool {
        $reset = toeicGetValidPasswordReset($conn, $token);
        if (!$reset) {
            return false;
        }

        $idCol = getUsersIdColumn($conn);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $userId = (int)$reset['user_id'];
        $resetId = (int)$reset['id'];

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE {$idCol} = ?");
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $resetId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
?>
