<?php
/**
 * Developed by Muhafiz Ahmed
 * WhatsApp: https://wa.me/+251953115473 (For Custom Projects)
 *
 * Email OTP Verification Page
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
    header("Location: login.php");
    exit();
}

// Get site branding
$branding = getSiteBranding();

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'resend') {
        // Resend OTP
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND email_verified = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $stmt->close();

                $otp = createEmailVerification($user['id'], $email);
                $emailBody = getOTPEmailTemplate($otp, 'verify');
                $emailResult = sendEmail($email, 'Verify Your Email - ' . $branding['site_name'], $emailBody);

                if ($emailResult['success']) {
                    $success = 'A new OTP has been sent to your email.';
                } else {
                    $error = 'Failed to send OTP. Please try again.';
                }
            } else {
                $stmt->close();
                header("Location: login.php");
                exit();
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
        }
    } elseif (isset($_POST['otp'])) {
        // Verify OTP
        $otp = trim($_POST['otp']);

        if (strlen($otp) !== 6 || !ctype_digit($otp)) {
            $error = 'Please enter a valid 6-digit OTP code.';
        } else {
            if (verifyEmailOTP($email, $otp)) {
                header("Location: login.php?verified=1");
                exit();
            } else {
                $error = 'Invalid or expired OTP code. Please try again.';
            }
        }
    }
}
?>
<!--
  Developed by Muhafiz Ahmed
  WhatsApp: https://wa.me/+251953115473 (For Custom Projects)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Verify Email - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="login-logo">
            <h2>Verify Your Email</h2>
            <p class="login-subtitle">
                We sent a 6-digit OTP code to<br>
                <strong class="highlight"><?php echo htmlspecialchars($email); ?></strong>
            </p>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label><i class="fas fa-key"></i> Enter OTP Code</label>
                    <input type="text" name="otp" required autofocus maxlength="6" minlength="6" pattern="[0-9]{6}" placeholder="000000" class="otp-input" autocomplete="one-time-code" inputmode="numeric">
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-check-circle"></i> Verify Email
                </button>
            </form>

            <div class="resend-section">
                <p class="resend-countdown" id="resendText">
                    Didn't receive the code? Wait <span id="countdown" class="timer">60</span>s
                </p>
                <form method="POST" action="" id="resendForm" class="initially-hidden">
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="btn btn-secondary btn-block">
                        <i class="fas fa-redo"></i> Resend OTP
                    </button>
                </form>
            </div>

            <div class="login-footer">
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
    // Resend cooldown timer
    let countdown = 60;
    const countdownEl = document.getElementById('countdown');
    const resendText = document.getElementById('resendText');
    const resendForm = document.getElementById('resendForm');

    const timer = setInterval(function() {
        countdown--;
        if (countdownEl) countdownEl.textContent = countdown;
        if (countdown <= 0) {
            clearInterval(timer);
            resendText.style.display = 'none';
            resendForm.style.display = 'block';
        }
    }, 1000);

    // Theme Toggle
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
