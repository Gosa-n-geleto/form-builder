<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Reset Password Page (OTP + New Password)
 */

require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: forgot_password.php");
    exit();
}

// Get site branding
$branding = getSiteBranding();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = isset($_POST['otp']) ? trim($_POST['otp']) : '';
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    if (strlen($otp) !== 6 || !ctype_digit($otp)) {
        $error = 'Please enter a valid 6-digit OTP code.';
    } elseif (strlen($new_password) < 6 || strlen($new_password) > 255) {
        $error = 'Password must be between 6 and 255 characters.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        if (verifyPasswordResetOTP($email, $otp)) {
            // Update password
            try {
                $conn = getDBConnection();
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashed, $email);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $stmt->close();

                    // Get user for logging
                    $log_stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
                    $log_stmt->bind_param("s", $email);
                    $log_stmt->execute();
                    $log_result = $log_stmt->get_result();
                    if ($log_result->num_rows > 0) {
                        $log_user = $log_result->fetch_assoc();
                        logActivity($log_user['id'], $log_user['username'], 'Password Reset', 'Password reset via email OTP');
                    }
                    $log_stmt->close();

                    header("Location: login.php?reset=1");
                    exit();
                } else {
                    $stmt->close();
                    $error = 'Failed to update password. Please try again.';
                }
            } catch (Exception $e) {
                $error = 'An error occurred. Please try again.';
                error_log("Password reset error: " . $e->getMessage());
            }
        } else {
            $error = 'Invalid or expired OTP code. Please request a new one.';
        }
    }
}
?>
<!--
  Developed by Rameez Scripts
  WhatsApp: https://wa.me/923224083545 (For Custom Projects)
  YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Reset Password - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="login-logo">
            <h2>Reset Password</h2>
            <p class="login-subtitle">
                Enter the OTP sent to <strong class="highlight"><?php echo htmlspecialchars($email); ?></strong> and your new password.
            </p>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label><i class="fas fa-key"></i> OTP Code</label>
                    <input type="text" name="otp" required autofocus maxlength="6" minlength="6" pattern="[0-9]{6}" placeholder="000000" class="otp-input" autocomplete="one-time-code" inputmode="numeric">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> New Password</label>
                    <input type="password" name="new_password" required autocomplete="new-password" minlength="6" placeholder="Minimum 6 characters">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Confirm Password</label>
                    <input type="password" name="confirm_password" required autocomplete="new-password" minlength="6" placeholder="Confirm new password">
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Reset Password
                </button>
            </form>

            <div class="login-footer">
                <p class="login-footer-link"><a href="forgot_password.php" class="login-link"><i class="fas fa-redo"></i> Request New OTP</a></p>
                <p class="login-footer-link"><a href="login.php" class="login-link"><i class="fas fa-arrow-left"></i> Back to Login</a></p>
                <p><?php echo $branding['copyright_text']; ?></p>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
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
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    initTheme();
    </script>
</body>
</html>
