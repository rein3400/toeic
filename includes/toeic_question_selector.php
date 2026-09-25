<?php
declare(strict_types=1);
require_once __DIR__ . '/toeic_question_identity.php';

/** Plan unique TOEIC question assignments before any database writes. */
final class ToeicQuestionSelector {
    private const PHOTO_VALIDATION_BUDGET_SECONDS = 12.0;
    private Closure $photoValidator;

    /** Accept an explicit validation seam for tests; production uses the real media checker. */
    public function __construct(?callable $photoValidator = null) {
        $this->photoValidator = $photoValidator !== null ? Closure::fromCallable($photoValidator)
            : static function (string $path): bool {
                require_once __DIR__ . '/toeic_asset_storage.php';
                return toeicPhotoIsUsable($path);
            };
    }

    /** Select an exact quota from logical questions, never from duplicate row counts. */
    public function select(array $rows, string $part, int $target, array $history = []): array {
        if (!in_array($part, ['1', '2', '3', '4', '5', '6', '7'], true) || $target < 1) {
            throw new InvalidArgumentException('Invalid TOEIC part or question quota.');
        }
        $rows = $this->eligibleRows($rows, $part);
        if (in_array($part, ['3', '4', '6', '7'], true)) {
            return $this->selectGroups($rows, $part, $target, $history);
        }
        $unique = [];
        foreach ($rows as $row) {
            $signature = toeicQuestionContentSignature($row, $part);
            if (!isset($unique[$signature])) {
                $row['_content_signature'] = $signature;
                $row['_seen_count'] = max(0, (int)($history['question_counts'][$signature] ?? 0));
                $recent = !empty($history['recent_questions'][$signature]);
                if ($recent) { $row['_seen_count'] = max(1, $row['_seen_count']); }
                $row['_tier_rank'] = $recent ? 2 : ($row['_seen_count'] > 0 ? 1 : 0);
                $unique[$signature] = $row;
            }
        }
        if (count($unique) < $target) {
            throw new RuntimeException("Bank soal Part {$part} belum memiliki {$target} soal unik yang lengkap dan layak. Silakan hubungi administrator.");
        }
        $candidates = array_values($unique);
        shuffle($candidates);
        usort($candidates, static function (array $left, array $right): int {
            return ($left['_tier_rank'] <=> $right['_tier_rank']) ?: ($left['_seen_count'] <=> $right['_seen_count']);
        });
        $selected = [];
        $deadline = microtime(true) + self::PHOTO_VALIDATION_BUDGET_SECONDS;
        foreach ($candidates as $candidate) {
            if ($part === '1') {
                if (microtime(true) > $deadline) { break; }
                if (!(($this->photoValidator)((string)$candidate['_photo_path']))) { continue; }
            }
            $selected[] = $candidate;
            if (count($selected) === $target) { break; }
        }
        if (count($selected) !== $target) {
            throw new RuntimeException("Bank soal Part {$part} belum memiliki {$target} soal unik dengan media yang layak. Silakan hubungi administrator.");
        }
        return $this->summarize($selected, $target);
    }

    /** Reject invalid keys, missing context and ambiguous copies without modifying the bank. */
    private function eligibleRows(array $rows, string $part): array {
        $grouped = in_array($part, ['3', '4', '6', '7'], true);
        $groupColumn = in_array($part, ['3', '4'], true) ? 'id_audio' : 'id_teks';
        $invalidGroups = [];
        $answers = [];
        $valid = [];
        foreach ($rows as $row) {
            if (isset($row['part']) && (string)$row['part'] !== $part) { continue; }
            $groupId = (int)($row[$groupColumn] ?? 0);
            $eligible = toeicQuestionHasValidAnswer($row, $part);
            if (!in_array($part, ['1', '2'], true) && trim((string)($row['pertanyaan'] ?? '')) === '') { $eligible = false; }
            if (in_array($part, ['1', '2', '3', '4'], true)) {
                $eligible = $eligible && !empty($row['id_audio']) && trim((string)($row['_audio_path'] ?? '')) !== '';
            }
            if ($part === '1') { $eligible = $eligible && trim((string)($row['_photo_path'] ?? '')) !== ''; }
            if (in_array($part, ['6', '7'], true)) {
                $eligible = $eligible && $groupId > 0 && trim((string)($row['_passage_1'] ?? '')) !== '';
                $type = strtolower((string)($row['_text_type'] ?? '') . ' ' . (string)($row['question_type'] ?? ''));
                if (str_contains($type, 'double') || str_contains($type, 'triple')) {
                    $eligible = $eligible && trim((string)($row['_passage_2'] ?? '')) !== '';
                }
                if (str_contains($type, 'triple')) { $eligible = $eligible && trim((string)($row['_passage_3'] ?? '')) !== ''; }
            }
            if (!$eligible) {
                if ($grouped && $groupId > 0) { $invalidGroups[$groupId] = true; }
                continue;
            }
            $signature = toeicQuestionContentSignature($row, $part);
            $answers[$signature][toeicQuestionAnswerText($row, $part)] = true;
            $row['_content_signature'] = $signature;
            $valid[] = $row;
        }
        foreach ($valid as $row) {
            if ($grouped && count($answers[$row['_content_signature']]) > 1) {
                $invalidGroups[(int)$row[$groupColumn]] = true;
            }
        }
        return array_values(array_filter($valid, static function (array $row) use ($answers, $invalidGroups, $grouped, $groupColumn): bool {
            return count($answers[$row['_content_signature']]) === 1
                && (!$grouped || empty($invalidGroups[(int)$row[$groupColumn]]));
        }));
    }

