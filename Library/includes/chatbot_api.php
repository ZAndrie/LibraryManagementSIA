<?php
/**
 * ADMIN Library Chatbot - Complete System Control & Analytics
 * Full access to ALL data, statistics, and administrative functions
 */

// Deny direct access; allow only via API gateway
if (!defined('API_GATEWAY')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Direct access denied. Use the API gateway.']);
    exit;
}

// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Gateway already sets CORS and Content-Type; avoid duplicate headers here

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

// ============================================
// GEMINI API KEY
// ============================================
define('GEMINI_API_KEY', 'AIzaSyBnfjjszhUOty19EFMUr_KumJKQ35L6Zyw');

// ============================================
// HELPER FUNCTIONS
// ============================================
function sendResponse($success, $message, $data = null) {
    ob_clean();
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getConversationHistory() {
    if (!isset($_SESSION['chat_history_admin'])) {
        $_SESSION['chat_history_admin'] = [];
    }
    return $_SESSION['chat_history_admin'];
}

function addToHistory($user_msg, $bot_msg) {
    if (!isset($_SESSION['chat_history_admin'])) {
        $_SESSION['chat_history_admin'] = [];
    }
    
    $_SESSION['chat_history_admin'][] = [
        'user' => $user_msg,
        'bot' => $bot_msg,
        'timestamp' => time()
    ];
    
    // Keep last 8 exchanges for admin (more context)
    if (count($_SESSION['chat_history_admin']) > 8) {
        $_SESSION['chat_history_admin'] = array_slice($_SESSION['chat_history_admin'], -8);
    }
}

// ============================================
// GET COMPLETE ADMIN SYSTEM DATA
// This retrieves EVERYTHING from the database
// ============================================
function getAdminSystemData($conn) {
    $system_data = [];
    
    // ========== COMPLETE BOOKS DATA ==========
    $books_query = "SELECT * FROM books ORDER BY title";
    $result = $conn->query($books_query);
    
    if (!$result) {
        return ['success' => false, 'error' => 'Books query failed: ' . $conn->error];
    }
    
    $system_data['books'] = [];
    $system_data['total_books'] = 0;
    $system_data['available_books'] = 0;
    $system_data['borrowed_books'] = 0;
    
    while ($row = $result->fetch_assoc()) {
        $available = (int)($row['available'] ?? 0);
        $total = (int)($row['quantity'] ?? 1);
        $borrowed = $total - $available;
        
        $book = [
            'id' => $row['id'],
            'title' => $row['title'],
            'author' => $row['author'],
            'isbn' => $row['isbn'] ?? 'N/A',
            'category' => $row['category'] ?? 'General',
            'published_year' => $row['published_year'] ?? 'N/A',
            'tags' => $row['tags'] ?? '',
            'total_quantity' => $total,
            'available' => $available,
            'borrowed' => $borrowed,
            'status' => $available > 0 ? 'Available' : 'All Borrowed',
            'added_date' => $row['created_at'] ?? 'N/A'
        ];
        
        $system_data['books'][] = $book;
        $system_data['total_books']++;
        $system_data['available_books'] += $available;
        $system_data['borrowed_books'] += $borrowed;
    }
    
    // ========== CATEGORIES ANALYSIS ==========
    $categories_query = "SELECT category, COUNT(*) as count FROM books GROUP BY category ORDER BY count DESC";
    $result = $conn->query($categories_query);
    
    $system_data['categories'] = [];
    while ($row = $result->fetch_assoc()) {
        $system_data['categories'][] = [
            'name' => $row['category'] ?? 'General',
            'count' => $row['count']
        ];
    }
    
    // ========== AUTHORS ANALYSIS ==========
    $authors_query = "SELECT author, COUNT(*) as book_count FROM books GROUP BY author ORDER BY book_count DESC";
    $result = $conn->query($authors_query);
    
    $system_data['authors'] = [];
    while ($row = $result->fetch_assoc()) {
        $system_data['authors'][] = [
            'name' => $row['author'],
            'book_count' => $row['book_count']
        ];
    }
    
    // ========== COMPLETE PATRONS DATA (With Contact Info) ==========
    $patrons_query = "SELECT * FROM patrons ORDER BY created_at DESC";
    $result = $conn->query($patrons_query);
    
    $system_data['patrons'] = [];
    $system_data['total_patrons'] = 0;
    
    while ($row = $result->fetch_assoc()) {
        $system_data['patrons'][] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'] ?? 'N/A',
            'address' => $row['address'] ?? 'N/A',
            'joined_date' => $row['created_at'] ?? 'N/A'
        ];
        $system_data['total_patrons']++;
    }
    
    // ========== ALL BORROWINGS (Complete History) ==========
    $borrowings_query = "
        SELECT 
            br.id as borrowing_id,
            br.borrow_date,
            br.due_date,
            br.return_date,
            br.status,
            b.id as book_id,
            b.title,
            b.author,
            b.isbn,
            b.category,
            p.id as patron_id,
            p.name as patron_name,
            p.email as patron_email,
            p.phone as patron_phone
        FROM borrowings br
        JOIN books b ON br.book_id = b.id
        JOIN patrons p ON br.patron_id = p.id
        ORDER BY br.borrow_date DESC
    ";
    $result = $conn->query($borrowings_query);
    
    $system_data['all_borrowings'] = [];
    $system_data['active_borrowings'] = [];
    $system_data['overdue_borrowings'] = [];
    $system_data['returned_borrowings'] = [];
    
    while ($row = $result->fetch_assoc()) {
        $borrowing = [
            'borrowing_id' => $row['borrowing_id'],
            'book_id' => $row['book_id'],
            'book_title' => $row['title'],
            'author' => $row['author'],
            'isbn' => $row['isbn'],
            'category' => $row['category'],
            'patron_id' => $row['patron_id'],
            'patron_name' => $row['patron_name'],
            'patron_email' => $row['patron_email'],
            'patron_phone' => $row['patron_phone'] ?? 'N/A',
            'borrow_date' => $row['borrow_date'],
            'due_date' => $row['due_date'],
            'return_date' => $row['return_date'] ?? 'Not yet returned',
            'status' => $row['status']
        ];
        
        // Calculate days overdue if applicable
        if ($row['status'] === 'overdue') {
            $days_overdue = floor((strtotime('now') - strtotime($row['due_date'])) / (60 * 60 * 24));
            $borrowing['days_overdue'] = $days_overdue;
            $system_data['overdue_borrowings'][] = $borrowing;
        }
        
        if (in_array($row['status'], ['borrowed', 'overdue'])) {
            $system_data['active_borrowings'][] = $borrowing;
        }
        
        if ($row['status'] === 'returned') {
            $system_data['returned_borrowings'][] = $borrowing;
        }
        
        $system_data['all_borrowings'][] = $borrowing;
    }
    
    // ========== BORROWING STATISTICS ==========
    $borrow_stats_query = "
        SELECT 
            COUNT(*) as total_borrowings,
            COUNT(CASE WHEN status = 'borrowed' THEN 1 END) as currently_borrowed,
            COUNT(CASE WHEN status = 'returned' THEN 1 END) as total_returned,
            COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count
        FROM borrowings
    ";
    $result = $conn->query($borrow_stats_query);
    $system_data['borrowing_stats'] = $result->fetch_assoc();
    
    // ========== COMPLETE FINES DATA ==========
    $fines_query = "
        SELECT 
            f.*,
            p.name as patron_name,
            p.email as patron_email,
            p.phone as patron_phone,
            b.title as book_title,
            b.author as book_author,
            br.borrow_date,
            br.due_date,
            br.return_date
        FROM fines f
        JOIN patrons p ON f.patron_id = p.id
        JOIN borrowings br ON f.borrowing_id = br.id
        JOIN books b ON br.book_id = b.id
        ORDER BY f.created_at DESC
    ";
    $result = $conn->query($fines_query);
    
    $system_data['all_fines'] = [];
    $system_data['unpaid_fines'] = [];
    $system_data['paid_fines'] = [];
    $system_data['total_unpaid_amount'] = 0;
    $system_data['total_paid_amount'] = 0;
    $system_data['total_fines_amount'] = 0;
    
    while ($row = $result->fetch_assoc()) {
        $fine = [
            'fine_id' => $row['id'],
            'patron_name' => $row['patron_name'],
            'patron_email' => $row['patron_email'],
            'patron_phone' => $row['patron_phone'] ?? 'N/A',
            'book_title' => $row['book_title'],
            'book_author' => $row['book_author'],
            'amount' => $row['amount'],
            'days_overdue' => $row['days_overdue'],
            'reason' => $row['reason'],
            'status' => $row['status'],
            'borrow_date' => $row['borrow_date'],
            'due_date' => $row['due_date'],
            'return_date' => $row['return_date'] ?? 'N/A',
            'fine_date' => $row['created_at']
        ];
        
        $amount = floatval($row['amount']);
        $system_data['total_fines_amount'] += $amount;
        
        if ($row['status'] === 'unpaid') {
            $system_data['unpaid_fines'][] = $fine;
            $system_data['total_unpaid_amount'] += $amount;
        } else {
            $system_data['paid_fines'][] = $fine;
            $system_data['total_paid_amount'] += $amount;
        }
        
        $system_data['all_fines'][] = $fine;
    }
    
    // ========== SYSTEM SETTINGS ==========
    $settings_query = "SELECT * FROM system_settings";
    $result = $conn->query($settings_query);
    
    $system_data['settings'] = [];
    while ($row = $result->fetch_assoc()) {
        $system_data['settings'][$row['setting_key']] = [
            'value' => $row['setting_value'],
            'description' => $row['description'] ?? ''
        ];
    }
    
    // ========== USERS/ADMIN DATA ==========
    $users_query = "SELECT id, full_name, username, email, role, created_at FROM users ORDER BY created_at DESC";
    $result = $conn->query($users_query);
    
    $system_data['users'] = [];
    $system_data['admin_count'] = 0;
    $system_data['client_count'] = 0;
    
    while ($row = $result->fetch_assoc()) {
        $user = [
            'id' => $row['id'],
            'full_name' => $row['full_name'],
            'username' => $row['username'],
            'email' => $row['email'],
            'role' => $row['role'],
            'created_at' => $row['created_at']
        ];
        
        $system_data['users'][] = $user;
        
        if ($row['role'] === 'admin') {
            $system_data['admin_count']++;
        } else {
            $system_data['client_count']++;
        }
    }
    
    // ========== ACTIVITY LOGS ==========
    $activity_query = "
        SELECT 
            al.*,
            u.full_name as user_name,
            u.role as user_role
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 20
    ";
    $result = $conn->query($activity_query);
    
    $system_data['recent_activities'] = [];
    while ($row = $result->fetch_assoc()) {
        $system_data['recent_activities'][] = [
            'action' => $row['action'],
            'description' => $row['description'],
            'user_name' => $row['user_name'] ?? 'System',
            'user_role' => $row['user_role'] ?? 'system',
            'timestamp' => $row['created_at']
        ];
    }
    
    // ========== MONTHLY STATISTICS ==========
    $monthly_borrows_query = "
        SELECT 
            DATE_FORMAT(borrow_date, '%Y-%m') as month,
            COUNT(*) as total_borrows
        FROM borrowings
        WHERE borrow_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month
        ORDER BY month DESC
    ";
    $result = $conn->query($monthly_borrows_query);
    
    $system_data['monthly_statistics'] = [];
    while ($row = $result->fetch_assoc()) {
        $system_data['monthly_statistics'][] = [
            'month' => $row['month'],
            'total_borrows' => $row['total_borrows']
        ];
    }
    
    // ========== TOP BORROWED BOOKS ==========
    $top_books_query = "
        SELECT 
            b.title,
            b.author,
            b.category,
            COUNT(*) as borrow_count
        FROM borrowings br
        JOIN books b ON br.book_id = b.id
        GROUP BY br.book_id
        ORDER BY borrow_count DESC
        LIMIT 10
    ";
    $result = $conn->query($top_books_query);
    
    $system_data['top_borrowed_books'] = [];
    while ($row = $result->fetch_assoc()) {
        $system_data['top_borrowed_books'][] = [
            'title' => $row['title'],
            'author' => $row['author'],
            'category' => $row['category'],
            'times_borrowed' => $row['borrow_count']
        ];
    }
    
    // ========== MOST ACTIVE PATRONS ==========
    $top_patrons_query = "
        SELECT 
            p.name,
            p.email,
            p.phone,
            COUNT(*) as borrow_count
        FROM borrowings br
        JOIN patrons p ON br.patron_id = p.id
        GROUP BY br.patron_id
        ORDER BY borrow_count DESC
        LIMIT 10
    ";
    $result = $conn->query($top_patrons_query);
    
    $system_data['most_active_patrons'] = [];
    while ($row = $result->fetch_assoc()) {
        $system_data['most_active_patrons'][] = [
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'] ?? 'N/A',
            'total_borrows' => $row['borrow_count']
        ];
    }
    
    return ['success' => true, 'data' => $system_data];
}

