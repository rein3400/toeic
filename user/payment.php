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
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>Pembayaran TOEIC - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
</head>
<body class="toeic-redesign-body toeic-student-page">
    <main class="toeic-page-shell">
        <div class="toeic-page-header">
            <div>
                <div class="toeic-kicker mb-3">Payment</div>
                <h1 class="display-6 mb-3">Choose a payment method for your TOEIC simulator package.</h1>
                <p class="toeic-subcopy">Select the payment route that matches your workflow, then return to the TOEIC dashboard once the transaction is confirmed.</p>
            </div>
            <a href="buy_exam.php" class="btn btn-outline-secondary">Back</a>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="toeic-panel p-4 p-lg-5 h-100">
                    <div class="toeic-eyebrow mb-3">Payment methods</div>
                    <h2 class="h3 mb-4">Proceed with Tripay or redeem a TOEIC voucher.</h2>
                    <div class="row g-3">
                        <?php foreach ([
                            ['QRIS', 'QRIS', 'fa-qrcode'],
                            ['OVO', 'OVO', 'fa-wallet'],
                            ['DANA', 'DANA', 'fa-money-bill'],
                            ['SHOPEEPAY', 'ShopeePay', 'fa-bag-shopping'],
                            ['BCAVA', 'BCA Virtual Account', 'fa-building-columns'],
                            ['BNIVA', 'BNI Virtual Account', 'fa-building-columns'],
                            ['BRIVA', 'BRI Virtual Account', 'fa-building-columns'],
                            ['MANDIRIVA', 'Mandiri Virtual Account', 'fa-building-columns'],
                        ] as $index => $method): ?>
                            <div class="col-md-6">
                                <label class="d-block p-4 h-100 toeic-surface payment-method <?php echo $index === 0 ? 'payment-method-active' : ''; ?>">
                                    <input type="radio" name="payment_method" value="<?php echo $method[0]; ?>" <?php echo $index === 0 ? 'checked' : ''; ?> hidden>
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($method[1]); ?></div>
                                            <div class="small text-muted">Pembayaran TOEIC via <?php echo htmlspecialchars($method[1]); ?></div>
                                        </div>
                                        <i class="fas <?php echo $method[2]; ?> text-warning"></i>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 pt-4 border-top" style="border-color: rgba(23, 38, 63, 0.08) !important;">
                        <div class="toeic-eyebrow mb-3">Redeem voucher</div>
                        <h3 class="h4 mb-3">Use a TOEIC voucher instead.</h3>
                        <div class="input-group">
                            <input type="text" id="voucherCode" class="form-control" placeholder="Masukkan kode voucher">
                            <button class="btn btn-outline-warning" type="button" onclick="redeemVoucher()">Redeem</button>
                        </div>
                        <div id="voucherMessage" class="small mt-3 text-muted"></div>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="toeic-band h-100">
                    <div class="toeic-eyebrow mb-3">Summary</div>
                    <h2 class="display-6 mb-2"><?php echo $price_formatted; ?></h2>
                    <p class="toeic-copy mb-4"><?php echo htmlspecialchars($product_name); ?></p>
                    <ul class="toeic-list-check mb-4">
                        <li>Full simulation 200 soal</li>
                        <li>Practice simulation</li>
                        <li>Score report and analytics</li>
                    </ul>
                    <?php if ($tripay_ready): ?>
                        <button id="payButton" class="btn btn-warning w-100" onclick="createTransaction()">Pay Now</button>
                    <?php else: ?>
                        <div class="alert alert-warning rounded-4 border-0 mb-0">Payment gateway belum dikonfigurasi.</div>
                    <?php endif; ?>
                    <div id="paymentMessage" class="small mt-3 text-muted"></div>
                </section>
            </div>
        </div>
    </main>

    <style>
        .payment-method {
            cursor: pointer;
            transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }

        .payment-method-active {
            border-color: rgba(209, 139, 31, 0.5) !important;
            box-shadow: 0 18px 34px rgba(209, 139, 31, 0.14);
            transform: translateY(-1px);
        }
    </style>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const labels = document.querySelectorAll('.payment-method');
        labels.forEach((label) => {
            label.addEventListener('click', () => {
                labels.forEach((item) => item.classList.remove('payment-method-active'));
                label.classList.add('payment-method-active');
            });
        });

        async function createTransaction() {
            const button = document.getElementById('payButton');
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked').value;
            const message = document.getElementById('paymentMessage');
            button.disabled = true;
            button.textContent = 'Memproses...';
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
                message.textContent = data.message || 'Gagal membuat transaksi.';
            } catch (error) {
                message.textContent = error.message;
            } finally {
                button.disabled = false;
                button.textContent = 'Pay Now';
            }
        }

        async function redeemVoucher() {
            const code = document.getElementById('voucherCode').value.trim();
            const message = document.getElementById('voucherMessage');
            if (!code) {
                message.textContent = 'Kode voucher tidak boleh kosong.';
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
