<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

$message = '';
$error = '';

// Handle session termination
if (isset($_POST['terminate'])) {
    $session_id = $_POST['session_id'];
    $sql = "DELETE FROM user_sessions WHERE session_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $session_id);
    if ($stmt->execute()) {
        logActivity($conn, $_SESSION['user_id'], 'TERMINATE_SESSION', "Terminated session: $session_id");
        $message = "Session terminated successfully";
    } else {
        $error = "Error terminating session";
    }
}

// Get active sessions
$sql = "SELECT us.*, u.username, u.full_name, u.role 
        FROM user_sessions us 
        JOIN users u ON us.user_id = u.id 
        ORDER BY us.last_activity DESC";
$sessions = $conn->query($sql);

// Get statistics
$total_sessions = $conn->query("SELECT COUNT(*) as total FROM user_sessions")->fetch_assoc()['total'];
$admin_sessions = $conn->query("SELECT COUNT(*) as total FROM user_sessions us JOIN users u ON us.user_id = u.id WHERE u.role = 'admin'")->fetch_assoc()['total'];
$client_sessions = $conn->query("SELECT COUNT(*) as total FROM user_sessions us JOIN users u ON us.user_id = u.id WHERE u.role = 'client'")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Sessions - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
            border: none;
            font-size: 14px;
        }

        .btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .btn-danger {
            background: #e74c3c;
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
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

        .stat-box.total {
            background: linear-gradient(135deg, black 0%, grey 100%);
        }

        .stat-box.admin {
            background: linear-gradient(135deg, black 0%, grey 100%);
        }

        .stat-box.client {
           background: linear-gradient(135deg, black 0%, grey 100%);
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

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-info .name {
            font-weight: 600;
            color: white;
        }

        .user-info .username {
            font-size: 12px;
            color: white;
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .role-admin {
            background: #2d2f36ff;
            color: white;
        }

        .role-client {
            background: #2d2f36ff;
            color: white;
        }

        .ip-info {
            font-family: monospace;
            font-size: 12px;
            color:white;
        }

        .timestamp {
            color: white;
            font-size: 12px;
        }

        .current-session {
            background: #474948ff;
        }

        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <h1>🖥️ Active Sessions</h1>
        <div style="display: flex; gap: 15px;">
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-box total">
                <h3>Total Active Sessions</h3>
                <div class="number"><?php echo $total_sessions; ?></div>
            </div>
            <div class="stat-box admin">
                <h3>Admin Sessions</h3>
                <div class="number"><?php echo $admin_sessions; ?></div>
            </div>
            <div class="stat-box client">
                <h3>Client Sessions</h3>
                <div class="number"><?php echo $client_sessions; ?></div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Current Active Sessions</h2>
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>IP Address</th>
                        <th>Browser</th>
                        <th>Login Time</th>
                        <th>Last Activity</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sessions->num_rows > 0): ?>
                        <?php while ($session = $sessions->fetch_assoc()): ?>
                            <?php $is_current = $session['session_id'] == session_id(); ?>
                            <tr <?php echo $is_current ? 'class="current-session"' : ''; ?>>
                                <td>
                                    <div class="user-info">
                                        <span class="name">
                                            <?php echo htmlspecialchars($session['full_name']); ?>
                                            <?php if ($is_current): ?>
                                                <span style="color: #ffffffff; font-weight: bold;"> (You)</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="username">@<?php echo htmlspecialchars($session['username']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-badge role-<?php echo $session['role']; ?>">
                                        <?php echo ucfirst($session['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ip-info"><?php echo htmlspecialchars($session['ip_address']); ?></span>
                                </td>
                                <td><?php echo formatUserAgent($session['user_agent']); ?></td>
                                <td>
                                    <div class="timestamp">
                                        <?php echo date('M d, Y', strtotime($session['created_at'])); ?><br>
                                        <?php echo date('h:i A', strtotime($session['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="timestamp">
                                        <?php
                                        $time_diff = time() - strtotime($session['last_activity']);
                                        if ($time_diff < 60) {
                                            echo "Just now";
                                        } elseif ($time_diff < 3600) {
                                            echo floor($time_diff / 60) . " min ago";
                                        } else {
                                            echo floor($time_diff / 3600) . " hr ago";
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!$is_current): ?>
                                        <form method="POST" style="display: inline;"
                                            onsubmit="return confirm('Are you sure you want to terminate this session?')">
                                            <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                            <button type="submit" name="terminate" class="btn btn-danger">Terminate</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #ffffffff; font-size: 12px;">Current Session</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #ffffffff;">
                                No active sessions
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>