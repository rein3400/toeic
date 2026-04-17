<?php
$brandHref = 'index.php';
?>
<nav class="rg-navbar navbar navbar-expand-lg" id="mainNavbar">
    <div class="container d-flex justify-content-between align-items-center">
        <a class="navbar-brand" href="<?php echo $brandHref; ?>">
            <?php if (!empty($website_logo) && file_exists($website_logo)): ?>
                <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo">
            <?php else: ?>
                <i class="fas fa-briefcase"></i>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($website_title); ?></span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent" aria-controls="navContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="navContent">
            <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
                <a class="nav-link" href="index.php#overview">Overview</a>
                <a class="nav-link" href="index.php#why-toeic">Why TOEIC</a>
                <a class="nav-link" href="index.php#proof">Score Report</a>
                <a class="nav-link" href="index.php#simulator">The TOEIC Tests</a>

                <?php if ($is_logged_in): ?>
                    <?php if ($is_admin): ?>
                        <a class="btn btn-outline-secondary" href="admin/index.php">Admin Console</a>
                    <?php else: ?>
                        <a class="btn btn-warning" href="user/index.php">Open Dashboard</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="btn btn-outline-secondary" href="login.php">Sign In</a>
                    <a class="btn btn-warning" href="register.php">Get Started</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
