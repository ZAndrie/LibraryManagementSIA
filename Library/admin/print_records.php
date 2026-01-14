<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

// Get filter parameters
$record_type = isset($_GET['record_type']) ? $_GET['record_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';

$records = null;
$columns = [];
$title = '';

// Fetch records based on type
if ($record_type) {
    switch ($record_type) {
        case 'books':
            $sql = "SELECT id, title, author, isbn, category, quantity, available, published_year, created_at FROM books WHERE 1=1";
            if ($category_filter) {
                $sql .= " AND category = '" . $conn->real_escape_string($category_filter) . "'";
            }
            $sql .= " ORDER BY title";
            $records = $conn->query($sql);
            $columns = ['ID', 'Title', 'Author', 'ISBN', 'Category', 'Total Qty', 'Available', 'Year', 'Added Date'];
            $title = 'Books Inventory Report';
            break;

        case 'patrons':
            $sql = "SELECT id, name, email, phone, address, created_at FROM patrons WHERE 1=1";
            if ($date_from) {
                $sql .= " AND DATE(created_at) >= '" . $conn->real_escape_string($date_from) . "'";
            }
            if ($date_to) {
                $sql .= " AND DATE(created_at) <= '" . $conn->real_escape_string($date_to) . "'";
            }
            $sql .= " ORDER BY name";
            $records = $conn->query($sql);
            $columns = ['ID', 'Name', 'Email', 'Phone', 'Address', 'Registration Date'];
            $title = 'Patrons List Report';
            break;

        case 'borrowings':
            $sql = "SELECT b.id, bk.title, p.name as patron_name, b.borrow_date, b.due_date, b.return_date, b.status 
                    FROM borrowings b 
                    JOIN books bk ON b.book_id = bk.id 
                    JOIN patrons p ON b.patron_id = p.id 
                    WHERE 1=1";
            if ($status_filter) {
                $sql .= " AND b.status = '" . $conn->real_escape_string($status_filter) . "'";
            }
            if ($date_from) {
                $sql .= " AND DATE(b.borrow_date) >= '" . $conn->real_escape_string($date_from) . "'";
            }
            if ($date_to) {
                $sql .= " AND DATE(b.borrow_date) <= '" . $conn->real_escape_string($date_to) . "'";
            }
            $sql .= " ORDER BY b.borrow_date DESC";
            $records = $conn->query($sql);
            $columns = ['ID', 'Book Title', 'Patron Name', 'Borrow Date', 'Due Date', 'Return Date', 'Status'];
            $title = 'Borrowing Records Report';
            break;

        case 'fines':
            $sql = "SELECT f.id, p.name as patron_name, b.title as book_title, f.amount, f.days_overdue, f.status, f.created_at 
                    FROM fines f 
                    JOIN patrons p ON f.patron_id = p.id 
                    JOIN borrowings br ON f.borrowing_id = br.id 
                    JOIN books b ON br.book_id = b.id 
                    WHERE 1=1";
            if ($status_filter) {
                $sql .= " AND f.status = '" . $conn->real_escape_string($status_filter) . "'";
            }
            if ($date_from) {
                $sql .= " AND DATE(f.created_at) >= '" . $conn->real_escape_string($date_from) . "'";
            }
            if ($date_to) {
                $sql .= " AND DATE(f.created_at) <= '" . $conn->real_escape_string($date_to) . "'";
            }
            $sql .= " ORDER BY f.created_at DESC";
            $records = $conn->query($sql);
            $columns = ['ID', 'Patron Name', 'Book Title', 'Amount (₱)', 'Days Overdue', 'Status', 'Date Created'];
            $title = 'Fines & Penalties Report';
            break;

        case 'users':
            $sql = "SELECT u.id, u.username, u.full_name, u.email, u.role, u.last_login, u.created_at 
                    FROM users u WHERE 1=1";
            if ($role_filter) {
                $sql .= " AND u.role = '" . $conn->real_escape_string($role_filter) . "'";
            }
            $sql .= " ORDER BY u.full_name";
            $records = $conn->query($sql);
            $columns = ['ID', 'Username', 'Full Name', 'Email', 'Role', 'Last Login', 'Created Date'];
            $title = 'User Accounts Report';
            break;

        case 'activity_logs':
            $sql = "SELECT al.id, u.full_name, u.username, al.action, al.description, al.ip_address, al.created_at 
                    FROM activity_logs al 
                    LEFT JOIN users u ON al.user_id = u.id 
                    WHERE 1=1";
            if ($date_from) {
                $sql .= " AND DATE(al.created_at) >= '" . $conn->real_escape_string($date_from) . "'";
            }
            if ($date_to) {
                $sql .= " AND DATE(al.created_at) <= '" . $conn->real_escape_string($date_to) . "'";
            }
            $sql .= " ORDER BY al.created_at DESC LIMIT 500";
            $records = $conn->query($sql);
            $columns = ['ID', 'User', 'Username', 'Action', 'Description', 'IP Address', 'Timestamp'];
            $title = 'Activity Logs Report';
            break;

        case 'inventory':
            $sql = "SELECT id, title, author, category, quantity, available, (quantity - available) as borrowed 
                    FROM books WHERE 1=1";
            if ($category_filter) {
                $sql .= " AND category = '" . $conn->real_escape_string($category_filter) . "'";
            }
            $sql .= " ORDER BY title";
            $records = $conn->query($sql);
            $columns = ['ID', 'Title', 'Author', 'Category', 'Total', 'Available', 'Borrowed'];
            $title = 'Book Inventory Summary';
            break;
    }
}

// Get categories for filter
$categories = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL ORDER BY category");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Records - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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

        .btn-success {
            background: black;
            border: none;
        }

        .btn-print {
            background: black;
            border: none;
        }

        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
            margin-bottom: 20px;
        }

        .info-box {
            background: lightgrey;
            border-left: 4px solid black;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .form-group select,
        .form-group input {
            padding: 10px;
            border: 2px solid black;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .preview-section {
            margin-top: 30px;
        }

        .print-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid black;
        }

        .print-header h1 {
            color: black;
            margin-bottom: 10px;
        }

        .print-header .subtitle {
            color: #666;
            font-size: 16px;
        }

        .print-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .print-info .info-item {
            display: flex;
            flex-direction: column;
        }

        .print-info .label {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .print-info .value {
            color: #333;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }

        th {
            background: black;
            color: white;
            font-weight: 600;
        }

        tbody tr:nth-child(even) {
            background: #f8f9fa;
        }

        tbody tr:hover {
            background: #e8eaf6;
        }

        .record-count {
            margin-top: 20px;
            text-align: right;
            color: #666;
            font-weight: 600;
        }

        .no-records {
            text-align: center;
            padding: 60px;
            color: #999;
        }

        .no-records i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
            }

            .navbar,
            .btn,
            .card:first-child,
            .form-actions {
                display: none !important;
            }

            .container {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            .card {
                box-shadow: none;
                padding: 0;
            }

            .preview-section {
                margin-top: 0;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            thead {
                display: table-header-group;
            }

            .print-header {
                margin-bottom: 20px;
            }
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-borrowed { background: #fff3cd; color: #856404; }
        .status-returned { background: #d4edda; color: #155724; }
        .status-overdue { background: #f8d7da; color: #721c24; }
        .status-paid { background: #d4edda; color: #155724; }
        .status-unpaid { background: #f8d7da; color: #721c24; }
        .status-waived { background: #e2e3e5; color: #383d41; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-print"></i> Print Records</h1>
        <div style="display: flex; gap: 15px;">
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Instructions:</strong>
                <p style="margin-top: 10px;">Select the type of records you want to print, apply filters if needed, then click "Generate Preview". Once the preview appears, click "Print Records" to print the report.</p>
            </div>

            <form method="GET" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="record_type"><i class="fas fa-file-alt"></i> Record Type *</label>
                        <select name="record_type" id="record_type" required onchange="toggleFilters()">
                            <option value="">-- Select Record Type --</option>
                            <option value="books" <?php echo $record_type == 'books' ? 'selected' : ''; ?>>Books List</option>
                            <option value="inventory" <?php echo $record_type == 'inventory' ? 'selected' : ''; ?>>Book Inventory Summary</option>
                            <option value="patrons" <?php echo $record_type == 'patrons' ? 'selected' : ''; ?>>Patrons List</option>
                            <option value="borrowings" <?php echo $record_type == 'borrowings' ? 'selected' : ''; ?>>Borrowing Records</option>
                            <option value="fines" <?php echo $record_type == 'fines' ? 'selected' : ''; ?>>Fines & Penalties</option>
                            <option value="users" <?php echo $record_type == 'users' ? 'selected' : ''; ?>>User Accounts</option>
                            <option value="activity_logs" <?php echo $record_type == 'activity_logs' ? 'selected' : ''; ?>>Activity Logs</option>
                        </select>
                    </div>

                    <div class="form-group" id="date_from_group">
                        <label for="date_from"><i class="fas fa-calendar"></i> Date From</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo $date_from; ?>">
                    </div>

                    <div class="form-group" id="date_to_group">
                        <label for="date_to"><i class="fas fa-calendar"></i> Date To</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo $date_to; ?>">
                    </div>

                    <div class="form-group" id="status_group" style="display: none;">
                        <label for="status"><i class="fas fa-filter"></i> Status Filter</label>
                        <select name="status" id="status">
                            <option value="">All Status</option>
                        </select>
                    </div>

                    <div class="form-group" id="category_group" style="display: none;">
                        <label for="category"><i class="fas fa-tag"></i> Category Filter</label>
                        <select name="category" id="category">
                            <option value="">All Categories</option>
                            <?php while($cat = $categories->fetch_assoc()): ?>
                                <option value="<?php echo $cat['category']; ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group" id="role_group" style="display: none;">
                        <label for="role"><i class="fas fa-user-tag"></i> Role Filter</label>
                        <select name="role" id="role">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="client" <?php echo $role_filter == 'client' ? 'selected' : ''; ?>>Client</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-eye"></i> Generate Preview
                    </button>
                    <?php if ($records): ?>
                        <button type="button" class="btn btn-print" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Records
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($records): ?>
            <div class="card preview-section">
                <div class="print-header">
                    <h1><?php echo $title; ?></h1>
                    <div class="subtitle">Library Management System</div>
                </div>

                <div class="print-info">
                    <div class="info-item">
                        <span class="label">Generated By:</span>
                        <span class="value"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Date Generated:</span>
                        <span class="value"><?php echo date('F d, Y h:i A'); ?></span>
                    </div>
                    <?php if ($date_from || $date_to): ?>
                        <div class="info-item">
                            <span class="label">Date Range:</span>
                            <span class="value">
                                <?php 
                                    if ($date_from && $date_to) {
                                        echo date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to));
                                    } elseif ($date_from) {
                                        echo 'From ' . date('M d, Y', strtotime($date_from));
                                    } else {
                                        echo 'Until ' . date('M d, Y', strtotime($date_to));
                                    }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="label">Total Records:</span>
                        <span class="value"><?php echo $records->num_rows; ?></span>
                    </div>
                </div>

                <?php if ($records->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <th><?php echo $column; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $records->fetch_assoc()): ?>
                                <tr>
                                    <?php foreach ($row as $key => $value): ?>
                                        <td>
                                            <?php 
                                                if ($key == 'status') {
                                                    echo '<span class="status-badge status-' . htmlspecialchars($value) . '">' . 
                                                         htmlspecialchars(ucfirst($value)) . '</span>';
                                                } elseif ($key == 'role') {
                                                    echo '<strong>' . htmlspecialchars(ucfirst($value)) . '</strong>';
                                                } elseif (in_array($key, ['created_at', 'borrow_date', 'due_date', 'return_date', 'last_login'])) {
                                                    echo $value ? date('M d, Y', strtotime($value)) : '-';
                                                } elseif ($key == 'amount') {
                                                    echo '₱' . number_format($value, 2);
                                                } else {
                                                    echo htmlspecialchars($value ?? '-');
                                                }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <div class="record-count">
                        Total Records: <?php echo $records->num_rows; ?>
                    </div>
                <?php else: ?>
                    <div class="no-records">
                        <i class="fas fa-inbox"></i>
                        <h3>No Records Found</h3>
                        <p>No records match the selected criteria</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleFilters() {
            const recordType = document.getElementById('record_type').value;
            const statusGroup = document.getElementById('status_group');
            const categoryGroup = document.getElementById('category_group');
            const roleGroup = document.getElementById('role_group');
            const dateFromGroup = document.getElementById('date_from_group');
            const dateToGroup = document.getElementById('date_to_group');
            const statusSelect = document.getElementById('status');

            // Reset all filters
            statusGroup.style.display = 'none';
            categoryGroup.style.display = 'none';
            roleGroup.style.display = 'none';
            dateFromGroup.style.display = 'flex';
            dateToGroup.style.display = 'flex';
            statusSelect.innerHTML = '<option value="">All Status</option>';

            // Show relevant filters based on record type
            switch(recordType) {
                case 'books':
                case 'inventory':
                    categoryGroup.style.display = 'flex';
                    dateFromGroup.style.display = 'none';
                    dateToGroup.style.display = 'none';
                    break;

                case 'borrowings':
                    statusGroup.style.display = 'flex';
                    statusSelect.innerHTML = `
                        <option value="">All Status</option>
                        <option value="borrowed">Borrowed</option>
                        <option value="returned">Returned</option>
                        <option value="overdue">Overdue</option>
                    `;
                    break;

                case 'fines':
                    statusGroup.style.display = 'flex';
                    statusSelect.innerHTML = `
                        <option value="">All Status</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="paid">Paid</option>
                        <option value="waived">Waived</option>
                    `;
                    break;

                case 'users':
                    roleGroup.style.display = 'flex';
                    dateFromGroup.style.display = 'none';
                    dateToGroup.style.display = 'none';
                    break;

                case 'patrons':
                case 'activity_logs':
                    // Date filters already visible
                    break;
            }
        }

        // Initialize filters on page load
        toggleFilters();
    </script>
</body>
</html>