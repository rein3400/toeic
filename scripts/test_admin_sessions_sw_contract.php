<?php
/**
 * Static contract checks for the combined TOEIC LR/SW admin sessions page.
 */

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/admin/test_sessions.php');
$failures = [];

function admin_sessions_sw_check(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

admin_sessions_sw_check(strpos($page, 'toeic_sw_helper.php') !== false, 'admin/test_sessions.php must load the SW schema helper.');
admin_sessions_sw_check(strpos($page, 'toeic_sw_test_sessions') !== false, 'admin/test_sessions.php must query TOEIC SW sessions.');
admin_sessions_sw_check(strpos($page, 'toeic_sw_test_results') !== false, 'admin/test_sessions.php must join TOEIC SW scores/results.');
admin_sessions_sw_check(strpos($page, 'speaking_scaled') !== false, 'admin/test_sessions.php must display Speaking scaled score data.');
admin_sessions_sw_check(strpos($page, 'writing_scaled') !== false, 'admin/test_sessions.php must display Writing scaled score data.');
admin_sessions_sw_check(strpos($page, 'toeic_sw_result_detail.php') !== false, 'admin/test_sessions.php must route SW rows to the SW detail page.');
admin_sessions_sw_check(strpos($page, 'TOEIC SW') !== false, 'admin/test_sessions.php must visibly distinguish TOEIC SW rows.');

if (!empty($failures)) {
    fwrite(STDERR, "Admin sessions SW contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin sessions SW contract passed.\n";
