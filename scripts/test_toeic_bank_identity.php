<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Offline tests of the production builder's actual content-identity method. */
$root = dirname(__DIR__);
$identityFile = $root . '/includes/toeic_question_identity.php';
if (is_file($identityFile)) { require_once $identityFile; }
$source = file_get_contents($root . '/includes/toeic_test_builder.php');
$start = strpos($source, 'class ToeicTestBuilder {');
if ($start === false) { throw new RuntimeException('Builder class unavailable'); }
eval(substr($source, $start));
$builder = (new ReflectionClass(ToeicTestBuilder::class))->newInstanceWithoutConstructor();
$signature = new ReflectionMethod($builder, 'rowSignature');
$failures = [];
$checks = 0;
/** Assert a specific expected production behavior. */
function identityCheck(bool $ok, string $name): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures[] = $name; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
}
$base = ['id_soal' => 1, 'id_teks' => 10, 'pertanyaan' => 'Choose blank 1.',
    'opsi_a' => 'approve', 'opsi_b' => 'reject', 'opsi_c' => 'wait', 'opsi_d' => 'review',
    'jawaban_benar' => 'A', '_passage_1' => 'SYNTH: Finance will ___1___ the budget.',
    '_passage_2' => '', '_passage_3' => ''];
$clone = $base; $clone['id_soal'] = 200; $clone['id_teks'] = 900;
identityCheck($signature->invoke($builder, $base, '6') === $signature->invoke($builder, $clone, '6'), 'same passage and question with different DB IDs is one logical question');
$different = $base; $different['_passage_1'] = 'SYNTH: Legal will ___1___ the merger.';
identityCheck($signature->invoke($builder, $base, '6') !== $signature->invoke($builder, $different, '6'), 'different full passage stays distinct even with reused IDs and generic stem');
$multi = $base; $multi['_passage_2'] = 'SYNTH: Only the revised notice permits approval.';
identityCheck($signature->invoke($builder, $base, '7') !== $signature->invoke($builder, $multi, '7'), 'second passage participates in question identity');
$rotated = $clone; $rotated['opsi_a'] = 'reject'; $rotated['opsi_b'] = 'approve'; $rotated['jawaban_benar'] = 'B';
identityCheck($signature->invoke($builder, $base, '6') === $signature->invoke($builder, $rotated, '6'), 'option rotation and new text ID do not create another question');
$audio = ['id_audio' => 1, 'pertanyaan' => 'Listen and choose a response.',
    'opsi_a' => 'Only after approval.', 'opsi_b' => 'The room is upstairs.', 'opsi_c' => 'Lunch was cancelled.', 'opsi_d' => '',
    'jawaban_benar' => 'A', '_audio_transcript' => 'Can we release the report? Only after approval. The room is upstairs. Lunch was cancelled.'];
$audioClone = $audio; $audioClone['id_audio'] = 100;
$audioClone['opsi_a'] = 'The room is upstairs.'; $audioClone['opsi_b'] = 'Only after approval.'; $audioClone['jawaban_benar'] = 'B';
$audioClone['_audio_transcript'] = 'Can we release the report? The room is upstairs. Only after approval. Lunch was cancelled.';
identityCheck($signature->invoke($builder, $audio, '2') === $signature->invoke($builder, $audioClone, '2'), 'same spoken prompt with rotated responses and different audio IDs is one question');
$audioOther = $audio; $audioOther['_audio_transcript'] = 'Can we cancel the meeting? Only after approval. The room is upstairs. Lunch was cancelled.';
identityCheck($signature->invoke($builder, $audio, '2') !== $signature->invoke($builder, $audioOther, '2'), 'generic screen instruction does not collapse different spoken prompts');
$photoA = $audio; $photoA['_photo_path'] = 'one.jpg';
$photoB = $photoA; $photoB['_photo_path'] = 'two.jpg';
identityCheck($signature->invoke($builder, $photoA, '1') !== $signature->invoke($builder, $photoB, '1'), 'different photos remain distinct despite identical statements');
if ($failures) { fwrite(STDERR, count($failures) . "/{$checks} identity checks failed\n"); exit(1); }
echo "{$checks}/{$checks} identity checks passed\n";
