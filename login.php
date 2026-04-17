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
$db_unavailable = !($conn instanceof mysqli);
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($db_unavailable) {
        $error = "Login is temporarily unavailable because the database connection failed.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $redirect = $_POST['redirect'] ?? '';

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
                $error = "Invalid credentials.";
            }
        } else {
            $error = "Invalid credentials.";
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
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/ruangguru-theme.css" rel="stylesheet">
    <link href="assets/css/toeic-frontend.css" rel="stylesheet">
    <link href="user/css/mobile-responsive.css" rel="stylesheet">
</head>
<body>
    <main class="toeic-page-shell">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
                <section class="toeic-form-panel h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="toeic-kicker mb-3">Powering global communication</div>
                        <h1 class="display-5 text-white mb-3">Sign in to your TOEIC global English skills workspace.</h1>
                        <p class="text-white-50 mb-4">
                            Return to a TOEIC-only environment built for full simulation, practice simulation, score reporting, analytics, and review-led improvement.
                        </p>
                        <ul class="toeic-list-check">
                            <li>Access the TOEIC Listening and Reading simulator.</li>
                            <li>Review full score reports and recent attempts.</li>
                            <li>Continue practice without leaving the TOEIC flow.</li>
                        </ul>
                    </div>
                    <div class="small text-white-50 mt-4">
                        TOEIC Listening and Reading Test
                    </div>
                </section>
            </div>
            <div class="col-lg-7">
                <section class="toeic-form-shell h-100">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                        <div>
                            <div class="toeic-kicker mb-3">The TOEIC Tests</div>
                            <h2 class="display-6 mb-2">Welcome back.</h2>
                            <p class="toeic-copy mb-0">Enter your account details to continue building your TOEIC score with the same simulator, timing, and reporting structure.</p>
                        </div>
                        <a href="index.php" class="btn btn-outline-secondary">Back to Home</a>
                    </div>

                    <div class="d-flex align-items-center gap-3 mb-4">
                        <?php if (!empty($website_logo) && file_exists($website_logo)): ?>
                            <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo" style="height: 40px; width: auto;">
                        <?php else: ?>
                            <div class="toeic-feature-icon"><i class="fas fa-briefcase"></i></div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($website_title); ?></div>
                            <div class="small text-muted">TOEIC-only simulation platform</div>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger rounded-4 border-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                        <div class="col-12">
                            <label class="toeic-field-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required autocomplete="username">
                        </div>
                        <div class="col-12">
                            <label class="toeic-field-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                        </div>
                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-warning w-100">Sign In</button>
                        </div>
                    </form>

                    <div class="toeic-band mt-4">
                        <div class="toeic-eyebrow mb-3">Need access?</div>
                        <h3 class="h3 mb-2">Create your TOEIC account.</h3>
                        <p class="toeic-copy mb-3">Open an account to access full simulation, practice simulation, and TOEIC score reporting.</p>
                        <a href="register.php<?php echo $redirect !== '' ? '?redirect=' . urlencode($redirect) : ''; ?>" class="btn btn-outline-warning">Create Account</a>
                    </div>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
