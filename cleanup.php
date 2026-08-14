<?php
// cleanup.php - FIXED: Uses information_schema instead of performance_schema
// Run via cron: */5 * * * * php /home/askusecu/emp.askuset.org/cleanup.php

// Include your config file to use the same database connection
require_once __DIR__ . '/dbcon.php';

// Set execution time limit
set_time_limit(120);

// Enable error logging
ini_set('log_errors', 1);
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/cleanup_errors.log');

$logFile = $logDir . '/cleanup.log';

function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

writeLog("=== STARTING CLEANUP PROCESS ===");

try {
    // Get database connection using your config function
    $conn = getDBConnection();
    
    if (!$conn) {
        writeLog("ERROR: Failed to get database connection");
        die("Database connection failed");
    }
    
    writeLog("Database connected successfully");

    // ============================================
    // 1. Check current prepared statement count BEFORE cleanup
    // ============================================
    $result = $conn->query("SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count'");
    $current_count = 0;
    if ($result) {
        $row = $result->fetch_assoc();
        $current_count = isset($row['Value']) ? intval($row['Value']) : 0;
        writeLog("Current prepared statements BEFORE cleanup: $current_count");
        $result->free();
    }

    // ============================================
    // 2. DEALLOCATE ALL PREPARED STATEMENTS
    // FIXED: Using information_schema (works without special permissions)
    // ============================================
    writeLog("Attempting to deallocate prepared statements using information_schema...");
    $statements_freed = 0;
    
    // Try information_schema first (works with standard permissions)
    $result = $conn->query("SELECT STATEMENT_ID FROM information_schema.prepared_statements");
    
    if ($result && $result->num_rows > 0) {
        $total_found = $result->num_rows;
        writeLog("Found $total_found prepared statements in information_schema");
        
        while ($row = $result->fetch_assoc()) {
            if (isset($row['STATEMENT_ID'])) {
                $stmt_id = $row['STATEMENT_ID'];
                
                // Try direct deallocation
                if ($conn->query("DEALLOCATE PREPARE " . $stmt_id)) {
                    $statements_freed++;
                } else {
                    // Try with @ prefix
                    if ($conn->query("DEALLOCATE PREPARE @stmt_" . $stmt_id)) {
                        $statements_freed++;
                    }
                }
                
                // Log progress every 100 statements
                if ($statements_freed % 100 == 0) {
                    writeLog("Progress: Freed $statements_freed statements so far...");
                }
            }
        }
        $result->free();
        writeLog("Deallocated $statements_freed prepared statements via information_schema");
    } else {
        writeLog("No prepared statements found in information_schema or access denied");
        
        // Fallback: Try to get list of statements by checking prepared statement status
        $result = $conn->query("SELECT @_prepared_stmt_count");
        if ($result) {
            $row = $result->fetch_assoc();
            writeLog("Internal prepared statement count: " . ($row ? $row['@_prepared_stmt_count'] : 'unknown'));
            $result->free();
        }
    }

    // ============================================
    // 3. KILL IDLE CONNECTIONS WITH OPEN STATEMENTS
    // ============================================
    writeLog("Checking for idle connections to kill...");
    $killed = 0;
    $current_thread_id = $conn->thread_id;
    
    $result = $conn->query("SHOW PROCESSLIST");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Kill idle connections that have been sleeping > 60 seconds
            if (isset($row['Time']) && $row['Time'] > 60 && $row['Command'] === 'Sleep') {
                if (isset($row['Id']) && $row['Id'] != $current_thread_id) {
                    if ($conn->query("KILL " . $row['Id'])) {
                        $killed++;
                        writeLog("Killed idle connection ID: " . $row['Id'] . " (Time: " . $row['Time'] . "s, User: " . $row['User'] . ")");
                    }
                }
            }
        }
        $result->free();
        if ($killed > 0) {
            writeLog("Killed $killed idle connections");
        }
    }

    // ============================================
    // 4. CHECK FINAL COUNT AFTER CLEANUP
    // ============================================
    $result = $conn->query("SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count'");
    if ($result) {
        $row = $result->fetch_assoc();
        $new_count = isset($row['Value']) ? intval($row['Value']) : 0;
        writeLog("Final prepared statements count AFTER cleanup: $new_count");
        $result->free();
        
        $reduction = $current_count - $new_count;
        if ($reduction > 0) {
            writeLog("SUCCESS: Reduced prepared statements by $reduction");
        } else {
            writeLog("WARNING: No reduction in prepared statement count");
        }
    }

    // ============================================
    // 5. CHECK MAX LIMIT
    // ============================================
    $result = $conn->query("SHOW GLOBAL VARIABLES LIKE 'max_prepared_stmt_count'");
    if ($result) {
        $row = $result->fetch_assoc();
        $max_limit = isset($row['Value']) ? intval($row['Value']) : 0;
        writeLog("Max prepared statement limit: $max_limit");
        $result->free();
        
        if ($new_count > ($max_limit * 0.9)) {
            writeLog("CRITICAL: Statements at $new_count (90%+ of $max_limit limit)");
        }
    }

    // ============================================
    // 6. AGGGRESSIVE CLEANUP IF STILL TOO HIGH
    // ============================================
    if ($new_count > 5000) {
        writeLog("WARNING: Still $new_count statements. Attempting aggressive cleanup...");
        
        // Try to get statement IDs from information_schema with different approach
        $result = $conn->query("SELECT STATEMENT_ID FROM information_schema.prepared_statements LIMIT 1000");
        if ($result && $result->num_rows > 0) {
            $freed_extra = 0;
            while ($row = $result->fetch_assoc()) {
                if (isset($row['STATEMENT_ID'])) {
                    $stmt_id = $row['STATEMENT_ID'];
                    // Try different deallocation syntax
                    $conn->query("DEALLOCATE PREPARE " . $stmt_id);
                    $conn->query("DEALLOCATE PREPARE @stmt_" . $stmt_id);
                    $freed_extra++;
                }
            }
            $result->free();
            writeLog("Aggressive cleanup: Attempted to free $freed_extra additional statements");
        }
        
        // Try to flush status (may require SUPER privileges)
        if ($conn->query("FLUSH STATUS")) {
            writeLog("FLUSH STATUS executed successfully");
            
            // Check again after flush
            $result = $conn->query("SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count'");
            if ($result) {
                $row = $result->fetch_assoc();
                $after_flush = isset($row['Value']) ? intval($row['Value']) : 0;
                writeLog("Count after FLUSH STATUS: $after_flush");
                $result->free();
            }
        } else {
            writeLog("FLUSH STATUS failed (may need SUPER privileges)");
        }
        
        // Nuclear option: Reset connection
        if ($new_count > 8000) {
            writeLog("CRITICAL: Still $new_count statements. Forcing connection reset...");
            
            // Close and reopen connection
            $conn->close();
            
            // Reconnect using your config function
            $conn = getDBConnection();
            if ($conn) {
                writeLog("Connection reset successfully");
                
                // Check final count
                $result = $conn->query("SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count'");
                if ($result) {
                    $row = $result->fetch_assoc();
                    $final_count = isset($row['Value']) ? intval($row['Value']) : 0;
                    writeLog("Final count after connection reset: $final_count");
                    $result->free();
                }
            }
        }
    }

    writeLog("=== CLEANUP COMPLETED SUCCESSFULLY ===");
    writeLog("=== END CLEANUP PROCESS ===\n");

} catch (Exception $e) {
    writeLog("ERROR: " . $e->getMessage());
    writeLog("ERROR Trace: " . $e->getTraceAsString());
    writeLog("=== CLEANUP FAILED ===\n");
}

// Close connection if still open
if (isset($conn) && $conn) {
    @$conn->close();
    writeLog("Database connection closed");
}
?>