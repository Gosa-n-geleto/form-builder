<?php
/**
 * 
 * Google OAuth Callback Handler
 */

require_once 'config.php';

// Debug logging helper
function oauthLog($step, $message) {
    error_log("[Google OAuth Debug] Step: $step | $message");
}

oauthLog('START', 'Callback received. GET params: ' . json_encode($_GET));

// Check if Google OAuth is enabled
$oauth_enabled = getSetting('google_oauth_enabled', '0');
if ($oauth_enabled !== '1') {
    oauthLog('FAIL', 'OAuth is NOT enabled in settings');
    header("Location: login.php");
    exit();
}

// Get OAuth credentials from database
$client_id = getSetting('google_client_id', '');
$client_secret = getSetting('google_client_secret', '');
$redirect_uri = getSetting('google_redirect_uri', '');

oauthLog('CONFIG', 'redirect_uri from DB: ' . $redirect_uri);
oauthLog('CONFIG', 'client_id exists: ' . (!empty($client_id) ? 'YES' : 'NO'));
oauthLog('CONFIG', 'client_secret exists: ' . (!empty($client_secret) ? 'YES' : 'NO'));

if (empty($client_id) || empty($client_secret) || empty($redirect_uri)) {
    oauthLog('FAIL', 'Missing OAuth credentials');
    header("Location: login.php?error=oauth_not_configured");
    exit();
}

// Check for error from Google
if (isset($_GET['error'])) {
    oauthLog('FAIL', 'Google returned error: ' . $_GET['error']);
    header("Location: login.php?error=oauth_denied");
    exit();
}

// Check for authorization code
if (!isset($_GET['code'])) {
    oauthLog('FAIL', 'No authorization code in URL');
    header("Location: login.php");
    exit();
}

$code = $_GET['code'];
oauthLog('CODE', 'Got authorization code: ' . substr($code, 0, 20) . '...');

// Determine if this is a login or signup flow from the state parameter
$oauth_action = 'login'; // default
if (isset($_GET['state'])) {
    $state_data = json_decode(base64_decode($_GET['state']), true);
    if (is_array($state_data) && isset($state_data['action'])) {
        $oauth_action = $state_data['action'] === 'signup' ? 'signup' : 'login';
    }
}
oauthLog('STATE', 'OAuth action: ' . $oauth_action);

// Exchange authorization code for access token
$token_url = 'https://oauth2.googleapis.com/token';
$token_data = [
    'code' => $code,
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri' => $redirect_uri,
    'grant_type' => 'authorization_code'
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// SSL Configuration - try with verification first, fallback if needed
$cacert_path = getCACertPath();
if ($cacert_path) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CAINFO, $cacert_path);
    oauthLog('SSL', 'Using CA cert: ' . $cacert_path);
} else {
    // Fallback: disable verification (XAMPP / shared hosting without CA bundle)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    oauthLog('SSL', 'No CA cert found - SSL verify disabled');
}

$token_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

oauthLog('TOKEN', "HTTP $http_code | cURL error: " . ($curl_error ?: 'none') . " | Response: " . substr($token_response ?: 'false', 0, 500));

if ($token_response === false || $http_code !== 200) {
    oauthLog('FAIL', "Token exchange failed (HTTP $http_code): " . ($curl_error ?: $token_response));
    header("Location: login.php?error=oauth_token_failed");
    exit();
}

$token_data = json_decode($token_response, true);

if (!isset($token_data['access_token'])) {
    oauthLog('FAIL', 'No access_token in response: ' . $token_response);
    header("Location: login.php?error=oauth_token_failed");
    exit();
}

$access_token = $token_data['access_token'];
oauthLog('TOKEN', 'Got access token successfully');

