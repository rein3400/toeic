<?php
// Enable error logging for Railway
if (getenv('MYSQLHOST')) {
    ini_set('log_errors', '1');
    ini_set('error_log', '/tmp/php_errors.log');
}

require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';
require_once 'includes/db_utils.php';

// Fix for MySQL 8.0+ Strict Mode
if (isset($conn)) {
    $conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
    $conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode,'STRICT_TRANS_TABLES',''));");
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: admin/index.php");
    } else {
        header("Location: user/index.php");
    }
    exit();
}

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$db_unavailable = !($conn instanceof mysqli);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if ($db_unavailable) {
            throw new Exception("Registration is temporarily unavailable because the database connection failed.");
        }

        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $full_name = trim($_POST['full_name']);
        $redirect = $_POST['redirect'] ?? '';

        // Validation
        if (empty($username) || empty($password) || empty($confirm_password) || empty($full_name)) {
            $error = "All fields are required.";
        } elseif (strlen($username) < 3) {
            $error = "Username must be at least 3 characters long.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = "Username can only contain letters, numbers, and underscores.";
        } else {
            $stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ?");
            if (!$stmt) throw new Exception("Database prepare error: " . $conn->error);
            $stmt->bind_param("s", $username);
            $stmt->execute();

            if ($stmt->get_result()->num_rows > 0) {
                $error = "Username already exists. Please choose a different username.";
                $stmt->close();
            } else {
                $stmt->close();
                $conn->begin_transaction();
                try {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'student')");
                    if (!$stmt) throw new Exception("Database prepare error: " . $conn->error);
                    $stmt->bind_param("sss", $username, $hashed_password, $full_name);
                    if (!$stmt->execute()) throw new Exception("Failed to insert user: " . $stmt->error);

                    $new_user_id = $stmt->insert_id;
                    $stmt->close();

                    // Grant 1 free TOEIC starter credit (once per user, defensive check)
                    $trialCount = 0;
                    $has_transaction_ref = checkColumnExists($conn, 'user_purchases', 'transaction_ref');
                    if ($has_transaction_ref) {
                        $trialCheck = $conn->prepare(
                            "SELECT COUNT(*) FROM user_purchases WHERE user_id = ? AND transaction_ref = 'FREE_TRIAL'"
                        );
                        if (!$trialCheck) {
                            throw new Exception("Database prepare error: " . $conn->error);
                        }
                        $trialCheck->bind_param('i', $new_user_id);
                        $trialCheck->execute();
                        $trialCheck->bind_result($trialCount);
                        $trialCheck->fetch();
                        $trialCheck->close();
                    }

                    if ($trialCount === 0) {
                        grantTestCredit($conn, $new_user_id, 'toeic', 'FREE_TRIAL');
                    }

                    // Auto-Login after registration
                    $_SESSION['user_id'] = $new_user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['role'] = 'student';

                    // Link Pending Guest Payment (if any)
                    if (isset($_SESSION['pending_guest_payment'])) {
                        $guest_pay = $_SESSION['pending_guest_payment'];
                        $stmt_pay = $conn->prepare("INSERT INTO payment_transactions (user_id, transaction_id, test_type, amount, payment_method, status) VALUES (?, ?, ?, ?, 'QRIS', 'success')");
                        $stmt_pay->bind_param("issd", $new_user_id, $guest_pay['transaction_id'], $guest_pay['test_type'], $guest_pay['amount']);
                        if (!$stmt_pay->execute()) throw new Exception("Failed to link payment: " . $stmt_pay->error);
                        $stmt_pay->close();

                        $stmt_acc = $conn->prepare("INSERT INTO user_purchases (user_id, test_type, status) VALUES (?, ?, 'active')");
                        $stmt_acc->bind_param("is", $new_user_id, $guest_pay['test_type']);
                        if (!$stmt_acc->execute()) throw new Exception("Failed to grant access: " . $stmt_acc->error);
                        $stmt_acc->close();

                        unset($_SESSION['pending_guest_payment']);
                    }

                    $conn->commit();
                    $success = "Account created successfully!";

                    // Restrict redirect to safe relative paths only (prevent open redirect)
                    $redirectUrl = "user/index.php";
                    if (!empty($redirect) && preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $redirect) && strpos($redirect, '//') === false) {
                        $redirectUrl = $redirect;
                    }

                    header("Location: " . $redirectUrl);
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        $error = "Registration failed. Please try again. (" . htmlspecialchars($e->getMessage()) . ")";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Register</title>
    <?php echo getFaviconHTML(); ?>
    <meta name="description" content="Buat akun TOEIC untuk mengakses full simulation, practice mode Part 1-7, dan score report TOEIC.">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="user/css/mobile-responsive.css" rel="stylesheet">

    <style>
        body { color: var(--rg-text); }

        .register-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .register-wrapper {
            display: flex;
            max-width: 1000px;
            width: 100%;
            border-radius: 32px;
            overflow: hidden;
            background: rgba(255,253,248,0.95);
            border: 1px solid rgba(231,223,207,0.92);
            box-shadow: 0 28px 80px rgba(15,31,61,0.12);
        }

        /* Left info panel */
        .register-info {
            background: linear-gradient(135deg, #0f1f3d 0%, #1d3561 55%, #d68300 180%);
            color: white;
            padding: 3rem;
            flex: 0 0 45%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .register-info::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -30%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .register-info::after {
            content: '';
            position: absolute;
            bottom: -25%;
            left: -15%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
        }

        .register-info .info-content { position: relative; z-index: 1; }

        .register-info .info-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
        }

        .register-info h2 {
            font-family: var(--rg-display);
            font-weight: 800;
            font-size: 2rem;
            margin-bottom: 0.75rem;
            line-height: 1.2;
        }

        .register-info .subtitle {
            font-size: 1.05rem;
            opacity: 0.85;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0 0 2rem 0;
        }

        .feature-list li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            font-weight: 500;
            font-size: 0.95rem;
            opacity: 0.9;
        }

        .feature-list li i {
            margin-right: 0.75rem;
            width: 20px;
            color: #ffd48a;
            font-size: 1rem;
        }

        .stats-row {
            display: flex;
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .stat-box {
            text-align: center;
        }

        .stat-box .value {
            font-size: 1.5rem;
            font-weight: 800;
            display: block;
        }

        .stat-box .label {
            font-size: 0.75rem;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Right form panel */
        .register-form-panel {
            flex: 1;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .register-form-panel h3 {
            font-size: 1.6rem;
            font-family: var(--rg-display);
            font-weight: 800;
            color: var(--rg-text);
            margin-bottom: 0.35rem;
        }

        .register-form-panel .form-subtitle {
            color: var(--rg-text-muted);
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }

        .form-floating {
            margin-bottom: 1.15rem;
        }

        .form-floating .form-control {
            border: 2px solid var(--rg-border);
            border-radius: 12px;
            padding: 1rem 0.75rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--rg-bg-white);
            color: var(--rg-text);
            height: 56px;
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

        .form-hint {
            font-size: 0.78rem;
            color: var(--rg-text-muted);
            margin-top: 0.25rem;
        }

        .btn-register {
            background: linear-gradient(135deg, var(--toeic-amber), var(--toeic-amber-deep));
            border: none;
            border-radius: 12px;
            padding: 0.85rem 2rem;
            font-weight: 700;
            font-size: 1.05rem;
            color: #1d1400;
            transition: all 0.3s ease;
            width: 100%;
            margin-bottom: 1rem;
        }

        .btn-register:hover {
            background: linear-gradient(135deg, #f5b53f, var(--toeic-amber-deep));
            color: #1d1400;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(240,161,26,0.28);
        }

        .login-link {
            text-align: center;
            padding-top: 1rem;
            border-top: 1px solid var(--rg-border-light);
        }

        .login-link a {
            color: var(--rg-primary);
            font-weight: 600;
            text-decoration: none;
        }

        .login-link a:hover { text-decoration: underline; }
        .login-link p { color: var(--rg-text-muted); font-size: 0.9rem; }

        .alert { border-radius: 12px; border: none; }
        .alert-danger { background: #FEF2F2; border: 1px solid #FECACA; color: #B91C1C; }
        .alert-success { background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; }

        .password-strength { font-size: 0.8rem; margin-top: 0.35rem; }
        .strength-weak { color: #EF4444; }
        .strength-medium { color: #F59E0B; }
        .strength-strong { color: #10B981; }

        .form-check-input:checked { background-color: var(--rg-primary); border-color: var(--rg-primary); }
        .form-check-input:focus { border-color: var(--rg-primary); box-shadow: 0 0 0 3px var(--rg-primary-light); }
        .form-check-label { font-size: 0.88rem; color: var(--rg-text-secondary); }
        .form-check-label a { color: var(--rg-primary); font-weight: 600; }

        @media (max-width: 768px) {
            .register-info { display: none; }
            .register-form-panel { padding: 2rem; }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-wrapper rg-fade-in">
            <!-- Left Info Panel -->
            <div class="register-info">
                <div class="info-content">
                    <div class="info-icon">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <h2>Bangun Basis Skor TOEIC Anda</h2>
                    <p class="subtitle">Buat akun sekali untuk mengakses full simulation, practice mode per part, dan riwayat score TOEIC yang terpusat.</p>
                    
                    <ul class="feature-list">
                        <li><i class="fas fa-headphones"></i> Full simulation 200 soal</li>
                        <li><i class="fas fa-bullseye"></i> Practice mode Part 1-7</li>
                        <li><i class="fas fa-chart-line"></i> Weakness map dan score trend</li>
                        <li><i class="fas fa-volume-up"></i> Secure audio TOEIC</li>
                        <li><i class="fas fa-mobile-alt"></i> Nyaman di desktop dan mobile</li>
                        <li><i class="fas fa-user-shield"></i> Proctoring-ready flow</li>
                    </ul>

                    <div class="stats-row">
                        <div class="stat-box">
                            <span class="value">200</span>
                            <span class="label">Soal</span>
                        </div>
                        <div class="stat-box">
                            <span class="value">7</span>
                            <span class="label">Part</span>
                        </div>
                        <div class="stat-box">
                            <span class="value">100%</span>
                            <span class="label">Gratis Daftar</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Form Panel -->
            <div class="register-form-panel">
                <h3>Buat Akun TOEIC</h3>
                <p class="form-subtitle">Isi data di bawah untuk membuka dashboard TOEIC Anda.</p>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form action="register.php" method="POST" id="registerForm">
                    <?php if (isset($_GET['redirect'])): ?>
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect']); ?>">
                    <?php endif; ?>

                    <div class="form-floating">
                        <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Full Name" required>
                        <label for="full_name"><i class="fas fa-user me-2"></i>Nama Lengkap</label>
                    </div>

                    <div class="form-floating">
                        <input type="text" class="form-control" id="username" name="username" placeholder="Username" required minlength="3" pattern="[a-zA-Z0-9_]+">
                        <label for="username"><i class="fas fa-at me-2"></i>Username</label>
                        <div class="form-hint">Hanya huruf, angka, dan underscore</div>
                    </div>

                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required minlength="6">
                        <label for="password"><i class="fas fa-lock me-2"></i>Password</label>
                        <div id="passwordStrength" class="password-strength"></div>
                    </div>

                    <div class="form-floating">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required minlength="6">
                        <label for="confirm_password"><i class="fas fa-check me-2"></i>Konfirmasi Password</label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="terms" required>
                        <label class="form-check-label" for="terms">
                            Saya setuju dengan <a href="#">Syarat & Ketentuan</a> dan <a href="#">Kebijakan Privasi</a>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-register">
                        <i class="fas fa-user-plus me-2"></i>Buat Akun
                    </button>
                </form>

                <div class="login-link">
                    <p class="mb-0">Sudah punya akun? <a href="login.php"><i class="fas fa-sign-in-alt me-1"></i>Login TOEIC</a></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Chatbot -->
    <?php require_once 'includes/chatbot_loader.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        // Password strength checker
        function checkPasswordStrength(password) {
            const div = document.getElementById('passwordStrength');
            let s = 0;
            if (password.length >= 6) s++;
            if (password.match(/[a-z]/)) s++;
            if (password.match(/[A-Z]/)) s++;
            if (password.match(/[0-9]/)) s++;
            if (password.match(/[^a-zA-Z0-9]/)) s++;

            const labels = [
                ['', ''],
                ['<span class="strength-weak"><i class="fas fa-circle me-1"></i>Lemah</span>', ''],
                ['<span class="strength-medium"><i class="fas fa-circle me-1"></i>Sedang</span>', ''],
                ['<span class="strength-medium"><i class="fas fa-circle me-1"></i>Sedang</span>', ''],
                ['<span class="strength-strong"><i class="fas fa-circle me-1"></i>Kuat</span>', ''],
                ['<span class="strength-strong"><i class="fas fa-circle me-1"></i>Sangat Kuat</span>', '']
            ];
            div.innerHTML = password ? labels[s][0] : '';
        }

        function validatePasswordMatch() {
            const p = document.getElementById('password').value;
            const c = document.getElementById('confirm_password');
            if (c.value && p !== c.value) {
                c.setCustomValidity('Passwords do not match');
                c.classList.add('is-invalid');
            } else {
                c.setCustomValidity('');
                c.classList.remove('is-invalid');
            }
        }

        document.getElementById('password').addEventListener('input', function() {
            checkPasswordStrength(this.value);
            validatePasswordMatch();
        });
        document.getElementById('confirm_password').addEventListener('input', validatePasswordMatch);

        document.getElementById('username').addEventListener('input', function() {
            if (this.value && !/^[a-zA-Z0-9_]+$/.test(this.value)) {
                this.setCustomValidity('Username can only contain letters, numbers, and underscores');
                this.classList.add('is-invalid');
            } else {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
            }
        });

        document.getElementById('registerForm').addEventListener('submit', function(e) {
            if (document.getElementById('password').value !== document.getElementById('confirm_password').value) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                const btn = a.querySelector('.btn-close');
                if (btn) btn.click();
            });
        }, 5000);

        <?php if (!empty($success)): ?>
        setTimeout(() => { window.location.href = 'login.php'; }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>
