<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('client');

// Get user info
$user = getCurrentUser();
$is_guest = ($user['role'] == 'guest');

$is_guest = (isset($_SESSION['role']) && $_SESSION['role'] == 'guest');
if ($is_guest) {
    $user = [
        'full_name' => 'Guest User',
        'username' => 'guest',
        'email' => 'guest@library.system'
    ];
}
// Get statistics
$total_books = $conn->query("SELECT COUNT(*) as total FROM books")->fetch_assoc()['total'];
$available_books = $conn->query("SELECT SUM(available) as total FROM books")->fetch_assoc()['total'];

// Get recent books
$recent_books = $conn->query("SELECT * FROM books WHERE available > 0 ORDER BY id DESC LIMIT 6");

// Get all categories for filter
$categories = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL ORDER BY category");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: white;
        }
        .navbar {
            background: linear-gradient(135deg, black 0%, black 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar h1 { font-size: 24px; }
        .navbar .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            background: rgba(255,255,255,0.3);
        }
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .welcome-card {
            background: linear-gradient(135deg,black 0%, grey 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        .welcome-card h2 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        .welcome-card p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        /* Action Buttons Section */
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .action-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 2px solid transparent;
        }
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            border-color: #000;
        }
        .action-card .icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
        }
        .action-card.browse .icon {
            background: linear-gradient(135deg, #000 0%, #333 100%);
        }
        .action-card.chatbot .icon {
            background: linear-gradient(135deg, #333 0%, #666 100%);
        }
        .action-card.search .icon {
            background: linear-gradient(135deg, #555 0%, #888 100%);
        }
        .action-card .content h3 {
            font-size: 18px;
            margin-bottom: 5px;
            color: #000;
        }
        .action-card .content p {
            font-size: 14px;
            color: #666;
            margin: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: black;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card h3 {
            color: white;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: white;
        }
        .search-section {
            background: linear-gradient(135deg,black 5%, grey 100%);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        .search-section h2 {
            margin-bottom: 20px;
            color: white;
            text-align: center;
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid white;
            padding-bottom: 0;
            justify-content: center;
        }
        .filter-tab {
            padding: 12px 24px;
            background: none;
            border: none;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
        }
        .filter-tab:hover {
            color: #ddd;
        }
        .filter-tab.active {
            color: white;
        }
        .filter-tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: white;
        }
        
        /* Filter Content */
        .filter-content {
            display: none;
        }
        .filter-content.active {
            display: block;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
            max-width: 700px;
            margin: 0 auto;
        }
        .search-box input,
        .search-box select {
            flex: 1;
            padding: 15px 20px;
            border: 2px solid white;
            border-radius: 8px;
            font-size: 16px;
        }
        .search-box input:focus,
        .search-box select:focus {
            outline: none;
            border-color: white;
        }
        .btn-search {
            padding: 15px 30px;
            background: #333;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-search:hover {
            background: #555;
        }
        
        /* Advanced Filter Grid */
        .advanced-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            max-width: 900px;
            margin: 0 auto 20px;
        }
        .advanced-filter-grid input,
        .advanced-filter-grid select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }
        .btn-clear {
            padding: 12px 24px;
            background: #555;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-clear:hover {
            background: #777;
        }
        
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .book-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .book-card h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 18px;
        }
        .book-card .author {
            color: #666;
            font-size: 14px;
            margin-bottom: 12px;
        }
        .book-card .category {
            display: inline-block;
            padding: 4px 12px;
            background: #f0f0f0;
            border-radius: 12px;
            font-size: 12px;
            color: #666;
            margin-bottom: 12px;
        }
        .book-card .availability {
            font-size: 14px;
            color: #27ae60;
            font-weight: 600;
        }
        .book-card .availability.unavailable {
            color: #e74c3c;
        }
    </style>
</head>
<body>
<nav class="navbar">
    <h1><i class="fas fa-book"></i> Library System</h1>
    <div class="user-info">
         <span>Welcome, <?php echo htmlspecialchars($user['full_name']); ?></span>
        <a href="../home.php" class="btn">Home</a>
        <?php if ($is_guest): ?>
            <a href="../auth/register.php" class="btn" style="background: #27ae60;">Create Account</a>
        <?php else: ?>
            <a href="../auth/logout.php" class="btn">Logout</a>
        <?php endif; ?>
    </div>
</nav>

    <div class="container">
        <div class="welcome-card">
            <h2>Welcome to the Library! <i class="fas fa-book-open"></i></h2>
            <p>Explore our collection of books and find your next great read.</p>
        </div>
        
        <?php if ($is_guest): ?>
        <div style="background: #fff3cd; border-left: 4px solid #f39c12; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;">
            <strong>👋 You're browsing as a guest!</strong><br>
            <span style="font-size: 14px;">Create an account to unlock more features and personalized recommendations.</span>
            <a href="../auth/register.php" style="display: inline-block; margin-left: 10px; color: #667eea; font-weight: 600;">Register Now →</a>
        </div>
        <?php endif; ?>
        
        <!-- Quick Action Cards -->
        <div class="action-buttons">
            <a href="search_books.php" class="action-card browse">
                <div class="icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="content">
                    <h3>Browse Books</h3>
                    <p>Explore our complete collection</p>
                </div>
            </a>
            
            <a href="chatbot.php" class="action-card chatbot">
                <div class="icon">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="content">
                    <h3>AI Book Search</h3>
                    <p>Ask our AI to find books for you</p>
                </div>
            </a>
            
            <a href="search_books.php" class="action-card browse" onclick="document.getElementById('chatInput').focus(); return false;">
                <div class="icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="content">
                    <h3>Advanced Search</h3>
                    <p>Filter by title, author, category</p>
                </div>
            </a>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Books</h3>
                <div class="number"><?php echo $total_books; ?></div>
            </div>
            <div class="stat-card">
                <h3>Available Books</h3>
                <div class="number"><?php echo $available_books; ?></div>
            </div>
        </div>

        <div class="search-section" id="search-section">
            <h2>Search for Books</h2>
            
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="switchTab('quick')">
                    <i class="fas fa-search"></i> Quick Search
                </button>
                <button class="filter-tab" onclick="switchTab('title')">
                    <i class="fas fa-book"></i> By Title
                </button>
                <button class="filter-tab" onclick="switchTab('author')">
                    <i class="fas fa-user"></i> By Author
                </button>
                <button class="filter-tab" onclick="switchTab('category')">
                    <i class="fas fa-tag"></i> By Category
                </button>
                <button class="filter-tab" onclick="switchTab('advanced')">
                    <i class="fas fa-sliders-h"></i> Advanced
                </button>
            </div>
            
            <!-- Quick Search -->
            <div id="quick-filter" class="filter-content active">
                <form action="search_books.php" method="GET" class="search-box">
                    <input type="text" name="search" id="chatInput" placeholder="Search by title, author, or category..." required>
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Title Search -->
            <div id="title-filter" class="filter-content">
                <form action="search_books.php" method="GET" class="search-box">
                    <input type="hidden" name="filter_type" value="title">
                    <input type="text" name="title" placeholder="Enter book title..." required>
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Author Search -->
            <div id="author-filter" class="filter-content">
                <form action="search_books.php" method="GET" class="search-box">
                    <input type="hidden" name="filter_type" value="author">
                    <input type="text" name="author" placeholder="Enter author name..." required>
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Category Search -->
            <div id="category-filter" class="filter-content">
                <form action="search_books.php" method="GET" class="search-box">
                    <input type="hidden" name="filter_type" value="category">
                    <select name="category" required>
                        <option value="">Select a category...</option>
                        <?php 
                        $categories->data_seek(0);
                        while($cat = $categories->fetch_assoc()): 
                        ?>
                            <option value="<?php echo htmlspecialchars($cat['category']); ?>">
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Advanced Search -->
            <div id="advanced-filter" class="filter-content">
                <form action="search_books.php" method="GET">
                    <input type="hidden" name="filter_type" value="advanced">
                    <div class="advanced-filter-grid">
                        <input type="text" name="title" placeholder="Title">
                        <input type="text" name="author" placeholder="Author">
                        <select name="category">
                            <option value="">All Categories</option>
                            <?php 
                            $categories->data_seek(0);
                            while($cat = $categories->fetch_assoc()): 
                            ?>
                                <option value="<?php echo htmlspecialchars($cat['category']); ?>">
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <input type="text" name="isbn" placeholder="ISBN">
                        <input type="number" name="year_from" placeholder="Year From" min="1000" max="9999">
                        <input type="number" name="year_to" placeholder="Year To" min="1000" max="9999">
                        <select name="availability">
                            <option value="">All Books</option>
                            <option value="available">Available Only</option>
                            <option value="unavailable">Unavailable Only</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="button" class="btn-clear" onclick="clearAdvancedFilters()">
                            <i class="fas fa-times"></i> Clear Filters
                        </button>
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <h2 style="margin-bottom: 20px; color: #333;">Recently Added Books</h2>
        <div class="books-grid">
            <?php while($book = $recent_books->fetch_assoc()): ?>
                <div class="book-card">
                    <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                    <div class="author">by <?php echo htmlspecialchars($book['author']); ?></div>
                    <?php if ($book['category']): ?>
                        <span class="category"><?php echo htmlspecialchars($book['category']); ?></span>
                    <?php endif; ?>
                    <div class="availability <?php echo $book['available'] > 0 ? '' : 'unavailable'; ?>">
                        <?php if ($book['available'] > 0): ?>
                            ✓ <?php echo $book['available']; ?> copies available
                        <?php else: ?>
                            ✗ Currently unavailable
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Hide all filter contents
            document.querySelectorAll('.filter-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected filter content
            document.getElementById(tabName + '-filter').classList.add('active');
            
            // Add active class to clicked tab
            event.target.closest('.filter-tab').classList.add('active');
        }
        
        function clearAdvancedFilters() {
            const form = document.querySelector('#advanced-filter form');
            form.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
                input.value = '';
            });
            form.querySelectorAll('select').forEach(select => {
                select.selectedIndex = 0;
            });
        }
    </script>
</body>
</html>