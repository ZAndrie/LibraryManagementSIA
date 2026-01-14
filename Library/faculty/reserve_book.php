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


if (!$permissions['can_make_reservations']) {
    die("You don't have permission to make reservations.");
}

$message = '';
$message_type = '';
$book = null;

// Get book ID
$book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;

if ($book_id <= 0) {
    header("Location: search_books.php");
    exit();
}

// Get book details
$stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();

if (!$book) {
    header("Location: search_books.php");
    exit();
}

// Handle reservation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patron_id = getOrCreatePatronForUser($conn, $user['id']);
    
    if ($patron_id) {
        $result = createReservation($conn, $book_id, $user['id'], $patron_id);
        
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
            header("Location: reservations.php");
            exit();
        } else {
            $message = $result['message'];
            $message_type = 'error';
        }
    } else {
        $message = "Failed to create patron record.";
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve Book - Faculty Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
        }
        h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
            text-align: center;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        .book-card {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
        }
        .book-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        .book-author {
            color: #666;
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
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
        }
        .detail-value {
            font-size: 15px;
            color: #333;
        }
        .info-box {
            background: #fff3e0;
            border-left: 4px solid #f57c00;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .info-box h3 {
            color: #f57c00;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .info-box ul {
            margin-left: 20px;
        }
        .info-box li {
            color: #333;
            margin-bottom: 5px;
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
            background: #17a2b8;
            color: white;
        }
        .btn-primary:hover {
            background: #138496;
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
        <h1><i class="fas fa-bookmark"></i> Reserve Book</h1>
        <p class="subtitle">Reserve this book to be notified when it becomes available</p>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="book-card">
            <div class="book-title"><?php echo htmlspecialchars($book['title'] ?? ''); ?></div>
            <div class="book-author">by <?php echo htmlspecialchars($book['author'] ?? ''); ?></div>
            
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
                    <span class="detail-value" style="color: #dc3545; font-weight: 700;">
                        <?php echo $book['available']; ?> / <?php echo $book['quantity']; ?>
                        <?php if ($book['available'] == 0): ?>
                            (All Borrowed)
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="info-box">
            <h3><i class="fas fa-info-circle"></i> How Reservations Work</h3>
            <ul>
                <li>You'll be notified when this book becomes available</li>
                <li>Reservation expires in <?php echo getSetting($conn, 'reservation_expiry_days', 3); ?> days if not picked up</li>
                <li>You'll get priority when a copy is returned</li>
                <li>Visit the library to borrow the book when notified</li>
                <li>You can cancel your reservation anytime</li>
            </ul>
        </div>
        
        <form method="POST" action="">
            <div class="actions">
                <a href="search_books.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Search
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-bookmark"></i> Confirm Reservation
                </button>
            </div>
        </form>
    </div>
</body>
</html>