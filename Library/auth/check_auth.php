<?php
require_once __DIR__ . '/../config/config.php';

// Allow guest users to access client pages
$is_guest = (isset($_SESSION['role']) && $_SESSION['role'] == 'guest');

// Check if user is logged in (or is guest)
if (!isset($_SESSION['user_id']) && !$is_guest) {
    header("Location: " . dirname($_SERVER['PHP_SELF']) . "/../auth/login.php");
    exit();
}

// Guest users can only access client pages
if ($is_guest) {
    $current_folder = basename(dirname($_SERVER['SCRIPT_FILENAME']));
    if ($current_folder == 'admin' || $current_folder == 'faculty') {
        header("Location: " . dirname($_SERVER['PHP_SELF']) . "/../auth/login.php");
        exit();
    }
}

// ============================================
// CHECK FOR SUSPENDED ACCOUNTS (CLIENT & FACULTY)
// ============================================
if (!$is_guest && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // Check patron account status for clients and faculty
    $check_suspension_sql = "SELECT u.role, u.email, p.account_status 
                             FROM users u 
                             LEFT JOIN patrons p ON u.id = p.user_id 
                             WHERE u.id = ?";
    $stmt = $conn->prepare($check_suspension_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        
        // Check suspension for CLIENTS
        if ($user_data['role'] == 'client') {
            $patron_status = $user_data['account_status'];
            
            // If patron is suspended, log them out immediately
            if ($patron_status == 'suspended') {
                // Log the suspension access attempt
                $log_sql = "INSERT INTO activity_logs (user_id, action, description, ip_address) 
                           VALUES (?, 'SUSPENDED_ACCESS_ATTEMPT', 'Suspended client attempted to access system', ?)";
                $log_stmt = $conn->prepare($log_sql);
                $ip = $_SERVER['REMOTE_ADDR'];
                $log_stmt->bind_param("is", $user_id, $ip);
                $log_stmt->execute();
                
                // Destroy session
                session_unset();
                session_destroy();
                
                // Redirect to login with suspension message
                header("Location: " . dirname($_SERVER['PHP_SELF']) . "/../auth/login.php?suspended=1");
                exit();
            }
            
            // Also block if account is still pending
            if ($patron_status == 'pending') {
                session_unset();
                session_destroy();
                header("Location: " . dirname($_SERVER['PHP_SELF']) . "/../auth/login.php?pending=1");
                exit();
            }
        }
        
        // Check suspension for FACULTY (separate block, not nested)
        if ($user_data['role'] == 'faculty') {
            $patron_status = $user_data['account_status'];
            
            // If faculty is suspended, log them out immediately
            if ($patron_status == 'suspended') {
                // Log the suspension access attempt
                $log_sql = "INSERT INTO activity_logs (user_id, action, description, ip_address) 
                           VALUES (?, 'SUSPENDED_ACCESS_ATTEMPT', 'Suspended faculty attempted to access system', ?)";
                $log_stmt = $conn->prepare($log_sql);
                $ip = $_SERVER['REMOTE_ADDR'];
                $log_stmt->bind_param("is", $user_id, $ip);
                $log_stmt->execute();
                
                // Destroy session
                session_unset();
                session_destroy();
                
                // Redirect to login with suspension message
                header("Location: " . dirname($_SERVER['PHP_SELF']) . "/../auth/login.php?suspended=1");
                exit();
            }
            
            // Also block if account is still pending
            if ($patron_status == 'pending') {
                session_unset();
                session_destroy();
                header("Location: " . dirname($_SERVER['PHP_SELF']) . "/../auth/login.php?pending=1");
                exit();
            }
        }
    }
}

// Check session timeout (30 minutes) - skip for guests
if (!$is_guest && isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../includes/functions.php';
    checkSessionTimeout($conn, 30);
}

// Check if specific role is required
function checkRole($required_role) {
    // Allow guest to pass as client
    if ($required_role == 'client' && isset($_SESSION['role']) && $_SESSION['role'] == 'guest') {
        return;
    }
    
    // Faculty can access client features
    if ($required_role == 'client' && isset($_SESSION['role']) && $_SESSION['role'] == 'faculty') {
        return;
    }
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $required_role) {
        header("Location: " . dirname($_SERVER['PHP_SELF']) . "/../auth/login.php");
        exit();
    }
}

// Check if user has specific permission (for faculty)
function checkPermission($permission) {
    global $conn;
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        return false;
    }
    
    // Admin has all permissions
    if ($_SESSION['role'] == 'admin') {
        return true;
    }
    
    // Check faculty permissions
    if ($_SESSION['role'] == 'faculty') {
        $user_id = $_SESSION['user_id'];
        $sql = "SELECT {$permission} FROM faculty_permissions WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $perms = $result->fetch_assoc();
            return $perms[$permission] == 1;
        }
        
        return false;
    }
    
    return false;
}

// Get current user info
function getCurrentUser() {
    global $conn;
    
    // Return guest user info
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'guest') {
        return [
            'id' => 0,
            'username' => 'guest',
            'full_name' => 'Guest User',
            'email' => 'guest@library.system',
            'role' => 'guest'
        ];
    }
    
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT u.*, fp.* FROM users u 
            LEFT JOIN faculty_permissions fp ON u.id = fp.user_id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get faculty permissions
function getFacultyPermissions($user_id) {
    global $conn;
    
    $sql = "SELECT * FROM faculty_permissions WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    // Return default permissions if not found
    return [
        'can_search_books' => 1,
        'can_borrow_books' => 1,
        'can_view_own_history' => 1,
        'can_make_reservations' => 1,
        'max_borrow_limit' => 10
    ];
}
?>  