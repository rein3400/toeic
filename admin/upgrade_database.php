<?php
// admin/upgrade_database.php
require_once '../includes/session_handler.php';

// Auth Check (Admin Only)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. Admins only.");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $migrations = glob(__DIR__ . '/../migrations/*.sql');
    sort($migrations); // Sort by name (001, 002, ...)
    
    $results = [];
    $total_errors = 0;

    foreach ($migrations as $file) {
        $filename = basename($file);
        
        // Skip non-numbered if we want strict ordering, but let's just run everything that looks like a migration
        // Or better, just run the ones we know we need for this update cycle: 004, 005, 006, 007
        // Actually, safer to run ALL numbered ones idempotently.
        if (!preg_match('/^\d{3}_/', $filename)) continue;

        $sql = file_get_contents($file);
        $queries = explode(';', $sql);
        
        $file_errors = 0;
        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query)) continue;
            
            try {
                // Suppress errors for duplicate columns/tables (idempotency)
                if (!$conn->query($query)) {
                    $err = $conn->errno;
                    // 1050: Table exists, 1060: Duplicate column, 1061: Duplicate key
                    if (!in_array($err, [1050, 1060, 1061])) {
                        $file_errors++;
                        $results[] = "<span class='text-danger'>Error in $filename: " . $conn->error . "</span>";
                    }
                }
            } catch (Exception $e) {
                $file_errors++;
                $results[] = "<span class='text-danger'>Exception in $filename: " . $e->getMessage() . "</span>";
            }
        }
        
        if ($file_errors === 0) {
            $results[] = "<span class='text-success'>$filename applied successfully (or already exists).</span>";
        } else {
            $total_errors++;
        }
    }
    
    if ($total_errors === 0) {
        $message = "<div class='alert alert-success'>Database upgrade completed!</div>";
    } else {
        $message = "<div class='alert alert-warning'>Upgrade finished with warnings/errors. Check details below.</div>";
    }
    
    $message .= "<div class='card mt-3'><div class='card-body'><pre>" . implode("\n", $results) . "</pre></div></div>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upgrade Database</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow">
            <div class="card-header bg-warning text-dark">
                <h4>System Database Upgrade</h4>
            </div>
            <div class="card-body">
                <p>This tool will apply all pending database migrations (001-007) to ensure your system has the latest tables for Secure Audio, Anti-Cheating, and Payment features.</p>
                <?php echo $message; ?>
                <form method="POST">
                    <button type="submit" class="btn btn-warning fw-bold">Run Database Upgrade</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
