<?php
/**
 * Developed by Muhafiz Ahmed
 * WhatsApp: https://wa.me/+251953115473 (For Custom Projects)
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
$current_page = 'smtp_setup';

// Only admins can access this page
if ($role !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    enforceCSRF();

    if ($_POST['action'] === 'getSmtpSettings') {
        try {
            $enabled = getSetting('smtp_enabled', '0');
            $host = getSetting('smtp_host', '');
            $port = getSetting('smtp_port', '587');
            $smtp_user = getSetting('smtp_username', '');
            $smtp_pass = getSetting('smtp_password', '');
            $from_email = getSetting('smtp_from_email', '');
            $from_name = getSetting('smtp_from_name', 'Dashboard System');
            $encryption = getSetting('smtp_encryption', 'tls');
            $verification_enabled = getSetting('email_verification_enabled', '0');
            $show_forgot_password = getSetting('show_forgot_password', '1');

            echo json_encode([
                'success' => true,
                'data' => [
                    'smtp_enabled' => $enabled,
                    'smtp_host' => $host,
                    'smtp_port' => $port,
                    'has_username' => !empty($smtp_user),
                    'has_password' => !empty($smtp_pass),
                    'smtp_from_email' => $from_email,
                    'smtp_from_name' => $from_name,
                    'smtp_encryption' => $encryption,
                    'email_verification_enabled' => $verification_enabled,
                    'show_forgot_password' => $show_forgot_password
                ]
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveSmtpSettings') {
        try {
            $fields = ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name', 'smtp_encryption', 'email_verification_enabled', 'show_forgot_password'];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $value = trim($_POST[$field]);
                    // Only update username/password if not the placeholder
                    if (($field === 'smtp_username' || $field === 'smtp_password') && $value === '********') {
                        continue;
                    }
                    setSetting($field, $value);
                }
            }

            logActivity($user_id, $username, 'SMTP Updated', 'SMTP settings updated');

            echo json_encode(['success' => true, 'message' => 'SMTP settings saved successfully']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'testSmtpEmail') {
        try {
            $test_email = isset($_POST['test_email']) ? trim($_POST['test_email']) : '';
            if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
                exit();
            }

            $branding = getSiteBranding();
            $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
                . '<div style="background:#001f3f;padding:20px;text-align:center;border-radius:8px 8px 0 0;">'
                . '<h1 style="color:#fff;margin:0;font-size:22px;">' . htmlspecialchars($branding['site_name']) . '</h1>'
                . '</div>'
                . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
                . '<h2 style="color:#333;margin-top:0;">SMTP Test Successful!</h2>'
                . '<p style="color:#666;">If you are reading this email, your SMTP configuration is working correctly.</p>'
                . '<div style="background:#d4edda;padding:15px;border-radius:8px;margin:20px 0;border:1px solid #c3e6cb;">'
                . '<p style="color:#155724;margin:0;font-weight:bold;"><i>&#10004;</i> SMTP Connection Verified</p>'
                . '</div>'
                . '<p style="color:#999;font-size:13px;">Sent from ' . htmlspecialchars($branding['site_name']) . ' admin panel.</p>'
                . '</div></div>';

            $result = sendEmail($test_email, 'SMTP Test - ' . $branding['site_name'], $htmlBody);

            logActivity($user_id, $username, 'SMTP Test', 'Test email sent to ' . $test_email . ': ' . ($result['success'] ? 'Success' : 'Failed'));

            echo json_encode($result);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'clearSmtpCredentials') {
        try {
            $clear_fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name'];
            foreach ($clear_fields as $field) {
                setSetting($field, '');
            }
            setSetting('smtp_port', '587');
            setSetting('smtp_from_name', 'Dashboard System');
            setSetting('smtp_encryption', 'tls');
            setSetting('smtp_enabled', '0');
            setSetting('email_verification_enabled', '0');
            setSetting('show_forgot_password', '1');

            logActivity($user_id, $username, 'SMTP Cleared', 'SMTP credentials cleared');

            echo json_encode(['success' => true, 'message' => 'SMTP credentials cleared successfully']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
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
    <title>SMTP Setup · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           SMTP SETUP - WORLD-CLASS MODERN UI
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

        /* Card */
        .card-modern {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
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
            margin-bottom: 28px;
        }
        .settings-grid-full { grid-template-columns: 1fr; }

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
        .form-group input,
        .form-group select {
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
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 4px var(--accent-blue-bg);
        }
        .form-group input::placeholder {
            color: var(--text-muted);
        }
        .form-group select option {
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
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

        /* Toggle */
        .control-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .control-group:last-child { border-bottom: none; }

        .control-group .control-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .control-group .control-left .control-icon {
            width: 36px; height: 36px;
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            background: var(--bg-input);
            color: var(--text-secondary);
            flex-shrink: 0;
        }
        .control-group .control-left .control-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .control-group .control-left .control-desc {
            font-size: 13px;
            color: var(--text-muted);
        }

        .control-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        .toggle-status {
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .toggle-status .status-dot {
            width: 8px;
            height: 8px;
            border-radius: var(--radius-full);
            display: inline-block;
        }
        .status-dot-enabled { background: var(--accent-green); }
        .status-dot-disabled { background: var(--text-muted); }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            width: 48px;
            height: 26px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-switch .toggle-slider {
            position: absolute;
            inset: 0;
            background: var(--bg-input);
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all var(--transition-fast);
            border: 2px solid var(--border-color);
        }
        .toggle-switch .toggle-slider::before {
            content: '';
            position: absolute;
            height: 18px;
            width: 18px;
            left: 2px;
            bottom: 2px;
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

        /* Status Card */
        .status-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .status-item:last-child { border-bottom: none; }
        .status-item .status-icon {
            width: 32px; height: 32px;
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            background: var(--bg-input);
            color: var(--text-secondary);
            flex-shrink: 0;
        }
        .status-item .status-label {
            font-size: 12px;
            color: var(--text-muted);
        }
        .status-item .status-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-left: auto;
        }
        .status-value .text-success { color: var(--accent-green); }
        .status-value .text-danger { color: var(--accent-red); }
        .status-value .text-muted { color: var(--text-muted); }

        .card-divider {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 16px 0;
        }

        /* Guide Table */
        .guide-table-wrapper {
            overflow-x: auto;
        }
        .guide-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .guide-table thead th {
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
            background: var(--bg-secondary);
            text-align: left;
        }
        body:not(.dark-mode) .guide-table thead th {
            background: #eef2f6;
            color: #475569;
        }
        .guide-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            vertical-align: middle;
        }
        body:not(.dark-mode) .guide-table tbody td {
            color: #475569;
        }
        .guide-table tbody tr:hover td {
            background: var(--bg-card-hover);
        }
        body:not(.dark-mode) .guide-table tbody tr:hover td {
            background: #f8fafc;
        }

        .guide-table .step-num {
            font-weight: 700;
            color: var(--accent-blue);
            width: 40px;
            text-align: center;
        }
        .guide-table .step-name {
            font-weight: 500;
            color: var(--text-primary);
            white-space: nowrap;
        }
        body:not(.dark-mode) .guide-table .step-name {
            color: #0f172a;
        }
        .guide-table .step-section-header td {
            font-weight: 700;
            font-size: 15px;
            color: var(--text-primary);
            padding: 16px 16px 10px;
            background: var(--bg-secondary);
            border-top: 2px solid var(--border-color);
        }
        body:not(.dark-mode) .guide-table .step-section-header td {
            background: #eef2f6;
            color: #0f172a;
        }
        .guide-table .step-section-header td i {
            margin-right: 8px;
        }
        .guide-table .step-final td {
            background: var(--accent-green-bg);
            font-weight: 500;
        }
        body:not(.dark-mode) .guide-table .step-final td {
            background: #ecfdf5;
        }

        /* Info Banner */
        .info-banner {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            background: var(--accent-blue-bg);
            border: 1px solid rgba(59, 130, 246, 0.15);
            margin-top: 16px;
        }
        .info-banner i {
            font-size: 18px;
            color: var(--accent-blue);
            flex-shrink: 0;
            margin-top: 2px;
        }
        .info-banner span {
            font-size: 13px;
            color: var(--text-secondary);
        }
        body:not(.dark-mode) .info-banner span {
            color: #475569;
        }
        .info-banner span strong {
            color: var(--text-primary);
        }
        body:not(.dark-mode) .info-banner span strong {
            color: #0f172a;
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
            .settings-grid { grid-template-columns: 1fr; }
        }
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
            .guide-table { font-size: 13px; }
            .guide-table tbody td { padding: 10px 12px; }
            .guide-table .step-name { white-space: normal; }
            .settings-grid { gap: 16px; }
        }
        @media (max-width: 480px) {
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .control-group { flex-direction: column; align-items: stretch; }
            .control-toggle { margin-left: 0; justify-content: space-between; }
            .guide-table .step-num { width: 30px; }
            .guide-table tbody td { padding: 8px 10px; font-size: 12px; }
            .info-banner { flex-direction: column; align-items: center; text-align: center; }
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
                <span class="current">SMTP Setup</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-envelope"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>SMTP Email Setup</h1>
                        <p class="subtitle">
                            Configure email sending for OTP verification and password reset
                            <span class="badge-beta">v2.0</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadSmtpSettings()" title="Refresh">
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

            <!-- SMTP Content -->
            <div id="smtpContent" class="initially-hidden">

                <!-- Control Toggles Card -->
                <div class="card-modern mb-28">
                    <div class="card-header">
                        <div class="header-icon header-icon-blue"><i class="fas fa-power-off"></i></div>
                        <div>
                            <div class="header-title">SMTP Service Controls</div>
                            <div class="header-subtitle">Enable or disable SMTP email functionality and related features</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- SMTP Enabled -->
                        <div class="control-group">
                            <div class="control-left">
                                <div class="control-icon"><i class="fas fa-envelope"></i></div>
                                <div>
                                    <div class="control-title">SMTP Email Service</div>
                                    <div class="control-desc">Enable SMTP for sending OTP verification &amp; password reset emails</div>
                                </div>
                            </div>
                            <div class="control-toggle">
                                <div class="toggle-status">
                                    <span class="status-dot status-dot-disabled" id="smtpDot"></span>
                                    <span id="smtpStatusText">Disabled</span>
                                </div>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="smtpEnabled">
                                    <label for="smtpEnabled" class="toggle-slider"></label>
                                </div>
                            </div>
                        </div>

                        <!-- Email Verification -->
                        <div class="control-group">
                            <div class="control-left">
                                <div class="control-icon"><i class="fas fa-shield-alt"></i></div>
                                <div>
                                    <div class="control-title">Email Verification on Signup</div>
                                    <div class="control-desc">Require OTP email verification when users sign up manually</div>
                                </div>
                            </div>
                            <div class="control-toggle">
                                <div class="toggle-status">
                                    <span class="status-dot status-dot-disabled" id="verificationDot"></span>
                                    <span id="verificationStatusText">Disabled</span>
                                </div>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="emailVerificationEnabled">
                                    <label for="emailVerificationEnabled" class="toggle-slider"></label>
                                </div>
                            </div>
                        </div>

                        <!-- Forgot Password -->
                        <div class="control-group">
                            <div class="control-left">
                                <div class="control-icon"><i class="fas fa-key"></i></div>
                                <div>
                                    <div class="control-title">Forgot Password on Login</div>
                                    <div class="control-desc">Show the "Forgot Password?" link on the login page</div>
                                </div>
                            </div>
                            <div class="control-toggle">
                                <div class="toggle-status">
                                    <span class="status-dot status-dot-disabled" id="forgotDot"></span>
                                    <span id="forgotStatusText">Disabled</span>
                                </div>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="showForgotPassword">
                                    <label for="showForgotPassword" class="toggle-slider"></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid: Credentials + Status -->
                <div class="settings-grid">

                    <!-- SMTP Credentials Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-purple"><i class="fas fa-key"></i></div>
                            <div>
                                <div class="header-title">SMTP Credentials</div>
                                <div class="header-subtitle">Enter your SMTP server details (hidden on screen)</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label><i class="fas fa-server"></i> SMTP Host</label>
                                <input type="password" id="smtpHost" placeholder="e.g. smtp.gmail.com">
                            </div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label><i class="fas fa-hashtag"></i> Port</label>
                                    <input type="password" id="smtpPort" placeholder="587">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-lock"></i> Encryption</label>
                                    <select id="smtpEncryption">
                                        <option value="tls">TLS (Port 587)</option>
                                        <option value="ssl">SSL (Port 465)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> SMTP Username</label>
                                <input type="password" id="smtpUsername" placeholder="e.g. your@gmail.com">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> SMTP Password</label>
                                <input type="password" id="smtpPassword" placeholder="App password or email password">
                            </div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label><i class="fas fa-at"></i> From Email</label>
                                    <input type="password" id="smtpFromEmail" placeholder="noreply@yourdomain.com">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-tag"></i> From Name</label>
                                    <input type="text" id="smtpFromName" placeholder="Dashboard System">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-success" onclick="saveSmtpSettings()">
                                    <i class="fas fa-save"></i> Save Settings
                                </button>
                                <button class="btn btn-ghost" onclick="clearSmtpCredentials()">
                                    <i class="fas fa-trash-alt"></i> Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Connection Status Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-green"><i class="fas fa-plug"></i></div>
                            <div>
                                <div class="header-title">Connection Status</div>
                                <div class="header-subtitle">Test your SMTP configuration</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="status-item">
                                <div class="status-icon"><i class="fas fa-server"></i></div>
                                <div class="status-label">SMTP Host</div>
                                <div class="status-value" id="statusHost">Not configured</div>
                            </div>
                            <div class="status-item">
                                <div class="status-icon"><i class="fas fa-user-check"></i></div>
                                <div class="status-label">Credentials</div>
                                <div class="status-value" id="statusCredentials">Not set</div>
                            </div>
                            <div class="status-item">
                                <div class="status-icon"><i class="fas fa-lock"></i></div>
                                <div class="status-label">Encryption</div>
                                <div class="status-value" id="statusEncryption">TLS</div>
                            </div>

                            <hr class="card-divider">

                            <div class="form-group" style="margin-bottom:12px;">
                                <label><i class="fas fa-paper-plane"></i> Send Test Email</label>
                                <input type="email" id="testEmail" placeholder="Enter email to receive test">
                            </div>
                            <button class="btn btn-primary btn-block" onclick="sendTestEmail()">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step-by-Step Guide -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-amber"><i class="fas fa-book"></i></div>
                        <div>
                            <div class="header-title">SMTP Setup Guide</div>
                            <div class="header-subtitle">Step-by-step instructions for Gmail &amp; Hostinger SMTP</div>
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
                                    <tr class="step-section-header">
                                        <td colspan="3">
                                            <i class="fab fa-google" style="color:#ea4335;"></i> Gmail SMTP Setup (Free - 500 emails/day)
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">1</td>
                                        <td class="step-name">Open Google Account</td>
                                        <td>Go to <strong>myaccount.google.com</strong> and sign in with your Gmail</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">2</td>
                                        <td class="step-name">Go to Security</td>
                                        <td>Click <strong>Security</strong> in the left sidebar</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">3</td>
                                        <td class="step-name">Enable 2-Step Verification</td>
                                        <td>Under "Signing in to Google", enable <strong>2-Step Verification</strong> (required for App Passwords)</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">4</td>
                                        <td class="step-name">Create App Password</td>
                                        <td>Go to <strong>Security &rarr; 2-Step Verification &rarr; App passwords</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">5</td>
                                        <td class="step-name">Generate Password</td>
                                        <td>Enter app name (e.g. "Dashboard SMTP"), click <strong>Create</strong>. Copy the <strong>16-character password</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">6</td>
                                        <td class="step-name">Enter Gmail SMTP Settings</td>
                                        <td>
                                            <strong>Host:</strong> smtp.gmail.com<br>
                                            <strong>Port:</strong> 587<br>
                                            <strong>Encryption:</strong> TLS<br>
                                            <strong>Username:</strong> your@gmail.com<br>
                                            <strong>Password:</strong> 16-char App Password<br>
                                            <strong>From Email:</strong> your@gmail.com
                                        </td>
                                    </tr>
                                    <tr class="step-section-header">
                                        <td colspan="3">
                                            <i class="fas fa-globe"></i> Hostinger SMTP Setup
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">7</td>
                                        <td class="step-name">Log in to Hostinger</td>
                                        <td>Go to <strong>hPanel &rarr; Emails &rarr; Email Accounts</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">8</td>
                                        <td class="step-name">Create Email Account</td>
                                        <td>Create an email like <strong>noreply@yourdomain.com</strong> and set a password</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">9</td>
                                        <td class="step-name">Enter Hostinger SMTP Settings</td>
                                        <td>
                                            <strong>Host:</strong> smtp.hostinger.com<br>
                                            <strong>Port:</strong> 465<br>
                                            <strong>Encryption:</strong> SSL<br>
                                            <strong>Username:</strong> noreply@yourdomain.com<br>
                                            <strong>Password:</strong> your email password<br>
                                            <strong>From Email:</strong> noreply@yourdomain.com
                                        </td>
                                    </tr>
                                    <tr class="step-section-header">
                                        <td colspan="3">
                                            <i class="fas fa-check-circle"></i> Final Steps
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">10</td>
                                        <td class="step-name">Save &amp; Test</td>
                                        <td>Click <strong>Save Settings</strong>, then use <strong>Send Test Email</strong> to verify everything works</td>
                                    </tr>
                                    <tr>
                                        <td class="step-num">11</td>
                                        <td class="step-name">Enable SMTP</td>
                                        <td>Toggle <strong>SMTP Email</strong> to ON at the top of this page</td>
                                    </tr>
                                    <tr class="step-final">
                                        <td class="step-num">12</td>
                                        <td class="step-name">Enable Email Verification</td>
                                        <td>Toggle <strong>Email Verification on Signup</strong> to ON to require OTP verification for new manual signups</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="info-banner">
                            <i class="fas fa-magic"></i>
                            <span><strong>How it works:</strong> When SMTP is enabled, the system can send OTP verification emails on signup and password reset emails. Gmail allows <strong>500 free emails/day</strong>.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- CSRF Token for AJAX Requests -->
    <script>
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo generateCSRFToken(); ?>');
        }
    });
    </script>

    <script>
    /**
     * SMTP Setup - World-Class JavaScript
     */
    $(document).ready(function() {
        loadSmtpSettings();
        setupToggleListeners();
    });

    function setupToggleListeners() {
        $('#smtpEnabled').on('change', function() {
            updateToggleStatus('smtp', this.checked);
        });
        $('#emailVerificationEnabled').on('change', function() {
            updateToggleStatus('verification', this.checked);
        });
        $('#showForgotPassword').on('change', function() {
            updateToggleStatus('forgot', this.checked);
        });
    }

    function updateToggleStatus(type, isEnabled) {
        const dotMap = {
            'smtp': 'smtpDot',
            'verification': 'verificationDot',
            'forgot': 'forgotDot'
        };
        const textMap = {
            'smtp': 'smtpStatusText',
            'verification': 'verificationStatusText',
            'forgot': 'forgotStatusText'
        };

        const dotEl = document.getElementById(dotMap[type]);
        const textEl = document.getElementById(textMap[type]);

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

    function loadSmtpSettings() {
        $('#loadingSkeleton').show();
        $('#smtpContent').hide();

        $.ajax({
            url: '', method: 'POST',
            data: { action: 'getSmtpSettings' },
            dataType: 'json',
            success: function(response) {
                setTimeout(() => {
                    $('#loadingSkeleton').hide();
                    $('#smtpContent').fadeIn(300);
                }, 400);

                if (response.success) {
                    const d = response.data;

                    // Toggles
                    $('#smtpEnabled').prop('checked', d.smtp_enabled === '1');
                    updateToggleStatus('smtp', d.smtp_enabled === '1');
                    $('#emailVerificationEnabled').prop('checked', d.email_verification_enabled === '1');
                    updateToggleStatus('verification', d.email_verification_enabled === '1');
                    $('#showForgotPassword').prop('checked', d.show_forgot_password === '1');
                    updateToggleStatus('forgot', d.show_forgot_password === '1');

                    // Credentials (show host/port/from for convenience, mask sensitive)
                    if (d.smtp_host) $('#smtpHost').val(d.smtp_host);
                    if (d.smtp_port) $('#smtpPort').val(d.smtp_port);
                    $('#smtpEncryption').val(d.smtp_encryption || 'tls');
                    if (d.has_username) $('#smtpUsername').val('********');
                    if (d.has_password) $('#smtpPassword').val('********');
                    if (d.smtp_from_email) $('#smtpFromEmail').val(d.smtp_from_email);
                    if (d.smtp_from_name) $('#smtpFromName').val(d.smtp_from_name);

                    // Status card
                    $('#statusHost').text(d.smtp_host || 'Not configured');
                    $('#statusCredentials').html(d.has_username && d.has_password
                        ? '<span class="text-success">Configured</span>'
                        : '<span class="text-danger">Not set</span>');
                    $('#statusEncryption').text((d.smtp_encryption || 'tls').toUpperCase() + ' (Port ' + (d.smtp_port || '587') + ')');
                }
            },
            error: function() {
                $('#loadingSkeleton').hide();
                $('#smtpContent').show();
            }
        });
    }

    function saveSmtpSettings() {
        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '', method: 'POST',
            data: {
                action: 'saveSmtpSettings',
                smtp_enabled: $('#smtpEnabled').is(':checked') ? '1' : '0',
                smtp_host: $('#smtpHost').val(),
                smtp_port: $('#smtpPort').val(),
                smtp_username: $('#smtpUsername').val(),
                smtp_password: $('#smtpPassword').val(),
                smtp_from_email: $('#smtpFromEmail').val(),
                smtp_from_name: $('#smtpFromName').val(),
                smtp_encryption: $('#smtpEncryption').val(),
                email_verification_enabled: $('#emailVerificationEnabled').is(':checked') ? '1' : '0',
                show_forgot_password: $('#showForgotPassword').is(':checked') ? '1' : '0'
            },
            dataType: 'json',
            success: function(response) {
                Swal.close();
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Saved!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                    loadSmtpSettings();
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
                    text: 'Failed to save settings',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    function sendTestEmail() {
        const email = $('#testEmail').val().trim();
        if (!email) {
            Swal.fire({
                icon: 'warning',
                title: 'Enter Email',
                text: 'Please enter an email address to send the test to.',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            });
            return;
        }

        Swal.fire({
            title: 'Sending Test Email...',
            text: 'This may take a few seconds',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '', method: 'POST',
            data: { action: 'testSmtpEmail', test_email: email },
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                Swal.close();
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Test Email Sent!',
                        text: 'Check your inbox at ' + email,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
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
                    text: 'Request timed out. Check your SMTP settings.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    function clearSmtpCredentials() {
        Swal.fire({
            title: 'Clear SMTP Credentials?',
            text: 'This will remove all SMTP settings and disable email.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Clear All',
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
                    url: '', method: 'POST',
                    data: { action: 'clearSmtpCredentials' },
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Cleared!',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            });
                            loadSmtpSettings();
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
                            text: 'Failed to clear settings',
                            background: 'var(--bg-card)',
                            color: 'var(--text-primary)'
                        });
                    }
                });
            }
        });
    }

    // Keyboard shortcut: Ctrl+S to save
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveSmtpSettings();
        }
    });

    console.log('🚀 SMTP Setup page loaded successfully');
    console.log('⌨️ Shortcut: Ctrl+S to save settings');
    </script>
</body>
</html>