<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/email_verification_helper.php';
require_once '../includes/toeic_quality_helpers.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

toeicEnsureEmailVerificationSchema($conn);

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
            $email = strtolower(trim($_POST['email'] ?? ''));

            if (empty($full_name) || empty($username) || empty($email)) {
                $error = "Profile name, username, and email are required.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Profile email is not valid.";
            } else {
                $check_stmt = $conn->prepare("SELECT id_user FROM users WHERE (username = ? OR LOWER(email) = ?) AND id_user != ?");
                $check_stmt->bind_param("ssi", $username, $email, $_SESSION['user_id']);
                $check_stmt->execute();

                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = "Profile username or email is already taken by another user.";
                } else {
                    $current_stmt = $conn->prepare("SELECT email FROM users WHERE id_user = ?");
                    $current_stmt->bind_param("i", $_SESSION['user_id']);
                    $current_stmt->execute();
                    $current_email = strtolower(trim((string)($current_stmt->get_result()->fetch_assoc()['email'] ?? '')));
                    $current_stmt->close();

                    $email_changed = $current_email !== $email;
                    if ($email_changed) {
                        $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, email_verified_at = NULL WHERE id_user = ?");
                        $stmt->bind_param("sssi", $full_name, $username, $email, $_SESSION['user_id']);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ? WHERE id_user = ?");
                        $stmt->bind_param("sssi", $full_name, $username, $email, $_SESSION['user_id']);
                    }

                    if ($stmt->execute()) {
                        $_SESSION['full_name'] = $full_name;
                        $_SESSION['username'] = $username;
                        if ($email_changed) {
                            toeicCreateEmailVerification($conn, (int)$_SESSION['user_id']);
                            $success = "Profile updated successfully! Please verify the new email address.";
                        } else {
                            $success = "Profile updated successfully!";
                        }
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
$stmt = $conn->prepare("SELECT username, full_name, email, email_verified_at, created_at FROM users WHERE id_user = ?");
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

$total_tests = (int)($user_stats['total_tests'] ?? 0);
$best_score_display = toeicDisplayRoundedScore($user_stats['best_score'] ?? null);
$avg_score_display = toeicDisplayRoundedScore($user_stats['avg_score'] ?? null);
$first_test = $user_stats['first_test'] ?? null;
$last_test = $user_stats['last_test'] ?? null;

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
    <title>Profile Settings - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .profile-hero {
            background: linear-gradient(135deg, var(--academy-blue) 0%, var(--focus-blue) 100%);
            padding: 3rem 0;
            color: white;
            text-align: center;
        }
        .profile-avatar-lg {
            width: 100px;
            height: 100px;
            background: var(--sunbeam-yellow) !important;
            color: var(--focus-blue) !important;
            border: 4px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        .nav-tabs .nav-link {
            border: none !important;
            color: var(--muted-slate);
            font-weight: 800;
            padding: 1rem 1.5rem;
            border-radius: 12px 12px 0 0 !important;
        }
        .nav-tabs .nav-link.active {
            background: white !important;
            color: var(--focus-blue) !important;
            border: 2px solid var(--cloud-line) !important;
            border-bottom: none !important;
        }
    </style>
</head>
<body class="tc-user-page tc-profile-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <div class="avatar-circle" data-bs-toggle="dropdown" role="button"><?php echo htmlspecialchars($initials); ?></div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 p-2 rounded-3">
                        <li><a class="dropdown-item rounded-2 py-2" href="index.php"><i class="fas fa-home me-2"></i> Dashboard</a></li>
                        <li><a class="dropdown-item rounded-2 py-2" href="analytics.php"><i class="fas fa-chart-pie me-2"></i> Analytics</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item rounded-2 py-2 text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main>
        <section class="profile-hero mb-4">
            <div class="container">
                <div class="profile-avatar-lg"><?php echo htmlspecialchars($initials); ?></div>
                <h1 class="text-white mb-2"><?php echo htmlspecialchars($user_data['full_name']); ?></h1>
                <p class="opacity-75 mb-0">@<?php echo htmlspecialchars($user_data['username']); ?> · Member since <?php echo date('M Y', strtotime($user_data['created_at'])); ?></p>
            </div>
        </section>

        <div class="toeic-page-shell">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="study-card mb-4">
                        <span class="study-kicker">Performance</span>
                        <h2 class="h4 mb-4">Stats Summary</h2>

                        <div class="p-3 rounded-3 bg-light mb-3 text-center">
                            <div class="h2 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo $total_tests; ?></div>
                            <div class="small text-muted fw-bold">Tests Completed</div>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <div class="p-3 rounded-3 bg-light text-center">
                                    <div class="h4 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo htmlspecialchars($best_score_display); ?></div>
                                    <div class="small text-muted fw-bold">Best</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded-3 bg-light text-center">
                                    <div class="h4 fw-bold mb-0" style="color:var(--focus-blue);"><?php echo htmlspecialchars($avg_score_display); ?></div>
                                    <div class="small text-muted fw-bold">Avg</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($total_tests > 0 && $first_test && $last_test): ?>
                        <div class="study-card">
                            <span class="study-kicker">Timeline</span>
                            <div class="mb-3">
                                <div class="small text-muted fw-bold uppercase mb-1">First Attempt</div>
                                <div class="fw-bold"><?php echo date('M j, Y', strtotime($first_test)); ?></div>
                            </div>
                            <div>
                                <div class="small text-muted fw-bold uppercase mb-1">Latest Attempt</div>
                                <div class="fw-bold"><?php echo date('M j, Y', strtotime($last_test)); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-8">
                    <div class="study-card p-0 overflow-visible" style="border-bottom:none !important; background:transparent !important;">
                        <ul class="nav nav-tabs border-0" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">Account Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">Security</button>
                            </li>
                        </ul>
                    </div>

                    <div class="study-card" style="border-top-left-radius: 0;">
                        <div class="tab-content" id="profileTabsContent">
                            <div class="tab-pane fade show active" id="info" role="tabpanel">
                                <?php if ($success && strpos($success, 'Password') === false): ?>
                                    <div class="alert alert-success border-0 rounded-3 mb-4"><i class="fas fa-check-circle me-2"></i> <?php echo $success; ?></div>
                                <?php endif; ?>
                                <?php if ($error && strpos($error, 'Password') === false): ?>
                                    <div class="alert alert-danger border-0 rounded-3 mb-4"><i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?></div>
                                <?php endif; ?>

                                <form method="POST">
                                    <input type="hidden" name="action" value="update_profile">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="toeic-field-label" for="full_name">Full Name</label>
                                            <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="toeic-field-label" for="username">Username</label>
                                            <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="toeic-field-label" for="email">Email</label>
                                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars((string)($user_data['email'] ?? '')); ?>" required autocomplete="email">
                                            <div class="small fw-bold mt-2 <?php echo !empty($user_data['email_verified_at']) ? 'text-success' : 'text-warning'; ?>">
                                                <?php echo !empty($user_data['email_verified_at']) ? 'Email verified' : 'Email not verified'; ?>
                                            </div>
                                        </div>
                                        <div class="col-12 pt-3">
                                            <button type="submit" class="study-button">Save Changes</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="tab-pane fade" id="security" role="tabpanel">
                                <?php if ($success && strpos($success, 'Password') !== false): ?>
                                    <div class="alert alert-success border-0 rounded-3 mb-4"><i class="fas fa-check-circle me-2"></i> <?php echo $success; ?></div>
                                <?php endif; ?>
                                <?php if ($error && strpos($error, 'Password') !== false): ?>
                                    <div class="alert alert-danger border-0 rounded-3 mb-4"><i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?></div>
                                <?php endif; ?>

                                <form method="POST">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="mb-4">
                                        <label class="toeic-field-label" for="current_password">Current Password</label>
                                        <input type="password" id="current_password" name="current_password" class="form-control" required>
                                    </div>
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-6">
                                            <label class="toeic-field-label" for="new_password">New Password</label>
                                            <input type="password" id="new_password" name="new_password" class="form-control" minlength="6" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="toeic-field-label" for="confirm_password">Confirm New Password</label>
                                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="6" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="study-button">Update Password</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
