<?php
/**
 * Static and helper-level contracts for TOEIC payment verification, media URLs,
 * no-proctor practice copy, and full-test proctor upload wiring.
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

function toeic_contract_same($expected, $actual, string $message): void {
    global $failures;
    if ($expected !== $actual) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
}

$adminPayments = toeic_contract_source('admin/payments.php');
$adminConfig = toeic_contract_source('admin/includes/admin_config.php');
$adminIndex = toeic_contract_source('admin/index.php');
$instructions = toeic_contract_source('user/test_instructions.php');
$apiProctor = toeic_contract_source('api/ajax_proctor.php');
$proctorJs = toeic_contract_source('user/js/proctor.js');

foreach ([
    'admin/payments.php' => $adminPayments,
    'admin/includes/admin_config.php' => $adminConfig,
    'admin/index.php' => $adminIndex,
    'user/test_instructions.php' => $instructions,
    'api/ajax_proctor.php' => $apiProctor,
    'user/js/proctor.js' => $proctorJs,
] as $path => $source) {
    toeic_contract_check($source !== '', "$path must be readable.");
}

toeic_contract_check(strpos($adminConfig, 'payments.php') !== false, 'Admin navigation must expose payment verification.');
toeic_contract_check(strpos($adminIndex, 'payments.php') !== false, 'Admin dashboard must link to payment verification.');
toeic_contract_check(strpos($adminPayments, 'payment_transactions') !== false, 'Payment verification page must read payment_transactions.');
toeic_contract_check(strpos($adminPayments, 'grantSettledPaymentCredit') !== false, 'Payment verification page must grant TOEIC credit when settling payment.');
toeic_contract_check(strpos($adminPayments, 'validateCsrfToken') !== false, 'Payment verification actions must validate CSRF tokens.');
toeic_contract_check(strpos($adminPayments, "'settlement'") !== false, 'Payment verification page must support marking payments settled.');
toeic_contract_check(strpos($adminPayments, "'deny'") !== false, 'Payment verification page must support rejecting invalid payments.');

toeic_contract_check(strpos($instructions, '$is_lr_practice') !== false, 'Instructions must branch LR practice copy separately from proctored full tests.');
toeic_contract_check(strpos($instructions, 'No Proctoring') !== false, 'LR practice instructions must state that practice is not proctored.');
toeic_contract_check(strpos($instructions, 'Audio ready') !== false, 'LR practice environment checklist must ask for audio readiness instead of webcam readiness.');

toeic_contract_check(strpos($proctorJs, "formData.append('action', 'upload_chunk')") !== false, 'Proctor JS contract should upload chunks with action=upload_chunk.');
toeic_contract_check(strpos($proctorJs, "formData.append('chunk', blob)") !== false, 'Proctor JS contract should upload the file field named chunk.');
toeic_contract_check(strpos($apiProctor, "case 'upload_chunk':") !== false, 'API proctor endpoint must accept the upload_chunk action used by the JS client.');
toeic_contract_check(strpos($apiProctor, '$_FILES[\'chunk\']') !== false, 'API proctor endpoint must read the chunk file field used by the JS client.');
toeic_contract_check(strpos($apiProctor, 'saveVideoChunk($session_id, $chunkIndex, $chunkFile, $chunkDuration)') !== false, 'API proctor endpoint must pass saveVideoChunk arguments in the helper order.');

require_once $root . '/includes/toeic_asset_storage.php';
putenv('TOEIC_STORAGE_DRIVER=r2');
putenv('TOEIC_AUDIO_STORAGE_DRIVER=r2');
putenv('R2_PUBLIC_BASE_URL=https://cdn.example.com/toeic-assets');
putenv('R2_AUDIO_PUBLIC_BASE_URL=https://cdn.example.com/toeic-audio');

$nestedAudio = 'toeic/audio/package_02/dialogue 01.mp3';
$expectedAudioUrl = 'https://cdn.example.com/toeic-audio/toeic/audio/package_02/dialogue%2001.mp3';
toeic_contract_same($expectedAudioUrl, toeicAudioUrl($nestedAudio), 'Nested TOEIC audio R2 key must be preserved and encoded.');

$audioSource = toeicAudioSource($nestedAudio);
toeic_contract_same('remote', $audioSource['mode'] ?? null, 'Nested TOEIC audio source must resolve as remote under R2.');
toeic_contract_same($expectedAudioUrl, $audioSource['url'] ?? null, 'Nested TOEIC audio source URL must preserve the package key.');

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC payment/media/proctor contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TOEIC payment/media/proctor contract passed.\n";
