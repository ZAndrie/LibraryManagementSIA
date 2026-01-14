<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get statistics
$sql_books = "SELECT COUNT(*) as total FROM books";
$sql_available = "SELECT SUM(available) as total FROM books";
$sql_patrons = "SELECT COUNT(*) as total FROM patrons";
$sql_borrowed = "SELECT COUNT(*) as total FROM borrowings WHERE status = 'borrowed'";
$sql_overdue = "SELECT COUNT(*) as total FROM borrowings WHERE status = 'overdue'";

$total_books = $conn->query($sql_books)->fetch_assoc()['total'];
$available_books = $conn->query($sql_available)->fetch_assoc()['total'];
$total_patrons = $conn->query($sql_patrons)->fetch_assoc()['total'];
$borrowed_books = $conn->query($sql_borrowed)->fetch_assoc()['total'];
$overdue_books = $conn->query($sql_overdue)->fetch_assoc()['total'];

// Recent borrowings
$sql_recent = "SELECT b.*, bk.title, p.name as patron_name 
               FROM borrowings b 
               JOIN books bk ON b.book_id = bk.id 
               JOIN patrons p ON b.patron_id = p.id 
               ORDER BY b.borrow_date DESC LIMIT 5";
$recent_borrowings = $conn->query($sql_recent);

// === NEW ARRIVALS (Last 7 days, limit 6) ===
$new_arrivals_query = "SELECT * FROM books 
                       WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                       ORDER BY created_at DESC 
                       LIMIT 6";
