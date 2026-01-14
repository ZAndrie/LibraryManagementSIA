<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get date range filter
$period = isset($_GET['period']) ? $_GET['period'] : '30'; // Default 30 days
$date_from = date('Y-m-d', strtotime("-{$period} days"));
$date_to = date('Y-m-d');

// Override with custom dates if provided
if (isset($_GET['custom_from']) && isset($_GET['custom_to'])) {
    $date_from = $_GET['custom_from'];
    $date_to = $_GET['custom_to'];
    $period = 'custom';
}

// === GENERAL STATISTICS ===
$total_books = $conn->query("SELECT COUNT(*) as total FROM books")->fetch_assoc()['total'];
$total_patrons = $conn->query("SELECT COUNT(*) as total FROM patrons")->fetch_assoc()['total'];
$total_users = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
$active_borrowings = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status = 'borrowed'")->fetch_assoc()['total'];

// === TOP 10 MOST BORROWED BOOKS ===
$top_books_query = "SELECT b.title, b.author, b.category, COUNT(br.id) as borrow_count 
                    FROM books b
                    JOIN borrowings br ON b.id = br.book_id
                    WHERE DATE(br.borrow_date) BETWEEN ? AND ?
                    GROUP BY b.id
                    ORDER BY borrow_count DESC
                    LIMIT 10";
$stmt = $conn->prepare($top_books_query);
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$top_books = $stmt->get_result();

// === MOST ACTIVE PATRONS ===
$top_patrons_query = "SELECT p.name, p.email, COUNT(br.id) as borrow_count,
                      SUM(CASE WHEN br.status = 'overdue' THEN 1 ELSE 0 END) as overdue_count
                      FROM patrons p
                      JOIN borrowings br ON p.id = br.patron_id
                      WHERE DATE(br.borrow_date) BETWEEN ? AND ?
                      GROUP BY p.id
                      ORDER BY borrow_count DESC
                      LIMIT 10";
$stmt = $conn->prepare($top_patrons_query);
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$top_patrons = $stmt->get_result();

// === CATEGORY POPULARITY ===
$category_stats_query = "SELECT b.category, COUNT(br.id) as borrow_count
                         FROM books b
                         JOIN borrowings br ON b.id = br.book_id
                         WHERE DATE(br.borrow_date) BETWEEN ? AND ?
                         AND b.category IS NOT NULL
                         GROUP BY b.category
                         ORDER BY borrow_count DESC";
$stmt = $conn->prepare($category_stats_query);
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$category_stats = $stmt->get_result();

