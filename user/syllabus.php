<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

// Check login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$syllabus_id = $_GET['id'] ?? 0;
if (!$syllabus_id) {
    header("Location: index.php");
    exit();
}

// Fetch syllabus
$stmt = $conn->prepare("SELECT * FROM user_syllabus WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $syllabus_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$syllabus_row = $result->fetch_assoc();

if (!$syllabus_row) {
    die("Syllabus not found.");
}

$syllabus = json_decode($syllabus_row['syllabus_content'], true);
$website_title = getWebsiteTitle();
$website_logo = getWebsiteLogo();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Personalized Study Plan - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/dark-user.css', 'css/dark-user.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">
    <style>
        body {
            font-family: 'Manrope', sans-serif;
            padding-top: 80px;
            background: linear-gradient(180deg, #faf6ee 0%, #f5efe2 100%);
        }

        .syllabus-header {
            background: linear-gradient(180deg, rgba(255, 253, 248, 0.98), rgba(252, 248, 240, 0.98));
            backdrop-filter: blur(20px);
            padding: 2rem;
            border-radius: 24px;
            box-shadow: 0 18px 42px rgba(21, 39, 66, 0.1);
            margin-bottom: 2rem;
            border-left: 5px solid #152742;
            border: 1px solid rgba(23,38,63,0.08);
            color: var(--toeic-ink);
        }

        .analysis-card {
            background: rgba(21,39,66,0.06);
            border: 1px solid rgba(23,38,63,0.08);
            border-radius: 24px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            color: var(--toeic-ink);
        }

        .week-card {
            background: linear-gradient(180deg, rgba(255, 253, 248, 0.98), rgba(252, 248, 240, 0.98));
            border: 1px solid rgba(23,38,63,0.08);
            border-radius: 24px;
            box-shadow: 0 16px 34px rgba(21,39,66,0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.2s;
            color: var(--toeic-ink);
        }

        .week-card:hover {
            transform: translateY(-3px);
        }

        .week-header {
            background: linear-gradient(135deg, #152742 0%, #21385c 58%, #c5851c 170%);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }

        .week-body {
            padding: 1.5rem;
        }

        .activity-item {
            padding: 0.75rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .day-badge {
            background: rgba(21,39,66,0.08);
            color: var(--toeic-ink);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-right: 1rem;
            min-width: 80px;
            text-align: center;
            border: 1px solid #ddd;
        }

        .recommendation-list li {
            margin-bottom: 0.5rem;
            position: relative;
            padding-left: 1.5rem;
        }

        .recommendation-list li:before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 0;
            color: #1e8078;
        }
    </style>
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="syllabus-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Personalized Study Plan</h2>
                    <p class="text-muted mb-0">Generated on
                        <?php echo date('F j, Y', strtotime($syllabus_row['created_at'])); ?>
                    </p>
                </div>
                <div>
                    <span class="badge bg-primary fs-6 me-2">
                        <i class="fas fa-clock me-1"></i> 4-Week Plan
                    </span>
                    <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back</a>
                    <button onclick="window.print()" class="btn btn-outline-primary ms-2"><i
                            class="fas fa-print me-2"></i>Print</button>
                </div>
            </div>
        </div>

        <?php if (isset($syllabus['analysis'])): ?>
            <div class="analysis-card">
                <h4 class="text-primary"><i class="fas fa-chart-line me-2"></i>Performance Analysis</h4>
                <p class="mb-0 lead fs-6"><?php echo nl2br(htmlspecialchars($syllabus['analysis'])); ?></p>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <h4 class="mb-4"><i class="fas fa-calendar-alt me-2"></i>4-Week Schedule</h4>

                <?php if (isset($syllabus['weeks']) && is_array($syllabus['weeks'])): ?>
                    <?php foreach ($syllabus['weeks'] as $week): ?>
                        <div class="week-card">
                            <div class="week-header d-flex justify-content-between align-items-center">
                                <span>Week <?php echo $week['week']; ?></span>
                                <span
                                    class="badge bg-light text-dark"><?php echo htmlspecialchars($week['theme'] ?? 'General Review'); ?></span>
                            </div>
                            <div class="week-body">
                                <?php if (isset($week['activities']) && is_array($week['activities'])): ?>
                                    <?php foreach ($week['activities'] as $activity): ?>
                                        <div class="activity-item">
                                            <span class="day-badge"><?php echo htmlspecialchars($activity['day']); ?></span>
                                            <span><?php echo htmlspecialchars($activity['task']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-warning">No schedule data available.</div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title text-primary mb-3"><i class="fas fa-lightbulb me-2"></i>Recommendations
                        </h5>
                        <ul class="list-unstyled recommendation-list">
                            <?php if (isset($syllabus['recommendations']) && is_array($syllabus['recommendations'])): ?>
                                <?php foreach ($syllabus['recommendations'] as $rec): ?>
                                    <li><?php echo htmlspecialchars($rec); ?></li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 bg-light">
                    <div class="card-body p-4 text-center">
                        <h5 class="mb-3">Ready to Practice?</h5>
                        <p class="small text-muted mb-4">Apply what you've learned in a new practice session.</p>
                        <a href="test.php?section=listening&start=1&type=trial" class="btn btn-primary w-100">Start
                            Practice Test</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
?>
