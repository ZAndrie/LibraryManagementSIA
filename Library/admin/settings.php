<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');

$message = '';
$error = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $default_borrow_days = intval($_POST['default_borrow_days']);
    $fine_per_day = floatval($_POST['fine_per_day']);
    $max_fine_amount = floatval($_POST['max_fine_amount']);
    $max_books_per_patron = intval($_POST['max_books_per_patron']);
    $reservation_expiry_days = intval($_POST['reservation_expiry_days']);
    $overdue_reminder_days = intval($_POST['overdue_reminder_days']);
    $library_email = trim($_POST['library_email']);
    $library_phone = trim($_POST['library_phone']);
    $library_address = trim($_POST['library_address']);
    $allow_renewals = isset($_POST['allow_renewals']) ? '1' : '0';
    $max_renewals = intval($_POST['max_renewals']);
    $notification_enabled = isset($_POST['notification_enabled']) ? '1' : '0';
    $auto_calculate_fines = isset($_POST['auto_calculate_fines']) ? '1' : '0';
    $grace_period_days = intval($_POST['grace_period_days']);
    $max_reservation_queue = intval($_POST['max_reservation_queue']);
    
    // Validation
    if ($default_borrow_days < 1 || $default_borrow_days > 365) {
        $error = "Borrow days must be between 1 and 365";
    } elseif ($fine_per_day < 0) {
        $error = "Fine per day cannot be negative";
    } elseif ($max_fine_amount < 0) {
        $error = "Maximum fine cannot be negative";
    } elseif ($max_books_per_patron < 1 || $max_books_per_patron > 50) {
        $error = "Max books per patron must be between 1 and 50";
    } elseif (!empty($library_email) && !filter_var($library_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address";
    } else {
        // Settings to update
        $settings_to_update = [
            'default_borrow_days' => $default_borrow_days,
            'fine_per_day' => $fine_per_day,
            'max_fine_amount' => $max_fine_amount,
            'max_books_per_patron' => $max_books_per_patron,
            'reservation_expiry_days' => $reservation_expiry_days,
            'overdue_reminder_days' => $overdue_reminder_days,
            'library_email' => $library_email,
            'library_phone' => $library_phone,
            'library_address' => $library_address,
            'allow_renewals' => $allow_renewals,
            'max_renewals' => $max_renewals,
            'notification_enabled' => $notification_enabled,
            'auto_calculate_fines' => $auto_calculate_fines,
            'grace_period_days' => $grace_period_days,
            'max_reservation_queue' => $max_reservation_queue
        ];
        
        // Update or insert settings
        foreach ($settings_to_update as $key => $value) {
            // Check if setting exists
            $check = $conn->prepare("SELECT setting_key FROM system_settings WHERE setting_key = ?");
            $check->bind_param("s", $key);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing
                $update = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                $update->bind_param("ss", $value, $key);
                $update->execute();
            } else {
                // Insert new
                $insert = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                $insert->bind_param("ss", $key, $value);
                $insert->execute();
            }
        }
        
        logActivity($conn, $_SESSION['user_id'], 'UPDATE_SETTINGS', "Updated system settings");
        $message = "Settings updated successfully!";
    }
}