// ============================================
// BUILD COMPREHENSIVE ADMIN PROMPT
// ============================================
function buildAdminPrompt($user_message, $system_data, $conversation_history) {
    // Format conversation history
    $history_text = "";
    if (!empty($conversation_history)) {
        $recent_history = array_slice($conversation_history, -4);
        $history_text = "\n📜 CONVERSATION HISTORY:\n";
        foreach ($recent_history as $exchange) {
            $history_text .= "Admin: {$exchange['user']}\n";
            $history_text .= "You: {$exchange['bot']}\n\n";
        }
    }
    
    // ========== COMPLETE BOOKS DATABASE ==========
    $books_database = "\n📚 COMPLETE BOOK DATABASE ({$system_data['total_books']} books):\n";
    $books_database .= str_repeat("=", 100) . "\n\n";
    
    foreach ($system_data['books'] as $index => $book) {
        $num = $index + 1;
        $books_database .= "BOOK #{$num} [ID: {$book['id']}]:\n";
        $books_database .= "  📖 Title: {$book['title']}\n";
        $books_database .= "  ✍️ Author: {$book['author']}\n";
        $books_database .= "  📢 ISBN: {$book['isbn']}\n";
        $books_database .= "  📂 Category: {$book['category']}\n";
        $books_database .= "  📅 Published Year: {$book['published_year']}\n";
        $books_database .= "  🏷️ Tags: {$book['tags']}\n";
        $books_database .= "  📊 Total Quantity: {$book['total_quantity']} copies\n";
        $books_database .= "  ✅ Available: {$book['available']} copies\n";
        $books_database .= "  📤 Currently Borrowed: {$book['borrowed']} copies\n";
        $books_database .= "  📖 Status: {$book['status']}\n";
        $books_database .= "  📆 Added: {$book['added_date']}\n";
        $books_database .= str_repeat("-", 100) . "\n\n";
    }
    
    // ========== CATEGORIES & AUTHORS ==========
    $categories_text = "\n📂 BOOK CATEGORIES:\n";
    foreach ($system_data['categories'] as $cat) {
        $categories_text .= "  • {$cat['name']}: {$cat['count']} books\n";
    }
    
    $authors_text = "\n✍️ AUTHORS:\n";
    foreach ($system_data['authors'] as $author) {
        $authors_text .= "  • {$author['name']}: {$author['book_count']} book(s)\n";
    }
    
    // ========== COMPLETE PATRONS LIST ==========
    $patrons_text = "\n👥 ALL REGISTERED PATRONS ({$system_data['total_patrons']} members):\n";
    foreach (array_slice($system_data['patrons'], 0, 15) as $patron) {
        $patrons_text .= "  • {$patron['name']} [ID: {$patron['id']}]\n";
        $patrons_text .= "    Email: {$patron['email']}, Phone: {$patron['phone']}\n";
        $patrons_text .= "    Joined: {$patron['joined_date']}\n\n";
    }
    if (count($system_data['patrons']) > 15) {
        $patrons_text .= "  ... and " . (count($system_data['patrons']) - 15) . " more patrons\n";
    }
    
    // ========== ACTIVE BORROWINGS ==========
    $active_text = "\n📤 CURRENTLY BORROWED BOOKS ({$system_data['borrowing_stats']['currently_borrowed']}):\n";
    foreach (array_slice($system_data['active_borrowings'], 0, 10) as $borrow) {
        $status_icon = $borrow['status'] === 'overdue' ? '⚠️' : '📖';
        $active_text .= "  {$status_icon} \"{$borrow['book_title']}\" by {$borrow['author']}\n";
        $active_text .= "     Borrower: {$borrow['patron_name']} ({$borrow['patron_email']})\n";
        $active_text .= "     Borrow Date: {$borrow['borrow_date']}\n";
        $active_text .= "     Due Date: {$borrow['due_date']}\n";
        $active_text .= "     Status: {$borrow['status']}\n";
        if (isset($borrow['days_overdue'])) {
            $active_text .= "     ⚠️ Days Overdue: {$borrow['days_overdue']} days\n";
        }
        $active_text .= "\n";
    }
    
    // ========== OVERDUE BOOKS ==========
    $overdue_text = "";
    if (!empty($system_data['overdue_borrowings'])) {
        $overdue_text = "\n⚠️ OVERDUE BOOKS (" . count($system_data['overdue_borrowings']) . "):\n";
        foreach ($system_data['overdue_borrowings'] as $overdue) {
            $overdue_text .= "  🚨 \"{$overdue['book_title']}\" by {$overdue['author']}\n";
            $overdue_text .= "     Borrower: {$overdue['patron_name']} ({$overdue['patron_email']}, {$overdue['patron_phone']})\n";
            $overdue_text .= "     Due Date: {$overdue['due_date']}\n";
            $overdue_text .= "     Days Overdue: {$overdue['days_overdue']} days\n\n";
        }
    }
    
    // ========== FINES INFORMATION ==========
    $fines_text = "\n💰 FINES MANAGEMENT:\n";
    $fines_text .= "  💵 Total Unpaid Fines: ₱" . number_format($system_data['total_unpaid_amount'], 2) . "\n";
    $fines_text .= "  ✅ Total Paid Fines: ₱" . number_format($system_data['total_paid_amount'], 2) . "\n";
    $fines_text .= "  📊 Total Fines (All Time): ₱" . number_format($system_data['total_fines_amount'], 2) . "\n";
    $fines_text .= "  🚨 Unpaid Fine Records: " . count($system_data['unpaid_fines']) . "\n";
    
    if (!empty($system_data['unpaid_fines'])) {
        $fines_text .= "\n  ⚠️ UNPAID FINES DETAILS:\n";
        foreach (array_slice($system_data['unpaid_fines'], 0, 10) as $fine) {
            $fines_text .= "    • {$fine['patron_name']} - \"{$fine['book_title']}\"\n";
            $fines_text .= "      Amount: ₱{$fine['amount']}, Days Overdue: {$fine['days_overdue']}\n";
            $fines_text .= "      Contact: {$fine['patron_email']}, {$fine['patron_phone']}\n\n";
        }
    }
    
    // ========== SYSTEM STATISTICS ==========
    $stats_text = "\n📊 COMPLETE LIBRARY STATISTICS:\n";
    $stats_text .= "  📚 Total Books: {$system_data['total_books']}\n";
    $stats_text .= "  ✅ Available Copies: {$system_data['available_books']}\n";
    $stats_text .= "  📤 Currently Borrowed: {$system_data['borrowed_books']}\n";
    $stats_text .= "  📂 Total Categories: " . count($system_data['categories']) . "\n";
    $stats_text .= "  ✍️ Total Authors: " . count($system_data['authors']) . "\n";
    $stats_text .= "  👥 Total Patrons: {$system_data['total_patrons']}\n";
    $stats_text .= "  👨‍💼 Admin Users: {$system_data['admin_count']}\n";
    $stats_text .= "  👤 Client Users: {$system_data['client_count']}\n";
    $stats_text .= "  📖 Total Borrowings (All Time): {$system_data['borrowing_stats']['total_borrowings']}\n";
    $stats_text .= "  📤 Currently Borrowed: {$system_data['borrowing_stats']['currently_borrowed']}\n";
    $stats_text .= "  ✅ Total Returned: {$system_data['borrowing_stats']['total_returned']}\n";
    $stats_text .= "  ⚠️ Overdue Books: {$system_data['borrowing_stats']['overdue_count']}\n";
    
    // ========== TOP BORROWED BOOKS ==========
    $top_books_text = "\n🏆 TOP 10 MOST BORROWED BOOKS:\n";
    foreach ($system_data['top_borrowed_books'] as $index => $book) {
        $rank = $index + 1;
        $top_books_text .= "  {$rank}. \"{$book['title']}\" by {$book['author']}\n";
        $top_books_text .= "     Category: {$book['category']}, Times Borrowed: {$book['times_borrowed']}\n";
    }
    
    // ========== MOST ACTIVE PATRONS ==========
    $top_patrons_text = "\n👑 TOP 10 MOST ACTIVE PATRONS:\n";
    foreach ($system_data['most_active_patrons'] as $index => $patron) {
        $rank = $index + 1;
        $top_patrons_text .= "  {$rank}. {$patron['name']}\n";
        $top_patrons_text .= "     Email: {$patron['email']}, Phone: {$patron['phone']}\n";
        $top_patrons_text .= "     Total Borrows: {$patron['total_borrows']}\n";
    }
    
    // ========== SYSTEM SETTINGS ==========
    $settings_text = "\n⚙️ LIBRARY POLICIES & SETTINGS:\n";
    if (isset($system_data['settings']['default_borrow_days'])) {
        $settings_text .= "  📅 Borrowing Period: {$system_data['settings']['default_borrow_days']['value']} days\n";
    }
    if (isset($system_data['settings']['fine_per_day'])) {
        $settings_text .= "  💰 Late Fee: ₱{$system_data['settings']['fine_per_day']['value']} per day\n";
    }
    if (isset($system_data['settings']['max_fine_amount'])) {
        $settings_text .= "  💵 Maximum Fine: ₱{$system_data['settings']['max_fine_amount']['value']}\n";
    }
    
    // ========== RECENT ACTIVITIES ==========
    $activities_text = "\n📋 RECENT SYSTEM ACTIVITIES:\n";
    foreach (array_slice($system_data['recent_activities'], 0, 10) as $activity) {
        $activities_text .= "  • [{$activity['timestamp']}] {$activity['action']}\n";
        $activities_text .= "    User: {$activity['user_name']} ({$activity['user_role']})\n";
        $activities_text .= "    {$activity['description']}\n\n";
    }
    
    // ========== MONTHLY STATISTICS ==========
    $monthly_text = "\n📈 MONTHLY BORROWING TRENDS (Last 6 Months):\n";
    foreach ($system_data['monthly_statistics'] as $month) {
        $monthly_text .= "  • {$month['month']}: {$month['total_borrows']} borrows\n";
    }
    
    // ========== BUILD COMPLETE ADMIN PROMPT ==========
    $prompt = "You are an ADVANCED ADMINISTRATIVE AI assistant for a Library Management System with COMPLETE ACCESS to all data, statistics, and records.

🎯 YOUR ROLE: Provide comprehensive, detailed, and accurate information to administrators. You have access to EVERYTHING.

{$stats_text}
{$settings_text}
{$categories_text}
{$authors_text}
{$top_books_text}
{$top_patrons_text}
{$monthly_text}
{$books_database}
{$patrons_text}
{$active_text}
{$overdue_text}
{$fines_text}
{$activities_text}
{$history_text}

🗣️ ADMIN QUESTION: {$user_message}

📝 ADMIN RESPONSE GUIDELINES:

1. **COMPREHENSIVE INFORMATION**:
   - Provide detailed statistics, analytics, and insights
   - Include specific numbers, dates, and identifiers (IDs, emails, phones)
   - Give actionable recommendations when relevant

2. **BOOK QUERIES**:
   - Show complete book details including IDs, ISBN, quantities
   - Mention borrowing history if relevant
   - Suggest actions (restock, remove, etc.)

3. **PATRON QUERIES**:
   - Show full patron details with contact information
   - Include borrowing history and any fines
   - Alert about overdue books or unpaid fines

4. **BORROWING & OVERDUE QUERIES**:
   - List ALL overdue books with borrower contact details
   - Calculate overdue days and fines
   - Provide patron contact info for follow-up

5. **FINES QUERIES**:
   - Show total amounts (unpaid, paid, all time)
   - List specific patrons with unpaid fines
   - Include contact information for collections

6. **STATISTICS & ANALYTICS**:
   - Provide comprehensive data analysis
   - Compare trends over time
   - Identify patterns (most borrowed books, active patrons)
   - Give actionable insights

7. **SYSTEM MANAGEMENT**:
   - Explain current settings and policies
   - Reference activity logs when relevant
   - Suggest improvements based on data

8. **USER/ADMIN QUERIES**:
   - Show user account information
   - Reference roles and permissions
   - Discuss system access levels

9. **FORMATTING**:
   - Use emojis for visual clarity: 📚 ✅ ❌ 🔍 📖 ✍️ 📊 💰 ⚠️ 🚨 👥 📈
   - Use **bold** for important items (names, titles, amounts)
   - Use bullet points for lists
   - Include tables when showing multiple records
   - Be professional but friendly

10. **EXAMPLE RESPONSES**:

Admin: \"Show me all overdue books\"
You: \"🚨 OVERDUE BOOKS REPORT

We currently have {count} overdue books:

**1. \"[Title]\" by [Author]**
   • Borrower: [Name]
   • Contact: [Email], [Phone]
   • Due Date: [Date]
   • Days Overdue: X days
   • Estimated Fine: ₱XXX

**2. [Next book]...**

💡 Recommendation: Contact these patrons immediately for book return and fine collection.\"

Admin: \"What are our top borrowed books?\"
You: \"🏆 TOP 10 MOST BORROWED BOOKS

[List with borrowing counts, categories, current availability]

📊 Insights:
- Fiction dominates with X% of top books
- [Author] is most popular
- Consider ordering more copies of [Title]\"

Admin: \"Who owes fines?\"
You: \"💰 UNPAID FINES REPORT

Total Outstanding: ₱X,XXX.XX
Number of Patrons: X

**TOP AMOUNTS:**
1. [Name] - ₱XXX.XX ([Book Title], X days overdue)
   Contact: [Email], [Phone]
2. [Next patron]...

📋 Recommendation: Send payment reminders to these patrons.\"

11. **CONVERSATION CONTEXT**:
    - Remember previous admin questions
    - Reference earlier data if user asks follow-up questions
    - Be proactive in suggesting related information

12. **SECURITY & PRIVACY**:
    - You're speaking to an ADMIN who has authorization to see all data
    - Provide full contact details and sensitive information as needed
    - Include IDs, emails, phone numbers for administrative purposes

NOW RESPOND TO THE ADMIN'S QUESTION WITH COMPLETE ACCURACY AND DETAIL:";

    return $prompt;
}

