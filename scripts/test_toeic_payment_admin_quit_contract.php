<?php
/**
 * Static contract checks for GoPay manual checkout, TOEIC admin quick editing,
 * and reliable test-page quit controls.
 */

$root = dirname(__DIR__);
$failures = [];

function toeic_contract_check(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function toeic_contract_source(string $relativePath): string {
    global $root;
    $path = $root . '/' . $relativePath;
    return is_file($path) ? (string)file_get_contents($path) : '';
}

$helper = toeic_contract_source('includes/toeic_pricing_helper.php');
$settings = toeic_contract_source('admin/settings.php');
$payment = toeic_contract_source('user/payment.php');
$pending = toeic_contract_source('user/payment_pending.php');
$buyExam = toeic_contract_source('user/buy_exam.php');
$manager = toeic_contract_source('admin/manage_toeic.php');
$lrTest = toeic_contract_source('user/test_toeic.php');
$swTest = toeic_contract_source('user/test_toeic_sw.php');
$migration = toeic_contract_source('scripts/migrate_toeic_standalone.php');

foreach ([
    'includes/toeic_pricing_helper.php' => $helper,
    'admin/settings.php' => $settings,
    'user/payment.php' => $payment,
    'user/payment_pending.php' => $pending,
    'user/buy_exam.php' => $buyExam,
    'admin/manage_toeic.php' => $manager,
    'user/test_toeic.php' => $lrTest,
    'user/test_toeic_sw.php' => $swTest,
    'scripts/migrate_toeic_standalone.php' => $migration,
] as $path => $source) {
    toeic_contract_check($source !== '', "$path must be readable.");
}

foreach (['GOPAY', '+62856-4359-7072', 'Leonardus Bayu'] as $needle) {
    toeic_contract_check(strpos($helper, $needle) !== false, "Payment helper must default to $needle.");
    toeic_contract_check(strpos($payment, $needle) !== false, "Payment page must show $needle.");
    toeic_contract_check(strpos($pending, $needle) !== false, "Pending page must show $needle.");
    toeic_contract_check(strpos($migration, $needle) !== false, "Migration seed must preserve $needle.");
}
toeic_contract_check(strpos($settings, 'GoPay Manual') !== false, 'Admin settings must label the manual method as GoPay Manual.');
toeic_contract_check(strpos($buyExam, 'Bayar via GoPay Manual') !== false, 'Buy Exam page must make GoPay Manual expectation visible.');

toeic_contract_check(substr_count($manager, "ajaxSubmit('questionForm')") === 0, 'TOEIC manager must not register duplicate ajaxSubmit() handler for questionForm.');
toeic_contract_check(strpos($manager, 'syncQuestionPartOptions') !== false, 'TOEIC manager must dynamically filter question part options by section.');
toeic_contract_check(strpos($manager, 'id="question_error"') !== false, 'TOEIC manager must render inline question save errors.');
toeic_contract_check(strpos($manager, 'name="opsi_d"') !== false && strpos($manager, 'id="question_opsi_d"') !== false, 'TOEIC manager must control Option D for Part 2.');
toeic_contract_check(strpos($manager, 'id="quickEditors"') !== false, 'TOEIC manager must place quick editors in a dedicated top section.');

foreach ([
    'user/test_toeic.php' => $lrTest,
    'user/test_toeic_sw.php' => $swTest,
] as $path => $source) {
    toeic_contract_check(strpos($source, 'id="quitTestBtn"') !== false, "$path must expose a dedicated Quit Test control.");
    toeic_contract_check(strpos($source, 'handleQuitTest') !== false, "$path must implement a dedicated Quit Test handler.");
    toeic_contract_check(strpos($source, 'pause(5000)') !== false, "$path must pause proctoring before quit navigation when available.");
}

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC payment/admin/quit contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TOEIC payment/admin/quit contract passed.\n";
