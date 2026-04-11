<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/skill_taxonomy.php';

// Debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get website settings
$website_title = getWebsiteTitle();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_passage') {
            $judul = trim($_POST['judul']);
            $isi_teks = trim($_POST['isi_teks']);
            $topik = trim($_POST['topik']);

            $stmt = $conn->prepare("INSERT INTO teks_bacaan (judul, isi_teks, topik) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $judul, $isi_teks, $topik);

            if ($stmt->execute()) {
                $passage_id = $conn->insert_id;
                $success = "Passage added successfully with ID: " . $passage_id;
            } else {
                $error = "Failed to add passage: " . $conn->error;
            }
        } elseif ($_POST['action'] == 'add_question') {
            $id_teks = $_POST['id_teks'];
            $pertanyaan = trim($_POST['pertanyaan']);
            $opsi_a = trim($_POST['opsi_a']);
            $opsi_b = trim($_POST['opsi_b']);
            $opsi_c = trim($_POST['opsi_c']);
            $opsi_d = trim($_POST['opsi_d']);
            $jawaban_benar = $_POST['jawaban_benar'];

            // Simple insert with auto increment (with auto-fix schema)
            try {
                $question_type = !empty($_POST['question_type']) ? $_POST['question_type'] : null;
                $stmt = $conn->prepare("INSERT INTO soal_reading (id_teks, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssssss", $id_teks, $pertanyaan, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar, $question_type);

                if ($stmt->execute()) {
                    $success = "Question added successfully with ID: " . $conn->insert_id;
                } else {
                    throw new Exception($conn->error, $conn->errno);
                }
            } catch (Exception $e) {
                // Check for "Field doesn't have a default value" error (1364 for id_soal)
                if ($e->getCode() == 1364 && strpos($e->getMessage(), "id_soal") !== false) {
                    // Auto-fix: Add AUTO_INCREMENT to id_soal
                    $conn->query("ALTER TABLE soal_reading MODIFY COLUMN id_soal INT AUTO_INCREMENT PRIMARY KEY");

                    // Retry insert
                    $stmt = $conn->prepare("INSERT INTO soal_reading (id_teks, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssssss", $id_teks, $pertanyaan, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar, $question_type);

                    if ($stmt->execute()) {
                        $success = "Question added successfully (Schema fixed automatically)! ID: " . $conn->insert_id;
                    } else {
                        $error = "Failed to add question after schema fix: " . $conn->error;
                    }
                } else {
                    $error = "Failed to add question: " . $e->getMessage();
                }
            }

        } elseif ($_POST['action'] == 'edit_question') {
            $id_soal = $_POST['id_soal'];
            $id_teks = $_POST['id_teks'];
            $pertanyaan = trim($_POST['pertanyaan']);
            $opsi_a = trim($_POST['opsi_a']);
            $opsi_b = trim($_POST['opsi_b']);
            $opsi_c = trim($_POST['opsi_c']);
            $opsi_d = trim($_POST['opsi_d']);
            $jawaban_benar = $_POST['jawaban_benar'];
            $question_type = !empty($_POST['question_type']) ? $_POST['question_type'] : null;

            $stmt = $conn->prepare("UPDATE soal_reading SET id_teks=?, pertanyaan=?, opsi_a=?, opsi_b=?, opsi_c=?, opsi_d=?, jawaban_benar=?, question_type=? WHERE id_soal=?");
            $stmt->bind_param("isssssssi", $id_teks, $pertanyaan, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar, $question_type, $id_soal);

            if ($stmt->execute()) {
                $success = "Question updated successfully!";
            } else {
                $error = "Failed to update question: " . $conn->error;
            }
        }
    }
}

// Handle delete operations
if (isset($_GET['delete_passage'])) {
    $id = (int) $_GET['delete_passage'];

    // Delete related questions first
    $conn->query("DELETE FROM soal_reading WHERE id_teks = $id");

    // Delete passage
    if ($conn->query("DELETE FROM teks_bacaan WHERE id_teks = $id")) {
        $success = "Passage and related questions deleted successfully!";
    } else {
        $error = "Failed to delete passage: " . $conn->error;
    }
}

if (isset($_GET['delete_question'])) {
    $id = (int) $_GET['delete_question'];

    if ($conn->query("DELETE FROM soal_reading WHERE id_soal = $id")) {
        $success = "Question deleted successfully!";
    } else {
        $error = "Failed to delete question: " . $conn->error;
    }
}

// Get data for display
$passages = $conn->query("SELECT * FROM teks_bacaan ORDER BY id_teks");

