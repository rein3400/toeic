<?php
/**
 * Import ETS-format TOEIC Speaking & Writing package manifests.
 */

require_once __DIR__ . '/toeic_sw_helper.php';

class ToeicSwPackageImporter {
    private mysqli $conn;
    private string $root;
    private string $mediaBaseUrl = '';
    private bool $useRemoteMedia = false;
    private bool $verifyRemoteMedia = false;
    private bool $imageOnly = false;

    public function __construct(mysqli $conn, ?string $root = null) {
        $this->conn = $conn;
        $this->root = $root ?: dirname(__DIR__);
        ensureToeicSwSchema($this->conn);
    }

    public function import(string $packageRoot, bool $dryRun = true, array $options = []): array {
        $this->mediaBaseUrl = rtrim(trim((string)($options['media_base_url'] ?? '')), '/');
        $this->useRemoteMedia = !empty($options['use_remote_media']) && $this->mediaBaseUrl !== '';
        $this->verifyRemoteMedia = $this->useRemoteMedia && !empty($options['verify_remote_media']);
        $this->imageOnly = ($options['import_mode'] ?? '') === 'images_only';

        $stats = [
            'dry_run' => $dryRun,
            'import_mode' => $this->imageOnly ? 'images_only' : 'full',
            'packages' => 0,
            'validated' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'removed_stale' => 0,
            'audio_files' => 0,
            'audio_transcripts' => 0,
            'image_files' => 0,
            'remote_media' => $this->useRemoteMedia,
            'media_base_url' => $this->mediaBaseUrl,
            'media_verified' => $this->verifyRemoteMedia,
            'verified_media_urls' => 0,
            'errors' => [],
            'logs' => [],
        ];

        $packageRoot = rtrim($packageRoot, "/\\");
        if (!$dryRun) {
            $this->conn->begin_transaction();
        }

        for ($package = 1; $package <= 10; $package++) {
            $packageName = sprintf('package_%02d', $package);
            $packageDir = $packageRoot . DIRECTORY_SEPARATOR . $packageName;
            $manifestPath = $packageDir . DIRECTORY_SEPARATOR . 'manifest.json';
            $stats['packages']++;

            try {
                $manifest = $this->readJson($manifestPath);
                $this->validateManifest($manifest, $package, $packageDir, $stats);
                $stats['validated']++;

                if ($this->imageOnly) {
                    $this->updatePackageImages($manifest, $package, $packageName, $stats, $dryRun);
                } elseif (!$dryRun) {
                    $this->upsertPackage($manifest, $package, $packageName, $stats);
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "{$packageName}: " . $e->getMessage();
            }
        }

        if (!$dryRun) {
            if (empty($stats['errors'])) {
                $this->conn->commit();
                $stats['logs'][] = 'Import transaction committed.';
            } else {
                $this->conn->rollback();
                $stats['logs'][] = 'Import transaction rolled back because errors were found.';
            }
        }

        $stats['messages'] = $stats['logs'];
        $stats['error_messages'] = $stats['errors'];
        return $stats;
    }

    private function readJson(string $path): array {
        if (!file_exists($path)) {
            throw new RuntimeException('Missing manifest: ' . $path);
        }
        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid manifest JSON: ' . $path);
        }
        return $decoded;
    }

