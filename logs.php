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
$current_page = 'logs';

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'getLogs') {
    header('Content-Type: application/json');
    enforceCSRF();

    try {
        $conn = getDBConnection();

        // Admin sees all logs, regular users see only their own
        // FIXED: Use direct queries instead of prepared statements
        if ($role === 'Admin') {
            $result = $conn->query("SELECT * FROM activity_logs ORDER BY timestamp DESC");
        } else {
            $result = $conn->query("SELECT * FROM activity_logs WHERE user_id = " . (int)$user_id . " ORDER BY timestamp DESC");
        }

        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }

        $logs = [];

        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'action' => $row['action'],
                'details' => $row['details'],
                'ip_address' => $row['ip_address'],
                'timestamp' => date('M d, Y H:i:s', strtotime($row['timestamp'])),
                'timestamp_iso' => $row['timestamp']
            ];
        }

        $result->free();

        // Auto-cleanup after fetching logs
        autoCleanupPreparedStatements();

        echo json_encode(['success' => true, 'data' => $logs]);
        exit();

    } catch (Exception $e) {
        error_log("Logs.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading logs: ' . $e->getMessage()]);
        exit();
    }
}

// AJAX: Get log stats
if (isset($_GET['action']) && $_GET['action'] === 'getLogStats') {
    header('Content-Type: application/json');
    enforceCSRF();

    try {
        $conn = getDBConnection();
        $stats = [];

        // Total logs
        if ($role === 'Admin') {
            $result = $conn->query("SELECT COUNT(*) as total FROM activity_logs");
        } else {
            $result = $conn->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = " . (int)$user_id);
        }
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['total'] = (int)$row['total'];
            $result->free();
        }

        // Today's logs
        if ($role === 'Admin') {
            $result = $conn->query("SELECT COUNT(*) as total FROM activity_logs WHERE DATE(timestamp) = CURDATE()");
        } else {
            $result = $conn->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = " . (int)$user_id . " AND DATE(timestamp) = CURDATE()");
        }
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['today'] = (int)$row['total'];
            $result->free();
        }

        // This week's logs
        if ($role === 'Admin') {
            $result = $conn->query("SELECT COUNT(*) as total FROM activity_logs WHERE YEARWEEK(timestamp) = YEARWEEK(CURDATE())");
        } else {
            $result = $conn->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = " . (int)$user_id . " AND YEARWEEK(timestamp) = YEARWEEK(CURDATE())");
        }
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['week'] = (int)$row['total'];
            $result->free();
        }

        // Unique actions
        if ($role === 'Admin') {
            $result = $conn->query("SELECT COUNT(DISTINCT action) as total FROM activity_logs");
        } else {
            $result = $conn->query("SELECT COUNT(DISTINCT action) as total FROM activity_logs WHERE user_id = " . (int)$user_id);
        }
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['unique_actions'] = (int)$row['total'];
            $result->free();
        }

        echo json_encode(['success' => true, 'data' => $stats]);
        exit();

    } catch (Exception $e) {
        error_log("Log stats error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading stats']);
        exit();
    }
}

