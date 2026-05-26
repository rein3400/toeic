<?php
/**
 * User password reset helpers for registered-email verification.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db_utils.php';

$toeicPasswordResetAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($toeicPasswordResetAutoload)) {
    require_once $toeicPasswordResetAutoload;
}

if (!function_exists('toeicPasswordResetEnabled')) {
    function toeicPasswordResetEnabled(): bool {
        return getSiteSetting('forgot_password_enabled', '1') === '1';
    }
}

if (!function_exists('toeicPasswordResetIntSetting')) {
    function toeicPasswordResetIntSetting(string $key, int $default, int $min, int $max): int {
        $value = (int)getSiteSetting($key, (string)$default);
        return max($min, min($max, $value));
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

if (!function_exists('toeicPasswordResetSmtpConfig')) {
    function toeicPasswordResetSmtpConfig(): array {
        return [
            'enabled' => getSiteSetting('password_reset_smtp_enabled', '0') === '1',
            'host' => trim((string)getSiteSetting('password_reset_smtp_host', '')),
            'port' => toeicPasswordResetIntSetting('password_reset_smtp_port', 587, 1, 65535),
            'username' => trim((string)getSiteSetting('password_reset_smtp_username', '')),
            'password' => (string)getSiteSetting('password_reset_smtp_password', ''),
            'encryption' => strtolower(trim((string)getSiteSetting('password_reset_smtp_encryption', 'tls'))),
        ];
    }
}

if (!function_exists('toeicPasswordResetSendSmtpEmail')) {
    function toeicPasswordResetSendSmtpEmail(string $email, string $name, string $subject, string $body, string $fromEmail, string $fromName): bool {
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            error_log('Password reset SMTP requested but PHPMailer is not installed.');
            return false;
        }

        $smtp = toeicPasswordResetSmtpConfig();
        if (!$smtp['enabled'] || $smtp['host'] === '') {
            return false;
        }

        $from = $fromEmail !== '' ? $fromEmail : $smtp['username'];
        if ($from === '') {
            error_log('Password reset SMTP requested but no from email or SMTP username is configured.');
            return false;
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = $smtp['port'];
            $mail->SMTPAuth = $smtp['username'] !== '' || $smtp['password'] !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $smtp['username'];
                $mail->Password = $smtp['password'];
            }
            if (in_array($smtp['encryption'], ['tls', 'ssl'], true)) {
                $mail->SMTPSecure = $smtp['encryption'];
            }

            $mail->setFrom($from, $fromName !== '' ? $fromName : 'TOEIC Support');
            $mail->addAddress($email, $name);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $body;
            $mail->isHTML(false);

            return $mail->send();
        } catch (Throwable $e) {
            error_log('Password reset SMTP failed: ' . $e->getMessage());
            return false;
        }
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

        if (toeicPasswordResetSendSmtpEmail($email, $name, $subject, $body, $fromEmail, $fromName)) {
            return true;
        }

        $headers = [];
        if ($fromEmail !== '') {
            $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        }
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        $sent = mail($email, $subject, $body, implode("\r\n", $headers));
        if (!$sent) {
            error_log("Password reset mail failed for {$email}.");
        }
        return $sent;
    }
}

if (!function_exists('toeicPasswordResetRateLimitStatus')) {
    function toeicPasswordResetRateLimitStatus(mysqli $conn, string $email): array {
        $email = strtolower(trim($email));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $windowMinutes = toeicPasswordResetIntSetting('password_reset_rate_window_minutes', 60, 10, 1440);
        $emailLimit = toeicPasswordResetIntSetting('password_reset_email_limit', 3, 1, 100);
        $ipLimit = toeicPasswordResetIntSetting('password_reset_ip_limit', 10, 1, 500);

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM password_reset_tokens
            WHERE LOWER(email) = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->bind_param('si', $email, $windowMinutes);
        $stmt->execute();
        $emailCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        if ($emailCount >= $emailLimit) {
            return [
                'allowed' => false,
                'rate_limited' => true,
                'reason' => 'email',
                'count' => $emailCount,
                'limit' => $emailLimit,
                'window_minutes' => $windowMinutes,
            ];
        }

        if ($ip !== '') {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM password_reset_tokens
                WHERE ip_address = ?
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->bind_param('si', $ip, $windowMinutes);
            $stmt->execute();
            $ipCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($ipCount >= $ipLimit) {
                return [
                    'allowed' => false,
                    'rate_limited' => true,
                    'reason' => 'ip',
                    'count' => $ipCount,
                    'limit' => $ipLimit,
                    'window_minutes' => $windowMinutes,
                ];
            }
        }

        return [
            'allowed' => true,
            'rate_limited' => false,
            'reason' => null,
            'window_minutes' => $windowMinutes,
        ];
    }
}

if (!function_exists('toeicCreatePasswordReset')) {
    function toeicCreatePasswordReset(mysqli $conn, string $email): bool {
        if (!toeicPasswordResetEnabled()) {
            return false;
        }

        toeicEnsurePasswordResetSchema($conn);
        $conn->query("DELETE FROM password_reset_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");

        $rateLimit = toeicPasswordResetRateLimitStatus($conn, $email);
        if (!$rateLimit['allowed']) {
            error_log('Password reset rate_limited by ' . $rateLimit['reason']);
            return false;
        }

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
