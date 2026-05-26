<?php
require_once 'includes/session_handler.php';
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
require_once 'includes/email_verification_helper.php';

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$db_unavailable = !($conn instanceof mysqli);
$error = '';
$notice = '';

if (!$db_unavailable) {
    toeicEnsureEmailVerificationSchema($conn);
}

if (($_GET['registered'] ?? '') === '1') {
    $notice = (($_GET['verify_email'] ?? '') === '1')
        ? 'Akun berhasil dibuat. Silakan cek email untuk verifikasi, lalu masuk.'
        : 'Akun berhasil dibuat. Silakan masuk untuk melanjutkan.';
} elseif (($_GET['message'] ?? '') === 'logged_out') {
    $notice = 'Anda sudah logout dari sesi sebelumnya.';
} elseif (!empty($_GET['message'])) {
    $notice = trim((string)$_GET['message']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($db_unavailable) {
        $error = "Login sementara tidak tersedia karena koneksi database gagal.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $redirect = $_POST['redirect'] ?? '';

        $id_col = getUsersIdColumn($conn);
        $stmt = $conn->prepare("SELECT $id_col as user_id, username, password, full_name, role, email, email_verified_at FROM users WHERE username = ?");
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

                if ($user['role'] !== 'admin' && toeicUserNeedsEmailVerification($conn, (int)$user['user_id'])) {
                    $verify_redirect = $redirect !== '' ? $redirect : 'user/index.php';
                    header("Location: user/verify_email.php?redirect=" . urlencode($verify_redirect));
                    exit();
                }

                if (!empty($redirect)) {
                    header("Location: " . $redirect);
                    exit();
                }

                if ($user['role'] === 'admin') {
                    header("Location: admin/index.php");
                } else {
                    header("Location: user/index.php");
                }
                exit();
            } else {
                $error = "Username atau password salah.";
            }
        } else {
            $error = "Username atau password salah.";
        }
        $stmt->close();
    }
}

$redirect = $_GET['redirect'] ?? '';
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
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-auth-page tc-public-page">
    <main class="tc-auth-shell">
        <div class="auth-container tc-auth-card">
            <section class="tc-auth-aside" aria-label="TOEIC login overview">
                <div class="tc-auth-mark">TOEIC</div>
                <span class="tc-eyebrow">Score Cockpit</span>
                <h2>Masuk ke ruang latihan TOEIC.</h2>
                <p>Lanjutkan target skor, part lemah, dan simulasi terakhir tanpa banyak langkah.</p>
                <div class="tc-auth-score">
                    <span>Total score</span>
                    <strong>745</strong>
                    <small>Target kerja: 800+</small>
                </div>
                <div class="tc-auth-mini-grid" aria-label="TOEIC progress preview">
                    <span><strong>390</strong>Listening</span>
                    <span><strong>355</strong>Reading</span>
                    <span><strong>P5</strong>Next focus</span>
                </div>
            </section>
            <section class="tc-auth-form-panel">
                <div class="tc-auth-top">
                    <div>
                        <span class="tc-panel-label">AKSES SISWA</span>
                        <h1>Masuk</h1>
                    </div>
                    <a href="index.php" class="tc-icon-link" aria-label="Back to homepage">
                        <i class="fas fa-house"></i><span>Beranda</span>
                    </a>
                </div>

                <?php if ($notice): ?>
                    <div class="alert alert-success rounded-3 mb-4 border-0" style="background: #f0fdf4; color: #15803d; font-weight: 600;">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger rounded-3 mb-4 border-0" style="background: #fff1f2; color: #be123c; font-weight: 600;">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                    <div class="mb-4">
                        <label class="toeic-field-label" for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" placeholder="your_username" required autocomplete="username">
                    </div>
                    <div class="mb-4">
                        <label class="toeic-field-label" for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                    </div>
                    <div class="d-flex justify-content-end mb-4">
                        <a href="forgot_password.php" class="fw-bold text-decoration-none small" style="color: var(--focus-blue);">Lupa password?</a>
                    </div>
                    <button type="submit" class="tc-button w-100 mb-4">
                        <i class="fas fa-arrow-right-to-bracket me-2"></i>Masuk ke Akun
                    </button>
                </form>

                <div class="tc-auth-foot">
                    <p class="text-muted mb-0">Belum punya akun?</p>
                    <a href="register.php<?php echo $redirect !== '' ? '?redirect=' . urlencode($redirect) : ''; ?>" class="fw-bold text-decoration-none" style="color: var(--focus-blue);">Buat akun gratis <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
