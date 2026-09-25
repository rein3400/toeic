<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Regression test: importer duplicate-warning fingerprint must use real a/b/c/d
// options for BOTH new and existing rows (variable-variable ${opsi_a} bug made
// the new-row hash use blank options, so real duplicates were never warned).
//
// Pure-function contract under test (defined in
// includes/toeic_c2_package_importer.php):
//   toeicC2SignatureFingerprint(section, part, question, a, b, c, d, audioId, textId): string
//
// No DB required: this exercises the shared fingerprint directly plus a
// structural check that toeicC2CheckSignatureDuplicate builds both signatures
// through it (no divergent inline signature building).

require_once __DIR__ . '/../includes/toeic_c2_package_importer.php';

$failures = [];

function assertFp($actual, $expected, $label) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true);
    }
}

function assertFpTrue($cond, $label) {
    global $failures;
    if (!$cond) {
        $failures[] = $label;
    }
}

// 0. Shared fingerprint function must exist (testability gate).
assertFpTrue(
    function_exists('toeicC2SignatureFingerprint'),
    'toeicC2SignatureFingerprint() must exist in includes/toeic_c2_package_importer.php'
);

if (!function_exists('toeicC2SignatureFingerprint')) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

$Q = 'What does the speaker imply?';
$A = 'Alpha word';
$B = 'Bravo word';
$C = 'Charlie word';
$D = 'Delta word';

// 1. Real same options => same digest (new row vs existing DB row mapping).
$newSig = toeicC2SignatureFingerprint('listening', '1', $Q, $A, $B, $C, $D, 11, null);
$otherRow = [
    'pertanyaan' => $Q,
    'opsi_a' => $A,
    'opsi_b' => $B,
    'opsi_c' => $C,
    'opsi_d' => $D,
    'id_audio' => 11,
    'id_teks' => null,
];
$existingSig = toeicC2SignatureFingerprint(
    'listening',
    '1',
    (string)($otherRow['pertanyaan'] ?? ''),
    isset($otherRow['opsi_a']) ? (string)$otherRow['opsi_a'] : null,
    isset($otherRow['opsi_b']) ? (string)$otherRow['opsi_b'] : null,
    isset($otherRow['opsi_c']) ? (string)$otherRow['opsi_c'] : null,
    isset($otherRow['opsi_d']) ? (string)$otherRow['opsi_d'] : null,
    !empty($otherRow['id_audio']) ? (int)$otherRow['id_audio'] : null,
    !empty($otherRow['id_teks']) ? (int)$otherRow['id_teks'] : null
);
assertFp($existingSig, $newSig, 'same options (new vs existing row mapping) must share digest');

// 2. Options actually contribute: real options must NOT hash like blank options.
// (Old buggy new-row code read ${opsi_a}..${opsi_d} which are undefined, so the
// new-row digest always equalled the blank-options digest.)
$blankSig = toeicC2SignatureFingerprint('listening', '1', $Q, '', '', '', '', 11, null);
assertFpTrue($newSig !== $blankSig, 'real options must not digest as blank options (a/b/c/d mapping)');
assertFpTrue(
    toeicC2SignatureFingerprint('listening', '1', $Q, null, null, null, null, 11, null) === $blankSig,
    'null options must digest as blank options'
);

// 3. Genuinely different options => not duplicate.
$diffSig = toeicC2SignatureFingerprint('listening', '1', $Q, 'Alpha word', 'Bravo word', 'Charlie word', 'Zulu word', 11, null);
assertFpTrue($diffSig !== $newSig, 'genuinely different options must not match');
$diffQ = toeicC2SignatureFingerprint('listening', '1', 'What does the speaker state?', $A, $B, $C, $D, 11, null);
assertFpTrue($diffQ !== $newSig, 'different question text must not match');

// 4. Rotated option order => same digest (order-insensitive dedup contract).
$rotSig = toeicC2SignatureFingerprint('listening', '1', $Q, $D, $C, $B, $A, 11, null);
assertFp($rotSig, $newSig, 'rotated option order must share digest');
$rotSig2 = toeicC2SignatureFingerprint('listening', '1', $Q, $B, $A, $D, $C, 11, null);
assertFp($rotSig2, $newSig, 'shuffled option order must share digest');

