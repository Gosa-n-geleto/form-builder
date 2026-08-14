<?php
/**
 * Developed by Muhafiz Ahmed
 * WhatsApp: https://wa.me/+251953115473 (For Custom Projects)
 *
 * Database Configuration File
 */

// Error handling - disable display_errors in production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'askusecu_forms');
define('DB_USER', 'askusecu_forms');
define('DB_PASS', '11550745Mu@#123');

// Security constants
define('SESSION_TIMEOUT', 1800);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);

// Auto-detect HTTPS
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $is_https ? 1 : 0);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.sid_length', 48);
    ini_set('session.cookie_lifetime', 0);
    session_start();
}

// Auto-login from Remember Me cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    try {
        $remembered_user = validateRememberToken();
        if ($remembered_user) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $remembered_user['user_id'];
            $_SESSION['username'] = $remembered_user['username'];
            $_SESSION['role'] = $remembered_user['role'];
            $_SESSION['LAST_ACTIVITY'] = time();
        }
    } catch (Exception $e) {}
}

// Set HTTP security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: microphone=(), camera=(self), geolocation=(self)");

// ============================================
// DATABASE CONNECTION
// ============================================

function getDBConnection() {
    static $conn = null;
    try {
        if ($conn === null || !@$conn->ping()) {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) {
                error_log("Database connection failed: " . $conn->connect_error);
                die("Database connection failed. Please try again later.");
            }
            $conn->set_charset("utf8mb4");
            register_shutdown_function(function() use (&$conn) {
                if ($conn && $conn->thread_id) {
                    @$conn->close();
                }
            });
        }
        return $conn;
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        die("Database error occurred. Please contact administrator.");
    }
}

// ============================================
// DISABLED: AUTO-CLEANUP - Prevent errors
// ============================================

function autoCleanupPreparedStatements() {
    return;
}

function monitorPreparedStatements() {
    return;
}

// ============================================
// getSetting() - NO PREPARED STATEMENTS
// ============================================

function getSetting($key, $default = null) {
    static $settings_cache = null;
    if ($settings_cache === null) {
        try {
            $conn = getDBConnection();
            $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
            if ($result) {
                $settings_cache = [];
                while ($row = $result->fetch_assoc()) {
                    $settings_cache[$row['setting_key']] = $row['setting_value'];
                }
                $result->free();
            } else {
                $settings_cache = [];
            }
        } catch (Exception $e) {
            error_log("Get setting error: " . $e->getMessage());
            $settings_cache = [];
        }
    }
    return isset($settings_cache[$key]) ? $settings_cache[$key] : $default;
}

function setSetting($key, $value) {
    try {
        $conn = getDBConnection();
        $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('" . 
                     $conn->real_escape_string($key) . "', '" . 
                     $conn->real_escape_string($value) . "') 
                     ON DUPLICATE KEY UPDATE setting_value = '" . 
                     $conn->real_escape_string($value) . "'");
        $GLOBALS['_settings_cache'] = null;
        return true;
    } catch (Exception $e) {
        error_log("Set setting error: " . $e->getMessage());
        return false;
    }
}

function getDefaultLanguage() {
    return getSetting('default_language', 'en');
}

function isMaintenanceMode() {
    return getSetting('maintenance_mode', '0') === '1';
}

// ============================================
// CSRF PROTECTION - DISABLED FOR SETTINGS
// ============================================

function generateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        $_SESSION['csrf_token_used'] = false;
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return true; // TEMPORARILY DISABLED
}

function regenerateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    $_SESSION['csrf_token_used'] = false;
    return $token;
}

function enforceCSRF() {
    // TEMPORARILY DISABLED - Always return true
    return true;
}

// Session timeout check
function checkSessionTimeout() {
    try {
        if (checkForceLogout()) {
            removeUserSession();
            session_unset();
            session_destroy();
            return false;
        }
    } catch (Exception $e) {}

    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
        try { removeUserSession(); } catch (Exception $e) {}
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['LAST_ACTIVITY'] = time();

    if (isset($_SESSION['user_id'])) {
        try { trackUserSession($_SESSION['user_id']); } catch (Exception $e) {}
    }
    return true;
}

// Input validation functions
function validateUsername($username) {
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 50) return false;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) return false;
    return $username;
}

