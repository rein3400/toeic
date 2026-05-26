<?php
/**
 * Static contract checks for TOEIC email verification.
 */

$root = dirname(__DIR__);
$failures = [];

function email_contract_read(string $path): string {
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$path}");
    }
    return file_get_contents($path);
}

function email_contract_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "[PASS] {$message}\n";
        return;
    }

    echo "[FAIL] {$message}\n";
    $failures[] = $message;
}

$helperPath = $root . '/includes/email_verification_helper.php';
$helper = is_file($helperPath) ? email_contract_read($helperPath) : '';
$register = email_contract_read($root . '/register.php');
$login = email_contract_read($root . '/login.php');
$profile = email_contract_read($root . '/user/profile.php');
$adminUsers = email_contract_read($root . '/admin/users.php');
$migration = email_contract_read($root . '/scripts/migrate_toeic_standalone.php');
$verifyPage = is_file($root . '/verify_email.php') ? email_contract_read($root . '/verify_email.php') : '';
$studentVerifyPage = is_file($root . '/user/verify_email.php') ? email_contract_read($root . '/user/verify_email.php') : '';
$resendPage = is_file($root . '/user/resend_verification.php') ? email_contract_read($root . '/user/resend_verification.php') : '';

email_contract_check($helper !== '', 'Email verification helper exists.');

foreach ([
    'toeicEnsureEmailVerificationSchema',
    'toeicCreateEmailVerification',
    'toeicConsumeEmailVerification',
    'toeicEmailVerificationRateLimitStatus',
    'toeicSendEmailVerification',
    'toeicRequireVerifiedEmail',
] as $functionName) {
    email_contract_check(strpos($helper, $functionName) !== false, "Helper defines {$functionName}.");
}

email_contract_check(
    strpos($helper, 'email_verification_tokens') !== false
        && strpos($helper, 'email_verified_at') !== false
        && strpos($migration, 'email_verification_tokens') !== false
        && strpos($migration, 'email_verified_at') !== false,
    'Email verification schema is present in helper and migration.'
);

email_contract_check(
    strpos($helper, 'toeicPasswordResetSendSmtpEmail') !== false,
    'Email verification reuses the configured SMTP transport.'
);

email_contract_check(
    strpos($register, 'email_verification_helper.php') !== false
        && strpos($register, 'toeicCreateEmailVerification') !== false
        && strpos($register, 'email_verified_at') !== false,
    'Registration creates unverified users and sends verification.'
);

email_contract_check(
    strpos($login, 'email_verification_helper.php') !== false
        && strpos($login, 'toeicUserNeedsEmailVerification') !== false
        && strpos($login, 'user/verify_email.php') !== false,
    'Login redirects unverified students to the verification page.'
);

email_contract_check(
    $verifyPage !== ''
        && strpos($verifyPage, 'toeicConsumeEmailVerification') !== false,
    'Public verify_email.php consumes verification tokens.'
);

email_contract_check(
    $studentVerifyPage !== ''
        && strpos($studentVerifyPage, 'toeicGetUserEmailVerificationState') !== false
        && strpos($studentVerifyPage, 'resend_verification.php') !== false,
    'Student verification page shows status and resend action.'
);

email_contract_check(
    $resendPage !== ''
        && strpos($resendPage, 'toeicCreateEmailVerification') !== false,
    'Student resend page creates a fresh verification token.'
);

email_contract_check(
    strpos($profile, 'email_verification_helper.php') !== false
        && strpos($profile, 'email_verified_at = NULL') !== false
        && strpos($profile, 'toeicCreateEmailVerification') !== false,
    'Profile email changes clear verification and send a new link.'
);

email_contract_check(
    strpos($adminUsers, 'email_verified_at') !== false
        && strpos($adminUsers, 'resend_verification') !== false,
    'Admin user management surfaces verification status and resend control.'
);

foreach ([
    'user/index.php',
    'user/buy_exam.php',
    'user/payment.php',
    'checkout-va.php',
    'user/test_instructions.php',
    'user/test_toeic.php',
    'user/test_toeic_sw.php',
] as $guardedPath) {
    $content = email_contract_read($root . '/' . $guardedPath);
    email_contract_check(
        strpos($content, 'email_verification_helper.php') !== false
            && strpos($content, 'toeicRequireVerifiedEmail') !== false,
        "{$guardedPath} requires verified email."
    );
}

if ($failures) {
    echo "\nEmail verification contract failed: " . count($failures) . " issue(s).\n";
    exit(1);
}

echo "\nEmail verification contract passed.\n";
