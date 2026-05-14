<?php
/**
 * Contract checks for the TOEIC Speaking & Writing package implementation.
 *
 * This intentionally avoids a live database so it can run in early bootstrap
 * environments. It verifies the ETS-format invariants, route surface, and
 * generated package manifests that the runtime depends on.
 */

$root = dirname(__DIR__);
$failures = [];

function toeic_sw_check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$helperPath = $root . '/includes/toeic_sw_helper.php';
toeic_sw_check(file_exists($helperPath), 'includes/toeic_sw_helper.php must exist.');

if (file_exists($helperPath)) {
    require_once $helperPath;

    toeic_sw_check(function_exists('getToeicSwSectionOrder'), 'getToeicSwSectionOrder() must exist.');
    toeic_sw_check(function_exists('getToeicSwTaskBlueprint'), 'getToeicSwTaskBlueprint() must exist.');
    toeic_sw_check(function_exists('getToeicSwPackageRequirements'), 'getToeicSwPackageRequirements() must exist.');

    if (function_exists('getToeicSwSectionOrder')) {
        toeic_sw_check(getToeicSwSectionOrder() === ['speaking', 'writing'], 'SW section order must be Speaking then Writing.');
    }

    if (function_exists('getToeicSwTaskBlueprint')) {
        $blueprint = getToeicSwTaskBlueprint();
        $speaking = $blueprint['speaking'] ?? [];
        $writing = $blueprint['writing'] ?? [];

        toeic_sw_check(count($speaking) === 11, 'Speaking blueprint must contain exactly 11 questions.');
        toeic_sw_check(count($writing) === 8, 'Writing blueprint must contain exactly 8 questions.');

        $expectedSpeaking = [
            1 => ['type' => 'read_text_aloud', 'prepare_seconds' => 45, 'response_seconds' => 45],
            2 => ['type' => 'read_text_aloud', 'prepare_seconds' => 45, 'response_seconds' => 45],
            3 => ['type' => 'describe_picture', 'prepare_seconds' => 45, 'response_seconds' => 30],
            4 => ['type' => 'describe_picture', 'prepare_seconds' => 45, 'response_seconds' => 30],
            5 => ['type' => 'respond_to_questions', 'prepare_seconds' => 3, 'response_seconds' => 15],
            6 => ['type' => 'respond_to_questions', 'prepare_seconds' => 3, 'response_seconds' => 15],
            7 => ['type' => 'respond_to_questions', 'prepare_seconds' => 3, 'response_seconds' => 30],
            8 => ['type' => 'respond_using_information', 'prepare_seconds' => 3, 'response_seconds' => 15, 'read_seconds' => 45],
            9 => ['type' => 'respond_using_information', 'prepare_seconds' => 3, 'response_seconds' => 15, 'read_seconds' => 45],
            10 => ['type' => 'respond_using_information', 'prepare_seconds' => 3, 'response_seconds' => 30, 'read_seconds' => 45, 'repeat_question' => true],
            11 => ['type' => 'express_opinion', 'prepare_seconds' => 45, 'response_seconds' => 60],
        ];

        foreach ($expectedSpeaking as $number => $expected) {
            $actual = $speaking[$number] ?? null;
            toeic_sw_check(is_array($actual), "Speaking Q{$number} must exist in the blueprint.");
            if (!is_array($actual)) {
                continue;
            }
            foreach ($expected as $key => $value) {
                toeic_sw_check(($actual[$key] ?? null) === $value, "Speaking Q{$number} {$key} must be " . var_export($value, true) . '.');
            }
        }

        for ($i = 1; $i <= 5; $i++) {
            toeic_sw_check(($writing[$i]['type'] ?? null) === 'write_sentence_based_on_picture', "Writing Q{$i} must be picture sentence.");
            toeic_sw_check(($writing[$i]['required_words_count'] ?? null) === 2, "Writing Q{$i} must require exactly two words or phrases.");
        }
        for ($i = 6; $i <= 7; $i++) {
            toeic_sw_check(($writing[$i]['type'] ?? null) === 'respond_to_written_request', "Writing Q{$i} must be written request.");
            toeic_sw_check(($writing[$i]['task_minutes'] ?? null) === 10, "Writing Q{$i} must have a 10-minute task timer.");
        }
        toeic_sw_check(($writing[8]['type'] ?? null) === 'write_opinion_essay', 'Writing Q8 must be opinion essay.');
        toeic_sw_check(($writing[8]['minimum_words'] ?? null) === 300, 'Writing Q8 must use 300 words as the quality target.');
    }

    if (function_exists('getToeicSwPackageRequirements')) {
        $requirements = getToeicSwPackageRequirements();
        toeic_sw_check(($requirements['packages'] ?? null) === 10, 'Package requirement must be 10 packages.');
        toeic_sw_check(($requirements['speaking_per_package'] ?? null) === 11, 'Each package must contain 11 Speaking questions.');
        toeic_sw_check(($requirements['writing_per_package'] ?? null) === 8, 'Each package must contain 8 Writing questions.');
        toeic_sw_check(($requirements['images_per_package'] ?? null) === 7, 'Each package must contain 7 original images.');
    }
}

