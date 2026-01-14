<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get filters
$user_filter = isset($_GET['user']) ? intval($_GET['user']) : 0;
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$sql = "SELECT al.*, u.username, u.full_name 
        FROM activity_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        WHERE 1=1";
$params = array();
$types = "";

if ($user_filter > 0) {
    $sql .= " AND al.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

if (!empty($action_filter)) {
    $sql .= " AND al.action = ?";
    $params[] = $action_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $sql .= " AND DATE(al.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $sql .= " AND DATE(al.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$sql .= " ORDER BY al.created_at DESC LIMIT 100";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $logs = $stmt->get_result();
} else {
    $logs = $conn->query($sql);
}

// Get all users for filter
$users = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");

// Get unique actions
$actions = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
     <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .navbar h1 {
            font-size: 24px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn:hover {
            background: grey;
        }

        .btn-primary {
            background: black;
            border: none;
            text-align: center;
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
            box-shadow: 0 2px 10px rgba(0, 0, 0,5);
            margin-bottom: 20px;
        }

        .filter-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-section select,
        .filter-section input {
            padding: 10px;
            border: 2px solid black;
            border-radius: 6px;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
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

        td {
            font-size: 13px;
        }

        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            background: lightgrey;
            color: black;
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

        .description {
            color: #666;
            font-size: 12px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: linear-gradient(135deg, black 0%, grey 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-box h3 {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
        }

        .stat-box .number {
            font-size: 32px;
            font-weight: bold;
        }
    </style>
<body>
    <nav class="navbar">
        <h1>📋 Activity Logs</h1>
        <div style="display: flex; gap: 15px;">
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php
        // Get statistics
        $total_logs = $conn->query("SELECT COUNT(*) as total FROM activity_logs")->fetch_assoc()['total'];
        $today_logs = $conn->query("SELECT COUNT(*) as total FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['total'];
        $unique_users = $conn->query("SELECT COUNT(DISTINCT user_id) as total FROM activity_logs WHERE user_id IS NOT NULL")->fetch_assoc()['total'];
        ?>

        <div class="stats-grid">
            <div class="stat-box">
                <h3>Total Activities</h3>
                <div class="number"><?php echo $total_logs; ?></div>
            </div>
            <div class="stat-box">
                <h3>Today's Activities</h3>
                <div class="number"><?php echo $today_logs; ?></div>
            </div>
            <div class="stat-box">
                <h3>Active Users</h3>
                <div class="number"><?php echo $unique_users; ?></div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Filter Logs</h2>
            <form method="GET" class="filter-section">
                <select name="user">
                    <option value="0">All Users</option>
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $user_filter == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?> (@<?php echo $u['username']; ?>)
                        </option>
                    <?php endwhile; ?>
                </select>

                <select name="action">
                    <option value="">All Actions</option>
                    <?php while ($a = $actions->fetch_assoc()): ?>
                        <option value="<?php echo $a['action']; ?>" <?php echo $action_filter == $a['action'] ? 'selected' : ''; ?>>
                            <?php echo str_replace('_', ' ', $a['action']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <input type="date" name="date_from" value="<?php echo $date_from; ?>" placeholder="From Date">
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" placeholder="To Date">

                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="activity_logs.php" class="btn btn-primary">Clear</a>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Activity History (Last 100)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                        <th>Browser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs->num_rows > 0): ?>
                        <?php while ($log = $logs->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="timestamp">
                                        <?php echo date('M d, Y', strtotime($log['created_at'])); ?><br>
                                        <?php echo date('h:i A', strtotime($log['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="user-info">
                                        <span class="name"><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown'); ?></span>
                                        <span class="username">@<?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="action-badge">
                                        <?php echo getActionIcon($log['action']); ?>
                                        <?php echo str_replace('_', ' ', $log['action']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="description"><?php echo htmlspecialchars($log['description']); ?></div>
                                </td>
                                <td>
                                    <span class="ip-info"><?php echo htmlspecialchars($log['ip_address']); ?></span>
                                </td>
                                <td>
                                    <?php echo formatUserAgent($log['user_agent']); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                                No activity logs found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>