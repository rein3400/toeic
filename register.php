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

$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($full_name) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = "Semua field wajib diisi.";
    } elseif ($password !== $confirm_password) {
        $error = "Konfirmasi password tidak cocok.";
    } elseif (strlen($password) < 6) {
        $error = "Password minimal 6 karakter.";
    } else {
        $stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Username sudah digunakan.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'student';
            $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $full_name, $username, $hashed_password, $role);
            if ($stmt->execute()) {
                $new_user_id = $conn->insert_id;
                grantTestCredit($conn, $new_user_id, 'toeic', 'FREE_TRIAL');
                header("Location: login.php?registered=1");
                exit();
            } else {
                $error = "Pendaftaran gagal: " . $conn->error;
            }
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
    <title><?php echo htmlspecialchars($website_title); ?> - Register</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body class="tc-auth-page tc-public-page">
    <main class="tc-auth-shell">
        <div class="auth-container tc-auth-card">
            <section class="tc-auth-aside" aria-label="TOEIC registration overview">
                <div class="tc-auth-mark">TOEIC</div>
                <span class="tc-eyebrow">Score Cockpit</span>
                <h2>Buat akun latihan TOEIC.</h2>
                <p>Mulai dari skor awal, lalu sistem bantu arahkan part yang perlu dilatih berikutnya.</p>
                <div class="tc-auth-score">
                    <span>Starting plan</span>
                    <strong>200</strong>
                    <small>Listening + Reading items</small>
                </div>
                <div class="tc-auth-mini-grid" aria-label="TOEIC setup preview">
                    <span><strong>7</strong>Parts</span>
                    <span><strong>2</strong>Skills</span>
                    <span><strong>AI</strong>Review</span>
                </div>
            </section>
            <section class="tc-auth-form-panel">
                <div class="tc-auth-top">
                    <div>
                        <span class="tc-panel-label">SISWA BARU</span>
                        <h1>Buat Akun</h1>
                    </div>
                    <a href="login.php" class="tc-icon-link" aria-label="Back to login page">
                        <i class="fas fa-arrow-right-to-bracket"></i><span>Masuk</span>
                    </a>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger rounded-3 mb-4 border-0" style="background: #fff1f2; color: #be123c; font-weight: 600;">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success rounded-3 mb-4 border-0" style="background: #f0fdf4; color: #15803d; font-weight: 600;">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="toeic-field-label" for="full_name">Nama Lengkap</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" placeholder="Raka Pratama" required>
                        </div>
                        <div class="col-12">
                            <label class="toeic-field-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" placeholder="choose_username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="toeic-field-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <div class="col-md-6">
                            <label class="toeic-field-label" for="confirm_password">Konfirmasi</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <div class="col-12 pt-3">
                            <button type="submit" class="tc-button w-100">
                                <i class="fas fa-user-plus me-2"></i>Buat Akun Gratis
                            </button>
                        </div>
                    </div>
                </form>

                <div class="tc-auth-foot small">
                    Dengan mendaftar, Anda menyetujui <a href="#" class="text-decoration-none">Ketentuan Layanan</a> dan <a href="#" class="text-decoration-none">Kebijakan Privasi</a>.
                </div>
            </section>
        </div>
    </main>
</body>
</html>
