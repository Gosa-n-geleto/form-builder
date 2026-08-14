<?php
// Start output buffering
ob_start();

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

function logError($message, $data = null) {
    $log = date('Y-m-d H:i:s') . " - INFO: " . $message;
    if ($data !== null) {
        $log .= " - DATA: " . print_r($data, true);
    }
    error_log($log . "\n", 3, __DIR__ . '/error_log.txt');
}

logError("=== public_form.php started ===");

require_once 'config.php';

// =============================================
// CSRF TOKEN FUNCTIONS - NOW USING CONFIG.PHP VERSIONS
// =============================================
// The generateCSRFToken(), validateCSRFToken(), and regenerateCSRFToken()
// functions are already defined in config.php
// DO NOT redeclare them here!

// Set session for form security if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$branding_early = getSiteBranding();

if (empty($token)) {
    http_response_code(404);
    showFormError($branding_early, 'Form Not Found', 'The form link is invalid or the token is missing. Please check the URL and try again.', 'fa-link-slash');
    exit();
}

$form_def = getCrudFormByToken($token);
if (!$form_def) {
    http_response_code(404);
    showFormError($branding_early, 'Form Not Found', 'This form is not available or has been deactivated. It may have been removed by the administrator.', 'fa-file-circle-xmark');
    exit();
}

