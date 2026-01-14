<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';

// Load settings
$settings = getAllSettings($conn);
$default_days = $settings['default_borrow_days'] ?? 14;
$max_books_per_patron = $settings['max_books_per_patron'] ?? 5;
$allow_renewals = ($settings['allow_renewals'] ?? '1') == '1';

$message = '';
$error = '';

// Get current user's patron record
$current_user_email = $_SESSION['email'] ?? '';
$sql = "SELECT id FROM patrons WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $current_user_email);
$stmt->execute();
$result = $stmt->get_result();
$current_patron = $result->fetch_assoc();

// Handle new borrowing - IMPROVED SYNC
if (isset($_POST['borrow'])) {
    $book_id = intval($_POST['book_id']);
    $patron_id = null;
    
    // SECURITY: If user is NOT admin, remove any patron_id they might have submitted
    if ($_SESSION['role'] != 'admin') {
        $_POST['patron_id'] = ''; // Force empty for non-admins
    }
   // ONLY ADMINS can borrow for others - clients ALWAYS borrow for themselves
   if ($_SESSION['role'] == 'admin' && isset($_POST['patron_id']) && !empty($_POST['patron_id'])) {
    // Admin is borrowing for someone else
    $submitted_value = $_POST['patron_id'];
    
    // Check if it has a prefix (P: for patron_id, U: for user_id)
    $is_patron_id = false;
    $is_user_id = false;
    $selected_id = 0;
    
    if (strpos($submitted_value, 'P:') === 0) {
        // Patron ID
        $is_patron_id = true;
        $selected_id = intval(substr($submitted_value, 2));
    } elseif (strpos($submitted_value, 'U:') === 0) {
        // User ID
        $is_user_id = true;
        $selected_id = intval(substr($submitted_value, 2));
    } else {
        // Legacy: no prefix, assume it's a patron_id
        $selected_id = intval($submitted_value);
        $is_patron_id = true;
    }
    
    // FIXED LOGIC: Try patron_id FIRST, then user_id
    // This is because the dropdown passes patron_id when available
    
  // Use the prefix to determine lookup strategy
  if ($is_user_id) {
    // Explicitly marked as user_id - look up user and create/find patron
    $user_sql = "SELECT id, full_name, email FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $selected_id);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    
    if ($user_data) {
        // Found user, now check/create patron record
        $check_patron = $conn->prepare("SELECT id FROM patrons WHERE email = ?");
        $check_patron->bind_param("s", $user_data['email']);
        $check_patron->execute();
        $existing_patron = $check_patron->get_result()->fetch_assoc();
        
        if ($existing_patron) {
            $patron_id = $existing_patron['id'];
        } else {
            // Create patron record for this user
            $insert = $conn->prepare("INSERT INTO patrons (name, email, phone, address) VALUES (?, ?, '', '')");
            $insert->bind_param("ss", $user_data['full_name'], $user_data['email']);
            if ($insert->execute()) {
                $patron_id = $insert->insert_id;
            }
        }
    }
} else {
    // Patron ID (or legacy no-prefix) - look up patron directly
    $check_patron = $conn->prepare("SELECT id, email FROM patrons WHERE id = ?");
    $check_patron->bind_param("i", $selected_id);
    $check_patron->execute();
    $patron_exists = $check_patron->get_result()->fetch_assoc();
    
    if ($patron_exists) {
        $patron_id = $patron_exists['id'];
    }
}
    } else {
        // NON-ADMIN or empty patron_id: ALWAYS borrow for current logged-in user
        // This prevents clients from borrowing books under other people's names
        $check_patron = $conn->prepare("SELECT id FROM patrons WHERE email = ?");
        $check_patron->bind_param("s", $current_user_email);
        $check_patron->execute();
        $existing_patron = $check_patron->get_result()->fetch_assoc();
        
        if ($existing_patron) {
            $patron_id = $existing_patron['id'];
        } else {
            // Create patron record for current user
            $insert = $conn->prepare("INSERT INTO patrons (name, email, phone, address) VALUES (?, ?, '', '')");
            $insert->bind_param("ss", $_SESSION['full_name'], $current_user_email);
            if ($insert->execute()) {
                $patron_id = $insert->insert_id;
            }
        }
    }
    
    if ($patron_id) {
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime("+{$default_days} days"));
        $limit_check = canPatronBorrow($conn, $patron_id);
        
        if (!$limit_check['can_borrow']) {
            $error = $limit_check['message'];
        } else {
            $borrow_date = date('Y-m-d');
            $book_check = $conn->prepare("SELECT title, available FROM books WHERE id = ?");
            $book_check->bind_param("i", $book_id);
            $book_check->execute();
            $book = $book_check->get_result()->fetch_assoc();

            if ($book && $book['available'] > 0) {
                $patron_info = $conn->prepare("SELECT name FROM patrons WHERE id = ?");
                $patron_info->bind_param("i", $patron_id);
                $patron_info->execute();
                $patron = $patron_info->get_result()->fetch_assoc();

                $insert_borrow = $conn->prepare("INSERT INTO borrowings (book_id, patron_id, borrow_date, due_date, status) VALUES (?, ?, ?, ?, 'borrowed')");
                $insert_borrow->bind_param("iiss", $book_id, $patron_id, $borrow_date, $due_date);

                if ($insert_borrow->execute()) {
                    // Availability is managed by DB triggers on borrowings; do not update here to avoid double-adjusting
                    $days = floor((strtotime($due_date) - strtotime($borrow_date)) / 86400);
                    logActivity($conn, $_SESSION['user_id'], 'BORROW_BOOK', "Issued '{$book['title']}' to {$patron['name']} for {$days} days");
                    $message = "Book borrowed successfully! Due on " . date('M d, Y', strtotime($due_date));
                } else {
                    $error = "Error borrowing book";
                }
            } else {
                $error = "Book is not available";
            }
        }
    } else {
        $error = "Unable to create or find patron record.";
    }
}

