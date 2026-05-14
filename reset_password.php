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
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$reset = (!$db_unavailable && $token !== '') ? toeicGetValidPasswordReset($conn, $token) : null;
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($db_unavailable) {
        $error = 'Reset password sementara tidak tersedia karena koneksi database gagal.';
    } elseif (!$reset) {
        $error = 'Link reset password tidak valid atau sudah kedaluwarsa.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } elseif (toeicConsumePasswordReset($conn, $token, $password)) {
        header("Location: login.php?message=" . urlencode('Password berhasil diperbarui. Silakan masuk.'));
        exit();
    } else {
        $error = 'Password gagal diperbarui. Minta link reset baru.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-auth-page tc-public-page">
    <main class="tc-auth-shell">
        <div class="auth-container tc-auth-card">
            <section class="tc-auth-aside" aria-label="TOEIC password reset form">
                <div class="tc-auth-mark">TOEIC</div>
                <span class="tc-eyebrow">Secure Reset</span>
                <h2>Buat password baru.</h2>
                <p>Gunakan link dari email terdaftar sebelum masa berlakunya habis.</p>
            </section>
            <section class="tc-auth-form-panel">
                <div class="tc-auth-top">
                    <div>
                        <span class="tc-panel-label">AKUN SISWA</span>
                        <h1>Reset Password</h1>
                    </div>
                    <a href="login.php" class="tc-icon-link" aria-label="Back to login">
                        <i class="fas fa-arrow-right-to-bracket"></i><span>Masuk</span>
                    </a>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger rounded-3 mb-4 border-0" style="background: #fff1f2; color: #be123c; font-weight: 600;">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$reset && !$error): ?>
                    <div class="alert alert-warning rounded-3 mb-4 border-0">
                        Link reset password tidak valid atau sudah kedaluwarsa.
                    </div>
                    <a href="forgot_password.php" class="tc-button w-100">Minta Link Baru</a>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                        <div class="mb-4">
                            <label class="toeic-field-label" for="password">Password Baru</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Minimal 6 karakter" required autocomplete="new-password">
                        </div>
                        <div class="mb-4">
                            <label class="toeic-field-label" for="confirm_password">Konfirmasi Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Ulangi password baru" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="tc-button w-100 mb-4">
                            <i class="fas fa-key me-2"></i>Simpan Password Baru
                        </button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
