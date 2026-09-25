<?php
declare(strict_types=1);

/**
 * Detect caller-owned transaction mode on MySQL and MariaDB without committing it.
 * SAVEPOINT is a no-op in autocommit mode; RELEASE then returns ER_SP_DOES_NOT_EXIST.
 * Inside BEGIN/autocommit-off, the unique savepoint exists and can be released.
 * No vendor-only system variable, table read, BEGIN, DDL, COMMIT or ROLLBACK is used.
 */
function toeicConnectionInTransaction(mysqli $conn): bool {
    $name = 'toeic_probe_' . bin2hex(random_bytes(12));
    if (!$conn->query('SAVEPOINT `' . $name . '`')) {
        throw new RuntimeException('Cannot inspect TOEIC transaction state.');
    }
    try {
        $released = $conn->query('RELEASE SAVEPOINT `' . $name . '`');
    } catch (mysqli_sql_exception $error) {
        if ($error->getCode() === 1305) { return false; }
        throw $error;
    }
    if ($released === false) {
        if ($conn->errno === 1305) { return false; }
        throw new RuntimeException('Cannot release TOEIC transaction probe.');
    }
    return true;
}