$new_arrivals = $conn->query($new_arrivals_query);
$new_arrivals_count = $conn->query("SELECT COUNT(*) as total FROM books WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['total'];

// === ANALYTICS DATA ===
// Collection Statistics
$total_copies = $conn->query("SELECT SUM(quantity) as total FROM books")->fetch_assoc()['total'];
$available_copies = $conn->query("SELECT SUM(available) as total FROM books")->fetch_assoc()['total'];
$borrowed_copies = $total_copies - $available_copies;
$utilization_rate = $total_copies > 0 ? round(($borrowed_copies / $total_copies) * 100, 1) : 0;

// Fine Statistics
$paid_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE status = 'paid'")->fetch_assoc()['total'];
$unpaid_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE status = 'unpaid'")->fetch_assoc()['total'];

// Daily Borrowing Trend (Last 7 days)
$daily_trend_query = "SELECT DATE(borrow_date) as date, COUNT(*) as count
                      FROM borrowings
                      WHERE DATE(borrow_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
                      GROUP BY DATE(borrow_date)
                      ORDER BY date ASC";
$daily_trend = $conn->query($daily_trend_query);

// Category Popularity (Top 5)
$category_stats_query = "SELECT b.category, COUNT(br.id) as borrow_count
                         FROM books b
                         JOIN borrowings br ON b.id = br.book_id
                         WHERE b.category IS NOT NULL
                         GROUP BY b.category
                         ORDER BY borrow_count DESC
                         LIMIT 5";
$category_stats = $conn->query($category_stats_query);

// Top 5 Most Borrowed Books
$top_books_query = "SELECT b.title, b.author, COUNT(br.id) as borrow_count 
                    FROM books b
                    JOIN borrowings br ON b.id = br.book_id
                    GROUP BY b.id
                    ORDER BY borrow_count DESC
                    LIMIT 5";
$top_books = $conn->query($top_books_query);

// Status breakdown
$status_returned = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status = 'returned'")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: black;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .card h2 {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #000000ff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-borrowed {
            background: #fff3cd;
            color: #856404;
        }

        .status-overdue {
            background: #f8d7da;
            color: #721c24;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .chart-card h3 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px 0 20px 0;
        }

        .section-header h2 {
            color: #333;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .view-all-btn {
            padding: 8px 16px;
            background: black;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .view-all-btn:hover {
            background: #333;
            transform: translateY(-2px);
        }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-weight: bold;
            font-size: 13px;
        }

        .rank-1 { background: #FFD700; color: #333; }
        .rank-2 { background: #C0C0C0; color: #333; }
        .rank-3 { background: #CD7F32; color: white; }
        .rank-other { background: #e8eaf6; color: #667eea; }

        /* New Arrivals Styles */
        .new-arrivals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .new-book-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            position: relative;
            cursor: pointer;
        }

        .new-book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }

        .new-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #27ae60;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 600;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .new-book-image {
            height: 160px;
            background: linear-gradient(135deg, black 0%, grey 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 50px;
        }

        .new-book-content {
            padding: 15px;
        }

        .new-book-title {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 40px;
        }

        .new-book-author {
            font-size: 13px;
            color: #666;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .new-book-category {
            display: inline-block;
            padding: 3px 8px;
            background: #e8eaf6;
            color: black;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .new-book-date {
            font-size: 11px;
            color: #999;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .empty-new-arrivals {
            text-align: center;
            padding: 40px;
            color: #999;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .empty-new-arrivals i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
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
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .new-arrivals-grid {
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
                <i class="fas fa-chart-line"></i>
                Dashboard Overview
            </div>
            <div class="top-bar-actions">
                <a href="analytics.php" class="btn-icon" title="View Full Analytics">
                    <i class="fas fa-chart-bar"></i>
                </a>
                <button class="btn-icon" title="Refresh" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        
        <div class="content-wrapper">
            <!-- Key Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Books</h3>
                    <div class="number"><?php echo $total_books; ?></div>
                </div>
                <div class="stat-card success">
                    <h3>Available Books</h3>
                    <div class="number"><?php echo $available_books; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Patrons</h3>
                    <div class="number"><?php echo $total_patrons; ?></div>
                </div>
                <div class="stat-card warning">
                    <h3>Currently Borrowed</h3>
                    <div class="number"><?php echo $borrowed_books; ?></div>
                </div>
                <div class="stat-card danger">
                    <h3>Overdue Books</h3>
                    <div class="number"><?php echo $overdue_books; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Collection Usage</h3>
                    <div class="number"><?php echo $utilization_rate; ?>%</div>
                </div>
            </div>

            <!-- NEW ARRIVALS SECTION -->
            <div class="section-header">
                <h2><i class="fas fa-sparkles"></i> New Arrivals (Last 7 Days)</h2>
                <a href="new_arrivals.php" class="view-all-btn">
                    <span>View All (<?php echo $new_arrivals_count; ?>)</span>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <?php if ($new_arrivals->num_rows > 0): ?>
                <div class="new-arrivals-grid">
                    <?php while ($book = $new_arrivals->fetch_assoc()): 
                        $days_ago = floor((strtotime('now') - strtotime($book['created_at'])) / 86400);
                        $book_json = htmlspecialchars(json_encode($book), ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="new-book-card" onclick='showBookModal(<?php echo $book_json; ?>)'>
                            <div class="new-badge">
                                <i class="fas fa-star"></i> NEW
                            </div>
                            
                            <div class="new-book-image">
                                <i class="fas fa-book"></i>
                            </div>
                            
                            <div class="new-book-content">
                                <?php if (!empty($book['category'])): ?>
                                    <div class="new-book-category">
                                        <?php echo htmlspecialchars($book['category']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="new-book-title">
                                    <?php echo htmlspecialchars($book['title']); ?>
                                </div>
                                
                                <div class="new-book-author">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($book['author']); ?>
                                </div>
                                
                                <div class="new-book-date">
                                    <i class="fas fa-clock"></i>
                                    Added <?php echo $days_ago == 0 ? 'today' : $days_ago . ' day' . ($days_ago > 1 ? 's' : '') . ' ago'; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-new-arrivals">
                    <i class="fas fa-inbox"></i>
                    <p>No new books added in the last 7 days</p>
                </div>
            <?php endif; ?>

            <!-- Analytics Charts Section -->
            <div class="section-header">
                <h2><i class="fas fa-chart-pie"></i> Quick Analytics</h2>
                <a href="analytics.php" class="view-all-btn">
                    <span>View Full Analytics</span>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="charts-grid">
                <!-- Daily Borrowing Trend -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Weekly Borrowing Trend</h3>
                    <canvas id="dailyTrendChart"></canvas>
                </div>

                <!-- Category Popularity -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Top 5 Categories</h3>
                    <canvas id="categoryChart"></canvas>
                </div>

                <!-- Borrowing Status -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Borrowing Status</h3>
                    <canvas id="statusChart"></canvas>
                </div>

                <!-- Fine Collection -->
                <div class="chart-card">
                    <h3><i class="fas fa-coins"></i> Fine Collection</h3>
                    <canvas id="fineChart"></canvas>
                </div>
            </div>

            <!-- Top Books -->
            <div class="card">
                <h2><i class="fas fa-trophy"></i> Top 5 Most Borrowed Books</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Times Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($top_books->num_rows > 0): ?>
                            <?php 
                            $rank = 1;
                            while ($book = $top_books->fetch_assoc()): 
                                $rank_class = $rank <= 3 ? "rank-{$rank}" : "rank-other";
                            ?>
                                <tr>
                                    <td>
                                        <span class="rank-badge <?php echo $rank_class; ?>">
                                            <?php echo $rank; ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo $book['borrow_count']; ?> times</td>
                                </tr>
                            <?php 
                                $rank++;
                            endwhile; 
                            ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #999;">No borrowing data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Borrowings -->
            <div class="card">
                <h2><i class="fas fa-clock"></i> Recent Borrowings</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Patron</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_borrowings->num_rows > 0): ?>
                            <?php while ($row = $recent_borrowings->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['patron_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($row['borrow_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($row['due_date'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $row['status']; ?>">
                                            <?php echo ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #999;">No recent borrowings</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
            console.log('Book data:', book);
            
            try {
                const modal = document.getElementById('bookModal');
                
                // Set basic info
                document.getElementById('modalTitle').textContent = book.title || 'N/A';
                document.getElementById('modalAuthor').textContent = book.author || 'N/A';
                document.getElementById('modalCategory').textContent = book.category || 'N/A';
                document.getElementById('modalISBN').textContent = book.isbn || 'N/A';
                document.getElementById('modalYear').textContent = book.published_year || 'N/A';
                document.getElementById('modalPublisher').textContent = book.publisher || 'N/A';
                document.getElementById('modalLocation').textContent = book.location || 'N/A';
                
                // Set availability
                document.getElementById('modalAvailable').textContent = book.available || 0;
                document.getElementById('modalQuantity').textContent = book.quantity || 0;
                document.getElementById('modalBorrowed').textContent = (book.quantity || 0) - (book.available || 0);
                
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
            } catch (error) {
                console.error('Error showing modal:', error);
                alert('Error displaying book details. Please try again.');
            }
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

        // Daily Trend Chart
        const dailyCtx = document.getElementById('dailyTrendChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: [<?php 
                    $daily_trend->data_seek(0);
                    $labels = [];
                    while($row = $daily_trend->fetch_assoc()) {
                        $labels[] = "'" . date('M d', strtotime($row['date'])) . "'";
                    }
                    echo implode(',', $labels);
                ?>],
                datasets: [{
                    label: 'Books Borrowed',
                    data: [<?php 
                        $daily_trend->data_seek(0);
                        $values = [];
                        while($row = $daily_trend->fetch_assoc()) {
                            $values[] = $row['count'];
                        }
                        echo implode(',', $values);
                    ?>],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });

        // Category Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php 
                    $category_stats->data_seek(0);
                    $cat_labels = [];
                    while($row = $category_stats->fetch_assoc()) {
                        $cat_labels[] = "'" . addslashes($row['category']) . "'";
                    }
                    echo implode(',', $cat_labels);
                ?>],
                datasets: [{
                    data: [<?php 
                        $category_stats->data_seek(0);
                        $cat_values = [];
                        while($row = $category_stats->fetch_assoc()) {
                            $cat_values[] = $row['borrow_count'];
                        }
                        echo implode(',', $cat_values);
                    ?>],
                    backgroundColor: [
                        '#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'bar',
            data: {
                labels: ['Borrowed', 'Returned', 'Overdue'],
                datasets: [{
                    label: 'Books',
                    data: [<?php echo $borrowed_books; ?>, <?php echo $status_returned; ?>, <?php echo $overdue_books; ?>],
                    backgroundColor: ['#f2c94c', '#27ae60', '#e74c3c']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // Fine Chart
        const fineCtx = document.getElementById('fineChart').getContext('2d');
        new Chart(fineCtx, {
            type: 'pie',
            data: {
                labels: ['Paid Fines', 'Unpaid Fines'],
                datasets: [{
                    data: [<?php echo $paid_fines; ?>, <?php echo $unpaid_fines; ?>],
                    backgroundColor: ['#27ae60', '#e74c3c']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '₱' + context.parsed.toLocaleString('en-PH', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                                return label;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>