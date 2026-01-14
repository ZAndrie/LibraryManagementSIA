<?php
require_once '../auth/check_auth.php';
require_once '../includes/functions.php';
require_once '../includes/book_api.php';
checkRole('admin');

$error = '';
$success = '';
$book = null;

if (!isset($_GET['id'])) {
    header("Location: books.php");
    exit();
}

$id = intval($_GET['id']);

$sql = "SELECT * FROM books WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$book = $result->fetch_assoc();

if (!$book) {
    header("Location: books.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_book'])) {
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $isbn = trim($_POST['isbn']);
    $category = trim($_POST['category']);
    $quantity = intval($_POST['quantity']);
    $available = intval($_POST['available']);
    $published_year = intval($_POST['published_year']);
    
    if (empty($title) || empty($author)) {
        $error = "Title and Author are required";
    } else {
        $sql = "UPDATE books SET title=?, author=?, isbn=?, category=?, quantity=?, available=?, published_year=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssiiii", $title, $author, $isbn, $category, $quantity, $available, $published_year, $id);
        
        if ($stmt->execute()) {
            // Use safe logging to prevent foreign key errors
            if (isset($_SESSION['user_id'])) {
                $user_id = $_SESSION['user_id'];
                $check_sql = "SELECT id FROM users WHERE id = ?";
                $check_stmt = $conn->prepare($check_sql);
                if ($check_stmt) {
                    $check_stmt->bind_param("i", $user_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    if ($check_result->num_rows > 0) {
                        logActivity($conn, $user_id, 'UPDATE_BOOK', "Updated book: $title (ID: $id)");
                    }
                    $check_stmt->close();
                }
            }
            
            $success = "Book updated successfully!";
            $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Error updating book";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Book - Library System</title>
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
        .btn:hover { background: grey }
        .btn-primary {
            background: black;
            border: none;
            font-size: 16px;
        }
        .btn-api {
            background: black;
            border: none;
            font-size: 14px;
            margin-left: 10px;
        }
        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,5);
            margin-bottom: 20px;
        }
        .api-search-section {
            background: white;
            border-left: 4px solid black;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .api-search-section h3 {
            color: black;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .search-box {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: black;
            font-weight: 500;
        }
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid black;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: grey;
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
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 10px;
            color: #667eea;
        }
        .loading.active {
            display: block;
        }
        .api-badge {
            display: inline-block;
            padding: 4px 10px;
            background: black;
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-book"></i> Edit Book</h1>
        <div style="display: flex; gap: 15px;">
            <a href="books.php" class="btn">← Back to Books</a>
            <a href="dashboard.php" class="btn">Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card api-search-section">
            <h3>
                <i class="fas fa-sync-alt"></i> 
                Update Book Information from API
                <span class="api-badge">REFRESH</span>
            </h3>
            <p style="margin-bottom: 15px; color: black; font-size: 14px;">
                Search to update/refresh book details from online databases
            </p>
            <div class="search-box">
                <input type="text" id="search_isbn" placeholder="Enter ISBN" value="<?php echo htmlspecialchars($book['isbn']); ?>">
                <input type="text" id="search_title" placeholder="Or enter Title" value="<?php echo htmlspecialchars($book['title']); ?>">
                <button type="button" class="btn btn-api" onclick="searchBook()">
                    <i class="fas fa-search"></i> Search & Update
                </button>
            </div>
            <div id="api_loading" class="loading">
                <i class="fas fa-spinner fa-spin"></i> Searching book databases...
            </div>
            <div id="api_result" style="margin-top: 10px;"></div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 25px; color: #333;">Edit Book Information</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="title">Book Title *</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($book['title']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="author">Author *</label>
                    <input type="text" id="author" name="author" value="<?php echo htmlspecialchars($book['author']); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="isbn">ISBN</label>
                        <input type="text" id="isbn" name="isbn" value="<?php echo htmlspecialchars($book['isbn']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">Select Category</option>
                            <option value="Fiction" <?php echo $book['category'] == 'Fiction' ? 'selected' : ''; ?>>Fiction</option>
                            <option value="Non-Fiction" <?php echo $book['category'] == 'Non-Fiction' ? 'selected' : ''; ?>>Non-Fiction</option>
                            <option value="Science" <?php echo $book['category'] == 'Science' ? 'selected' : ''; ?>>Science</option>
                            <option value="History" <?php echo $book['category'] == 'History' ? 'selected' : ''; ?>>History</option>
                            <option value="Biography" <?php echo $book['category'] == 'Biography' ? 'selected' : ''; ?>>Biography</option>
                            <option value="Technology" <?php echo $book['category'] == 'Technology' ? 'selected' : ''; ?>>Technology</option>
                            <option value="Literature" <?php echo $book['category'] == 'Literature' ? 'selected' : ''; ?>>Literature</option>
                            <option value="Children" <?php echo $book['category'] == 'Children' ? 'selected' : ''; ?>>Children</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="quantity">Total Quantity *</label>
                        <input type="number" id="quantity" name="quantity" min="0" value="<?php echo $book['quantity']; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="available">Available *</label>
                        <input type="number" id="available" name="available" min="0" value="<?php echo $book['available']; ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="published_year">Published Year</label>
                    <input type="number" id="published_year" name="published_year" min="1800" max="2025" value="<?php echo $book['published_year']; ?>">
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_book" class="btn btn-primary">Update Book</button>
                    <a href="books.php" class="btn btn-primary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

   <script>
function searchBook() {
    const isbn = document.getElementById('search_isbn').value.trim();
    const title = document.getElementById('search_title').value.trim();
    const loading = document.getElementById('api_loading');
    const result = document.getElementById('api_result');
    
    if (!isbn && !title) {
        result.innerHTML = '<div class="alert alert-error">Please enter ISBN or Title</div>';
        return;
    }
    
    loading.classList.add('active');
    result.innerHTML = '';
    
    const formData = new FormData();
    formData.append('isbn', isbn);
    formData.append('title', title);
    
    fetch('../api/index.php?module=admin_book_search', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        loading.classList.remove('active');
        
        if (data.success) {
            result.innerHTML = '<div class="alert alert-success"><strong>✓ Found!</strong> Book details loaded from ' + data.source + '. Review and save changes.</div>';
            
            // Update form fields (except quantity and available)
            document.getElementById('title').value = data.data.title || '';
            document.getElementById('author').value = data.data.author || '';
            document.getElementById('isbn').value = data.data.isbn || '';
            document.getElementById('published_year').value = data.data.published_year || '';
            
            // Set category if exists
            const categorySelect = document.getElementById('category');
            const category = data.data.category || '';
            for (let i = 0; i < categorySelect.options.length; i++) {
                if (categorySelect.options[i].value.toLowerCase() === category.toLowerCase()) {
                    categorySelect.value = categorySelect.options[i].value;
                    break;
                }
            }
            
            // Highlight updated fields
            document.getElementById('title').style.background = '#d4edda';
            document.getElementById('author').style.background = '#d4edda';
            setTimeout(function() {
                document.getElementById('title').style.background = '';
                document.getElementById('author').style.background = '';
            }, 2000);
        } else {
            result.innerHTML = '<div class="alert alert-info"><strong>ℹ️ ' + data.message + '</strong></div>';
        }
    })
    .catch(error => {
        loading.classList.remove('active');
        result.innerHTML = '<div class="alert alert-error"><strong>Error:</strong> ' + error.message + '</div>';
    });
}

// Allow Enter key to trigger search
document.getElementById('search_isbn').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchBook();
    }
});

document.getElementById('search_title').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchBook();
    }
});
</script>
</body>
</html>