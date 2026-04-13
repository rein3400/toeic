<?php

require_once __DIR__ . '/../includes/proctor_helper.php';

function assertSameValue($expected, $actual, $label)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$label}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$validated = evaluateSnapshotViolationDecision('multiple_faces', [
    'verdict' => 'cheating',
    'risk_score' => 92,
    'detected' => ['multiple people'],
    'reason' => 'Two faces visible in frame.',
], [
    'confirm_threshold' => 80,
    'dismiss_threshold' => 35,
]);

assertSameValue('validated', $validated['review_status'] ?? null, 'cheating verdict becomes validated');
assertSameValue('valid_violation', $validated['review_verdict'] ?? null, 'cheating verdict maps to valid_violation');
assertSameValue('apply_penalty', $validated['enforcement_action'] ?? null, 'validated snapshot applies penalty');
assertSameValue('critical', $validated['enforced_severity'] ?? null, 'multiple faces is enforced as critical');
assertSameValue(15, $validated['score_impact'] ?? null, 'critical severity uses critical score impact');

$dismissed = evaluateSnapshotViolationDecision('periodic_check', [
    'verdict' => 'clean',
    'risk_score' => 5,
    'detected' => [],
    'reason' => 'No prohibited object visible.',
], [
    'confirm_threshold' => 80,
    'dismiss_threshold' => 35,
]);

assertSameValue('dismissed', $dismissed['review_status'] ?? null, 'clean verdict becomes dismissed');
assertSameValue('invalid_violation', $dismissed['review_verdict'] ?? null, 'clean verdict maps to invalid_violation');
assertSameValue('dismiss', $dismissed['enforcement_action'] ?? null, 'dismissed snapshot applies no penalty');
assertSameValue(0, $dismissed['score_impact'] ?? null, 'dismissed snapshot keeps zero score impact');

$uncertain = evaluateSnapshotViolationDecision('periodic_check', [
    'verdict' => 'suspicious',
    'risk_score' => 58,
    'detected' => ['possible phone'],
    'reason' => 'Object is partially occluded.',
], [
    'confirm_threshold' => 80,
    'dismiss_threshold' => 35,
]);

assertSameValue('needs_review', $uncertain['review_status'] ?? null, 'suspicious middle score becomes needs_review');
assertSameValue('uncertain', $uncertain['review_verdict'] ?? null, 'middle score maps to uncertain');
assertSameValue('flag_review', $uncertain['enforcement_action'] ?? null, 'uncertain snapshot does not apply penalty');
assertSameValue(0, $uncertain['score_impact'] ?? null, 'uncertain snapshot keeps zero score impact');

$error = evaluateSnapshotViolationDecision('periodic_check', [
    'verdict' => 'error',
    'risk_score' => 0,
    'detected' => [],
    'reason' => 'Provider timeout',
], [
    'confirm_threshold' => 80,
    'dismiss_threshold' => 35,
]);

assertSameValue('error', $error['review_status'] ?? null, 'error verdict stays error');
assertSameValue('error', $error['review_verdict'] ?? null, 'error verdict maps to error');
assertSameValue('fail_open', $error['enforcement_action'] ?? null, 'error verdict fails open');
assertSameValue(0, $error['score_impact'] ?? null, 'error verdict applies no score impact');

echo "proctor snapshot validation tests passed\n";
