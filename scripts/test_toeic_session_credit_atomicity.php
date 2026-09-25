<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Real isolated MySQL tests of credit/session atomicity; never targets production. */
require_once __DIR__ . '/test_support/toeic_isolated_fixture.php';
require_once dirname(__DIR__) . '/includes/toeic_question_identity.php';
require_once dirname(__DIR__) . '/includes/toeic_question_selector.php';
require_once dirname(__DIR__) . '/includes/toeic_asset_storage.php';
require_once dirname(__DIR__) . '/includes/toeic_transaction.php';
$root = dirname(__DIR__);
$path = $root . '/includes/toeic_session_start.php';
if (!is_file($path)) { fwrite(STDERR, "FAIL atomic TOEIC session start helper missing; credit consumption is outside build rollback\n"); exit(1); }
$source = file_get_contents($root . '/includes/toeic_test_builder.php');
eval(substr($source, strpos($source, 'class ToeicTestBuilder {')));
require_once $path;
[$conn, $database] = toeicFixtureConnection(); toeicFixtureSchema($conn); toeicFixtureSeed($conn);
$credit = toeicFixtureInsert($conn, 'user_purchases', ['user_id'=>300,'exam_type'=>'toeic','transaction_ref'=>'SYNTH_START','status'=>'active']);
$options = ['practice_mode'=>1,'target_part'=>'5','target_section'=>'reading','current_section'=>'reading'];
$observations = [];
/** Assert observable DB state after calling the real start coordinator. */
function creditCheck(bool $value, string $label): void {
    global $observations;
    if (!$value) { throw new RuntimeException('FAIL ' . $label); }
    $observations[] = $label; echo 'PASS ' . $label . "\n";
}
$conn->query("UPDATE toeic_soal_reading SET jawaban_benar='INVALID' WHERE part='5'");
$failed = false;
try { toeicStartSessionWithCredit($conn, 'atomic_retry', 300, $options, $credit); }
catch (RuntimeException $error) { $failed = str_contains($error->getMessage(), 'soal unik'); }
creditCheck($failed, 'invalid bank rejects new session with specific failure');
creditCheck($conn->query("SELECT status FROM user_purchases WHERE id={$credit}")->fetch_row()[0] === 'active', 'failed build refunds the reserved credit by rollback');
creditCheck((int)$conn->query("SELECT COUNT(*) FROM toeic_test_sessions WHERE test_session='atomic_retry'")->fetch_row()[0] === 0, 'failed build leaves no active ghost session');
creditCheck((int)$conn->query("SELECT COUNT(*) FROM toeic_test_questions WHERE test_session='atomic_retry'")->fetch_row()[0] === 0, 'failed build leaves no partial assignments');
$conn->query("UPDATE toeic_soal_reading SET jawaban_benar='A' WHERE part='5'");
toeicStartSessionWithCredit($conn, 'atomic_retry', 300, $options, $credit);
creditCheck($conn->query("SELECT status FROM user_purchases WHERE id={$credit}")->fetch_row()[0] === 'used', 'successful retry consumes exactly the intended credit');
creditCheck((int)$conn->query("SELECT COUNT(*) FROM toeic_test_questions WHERE test_session='atomic_retry'")->fetch_row()[0] === 30, 'successful retry persists a complete practice part');
$next = toeicFixtureInsert($conn, 'user_purchases', ['user_id'=>300,'exam_type'=>'toeic','transaction_ref'=>'SYNTH_SECOND','status'=>'active']);
$duplicateRejected = false;
try { toeicStartSessionWithCredit($conn, 'atomic_retry', 300, $options, $next); } catch (RuntimeException $error) { $duplicateRejected = true; }
creditCheck($duplicateRejected && $conn->query("SELECT status FROM user_purchases WHERE id={$next}")->fetch_row()[0] === 'active', 'duplicate session identity never spends a second credit');
$staleRejected = false;
try { toeicStartSessionWithCredit($conn, 'stale_preview', 300, $options, $credit); } catch (RuntimeException $error) { $staleRejected = true; }
creditCheck($staleRejected && $conn->query("SELECT status FROM user_purchases WHERE id={$next}")->fetch_row()[0] === 'active', 'stale credit preview cannot consume a different package');
creditCheck((int)$conn->query('SELECT @@in_transaction')->fetch_row()[0] === 0, 'coordinator closes its transaction on success and failure');
file_put_contents($root . '/.workflow/toeic-bank-fix-20260923-1518/credit-integration.json', json_encode(['mode'=>'REAL isolated mysqli synthetic fixtures','database'=>$database,'passed'=>count($observations),'checks'=>$observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
