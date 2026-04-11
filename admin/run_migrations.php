<?php
/**
 * Admin Migration Runner
 *
 * Web-accessible page to run database migrations.
 * Requires admin authentication.
 *
 * Usage: Navigate to /admin/run_migrations.php
 *
 * @package OSGLI
 * @since 2026-02-19
 */

require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';

// Admin authentication check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo '<html><head><title>403 Forbidden</title></head><body>';
    echo '<h1>403 Forbidden</h1>';
    echo '<p>Admin access required.</p>';
    echo '<p>Your session:</p>';
    echo '<pre>';
    echo 'user_id: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET') . "\n";
    echo 'role: ' . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET') . "\n";
    echo 'logged_in: ' . (isset($_SESSION['logged_in']) ? $_SESSION['logged_in'] : 'NOT SET') . "\n";
    echo '</pre>';
    echo '<a href="login.php">Go to Admin Login</a>';
    echo '</body></html>';
    exit();
}

// CSRF protection
$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Get all migration files
$migrations_dir = __DIR__ . '/../migrations';
$migration_files = glob($migrations_dir . '/*.sql');
sort($migration_files);

// Track applied migrations (simple file-based tracking)
$applied_file = __DIR__ . '/../migrations/.applied';
$applied = [];
if (file_exists($applied_file)) {
    $applied = json_decode(file_get_contents($applied_file), true) ?? [];
}

// Process form submission
$result = null;
$selected_migrations = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $result = ['error' => 'Invalid CSRF token'];
    } else {
        $selected_migrations = $_POST['migrations'] ?? [];
        $dry_run = isset($_POST['dry_run']);
        
        $results = [];
        
        foreach ($selected_migrations as $migration) {
            // Security: validate migration filename
            $migration = basename($migration);
            $path = $migrations_dir . '/' . $migration;
            
            if (!file_exists($path)) {
                $results[$migration] = ['status' => 'error', 'message' => 'File not found'];
                continue;
            }
            
            $sql = file_get_contents($path);
            $queries = explode(';', $sql);
            
            $errors = [];
            $executed = 0;
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (empty($query)) continue;
                
                if ($dry_run) {
                    // Dry run: just validate syntax (basic check)
                    $executed++;
                } else {
                    // PHP 8.1+ throws mysqli_sql_exception by default
                    // We need to handle ignoreable error codes in both paths
                    $ignoreable = [1060, 1061, 1050, 1091, 1054]; // dup col, dup key, table exists, drop nonexistent, unknown col (for DROP)
                    try {
                        if (!$conn->query($query)) {
                            // Ignore "Duplicate column" or "Duplicate key" errors for idempotency
                            if (in_array($conn->errno, $ignoreable)) {
                                $executed++;
                            } else {
                                $errors[] = $conn->error;
                            }
                        } else {
                            $executed++;
                        }
                    } catch (mysqli_sql_exception $e) {
                        // PHP 8.1+ throws exceptions instead of returning false
                        if (in_array($e->getCode(), $ignoreable)) {
                            $executed++;
                        } else {
                            $errors[] = $e->getMessage();
                        }
                    } catch (Exception $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }
            
            if (empty($errors)) {
                $results[$migration] = [
                    'status' => 'success',
                    'message' => $dry_run ? "Dry run: $executed queries would be executed" : "Applied $executed queries successfully"
                ];
                
                // Mark as applied (only if not dry run)
                if (!$dry_run && !in_array($migration, $applied)) {
                    $applied[] = $migration;
                }
            } else {
                $results[$migration] = [
                    'status' => 'error',
                    'message' => implode('; ', $errors)
                ];
            }
        }
        
        // Save applied migrations
        if (!$dry_run && !empty($applied)) {
            file_put_contents($applied_file, json_encode($applied, JSON_PRETTY_PRINT));
        }
        
        $result = $results;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Runner - OSGLI Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .migration-applied { background-color: var(--success-light); }
        .migration-pending { background-color: var(--warning-light); }
        .migration-new { background-color: var(--info-light); }
        .result-success { color: var(--success); }
        .result-error { color: var(--danger); }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>🗄️ Database Migration Runner</h1>
        <p class="text-muted">Run SQL migrations via web interface. Admin only.</p>
        
        <?php if ($result): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Migration Results</h5>
            </div>
            <div class="card-body">
                <?php foreach ($result as $file => $info): ?>
                <div class="alert alert-<?= $info['status'] === 'success' ? 'success' : 'danger' ?>">
                    <strong><?= htmlspecialchars($file) ?></strong><br>
                    <?= htmlspecialchars($info['message']) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Available Migrations</h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(true)">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">Deselect All</button>
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="selectPending()">Select Pending</button>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th width="50"><input type="checkbox" id="checkAll"></th>
                                <th>Migration File</th>
                                <th>Status</th>
                                <th>Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($migration_files as $file): ?>
                            <?php 
                            $filename = basename($file);
                            $is_applied = in_array($filename, $applied);
                            $is_new = strpos($filename, '009_') === 0; // Highlight new migrations
                            ?>
                            <tr class="<?= $is_applied ? 'migration-applied' : ($is_new ? 'migration-new' : 'migration-pending') ?>">
                                <td>
                                    <input type="checkbox" name="migrations[]" value="<?= htmlspecialchars($filename) ?>" 
                                           class="migration-check" data-applied="<?= $is_applied ? '1' : '0' ?>">
                                </td>
                                <td>
                                    <label class="mb-0" style="cursor:pointer">
                                        <?= htmlspecialchars($filename) ?>
                                    </label>
                                </td>
                                <td>
                                    <?php if ($is_applied): ?>
                                        <span class="badge bg-success">Applied</span>
                                    <?php elseif ($is_new): ?>
                                        <span class="badge bg-info">New</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format(filesize($file)) ?> bytes</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if (empty($migration_files)): ?>
                    <p class="text-muted">No migration files found in <code>migrations/</code></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Run selected migrations?')">
                    ▶️ Run Selected
                </button>
                <button type="submit" name="dry_run" value="1" class="btn btn-outline-secondary">
                    🔍 Dry Run
                </button>
                <a href="index.php" class="btn btn-outline-secondary">← Back to Admin</a>
            </div>
        </form>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">Legend</h5>
            </div>
            <div class="card-body">
                <p><span class="badge bg-success">Applied</span> Already executed (idempotent - safe to re-run)</p>
                <p><span class="badge bg-warning text-dark">Pending</span> Not yet executed</p>
                <p><span class="badge bg-info">New</span> Recently added migration</p>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">Migration Tracking</h5>
            </div>
            <div class="card-body">
                <p><strong>Tracking file:</strong> <code>migrations/.applied</code></p>
                <p><strong>Applied migrations:</strong> <?= count($applied) ?> / <?= count($migration_files) ?></p>
                <?php if (count($applied) < count($migration_files)): ?>
                <div class="alert alert-warning">
                    ⚠️ There are pending migrations. Run them to keep your database up to date.
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    ✅ All migrations have been applied.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    function toggleAll(checked) {
        document.querySelectorAll('.migration-check').forEach(cb => cb.checked = checked);
    }
    
    function selectPending() {
        document.querySelectorAll('.migration-check').forEach(cb => {
            cb.checked = cb.dataset.applied === '0';
        });
    }
    
    document.getElementById('checkAll').addEventListener('change', function() {
        toggleAll(this.checked);
    });
    </script>
</body>
</html>
