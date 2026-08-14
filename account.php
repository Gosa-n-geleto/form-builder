<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'User';
$user_id = $_SESSION['user_id'];
$current_page = 'account';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    enforceCSRF();

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getAccountInfo':
                // Use direct query to avoid prepared statement issues
                $query = "SELECT id, username, email, role, profile_image, created_at, 
                          COALESCE(theme_primary, '#001f3f') as theme_primary, 
                          COALESCE(theme_secondary, '#003366') as theme_secondary, 
                          COALESCE(theme_accent, '#0074D9') as theme_accent, 
                          COALESCE(theme_mode, 'light') as theme_mode 
                          FROM users WHERE id = " . intval($user_id);
                $result = $conn->query($query);

                if ($result && $result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';
                    
                    $result->free();
                    $conn->close();

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                            'profile_image' => $user['profile_image'],
                            'created_at' => date('M d, Y H:i:s', strtotime($user['created_at'])),
                            'allow_user_uploads' => $allowUserUploads,
                            'theme_primary' => $user['theme_primary'] ?? '#001f3f',
                            'theme_secondary' => $user['theme_secondary'] ?? '#003366',
                            'theme_accent' => $user['theme_accent'] ?? '#0074D9',
                            'theme_mode' => $user['theme_mode'] ?? 'light'
                        ]
                    ]);
                } else {
                    if ($result) $result->free();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                }
                exit();

            case 'saveTheme':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $theme_primary = isset($_POST['theme_primary']) ? trim($_POST['theme_primary']) : '#001f3f';
                $theme_secondary = isset($_POST['theme_secondary']) ? trim($_POST['theme_secondary']) : '#003366';
                $theme_accent = isset($_POST['theme_accent']) ? trim($_POST['theme_accent']) : '#0074D9';
                $theme_mode = isset($_POST['theme_mode']) ? trim($_POST['theme_mode']) : 'light';

                // Validate hex colors
                $color_pattern = '/^#[0-9A-Fa-f]{6}$/';
                if (!preg_match($color_pattern, $theme_primary) ||
                    !preg_match($color_pattern, $theme_secondary) ||
                    !preg_match($color_pattern, $theme_accent)) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Invalid color format. Use hex colors like #001f3f']);
                    exit();
                }

                // Validate theme mode
                if (!in_array($theme_mode, ['light', 'dark'])) {
                    $theme_mode = 'light';
                }

                $result = setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode);
                $conn->close();

                if ($result) {
                    logActivity($user_id, $username, 'Theme Updated', "Updated UI colors: Primary=$theme_primary, Secondary=$theme_secondary, Accent=$theme_accent, Mode=$theme_mode");
                    echo json_encode(['success' => true, 'message' => 'Theme settings saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save theme settings']);
                }
                exit();

            case 'resetTheme':
                $result = setUserTheme($user_id, '#001f3f', '#003366', '#0074D9', 'light');
                $conn->close();

                if ($result) {
                    logActivity($user_id, $username, 'Theme Reset', 'Reset UI colors to default');
                    echo json_encode(['success' => true, 'message' => 'Theme reset to default']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset theme']);
                }
                exit();

            case 'updateProfile':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $newUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';

                // Validate inputs
                if (empty($newUsername) || empty($email)) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                $newUsername = validateUsername($newUsername);
                if ($newUsername === false) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Invalid username format. Use 3-50 alphanumeric characters.']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // Check if username is already taken by another user - use direct query
                $escaped_username = $conn->real_escape_string($newUsername);
                $result = $conn->query("SELECT id FROM users WHERE username = '$escaped_username' AND id != " . intval($user_id));

                if ($result && $result->num_rows > 0) {
                    $result->free();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Username already taken']);
                    exit();
                }
                if ($result) $result->free();

                // Update profile using direct query
                $escaped_email = $conn->real_escape_string($email);
                $query = "UPDATE users SET username = '$escaped_username', email = '$escaped_email' WHERE id = " . intval($user_id);
                
                if ($conn->query($query)) {
                    // Update session username if changed
                    $_SESSION['username'] = $newUsername;

                    // Log activity
                    logActivity($user_id, $newUsername, 'Profile Updated', 'Updated username and email');

                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Profile updated successfully', 'new_username' => $newUsername]);
                } else {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
                }
                exit();

            case 'changePassword':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
                $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
                $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

                // Validate inputs
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if ($newPassword !== $confirmPassword) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit();
                }

                $newPassword = validatePassword($newPassword);
                if ($newPassword === false) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Password must be 6-255 characters']);
                    exit();
                }

                // Verify current password - use direct query
                $result = $conn->query("SELECT password FROM users WHERE id = " . intval($user_id));

                if ($result && $result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    $result->free();

                    if (!password_verify($currentPassword, $user['password'])) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                        exit();
                    }
                } else {
                    if ($result) $result->free();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }

                // Update password using direct query
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $escaped_password = $conn->real_escape_string($hashedPassword);
                $query = "UPDATE users SET password = '$escaped_password' WHERE id = " . intval($user_id);

                if ($conn->query($query)) {
                    // Log activity
                    logActivity($user_id, $username, 'Password Changed', 'User changed their password');

                    // Notify user
                    try { createNotification($user_id, 'Password Changed', 'Your password was changed successfully. If this wasn\'t you, contact an administrator immediately.', 'warning', 'account.php'); } catch (Exception $e) {}

                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
                } else {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to change password']);
                }
                exit();

            default:
                $conn->close();
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Account.php error: " . $e->getMessage());
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle profile image upload (separate from AJAX JSON responses)
if (isset($_POST['action']) && $_POST['action'] === 'uploadProfileImage') {
    enforceCSRF();
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();
        
        // Check if user uploads are allowed
        $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';
        if (!$allowUserUploads && $role !== 'Admin') {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Profile uploads are currently disabled']);
            exit();
        }

        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
            exit();
        }

        // Upload the file
        $uploadResult = uploadProfileImage($_FILES['profile_image'], $user_id);

        if (!$uploadResult['success']) {
            $conn->close();
            echo json_encode($uploadResult);
            exit();
        }

        // Get old profile image
        $oldImage = getProfileImage($user_id);

        // Update database using direct query
        $escaped_filename = $conn->real_escape_string($uploadResult['filename']);
        $query = "UPDATE users SET profile_image = '$escaped_filename' WHERE id = " . intval($user_id);

        if ($conn->query($query)) {
            // Delete old image if exists
            if ($oldImage) {
                deleteProfileImage($oldImage);
            }

            // Log activity
            logActivity($user_id, $username, 'Profile Image Updated', 'User uploaded a new profile image');

            $conn->close();
            echo json_encode([
                'success' => true,
                'message' => 'Profile image uploaded successfully',
                'image_url' => $uploadResult['filename']
            ]);
        } else {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Failed to update profile image']);
        }
    } catch (Exception $e) {
        error_log("Profile image upload error: " . $e->getMessage());
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

// If we reach here, render the HTML page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>My Account · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           ACCOUNT PAGE - WORLD-CLASS MODERN UI
           Same design language as other pages
           ============================================================ */

        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #111118;
            --bg-card: #16161f;
            --bg-card-hover: #1c1c28;
            --bg-input: #1a1a26;
            --bg-input-hover: #222234;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: rgba(255,255,255,0.06);
            --border-hover: rgba(255,255,255,0.12);
            --accent-blue: #3b82f6;
            --accent-blue-bg: rgba(59, 130, 246, 0.12);
            --accent-green: #10b981;
            --accent-green-bg: rgba(16, 185, 129, 0.12);
            --accent-red: #ef4444;
            --accent-red-bg: rgba(239, 68, 68, 0.12);
            --accent-amber: #f59e0b;
            --accent-amber-bg: rgba(245, 158, 11, 0.12);
            --accent-purple: #8b5cf6;
            --accent-purple-bg: rgba(139, 92, 246, 0.12);
            --accent-pink: #ec4899;
            --accent-pink-bg: rgba(236, 72, 153, 0.12);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.5);
            --shadow-xl: 0 16px 64px rgba(0,0,0,0.6);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 18px;
            --radius-full: 9999px;
            --transition-fast: 0.12s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="light"],
        body:not(.dark-mode) {
            --bg-primary: #f6f8fa;
            --bg-secondary: #eef2f6;
            --bg-card: #ffffff;
            --bg-card-hover: #f0f2f5;
            --bg-input: #f0f2f5;
            --bg-input-hover: #e4e8ee;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border-color: rgba(0,0,0,0.06);
            --border-hover: rgba(0,0,0,0.12);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.06);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.08);
            --shadow-xl: 0 16px 64px rgba(0,0,0,0.1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            transition: background var(--transition-slow), color var(--transition-slow);
        }

        /* Breadcrumb */
        .breadcrumb-modern {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--text-muted);
            padding: 20px 0 12px; margin-bottom: 4px;
        }
        .breadcrumb-modern a { color: var(--text-secondary); text-decoration: none; transition: color var(--transition-fast); display: flex; align-items: center; gap: 6px; }
        .breadcrumb-modern a:hover { color: var(--accent-blue); }
        .breadcrumb-modern .sep { color: var(--text-muted); font-size: 10px; }
        .breadcrumb-modern .current { color: var(--text-secondary); font-weight: 500; }

        /* Page Header */
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            flex-wrap: wrap; gap: 20px; padding: 4px 0 24px;
            border-bottom: 1px solid var(--border-color); margin-bottom: 28px;
        }
        .page-header-left { display: flex; align-items: center; gap: 20px; }
        .page-header-left .icon-wrapper {
            width: 56px; height: 56px; border-radius: var(--radius-lg);
            background: linear-gradient(135deg, var(--accent-purple-bg), rgba(139, 92, 246, 0.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: var(--accent-purple);
            border: 1px solid var(--border-color); flex-shrink: 0;
            position: relative;
        }
        .page-header-left .icon-wrapper .pulse-dot {
            position: absolute; top: -4px; right: -4px;
            width: 12px; height: 12px; border-radius: var(--radius-full);
            background: var(--accent-green); border: 2px solid var(--bg-card);
            animation: pulse-dot 2s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }
        .page-header-left .title-group h1 {
            font-size: 28px; font-weight: 700; margin: 0;
            letter-spacing: -0.03em; color: var(--text-primary); line-height: 1.2;
        }
        .page-header-left .title-group .subtitle {
            font-size: 14px; color: var(--text-secondary);
            margin: 2px 0 0; font-weight: 400;
        }
        .page-header-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 22px; border-radius: var(--radius-md);
            font-size: 14px; font-weight: 600; font-family: inherit;
            border: 1px solid var(--border-color); cursor: pointer;
            transition: all var(--transition-fast); text-decoration: none;
            background: var(--bg-card); color: var(--text-secondary);
            user-select: none;
        }
        .btn:active { transform: scale(0.96); }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent-blue), #2563eb);
            color: #fff; border-color: transparent;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--accent-blue-hover), #3b82f6);
            color: #fff; box-shadow: 0 4px 24px rgba(59, 130, 246, 0.35);
            transform: translateY(-1px);
        }
        .btn-success {
            background: linear-gradient(135deg, var(--accent-green), #059669);
            color: #fff; border-color: transparent;
        }
        .btn-success:hover {
            box-shadow: 0 4px 24px rgba(16, 185, 129, 0.35);
            transform: translateY(-1px); color: #fff;
        }
        .btn-ghost { background: transparent; border-color: transparent; color: var(--text-muted); }
        .btn-ghost:hover { background: var(--bg-card); border-color: var(--border-color); color: var(--text-primary); }
        .btn-sm { padding: 6px 14px; font-size: 12px; border-radius: var(--radius-sm); }

        /* Card */
        .card-modern {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
            margin-bottom: 28px;
        }
        .card-modern:hover { border-color: var(--border-hover); }

        .card-modern .card-header {
            display: flex; align-items: center; gap: 14px;
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .card-modern .card-header .header-icon {
            width: 40px; height: 40px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .header-icon-purple { background: var(--accent-purple-bg); color: var(--accent-purple); }
        .header-icon-blue { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .header-icon-green { background: var(--accent-green-bg); color: var(--accent-green); }
        .header-icon-amber { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .header-icon-pink { background: var(--accent-pink-bg); color: var(--accent-pink); }

        .card-modern .card-header .header-title {
            font-size: 16px; font-weight: 600; color: var(--text-primary);
        }
        .card-modern .card-header .header-subtitle {
            font-size: 13px; color: var(--text-muted);
        }
        .card-modern .card-body { padding: 24px; }

        /* Profile Image Section */
        .profile-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 32px;
            align-items: start;
        }
        .profile-image-container {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-input);
            flex-shrink: 0;
            transition: all var(--transition-fast);
        }
        .profile-image-container:hover {
            border-color: var(--accent-blue);
        }
        .profile-image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-image-container .default-icon {
            font-size: 64px;
            color: var(--text-muted);
            opacity: 0.5;
        }
        .profile-image-label {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }

        .profile-upload-area {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .profile-upload-area .file-drop-zone {
            padding: 24px 32px;
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--bg-secondary);
        }
        .profile-upload-area .file-drop-zone:hover {
            border-color: var(--accent-blue);
            background: var(--bg-card-hover);
        }
        .profile-upload-area .file-drop-zone i {
            font-size: 32px;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: block;
        }
        .profile-upload-area .file-drop-zone .drop-text {
            font-size: 14px;
            color: var(--text-secondary);
        }
        .profile-upload-area .file-drop-zone .drop-hint {
            font-size: 12px;
            color: var(--text-muted);
        }
        .profile-upload-area .upload-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .profile-upload-area .upload-actions .btn { flex: 1; justify-content: center; }

        /* Account Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .info-item {
            padding: 14px 18px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }
        body:not(.dark-mode) .info-item {
            background: #f8fafc;
        }
        .info-item .info-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .info-item .info-label i { font-size: 12px; }
        .info-item .info-value {
            font-size: 15px;
            font-weight: 500;
            color: var(--text-primary);
        }
        body:not(.dark-mode) .info-item .info-value {
            color: #0f172a;
        }

        /* Form */
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }
        .form-group label i {
            margin-right: 6px;
            color: var(--text-muted);
            width: 16px;
        }
        .form-group label .required {
            color: var(--accent-red);
            margin-left: 2px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            transition: all var(--transition-fast);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 4px var(--accent-blue-bg);
        }
        .form-group input::placeholder {
            color: var(--text-muted);
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .form-actions .btn { flex: 1; justify-content: center; }

        /* Theme Customization */
        .theme-preview-container {
            padding: 16px 20px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        body:not(.dark-mode) .theme-preview-container {
            background: #f8fafc;
        }
        .theme-preview-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }
        .theme-preview-swatches {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .color-preview-box {
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            color: #fff;
            border: 1px solid var(--border-color);
            min-width: 70px;
            text-align: center;
        }
        .preview-button-spacer {
            padding: 8px 20px;
            border-radius: var(--radius-sm);
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: default;
            color: #fff;
        }

        .color-picker-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .color-picker-input {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            border: 2px solid var(--border-color);
            padding: 2px;
            cursor: pointer;
            background: transparent;
            flex-shrink: 0;
        }
        .color-picker-input::-webkit-color-swatch-wrapper {
            padding: 2px;
        }
        .color-picker-input::-webkit-color-swatch {
            border-radius: var(--radius-sm);
            border: none;
        }
        .hex-input {
            width: 100px;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 13px;
            font-family: monospace;
            transition: all var(--transition-fast);
        }
        .hex-input:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-bg);
        }

        .theme-mode-options {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .theme-mode-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: var(--radius-md);
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--bg-input);
            color: var(--text-secondary);
        }
        .theme-mode-option:hover {
            border-color: var(--border-hover);
        }
        .theme-mode-option input[type="radio"] {
            display: none;
        }
        .theme-mode-option.active {
            border-color: var(--accent-blue);
            background: var(--accent-blue-bg);
            color: var(--accent-blue);
        }
        .theme-mode-option i {
            font-size: 18px;
        }
        .icon-sun { color: #f59e0b; }
        .icon-moon { color: #94a3b8; }

        .preset-colors-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .preset-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: all var(--transition-fast);
            padding: 0;
            flex-shrink: 0;
        }
        .preset-btn:hover {
            transform: scale(1.1);
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-bg);
        }

        .help-text {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .help-text i {
            margin-right: 4px;
        }
        .mt-20 { margin-top: 20px; }
        .mt-25 { margin-top: 25px; }
        .mt-30 { margin-top: 30px; }
        .mb-28 { margin-bottom: 28px; }

        /* Warning Message */
        .warning-message {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            background: var(--accent-amber-bg);
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: var(--accent-amber);
            font-size: 13px;
        }

        /* Skeleton */
        .skeleton-card { background: var(--bg-card); border-radius: var(--radius-lg); padding: 20px 24px; border: 1px solid var(--border-color); }
        .skeleton { background: var(--bg-input); border-radius: var(--radius-sm); animation: skeletonPulse 1.5s ease-in-out infinite; }
        .skeleton-text { height: 16px; width: 60%; margin: 8px 0; }
        .skeleton-text-large { height: 24px; width: 40%; }
        .skeleton-w-50 { width: 50%; }
        .skeleton-w-70 { width: 70%; }
        .skeleton-mb-md { margin-bottom: 16px; }
        @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }

        .initially-hidden { display: none; }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-grid-3 { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .profile-grid { grid-template-columns: 1fr; justify-items: center; text-align: center; }
            .profile-upload-area .upload-actions { justify-content: center; }
            .profile-upload-area .upload-actions .btn { flex: none; }
            .info-grid { grid-template-columns: 1fr; }
            .form-grid-2 { grid-template-columns: 1fr; }
            .form-grid-3 { grid-template-columns: 1fr; }
            .card-modern .card-body { padding: 16px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { flex: none; }
            .theme-preview-swatches { justify-content: center; }
            .preset-colors-row { justify-content: center; }
        }
        @media (max-width: 480px) {
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .profile-image-container { width: 100px; height: 100px; }
            .profile-image-container .default-icon { font-size: 48px; }
            .profile-upload-area .file-drop-zone { padding: 16px 20px; }
            .color-picker-row { flex-wrap: wrap; }
            .theme-mode-options { flex-direction: column; width: 100%; }
            .theme-mode-option { justify-content: center; }
        }

        * { transition: background-color var(--transition-slow), color var(--transition-slow), border-color var(--transition-slow), box-shadow var(--transition-slow); }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-modern">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                <span class="current">My Account</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-user-circle"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>My Account</h1>
                        <p class="subtitle">Manage your profile, security, and appearance settings</p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadAccountInfo()" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card">
                    <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-70"></div>
                    <div class="skeleton skeleton-text skeleton-w-70"></div>
                    <div class="skeleton skeleton-text skeleton-w-50"></div>
                </div>
            </div>

            <!-- Account Content -->
            <div id="accountContent" class="initially-hidden">

                <!-- Profile Image Card -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-purple"><i class="fas fa-image"></i></div>
                        <div>
                            <div class="header-title">Profile Image</div>
                            <div class="header-subtitle">Upload a photo to personalize your account</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="profile-grid">
                            <!-- Image Display -->
                            <div style="text-align:center;">
                                <div class="profile-image-container" id="profileImageContainer">
                                    <img id="currentProfileImage" src="" alt="Profile" class="initially-hidden" style="display:none;">
                                    <i id="defaultProfileIcon" class="fas fa-user default-icon"></i>
                                </div>
                                <div class="profile-image-label">Current Image</div>
                            </div>

                            <!-- Upload Area -->
                            <div class="profile-upload-area">
                                <form id="profileImageForm" enctype="multipart/form-data">
                                    <div class="file-drop-zone" id="fileDropZone" onclick="document.getElementById('profileImageInput').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <div class="drop-text">Click to choose a file</div>
                                        <div class="drop-hint">Accepted: JPG, PNG, GIF, WEBP (Max 2MB)</div>
                                        <input type="file" id="profileImageInput" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                    </div>
                                    <div id="uploadSection" class="upload-actions" style="display:none;">
                                        <button type="submit" class="btn btn-primary" id="uploadBtn">
                                            <i class="fas fa-upload"></i> Upload Image
                                        </button>
                                        <button type="button" class="btn btn-ghost" onclick="cancelUpload()">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </div>
                                </form>
                                <div id="uploadDisabledMessage" class="warning-message initially-hidden">
                                    <i class="fas fa-exclamation-triangle"></i> Profile image uploads are currently disabled by administrator.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Information Card -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-blue"><i class="fas fa-info-circle"></i></div>
                        <div>
                            <div class="header-title">Account Information</div>
                            <div class="header-subtitle">Your account details and member information</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-user"></i> Username</div>
                                <div class="info-value" id="display-username">Loading...</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                                <div class="info-value" id="display-email">Loading...</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-user-tag"></i> Role</div>
                                <div class="info-value" id="display-role">Loading...</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-calendar-alt"></i> Member Since</div>
                                <div class="info-value" id="display-created">Loading...</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Profile Card -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-green"><i class="fas fa-edit"></i></div>
                        <div>
                            <div class="header-title">Update Profile</div>
                            <div class="header-subtitle">Change your username and email address</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form id="profileForm">
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label><i class="fas fa-user"></i> Username <span class="required">*</span></label>
                                    <input type="text" id="username" name="username" required placeholder="Enter username">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                                    <input type="email" id="email" name="email" required placeholder="Enter email">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Change Password Card -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-amber"><i class="fas fa-lock"></i></div>
                        <div>
                            <div class="header-title">Change Password</div>
                            <div class="header-subtitle">Update your account password</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form id="passwordForm">
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label><i class="fas fa-lock"></i> Current Password <span class="required">*</span></label>
                                    <input type="password" id="current_password" name="current_password" required placeholder="Enter current password">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-key"></i> New Password <span class="required">*</span></label>
                                    <input type="password" id="new_password" name="new_password" required minlength="6" placeholder="Enter new password">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-key"></i> Confirm New Password <span class="required">*</span></label>
                                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6" placeholder="Confirm new password">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- UI Customization Card -->
                <div class="card-modern" id="uiCustomizationSection">
                    <div class="card-header">
                        <div class="header-icon header-icon-pink"><i class="fas fa-palette"></i></div>
                        <div>
                            <div class="header-title">UI Customization</div>
                            <div class="header-subtitle">Personalize your dashboard appearance</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="help-text" style="margin-bottom:16px;">
                            <i class="fas fa-info-circle"></i> Customize your dashboard colors. Changes are saved per user and will apply across all pages.
                        </p>

                        <!-- Theme Preview -->
                        <div class="theme-preview-container">
                            <div class="theme-preview-title"><i class="fas fa-eye"></i> Live Preview</div>
                            <div class="theme-preview-swatches">
                                <div id="previewPrimary" class="color-preview-box" style="background:#001f3f;">Primary</div>
                                <div id="previewSecondary" class="color-preview-box" style="background:#003366;">Secondary</div>
                                <div id="previewAccent" class="color-preview-box" style="background:#0074D9;">Accent</div>
                                <button class="preview-button-spacer" id="previewButton" style="background:#001f3f;color:#fff;">
                                    <i class="fas fa-check"></i> Sample
                                </button>
                            </div>
                        </div>

                        <form id="themeForm">
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label><i class="fas fa-square" id="primaryColorIcon" style="color:#001f3f;"></i> Primary Color</label>
                                    <div class="color-picker-row">
                                        <input type="color" id="theme_primary" name="theme_primary" value="#001f3f" class="color-picker-input">
                                        <input type="text" id="theme_primary_hex" value="#001f3f" maxlength="7" class="hex-input" placeholder="#000000">
                                    </div>
                                    <small class="help-text">Sidebar &amp; headers</small>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-square" id="secondaryColorIcon" style="color:#003366;"></i> Secondary Color</label>
                                    <div class="color-picker-row">
                                        <input type="color" id="theme_secondary" name="theme_secondary" value="#003366" class="color-picker-input">
                                        <input type="text" id="theme_secondary_hex" value="#003366" maxlength="7" class="hex-input" placeholder="#000000">
                                    </div>
                                    <small class="help-text">Hover states &amp; gradients</small>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-square" id="accentColorIcon" style="color:#0074D9;"></i> Accent Color</label>
                                    <div class="color-picker-row">
                                        <input type="color" id="theme_accent" name="theme_accent" value="#0074D9" class="color-picker-input">
                                        <input type="text" id="theme_accent_hex" value="#0074D9" maxlength="7" class="hex-input" placeholder="#000000">
                                    </div>
                                    <small class="help-text">Buttons &amp; links</small>
                                </div>
                            </div>

                            <!-- Theme Mode -->
                            <div class="form-group mt-20">
                                <label><i class="fas fa-adjust"></i> Default Theme Mode</label>
                                <div class="theme-mode-options">
                                    <label class="theme-mode-option active" id="lightModeOption">
                                        <input type="radio" name="theme_mode" value="light" id="theme_mode_light" checked>
                                        <i class="fas fa-sun icon-sun"></i>
                                        <span>Light Mode</span>
                                    </label>
                                    <label class="theme-mode-option" id="darkModeOption">
                                        <input type="radio" name="theme_mode" value="dark" id="theme_mode_dark">
                                        <i class="fas fa-moon icon-moon"></i>
                                        <span>Dark Mode</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Preset Colors -->
                            <div class="form-group mt-25">
                                <label><i class="fas fa-swatchbook"></i> Quick Presets</label>
                                <div class="preset-colors-row">
                                    <button type="button" class="preset-btn" onclick="applyPreset('#001f3f', '#003366', '#0074D9')" style="background: linear-gradient(135deg, #001f3f 50%, #0074D9 50%);" title="Navy Blue (Default)"></button>
                                    <button type="button" class="preset-btn" onclick="applyPreset('#1a1a2e', '#16213e', '#e94560')" style="background: linear-gradient(135deg, #1a1a2e 50%, #e94560 50%);" title="Dark Rose"></button>
                                    <button type="button" class="preset-btn" onclick="applyPreset('#2d3436', '#636e72', '#00b894')" style="background: linear-gradient(135deg, #2d3436 50%, #00b894 50%);" title="Emerald Dark"></button>
                                    <button type="button" class="preset-btn" onclick="applyPreset('#4a0e4e', '#810e7a', '#c92bc8')" style="background: linear-gradient(135deg, #4a0e4e 50%, #c92bc8 50%);" title="Purple Magic"></button>
                                    <button type="button" class="preset-btn" onclick="applyPreset('#1b4332', '#2d6a4f', '#40916c')" style="background: linear-gradient(135deg, #1b4332 50%, #40916c 50%);" title="Forest Green"></button>
                                    <button type="button" class="preset-btn" onclick="applyPreset('#7f5539', '#9c6644', '#dda15e')" style="background: linear-gradient(135deg, #7f5539 50%, #dda15e 50%);" title="Warm Brown"></button>
                                    <button type="button" class="preset-btn" onclick="applyPreset('#03045e', '#0077b6', '#00b4d8')" style="background: linear-gradient(135deg, #03045e 50%, #00b4d8 50%);" title="Ocean Blue"></button>
                                    <button type="button" class="preset-btn" onclick="applyPreset('#3d0066', '#7b2cbf', '#c77dff')" style="background: linear-gradient(135deg, #3d0066 50%, #c77dff 50%);" title="Violet Dream"></button>
                                </div>
                            </div>

                            <div class="form-actions mt-30">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Theme Settings
                                </button>
                                <button type="button" class="btn btn-success" onclick="applyThemePreview()">
                                    <i class="fas fa-eye"></i> Preview Changes
                                </button>
                                <button type="button" class="btn btn-ghost" onclick="resetTheme()">
                                    <i class="fas fa-undo"></i> Reset to Default
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- CSRF Token -->
    <script>
    const CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';
    let allowUserUploads = true;

    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            xhr.setRequestHeader('X-CSRF-TOKEN', CSRF_TOKEN);
        }
    });

    $(document).ready(function() {
        loadAccountInfo();
        setupThemeModeToggle();
    });

    // ==========================================
    // LOAD ACCOUNT INFO
    // ==========================================
    function loadAccountInfo() {
        $('#loadingSkeleton').show();
        $('#accountContent').hide();

        $.ajax({
            url: '?action=getAccountInfo',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                setTimeout(() => {
                    $('#loadingSkeleton').hide();
                    $('#accountContent').fadeIn(300);
                }, 400);

                if (response.success) {
                    const data = response.data;

                    // Display account info
                    document.getElementById('display-username').textContent = data.username;
                    document.getElementById('display-email').textContent = data.email;
                    document.getElementById('display-role').innerHTML =
                        `<span class="status-badge ${data.role === 'Admin' ? 'status-admin' : 'status-user'}">${data.role}</span>`;
                    document.getElementById('display-created').textContent = data.created_at;

                    // Populate form
                    document.getElementById('username').value = data.username;
                    document.getElementById('email').value = data.email;

                    // Handle profile image
                    allowUserUploads = data.allow_user_uploads;
                    const img = document.getElementById('currentProfileImage');
                    const defaultIcon = document.getElementById('defaultProfileIcon');

                    if (data.profile_image) {
                        img.src = data.profile_image;
                        img.style.display = 'block';
                        defaultIcon.style.display = 'none';
                    } else {
                        img.style.display = 'none';
                        defaultIcon.style.display = 'block';
                    }

                    // Check if uploads are allowed
                    if (!allowUserUploads && data.role !== 'Admin') {
                        document.getElementById('profileImageInput').disabled = true;
                        document.getElementById('uploadDisabledMessage').style.display = 'block';
                        document.getElementById('fileDropZone').style.cursor = 'default';
                        document.getElementById('fileDropZone').style.opacity = '0.5';
                    }

                    // Load theme values
                    loadThemeValues(data);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to load account info',
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function() {
                $('#loadingSkeleton').hide();
                $('#accountContent').show();
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not load account information',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    // ==========================================
    // PROFILE IMAGE UPLOAD
    // ==========================================
    document.getElementById('profileImageInput').addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const file = this.files[0];

            if (file.size > 2 * 1024 * 1024) {
                Swal.fire({
                    icon: 'error',
                    title: 'File Too Large',
                    text: 'Profile image must be less than 2MB',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
                this.value = '';
                return;
            }

            document.getElementById('uploadSection').style.display = 'flex';

            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById('currentProfileImage');
                img.src = e.target.result;
                img.style.display = 'block';
                document.getElementById('defaultProfileIcon').style.display = 'none';
            };
            reader.readAsDataURL(file);
        }
    });

    function cancelUpload() {
        document.getElementById('profileImageInput').value = '';
        document.getElementById('uploadSection').style.display = 'none';
        loadAccountInfo();
    }

    document.getElementById('profileImageForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData();
        const fileInput = document.getElementById('profileImageInput');

        if (!fileInput.files || !fileInput.files[0]) {
            Swal.fire({
                icon: 'error',
                title: 'No File Selected',
                text: 'Please select an image to upload',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            });
            return;
        }

        formData.append('profile_image', fileInput.files[0]);
        formData.append('action', 'uploadProfileImage');

        Swal.fire({
            title: 'Uploading...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });

                    document.getElementById('profileImageInput').value = '';
                    document.getElementById('uploadSection').style.display = 'none';
                    setTimeout(() => loadAccountInfo(), 500);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Upload Failed',
                        text: response.message,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: 'Connection error',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    });

    // ==========================================
    // UPDATE PROFILE
    // ==========================================
    document.getElementById('profileForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        Swal.fire({
            title: 'Updating Profile...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '?action=updateProfile',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });

                    if (response.new_username) {
                        document.getElementById('display-username').textContent = response.new_username;
                    }
                    setTimeout(() => loadAccountInfo(), 100);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Connection error',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    });

    // ==========================================
    // CHANGE PASSWORD
    // ==========================================
    document.getElementById('passwordForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;

        if (newPassword !== confirmPassword) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'New passwords do not match',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            });
            return;
        }

        const formData = new FormData(this);

        Swal.fire({
            title: 'Changing Password...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '?action=changePassword',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                    document.getElementById('passwordForm').reset();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Connection error',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    });

    // ==========================================
    // THEME CUSTOMIZATION
    // ==========================================
    function setupThemeModeToggle() {
        document.querySelectorAll('.theme-mode-option input[type="radio"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.theme-mode-option').forEach(function(opt) {
                    opt.classList.toggle('active', opt.querySelector('input').checked);
                });
            });
        });
    }

    // Sync color picker with hex input
    document.getElementById('theme_primary').addEventListener('input', function() {
        document.getElementById('theme_primary_hex').value = this.value.toUpperCase();
        updatePreview();
        updateColorIcons();
    });
    document.getElementById('theme_secondary').addEventListener('input', function() {
        document.getElementById('theme_secondary_hex').value = this.value.toUpperCase();
        updatePreview();
        updateColorIcons();
    });
    document.getElementById('theme_accent').addEventListener('input', function() {
        document.getElementById('theme_accent_hex').value = this.value.toUpperCase();
        updatePreview();
        updateColorIcons();
    });

    // Sync hex input with color picker
    document.getElementById('theme_primary_hex').addEventListener('input', function() {
        if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
            document.getElementById('theme_primary').value = this.value;
            updatePreview();
            updateColorIcons();
        }
    });
    document.getElementById('theme_secondary_hex').addEventListener('input', function() {
        if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
            document.getElementById('theme_secondary').value = this.value;
            updatePreview();
            updateColorIcons();
        }
    });
    document.getElementById('theme_accent_hex').addEventListener('input', function() {
        if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
            document.getElementById('theme_accent').value = this.value;
            updatePreview();
            updateColorIcons();
        }
    });

    function updatePreview() {
        const primary = document.getElementById('theme_primary').value;
        const secondary = document.getElementById('theme_secondary').value;
        const accent = document.getElementById('theme_accent').value;

        document.getElementById('previewPrimary').style.background = primary;
        document.getElementById('previewSecondary').style.background = secondary;
        document.getElementById('previewAccent').style.background = accent;
        document.getElementById('previewButton').style.background = primary;
        document.getElementById('previewButton').style.color = '#fff';
    }

    function updateColorIcons() {
        document.getElementById('primaryColorIcon').style.color = document.getElementById('theme_primary').value;
        document.getElementById('secondaryColorIcon').style.color = document.getElementById('theme_secondary').value;
        document.getElementById('accentColorIcon').style.color = document.getElementById('theme_accent').value;
    }

    function applyPreset(primary, secondary, accent) {
        document.getElementById('theme_primary').value = primary;
        document.getElementById('theme_primary_hex').value = primary.toUpperCase();
        document.getElementById('theme_secondary').value = secondary;
        document.getElementById('theme_secondary_hex').value = secondary.toUpperCase();
        document.getElementById('theme_accent').value = accent;
        document.getElementById('theme_accent_hex').value = accent.toUpperCase();
        updatePreview();
        updateColorIcons();

        Swal.fire({
            icon: 'info',
            title: 'Preset Applied',
            text: 'Click "Save Theme Settings" to apply permanently',
            timer: 2000,
            showConfirmButton: false,
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });
    }

    function applyThemePreview() {
        const primary = document.getElementById('theme_primary').value;
        const secondary = document.getElementById('theme_secondary').value;
        const accent = document.getElementById('theme_accent').value;

        document.documentElement.style.setProperty('--navy-primary', primary);
        document.documentElement.style.setProperty('--navy-light', secondary);
        document.documentElement.style.setProperty('--navy-dark', primary);
        document.documentElement.style.setProperty('--navy-hover', secondary);
        document.documentElement.style.setProperty('--navy-accent', accent);

        Swal.fire({
            icon: 'success',
            title: 'Preview Applied!',
            text: 'This is a temporary preview. Save to make it permanent.',
            timer: 3000,
            showConfirmButton: false,
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });
    }

    // Save theme settings
    document.getElementById('themeForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData();
        formData.append('theme_primary', document.getElementById('theme_primary').value);
        formData.append('theme_secondary', document.getElementById('theme_secondary').value);
        formData.append('theme_accent', document.getElementById('theme_accent').value);
        formData.append('theme_mode', document.querySelector('input[name="theme_mode"]:checked').value);

        Swal.fire({
            title: 'Saving Theme...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '?action=saveTheme',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    applyThemePreview();

                    const themeMode = document.querySelector('input[name="theme_mode"]:checked').value;
                    localStorage.setItem('theme', themeMode);
                    if (themeMode === 'dark') {
                        document.body.classList.add('dark-mode');
                        document.body.classList.remove('light-mode');
                    } else {
                        document.body.classList.remove('dark-mode');
                        document.body.classList.add('light-mode');
                    }

                    Swal.fire({
                        icon: 'success',
                        title: 'Theme Saved!',
                        text: 'Your custom theme has been saved.',
                        timer: 2000,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Connection error',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    });

    // Reset theme to default
    function resetTheme() {
        Swal.fire({
            icon: 'warning',
            title: 'Reset Theme?',
            text: 'This will reset your colors to the default Navy Blue theme.',
            showCancelButton: true,
            confirmButtonColor: '#001f3f',
            confirmButtonText: 'Yes, Reset',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '?action=resetTheme',
                    method: 'POST',
                    data: { csrf_token: CSRF_TOKEN },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Reset form values
                            document.getElementById('theme_primary').value = '#001f3f';
                            document.getElementById('theme_primary_hex').value = '#001F3F';
                            document.getElementById('theme_secondary').value = '#003366';
                            document.getElementById('theme_secondary_hex').value = '#003366';
                            document.getElementById('theme_accent').value = '#0074D9';
                            document.getElementById('theme_accent_hex').value = '#0074D9';
                            document.getElementById('theme_mode_light').checked = true;

                            document.querySelectorAll('.theme-mode-option').forEach(function(opt) {
                                opt.classList.toggle('active', opt.querySelector('input').checked);
                            });

                            // Apply default theme
                            document.documentElement.style.setProperty('--navy-primary', '#001f3f');
                            document.documentElement.style.setProperty('--navy-light', '#003366');
                            document.documentElement.style.setProperty('--navy-dark', '#001f3f');
                            document.documentElement.style.setProperty('--navy-hover', '#003366');
                            document.documentElement.style.setProperty('--navy-accent', '#0074D9');

                            localStorage.setItem('theme', 'light');
                            document.body.classList.remove('dark-mode');
                            document.body.classList.add('light-mode');

                            updatePreview();
                            updateColorIcons();

                            Swal.fire({
                                icon: 'success',
                                title: 'Theme Reset!',
                                text: 'Colors have been reset to default.',
                                timer: 2000,
                                showConfirmButton: false,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Connection error',
                            background: 'var(--bg-card)',
                            color: 'var(--text-primary)'
                        });
                    }
                });
            }
        });
    }

    // Load theme values into form
    function loadThemeValues(data) {
        if (data.theme_primary) {
            document.getElementById('theme_primary').value = data.theme_primary;
            document.getElementById('theme_primary_hex').value = data.theme_primary.toUpperCase();
        }
        if (data.theme_secondary) {
            document.getElementById('theme_secondary').value = data.theme_secondary;
            document.getElementById('theme_secondary_hex').value = data.theme_secondary.toUpperCase();
        }
        if (data.theme_accent) {
            document.getElementById('theme_accent').value = data.theme_accent;
            document.getElementById('theme_accent_hex').value = data.theme_accent.toUpperCase();
        }
        if (data.theme_mode === 'dark') {
            document.getElementById('theme_mode_dark').checked = true;
        } else {
            document.getElementById('theme_mode_light').checked = true;
        }

        document.querySelectorAll('.theme-mode-option').forEach(function(opt) {
            opt.classList.toggle('active', opt.querySelector('input').checked);
        });

        updatePreview();
        updateColorIcons();
    }

    // Scroll to UI Customization section when ?tab=settings
    if (new URLSearchParams(window.location.search).get('tab') === 'settings') {
        const section = document.getElementById('uiCustomizationSection');
        if (section) {
            setTimeout(function() {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 500);
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const activeForm = document.activeElement;
            if (activeForm && activeForm.closest('#themeForm')) {
                document.getElementById('themeForm').dispatchEvent(new Event('submit'));
            } else if (activeForm && activeForm.closest('#profileForm')) {
                document.getElementById('profileForm').dispatchEvent(new Event('submit'));
            }
        }
    });

    console.log('⚡ Account page loaded successfully');
    console.log('⌨️ Shortcut: Ctrl+S to save active form');
    </script>
</body>
</html>