<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/toeic_sw_helper.php';

$root = dirname(__DIR__);
$packageRoot = $root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'toeic_sw';
$failures = [];

function toeicSwValidateFail(string $message): void {
    global $failures;
    $failures[] = $message;
}

if (!is_dir($packageRoot)) {
    toeicSwValidateFail("Package root does not exist: {$packageRoot}");
} else {
    $blueprint = getToeicSwTaskBlueprint();
    for ($package = 1; $package <= 10; $package++) {
        $packageName = sprintf('package_%02d', $package);
        $dir = $packageRoot . DIRECTORY_SEPARATOR . $packageName;
        $manifestPath = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!file_exists($manifestPath)) {
            toeicSwValidateFail("{$packageName}: missing manifest.json");
            continue;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            toeicSwValidateFail("{$packageName}: invalid JSON");
            continue;
        }

        if ((int)($manifest['package_number'] ?? 0) !== $package) {
            toeicSwValidateFail("{$packageName}: package_number mismatch");
        }

        foreach (['speaking' => 11, 'writing' => 8] as $section => $count) {
            $tasks = $manifest[$section] ?? [];
            if (count($tasks) !== $count) {
                toeicSwValidateFail("{$packageName}: {$section} count must be {$count}");
                continue;
            }
            foreach ($tasks as $task) {
                $number = (int)($task['question_number'] ?? 0);
                $expected = $blueprint[$section][$number] ?? null;
                if (!$expected) {
                    toeicSwValidateFail("{$packageName}: invalid {$section} question number {$number}");
                    continue;
                }
                if (($task['type'] ?? '') !== $expected['type']) {
                    toeicSwValidateFail("{$packageName}: {$section} Q{$number} type mismatch");
                }
                if (($task['difficulty'] ?? '') !== 'C2' || ($task['cefr_level'] ?? '') !== 'C2') {
                    toeicSwValidateFail("{$packageName}: {$section} Q{$number} must be C2");
                }
                if ($section === 'speaking' && toeicSwSpeakingUsesPromptAudio((string)($task['type'] ?? ''))) {
                    $audioPath = trim((string)($task['audio_path'] ?? ''));
                    if ($audioPath === '') {
                        toeicSwValidateFail("{$packageName}: Speaking Q{$number} missing audio_path");
                    } else {
                        $fullAudioPath = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $audioPath);
                        if (!file_exists($fullAudioPath)) {
                            toeicSwValidateFail("{$packageName}: missing audio {$audioPath}");
                        } elseif (filesize($fullAudioPath) < 12000) {
                            toeicSwValidateFail("{$packageName}: audio too small {$audioPath}");
                        }
                    }
                } elseif ($section === 'speaking' && !empty($task['audio_path'])) {
                    toeicSwValidateFail("{$packageName}: Speaking Q{$number} should not reference prompt audio for {$task['type']}");
                }
                if (($task['type'] ?? '') === 'write_sentence_based_on_picture' && count($task['required_words'] ?? []) !== 2) {
                    toeicSwValidateFail("{$packageName}: Writing Q{$number} must have two required words");
                }
            }
        }

        $images = [];
        foreach (array_merge($manifest['speaking'] ?? [], $manifest['writing'] ?? []) as $task) {
            if (!empty($task['image_path'])) {
                $images[$task['image_path']] = true;
                $path = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$task['image_path']);
                if (!file_exists($path)) {
                    toeicSwValidateFail("{$packageName}: missing image {$task['image_path']}");
                }
            }
        }
        if (count($images) !== 7) {
            toeicSwValidateFail("{$packageName}: must reference 7 unique images");
        }

        $audioFiles = [];
        foreach ($manifest['speaking'] ?? [] as $task) {
            if (!empty($task['audio_path'])) {
                $audioFiles[$task['audio_path']] = true;
            }
        }
        if (count($audioFiles) !== 7) {
            toeicSwValidateFail("{$packageName}: must reference 7 unique speaking prompt audio files");
        }
    }
}

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC SW package validation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Validated 10 TOEIC SW package manifests successfully.\n";
