<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();
$toeic_name = htmlspecialchars(getSiteSetting('name_toeic', 'TOEIC Listening & Reading'));
$toeic_price = number_format((int)getSiteSetting('price_toeic', '175000'), 0, ',', '.');
$features = json_decode(getSiteSetting('features_toeic', ''), true) ?: [
    'Full simulation 200 soal',
    'Prep mode Part 1-7',
    'Score report TOEIC',
    'Weakness map per part',
    'Secure audio dan proctoring ready',
];
$tripay_ready = !empty(TRIPAY_API_KEY) && !empty(TRIPAY_PRIVATE_KEY) && !empty(TRIPAY_MERCHANT_CODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>Paket TOEIC - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <style>
        body { color: #10233d; }
        .shell { max-width: 980px; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .hero, .product-card { position: relative; border-radius: 30px; }
        .hero { padding: 2.5rem; margin-bottom: 1.5rem; }
        .product-card { padding: 2rem; }
        .feature-row {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eef3f8;
        }
        .feature-row:last-child { border-bottom: none; }
        .voucher-panel {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(16, 35, 61, 0.12);
        }
        .voucher-input .form-control,
        .voucher-input .btn {
            min-height: 3.25rem;
        }
        .voucher-input .form-control {
            border-radius: 18px 0 0 18px;
            border-color: rgba(16, 35, 61, 0.14);
        }
        .voucher-input .btn {
            border-radius: 0 18px 18px 0;
        }
        #voucherMessage {
            min-height: 1.25rem;
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <div class="small text-uppercase text-muted fw-semibold">TOEIC Package</div>
                <h1 class="h3 fw-bold mb-0">Aktifkan simulator TOEIC Anda</h1>
            </div>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">Kembali ke Dashboard</a>
        </div>

        <section class="hero toeic-panel toeic-grid-lines">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <h2 class="display-6 fw-bold mb-3"><?php echo $toeic_name; ?></h2>
                    <p class="text-muted mb-4">
                        Produk ini sudah TOEIC-only. Satu paket memberi akses ke full simulation Listening & Reading, prep mode Part 1-7, score report, dan analytics TOEIC.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge rounded-pill text-bg-warning px-3 py-2">200 soal</span>
                        <span class="badge rounded-pill text-bg-light border px-3 py-2">120 menit</span>
                        <span class="badge rounded-pill text-bg-light border px-3 py-2">Score 10-990</span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="product-card toeic-surface">
                        <div class="small text-uppercase text-muted fw-semibold mb-2">Harga Paket</div>
                        <div class="display-6 fw-bold mb-3">Rp <?php echo $toeic_price; ?></div>
                        <?php if ($tripay_ready): ?>
                            <a href="payment.php?exam_type=toeic" class="btn btn-warning w-100 py-3 fw-bold rounded-pill">Lanjut ke Pembayaran</a>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">Payment gateway belum siap. Anda tetap bisa redeem voucher TOEIC di bawah.</div>
                        <?php endif; ?>

                        <div class="voucher-panel">
                            <div class="small text-uppercase text-muted fw-semibold mb-2">Redeem Voucher TOEIC</div>
                            <div class="input-group voucher-input">
                                <input type="text" id="voucherCode" class="form-control form-control-lg" placeholder="Masukkan kode voucher">
                                <button class="btn btn-outline-warning btn-lg" type="button" onclick="redeemVoucher()">Redeem</button>
                            </div>
                            <div id="voucherMessage" class="small mt-3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="product-card toeic-panel">
            <h2 class="h4 fw-bold mb-3">Yang Anda dapatkan</h2>
            <?php foreach ($features as $feature): ?>
                <div class="feature-row">
                    <i class="fas fa-check-circle text-warning"></i>
                    <span><?php echo htmlspecialchars($feature); ?></span>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        async function redeemVoucher() {
            const code = document.getElementById('voucherCode').value.trim();
            const message = document.getElementById('voucherMessage');

            if (!code) {
                message.className = 'small mt-3 text-danger';
                message.textContent = 'Kode voucher tidak boleh kosong.';
                return;
            }

            message.className = 'small mt-3 text-muted';
            message.textContent = 'Memeriksa voucher...';

            try {
                const response = await fetch('ajax_redeem_voucher.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({code})
                });
                const data = await response.json();
                const isSuccess = Boolean(data.success);

                message.className = 'small mt-3 ' + (isSuccess ? 'text-success' : 'text-danger');
                message.textContent = data.message || data.error || 'Voucher tidak dapat diproses.';

                if (isSuccess) {
                    setTimeout(() => window.location.href = 'index.php', 1200);
                }
            } catch (error) {
                message.className = 'small mt-3 text-danger';
                message.textContent = error.message || 'Terjadi kesalahan saat redeem voucher.';
            }
        }
    </script>
</body>
</html>
