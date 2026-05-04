<?php
declare(strict_types=1);

/**
 * Validate generated TOEIC C2 package directories.
 */

$root = dirname(__DIR__);
$packageRoot = $argv[1] ?? ($root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'toeic_packages');
if (!is_dir($packageRoot)) {
    fwrite(STDERR, "Package root not found: $packageRoot\n");
    exit(1);
}

function readJsonStrict(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: $path");
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON in $path: " . json_last_error_msg());
    }
    return $data;
}

function fail(array &$errors, string $package, string $message): void {
    $errors[] = "[$package] $message";
}

function validAnswer(string $answer, array $options, bool $threeChoice = false): bool {
    $allowed = $threeChoice ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
    return in_array($answer, $allowed, true) && isset($options[$answer]);
}

function validateOptions(array $options, bool $threeChoice, string $package, string $where, array &$errors): void {
    $letters = $threeChoice ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D'];
    foreach ($letters as $letter) {
        if (!isset($options[$letter]) || trim((string)$options[$letter]) === '') {
            fail($errors, $package, "$where has missing option $letter");
        }
        if (preg_match('/^[A-D]$/', trim((string)($options[$letter] ?? '')))) {
            fail($errors, $package, "$where option $letter is placeholder-only");
        }
    }
    $values = array_map(static fn($v) => strtolower(trim((string)$v)), array_intersect_key($options, array_flip($letters)));
    if (count($values) !== count(array_unique($values))) {
        fail($errors, $package, "$where has duplicate option text");
    }
}

function validateQuestion(array $q, string $package, string $where, array &$errors, bool $threeChoice = false): void {
    $options = $q['options'] ?? [];
    if (!is_array($options)) {
        fail($errors, $package, "$where options are not an object");
        return;
    }
    validateOptions($options, $threeChoice, $package, $where, $errors);
    $answer = strtoupper(trim((string)($q['correct_answer'] ?? '')));
    if (!validAnswer($answer, $options, $threeChoice)) {
        fail($errors, $package, "$where has invalid correct_answer '$answer'");
    }
    $questionText = trim((string)($q['question_text'] ?? $q['sentence'] ?? $q['prompt_text'] ?? $q['title'] ?? ''));
    if ($questionText === '') {
        fail($errors, $package, "$where has empty question text");
    }
    if (trim((string)($q['explanation'] ?? '')) === '') {
        fail($errors, $package, "$where has empty explanation");
    }
}

function countSetQuestions(array $sets): int {
    $total = 0;
    foreach ($sets as $set) {
        $total += count($set['questions'] ?? []);
    }
    return $total;
}

