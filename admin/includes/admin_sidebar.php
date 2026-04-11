<?php
/**
 * Admin Sidebar Component
 * Reusable sidebar for all admin pages - Dark Glassmorphism Theme
 */

// Include required files
if (!function_exists('getWebsiteTitle')) {
    require_once '../includes/settings.php';
}

// Include admin configuration
require_once 'admin_config.php';

// Active page detection
$current_page = basename($_SERVER['PHP_SELF']);

// Get website settings
$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();

// Get navigation configuration
$grouped_nav = getGroupedNavigation();
$admin_config = getAdminConfig();
$current_page_info = getCurrentPageInfo();

// Check user permissions
$user_role = $_SESSION['role'] ?? '';
?>

<!-- Mobile Menu Toggle -->
<button class="mobile-menu-toggle btn" onclick="toggleMobileMenu()" aria-label="Toggle mobile menu">
    <i class="fas fa-bars"></i>
</button>

<!-- Mobile Menu Overlay -->
<div class="mobile-menu-overlay" onclick="closeMobileMenu()"></div>

<!-- Admin Sidebar -->
<div class="col-md-2 admin-sidebar p-0" id="adminSidebar">
    <div class="d-flex flex-column h-100">
        <!-- Header Section -->
        <div class="sidebar-header p-4">
            <div class="d-flex align-items-center gap-3">
                <div class="sidebar-logo-container d-flex align-items-center justify-content-center"
                    style="width: 40px; height: 40px; border-radius: 12px; background: rgba(37,99,235,0.15);">
                    <?php if (!empty($website_logo) && file_exists('../' . $website_logo)): ?>
                        <img src="../<?php echo htmlspecialchars($website_logo); ?>" alt="Logo" class="sidebar-logo">
                    <?php else: ?>
                        <i class="fas fa-shield-alt fa-lg" style="color: #60a5fa;"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold" style="color: rgba(255,255,255,0.92);"><?php echo htmlspecialchars($website_title); ?></h6>
                    <small style="font-size: 0.75rem; color: rgba(255,255,255,0.4);">Admin Panel</small>
                </div>
            </div>
        </div>

        <!-- Navigation Section -->
        <div class="sidebar-nav flex-grow-1 p-3" style="overflow-y: auto;">
            <nav class="nav flex-column gap-1" role="navigation">
                <?php foreach ($grouped_nav as $group_key => $group): ?>
                    <?php if ($group_key !== 'main' && $admin_config['show_group_titles']): ?>
                        <div class="nav-group-title mt-3 mb-2 px-3">
                            <small class="text-uppercase fw-bold"
                                style="font-size: 0.7rem; letter-spacing: 0.5px; color: rgba(255,255,255,0.35);"><?php echo $group['title']; ?></small>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($group['items'] as $url => $item): ?>
                        <?php if (hasAdminPermission($url, $user_role)): ?>
                            <a class="nav-link sidebar-link <?php echo ($current_page == $url) ? 'active' : ''; ?>" href="<?php echo $url; ?>"
                                title="<?php echo htmlspecialchars($item['description']); ?>">
                                <i class="fas <?php echo $item['icon']; ?> nav-icon"></i>
                                <span class="nav-text"><?php echo $item['title']; ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($group_key !== 'system' && count($grouped_nav) > 1): ?>
                        <div class="my-2 mx-3" style="border-top: 1px solid rgba(255,255,255,0.06);"></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- Footer Section -->
        <div class="sidebar-footer p-3" style="border-top: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.02);">
            <div class="d-flex align-items-center gap-3 mb-3 px-2">
                <div class="d-flex align-items-center justify-content-center"
                    style="width: 36px; height: 36px; border-radius: 50%; background: rgba(37,99,235,0.15); border: 1px solid rgba(255,255,255,0.1);">
                    <span style="color: #60a5fa; font-weight: 600; font-size: 0.85rem;">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'AD', 0, 2)); ?>
                    </span>
                </div>
                <div class="overflow-hidden">
                    <div class="fw-bold small text-truncate" style="color: rgba(255,255,255,0.85);">
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>
                    </div>
                    <div class="small" style="font-size: 0.7rem; color: rgba(255,255,255,0.4);">Administrator</div>
                </div>
            </div>
            <a href="logout.php"
                class="btn btn-outline-danger w-100 btn-sm d-flex align-items-center justify-content-center gap-2"
                style="border-color: rgba(239,68,68,0.3); color: #f87171; border-radius: 10px;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</div>

<style>
    /* Dark Glassmorphism Sidebar */
    .admin-sidebar {
        background: rgba(15,15,26,0.95);
        backdrop-filter: blur(24px);
        -webkit-backdrop-filter: blur(24px);
        border-right: 1px solid rgba(255,255,255,0.06);
        transition: transform 0.3s ease;
    }

    .sidebar-header {
        border-bottom: 1px solid rgba(255,255,255,0.06);
    }

    .sidebar-link {
        color: rgba(255,255,255,0.55) !important;
        border-radius: 10px;
        padding: 0.7rem 1rem !important;
        font-weight: 500;
        font-size: 0.88rem;
        display: flex;
        align-items: center;
        transition: all 0.2s;
        border-left: 3px solid transparent;
    }

    .sidebar-link:hover {
        background: rgba(255,255,255,0.06);
        color: rgba(255,255,255,0.9) !important;
    }

    .sidebar-link.active {
        background: rgba(37,99,235,0.12);
        color: #60a5fa !important;
        font-weight: 600;
        border-left-color: #2563eb;
    }

    .nav-icon {
        width: 20px;
        text-align: center;
        margin-right: 0.75rem;
        font-size: 0.9rem;
    }

    .sidebar-logo {
        width: 24px;
        height: 24px;
        object-fit: contain;
    }

    /* Sidebar scrollbar */
    .sidebar-nav::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar-nav::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.1);
        border-radius: 2px;
    }

    /* Mobile */
    .mobile-menu-toggle {
        display: none;
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 1050;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #2563eb;
        color: white;
        box-shadow: 0 4px 16px rgba(37,99,235,0.4);
        border: none;
        align-items: center;
        justify-content: center;
    }

    .mobile-menu-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        z-index: 1040;
        opacity: 0;
        transition: opacity 0.3s;
    }

    @media (max-width: 768px) {
        .admin-sidebar {
            transform: translateX(-100%);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 280px;
            z-index: 1045;
        }

        .admin-sidebar.show {
            transform: translateX(0);
        }

        .mobile-menu-toggle {
            display: flex;
        }

        .mobile-menu-overlay.show {
            display: block;
            opacity: 1;
        }
    }
</style>

<script>
    function toggleMobileMenu() {
        document.getElementById('adminSidebar').classList.toggle('show');
        document.querySelector('.mobile-menu-overlay').classList.toggle('show');
    }

    function closeMobileMenu() {
        document.getElementById('adminSidebar').classList.remove('show');
        document.querySelector('.mobile-menu-overlay').classList.remove('show');
    }

    // Auto-active based on current page
    document.addEventListener('DOMContentLoaded', () => {
        const links = document.querySelectorAll('.sidebar-link');
        const currentPath = window.location.pathname.split('/').pop();
        links.forEach(link => {
            if (link.getAttribute('href') === currentPath) {
                link.classList.add('active');
            }
        });
    });
</script>
