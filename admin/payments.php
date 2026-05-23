<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$uid = getUsersIdColumn($conn);
$payment_table_ready = checkTableExists($conn, 'payment_transactions');
$success = '';
$error = '';

function paymentAdminRp($amount): string {
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function paymentAdminRefExpression(mysqli $conn, string $alias = 'pt'): string {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $refs = [];
    if (checkColumnExists($conn, 'payment_transactions', 'transaction_id')) {
        $refs[] = "NULLIF({$prefix}transaction_id, '')";
    }
    if (checkColumnExists($conn, 'payment_transactions', 'order_id')) {
        $refs[] = "NULLIF({$prefix}order_id, '')";
    }
    $refs[] = "CAST({$prefix}id AS CHAR)";

    return 'COALESCE(' . implode(', ', $refs) . ')';
}

function paymentAdminOptionalColumn(mysqli $conn, string $column, string $alias, string $default = 'NULL'): string {
    return checkColumnExists($conn, 'payment_transactions', $column)
        ? "pt.{$column} AS {$alias}"
        : "{$default} AS {$alias}";
}

function paymentAdminInferExamType(array $transaction): string {
    $testType = strtolower(trim((string)($transaction['test_type'] ?? '')));
    if ($testType === 'toeic_sw') {
        return 'toeic_sw';
    }

    $ref = strtoupper((string)($transaction['transaction_ref'] ?? ''));
    if (strpos($ref, 'TOEICSW') !== false) {
        return 'toeic_sw';
    }

    return 'toeic';
}

function paymentAdminExamLabel(string $examType): string {
    return $examType === 'toeic_sw' ? 'TOEIC SW' : 'TOEIC LR';
}

function paymentAdminStatusBadge(string $status): string {
    $status = strtolower(trim($status));
    $classes = [
        'pending' => 'bg-warning text-dark',
        'settlement' => 'bg-success',
        'deny' => 'bg-danger',
        'expire' => 'bg-secondary',
        'cancel' => 'bg-secondary',
    ];
    $class = $classes[$status] ?? 'bg-light text-dark';

    return '<span class="badge ' . $class . '">' . htmlspecialchars($status ?: 'unknown') . '</span>';
}

function paymentAdminMethodLabel(array $transaction): string {
    $method = strtoupper(trim((string)($transaction['payment_method'] ?? '')));
    $type = strtolower(trim((string)($transaction['payment_type'] ?? '')));
    if ($method === 'BANK_TRANSFER' || $type === 'direct_bank') {
        return 'GoPay Manual';
    }
    if ($method !== '') {
        return $method;
    }
    return $type !== '' ? $type : '-';
}

function paymentAdminBind(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types !== '') {
        $bindParams = [$types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }
}

function paymentAdminCount(mysqli $conn, string $where = '', string $types = '', array $params = []): int {
    $sql = "SELECT COUNT(*) AS total FROM payment_transactions pt" . ($where !== '' ? " WHERE {$where}" : '');
    $stmt = $conn->prepare($sql);
    paymentAdminBind($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$payment_table_ready) {
        $error = 'Tabel payment_transactions belum tersedia. Jalankan migration lebih dulu.';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Token verifikasi tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $transaction_id = (int)($_POST['transaction_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $allowed_actions = [
            'settle' => 'settlement',
            'deny' => 'deny',
        ];

        if ($transaction_id <= 0 || !isset($allowed_actions[$action])) {
            $error = 'Aksi pembayaran tidak valid.';
        } else {
            try {
                $conn->begin_transaction();

                $refExpr = paymentAdminRefExpression($conn, 'pt');
                $testTypeSelect = paymentAdminOptionalColumn($conn, 'test_type', 'test_type');
                $stmt = $conn->prepare("
                    SELECT pt.id, pt.user_id, pt.status, pt.amount, {$refExpr} AS transaction_ref, {$testTypeSelect}
                    FROM payment_transactions pt
                    WHERE pt.id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param('i', $transaction_id);
                $stmt->execute();
                $transaction = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$transaction) {
                    throw new RuntimeException('Transaksi tidak ditemukan.');
                }

                $new_status = $allowed_actions[$action];
                if ($new_status === 'settlement') {
                    $exam_type = paymentAdminInferExamType($transaction);
                    $granted = grantSettledPaymentCredit(
                        $conn,
                        (int)$transaction['user_id'],
                        $exam_type,
                        (string)$transaction['transaction_ref']
                    );
                    if (!$granted) {
                        throw new RuntimeException('Gagal memberi kredit TOEIC untuk pembayaran ini.');
                    }
                }

                $update = $conn->prepare("UPDATE payment_transactions SET status = ? WHERE id = ?");
                $update->bind_param('si', $new_status, $transaction_id);
                if (!$update->execute()) {
                    throw new RuntimeException('Gagal memperbarui status pembayaran.');
                }
                $update->close();

                $conn->commit();
                $success = $new_status === 'settlement'
                    ? 'Pembayaran diverifikasi dan kredit TOEIC sudah diberikan.'
                    : 'Pembayaran ditandai tidak valid.';
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'settlement', 'deny', 'expire', 'cancel', 'all'], true)) {
    $status_filter = 'pending';
}
$search = trim((string)($_GET['search'] ?? ''));

$transactions = [];
$status_counts = ['pending' => 0, 'settlement' => 0, 'deny' => 0, 'expire' => 0, 'cancel' => 0, 'all' => 0];

if ($payment_table_ready) {
    foreach (['pending', 'settlement', 'deny', 'expire', 'cancel'] as $status) {
        $status_counts[$status] = paymentAdminCount($conn, 'pt.status = ?', 's', [$status]);
    }
    $status_counts['all'] = paymentAdminCount($conn);

    $refExpr = paymentAdminRefExpression($conn, 'pt');
    $select = [
        'pt.id',
        'pt.user_id',
        'pt.status',
        'pt.amount',
        "{$refExpr} AS transaction_ref",
        paymentAdminOptionalColumn($conn, 'test_type', 'test_type'),
        paymentAdminOptionalColumn($conn, 'payment_method', 'payment_method'),
        paymentAdminOptionalColumn($conn, 'payment_type', 'payment_type'),
        paymentAdminOptionalColumn($conn, 'created_at', 'created_at'),
        'u.full_name',
        'u.username',
    ];

    $where = [];
    $types = '';
    $params = [];

    if ($status_filter !== 'all') {
        $where[] = 'pt.status = ?';
        $types .= 's';
        $params[] = $status_filter;
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "({$refExpr} LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $conn->prepare("
        SELECT " . implode(",\n               ", $select) . "
        FROM payment_transactions pt
        LEFT JOIN users u ON u.{$uid} = pt.user_id
        {$whereSql}
        ORDER BY
            CASE pt.status
                WHEN 'pending' THEN 1
                WHEN 'settlement' THEN 2
                WHEN 'deny' THEN 3
                WHEN 'expire' THEN 4
                ELSE 5
            END,
            pt.id DESC
        LIMIT 100
    ");
    paymentAdminBind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?php echo csrfMeta(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Pembayaran - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .payment-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.25rem;
        }
        .payment-stat {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 1rem;
        }
        .payment-table th {
            color: rgba(255,255,255,0.55);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom-color: rgba(255,255,255,0.12);
        }
        .payment-table td {
            color: rgba(255,255,255,0.85);
            border-bottom-color: rgba(255,255,255,0.06);
            vertical-align: middle;
        }
        .payment-filter {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
            border-radius: 8px;
        }
        .payment-filter:focus {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.25);
            color: #fff;
            box-shadow: none;
        }
        .payment-filter option {
            background: #111827;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div>
                        <div class="small text-uppercase text-muted fw-semibold">TOEIC Payments</div>
                        <h1 class="fw-bold mb-1">Verifikasi Pembayaran</h1>
                        <p class="text-muted mb-0">Review pembayaran manual GoPay dan grant kredit TOEIC setelah valid.</p>
                    </div>
                    <a href="settings.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                        <i class="fas fa-cog me-2"></i>Payment Settings
                    </a>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success border-0"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger border-0"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if (!$payment_table_ready): ?>
                    <div class="payment-panel">
                        <h2 class="h5 fw-bold mb-2">Tabel pembayaran belum tersedia</h2>
                        <p class="text-muted mb-0">Jalankan migration TOEIC standalone agar admin bisa membaca transaksi manual dan gateway.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3 mb-4">
                        <?php foreach (['pending' => 'Pending', 'settlement' => 'Settled', 'deny' => 'Rejected', 'all' => 'Total'] as $key => $label): ?>
                            <div class="col-lg-3 col-md-6">
                                <div class="payment-stat">
                                    <div class="small text-muted mb-2"><?php echo htmlspecialchars($label); ?></div>
                                    <div class="h2 fw-bold mb-0"><?php echo (int)$status_counts[$key]; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="payment-panel mb-4">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Status</label>
                                <select name="status" class="form-select payment-filter">
                                    <?php foreach (['pending' => 'Pending', 'settlement' => 'Settled', 'deny' => 'Rejected', 'expire' => 'Expired', 'cancel' => 'Cancelled', 'all' => 'All'] as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Cari nama, username, atau order</label>
                                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control payment-filter" placeholder="BANK-TOEIC...">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-warning w-100 fw-bold" type="submit">
                                    <i class="fas fa-filter me-2"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="payment-panel">
                        <div class="table-responsive">
                            <table class="table payment-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Student</th>
                                        <th>Product</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">Tidak ada transaksi pada filter ini.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $transaction): ?>
                                            <?php
                                            $examType = paymentAdminInferExamType($transaction);
                                            $status = strtolower((string)($transaction['status'] ?? ''));
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)$transaction['transaction_ref']); ?></div>
                                                    <div class="small text-muted">#<?php echo (int)$transaction['id']; ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($transaction['full_name'] ?: 'Unknown user')); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars((string)($transaction['username'] ?? '')); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars(paymentAdminExamLabel($examType)); ?></td>
                                                <td><?php echo htmlspecialchars(paymentAdminMethodLabel($transaction)); ?></td>
                                                <td class="fw-semibold"><?php echo paymentAdminRp($transaction['amount'] ?? 0); ?></td>
                                                <td><?php echo paymentAdminStatusBadge($status); ?></td>
                                                <td class="small text-muted">
                                                    <?php echo !empty($transaction['created_at']) ? htmlspecialchars(date('d M Y H:i', strtotime((string)$transaction['created_at']))) : '-'; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($status === 'pending'): ?>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Verifikasi pembayaran ini dan grant kredit TOEIC?');">
                                                            <?php echo csrfField(); ?>
                                                            <input type="hidden" name="transaction_id" value="<?php echo (int)$transaction['id']; ?>">
                                                            <input type="hidden" name="action" value="settle">
                                                            <button class="btn btn-success btn-sm fw-bold" type="submit">
                                                                <i class="fas fa-check me-1"></i>Verify
                                                            </button>
                                                        </form>
                                                        <form method="post" class="d-inline ms-1" onsubmit="return confirm('Tolak pembayaran ini?');">
                                                            <?php echo csrfField(); ?>
                                                            <input type="hidden" name="transaction_id" value="<?php echo (int)$transaction['id']; ?>">
                                                            <input type="hidden" name="action" value="deny">
                                                            <button class="btn btn-outline-danger btn-sm fw-bold" type="submit">
                                                                <i class="fas fa-times me-1"></i>Reject
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">No action</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
