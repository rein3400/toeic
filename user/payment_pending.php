<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$website_title = getWebsiteTitle();
$user_name     = $_SESSION['full_name'] ?? 'Student';
$user_id       = (int) $_SESSION['user_id'];

// Avatar initials (up to 2 chars)
$initials = strtoupper(substr($user_name, 0, 1));
if (strpos($user_name, ' ') !== false) {
    $parts    = explode(' ', $user_name, 2);
    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
}

$order_id = trim($_GET['order_id'] ?? '');
if (empty($order_id)) {
    header('Location: buy_exam.php');
    exit;
}

// Verify order belongs to this user
$hasTransactionId = $conn->query("SHOW COLUMNS FROM payment_transactions LIKE 'transaction_id'")->num_rows > 0;
$idCol            = $hasTransactionId ? 'transaction_id' : 'order_id';

$stmt = $conn->prepare("SELECT status, amount FROM payment_transactions WHERE $idCol = ? AND user_id = ? LIMIT 1");
if (!$stmt) {
    header('Location: buy_exam.php');
    exit;
}
$stmt->bind_param('si', $order_id, $user_id);
$stmt->execute();
$tx = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tx) {
    header('Location: buy_exam.php');
    exit;
}

// Already paid? Skip the waiting page
if ($tx['status'] === 'settlement') {
    header('Location: index.php?payment=success');
    exit;
}

