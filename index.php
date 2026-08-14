<?php
// Default index page - redirects to login or dashboard
require_once 'config.php';

// Check maintenance mode
if (isMaintenanceMode()) {
    // Allow logged-in admins through
    if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'Admin') {
        header("Location: dashboard.php");
        exit();
    }
    header("Location: maintenance.php");
    exit();
}

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit();
?>