// ============================================
// GEMINI API CALL
// ============================================
function callGeminiAPI($prompt) {
    $api_key = GEMINI_API_KEY;
    
    if (empty($api_key) || $api_key === 'YOUR_GEMINI_API_KEY_HERE') {
        return [
            'success' => false,
            'error' => 'API key not configured. Please set your Gemini API key.'
        ];
    }
    
    // Use gemini-2.5-flash for fast, accurate responses
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$api_key}";
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.3, // Lower temperature for more accurate admin data
            'maxOutputTokens' => 4096, // Higher token limit for detailed responses
            'topP' => 0.8,
            'topK' => 40
        ],
        'safetySettings' => [
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => 'Connection error: ' . $error
        ];
    }
    
    if ($http_code !== 200) {
        $error_data = json_decode($response, true);
        $error_message = 'API Error (HTTP ' . $http_code . ')';
        
        if (isset($error_data['error']['message'])) {
            $error_message = $error_data['error']['message'];
        } elseif (isset($error_data['error']['status'])) {
            $error_message = $error_data['error']['status'];
        }
        
        return [
            'success' => false,
            'error' => $error_message,
            'http_code' => $http_code
        ];
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response: ' . json_last_error_msg()
        ];
    }
    
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return [
            'success' => true,
            'response' => trim($result['candidates'][0]['content']['parts'][0]['text'])
        ];
    }
    
    if (isset($result['candidates'][0]['finishReason'])) {
        $reason = $result['candidates'][0]['finishReason'];
        if ($reason !== 'STOP') {
            return [
                'success' => false,
                'error' => 'Response blocked: ' . $reason
            ];
        }
    }
    
    return [
        'success' => false,
        'error' => 'Unexpected API response format'
    ];
}

