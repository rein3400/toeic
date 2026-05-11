<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

function generateVoucherCode($conn) {
    $charset = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = 'OSGLI-';
        for ($i = 0; $i < 5; $i++) {
            $code .= $charset[random_int(0, strlen($charset) - 1)];
        }
        $s = $conn->prepare("SELECT id FROM vouchers WHERE code = ?");
        $s->bind_param("s", $code);
        $s->execute();
        $exists = $s->get_result()->num_rows > 0;
        $s->close();
    } while ($exists);
    return $code;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'generate': {
        $exam_type  = trim($_POST['exam_type'] ?? '');
        $count      = (int)($_POST['count'] ?? 1);
        $expires_at = trim($_POST['expires_at'] ?? '');

        if (empty($exam_type)) {
            echo json_encode(['success' => false, 'error' => 'exam_type is required']);
            exit;
        }
        $allowed_exam_types = ['toeic', 'toeic_sw'];
        if (!in_array($exam_type, $allowed_exam_types)) {
            echo json_encode(['success' => false, 'error' => 'Jenis ujian tidak valid.']);
            exit;
        }
        if ($count < 1 || $count > 500) {
            echo json_encode(['success' => false, 'error' => 'count must be between 1 and 500']);
            exit;
        }

        if (!empty($expires_at)) {
            $dt = DateTime::createFromFormat('Y-m-d', $expires_at) ?: DateTime::createFromFormat('Y-m-d H:i:s', $expires_at);
            if (!$dt) {
                echo json_encode(['success' => false, 'error' => 'Format tanggal kadaluarsa tidak valid.']);
                exit;
            }
            $expires_at = $dt->format('Y-m-d H:i:s');
        }

        $admin_id  = (int)$_SESSION['user_id'];

        // Server-side rate limiting: prevent duplicate generation within 5 seconds
        // by checking if the same admin generated vouchers in the last 5 seconds
        $rate_stmt = $conn->prepare("
            SELECT COUNT(*) as recent_count, GROUP_CONCAT(batch_id) as recent_batches
            FROM vouchers
            WHERE created_by = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
        ");
        $rate_stmt->bind_param("i", $admin_id);
        $rate_stmt->execute();
        $rate_row = $rate_stmt->get_result()->fetch_assoc();
        $rate_stmt->close();

        if ($rate_row['recent_count'] > 0) {
            error_log("[VOUCHER] Rate limit hit: admin_id=$admin_id, recent_count={$rate_row['recent_count']}, batches={$rate_row['recent_batches']}");
            echo json_encode([
                'success' => false,
                'error' => 'Generate voucher terlalu cepat. Tunggu beberapa detik sebelum generate lagi.',
                'recent_count' => (int)$rate_row['recent_count'],
            ]);
            exit;
        }

        $batch_id  = 'BATCH-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $generated = [];

        $expires_param = (!empty($expires_at)) ? $expires_at : null;

        $stmt = $conn->prepare(
            "INSERT INTO vouchers (code, exam_type, status, created_by, expires_at, batch_id)
             VALUES (?, ?, 'active', ?, ?, ?)"
        );

        for ($i = 0; $i < $count; $i++) {
            try {
                $code = generateVoucherCode($conn);
                $stmt->bind_param("ssiss", $code, $exam_type, $admin_id, $expires_param, $batch_id);
                if ($stmt->execute()) {
                    $generated[] = ['code' => $code];
                }
            } catch (Throwable $e) {
                // Skip failed inserts but continue generating remaining vouchers
            }
        }
        $stmt->close();

        // Write audit log if table exists
        $audit_check = $conn->query("SHOW TABLES LIKE 'voucher_audit_log'");
        if ($audit_check && $audit_check->num_rows > 0) {
            $audit_stmt = $conn->prepare("
                INSERT INTO voucher_audit_log (voucher_code, action, performed_by, details)
                VALUES (?, 'generate', ?, ?)
            ");
            if ($audit_stmt) {
                $details = json_encode(['batch_id' => $batch_id, 'exam_type' => $exam_type, 'count' => count($generated)]);
                foreach ($generated as $v) {
                    $audit_stmt->bind_param("sis", $v['code'], $admin_id, $details);
                    $audit_stmt->execute();
                }
                $audit_stmt->close();
            }
        }

        echo json_encode([
            'success'  => true,
            'vouchers' => $generated,
            'batch_id' => $batch_id,
            'count'    => count($generated),
        ]);
        exit;
    }

    case 'list': {
        $status    = trim($_GET['status'] ?? '');
        $exam_type = trim($_GET['exam_type'] ?? '');
        $batch_id  = trim($_GET['batch_id'] ?? '');
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $per_page  = 50;
        $offset    = ($page - 1) * $per_page;

        $users_id_col = getUsersIdColumn($conn);

        // Build WHERE conditions
        $conditions = [];
        $types      = '';
        $params     = [];

        if (!empty($status)) {
            $conditions[] = 'v.status = ?';
            $types        .= 's';
            $params[]     = $status;
        }
        if (!empty($exam_type)) {
            $conditions[] = 'v.exam_type = ?';
            $types        .= 's';
            $params[]     = $exam_type;
        }
        if (!empty($batch_id)) {
            $conditions[] = 'v.batch_id = ?';
            $types        .= 's';
            $params[]     = $batch_id;
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count query
        $count_sql  = "SELECT COUNT(*) AS total FROM vouchers v $where";
        $count_stmt = $conn->prepare($count_sql);
        if ($types && $params) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $total = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();

        // Data query with LEFT JOIN to users for redeemed_by_name
        $data_sql = "
            SELECT
                v.id,
                v.code,
                v.exam_type,
                v.status,
                v.batch_id,
                v.created_at,
                v.redeemed_by,
                v.redeemed_at,
                v.expires_at,
                u.full_name AS redeemed_by_name
            FROM vouchers v
            LEFT JOIN users u ON u.{$users_id_col} = v.redeemed_by
            $where
            ORDER BY v.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $data_stmt = $conn->prepare($data_sql);
        $data_types  = $types . 'ii';
        $data_params = array_merge($params, [$per_page, $offset]);
        $data_stmt->bind_param($data_types, ...$data_params);
        $data_stmt->execute();
        $result   = $data_stmt->get_result();
        $vouchers = [];
        while ($row = $result->fetch_assoc()) {
            $vouchers[] = $row;
        }
        $data_stmt->close();

        echo json_encode([
            'success'  => true,
            'vouchers' => $vouchers,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ]);
        exit;
    }

    case 'disable': {
        $voucher_id = (int)($_POST['voucher_id'] ?? 0);
        if ($voucher_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid voucher_id']);
            exit;
        }

        $stmt = $conn->prepare(
            "UPDATE vouchers SET status = 'disabled' WHERE id = ? AND status = 'active'"
        );
        $stmt->bind_param("i", $voucher_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            echo json_encode(['success' => false, 'error' => 'Voucher not found or not active']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    case 'disable_batch': {
        $batch_id = trim($_POST['batch_id'] ?? '');
        if (empty($batch_id)) {
            echo json_encode(['success' => false, 'error' => 'batch_id is required']);
            exit;
        }

        $stmt = $conn->prepare(
            "UPDATE vouchers SET status = 'disabled' WHERE batch_id = ? AND status = 'active'"
        );
        $stmt->bind_param("s", $batch_id);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();

        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    case 'export_csv': {
        $batch_id = trim($_GET['batch_id'] ?? '');
        if (empty($batch_id)) {
            echo json_encode(['success' => false, 'error' => 'batch_id is required']);
            exit;
        }

        $stmt = $conn->prepare(
            "SELECT code, exam_type FROM vouchers WHERE batch_id = ? ORDER BY created_at ASC"
        );
        $stmt->bind_param("s", $batch_id);
        $stmt->execute();
        $result = $stmt->get_result();

        // Override Content-Type for CSV output
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vouchers_' . preg_replace('/[^A-Za-z0-9\-]/', '_', $batch_id) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['code', 'exam_type']);
        while ($row = $result->fetch_assoc()) {
            fputcsv($out, [$row['code'], $row['exam_type']]);
        }
        fclose($out);
        $stmt->close();
        exit;
    }

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}