    private function validateManifest(array $manifest, int $package, string $packageDir, array &$stats): void {
        if ((int)($manifest['package_number'] ?? 0) !== $package) {
            throw new RuntimeException('package_number does not match directory.');
        }

        $blueprint = getToeicSwTaskBlueprint();
        $speaking = $manifest['speaking'] ?? [];
        $writing = $manifest['writing'] ?? [];
        if (count($speaking) !== 11) {
            throw new RuntimeException('Speaking must contain exactly 11 tasks.');
        }
        if (count($writing) !== 8) {
            throw new RuntimeException('Writing must contain exactly 8 tasks.');
        }

        $images = [];
        $audio = [];
        $taskIds = [];
        foreach (['speaking' => $speaking, 'writing' => $writing] as $section => $tasks) {
            $seenNumbers = [];
            foreach ($tasks as $task) {
                $number = (int)($task['question_number'] ?? 0);
                if (isset($seenNumbers[$number])) {
                    throw new RuntimeException("Duplicate {$section} question number: {$number}.");
                }
                $seenNumbers[$number] = true;

                $taskId = trim((string)($task['task_id'] ?? ''));
                if ($taskId !== '') {
                    if (isset($taskIds[$taskId])) {
                        throw new RuntimeException("Duplicate task_id: {$taskId}.");
                    }
                    $taskIds[$taskId] = true;
                }

                $expected = $blueprint[$section][$number] ?? null;
                if (!$expected) {
                    throw new RuntimeException("Invalid {$section} question number: {$number}.");
                }
                if (($task['type'] ?? '') !== $expected['type']) {
                    throw new RuntimeException("{$section} Q{$number} must be {$expected['type']}.");
                }

                $this->assertNoOfficialCopy($task, "{$section} Q{$number}");

                if (($task['difficulty'] ?? '') !== 'C2' || ($task['cefr_level'] ?? '') !== 'C2') {
                    throw new RuntimeException("{$section} Q{$number} must be locked to C2 difficulty.");
                }

                if (!$this->imageOnly && $section === 'speaking' && toeicSwSpeakingUsesPromptAudio((string)$task['type'])) {
                    $audioPath = trim((string)($task['audio_path'] ?? ''));
                    if ($audioPath === '') {
                        throw new RuntimeException("{$section} Q{$number} missing audio_path.");
                    }
                    $absoluteAudio = $packageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $audioPath);
                    if (!file_exists($absoluteAudio)) {
                        throw new RuntimeException("{$section} Q{$number} audio does not exist: {$audioPath}.");
                    }
                    if (filesize($absoluteAudio) < 1024) {
                        throw new RuntimeException("{$section} Q{$number} audio is too small to be valid: {$audioPath}.");
                    }
                    if ($this->verifyRemoteMedia) {
                        $this->assertRemoteMediaOk($this->resolveMediaPath(sprintf('package_%02d', $package), $audioPath), "{$section} Q{$number} audio");
                        $stats['verified_media_urls']++;
                    }
                    if ($this->audioTranscriptForTask($task) === '') {
                        throw new RuntimeException("{$section} Q{$number} missing audio transcript.");
                    }
                    $audio[$audioPath] = true;
                    $stats['audio_files']++;
                    $stats['audio_transcripts']++;
                } elseif (!$this->imageOnly && $section === 'speaking' && !empty($task['audio_path'])) {
                    throw new RuntimeException("{$section} Q{$number} should not reference prompt audio for {$task['type']}.");
                } elseif (!$this->imageOnly && $section === 'speaking' && $this->audioTranscriptForTask($task) !== '') {
                    throw new RuntimeException("{$section} Q{$number} should not reference prompt audio transcript for {$task['type']}.");
                }

                if (in_array($task['type'], ['describe_picture', 'write_sentence_based_on_picture'], true)) {
                    $imagePath = trim((string)($task['image_path'] ?? ''));
                    if ($imagePath === '') {
                        throw new RuntimeException("{$section} Q{$number} missing image_path.");
                    }
                    if (!file_exists($packageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath))) {
                        throw new RuntimeException("{$section} Q{$number} image does not exist: {$imagePath}.");
                    }
                    if ($this->verifyRemoteMedia) {
                        $this->assertRemoteMediaOk($this->resolveMediaPath(sprintf('package_%02d', $package), $imagePath), "{$section} Q{$number} image");
                        $stats['verified_media_urls']++;
                    }
                    $images[$imagePath] = true;
                    $stats['image_files']++;
                }

                if ($task['type'] === 'write_sentence_based_on_picture') {
                    $words = $task['required_words'] ?? [];
                    if (!is_array($words) || count($words) !== 2) {
                        throw new RuntimeException("Writing Q{$number} must have exactly two required words or phrases.");
                    }
                }
            }

