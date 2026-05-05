<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

// Fetch dynamic website settings
$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();

$error = '';
$success = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'update_profile') {
            $full_name = trim($_POST['full_name']);
            $username = trim($_POST['username']);

            if (empty($full_name) || empty($username)) {
                $error = "Full name and username are required.";
            } else {
                $check_stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ? AND id_user != ?");
                $check_stmt->bind_param("si", $username, $_SESSION['user_id']);
                $check_stmt->execute();

                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = "Username is already taken by another user.";
                } else {
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ? WHERE id_user = ?");
                    $stmt->bind_param("ssi", $full_name, $username, $_SESSION['user_id']);

                    if ($stmt->execute()) {
                        $_SESSION['full_name'] = $full_name;
                        $_SESSION['username'] = $username;
                        $success = "Profile updated successfully!";
                    } else {
                        $error = "Failed to update profile: " . $conn->error;
                    }
                }
            }
        } elseif ($_POST['action'] == 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error = "All password fields are required.";
            } elseif ($new_password !== $confirm_password) {
                $error = "New passwords do not match.";
            } elseif (strlen($new_password) < 6) {
                $error = "New password must be at least 6 characters long.";
            } else {
                $stmt = $conn->prepare("SELECT password FROM users WHERE id_user = ?");
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();

                    if (password_verify($current_password, $user['password'])) {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id_user = ?");
                        $update_stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);

                        if ($update_stmt->execute()) {
                            $success = "Password changed successfully!";
                        } else {
                            $error = "Failed to change password: " . $conn->error;
                        }
                    } else {
                        $error = "Current password is incorrect.";
                    }
                } else {
                    $error = "User not found.";
                }
            }
        }
    }
}

