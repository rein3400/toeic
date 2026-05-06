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
    <title>Waiting for Payment - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .pulse-icon { animation: pulse 1.5s infinite; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
        }
        #status-area { min-height: 300px; display: flex; flex-direction: column; justify-content: center; }
        .payment-qr {
            width: 220px;
            height: 220px;
            background: white;
            padding: 10px;
            border: 2px solid var(--cloud-line);
            border-radius: 16px;
            margin: 1.5rem auto;
        }
    </style>
</head>
<body class="tc-user-page tc-payment-status-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="avatar-circle"><?php echo htmlspecialchars($initials); ?></div>
        </div>
    </header>

    <main class="toeic-page-shell d-flex align-items-center justify-content-center" style="min-height: 80dvh;">
        <div class="study-card text-center" style="max-width: 540px; width: 100%;">
            <div id="status-area">
                <!-- Pending state -->
                <div id="state-pending">
                    <div class="mb-4">
                        <i class="fas fa-clock fa-4x pulse-icon text-warning"></i>
                    </div>
                    <span class="study-kicker">Payment Status</span>
                    <h2 class="h3 mb-3 fw-bold">Waiting for Payment</h2>

                    <div class="p-3 rounded-4 mb-4" style="background: rgba(72,127,181,0.05);">
                        <div class="small text-muted uppercase fw-bold mb-1">Total Amount</div>
                        <div class="h4 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo $amount_fmt; ?></div>
                        <div class="mt-2 small">Order ID: <code><?php echo htmlspecialchars($order_id); ?></code></div>
                    </div>

                    <div id="payment-info"></div>

                    <p class="small text-muted mb-4" id="pending-desc">
                        Please complete the payment in your app.<br>
                        This page will refresh automatically.
                    </p>

                    <div id="tripay-link-area" class="mb-4" style="display:none;">
                        <a href="#" id="tripay-link" target="_blank" class="study-button study-button-secondary w-100">
                            <i class="fas fa-external-link-alt me-2"></i>Open Payment Page
                        </a>
                    </div>

                    <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="small text-muted fw-bold">Checking status... (<span id="attempt-counter">0</span>/36)</span>
                    </div>
                </div>

                <!-- Success state -->
                <div id="state-success" style="display:none;">
                    <div class="mb-4">
                        <i class="fas fa-check-circle fa-5x text-success"></i>
                    </div>
                    <h2 class="h3 fw-bold mb-2">Payment Successful!</h2>
                    <p class="text-muted">Your exam access is now active. Redirecting you to the dashboard...</p>
                </div>

                <!-- Failed state -->
                <div id="state-failed" style="display:none;">
                    <div class="mb-4">
                        <i class="fas fa-times-circle fa-5x text-danger"></i>
                    </div>
                    <h2 class="h3 fw-bold mb-2">Payment Failed</h2>
                    <p class="text-muted mb-4">We couldn't process your transaction. Please try again.</p>
                    <a href="buy_exam.php" class="study-button w-100">Try Again</a>
                </div>

                <!-- Timeout state -->
                <div id="state-timeout" style="display:none;">
                    <div class="mb-4">
                        <i class="fas fa-exclamation-triangle fa-5x text-muted"></i>
                    </div>
                    <h2 class="h3 fw-bold mb-2">Transaction Pending</h2>
                    <p class="text-muted mb-4">
                        We're still waiting for confirmation. If you've already paid, please contact support with your Order ID:
                    </p>
                    <div class="p-3 bg-light rounded-3 mb-4"><code><?php echo htmlspecialchars($order_id); ?></code></div>
                    <a href="buy_exam.php" class="study-button study-button-secondary w-100">Back to Packages</a>
                </div>
            </div>
        </div>
    </main>

    <script>
    (function () {
        const orderId   = <?php echo json_encode($order_id); ?>;
        const MAX_TRIES = 36;
        let   attempts  = 0;

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
            infoEl.innerHTML = `
                <div class="mb-4">
                    <div class="small text-muted uppercase fw-bold mb-2">Transfer to ${bankName} Virtual Account</div>
                    <div class="d-flex align-items-center justify-content-center gap-2">
                        <code class="h3 fw-bold mb-0" style="letter-spacing:2px; color:var(--focus-blue);">${safeCode}</code>
                        <button onclick="copyToClipboard('${safeCode}', this)" class="btn btn-sm btn-outline-primary border-0 rounded-pill px-3">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                </div>
            `;
            if (descEl) descEl.innerHTML = 'Complete the transfer via ATM or Mobile Banking.';
        } else if (method === 'QRIS' && qrUrl) {
            infoEl.innerHTML = `
                <div class="mb-4">
                    <div class="small text-muted uppercase fw-bold mb-2">Scan QRIS Code</div>
                    <div class="payment-qr shadow-sm">
                        <img src="${qrUrl}" alt="QRIS" class="img-fluid rounded-3">
                    </div>
                </div>
            `;
            if (descEl) descEl.innerHTML = 'Scan with any supported payment app.';
        } else if (['OVO','SHOPEEPAY','DANA'].includes(method) && storedUrl) {
            infoEl.innerHTML = `
                <div class="mb-4">
                    <a href="${storedUrl}" class="study-button w-100" target="_blank">
                        <i class="fas fa-external-link-alt me-2"></i>Pay via ${method}
                    </a>
                </div>
            `;
        }

        if (storedUrl) {
            const linkArea = document.getElementById('tripay-link-area');
            const link     = document.getElementById('tripay-link');
            if (linkArea && link) { link.href = storedUrl; linkArea.style.display = ''; }
        }

        window.copyToClipboard = function(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const icon = btn.querySelector('i');
                icon.className = 'fas fa-check';
                setTimeout(() => icon.className = 'fas fa-copy', 2000);
            });
        };

        function showState(name) {
            ['pending', 'success', 'failed', 'timeout'].forEach(s => {
                document.getElementById('state-' + s).style.display = (s === name) ? '' : 'none';
            });
        }

        function poll() {
            if (attempts >= MAX_TRIES) { showState('timeout'); return; }
            attempts++;
            document.getElementById('attempt-counter').textContent = attempts;

            fetch('ajax_payment_status.php?order_id=' + encodeURIComponent(orderId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { setTimeout(poll, 5000); return; }
                    switch (data.status) {
                        case 'settlement':
                            showState('success');
                            setTimeout(() => window.location.href = 'index.php?payment=success', 2000);
                            break;
                        case 'expire': case 'deny': case 'cancel':
                            showState('failed');
                            break;
                        default: setTimeout(poll, 5000);
                    }
                })
                .catch(() => setTimeout(poll, 5000));
        }

        setTimeout(poll, 3000);
    })();
    </script>
</body>
</html>
