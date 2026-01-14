<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

$error = '';
$success = '';

if (!isset($_GET['id'])) {
    header("Location: patron_directory.php");
    exit();
}

$id = intval($_GET['id']);

// Fetch patron with user information
$sql = "SELECT p.*, u.id as user_id, u.username, u.full_name as user_full_name, u.email as user_email, u.role
        FROM patrons p 
        LEFT JOIN users u ON p.user_id = u.id 
        WHERE p.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$patron = $result->fetch_assoc();

if (!$patron) {
    header("Location: patron_directory.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Patron fields
    $student_id = trim($_POST['student_id']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $account_status = $_POST['account_status'];
    
    // User fields (if user exists)
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $user_full_name = isset($_POST['user_full_name']) ? trim($_POST['user_full_name']) : '';
    $user_email = isset($_POST['user_email']) ? trim($_POST['user_email']) : '';
    $role = isset($_POST['role']) ? $_POST['role'] : '';
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Validation
    if (empty($student_id) || empty($name) || empty($email)) {
        $error = "Student ID, Name, and Email are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif ($patron['user_id'] && empty($username)) {
        $error = "Username is required for registered users";
    } elseif ($patron['user_id'] && !empty($new_password) && strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters";
    } elseif ($patron['user_id'] && !empty($new_password) && $new_password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        $conn->begin_transaction();
        
        try {
            // Check if student_id exists for another patron
            $sql = "SELECT id FROM patrons WHERE student_id = ? AND id != ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $student_id, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception("Student ID already exists for another patron");
            }
            
            // Update patron information
            $sql = "UPDATE patrons SET student_id=?, name=?, email=?, phone=?, address=?, account_status=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssi", $student_id, $name, $email, $phone, $address, $account_status, $id);
            
            if (!$stmt->execute()) {
                throw new Exception("Error updating patron: " . $conn->error);
            }
            
            // Update user account if exists
            if ($patron['user_id']) {
                // Check if username exists for other users
                $sql = "SELECT id FROM users WHERE username = ? AND id != ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $username, $patron['user_id']);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    throw new Exception("Username already exists for another user");
                }
                
                // Check if email exists for other users
                $sql = "SELECT id FROM users WHERE email = ? AND id != ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $user_email, $patron['user_id']);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    throw new Exception("Email already exists for another user");
                }
                
                // Update user account
                if (!empty($new_password)) {
                    // Update with new password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET username=?, password=?, full_name=?, email=?, role=? WHERE id=?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssi", $username, $hashed_password, $user_full_name, $user_email, $role, $patron['user_id']);
                } else {
                    // Update without changing password
                    $sql = "UPDATE users SET username=?, full_name=?, email=?, role=? WHERE id=?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssssi", $username, $user_full_name, $user_email, $role, $patron['user_id']);
                }
                
                if (!$stmt->execute()) {
                    throw new Exception("Error updating user account: " . $conn->error);
                }
            }
            
            $conn->commit();
            logActivity($conn, $_SESSION['user_id'], 'UPDATE_PATRON', "Updated patron: $name (ID: $id)");
            $success = "Patron and account information updated successfully!";
            
            // Refresh patron data
            $stmt = $conn->prepare("SELECT p.*, u.id as user_id, u.username, u.full_name as user_full_name, u.email as user_email, u.role
                                   FROM patrons p 
                                   LEFT JOIN users u ON p.user_id = u.id 
                                   WHERE p.id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $patron = $stmt->get_result()->fetch_assoc();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Patron - Library System</title>
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
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-registered { background: #d4edda; color: #155724; }
        .status-active { background: #cce5ff; color: #004085; }
        .status-suspended { background: #f8d7da; color: #721c24; }
        .account-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .account-info h3 {
            color: black;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .account-info p {
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }
        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            margin: 30px -30px 20px -30px;
            border-top: 2px solid black;
            border-bottom: 2px solid black;
        }
        .section-header h3 {
            color: black;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
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
        input[type="password"],
        select,
        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            font-family: inherit;
        }
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: black;
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
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid black;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fa fa-edit"></i> Edit Patron</h1>
        <div style="display: flex; gap: 15px;">
            <a href="patron_profile.php?id=<?php echo $id; ?>" class="btn">View Profile</a>
            <a href="patron_directory.php" class="btn">← Back to Directory</a>
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

        <!-- Account Status Card -->
        <div class="card">
            <div class="account-info">
                <h3><i class="fas fa-info-circle"></i> Current Status</h3>
                <p><strong>Account Status:</strong> 
                    <?php 
                    $status = $patron['account_status'];
                    $badge_class = 'status-' . $status;
                    echo "<span class='status-badge $badge_class'>" . ucfirst($status) . "</span>";
                    ?>
                </p>
                <?php if ($patron['user_id']): ?>
                    <p><strong>Registered User:</strong> <?php echo htmlspecialchars($patron['username']); ?> (<?php echo htmlspecialchars($patron['user_full_name']); ?>)</p>
                    <p style="color: #28a745;"><i class="fas fa-check-circle"></i> This patron has an active user account</p>
                <?php else: ?>
                    <p style="color: #856404;"><i class="fas fa-exclamation-triangle"></i> This patron hasn't registered an account yet</p>
                    <p style="font-size: 12px; color: #666;">Provide Student ID <strong><?php echo htmlspecialchars($patron['student_id']); ?></strong> to the patron for registration</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit Form -->
        <div class="card">
            <form method="POST" action="">
                
                <!-- PATRON INFORMATION SECTION -->
                <div class="section-header">
                    <h3><i class="fas fa-address-card"></i> Patron Information</h3>
                </div>

                <div class="form-group">
                    <label for="student_id">Student ID <span class="required">*</span></label>
                    <input type="text" id="student_id" name="student_id" 
                           value="<?php echo htmlspecialchars($patron['student_id']); ?>" required>
                    <div class="help-text">Unique Student ID used for account registration</div>
                </div>

                <div class="form-group">
                    <label for="name">Full Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($patron['name']); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($patron['email']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($patron['phone']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address"><?php echo htmlspecialchars($patron['address']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="account_status">Account Status <span class="required">*</span></label>
                    <select id="account_status" name="account_status" required>
                        <option value="pending" <?php echo $patron['account_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="registered" <?php echo $patron['account_status'] == 'registered' ? 'selected' : ''; ?>>Registered</option>
                        <option value="active" <?php echo $patron['account_status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="suspended" <?php echo $patron['account_status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>

                <?php if ($patron['user_id']): ?>
                    <!-- USER ACCOUNT SECTION -->
                    <div class="section-header">
                        <h3><i class="fas fa-user-lock"></i> User Account Settings</h3>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i> <strong>Note:</strong> Leave password fields empty if you don't want to change the password
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">Username <span class="required">*</span></label>
                            <input type="text" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($patron['username']); ?>" required>
                            <div class="help-text">Used for login</div>
                        </div>

                        <div class="form-group">
                            <label for="user_full_name">Full Name (Account) <span class="required">*</span></label>
                            <input type="text" id="user_full_name" name="user_full_name" 
                                   value="<?php echo htmlspecialchars($patron['user_full_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="user_email">Account Email <span class="required">*</span></label>
                            <input type="email" id="user_email" name="user_email" 
                                   value="<?php echo htmlspecialchars($patron['user_email']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="role">User Role <span class="required">*</span></label>
                            <select id="role" name="role" required>
                                <option value="client" <?php echo $patron['role'] == 'client' ? 'selected' : ''; ?>>Client</option>
                                <option value="faculty" <?php echo $patron['role'] == 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                                <option value="admin" <?php echo $patron['role'] == 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            </select>
                        </div>
                    </div>

                    <hr style="margin: 25px 0; border: none; border-top: 1px solid #eee;">
                    
                    <h4 style="margin-bottom: 15px; color: #333; font-size: 16px;">Change Password (Optional)</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password">
                            <div class="help-text">Minimum 6 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password">
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save All Changes
                    </button>
                    <a href="patron_profile.php?id=<?php echo $id; ?>" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>