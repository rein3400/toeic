<?php
require_once 'includes/session_handler.php';
// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: admin/index.php");
    } else {
        header("Location: user/index.php");
    }
    exit();
}
require_once 'includes/config.php';
require_once 'includes/settings.php';
require_once 'includes/db_utils.php';

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$db_unavailable = !($conn instanceof mysqli);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($db_unavailable) {
        $error = "Login is temporarily unavailable because the database connection failed.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $redirect = $_POST['redirect'] ?? '';

        // Resolve schema drift: users table may have 'id' or 'id_user' as PK column
        $id_col = getUsersIdColumn($conn);
        $stmt = $conn->prepare("SELECT $id_col as user_id, username, password, full_name, role FROM users WHERE username = ?");
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

                // Handle Redirect Parameter
                if (!empty($redirect)) {
                    header("Location: " . $redirect);
                    exit();
                }

                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header("Location: admin/index.php");
                } else {
                    header("Location: user/index.php");
                }
                exit();
            } else {
                $error = "Invalid credentials.";
            }
        } else {
            $error = "Invalid credentials.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Login</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="user/css/mobile-responsive.css" rel="stylesheet">

    <style>
        body { color: var(--rg-text); }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .login-wrapper {
            display: flex;
            max-width: 900px;
            width: 100%;
            border-radius: 32px;
            overflow: hidden;
            background: rgba(255,253,248,0.95);
            border: 1px solid rgba(231,223,207,0.92);
            box-shadow: 0 28px 80px rgba(15,31,61,0.12);
        }

        /* Left illustration panel */
        .login-illustration {
            background: linear-gradient(135deg, #0f1f3d 0%, #1d3561 60%, #d68300 180%);
            color: white;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            flex: 0 0 40%;
            position: relative;
            overflow: hidden;
        }

        .login-illustration::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -30%;
            width: 250px;
            height: 250px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .login-illustration::after {
            content: '';
            position: absolute;
            bottom: -20%;
            left: -20%;
            width: 180px;
            height: 180px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
        }

        .login-illustration .illust-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .login-illustration h2 {
            font-family: var(--rg-display);
            font-weight: 800;
            font-size: 1.6rem;
            margin-bottom: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .login-illustration p {
            font-size: 0.95rem;
            opacity: 0.85;
            position: relative;
            z-index: 1;
            line-height: 1.6;
        }

        /* Right form panel */
        .login-form-panel {
            flex: 1;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-form-header {
            margin-bottom: 2rem;
        }

        .login-form-header .logo-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .login-form-header .logo-row img {
            height: 36px;
        }

        .login-form-header .logo-row .brand-name {
            font-weight: 800;
            font-size: 1.2rem;
            color: var(--rg-text);
        }

        .login-form-header h3 {
            font-size: 1.6rem;
            font-family: var(--rg-display);
            font-weight: 800;
            color: var(--rg-text);
            margin-bottom: 0.35rem;
        }

        .login-form-header p {
            color: var(--rg-text-muted);
            font-size: 0.95rem;
            margin: 0;
        }

        .form-floating {
            margin-bottom: 1.25rem;
        }

        .form-floating .form-control {
            border: 2px solid var(--rg-border);
            border-radius: 12px;
            padding: 1rem 0.75rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--rg-bg-white);
            height: 58px;
            color: var(--rg-text);
        }

        .form-floating .form-control:focus {
            border-color: var(--rg-primary);
            box-shadow: 0 0 0 3px var(--rg-primary-light);
        }

        .form-floating label {
            color: var(--rg-text-muted);
            font-weight: 500;
            padding: 1rem 0.75rem;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--toeic-amber), var(--toeic-amber-deep));
            border: none;
            color: #1d1400;
            padding: 0.85rem 2rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.05rem;
            width: 100%;
            transition: all 0.3s ease;
            margin-bottom: 1.25rem;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #f5b53f, var(--toeic-amber-deep));
            color: #1d1400;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(240,161,26,0.28);
        }

        .login-links {
            text-align: center;
            padding-top: 1rem;
            border-top: 1px solid var(--rg-border-light);
        }

        .login-links a {
            color: var(--rg-primary);
            text-decoration: none;
            font-weight: 600;
        }

        .login-links a:hover {
            color: var(--rg-primary-dark);
            text-decoration: underline;
        }

        .login-links p {
            color: var(--rg-text-muted);
            font-size: 0.9rem;
        }

        .alert {
            border-radius: 12px;
            border: none;
            margin-bottom: 1.25rem;
        }

        .alert-danger {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #B91C1C;
        }

        @media (max-width: 768px) {
            .login-illustration {
                display: none;
            }
            .login-form-panel {
                padding: 2rem;
            }
            .login-wrapper {
                border-radius: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-wrapper rg-fade-in">
            <!-- Left Illustration -->
            <div class="login-illustration">
                <div class="illust-icon">
                    <i class="fas fa-briefcase"></i>
                </div>
                <h2>Masuk ke TOEIC Command Center</h2>
                <p>Lanjutkan full simulation, drill per part, dan review score TOEIC terakhir Anda dari satu dashboard yang fokus.</p>
            </div>

            <!-- Right Form -->
            <div class="login-form-panel">
                <div class="login-form-header">
                    <div class="logo-row">
                        <?php if (!empty($website_logo) && file_exists($website_logo)): ?>
                            <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo">
                        <?php else: ?>
                            <i class="fas fa-graduation-cap" style="font-size:1.5rem; color:var(--rg-primary);"></i>
                        <?php endif; ?>
                        <span class="brand-name"><?php echo htmlspecialchars($website_title); ?></span>
                    </div>
                    <h3>Login TOEIC</h3>
                    <p>Masukkan username dan password untuk membuka dashboard TOEIC Anda.</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST">
                    <?php if (isset($_GET['redirect'])): ?>
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect']); ?>">
                    <?php endif; ?>
                    <div class="form-floating">
                        <input type="text" class="form-control" id="username" name="username" placeholder="Username" required>
                        <label for="username">
                            <i class="fas fa-user me-2"></i>Username
                        </label>
                    </div>

                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                        <label for="password">
                            <i class="fas fa-lock me-2"></i>Password
                        </label>
                    </div>

                    <button type="submit" class="btn btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i>Masuk
                    </button>
                </form>

                <div class="login-links">
                    <p class="mb-2">Belum punya akun? <a href="register.php">Buat akun TOEIC</a></p>
                    <p class="mb-0"><a href="index.php"><i class="fas fa-arrow-left me-1"></i>Kembali ke beranda TOEIC</a></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Chatbot -->
    <?php require_once 'includes/chatbot_loader.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        // Add loading animation to login button
        document.querySelector('form').addEventListener('submit', function (e) {
            const btn = document.querySelector('.btn-login');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sedang masuk...';
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }, 3000);
        });

        // Auto-dismiss alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                const btn = a.querySelector('.btn-close');
                if (btn) btn.click();
            });
        }, 5000);
    </script>
</body>

</html>
