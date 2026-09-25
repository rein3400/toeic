<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Fresh isolated schema: missing columns must never make createSession implicitly commit a caller transaction. */
require_once __DIR__.'/test_support/toeic_isolated_fixture.php';
require_once dirname(__DIR__).'/includes/toeic_question_identity.php';
require_once dirname(__DIR__).'/includes/toeic_question_selector.php';
require_once dirname(__DIR__).'/includes/toeic_asset_storage.php';
$helper=dirname(__DIR__).'/includes/toeic_transaction.php'; if(is_file($helper)){require_once $helper;}
[$conn,$database]=toeicFixtureConnection();toeicFixtureSchema($conn);
// Only this newly-created synthetic schema is altered.
$conn->query('ALTER TABLE toeic_test_sessions DROP COLUMN practice_mode');
$source=file_get_contents(dirname(__DIR__).'/includes/toeic_test_builder.php');eval(substr($source,strpos($source,'class ToeicTestBuilder {')));
$builder=new ToeicTestBuilder($conn);
$conn->begin_transaction();
toeicFixtureInsert($conn,'user_purchases',['user_id'=>42,'exam_type'=>'toeic','status'=>'active','transaction_ref'=>'SYNTH_NO_DDL_COMMIT']);
$error=null;
try { $builder->createSession('caller_schema_missing',42); }
catch (Throwable $caught) { $error=$caught; }
$active=(int)$conn->query('SELECT @@in_transaction')->fetch_row()[0];
$conn->rollback();
$markers=(int)$conn->query("SELECT COUNT(*) FROM user_purchases WHERE transaction_ref='SYNTH_NO_DDL_COMMIT'")->fetch_row()[0];
$sessions=(int)$conn->query("SELECT COUNT(*) FROM toeic_test_sessions WHERE test_session='caller_schema_missing'")->fetch_row()[0];
if($active!==1 || $markers!==0 || $sessions!==0 || !$error){throw new RuntimeException('FAIL createSession implicitly committed caller work instead of rejecting missing schema: '.json_encode(compact('active','markers','sessions')));}
echo "PASS missing schema cannot implicitly commit caller credit or session\n";
$builder->prepareSessionSchema();
$conn->begin_transaction();
$builder->createSession('caller_schema_ready',42);
$conn->rollback();
if((int)$conn->query("SELECT COUNT(*) FROM toeic_test_sessions WHERE test_session='caller_schema_ready'")->fetch_row()[0]!==0){throw new RuntimeException('Prepared schema path committed outer transaction');}
echo "PASS explicit preflight before BEGIN preserves direct-caller rollback\n";
