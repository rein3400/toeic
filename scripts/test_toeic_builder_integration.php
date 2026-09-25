<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Real mysqli integration. Every run creates a new synthetic schema on the isolated loopback server. */
require_once __DIR__ . '/test_support/toeic_isolated_fixture.php';
require_once dirname(__DIR__) . '/includes/toeic_question_identity.php';
require_once dirname(__DIR__) . '/includes/toeic_question_selector.php';
require_once dirname(__DIR__) . '/includes/toeic_asset_storage.php';
require_once dirname(__DIR__) . '/includes/toeic_transaction.php';
$root = dirname(__DIR__);
$out = $root . '/.workflow/toeic-bank-fix-20260923-1518';
if (!is_dir($out)) { mkdir($out, 0770, true); }
$baseline = in_array('--baseline', $argv, true);
$builderPath = $baseline ? $out . '/baseline/includes/toeic_test_builder.php' : $root . '/includes/toeic_test_builder.php';
$source = file_get_contents($builderPath);
$start = strpos($source, 'class ToeicTestBuilder {');
if ($start === false) { throw new RuntimeException('Builder source unavailable'); }
eval(substr($source, $start));
[$conn, $database] = toeicFixtureConnection();
toeicFixtureSchema($conn);
toeicFixtureSeed($conn);
$builder = new ToeicTestBuilder($conn);
$results = [];
/** Record actual assertions and errors without turning failures into a successful exit. */
function integrationCheck(string $name, callable $test): void {
    global $results;
    try {
        $observation = $test();
        $results[] = ['name' => $name, 'passed' => true, 'observation' => $observation];
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $error) {
        $results[] = ['name' => $name, 'passed' => false, 'error' => get_class($error) . ': ' . $error->getMessage()];
        echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n";
    }
}
/** Read persisted assignments with the full actual source stimulus, for independent invariants. */
function fixtureAssignments(mysqli $conn, string $session): array {
    $all = [];
    foreach (['listening', 'reading'] as $section) {
        $table = 'toeic_soal_' . $section;
        $extra = $section === 'listening'
            ? "ta.file_path AS _audio_path, ta.transcript AS _audio_transcript, tp.file_path AS _photo_path"
            : "tx.isi_teks AS _passage_1, tx.isi_teks_2 AS _passage_2, tx.isi_teks_3 AS _passage_3, tx.text_type AS _text_type";
        $joins = $section === 'listening'
            ? 'LEFT JOIN toeic_audio ta ON ta.id_audio=src.id_audio LEFT JOIN toeic_photos tp ON tp.id_photo=ta.id_photo'
            : 'LEFT JOIN toeic_teks tx ON tx.id_teks=src.id_teks';
        $stmt = $conn->prepare("SELECT src.*, tq.section, tq.question_order, tq.group_order, tq.stimulus_group_id, {$extra} FROM toeic_test_questions tq JOIN {$table} src ON src.id_soal=tq.question_id {$joins} WHERE tq.test_session=? AND tq.section=? ORDER BY tq.question_order");
        $stmt->bind_param('ss', $session, $section); $stmt->execute();
        $all = array_merge($all, $stmt->get_result()->fetch_all(MYSQLI_ASSOC)); $stmt->close();
    }
    return $all;
}
/** Assert exact per-part quotas and logical-question uniqueness from persisted assignments. */
function fixtureAssertFull(mysqli $conn, string $session): array {
    $rows = fixtureAssignments($conn, $session);
    $parts = []; $signatures = [];
    foreach ($rows as $row) {
        $part = (string)$row['part']; $parts[$part] = ($parts[$part] ?? 0) + 1;
        $signatures[] = toeicQuestionContentSignature($row, $part);
    }
    ksort($parts);
    if ($parts !== [1=>6,2=>25,3=>39,4=>30,5=>30,6=>16,7=>54]) { throw new RuntimeException('Wrong actual quotas ' . json_encode($parts)); }
    if (count(array_unique($signatures)) !== 200) { throw new RuntimeException('Logical duplicate persisted within a session'); }
    return ['parts' => $parts, 'unique' => count(array_unique($signatures))];
}

