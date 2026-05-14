<?php
/**
 * Static contract checks for the TOEIC fixes requested on 2026-05-14.
 *
 * These checks avoid a live database so they can run before production import.
 */

$root = dirname(__DIR__);
$failures = [];

function goal_check($condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function goal_source(string $relativePath): string {
    global $root;
    $path = $root . '/' . $relativePath;
    return is_file($path) ? (string)file_get_contents($path) : '';
}

$requiredFiles = [
    'forgot_password.php',
    'reset_password.php',
    'includes/password_reset_helper.php',
    'includes/toeic_pricing_helper.php',
];

foreach ($requiredFiles as $relativePath) {
    goal_check(is_file($root . '/' . $relativePath), "{$relativePath} must exist.");
}

$login = goal_source('login.php');
goal_check(strpos($login, 'forgot_password.php') !== false, 'login.php must link to the user forgot-password flow.');

$resetHelper = goal_source('includes/password_reset_helper.php');
goal_check(strpos($resetHelper, 'password_reset_tokens') !== false, 'Password reset helper must own a password_reset_tokens table.');
goal_check(strpos($resetHelper, 'random_bytes') !== false, 'Password reset tokens must be generated with random_bytes().');
goal_check(strpos($resetHelper, 'hash(') !== false, 'Password reset helper must store hashed reset tokens.');
goal_check(strpos($resetHelper, 'mail(') !== false, 'Password reset helper must send verification email to the registered address.');

$settings = goal_source('admin/settings.php');
foreach ([
    'price_toeic_retail',
    'price_toeic_partner',
    'price_toeic_bulk',
    'price_toeic_sw_retail',
    'price_toeic_sw_partner',
    'price_toeic_sw_bulk',
    'payment_mode',
    'direct_bank',
    'bank_account_number',
    'forgot_password_enabled',
] as $needle) {
    goal_check(strpos($settings, $needle) !== false, "admin/settings.php must expose {$needle}.");
}

$pricingHelper = goal_source('includes/toeic_pricing_helper.php');
goal_check(strpos($pricingHelper, 'toeicGetProductPrice') !== false, 'Pricing helper must expose toeicGetProductPrice().');
goal_check(strpos($pricingHelper, 'retail') !== false && strpos($pricingHelper, 'partner') !== false && strpos($pricingHelper, 'bulk') !== false, 'Pricing helper must support retail, partner, and bulk tiers.');

$payment = goal_source('user/payment.php');
$createTransaction = goal_source('api/create_transaction.php');
$pending = goal_source('user/payment_pending.php');
$buyExam = goal_source('user/buy_exam.php');
goal_check(strpos($payment, 'toeicGetProductPrice') !== false, 'user/payment.php must use separated TOEIC pricing tiers.');
goal_check(strpos($buyExam, 'toeicGetProductPrice') !== false, 'user/buy_exam.php must use separated TOEIC pricing tiers.');
goal_check(strpos($payment, 'direct_bank') !== false, 'user/payment.php must render direct-bank checkout mode.');
goal_check(strpos($createTransaction, 'BANK_TRANSFER') !== false, 'api/create_transaction.php must create direct bank-transfer transactions.');
goal_check(strpos($pending, 'Direct Bank Transfer') !== false || strpos($pending, 'Transfer Bank Langsung') !== false, 'payment_pending.php must show direct-bank transfer instructions.');

$dbUtils = goal_source('includes/db_utils.php');
$builder = goal_source('includes/toeic_test_builder.php');
$testToeic = goal_source('user/test_toeic.php');
$adminSessions = goal_source('admin/test_sessions.php');
goal_check(strpos($dbUtils, 'peekNextTestCredit') !== false, 'db_utils.php must expose the next active credit before consumption.');
goal_check(strpos($dbUtils, 'toeicIsFreeTrialCredit') !== false, 'db_utils.php must classify FREE_TRIAL credits.');
goal_check(strpos($builder, 'FREE_TRIAL_QUESTION_LIMIT') !== false && strpos($builder, '15') !== false, 'TOEIC builder must cap free-trial sessions at 15 questions.');
goal_check(strpos($builder, 'checkout_source') !== false && strpos($builder, 'checkout_reference') !== false, 'TOEIC sessions must persist checkout source/reference.');
goal_check(strpos($testToeic, 'free_trial') !== false && strpos($testToeic, 'checkout_source') !== false, 'test_toeic.php must pass free-trial and checkout source into session creation.');
goal_check(strpos($adminSessions, 'Checkout Source') !== false || strpos($adminSessions, 'Sumber Akses') !== false, 'admin/test_sessions.php must show checkout/voucher source column.');

$packageRoot = $root . '/content/generated/toeic_sw';
$referencedImages = [];
if (is_dir($packageRoot)) {
    for ($package = 1; $package <= 10; $package++) {
        $packageName = sprintf('package_%02d', $package);
        $manifestPath = $packageRoot . '/' . $packageName . '/manifest.json';
        if (!is_file($manifestPath)) {
            $failures[] = "{$packageName}/manifest.json must exist.";
            continue;
        }
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            $failures[] = "{$packageName}/manifest.json must be valid JSON.";
            continue;
        }
        foreach (array_merge($manifest['speaking'] ?? [], $manifest['writing'] ?? []) as $task) {
            if (empty($task['image_path'])) {
                continue;
            }
            $path = $packageRoot . '/' . $packageName . '/' . $task['image_path'];
            $relative = $packageName . '/' . $task['image_path'];
            $referencedImages[$relative] = true;
            goal_check(is_file($path), "{$relative} must exist.");
            if (is_file($path)) {
                $svg = (string)file_get_contents($path);
                goal_check(strpos($svg, 'data-scene-key=') !== false, "{$relative} must declare a distinct data-scene-key.");
                goal_check(strpos($svg, '<svg') !== false && strpos($svg, '</svg>') !== false, "{$relative} must be a complete SVG.");
            }
        }
    }
    goal_check(count($referencedImages) === 70, 'TOEIC SW manifests must reference exactly 70 package images.');
} else {
    $failures[] = 'content/generated/toeic_sw must exist.';
}

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC goal contract checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TOEIC goal contract checks passed.\n";
