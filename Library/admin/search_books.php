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
    <title>Search Books (API Admin) - Library System</title>
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
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #333;
        }
        .container {
            max-width: 1400px;
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
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 0;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 12px 20px;
            background: none;
            border: none;
            color: #666;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .filter-tab:hover { color: black; }
        .filter-tab.active { color: black; }
        .filter-tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: black;
        }
        
        /* Filter Content */
        .filter-content {
            display: none;
        }
        .filter-content.active {
            display: block;
        }
        
        .search-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-form input,
        .search-form select {
            flex: 1;
            min-width: 200px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .search-form input:focus,
        .search-form select:focus {
            outline: none;
            border-color: black;
        }
        .btn-search {
            padding: 12px 24px;
            background: black;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .btn-search:hover { background: grey; }
        
        /* Advanced Filter Grid */
        .advanced-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .btn-clear {
            padding: 12px 24px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-clear:hover { background: #5a6268; }
        
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
        
        .results-info {
            margin-bottom: 20px;
            padding: 15px;
            background: #e7f3ff;
            border-radius: 8px;
            display: none;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .results-info.show {
            display: flex;
        }
        .results-count {
            color: black;
            font-weight: 600;
        }
        
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
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
            font-size: 20px;
        }
        .book-card .author {
            color: #666;
            font-size: 15px;
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
            font-size: 14px;
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
            font-size: 14px;
            margin-bottom: 15px;
        }
        .availability.available {
            background: #d4edda;
            color: #155724;
        }
        .availability.unavailable {
            background: #f8d7da;
            color: #721c24;
        }
        .actions {
            display: flex;
            gap: 8px;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #999;
            display: none;
        }
        .no-results.show {
            display: block;
        }
        .no-results .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>
            <i class="fas fa-search"></i> Search Books (Admin)
            <span class="api-badge">API Powered</span>
        </h1>
        <div style="display: flex; gap: 15px;">
            <a href="../home.php" class="btn"><i class="fas fa-home"></i> Home</a>
            <a href="dashboard.php" class="btn"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="../auth/logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="search-card">
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="switchTab('quick')">
                    <i class="fas fa-search"></i> Quick Search
                </button>
                <button class="filter-tab" onclick="switchTab('title')">
                    <i class="fas fa-book"></i> By Title
                </button>
                <button class="filter-tab" onclick="switchTab('author')">
                    <i class="fas fa-user"></i> By Author
                </button>
                <button class="filter-tab" onclick="switchTab('isbn')">
                    <i class="fas fa-barcode"></i> By ISBN
                </button>
                <button class="filter-tab" onclick="switchTab('category')">
                    <i class="fas fa-tag"></i> By Category
                </button>
                <button class="filter-tab" onclick="switchTab('advanced')">
                    <i class="fas fa-sliders-h"></i> Advanced
                </button>
            </div>
            
            <!-- Quick Search -->
            <div id="quick-filter" class="filter-content active">
                <form id="quickForm" class="search-form">
                    <input type="text" id="quickSearch" placeholder="Search by title, author, ISBN, or category...">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Title Search -->
            <div id="title-filter" class="filter-content">
                <form id="titleForm" class="search-form">
                    <input type="text" id="titleSearch" placeholder="Enter book title...">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Author Search -->
            <div id="author-filter" class="filter-content">
                <form id="authorForm" class="search-form">
                    <input type="text" id="authorSearch" placeholder="Enter author name...">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- ISBN Search -->
            <div id="isbn-filter" class="filter-content">
                <form id="isbnForm" class="search-form">
                    <input type="text" id="isbnSearch" placeholder="Enter ISBN...">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Category Search -->
            <div id="category-filter" class="filter-content">
                <form id="categoryForm" class="search-form">
                    <select id="categorySearch">
                        <option value="">Select a category...</option>
                    </select>
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
            
            <!-- Advanced Search -->
            <div id="advanced-filter" class="filter-content">
                <form id="advancedForm">
                    <div class="advanced-filter-grid">
                        <input type="text" id="advTitle" placeholder="Title">
                        <input type="text" id="advAuthor" placeholder="Author">
                        <select id="advCategory">
                            <option value="">All Categories</option>
                        </select>
                        <input type="text" id="advIsbn" placeholder="ISBN">
                        <input type="number" id="advYearFrom" placeholder="Year From" min="1000" max="9999">
                        <input type="number" id="advYearTo" placeholder="Year To" min="1000" max="9999">
                        <select id="advAvailability">
                            <option value="">All Books</option>
                            <option value="available">Available Only</option>
                            <option value="unavailable">Unavailable Only</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="button" class="btn-clear" onclick="clearAdvancedFilters()">
                            <i class="fas fa-times"></i> Clear Filters
                        </button>
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>
            
            <p style="color: #666; font-size: 13px; margin-top: 15px;">
                <i class="fas fa-info-circle"></i> 
                API Architecture: UI/UX → API → DATABASE → API → OUTPUT
            </p>
        </div>

        <!-- Loading State -->
        <div id="loadingState" class="loading" style="display: none;">
            <div class="spinner"></div>
            <p>Searching books via API...</p>
        </div>

        <!-- Results Info -->
        <div id="resultsInfo" class="results-info">
            <div class="results-count" id="resultsCount"></div>
            <div class="api-badge">
                <i class="fas fa-plug"></i> API Powered
            </div>
        </div>

        <!-- Books Grid -->
        <div id="booksGrid" class="books-grid"></div>

        <!-- No Results -->
        <div id="noResults" class="no-results">
            <div class="icon"><i class="fas fa-book"></i></div>
            <h2>No books found</h2>
            <p>Try adjusting your search criteria</p>
        </div>
    </div>

    <script>
        // Load categories on page load
        window.addEventListener('load', () => {
            loadCategories();
            searchBooks(''); // Show all books initially
        });

        // Form submissions
        document.getElementById('quickForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const query = document.getElementById('quickSearch').value.trim();
            searchBooks(query);
        });

        document.getElementById('titleForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const title = document.getElementById('titleSearch').value.trim();
            searchBooks('', { title });
        });

        document.getElementById('authorForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const author = document.getElementById('authorSearch').value.trim();
            searchBooks('', { author });
        });

        document.getElementById('isbnForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const isbn = document.getElementById('isbnSearch').value.trim();
            searchBooks('', { isbn });
        });

        document.getElementById('categoryForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const category = document.getElementById('categorySearch').value;
            searchBooks('', { category });
        });

        document.getElementById('advancedForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const filters = {
                title: document.getElementById('advTitle').value.trim(),
                author: document.getElementById('advAuthor').value.trim(),
                category: document.getElementById('advCategory').value,
                isbn: document.getElementById('advIsbn').value.trim(),
                year_from: document.getElementById('advYearFrom').value,
                year_to: document.getElementById('advYearTo').value,
                availability: document.getElementById('advAvailability').value
            };
            searchBooks('', filters);
        });

        // Switch tabs
        function switchTab(tabName) {
            document.querySelectorAll('.filter-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName + '-filter').classList.add('active');
            event.target.closest('.filter-tab').classList.add('active');
        }

        // Clear advanced filters
        function clearAdvancedFilters() {
            document.getElementById('advTitle').value = '';
            document.getElementById('advAuthor').value = '';
            document.getElementById('advCategory').selectedIndex = 0;
            document.getElementById('advIsbn').value = '';
            document.getElementById('advYearFrom').value = '';
            document.getElementById('advYearTo').value = '';
            document.getElementById('advAvailability').selectedIndex = 0;
        }

        // Load categories
        async function loadCategories() {
            try {
                const response = await fetch('../api/index.php?module=books&action=get_categories');
                const data = await response.json();
                
                if (data.success) {
                    const select1 = document.getElementById('categorySearch');
                    const select2 = document.getElementById('advCategory');
                    
                    data.data.forEach(category => {
                        const option1 = document.createElement('option');
                        option1.value = category;
                        option1.textContent = category;
                        select1.appendChild(option1);
                        
                        const option2 = document.createElement('option');
                        option2.value = category;
                        option2.textContent = category;
                        select2.appendChild(option2);
                    });
                }
            } catch (error) {
                console.error('Failed to load categories:', error);
            }
        }

        // Search books via API
        async function searchBooks(query = '', filters = {}) {
            document.getElementById('loadingState').style.display = 'block';
            document.getElementById('resultsInfo').classList.remove('show');
            document.getElementById('booksGrid').innerHTML = '';
            document.getElementById('noResults').classList.remove('show');
            
            try {
                let url = '../api/index.php?module=books&action=search';
                
                if (query) {
                    url += `&search=${encodeURIComponent(query)}`;
                }
                
                Object.keys(filters).forEach(key => {
                    if (filters[key]) {
                        url += `&${key}=${encodeURIComponent(filters[key])}`;
                    }
                });
                
                const response = await fetch(url);
                const data = await response.json();
                
                document.getElementById('loadingState').style.display = 'none';
                
                if (data.success) {
                    displayResults(data.data, data.count);
                } else {
                    document.getElementById('noResults').classList.add('show');
                }
                
            } catch (error) {
                document.getElementById('loadingState').style.display = 'none';
                alert('Search failed: ' + error.message);
            }
        }

        // Display search results
        function displayResults(books, count) {
            if (count === 0) {
                document.getElementById('noResults').classList.add('show');
                return;
            }
            
            document.getElementById('resultsInfo').classList.add('show');
            document.getElementById('resultsCount').innerHTML = 
                `<i class="fas fa-check-circle"></i> Found <strong>${count}</strong> book${count !== 1 ? 's' : ''}`;
            
            const grid = document.getElementById('booksGrid');
            books.forEach(book => {
                grid.appendChild(createBookCard(book));
            });
        }

        // Create book card
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
                <div class="actions">
                    <a href="edit_book.php?id=${book.id}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit Book
                    </a>
                </div>
            `;
            
            return card;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>