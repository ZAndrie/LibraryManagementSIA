<?php
// Get current page
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? 'guest';
$is_admin = ($user_role === 'admin');
$is_guest = ($user_role === 'guest');
?>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f6fa;
        margin: 0;
        padding: 0;
    }
    
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        height: 100vh;
        width: 260px;
        background: white;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        transition: width 0.3s ease;
        z-index: 1000;
        overflow-y: auto;
        overflow-x: hidden;
    }
    
    .sidebar.collapsed {
        width: 70px;
    }
    
  .sidebar-header {
    padding: 20px;
    background: linear-gradient(135deg, black 0%, grey 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 70px;
    transition: all 0.3s ease;
}

.sidebar.collapsed .sidebar-header {
    padding: 20px 10px;
    flex-direction: column;
    gap: 10px;
}

.sidebar-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 20px;
    font-weight: bold;
    overflow: hidden;
    transition: all 0.3s ease;
}

.sidebar-logo i {
    font-size: 24px;
    min-width: 24px;
    flex-shrink: 0;
}

.sidebar-logo span {
    white-space: nowrap;
    transition: opacity 0.3s ease, width 0.3s ease;
}

.sidebar.collapsed .sidebar-logo {
    width: 100%;
    justify-content: center;
}

.sidebar.collapsed .sidebar-logo span {
    opacity: 0;
    width: 0;
    display: none;
}

.toggle-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 35px;
    height: 35px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    flex-shrink: 0;
    z-index: 1001;
}

.toggle-btn:hover {
    background: rgba(255,255,255,0.4);
    transform: scale(1.05);
}

.toggle-btn:active {
    transform: scale(0.95);
}

/* Keep toggle button visible and centered when collapsed */
.sidebar.collapsed .toggle-btn {
    display: flex;
    width: 100%;
    justify-content: center;
}
    
    .sidebar-user {
        padding: 15px 20px;
        border-bottom: 1px solid #e0e0e0;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: all 0.3s ease;
    }
    
    .sidebar.collapsed .sidebar-user {
        padding: 15px 10px;
        justify-content: center;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, black 0%, grey 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 16px;
        flex-shrink: 0;
    }
    
    .user-info {
        flex: 1;
        overflow: hidden;
        transition: opacity 0.3s ease, width 0.3s ease;
        min-width: 0;
    }
    
    .sidebar.collapsed .user-info {
        opacity: 0;
        width: 0;
        display: none;
    }
    
    .user-name {
        font-weight: 600;
        color: #333;
        font-size: 14px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .user-role {
        font-size: 12px;
        color: #666;
        text-transform: capitalize;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .sidebar-nav {
        padding: 10px 0;
        padding-bottom: 80px;
    }
    
    .nav-section {
        margin-bottom: 5px;
    }
    
    .nav-section-title {
        padding: 15px 20px 8px;
        font-size: 11px;
        font-weight: 600;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .sidebar.collapsed .nav-section-title {
        opacity: 0;
        height: 0;
        padding: 0;
        margin: 0;
    }
    
    .nav-item {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: #666;
        text-decoration: none;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    
    .sidebar.collapsed .nav-item {
        padding: 12px 0;
        justify-content: center;
    }
    
    .nav-item:hover {
        background: #242735ff;
        color: white;
    }
    
    .nav-item.active {
        background: #e8eaf6;
        color: #000000ff;
        font-weight: 600;
        border-right: 3px solid #000000ff;
    }
    
    .nav-item i {
        width: 24px;
        min-width: 24px;
        font-size: 18px;
        text-align: center;
        flex-shrink: 0;
        transition: all 0.3s ease;
    }
    
    .sidebar.collapsed .nav-item i {
        margin: 0;
        font-size: 20px;
    }
    
    .nav-item span {
        margin-left: 15px;
        white-space: nowrap;
        transition: opacity 0.3s ease, width 0.3s ease;
    }
    
    .sidebar.collapsed .nav-item span {
        opacity: 0;
        width: 0;
        display: none;
    }
    
    .nav-item .badge {
        margin-left: auto;
        background: #e74c3c;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
        flex-shrink: 0;
        transition: opacity 0.3s ease;
    }
    
    .sidebar.collapsed .badge {
        opacity: 0;
        display: none;
    }
    
    .main-content {
        margin-left: 260px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
    }
    
    .main-content.expanded {
        margin-left: 70px;
    }
    
    .top-bar {
        background: white;
        padding: 15px 30px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 999;
    }
    
    .page-title {
        font-size: 24px;
        color: #333;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .top-bar-actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .btn-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        border: none;
        background: #f5f6fa;
        color: #666;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
    }
    
    .btn-icon:hover {
        background: #e8eaf6;
        color: black;
    }
    
    .content-wrapper {
        padding: 30px;
    }
    
    /* Tooltip for collapsed sidebar */
    .sidebar.collapsed .nav-item::after {
        content: attr(data-tooltip);
        position: absolute;
        left: 75px;
        background: #333;
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        white-space: nowrap;
        font-size: 13px;
        z-index: 10001;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }
    
    .sidebar.collapsed .nav-item:hover::after {
        opacity: 1;
    }
    
    .logout-section {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 260px;
        border-top: 1px solid #e0e0e0;
        background: white;
        transition: width 0.3s ease;
        z-index: 999;
    }
    
    .sidebar.collapsed .logout-section {
        width: 70px;
    }
    
    /* Scrollbar styling */
    .sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .sidebar::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 3px;
    }
    
    .sidebar::-webkit-scrollbar-thumb:hover {
        background: #999;
    }
    
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }
        
        .sidebar.mobile-open {
            transform: translateX(0);
        }
        
        .main-content {
            margin-left: 0 !important;
        }
    }
</style>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <!-- Header -->
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-book"></i>
            <span>Library System</span>
        </div>
        <button class="toggle-btn" onclick="toggleSidebar()" type="button" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <!-- User Info -->
    <div class="sidebar-user">
        <div class="user-avatar">
            <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'G', 0, 1)); ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Guest User'); ?></div>
            <div class="user-role"><?php echo ucfirst($user_role); ?></div>
        </div>
    </div>
    
    <!-- Navigation -->
    <div class="sidebar-nav">
        <!-- Home Section -->
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <a href="<?php echo $is_admin ? '/Library/admin/dashboard.php' : '/Library/client/dashboard.php'; ?>" 
               class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"
               data-tooltip="Dashboard">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="/Library/home.php" 
               class="nav-item <?php echo ($current_page == 'home.php') ? 'active' : ''; ?>"
               data-tooltip="Home">
                <i class="fas fa-house-user"></i>
                <span>Home</span>
            </a>
            <a href="/Library/admin/borrowing.php" 
               class="nav-item <?php echo ($current_page == 'borrowing.php') ? 'active' : ''; ?>"
               data-tooltip="Borrowing System">
                <i class="fas fa-book-open"></i>
                <span>Borrowing System</span>
            </a>

             <a href="/Library/chatbot.php" 
   class="nav-item <?php echo ($current_page == 'chatbot.php') ? 'active' : ''; ?>"
   data-tooltip="AI Assistant">
    <i class="fas fa-robot"></i>
    <span>AI Assistant</span>
