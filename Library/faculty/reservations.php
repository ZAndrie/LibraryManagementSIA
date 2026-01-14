<?php
require_once '../auth/check_auth.php';
checkRole('faculty');
require_once '../includes/functions.php';

$user = getCurrentUser();
// FIX: Ensure user has required fields with defaults
$user = array_merge([
    'id' => 0,
    'username' => 'user',
    'full_name' => 'User',
    'email' => '',
    'role' => 'faculty',
    'created_at' => date('Y-m-d H:i:s')
], $user ?? []);

$permissions = getFacultyPermissions($user['id']);
// FIX: Ensure permissions has defaults
$permissions = array_merge([
    'can_search_books' => 1,
    'can_borrow_books' => 1,
    'can_make_reservations' => 1,
    'can_view_own_history' => 1,
    'max_borrow_limit' => 10
], $permissions ?? []);


$message = '';
$message_type = '';

// Handle cancel reservation
if (isset($_GET['cancel'])) {
    $reservation_id = intval($_GET['cancel']);
    $result = cancelReservation($conn, $reservation_id, $user['id']);
    $message = $result['message'];
    $message_type = $result['success'] ? 'success' : 'error';
}

// Get all reservations
$reservations = getFacultyReservations($conn, $user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations - Faculty Portal</title>
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
            background: black; color: white; padding: 10px 20px;
            border: none; border-radius: 8px; text-decoration: none;
            font-size: 14px; cursor: pointer; transition: all 0.3s;
        }
        .logout-btn:hover { background: grey; }
        
        .alert {
            padding: 15px 20px; border-radius: 10px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .content-section {
            background: white; padding: 25px; border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-header {
            margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .section-header h2 { font-size: 20px; color: #333; }
        
        .info-box {
            background: lightgrey; border-left: 4px solid black;
            padding: 15px; border-radius: 8px; margin-bottom: 20px;
        }
        .info-box h3 { color: black; font-size: 16px; margin-bottom: 5px; }
        .info-box p { color: #333; font-size: 14px; line-height: 1.6; }
        
        .reservations-grid {
            display: grid; gap: 20px;
        }
        .reservation-card {
            border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px;
            transition: all 0.3s;
        }
        .reservation-card:hover {
            border-color: #1976d2; box-shadow: 0 4px 12px rgba(25, 118, 210, 0.1);
        }
        .reservation-card.ready {
            border-color: #28a745; background: #f8fff9;
        }
        .reservation-card.expired {
            opacity: 0.6; background: #f5f5f5;
        }
        
        .card-header {
            display: flex; justify-content: space-between; align-items: start;
            margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #e0e0e0;
        }
        .book-info h3 {
            font-size: 18px; color: #333; margin-bottom: 5px;
        }
        .book-info p {
            color: #666; font-size: 14px;
        }
        .card-status {
            padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-ready { background: #d4edda; color: #155724; }
        .status-expired { background: #f8d7da; color: #721c24; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        
        .card-details {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;
            margin-bottom: 15px;
        }
        .detail-item {
            display: flex; flex-direction: column; gap: 5px;
        }
        .detail-label {
            font-size: 12px; color: #666; text-transform: uppercase;
        }
        .detail-value {
            font-size: 14px; color: #333; font-weight: 600;
        }
        
        .card-actions {
            display: flex; gap: 10px; margin-top: 15px; padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }
        .btn {
            padding: 10px 20px; border: none; border-radius: 8px;
            font-size: 14px; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.3s;
        }
        .btn-danger {
            background: #dc3545; color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-success {
            background: #28a745; color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        
        .empty-state {
            text-align: center; padding: 60px 20px;
        }
        .empty-state i { font-size: 64px; color: #ccc; margin-bottom: 20px; }
        .empty-state h3 { font-size: 24px; color: #666; margin-bottom: 10px; }
        .empty-state p { color: #999; margin-bottom: 20px; }
        .btn-primary {
            background: #000000ff; color: white;
        }
        .btn-primary:hover {
            background: grey;
        }
    </style>
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
            <li><a href="my_borrowings.php"><i class="fas fa-book"></i> My Borrowings</a></li>
            <li><a href="reservations.php" class="active"><i class="fas fa-bookmark"></i> My Reservations</a></li>
            <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-bookmark"></i> My Reservations</h1>
            <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="content-section">
            <div class="section-header">
                <h2>All Reservations</h2>
                <a href="search_books.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Reserve More Books
                </a>
            </div>
            
            <div class="info-box">
                <h3><i class="fas fa-info-circle"></i> About Reservations</h3>
                <p>When a book you want is unavailable, you can reserve it. You'll be notified when it becomes available. Reservations expire after 3 days if not picked up.</p>
            </div>
            
            <?php if ($reservations->num_rows > 0): ?>
                <div class="reservations-grid">
                    <?php while ($res = $reservations->fetch_assoc()): ?>
                    <div class="reservation-card <?php echo $res['status']; ?>">
                        <div class="card-header">
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($res['title'] ?? ''); ?></h3>
                                <p>by <?php echo htmlspecialchars($res['author'] ?? ''); ?></p>
                            </div>
                            <div class="card-status status-<?php echo $res['status']; ?>">
                                <?php
                                    $status_icons = [
                                        'pending' => 'clock',
                                        'ready' => 'check-circle',
                                        'expired' => 'times-circle',
                                        'cancelled' => 'ban',
                                        'fulfilled' => 'check'
                                    ];
                                    $status_labels = [
                                        'pending' => 'Pending',
                                        'ready' => 'Ready',
                                        'expired' => 'Expired',
                                        'cancelled' => 'Cancelled',
                                        'fulfilled' => 'Fulfilled'
                                    ];
                                ?>
                                <i class="fas fa-<?php echo $status_icons[$res['status']]; ?>"></i>
                                <?php echo $status_labels[$res['status']]; ?>
                            </div>
                        </div>
                        
                        <div class="card-details">
                            <div class="detail-item">
                                <span class="detail-label">Category</span>
                                <span class="detail-value"><?php echo htmlspecialchars($res['category'] ?? ''); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Reserved On</span>
                                <span class="detail-value"><?php echo date('M d, Y', strtotime($res['reservation_date'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Expires On</span>
                                <span class="detail-value"><?php echo date('M d, Y', strtotime($res['expiry_date'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Available Copies</span>
                                <span class="detail-value"><?php echo $res['available']; ?> copies</span>
                            </div>
                        </div>
                        
                        <?php if ($res['notes']): ?>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 6px; margin-bottom: 15px;">
                            <small style="color: #666;">Note: <?php echo htmlspecialchars($res['notes'] ?? ''); ?></small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="card-actions">
                            <?php if ($res['status'] == 'ready'): ?>
                                <span style="color: #28a745; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-check-circle"></i>
                                    Book is ready for pickup! Visit the library to borrow it.
                                </span>
                            <?php elseif ($res['status'] == 'pending'): ?>
                                <a href="?cancel=<?php echo $res['id']; ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('Are you sure you want to cancel this reservation?')">
                                    <i class="fas fa-times"></i> Cancel Reservation
                                </a>
                                
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bookmark"></i>
                    <h3>No Reservations</h3>
                    <p>You don't have any book reservations at the moment.</p>
                    <a href="search_books.php" class="btn btn-primary">
                         Browse Books
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>