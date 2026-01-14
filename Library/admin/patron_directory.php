<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get filters
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$registration_filter = isset($_GET['registration']) ? $_GET['registration'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query - Unified patron and user view
$sql = "SELECT 
        p.id as patron_id,
        p.student_id,
        p.name as patron_name,
        p.email as patron_email,
        p.phone,
        p.account_status,
        p.created_at as patron_created_at,
        u.id as user_id,
        u.username,
        u.full_name as user_name,
        u.email as user_email,
        u.role,
        u.last_login,
        u.created_at as user_created_at,
        COALESCE(ubs.total_borrowed, 0) as total_borrowed,
        COALESCE(ubs.currently_borrowed, 0) as currently_borrowed,
        COALESCE(ubs.total_returned, 0) as total_returned,
        COALESCE(ubs.overdue_count, 0) as overdue_count,
        up.membership_status
        FROM patrons p
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN user_borrowing_stats ubs ON u.id = ubs.user_id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE 1=1";

$params = array();
$types = "";

// Search filter
if (!empty($search)) {
    $sql .= " AND (p.student_id LIKE ? OR p.name LIKE ? OR p.email LIKE ? OR u.username LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

// Role filter
if (!empty($role_filter)) {
    $sql .= " AND u.role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

// Account status filter
if (!empty($status_filter)) {
    $sql .= " AND p.account_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Registration filter
if (!empty($registration_filter)) {
    if ($registration_filter === 'registered') {
        $sql .= " AND u.id IS NOT NULL";
    } elseif ($registration_filter === 'pending') {
        $sql .= " AND u.id IS NULL";
    }
}

$sql .= " ORDER BY p.created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $patrons = $stmt->get_result();
} else {
    $patrons = $conn->query($sql);
}

// Get statistics
$total_patrons = $conn->query("SELECT COUNT(*) as total FROM patrons")->fetch_assoc()['total'];
$registered_count = $conn->query("SELECT COUNT(*) as total FROM patrons WHERE user_id IS NOT NULL")->fetch_assoc()['total'];
$pending_count = $conn->query("SELECT COUNT(*) as total FROM patrons WHERE user_id IS NULL")->fetch_assoc()['total'];
$active_borrowers = $conn->query("SELECT COUNT(DISTINCT user_id) as total FROM user_borrowing_stats WHERE currently_borrowed > 0")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patron Directory - Library System</title>
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
            font-size: 14px;
        }
        .btn:hover { background: grey; }
        .btn-primary {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
        }
        .btn-primary1 {
           background: black;
        }
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: black;
        }
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .filter-section {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            gap: 15px;
            margin-bottom: 20px;
        }
        .filter-section input,
        .filter-section select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        .info-banner {
            background: #e7f3ff;
            border-left: 4px solid black;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        .info-banner h3 {
            color: black;
            font-size: 16px;
            margin-bottom: 8px;
        }
        .info-banner p {
            font-size: 13px;
            color: #333;
            line-height: 1.6;
        }
        
        /* Card Grid Layout */
        .patrons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .patron-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        
        .patron-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .patron-header {
            background: linear-gradient(135deg, black 0%, grey 100%);
            padding: 30px 20px;
            text-align: center;
            position: relative;
        }
        
        .patron-avatar {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 32px;
            color: black;
            font-weight: bold;
        }
        
        .patron-header h3 {
            color: white;
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .patron-header .student-id {
            color: rgba(255,255,255,0.8);
            font-size: 13px;
        }
        
        .patron-body {
            padding: 20px;
        }
        
        .patron-info-row {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            font-size: 13px;
        }
        
        .patron-info-row i {
            width: 20px;
            color: #666;
            margin-right: 10px;
        }
        
        .patron-info-row .label {
            color: #999;
            margin-right: 8px;
        }
        
        .patron-info-row .value {
            color: #333;
            font-weight: 600;
        }
        
        .patron-badges {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-registered {
            background: #d4edda;
            color: #155724;
        }
        .status-active {
            background: #cce5ff;
            color: #004085;
        }
        .status-suspended {
            background: #f8d7da;
            color: #721c24;
        }
        .role-badge {
            padding: 4px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .role-admin {
            background: #343a40;
            color: white;
        }
        .role-client {
            background: #28a745;
            color: white;
        }
        .role-faculty {
            background: #007bff;
            color: white;
        }
        
        .registration-indicator {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            padding: 6px 10px;
            border-radius: 8px;
            background: #f8f9fa;
        }
        
        .registration-indicator.registered {
            background: #d4edda;
            color: #155724;
        }
        
        .registration-indicator.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .stats-row {
            display: flex;
            justify-content: space-around;
            padding: 15px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
            margin: 15px 0;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-item .number {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-item .number.warning { color: #f39c12; }
        .stat-item .number.danger { color: #e74c3c; }
        .stat-item .number.success { color: #27ae60; }
        
        .stat-item .label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            margin-top: 4px;
        }
        
        .patron-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            flex: 1;
            background: black;
            color: white;
            border: none;
            padding: 10px;
            font-size: 13px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s;
            text-decoration: none;
            text-align: center;
        }
        
        .btn-action:hover {
            background: #333;
        }
        
        .btn-action i {
            margin-right: 5px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fa fa-users"></i> Patron Directory</h1>
        <div style="display: flex; gap: 15px;">
            <a href="add_patron.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Patron</a>
            <a href="import_patrons.php" class="btn"><i class="fas fa-file-import"></i> Import Patrons</a>
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="info-banner">
            <h3><i class="fas fa-info-circle"></i> Unified Patron Management</h3>
            <p>This directory shows all patrons in your library system. Patrons are added by administrators, then register their own accounts using their Student ID. Track registration status, account activity, and borrowing statistics all in one place.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Patrons</h3>
                <div class="number"><?php echo $total_patrons; ?></div>
            </div>
            <div class="stat-card">
                <h3>Registered</h3>
                <div class="number"><?php echo $registered_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>Pending Registration</h3>
                <div class="number"><?php echo $pending_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Borrowers</h3>
                <div class="number"><?php echo $active_borrowers; ?></div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Filter & Search Patrons</h2>
            <form method="GET" class="filter-section">
                <input type="text" name="search" placeholder="Search by name, student ID, email, or username..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                
                <select name="registration">
                    <option value="">All Registration Status</option>
                    <option value="registered" <?php echo $registration_filter == 'registered' ? 'selected' : ''; ?>>Registered</option>
                    <option value="pending" <?php echo $registration_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                </select>

                <select name="role">
                    <option value="">All Roles</option>
                    <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="client" <?php echo $role_filter == 'client' ? 'selected' : ''; ?>>Client</option>
                    <option value="faculty" <?php echo $role_filter == 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                </select>

                <select name="status">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="registered" <?php echo $status_filter == 'registered' ? 'selected' : ''; ?>>Registered</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
                
                <button type="submit" class="btn btn-primary1">Apply</button>
                <a href="patron_directory.php" class="btn btn-primary1">Clear</a>
            </form>
        </div>

        <?php if ($patrons && $patrons->num_rows > 0): ?>
            <div class="patrons-grid">
                <?php while($patron = $patrons->fetch_assoc()): 
                    // Get first letter for avatar
                    $initials = strtoupper(substr($patron['patron_name'], 0, 1));
                ?>
                    <div class="patron-card" onclick="window.location.href='patron_profile.php?id=<?php echo htmlspecialchars($patron['patron_id']); ?>'">
                        <div class="patron-header">
                            <div class="patron-avatar"><?php echo $initials; ?></div>
                            <h3><?php echo htmlspecialchars($patron['patron_name']); ?></h3>
                            <div class="student-id">
                                <i class="fas fa-id-card"></i> 
                                <?php echo htmlspecialchars($patron['student_id'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        
                        <div class="patron-body">
                            <div class="patron-badges">
                                <span class="status-badge status-<?php echo htmlspecialchars($patron['account_status']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($patron['account_status'])); ?>
                                </span>
                                <?php if ($patron['role']): ?>
                                    <span class="role-badge role-<?php echo htmlspecialchars($patron['role']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($patron['role'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="patron-info-row">
                                <i class="fas fa-envelope"></i>
                                <span class="value"><?php echo htmlspecialchars($patron['patron_email']); ?></span>
                            </div>
                            
                            <?php if ($patron['user_id']): ?>
                                <div class="patron-info-row">
                                    <i class="fas fa-user"></i>
                                    <span class="value">@<?php echo htmlspecialchars($patron['username']); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="patron-info-row">
                                <i class="fas fa-clock"></i>
                                <span class="label">Last Login:</span>
                                <span class="value">
                                    <?php echo $patron['last_login'] ? date('M d, Y', strtotime($patron['last_login'])) : 'Never'; ?>
                                </span>
                            </div>
                            
                            <div class="stats-row">
                                <div class="stat-item">
                                    <div class="number success"><?php echo htmlspecialchars($patron['total_borrowed']); ?></div>
                                    <div class="label">Total</div>
                                </div>
                                <div class="stat-item">
                                    <div class="number warning"><?php echo htmlspecialchars($patron['currently_borrowed']); ?></div>
                                    <div class="label">Current</div>
                                </div>
                                <div class="stat-item">
                                    <div class="number danger"><?php echo htmlspecialchars($patron['overdue_count']); ?></div>
                                    <div class="label">Overdue</div>
                                </div>
                            </div>
                            
                            <div class="registration-indicator <?php echo $patron['user_id'] ? 'registered' : 'pending'; ?>">
                                <?php if ($patron['user_id']): ?>
                                    <i class="fas fa-check-circle"></i>
                                    <span>Registered Account</span>
                                <?php else: ?>
                                    <i class="fas fa-clock"></i>
                                    <span>Awaiting Registration</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="patron-actions" onclick="event.stopPropagation();">
                                <a href="patron_profile.php?id=<?php echo htmlspecialchars($patron['patron_id']); ?>" 
                                   class="btn-action">
                                    <i class="fas fa-eye"></i> View Profile
                                </a>
                                <a href="edit_patron.php?id=<?php echo htmlspecialchars($patron['patron_id']); ?>" 
                                   class="btn-action">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Patrons Found</h3>
                    <p>Try adjusting your filters or add a new patron to get started.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>