<?php
/**
 * Developed by Muhafiz Ahmed
 * WhatsApp: https://wa.me/+251953115473 (For Custom Projects)
 */

require_once 'config.php';

// Check maintenance mode - block signup
if (isMaintenanceMode()) {
    header("Location: maintenance.php");
    exit();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';
$csrf_token = generateCSRFToken();

// Check for OAuth error messages
if (isset($_GET['error'])) {
    $oauth_errors = [
        'oauth_create_failed' => 'Could not create account with Google. Please try again.',
        'oauth_error' => 'An error occurred with Google signup. Please try again.'
    ];
    $error = isset($oauth_errors[$_GET['error']]) ? $oauth_errors[$_GET['error']] : '';
}

// Check if Google OAuth is enabled
$google_oauth_enabled = false;
$google_login_url = '';
try {
    $oauth_enabled = getSetting('google_oauth_enabled', '0');
    if ($oauth_enabled === '1') {
        $g_client_id = getSetting('google_client_id', '');
        $g_redirect_uri = getSetting('google_redirect_uri', '');
        if (!empty($g_client_id) && !empty($g_redirect_uri)) {
            $google_oauth_enabled = true;
            $google_login_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $g_client_id,
                'redirect_uri' => $g_redirect_uri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'access_type' => 'online',
                'prompt' => 'select_account',
                'state' => base64_encode(json_encode(['action' => 'signup']))
            ]);
        }
    }
} catch (Exception $e) {
    // OAuth not available, silently skip
}

