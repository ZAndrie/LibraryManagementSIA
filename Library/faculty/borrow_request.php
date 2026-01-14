<?php
require_once '../auth/check_auth.php';
checkRole('faculty');
require_once '../includes/functions.php';

$user = getCurrentUser();
$message = '';
$message_type = '';

// ============================================
// PATRON SETUP
// ============================================
$patron_id = null;
$patron_setup_error = false;
$error_details = '';

try {
    if (empty($user['id']) || empty($user['email'])) {
        throw new Exception("User session data is incomplete. Please log out and log back in.");
    }
    
    if (empty($user['full_name'])) {
        $stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $user_data = $stmt->get_result()->fetch_assoc();
        
        if (!$user_data || empty($user_data['full_name'])) {
            throw new Exception("Your account profile is incomplete. Please contact an administrator.");
        }
        
        $user['full_name'] = $user_data['full_name'];
        $user['email'] = $user_data['email'];
    }
    
    $stmt = $conn->prepare("SELECT patron_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $user_record = $stmt->get_result()->fetch_assoc();
    
    if ($user_record && !empty($user_record['patron_id'])) {
        $stmt = $conn->prepare("SELECT id, name, email FROM patrons WHERE id = ?");
        $stmt->bind_param("i", $user_record['patron_id']);
        $stmt->execute();
        $patron_exists = $stmt->get_result()->fetch_assoc();
        
        if ($patron_exists) {
            $patron_id = $user_record['patron_id'];
        }
    }
    
    if (!$patron_id) {
        $stmt = $conn->prepare("SELECT id, user_id FROM patrons WHERE email = ?");
        $stmt->bind_param("s", $user['email']);
        $stmt->execute();
        $patron_by_email = $stmt->get_result()->fetch_assoc();
        
        if ($patron_by_email) {
            $patron_id = $patron_by_email['id'];
            
            if (empty($patron_by_email['user_id']) || $patron_by_email['user_id'] != $user['id']) {
                $stmt = $conn->prepare("UPDATE patrons SET user_id = ?, account_status = 'active' WHERE id = ?");
                $stmt->bind_param("ii", $user['id'], $patron_id);
                $stmt->execute();
            }
            
            $stmt = $conn->prepare("UPDATE users SET patron_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $patron_id, $user['id']);
            $stmt->execute();
        }
    }
    
    if (!$patron_id) {
        if (empty($user['full_name'])) {
            throw new Exception("Cannot create patron: Name is required");
        }
        if (empty($user['email'])) {
            throw new Exception("Cannot create patron: Email is required");
        }
        
        $stmt = $conn->prepare("
            INSERT INTO patrons (name, email, phone, address, account_status, user_id, created_at) 
            VALUES (?, ?, '', NULL, 'active', ?, NOW())
        ");
        
        $patron_name = trim($user['full_name']);
        if (empty($patron_name)) {
            throw new Exception("Cannot create patron: Name cannot be empty");
        }
        
        $stmt->bind_param("ssi", $patron_name, $user['email'], $user['id']);
        
        if ($stmt->execute()) {
            $patron_id = $conn->insert_id;
            
            $stmt = $conn->prepare("UPDATE users SET patron_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $patron_id, $user['id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to link patron to user account: " . $stmt->error);
            }
        } else {
            throw new Exception("Failed to create patron record: " . $stmt->error);
        }
    }
    
} catch (Exception $e) {
    $patron_setup_error = true;
    $error_details = $e->getMessage();
    error_log("Patron setup error for user ID " . ($user['id'] ?? 'unknown') . ": " . $error_details);
}

if (!$patron_id || $patron_setup_error) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Setup Error - Faculty Portal</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #1a1a1a;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                max-width: 600px;
                background: #2d2d2d;
                padding: 40px;
                border-radius: 20px;
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                color: #e57373;
                margin-bottom: 20px;
            }
            h1 {
                color: #fff;
                margin-bottom: 15px;
                font-size: 28px;
            }
            p {
                color: #b0b0b0;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .error-details {
                background: #1a1a1a;
                padding: 15px;
                border-radius: 10px;
                color: #e57373;
                margin-bottom: 30px;
                font-family: monospace;
                font-size: 14px;
                text-align: left;
            }
            .btn {
                display: inline-block;
                padding: 15px 30px;
                background: #1976d2;
                color: white;
                text-decoration: none;
                border-radius: 10px;
                font-weight: 600;
                transition: all 0.3s;
            }
            .btn:hover {
                background: #1565c0;
                transform: translateY(-2px);
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>Account Setup Error</h1>
            <p>We encountered an issue while setting up your patron account. This is required before you can borrow books.</p>
            <div class="error-details">
                <?php echo htmlspecialchars($error_details); ?>
            </div>
            <p>Please contact the library administrator for assistance.</p>
            <a href="dashboard.php" class="btn">Back to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// MAIN BORROW REQUEST LOGIC
// ============================================

$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

if ($book_id <= 0) {
    header("Location: search_books.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();

if (!$book) {
    header("Location: search_books.php");
    exit;
}

// Check if user can borrow
$can_borrow = [
    'can_borrow' => true,
    'reason' => '',
    'current_count' => 0,
    'max_limit' => 10,
    'has_overdue' => false,
    'unpaid_fines' => 0
];

try {
    $user_role = $user['role'] ?? 'faculty';
    
    if ($user_role == 'faculty') {
        $stmt = $conn->prepare("SELECT max_borrow_limit FROM faculty_permissions WHERE user_id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $can_borrow['max_limit'] = $row['max_borrow_limit'] ?? 10;
        }
    } else {
        // FIXED: Removed COLLATE clause
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $setting_key_books = 'max_books_per_patron';
        $stmt->bind_param("s", $setting_key_books);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $can_borrow['max_limit'] = (int)$row['setting_value'];
        }
    }
    
    // Count borrowed AND pending approval
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ? AND status IN ('borrowed', 'overdue', 'pending')");
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $can_borrow['current_count'] = $row['count'];
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ? AND status = 'overdue'");
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $can_borrow['has_overdue'] = $row['count'] > 0;
    }
    
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount - amount_paid), 0) as unpaid FROM fines WHERE patron_id = ? AND status IN ('unpaid', 'partial')");
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $can_borrow['unpaid_fines'] = $row['unpaid'];
    }
    
    if ($can_borrow['current_count'] >= $can_borrow['max_limit']) {
        $can_borrow['can_borrow'] = false;
        $can_borrow['reason'] = "You have reached your borrowing limit of {$can_borrow['max_limit']} books.";
    } elseif ($can_borrow['has_overdue']) {
        $can_borrow['can_borrow'] = false;
        $can_borrow['reason'] = "You have overdue books. Please return them before borrowing more.";
    } elseif ($can_borrow['unpaid_fines'] > 0) {
        $can_borrow['can_borrow'] = false;
        $can_borrow['reason'] = "You have unpaid fines of ₱" . number_format($can_borrow['unpaid_fines'], 2) . ". Please pay them before borrowing.";
    }
    
} catch (Exception $e) {
    $can_borrow['can_borrow'] = false;
    $can_borrow['reason'] = "Unable to check borrowing eligibility. Please contact the library.";
}

