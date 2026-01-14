<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
require_once '../includes/book_api.php';
checkRole('admin');

$error = '';
$success = '';
$import_results = null;

// Handle Excel Import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_excel'])) {
    if (isset($_POST['excel_data']) && !empty($_POST['excel_data'])) {
        $excel_data = json_decode($_POST['excel_data'], true);
        
        if ($excel_data && is_array($excel_data)) {
            $imported = 0;
            $skipped = 0;
            $errors = array();
            
            $conn->begin_transaction();
            
            try {
                foreach ($excel_data as $index => $row) {
                    $row_num = $index + 2; // +2 because row 1 is header, arrays start at 0
                    
                    // Validate required fields
                    if (empty($row['title']) || empty($row['author'])) {
                        $errors[] = "Row $row_num: Title and Author are required";
                        $skipped++;
                        continue;
                    }
                    
                    $title = trim($row['title']);
                    $author = trim($row['author']);
                    $isbn = isset($row['isbn']) ? trim($row['isbn']) : '';
                    $category = isset($row['category']) ? trim($row['category']) : '';
                    $quantity = isset($row['quantity']) ? intval($row['quantity']) : 1;
                    $published_year = isset($row['published_year']) ? intval($row['published_year']) : 0;
                    
                    // Check for duplicate ISBN if provided
                    if (!empty($isbn)) {
                        $check_sql = "SELECT id FROM books WHERE isbn = ?";
                        $check_stmt = $conn->prepare($check_sql);
                        $check_stmt->bind_param("s", $isbn);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows > 0) {
                            $errors[] = "Row $row_num: Book with ISBN '$isbn' already exists";
                            $skipped++;
                            continue;
                        }
                    }
                    
                    // Insert or merge via API gateway to centralize logic
                    $payload = [
                        'title' => $title,
                        'author' => $author,
                        'isbn' => $isbn,
                        'category' => $category,
                        'quantity' => $quantity,
                        'available' => $quantity,
                        'published_year' => $published_year
                    ];

                    // Call gateway
                    $gateway = ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/Library/api/index.php?module=books&action=add_book';
                    $ch = curl_init($gateway);
                    $json = json_encode($payload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    // send session cookie
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $resp = curl_exec($ch);
                    $curl_err = curl_error($ch);
                    curl_close($ch);

                    if ($resp === false) {
                        $errors[] = "Row $row_num: cURL error - " . $curl_err;
                        $skipped++;
                    } else {
                        $d = json_decode($resp, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($d['success']) && $d['success'] === true) {
                            $imported++;
                        } else {
                            $msg = is_array($d) && isset($d['message']) ? $d['message'] : 'Unexpected API response';
                            $errors[] = "Row $row_num: API error - " . $msg;
                            $skipped++;
                        }
                    }
                }
                
                $conn->commit();
                
                $import_results = array(
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => $errors
                );
                
                if ($imported > 0) {
                    $success = "$imported book(s) imported successfully!";
                }
                if ($skipped > 0) {
                    $error = "$skipped book(s) were skipped. See details below.";
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Import failed: " . $e->getMessage();
            }
        } else {
            $error = "Invalid Excel data format";
        }
    } else {
        $error = "No Excel data received";
    }
}

