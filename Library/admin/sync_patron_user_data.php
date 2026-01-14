<?php
/**
 * Database Synchronization Script
 * Run this ONCE to fix existing patron-user relationships and borrowing stats
 * 
 * Place this file in: /Library/admin/sync_patron_user_data.php
 * Access via: http://localhost/Library/admin/sync_patron_user_data.php
 */

require_once '../config/config.php';
require_once '../auth/check_auth.php';
checkRole('admin');

$output = [];
$errors = [];

// Start transaction
$conn->begin_transaction();

try {
    $output[] = "=== STARTING DATABASE SYNCHRONIZATION ===\n";
    
    // STEP 1: Link patrons to users by email
    $output[] = "\n[STEP 1] Linking patrons to users by matching email...";
    
    $sql = "UPDATE patrons p
            INNER JOIN users u ON p.email = u.email
            SET p.user_id = u.id
            WHERE p.user_id IS NULL OR p.user_id = 0";
    
    $result = $conn->query($sql);
    $affected = $conn->affected_rows;
    $output[] = "✓ Linked $affected patron record(s) to user accounts";
    
    // STEP 2: Link users to patrons (bidirectional)
    $output[] = "\n[STEP 2] Creating bidirectional user-patron links...";
    
    $sql = "UPDATE users u
            INNER JOIN patrons p ON u.email = p.email
            SET u.patron_id = p.id
            WHERE (u.patron_id IS NULL OR u.patron_id = 0) AND u.role = 'client'";
    
    $result = $conn->query($sql);
    $affected = $conn->affected_rows;
    $output[] = "✓ Linked $affected user record(s) to patron accounts";
    
    // STEP 3: Update patron account status
    $output[] = "\n[STEP 3] Updating patron account status...";
    
    $sql = "UPDATE patrons p
            INNER JOIN users u ON p.user_id = u.id
            SET p.account_status = 'registered'
            WHERE p.account_status = 'pending' AND p.user_id IS NOT NULL";
    
    $result = $conn->query($sql);
    $affected = $conn->affected_rows;
    $output[] = "✓ Updated $affected patron(s) from 'pending' to 'registered'";
    
    // STEP 4: Create missing user profiles
    $output[] = "\n[STEP 4] Creating missing user profiles...";
    
    $sql = "INSERT INTO user_profiles (user_id, membership_status)
            SELECT u.id, 'active'
            FROM users u
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE up.id IS NULL";
    
    $result = $conn->query($sql);
    $affected = $conn->affected_rows;
    $output[] = "✓ Created $affected user profile(s)";
    
    // STEP 5: Sync borrowing statistics
    $output[] = "\n[STEP 5] Syncing borrowing statistics...";
    
    // First, create missing stats records
    $sql = "INSERT INTO user_borrowing_stats (user_id, total_borrowed, currently_borrowed, total_returned, overdue_count)
            SELECT u.id, 0, 0, 0, 0
            FROM users u
            LEFT JOIN user_borrowing_stats ubs ON u.id = ubs.user_id
            WHERE ubs.id IS NULL";
    
    $result = $conn->query($sql);
    $affected = $conn->affected_rows;
    $output[] = "✓ Created $affected borrowing stats record(s)";
    
    // Now, calculate correct statistics from borrowings table
    $output[] = "Calculating actual borrowing statistics...";
    
    $sql = "UPDATE user_borrowing_stats ubs
            INNER JOIN users u ON ubs.user_id = u.id
            INNER JOIN patrons p ON u.id = p.user_id
            LEFT JOIN (
                SELECT 
                    patron_id,
                    COUNT(*) as total_borrowed,
                    SUM(CASE WHEN status IN ('borrowed', 'overdue') THEN 1 ELSE 0 END) as currently_borrowed,
                    SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as total_returned,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count
                FROM borrowings
                WHERE status != 'pending'
                GROUP BY patron_id
            ) b ON p.id = b.patron_id
            SET 
                ubs.total_borrowed = COALESCE(b.total_borrowed, 0),
                ubs.currently_borrowed = COALESCE(b.currently_borrowed, 0),
                ubs.total_returned = COALESCE(b.total_returned, 0),
                ubs.overdue_count = COALESCE(b.overdue_count, 0)";
    
    $result = $conn->query($sql);
    $affected = $conn->affected_rows;
    $output[] = "✓ Updated $affected user(s) with actual borrowing statistics";
    
    // STEP 6: Verify data integrity
    $output[] = "\n[STEP 6] Verifying data integrity...";
    
    // Check for orphaned patrons (no user but should have)
    $sql = "SELECT COUNT(*) as count FROM patrons WHERE user_id IS NULL AND account_status = 'registered'";
    $result = $conn->query($sql);
    $count = $result->fetch_assoc()['count'];
    if ($count > 0) {
        $errors[] = "⚠ Found $count patron(s) marked as 'registered' but not linked to any user";
    }
    
    // Check for users without patron (clients only)
    $sql = "SELECT COUNT(*) as count FROM users u 
            WHERE u.role = 'client' AND u.patron_id IS NULL";
    $result = $conn->query($sql);
    $count = $result->fetch_assoc()['count'];
    if ($count > 0) {
        $output[] = "ℹ Found $count client user(s) without patron records (may be normal)";
    }
    
    // Check borrowing stats accuracy
    $sql = "SELECT 
                u.id,
                u.username,
                ubs.currently_borrowed as stats_count,
                COALESCE(actual.count, 0) as actual_count
            FROM users u
            INNER JOIN user_borrowing_stats ubs ON u.id = ubs.user_id
            INNER JOIN patrons p ON u.id = p.user_id
            LEFT JOIN (
                SELECT patron_id, COUNT(*) as count
                FROM borrowings
                WHERE status IN ('borrowed', 'overdue')
                GROUP BY patron_id
            ) actual ON p.id = actual.patron_id
            WHERE ubs.currently_borrowed != COALESCE(actual.count, 0)";
    
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $errors[] = "⚠ Found borrowing stats mismatches:";
        while ($row = $result->fetch_assoc()) {
            $errors[] = "  - User '{$row['username']}' (ID: {$row['id']}): Stats shows {$row['stats_count']}, actual is {$row['actual_count']}";
        }
    } else {
        $output[] = "✓ All borrowing statistics are accurate";
    }
    
    // Commit all changes
    $conn->commit();
    $output[] = "\n=== SYNCHRONIZATION COMPLETED SUCCESSFULLY ===";
    
} catch (Exception $e) {
    $conn->rollback();
    $errors[] = "❌ ERROR: " . $e->getMessage();
    $output[] = "\n=== SYNCHRONIZATION FAILED - ROLLED BACK ===";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Synchronization - Library System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #00ff00;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #2d2d2d;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        h1 {
            color: #00ff00;
            margin-bottom: 20px;
            text-align: center;
            font-size: 24px;
        }
        .output {
            background: #1e1e1e;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #00ff00;
            max-height: 600px;
            overflow-y: auto;
        }
        .output pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.6;
        }
        .error {
            color: #ff4444;
            background: #2d1e1e;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #ff4444;
        }
        .error pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #00ff00;
            color: #1e1e1e;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin-right: 10px;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #00cc00;
        }
        .btn-danger {
            background: #ff4444;
            color: white;
        }
        .btn-danger:hover {
            background: #cc0000;
        }
        .actions {
            text-align: center;
            margin-top: 20px;
        }
        .warning {
            background: #3d2d1e;
            border: 1px solid #ff9900;
            color: #ff9900;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-sync-alt"></i> DATABASE SYNCHRONIZATION SCRIPT</h1>
        
        <div class="warning">
            <strong>⚠ WARNING:</strong> This script has modified your database. Please verify the results below.
        </div>
        
        <div class="output">
            <pre><?php echo implode("\n", $output); ?></pre>
        </div>
        
        <?php if (!empty($errors)): ?>
        <div class="error">
            <pre><?php echo implode("\n", $errors); ?></pre>
        </div>
        <?php endif; ?>
        
        <div class="actions">
            <a href="patron_directory.php" class="btn">
                <i class="fas fa-users"></i> View Patron Directory
            </a>
            <a href="dashboard.php" class="btn">
                <i class="fas fa-tachometer-alt"></i> Go to Dashboard
            </a>
            <form method="POST" style="display: inline;" 
                  onsubmit="return confirm('Are you sure? This will run the sync again.')">
                <button type="submit" name="rerun" class="btn btn-danger">
                    <i class="fas fa-redo"></i> Run Sync Again
                </button>
            </form>
        </div>
    </div>
</body>
</html>