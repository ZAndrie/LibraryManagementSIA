<?php
/**
 * Enhanced Books API - Admin & Client Support
 * Handles all database operations for the library system
 * Returns JSON responses
 *
 * Architecture: UI/UX → API → DATABASE → API → OUTPUT
 */

// Gateway already sends headers; do not emit headers here to avoid duplication

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// API token authentication (optional): allow header X-API-KEY or query/post param api_key
$api_authenticated = false;
$api_user_id = 0;
// Try to read API key from headers or params
$api_key = '';
if (isset($_GET['api_key']) && $_GET['api_key'] !== '') {
    $api_key = $_GET['api_key'];
} elseif (isset($_POST['api_key']) && $_POST['api_key'] !== '') {
    $api_key = $_POST['api_key'];
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $api_key = $_SERVER['HTTP_X_API_KEY'];
}

if ($api_key !== '') {
    $expected = getSetting($conn, 'api_token', null);
    if ($expected && hash_equals($expected, $api_key)) {
        // Valid token — map to user id if provided in settings
        $uid = intval(getSetting($conn, 'api_token_user_id', 0));
        if ($uid > 0) {
            // Verify user exists and is admin
            $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                if ($row['role'] === 'admin') {
                    $api_authenticated = true;
                    $api_user_id = $uid;
                    // ensure session has user id for logging
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $_SESSION['user_id'] = $api_user_id;
                }
            }
        }
    }
}

// Deny direct access unless coming through the gateway or presenting a valid API token
if (!defined('API_GATEWAY') && !$api_authenticated) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Direct access denied. Use the API gateway.']);
    exit;
}

// API Response function
function sendResponse($success, $message, $data = null, $count = null) {
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($count !== null) {
        $response['count'] = $count;
    }
    
    echo json_encode($response);
    exit;
}

