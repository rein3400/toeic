<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

if (!FEATURE_TOEIC) {
    $_SESSION['error'] = 'TOEIC sedang tidak tersedia.';
    header("Location: index.php");
    exit();
}

$website_title = getWebsiteTitle();
$mode = (($_GET['mode'] ?? 'full') === 'prep') ? 'prep' : 'full';
$has_full_credit = hasStrictTestCredit($conn, (int)$_SESSION['user_id'], 'toeic');
$full_credit_count = countStrictTestCredits($conn, (int)$_SESSION['user_id'], 'toeic');
$full_test_parts = [
    ['label' => 'Listening', 'detail' => 'Part 1-4 · 100 soal · 45 menit'],
    ['label' => 'Reading', 'detail' => 'Part 5-7 · 100 soal · 75 menit'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_instructions'])) {
    $postedMode = (($_POST['mode'] ?? 'full') === 'prep') ? 'prep' : 'full';

    $_SESSION['instructions_confirmed_toeic'] = time();
    $_SESSION['practice_mode_toeic'] = $postedMode === 'prep' ? 1 : 0;
    $_SESSION['practice_part_toeic'] = null;

    header("Location: test_toeic.php?start_new=1&mode=" . urlencode($postedMode));
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instruksi TOEIC - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="../assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="css/mobile-responsive.css" rel="stylesheet">
    <style>
        body { color: #10233d; }
        .hero {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #fff;
            padding: 4rem 0 3rem;
            position: relative;
            overflow: hidden;
        }
        .hero::after {
            content: '';
            position: absolute;
            inset: auto -10% -45% auto;
            width: 28rem;
            height: 28rem;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }
        .instruction-card, .mode-card, .summary-card { border-radius: 24px; }
        .mode-card.active {
            border-color: #f59e0b;
            box-shadow: 0 18px 50px rgba(245,158,11,0.16);
        }
        .mode-toggle input { display: none; }
        .mode-toggle label { cursor: pointer; }
        .checklist li {
            margin-bottom: 0.8rem;
            color: #42526b;
        }
        .checklist i {
            color: #d97706;
            width: 1.1rem;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 0.9rem;
            border-radius: 999px;
            background: rgba(255,255,255,0.18);
            font-size: 0.9rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <section class="hero">
        <div class="container">
            <a href="index.php" class="text-white text-decoration-none d-inline-flex align-items-center gap-2 mb-4">
                <i class="fas fa-arrow-left"></i>
                <span>Kembali ke dashboard TOEIC</span>
            </a>
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <span class="pill mb-3"><i class="fas fa-briefcase"></i> TOEIC Listening & Reading</span>
                    <h1 class="display-6 fw-bold mb-3">
                        <?php echo $mode === 'prep' ? 'Instruksi TOEIC Practice Simulation' : 'Instruksi TOEIC Full Simulation'; ?>
                    </h1>
                    <p class="mb-0 fs-5" style="max-width: 52rem;">
                        <?php if ($mode === 'prep'): ?>
                            Practice simulation menjalankan TOEIC penuh 200 soal dengan urutan section yang sama seperti full simulation, tetapi tanpa proctoring dan tanpa memakai paket test aktif.
                        <?php else: ?>
                            Full simulation menjalankan TOEIC penuh 200 soal dengan proctoring aktif dan memakai satu paket test TOEIC yang masih aktif.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-lg-4">
                    <div class="instruction-card p-4">
                        <div class="fw-bold text-uppercase text-muted small mb-2">Akses Produk</div>
                        <div class="fs-5 fw-bold mb-2">
                            <?php echo $mode === 'prep' ? 'Practice Always Available' : ($has_full_credit ? 'Active TOEIC Package Detected' : 'Active TOEIC Package Required'); ?>
                        </div>
                        <div class="small text-muted mb-2">Paket test aktif: <strong><?php echo $full_credit_count; ?></strong></div>
                        <p class="mb-0 text-muted">
                            <?php if ($mode === 'prep'): ?>
                                Practice simulation tidak mengonsumsi paket test TOEIC.
                            <?php elseif ($has_full_credit): ?>
                                Akun ini memiliki paket TOEIC aktif untuk full simulation.
                            <?php else: ?>
                                Anda perlu membeli satu paket TOEIC aktif sebelum menjalankan full simulation.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <main class="container py-5">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="instruction-card p-4 p-lg-5 mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                        <div>
                            <h2 class="h4 fw-bold mb-2">Format yang akan Anda kerjakan</h2>
                            <p class="text-muted mb-0">Kedua mode memakai TOEIC penuh 200 soal. Perbedaannya hanya pada proctoring dan pemakaian paket test aktif.</p>
                        </div>
                    </div>

                    <div class="row g-3 mb-4 mode-toggle">
                        <div class="col-md-6">
                            <input type="radio" id="mode_full" name="mode_picker" value="full" <?php echo $mode !== 'prep' ? 'checked' : ''; ?>>
                            <label for="mode_full" class="mode-card d-block p-4 h-100">
                                <div class="small text-uppercase text-muted fw-semibold mb-2">Package + Proctor</div>
                                <div class="h5 fw-bold mb-2">Full Simulation</div>
                                <p class="text-muted mb-0">Listening 45 menit lalu Reading 75 menit. Menggunakan proctoring dan satu paket TOEIC aktif.</p>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <input type="radio" id="mode_prep" name="mode_picker" value="prep" <?php echo $mode === 'prep' ? 'checked' : ''; ?>>
                            <label for="mode_prep" class="mode-card d-block p-4 h-100">
                                <div class="small text-uppercase text-muted fw-semibold mb-2">No Package + No Proctor</div>
                                <div class="h5 fw-bold mb-2">Practice Simulation</div>
                                <p class="text-muted mb-0">Menjalankan TOEIC penuh 200 soal tanpa proctoring dan tanpa menghabiskan paket aktif.</p>
                            </label>
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($full_test_parts as $item): ?>
                            <div class="col-md-6">
                                <div class="summary-card p-4 h-100">
                                    <div class="fw-bold mb-2"><?php echo htmlspecialchars($item['label']); ?></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($item['detail']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="instruction-card p-4 p-lg-5">
                    <h2 class="h4 fw-bold mb-4">Aturan sebelum memulai</h2>
                    <ul class="list-unstyled checklist mb-0">
                        <li><i class="fas fa-headphones"></i> Gunakan headphone atau earphone agar audio Listening terdengar jelas.</li>
                        <li><i class="fas fa-stopwatch"></i> Timer berjalan terus selama section aktif. Tidak ada pause saat ujian berlangsung.</li>
                        <li><i class="fas fa-arrow-right-arrow-left"></i> Full simulation dan practice sama-sama memakai urutan resmi TOEIC: Listening lebih dulu, lalu Reading.</li>
                        <?php if ($mode === 'prep'): ?>
                            <li><i class="fas fa-route"></i> Practice simulation berjalan tanpa proctoring, tetapi tampilan, timer, dan alur soal tetap sama seperti full simulation.</li>
                        <?php else: ?>
                            <li><i class="fas fa-shield-alt"></i> Full simulation memakai proctoring. Jangan pindah tab, resize window berlebihan, atau mencoba replay audio lewat cara lain.</li>
                        <?php endif; ?>
                        <li><i class="fas fa-clipboard-check"></i> Kedua mode menyimpan jawaban per soal dan menghasilkan ringkasan hasil TOEIC setelah ujian selesai.</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="instruction-card p-4 p-lg-5 position-sticky" style="top: 2rem;">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Ready Check</div>
                    <h2 class="h4 fw-bold mb-3">Mulai TOEIC Anda</h2>
                    <p class="text-muted mb-4">Konfirmasi mode yang ingin Anda jalankan.</p>

                    <form method="post">
                        <input type="hidden" name="mode" id="modeField" value="<?php echo htmlspecialchars($mode); ?>">
                        <button type="submit" name="confirm_instructions" id="startButton" class="btn btn-warning w-100 py-3 fw-bold">
                            <?php echo $mode === 'prep' ? 'Mulai Practice Simulation' : 'Mulai Full Simulation'; ?>
                        </button>
                    </form>
                    <?php if (!$has_full_credit): ?>
                        <div class="mt-3 small text-muted" id="fullCreditHint">
                            Full simulation membutuhkan satu paket TOEIC aktif. Practice simulation tetap bisa dijalankan tanpa paket aktif.
                        </div>
                    <?php endif; ?>

                    <div class="mt-4 text-muted small">
                        <?php if ($mode === 'prep'): ?>
                            Target saat ini: <strong>TOEIC Practice Simulation 200 soal tanpa proctoring</strong>.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const modeRadios = document.querySelectorAll('input[name="mode_picker"]');
        const modeField = document.getElementById('modeField');
        const startButton = document.getElementById('startButton');
        const fullCreditHint = document.getElementById('fullCreditHint');
        const hasFullCredit = <?php echo $has_full_credit ? 'true' : 'false'; ?>;

        function refreshModeCards() {
            document.querySelectorAll('.mode-card').forEach((card) => card.classList.remove('active'));
            const checkedMode = document.querySelector('input[name="mode_picker"]:checked');
            if (checkedMode) {
                checkedMode.nextElementSibling.classList.add('active');
                modeField.value = checkedMode.value;
                if (startButton) {
                    startButton.textContent = checkedMode.value === 'prep' ? 'Mulai Practice Simulation' : 'Mulai Full Simulation';
                }
                if (fullCreditHint) {
                    fullCreditHint.style.display = (!hasFullCredit && checkedMode.value === 'full') ? 'block' : 'none';
                }
            }
        }

        modeRadios.forEach((radio) => radio.addEventListener('change', refreshModeCards));
        refreshModeCards();
    </script>
</body>
</html>