integrationCheck('first full test persists 200 unique questions with exact quotas', function () use ($conn, $builder): array {
    $builder->createSession('full_one', 42); $builder->buildTest('full_one', 42);
    return fixtureAssertFull($conn, 'full_one');
});
integrationCheck('Part7 articles are complete and contiguous', function () use ($conn): array {
    $groups = [];
    foreach (fixtureAssignments($conn, 'full_one') as $row) {
        if ($row['part'] === '7') { $groups[(int)$row['id_teks']][] = (int)$row['question_order']; }
    }
    foreach ($groups as $id => $orders) {
        $available = (int)$conn->query("SELECT COUNT(*) FROM toeic_soal_reading WHERE part='7' AND id_teks={$id}")->fetch_row()[0];
        if (count($orders) !== $available || max($orders) - min($orders) + 1 !== count($orders)) { throw new RuntimeException('Fragmented article ' . $id . ' at ' . json_encode($orders)); }
    }
    return ['complete_groups' => count($groups)];
});
integrationCheck('recent-history methods execute against real mysqli with correct part scope', function () use ($builder): array {
    $ids = (new ReflectionMethod($builder, 'getMostRecentSeenQuestionIds'))->invoke($builder, 'toeic_soal_reading', '5', 10);
    $groups = (new ReflectionMethod($builder, 'getMostRecentSeenGroupIds'))->invoke($builder, 'toeic_soal_reading', '6', 'id_teks', 10);
    if (count($ids) !== 30 || count($groups) !== 4) { throw new RuntimeException('Incorrect history counts: ' . count($ids) . '/' . count($groups)); }
    return ['part5_history' => count($ids), 'part6_groups' => count($groups)];
});
integrationCheck('second full test avoids all previous logical questions when alternatives exist', function () use ($conn, $builder): array {
    $builder->createSession('full_two', 42); $builder->buildTest('full_two', 42);
    fixtureAssertFull($conn, 'full_two');
    $keys = static fn(array $rows): array => array_map(static fn(array $r): string => toeicQuestionContentSignature($r, (string)$r['part']), $rows);
    $overlap = array_intersect($keys(fixtureAssignments($conn, 'full_one')), $keys(fixtureAssignments($conn, 'full_two')));
    if ($overlap !== []) { throw new RuntimeException('Logical repeats despite available unseen content: ' . count($overlap)); }
    return ['overlap' => 0];
});
integrationCheck('exhausted history falls back honestly without duplicate or missing slots', function () use ($conn, $builder): array {
    $builder->createSession('full_three', 42); $builder->buildTest('full_three', 42);
    $full = fixtureAssertFull($conn, 'full_three');
    $count = (int)$conn->query("SELECT fallback_count FROM toeic_test_sessions WHERE test_session='full_three'")->fetch_row()[0];
    if ($count < 1) { throw new RuntimeException('Reused content was reported as zero fallback'); }
    return $full + ['fallback_count' => $count];
});
integrationCheck('free trial preserves 4+4+7 policy with 15 unique assigned questions', function () use ($conn, $builder): array {
    $options = ['free_trial'=>1,'practice_mode'=>1];
    $builder->createSession('trial', 77, $options); $builder->buildTest('trial', 77, $options);
    $parts = []; foreach (fixtureAssignments($conn, 'trial') as $row) { $parts[$row['part']] = ($parts[$row['part']] ?? 0) + 1; }
    ksort($parts); if ($parts !== [1=>4,2=>4,5=>7]) { throw new RuntimeException('Wrong trial plan ' . json_encode($parts)); }
    return $parts;
});
integrationCheck('Part7 practice keeps the requested part and whole groups', function () use ($conn, $builder): array {
    $options = ['practice_mode'=>1,'target_part'=>'7','target_section'=>'reading'];
    $builder->createSession('practice', 78, $options); $builder->buildTest('practice', 78, $options);
    $rows = fixtureAssignments($conn, 'practice');
    if (count($rows) !== 54 || array_values(array_unique(array_column($rows, 'part'))) !== ['7']) { throw new RuntimeException('Wrong practice assignment scope'); }
    return ['part7' => count($rows)];
});
integrationCheck('unusable bank fails before replacing an existing session', function () use ($conn, $builder): array {
    $before = $conn->query("SELECT id FROM toeic_test_questions WHERE test_session='full_one' ORDER BY id")->fetch_all(MYSQLI_NUM);
    $conn->begin_transaction();
    try {
        $conn->query("UPDATE toeic_soal_reading SET jawaban_benar='INVALID' WHERE part='5'");
        $rejected = false;
        try { $builder->buildTest('full_one', 42, ['target_part'=>'5','target_section'=>'reading']); }
        catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'soal unik'); }
        $after = $conn->query("SELECT id FROM toeic_test_questions WHERE test_session='full_one' ORDER BY id")->fetch_all(MYSQLI_NUM);
        if (!$rejected || $after !== $before) { throw new RuntimeException('Failed build changed assignments or accepted invalid keys'); }
        return ['old_assignments_preserved' => count($after)];
    } finally { $conn->rollback(); }
});
integrationCheck('builder never commits an outer credit transaction', function () use ($conn, $builder): array {
    $conn->begin_transaction();
    try {
        toeicFixtureInsert($conn, 'user_purchases', ['user_id'=>99,'exam_type'=>'toeic','transaction_ref'=>'SYNTH_ROLLBACK','status'=>'active']);
        $options = ['practice_mode'=>1,'target_part'=>'5','target_section'=>'reading'];
        $builder->createSession('outer_transaction', 99, $options); $builder->buildTest('outer_transaction', 99, $options);
        if ((int)$conn->query('SELECT @@in_transaction')->fetch_row()[0] !== 1) { throw new RuntimeException('Builder committed caller transaction'); }
    } finally { $conn->rollback(); }
    $remaining = (int)$conn->query("SELECT COUNT(*) FROM toeic_test_sessions WHERE test_session='outer_transaction'")->fetch_row()[0];
    $credit = (int)$conn->query("SELECT COUNT(*) FROM user_purchases WHERE transaction_ref='SYNTH_ROLLBACK'")->fetch_row()[0];
    if ($remaining !== 0 || $credit !== 0) { throw new RuntimeException('Outer rollback did not restore session and credit together'); }
    return ['sessions_after_rollback'=>$remaining,'credits_after_rollback'=>$credit];
});
$failed = count(array_filter($results, static fn(array $result): bool => !$result['passed']));
$report = ['mode'=>'REAL mysqli, isolated synthetic database; not production', 'database'=>$database,
    'source_path'=>$builderPath,'source_sha256'=>hash_file('sha256',$builderPath), 'results'=>$results,
    'passed'=>count($results)-$failed,'failed'=>$failed];
file_put_contents($out . ($baseline ? '/integration-baseline.json' : '/integration-candidate.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['database'=>$database,'passed'=>$report['passed'],'failed'=>$failed]) . "\n";
exit($failed > 0 ? 1 : 0);
