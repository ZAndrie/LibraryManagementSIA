<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
checkRole('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Books (API) - Library System</title>
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
        .navbar h1 { 
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .api-badge {
            background: #28a745;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .navbar .nav-links {
            display: flex;
            gap: 15px;
        }
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
            font-size: 14px;
        }
        .btn:hover { background: grey; }
        .btn-primary {
            background: black;
            border: none;
        }
        .btn-success {
            background: #28a745;
            border: none;
        }
        .btn-danger {
            background: #dc3545;
            border: none;
        }
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .search-box {
            display: flex;
            gap: 10px;
        }
        .search-box input {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            width: 300px;
        }
        
        /* Loading State */
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .loading .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid black;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .actions {
            display: flex;
            gap: 8px;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: none;
        }
        .alert.show {
            display: block;
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
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: #e7f3ff;
            border-radius: 8px;
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #333;
            font-size: 14px;
        }
        .stat-item strong {
            color: black;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>
            <i class="fas fa-book"></i> Manage Books
            <span class="api-badge">API Powered</span>
        </h1>
        <div class="nav-links">
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="../auth/logout.php" class="btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <!-- Alert Messages -->
        <div id="alertSuccess" class="alert alert-success"></div>
        <div id="alertError" class="alert alert-error"></div>

        <!-- Stats Bar -->
        <div id="statsBar" class="stats-bar" style="display: none;">
            <div class="stat-item">
                <i class="fas fa-book"></i>
                <span>Total: <strong id="statTotal">0</strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Available: <strong id="statAvailable">0</strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-tag"></i>
                <span>Categories: <strong id="statCategories">0</strong></span>
            </div>
        </div>

        <div class="card">
            <div class="header-section">
                <h2>Book List</h2>
                <div style="display: flex; gap: 10px;">
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Search by title or author..." 
                               autocomplete="off">
                        <button onclick="searchBooks()" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                    <a href="add_book.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add New Book
                    </a>
                </div>
            </div>

            <!-- Loading State -->
            <div id="loadingState" class="loading">
                <div class="spinner"></div>
                <p>Loading books via API...</p>
            </div>

            <!-- Books Table -->
            <div id="booksTableContainer" style="display: none;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>ISBN</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Available</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="booksTableBody">
                        <!-- Books will be inserted here by JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- No Results -->
            <div id="noResults" class="no-results" style="display: none;">
                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
                <h3>No books found</h3>
                <p>Click "Add New Book" to get started</p>
            </div>
        </div>
    </div>

    <script>
        // State
        let allBooks = [];

        // Load books on page load
        window.addEventListener('load', () => {
            loadAllBooks();
            loadStats();
        });

        // Enter key search
        document.getElementById('searchInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                searchBooks();
            }
        });

        // Load all books from API
        async function loadAllBooks() {
            showLoading(true);
            hideAlerts();

            try {
                const response = await fetch('../api/index.php?module=books&action=get_all_books');
                const data = await response.json();

                if (data.success) {
                    allBooks = data.data.books;
                    displayBooks(allBooks);
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showError('Failed to load books: ' + error.message);
            } finally {
                showLoading(false);
            }
        }

        // Search books
        async function searchBooks() {
            const query = document.getElementById('searchInput').value.trim();
            
            if (!query) {
                displayBooks(allBooks);
                return;
            }

            showLoading(true);
            hideAlerts();

            try {
                const response = await fetch(`../api/index.php?module=books&action=search&search=${encodeURIComponent(query)}`);
                const data = await response.json();

                if (data.success) {
                    displayBooks(data.data);
                } else {
                    showError(data.message);
                    displayBooks([]);
                }
            } catch (error) {
                showError('Search failed: ' + error.message);
            } finally {
                showLoading(false);
            }
        }

        // Display books in table
        function displayBooks(books) {
            const tbody = document.getElementById('booksTableBody');
            const tableContainer = document.getElementById('booksTableContainer');
            const noResults = document.getElementById('noResults');

            tbody.innerHTML = '';

            if (books.length === 0) {
                tableContainer.style.display = 'none';
                noResults.style.display = 'block';
                return;
            }

            tableContainer.style.display = 'block';
            noResults.style.display = 'none';

            books.forEach(book => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${book.id}</td>
                    <td><strong>${escapeHtml(book.title)}</strong></td>
                    <td>${escapeHtml(book.author)}</td>
                    <td>${escapeHtml(book.isbn || '-')}</td>
                    <td>${escapeHtml(book.category || '-')}</td>
                    <td>${book.quantity}</td>
                    <td>${book.available}</td>
                    <td>
                        <div class="actions">
                            <a href="edit_book.php?id=${book.id}" class="btn btn-warning" title="Edit">
                                <i class="fas fa-pen"></i>
                            </a>
                            <button onclick="deleteBook(${book.id}, '${escapeHtml(book.title)}')" 
                                    class="btn btn-danger" title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // Delete book
        async function deleteBook(id, title) {
            if (!confirm(`Are you sure you want to delete "${title}"?`)) {
                return;
            }

            try {
                const response = await fetch('../api/index.php?module=books&action=delete_book', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: id })
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess(`Book "${title}" deleted successfully!`);
                    // Remove from local array
                    allBooks = allBooks.filter(book => book.id !== id);
                    displayBooks(allBooks);
                    loadStats(); // Refresh stats
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showError('Failed to delete book: ' + error.message);
            }
        }

        // Load statistics
        async function loadStats() {
            try {
                const response = await fetch('../api/index.php?module=books&action=get_stats');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('statTotal').textContent = data.data.total_books;
                    document.getElementById('statAvailable').textContent = data.data.available_copies;
                    document.getElementById('statCategories').textContent = data.data.categories_count;
                    document.getElementById('statsBar').style.display = 'flex';
                }
            } catch (error) {
                console.error('Failed to load stats:', error);
            }
        }

        // UI Helper Functions
        function showLoading(show) {
            document.getElementById('loadingState').style.display = show ? 'block' : 'none';
        }

        function showSuccess(message) {
            const alert = document.getElementById('alertSuccess');
            alert.textContent = message;
            alert.classList.add('show');
            setTimeout(() => alert.classList.remove('show'), 5000);
        }

        function showError(message) {
            const alert = document.getElementById('alertError');
            alert.textContent = message;
            alert.classList.add('show');
            setTimeout(() => alert.classList.remove('show'), 5000);
        }

        function hideAlerts() {
            document.getElementById('alertSuccess').classList.remove('show');
            document.getElementById('alertError').classList.remove('show');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>