// Handle return
if (isset($_POST['return']) && $_SESSION['role'] == 'admin') {
    $borrow_id = intval($_POST['borrow_id']);
    $return_date = date('Y-m-d');

    $info_sql = $conn->prepare("SELECT b.title, p.name, br.book_id, br.due_date FROM borrowings br 
                JOIN books b ON br.book_id = b.id 
                JOIN patrons p ON br.patron_id = p.id 
                WHERE br.id = ?");
    $info_sql->bind_param("i", $borrow_id);
    $info_sql->execute();
    $info = $info_sql->get_result()->fetch_assoc();

    if ($info) {
        $update_borrow = $conn->prepare("UPDATE borrowings SET return_date = ?, status = 'returned' WHERE id = ?");
        $update_borrow->bind_param("si", $return_date, $borrow_id);
        $update_borrow->execute();

        // Availability is managed by DB triggers on borrowings; do not update here to avoid double-adjusting

        if (($settings['auto_calculate_fines'] ?? '1') == '1') {
            $fine_amount = calculateFine($conn, $borrow_id);
            if ($fine_amount > 0) {
                $grace = $settings['grace_period_days'] ?? 0;
                $days_overdue = max(0, floor((strtotime($return_date) - strtotime($info['due_date'])) / 86400) - $grace);
                $message = "Book returned! " . ($days_overdue > 0 ? "Fine: ₱" . number_format($fine_amount, 2) . " ({$days_overdue} days overdue)" : "");
            } else {
                $message = "Book returned successfully!";
            }
        } else {
            $message = "Book returned successfully!";
        }

        logActivity($conn, $_SESSION['user_id'], 'RETURN_BOOK', "Returned '{$info['title']}' from {$info['name']}");
    }
}

checkAndUpdateOverdueBooks($conn);

// Get parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$user_filter = $_GET['user_filter'] ?? 'all';

// Get books
$books = $conn->query("SELECT * FROM books WHERE available > 0 ORDER BY title");

// Get all patrons/users for admin
$patrons = null;
$patron_counts = ['all' => 0, 'admin' => 0, 'client' => 0, 'patron' => 0];

if ($_SESSION['role'] == 'admin') {
    $patrons = $conn->query("
        SELECT u.id as user_id, u.full_name as name, u.email, u.role,
               p.id as patron_id, IF(p.id IS NOT NULL, 'yes', 'no') as has_patron
        FROM users u
        LEFT JOIN patrons p ON u.email = p.email
        UNION
        SELECT NULL, p.name, p.email, '', p.id, 'yes'
        FROM patrons p
        WHERE p.email NOT IN (SELECT email FROM users WHERE email IS NOT NULL)
        ORDER BY name
    ");
    
    $counts = $conn->query("
        SELECT 
            (SELECT COUNT(*) FROM users) + (SELECT COUNT(*) FROM patrons WHERE email NOT IN (SELECT email FROM users WHERE email IS NOT NULL)) as total,
            (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_count,
            (SELECT COUNT(*) FROM users WHERE role = 'client') as client_count,
            (SELECT COUNT(*) FROM patrons WHERE email NOT IN (SELECT email FROM users WHERE email IS NOT NULL)) as patron_count
    ")->fetch_assoc();
    
    $patron_counts = [
        'all' => $counts['total'],
        'admin' => $counts['admin_count'],
        'client' => $counts['client_count'],
        'patron' => $counts['patron_count']
    ];
}

// UPDATED QUERY - Show all recent borrowings by default, search only filters
$where_clauses = [];
$params = [];
$types = "";

if ($_SESSION['role'] == 'admin') {
    // Status filter
    if ($filter == 'overdue') {
        $where_clauses[] = "b.status = 'borrowed' AND b.due_date < CURDATE()";
    } elseif ($filter == 'returned') {
        $where_clauses[] = "b.status = 'returned'";
    } else {
        $where_clauses[] = "b.status = 'borrowed'";
    }
    
    // User type filter
    if ($user_filter == 'admin') {
        $where_clauses[] = "u.role = 'admin'";
    } elseif ($user_filter == 'client') {
        $where_clauses[] = "u.role = 'client'";
    } elseif ($user_filter == 'patron') {
        $where_clauses[] = "(u.role IS NULL OR u.role = '')";
    }
    
    // Search - only when actively searching
    if (!empty($search)) {
        $where_clauses[] = "(bk.title LIKE ? OR bk.author LIKE ? OR p.name LIKE ? OR p.email LIKE ?)";
        $search_param = "%$search%";
        $params = [$search_param, $search_param, $search_param, $search_param];
        $types = "ssss";
    }
    
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
    
    $sql = "SELECT b.*, bk.title, bk.author, p.name as patron_name, p.email as patron_email, 
        u.role as user_role, u.full_name as user_full_name, u.id as user_id
        FROM borrowings b 
        JOIN books bk ON b.book_id = bk.id 
        JOIN patrons p ON b.patron_id = p.id 
        LEFT JOIN users u ON p.email = u.email
        $where_sql
        ORDER BY " . ($filter == 'returned' ? 'b.return_date DESC' : 'b.borrow_date DESC, b.id DESC');
    
    if ($filter == 'returned') $sql .= " LIMIT 50";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
} else {
    // Client view
    $where_clauses = ["p.email = ?"];
    $params = [$current_user_email];
    $types = "s";
    
    if ($filter == 'overdue') {
        $where_clauses[] = "b.status = 'borrowed' AND b.due_date < CURDATE()";
    } elseif ($filter == 'returned') {
        $where_clauses[] = "b.status = 'returned'";
    } else {
        $where_clauses[] = "b.status = 'borrowed'";
    }
    
    if (!empty($search)) {
        $where_clauses[] = "(bk.title LIKE ? OR bk.author LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ss";
    }
    
    $sql = "SELECT b.*, bk.title, bk.author, p.name as patron_name 
            FROM borrowings b 
            JOIN books bk ON b.book_id = bk.id 
            JOIN patrons p ON b.patron_id = p.id 
            WHERE " . implode(" AND ", $where_clauses) . "
            ORDER BY b.borrow_date DESC";
    
    if ($filter == 'returned') $sql .= " LIMIT 50";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$borrowings = $stmt->get_result();

// Get counts
if ($_SESSION['role'] == 'admin') {
    $count_borrowed = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE status = 'borrowed'")->fetch_assoc()['count'];
    $count_overdue = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE status = 'borrowed' AND due_date < CURDATE()")->fetch_assoc()['count'];
    $count_returned = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE status = 'returned'")->fetch_assoc()['count'];
    
    $count_admin = $conn->query("SELECT COUNT(DISTINCT b.id) as count FROM borrowings b JOIN patrons p ON b.patron_id = p.id JOIN users u ON p.email = u.email WHERE b.status = 'borrowed' AND u.role = 'admin'")->fetch_assoc()['count'];
    $count_client = $conn->query("SELECT COUNT(DISTINCT b.id) as count FROM borrowings b JOIN patrons p ON b.patron_id = p.id JOIN users u ON p.email = u.email WHERE b.status = 'borrowed' AND u.role = 'client'")->fetch_assoc()['count'];
    $count_patron = $conn->query("SELECT COUNT(DISTINCT b.id) as count FROM borrowings b JOIN patrons p ON b.patron_id = p.id LEFT JOIN users u ON p.email = u.email WHERE b.status = 'borrowed' AND (u.role IS NULL OR u.role = '')")->fetch_assoc()['count'];
} else {
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM borrowings b JOIN patrons p ON b.patron_id = p.id WHERE b.status = 'borrowed' AND p.email = ?");
    $stmt_count->bind_param("s", $current_user_email);
    $stmt_count->execute();
    $count_borrowed = $stmt_count->get_result()->fetch_assoc()['count'];
    
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM borrowings b JOIN patrons p ON b.patron_id = p.id WHERE b.status = 'borrowed' AND b.due_date < CURDATE() AND p.email = ?");
    $stmt_count->bind_param("s", $current_user_email);
    $stmt_count->execute();
    $count_overdue = $stmt_count->get_result()->fetch_assoc()['count'];
    
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM borrowings b JOIN patrons p ON b.patron_id = p.id WHERE b.status = 'returned' AND p.email = ?");
    $stmt_count->bind_param("s", $current_user_email);
    $stmt_count->execute();
    $count_returned = $stmt_count->get_result()->fetch_assoc()['count'];
}

$default_due_date = date('Y-m-d', strtotime("+{$default_days} days"));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrowing System - Library</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php require_once '../includes/sidebar.php'; ?>
    <style>
        .grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }

        select, input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        input[type="date"] {
            cursor: pointer;
        }

        /* Searchable Select Styles */
        .searchable-select {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 10px 35px 10px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .search-input:focus {
            outline: none;
            border-color: #333;
        }

        .dropdown-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #666;
        }

        .patron-type-tabs {
            display: flex;
            gap: 8px;
            padding: 10px;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .patron-type-btn {
            padding: 6px 12px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .patron-type-btn:hover {
            border-color: #333;
        }

        .patron-type-btn.active {
            background: #333;
            color: white;
            border-color: #333;
        }

        .patron-type-btn .count {
            background: rgba(0,0,0,0.1);
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 10px;
        }

        .patron-type-btn.active .count {
            background: rgba(255,255,255,0.3);
        }

        .dropdown-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e0e0e0;
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 350px;
            overflow-y: auto;
            display: none;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .dropdown-list.active {
            display: block;
        }

        .dropdown-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }

        .dropdown-item:hover {
            background: #f5f5f5;
        }

        .dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-item .patron-name {
            font-weight: 500;
            color: #333;
        }

        .dropdown-item .patron-email {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }

        .dropdown-item .patron-role {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }
        .patron-role {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    margin-left: 8px;
}

.patron-role.role-admin {
    background: #e3f2fd;
    color: #1565c0;
}

.patron-role.role-client {
    background: #f3e5f5;
    color: #7b1fa2;
}

.patron-role.role-patron {
    background: #e8f5e9;
    color: #2e7d32;
}

        .role-admin {
            background: #e3f2fd;
            color: #1565c0;
        }

        .role-client {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .role-patron {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .no-results {
            padding: 20px;
            text-align: center;
            color: #999;
        }

        .info-box {
            background: lightgrey;
            border-left: 4px solid black;
            padding: 12px 15px;
            margin-bottom: 15px;
            border-radius: 4px;
            font-size: 13px;
            color: black;
        }

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 12px 15px;
            margin-bottom: 15px;
            border-radius: 4px;
            font-size: 13px;
            color: #856404;
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: #666;
            background: #f5f5f5;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
        }

        .filter-tab:hover {
            background: #e8e8e8;
        }

        .filter-tab.active {
            background: black;
            color: white;
            border-color: black;
        }

        .filter-tab .count {
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .filter-tab.active .count {
            background: rgba(255,255,255,0.3);
        }

        .user-type-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            flex-wrap: wrap;
        }

        .user-type-tab {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: #666;
            background: white;
            border: 2px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }

        .user-type-tab:hover {
            border-color: #333;
        }

        .user-type-tab.active {
            background: #333;
            color: white;
            border-color: #333;
        }

        .user-type-tab .count {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
        }

        .user-type-tab.active .count {
            background: rgba(255,255,255,0.3);
        }

        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-box input {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            flex: 1;
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

        .status-returned {
            background: #d4edda;
            color: #155724;
        }

        .btn-primary {
            background: black;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background: grey;
        }

        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .btn-search {
            padding: 10px 20px;
            background: black;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-clear {
            padding: 10px 20px;
            background: #f5f5f5;
            color: #666;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <div class="page-title">
                <i class="fas fa-book-open"></i>
                <?php echo $_SESSION['role'] == 'admin' ? 'Borrowing System' : 'My Borrowed Books'; ?>
            </div>
            <div class="top-bar-actions">
                <?php if ($_SESSION['role'] == 'admin'): ?>
                <a href="settings.php" class="btn-icon" title="Settings">
                    <i class="fas fa-cog"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-wrapper">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="grid">
                <div class="card">
                    <h2 style="margin-bottom: 20px;"><?php echo $_SESSION['role'] == 'admin' ? 'Issue New Book' : 'Borrow a Book'; ?></h2>
                    
                    <div class="info-box">
                        <strong><i class="fas fa-clipboard"></i> Borrowing Rules:</strong><br>
                        • Default period: <strong><?php echo $default_days; ?> days</strong><br>
                        • Max books: <strong><?php echo $max_books_per_patron; ?></strong><br>
                        • Renewals: <strong><?php echo $allow_renewals ? 'Allowed' : 'Not allowed'; ?></strong><br>
                        • Grace period: <strong><?php echo $settings['grace_period_days'] ?? 0; ?> day(s)</strong>
                    </div>
                    
                    <form method="POST" id="borrowForm">
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                        <div class="form-group">
                            <label>Search Patron <small style="color: #666; font-weight: normal;">(or leave empty to borrow for yourself)</small></label>
                            <div class="searchable-select">
                                <input type="text" 
                                       id="patron_search" 
                                       class="search-input" 
                                       placeholder="Type to search patrons, users, admins... (or leave empty for yourself)"
                                       autocomplete="off">
                                <i class="fas fa-chevron-down dropdown-icon"></i>
                                <input type="hidden" name="patron_id" id="patron_id">
                                <div class="dropdown-list" id="patron_dropdown">
                                    <!-- Filter tabs inside dropdown -->
                                    <div class="patron-type-tabs">
                                        <button type="button" class="patron-type-btn active" data-filter="all">
                                            <i class="fas fa-users"></i>
                                            All
                                            <span class="count"><?php echo $patron_counts['all']; ?></span>
                                        </button>
                                        <button type="button" class="patron-type-btn" data-filter="admin">
                                            <i class="fas fa-user-shield"></i>
                                            Admins
                                            <span class="count"><?php echo $patron_counts['admin']; ?></span>
                                        </button>
                                        <button type="button" class="patron-type-btn" data-filter="client">
                                            <i class="fas fa-user"></i>
                                            Clients
                                            <span class="count"><?php echo $patron_counts['client']; ?></span>
                                        </button>
                                        <button type="button" class="patron-type-btn" data-filter="patron">
                                            <i class="fas fa-user-tag"></i>
                                            Patrons
                                            <span class="count"><?php echo $patron_counts['patron']; ?></span>
                                        </button>
                                    </div>
                                    
                                    <!-- Myself option -->
                                    <div class="dropdown-item patron-item" 
                                         data-id=""
                                         data-name="<?php echo htmlspecialchars($_SESSION['full_name']); ?>"
                                         data-email="<?php echo htmlspecialchars($current_user_email); ?>"
                                         data-role="MYSELF"
                                         data-type="admin"
                                         style="background: #f0f8ff; border-bottom: 2px solid #333;">
                                        <div class="patron-name">
                                            <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?> (Me)
                                            <span class="patron-role role-admin">ADMIN</span>
                                        </div>
                                        <div class="patron-email"><?php echo htmlspecialchars($current_user_email); ?></div>
                                    </div>
                                    
                                    <!-- All patrons list -->
                                    <?php 
                                    if ($patrons):
                                        $patrons->data_seek(0);
                                        while ($patron = $patrons->fetch_assoc()): 
                                            $role = !empty($patron['role']) ? $patron['role'] : 'patron';
                                            $role_display = !empty($patron['role']) ? strtoupper($patron['role']) : 'PATRON';
                                            $role_class = !empty($patron['role']) ? "role-{$patron['role']}" : 'role-patron';
                                            $patron_email = !empty($patron['email']) ? $patron['email'] : 'No email';
                                            $data_type = !empty($patron['role']) ? $patron['role'] : 'patron';
                                            
                                            // Use user_id if patron doesn't have an id (user without patron record)
                                            $display_id = (isset($patron['patron_id']) && $patron['patron_id'] > 0) ? $patron['patron_id'] : $patron['user_id'];
                                        ?>
                                            <div class="dropdown-item patron-item" 
                                                 data-id="<?php echo $display_id; ?>"
                                                 data-name="<?php echo htmlspecialchars($patron['name']); ?>"
                                                 data-email="<?php echo htmlspecialchars($patron_email); ?>"
                                                 data-role="<?php echo $role_display; ?>"
                                                 data-type="<?php echo $data_type; ?>"
                                                 data-has-patron="<?php echo (isset($patron['patron_id']) && $patron['patron_id'] > 0) ? 'yes' : 'no'; ?>">
                                                <div class="patron-name">
                                                    <?php 
                                                    if ($role === 'admin') {
                                                        echo '<i class="fas fa-user-shield"></i> ';
                                                    } elseif ($role === 'client') {
                                                        echo '<i class="fas fa-user"></i> ';
                                                    } else {
                                                        echo '<i class="fas fa-user-tag"></i> ';
                                                    }
                                                    ?>
                                                    <?php echo htmlspecialchars($patron['name']); ?>
                                                    <span class="patron-role <?php echo $role_class; ?>"><?php echo $role_display; ?></span>
                                                    <?php if (!isset($patron['patron_id']) || $patron['patron_id'] == 0): ?>
                                                        <small style="color: #999; font-weight: normal;"> (Will create patron record)</small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="patron-email"><?php echo htmlspecialchars($patron_email); ?></div>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                    
                                    <!-- No results message -->
                                    <div id="no_patron_results" class="no-results" style="display: none;">
                                        <i class="fas fa-search"></i>
                                        <p>No patrons found</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="patron_status"></div>
                        <?php else: ?>
                        <div class="info-box">
                            <strong>Borrowing as:</strong> <?php echo htmlspecialchars($_SESSION['full_name']); ?><br>
                            <strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['email']); ?>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Select Book</label>
                            <select name="book_id" required>
                                <option value="">Choose a book...</option>
                                <?php 
                                $books->data_seek(0);
                                while ($book = $books->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $book['id']; ?>">
                                        <?php echo htmlspecialchars($book['title']); ?> - <?php echo htmlspecialchars($book['author']); ?> (<?php echo $book['available']; ?> available)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Due Date <small style="color: #666; font-weight: normal;">(Select from calendar)</small></label>
                            <input type="date" 
                                   name="due_date" 
                                   id="due_date"
                                   value="<?php echo $default_due_date; ?>"
                                   min="<?php echo date('Y-m-d'); ?>"
                                   required>
                            <small style="color: #666; font-size: 12px;">Default: <?php echo $default_days; ?> days from today</small>
                        </div>

                        <button type="submit" name="borrow" class="btn-primary" id="borrowBtn" style="width: 100%;">
                            <i class="fas fa-book-open"></i> Borrow Book
                        </button>
                    </form>
                </div>

                <div class="card">
                    <h2 style="margin-bottom: 20px;">Borrowing Records</h2>

                    <div class="filter-tabs">
                        <a href="?filter=all&search=<?php echo urlencode($search); ?>&user_filter=<?php echo urlencode($user_filter); ?>" 
                           class="filter-tab <?php echo ($filter == 'all' || $filter == 'borrowed') ? 'active' : ''; ?>">
                            <i class="fas fa-book-open"></i>
                            Borrowed
                            <span class="count"><?php echo $count_borrowed; ?></span>
                        </a>
                        <a href="?filter=overdue&search=<?php echo urlencode($search); ?>&user_filter=<?php echo urlencode($user_filter); ?>" 
                           class="filter-tab <?php echo $filter == 'overdue' ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-triangle"></i>
                            Overdue
                            <span class="count"><?php echo $count_overdue; ?></span>
                        </a>
                        <a href="?filter=returned&search=<?php echo urlencode($search); ?>&user_filter=<?php echo urlencode($user_filter); ?>" 
                           class="filter-tab <?php echo $filter == 'returned' ? 'active' : ''; ?>">
                            <i class="fas fa-check-circle"></i>
                            Returned
                            <span class="count"><?php echo $count_returned; ?></span>
                        </a>
                    </div>

                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <div class="user-type-filters">
                        <strong style="margin-right: 10px; align-self: center; color: #666;">Filter by User Type:</strong>
                        <a href="?filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search); ?>&user_filter=all" 
                           class="user-type-tab <?php echo $user_filter == 'all' ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i>
                            All Users
                        </a>
                        <a href="?filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search); ?>&user_filter=admin" 
                           class="user-type-tab <?php echo $user_filter == 'admin' ? 'active' : ''; ?>">
                            <i class="fas fa-user-shield"></i>
                            Admins
                            <span class="count"><?php echo $count_admin; ?></span>
                        </a>
                        <a href="?filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search); ?>&user_filter=client" 
                           class="user-type-tab <?php echo $user_filter == 'client' ? 'active' : ''; ?>">
                            <i class="fas fa-user"></i>
                            Clients
                            <span class="count"><?php echo $count_client; ?></span>
                        </a>
                        <a href="?filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search); ?>&user_filter=patron" 
                           class="user-type-tab <?php echo $user_filter == 'patron' ? 'active' : ''; ?>">
                            <i class="fas fa-user-tag"></i>
                            Patrons Only
                            <span class="count"><?php echo $count_patron; ?></span>
                        </a>
                    </div>
                    <?php endif; ?>

                    <form method="GET" class="search-box">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="hidden" name="user_filter" value="<?php echo htmlspecialchars($user_filter); ?>">
                        <input type="text" name="search" placeholder="Search by book title, author, patron name, or email..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <?php if ($search): ?>
                            <a href="?filter=<?php echo $filter; ?>&user_filter=<?php echo $user_filter; ?>" class="btn-clear">Clear</a>
                        <?php endif; ?>
                    </form>

                    <table>
                        <thead>
                            <tr>
                                <th>Book</th>
                                <?php if ($_SESSION['role'] == 'admin'): ?>
                                <th>Patron / User</th>
                                <?php endif; ?>
                                <th>Borrowed</th>
                                <th>Due Date</th>
                                <?php if ($filter == 'returned'): ?>
                                <th>Returned</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <?php if ($_SESSION['role'] == 'admin' && $filter != 'returned'): ?>
                                <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($borrowings->num_rows > 0): ?>
                                <?php while ($row = $borrowings->fetch_assoc()): ?>
                                    <?php
                                    $is_overdue = strtotime($row['due_date']) < time() && $row['status'] == 'borrowed';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['title']); ?></strong><br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($row['author']); ?></small>
                                        </td>
                                        <?php if ($_SESSION['role'] == 'admin'): ?>
                                        <td>
                                            <?php echo htmlspecialchars($row['patron_name']); ?>
                                            <?php if (isset($row['user_role']) && !empty($row['user_role'])): ?>
    <span class="patron-role role-<?php echo $row['user_role']; ?>">
        <?php echo strtoupper($row['user_role']); ?>
    </span>
<?php else: ?>
    <span class="patron-role role-patron">PATRON</span>
<?php endif; ?>
                                            <br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($row['patron_email']); ?></small>
                                        </td>
                                        <?php endif; ?>
                                        <td><?php echo date('M d, Y', strtotime($row['borrow_date'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($row['due_date'])); ?></td>
                                        <?php if ($filter == 'returned'): ?>
                                        <td><?php echo $row['return_date'] ? date('M d, Y', strtotime($row['return_date'])) : '-'; ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <span class="status-badge status-<?php echo $is_overdue ? 'overdue' : $row['status']; ?>">
                                                <?php 
                                                if ($is_overdue) {
                                                    echo 'Overdue';
                                                } else {
                                                    echo ucfirst($row['status']);
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <?php if ($_SESSION['role'] == 'admin' && $filter != 'returned'): ?>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="borrow_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="return" class="btn-primary" style="padding: 8px 16px; width: auto;">
                                                    <i class="fas fa-check"></i> Return
                                                </button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo ($_SESSION['role'] == 'admin' ? '6' : '4') + ($filter == 'returned' ? 1 : 0); ?>">
                                        <div class="empty-state">
                                            <i class="fas fa-search"></i>
                                            <p>No records found</p>
                                            <?php if ($search): ?>
                                                <p style="font-size: 14px; margin-top: 10px;">
                                                    Try adjusting your search terms
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($_SESSION['role'] == 'admin'): ?>
    <script>
    // Searchable patron dropdown
    const patronSearch = document.getElementById('patron_search');
    const patronDropdown = document.getElementById('patron_dropdown');
    const patronIdInput = document.getElementById('patron_id');
    const allPatronItems = Array.from(document.querySelectorAll('.patron-item'));
    const patronTypeBtns = document.querySelectorAll('.patron-type-btn');
    const noResultsMsg = document.getElementById('no_patron_results');
    
    let currentFilter = 'all';

    // Toggle dropdown on click
    patronSearch.addEventListener('click', function() {
        patronDropdown.classList.add('active');
    });

    // Filter by patron type buttons
    patronTypeBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            
            // Update active button
            patronTypeBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Get filter type
            currentFilter = this.dataset.filter;
            
            // Apply filter
            filterPatrons();
        });
    });

    // Filter patrons as user types
    patronSearch.addEventListener('input', function() {
        filterPatrons();
    });

    function filterPatrons() {
        const searchTerm = patronSearch.value.toLowerCase();
        let hasResults = false;

        allPatronItems.forEach(item => {
            const name = item.dataset.name.toLowerCase();
            const email = item.dataset.email.toLowerCase();
            const role = item.dataset.role.toLowerCase();
            const type = item.dataset.type;
            
            // Check if matches search term
            const matchesSearch = name.includes(searchTerm) || 
                                 email.includes(searchTerm) || 
                                 role.includes(searchTerm);
            
            // Check if matches filter
            const matchesFilter = currentFilter === 'all' || type === currentFilter;
            
            if (matchesSearch && matchesFilter) {
                item.style.display = 'block';
                hasResults = true;
            } else {
                item.style.display = 'none';
            }
        });

        // Show/hide no results message
        if (hasResults) {
            patronDropdown.classList.add('active');
            noResultsMsg.style.display = 'none';
        } else {
            noResultsMsg.style.display = 'block';
        }
    }

    // Select patron from dropdown
    allPatronItems.forEach(item => {
        item.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const email = this.dataset.email;
            const role = this.dataset.role;

            const hasPatron = this.dataset.hasPatron;

if (role === 'MYSELF' || id === '') {
    patronSearch.value = `${name} (Borrowing for myself)`;
    patronIdInput.value = ''; // Empty means borrow for self
} else {
    patronSearch.value = `${name} (${role})`;
    // Add prefix to identify if it's a patron_id (P:) or user_id (U:)
    if (hasPatron === 'yes') {
        patronIdInput.value = 'P:' + id; // P: means patron_id
    } else {
        patronIdInput.value = 'U:' + id; // U: means user_id (will create patron)
    }
}
            
            patronDropdown.classList.remove('active');

            // Check patron limit only if selecting someone else
            if (id !== '') {
                checkPatronLimit(id);
            } else {
                // Clear status when borrowing for self
                document.getElementById('patron_status').innerHTML = '';
                document.getElementById('borrowBtn').disabled = false;
            }
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!patronSearch.contains(e.target) && !patronDropdown.contains(e.target)) {
            patronDropdown.classList.remove('active');
        }
    });

    // Check patron borrowing limit
    function checkPatronLimit(patronId) {
        if (!patronId) {
            document.getElementById('patron_status').innerHTML = '';
            document.getElementById('borrowBtn').disabled = false;
            return;
        }
        
        fetch('../api/index.php?module=check_patron_limit&patron_id=' + patronId)
            .then(response => response.json())
            .then(data => {
                const statusDiv = document.getElementById('patron_status');
                const borrowBtn = document.getElementById('borrowBtn');
                
                if (data.can_borrow) {
                    statusDiv.innerHTML = `
                        <div class="info-box">
                            <strong>✅ ${data.message}</strong>
                        </div>
                    `;
                    borrowBtn.disabled = false;
                } else {
                    statusDiv.innerHTML = `
                        <div class="warning-box">
                            <strong>⚠️ ${data.message}</strong><br>
                            Currently borrowed: <strong>${data.current_count}</strong> / <strong>${data.max_allowed}</strong>
                        </div>
                    `;
                    borrowBtn.disabled = true;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('patron_status').innerHTML = `
                    <div class="warning-box">
                        <strong>⚠️ Error checking patron status</strong>
                    </div>
                `;
            });
    }
    </script>
    <?php endif; ?>
</body>
</html>