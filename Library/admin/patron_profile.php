<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

if (!isset($_GET['id'])) {
    header("Location: patron_directory.php");
    exit();
}

$patron_id = intval($_GET['id']);

// Fetch complete patron and user information
$sql = "SELECT 
        p.*,
        u.id as user_id,
        u.username,
        u.full_name as user_name,
        u.email as user_email,
        u.role,
        u.last_login,
        u.last_activity,
        u.created_at as user_created_at,
        up.membership_status,
        COALESCE(ubs.total_borrowed, 0) as total_borrowed,
        COALESCE(ubs.currently_borrowed, 0) as currently_borrowed,
        COALESCE(ubs.total_returned, 0) as total_returned,
        COALESCE(ubs.overdue_count, 0) as overdue_count
        FROM patrons p
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN user_borrowing_stats ubs ON u.id = ubs.user_id
        WHERE p.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patron_id);
$stmt->execute();
$result = $stmt->get_result();
$patron = $result->fetch_assoc();

if (!$patron) {
    header("Location: patron_directory.php");
    exit();
}

// Get borrowing history
$borrowing_sql = "SELECT b.*, bk.title, bk.author, bk.isbn
                  FROM borrowings b
                  JOIN books bk ON b.book_id = bk.id
                  WHERE b.patron_id = ?
                  ORDER BY b.created_at DESC
                  LIMIT 10";
$stmt = $conn->prepare($borrowing_sql);
$stmt->bind_param("i", $patron_id);
$stmt->execute();
$borrowings = $stmt->get_result();

// Get fines
$fines_sql = "SELECT f.*, b.borrow_date, b.due_date, bk.title
              FROM fines f
              JOIN borrowings b ON f.borrowing_id = b.id
              JOIN books bk ON b.book_id = bk.id
              WHERE f.patron_id = ?
              ORDER BY f.created_at DESC
              LIMIT 5";
$stmt = $conn->prepare($fines_sql);
$stmt->bind_param("i", $patron_id);
$stmt->execute();
$fines = $stmt->get_result();

// Calculate total unpaid fines
$total_fines_sql = "SELECT COALESCE(SUM(amount - amount_paid), 0) as total_unpaid
                    FROM fines
                    WHERE patron_id = ? AND status IN ('unpaid', 'partial')";