            $expectedNumbers = range(1, count($blueprint[$section]));
            $actualNumbers = array_keys($seenNumbers);
            sort($actualNumbers);
            if ($actualNumbers !== $expectedNumbers) {
                throw new RuntimeException("{$section} question order must be exactly Q1-Q" . count($blueprint[$section]) . '.');
            }
        }

        if (count($images) !== 7) {
            throw new RuntimeException('Each package must reference exactly 7 unique images.');
        }
        if (!$this->imageOnly && count($audio) !== 7) {
            throw new RuntimeException('Each package must reference exactly 7 unique speaking prompt audio files.');
        }
    }

    private function assertNoOfficialCopy(array $task, string $context): void {
        $text = json_encode($task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $blocked = ['Educational Testing Service', 'official ETS', 'from ETS', 'ETS official'];
        foreach ($blocked as $needle) {
            if (stripos((string)$text, $needle) !== false) {
                throw new RuntimeException("{$context} contains disallowed official-source wording.");
            }
        }
    }

    private function upsertPackage(array $manifest, int $package, string $packageName, array &$stats): void {
        $this->deleteStalePackageRows($manifest, $package, $stats);

        foreach (['speaking', 'writing'] as $section) {
            foreach (($manifest[$section] ?? []) as $task) {
                $table = getToeicSwContentTableForType((string)$task['type']);
                if (!$table) {
                    throw new RuntimeException('Unknown task type: ' . ($task['type'] ?? ''));
                }

                $questionNumber = (int)$task['question_number'];
                $exists = $this->contentExists($table, $package, $questionNumber);
                $this->upsertTask($table, $package, $packageName, $task);
                if ($exists) {
                    $stats['updated']++;
                } else {
                    $stats['inserted']++;
                }
            }
        }
        $stats['logs'][] = "{$packageName}: imported.";
    }

    private function updatePackageImages(array $manifest, int $package, string $packageName, array &$stats, bool $dryRun): void {
        $planned = 0;

        foreach (['speaking', 'writing'] as $section) {
            foreach (($manifest[$section] ?? []) as $task) {
                if (!in_array($task['type'] ?? '', ['describe_picture', 'write_sentence_based_on_picture'], true)) {
                    continue;
                }

                $table = getToeicSwContentTableForType((string)$task['type']);
                if (!$table) {
                    throw new RuntimeException('Unknown image task type: ' . ($task['type'] ?? ''));
                }

                $questionNumber = (int)$task['question_number'];
                if (!$this->contentExists($table, $package, $questionNumber)) {
                    throw new RuntimeException("Cannot update image only; existing {$table} package {$package} Q{$questionNumber} was not found.");
                }

                $imagePath = $this->resolveMediaPath($packageName, (string)$task['image_path']);
                $planned++;

                if ($dryRun) {
                    continue;
                }

                $stmt = $this->conn->prepare("UPDATE {$table} SET image_path = ? WHERE package_number = ? AND question_number = ?");
                if (!$stmt) {
                    throw new RuntimeException("Unable to prepare image-only update for {$table}.");
                }
                $stmt->bind_param("sii", $imagePath, $package, $questionNumber);
                $stmt->execute();
                $stmt->close();
            }
        }

        $stats['updated'] += $planned;
        $stats['logs'][] = $dryRun
            ? "{$packageName}: image-only dry run planned {$planned} image URL updates."
            : "{$packageName}: image-only updated {$planned} image URLs; content and audio were preserved.";
    }

    private function deleteStalePackageRows(array $manifest, int $package, array &$stats): void {
        $expected = $this->expectedTableQuestionNumbers($manifest);

        foreach ($this->contentTables() as $table) {
            $numbers = array_keys($expected[$table] ?? []);
            if (empty($numbers)) {
                $stmt = $this->conn->prepare("DELETE FROM {$table} WHERE package_number = ?");
                $stmt->bind_param("i", $package);
            } else {
                $placeholders = implode(', ', array_fill(0, count($numbers), '?'));
                $types = 'i' . str_repeat('i', count($numbers));
                $values = array_merge([$package], $numbers);
                $stmt = $this->conn->prepare("DELETE FROM {$table} WHERE package_number = ? AND question_number NOT IN ({$placeholders})");
                $stmt->bind_param($types, ...$values);
            }

            $stmt->execute();
            $stats['removed_stale'] += max(0, $stmt->affected_rows);
            $stmt->close();
        }
    }

    private function expectedTableQuestionNumbers(array $manifest): array {
        $expected = [];
        foreach (['speaking', 'writing'] as $section) {
            foreach (($manifest[$section] ?? []) as $task) {
                $table = getToeicSwContentTableForType((string)($task['type'] ?? ''));
                if (!$table) {
                    continue;
                }
                $expected[$table][(int)$task['question_number']] = true;
            }
        }
        return $expected;
    }

    private function contentTables(): array {
        $tables = [];
        foreach (getToeicSwTaskBlueprint() as $section) {
            foreach ($section as $task) {
                $table = getToeicSwContentTableForType((string)$task['type']);
                if ($table) {
                    $tables[$table] = true;
                }
            }
        }
        return array_keys($tables);
    }

    private function contentExists(string $table, int $package, int $questionNumber): bool {
        $stmt = $this->conn->prepare("SELECT id FROM {$table} WHERE package_number = ? AND question_number = ? LIMIT 1");
        $stmt->bind_param("ii", $package, $questionNumber);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function upsertTask(string $table, int $package, string $packageName, array $task): void {
        $number = (int)$task['question_number'];
        $title = (string)($task['title'] ?? '');
        $prompt = (string)($task['prompt_text'] ?? $task['question_text'] ?? '');
        $sample = (string)($task['sample_response'] ?? '');
        $rubric = (string)($task['scoring_rubric'] ?? '');
        $difficulty = (string)($task['difficulty'] ?? 'advanced');
        $cefr = (string)($task['cefr_level'] ?? 'C1');
        $imagePath = !empty($task['image_path'])
            ? $this->resolveMediaPath($packageName, (string)$task['image_path'])
            : '';
        $audioPath = !empty($task['audio_path'])
            ? $this->resolveMediaPath($packageName, (string)$task['audio_path'])
            : '';
        $audioTranscript = $audioPath !== '' ? $this->audioTranscriptForTask($task) : '';

        switch ($table) {
            case 'toeic_sw_describe_picture':
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                    (package_number, question_number, title, prompt_text, sample_response, scoring_rubric, difficulty, cefr_level, image_path)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE title=VALUES(title), prompt_text=VALUES(prompt_text), sample_response=VALUES(sample_response), scoring_rubric=VALUES(scoring_rubric), difficulty=VALUES(difficulty), cefr_level=VALUES(cefr_level), image_path=VALUES(image_path)
                ");
                $stmt->bind_param("iisssssss", $package, $number, $title, $prompt, $sample, $rubric, $difficulty, $cefr, $imagePath);
                break;

            case 'toeic_sw_picture_sentence':
                $words = json_encode($task['required_words'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                    (package_number, question_number, title, prompt_text, sample_response, scoring_rubric, difficulty, cefr_level, image_path, required_words_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE title=VALUES(title), prompt_text=VALUES(prompt_text), sample_response=VALUES(sample_response), scoring_rubric=VALUES(scoring_rubric), difficulty=VALUES(difficulty), cefr_level=VALUES(cefr_level), image_path=VALUES(image_path), required_words_json=VALUES(required_words_json)
                ");
                $stmt->bind_param("iissssssss", $package, $number, $title, $prompt, $sample, $rubric, $difficulty, $cefr, $imagePath, $words);
                break;

            case 'toeic_sw_respond_information':
                $stimulusGroupId = (string)($task['stimulus_group_id'] ?? sprintf('pkg%02d-info', $package));
                $informationCard = (string)($task['information_card'] ?? '');
                $repeatQuestion = !empty($task['repeat_question']) ? 1 : 0;
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                    (package_number, question_number, title, prompt_text, sample_response, scoring_rubric, difficulty, cefr_level, stimulus_group_id, information_card, repeat_question)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE title=VALUES(title), prompt_text=VALUES(prompt_text), sample_response=VALUES(sample_response), scoring_rubric=VALUES(scoring_rubric), difficulty=VALUES(difficulty), cefr_level=VALUES(cefr_level), stimulus_group_id=VALUES(stimulus_group_id), information_card=VALUES(information_card), repeat_question=VALUES(repeat_question)
                ");
                $stmt->bind_param("iissssssssi", $package, $number, $title, $prompt, $sample, $rubric, $difficulty, $cefr, $stimulusGroupId, $informationCard, $repeatQuestion);
                break;

            case 'toeic_sw_written_request':
                $recipientType = (string)($task['recipient_type'] ?? 'client');
                $wordMin = (int)($task['word_limit_min'] ?? 80);
                $wordMax = (int)($task['word_limit_max'] ?? 120);
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                    (package_number, question_number, title, prompt_text, sample_response, scoring_rubric, difficulty, cefr_level, recipient_type, word_limit_min, word_limit_max)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE title=VALUES(title), prompt_text=VALUES(prompt_text), sample_response=VALUES(sample_response), scoring_rubric=VALUES(scoring_rubric), difficulty=VALUES(difficulty), cefr_level=VALUES(cefr_level), recipient_type=VALUES(recipient_type), word_limit_min=VALUES(word_limit_min), word_limit_max=VALUES(word_limit_max)
                ");
                $stmt->bind_param("iisssssssii", $package, $number, $title, $prompt, $sample, $rubric, $difficulty, $cefr, $recipientType, $wordMin, $wordMax);
                break;

            case 'toeic_sw_opinion_essay':
                $minimumWords = (int)($task['minimum_words'] ?? 300);
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                    (package_number, question_number, title, prompt_text, sample_response, scoring_rubric, difficulty, cefr_level, minimum_words)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE title=VALUES(title), prompt_text=VALUES(prompt_text), sample_response=VALUES(sample_response), scoring_rubric=VALUES(scoring_rubric), difficulty=VALUES(difficulty), cefr_level=VALUES(cefr_level), minimum_words=VALUES(minimum_words)
                ");
                $stmt->bind_param("iissssssi", $package, $number, $title, $prompt, $sample, $rubric, $difficulty, $cefr, $minimumWords);
                break;

            default:
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                    (package_number, question_number, title, prompt_text, sample_response, scoring_rubric, difficulty, cefr_level)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE title=VALUES(title), prompt_text=VALUES(prompt_text), sample_response=VALUES(sample_response), scoring_rubric=VALUES(scoring_rubric), difficulty=VALUES(difficulty), cefr_level=VALUES(cefr_level)
                ");
                $stmt->bind_param("iissssss", $package, $number, $title, $prompt, $sample, $rubric, $difficulty, $cefr);
                break;
        }

        $stmt->execute();
        $stmt->close();

        $this->updateAudioMetadata($table, $package, $number, $audioPath, $audioTranscript);
    }

    private function audioTranscriptForTask(array $task): string {
        $transcript = trim((string)($task['audio_transcript'] ?? ''));
        if ($transcript !== '') {
            return $transcript;
        }

        return trim((string)($task['audio_script'] ?? ''));
    }

    private function resolveMediaPath(string $packageName, string $relativePath): string {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (preg_match('#^https?://#i', $relativePath)) {
            return $relativePath;
        }

        if ($this->useRemoteMedia) {
            return "{$this->mediaBaseUrl}/toeic/sw/{$packageName}/{$relativePath}";
        }

        return "content/generated/toeic_sw/{$packageName}/{$relativePath}";
    }

    private function assertRemoteMediaOk(string $url, string $label): void {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $headers = @get_headers($url, true, $context);
        $statusLine = is_array($headers) ? (string)($headers[0] ?? '') : '';
        if (!preg_match('#\s(200|206)\s#', $statusLine)) {
            throw new RuntimeException("{$label} URL is not reachable: {$url}");
        }
    }

    private function updateAudioMetadata(string $table, int $package, int $questionNumber, string $audioPath, string $audioTranscript): void {
        $stmt = $this->conn->prepare("UPDATE {$table} SET audio_path = ?, audio_transcript = ? WHERE package_number = ? AND question_number = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("ssii", $audioPath, $audioTranscript, $package, $questionNumber);
        $stmt->execute();
        $stmt->close();
    }
}
?>
