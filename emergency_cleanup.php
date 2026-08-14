<?php
// emergency_cleanup.php - Run this immediately to fix the prepared statement limit

// Set execution time limit
set_time_limit(120);

echo "=== EMERGENCY PREPARED STATEMENT CLEANUP ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

// Database connection
$host = '65.181.113.236';
$user = 'etexamcy_ask';
$password = 'MCt0923233955';
$database = 'etexamcy_ask';

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("ERROR: Failed to connect to database: " . mysqli_connect_error() . "\n");
}

mysqli_set_charset($conn, "utf8mb4");
echo "Database connected successfully\n\n";

// Check current count
$result = mysqli_query($conn, "SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $current_count = isset($row['Value']) ? intval($row['Value']) : 0;
    echo "Current prepared statements: $current_count\n";
    mysqli_free_result($result);
}

// Try information_schema (works without special permissions)
echo "\nAttempting to deallocate statements using information_schema...\n";

$total_freed = 0;
$result = mysqli_query($conn, "SELECT STATEMENT_ID FROM information_schema.prepared_statements");

if ($result && mysqli_num_rows($result) > 0) {
    $total = mysqli_num_rows($result);
    echo "Found $total prepared statements\n";
    
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($row['STATEMENT_ID'])) {
            $stmt_id = $row['STATEMENT_ID'];
            
            // Try direct deallocation
            if (mysqli_query($conn, "DEALLOCATE PREPARE " . $stmt_id)) {
                $total_freed++;
            } else {
                // Try with @ prefix
                if (mysqli_query($conn, "DEALLOCATE PREPARE @stmt_" . $stmt_id)) {
                    $total_freed++;
                }
            }
            
            // Show progress
            if ($total_freed % 100 == 0) {
                echo "Progress: Freed $total_freed statements...\n";
            }
        }
    }
    mysqli_free_result($result);
    echo "Freed $total_freed statements total\n";
} else {
    echo "No statements found in information_schema or access denied\n";
    
    // Try alternative - kill all connections
    echo "\nAttempting to kill all connections...\n";
    $result = mysqli_query($conn, "SHOW PROCESSLIST");
    if ($result) {
        $killed = 0;
        $current_id = mysqli_thread_id($conn);
        while ($row = mysqli_fetch_assoc($result)) {
            if (isset($row['Id']) && $row['Id'] != $current_id) {
                if (mysqli_query($conn, "KILL " . $row['Id'])) {
                    $killed++;
                }
            }
        }
        mysqli_free_result($result);
        echo "Killed $killed connections\n";
    }
}

// Check final count
$result = mysqli_query($conn, "SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $final_count = isset($row['Value']) ? intval($row['Value']) : 0;
    echo "\nFinal prepared statements count: $final_count\n";
    mysqli_free_result($result);
}

// If still high, suggest restart
if ($final_count > 5000) {
    echo "\n⚠️  WARNING: Count is still at $final_count\n";
    echo "Please contact your hosting provider to restart MySQL:\n";
    echo "  sudo systemctl restart mysql\n";
    echo "  OR\n";
    echo "  sudo service mysql restart\n";
} else {
    echo "\n✅ CLEANUP SUCCESSFUL!\n";
}

mysqli_close($conn);
echo "\n=== CLEANUP COMPLETE ===\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
?>