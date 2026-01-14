<?php
// ========================================
// PATRON ANALYTICS AND PROFILING FUNCTIONS
// ========================================

/**
 * Get comprehensive patron statistics
 */
function getPatronStatistics($conn, $patron_id) {
    $stats = [
        'total_borrowed' => 0,
        'currently_borrowed' => 0,
        'total_returned' => 0,
        'overdue_count' => 0
    ];
    
    $sql = "SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_borrowed'] = $result->fetch_assoc()['count'];
    
    $sql = "SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ? AND status IN ('borrowed', 'overdue')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['currently_borrowed'] = $result->fetch_assoc()['count'];
    
    $sql = "SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ? AND status = 'returned'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_returned'] = $result->fetch_assoc()['count'];
    
    $sql = "SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ? AND status = 'overdue'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['overdue_count'] = $result->fetch_assoc()['count'];
    
    return $stats;
}

/**
 * Get patron's favorite book categories
 */
function getPatronFavoriteCategories($conn, $patron_id, $limit = 5) {
    $sql = "SELECT b.category, COUNT(*) as count 
            FROM borrowings br
            JOIN books b ON br.book_id = b.id
            WHERE br.patron_id = ? AND b.category IS NOT NULL AND b.category != ''
            GROUP BY b.category
            ORDER BY count DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $patron_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    
    return $categories;
}

/**
 * Get patron's borrowing trends by month
 */
function getPatronBorrowingTrends($conn, $patron_id, $months = 6) {
    $sql = "SELECT DATE_FORMAT(borrow_date, '%Y-%m') as month,
            DATE_FORMAT(borrow_date, '%b %Y') as month_name,
            COUNT(*) as count
            FROM borrowings
            WHERE patron_id = ? AND borrow_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY month, month_name
            ORDER BY month DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $patron_id, $months);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $trends = [];
    while ($row = $result->fetch_assoc()) {
        $trends[] = [
            'month' => $row['month_name'],
            'count' => $row['count']
        ];
    }
    
    return $trends;
}

/**
 * Get patron's reading velocity (average days to return)
 */
function getPatronReadingVelocity($conn, $patron_id) {
    $sql = "SELECT AVG(DATEDIFF(return_date, borrow_date)) as avg_days
            FROM borrowings
            WHERE patron_id = ? AND return_date IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return round($row['avg_days'] ?? 0, 1);
}

/**
 * Get patron's on-time return rate
 */
function getPatronOnTimeRate($conn, $patron_id) {
    $sql = "SELECT COUNT(*) as total FROM borrowings WHERE patron_id = ? AND return_date IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    
    if ($total == 0) return 100;
    
    $sql = "SELECT COUNT(*) as ontime FROM borrowings 
            WHERE patron_id = ? AND return_date IS NOT NULL AND return_date <= due_date";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $ontime = $stmt->get_result()->fetch_assoc()['ontime'];
    
    return round(($ontime / $total) * 100, 1);
}

/**
 * Get patron's borrowing activity summary
 */
function getPatronActivitySummary($conn, $patron_id) {
    return [
        'statistics' => getPatronStatistics($conn, $patron_id),
        'favorite_categories' => getPatronFavoriteCategories($conn, $patron_id),
        'reading_velocity' => getPatronReadingVelocity($conn, $patron_id),
        'ontime_rate' => getPatronOnTimeRate($conn, $patron_id),
        'total_fines' => getPatronTotalFines($conn, $patron_id),
        'monthly_trends' => getPatronBorrowingTrends($conn, $patron_id)
    ];
}

/**
 * Check if patron has any restrictions
 */
function checkPatronRestrictions($conn, $patron_id) {
    $restrictions = [
        'restricted' => false,
        'reasons' => []
    ];
    
    $sql = "SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ? AND status = 'overdue'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $overdue_count = $stmt->get_result()->fetch_assoc()['count'];
    
    if ($overdue_count > 0) {
        $restrictions['restricted'] = true;
        $restrictions['reasons'][] = "$overdue_count overdue book(s)";
    }
    
    $unpaid_fines = getPatronTotalFines($conn, $patron_id);
    if ($unpaid_fines > 0) {
        $restrictions['restricted'] = true;
        $restrictions['reasons'][] = "₱" . number_format($unpaid_fines, 2) . " in unpaid fines";
    }
    
    $limit_check = canPatronBorrow($conn, $patron_id);
    if (!$limit_check['can_borrow']) {
        $restrictions['restricted'] = true;
        $restrictions['reasons'][] = "Maximum borrowing limit reached";
    }
    
    return $restrictions;
}

