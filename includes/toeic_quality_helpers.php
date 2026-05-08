<?php

if (!function_exists('toeicNormalizeVoucherCode')) {
    function toeicNormalizeVoucherCode($value): string {
        $code = trim((string)$value);
        if ($code === '') {
            return '';
        }

        $code = str_replace("\xC2\xA0", ' ', $code);
        $code = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $code) ?? $code;
        $code = preg_replace('/\s+/u', '', $code) ?? $code;
        $code = strtoupper($code);

        if (preg_match('/^(OSGLI)([A-Z0-9]{5,12})$/', $code, $matches)) {
            $code = $matches[1] . '-' . $matches[2];
        } elseif (preg_match('/^([A-Z]{3,5})([0-9][A-Z0-9]{3,12})$/', $code, $matches)) {
            $code = $matches[1] . '-' . $matches[2];
        }

        return $code;
    }
}

if (!function_exists('toeicDisplayRoundedScore')) {
    function toeicDisplayRoundedScore($value): string {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '-';
        }

        $score = (int)round((float)$value);
        return $score > 0 ? (string)$score : '-';
    }
}

if (!function_exists('toeicSetFlash')) {
    function toeicSetFlash(string $type, string $message): void {
        if (session_status() !== PHP_SESSION_ACTIVE || trim($message) === '') {
            return;
        }

        $allowed = ['success', 'error', 'info', 'warning'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }

        if (!isset($_SESSION['toeic_flash']) || !is_array($_SESSION['toeic_flash'])) {
            $_SESSION['toeic_flash'] = [];
        }

        $_SESSION['toeic_flash'][] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('toeicConsumeFlashes')) {
    function toeicConsumeFlashes(): array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $flashes = [];
        if (isset($_SESSION['toeic_flash']) && is_array($_SESSION['toeic_flash'])) {
            foreach ($_SESSION['toeic_flash'] as $flash) {
                if (!empty($flash['message'])) {
                    $flashes[] = [
                        'type' => $flash['type'] ?? 'info',
                        'message' => (string)$flash['message'],
                    ];
                }
            }
        }

        foreach (['success', 'error', 'info'] as $legacy_key) {
            if (!empty($_SESSION[$legacy_key]) && is_string($_SESSION[$legacy_key])) {
                $flashes[] = [
                    'type' => $legacy_key,
                    'message' => $_SESSION[$legacy_key],
                ];
                unset($_SESSION[$legacy_key]);
            }
        }

        unset($_SESSION['toeic_flash']);
        return $flashes;
    }
}

if (!function_exists('toeicRedirectWithFlash')) {
    function toeicRedirectWithFlash(string $location, string $type, string $message): void {
        toeicSetFlash($type, $message);
        header('Location: ' . $location);
        exit();
    }
}

if (!function_exists('toeicIsPracticeSession')) {
    function toeicIsPracticeSession($conn, int $user_id, string $test_session): bool {
        if (!($conn instanceof mysqli) || $test_session === '') {
            return false;
        }

        $stmt = $conn->prepare('SELECT practice_mode FROM toeic_test_sessions WHERE test_session = ? AND user_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $test_session, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return !empty($row['practice_mode']);
    }
}

if (!function_exists('toeicGetSessionSummary')) {
    function toeicGetSessionSummary($conn, int $user_id, string $test_session): ?array {
        if (!($conn instanceof mysqli) || $test_session === '') {
            return null;
        }

        $stmt = $conn->prepare('
            SELECT status, current_section, practice_mode, target_part
            FROM toeic_test_sessions
            WHERE test_session = ? AND user_id = ?
            LIMIT 1
        ');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('si', $test_session, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('toeicLogoutAndRedirect')) {
    function toeicLogoutAndRedirect(string $location): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_unset();
        session_destroy();

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $location);
        exit();
    }
}
