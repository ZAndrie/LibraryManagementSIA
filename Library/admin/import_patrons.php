<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

$message = '';
$error = '';
$import_results = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Error uploading file";
    } else {
        $allowed_extensions = ['csv', 'xls', 'xlsx'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $error = "Invalid file type. Please upload a CSV file (.csv) or Excel file (.xls, .xlsx)";
        } else {
            try {
                $rows = [];
                
                if ($file_extension == 'csv') {
                    // Handle CSV files
                    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            $rows[] = $data;
                        }
                        fclose($handle);
                    }
                    $import_results = importPatrons($conn, $rows);
                } else {
                    // Handle Excel files - Check if required library exists
                    if (!file_exists('../includes/SimpleXLSX.php')) {
                        $error = "Excel support library not found. Please upload CSV format instead.";
                    } else {
                        require_once '../includes/SimpleXLSX.php';
                        
                        if ($xlsx = SimpleXLSX::parse($file['tmp_name'])) {
                            $rows = $xlsx->rows();
                            $import_results = importPatrons($conn, $rows);
                        } else {
                            $error = SimpleXLSX::parseError();
                        }
                    }
                }
                
                if ($import_results && $import_results['success'] > 0) {
                    $message = "Successfully imported {$import_results['success']} patron(s)!";
                    if ($import_results['skipped'] > 0) {
                        $message .= " ({$import_results['skipped']} skipped - already exists)";
                    }
                    // Using logActivitySafe function from functions.php
                    if (function_exists('logActivitySafe')) {
                        logActivitySafe($conn, $_SESSION['user_id'], 'IMPORT_PATRONS', "Imported {$import_results['success']} patron records");
                    }
                } elseif ($import_results && $import_results['errors'] > 0) {
                    $error = "Import completed with {$import_results['errors']} error(s). Successfully imported: {$import_results['success']}, Skipped: {$import_results['skipped']}";
                } elseif ($import_results) {
                    $error = "No records were imported. Please check your file format.";
                }
                
            } catch (Exception $e) {
                $error = "Error processing file: " . $e->getMessage();
            }
        }
    }
}

function importPatrons($conn, $rows) {
    $success = 0;
    $errors = 0;
    $skipped = 0;
    $error_log = [];
    
    // Skip header row
    if (count($rows) > 0) {
        array_shift($rows);
    }
    
    foreach ($rows as $index => $row) {
        $row_num = $index + 2;
        
        // Get data from columns - MATCHES YOUR DATABASE STRUCTURE
        // student_id, name, email, phone, address, role
        $student_id = isset($row[0]) ? trim((string)$row[0]) : '';
        $name = isset($row[1]) ? trim((string)$row[1]) : '';
        $email = isset($row[2]) ? trim((string)$row[2]) : '';
        $phone = isset($row[3]) ? trim((string)$row[3]) : '';
        $address = isset($row[4]) ? trim((string)$row[4]) : '';
        $role = isset($row[5]) ? strtolower(trim((string)$row[5])) : 'client';
        
        // Skip empty rows
        if (empty($student_id) && empty($name) && empty($email)) {
            continue;
        }
        
        // Validation
        if (empty($student_id)) {
            $error_log[] = "Row $row_num: Student ID is required";
            $errors++;
            continue;
        }
        
        if (empty($name)) {
            $error_log[] = "Row $row_num: Name is required";
            $errors++;
            continue;
        }
        
        if (empty($email)) {
            $error_log[] = "Row $row_num: Email is required";
            $errors++;
            continue;
        }
        
        // Clean and validate email
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_log[] = "Row $row_num: Invalid email format - $email";
            $errors++;
            continue;
        }
        
        // Validate role (must be 'client' or 'faculty')
        if (!in_array($role, ['client', 'faculty'])) {
            $role = 'client'; // Default to client if invalid
        }
        
        // Check if student ID already exists
        $check_sql = "SELECT id FROM patrons WHERE student_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_log[] = "Row $row_num: Student ID '$student_id' already exists - skipped";
            $skipped++;
            $check_stmt->close();
            continue;
        }
        $check_stmt->close();
        
        // Check if email already exists
        $check_email_sql = "SELECT id FROM patrons WHERE email = ?";
        $check_email_stmt = $conn->prepare($check_email_sql);
        $check_email_stmt->bind_param("s", $email);
        $check_email_stmt->execute();
        $check_email_result = $check_email_stmt->get_result();
        
        if ($check_email_result->num_rows > 0) {
            $error_log[] = "Row $row_num: Email '$email' already exists - skipped";
            $skipped++;
            $check_email_stmt->close();
            continue;
        }
        $check_email_stmt->close();
        
        // Insert patron - EXACT DATABASE STRUCTURE
        // Columns: student_id, name, email, phone, address, role, account_status, user_id
        // account_status defaults to 'active' but we'll set it to 'registered' for imports
        $account_status = 'registered'; // Pre-registered by admin
        
        $sql = "INSERT INTO patrons (student_id, name, email, phone, address, role, account_status, user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NULL)";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $error_log[] = "Row $row_num: Database error - " . $conn->error;
            $errors++;
            continue;
        }
        
        $stmt->bind_param("sssssss", $student_id, $name, $email, $phone, $address, $role, $account_status);
        
        if ($stmt->execute()) {
            $success++;
        } else {
            $error_log[] = "Row $row_num: Failed to insert - " . $stmt->error;
            $errors++;
        }
        $stmt->close();
    }
    
    return [
        'success' => $success,
        'skipped' => $skipped,
        'errors' => $errors,
        'error_log' => $error_log
    ];
}

