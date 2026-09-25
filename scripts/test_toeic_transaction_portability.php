<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Real MariaDB connection with a strict MySQL-dialect trap; NOT a native MySQL server test. */
require_once __DIR__ . '/test_support/toeic_isolated_fixture.php';
require_once dirname(__DIR__) . '/includes/toeic_question_identity.php';
require_once dirname(__DIR__) . '/includes/toeic_question_selector.php';
require_once dirname(__DIR__) . '/includes/toeic_asset_storage.php';
$transactionHelper = dirname(__DIR__) . '/includes/toeic_transaction.php';
if (is_file($transactionHelper)) { require_once $transactionHelper; }
/** Keep real mysqli behavior, but reject the vendor-only SQL missing from MySQL. */
class ToeicMySqlDialectConnection extends mysqli {
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool {
        if (stripos($query, '@@in_transaction') !== false) {
            throw new mysqli_sql_exception("Unknown system variable 'in_transaction' (MySQL dialect trap)", 1193);
        }
        return parent::query($query, $result_mode);
    }
}
[$setup, $database] = toeicFixtureConnection(); toeicFixtureSchema($setup); toeicFixtureSeed($setup);
$conn = new ToeicMySqlDialectConnection('127.0.0.1','root','',$database,23306);
$source=file_get_contents(dirname(__DIR__).'/includes/toeic_test_builder.php');eval(substr($source,strpos($source,'class ToeicTestBuilder {')));
require_once dirname(__DIR__).'/includes/toeic_session_start.php';
$credit=toeicFixtureInsert($conn,'user_purchases',['user_id'=>42,'exam_type'=>'toeic','status'=>'active','transaction_ref'=>'SYNTH_PORTABLE']);
toeicStartSessionWithCredit($conn,'portable_start',42,['practice_mode'=>1,'target_part'=>'5','target_section'=>'reading'],$credit);
if ((int)$conn->query("SELECT COUNT(*) FROM toeic_test_questions WHERE test_session='portable_start'")->fetch_row()[0] !== 30) { throw new RuntimeException('Wrong portable start quota'); }
echo "PASS real start and builder do not use MariaDB-only transaction variables\n";
if (toeicConnectionInTransaction($conn)) { throw new RuntimeException('Autocommit incorrectly classified as caller transaction'); }
$conn->begin_transaction();
toeicFixtureInsert($conn,'user_purchases',['user_id'=>42,'exam_type'=>'toeic','status'=>'active','transaction_ref'=>'SYNTH_OUTER_MARKER']);
if (!toeicConnectionInTransaction($conn)) { throw new RuntimeException('Explicit caller transaction not detected'); }
if ((int)$setup->query("SELECT COUNT(*) FROM user_purchases WHERE transaction_ref='SYNTH_OUTER_MARKER'")->fetch_row()[0] !== 0) { throw new RuntimeException('Probe leaked uncommitted caller data'); }
$conn->rollback();
if ((int)$conn->query("SELECT COUNT(*) FROM user_purchases WHERE transaction_ref='SYNTH_OUTER_MARKER'")->fetch_row()[0] !== 0) { throw new RuntimeException('Probe committed caller data'); }
echo "PASS transaction probe preserves caller writes and rollback\n";
$conn->autocommit(false);
if (!toeicConnectionInTransaction($conn)) { throw new RuntimeException('Autocommit-off caller mode not detected'); }
$conn->rollback(); $conn->autocommit(true);
if (toeicConnectionInTransaction($conn)) { throw new RuntimeException('Transaction probe leaves an active transaction'); }
echo "PASS autocommit-off and clean autocommit states\n";