function showFormError($branding, $title, $message, $icon = 'fa-circle-exclamation') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?> - <?php echo htmlspecialchars($branding['site_name']); ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="stylesheet" href="styles.css?v=7.0">
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; padding: 0; }
            .error-page {
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            body.dark-mode .error-page {
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            }
            .error-card {
                background: var(--bg-card, #fff);
                border-radius: 16px;
                box-shadow: 0 8px 32px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 480px;
                text-align: center;
                overflow: hidden;
            }
            .error-card-header {
                background: linear-gradient(135deg, #001f3f 0%, #003366 100%);
                padding: 30px 20px 24px;
            }
            .error-card-header img {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                border: 2px solid rgba(255,255,255,0.3);
                margin-bottom: 10px;
            }
            .error-card-header h2 {
                color: #fff;
                margin: 0;
                font-size: 16px;
                font-weight: 500;
                opacity: 0.9;
            }
            .error-card-body {
                padding: 40px 30px 36px;
            }
            .error-icon {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: linear-gradient(135deg, #fee2e2, #fecaca);
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
            }
            .error-icon i {
                font-size: 36px;
                color: #dc2626;
            }
            body.dark-mode .error-icon {
                background: linear-gradient(135deg, #451a1a, #5c1e1e);
            }
            body.dark-mode .error-icon i {
                color: #f87171;
            }
            .error-card-body h1 {
                margin: 0 0 10px;
                font-size: 22px;
                color: var(--text-primary, #1a1a2e);
                font-weight: 700;
            }
            .error-card-body p {
                margin: 0 0 24px;
                font-size: 14px;
                color: var(--text-muted, #6b7280);
                line-height: 1.6;
            }
            .error-actions {
                display: flex;
                gap: 10px;
                justify-content: center;
                flex-wrap: wrap;
            }
            .error-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 10px 20px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                border: none;
                transition: all 0.2s;
            }
            .error-btn-primary {
                background: #001f3f;
                color: #fff;
            }
            .error-btn-primary:hover { background: #003366; }
            .error-btn-secondary {
                background: var(--bg-secondary, #f3f4f6);
                color: var(--text-primary, #374151);
                border: 1px solid var(--border-color, #e5e7eb);
            }
            .error-btn-secondary:hover { background: var(--bg-hover, #e5e7eb); }
            .error-card-footer {
                padding: 14px 20px;
                border-top: 1px solid var(--border-color, #e5e7eb);
                font-size: 12px;
                color: var(--text-muted, #9ca3af);
            }
            @media (max-width: 500px) {
                .error-card { border-radius: 0; min-height: 100vh; display: flex; flex-direction: column; justify-content: center; }
                .error-card-body { padding: 32px 20px 28px; }
                .error-card-header { border-radius: 0; }
                .error-card-footer { margin-top: auto; }
            }
        </style>
    </head>
    <body>
        <div class="error-page">
            <div class="error-card">
                <div class="error-card-header">
                    <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo">
                    <h2><?php echo htmlspecialchars($branding['site_name']); ?></h2>
                </div>
                <div class="error-card-body">
                    <div class="error-icon">
                        <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                    </div>
                    <h1><?php echo htmlspecialchars($title); ?></h1>
                    <p><?php echo htmlspecialchars($message); ?></p>
                    <div class="error-actions">
                        <button class="error-btn error-btn-primary" onclick="location.reload()">
                            <i class="fas fa-rotate-right"></i> Try Again
                        </button>
                        <a href="login.php" class="error-btn error-btn-secondary">
                            <i class="fas fa-arrow-right-to-bracket"></i> Go to Login
                        </a>
                    </div>
                </div>
                <div class="error-card-footer">
                    Powered by <?php echo htmlspecialchars($branding['site_name']); ?>
                </div>
            </div>
        </div>
        <script>
            (function() {
                var saved = localStorage.getItem('theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (saved === 'dark' || (!saved && prefersDark)) document.body.classList.add('dark-mode');
            })();
        </script>
    </body>
    </html>
    <?php
}

$form_fields = json_decode($form_def['fields_json'], true) ?: [];
usort($form_fields, function($a, $b) { return ($a['order'] ?? 0) - ($b['order'] ?? 0); });

$form_steps = [];
if (!empty($form_def['form_steps'])) {
    $form_steps = json_decode($form_def['form_steps'], true) ?: [];
}
$is_multistep = count($form_steps) > 0;

$branding = getSiteBranding();

// =============================================
// AJAX: GET AVAILABLE APPOINTMENT SLOTS
// =============================================
if (isset($_GET['action']) && $_GET['action'] === 'getAvailableSlots' && isset($_GET['field_name']) && isset($_GET['date'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $field_name = trim($_GET['field_name']);
        $date = trim($_GET['date']);
        $form_id = $form_def['id'];

        $appt_config = null;
        foreach ($form_fields as $ff) {
            if ($ff['name'] === $field_name && $ff['type'] === 'appointment' && isset($ff['appointment_config'])) {
                $appt_config = $ff['appointment_config'];
                break;
            }
        }

        if (!$appt_config) {
            echo json_encode(['success' => false, 'message' => 'Appointment config not found']);
            exit();
        }

        $dayOfWeek = (int)date('w', strtotime($date));
        $daysOff = isset($appt_config['days_off']) ? $appt_config['days_off'] : [];
        if (in_array($dayOfWeek, $daysOff)) {
            echo json_encode(['success' => true, 'slots' => [], 'message' => 'This day is a day off']);
            exit();
        }

        $holidays = isset($appt_config['holidays']) ? $appt_config['holidays'] : [];
        if (in_array($date, $holidays)) {
            echo json_encode(['success' => true, 'slots' => [], 'message' => 'This date is a holiday']);
            exit();
        }

        $duration = intval($appt_config['slot_duration'] ?? 30);
        $startTime = strtotime($date . ' ' . ($appt_config['start_time'] ?? '09:00'));
        $endTime = strtotime($date . ' ' . ($appt_config['end_time'] ?? '17:00'));
        $breakStart = !empty($appt_config['break_start']) ? strtotime($date . ' ' . $appt_config['break_start']) : null;
        $breakEnd = !empty($appt_config['break_end']) ? strtotime($date . ' ' . $appt_config['break_end']) : null;
        $maxBookings = intval($appt_config['max_bookings'] ?? 1);

        $conn_appt = getDBConnection();
        
        $booked_counts = [];
        $result_slots = $conn_appt->query("SELECT data_json FROM crud_data WHERE form_id = " . (int)$form_id);
        if ($result_slots) {
            while ($row = $result_slots->fetch_assoc()) {
                $data = json_decode($row['data_json'], true);
                if (isset($data[$field_name])) {
                    $booking_val = $data[$field_name];
                    if (strpos($booking_val, $date) === 0) {
                        $slot_part = trim(substr($booking_val, strlen($date) + 1));
                        if (!isset($booked_counts[$slot_part])) $booked_counts[$slot_part] = 0;
                        $booked_counts[$slot_part]++;
                    }
                }
            }
            $result_slots->free();
        }

        $slots = [];
        $current = $startTime;
        while ($current + ($duration * 60) <= $endTime) {
            $slotEnd = $current + ($duration * 60);
            if ($breakStart && $breakEnd && $current >= $breakStart && $current < $breakEnd) {
                $current = $breakEnd;
                continue;
            }
            if ($breakStart && $breakEnd && $slotEnd > $breakStart && $current < $breakEnd) {
                $current = $breakEnd;
                continue;
            }
            $slotLabel = date('H:i', $current) . '-' . date('H:i', $slotEnd);
            $booked = isset($booked_counts[$slotLabel]) ? $booked_counts[$slotLabel] : 0;
            $slots[] = [
                'time' => $slotLabel,
                'start' => date('H:i', $current),
                'end' => date('H:i', $slotEnd),
                'booked' => $booked,
                'max' => $maxBookings,
                'available' => $booked < $maxBookings
            ];
            $current = $slotEnd;
        }

        echo json_encode(['success' => true, 'slots' => $slots]);
        exit();
        
    } catch (Exception $e) {
        logError("Appointment slots error", $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading slots']);
        exit();
    }
}

// =============================================
// PRE-RENDER VALIDATION
// =============================================
$form_blocked = false;
$block_reason = '';
$conn_check = getDBConnection();

// Check form scheduling
if (!empty($form_def['schedule_start']) && strtotime($form_def['schedule_start']) > time()) {
    $form_blocked = true;
    $block_reason = 'This form is not yet active. It opens on ' . date('M d, Y h:i A', strtotime($form_def['schedule_start'])) . '.';
}
if (!$form_blocked && !empty($form_def['schedule_end']) && strtotime($form_def['schedule_end']) < time()) {
    $form_blocked = true;
    $block_reason = 'This form has closed. It was available until ' . date('M d, Y h:i A', strtotime($form_def['schedule_end'])) . '.';
}

// Check form expiry
if (!$form_blocked && !empty($form_def['expires_at'])) {
    $expiry = new DateTime($form_def['expires_at']);
    $now = new DateTime();
    if ($now > $expiry) {
        $form_blocked = true;
        $block_reason = 'This form has expired and is no longer accepting submissions.';
    }
}

// Check submission limit
if (!$form_blocked && intval($form_def['max_submissions'] ?? 0) > 0) {
    $cnt_result = $conn_check->query("SELECT COUNT(*) as cnt FROM crud_data WHERE form_id = " . (int)$form_def['id']);
    $cnt = $cnt_result ? $cnt_result->fetch_assoc()['cnt'] : 0;
    if ($cnt_result) $cnt_result->free();
    if ($cnt >= intval($form_def['max_submissions'])) {
        $form_blocked = true;
        $block_reason = 'This form has reached its maximum number of submissions.';
    }
}

// Check one submission per IP
if (!$form_blocked && intval($form_def['one_per_user'] ?? 0) === 1) {
    $visitor_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $cnt_result = $conn_check->query("SELECT COUNT(*) as cnt FROM crud_data WHERE form_id = " . (int)$form_def['id'] . " AND ip_address = '" . $conn_check->real_escape_string($visitor_ip) . "' AND is_public_submission = 1");
    $cnt = $cnt_result ? $cnt_result->fetch_assoc()['cnt'] : 0;
    if ($cnt_result) $cnt_result->free();
    if ($cnt > 0) {
        $form_blocked = true;
        $block_reason = 'You have already submitted this form.';
    }
}

// Check password protection
$needs_password = false;
if (!$form_blocked && !empty($form_def['form_password'])) {
    if (!isset($_SESSION['form_unlocked_' . $form_def['id']]) || $_SESSION['form_unlocked_' . $form_def['id']] !== true) {
        $needs_password = true;
    }
}

// =============================================
// GENERATE CSRF TOKEN - USING CONFIG.PHP FUNCTION
// =============================================
$public_csrf = generateCSRFToken();
logError("CSRF token generated: " . substr($public_csrf, 0, 20) . "...");

// =============================================
// HANDLE PASSWORD VERIFICATION (POST)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_form_password'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    // Validate CSRF for password form
    $submitted_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!validateCSRFToken($submitted_token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit();
    }
    
    $entered_password = trim($_POST['form_password'] ?? '');
    if (hash_equals($form_def['form_password'], $entered_password)) {
        $_SESSION['form_unlocked_' . $form_def['id']] = true;
        // Regenerate token after successful password
        $new_token = regenerateCSRFToken();
        echo json_encode(['success' => true, 'token' => $new_token]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
    }
    exit();
}

// =============================================
// HANDLE FORM SUBMISSION (POST)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_public_form'])) {
    // Clear output buffer
    ob_clean();
    header('Content-Type: application/json');
    
    logError("=== FORM SUBMISSION STARTED ===");
    logError("POST data", $_POST);
    logError("FILES data", $_FILES);
    
    try {
        // =============================================
        // VALIDATE CSRF TOKEN - USING CONFIG.PHP FUNCTION
        // =============================================
        $submitted_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
        
        if (!validateCSRFToken($submitted_token)) {
            logError("CSRF validation failed - Token: " . substr($submitted_token, 0, 20) . "...");
            echo json_encode(['success' => false, 'message' => 'Invalid or missing security token. Please refresh the page and try again.']);
            exit();
        }
        
        logError("CSRF verified successfully");
        
        // =============================================
        // REGENERATE TOKEN AFTER SUCCESSFUL SUBMISSION
        // =============================================
        // This prevents token reuse
        $new_token = regenerateCSRFToken();
        logError("CSRF token regenerated after submission: " . substr($new_token, 0, 20) . "...");
        
        $conn = getDBConnection();
        $form_id = (int)$form_def['id'];
        $form_slug = $conn->real_escape_string($form_def['form_slug']);
        $ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        
        logError("Form ID: $form_id, IP: $ip");

        // --- VALIDATION CHECKS ---
        
        // Rate limiting
        $rate_result = $conn->query("SELECT COUNT(*) as cnt FROM crud_data WHERE form_id = $form_id AND ip_address = '$ip' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $rate_count = $rate_result ? (int)$rate_result->fetch_assoc()['cnt'] : 0;
        if ($rate_result) $rate_result->free();
        if ($rate_count >= 10) {
            logError("Rate limit exceeded: $rate_count");
            echo json_encode(['success' => false, 'message' => 'Too many submissions. Please try again later.']);
            exit();
        }
        logError("Rate limit check passed");

        // Scheduling
        if (!empty($form_def['schedule_start']) && strtotime($form_def['schedule_start']) > time()) {
            logError("Form not active yet");
            echo json_encode(['success' => false, 'message' => 'This form is not yet active.']);
            exit();
        }
        if (!empty($form_def['schedule_end']) && strtotime($form_def['schedule_end']) < time()) {
            logError("Form has closed");
            echo json_encode(['success' => false, 'message' => 'This form has closed.']);
            exit();
        }
        logError("Scheduling check passed");

        // Expiry
        if (!empty($form_def['expires_at'])) {
            $expiry = new DateTime($form_def['expires_at']);
            if (new DateTime() > $expiry) {
                logError("Form expired");
                echo json_encode(['success' => false, 'message' => 'This form has expired.']);
                exit();
            }
        }
        logError("Expiry check passed");

        // Submission limit
        if (intval($form_def['max_submissions'] ?? 0) > 0) {
            $cnt_result = $conn->query("SELECT COUNT(*) as cnt FROM crud_data WHERE form_id = $form_id");
            $cnt = $cnt_result ? (int)$cnt_result->fetch_assoc()['cnt'] : 0;
            if ($cnt_result) $cnt_result->free();
            if ($cnt >= intval($form_def['max_submissions'])) {
                logError("Submission limit reached: $cnt");
                echo json_encode(['success' => false, 'message' => 'This form has reached its maximum number of submissions.']);
                exit();
            }
        }
        logError("Submission limit check passed");

        // One per user
        if (intval($form_def['one_per_user'] ?? 0) === 1) {
            $cnt_result = $conn->query("SELECT COUNT(*) as cnt FROM crud_data WHERE form_id = $form_id AND ip_address = '$ip' AND is_public_submission = 1");
            $cnt = $cnt_result ? (int)$cnt_result->fetch_assoc()['cnt'] : 0;
            if ($cnt_result) $cnt_result->free();
            if ($cnt > 0) {
                logError("Already submitted: $cnt");
                echo json_encode(['success' => false, 'message' => 'You have already submitted this form.']);
                exit();
            }
        }
        logError("One per user check passed");

        // --- PROCESS FORM DATA ---
        $data = [];
        $submitted_name = 'Anonymous';
        $submitted_email = '';

        logError("Processing " . count($form_fields) . " fields");

        // Process each field
        foreach ($form_fields as $field) {
            $fname = $field['name'];
            $label = $field['label'] ?? $fname;
            
            // Skip file/upload fields - handled separately
            if (in_array($field['type'], ['file', 'signature', 'camera'])) {
                continue;
            }

            // Get value based on field type
            $value = '';
            if ($field['type'] === 'checkbox' && isset($field['options']) && count($field['options']) > 0) {
                if (isset($_POST[$fname]) && is_array($_POST[$fname])) {
                    $value = implode(',', $_POST[$fname]);
                } else {
                    $value = '';
                }
            } else {
                $value = isset($_POST[$fname]) ? trim($_POST[$fname]) : '';
            }

            // Store in data array
            $data[$fname] = $value;

            // Required validation
            if (!empty($field['required']) && empty($value)) {
                $error_msg = !empty($field['custom_error']) ? $field['custom_error'] : "Field \"$label\" is required";
                logError("Required field missing: $fname");
                echo json_encode(['success' => false, 'message' => $error_msg]);
                exit();
            }

            // Regex validation
            if (!empty($value) && !empty($field['regex'])) {
                $pattern = '/' . str_replace('/', '\\/', $field['regex']) . '/';
                if (!@preg_match($pattern, $value)) {
                    $error_msg = !empty($field['custom_error']) ? $field['custom_error'] : "$label does not match the required format";
                    logError("Regex validation failed for $fname: $value");
                    echo json_encode(['success' => false, 'message' => $error_msg]);
                    exit();
                }
            }

            // Unique validation - using regular query, no prepared statements
            if (!empty($value) && !empty($field['unique'])) {
                $escaped_value = $conn->real_escape_string($value);
                $uq_result = $conn->query("SELECT id FROM crud_data WHERE form_id = $form_id");
                $found_duplicate = false;
                if ($uq_result) {
                    while ($row = $uq_result->fetch_assoc()) {
                        $record_result = $conn->query("SELECT data_json FROM crud_data WHERE id = " . (int)$row['id']);
                        if ($record_result) {
                            $record = $record_result->fetch_assoc();
                            if ($record) {
                                $record_data = json_decode($record['data_json'], true);
                                if (isset($record_data[$fname]) && $record_data[$fname] === $value) {
                                    $found_duplicate = true;
                                    $record_result->free();
                                    break;
                                }
                            }
                            $record_result->free();
                        }
                    }
                    $uq_result->free();
                }
                if ($found_duplicate) {
                    $error_msg = !empty($field['custom_error']) ? $field['custom_error'] : "\"" . htmlspecialchars($value) . "\" already exists for $label. Please enter a unique value.";
                    logError("Duplicate value for $fname: $value");
                    echo json_encode(['success' => false, 'message' => $error_msg]);
                    exit();
                }
            }

            // Capture submitted name
            if (in_array($fname, ['full_name', 'name', 'ticket_subject']) && !empty($value)) {
                $submitted_name = $value;
            }
            
            // Capture submitted email
            if ($field['type'] === 'email' && !empty($value)) {
                $submitted_email = $value;
            }
        }
        
        logError("Data collected: " . json_encode($data));

        // --- HANDLE FILE UPLOADS ---
        $upload_dir_base = __DIR__ . '/uploads/crud/' . $form_slug . '/';
        if (!file_exists($upload_dir_base)) {
            logError("Creating upload directory: $upload_dir_base");
            mkdir($upload_dir_base, 0755, true);
        }

        // Process signature fields
        foreach ($form_fields as $field) {
            if ($field['type'] === 'signature' && isset($_FILES[$field['name']]) && $_FILES[$field['name']]['error'] === UPLOAD_ERR_OK) {
                $fname = $field['name'];
                $filename = $fname . '_' . time() . '_' . uniqid() . '.png';
                logError("Processing signature: $fname -> $filename");
                if (move_uploaded_file($_FILES[$fname]['tmp_name'], $upload_dir_base . $filename)) {
                    $data[$fname] = 'uploads/crud/' . $form_slug . '/' . $filename;
                    logError("Signature saved: " . $data[$fname]);
                } else {
                    logError("Failed to move signature file: $fname");
                }
            }
        }

        // Process camera fields
        foreach ($form_fields as $field) {
            if ($field['type'] === 'camera' && isset($_FILES[$field['name']]) && $_FILES[$field['name']]['error'] === UPLOAD_ERR_OK) {
                $fname = $field['name'];
                $filename = $fname . '_' . time() . '_' . uniqid() . '.jpg';
                logError("Processing camera: $fname -> $filename");
                if (move_uploaded_file($_FILES[$fname]['tmp_name'], $upload_dir_base . $filename)) {
                    $data[$fname] = 'uploads/crud/' . $form_slug . '/' . $filename;
                    logError("Camera saved: " . $data[$fname]);
                } else {
                    logError("Failed to move camera file: $fname");
                }
            }
        }

        // --- INSERT INTO DATABASE - USING REGULAR QUERY (NO PREPARED STATEMENTS) ---
        $data_json = $conn->real_escape_string(json_encode($data));
        $submitted_name_escaped = $conn->real_escape_string($submitted_name);
        $submitted_email_escaped = $conn->real_escape_string($submitted_email);
        
        logError("Data JSON: " . json_encode($data));
        logError("Inserting record using regular query...");
        
        $insert_query = "INSERT INTO crud_data (form_id, data_json, submitted_by, submitted_by_name, submitted_by_email, ip_address, is_public_submission) 
                         VALUES ($form_id, '$data_json', NULL, '$submitted_name_escaped', '$submitted_email_escaped', '$ip', 1)";
        
        logError("Insert Query: " . $insert_query);
        
        if (!$conn->query($insert_query)) {
            logError("Insert error: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit();
        }
        
        $record_id = $conn->insert_id;
        logError("Record inserted with ID: $record_id");

        // --- PROCESS FILE UPLOADS (regular file fields) - USING REGULAR QUERIES ---
        foreach ($form_fields as $field) {
            if ($field['type'] === 'file' && isset($_FILES[$field['name']]) && $_FILES[$field['name']]['error'] === UPLOAD_ERR_OK) {
                $fname = $field['name'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
                
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES[$fname]['tmp_name']);
                finfo_close($finfo);
                
                logError("Processing file: $fname, MIME: $mime");

                if (in_array($mime, $allowed_types)) {
                    $ext = strtolower(pathinfo($_FILES[$fname]['name'], PATHINFO_EXTENSION));
                    $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
                    $filename = $fname . '_' . $record_id . '_' . time() . '.' . $safe_ext;
                    
                    if (move_uploaded_file($_FILES[$fname]['tmp_name'], $upload_dir_base . $filename)) {
                        $relative_path = 'uploads/crud/' . $form_slug . '/' . $filename;
                        $data[$fname] = $relative_path;
                        logError("File saved: $relative_path");
                        
                        // Update data_json with file path - USING REGULAR QUERY
                        $data_json_updated = $conn->real_escape_string(json_encode($data));
                        $conn->query("UPDATE crud_data SET data_json = '$data_json_updated' WHERE id = $record_id");
                        
                        // Insert file record - USING REGULAR QUERY
                        $orig_name = $conn->real_escape_string($_FILES[$fname]['name']);
                        $fsize = (int)$_FILES[$fname]['size'];
                        $fname_escaped = $conn->real_escape_string($fname);
                        $relative_path_escaped = $conn->real_escape_string($relative_path);
                        $mime_escaped = $conn->real_escape_string($mime);
                        
                        $file_insert_query = "INSERT INTO crud_files (form_id, record_id, field_name, file_name, file_path, file_type, file_size) 
                                              VALUES ($form_id, $record_id, '$fname_escaped', '$orig_name', '$relative_path_escaped', '$mime_escaped', $fsize)";
                        
                        if (!$conn->query($file_insert_query)) {
                            logError("Failed to insert file record: " . $conn->error);
                        } else {
                            logError("File record inserted");
                        }
                    } else {
                        logError("Failed to move file: $fname");
                    }
                } else {
                    logError("File type not allowed: $mime");
                }
            }
        }

        // --- SEND NOTIFICATIONS ---
        logError("Sending notifications...");
        if (!empty($form_def['email_notify_admin'])) {
            createNotificationForAdmins('Public Submission', "New public submission on \"{$form_def['form_name']}\"", 'info', "crud_view.php?form={$form_slug}");
            logError("Admin notification sent");
        }

        // --- SUCCESS RESPONSE ---
        $response = ['success' => true, 'message' => 'Form submitted successfully! Reference: #' . $record_id];
        if (!empty($form_def['thank_you_message'])) {
            $response['thank_you_message'] = $form_def['thank_you_message'];
        }
        if (!empty($form_def['redirect_url'])) {
            $response['redirect_url'] = $form_def['redirect_url'];
        }
        
        logError("SUCCESS: " . json_encode($response));
        echo json_encode($response);
        exit();

    } catch (Exception $e) {
        logError("EXCEPTION: " . $e->getMessage());
        logError("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit();
    }
}
?>
<!-- HTML OUTPUT STARTS HERE -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?php echo htmlspecialchars($form_def['form_name']); ?> - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="styles.css?v=7.0">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; }
        .public-form-wrapper { min-height: 100vh; display: flex; justify-content: center; align-items: flex-start; padding: 0; }
        .public-form-container { background: var(--bg-card, #fff); width: 100%; min-height: 100vh; }
        .public-form-header { background: var(--navy-primary, #001f3f); color: white; padding: 32px 24px 28px; text-align: center; }
        .public-form-header img { width: 56px; height: 56px; border-radius: 50%; margin-bottom: 10px; border: 2px solid rgba(255,255,255,0.2); }
        .public-form-header h1 { margin: 0 0 4px; font-size: 20px; font-weight: 600; word-wrap: break-word; }
        .public-form-header p { margin: 0 auto; opacity: 0.7; font-size: 13px; max-width: 500px; }
        .public-form-body { padding: 28px 32px 32px; width: 100%; }
        .public-form-body .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px 20px; }
        .public-form-body .form-group { margin-bottom: 0; background: transparent; padding: 0; border: none; border-radius: 0; }
        .public-form-body .form-grid .form-group { grid-column: auto !important; }
        .public-form-body .form-grid .form-group:has(textarea) { grid-column: 1 / -1 !important; }
        .public-form-body .form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .public-form-body .form-group label i { margin-right: 5px; font-size: 12px; color: var(--navy-accent, #0074D9); }
        body.dark-mode .public-form-body .form-group label { color: #d1d5db; }
        .public-form-body .form-group input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="range"]):not([type="color"]):not([type="hidden"]),
        .public-form-body .form-group select,
        .public-form-body .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
            color: var(--text-primary, #111);
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: inherit;
            touch-action: manipulation;
        }
        .public-form-body .form-group input:focus,
        .public-form-body .form-group select:focus,
        .public-form-body .form-group textarea:focus {
            outline: none;
            border-color: var(--navy-primary, #001f3f);
            box-shadow: 0 0 0 2px rgba(0,31,63,0.10);
        }
        body.dark-mode .public-form-body .form-group input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="range"]):not([type="color"]):not([type="hidden"]),
        body.dark-mode .public-form-body .form-group select,
        body.dark-mode .public-form-body .form-group textarea {
            background: var(--input-bg, #1e1e1e);
            border-color: var(--input-border, #374151);
            color: var(--text-primary, #e5e7eb);
        }
        .public-form-body .form-group input[type="file"] {
            padding: 10px 12px;
            border: 1px dashed #d1d5db;
            border-radius: 8px;
            background: #fafafa;
            cursor: pointer;
            font-size: 14px;
            width: 100%;
            touch-action: manipulation;
        }
        .public-form-body .checkbox-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .public-form-body .checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            background: #f9fafb;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            color: var(--text-primary, #374151);
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .public-form-body .checkbox-label:hover { border-color: #9ca3af; background: #f3f4f6; }
        .public-form-body .checkbox-label:has(input:checked) {
            border-color: var(--navy-primary, #001f3f);
            background: rgba(0,31,63,0.04);
            color: var(--navy-primary, #001f3f);
            font-weight: 500;
        }
        .public-form-body .checkbox-label input[type="radio"],
        .public-form-body .checkbox-label input[type="checkbox"] { accent-color: var(--navy-primary, #001f3f); width: 16px; height: 16px; margin: 0; flex-shrink: 0; }
        .public-form-body .form-actions { margin-top: 28px; }
        .public-form-body .form-actions .btn-primary,
        .public-form-body #submitBtn {
            background: var(--navy-primary, #001f3f);
            border: none;
            border-radius: 8px;
            padding: 13px 28px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            transition: opacity 0.15s;
        }
        .public-form-body .form-actions .btn-primary:hover,
        .public-form-body #submitBtn:hover { opacity: 0.9; }
        .public-form-footer { padding: 14px 24px; text-align: center; border-top: 1px solid var(--border-color, #e5e7eb); font-size: 12px; color: var(--text-muted, #9ca3af); }
        .hp-field { position: absolute; left: -9999px; }
        .signature-canvas-container { width: 100%; }
        .signature-canvas { border: 1px solid #d1d5db; border-radius: 8px; cursor: crosshair; background: #fff; width: 100%; touch-action: none; display: block; }
        .signature-btn-row { display: flex; gap: 8px; margin-top: 6px; }
        .step-navigation { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 10px; }
        @media (max-width: 600px) {
            .public-form-header { padding: 24px 16px 20px; }
            .public-form-body { padding: 16px 12px 20px; }
            .public-form-body .form-grid { grid-template-columns: 1fr; gap: 18px; }
            .public-form-body .form-grid .form-group { grid-column: 1 / -1 !important; }
            .public-form-body .checkbox-group { flex-direction: column; gap: 6px; }
            .public-form-body .checkbox-label { padding: 10px 14px; min-height: 44px; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="public-form-wrapper">
        <?php if ($form_blocked): ?>
        <div class="public-form-container">
            <div class="public-form-header">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo">
                <h1><i class="<?php echo htmlspecialchars($form_def['form_icon']); ?>"></i> <?php echo htmlspecialchars($form_def['form_name']); ?></h1>
            </div>
            <div class="public-form-body" style="text-align:center;padding:60px 30px;">
                <i class="fas fa-ban" style="font-size:48px;color:#ea4335;margin-bottom:20px;display:block;"></i>
                <h2 style="color:var(--text-primary);margin-bottom:10px;">Form Unavailable</h2>
                <p style="color:var(--text-muted);font-size:16px;"><?php echo htmlspecialchars($block_reason); ?></p>
            </div>
            <div class="public-form-footer">Powered by <?php echo htmlspecialchars($branding['site_name']); ?></div>
        </div>
        <?php elseif ($needs_password): ?>
        <div class="public-form-container">
            <div class="public-form-header">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo">
                <h1><i class="fas fa-lock"></i> <?php echo htmlspecialchars($form_def['form_name']); ?></h1>
                <p>This form is password protected</p>
            </div>
            <div class="public-form-body" style="text-align:center;padding:40px 30px;">
                <i class="fas fa-key" style="font-size:48px;color:var(--navy-accent, #0074D9);margin-bottom:20px;display:block;"></i>
                <p style="margin-bottom:20px;color:var(--text-muted);">Please enter the password to access this form.</p>
                <form id="passwordForm" style="max-width:300px;margin:0 auto;">
                    <div class="form-group" style="margin-bottom:15px;">
                        <input type="password" id="formPasswordInput" placeholder="Enter password" required style="text-align:center;font-size:16px;">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;" id="pwdSubmitBtn">
                        <i class="fas fa-unlock"></i> Access Form
                    </button>
                </form>
            </div>
            <div class="public-form-footer">Powered by <?php echo htmlspecialchars($branding['site_name']); ?></div>
        </div>
        <?php else: ?>
        <!-- Normal Form -->
        <div class="public-form-container">
            <div class="public-form-header">
                <?php if (!empty($form_def['form_logo'])): ?>
                <img src="<?php echo htmlspecialchars($form_def['form_logo']); ?>" alt="Form Logo" style="max-height:60px;object-fit:contain;">
                <?php else: ?>
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo">
                <?php endif; ?>
                <h1><i class="<?php echo htmlspecialchars($form_def['form_icon']); ?>"></i> <?php echo htmlspecialchars($form_def['form_name']); ?></h1>
                <?php if ($form_def['form_description']): ?>
                <p><?php echo htmlspecialchars($form_def['form_description']); ?></p>
                <?php endif; ?>
            </div>

            <div class="public-form-body">
                <form id="publicForm" enctype="multipart/form-data">
                    <div class="hp-field">
                        <input type="text" name="website_url_hp" tabindex="-1" autocomplete="off">
                    </div>

                    <?php
                    // Group fields by step
                    $steps_map = [];
                    foreach ($form_fields as $field) {
                        $s = isset($field['step']) ? intval($field['step']) : 1;
                        $steps_map[$s][] = $field;
                    }
                    if (!$is_multistep) $steps_map = [1 => $form_fields];
                    ksort($steps_map);
                    ?>

                    <?php foreach ($steps_map as $step_num => $step_fields): ?>
                    <div class="<?php echo $is_multistep ? 'step-content' : ''; ?><?php echo (!$is_multistep || $step_num === 1) ? ' active' : ''; ?>" data-step="<?php echo $step_num; ?>">
                    <div class="form-grid" id="publicFields_<?php echo $step_num; ?>">
                        <?php foreach ($step_fields as $field):
                            $req = !empty($field['required']) ? 'required' : '';
                            $reqMark = !empty($field['required']) ? ' *' : '';
                            $widthStyle = ($field['width'] ?? 'half') === 'full' ? 'style="grid-column: 1 / -1;"' : '';
                            $placeholder = !empty($field['placeholder']) ? 'placeholder="' . htmlspecialchars($field['placeholder']) . '"' : '';
                            $iconHtml = !empty($field['icon']) ? '<i class="' . htmlspecialchars($field['icon']) . '"></i> ' : '';

                            if ($field['type'] === 'hidden'): ?>
                                <input type="hidden" name="<?php echo htmlspecialchars($field['name']); ?>" value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>">
                                <?php continue; endif; ?>

                            <div class="form-group" <?php echo $widthStyle; ?>>
                                <label><?php echo $iconHtml . htmlspecialchars($field['label']) . $reqMark; ?></label>

                                <?php if ($field['type'] === 'textarea'): ?>
                                    <textarea name="<?php echo htmlspecialchars($field['name']); ?>" rows="3" <?php echo $placeholder . ' ' . $req; ?>></textarea>

                                <?php elseif ($field['type'] === 'select'): ?>
                                    <select id="field_<?php echo htmlspecialchars($field['name']); ?>" name="<?php echo htmlspecialchars($field['name']); ?>" <?php echo $req; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach (($field['options'] ?? []) as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php elseif ($field['type'] === 'radio'): ?>
                                    <div class="checkbox-group">
                                        <?php foreach (($field['options'] ?? []) as $i => $opt): ?>
                                            <label class="checkbox-label"><input type="radio" name="<?php echo htmlspecialchars($field['name']); ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $i === 0 && !empty($field['required']) ? 'required' : ''; ?>> <?php echo htmlspecialchars($opt); ?></label>
                                        <?php endforeach; ?>
                                    </div>

                                <?php elseif ($field['type'] === 'checkbox'): ?>
                                    <div class="checkbox-group">
                                        <?php if (!empty($field['options'])): ?>
                                            <?php foreach ($field['options'] as $opt): ?>
                                                <label class="checkbox-label"><input type="checkbox" name="<?php echo htmlspecialchars($field['name']); ?>[]" value="<?php echo htmlspecialchars($opt); ?>"> <?php echo htmlspecialchars($opt); ?></label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <label class="checkbox-label"><input type="checkbox" name="<?php echo htmlspecialchars($field['name']); ?>" value="1"> <?php echo htmlspecialchars($field['label']); ?></label>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($field['type'] === 'file'): ?>
                                    <input type="file" name="<?php echo htmlspecialchars($field['name']); ?>" accept="<?php echo htmlspecialchars($field['accept'] ?? '*/*'); ?>" <?php echo $req; ?>>
                                    <small style="color:var(--text-muted)">Max: <?php echo $field['max_size'] ?? 2; ?>MB</small>

                                <?php elseif ($field['type'] === 'signature'): ?>
                                    <div class="signature-wrapper">
                                        <div class="signature-canvas-container" id="sigContainer_<?php echo htmlspecialchars($field['name']); ?>">
                                            <canvas id="sigCanvas_<?php echo htmlspecialchars($field['name']); ?>" class="signature-canvas"></canvas>
                                        </div>
                                        <div class="signature-btn-row">
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="clearSig('<?php echo htmlspecialchars($field['name']); ?>')"><i class="fas fa-eraser"></i> Clear</button>
                                        </div>
                                    </div>

                                <?php elseif ($field['type'] === 'appointment'): ?>
                                    <?php
                                    $apptConfig = isset($field['appointment_config']) ? $field['appointment_config'] : [];
                                    $advDays = isset($apptConfig['advance_days']) ? intval($apptConfig['advance_days']) : 30;
                                    $minDate = date('Y-m-d');
                                    $maxDate = date('Y-m-d', strtotime('+' . $advDays . ' days'));
                                    $fieldNameSafe = htmlspecialchars($field['name']);
                                    ?>
                                    <div class="appt-picker-wrapper" id="apptWrapper_<?php echo $fieldNameSafe; ?>">
                                        <input type="hidden" id="field_<?php echo $fieldNameSafe; ?>" name="<?php echo $fieldNameSafe; ?>" <?php echo $req; ?>>
                                        <div class="appt-date-row">
                                            <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;"><i class="fas fa-calendar" style="color:var(--navy-accent);margin-right:4px;"></i> Select Date</label>
                                            <input type="date" id="apptDate_<?php echo $fieldNameSafe; ?>" min="<?php echo $minDate; ?>" max="<?php echo $maxDate; ?>" onchange="loadAppointmentSlots('<?php echo $fieldNameSafe; ?>')" style="max-width:220px;">
                                        </div>
                                        <div id="apptSlotsContainer_<?php echo $fieldNameSafe; ?>" class="appt-slots-container" style="display:none;"></div>
                                        <div id="apptSelected_<?php echo $fieldNameSafe; ?>" class="appt-selected-slot" style="display:none;"></div>
                                    </div>

                                <?php else: ?>
                                    <input type="<?php echo htmlspecialchars($field['type']); ?>" name="<?php echo htmlspecialchars($field['name']); ?>" <?php echo $placeholder . ' ' . $req; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($is_multistep): ?>
                    <div class="step-navigation" id="stepNavigation">
                        <button type="button" class="btn btn-secondary" id="prevStepBtn" onclick="changeStep(-1)" style="display:none;">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <span class="step-indicator-text" id="stepIndicator">Step 1 of <?php echo count($form_steps); ?></span>
                        <button type="button" class="btn btn-primary" id="nextStepBtn" onclick="changeStep(1)">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn" style="display:none;min-width:140px;padding:12px;">
                            <i class="fas fa-paper-plane"></i> Submit
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="form-actions" style="margin-top:25px;">
                        <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Submit
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="public-form-footer">
                Powered by <?php echo htmlspecialchars($branding['site_name']); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>

    <script>
    // Theme toggle
    (function() {
        const saved = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (saved === 'dark' || (!saved && prefersDark)) {
            document.body.classList.add('dark-mode');
            var icon = document.getElementById('themeIcon');
            if (icon) icon.className = 'fas fa-sun';
        }
    })();
    function toggleTheme() {
        var isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        var icon = document.getElementById('themeIcon');
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    // =============================================
    // CSRF TOKEN MANAGEMENT
    // =============================================
    var csrfToken = '<?php echo $public_csrf; ?>';
    
    // Store token globally for use in AJAX requests
    window.csrfToken = csrfToken;
    console.log('CSRF Token loaded: ' + csrfToken.substring(0, 20) + '...');

    // =============================================
    // AJAX INTERCEPTOR FOR CSRF TOKEN
    // =============================================
    $(document).ajaxSend(function(event, jqxhr, settings) {
        // Only add for POST requests to public_form.php
        if (settings.type === 'POST' && settings.url.indexOf('public_form.php') !== -1) {
            // If FormData is used, append the token
            if (settings.data instanceof FormData) {
                // Check if token already exists
                var hasToken = false;
                for (var pair of settings.data.entries()) {
                    if (pair[0] === 'csrf_token') {
                        hasToken = true;
                        break;
                    }
                }
                if (!hasToken) {
                    settings.data.append('csrf_token', csrfToken);
                }
            }
            // If it's a regular POST, add it to the data
            else if (settings.data && typeof settings.data === 'string') {
                if (settings.data.indexOf('csrf_token=') === -1) {
                    var separator = settings.data.indexOf('?') === -1 ? '&' : '&';
                    settings.data += separator + 'csrf_token=' + encodeURIComponent(csrfToken);
                }
            }
            // If no data, create it
            else if (!settings.data) {
                settings.data = 'csrf_token=' + encodeURIComponent(csrfToken);
            }
        }
    });

    // Signature pads
    var sigPads = {};
    document.querySelectorAll('.signature-canvas').forEach(function(canvas) {
        var dpr = window.devicePixelRatio || 1;
        var container = canvas.parentElement;
        var containerWidth = container ? container.offsetWidth : 400;
        var canvasHeight = 150;

        canvas.width = containerWidth * dpr;
        canvas.height = canvasHeight * dpr;
        canvas.style.width = containerWidth + 'px';
        canvas.style.height = canvasHeight + 'px';

        var ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        var drawing = false;
        ctx.strokeStyle = '#001f3f';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        canvas.addEventListener('mousedown', function(e) { drawing = true; ctx.beginPath(); ctx.moveTo(e.offsetX, e.offsetY); });
        canvas.addEventListener('mousemove', function(e) { if (!drawing) return; ctx.lineTo(e.offsetX, e.offsetY); ctx.stroke(); });
        canvas.addEventListener('mouseup', function() { drawing = false; });
        canvas.addEventListener('mouseleave', function() { drawing = false; });
        canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var r = canvas.getBoundingClientRect(); var scaleX = canvas.width / dpr / r.width; var scaleY = canvas.height / dpr / r.height; ctx.beginPath(); ctx.moveTo((e.touches[0].clientX - r.left) * scaleX, (e.touches[0].clientY - r.top) * scaleY); }, { passive: false });
        canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; var r = canvas.getBoundingClientRect(); var scaleX = canvas.width / dpr / r.width; var scaleY = canvas.height / dpr / r.height; ctx.lineTo((e.touches[0].clientX - r.left) * scaleX, (e.touches[0].clientY - r.top) * scaleY); ctx.stroke(); }, { passive: false });
        canvas.addEventListener('touchend', function() { drawing = false; });

        var name = canvas.id.replace('sigCanvas_', '');
        sigPads[name] = { canvas: canvas, ctx: ctx, dpr: dpr };
    });

    function clearSig(name) {
        var pad = sigPads[name];
        if (pad) pad.ctx.clearRect(0, 0, pad.canvas.width / pad.dpr, pad.canvas.height / pad.dpr);
    }

    // Password form handler
    var pwdForm = document.getElementById('passwordForm');
    if (pwdForm) {
        pwdForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('pwdSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

            var fd = new FormData();
            fd.append('verify_form_password', '1');
            fd.append('form_password', document.getElementById('formPasswordInput').value);
            fd.append('csrf_token', csrfToken);

            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Update CSRF token if returned
                        if (response.token) {
                            csrfToken = response.token;
                            window.csrfToken = csrfToken;
                            console.log('CSRF Token updated after password verification');
                        }
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-unlock"></i> Access Form';
                        Swal.fire({ icon: 'error', title: 'Wrong Password', text: response.message });
                    }
                },
                error: function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-unlock"></i> Access Form';
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error.' });
                }
            });
        });
    }

    // Multi-step navigation
    var TOTAL_STEPS = <?php echo $is_multistep ? count($form_steps) : 1; ?>;
    var currentStep = 1;
    var IS_MULTISTEP = <?php echo $is_multistep ? 'true' : 'false'; ?>;

    function changeStep(dir) {
        var newStep = currentStep + dir;
        if (newStep < 1 || newStep > TOTAL_STEPS) return;

        if (dir > 0) {
            var currentContent = document.querySelector('.step-content[data-step="' + currentStep + '"]');
            if (currentContent) {
                var invalid = false;
                currentContent.querySelectorAll('[required]').forEach(function(el) {
                    var fg = el.closest('.form-group');
                    if (fg && fg.style.display === 'none') return;
                    if (!el.value || (el.type === 'radio' && !currentContent.querySelector('input[name="' + el.name + '"]:checked'))) {
                        el.reportValidity();
                        invalid = true;
                    }
                });
                if (invalid) return;
            }
        }

        var allSteps = document.querySelectorAll('.step-content');
        allSteps.forEach(function(s) { s.classList.remove('active'); });
        var target = document.querySelector('.step-content[data-step="' + newStep + '"]');
        if (target) target.classList.add('active');

        currentStep = newStep;
        updateStepUI();
    }

    function updateStepUI() {
        if (!IS_MULTISTEP) return;
        var prevBtn = document.getElementById('prevStepBtn');
        var nextBtn = document.getElementById('nextStepBtn');
        var submitBtn = document.getElementById('submitBtn');
        var indicator = document.getElementById('stepIndicator');
        if (prevBtn) prevBtn.style.display = currentStep > 1 ? '' : 'none';
        if (nextBtn) nextBtn.style.display = currentStep < TOTAL_STEPS ? '' : 'none';
        if (submitBtn) submitBtn.style.display = currentStep === TOTAL_STEPS ? '' : 'none';
        if (indicator) indicator.textContent = 'Step ' + currentStep + ' of ' + TOTAL_STEPS;
    }

    // Appointment slots
    function loadAppointmentSlots(fieldName) {
        var dateInput = document.getElementById('apptDate_' + fieldName);
        var slotsContainer = document.getElementById('apptSlotsContainer_' + fieldName);
        var selectedDisplay = document.getElementById('apptSelected_' + fieldName);
        var hiddenInput = document.getElementById('field_' + fieldName);

        if (!dateInput || !dateInput.value) {
            slotsContainer.style.display = 'none';
            selectedDisplay.style.display = 'none';
            hiddenInput.value = '';
            return;
        }

        slotsContainer.style.display = 'block';
        slotsContainer.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading slots...</div>';
        selectedDisplay.style.display = 'none';
        hiddenInput.value = '';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'public_form.php?token=<?php echo urlencode($form_def['public_token'] ?? ''); ?>&action=getAvailableSlots&field_name=' + encodeURIComponent(fieldName) + '&date=' + encodeURIComponent(dateInput.value));
        xhr.onload = function() {
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success && response.slots.length > 0) {
                    var html = '<div class="appt-slots-grid">';
                    response.slots.forEach(function(slot) {
                        var cls = slot.available ? 'appt-slot appt-slot-available' : 'appt-slot appt-slot-booked';
                        var onclick = slot.available ? ' onclick="selectAppointmentSlot(\'' + fieldName + '\',\'' + slot.time + '\',\'' + dateInput.value + '\')"' : '';
                        var statusIcon = slot.available ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';
                        html += '<div class="' + cls + '"' + onclick + '>' + statusIcon + ' <span class="appt-slot-time">' + slot.time + '</span></div>';
                    });
                    html += '</div>';
                    slotsContainer.innerHTML = html;
                } else {
                    slotsContainer.innerHTML = '<div class="appt-no-slots"><i class="fas fa-calendar-times"></i> No available slots</div>';
                }
            } catch(e) {
                slotsContainer.innerHTML = '<div class="appt-no-slots"><i class="fas fa-exclamation-triangle"></i> Error loading slots</div>';
            }
        };
        xhr.onerror = function() {
            slotsContainer.innerHTML = '<div class="appt-no-slots"><i class="fas fa-exclamation-triangle"></i> Connection error</div>';
        };
        xhr.send();
    }

    function selectAppointmentSlot(fieldName, slotTime, date) {
        var hiddenInput = document.getElementById('field_' + fieldName);
        var selectedDisplay = document.getElementById('apptSelected_' + fieldName);

        hiddenInput.value = date + ' ' + slotTime;
        selectedDisplay.style.display = 'flex';
        selectedDisplay.innerHTML = '<i class="fas fa-calendar-check" style="color:var(--navy-accent);"></i> <strong>' + date + '</strong> &nbsp; <i class="fas fa-clock" style="color:var(--navy-accent);"></i> <strong>' + slotTime + '</strong> <button type="button" class="btn btn-sm btn-secondary" onclick="clearAppointmentSlot(\'' + fieldName + '\')" style="margin-left:auto;font-size:11px;padding:2px 8px;"><i class="fas fa-times"></i></button>';
    }

    function clearAppointmentSlot(fieldName) {
        document.getElementById('field_' + fieldName).value = '';
        var selectedDisplay = document.getElementById('apptSelected_' + fieldName);
        if (selectedDisplay) selectedDisplay.style.display = 'none';
    }

    // ============================
    // FORM SUBMIT
    // ============================
    document.getElementById('publicForm').addEventListener('submit', function(e) {
        e.preventDefault();

        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

        var formData = new FormData(this);
        
        // Add CSRF token explicitly
        formData.append('submit_public_form', '1');
        formData.append('csrf_token', csrfToken);

        // Convert signature canvases to blobs
        Object.keys(sigPads).forEach(function(name) {
            var pad = sigPads[name];
            var canvas = pad.canvas;
            var ctx = pad.ctx;
            var dpr = pad.dpr;
            var imageData = ctx.getImageData(0, 0, canvas.width / dpr, canvas.height / dpr);
            var hasContent = false;
            for (var i = 0; i < imageData.data.length; i += 4) {
                if (imageData.data[i+3] > 0) { hasContent = true; break; }
            }
            if (hasContent) {
                var dataUrl = canvas.toDataURL('image/png');
                var byteString = atob(dataUrl.split(',')[1]);
                var mimeString = dataUrl.split(',')[0].split(':')[1].split(';')[0];
                var ab = new ArrayBuffer(byteString.length);
                var ia = new Uint8Array(ab);
                for (var i = 0; i < byteString.length; i++) ia[i] = byteString.charCodeAt(i);
                var blob = new Blob([ab], { type: mimeString });
                formData.append(name, blob, name + '.png');
            }
        });

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 60000,
            success: function(response) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit';
                if (response.success) {
                    var thankText = response.thank_you_message || response.message || 'Form submitted successfully!';
                    Swal.fire({
                        icon: 'success',
                        title: 'Submitted!',
                        text: thankText,
                        confirmButtonColor: '#001f3f'
                    }).then(function() {
                        // Reset form
                        document.getElementById('publicForm').reset();
                        Object.keys(sigPads).forEach(clearSig);
                        // Clear appointment selections
                        document.querySelectorAll('.appt-selected-slot').forEach(function(el) {
                            el.style.display = 'none';
                        });
                        document.querySelectorAll('.appt-slots-container').forEach(function(el) {
                            el.style.display = 'none';
                        });
                        // Reload page to get new CSRF token
                        location.reload();
                    });
                } else {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: response.message || 'An error occurred. Please try again.' 
                    });
                }
            },
            error: function(xhr, status, error) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit';
                
                // Enhanced error handling with console logging
                console.error('AJAX Error:', {
                    status: status,
                    error: error,
                    xhrStatus: xhr.status,
                    response: xhr.responseText
                });
                
                var errMsg = 'Connection error. Please try again.';
                try {
                    var jsonResp = JSON.parse(xhr.responseText);
                    if (jsonResp && jsonResp.message) errMsg = jsonResp.message;
                } catch(e) {
                    if (xhr.status === 403) {
                        errMsg = 'Security token expired. Please refresh the page.';
                    } else if (xhr.status === 500) {
                        errMsg = 'Server error. Please try again later.';
                    }
                }
                Swal.fire({ icon: 'error', title: 'Error', text: errMsg });
            }
        });
    });
    </script>
</body>
</html>