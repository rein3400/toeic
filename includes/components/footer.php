<?php
// Extracted Footer Component — Ruangguru Light Theme
?>
<footer class="rg-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <h5>
                    <?php if (!empty($website_logo) && file_exists($website_logo)): ?>
                        <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo" style="height:28px; margin-right:8px; filter: brightness(0) invert(1);">
                    <?php else: ?>
                        <i class="fas fa-graduation-cap me-2" style="color:#34D399;"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($website_title); ?>
                </h5>
                <p style="font-size:0.9rem; color:rgba(255,255,255,0.55); line-height:1.7;">
                    Platform simulasi TOEIC yang fokus pada Listening & Reading, practice mode per part, dan score report yang siap dipakai untuk latihan terarah.
                </p>
                <div class="d-flex gap-3 mt-3">
                    <a href="#" style="color:rgba(255,255,255,0.5); font-size:1.2rem;" title="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" style="color:rgba(255,255,255,0.5); font-size:1.2rem;" title="YouTube"><i class="fab fa-youtube"></i></a>
                    <a href="#" style="color:rgba(255,255,255,0.5); font-size:1.2rem;" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                </div>
            </div>
            <div class="col-lg-2 col-md-6 col-6">
                <h5>Tes</h5>
                <ul class="rg-footer-links">
                    <li><a href="#tests">TOEIC</a></li>
                </ul>
            </div>
            <div class="col-lg-2 col-md-6 col-6">
                <h5>Platform</h5>
                <ul class="rg-footer-links">
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php">Daftar</a></li>
                    <li><a href="index.php#features">Fitur</a></li>
                </ul>
            </div>
            <div class="col-lg-2 col-md-6 col-6">
                <h5>Informasi</h5>
                <ul class="rg-footer-links">
                    <li><a href="privacy-policy.php">Kebijakan Privasi</a></li>
                    <li><a href="terms-of-service.php">Syarat & Ketentuan</a></li>
                    <li><a href="contact.php">Hubungi Kami</a></li>
                    <li><a href="index.php#faqAccordion">FAQ</a></li>
                </ul>
            </div>
            <div class="col-lg-2 col-md-6">
                <h5>Kontak</h5>
                <ul class="rg-footer-links">
                    <li><i class="fas fa-envelope me-2" style="color:rgba(255,255,255,0.4); width:16px;"></i>support@osgli.com</li>
                    <li><i class="fas fa-globe me-2" style="color:rgba(255,255,255,0.4); width:16px;"></i>www.osgli.com</li>
                </ul>
            </div>
        </div>
        <div class="rg-footer-bottom">
            <p class="mb-0">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($website_title); ?>. All rights reserved.
                | <a href="privacy-policy.php">Privasi</a>
                | <a href="terms-of-service.php">Syarat</a>
                | <a href="contact.php">Kontak</a>
                | Developed with <span style="color:#EF4444;">❤️</span> by <a href="https://frans.web.id" target="_blank">Frans Creative Studio</a>
            </p>
        </div>
    </div>
</footer>
