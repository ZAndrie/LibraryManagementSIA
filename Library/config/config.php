<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'library_system');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// CRITICAL FIX: Set charset to utf8mb4 to match database collation
$conn->set_charset("utf8mb4");

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auto-check for overdue books on every page load (runs once per session)
if (!isset($_SESSION['overdue_checked']) || (time() - $_SESSION['overdue_checked']) > 3600) {
    require_once __DIR__ . '/../includes/functions.php';
    checkAndUpdateOverdueBooks($conn);
    $_SESSION['overdue_checked'] = time();
}
?>