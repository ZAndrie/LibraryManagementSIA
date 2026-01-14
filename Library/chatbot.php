<?php
require_once 'auth/check_auth.php';
require_once 'includes/functions.php';

// Get user info
$user_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Guest';
$user_role = isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Assistant - AI Chatbot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
             background: linear-gradient(135deg, #000000ff 0%, grey 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar */
        .navbar {
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
        }
        .navbar h1 {
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        .btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }

        /* Chat Container */
        .chat-container {
            flex: 1;
            display: flex;
            max-width: 1200px;
            width: 100%;
            margin: 30px auto;
            gap: 20px;
            padding: 0 20px;
        }

        /* Sidebar */
        .sidebar {
            width: 300px;
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .sidebar h3 {
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            margin-bottom: 10px;
        }
        .user-info {
            padding: 20px;
             background: linear-gradient(135deg, #000000ff 0%, grey 100%);
            border-radius: 12px;
            color: white;
            text-align: center;
        }
        .user-info .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 15px;
            border: 3px solid white;
        }
        .user-info h4 {
            margin-bottom: 5px;
        }
        .user-info .role {
            font-size: 13px;
            opacity: 0.9;
        }
        .quick-questions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .quick-question {
            padding: 12px 15px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
        }
        .quick-question:hover {
            background: black;
            color: white;
            border-color: black;
            transform: translateX(5px);
        }
        .quick-question i {
            font-size: 16px;
            width: 20px;
        }
        .stats-box {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
            font-size: 14px;
        }
        .stat-row:last-child {
            border-bottom: none;
        }
        .stat-label {
            color: #666;
        }
        .stat-value {
            font-weight: 600;
            color: #333;
        }

        /* Chat Main */
        .chat-main {
            flex: 1;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Chat Header */
        .chat-header {
            background: linear-gradient(135deg, #000000ff 0%, grey 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-header h2 {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 24px;
        }
        .status-badge {
            background: rgba(74, 222, 128, 0.3);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(74, 222, 128, 0.5);
        }
        .status-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #4ade80;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Welcome Message */
        .welcome-message {
            padding: 30px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-bottom: 2px solid #e9ecef;
        }
        .welcome-message h3 {
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-message p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .starter-questions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .starter-btn {
            padding: 15px 20px;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #333;
            font-size: 14px;
        }
        .starter-btn:hover {
            border-color: black;
            background: black;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        .starter-btn i {
            font-size: 20px;
            width: 24px;
        }

        /* Chat Messages */
        .chat-messages {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
            background: #f8f9fa;
        }
        .chat-messages::-webkit-scrollbar {
            width: 8px;
        }
        .chat-messages::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .chat-messages::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 4px;
        }
        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #999;
        }

        .message {
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message.bot {
            justify-content: flex-start;
        }
        .message.user {
            justify-content: flex-end;
        }
        .message-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .message.bot .message-avatar {
             background: linear-gradient(135deg, #000000ff 0%, grey 100%);
            color: white;
        }
        .message.user .message-avatar {
            background: #e9ecef;
            color: #495057;
            order: 2;
        }
        .message-content {
            max-width: 70%;
            padding: 16px 20px;
            border-radius: 12px;
            line-height: 1.6;
            font-size: 15px;
        }
        .message.bot .message-content {
            background: white;
            color: #333;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-bottom-left-radius: 4px;
        }
        .message.user .message-content {
             background: linear-gradient(135deg, #000000ff 0%, grey 100%);
            color: white;
            border-bottom-right-radius: 4px;
            order: 1;
        }
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
        }

        /* Typing Indicator */
        .typing-indicator {
            display: none;
            align-items: center;
            gap: 15px;
            padding: 16px 20px;
            background: white;
            border-radius: 12px;
            width: fit-content;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .typing-indicator.show {
            display: flex;
        }
        .typing-dot {
            width: 10px;
            height: 10px;
            background: black;
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }
        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-10px); opacity: 1; }
        }

        /* Chat Input */
        .chat-input {
            padding: 25px 30px;
            border-top: 2px solid #e9ecef;
            background: white;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .chat-input input {
            flex: 1;
            padding: 15px 20px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.3s;
        }
        .chat-input input:focus {
            border-color: black;
        }
        .send-button {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #000000ff 0%, grey 100%);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .send-button:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.6);
        }
        .send-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: scale(1);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .chat-container {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .message-content {
                max-width: 85%;
            }
            .starter-questions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <h1>
            <i class="fas fa-robot"></i>
            Library Assistant
        </h1>
        <div class="nav-links">
            <a href="home.php" class="btn">
                <i class="fas fa-home"></i> Home
            </a>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="admin/dashboard.php" class="btn">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            <?php endif; ?>
            <a href="auth/logout.php" class="btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>

    <!-- Chat Container -->
    <div class="chat-container">
        
        <!-- Sidebar -->
        <aside class="sidebar">
            <!-- User Info -->
            <div class="user-info">
                <div class="avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h4><?php echo htmlspecialchars($user_name); ?></h4>
                <div class="role">
                    <i class="fas fa-id-badge"></i> <?php echo $user_role; ?>
                </div>
            </div>

            <!-- Quick Questions -->
            <div>
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="quick-questions">
                    <button class="quick-question" onclick="sendQuickMessage('How many books do you have?')">
                        <i class="fas fa-book"></i>
                        <span>Book Count</span>
                    </button>
                    <button class="quick-question" onclick="sendQuickMessage('What categories are available?')">
                        <i class="fas fa-tags"></i>
                        <span>Categories</span>
                    </button>
                    <button class="quick-question" onclick="sendQuickMessage('Tell me about Harry Potter')">
                        <i class="fas fa-search"></i>
                        <span>Book Info</span>
                    </button>
                    <button class="quick-question" onclick="sendQuickMessage('What are your library hours?')">
                        <i class="fas fa-clock"></i>
                        <span>Library Hours</span>
                    </button>
                    <button class="quick-question" onclick="sendQuickMessage('How do I borrow books?')">
                        <i class="fas fa-question-circle"></i>
                        <span>Borrowing Info</span>
                    </button>
                    <button class="quick-question" onclick="sendQuickMessage('Recommend me a book')">
                        <i class="fas fa-star"></i>
                        <span>Get Recommendations</span>
                    </button>
                </div>
            </div>

            <!-- Library Stats -->
            <div>
                <h3><i class="fas fa-chart-bar"></i> Library Stats</h3>
                <div class="stats-box" id="statsBox">
                    <div class="stat-row">
                        <span class="stat-label">Total Books:</span>
                        <span class="stat-value" id="statTotalBooks">-</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Available:</span>
                        <span class="stat-value" id="statAvailable">-</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Categories:</span>
                        <span class="stat-value" id="statCategories">-</span>
                    </div>
                </div>
            </div>

            <!-- Clear Chat -->
            <button class="btn" onclick="clearChat()" style="width: 100%; justify-content: center; background: rgba(220, 53, 69, 0.1); color: #dc3545; border-color: #dc3545;">
                <i class="fas fa-trash-alt"></i> Clear Chat
            </button>
        </aside>

        <!-- Main Chat -->
        <main class="chat-main">
            <!-- Chat Header -->
            <div class="chat-header">
                <h2>
                    <i class="fas fa-comments"></i>
                    Chat
                </h2>
                <div class="status-badge">
                    <span>AI Online</span>
                </div>
            </div>

            <!-- Welcome Message -->
            <div class="welcome-message" id="welcomeMessage">
                <h3><i class="fas fa-hand-wave"></i> Welcome to Library Assistant!</h3>
                <p>I'm here to help you with book searches, get information about any book from the internet, check availability, and answer questions about our library. Just talk to me naturally!</p>
                
                <div class="starter-questions">
                    <button class="starter-btn" onclick="sendQuickMessage('Tell me about The Great Gatsby')">
                        <i class="fas fa-book"></i>
                        <span>Book Information</span>
                    </button>
                    <button class="starter-btn" onclick="sendQuickMessage('Do you have Harry Potter?')">
                        <i class="fas fa-search"></i>
                        <span>Search Library</span>
                    </button>
                    <button class="starter-btn" onclick="sendQuickMessage('What are the borrowing rules?')">
                        <i class="fas fa-book-reader"></i>
                        <span>Borrowing Policy</span>
                    </button>
                    <button class="starter-btn" onclick="sendQuickMessage('Recommend me a mystery book')">
                        <i class="fas fa-star"></i>
                        <span>Get Recommendations</span>
                    </button>
                </div>
            </div>

            <!-- Chat Messages -->
            <div id="chatMessages" class="chat-messages">
                <!-- Messages will be inserted here -->
            </div>

            <!-- Typing Indicator -->
            <div class="message bot" style="margin: 0 30px 20px;">
                <div class="message-avatar">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="typing-indicator" id="typingIndicator">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
            </div>

            <!-- Chat Input -->
            <div class="chat-input">
                <input 
                    type="text" 
                    id="chatInput" 
                    placeholder="Type your message here... (e.g., 'Tell me about 1984' or 'Do you have books by Stephen King?')" 
                    onkeypress="handleKeyPress(event)"
                    autocomplete="off"
                >
                <button class="send-button" id="sendButton" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </main>
    </div>

    <script>
        // Global variables
        let messageCount = 0;

        // Load library stats on page load
        window.addEventListener('load', () => {
            loadLibraryStats();
            // Auto-focus input
            const input = document.getElementById('chatInput');
            if (input) input.focus();
        });

        // Handle Enter key
        function handleKeyPress(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        }

        // Send quick message
        function sendQuickMessage(message) {
            document.getElementById('chatInput').value = message;
            sendMessage();
            
            // Hide welcome message on first interaction
            if (messageCount === 0) {
                const welcomeMsg = document.getElementById('welcomeMessage');
                if (welcomeMsg) welcomeMsg.style.display = 'none';
            }
        }

        // Send message
        async function sendMessage() {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            
            if (!message) return;
            
            // Hide welcome message on first message
            if (messageCount === 0) {
                const welcomeMsg = document.getElementById('welcomeMessage');
                if (welcomeMsg) welcomeMsg.style.display = 'none';
            }
            
            // Add user message
            addMessage(message, 'user');
            input.value = '';
            
            // Show typing indicator
            showTyping(true);
            
            // Disable send button
            const sendButton = document.getElementById('sendButton');
            sendButton.disabled = true;
            
            try {
                // Call chatbot API with conversation context
                const response = await fetch('api/index.php?module=chatbot_admin', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        message: message,
                        context: 'general'
                    })
                });
                
                const data = await response.json();
                
                // Hide typing indicator
                showTyping(false);
                
                // Add bot response
                if (data.success) {
                    addMessage(data.data.response, 'bot');
                    
                    // Log context info (for debugging)
                    if (data.data.context_maintained) {
                        console.log('✓ Conversation context maintained');
                        console.log('Intent:', data.data.intent);
                    }
                } else {
                    addMessage('I apologize, but I encountered an error. Please try again or contact support.', 'bot');
                }
                
            } catch (error) {
                showTyping(false);
                addMessage('I cannot connect to the server right now. Please check your connection and try again.', 'bot');
                console.error('Chatbot error:', error);
            } finally {
                sendButton.disabled = false;
                input.focus();
            }
        }

        // Add message to chat
        function addMessage(text, type) {
            messageCount++;
            const messagesContainer = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            avatar.innerHTML = type === 'bot' ? '<i class="fas fa-robot"></i>' : '<i class="fas fa-user"></i>';
            
            const contentWrapper = document.createElement('div');
            const content = document.createElement('div');
            content.className = 'message-content';
            content.innerHTML = formatMessage(text);
            
            const time = document.createElement('div');
            time.className = 'message-time';
            time.textContent = getCurrentTime();
            
            contentWrapper.appendChild(content);
            contentWrapper.appendChild(time);
            
            if (type === 'user') {
                messageDiv.appendChild(contentWrapper);
                messageDiv.appendChild(avatar);
            } else {
                messageDiv.appendChild(avatar);
                messageDiv.appendChild(contentWrapper);
            }
            
            messagesContainer.appendChild(messageDiv);
            
            // Smooth scroll to bottom
            messagesContainer.scrollTo({
                top: messagesContainer.scrollHeight,
                behavior: 'smooth'
            });
        }

        // Format message with markdown-like syntax
        function formatMessage(text) {
            // Bold text
            text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            
            // Emojis and line breaks
            text = text.replace(/\n/g, '<br>');
            
            // Lists
            text = text.replace(/^[•●] (.+)$/gm, '<span style="display:block; margin-left:15px;">• $1</span>');
            
            return text;
        }

        // Get current time
        function getCurrentTime() {
            const now = new Date();
            return now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        // Show/hide typing indicator
        function showTyping(show) {
            const indicator = document.getElementById('typingIndicator');
            if (show) {
                indicator.classList.add('show');
            } else {
                indicator.classList.remove('show');
            }
            
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.scrollTo({
                top: messagesContainer.scrollHeight,
                behavior: 'smooth'
            });
        }

        // Load library statistics
        async function loadLibraryStats() {
            try {
                const response = await fetch('api/index.php?module=books&action=get_stats');
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('statTotalBooks').textContent = data.data.total_books;
                    document.getElementById('statAvailable').textContent = data.data.available_copies;
                    document.getElementById('statCategories').textContent = data.data.categories_count;
                }
            } catch (error) {
                console.error('Failed to load stats:', error);
            }
        }

        // Clear chat with conversation context reset
        async function clearChat() {
            if (confirm('Are you sure you want to clear the chat history? This will also reset our conversation context.')) {
                // Clear UI
                document.getElementById('chatMessages').innerHTML = '';
                const welcomeMsg = document.getElementById('welcomeMessage');
                if (welcomeMsg) welcomeMsg.style.display = 'block';
                messageCount = 0;
                
                // Clear server-side conversation context
                try {
                    await fetch('api/index.php?module=chatbot_admin', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            message: 'clear',
                            action: 'clear'
                        })
                    });
                    console.log('✓ Conversation context cleared');
                } catch (error) {
                    console.error('Failed to clear context:', error);
                }
            }
        }
    </script>
</body>
</html>