// Get current user data
$stmt = $conn->prepare("SELECT username, full_name, created_at FROM users WHERE id_user = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

// Get TOEIC-only statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_tests,
        MAX(total_score) as best_score,
        AVG(total_score) as avg_score,
        MIN(completed_at) as first_test,
        MAX(completed_at) as last_test
    FROM toeic_test_results
    WHERE user_id = ?
");
$stats_stmt->bind_param("i", $_SESSION['user_id']);
$stats_stmt->execute();
$user_stats = $stats_stmt->get_result()->fetch_assoc();

$user_name = $_SESSION['full_name'] ?? 'Student';
$initials = strtoupper(substr($user_name, 0, 1));
if (strpos($user_name, ' ') !== false) {
    $parts = explode(' ', $user_name, 2);
    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Profile Settings</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/ruangguru-theme.css', '../assets/css/ruangguru-theme.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/dark-user.css', 'css/dark-user.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">

    <style>
        body { background: var(--rg-bg); color: var(--rg-text); }

        .profile-hero {
            background: linear-gradient(135deg, var(--rg-primary) 0%, var(--rg-accent-blue) 100%);
            color: white;
            padding: 2.5rem 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: -40%; right: -20%;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .profile-avatar {
            width: 90px; height: 90px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; font-weight: 800;
            color: white;
            margin: 0 auto 1rem;
            border: 3px solid rgba(255,255,255,0.3);
        }

        .profile-name { font-size: 1.75rem; font-weight: 800; margin-bottom: 0.25rem; }
        .profile-meta { opacity: 0.8; font-size: 0.9rem; }
        .profile-meta i { opacity: 0.7; }

        /* Stats cards */
        .stats-card {
            background: var(--rg-bg-white);
            border: 1px solid var(--rg-border);
            border-radius: 16px;
            padding: 1.25rem;
            text-align: center;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .stats-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .stats-card h3 { font-size: 1.75rem; font-weight: 800; color: var(--rg-primary); margin-bottom: 0.25rem; }
        .stats-card p { color: var(--rg-text-muted); font-size: 0.85rem; margin: 0; }

        /* Cards */
        .card {
            background: var(--rg-bg-white);
            border: 1px solid var(--rg-border);
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.2s;
            color: var(--rg-text);
        }

        .card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
        .card h4, .card h6 { color: var(--rg-text) !important; }

        .form-control {
            border: 2px solid var(--rg-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
            color: var(--rg-text);
        }

        .form-control:focus {
            border-color: var(--rg-primary);
            box-shadow: 0 0 0 3px var(--rg-primary-light);
            color: var(--rg-text);
        }

        .form-label { color: var(--rg-text-secondary); font-weight: 600; margin-bottom: 0.5rem; }
        .form-label i { color: var(--rg-primary) !important; }

        .btn-primary {
            background: var(--rg-primary);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--rg-primary-dark);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,166,140,0.3);
        }

        .btn-success {
            background: var(--rg-primary);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }

        .btn-success:hover {
            background: var(--rg-primary-dark);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,166,140,0.3);
        }

        .nav-tabs { border-bottom: 2px solid var(--rg-border); }

        .nav-tabs .nav-link {
            border: none;
            border-radius: 10px 10px 0 0;
            color: var(--rg-text-secondary);
            font-weight: 600;
            margin-right: 0.25rem;
            padding: 0.65rem 1.25rem;
            transition: all 0.2s;
        }

        .nav-tabs .nav-link:hover { background: var(--rg-primary-light); color: var(--rg-primary); }

        .nav-tabs .nav-link.active {
            background: var(--rg-primary);
            color: white!important;
            border-color: var(--rg-primary);
        }

        .nav-tabs .nav-link i { color: inherit !important; }

        .alert-danger { background: #FEF2F2; border: 1px solid #FECACA; border-radius: 12px; color: #B91C1C; }
        .alert-success { background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 12px; color: #065F46; }
        .alert-info { background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 12px; color: #1E40AF; }

        h4 i, h6 i { color: var(--rg-primary) !important; }
        .fas { color: white; }

        .input-group-text {
            background: var(--rg-primary-light);
            color: var(--rg-primary);
            border: 2px solid var(--rg-border);
            border-radius: 12px 0 0 12px;
        }

        @media (max-width: 768px) {
            .profile-hero { padding: 2rem 0; }
            .profile-avatar { width: 70px; height: 70px; font-size: 1.5rem; }
            .profile-name { font-size: 1.35rem; }
        }
    </style>
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body>
    <div class="bg-orbs"></div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container py-1">
            <a class="navbar-brand" href="index.php">
                <?php if (!empty($website_logo) && file_exists('../' . $website_logo)): ?>
                    <img src="../<?php echo htmlspecialchars($website_logo); ?>" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-graduation-cap" style="color:var(--rg-primary) !important;"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto ms-3 gap-1">
                    <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home me-1"></i> Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="analytics.php"><i class="fas fa-chart-line me-1"></i> Analytics</a></li>
                    <li class="nav-item"><a class="nav-link" href="buy_exam.php"><i class="fas fa-shopping-cart me-1"></i> Beli Paket</a></li>
                </ul>
                <div class="dropdown ms-2">
                    <div class="avatar-circle" data-bs-toggle="dropdown" role="button">
                        <?php echo htmlspecialchars($initials); ?>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark-custom mt-2">
                        <li><span class="dropdown-item-text px-3 py-2" style="font-size:0.8rem; color:var(--rg-text-muted); font-weight:500;"><?php echo htmlspecialchars($user_name); ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item active" href="profile.php"><i class="fas fa-user-cog me-2" style="width:16px;"></i> Profil Saya</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger-custom" href="../logout.php"><i class="fas fa-sign-out-alt me-2" style="width:16px;"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="profile-hero">
        <div class="container text-center" style="position:relative; z-index:1;">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
            </div>
            <h1 class="profile-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></h1>
            <p class="profile-meta">
                <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
                <span class="mx-2">·</span>
                <i class="fas fa-calendar me-1"></i> Member since <?php echo date('M Y', strtotime($user_data['created_at'])); ?>
            </p>
        </div>
    </section>

    <div class="main-content">
    <div class="container pb-5">
        <div class="row g-4">
            <!-- User Statistics -->
            <div class="col-lg-4">
                <h4 class="mb-3" style="font-weight:700; font-size:1.1rem;">
                    <i class="fas fa-chart-line me-2"></i>Your Statistics
                </h4>

                <div class="stats-card mb-3 fade-in-up">
                    <h3><?php echo $user_stats['total_tests'] ?: '0'; ?></h3>
                    <p>TOEIC Tests Completed</p>
                </div>

                <?php if ($user_stats['total_tests'] > 0): ?>
                    <div class="stats-card mb-3 fade-in-up delay-1">
                        <h3><?php echo $user_stats['best_score']; ?></h3>
                        <p>Best Score</p>
                    </div>

                    <div class="stats-card mb-3 fade-in-up delay-2">
                        <h3><?php echo round($user_stats['avg_score']); ?></h3>
                        <p>Average Score</p>
                    </div>

                    <div class="card fade-in-up delay-3">
                        <div class="card-body">
                            <h6 class="mb-3" style="font-weight:700;">
                                <i class="fas fa-info-circle me-2"></i>TOEIC History
                            </h6>
                            <p class="mb-2">
                                <strong>First Test:</strong><br>
                                <small class="text-muted"><?php echo date('M j, Y', strtotime($user_stats['first_test'])); ?></small>
                            </p>
                            <p class="mb-0">
                                <strong>Latest Test:</strong><br>
                                <small class="text-muted"><?php echo date('M j, Y', strtotime($user_stats['last_test'])); ?></small>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card text-center fade-in-up delay-1">
                        <div class="card-body py-4">
                            <i class="fas fa-clipboard-list fa-3x mb-3" style="color:var(--rg-primary) !important; opacity:0.5;"></i>
                            <p class="text-muted">Belum ada full simulation TOEIC yang selesai.<br>Mulai tes pertama Anda untuk melihat statistik!</p>
                            <a href="index.php" class="btn btn-primary">
                                <i class="fas fa-play me-2"></i>Start TOEIC
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Profile Settings -->
            <div class="col-lg-8">
                <div class="card fade-in-up">
                    <div class="card-body p-4">
                        <h4 class="mb-4" style="font-weight:700;">
                            <i class="fas fa-cog me-2"></i>Profile Settings
                        </h4>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Tabs -->
                        <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">
                                    <i class="fas fa-user me-2"></i>Profile Information
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                                    <i class="fas fa-lock me-2"></i>Change Password
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="profileTabsContent">
                            <!-- Profile Information Tab -->
                            <div class="tab-pane fade show active" id="profile" role="tabpanel">
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_profile">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="full_name" class="form-label"><i class="fas fa-user me-2"></i>Full Name</label>
                                                <input type="text" class="form-control" id="full_name" name="full_name"
                                                    value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="username" class="form-label"><i class="fas fa-at me-2"></i>Username</label>
                                                <input type="text" class="form-control" id="username" name="username"
                                                    value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-shield-alt me-2"></i>Account Type</label>
                                        <input type="text" class="form-control" value="Student" readonly style="background:var(--rg-bg);">
                                        <small class="text-muted">Account type cannot be changed</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-calendar me-2"></i>Member Since</label>
                                        <input type="text" class="form-control"
                                            value="<?php echo date('F j, Y g:i A', strtotime($user_data['created_at'])); ?>" readonly style="background:var(--rg-bg);">
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Profile
                                    </button>
                                </form>
                            </div>

                            <!-- Change Password Tab -->
                            <div class="tab-pane fade" id="password" role="tabpanel">
                                <form method="POST" id="passwordForm">
                                    <input type="hidden" name="action" value="change_password">

                                    <div class="mb-3">
                                        <label for="current_password" class="form-label"><i class="fas fa-lock me-2"></i>Current Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')" style="border-radius: 0 12px 12px 0;">
                                                <i class="fas fa-eye" style="color:var(--rg-text-muted) !important;"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="new_password" class="form-label"><i class="fas fa-key me-2"></i>New Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="6" required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')" style="border-radius: 0 12px 12px 0;">
                                                <i class="fas fa-eye" style="color:var(--rg-text-muted) !important;"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Password must be at least 6 characters long</small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label"><i class="fas fa-check me-2"></i>Confirm New Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="6" required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')" style="border-radius: 0 12px 12px 0;">
                                                <i class="fas fa-eye" style="color:var(--rg-text-muted) !important;"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2" style="color:#1E40AF !important;"></i>
                                        <strong>Password Requirements:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>At least 6 characters long</li>
                                            <li>Use a combination of letters and numbers for better security</li>
                                            <li>Avoid using personal information</li>
                                        </ul>
                                    </div>

                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.nextElementSibling;
            const icon = button.querySelector('i');
            if (field.type === 'password') {
                field.type = 'text'; icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password'; icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
            }
        }

        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            if (document.getElementById('new_password').value !== document.getElementById('confirm_password').value) {
                e.preventDefault(); alert('New passwords do not match!'); return false;
            }
        });

        document.getElementById('confirm_password').addEventListener('input', function() {
            if (this.value && document.getElementById('new_password').value !== this.value) {
                this.setCustomValidity('Passwords do not match'); this.classList.add('is-invalid');
            } else {
                this.setCustomValidity(''); this.classList.remove('is-invalid');
            }
        });

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                const btn = a.querySelector('.btn-close'); if (btn) btn.click();
            });
        }, 5000);
    </script>
</body>
</html>
