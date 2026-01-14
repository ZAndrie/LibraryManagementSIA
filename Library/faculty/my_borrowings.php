<?php
require_once '../auth/check_auth.php';
checkRole('faculty');
require_once '../includes/functions.php';

$user = getCurrentUser();
$user = array_merge([
    'id' => 0,
    'username' => 'user',
    'full_name' => 'User',
    'email' => '',
    'role' => 'faculty',
    'created_at' => date('Y-m-d H:i:s')
], $user ?? []);

// Get patron ID
$patron_id = getOrCreatePatronForUser($conn, $user['id']);

// FIXED: Get borrowing history INCLUDING PENDING AND REJECTED
$sql = "SELECT b.*, bk.title, bk.author, bk.category,
        CASE 
            WHEN b.status = 'pending' THEN 'pending'
            WHEN b.status = 'rejected' THEN 'rejected'
            WHEN b.status = 'borrowed' AND b.due_date < CURDATE() THEN 'overdue'
            WHEN b.status = 'borrowed' THEN 'borrowed'
            WHEN b.status = 'returned' THEN 'returned'
            ELSE b.status
        END as current_status,
        DATEDIFF(CURDATE(), b.due_date) as days_overdue,
        DATEDIFF(b.due_date, CURDATE()) as days_until_due
        FROM borrowings b
        JOIN books bk ON b.book_id = bk.id
        WHERE b.patron_id = ?
        ORDER BY 
            CASE 
                WHEN b.status = 'pending' THEN 1
                WHEN b.status = 'borrowed' THEN 2
                WHEN b.status = 'overdue' THEN 3
                WHEN b.status = 'returned' THEN 4
                WHEN b.status = 'rejected' THEN 5
                ELSE 6
            END,
            b.borrow_date DESC
        LIMIT 50";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patron_id);
$stmt->execute();
$history = $stmt->get_result();

