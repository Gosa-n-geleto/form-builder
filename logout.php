<?php
require_once 'config.php';

// Log logout activity before destroying session
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    logActivity($_SESSION['user_id'], $_SESSION['username'], 'Logout', 'User logged out');

    // Remove session tracking
    try { removeUserSession(); } catch (Exception $e) {}

    // Clear Remember Me token
    clearRememberToken($_SESSION['user_id']);
}

// Destroy all session data
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Get site branding
$branding = getSiteBranding();

// Get the current theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : (isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Logging Out - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           ADVANCED MODERN LOGOUT PAGE
           ============================================================ */

        :root {
            /* Light Mode */
            --bg-primary: #f0f4ff;
            --bg-card: rgba(255, 255, 255, 0.85);
            --bg-input: rgba(255, 255, 255, 0.9);
            --bg-hover: rgba(59, 130, 246, 0.05);
            
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            
            --border-color: rgba(226, 232, 240, 0.6);
            --border-focus: #3b82f6;
            
            --shadow-card: 0 20px 60px rgba(59, 130, 246, 0.08), 0 8px 24px rgba(0, 0, 0, 0.04);
            --shadow-input: 0 2px 8px rgba(0, 0, 0, 0.04);
            
            --accent-blue: #3b82f6;
            --accent-blue-dark: #2563eb;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-yellow: #f59e0b;
            --accent-purple: #8b5cf6;
            --accent-pink: #ec4899;
            
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-xl: 28px;
            
            --transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Dark Mode */
        body.dark-mode {
            --bg-primary: #0a0e1a;
            --bg-card: rgba(30, 41, 59, 0.85);
            --bg-input: rgba(51, 65, 85, 0.8);
            --bg-hover: rgba(59, 130, 246, 0.1);
            
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            
            --border-color: rgba(51, 65, 85, 0.6);
            
            --shadow-card: 0 20px 60px rgba(0, 0, 0, 0.4), 0 8px 24px rgba(0, 0, 0, 0.2);
            --shadow-input: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: background-color 0.6s ease, color 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        /* ===== ANIMATED BACKGROUND ===== */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(ellipse at 20% 50%, rgba(59, 130, 246, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 50%, rgba(139, 92, 246, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 100%, rgba(236, 72, 153, 0.04) 0%, transparent 50%);
            z-index: 0;
            animation: backgroundFloat 20s ease-in-out infinite;
        }

        body.dark-mode::before {
            background: 
                radial-gradient(ellipse at 20% 50%, rgba(59, 130, 246, 0.12) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 50%, rgba(139, 92, 246, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 100%, rgba(236, 72, 153, 0.06) 0%, transparent 50%);
        }

        @keyframes backgroundFloat {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(-2%, -1%) scale(1.02); }
            66% { transform: translate(2%, 1%) scale(0.98); }
        }

        /* ===== FLOATING PARTICLES ===== */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            width: 6px;
            height: 6px;
            background: radial-gradient(circle, var(--accent-blue), transparent);
            border-radius: 50%;
            opacity: 0.15;
            animation: particleFloat 15s infinite linear;
        }

        .particle:nth-child(1) { left: 10%; animation-delay: 0s; animation-duration: 18s; }
        .particle:nth-child(2) { left: 20%; animation-delay: 2s; animation-duration: 22s; opacity: 0.1; }
        .particle:nth-child(3) { left: 30%; animation-delay: 4s; animation-duration: 16s; }
        .particle:nth-child(4) { left: 40%; animation-delay: 1s; animation-duration: 20s; opacity: 0.08; }
        .particle:nth-child(5) { left: 50%; animation-delay: 3s; animation-duration: 24s; }
        .particle:nth-child(6) { left: 60%; animation-delay: 5s; animation-duration: 19s; opacity: 0.12; }
        .particle:nth-child(7) { left: 70%; animation-delay: 2.5s; animation-duration: 21s; }
        .particle:nth-child(8) { left: 80%; animation-delay: 4.5s; animation-duration: 17s; opacity: 0.09; }
        .particle:nth-child(9) { left: 90%; animation-delay: 1.5s; animation-duration: 23s; }
        .particle:nth-child(10) { left: 95%; animation-delay: 3.5s; animation-duration: 20s; opacity: 0.11; }

        @keyframes particleFloat {
            0% { transform: translateY(100vh) scale(0); opacity: 0; }
            10% { opacity: 0.15; }
            90% { opacity: 0.15; }
            100% { transform: translateY(-100vh) scale(1); opacity: 0; }
        }

        /* ===== LOGOUT CONTAINER ===== */
        .logout-container {
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 1;
        }

        /* ===== LOGOUT BOX ===== */
        .logout-box {
            background: var(--bg-card);
            backdrop-filter: blur(20px) saturate(1.2);
            -webkit-backdrop-filter: blur(20px) saturate(1.2);
            border-radius: var(--radius-lg);
            padding: 48px 36px 36px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-color);
            transition: all var(--transition);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .logout-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-blue), var(--accent-purple), var(--accent-pink), var(--accent-blue));
            background-size: 300% 100%;
            animation: gradientMove 4s ease-in-out infinite;
        }

        @keyframes gradientMove {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        /* ===== GLOW EFFECT ===== */
        .logout-box::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at top right, rgba(59, 130, 246, 0.03), transparent 70%);
            pointer-events: none;
        }

        /* ===== ICON ===== */
        .logout-icon-wrapper {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 24px;
        }

        .logout-icon-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid var(--border-color);
            transition: all var(--transition);
            position: relative;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .logout-icon-circle i {
            font-size: 48px;
            color: var(--accent-blue);
            transition: all var(--transition);
        }

        .logout-icon-circle .fa-spinner {
            position: absolute;
            font-size: 64px;
            color: var(--accent-blue);
            opacity: 0.2;
            animation: spin 3s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ===== PROGRESS BAR ===== */
        .progress-container {
            margin: 28px 0 20px;
            position: relative;
        }

        .progress-bar {
            width: 100%;
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-blue), var(--accent-purple));
            border-radius: 2px;
            animation: progressAnimation 3s ease-in-out forwards;
            width: 0%;
        }

        @keyframes progressAnimation {
            0% { width: 0%; }
            20% { width: 20%; }
            40% { width: 45%; }
            60% { width: 70%; }
            80% { width: 90%; }
            100% { width: 100%; }
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
            transition: color var(--transition);
        }

        .progress-text .status {
            color: var(--accent-blue);
            font-weight: 600;
        }

        /* ===== LOGOUT BOX CONTENT ===== */
        .logout-box h2 {
            font-size: 28px;
            font-weight: 900;
            color: var(--text-primary);
            letter-spacing: -0.03em;
            margin-bottom: 8px;
            transition: color var(--transition);
        }

        .logout-box h2 .highlight {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logout-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 400;
            margin-bottom: 24px;
            transition: color var(--transition);
        }

        /* ===== GOODBYE MESSAGE ===== */
        .goodbye-message {
            padding: 16px;
            background: var(--bg-hover);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            margin: 20px 0 24px;
            transition: all var(--transition);
        }

        .goodbye-message p {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.6;
            transition: color var(--transition);
        }

        .goodbye-message .emoji {
            font-size: 24px;
            display: block;
            margin-bottom: 4px;
        }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 24px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-blue-dark));
            color: #fff;
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.6s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(59, 130, 246, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-secondary);
            border: 2px solid var(--border-color);
        }

        .btn-secondary:hover {
            border-color: var(--accent-blue);
            color: var(--accent-blue);
            background: var(--bg-hover);
            transform: translateY(-2px);
        }

        .btn-secondary:active {
            transform: translateY(0);
        }

        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .btn-group .btn {
            flex: 1;
        }

        /* ===== THEME TOGGLE ===== */
        .theme-toggle {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            backdrop-filter: blur(12px);
        }

        .theme-toggle:hover {
            background: var(--accent-blue);
            color: #fff;
            border-color: var(--accent-blue);
            transform: scale(1.1) rotate(15deg);
            box-shadow: 0 8px 32px rgba(59, 130, 246, 0.35);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            .logout-box {
                padding: 32px 20px 24px;
                border-radius: var(--radius-md);
            }

            .logout-icon-wrapper,
            .logout-icon-circle {
                width: 80px;
                height: 80px;
            }

            .logout-icon-circle i {
                font-size: 36px;
            }

            .logout-icon-circle .fa-spinner {
                font-size: 48px;
            }

            .logout-box h2 {
                font-size: 24px;
            }

            .btn-group {
                flex-direction: column;
            }

            .theme-toggle {
                bottom: 16px;
                right: 16px;
                width: 42px;
                height: 42px;
                font-size: 17px;
            }
        }

        @media (max-width: 360px) {
            .logout-box {
                padding: 24px 16px;
            }

            .logout-icon-wrapper,
            .logout-icon-circle {
                width: 64px;
                height: 64px;
            }

            .logout-icon-circle i {
                font-size: 28px;
            }

            .logout-icon-circle .fa-spinner {
                font-size: 40px;
            }

            .btn {
                font-size: 14px;
                padding: 12px 16px;
            }
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: var(--bg-primary);
        }
        ::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* ===== GLASS MORPHISM EFFECTS ===== */
        .glass-effect {
            background: var(--bg-card);
            backdrop-filter: blur(20px) saturate(1.2);
            -webkit-backdrop-filter: blur(20px) saturate(1.2);
            border: 1px solid var(--border-color);
        }

        /* ===== ANIMATED SHIMMER ===== */
        .shimmer {
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(255,255,255,0.05) 50%, 
                transparent 100%
            );
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        /* ===== FADE IN ANIMATION ===== */
        .fade-in {
            animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ===== REDIRECT COUNTER ===== */
        .redirect-counter {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
            font-size: 13px;
            color: var(--text-muted);
            transition: color var(--transition);
        }

        .redirect-counter .count {
            font-weight: 700;
            color: var(--accent-blue);
            font-size: 18px;
            min-width: 24px;
        }
    </style>
</head>
<body>
    <!-- Animated Particles Background -->
    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>

    <div class="logout-container fade-in">
        <div class="logout-box">
            <!-- Icon -->
            <div class="logout-icon-wrapper">
                <div class="logout-icon-circle">
                    <i class="fas fa-sign-out-alt"></i>
                    <i class="fas fa-spinner"></i>
                </div>
            </div>
            
            <h2>See You <span class="highlight">Soon!</span></h2>
            <p class="logout-subtitle">You have been successfully logged out</p>

            <!-- Goodbye Message -->
            <div class="goodbye-message">
                <span class="emoji">👋</span>
                <p>Thank you for using <strong><?php echo htmlspecialchars($branding['site_name']); ?></strong>. We hope to see you again soon!</p>
            </div>

            <!-- Progress Bar -->
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <div class="progress-text">
                    <span>Logging out...</span>
                    <span class="status" id="statusText">Redirecting in <span id="countdown">5</span>s</span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="btn-group">
                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Login Again
                </a>
                <a href="javascript:history.back()" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Go Back
                </a>
            </div>

            <!-- Redirect Counter -->
            <div class="redirect-counter">
                <i class="fas fa-clock"></i>
                Redirecting to login in <span class="count" id="countdownDisplay">5</span> seconds
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
        // ============================================================
        // ADVANCED LOGOUT PAGE JAVASCRIPT
        // ============================================================

        // ===== THEME TOGGLE =====
        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.body.classList.add('dark-mode');
                updateThemeIcon(true);
            }
        }

        function toggleTheme() {
            const isDark = document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon(isDark);
            
            // Trigger particle color update
            document.querySelectorAll('.particle').forEach(p => {
                p.style.background = isDark ? 
                    'radial-gradient(circle, rgba(59, 130, 246, 0.3), transparent)' :
                    'radial-gradient(circle, var(--accent-blue), transparent)';
            });
        }

        function updateThemeIcon(isDark) {
            const icon = document.getElementById('themeIcon');
            if (icon) {
                icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

        // Initialize theme on load
        initTheme();

        // ===== COUNTDOWN TIMER =====
        let countdown = 5;
        const countdownDisplay = document.getElementById('countdownDisplay');
        const countdownElement = document.getElementById('countdown');
        const statusText = document.getElementById('statusText');

        function updateCountdown() {
            countdown--;
            
            if (countdownDisplay) {
                countdownDisplay.textContent = countdown;
            }
            
            if (countdownElement) {
                countdownElement.textContent = countdown;
            }
            
            if (statusText) {
                if (countdown > 1) {
                    statusText.textContent = `Redirecting in ${countdown}s`;
                } else if (countdown === 1) {
                    statusText.textContent = 'Redirecting now...';
                }
            }

            if (countdown <= 0) {
                clearInterval(timerInterval);
                // Redirect to login page
                window.location.href = 'login.php';
            }
        }

        // Start countdown
        const timerInterval = setInterval(updateCountdown, 1000);

        // ===== KEYBOARD SHORTCUTS =====
        document.addEventListener('keydown', function(e) {
            // Ctrl+Shift+D or Cmd+Shift+D for dark mode toggle
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'd' || e.key === 'D')) {
                e.preventDefault();
                toggleTheme();
            }
            
            // Enter key to login again
            if (e.key === 'Enter') {
                window.location.href = 'login.php';
            }
            
            // Escape key to go back
            if (e.key === 'Escape') {
                window.history.back();
            }
        });

        // ===== RIPPLE EFFECT =====
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const rect = this.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const ripple = document.createElement('span');
                ripple.style.cssText = `
                    position: absolute;
                    width: 0;
                    height: 0;
                    border-radius: 50%;
                    background: rgba(255,255,255,0.3);
                    transform: translate(${x}px, ${y}px);
                    pointer-events: none;
                    transition: all 0.6s ease;
                `;
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.style.width = '400px';
                    ripple.style.height = '400px';
                    ripple.style.opacity = '0';
                }, 10);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // ===== PERFORMANCE OPTIMIZATION =====
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                // Handle any resize-specific logic
            }, 250);
        });

        // ===== CONSOLE LOG =====
        console.log('🚀 Advanced Logout Page Loaded');
        console.log('⏱️ You will be redirected in 5 seconds');
        console.log('⌨️ Shortcuts: Enter=Login, Escape=Back, Ctrl+Shift+D=Toggle Theme');
        console.log('🔐 Built with ❤️ by Rameez Scripts');

        // ===== PREVENT BACK BUTTON ISSUES =====
        window.addEventListener('popstate', function(e) {
            // Prevent back button from going back to dashboard
            if (window.location.pathname.includes('logout.php')) {
                window.location.href = 'login.php';
            }
        });

        // ===== CLEANUP =====
        window.addEventListener('beforeunload', function() {
            clearInterval(timerInterval);
        });

        // ===== PREVENT ACCIDENTAL NAVIGATION =====
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden, but we still want to redirect after countdown
                // The countdown will continue in the background
            }
        });
    </script>
</body>
</html>