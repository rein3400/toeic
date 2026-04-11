<?php
// admin/run_migration.php
require_once '../includes/session_handler.php';

// Auth Check (Admin Only)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. Admins only.");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$sqlFile = __DIR__ . '/../migrations/003_setup_payment_tables.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
        $queries = explode(';', $sql);
        
        $success = 0;
        $errors = [];

        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query)) continue;
            
            try {
                if ($conn->query($query) === TRUE) {
                    $success++;
                } else {
                    $errors[] = $conn->error;
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        
        if (empty($errors)) {
            $message = "<div class='alert alert-success'>Migration completed successfully! $success queries executed.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Migration finished with errors:<br>" . implode("<br>", $errors) . "</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Migration file not found!</div>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Run Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h4>Database Migration Tool</h4>
            </div>
            <div class="card-body">
                <p>This tool will execute <code>migrations/003_setup_payment_tables.sql</code> to create the necessary tables for the Payment Gateway.</p>
                <?php echo $message; ?>
                <form method="POST">
                    <button type="submit" class="btn btn-primary">Run Migration Now</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
