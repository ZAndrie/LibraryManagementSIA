<?php
/**
 * Client Library Chatbot - Book Search Only
 * Working version with correct Gemini API model
 *
 * Only accessible via API gateway
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
    if (!isset($_SESSION['chat_history_client'])) {
        $_SESSION['chat_history_client'] = [];
    }
    return $_SESSION['chat_history_client'];
}

function addToHistory($user_msg, $bot_msg) {
    if (!isset($_SESSION['chat_history_client'])) {
        $_SESSION['chat_history_client'] = [];
    }
    
    $_SESSION['chat_history_client'][] = [
        'user' => $user_msg,
        'bot' => $bot_msg,
        'timestamp' => time()
    ];
    
    // Keep last 6 exchanges
    if (count($_SESSION['chat_history_client']) > 6) {
        $_SESSION['chat_history_client'] = array_slice($_SESSION['chat_history_client'], -6);
    }
}

// ============================================
// GET BOOKS DATA (CLIENT VIEW ONLY)
// ============================================
function getClientBooksData($conn) {
    $books_data = [];
    
    $query = "SELECT id, title, author, isbn, category, published_year, tags, quantity, available, created_at 
              FROM books 
              ORDER BY title";
    
    $result = $conn->query($query);
    
    if (!$result) {
        return [
            'success' => false,
            'error' => 'Database query failed: ' . $conn->error
        ];
    }
    
    $books_data['books'] = [];
    $books_data['total_books'] = 0;
    $books_data['available_books'] = 0;
    $books_data['categories'] = [];
    $books_data['authors'] = [];
    
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
        
        $books_data['books'][] = $book;
        $books_data['total_books']++;
        $books_data['available_books'] += $available;
        
        // Count categories
        $cat = $book['category'];
        if (!isset($books_data['categories'][$cat])) {
            $books_data['categories'][$cat] = 0;
        }
        $books_data['categories'][$cat]++;
        
        // Count authors
        $author = $book['author'];
        if (!isset($books_data['authors'][$author])) {
            $books_data['authors'][$author] = 0;
        }
        $books_data['authors'][$author]++;
    }
    
    return [
        'success' => true,
        'data' => $books_data
    ];
}

// ============================================
// BUILD CLIENT PROMPT
// ============================================
function buildClientPrompt($user_message, $books_data, $conversation_history) {
    // Format conversation history
    $history_text = "";
    if (!empty($conversation_history)) {
        $recent_history = array_slice($conversation_history, -3);
        $history_text = "\n📜 RECENT CONVERSATION:\n";
        foreach ($recent_history as $exchange) {
            $history_text .= "User: {$exchange['user']}\n";
            $history_text .= "You: {$exchange['bot']}\n\n";
        }
    }
    
    // Build books database
    $books_database = "\n📚 LIBRARY BOOK COLLECTION (" . $books_data['total_books'] . " books):\n";
    $books_database .= str_repeat("=", 80) . "\n\n";
    
    foreach ($books_data['books'] as $index => $book) {
        $num = $index + 1;
        $books_database .= "BOOK #{$num}:\n";
        $books_database .= "  📖 Title: {$book['title']}\n";
        $books_database .= "  ✍️ Author: {$book['author']}\n";
        $books_database .= "  📚 Category: {$book['category']}\n";
        $books_database .= "  📅 Year: {$book['published_year']}\n";
        $books_database .= "  🏷️ Tags: {$book['tags']}\n";
        $books_database .= "  📊 Total Copies: {$book['total_quantity']}\n";
        $books_database .= "  ✅ Available: {$book['available']} copies\n";
        $books_database .= "  📤 Borrowed: {$book['borrowed']} copies\n";
        $books_database .= "  📖 Status: {$book['status']}\n";
        $books_database .= str_repeat("-", 80) . "\n\n";
    }
    
    // Categories
    $categories_text = "\n📂 BOOK CATEGORIES:\n";
    foreach ($books_data['categories'] as $cat => $count) {
        $categories_text .= "  • $cat: $count books\n";
    }
    
    // Authors
    $authors_text = "\n✍️ AUTHORS:\n";
    foreach ($books_data['authors'] as $author => $count) {
        $authors_text .= "  • $author: $count book(s)\n";
    }
    
    // Statistics
    $stats = "\n📊 LIBRARY STATISTICS:\n";
    $stats .= "  📚 Total Books: {$books_data['total_books']}\n";
    $stats .= "  ✅ Available Copies: {$books_data['available_books']}\n";
    $stats .= "  📂 Categories: " . count($books_data['categories']) . "\n";
    $stats .= "  ✍️ Authors: " . count($books_data['authors']) . "\n";
    
    $prompt = "You are a friendly library book search assistant helping users find books.

{$stats}
{$categories_text}
{$authors_text}
{$books_database}
{$history_text}

🗣️ USER QUESTION: {$user_message}

📝 RESPONSE INSTRUCTIONS:

1. **Use ONLY the books listed above** - If a book isn't in the database, we don't have it.

2. **When showing available books**:
   - List books where Available > 0
   - Format: **Title** by Author (Category) - ✅ X copies available
   - Group by category if many books

3. **When searching for specific books**:
   - Find exact or similar title matches
   - Show: Title, Author, Category, Year, Availability
   - If unavailable, suggest similar books

4. **When searching by category**:
   - List all books in that category with availability

5. **When searching by author**:
   - List all books by that author with availability

6. **Formatting**:
   - Use emojis: 📚 ✅ ❌ 🔍 📖 ✍️ 😊
   - Use **bold** for book titles
   - Be friendly and helpful
   - Keep responses clear and organized

7. **If book not found**:
   - Say politely we don't have it
   - Suggest similar books or categories

8. **Remember conversation context** - Reference previous messages when user says \"that book\", \"the first one\", etc.

NOW RESPOND TO THE USER:";

    return $prompt;
}

// ============================================
// GEMINI API CALL - USING CORRECT MODEL
// ============================================
function callGeminiAPI($prompt) {
    $api_key = GEMINI_API_KEY;
    
    if (empty($api_key) || $api_key === 'YOUR_GEMINI_API_KEY_HERE') {
        return [
            'success' => false,
            'error' => 'API key not configured'
        ];
    }
    
    // Use gemini-2.5-flash which is currently available on v1beta
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
            'temperature' => 0.7,
            'maxOutputTokens' => 2048,
            'topP' => 0.9,
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
            'http_code' => $http_code,
            'raw_response' => substr($response, 0, 500)
        ];
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response: ' . json_last_error_msg()
        ];
    }
    
    // Extract response from Gemini API
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return [
            'success' => true,
            'response' => trim($result['candidates'][0]['content']['parts'][0]['text'])
        ];
    }
    
    // Check for safety ratings or blocked content
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
        'error' => 'Unexpected API response format',
        'response_data' => $result
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
        $_SESSION['chat_history_client'] = [];
        sendResponse(true, "Chat cleared", [
            'response' => 'Chat history cleared! 🔄 How can I help you find books? 😊'
        ]);
    }
    
    // Validate message
    if (empty($user_message)) {
        sendResponse(false, "Message cannot be empty");
    }
    
    // Get books data
    $books_result = getClientBooksData($conn);
    
    if (!$books_result['success']) {
        sendResponse(false, "Database error", [
            'response' => "I'm having trouble accessing the book database right now. 😕\n\nPlease try again in a moment."
        ]);
    }
    
    $books_data = $books_result['data'];
    
    // Get conversation history
    $conversation_history = getConversationHistory();
    
    // Build prompt
    $prompt = buildClientPrompt($user_message, $books_data, $conversation_history);
    
    // Call Gemini API
    $ai_result = callGeminiAPI($prompt);
    
    // Handle API errors
    if (!$ai_result['success']) {
        $error_response = "I'm having trouble connecting to my AI service right now. 😅\n\n";
        $error_response .= "Error: {$ai_result['error']}\n\n";
        $error_response .= "But I can tell you we have {$books_data['total_books']} books with {$books_data['available_books']} copies available! 📚\n\n";
        $error_response .= "Please try again in a moment or ask me directly about specific books.";
        
        sendResponse(true, "Error response", [
            'response' => $error_response,
            'error_details' => $ai_result
        ]);
    }
    
    // Get AI response
    $bot_response = $ai_result['response'];
    
    // Add to conversation history
    addToHistory($user_message, $bot_response);
    
    // Send successful response
    sendResponse(true, "Response generated successfully", [
        'response' => $bot_response,
        'stats' => [
            'total_books' => $books_data['total_books'],
            'available' => $books_data['available_books'],
            'categories' => count($books_data['categories']),
            'authors' => count($books_data['authors'])
        ]
    ]);
    
} catch (Exception $e) {
    sendResponse(false, "Server error: " . $e->getMessage());
}
?>