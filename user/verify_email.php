<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/email_verification_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: ../login.php");
    exit();
}

function toeicVerificationSafeRedirect(string $redirect): string {
    $redirect = trim($redirect);
    if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || str_contains($redirect, "\r") || str_contains($redirect, "\n")) {
        return 'index.php';
    }
    if (str_starts_with($redirect, '/user/')) {
        return substr($redirect, 6);
    }
    if (str_starts_with($redirect, 'user/')) {
        return substr($redirect, 5);
    }
    if ($redirect[0] === '/') {
        return '../' . ltrim($redirect, '/');
    }
    return $redirect;
}

$website_title = getWebsiteTitle();
$state = toeicGetUserEmailVerificationState($conn, (int)$_SESSION['user_id']);
$redirect = toeicVerificationSafeRedirect((string)($_GET['redirect'] ?? 'index.php'));

if ($state['is_verified']) {
    header("Location: " . $redirect);
    exit();
}

$notice = '';
if (($_GET['sent'] ?? '') === '1') {
    $notice = 'Email verifikasi sudah dikirim. Silakan cek inbox atau folder spam.';
} elseif (($_GET['limited'] ?? '') === '1') {
    $notice = 'Permintaan verifikasi belum bisa diproses. Coba beberapa menit lagi atau pastikan email sudah benar.';
}

$user_name = $_SESSION['full_name'] ?? 'Student';
$initials = strtoupper(substr($user_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Email - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-user-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="avatar-circle" style="width:40px; height:40px;"><?php echo htmlspecialchars($initials); ?></div>
        </div>
    </header>

    <main class="toeic-page-shell">
        <section class="study-card p-4 p-lg-5 mx-auto" style="max-width: 720px;">
            <span class="study-kicker">Account Security</span>
            <h1 class="h2 fw-bold mb-3">Verifikasi email dulu</h1>
            <p class="text-muted mb-4">Akun TOEIC harus memakai email aktif sebelum membuka dashboard, checkout, atau simulasi.</p>

            <?php if ($notice): ?>
                <div class="alert alert-info border-0 rounded-3">
                    <i class="fas fa-circle-info me-2"></i><?php echo htmlspecialchars($notice); ?>
                </div>
            <?php endif; ?>

            <?php if (!$state['has_email']): ?>
                <div class="alert alert-warning border-0 rounded-3">
                    <i class="fas fa-envelope-open-text me-2"></i>Akun ini belum punya email terdaftar. Tambahkan email aktif lewat profil, lalu sistem akan mengirim link verifikasi.
                </div>
                <a href="profile.php" class="study-button">
                    <i class="fas fa-user-pen me-2"></i>Tambahkan Email
                </a>
            <?php else: ?>
                <div class="p-3 rounded-3 bg-light mb-4">
                    <div class="small text-muted fw-bold mb-1">Email terdaftar</div>
                    <div class="fw-bold"><?php echo htmlspecialchars($state['email']); ?></div>
                </div>
                <form method="POST" action="resend_verification.php">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                    <button type="submit" class="study-button">
                        <i class="fas fa-paper-plane me-2"></i>Kirim Ulang Verifikasi
                    </button>
                    <a href="profile.php" class="study-button study-button-secondary ms-2">
                        <i class="fas fa-pen me-2"></i>Ubah Email
                    </a>
                </form>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
