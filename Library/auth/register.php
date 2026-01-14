<?php
require_once '../config/config.php';

$error = '';
$success = '';
$registration_type = isset($_GET['type']) ? $_GET['type'] : '';
$step = 1;

// Handle Student ID Verification (Step 1 - Students Only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_student_id'])) {
    $student_id = trim($_POST['student_id']);
    
    if (empty($student_id)) {
        $error = "Please enter your Student ID";
    } else {
        // Check if Student ID exists in patrons table
        $sql = "SELECT * FROM patrons WHERE student_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            $error = "Student ID not found. Please contact the admin to register your Student ID first.";
        } else {
            $patron = $result->fetch_assoc();
            
            // Check if patron already has an account (check BOTH user_id and email match in users)
            $check_sql = "SELECT u.id FROM users u WHERE u.email = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $patron['email']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "This Student ID is already registered. Please login instead.";
            } else {
                // Student ID verified, proceed to Step 2
                $_SESSION['verified_patron'] = $patron;
                $_SESSION['registration_type'] = 'student';
                $step = 2;
                $success = "Student ID verified! Please complete your account registration.";
            }
        }
    }
}

// Check if we're in Step 2
if (isset($_SESSION['verified_patron']) && !isset($_POST['verify_student_id'])) {
    $step = 2;
    $patron = $_SESSION['verified_patron'];
}

// Handle Account Registration (Step 2)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_account'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    
    // Validation
    if (empty($username) || empty($password) || empty($full_name) || empty($email) || empty($role)) {
        $error = "All fields are required";
    } elseif (!in_array($role, ['admin', 'client', 'faculty'])) {
        $error = "Invalid role selected";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        // Check if username exists
        $sql = "SELECT id FROM users WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Username already exists";
        } else {
            // Check if email exists
            $sql = "SELECT id FROM users WHERE email = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "Email already exists";
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Start transaction for data consistency
                $conn->begin_transaction();
                
                try {
                    // For students, link with patron
                    if ($role == 'client' && isset($_SESSION['verified_patron'])) {
                        $patron = $_SESSION['verified_patron'];
                        
                        // Insert user WITHOUT patron_id first (to avoid FK constraint issues)
                        $sql = "INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssss", $username, $hashed_password, $full_name, $email, $role);
                        
                        if (!$stmt->execute()) {
                            throw new Exception("Failed to create user account: " . $stmt->error);
                        }
                        
                        $user_id = $conn->insert_id;
                        
                        // CRITICAL FIX: Update patron record with user_id AND change status to 'registered'
                        $update_patron_sql = "UPDATE patrons SET user_id = ?, account_status = 'registered' WHERE id = ?";
                        $update_stmt = $conn->prepare($update_patron_sql);
                        $update_stmt->bind_param("ii", $user_id, $patron['id']);
                        
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to link patron account: " . $update_stmt->error);
                        }
                        
                        // Verify the update worked
                        if ($update_stmt->affected_rows === 0) {
                            throw new Exception("No patron record was updated. Patron may not exist.");
                        }
                        
                        // Now update users table with patron_id (bidirectional link)
                        $update_user_sql = "UPDATE users SET patron_id = ? WHERE id = ?";
                        $update_user_stmt = $conn->prepare($update_user_sql);
                        $update_user_stmt->bind_param("ii", $patron['id'], $user_id);
                        $update_user_stmt->execute();
                        
                        // Create user profile
                        $profile_sql = "INSERT INTO user_profiles (user_id, membership_status) VALUES (?, 'active')";
                        $profile_stmt = $conn->prepare($profile_sql);
                        $profile_stmt->bind_param("i", $user_id);
                        $profile_stmt->execute();
                        
                        // Initialize borrowing stats
                        $stats_sql = "INSERT INTO user_borrowing_stats (user_id, total_borrowed, currently_borrowed, total_returned, overdue_count) 
                                     VALUES (?, 0, 0, 0, 0)";
                        $stats_stmt = $conn->prepare($stats_sql);
                        $stats_stmt->bind_param("i", $user_id);
                        $stats_stmt->execute();
                        
                        // Log activity
                        $log_sql = "INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, 'REGISTER', ?, ?)";
                        $log_stmt = $conn->prepare($log_sql);
                        $description = "Client registration completed: $username (Patron ID: {$patron['id']})";
                        $ip = $_SERVER['REMOTE_ADDR'];
                        $log_stmt->bind_param("iss", $user_id, $description, $ip);
                        $log_stmt->execute();
                        
                        // Commit transaction
                        $conn->commit();
                        
                        // Clear session
                        unset($_SESSION['verified_patron']);
                        unset($_SESSION['registration_type']);
                        
                        // Redirect to login
                        header("Location: login.php?registered=1");
                        exit();
                        
                    } else {
                        // For admins and faculty, create without patron link
                        $sql = "INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssss", $username, $hashed_password, $full_name, $email, $role);
                        
                        if (!$stmt->execute()) {
                            throw new Exception("Failed to create user account: " . $stmt->error);
                        }
                        
                        $user_id = $conn->insert_id;
                        
                        // Create user profile
                        $profile_sql = "INSERT INTO user_profiles (user_id, membership_status) VALUES (?, 'active')";
                        $profile_stmt = $conn->prepare($profile_sql);
                        $profile_stmt->bind_param("i", $user_id);
                        $profile_stmt->execute();
                        
                        // Initialize borrowing stats
                        $stats_sql = "INSERT INTO user_borrowing_stats (user_id, total_borrowed, currently_borrowed, total_returned, overdue_count) 
                                     VALUES (?, 0, 0, 0, 0)";
                        $stats_stmt = $conn->prepare($stats_sql);
                        $stats_stmt->bind_param("i", $user_id);
                        $stats_stmt->execute();
                        
                        // Log activity
                        $log_sql = "INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, 'REGISTER', ?, ?)";
                        $log_stmt = $conn->prepare($log_sql);
                        $description = ucfirst($role) . " registration completed: $username";
                        $ip = $_SERVER['REMOTE_ADDR'];
                        $log_stmt->bind_param("iss", $user_id, $description, $ip);
                        $log_stmt->execute();
                        
                        // Commit transaction
                        $conn->commit();
                        
                        // Clear session
                        unset($_SESSION['verified_patron']);
                        unset($_SESSION['registration_type']);
                        
                        // Redirect to login
                        header("Location: login.php?registered=1");
                        exit();
                    }
                } catch (Exception $e) {
                    // Rollback on error
                    $conn->rollback();
                    $error = "Error creating account: " . $e->getMessage();
                }
            }
        }
    }
}