// ============================================
// MAIN REQUEST HANDLER
// ============================================

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    if ($method !== 'POST') {
        sendResponse(false, "Only POST requests allowed");
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, "Invalid JSON input");
    }
    
    $user_message = isset($input['message']) ? trim($input['message']) : '';
    $action = isset($input['action']) ? $input['action'] : null;
    
    // Handle clear chat
    if ($action === 'clear') {
        $_SESSION['chat_history_admin'] = [];
        sendResponse(true, "Chat cleared", [
            'response' => 'Admin chat cleared! 🔄 What would you like to know about the library system? 📊'
        ]);
    }
    
    // Validate message
    if (empty($user_message)) {
        sendResponse(false, "Message cannot be empty");
    }
    
    // Get ALL system data
    $data_result = getAdminSystemData($conn);
    
    if (!$data_result['success']) {
        sendResponse(false, "Database error", [
            'response' => "I'm having trouble accessing the database right now. 😕\n\nError: {$data_result['error']}\n\nPlease check the database connection."
        ]);
    }
    
    $system_data = $data_result['data'];
    
    // Get conversation history
    $conversation_history = getConversationHistory();
    
    // Build comprehensive admin prompt
    $prompt = buildAdminPrompt($user_message, $system_data, $conversation_history);
    
    // Call Gemini API
    $ai_result = callGeminiAPI($prompt);
    
    // Handle API errors
    if (!$ai_result['success']) {
        $error_response = "I'm having trouble connecting to my AI service right now. 😅\n\n";
        $error_response .= "Error: {$ai_result['error']}\n\n";
        $error_response .= "📊 Quick Stats:\n";
        $error_response .= "• Total Books: {$system_data['total_books']}\n";
        $error_response .= "• Available: {$system_data['available_books']}\n";
        $error_response .= "• Currently Borrowed: {$system_data['borrowed_books']}\n";
        $error_response .= "• Overdue: {$system_data['borrowing_stats']['overdue_count']}\n";
        $error_response .= "• Total Patrons: {$system_data['total_patrons']}\n\n";
        $error_response .= "Please try again in a moment.";
        
        sendResponse(true, "Error response", [
            'response' => $error_response,
            'error_details' => $ai_result
        ]);
    }
    
    // Get AI response
    $bot_response = $ai_result['response'];
    
    // Add to conversation history
    addToHistory($user_message, $bot_response);
    
    // Send successful response with comprehensive stats
    sendResponse(true, "Admin response generated successfully", [
        'response' => $bot_response,
        'system_stats' => [
            'total_books' => $system_data['total_books'],
            'available' => $system_data['available_books'],
            'borrowed' => $system_data['borrowed_books'],
            'total_patrons' => $system_data['total_patrons'],
            'active_borrowings' => $system_data['borrowing_stats']['currently_borrowed'],
            'overdue_count' => $system_data['borrowing_stats']['overdue_count'],
            'unpaid_fines' => $system_data['total_unpaid_amount'],
            'total_fines_collected' => $system_data['total_fines_amount'],
            'admin_count' => $system_data['admin_count'],
            'client_count' => $system_data['client_count']
        ]
    ]);
    
} catch (Exception $e) {
    sendResponse(false, "Server error: " . $e->getMessage());
}
?>