/**
 * Get most borrowed books by patron
 */
function getPatronMostBorrowedBooks($conn, $patron_id, $limit = 5) {
    $sql = "SELECT b.title, b.author, COUNT(*) as borrow_count
            FROM borrowings br
            JOIN books b ON br.book_id = b.id
            WHERE br.patron_id = ?
            GROUP BY br.book_id, b.title, b.author
            ORDER BY borrow_count DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $patron_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $books = [];
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
    
    return $books;
}

// ========================================
// SETTINGS HELPER FUNCTIONS
// ========================================

function getSetting($conn, $key, $default = null) {
    static $settings_cache = [];
    
    if (isset($settings_cache[$key])) {
        return $settings_cache[$key];
    }
    
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $value = $result->fetch_assoc()['setting_value'];
        $settings_cache[$key] = $value;
        return $value;
    }
    
    return $default;
}

function getAllSettings($conn) {
    static $all_settings = null;
    
    if ($all_settings !== null) {
        return $all_settings;
    }
    
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    $all_settings = $settings;
    return $settings;
}

function canPatronBorrow($conn, $patron_id) {
    $max_books = getSetting($conn, 'max_books_per_patron', 5);
    
    $sql = "SELECT COUNT(*) as count FROM borrowings WHERE patron_id = ? AND status IN ('borrowed', 'overdue')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_count = $result->fetch_assoc()['count'];
    
    $can_borrow = $current_count < $max_books;
    $message = $can_borrow ? 
        "Patron can borrow " . ($max_books - $current_count) . " more book(s)" :
        "Patron has reached the maximum limit of $max_books books";
    
    return [
        'can_borrow' => $can_borrow,
        'current_count' => $current_count,
        'max_allowed' => $max_books,
        'message' => $message
    ];
}

// ========================================
// ACTIVITY LOGGING FUNCTIONS
// ========================================

// ========================================
// ACTIVITY LOGGING FUNCTIONS - FIXED
// ========================================

function logActivity($conn, $user_id, $action, $description = '') {
    // Validate user_id exists before logging
    if (!$user_id || !is_numeric($user_id)) {
        return false;
    }
    
    // Check if user exists in database
    $check_sql = "SELECT id FROM users WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        return false;
    }
    
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    // If user doesn't exist, don't log
    if ($result->num_rows === 0) {
        $check_stmt->close();
        return false;
    }
    $check_stmt->close();
    
    // Proceed with logging
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $sql = "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("issss", $user_id, $action, $description, $ip_address, $user_agent);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function logActivitySafe($conn, $user_id, $action, $description = '') {
    // This function now just calls the fixed logActivity
    return logActivity($conn, $user_id, $action, $description);
}

function logLogin($conn, $user_id, $username, $status, $failure_reason = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $sql = "INSERT INTO login_history (user_id, username, ip_address, user_agent, status, failure_reason) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("isssss", $user_id, $username, $ip_address, $user_agent, $status, $failure_reason);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function updateLastActivity($conn, $user_id) {
    if (!$user_id || !is_numeric($user_id)) {
        return false;
    }
    
    $sql = "UPDATE users SET last_activity = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function checkAccountLocked($conn, $username) {
    $sql = "SELECT locked_until FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $stmt->close();
            return true;
        } else if ($user['locked_until']) {
            $stmt->close();
            $sql = "UPDATE users SET locked_until = NULL, login_attempts = 0 WHERE username = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    if (isset($stmt)) {
        $stmt->close();
    }
    return false;
}

function incrementLoginAttempts($conn, $username) {
    $sql = "UPDATE users SET login_attempts = login_attempts + 1 WHERE username = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();

    $sql = "SELECT login_attempts FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if ($user['login_attempts'] >= 5) {
            $locked_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            $sql = "UPDATE users SET locked_until = ? WHERE username = ?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("ss", $locked_until, $username);
                $stmt->execute();
                $stmt->close();
            }
            return true;
        }
    } else {
        $stmt->close();
    }
    
    return false;
}