// Get user info from Google
$userinfo_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init($userinfo_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

if ($cacert_path) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CAINFO, $cacert_path);
} else {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$userinfo_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

oauthLog('USERINFO', "HTTP $http_code | Response: " . substr($userinfo_response ?: 'false', 0, 500));

if ($userinfo_response === false || $http_code !== 200) {
    oauthLog('FAIL', "Userinfo failed (HTTP $http_code): " . ($curl_error ?: $userinfo_response));
    header("Location: login.php?error=oauth_userinfo_failed");
    exit();
}

$google_user = json_decode($userinfo_response, true);

if (!isset($google_user['id']) || !isset($google_user['email'])) {
    oauthLog('FAIL', 'Missing user id or email in response: ' . $userinfo_response);
    header("Location: login.php?error=oauth_userinfo_failed");
    exit();
}

$google_id = $google_user['id'];
$google_email = $google_user['email'];
$google_name = isset($google_user['name']) ? $google_user['name'] : '';

oauthLog('USER', "Google ID: $google_id | Email: $google_email | Name: $google_name");

// Check maintenance mode
$is_maintenance = isMaintenanceMode();

try {
    $conn = getDBConnection();
    oauthLog('DB', 'Database connected');

    // First, check if user exists by google_id
    $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE google_id = ?");
    $stmt->bind_param("s", $google_id);
    $stmt->execute();
    $result = $stmt->get_result();

    oauthLog('DB', 'Google ID lookup: ' . $result->num_rows . ' rows found');

    if ($result->num_rows > 0) {
        // Existing Google user - log them in
        $user = $result->fetch_assoc();
        $stmt->close();

        oauthLog('LOGIN', 'Found user by google_id: ' . $user['username'] . ' (ID: ' . $user['id'] . ')');

        // Block non-admin users during maintenance mode
        if ($is_maintenance && $user['role'] !== 'Admin') {
            oauthLog('FAIL', 'Maintenance mode - blocked non-admin');
            header("Location: login.php?error=oauth_maintenance");
            exit();
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['LAST_ACTIVITY'] = time();

        logActivity($user['id'], $user['username'], 'Google Login', 'User logged in via Google OAuth');
        try { trackUserSession($user['id']); } catch (Exception $e) {}

        oauthLog('SUCCESS', 'Logged in user ' . $user['username'] . ' - redirecting to dashboard');
        header("Location: dashboard.php");
        exit();
    }
    $stmt->close();

    // Check if user exists by email
    $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $google_email);
    $stmt->execute();
    $result = $stmt->get_result();

    oauthLog('DB', 'Email lookup (' . $google_email . '): ' . $result->num_rows . ' rows found');

    if ($result->num_rows > 0) {
        // Link Google account to existing user
        $user = $result->fetch_assoc();
        $stmt->close();

        oauthLog('LINK', 'Found user by email: ' . $user['username'] . ' - linking Google ID');

        // Block non-admin users during maintenance mode
        if ($is_maintenance && $user['role'] !== 'Admin') {
            oauthLog('FAIL', 'Maintenance mode - blocked non-admin');
            header("Location: login.php?error=oauth_maintenance");
            exit();
        }

        $update_stmt = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
        $update_stmt->bind_param("si", $google_id, $user['id']);
        $update_stmt->execute();
        $update_stmt->close();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['LAST_ACTIVITY'] = time();

        logActivity($user['id'], $user['username'], 'Google Login', 'Google account linked and logged in');
        try { trackUserSession($user['id']); } catch (Exception $e) {}

        oauthLog('SUCCESS', 'Linked and logged in user ' . $user['username'] . ' - redirecting to dashboard');
        header("Location: dashboard.php");
        exit();
    }
    $stmt->close();

    // ---- User does NOT exist - auto-create account ----
    oauthLog('CREATE', 'No existing user found - creating new account');

    // Block new signups during maintenance mode
    if ($is_maintenance) {
        oauthLog('FAIL', 'Maintenance mode - blocked new signup');
        header("Location: login.php?error=oauth_maintenance");
        exit();
    }

    // Auto-create account from both Login and Signup pages
    // (If user clicks "Login with Google" and has no account, one is created automatically)
    // Generate a username from email (part before @)
    $base_username = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $google_email)[0]);
    if (strlen($base_username) < 3) {
        $base_username = 'user_' . $base_username;
    }

    // Ensure unique username
    $username_candidate = $base_username;
    $counter = 1;
    while (true) {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username_candidate);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_stmt->close();

        if ($check_result->num_rows == 0) {
            break;
        }
        $username_candidate = $base_username . '_' . $counter;
        $counter++;
    }

    oauthLog('CREATE', 'Generated username: ' . $username_candidate);

    // Create user with a random password (they'll login via Google)
    $random_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $role = 'User';

    $insert_stmt = $conn->prepare("INSERT INTO users (username, password, email, role, google_id) VALUES (?, ?, ?, ?, ?)");
    $insert_stmt->bind_param("sssss", $username_candidate, $random_password, $google_email, $role, $google_id);

    if ($insert_stmt->execute()) {
        $new_user_id = $insert_stmt->insert_id;
        $insert_stmt->close();

        oauthLog('CREATE', 'User created with ID: ' . $new_user_id);

        // Auto-verify email for Google OAuth users (no OTP needed)
        $verify_stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
        $verify_stmt->bind_param("i", $new_user_id);
        $verify_stmt->execute();
        $verify_stmt->close();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $new_user_id;
        $_SESSION['username'] = $username_candidate;
        $_SESSION['role'] = $role;
        $_SESSION['LAST_ACTIVITY'] = time();

        logActivity($new_user_id, $username_candidate, 'Google Signup', 'New user registered via Google OAuth');
        try { trackUserSession($new_user_id); } catch (Exception $e) {}

        // Notify admins about new registration
        try {
            createNotificationForAdmins(
                'New User Registered (Google)',
                'User "' . htmlspecialchars($username_candidate) . '" signed up via Google OAuth (' . htmlspecialchars($google_email) . ')',
                'info',
                'users.php'
            );
        } catch (Exception $e) {}

        oauthLog('SUCCESS', 'New user created and logged in - redirecting to dashboard');
        header("Location: dashboard.php");
        exit();
    } else {
        $insert_stmt->close();
        oauthLog('FAIL', 'INSERT failed: ' . $conn->error);
        header("Location: login.php?error=oauth_create_failed");
        exit();
    }
} catch (Exception $e) {
    oauthLog('EXCEPTION', $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine());
    header("Location: login.php?error=oauth_error");
    exit();
}