$stmt = $conn->prepare($total_fines_sql);
$stmt->bind_param("i", $patron_id);
$stmt->execute();
$total_unpaid = $stmt->get_result()->fetch_assoc()['total_unpaid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patron Profile - <?php echo htmlspecialchars($patron['name']); ?></title>
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
        .btn:hover { background: grey; }
        .btn-primary { background: black; border: none; }
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .profile-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: start;
        }
        .profile-main {
            flex: 1;
        }
        .profile-main h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }
        .profile-meta {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        .meta-item .label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
        }
        .meta-item .value {
            font-size: 14px;
            color: #333;
            font-weight: 600;
        }
        .profile-actions {
            display: flex;
            gap: 10px;
        }
        .status-badge {
            padding: 6px 14px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-registered { background: #d4edda; color: #155724; }
        .status-active { background: #cce5ff; color: #004085; }
        .status-suspended { background: #f8d7da; color: #721c24; }
        .role-badge {
            padding: 6px 14px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
        }
        .role-admin { background: #343a40; color: white; }
        .role-client { background: #28a745; color: white; }
        .role-faculty { background: #007bff; color: white; }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .card h3 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .label {
            color: #666;
            font-size: 14px;
        }
        .info-row .value {
            color: #333;
            font-size: 14px;
            font-weight: 600;
        }
        .stat-box {
            text-align: center;
            padding: 20px;
        }
        .stat-box .number {
            font-size: 32px;
            font-weight: bold;
            color: black;
            margin-bottom: 5px;
        }
        .stat-box .label {
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
        }
        .stat-box.warning .number { color: #f39c12; }
        .stat-box.danger .number { color: #e74c3c; }
        .stat-box.success .number { color: #27ae60; }
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
        .status-borrowed { color: #f39c12; font-weight: 600; }
        .status-returned { color: #27ae60; font-weight: 600; }
        .status-overdue { color: #e74c3c; font-weight: 600; }
        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .alert-warning i {
            font-size: 24px;
            color: #856404;
        }
        .alert-info {
            background: #e7f3ff;
            border-left: 4px solid black;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        .not-registered {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            padding: 30px;
            text-align: center;
            border-radius: 12px;
            color: #666;
        }
        .not-registered i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fa fa-user-circle"></i> Patron Profile</h1>
        <div style="display: flex; gap: 15px;">
            <a href="patron_directory.php" class="btn">← Back to Directory</a>
            <a href="dashboard.php" class="btn">Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($total_unpaid > 0): ?>
            <div class="alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Outstanding Fines:</strong> This patron has ₱<?php echo number_format($total_unpaid, 2); ?> in unpaid fines.
                    <a href="fines.php?patron_id=<?php echo $patron_id; ?>" style="margin-left: 10px; color: #856404; text-decoration: underline;">Manage Fines →</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-main">
                <h2><?php echo htmlspecialchars($patron['name']); ?></h2>
                <div style="margin-top: 10px;">
                    <span class="status-badge status-<?php echo htmlspecialchars($patron['account_status']); ?>">
                        <?php echo htmlspecialchars(ucfirst($patron['account_status'])); ?>
                    </span>
                    <?php if ($patron['role']): ?>
                        <span class="role-badge role-<?php echo htmlspecialchars($patron['role']); ?>">
                            <?php echo htmlspecialchars(ucfirst($patron['role'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="profile-meta">
                    <div class="meta-item">
                        <span class="label">Student ID</span>
                        <span class="value"><?php echo htmlspecialchars($patron['student_id'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="label">Email</span>
                        <span class="value"><?php echo htmlspecialchars($patron['email']); ?></span>
                    </div>
                    <?php if ($patron['user_id']): ?>
                        <div class="meta-item">
                            <span class="label">Username</span>
                            <span class="value">@<?php echo htmlspecialchars($patron['username']); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <span class="label">Member Since</span>
                        <span class="value"><?php echo date('M d, Y', strtotime($patron['created_at'])); ?></span>
                    </div>
                </div>
            </div>
            <div class="profile-actions">
                <a href="edit_patron.php?id=<?php echo $patron_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
            </div>
        </div>

        <!-- Borrowing Statistics -->
        <div class="grid-3">
            <div class="card">
                <div class="stat-box">
                    <div class="number"><?php echo $patron['total_borrowed']; ?></div>
                    <div class="label">Total Borrowed</div>
                </div>
            </div>
            <div class="card">
                <div class="stat-box warning">
                    <div class="number"><?php echo $patron['currently_borrowed']; ?></div>
                    <div class="label">Currently Borrowed</div>
                </div>
            </div>
            <div class="card">
                <div class="stat-box danger">
                    <div class="number"><?php echo $patron['overdue_count']; ?></div>
                    <div class="label">Overdue Books</div>
                </div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Patron Information -->
            <div class="card">
                <h3><i class="fas fa-address-card"></i> Patron Information</h3>
                <div class="info-row">
                    <span class="label">Full Name</span>
                    <span class="value"><?php echo htmlspecialchars($patron['name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Student ID</span>
                    <span class="value"><?php echo htmlspecialchars($patron['student_id'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Email</span>
                    <span class="value"><?php echo htmlspecialchars($patron['email']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Phone</span>
                    <span class="value"><?php echo htmlspecialchars($patron['phone'] ?: 'Not provided'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Address</span>
                    <span class="value"><?php echo htmlspecialchars($patron['address'] ?: 'Not provided'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Account Status</span>
                    <span class="value"><?php echo htmlspecialchars(ucfirst($patron['account_status'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Registered</span>
                    <span class="value"><?php echo date('M d, Y', strtotime($patron['created_at'])); ?></span>
                </div>
            </div>

            <!-- User Account Information -->
            <div class="card">
                <h3><i class="fas fa-user-lock"></i> User Account</h3>
                <?php if ($patron['user_id']): ?>
                    <div class="info-row">
                        <span class="label">Username</span>
                        <span class="value">@<?php echo htmlspecialchars($patron['username']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Account Role</span>
                        <span class="value"><?php echo htmlspecialchars(ucfirst($patron['role'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">User Email</span>
                        <span class="value"><?php echo htmlspecialchars($patron['user_email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Last Login</span>
                        <span class="value">
                            <?php echo $patron['last_login'] ? date('M d, Y g:i A', strtotime($patron['last_login'])) : 'Never'; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="label">Last Activity</span>
                        <span class="value">
                            <?php echo $patron['last_activity'] ? date('M d, Y g:i A', strtotime($patron['last_activity'])) : 'No activity'; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="label">Account Created</span>
                        <span class="value"><?php echo date('M d, Y', strtotime($patron['user_created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Membership Status</span>
                        <span class="value"><?php echo htmlspecialchars(ucfirst($patron['membership_status'] ?? 'Active')); ?></span>
                    </div>
                <?php else: ?>
                    <div class="not-registered">
                        <i class="fas fa-user-clock"></i>
                        <h4 style="margin-bottom: 10px; color: #333;">Account Not Registered</h4>
                        <p>This patron has not yet registered their user account.</p>
                        <p style="margin-top: 10px;">
                            <strong>Student ID:</strong> <?php echo htmlspecialchars($patron['student_id']); ?>
                        </p>
                        <p style="font-size: 13px; margin-top: 10px;">
                            Provide this Student ID to the patron so they can register at the login page.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Borrowing History -->
        <div class="card">
            <h3><i class="fas fa-history"></i> Recent Borrowing History</h3>
            <?php if ($borrowings && $borrowings->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>ISBN</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($borrow = $borrowings->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($borrow['title']); ?></td>
                                <td><?php echo htmlspecialchars($borrow['author']); ?></td>
                                <td><?php echo htmlspecialchars($borrow['isbn']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($borrow['borrow_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($borrow['due_date'])); ?></td>
                                <td>
                                    <?php echo $borrow['return_date'] ? date('M d, Y', strtotime($borrow['return_date'])) : '-'; ?>
                                </td>
                                <td>
                                    <span class="status-<?php echo htmlspecialchars($borrow['status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($borrow['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; padding: 40px; color: #999;">No borrowing history yet</p>
            <?php endif; ?>
        </div>

        <!-- Fines & Penalties -->
        <div class="card">
            <h3><i class="fas fa-dollar-sign"></i> Fines & Penalties</h3>
            <?php if ($fines && $fines->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Due Date</th>
                            <th>Days Overdue</th>
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($fine = $fines->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fine['title']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($fine['due_date'])); ?></td>
                                <td><?php echo $fine['days_overdue']; ?> days</td>
                                <td>₱<?php echo number_format($fine['amount'], 2); ?></td>
                                <td>₱<?php echo number_format($fine['amount_paid'], 2); ?></td>
                                <td>
                                    <span class="status-<?php echo htmlspecialchars($fine['status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($fine['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <div style="margin-top: 20px; text-align: right;">
                    <strong>Total Unpaid: ₱<?php echo number_format($total_unpaid, 2); ?></strong>
                </div>
            <?php else: ?>
                <p style="text-align: center; padding: 40px; color: #999;">No fines or penalties</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>