function resetLoginAttempts($conn, $username) {
    $sql = "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE username = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $username);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function createUserSession($conn, $user_id, $session_id) {
    if (!$user_id || !is_numeric($user_id)) {
        return false;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $sql = "INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, last_activity) 
            VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("isss", $user_id, $session_id, $ip_address, $user_agent);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function updateUserSession($conn, $session_id) {
    $sql = "UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $session_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function deleteUserSession($conn, $session_id) {
    $sql = "DELETE FROM user_sessions WHERE session_id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $session_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function checkSessionTimeout($conn, $timeout_minutes = 30) {
    if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];

        if ($inactive_time > ($timeout_minutes * 60)) {
            // Only log if user_id is valid
            if (is_numeric($_SESSION['user_id'])) {
                logActivity($conn, $_SESSION['user_id'], 'SESSION_TIMEOUT', 'Session expired due to inactivity');
            }
            
            if (session_id()) {
                deleteUserSession($conn, session_id());
            }
            
            session_unset();
            session_destroy();
            header("Location: ../auth/login.php?timeout=1");
            exit();
        }

        $_SESSION['last_activity'] = time();
        
        if (session_id()) {
            updateUserSession($conn, session_id());
        }
        
        if (is_numeric($_SESSION['user_id'])) {
            updateLastActivity($conn, $_SESSION['user_id']);
        }
    }
}

function formatUserAgent($user_agent) {
    if (strpos($user_agent, 'Chrome') !== false) return 'Chrome';
    if (strpos($user_agent, 'Firefox') !== false) return 'Firefox';
    if (strpos($user_agent, 'Safari') !== false) return 'Safari';
    if (strpos($user_agent, 'Edge') !== false) return 'Edge';
    return 'Other';
}

function getActionIcon($action) {
    $icons = [
        'LOGIN' => '🔓',
        'LOGOUT' => '🔒',
        'CREATE_BOOK' => '📚',
        'UPDATE_BOOK' => '✏️',
        'DELETE_BOOK' => '🗑️',
        'CREATE_PATRON' => '👤',
        'UPDATE_PATRON' => '✏️',
        'DELETE_PATRON' => '🗑️',
        'CREATE_USER' => '👥',
        'UPDATE_USER' => '✏️',
        'DELETE_USER' => '🗑️',
        'BORROW_BOOK' => '📖',
        'RETURN_BOOK' => '📥',
        'PASSWORD_CHANGE' => '🔑',
        'PASSWORD_RESET' => '🔓',
        'SESSION_TIMEOUT' => '⏱️'
    ];

    return isset($icons[$action]) ? $icons[$action] : '📋';
}

// ========================================
// FINE CALCULATION FUNCTIONS
// ========================================

function calculateFine($conn, $borrowing_id) {
    $fine_per_day = getSetting($conn, 'fine_per_day', 5.00);
    $max_fine = getSetting($conn, 'max_fine_amount', 500.00);
    $grace_period = getSetting($conn, 'grace_period_days', 0);
    
    $sql = "SELECT due_date, return_date FROM borrowings WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $borrowing_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return 0;
    }
    
    $borrowing = $result->fetch_assoc();
    $due_date = strtotime($borrowing['due_date']);
    $return_date = $borrowing['return_date'] ? strtotime($borrowing['return_date']) : time();
    
    $days_overdue = max(0, floor(($return_date - $due_date) / (60 * 60 * 24)) - $grace_period);
    
    if ($days_overdue <= 0) {
        return 0;
    }
    
    $fine_amount = min($days_overdue * $fine_per_day, $max_fine);
    
    return $fine_amount;
}

function checkAndUpdateOverdueBooks($conn) {
    $auto_calculate = getSetting($conn, 'auto_calculate_fines', '1');
    
    $sql = "UPDATE borrowings 
            SET status = 'overdue' 
            WHERE status = 'borrowed' 
            AND due_date < CURDATE()";
    $conn->query($sql);
    
    if ($auto_calculate == '1') {
        $sql = "SELECT id, patron_id, due_date FROM borrowings WHERE status = 'overdue'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $fine_amount = calculateFine($conn, $row['id']);
                $days_overdue = floor((time() - strtotime($row['due_date'])) / (60 * 60 * 24));
                
                $grace_period = getSetting($conn, 'grace_period_days', 0);
                $days_overdue = max(0, $days_overdue - $grace_period);
                
                if ($fine_amount > 0 && $days_overdue > 0) {
                    $sql = "INSERT INTO fines (borrowing_id, patron_id, amount, reason, days_overdue, status)
                            VALUES (?, ?, ?, 'Overdue book', ?, 'unpaid')
                            ON DUPLICATE KEY UPDATE 
                                amount = VALUES(amount),
                                days_overdue = VALUES(days_overdue)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("iidi", $row['id'], $row['patron_id'], $fine_amount, $days_overdue);
                    $stmt->execute();
                }
            }
        }
    }
}

