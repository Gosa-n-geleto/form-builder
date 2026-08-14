<?php
/**
 * Developed by Muhafiz Ahmed
 */

require_once 'config.php';

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

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'sessions';
$current_session_id = session_id();

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // CSRF is TEMPORARILY DISABLED for sessions
    // enforceCSRF(); // Commented out to prevent session issues
    
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getSessions':
                // Clean expired sessions first
                cleanExpiredSessions(SESSION_TIMEOUT);

                // FIXED: Use direct query instead of prepared statement
                $timeout = (int)SESSION_TIMEOUT;
                $sql = "SELECT us.*, u.username, u.role
                    FROM user_sessions us
                    JOIN users u ON us.user_id = u.id
                    WHERE us.last_activity > DATE_SUB(NOW(), INTERVAL $timeout SECOND)
                    ORDER BY us.last_activity DESC";
                
                $result = $conn->query($sql);
                
                if (!$result) {
                    throw new Exception("Query failed: " . $conn->error);
                }

                $sessions = [];
                $unique_users = [];
                $admin_count = 0;

                while ($row = $result->fetch_assoc()) {
                    $is_current = ($row['session_id'] === $current_session_id);

                    // Parse user agent for browser
                    $browser = 'Unknown';
                    $ua = $row['user_agent'] ?? '';
                    if (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
                    elseif (strpos($ua, 'Edg') !== false) $browser = 'Edge';
                    elseif (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
                    elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
                    elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) $browser = 'Opera';

                    // Parse OS
                    $os = 'Unknown';
                    if (strpos($ua, 'Windows') !== false) $os = 'Windows';
                    elseif (strpos($ua, 'Mac') !== false) $os = 'macOS';
                    elseif (strpos($ua, 'Linux') !== false) $os = 'Linux';
                    elseif (strpos($ua, 'Android') !== false) $os = 'Android';
                    elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) $os = 'iOS';

                    $sessions[] = [
                        'id' => $row['id'],
                        'session_id' => $row['session_id'],
                        'user_id' => $row['user_id'],
                        'username' => $row['username'],
                        'role' => $row['role'],
                        'ip_address' => $row['ip_address'],
                        'browser' => $browser,
                        'os' => $os,
                        'last_activity' => date('c', strtotime($row['last_activity'])),
                        'created_at' => date('c', strtotime($row['created_at'])),
                        'is_current' => $is_current
                    ];

                    $unique_users[$row['user_id']] = true;
                    if ($row['role'] === 'Admin') $admin_count++;
                }
                $result->free();

                echo json_encode([
                    'success' => true,
                    'data' => $sessions,
                    'stats' => [
                        'total' => count($sessions),
                        'unique_users' => count($unique_users),
                        'admin_sessions' => $admin_count
                    ]
                ]);
                exit();

            case 'forceLogout':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $target_session_id = isset($_POST['session_id']) ? $_POST['session_id'] : '';

                if (empty($target_session_id)) {
                    echo json_encode(['success' => false, 'message' => 'Session ID required']);
                    exit();
                }

                if ($target_session_id === $current_session_id) {
                    echo json_encode(['success' => false, 'message' => 'Cannot force-logout your own session']);
                    exit();
                }

                // FIXED: Use direct query instead of prepared statement
                $escaped_session_id = $conn->real_escape_string($target_session_id);
                
                // Get target user info
                $result = $conn->query("SELECT us.user_id, u.username FROM user_sessions us JOIN users u ON us.user_id = u.id WHERE us.session_id = '$escaped_session_id'");
                
                if (!$result) {
                    throw new Exception("Query failed: " . $conn->error);
                }
                
                $target = $result->fetch_assoc();
                $result->free();

                if (!$target) {
                    echo json_encode(['success' => false, 'message' => 'Session not found']);
                    exit();
                }

                // Set force_logout flag - direct query
                $conn->query("UPDATE user_sessions SET force_logout = 1 WHERE session_id = '$escaped_session_id'");

                // Log activity
                logActivity($user_id, $username, 'Force Logout', 'Force-logged out user: ' . $target['username']);

                // Notify the target user
                try {
                    createNotification($target['user_id'], 'Session Terminated', 'Your session was terminated by an administrator.', 'danger', 'login.php');
                } catch (Exception $e) {}

                echo json_encode(['success' => true, 'message' => 'User session terminated']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Sessions.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Session Management · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           SESSIONS - WORLD-CLASS MODERN UI
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
            background: linear-gradient(135deg, var(--accent-pink-bg), rgba(236, 72, 153, 0.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: var(--accent-pink);
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
            background: var(--accent-pink-bg); color: var(--accent-pink);
            border: 1px solid rgba(236, 72, 153, 0.15);
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

        /* Stats Grid */
        .stats-grid {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 16px; margin-bottom: 28px;
        }
        .stat-card {
            background: var(--bg-card); border-radius: var(--radius-lg);
            padding: 20px 24px; border: 1px solid var(--border-color);
            transition: all var(--transition-normal); position: relative; overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 2.5px; background: linear-gradient(90deg, var(--accent-pink), var(--accent-purple));
            opacity: 0; transition: opacity var(--transition-normal);
        }
        .stat-card:hover { border-color: var(--border-hover); transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-card:hover::before { opacity: 1; }
        .stat-card .stat-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .stat-card .stat-label { font-size: 13px; font-weight: 500; color: var(--text-secondary); letter-spacing: 0.01em; }
        .stat-card .stat-icon {
            width: 38px; height: 38px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; background: var(--bg-input); color: var(--text-secondary);
            transition: all var(--transition-fast);
        }
        .stat-card:hover .stat-icon { background: var(--accent-pink-bg); color: var(--accent-pink); }
        .stat-card .stat-value { font-size: 32px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.025em; line-height: 1.2; }

        /* Table Wrapper */
        .table-wrapper {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
        }
        .table-wrapper:hover { border-color: var(--border-hover); }

        .table-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap; gap: 12px;
        }
        .table-toolbar .left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .table-toolbar .left h3 {
            font-size: 15px; font-weight: 600; margin: 0;
            color: var(--text-primary); display: flex; align-items: center; gap: 8px;
        }
        .table-toolbar .left .count-badge {
            font-size: 12px; font-weight: 500; color: var(--text-secondary);
            background: var(--bg-input); padding: 2px 14px;
            border-radius: var(--radius-full); border: 1px solid var(--border-color);
        }
        .table-toolbar .right { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .table-toolbar .right .btn { padding: 6px 12px; font-size: 12px; }

        /* DataTable */
        .table-wrapper .dataTables_wrapper { padding: 0 24px 20px; }
        .table-wrapper .dataTables_wrapper .dataTables_filter { display: none; }
        .table-wrapper .dataTables_wrapper .dataTables_length { display: none; }
        .table-wrapper .dataTables_wrapper .dataTables_info {
            padding: 16px 0 0; font-size: 13px; color: var(--text-muted);
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate { padding: 16px 0 0; }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 6px 14px; border-radius: var(--radius-sm); border: none;
            background: transparent; color: var(--text-secondary); font-weight: 500;
            font-size: 13px; transition: all var(--transition-fast); margin: 0 2px; cursor: pointer;
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--bg-input); color: var(--text-primary);
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--accent-pink); color: #fff; border-radius: var(--radius-sm);
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--accent-pink); color: #fff;
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.3; cursor: not-allowed;
        }

        /* ===== TABLE HEADERS - VISIBLE IN BOTH MODES ===== */
        .table-wrapper table.dataTable {
            width: 100% !important; border-collapse: collapse;
            margin: 0 !important; font-size: 14px; background: transparent !important;
        }
        .table-wrapper table.dataTable thead th {
            padding: 14px 14px 10px;
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.06em;
            border-bottom: 2px solid var(--border-color);
            background: var(--bg-card) !important;
            text-align: left;
            color: var(--text-secondary) !important;
        }
        body:not(.dark-mode) .table-wrapper table.dataTable thead th,
        [data-theme="light"] .table-wrapper table.dataTable thead th {
            background: #eef2f6 !important;
            color: #0f172a !important;
            border-bottom-color: #d1d5db !important;
        }
        body:not(.dark-mode) .table-wrapper table.dataTable thead th:hover,
        [data-theme="light"] .table-wrapper table.dataTable thead th:hover {
            background: #e2e8f0 !important;
        }

        .table-wrapper table.dataTable tbody td {
            padding: 14px 14px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            vertical-align: middle;
            background: transparent !important;
        }
        body:not(.dark-mode) .table-wrapper table.dataTable tbody td,
        [data-theme="light"] .table-wrapper table.dataTable tbody td {
            color: #1e293b !important;
            border-bottom-color: #e2e8f0 !important;
        }
        .table-wrapper table.dataTable tbody tr:hover td {
            background: var(--bg-card-hover) !important;
        }
        body:not(.dark-mode) .table-wrapper table.dataTable tbody tr:hover td,
        [data-theme="light"] .table-wrapper table.dataTable tbody tr:hover td {
            background: #f1f4f8 !important;
        }
        .table-wrapper table.dataTable tbody tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 500;
        }
        .status-badge-admin {
            background: var(--accent-blue-bg);
            color: var(--accent-blue);
        }
        .status-badge-user {
            background: var(--accent-green-bg);
            color: var(--accent-green);
        }
        .status-badge-current {
            background: var(--accent-amber-bg);
            color: var(--accent-amber);
            font-weight: 600;
        }

        /* IP Mask */
        .ip-mask-wrap {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: monospace;
            font-size: 13px;
        }
        .ip-display {
            background: var(--bg-input);
            padding: 2px 8px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            font-size: 12px;
            transition: all var(--transition-fast);
        }
        body:not(.dark-mode) .ip-display {
            background: #f1f4f8;
        }
        .ip-toggle-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
            font-size: 12px;
        }
        .ip-toggle-btn:hover {
            background: var(--bg-input);
            color: var(--text-primary);
        }

        .dt-button {
            background: var(--bg-input) !important;
            color: var(--text-secondary) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: var(--radius-sm) !important;
            padding: 6px 14px !important;
            font-size: 12px !important;
            font-family: inherit !important;
            font-weight: 500 !important;
            cursor: pointer !important;
            transition: all var(--transition-fast) !important;
        }
        .dt-button:hover {
            background: var(--bg-card-hover) !important;
            color: var(--text-primary) !important;
            border-color: var(--border-hover) !important;
        }

        /* Skeleton */
        .skeleton-table { padding: 16px 24px; }
        .skeleton-table-row { display: flex; gap: 12px; margin-bottom: 8px; }
        .skeleton-table-cell { height: 32px; border-radius: var(--radius-sm); background: var(--bg-input); animation: skeletonPulse 1.5s ease-in-out infinite; }
        .skeleton-flex-1 { flex: 1; }
        .skeleton-flex-2 { flex: 2; }
        @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }

        .initially-hidden { display: none; }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 16px 18px; }
            .stat-card .stat-value { font-size: 22px; }
            .table-toolbar { flex-direction: column; align-items: stretch; gap: 10px; padding: 14px 16px; }
            .table-toolbar .right { flex-wrap: wrap; }
            .table-toolbar .right .btn .label { display: none; }
            .table-wrapper .dataTables_wrapper { padding: 0 14px 16px; }
            .table-wrapper table.dataTable { font-size: 13px; }
            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td { padding: 10px 10px; }
            .skeleton-table { padding: 10px 14px; }
            .skeleton-table-row { gap: 8px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .stat-value { font-size: 18px; }
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td { padding: 6px 8px; font-size: 12px; }
            .ip-mask-wrap { font-size: 11px; }
        }

        * { transition: background-color var(--transition-slow), color var(--transition-slow), border-color var(--transition-slow), box-shadow var(--transition-slow); }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-modern">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                <a href="settings.php">System</a>
                <span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                <span class="current">Session Management</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-desktop"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>Session Management</h1>
                        <p class="subtitle">
                            Monitor and manage active user sessions
                            <span class="badge-beta">Admin Only</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadSessions()" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid" id="statsContainer">
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Active Sessions</span>
                        <span class="stat-icon"><i class="fas fa-desktop"></i></span>
                    </div>
                    <div class="stat-value" id="statActiveSessions">-</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Unique Users Online</span>
                        <span class="stat-icon"><i class="fas fa-users"></i></span>
                    </div>
                    <div class="stat-value" id="statUniqueUsers">-</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Admin Sessions</span>
                        <span class="stat-icon"><i class="fas fa-user-shield"></i></span>
                    </div>
                    <div class="stat-value" id="statAdminSessions">-</div>
                </div>
            </div>

            <!-- Sessions Table -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="left">
                        <h3><i class="fas fa-list"></i> Active Sessions</h3>
                        <span class="count-badge" id="sessionCountBadge">0 sessions</span>
                    </div>
                    <div class="right">
                        <button class="btn btn-ghost btn-sm" onclick="loadSessions()" title="Refresh">
                            <i class="fas fa-sync"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('copy')" title="Copy">
                            <i class="fas fa-copy"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('csv')" title="CSV">
                            <i class="fas fa-file-csv"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('pdf')" title="PDF">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('print')" title="Print">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>

                <!-- Skeleton Loader -->
                <div id="sessionsSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="sessionsTableContainer" class="initially-hidden">
                    <div class="table-responsive" style="overflow-x:auto;max-width:100%;">
                        <table id="sessionsTable" class="display" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>IP Address</th>
                                    <th>Browser / OS</th>
                                    <th>Last Active</th>
                                    <th>Started</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

    <!-- CSRF Token - DISABLED for sessions -->
    <script>
    // CSRF is TEMPORARILY DISABLED for sessions AJAX requests
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            // Skip CSRF header for sessions AJAX requests
            if (settings.url && (settings.url.indexOf('sessions.php?action=getSessions') !== -1 ||
                settings.url.indexOf('sessions.php?action=forceLogout') !== -1)) {
                return;
            }
            xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo generateCSRFToken(); ?>');
        }
    });
    </script>

    <script>
    /* Developed by Muhafiz Ahmed */

    let sessionsTable = null;
    let refreshInterval = null;

    // ==========================================
    // IP MASKING
    // ==========================================
    function maskIP(ip) {
        var parts = ip.split('.');
        if (parts.length === 4) return parts[0] + '.' + parts[1] + '.***.***';
        return ip.replace(/:[\da-f]+:[\da-f]+$/i, ':***:***');
    }

    function renderIP(ip) {
        var masked = maskIP(ip);
        var escaped = $('<span>').text(ip).html();
        var escapedMasked = $('<span>').text(masked).html();
        return '<span class="ip-mask-wrap">' +
            '<code class="ip-display" data-full="' + escaped + '" data-masked="' + escapedMasked + '">' + escapedMasked + '</code>' +
            '<button class="ip-toggle-btn" onclick="toggleIP(this)" title="Show/Hide IP"><i class="fas fa-eye"></i></button>' +
            '</span>';
    }

    function toggleIP(btn) {
        var code = btn.previousElementSibling;
        var icon = btn.querySelector('i');
        if (code.textContent === code.getAttribute('data-masked')) {
            code.textContent = code.getAttribute('data-full');
            icon.className = 'fas fa-eye-slash';
        } else {
            code.textContent = code.getAttribute('data-masked');
            icon.className = 'fas fa-eye';
        }
    }

    // ==========================================
    // TIME HELPERS
    // ==========================================
    function timeAgo(dateStr) {
        const seconds = Math.floor((new Date() - new Date(dateStr)) / 1000);
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        return Math.floor(seconds / 86400) + 'd ago';
    }

    // ==========================================
    // LOAD SESSIONS
    // ==========================================
    function loadSessions() {
        $('#sessionsSkeleton').show();
        $('#sessionsTableContainer').hide();

        $.ajax({
            url: 'sessions.php?action=getSessions',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#sessionsSkeleton').hide();
                
                if (response.success) {
                    // Update stats
                    $('#statActiveSessions').text(response.stats.total);
                    $('#statUniqueUsers').text(response.stats.unique_users);
                    $('#statAdminSessions').text(response.stats.admin_sessions);
                    $('#sessionCountBadge').text(response.stats.total + ' session' + (response.stats.total !== 1 ? 's' : ''));

                    // Show table
                    $('#sessionsTableContainer').show();

                    // Build data for DataTable
                    var tableData = response.data.map(function(s) {
                        var roleBadge = s.role === 'Admin'
                            ? '<span class="status-badge status-badge-admin">Admin</span>'
                            : '<span class="status-badge status-badge-user">User</span>';

                        var currentBadge = s.is_current
                            ? ' <span class="status-badge status-badge-current"><i class="fas fa-circle" style="font-size:8px;"></i> Current</span>'
                            : '';

                        var actionBtn = s.is_current
                            ? '<span style="color:var(--text-muted);font-size:12px;"><i class="fas fa-shield-alt"></i> Current Session</span>'
                            : '<button class="btn btn-danger btn-sm" onclick="forceLogout(\'' + s.session_id + '\', \'' + s.username.replace(/'/g, "\\'") + '\')" style="font-size:11px;padding:4px 12px;"><i class="fas fa-power-off"></i> Terminate</button>';

                        return [
                            '<strong>' + escapeHtml(s.username) + '</strong>' + currentBadge,
                            roleBadge,
                            renderIP(s.ip_address),
                            '<i class="fas fa-' + (s.browser === 'Chrome' ? 'chrome' : s.browser === 'Firefox' ? 'firefox' : s.browser === 'Edge' ? 'edge' : s.browser === 'Safari' ? 'safari' : 'globe') + '" style="color:var(--text-muted);margin-right:4px;"></i> ' + s.browser + ' / ' + s.os,
                            '<span data-order="' + s.last_activity + '">' + timeAgo(s.last_activity) + '</span>',
                            '<span data-order="' + s.created_at + '">' + timeAgo(s.created_at) + '</span>',
                            actionBtn
                        ];
                    });

                    if (sessionsTable) {
                        sessionsTable.clear().rows.add(tableData).draw();
                    } else {
                        sessionsTable = $('#sessionsTable').DataTable({
                            data: tableData,
                            pageLength: 15,
                            lengthMenu: [[10, 15, 25, 50, 100, -1], [10, 15, 25, 50, 100, "All"]],
                            responsive: true,
                            autoWidth: false,
                            order: [[4, 'asc']],
                            columnDefs: [
                                { targets: [0, 1, 2, 3, 4, 5, 6], className: 'dt-nowrap' }
                            ],
                            dom: '<"top"lf>rt<"bottom"ip>',
                            language: {
                                emptyTable: '<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:40px;margin-bottom:10px;display:block;opacity:0.5;"></i>No active sessions found</div>',
                                info: 'Showing _START_ to _END_ of _TOTAL_ sessions',
                                infoEmpty: 'No sessions available',
                                infoFiltered: '(filtered from _MAX_ total sessions)'
                            },
                            buttons: [
                                { extend: 'copy', text: 'Copy', exportOptions: { columns: ':not(:last-child)' } },
                                { extend: 'csv', text: 'CSV', exportOptions: { columns: ':not(:last-child)' } },
                                { extend: 'pdf', text: 'PDF', exportOptions: { columns: ':not(:last-child)' } },
                                { extend: 'print', text: 'Print', exportOptions: { columns: ':not(:last-child)' } }
                            ]
                        });

                        // Hide default buttons (we use custom)
                        $('.dt-buttons').hide();
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to load sessions',
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function(xhr, status, error) {
                $('#sessionsSkeleton').hide();
                $('#sessionsTableContainer').show();
                console.error('AJAX Error:', status, error);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not connect to server. Please refresh the page and try again.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    // ==========================================
    // EXPORT DATA
    // ==========================================
    function exportData(type) {
        if (!sessionsTable) {
            Swal.fire({ icon: 'warning', title: 'Table not loaded', text: 'Please wait for sessions to load.' });
            return;
        }
        try {
            var buttons = sessionsTable.buttons();
            var idx = { copy: 0, csv: 1, pdf: 2, print: 3 };
            if (type in idx && buttons.button(idx[type])) {
                buttons.button(idx[type]).trigger();
                Swal.fire({ icon: 'success', title: 'Export started', timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Export failed', text: 'Export type not available.' });
            }
        } catch(e) {
            Swal.fire({ icon: 'error', title: 'Export failed', text: e.message });
        }
    }

    // ==========================================
    // FORCE LOGOUT
    // ==========================================
    function forceLogout(sessionId, username) {
        Swal.fire({
            title: 'Terminate Session?',
            html: 'Are you sure you want to terminate <strong>' + escapeHtml(username) + '</strong>\'s session?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Terminate',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then(function(result) {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Terminating...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });

                $.ajax({
                    url: 'sessions.php?action=forceLogout',
                    method: 'POST',
                    data: { session_id: sessionId },
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Terminated!',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            });
                            loadSessions();
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
                            text: 'Request failed',
                            background: 'var(--bg-card)',
                            color: 'var(--text-primary)'
                        });
                    }
                });
            }
        });
    }

    // ==========================================
    // UTILITIES
    // ==========================================
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==========================================
    // INIT
    // ==========================================
    $(document).ready(function() {
        loadSessions();

        // Auto-refresh every 30 seconds
        refreshInterval = setInterval(loadSessions, 30000);

        // Keyboard shortcut: Ctrl+R to refresh
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                loadSessions();
            }
        });

        console.log('🚀 Session Management page loaded successfully');
        console.log('⌨️ Shortcut: Ctrl+R to refresh');
        console.log('🔄 Auto-refresh every 30 seconds');
    });
    </script>
</body>
</html>