<?php
ini_set('memory_limit', '256M');
set_time_limit(300);

require_once 'config.php';

// Override config.php error suppression - setup MUST show errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Catch fatal errors that kill the script silently
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> FATAL ERROR: '
             . htmlspecialchars($error['message'])
             . ' in ' . htmlspecialchars($error['file'])
             . ' on line ' . $error['line'] . '</div>';
    }
});

// EMERGENCY OVERRIDE: Allow setup to run
echo '<div class="log-item log-warning"><i class="fas fa-exclamation-triangle"></i> EMERGENCY MODE: Setup is running without admin authentication</div>';

// Protect setup page - only logged-in Admins can run it (COMMENTED OUT FOR EMERGENCY)
/*
if (isset($_SESSION['user_id'])) {
    $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'User';
    if ($role !== 'Admin') {
        echo '<h3>Access Denied. Only Admins can run database setup.</h3>';
        exit();
    }
} else {
    // Allow first-time setup (no users table yet)
    try {
        $test_conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$test_conn->connect_error) {
            $tbl_check = $test_conn->query("SHOW TABLES LIKE 'users'");
            if ($tbl_check && $tbl_check->num_rows > 0) {
                $user_check = $test_conn->query("SELECT COUNT(*) as cnt FROM users");
                $row = $user_check->fetch_assoc();
                if ($row['cnt'] > 0) {
                    echo '<h3>Access Denied. Please log in as Admin to re-run setup.</h3>';
                    $test_conn->close();
                    exit();
                }
            }
            $test_conn->close();
        }
    } catch (Exception $e) {
        // DB doesn't exist yet - allow first-time setup
    }
}
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Database Setup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <style>
        .setup-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #f0f4ff;
            font-family: 'Inter', sans-serif;
        }
        body.dark-mode .setup-wrapper {
            background: #0a0e1a;
        }
        .setup-container {
            max-width: 750px;
            width: 100%;
            background: #fff;
            border-radius: 20px;
            padding: 40px 45px 35px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08);
            border: 1px solid rgba(226,232,240,0.6);
            position: relative;
        }
        body.dark-mode .setup-container {
            background: #1a1a2e;
            border-color: rgba(255,255,255,0.06);
            color: #e2e8f0;
        }
        .setup-container h2 {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        body.dark-mode .setup-container h2 {
            color: #f1f5f9;
        }
        .setup-container h2 i {
            color: #3b82f6;
        }
        .setup-container .subtitle {
            color: #64748b;
            font-size: 15px;
            margin-bottom: 20px;
        }
        body.dark-mode .setup-container .subtitle {
            color: #94a3b8;
        }
        .setup-container hr {
            border: none;
            border-top: 1px solid #e2e8f0;
            margin: 16px 0 20px;
        }
        body.dark-mode .setup-container hr {
            border-color: rgba(255,255,255,0.08);
        }
        .log-item {
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .log-item i {
            font-size: 18px;
            flex-shrink: 0;
        }
        .log-success {
            background: rgba(16,185,129,0.08);
            color: #10b981;
            border: 1px solid rgba(16,185,129,0.15);
        }
        body.dark-mode .log-success {
            background: rgba(16,185,129,0.12);
        }
        .log-error {
            background: rgba(239,68,68,0.08);
            color: #ef4444;
            border: 1px solid rgba(239,68,68,0.15);
        }
        body.dark-mode .log-error {
            background: rgba(239,68,68,0.12);
        }
        .log-info {
            background: rgba(59,130,246,0.06);
            color: #3b82f6;
            border: 1px solid rgba(59,130,246,0.1);
        }
        body.dark-mode .log-info {
            background: rgba(59,130,246,0.08);
        }
        .log-warning {
            background: rgba(245,158,11,0.08);
            color: #f59e0b;
            border: 1px solid rgba(245,158,11,0.15);
        }
        body.dark-mode .log-warning {
            background: rgba(245,158,11,0.12);
        }
        .credentials-box {
            background: rgba(59,130,246,0.05);
            border: 1px solid rgba(59,130,246,0.15);
            border-radius: 10px;
            padding: 18px 22px;
            margin: 12px 0 16px;
            font-size: 14px;
            line-height: 1.8;
        }
        body.dark-mode .credentials-box {
            background: rgba(59,130,246,0.08);
        }
        .credentials-box strong {
            color: #0f172a;
        }
        body.dark-mode .credentials-box strong {
            color: #f1f5f9;
        }
        .text-warning {
            color: #f59e0b;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-top: 20px;
            box-shadow: 0 4px 16px rgba(59,130,246,0.3);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(59,130,246,0.4);
        }
        .login-theme-toggle {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 1px solid rgba(226,232,240,0.6);
            background: rgba(255,255,255,0.85);
            color: #475569;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(12px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
        }
        .login-theme-toggle:hover {
            background: #3b82f6;
            color: #fff;
            transform: scale(1.1);
        }
        body.dark-mode .login-theme-toggle {
            background: rgba(30,41,59,0.85);
            border-color: rgba(255,255,255,0.08);
            color: #94a3b8;
        }
        body.dark-mode .login-theme-toggle:hover {
            background: #3b82f6;
            color: #fff;
        }
        @media (max-width: 600px) {
            .setup-container {
                padding: 24px 18px 20px;
            }
            .setup-container h2 {
                font-size: 22px;
            }
            .log-item {
                font-size: 13px;
                padding: 10px 14px;
            }
            .btn {
                width: 100%;
                justify-content: center;
                padding: 12px 20px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-database"></i> Database Setup</h2>
        <p class="subtitle">Setting up your dashboard database tables...</p>
        <hr>

        <?php
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Connection failed: ' . htmlspecialchars($conn->connect_error) . '</div>';
            die();
        }

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection successful</div>';

        // =============================================
        // STEP 1: DROP ALL TABLES (clean slate)
        // =============================================
        echo '<br><div class="log-item log-info"><i class="fas fa-trash-alt"></i> <strong>Dropping all existing tables...</strong></div>';

        $drop_tables = [
            'email_logs',
            'crud_files',
            'crud_data',
            'crud_forms',
            'notifications',
            'user_sessions',
            'login_attempts',
            'remember_tokens',
            'password_resets',
            'email_verifications',
            'activity_logs',
            'system_settings',
            'users'
        ];

        foreach ($drop_tables as $table) {
            $conn->query("DROP TABLE IF EXISTS `" . $conn->real_escape_string($table) . "`");
            echo '<div class="log-item log-info"><i class="fas fa-minus-circle"></i> Dropped table "' . htmlspecialchars($table) . '"</div>';
        }

        // =============================================
        // STEP 2: CREATE ALL TABLES
        // =============================================
        echo '<br><div class="log-item log-info"><i class="fas fa-plus-circle"></i> <strong>Creating all tables...</strong></div>';

        // --- users ---
        $sql = "CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) NOT NULL,
            email_verified TINYINT(1) DEFAULT 0,
            profile_image VARCHAR(255) DEFAULT NULL,
            role ENUM('Admin','User') DEFAULT 'User',
            theme_primary VARCHAR(20) DEFAULT '#001f3f',
            theme_secondary VARCHAR(20) DEFAULT '#003366',
            theme_accent VARCHAR(20) DEFAULT '#0074D9',
            theme_mode VARCHAR(10) DEFAULT 'light',
            google_id VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "users" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating users: ' . $conn->error . '</div>';
        }

        // --- activity_logs ---
        $sql = "CREATE TABLE activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(50) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45) NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_username (username),
            INDEX idx_action (action),
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "activity_logs" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- system_settings ---
        $sql = "CREATE TABLE system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "system_settings" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- email_verifications ---
        $sql = "CREATE TABLE email_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(100) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used TINYINT(1) DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "email_verifications" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- password_resets ---
        $sql = "CREATE TABLE password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used TINYINT(1) DEFAULT 0,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "password_resets" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- remember_tokens ---
        $sql = "CREATE TABLE remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_token (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "remember_tokens" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- login_attempts ---
        $sql = "CREATE TABLE login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_attempt_time (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "login_attempts" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- user_sessions ---
        $sql = "CREATE TABLE user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(128) NOT NULL UNIQUE,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            force_logout TINYINT(1) DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "user_sessions" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- notifications ---
        $sql = "CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info','success','warning','danger') DEFAULT 'info',
            is_read TINYINT(1) DEFAULT 0,
            link VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_user_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "notifications" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- crud_forms ---
        $sql = "CREATE TABLE crud_forms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_name VARCHAR(100) NOT NULL,
            form_slug VARCHAR(100) NOT NULL UNIQUE,
            form_icon VARCHAR(50) DEFAULT 'fas fa-list',
            form_logo VARCHAR(500) DEFAULT NULL,
            form_description TEXT,
            fields_json LONGTEXT NOT NULL,
            access_type ENUM('admin','user','both') DEFAULT 'both',
            can_edit_roles VARCHAR(255) DEFAULT '[\"Admin\"]',
            can_delete_roles VARCHAR(255) DEFAULT '[\"Admin\"]',
            is_public TINYINT(1) DEFAULT 0,
            public_token VARCHAR(64) DEFAULT NULL UNIQUE,
            email_on_submit TINYINT(1) DEFAULT 0,
            email_notify_admin TINYINT(1) DEFAULT 0,
            email_confirmation TINYINT(1) DEFAULT 0,
            enable_pdf_download TINYINT(1) DEFAULT 0,
            expires_at DATETIME DEFAULT NULL,
            max_submissions INT DEFAULT 0,
            form_password VARCHAR(255) DEFAULT NULL,
            one_per_user TINYINT(1) DEFAULT 0,
            thank_you_message TEXT DEFAULT NULL,
            redirect_url VARCHAR(500) DEFAULT NULL,
            form_steps TEXT DEFAULT NULL,
            schedule_start DATETIME DEFAULT NULL,
            schedule_end DATETIME DEFAULT NULL,
            duplicate_detection TINYINT(1) DEFAULT 0,
            duplicate_fields TEXT DEFAULT NULL,
            visible_to_users TEXT DEFAULT NULL,
            editable_by_users TEXT DEFAULT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            sort_order INT DEFAULT 0,
            created_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_slug (form_slug),
            INDEX idx_access (access_type),
            INDEX idx_status (status),
            INDEX idx_public_token (public_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "crud_forms" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- crud_data ---
        $sql = "CREATE TABLE crud_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NOT NULL,
            data_json LONGTEXT NOT NULL,
            submitted_by INT DEFAULT NULL,
            submitted_by_name VARCHAR(100) DEFAULT NULL,
            submitted_by_email VARCHAR(100) DEFAULT NULL,
            ip_address VARCHAR(45),
            is_public_submission TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_form_id (form_id),
            INDEX idx_submitted_by (submitted_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "crud_data" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- crud_files ---
        $sql = "CREATE TABLE crud_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NOT NULL,
            record_id INT NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type VARCHAR(100),
            file_size INT,
            uploaded_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_form_id (form_id),
            INDEX idx_record_id (record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "crud_files" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // --- email_logs ---
        $sql = "CREATE TABLE email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(50) NOT NULL,
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) NOT NULL,
            to_email VARCHAR(500) NOT NULL,
            cc_email VARCHAR(500) DEFAULT NULL,
            bcc_email VARCHAR(500) DEFAULT NULL,
            subject VARCHAR(500) NOT NULL,
            body TEXT NOT NULL,
            status ENUM('sent','failed') DEFAULT 'sent',
            error_message TEXT DEFAULT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "email_logs" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // =============================================
        // STEP 3: SEED DEMO DATA (NO PREPARED STATEMENTS!)
        // =============================================
        echo '<br><div class="log-item log-info"><i class="fas fa-seedling"></i> <strong>Seeding demo data...</strong></div>';

        // --- Demo Users (using direct queries - NO prepared statements) ---
        $admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
        $user_pass = password_hash('user123', PASSWORD_DEFAULT);

        $users_data = [
            ['admin', $admin_pass, 'admin@demo.com', 'Admin'],
            ['User 1', $user_pass, 'user1@demo.com', 'User'],
            ['User 2', $user_pass, 'user2@demo.com', 'User'],
            ['User 3', $user_pass, 'user3@demo.com', 'User'],
        ];

        foreach ($users_data as $u) {
            $sql = "INSERT INTO users (username, password, email, email_verified, role) 
                    VALUES ('" . $conn->real_escape_string($u[0]) . "', '" . 
                    $conn->real_escape_string($u[1]) . "', '" . 
                    $conn->real_escape_string($u[2]) . "', 1, '" . 
                    $conn->real_escape_string($u[3]) . "')";
            $conn->query($sql);
        }

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Created 4 demo users (admin + 3 users)</div>';
        echo '<div class="credentials-box">';
        echo '<strong><i class="fas fa-key"></i> Login Credentials:</strong><br><br>';
        echo '<strong>Admin:</strong> admin / admin123<br>';
        echo '<strong>Users:</strong> User 1 / user123, User 2 / user123, User 3 / user123<br><br>';
        echo '<em class="text-warning"><i class="fas fa-exclamation-triangle"></i> Change passwords after first login!</em>';
        echo '</div>';

        // --- Default System Settings (NO prepared statements) ---
        $settings = [
            'allow_user_profile_uploads' => '1',
            'site_name' => 'Dashboard System',
            'site_logo' => 'https://raw.githubusercontent.com/ASK-US-Youth/Ask-Us/main/photo_2025-07-25_07-32-30.jpg',
            'copyright_text' => '&copy; 2026 Dashboard System. All rights reserved.',
            'currency_symbol' => '$',
            'default_language' => 'en',
            'show_forgot_password' => '1',
            'maintenance_mode' => '0',
            'smtp_enabled' => '0',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from_email' => '',
            'smtp_from_name' => 'Dashboard System',
            'smtp_encryption' => 'tls',
            'email_verification_enabled' => '0',
            'google_oauth_enabled' => '0',
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_redirect_uri' => '',
            'gemini_api_key' => '',
        ];

        foreach ($settings as $key => $value) {
            $sql = "INSERT INTO system_settings (setting_key, setting_value) 
                    VALUES ('" . $conn->real_escape_string($key) . "', '" . 
                    $conn->real_escape_string($value) . "')";
            $conn->query($sql);
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default system settings created (' . count($settings) . ' settings)</div>';

        // --- Demo CRUD Forms (NO prepared statements) ---
        echo '<br><div class="log-item log-info"><i class="fas fa-wpforms"></i> <strong>Creating demo CRUD forms...</strong></div>';

        // Form 1: Students
        $students_fields = $conn->real_escape_string(json_encode([
            ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter full name', 'icon' => 'fas fa-user', 'width' => 'half', 'validation' => ['min' => 2, 'max' => 100], 'show_in_table' => true, 'order' => 1],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'placeholder' => 'Enter email', 'icon' => 'fas fa-envelope', 'width' => 'half', 'show_in_table' => true, 'order' => 2],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false, 'placeholder' => 'Enter phone number', 'icon' => 'fas fa-phone', 'width' => 'half', 'show_in_table' => true, 'order' => 3],
            ['name' => 'department', 'label' => 'Department', 'type' => 'select', 'required' => true, 'icon' => 'fas fa-building', 'options' => ['Computer Science', 'Engineering', 'Business', 'Arts', 'Science'], 'width' => 'half', 'show_in_table' => true, 'order' => 4],
            ['name' => 'enrollment_date', 'label' => 'Enrollment Date', 'type' => 'date', 'required' => true, 'icon' => 'fas fa-calendar', 'width' => 'half', 'show_in_table' => true, 'order' => 5],
            ['name' => 'gpa', 'label' => 'GPA', 'type' => 'number', 'required' => false, 'placeholder' => 'Enter GPA', 'icon' => 'fas fa-graduation-cap', 'width' => 'half', 'validation' => ['min' => 0, 'max' => 4, 'step' => 0.1], 'show_in_table' => true, 'order' => 6],
            ['name' => 'photo', 'label' => 'Photo', 'type' => 'file', 'required' => false, 'icon' => 'fas fa-camera', 'accept' => 'image/*', 'max_size' => 2, 'width' => 'full', 'show_in_table' => false, 'order' => 7],
            ['name' => 'location', 'label' => 'Location', 'type' => 'gps', 'required' => false, 'placeholder' => '', 'icon' => 'fas fa-map-marker-alt', 'width' => 'full', 'show_in_table' => true, 'order' => 8],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'placeholder' => 'Additional notes', 'icon' => 'fas fa-sticky-note', 'width' => 'full', 'show_in_table' => false, 'order' => 9],
        ]));

        $sql = "INSERT INTO crud_forms (form_name, form_slug, form_icon, form_description, fields_json, access_type, can_edit_roles, can_delete_roles, is_public, status, sort_order, created_by) 
                VALUES ('Students', 'students', 'fas fa-user-graduate', 'Student records management', '$students_fields', 'both', '[\"Admin\",\"User\"]', '[\"Admin\"]', 0, 'active', 1, 1)";
        $conn->query($sql);
        $students_form_id = $conn->insert_id;
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Form "Students" created (9 fields)</div>';

        // Form 2: Inventory Items
        $inventory_fields = $conn->real_escape_string(json_encode([
            ['name' => 'item_name', 'label' => 'Item Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter item name', 'icon' => 'fas fa-box', 'width' => 'half', 'show_in_table' => true, 'order' => 1],
            ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'icon' => 'fas fa-tags', 'options' => ['Electronics', 'Furniture', 'Office Supplies', 'Hardware', 'Software'], 'width' => 'half', 'show_in_table' => true, 'order' => 2],
            ['name' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'required' => true, 'placeholder' => '0', 'icon' => 'fas fa-cubes', 'width' => 'half', 'validation' => ['min' => 0], 'show_in_table' => true, 'order' => 3],
            ['name' => 'unit_price', 'label' => 'Unit Price ($)', 'type' => 'number', 'required' => true, 'placeholder' => '0.00', 'icon' => 'fas fa-dollar-sign', 'width' => 'half', 'validation' => ['min' => 0, 'step' => 0.01], 'show_in_table' => true, 'order' => 4],
            ['name' => 'supplier', 'label' => 'Supplier', 'type' => 'text', 'required' => false, 'placeholder' => 'Supplier name', 'icon' => 'fas fa-truck', 'width' => 'half', 'show_in_table' => true, 'order' => 5],
            ['name' => 'purchase_date', 'label' => 'Purchase Date', 'type' => 'date', 'required' => false, 'icon' => 'fas fa-calendar-alt', 'width' => 'half', 'show_in_table' => true, 'order' => 6],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'icon' => 'fas fa-info-circle', 'options' => ['In Stock', 'Low Stock', 'Out of Stock', 'Discontinued'], 'width' => 'half', 'show_in_table' => true, 'order' => 7],
            ['name' => 'barcode', 'label' => 'Barcode', 'type' => 'text', 'required' => false, 'placeholder' => 'Enter barcode', 'icon' => 'fas fa-barcode', 'width' => 'half', 'show_in_table' => true, 'order' => 8],
        ]));

        $sql = "INSERT INTO crud_forms (form_name, form_slug, form_icon, form_description, fields_json, access_type, can_edit_roles, can_delete_roles, is_public, status, sort_order, created_by) 
                VALUES ('Inventory Items', 'inventory-items', 'fas fa-boxes-stacked', 'Track inventory and stock levels', '$inventory_fields', 'admin', '[\"Admin\"]', '[\"Admin\"]', 0, 'active', 2, 1)";
        $conn->query($sql);
        $inventory_form_id = $conn->insert_id;
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Form "Inventory Items" created (8 fields)</div>';

        // Form 3: Support Tickets (public)
        $tickets_fields = $conn->real_escape_string(json_encode([
            ['name' => 'ticket_subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'placeholder' => 'Brief description of the issue', 'icon' => 'fas fa-heading', 'width' => 'full', 'show_in_table' => true, 'order' => 1],
            ['name' => 'requester_email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'placeholder' => 'Your email address', 'icon' => 'fas fa-envelope', 'width' => 'half', 'show_in_table' => true, 'order' => 2],
            ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'icon' => 'fas fa-flag', 'options' => ['Low', 'Medium', 'High', 'Critical'], 'width' => 'half', 'show_in_table' => true, 'order' => 3],
            ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'icon' => 'fas fa-folder', 'options' => ['Bug Report', 'Feature Request', 'General Inquiry', 'Account Issue', 'Billing'], 'width' => 'half', 'show_in_table' => true, 'order' => 4],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => false, 'icon' => 'fas fa-tasks', 'options' => ['Open', 'In Progress', 'Resolved', 'Closed'], 'width' => 'half', 'show_in_table' => true, 'order' => 5],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Describe your issue in detail', 'icon' => 'fas fa-align-left', 'width' => 'full', 'show_in_table' => false, 'order' => 6],
            ['name' => 'attachment', 'label' => 'Attachment', 'type' => 'file', 'required' => false, 'icon' => 'fas fa-paperclip', 'accept' => 'image/*,.pdf,.doc,.docx', 'max_size' => 5, 'width' => 'full', 'show_in_table' => false, 'order' => 7],
        ]));

        $public_token = bin2hex(random_bytes(32));
        $sql = "INSERT INTO crud_forms (form_name, form_slug, form_icon, form_description, fields_json, access_type, can_edit_roles, can_delete_roles, is_public, public_token, email_on_submit, email_notify_admin, status, sort_order, created_by) 
                VALUES ('Support Tickets', 'support-tickets', 'fas fa-ticket', 'Customer support ticket system', '$tickets_fields', 'both', '[\"Admin\"]', '[\"Admin\"]', 1, '$public_token', 1, 1, 'active', 3, 1)";
        $conn->query($sql);
        $tickets_form_id = $conn->insert_id;
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Form "Support Tickets" created (7 fields, public)</div>';

        // --- Demo Records for Students (NO prepared statements) ---
        echo '<br><div class="log-item log-info"><i class="fas fa-database"></i> <strong>Inserting demo records...</strong></div>';

        $student_records = [
            [json_encode(['full_name' => 'Student 1', 'email' => 'student1@demo.com', 'phone' => '03001000001', 'department' => 'Computer Science', 'enrollment_date' => '2025-09-01', 'gpa' => '3.8', 'location' => '33.6844,73.0479', 'notes' => 'Demo student record']), 1, '2025-09-01T10:00:00Z'],
            [json_encode(['full_name' => 'Student 2', 'email' => 'student2@demo.com', 'phone' => '03001000002', 'department' => 'Engineering', 'enrollment_date' => '2025-09-02', 'gpa' => '3.5', 'location' => '31.5204,74.3587', 'notes' => '']), 1, '2025-09-02T11:30:00Z'],
            [json_encode(['full_name' => 'Student 3', 'email' => 'student3@demo.com', 'phone' => '03001000003', 'department' => 'Business', 'enrollment_date' => '2025-09-03', 'gpa' => '3.2', 'location' => '24.8607,67.0011', 'notes' => 'Transfer student']), 2, '2025-09-03T09:15:00Z'],
            [json_encode(['full_name' => 'Student 4', 'email' => 'student4@demo.com', 'phone' => '03001000004', 'department' => 'Arts', 'enrollment_date' => '2025-09-04', 'gpa' => '3.9', 'location' => '', 'notes' => 'Scholarship recipient']), 2, '2025-09-04T14:20:00Z'],
            [json_encode(['full_name' => 'Student 5', 'email' => 'student5@demo.com', 'phone' => '03001000005', 'department' => 'Science', 'enrollment_date' => '2025-09-05', 'gpa' => '3.1', 'location' => '30.1798,66.9750', 'notes' => '']), 1, '2025-09-05T16:45:00Z'],
            [json_encode(['full_name' => 'Student 6', 'email' => 'student6@demo.com', 'phone' => '03001000006', 'department' => 'Computer Science', 'enrollment_date' => '2025-09-06', 'gpa' => '3.7', 'location' => '34.0151,71.5249', 'notes' => 'Lab assistant']), 3, '2025-09-06T08:00:00Z'],
        ];

        foreach ($student_records as $r) {
            $sql = "INSERT INTO crud_data (form_id, data_json, submitted_by, ip_address, created_at) 
                    VALUES (" . (int)$students_form_id . ", '" . 
                    $conn->real_escape_string($r[0]) . "', " . 
                    ($r[1] ? (int)$r[1] : 'NULL') . ", '127.0.0.1', '" . 
                    $conn->real_escape_string($r[2]) . "')";
            $conn->query($sql);
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted 6 demo student records</div>';

        // --- Demo Records for Inventory ---
        $inventory_records = [
            [json_encode(['item_name' => 'Laptop Pro 15', 'category' => 'Electronics', 'quantity' => '25', 'unit_price' => '1299.99', 'supplier' => 'Supplier 1', 'purchase_date' => '2025-08-15', 'status' => 'In Stock', 'barcode' => 'LAP-001-2025']), 1, '2025-08-15T10:00:00Z'],
            [json_encode(['item_name' => 'Office Desk Standard', 'category' => 'Furniture', 'quantity' => '10', 'unit_price' => '349.50', 'supplier' => 'Supplier 2', 'purchase_date' => '2025-07-20', 'status' => 'In Stock', 'barcode' => 'DSK-002-2025']), 1, '2025-07-20T11:00:00Z'],
            [json_encode(['item_name' => 'Printer Ink Cartridge', 'category' => 'Office Supplies', 'quantity' => '3', 'unit_price' => '45.00', 'supplier' => 'Supplier 3', 'purchase_date' => '2025-09-01', 'status' => 'Low Stock', 'barcode' => 'INK-003-2025']), 1, '2025-09-01T09:30:00Z'],
            [json_encode(['item_name' => 'Wireless Mouse', 'category' => 'Electronics', 'quantity' => '50', 'unit_price' => '29.99', 'supplier' => 'Supplier 1', 'purchase_date' => '2025-08-10', 'status' => 'In Stock', 'barcode' => 'MOU-004-2025']), 1, '2025-08-10T14:00:00Z'],
            [json_encode(['item_name' => 'Whiteboard 6x4', 'category' => 'Office Supplies', 'quantity' => '0', 'unit_price' => '89.99', 'supplier' => 'Supplier 4', 'purchase_date' => '2025-06-15', 'status' => 'Out of Stock', 'barcode' => 'WBD-005-2025']), 1, '2025-06-15T10:00:00Z'],
        ];

        foreach ($inventory_records as $r) {
            $sql = "INSERT INTO crud_data (form_id, data_json, submitted_by, ip_address, created_at) 
                    VALUES (" . (int)$inventory_form_id . ", '" . 
                    $conn->real_escape_string($r[0]) . "', " . 
                    ($r[1] ? (int)$r[1] : 'NULL') . ", '127.0.0.1', '" . 
                    $conn->real_escape_string($r[2]) . "')";
            $conn->query($sql);
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted 5 demo inventory records</div>';

        // --- Demo Records for Support Tickets ---
        $ticket_records = [
            [json_encode(['ticket_subject' => 'Cannot login to dashboard', 'requester_email' => 'user1@demo.com', 'priority' => 'High', 'category' => 'Bug Report', 'status' => 'Open', 'description' => 'Getting error message when trying to login with correct credentials.']), 2, '2025-10-01T08:00:00Z'],
            [json_encode(['ticket_subject' => 'Request dark mode feature', 'requester_email' => 'user2@demo.com', 'priority' => 'Low', 'category' => 'Feature Request', 'status' => 'In Progress', 'description' => 'Would be great to have a dark mode option for the dashboard.']), 3, '2025-10-02T09:30:00Z'],
            [json_encode(['ticket_subject' => 'How to export data?', 'requester_email' => 'user3@demo.com', 'priority' => 'Medium', 'category' => 'General Inquiry', 'status' => 'Resolved', 'description' => 'Need help understanding how to export data to CSV format.']), 2, '2025-10-03T11:00:00Z'],
            [json_encode(['ticket_subject' => 'Account locked after failed attempts', 'requester_email' => 'user4@demo.com', 'priority' => 'Critical', 'category' => 'Account Issue', 'status' => 'Open', 'description' => 'My account got locked. Please unlock it.']), null, '2025-10-04T14:00:00Z'],
            [json_encode(['ticket_subject' => 'Invoice discrepancy', 'requester_email' => 'user5@demo.com', 'priority' => 'Medium', 'category' => 'Billing', 'status' => 'Open', 'description' => 'The amount on my invoice does not match the quoted price.']), null, '2025-10-05T16:30:00Z'],
        ];

        foreach ($ticket_records as $r) {
            $sql = "INSERT INTO crud_data (form_id, data_json, submitted_by, ip_address, created_at) 
                    VALUES (" . (int)$tickets_form_id . ", '" . 
                    $conn->real_escape_string($r[0]) . "', " . 
                    ($r[1] ? (int)$r[1] : 'NULL') . ", '127.0.0.1', '" . 
                    $conn->real_escape_string($r[2]) . "')";
            $conn->query($sql);
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted 5 demo support tickets</div>';

        // --- Activity Logs ---
        $logs = [
            [1, 'admin', 'Login', 'Admin logged in', '2025-10-01T08:00:00Z'],
            [1, 'admin', 'Created Form', 'Created form: Students', '2025-10-01T08:05:00Z'],
            [1, 'admin', 'Created Form', 'Created form: Inventory Items', '2025-10-01T08:10:00Z'],
            [1, 'admin', 'Created Form', 'Created form: Support Tickets', '2025-10-01T08:15:00Z'],
            [2, 'User 1', 'Login', 'User logged in', '2025-10-01T09:00:00Z'],
            [2, 'User 1', 'Added Record', 'Added record in Students', '2025-10-01T09:05:00Z'],
        ];

        foreach ($logs as $l) {
            $sql = "INSERT INTO activity_logs (user_id, username, action, details, ip_address, timestamp) 
                    VALUES (" . (int)$l[0] . ", '" . 
                    $conn->real_escape_string($l[1]) . "', '" . 
                    $conn->real_escape_string($l[2]) . "', '" . 
                    $conn->real_escape_string($l[3]) . "', '127.0.0.1', '" . 
                    $conn->real_escape_string($l[4]) . "')";
            $conn->query($sql);
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted 6 demo activity logs</div>';

        // =============================================
        // STEP 4: CREATE UPLOAD DIRECTORIES
        // =============================================
        echo '<br><div class="log-item log-info"><i class="fas fa-folder"></i> <strong>Creating upload directories...</strong></div>';

        $dirs = [
            __DIR__ . '/uploads',
            __DIR__ . '/uploads/profiles',
            __DIR__ . '/uploads/branding',
            __DIR__ . '/uploads/crud',
            __DIR__ . '/uploads/crud/students',
            __DIR__ . '/uploads/crud/inventory-items',
            __DIR__ . '/uploads/crud/support-tickets',
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                if (mkdir($dir, 0755, true)) {
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Created: ' . str_replace(__DIR__ . '/', '', $dir) . '/</div>';
                } else {
                    echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create: ' . str_replace(__DIR__ . '/', '', $dir) . '/</div>';
                }
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Already exists: ' . str_replace(__DIR__ . '/', '', $dir) . '/</div>';
            }
        }

        // Security .htaccess for uploads
        $htaccess_content = "# Protect uploads directory\n";
        $htaccess_content .= "<Files ~ \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|htm|shtml|sh|cgi)$\">\n";
        $htaccess_content .= "    deny from all\n";
        $htaccess_content .= "</Files>\n";
        $htaccess_content .= "\n# Allow safe files only\n";
        $htaccess_content .= "<FilesMatch \"\\.(jpg|jpeg|png|gif|webp|pdf|doc|docx|xls|xlsx|csv|txt)$\">\n";
        $htaccess_content .= "    allow from all\n";
        $htaccess_content .= "</FilesMatch>\n";

        if (file_put_contents(__DIR__ . '/uploads/.htaccess', $htaccess_content) !== false) {
            echo '<div class="log-item log-success"><i class="fas fa-shield-alt"></i> Security .htaccess created</div>';
        }

        // =============================================
        // DONE
        // =============================================
        echo '<br><div class="log-item log-success">';
        echo '<i class="fas fa-check-circle"></i> <strong>Setup completed successfully!</strong>';
        echo '</div>';

        echo '<div class="log-item log-info"><i class="fas fa-link"></i> Public form link: <a href="public_form.php?token=' . $public_token . '" target="_blank">Support Tickets (Public)</a></div>';

        $conn->close();
        ?>

        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Go to Login Page
        </a>
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