function getPatronTotalFines($conn, $patron_id) {
    $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE patron_id = ? AND status = 'unpaid'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['total'];
}

function hasUnpaidFines($conn, $patron_id) {
    $sql = "SELECT COUNT(*) as count FROM fines WHERE patron_id = ? AND status = 'unpaid'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patron_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    return $count > 0;
}

// ========================================
// USER/PATRON MANAGEMENT FUNCTIONS
// ========================================

/**
 * Get or create patron record for a user
 */
function getOrCreatePatronForUser($conn, $user_id) {
    $stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user) return null;
    
    $stmt = $conn->prepare("SELECT id FROM patrons WHERE email = ?");
    $stmt->bind_param("s", $user['email']);
    $stmt->execute();
    $patron = $stmt->get_result()->fetch_assoc();
    
    if ($patron) {
        return $patron['id'];
    }
    
    $account_status = 'active';
    $stmt = $conn->prepare("INSERT INTO patrons (name, email, phone, address, account_status, user_id) VALUES (?, ?, '', '', ?, ?)");
    $stmt->bind_param("sssi", $user['full_name'], $user['email'], $account_status, $user_id);
    
    if ($stmt->execute()) {
        return $stmt->insert_id;
    }
    
    return null;
}

/**
 * Get patron ID from user email
 */
