<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

// Check admin login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$syllabus_id = $_GET['id'] ?? 0;
if (!$syllabus_id) {
    header("Location: test_results.php");
    exit();
}

// Fetch syllabus and student info
$stmt = $conn->prepare("
    SELECT us.*, u.full_name, u.username, tr.listening_score, tr.structure_score, tr.reading_score, tr.total_score
    FROM user_syllabus us
    JOIN users u ON us.user_id = u.id_user
    JOIN test_results tr ON us.test_session = tr.test_session
    WHERE us.id = ?
");
$stmt->bind_param("i", $syllabus_id);
$stmt->execute();
$syllabus_row = $stmt->get_result()->fetch_assoc();

if (!$syllabus_row) {
    die("Syllabus not found.");
}

$syllabus = json_decode($syllabus_row['syllabus_content'], true);
$website_title = getWebsiteTitle();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Syllabus - <?php echo htmlspecialchars($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        /* page-specific styles only - base handled by modern-theme.css */
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-9 col-lg-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-edit me-2"></i>Edit Study Plan</h2>
                    <a href="view_result.php?id=<?php echo $syllabus_row['user_id']; // This might be wrong, logic check later ?>"
                        class="btn btn-secondary">Back to Result</a>
                </div>

                <div class="content-card">
                    <h4>Student: <?php echo htmlspecialchars($syllabus_row['full_name']); ?>
                        (<?php echo htmlspecialchars($syllabus_row['username']); ?>)</h4>
                    <p class="text-muted">
                        Scores: L:<?php echo $syllabus_row['listening_score']; ?> |
                        S:<?php echo $syllabus_row['structure_score']; ?> |
                        R:<?php echo $syllabus_row['reading_score']; ?> |
                        Total: <?php echo $syllabus_row['total_score']; ?>
                    </p>
                </div>

                <form id="syllabusForm">
                    <input type="hidden" name="syllabus_id" value="<?php echo $syllabus_id; ?>">

                    <div class="content-card">
                        <label class="form-label h5">Performance Analysis (Weaknesses & Strengths)</label>
                        <textarea class="form-control mb-3" name="analysis"
                            rows="5"><?php echo htmlspecialchars($syllabus['analysis'] ?? ''); ?></textarea>
                        <div class="form-text">Edit this section to provide more detailed feedback on the student's
                            weaknesses.</div>
                    </div>

                    <div class="content-card">
                        <h5>Weekly Schedule</h5>
                        <div id="weeksContainer">
                            <?php if (isset($syllabus['weeks']) && is_array($syllabus['weeks'])): ?>
                                <?php foreach ($syllabus['weeks'] as $wIndex => $week): ?>
                                    <div class="card mb-3 border-primary">
                                        <div class="card-header bg-light">
                                            <div class="row align-items-center">
                                                <div class="col-auto"><strong>Week <?php echo $week['week']; ?></strong></div>
                                                <div class="col"><input type="text" class="form-control form-control-sm"
                                                        name="weeks[<?php echo $wIndex; ?>][theme]"
                                                        value="<?php echo htmlspecialchars($week['theme']); ?>"
                                                        placeholder="Weekly Theme"></div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <input type="hidden" name="weeks[<?php echo $wIndex; ?>][week]"
                                                value="<?php echo $week['week']; ?>">
                                            <?php foreach ($week['activities'] as $aIndex => $activity): ?>
                                                <div class="row mb-2 g-2">
                                                    <div class="col-2">
                                                        <input type="text" class="form-control form-control-sm"
                                                            name="weeks[<?php echo $wIndex; ?>][activities][<?php echo $aIndex; ?>][day]"
                                                            value="<?php echo htmlspecialchars($activity['day']); ?>">
                                                    </div>
                                                    <div class="col">
                                                        <input type="text" class="form-control form-control-sm"
                                                            name="weeks[<?php echo $wIndex; ?>][activities][<?php echo $aIndex; ?>][task]"
                                                            value="<?php echo htmlspecialchars($activity['task']); ?>">
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="content-card">
                        <label class="form-label h5">Recommendations</label>
                        <div id="recommendationsContainer">
                            <?php if (isset($syllabus['recommendations']) && is_array($syllabus['recommendations'])): ?>
                                <?php foreach ($syllabus['recommendations'] as $rIndex => $rec): ?>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control" name="recommendations[]"
                                            value="<?php echo htmlspecialchars($rec); ?>">
                                        <button class="btn btn-outline-danger" type="button"
                                            onclick="this.parentElement.remove()">X</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2"
                            onclick="addRecommendation()">+ Add Recommendation</button>
                    </div>

                    <div class="fixed-bottom p-3 border-top shadow text-end" style="background: var(--dark-bg-secondary);">
                        <button type="submit" class="btn btn-primary btn-lg" id="btnSave"><i
                                class="fas fa-save me-2"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        function addRecommendation() {
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <input type="text" class="form-control" name="recommendations[]" placeholder="New recommendation">
                <button class="btn btn-outline-danger" type="button" onclick="this.parentElement.remove()">X</button>
            `;
            document.getElementById('recommendationsContainer').appendChild(div);
        }

        document.getElementById('syllabusForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('btnSave');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            btn.disabled = true;

            const formData = new FormData(this);

            fetch('ajax_save_syllabus.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Syllabus updated successfully!');
                    } else {
                        alert('Error: ' + data.error);
                    }
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred.');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        });
    </script>
</body>

</html>
?>