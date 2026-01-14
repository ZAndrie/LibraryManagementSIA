<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = trim($_POST['student_id']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    
    if (empty($student_id) || empty($name) || empty($email)) {
        $error = "Student ID, Name, and Email are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        // Check if student_id already exists
        $sql = "SELECT id FROM patrons WHERE student_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Student ID already exists";
        } else {
            $sql = "INSERT INTO patrons (student_id, name, email, phone, address, account_status) VALUES (?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssss", $student_id, $name, $email, $phone, $address);
            
            if ($stmt->execute()) {
                logActivity($conn, $_SESSION['user_id'], 'CREATE_PATRON', "Added patron: $name (Student ID: $student_id)");
                $success = "Patron added successfully! Student can now register using Student ID: <strong>$student_id</strong>";
                $_POST = array();
            } else {
                if ($conn->errno == 1062) {
                    $error = "Email or Student ID already exists";
                } else {
                    $error = "Error adding patron: " . $conn->error;
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
    <title>Add New Patron - Library System</title>
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
        }
        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid black;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .info-box strong {
            display: block;
            margin-bottom: 5px;
            color: black;
        }
        .info-box p {
            font-size: 13px;
            color: #333;
            line-height: 1.6;
            margin: 5px 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        label .required {
            color: #e74c3c;
        }
        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid black;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            font-family: inherit;
        }
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        input:focus, textarea:focus {
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
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .student-id-generator {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 10px;
            border-radius: 6px;
            margin-top: 5px;
            font-size: 12px;
        }
        .student-id-generator button {
            background: #ffc107;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            margin-top: 5px;
        }
        .student-id-generator button:hover {
            background: #ffb300;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fa fa-users"></i> Add New Patron</h1>
        <div style="display: flex; gap: 15px;">
            <a href="patron_directory.php" class="btn">← Back to Patron Directory</a>
            <a href="dashboard.php" class="btn">Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Student ID Registration System</strong>
                <p>• Students must be added to the patron database BEFORE they can register for an account</p>
                <p>• Each student needs a unique Student ID for account verification</p>
                <p>• After adding a patron, provide the Student ID to the student for registration</p>
            </div>

            <h2 style="margin-bottom: 25px; color: #333;"><i class="fas fa-user-plus"></i> Patron Information</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="student_id">Student ID <span class="required">*</span></label>
                    <input type="text" id="student_id" name="student_id" required 
                           placeholder="e.g., STU-001234, 2024-00123, etc."
                           value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>">
                    <div class="help-text">Enter a unique Student ID. This will be used by the student to register their account.</div>
                    <div class="student-id-generator">
                        <i class="fas fa-lightbulb"></i> <strong>Quick Generate:</strong>
                        <button type="button" onclick="generateStudentID()">Generate Student ID</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="name">Full Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" required
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        <div class="help-text">Student will use this email for account registration</div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone"
                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Patron
                    </button>
                    <a href="patron_directory.php" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function generateStudentID() {
            // Generate Student ID format: STU-YYMMDD-XXXX
            const now = new Date();
            const year = now.getFullYear().toString().slice(-2);
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const random = String(Math.floor(Math.random() * 10000)).padStart(4, '0');
            
            const studentId = `STU-${year}${month}${day}-${random}`;
            document.getElementById('student_id').value = studentId;
        }
    </script>
</body>
</html>