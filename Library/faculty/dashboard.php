<?php
require_once '../auth/check_auth.php';
checkRole('faculty');
require_once '../includes/functions.php';

$user = getCurrentUser();

// Ensure user has required fields with defaults
$user['id'] = $user['id'] ?? 0;
$user['full_name'] = $user['full_name'] ?? 'User';
$user['email'] = $user['email'] ?? '';

$permissions = getFacultyPermissions($user['id']);

// Ensure permissions has defaults
$permissions = array_merge([
    'can_search_books' => 1,
    'can_borrow_books' => 1,
    'can_make_reservations' => 1,
    'can_view_own_history' => 1,
    'max_borrow_limit' => 10
], $permissions ?? []);

// Get faculty statistics
$patron_id = getOrCreatePatronForUser($conn, $user['id']);
$stats = getUserBorrowingStats($conn, $user['email']);

// Ensure stats has defaults
$stats = array_merge([
    'total_borrowed' => 0,
    'currently_borrowed' => 0,
    'total_returned' => 0,
    'overdue_count' => 0
], $stats ?? []);

// Get currently borrowed books
$current_borrowings = getUserCurrentBorrowings($conn, $user['email']);

// Get reservations
$sql = "SELECT br.*, b.title, b.author, b.available 
        FROM book_reservations br
        JOIN books b ON br.book_id = b.id
        WHERE br.user_id = ? AND br.status IN ('pending', 'ready')
        ORDER BY br.reservation_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$reservations = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            height: 100vh;
            background: linear-gradient(135deg, black 0%, grey 100%);
            color: white;
            padding: 20px;
            overflow-y: auto;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(255,255,255,0.2);
        }
        .logo i {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .logo h2 {
            font-size: 20px;
        }
        .user-info {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .user-info h3 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        .user-info p {
            font-size: 12px;
            opacity: 0.9;
        }
        .nav-menu {
            list-style: none;
        }
        .nav-menu li {
            margin-bottom: 5px;
        }
        .nav-menu a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .nav-menu a:hover,
        .nav-menu a.active {
            background: rgba(255,255,255,0.2);
        }
        .nav-menu i {
            margin-right: 10px;
            width: 20px;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 250px;
            padding: 30px;
        }
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 28px;
            color: #333;
        }
        .logout-btn {
            background: black;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .logout-btn:hover {
            background: grey;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        .stat-icon.blue { background: #3d3d3dff; color: #ffffffff; }
        .stat-icon.green { background: #3d3d3dff; color: #ffffffff; }
        .stat-icon.orange { background: #3d3d3dff; color: #ffffffff; }
        .stat-icon.red { background: #3d3d3dff; color: #ffffffff; }
        .stat-info h3 {
            font-size: 32px;
            margin-bottom: 5px;
            color: #333;
        }
        .stat-info p {
            color: #666;
            font-size: 14px;
        }
        
        /* Content Section */
        .content-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        .section-header h2 {
            font-size: 20px;
            color: #333;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #000000ff;
            color: white;
        }
        .btn-primary:hover {
            background: grey;
        }
        
        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-book-reader"></i>
            <h2>Faculty Portal</h2>
        </div>
        
        <div class="user-info">
            <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
            <p><i class="fas fa-chalkboard-teacher"></i> Faculty Member</p>
            <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
        </div>
        
        <ul class="nav-menu">
            <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="search_books.php"><i class="fas fa-search"></i> Search Books</a></li>
            <li><a href="my_borrowings.php"><i class="fas fa-book"></i> My Borrowings</a></li>
            <li><a href="reservations.php"><i class="fas fa-bookmark"></i> My Reservations</a></li>
            <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-tachometer-alt"></i> Faculty Dashboard</h1>
            <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['currently_borrowed']; ?></h3>
                   <p>Currently Borrowed</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_returned']; ?></h3>
                    <p>Total Returned</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-bookmark"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $reservations->num_rows; ?></h3>
                    <p>Active Reservations</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon red">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['overdue_count']; ?></h3>
                    <p>Overdue Books</p>
                </div>
            </div>
        </div>
        
        <!-- Currently Borrowed Books -->
        <div class="content-section">
            <div class="section-header">
                <h2><i class="fas fa-book-open"></i> Currently Borrowed Books</h2>
                <a href="search_books.php" class="btn btn-primary"><i class="fas fa-search"></i> Search More Books</a>
            </div>
            
            <?php if ($current_borrowings && $current_borrowings->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>Borrowed Date</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $current_borrowings->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['title'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['author'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['category'] ?? ''); ?></td>
                            <td><?php echo isset($row['borrow_date']) ? date('M d, Y', strtotime($row['borrow_date'])) : ''; ?></td>
                            <td><?php echo isset($row['due_date']) ? date('M d, Y', strtotime($row['due_date'])) : ''; ?></td>
                            <td>
                                <?php if (isset($row['status_flag']) && $row['status_flag'] == 'overdue'): ?>
                                    <span class="badge badge-danger">
                                        <i class="fas fa-exclamation-triangle"></i> Overdue (<?php echo $row['days_overdue'] ?? 0; ?> days)
                                    </span>
                                <?php elseif (isset($row['status_flag']) && $row['status_flag'] == 'due_soon'): ?>
                                    <span class="badge badge-warning">
                                        <i class="fas fa-clock"></i> Due in <?php echo $row['days_until_due'] ?? 0; ?> days
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-success">
                                        <i class="fas fa-check"></i> On Time
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <p>You don't have any borrowed books at the moment.</p>
                    <a href="search_books.php" class="btn btn-primary" style="margin-top: 15px;">
                        Browse Books
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Active Reservations -->
        <?php if ($reservations->num_rows > 0): ?>
        <div class="content-section">
            <div class="section-header">
                <h2><i class="fas fa-bookmark"></i> Active Reservations</h2>
                <a href="reservations.php" class="btn btn-primary">View All</a>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Book Title</th>
                        <th>Author</th>
                        <th>Reserved Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $reservations->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['title'] ?? ''); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['author'] ?? ''); ?></td>
                        <td><?php echo isset($row['reservation_date']) ? date('M d, Y', strtotime($row['reservation_date'])) : ''; ?></td>
                        <td>
                            <?php if (isset($row['status']) && $row['status'] == 'ready'): ?>
                                <span class="badge badge-success">Ready for Pickup</span>
                            <?php else: ?>
                                <span class="badge badge-info">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (isset($row['status']) && $row['status'] == 'ready'): ?>
                                <span style="color: #28a745; font-weight: 600;">
                                    <i class="fas fa-check-circle"></i> Available Now
                                </span>
                            <?php else: ?>
                                <a href="reservations.php?cancel=<?php echo $row['id'] ?? 0; ?>" 
                                   onclick="return confirm('Cancel this reservation?')"
                                   style="color: #dc3545; text-decoration: none;">
                                    <i class="fas fa-times-circle"></i> Cancel
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>