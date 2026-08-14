<?php
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
$current_page = 'users';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    enforceCSRF();

    try {
        switch ($_GET['action']) {
            case 'getUsers':
                $conn = getDBConnection();
                $result = $conn->query("SELECT id, username, email, role, created_at FROM users ORDER BY id DESC");
                
                $users = [];
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $users[] = [
                            'id' => $row['id'],
                            'username' => $row['username'],
                            'email' => $row['email'],
                            'role' => $row['role'],
                            'created_at' => date('M d, Y', strtotime($row['created_at']))
                        ];
                    }
                    $result->free();
                }

                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'addUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $newUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $newRole = isset($_POST['role']) ? $_POST['role'] : 'User';

                if (empty($newUsername) || empty($email) || empty($password)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $conn = getDBConnection();
                
                // Check if username exists
                $result = $conn->query("SELECT id FROM users WHERE username = '" . $conn->real_escape_string($newUsername) . "'");
                if ($result && $result->num_rows > 0) {
                    $result->free();
                    echo json_encode(['success' => false, 'message' => 'Username already exists']);
                    exit();
                }
                if ($result) $result->free();

                // Check if email exists
                $result = $conn->query("SELECT id FROM users WHERE email = '" . $conn->real_escape_string($email) . "'");
                if ($result && $result->num_rows > 0) {
                    $result->free();
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    exit();
                }
                if ($result) $result->free();

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO users (username, email, password, role) VALUES ('" . 
                       $conn->real_escape_string($newUsername) . "', '" . 
                       $conn->real_escape_string($email) . "', '" . 
                       $conn->real_escape_string($hashedPassword) . "', '" . 
                       $conn->real_escape_string($newRole) . "')";
                
                if ($conn->query($sql)) {
                    // Log activity
                    logActivity($user_id, $username, 'User Created', "Created user: $newUsername ($newRole)");

                    // Notify admins
                    try { createNotificationForAdmins('User Created', 'Admin "' . htmlspecialchars($username) . '" created user "' . htmlspecialchars($newUsername) . '" (' . $newRole . ').', 'info', 'users.php'); } catch (Exception $e) {}

                    echo json_encode(['success' => true, 'message' => 'User added successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to add user: ' . $conn->error]);
                }
                exit();

            case 'updateUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $newUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $newRole = isset($_POST['role']) ? $_POST['role'] : 'User';
                $password = isset($_POST['password']) ? $_POST['password'] : '';

                if ($userId <= 0 || empty($newUsername) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid input']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $conn = getDBConnection();

                // Get old role for change detection
                $old_role_result = $conn->query("SELECT role FROM users WHERE id = " . (int)$userId);
                $old_role = '';
                if ($old_role_result && $old_role_result->num_rows > 0) {
                    $old_role = $old_role_result->fetch_assoc()['role'];
                    $old_role_result->free();
                }

                // Check if username exists for other users
                $result = $conn->query("SELECT id FROM users WHERE username = '" . $conn->real_escape_string($newUsername) . "' AND id != " . (int)$userId);
                if ($result && $result->num_rows > 0) {
                    $result->free();
                    echo json_encode(['success' => false, 'message' => 'Username already exists']);
                    exit();
                }
                if ($result) $result->free();

                // Check if email exists for other users
                $result = $conn->query("SELECT id FROM users WHERE email = '" . $conn->real_escape_string($email) . "' AND id != " . (int)$userId);
                if ($result && $result->num_rows > 0) {
                    $result->free();
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    exit();
                }
                if ($result) $result->free();

                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET username = '" . $conn->real_escape_string($newUsername) . 
                           "', email = '" . $conn->real_escape_string($email) . 
                           "', password = '" . $conn->real_escape_string($hashedPassword) . 
                           "', role = '" . $conn->real_escape_string($newRole) . 
                           "' WHERE id = " . (int)$userId;
                } else {
                    $sql = "UPDATE users SET username = '" . $conn->real_escape_string($newUsername) . 
                           "', email = '" . $conn->real_escape_string($email) . 
                           "', role = '" . $conn->real_escape_string($newRole) . 
                           "' WHERE id = " . (int)$userId;
                }

                if ($conn->query($sql)) {
                    // Log activity
                    $details = !empty($password) ? "Updated user: $newUsername (password changed)" : "Updated user: $newUsername";
                    logActivity($user_id, $username, 'User Updated', $details);

                    // Notify user if role changed
                    try {
                        if ($old_role !== $newRole) {
                            createNotification($userId, 'Role Changed', 'Your role has been changed from ' . $old_role . ' to ' . $newRole . '.', 'warning', 'account.php');
                        }
                    } catch (Exception $e) {}

                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $conn->error]);
                }
                exit();

            case 'deleteUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }

                if ((int)$userId === (int)$_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                    exit();
                }

                $conn = getDBConnection();

                // Get username before deleting
                $result = $conn->query("SELECT username FROM users WHERE id = " . (int)$userId);
                $deletedUsername = '';
                if ($result && $result->num_rows > 0) {
                    $deletedUsername = $result->fetch_assoc()['username'];
                    $result->free();
                }

                $sql = "DELETE FROM users WHERE id = " . (int)$userId;
                if ($conn->query($sql)) {
                    // Log activity
                    logActivity($user_id, $username, 'User Deleted', "Deleted user: $deletedUsername");
                    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to delete user: ' . $conn->error]);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Users.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
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
    <title>Users · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/searchbuilder/1.5.0/css/searchBuilder.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">

    <style>
        /* ============================================================
           USERS PAGE - OUTSTANDING MODERN UI
           Inspired by: Linear, Stripe, Vercel, GitHub
           ============================================================ */

        /* ---------- CSS Variables (Dark Mode First) ---------- */
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #111118;
            --bg-card: #16161f;
            --bg-card-hover: #1c1c28;
            --bg-input: #1a1a26;
            --bg-input-hover: #222234;
            --bg-sidebar: #0d0d14;
            --bg-dropdown: #1a1a26;
            --bg-tooltip: #222234;

            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --text-inverse: #0a0a0f;

            --border-color: rgba(255,255,255,0.06);
            --border-hover: rgba(255,255,255,0.12);

            --accent-blue: #3b82f6;
            --accent-blue-hover: #60a5fa;
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

            --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.5);
            --shadow-xl: 0 16px 64px rgba(0,0,0,0.6);
            --shadow-glow: 0 0 40px rgba(59, 130, 246, 0.1);

            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 18px;
            --radius-full: 9999px;

            --transition-fast: 0.12s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-bounce: 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* Light Mode Override */
        [data-theme="light"],
        body:not(.dark-mode) {
            --bg-primary: #f6f8fa;
            --bg-secondary: #eef2f6;
            --bg-card: #ffffff;
            --bg-card-hover: #f0f2f5;
            --bg-input: #f0f2f5;
            --bg-input-hover: #e4e8ee;
            --bg-sidebar: #ffffff;

            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --text-inverse: #ffffff;

            --border-color: rgba(0,0,0,0.06);
            --border-hover: rgba(0,0,0,0.12);

            --shadow-sm: 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.06);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.08);
            --shadow-xl: 0 16px 64px rgba(0,0,0,0.1);
            --shadow-glow: 0 0 40px rgba(59, 130, 246, 0.05);
        }

        /* ---------- Base ---------- */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
            transition: background var(--transition-slow), color var(--transition-slow);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            min-height: 100vh;
        }

        /* ---------- Scrollbar ---------- */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }
        ::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: var(--radius-full);
        }
        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* ---------- Breadcrumb ---------- */
        .breadcrumb-modern {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-muted);
            padding: 20px 0 12px;
            margin-bottom: 4px;
        }
        .breadcrumb-modern a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color var(--transition-fast);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .breadcrumb-modern a:hover {
            color: var(--accent-blue);
        }
        .breadcrumb-modern .sep {
            color: var(--text-muted);
            font-size: 10px;
        }
        .breadcrumb-modern .current {
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* ---------- Page Header ---------- */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            padding: 4px 0 24px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 28px;
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .page-header-left .icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-lg);
            background: linear-gradient(135deg, var(--accent-blue-bg), rgba(59, 130, 246, 0.05));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: var(--accent-blue);
            flex-shrink: 0;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .page-header-left .icon-wrapper .pulse-dot {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 12px;
            height: 12px;
            border-radius: var(--radius-full);
            background: var(--accent-green);
            border: 2px solid var(--bg-card);
            animation: pulse-dot 2s ease-in-out infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        .page-header-left .title-group h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.03em;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .page-header-left .title-group .subtitle {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 2px 0 0;
            font-weight: 400;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-header-left .title-group .subtitle .badge-beta {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 2px 10px;
            border-radius: var(--radius-full);
            background: var(--accent-purple-bg);
            color: var(--accent-purple);
            border: 1px solid rgba(139, 92, 246, 0.15);
        }

        .page-header-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ---------- Buttons ---------- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all var(--transition-fast);
            text-decoration: none;
            background: var(--bg-card);
            color: var(--text-secondary);
            position: relative;
            overflow: hidden;
            user-select: none;
        }

        .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--accent-blue);
            opacity: 0;
            transition: opacity var(--transition-fast);
        }

        .btn:active {
            transform: scale(0.96);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-blue), #2563eb);
            color: #fff;
            border-color: transparent;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--accent-blue-hover), #3b82f6);
            color: #fff;
            box-shadow: 0 4px 24px rgba(59, 130, 246, 0.35);
            transform: translateY(-1px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--accent-green), #059669);
            color: #fff;
            border-color: transparent;
        }

        .btn-success:hover {
            box-shadow: 0 4px 24px rgba(16, 185, 129, 0.35);
            transform: translateY(-1px);
            color: #fff;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--accent-red), #dc2626);
            color: #fff;
            border-color: transparent;
        }

        .btn-danger:hover {
            box-shadow: 0 4px 24px rgba(239, 68, 68, 0.35);
            transform: translateY(-1px);
            color: #fff;
        }

        .btn-ghost {
            background: transparent;
            border-color: transparent;
            color: var(--text-muted);
        }

        .btn-ghost:hover {
            background: var(--bg-card);
            border-color: var(--border-color);
            color: var(--text-primary);
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
            border-radius: var(--radius-sm);
        }

        .btn-xs {
            padding: 4px 10px;
            font-size: 11px;
            border-radius: var(--radius-sm);
        }

        .btn .badge-count {
            background: rgba(255,255,255,0.15);
            color: #fff;
            font-size: 10px;
            padding: 1px 8px;
            border-radius: var(--radius-full);
            margin-left: 2px;
        }

        .btn-primary .badge-count {
            background: rgba(255,255,255,0.2);
        }

        /* ---------- Stats Grid ---------- */
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
            top: 0;
            left: 0;
            right: 0;
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

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .stat-card .stat-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            letter-spacing: 0.01em;
        }

        .stat-card .stat-icon {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
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

        .stat-card .stat-change.negative {
            background: var(--accent-red-bg);
            color: var(--accent-red);
        }

        .stat-card:nth-child(2)::before {
            background: linear-gradient(90deg, var(--accent-purple), var(--accent-pink));
        }
        .stat-card:nth-child(3)::before {
            background: linear-gradient(90deg, var(--accent-green), var(--accent-amber));
        }
        .stat-card:nth-child(4)::before {
            background: linear-gradient(90deg, var(--accent-amber), var(--accent-red));
        }

        .stat-card:nth-child(2):hover .stat-icon {
            background: var(--accent-purple-bg);
            color: var(--accent-purple);
        }
        .stat-card:nth-child(3):hover .stat-icon {
            background: var(--accent-green-bg);
            color: var(--accent-green);
        }
        .stat-card:nth-child(4):hover .stat-icon {
            background: var(--accent-amber-bg);
            color: var(--accent-amber);
        }

        /* ---------- Search & Filters Bar ---------- */
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

        .filters-bar:hover {
            border-color: var(--border-hover);
        }

        .filters-bar .search-wrapper {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .filters-bar .search-wrapper input {
            width: 100%;
            padding: 9px 14px 9px 38px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 13px;
            font-family: inherit;
            transition: all var(--transition-fast);
        }

        .filters-bar .search-wrapper input:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-bg);
        }

        .filters-bar .search-wrapper input::placeholder {
            color: var(--text-muted);
        }

        .filters-bar .search-wrapper .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filters-bar .filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .filters-bar .filter-group select {
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 13px;
            font-family: inherit;
            transition: all var(--transition-fast);
            min-width: 120px;
            cursor: pointer;
        }

        .filters-bar .filter-group select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-bg);
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

        /* ---------- Table Wrapper ---------- */
        .table-wrapper {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
        }

        .table-wrapper:hover {
            border-color: var(--border-hover);
        }

        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 12px;
        }

        .table-toolbar .left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .table-toolbar .left h3 {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-toolbar .left .count-badge {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            background: var(--bg-input);
            padding: 2px 14px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border-color);
        }

        .table-toolbar .right {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }

        .table-toolbar .right .btn {
            padding: 6px 12px;
            font-size: 12px;
        }

        .table-toolbar .right .btn i {
            font-size: 13px;
        }

        .table-toolbar .right .btn .label {
            display: inline;
        }

        /* ---------- DataTable Customization ---------- */
        .table-wrapper .dataTables_wrapper {
            padding: 0 24px 20px;
        }

        .table-wrapper .dataTables_wrapper .dataTables_filter {
            display: none;
        }

        .table-wrapper .dataTables_wrapper .dataTables_length {
            display: none;
        }

        .table-wrapper .dataTables_wrapper .dataTables_info {
            padding: 16px 0 0;
            font-size: 13px;
            color: var(--text-muted);
        }

        .table-wrapper .dataTables_wrapper .dataTables_paginate {
            padding: 16px 0 0;
        }

        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 13px;
            transition: all var(--transition-fast);
            margin: 0 2px;
            cursor: pointer;
        }

        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--bg-input);
            color: var(--text-primary);
        }

        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--accent-blue);
            color: #fff;
            border-radius: var(--radius-sm);
        }

        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--accent-blue-hover);
            color: #fff;
        }

        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        /* ---------- Table ---------- */
        .table-wrapper table.dataTable {
            width: 100% !important;
            border-collapse: collapse;
            margin: 0 !important;
            font-size: 14px;
            background: transparent !important;
        }

        .table-wrapper table.dataTable thead th {
            padding: 14px 14px 10px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
            background: transparent !important;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .table-wrapper table.dataTable thead th:first-child {
            padding-left: 0;
        }

        .table-wrapper table.dataTable tbody td {
            padding: 14px 14px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            vertical-align: middle;
            background: transparent !important;
        }

        .table-wrapper table.dataTable tbody td:first-child {
            padding-left: 0;
        }

        .table-wrapper table.dataTable tbody tr:hover td {
            background: var(--bg-card-hover) !important;
        }

        .table-wrapper table.dataTable tbody tr:last-child td {
            border-bottom: none;
        }

        .table-wrapper table.dataTable tbody tr td .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: var(--radius-full);
            background: var(--accent-blue-bg);
            color: var(--accent-blue);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 13px;
            margin-right: 10px;
            flex-shrink: 0;
            transition: all var(--transition-fast);
        }

        .table-wrapper table.dataTable tbody tr:hover td .user-avatar {
            background: var(--accent-blue);
            color: #fff;
        }

        .table-wrapper table.dataTable tbody tr td .user-cell {
            display: flex;
            align-items: center;
        }

        /* ---------- Badges ---------- */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
        }

        .badge .dot {
            width: 6px;
            height: 6px;
            border-radius: var(--radius-full);
            display: inline-block;
        }

        .badge-admin {
            background: var(--accent-blue-bg);
            color: var(--accent-blue);
            border-color: rgba(59, 130, 246, 0.15);
        }

        .badge-admin .dot {
            background: var(--accent-blue);
        }

        .badge-user {
            background: var(--accent-green-bg);
            color: var(--accent-green);
            border-color: rgba(16, 185, 129, 0.15);
        }

        .badge-user .dot {
            background: var(--accent-green);
        }

        /* ---------- Action Buttons ---------- */
        .action-group {
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            border-radius: var(--radius-sm);
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            position: relative;
        }

        .action-btn:hover {
            background: var(--bg-input);
            color: var(--text-secondary);
        }

        .action-btn-edit:hover {
            color: var(--accent-blue);
            background: var(--accent-blue-bg);
        }

        .action-btn-delete:hover {
            color: var(--accent-red);
            background: var(--accent-red-bg);
        }

        .action-btn .tooltip {
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%) scale(0.8);
            background: var(--bg-tooltip);
            color: var(--text-primary);
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all var(--transition-fast);
            border: 1px solid var(--border-color);
        }

        .action-btn .tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: var(--bg-tooltip);
        }

        .action-btn:hover .tooltip {
            opacity: 1;
            transform: translateX(-50%) scale(1);
        }

        /* ---------- Modal ---------- */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: overlayIn var(--transition-normal);
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes overlayIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal {
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            max-width: 520px;
            width: 100%;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
            overflow: hidden;
            animation: modalIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(0.92) translateY(24px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-header {
            padding: 24px 28px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            background: var(--bg-card);
            z-index: 1;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.02em;
        }

        .modal-header h3 i {
            color: var(--accent-blue);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            border: none;
            background: var(--bg-input);
            color: var(--text-secondary);
            font-size: 18px;
            cursor: pointer;
            transition: all var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            background: var(--accent-red-bg);
            color: var(--accent-red);
        }

        .modal-body {
            padding: 24px 28px 28px;
        }

        /* ---------- Form ---------- */
        .form-group {
            margin-bottom: 18px;
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

        .form-group .hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
            display: none;
        }

        .form-group .hint.visible {
            display: block;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .form-actions .btn {
            flex: 1;
            justify-content: center;
            padding: 12px 20px;
        }

        /* ---------- Toast Notification ---------- */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: 380px;
        }

        .toast {
            padding: 14px 20px;
            border-radius: var(--radius-md);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideInRight 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            backdrop-filter: blur(8px);
        }

        .toast.success { border-left: 3px solid var(--accent-green); }
        .toast.error { border-left: 3px solid var(--accent-red); }
        .toast.info { border-left: 3px solid var(--accent-blue); }
        .toast.warning { border-left: 3px solid var(--accent-amber); }

        .toast .toast-icon {
            font-size: 18px;
        }
        .toast.success .toast-icon { color: var(--accent-green); }
        .toast.error .toast-icon { color: var(--accent-red); }
        .toast.info .toast-icon { color: var(--accent-blue); }
        .toast.warning .toast-icon { color: var(--accent-amber); }

        .toast .toast-content {
            flex: 1;
        }
        .toast .toast-content .toast-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .toast .toast-content .toast-message {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .toast .toast-close {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
        }
        .toast .toast-close:hover {
            color: var(--text-primary);
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(40px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* ---------- Responsive ---------- */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 14px;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }

            .page-header-right {
                width: 100%;
            }

            .page-header-right .btn {
                flex: 1;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 16px 18px;
            }

            .stat-card .stat-value {
                font-size: 22px;
            }

            .filters-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 14px 16px;
            }

            .filters-bar .search-wrapper {
                min-width: unset;
            }

            .filters-bar .filter-group {
                flex-wrap: wrap;
            }

            .filters-bar .filter-group select {
                min-width: 100%;
                flex: 1;
            }

            .filters-bar .filter-actions {
                margin-left: 0;
                justify-content: stretch;
            }

            .filters-bar .filter-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .table-toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 14px 16px;
            }

            .table-toolbar .left {
                flex-wrap: wrap;
            }

            .table-toolbar .right {
                flex-wrap: wrap;
            }

            .table-toolbar .right .btn .label {
                display: none;
            }

            .table-wrapper .dataTables_wrapper {
                padding: 0 14px 16px;
            }

            .table-wrapper table.dataTable {
                font-size: 13px;
            }

            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td {
                padding: 10px 10px;
            }

            .modal {
                margin: 10px;
                border-radius: var(--radius-lg);
                max-height: 95vh;
            }

            .modal-header {
                padding: 18px 20px 12px;
            }

            .modal-body {
                padding: 18px 20px 22px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                flex: none;
            }

            .toast-container {
                bottom: 16px;
                right: 16px;
                left: 16px;
                max-width: unset;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .stat-card {
                padding: 12px 14px;
            }

            .stat-card .stat-value {
                font-size: 18px;
            }

            .stat-card .stat-label {
                font-size: 11px;
            }

            .stat-card .stat-icon {
                width: 28px;
                height: 28px;
                font-size: 13px;
            }

            .page-header-left .icon-wrapper {
                width: 42px;
                height: 42px;
                font-size: 18px;
            }

            .page-header-left .title-group h1 {
                font-size: 20px;
            }

            .page-header-left .title-group .subtitle {
                font-size: 12px;
            }

            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td {
                padding: 8px 6px;
                font-size: 12px;
            }

            .badge {
                font-size: 10px;
                padding: 2px 10px;
            }

            .action-btn {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }
        }

        /* ---------- Smooth Theme Transition ---------- */
        * {
            transition: background-color var(--transition-slow),
                        color var(--transition-slow),
                        border-color var(--transition-slow),
                        box-shadow var(--transition-slow),
                        transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ---------- DataTable Buttons Override ---------- */
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

        .dt-button.dt-button-active {
            background: var(--accent-blue) !important;
            color: #fff !important;
            border-color: transparent !important;
        }
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
                <span class="current">Users</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-users"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>User Management</h1>
                        <p class="subtitle">
                            Manage system users, roles, and permissions
                            <span class="badge-beta">v2.0</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadUsers()" title="Refresh users (Ctrl+R)">
                        <i class="fas fa-sync"></i>
                        <span class="label">Refresh</span>
                    </button>
                    <button class="btn btn-primary" onclick="openAddModal()" title="Add new user (Ctrl+N)">
                        <i class="fas fa-plus"></i>
                        <span class="label">Add User</span>
                    </button>
                </div>
            </div>

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
                        <span class="stat-label">Administrators</span>
                        <span class="stat-icon"><i class="fas fa-user-shield"></i></span>
                    </div>
                    <div class="stat-value" id="adminCount">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Regular Users</span>
                        <span class="stat-icon"><i class="fas fa-user"></i></span>
                    </div>
                    <div class="stat-value" id="userCount">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">New This Month</span>
                        <span class="stat-icon"><i class="fas fa-calendar-plus"></i></span>
                    </div>
                    <div class="stat-value" id="newThisMonth">0</div>
                </div>
            </div>

            <!-- Search & Filters -->
            <div class="filters-bar" id="filtersSection">
                <div class="search-wrapper">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="tableSearch" placeholder="Search users by name, email, or role..." autocomplete="off">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> From</label>
                    <input type="date" id="filterDateFrom" style="padding:8px 12px;border-radius:var(--radius-sm);border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);font-size:13px;font-family:inherit;transition:all var(--transition-fast);">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> To</label>
                    <input type="date" id="filterDateTo" style="padding:8px 12px;border-radius:var(--radius-sm);border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);font-size:13px;font-family:inherit;transition:all var(--transition-fast);">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-user-tag"></i> Role</label>
                    <select id="filterRole">
                        <option value="">All Roles</option>
                        <option value="Admin">Admin</option>
                        <option value="User">User</option>
                    </select>
                </div>
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
                        <h3><i class="fas fa-table"></i> All Users</h3>
                        <span class="count-badge" id="userCountBadge">0 users</span>
                    </div>
                    <div class="right">
                        <button class="btn btn-ghost btn-sm" onclick="exportData('copy')" title="Copy to clipboard">
                            <i class="fas fa-copy"></i>
                            <span class="label">Copy</span>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('csv')" title="Export as CSV">
                            <i class="fas fa-file-csv"></i>
                            <span class="label">CSV</span>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('excel')" title="Export as Excel">
                            <i class="fas fa-file-excel"></i>
                            <span class="label">Excel</span>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('pdf')" title="Export as PDF">
                            <i class="fas fa-file-pdf"></i>
                            <span class="label">PDF</span>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('print')" title="Print">
                            <i class="fas fa-print"></i>
                            <span class="label">Print</span>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="usersTable" class="display"></table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="userForm" autocomplete="off">
                    <input type="hidden" id="userId" name="id">

                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Username <span class="required">*</span></label>
                        <input type="text" id="username" name="username" placeholder="Enter username" required autofocus>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" placeholder="user@example.com" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password <span class="required" id="passwordRequired">*</span></label>
                        <input type="password" id="password" name="password" placeholder="Enter password">
                        <div class="hint" id="passwordHint">Leave empty to keep current password</div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> Role <span class="required">*</span></label>
                        <select id="role" name="role" required>
                            <option value="User">User</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save User
                        </button>
                        <button type="button" class="btn btn-ghost" onclick="closeModal()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Scripts -->
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
        // ============================================================
        // USERS PAGE - OUTSTANDING MODERN FRONTEND
        // ============================================================

        let usersTable;
        let isEditMode = false;
        let usersData = [];

        // ===== TOAST NOTIFICATION =====
        function showToast(message, type = 'info', title = '') {
            const container = document.getElementById('toastContainer');
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-times-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            const titles = {
                success: 'Success',
                error: 'Error',
                warning: 'Warning',
                info: 'Info'
            };

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <span class="toast-icon"><i class="fas ${icons[type] || icons.info}"></i></span>
                <div class="toast-content">
                    <div class="toast-title">${title || titles[type] || 'Info'}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;

            container.appendChild(toast);
            setTimeout(() => {
                if (toast.parentElement) toast.remove();
            }, 5000);
        }

        // ===== INITIALIZE =====
        $(document).ready(function() {
            loadUsers();
            setupModalEvents();
            setupSearchListener();

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    openAddModal();
                }
                if (e.key === 'Escape') {
                    if (document.getElementById('userModal').classList.contains('active')) {
                        closeModal();
                    }
                }
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    loadUsers();
                }
            });

            console.log('🚀 Users Management v2.0 loaded');
            console.log('⌨️ Shortcuts: Ctrl+N=Add, Ctrl+R=Refresh, Esc=Close');
        });

        function setupModalEvents() {
            document.getElementById('userModal').addEventListener('click', function(e) {
                if (e.target === this) closeModal();
            });

            document.getElementById('userForm').addEventListener('submit', function(e) {
                e.preventDefault();
                submitUserForm();
            });
        }

        function setupSearchListener() {
            document.getElementById('tableSearch').addEventListener('input', function() {
                if (usersTable) {
                    usersTable.search(this.value).draw();
                }
            });
        }

        // ===== LOAD USERS =====
        function loadUsers() {
            const refreshBtn = document.querySelector('.btn-ghost .fa-sync');
            if (refreshBtn) refreshBtn.classList.add('fa-spin');

            $.ajax({
                url: '?action=getUsers',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        usersData = response.data;
                        updateStats(response.data);
                        initializeDataTable(response.data);
                        showToast('Users loaded successfully', 'success');
                    } else {
                        showToast(response.message || 'Failed to load users', 'error');
                    }
                },
                error: function() {
                    showToast('Could not connect to server', 'error');
                },
                complete: function() {
                    if (refreshBtn) refreshBtn.classList.remove('fa-spin');
                }
            });
        }

        // ===== UPDATE STATS =====
        function updateStats(data) {
            const total = data.length;
            const admins = data.filter(u => u.role === 'Admin').length;
            const users = data.filter(u => u.role === 'User').length;

            const now = new Date();
            const thisMonth = now.getMonth();
            const thisYear = now.getFullYear();
            const newThisMonth = data.filter(u => {
                const d = new Date(u.created_at);
                return d.getMonth() === thisMonth && d.getFullYear() === thisYear;
            }).length;

            document.getElementById('totalUsers').textContent = total;
            document.getElementById('adminCount').textContent = admins;
            document.getElementById('userCount').textContent = users;
            document.getElementById('newThisMonth').textContent = newThisMonth;
            document.getElementById('userCountBadge').textContent = total + ' user' + (total !== 1 ? 's' : '');
        }

        // ===== INIT DATATABLE =====
        function initializeDataTable(data) {
            if (usersTable) {
                usersTable.destroy();
                $('#usersTable').empty();
            }

            setTimeout(() => {
                usersTable = $('#usersTable').DataTable({
                    data: data,
                    columns: [
                        {
                            data: null,
                            title: '#',
                            width: '45px',
                            render: function(data, type, row, meta) {
                                return meta.row + 1;
                            }
                        },
                        {
                            data: 'username',
                            title: 'Username',
                            render: function(data) {
                                const initial = data.charAt(0).toUpperCase();
                                return `<div class="user-cell">
                                    <span class="user-avatar">${initial}</span>
                                    ${data}
                                </div>`;
                            }
                        },
                        { data: 'email', title: 'Email' },
                        {
                            data: 'role',
                            title: 'Role',
                            render: function(data) {
                                const cls = data === 'Admin' ? 'badge-admin' : 'badge-user';
                                return `<span class="badge ${cls}">
                                    <span class="dot"></span> ${data}
                                </span>`;
                            }
                        },
                        {
                            data: 'created_at',
                            title: 'Joined',
                            render: function(data) {
                                return `<span style="color:var(--text-muted);font-size:13px;">${data}</span>`;
                            }
                        },
                        {
                            data: null,
                            title: 'Actions',
                            orderable: false,
                            width: '90px',
                            render: function(data, type, row) {
                                return `
                                    <div class="action-group">
                                        <button class="action-btn action-btn-edit" onclick='editUser(${JSON.stringify(row)})'>
                                            <i class="fas fa-pen"></i>
                                            <span class="tooltip">Edit</span>
                                        </button>
                                        <button class="action-btn action-btn-delete" onclick="deleteUser(${row.id})">
                                            <i class="fas fa-trash-alt"></i>
                                            <span class="tooltip">Delete</span>
                                        </button>
                                    </div>
                                `;
                            }
                        }
                    ],
                    pageLength: 15,
                    lengthMenu: [[10, 15, 25, 50, 100, -1], [10, 15, 25, 50, 100, "All"]],
                    responsive: true,
                    autoWidth: false,
                    dom: '<"top"lf>rt<"bottom"ip>',
                    language: {
                        emptyTable: 'No users found',
                        info: 'Showing _START_ to _END_ of _TOTAL_ users',
                        infoEmpty: 'No users available',
                        infoFiltered: '(filtered from _MAX_ total users)',
                        search: 'Search:'
                    },
                    order: [[0, 'desc']],
                    columnDefs: [
                        { targets: 0, orderable: false }
                    ],
                    buttons: [
                        {
                            extend: 'copy',
                            text: 'Copy',
                            exportOptions: { columns: ':not(:last-child)' }
                        },
                        {
                            extend: 'csv',
                            text: 'CSV',
                            exportOptions: { columns: ':not(:last-child)' }
                        },
                        {
                            extend: 'excel',
                            text: 'Excel',
                            exportOptions: { columns: ':not(:last-child)' }
                        },
                        {
                            extend: 'pdf',
                            text: 'PDF',
                            exportOptions: { columns: ':not(:last-child)' },
                            customize: function(doc) {
                                doc.styles.title = { color: '#0a0a0f' };
                                doc.styles.tableHeader = { fillColor: '#3b82f6' };
                            }
                        },
                        {
                            extend: 'print',
                            text: 'Print',
                            exportOptions: { columns: ':not(:last-child)' }
                        }
                    ]
                });

                // Remove default DataTable buttons (we use custom)
                $('.dt-buttons').hide();

                // Apply filters
                $('#filterDateFrom, #filterDateTo, #filterRole').on('change', function() {
                    applyFilters();
                });

                // Sync search with custom search
                const customSearch = document.getElementById('tableSearch');
                if (customSearch) {
                    customSearch.value = '';
                }

            }, 50);
        }

        // ===== FILTERS =====
        function applyFilters() {
            if (!usersTable) return;

            $.fn.dataTable.ext.search = [];

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const role = document.getElementById('filterRole').value;

            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const createdAt = usersData[dataIndex]?.created_at;
                    if (!createdAt) return true;

                    const recordDate = new Date(createdAt);
                    const fromDate = dateFrom ? new Date(dateFrom) : null;
                    const toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;

                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            if (role) {
                usersTable.column(3).search(role);
            } else {
                usersTable.column(3).search('');
            }

            usersTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterRole').value = '';
            document.getElementById('tableSearch').value = '';

            if (usersTable) {
                $.fn.dataTable.ext.search = [];
                usersTable.search('').columns().search('').draw();
            }
            showToast('Filters cleared', 'info');
        }

        // ===== EXPORT DATA =====
        function exportData(type) {
            if (!usersTable) {
                showToast('Table not loaded yet', 'warning');
                return;
            }

            try {
                const buttons = usersTable.buttons();

                switch(type) {
                    case 'copy':
                        buttons.button(0).trigger();
                        showToast('Data copied to clipboard', 'success');
                        break;
                    case 'csv':
                        buttons.button(1).trigger();
                        showToast('CSV export started', 'success');
                        break;
                    case 'excel':
                        buttons.button(2).trigger();
                        showToast('Excel export started', 'success');
                        break;
                    case 'pdf':
                        buttons.button(3).trigger();
                        showToast('PDF export started', 'success');
                        break;
                    case 'print':
                        buttons.button(4).trigger();
                        break;
                    default:
                        showToast('Unknown export type', 'error');
                }
            } catch(e) {
                showToast('Export failed: ' + e.message, 'error');
            }
        }

        // ===== MODAL =====
        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('password').required = true;
            document.getElementById('passwordRequired').textContent = '*';
            document.getElementById('passwordHint').classList.remove('visible');
            document.getElementById('userModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => document.getElementById('username').focus(), 100);
        }

        function editUser(user) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit"></i> Edit User';
            document.getElementById('userId').value = user.id;
            document.getElementById('username').value = user.username;
            document.getElementById('email').value = user.email;
            document.getElementById('role').value = user.role;
            document.getElementById('password').value = '';
            document.getElementById('password').required = false;
            document.getElementById('passwordRequired').textContent = '';
            document.getElementById('passwordHint').classList.add('visible');
            document.getElementById('userModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => document.getElementById('username').focus(), 100);
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
            document.body.style.overflow = '';
            document.getElementById('userForm').reset();
        }

        // ===== SUBMIT FORM =====
        function submitUserForm() {
            const formData = new FormData(document.getElementById('userForm'));
            const action = isEditMode ? 'updateUser' : 'addUser';

            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            if (!username || !email) {
                showToast('Username and email are required', 'warning', 'Required Fields');
                return;
            }

            if (!isEditMode && !password) {
                showToast('Please set a password for the new user', 'warning', 'Password Required');
                return;
            }

            showToast('Processing...', 'info', 'Please wait');

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        closeModal();
                        setTimeout(() => loadUsers(), 200);
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function() {
                    showToast('Connection error. Please try again.', 'error');
                }
            });
        }

        // ===== DELETE USER =====
        function deleteUser(userId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete User?',
                text: 'This action cannot be undone.',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)',
                reverseButtons: true
            }).then((result) => {
                if (!result.isConfirmed) return;

                const formData = new FormData();
                formData.append('id', userId);

                showToast('Deleting user...', 'info', 'Please wait');

                $.ajax({
                    url: '?action=deleteUser',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showToast(response.message, 'success');
                            setTimeout(() => loadUsers(), 200);
                        } else {
                            showToast(response.message, 'error');
                        }
                    },
                    error: function() {
                        showToast('Connection error.', 'error');
                    }
                });
            });
        }

        // ===== TOGGLE THEME (for sidebar compatibility) =====
        document.addEventListener('themeChanged', function() {
            // DataTable auto-adjusts via CSS variables
        });
    </script>
</body>
</html>