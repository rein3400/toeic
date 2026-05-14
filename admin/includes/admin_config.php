<?php
/**
 * Admin Configuration
 * Central configuration for admin panel
 */

// Admin navigation configuration
function getAdminNavigation()
{
    return [
        'index.php' => [
            'icon' => 'fa-tachometer-alt',
            'title' => 'Dashboard',
            'description' => 'TOEIC product overview and statistics',
            'group' => 'main'
        ],
        'manage_toeic.php' => [
            'icon' => 'fa-briefcase',
            'title' => 'TOEIC Bank',
            'description' => 'Manage TOEIC parts, questions, audio, and text linkage',
            'group' => 'content'
        ],
        'toeic_sw_bank.php' => [
            'icon' => 'fa-microphone-lines',
            'title' => 'TOEIC SW Bank',
            'description' => 'Inspect TOEIC Speaking & Writing packages, prompts, audio, and images',
            'group' => 'content'
        ],
        'test_results.php' => [
            'icon' => 'fa-chart-line',
            'title' => 'TOEIC Results',
            'description' => 'View TOEIC result reports and completion history',
            'group' => 'analytics'
        ],
        'toeic_sw_results.php' => [
            'icon' => 'fa-microphone',
            'title' => 'TOEIC SW Results',
            'description' => 'Review TOEIC Speaking & Writing results and rescoring',
            'group' => 'analytics'
        ],
        'test_sessions.php' => [
            'icon' => 'fa-list-check',
            'title' => 'TOEIC Sessions',
            'description' => 'Inspect full-test and practice session runtime state',
            'group' => 'analytics'
        ],
        'proctoring_sessions.php' => [
            'icon' => 'fa-user-shield',
            'title' => 'Proctoring',
            'description' => 'Review TOEIC proctoring sessions and clearance status',
            'group' => 'analytics'
        ],
        'vouchers.php' => [
            'icon' => 'fa-ticket-alt',
            'title' => 'Voucher',
            'description' => 'Generate and manage exam vouchers',
            'group' => 'system'
        ],
        'users.php' => [
            'icon' => 'fa-users',
            'title' => 'Users',
            'description' => 'Manage system users',
            'group' => 'system'
        ],
        'settings.php' => [
            'icon' => 'fa-cog',
            'title' => 'Settings',
            'description' => 'System configuration',
            'group' => 'system'
        ],
        'ai_api_settings.php' => [
            'icon' => 'fa-robot',
            'title' => 'AI API Settings',
            'description' => 'Configure AI providers',
            'group' => 'system'
        ],
    ];
}

// Navigation groups configuration
function getAdminNavGroups()
{
    return [
        'main' => [
            'title' => 'Main',
            'order' => 1
        ],
        'content' => [
            'title' => 'Content Management',
            'order' => 2
        ],
        'analytics' => [
            'title' => 'Analytics',
            'order' => 3
        ],
        'system' => [
            'title' => 'System',
            'order' => 4
        ]
    ];
}

// Get grouped navigation items
function getGroupedNavigation()
{
    $nav_items = getAdminNavigation();
    $nav_groups = getAdminNavGroups();
    $grouped = [];

    // Initialize groups
    foreach ($nav_groups as $group_key => $group_info) {
        $grouped[$group_key] = [
            'title' => $group_info['title'],
            'order' => $group_info['order'],
            'items' => []
        ];
    }

    // Group navigation items
    foreach ($nav_items as $url => $item) {
        $group = $item['group'] ?? 'main';
        if (isset($grouped[$group])) {
            $grouped[$group]['items'][$url] = $item;
        }
    }

    // Sort groups by order
    uasort($grouped, function ($a, $b) {
        return $a['order'] <=> $b['order'];
    });

    return $grouped;
}

// Check if user has permission for specific page
function hasAdminPermission($page, $user_role = null)
{
    if ($user_role === null) {
        $user_role = $_SESSION['role'] ?? '';
    }

    // Admin has access to all pages
    if ($user_role === 'admin') {
        return true;
    }

    // Define restricted pages for non-admin users
    $restricted_pages = [
        'users.php',
        'settings.php',
        'ai_api_settings.php'
    ];

    return !in_array($page, $restricted_pages);
}

// Get current page info
function getCurrentPageInfo()
{
    $current_page = basename($_SERVER['PHP_SELF']);
    $nav_items = getAdminNavigation();

    return $nav_items[$current_page] ?? [
        'icon' => 'fa-file',
        'title' => 'Unknown Page',
        'description' => 'Page information not available',
        'group' => 'main'
    ];
}

// Admin panel configuration
function getAdminConfig()
{
    return [
        'sidebar_width' => '280px',
        'mobile_breakpoint' => '1200px',
        'enable_tooltips' => true,
        'enable_animations' => true,
        'show_group_titles' => true,
        'auto_collapse_mobile' => true,
        'debug_mode' => false
    ];
}
?>
