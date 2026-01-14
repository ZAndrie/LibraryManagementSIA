<?php
require_once '../auth/check_auth.php';
checkRole('client');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Books (API Version) - Library System</title>
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
        .navbar .api-badge {
            background: #28a745;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
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
        }
        .btn:hover {
            background: rgba(255,255,255,0.3);
        }
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .search-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        /* Search Form */
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .search-form input {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        .search-form input:focus {
            outline: none;
            border-color: black;
        }
        .btn-search {
            padding: 12px 24px;
            background: grey;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-search:hover {
            background: darkgrey;
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
        
        /* Results Info */
        .results-info {
            margin-bottom: 20px;
            padding: 15px;
            background: #e7f3ff;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .results-info .count {
            color: black;
            font-weight: 600;
        }
        .api-indicator {
            background: #28a745;
            color: white;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Books Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .book-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
        }
        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .book-card h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 18px;
        }
        .book-card .author {
            color: #666;
            font-size: 14px;
            margin-bottom: 12px;
        }
        .book-card .info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }
        .book-card .info-item {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
        }
        .book-card .info-item .label {
            color: #666;
        }
        .book-card .info-item .value {
            color: #333;
            font-weight: 500;
        }
        .category-badge {
            display: inline-block;
            padding: 5px 12px;
            background: black;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .availability {
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            font-size: 13px;
        }
        .availability.available {
            background: #d4edda;
            color: #155724;
        }
        .availability.unavailable {
            background: #f8d7da;
            color: #721c24;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .no-results .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div>
            <h1>
                <i class="fas fa-search"></i> Search Books
                <span class="api-badge">API Version</span>
            </h1>
        </div>
        <div style="display: flex; gap: 15px;">
            <a href="../home.php" class="btn"><i class="fas fa-home"></i> Home</a>
            <a href="dashboard.php" class="btn"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="../auth/logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="search-card">
            <h2 style="margin-bottom: 20px; color: #333;">
                <i class="fas fa-search"></i> Search Library Collection
            </h2>
            <form id="searchForm" class="search-form">
                <input 
                    type="text" 
                    id="searchInput" 
                    placeholder="Search by title, author, or category..." 
                    autocomplete="off"
                >
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
            <p style="color: #666; font-size: 13px;">
                <i class="fas fa-info-circle"></i> 
                This page uses API architecture: UI/UX → API → DATABASE → API → OUTPUT
            </p>
        </div>

        <!-- Loading State -->
        <div id="loadingState" class="loading" style="display: none;">
            <div class="spinner"></div>
            <p>Searching books via API...</p>
        </div>

        <!-- Error Message -->
        <div id="errorMessage" class="error-message" style="display: none;"></div>

        <!-- Results Info -->
        <div id="resultsInfo" class="results-info" style="display: none;">
            <div class="count" id="resultsCount"></div>
            <div class="api-indicator">
                <i class="fas fa-plug"></i> API Powered
            </div>
        </div>

        <!-- Books Grid -->
        <div id="booksGrid" class="books-grid"></div>

        <!-- No Results -->
        <div id="noResults" class="no-results" style="display: none;">
            <div class="icon">📚</div>
            <h2>No books found</h2>
            <p>Try a different search term</p>
        </div>
    </div>

    <script>
        const searchForm = document.getElementById('searchForm');
        const searchInput = document.getElementById('searchInput');
        const loadingState = document.getElementById('loadingState');
        const errorMessage = document.getElementById('errorMessage');
        const resultsInfo = document.getElementById('resultsInfo');
        const resultsCount = document.getElementById('resultsCount');
        const booksGrid = document.getElementById('booksGrid');
        const noResults = document.getElementById('noResults');

        // Handle form submission
        searchForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const query = searchInput.value.trim();
            
            if (!query) {
                alert('Please enter a search term');
                return;
            }
            
            await searchBooks(query);
        });

        // Search books via API
        async function searchBooks(query) {
            // Show loading, hide everything else
            loadingState.style.display = 'block';
            errorMessage.style.display = 'none';
            resultsInfo.style.display = 'none';
            booksGrid.innerHTML = '';
            noResults.style.display = 'none';
            
            try {
                // API CALL - Layer 1 (Request to API)
                const response = await fetch(`../api/index.php?module=books&action=search&search=${encodeURIComponent(query)}`);
                
                // Parse JSON response - Layer 2 (Receive from API)
                const data = await response.json();
                
                // Hide loading
                loadingState.style.display = 'none';
                
                if (data.success) {
                    // Display results - OUTPUT Layer
                    displayResults(data.data, data.count);
                } else {
                    // Show error
                    showError(data.message);
                }
                
            } catch (error) {
                loadingState.style.display = 'none';
                showError('Failed to connect to API: ' + error.message);
            }
        }

        // Display search results
        function displayResults(books, count) {
            if (count === 0) {
                noResults.style.display = 'block';
                return;
            }
            
            // Show results info
            resultsInfo.style.display = 'flex';
            resultsCount.innerHTML = `<i class="fas fa-check-circle"></i> Found <strong>${count}</strong> book${count !== 1 ? 's' : ''}`;
            
            // Display books
            books.forEach(book => {
                const bookCard = createBookCard(book);
                booksGrid.appendChild(bookCard);
            });
        }

        // Create book card element
        function createBookCard(book) {
            const card = document.createElement('div');
            card.className = 'book-card';
            
            const categoryBadge = book.category ? 
                `<span class="category-badge">${escapeHtml(book.category)}</span>` : '';
            
            const isbn = book.isbn ? 
                `<div class="info-item">
                    <span class="label">ISBN:</span>
                    <span class="value">${escapeHtml(book.isbn)}</span>
                </div>` : '';
            
            const year = book.published_year ? 
                `<div class="info-item">
                    <span class="label">Published:</span>
                    <span class="value">${book.published_year}</span>
                </div>` : '';
            
            const availabilityClass = book.is_available ? 'available' : 'unavailable';
            const availabilityText = book.is_available ? 
                `✓ ${book.available} ${book.available === 1 ? 'copy' : 'copies'} available` : 
                '✗ Currently unavailable';
            
            card.innerHTML = `
                <h3>${escapeHtml(book.title)}</h3>
                <div class="author">by ${escapeHtml(book.author)}</div>
                ${categoryBadge}
                <div class="info">
                    ${isbn}
                    ${year}
                    <div class="info-item">
                        <span class="label">Total Copies:</span>
                        <span class="value">${book.quantity}</span>
                    </div>
                </div>
                <div class="availability ${availabilityClass}">
                    ${availabilityText}
                </div>
            `;
            
            return card;
        }

        // Show error message
        function showError(message) {
            errorMessage.style.display = 'block';
            errorMessage.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${escapeHtml(message)}`;
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load all books on page load
        window.addEventListener('load', () => {
            searchBooks(''); // Empty search shows all books
        });
    </script>
</body>
</html>