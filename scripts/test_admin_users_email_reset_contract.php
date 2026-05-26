<?php
/**
 * Static contract checks for production password-reset readiness in admin user management.
 */

$root = dirname(__DIR__);
$failures = [];

function reset_contract_read(string $path): string {
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$path}");
    }
    return file_get_contents($path);
}

function reset_contract_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "[PASS] {$message}\n";
        return;
    }

    echo "[FAIL] {$message}\n";
    $failures[] = $message;
}

$adminUsers = reset_contract_read($root . '/admin/users.php');

reset_contract_check(
    strpos($adminUsers, "ADD COLUMN email") !== false,
    'Admin users page ensures the users.email column exists for legacy installs.'
);

reset_contract_check(
    strpos($adminUsers, '$email = strtolower(trim') !== false,
    'Admin add/edit user flow normalizes an email field.'
);

reset_contract_check(
    strpos($adminUsers, 'FILTER_VALIDATE_EMAIL') !== false,
    'Admin add/edit user flow validates email format.'
);

reset_contract_check(
    strpos($adminUsers, 'LOWER(email) = ?') !== false,
    'Admin add/edit user flow checks duplicate email addresses.'
);

reset_contract_check(
    strpos($adminUsers, 'INSERT INTO users (username, email, password, full_name, role)') !== false,
    'Admin add user flow stores email with new accounts.'
);

reset_contract_check(
    strpos($adminUsers, 'UPDATE users SET username = ?, email = ?') !== false,
    'Admin edit user flow updates email on existing accounts.'
);

reset_contract_check(
    strpos($adminUsers, 'name="email"') !== false
        && strpos($adminUsers, 'type="email"') !== false,
    'Admin user forms expose an email input.'
);

reset_contract_check(
    strpos($adminUsers, 'missing_email_count') !== false,
    'Admin user list surfaces accounts missing reset email.'
);

reset_contract_check(
    strpos($adminUsers, 'email LIKE ?') !== false,
    'Admin user search can find accounts by email.'
);

if ($failures) {
    echo "\nAdmin users email reset contract failed: " . count($failures) . " issue(s).\n";
    exit(1);
}

echo "\nAdmin users email reset contract passed.\n";
