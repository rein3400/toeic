<?php
/**
 * Static contract checks for PHP mail() fallback safety.
 */

$root = dirname(__DIR__);
$failures = [];

function mail_fallback_contract_read(string $path): string {
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$path}");
    }
    return file_get_contents($path);
}

function mail_fallback_contract_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "[PASS] {$message}\n";
        return;
    }

    echo "[FAIL] {$message}\n";
    $failures[] = $message;
}

$emailVerificationHelper = mail_fallback_contract_read($root . '/includes/email_verification_helper.php');
$passwordResetHelper = mail_fallback_contract_read($root . '/includes/password_reset_helper.php');

foreach ([
    'Email verification helper' => $emailVerificationHelper,
    'Password reset helper' => $passwordResetHelper,
] as $label => $content) {
    mail_fallback_contract_check(
        strpos($content, 'function_exists(\'mail\')') !== false || strpos($content, 'function_exists("mail")') !== false,
        "{$label} checks that PHP mail() exists before using fallback delivery."
    );

    mail_fallback_contract_check(
        strpos($content, 'mail(') !== false,
        "{$label} still supports native mail fallback when available."
    );
}

if ($failures) {
    echo "\nPHP mail fallback contract failed: " . count($failures) . " issue(s).\n";
    exit(1);
}

echo "\nPHP mail fallback contract passed.\n";
?>
