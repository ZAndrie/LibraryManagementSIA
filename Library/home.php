<?php
require_once 'config/config.php';

// Check if user is already logged in
$is_logged_in = isset($_SESSION['user_id']);
$user_role = $is_logged_in ? $_SESSION['role'] : null;
$user_name = $is_logged_in ? $_SESSION['full_name'] : null;

// Get statistics for display
$total_books = $conn->query("SELECT COUNT(*) as total FROM books")->fetch_assoc()['total'];
$total_available = $conn->query("SELECT SUM(available) as total FROM books")->fetch_assoc()['total'];
$total_patrons = $conn->query("SELECT COUNT(*) as total FROM patrons")->fetch_assoc()['total'];
$total_borrowed = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status = 'borrowed'")->fetch_assoc()['total'];

// Get featured books (most recently added)
$featured_books = $conn->query("SELECT * FROM books WHERE available > 0 ORDER BY id DESC LIMIT 3");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Library Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #000000ff 0%, white 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .navbar {
            background: black;
            backdrop-filter: blur(10px);
            padding: 20px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .navbar .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            font-size: 28px;
            font-weight: bold;
        }

        .navbar .nav-links {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            color: white;
            background: rgba(255,255,255,0.2);
            border: 2px solid rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            display: inline-block;
        }

        .btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }

        .btn-primary {
            background: white;
            color: black;
            border: none;
        }

        .btn-primary:hover {
            background: #f0f0f0;
        }

        .btn-success {
            background: #27ae60;
            border: none;
        }

        .btn-success:hover {
            background: #229954;
        }
        
        .btn-faculty {
            background: grey;
            border: 1px solid white;
        }

        .btn-faculty:hover {
            background: lightgrey;
        }

        .hero-section {
            text-align: center;
            padding: 100px 20px;
            color: white;
        }

        .hero-section h1 {
            font-size: 56px;
            margin-bottom: 20px;
            animation: fadeInUp 1s ease;
        }

        .hero-section p {
            font-size: 20px;
            margin-bottom: 40px;
            opacity: 0.9;
            animation: fadeInUp 1.2s ease;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeInUp 1.4s ease;
        }

        .hero-buttons .btn {
            padding: 15px 35px;
            font-size: 18px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .features-section {
            background: white;
            padding: 80px 50px;
        }

        .features-section h2 {
            text-align: center;
            font-size: 42px;
            color: #333;
            margin-bottom: 60px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .feature-card {
            background: linear-gradient(135deg, black 0%, black 100%);
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            color: white;
            transition: transform 0.3s;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .feature-card:hover {
            transform: translateY(-10px);
        }

        .feature-card .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .feature-card h3 {
            font-size: 24px;
            margin-bottom: 15px;
        }

        .feature-card p {
            font-size: 16px;
            opacity: 0.9;
        }

        .stats-section {
            background: linear-gradient(135deg, black 0%, black 100%);
            padding: 80px 50px;
            color: white;
        }

        .stats-section h2 {
            text-align: center;
            font-size: 42px;
            margin-bottom: 60px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .stat-card {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 30px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
        }

        .stat-card .number {
            font-size: 56px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .stat-card .label {
            font-size: 18px;
            opacity: 0.9;
        }

        .books-section {
            background: #f5f6fa;
            padding: 80px 50px;
        }

        .books-section h2 {
            text-align: center;
            font-size: 42px;
            color: #333;
            margin-bottom: 60px;
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .book-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .book-card:hover {
            transform: translateY(-5px);
        }

        .book-card h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 22px;
        }

        .book-card .author {
            color: #666;
            margin-bottom: 15px;
        }

        .book-card .category {
            display: inline-block;
            padding: 5px 15px;
            background: #667eea;
            color: white;
            border-radius: 15px;
            font-size: 12px;
            margin-bottom: 15px;
        }

        .book-card .availability {
            color: #27ae60;
            font-weight: 600;
        }

        .footer {
            background: rgba(0, 0, 0, 0.3);
            color: white;
            text-align: center;
            padding: 30px;
        }

        .welcome-banner {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            margin: 20px 50px;
            border-radius: 10px;
            color: white;
            text-align: center;
            animation: fadeInUp 0.8s ease;
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 15px 20px;
                flex-direction: column;
                gap: 15px;
            }

            .hero-section h1 {
                font-size: 36px;
            }

            .features-section, .stats-section, .books-section {
                padding: 50px 20px;
            }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <div class="logo">
        <i class="fa fa-book"></i>Library System
    </div>
    <div class="nav-links">
        <?php if ($is_logged_in): ?>
            <span style="color: white;">Welcome, <?php echo htmlspecialchars($user_name); ?></span>
            <?php if ($user_role == 'admin'): ?>
                <a href="admin/dashboard.php" class="btn">Dashboard</a>
                <a href="auth/logout.php" class="btn">Logout</a>
            <?php elseif ($user_role == 'faculty'): ?>
                <a href="faculty/dashboard.php" class="btn btn-faculty">Dashboard</a>
                <a href="auth/logout.php" class="btn">Logout</a>
            <?php elseif ($user_role == 'client'): ?>
                <a href="client/dashboard.php" class="btn">Dashboard</a>
                <a href="auth/logout.php" class="btn">Logout</a>
            <?php elseif ($user_role == 'guest'): ?>
                <a href="client/dashboard.php" class="btn">Dashboard</a>
                <a href="auth/register.php" class="btn btn-success">Create Account</a>
            <?php endif; ?>
        <?php else: ?>
            <a href="auth/login.php" class="btn btn-primary">Login</a>
            <a href="auth/register.php" class="btn">Register</a>
        <?php endif; ?>
    </div>
</nav>

    <?php if ($is_logged_in): ?>
        <div class="welcome-banner">
            <h3>
                <?php 
                if ($user_role == 'admin') {
                    echo "Welcome back, Administrator!";
                } elseif ($user_role == 'faculty') {
                    echo "Welcome back, Faculty Member!";
                } elseif ($user_role == 'guest') {
                    echo "Welcome, Guest! Create an account to unlock more features.";
                } else {
                    echo "Welcome back to your Library!";
                }
                ?>
            </h3>
        </div>
    <?php endif; ?>

    <section class="hero-section">
        <h1>Welcome to Our Library</h1>
        <p>Discover thousands of books, manage your reading journey, and explore new worlds of knowledge.</p>
        <div class="hero-buttons">
    <?php if (!$is_logged_in): ?>
        <a href="auth/login.php?guest=1" class="btn btn-success">🚀 Browse as Guest</a>
        <a href="auth/register.php" class="btn btn-primary">Create Account</a>
    <?php else: ?>
        <?php if ($user_role == 'admin'): ?>
            <a href="admin/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
            <a href="admin/books.php" class="btn">Manage Books</a>
            <a href="admin/borrowing.php" class="btn">Borrowing System</a>
        <?php elseif ($user_role == 'faculty'): ?>
            <a href="faculty/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
            <a href="faculty/search_books.php" class="btn btn-faculty">Search Books</a>
            <a href="faculty/my_borrowings.php" class="btn">My Borrowings</a>
        <?php elseif ($user_role == 'client'): ?>
            <a href="client/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
            <a href="client/search_books.php" class="btn">Search Books</a>
        <?php elseif ($user_role == 'guest'): ?>
            <a href="client/dashboard.php" class="btn btn-primary">Browse Books</a>
            <a href="client/search_books.php" class="btn">Search Books</a>
            <a href="auth/register.php" class="btn btn-success">Create Account</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
    </section>

    <section class="features-section">
        <h2>Why Choose Our Library?</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="icon"><i class="fa fa-book"></i></div>
                <h3>Vast Collection</h3>
                <p>Access thousands of books across multiple genres and categories.</p>
            </div>
            <div class="feature-card">
                <div class="icon"><i class="fa fa-search"></i></div>
                <h3>Easy Search</h3>
                <p>Find your favorite books quickly with our advanced search system.</p>
            </div>
            <div class="feature-card">
                <div class="icon"><i class="fa fa-paper-plane"></i></div>
                <h3>Quick Access</h3>
                <p>Browse books instantly as a guest or create an account for more features.</p>
            </div>
            <div class="feature-card">
                <div class="icon"><i class="fa fa-bookmark"></i></div>
                <h3>Track Records</h3>
                <p>Keep track of borrowed books and manage your reading history.</p>
            </div>
            <div class="feature-card">
                <div class="icon"><i class="fa fa-lock"></i></div>
                <h3>Secure System</h3>
                <p>Your data is protected with advanced security measures.</p>
            </div>
            <div class="feature-card">
                <div class="icon"><i class="fa fa-briefcase"></i></div>
                <h3>Admin Control</h3>
                <p>Powerful management tools for librarians and administrators.</p>
            </div>
        </div>
    </section>

    <section class="stats-section">
        <h2>Library Statistics</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $total_books; ?></div>
                <div class="label">Total Books</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $total_available; ?></div>
                <div class="label">Available Copies</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $total_patrons; ?></div>
                <div class="label">Registered Patrons</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $total_borrowed; ?></div>
                <div class="label">Currently Borrowed</div>
            </div>
        </div>
    </section>

    <section class="books-section">
        <h2>Featured Books</h2>
        <div class="books-grid">
            <?php if ($featured_books->num_rows > 0): ?>
                <?php while($book = $featured_books->fetch_assoc()): ?>
                    <div class="book-card">
                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                        <div class="author">by <?php echo htmlspecialchars($book['author']); ?></div>
                        <?php if ($book['category']): ?>
                            <span class="category"><?php echo htmlspecialchars($book['category']); ?></span>
                        <?php endif; ?>
                        <div class="availability">
                            ✓ <?php echo $book['available']; ?> copies available
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="text-align: center; color: #666; grid-column: 1/-1;">No books available at the moment.</p>
            <?php endif; ?>
        </div>
    </section>

    <footer class="footer">
        <p>&copy; 2025 Library Management System. All rights reserved.</p>
        <p>Empowering readers, one book at a time.</p>
    </footer>
</body>
</html>