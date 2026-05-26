<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/email_verification_helper.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_quality_helpers.php';
require_once '../includes/toeic_pricing_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

toeicRequireVerifiedEmail($conn);

$user_id = (int)$_SESSION['user_id'];
$hasAvailableToeicFullAccess = hasStrictTestCredit($conn, $user_id, 'toeic');
$hasAvailableToeicSwAccess = hasStrictTestCredit($conn, $user_id, 'toeic_sw');

$website_title = getWebsiteTitle();
$flash_messages = toeicConsumeFlashes();
$toeic_name = htmlspecialchars(getSiteSetting('name_toeic', 'TOEIC Listening & Reading'));
$toeic_price = number_format(toeicGetProductPrice('toeic', 'retail'), 0, ',', '.');
$toeic_sw_name = htmlspecialchars(getSiteSetting('name_toeic_sw', 'TOEIC Speaking & Writing'));
$toeic_sw_price = number_format(toeicGetProductPrice('toeic_sw', 'retail'), 0, ',', '.');
$features = json_decode(getSiteSetting('features_toeic', ''), true) ?: [
    'Full simulation 200 soal',
    'Prep mode Part 1-7',
    'Score report TOEIC',
    'Weakness map per part',
    'Secure audio dan proctoring ready',
];
$sw_features = json_decode(getSiteSetting('features_toeic_sw', ''), true) ?: [
    'Speaking 11 questions',
    'Writing 8 questions',
    'Score report Speaking 0-200',
    'Score report Writing 0-200',
    'AI-assisted transcript and feedback',
];
$checkout_ready = toeicCheckoutAvailable();
$payment_mode = toeicGetPaymentMode();
$manual_payment = toeicGetBankTransferSettings();
$checkout_label = $payment_mode === 'direct_bank' ? 'GoPay Manual' : 'Tripay';
$manual_payment_channel = htmlspecialchars($manual_payment['payment_channel'] ?? 'GOPAY');
$manual_payment_number = htmlspecialchars($manual_payment['bank_account_number'] ?? '+62856-4359-7072');
$manual_payment_holder = htmlspecialchars($manual_payment['bank_account_holder'] ?? 'Leonardus Bayu');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>Buy Package - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-user-page tc-package-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <a href="index.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height: 40px; font-size: 13px;">Dashboard</a>
        </div>
    </header>

    <main class="toeic-page-shell">
        <?php foreach ($flash_messages as $flash): ?>
            <div class="alert tc-page-alert <?php echo htmlspecialchars($flash['type']); ?> mb-4" role="alert">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endforeach; ?>

        <div class="mb-5">
            <span class="study-kicker">Paket TOEIC</span>
            <h1 class="display-5 mb-2">Aktifkan Simulasi Full</h1>
            <p class="lead text-muted">Pilih paket untuk membuka simulasi penuh dengan laporan skor TOEIC.</p>
        </div>

        <section class="tc-package-row mb-4">
            <div class="tc-package-body">
                <span class="study-kicker">TOEIC LR</span>
                <h2 class="h3 fw-bold mb-3"><?php echo $toeic_name; ?></h2>
                <p class="text-muted mb-4">Full simulation Listening & Reading 200 soal dengan laporan skor TOEIC 10-990.</p>
                <div class="tc-feature-line">
                    <span>200 Questions</span>
                    <span>120 Minutes</span>
                    <span>Score 10-990</span>
                    <?php foreach (array_slice($features, 0, 3) as $feature): ?>
                        <span><?php echo htmlspecialchars($feature); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="tc-package-action">
                <div class="study-kicker mb-2">Total Harga</div>
                <div class="tc-package-price h2 fw-bold mb-3">Rp <?php echo $toeic_price; ?></div>
                <div class="tc-payment-expectation mb-3">
                    Bayar via GoPay Manual: <?php echo $manual_payment_channel; ?> <?php echo $manual_payment_number; ?> a.n. <?php echo $manual_payment_holder; ?>
                </div>

                <?php if ($hasAvailableToeicFullAccess): ?>
                    <div class="alert alert-success border-0 small mb-3">
                        <i class="fas fa-check-circle me-1"></i> Anda punya kredit aktif.
                    </div>
                    <a href="test_instructions.php?mode=full" class="study-button w-100 mb-3">Mulai Simulasi Full</a>
                    <?php if ($checkout_ready): ?>
                        <a href="payment.php?exam_type=toeic" class="study-button study-button-secondary w-100">Beli Paket Lagi</a>
                    <?php endif; ?>
                <?php elseif ($checkout_ready): ?>
                    <a href="payment.php?exam_type=toeic" class="study-button w-100">Lanjut Bayar</a>
                <?php else: ?>
                    <div class="alert alert-warning border-0 small mb-0">
                        Checkout <?php echo htmlspecialchars($checkout_label); ?> belum lengkap. Gunakan voucher atau hubungi admin.
                    </div>
                <?php endif; ?>

                <div class="mt-4 pt-4 border-top">
                    <div class="study-kicker mb-3">Voucher TOEIC LR</div>
                    <div class="d-flex gap-2 voucher-redeem-row">
                        <input type="text" id="voucherCodeToeic" class="form-control" placeholder="OSGLI-33YRB" style="min-height: 48px;">
                        <button type="button" class="study-button py-2 px-3 voucher-icon-button" onclick="redeemVoucher('toeic')" aria-label="Tukar voucher TOEIC LR" style="min-height: 48px;"><i class="fas fa-gift"></i></button>
                    </div>
                    <div id="voucherMessageToeic" class="small mt-2"></div>
                </div>
            </div>
        </section>

        <section class="tc-package-row mb-5">
            <div class="tc-package-body">
                <span class="study-kicker">TOEIC SW</span>
                <h2 class="h3 fw-bold mb-3"><?php echo $toeic_sw_name; ?></h2>
                <p class="text-muted mb-4">Paket Speaking 11 soal dan Writing 8 soal dengan score report Speaking 0-200 dan Writing 0-200.</p>
                <div class="tc-feature-line">
                    <span>Speaking 11 Qs</span>
                    <span>Writing 8 Qs</span>
                    <span>Score /400</span>
                    <?php foreach (array_slice($sw_features, 0, 3) as $feature): ?>
                        <span><?php echo htmlspecialchars($feature); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="tc-package-action">
                <div class="study-kicker mb-2">Total Harga</div>
                <div class="tc-package-price h2 fw-bold mb-3">Rp <?php echo $toeic_sw_price; ?></div>
                <div class="tc-payment-expectation mb-3">
                    Bayar via GoPay Manual: <?php echo $manual_payment_channel; ?> <?php echo $manual_payment_number; ?> a.n. <?php echo $manual_payment_holder; ?>
                </div>

                <?php if ($hasAvailableToeicSwAccess): ?>
                    <div class="alert alert-success border-0 small mb-3">
                        <i class="fas fa-check-circle me-1"></i> Anda punya kredit SW aktif.
                    </div>
                    <a href="test_instructions.php?test_format=toeic_sw&mode=full" class="study-button w-100 mb-3">Mulai SW Full Simulation</a>
                    <a href="test_instructions.php?test_format=toeic_sw&mode=prep" class="study-button study-button-secondary w-100 mb-3">Mulai SW Practice</a>
                    <?php if ($checkout_ready): ?>
                        <a href="payment.php?exam_type=toeic_sw" class="study-button study-button-secondary w-100">Beli Paket SW Lagi</a>
                    <?php endif; ?>
                <?php elseif ($checkout_ready): ?>
                    <a href="payment.php?exam_type=toeic_sw" class="study-button w-100">Lanjut Bayar SW</a>
                <?php else: ?>
                    <div class="alert alert-warning border-0 small mb-0">
                        Checkout <?php echo htmlspecialchars($checkout_label); ?> belum lengkap. Gunakan voucher atau hubungi admin.
                    </div>
                <?php endif; ?>

                <div class="mt-4 pt-4 border-top">
                    <div class="study-kicker mb-3">Voucher TOEIC SW</div>
                    <div class="d-flex gap-2 voucher-redeem-row">
                        <input type="text" id="voucherCodeToeicSw" class="form-control" placeholder="OSGLI-33YRB" style="min-height: 48px;">
                        <button type="button" class="study-button py-2 px-3 voucher-icon-button" onclick="redeemVoucher('toeic_sw')" aria-label="Tukar voucher TOEIC SW" style="min-height: 48px;"><i class="fas fa-gift"></i></button>
                    </div>
                    <div id="voucherMessageToeicSw" class="small mt-2"></div>
                </div>
            </div>
        </section>
    </main>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        function normalizeVoucherCode(value) {
            return value.trim().replace(/[\u2010-\u2015\u2212]/g, '-').replace(/\s+/g, '').toUpperCase();
        }

        async function redeemVoucher(expectedExamType) {
            const isSw = expectedExamType === 'toeic_sw';
            const input = document.getElementById(isSw ? 'voucherCodeToeicSw' : 'voucherCodeToeic');
            const code = normalizeVoucherCode(input.value);
            const message = document.getElementById(isSw ? 'voucherMessageToeicSw' : 'voucherMessageToeic');
            input.value = code;

            if (!code) {
                message.className = 'small mt-2 text-danger fw-bold';
                message.textContent = 'Masukkan kode voucher dulu.';
                return;
            }

            message.className = 'small mt-2 text-muted';
            message.textContent = 'Memproses voucher...';

            try {
                const response = await fetch('ajax_redeem_voucher.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({code, expected_exam_type: expectedExamType})
                });
                const data = await response.json();
                const isSuccess = Boolean(data.success);

                message.className = 'small mt-2 fw-bold ' + (isSuccess ? 'text-success' : 'text-danger');
                message.textContent = data.message || data.error || 'Voucher gagal diproses.';

                if (isSuccess) {
                    setTimeout(() => window.location.href = 'index.php', 1200);
                }
            } catch (error) {
                message.className = 'small mt-2 text-danger fw-bold';
                message.textContent = 'Voucher gagal diproses. Coba lagi.';
            }
        }
    </script>
</body>
</html>
