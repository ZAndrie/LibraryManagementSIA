<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$user_filter = isset($_GET['user']) ? intval($_GET['user']) : 0;

// Build query
$sql = "SELECT lh.*, u.full_name 
        FROM login_history lh 
        LEFT JOIN users u ON lh.user_id = u.id 
        WHERE 1=1";
$params = array();
$types = "";

if (!empty($status_filter)) {
    $sql .= " AND lh.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($user_filter > 0) {
    $sql .= " AND lh.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

$sql .= " ORDER BY lh.created_at DESC LIMIT 100";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $logins = $stmt->get_result();
} else {
    $logins = $conn->query($sql);
}

// Get users for filter
$users = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");

// Get statistics
$total_logins = $conn->query("SELECT COUNT(*) as total FROM login_history")->fetch_assoc()['total'];
$successful = $conn->query("SELECT COUNT(*) as total FROM login_history WHERE status = 'success'")->fetch_assoc()['total'];
$failed = $conn->query("SELECT COUNT(*) as total FROM login_history WHERE status = 'failed'")->fetch_assoc()['total'];
$today_logins = $conn->query("SELECT COUNT(*) as total FROM login_history WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login History - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
        }
        .navbar {
            background: linear-gradient(135deg, black 0%, black 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar h1 { font-size: 24px; }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
        }
        .btn:hover { background: grey }
        .btn-primary {
            background: black;
            border: none;
        }
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
            margin-bottom: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            color: white;
        }
        .stat-box.total { background: linear-gradient(135deg, black 0%, grey 100%); }
        .stat-box.success { background: linear-gradient(135deg, black 0%, grey 100%); }
        .stat-box.failed { background: linear-gradient(135deg, black 0%,  grey 100%); }
        .stat-box.today { background: linear-gradient(135deg, black 0%, grey 100%); }
        .stat-box h3 {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        .stat-box .number {
            font-size: 32px;
            font-weight: bold;
        }
        .filter-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-section select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 13px;
        }
        td { font-size: 13px; }
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        .user-info {
            display: flex;
            flex-direction: column;
        }
        .user-info .name {
            font-weight: 600;
            color: #333;
        }
        .user-info .username {
            font-size: 12px;
            color: #666;
        }
        .ip-info {
            font-family: monospace;
            font-size: 12px;
            color: #666;
        }
        .timestamp {
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class = "fas fa-lock"></i> Login History</h1>
        <div style="display: flex; gap: 15px;">
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-box total">
                <h3>Total Logins</h3>
                <div class="number"><?php echo $total_logins; ?></div>
            </div>
            <div class="stat-box success">
                <h3>Successful</h3>
                <div class="number"><?php echo $successful; ?></div>
            </div>
            <div class="stat-box failed">
                <h3>Failed Attempts</h3>
                <div class="number"><?php echo $failed; ?></div>
            </div>
            <div class="stat-box today">
                <h3>Today's Logins</h3>
                <div class="number"><?php echo $today_logins; ?></div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Filter Login History</h2>
            <form method="GET" class="filter-section">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="success" <?php echo $status_filter == 'success' ? 'selected' : ''; ?>>Successful</option>
                    <option value="failed" <?php echo $status_filter == 'failed' ? 'selected' : ''; ?>>Failed</option>
                </select>
                
                <select name="user">
                    <option value="0">All Users</option>
                    <?php while($u = $users->fetch_assoc()): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $user_filter == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?> (@<?php echo $u['username']; ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
                
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="login_history.php" class="btn btn-primary">Clear</a>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Login Attempts (Last 100)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Username</th>
                        <th>IP Address</th>
                        <th>Browser</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logins->num_rows > 0): ?>
                        <?php while($login = $logins->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="timestamp">
                                        <?php echo date('M d, Y', strtotime($login['created_at'])); ?><br>
                                        <?php echo date('h:i A', strtotime($login['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="user-info">
                                        <span class="name"><?php echo htmlspecialchars($login['full_name'] ?? 'Unknown'); ?></span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($login['username']); ?></td>
                                <td>
                                    <span class="ip-info"><?php echo htmlspecialchars($login['ip_address']); ?></span>
                                </td>
                                <td><?php echo formatUserAgent($login['user_agent']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $login['status']; ?>">
                                        <?php echo $login['status'] == 'success' ? '✓' : '✗'; ?>
                                        <?php echo ucfirst($login['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($login['failure_reason']): ?>
                                        <span style="color: #e74c3c; font-size: 12px;">
                                            <?php echo htmlspecialchars($login['failure_reason']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #27ae60; font-size: 12px;">Login successful</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                No login history found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>