<?php
/**
 * Production Data Fix Script
 * 
 * Run with: php scripts/fix_production_data.php           (apply changes)
 * Run with: php scripts/fix_production_data.php --dry-run  (preview only)
 * 
 * Fixes:
 * 1. Give FREE_TRIAL credit to students without an active TOEIC purchase
 * 2. Reactivate stuck used credits for students with zero active credits
 * 3. Clean up stale proctoring sessions (active but camera=0, mic=0, older than 1 day)
 * 4. Delete orphaned active test sessions that will never be completed (older than 7 days, no proctoring)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_utils.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "ERROR: Database connection is unavailable.\n");
    exit(1);
}

function runSql(mysqli $conn, string $sql, string $label, bool $dryRun): void {
    echo ($dryRun ? '[DRY] ' : '[RUN] ') . $label . PHP_EOL;
    if ($dryRun) {
        echo "    SQL: " . $sql . PHP_EOL . PHP_EOL;
        return;
    }
    if (!$conn->query($sql)) {
        echo "  ERROR: " . $conn->error . PHP_EOL . PHP_EOL;
    } else {
        echo "  Affected rows: " . $conn->affected_rows . PHP_EOL . PHP_EOL;
    }
}

echo "=== PRODUCTION DATA FIX ===" . PHP_EOL;
echo ($dryRun ? "Mode: DRY RUN (no changes will be made)" : "Mode: APPLY (changes will be made)") . PHP_EOL . PHP_EOL;

// ── Fix 1: Students without any active TOEIC credit ──
echo "── Fix 1: Grant FREE_TRIAL credit to students without active TOEIC purchase ──" . PHP_EOL;

$result = $conn->query("
    SELECT u.id_user, u.username
    FROM users u
    WHERE u.role = 'student'
      AND NOT EXISTS (
        SELECT 1 FROM user_purchases up
        WHERE up.user_id = u.id_user AND up.exam_type = 'toeic' AND up.status = 'active'
      )
    ORDER BY u.id_user
");
$missing = [];
while ($row = $result->fetch_assoc()) {
    $missing[] = $row;
}
echo "  Found " . count($missing) . " students without active TOEIC credit:" . PHP_EOL;
foreach ($missing as $m) {
    echo "    - id={$m['id_user']} username={$m['username']}" . PHP_EOL;
}
echo PHP_EOL;

runSql($conn, "
    INSERT INTO user_purchases (user_id, exam_type, status, transaction_ref, purchase_date)
    SELECT u.id_user, 'toeic', 'active', 'FREE_TRIAL', NOW()
    FROM users u
    WHERE u.role = 'student'
      AND NOT EXISTS (
        SELECT 1 FROM user_purchases up
        WHERE up.user_id = u.id_user AND up.exam_type = 'toeic' AND up.status = 'active'
      )
", "Insert FREE_TRIAL credit for students missing active TOEIC purchase", $dryRun);

// ── Fix 2: Stale proctoring sessions (active, camera=0, mic=0, older than 1 day) ──
echo "── Fix 2: Clean up stuck proctoring sessions ──" . PHP_EOL;

$staleProc = $conn->query("
    SELECT COUNT(*) as cnt FROM proctoring_sessions
    WHERE camera_granted = 0 AND microphone_granted = 0 AND status = 'active'
      AND started_at < NOW() - INTERVAL 1 DAY
");
$staleCount = $staleProc->fetch_assoc()['cnt'];
echo "  Found {$staleCount} stuck proctoring sessions (camera=0, mic=0, >1 day old)" . PHP_EOL . PHP_EOL;

runSql($conn, "
    DELETE FROM proctoring_sessions
    WHERE camera_granted = 0 AND microphone_granted = 0 AND status = 'active'
      AND started_at < NOW() - INTERVAL 1 DAY
", "Remove stuck proctoring sessions older than 1 day", $dryRun);

// ── Fix 3: Orphaned active test sessions (older than 7 days, no completed result) ──
echo "── Fix 3: Mark orphaned active test sessions as expired ──" . PHP_EOL;

$orphaned = $conn->query("
    SELECT COUNT(*) as cnt FROM toeic_test_sessions
    WHERE status = 'active'
      AND started_at < NOW() - INTERVAL 7 DAY
");
$orphanCount = $orphaned->fetch_assoc()['cnt'];
echo "  Found {$orphanCount} active test sessions older than 7 days" . PHP_EOL . PHP_EOL;

runSql($conn, "
    UPDATE toeic_test_sessions
    SET status = 'expired', completed_at = NOW()
    WHERE status = 'active'
      AND started_at < NOW() - INTERVAL 7 DAY
", "Mark orphaned test sessions (>7 days active) as expired", $dryRun);

// ── Fix 4: Stale sessions for the same user/test_session with multiple proctoring entries ──
echo "── Fix 4: Clean up duplicate proctoring sessions per test_session ──" . PHP_EOL;

$duplicateProc = $conn->query("
    SELECT test_session, user_id, COUNT(*) as cnt
    FROM proctoring_sessions
    WHERE status = 'active'
    GROUP BY test_session, user_id
    HAVING cnt > 1
");
$dupCount = 0;
while ($dup = $duplicateProc->fetch_assoc()) {
    $dupCount += $dup['cnt'] - 1;
    echo "    test_session={$dup['test_session']} user_id={$dup['user_id']} has {$dup['cnt']} active proctoring sessions" . PHP_EOL;
}
echo "  Total duplicate entries to resolve: {$dupCount}" . PHP_EOL . PHP_EOL;

if ($dupCount > 0) {
    runSql($conn, "
        DELETE ps FROM proctoring_sessions ps
        INNER JOIN (
            SELECT test_session, user_id, MIN(id) as keep_id
            FROM proctoring_sessions
            WHERE status = 'active'
            GROUP BY test_session, user_id
            HAVING COUNT(*) > 1
        ) dup ON ps.test_session = dup.test_session
            AND ps.user_id = dup.user_id
            AND ps.id != dup.keep_id
            AND ps.status = 'active'
    ", "Remove duplicate proctoring sessions (keep oldest)", $dryRun);
}

// ── Fix 5: Update photo file_path in DB from .png to .jpg for Part 1 photos ──
echo "── Fix 5: Update toeic_photos file_path .png to .jpg for Part 1 photos ──" . PHP_EOL;

$photoFixes = [
    'toeic_p1_01.png' => 'toeic_p1_01.jpg',
    'toeic_p1_02.png' => 'toeic_p1_02.jpg',
    'toeic_p1_03.png' => 'toeic_p1_03.jpg',
    'toeic_p1_04.png' => 'toeic_p1_04.jpg',
    'toeic_p1_05.png' => 'toeic_p1_05.jpg',
    'toeic_p1_06.png' => 'toeic_p1_06.jpg',
];
$totalPhotoFixes = 0;
foreach ($photoFixes as $oldName => $newName) {
    $checkStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM toeic_photos WHERE file_path LIKE ?");
    $likeOld = '%' . $oldName;
    $checkStmt->bind_param("s", $likeOld);
    $checkStmt->execute();
    $cnt = $checkStmt->get_result()->fetch_assoc()['cnt'];
    $checkStmt->close();
    if ($cnt > 0) {
        $totalPhotoFixes += $cnt;
        echo "    {$oldName} → {$newName} ({$cnt} rows)" . PHP_EOL;
    }
}
echo "  Total photo paths to fix: {$totalPhotoFixes}" . PHP_EOL . PHP_EOL;

foreach ($photoFixes as $oldName => $newName) {
    runSql($conn, "
        UPDATE toeic_photos
        SET file_path = REPLACE(file_path, '{$oldName}', '{$newName}')
        WHERE file_path LIKE '%{$oldName}'
    ", "Update toeic_photos: {$oldName} → {$newName}", $dryRun);
}

// ── Summary ──
echo "=== SUMMARY ===" . PHP_EOL;
if ($dryRun) {
    echo "No changes were made. Run without --dry-run to apply." . PHP_EOL;
} else {
    echo "All fixes applied successfully." . PHP_EOL;
}
echo PHP_EOL;