// Get specific question for editing
$edit_question = null;
if (isset($_GET['edit_question'])) {
    $edit_id = (int) $_GET['edit_question'];
    $edit_stmt = $conn->prepare("SELECT * FROM soal_reading WHERE id_soal = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_question = $edit_stmt->get_result()->fetch_assoc();
}

// Get questions with simplified query
$questions_query = "
    SELECT sr.*, 
           COALESCE(tb.judul, 'AI Generated Content') as passage_title,
           CASE 
               WHEN tb.id_teks IS NULL THEN 'AI Generated'
               ELSE 'Traditional'
           END as content_source
    FROM soal_reading sr 
    LEFT JOIN teks_bacaan tb ON sr.id_teks = tb.id_teks 
    ORDER BY sr.id_soal DESC
    LIMIT 50
";

$questions = $conn->query($questions_query);

// Get statistics
$stats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM teks_bacaan) as total_passages,
        (SELECT COUNT(*) FROM soal_reading) as total_questions
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Manage Reading</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .badge-maroon {
            background: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="admin-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1><i class="fas fa-book-open me-3"></i>Manage Reading Questions</h1>
                        <div class="text-end">
                            <div class="text-white-50 small">Statistics</div>
                            <div class="fw-bold"><?php echo $stats['total_passages']; ?> Passages |
                                <?php echo $stats['total_questions']; ?> Questions
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="stats-card">
                            <h5><i class="fas fa-book me-2"></i>Total Passages</h5>
                            <h3><?php echo $stats['total_passages']; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stats-card">
                            <h5><i class="fas fa-question-circle me-2"></i>Total Questions</h5>
                            <h3><?php echo $stats['total_questions']; ?></h3>
                        </div>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Edit Question Form -->
                <?php if ($edit_question): ?>
                    <div class="content-card">
                        <h4><i class="fas fa-edit me-2"></i>Edit Question</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_question">
                            <input type="hidden" name="id_soal" value="<?php echo $edit_question['id_soal']; ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Passage</label>
                                    <select name="id_teks" class="form-select" required>
                                        <option value="">Select Passage</option>
                                        <option value="0" <?php echo $edit_question['id_teks'] == 0 ? 'selected' : ''; ?>>AI Generated Content</option>
                                        <?php
                                        if ($passages) {
                                            $passages->data_seek(0);
                                            while ($passage = $passages->fetch_assoc()):
                                                ?>
                                                <option value="<?php echo $passage['id_teks']; ?>" <?php echo $edit_question['id_teks'] == $passage['id_teks'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($passage['judul']); ?>
                                                </option>
                                            <?php endwhile;
                                        } ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Skill Type</label>
                                    <select name="question_type" class="form-select">
                                        <option value="">-- Select Skill --</option>
                                        <?php 
                                        $skills = getSkillOptions('reading');
                                        foreach ($skills as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $edit_question['question_type'] == $key ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label">Question</label>
                                <textarea name="pertanyaan" class="form-control" rows="2" required><?php echo htmlspecialchars($edit_question['pertanyaan']); ?></textarea>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label">Option A</label>
                                    <input type="text" name="opsi_a" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_a']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Option B</label>
                                    <input type="text" name="opsi_b" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_b']); ?>" required>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label">Option C</label>
                                    <input type="text" name="opsi_c" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_c']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Option D</label>
                                    <input type="text" name="opsi_d" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_d']); ?>" required>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label">Correct Answer</label>
                                    <select name="jawaban_benar" class="form-select" required>
                                        <option value="A" <?php echo $edit_question['jawaban_benar'] == 'A' ? 'selected' : ''; ?>>A</option>
                                        <option value="B" <?php echo $edit_question['jawaban_benar'] == 'B' ? 'selected' : ''; ?>>B</option>
                                        <option value="C" <?php echo $edit_question['jawaban_benar'] == 'C' ? 'selected' : ''; ?>>C</option>
                                        <option value="D" <?php echo $edit_question['jawaban_benar'] == 'D' ? 'selected' : ''; ?>>D</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-save me-2"></i>Update Question
                                </button>
                                <a href="manage_reading.php" class="btn btn-secondary ms-2">Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Add Passage -->
                <div class="content-card">
                    <h4><i class="fas fa-plus me-2"></i>Add Reading Passage</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_passage">
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label">Passage Title</label>
                                <input type="text" name="judul" class="form-control" required
                                    placeholder="Enter passage title...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Topic</label>
                                <input type="text" name="topik" class="form-control" required
                                    placeholder="e.g., Science, History">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">Passage Text</label>
                            <textarea name="isi_teks" class="form-control" rows="6" required
                                placeholder="Enter the reading passage text here..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary mt-3">
                            <i class="fas fa-plus me-2"></i>Add Passage
                        </button>
                    </form>
                </div>

                <!-- Add Question -->
                <div class="content-card">
                    <h4><i class="fas fa-question-circle me-2"></i>Add Question</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_question">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Passage</label>
                                <select name="id_teks" class="form-select" required>
                                    <option value="">Select Passage</option>
                                    <option value="0">AI Generated Content</option>
                                    <?php
                                    if ($passages) {
                                        $passages->data_seek(0);
                                        while ($passage = $passages->fetch_assoc()):
                                            ?>
                                            <option value="<?php echo $passage['id_teks']; ?>">
                                                <?php echo htmlspecialchars($passage['judul']); ?>
                                            </option>
                                        <?php endwhile;
                                    } ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Skill Type (Micro-skill)</label>
                                <select name="question_type" class="form-select">
                                    <option value="">-- Select Skill --</option>
                                    <?php 
                                    $skills = getSkillOptions('reading');
                                    foreach ($skills as $key => $label): ?>
                                        <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Question ID</label>
                                <input type="text" class="form-control" value="Auto-generated" readonly>
                                <small class="text-muted">ID will be assigned automatically</small>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">Question</label>
                            <textarea name="pertanyaan" class="form-control" rows="2" required
                                placeholder="Enter the question text..."></textarea>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Option A</label>
                                <input type="text" name="opsi_a" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Option B</label>
                                <input type="text" name="opsi_b" class="form-control" required>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Option C</label>
                                <input type="text" name="opsi_c" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Option D</label>
                                <input type="text" name="opsi_d" class="form-control" required>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Correct Answer</label>
                                <select name="jawaban_benar" class="form-select" required>
                                    <option value="">Select correct answer</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success mt-3">
                            <i class="fas fa-plus me-2"></i>Add Question
                        </button>
                    </form>
                </div>

                <!-- Passages List -->
                <div class="content-card">
                    <h4><i class="fas fa-list me-2"></i>Reading Passages</h4>
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Topic</th>
                                        <th>Text Preview</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($passages) {
                                        $passages->data_seek(0);
                                        while ($passage = $passages->fetch_assoc()):
                                            ?>
                                            <tr>
                                                <td><?php echo $passage['id_teks']; ?></td>
                                                <td><?php echo htmlspecialchars($passage['judul']); ?></td>
                                                <td><span
                                                        class="badge-maroon"><?php echo htmlspecialchars($passage['topik']); ?></span>
                                                </td>
                                                <td>
                                                    <div style="max-width: 300px;">
                                                        <?php echo substr(htmlspecialchars($passage['isi_teks']), 0, 100); ?>...
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="?delete_passage=<?php echo $passage['id_teks']; ?>"
                                                        class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Delete this passage and all related questions?')"
                                                        title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile;
                                    } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Questions List -->
                <div class="content-card">
                    <h4><i class="fas fa-question-circle me-2"></i>Reading Questions</h4>

                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Passage</th>
                                        <th>Question</th>
                                        <th>Options</th>
                                        <th>Skill Type</th>
                                        <th>Answer</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($questions):
                                        while ($question = $questions->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo $question['id_soal']; ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($question['passage_title']); ?>
                                                </td>
                                                <td>
                                                    <div style="max-width: 250px;">
                                                        <?php echo substr(htmlspecialchars($question['pertanyaan']), 0, 60); ?>...
                                                    </div>
                                                </td>
                                                <td>
                                                    <small>
                                                        <strong>A:</strong>
                                                        <?php echo substr(htmlspecialchars($question['opsi_a']), 0, 15); ?>...<br>
                                                        <strong>B:</strong>
                                                        <?php echo substr(htmlspecialchars($question['opsi_b']), 0, 15); ?>...<br>
                                                        <strong>C:</strong>
                                                        <?php echo substr(htmlspecialchars($question['opsi_c']), 0, 15); ?>...<br>
                                                        <strong>D:</strong>
                                                        <?php echo substr(htmlspecialchars($question['opsi_d']), 0, 15); ?>...
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge-maroon"><?php echo $question['jawaban_benar']; ?></span>
                                                    <div class="mt-1">
                                                    <?php if (!empty($question['question_type'])): ?>
                                                        <span class="badge bg-info text-dark"><?php echo getSkillLabel('reading', $question['question_type']); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary" style="font-size: 0.7em;">Untagged</span>
                                                    <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="?edit_question=<?php echo $question['id_soal']; ?>" class="btn btn-sm btn-warning mb-1" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?delete_question=<?php echo $question['id_soal']; ?>"
                                                        class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Delete this question?')" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>

</html>