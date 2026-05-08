<?php
require_once __DIR__ . '/../includes/toeic_quality_helpers.php';

$failures = [];

function assertToeicQualityEqual($actual, $expected, $label) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true);
    }
}

assertToeicQualityEqual(toeicNormalizeVoucherCode(' osgLi - 33yrb '), 'OSGLI-33YRB', 'voucher trims spaces and uppercases');
assertToeicQualityEqual(toeicNormalizeVoucherCode("OSGLI\xC2\xA0-\xC2\xA033YRB"), 'OSGLI-33YRB', 'voucher strips non-breaking spaces');
assertToeicQualityEqual(toeicNormalizeVoucherCode('osgli-33yrb'), 'OSGLI-33YRB', 'voucher keeps canonical hyphen');
assertToeicQualityEqual(toeicNormalizeVoucherCode('osgliabcde'), 'OSGLI-ABCDE', 'voucher inserts OSGLI hyphen for typed code');
assertToeicQualityEqual(toeicNormalizeVoucherCode("OSGLI\xE2\x80\x9333YRB"), 'OSGLI-33YRB', 'voucher normalizes en dash');

assertToeicQualityEqual(toeicDisplayRoundedScore(null), '-', 'null average score is hidden');
assertToeicQualityEqual(toeicDisplayRoundedScore(''), '-', 'empty average score is hidden');
assertToeicQualityEqual(toeicDisplayRoundedScore(0), '-', 'zero score is hidden as empty stat');
assertToeicQualityEqual(toeicDisplayRoundedScore(744.6), '745', 'average score is rounded');
assertToeicQualityEqual(toeicDisplayRoundedScore('822.2'), '822', 'numeric string score is rounded');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "TOEIC quality helper tests passed." . PHP_EOL;