</a>
        </div>
        
        <?php if ($is_admin): ?>
        <!-- Admin - Books Management -->
        <div class="nav-section">
            <div class="nav-section-title">Books Management</div>
            <a href="/Library/admin/new_arrivals.php" 
               class="nav-item <?php echo ($current_page == 'new_arrivals.php') ? 'active' : ''; ?>"
               data-tooltip="New Arrivals">
                <i class="fas fa-star"></i>
                <span>New Arrivals</span>
            </a>
            <a href="/Library/admin/books.php" 
               class="nav-item <?php echo ($current_page == 'books.php') ? 'active' : ''; ?>"
               data-tooltip="Manage Books">
                <i class="fas fa-book"></i>
                <span>Manage Books</span>
            </a>
            <a href="/Library/admin/add_book.php" 
               class="nav-item <?php echo ($current_page == 'add_book.php') ? 'active' : ''; ?>"
               data-tooltip="Add New Book">
                <i class="fas fa-plus-circle"></i>
                <span>Add New Book</span>
            </a>
            <a href="/Library/admin/inventory.php" 
               class="nav-item <?php echo ($current_page == 'inventory.php') ? 'active' : ''; ?>"
               data-tooltip="Inventory">
                <i class="fas fa-boxes"></i>
                <span>Inventory</span>
            </a>
            <a href="/Library/admin/search_books.php" 
               class="nav-item <?php echo ($current_page == 'search_books.php') ? 'active' : ''; ?>"
               data-tooltip="Search Books">
                <i class="fas fa-search"></i>
                <span>Search Books</span>
            </a>
             <a href="/Library/admin/manage_faculty.php" 
               class="nav-item <?php echo ($current_page == 'manage_faculty.php') ? 'active' : ''; ?>"
               data-tooltip="Manage Faculty">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Manage Faculty</span>
            </a>
        </div>
        
        <!-- Admin - Patron Management (Unified) -->
        <div class="nav-section">
            <div class="nav-section-title">Patron Management</div>
            <a href="/Library/admin/patron_directory.php" 
               class="nav-item <?php echo ($current_page == 'patron_directory.php' || $current_page == 'patron_profile.php' || $current_page == 'edit_patron.php') ? 'active' : ''; ?>"
               data-tooltip="Patron Directory">
                <i class="fas fa-list"></i>
                <span>Patron Directory</span>
            </a>
            <a href="/Library/admin/add_patron.php" 
               class="nav-item <?php echo ($current_page == 'add_patron.php') ? 'active' : ''; ?>"
               data-tooltip="Add Patron">
                <i class="fas fa-user-plus"></i>
                <span>Add Patron</span>
            </a>
            <a href="/Library/admin/import_patrons.php" 
               class="nav-item <?php echo ($current_page == 'import_patrons.php') ? 'active' : ''; ?>"
               data-tooltip="Import Patrons">
                <i class="fas fa-file-import"></i>
                <span>Import Patrons</span>
            </a>
            <a href="/Library/admin/fines.php" 
               class="nav-item <?php echo ($current_page == 'fines.php') ? 'active' : ''; ?>"
               data-tooltip="Fines & Penalties">
                <i class="fas fa-money-bill-wave"></i>
                <span>Fines & Penalties</span>
            </a>
        </div>

        
        <!-- Admin - Security & Logs -->
        <div class="nav-section">
            <div class="nav-section-title">Security & Monitoring</div>
            <a href="/Library/admin/activity_logs.php" 
               class="nav-item <?php echo ($current_page == 'activity_logs.php') ? 'active' : ''; ?>"
               data-tooltip="Activity Logs">
                <i class="fas fa-clipboard-list"></i>
                <span>Activity Logs</span>
            </a>
            <a href="/Library/admin/login_history.php" 
               class="nav-item <?php echo ($current_page == 'login_history.php') ? 'active' : ''; ?>"
               data-tooltip="Login History">
                <i class="fas fa-history"></i>
                <span>Login History</span>
            </a>
            <a href="/Library/admin/active_sessions.php" 
               class="nav-item <?php echo ($current_page == 'active_sessions.php') ? 'active' : ''; ?>"
               data-tooltip="Active Sessions">
                <i class="fas fa-desktop"></i>
                <span>Active Sessions</span>
            </a>
        </div>
        
        <!-- Admin - Reports & Analytics -->
        <div class="nav-section">
            <div class="nav-section-title">Reports & Analytics</div>
            <a href="/Library/admin/analytics.php" 
               class="nav-item <?php echo ($current_page == 'analytics.php') ? 'active' : ''; ?>"
               data-tooltip="Analytics Dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Analytics</span>
            </a>
            <a href="/Library/admin/print_records.php" 
               class="nav-item <?php echo ($current_page == 'print_records.php') ? 'active' : ''; ?>"
               data-tooltip="Print Records">
                <i class="fas fa-print"></i>
                <span>Print Records</span>
            </a>
            <a href="/Library/admin/settings.php" 
               class="nav-item <?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>"
               data-tooltip="Settings">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
        
        <?php else: ?>
        <!-- Client/Guest - Books -->
        <div class="nav-section">
            <div class="nav-section-title">Library</div>
            <a href="/Library/client/search_books.php" 
               class="nav-item <?php echo ($current_page == 'search_books.php') ? 'active' : ''; ?>"
               data-tooltip="Search Books">
                <i class="fas fa-search"></i>
                <span>Search Books</span>
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Logout Section -->
    <div class="logout-section">
        <?php if ($is_guest): ?>
        <a href="/Library/auth/register.php" class="nav-item" data-tooltip="Create Account">
            <i class="fas fa-user-plus"></i>
            <span>Create Account</span>
        </a>
        <?php else: ?>
        <a href="/Library/auth/logout.php" class="nav-item" data-tooltip="Logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    
    if (!sidebar || !mainContent) {
        console.error('Sidebar or mainContent element not found!');
        return;
    }
    
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
    
    // Save state to localStorage
    const isCollapsed = sidebar.classList.contains('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    
    console.log('Sidebar toggled. Collapsed:', isCollapsed);
}

// Restore sidebar state on page load
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    
    if (sidebar && mainContent) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
        console.log('Sidebar state restored. Collapsed:', isCollapsed);
    }
});
</script>