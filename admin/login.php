<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';

// Get website settings
$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Resolve schema drift: users table may have 'id' or 'id_user' as PK column
    $id_col = getUsersIdColumn($conn);
    $stmt = $conn->prepare("SELECT $id_col as user_id, username, password, full_name, role FROM users WHERE username = ? AND role = 'admin'");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
         if (password_verify($password, $user['password'])) {
             $_SESSION['user_id'] = $user['user_id'];
             $_SESSION['username'] = $user['username'];
             $_SESSION['full_name'] = $user['full_name'];
             $_SESSION['role'] = $user['role'];

            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid admin credentials.";
        }
    } else {
        $error = "Invalid admin credentials.";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Admin Login</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .login-container {
            background: var(--glass-bg);
            backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            overflow: hidden;
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
            box-shadow: var(--shadow-lg);
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .logo-container {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }

        .login-header h2 {
            font-weight: 600;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.875rem;
        }

        .login-body {
            padding: 2rem 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .btn-login {
            background: var(--primary);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.2s ease;
            width: 100%;
            color: white;
            font-size: 0.875rem;
        }

        .btn-login:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--primary-glow);
            color: white;
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .footer-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .footer-link a {
            color: var(--primary-hover);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .footer-link a:hover {
            color: #93c5fd;
        }

        .security-info {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem;
            margin-top: 1rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: center;
        }

        @media (max-width: 576px) {
            body {
                padding: 1rem 0.5rem;
            }

            .login-container {
                max-width: 100%;
            }

            .login-header {
                padding: 1.5rem 1rem;
            }

            .login-body {
                padding: 1.5rem 1rem;
            }

            .logo-container {
                width: 50px;
                height: 50px;
            }

            .login-header h2 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
                <?php if (!empty($website_logo) && file_exists('../' . $website_logo)): ?>
                    <img src="../<?php echo htmlspecialchars($website_logo); ?>" alt="Logo"
                        style="height: 50px; width: 50px; object-fit: contain;">
                <?php else: ?>
                    <i class="fas fa-shield-alt fa-2x" style="color: var(--maroon-primary);"></i>
                <?php endif; ?>
            </div>
            <h2><i class="fas fa-crown me-2"></i>Admin Panel</h2>
            <p class="mb-0 opacity-75"><?php echo htmlspecialchars($website_title); ?> Management System</p>
            <div class="security-badge">
                <i class="fas fa-lock me-1"></i>Secure Access
            </div>
        </div>
        <div class="login-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" id="adminLoginForm">
                <div class="form-floating">
                    <input type="text" class="form-control" id="username" name="username" placeholder="Admin Username"
                        required>
                    <label for="username">
                        <i class="fas fa-user-shield me-2"></i>Admin Username
                    </label>
                </div>

                <div class="form-floating">
                    <input type="password" class="form-control" id="password" name="password"
                        placeholder="Admin Password" required>
                    <label for="password">
                        <i class="fas fa-lock me-2"></i>Admin Password
                    </label>
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Access Admin Panel
                </button>
            </form>

            <div class="footer-link">
                <div class="text-center">
                    <div class="small text-muted mb-2">
                        <i class="fas fa-shield-check me-1"></i>Administrator Access Only
                    </div>
                    <a href="../login.php">
                        <i class="fas fa-arrow-left me-1"></i>Back to Student Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer
        style="position: fixed; bottom: 0; width: 100%; background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-secondary)); color: white; padding: 1rem 0; border-top: 3px solid var(--maroon-dark); box-shadow: 0 -4px 15px rgba(128, 0, 32, 0.2);">
        <div class="container">
            <div style="text-align: center; color: rgba(255, 255, 255, 0.9);">
                <p class="mb-0">&copy; 2025 <?php echo htmlspecialchars($website_title); ?> System. All rights reserved.
                    | Developed with <span style="color: #ffd700;">❤️</span> by <a href="https://frans.web.id"
                        target="_blank" style="color: white; text-decoration: none; font-weight: 600;">Frans Creative
                        Studio</a></p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        // Add loading animation to login button
        document.getElementById('adminLoginForm').addEventListener('submit', function (e) {
            const submitBtn = this.querySelector('.btn-login');
            const originalText = submitBtn.innerHTML;

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Authenticating...';
            submitBtn.disabled = true;

            // Re-enable button after 3 seconds if form doesn't submit
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });

        // Add floating animation to form elements
        const formInputs = document.querySelectorAll('.form-floating input');
        formInputs.forEach(input => {
            input.addEventListener('focus', function () {
                this.parentElement.style.transform = 'translateY(-2px)';
                this.parentElement.style.transition = 'transform 0.3s ease';
            });

            input.addEventListener('blur', function () {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (alert.querySelector('.btn-close')) {
                    alert.querySelector('.btn-close').click();
                }
            });
        }, 5000);
    </script>
</body>

</html>