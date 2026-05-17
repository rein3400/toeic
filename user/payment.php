<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_quality_helpers.php';
require_once '../includes/toeic_pricing_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$exam_type = $_GET['exam_type'] ?? 'toeic';
$products = [
    'toeic' => [
        'name' => getSiteSetting('name_toeic', 'TOEIC Listening & Reading'),
        'price' => toeicGetProductPrice('toeic', 'retail'),
        'summary' => 'Akses full Listening & Reading dengan laporan skor 10-990',
    ],
    'toeic_sw' => [
        'name' => getSiteSetting('name_toeic_sw', 'TOEIC Speaking & Writing'),
        'price' => toeicGetProductPrice('toeic_sw', 'retail'),
        'summary' => 'Akses full Speaking & Writing dengan skor Speaking 0-200 dan Writing 0-200',
    ],
];
if (!isset($products[$exam_type])) {
    toeicRedirectWithFlash('buy_exam.php', 'info', 'Produk TOEIC yang diminta tidak tersedia.');
}

$website_title = getWebsiteTitle();
$product = $products[$exam_type];
$product_name = $product['name'];
$price_value = $product['price'];
$price_formatted = 'Rp ' . number_format($price_value, 0, ',', '.');
$payment_mode = toeicGetPaymentMode();
$tripay_ready = toeicPaymentUsesTripay();
$direct_bank_ready = toeicIsDirectBankConfigured();
$bank_transfer = toeicGetBankTransferSettings();
$manual_payment_label = $bank_transfer['display_label'] ?? 'GoPay Manual';
$manual_payment_channel = $bank_transfer['payment_channel'] ?? 'GOPAY';
$manual_payment_number = $bank_transfer['bank_account_number'] ?? '+62856-4359-7072';
$manual_payment_holder = $bank_transfer['bank_account_holder'] ?? 'Leonardus Bayu';
$checkout_ready = $payment_mode === 'direct_bank' ? $direct_bank_ready : $tripay_ready;
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
            <a href="buy_exam.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">Kembali</a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <div class="mb-5">
            <span class="study-kicker">Checkout</span>
            <h1 class="display-5 mb-2">Metode Pembayaran</h1>
            <p class="lead text-muted">Selesaikan pembelian untuk membuka simulasi <?php echo htmlspecialchars($product_name); ?>.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <section class="study-card mb-4">
                    <span class="study-kicker">Selection</span>
                    <h2 class="h4 mb-4">Pilih Metode</h2>

                    <?php if ($payment_mode === 'direct_bank'): ?>
                        <div class="payment-option active">
                            <input type="radio" name="payment_method" value="BANK_TRANSFER" checked class="d-none">
                            <div class="d-flex gap-3 align-items-start">
                                <div class="avatar-circle flex-shrink-0" style="width:50px; height:50px; background:rgba(16,185,129,0.1) !important; border:none;">
                                    <i class="fas fa-wallet text-success h4 mb-0"></i>
                                </div>
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($manual_payment_label); ?></div>
                                    <div class="small text-muted">Invoice dibuat di sistem, lalu pembayaran diselesaikan ke nomor GoPay admin.</div>
                                    <?php if ($direct_bank_ready): ?>
                                        <div class="mt-3 small">
                                            <div class="fw-bold"><?php echo htmlspecialchars($manual_payment_channel); ?></div>
                                            <div><?php echo htmlspecialchars($manual_payment_number); ?> a.n. <?php echo htmlspecialchars($manual_payment_holder); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
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
                    <?php endif; ?>

                    <div class="mt-5 pt-4 border-top">
                        <div class="study-kicker mb-3">Voucher <?php echo $exam_type === 'toeic_sw' ? 'TOEIC SW' : 'TOEIC LR'; ?></div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <input type="text" id="voucherCode" class="form-control" placeholder="OSGLI-33YRB">
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="study-button w-100" onclick="redeemVoucher()">Tukar</button>
                            </div>
                        </div>
                        <div id="voucherMessage" class="small mt-2 fw-bold"></div>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="study-card h-100">
                    <span class="study-kicker">Order</span>
                    <h2 class="h4 mb-4">Ringkasan</h2>

                    <div class="p-4 rounded-4 mb-4" style="background: rgba(72, 127, 181, 0.05);">
                        <div class="fw-bold h5 mb-1" style="color:var(--focus-blue);"><?php echo htmlspecialchars($product_name); ?></div>
                        <div class="text-muted small mb-4"><?php echo htmlspecialchars($product['summary']); ?></div>

                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <span class="fw-bold">Total</span>
                            <span class="h4 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo $price_formatted; ?></span>
                        </div>
                    </div>

                    <ul class="list-unstyled mb-5">
                        <li class="mb-2 small d-flex gap-2 align-items-center"><i class="fas fa-check-circle text-success"></i> Aktivasi instan</li>
                        <li class="mb-2 small d-flex gap-2 align-items-center"><i class="fas fa-check-circle text-success"></i> <?php echo $payment_mode === 'direct_bank' ? 'Bayar via GoPay Manual' : 'Transaksi aman'; ?></li>
                        <li class="mb-2 small d-flex gap-2 align-items-center"><i class="fas fa-check-circle text-success"></i> Berlaku untuk 1 sesi</li>
                    </ul>

                    <?php if ($checkout_ready): ?>
                        <button id="payButton" class="study-button w-100" onclick="createTransaction()">Selesaikan Pembelian</button>
                    <?php else: ?>
                        <div class="alert alert-warning border-0 small text-center">
                            <?php echo $payment_mode === 'direct_bank' ? 'Nomor GoPay Manual belum lengkap.' : 'Payment gateway belum aktif di environment ini.'; ?> Gunakan voucher atau hubungi admin.
                        </div>
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
            const message = document.getElementById('paymentMessage');
            button.disabled = true;
            button.textContent = 'Memproses...';
            message.textContent = '';

            try {
                const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
                const paymentMethod = selectedMethod ? selectedMethod.value : 'BANK_TRANSFER';
                const response = await fetch('../api/create_transaction.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({exam_type: <?php echo json_encode($exam_type); ?>, payment_method: paymentMethod})
                });
                const data = await response.json();
                if (data.status === 'success') {
                    window.location.href = data.payment_url || data.redirect_url || 'index.php';
                    return;
                }
                message.textContent = data.message || 'Transaksi gagal dibuat.';
            } catch (error) {
                message.textContent = error.message;
            } finally {
                button.disabled = false;
                button.textContent = 'Selesaikan Pembelian';
            }
        }

        function normalizeVoucherCode(value) {
            return value.trim().replace(/[\u2010-\u2015\u2212]/g, '-').replace(/\s+/g, '').toUpperCase();
        }

        async function redeemVoucher() {
            const input = document.getElementById('voucherCode');
            const code = normalizeVoucherCode(input.value);
            const message = document.getElementById('voucherMessage');
            input.value = code;
            if (!code) {
                message.textContent = 'Masukkan kode voucher dulu.';
                message.className = 'small mt-2 fw-bold text-danger';
                return;
            }

            try {
                const response = await fetch('ajax_redeem_voucher.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                    body: JSON.stringify({code, expected_exam_type: <?php echo json_encode($exam_type); ?>})
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
