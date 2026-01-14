<?php
/**
 * Central API Gateway - Single Entry Point
 * Usage: /api/index.php?module=books&action=search&search=term
 * Modules supported: books, chatbot_admin, chatbot_client, admin_book_search, check_patron_limit
 */

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mark that requests come through the gateway
if (!defined('API_GATEWAY')) {
    define('API_GATEWAY', true);
}

$method = $_SERVER['REQUEST_METHOD'];

// Accept module via GET, POST, or the path
$module = null;
if (isset($_GET['module']) && $_GET['module'] !== '') {
    $module = $_GET['module'];
} elseif (isset($_POST['module']) && $_POST['module'] !== '') {
    $module = $_POST['module'];
}

$action = null;
if (isset($_GET['action'])) $action = $_GET['action'];
elseif (isset($_POST['action'])) $action = $_POST['action'];

// Map logical modules to existing PHP endpoints (filesystem paths)
$map = [
    'books' => __DIR__ . '/../includes/api_books.php',
    'chatbot_admin' => __DIR__ . '/../includes/chatbot_api.php',
    'chatbot_client' => __DIR__ . '/../includes/chatbot_api_client.php',
    'admin_book_search' => __DIR__ . '/../admin/api_book_search.php',
    'check_patron_limit' => __DIR__ . '/../admin/check_patron_limit.php'
];

if (empty($module)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameter: module']);
    exit;
}

// Allow CORS and preflight
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($map[$module]) || !file_exists($map[$module])) {
    echo json_encode(['success' => false, 'message' => 'Unknown or unavailable module: ' . $module]);
    exit;
}

$target = $map[$module];

// Forward the action parameter to the included script
if ($action !== null) {
    // For safety, set in $_GET and $_POST as appropriate
    $_GET['action'] = $action;
    $_POST['action'] = $action;
    $_REQUEST['action'] = $action;
}

// Merge any incoming query parameters into $_GET so included script can access them
// (they already exist in $_GET, so no extra work needed)

// Capture output of the target script and return it
ob_start();
include $target;
$output = ob_get_clean();

// If the included script already emitted JSON, forward it. Otherwise wrap/normalize it.
if ($output === null || $output === '') {
    echo json_encode(['success' => false, 'message' => 'No output from module: ' . $module]);
    exit;
}

// Try to decode JSON output
$decoded = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    // Valid JSON produced by included script — echo as-is
    echo $output;
    exit;
}

// Not JSON — wrap raw output inside a JSON envelope
echo json_encode([
    'success' => false,
    'message' => 'Module produced non-JSON output',
    'raw' => $output
]);

exit;

exit;
