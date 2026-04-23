<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

function adminTableExists($conn, $table) {
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$escaped'");
    return $result && $result->num_rows > 0;
}

function adminColumnExists($conn, $table, $column) {
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$escapedTable` LIKE '$escapedColumn'");
    return $result && $result->num_rows > 0;
}

// Fix for MySQL 8.0+ Strict Mode (GROUP BY issue)
$conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");

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
        if ($_POST['action'] == 'add_user') {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $full_name = trim($_POST['full_name']);
            $role = $_POST['role'];

            // Check if username already exists
            $check_stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ?");
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();

            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Username already exists!";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username, $hashed_password, $full_name, $role);

                if ($stmt->execute()) {
                    $success = "User added successfully!";
                } else {
                    $error = "Failed to add user: " . $conn->error;
                }
            }
        } elseif ($_POST['action'] == 'edit_user') {
            $id = $_POST['id'];
            $username = trim($_POST['username']);
            $full_name = trim($_POST['full_name']);
            $role = $_POST['role'];

            // Check if username already exists (excluding current user)
            $check_stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ? AND id_user != ?");
            $check_stmt->bind_param("si", $username, $id);
            $check_stmt->execute();

            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Username already exists!";
            } else {
                // Update password if provided
                if (!empty($_POST['password'])) {
                    $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, full_name = ?, role = ? WHERE id_user = ?");
                    $stmt->bind_param("ssssi", $username, $hashed_password, $full_name, $role, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, role = ? WHERE id_user = ?");
                    $stmt->bind_param("sssi", $username, $full_name, $role, $id);
                }

                if ($stmt->execute()) {
                    $success = "User updated successfully!";
                } else {
                    $error = "Failed to update user: " . $conn->error;
                }
            }
        }
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    // Don't allow deleting current admin
    if ($id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account!";
    } else {
        if (adminTableExists($conn, 'toeic_test_questions')) {
            $del_stmt = $conn->prepare("DELETE FROM toeic_test_questions WHERE user_id = ?");
            $del_stmt->bind_param("i", $id);
            $del_stmt->execute();
        }
        if (adminTableExists($conn, 'toeic_test_results')) {
            $del_stmt = $conn->prepare("DELETE FROM toeic_test_results WHERE user_id = ?");
            $del_stmt->bind_param("i", $id);
            $del_stmt->execute();
        }
        if (adminTableExists($conn, 'toeic_test_sessions')) {
            $del_stmt = $conn->prepare("DELETE FROM toeic_test_sessions WHERE user_id = ?");
            $del_stmt->bind_param("i", $id);
            $del_stmt->execute();
        }
        if (adminTableExists($conn, 'user_purchases')) {
            $del_stmt = $conn->prepare("DELETE FROM user_purchases WHERE user_id = ?");
            $del_stmt->bind_param("i", $id);
            $del_stmt->execute();
        }
        if (adminTableExists($conn, 'payment_transactions')) {
            $del_stmt = $conn->prepare("DELETE FROM payment_transactions WHERE user_id = ?");
            $del_stmt->bind_param("i", $id);
            $del_stmt->execute();
        }

        // Delete user
        $del_stmt = $conn->prepare("DELETE FROM users WHERE id_user = ?");
        $del_stmt->bind_param("i", $id);
        if ($del_stmt->execute()) {
            $success = "User deleted successfully!";
        } else {
            $error = "Failed to delete user: " . $conn->error;
        }
    }
}

// Get data for editing
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $edit_stmt = $conn->prepare("SELECT *, id_user as id FROM users WHERE id_user = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_user = $edit_stmt->get_result()->fetch_assoc();
}

// Pagination and filtering
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_role = isset($_GET['filter_role']) ? $_GET['filter_role'] : '';

// Build query with filters
$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(full_name LIKE ? OR username LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term]);
    $param_types .= 'ss';
}

if (!empty($filter_role)) {
    $where_conditions[] = "role = ?";
    $params[] = $filter_role;
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total FROM users $where_clause";
if (!empty($params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $total_users = $count_stmt->get_result()->fetch_assoc()['total'];
} else {
    $total_users = $conn->query($count_query)->fetch_assoc()['total'];
}

$total_pages = ceil($total_users / $per_page);

// Get users with test statistics
$result_sources = [];
if (
    adminTableExists($conn, 'toeic_test_results')
    && adminColumnExists($conn, 'toeic_test_results', 'user_id')
    && adminColumnExists($conn, 'toeic_test_results', 'total_score')
    && adminColumnExists($conn, 'toeic_test_results', 'completed_at')
) {
    $result_sources[] = "SELECT user_id AS user_id, total_score, completed_at FROM toeic_test_results";
}

$stats_join = "LEFT JOIN (
    SELECT user_id, COUNT(*) AS test_count, AVG(total_score) AS avg_score, MAX(total_score) AS best_score, MAX(completed_at) AS last_test
    FROM (SELECT NULL AS user_id, NULL AS total_score, NULL AS completed_at) seed
    WHERE 1 = 0
) rs ON u.id_user = rs.user_id";

if (!empty($result_sources)) {
    $stats_join = "LEFT JOIN (
        SELECT user_id, COUNT(*) AS test_count, AVG(total_score) AS avg_score, MAX(total_score) AS best_score, MAX(completed_at) AS last_test
        FROM (" . implode(" UNION ALL ", $result_sources) . ") aggregated_results
        GROUP BY user_id
    ) rs ON u.id_user = rs.user_id";
}

$users_query = "
    SELECT u.id_user as id, u.*,
           COALESCE(rs.test_count, 0) as test_count,
           rs.avg_score as avg_score,
           rs.best_score as best_score,
           rs.last_test as last_test
    FROM users u
    $stats_join
    $where_clause
    ORDER BY u.created_at DESC
    LIMIT $per_page OFFSET $offset
";

if (!empty($params)) {
    $users_stmt = $conn->prepare($users_query);
    $users_stmt->bind_param($param_types, ...$params);
    $users_stmt->execute();
    $users = $users_stmt->get_result();
} else {
    $users = $conn->query($users_query);
}

// Get statistics
$stats = $conn->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as new_users_30d
    FROM users
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Manage Users</title>
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

        @media (max-width: 768px) {
            .content-card .btn,
            .edit-form .btn {
                min-height: 46px;
            }

            .content-card form .col-md-4 > div,
            .edit-form .mt-3 {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
            }

            .content-card form .col-md-4 > div .btn,
            .edit-form .mt-3 .btn {
                width: 100%;
                margin-left: 0 !important;
            }

            .table-responsive .btn-sm {
                min-width: 44px;
                min-height: 44px;
            }
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
                        <h1><i class="fas fa-users me-3"></i>Manage Users</h1>
                        <div class="text-end">
                            <div class="text-white-50 small">User Management</div>
                            <div class="fw-bold"><?php echo $stats['total_users']; ?> Total |
                                <?php echo $stats['students']; ?> Students | <?php echo $stats['admins']; ?> Admins
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h5><i class="fas fa-users me-2"></i>Total Users</h5>
                            <h3><?php echo $stats['total_users']; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h5><i class="fas fa-user-graduate me-2"></i>Students</h5>
                            <h3><?php echo $stats['students']; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h5><i class="fas fa-user-shield me-2"></i>Admins</h5>
                            <h3><?php echo $stats['admins']; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h5><i class="fas fa-user-plus me-2"></i>New (30d)</h5>
                            <h3><?php echo $stats['new_users_30d']; ?></h3>
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

                <!-- Edit User Form -->
                <?php if ($edit_user): ?>
                    <div class="edit-form">
                        <h4><i class="fas fa-edit me-2"></i>Edit User</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_user">
                            <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" class="form-control"
                                        value="<?php echo htmlspecialchars($edit_user['username']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control"
                                        value="<?php echo htmlspecialchars($edit_user['full_name']); ?>" required>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label">Password (leave empty to keep current)</label>
                                    <input type="password" name="password" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-select" required>
                                        <option value="student" <?php echo $edit_user['role'] == 'student' ? 'selected' : ''; ?>>Student</option>
                                        <option value="admin" <?php echo $edit_user['role'] == 'admin' ? 'selected' : ''; ?>>
                                            Admin</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update User
                                </button>
                                <a href="users.php" class="btn btn-secondary ms-2">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Add User -->
                <?php if (!$edit_user): ?>
                    <div class="content-card">
                        <h4><i class="fas fa-plus me-2"></i>Add New User</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_user">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-select" required>
                                        <option value="">Select Role</option>
                                        <option value="student">Student</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success mt-3">
                                <i class="fas fa-plus me-2"></i>Add User
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Filter and Search -->
                <div class="content-card">
                    <h4><i class="fas fa-filter me-2"></i>Filter & Search Users</h4>
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control"
                                value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or username...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Filter by Role</label>
                            <select name="filter_role" class="form-select">
                                <option value="">All Roles</option>
                                <option value="student" <?php echo $filter_role == 'student' ? 'selected' : ''; ?>>
                                    Students</option>
                                <option value="admin" <?php echo $filter_role == 'admin' ? 'selected' : ''; ?>>Admins
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-2"></i>Search
                                </button>
                                <a href="users.php" class="btn btn-secondary ms-2">
                                    <i class="fas fa-times me-2"></i>Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Users List -->
                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4><i class="fas fa-list me-2"></i>Users</h4>
                        <span class="text-muted">Showing <?php echo $users->num_rows; ?> of <?php echo $total_users; ?>
                            users</span>
                    </div>

                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Test Statistics</th>
                                        <th>Last Activity</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($user = $users->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['full_name']); ?></strong><br>
                                                <small
                                                    class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge-maroon">
                                                    <i
                                                        class="fas fa-<?php echo $user['role'] == 'admin' ? 'shield-alt' : 'user-graduate'; ?> me-1"></i>
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user['test_count'] > 0): ?>
                                                    <small>
                                                        <strong>Tests:</strong> <?php echo $user['test_count']; ?><br>
                                                        <strong>Avg:</strong> <?php echo round($user['avg_score']); ?><br>
                                                        <strong>Best:</strong> <?php echo $user['best_score']; ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">No tests taken</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user['last_test']): ?>
                                                    <?php echo date('M j, Y', strtotime($user['last_test'])); ?><br>
                                                    <small
                                                        class="text-muted"><?php echo date('g:i A', strtotime($user['last_test'])); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                            </td>
                                            <td>
                                                <a href="?edit=<?php echo $user['id']; ?>"
                                                    class="btn btn-sm btn-warning me-1" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <a href="?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Delete this user and all their data?')"
                                                        title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge-maroon">Current User</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Users pagination">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&filter_role=<?php echo $filter_role; ?>">Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link"
                                            href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter_role=<?php echo $filter_role; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&filter_role=<?php echo $filter_role; ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>

</html>
