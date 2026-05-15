<?php
require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$brand_name = $website_title ?: 'TOEIC Simulator';
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$dashboard_link = $is_admin ? 'admin/index.php' : 'user/index.php';
$primary_cta = $is_logged_in ? $dashboard_link : 'register.php';
$secondary_cta = $is_logged_in ? $dashboard_link : 'login.php';
$practice_cta = $is_logged_in ? ($is_admin ? 'admin/index.php' : 'user/test_instructions.php?mode=prep') : 'register.php';
$full_simulation_cta = $is_logged_in ? ($is_admin ? 'admin/index.php' : 'user/test_instructions.php?mode=full') : 'register.php';
$pricing_cta = $is_logged_in ? ($is_admin ? 'admin/index.php' : 'user/buy_exam.php') : 'register.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($brand_name); ?> - TOEIC Listening and Reading Simulator</title>
    <?php echo getFaviconHTML(); ?>
    <meta name="description" content="TOEIC-only Listening and Reading simulator with timed practice, full test flow, score analytics, AI feedback, and proctoring-ready exam preparation.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/ruangguru-theme.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/landing.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="toeic-cockpit-page tc-public-page">
    <?php require_once 'includes/components/navbar.php'; ?>

    <main>
        <section id="overview" class="tc-shell tc-hero">
            <div>
                <div class="tc-eyebrow">TOEIC Score Cockpit</div>
                <h1 class="tc-title">
                    Latihan TOEIC dalam satu <span>ruang kendali skor.</span>
                </h1>
                <p class="tc-lead">
                    Sederhana: skor utama dan part lemah.<br>Aksi berikutnya langsung terlihat.
                </p>
                <div class="tc-actions">
                    <a href="<?php echo htmlspecialchars($primary_cta); ?>" class="tc-button">
                        <?php echo $is_logged_in ? 'Buka Dashboard' : 'Mulai Latihan'; ?>
                    </a>
                    <a href="#simulator" class="tc-button-outline">Lihat format tes</a>
                </div>
                <div class="tc-chip-row" aria-label="TOEIC highlights">
                    <span class="tc-chip">TOEIC-only</span>
                    <span class="tc-chip">200 soal</span>
                    <span class="tc-chip">Skor 10-990</span>
                </div>
            </div>

            <div class="tc-score-panel" aria-label="TOEIC score cockpit preview">
                <div class="tc-panel-top">
                    <div>
                        <div class="tc-panel-label">SCORE PREVIEW</div>
                        <div class="tc-panel-title">Target skor kerja: 800+</div>
                    </div>
                    <span class="tc-tag amber">Listening + Reading</span>
                </div>
                <div class="tc-score-main">
                    <div class="tc-score-dial">
                        <div>
                            <div class="tc-score-number">745</div>
                            <div class="tc-score-label">TOTAL SCORE</div>
                        </div>
                    </div>
                    <div class="tc-split-grid">
                        <div class="tc-split-card">
                            <strong>Listening <span>390</span></strong>
                            <div class="tc-bar"><span style="width: 78%;"></span></div>
                        </div>
                        <div class="tc-split-card">
                            <strong>Reading <span>355</span></strong>
                            <div class="tc-bar amber"><span style="width: 71%;"></span></div>
                        </div>
                        <div class="tc-split-card">
                            <strong>Next focus <span>Part 5</span></strong>
                            <div class="tc-bar amber"><span style="width: 54%;"></span></div>
                        </div>
                    </div>
                </div>
                <div class="tc-part-strip" aria-label="TOEIC part map">
                    <div class="tc-part active"><strong>1</strong><span>Photos</span></div>
                    <div class="tc-part active"><strong>2</strong><span>QA</span></div>
                    <div class="tc-part"><strong>3</strong><span>Talks</span></div>
                    <div class="tc-part"><strong>4</strong><span>Talks</span></div>
                    <div class="tc-part"><strong>5</strong><span>Grammar</span></div>
                    <div class="tc-part"><strong>6</strong><span>Text</span></div>
                    <div class="tc-part"><strong>7</strong><span>Reading</span></div>
                </div>
            </div>
        </section>

        <section class="category-bar" aria-label="TOEIC focus areas">
            <div class="container">
                <div class="category-bar-inner rg-fade-in">
                    <a href="#simulator" class="category-item">
                        <div class="category-icon toeic"><i class="fas fa-headphones"></i></div>
                        <span class="category-label">Listening</span>
                    </a>
                    <a href="#simulator" class="category-item">
                        <div class="category-icon" style="background:#EFF6FF; color:#2563EB;"><i class="fas fa-book-open"></i></div>
                        <span class="category-label">Reading</span>
                    </a>
                    <a href="#proof" class="category-item">
                        <div class="category-icon" style="background:#E0F7F3; color:#008F78;"><i class="fas fa-chart-bar"></i></div>
                        <span class="category-label">Analytics</span>
                    </a>
                    <a href="<?php echo htmlspecialchars($full_simulation_cta); ?>" class="category-item">
                        <div class="category-icon" style="background:#FFF0E6; color:#F26722;"><i class="fas fa-shield-alt"></i></div>
                        <span class="category-label">Full Test</span>
                    </a>
                    <a href="<?php echo htmlspecialchars($pricing_cta); ?>" class="category-item">
                        <div class="category-icon" style="background:#FFFBEB; color:#B45309;"><i class="fas fa-shopping-cart"></i></div>
                        <span class="category-label">Paket</span>
                    </a>
                </div>
            </div>
        </section>

        <section id="simulator" class="tests-section">
            <div class="container">
                <div class="section-header">
                    <span class="section-badge"><i class="fas fa-star"></i> Simulasi TOEIC</span>
                    <h2 class="section-title">Format tes yang tersedia</h2>
                    <p class="section-subtitle">Satu fokus: TOEIC Listening dan Reading dengan struktur latihan yang dekat dengan alur tes sebenarnya.</p>
                </div>

                <div class="test-cards">
                    <article class="test-card toeic rg-fade-in">
                        <div class="test-card-popular-badge"><i class="fas fa-fire"></i> TOEIC-only</div>
                        <div class="test-card-header">
                            <div class="test-card-icon"><i class="fas fa-briefcase"></i></div>
                            <div class="test-card-meta">
                                <span class="test-meta-pill"><i class="fas fa-clock"></i> 120 menit</span>
                                <span class="test-meta-pill"><i class="fas fa-layer-group"></i> 200 soal</span>
                            </div>
                        </div>

                        <h3 class="test-card-title">TOEIC Listening and Reading</h3>
                        <p class="test-card-subtitle">Full simulation and targeted practice</p>

                        <ul class="test-card-features">
                            <li><i class="fas fa-headphones"></i> <span>Listening Comprehension <strong>(100 soal)</strong></span></li>
                            <li><i class="fas fa-book-open"></i> <span>Reading Comprehension <strong>(100 soal)</strong></span></li>
                            <li><i class="fas fa-chart-line"></i> <span>Score analytics, AI review, and learning pathway</span></li>
                        </ul>

                        <div class="test-card-score">
                            <div class="score-info">
                                <span class="test-card-score-label">Score range</span>
                                <span class="test-card-score-value">10 - 990</span>
                            </div>
                            <div class="score-bar"><div class="score-bar-fill toeic-fill"></div></div>
                        </div>

                        <a href="<?php echo htmlspecialchars($full_simulation_cta); ?>" class="btn-test-card">
                            <i class="fas fa-play"></i> Mulai simulasi
                        </a>
                    </article>
                </div>
            </div>
        </section>

        <section id="why-toeic" class="features-section">
            <div class="container">
                <div class="section-header">
                    <span class="section-badge"><i class="fas fa-bullseye"></i> Kenapa TOEIC</span>
                    <h2 class="section-title">Dibuat untuk latihan yang terukur</h2>
                    <p class="section-subtitle">Setiap halaman student diarahkan ke aktivitas yang bisa dipakai: latihan, simulasi, pembayaran, hasil, dan rencana belajar.</p>
                </div>

                <div class="features-grid">
                    <article class="feature-item">
                        <div class="feature-icon"><i class="fas fa-clipboard-list"></i></div>
                        <h3 class="feature-title">Instruksi tes jelas</h3>
                        <p class="feature-desc">Siswa masuk dari instruksi, cek kamera bila diperlukan, lalu lanjut ke halaman pengerjaan TOEIC.</p>
                    </article>
                    <article class="feature-item">
                        <div class="feature-icon" style="background:linear-gradient(135deg,#2563EB,#60A5FA);"><i class="fas fa-chart-pie"></i></div>
                        <h3 class="feature-title">Analitik setelah ujian</h3>
                        <p class="feature-desc">Skor Listening, Reading, total, riwayat, dan part accuracy ditampilkan untuk keputusan belajar berikutnya.</p>
                    </article>
                    <article class="feature-item">
                        <div class="feature-icon" style="background:linear-gradient(135deg,#F59E0B,#FBBF24);"><i class="fas fa-road"></i></div>
                        <h3 class="feature-title">Learning pathway</h3>
                        <p class="feature-desc">Hasil simulasi bisa diteruskan ke AI analysis, syllabus, dan modul latihan yang lebih personal.</p>
                    </article>
                    <article class="feature-item">
                        <div class="feature-icon" style="background:linear-gradient(135deg,#F26722,#FB923C);"><i class="fas fa-gift"></i></div>
                        <h3 class="feature-title">Paket dan voucher</h3>
                        <p class="feature-desc">Alur aktivasi menjaga siswa tetap dekat dengan dashboard, pembayaran, dan status transaksi.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="proof" class="how-it-works-section">
            <div class="container">
                <div class="section-header">
                    <span class="section-badge"><i class="fas fa-route"></i> Alur siswa</span>
                    <h2 class="section-title">Dari paket sampai rencana belajar</h2>
                    <p class="section-subtitle">Semua route tetap TOEIC-only, dari aktivasi paket sampai laporan skor dan pathway belajar.</p>
                </div>

                <div class="steps-grid">
                    <div class="step-item">
                        <div class="step-number">1</div>
                        <div class="step-icon"><i class="fas fa-shopping-cart"></i></div>
                        <h3 class="step-title">Aktivasi paket</h3>
                        <p class="step-desc">Beli paket atau redeem voucher.</p>
                    </div>
                    <div class="step-connector"><i class="fas fa-chevron-right"></i></div>
                    <div class="step-item">
                        <div class="step-number">2</div>
                        <div class="step-icon"><i class="fas fa-shield-alt"></i></div>
                        <h3 class="step-title">Persiapan tes</h3>
                        <p class="step-desc">Baca instruksi dan cek kamera.</p>
                    </div>
                    <div class="step-connector"><i class="fas fa-chevron-right"></i></div>
                    <div class="step-item">
                        <div class="step-number">3</div>
                        <div class="step-icon"><i class="fas fa-pen"></i></div>
                        <h3 class="step-title">Kerjakan TOEIC</h3>
                        <p class="step-desc">Listening dan Reading dalam flow penuh.</p>
                    </div>
                    <div class="step-connector"><i class="fas fa-chevron-right"></i></div>
                    <div class="step-item">
                        <div class="step-number">4</div>
                        <div class="step-icon"><i class="fas fa-chart-line"></i></div>
                        <h3 class="step-title">Review hasil</h3>
                        <p class="step-desc">Buka result, AI analysis, dan pathway.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="cta-section">
            <div class="cta-box">
                <h2 class="cta-title">Siap mulai latihan TOEIC?</h2>
                <p class="cta-desc">Masuk ke dashboard untuk melihat paket aktif, latihan part tertentu, atau menjalankan simulasi penuh.</p>
                <div class="cta-buttons">
                    <a href="<?php echo htmlspecialchars($primary_cta); ?>" class="btn-hero-primary">
                        <i class="fas fa-play"></i><?php echo $is_logged_in ? 'Buka Dashboard' : 'Daftar Sekarang'; ?>
                    </a>
                    <a href="<?php echo htmlspecialchars($practice_cta); ?>" class="btn-hero-secondary">
                        <i class="fas fa-headphones"></i>Latihan part TOEIC
                    </a>
                </div>
            </div>
        </section>
    </main>

    <?php require_once 'includes/components/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
