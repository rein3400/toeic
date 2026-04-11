<?php
/**
 * WeaknessAnalyzer - TOEIC-only analysis for learning curriculum generation.
 */
class WeaknessAnalyzer {
    private mysqli $conn;

    private const PART_SKILLS = [
        '1' => ['photo_description', 'visual_comprehension'],
        '2' => ['question_response', 'short_listening_inference'],
        '3' => ['conversation_tracking', 'speaker_intent', 'detail_capture'],
        '4' => ['talk_tracking', 'detail_capture', 'main_idea_listening'],
        '5' => ['grammar', 'sentence_completion', 'vocabulary_usage'],
        '6' => ['text_completion', 'cohesion', 'grammar'],
        '7' => ['reading_comprehension', 'detail_finding', 'inference'],
    ];

    private const SKILL_DESCRIPTIONS = [
        'photo_description' => 'Memetakan detail visual ke pilihan jawaban Part 1',
        'visual_comprehension' => 'Membaca konteks visual dan aksi pada gambar',
        'question_response' => 'Memilih respons paling tepat untuk pertanyaan singkat',
        'short_listening_inference' => 'Menangkap maksud pembicara dari percakapan singkat',
        'conversation_tracking' => 'Mengikuti alur percakapan multi-speaker pada Part 3',
        'speaker_intent' => 'Menentukan tujuan atau maksud pembicara',
        'detail_capture' => 'Menangkap detail spesifik dari audio atau teks',
        'talk_tracking' => 'Mengikuti monolog/talk pada Part 4',
        'main_idea_listening' => 'Menangkap ide utama dari audio yang lebih panjang',
        'grammar' => 'Akurasi tata bahasa dan pola kalimat',
        'sentence_completion' => 'Melengkapi kalimat dengan struktur yang tepat',
        'vocabulary_usage' => 'Memilih kosakata yang paling tepat dalam konteks bisnis/kerja',
        'text_completion' => 'Menyelesaikan teks Part 6 dengan kohesi yang benar',
        'cohesion' => 'Membaca hubungan antar kalimat dan transisi',
        'reading_comprehension' => 'Memahami isi bacaan Part 7',
        'detail_finding' => 'Menemukan detail penting dalam bacaan',
        'inference' => 'Menarik kesimpulan implisit dari bacaan',
    ];

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function analyze(int $user_id, string $test_session): array {
        $stmt = $this->conn->prepare("
            SELECT section, part, is_correct, question_id
            FROM toeic_test_questions
            WHERE user_id = ? AND test_session = ?
            ORDER BY section, question_order
        ");
        $stmt->bind_param("is", $user_id, $test_session);
        $stmt->execute();
        $answers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($answers)) {
            return $this->emptyReport();
        }

        $sectionStats = [];
        $skillWeaknesses = [];
        $skillStrengths = [];
        $partStats = [];

        foreach ($answers as $answer) {
            $section = $answer['section'] ?? 'unknown';
            $part = (string)($answer['part'] ?? '');
            $correct = (int)($answer['is_correct'] ?? 0);

            if (!isset($sectionStats[$section])) {
                $sectionStats[$section] = ['total' => 0, 'correct' => 0, 'wrong' => 0, 'parts' => []];
            }
            if (!isset($sectionStats[$section]['parts'][$part])) {
                $sectionStats[$section]['parts'][$part] = ['total' => 0, 'correct' => 0, 'wrong' => 0];
            }
            if (!isset($partStats[$part])) {
                $partStats[$part] = ['total' => 0, 'correct' => 0, 'wrong' => 0];
            }

            $sectionStats[$section]['total']++;
            $sectionStats[$section][$correct ? 'correct' : 'wrong']++;
            $sectionStats[$section]['parts'][$part]['total']++;
            $sectionStats[$section]['parts'][$part][$correct ? 'correct' : 'wrong']++;
            $partStats[$part]['total']++;
            $partStats[$part][$correct ? 'correct' : 'wrong']++;

            $skills = self::PART_SKILLS[$part] ?? ['general_toeic'];
            foreach ($skills as $skill) {
                if ($correct) {
                    if (!isset($skillStrengths[$skill])) {
                        $skillStrengths[$skill] = [
                            'hits' => 0,
                            'attempts' => 0,
                            'description' => self::SKILL_DESCRIPTIONS[$skill] ?? $skill,
                            'section' => $section,
                        ];
                    }
                    $skillStrengths[$skill]['hits']++;
                    $skillStrengths[$skill]['attempts']++;
                } else {
                    if (!isset($skillWeaknesses[$skill])) {
                        $skillWeaknesses[$skill] = [
                            'count' => 0,
                            'description' => self::SKILL_DESCRIPTIONS[$skill] ?? $skill,
                            'section' => $section,
                            'part' => $part,
                        ];
                    }
                    $skillWeaknesses[$skill]['count']++;
                }

                if (isset($skillStrengths[$skill]) && !$correct) {
                    $skillStrengths[$skill]['attempts']++;
                }
            }
        }

        foreach ($skillStrengths as $skill => &$data) {
            $attempts = max(1, (int)$data['attempts']);
            $data['accuracy'] = round(($data['hits'] / $attempts) * 100);
            unset($data['hits'], $data['attempts']);
        }
        unset($data);

        foreach (array_keys($skillWeaknesses) as $skill) {
            unset($skillStrengths[$skill]);
        }

        uasort($skillWeaknesses, fn($a, $b) => $b['count'] <=> $a['count']);
        uasort($skillStrengths, fn($a, $b) => ($b['accuracy'] ?? 0) <=> ($a['accuracy'] ?? 0));

        $scores = $this->getScores($test_session);
        $accuracy = round(count(array_filter($answers, fn($a) => (int)($a['is_correct'] ?? 0) === 1)) / count($answers) * 100, 1);

        return [
            'user_id' => $user_id,
            'test_session' => $test_session,
            'total_questions' => count($answers),
            'total_correct' => count(array_filter($answers, fn($a) => (int)($a['is_correct'] ?? 0) === 1)),
            'overall_accuracy' => $accuracy,
            'scores' => $scores,
            'section_stats' => $sectionStats,
            'part_stats' => $partStats,
            'weak_skills' => $skillWeaknesses,
            'strong_skills' => $skillStrengths,
            'recommended_cefr_start' => $this->determineStartLevel($scores),
            'priority_areas' => array_slice(array_keys($skillWeaknesses), 0, 5),
        ];
    }

    private function getScores(string $test_session): array {
        $stmt = $this->conn->prepare("
            SELECT total_score, listening_scaled, reading_scaled, cefr_level
            FROM toeic_test_results
            WHERE test_session = ?
        ");
        $stmt->bind_param("s", $test_session);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: [];
    }

    private function determineStartLevel(array $scores): string {
        $total = (int)($scores['total_score'] ?? 0);
        if ($total >= 785) return 'B2';
        if ($total >= 605) return 'B1';
        if ($total >= 405) return 'A2';
        return 'A1';
    }

    private function emptyReport(): array {
        return [
            'total_questions' => 0,
            'total_correct' => 0,
            'overall_accuracy' => 0,
            'scores' => [],
            'section_stats' => [],
            'part_stats' => [],
            'weak_skills' => [],
            'strong_skills' => [],
            'recommended_cefr_start' => 'A1',
            'priority_areas' => [],
        ];
    }

    public static function getSkillDescriptions(): array {
        return self::SKILL_DESCRIPTIONS;
    }
}
