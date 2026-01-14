<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get filters
$category = isset($_GET['category']) ? $_GET['category'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$tag = isset($_GET['tag']) ? $_GET['tag'] : '';

// Build query
$sql = "SELECT * FROM books WHERE 1=1";
$params = array();
$types = "";

if (!empty($search)) {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($category)) {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

if (!empty($tag) && $tags_exist) {
    $sql .= " AND (tags LIKE ? OR tags = ?)";
    $tagParam = "%$tag%";
    $params[] = $tagParam;
    $params[] = $tag;
    $types .= "ss";
}

if ($status == 'available') {
    $sql .= " AND available > 0";
} elseif ($status == 'unavailable') {
    $sql .= " AND available = 0";
}

$sql .= " ORDER BY title";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $books = $stmt->get_result();
} else {
    $books = $conn->query($sql);
}

// Get categories for filter
$categories = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL ORDER BY category");

// Check if tags column exists
$columns = $conn->query("SHOW COLUMNS FROM books LIKE 'tags'");
$tags_exist = $columns->num_rows > 0;

// Get all unique tags (only if column exists)
$all_tags = array();
if ($tags_exist) {
    $tags_query = $conn->query("SELECT DISTINCT tags FROM books WHERE tags IS NOT NULL AND tags != ''");
    while($row = $tags_query->fetch_assoc()) {
        if (!empty($row['tags'])) {
            $book_tags = explode(',', $row['tags']);
            foreach($book_tags as $t) {
                $t = trim($t);
                if (!empty($t) && !in_array($t, $all_tags)) {
                    $all_tags[] = $t;
                }
            }
        }
    }
    sort($all_tags);
}

// Get statistics
$total_books = $conn->query("SELECT COUNT(*) as total FROM books")->fetch_assoc()['total'];
$total_copies = $conn->query("SELECT SUM(quantity) as total FROM books")->fetch_assoc()['total'];
$available_copies = $conn->query("SELECT SUM(available) as total FROM books")->fetch_assoc()['total'];
$borrowed_copies = $total_copies - $available_copies;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
        }
        .navbar {
            background: white;
            color: #333;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-bottom: 1px solid #e0e0e0;
        }
        .navbar h1 { 
            font-size: 24px;
            color: #333;
            font-weight: 600;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            background: white;
            border: 1px solid #ddd;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
            font-size: 14px;
        }
        .btn:hover { 
            background: #f5f5f5;
            border-color: #ccc;
        }
        .btn-primary {
            background: #000;
            color: white;
            border: none;
        }
        .btn-primary:hover { background: #333; }
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            border: 1px solid #e0e0e0;
        }
        .stat-card h3 {
            color: #666;
            font-size: 11px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #333;
        }
        .barcode-scanner {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
        }
        .barcode-scanner h3 {
            margin-bottom: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            font-weight: 600;
        }
        .barcode-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .barcode-input-group input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            background: white;
            color: #333;
            transition: all 0.3s;
        }
        .barcode-input-group input::placeholder {
            color: #999;
        }
        .barcode-input-group input:focus {
            outline: none;
            border-color: #000;
        }
        .barcode-input-group button {
            padding: 10px 24px;
            background: #000;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }
        .barcode-input-group button:hover {
            background: #333;
        }
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
        }
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 10px 20px;
            border-radius: 4px;
            background: white;
            border: 1px solid #ddd;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }
        .filter-tab.active {
            background: #000;
            color: white;
            border-color: #000;
        }
        .filter-tab:hover {
            background: #f5f5f5;
            border-color: #ccc;
        }
        .filter-tab.active:hover {
            background: #333;
        }
        .search-box {
            flex: 1;
            min-width: 300px;
            position: relative;
        }
        .search-box input {
            width: 100%;
            padding: 10px 45px 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .search-box input:focus {
            outline: none;
            border-color: #000;
        }
        .search-box button {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            background: #000;
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        .search-box button:hover {
            background: #333;
        }
        select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            background: white;
            color: #333;
        }
        .tags-filter {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }
        .tag-filter-item {
            padding: 6px 14px;
            background: white;
            border-radius: 4px;
            font-size: 12px;
            color: #333;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid #ddd;
        }
        .tag-filter-item:hover {
            background: #f5f5f5;
            border-color: #ccc;
        }
        .tag-filter-item.active {
            background: #000;
            color: white;
            border-color: #000;
        }
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .book-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 25px;
            transition: all 0.3s;
            border: 1px solid #e0e0e0;
        }
        .book-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
            border-color: #000;
        }
        .book-header {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
        }
        .book-icon {
            width: 60px;
            height: 60px;
            background: #2c3e50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            flex-shrink: 0;
        }
        .book-info {
            flex: 1;
            min-width: 0;
        }
        .book-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .book-author {
            color: #666;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .book-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .meta-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .meta-value {
            font-size: 14px;
            color: #333;
            font-weight: 500;
        }
        .book-tags {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .book-tag {
            padding: 4px 10px;
            background: #f5f5f5;
            border-radius: 4px;
            font-size: 11px;
            color: #666;
            font-weight: 500;
            border: 1px solid #e0e0e0;
        }
        .availability-section {
            background: #fafafa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }
        .availability-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .availability-label {
            font-size: 11px;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
        }
        .availability-numbers {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        .progress-bar {
            height: 10px;
            background: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #27ae60 0%, #2ecc71 100%);
            transition: width 0.3s;
            border-radius: 5px;
        }
        .progress-fill.low {
            background: linear-gradient(90deg, #f39c12 0%, #f1c40f 100%);
        }
        .progress-fill.none {
            background: linear-gradient(90deg, #e74c3c 0%, #c0392b 100%);
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.available {
            background: #d4edda;
            color: #155724;
        }
        .status-badge.low {
            background: #fff3cd;
            color: #856404;
        }
        .status-badge.unavailable {
            background: #f8d7da;
            color: #721c24;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
        }
        .no-results i {
            font-size: 64px;
            color: #ddd;
            margin-bottom: 20px;
        }
        .no-results h3 {
            color: #666;
            margin-bottom: 10px;
        }
        .no-results p {
            color: #999;
        }
        .barcode-result {
            margin-top: 15px;
            padding: 12px;
            background: #f5f5f5;
            border-radius: 4px;
            font-size: 14px;
            color: #333;
            border: 1px solid #e0e0e0;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-boxes"></i> Book Inventory</h1>
        <div style="display: flex; gap: 15px;">
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Titles</h3>
                <div class="number"><?php echo $total_books; ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Copies</h3>
                <div class="number"><?php echo $total_copies; ?></div>
            </div>
            <div class="stat-card">
                <h3>Available</h3>
                <div class="number" style="color: #27ae60;"><?php echo $available_copies; ?></div>
            </div>
            <div class="stat-card">
                <h3>Borrowed</h3>
                <div class="number" style="color: #e74c3c;"><?php echo $borrowed_copies; ?></div>
            </div>
        </div>

        <!-- Barcode Scanner Section -->
        <div class="barcode-scanner">
            <h3>
                <i class="fas fa-barcode"></i> Barcode Scanner
            </h3>
            <form method="GET" action="" class="barcode-input-group">
                <input type="text" 
                       name="search" 
                       id="barcodeInput" 
                       placeholder="Scan or enter ISBN/Barcode here..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       autofocus>
                <button type="submit">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
            <div class="barcode-result" style="<?php echo empty($search) ? 'display:none;' : ''; ?>">
                <i class="fas fa-info-circle"></i> 
                <?php 
                if (!empty($search) && $books->num_rows > 0) {
                    echo "Found " . $books->num_rows . " book(s) matching: <strong>" . htmlspecialchars($search) . "</strong>";
                } elseif (!empty($search)) {
                    echo "No books found for: <strong>" . htmlspecialchars($search) . "</strong>";
                }
                ?>
            </div>
        </div>

        <div class="filter-bar">
            <div class="filter-tabs">
                <button class="filter-tab <?php echo $status == '' ? 'active' : ''; ?>" 
                        onclick="filterStatus('')">
                    <i class="fas fa-list"></i> All Books
                </button>
                <button class="filter-tab <?php echo $status == 'available' ? 'active' : ''; ?>"
                        onclick="filterStatus('available')">
                    <i class="fas fa-check-circle"></i> Available
                </button>
                <button class="filter-tab <?php echo $status == 'unavailable' ? 'active' : ''; ?>"
                        onclick="filterStatus('unavailable')">
                    <i class="fas fa-times-circle"></i> Unavailable
                </button>
            </div>

            <form method="GET" class="filter-row">
                <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($status); ?>">
                <input type="hidden" name="tag" id="tagInput" value="<?php echo htmlspecialchars($tag); ?>">
                
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by title, author, or ISBN..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
                
                <select name="category">
                    <option value="">All Categories</option>
                    <?php 
                    $categories->data_seek(0);
                    while($cat = $categories->fetch_assoc()): 
                    ?>
                        <option value="<?php echo $cat['category']; ?>" <?php echo $category == $cat['category'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                
                <a href="inventory.php" class="btn" style="white-space: nowrap;">
                    <i class="fas fa-redo"></i> Clear
                </a>
            </form>

            <?php if (count($all_tags) > 0 && $tags_exist): ?>
            <div class="tags-filter">
                <span style="font-size: 12px; color: #666; font-weight: 600; margin-right: 10px;">
                    <i class="fas fa-tags"></i> FILTER BY TAG:
                </span>
                <?php foreach($all_tags as $t): ?>
                    <span class="tag-filter-item <?php echo $tag == $t ? 'active' : ''; ?>" 
                          onclick="filterByTag('<?php echo htmlspecialchars($t); ?>')">
                        <?php echo htmlspecialchars($t); ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($books->num_rows > 0): ?>
            <div class="books-grid">
                <?php 
                $books->data_seek(0);
                while($book = $books->fetch_assoc()): 
                ?>
                    <?php
                    $percentage = ($book['quantity'] > 0) ? ($book['available'] / $book['quantity']) * 100 : 0;
                    $bar_class = '';
                    $status_class = 'available';
                    $status_text = 'Available';
                    
                    if ($percentage == 0) {
                        $bar_class = 'none';
                        $status_class = 'unavailable';
                        $status_text = 'Unavailable';
                    } elseif ($percentage < 50) {
                        $bar_class = 'low';
                        $status_class = 'low';
                        $status_text = 'Low Stock';
                    }
                    
                    $book_tags = ($tags_exist && !empty($book['tags'])) ? explode(',', $book['tags']) : array();
                    ?>
                    <div class="book-card">
                        <div class="book-header">
                            <div class="book-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <div class="book-info">
                                <div class="book-title" title="<?php echo htmlspecialchars($book['title']); ?>">
                                    <?php echo htmlspecialchars($book['title']); ?>
                                </div>
                                <div class="book-author">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($book['author']); ?>
                                </div>
                            </div>
                        </div>

                        <?php if (count($book_tags) > 0): ?>
                        <div class="book-tags">
                            <?php foreach($book_tags as $t): ?>
                                <span class="book-tag">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars(trim($t)); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="book-meta">
                            <div class="meta-item">
                                <span class="meta-label">Category</span>
                                <span class="meta-value">
                                    <i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($book['category']); ?>
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">ISBN</span>
                                <span class="meta-value">
                                    <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($book['isbn']); ?>
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Total Copies</span>
                                <span class="meta-value">
                                    <i class="fas fa-copy"></i> <?php echo $book['quantity']; ?> copies
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Status</span>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                        </div>

                        <div class="availability-section">
                            <div class="availability-header">
                                <span class="availability-label">Availability</span>
                                <span class="availability-numbers">
                                    <?php echo $book['available']; ?> / <?php echo $book['quantity']; ?>
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $bar_class; ?>" 
                                     style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                            <div style="font-size: 11px; color: #666; text-align: center;">
                                <?php echo round($percentage); ?>% Available
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="no-results">
                <i class="fas fa-book-open"></i>
                <h3>No Books Found</h3>
                <p>Try adjusting your search or filters</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterStatus(status) {
            const urlParams = new URLSearchParams(window.location.search);
            if (status) {
                urlParams.set('status', status);
            } else {
                urlParams.delete('status');
            }
            window.location.href = '?' + urlParams.toString();
        }

        function filterByTag(tag) {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('tag') === tag) {
                urlParams.delete('tag');
            } else {
                urlParams.set('tag', tag);
            }
            window.location.href = '?' + urlParams.toString();
        }

        // Auto-focus barcode input and handle quick scanning
        document.getElementById('barcodeInput').focus();
        
        // Clear the input when clicking on it for new scan
        document.getElementById('barcodeInput').addEventListener('click', function() {
            this.select();
        });
    </script>
</body>
</html>