<?php
require_once 'includes/session_handler.php';
if (isset($_SESSION['user_id'])) {
    header("Location: user/index.php");
    exit();
}

require_once 'includes/config.php';
require_once 'includes/settings.php';
require_once 'includes/password_reset_helper.php';

$website_title = getWebsiteTitle();
$db_unavailable = !($conn instanceof mysqli);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($db_unavailable) {
        $error = 'Reset password sementara tidak tersedia karena koneksi database gagal.';
    } elseif (!toeicPasswordResetEnabled()) {
        $error = 'Fitur lupa password sedang dinonaktifkan.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Masukkan email terdaftar yang valid.';
    } else {
        toeicCreatePasswordReset($conn, $email);
        $notice = 'Jika email tersebut terdaftar, link reset password sudah dikirim.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-auth-page tc-public-page">
    <main class="tc-auth-shell">
        <div class="auth-container tc-auth-card">
            <section class="tc-auth-aside" aria-label="TOEIC password reset overview">
                <div class="tc-auth-mark">TOEIC</div>
                <span class="tc-eyebrow">Account Recovery</span>
                <h2>Reset akses dengan email terdaftar.</h2>
                <p>Masukkan email akun, lalu buka link verifikasi untuk membuat password baru.</p>
            </section>
            <section class="tc-auth-form-panel">
                <div class="tc-auth-top">
                    <div>
                        <span class="tc-panel-label">AKUN SISWA</span>
                        <h1>Lupa Password</h1>
                    </div>
                    <a href="login.php" class="tc-icon-link" aria-label="Back to login">
                        <i class="fas fa-arrow-right-to-bracket"></i><span>Masuk</span>
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
                    <div class="mb-4">
                        <label class="toeic-field-label" for="email">Email Terdaftar</label>
                        <input type="email" id="email" name="email" class="form-control" placeholder="nama@email.com" required autocomplete="email">
                    </div>
                    <button type="submit" class="tc-button w-100 mb-4">
                        <i class="fas fa-paper-plane me-2"></i>Kirim Link Reset
                    </button>
                </form>

                <div class="tc-auth-foot">
                    <p class="text-muted mb-0">Ingat password?</p>
                    <a href="login.php" class="fw-bold text-decoration-none" style="color: var(--focus-blue);">Masuk ke akun <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
