<?php
// Allow access only via API gateway
if (!defined('API_GATEWAY')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Direct access denied. Use the API gateway.']);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

require_once '../auth/check_auth.php';
require_once '../includes/book_api.php';
checkRole('admin');

// Gateway will set Content-Type and CORS headers

$response = array(
    'success' => false,
    'message' => '',
    'data' => null
);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $isbn = isset($_POST['isbn']) ? trim($_POST['isbn']) : '';
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $author = isset($_POST['author']) ? trim($_POST['author']) : '';
        
        if (empty($isbn) && empty($title)) {
            $response['message'] = 'Please provide ISBN or Title';
            echo json_encode($response);
            exit;
        }
        
        $bookAPI = new BookAPI();
        $result = $bookAPI->searchBook($isbn, $title, $author);
        
        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = 'Book found from ' . $result['source'];
            $response['data'] = $result['data'];
            $response['source'] = $result['source'];
        } else {
            $response['message'] = 'No book found. Please enter details manually.';
        }
    } else {
        $response['message'] = 'Invalid request method';
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit;
?>