<?php
// Extracted Navbar Component — Ruangguru Light Theme
?>
<nav class="rg-navbar navbar navbar-expand-lg" id="mainNavbar">
    <div class="container d-flex justify-content-between align-items-center">
        <a class="navbar-brand" href="index.php">
            <?php if (!empty($website_logo) && file_exists($website_logo)): ?>
                <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo">
            <?php else: ?>
                <i class="fas fa-graduation-cap" style="color:var(--rg-primary, #00A68C);"></i>
            <?php endif; ?>
            <?php echo htmlspecialchars($website_title); ?>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="navContent">
            <div class="d-flex align-items-center gap-2 ms-auto">
                <!-- Tests dropdown -->
                <div class="dropdown d-none d-md-inline">
                    <a class="nav-link dropdown-toggle" href="#tests" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer;">
                        <i class="fas fa-file-alt me-1" style="font-size:0.8rem;"></i>Tes
                    </a>
                    <ul class="dropdown-menu dropdown-menu-nav mt-2">
                        <li>
                            <a class="dropdown-item" href="index.php#tests">
                                <i class="fas fa-briefcase me-2" style="color:#F59E0B;"></i>TOEIC
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Fitur dropdown -->
                <div class="dropdown d-none d-md-inline">
                    <a class="nav-link dropdown-toggle" href="#features" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer;">
                        <i class="fas fa-th-large me-1" style="font-size:0.8rem;"></i>Fitur
                    </a>
                    <ul class="dropdown-menu dropdown-menu-nav mt-2">
                        <li>
                            <a class="dropdown-item" href="index.php#features">
                                <i class="fas fa-star me-2" style="color:#00A68C;"></i>Kenapa Pilih Kami
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="index.php#how-it-works">
                                <i class="fas fa-route me-2" style="color:#2563EB;"></i>Cara Kerja
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="index.php#testimonials">
                                <i class="fas fa-heart me-2" style="color:#EF4444;"></i>Testimoni
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="index.php#faqAccordion">
                                <i class="fas fa-question-circle me-2" style="color:#8B5CF6;"></i>FAQ
                            </a>
                        </li>
                    </ul>
                </div>

                <?php if ($is_logged_in): ?>
                    <?php if ($is_admin): ?>
                        <a class="btn-rg-primary" href="admin/index.php">
                            <i class="fas fa-tachometer-alt"></i>Admin
                        </a>
                    <?php else: ?>
                        <a class="btn-rg-primary" href="user/index.php">
                            <i class="fas fa-th-large"></i>Dashboard
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="nav-link" href="login.php">Login</a>
                    <a class="btn-rg-secondary" href="register.php">
                        Mulai Belajar
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<style>
.dropdown-menu-nav {
    border: 1px solid var(--rg-border, #E5E7EB);
    border-radius: 12px;
    padding: 0.5rem;
    box-shadow: 0 8px 30px rgba(0,0,0,0.1);
    background: #FFFFFF;
    min-width: 200px;
}
.dropdown-menu-nav .dropdown-item {
    border-radius: 8px;
    padding: 0.55rem 0.85rem;
    font-size: 0.88rem;
    font-weight: 500;
    color: var(--rg-text, #1A2A44);
    transition: all 0.2s ease;
}
.dropdown-menu-nav .dropdown-item:hover {
    background: var(--rg-primary-light, #E0F7F3);
    color: var(--rg-primary, #00A68C);
}
.rg-navbar .nav-link.dropdown-toggle::after {
    font-size: 0.6rem;
    vertical-align: 0.15rem;
    margin-left: 0.3rem;
}
</style>

<script>
window.addEventListener('scroll', function() {
    const nav = document.getElementById('mainNavbar');
    if (nav) {
        nav.classList.toggle('scrolled', window.scrollY > 30);
    }
});
</script>
