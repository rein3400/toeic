<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/toeic_quality_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$syllabus_id = $_GET['id'] ?? 0;
if (!$syllabus_id) {
    toeicRedirectWithFlash('index.php', 'info', 'Study plan akan tersedia setelah Anda punya hasil TOEIC yang bisa dianalisis.');
}

$stmt = $conn->prepare("SELECT * FROM user_syllabus WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $syllabus_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$syllabus_row = $result->fetch_assoc();

if (!$syllabus_row) {
    toeicRedirectWithFlash('index.php', 'error', 'Study plan tidak ditemukan untuk akun ini.');
}

$syllabus = json_decode($syllabus_row['syllabus_content'], true);
$website_title = getWebsiteTitle();
$user_name = $_SESSION['full_name'] ?? 'Student';
$initials = strtoupper(substr($user_name, 0, 1));
if (strpos($user_name, ' ') !== false) {
    $parts = explode(' ', $user_name, 2);
    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Syllabus - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
    <style>
        .week-card { margin-bottom: 2rem; }
        .week-header {
            padding: 1.25rem 1.5rem; background: var(--focus-blue); color: white;
            border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;
        }
        .activity-item {
            display: flex; gap: 1rem; align-items: flex-start; padding: 1rem;
            border-bottom: 1px solid var(--cloud-line);
        }
        .activity-item:last-child { border-bottom: none; }
        .day-label {
            min-width: 80px; padding: 4px 12px; background: rgba(72,127,181,0.1);
            border-radius: 20px; font-size: 11px; font-weight: 800; text-align: center; color: var(--focus-blue);
        }
    </style>
</head>
<body class="tc-user-page tc-syllabus-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="avatar-circle"><?php echo htmlspecialchars($initials); ?></div>
        </div>
    </header>

    <main class="toeic-page-shell">
        <div class="mb-5 d-flex justify-content-between align-items-end flex-wrap gap-4">
            <div>
                <span class="study-kicker">Personal Roadmap</span>
                <h1 class="display-5 mb-2">Your Study Plan</h1>
                <p class="lead text-muted mb-0">Generated on <?php echo date('M j, Y', strtotime($syllabus_row['created_at'])); ?></p>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height:40px; font-size:13px;">
                    <i class="fas fa-print me-2"></i> Print Plan
                </button>
                <a href="index.php" class="study-button study-button-secondary py-2 px-3 min-vh-0" style="min-height:40px; font-size:13px;">
                    Dashboard
                </a>
            </div>
        </div>

        <?php if (isset($syllabus['analysis'])): ?>
            <section class="study-card mb-5" style="background: rgba(72, 127, 181, 0.05); border-left: 6px solid var(--focus-blue) !important;">
                <h2 class="h4 mb-3 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i>AI Analysis</h2>
                <p class="mb-0 fw-medium lead fs-6" style="line-height: 1.8;"><?php echo nl2br(htmlspecialchars($syllabus['analysis'])); ?></p>
            </section>
        <?php endif; ?>

        <div class="row g-5">
            <div class="col-lg-8">
                <h3 class="h4 mb-4 fw-bold uppercase tracking-wider text-muted"><i class="fas fa-calendar-alt me-2"></i>4-Week Schedule</h3>

                <?php if (isset($syllabus['weeks']) && is_array($syllabus['weeks'])): ?>
                    <?php foreach ($syllabus['weeks'] as $week): ?>
                        <div class="study-card p-0 overflow-hidden week-card">
                            <div class="week-header">
                                <div class="fw-bold uppercase tracking-widest small">Week <?php echo $week['week']; ?></div>
                                <span class="badge bg-white text-primary rounded-pill px-3 py-2 fw-bold small">
                                    <?php echo htmlspecialchars($week['theme'] ?? 'Review'); ?>
                                </span>
                            </div>
                            <div class="p-2">
                                <?php if (isset($week['activities']) && is_array($week['activities'])): ?>
                                    <?php foreach ($week['activities'] as $act): ?>
                                        <div class="activity-item">
                                            <div class="day-label uppercase fw-black"><?php echo htmlspecialchars($act['day']); ?></div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($act['task']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <section class="study-card mb-4">
                    <span class="study-kicker">Success Tips</span>
                    <h3 class="h5 mb-4 fw-bold">Recommendations</h3>
                    <ul class="list-unstyled">
                        <?php if (isset($syllabus['recommendations']) && is_array($syllabus['recommendations'])): ?>
                            <?php foreach ($syllabus['recommendations'] as $rec): ?>
                                <li class="mb-3 d-flex gap-3">
                                    <i class="fas fa-lightbulb text-warning mt-1"></i>
                                    <span class="small fw-bold"><?php echo htmlspecialchars($rec); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </section>

                <div class="study-card text-center text-white" style="background: linear-gradient(135deg, var(--academy-blue), var(--focus-blue)); border:none;">
                    <h4 class="h5 fw-bold mb-3">Ready to Practice?</h4>
                    <p class="small opacity-75 mb-4">Apply your knowledge in a timed simulation session.</p>
                    <a href="test_instructions.php?mode=prep" class="study-button w-100">Start Session</a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
