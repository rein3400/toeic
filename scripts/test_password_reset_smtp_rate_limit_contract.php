<?php
/**
 * Static contract checks for password reset SMTP delivery and throttling.
 */

$root = dirname(__DIR__);
$failures = [];

function contract_read(string $path): string {
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$path}");
    }
    return file_get_contents($path);
}

function contract_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "[PASS] {$message}\n";
        return;
    }

    echo "[FAIL] {$message}\n";
    $failures[] = $message;
}

$composer = json_decode(contract_read($root . '/composer.json'), true);
$resetHelper = contract_read($root . '/includes/password_reset_helper.php');
$forgotPage = contract_read($root . '/forgot_password.php');
$adminSettings = contract_read($root . '/admin/settings.php');
$migration = contract_read($root . '/scripts/migrate_toeic_standalone.php');

contract_check(
    isset($composer['require']['phpmailer/phpmailer']),
    'Composer requires phpmailer/phpmailer for SMTP delivery.'
);

foreach ([
    'password_reset_smtp_enabled',
    'password_reset_smtp_host',
    'password_reset_smtp_port',
    'password_reset_smtp_username',
    'password_reset_smtp_password',
    'password_reset_smtp_encryption',
    'password_reset_email_limit',
    'password_reset_ip_limit',
    'password_reset_rate_window_minutes',
] as $settingKey) {
    contract_check(
        strpos($resetHelper, $settingKey) !== false
            && strpos($adminSettings, $settingKey) !== false
            && strpos($migration, $settingKey) !== false,
        "Setting key {$settingKey} is wired through helper, admin settings, and migration."
    );
}

contract_check(
    strpos($resetHelper, 'PHPMailer\\PHPMailer\\PHPMailer') !== false,
    'Password reset helper uses PHPMailer for SMTP delivery.'
);

contract_check(
    strpos($resetHelper, 'toeicPasswordResetSendSmtpEmail') !== false,
    'Password reset helper has a dedicated SMTP send helper.'
);

contract_check(
    strpos($resetHelper, 'toeicPasswordResetRateLimitStatus') !== false,
    'Password reset helper has a dedicated rate-limit status helper.'
);

contract_check(
    strpos($resetHelper, 'rate_limited') !== false,
    'Password reset helper records a rate-limited branch before token creation.'
);

contract_check(
    strpos($forgotPage, 'Jika email tersebut terdaftar, link reset password sudah dikirim.') !== false,
    'Forgot-password page keeps neutral success copy.'
);

contract_check(
    strpos($adminSettings, 'SMTP') !== false
        && strpos($adminSettings, 'Rate Limit') !== false,
    'Admin settings exposes SMTP and rate-limit controls.'
);

if ($failures) {
    echo "\nPassword reset SMTP/rate-limit contract failed: " . count($failures) . " issue(s).\n";
    exit(1);
}

echo "\nPassword reset SMTP/rate-limit contract passed.\n";