// 5. Each a/b/c/d slot is read (change exactly one slot => digest changes).
assertFpTrue(toeicC2SignatureFingerprint('listening', '1', $Q, 'Ax', $B, $C, $D, 11, null) !== $newSig, 'slot A must feed digest');
assertFpTrue(toeicC2SignatureFingerprint('listening', '1', $Q, $A, 'Bx', $C, $D, 11, null) !== $newSig, 'slot B must feed digest');
assertFpTrue(toeicC2SignatureFingerprint('listening', '1', $Q, $A, $B, 'Cx', $D, 11, null) !== $newSig, 'slot C must feed digest');
assertFpTrue(toeicC2SignatureFingerprint('listening', '1', $Q, $A, $B, $C, 'Dx', 11, null) !== $newSig, 'slot D must feed digest');

// 6. Part 2 uses only A/B/C (D ignored); other parts use A/B/C/D.
$p2a = toeicC2SignatureFingerprint('listening', '2', $Q, $A, $B, $C, $D, 11, null);
$p2b = toeicC2SignatureFingerprint('listening', '2', $Q, $A, $B, $C, 'Something else entirely', 11, null);
$p2c = toeicC2SignatureFingerprint('listening', '2', $Q, $A, $B, $C, null, 11, null);
assertFp($p2b, $p2a, 'part 2 must ignore option D (variant)');
assertFp($p2c, $p2a, 'part 2 must ignore option D (null)');
assertFpTrue(
    toeicC2SignatureFingerprint('listening', '2', $Q, $A, $B, 'Cx', $D, 11, null) !== $p2a,
    'part 2 must still read option C'
);
assertFpTrue(
    toeicC2SignatureFingerprint('listening', '2', $Q, $C, $B, $A, 'zzz', 11, null) === toeicC2SignatureFingerprint('listening', '2', $Q, $A, $B, $C, 'other', 11, null),
    'part 2 rotated A/B/C must share digest regardless of D'
);

// 7. Context IDs retained (current contract): audio/text/section/part feed digest.
assertFpTrue(toeicC2SignatureFingerprint('listening', '1', $Q, $A, $B, $C, $D, 12, null) !== $newSig, 'different audioId must not match');
assertFpTrue(toeicC2SignatureFingerprint('listening', '1', $Q, $A, $B, $C, $D, null, null) !== $newSig, 'null vs set audioId must not match');
assertFpTrue(toeicC2SignatureFingerprint('reading', '5', $Q, $A, $B, $C, $D, null, 7) !== toeicC2SignatureFingerprint('reading', '5', $Q, $A, $B, $C, $D, null, 8), 'different textId must not match');
assertFpTrue(toeicC2SignatureFingerprint('reading', '5', $Q, $A, $B, $C, $D, null, null) !== toeicC2SignatureFingerprint('reading', '5', $Q, $A, $B, $C, $D, null, 7), 'null vs set textId must not match');
assertFpTrue(toeicC2SignatureFingerprint('reading', '1', $Q, $A, $B, $C, $D, 11, null) !== toeicC2SignatureFingerprint('listening', '1', $Q, $A, $B, $C, $D, 11, null), 'section must feed digest');
assertFpTrue(toeicC2SignatureFingerprint('listening', '3', $Q, $A, $B, $C, $D, 11, null) !== toeicC2SignatureFingerprint('listening', '1', $Q, $A, $B, $C, $D, 11, null), 'part must feed digest');

// 8. Normalization locked: case/punctuation/whitespace-insensitive question+options.
$normSig = toeicC2SignatureFingerprint('listening', '1', '  WHAT does the speaker, imply?! ', '  ALPHA word ', 'bravo-WORD', 'Charlie   word', 'delta.word!', 11, null);
assertFp($normSig, $newSig, 'normalization (case/space/punct) must be stable');

// 9. Structural: duplicate check must build BOTH signatures via the shared function
// (eliminates the divergent new/existing inline builders).
$src = file_get_contents(__DIR__ . '/../includes/toeic_c2_package_importer.php');
$checkPos = strpos($src, 'function toeicC2CheckSignatureDuplicate');
assertFpTrue($checkPos !== false, 'toeicC2CheckSignatureDuplicate must still exist');
$checkBody = $checkPos !== false ? substr($src, $checkPos) : '';
// Cut at next top-level function (heuristic: next "\nfunction " after body start).
$nextFn = strpos($checkBody, "\nfunction ", 1);
if ($nextFn !== false) {
    $checkBody = substr($checkBody, 0, $nextFn);
}
assertFpTrue(substr_count($checkBody, 'toeicC2SignatureFingerprint') >= 2, 'duplicate check must call shared fingerprint for new AND existing rows');
assertFpTrue(strpos($checkBody, '${') === false, 'duplicate check must not use variable-variable ${...} (the opsi_a bug)');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'TOEIC import fingerprint regression tests passed.' . PHP_EOL;
