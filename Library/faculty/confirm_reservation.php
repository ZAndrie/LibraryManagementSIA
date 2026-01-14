<?php
require_once '../auth/check_auth.php';
checkRole('faculty');
require_once '../includes/functions.php';

$user = getCurrentUser();
$reservation_id = isset($_GET['reservation_id']) ? intval($_GET['reservation_id']) : 0;

if ($reservation_id <= 0) {
    header("Location: reservations.php");
    exit;
}

// Get reservation details
$stmt = $conn->prepare("
    SELECT br.*, b.title, b.author, b.available, b.id as book_id
    FROM book_reservations br
    JOIN books b ON br.book_id = b.id
    WHERE br.id = ? AND br.user_id = ? AND br.status = 'ready'
");
$stmt->bind_param("ii", $reservation_id, $user['id']);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();

if (!$reservation) {
    $_SESSION['error_message'] = "Reservation not found or not ready.";
    header("Location: reservations.php");
    exit;
}

$message = '';
$message_type = '';

// Handle confirmation - CREATE PENDING BORROWING REQUEST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    
    try {
        // CRITICAL FIX: Get patron using email to ensure consistency
        $stmt = $conn->prepare("SELECT id FROM patrons WHERE email = ?");
        $stmt->bind_param("s", $user['email']);
        $stmt->execute();
        $patron_result = $stmt->get_result()->fetch_assoc();
        
        if (!$patron_result) {
            // Create patron if doesn't exist
            $stmt = $conn->prepare("
                INSERT INTO patrons (name, email, phone, address, account_status, user_id, created_at) 
                VALUES (?, ?, '', NULL, 'active', ?, NOW())
            ");
            $stmt->bind_param("ssi", $user['full_name'], $user['email'], $user['id']);
            $stmt->execute();
            $patron_id = $conn->insert_id;
            
            // Update user record
            $stmt = $conn->prepare("UPDATE users SET patron_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $patron_id, $user['id']);
            $stmt->execute();
        } else {
            $patron_id = $patron_result['id'];
        }
        
        if (!$patron_id) {
            throw new Exception("Failed to get patron record");
        }
        
        // Check book availability
        if ($reservation['available'] <= 0) {
            throw new Exception("Book is no longer available");
        }
        
        // Calculate dates
        $borrow_date = date('Y-m-d');
        $borrow_days = 30;
        $stmt_settings = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'faculty_max_borrow_days'");
        if ($stmt_settings && $stmt_settings->execute()) {
            $result = $stmt_settings->get_result();
            if ($row = $result->fetch_assoc()) {
                $borrow_days = (int)$row['setting_value'];
            }
        }
        $due_date = date('Y-m-d', strtotime("+$borrow_days days"));
        
        // CRITICAL FIX: Create borrowing with explicit NULL for approved_by
        $stmt = $conn->prepare("
            INSERT INTO borrowings (book_id, patron_id, borrow_date, due_date, status, approved_by, created_at, notes, updated_at) 
            VALUES (?, ?, ?, ?, 'pending', NULL, NOW(), 'From reservation confirmation', NOW())
        ");
        $stmt->bind_param("iiss", $reservation['book_id'], $patron_id, $borrow_date, $due_date);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create borrowing record: " . $stmt->error);
        }
        
        $borrowing_id = $conn->insert_id;
        
        // Verify the borrowing was created
        $verify_stmt = $conn->prepare("SELECT id, status FROM borrowings WHERE id = ?");
        $verify_stmt->bind_param("i", $borrowing_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result()->fetch_assoc();
        
        if (!$verify_result || $verify_result['status'] !== 'pending') {
            throw new Exception("Borrowing record verification failed");
        }
        
        // Update reservation status to fulfilled
        $stmt = $conn->prepare("UPDATE book_reservations SET status = 'fulfilled', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();
        
        // Log activity
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, created_at) VALUES (?, 'confirm_reservation', ?, ?, NOW())");
        $log_desc = "Confirmed reservation #" . $reservation_id . " and created pending borrow request #" . $borrowing_id . " for: " . $reservation['title'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt_log->bind_param("iss", $user['id'], $log_desc, $ip);
        $stmt_log->execute();
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Reservation confirmed! Your borrow request (ID: #" . $borrowing_id . ") has been sent to admin for approval. You'll be notified once approved.";
        header("Location: my_borrowings.php?pending=1");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
        
        // Log the error for debugging
        error_log("Reservation confirmation error for user " . $user['id'] . ": " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Reservation - Faculty Portal</title>
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
            max-width: 700px;
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
        .book-card {
            background: #3d3d3d;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            border: 2px solid #28a745;
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
        .info-box {
            background: #1b3a1b;
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .info-box h3 {
            color: #81c784;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .info-box ul {
            margin-left: 20px;
        }
        .info-box li {
            color: #a5d6a7;
            margin-bottom: 5px;
            line-height: 1.6;
        }
        .warning-box {
            background: #4a3319;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .warning-box h3 {
            color: #ffb74d;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .warning-box p {
            color: #ffe082;
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
            background: #28a745;
            color: white;
        }
        .btn-primary:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-check-circle"></i> Confirm Reservation</h1>
        <p class="subtitle">Your reserved book is ready! Confirm to send a borrow request to admin.</p>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="book-card">
            <div class="book-title"><?php echo htmlspecialchars($reservation['title']); ?></div>
            <div class="book-author">by <?php echo htmlspecialchars($reservation['author']); ?></div>
            
            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #1b3a1b; border-radius: 8px; color: #81c784;">
                <i class="fas fa-check-circle" style="font-size: 20px;"></i>
                <div>
                    <div style="font-weight: 600;">Book is Ready!</div>
                    <div style="font-size: 14px;">Available copies: <?php echo $reservation['available']; ?></div>
                </div>
            </div>
        </div>
        
        <div class="warning-box">
            <h3><i class="fas fa-info-circle"></i> Important Notice</h3>
            <p><strong>This will create a borrow request that requires admin approval.</strong> Your request will be placed in the admin's pending queue. Once approved, you can collect the book from the library.</p>
        </div>
        
        <div class="info-box">
            <h3><i class="fas fa-list-check"></i> Next Steps</h3>
            <ul>
                <li><strong>Step 1:</strong> Click "Confirm" to send your borrow request</li>
                <li><strong>Step 2:</strong> Admin will review and approve your request in the "Faculty Book Borrowings" section</li>
                <li><strong>Step 3:</strong> Once approved, visit the library to collect the book</li>
                <li><strong>Step 4:</strong> You'll have 30 days to return the book</li>
                <li>You can check your request status in "My Borrowings"</li>
            </ul>
        </div>
        
        <form method="POST" action="">
            <div class="actions">
                <a href="reservations.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Confirm & Send Request
                </button>
            </div>
        </form>
    </div>
</body>
</html>