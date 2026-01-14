<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Safe activity logging - only log if user exists
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // Verify user exists before attempting to log
    $check_sql = "SELECT id FROM users WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if ($check_stmt) {
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        // Only log if user exists in database
        if ($result->num_rows > 0) {
            logActivity($conn, $user_id, 'LOGOUT', 'User logged out');
        }
        $check_stmt->close();
    }
    
    // Delete session regardless
    deleteUserSession($conn, session_id());
}

// Destroy session
session_unset();
session_destroy();
header("Location: ../auth/login.php");
exit();
?>