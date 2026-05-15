<?php
/**
 * CurriculumGenerator - TOEIC-only personalized curriculum generation.
 */
require_once __DIR__ . '/weakness_analyzer.php';
require_once __DIR__ . '/ai_helper.php';
require_once __DIR__ . '/settings.php';

class CurriculumGenerator {
    private mysqli $conn;
    private array $config;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->config = $this->loadApiConfig();
    }

    private function loadApiConfig(): array {
        $curriculum_api = getSiteSetting('curriculum_ai_api', '');
        if (!empty($curriculum_api)) {
            $config = json_decode(getSiteSetting($curriculum_api, ''), true);
            if (!empty($config['api_key'])) {
                $override = trim(getSiteSetting('curriculum_ai_model_override', ''));
                if ($override !== '') {
                    $config['llm'] = $override;
                }
                return $config;
            }
        }

        $active = getActiveAIProvider();
        if (!$active || empty($active['api_key'])) {
            throw new Exception('No active AI API configured');
        }

        return $active;
    }

    public function generate(int $user_id, string $test_session): array {
        $existing = $this->getExisting($user_id, $test_session);
        if ($existing && $existing['status'] === 'ready') {
            return ['success' => true, 'curriculum_id' => $existing['id'], 'cached' => true];
        }

        $weakness = strpos($test_session, 'toeic_sw_') === 0
            ? $this->analyzeToeicSwWeakness($user_id, $test_session)
            : (new WeaknessAnalyzer($this->conn))->analyze($user_id, $test_session);

        $stmt = $this->conn->prepare("
            INSERT INTO learning_curriculum (user_id, test_session, weakness_analysis, status, ai_provider)
            VALUES (?, ?, ?, 'generating', ?)
        ");
        $weaknessJson = json_encode($weakness, JSON_UNESCAPED_UNICODE);
        $providerInfo = ($this->config['provider'] ?? 'AI') . '/' . ($this->config['llm'] ?? 'model');
        $stmt->bind_param("isss", $user_id, $test_session, $weaknessJson, $providerInfo);
        $stmt->execute();
        $curriculum_id = (int)$this->conn->insert_id;
        $stmt->close();

        try {
            $syllabus = $this->generateSyllabus($weakness);
            $syllabusJson = json_encode($syllabus, JSON_UNESCAPED_UNICODE);
            $stmt = $this->conn->prepare("UPDATE learning_curriculum SET syllabus = ?, status = 'ready' WHERE id = ?");
            $stmt->bind_param("si", $syllabusJson, $curriculum_id);
            $stmt->execute();
            $stmt->close();

            $moduleOrder = 0;
            foreach ($syllabus['modules'] as $modulePlan) {
                $moduleOrder++;
                $title = $modulePlan['title'] ?? "Module $moduleOrder";
                $section = $modulePlan['section'] ?? 'reading';
                $skill = $modulePlan['skill_category'] ?? '';
                $cefr = $modulePlan['cefr_level'] ?? 'A2';
                $minutes = (int)($modulePlan['estimated_minutes'] ?? 45);
                $status = $moduleOrder === 1 ? 'available' : 'locked';

                $stmt = $this->conn->prepare("
                    INSERT INTO learning_modules (curriculum_id, module_order, title, section, skill_category, cefr_level, content_html, exercises_json, estimated_minutes, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, '[]', ?, ?)
                ");
                $placeholder = '';
                $stmt->bind_param("iisssssis", $curriculum_id, $moduleOrder, $title, $section, $skill, $cefr, $placeholder, $minutes, $status);
                $stmt->execute();
                $stmt->close();
            }

            return ['success' => true, 'curriculum_id' => $curriculum_id, 'modules' => $moduleOrder];
        } catch (Throwable $e) {
            $stmt = $this->conn->prepare("UPDATE learning_curriculum SET status = 'failed' WHERE id = ?");
            $stmt->bind_param("i", $curriculum_id);
            $stmt->execute();
            $stmt->close();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function fillModuleContent(int $module_id): array {
        $stmt = $this->conn->prepare("
            SELECT m.*, c.weakness_analysis, c.syllabus
            FROM learning_modules m
            JOIN learning_curriculum c ON m.curriculum_id = c.id
            WHERE m.id = ?
        ");
        $stmt->bind_param("i", $module_id);
        $stmt->execute();
        $module = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$module) {
            return ['success' => false, 'error' => 'Module not found'];
        }

        if (!empty($module['content_html']) && strpos($module['content_html'], 'fa-spinner') === false) {
            return ['success' => true, 'already_generated' => true];
        }

        $syllabus = json_decode($module['syllabus'] ?? '{}', true);
        $modulePlan = $syllabus['modules'][$module['module_order'] - 1] ?? [
            'title' => $module['title'],
            'section' => $module['section'],
            'skill_category' => $module['skill_category'],
            'cefr_level' => $module['cefr_level'],
            'topics' => [],
            'description' => '',
        ];

        $prompt = $this->buildModulePrompt(
            $modulePlan['title'] ?? $module['title'],
            $modulePlan['section'] ?? $module['section'],
            $modulePlan['skill_category'] ?? $module['skill_category'],
            $modulePlan['cefr_level'] ?? $module['cefr_level'],
            implode(', ', $modulePlan['topics'] ?? []),
            $modulePlan['description'] ?? ''
        );

        $response = $this->requestAI($prompt, 16000);
        $decoded = $this->parseJsonResponse($response);
        if (!$decoded) {
            return ['success' => false, 'error' => 'Invalid AI response'];
        }

        $contentHtml = $decoded['content_html'] ?? '';
        $exercises = is_array($decoded['exercises'] ?? null) ? $decoded['exercises'] : [];
        $exercisesJson = json_encode($exercises, JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare("UPDATE learning_modules SET content_html = ?, exercises_json = ? WHERE id = ?");
        $stmt->bind_param("ssi", $contentHtml, $exercisesJson, $module_id);
        $stmt->execute();
        $stmt->close();

        $cleanup = $this->conn->prepare("DELETE FROM learning_exercises WHERE module_id = ?");
        $cleanup->bind_param("i", $module_id);
        $cleanup->execute();
        $cleanup->close();

        foreach ($exercises as $i => $ex) {
            $order = $i + 1;
            $type = $ex['type'] ?? 'multiple_choice';
            $question = $ex['question_html'] ?? '';
            $optionsJson = isset($ex['options']) ? json_encode($ex['options'], JSON_UNESCAPED_UNICODE) : null;
            $correct = $ex['correct_answer'] ?? '';
            $explanation = $ex['explanation_html'] ?? '';
            $points = (int)($ex['points'] ?? 10);

            $stmt = $this->conn->prepare("
                INSERT INTO learning_exercises (module_id, exercise_order, type, question_html, options_json, correct_answer, explanation_html, points)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisssssi", $module_id, $order, $type, $question, $optionsJson, $correct, $explanation, $points);
            $stmt->execute();
            $stmt->close();
        }

        return ['success' => true, 'exercises_count' => count($exercises)];
    }

    private function analyzeToeicSwWeakness(int $user_id, string $test_session): array {
        $stmt = $this->conn->prepare("
            SELECT speaking_scaled, writing_scaled, total_score, cefr_level
            FROM toeic_sw_test_results
            WHERE user_id = ? AND test_session = ?
            LIMIT 1
        ");
        $stmt->bind_param("is", $user_id, $test_session);
        $stmt->execute();
        $scores = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $stmt = $this->conn->prepare("
            SELECT q.section, q.question_type, q.question_order, q.is_correct,
                   s.normalized_score, s.feedback_json, s.fallback_reason, s.status
            FROM toeic_sw_test_questions q
            LEFT JOIN toeic_sw_subjective_scores s
              ON s.test_session = q.test_session
             AND s.question_row_id = q.id
            WHERE q.user_id = ? AND q.test_session = ?
            ORDER BY FIELD(q.section, 'speaking', 'writing'), q.question_order
        ");
        $stmt->bind_param("is", $user_id, $test_session);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $skillDescriptions = [
            'read_text_aloud' => 'Pronunciation, pacing, and accurate read-aloud delivery',
            'describe_picture' => 'Detailed picture description and organized visual language',
            'respond_to_questions' => 'Direct spoken responses with complete reasons and examples',
            'respond_using_information' => 'Using schedule or briefing information accurately under time pressure',
            'express_opinion' => 'Clear spoken opinion with structure, support, and fluency',
            'write_sentence_based_on_picture' => 'Accurate sentence writing using required words and visual context',
            'respond_to_written_request' => 'Professional written reply with complete task coverage',
            'write_opinion_essay' => 'Organized opinion essay with relevant support and control',
        ];

        $sectionStats = [];
        $weakSkills = [];
        $strongSkills = [];
        $sum = 0.0;

        foreach ($rows as $row) {
            $section = (string)($row['section'] ?? 'unknown');
            $type = (string)($row['question_type'] ?? 'general_sw');
            $score = $row['normalized_score'] !== null
                ? (float)$row['normalized_score']
                : (float)($row['is_correct'] ?? 0);

            if (!isset($sectionStats[$section])) {
                $sectionStats[$section] = ['total' => 0, 'normalized_sum' => 0.0, 'needs_rescore' => 0];
            }
            $sectionStats[$section]['total']++;
            $sectionStats[$section]['normalized_sum'] += $score;
            if (($row['status'] ?? '') === 'needs_rescore') {
                $sectionStats[$section]['needs_rescore']++;
            }

            $sum += $score;
            $description = $skillDescriptions[$type] ?? str_replace('_', ' ', $type);
            $feedback = json_decode((string)($row['feedback_json'] ?? ''), true);
            $summary = is_array($feedback)
                ? (string)($feedback['feedback_summary'] ?? $feedback['fallback_reason'] ?? '')
                : (string)($row['fallback_reason'] ?? '');

            if ($score < 0.6 || ($row['status'] ?? '') === 'needs_rescore') {
                if (!isset($weakSkills[$type])) {
                    $weakSkills[$type] = [
                        'count' => 0,
                        'description' => $description,
                        'section' => $section,
                        'evidence' => [],
                    ];
                }
                $weakSkills[$type]['count']++;
                if ($summary !== '' && count($weakSkills[$type]['evidence']) < 3) {
                    $weakSkills[$type]['evidence'][] = $summary;
                }
            } elseif ($score >= 0.75) {
                if (!isset($strongSkills[$type])) {
                    $strongSkills[$type] = [
                        'accuracy' => round($score * 100),
                        'description' => $description,
                        'section' => $section,
                    ];
                }
            }
        }

        foreach ($sectionStats as &$stat) {
            $stat['average_normalized'] = $stat['total'] > 0
                ? round($stat['normalized_sum'] / $stat['total'], 4)
                : 0.0;
            unset($stat['normalized_sum']);
        }
        unset($stat);

        uasort($weakSkills, fn($a, $b) => $b['count'] <=> $a['count']);
        uasort($strongSkills, fn($a, $b) => ($b['accuracy'] ?? 0) <=> ($a['accuracy'] ?? 0));

        $total = count($rows);
        $average = $total > 0 ? $sum / $total : 0.0;

        return [
            'format' => 'toeic_sw',
            'user_id' => $user_id,
            'test_session' => $test_session,
            'total_questions' => $total,
            'overall_accuracy' => round($average * 100, 1),
            'scores' => $scores,
            'section_stats' => $sectionStats,
            'weak_skills' => $weakSkills,
            'strong_skills' => $strongSkills,
            'recommended_cefr_start' => (string)($scores['cefr_level'] ?? 'A2'),
            'priority_areas' => array_slice(array_keys($weakSkills), 0, 5),
        ];
    }

    private function generateSyllabus(array $weakness): array {
        if (($weakness['format'] ?? '') === 'toeic_sw') {
            return $this->generateToeicSwSyllabus($weakness);
        }

        $weakSkillsList = [];
        foreach ($weakness['weak_skills'] as $skill => $data) {
            $weakSkillsList[] = "- {$data['description']} ({$skill})";
        }

        $strongSkillsList = [];
        foreach ($weakness['strong_skills'] as $skill => $data) {
            $strongSkillsList[] = "- {$data['description']} ({$skill})";
        }

        $prompt = <<<PROMPT
Kamu adalah perancang kurikulum TOEIC Listening & Reading.

Data siswa:
- Total soal: {$weakness['total_questions']}
- Total benar: {$weakness['total_correct']}
- Akurasi: {$weakness['overall_accuracy']}%
- Skor TOEIC terbaru: {$weakness['scores']['total_score']}
- Level awal rekomendasi: {$weakness['recommended_cefr_start']}

Kelemahan utama:
{implode("\n", $weakSkillsList)}

Kekuatan utama:
{implode("\n", $strongSkillsList)}

Buat syllabus TOEIC personal 6-8 modul. Setiap modul harus fokus pada skill TOEIC yang lemah dan relevan dengan Part 1-7.

Output JSON valid saja:
{
  "title": "Kurikulum Personal TOEIC",
  "target_cefr": "B1",
  "estimated_weeks": 4,
  "modules": [
    {
      "title": "Judul modul",
      "section": "listening|reading|grammar|vocabulary",
      "skill_category": "nama_skill",
      "cefr_level": "A1|A2|B1|B2",
      "description": "deskripsi singkat",
      "topics": ["topik 1", "topik 2"],
      "estimated_minutes": 45
    }
  ]
}
PROMPT;

        $prompt = str_replace(
            ['{implode("\n", $weakSkillsList)}', '{implode("\n", $strongSkillsList)}'],
            [implode("\n", $weakSkillsList), implode("\n", $strongSkillsList)],
            $prompt
        );

        $decoded = $this->parseJsonResponse($this->requestAI($prompt, 4000));
        if (!$decoded || !isset($decoded['modules']) || !is_array($decoded['modules'])) {
            throw new Exception('Failed to generate TOEIC syllabus');
        }

        return $decoded;
    }

    private function generateToeicSwSyllabus(array $weakness): array {
        $weakSkillsList = [];
        foreach (($weakness['weak_skills'] ?? []) as $skill => $data) {
            $evidence = '';
            if (!empty($data['evidence']) && is_array($data['evidence'])) {
                $evidence = ' Evidence: ' . implode(' | ', array_slice(array_map('strval', $data['evidence']), 0, 2));
            }
            $weakSkillsList[] = "- {$data['description']} ({$skill}, {$data['section']}){$evidence}";
        }

        $strongSkillsList = [];
        foreach (($weakness['strong_skills'] ?? []) as $skill => $data) {
            $strongSkillsList[] = "- {$data['description']} ({$skill}, {$data['section']})";
        }

        $scores = $weakness['scores'] ?? [];
        $speakingScaled = (int)($scores['speaking_scaled'] ?? 0);
        $writingScaled = (int)($scores['writing_scaled'] ?? 0);
        $totalScore = (int)($scores['total_score'] ?? 0);
        $cefrLevel = (string)($scores['cefr_level'] ?? $weakness['recommended_cefr_start'] ?? 'A2');
        $prompt = <<<PROMPT
Kamu adalah perancang kurikulum TOEIC Speaking & Writing.

Data siswa:
- Speaking scaled: {$speakingScaled}
- Writing scaled: {$writingScaled}
- Total SW score: {$totalScore}
- CEFR: {$cefrLevel}
- Akurasi/normalized rata-rata: {$weakness['overall_accuracy']}%
- Level awal rekomendasi: {$weakness['recommended_cefr_start']}

Kelemahan utama:
{weakness_list}

Kekuatan utama:
{strength_list}

Buat syllabus personal TOEIC Speaking & Writing 6-8 modul. Modul harus actionable dan fokus pada task SW: read aloud, describe picture, spoken response, use provided information, express opinion, sentence writing, written request, dan opinion essay.

Output JSON valid saja:
{
  "title": "Kurikulum Personal TOEIC Speaking & Writing",
  "target_cefr": "B1",
  "estimated_weeks": 4,
  "modules": [
    {
      "title": "Judul modul",
      "section": "speaking|writing",
      "skill_category": "nama_skill",
      "cefr_level": "A1|A2|B1|B2|C1",
      "description": "deskripsi singkat",
      "topics": ["topik 1", "topik 2"],
      "estimated_minutes": 45
    }
  ]
}
PROMPT;

        $prompt = str_replace(
            ['{weakness_list}', '{strength_list}'],
            [implode("\n", $weakSkillsList) ?: '- General TOEIC SW task completion', implode("\n", $strongSkillsList) ?: '- No clear strong skill yet'],
            $prompt
        );

        $decoded = $this->parseJsonResponse($this->requestAI($prompt, 4000));
        if (!$decoded || !isset($decoded['modules']) || !is_array($decoded['modules'])) {
            throw new Exception('Failed to generate TOEIC SW syllabus');
        }

        return $decoded;
    }

    private function buildModulePrompt(string $title, string $section, string $skill, string $cefr, string $topics, string $description): string {
        return <<<PROMPT
Kamu adalah guru TOEIC dan penulis modul belajar.

MODUL:
- Judul: {$title}
- Section: {$section}
- Skill: {$skill}
- CEFR: {$cefr}
- Topik: {$topics}
- Deskripsi: {$description}

Buat modul belajar TOEIC yang benar-benar mengajar, bukan sekadar tips.

Output JSON valid saja:
{
  "content_html": "<div class='module-content'>...</div>",
  "exercises": [
    {
      "type": "multiple_choice",
      "question_html": "<p>Soal</p>",
      "options": ["Pilihan A", "Pilihan B", "Pilihan C", "Pilihan D"],
      "correct_answer": "Pilihan B",
      "explanation_html": "<p>Penjelasan kenapa jawaban benar.</p>",
      "points": 10
    }
  ]
}

PANDUAN:
- Materi minimal 1000 kata, bilingual penjelasan Indonesia + contoh English
- Fokus sesuai section modul: Listening/Reading untuk TOEIC LR, atau Speaking/Writing untuk TOEIC SW
- Gunakan konteks workplace/business dan format latihan yang cocok dengan task TOEIC
- Buat 8-10 latihan dengan explanation_html yang jelas
PROMPT;
    }

    private function requestAI(string $prompt, int $max_tokens = 8000): string {
        $response = callAI($prompt, $this->config, $max_tokens, [], 240000);
        if (!is_string($response) || trim($response) === '') {
            throw new Exception('AI response is empty');
        }
        return $response;
    }

    private function parseJsonResponse(string $response): ?array {
        $response = trim($response);
        $response = preg_replace('/<think>.*?<\/think>/is', '', $response);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = substr($response, $start, $end - $start + 1);
        foreach ([$candidate, preg_replace('/,\s*([}\]])/', '$1', $candidate)] as $json) {
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    private function getExisting(int $user_id, string $test_session): ?array {
        $stmt = $this->conn->prepare("
            SELECT id, status
            FROM learning_curriculum
            WHERE user_id = ? AND test_session = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("is", $user_id, $test_session);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
