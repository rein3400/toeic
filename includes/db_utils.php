<?php

// ============================================================
// SCHEMA CACHE — avoids repeated SHOW COLUMNS / SHOW TABLES
// queries within a single request.
// ============================================================
$_schemaCache = [];

/**
 * Cached column-existence check. Each (table, column) pair is
 * queried at most once per PHP request.
 */
if (!function_exists('checkColumnExists')) {
    function checkColumnExists($conn, $table, $column) {
        global $_schemaCache;
        $cacheKey = "col:$table.$column";
        if (isset($_schemaCache[$cacheKey])) {
            return $_schemaCache[$cacheKey];
        }
        try {
            $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            $_schemaCache[$cacheKey] = ($result && $result->num_rows > 0);
        } catch (\Throwable $e) {
            $_schemaCache[$cacheKey] = false;
        }
        return $_schemaCache[$cacheKey];
    }
}

/**
 * Cached table-existence check.
 */
if (!function_exists('checkTableExists')) {
    function checkTableExists($conn, $table) {
        global $_schemaCache;
        $cacheKey = "tbl:$table";
        if (isset($_schemaCache[$cacheKey])) {
            return $_schemaCache[$cacheKey];
        }
        try {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $_schemaCache[$cacheKey] = ($result && $result->num_rows > 0);
        } catch (\Throwable $e) {
            $_schemaCache[$cacheKey] = false;
        }
        return $_schemaCache[$cacheKey];
    }
}

/**
 * Ensure user_purchases.status column can hold the value 'used'.
 * Runs at most once per PHP request (static cache). Silently alters the
 * column if it is an ENUM that does not yet include 'used'.
 */
if (!function_exists('_ensureStatusColumnSupportsUsed')) {
    function _ensureStatusColumnSupportsUsed($conn) {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $res = $conn->query(
                "SELECT column_type FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'user_purchases'
                   AND column_name  = 'status'"
            );
            $row = $res ? $res->fetch_assoc() : null;
            $col_type = strtolower($row['column_type'] ?? '');

            if (strpos($col_type, 'enum') !== false && strpos($col_type, "'used'") === false) {
                $conn->query(
                    "ALTER TABLE user_purchases
                     MODIFY COLUMN status ENUM('active','expired','revoked','used') DEFAULT 'active'"
                );
            }
        } catch (Throwable $e) {
            // Swallow — PHP 8.1+ throws mysqli_sql_exception by default.
        }
    }
}

if (!function_exists('getUsersIdColumn')) {
    function getUsersIdColumn($conn) {
        global $_schemaCache;
        $cacheKey = "col:users.id_user";
        if (isset($_schemaCache[$cacheKey])) {
            return $_schemaCache[$cacheKey] ? 'id_user' : 'id';
        }
        try {
            $result = $conn->query("SHOW COLUMNS FROM users LIKE 'id_user'");
            $exists = ($result && $result->num_rows > 0);
            if ($exists) {
                try {
                    if (checkColumnExists($conn, 'users', 'id')) {
                        $conn->query("UPDATE users SET id_user = id WHERE (id_user IS NULL OR id_user = 0) AND id IS NOT NULL");
                    }
                } catch (\Throwable $e) {
                    // Ignore backfill failure and continue with best-effort detection.
                }
            }
            $_schemaCache[$cacheKey] = $exists;
            return $exists ? 'id_user' : 'id';
        } catch (\Throwable $e) {
            $_schemaCache[$cacheKey] = false;
            return 'id';
        }
    }
}

/**
 * Check whether a user has at least one active test credit for the given exam type.
 */