// Handle Manual Add Book (server-side fallback) - route through API gateway to avoid direct-access errors
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_book'])) {
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $isbn = trim($_POST['isbn']);
    $category = trim($_POST['category']);
    $quantity = intval($_POST['quantity']);
    $published_year = intval($_POST['published_year']);

    if (empty($title) || empty($author)) {
        $error = "Title and Author are required";
    } else {
        // Prepare payload for API gateway
        $payload = [
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'category' => $category,
            'quantity' => $quantity,
            'available' => $quantity,
            'published_year' => $published_year
        ];

        // Build gateway URL using current host
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $gateway_url = $scheme . '://' . $host . dirname($_SERVER['REQUEST_URI']);
        // Ensure we point to /api/index.php
        $gateway_url = $scheme . '://' . $host . '/Library/api/index.php?module=books&action=add_book';

        // cURL POST JSON with session cookie to authenticate
        $ch = curl_init($gateway_url);
        $json = json_encode($payload);
        $headers = [ 'Content-Type: application/json' ];

        // Send current session cookie
        if (session_status() === PHP_SESSION_NONE) session_start();
        $sess_name = session_name();
        $sess_id = session_id();
        $cookie_header = $sess_name . '=' . $sess_id;

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIE, $cookie_header);
        // For local dev, allow self-signed
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $resp = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            $error = 'cURL error: ' . $curl_err;
        } else {
            $decoded = json_decode($resp, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['success']) && $decoded['success'] === true) {
                $new_id = $decoded['data']['id'] ?? null;
                $success = 'Book added successfully!';
                if ($new_id) $success .= ' (ID: ' . $new_id . ')';
                $_POST = array();
            } else {
                // Pass through message from API if available
                if (is_array($decoded) && isset($decoded['message'])) {
                    $error = 'API error: ' . $decoded['message'];
                } else {
                    $error = 'API returned unexpected response (HTTP ' . $http_code . ')';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Book - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
        }
        .btn:hover { background: grey; }
        .btn-primary {
            background: black;
            border: none;
            font-size: 16px;
        }
        .btn-success {
            background: #28a745;
            border: none;
            font-size: 14px;
        }
        .btn-success:hover { background: #218838; }
        .btn-api {
            background: black;
            border: none;
            font-size: 14px;
            margin-left: 10px;
        }
        .btn-download {
            background: #17a2b8;
            border: none;
            font-size: 14px;
        }
        .btn-download:hover { background: #138496; }
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .excel-import-section {
            background: white;
            border-left: 4px solid #28a745;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .excel-import-section h3 {
            color: #28a745;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .api-search-section {
            background: white;
            border-left: 4px solid black;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .api-search-section h3 {
            color: black;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .search-box {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 10px;
        }
        .upload-area {
            border: 3px dashed #28a745;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s;
            cursor: pointer;
        }
        .upload-area:hover {
            background: #e9ecef;
            border-color: #218838;
        }
        .upload-area.dragover {
            background: #d4edda;
            border-color: #218838;
        }
        .file-input {
            display: none;
        }
        .upload-icon {
            font-size: 48px;
            color: #28a745;
            margin-bottom: 15px;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
        }
        .preview-table th,
        .preview-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .preview-table th {
            background: black;
            color: white;
            font-weight: 600;
        }
        .preview-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .preview-container {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: black;
            font-weight: 500;
        }
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: grey;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 10px;
            color: #667eea;
        }
        .loading.active {
            display: block;
        }
        .api-badge {
            display: inline-block;
            padding: 4px 10px;
            background: black;
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        .excel-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #28a745;
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        .import-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
        }
        .import-summary h4 {
            margin-bottom: 10px;
            color: #333;
        }
        .error-list {
            list-style: none;
            padding: 0;
        }
        .error-list li {
            padding: 8px;
            margin: 5px 0;
            background: #fff;
            border-left: 3px solid #dc3545;
            border-radius: 3px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .stat-box {
            padding: 15px;
            background: white;
            border-radius: 6px;
            border: 2px solid #e0e0e0;
            text-align: center;
        }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        .stat-label {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            color: #666;
            transition: all 0.3s;
        }
        .tab:hover {
            color: #333;
        }
        .tab.active {
            color: black;
            border-bottom-color: black;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-book"></i> Add New Book</h1>
        <div style="display: flex; gap: 15px;">
            <a href="books.php" class="btn">Back to Books</a>
            <a href="dashboard.php" class="btn">Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <!-- Placeholder for client-side messages (AJAX responses) -->
        <div id="client_message"></div>

        <?php if ($import_results): ?>
            <div class="import-summary">
                <h4><i class="fas fa-chart-bar"></i> Import Summary</h4>
                <div class="stats">
                    <div class="stat-box" style="border-color: #28a745;">
                        <div class="stat-number" style="color: #28a745;"><?php echo $import_results['imported']; ?></div>
                        <div class="stat-label">Imported</div>
                    </div>
                    <div class="stat-box" style="border-color: #ffc107;">
                        <div class="stat-number" style="color: #ffc107;"><?php echo $import_results['skipped']; ?></div>
                        <div class="stat-label">Skipped</div>
                    </div>
                    <div class="stat-box" style="border-color: #17a2b8;">
                        <div class="stat-number" style="color: #17a2b8;"><?php echo $import_results['imported'] + $import_results['skipped']; ?></div>
                        <div class="stat-label">Total Rows</div>
                    </div>
                </div>
                
                <?php if (!empty($import_results['errors'])): ?>
                    <h4 style="margin-top: 15px;"><i class="fas fa-exclamation-triangle"></i> Errors & Warnings</h4>
                    <ul class="error-list">
                        <?php foreach ($import_results['errors'] as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('excel')">
                    <i class="fas fa-file-excel"></i> Import from Excel
                </button>
                <button class="tab" onclick="switchTab('api')">
                    <i class="fas fa-magic"></i> Auto-Fill (API)
                </button>
                <button class="tab" onclick="switchTab('manual')">
                    <i class="fas fa-keyboard"></i> Manual Entry
                </button>
            </div>

            <!-- Excel Import Tab -->
            <div id="excel-tab" class="tab-content active">
                <div class="excel-import-section">
                    <h3>
                        <i class="fas fa-file-excel"></i> 
                        Import Books from Excel
                        <span class="excel-badge">BULK IMPORT</span>
                    </h3>
                    <p style="margin-bottom: 15px; color: #666; font-size: 14px;">
                        Upload an Excel file (.xlsx or .xls) with book information. The file should have columns: Title, Author, ISBN, Category, Quantity, Published Year
                    </p>
                    
                    <div style="margin-bottom: 15px;">
                        <button type="button" class="btn btn-download" onclick="downloadTemplate()">
                            <i class="fas fa-download"></i> Download Excel Template
                        </button>
                    </div>

                    <div class="upload-area" id="uploadArea" onclick="document.getElementById('excelFile').click()">
                        <input type="file" id="excelFile" class="file-input" accept=".xlsx,.xls" onchange="handleFile(this.files[0])">
                        <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <p style="font-size: 16px; color: #333; margin-bottom: 8px;">
                            <strong>Click to upload</strong> or drag and drop
                        </p>
                        <p style="font-size: 13px; color: #666;">
                            Excel files (.xlsx, .xls) - Maximum 1000 rows
                        </p>
                    </div>

                    <div id="previewContainer" class="preview-container" style="display: none;">
                        <h4 style="margin-bottom: 10px;">Preview (First 10 rows)</h4>
                        <div style="overflow-x: auto;">
                            <table class="preview-table" id="previewTable"></table>
                        </div>
                        <form method="POST" id="importForm">
                            <input type="hidden" name="excel_data" id="excelData">
                            <div class="form-actions">
                                <button type="submit" name="import_excel" class="btn btn-success">
                                    <i class="fas fa-upload"></i> Import <span id="rowCount">0</span> Books
                                </button>
                                <button type="button" class="btn" onclick="cancelImport()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- API Search Tab -->
            <div id="api-tab" class="tab-content">
                <div class="api-search-section">
                    <h3>
                        <i class="fas fa-magic"></i> 
                        Auto-Fill Book Information
                        <span class="api-badge">API</span>
                    </h3>
                    <p style="margin-bottom: 15px; color: #666; font-size: 14px;">
                        Search by ISBN or Title to automatically fill book details from Google Books or Open Library
                    </p>
                    <div class="search-box">
                        <input type="text" id="search_isbn" placeholder="Enter ISBN (e.g., 9780134685991)">
                        <input type="text" id="search_title" placeholder="Or enter Title">
                        <button type="button" class="btn btn-api" onclick="searchBook()">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                    <div id="api_loading" class="loading">
                        <i class="fas fa-spinner fa-spin"></i> Searching book databases...
                    </div>
                    <div id="api_result" style="margin-top: 10px;"></div>
                </div>
            </div>

            <!-- Manual Entry Tab -->
            <div id="manual-tab" class="tab-content">
                <h2 style="margin-bottom: 25px; color: #333;">Book Information</h2>
                
                <form id="manualForm">
                    <div class="form-group">
                        <label for="title">Book Title *</label>
                        <input type="text" id="title" name="title" required>
                    </div>

                    <div class="form-group">
                        <label for="author">Author *</label>
                        <input type="text" id="author" name="author" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="isbn">ISBN</label>
                            <input type="text" id="isbn" name="isbn">
                        </div>

                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="">Select Category</option>
                                <option value="Fiction">Fiction</option>
                                <option value="Non-Fiction">Non-Fiction</option>
                                <option value="Science">Science</option>
                                <option value="History">History</option>
                                <option value="Biography">Biography</option>
                                <option value="Technology">Technology</option>
                                <option value="Literature">Literature</option>
                                <option value="Children">Children</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="quantity">Quantity *</label>
                            <input type="number" id="quantity" name="quantity" min="1" value="1" required>
                        </div>

                        <div class="form-group">
                            <label for="published_year">Published Year</label>
                            <input type="number" id="published_year" name="published_year" min="1800" max="2025">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" id="addBookBtn" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Book
                        </button>
                        <a href="books.php" class="btn">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    let excelDataGlobal = [];

    // Tab switching
    function switchTab(tabName) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        event.target.classList.add('active');
        document.getElementById(tabName + '-tab').classList.add('active');
    }

    // Download Excel template
    function downloadTemplate() {
        const wb = XLSX.utils.book_new();
        const ws_data = [
            ['Title', 'Author', 'ISBN', 'Category', 'Quantity', 'Published Year'],
            ['The Great Gatsby', 'F. Scott Fitzgerald', '9780743273565', 'Fiction', 5, 1925],
            ['To Kill a Mockingbird', 'Harper Lee', '9780061120084', 'Fiction', 3, 1960],
            ['1984', 'George Orwell', '9780451524935', 'Fiction', 4, 1949]
        ];
        const ws = XLSX.utils.aoa_to_sheet(ws_data);
        
        // Set column widths
        ws['!cols'] = [
            {wch: 30}, // Title
            {wch: 25}, // Author
            {wch: 15}, // ISBN
            {wch: 15}, // Category
            {wch: 10}, // Quantity
            {wch: 15}  // Published Year
        ];
        
        XLSX.utils.book_append_sheet(wb, ws, 'Books Template');
        XLSX.writeFile(wb, 'library_books_template.xlsx');
    }

    // Drag and drop handlers
    const uploadArea = document.getElementById('uploadArea');
    
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    });

    // Handle Excel file
    function handleFile(file) {
        if (!file) return;
        
        const validTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        if (!validTypes.includes(file.type) && !file.name.match(/\.(xlsx|xls)$/)) {
            alert('Please upload a valid Excel file (.xlsx or .xls)');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array'});
                
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                const jsonData = XLSX.utils.sheet_to_json(firstSheet, {header: 1});
                
                if (jsonData.length < 2) {
                    alert('Excel file must have at least a header row and one data row');
                    return;
                }
                
                processExcelData(jsonData);
            } catch (error) {
                alert('Error reading Excel file: ' + error.message);
            }
        };
        reader.readAsArrayBuffer(file);
    }

    // Process Excel data
    function processExcelData(data) {
        const headers = data[0].map(h => String(h).toLowerCase().trim());
        const rows = data.slice(1);
        
        // Map headers to expected fields
        const headerMap = {
            'title': ['title', 'book title', 'book name'],
            'author': ['author', 'authors', 'writer'],
            'isbn': ['isbn', 'isbn13', 'isbn10', 'isbn number'],
            'category': ['category', 'genre', 'type', 'subject'],
            'quantity': ['quantity', 'qty', 'copies', 'stock'],
            'published_year': ['published year', 'year', 'publish year', 'publication year', 'published']
        };
        
        // Find column indices
        const columnIndices = {};
        for (const [field, aliases] of Object.entries(headerMap)) {
            const index = headers.findIndex(h => aliases.includes(h));
            columnIndices[field] = index;
        }
        
        // Validate required columns
        if (columnIndices.title === -1 || columnIndices.author === -1) {
            alert('Excel file must have "Title" and "Author" columns');
            return;
        }
        
        // Convert to objects
        excelDataGlobal = [];
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            
            // Skip empty rows
            if (!row || row.every(cell => !cell)) continue;
            
            const bookData = {
                title: columnIndices.title !== -1 ? String(row[columnIndices.title] || '') : '',
                author: columnIndices.author !== -1 ? String(row[columnIndices.author] || '') : '',
                isbn: columnIndices.isbn !== -1 ? String(row[columnIndices.isbn] || '') : '',
                category: columnIndices.category !== -1 ? String(row[columnIndices.category] || '') : '',
                quantity: columnIndices.quantity !== -1 ? parseInt(row[columnIndices.quantity]) || 1 : 1,
                published_year: columnIndices.published_year !== -1 ? parseInt(row[columnIndices.published_year]) || 0 : 0
            };
            
            // Only add if title and author exist
            if (bookData.title && bookData.author) {
                excelDataGlobal.push(bookData);
            }
        }
        
        if (excelDataGlobal.length === 0) {
            alert('No valid book data found in Excel file');
            return;
        }
        
        if (excelDataGlobal.length > 1000) {
            alert('Maximum 1000 books can be imported at once. Only the first 1000 will be processed.');
            excelDataGlobal = excelDataGlobal.slice(0, 1000);
        }
        
        displayPreview(excelDataGlobal);
    }

    // Display preview table
    function displayPreview(data) {
        const previewTable = document.getElementById('previewTable');
        const previewContainer = document.getElementById('previewContainer');
        const rowCount = document.getElementById('rowCount');
        const excelDataInput = document.getElementById('excelData');
        
        // Show preview
        previewContainer.style.display = 'block';
        rowCount.textContent = data.length;
        excelDataInput.value = JSON.stringify(data);
        
        // Build table
        let tableHTML = '<thead><tr>';
        tableHTML += '<th>#</th><th>Title</th><th>Author</th><th>ISBN</th><th>Category</th><th>Quantity</th><th>Year</th>';
        tableHTML += '</tr></thead><tbody>';
        
        const previewData = data.slice(0, 10); // Show first 10 rows
        previewData.forEach((book, index) => {
            tableHTML += '<tr>';
            tableHTML += `<td>${index + 1}</td>`;
            tableHTML += `<td>${escapeHtml(book.title)}</td>`;
            tableHTML += `<td>${escapeHtml(book.author)}</td>`;
            tableHTML += `<td>${escapeHtml(book.isbn)}</td>`;
            tableHTML += `<td>${escapeHtml(book.category)}</td>`;
            tableHTML += `<td>${book.quantity}</td>`;
            tableHTML += `<td>${book.published_year || '-'}</td>`;
            tableHTML += '</tr>';
        });
        
        if (data.length > 10) {
            tableHTML += `<tr><td colspan="7" style="text-align: center; font-style: italic; color: #666;">
                ... and ${data.length - 10} more rows
            </td></tr>`;
        }
        
        tableHTML += '</tbody>';
        previewTable.innerHTML = tableHTML;
        
        // Scroll to preview
        previewContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Cancel import
    function cancelImport() {
        document.getElementById('previewContainer').style.display = 'none';
        document.getElementById('excelFile').value = '';
        excelDataGlobal = [];
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // API Search functionality
    function searchBook() {
        var isbn = document.getElementById('search_isbn').value.trim();
        var title = document.getElementById('search_title').value.trim();
        var loading = document.getElementById('api_loading');
        var result = document.getElementById('api_result');
        
        if (!isbn && !title) {
            result.innerHTML = '<div class="alert alert-error">Please enter ISBN or Title</div>';
            return;
        }
        
        loading.classList.add('active');
        result.innerHTML = '';
        
        var xhr = new XMLHttpRequest();
        // Route through central API gateway so api endpoints that deny direct access work
        xhr.open('POST', '../api/index.php?module=admin_book_search', true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            loading.classList.remove('active');
            
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    
                    if (data.success) {
                        result.innerHTML = '<div class="alert alert-success"><strong>SUCCESS!</strong> Book details loaded from ' + data.source + '</div>';
                        
                        document.getElementById('title').value = data.data.title || '';
                        document.getElementById('author').value = data.data.author || '';
                        document.getElementById('isbn').value = data.data.isbn || '';
                        document.getElementById('published_year').value = data.data.published_year || '';
                        
                        var categorySelect = document.getElementById('category');
                        var category = data.data.category || '';
                        for (var i = 0; i < categorySelect.options.length; i++) {
                            if (categorySelect.options[i].value.toLowerCase() === category.toLowerCase()) {
                                categorySelect.value = categorySelect.options[i].value;
                                break;
                            }
                        }
                        
                        // Switch to manual tab to show filled data
                        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                        document.querySelectorAll('.tab')[2].classList.add('active');
                        document.getElementById('manual-tab').classList.add('active');
                        
                        document.getElementById('title').focus();
                    } else {
                        result.innerHTML = '<div class="alert alert-info"><strong>INFO:</strong> ' + data.message + '</div>';
                    }
                } catch (e) {
                    result.innerHTML = '<div class="alert alert-error"><strong>Error:</strong> ' + e.message + '</div>';
                }
            } else {
                result.innerHTML = '<div class="alert alert-error"><strong>Error:</strong> Request failed</div>';
            }
        };
        
        xhr.onerror = function() {
            loading.classList.remove('active');
            result.innerHTML = '<div class="alert alert-error"><strong>Error:</strong> Network error</div>';
        };
        
        var params = 'isbn=' + encodeURIComponent(isbn) + '&title=' + encodeURIComponent(title);
        xhr.send(params);
    }
    
    document.getElementById('search_isbn').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            searchBook();
        }
    });
    
    document.getElementById('search_title').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            searchBook();
        }
    });
    
    // Manual form submission via API gateway (uses embedded message area instead of alert())
    const manualForm = document.getElementById('manualForm');
    if (manualForm) {
        manualForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const title = document.getElementById('title').value.trim();
            const author = document.getElementById('author').value.trim();
            const isbn = document.getElementById('isbn').value.trim();
            const category = document.getElementById('category').value;
            const quantity = parseInt(document.getElementById('quantity').value) || 1;
            const published_year = parseInt(document.getElementById('published_year').value) || null;
            const clientMsg = document.getElementById('client_message');

            if (!title || !author) {
                if (clientMsg) {
                    clientMsg.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Title and Author are required</div>';
                    clientMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            const payload = { title, author, isbn, category, quantity, available: quantity, published_year };

            // Send via API gateway. Credentials: same-origin so session cookie is sent for auth.
            fetch('../api/index.php?module=books&action=add_book', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (clientMsg) clientMsg.innerHTML = '';

                if (data && data.success) {
                    if (clientMsg) {
                        const idText = data.data && data.data.id ? (' (ID: ' + data.data.id + ')') : '';
                        clientMsg.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Book added successfully' + idText + '</div>';
                        clientMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    // Clear form
                    document.getElementById('title').value = '';
                    document.getElementById('author').value = '';
                    document.getElementById('isbn').value = '';
                    document.getElementById('category').value = '';
                    document.getElementById('quantity').value = 1;
                    document.getElementById('published_year').value = '';
                } else {
                    const msg = (data && data.message) ? data.message : 'Unknown error';
                    if (clientMsg) {
                        clientMsg.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Error: ' + escapeHtml(msg) + '</div>';
                        clientMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            })
            .catch(err => {
                console.error(err);
                if (clientMsg) {
                    clientMsg.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Network error while adding book</div>';
                    clientMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        });
    }
    </script>
</body>
</html>