// Get current settings
$settings = [];
$settings_query = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($settings_query) {
    while ($row = $settings_query->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Default values if not set
$defaults = [
    'default_borrow_days' => 7,
    'fine_per_day' => 5.00,
    'max_fine_amount' => 500.00,
    'max_books_per_patron' => 5,
    'reservation_expiry_days' => 3,
    'overdue_reminder_days' => 3,
    'library_email' => '',
    'library_phone' => '',
    'library_address' => '',
    'allow_renewals' => '1',
    'max_renewals' => 2,
    'notification_enabled' => '1',
    'auto_calculate_fines' => '1',
    'grace_period_days' => 0,
    'max_reservation_queue' => 3
];

foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Get statistics
$total_settings = count($settings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php require_once '../includes/sidebar.php'; ?>
    <style>
        .settings-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        .settings-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
        }

        .settings-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid black;
        }

        .settings-header i {
            font-size: 24px;
            color: black;
        }

        .settings-header h3 {
            color: #333;
            font-size: 18px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        
        .description {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
            line-height: 1.4;
        }
        
        input[type="number"],
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
            min-height: 80px;
        }
        
        input:focus,
        textarea:focus {
            outline: none;
            border-color: grey;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-left: 10px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: grey;
            transition: .4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: black;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        .toggle-container {
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        .info-box {
            background: lightgrey;
            border-left: 4px solid black;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 5);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, black 0%, grey 100%);
        }

        .stat-info .label {
            color: #666;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .stat-info .value {
            color: #333;
            font-size: 20px;
            font-weight: bold;
        }

        .btn-primary {
            background: black;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
        }

        .btn-primary:hover {
            background: #525252;
            transform: translateY(-2px);
        }

        .currency-input {
            position: relative;
        }

        .currency-input::before {
            content: '₱';
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-weight: 600;
        }

        .currency-input input {
            padding-left: 30px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <div class="page-title">
                <i class="fas fa-cog"></i>
                System Settings
            </div>
            <div class="top-bar-actions">
                <button class="btn-icon" title="Refresh" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        
        <div class="content-wrapper">
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

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div class="stat-info">
                        <div class="label">Total Settings</div>
                        <div class="value"><?php echo $total_settings; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, black 0%, grey 100%);">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-info">
                        <div class="label">Admin User</div>
                        <div class="value" style="font-size: 14px;">
                            <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, black 0%, grey 100%);">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-info">
                        <div class="label">System Status</div>
                        <div class="value" style="font-size: 14px;">Active</div>
                    </div>
                </div>
            </div>

            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Important:</strong> These settings affect how the entire library system operates. Changes take effect immediately. Please review carefully before saving.
            </div>

            <form method="POST" action="">
                <div class="settings-container">
                    <!-- Borrowing Settings -->
                    <div class="settings-card">
                        <div class="settings-header">
                            <i class="fas fa-book-reader"></i>
                            <h3>Borrowing Configuration</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="default_borrow_days">
                                    Default Borrowing Period
                                    <span style="color: #e74c3c;">*</span>
                                </label>
                                <div class="description">Number of days a patron can borrow a book (1-365 days)</div>
                                <input type="number" id="default_borrow_days" name="default_borrow_days" 
                                       value="<?php echo $settings['default_borrow_days']; ?>" 
                                       min="1" max="365" required>
                            </div>

                            <div class="form-group">
                                <label for="max_books_per_patron">
                                    Max Books Per Patron
                                    <span style="color: #e74c3c;">*</span>
                                </label>
                                <div class="description">Maximum number of books a patron can borrow at once</div>
                                <input type="number" id="max_books_per_patron" name="max_books_per_patron" 
                                       value="<?php echo $settings['max_books_per_patron']; ?>" 
                                       min="1" max="50" required>
                            </div>

                            <div class="form-group">
                                <label for="grace_period_days">Grace Period (Days)</label>
                                <div class="description">Days after due date before fines are applied</div>
                                <input type="number" id="grace_period_days" name="grace_period_days" 
                                       value="<?php echo $settings['grace_period_days']; ?>" 
                                       min="0" max="7">
                            </div>

                            <div class="form-group">
                                <label for="overdue_reminder_days">Overdue Reminder</label>
                                <div class="description">Send reminder this many days before due date</div>
                                <input type="number" id="overdue_reminder_days" name="overdue_reminder_days" 
                                       value="<?php echo $settings['overdue_reminder_days']; ?>" 
                                       min="0" max="30">
                            </div>
                        </div>
                    </div>

                    <!-- Renewal Settings -->
                    <div class="settings-card">
                        <div class="settings-header">
                            <i class="fas fa-redo"></i>
                            <h3>Renewal Settings</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Allow Book Renewals</label>
                                <div class="toggle-container">
                                    <span class="description">Enable patrons to renew borrowed books</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="allow_renewals" 
                                               <?php echo $settings['allow_renewals'] == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="max_renewals">Maximum Renewals</label>
                                <div class="description">How many times a book can be renewed</div>
                                <input type="number" id="max_renewals" name="max_renewals" 
                                       value="<?php echo $settings['max_renewals']; ?>" 
                                       min="0" max="10">
                            </div>
                        </div>
                    </div>

                    <!-- Fine Settings -->
                    <div class="settings-card">
                        <div class="settings-header">
                            <i class="fas fa-money-bill-wave"></i>
                            <h3>Fine Configuration</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="fine_per_day">
                                    Fine Per Day (₱)
                                    <span style="color: #e74c3c;">*</span>
                                </label>
                                <div class="description">Amount charged for each overdue day</div>
                                <div class="currency-input">
                                    <input type="number" id="fine_per_day" name="fine_per_day" 
                                           value="<?php echo $settings['fine_per_day']; ?>" 
                                           min="0" step="0.01" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="max_fine_amount">
                                    Maximum Fine (₱)
                                    <span style="color: #e74c3c;">*</span>
                                </label>
                                <div class="description">Maximum fine that can be charged per book</div>
                                <div class="currency-input">
                                    <input type="number" id="max_fine_amount" name="max_fine_amount" 
                                           value="<?php echo $settings['max_fine_amount']; ?>" 
                                           min="0" step="0.01" required>
                                </div>
                            </div>

                            <div class="form-group full-width">
                                <label>Auto-Calculate Fines</label>
                                <div class="toggle-container">
                                    <span class="description">Automatically calculate fines for overdue books</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="auto_calculate_fines" 
                                               <?php echo $settings['auto_calculate_fines'] == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reservation Settings -->
                    <div class="settings-card">
                        <div class="settings-header">
                            <i class="fas fa-bookmark"></i>
                            <h3>Reservation Settings</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="reservation_expiry_days">Reservation Hold Period</label>
                                <div class="description">Days to hold a reserved book before canceling</div>
                                <input type="number" id="reservation_expiry_days" name="reservation_expiry_days" 
                                       value="<?php echo $settings['reservation_expiry_days']; ?>" 
                                       min="1" max="14">
                            </div>

                            <div class="form-group">
                                <label for="max_reservation_queue">Max Reservation Queue</label>
                                <div class="description">Maximum patrons that can reserve the same book</div>
                                <input type="number" id="max_reservation_queue" name="max_reservation_queue" 
                                       value="<?php echo $settings['max_reservation_queue']; ?>" 
                                       min="1" max="20">
                            </div>
                        </div>
                    </div>

                    <!-- Library Information -->
                    <div class="settings-card">
                        <div class="settings-header">
                            <i class="fas fa-building"></i>
                            <h3>Library Information</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="library_email">Library Email</label>
                                <div class="description">Official contact email for the library</div>
                                <input type="email" id="library_email" name="library_email" 
                                       value="<?php echo htmlspecialchars($settings['library_email']); ?>" 
                                       placeholder="library@example.com">
                            </div>

                            <div class="form-group">
                                <label for="library_phone">Library Phone</label>
                                <div class="description">Contact phone number</div>
                                <input type="text" id="library_phone" name="library_phone" 
                                       value="<?php echo htmlspecialchars($settings['library_phone']); ?>" 
                                       placeholder="+63 123 456 7890">
                            </div>

                            <div class="form-group full-width">
                                <label for="library_address">Library Address</label>
                                <div class="description">Physical address of the library</div>
                                <textarea id="library_address" name="library_address" 
                                          placeholder="Enter complete address..."><?php echo htmlspecialchars($settings['library_address']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Notification Settings -->
                    <div class="settings-card">
                        <div class="settings-header">
                            <i class="fas fa-bell"></i>
                            <h3>Notification Settings</h3>
                        </div>
                        
                        <div class="form-group">
                            <label>Enable Notifications</label>
                            <div class="toggle-container">
                                <span class="description">Send email notifications to patrons</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="notification_enabled" 
                                           <?php echo $settings['notification_enabled'] == '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="info-box" style="margin-top: 15px;">
                            <strong><i class="fas fa-info-circle"></i> Note:</strong> Notifications include due date reminders, overdue alerts, reservation confirmations, and fine notices.
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="settings-card">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Save All Settings
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>