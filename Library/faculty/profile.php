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

$stats = getFacultyStats($conn, $user['id']);
// FIX: Ensure stats has defaults
$stats = array_merge([
    'total_borrowed' => 0,
    'currently_borrowed' => 0,
    'total_returned' => 0,
    'overdue_count' => 0,
    'reservations_count' => 0
], $stats ?? []);

$permissions = getFacultyPermissions($user['id']);
// FIX: Ensure permissions has defaults
$permissions = array_merge([
    'can_search_books' => 1,
    'can_borrow_books' => 1,
    'can_make_reservations' => 1,
    'can_view_own_history' => 1,
    'max_borrow_limit' => 10
], $permissions ?? []);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Faculty Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 250px; height: 100vh;
            background: linear-gradient(135deg, #080808ff 0%, grey 100%);
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
        
        .profile-grid {
            display: grid; grid-template-columns: 1fr 2fr; gap: 30px;
        }
        .profile-card {
            background: white; padding: 30px; border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .profile-avatar {
            width: 120px; height: 120px; border-radius: 50%;
           background: linear-gradient(135deg, #080808ff 0%, grey 100%);
            display: flex; align-items: center; justify-content: center;
            font-size: 48px; color: white; margin: 0 auto 20px;
        }
        .profile-name {
            text-align: center; font-size: 22px; font-weight: 600;
            color: #333; margin-bottom: 5px;
        }
        .profile-role {
            text-align: center; color: #666; margin-bottom: 20px;
            padding-bottom: 20px; border-bottom: 2px solid #f0f0f0;
        }
        .info-row {
            display: flex; justify-content: space-between; padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-label { color: #666; font-weight: 600; }
        .info-value { color: #333; }
        
        .section-title {
            font-size: 20px; color: #333; margin-bottom: 20px;
            padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;
        }
        .permission-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;
        }
        .permission-item {
            padding: 15px; background: #f8f9fa; border-radius: 10px;
            display: flex; align-items: center; gap: 10px;
        }
        .permission-item.enabled {
            background: #d4edda; color: #155724;
        }
        .permission-item.disabled {
            background: #f8d7da; color: #721c24;
        }
        .stat-box {
            padding: 20px; background: #f8f9fa; border-radius: 12px;
            margin-bottom: 15px; display: flex; justify-content: space-between;
            align-items: center;
        }
        .stat-box h3 { font-size: 28px; color: #000000ff; }
        .stat-box p { color: #666; font-size: 14px; }
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
            <li><a href="reservations.php"><i class="fas fa-bookmark"></i> My Reservations</a></li>
            <li><a href="profile.php" class="active"><i class="fas fa-user"></i> My Profile</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-user"></i> My Profile</h1>
            <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="profile-grid">
            <div class="profile-card">
                <div class="profile-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="profile-name"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></div>
                <div class="profile-role">
                    <i class="fas fa-chalkboard-teacher"></i> Faculty Member
                </div>
                
                <div class="info-row">
                    <span class="info-label">Username:</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['username'] ?? ''); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Account Type:</span>
                    <span class="info-value">Faculty</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Member Since:</span>
                    <span class="info-value"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                </div>
            </div>
            
            <div>
                <div class="profile-card" style="margin-bottom: 20px;">
                    <h2 class="section-title"><i class="fas fa-chart-bar"></i> My Statistics</h2>
                    
                    <div class="stat-box">
                        <div>
                            <p>Total Borrowed</p>
                            <h3><?php echo $stats['total_borrowed']; ?></h3>
                        </div>
                        <i class="fas fa-book" style="font-size: 36px; color: #1976d2; opacity: 0.2;"></i>
                    </div>
                    
                    <div class="stat-box">
                        <div>
                            <p>Currently Borrowed</p>
                            <h3 style="color: #000000ff;"><?php echo $stats['currently_borrowed']; ?></h3>
                        </div>
                        <i class="fas fa-book-open" style="font-size: 36px; color: #f57c00; opacity: 0.2;"></i>
                    </div>
                    
                    <div class="stat-box">
                        <div>
                            <p>Total Returned</p>
                            <h3 style="color: #000000ff;"><?php echo $stats['total_returned']; ?></h3>
                        </div>
                        <i class="fas fa-check-circle" style="font-size: 36px; color: #388e3c; opacity: 0.2;"></i>
                    </div>
                    
                    <div class="stat-box">
                        <div>
                            <p>Active Reservations</p>
                            <h3 style="color: #000000ff;"><?php echo $stats['reservations_count']; ?></h3>
                        </div>
                        <i class="fas fa-bookmark" style="font-size: 36px; color: #17a2b8; opacity: 0.2;"></i>
                    </div>
                </div>
                
                <div class="profile-card">
                    <h2 class="section-title"><i class="fas fa-shield-alt"></i> Permissions & Access</h2>
                    
                    <div class="permission-grid">
                        <div class="permission-item <?php echo $permissions['can_search_books'] ? 'enabled' : 'disabled'; ?>">
                            <i class="fas fa-<?php echo $permissions['can_search_books'] ? 'check-circle' : 'times-circle'; ?>"></i>
                            <span>Search Books</span>
                        </div>
                        
                        <div class="permission-item <?php echo $permissions['can_borrow_books'] ? 'enabled' : 'disabled'; ?>">
                            <i class="fas fa-<?php echo $permissions['can_borrow_books'] ? 'check-circle' : 'times-circle'; ?>"></i>
                            <span>Borrow Books</span>
                        </div>
                        
                        <div class="permission-item <?php echo $permissions['can_make_reservations'] ? 'enabled' : 'disabled'; ?>">
                            <i class="fas fa-<?php echo $permissions['can_make_reservations'] ? 'check-circle' : 'times-circle'; ?>"></i>
                            <span>Make Reservations</span>
                        </div>
                        
                        <div class="permission-item <?php echo $permissions['can_view_own_history'] ? 'enabled' : 'disabled'; ?>">
                            <i class="fas fa-<?php echo $permissions['can_view_own_history'] ? 'check-circle' : 'times-circle'; ?>"></i>
                            <span>View History</span>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #fff3e0; border-radius: 10px;">
                        <strong style="color: #f57c00;">Borrowing Limit:</strong>
                        <p style="color: #333; margin-top: 5px;">
                            You can borrow up to <strong><?php echo $permissions['max_borrow_limit']; ?> books</strong> at a time.
                            Currently borrowed: <strong><?php echo $stats['currently_borrowed']; ?></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>