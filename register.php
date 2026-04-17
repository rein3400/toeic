<?php
if (getenv('MYSQLHOST')) {
    ini_set('log_errors', '1');
    ini_set('error_log', '/tmp/php_errors.log');
}

require_once 'includes/session_handler.php';
require_once 'includes/config.php';
require_once 'includes/settings.php';
require_once 'includes/db_utils.php';

if (isset($conn)) {
    $conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
    $conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode,'STRICT_TRANS_TABLES',''));");
}

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
            if (!$stmt) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
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
                    if (!$stmt) {
                        throw new Exception("Database prepare error: " . $conn->error);
                    }
                    $stmt->bind_param("sss", $username, $hashed_password, $full_name);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to insert user: " . $stmt->error);
                    }

                    $new_user_id = $stmt->insert_id;
                    $stmt->close();

                    $trialCount = 0;
                    $has_transaction_ref = checkColumnExists($conn, 'user_purchases', 'transaction_ref');
                    if ($has_transaction_ref) {
                        $trialCheck = $conn->prepare("SELECT COUNT(*) FROM user_purchases WHERE user_id = ? AND transaction_ref = 'FREE_TRIAL'");
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

                    $_SESSION['user_id'] = $new_user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['role'] = 'student';

                    if (isset($_SESSION['pending_guest_payment'])) {
                        $guest_pay = $_SESSION['pending_guest_payment'];
                        $stmt_pay = $conn->prepare("INSERT INTO payment_transactions (user_id, transaction_id, test_type, amount, payment_method, status) VALUES (?, ?, ?, ?, 'QRIS', 'success')");
                        $stmt_pay->bind_param("issd", $new_user_id, $guest_pay['transaction_id'], $guest_pay['test_type'], $guest_pay['amount']);
                        if (!$stmt_pay->execute()) {
                            throw new Exception("Failed to link payment: " . $stmt_pay->error);
                        }
                        $stmt_pay->close();

                        $stmt_acc = $conn->prepare("INSERT INTO user_purchases (user_id, test_type, status) VALUES (?, ?, 'active')");
                        $stmt_acc->bind_param("is", $new_user_id, $guest_pay['test_type']);
                        if (!$stmt_acc->execute()) {
                            throw new Exception("Failed to grant access: " . $stmt_acc->error);
                        }
                        $stmt_acc->close();

                        unset($_SESSION['pending_guest_payment']);
                    }

                    $conn->commit();
                    $success = "Account created successfully!";

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

$redirect = $_GET['redirect'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Register</title>
    <?php echo getFaviconHTML(); ?>
    <meta name="description" content="Create your TOEIC account to access full simulation, practice simulation, and score reporting.">
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
                        <div class="toeic-kicker mb-3">The TOEIC tests</div>
                        <h1 class="display-5 text-white mb-3">Create a TOEIC account and start standardized practice.</h1>
                        <p class="text-white-50 mb-4">
                            Build your TOEIC workspace around a full Listening and Reading simulator, repeatable practice flow, score reports, analytics, and package activation in one place.
                        </p>
                        <ul class="toeic-list-check">
                            <li>Receive a TOEIC starter credit after registration.</li>
                            <li>Access practice simulation and full simulation flows.</li>
                            <li>Review analytics and score reports from the same account.</li>
                        </ul>
                    </div>
                    <div class="small text-white-50 mt-4">Why standardized English proficiency assessment matters for serious preparation.</div>
                </section>
            </div>
            <div class="col-lg-7">
                <section class="toeic-form-shell h-100">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                        <div>
                            <div class="toeic-kicker mb-3">Create account</div>
                            <h2 class="display-6 mb-2">Open your TOEIC simulator workspace.</h2>
                            <p class="toeic-copy mb-0">Set up your account to enter a TOEIC-only environment designed for score visibility, disciplined timing, and repeatable progress.</p>
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
                    <?php elseif ($success): ?>
                        <div class="alert alert-success rounded-4 border-0 mb-4"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                        <div class="col-md-6">
                            <label class="toeic-field-label" for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" placeholder="Enter your full name" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="toeic-field-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" placeholder="Choose a username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="toeic-field-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="toeic-field-label" for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
                        </div>
                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-warning w-100">Create Account</button>
                        </div>
                    </form>

                    <div class="toeic-band mt-4">
                        <div class="toeic-eyebrow mb-3">Already have access?</div>
                        <h3 class="h3 mb-2">Sign in to continue your TOEIC preparation.</h3>
                        <p class="toeic-copy mb-3">Return to your dashboard to launch a simulator, review results, or continue a practice cycle.</p>
                        <a href="login.php<?php echo $redirect !== '' ? '?redirect=' . urlencode($redirect) : ''; ?>" class="btn btn-outline-warning">Sign In</a>
                    </div>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