$routeFiles = [
    'user/test_toeic_sw.php',
    'user/ajax_save_toeic_sw_answer.php',
    'user/ajax_save_toeic_sw_recording.php',
    'user/ajax_submit_section_toeic_sw.php',
    'user/result_toeic_sw.php',
    'user/js/test_toeic_sw.js',
    'includes/toeic_sw_test_builder.php',
    'includes/toeic_sw_scorer.php',
    'includes/toeic_sw_subjective_scorer.php',
    'includes/toeic_sw_package_importer.php',
    'admin/import_toeic_sw_packages.php',
    'scripts/generate_toeic_sw_packages.php',
    'scripts/validate_toeic_sw_packages.php',
];

foreach ($routeFiles as $relativePath) {
    toeic_sw_check(file_exists($root . '/' . $relativePath), "{$relativePath} must exist.");
}

$aiSettingsPath = $root . '/admin/ai_api_settings.php';
if (file_exists($aiSettingsPath)) {
    $aiSettingsSource = file_get_contents($aiSettingsPath);
    toeic_sw_check(strpos($aiSettingsSource, 'toeic_sw_scoring_ai_api') !== false, 'AI settings must expose TOEIC SW scoring provider selection.');
    toeic_sw_check(strpos($aiSettingsSource, 'toeic_sw_transcription_ai_api') !== false, 'AI settings must expose TOEIC SW transcription provider selection.');
    toeic_sw_check(strpos($aiSettingsSource, 'toeic_sw_scoring_model') !== false, 'AI settings must expose TOEIC SW scoring model override.');
    toeic_sw_check(strpos($aiSettingsSource, 'toeic_sw_transcription_model') !== false, 'AI settings must expose TOEIC SW transcription model override.');
}

$subjectiveScorerPath = $root . '/includes/toeic_sw_subjective_scorer.php';
if (file_exists($subjectiveScorerPath)) {
    $subjectiveScorerSource = file_get_contents($subjectiveScorerPath);
    toeic_sw_check(strpos($subjectiveScorerSource, 'toeic_sw_scoring_ai_api') !== false, 'TOEIC SW scorer must read the scoring provider setting.');
    toeic_sw_check(strpos($subjectiveScorerSource, 'toeic_sw_transcription_ai_api') !== false, 'TOEIC SW scorer must read the transcription provider setting.');
    toeic_sw_check(strpos($subjectiveScorerSource, 'api.groq.com/openai/v1/audio/transcriptions') !== false, 'TOEIC SW transcription must support Groq audio transcription.');
    toeic_sw_check(strpos($subjectiveScorerSource, ':generateContent') !== false, 'TOEIC SW transcription must support Gemini audio transcription.');
}

$packageRoot = $root . '/content/generated/toeic_sw';
toeic_sw_check(is_dir($packageRoot), 'content/generated/toeic_sw must exist.');

for ($package = 1; $package <= 10; $package++) {
    $packageName = sprintf('package_%02d', $package);
    $manifestPath = $packageRoot . '/' . $packageName . '/manifest.json';
    toeic_sw_check(file_exists($manifestPath), "{$packageName}/manifest.json must exist.");
    if (!file_exists($manifestPath)) {
        continue;
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    toeic_sw_check(is_array($manifest), "{$packageName}/manifest.json must be valid JSON.");
    if (!is_array($manifest)) {
        continue;
    }

    $speaking = $manifest['speaking'] ?? [];
    $writing = $manifest['writing'] ?? [];
    toeic_sw_check(count($speaking) === 11, "{$packageName} must contain 11 Speaking tasks.");
    toeic_sw_check(count($writing) === 8, "{$packageName} must contain 8 Writing tasks.");

    $images = [];
    $speakingPromptAudio = 0;
    $speakingPromptAudioTranscripts = 0;
    foreach (array_merge($speaking, $writing) as $task) {
        if (!empty($task['image_path'])) {
            $images[$task['image_path']] = true;
            toeic_sw_check(file_exists($packageRoot . '/' . $packageName . '/' . $task['image_path']), "{$packageName} image {$task['image_path']} must exist.");
        }
        if (($task['type'] ?? '') && function_exists('toeicSwSpeakingUsesPromptAudio') && toeicSwSpeakingUsesPromptAudio((string)$task['type'])) {
            $speakingPromptAudio += empty($task['audio_path']) ? 0 : 1;
            $audioTranscript = trim((string)($task['audio_transcript'] ?? $task['audio_script'] ?? ''));
            $speakingPromptAudioTranscripts += $audioTranscript === '' ? 0 : 1;
        }
    }
    toeic_sw_check(count($images) === 7, "{$packageName} must reference exactly 7 unique images.");
    toeic_sw_check($speakingPromptAudio === 7, "{$packageName} must reference exactly 7 speaking prompt audio files.");
    toeic_sw_check($speakingPromptAudioTranscripts === 7, "{$packageName} must include exactly 7 speaking prompt audio transcripts.");
}

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC SW contract checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TOEIC SW contract checks passed.\n";