    /** Select whole canonical stimuli using an exact-quota, least-reuse subset plan. */
    private function selectGroups(array $rows, string $part, int $target, array $history): array {
        $column = in_array($part, ['3', '4'], true) ? 'id_audio' : 'id_teks';
        $byId = [];
        foreach ($rows as $row) {
            $id = (int)($row[$column] ?? 0);
            if ($id < 1) { continue; }
            $signature = toeicQuestionContentSignature($row, $part);
            $row['_content_signature'] = $signature;
            $byId[$id][$signature] = $row;
        }
        $canonical = [];
        foreach ($byId as $group) {
            $group = array_values($group);
            usort($group, static function (array $left, array $right): int {
                return ((int)($left['nomor_soal'] ?? 0) <=> (int)($right['nomor_soal'] ?? 0))
                    ?: ((int)$left['id_soal'] <=> (int)$right['id_soal']);
            });
            $stimulus = toeicStimulusContentSignature($group[0], $part);
            if (isset($canonical[$stimulus]) && count($canonical[$stimulus]['rows']) >= count($group)) { continue; }
            $seen = max(0, (int)($history['stimulus_counts'][$stimulus] ?? 0));
            $recent = !empty($history['recent_stimuli'][$stimulus]);
            foreach ($group as $row) {
                $seen = max($seen, (int)($history['question_counts'][$row['_content_signature']] ?? 0));
                $recent = $recent || !empty($history['recent_questions'][$row['_content_signature']]);
            }
            if ($recent) { $seen = max(1, $seen); }
            $rank = $recent ? 2 : ($seen > 0 ? 1 : 0);
            foreach ($group as &$row) {
                $row['_stimulus_signature'] = $stimulus;
                $row['_seen_count'] = $seen;
                $row['_tier_rank'] = $rank;
            }
            unset($row);
            $size = count($group);
            $canonical[$stimulus] = ['rows' => $group,
                'cost' => [$rank === 2 ? $size : 0, $rank > 0 ? $size : 0, $seen]];
        }
        $units = array_values($canonical);
        shuffle($units);
        $reachable = [0 => ['path' => [], 'cost' => [0, 0, 0]]];
        foreach ($units as $index => $unit) {
            $next = $reachable;
            foreach ($reachable as $count => $state) {
                $sum = $count + count($unit['rows']);
                if ($sum > $target) { continue; }
                $cost = [];
                foreach ($state['cost'] as $component => $value) { $cost[] = $value + $unit['cost'][$component]; }
                if (!isset($next[$sum]) || $cost < $next[$sum]['cost']) {
                    $next[$sum] = ['path' => array_merge($state['path'], [$index]), 'cost' => $cost];
                }
            }
            $reachable = $next;
        }
        if (!isset($reachable[$target])) {
            throw new RuntimeException("Bank soal Part {$part} belum memiliki {$target} soal unik dalam kelompok lengkap dan layak. Silakan hubungi administrator.");
        }
        $selected = [];
        foreach ($reachable[$target]['path'] as $index) { $selected = array_merge($selected, $units[$index]['rows']); }
        return $this->summarize($selected, $target);
    }

    /** Count delivered questions and prior-content reuse, not pre-dedup candidates. */
    private function summarize(array $selected, int $target): array {
        $reused = count(array_filter($selected, static fn(array $row): bool => $row['_seen_count'] > 0));
        $worstTier = max(array_column($selected, '_tier_rank'));
        $tiers = ['unseen', 'least_seen', 'least_seen_all'];
        return ['rows' => $selected, 'target' => $target, 'drawn' => count($selected), 'reused' => $reused, 'tier' => $tiers[$worstTier]];
    }
}