/**
 * Find a usable CA certificate bundle for cURL SSL verification.
 * Returns the path if found, or false if not available.
 */
function getCACertPath() {
    // Check common locations for CA bundle
    $paths = [
        '/etc/ssl/certs/ca-certificates.crt',     // Debian/Ubuntu
        '/etc/pki/tls/certs/ca-bundle.crt',        // CentOS/RHEL
        '/etc/ssl/ca-bundle.pem',                    // OpenSUSE
        '/etc/pki/tls/cacert.pem',                   // Fedora
        '/usr/local/share/certs/ca-root-nss.crt',   // FreeBSD
        '/etc/ssl/cert.pem',                          // macOS
        ini_get('curl.cainfo'),                       // PHP configured path
        ini_get('openssl.cafile'),                     // OpenSSL configured path
    ];

    // Check for XAMPP's bundled cert at multiple levels
    // Project at htdocs/subfolder: dirname(__DIR__,2) = xampp root
    // Project at htdocs directly: dirname(__DIR__) = xampp root
    $check_levels = [dirname(__DIR__, 2), dirname(__DIR__)];
    foreach ($check_levels as $root) {
        $cert = $root . '/apache/bin/curl-ca-bundle.crt';
        if (file_exists($cert)) {
            return $cert;
        }
    }

    foreach ($paths as $path) {
        if (!empty($path) && file_exists($path)) {
            return $path;
        }
    }

    return false;
}
