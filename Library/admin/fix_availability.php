<?php
/**
 * AVAILABILITY FIX SCRIPT
 * Run this once to fix all availability inconsistencies
 * Place this file in /admin/ directory and run it once
 */

require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

$issues_found = [];
$fixes_applied = [];

// Start transaction
$conn->begin_transaction();

try {
    // 1. Fix books where available > quantity (impossible state)
    $query1 = "SELECT id, title, quantity, available FROM books WHERE available > quantity";
    $result1 = $conn->query($query1);
    
    while ($book = $result1->fetch_assoc()) {
        $issues_found[] = "Book ID {$book['id']} '{$book['title']}': available ({$book['available']}) > quantity ({$book['quantity']})";
        
        // Set available = quantity - currently_borrowed
        $borrowed_query = "SELECT COUNT(*) as borrowed FROM borrowings 
                          WHERE book_id = ? AND status = 'borrowed'";
        $stmt = $conn->prepare($borrowed_query);
        $stmt->bind_param("i", $book['id']);
        $stmt->execute();
        $borrowed = $stmt->get_result()->fetch_assoc()['borrowed'];
        
        $correct_available = max(0, $book['quantity'] - $borrowed);
        
        $update = $conn->prepare("UPDATE books SET available = ? WHERE id = ?");
        $update->bind_param("ii", $correct_available, $book['id']);
        $update->execute();
        
        $fixes_applied[] = "Fixed Book ID {$book['id']}: Set available from {$book['available']} to {$correct_available}";
    }
    
    // 2. Fix books where available count doesn't match actual borrowings
    $query2 = "SELECT b.id, b.title, b.quantity, b.available,
               (SELECT COUNT(*) FROM borrowings WHERE book_id = b.id AND status = 'borrowed') as actually_borrowed
               FROM books b";
    $result2 = $conn->query($query2);
    
    while ($book = $result2->fetch_assoc()) {
        $expected_available = $book['quantity'] - $book['actually_borrowed'];
        
        if ($book['available'] != $expected_available) {
            $issues_found[] = "Book ID {$book['id']} '{$book['title']}': available is {$book['available']}, should be {$expected_available} (quantity: {$book['quantity']}, borrowed: {$book['actually_borrowed']})";
            
            $update = $conn->prepare("UPDATE books SET available = ? WHERE id = ?");
            $update->bind_param("ii", $expected_available, $book['id']);
            $update->execute();
            
            $fixes_applied[] = "Fixed Book ID {$book['id']}: Set available from {$book['available']} to {$expected_available}";
        }
    }
    
    // 3. Check for orphaned borrowings (book doesn't exist)
    $query3 = "SELECT br.id, br.book_id FROM borrowings br 
               LEFT JOIN books b ON br.book_id = b.id 
               WHERE b.id IS NULL AND br.status = 'borrowed'";
    $result3 = $conn->query($query3);
    
    while ($borrowing = $result3->fetch_assoc()) {
        $issues_found[] = "Orphaned borrowing ID {$borrowing['id']} for non-existent book ID {$borrowing['book_id']}";
        
        // Mark as returned
        $update = $conn->prepare("UPDATE borrowings SET status = 'returned', return_date = CURDATE() WHERE id = ?");
        $update->bind_param("i", $borrowing['id']);
        $update->execute();
        
        $fixes_applied[] = "Marked orphaned borrowing ID {$borrowing['id']} as returned";
    }
    
    // Commit all fixes
    $conn->commit();
    
    $success = true;
    
} catch (Exception $e) {
    $conn->rollback();
    $success = false;
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability Fix - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            padding: 30px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        .item {
            padding: 10px 15px;
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            margin-bottom: 10px;
            border-radius: 4px;
            font-size: 14px;
            line-height: 1.6;
        }
        .item.fix {
            border-left-color: #28a745;
            background: #e7f7ed;
        }
        .item.issue {
            border-left-color: #ffc107;
            background: #fff9e6;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-box .number {
            font-size: 36px;
            font-weight: bold;
            color: #333;
        }
        .stat-box .label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: black;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
            transition: all 0.3s;
        }
        .btn:hover {
            background: grey;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <i class="fas fa-tools"></i>
            Book Availability Fix Report
        </h1>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <strong>Fix completed successfully!</strong> All availability counts have been synchronized.
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-box">
                <div class="number"><?php echo count($issues_found); ?></div>
                <div class="label">Issues Found</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo count($fixes_applied); ?></div>
                <div class="label">Fixes Applied</div>
            </div>
        </div>
        
        <?php if (count($issues_found) > 0): ?>
            <div class="section">
                <h2><i class="fas fa-exclamation-triangle"></i> Issues Found</h2>
                <?php foreach ($issues_found as $issue): ?>
                    <div class="item issue">
                        <?php echo htmlspecialchars($issue); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                No issues found! All book availability counts are correct.
            </div>
        <?php endif; ?>
        
        <?php if (count($fixes_applied) > 0): ?>
            <div class="section">
                <h2><i class="fas fa-check"></i> Fixes Applied</h2>
                <?php foreach ($fixes_applied as $fix): ?>
                    <div class="item fix">
                        <?php echo htmlspecialchars($fix); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <a href="books.php" class="btn">
            <i class="fas fa-arrow-left"></i> Back to Books
        </a>
        <a href="borrowing.php" class="btn" style="background: #28a745;">
            <i class="fas fa-book"></i> View Borrowing System
        </a>
    </div>
</body>
</html>