$errors = [];
$packages = glob(rtrim($packageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'package_*', GLOB_ONLYDIR) ?: [];
sort($packages);
if (!$packages) {
    fwrite(STDERR, "No package directories found in $packageRoot\n");
    exit(1);
}

foreach ($packages as $dir) {
    $package = basename($dir);
    try {
        $part1 = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'part1.json');
        $part2 = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'part2.json');
        $part3 = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'part3.json');
        $part4 = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'part4.json');
        $part5 = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'part5.json');
        $part6 = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'part6.json');
        $part7 = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'part7.json');
        $manifest = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'manifest.json');
        $transcripts = readJsonStrict($dir . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'transcripts.json');
    } catch (Throwable $e) {
        fail($errors, $package, $e->getMessage());
        continue;
    }

    if (count($part1['items'] ?? []) !== 6) fail($errors, $package, 'Part 1 must contain 6 items');
    if (count($part2['items'] ?? []) !== 25) fail($errors, $package, 'Part 2 must contain 25 items');
    if (count($part3['sets'] ?? []) !== 13 || countSetQuestions($part3['sets'] ?? []) !== 39) fail($errors, $package, 'Part 3 must contain 13 sets and 39 questions');
    if (count($part4['sets'] ?? []) !== 10 || countSetQuestions($part4['sets'] ?? []) !== 30) fail($errors, $package, 'Part 4 must contain 10 sets and 30 questions');
    if (count($part5['items'] ?? []) !== 30) fail($errors, $package, 'Part 5 must contain 30 items');
    if (count($part6['sets'] ?? []) !== 4 || countSetQuestions($part6['sets'] ?? []) !== 16) fail($errors, $package, 'Part 6 must contain 4 sets and 16 questions');
    $part7Total = countSetQuestions($part7['single_sets'] ?? []) + countSetQuestions($part7['double_sets'] ?? []) + countSetQuestions($part7['triple_sets'] ?? []);
    if ($part7Total !== 54) fail($errors, $package, "Part 7 must contain 54 questions, found $part7Total");

    $audioRefs = [];
    $imageRefs = [];
    foreach ($part1['items'] ?? [] as $i => $item) {
        validateQuestion($item, $package, "Part 1 item " . ($i + 1), $errors);
        $audioRefs[] = (string)($item['audio_file'] ?? '');
        $imageRefs[] = (string)($item['image_file'] ?? '');
        if (strlen((string)($item['image_prompt'] ?? '')) < 120) {
            fail($errors, $package, "Part 1 item " . ($i + 1) . ' image_prompt is too short');
        }
    }
    foreach ($part2['items'] ?? [] as $i => $item) {
        validateQuestion($item, $package, "Part 2 item " . ($i + 1), $errors, true);
        $audioRefs[] = (string)($item['audio_file'] ?? '');
    }
    foreach (['part3' => $part3, 'part4' => $part4] as $partName => $part) {
        foreach ($part['sets'] ?? [] as $setIndex => $set) {
            $audioRefs[] = (string)($set['audio_file'] ?? '');
            if (strlen((string)($set['audio_script'] ?? '')) < 400) {
                fail($errors, $package, "$partName set " . ($setIndex + 1) . ' audio_script is too short for C2 listening');
            }
            foreach ($set['questions'] ?? [] as $questionIndex => $question) {
                validateQuestion($question, $package, "$partName set " . ($setIndex + 1) . ' question ' . ($questionIndex + 1), $errors);
            }
        }
    }
    foreach ($part5['items'] ?? [] as $i => $item) {
        validateQuestion($item, $package, 'Part 5 item ' . ($i + 1), $errors);
    }
    foreach ($part6['sets'] ?? [] as $setIndex => $set) {
        if (substr_count((string)($set['passage_with_blanks'] ?? ''), '___') < 8) {
            fail($errors, $package, 'Part 6 set ' . ($setIndex + 1) . ' does not include four visible blanks');
        }
        foreach ($set['questions'] ?? [] as $questionIndex => $question) {
            validateQuestion($question, $package, 'Part 6 set ' . ($setIndex + 1) . ' question ' . ($questionIndex + 1), $errors);
        }
    }
    foreach (['single_sets', 'double_sets', 'triple_sets'] as $setKey) {
        foreach ($part7[$setKey] ?? [] as $setIndex => $set) {
            if (strlen((string)($set['passage_1'] ?? '')) < 180) {
                fail($errors, $package, "Part 7 $setKey set " . ($setIndex + 1) . ' passage_1 is too short');
            }
            foreach ($set['questions'] ?? [] as $questionIndex => $question) {
                validateQuestion($question, $package, "Part 7 $setKey set " . ($setIndex + 1) . ' question ' . ($questionIndex + 1), $errors);
            }
        }
    }

    $audioRefs = array_values(array_filter($audioRefs));
    $imageRefs = array_values(array_filter($imageRefs));
    if (count($audioRefs) !== 54 || count(array_unique($audioRefs)) !== 54) {
        fail($errors, $package, 'Expected 54 unique audio file references');
    }
    if (count($imageRefs) !== 6 || count(array_unique($imageRefs)) !== 6) {
        fail($errors, $package, 'Expected 6 unique image file references');
    }
    $transcriptFiles = $transcripts['files'] ?? [];
    if (!is_array($transcriptFiles) || count($transcriptFiles) !== 54) {
        fail($errors, $package, 'Transcript manifest must contain 54 files');
    }
    foreach ($audioRefs as $audio) {
        if (!isset($transcriptFiles[$audio])) {
            fail($errors, $package, "Transcript manifest missing $audio");
        }
    }
    if (($manifest['counts']['total'] ?? null) !== 200) {
        fail($errors, $package, 'Manifest total must be 200');
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    fwrite(STDERR, 'Validation failed with ' . count($errors) . " issue(s).\n");
    exit(1);
}

echo 'Validated ' . count($packages) . " TOEIC C2 package(s) successfully.\n";
