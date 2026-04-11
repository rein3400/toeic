<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

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
        if ($_POST['action'] == 'add_question') {
            $part = $_POST['part'] ?? 'A';
            $pertanyaan = trim($_POST['pertanyaan']);
            $opsi_a = trim($_POST['opsi_a']);
            $opsi_b = trim($_POST['opsi_b']);
            $opsi_c = trim($_POST['opsi_c']);
            $opsi_d = trim($_POST['opsi_d']);
            $jawaban_benar = $_POST['jawaban_benar'];
            $penjelasan = trim($_POST['penjelasan']);
            $difficulty = $_POST['difficulty'] ?? 'medium';
            
            // Auto-generate question number (get next available number)
            $max_query = $conn->query("SELECT MAX(id_soal) as max_id, MAX(nomor_soal) as max_nomor FROM soal_structure");
            $max_result = $max_query->fetch_assoc();
            $id_soal = ($max_result['max_id'] ?? 0) + 1;
            $nomor_soal = ($max_result['max_nomor'] ?? 0) + 1;
            
            $stmt = $conn->prepare("INSERT INTO soal_structure (id_soal, part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, penjelasan, difficulty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isissssssss", $id_soal, $part, $nomor_soal, $pertanyaan, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar, $penjelasan, $difficulty);
            
            if ($stmt->execute()) {
                $success = "Question added successfully with number: " . $id_soal;
            } else {
                $error = "Failed to add question: " . $conn->error;
            }
        }
        
        elseif ($_POST['action'] == 'edit_question') {
            $id_soal = $_POST['id_soal'];
            $part = $_POST['part'] ?? 'A';
            $pertanyaan = trim($_POST['pertanyaan']);
            $opsi_a = trim($_POST['opsi_a']);
            $opsi_b = trim($_POST['opsi_b']);
            $opsi_c = trim($_POST['opsi_c']);
            $opsi_d = trim($_POST['opsi_d']);
            $jawaban_benar = $_POST['jawaban_benar'];
            $penjelasan = trim($_POST['penjelasan']);
            $difficulty = $_POST['difficulty'] ?? 'medium';
            
            $stmt = $conn->prepare("UPDATE soal_structure SET part = ?, pertanyaan = ?, opsi_a = ?, opsi_b = ?, opsi_c = ?, opsi_d = ?, jawaban_benar = ?, penjelasan = ?, difficulty = ? WHERE id_soal = ?");
            $stmt->bind_param("sssssssssi", $part, $pertanyaan, $opsi_a, $opsi_b, $opsi_c, $opsi_d, $jawaban_benar, $penjelasan, $difficulty, $id_soal);
            
            if ($stmt->execute()) {
                $success = "Question updated successfully!";
            } else {
                $error = "Failed to update question: " . $conn->error;
            }
        }
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    if ($conn->query("DELETE FROM soal_structure WHERE id_soal = $id")) {
        $success = "Question deleted successfully!";
    } else {
        $error = "Failed to delete question: " . $conn->error;
    }
}

// Get data for editing
$edit_question = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_stmt = $conn->prepare("SELECT * FROM soal_structure WHERE id_soal = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_question = $edit_stmt->get_result()->fetch_assoc();
}


// Handle part filter
$part_filter = isset($_GET['part']) ? $_GET['part'] : '';
$where_clause = '';
if ($part_filter && in_array($part_filter, ['A', 'B'])) {
    $where_clause = " WHERE part = '" . $conn->real_escape_string($part_filter) . "'";
}

