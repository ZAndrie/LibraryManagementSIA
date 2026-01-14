<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

$error = '';
$success = '';

// Check for timeout message
if (isset($_GET['timeout'])) {
    $error = "Your session has expired due to inactivity. Please login again.";
}

// Check for registration success
if (isset($_GET['registered'])) {
    $success = "Registration successful! Please login with your credentials.";
}

// Check for suspended account
if (isset($_GET['suspended'])) {
    $error = "Your account has been suspended by the administrator. Please contact the library admin for more information.";
}

// Check for pending account
if (isset($_GET['pending'])) {
    $error = "Your account registration is still pending. Please wait for administrator approval.";
}

// Handle Guest Access
if (isset($_GET['guest'])) {
    // Create guest session
    $_SESSION['user_id'] = 0; // Guest ID
    $_SESSION['username'] = 'guest';
    $_SESSION['full_name'] = 'Guest User';
    $_SESSION['role'] = 'guest';
    $_SESSION['last_activity'] = time();
    
    // Log guest access
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $sql = "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) 
            VALUES (NULL, 'GUEST_ACCESS', 'Guest user accessed system', ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $ip_address, $user_agent);
    $stmt->execute();
    
    header("Location: ../home.php");
    exit();
}

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != 0) {
    header("Location: ../home.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    
    if (empty($username) || empty($password) || empty($role)) {
        $error = "Please enter username, password, and select your role";
    } elseif (!in_array($role, ['admin', 'client', 'faculty'])) {
        $error = "Invalid role selected";
    } else {
        // Check if account is locked
        if (checkAccountLocked($conn, $username)) {
            $error = "Account is locked due to multiple failed login attempts. Please try again in 30 minutes.";
            logLogin($conn, null, $username, 'failed', 'Account locked');
        } else {
            $sql = "SELECT * FROM users WHERE username = ? AND role = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $username, $role);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    
                    // Check if CLIENT account is suspended BEFORE allowing login
                    if ($user['role'] == 'client') {
                        $check_patron_sql = "SELECT account_status FROM patrons WHERE user_id = ?";
                        $check_stmt = $conn->prepare($check_patron_sql);
                        $check_stmt->bind_param("i", $user['id']);
                        $check_stmt->execute();
                        $patron_result = $check_stmt->get_result();
                        
                        if ($patron_result->num_rows > 0) {
                            $patron = $patron_result->fetch_assoc();
                            
                            // Block suspended accounts
                            if ($patron['account_status'] == 'suspended') {
                                $error = "Your account has been suspended. Please contact the library administrator.";
                                logLogin($conn, $user['id'], $username, 'failed', 'Account suspended');
                                goto skip_login;
                            }
                            
                            // Block pending accounts
                            if ($patron['account_status'] == 'pending') {
                                $error = "Your account is pending approval. Please wait for administrator confirmation.";
                                logLogin($conn, $user['id'], $username, 'failed', 'Account pending');
                                goto skip_login;
                            }
                        }
                    }
                    
                    // Check if FACULTY account is suspended BEFORE allowing login
                    if ($user['role'] == 'faculty') {
                        $check_patron_sql = "SELECT account_status FROM patrons WHERE user_id = ?";
                        $check_stmt = $conn->prepare($check_patron_sql);
                        $check_stmt->bind_param("i", $user['id']);
                        $check_stmt->execute();
                        $patron_result = $check_stmt->get_result();
                        
                        if ($patron_result->num_rows > 0) {
                            $patron = $patron_result->fetch_assoc();
                            
                            // Block suspended accounts
                            if ($patron['account_status'] == 'suspended') {
                                $error = "Your account has been suspended. Please contact the library administrator.";
                                logLogin($conn, $user['id'], $username, 'failed', 'Account suspended');
                                goto skip_login;
                            }
                            
                            // Block pending accounts
                            if ($patron['account_status'] == 'pending') {
                                $error = "Your account is pending approval. Please wait for administrator confirmation.";
                                logLogin($conn, $user['id'], $username, 'failed', 'Account pending');
                                goto skip_login;
                            }
                        }
                    }
                    
                    // Successful login
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['last_activity'] = time();
                    
                    resetLoginAttempts($conn, $username);
                    
                    $sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                    
                    logLogin($conn, $user['id'], $username, 'success', null);
                    logActivity($conn, $user['id'], 'LOGIN', 'User logged in successfully');
                    createUserSession($conn, $user['id'], session_id());
                    
                    header("Location: ../home.php");
                    exit();
                }
                else {
                    skip_login: // Label for goto statement
                    
                    $locked = incrementLoginAttempts($conn, $username);
                    
                    if ($locked) {
                        $error = "Too many failed login attempts. Account locked for 30 minutes.";
                        logLogin($conn, $user['id'], $username, 'failed', 'Account locked');
                    } else {
                        if (empty($error)) {
                            $error = "Invalid username or password";
                        }
                        logLogin($conn, $user['id'], $username, 'failed', 'Invalid password');
                    }
                }
            } else {
                $error = "Invalid username, password, or role";
                logLogin($conn, null, $username, 'failed', 'User not found or wrong role');
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
    <title>Login - Library Management System</title>
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
        .login-container {
            background: white;
            padding: 40px 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,5);
            width: 100%;
            max-width: 900px;
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
        .guest-info {
            background: #e7f3ff;
            border-left: 4px solid black;
            padding: 12px 15px;
            margin-bottom: 25px;
            border-radius: 4px;
            font-size: 13px;
            color: black;
        }
        .guest-info strong {
            display: block;
            margin-bottom: 5px;
            color: black;
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
        
        /* Main Form Layout */
        .form-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: start;
        }
        
        .left-section {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .right-section {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: black;
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid grey;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: black;
        }
        .role-selection {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }
        .role-option {
            position: relative;
        }
        .role-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        .role-label {
            display: block;
            padding: 18px 10px;
            border: 2px solid black;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .role-option input[type="radio"]:checked + .role-label {
            border-color: blue;
            background: #f0f4ff;
        }
        .role-label .icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .role-label .title {
            font-weight: 600;
            color: black;
            font-size: 15px;
        }
        .role-label .desc {
            font-size: 11px;
            color: #666;
            margin-top: 4px;
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
            text-decoration: none;
            display: block;
            text-align: center;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .divider {
            text-align: center;
            color: #999;
            font-size: 14px;
            position: relative;
            padding: 10px 0;
        }
        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 42%;
            height: 1px;
            background: #ddd;
        }
        .divider::before {
            left: 0;
        }
        .divider::after {
            right: 0;
        }
        .footer-links {
            text-align: center;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .footer-links a {
            color: black;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .footer-links a:hover {
            text-decoration: underline;
        }
        .forgot-link {
            color: #999 !important;
            font-size: 13px !important;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1><i class="fas fa-book"></i> Library System</h1>
            <p>Please login to continue</p>
        </div>

        <div class="guest-info">
            <strong><i class="fas fa-rocket"></i> Quick Access Available!</strong>
            Want to browse books without logging in? Click "Continue as Guest" below!
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-wrapper">
                <div class="left-section">
                    <div class="form-group">
                        <label>Select Your Role</label>
                        <div class="role-selection">
                            <div class="role-option">
                                <input type="radio" id="role_admin" name="role" value="admin" required>
                                <label for="role_admin" class="role-label">
                                    <div class="icon"><i class="fas fa-user-shield"></i></div>
                                    <div class="title">Admin</div>
                                    <div class="desc">Full Access</div>
                                </label>
                            </div>
                            <div class="role-option">
                                <input type="radio" id="role_client" name="role" value="client" required>
                                <label for="role_client" class="role-label">
                                    <div class="icon"><i class="fas fa-user-graduate"></i></div>
                                    <div class="title">Client</div>
                                    <div class="desc">Student Access</div>
                                </label>
                            </div>
                            <div class="role-option">
                                <input type="radio" id="role_faculty" name="role" value="faculty" required>
                                <label for="role_faculty" class="role-label">
                                    <div class="icon"><i class="fas fa-chalkboard-teacher"></i></div>
                                    <div class="title">Faculty</div>
                                    <div class="desc">Teaching Staff</div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <a href="?guest=1" class="btn" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);"><i class="fas fa-rocket"></i> Continue as Guest</a>
                </div>

                <div class="right-section">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <button type="submit" class="btn">Login</button>
                </div>
            </div>
        </form>

        <div class="footer-links">
            <span>Don't have an account? <a href="../auth/register.php">Register Here</a></span>
            <a href="../auth/forgot_password.php" class="forgot-link">Forgot Password?</a>
        </div>
    </div>
</body>
</html>