<?php
require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - TOEIC Simulation</title>
    <?php echo getFaviconHTML(); ?>
    <meta name="description" content="TOEIC-only simulation platform dengan full test 200 soal, practice mode Part 1-7, secure audio, dan score report TOEIC.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="user/css/mobile-responsive.css" rel="stylesheet">
    <style>
        body { color: #10233d; }
        .hero {
            background: linear-gradient(135deg, #0f1f3d, #1f3a61 55%, #3b5f98);
            color: #fff;
            padding: 5rem 0 4rem;
            position: relative;
            overflow: hidden;
        }
        .hero::after {
            content: '';
            position: absolute;
            right: -8rem;
            top: -5rem;
            width: 24rem;
            height: 24rem;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }
        .section-card { position: relative; }
        .hero-format-card {
            color: var(--toeic-ink, #14243f);
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(255,252,245,0.99));
        }
        .hero-format-card .h3,
        .hero-format-card strong {
            color: var(--toeic-ink, #14243f);
        }
        .hero-format-card .text-muted {
            color: var(--toeic-muted, #61718b) !important;
        }
        .feature-item {
            display: flex;
            gap: 0.9rem;
            padding: 0.85rem 0;
            border-bottom: 1px solid #eef3f8;
        }
        .feature-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <?php require_once 'includes/components/navbar.php'; ?>

    <section class="hero">
        <div class="container position-relative" style="z-index:1;">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <div class="text-uppercase small fw-semibold opacity-75 mb-3">TOEIC-only Product</div>
                    <h1 class="display-4 fw-bold mb-3">Simulasi TOEIC yang fokus, realistis, dan siap dipakai latihan serius.</h1>
                    <p class="fs-5 opacity-75 mb-4">
                        Full simulation 200 soal, practice mode Part 1-7, secure audio, proctoring-ready flow, dan score report yang konsisten dengan struktur TOEIC Listening & Reading.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if ($is_logged_in): ?>
                            <a href="<?php echo $is_admin ? 'admin/index.php' : 'user/index.php'; ?>" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold">Masuk ke Dashboard</a>
                        <?php else: ?>
                            <a href="register.php" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold">Daftar & Mulai TOEIC</a>
                            <a href="login.php" class="btn btn-outline-light btn-lg rounded-pill px-4 fw-bold">Login</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="section-card toeic-panel toeic-grid-lines hero-format-card p-4 p-lg-5">
                        <div class="small text-uppercase text-muted fw-semibold mb-2">Format Resmi</div>
                        <div class="h3 fw-bold mb-3">TOEIC Listening & Reading</div>
                        <div class="feature-item"><i class="fas fa-headphones text-warning mt-1"></i><div><strong>Listening</strong><div class="text-muted small">Part 1-4 · 100 soal · 45 menit</div></div></div>
                        <div class="feature-item"><i class="fas fa-book-open text-warning mt-1"></i><div><strong>Reading</strong><div class="text-muted small">Part 5-7 · 100 soal · 75 menit</div></div></div>
                        <div class="feature-item"><i class="fas fa-chart-line text-warning mt-1"></i><div><strong>Score Range</strong><div class="text-muted small">10 - 990 dengan breakdown per section</div></div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <main class="container py-5">
        <section id="tests" class="section-card toeic-panel p-4 p-lg-5 mb-4">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Use Cases</div>
                    <h2 class="display-6 fw-bold mb-3">Dua mode utama untuk satu tujuan: score TOEIC yang lebih tinggi.</h2>
                    <p class="text-muted mb-0">
                        Produk ini tidak lagi bercabang ke format lain. Semua alur, analytics, dan admin panel difokuskan untuk TOEIC.
                    </p>
                </div>
                <div class="col-lg-6">
                    <div class="feature-item"><i class="fas fa-clipboard-check text-warning mt-1"></i><div><strong>Full Simulation</strong><div class="text-muted small">Alur section resmi dengan timer TOEIC penuh dan score report akhir.</div></div></div>
                    <div class="feature-item"><i class="fas fa-bullseye text-warning mt-1"></i><div><strong>Practice Mode</strong><div class="text-muted small">Drill khusus Part 1-7 dengan ringkasan akurasi per part.</div></div></div>
                    <div class="feature-item"><i class="fas fa-volume-up text-warning mt-1"></i><div><strong>Secure Audio</strong><div class="text-muted small">Streaming audio TOEIC terkontrol untuk sesi listening yang disiplin.</div></div></div>
                    <div class="feature-item"><i class="fas fa-layer-group text-warning mt-1"></i><div><strong>Weakness Map</strong><div class="text-muted small">Lihat area Part 1-7 yang paling membutuhkan penguatan.</div></div></div>
                </div>
            </div>
        </section>

        <section class="section-card toeic-panel p-4 p-lg-5">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Start Now</div>
                    <h2 class="h1 fw-bold mb-3">Siap pindah dari latihan generik ke simulator TOEIC yang benar-benar fokus?</h2>
                    <p class="text-muted mb-0">
                        Masuk ke dashboard untuk mulai full simulation, practice mode per part, atau membeli paket TOEIC jika akun Anda belum aktif.
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <?php if ($is_logged_in): ?>
                        <a href="<?php echo $is_admin ? 'admin/index.php' : 'user/index.php'; ?>" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold">Buka Dashboard</a>
                    <?php else: ?>
                        <a href="register.php" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold">Buat Akun TOEIC</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <?php require_once 'includes/components/footer.php'; ?>
</body>
</html>
