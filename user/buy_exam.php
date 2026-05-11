<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_quality_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$hasAvailableToeicFullAccess = false;
$hasAvailableToeicSwAccess = hasStrictTestCredit($conn, $user_id, 'toeic_sw');

if (hasTestCredit($conn, $user_id, 'toeic')) {
    $hasCompletedRealToeicSession = false;

    try {
        if (checkTableExists($conn, 'toeic_test_sessions')) {
            $conditions = ["user_id = ?"];

            if (checkColumnExists($conn, 'toeic_test_sessions', 'practice_mode')) {
                $conditions[] = "(practice_mode = 0 OR practice_mode IS NULL)";
            }

            if (checkColumnExists($conn, 'toeic_test_sessions', 'status')) {
                $conditions[] = "status = 'completed'";
            }

            $sql = "SELECT test_session FROM toeic_test_sessions WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
            $checkStmt = $conn->prepare($sql);
            if ($checkStmt) {
                $checkStmt->bind_param('i', $user_id);
                $checkStmt->execute();
                $hasCompletedRealToeicSession = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();
            }
        }
    } catch (\Throwable $e) {
        $hasCompletedRealToeicSession = false;
    }

    $hasAvailableToeicFullAccess = !$hasCompletedRealToeicSession;
}

$website_title = getWebsiteTitle();
$flash_messages = toeicConsumeFlashes();
$toeic_name = htmlspecialchars(getSiteSetting('name_toeic', 'TOEIC Listening & Reading'));
$toeic_price = number_format((int)getSiteSetting('price_toeic', '175000'), 0, ',', '.');
$toeic_sw_name = htmlspecialchars(getSiteSetting('name_toeic_sw', 'TOEIC Speaking & Writing'));
$toeic_sw_price = number_format((int)getSiteSetting('price_toeic_sw', '175000'), 0, ',', '.');
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
$tripay_ready = !empty(TRIPAY_API_KEY) && !empty(TRIPAY_PRIVATE_KEY) && !empty(TRIPAY_MERCHANT_CODE);
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

        <section class="study-card p-4 p-lg-5 mb-4 text-white" style="background: linear-gradient(135deg, var(--academy-blue), var(--focus-blue)); border:none;">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <span class="study-kicker" style="color:var(--sunbeam-yellow) !important;">Special Offer</span>
                    <h2 class="display-4 text-white mb-3"><?php echo $toeic_name; ?></h2>
                    <p class="text-white-50 mb-4" style="font-size: 1.1rem;">
                        Akses 200 soal, mode full dengan proctoring, dan konversi skor TOEIC Listening & Reading.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-white text-dark rounded-pill px-3 py-2 fw-bold">200 Questions</span>
                        <span class="badge bg-white text-dark rounded-pill px-3 py-2 fw-bold">120 Minutes</span>
                        <span class="badge bg-white text-dark rounded-pill px-3 py-2 fw-bold">Score 10-990</span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="study-card text-center bg-white border-0 shadow-lg p-4">
                        <div class="study-kicker">Total Harga</div>
                        <div class="display-3 fw-bold mb-4" style="color:var(--focus-blue);">Rp <?php echo $toeic_price; ?></div>

                        <?php if ($hasAvailableToeicFullAccess): ?>
                            <div class="alert alert-success border-0 small mb-3">
                                <i class="fas fa-check-circle me-1"></i> Anda punya kredit aktif.
                            </div>
                            <a href="test_instructions.php?mode=full" class="study-button w-100 mb-3">Mulai Simulasi Full</a>
                            <?php if ($tripay_ready): ?>
                                <a href="payment.php?exam_type=toeic" class="study-button study-button-secondary w-100">Beli Paket Lagi</a>
                            <?php endif; ?>
                        <?php elseif ($tripay_ready): ?>
                            <a href="payment.php?exam_type=toeic" class="study-button w-100">Lanjut Bayar</a>
                        <?php else: ?>
                            <div class="alert alert-warning border-0 small mb-0">
                                Payment gateway belum aktif. Gunakan voucher atau hubungi admin untuk aktivasi manual.
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 pt-4 border-top">
                            <div class="study-kicker mb-3">Atau Pakai Voucher</div>
                            <div class="d-flex gap-2 voucher-redeem-row">
                                <input type="text" id="voucherCode" class="form-control" placeholder="OSGLI-33YRB" style="min-height: 48px;">
                                <button type="button" class="study-button py-2 px-3 voucher-icon-button" onclick="redeemVoucher()" aria-label="Tukar voucher" style="min-height: 48px;"><i class="fas fa-gift"></i></button>
                            </div>
                            <div id="voucherMessage" class="small mt-2"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="study-card p-4 p-lg-5 mb-5">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <span class="study-kicker">Separate SW Package</span>
                    <h2 class="display-5 mb-3"><?php echo $toeic_sw_name; ?></h2>
                    <p class="text-muted mb-4" style="font-size: 1.05rem;">
                        Paket terpisah untuk simulasi Speaking 11 soal dan Writing 8 soal, dengan score report Speaking 0-200 dan Writing 0-200.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-bold">Speaking 11 Qs</span>
                        <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-bold">Writing 8 Qs</span>
                        <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-bold">Score /400</span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="study-card text-center bg-white border shadow-sm p-4">
                        <div class="study-kicker">Total Harga</div>
                        <div class="display-4 fw-bold mb-4" style="color:var(--focus-blue);">Rp <?php echo $toeic_sw_price; ?></div>

                        <?php if ($hasAvailableToeicSwAccess): ?>
                            <div class="alert alert-success border-0 small mb-3">
                                <i class="fas fa-check-circle me-1"></i> Anda punya kredit SW aktif.
                            </div>
                            <a href="test_instructions.php?test_format=toeic_sw&mode=full" class="study-button w-100 mb-3">Mulai TOEIC SW</a>
                            <?php if ($tripay_ready): ?>
                                <a href="payment.php?exam_type=toeic_sw" class="study-button study-button-secondary w-100">Beli Paket SW Lagi</a>
                            <?php endif; ?>
                        <?php elseif ($tripay_ready): ?>
                            <a href="payment.php?exam_type=toeic_sw" class="study-button w-100">Lanjut Bayar SW</a>
                        <?php else: ?>
                            <div class="alert alert-warning border-0 small mb-0">
                                Payment gateway belum aktif. Gunakan voucher atau hubungi admin untuk aktivasi manual.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <h3 class="h4 mb-4 fw-bold">What's Included?</h3>
        <div class="row g-4">
            <?php foreach ($features as $feature): ?>
                <div class="col-md-4">
                    <div class="study-card h-100 py-3 d-flex align-items-center gap-3">
                        <div class="avatar-circle flex-shrink-0" style="width:40px; height:40px; background:rgba(72,127,181,0.1) !important; border:none;">
                            <i class="fas fa-check text-primary"></i>
                        </div>
                        <div class="fw-bold"><?php echo htmlspecialchars($feature); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php foreach ($sw_features as $feature): ?>
                <div class="col-md-4">
                    <div class="study-card h-100 py-3 d-flex align-items-center gap-3">
                        <div class="avatar-circle flex-shrink-0" style="width:40px; height:40px; background:rgba(72,127,181,0.1) !important; border:none;">
                            <i class="fas fa-microphone text-primary"></i>
                        </div>
                        <div class="fw-bold"><?php echo htmlspecialchars($feature); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        function normalizeVoucherCode(value) {
            return value.trim().replace(/[\u2010-\u2015\u2212]/g, '-').replace(/\s+/g, '').toUpperCase();
        }

        async function redeemVoucher() {
            const input = document.getElementById('voucherCode');
            const code = normalizeVoucherCode(input.value);
            const message = document.getElementById('voucherMessage');
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
                    body: JSON.stringify({code})
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