if (!function_exists('hasTestCredit')) {
    function hasTestCredit($conn, $user_id, $exam_type) {
        $target_col = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';

        $stmt = $conn->prepare("SELECT id FROM user_purchases WHERE user_id = ? AND $target_col = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param("is", $user_id, $exam_type);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;

        // Defensive free trial for TOEIC if grantTestCredit failed during registration.
        if ($exam_type === 'toeic') {
            $has_real_test = false;
            try {
                $chk = $conn->prepare("SELECT id FROM toeic_test_sessions WHERE user_id = ? AND (practice_mode = 0 OR practice_mode IS NULL) LIMIT 1");
                if ($chk) {
                    $chk->bind_param("i", $user_id);
                    $chk->execute();
                    $has_real_test = $chk->get_result()->num_rows > 0;
                    $chk->close();
                }
            } catch (\Throwable $e) {
                // Table might not exist yet — treat as no completed TOEIC test.
            }

            if (!$has_real_test) {
                try {
                    $has_ref = checkColumnExists($conn, 'user_purchases', 'transaction_ref');
                    if ($has_ref) {
                        $ins = $conn->prepare("INSERT INTO user_purchases (user_id, $target_col, status, transaction_ref) VALUES (?, ?, 'active', 'FREE_TRIAL')");
                    } else {
                        $ins = $conn->prepare("INSERT INTO user_purchases (user_id, $target_col, status) VALUES (?, ?, 'active')");
                    }
                    $ins->bind_param("is", $user_id, $exam_type);
                    $ins->execute();
                    $ins->close();
                    return true;
                } catch (\Throwable $e) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('hasPurchaseHistory')) {
    function hasPurchaseHistory($conn, $user_id, $exam_type) {
        $target_col = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';
        $stmt = $conn->prepare("SELECT id FROM user_purchases WHERE user_id = ? AND $target_col = ? LIMIT 1");
        $stmt->bind_param("is", $user_id, $exam_type);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('hasStrictTestCredit')) {
    function hasStrictTestCredit($conn, $user_id, $exam_type) {
        $target_col = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';
        $stmt = $conn->prepare("SELECT id FROM user_purchases WHERE user_id = ? AND $target_col = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param("is", $user_id, $exam_type);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('countStrictTestCredits')) {
    function countStrictTestCredits($conn, $user_id, $exam_type) {
        $target_col = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM user_purchases WHERE user_id = ? AND $target_col = ? AND status = 'active'");
        $stmt->bind_param("is", $user_id, $exam_type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('hasToeicAccess')) {
    function hasToeicAccess($conn, $user_id) {
        if (hasTestCredit($conn, $user_id, 'toeic')) {
            return true;
        }

        if (hasPurchaseHistory($conn, $user_id, 'toeic')) {
            return true;
        }

        if (checkTableExists($conn, 'toeic_test_results')) {
            $stmt = $conn->prepare("SELECT test_session FROM toeic_test_results WHERE user_id = ? LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($exists) {
                return true;
            }
        }

        if (checkTableExists($conn, 'toeic_test_sessions')) {
            $stmt = $conn->prepare("SELECT test_session FROM toeic_test_sessions WHERE user_id = ? LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($exists) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Consume one active test credit for the given exam type.
 * Marks the oldest active credit as 'used' (preferred) or 'expired'
 * (fallback when the ENUM has not been patched yet).
 * Returns true on success, false if no credit available.
 */
if (!function_exists('consumeTestCredit')) {
    function consumeTestCredit($conn, $user_id, $exam_type) {
        _ensureStatusColumnSupportsUsed($conn);

        $target_col = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';

        // Find oldest active credit
        $stmt = $conn->prepare("SELECT id FROM user_purchases WHERE user_id = ? AND $target_col = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
        $stmt->bind_param("is", $user_id, $exam_type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return false;

        $has_used_at = checkColumnExists($conn, 'user_purchases', 'used_at');

        // Try 'used' first; fall back to 'expired' if the ENUM rejects it.
        $statuses = ['used', 'expired'];
        foreach ($statuses as $status) {
            try {
                if ($has_used_at) {
                    $upd = $conn->prepare("UPDATE user_purchases SET status = ?, used_at = NOW() WHERE id = ?");
                    $upd->bind_param("si", $status, $row['id']);
                } else {
                    $upd = $conn->prepare("UPDATE user_purchases SET status = ? WHERE id = ?");
                    $upd->bind_param("si", $status, $row['id']);
                }
                $ok = $upd->execute();
                $upd->close();
                return $ok;
            } catch (mysqli_sql_exception $e) {
                // 1265 = ER_WARN_DATA_TRUNCATED (value not in ENUM)
                if ($e->getCode() === 1265 && $status === 'used') {
                    continue; // retry with next fallback status
                }
                throw $e; // re-throw unexpected errors
            }
        }
        return false;
    }
}

/**
 * Grant one test credit to a user.
 * Tries INSERT; on duplicate-key (legacy UNIQUE on user_id+test_type)
 * falls back to reactivating the existing row.
 */
if (!function_exists('grantTestCredit')) {
    function grantTestCredit($conn, $user_id, $exam_type, $transaction_ref = null) {
        $target_col = checkColumnExists($conn, 'user_purchases', 'exam_type') ? 'exam_type' : 'test_type';
        $has_ref    = checkColumnExists($conn, 'user_purchases', 'transaction_ref');

        // Auto-create transaction_ref column if needed
        if (!$has_ref && $transaction_ref) {
            @$conn->query("ALTER TABLE user_purchases ADD COLUMN transaction_ref VARCHAR(100) DEFAULT NULL");
            $has_ref = checkColumnExists($conn, 'user_purchases', 'transaction_ref');
        }

        try {
            if ($has_ref && $transaction_ref) {
                $stmt = $conn->prepare("INSERT INTO user_purchases (user_id, $target_col, transaction_ref, status) VALUES (?, ?, ?, 'active')");
                $stmt->bind_param("iss", $user_id, $exam_type, $transaction_ref);
            } else {
                $stmt = $conn->prepare("INSERT INTO user_purchases (user_id, $target_col, status) VALUES (?, ?, 'active')");
                $stmt->bind_param("is", $user_id, $exam_type);
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (mysqli_sql_exception $e) {
            // 1062 = ER_DUP_ENTRY — legacy UNIQUE KEY (user_id, test_type) still present.
            // Reactivate the existing consumed/expired row instead.
            if ($e->getCode() === 1062) {
                $upd = $conn->prepare("UPDATE user_purchases SET status = 'active', purchase_date = NOW() WHERE user_id = ? AND $target_col = ? AND status != 'active' LIMIT 1");
                $upd->bind_param("is", $user_id, $exam_type);
                $ok = $upd->execute();
                $upd->close();
                return $ok;
            }
            throw $e;
        }
    }
}
