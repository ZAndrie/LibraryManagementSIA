-- STAR SCHEMA SETUP for Library Analytics
-- File: database_star_schema.sql
-- Usage: run this against your MySQL/MariaDB database connected to the Library schema.
-- It creates dimension and fact tables, populates them from existing operational tables,
-- and provides sample analytics queries.

SET FOREIGN_KEY_CHECKS = 0;

-- DIMENSION: Date
DROP TABLE IF EXISTS dim_date;
CREATE TABLE dim_date (
  date_id INT AUTO_INCREMENT PRIMARY KEY,
  dt DATE NOT NULL UNIQUE,
  year INT,
  quarter INT,
  month INT,
  day INT,
  day_of_week INT,
  is_weekend TINYINT(1)
) ENGINE=InnoDB;

-- DIMENSION: Book (surrogate key + natural key)
DROP TABLE IF EXISTS dim_book;
CREATE TABLE dim_book (
  book_key INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT, -- natural key from books.id
  isbn VARCHAR(64),
  title VARCHAR(1024),
  author VARCHAR(512),
  category VARCHAR(255),
  published_year INT,
  UNIQUE KEY uk_book_natural (COALESCE(isbn, ''), COALESCE(title, ''), COALESCE(author, ''))
) ENGINE=InnoDB;

-- DIMENSION: Patron
DROP TABLE IF EXISTS dim_patron;
CREATE TABLE dim_patron (
  patron_key INT AUTO_INCREMENT PRIMARY KEY,
  patron_id INT, -- natural key from patrons.id
  name VARCHAR(512),
  email VARCHAR(255),
  account_status VARCHAR(50)
) ENGINE=InnoDB;

-- DIMENSION: User (staff/admin) - for actions like approved_by
DROP TABLE IF EXISTS dim_user;
CREATE TABLE dim_user (
  user_key INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT, -- natural key from users.id
  full_name VARCHAR(255),
  email VARCHAR(255),
  role VARCHAR(50)
) ENGINE=InnoDB;

-- FACT: Borrowings
DROP TABLE IF EXISTS fact_borrowings;
CREATE TABLE fact_borrowings (
  borrowing_key BIGINT AUTO_INCREMENT PRIMARY KEY,
  borrowing_id INT, -- natural id from borrowings.id
  borrow_date_id INT,
  due_date_id INT,
  return_date_id INT,
  book_key INT,
  patron_key INT,
  user_key INT NULL,
  status VARCHAR(50),
  fine_amount DECIMAL(10,2) DEFAULT 0,
  days_overdue INT NULL,
  days_borrowed INT NULL,
  created_at TIMESTAMP NULL,
  INDEX (borrow_date_id),
  INDEX (book_key),
  INDEX (patron_key),
  INDEX (user_key),
  FOREIGN KEY (borrow_date_id) REFERENCES dim_date(date_id),
  FOREIGN KEY (due_date_id) REFERENCES dim_date(date_id),
  FOREIGN KEY (return_date_id) REFERENCES dim_date(date_id),
  FOREIGN KEY (book_key) REFERENCES dim_book(book_key),
  FOREIGN KEY (patron_key) REFERENCES dim_patron(patron_key),
  FOREIGN KEY (user_key) REFERENCES dim_user(user_key)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ===================================================================
-- ETL: Populate dimensions from operational tables
-- Run these once, then schedule/refresh as needed.
-- ===================================================================

-- 1) Populate dim_date from borrowings (borrow_date, due_date, return_date)
INSERT IGNORE INTO dim_date (dt)
SELECT DISTINCT borrow_date FROM borrowings WHERE borrow_date IS NOT NULL;
INSERT IGNORE INTO dim_date (dt)
SELECT DISTINCT due_date FROM borrowings WHERE due_date IS NOT NULL;
INSERT IGNORE INTO dim_date (dt)
SELECT DISTINCT return_date FROM borrowings WHERE return_date IS NOT NULL;

-- Fill date attributes
UPDATE dim_date SET
  year = YEAR(dt),
  quarter = QUARTER(dt),
  month = MONTH(dt),
  day = DAY(dt),
  day_of_week = DAYOFWEEK(dt),
  is_weekend = CASE WHEN DAYOFWEEK(dt) IN (1,7) THEN 1 ELSE 0 END
WHERE year IS NULL;

-- 2) Populate dim_book
INSERT IGNORE INTO dim_book (book_id, isbn, title, author, category, published_year)
SELECT id, isbn, title, author, category, published_year FROM books;

-- 3) Populate dim_patron
INSERT IGNORE INTO dim_patron (patron_id, name, email, account_status)
SELECT id, name, email, account_status FROM patrons;

-- 4) Populate dim_user
INSERT IGNORE INTO dim_user (user_id, full_name, email, role)
SELECT id, full_name, email, role FROM users;

