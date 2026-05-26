<?php
/**
 * Email verification helpers for TOEIC accounts.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db_utils.php';
require_once __DIR__ . '/password_reset_helper.php';

if (!function_exists('toeicEmailVerificationEnabled')) {
    function toeicEmailVerificationEnabled(): bool {
        return getSiteSetting('email_verification_enabled', '1') === '1';
    }
}

if (!function_exists('toeicEnsureEmailVerificationSchema')) {
    function toeicEnsureEmailVerificationSchema(mysqli $conn): void {
        toeicEnsureUserEmailColumn($conn);
        try {
            if (!checkColumnExists($conn, 'users', 'email_verified_at')) {
                $conn->query("ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email");
            }
        } catch (Throwable $e) {
            error_log('Unable to ensure users.email_verified_at: ' . $e->getMessage());
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS email_verification_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                email VARCHAR(191) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_email_verification_token_hash (token_hash),
                INDEX idx_email_verification_user (user_id),
                INDEX idx_email_verification_email (email),
                INDEX idx_email_verification_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('toeicGetUserEmailVerificationState')) {
    function toeicGetUserEmailVerificationState(mysqli $conn, int $userId): array {
        toeicEnsureEmailVerificationSchema($conn);
        $idCol = getUsersIdColumn($conn);

        $stmt = $conn->prepare("SELECT {$idCol} AS user_id, username, full_name, email, email_verified_at, role FROM users WHERE {$idCol} = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $email = strtolower(trim((string)($user['email'] ?? '')));
        $isVerified = $email !== '' && !empty($user['email_verified_at']);

        return [
            'user_id' => (int)($user['user_id'] ?? $userId),
            'username' => (string)($user['username'] ?? ''),
            'full_name' => (string)($user['full_name'] ?? ''),
            'email' => $email,
            'email_verified_at' => $user['email_verified_at'] ?? null,
            'role' => (string)($user['role'] ?? ''),
            'has_email' => $email !== '',
            'is_verified' => $isVerified,
        ];
    }
}

if (!function_exists('toeicUserNeedsEmailVerification')) {
    function toeicUserNeedsEmailVerification(mysqli $conn, int $userId): bool {
        if (!toeicEmailVerificationEnabled()) {
            return false;
        }

        $state = toeicGetUserEmailVerificationState($conn, $userId);
        if (($state['role'] ?? '') === 'admin') {
            return false;
        }

        return !$state['is_verified'];
    }
}

if (!function_exists('toeicEmailVerificationRateLimitStatus')) {
    function toeicEmailVerificationRateLimitStatus(mysqli $conn, string $email): array {
        $email = strtolower(trim($email));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $windowMinutes = toeicPasswordResetIntSetting('email_verification_rate_window_minutes', 60, 10, 1440);
        $emailLimit = toeicPasswordResetIntSetting('email_verification_email_limit', 5, 1, 100);
        $ipLimit = toeicPasswordResetIntSetting('email_verification_ip_limit', 20, 1, 500);

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM email_verification_tokens
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
                'reason' => 'email',
                'count' => $emailCount,
                'limit' => $emailLimit,
                'window_minutes' => $windowMinutes,
            ];
        }

        if ($ip !== '') {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM email_verification_tokens
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
                    'reason' => 'ip',
                    'count' => $ipCount,
                    'limit' => $ipLimit,
                    'window_minutes' => $windowMinutes,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'window_minutes' => $windowMinutes,
        ];
    }
}

if (!function_exists('toeicSendEmailVerification')) {
    function toeicSendEmailVerification(string $email, string $name, string $verificationLink): bool {
        $fromEmail = trim((string)getSiteSetting('password_reset_from_email', ''));
        $fromName = trim((string)getSiteSetting('password_reset_from_name', 'TOEIC Support'));
        $subject = 'Verifikasi email akun TOEIC';
        $body = "Halo {$name},\n\n"
            . "Terima kasih sudah mendaftar akun TOEIC.\n\n"
            . "Buka link berikut untuk memverifikasi email akun Anda:\n{$verificationLink}\n\n"
            . "Jika Anda tidak membuat akun TOEIC, abaikan email ini.";

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
            error_log("Email verification mail failed for {$email}.");
        }
        return $sent;
    }
}

if (!function_exists('toeicCreateEmailVerification')) {
    function toeicCreateEmailVerification(mysqli $conn, int $userId): bool {
        if (!toeicEmailVerificationEnabled()) {
            return false;
        }

        toeicEnsureEmailVerificationSchema($conn);
        $state = toeicGetUserEmailVerificationState($conn, $userId);
        if (!$state['has_email'] || !filter_var($state['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($state['is_verified']) {
            return true;
        }

        $conn->query("DELETE FROM email_verification_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");

        $rateLimit = toeicEmailVerificationRateLimitStatus($conn, $state['email']);
        if (!$rateLimit['allowed']) {
            error_log('Email verification rate-limited by ' . $rateLimit['reason']);
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiryMinutes = toeicPasswordResetIntSetting('email_verification_expiry_minutes', 1440, 10, 10080);
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

        $stmt = $conn->prepare("
            INSERT INTO email_verification_tokens (user_id, email, token_hash, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $email = $state['email'];
        $stmt->bind_param('isssss', $userId, $email, $tokenHash, $expiresAt, $ip, $agent);
        $stmt->execute();
        $stmt->close();

        $verificationLink = toeicPasswordResetBaseUrl() . '/verify_email.php?token=' . urlencode($token);
        return toeicSendEmailVerification($email, $state['full_name'] ?: 'Student', $verificationLink);
    }
}

if (!function_exists('toeicConsumeEmailVerification')) {
    function toeicConsumeEmailVerification(mysqli $conn, string $token): bool {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        toeicEnsureEmailVerificationSchema($conn);
        $idCol = getUsersIdColumn($conn);
        $tokenHash = hash('sha256', $token);

        $stmt = $conn->prepare("
            SELECT evt.id, evt.user_id, evt.email, u.email AS current_email
            FROM email_verification_tokens evt
            JOIN users u ON u.{$idCol} = evt.user_id
            WHERE evt.token_hash = ?
              AND evt.used_at IS NULL
              AND evt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$row || strtolower((string)$row['email']) !== strtolower((string)$row['current_email'])) {
            return false;
        }

        $conn->begin_transaction();
        try {
            $userId = (int)$row['user_id'];
            $tokenId = (int)$row['id'];

            $stmt = $conn->prepare("UPDATE users SET email_verified_at = NOW() WHERE {$idCol} = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $tokenId);
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

if (!function_exists('toeicRequireVerifiedEmail')) {
    function toeicRequireVerifiedEmail(mysqli $conn): void {
        if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
            return;
        }
        if (!toeicUserNeedsEmailVerification($conn, (int)$_SESSION['user_id'])) {
            return;
        }

        $current = $_SERVER['REQUEST_URI'] ?? '/user/index.php';
        header('Location: /user/verify_email.php?redirect=' . urlencode($current));
        exit();
    }
}
?>
