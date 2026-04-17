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
</head>
<body>
    <main class="toeic-page-shell">
        <div class="toeic-page-header">
            <div>
                <div class="toeic-kicker mb-3">The TOEIC tests</div>
                <h1 class="display-6 mb-3">Activate your TOEIC listening and reading simulator.</h1>
                <p class="toeic-subcopy">One package opens the full simulation route, while practice simulation, analytics, and reporting stay connected to the same TOEIC-only experience.</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>

        <section class="toeic-hero-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <div class="toeic-kicker mb-3">TOEIC package</div>
                    <h2 class="display-6 text-white mb-3"><?php echo $toeic_name; ?></h2>
                    <p class="text-white-50 mb-4">
                        Aligned to the TOEIC Listening and Reading structure, this package unlocks the full monitored simulation, result reporting, and TOEIC analytics workflow.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="toeic-pill text-bg-warning">200 Questions</span>
                        <span class="toeic-pill toeic-pill-soft">120 Minutes</span>
                        <span class="toeic-pill toeic-pill-soft">Score 10-990</span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="toeic-band h-100">
                        <div class="toeic-eyebrow mb-3">Price</div>
                        <div class="display-4 mb-3">Rp <?php echo $toeic_price; ?></div>
                        <?php if ($tripay_ready): ?>
                            <a href="payment.php?exam_type=toeic" class="btn btn-warning w-100">Proceed to Payment</a>
                        <?php else: ?>
                            <div class="alert alert-warning rounded-4 border-0 mb-0">Payment gateway belum siap. Anda tetap bisa redeem voucher TOEIC di bawah.</div>
                        <?php endif; ?>

                        <div class="mt-4 pt-4 border-top" style="border-color: rgba(23, 38, 63, 0.08) !important;">
                            <div class="toeic-eyebrow mb-3">Redeem voucher</div>
                            <div class="input-group">
                                <input type="text" id="voucherCode" class="form-control" placeholder="Masukkan kode voucher">
                                <button class="btn btn-outline-warning" type="button" onclick="redeemVoucher()">Redeem</button>
                            </div>
                            <div id="voucherMessage" class="small mt-3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="toeic-card-grid">
            <?php foreach ($features as $feature): ?>
                <div class="toeic-display-panel toeic-surface h-100">
                    <div class="toeic-eyebrow mb-3">Included</div>
                    <h3 class="h4 mb-0"><?php echo htmlspecialchars($feature); ?></h3>
                </div>
            <?php endforeach; ?>
        </section>
    </main>

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
