<?php

function assertContainsText(string $haystack, string $needle, string $label): void {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function assertNotContainsText(string $haystack, string $needle, string $label): void {
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function assertBeforeText(string $haystack, string $first, string $second, string $label): void {
    $firstPos = strpos($haystack, $first);
    $secondPos = strpos($haystack, $second);

    if ($firstPos === false || $secondPos === false || $firstPos >= $secondPos) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$testToeic = file_get_contents($root . '/user/test_toeic.php');

assertContainsText(
    $testToeic,
    "if (!hasStrictTestCredit(\$conn, \$_SESSION['user_id'], 'toeic'))",
    'new TOEIC sessions require strict credit regardless of mode'
);

assertNotContainsText(
    $testToeic,
    'if (!$practice_mode && !hasStrictTestCredit',
    'practice mode must not bypass strict credit check'
);

// Credit consumption moved into the atomic helper; assert the actual call chain
// and transaction boundaries rather than requiring its old inline location.
$start = file_get_contents($root . '/includes/toeic_session_start.php');
assertContainsText($testToeic, 'toeicStartSessionWithCredit(', 'page delegates creation to the atomic credit helper');
assertContainsText($start, "!consumeTestCredit(\$conn, \$userId, 'toeic')", 'new TOEIC sessions consume one credit regardless of mode');
assertNotContainsText($testToeic . $start, "if (!\$practice_mode) {\n        if (!consumeTestCredit", 'practice mode must not bypass credit consumption');
assertBeforeText($start, '$conn->begin_transaction()', '!consumeTestCredit(', 'credit consumption happens inside the start transaction');
assertBeforeText($start, '!consumeTestCredit(', '$builder->createSession', 'credit is reserved before creating the test session');
assertBeforeText($start, '$builder->buildTest', '$conn->commit()', 'credit commits only after the complete session is built');
assertContainsText($start, '$conn->rollback()', 'failed builds can restore the credit');

$copyFiles = [
    'index.php',
    'user/index.php',
    'user/test_instructions.php',
];

$forbiddenCopy = [
    'without consuming an active package',
    'without spending an active package',
    'does not consume an active TOEIC package',
    'Practice always available',
    'No Package + No Proctor',
    'Practice simulation remains available without package activation',
];

foreach ($copyFiles as $relativePath) {
    $contents = file_get_contents($root . '/' . $relativePath);
    foreach ($forbiddenCopy as $phrase) {
        assertNotContainsText(
            $contents,
            $phrase,
            "{$relativePath} must not describe practice as free/no-package"
        );
    }
}

echo "Practice credit consumption regression checks passed.\n";
