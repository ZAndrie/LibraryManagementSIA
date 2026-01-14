<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get filter parameters
$days = isset($_GET['days']) ? intval($_GET['days']) : 30;
$category = isset($_GET['category']) ? $_GET['category'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$query = "SELECT * FROM books WHERE 1=1";
$params = array();
$types = "";

// Date filter
$query .= " AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
$params[] = $days;
$types .= "i";

// Category filter
if (!empty($category)) {
    $query .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

// Search filter
if (!empty($search)) {
    $query .= " AND (title LIKE ? OR author LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$query .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$new_arrivals = $stmt->get_result();

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category";
$categories_result = $conn->query($categories_query);

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total_new,
    SUM(quantity) as total_copies,
    COUNT(DISTINCT category) as categories
FROM books 
WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $days);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Arrivals - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php require_once '../includes/sidebar.php'; ?>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
        }

        .stat-icon.blue { background: linear-gradient(135deg, black 0%, grey 100%); }
        .stat-icon.green { background: linear-gradient(135deg, black 0%, grey 100%); }
        .stat-icon.orange { background: linear-gradient(135deg, black 0%, grey 100%); }

        .stat-info {
            flex: 1;
        }

        .stat-info .label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .stat-info .value {
            color: #333;
            font-size: 28px;
            font-weight: bold;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: auto auto auto 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            background: black;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn:hover {
            background: grey;
            transform: translateY(-2px);
        }

        .btn-success {
            background: black;
        }

        .btn-success:hover {
            background: grey;
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .book-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            position: relative;
            cursor: pointer;
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }

        .book-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #ffffff;
            color: black;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .book-image {
            height: 200px;
            background: linear-gradient(135deg, black 0%, grey 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 60px;
        }

        .book-content {
            padding: 20px;
        }

        .book-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .book-author {
            font-size: 14px;
            color: #666;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .book-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 12px;
            font-size: 13px;
            color: #999;
        }

        .book-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .book-category {
            display: inline-block;
            padding: 4px 10px;
            background: #e8eaf6;
            color: black;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .book-date {
            font-size: 12px;
            color: #999;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .availability {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #eee;
        }

        .availability-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .availability-badge.available {
            background: #d4edda;
            color: #155724;
        }

        .availability-badge.limited {
            background: #fff3cd;
            color: #856404;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #666;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            background: linear-gradient(135deg, black 0%, #333 100%);
            color: white;
            padding: 25px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 24px;
            color: white;
            flex: 1;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
        }

        .book-detail-section {
            margin-bottom: 25px;
        }

        .book-detail-section h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f0f0;
        }

        .book-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .book-info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .book-info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
        }

        .book-info-value {
            font-size: 15px;
            color: #333;
            font-weight: 500;
        }

        .book-description {
            line-height: 1.6;
            color: #555;
        }

        .availability-info {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            align-items: center;
        }

        .availability-item {
            flex: 1;
            text-align: center;
        }

        .availability-item .label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }

        .availability-item .value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .book-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .book-tag {
            padding: 5px 12px;
            background: #e8eaf6;
            color: #667eea;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .books-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 10px;
            }

            .book-info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <div class="page-title">
                <i class="fas fa-sparkles"></i>
                New Arrivals
            </div>
            <div class="top-bar-actions">
                <a href="add_book.php" class="btn-icon" title="Add New Book">
                    <i class="fas fa-plus"></i>
                </a>
                <button class="btn-icon" title="Refresh" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        
        <div class="content-wrapper">
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-info">
                        <div class="label">New Books</div>
                        <div class="value"><?php echo number_format($stats['total_new']); ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="stat-info">
                        <div class="label">Total Copies</div>
                        <div class="value"><?php echo number_format($stats['total_copies']); ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="stat-info">
                        <div class="label">Categories</div>
                        <div class="value"><?php echo number_format($stats['categories']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="filters-grid">
                    <div class="filter-group">
                        <label>Time Period</label>
                        <select name="days">
                            <option value="7" <?php echo $days == 7 ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30" <?php echo $days == 30 ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="60" <?php echo $days == 60 ? 'selected' : ''; ?>>Last 60 Days</option>
                            <option value="90" <?php echo $days == 90 ? 'selected' : ''; ?>>Last 90 Days</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Category</label>
                        <select name="category">
                            <option value="">All Categories</option>
                            <?php while ($cat = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                    <?php echo $category == $cat['category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Title or Author..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="new_arrivals.php" class="btn" style="background: #95a5a6;">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>

                    <a href="add_book.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add New Book
                    </a>
                </form>
            </div>

            <!-- Books Grid -->
            <?php if ($new_arrivals->num_rows > 0): ?>
                <div class="books-grid">
                    <?php while ($book = $new_arrivals->fetch_assoc()): 
                        $days_ago = floor((strtotime('now') - strtotime($book['created_at'])) / 86400);
                        $is_new = $days_ago <= 7;
                    ?>
                        <div class="book-card" onclick='showBookModal(<?php echo json_encode($book); ?>)'>
                            <?php if ($is_new): ?>
                                <div class="book-badge">
                                    <i class="fas fa-star"></i> NEW
                                </div>
                            <?php endif; ?>
                            
                            <div class="book-image">
                                <i class="fas fa-book"></i>
                            </div>
                            
                            <div class="book-content">
                                <?php if (!empty($book['category'])): ?>
                                    <div class="book-category">
                                        <?php echo htmlspecialchars($book['category']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="book-title">
                                    <?php echo htmlspecialchars($book['title']); ?>
                                </div>
                                
                                <div class="book-author">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($book['author']); ?>
                                </div>
                                
                                <div class="book-meta">
                                    <?php if (!empty($book['isbn'])): ?>
                                        <span>
                                            <i class="fas fa-barcode"></i>
                                            <?php echo htmlspecialchars($book['isbn']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($book['published_year'])): ?>
                                        <span>
                                            <i class="fas fa-calendar"></i>
                                            <?php echo $book['published_year']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="book-date">
                                    <i class="fas fa-clock"></i>
                                    Added <?php echo $days_ago == 0 ? 'today' : $days_ago . ' day' . ($days_ago > 1 ? 's' : '') . ' ago'; ?>
                                </div>
                                
                                <div class="availability">
                                    <div>
                                        <strong><?php echo $book['available']; ?></strong> / <?php echo $book['quantity']; ?> available
                                    </div>
                                    <span class="availability-badge <?php echo $book['available'] > 0 ? 'available' : 'limited'; ?>">
                                        <?php echo $book['available'] > 0 ? 'Available' : 'Out of Stock'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No New Arrivals</h3>
                    <p>No books have been added in the selected time period.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Book Details Modal -->
    <div id="bookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"></h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Basic Information -->
                <div class="book-detail-section">
                    <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                    <div class="book-info-grid">
                        <div class="book-info-item">
                            <span class="book-info-label">Author</span>
                            <span class="book-info-value" id="modalAuthor"></span>
                        </div>
                        <div class="book-info-item">
                            <span class="book-info-label">Category</span>
                            <span class="book-info-value" id="modalCategory"></span>
                        </div>
                        <div class="book-info-item">
                            <span class="book-info-label">ISBN</span>
                            <span class="book-info-value" id="modalISBN"></span>
                        </div>
                        <div class="book-info-item">
                            <span class="book-info-label">Published Year</span>
                            <span class="book-info-value" id="modalYear"></span>
                        </div>
                        <div class="book-info-item">
                            <span class="book-info-label">Publisher</span>
                            <span class="book-info-value" id="modalPublisher"></span>
                        </div>
                        <div class="book-info-item">
                            <span class="book-info-label">Location</span>
                            <span class="book-info-value" id="modalLocation"></span>
                        </div>
                    </div>
                </div>

                <!-- Availability -->
                <div class="book-detail-section">
                    <h3><i class="fas fa-check-circle"></i> Availability</h3>
                    <div class="availability-info">
                        <div class="availability-item">
                            <div class="label">Available</div>
                            <div class="value" id="modalAvailable"></div>
                        </div>
                        <div class="availability-item">
                            <div class="label">Total Copies</div>
                            <div class="value" id="modalQuantity"></div>
                        </div>
                        <div class="availability-item">
                            <div class="label">Borrowed</div>
                            <div class="value" id="modalBorrowed"></div>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="book-detail-section" id="descriptionSection" style="display: none;">
                    <h3><i class="fas fa-align-left"></i> Description</h3>
                    <p class="book-description" id="modalDescription"></p>
                </div>

                <!-- Tags -->
                <div class="book-detail-section" id="tagsSection" style="display: none;">
                    <h3><i class="fas fa-tags"></i> Tags</h3>
                    <div class="book-tags" id="modalTags"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Modal Functions
        function showBookModal(book) {
            const modal = document.getElementById('bookModal');
            
            // Set basic info
            document.getElementById('modalTitle').textContent = book.title;
            document.getElementById('modalAuthor').textContent = book.author || 'N/A';
            document.getElementById('modalCategory').textContent = book.category || 'N/A';
            document.getElementById('modalISBN').textContent = book.isbn || 'N/A';
            document.getElementById('modalYear').textContent = book.published_year || 'N/A';
            document.getElementById('modalPublisher').textContent = book.publisher || 'N/A';
            document.getElementById('modalLocation').textContent = book.location || 'N/A';
            
            // Set availability
            document.getElementById('modalAvailable').textContent = book.available;
            document.getElementById('modalQuantity').textContent = book.quantity;
            document.getElementById('modalBorrowed').textContent = book.quantity - book.available;
            
            // Set description
            const descSection = document.getElementById('descriptionSection');
            if (book.description && book.description.trim()) {
                document.getElementById('modalDescription').textContent = book.description;
                descSection.style.display = 'block';
            } else {
                descSection.style.display = 'none';
            }
            
            // Set tags
            const tagsSection = document.getElementById('tagsSection');
            if (book.tags && book.tags.trim()) {
                const tagsContainer = document.getElementById('modalTags');
                tagsContainer.innerHTML = '';
                const tags = book.tags.split(',').map(t => t.trim()).filter(t => t);
                tags.forEach(tag => {
                    const tagEl = document.createElement('span');
                    tagEl.className = 'book-tag';
                    tagEl.textContent = tag;
                    tagsContainer.appendChild(tagEl);
                });
                tagsSection.style.display = 'block';
            } else {
                tagsSection.style.display = 'none';
            }
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            const modal = document.getElementById('bookModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('bookModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>