// ============================================
// GET REQUESTS
// ============================================
if ($method === 'GET') {
    switch ($action) {
        
        case 'get_all_books':
            // ============================================
            // GET ALL BOOKS - For admin book management
            // ============================================
            
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
            $offset = ($page - 1) * $limit;
            
            try {
                // Get total count
                $count_result = $conn->query("SELECT COUNT(*) as total FROM books");
                $total = $count_result->fetch_assoc()['total'];
                
                // Get books with pagination
                $sql = "SELECT * FROM books ORDER BY id DESC LIMIT ? OFFSET ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $limit, $offset);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $books = array();
                while ($row = $result->fetch_assoc()) {
                    $books[] = [
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'author' => $row['author'],
                        'isbn' => $row['isbn'],
                        'category' => $row['category'],
                        'published_year' => $row['published_year'],
                        'quantity' => $row['quantity'],
                        'available' => $row['available'],
                        'is_available' => $row['available'] > 0,
                        'created_at' => $row['created_at']
                    ];
                }
                
                sendResponse(true, "Retrieved " . count($books) . " books", [
                    'books' => $books,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => ceil($total / $limit),
                        'total_items' => $total,
                        'items_per_page' => $limit
                    ]
                ], count($books));
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
        
        case 'search':
            // ============================================
            // SEARCH BOOKS - Main search functionality
            // ============================================
            
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $title = isset($_GET['title']) ? trim($_GET['title']) : '';
            $author = isset($_GET['author']) ? trim($_GET['author']) : '';
            $category = isset($_GET['category']) ? $_GET['category'] : '';
            $isbn = isset($_GET['isbn']) ? trim($_GET['isbn']) : '';
            $year_from = isset($_GET['year_from']) ? $_GET['year_from'] : '';
            $year_to = isset($_GET['year_to']) ? $_GET['year_to'] : '';
            $availability = isset($_GET['availability']) ? $_GET['availability'] : '';
            
            // Build query
            $sql = "SELECT * FROM books WHERE 1=1";
            $params = array();
            $types = "";
            
            // Quick search
            if (!empty($search)) {
                $sql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR category LIKE ?)";
                $search_param = "%$search%";
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
                $types .= "ssss";
            }
            
            // Title filter
            if (!empty($title)) {
                $sql .= " AND title LIKE ?";
                $params[] = "%$title%";
                $types .= "s";
            }
            
            // Author filter
            if (!empty($author)) {
                $sql .= " AND author LIKE ?";
                $params[] = "%$author%";
                $types .= "s";
            }
            
            // Category filter
            if (!empty($category)) {
                $sql .= " AND category = ?";
                $params[] = $category;
                $types .= "s";
            }
            
            // ISBN filter
            if (!empty($isbn)) {
                $sql .= " AND isbn LIKE ?";
                $params[] = "%$isbn%";
                $types .= "s";
            }
            
            // Year range filter
            if (!empty($year_from)) {
                $sql .= " AND published_year >= ?";
                $params[] = $year_from;
                $types .= "i";
            }
            if (!empty($year_to)) {
                $sql .= " AND published_year <= ?";
                $params[] = $year_to;
                $types .= "i";
            }
            
            // Availability filter
            if ($availability == 'available') {
                $sql .= " AND available > 0";
            } elseif ($availability == 'unavailable') {
                $sql .= " AND available = 0";
            }
            
            $sql .= " ORDER BY title";
            
            try {
                if (!empty($params)) {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } else {
                    $result = $conn->query($sql);
                }
                
                $books = array();
                while ($row = $result->fetch_assoc()) {
                    $books[] = [
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'author' => $row['author'],
                        'isbn' => $row['isbn'],
                        'category' => $row['category'],
                        'published_year' => $row['published_year'],
                        'quantity' => $row['quantity'],
                        'available' => $row['available'],
                        'is_available' => $row['available'] > 0
                    ];
                }
                
                sendResponse(true, "Found " . count($books) . " books", $books, count($books));
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        case 'get_book':
            // ============================================
            // GET SINGLE BOOK BY ID
            // ============================================
            
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            
            if ($id <= 0) {
                sendResponse(false, "Invalid book ID");
            }
            
            try {
                $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $book = $result->fetch_assoc();
                    sendResponse(true, "Book found", $book);
                } else {
                    sendResponse(false, "Book not found");
                }
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        case 'get_categories':
            // ============================================
            // GET ALL CATEGORIES
            // ============================================
            
            try {
                $result = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category");
                
                $categories = array();
                while ($row = $result->fetch_assoc()) {
                    $categories[] = $row['category'];
                }
                
                sendResponse(true, "Categories retrieved", $categories, count($categories));
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        case 'get_stats':
            // ============================================
            // GET BOOK STATISTICS - For dashboard
            // ============================================
            
            try {
                $stats = [
                    'total_books' => 0,
                    'total_copies' => 0,
                    'available_copies' => 0,
                    'borrowed_copies' => 0,
                    'categories_count' => 0,
                    'utilization_rate' => 0
                ];
                
                // Total unique books
                $result = $conn->query("SELECT COUNT(*) as total FROM books");
                $stats['total_books'] = $result->fetch_assoc()['total'];
                
                // Total copies
                $result = $conn->query("SELECT SUM(quantity) as total FROM books");
                $stats['total_copies'] = $result->fetch_assoc()['total'] ?? 0;
                
                // Available copies
                $result = $conn->query("SELECT SUM(available) as total FROM books");
                $stats['available_copies'] = $result->fetch_assoc()['total'] ?? 0;
                
                // Borrowed copies
                $stats['borrowed_copies'] = $stats['total_copies'] - $stats['available_copies'];
                
                // Categories count
                $result = $conn->query("SELECT COUNT(DISTINCT category) as total FROM books WHERE category IS NOT NULL AND category != ''");
                $stats['categories_count'] = $result->fetch_assoc()['total'];
                
                // Utilization rate
                if ($stats['total_copies'] > 0) {
                    $stats['utilization_rate'] = round(($stats['borrowed_copies'] / $stats['total_copies']) * 100, 1);
                }
                
                sendResponse(true, "Statistics retrieved", $stats);
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        case 'check_availability':
            // ============================================
            // CHECK BOOK AVAILABILITY
            // ============================================
            
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            
            if ($id <= 0) {
                sendResponse(false, "Invalid book ID");
            }
            
            try {
                $stmt = $conn->prepare("SELECT title, available, quantity FROM books WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $book = $result->fetch_assoc();
                    $data = [
                        'title' => $book['title'],
                        'available' => $book['available'],
                        'total' => $book['quantity'],
                        'is_available' => $book['available'] > 0
                    ];
                    sendResponse(true, "Availability checked", $data);
                } else {
                    sendResponse(false, "Book not found");
                }
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        default:
            sendResponse(false, "Invalid action. Available GET actions: get_all_books, search, get_book, get_categories, get_stats, check_availability");
            break;
    }
}

// ============================================
// POST REQUESTS - Admin Operations
// ============================================
elseif ($method === 'POST') {
    
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        
        case 'delete_book':
            // ============================================
            // DELETE BOOK - Admin only
            // ============================================
            
            $id = isset($input['id']) ? intval($input['id']) : 0;
            
            if ($id <= 0) {
                sendResponse(false, "Invalid book ID");
            }
            
            try {
                // Get book title before deleting (for logging)
                $stmt = $conn->prepare("SELECT title FROM books WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    sendResponse(false, "Book not found");
                }
                
                $book = $result->fetch_assoc();
                $title = $book['title'];
                
                // Delete the book
                $stmt = $conn->prepare("DELETE FROM books WHERE id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log activity if user is logged in
                    if (isset($_SESSION['user_id'])) {
                        logActivitySafe($conn, $_SESSION['user_id'], 'DELETE_BOOK', "Deleted book: $title (ID: $id)");
                    }
                    
                    sendResponse(true, "Book deleted successfully", ['id' => $id, 'title' => $title]);
                } else {
                    sendResponse(false, "Failed to delete book");
                }
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        case 'update_book':
            // ============================================
            // UPDATE BOOK - Admin only
            // ============================================
            
            $id = isset($input['id']) ? intval($input['id']) : 0;
            $title = isset($input['title']) ? trim($input['title']) : '';
            $author = isset($input['author']) ? trim($input['author']) : '';
            $isbn = isset($input['isbn']) ? trim($input['isbn']) : '';
            $category = isset($input['category']) ? trim($input['category']) : '';
            $quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;
            $available = isset($input['available']) ? intval($input['available']) : 1;
            $published_year = isset($input['published_year']) ? intval($input['published_year']) : null;
            
            if ($id <= 0) {
                sendResponse(false, "Invalid book ID");
            }
            
            if (empty($title) || empty($author)) {
                sendResponse(false, "Title and author are required");
            }
            
            try {
                $sql = "UPDATE books SET title = ?, author = ?, isbn = ?, category = ?, 
                        quantity = ?, available = ?, published_year = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssiiii", $title, $author, $isbn, $category, $quantity, $available, $published_year, $id);
                
                if ($stmt->execute()) {
                    if (isset($_SESSION['user_id'])) {
                        logActivitySafe($conn, $_SESSION['user_id'], 'UPDATE_BOOK', "Updated book: $title (ID: $id)");
                    }
                    
                    sendResponse(true, "Book updated successfully", ['id' => $id]);
                } else {
                    sendResponse(false, "Failed to update book");
                }
                
            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        case 'add_book':
            // ============================================
            // ADD NEW BOOK - Admin only
            // ============================================
            
            $title = isset($input['title']) ? trim($input['title']) : '';
            $author = isset($input['author']) ? trim($input['author']) : '';
            $isbn = isset($input['isbn']) ? trim($input['isbn']) : '';
            $category = isset($input['category']) ? trim($input['category']) : '';
            $quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;
            $available = isset($input['available']) ? intval($input['available']) : 1;
            $published_year = isset($input['published_year']) ? intval($input['published_year']) : null;
            
            if (empty($title) || empty($author)) {
                sendResponse(false, "Title and author are required");
            }
            
            try {
                // If ISBN provided and matches an existing book, update quantity/available instead
                if (!empty($isbn)) {
                    $check = $conn->prepare("SELECT id, quantity, available FROM books WHERE isbn = ? LIMIT 1");
                    $check->bind_param("s", $isbn);
                    $check->execute();
                    $res = $check->get_result();
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $new_quantity = $row['quantity'] + $quantity;
                        $new_available = min($new_quantity, $row['available'] + $quantity);
                        $upd = $conn->prepare("UPDATE books SET quantity = ?, available = ? WHERE id = ?");
                        $upd->bind_param("iii", $new_quantity, $new_available, $row['id']);
                        if ($upd->execute()) {
                            if (isset($_SESSION['user_id'])) {
                                logActivitySafe($conn, $_SESSION['user_id'], 'UPDATE_BOOK', "Updated copies for book (ISBN: $isbn) - added $quantity copies (ID: {$row['id']})");
                            }
                            sendResponse(true, "Book copies updated", ['id' => $row['id'], 'quantity' => $new_quantity, 'available' => $new_available]);
                        } else {
                            sendResponse(false, "Failed to update existing book copies");
                        }
                    }
                }

                // If no ISBN match, try to find by title+author (case-insensitive)
                $found_id = null;
                if (empty($isbn)) {
                    $t = mb_strtolower($title);
                    $a = mb_strtolower($author);
                    $stmt_find = $conn->prepare("SELECT id, quantity, available FROM books WHERE LOWER(title) = ? AND LOWER(author) = ? LIMIT 1");
                    $stmt_find->bind_param("ss", $t, $a);
                    $stmt_find->execute();
                    $rf = $stmt_find->get_result();
                    if ($rf && $rf->num_rows > 0) {
                        $r = $rf->fetch_assoc();
                        $found_id = $r['id'];
                        $new_quantity = $r['quantity'] + $quantity;
                        $new_available = min($new_quantity, $r['available'] + $quantity);
                        $upd2 = $conn->prepare("UPDATE books SET quantity = ?, available = ? WHERE id = ?");
                        $upd2->bind_param("iii", $new_quantity, $new_available, $found_id);
                        if ($upd2->execute()) {
                            if (isset($_SESSION['user_id'])) {
                                logActivitySafe($conn, $_SESSION['user_id'], 'UPDATE_BOOK', "Updated copies for book '{$title}' by {$author} - added $quantity copies (ID: {$found_id})");
                            }
                            sendResponse(true, "Book copies updated", ['id' => $found_id, 'quantity' => $new_quantity, 'available' => $new_available]);
                        } else {
                            sendResponse(false, "Failed to update existing book copies");
                        }
                    }
                }

                // Otherwise insert a new book
                $sql = "INSERT INTO books (title, author, isbn, category, quantity, available, published_year) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssiis", $title, $author, $isbn, $category, $quantity, $available, $published_year);

                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;

                    if (isset($_SESSION['user_id'])) {
                        logActivitySafe($conn, $_SESSION['user_id'], 'CREATE_BOOK', "Added new book: $title (ID: $new_id)");
                    }

                    sendResponse(true, "Book added successfully", ['id' => $new_id]);
                } else {
                    sendResponse(false, "Failed to add book");
                }

            } catch (Exception $e) {
                sendResponse(false, "Database error: " . $e->getMessage());
            }
            break;
            
        default:
            sendResponse(false, "Invalid action. Available POST actions: delete_book, update_book, add_book");
            break;
    }
}

else {
    sendResponse(false, "Only GET and POST requests are supported");
}
?>