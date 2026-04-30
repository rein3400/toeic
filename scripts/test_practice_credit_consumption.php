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

assertContainsText(
    $testToeic,
    "if (!consumeTestCredit(\$conn, \$_SESSION['user_id'], 'toeic'))",
    'new TOEIC sessions consume one credit regardless of mode'
);

assertNotContainsText(
    $testToeic,
    "if (!\$practice_mode) {\n        if (!consumeTestCredit",
    'practice mode must not bypass credit consumption'
);

assertBeforeText(
    $testToeic,
    "if (!consumeTestCredit(\$conn, \$_SESSION['user_id'], 'toeic'))",
    '$builder->createSession',
    'credit is consumed before creating the test session'
);

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
