<?php
require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$dashboard_link = $is_admin ? 'admin/index.php' : 'user/index.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - TOEIC Simulation</title>
    <?php echo getFaviconHTML(); ?>
    <meta name="description" content="TOEIC-only simulation platform dengan full simulation 200 soal, practice mode, secure audio, analytics, dan score report Listening & Reading.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/ruangguru-theme.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css')); ?>" rel="stylesheet">
</head>
<body class="toeic-redesign-body toeic-public-page">
    <?php require_once 'includes/components/navbar.php'; ?>

    <main class="toeic-page-shell">
        <section class="toeic-hero-card p-4 p-lg-5 mb-4" id="overview">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <div class="toeic-kicker mb-3">Powering global communication</div>
                    <h1 class="toeic-headline text-white">TOEIC global English skills simulator.</h1>
                    <p class="toeic-subcopy text-white-50 mb-4">
                        Standardized practice for serious preparation. Build real-world readiness with a TOEIC-only platform designed around the Listening and Reading flow, controlled audio delivery, score reporting, and repeatable practice.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if ($is_logged_in): ?>
                            <a href="<?php echo $dashboard_link; ?>" class="btn btn-warning btn-lg">Open Dashboard</a>
                        <?php else: ?>
                            <a href="register.php" class="btn btn-warning btn-lg">Get Started</a>
                            <a href="login.php" class="btn btn-outline-light btn-lg">Sign In</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="toeic-band h-100">
                        <div class="toeic-eyebrow mb-3">TOEIC listening and reading test</div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="toeic-stat h-100">
                                    <div class="toeic-stat-value">200</div>
                                    <div class="toeic-stat-label">Questions</div>
                                    <div class="small text-muted mt-2">Part 1-7 in one complete simulator flow.</div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="toeic-stat h-100">
                                    <div class="toeic-stat-value">120</div>
                                    <div class="toeic-stat-label">Minutes</div>
                                    <div class="small text-muted mt-2">45 minutes Listening and 75 minutes Reading.</div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="toeic-stat h-100">
                                    <div class="toeic-stat-value">10-990</div>
                                    <div class="toeic-stat-label">Score Range</div>
                                    <div class="small text-muted mt-2">Listening, Reading, and total score report.</div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="toeic-stat h-100">
                                    <div class="toeic-stat-value">2</div>
                                    <div class="toeic-stat-label">Modes</div>
                                    <div class="small text-muted mt-2">Full Simulation and Practice Simulation.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="toeic-proof-grid mb-4" id="why-toeic">
            <div class="toeic-display-panel toeic-panel">
                <div class="toeic-eyebrow mb-3">Trusted test architecture</div>
                <h2 class="h1 mb-3">Why TOEIC?</h2>
                <p class="toeic-copy mb-4">
                    In today's business environment, the future belongs to up-to-date, real-world communication. This simulator is built to mirror that requirement with a focused TOEIC practice environment, disciplined section timing, and a score report you can review after every attempt.
                </p>
                <ul class="toeic-list-check">
                    <li>Workplace-focused Listening and Reading practice.</li>
                    <li>Official section order with a score-driven review loop.</li>
                    <li>Repeatable simulator sessions for targeted improvement.</li>
                </ul>
            </div>
            <div class="toeic-display-panel toeic-panel">
                <div class="toeic-eyebrow mb-3">Built around the TOEIC format</div>
                <h2 class="h2 mb-3">Learn more about the learners who use focused TOEIC preparation.</h2>
                <div class="toeic-feature-list">
                    <div class="toeic-feature-row">
                        <div class="toeic-feature-icon"><i class="fas fa-headphones"></i></div>
                        <div>
                            <strong>Secure Listening Delivery</strong>
                            <div class="small text-muted">Controlled playback for Listening stimuli and audio completion tracking.</div>
                        </div>
                    </div>
                    <div class="toeic-feature-row">
                        <div class="toeic-feature-icon"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <strong>Score Report and Analytics</strong>
                            <div class="small text-muted">Review total score, section score, and part-level accuracy after each completed session.</div>
                        </div>
                    </div>
                    <div class="toeic-feature-row">
                        <div class="toeic-feature-icon"><i class="fas fa-shield-alt"></i></div>
                        <div>
                            <strong>Proctoring-Ready Full Test</strong>
                            <div class="small text-muted">Run a full simulation flow with pre-check, monitoring, and disqualification handling.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="toeic-display-panel toeic-panel">
                <div class="toeic-eyebrow mb-3">Practice with intent</div>
                <h2 class="h2 mb-3">Why standardized English proficiency assessment matters for serious preparation.</h2>
                <p class="toeic-copy mb-0">
                    TOEIC preparation is stronger when the structure stays consistent. This product removes unrelated formats and keeps every public, student, and reporting flow anchored to TOEIC Listening and Reading only.
                </p>
            </div>
        </section>

        <section class="toeic-panel p-4 p-lg-5 mb-4" id="proof">
            <div class="toeic-page-header mb-4">
                <div>
                    <div class="toeic-eyebrow mb-3">Trusted by focused learners</div>
                    <h2 class="display-6 mb-3">A score report designed for decision-making after every session.</h2>
                    <p class="toeic-copy mb-0">Move from generic drills to a simulator that exposes Listening and Reading performance, part-level gaps, and the next best action after every attempt.</p>
                </div>
            </div>
            <div class="toeic-card-grid">
                <div class="toeic-display-panel toeic-surface">
                    <div class="toeic-eyebrow mb-3">Performance proof</div>
                    <h3 class="h3 mb-3">Latest full simulation breakdown</h3>
                    <p class="toeic-copy mb-0">Track how many answers were correct in each part and compare where your time and attention should go next.</p>
                </div>
                <div class="toeic-display-panel toeic-surface">
                    <div class="toeic-eyebrow mb-3">Score visibility</div>
                    <h3 class="h3 mb-3">Listening, Reading, and total score</h3>
                    <p class="toeic-copy mb-0">View section-level scaled scores together with a full report page and follow-up analytics flow.</p>
                </div>
                <div class="toeic-display-panel toeic-surface">
                    <div class="toeic-eyebrow mb-3">Practice continuity</div>
                    <h3 class="h3 mb-3">Resume sessions and review results</h3>
                    <p class="toeic-copy mb-0">Keep progress alive with active-session handling, practice summaries, and repeatable part-by-part improvement loops.</p>
                </div>
            </div>
        </section>

        <section class="toeic-panel p-4 p-lg-5 mb-4" id="simulator">
            <div class="toeic-page-header mb-4">
                <div>
                    <div class="toeic-eyebrow mb-3">The TOEIC tests</div>
                    <h2 class="display-6 mb-3">Choose the practice flow that matches the score you want to build.</h2>
                    <p class="toeic-copy mb-0">Aligned with the TOEIC Listening and Reading structure, these modules let you prepare through a full monitored simulation, a practice-first simulation, and review-led progress tools.</p>
                </div>
            </div>
            <div class="toeic-card-grid">
                <div class="toeic-display-panel toeic-surface h-100">
                    <div class="toeic-eyebrow mb-3">Primary module</div>
                    <h3 class="h3 mb-3">TOEIC Listening and Reading Test</h3>
                    <p class="toeic-copy mb-4">Assesses intermediate to advanced listening and reading skills needed in the workplace through a complete 200-question simulator.</p>
                    <a href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=full' : 'register.php'; ?>" class="btn btn-warning">Learn More</a>
                </div>
                <div class="toeic-display-panel toeic-surface h-100">
                    <div class="toeic-eyebrow mb-3">Practice route</div>
                    <h3 class="h3 mb-3">Practice Simulation</h3>
                    <p class="toeic-copy mb-4">Run the same full TOEIC sequence without proctoring while using one active package for each new practice attempt.</p>
                    <a href="<?php echo $is_logged_in ? 'user/test_instructions.php?mode=prep' : 'register.php'; ?>" class="btn btn-outline-warning">Learn More</a>
                </div>
                <div class="toeic-display-panel toeic-surface h-100">
                    <div class="toeic-eyebrow mb-3">Progress system</div>
                    <h3 class="h3 mb-3">Analytics and AI Analysis</h3>
                    <p class="toeic-copy mb-4">Use trend data, part breakdown, and AI-generated study focus to turn each report into the next improvement cycle.</p>
                    <a href="<?php echo $is_logged_in ? 'user/analytics.php' : 'login.php'; ?>" class="btn btn-outline-secondary">Learn More</a>
                </div>
            </div>
        </section>

        <section class="toeic-band mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <div class="toeic-eyebrow mb-3">Explore the simulator</div>
                    <h2 class="display-6 mb-3">Build real workforce effectiveness through focused TOEIC practice.</h2>
                    <p class="toeic-copy mb-0">From account creation to result review, every surface in this product is oriented around one goal: make TOEIC practice more structured, measurable, and repeatable.</p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <?php if ($is_logged_in): ?>
                        <a href="<?php echo $dashboard_link; ?>" class="btn btn-warning btn-lg">Go to Dashboard</a>
                    <?php else: ?>
                        <a href="register.php" class="btn btn-warning btn-lg">Create TOEIC Account</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <?php require_once 'includes/components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