function getPatronIdByEmail($conn, $email) {
    $stmt = $conn->prepare("SELECT id FROM patrons WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result ? $result['id'] : null;
}

/**
 * Get user's borrowing history
 */
function getUserBorrowingHistory($conn, $email, $limit = 10) {
    $sql = "SELECT b.*, bk.title, bk.author, bk.isbn, bk.category,
            CASE 
                WHEN b.status = 'borrowed' AND b.due_date < CURDATE() THEN 'overdue'
                ELSE b.status
            END as current_status,
            DATEDIFF(b.due_date, CURDATE()) as days_until_due,
            DATEDIFF(CURDATE(), b.due_date) as days_overdue
            FROM borrowings b
            JOIN books bk ON b.book_id = bk.id
            JOIN patrons p ON b.patron_id = p.id
            WHERE p.email = ?
            ORDER BY b.borrow_date DESC
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $email, $limit);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get user's currently borrowed books
 */
function getUserCurrentBorrowings($conn, $email) {
    $sql = "SELECT b.*, bk.title, bk.author, bk.isbn, bk.category,
            CASE 
                WHEN b.due_date < CURDATE() THEN 'overdue'
                WHEN DATEDIFF(b.due_date, CURDATE()) <= 3 THEN 'due_soon'
                ELSE 'ok'
            END as status_flag,
            DATEDIFF(b.due_date, CURDATE()) as days_until_due,
            DATEDIFF(CURDATE(), b.due_date) as days_overdue
            FROM borrowings b
            JOIN books bk ON b.book_id = bk.id
            JOIN patrons p ON b.patron_id = p.id
            WHERE p.email = ? AND b.status = 'borrowed'
            ORDER BY b.due_date ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get user borrowing statistics
 */
function getUserBorrowingStats($conn, $email) {
    $patron_id = getPatronIdByEmail($conn, $email);
    
    if (!$patron_id) {
        return [
            'total_borrowed' => 0,
            'currently_borrowed' => 0,
            'total_returned' => 0,
            'overdue_count' => 0,
            'total_fines' => 0
        ];
    }
    
    $stats = [];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE patron_id = {$patron_id}");
    $stats['total_borrowed'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE patron_id = {$patron_id} AND status = 'borrowed'");
    $stats['currently_borrowed'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE patron_id = {$patron_id} AND status = 'returned'");
    $stats['total_returned'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE patron_id = {$patron_id} AND status = 'borrowed' AND due_date < CURDATE()");
    $stats['overdue_count'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM fines WHERE patron_id = {$patron_id} AND status = 'unpaid'");
    $stats['total_fines'] = $result->fetch_assoc()['total'];
    
    return $stats;
}

/**
 * Sync user and patron data
 */
function syncUserPatronData($conn, $user_id) {
    $stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if ($user) {
        $stmt = $conn->prepare("UPDATE patrons SET name = ? WHERE email = ?");
        $stmt->bind_param("ss", $user['full_name'], $user['email']);
        $stmt->execute();
        
        return $stmt->affected_rows > 0;
    }
    
    return false;
}

/**
 * Get unified user/patron profile
 */
function getUnifiedProfile($conn, $identifier, $identifier_type = 'email') {
    $profile = [];
    
    if ($identifier_type == 'email') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        $stmt = $conn->prepare("SELECT * FROM patrons WHERE email = ?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $patron = $stmt->get_result()->fetch_assoc();
        
        $profile = [
            'user' => $user,
            'patron' => $patron,
            'has_user_account' => !empty($user),
            'has_patron_record' => !empty($patron)
        ];
    } elseif ($identifier_type == 'user_id') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if ($user) {
            $stmt = $conn->prepare("SELECT * FROM patrons WHERE email = ?");
            $stmt->bind_param("s", $user['email']);
            $stmt->execute();
            $patron = $stmt->get_result()->fetch_assoc();
            
            $profile = [
                'user' => $user,
                'patron' => $patron,
                'has_user_account' => true,
                'has_patron_record' => !empty($patron)
            ];
        }
    } elseif ($identifier_type == 'patron_id') {
        $stmt = $conn->prepare("SELECT * FROM patrons WHERE id = ?");
        $stmt->bind_param("i", $identifier);
        $stmt->execute();
        $patron = $stmt->get_result()->fetch_assoc();
        
        if ($patron) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->bind_param("s", $patron['email']);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
            $profile = [
                'user' => $user,
                'patron' => $patron,
                'has_user_account' => !empty($user),
                'has_patron_record' => true
            ];
        }
    }
    
    return $profile;
}

function checkBorrowingSyncStatus($conn, $email) {
    $patron_id = getPatronIdByEmail($conn, $email);
    
    if (!$patron_id) {
        return [
            'synced' => false,
            'message' => 'No patron record found',
            'needs_sync' => true
        ];
    }
    
    $borrowing_count = $conn->query("SELECT COUNT(*) as count FROM borrowings WHERE patron_id = {$patron_id}")->fetch_assoc()['count'];
    
    return [
        'synced' => true,
        'message' => 'Records are synchronized',
        'patron_id' => $patron_id,
        'borrowing_count' => $borrowing_count,
        'needs_sync' => false
    ];
}

// ========================================
// FACULTY-SPECIFIC FUNCTIONS
// ========================================

/**
 * Create a book reservation for faculty
 */
function createReservation($conn, $book_id, $user_id, $patron_id) {
    $stmt = $conn->prepare("SELECT available FROM books WHERE id = ?");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $book = $stmt->get_result()->fetch_assoc();
    
    if (!$book) {
        return ['success' => false, 'message' => 'Book not found'];
    }
    
    $stmt = $conn->prepare("SELECT id FROM book_reservations WHERE book_id = ? AND user_id = ? AND status IN ('pending', 'ready')");
    $stmt->bind_param("ii", $book_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'You already have an active reservation for this book'];
    }
    
    $expiry_days = getSetting($conn, 'reservation_expiry_days', 3);
    $expiry_date = date('Y-m-d H:i:s', strtotime("+{$expiry_days} days"));
    
    $stmt = $conn->prepare("INSERT INTO book_reservations (book_id, user_id, patron_id, expiry_date, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->bind_param("iiis", $book_id, $user_id, $patron_id, $expiry_date);
    
    if ($stmt->execute()) {
        logActivity($conn, $user_id, 'BOOK_RESERVATION', "Reserved book ID: {$book_id}");
        return ['success' => true, 'message' => 'Book reserved successfully'];
    }
    
    return ['success' => false, 'message' => 'Failed to create reservation'];
}

/**
 * Cancel a reservation
 */
function cancelReservation($conn, $reservation_id, $user_id) {
    $stmt = $conn->prepare("SELECT book_id FROM book_reservations WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $reservation_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return ['success' => false, 'message' => 'Reservation not found'];
    }
    
    $reservation = $result->fetch_assoc();
    
    $stmt = $conn->prepare("UPDATE book_reservations SET status = 'cancelled' WHERE id = ?");
    $stmt->bind_param("i", $reservation_id);
    
    if ($stmt->execute()) {
        logActivity($conn, $user_id, 'CANCEL_RESERVATION', "Cancelled reservation ID: {$reservation_id}");
        return ['success' => true, 'message' => 'Reservation cancelled successfully'];
    }
    
    return ['success' => false, 'message' => 'Failed to cancel reservation'];
}

/**
 * Get faculty reservations
 */
function getFacultyReservations($conn, $user_id, $status = null) {
    $sql = "SELECT br.*, b.title, b.author, b.available, b.category
            FROM book_reservations br
            JOIN books b ON br.book_id = b.id
            WHERE br.user_id = ?";
    
    $params = [$user_id];
    $types = "i";
    
    if ($status) {
        $sql .= " AND br.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    $sql .= " ORDER BY br.reservation_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Check if faculty can borrow more books
 */
function canFacultyBorrow($conn, $user_id) {
    $stmt = $conn->prepare("SELECT max_borrow_limit FROM faculty_permissions WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $max_limit = 10;
    if ($result->num_rows > 0) {
        $max_limit = $result->fetch_assoc()['max_borrow_limit'];
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM borrowings b
                           JOIN patrons p ON b.patron_id = p.id
                           JOIN users u ON p.email = u.email
                           WHERE u.id = ? AND b.status IN ('borrowed', 'overdue')");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $current_count = $stmt->get_result()->fetch_assoc()['count'];
    
    return [
        'can_borrow' => $current_count < $max_limit,
        'current_count' => $current_count,
        'max_limit' => $max_limit,
        'remaining' => $max_limit - $current_count
    ];
}

/**
 * Get faculty borrow statistics
 */
function getFacultyStats($conn, $user_id) {
    $email = null;
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $email = $result->fetch_assoc()['email'];
    }
    
    if (!$email) {
        return [
            'total_borrowed' => 0,
            'currently_borrowed' => 0,
            'total_returned' => 0,
            'overdue_count' => 0,
            'reservations_count' => 0
        ];
    }
    
    $stats = getUserBorrowingStats($conn, $email);
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM book_reservations WHERE user_id = ? AND status IN ('pending', 'ready')");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats['reservations_count'] = $stmt->get_result()->fetch_assoc()['count'];
    
    return $stats;
}

/**
 * Create borrow request for faculty
 */
function createFacultyBorrowRequest($conn, $book_id, $user_id) {
    $can_borrow = canFacultyBorrow($conn, $user_id);
    if (!$can_borrow['can_borrow']) {
        return [
            'success' => false,
            'message' => "You have reached your borrowing limit of {$can_borrow['max_limit']} books"
        ];
    }
    
    $stmt = $conn->prepare("SELECT title, available FROM books WHERE id = ?");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $book = $stmt->get_result()->fetch_assoc();
    
    if (!$book) {
        return ['success' => false, 'message' => 'Book not found'];
    }
    
    if ($book['available'] <= 0) {
        return ['success' => false, 'message' => 'Book is not available'];
    }
    
    $patron_id = getOrCreatePatronForUser($conn, $user_id);
    if (!$patron_id) {
        return ['success' => false, 'message' => 'Failed to create patron record'];
    }
    
    $stmt = $conn->prepare("SELECT id FROM borrowings WHERE book_id = ? AND patron_id = ? AND status IN ('borrowed', 'overdue')");
    $stmt->bind_param("ii", $book_id, $patron_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'You have already borrowed this book'];
    }
    
    $borrow_days = getSetting($conn, 'faculty_max_borrow_days', 30);
    $borrow_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime("+{$borrow_days} days"));
    
    $stmt = $conn->prepare("INSERT INTO borrowings (book_id, patron_id, borrow_date, due_date, status) VALUES (?, ?, ?, ?, 'borrowed')");
    $stmt->bind_param("iiss", $book_id, $patron_id, $borrow_date, $due_date);
    
    if ($stmt->execute()) {
        // Availability is managed by DB triggers on borrowings; do not update here to avoid double-adjusting
        logActivity($conn, $user_id, 'BORROW_BOOK', "Borrowed book: {$book['title']}");
        
        return [
            'success' => true,
            'message' => "Book borrowed successfully! Due date: " . date('M d, Y', strtotime($due_date))
        ];
    }
    
    return ['success' => false, 'message' => 'Failed to process borrow request'];
}

/**
 * Expire old reservations
 */
function expireOldReservations($conn) {
    $sql = "UPDATE book_reservations 
            SET status = 'expired' 
            WHERE status = 'pending' 
            AND expiry_date < NOW()";
    
    return $conn->query($sql);
}

/**
 * Get faculty dashboard summary
 */
function getFacultyDashboardSummary($conn, $user_id) {
    $stats = getFacultyStats($conn, $user_id);
    $can_borrow = canFacultyBorrow($conn, $user_id);
    
    return [
        'stats' => $stats,
        'borrow_limit' => $can_borrow,
        'has_overdue' => $stats['overdue_count'] > 0,
        'has_reservations' => $stats['reservations_count'] > 0
    ];
}
?>