// Handle form submission - CREATE PENDING BORROWING
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($book['available'] <= 0) {
            throw new Exception("This book is no longer available");
        }
        
        if (!$can_borrow['can_borrow']) {
            throw new Exception("You cannot borrow at this time: " . $can_borrow['reason']);
        }
        
        // Calculate dates
        $borrow_date = date('Y-m-d');
        $borrow_days = 30; // Default
        
        // FIXED: Removed COLLATE clause
        $stmt_settings = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $setting_key = 'faculty_max_borrow_days';
        $stmt_settings->bind_param("s", $setting_key);
        if ($stmt_settings && $stmt_settings->execute()) {
            $result_settings = $stmt_settings->get_result();
            if ($row_settings = $result_settings->fetch_assoc()) {
                $borrow_days = (int)$row_settings['setting_value'];
            }
        }
        $due_date = date('Y-m-d', strtotime("+$borrow_days days"));
        
        // Insert borrowing record with PENDING status
        $stmt = $conn->prepare("
            INSERT INTO borrowings (book_id, patron_id, borrow_date, due_date, status, approved_by, created_at) 
            VALUES (?, ?, ?, ?, 'pending', NULL, NOW())
        ");
        $stmt->bind_param("iiss", $book_id, $patron_id, $borrow_date, $due_date);
        
        if ($stmt->execute()) {
            $borrowing_id = $conn->insert_id;
            
            // Log activity
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, created_at) VALUES (?, 'request_borrow', ?, ?, NOW())");
            $log_description = "Requested to borrow book: " . $book['title'];
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $log_stmt->bind_param("iss", $user['id'], $log_description, $ip_address);
            $log_stmt->execute();
            
            $message = "Borrow request submitted successfully! Waiting for admin approval.";
            $message_type = 'success';
            
            // Redirect after success
            header("Location: my_borrowings.php?pending=1");
            exit;
        } else {
            throw new Exception("Failed to create borrow request: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow Book - Faculty Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a1a;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #2d2d2d;
            padding: 40px;
            border-radius: 20px;
        }
        h1 {
            color: #fff;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .subtitle {
            color: #b0b0b0;
            margin-bottom: 30px;
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error {
            background: #4a1f1f;
            color: #e57373;
            border-left: 4px solid #dc3545;
        }
        .alert-warning {
            background: #4a3319;
            color: #ffb74d;
            border-left: 4px solid #ffc107;
        }
        .alert-success {
            background: #1b3a1b;
            color: #81c784;
            border-left: 4px solid #28a745;
        }
        .book-card {
            background: #3d3d3d;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            border: 1px solid #505050;
        }
        .book-title {
            font-size: 22px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 10px;
        }
        .book-author {
            color: #b0b0b0;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .book-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .detail-label {
            font-size: 12px;
            color: #b0b0b0;
            text-transform: uppercase;
            font-weight: 600;
        }
        .detail-value {
            font-size: 15px;
            color: #e0e0e0;
        }
        .info-box {
            background: #1e3a5f;
            border-left: 4px solid #1976d2;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .info-box h3 {
            color: #64b5f6;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .info-box ul {
            margin-left: 20px;
        }
        .info-box li {
            color: #b0d4f1;
            margin-bottom: 5px;
            line-height: 1.6;
        }
        .success-notice {
            background: #1b3a1b;
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .success-notice h3 {
            color: #81c784;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .success-notice p {
            color: #a5d6a7;
            line-height: 1.6;
        }
        .actions {
            display: flex;
            gap: 15px;
        }
        .btn {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #1976d2;
            color: white;
        }
        .btn-primary:hover {
            background: #1565c0;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .btn:disabled {
            background: #505050;
            color: #808080;
            cursor: not-allowed;
            transform: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-book"></i> Borrow Book Request</h1>
        <p class="subtitle">Review the details and submit your borrow request for admin approval</p>
        
        <div class="success-notice">
            <h3><i class="fas fa-check-circle"></i> Patron Account Active</h3>
            <p>Your patron record is set up and ready. Submit your request and wait for admin approval.</p>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'error' ? 'exclamation-circle' : ($message_type == 'warning' ? 'exclamation-triangle' : 'check-circle'); ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!$can_borrow['can_borrow']): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo htmlspecialchars($can_borrow['reason']); ?>
        </div>
        <?php endif; ?>
        
        <div class="book-card">
            <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
            <div class="book-author">by <?php echo htmlspecialchars($book['author']); ?></div>
            
            <div class="book-details">
                <div class="detail-item">
                    <span class="detail-label">Category</span>
                    <span class="detail-value"><?php echo htmlspecialchars($book['category'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Published Year</span>
                    <span class="detail-value"><?php echo htmlspecialchars($book['published_year'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">ISBN</span>
                    <span class="detail-value"><?php echo htmlspecialchars($book['isbn'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Available Copies</span>
                    <span class="detail-value"><?php echo $book['available']; ?> / <?php echo $book['quantity']; ?></span>
                </div>
            </div>
        </div>
        
        <div class="info-box">
            <h3><i class="fas fa-info-circle"></i> Borrowing Information</h3>
            <ul>
                <li><strong>Borrowing Period:</strong> <?php 
                    $borrow_days_display = 30;
                    // FIXED: Removed COLLATE clause
                    $stmt_display = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                    $setting_key_display = 'faculty_max_borrow_days';
                    $stmt_display->bind_param("s", $setting_key_display);
                    if ($stmt_display && $stmt_display->execute()) {
                        $result_display = $stmt_display->get_result();
                        if ($row_display = $result_display->fetch_assoc()) {
                            $borrow_days_display = (int)$row_display['setting_value'];
                        }
                    }
                    echo $borrow_days_display;
                ?> days</li>
                <li><strong>Your Current Borrowed:</strong> <?php echo $can_borrow['current_count']; ?> / <?php echo $can_borrow['max_limit']; ?> books</li>
                <li><strong>Available to Borrow:</strong> <?php echo ($can_borrow['max_limit'] - $can_borrow['current_count']); ?> more book(s)</li>
                <li><strong>Your Patron ID:</strong> <?php echo $patron_id; ?></li>
                <li><strong style="color: #ffc107;">Your request will be sent to the admin for approval</strong></li>
                <li>You will be notified once approved</li>
                <li>Please return the book on or before the due date to avoid penalties</li>
            </ul>
        </div>
        
        <?php if ($book['available'] <= 0): ?>
        <div class="alert alert-error">
            <i class="fas fa-times-circle"></i>
            This book is currently unavailable. All copies are borrowed.
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="actions">
                <a href="search_books.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Search
                </a>
                <button type="submit" 
                        class="btn btn-primary" 
                        <?php echo (!$can_borrow['can_borrow'] || $book['available'] <= 0) ? 'disabled' : ''; ?>>
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</body>
</html>