// FIXED: Get stats INCLUDING PENDING AND REJECTED
$stats_sql = "SELECT 
    COUNT(*) as total_borrowed,
    SUM(CASE WHEN status IN ('borrowed', 'overdue') THEN 1 ELSE 0 END) as currently_borrowed,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as total_returned,
    SUM(CASE WHEN status = 'borrowed' AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
    FROM borrowings WHERE patron_id = ?";
$stmt_stats = $conn->prepare($stats_sql);
$stmt_stats->bind_param("i", $patron_id);
$stmt_stats->execute();
$stats = $stmt_stats->get_result()->fetch_assoc();

$stats = array_merge([
    'total_borrowed' => 0,
    'currently_borrowed' => 0,
    'pending_count' => 0,
    'total_returned' => 0,
    'overdue_count' => 0,
    'rejected_count' => 0
], $stats ?? []);

// Check if coming from successful request
$show_pending_message = isset($_GET['pending']) && $_GET['pending'] == 1;
if ($show_pending_message && isset($_SESSION['success_message'])) {
    $pending_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Borrowings - Faculty Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 250px; height: 100vh;
            background: linear-gradient(135deg, black 0%, grey 100%);
            color: white; padding: 20px; overflow-y: auto;
        }
        .logo { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid rgba(255,255,255,0.2); }
        .logo i { font-size: 48px; margin-bottom: 10px; }
        .logo h2 { font-size: 20px; }
        .user-info { background: rgba(255,255,255,0.1); padding: 15px; border-radius: 10px; margin-bottom: 30px; }
        .user-info h3 { font-size: 16px; margin-bottom: 5px; }
        .user-info p { font-size: 12px; opacity: 0.9; }
        .nav-menu { list-style: none; }
        .nav-menu li { margin-bottom: 5px; }
        .nav-menu a {
            display: flex; align-items: center; padding: 12px 15px; color: white;
            text-decoration: none; border-radius: 8px; transition: all 0.3s;
        }
        .nav-menu a:hover, .nav-menu a.active { background: rgba(255,255,255,0.2); }
        .nav-menu i { margin-right: 10px; width: 20px; }
        
        .main-content { margin-left: 250px; padding: 30px; }
        .header {
            background: white; padding: 20px 30px; border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { font-size: 28px; color: #333; }
        .logout-btn {
            background: #080808ff; color: white; padding: 10px 20px;
            border: none; border-radius: 8px; text-decoration: none;
            font-size: 14px; cursor: pointer; transition: all 0.3s;
        }
        .logout-btn:hover { background: grey; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .stats-row {
            display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin-bottom: 30px;
        }
        .stat-box {
            background: white; padding: 20px; border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center;
        }
        .stat-box h3 { font-size: 32px; margin-bottom: 5px; }
        .stat-box p { color: #666; font-size: 14px; }
        .stat-box.blue h3 { color: #080808ff; }
        .stat-box.green h3 { color: #080808ff; }
        .stat-box.orange h3 { color: #080808ff; }
        .stat-box.red h3 { color: #080808ff; }
        .stat-box.pending h3 { color: #ffc107; }
        
        .content-section {
            background: white; padding: 25px; border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-header {
            margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;
        }
        .section-header h2 { font-size: 20px; color: #333; }
        
        .filter-tabs {
            display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .filter-tab {
            padding: 10px 20px; border: 2px solid #e0e0e0; border-radius: 8px;
            background: white; cursor: pointer; transition: all 0.3s;
        }
        .filter-tab.active {
            background: #080808ff; color: white; border-color: #080808ff;
        }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        th { background: #f8f9fa; font-weight: 600; color: #333; }
        .badge {
            padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-rejected { background: #e2e3e5; color: #383d41; }
        
        .empty-state {
            text-align: center; padding: 60px 20px;
        }
        .empty-state i { font-size: 64px; color: #ccc; margin-bottom: 20px; }
        .empty-state h3 { font-size: 24px; color: #666; margin-bottom: 10px; }
        .empty-state p { color: #999; }
        
        .notes-section {
            margin-top: 5px;
            padding: 8px;
            background: #fff3cd;
            border-radius: 5px;
            font-size: 12px;
            color: #856404;
        }
    </style>
    <script>
        function filterTable(status) {
            const rows = document.querySelectorAll('.borrowing-row');
            const tabs = document.querySelectorAll('.filter-tab');
            
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
</head>
<body>
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-book-reader"></i>
            <h2>Faculty Portal</h2>
        </div>
        <div class="user-info">
            <h3><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></h3>
            <p><i class="fas fa-chalkboard-teacher"></i> Faculty Member</p>
        </div>
        <ul class="nav-menu">
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="search_books.php"><i class="fas fa-search"></i> Search Books</a></li>
            <li><a href="my_borrowings.php" class="active"><i class="fas fa-book"></i> My Borrowings</a></li>
            <li><a href="reservations.php"><i class="fas fa-bookmark"></i> My Reservations</a></li>
            <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-book"></i> My Borrowings</h1>
            <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <?php if ($show_pending_message && isset($pending_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($pending_message); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($stats['pending_count'] > 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock"></i>
            You have <strong><?php echo $stats['pending_count']; ?></strong> borrow request(s) waiting for admin approval.
        </div>
        <?php endif; ?>
        
        <div class="stats-row">
            <div class="stat-box blue">
                <h3><?php echo $stats['total_borrowed']; ?></h3>
                <p>Total Borrowed</p>
            </div>
            <div class="stat-box pending">
                <h3><?php echo $stats['pending_count']; ?></h3>
                <p>Pending Approval</p>
            </div>
            <div class="stat-box orange">
                <h3><?php echo $stats['currently_borrowed']; ?></h3>
                <p>Currently Borrowed</p>
            </div>
            <div class="stat-box green">
                <h3><?php echo $stats['total_returned']; ?></h3>
                <p>Returned</p>
            </div>
            <div class="stat-box red">
                <h3><?php echo $stats['overdue_count']; ?></h3>
                <p>Overdue</p>
            </div>
        </div>
        
        <div class="content-section">
            <div class="section-header">
                <h2>Borrowing History</h2>
            </div>
            
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="filterTable('all')">All</button>
                <button class="filter-tab" onclick="filterTable('pending')">Pending</button>
                <button class="filter-tab" onclick="filterTable('borrowed')">Borrowed</button>
                <button class="filter-tab" onclick="filterTable('returned')">Returned</button>
                <button class="filter-tab" onclick="filterTable('overdue')">Overdue</button>
                <button class="filter-tab" onclick="filterTable('rejected')">Rejected</button>
            </div>
            
            <?php if ($history->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>Request Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $history->fetch_assoc()): ?>
                        <tr class="borrowing-row" data-status="<?php echo $row['current_status']; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($row['title'] ?? ''); ?></strong>
                                <?php if ($row['current_status'] == 'rejected' && !empty($row['notes'])): ?>
                                    <div class="notes-section">
                                        <strong><i class="fas fa-info-circle"></i> Rejection Reason:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($row['notes'])); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['author'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['category'] ?? ''); ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['borrow_date'])); ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['due_date'])); ?></td>
                            <td><?php echo $row['return_date'] ? date('M d, Y', strtotime($row['return_date'])) : '-'; ?></td>
                            <td>
                                <?php if ($row['current_status'] == 'pending'): ?>
                                    <span class="badge badge-pending">
                                        <i class="fas fa-clock"></i> Pending Approval
                                    </span>
                                <?php elseif ($row['current_status'] == 'rejected'): ?>
                                    <span class="badge badge-rejected">
                                        <i class="fas fa-times-circle"></i> Rejected
                                    </span>
                                <?php elseif ($row['current_status'] == 'returned'): ?>
                                    <span class="badge badge-success">
                                        <i class="fas fa-check"></i> Returned
                                    </span>
                                <?php elseif ($row['current_status'] == 'overdue'): ?>
                                    <span class="badge badge-danger">
                                        <i class="fas fa-exclamation-triangle"></i> Overdue (<?php echo $row['days_overdue']; ?> days)
                                    </span>
                                <?php else: ?>
                                    <?php if ($row['days_until_due'] <= 3 && $row['days_until_due'] >= 0): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-clock"></i> Due in <?php echo $row['days_until_due']; ?> day<?php echo $row['days_until_due'] != 1 ? 's' : ''; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info">
                                            <i class="fas fa-book-open"></i> Borrowed
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>No borrowing history</h3>
                    <p>You haven't borrowed any books yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>