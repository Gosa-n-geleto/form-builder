<?php
/**
 * Developed by     Gosa Negeso
 * WhatsApp: https://wa.me/+251916028154 (For Custom Projects)
 */

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
$current_page = 'email';

// Auto-create email_logs table if not exists
try {
    $conn = getDBConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS email_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        username VARCHAR(50) NOT NULL,
        from_email VARCHAR(255) NOT NULL,
        from_name VARCHAR(255) NOT NULL,
        to_email VARCHAR(500) NOT NULL,
        cc_email VARCHAR(500) DEFAULT NULL,
        bcc_email VARCHAR(500) DEFAULT NULL,
        subject VARCHAR(500) NOT NULL,
        body TEXT NOT NULL,
        status ENUM('sent','failed') DEFAULT 'sent',
        error_message TEXT DEFAULT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("email_logs table creation error: " . $e->getMessage());
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    enforceCSRF();

    if ($_POST['action'] === 'getEmailDefaults') {
        try {
            $smtp_enabled = getSetting('smtp_enabled', '0');
            $from_email = getSetting('smtp_from_email', '');
            $from_name = getSetting('smtp_from_name', 'Dashboard System');

            echo json_encode([
                'success' => true,
                'data' => [
                    'smtp_enabled' => $smtp_enabled,
                    'from_email' => $from_email,
                    'from_name' => $from_name
                ]
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'sendComposedEmail') {
        try {
            $smtp_enabled = getSetting('smtp_enabled', '0');
            if ($smtp_enabled !== '1') {
                echo json_encode(['success' => false, 'message' => 'SMTP is not enabled. Ask your admin to configure SMTP settings first.']);
                exit();
            }

            $to_email = isset($_POST['to_email']) ? trim($_POST['to_email']) : '';
            $cc_email = isset($_POST['cc_email']) ? trim($_POST['cc_email']) : '';
            $bcc_email = isset($_POST['bcc_email']) ? trim($_POST['bcc_email']) : '';
            $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
            $body = isset($_POST['body']) ? trim($_POST['body']) : '';

            if (empty($to_email)) {
                echo json_encode(['success' => false, 'message' => 'To email is required']);
                exit();
            }
            if (empty($subject)) {
                echo json_encode(['success' => false, 'message' => 'Subject is required']);
                exit();
            }
            if (empty($body)) {
                echo json_encode(['success' => false, 'message' => 'Email body is required']);
                exit();
            }

            // Validate all email addresses
            $to_addresses = array_filter(array_map('trim', explode(',', $to_email)));
            $cc_addresses = !empty($cc_email) ? array_filter(array_map('trim', explode(',', $cc_email))) : [];
            $bcc_addresses = !empty($bcc_email) ? array_filter(array_map('trim', explode(',', $bcc_email))) : [];

            foreach ($to_addresses as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid To email: ' . htmlspecialchars($addr)]);
                    exit();
                }
            }
            foreach ($cc_addresses as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid CC email: ' . htmlspecialchars($addr)]);
                    exit();
                }
            }
            foreach ($bcc_addresses as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid BCC email: ' . htmlspecialchars($addr)]);
                    exit();
                }
            }

            // Get SMTP settings
            $host = getSetting('smtp_host', '');
            $port = intval(getSetting('smtp_port', '587'));
            $smtp_user = getSetting('smtp_username', '');
            $smtp_pass = getSetting('smtp_password', '');
            $from_email_addr = getSetting('smtp_from_email', '');
            $from_name = getSetting('smtp_from_name', 'Dashboard System');
            $encryption = getSetting('smtp_encryption', 'tls');

            if (empty($host) || empty($smtp_user) || empty($smtp_pass) || empty($from_email_addr)) {
                echo json_encode(['success' => false, 'message' => 'SMTP credentials not configured']);
                exit();
            }

            // Build branded HTML email
            $branding = getSiteBranding();
            $bodyHtml = nl2br(htmlspecialchars($body));
            $htmlEmail = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">'
                . '<div style="background:#001f3f;padding:20px;text-align:center;border-radius:8px 8px 0 0;">'
                . '<h1 style="color:#fff;margin:0;font-size:22px;">' . htmlspecialchars($branding['site_name']) . '</h1>'
                . '</div>'
                . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
                . '<h2 style="color:#333;margin-top:0;margin-bottom:20px;">' . htmlspecialchars($subject) . '</h2>'
                . '<div style="color:#555;font-size:15px;line-height:1.8;">' . $bodyHtml . '</div>'
                . '</div>'
                . '<div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #e9ecef;border-top:none;">'
                . '<p style="color:#999;font-size:12px;margin:0;">Sent from ' . htmlspecialchars($branding['site_name']) . '</p>'
                . '</div></div>';

            // Build recipient lists
            $all_recipients = array_merge($to_addresses, $cc_addresses, $bcc_addresses);
            $to_header = implode(', ', $to_addresses);
            $cc_header = !empty($cc_addresses) ? implode(', ', $cc_addresses) : '';

            // Send via SMTP
            $result = sendComposedEmailSMTP($host, $port, $smtp_user, $smtp_pass, $from_email_addr, $from_name, $encryption, $all_recipients, $to_header, $cc_header, $subject, $htmlEmail);

            // Log to database
            $conn = getDBConnection();
            $status = $result['success'] ? 'sent' : 'failed';
            $error_msg = $result['success'] ? null : $result['message'];
            $stmt = $conn->prepare("INSERT INTO email_logs (user_id, username, from_email, from_name, to_email, cc_email, bcc_email, subject, body, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssssss", $user_id, $username, $from_email_addr, $from_name, $to_email, $cc_email, $bcc_email, $subject, $body, $status, $error_msg);
            $stmt->execute();
            $stmt->close();

            logActivity($user_id, $username, 'Email Sent', 'To: ' . $to_email . ' | Subject: ' . $subject . ' | Status: ' . $status);

            echo json_encode($result);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'getSentEmails') {
        try {
            $conn = getDBConnection();

            if ($role === 'Admin') {
                $stmt = $conn->prepare("SELECT * FROM email_logs ORDER BY sent_at DESC LIMIT 500");
            } else {
                $stmt = $conn->prepare("SELECT * FROM email_logs WHERE user_id = ? ORDER BY sent_at DESC LIMIT 500");
                $stmt->bind_param("i", $user_id);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $emails = [];
            while ($row = $result->fetch_assoc()) {
                $row['sent_at_formatted'] = date('M d, Y H:i', strtotime($row['sent_at']));
                $emails[] = $row;
            }
            $stmt->close();

            echo json_encode(['success' => true, 'data' => $emails]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'deleteEmailLog') {
        try {
            $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
            if ($log_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid log ID']);
                exit();
            }

            $conn = getDBConnection();

            if ($role === 'Admin') {
                $stmt = $conn->prepare("DELETE FROM email_logs WHERE id = ?");
                $stmt->bind_param("i", $log_id);
            } else {
                $stmt = $conn->prepare("DELETE FROM email_logs WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $log_id, $user_id);
            }

            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();

            if ($deleted) {
                logActivity($user_id, $username, 'Email Log Deleted', 'Deleted email log #' . $log_id);
                echo json_encode(['success' => true, 'message' => 'Email log deleted']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Log not found or access denied']);
            }
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }
}

/**
 * Send email via SMTP with CC/BCC support
 */
function sendComposedEmailSMTP($host, $port, $smtpUser, $smtpPass, $from_email, $from_name, $encryption, $all_recipients, $to_header, $cc_header, $subject, $htmlBody) {
    try {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno, $errstr, 30,
            STREAM_CLIENT_CONNECT, $context
        );

        if (!$socket) {
            return ['success' => false, 'message' => "Connection failed: $errstr"];
        }

        stream_set_timeout($socket, 10);

        $readResponse = function() use ($socket) {
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (isset($line[3]) && ($line[3] === ' ' || $line[3] === "\r")) break;
            }
            return trim($response);
        };

        $sendCommand = function($cmd) use ($socket, $readResponse) {
            fputs($socket, $cmd . "\r\n");
            return $readResponse();
        };

        $readResponse(); // greeting
        $sendCommand('EHLO ' . gethostname());

        if ($encryption === 'tls') {
            $resp = $sendCommand('STARTTLS');
            if (strpos($resp, '220') === false) {
                fclose($socket);
                return ['success' => false, 'message' => 'STARTTLS failed'];
            }
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                fclose($socket);
                return ['success' => false, 'message' => 'TLS encryption failed'];
            }
            $sendCommand('EHLO ' . gethostname());
        }

        $sendCommand('AUTH LOGIN');
        $sendCommand(base64_encode($smtpUser));
        $authResp = $sendCommand(base64_encode($smtpPass));

        if (strpos($authResp, '235') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'SMTP authentication failed'];
        }

        $resp = $sendCommand("MAIL FROM:<$from_email>");
        if (strpos($resp, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Sender rejected by server'];
        }

        // RCPT TO for all recipients (To + CC + BCC)
        foreach ($all_recipients as $recipient) {
            $resp = $sendCommand("RCPT TO:<$recipient>");
            if (strpos($resp, '250') === false) {
                fclose($socket);
                return ['success' => false, 'message' => 'Recipient rejected: ' . $recipient];
            }
        }

        $resp = $sendCommand('DATA');
        if (strpos($resp, '354') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA command rejected'];
        }

        // Build message headers
        $msg = "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <$from_email>\r\n";
        $msg .= "To: $to_header\r\n";
        if (!empty($cc_header)) {
            $msg .= "Cc: $cc_header\r\n";
        }
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "Message-ID: <" . uniqid() . "@" . gethostname() . ">\r\n";
        $msg .= "\r\n";
        // RFC 5321 dot-stuffing: double any leading period in body lines
        $stuffed = str_replace("\r\n.", "\r\n..", $htmlBody);
        $msg .= $stuffed . "\r\n.\r\n";

        fputs($socket, $msg);
        $sendResp = $readResponse();

        $sendCommand('QUIT');
        fclose($socket);

        if (strpos($sendResp, '250') !== false) {
            return ['success' => true, 'message' => 'Email sent successfully!'];
        }

        return ['success' => false, 'message' => 'Email delivery failed'];

    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) fclose($socket);
        return ['success' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Send Email · Dashboard</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>

    <style>
        /* ============================================================
           EMAIL PAGE - WORLD-CLASS MODERN UI
           Same design language as Users & CRUD pages
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
            --text-muted: #64748b;
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
            background: linear-gradient(135deg, var(--accent-purple-bg), rgba(139, 92, 246, 0.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: var(--accent-purple);
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
            height: 2.5px; background: linear-gradient(90deg, var(--accent-purple), var(--accent-blue));
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
        .stat-card:hover .stat-icon { background: var(--accent-purple-bg); color: var(--accent-purple); }
        .stat-card .stat-value { font-size: 32px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.025em; line-height: 1.2; }

        /* Compose Card */
        .compose-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
            margin-bottom: 28px;
        }
        .compose-card:hover { border-color: var(--border-hover); }

        .compose-card .card-header {
            display: flex; align-items: center; gap: 14px;
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .compose-card .card-header .header-icon {
            width: 40px; height: 40px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            background: var(--accent-purple-bg);
            color: var(--accent-purple);
            flex-shrink: 0;
        }
        .compose-card .card-header .header-title {
            font-size: 16px; font-weight: 600; color: var(--text-primary);
        }
        .compose-card .card-header .header-subtitle {
            font-size: 13px; color: var(--text-muted);
        }

        .compose-card .card-body {
            padding: 24px;
        }

        /* Form Group */
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px; font-weight: 600;
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
        .form-group textarea {
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
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 4px var(--accent-blue-bg);
        }
        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: var(--text-muted);
        }
        .form-group textarea {
            min-height: 160px;
            resize: vertical;
            line-height: 1.7;
        }

        .form-group .from-display {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }
        .form-group .from-display i {
            color: var(--accent-blue);
            font-size: 18px;
        }
        .form-group .from-display .from-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        .form-group .from-display .from-email {
            color: var(--text-muted);
        }

        /* CC/BCC Toggle */
        .ccbcc-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            font-size: 13px;
            font-weight: 500;
            color: var(--accent-blue);
            cursor: pointer;
            border: none;
            background: none;
            transition: color var(--transition-fast);
        }
        .ccbcc-toggle:hover { color: var(--accent-blue-hover); }
        .ccbcc-toggle i { font-size: 14px; }

        .ccbcc-fields {
            display: none;
            animation: slideDown 0.3s ease;
            padding-top: 4px;
        }
        .ccbcc-fields.open { display: block; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .char-count {
            text-align: right;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* SMTP Warning */
        .smtp-warning {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            background: var(--accent-amber-bg);
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: var(--accent-amber);
            margin-bottom: 20px;
        }
        .smtp-warning i { font-size: 20px; flex-shrink: 0; margin-top: 2px; }
        .smtp-warning .warning-content { flex: 1; }
        .smtp-warning .warning-content strong { display: block; font-size: 14px; }
        .smtp-warning .warning-content p { margin: 2px 0 0; font-size: 13px; opacity: 0.8; }

        /* Status Badges */
        .badge-email {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 14px; border-radius: var(--radius-full);
            font-size: 12px; font-weight: 600;
        }
        .badge-email-sent {
            background: var(--accent-green-bg);
            color: var(--accent-green);
        }
        .badge-email-failed {
            background: var(--accent-red-bg);
            color: var(--accent-red);
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
            background: var(--accent-purple); color: #fff; border-radius: var(--radius-sm);
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--accent-purple); color: #fff;
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

        .action-icon {
            background: none; border: none; color: var(--text-muted);
            cursor: pointer; padding: 4px 8px; border-radius: var(--radius-sm);
            transition: all var(--transition-fast); font-size: 14px;
        }
        body:not(.dark-mode) .action-icon,
        [data-theme="light"] .action-icon {
            color: #94a3b8 !important;
        }
        .action-icon:hover { background: var(--bg-input); color: var(--text-primary); }
        .edit-icon:hover { color: var(--accent-blue); background: var(--accent-blue-bg); }
        .delete-icon:hover { color: var(--accent-red); background: var(--accent-red-bg); }

        /* Skeleton */
        .skeleton-card { background: var(--bg-card); border-radius: var(--radius-lg); padding: 20px 24px; border: 1px solid var(--border-color); }
        .skeleton { background: var(--bg-input); border-radius: var(--radius-sm); animation: skeletonPulse 1.5s ease-in-out infinite; }
        .skeleton-text { height: 16px; width: 60%; margin: 8px 0; }
        .skeleton-text-large { height: 24px; width: 40%; }
        .skeleton-w-50 { width: 50%; }
        .skeleton-w-70 { width: 70%; }
        .skeleton-w-100 { width: 100%; }
        .skeleton-mb-md { margin-bottom: 16px; }
        .skeleton-mb-sm { margin-bottom: 8px; }
        .skeleton-chart { height: 200px; border-radius: var(--radius-md); margin-top: 12px; }
        .skeleton-table { margin-top: 12px; }
        .skeleton-table-row { display: flex; gap: 12px; margin-bottom: 8px; }
        .skeleton-table-cell { height: 32px; border-radius: var(--radius-sm); }
        .skeleton-flex-1 { flex: 1; }
        .skeleton-flex-2 { flex: 2; }
        @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }

        .initially-hidden { display: none; }

        /* Form Actions */
        .form-actions {
            display: flex; gap: 12px; flex-wrap: wrap;
            padding-top: 8px;
        }
        .form-actions .btn { padding: 12px 28px; font-size: 15px; }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(12px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: overlayIn var(--transition-normal);
        }
        .modal-overlay.active { display: flex; }
        @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }
        .modal {
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            max-width: 600px;
            width: 100%;
            max-height: 92vh;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
            overflow: hidden;
            animation: modalIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            flex-direction: column;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.92) translateY(24px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            padding: 20px 28px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .modal-header h3 {
            font-size: 20px; font-weight: 700; margin: 0;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 12px;
            letter-spacing: -0.02em;
        }
        .modal-header h3 i { color: var(--accent-purple); }
        .modal-close {
            width: 36px; height: 36px; border-radius: var(--radius-md);
            border: none; background: var(--bg-input); color: var(--text-secondary);
            font-size: 18px; cursor: pointer; transition: all var(--transition-fast);
            display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { background: var(--accent-red-bg); color: var(--accent-red); }
        .modal-body { padding: 24px 28px; overflow-y: auto; flex: 1; }

        .record-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .detail-item {
            padding: 10px 14px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            border-left: 3px solid var(--accent-purple);
        }
        body:not(.dark-mode) .detail-item {
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        .detail-item .detail-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 3px;
            display: block;
        }
        .detail-item .detail-value {
            font-size: 13px;
            color: var(--text-primary);
            word-break: break-word;
        }
        body:not(.dark-mode) .detail-item .detail-value {
            color: #0f172a;
        }

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
            .compose-card .card-body { padding: 16px; }
            .table-toolbar { flex-direction: column; align-items: stretch; gap: 10px; padding: 14px 16px; }
            .table-toolbar .right { flex-wrap: wrap; }
            .table-wrapper .dataTables_wrapper { padding: 0 14px 16px; }
            .table-wrapper table.dataTable { font-size: 13px; }
            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td { padding: 10px 10px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; justify-content: center; }
            .record-details-grid { grid-template-columns: 1fr; }
            .modal { margin: 10px; max-height: 95vh; }
            .modal-header { padding: 18px 20px 12px; }
            .modal-body { padding: 18px 20px 22px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .stat-value { font-size: 18px; }
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .compose-card .card-header { flex-wrap: wrap; }
            .compose-card .card-header .header-title { font-size: 15px; }
            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td { padding: 6px 8px; font-size: 12px; }
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
                <span>Tools</span>
                <span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                <span class="current">Send Email</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-paper-plane"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>Send Email</h1>
                        <p class="subtitle">Compose and send emails using your configured SMTP server</p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadEmailDefaults(); loadSentEmails();" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Sent</span>
                        <span class="stat-icon"><i class="fas fa-paper-plane"></i></span>
                    </div>
                    <div class="stat-value" id="totalSent">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Successful</span>
                        <span class="stat-icon"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <div class="stat-value" id="successCount">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Failed</span>
                        <span class="stat-icon"><i class="fas fa-times-circle"></i></span>
                    </div>
                    <div class="stat-value" id="failedCount">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">SMTP Status</span>
                        <span class="stat-icon"><i class="fas fa-server"></i></span>
                    </div>
                    <div class="stat-value" id="smtpStatus" style="font-size:18px;">Checking...</div>
                </div>
            </div>

            <!-- Compose Email Card -->
            <div class="compose-card" id="composeCard">
                <div class="card-header">
                    <div class="header-icon"><i class="fas fa-pen-to-square"></i></div>
                    <div>
                        <div class="header-title">Compose Email</div>
                        <div class="header-subtitle">Send emails to one or more recipients</div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- SMTP Warning -->
                    <div id="smtpWarning" class="smtp-warning" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div class="warning-content">
                            <strong>SMTP Not Enabled</strong>
                            <p>Email sending requires SMTP to be configured.
                            <?php if ($role === 'Admin'): ?>
                                <a href="smtp_setup.php" style="color:var(--accent-blue);font-weight:600;text-decoration:none;">Configure SMTP</a>
                            <?php else: ?>
                                Please contact your administrator.
                            <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Form -->
                    <div class="form-group">
                        <label><i class="fas fa-user-circle"></i> From</label>
                        <div class="from-display">
                            <i class="fas fa-envelope"></i>
                            <span class="from-name" id="fromNameDisplay">Loading...</span>
                            <span class="from-email" id="fromEmailDisplay"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-at"></i> To <span class="required">*</span></label>
                        <input type="text" id="toEmail" placeholder="recipient@example.com (separate multiple with commas)">
                    </div>

                    <button class="ccbcc-toggle" onclick="toggleCcBcc()">
                        <i class="fas fa-plus-circle" id="ccbccIcon"></i>
                        <span id="ccbccText">Add CC / BCC</span>
                    </button>

                    <div class="ccbcc-fields" id="ccbccFields">
                        <div class="form-group">
                            <label><i class="fas fa-copy"></i> CC</label>
                            <input type="text" id="ccEmail" placeholder="cc@example.com (separate multiple with commas)">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-eye-slash"></i> BCC</label>
                            <input type="text" id="bccEmail" placeholder="bcc@example.com (separate multiple with commas)">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-heading"></i> Subject <span class="required">*</span></label>
                        <input type="text" id="emailSubject" placeholder="Enter email subject">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Message <span class="required">*</span></label>
                        <textarea id="emailBody" placeholder="Write your email message here..."></textarea>
                        <div class="char-count"><span id="charCount">0</span> characters</div>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary" onclick="sendEmail()" id="sendBtn">
                            <i class="fas fa-paper-plane"></i> Send Email
                        </button>
                        <button class="btn btn-ghost" onclick="clearForm()">
                            <i class="fas fa-eraser"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sent Emails Log -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="left">
                        <h3><i class="fas fa-history"></i> Sent Emails</h3>
                        <span class="count-badge" id="emailCountBadge">0 emails</span>
                    </div>
                    <div class="right">
                        <button class="btn btn-ghost btn-sm" onclick="loadSentEmails()" title="Refresh">
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

                <div class="table-scroll-hint">
                    <i class="fas fa-arrows-left-right"></i> Scroll to see all columns
                </div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table" style="padding:16px 24px;">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" class="initially-hidden">
                    <div class="table-responsive" style="overflow-x:auto;max-width:100%;">
                        <table id="emailsTable" class="display" style="width:100%;">
                            <thead><tr><th>#</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Email Modal -->
    <div class="modal-overlay" id="viewEmailModal" onclick="if(event.target===this)closeViewEmail()">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewEmailTitle"><i class="fas fa-envelope-open-text"></i> Email Details</h3>
                <button class="modal-close" onclick="closeViewEmail()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewEmailBody">
                <div id="viewEmailContent"></div>
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
    /* Developed by Muhafiz Ahmed */

    let emailsTable;
    let emailsData = [];
    let currentViewEmail = null;
    const isAdmin = <?php echo $role === 'Admin' ? 'true' : 'false'; ?>;

    // ==========================================
    // INIT
    // ==========================================
    $(document).ready(function() {
        loadEmailDefaults();
        loadSentEmails();

        // Character counter
        $('#emailBody').on('input', function() {
            $('#charCount').text(this.value.length);
        });
    });

    // ==========================================
    // LOAD EMAIL DEFAULTS
    // ==========================================
    function loadEmailDefaults() {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'getEmailDefaults' },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const d = response.data;

                    if (d.from_name && d.from_email) {
                        $('#fromNameDisplay').text(d.from_name);
                        $('#fromEmailDisplay').text('<' + d.from_email + '>');
                    } else {
                        $('#fromNameDisplay').text('Not configured');
                        $('#fromEmailDisplay').text('');
                    }

                    // SMTP Status
                    if (d.smtp_enabled === '1') {
                        $('#smtpWarning').hide();
                        $('#sendBtn').prop('disabled', false).css('opacity', '1');
                        $('#smtpStatus').text('✅ Enabled').css('color', 'var(--accent-green)');
                    } else {
                        $('#smtpWarning').show();
                        $('#sendBtn').prop('disabled', true).css('opacity', '0.5');
                        $('#smtpStatus').text('❌ Disabled').css('color', 'var(--accent-red)');
                    }
                }
            },
            error: function() {
                $('#fromNameDisplay').text('Error loading');
                $('#smtpStatus').text('⚠️ Error');
            }
        });
    }

    // ==========================================
    // LOAD SENT EMAILS
    // ==========================================
    function loadSentEmails() {
        $('#loadingSkeleton').show();
        $('#tableContainer').hide();

        $.ajax({
            url: '', method: 'POST',
            data: { action: 'getSentEmails' },
            dataType: 'json',
            success: function(response) {
                $('#loadingSkeleton').hide();
                $('#tableContainer').show();

                if (response.success) {
                    emailsData = response.data;
                    updateStats(response.data);
                    initEmailsTable(response.data);
                }
            },
            error: function() {
                $('#loadingSkeleton').hide();
                $('#tableContainer').show();
            }
        });
    }

    function updateStats(data) {
        const total = data.length;
        const sent = data.filter(e => e.status === 'sent').length;
        const failed = data.filter(e => e.status === 'failed').length;

        $('#totalSent').text(total);
        $('#successCount').text(sent);
        $('#failedCount').text(failed);
        $('#emailCountBadge').text(total + ' email' + (total !== 1 ? 's' : ''));
    }

    // ==========================================
    // INIT TABLE
    // ==========================================
    function initEmailsTable(data) {
        if (emailsTable) {
            emailsTable.destroy();
            $('#emailsTable').empty();
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
                data: 'to_email',
                title: 'To',
                render: function(d) {
                    var escaped = escapeHtml(d);
                    return '<span title="' + escaped + '">' + truncate(escaped, 30) + '</span>';
                }
            },
            {
                data: 'subject',
                title: 'Subject',
                render: function(d) {
                    var escaped = escapeHtml(d);
                    return '<span title="' + escaped + '">' + truncate(escaped, 35) + '</span>';
                }
            },
            {
                data: 'status',
                title: 'Status',
                className: 'dt-center',
                render: function(d) {
                    if (d === 'sent') {
                        return '<span class="badge-email badge-email-sent"><i class="fas fa-check-circle"></i> Sent</span>';
                    }
                    return '<span class="badge-email badge-email-failed"><i class="fas fa-times-circle"></i> Failed</span>';
                }
            }
        ];

        if (isAdmin) {
            columns.splice(3, 0, {
                data: 'username',
                title: 'Sent By',
                render: function(d) {
                    return '<span style="font-weight:500;">' + escapeHtml(d) + '</span>';
                }
            });
        }

        columns.push(
            {
                data: 'sent_at_formatted',
                title: 'Date',
                className: 'dt-center',
                width: '100px'
            },
            {
                data: null,
                title: 'Actions',
                orderable: false,
                className: 'dt-center',
                width: '80px',
                render: function(d, t, row) {
                    return '<button class="action-icon edit-icon" onclick="viewEmail(' + row.id + ')" title="View Email"><i class="fas fa-eye"></i></button>' +
                           '<button class="action-icon delete-icon" onclick="deleteEmailLog(' + row.id + ')" title="Delete"><i class="fas fa-trash-alt"></i></button>';
                }
            }
        );

        var theadHtml = '<tr>';
        columns.forEach(function(col) { theadHtml += '<th>' + (col.title || '') + '</th>'; });
        theadHtml += '</tr>';
        $('#emailsTable thead').html(theadHtml);
        $('#emailsTable tbody').html('');

        emailsTable = $('#emailsTable').DataTable({
            data: data,
            destroy: true,
            columns: columns,
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            autoWidth: false,
            order: [[0, 'desc']],
            columnDefs: [{ targets: 0, orderable: false }],
            dom: '<"top"lf>rt<"bottom"ip>',
            language: {
                emptyTable: '<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:40px;margin-bottom:10px;display:block;opacity:0.5;"></i>No emails sent yet</div>',
                info: 'Showing _START_ to _END_ of _TOTAL_ emails',
                infoEmpty: 'No emails available',
                infoFiltered: '(filtered from _MAX_ total emails)'
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

    // ==========================================
    // EXPORT DATA
    // ==========================================
    function exportData(type) {
        if (!emailsTable) {
            Swal.fire({ icon: 'warning', title: 'Table not loaded', text: 'Please wait for emails to load.' });
            return;
        }
        try {
            var buttons = emailsTable.buttons();
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
    // TOGGLE CC/BCC
    // ==========================================
    function toggleCcBcc() {
        const fields = document.getElementById('ccbccFields');
        const icon = document.getElementById('ccbccIcon');
        const text = document.getElementById('ccbccText');

        if (fields.classList.contains('open')) {
            fields.classList.remove('open');
            icon.className = 'fas fa-plus-circle';
            text.textContent = 'Add CC / BCC';
        } else {
            fields.classList.add('open');
            icon.className = 'fas fa-minus-circle';
            text.textContent = 'Hide CC / BCC';
        }
    }

    // ==========================================
    // SEND EMAIL
    // ==========================================
    function sendEmail() {
        const to = $('#toEmail').val().trim();
        const cc = $('#ccEmail').val().trim();
        const bcc = $('#bccEmail').val().trim();
        const subject = $('#emailSubject').val().trim();
        const body = $('#emailBody').val().trim();

        if (!to) {
            Swal.fire({ icon: 'warning', title: 'Missing Recipient', text: 'Please enter at least one email in the To field.' });
            return;
        }
        if (!subject) {
            Swal.fire({ icon: 'warning', title: 'Missing Subject', text: 'Please enter a subject for your email.' });
            return;
        }
        if (!body) {
            Swal.fire({ icon: 'warning', title: 'Missing Message', text: 'Please write a message body.' });
            return;
        }

        // Confirm send
        Swal.fire({
            title: 'Send Email?',
            html: '<div style="text-align:left;font-size:14px;">' +
                '<p><strong>To:</strong> ' + escapeHtml(to) + '</p>' +
                (cc ? '<p><strong>CC:</strong> ' + escapeHtml(cc) + '</p>' : '') +
                (bcc ? '<p><strong>BCC:</strong> ' + escapeHtml(bcc) + '</p>' : '') +
                '<p><strong>Subject:</strong> ' + escapeHtml(subject) + '</p>' +
                '</div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: 'var(--accent-blue)',
            confirmButtonText: '<i class="fas fa-paper-plane"></i> Send Now',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then((result) => {
            if (result.isConfirmed) {
                doSendEmail(to, cc, bcc, subject, body);
            }
        });
    }

    function doSendEmail(to, cc, bcc, subject, body) {
        Swal.fire({
            title: 'Sending Email...',
            text: 'Please wait while your email is being sent',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: '', method: 'POST',
            data: {
                action: 'sendComposedEmail',
                to_email: to,
                cc_email: cc,
                bcc_email: bcc,
                subject: subject,
                body: body
            },
            dataType: 'json',
            timeout: 60000,
            success: function(response) {
                Swal.close();
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Email Sent!',
                        text: response.message,
                        timer: 2500,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                    clearForm();
                    loadSentEmails();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Send Failed',
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
                    text: 'Request timed out. Check your SMTP settings.',
                    background: 'var(--bg-card)',
                    color: 'var(--text-primary)'
                });
            }
        });
    }

    // ==========================================
    // CLEAR FORM
    // ==========================================
    function clearForm() {
        $('#toEmail').val('');
        $('#ccEmail').val('');
        $('#bccEmail').val('');
        $('#emailSubject').val('');
        $('#emailBody').val('');
        $('#charCount').text('0');

        // Close CC/BCC if open
        const fields = document.getElementById('ccbccFields');
        if (fields.classList.contains('open')) {
            toggleCcBcc();
        }
    }

    // ==========================================
    // VIEW EMAIL DETAIL
    // ==========================================
    function viewEmail(id) {
        const email = emailsData.find(e => e.id == id);
        if (!email) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Email not found' });
            return;
        }

        currentViewEmail = email;
        document.getElementById('viewEmailTitle').innerHTML = '<i class="fas fa-envelope-open-text"></i> ' + escapeHtml(email.subject);

        const ccLine = email.cc_email ? '<div class="detail-item"><span class="detail-label">CC</span><span class="detail-value">' + escapeHtml(email.cc_email) + '</span></div>' : '';
        const bccLine = email.bcc_email ? '<div class="detail-item"><span class="detail-label">BCC</span><span class="detail-value">' + escapeHtml(email.bcc_email) + '</span></div>' : '';
        const errorLine = email.error_message ? '<div class="detail-item" style="border-left-color:var(--accent-red);"><span class="detail-label">Error</span><span class="detail-value" style="color:var(--accent-red);">' + escapeHtml(email.error_message) + '</span></div>' : '';

        const html = `
            <div class="record-details-grid">
                <div class="detail-item"><span class="detail-label">From</span><span class="detail-value">${escapeHtml(email.from_name)} &lt;${escapeHtml(email.from_email)}&gt;</span></div>
                <div class="detail-item"><span class="detail-label">To</span><span class="detail-value">${escapeHtml(email.to_email)}</span></div>
                ${ccLine}
                ${bccLine}
                <div class="detail-item"><span class="detail-label">Date</span><span class="detail-value">${escapeHtml(email.sent_at_formatted)}</span></div>
                <div class="detail-item"><span class="detail-label">Status</span><span class="detail-value">${email.status === 'sent' ? '<span style="color:var(--accent-green);font-weight:600;"><i class="fas fa-check-circle"></i> Sent</span>' : '<span style="color:var(--accent-red);font-weight:600;"><i class="fas fa-times-circle"></i> Failed</span>'}</span></div>
                ${isAdmin ? '<div class="detail-item"><span class="detail-label">Sent By</span><span class="detail-value">' + escapeHtml(email.username) + '</span></div>' : ''}
                ${errorLine}
            </div>
            <div style="margin-top:16px;padding:16px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border-color);white-space:pre-wrap;line-height:1.8;color:var(--text-primary);max-height:300px;overflow-y:auto;">${escapeHtml(email.body)}</div>
        `;

        document.getElementById('viewEmailContent').innerHTML = html;
        document.getElementById('viewEmailModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeViewEmail() {
        document.getElementById('viewEmailModal').classList.remove('active');
        document.body.style.overflow = '';
        currentViewEmail = null;
    }

    // ==========================================
    // DELETE EMAIL LOG
    // ==========================================
    function deleteEmailLog(id) {
        Swal.fire({
            title: 'Delete Email Log?',
            text: 'This will permanently remove this email log entry.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'deleteEmailLog', log_id: id },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false,
                                background: 'var(--bg-card)',
                                color: 'var(--text-primary)'
                            });
                            loadSentEmails();
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
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to delete email log',
                            background: 'var(--bg-card)',
                            color: 'var(--text-primary)'
                        });
                    }
                });
            }
        });
    }

    // ==========================================
    // KEYBOARD SHORTCUTS
    // ==========================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeViewEmail();
        }
        // Ctrl+Enter to send
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            sendEmail();
        }
    });

    // ==========================================
    // UTILITIES
    // ==========================================
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function truncate(str, maxLen) {
        if (!str) return '';
        return str.length > maxLen ? str.substring(0, maxLen) + '...' : str;
    }

    console.log('🚀 Email page loaded successfully');
    console.log('⌨️ Shortcut: Ctrl+Enter to send');
    </script>
</body>
</html>