// Check if SimpleXLSX library is available
$excelSupported = file_exists('../includes/SimpleXLSX.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Patrons - Library System</title>
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
        }
        .btn:hover { background: grey; }
        .btn-primary {
            background: black;
            border: none;
            font-size: 16px;
            padding: 12px 24px;
        }
        .container {
            max-width: 1000px;
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
        .card h2 {
            margin-bottom: 20px;
            color: #333;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
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
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px dashed #e0e0e0;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: pointer;
        }
        .template-section {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 8px;
        }
        .sample-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        .sample-table th,
        .sample-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .sample-table th {
            background: #f5f5f5;
            font-weight: 600;
        }
        .error-log {
            background: #fff5f5;
            padding: 15px;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 15px;
        }
        .error-log-item {
            padding: 8px;
            border-bottom: 1px solid #ffebee;
            color: #c62828;
            font-size: 13px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        .stat-box {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-box.success {
            background: #d4edda;
            color: #155724;
        }
        .stat-box.warning {
            background: #fff3cd;
            color: #856404;
        }
        .stat-box.error {
            background: #f8d7da;
            color: #721c24;
        }
        .stat-box .number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-box .label {
            font-size: 12px;
            text-transform: uppercase;
        }
        .note-box {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 15px;
            border-radius: 4px;
            margin-top: 15px;
        }
        .note-box strong {
            display: block;
            margin-bottom: 8px;
            color: #e65100;
        }
        .note-box ul {
            margin-left: 20px;
            color: #666;
        }
        .note-box li {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-file-import"></i> Import Patrons</h1>
        <div style="display: flex; gap: 15px;">
            <a href="patron_directory.php" class="btn">← Back to Patrons</a>
            <a href="dashboard.php" class="btn">Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <?php if (!$excelSupported): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> <strong>Excel Support Limited:</strong> 
                Excel parsing library (SimpleXLSX.php) not found. You can only upload <strong>CSV files</strong> at this time. 
                To enable Excel file support (.xls, .xlsx), please add the SimpleXLSX.php library to the includes folder.
            </div>
        <?php endif; ?>

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

        <?php if ($import_results): ?>
            <div class="card">
                <h2>Import Results</h2>
                <div class="stats-grid">
                    <div class="stat-box success">
                        <div class="number"><?php echo $import_results['success']; ?></div>
                        <div class="label">Successfully Imported</div>
                    </div>
                    <div class="stat-box warning">
                        <div class="number"><?php echo $import_results['skipped']; ?></div>
                        <div class="label">Skipped (Duplicates)</div>
                    </div>
                    <div class="stat-box error">
                        <div class="number"><?php echo $import_results['errors']; ?></div>
                        <div class="label">Errors</div>
                    </div>
                </div>

                <?php if (!empty($import_results['error_log'])): ?>
                    <div class="error-log">
                        <strong><i class="fas fa-list"></i> Error & Skip Details:</strong>
                        <?php foreach ($import_results['error_log'] as $log): ?>
                            <div class="error-log-item"><?php echo htmlspecialchars($log); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Upload File</h2>
            
            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Supported Formats:</strong> 
                <?php if ($excelSupported): ?>
                    Excel (.xls, .xlsx) and CSV (.csv)
                <?php else: ?>
                    <strong>CSV (.csv) only</strong> - SimpleXLSX library or PHP ZIP extension required for Excel support
                <?php endif; ?>
            </div>

            <?php if (!$excelSupported): ?>
                <div class="warning-box">
                    <strong><i class="fas fa-lightbulb"></i> How to convert Excel to CSV:</strong><br>
                    1. Open your Excel file<br>
                    2. Click "File" → "Save As"<br>
                    3. Choose "CSV (Comma delimited) (*.csv)" as the file type<br>
                    4. Save and upload the CSV file here
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="excel_file">Select File:</label>
                    <input type="file" id="excel_file" name="excel_file" 
                           accept="<?php echo $excelSupported ? '.xls,.xlsx,.csv' : '.csv'; ?>" required>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i> Import Patrons
                </button>
            </form>
        </div>

        <div class="card">
            <div class="template-section">
                <h3><i class="fas fa-table"></i> Required File Format</h3>
                <p><strong>IMPORTANT:</strong> Your Excel/CSV file must have columns in this exact order:</p>
                
                <table class="sample-table">
                    <thead>
                        <tr>
                            <th>Column 1<br>Student ID</th>
                            <th>Column 2<br>Name</th>
                            <th>Column 3<br>Email</th>
                            <th>Column 4<br>Phone</th>
                            <th>Column 5<br>Address</th>
                            <th>Column 6<br>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>2021-1184-5</td>
                            <td>John Doe</td>
                            <td>john@example.com</td>
                            <td>09123456789</td>
                            <td>123 Main St</td>
                            <td>client</td>
                        </tr>
                        <tr>
                            <td>STU-251230-8435</td>
                            <td>Jane Smith</td>
                            <td>jane@example.com</td>
                            <td>09987654321</td>
                            <td>456 Oak Ave</td>
                            <td>faculty</td>
                        </tr>
                        <tr>
                            <td>2021-1184-6</td>
                            <td>Bob Wilson</td>
                            <td>bob@example.com</td>
                            <td>09111222333</td>
                            <td>789 Pine Rd</td>
                            <td>client</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="note-box">
                    <strong><i class="fas fa-exclamation-triangle"></i> Column Requirements:</strong>
                    <ul>
                        <li><strong>Required Columns:</strong> Student ID, Name, Email (must have valid @ format)</li>
                        <li><strong>Optional Columns:</strong> Phone, Address</li>
                        <li><strong>Role Column:</strong> Must be either "client" or "faculty" (defaults to "client" if empty/invalid)</li>
                        <li><strong>Account Status:</strong> All imported patrons will be set to "registered" status automatically</li>
                        <li><strong>Duplicates:</strong> Records with existing Student ID or Email will be skipped</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>