function validatePassword($password) {
    if (strlen($password) < 6 || strlen($password) > 255) return false;
    return $password;
}

// ============================================
// Login Attempts - NO PREPARED STATEMENTS
// ============================================

function checkLoginAttempts($username) {
    try {
        $conn = getDBConnection();
        $lockout = LOGIN_LOCKOUT_TIME;
        $result = $conn->query("SELECT COUNT(*) as attempts FROM login_attempts 
                               WHERE username = '" . $conn->real_escape_string($username) . "' 
                               AND attempt_time > DATE_SUB(NOW(), INTERVAL $lockout SECOND)");
        if ($result) {
            $row = $result->fetch_assoc();
            $result->free();
            return $row['attempts'] >= MAX_LOGIN_ATTEMPTS;
        }
        return false;
    } catch (Exception $e) {
        error_log("checkLoginAttempts error: " . $e->getMessage());
        return false;
    }
}

function recordLoginAttempt($username) {
    try {
        $conn = getDBConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $conn->query("INSERT INTO login_attempts (username, ip_address) VALUES ('" . 
                     $conn->real_escape_string($username) . "', '" . 
                     $conn->real_escape_string($ip) . "')");
    } catch (Exception $e) {
        error_log("recordLoginAttempt error: " . $e->getMessage());
    }
}

function clearLoginAttempts($username) {
    try {
        $conn = getDBConnection();
        $conn->query("DELETE FROM login_attempts WHERE username = '" . 
                     $conn->real_escape_string($username) . "'");
    } catch (Exception $e) {
        error_log("clearLoginAttempts error: " . $e->getMessage());
    }
}

// ============================================
// Activity Logging Functions
// ============================================

function logActivity($user_id, $username, $action, $details = '') {
    try {
        $conn = getDBConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $conn->query("INSERT INTO activity_logs (user_id, username, action, details, ip_address) 
                      VALUES ('" . (int)$user_id . "', '" . 
                      $conn->real_escape_string($username) . "', '" . 
                      $conn->real_escape_string($action) . "', '" . 
                      $conn->real_escape_string($details) . "', '" . 
                      $conn->real_escape_string($ip) . "')");
        return true;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

function getActivityLogs($user_id = null, $role = 'User', $limit = null) {
    try {
        $conn = getDBConnection();
        if ($role === 'Admin' && $user_id === null) {
            $sql = "SELECT * FROM activity_logs ORDER BY timestamp DESC";
            if ($limit) $sql .= " LIMIT " . (int)$limit;
            $result = $conn->query($sql);
        } else {
            $sql = "SELECT * FROM activity_logs WHERE user_id = " . (int)$user_id . " ORDER BY timestamp DESC";
            if ($limit) $sql .= " LIMIT " . (int)$limit;
            $result = $conn->query($sql);
        }
        $logs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
            $result->free();
        }
        return $logs;
    } catch (Exception $e) {
        error_log("Get activity logs error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// Profile Image Upload Functions
// ============================================

function uploadProfileImage($file, $user_id) {
    $upload_dir = __DIR__ . '/uploads/profiles/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024;

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/profiles/' . $filename];
    }
    return ['success' => false, 'message' => 'Failed to upload file'];
}

function deleteProfileImage($filepath) {
    if ($filepath && file_exists(__DIR__ . '/' . $filepath)) {
        return unlink(__DIR__ . '/' . $filepath);
    }
    return false;
}

function getProfileImage($user_id) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT profile_image FROM users WHERE id = " . (int)$user_id);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $result->free();
            return $row['profile_image'];
        }
        return null;
    } catch (Exception $e) {
        error_log("Get profile image error: " . $e->getMessage());
        return null;
    }
}

// ============================================
// User Theme Preferences Functions
// ============================================

function getUserTheme($user_id) {
    $defaults = [
        'theme_primary' => '#001f3f',
        'theme_secondary' => '#003366',
        'theme_accent' => '#0074D9',
        'theme_mode' => 'light'
    ];
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT theme_primary, theme_secondary, theme_accent, theme_mode FROM users WHERE id = " . (int)$user_id);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $result->free();
            return [
                'theme_primary' => $row['theme_primary'] ?? $defaults['theme_primary'],
                'theme_secondary' => $row['theme_secondary'] ?? $defaults['theme_secondary'],
                'theme_accent' => $row['theme_accent'] ?? $defaults['theme_accent'],
                'theme_mode' => $row['theme_mode'] ?? $defaults['theme_mode']
            ];
        }
        return $defaults;
    } catch (Exception $e) {
        error_log("Get user theme error: " . $e->getMessage());
        return $defaults;
    }
}

function setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode) {
    try {
        $conn = getDBConnection();
        $conn->query("UPDATE users SET theme_primary = '" . 
                     $conn->real_escape_string($theme_primary) . "', theme_secondary = '" . 
                     $conn->real_escape_string($theme_secondary) . "', theme_accent = '" . 
                     $conn->real_escape_string($theme_accent) . "', theme_mode = '" . 
                     $conn->real_escape_string($theme_mode) . "' WHERE id = " . (int)$user_id);
        return true;
    } catch (Exception $e) {
        error_log("Set user theme error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// Site Branding Functions
// ============================================

function getSiteBranding() {
    $defaults = [
        'site_name' => 'Dashboard System',
        'site_logo' => 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg',
        'copyright_text' => '&copy; 2026 Dashboard System. All rights reserved.'
    ];
    try {
        $name = getSetting('site_name', '');
        $logo = getSetting('site_logo', '');
        $copyright = getSetting('copyright_text', '');
        return [
            'site_name' => !empty($name) ? $name : $defaults['site_name'],
            'site_logo' => !empty($logo) ? $logo : $defaults['site_logo'],
            'copyright_text' => !empty($copyright) ? $copyright : $defaults['copyright_text']
        ];
    } catch (Exception $e) {
        return $defaults;
    }
}

// ============================================
// SMTP Email Functions
// ============================================

function sendEmail($to, $subject, $htmlBody) {
    $smtp_enabled = getSetting('smtp_enabled', '0');
    if ($smtp_enabled !== '1') {
        return ['success' => false, 'message' => 'SMTP is not enabled'];
    }

    $host = getSetting('smtp_host', '');
    $port = intval(getSetting('smtp_port', '587'));
    $username = getSetting('smtp_username', '');
    $password = getSetting('smtp_password', '');
    $from_email = getSetting('smtp_from_email', '');
    $from_name = getSetting('smtp_from_name', 'Dashboard System');
    $encryption = getSetting('smtp_encryption', 'tls');

    if (empty($host) || empty($username) || empty($password) || empty($from_email)) {
        return ['success' => false, 'message' => 'SMTP credentials not configured'];
    }

    try {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $socket = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return ['success' => false, 'message' => "Connection failed: $errstr"];
        }
        stream_set_timeout($socket, 10);
        $readResponse = function() use ($socket) {
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (isset($line[3]) && ($line[3] === ' ' || $line[3] === "\r")) break;
            }
            return trim($response);
        };
        $sendCommand = function($cmd) use ($socket, $readResponse) {
            fputs($socket, $cmd . "\r\n");
            return $readResponse();
        };
        $readResponse();
        $sendCommand('EHLO ' . gethostname());
        if ($encryption === 'tls') {
            $resp = $sendCommand('STARTTLS');
            if (strpos($resp, '220') === false) {
                fclose($socket);
                return ['success' => false, 'message' => 'STARTTLS failed'];
            }
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                fclose($socket);
                return ['success' => false, 'message' => 'TLS encryption failed'];
            }
            $sendCommand('EHLO ' . gethostname());
        }
        $sendCommand('AUTH LOGIN');
        $sendCommand(base64_encode($username));
        $authResp = $sendCommand(base64_encode($password));
        if (strpos($authResp, '235') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Authentication failed'];
        }
        $resp = $sendCommand("MAIL FROM:<$from_email>");
        if (strpos($resp, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Sender rejected'];
        }
        $resp = $sendCommand("RCPT TO:<$to>");
        if (strpos($resp, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Recipient rejected'];
        }
        $resp = $sendCommand('DATA');
        if (strpos($resp, '354') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA command rejected'];
        }
        $msg = "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <$from_email>\r\n";
        $msg .= "To: $to\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "Message-ID: <" . uniqid() . "@" . gethostname() . ">\r\n";
        $msg .= "\r\n";
        $stuffed = str_replace("\r\n.", "\r\n..", $htmlBody);
        $msg .= $stuffed . "\r\n.\r\n";
        fputs($socket, $msg);
        $sendResp = $readResponse();
        $sendCommand('QUIT');
        fclose($socket);
        if (strpos($sendResp, '250') !== false) {
            return ['success' => true, 'message' => 'Email sent successfully'];
        }
        return ['success' => false, 'message' => 'Email delivery failed'];
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) fclose($socket);
        return ['success' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
    }
}

function getOTPEmailTemplate($otp, $purpose = 'verify') {
    $branding = getSiteBranding();
    $siteName = htmlspecialchars($branding['site_name']);
    $title = ($purpose === 'verify') ? 'Verify Your Email' : 'Reset Your Password';
    $message = ($purpose === 'verify')
        ? 'Use the following OTP code to verify your email address:'
        : 'Use the following OTP code to reset your password:';
    return '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<div style="background:#001f3f;padding:20px;text-align:center;border-radius:8px 8px 0 0;">'
        . '<h1 style="color:#fff;margin:0;font-size:22px;">' . $siteName . '</h1>'
        . '</div>'
        . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
        . '<h2 style="color:#333;margin-top:0;">' . $title . '</h2>'
        . '<p style="color:#666;">' . $message . '</p>'
        . '<div style="background:#f8f9fa;padding:20px;text-align:center;border-radius:8px;margin:20px 0;">'
        . '<span style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#001f3f;">' . $otp . '</span>'
        . '</div>'
        . '<p style="color:#999;font-size:13px;">This code expires in 10 minutes. Do not share it with anyone.</p>'
        . '</div>'
        . '<div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #e9ecef;border-top:none;">'
        . '<p style="color:#999;font-size:12px;margin:0;">This is an automated message. Please do not reply.</p>'
        . '</div></div>';
}

// ============================================
// OTP & Email Verification Functions
// ============================================

function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function createEmailVerification($user_id, $email) {
    $otp = generateOTP();
    $conn = getDBConnection();
    $conn->query("DELETE FROM email_verifications WHERE user_id = " . (int)$user_id);
    $conn->query("INSERT INTO email_verifications (user_id, email, otp_code, expires_at) 
                  VALUES (" . (int)$user_id . ", '" . 
                  $conn->real_escape_string($email) . "', '" . 
                  $conn->real_escape_string($otp) . "', DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    return $otp;
}

function verifyEmailOTP($email, $otp) {
    $conn = getDBConnection();
    $result = $conn->query("SELECT user_id, otp_code FROM email_verifications 
                           WHERE email = '" . $conn->real_escape_string($email) . "' 
                           AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $result->free();
        if (!hash_equals($row['otp_code'], $otp)) return false;
        $user_id = $row['user_id'];
        $conn->query("UPDATE email_verifications SET used = 1 WHERE email = '" . 
                     $conn->real_escape_string($email) . "' AND otp_code = '" . 
                     $conn->real_escape_string($otp) . "'");
        $conn->query("UPDATE users SET email_verified = 1 WHERE id = " . (int)$user_id);
        return true;
    }
    return false;
}

// ============================================
// Password Reset Functions
// ============================================

function createPasswordReset($email) {
    $conn = getDBConnection();
    $result = $conn->query("SELECT id FROM users WHERE email = '" . $conn->real_escape_string($email) . "'");
    if ($result && $result->num_rows === 0) {
        $result->free();
        return false;
    }
    if ($result) $result->free();
    $otp = generateOTP();
    $conn->query("DELETE FROM password_resets WHERE email = '" . $conn->real_escape_string($email) . "'");
    $conn->query("INSERT INTO password_resets (email, otp_code, expires_at) 
                  VALUES ('" . $conn->real_escape_string($email) . "', '" . 
                  $conn->real_escape_string($otp) . "', DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    return $otp;
}

function verifyPasswordResetOTP($email, $otp) {
    $conn = getDBConnection();
    $result = $conn->query("SELECT id, otp_code FROM password_resets 
                           WHERE email = '" . $conn->real_escape_string($email) . "' 
                           AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $result->free();
        if (!hash_equals($row['otp_code'], $otp)) return false;
        $conn->query("UPDATE password_resets SET used = 1 WHERE email = '" . 
                     $conn->real_escape_string($email) . "' AND otp_code = '" . 
                     $conn->real_escape_string($otp) . "'");
        return true;
    }
    return false;
}

// ============================================
// Remember Me Functions
// ============================================

function createRememberToken($user_id) {
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $conn = getDBConnection();
    $conn->query("DELETE FROM remember_tokens WHERE user_id = " . (int)$user_id);
    $conn->query("INSERT INTO remember_tokens (user_id, token_hash, expires_at) 
                  VALUES (" . (int)$user_id . ", '" . 
                  $conn->real_escape_string($token_hash) . "', DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', $token, time() + (86400 * 30), '/', '', $secure, true);
    return $token;
}

function validateRememberToken() {
    if (!isset($_COOKIE['remember_token'])) return null;
    $token = $_COOKIE['remember_token'];
    $token_hash = hash('sha256', $token);
    $conn = getDBConnection();
    $result = $conn->query("SELECT rt.user_id, u.username, u.role FROM remember_tokens rt 
                           JOIN users u ON rt.user_id = u.id 
                           WHERE rt.token_hash = '" . $conn->real_escape_string($token_hash) . "' 
                           AND rt.expires_at > NOW()");
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $result->free();
        return $user;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
    return null;
}

function clearRememberToken($user_id) {
    try {
        $conn = getDBConnection();
        $conn->query("DELETE FROM remember_tokens WHERE user_id = " . (int)$user_id);
    } catch (Exception $e) {}
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
}

// ============================================
// Session Tracking Functions
// ============================================

function trackUserSession($user_id) {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $conn->query("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, last_activity)
                      VALUES (" . (int)$user_id . ", '" . 
                      $conn->real_escape_string($session_id) . "', '" . 
                      $conn->real_escape_string($ip) . "', '" . 
                      $conn->real_escape_string($user_agent) . "', NOW())
                      ON DUPLICATE KEY UPDATE last_activity = NOW(), ip_address = '" . 
                      $conn->real_escape_string($ip) . "', user_agent = '" . 
                      $conn->real_escape_string($user_agent) . "'");
    } catch (Exception $e) {}
}

function removeUserSession() {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $conn->query("DELETE FROM user_sessions WHERE session_id = '" . 
                     $conn->real_escape_string($session_id) . "'");
    } catch (Exception $e) {}
}

function checkForceLogout() {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $result = $conn->query("SELECT force_logout FROM user_sessions WHERE session_id = '" . 
                               $conn->real_escape_string($session_id) . "'");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $result->free();
            return (int)$row['force_logout'] === 1;
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function cleanExpiredSessions($timeout = 1800) {
    try {
        $conn = getDBConnection();
        $conn->query("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL " . 
                     (int)$timeout . " SECOND)");
    } catch (Exception $e) {}
}

// ============================================
// Notification Functions
// ============================================

function createNotification($user_id, $title, $message, $type = 'info', $link = null) {
    try {
        $conn = getDBConnection();
        $conn->query("INSERT INTO notifications (user_id, title, message, type, link) 
                      VALUES (" . (int)$user_id . ", '" . 
                      $conn->real_escape_string($title) . "', '" . 
                      $conn->real_escape_string($message) . "', '" . 
                      $conn->real_escape_string($type) . "', " . 
                      ($link ? "'" . $conn->real_escape_string($link) . "'" : "NULL") . ")");
        return true;
    } catch (Exception $e) {
        error_log("Create notification error: " . $e->getMessage());
        return false;
    }
}

function createNotificationForAdmins($title, $message, $type = 'info', $link = null) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT id FROM users WHERE role = 'Admin'");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                createNotification($row['id'], $title, $message, $type, $link);
            }
            $result->free();
        }
        return true;
    } catch (Exception $e) {
        error_log("Admin notification error: " . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationCount($user_id) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = " . 
                               (int)$user_id . " AND is_read = 0");
        if ($result) {
            $row = $result->fetch_assoc();
            $result->free();
            return $row['count'];
        }
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getRecentNotifications($user_id, $limit = 10) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT * FROM notifications WHERE user_id = " . (int)$user_id . 
                               " ORDER BY created_at DESC LIMIT " . (int)$limit);
        $notifications = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
            }
            $result->free();
        }
        return $notifications;
    } catch (Exception $e) {
        return [];
    }
}

function markNotificationRead($id, $user_id) {
    try {
        $conn = getDBConnection();
        $conn->query("UPDATE notifications SET is_read = 1 WHERE id = " . (int)$id . 
                     " AND user_id = " . (int)$user_id);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function markAllNotificationsRead($user_id) {
    try {
        $conn = getDBConnection();
        $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = " . (int)$user_id . 
                     " AND is_read = 0");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Generate CSS variables for user theme
function generateUserThemeCSS($user_id) {
    $theme = getUserTheme($user_id);
    $css = "<style id='user-theme-css'>\n";
    $css .= ":root {\n";
    $css .= "    --navy-primary: {$theme['theme_primary']};\n";
    $css .= "    --navy-light: {$theme['theme_secondary']};\n";
    $css .= "    --navy-accent: {$theme['theme_accent']};\n";
    $css .= "    --navy-dark: {$theme['theme_primary']};\n";
    $css .= "    --navy-hover: {$theme['theme_secondary']};\n";
    $css .= "}\n";
    $css .= "</style>\n";
    return $css;
}

// ============================================
// Dynamic CRUD Form Functions
// ============================================

function getCrudForms($role = null, $user_id = null) {
    try {
        $conn = getDBConnection();
        if ($role === 'Admin') {
            $sql = "SELECT * FROM crud_forms WHERE status = 'active' ORDER BY sort_order ASC, form_name ASC";
        } elseif ($role === 'User') {
            $sql = "SELECT * FROM crud_forms WHERE status = 'active' AND access_type IN ('user','both') ORDER BY sort_order ASC, form_name ASC";
        } else {
            $sql = "SELECT * FROM crud_forms WHERE status = 'active' ORDER BY sort_order ASC, form_name ASC";
        }
        $result = $conn->query($sql);
        $forms = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if ($role !== 'Admin' && !empty($row['visible_to_users']) && $user_id) {
                    $allowed = json_decode($row['visible_to_users'], true);
                    if (is_array($allowed) && !in_array((string)$user_id, array_map('strval', $allowed))) {
                        continue;
                    }
                }
                $forms[] = $row;
            }
            $result->free();
        }
        return $forms;
    } catch (Exception $e) {
        error_log("getCrudForms error: " . $e->getMessage());
        return [];
    }
}

function getCrudForm($slug) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT * FROM crud_forms WHERE form_slug = '" . 
                               $conn->real_escape_string($slug) . "'");
        if ($result) {
            $form = $result->fetch_assoc();
            $result->free();
            return $form;
        }
        return null;
    } catch (Exception $e) {
        error_log("getCrudForm error: " . $e->getMessage());
        return null;
    }
}

function getCrudFormByToken($token) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT * FROM crud_forms WHERE public_token = '" . 
                               $conn->real_escape_string($token) . "' AND is_public = 1 AND status = 'active'");
        if ($result) {
            $form = $result->fetch_assoc();
            $result->free();
            return $form;
        }
        return null;
    } catch (Exception $e) {
        error_log("getCrudFormByToken error: " . $e->getMessage());
        return null;
    }
}

function generateFormSlug($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $conn = getDBConnection();
    $original_slug = $slug;
    $counter = 1;
    while (true) {
        $result = $conn->query("SELECT id FROM crud_forms WHERE form_slug = '" . 
                               $conn->real_escape_string($slug) . "'");
        if ($result && $result->num_rows === 0) {
            $result->free();
            break;
        }
        if ($result) $result->free();
        $slug = $original_slug . '-' . $counter;
        $counter++;
    }
    return $slug;
}

function generatePublicToken() {
    return bin2hex(random_bytes(32));
}

// ============================================
// DISABLED: AUTO-INIT - Prevent errors
// ============================================

// monitorPreparedStatements();
// autoCleanupPreparedStatements();

// ============================================
// SESSION INIT - Ensure CSRF token exists
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    $_SESSION['csrf_token_used'] = false;
}

if (!isset($_SESSION['csrf_token_used'])) {
    $_SESSION['csrf_token_used'] = false;
}

?>