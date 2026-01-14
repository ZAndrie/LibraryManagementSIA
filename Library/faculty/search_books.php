<?php
require_once '../auth/check_auth.php';
checkRole('faculty');
require_once '../includes/functions.php';

$user = getCurrentUser();
$user = array_merge([
    'id' => 0,
    'username' => 'user',
    'full_name' => 'User',
    'email' => '',
    'role' => 'faculty',
    'created_at' => date('Y-m-d H:i:s')
], $user ?? []);

$permissions = getFacultyPermissions($user['id']);
$permissions = array_merge([
    'can_search_books' => 1,
    'can_borrow_books' => 1,
    'can_make_reservations' => 1,
    'can_view_own_history' => 1,
    'max_borrow_limit' => 10
], $permissions ?? []);

// Search logic
$search_query = '';
$category_filter = '';
$author_filter = '';
$books = null;

if (isset($_GET['search']) || isset($_GET['category']) || isset($_GET['author'])) {
    $search_query = isset($_GET['query']) ? trim($_GET['query']) : '';
    $category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';
    $author_filter = isset($_GET['author']) ? trim($_GET['author']) : '';
    
    $sql = "SELECT * FROM books WHERE 1=1";
    $params = [];
    $types = '';
    
    if (!empty($search_query)) {
        $sql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR tags LIKE ?)";
        $search_param = "%$search_query%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
        $types .= 'ssss';
    }
    
    if (!empty($category_filter)) {
        $sql .= " AND category = ?";
        $params[] = $category_filter;
        $types .= 's';
    }
    
    if (!empty($author_filter)) {
        $sql .= " AND author LIKE ?";
        $params[] = "%$author_filter%";
        $types .= 's';
    }
    
    $sql .= " ORDER BY title ASC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $books = $stmt->get_result();
}

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM books WHERE category IS NOT NULL ORDER BY category";
$categories_result = $conn->query($categories_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Books - Faculty Portal</title>
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
        
        .search-section {
            background: white; padding: 25px; border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;
        }
        .search-form {
            display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 15px;
        }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 14px; color: #666; margin-bottom: 5px; font-weight: 600; }
        .form-group input, .form-group select {
            padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px;
            font-size: 14px; transition: all 0.3s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: #2196f3;
        }
        .btn-search {
            background: #2196f3; color: white; border: none;
            padding: 12px 30px; border-radius: 8px; cursor: pointer;
            font-size: 16px; font-weight: 600; transition: all 0.3s;
            align-self: flex-end;
        }
        .btn-search:hover { background: #1976d2; transform: translateY(-2px); }
        
        .results-section {
            background: white; padding: 25px; border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-header {
            margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;
        }
        .section-header h2 { font-size: 20px; color: #333; }
        
        .books-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;
        }
        .book-card {
            border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px;
            transition: all 0.3s; position: relative;
        }
        .book-card:hover {
            border-color: #2196f3; box-shadow: 0 4px 12px rgba(33, 150, 243, 0.1);
            transform: translateY(-2px);
        }
        .book-title {
            font-size: 18px; font-weight: 600; color: #333;
            margin-bottom: 8px; line-height: 1.3;
        }
        .book-author {
            color: #666; font-size: 14px; margin-bottom: 15px;
        }
        .book-details {
            display: flex; flex-direction: column; gap: 8px; margin-bottom: 15px;
        }
        .book-detail {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: #666;
        }
        .book-detail i { width: 16px; color: #2196f3; }
        .availability {
            padding: 8px 12px; border-radius: 20px; font-size: 12px;
            font-weight: 600; display: inline-flex; align-items: center; gap: 6px;
            margin-bottom: 15px;
        }
        .availability.available { background: #d4edda; color: #155724; }
        .availability.unavailable { background: #f8d7da; color: #721c24; }
        .book-actions {
            display: flex; gap: 10px;
        }
        .btn {
            flex: 1; padding: 10px; border: none; border-radius: 8px;
            font-size: 13px; font-weight: 600; cursor: pointer;
            text-decoration: none; display: flex; align-items: center;
            justify-content: center; gap: 6px; transition: all 0.3s;
        }
        .btn-primary {
            background: #2196f3; color: white;
        }
        .btn-primary:hover { background: #1976d2; }
        .btn-secondary {
            background: #17a2b8; color: white;
        }
        .btn-secondary:hover { background: #138496; }
        
        .empty-state {
            text-align: center; padding: 60px 20px;
        }
        .empty-state i { font-size: 64px; color: #ccc; margin-bottom: 20px; }
        .empty-state h3 { font-size: 24px; color: #666; margin-bottom: 10px; }
        .empty-state p { color: #999; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-book-reader"></i>
            <h2>Faculty Portal</h2>
        </div>
        <div class="user-info">
            <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
            <p><i class="fas fa-chalkboard-teacher"></i> Faculty Member</p>
        </div>
        <ul class="nav-menu">
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="search_books.php" class="active"><i class="fas fa-search"></i> Search Books</a></li>
            <li><a href="my_borrowings.php"><i class="fas fa-book"></i> My Borrowings</a></li>
            <li><a href="reservations.php"><i class="fas fa-bookmark"></i> My Reservations</a></li>
            <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-search"></i> Search Books</h1>
            <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="search-section">
            <form method="GET" action="" class="search-form">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="query" placeholder="Title, Author, ISBN, or Tags" 
                           value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php while ($cat = $categories_result->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Author</label>
                    <input type="text" name="author" placeholder="Author name" 
                           value="<?php echo htmlspecialchars($author_filter); ?>">
                </div>
                <button type="submit" name="search" class="btn-search">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
        
        <div class="results-section">
            <?php if ($books !== null): ?>
                <div class="section-header">
                    <h2>Found <?php echo $books->num_rows; ?> book(s)</h2>
                </div>
                
                <?php if ($books->num_rows > 0): ?>
                    <div class="books-grid">
                        <?php while ($book = $books->fetch_assoc()): ?>
                        <div class="book-card">
                            <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                            <div class="book-author">by <?php echo htmlspecialchars($book['author']); ?></div>
                            
                            <div class="book-details">
                                <div class="book-detail">
                                    <i class="fas fa-tag"></i>
                                    <?php echo htmlspecialchars($book['category'] ?? 'N/A'); ?>
                                </div>
                                <div class="book-detail">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo htmlspecialchars($book['published_year'] ?? 'N/A'); ?>
                                </div>
                                <div class="book-detail">
                                    <i class="fas fa-barcode"></i>
                                    <?php echo htmlspecialchars($book['isbn'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            
                            <div class="availability <?php echo $book['available'] > 0 ? 'available' : 'unavailable'; ?>">
                                <i class="fas fa-<?php echo $book['available'] > 0 ? 'check-circle' : 'times-circle'; ?>"></i>
                                <?php echo $book['available']; ?> / <?php echo $book['quantity']; ?> available
                            </div>
                            
                            <div class="book-actions">
                                <?php if ($book['available'] > 0): ?>
                                    <?php if ($permissions['can_borrow_books']): ?>
                                    <a href="borrow_request.php?book_id=<?php echo $book['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Request to Borrow
                                    </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($permissions['can_make_reservations']): ?>
                                    <a href="reserve_book.php?book_id=<?php echo $book['id']; ?>" class="btn btn-secondary">
                                        <i class="fas fa-bookmark"></i> Reserve
                                    </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No books found</h3>
                        <p>Try adjusting your search criteria</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>Start Your Search</h3>
                    <p>Use the search form above to find books</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>