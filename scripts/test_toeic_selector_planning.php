<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Behavioral tests for the production unique-question planner, without a database. */
$root = dirname(__DIR__);
$plannerPath = $root . '/includes/toeic_question_selector.php';
if (!is_file($plannerPath)) { fwrite(STDERR, "FAIL unique-question planner is missing; LIMIT-before-dedup cannot refill quotas\n"); exit(1); }
require_once $plannerPath;
/** Create explicit, synthetic Part5 rows. */
function selectorRow(int $id, string $stem): array {
    return ['id_soal' => $id, 'part' => '5', 'pertanyaan' => $stem, 'nomor_soal' => $id,
        'opsi_a' => 'correct', 'opsi_b' => 'wrong one', 'opsi_c' => 'wrong two', 'opsi_d' => 'wrong three',
        'jawaban_benar' => 'A', 'id_teks' => null, 'id_audio' => null];
}
/** Fail closed on a wrong production result. */
function selectorAssert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('FAIL ' . $message); }
    echo 'PASS ' . $message . "\n";
}
$planner = new ToeicQuestionSelector();
$rows = [selectorRow(1, 'First item'), selectorRow(2, 'First item'), selectorRow(3, 'Second item'), selectorRow(4, 'Third item')];
$plan = $planner->select($rows, '5', 3);
$stems = array_column($plan['rows'], 'pertanyaan'); sort($stems);
selectorAssert($stems === ['First item', 'Second item', 'Third item'], 'duplicates are removed before limit and quota is refilled');
selectorAssert($plan['drawn'] === 3 && $plan['reused'] === 0, 'actual assignments and reuse counts match the plan');
$caught = false;
try { $planner->select($rows, '5', 4); } catch (RuntimeException $error) { $caught = str_contains($error->getMessage(), 'soal unik'); }
selectorAssert($caught, 'insufficient unique pool fails explicitly rather than claiming a complete session');
$seenKey = toeicQuestionContentSignature($rows[0], '5');
$history = ['question_counts' => [$seenKey => 4], 'recent_questions' => [$seenKey => true]];
$reusedPlan = $planner->select($rows, '5', 3, $history);
selectorAssert($reusedPlan['reused'] === 1 && $reusedPlan['tier'] === 'least_seen_all', 'exhausted fresh pool reports actual content reuse instead of zero missing rows');
$unseenPlan = $planner->select($rows, '5', 2, $history);
$unseenStems = array_column($unseenPlan['rows'], 'pertanyaan'); sort($unseenStems);
selectorAssert($unseenStems === ['Second item', 'Third item'], 'recent clone content with a new ID is avoided when enough unseen alternatives exist');
$oldHistory = ['question_counts' => [$seenKey => 4], 'recent_questions' => []];
$oldPlan = $planner->select($rows, '5', 3, $oldHistory);
selectorAssert($oldPlan['reused'] === 1 && $oldPlan['tier'] === 'least_seen', 'older content reuse is distinguished from last-window reuse');
/** Make a complete synthetic article group with explicit ordered questions. */
function selectorGroup(int $textId, int $size, string $article, int $idStart): array {
    $group = [];
    for ($i = 1; $i <= $size; $i++) {
        $row = selectorRow($idStart + $i, 'Article question ' . $i);
        $row['part'] = '7'; $row['id_teks'] = $textId; $row['_passage_1'] = $article;
        $group[] = $row;
    }
    return $group;
}
$groupPool = array_merge(selectorGroup(10, 3, 'Article A', 10), selectorGroup(20, 2, 'Article B', 20));
$caught = false;
try { $planner->select($groupPool, '7', 4); } catch (RuntimeException $error) { $caught = str_contains($error->getMessage(), 'soal unik'); }
selectorAssert($caught, 'cannot fill a quota by silently splitting a three-question article');
$exactPool = array_merge(selectorGroup(10, 4, 'Article A', 10), selectorGroup(20, 3, 'Article B', 20), selectorGroup(30, 3, 'Article C', 30));
$exact = $planner->select($exactPool, '7', 6);
$counts = array_count_values(array_column($exact['rows'], 'id_teks')); ksort($counts);
selectorAssert($counts === [20 => 3, 30 => 3], 'whole-group selection finds exact three-plus-three quota instead of greedy four-plus-fragment');
$sequence = array_column($exact['rows'], 'id_teks');
selectorAssert($sequence === [20,20,20,30,30,30] || $sequence === [30,30,30,20,20,20], 'every selected article is contiguous');
$a = selectorGroup(10, 2, 'Repeated article', 100);
$b = selectorGroup(90, 2, 'Repeated article', 900);
$interleaved = [$a[0], $b[1], $a[1], $b[0]];
$canonical = $planner->select($interleaved, '7', 2);
selectorAssert(count(array_unique(array_column($canonical['rows'], 'id_teks'))) === 1, 'same-article clones are canonicalized as complete groups rather than mixed question rows');
$contextKey = toeicStimulusContentSignature($groupPool[0], '7');
$contextHistory = ['stimulus_counts' => [$contextKey => 2], 'recent_stimuli' => [$contextKey => true]];
$articleRepeat = $planner->select($groupPool, '7', 5, $contextHistory);
selectorAssert($articleRepeat['reused'] === 3 && $articleRepeat['tier'] === 'least_seen_all', 'repeated article context counts as reuse even for different question IDs');
$badKey = selectorRow(5, 'Malformed answer'); $badKey['jawaban_benar'] = 'MAY 16, 2026';
$caught = false;
try { $planner->select(array_merge($rows, [$badKey]), '5', 4); } catch (RuntimeException $error) { $caught = str_contains($error->getMessage(), 'soal unik'); }
selectorAssert($caught, 'invalid stored answer text cannot count toward an eligible quota');
$conflict = selectorRow(90, 'First item'); $conflict['jawaban_benar'] = 'B';
$caught = false;
try { $planner->select(array_merge($rows, [$conflict]), '5', 3); } catch (RuntimeException $error) { $caught = str_contains($error->getMessage(), 'soal unik'); }
selectorAssert($caught, 'conflicting logical answer texts quarantine the ambiguous question instead of selecting an arbitrary copy');
$invalidGroup = $groupPool; $invalidGroup[0]['jawaban_benar'] = 'INVALID';
$caught = false;
try { $planner->select($invalidGroup, '7', 3); } catch (RuntimeException $error) { $caught = str_contains($error->getMessage(), 'soal unik'); }
selectorAssert($caught, 'invalid member rejects the whole article instead of splitting its question set');
$photos = [];
foreach ([1 => 'good-one.jpg', 2 => 'broken.jpg', 3 => 'good-two.jpg'] as $id => $path) {
    $row = selectorRow($id, 'Choose a statement'); $row['part'] = '1'; $row['id_audio'] = $id;
    $row['_audio_path'] = 'audio-' . $id . '.mp3'; $row['_photo_path'] = $path; $photos[] = $row;
}
$mediaPlanner = new ToeicQuestionSelector(static fn(string $path): bool => $path !== 'broken.jpg');
$caught = false;
try { $mediaPlanner->select($photos, '1', 3); } catch (RuntimeException $error) { $caught = str_contains($error->getMessage(), 'soal unik'); }
selectorAssert($caught, 'nonempty photo path is insufficient when the photo validator rejects it');
$goodPhotos = $mediaPlanner->select($photos, '1', 2);
$photoIds = array_column($goodPhotos['rows'], 'id_soal'); sort($photoIds);
selectorAssert($photoIds === [1,3], 'broken photo is replaced by a healthy candidate before assignment');
