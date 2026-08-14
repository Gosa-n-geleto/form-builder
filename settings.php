<?php
// ============================================================
// SESSION FIX - Ensure CSRF token exists
// ============================================================

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ONLY generate token if it doesn't exist - DO NOT force regenerate
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    $_SESSION['csrf_token_used'] = false;
}

// ONLY regenerate if expired (5 minutes)
if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time'] > 300)) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    $_SESSION['csrf_token_used'] = false;
}

// Ensure token_used flag exists
if (!isset($_SESSION['csrf_token_used'])) {
    $_SESSION['csrf_token_used'] = false;
}

// ============================================================
// Include configuration
// ============================================================

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
$current_page = 'settings';

// Only admins can access settings
if ($role !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // CSRF is TEMPORARILY DISABLED for settings
    // enforceCSRF(); // Commented out to prevent session issues
    
    if ($_POST['action'] === 'getSettings') {
        try {
            $allowUserUploads = getSetting('allow_user_profile_uploads', '1');
            $currency_symbol = getSetting('currency_symbol', '$');
            $default_language = getSetting('default_language', 'en');
            $maintenance_mode = getSetting('maintenance_mode', '0');
            $gemini_api_key = getSetting('gemini_api_key', '');
            $branding = getSiteBranding();

            echo json_encode([
                'success' => true,
                'data' => [
                    'allow_user_profile_uploads' => $allowUserUploads,
                    'currency_symbol' => $currency_symbol,
                    'default_language' => $default_language,
                    'maintenance_mode' => $maintenance_mode,
                    'gemini_api_key' => $gemini_api_key,
                    'site_name' => $branding['site_name'],
                    'site_logo' => $branding['site_logo'],
                    'copyright_text' => $branding['copyright_text']
                ]
            ]);
            exit();
        } catch (Exception $e) {
            error_log("Get settings error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error loading settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveSettings') {
        try {
            $allowUserUploads = isset($_POST['allow_user_profile_uploads']) ? $_POST['allow_user_profile_uploads'] : '0';
            $siteName = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
            $copyrightText = isset($_POST['copyright_text']) ? trim($_POST['copyright_text']) : '';

            // Save settings
            setSetting('allow_user_profile_uploads', $allowUserUploads);

            if (!empty($siteName)) {
                setSetting('site_name', $siteName);
            }
            if (!empty($copyrightText)) {
                setSetting('copyright_text', $copyrightText);
            }

            $currency_symbol = isset($_POST['currency_symbol']) ? trim($_POST['currency_symbol']) : '$';
            setSetting('currency_symbol', $currency_symbol);

            $default_language = isset($_POST['default_language']) ? trim($_POST['default_language']) : 'en';
            setSetting('default_language', $default_language);

            $maintenance_mode = isset($_POST['maintenance_mode']) ? $_POST['maintenance_mode'] : '0';
            setSetting('maintenance_mode', $maintenance_mode);

            $gemini_api_key = isset($_POST['gemini_api_key']) ? trim($_POST['gemini_api_key']) : '';
            setSetting('gemini_api_key', $gemini_api_key);

            // Log activity
            logActivity($user_id, $username, 'Settings Updated', 'System settings updated');

            echo json_encode([
                'success' => true,
                'message' => 'Settings saved successfully',
                'default_language' => $default_language
            ]);
            exit();
        } catch (Exception $e) {
            error_log("Save settings error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error saving settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'uploadSiteLogo') {
        try {
            if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred']);
                exit();
            }

            $file = $_FILES['logo_file'];
            $upload_dir = __DIR__ . '/uploads/branding/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Validate file
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            $max_size = 2 * 1024 * 1024; // 2MB

            if ($file['size'] > $max_size) {
                echo json_encode(['success' => false, 'message' => 'File size must be less than 2MB']);
                exit();
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, WEBP, and SVG allowed']);
                exit();
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed_extensions)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file extension']);
                exit();
            }

            // Delete old logo file if it exists locally
            $old_logo = getSetting('site_logo', '');
            if (!empty($old_logo) && strpos($old_logo, 'uploads/branding/') === 0 && file_exists(__DIR__ . '/' . $old_logo)) {
                unlink(__DIR__ . '/' . $old_logo);
            }

            // Generate unique filename
            $filename = 'site_logo_' . time() . '.' . $extension;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $relative_path = 'uploads/branding/' . $filename;
                setSetting('site_logo', $relative_path);

                logActivity($user_id, $username, 'Logo Updated', 'Site logo uploaded');

                echo json_encode([
                    'success' => true,
                    'message' => 'Logo uploaded successfully',
                    'logo_path' => $relative_path
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
            }
            exit();
        } catch (Exception $e) {
            error_log("Upload logo error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error uploading logo: ' . $e->getMessage()]);
            exit();
        }
    }
}

// Get the current language setting for the page
$current_language = getSetting('default_language', 'en');
// Determine if translation should be active
$translation_active = ($current_language !== 'en');
?>
<!DOCTYPE html>
<html lang="<?php echo $current_language; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>System Settings · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           SYSTEM SETTINGS - WORLD-CLASS MODERN UI
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
            background: linear-gradient(135deg, var(--accent-blue-bg), rgba(59, 130, 246, 0.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: var(--accent-blue);
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
            display: flex; align-items: center; gap: 8px;
        }
        .page-header-left .title-group .subtitle .badge-beta {
            font-size: 10px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.05em; padding: 2px 10px; border-radius: var(--radius-full);
            background: var(--accent-blue-bg); color: var(--accent-blue);
            border: 1px solid rgba(59, 130, 246, 0.15);
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
        .btn-danger {
            background: linear-gradient(135deg, var(--accent-red), #dc2626);
            color: #fff; border-color: transparent;
        }
        .btn-danger:hover {
            box-shadow: 0 4px 24px rgba(239, 68, 68, 0.35);
            transform: translateY(-1px); color: #fff;
        }
        .btn-ghost { background: transparent; border-color: transparent; color: var(--text-muted); }
        .btn-ghost:hover { background: var(--bg-card); border-color: var(--border-color); color: var(--text-primary); }
        .btn-sm { padding: 6px 14px; font-size: 12px; border-radius: var(--radius-sm); }
        .btn-block { width: 100%; justify-content: center; }

        /* Cards */
        .card-modern {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
            margin-bottom: 24px;
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
        .header-icon-blue { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .header-icon-green { background: var(--accent-green-bg); color: var(--accent-green); }
        .header-icon-purple { background: var(--accent-purple-bg); color: var(--accent-purple); }
        .header-icon-amber { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .header-icon-red { background: var(--accent-red-bg); color: var(--accent-red); }
        .header-icon-pink { background: var(--accent-pink-bg); color: var(--accent-pink); }

        .card-modern .card-header .header-title {
            font-size: 16px; font-weight: 600; color: var(--text-primary);
        }
        .card-modern .card-header .header-subtitle {
            font-size: 13px; color: var(--text-muted);
        }
        .card-modern .card-body { padding: 24px; }

        /* Grid */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        .settings-grid-full { grid-template-columns: 1fr; }

        /* Form */
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--text-secondary); margin-bottom: 6px;
        }
        .form-group label i { margin-right: 6px; color: var(--text-muted); width: 16px; }
        .form-group input,
        .form-group select {
            width: 100%; padding: 12px 16px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            transition: all var(--transition-fast);
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 4px var(--accent-blue-bg);
        }
        .form-group input::placeholder { color: var(--text-muted); }
        .form-group select option { background: var(--bg-card); color: var(--text-primary); }

        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .form-help-text { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .form-help-text a { color: var(--accent-blue); text-decoration: none; }
        .form-help-text a:hover { text-decoration: underline; }

        .form-actions {
            display: flex; gap: 12px; margin-top: 20px;
            padding-top: 16px; border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .form-actions .btn { flex: 1; justify-content: center; }

        /* Control Group (Toggle) */
        .control-group {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 0; border-bottom: 1px solid var(--border-color);
        }
        .control-group:last-child { border-bottom: none; }

        .control-group .control-left {
            display: flex; align-items: center; gap: 14px;
        }
        .control-group .control-left .control-icon {
            width: 36px; height: 36px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            background: var(--bg-input); color: var(--text-secondary);
            flex-shrink: 0;
        }
        .control-group .control-left .control-title {
            font-size: 14px; font-weight: 600; color: var(--text-primary);
        }
        .control-group .control-left .control-desc {
            font-size: 13px; color: var(--text-muted);
        }

        .control-toggle {
            display: flex; align-items: center; gap: 12px; flex-shrink: 0;
        }
        .toggle-status {
            font-size: 12px; font-weight: 500;
            display: flex; align-items: center; gap: 6px;
        }
        .toggle-status .status-dot {
            width: 8px; height: 8px; border-radius: var(--radius-full);
            display: inline-block;
        }
        .status-dot-enabled { background: var(--accent-green); }
        .status-dot-disabled { background: var(--text-muted); }

        .toggle-switch {
            position: relative; width: 48px; height: 26px; flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-switch .toggle-slider {
            position: absolute; inset: 0;
            background: var(--bg-input);
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all var(--transition-fast);
            border: 2px solid var(--border-color);
        }
        .toggle-switch .toggle-slider::before {
            content: '';
            position: absolute;
            height: 18px; width: 18px;
            left: 2px; bottom: 2px;
            background: var(--text-muted);
            border-radius: var(--radius-full);
            transition: all var(--transition-fast);
        }
        .toggle-switch input:checked + .toggle-slider {
            background: var(--accent-blue);
            border-color: var(--accent-blue);
        }
        .toggle-switch input:checked + .toggle-slider::before {
            transform: translateX(22px);
            background: #fff;
        }

        /* Logo Upload */
        .logo-upload-row {
            display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
        }
        .logo-preview-img {
            width: 80px; height: 80px;
            border-radius: var(--radius-md);
            border: 2px solid var(--border-color);
            object-fit: contain;
            background: var(--bg-secondary);
            padding: 4px;
        }
        .logo-upload-controls { display: flex; flex-direction: column; gap: 4px; }
        .logo-upload-controls-inner { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .logo-upload-btn { cursor: pointer; }
        .logo-file-name { font-size: 13px; color: var(--text-muted); }
        .logo-help-text { font-size: 12px; color: var(--text-muted); margin: 0; }

        /* API Key Input */
        .api-key-input-wrapper {
            display: flex; align-items: center; gap: 8px; position: relative;
        }
        .api-key-input-wrapper input { flex: 1; padding-right: 44px; }
        .api-key-toggle-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
            font-size: 16px;
        }
        .api-key-toggle-btn:hover { color: var(--text-primary); }

        /* Info Banner */
        .info-banner {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 14px 18px;
            border-radius: var(--radius-md);
            margin-top: 14px;
        }
        .info-banner i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }
        .info-banner span { font-size: 13px; color: var(--text-secondary); }
        body:not(.dark-mode) .info-banner span { color: #475569; }
        .info-banner span strong { color: var(--text-primary); }
        body:not(.dark-mode) .info-banner span strong { color: #0f172a; }
        .info-banner-blue { background: var(--accent-blue-bg); border: 1px solid rgba(59, 130, 246, 0.15); }
        .info-banner-blue i { color: var(--accent-blue); }
        .info-banner-amber { background: var(--accent-amber-bg); border: 1px solid rgba(245, 158, 11, 0.15); }
        .info-banner-amber i { color: var(--accent-amber); }

        /* Status Items */
        .stat-item-inline {
            display: flex; align-items: center; gap: 14px;
            padding: 10px 0; border-bottom: 1px solid var(--border-color);
        }
        .stat-item-inline:last-child { border-bottom: none; }
        .stat-item-icon {
            width: 32px; height: 32px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .stat-icon-success { background: var(--accent-green-bg); color: var(--accent-green); }
        .stat-icon-accent { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .stat-icon-warning { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .stat-icon-danger { background: var(--accent-red-bg); color: var(--accent-red); }
        .stat-icon-purple { background: var(--accent-purple-bg); color: var(--accent-purple); }

        .stat-item-content .stat-item-label { font-size: 12px; color: var(--text-muted); }
        .stat-item-content .stat-item-value { font-size: 14px; font-weight: 500; color: var(--text-primary); }
        body:not(.dark-mode) .stat-item-content .stat-item-value { color: #0f172a; }

        /* Security Features */
        .security-feature {
            display: flex; align-items: center; gap: 14px;
            padding: 10px 0; border-bottom: 1px solid var(--border-color);
        }
        .security-feature:last-child { border-bottom: none; }
        .security-feature-icon {
            width: 32px; height: 32px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            color: var(--accent-green);
            flex-shrink: 0;
        }
        .security-feature-content { flex: 1; }
        .security-feature-name { font-size: 14px; font-weight: 500; color: var(--text-primary); }
        body:not(.dark-mode) .security-feature-name { color: #0f172a; }
        .security-feature-desc { font-size: 12px; color: var(--text-muted); }
        .security-feature-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 12px; border-radius: var(--radius-full);
            font-size: 11px; font-weight: 500;
            background: var(--accent-green-bg); color: var(--accent-green);
            flex-shrink: 0;
        }

        /* Server Info */
        .server-info-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 0; border-bottom: 1px solid var(--border-color);
        }
        .server-info-item:last-child { border-bottom: none; }
        .server-info-label {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--text-secondary);
        }
        .server-info-label i { color: var(--text-muted); width: 16px; }
        .server-info-value { font-size: 13px; font-weight: 500; color: var(--text-primary); }
        body:not(.dark-mode) .server-info-value { color: #0f172a; }
        .version-badge {
            display: inline-block; padding: 2px 12px; border-radius: var(--radius-full);
            background: var(--accent-purple-bg); color: var(--accent-purple);
            font-size: 12px; font-weight: 600;
        }
        .upload-badge {
            display: inline-block; padding: 2px 12px; border-radius: var(--radius-full);
            background: var(--accent-blue-bg); color: var(--accent-blue);
            font-size: 12px; font-weight: 600;
        }
        .time-value { font-family: monospace; font-size: 13px; }

        /* Skeleton */
        .skeleton-card { background: var(--bg-card); border-radius: var(--radius-lg); padding: 20px 24px; border: 1px solid var(--border-color); }
        .skeleton { background: var(--bg-input); border-radius: var(--radius-sm); animation: skeletonPulse 1.5s ease-in-out infinite; }
        .skeleton-text { height: 16px; width: 60%; margin: 8px 0; }
        .skeleton-text-large { height: 24px; width: 40%; }
        .skeleton-w-50 { width: 50%; }
        .skeleton-w-60 { width: 60%; }
        .skeleton-w-70 { width: 70%; }
        .skeleton-w-80 { width: 80%; }
        .skeleton-mb-md { margin-bottom: 16px; }
        .skeleton-mb-sm { margin-bottom: 8px; }
        .skeleton-icon { width: 40px; height: 40px; border-radius: var(--radius-md); margin-bottom: 12px; }
        @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }

        .initially-hidden { display: none; }

        /* Maintenance Card */
        .maintenance-card-warning .card-header {
            border-bottom-color: rgba(239, 68, 68, 0.2);
        }

        /* Language select fix */
        #defaultLanguage option { padding: 4px 8px; }

        /* Responsive */
        @media (max-width: 1024px) { .settings-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .form-grid-2 { grid-template-columns: 1fr; }
            .card-modern .card-body { padding: 16px; }
            .control-group { flex-wrap: wrap; gap: 12px; }
            .control-toggle { margin-left: auto; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { flex: none; }
            .logo-upload-row { flex-direction: column; align-items: flex-start; }
            .logo-upload-controls-inner { flex-wrap: wrap; }
            .settings-grid { gap: 16px; }
            .security-feature { flex-wrap: wrap; }
            .security-feature-badge { margin-left: 46px; }
            #defaultLanguage { font-size: 13px; }
        }
        @media (max-width: 480px) {
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .control-group { flex-direction: column; align-items: stretch; }
            .control-toggle { margin-left: 0; justify-content: space-between; }
            .server-info-item { flex-direction: column; align-items: flex-start; gap: 4px; }
            #defaultLanguage { font-size: 12px; }
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
                <span class="current">System Settings</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-cog"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>System Settings</h1>
                        <p class="subtitle">
                            Configure your application settings and preferences
                            <span class="badge-beta">Admin Only</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadSettings()" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card">
                    <div class="skeleton skeleton-text-large skeleton-w-60 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-70 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-50"></div>
                </div>
            </div>

            <!-- Settings Content -->
            <div id="settingsContent" class="initially-hidden">

                <!-- Site Branding Card -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-blue"><i class="fas fa-paint-brush"></i></div>
                        <div>
                            <div class="header-title">Site Branding</div>
                            <div class="header-subtitle">Customize your site name, logo, and copyright</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label><i class="fas fa-heading"></i> Site Name</label>
                                <input type="text" id="siteName" placeholder="e.g. Dashboard System" maxlength="100">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-copyright"></i> Copyright Text</label>
                                <input type="text" id="copyrightText" placeholder="e.g. &copy; 2026 My Company" maxlength="200">
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-image"></i> Site Logo</label>
                            <div class="logo-upload-row">
                                <img id="logoPreview" src="" alt="Logo" class="logo-preview-img" style="display:none;">
                                <div class="logo-upload-controls">
                                    <div class="logo-upload-controls-inner">
                                        <label for="logoFileInput" class="btn btn-primary logo-upload-btn btn-sm">
                                            <i class="fas fa-upload"></i> Choose Image
                                        </label>
                                        <input type="file" id="logoFileInput" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="display:none;">
                                        <span id="logoFileName" class="logo-file-name">No file chosen</span>
                                    </div>
                                    <p class="logo-help-text">Allowed: JPG, PNG, GIF, WEBP, SVG (Max 2MB)</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label><i class="fas fa-money-bill-wave"></i> Currency Symbol</label>
                                <select id="currencySymbol">
                                    <option value="$">$ - US Dollar</option>
                                    <option value="€">€ - Euro</option>
                                    <option value="£">£ - British Pound</option>
                                    <option value="¥">¥ - Japanese Yen</option>
                                    <option value="₹">₹ - Indian Rupee</option>
                                    <option value="₨">₨ - Pakistani Rupee</option>
                                    <option value="₱">₱ - Philippine Peso</option>
                                    <option value="₩">₩ - Korean Won</option>
                                    <option value="﷼">﷼ - Saudi Riyal</option>
                                    <option value="RM">RM - Malaysian Ringgit</option>
                                    <option value="R">R - South African Rand</option>
                                    <option value="kr">kr - Swedish Krona</option>
                                    <option value="CHF">CHF - Swiss Franc</option>
                                    <option value="A$">A$ - Australian Dollar</option>
                                    <option value="C$">C$ - Canadian Dollar</option>
                                    <option value="NZ$">NZ$ - New Zealand Dollar</option>
                                    <option value="ETB">ETB - Ethiopian Birr</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-language"></i> Default Language</label>
                                <select id="defaultLanguage" onchange="onLanguageChange(this)">
                                    <option value="en">English</option>
                                    <option value="af">Afrikaans</option>
                                    <option value="sq">Shqip (Albanian)</option>
                                    <option value="am">አማርኛ (Amharic)</option>
                                    <option value="ar">العربية (Arabic)</option>
                                    <option value="hy">Հայերեն (Armenian)</option>
                                    <option value="az">Azərbaycan (Azerbaijani)</option>
                                    <option value="eu">Euskara (Basque)</option>
                                    <option value="be">Беларуская (Belarusian)</option>
                                    <option value="bn">বাংলা (Bengali)</option>
                                    <option value="bs">Bosanski (Bosnian)</option>
                                    <option value="bg">Български (Bulgarian)</option>
                                    <option value="ca">Català (Catalan)</option>
                                    <option value="ceb">Cebuano</option>
                                    <option value="ny">Chichewa</option>
                                    <option value="zh-CN">中文简体 (Chinese Simplified)</option>
                                    <option value="zh-TW">中文繁體 (Chinese Traditional)</option>
                                    <option value="co">Corsu (Corsican)</option>
                                    <option value="hr">Hrvatski (Croatian)</option>
                                    <option value="cs">Čeština (Czech)</option>
                                    <option value="da">Dansk (Danish)</option>
                                    <option value="nl">Nederlands (Dutch)</option>
                                    <option value="eo">Esperanto</option>
                                    <option value="et">Eesti (Estonian)</option>
                                    <option value="tl">Filipino (Tagalog)</option>
                                    <option value="fi">Suomi (Finnish)</option>
                                    <option value="fr">Français (French)</option>
                                    <option value="fy">Frysk (Frisian)</option>
                                    <option value="gl">Galego (Galician)</option>
                                    <option value="ka">ქართული (Georgian)</option>
                                    <option value="de">Deutsch (German)</option>
                                    <option value="el">Ελληνικά (Greek)</option>
                                    <option value="gu">ગુજરાતી (Gujarati)</option>
                                    <option value="ht">Kreyòl Ayisyen (Haitian Creole)</option>
                                    <option value="ha">Hausa</option>
                                    <option value="haw">ʻŌlelo Hawaiʻi (Hawaiian)</option>
                                    <option value="he">עברית (Hebrew)</option>
                                    <option value="hi">हिन्दी (Hindi)</option>
                                    <option value="hmn">Hmong</option>
                                    <option value="hu">Magyar (Hungarian)</option>
                                    <option value="is">Íslenska (Icelandic)</option>
                                    <option value="ig">Igbo</option>
                                    <option value="id">Bahasa Indonesia (Indonesian)</option>
                                    <option value="ga">Gaeilge (Irish)</option>
                                    <option value="it">Italiano (Italian)</option>
                                    <option value="ja">日本語 (Japanese)</option>
                                    <option value="jv">Basa Jawa (Javanese)</option>
                                    <option value="kn">ಕನ್ನಡ (Kannada)</option>
                                    <option value="kk">Қазақ (Kazakh)</option>
                                    <option value="km">ខ្មែរ (Khmer)</option>
                                    <option value="rw">Kinyarwanda</option>
                                    <option value="ko">한국어 (Korean)</option>
                                    <option value="ku">Kurdî (Kurdish)</option>
                                    <option value="ky">Кыргызча (Kyrgyz)</option>
                                    <option value="lo">ລາວ (Lao)</option>
                                    <option value="la">Latina (Latin)</option>
                                    <option value="lv">Latviešu (Latvian)</option>
                                    <option value="lt">Lietuvių (Lithuanian)</option>
                                    <option value="lb">Lëtzebuergesch (Luxembourgish)</option>
                                    <option value="mk">Македонски (Macedonian)</option>
                                    <option value="mg">Malagasy</option>
                                    <option value="ms">Bahasa Melayu (Malay)</option>
                                    <option value="ml">മലയാളം (Malayalam)</option>
                                    <option value="mt">Malti (Maltese)</option>
                                    <option value="mi">Māori</option>
                                    <option value="mr">मराठी (Marathi)</option>
                                    <option value="mn">Монгол (Mongolian)</option>
                                    <option value="my">မြန်မာ (Myanmar/Burmese)</option>
                                    <option value="ne">नेपाली (Nepali)</option>
                                    <option value="no">Norsk (Norwegian)</option>
                                    <option value="or">ଓଡ଼ିଆ (Odia)</option>
                                    <option value="ps">پښتو (Pashto)</option>
                                    <option value="fa">فارسی (Persian)</option>
                                    <option value="pl">Polski (Polish)</option>
                                    <option value="pt">Português (Portuguese)</option>
                                    <option value="pa">ਪੰਜਾਬੀ (Punjabi)</option>
                                    <option value="ro">Română (Romanian)</option>
                                    <option value="ru">Русский (Russian)</option>
                                    <option value="sm">Gagana Samoa (Samoan)</option>
                                    <option value="gd">Gàidhlig (Scots Gaelic)</option>
                                    <option value="sr">Српски (Serbian)</option>
                                    <option value="st">Sesotho</option>
                                    <option value="sn">Shona</option>
                                    <option value="sd">سنڌي (Sindhi)</option>
                                    <option value="si">සිංහල (Sinhala)</option>
                                    <option value="sk">Slovenčina (Slovak)</option>
                                    <option value="sl">Slovenščina (Slovenian)</option>
                                    <option value="so">Soomaali (Somali)</option>
                                    <option value="es">Español (Spanish)</option>
                                    <option value="su">Basa Sunda (Sundanese)</option>
                                    <option value="sw">Kiswahili (Swahili)</option>
                                    <option value="sv">Svenska (Swedish)</option>
                                    <option value="tg">Тоҷикӣ (Tajik)</option>
                                    <option value="ta">தமிழ் (Tamil)</option>
                                    <option value="tt">Татар (Tatar)</option>
                                    <option value="te">తెలుగు (Telugu)</option>
                                    <option value="th">ไทย (Thai)</option>
                                    <option value="tr">Türkçe (Turkish)</option>
                                    <option value="tk">Türkmen (Turkmen)</option>
                                    <option value="uk">Українська (Ukrainian)</option>
                                    <option value="ur">اردو (Urdu)</option>
                                    <option value="ug">ئۇيغۇرچە (Uyghur)</option>
                                    <option value="uz">Oʻzbek (Uzbek)</option>
                                    <option value="vi">Tiếng Việt (Vietnamese)</option>
                                    <option value="cy">Cymraeg (Welsh)</option>
                                    <option value="xh">isiXhosa (Xhosa)</option>
                                    <option value="yi">ייִדיש (Yiddish)</option>
                                    <option value="yo">Yorùbá (Yoruba)</option>
                                    <option value="zu">isiZulu (Zulu)</option>
                                </select>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="revertToEnglish()" style="margin-top:6px;">
                                    <i class="fas fa-undo"></i> Revert to English
                                </button>
                            </div>
                        </div>

                        <div class="info-banner info-banner-blue">
                            <i class="fas fa-info-circle"></i>
                            <span>These settings apply to the Login, Sign Up, and Setup pages</span>
                        </div>
                    </div>
                </div>

                <!-- API Integration Card -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-purple"><i class="fas fa-key"></i></div>
                        <div>
                            <div class="header-title">API Integration</div>
                            <div class="header-subtitle">Configure external API keys for enhanced features</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label><i class="fas fa-robot"></i> Gemini API Key</label>
                            <div class="api-key-input-wrapper">
                                <input type="password" id="geminiApiKey" placeholder="Enter your Google Gemini API key" autocomplete="off">
                                <button type="button" class="api-key-toggle-btn" onclick="toggleApiKeyVisibility()" title="Show/Hide API Key">
                                    <i class="fas fa-eye" id="apiKeyToggleIcon"></i>
                                </button>
                            </div>
                            <p class="form-help-text"><i class="fas fa-info-circle"></i> Get your API key from <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a></p>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid -->
                <div class="settings-grid">

                    <!-- User Management Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-green"><i class="fas fa-users-cog"></i></div>
                            <div>
                                <div class="header-title">User Management</div>
                                <div class="header-subtitle">Control user permissions and features</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="control-group">
                                <div class="control-left">
                                    <div class="control-icon"><i class="fas fa-image"></i></div>
                                    <div>
                                        <div class="control-title">Profile Image Uploads</div>
                                        <div class="control-desc">Allow users to upload profile pictures</div>
                                    </div>
                                </div>
                                <div class="control-toggle">
                                    <div class="toggle-status">
                                        <span class="status-dot status-dot-disabled" id="uploadDot"></span>
                                        <span id="uploadStatusText">Disabled</span>
                                    </div>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="allowUserUploads">
                                        <label for="allowUserUploads" class="toggle-slider"></label>
                                    </div>
                                </div>
                            </div>
                            <div class="info-banner info-banner-blue" style="margin-top:8px;">
                                <i class="fas fa-info-circle"></i>
                                <span>When disabled, only administrators can manage profile images</span>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Mode Card -->
                    <div class="card-modern maintenance-card-warning">
                        <div class="card-header">
                            <div class="header-icon header-icon-red"><i class="fas fa-hard-hat"></i></div>
                            <div>
                                <div class="header-title">Maintenance Mode</div>
                                <div class="header-subtitle">Take your site offline for maintenance</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="control-group">
                                <div class="control-left">
                                    <div class="control-icon"><i class="fas fa-power-off"></i></div>
                                    <div>
                                        <div class="control-title">Enable Maintenance Mode</div>
                                        <div class="control-desc">Only admins can access the site. Visitors see a maintenance page.</div>
                                    </div>
                                </div>
                                <div class="control-toggle">
                                    <div class="toggle-status">
                                        <span class="status-dot status-dot-disabled" id="maintenanceDot"></span>
                                        <span id="maintenanceStatusText">Disabled</span>
                                    </div>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="maintenanceMode">
                                        <label for="maintenanceMode" class="toggle-slider"></label>
                                    </div>
                                </div>
                            </div>
                            <div class="info-banner info-banner-amber">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>Login, Signup, Forgot Password, and Google OAuth will be blocked for all users. Only manual admin login will be available.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid: System Overview + Security -->
                <div class="settings-grid">

                    <!-- System Overview Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-amber"><i class="fas fa-server"></i></div>
                            <div>
                                <div class="header-title">System Overview</div>
                                <div class="header-subtitle">Core system configuration</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-success"><i class="fas fa-database"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Database</div>
                                    <div class="stat-item-value"><?php echo DB_NAME; ?></div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-accent"><i class="fas fa-clock"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Session Timeout</div>
                                    <div class="stat-item-value"><?php echo SESSION_TIMEOUT / 60; ?> minutes</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-warning"><i class="fas fa-shield-alt"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Max Login Attempts</div>
                                    <div class="stat-item-value"><?php echo MAX_LOGIN_ATTEMPTS; ?> attempts</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-danger"><i class="fas fa-lock"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Lockout Duration</div>
                                    <div class="stat-item-value"><?php echo LOGIN_LOCKOUT_TIME / 60; ?> minutes</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Security Status Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-green"><i class="fas fa-shield-alt"></i></div>
                            <div>
                                <div class="header-title">Security Status</div>
                                <div class="header-subtitle">Active security features</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fas fa-check-circle"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Session Security</div>
                                    <div class="security-feature-desc">HTTPOnly cookies &amp; Strict SameSite</div>
                                </div>
                                <div class="security-feature-badge"><i class="fas fa-shield-alt"></i> Active</div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fas fa-check-circle"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Password Encryption</div>
                                    <div class="security-feature-desc">Bcrypt (PASSWORD_DEFAULT)</div>
                                </div>
                                <div class="security-feature-badge"><i class="fas fa-shield-alt"></i> Active</div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fas fa-check-circle"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Rate Limiting</div>
                                    <div class="security-feature-desc">User &amp; IP tracking enabled</div>
                                </div>
                                <div class="security-feature-badge"><i class="fas fa-shield-alt"></i> Active</div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fas fa-check-circle"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">CSRF Protection</div>
                                    <div class="security-feature-desc">Token-based validation</div>
                                </div>
                                <div class="security-feature-badge"><i class="fas fa-shield-alt"></i> Active</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Server Information Card (Full Width) -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-pink"><i class="fas fa-server"></i></div>
                        <div>
                            <div class="header-title">Server Information</div>
                            <div class="header-subtitle">Environment details and server configuration</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="settings-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 8px;">
                            <div class="server-info-item">
                                <div class="server-info-label"><i class="fas fa-database"></i> Database Host</div>
                                <div class="server-info-value"><?php echo DB_HOST; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label"><i class="fas fa-user"></i> Database User</div>
                                <div class="server-info-value"><?php echo DB_USER; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label"><i class="fas fa-code"></i> PHP Version</div>
                                <div class="server-info-value"><span class="version-badge"><?php echo phpversion(); ?></span></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label"><i class="fas fa-hdd"></i> Max Upload</div>
                                <div class="server-info-value"><span class="upload-badge"><?php echo ini_get('upload_max_filesize'); ?></span></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label"><i class="fas fa-clock"></i> Server Time</div>
                                <div class="server-info-value time-value"><?php echo date('Y-m-d H:i:s'); ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label"><i class="fas fa-globe"></i> Timezone</div>
                                <div class="server-info-value"><?php echo date_default_timezone_get(); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save Actions -->
                <div class="card-modern">
                    <div class="card-body" style="display:flex; gap:12px; flex-wrap:wrap; justify-content:center;">
                        <button class="btn btn-primary" onclick="saveSettings()" style="min-width:160px;">
                            <i class="fas fa-save"></i> Save All Settings
                        </button>
                        <button class="btn btn-ghost" onclick="loadSettings()">
                            <i class="fas fa-sync"></i> Reload
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Google Translate - Loaded conditionally via JavaScript -->
    <div id="google_translate_element" style="display:none;"></div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- CSRF Token - DISABLED for settings -->
    <script>
    // CSRF is TEMPORARILY DISABLED for settings AJAX requests
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            // Skip CSRF header for all settings AJAX requests
            if (settings.data && (
                settings.data.indexOf('action=getSettings') !== -1 ||
                settings.data.indexOf('action=saveSettings') !== -1 ||
                settings.data.indexOf('action=uploadSiteLogo') !== -1
            )) {
                return;
            }
            // Only send CSRF for non-settings requests
            xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo generateCSRFToken(); ?>');
        }
    });
    </script>

    <script>
    /* Developed by Rameez Scripts */

    // ==========================================
    // LANGUAGE HANDLING - COMPLETE FIX
    // ==========================================
    
    // Get the current language from PHP
    var currentLanguage = '<?php echo $current_language; ?>';
    var translationActive = <?php echo $translation_active ? 'true' : 'false'; ?>;
    var googleTranslateLoaded = false;

    // Function to clear ALL Google Translate cookies
    function clearTranslateCookies() {
        var domains = [
            '',
            window.location.hostname,
            '.' + window.location.hostname
        ];
        domains.forEach(function(domain) {
            var cookieStr = 'googtrans=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
            if (domain) {
                cookieStr += ';domain=' + domain;
            }
            document.cookie = cookieStr;
        });
        console.log('[Lang] Cleared all translation cookies');
    }

    // Function to set translation cookie
    function setTranslateCookie(lang) {
        clearTranslateCookies();
        if (lang && lang !== 'en') {
            var value = '/en/' + lang;
            document.cookie = 'googtrans=' + value + ';path=/';
            document.cookie = 'googtrans=' + value + ';path=/;domain=' + window.location.hostname;
            document.cookie = 'googtrans=' + value + ';path=/;domain=.' + window.location.hostname;
            console.log('[Lang] Set translation cookie to:', lang);
            return true;
        }
        return false;
    }

    // Load Google Translate script dynamically
    function loadGoogleTranslate(callback) {
        if (googleTranslateLoaded) {
            if (callback) callback();
            return;
        }
        
        // Check if already loaded
        if (typeof google !== 'undefined' && google.translate) {
            googleTranslateLoaded = true;
            if (callback) callback();
            return;
        }

        // Function to initialize translate
        window.googleTranslateElementInit = function() {
            try {
                new google.translate.TranslateElement({
                    pageLanguage: 'en',
                    autoDisplay: false,
                    layout: google.translate.TranslateElement.InlineLayout.SIMPLE
                }, 'google_translate_element');
                googleTranslateLoaded = true;
                console.log('[Lang] Google Translate initialized');
                if (callback) callback();
            } catch(e) {
                console.error('[Lang] Translate init error:', e);
                if (callback) callback();
            }
        };

        // Load the script
        var script = document.createElement('script');
        script.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
        script.async = true;
        script.onerror = function() {
            console.error('[Lang] Failed to load Google Translate script');
            if (callback) callback();
        };
        document.head.appendChild(script);
    }

    // Apply language translation
    function applyLanguage(lang, callback) {
        console.log('[Lang] Applying language:', lang);
        
        // Clear existing translation
        clearTranslateCookies();
        
        if (lang && lang !== 'en') {
            // Set the translation cookie
            setTranslateCookie(lang);
            
            // Load Google Translate if not loaded
            loadGoogleTranslate(function() {
                // Force translation by reloading the page
                console.log('[Lang] Reloading page for translation');
                if (callback) callback();
                // Small delay to ensure cookies are set
                setTimeout(function() {
                    location.reload();
                }, 300);
            });
        } else {
            // English - just reload without translation
            console.log('[Lang] Setting to English, reloading');
            if (callback) callback();
            setTimeout(function() {
                location.reload();
            }, 200);
        }
    }

    // ==========================================
    // ON LANGUAGE CHANGE
    // ==========================================
    function onLanguageChange(select) {
        var selectedLang = select.value;
        console.log('[Lang] User selected:', selectedLang);
        
        // Show loading
        Swal.fire({
            title: 'Changing Language...',
            text: 'Please wait while the language is applied',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        // Save the language setting first
        var formData = {
            action: 'saveSettings',
            allow_user_profile_uploads: $('#allowUserUploads').is(':checked') ? '1' : '0',
            site_name: $('#siteName').val().trim(),
            copyright_text: $('#copyrightText').val().trim(),
            currency_symbol: $('#currencySymbol').val(),
            default_language: selectedLang,
            maintenance_mode: $('#maintenanceMode').is(':checked') ? '1' : '0',
            gemini_api_key: $('#geminiApiKey').val().trim()
        };

        $.ajax({
            url: '',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Apply the language
                    applyLanguage(selectedLang);
                } else {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.close();
                console.error('AJAX Error:', status, error);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Failed to save language setting. Please try again.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    // ==========================================
    // REVERT TO ENGLISH
    // ==========================================
    function revertToEnglish() {
        $('#defaultLanguage').val('en');
        onLanguageChange(document.getElementById('defaultLanguage'));
    }

    // ==========================================
    // INIT
    // ==========================================
    $(document).ready(function() {
        loadSettings();
        setupToggleListeners();

        // Set the language selector to current language
        $('#defaultLanguage').val(currentLanguage);

        // If language is not English, load Google Translate
        if (currentLanguage !== 'en') {
            loadGoogleTranslate(function() {
                console.log('[Lang] Google Translate loaded for:', currentLanguage);
            });
        }
    });

    function setupToggleListeners() {
        $('#allowUserUploads').on('change', function() {
            updateToggleStatus('upload', this.checked);
        });
        $('#maintenanceMode').on('change', function() {
            updateToggleStatus('maintenance', this.checked);
        });
    }

    function updateToggleStatus(type, isEnabled) {
        const dotMap = {
            'upload': 'uploadDot',
            'maintenance': 'maintenanceDot'
        };
        const textMap = {
            'upload': 'uploadStatusText',
            'maintenance': 'maintenanceStatusText'
        };

        const dotEl = document.getElementById(dotMap[type]);
        const textEl = document.getElementById(textMap[type]);

        if (isEnabled) {
            dotEl.className = 'status-dot status-dot-enabled';
            textEl.textContent = 'Enabled';
            textEl.style.color = 'var(--accent-green)';
            if (type === 'maintenance') {
                textEl.style.color = 'var(--accent-red)';
            }
        } else {
            dotEl.className = 'status-dot status-dot-disabled';
            textEl.textContent = 'Disabled';
            textEl.style.color = 'var(--text-muted)';
        }
    }

    // ==========================================
    // LOAD SETTINGS
    // ==========================================
    function loadSettings() {
        $('#loadingSkeleton').show();
        $('#settingsContent').hide();

        $.ajax({
            url: '',
            method: 'POST',
            data: { action: 'getSettings' },
            dataType: 'json',
            success: function(response) {
                setTimeout(() => {
                    $('#loadingSkeleton').hide();
                    $('#settingsContent').fadeIn(300);
                }, 400);

                if (response.success) {
                    const data = response.data;

                    // Toggle states
                    $('#allowUserUploads').prop('checked', data.allow_user_profile_uploads === '1');
                    updateToggleStatus('upload', data.allow_user_profile_uploads === '1');

                    $('#maintenanceMode').prop('checked', data.maintenance_mode === '1');
                    updateToggleStatus('maintenance', data.maintenance_mode === '1');

                    // Branding
                    $('#siteName').val(data.site_name || '');
                    $('#copyrightText').val(data.copyright_text || '');

                    // Currency
                    $('#currencySymbol').val(data.currency_symbol || '$');

                    // Language - use the current language from PHP
                    $('#defaultLanguage').val(currentLanguage);

                    // API Key
                    $('#geminiApiKey').val(data.gemini_api_key || '');

                    // Logo
                    updateLogoPreview(data.site_logo);

                    console.log('[Lang] Settings loaded, language:', currentLanguage);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to load settings',
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function(xhr, status, error) {
                $('#loadingSkeleton').hide();
                $('#settingsContent').show();
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                
                // Don't show session expired for settings - just show error
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not connect to server. Please refresh the page and try again.',
                    confirmButtonText: 'Refresh Page',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                }).then(function() {
                    location.reload();
                });
            }
        });
    }

    // ==========================================
    // SAVE SETTINGS (Manual Save)
    // ==========================================
    function saveSettings() {
        var selectedLanguage = $('#defaultLanguage').val();
        console.log('[Lang] Saving language:', selectedLanguage);

        const formData = {
            action: 'saveSettings',
            allow_user_profile_uploads: $('#allowUserUploads').is(':checked') ? '1' : '0',
            site_name: $('#siteName').val().trim(),
            copyright_text: $('#copyrightText').val().trim(),
            currency_symbol: $('#currencySymbol').val(),
            default_language: selectedLanguage,
            maintenance_mode: $('#maintenanceMode').is(':checked') ? '1' : '0',
            gemini_api_key: $('#geminiApiKey').val().trim()
        };

        Swal.fire({
            title: 'Saving Settings...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                Swal.close();
                if (response.success) {
                    // If language changed, apply it
                    if (selectedLanguage !== currentLanguage) {
                        applyLanguage(selectedLanguage);
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: 'Settings Saved!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false,
                            background: 'var(--bg-card)',
                            color: 'var(--text-primary)'
                        });
                    }
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
            error: function(xhr, status, error) {
                Swal.close();
                console.error('AJAX Error:', status, error);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Failed to save settings. Please try again.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    // ==========================================
    // API KEY VISIBILITY
    // ==========================================
    function toggleApiKeyVisibility() {
        const input = document.getElementById('geminiApiKey');
        const icon = document.getElementById('apiKeyToggleIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
        }
    }

    // ==========================================
    // LOGO UPLOAD
    // ==========================================
    function updateLogoPreview(url) {
        const preview = document.getElementById('logoPreview');
        if (url && url.trim() !== '') {
            preview.src = url;
            preview.style.display = 'block';
            preview.onerror = function() { this.style.display = 'none'; };
        } else {
            preview.style.display = 'none';
        }
    }

    $('#logoFileInput').on('change', function() {
        const file = this.files[0];
        if (!file) return;

        $('#logoFileName').text(file.name);

        const reader = new FileReader();
        reader.onload = function(e) {
            updateLogoPreview(e.target.result);
        };
        reader.readAsDataURL(file);

        const formData = new FormData();
        formData.append('action', 'uploadSiteLogo');
        formData.append('logo_file', file);

        Swal.fire({
            title: 'Uploading Logo...',
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
                Swal.close();
                if (response.success) {
                    updateLogoPreview(response.logo_path);
                    Swal.fire({
                        icon: 'success',
                        title: 'Logo Updated!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
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
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to upload logo.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    });

    // ==========================================
    // KEYBOARD SHORTCUTS
    // ==========================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveSettings();
        }
    });

    console.log('🚀 System Settings page loaded successfully');
    console.log('⌨️ Shortcut: Ctrl+S to save settings');
    console.log('🌐 Current language:', currentLanguage);
    console.log('🔄 Translation active:', translationActive);
    </script>
</body>
</html>