// If we reach here, render the HTML page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Activity Logs · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           ACTIVITY LOGS - WORLD-CLASS MODERN UI
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
            background: linear-gradient(135deg, var(--accent-amber-bg), rgba(245, 158, 11, 0.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: var(--accent-amber);
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

        /* Stats Grid */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 16px; margin-bottom: 28px;
        }
        .stat-card {
            background: var(--bg-card); border-radius: var(--radius-lg);
            padding: 20px 24px; border: 1px solid var(--border-color);
            transition: all var(--transition-normal); position: relative; overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 2.5px; background: linear-gradient(90deg, var(--accent-amber), var(--accent-blue));
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
        .stat-card:hover .stat-icon { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .stat-card .stat-value { font-size: 32px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.025em; line-height: 1.2; }

        /* Filters Bar */
        .filters-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px 20px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            transition: all var(--transition-normal);
        }
        .filters-bar:hover { border-color: var(--border-hover); }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filters-bar .filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .filters-bar .filter-group label i {
            margin-right: 4px;
        }
        .filters-bar .filter-group input,
        .filters-bar .filter-group select {
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 13px;
            font-family: inherit;
            transition: all var(--transition-fast);
            min-width: 130px;
        }
        .filters-bar .filter-group input:focus,
        .filters-bar .filter-group select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-bg);
        }
        .filters-bar .filter-group input[type="date"] {
            min-width: 140px;
        }
        .filters-bar .filter-group select option {
            background: var(--bg-card);
            color: var(--text-primary);
        }
        .filters-bar .filter-actions {
            display: flex;
            gap: 6px;
            margin-left: auto;
        }

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
            display: flex;
            align-items: center;
            justify-content: space-between;
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

        .table-scroll-hint {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 8px 16px; font-size: 12px; color: var(--text-muted);
            background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            animation: hintPulse 2.5s ease-in-out infinite;
        }
        @keyframes hintPulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }

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
            background: var(--accent-amber); color: #fff; border-radius: var(--radius-sm);
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--accent-amber); color: #fff;
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
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 2px solid var(--border-color);
            background: var(--bg-card) !important;
            text-align: left;
            color: var(--text-secondary) !important;
        }
        /* Light mode header - VISIBLE */
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

        /* Action Badge */
        .action-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 500;
        }
        .action-badge-login { background: var(--accent-green-bg); color: var(--accent-green); }
        .action-badge-logout { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .action-badge-create { background: var(--accent-purple-bg); color: var(--accent-purple); }
        .action-badge-update { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .action-badge-delete { background: var(--accent-red-bg); color: var(--accent-red); }
        .action-badge-default { background: var(--bg-input); color: var(--text-muted); }

        /* Skeleton */
        .skeleton-card { background: var(--bg-card); border-radius: var(--radius-lg); padding: 20px 24px; border: 1px solid var(--border-color); }
        .skeleton { background: var(--bg-input); border-radius: var(--radius-sm); animation: skeletonPulse 1.5s ease-in-out infinite; }
        .skeleton-text { height: 16px; width: 60%; margin: 8px 0; }
        .skeleton-text-large { height: 24px; width: 40%; }
        .skeleton-w-50 { width: 50%; }
        .skeleton-w-70 { width: 70%; }
        .skeleton-mb-md { margin-bottom: 16px; }
        .skeleton-table { margin-top: 12px; }
        .skeleton-table-row { display: flex; gap: 12px; margin-bottom: 8px; }
        .skeleton-table-cell { height: 32px; border-radius: var(--radius-sm); }
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
            .filters-bar { flex-direction: column; align-items: stretch; gap: 10px; padding: 14px 16px; }
            .filters-bar .filter-group { flex-wrap: wrap; }
            .filters-bar .filter-group input,
            .filters-bar .filter-group select { min-width: 100%; flex: 1; }
            .filters-bar .filter-actions { margin-left: 0; justify-content: stretch; }
            .filters-bar .filter-actions .btn { flex: 1; justify-content: center; }
            .table-toolbar { flex-direction: column; align-items: stretch; gap: 10px; padding: 14px 16px; }
            .table-toolbar .right { flex-wrap: wrap; }
            .table-toolbar .right .btn .label { display: none; }
            .table-wrapper .dataTables_wrapper { padding: 0 14px 16px; }
            .table-wrapper table.dataTable { font-size: 13px; }
            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td { padding: 10px 10px; }
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

        <!-- Main Content -->
        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-modern">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                <span class="current">Activity Logs</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-history"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>Activity Logs</h1>
                        <p class="subtitle">
                            <?php if ($role === 'Admin'): ?>
                            Complete audit trail of all system activity
                            <?php else: ?>
                            Your personal activity history
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadLogs()" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Activities</span>
                        <span class="stat-icon"><i class="fas fa-list"></i></span>
                    </div>
                    <div class="stat-value" id="totalActivities">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Today</span>
                        <span class="stat-icon"><i class="fas fa-calendar-day"></i></span>
                    </div>
                    <div class="stat-value" id="todayActivities">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">This Week</span>
                        <span class="stat-icon"><i class="fas fa-calendar-week"></i></span>
                    </div>
                    <div class="stat-value" id="weekActivities">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Unique Actions</span>
                        <span class="stat-icon"><i class="fas fa-tags"></i></span>
                    </div>
                    <div class="stat-value" id="uniqueActions">0</div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="filters-bar" id="filtersSection">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> From</label>
                    <input type="date" id="filterDateFrom">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> To</label>
                    <input type="date" id="filterDateTo">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-tag"></i> Action</label>
                    <select id="filterAction">
                        <option value="">All Actions</option>
                    </select>
                </div>
                <?php if ($role === 'Admin'): ?>
                <div class="filter-group">
                    <label><i class="fas fa-user"></i> User</label>
                    <select id="filterUsername">
                        <option value="">All Users</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-actions">
                    <button class="btn btn-ghost btn-sm" onclick="clearFilters()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="left">
                        <h3><i class="fas fa-table"></i> Activity History</h3>
                        <span class="count-badge" id="logCountBadge">0 logs</span>
                    </div>
                    <div class="right">
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

                <div class="table-scroll-hint">
                    <i class="fas fa-arrows-left-right"></i> Scroll to see all columns
                </div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table" style="padding:16px 24px;">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- DataTable -->
                <div id="tableContainer" class="initially-hidden">
                    <div class="table-responsive" style="overflow-x:auto;max-width:100%;">
                        <table id="logsTable" class="display" style="width:100%;">
                            <thead><tr><th>#</th></tr></thead>
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

    <!-- CSRF Token -->
    <script>
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo generateCSRFToken(); ?>');
        }
    });
    </script>

    <script>
    /* Developed by Rameez Scripts */

    let logsTable;
    let logsData = [];
    let uniqueActions = [];
    let uniqueUsernames = [];
    const isAdmin = <?php echo $role === 'Admin' ? 'true' : 'false'; ?>;

    // ==========================================
    // INIT
    // ==========================================
    $(document).ready(function() {
        loadLogs();
        loadStats();
    });

    // ==========================================
    // LOAD STATS
    // ==========================================
    function loadStats() {
        $.ajax({
            url: '?action=getLogStats',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    document.getElementById('totalActivities').textContent = response.data.total || 0;
                    document.getElementById('todayActivities').textContent = response.data.today || 0;
                    document.getElementById('weekActivities').textContent = response.data.week || 0;
                    document.getElementById('uniqueActions').textContent = response.data.unique_actions || 0;
                }
            },
            error: function() {
                console.log('Failed to load stats');
            }
        });
    }

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
    // GET ACTION BADGE
    // ==========================================
    function getActionBadge(action) {
        var cls = 'action-badge-default';
        var lower = (action || '').toLowerCase();
        if (lower.includes('login')) cls = 'action-badge-login';
        else if (lower.includes('logout')) cls = 'action-badge-logout';
        else if (lower.includes('create') || lower.includes('added')) cls = 'action-badge-create';
        else if (lower.includes('update') || lower.includes('edit') || lower.includes('updated')) cls = 'action-badge-update';
        else if (lower.includes('delete') || lower.includes('deleted')) cls = 'action-badge-delete';
        return '<span class="action-badge ' + cls + '">' + escapeHtml(action) + '</span>';
    }

    // ==========================================
    // LOAD LOGS
    // ==========================================
    function loadLogs() {
        $('#loadingSkeleton').show();
        $('#tableContainer').hide();

        $.ajax({
            url: '?action=getLogs',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    logsData = response.data;

                    // Extract unique values for filters
                    uniqueActions = [...new Set(response.data.map(r => r.action).filter(Boolean))];
                    uniqueUsernames = [...new Set(response.data.map(r => r.username).filter(Boolean))];

                    updateStats(response.data);
                    populateFilters();
                    initLogsTable(response.data);

                    $('#loadingSkeleton').hide();
                    $('#tableContainer').show();
                } else {
                    $('#loadingSkeleton').hide();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to load logs',
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                }
            },
            error: function() {
                $('#loadingSkeleton').hide();
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
    // UPDATE STATS
    // ==========================================
    function updateStats(data) {
        const total = data.length;
        const today = new Date();
        const todayStr = today.toISOString().split('T')[0];

        // Today
        const todayCount = data.filter(function(r) {
            return r.timestamp_iso && r.timestamp_iso.startsWith(todayStr);
        }).length;

        // This week
        const weekStart = new Date(today);
        weekStart.setDate(weekStart.getDate() - weekStart.getDay());
        const weekStartStr = weekStart.toISOString().split('T')[0];
        const weekCount = data.filter(function(r) {
            return r.timestamp_iso && r.timestamp_iso >= weekStartStr;
        }).length;

        // Unique actions
        const actions = new Set(data.map(function(r) { return r.action; }).filter(Boolean));

        document.getElementById('totalActivities').textContent = total;
        document.getElementById('todayActivities').textContent = todayCount;
        document.getElementById('weekActivities').textContent = weekCount;
        document.getElementById('uniqueActions').textContent = actions.size;
        document.getElementById('logCountBadge').textContent = total + ' log' + (total !== 1 ? 's' : '');
    }

    // ==========================================
    // POPULATE FILTERS
    // ==========================================
    function populateFilters() {
        // Action filter
        const actionSelect = document.getElementById('filterAction');
        actionSelect.innerHTML = '<option value="">All Actions</option>';
        uniqueActions.forEach(function(action) {
            const option = document.createElement('option');
            option.value = action;
            option.textContent = action;
            actionSelect.appendChild(option);
        });

        // Username filter (Admin only)
        if (isAdmin) {
            const usernameSelect = document.getElementById('filterUsername');
            usernameSelect.innerHTML = '<option value="">All Users</option>';
            uniqueUsernames.forEach(function(username) {
                const option = document.createElement('option');
                option.value = username;
                option.textContent = username;
                usernameSelect.appendChild(option);
            });
        }
    }

    // ==========================================
    // INIT TABLE
    // ==========================================
    function initLogsTable(data) {
        if (logsTable) {
            logsTable.destroy();
            $('#logsTable').empty();
        }

        var columns = [
            {
                data: null,
                title: '#',
                width: '45px',
                render: function(d, t, r, m) { return m.row + 1; },
                className: 'dt-center'
            },
            {
                data: 'username',
                title: 'User',
                render: function(d) {
                    return '<span style="font-weight:500;">' + escapeHtml(d) + '</span>';
                }
            },
            {
                data: 'action',
                title: 'Action',
                render: function(d) {
                    return getActionBadge(d);
                }
            },
            {
                data: 'details',
                title: 'Details',
                render: function(d) {
                    if (!d) return '<span style="color:var(--text-muted);font-size:13px;">—</span>';
                    return escapeHtml(d.length > 80 ? d.substring(0, 80) + '...' : d);
                }
            },
            {
                data: 'ip_address',
                title: 'IP Address',
                render: function(d) {
                    return renderIP(d);
                }
            },
            {
                data: 'timestamp',
                title: 'Date/Time',
                className: 'dt-nowrap',
                width: '140px'
            }
        ];

        var theadHtml = '<tr>';
        columns.forEach(function(col) { theadHtml += '<th>' + (col.title || '') + '</th>'; });
        theadHtml += '</tr>';
        $('#logsTable thead').html(theadHtml);
        $('#logsTable tbody').html('');

        logsTable = $('#logsTable').DataTable({
            data: data,
            destroy: true,
            columns: columns,
            pageLength: 15,
            lengthMenu: [[10, 15, 25, 50, 100, -1], [10, 15, 25, 50, 100, "All"]],
            responsive: true,
            autoWidth: false,
            order: [[0, 'desc']],
            columnDefs: [{ targets: 0, orderable: false }],
            dom: '<"top"lf>rt<"bottom"ip>',
            language: {
                emptyTable: '<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:40px;margin-bottom:10px;display:block;opacity:0.5;"></i>No activity logs found</div>',
                info: 'Showing _START_ to _END_ of _TOTAL_ logs',
                infoEmpty: 'No logs available',
                infoFiltered: '(filtered from _MAX_ total logs)'
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

        // Apply filter listeners
        $('#filterDateFrom, #filterDateTo, #filterAction, #filterUsername').on('change', function() {
            applyFilters();
        });
    }

    // ==========================================
    // EXPORT DATA
    // ==========================================
    function exportData(type) {
        if (!logsTable) {
            Swal.fire({ icon: 'warning', title: 'Table not loaded', text: 'Please wait for logs to load.' });
            return;
        }
        try {
            var buttons = logsTable.buttons();
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
    // APPLY FILTERS
    // ==========================================
    function applyFilters() {
        if (!logsTable) return;

        $.fn.dataTable.ext.search = [];

        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo = document.getElementById('filterDateTo').value;
        const action = document.getElementById('filterAction').value;
        const username = isAdmin ? document.getElementById('filterUsername').value : '';

        // Date range filter
        if (dateFrom || dateTo) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                const timestamp = logsData[dataIndex]?.timestamp_iso;
                if (!timestamp) return true;

                const recordDate = new Date(timestamp);
                const fromDate = dateFrom ? new Date(dateFrom) : null;
                const toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;

                if (fromDate && recordDate < fromDate) return false;
                if (toDate && recordDate > toDate) return false;
                return true;
            });
        }

        // Column filters
        logsTable.columns().search('');
        if (action) logsTable.column(2).search(action);
        if (isAdmin && username) logsTable.column(1).search(username);

        logsTable.draw();
    }

    // ==========================================
    // CLEAR FILTERS
    // ==========================================
    function clearFilters() {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterAction').value = '';
        if (isAdmin) document.getElementById('filterUsername').value = '';

        if (logsTable) {
            $.fn.dataTable.ext.search = [];
            logsTable.columns().search('').draw();
        }
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

    // Keyboard shortcut: Ctrl+R to refresh
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            loadLogs();
            loadStats();
        }
    });

    console.log('🚀 Activity Logs page loaded successfully');
    console.log('⌨️ Shortcut: Ctrl+R to refresh');
    </script>
</body>
</html>