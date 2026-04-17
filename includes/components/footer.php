<?php
$footerYear = date('Y');
?>
<footer class="rg-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <h5>
                    <?php if (!empty($website_logo) && file_exists($website_logo)): ?>
                        <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo" style="height: 28px; margin-right: 8px; filter: brightness(0) invert(1);">
                    <?php else: ?>
                        <i class="fas fa-briefcase me-2"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($website_title); ?>
                </h5>
                <p class="mb-3">
                    Powering focused TOEIC preparation through a simulator built around the Listening and Reading format, official timing flow, score reporting, and structured practice.
                </p>
                <div class="d-flex gap-3">
                    <a href="index.php#overview">Overview</a>
                    <a href="index.php#why-toeic">Why TOEIC</a>
                    <a href="index.php#simulator">The TOEIC Tests</a>
                </div>
            </div>
            <div class="col-lg-2 col-md-6 col-6">
                <h5>Explore</h5>
                <ul class="rg-footer-links">
                    <li><a href="index.php#overview">Global English Skills</a></li>
                    <li><a href="index.php#proof">Score Report</a></li>
                    <li><a href="index.php#simulator">Learning Modules</a></li>
                </ul>
            </div>
            <div class="col-lg-2 col-md-6 col-6">
                <h5>Platform</h5>
                <ul class="rg-footer-links">
                    <li><a href="register.php">Create Account</a></li>
                    <li><a href="login.php">Sign In</a></li>
                    <li><a href="user/index.php">Student Dashboard</a></li>
                </ul>
            </div>
            <div class="col-lg-2 col-md-6 col-6">
                <h5>Simulator</h5>
                <ul class="rg-footer-links">
                    <li><a href="user/test_instructions.php?mode=full">Full Simulation</a></li>
                    <li><a href="user/test_instructions.php?mode=prep">Practice Simulation</a></li>
                    <li><a href="user/analytics.php">Analytics</a></li>
                </ul>
            </div>
            <div class="col-lg-2 col-md-6">
                <h5>Contact</h5>
                <ul class="rg-footer-links">
                    <li>support@osgli.com</li>
                    <li>TOEIC-only simulator</li>
                    <li>Listening and Reading focus</li>
                </ul>
            </div>
        </div>
        <div class="rg-footer-bottom">
            <p class="mb-0">&copy; <?php echo $footerYear; ?> <?php echo htmlspecialchars($website_title); ?>. All rights reserved.</p>
        </div>
    </div>
</footer>
