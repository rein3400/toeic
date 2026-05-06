<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$exam_type = $_GET['exam_type'] ?? 'toeic';
if ($exam_type !== 'toeic') {
    header("Location: buy_exam.php");
    exit();
}

$website_title = getWebsiteTitle();
$product_name = getSiteSetting('name_toeic', 'TOEIC Listening & Reading');
$price_value = (int)getSiteSetting('price_toeic', '175000');
$price_formatted = 'Rp ' . number_format($price_value, 0, ',', '.');
$tripay_ready = !empty(TRIPAY_API_KEY) && !empty(TRIPAY_PRIVATE_KEY) && !empty(TRIPAY_MERCHANT_CODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>Payment - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .payment-option {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid var(--cloud-line);
            border-bottom-width: 5px;
            background: white;
            border-radius: 12px;
            padding: 1rem;
            height: 100%;
        }
        .payment-option:hover {
            transform: translateY(-2px);
            border-color: var(--focus-blue);
        }
        .payment-option.active {
            border-color: var(--focus-blue);
            background: rgba(72, 127, 181, 0.05);
        }
        .payment-option input:checked + div .fw-bold {
            color: var(--focus-blue);
        }
    </style>
</head>
<body class="tc-user-page tc-payment-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <a href="buy_exam.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">Back</a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <div class="mb-5">
            <span class="study-kicker">Checkout</span>
            <h1 class="display-5 mb-2">Payment Method</h1>
            <p class="lead text-muted">Securely complete your purchase to start your TOEIC simulation.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <section class="study-card mb-4">
                    <span class="study-kicker">Selection</span>
                    <h2 class="h4 mb-4">Choose Method</h2>

                    <div class="row g-3">
                        <?php foreach ([
                            ['QRIS', 'QRIS', 'fa-qrcode'],
                            ['OVO', 'OVO', 'fa-wallet'],
                            ['DANA', 'DANA', 'fa-money-bill'],
                            ['SHOPEEPAY', 'ShopeePay', 'fa-shopping-bag'],
                            ['BCAVA', 'BCA Virtual Account', 'fa-building-columns'],
                            ['BNIVA', 'BNI Virtual Account', 'fa-building-columns'],
                            ['BRIVA', 'BRI Virtual Account', 'fa-building-columns'],
                            ['MANDIRIVA', 'Mandiri Virtual Account', 'fa-building-columns'],
                        ] as $index => $method): ?>
                            <div class="col-md-6 col-xl-4">
                                <label class="payment-option d-block <?php echo $index === 0 ? 'active' : ''; ?>" data-method="<?php echo $method[0]; ?>">
                                    <input type="radio" name="payment_method" value="<?php echo $method[0]; ?>" <?php echo $index === 0 ? 'checked' : ''; ?> class="d-none">
                                    <div class="d-flex flex-column align-items-center text-center">
                                        <div class="avatar-circle mb-2" style="width:50px; height:50px; background:rgba(0,0,0,0.03) !important; border:none;">
                                            <i class="fas <?php echo $method[2]; ?> text-primary h4 mb-0"></i>
                                        </div>
                                        <div class="fw-bold small uppercase"><?php echo htmlspecialchars($method[1]); ?></div>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-5 pt-4 border-top">
                        <div class="study-kicker mb-3">Or Use Voucher</div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <input type="text" id="voucherCode" class="form-control" placeholder="VOUCHER_CODE_HERE">
                            </div>
                            <div class="col-md-4">
                                <button class="study-button w-100" onclick="redeemVoucher()">Redeem</button>
                            </div>
                        </div>
                        <div id="voucherMessage" class="small mt-2 fw-bold"></div>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="study-card h-100">
                    <span class="study-kicker">Order</span>
                    <h2 class="h4 mb-4">Summary</h2>

                    <div class="p-4 rounded-4 mb-4" style="background: rgba(72, 127, 181, 0.05);">
                        <div class="fw-bold h5 mb-1" style="color:var(--focus-blue);"><?php echo htmlspecialchars($product_name); ?></div>
                        <div class="text-muted small mb-4">Full access with score reports</div>

                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <span class="fw-bold">Total</span>
                            <span class="h4 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo $price_formatted; ?></span>
                        </div>
                    </div>

                    <ul class="list-unstyled mb-5">
                        <li class="mb-2 small d-flex gap-2 align-items-center"><i class="fas fa-check-circle text-success"></i> Instant Activation</li>
                        <li class="mb-2 small d-flex gap-2 align-items-center"><i class="fas fa-check-circle text-success"></i> Secure Transaction</li>
                        <li class="mb-2 small d-flex gap-2 align-items-center"><i class="fas fa-check-circle text-success"></i> Valid for 1 Session</li>
                    </ul>

                    <?php if ($tripay_ready): ?>
                        <button id="payButton" class="study-button w-100" onclick="createTransaction()">Complete Purchase</button>
                    <?php else: ?>
                        <div class="alert alert-warning border-0 small text-center">Payment system offline.</div>
                    <?php endif; ?>
                    <div id="paymentMessage" class="small mt-3 text-center text-muted"></div>
                </section>
            </div>
        </div>
    </main>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const options = document.querySelectorAll('.payment-option');

        options.forEach(opt => {
            opt.addEventListener('click', () => {
                options.forEach(o => o.classList.remove('active'));
                opt.classList.add('active');
            });
        });

        async function createTransaction() {
            const button = document.getElementById('payButton');
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked').value;
            const message = document.getElementById('paymentMessage');
            button.disabled = true;
            button.textContent = 'Processing...';
            message.textContent = '';

            try {
                const response = await fetch('../api/create_transaction.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({exam_type: 'toeic', payment_method: paymentMethod})
                });
                const data = await response.json();
                if (data.status === 'success') {
                    window.location.href = data.payment_url || data.redirect_url || 'index.php';
                    return;
                }
                message.textContent = data.message || 'Failed to create transaction.';
            } catch (error) {
                message.textContent = error.message;
            } finally {
                button.disabled = false;
                button.textContent = 'Complete Purchase';
            }
        }

        async function redeemVoucher() {
            const code = document.getElementById('voucherCode').value.trim();
            const message = document.getElementById('voucherMessage');
            if (!code) {
                message.textContent = 'Enter code first.';
                message.className = 'small mt-2 fw-bold text-danger';
                return;
            }

            try {
                const response = await fetch('ajax_redeem_voucher.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                    body: JSON.stringify({code})
                });
                const data = await response.json();
                message.textContent = data.message || data.error || '';
                message.className = 'small mt-2 fw-bold ' + (data.success ? 'text-success' : 'text-danger');
                if (data.success) {
                    setTimeout(() => window.location.href = 'index.php', 1200);
                }
            } catch (error) {
                message.textContent = error.message;
            }
        }
    </script>
</body>
</html>