// === DAILY BORROWING TREND (Last 14 days) ===
$daily_trend_query = "SELECT DATE(borrow_date) as date, COUNT(*) as count
                      FROM borrowings
                      WHERE DATE(borrow_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND CURDATE()
                      GROUP BY DATE(borrow_date)
                      ORDER BY date ASC";
$daily_trend = $conn->query($daily_trend_query);

// === BORROWING STATUS BREAKDOWN ===
$status_borrowed = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status = 'borrowed'")->fetch_assoc()['total'];
$status_returned = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status = 'returned' AND DATE(return_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['total'];
$status_overdue = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status = 'overdue'")->fetch_assoc()['total'];

// === FINE STATISTICS ===
$total_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['total'];
$paid_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE status = 'paid' AND DATE(paid_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['total'];
$unpaid_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE status = 'unpaid'")->fetch_assoc()['total'];

// === COLLECTION STATISTICS ===
$total_copies = $conn->query("SELECT SUM(quantity) as total FROM books")->fetch_assoc()['total'];
$available_copies = $conn->query("SELECT SUM(available) as total FROM books")->fetch_assoc()['total'];
$borrowed_copies = $total_copies - $available_copies;
$utilization_rate = $total_copies > 0 ? round(($borrowed_copies / $total_copies) * 100, 1) : 0;

// === PEAK BORROWING HOURS (if you add time tracking) ===
// This would require time field in borrowings table

// === NEW ADDITIONS THIS PERIOD ===
$new_books = $conn->query("SELECT COUNT(*) as total FROM books WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['total'];
$new_patrons = $conn->query("SELECT COUNT(*) as total FROM patrons WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
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

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
            font-size: 14px;
        }

        .btn:hover { background: grey }

        .btn-primary {
            background: black;
            border: none;
        }

        .container {
            max-width: 1600px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h2 {
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-section {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .filter-section select,
        .filter-section input {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
            display: flex;
            align-items: center;
            gap: 20px;
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
        .stat-icon.red { background: linear-gradient(135deg, black 0%, grey 100%); }
        .stat-icon.purple { background: linear-gradient(135deg, black 0%, grey 100%); }
        .stat-icon.pink { background: linear-gradient(135deg, black 0%, grey 100%); }

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

        .stat-info .change {
            font-size: 12px;
            margin-top: 5px;
        }

        .stat-info .change.positive { color: #27ae60; }
        .stat-info .change.negative { color: #e74c3c; }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .chart-card h3 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .table-card h3 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            font-size: 13px;
        }

        td {
            font-size: 13px;
            color: #666;
        }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            font-weight: bold;
            font-size: 14px;
        }

        .rank-1 { background: #FFD700; color: #333; }
        .rank-2 { background: #C0C0C0; color: #333; }
        .rank-3 { background: #CD7F32; color: white; }
        .rank-other { background: #e8eaf6; color: #667eea; }

        .progress-bar {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: #667eea;
            transition: width 0.3s;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .filter-section {
                flex-direction: column;
                width: 100%;
            }

            .filter-section select,
            .filter-section input,
            .filter-section button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-chart-line"></i> Analytics Dashboard</h1>
        <div style="display: flex; gap: 15px;">
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <!-- Page Header with Filters -->
        <div class="page-header">
            <h2>
                <i class="fas fa-chart-bar"></i>
                Library Analytics
            </h2>
            <form method="GET" class="filter-section">
                <select name="period" id="period" onchange="toggleCustomDates()">
                    <option value="7" <?php echo $period == '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="30" <?php echo $period == '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                    <option value="90" <?php echo $period == '90' ? 'selected' : ''; ?>>Last 90 Days</option>
                    <option value="365" <?php echo $period == '365' ? 'selected' : ''; ?>>Last Year</option>
                    <option value="custom" <?php echo $period == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
                <div id="customDates" style="display: <?php echo $period == 'custom' ? 'flex' : 'none'; ?>; gap: 10px;">
                    <input type="date" name="custom_from" value="<?php echo $date_from; ?>">
                    <input type="date" name="custom_to" value="<?php echo $date_to; ?>">
                </div>
                <button type="submit" class="btn btn-primary">Apply</button>
            </form>
        </div>

        <!-- Key Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <div class="label">Total Books</div>
                    <div class="value"><?php echo number_format($total_books); ?></div>
                    <div class="change positive">
                        <i class="fas fa-arrow-up"></i> <?php echo $new_books; ?> new this period
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <div class="label">Total Patrons</div>
                    <div class="value"><?php echo number_format($total_patrons); ?></div>
                    <div class="change positive">
                        <i class="fas fa-arrow-up"></i> <?php echo $new_patrons; ?> new this period
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="stat-info">
                    <div class="label">Active Borrowings</div>
                    <div class="value"><?php echo number_format($active_borrowings); ?></div>
                    <div class="change">Currently borrowed</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon red">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-info">
                    <div class="label">Overdue Books</div>
                    <div class="value"><?php echo number_format($status_overdue); ?></div>
                    <div class="change negative">Needs attention</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-info">
                    <div class="label">Collection Utilization</div>
                    <div class="value"><?php echo $utilization_rate; ?>%</div>
                    <div class="change"><?php echo $borrowed_copies; ?> / <?php echo $total_copies; ?> copies</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon pink">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-info">
                    <div class="label">Fines Collected</div>
                    <div class="value">₱<?php echo number_format($paid_fines, 2); ?></div>
                    <div class="change negative">₱<?php echo number_format($unpaid_fines, 2); ?> unpaid</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <!-- Daily Borrowing Trend -->
            <div class="chart-card">
                <h3><i class="fas fa-chart-line"></i> Daily Borrowing Trend (Last 14 Days)</h3>
                <canvas id="dailyTrendChart"></canvas>
            </div>

            <!-- Category Popularity -->
            <div class="chart-card">
                <h3><i class="fas fa-chart-pie"></i> Popular Categories</h3>
                <canvas id="categoryChart"></canvas>
            </div>

            <!-- Borrowing Status -->
            <div class="chart-card">
                <h3><i class="fas fa-chart-bar"></i> Borrowing Status Distribution</h3>
                <canvas id="statusChart"></canvas>
            </div>

            <!-- Fine Collection -->
            <div class="chart-card">
                <h3><i class="fas fa-coins"></i> Fine Collection Overview</h3>
                <canvas id="fineChart"></canvas>
            </div>
        </div>

        <!-- Top Books Table -->
        <div class="table-card">
            <h3><i class="fas fa-trophy"></i> Top 10 Most Borrowed Books</h3>
            <?php if ($top_books->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>Times Borrowed</th>
                            <th>Popularity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        $max_borrows = 0;
                        $top_books_data = [];
                        
                        while($book = $top_books->fetch_assoc()) {
                            $top_books_data[] = $book;
                            if ($rank == 1) $max_borrows = $book['borrow_count'];
                        }
                        
                        foreach ($top_books_data as $book):
                            $percentage = $max_borrows > 0 ? ($book['borrow_count'] / $max_borrows) * 100 : 0;
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
                                <td><?php echo htmlspecialchars($book['category']); ?></td>
                                <td><?php echo $book['borrow_count']; ?> times</td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php 
                            $rank++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <p>No borrowing data for this period</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Top Patrons Table -->
        <div class="table-card">
            <h3><i class="fas fa-user-friends"></i> Most Active Patrons</h3>
            <?php if ($top_patrons->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Patron Name</th>
                            <th>Email</th>
                            <th>Books Borrowed</th>
                            <th>Overdue Count</th>
                            <th>Activity Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        $max_patron_borrows = 0;
                        $top_patrons_data = [];
                        
                        while($patron = $top_patrons->fetch_assoc()) {
                            $top_patrons_data[] = $patron;
                            if ($rank == 1) $max_patron_borrows = $patron['borrow_count'];
                        }
                        
                        foreach ($top_patrons_data as $patron):
                            $percentage = $max_patron_borrows > 0 ? ($patron['borrow_count'] / $max_patron_borrows) * 100 : 0;
                            $rank_class = $rank <= 3 ? "rank-{$rank}" : "rank-other";
                        ?>
                            <tr>
                                <td>
                                    <span class="rank-badge <?php echo $rank_class; ?>">
                                        <?php echo $rank; ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($patron['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($patron['email']); ?></td>
                                <td><?php echo $patron['borrow_count']; ?> books</td>
                                <td>
                                    <?php if ($patron['overdue_count'] > 0): ?>
                                        <span style="color: #e74c3c; font-weight: 600;">
                                            <?php echo $patron['overdue_count']; ?> overdue
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #27ae60;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php 
                            $rank++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>No patron activity for this period</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleCustomDates() {
            const period = document.getElementById('period').value;
            const customDates = document.getElementById('customDates');
            customDates.style.display = period === 'custom' ? 'flex' : 'none';
        }

        // Daily Trend Chart
        const dailyCtx = document.getElementById('dailyTrendChart').getContext('2d');
        const dailyTrendChart = new Chart(dailyCtx, {
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
        const categoryChart = new Chart(categoryCtx, {
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
                        '#667eea', '#764ba2', '#f093fb', '#f5576c',
                        '#4facfe', '#00f2fe', '#43e97b', '#38f9d7',
                        '#fa709a', '#fee140', '#30cfd0', '#330867'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });

        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'bar',
            data: {
                labels: ['Currently Borrowed', 'Returned', 'Overdue'],
                datasets: [{
                    label: 'Books',
                    data: [<?php echo $status_borrowed; ?>, <?php echo $status_returned; ?>, <?php echo $status_overdue; ?>],
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
        const fineChart = new Chart(fineCtx, {
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