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
$current_page = 'dashboard';

// Handle AJAX requests for dashboard stats
if (isset($_GET['action']) && $_GET['action'] === 'getDashboardStats') {
    header('Content-Type: application/json');
    enforceCSRF();

    if ($role !== 'Admin') {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    $conn = getDBConnection();

    // Total users
    $result = $conn->query("SELECT COUNT(*) as total FROM users");
    $totalUsers = $result->fetch_assoc()['total'];
    $result->free();

    // Admin users
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'Admin'");
    $totalAdmins = $result->fetch_assoc()['total'];
    $result->free();

    // Regular users
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'User'");
    $totalRegularUsers = $result->fetch_assoc()['total'];
    $result->free();

    // Total forms
    $result = $conn->query("SELECT COUNT(*) as total FROM crud_forms WHERE status = 'active'");
    $totalForms = $result->fetch_assoc()['total'];
    $result->free();

    // Total submissions
    $result = $conn->query("SELECT COUNT(*) as total FROM crud_data");
    $totalSubmissions = $result->fetch_assoc()['total'];
    $result->free();

    // Today's submissions
    $result = $conn->query("SELECT COUNT(*) as total FROM crud_data WHERE DATE(created_at) = CURDATE()");
    $todaySubmissions = $result->fetch_assoc()['total'];
    $result->free();

    // Active sessions
    cleanExpiredSessions(SESSION_TIMEOUT);
    $result = $conn->query("SELECT COUNT(*) as total FROM user_sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL " . SESSION_TIMEOUT . " SECOND)");
    $activeSessions = $result->fetch_assoc()['total'];
    $result->free();

    echo json_encode([
        'success' => true,
        'data' => [
            'totalUsers' => $totalUsers,
            'totalAdmins' => $totalAdmins,
            'totalRegularUsers' => $totalRegularUsers,
            'totalForms' => $totalForms,
            'totalSubmissions' => $totalSubmissions,
            'todaySubmissions' => $todaySubmissions,
            'activeSessions' => $activeSessions
        ]
    ]);
    exit();
}

// AJAX: Get recent activities
if (isset($_GET['action']) && $_GET['action'] === 'getRecentActivities') {
    header('Content-Type: application/json');
    enforceCSRF();

    try {
        if ($role === 'Admin') {
            $logs = getActivityLogs(null, 'Admin', 10);
        } else {
            $logs = getActivityLogs($user_id, 'User', 10);
        }

        echo json_encode(['success' => true, 'data' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error loading activities']);
    }
    exit();
}

// AJAX: Get chart data
if (isset($_GET['action']) && $_GET['action'] === 'getChartData') {
    ob_start();
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();
        $data = [];

        // 1. Line Chart - Submission Trends (Last 30 days)
        $trend_data = ['labels' => [], 'values' => []];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            // Use direct query instead of prepared statement
            $result = $conn->query("SELECT COUNT(*) as count FROM crud_data WHERE DATE(created_at) = '$date'");
            if ($result) {
                $row = $result->fetch_assoc();
                $trend_data['labels'][] = date('M d', strtotime($date));
                $trend_data['values'][] = (int)$row['count'];
                $result->free();
            } else {
                $trend_data['labels'][] = date('M d', strtotime($date));
                $trend_data['values'][] = 0;
            }
        }
        $data['trend'] = $trend_data;

        // 2. Bar Chart - Top Forms by Submissions
        $top_forms = ['labels' => [], 'values' => []];
        $result = $conn->query("SELECT f.form_name, COUNT(d.id) as count FROM crud_forms f LEFT JOIN crud_data d ON f.id = d.form_id WHERE f.status = 'active' GROUP BY f.id ORDER BY count DESC LIMIT 8");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $top_forms['labels'][] = $row['form_name'];
                $top_forms['values'][] = (int)$row['count'];
            }
            $result->free();
        }
        $data['top_forms'] = $top_forms;

        // 3. Pie Chart - Form Categories (Access Types)
        $categories = ['labels' => [], 'values' => []];
        $result = $conn->query("SELECT access_type, COUNT(*) as count FROM crud_forms WHERE status = 'active' GROUP BY access_type");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $label = ucfirst($row['access_type']);
                $categories['labels'][] = $label;
                $categories['values'][] = (int)$row['count'];
            }
            $result->free();
        }
        $data['categories'] = $categories;

        // 4. Doughnut Chart - User Roles
        $roles = ['labels' => [], 'values' => []];
        $result = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $roles['labels'][] = $row['role'];
                $roles['values'][] = (int)$row['count'];
            }
            $result->free();
        }
        $data['roles'] = $roles;

        // 5. Area Chart - Growth Over Time (Cumulative submissions)
        $growth_data = ['labels' => [], 'values' => []];
        $cumulative = 0;
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $result = $conn->query("SELECT COUNT(*) as count FROM crud_data WHERE DATE(created_at) = '$date'");
            if ($result) {
                $row = $result->fetch_assoc();
                $cumulative += (int)$row['count'];
                $result->free();
            }
            $growth_data['labels'][] = date('M d', strtotime($date));
            $growth_data['values'][] = $cumulative;
        }
        $data['growth'] = $growth_data;

        ob_end_clean();
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        ob_end_clean();
        error_log("Chart data error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading chart data: ' . $e->getMessage()]);
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
    <title>Dashboard · ASK US</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           DASHBOARD - WORLD-CLASS MODERN UI
           ============================================================ */

        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #111118;
            --bg-card: #16161f;
            --bg-card-hover: #1c1c28;
            --bg-input: #1a1a26;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            border: 1px solid var(--border-color);
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
            cursor: default;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2.5px;
            background: linear-gradient(90deg, var(--accent-blue), var(--accent-purple));
            opacity: 0;
            transition: opacity var(--transition-normal);
        }
        .stat-card:hover {
            border-color: var(--border-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .stat-card:hover::before { opacity: 1; }
        .stat-card .stat-top {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 6px;
        }
        .stat-card .stat-label {
            font-size: 13px; font-weight: 500;
            color: var(--text-secondary);
            letter-spacing: 0.01em;
        }
        .stat-card .stat-icon {
            width: 38px; height: 38px;
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            background: var(--bg-input);
            color: var(--text-secondary);
            transition: all var(--transition-fast);
        }
        .stat-card:hover .stat-icon {
            background: var(--accent-blue-bg);
            color: var(--accent-blue);
        }
        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.025em;
            line-height: 1.2;
        }
        .stat-card .stat-change {
            font-size: 12px;
            font-weight: 500;
            margin-top: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            background: var(--accent-green-bg);
            color: var(--accent-green);
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        .chart-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            border: 1px solid var(--border-color);
            transition: all var(--transition-normal);
        }
        .chart-card:hover { border-color: var(--border-hover); }
        .chart-card .chart-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .chart-card .chart-header h4 {
            font-size: 15px; font-weight: 600;
            color: var(--text-primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-card .chart-header h4 i { color: var(--accent-purple); }
        .chart-card .chart-header .chart-badge {
            font-size: 10px;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            background: var(--bg-input);
            color: var(--text-muted);
        }
        .chart-card .chart-wrapper {
            position: relative;
            height: 220px;
        }
        .chart-card .chart-wrapper canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* Activities Table */
        .activities-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
            margin-bottom: 28px;
        }
        .activities-card:hover { border-color: var(--border-hover); }
        .activities-card .card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 12px;
        }
        .activities-card .card-header .header-left {
            display: flex; align-items: center; gap: 14px;
        }
        .activities-card .card-header .header-left .header-icon {
            width: 40px; height: 40px;
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            background: var(--accent-amber-bg);
            color: var(--accent-amber);
            flex-shrink: 0;
        }
        .activities-card .card-header .header-title {
            font-size: 16px; font-weight: 600; color: var(--text-primary);
        }
        .activities-card .card-header .header-subtitle {
            font-size: 13px; color: var(--text-muted);
        }
        .activities-card .card-body { padding: 0; }
        .activities-table-wrapper { overflow-x: auto; }
        .activities-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .activities-table thead th {
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-secondary);
            text-align: left;
        }
        body:not(.dark-mode) .activities-table thead th {
            background: #eef2f6;
            color: #475569;
        }
        .activities-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            vertical-align: middle;
        }
        body:not(.dark-mode) .activities-table tbody td {
            color: #475569;
        }
        .activities-table tbody tr:hover td {
            background: var(--bg-card-hover);
        }
        body:not(.dark-mode) .activities-table tbody tr:hover td {
            background: #f8fafc;
        }
        .activities-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Action Badges */
        .action-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 500;
        }
        .action-badge-success {
            background: var(--accent-green-bg);
            color: var(--accent-green);
        }
        .action-badge-danger {
            background: var(--accent-red-bg);
            color: var(--accent-red);
        }
        .action-badge-warning {
            background: var(--accent-amber-bg);
            color: var(--accent-amber);
        }
        .action-badge-default {
            background: var(--bg-input);
            color: var(--text-muted);
        }
        .action-badge-info {
            background: var(--accent-blue-bg);
            color: var(--accent-blue);
        }

        /* IP Mask */
        .ip-mask-wrap {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: monospace;
            font-size: 12px;
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
            background: none; border: none;
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

        /* Activity Detail */
        .activity-detail {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Loading & Empty States */
        .activities-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: var(--text-muted);
            gap: 12px;
        }
        .activities-loading i { font-size: 32px; opacity: 0.5; }
        .activities-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: var(--text-muted);
            gap: 8px;
        }
        .activities-empty i { font-size: 40px; opacity: 0.3; }

        /* Skeleton */
        .skeleton-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            border: 1px solid var(--border-color);
        }
        .skeleton {
            background: var(--bg-input);
            border-radius: var(--radius-sm);
            animation: skeletonPulse 1.5s ease-in-out infinite;
        }
        .skeleton-text { height: 16px; width: 60%; margin: 8px 0; }
        .skeleton-text-large { height: 24px; width: 40%; }
        .skeleton-w-50 { width: 50%; }
        .skeleton-w-60 { width: 60%; }
        .skeleton-w-70 { width: 70%; }
        .skeleton-w-80 { width: 80%; }
        .skeleton-mb-md { margin-bottom: 16px; }
        .skeleton-icon { width: 40px; height: 40px; border-radius: var(--radius-md); margin-bottom: 12px; }
        @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }

        .initially-hidden { display: none; }

        /* Welcome Card for Non-Admins */
        .welcome-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 32px 36px;
            text-align: center;
            margin-bottom: 28px;
        }
        .welcome-card .welcome-icon {
            font-size: 48px;
            color: var(--accent-blue);
            margin-bottom: 16px;
        }
        .welcome-card h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 8px;
        }
        .welcome-card p {
            font-size: 15px;
            color: var(--text-secondary);
            margin: 0;
        }

        /* Chart Loading */
        .chart-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 200px;
            color: var(--text-muted);
        }
        .chart-loading i { font-size: 28px; margin-bottom: 8px; }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 16px 18px; }
            .stat-card .stat-value { font-size: 22px; }
            .activities-card .card-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .activities-table { font-size: 13px; }
            .activities-table thead th,
            .activities-table tbody td { padding: 8px 10px; }
            .welcome-card { padding: 24px 20px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .stat-value { font-size: 18px; }
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .activities-table thead th,
            .activities-table tbody td { padding: 6px 8px; font-size: 12px; }
            .activity-detail { max-width: 120px; }
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
                <span class="current">Overview</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-chart-line"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>Dashboard</h1>
                        <p class="subtitle">
                            Welcome back, <?php echo htmlspecialchars($username); ?>!
                            <span class="badge-beta">v3.0</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="refreshDashboard()" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <?php if ($role === 'Admin'): ?>
            <!-- Stats -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Users</span>
                        <span class="stat-icon"><i class="fas fa-users"></i></span>
                    </div>
                    <div class="stat-value" id="totalUsers">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Active Sessions</span>
                        <span class="stat-icon"><i class="fas fa-desktop"></i></span>
                    </div>
                    <div class="stat-value" id="activeSessions">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Forms</span>
                        <span class="stat-icon"><i class="fas fa-table"></i></span>
                    </div>
                    <div class="stat-value" id="totalForms">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Submissions</span>
                        <span class="stat-icon"><i class="fas fa-database"></i></span>
                    </div>
                    <div class="stat-value" id="totalSubmissions">0</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid" id="chartsGrid">
                <!-- Line Chart - Submission Trends -->
                <div class="chart-card" id="chartTrend">
                    <div class="chart-header">
                        <h4><i class="fas fa-chart-line"></i> Submission Trends</h4>
                        <span class="chart-badge">30 Days</span>
                    </div>
                    <div class="chart-wrapper">
                        <div id="trendLoading" class="chart-loading">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Loading chart...</span>
                        </div>
                        <canvas id="trendChart" style="display:none;"></canvas>
                    </div>
                </div>

                <!-- Bar Chart - Top Forms -->
                <div class="chart-card" id="chartTopForms">
                    <div class="chart-header">
                        <h4><i class="fas fa-chart-bar"></i> Top Forms</h4>
                        <span class="chart-badge">By Submissions</span>
                    </div>
                    <div class="chart-wrapper">
                        <div id="topFormsLoading" class="chart-loading">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Loading chart...</span>
                        </div>
                        <canvas id="topFormsChart" style="display:none;"></canvas>
                    </div>
                </div>

                <!-- Pie Chart - Form Categories -->
                <div class="chart-card" id="chartCategories">
                    <div class="chart-header">
                        <h4><i class="fas fa-chart-pie"></i> Form Categories</h4>
                        <span class="chart-badge">Access Types</span>
                    </div>
                    <div class="chart-wrapper">
                        <div id="categoriesLoading" class="chart-loading">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Loading chart...</span>
                        </div>
                        <canvas id="categoriesChart" style="display:none;"></canvas>
                    </div>
                </div>

                <!-- Doughnut Chart - User Roles -->
                <div class="chart-card" id="chartRoles">
                    <div class="chart-header">
                        <h4><i class="fas fa-chart-pie"></i> User Roles</h4>
                        <span class="chart-badge">Distribution</span>
                    </div>
                    <div class="chart-wrapper">
                        <div id="rolesLoading" class="chart-loading">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Loading chart...</span>
                        </div>
                        <canvas id="rolesChart" style="display:none;"></canvas>
                    </div>
                </div>
            </div>

            <!-- Area Chart - Growth Over Time (Full Width) -->
            <div class="chart-card" id="chartGrowth" style="margin-bottom:28px;">
                <div class="chart-header">
                    <h4><i class="fas fa-chart-area"></i> Growth Over Time</h4>
                    <span class="chart-badge">Cumulative Submissions</span>
                </div>
                <div class="chart-wrapper" style="height:200px;">
                    <div id="growthLoading" class="chart-loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <span>Loading chart...</span>
                    </div>
                    <canvas id="growthChart" style="display:none;"></canvas>
                </div>
            </div>

            <?php else: ?>
            <!-- Welcome Card for Non-Admins -->
            <div class="welcome-card">
                <div class="welcome-icon"><i class="fas fa-user-circle"></i></div>
                <h2>Welcome, <?php echo htmlspecialchars($username); ?>!</h2>
                <p>You are logged in as <strong><?php echo htmlspecialchars($role); ?></strong>. Use the sidebar to navigate through your available features.</p>
            </div>
            <?php endif; ?>

            <!-- Recent Activities -->
            <div class="activities-card">
                <div class="card-header">
                    <div class="header-left">
                        <div class="header-icon"><i class="fas fa-history"></i></div>
                        <div>
                            <div class="header-title">Recent Activities</div>
                            <div class="header-subtitle">Latest system activities</div>
                        </div>
                    </div>
                    <span class="chart-badge" style="font-size:11px;padding:4px 14px;border-radius:var(--radius-full);background:var(--bg-input);color:var(--text-muted);">
                        <i class="fas fa-clock"></i> Last 10 logs
                    </span>
                </div>
                <div class="card-body">
                    <div id="activitiesLoading" class="activities-loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading activities...</p>
                    </div>
                    <div id="activitiesContent" class="initially-hidden">
                        <div class="activities-table-wrapper">
                            <table class="activities-table">
                                <thead>
                                    <tr>
                                        <?php if ($role === 'Admin'): ?>
                                        <th><i class="fas fa-user"></i> User</th>
                                        <?php endif; ?>
                                        <th><i class="fas fa-bolt"></i> Action</th>
                                        <th><i class="fas fa-info-circle"></i> Details</th>
                                        <th><i class="fas fa-globe"></i> IP Address</th>
                                        <th><i class="fas fa-clock"></i> Time</th>
                                    </tr>
                                </thead>
                                <tbody id="activitiesTableBody">
                                </tbody>
                            </table>
                        </div>
                        <div id="noActivities" class="activities-empty initially-hidden">
                            <i class="fas fa-inbox"></i>
                            <p>No recent activities found</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script>
    /* Developed by Muhafiz Ahmed */

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

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==========================================
    // REFRESH DASHBOARD
    // ==========================================
    function refreshDashboard() {
        <?php if ($role === 'Admin'): ?>
        loadDashboardStats();
        loadChartData();
        <?php endif; ?>
        loadRecentActivities();
        
        // Show refresh feedback
        var refreshBtn = document.querySelector('.page-header-right .btn i');
        if (refreshBtn) {
            refreshBtn.classList.add('fa-spin');
            setTimeout(function() {
                refreshBtn.classList.remove('fa-spin');
            }, 1500);
        }
    }

    <?php if ($role === 'Admin'): ?>
    // ==========================================
    // LOAD DASHBOARD STATS
    // ==========================================
    function loadDashboardStats() {
        $.ajax({
            url: '?action=getDashboardStats',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    document.getElementById('totalUsers').textContent = response.data.totalUsers;
                    document.getElementById('activeSessions').textContent = response.data.activeSessions;
                    document.getElementById('totalForms').textContent = response.data.totalForms;
                    document.getElementById('totalSubmissions').textContent = response.data.totalSubmissions;
                }
            },
            error: function() {
                console.log('Failed to load dashboard stats');
            }
        });
    }

    // ==========================================
    // LOAD CHART DATA
    // ==========================================
    var chartInstances = {};
    var CHART_COLORS = [
        '#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#ef4444',
        '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#8b5cf6'
    ];

    function getChartColors(count) {
        var colors = [];
        for (var i = 0; i < count; i++) colors.push(CHART_COLORS[i % CHART_COLORS.length]);
        return colors;
    }

    function getChartColorsAlpha(count, alpha) {
        return getChartColors(count).map(function(hex) {
            var r = parseInt(hex.slice(1,3), 16);
            var g = parseInt(hex.slice(3,5), 16);
            var b = parseInt(hex.slice(5,7), 16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
        });
    }

    function showChartLoading(chartId) {
        var wrapper = document.querySelector('#' + chartId + ' .chart-wrapper');
        if (wrapper) {
            var loading = wrapper.querySelector('.chart-loading');
            var canvas = wrapper.querySelector('canvas');
            if (loading) loading.style.display = 'flex';
            if (canvas) canvas.style.display = 'none';
        }
    }

    function hideChartLoading(chartId) {
        var wrapper = document.querySelector('#' + chartId + ' .chart-wrapper');
        if (wrapper) {
            var loading = wrapper.querySelector('.chart-loading');
            var canvas = wrapper.querySelector('canvas');
            if (loading) loading.style.display = 'none';
            if (canvas) canvas.style.display = 'block';
        }
    }

    function loadChartData() {
        // Show loading for all charts
        showChartLoading('chartTrend');
        showChartLoading('chartTopForms');
        showChartLoading('chartCategories');
        showChartLoading('chartRoles');
        showChartLoading('chartGrowth');

        $.ajax({
            url: '?action=getChartData',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var data = response.data;
                    renderCharts(data);
                } else {
                    console.error('Chart data error:', response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('Failed to load chart data:', error);
                // Hide loading and show error message
                showChartError('chartTrend');
                showChartError('chartTopForms');
                showChartError('chartCategories');
                showChartError('chartRoles');
                showChartError('chartGrowth');
            }
        });
    }

    function showChartError(chartId) {
        var wrapper = document.querySelector('#' + chartId + ' .chart-wrapper');
        if (wrapper) {
            var loading = wrapper.querySelector('.chart-loading');
            if (loading) {
                loading.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--accent-red);"></i><span style="color:var(--accent-red);">Failed to load chart</span>';
            }
        }
    }

    function renderCharts(data) {
        var textColor = getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim() || '#f1f5f9';
        var mutedColor = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#94a3b8';
        var borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim() || 'rgba(255,255,255,0.06)';

        // Destroy existing charts
        for (var key in chartInstances) {
            if (chartInstances[key]) {
                chartInstances[key].destroy();
                delete chartInstances[key];
            }
        }

        var chartCount = 0;

        // 1. Line Chart - Submission Trends
        if (data.trend && data.trend.labels && data.trend.labels.length > 0) {
            hideChartLoading('chartTrend');
            var ctx = document.getElementById('trendChart').getContext('2d');
            chartInstances.trend = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.trend.labels,
                    datasets: [{
                        label: 'Submissions',
                        data: data.trend.values,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3,
                        pointBackgroundColor: '#3b82f6',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            cornerRadius: 6,
                            padding: 10
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: mutedColor, precision: 0 },
                            grid: { color: borderColor }
                        },
                        x: {
                            ticks: { color: mutedColor, maxTicksLimit: 15 },
                            grid: { display: false }
                        }
                    }
                }
            });
            chartCount++;
        }

        // 2. Bar Chart - Top Forms
        if (data.top_forms && data.top_forms.labels && data.top_forms.labels.length > 0) {
            hideChartLoading('chartTopForms');
            var ctx = document.getElementById('topFormsChart').getContext('2d');
            var colors = getChartColorsAlpha(data.top_forms.labels.length, 0.7);
            var borderColors = getChartColors(data.top_forms.labels.length);
            chartInstances.topForms = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.top_forms.labels,
                    datasets: [{
                        label: 'Submissions',
                        data: data.top_forms.values,
                        backgroundColor: colors,
                        borderColor: borderColors,
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            cornerRadius: 6,
                            padding: 10
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: mutedColor, precision: 0 },
                            grid: { color: borderColor }
                        },
                        x: {
                            ticks: { color: mutedColor },
                            grid: { display: false }
                        }
                    }
                }
            });
            chartCount++;
        }

        // 3. Pie Chart - Form Categories
        if (data.categories && data.categories.labels && data.categories.labels.length > 0) {
            hideChartLoading('chartCategories');
            var ctx = document.getElementById('categoriesChart').getContext('2d');
            var colors = getChartColors(data.categories.labels.length);
            chartInstances.categories = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: data.categories.labels,
                    datasets: [{
                        data: data.categories.values,
                        backgroundColor: colors,
                        borderColor: 'var(--bg-card)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: textColor, padding: 12, font: { size: 12 } }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            cornerRadius: 6,
                            padding: 10
                        }
                    }
                }
            });
            chartCount++;
        }

        // 4. Doughnut Chart - User Roles
        if (data.roles && data.roles.labels && data.roles.labels.length > 0) {
            hideChartLoading('chartRoles');
            var ctx = document.getElementById('rolesChart').getContext('2d');
            var colors = ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b'];
            chartInstances.roles = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.roles.labels,
                    datasets: [{
                        data: data.roles.values,
                        backgroundColor: colors.slice(0, data.roles.labels.length),
                        borderColor: 'var(--bg-card)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: textColor, padding: 12, font: { size: 12 } }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            cornerRadius: 6,
                            padding: 10
                        }
                    }
                }
            });
            chartCount++;
        }

        // 5. Area Chart - Growth Over Time
        if (data.growth && data.growth.labels && data.growth.labels.length > 0) {
            hideChartLoading('chartGrowth');
            var ctx = document.getElementById('growthChart').getContext('2d');
            chartInstances.growth = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.growth.labels,
                    datasets: [{
                        label: 'Cumulative Submissions',
                        data: data.growth.values,
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.15)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        pointBackgroundColor: '#8b5cf6',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            cornerRadius: 6,
                            padding: 10
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: mutedColor, precision: 0 },
                            grid: { color: borderColor }
                        },
                        x: {
                            ticks: { color: mutedColor, maxTicksLimit: 15 },
                            grid: { display: false }
                        }
                    }
                }
            });
            chartCount++;
        }

        // If no charts were rendered, show message
        if (chartCount === 0) {
            console.warn('No chart data available to render');
        }
    }
    <?php endif; ?>

    // ==========================================
    // LOAD RECENT ACTIVITIES
    // ==========================================
    function loadRecentActivities() {
        $('#activitiesLoading').show();
        $('#activitiesContent').hide();

        $.ajax({
            url: '?action=getRecentActivities',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#activitiesLoading').hide();
                $('#activitiesContent').show();

                if (response.success && response.data.length > 0) {
                    const tbody = document.getElementById('activitiesTableBody');
                    tbody.innerHTML = '';

                    response.data.forEach(function(log) {
                        const time = new Date(log.timestamp);
                        const timeStr = time.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                            + ' ' + time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

                        let badgeClass = 'action-badge-default';
                        const actionLower = log.action.toLowerCase();
                        if (actionLower.includes('login') || actionLower.includes('signup')) {
                            badgeClass = 'action-badge-success';
                        } else if (actionLower.includes('delete') || actionLower.includes('logout')) {
                            badgeClass = 'action-badge-danger';
                        } else if (actionLower.includes('update') || actionLower.includes('change') || actionLower.includes('edit')) {
                            badgeClass = 'action-badge-warning';
                        } else if (actionLower.includes('create') || actionLower.includes('add')) {
                            badgeClass = 'action-badge-info';
                        }

                        let row = '<tr>';
                        <?php if ($role === 'Admin'): ?>
                        row += '<td><strong>' + escapeHtml(log.username) + '</strong></td>';
                        <?php endif; ?>
                        row += '<td><span class="action-badge ' + badgeClass + '">' + escapeHtml(log.action) + '</span></td>';
                        row += '<td class="activity-detail" title="' + escapeHtml(log.details || '-') + '">' + escapeHtml(log.details || '-') + '</td>';
                        row += '<td>' + renderIP(log.ip_address) + '</td>';
                        row += '<td style="font-size:13px;color:var(--text-muted);">' + timeStr + '</td>';
                        row += '</tr>';

                        tbody.innerHTML += row;
                    });
                } else {
                    $('#noActivities').show();
                }
            },
            error: function() {
                $('#activitiesLoading').hide();
                $('#activitiesContent').show();
                $('#noActivities').show();
            }
        });
    }

    // ==========================================
    // AUTO-REFRESH
    // ==========================================
    var refreshInterval = null;

    $(document).ready(function() {
        <?php if ($role === 'Admin'): ?>
        loadDashboardStats();
        // Load charts after a small delay to ensure DOM is ready
        setTimeout(function() {
            loadChartData();
        }, 300);
        <?php endif; ?>
        loadRecentActivities();

        // Auto-refresh every 60 seconds
        refreshInterval = setInterval(function() {
            <?php if ($role === 'Admin'): ?>
            loadDashboardStats();
            <?php endif; ?>
            loadRecentActivities();
        }, 60000);

        // Keyboard shortcut: Ctrl+R to refresh
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                refreshDashboard();
            }
        });

        console.log('🚀 Dashboard loaded successfully');
        console.log('⌨️ Shortcut: Ctrl+R to refresh');
        console.log('🔄 Auto-refresh every 60 seconds');
    });
    </script>
</body>
</html>