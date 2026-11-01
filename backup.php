<?php
/**
 * Developed by Gosa Negeso
 */

require_once 'config.php';

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
$current_page = 'backup';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    enforceCSRF();

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getBackupInfo':
                $tables = [];
                $totalSize = 0;

                $result = $conn->query("SHOW TABLE STATUS");
                while ($row = $result->fetch_assoc()) {
                    $size = $row['Data_length'] + $row['Index_length'];
                    $tables[] = [
                        'name' => $row['Name'],
                        'rows' => $row['Rows'],
                        'size' => round($size / 1024, 2)
                    ];
                    $totalSize += $size;
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'database' => DB_NAME,
                        'tables' => $tables,
                        'total_tables' => count($tables),
                        'total_size' => round($totalSize / 1024, 2)
                    ]
                ]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle backup download
if (isset($_POST['action']) && $_POST['action'] === 'downloadBackup') {
    enforceCSRF();
    ob_start();
    try {
        $conn = getDBConnection();

        $sql = "-- Database Backup\n";
        $sql .= "-- Database: " . DB_NAME . "\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- By: " . $username . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

        $tables_result = $conn->query("SHOW TABLES");
        while ($table_row = $tables_result->fetch_row()) {
            $table = $table_row[0];

            // Validate table name - only allow alphanumeric and underscores
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) continue;
            $safe_table = $conn->real_escape_string($table);

            // Get CREATE TABLE statement
            $create_result = $conn->query("SHOW CREATE TABLE `" . $safe_table . "`");
            $create_row = $create_result->fetch_row();

            $sql .= "-- Table: " . $safe_table . "\n";
            $sql .= "DROP TABLE IF EXISTS `" . $safe_table . "`;\n";
            $sql .= $create_row[1] . ";\n\n";

            // Get table data
            $data_result = $conn->query("SELECT * FROM `" . $safe_table . "`");
            if ($data_result->num_rows > 0) {
                // Get column names
                $fields = $data_result->fetch_fields();
                $columns = array_map(function($f) { return '`' . $f->name . '`'; }, $fields);
                $column_list = implode(', ', $columns);

                while ($data_row = $data_result->fetch_assoc()) {
                    $values = [];
                    foreach ($data_row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $conn->real_escape_string($value) . "'";
                        }
                    }
                    $sql .= "INSERT INTO `" . $safe_table . "` ($column_list) VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql .= "\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // Log activity
        logActivity($user_id, $username, 'Backup Downloaded', 'Database backup downloaded');

        // Send as download — clear any buffered output first
        ob_end_clean();
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="backup_' . DB_NAME . '_' . date('Y-m-d_His') . '.sql"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit();

    } catch (Exception $e) {
        if (ob_get_level() > 0) ob_end_clean();
        error_log("Backup error: " . $e->getMessage());
        header("Location: backup.php?error=backup_failed");
        exit();
    }
}

// Handle restore
if (isset($_POST['action']) && $_POST['action'] === 'restoreBackup') {
    enforceCSRF();
    header('Content-Type: application/json');

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
        exit();
    }

    $file = $_FILES['backup_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext !== 'sql') {
        echo json_encode(['success' => false, 'message' => 'Only .sql files are allowed']);
        exit();
    }

    if ($file['size'] > 50 * 1024 * 1024) { // 50MB limit
        echo json_encode(['success' => false, 'message' => 'File too large (max 50MB)']);
        exit();
    }

    try {
        $sql_content = file_get_contents($file['tmp_name']);

        if (empty($sql_content)) {
            echo json_encode(['success' => false, 'message' => 'Empty SQL file']);
            exit();
        }

        // Validate SQL content - reject dangerous commands
        $forbidden_patterns = [
            '/\bGRANT\b/i', '/\bREVOKE\b/i', '/\bCREATE\s+USER\b/i', '/\bDROP\s+USER\b/i',
            '/\bDROP\s+DATABASE\b/i', '/\bLOAD_FILE\b/i', '/\bINTO\s+OUTFILE\b/i',
            '/\bINTO\s+DUMPFILE\b/i', '/\bSYSTEM\b/i', '/\bSHUTDOWN\b/i'
        ];
        foreach ($forbidden_patterns as $pattern) {
            if (preg_match($pattern, $sql_content)) {
                echo json_encode(['success' => false, 'message' => 'Backup file contains forbidden SQL commands']);
                exit();
            }
        }

        // Buffer output to prevent PHP warnings from corrupting JSON
        ob_start();

        $conn = getDBConnection();

        // Execute SQL
        if (!$conn->multi_query($sql_content)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'SQL Error: ' . $conn->error]);
            exit();
        }

        // Process all results
        $errors = [];
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
            if ($conn->error) {
                $errors[] = $conn->error;
            }
        } while ($conn->more_results() && $conn->next_result());

        // Check final error too
        if ($conn->error && !in_array($conn->error, $errors)) {
            $errors[] = $conn->error;
        }

        ob_end_clean();

        if (!empty($errors)) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'SQL Errors: ' . implode('; ', array_slice($errors, 0, 3))]);
            exit();
        }

        // Close and re-establish connection after multi_query
        $conn->close();

        // Log activity with fresh connection
        try { logActivity($user_id, $username, 'Backup Restored', 'Database restored from: ' . $file['name']); } catch (Exception $e) {}

        echo json_encode(['success' => true, 'message' => 'Database restored successfully']);
        exit();

    } catch (Exception $e) {
        if (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
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
    <title>Database Backup · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           BACKUP - WORLD-CLASS MODERN UI
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
            display: flex; align-items: center; gap: 8px;
        }
        .page-header-left .title-group .subtitle .badge-beta {
            font-size: 10px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.05em; padding: 2px 10px; border-radius: var(--radius-full);
            background: var(--accent-amber-bg); color: var(--accent-amber);
            border: 1px solid rgba(245, 158, 11, 0.15);
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
        .btn-danger {
            background: linear-gradient(135deg, var(--accent-red), #dc2626);
            color: #fff; border-color: transparent;
        }
        .btn-danger:hover {
            box-shadow: 0 4px 24px rgba(239, 68, 68, 0.35);
            transform: translateY(-1px); color: #fff;
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
        .header-icon-red { background: var(--accent-red-bg); color: var(--accent-red); }
        .header-icon-amber { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .header-icon-purple { background: var(--accent-purple-bg); color: var(--accent-purple); }
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
        .form-group label i {
            margin-right: 6px; color: var(--text-muted); width: 16px;
        }
        .form-group input[type="file"] {
            width: 100%; padding: 10px 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            transition: all var(--transition-fast);
        }
        .form-group input[type="file"]:hover {
            border-color: var(--border-hover);
        }

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
        .info-banner-red {
            background: var(--accent-red-bg);
            border: 1px solid rgba(239, 68, 68, 0.15);
        }
        .info-banner-red i { color: var(--accent-red); }

        /* Status Items */
        .stat-item-inline {
            display: flex; align-items: center; gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .stat-item-inline:last-child { border-bottom: none; }
        .stat-item-icon {
            width: 32px; height: 32px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            background: var(--bg-input);
            color: var(--text-secondary);
        }
        .stat-item-content .stat-item-label {
            font-size: 12px; color: var(--text-muted);
        }
        .stat-item-content .stat-item-value {
            font-size: 14px; font-weight: 500; color: var(--text-primary);
        }
        body:not(.dark-mode) .stat-item-content .stat-item-value { color: #0f172a; }

        .card-divider {
            border: none; border-top: 1px solid var(--border-color);
            margin: 16px 0;
        }

        /* Table */
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
            font-weight: 700; color: var(--accent-amber);
            width: 40px; text-align: center;
        }
        .guide-table .step-name {
            font-weight: 500; color: var(--text-primary);
        }
        body:not(.dark-mode) .guide-table .step-name { color: #0f172a; }
        .guide-table .step-final td {
            background: var(--accent-blue-bg);
            font-weight: 600;
        }
        body:not(.dark-mode) .guide-table .step-final td {
            background: #eff6ff;
        }
        .guide-table .step-final .step-num {
            color: var(--accent-blue);
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
            .card-modern .card-body { padding: 16px; }
            .settings-grid { gap: 16px; }
            .guide-table { font-size: 13px; }
            .guide-table tbody td { padding: 10px 12px; }
        }
        @media (max-width: 480px) {
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .guide-table .step-num { width: 30px; }
            .guide-table tbody td { padding: 8px 10px; font-size: 12px; }
            .stat-item-inline { flex-wrap: wrap; }
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
                <span class="current">Backup</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-database"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>Database Backup</h1>
                        <p class="subtitle">
                            Download and restore your database
                            <span class="badge-beta">Admin Only</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadBackupInfo()" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'backup_failed'): ?>
            <div class="info-banner info-banner-red" style="margin-bottom:20px;">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Backup download failed. Please try again.</span>
            </div>
            <?php endif; ?>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card">
                    <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-70 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-50"></div>
                </div>
            </div>

            <!-- Backup Content -->
            <div id="backupContent" class="initially-hidden">

                <!-- 2-Column Grid: Download + Restore -->
                <div class="settings-grid">

                    <!-- Download Backup Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-blue"><i class="fas fa-download"></i></div>
                            <div>
                                <div class="header-title">Download Backup</div>
                                <div class="header-subtitle">Export your database as a .sql file</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon"><i class="fas fa-database"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Database</div>
                                    <div class="stat-item-value" id="dbName">-</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon"><i class="fas fa-table"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Tables</div>
                                    <div class="stat-item-value" id="dbTables">-</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon"><i class="fas fa-weight-hanging"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Total Size</div>
                                    <div class="stat-item-value" id="dbSize">-</div>
                                </div>
                            </div>

                            <hr class="card-divider">

                            <form method="POST" action="backup.php" id="downloadForm">
                                <input type="hidden" name="action" value="downloadBackup">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <button type="submit" class="btn btn-primary btn-block" id="downloadBtn">
                                    <i class="fas fa-download"></i> Download .sql Backup
                                </button>
                            </form>
                            <div class="info-banner info-banner-blue" style="margin-top:12px;">
                                <i class="fas fa-info-circle"></i>
                                <span>The backup includes all tables and data. File is saved as <strong>backup_<?php echo DB_NAME; ?>_YYYY-MM-DD_HHMMSS.sql</strong></span>
                            </div>
                        </div>
                    </div>

                    <!-- Restore Backup Card -->
                    <div class="card-modern">
                        <div class="card-header">
                            <div class="header-icon header-icon-red"><i class="fas fa-upload"></i></div>
                            <div>
                                <div class="header-title">Restore Backup</div>
                                <div class="header-subtitle">Import a .sql backup file</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="info-banner info-banner-amber" style="margin-top:0;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span><strong>Warning:</strong> Restoring a backup will <strong>overwrite existing data</strong>. Make sure to download a backup first!</span>
                            </div>

                            <div class="form-group">
                                <label><i class="fas fa-file-upload"></i> Select .sql File</label>
                                <input type="file" id="restoreFile" accept=".sql">
                                <small style="color:var(--text-muted);font-size:12px;display:block;margin-top:4px;">Max file size: 50MB</small>
                            </div>

                            <button type="button" class="btn btn-danger btn-block" onclick="restoreBackup()" id="restoreBtn">
                                <i class="fas fa-upload"></i> Restore Database
                            </button>
                            <div class="info-banner info-banner-amber" style="margin-top:12px;">
                                <i class="fas fa-shield-alt"></i>
                                <span>Only <strong>.sql</strong> files are allowed. The system will validate the file before restoring.</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Table Details Card (Full Width) -->
                <div class="card-modern">
                    <div class="card-header">
                        <div class="header-icon header-icon-purple"><i class="fas fa-list"></i></div>
                        <div>
                            <div class="header-title">Database Tables</div>
                            <div class="header-subtitle">Overview of all tables in your database</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="guide-table-wrapper">
                            <table class="guide-table" id="tableListTable">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th>Table Name</th>
                                        <th>Rows</th>
                                        <th>Size</th>
                                    </tr>
                                </thead>
                                <tbody id="tableListBody">
                                    <tr>
                                        <td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px;">Loading tables...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="info-banner info-banner-blue" style="margin-top:14px;">
                            <i class="fas fa-info-circle"></i>
                            <span><strong>Tip:</strong> Download a backup regularly to keep your data safe. The .sql file can be restored anytime from this page or via phpMyAdmin.</span>
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
    // LOAD BACKUP INFO
    // ==========================================
    function loadBackupInfo() {
        $('#loadingSkeleton').show();
        $('#backupContent').hide();

        $.ajax({
            url: 'backup.php?action=getBackupInfo',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                setTimeout(function() {
                    $('#loadingSkeleton').hide();
                    $('#backupContent').fadeIn(300);
                }, 400);

                if (response.success) {
                    var data = response.data;
                    $('#dbName').text(data.database);
                    $('#dbTables').text(data.total_tables);
                    $('#dbSize').text(data.total_size + ' KB');

                    // Build table list
                    var html = '';
                    data.tables.forEach(function(t, i) {
                        html += '<tr>';
                        html += '<td class="step-num">' + (i + 1) + '</td>';
                        html += '<td class="step-name"><i class="fas fa-table" style="color:var(--accent-blue);width:16px;"></i> ' + t.name + '</td>';
                        html += '<td>' + t.rows + '</td>';
                        html += '<td>' + t.size + ' KB</td>';
                        html += '</tr>';
                    });
                    html += '<tr class="step-final">';
                    html += '<td class="step-num"><i class="fas fa-sigma"></i></td>';
                    html += '<td class="step-name"><strong>Total</strong></td>';
                    html += '<td><strong>' + data.total_tables + ' tables</strong></td>';
                    html += '<td><strong>' + data.total_size + ' KB</strong></td>';
                    html += '</tr>';
                    $('#tableListBody').html(html);
                }
            },
            error: function() {
                $('#loadingSkeleton').hide();
                $('#backupContent').show();
                $('#tableListBody').html('<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px;">Failed to load tables</td></tr>');
            }
        });
    }

    // ==========================================
    // RESTORE BACKUP
    // ==========================================
    function restoreBackup() {
        var fileInput = document.getElementById('restoreFile');
        if (!fileInput.files.length) {
            Swal.fire({
                icon: 'warning',
                title: 'No File Selected',
                text: 'Please select a .sql file first.',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            });
            return;
        }

        var file = fileInput.files[0];
        if (!file.name.toLowerCase().endsWith('.sql')) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid File',
                text: 'Only .sql files are allowed.',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            });
            fileInput.value = '';
            return;
        }

        if (file.size > 50 * 1024 * 1024) {
            Swal.fire({
                icon: 'warning',
                title: 'File Too Large',
                text: 'File size must be less than 50MB.',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            });
            fileInput.value = '';
            return;
        }

        Swal.fire({
            title: 'Restore Database?',
            html: '<div style="text-align:left;font-size:14px;">' +
                '<p style="color:var(--accent-red);font-weight:600;font-size:16px;"><i class="fas fa-exclamation-triangle"></i> This will overwrite all existing data!</p>' +
                '<p><strong>File:</strong> ' + escapeHtml(file.name) + '</p>' +
                '<p><strong>Size:</strong> ' + (file.size / 1024 / 1024).toFixed(2) + ' MB</p>' +
                '<p style="margin-top:12px;color:var(--text-muted);">Are you absolutely sure you want to proceed?</p>' +
                '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Restore!',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('action', 'restoreBackup');
                formData.append('backup_file', file);

                Swal.fire({
                    title: 'Restoring Database...',
                    text: 'Please wait while the database is being restored. This may take a few moments.',
                    allowOutsideClick: false,
                    didOpen: function() { Swal.showLoading(); },
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });

                $.ajax({
                    url: 'backup.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    timeout: 300000, // 5 minutes timeout for large restores
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Restored!',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            }).then(function() {
                                fileInput.value = '';
                                loadBackupInfo();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Restore Failed',
                                text: response.message,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            });
                        }
                    },
                    error: function(xhr) {
                        Swal.close();
                        var msg = 'Restore request failed. Please try again.';
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.message) msg = resp.message;
                        } catch(e) {
                            if (xhr.responseText) msg = 'Server error: ' + xhr.responseText.substring(0, 200);
                        }
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: msg,
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
    // KEYBOARD SHORTCUTS
    // ==========================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            loadBackupInfo();
        }
    });

    // ==========================================
    // INIT
    // ==========================================
    $(document).ready(function() {
        document.body.classList.remove('initially-hidden');
        loadBackupInfo();

        console.log('🚀 Backup page loaded successfully');
        console.log('⌨️ Shortcut: Ctrl+R to refresh');
    });
    </script>
</body>
</html>
