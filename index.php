<?php
require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$dashboard_link = $is_admin ? 'admin/index.php' : 'user/index.php';
$primary_cta = $is_logged_in ? $dashboard_link : 'register.php';
$secondary_cta = $is_logged_in ? 'user/test_instructions.php?mode=full' : 'login.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - TOEIC Test Pro</title>
    <?php echo getFaviconHTML(); ?>
    <meta name="description" content="Platform simulasi TOEIC Listening dan Reading dengan practice by part, full simulation, analytics, AI analysis, secure audio, dan proctoring-ready test flow.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-estudyme-home.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="toeic-estudy-home">
    <header class="est-home-header">
        <nav class="est-home-nav" aria-label="Primary navigation">
            <a class="est-brand" href="index.php" aria-label="<?php echo htmlspecialchars($website_title); ?> home">
                <span class="est-brand-mark">
                    <?php if (!empty($website_logo) && file_exists($website_logo)): ?>
                        <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="">
                    <?php else: ?>
                        <i class="fas fa-briefcase"></i>
                    <?php endif; ?>
                </span>
                <span class="est-brand-text"><?php echo htmlspecialchars($website_title); ?></span>
            </a>

            <div class="est-nav-links" aria-label="Homepage sections">
                <a href="#practice">Practice</a>
                <a href="#ai-analysis">AI Analysis</a>
                <a href="#exam-modes">Exam Modes</a>
                <a href="#inside">Features</a>
                <a href="#faq">FAQ</a>
            </div>

            <div class="est-nav-actions">
                <?php if ($is_logged_in): ?>
                    <a class="est-link-button" href="<?php echo $dashboard_link; ?>"><?php echo $is_admin ? 'Admin Console' : 'Dashboard'; ?></a>
                    <a class="est-app-button" href="user/test_instructions.php?mode=full">Start Test</a>
                <?php else: ?>
                    <a class="est-link-button" href="login.php">Sign In</a>
                    <a class="est-app-button" href="register.php">Get Started</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main>
        <section class="est-hero" id="overview">
            <div class="est-hero-inner">
                <div class="est-hero-copy">
                    <p class="est-kicker">TOEIC Listening & Reading simulator</p>
                    <h1>Free Online <span>TOEIC Test 2026</span></h1>
                    <p class="est-hero-text">
                        Latihan TOEIC yang fokus ke format asli: practice per part, full simulation 200 soal, score report, secure audio, analytics, dan AI study analysis dalam satu flow yang rapi.
                    </p>
                    <div class="est-hero-actions">
                        <a class="est-primary-button" href="<?php echo htmlspecialchars($primary_cta); ?>">
                            <?php echo $is_logged_in ? 'Open Dashboard' : 'Start Practice Now'; ?>
                        </a>
                        <a class="est-secondary-button" href="<?php echo htmlspecialchars($secondary_cta); ?>">
                            <?php echo $is_logged_in ? 'Full Simulation' : 'Already have account'; ?>
                        </a>
                    </div>
                </div>

                <div class="est-hero-visual" aria-label="TOEIC app preview">
                    <div class="est-orbit-card est-orbit-rating">
                        <span class="est-icon-pill"><i class="fas fa-star"></i></span>
                        <strong>990</strong>
                        <small>Score target</small>
                    </div>
                    <div class="est-orbit-card est-orbit-download">
                        <span class="est-icon-pill"><i class="fas fa-headphones"></i></span>
                        <strong>200</strong>
                        <small>Questions</small>
                    </div>
                    <div class="est-hero-orbit">
                        <div class="est-phone-frame">
                            <div class="est-phone-top">
                                <span></span>
                                <strong>TOEIC Pro</strong>
                            </div>
                            <div class="est-phone-score">
                                <small>Latest simulation</small>
                                <strong>785</strong>
                                <span>Working Proficiency Plus</span>
                            </div>
                            <div class="est-progress-stack">
                                <div><span>Listening</span><b style="--value: 84%"></b></div>
                                <div><span>Reading</span><b style="--value: 76%"></b></div>
                                <div><span>Part 7 Focus</span><b style="--value: 62%"></b></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="est-stats-strip" aria-label="Platform highlights">
            <article>
                <strong>7</strong>
                <span>TOEIC parts</span>
            </article>
            <article>
                <strong>120</strong>
                <span>minutes full test</span>
            </article>
            <article>
                <strong>10-990</strong>
                <span>scaled score range</span>
            </article>
        </section>

        <section class="est-practice-section" id="practice">
            <div class="est-section-heading">
                <p class="est-kicker">Practice test library</p>
                <h2>Choose your TOEIC learning part</h2>
                <p>Strukturnya mengikuti TOEIC Listening dan Reading. Tidak ada Speaking/Writing agar produk tetap TOEIC-only sesuai scope.</p>
            </div>

            <div class="est-skill-tabs" aria-label="TOEIC sections">
                <span class="active"><i class="fas fa-volume-up"></i> Listening</span>
                <span><i class="fas fa-book-open"></i> Reading</span>
            </div>

            <div class="est-topic-grid">
                <a class="est-topic-card" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">
                    <span class="est-topic-icon coral"><i class="fas fa-image"></i></span>
                    <strong>Photos</strong>
                    <small>Part 1</small>
                    <div class="est-topic-meta"><span>6 questions</span><span>Practice</span></div>
                </a>
                <a class="est-topic-card" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">
                    <span class="est-topic-icon blue"><i class="fas fa-comment-dots"></i></span>
                    <strong>Question - Response</strong>
                    <small>Part 2</small>
                    <div class="est-topic-meta"><span>25 questions</span><span>Practice</span></div>
                </a>
                <a class="est-topic-card" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">
                    <span class="est-topic-icon gold"><i class="fas fa-comments"></i></span>
                    <strong>Short Conversations</strong>
                    <small>Part 3</small>
                    <div class="est-topic-meta"><span>39 questions</span><span>Practice</span></div>
                </a>
                <a class="est-topic-card" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">
                    <span class="est-topic-icon navy"><i class="fas fa-bullhorn"></i></span>
                    <strong>Short Talks</strong>
                    <small>Part 4</small>
                    <div class="est-topic-meta"><span>30 questions</span><span>Practice</span></div>
                </a>
                <a class="est-topic-card" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">
                    <span class="est-topic-icon green"><i class="fas fa-pen-nib"></i></span>
                    <strong>Incomplete Sentences</strong>
                    <small>Part 5</small>
                    <div class="est-topic-meta"><span>30 questions</span><span>Practice</span></div>
                </a>
                <a class="est-topic-card" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">
                    <span class="est-topic-icon coral"><i class="fas fa-align-left"></i></span>
                    <strong>Text Completion</strong>
                    <small>Part 6</small>
                    <div class="est-topic-meta"><span>16 questions</span><span>Practice</span></div>
                </a>
                <a class="est-topic-card wide" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">
                    <span class="est-topic-icon blue"><i class="fas fa-newspaper"></i></span>
                    <strong>Reading Comprehension</strong>
                    <small>Part 7</small>
                    <div class="est-topic-meta"><span>54 questions</span><span>Practice</span></div>
                </a>
            </div>
        </section>

        <section class="est-ai-section" id="ai-analysis">
            <div class="est-ai-visual">
                <div class="est-ai-badge">AI Study Report</div>
                <div class="est-ai-card writing">
                    <span>Part accuracy</span>
                    <strong>Listening + Reading breakdown</strong>
                    <p>Temukan part yang paling banyak kehilangan poin.</p>
                </div>
                <div class="est-ai-card speaking">
                    <span>Next focus</span>
                    <strong>Personalized study priority</strong>
                    <p>Ubah hasil simulation menjadi pathway belajar berikutnya.</p>
                </div>
            </div>
            <div class="est-ai-copy">
                <p class="est-kicker">AI Analysis</p>
                <h2>Explore your TOEIC score report instantly</h2>
                <p>
                    Setelah test selesai, learner bisa membaca skor Listening, Reading, total scaled score, part breakdown, dan rekomendasi belajar tanpa menebak-nebak.
                </p>
                <a class="est-primary-button" href="<?php echo $is_logged_in ? 'user/analytics.php' : 'register.php'; ?>">Check My TOEIC Progress</a>
            </div>
        </section>

        <section class="est-exam-section" id="exam-modes">
            <div class="est-exam-copy">
                <p class="est-kicker">Get ready for TOEIC exams</p>
                <h2>Take computer-based exams and measure your TOEIC score.</h2>
                <p>Mode dibuat ringkas seperti landing app modern: learner langsung memilih latihan cepat, full simulation, atau test proctored.</p>
            </div>
            <div class="est-exam-tabs">
                <a href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>">Practice Mode</a>
                <a class="active" href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=full' : 'register.php'; ?>">Full Test</a>
                <a href="<?php echo $is_logged_in ? 'user/camera_setup.php' : 'register.php'; ?>">Exam Simulation</a>
            </div>
        </section>

        <section class="est-inside-section" id="inside">
            <div class="est-section-heading">
                <p class="est-kicker">What's inside?</p>
                <h2>Everything learners need after every answer</h2>
            </div>
            <div class="est-inside-grid">
                <article class="est-function-card">
                    <span><i class="fas fa-layer-group"></i></span>
                    <strong>All-in-one TOEIC platform</strong>
                    <p>Practice questions, test session, score report, analytics, and study pathway live in one product flow.</p>
                </article>
                <article class="est-function-card">
                    <span><i class="fas fa-chart-pie"></i></span>
                    <strong>Result statistics</strong>
                    <p>See correct, incorrect, unanswered, section score, and part-level accuracy after completion.</p>
                </article>
                <article class="est-function-card">
                    <span><i class="fas fa-headphones-alt"></i></span>
                    <strong>Secure audio</strong>
                    <p>Listening playback is controlled so the simulation experience stays close to a serious test environment.</p>
                </article>
                <article class="est-function-card">
                    <span><i class="fas fa-route"></i></span>
                    <strong>Learning path</strong>
                    <p>AI analysis can turn weak parts into recommended learning modules and focused practice cycles.</p>
                </article>
            </div>
        </section>

        <section class="est-review-section">
            <div class="est-review-copy">
                <p class="est-kicker">Learner confidence</p>
                <h2>Practice feels simpler when the product shows the next step clearly.</h2>
                <p>Bangun ritme latihan TOEIC yang lebih terarah, dari practice singkat sampai simulasi penuh dengan hasil yang mudah dipahami.</p>
            </div>
            <div class="est-review-wall">
                <article>
                    <strong>“Part-by-part practice makes the target easier to see.”</strong>
                    <span>TOEIC learner</span>
                </article>
                <article>
                    <strong>“The report tells me what to fix before the next simulation.”</strong>
                    <span>Student dashboard user</span>
                </article>
            </div>
        </section>

        <section class="est-download-section" id="faq">
            <div>
                <p class="est-kicker">Start from one clean flow</p>
                <h2>Ready to practice TOEIC Listening and Reading?</h2>
                <p>Mulai dari akun learner, masuk dashboard, lalu pilih practice atau full simulation sesuai kebutuhan.</p>
            </div>
            <a class="est-primary-button" href="<?php echo htmlspecialchars($primary_cta); ?>">
                <?php echo $is_logged_in ? 'Continue Learning' : 'Create Free Account'; ?>
            </a>
        </section>
    </main>

    <footer class="est-footer">
        <div>
            <strong><?php echo htmlspecialchars($website_title); ?></strong>
            <span>TOEIC Listening & Reading simulation platform.</span>
            <p class="est-footer-disclaimer">TOEIC is a registered trademark of Educational Testing Service (ETS). This web is not affiliated with or endorsed by Educational Testing Service.</p>
        </div>
        <nav aria-label="Footer navigation">
            <a href="#practice">Practice</a>
            <a href="#exam-modes">Exam</a>
            <a href="login.php">Sign In</a>
        </nav>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