$amount_fmt = 'Rp ' . number_format((int)($tx['amount'] ?? 0), 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menunggu Pembayaran - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="css/dark-user.css" rel="stylesheet">
    <link href="css/mobile-responsive.css" rel="stylesheet">
    <style>
        .status-card {
            background: linear-gradient(180deg, rgba(255, 253, 248, 0.97), rgba(252, 248, 240, 0.98));
            border: 1px solid rgba(23, 38, 63, 0.1);
            border-radius: 28px;
            padding: 3rem 2rem;
            max-width: 500px;
            margin: 0 auto;
            text-align: center;
            box-shadow: 0 18px 42px rgba(21, 39, 66, 0.12);
        }
        .pulse-icon { animation: pulse 1.5s infinite; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%       { transform: scale(1.1); opacity: 0.7; }
        }
        #status-area { min-height: 120px; color: var(--toeic-ink); }
        code { color: var(--toeic-amber-deep); }
    </style>
</head>
<body>
    <div class="bg-orbs"></div>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container py-1">
            <a class="navbar-brand" href="index.php">
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="ms-auto dropdown">
                <div class="avatar-circle" data-bs-toggle="dropdown" role="button">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark-custom mt-2">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-cog me-2"></i> Profil Saya</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger-custom" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="main-content">
    <div class="container py-5">
        <div class="status-card fade-in-up">
            <div id="status-area">

                <!-- Pending state (shown by default) -->
                <div id="state-pending">
                    <div class="mb-4">
                        <i class="fas fa-clock fa-4x pulse-icon" style="color:#f59e0b;"></i>
                    </div>
                    <div class="toeic-kicker justify-content-center mb-3">Payment status</div>
                    <h4 class="fw-bold mb-2">Menunggu Pembayaran</h4>
                    <p class="text-white-50 mb-1">Order: <code><?php echo htmlspecialchars($order_id); ?></code></p>
                    <p class="text-white-50 mb-3">Total: <strong><?php echo $amount_fmt; ?></strong></p>

                    <!-- Dynamic payment info: VA number, QR code, or e-wallet redirect -->
                    <div id="payment-info" class="mb-3"></div>

                    <p class="text-white-50 mb-3" id="pending-desc" style="font-size:0.85rem;">
                        Selesaikan pembayaran di aplikasi Anda.<br>
                        Halaman ini otomatis memperbarui status.
                    </p>
                    <div id="tripay-link-area" class="mb-3" style="display:none;">
                        <a href="#" id="tripay-link" target="_blank" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-external-link-alt me-2"></i>Buka Halaman Pembayaran Tripay
                        </a>
                    </div>
                    <div class="spinner-border text-warning mt-2" role="status">
                        <span class="visually-hidden">Checking...</span>
                    </div>
                    <p class="text-white-50 mt-2" style="font-size:0.8rem;">
                        Mengecek status... (<span id="attempt-counter">0</span>/36)
                    </p>
                </div>

                <!-- Success state -->
                <div id="state-success" style="display:none;">
                    <div class="mb-4">
                        <i class="fas fa-check-circle fa-4x" style="color:#34d399;"></i>
                    </div>
                    <h4 class="fw-bold mb-2" style="color:#34d399;">Pembayaran Berhasil!</h4>
                    <p class="text-white-50 mb-4">Akses ujian Anda telah aktif. Mengalihkan ke dashboard...</p>
                </div>

                <!-- Failed state -->
                <div id="state-failed" style="display:none;">
                    <div class="mb-4">
                        <i class="fas fa-times-circle fa-4x" style="color:#f87171;"></i>
                    </div>
                    <h4 class="fw-bold mb-2" style="color:#f87171;">Pembayaran Gagal / Kadaluarsa</h4>
                    <p class="text-white-50 mb-4">Transaksi tidak berhasil. Silakan coba lagi.</p>
                    <a href="buy_exam.php" class="btn btn-outline-light">
                        <i class="fas fa-redo me-2"></i>Coba Lagi
                    </a>
                </div>

                <!-- Timeout state -->
                <div id="state-timeout" style="display:none;">
                    <div class="mb-4">
                        <i class="fas fa-hourglass-end fa-4x" style="color:#94a3b8;"></i>
                    </div>
                    <h4 class="fw-bold mb-2">Pembayaran Belum Terkonfirmasi</h4>
                    <p class="text-white-50 mb-4">
                        Pembayaran Anda belum terkonfirmasi setelah 3 menit.<br>
                        Jika sudah membayar, hubungi administrator dengan menyebutkan order ID berikut:
                    </p>
                    <p class="mb-4"><code><?php echo htmlspecialchars($order_id); ?></code></p>
                    <a href="buy_exam.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left me-2"></i>Kembali
                    </a>
                </div>

            </div><!-- /status-area -->
        </div><!-- /status-card -->
    </div>
    </div><!-- /main-content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
    (function () {
        const orderId   = <?php echo json_encode($order_id); ?>;
        const MAX_TRIES = 36; // 36 × 5 s = 3 minutes
        let   attempts  = 0;

        // Read payment details stored by payment.php
        const storedUrl = sessionStorage.getItem('tripay_payment_url_' + orderId) || '';
        const payCode   = sessionStorage.getItem('tripay_pay_code_'   + orderId) || '';
        const method    = sessionStorage.getItem('tripay_method_'      + orderId) || '';
        const qrUrl     = sessionStorage.getItem('tripay_qr_url_'      + orderId) || '';

        const infoEl   = document.getElementById('payment-info');
        const descEl   = document.getElementById('pending-desc');
        const isVA     = method && method.endsWith('VA');
        const bankName = isVA ? method.replace('VA', '') : '';

        if (isVA && payCode) {
            const safeCode = payCode.replace(/[^0-9]/g, '');
            infoEl.innerHTML =
                '<p class="text-white-50 mb-1" style="font-size:0.85rem;">Transfer ke nomor VA berikut:</p>' +
                '<div class="d-flex align-items-center justify-content-center gap-2 mb-2">' +
                    '<code style="font-size:1.4rem;letter-spacing:3px;color:#f59e0b;">' + safeCode + '</code>' +
                    '<button onclick="navigator.clipboard.writeText(\'' + safeCode + '\').then(function(){this.innerHTML=\'<i class=\\\"fas fa-check\\\"></i>\';}.bind(this))" ' +
                        'class="btn btn-outline-light btn-sm" title="Salin">' +
                        '<i class="fas fa-copy"></i>' +
                    '</button>' +
                '</div>' +
                '<p class="text-white-50" style="font-size:0.8rem;">Bank: ' + bankName + '</p>';
            if (descEl) descEl.innerHTML = 'Lakukan transfer bank via ATM atau mobile banking.<br>Halaman ini otomatis memperbarui status.';
        } else if (method === 'QRIS' && qrUrl) {
            infoEl.innerHTML =
                '<p class="text-white-50 mb-2" style="font-size:0.85rem;">Scan QR code ini dengan aplikasi apapun:</p>' +
                '<img src="' + qrUrl + '" alt="QRIS" style="width:200px;height:200px;border-radius:12px;border:2px solid rgba(255,255,255,0.1);" class="mb-2">';
            if (descEl) descEl.innerHTML = 'Scan QR di aplikasi apapun yang mendukung QRIS.<br>Halaman ini otomatis memperbarui status.';
        } else if (['OVO','SHOPEEPAY','DANA'].includes(method) && storedUrl) {
            // Fallback: normally auto-redirected from payment.php, but show link in case
            infoEl.innerHTML =
                '<a href="' + storedUrl + '" class="btn btn-warning mb-2" target="_blank">' +
                    '<i class="fas fa-external-link-alt me-2"></i>Lanjutkan Pembayaran' +
                '</a>';
            if (descEl) descEl.innerHTML = 'Selesaikan pembayaran di aplikasi Anda.<br>Halaman ini otomatis memperbarui status.';
        }

        // Show Tripay fallback link
        if (storedUrl) {
            const linkArea = document.getElementById('tripay-link-area');
            const link     = document.getElementById('tripay-link');
            if (linkArea && link) {
                link.href = storedUrl;
                linkArea.style.display = '';
            }
        }

        function showState(name) {
            ['pending', 'success', 'failed', 'timeout'].forEach(function (s) {
                document.getElementById('state-' + s).style.display = (s === name) ? '' : 'none';
            });
        }

        function poll() {
            if (attempts >= MAX_TRIES) {
                showState('timeout');
                return;
            }
            attempts++;
            document.getElementById('attempt-counter').textContent = attempts;

            fetch('ajax_payment_status.php?order_id=' + encodeURIComponent(orderId))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        setTimeout(poll, 5000);
                        return;
                    }
                    switch (data.status) {
                        case 'settlement':
                            showState('success');
                            setTimeout(function () {
                                window.location.href = 'index.php?payment=success';
                            }, 2000);
                            break;
                        case 'expire':
                        case 'deny':
                        case 'cancel':
                            showState('failed');
                            break;
                        default:
                            setTimeout(poll, 5000);
                    }
                })
                .catch(function () {
                    // Network error — keep polling
                    setTimeout(poll, 5000);
                });
        }

        // Start first poll after 3 s
        setTimeout(poll, 3000);
    })();
    </script>
</body>
</html>