-- ===================================================================
-- ETL: Populate fact table
-- Note: This inserts borrowings facts. Re-run logic (upsert) as new borrowings are created.
-- ===================================================================

-- Optionally clear existing facts (uncomment if starting fresh)
-- TRUNCATE TABLE fact_borrowings;

INSERT INTO fact_borrowings (
  borrowing_id, borrow_date_id, due_date_id, return_date_id, book_key, patron_key, user_key,
  status, fine_amount, days_overdue, days_borrowed, created_at
)
SELECT
  br.id AS borrowing_id,
  (SELECT date_id FROM dim_date d WHERE d.dt = br.borrow_date LIMIT 1) AS borrow_date_id,
  (SELECT date_id FROM dim_date d WHERE d.dt = br.due_date LIMIT 1) AS due_date_id,
  (SELECT date_id FROM dim_date d WHERE d.dt = br.return_date LIMIT 1) AS return_date_id,
  (SELECT book_key FROM dim_book b WHERE b.book_id = br.book_id LIMIT 1) AS book_key,
  (SELECT patron_key FROM dim_patron p WHERE p.patron_id = br.patron_id LIMIT 1) AS patron_key,
  (SELECT user_key FROM dim_user u WHERE u.user_id = br.approved_by LIMIT 1) AS user_key,
  br.status,
  COALESCE((SELECT SUM(amount) FROM fines f WHERE f.borrowing_id = br.id), 0) AS fine_amount,
  CASE WHEN br.return_date IS NOT NULL THEN GREATEST(0, DATEDIFF(br.return_date, br.due_date)) ELSE NULL END AS days_overdue,
  CASE WHEN br.return_date IS NOT NULL THEN DATEDIFF(br.return_date, br.borrow_date) ELSE DATEDIFF(CURDATE(), br.borrow_date) END AS days_borrowed,
  br.created_at
FROM borrowings br
-- Avoid duplicates: only insert when not already present
WHERE NOT EXISTS (SELECT 1 FROM fact_borrowings fb WHERE fb.borrowing_id = br.id);

-- ===================================================================
-- SAMPLE ANALYTICS QUERIES (STAR schema style)
-- ===================================================================

-- 1) Monthly borrows (last 12 months)
-- Returns month, year, total borrows
SELECT CONCAT(d.year, '-', LPAD(d.month,2,'0')) AS year_month,
       COUNT(*) AS total_borrows
FROM fact_borrowings f
JOIN dim_date d ON f.borrow_date_id = d.date_id
WHERE d.dt >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY d.year, d.month
ORDER BY d.year DESC, d.month DESC;

-- 2) Top 10 most borrowed books (all time)
SELECT b.title, b.author, SUM(1) AS times_borrowed
FROM fact_borrowings f
JOIN dim_book b ON f.book_key = b.book_key
GROUP BY b.book_key
ORDER BY times_borrowed DESC
LIMIT 10;

-- 3) Patron borrowing trend (example patron_id = 123)
SELECT CONCAT(d.year,'-',LPAD(d.month,2,'0')) AS year_month, COUNT(*) AS borrows
FROM fact_borrowings f
JOIN dim_date d ON f.borrow_date_id = d.date_id
JOIN dim_patron p ON f.patron_key = p.patron_key
WHERE p.patron_id = 123 AND d.dt >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY d.year, d.month
ORDER BY d.year, d.month;

-- 4) Average days borrowed by category
SELECT b.category, ROUND(AVG(f.days_borrowed),1) AS avg_days_borrowed, COUNT(*) AS sample_size
FROM fact_borrowings f
JOIN dim_book b ON f.book_key = b.book_key
WHERE f.days_borrowed IS NOT NULL
GROUP BY b.category
ORDER BY avg_days_borrowed DESC;

-- 5) Overdue rate per month
SELECT CONCAT(d.year,'-',LPAD(d.month,2,'0')) AS year_month,
  SUM(CASE WHEN f.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count,
  COUNT(*) AS total_borrows,
  ROUND(SUM(CASE WHEN f.status = 'overdue' THEN 1 ELSE 0 END)/COUNT(*)*100,2) AS overdue_pct
FROM fact_borrowings f
JOIN dim_date d ON f.borrow_date_id = d.date_id
WHERE d.dt >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY d.year, d.month
ORDER BY d.year DESC, d.month DESC;

-- ===================================================================
-- NOTES / Next steps
-- - Schedule ETL loads: populate dims regularly and upsert fact table when new borrowings arrive.
-- - Consider incremental date range population for dim_date (the approach above uses distinct from borrowings).
-- - For Data Analytics demo: create views built on these joins for dashboards (e.g., view_monthly_borrows, view_top_books).
-- - If you want, I can add SQL to create these views and a small README explaining how to run the ETL.
-- ===================================================================
