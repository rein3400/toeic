<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Exercise the shipped additive migration and snapshot dependencies on a fresh guarded database. */
require_once __DIR__.'/test_support/toeic_isolated_fixture.php';
[$setup,$database]=toeicFixtureConnection();toeicFixtureSchema($setup);toeicFixtureSeed($setup);
foreach (['MYSQLHOST'=>'127.0.0.1','MYSQLPORT'=>'23306','MYSQLUSER'=>'root','MYSQLPASSWORD'=>'','MYSQLDATABASE'=>$database] as $key=>$value) { putenv($key.'='.$value); }
require_once dirname(__DIR__).'/includes/toeic_helper.php';
require_once dirname(__DIR__).'/includes/toeic_test_builder.php';
require_once dirname(__DIR__).'/includes/toeic_session_start.php';
if ($conn->query('SELECT DATABASE()')->fetch_row()[0]!==$database) { throw new RuntimeException('Unsafe bootstrap target'); }
$legacy=toeicFixtureInsert($conn,'toeic_test_sessions',['test_session'=>'legacy_migration_control','user_id'=>9,'current_section'=>'reading','status'=>'completed']);
$legacyQuestion=toeicFixtureInsert($conn,'toeic_test_questions',['test_session'=>'legacy_migration_control','user_id'=>9,'question_id'=>1,'section'=>'reading','part'=>'5','question_order'=>1,'user_answer'=>'A']);
foreach (['snap_jawaban_benar','snap_pertanyaan','snap_opsi_a','snap_opsi_b','snap_opsi_c','snap_opsi_d','original_question_id'] as $column) { $conn->query('ALTER TABLE toeic_test_questions DROP COLUMN '.$column); }
foreach (['fallback_count','fallback_breakdown','short_filled_count'] as $column) { $conn->query('ALTER TABLE toeic_test_sessions DROP COLUMN '.$column); }
$conn->query('DROP TABLE toeic_pool_exhausted_events');
$before=$conn->query('SELECT * FROM toeic_test_questions WHERE id='.$legacyQuestion)->fetch_assoc();
$bankBefore=(int)$conn->query('SELECT COUNT(*) FROM toeic_soal_reading')->fetch_row()[0];
$credit=toeicFixtureInsert($conn,'user_purchases',['user_id'=>101,'exam_type'=>'toeic','status'=>'active','transaction_ref'=>'SYNTH_SCHEMA']);
$options=['practice_mode'=>1,'target_part'=>'5','target_section'=>'reading'];
$missingRejected=false;
try { toeicStartSessionWithCredit($conn,'before_schema',101,$options,$credit); }
catch (mysqli_sql_exception $error) { $missingRejected=in_array($error->getCode(),[1054,1146],true); }
if (!$missingRejected || $conn->query('SELECT status FROM user_purchases WHERE id='.$credit)->fetch_row()[0]!=='active') { throw new RuntimeException('Unmigrated schema control did not fail safely'); }
echo "PASS unmigrated-schema negative control fails without spending credit\n";
$sql=file_get_contents(dirname(__DIR__).'/migrations/003_toeic_bank_delivery.sql');
$statements=array_filter(array_map('trim',explode(';',preg_replace('/^--.*$/m','',$sql))));
for ($pass=0;$pass<2;$pass++) {
    foreach ($statements as $statement) { $result=$conn->query($statement); if($result instanceof mysqli_result){$result->free();} }
}
$after=$conn->query('SELECT * FROM toeic_test_questions WHERE id='.$legacyQuestion)->fetch_assoc();
if (array_intersect_key($after,$before)!==$before || $after['snap_jawaban_benar']!==null || $after['original_question_id']!==null || (int)$conn->query('SELECT COUNT(*) FROM toeic_soal_reading')->fetch_row()[0]!==$bankBefore) { throw new RuntimeException('Migration rewrote bank/history data'); }
echo "PASS migration runs twice without changing legacy answers or bank rows\n";
toeicStartSessionWithCredit($conn,'after_schema',101,$options,$credit);
if ((int)$conn->query("SELECT COUNT(*) FROM toeic_test_questions WHERE test_session='after_schema'")->fetch_row()[0]!==30) { throw new RuntimeException('Migrated schema cannot persist complete practice'); }
echo "PASS published migration supplies every required builder column/table\n";
$conn->query("UPDATE toeic_test_questions SET user_answer='A' WHERE test_session='after_schema'");
$id=(int)$conn->query("SELECT question_id FROM toeic_test_questions WHERE test_session='after_schema' ORDER BY question_order LIMIT 1")->fetch_row()[0];
$conn->query("UPDATE toeic_soal_reading SET jawaban_benar='B' WHERE id_soal=".$id);
$score=(new ToeicScorer($conn))->scoreSection('after_schema','reading');
if ($score['raw']!==30) { throw new RuntimeException('Scoring ignored the selected answer snapshot'); }
echo "PASS shipped scorer honors snapshots after a synthetic source edit\n";
// Real source tables need only their own stimulus FK, not the opposite section's FK.
$conn->query('ALTER TABLE toeic_soal_reading DROP COLUMN id_audio');
$conn->query('ALTER TABLE toeic_soal_listening DROP COLUMN id_teks');
$row=getTOEICRandomizedQuestion('after_schema','reading',1);
if (!$row || trim($row['jawaban_benar'])!=='A') { throw new RuntimeException('Snapshot reader failed on section-specific source schema'); }
echo "PASS shared snapshot reader supports section-specific stimulus columns\n";
toeicFixtureInsert($conn,'toeic_test_questions',['test_session'=>'legacy_migration_control','user_id'=>9,'question_id'=>1,'section'=>'listening','part'=>'1','question_order'=>1]);
$legacyRow=getTOEICRandomizedQuestion('legacy_migration_control','listening',1);
if (!$legacyRow || $legacyRow['id_teks']!==null || (int)$legacyRow['id_audio']!==1 || trim($legacyRow['jawaban_benar'])!=='A') { throw new RuntimeException('Legacy listening fallback failed'); }
echo "PASS legacy listening rows still fall back to the live source safely\n";