// Get questions
$questions = $conn->query("SELECT * FROM soal_structure" . $where_clause . " ORDER BY id_soal DESC");
$total_questions_query = $conn->query("SELECT COUNT(*) as total FROM soal_structure");
$total_questions = $total_questions_query->fetch_assoc()['total'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Manage Structure</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .edit-form {
            background: var(--glass-bg);
            backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .badge-maroon {
            background: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .stats-badge {
            background: rgba(255,255,255,0.1);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 0.5rem 1rem;
            color: var(--text-main);
            font-weight: 600;
            margin-left: 0.5rem;
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
                        <h1><i class="fas fa-spell-check me-3"></i>Manage Structure Questions</h1>
                        <div class="text-end">
                            <a href="ai_generator_structure.php" class="btn btn-light me-2">
                                <i class="fas fa-magic me-2"></i>AI Generator
                            </a>
                            <span class="stats-badge">Total Questions: <?php echo $total_questions; ?></span>
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
                
                                
                                
                                
                <!-- Add New Question Form -->
                <?php if (!$edit_question): ?>
                    <div class="content-card">
                        <h4><i class="fas fa-plus me-2"></i>Add New Structure Question</h4>
                        <p class="text-muted">Create a new structure question manually. Question number will be automatically assigned.</p>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="add_question">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Part Type</label>
                                    <select name="part" class="form-select" required>
                                        <option value="A">Part A - Structure (Grammar)</option>
                                        <option value="B">Part B - Written Expression (Error Identification)</option>
                                    </select>
                                    <small class="text-muted">Part A tests grammar completion, Part B tests error identification</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Difficulty Level</label>
                                    <select name="difficulty" class="form-select" required>
                                        <option value="easy">Easy</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="hard">Hard</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Question</label>
                                <textarea name="pertanyaan" class="form-control" rows="3" required placeholder="Enter the structure question. Use underlines (_____) for blanks that need to be filled."></textarea>
                                <small class="text-muted">Tip: Use underlines (_____) to indicate where students should choose the correct answer.</small>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Option A</label>
                                    <input type="text" name="opsi_a" class="form-control" required placeholder="Enter option A">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Option B</label>
                                    <input type="text" name="opsi_b" class="form-control" required placeholder="Enter option B">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Option C</label>
                                    <input type="text" name="opsi_c" class="form-control" required placeholder="Enter option C">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Option D</label>
                                    <input type="text" name="opsi_d" class="form-control" required placeholder="Enter option D">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
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
                            
                            <div class="mb-3">
                                <label class="form-label">Explanation (Optional)</label>
                                <textarea name="penjelasan" class="form-control" rows="2" placeholder="Explain why this is the correct answer (optional)"></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-plus me-2"></i>Add Question
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Edit Question Form -->
                <?php if ($edit_question): ?>
                    <div class="edit-form">
                        <h4><i class="fas fa-edit me-2"></i>Edit Structure Question</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_question">
                            <input type="hidden" name="id_soal" value="<?php echo $edit_question['id_soal']; ?>">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Part Type</label>
                                    <select name="part" class="form-select" required>
                                        <option value="A" <?php echo ($edit_question['part'] ?? 'A') == 'A' ? 'selected' : ''; ?>>Part A - Structure (Grammar)</option>
                                        <option value="B" <?php echo ($edit_question['part'] ?? 'A') == 'B' ? 'selected' : ''; ?>>Part B - Written Expression (Error Identification)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Difficulty Level</label>
                                    <select name="difficulty" class="form-select" required>
                                        <option value="easy" <?php echo ($edit_question['difficulty'] ?? 'medium') == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                        <option value="medium" <?php echo ($edit_question['difficulty'] ?? 'medium') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="hard" <?php echo ($edit_question['difficulty'] ?? 'medium') == 'hard' ? 'selected' : ''; ?>>Hard</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Question</label>
                                <textarea name="pertanyaan" class="form-control" rows="3" required><?php echo htmlspecialchars($edit_question['pertanyaan']); ?></textarea>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Option A</label>
                                    <input type="text" name="opsi_a" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_a']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Option B</label>
                                    <input type="text" name="opsi_b" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_b']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Option C</label>
                                    <input type="text" name="opsi_c" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_c']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Option D</label>
                                    <input type="text" name="opsi_d" class="form-control" value="<?php echo htmlspecialchars($edit_question['opsi_d']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
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
                            
                            <div class="mb-3">
                                <label class="form-label">Explanation (Optional)</label>
                                <textarea name="penjelasan" class="form-control" rows="2" placeholder="Explain why this is the correct answer (optional)"><?php echo htmlspecialchars($edit_question['penjelasan'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Question
                                </button>
                                <a href="manage_structure.php" class="btn btn-secondary ms-2">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Questions List -->
                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4><i class="fas fa-list me-2"></i>Structure Questions</h4>
                        <div>
                            <form method="GET" class="d-inline-flex align-items-center">
                                <label class="me-2">Filter by Part:</label>
                                <select name="part" class="form-select form-select-sm me-2" style="width: auto;" onchange="this.form.submit()">
                                    <option value="">All Parts</option>
                                    <option value="A" <?php echo $part_filter === 'A' ? 'selected' : ''; ?>>Part A - Structure</option>
                                    <option value="B" <?php echo $part_filter === 'B' ? 'selected' : ''; ?>>Part B - Written Expression</option>
                                </select>
                                <?php if ($part_filter): ?>
                                    <a href="manage_structure.php" class="btn btn-sm btn-secondary"><i class="fas fa-times"></i></a>
                                <?php endif; ?>
                            </form>
                            <span class="text-muted ms-3">Showing <?php echo $questions->num_rows; ?> of <?php echo $total_questions; ?> questions</span>
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Part</th>
                                    <th>Question</th>
                                    <th>Options</th>
                                    <th>Correct</th>
                                    <th>Difficulty</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($questions->num_rows > 0): ?>
                                    <?php while($question = $questions->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <span class="badge-maroon"><?php echo $question['id_soal']; ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                $part = $question['part'] ?? 'A';
                                                $part_label = $part == 'A' ? 'Structure' : 'Written Exp';
                                                $part_class = $part == 'A' ? 'bg-primary' : 'bg-info';
                                                ?>
                                                <span class="badge <?php echo $part_class; ?>"><?php echo $part_label; ?></span>
                                            </td>
                                            <td>
                                                <div style="max-width: 300px;">
                                                    <?php 
                                                    $question_text = $question['pertanyaan'];
                                                    echo substr($question_text, 0, 100) . (strlen($question_text) > 100 ? '...' : '');
                                                    ?>
                                                </div>
                                                <?php if (!empty($question['penjelasan'])): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-info-circle me-1"></i>Has explanation
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small>
                                                    <strong>A:</strong> <?php echo substr(htmlspecialchars($question['opsi_a']), 0, 20); ?>...<br>
                                                    <strong>B:</strong> <?php echo substr(htmlspecialchars($question['opsi_b']), 0, 20); ?>...<br>
                                                    <strong>C:</strong> <?php echo substr(htmlspecialchars($question['opsi_c']), 0, 20); ?>...<br>
                                                    <strong>D:</strong> <?php echo substr(htmlspecialchars($question['opsi_d']), 0, 20); ?>...
                                                </small>
                                            </td>
                                            <td><span class="badge-maroon"><?php echo $question['jawaban_benar']; ?></span></td>
                                            <td>
                                                <?php 
                                                $difficulty = $question['difficulty'] ?? 'medium';
                                                $difficulty_class = '';
                                                switch($difficulty) {
                                                    case 'easy': $difficulty_class = 'bg-success'; break;
                                                    case 'hard': $difficulty_class = 'bg-danger'; break;
                                                    default: $difficulty_class = 'bg-warning'; break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $difficulty_class; ?>"><?php echo ucfirst($difficulty); ?></span>
                                            </td>
                                            <td>
                                                <a href="?edit=<?php echo $question['id_soal']; ?>" 
                                                   class="btn btn-sm btn-warning me-1" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?delete=<?php echo $question['id_soal']; ?>" 
                                                   class="btn btn-sm btn-danger"
                                                   onclick="return confirm('Delete this question?')" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <i class="fas fa-question-circle fa-3x mb-3 text-muted"></i>
                                            <h5 class="text-muted">No Structure Questions Found</h5>
                                            <p class="text-muted">Start by adding your first structure question manually or use the AI Generator.</p>
                                            <a href="ai_generator_structure.php" class="btn btn-primary me-2">
                                                <i class="fas fa-magic me-2"></i>Use AI Generator
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            </table>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                if (alert.querySelector('.btn-close')) {
                    alert.querySelector('.btn-close').click();
                }
            });
        }, 5000);
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        } else {
                            field.classList.remove('is-invalid');
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });
            
            // Real-time validation
            const inputs = document.querySelectorAll('input[required], textarea[required], select[required]');
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
                
                input.addEventListener('input', function() {
                    if (this.value.trim()) {
                        this.classList.remove('is-invalid');
                    }
                });
            });
        });
        
        // Character counter for textareas
        document.querySelectorAll('textarea').forEach(textarea => {
            const maxLength = textarea.getAttribute('maxlength');
            if (maxLength) {
                const counter = document.createElement('small');
                counter.className = 'text-muted';
                counter.style.float = 'right';
                textarea.parentNode.appendChild(counter);
                
                function updateCounter() {
                    const remaining = maxLength - textarea.value.length;
                    counter.textContent = `${remaining} characters remaining`;
                    counter.style.color = remaining < 50 ? '#dc3545' : '#6c757d';
                }
                
                textarea.addEventListener('input', updateCounter);
                updateCounter();
            }
        });
        
        // Confirm delete with more details
        function confirmDelete(questionId, questionText) {
            const truncatedText = questionText.length > 50 ? 
                questionText.substring(0, 50) + '...' : questionText;
            
            return confirm(`Are you sure you want to delete this question?\n\n"${truncatedText}"\n\nThis action cannot be undone.`);
        }
        
        // Add enhanced delete confirmation to existing delete links
        document.querySelectorAll('a[href*="delete="]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const row = this.closest('tr');
                const questionText = row.querySelector('td:nth-child(2)').textContent.trim();
                
                if (confirmDelete(null, questionText)) {
                    window.location.href = this.href;
                }
            });
        });
        
        // Auto-save draft functionality (optional)
        let draftTimer;
        const draftKey = 'structure_question_draft';
        
        function saveDraft() {
            const form = document.querySelector('form[method="POST"]');
            if (form && form.querySelector('input[name="action"][value="add_question"]')) {
                const formData = new FormData(form);
                const draft = {};
                for (let [key, value] of formData.entries()) {
                    if (key !== 'action') {
                        draft[key] = value;
                    }
                }
                localStorage.setItem(draftKey, JSON.stringify(draft));
            }
        }
        
        function loadDraft() {
            const draft = localStorage.getItem(draftKey);
            if (draft) {
                const data = JSON.parse(draft);
                const form = document.querySelector('form[method="POST"]');
                if (form && form.querySelector('input[name="action"][value="add_question"]')) {
                    Object.keys(data).forEach(key => {
                        const field = form.querySelector(`[name="${key}"]`);
                        if (field && data[key]) {
                            field.value = data[key];
                        }
                    });
                }
            }
        }
        
        function clearDraft() {
            localStorage.removeItem(draftKey);
        }
        
        // Load draft on page load
        document.addEventListener('DOMContentLoaded', loadDraft);
        
        // Save draft on form input
        document.addEventListener('input', function(e) {
            if (e.target.form && e.target.form.querySelector('input[name="action"][value="add_question"]')) {
                clearTimeout(draftTimer);
                draftTimer = setTimeout(saveDraft, 1000);
            }
        });
        
        // Clear draft on successful form submission
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert && successAlert.textContent.includes('added successfully')) {
                clearDraft();
            }
        });
    </script>
</body>
</html>