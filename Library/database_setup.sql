-- ============================================
-- IMPROVED LIBRARY SYSTEM DATABASE SETUP
-- Fixed collation issues and foreign key constraints
-- Version: 2.1 - Clean, No Duplicates
-- ============================================

-- Drop existing database if needed (CAREFUL - this deletes all data!)
-- DROP DATABASE IF EXISTS library_system;

-- Create Database with proper charset and collation
CREATE DATABASE IF NOT EXISTS library_system
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE library_system;

-- Set default charset for this session
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================
-- CORE TABLES
-- ============================================

-- Drop existing tables if they exist (to avoid conflicts)
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS login_history;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS user_borrowing_stats;
DROP TABLE IF EXISTS user_profiles;
DROP TABLE IF EXISTS fines;
DROP TABLE IF EXISTS book_reservations;
DROP TABLE IF EXISTS faculty_permissions;
DROP TABLE IF EXISTS borrowings;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS patrons;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS system_settings;

-- Users Table (Admin, Client, Faculty) - NO patron_id foreign key initially
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    role ENUM('admin', 'client', 'faculty') NOT NULL,
    patron_id INT DEFAULT NULL,
    reset_token VARCHAR(64),
    reset_expiry DATETIME,
    two_factor_secret VARCHAR(32),
    two_factor_enabled TINYINT(1) DEFAULT 0,
    last_login DATETIME,
    last_activity DATETIME,
    login_attempts INT DEFAULT 0,
    locked_until DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_patron_id (patron_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Patrons Table
CREATE TABLE patrons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(50) UNIQUE DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT '',
    address TEXT DEFAULT NULL,
    account_status ENUM('pending', 'registered', 'active', 'suspended') DEFAULT 'active',
    user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_student_id (student_id),
    INDEX idx_account_status (account_status),
    INDEX idx_user_id (user_id),
    UNIQUE KEY unique_email (email),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Now add the foreign key to users table AFTER patrons table exists
ALTER TABLE users
ADD CONSTRAINT fk_user_patron 
FOREIGN KEY (patron_id) REFERENCES patrons(id) 
ON DELETE SET NULL;

-- Books Table
CREATE TABLE books (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(100) NOT NULL,
    isbn VARCHAR(20) UNIQUE DEFAULT NULL,
    category VARCHAR(50) DEFAULT NULL,
    quantity INT DEFAULT 1,
    available INT DEFAULT 1,
    published_year INT DEFAULT NULL,
    publisher VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    tags VARCHAR(500) DEFAULT NULL,
    location VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_title (title),
    INDEX idx_author (author),
    INDEX idx_category (category),
    INDEX idx_isbn (isbn),
    INDEX idx_available (available),
    FULLTEXT KEY ft_search (title, author, isbn, tags)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Borrowing Records Table - WITH PENDING STATUS SUPPORT
CREATE TABLE borrowings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    book_id INT NOT NULL,
    patron_id INT NOT NULL,
    borrow_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE DEFAULT NULL,
    status ENUM('pending', 'borrowed', 'returned', 'overdue', 'lost', 'rejected') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_book_id (book_id),
    INDEX idx_patron_id (patron_id),
    INDEX idx_borrow_date (borrow_date),
    INDEX idx_due_date (due_date),
    INDEX idx_return_date (return_date),
    INDEX idx_status_pending (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- FACULTY & RESERVATION TABLES
-- ============================================

-- Faculty Permissions Table
CREATE TABLE faculty_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    can_search_books TINYINT(1) DEFAULT 1,
    can_borrow_books TINYINT(1) DEFAULT 1,
    can_view_own_history TINYINT(1) DEFAULT 1,
    can_make_reservations TINYINT(1) DEFAULT 1,
    max_borrow_limit INT DEFAULT 10,
    borrow_days_override INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_permission (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Book Reservations Table
CREATE TABLE book_reservations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    book_id INT NOT NULL,
    user_id INT NOT NULL,
    patron_id INT NOT NULL,
    reservation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATETIME NOT NULL,
    status ENUM('pending', 'ready', 'fulfilled', 'cancelled', 'expired') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    notified TINYINT(1) DEFAULT 0,
    notification_date DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_user_id (user_id),
    INDEX idx_book_id (book_id),
    INDEX idx_expiry_date (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- FINES & PENALTIES
-- ============================================

-- Fines Table
CREATE TABLE fines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    borrowing_id INT NOT NULL,
    patron_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(255) DEFAULT NULL,
    days_overdue INT DEFAULT 0,
    status ENUM('unpaid', 'paid', 'waived', 'partial') DEFAULT 'unpaid',
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    paid_date DATETIME DEFAULT NULL,
    waived_by INT DEFAULT NULL,
    waived_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (borrowing_id) REFERENCES borrowings(id) ON DELETE CASCADE,
    FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE CASCADE,
    FOREIGN KEY (waived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_patron_id (patron_id),
    INDEX idx_borrowing_id (borrowing_id),
    UNIQUE KEY unique_borrowing_fine (borrowing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ACTIVITY & LOGGING TABLES
-- ============================================

-- Activity Logs Table
CREATE TABLE activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login History Table
CREATE TABLE login_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT DEFAULT NULL,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    status ENUM('success', 'failed', 'blocked') NOT NULL,
    failure_reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_username (username),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Sessions Table
CREATE TABLE user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_id VARCHAR(128) UNIQUE NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    last_activity DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- STATISTICS & PROFILES
-- ============================================

-- User Profiles Table
CREATE TABLE user_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    profile_picture VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    membership_status ENUM('active', 'suspended', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_profile (user_id),
    INDEX idx_membership_status (membership_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Borrowing Statistics
CREATE TABLE user_borrowing_stats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    total_borrowed INT DEFAULT 0,
    currently_borrowed INT DEFAULT 0,
    total_returned INT DEFAULT 0,
    overdue_count INT DEFAULT 0,
    total_fines DECIMAL(10,2) DEFAULT 0.00,
    last_borrow_date DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_stats (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SYSTEM SETTINGS
-- ============================================

-- System Settings Table
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT DEFAULT NULL,
    category VARCHAR(50) DEFAULT 'general',
    is_editable TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, description, category) VALUES
('default_borrow_days', '14', 'number', 'Default borrowing period for students (days)', 'borrowing'),
('faculty_max_borrow_days', '30', 'number', 'Maximum borrowing period for faculty (days)', 'borrowing'),
('max_books_per_patron', '5', 'number', 'Maximum books a student can borrow', 'borrowing'),
('faculty_max_books', '10', 'number', 'Maximum books a faculty member can borrow', 'borrowing'),
('reservation_expiry_days', '3', 'number', 'Days until reservation expires', 'reservations'),
('fine_per_day', '5.00', 'number', 'Fine amount per day for overdue books (PHP)', 'fines'),
('max_fine_amount', '500.00', 'number', 'Maximum fine amount per book (PHP)', 'fines'),
('grace_period_days', '0', 'number', 'Grace period before fines start (days)', 'fines'),
('auto_calculate_fines', '1', 'boolean', 'Automatically calculate fines for overdue books', 'fines'),
('enable_reservations', '1', 'boolean', 'Enable book reservation system', 'features'),
('enable_email_notifications', '0', 'boolean', 'Send email notifications', 'features'),
('library_name', 'Library Management System', 'string', 'Name of the library', 'general'),
('library_email', 'library@example.com', 'string', 'Library contact email', 'general'),
('library_phone', '', 'string', 'Library contact phone', 'general'),
('max_login_attempts', '5', 'number', 'Maximum login attempts before lockout', 'security'),
('account_lockout_duration', '30', 'number', 'Account lockout duration (minutes)', 'security'),
('session_timeout', '30', 'number', 'Session timeout (minutes)', 'security');

-- ============================================
-- TRIGGERS (WITH PENDING STATUS SUPPORT)
-- ============================================

DELIMITER $$

-- Trigger: Update stats only when actually borrowed (NOT pending)
DROP TRIGGER IF EXISTS update_borrow_stats_on_borrow$$
CREATE TRIGGER update_borrow_stats_on_borrow
AFTER INSERT ON borrowings
FOR EACH ROW
BEGIN
    DECLARE user_email VARCHAR(100);
    DECLARE user_id_val INT;
    
    -- Only update stats when status is 'borrowed', not 'pending'
    IF NEW.status = 'borrowed' THEN
        SELECT email INTO user_email FROM patrons WHERE id = NEW.patron_id;
        SELECT id INTO user_id_val FROM users WHERE email = user_email LIMIT 1;
        
        IF user_id_val IS NOT NULL THEN
            INSERT INTO user_borrowing_stats (user_id, total_borrowed, currently_borrowed, last_borrow_date)
            VALUES (user_id_val, 1, 1, NOW())
            ON DUPLICATE KEY UPDATE
                total_borrowed = total_borrowed + 1,
                currently_borrowed = currently_borrowed + 1,
                last_borrow_date = NOW();
        END IF;
    END IF;
END$$

-- Trigger: Update stats when pending is approved to borrowed
DROP TRIGGER IF EXISTS update_stats_on_approval$$
CREATE TRIGGER update_stats_on_approval
AFTER UPDATE ON borrowings
FOR EACH ROW
BEGIN
    DECLARE user_email VARCHAR(100);
    DECLARE user_id_val INT;
    
    -- When status changes from pending to borrowed
    IF OLD.status = 'pending' AND NEW.status = 'borrowed' THEN
        SELECT email INTO user_email FROM patrons WHERE id = NEW.patron_id;
        SELECT id INTO user_id_val FROM users WHERE email = user_email LIMIT 1;
        
        IF user_id_val IS NOT NULL THEN
            INSERT INTO user_borrowing_stats (user_id, total_borrowed, currently_borrowed, last_borrow_date)
            VALUES (user_id_val, 1, 1, NOW())
            ON DUPLICATE KEY UPDATE
                total_borrowed = total_borrowed + 1,
                currently_borrowed = currently_borrowed + 1,
                last_borrow_date = NOW();
        END IF;
    END IF;
END$$

-- Trigger: Update stats when book is returned
DROP TRIGGER IF EXISTS update_borrow_stats_on_return$$
CREATE TRIGGER update_borrow_stats_on_return
AFTER UPDATE ON borrowings
FOR EACH ROW
BEGIN
    DECLARE user_email VARCHAR(100);
    DECLARE user_id_val INT;
    
    IF OLD.status IN ('borrowed', 'overdue') AND NEW.status = 'returned' THEN
        SELECT email INTO user_email FROM patrons WHERE id = NEW.patron_id;
        SELECT id INTO user_id_val FROM users WHERE email = user_email LIMIT 1;
        
        IF user_id_val IS NOT NULL THEN
            UPDATE user_borrowing_stats
            SET currently_borrowed = GREATEST(0, currently_borrowed - 1),
                total_returned = total_returned + 1
            WHERE user_id = user_id_val;
        END IF;
    END IF;
END$$

-- Trigger: Update overdue count
DROP TRIGGER IF EXISTS update_overdue_stats$$
CREATE TRIGGER update_overdue_stats
AFTER UPDATE ON borrowings
FOR EACH ROW
BEGIN
    DECLARE user_email VARCHAR(100);
    DECLARE user_id_val INT;
    
    IF OLD.status != 'overdue' AND NEW.status = 'overdue' THEN
        SELECT email INTO user_email FROM patrons WHERE id = NEW.patron_id;
        SELECT id INTO user_id_val FROM users WHERE email = user_email LIMIT 1;
        
        IF user_id_val IS NOT NULL THEN
            UPDATE user_borrowing_stats
            SET overdue_count = overdue_count + 1
            WHERE user_id = user_id_val;
        END IF;
    ELSEIF OLD.status = 'overdue' AND NEW.status = 'returned' THEN
        SELECT email INTO user_email FROM patrons WHERE id = NEW.patron_id;
        SELECT id INTO user_id_val FROM users WHERE email = user_email LIMIT 1;
        
        IF user_id_val IS NOT NULL THEN
            UPDATE user_borrowing_stats
            SET overdue_count = GREATEST(0, overdue_count - 1)
            WHERE user_id = user_id_val;
        END IF;
    END IF;
END$$

-- Trigger: Create faculty permissions automatically
DROP TRIGGER IF EXISTS create_faculty_permissions$$
CREATE TRIGGER create_faculty_permissions
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.role = 'faculty' THEN
        INSERT INTO faculty_permissions (
            user_id, 
            can_search_books, 
            can_borrow_books, 
            can_view_own_history, 
            can_make_reservations, 
            max_borrow_limit
        )
        VALUES (NEW.id, 1, 1, 1, 1, 10)
        ON DUPLICATE KEY UPDATE user_id = NEW.id;
    END IF;
END$$

-- Trigger: Check reservations on book return
DROP TRIGGER IF EXISTS check_reservations_on_return$$
CREATE TRIGGER check_reservations_on_return
AFTER UPDATE ON borrowings
FOR EACH ROW
BEGIN
    IF OLD.status != 'returned' AND NEW.status = 'returned' THEN
        -- Update the first pending reservation to ready
        UPDATE book_reservations
        SET status = 'ready', 
            updated_at = NOW(),
            notified = 0
        WHERE book_id = NEW.book_id 
        AND status = 'pending'
        ORDER BY reservation_date ASC
        LIMIT 1;
    END IF;
END$$

-- Trigger: Update book availability only when approved (NOT pending)
DROP TRIGGER IF EXISTS update_book_availability_on_borrow$$
CREATE TRIGGER update_book_availability_on_borrow
AFTER INSERT ON borrowings
FOR EACH ROW
BEGIN
    -- Only decrease availability when status is 'borrowed', not 'pending'
    IF NEW.status = 'borrowed' THEN
        UPDATE books 
        SET available = GREATEST(0, available - 1)
        WHERE id = NEW.book_id;
    END IF;
END$$

-- Trigger: Handle book availability on status changes
DROP TRIGGER IF EXISTS update_book_availability_on_status_change$$
CREATE TRIGGER update_book_availability_on_status_change
AFTER UPDATE ON borrowings
FOR EACH ROW
BEGIN
    -- When status changes from pending to borrowed, decrease availability
    IF OLD.status = 'pending' AND NEW.status = 'borrowed' THEN
        UPDATE books 
        SET available = GREATEST(0, available - 1)
        WHERE id = NEW.book_id;
    END IF;
    
    -- When book is returned, increase availability
    IF OLD.status IN ('borrowed', 'overdue') AND NEW.status = 'returned' THEN
        UPDATE books 
        SET available = LEAST(quantity, available + 1)
        WHERE id = NEW.book_id;
    END IF;
    
    -- When pending is rejected, don't touch availability (it was never decreased)
END$$

DELIMITER ;

-- ============================================
-- HELPER VIEWS
-- ============================================

-- View: Current borrowings with book details (including pending)
DROP VIEW IF EXISTS view_current_borrowings;

CREATE VIEW view_current_borrowings AS
SELECT 
    b.id as borrowing_id,
    b.borrow_date,
    b.due_date,
    b.status,
    b.approved_by,
    DATEDIFF(b.due_date, CURDATE()) as days_until_due,
    DATEDIFF(CURDATE(), b.due_date) as days_overdue,
    bk.id as book_id,
    bk.title,
    bk.author,
    bk.isbn,
    bk.category,
    p.id as patron_id,
    p.name as patron_name,
    p.email as patron_email,
    u.id as user_id,
    u.full_name as user_name,
    u.role as user_role,
    approver.full_name as approved_by_name
FROM borrowings b
JOIN books bk ON b.book_id = bk.id
JOIN patrons p ON b.patron_id = p.id
LEFT JOIN users u ON p.user_id = u.id
LEFT JOIN users approver ON b.approved_by = approver.id
WHERE b.status IN ('pending', 'borrowed', 'overdue');

-- View: Pending faculty requests
DROP VIEW IF EXISTS view_pending_faculty_requests;

CREATE VIEW view_pending_faculty_requests AS
SELECT 
    b.id as borrowing_id,
    b.borrow_date,
    b.due_date,
    b.created_at as request_date,
    b.notes,
    bk.id as book_id,
    bk.title as book_title,
    bk.author as book_author,
    bk.isbn,
    bk.category,
    bk.available,
    bk.quantity,
    p.id as patron_id,
    p.name as patron_name,
    p.email as patron_email,
    p.phone as patron_phone,
    u.id as user_id,
    u.full_name as user_name,
    u.role as user_role,
    u.email as user_email
FROM borrowings b
JOIN books bk ON b.book_id = bk.id
JOIN patrons p ON b.patron_id = p.id
LEFT JOIN users u ON p.user_id = u.id
WHERE b.status = 'pending' AND u.role = 'faculty'
ORDER BY b.created_at ASC;

-- View: Overdue books
DROP VIEW IF EXISTS view_overdue_books;

CREATE VIEW view_overdue_books AS
SELECT 
    b.id as borrowing_id,
    b.borrow_date,
    b.due_date,
    DATEDIFF(CURDATE(), b.due_date) as days_overdue,
    bk.title,
    bk.author,
    p.name as patron_name,
    p.email as patron_email,
    p.phone as patron_phone,
    u.full_name as user_name,
    u.role as user_role,
    COALESCE(f.amount, 0) as fine_amount,
    f.status as fine_status
FROM borrowings b
JOIN books bk ON b.book_id = bk.id
JOIN patrons p ON b.patron_id = p.id
LEFT JOIN users u ON p.user_id = u.id
LEFT JOIN fines f ON b.id = f.borrowing_id
WHERE b.status = 'overdue'
ORDER BY days_overdue DESC;

-- View: Book availability summary
DROP VIEW IF EXISTS view_book_availability;

CREATE VIEW view_book_availability AS
SELECT 
    id,
    title,
    author,
    category,
    quantity,
    available,
    (quantity - available) as borrowed_count,
    CASE 
        WHEN available = 0 THEN 'Unavailable'
        WHEN available <= (quantity * 0.2) THEN 'Low Stock'
        ELSE 'Available'
    END as availability_status
FROM books;

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER $$

-- Procedure: Get faculty permissions
DROP PROCEDURE IF EXISTS getFacultyPermissions$$
CREATE PROCEDURE getFacultyPermissions(IN p_user_id INT)
BEGIN
    SELECT 
        fp.*,
        u.username,
        u.full_name,
        u.email,
        u.role
    FROM faculty_permissions fp
    JOIN users u ON fp.user_id = u.id
    WHERE fp.user_id = p_user_id;
END$$

-- Procedure: Check if patron can borrow
DROP PROCEDURE IF EXISTS checkPatronCanBorrow$$
CREATE PROCEDURE checkPatronCanBorrow(
    IN p_patron_id INT,
    OUT can_borrow BOOLEAN,
    OUT current_count INT,
    OUT max_limit INT,
    OUT has_overdue BOOLEAN,
    OUT unpaid_fines DECIMAL(10,2)
)
BEGIN
    DECLARE user_role VARCHAR(20);
    
    -- Get user role
    SELECT u.role INTO user_role
    FROM patrons p
    JOIN users u ON p.user_id = u.id
    WHERE p.id = p_patron_id
    LIMIT 1;
    
    -- Get max limit based on role
    IF user_role = 'faculty' THEN
        SELECT COALESCE(
            (SELECT max_borrow_limit FROM faculty_permissions WHERE user_id = (SELECT user_id FROM patrons WHERE id = p_patron_id)),
            10
        ) INTO max_limit;
    ELSE
        SELECT CAST(setting_value AS UNSIGNED) INTO max_limit
        FROM system_settings 
        WHERE setting_key = 'max_books_per_patron';
    END IF;
    
    -- Get current count (including pending)
    SELECT COUNT(*) INTO current_count
    FROM borrowings
    WHERE patron_id = p_patron_id 
    AND status IN ('borrowed', 'overdue', 'pending');
    
    -- Check for overdue books
    SELECT COUNT(*) > 0 INTO has_overdue
    FROM borrowings
    WHERE patron_id = p_patron_id 
    AND status = 'overdue';
    
    -- Get unpaid fines
    SELECT COALESCE(SUM(amount - amount_paid), 0) INTO unpaid_fines
    FROM fines
    WHERE patron_id = p_patron_id 
    AND status IN ('unpaid', 'partial');
    
    -- Determine if can borrow
    SET can_borrow = (current_count < max_limit) AND NOT has_overdue AND (unpaid_fines = 0);
END$$

DELIMITER ;

-- ============================================
-- VERIFICATION & COMPLETION
-- ============================================

SELECT '✓ Database structure created successfully' as status;
SELECT '✓ All tables use utf8mb4_unicode_ci collation' as status;
SELECT '✓ Pending approval workflow configured' as status;
SELECT '✓ All tables, triggers, views, and procedures created' as status;
SELECT '✓ Default settings configured' as status;
SELECT '' as status;
SELECT 'Database is ready to use!' as message;

-- Show table summary
SELECT 
    TABLE_NAME as 'Table',
    TABLE_COLLATION as 'Collation',
    TABLE_ROWS as 'Rows',
    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as 'Size (MB)'
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'library_system'
ORDER BY TABLE_NAME;

-- Show borrowings status values
SHOW COLUMNS FROM borrowings LIKE 'status';

SELECT '' as '';
SELECT '===================================================' as '';
SELECT 'NEXT STEPS:' as '';
SELECT '1. Update config/config.php: utf8 → utf8mb4' as '';
SELECT '2. Update borrow_request.php: Remove COLLATE clauses' as '';
SELECT '3. Test workflow: Faculty borrow → Admin approve' as '';
SELECT '===================================================' as '';
SELECT

ALTER TABLE `patrons` 
ADD COLUMN `role` ENUM('client', 'faculty') DEFAULT 'client' 
AFTER `address`;