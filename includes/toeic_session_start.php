<?php
declare(strict_types=1);
require_once __DIR__ . '/db_utils.php';
require_once __DIR__ . '/toeic_transaction.php';
if (!class_exists('ToeicTestBuilder', false)) {
    require_once __DIR__ . '/toeic_test_builder.php';
}

/**
 * Reserve one TOEIC credit and create all assignments as one atomic start operation.
 * Schema preflight runs before BEGIN because MySQL DDL would implicitly commit a credit update.
 * A per-user lock also keeps concurrent starts from consuming a stale preview or repeating history.
 */
function toeicStartSessionWithCredit(mysqli $conn, string $testSession, int $userId, array $options, ?int $expectedCreditId = null): void {
    if ($userId < 1 || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $testSession)) {
        throw new InvalidArgumentException('Invalid TOEIC start request.');
    }
    if (toeicConnectionInTransaction($conn)) {
        throw new RuntimeException('TOEIC start requires its own credit transaction.');
    }
    $builder = new ToeicTestBuilder($conn);
    $builder->prepareSessionSchema();
    _ensureStatusColumnSupportsUsed($conn);
    $lockName = 'toeic_start_' . $userId;
    $timeout = 5;
    $lock = $conn->prepare('SELECT GET_LOCK(?, ?) AS acquired');
    $lock->bind_param('si', $lockName, $timeout);
    $lock->execute();
    $acquired = !empty($lock->get_result()->fetch_assoc()['acquired']);
    $lock->close();
    if (!$acquired) { throw new RuntimeException('Sesi TOEIC lain sedang disiapkan. Silakan coba lagi.'); }
    $started = false;
    try {
        $conn->begin_transaction();
        $started = true;
        $existing = $conn->prepare('SELECT id FROM toeic_test_sessions WHERE test_session = ? FOR UPDATE');
        $existing->bind_param('s', $testSession);
        $existing->execute();
        $alreadyExists = $existing->get_result()->num_rows > 0;
        $existing->close();
        if ($alreadyExists) { throw new RuntimeException('Sesi TOEIC ini sudah dibuat. Gunakan menu lanjutkan sesi.'); }
        $preview = peekNextTestCredit($conn, $userId, 'toeic');
        if (!$preview || ($expectedCreditId !== null && (int)$preview['id'] !== $expectedCreditId)) {
            throw new RuntimeException('Paket TOEIC berubah atau tidak tersedia. Silakan muat ulang sebelum memulai.');
        }
        $creditId = (int)$preview['id'];
        $creditLock = $conn->prepare("SELECT id FROM user_purchases WHERE id = ? AND user_id = ? AND status = 'active' FOR UPDATE");
        $creditLock->bind_param('ii', $creditId, $userId);
        $creditLock->execute();
        $available = $creditLock->get_result()->num_rows === 1;
        $creditLock->close();
        if (!$available || !consumeTestCredit($conn, $userId, 'toeic')) {
            throw new RuntimeException('Paket TOEIC aktif tidak dapat dipakai. Silakan cek kembali paket Anda.');
        }
        $builder->createSession($testSession, $userId, $options);
        $builder->buildTest($testSession, $userId, $options);
        $conn->commit();
        $started = false;
    } catch (Throwable $error) {
        if ($started) { $conn->rollback(); }
        throw $error;
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $release->bind_param('s', $lockName);
        $release->execute();
        $release->close();
    }
}
