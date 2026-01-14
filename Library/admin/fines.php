<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

$message = '';
$error = '';

// Handle payment
if (isset($_POST['mark_paid'])) {
    $fine_id = intval($_POST['fine_id']);
    $sql = "UPDATE fines SET status = 'paid', paid_date = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $fine_id);
    if ($stmt->execute()) {
        logActivity($conn, $_SESSION['user_id'], 'MARK_FINE_PAID', "Marked fine ID: $fine_id as paid");
        $message = "Fine marked as paid successfully!";
    } else {
        $error = "Error updating fine status";
    }
}

// Handle waive
if (isset($_POST['waive_fine'])) {
    $fine_id = intval($_POST['fine_id']);
    $sql = "UPDATE fines SET status = 'waived' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $fine_id);
    if ($stmt->execute()) {
        logActivity($conn, $_SESSION['user_id'], 'WAIVE_FINE', "Waived fine ID: $fine_id");
        $message = "Fine waived successfully!";
    } else {
        $error = "Error waiving fine";
    }
}

// Get filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query for fines
$sql = "SELECT f.*, p.name as patron_name, p.email, b.title as book_title, 
        br.borrow_date, br.due_date, br.return_date
        FROM fines f
        JOIN patrons p ON f.patron_id = p.id
        JOIN borrowings br ON f.borrowing_id = br.id
        JOIN books b ON br.book_id = b.id
        WHERE 1=1";

if (!empty($status_filter)) {
    $sql .= " AND f.status = '$status_filter'";
}

$sql .= " ORDER BY f.created_at DESC";
$fines = $conn->query($sql);

// Get statistics
$total_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines")->fetch_assoc()['total'];
$unpaid_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE status = 'unpaid'")->fetch_assoc()['total'];
$paid_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE status = 'paid'")->fetch_assoc()['total'];
$waived_fines = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE status = 'waived'")->fetch_assoc()['total'];

// Get system settings
$settings = [];
$settings_query = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $settings_query->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fines & Penalties - Library System</title>
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
            border: none;
            font-size: 14px;
        }
        .btn:hover { background: grey; }
        .btn-success {
            background: black;
            border: none;
        }
        .btn-warning {
            background: black;
            border: none;
        }
        .btn-primary {
            background: black;
            border: none;
        }
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
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
            text-align: center;
        }
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
        }
        .stat-card.total .number { color: #667eea; }
        .stat-card.unpaid .number { color: #e74c3c; }
        .stat-card.paid .number { color: #27ae60; }
        .stat-card.waived .number { color: #95a5a6; }
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .settings-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            background: lightgrey;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .setting-item {
            display: flex;
            flex-direction: column;
        }
        .setting-item .label {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }
        .setting-item .value {
            color: #333;
            font-size: 18px;
            font-weight: 600;
        }
        .filter-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
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
        td { font-size: 13px; }
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-unpaid {
            background: #fee;
            color: #c33;
        }
        .status-paid {
            background: #d4edda;
            color: #155724;
        }
        .status-waived {
            background: #e2e3e5;
            color: #383d41;
        }
        .amount {
            font-weight: 600;
            font-size: 15px;
        }
        .amount.unpaid { color: #e74c3c; }
        .amount.paid { color: #27ae60; }
        .alert {
            padding: 12px 20px;
            border-radius: 6px;
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
        .actions {
            display: flex;
            gap: 8px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-money-bill-wave"></i> Fines & Penalties Management</h1>
        <div style="display: flex; gap: 15px;">
            <a href="settings.php" class="btn"><i class="fas fa-cog"></i> Settings</a>
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="settings-info">
            <div class="setting-item">
                <div class="label">Default Borrow Days</div>
                <div class="value"><?php echo $settings['default_borrow_days'] ?? 14; ?> days</div>
            </div>
            <div class="setting-item">
                <div class="label">Fine Per Day</div>
                <div class="value">₱<?php echo number_format($settings['fine_per_day'] ?? 5, 2); ?></div>
            </div>
            <div class="setting-item">
                <div class="label">Maximum Fine</div>
                <div class="value">₱<?php echo number_format($settings['max_fine_amount'] ?? 500, 2); ?></div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card total">
                <h3>Total Fines</h3>
                <div class="number">₱<?php echo number_format($total_fines, 2); ?></div>
            </div>
            <div class="stat-card unpaid">
                <h3>Unpaid Fines</h3>
                <div class="number">₱<?php echo number_format($unpaid_fines, 2); ?></div>
            </div>
            <div class="stat-card paid">
                <h3>Paid Fines</h3>
                <div class="number">₱<?php echo number_format($paid_fines, 2); ?></div>
            </div>
            <div class="stat-card waived">
                <h3>Waived Fines</h3>
                <div class="number">₱<?php echo number_format($waived_fines, 2); ?></div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Filter Fines</h2>
            <form method="GET" class="filter-section">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="unpaid" <?php echo $status_filter == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                    <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="waived" <?php echo $status_filter == 'waived' ? 'selected' : ''; ?>>Waived</option>
                </select>
                <button type="submit" class="btn btn-primary">Apply Filter</button>
                <a href="fines.php" class="btn">Clear</a>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Fines List</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patron</th>
                        <th>Book</th>
                        <th>Due Date</th>
                        <th>Days Overdue</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($fines->num_rows > 0): ?>
                        <?php while($fine = $fines->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $fine['id']; ?></td>
                                <td>
                                    <div style="display: flex; flex-direction: column;">
                                        <strong><?php echo htmlspecialchars($fine['patron_name']); ?></strong>
                                        <small style="color: #666;"><?php echo htmlspecialchars($fine['email']); ?></small>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($fine['book_title']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($fine['due_date'])); ?></td>
                                <td>
                                    <strong style="color: #e74c3c;"><?php echo $fine['days_overdue']; ?> days</strong>
                                </td>
                                <td>
                                    <span class="amount <?php echo $fine['status']; ?>">
                                        ₱<?php echo number_format($fine['amount'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $fine['status']; ?>">
                                        <?php echo ucfirst($fine['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($fine['created_at'])); ?></td>
                                <td>
                                    <?php if ($fine['status'] == 'unpaid'): ?>
                                        <div class="actions">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="fine_id" value="<?php echo $fine['id']; ?>">
                                                <button type="submit" name="mark_paid" class="btn btn-success" 
                                                        onclick="return confirm('Mark this fine as paid?')">
                                                    <i class="fas fa-check"></i> Paid
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="fine_id" value="<?php echo $fine['id']; ?>">
                                                <button type="submit" name="waive_fine" class="btn btn-warning" 
                                                        onclick="return confirm('Waive this fine?')">
                                                    <i class="fas fa-times"></i> Waive
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;">
                                            <?php echo $fine['status'] == 'paid' ? 'Paid on ' . date('M d, Y', strtotime($fine['paid_date'])) : 'Waived'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #999;">
                                No fines found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>