// Get site branding
$branding = getSiteBranding();

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        // Input validation
        $username = validateUsername($username);
        $password = validatePassword($password);

        if ($username === false) {
            $error = 'Invalid username. Use 3-50 alphanumeric characters or underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($password === false) {
            $error = 'Password must be between 6 and 255 characters.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $conn = getDBConnection();

                // Check if username already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $error = 'Username already taken. Please choose another.';
                    $stmt->close();
                } else {
                    $stmt->close();

                    // Check if email already exists
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        $error = 'Email already registered. Please use another.';
                        $stmt->close();
                    } else {
                        $stmt->close();

                        // Hash password and create user
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $role = 'User';

                        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("ssss", $username, $hashed_password, $email, $role);

                        if ($stmt->execute()) {
                            $new_user_id = $stmt->insert_id;
                            $stmt->close();

                            // Log the signup
                            logActivity($new_user_id, $username, 'Signup', 'New user registered');

                            // Notify admins about new registration
                            try { createNotificationForAdmins('New User Registered', 'User "' . htmlspecialchars($username) . '" has signed up.', 'info', 'users.php'); } catch (Exception $e) {}

                            // Check if email verification is enabled
                            $verification_enabled = getSetting('email_verification_enabled', '0');
                            $smtp_enabled = getSetting('smtp_enabled', '0');

                            if ($verification_enabled === '1' && $smtp_enabled === '1') {
                                // Send OTP email for verification
                                $otp = createEmailVerification($new_user_id, $email);
                                $emailBody = getOTPEmailTemplate($otp, 'verify');
                                $emailResult = sendEmail($email, 'Verify Your Email - ' . $branding['site_name'], $emailBody);

                                if ($emailResult['success']) {
                                    header("Location: verify_otp.php?email=" . urlencode($email));
                                    exit();
                                } else {
                                    // Email failed but account created - auto-verify so user isn't stuck
                                    $verify_stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
                                    $verify_stmt->bind_param("i", $new_user_id);
                                    $verify_stmt->execute();
                                    $verify_stmt->close();
                                    error_log("Signup OTP email failed for $email: " . $emailResult['message']);
                                    $success = 'Account created successfully! Email verification skipped (SMTP error). You can now login.';
                                }
                            } else {
                                // Auto-verify if email verification is disabled
                                $verify_stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
                                $verify_stmt->bind_param("i", $new_user_id);
                                $verify_stmt->execute();
                                $verify_stmt->close();
                            }

                            $success = 'Account created successfully! You can now login.';
                        } else {
                            $error = 'Registration failed. Please try again.';
                            $stmt->close();
                        }
                    }
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), "doesn't exist") !== false) {
                    $error = 'Database not set up. Please run <a href="setup.php" class="login-link">setup.php</a> first.';
                } else {
                    $error = 'An error occurred. Please contact administrator.';
                    error_log("Signup error: " . $e->getMessage());
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Sign Up - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           ADVANCED MODERN SIGNUP PAGE
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

        /* ===== LOGIN CONTAINER ===== */
        .login-container {
            width: 100%;
            max-width: 460px;
            position: relative;
            z-index: 1;
        }

        /* ===== LOGIN BOX ===== */
        .login-box {
            background: var(--bg-card);
            backdrop-filter: blur(20px) saturate(1.2);
            -webkit-backdrop-filter: blur(20px) saturate(1.2);
            border-radius: var(--radius-lg);
            padding: 40px 36px 32px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-color);
            transition: all var(--transition);
            position: relative;
            overflow: hidden;
        }

        .login-box::before {
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
        .login-box::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at top right, rgba(59, 130, 246, 0.03), transparent 70%);
            pointer-events: none;
        }

        /* ===== LOGO ===== */
        .login-logo-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 16px;
        }

        .login-logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border-color);
            padding: 4px;
            background: var(--bg-card);
            transition: all var(--transition);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.12);
        }

        .login-logo:hover {
            transform: scale(1.05) rotate(-5deg);
            border-color: var(--accent-blue);
            box-shadow: 0 12px 32px rgba(59, 130, 246, 0.2);
        }

        .login-box h2 {
            text-align: center;
            font-size: 28px;
            font-weight: 900;
            color: var(--text-primary);
            letter-spacing: -0.03em;
            margin-bottom: 4px;
            transition: color var(--transition);
        }

        .login-box h2 .highlight {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-subtitle {
            text-align: center;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 400;
            margin-bottom: 28px;
            transition: color var(--transition);
        }

        /* ===== ALERTS ===== */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            margin-bottom: 20px;
            animation: slideDown 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            border: 1px solid transparent;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.08);
            color: var(--accent-green);
            border-color: rgba(16, 185, 129, 0.15);
        }

        body.dark-mode .alert-success {
            background: rgba(16, 185, 129, 0.12);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.08);
            color: var(--accent-red);
            border-color: rgba(239, 68, 68, 0.15);
        }

        body.dark-mode .alert-error {
            background: rgba(239, 68, 68, 0.12);
        }

        .alert i {
            font-size: 18px;
            flex-shrink: 0;
        }

        .alert a {
            color: var(--accent-blue);
            text-decoration: underline;
            font-weight: 600;
        }

        /* ===== FORM ===== */
        .form-group {
            margin-bottom: 18px;
            position: relative;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
            transition: color var(--transition);
        }

        .form-group label i {
            color: var(--accent-blue);
            font-size: 14px;
        }

        .form-group .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all var(--transition);
            outline: none;
            backdrop-filter: blur(8px);
            box-shadow: var(--shadow-input);
        }

        .form-group input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1), var(--shadow-input);
            background: var(--bg-card);
            transform: translateY(-1px);
        }

        .form-group input::placeholder {
            color: var(--text-muted);
            font-weight: 400;
        }

        /* ===== PASSWORD TOGGLE ===== */
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            transition: all var(--transition);
            font-size: 16px;
        }

        .password-toggle:hover {
            color: var(--accent-blue);
        }

        /* ===== PASSWORD STRENGTH INDICATOR ===== */
        .password-strength {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            height: 4px;
        }

        .password-strength .bar {
            flex: 1;
            height: 100%;
            background: var(--border-color);
            border-radius: 2px;
            transition: all var(--transition);
        }

        .password-strength .bar.active.weak {
            background: var(--accent-red);
        }
        .password-strength .bar.active.medium {
            background: var(--accent-yellow);
        }
        .password-strength .bar.active.strong {
            background: var(--accent-green);
        }

        .password-strength-text {
            font-size: 11px;
            font-weight: 500;
            margin-top: 4px;
            color: var(--text-muted);
            transition: color var(--transition);
        }

        .password-strength-text.weak { color: var(--accent-red); }
        .password-strength-text.medium { color: var(--accent-yellow); }
        .password-strength-text.strong { color: var(--accent-green); }

        /* ===== LOGIN OPTIONS ===== */
        .login-options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 22px 0 26px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .login-link {
            color: var(--accent-blue);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all var(--transition);
            position: relative;
        }

        .login-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent-blue);
            transition: width var(--transition);
        }

        .login-link:hover::after {
            width: 100%;
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

        /* ===== OAuth DIVIDER ===== */
        .oauth-divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 28px 0 22px;
        }

        .oauth-divider hr {
            flex: 1;
            border: none;
            border-top: 1px solid var(--border-color);
            transition: border-color var(--transition);
        }

        .oauth-divider span {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
            transition: color var(--transition);
        }

        /* ===== SOCIAL BUTTONS ===== */
        .social-buttons {
            display: flex;
            gap: 12px;
        }

        .social-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
        }

        .social-btn:hover {
            border-color: var(--accent-blue);
            background: var(--bg-hover);
            color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .social-btn svg {
            flex-shrink: 0;
        }

        /* ===== TERMS CHECKBOX ===== */
        .terms-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: color var(--transition);
            user-select: none;
            margin: 16px 0 20px;
        }

        .terms-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent-blue);
            cursor: pointer;
            border-radius: 4px;
            margin-top: 1px;
            flex-shrink: 0;
            transition: all var(--transition);
        }

        .terms-label a {
            color: var(--accent-blue);
            text-decoration: none;
            font-weight: 600;
        }

        .terms-label a:hover {
            text-decoration: underline;
        }

        /* ===== FOOTER ===== */
        .login-footer {
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            transition: border-color var(--transition);
        }

        .login-footer p {
            color: var(--text-muted);
            font-size: 13px;
            transition: color var(--transition);
        }

        .login-footer .login-footer-link {
            margin-bottom: 8px;
        }

        .login-footer .login-footer-link a {
            color: var(--accent-blue);
            text-decoration: none;
            font-weight: 600;
            transition: all var(--transition);
            position: relative;
        }

        .login-footer .login-footer-link a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent-blue);
            transition: width var(--transition);
        }

        .login-footer .login-footer-link a:hover::after {
            width: 100%;
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

            .login-box {
                padding: 28px 20px 24px;
                border-radius: var(--radius-md);
            }

            .login-logo {
                width: 64px;
                height: 64px;
            }

            .login-box h2 {
                font-size: 24px;
            }

            .form-group input {
                padding: 12px 14px;
                font-size: 14px;
            }

            .social-buttons {
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
            .login-box {
                padding: 20px 16px;
            }

            .login-logo {
                width: 56px;
                height: 56px;
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

        /* ===== LOADING STATE ===== */
        .btn.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .btn.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ===== INPUT VALIDATION STATES ===== */
        .form-group input.error {
            border-color: var(--accent-red);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .form-group input.success {
            border-color: var(--accent-green);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
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

        /* ===== PASSWORD REQUIREMENTS ===== */
        .password-requirements {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            transition: color var(--transition);
        }

        .password-requirements .req {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
        }

        .password-requirements .req i {
            font-size: 10px;
            transition: all var(--transition);
        }

        .password-requirements .req.valid {
            color: var(--accent-green);
        }

        .password-requirements .req.invalid {
            color: var(--text-muted);
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

    <div class="login-container">
        <div class="login-box">
            <!-- Logo -->
            <div class="login-logo-wrapper">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="login-logo" onerror="this.style.display='none'">
            </div>
            
            <h2>Create <span class="highlight">Account</span></h2>
            <p class="login-subtitle">Join us and get started with your journey</p>

            <!-- Success Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Signup Form -->
            <form method="POST" action="" id="signupForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Username
                    </label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" required autofocus autocomplete="username" minlength="3" maxlength="50" placeholder="Choose a username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i> Email
                    </label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" required autocomplete="email" maxlength="100" placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" required autocomplete="new-password" minlength="6" placeholder="Minimum 6 characters">
                        <button type="button" class="password-toggle" onclick="togglePassword('password', 'passwordIcon')" aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="passwordIcon"></i>
                        </button>
                    </div>
                    <!-- Password Strength Indicator -->
                    <div class="password-strength" id="passwordStrength">
                        <div class="bar" id="strengthBar1"></div>
                        <div class="bar" id="strengthBar2"></div>
                        <div class="bar" id="strengthBar3"></div>
                        <div class="bar" id="strengthBar4"></div>
                    </div>
                    <div class="password-strength-text" id="strengthText">Enter a password</div>
                    
                    <!-- Password Requirements -->
                    <div class="password-requirements" id="passwordRequirements">
                        <span class="req invalid" id="reqLength"><i class="fas fa-circle"></i> 6+ characters</span>
                        <span class="req invalid" id="reqLower"><i class="fas fa-circle"></i> Lowercase</span>
                        <span class="req invalid" id="reqUpper"><i class="fas fa-circle"></i> Uppercase</span>
                        <span class="req invalid" id="reqNumber"><i class="fas fa-circle"></i> Number</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">
                        <i class="fas fa-lock"></i> Confirm Password
                    </label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" minlength="6" placeholder="Confirm your password">
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'confirmIcon')" aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="confirmIcon"></i>
                        </button>
                    </div>
                    <div id="passwordMatch" style="font-size:12px;margin-top:4px;color:var(--text-muted);"></div>
                </div>

                <!-- Terms Checkbox -->
                <label class="terms-label">
                    <input type="checkbox" id="terms" required>
                    <span>I agree to the <a href="#" target="_blank">Terms of Service</a> and <a href="#" target="_blank">Privacy Policy</a></span>
                </label>

                <button type="submit" class="btn btn-primary" id="signupBtn">
                    <i class="fas fa-user-plus"></i> 
                    <span>Create Account</span>
                </button>
            </form>

            <!-- Social Login -->
            <?php if ($google_oauth_enabled): ?>
                <div class="oauth-divider">
                    <hr>
                    <span>or continue with</span>
                    <hr>
                </div>
                <div class="social-buttons">
                    <a href="<?php echo htmlspecialchars($google_login_url); ?>" class="social-btn">
                        <svg width="20" height="20" viewBox="0 0 48 48">
                            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                        </svg>
                        Google
                    </a>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="login-footer">
                <p class="login-footer-link">
                    Already have an account? <a href="login.php">Sign In</a>
                </p>
                <p><?php echo $branding['copyright_text']; ?></p>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
        // ============================================================
        // ADVANCED SIGNUP PAGE JAVASCRIPT
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

        // ===== PASSWORD TOGGLE =====
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // ===== PASSWORD STRENGTH =====
        function checkPasswordStrength(password) {
            let strength = 0;
            const requirements = {
                length: password.length >= 6,
                lower: /[a-z]/.test(password),
                upper: /[A-Z]/.test(password),
                number: /[0-9]/.test(password)
            };

            // Update requirements
            Object.keys(requirements).forEach(key => {
                const reqEl = document.getElementById('req' + key.charAt(0).toUpperCase() + key.slice(1));
                if (reqEl) {
                    if (requirements[key]) {
                        reqEl.className = 'req valid';
                        reqEl.innerHTML = '<i class="fas fa-check-circle"></i> ' + 
                            (key === 'length' ? '6+ characters' : 
                             key === 'lower' ? 'Lowercase' :
                             key === 'upper' ? 'Uppercase' : 'Number');
                    } else {
                        reqEl.className = 'req invalid';
                        reqEl.innerHTML = '<i class="fas fa-circle"></i> ' + 
                            (key === 'length' ? '6+ characters' : 
                             key === 'lower' ? 'Lowercase' :
                             key === 'upper' ? 'Uppercase' : 'Number');
                    }
                }
            });

            // Calculate strength
            if (requirements.length) strength++;
            if (requirements.lower) strength++;
            if (requirements.upper) strength++;
            if (requirements.number) strength++;

            const bars = document.querySelectorAll('#passwordStrength .bar');
            const text = document.getElementById('strengthText');

            // Reset bars
            bars.forEach(bar => {
                bar.className = 'bar';
            });

            // Update bars and text
            if (password.length === 0) {
                text.textContent = 'Enter a password';
                text.className = 'password-strength-text';
                return;
            }

            let level = '';
            if (strength <= 1) {
                level = 'weak';
                text.textContent = 'Weak password';
                text.className = 'password-strength-text weak';
            } else if (strength === 2) {
                level = 'medium';
                text.textContent = 'Medium password';
                text.className = 'password-strength-text medium';
            } else {
                level = 'strong';
                text.textContent = 'Strong password';
                text.className = 'password-strength-text strong';
            }

            // Activate bars
            const activeBars = strength;
            for (let i = 0; i < activeBars && i < bars.length; i++) {
                bars[i].classList.add('active', level);
            }
        }

        // ===== PASSWORD MATCH =====
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchEl = document.getElementById('passwordMatch');

            if (confirm.length === 0) {
                matchEl.textContent = '';
                matchEl.style.color = 'var(--text-muted)';
                return;
            }

            if (password === confirm) {
                matchEl.textContent = '✓ Passwords match';
                matchEl.style.color = 'var(--accent-green)';
                document.getElementById('confirm_password').classList.remove('error');
                document.getElementById('confirm_password').classList.add('success');
            } else {
                matchEl.textContent = '✗ Passwords do not match';
                matchEl.style.color = 'var(--accent-red)';
                document.getElementById('confirm_password').classList.remove('success');
                document.getElementById('confirm_password').classList.add('error');
            }
        }

        // ===== EVENT LISTENERS =====
        document.getElementById('password').addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
            
            // Validate input
            if (this.value.length >= 6) {
                this.classList.remove('error');
                this.classList.add('success');
            } else if (this.value.length > 0) {
                this.classList.remove('success');
                this.classList.add('error');
            }
        });

        document.getElementById('confirm_password').addEventListener('input', function() {
            checkPasswordMatch();
        });

        document.getElementById('username').addEventListener('blur', function() {
            const val = this.value.trim();
            if (val.length > 0 && val.length < 3) {
                this.classList.add('error');
                this.classList.remove('success');
            } else if (val.length >= 3) {
                this.classList.remove('error');
                this.classList.add('success');
            }
        });

        document.getElementById('email').addEventListener('blur', function() {
            const val = this.value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (val.length > 0 && !emailRegex.test(val)) {
                this.classList.add('error');
                this.classList.remove('success');
            } else if (emailRegex.test(val)) {
                this.classList.remove('error');
                this.classList.add('success');
            }
        });

        // ===== FORM SUBMISSION =====
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const terms = document.getElementById('terms');
            if (!terms.checked) {
                e.preventDefault();
                terms.style.borderColor = 'var(--accent-red)';
                terms.style.outline = '2px solid rgba(239, 68, 68, 0.3)';
                setTimeout(() => {
                    terms.style.borderColor = '';
                    terms.style.outline = '';
                }, 2000);
                
                const errorDiv = document.querySelector('.alert-error');
                if (!errorDiv) {
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-error';
                    alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please accept the Terms of Service and Privacy Policy';
                    const form = document.getElementById('signupForm');
                    form.parentNode.insertBefore(alertDiv, form);
                    setTimeout(() => {
                        alertDiv.remove();
                    }, 4000);
                }
                return;
            }

            const btn = document.getElementById('signupBtn');
            const icon = btn.querySelector('i');
            const text = btn.querySelector('span');
            
            // Show loading state
            btn.classList.add('loading');
            icon.className = 'fas fa-spinner';
            text.textContent = 'Creating account...';
        });

        // ===== TERMS CHECKBOX CLEANUP =====
        document.getElementById('terms').addEventListener('change', function() {
            this.style.borderColor = '';
            this.style.outline = '';
        });

        // ===== KEYBOARD SHORTCUTS =====
        document.addEventListener('keydown', function(e) {
            // Ctrl+Shift+D or Cmd+Shift+D for dark mode toggle
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'd' || e.key === 'D')) {
                e.preventDefault();
                toggleTheme();
            }
            
            // Escape to clear inputs
            if (e.key === 'Escape') {
                document.activeElement.blur();
            }
        });

        // ===== FOCUS MANAGEMENT =====
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus on username field
            document.getElementById('username').focus();
            
            // Add ripple effect to buttons
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
        console.log('🚀 Advanced Signup Page Loaded');
        console.log('⌨️ Shortcut: Ctrl+Shift+D (Cmd+Shift+D) to toggle theme');
        console.log('🔐 Built with ❤️ by Rameez Scripts');
    </script>
</body>
</html>