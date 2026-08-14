<?php
// clean_session.php - Clean and reset session

session_start();

// Clear all session data
session_unset();

// Destroy the session
session_destroy();

// Start a fresh session
session_start();

// Generate new CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['csrf_token_time'] = time();
$_SESSION['csrf_token_used'] = false;

echo "✅ Session cleaned and reset!\n";
echo "New CSRF Token: " . $_SESSION['csrf_token'] . "\n";
echo "Time: " . date('Y-m-d H:i:s', $_SESSION['csrf_token_time']) . "\n";
echo "\n🔗 Go to: settings.php\n";
?>