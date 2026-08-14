<?php
/**
 * Developed by Muhafiz Ahmed
 */

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
$current_page = 'oauth_setup';

// Only admins can access this page
if ($role !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    enforceCSRF();

    if ($_POST['action'] === 'getOAuthSettings') {
        try {
            $enabled = getSetting('google_oauth_enabled', '0');
            $client_id = getSetting('google_client_id', '');
            $client_secret = getSetting('google_client_secret', '');
            $redirect_uri = getSetting('google_redirect_uri', '');

            // Mask credentials - show only if they exist (as masked)
            $has_client_id = !empty($client_id);
            $has_client_secret = !empty($client_secret);
            $has_redirect_uri = !empty($redirect_uri);

            echo json_encode([
                'success' => true,
                'data' => [
                    'google_oauth_enabled' => $enabled,
                    'has_client_id' => $has_client_id,
                    'has_client_secret' => $has_client_secret,
                    'has_redirect_uri' => $has_redirect_uri,
                    'redirect_uri_display' => $has_redirect_uri ? str_repeat('*', strlen($redirect_uri)) : ''
                ]
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveOAuthSettings') {
        try {
            $enabled = isset($_POST['google_oauth_enabled']) ? $_POST['google_oauth_enabled'] : '0';
            $client_id = isset($_POST['google_client_id']) ? trim($_POST['google_client_id']) : '';
            $client_secret = isset($_POST['google_client_secret']) ? trim($_POST['google_client_secret']) : '';
            $redirect_uri = isset($_POST['google_redirect_uri']) ? trim($_POST['google_redirect_uri']) : '';

            // Save enabled/disabled toggle
            setSetting('google_oauth_enabled', $enabled);

            // Only update credentials if new values are provided (not empty placeholder)
            if (!empty($client_id)) {
                setSetting('google_client_id', $client_id);
            }
            if (!empty($client_secret)) {
                setSetting('google_client_secret', $client_secret);
            }
            if (!empty($redirect_uri)) {
                setSetting('google_redirect_uri', $redirect_uri);
            }

            // Log activity
            $statusText = $enabled === '1' ? 'enabled' : 'disabled';
            logActivity($user_id, $username, 'OAuth Settings Updated', "Google OAuth $statusText");

            echo json_encode([
                'success' => true,
                'message' => 'Google OAuth settings saved successfully'
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'clearOAuthCredentials') {
        try {
            setSetting('google_client_id', '');
            setSetting('google_client_secret', '');
            setSetting('google_redirect_uri', '');
            setSetting('google_oauth_enabled', '0');

            logActivity($user_id, $username, 'OAuth Credentials Cleared', 'Google OAuth credentials removed');

            echo json_encode([
                'success' => true,
                'message' => 'Google OAuth credentials cleared successfully'
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error clearing credentials: ' . $e->getMessage()]);
            exit();
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
    <title>Google OAuth Setup · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           OAUTH SETUP - WORLD-CLASS MODERN UI
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
            --accent-google: #ea4335;
            --accent-google-bg: rgba(234, 67, 53, 0.12);
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
            background: linear-gradient(135deg, var(--accent-google-bg), rgba(234, 67, 53, 0.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: var(--accent-google);
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
            background: var(--accent-google-bg); color: var(--accent-google);
            border: 1px solid rgba(234, 67, 53, 0.15);
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
        .btn-google {
            background: linear-gradient(135deg, #ea4335, #d33426);
            color: #fff; border-color: transparent;
        }
        .btn-google:hover {
            box-shadow: 0 4px 24px rgba(234, 67, 53, 0.35);
            transform: translateY(-1px); color: #fff;
        }

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
        .header-icon-google { background: var(--accent-google-bg); color: var(--accent-google); }
        .header-icon-blue { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .header-icon-green { background: var(--accent-green-bg); color: var(--accent-green); }
        .header-icon-purple { background: var(--accent-purple-bg); color: var(--accent-purple); }
        .header-icon-amber { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .header-icon-red { background: var(--accent-red-bg); color: var(--accent-red); }

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
        .form-group label i {
            margin-right: 6px; color: var(--text-muted); width: 16px;
        }
        .form-group input {
            width: 100%; padding: 12px 16px;
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
        .form-group input::placeholder { color: var(--text-muted); }
        .form-group input.readonly-placeholder {
            color: var(--text-muted);
            font-style: italic;
        }

        .form-actions {
            display: flex; gap: 12px; margin-top: 20px;
            padding-top: 16px; border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .form-actions .btn { flex: 1; justify-content: center; }

        /* Control Group (Toggle) */
        .control-group {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
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

        /* Credential Input */
        .credential-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .credential-input-wrapper input {
            flex: 1;
            padding-right: 44px;
        }
        .credential-status {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
        }
        .credential-status .text-success { color: var(--accent-green); }
        .credential-status .text-warning { color: var(--accent-amber); }

        /* Info Banner */
        .info-banner {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 14px 18px;
            border-radius: var(--radius-md);
            margin-top: 14px;
        }
        .info-banner i {
            font-size: 18px; flex-shrink: 0; margin-top: 2px;
        }
        .info-banner span {
            font-size: 13px; color: var(--text-secondary);
        }
        body:not(.dark-mode) .info-banner span { color: #475569; }
        .info-banner span strong { color: var(--text-primary); }
        body:not(.dark-mode) .info-banner span strong { color: #0f172a; }
        .info-banner-blue {
            background: var(--accent-blue-bg);
            border: 1px solid rgba(59, 130, 246, 0.15);
        }
        .info-banner-blue i { color: var(--accent-blue); }
        .info-banner-amber {
            background: var(--accent-amber-bg);
            border: 1px solid rgba(245, 158, 11, 0.15);
        }
        .info-banner-amber i { color: var(--accent-amber); }
        .info-banner-google {
            background: var(--accent-google-bg);
            border: 1px solid rgba(234, 67, 53, 0.15);
        }
        .info-banner-google i { color: var(--accent-google); }

        /* Security Features / Status Items */
        .security-feature {
            display: flex; align-items: center; gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .security-feature:last-child { border-bottom: none; }
        .security-feature-icon {
            width: 32px; height: 32px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            background: var(--bg-input);
            color: var(--text-secondary);
        }
        .security-feature-content { flex: 1; }
        .security-feature-name {
            font-size: 14px; font-weight: 500; color: var(--text-primary);
        }
        body:not(.dark-mode) .security-feature-name { color: #0f172a; }
        .security-feature-desc {
            font-size: 12px; color: var(--text-muted);
        }
        .security-feature-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 12px; border-radius: var(--radius-full);
            font-size: 11px; font-weight: 500;
            flex-shrink: 0;
        }
        .security-feature-badge.active {
            background: var(--accent-green-bg);
            color: var(--accent-green);
        }
        .security-feature-badge.stat-icon-danger {
            background: var(--accent-red-bg);
            color: var(--accent-red);
        }

        /* Guide Table */
        .guide-table-wrapper { overflow-x: auto; }
        .guide-table {
            width: 100%; border-collapse: collapse; font-size: 14px;
        }
        .guide-table thead th {
            padding: 12px 16px; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.06em;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
            background: var(--bg-secondary);
            text-align: left;
        }
        body:not(.dark-mode) .guide-table thead th {
            background: #eef2f6; color: #475569;
        }
        .guide-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            vertical-align: middle;
        }
        body:not(.dark-mode) .guide-table tbody td { color: #475569; }
        .guide-table tbody tr:hover td {
            background: var(--bg-card-hover);
        }
        body:not(.dark-mode) .guide-table tbody tr:hover td {
            background: #f8fafc;
        }
        .guide-table .step-num {
            font-weight: 700; color: var(--accent-google);
            width: 40px; text-align: center;
        }
        .guide-table .step-name {
            font-weight: 500; color: var(--text-primary);
            white-space: nowrap;
        }
        body:not(.dark-mode) .guide-table .step-name { color: #0f172a; }
        .guide-table .step-final td {
            background: var(--accent-green-bg);
            font-weight: 500;
        }
        body:not(.dark-mode) .guide-table .step-final td {
            background: #ecfdf5;
        }
        .guide-table code {
            background: var(--bg-input);
            padding: 2px 8px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: monospace;
            color: var(--text-primary);
            word-break: break-all;
        }
        body:not(.dark-mode) .guide-table code {
            background: #f1f4f8;
            color: #0f172a;
        }

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

        /* Responsive */
        @media (max-width: 1024px) {
            .settings-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .card-modern .card-body { padding: 16px; }
            .control-group { flex-wrap: wrap; gap: 12px; }
            .control-toggle { margin-left: auto; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { flex: none; }
            .settings-grid { gap: 16px; }
            .security-feature { flex-wrap: wrap; }
            .security-feature-badge { margin-left: 46px; }
            .guide-table { font-size: 13px; }
            .guide-table tbody td { padding: 10px 12px; }
            .guide-table .step-name { white-space: normal; }
        }
        @media (max-width: 480px) {
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .control-group { flex-direction: column; align-items: stretch; }
            .control-toggle { margin-left: 0; justify-content: space-between; }
            .guide-table .step-num { width: 30px; }
            .guide-table tbody td { padding: 8px 10px; font-size: 12px; }
            .guide-table code { font-size: 11px; }
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
                <a href="settings.php">System</a>
                <span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                <span class="current">OAuth Setup</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fab fa-google"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>Google OAuth Setup</h1>
                        <p class="subtitle">
                            Configure Google Login and Signup for your application
                            <span class="badge-beta">v2.0</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadOAuthSettings()" title="Refresh">
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

            <!-- OAuth Content -->
            <div id="oauthContent" class="initially-hidden">

                <!-- OAuth Toggle Card -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-google"><i class="fab fa-google"></i></div>
                        <div>
                            <div class="header-title">Google OAuth Login</div>
                            <div class="header-subtitle">Enable or disable Google login &amp; signup on your website</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="control-group">
                            <div class="control-left">
                                <div class="control-icon"><i class="fas fa-power-off"></i></div>
                                <div>
                                    <div class="control-title">Google Login / Signup</div>
                                    <div class="control-desc">Allow users to login or register with their Google account</div>
                                </div>
                            </div>
                            <div class="control-toggle">
                                <div class="toggle-status">
                                    <span class="status-dot status-dot-disabled" id="oauthDot"></span>
                                    <span id="oauthStatusText">Disabled</span>
                                </div>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="googleOAuthEnabled">
                                    <label for="googleOAuthEnabled" class="toggle-slider"></label>
                                </div>
                            </div>
                        </div>
                        <div class="info-banner info-banner-google">
                            <i class="fas fa-info-circle"></i>
                            <span>You must add valid Google OAuth credentials below before enabling this feature. When enabled, <strong>"Login with Google"</strong> and <strong>"Sign up with Google"</strong> buttons will appear on the login &amp; signup pages.</span>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid: Credentials + Status -->
                <div class="settings-grid">

                    <!-- Credentials Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-purple"><i class="fas fa-key"></i></div>
                            <div>
                                <div class="header-title">OAuth Credentials</div>
                                <div class="header-subtitle">Google Cloud Console credentials</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> Client ID</label>
                                <div class="credential-input-wrapper">
                                    <input type="password" id="googleClientId" placeholder="Enter Google Client ID" autocomplete="off">
                                    <span class="credential-status" id="clientIdStatus"></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Client Secret</label>
                                <div class="credential-input-wrapper">
                                    <input type="password" id="googleClientSecret" placeholder="Enter Google Client Secret" autocomplete="off">
                                    <span class="credential-status" id="clientSecretStatus"></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-link"></i> Redirect URI</label>
                                <div class="credential-input-wrapper">
                                    <input type="password" id="googleRedirectUri" placeholder="e.g. http://localhost/oauth_callback.php" autocomplete="off">
                                    <span class="credential-status" id="redirectUriStatus"></span>
                                </div>
                            </div>
                            <div class="info-banner info-banner-amber" style="margin-top:8px;">
                                <i class="fas fa-eye-slash"></i>
                                <span><strong>Credentials are always hidden</strong> — Enter new values to update, or leave empty to keep current values.</span>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-success" onclick="saveOAuthSettings()">
                                    <i class="fas fa-save"></i> Save Settings
                                </button>
                                <button class="btn btn-danger" onclick="clearCredentials()">
                                    <i class="fas fa-trash-alt"></i> Clear All
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Configuration Status Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-green"><i class="fas fa-clipboard-check"></i></div>
                            <div>
                                <div class="header-title">Configuration Status</div>
                                <div class="header-subtitle">Current OAuth setup status</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fab fa-google"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Google OAuth</div>
                                    <div class="security-feature-desc">Login/Signup feature</div>
                                </div>
                                <div class="security-feature-badge stat-icon-danger" id="statusOAuth">
                                    <i class="fas fa-times-circle"></i> Disabled
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fas fa-id-card"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Client ID</div>
                                    <div class="security-feature-desc">Google OAuth Client ID</div>
                                </div>
                                <div class="security-feature-badge stat-icon-danger" id="statusClientId">
                                    <i class="fas fa-times-circle"></i> Not Set
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fas fa-lock"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Client Secret</div>
                                    <div class="security-feature-desc">Google OAuth Client Secret</div>
                                </div>
                                <div class="security-feature-badge stat-icon-danger" id="statusClientSecret">
                                    <i class="fas fa-times-circle"></i> Not Set
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon"><i class="fas fa-link"></i></div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Redirect URI</div>
                                    <div class="security-feature-desc">OAuth callback URL</div>
                                </div>
                                <div class="security-feature-badge stat-icon-danger" id="statusRedirectUri">
                                    <i class="fas fa-times-circle"></i> Not Set
                                </div>
                            </div>
                            <div class="info-banner info-banner-blue" style="margin-top:8px;">
                                <i class="fas fa-info-circle"></i>
                                <span>All four items must be <strong>Configured</strong> and <strong>Enabled</strong> for OAuth to work.</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Setup Guide Card (Full Width) -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-amber"><i class="fas fa-book-open"></i></div>
                        <div>
                            <div class="header-title">Step-by-Step Setup Guide</div>
                            <div class="header-subtitle">How to get Google OAuth credentials from Google Cloud Console</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="guide-table-wrapper">
                            <table class="guide-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th>Step</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="step-num">1</td>
                                        <td class="step-name">Go to Google Cloud Console</td>
                                        <td>Open <strong>console.cloud.google.com</strong> and sign in with your Google account</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">2</td>
                                        <td class="step-name">Create a New Project</td>
                                        <td>Click the project dropdown (top-left) &rarr; <strong>New Project</strong> &rarr; Enter a project name &rarr; Click <strong>Create</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">3</td>
                                        <td class="step-name">Go to APIs &amp; Services</td>
                                        <td>From the left sidebar menu, click <strong>APIs &amp; Services</strong> &rarr; then click <strong>OAuth consent screen</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">4</td>
                                        <td class="step-name">Configure Consent Screen</td>
                                        <td>Select <strong>External</strong> user type &rarr; Click <strong>Create</strong> &rarr; Fill in App name, User support email, Developer email &rarr; Click <strong>Save and Continue</strong> through all steps</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">5</td>
                                        <td class="step-name">Add Scopes</td>
                                        <td>On the Scopes step, click <strong>Add or Remove Scopes</strong> &rarr; Select <strong>email</strong>, <strong>profile</strong>, and <strong>openid</strong> &rarr; Click <strong>Update</strong> &rarr; <strong>Save and Continue</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">6</td>
                                        <td class="step-name">Add Test Users</td>
                                        <td>If app is in <strong>Testing</strong> mode, add your Gmail address as a test user &rarr; Click <strong>Save and Continue</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">7</td>
                                        <td class="step-name">Create OAuth Credentials</td>
                                        <td>Go to <strong>Credentials</strong> (left sidebar) &rarr; Click <strong>+ Create Credentials</strong> &rarr; Select <strong>OAuth 2.0 Client IDs</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">8</td>
                                        <td class="step-name">Set Application Type</td>
                                        <td>Select <strong>Web application</strong> as the application type &rarr; Give it a name (e.g., "My Login System")</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">9</td>
                                        <td class="step-name">Add Authorized Redirect URI</td>
                                        <td>
                                            Under <strong>Authorized redirect URIs</strong>, click <strong>+ Add URI</strong> and enter your callback URL:<br>
                                            <code>
                                                <?php
                                                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                                $host = $_SERVER['HTTP_HOST'];
                                                $path = dirname($_SERVER['SCRIPT_NAME']);
                                                echo $protocol . '://' . $host . $path . '/oauth_callback.php';
                                                ?>
                                            </code>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">10</td>
                                        <td class="step-name">Copy Client ID &amp; Secret</td>
                                        <td>Click <strong>Create</strong> &rarr; A popup will show your <strong>Client ID</strong> and <strong>Client Secret</strong> &rarr; Copy both values</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">11</td>
                                        <td class="step-name">Paste Credentials Here</td>
                                        <td>Paste <strong>Client ID</strong>, <strong>Client Secret</strong>, and the <strong>Redirect URI</strong> in the credentials card above &rarr; Click <strong>Save Settings</strong></td>
                                    </tr>
                                    <tr class="step-final">
                                        <td class="step-num">12</td>
                                        <td class="step-name">Enable &amp; Test</td>
                                        <td>Turn ON the <strong>Google Login / Signup</strong> toggle &rarr; Click <strong>Save Settings</strong> &rarr; Visit your login page to see the "Login with Google" button</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="info-banner info-banner-blue">
                            <i class="fas fa-magic"></i>
                            <span><strong>How it works:</strong> When a user clicks "Login with Google" or "Sign up with Google", if they don't have an account yet, one is automatically created using their Google email and they are logged in directly. No extra steps needed.</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- CSRF Token -->
    <script>
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo generateCSRFToken(); ?>');
        }
    });
    </script>

    <script>
    /* Developed by Muhafiz Ahmed */

    // ==========================================
    // INIT
    // ==========================================
    $(document).ready(function() {
        loadOAuthSettings();
        setupToggleListeners();
    });

    function setupToggleListeners() {
        $('#googleOAuthEnabled').on('change', function() {
            updateToggleStatus(this.checked);
        });
    }

    function updateToggleStatus(isEnabled) {
        const dotEl = document.getElementById('oauthDot');
        const textEl = document.getElementById('oauthStatusText');

        if (isEnabled) {
            dotEl.className = 'status-dot status-dot-enabled';
            textEl.textContent = 'Enabled';
            textEl.style.color = 'var(--accent-green)';
        } else {
            dotEl.className = 'status-dot status-dot-disabled';
            textEl.textContent = 'Disabled';
            textEl.style.color = 'var(--text-muted)';
        }
    }

    // ==========================================
    // LOAD OAUTH SETTINGS
    // ==========================================
    function loadOAuthSettings() {
        $('#loadingSkeleton').show();
        $('#oauthContent').hide();

        $.ajax({
            url: '',
            method: 'POST',
            data: { action: 'getOAuthSettings' },
            dataType: 'json',
            success: function(response) {
                setTimeout(() => {
                    $('#loadingSkeleton').hide();
                    $('#oauthContent').fadeIn(300);
                }, 400);

                if (response.success) {
                    const data = response.data;

                    // Toggle
                    $('#googleOAuthEnabled').prop('checked', data.google_oauth_enabled === '1');
                    updateToggleStatus(data.google_oauth_enabled === '1');

                    // Clear input fields (credentials stay hidden)
                    $('#googleClientId').val('');
                    $('#googleClientSecret').val('');
                    $('#googleRedirectUri').val('');

                    // Update placeholders to show status
                    $('#googleClientId').attr('placeholder', data.has_client_id ? '••••••••••••••••••••••• (saved)' : 'Enter Google Client ID');
                    $('#googleClientSecret').attr('placeholder', data.has_client_secret ? '••••••••••••••••••••••• (saved)' : 'Enter Google Client Secret');
                    $('#googleRedirectUri').attr('placeholder', data.has_redirect_uri ? '••••••••••••••••••••••• (saved)' : 'e.g. http://localhost/oauth_callback.php');

                    // Update status badges
                    updateStatusBadge('statusOAuth', data.google_oauth_enabled === '1');
                    updateStatusBadge('statusClientId', data.has_client_id);
                    updateStatusBadge('statusClientSecret', data.has_client_secret);
                    updateStatusBadge('statusRedirectUri', data.has_redirect_uri);

                    // Update credential status indicators
                    updateCredentialStatus('clientIdStatus', data.has_client_id);
                    updateCredentialStatus('clientSecretStatus', data.has_client_secret);
                    updateCredentialStatus('redirectUriStatus', data.has_redirect_uri);

                    // Add class to placeholders for visual distinction
                    $('.credential-input-wrapper input').each(function() {
                        if ($(this).attr('placeholder').includes('(saved)')) {
                            $(this).addClass('readonly-placeholder');
                        } else {
                            $(this).removeClass('readonly-placeholder');
                        }
                    });
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
            error: function() {
                $('#loadingSkeleton').hide();
                $('#oauthContent').show();
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not connect to server.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    // ==========================================
    // SAVE OAUTH SETTINGS
    // ==========================================
    function saveOAuthSettings() {
        const formData = {
            action: 'saveOAuthSettings',
            google_oauth_enabled: $('#googleOAuthEnabled').is(':checked') ? '1' : '0',
            google_client_id: $('#googleClientId').val().trim(),
            google_client_secret: $('#googleClientSecret').val().trim(),
            google_redirect_uri: $('#googleRedirectUri').val().trim()
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
                    Swal.fire({
                        icon: 'success',
                        title: 'Settings Saved!',
                        text: response.message,
                        timer: 1500,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    }).then(function() {
                        loadOAuthSettings();
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
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to save settings.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    // ==========================================
    // CLEAR CREDENTIALS
    // ==========================================
    function clearCredentials() {
        Swal.fire({
            title: 'Clear All Credentials?',
            text: 'This will remove all Google OAuth credentials and disable the feature.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, clear all!',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Clearing...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });

                $.ajax({
                    url: '',
                    method: 'POST',
                    data: { action: 'clearOAuthCredentials' },
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Cleared!',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            }).then(function() {
                                loadOAuthSettings();
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
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to clear credentials.',
                            background: 'var(--bg-card)',
                            color: 'var(--text-primary)'
                        });
                    }
                });
            }
        });
    }

    // ==========================================
    // UI HELPERS
    // ==========================================
    function updateStatusBadge(elementId, isActive) {
        const badge = document.getElementById(elementId);
        if (isActive) {
            badge.className = 'security-feature-badge active';
            badge.innerHTML = '<i class="fas fa-check-circle"></i> ' + (elementId === 'statusOAuth' ? 'Enabled' : 'Configured');
        } else {
            badge.className = 'security-feature-badge stat-icon-danger';
            badge.innerHTML = '<i class="fas fa-times-circle"></i> ' + (elementId === 'statusOAuth' ? 'Disabled' : 'Not Set');
        }
    }

    function updateCredentialStatus(elementId, hasValue) {
        const el = document.getElementById(elementId);
        if (hasValue) {
            el.innerHTML = '<i class="fas fa-check-circle text-success"></i>';
        } else {
            el.innerHTML = '<i class="fas fa-exclamation-circle text-warning"></i>';
        }
    }

    // ==========================================
    // KEYBOARD SHORTCUTS
    // ==========================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveOAuthSettings();
        }
    });

    console.log('🚀 Google OAuth Setup page loaded successfully');
    console.log('⌨️ Shortcut: Ctrl+S to save settings');
    </script>
</body>
</html>