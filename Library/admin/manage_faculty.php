<?php
require_once '../auth/check_auth.php';
checkRole('admin');
require_once '../includes/functions.php';

$message = '';
$message_type = '';

// Handle APPROVE borrowing request
if (isset($_POST['approve_borrowing'])) {
    $borrowing_id = intval($_POST['borrowing_id']);
    
    $conn->begin_transaction();
    
    try {
        // Get borrowing details
        $stmt = $conn->prepare("SELECT book_id, patron_id FROM borrowings WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $borrowing_id);
        $stmt->execute();
        $borrow = $stmt->get_result()->fetch_assoc();
        
        if (!$borrow) {
            throw new Exception("Borrowing request not found or already processed");
        }
        
        // Check book availability
        $stmt = $conn->prepare("SELECT available FROM books WHERE id = ?");
        $stmt->bind_param("i", $borrow['book_id']);
        $stmt->execute();
        $book = $stmt->get_result()->fetch_assoc();
        
        if ($book['available'] <= 0) {
            throw new Exception("Book is no longer available");
        }
        
        // Update borrowing status to 'borrowed' and set approved_by
        $admin_id = $_SESSION['user_id']; // FIXED: Changed from $_SESSION['user']['id']
        $stmt = $conn->prepare("UPDATE borrowings SET status = 'borrowed', approved_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $admin_id, $borrowing_id);
        $stmt->execute();
        
        // IMPORTANT: The trigger in database will handle book availability decrease
        // So we DON'T manually decrease it here to avoid double-decrease
        
        // Log activity using the safe function
        logActivity($conn, $admin_id, 'APPROVE_BORROWING', "Approved borrowing request ID: " . $borrowing_id);
        
        $conn->commit();
        
        $message = "Borrowing request approved successfully! Faculty member can now collect the book.";
        $message_type = 'success';
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error approving request: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle REJECT borrowing request
if (isset($_POST['reject_borrowing'])) {
    $borrowing_id = intval($_POST['borrowing_id']);
    $reject_reason = isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : 'No reason provided';
    
    try {
        // Update borrowing status to 'rejected' with reason in notes
        $stmt = $conn->prepare("UPDATE borrowings SET status = 'rejected', notes = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("si", $reject_reason, $borrowing_id);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            throw new Exception("Request not found or already processed");
        }
        
        // Log activity using the safe function
        $admin_id = $_SESSION['user_id']; // FIXED: Changed from $_SESSION['user']['id']
        logActivity($conn, $admin_id, 'REJECT_BORROWING', "Rejected borrowing request ID: " . $borrowing_id . " - Reason: " . $reject_reason);
        
        $message = "Borrowing request rejected successfully.";
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = "Error rejecting request: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle RETURN book
if (isset($_POST['return_book'])) {
    $borrowing_id = intval($_POST['borrowing_id']);
    
    $conn->begin_transaction();
    
    try {
        // Get borrowing details
        $stmt = $conn->prepare("SELECT book_id, patron_id, due_date FROM borrowings WHERE id = ? AND status IN ('borrowed', 'overdue')");
        $stmt->bind_param("i", $borrowing_id);
        $stmt->execute();
        $borrow = $stmt->get_result()->fetch_assoc();
        
        if (!$borrow) {
            throw new Exception("Borrowing record not found or already returned");
        }
        
        $return_date = date('Y-m-d');
        
        // Update borrowing status to 'returned'
        $stmt = $conn->prepare("UPDATE borrowings SET status = 'returned', return_date = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $return_date, $borrowing_id);
        $stmt->execute();
        
        // IMPORTANT: The trigger in database will handle book availability increase
        // So we DON'T manually increase it here to avoid double-increase
        
        // Check if book was returned late and create fine if needed
        $fine_amount = 0;
        if ($return_date > $borrow['due_date']) {
            $days_late = (strtotime($return_date) - strtotime($borrow['due_date'])) / 86400;
            $fine_per_day = 5.00; // Default fine per day
            
            // Get fine rate from settings
            $fine_per_day = getSetting($conn, 'fine_per_day', 5.00);
            
            $fine_amount = $days_late * $fine_per_day;
            
            // Insert fine record
            $stmt_fine = $conn->prepare("INSERT INTO fines (patron_id, borrowing_id, amount, reason, status, created_at) VALUES (?, ?, ?, ?, 'unpaid', NOW())");
            $fine_reason = "Late return: " . (int)$days_late . " days overdue";
            $stmt_fine->bind_param("iids", $borrow['patron_id'], $borrowing_id, $fine_amount, $fine_reason);
            $stmt_fine->execute();
        }
        
        // Log activity using the safe function
        $admin_id = $_SESSION['user_id']; // FIXED: Changed from $_SESSION['user']['id']
        logActivity($conn, $admin_id, 'RETURN_BOOK', "Processed book return for borrowing ID: " . $borrowing_id);
        
        $conn->commit();
        
        $message = "Book returned successfully!";
        if ($fine_amount > 0) {
            $message .= " A fine of ₱" . number_format($fine_amount, 2) . " has been applied for late return.";
        }
        $message_type = 'success';
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error processing return: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pending';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// FIXED QUERY - This will show ALL borrowings properly
$sql = "SELECT 
    b.id,
    b.borrow_date,
    b.due_date,
    b.return_date,
    b.status,
    b.notes,
    bk.id as book_id,
    bk.title as book_title,
    bk.author as book_author,
    bk.category,
    bk.isbn,
    bk.available,
    u.id as faculty_id,
    COALESCE(u.full_name, p.name, 'Unknown') as faculty_name,
    COALESCE(u.email, p.email, '') as faculty_email,
    COALESCE(u.role, 'faculty') as user_role,
    p.id as patron_id,
    p.name as patron_name,
    (SELECT COUNT(*) FROM borrowings bor WHERE bor.patron_id = p.id AND bor.status IN ('borrowed', 'overdue')) as current_borrowed,
    (SELECT COUNT(*) FROM borrowings bor WHERE bor.patron_id = p.id AND bor.status = 'pending') as pending_requests,
    DATEDIFF(CURDATE(), b.due_date) as days_overdue
FROM borrowings b
INNER JOIN books bk ON b.book_id = bk.id
INNER JOIN patrons p ON b.patron_id = p.id
LEFT JOIN users u ON p.user_id = u.id
WHERE 1=1";

// Apply status filter
if ($filter != 'all') {
    $sql .= " AND b.status = '" . $conn->real_escape_string($filter) . "'";
}

// Apply search filter
if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE '%" . $conn->real_escape_string($search) . "%' 
              OR u.email LIKE '%" . $conn->real_escape_string($search) . "%'
              OR p.name LIKE '%" . $conn->real_escape_string($search) . "%'
              OR p.email LIKE '%" . $conn->real_escape_string($search) . "%'
              OR bk.title LIKE '%" . $conn->real_escape_string($search) . "%')";
}

// Order by status priority
$sql .= " ORDER BY 
    CASE b.status 
        WHEN 'pending' THEN 1 
        WHEN 'overdue' THEN 2
        WHEN 'borrowed' THEN 3 
        ELSE 4 
    END,
    b.borrow_date DESC";

$borrowings = $conn->query($sql);

// FIXED STATISTICS QUERY
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN b.status = 'borrowed' THEN 1 ELSE 0 END) as borrowed,
    SUM(CASE WHEN b.status = 'overdue' THEN 1 ELSE 0 END) as overdue,
    SUM(CASE WHEN b.status = 'returned' THEN 1 ELSE 0 END) as returned,
    SUM(CASE WHEN b.status = 'rejected' THEN 1 ELSE 0 END) as rejected
FROM borrowings b
INNER JOIN patrons p ON b.patron_id = p.id
LEFT JOIN users u ON p.user_id = u.id
WHERE 1=1";

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [
    'total' => 0,
    'pending' => 0,
    'borrowed' => 0,
    'overdue' => 0,
    'returned' => 0,
    'rejected' => 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Faculty Borrowings - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .top-bar {
            background: #000;
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-bar h1 {
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .top-bar-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-box h2 {
            font-size: 36px;
            margin-bottom: 5px;
            color: #333;
        }
        
        .stat-box p {
            color: #666;
            font-size: 14px;
        }
        
        .stat-box.pending h2 { color: #f57c00; }
        .stat-box.borrowed h2 { color: #2196f3; }
        .stat-box.overdue h2 { color: #dc3545; }
        .stat-box.returned h2 { color: #28a745; }
        
        .controls {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-tabs {
            display: flex;
            gap: 10px;
            flex: 1;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 10px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .filter-tab:hover {
            border-color: #000;
        }
        
        .filter-tab.active {
            background: #000;
            color: white;
            border-color: #000;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
        }
        
        .search-input {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 250px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .btn-primary {
            background: #000;
            color: white;
        }
        
        .btn-primary:hover {
            background: #333;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #000;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
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
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .borrowings-grid {
            display: grid;
            gap: 20px;
        }
        
        .borrowing-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 25px;
            display: grid;
            grid-template-columns: 80px 1fr 320px;
            gap: 20px;
            align-items: start;
            transition: all 0.3s;
        }
        
        .borrowing-card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .borrowing-card.pending {
            border-left: 4px solid #f57c00;
        }
        
        .borrowing-card.borrowed {
            border-left: 4px solid #2196f3;
        }
        
        .borrowing-card.overdue {
            border-left: 4px solid #dc3545;
            background: #fff5f5;
        }
        
        .borrowing-card.returned {
            opacity: 0.7;
            border-left: 4px solid #28a745;
        }
        
        .borrowing-card.rejected {
            opacity: 0.6;
            border-left: 4px solid #6c757d;
        }
        
        .faculty-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
        }
        
        .borrowing-info {
            flex: 1;
        }
        
        .faculty-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .faculty-email {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .faculty-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .faculty-stat {
            font-size: 13px;
        }
        
        .faculty-stat strong {
            color: #f57c00;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-borrowed {
            background: #cce5ff;
            color: #004085;
        }
        
        .status-overdue {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-returned {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .book-info {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .book-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .book-author {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .book-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            font-size: 12px;
        }
        
        .book-detail {
            color: #666;
        }
        
        .borrowing-dates {
            font-size: 13px;
            color: #666;
            margin-top: 10px;
        }
        
        .borrowing-dates div {
            margin-bottom: 5px;
        }
        
        .overdue-warning {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 6px;
            margin-top: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .borrowing-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-width: 300px;
        }
        
        .action-group {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .reject-form {
            margin-top: 10px;
            display: none;
        }
        
        .reject-form.active {
            display: block;
        }
        
        .reject-input {
            width: 100%;
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .notes-box {
            background: #fff3cd;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            color: #856404;
            margin-top: 10px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .empty-state i {
            font-size: 64px;
            color: #ccc;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 24px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #999;
        }

        .confirm-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .confirm-modal.active {
            display: flex;
        }

        .confirm-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 400px;
            text-align: center;
        }

        .confirm-content h3 {
            margin-bottom: 15px;
            color: #333;
        }

        .confirm-content p {
            margin-bottom: 25px;
            color: #666;
        }

        .confirm-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
    </style>
    <script>
        function toggleRejectForm(id) {
            const form = document.getElementById('reject-form-' + id);
            form.classList.toggle('active');
        }

        function confirmReturn(id, bookTitle) {
            const modal = document.getElementById('return-modal-' + id);
            modal.classList.add('active');
        }

        function closeModal(id) {
            const modal = document.getElementById('return-modal-' + id);
            modal.classList.remove('active');
        }
    </script>
</head>
<body>
    <div class="top-bar">
        <h1><i class="fas fa-book-reader"></i> Faculty Book Borrowings</h1>
        <div class="top-bar-actions">
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
            <a href="../auth/logout.php" class="btn btn-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-box pending">
                <h2><?php echo $stats['pending']; ?></h2>
                <p>Pending Approval</p>
            </div>
            <div class="stat-box borrowed">
                <h2><?php echo $stats['borrowed']; ?></h2>
                <p>Currently Borrowed</p>
            </div>
            <div class="stat-box overdue">
                <h2><?php echo $stats['overdue']; ?></h2>
                <p>Overdue Books</p>
            </div>
            <div class="stat-box returned">
                <h2><?php echo $stats['returned']; ?></h2>
                <p>Returned</p>
            </div>
        </div>
        
        <div class="controls">
            <div class="filter-tabs">
                <a href="?filter=pending<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter == 'pending' ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="?filter=borrowed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter == 'borrowed' ? 'active' : ''; ?>">
                    Borrowed
                </a>
                <a href="?filter=overdue<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter == 'overdue' ? 'active' : ''; ?>">
                    Overdue
                </a>
                <a href="?filter=returned<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter == 'returned' ? 'active' : ''; ?>">
                    Returned
                </a>
                <a href="?filter=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">
                    All
                </a>
            </div>
            
            <form method="GET" class="search-box">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="search" class="search-input" 
                       placeholder="Search faculty or book..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
        
        <?php if ($borrowings && $borrowings->num_rows > 0): ?>
            <div class="borrowings-grid">
                <?php while ($bor = $borrowings->fetch_assoc()): 
                    $initials = strtoupper(substr($bor['faculty_name'], 0, 2));
                ?>
                <div class="borrowing-card <?php echo $bor['status']; ?>">
                    <div class="faculty-avatar">
                        <?php echo $initials; ?>
                    </div>
                    
                    <div class="borrowing-info">
                        <div class="faculty-name"><?php echo htmlspecialchars($bor['faculty_name']); ?></div>
                        <div class="faculty-email">
                            <i class="fas fa-envelope"></i>
                            <?php echo htmlspecialchars($bor['faculty_email']); ?>
                        </div>
                        
                        <div class="faculty-stats">
                            <div class="faculty-stat">
                                <i class="fas fa-book"></i>
                                <strong><?php echo $bor['current_borrowed']; ?></strong> borrowed
                            </div>
                            <div class="faculty-stat">
                                <i class="fas fa-clock"></i>
                                <strong><?php echo $bor['pending_requests']; ?></strong> pending
                            </div>
                            <div class="faculty-stat">
                                <i class="fas fa-id-badge"></i>
                                Patron ID: <strong>#<?php echo str_pad($bor['patron_id'], 6, '0', STR_PAD_LEFT); ?></strong>
                            </div>
                        </div>
                        
                        <div class="status-badge status-<?php echo $bor['status']; ?>">
                            <?php
                            $status_icons = [
                                'pending' => 'clock',
                                'borrowed' => 'book-open',
                                'overdue' => 'exclamation-triangle',
                                'returned' => 'check-circle',
                                'rejected' => 'times-circle'
                            ];
                            $status_labels = [
                                'pending' => 'Pending Approval',
                                'borrowed' => 'Currently Borrowed',
                                'overdue' => 'Overdue',
                                'returned' => 'Returned',
                                'rejected' => 'Rejected'
                            ];
                            ?>
                            <i class="fas fa-<?php echo $status_icons[$bor['status']]; ?>"></i>
                            <?php echo $status_labels[$bor['status']]; ?>
                        </div>
                        
                        <div class="book-info">
                            <div class="book-title">
                                <i class="fas fa-book"></i>
                                <?php echo htmlspecialchars($bor['book_title']); ?>
                            </div>
                            <div class="book-author">by <?php echo htmlspecialchars($bor['book_author']); ?></div>
                            <div class="book-details">
                                <div class="book-detail">
                                    <i class="fas fa-tag"></i>
                                    <?php echo htmlspecialchars($bor['category'] ?? 'N/A'); ?>
                                </div>
                                <div class="book-detail">
                                    <i class="fas fa-barcode"></i>
                                    <?php echo htmlspecialchars($bor['isbn'] ?? 'N/A'); ?>
                                </div>
                                <div class="book-detail">
                                    <i class="fas fa-boxes"></i>
                                    Available: <?php echo $bor['available']; ?> copies
                                </div>
                            </div>
                        </div>
                        
                        <div class="borrowing-dates">
                            <div>
                                <i class="fas fa-calendar-plus"></i>
                                Borrowed: <?php echo date('M d, Y', strtotime($bor['borrow_date'])); ?>
                            </div>
                            <div>
                                <i class="fas fa-calendar-times"></i>
                                Due: <?php echo date('M d, Y', strtotime($bor['due_date'])); ?>
                            </div>
                            <?php if ($bor['return_date']): ?>
                            <div>
                                <i class="fas fa-calendar-check"></i>
                                Returned: <?php echo date('M d, Y', strtotime($bor['return_date'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($bor['status'] == 'overdue' && $bor['days_overdue'] > 0): ?>
                        <div class="overdue-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Overdue by <?php echo $bor['days_overdue']; ?> day<?php echo $bor['days_overdue'] > 1 ? 's' : ''; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($bor['notes']): ?>
                        <div class="notes-box">
                            <strong><i class="fas fa-sticky-note"></i> Notes:</strong><br>
                            <?php echo nl2br(htmlspecialchars($bor['notes'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="borrowing-actions">
                        <?php if ($bor['status'] == 'pending'): ?>
                        <div class="action-group">
                            <strong style="display: block; margin-bottom: 10px; color: #333;">
                                <i class="fas fa-tasks"></i> Actions Required
                            </strong>
                            <div class="action-buttons">
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="borrowing_id" value="<?php echo $bor['id']; ?>">
                                    <button type="submit" name="approve_borrowing" class="btn btn-success" style="width: 100%;">
                                        <i class="fas fa-check"></i> Approve Request
                                    </button>
                                </form>
                                
                                <button onclick="toggleRejectForm(<?php echo $bor['id']; ?>)" 
                                        class="btn btn-danger" style="width: 100%;">
                                    <i class="fas fa-times"></i> Reject Request
                                </button>
                                
                                <form method="POST" id="reject-form-<?php echo $bor['id']; ?>" class="reject-form">
                                    <input type="hidden" name="borrowing_id" value="<?php echo $bor['id']; ?>">
                                    <input type="text" name="reject_reason" class="reject-input" 
                                           placeholder="Reason for rejection..." required>
                                    <button type="submit" name="reject_borrowing" class="btn btn-danger" style="width: 100%;">
                                        <i class="fas fa-paper-plane"></i> Submit Rejection
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <?php elseif ($bor['status'] == 'borrowed' || $bor['status'] == 'overdue'): ?>
                        <div class="action-group">
                            <strong style="display: block; margin-bottom: 10px; color: #333;">
                                <i class="fas fa-book-open"></i> Currently Borrowed
                            </strong>
                        
                            
                            <!-- Return Confirmation Modal -->
                            <div id="return-modal-<?php echo $bor['id']; ?>" class="confirm-modal">
                                <div class="confirm-content">
                                    <h3><i class="fas fa-question-circle"></i> Confirm Book Return</h3>
                                    <p>Are you sure the faculty member has returned the book <strong>"<?php echo htmlspecialchars($bor['book_title']); ?>"</strong>?</p>
                                    <?php if ($bor['status'] == 'overdue'): ?>
                                    <p style="color: #dc3545; font-weight: 600;">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        This book is overdue. A late fine will be automatically calculated.
                                    </p>
                                    <?php endif; ?>
                                    <div class="confirm-buttons">
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="borrowing_id" value="<?php echo $bor['id']; ?>">
                                            <button type="submit" name="return_book" class="btn btn-success">
                                                <i class="fas fa-check"></i> Yes, Returned
                                            </button>
                                        </form>
                                        <button onclick="closeModal(<?php echo $bor['id']; ?>)" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php elseif ($bor['status'] == 'returned'): ?>
                        <div class="action-group" style="background: #d4edda; color: #155724;">
                            <strong style="display: block; margin-bottom: 10px;">
                                <i class="fas fa-check-circle"></i> Completed
                            </strong>
                            <p style="font-size: 13px;">
                                Book has been returned successfully.
                            </p>
                        </div>
                        
                        <?php elseif ($bor['status'] == 'rejected'): ?>
                        <div class="action-group" style="background: #f8d7da; color: #721c24;">
                            <strong style="display: block; margin-bottom: 10px;">
                                <i class="fas fa-ban"></i> Rejected
                            </strong>
                            <p style="font-size: 13px;">
                                This borrowing request was rejected.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book-reader"></i>
                <h3>No Borrowing Records Found</h3>
                <p>There are no faculty borrowing records matching your criteria.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>