// Set registration type from session or URL
if (isset($_SESSION['registration_type'])) {
    $registration_type = $_SESSION['registration_type'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Registration - Library Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, white 0%, white 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .register-container {
            background: white;
            padding: 40px 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 600px;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 {
            color: black;
            font-size: 28px;
            margin-bottom: 5px;
        }
        .logo p {
            color: black;
            font-size: 14px;
        }
        .registration-type-selection {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 30px;
        }
        .type-option {
            position: relative;
        }
        .type-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        .type-label {
            display: block;
            padding: 20px;
            border: 2px solid #ddd;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .type-option input[type="radio"]:checked + .type-label {
            border-color: black;
            background: #f0f0f0;
        }
        .type-label .icon {
            font-size: 32px;
            margin-bottom: 8px;
            color: black;
        }
        .type-label .title {
            font-weight: 600;
            color: black;
            font-size: 16px;
        }
        .type-label .desc {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: 600;
            position: relative;
            z-index: 2;
        }
        .step.active .step-number {
            background: black;
            color: white;
        }
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
        .step-label {
            font-size: 12px;
            color: #666;
        }
        .step.active .step-label {
            color: black;
            font-weight: 600;
        }
        .step-line {
            position: absolute;
            top: 20px;
            left: 50%;
            right: -50%;
            height: 2px;
            background: #e0e0e0;
            z-index: 1;
        }
        .step.completed .step-line {
            background: #28a745;
        }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        .alert-success {
            background: #efe;
            color: #2a7c2a;
            border: 1px solid #cfc;
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
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: black;
            font-weight: 500;
        }
        .form-group label .required {
            color: #e74c3c;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        select:focus {
            outline: none;
            border-color: black;
        }
        input[type="text"]:disabled,
        input[type="email"]:disabled {
            background: #f5f5f5;
            color: #666;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, black 0%, black 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            margin-top: 10px;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .patron-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .patron-info h3 {
            color: black;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .patron-info p {
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }
        .patron-info p strong {
            color: black;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
        }
        .login-link a {
            color: black;
            text-decoration: none;
            font-size: 14px;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <h1><i class="fas fa-book"></i> Library System</h1>
            <p>Account Registration</p>
        </div>

        <?php if (empty($registration_type) && $step == 1 && !isset($_SESSION['verified_patron'])): ?>
            <div class="info-box">
                <strong><i class="fas fa-user-plus"></i> Select Registration Type</strong>
                <p>Choose your account type to proceed with registration</p>
            </div>

            <div class="registration-type-selection" style="grid-template-columns: repeat(3, 1fr);">
                <div class="type-option">
                    <input type="radio" id="type_student" name="registration_type" value="student">
                    <label for="type_student" class="type-label" onclick="window.location.href='?type=student'">
                        <div class="icon"><i class="fas fa-user-graduate"></i></div>
                        <div class="title">Client</div>
                        <div class="desc">Required Student ID</div>
                    </label>
                </div>
                <div class="type-option">
                    <input type="radio" id="type_faculty" name="registration_type" value="faculty">
                    <label for="type_faculty" class="type-label" onclick="window.location.href='?type=faculty'">
                        <div class="icon"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="title">Faculty</div>
                        <div class="desc">Teaching staff access</div>
                    </label>
                </div>
                <div class="type-option">
                    <input type="radio" id="type_admin" name="registration_type" value="admin">
                    <label for="type_admin" class="type-label" onclick="window.location.href='?type=admin'">
                        <div class="icon"><i class="fas fa-user-shield"></i></div>
                        <div class="title">Admin</div>
                        <div class="desc">Full system access</div>
                    </label>
                </div>
            </div>
        <?php else: ?>

        <?php if ($registration_type == 'student'): ?>
        <div class="step-indicator">
            <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                <div class="step-number"><?php echo $step > 1 ? '✓' : '1'; ?></div>
                <div class="step-label">Verify Student ID</div>
                <?php if ($step < 2): ?>
                    <div class="step-line"></div>
                <?php endif; ?>
            </div>
            <div class="step <?php echo $step == 2 ? 'active' : ''; ?>">
                <div class="step-number">2</div>
                <div class="step-label">Create Account</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <?php if ($registration_type == 'student' && $step == 1): ?>
            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Student Registration Requirements</strong>
                <p>You must be registered as a patron by the library admin before creating an account. Please enter your Student ID to verify your registration.</p>
            </div>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="student_id">Student ID <span class="required">*</span></label>
                    <input type="text" id="student_id" name="student_id" required 
                           placeholder="Enter your Student ID (e.g., STU-001234)"
                           value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>">
                    <div class="help-text">Enter the Student ID provided by your institution</div>
                </div>

                <button type="submit" name="verify_student_id" class="btn">
                    <i class="fas fa-search"></i> Verify Student ID
                </button>
                
                <button type="button" class="btn btn-secondary" onclick="window.location.href='register.php'">
                    <i class="fas fa-arrow-left"></i> Back to Type Selection
                </button>
            </form>

        <?php elseif ($registration_type == 'admin' || $registration_type == 'faculty' || $step == 2): ?>
            <?php if (isset($patron)): ?>
            <div class="patron-info">
                <h3><i class="fas fa-user-check"></i> Verified Patron Information</h3>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($patron['name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($patron['email']); ?></p>
                <p><strong>Student ID:</strong> <?php echo htmlspecialchars($patron['student_id']); ?></p>
            </div>
            <?php elseif ($registration_type == 'faculty'): ?>
            <div class="info-box">
                <strong><i class="fas fa-chalkboard-teacher"></i> Faculty Registration</strong>
                <p>Create a faculty account with teaching staff privileges.</p>
            </div>
            <?php elseif ($registration_type == 'admin'): ?>
            <div class="info-box">
                <strong><i class="fas fa-user-shield"></i> Administrator Registration</strong>
                <p>Create an administrator account with full system access.</p>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php
                $role_value = 'client';
                if ($registration_type == 'faculty') {
                    $role_value = 'faculty';
                } elseif ($registration_type == 'admin') {
                    $role_value = 'admin';
                }
                ?>
                
                <input type="hidden" name="role" value="<?php echo $role_value; ?>">
                
                <div class="form-group">
                    <label for="role_display">Account Type <span class="required">*</span></label>
                    <input type="text" id="role_display" value="<?php 
                        echo $registration_type == 'faculty' ? 'Faculty' : 
                            ($registration_type == 'admin' ? 'Administrator' : 'Client (Student)'); 
                    ?>" disabled style="background: #f5f5f5;">
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" required
                           value="<?php echo isset($patron) ? htmlspecialchars($patron['name']) : (isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" 
                           value="<?php echo isset($patron) ? htmlspecialchars($patron['email']) : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''); ?>"
                           <?php echo isset($patron) ? 'readonly style="background: #f5f5f5;"' : 'required'; ?>>
                    <?php if (isset($patron)): ?>
                        <div class="help-text">Email from your patron record (cannot be changed)</div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="username">Username <span class="required">*</span></label>
                    <input type="text" id="username" name="username" required
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    <div class="help-text">Minimum 3 characters</div>
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <input type="password" id="password" name="password" required>
                    <div class="help-text">Minimum 6 characters</div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" name="create_account" class="btn">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
                
                <button type="button" class="btn btn-secondary" 
                        onclick="window.location.href='register.php'">
                    <i class="fas fa-arrow-left"></i> Back to Type Selection
                </button>
            </form>
        <?php endif; ?>
        <?php endif; ?>

        <div class="login-link">
            Already have an account? <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login Here</a>
        </div>

        <?php if ($step == 1 && $registration_type == 'student'): ?>
        <div class="login-link" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
            <small style="color: #666;">Don't have a Student ID? Contact the library administrator</small>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
// Clear session on manual clear
if (isset($_GET['clear_session'])) {
    unset($_SESSION['verified_patron']);
    unset($_SESSION['registration_type']);
}
?>