<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

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
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <style>
        body { background: #0f172a; color: #fff; font-family: var(--rg-font); }
        .shell { max-width: 1080px; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .card-panel {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 32px;
            box-shadow: 0 26px 80px rgba(0,0,0,0.22);
            backdrop-filter: blur(12px);
        }
        .method-label {
            display: block;
            padding: 1rem 1.1rem;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            cursor: pointer;
        }
        .method-label.active {
            border-color: rgba(245,158,11,0.65);
            background: rgba(245,158,11,0.12);
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <div class="small text-uppercase text-white-50 fw-semibold">Pembayaran Produk</div>
                <h1 class="h3 fw-bold mb-0"><?php echo htmlspecialchars($product_name); ?></h1>
            </div>
            <a href="buy_exam.php" class="btn btn-outline-light rounded-pill px-4">Kembali</a>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card-panel p-4 p-lg-5">
                    <h2 class="h5 fw-bold mb-4">Pilih metode pembayaran</h2>
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
                                <label class="method-label <?php echo $index === 0 ? 'active' : ''; ?>">
                                    <input type="radio" name="payment_method" value="<?php echo $method[0]; ?>" <?php echo $index === 0 ? 'checked' : ''; ?> hidden>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($method[1]); ?></div>
                                            <div class="small text-white-50">Pembayaran TOEIC via <?php echo htmlspecialchars($method[1]); ?></div>
                                        </div>
                                        <i class="fas <?php echo $method[2]; ?> text-warning"></i>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4">
                        <h3 class="h6 fw-bold mb-3">Redeem voucher TOEIC</h3>
                        <div class="input-group">
                            <input type="text" id="voucherCode" class="form-control form-control-lg" placeholder="Masukkan kode voucher" style="border-radius:18px 0 0 18px;">
                            <button class="btn btn-outline-warning btn-lg" type="button" onclick="redeemVoucher()" style="border-radius:0 18px 18px 0;">Redeem</button>
                        </div>
                        <div id="voucherMessage" class="small mt-3 text-white-50"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card-panel p-4 p-lg-5">
                    <div class="small text-uppercase text-white-50 fw-semibold mb-2">Ringkasan</div>
                    <div class="display-6 fw-bold mb-1"><?php echo $price_formatted; ?></div>
                    <div class="text-white-50 mb-4"><?php echo htmlspecialchars($product_name); ?></div>
                    <ul class="list-unstyled text-white-50 small mb-4">
                        <li class="mb-2"><i class="fas fa-check text-warning me-2"></i> Full simulation 200 soal</li>
                        <li class="mb-2"><i class="fas fa-check text-warning me-2"></i> Prep mode Part 1-7</li>
                        <li class="mb-2"><i class="fas fa-check text-warning me-2"></i> Score report dan analytics</li>
                    </ul>
                    <?php if ($tripay_ready): ?>
                        <button id="payButton" class="btn btn-warning w-100 py-3 fw-bold rounded-pill" onclick="createTransaction()">Bayar Sekarang</button>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">Payment gateway belum dikonfigurasi.</div>
                    <?php endif; ?>
                    <div id="paymentMessage" class="small mt-3 text-white-50"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const labels = document.querySelectorAll('.method-label');
        labels.forEach((label) => {
            label.addEventListener('click', () => {
                labels.forEach((item) => item.classList.remove('active'));
                label.classList.add('active');
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
                button.textContent = 'Bayar Sekarang';
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
