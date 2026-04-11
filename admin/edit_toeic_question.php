<?php
// admin/edit_toeic_question.php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';

// Auth Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'listening';
$table = ($type === 'listening') ? 'toeic_soal_listening' : 'toeic_soal_reading';
$message = '';
$error = '';

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $error = 'Invalid CSRF token. Please refresh the page and try again.';
    } else {
    $question = $_POST['pertanyaan'];
    $optA = $_POST['opsi_a'];
    $optB = $_POST['opsi_b'];
    $optC = $_POST['opsi_c'];
    $optD = $_POST['opsi_d'];
    $ans = $_POST['jawaban_benar'];
    
    $stmt = $conn->prepare("UPDATE $table SET pertanyaan=?, opsi_a=?, opsi_b=?, opsi_c=?, opsi_d=?, jawaban_benar=? WHERE id_soal=?");
    $stmt->bind_param("ssssssi", $question, $optA, $optB, $optC, $optD, $ans, $id);
    
    if ($stmt->execute()) {
        $message = "Question updated successfully!";
    } else {
        $error = "Error updating: " . $conn->error;
    }
    }
}

$csrf_token = generateCsrfToken();

// Fetch Data
$stmt = $conn->prepare("SELECT * FROM $table WHERE id_soal = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    die("Question not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Question</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <h4 class="mb-0">Edit TOEIC Question (#<?php echo $id; ?>)</h4>
                <a href="manage_toeic.php" class="btn btn-sm btn-light text-primary fw-bold">Back</a>
            </div>
            <div class="card-body">
                <?php if ($message) echo "<div class='alert alert-success'>$message</div>"; ?>
                <?php if ($error) echo "<div class='alert alert-danger'>$error</div>"; ?>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="mb-3">
                        <label>Question Text / Description</label>
                        <textarea name="pertanyaan" class="form-control" rows="3" required><?php echo htmlspecialchars($data['pertanyaan']); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Option A</label>
                            <input type="text" name="opsi_a" class="form-control" value="<?php echo htmlspecialchars($data['opsi_a']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Option B</label>
                            <input type="text" name="opsi_b" class="form-control" value="<?php echo htmlspecialchars($data['opsi_b']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Option C</label>
                            <input type="text" name="opsi_c" class="form-control" value="<?php echo htmlspecialchars($data['opsi_c']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Option D</label>
                            <input type="text" name="opsi_d" class="form-control" value="<?php echo htmlspecialchars($data['opsi_d']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label>Correct Answer</label>
                        <select name="jawaban_benar" class="form-select w-auto">
                            <option value="A" <?php if($data['jawaban_benar']=='A') echo 'selected'; ?>>A</option>
                            <option value="B" <?php if($data['jawaban_benar']=='B') echo 'selected'; ?>>B</option>
                            <option value="C" <?php if($data['jawaban_benar']=='C') echo 'selected'; ?>>C</option>
                            <option value="D" <?php if($data['jawaban_benar']=='D') echo 'selected'; ?>>D</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-success">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
