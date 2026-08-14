<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'User';
$user_id = $_SESSION['user_id'];

// Get form slug from URL
$form_slug = isset($_GET['form']) ? trim($_GET['form']) : '';
if (empty($form_slug)) { header("Location: dashboard.php"); exit(); }

// Load form definition
$form_def = getCrudForm($form_slug);
if (!$form_def) { header("Location: dashboard.php"); exit(); }

// Check access
$access = $form_def['access_type'];
if ($access === 'admin' && $role !== 'Admin') { header("Location: dashboard.php"); exit(); }
if ($access === 'user' && $role !== 'User') { header("Location: dashboard.php"); exit(); }

$current_page = 'crud_' . $form_slug;
$user_theme = getUserTheme($user_id);
$form_fields = json_decode($form_def['fields_json'], true) ?: [];
usort($form_fields, function($a, $b) { return ($a['order'] ?? 0) - ($b['order'] ?? 0); });

$can_edit_roles = json_decode($form_def['can_edit_roles'] ?? '["Admin"]', true) ?: ['Admin'];
$can_delete_roles = json_decode($form_def['can_delete_roles'] ?? '["Admin"]', true) ?: ['Admin'];
$can_edit = in_array($role, $can_edit_roles);
$can_delete = in_array($role, $can_delete_roles);

// Per-user edit access
if (!empty($form_def['editable_by_users']) && $role !== 'Admin') {
    $editable_users = json_decode($form_def['editable_by_users'], true);
    if (is_array($editable_users) && !in_array((string)$user_id, array_map('strval', $editable_users))) {
        $can_edit = false;
    }
}

// Get chart-enabled fields for dashboard
$chart_fields = [];
foreach ($form_fields as $f) {
    if (!empty($f['chart_enabled']) && !empty($f['chart_type'])) {
        $chart_fields[] = $f;
    }
}

// Generate CSRF token once and store in session if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// =============================================
// AJAX HANDLERS - FIXED CSRF
// =============================================
if (isset($_GET['action']) || isset($_POST['action'])) {
    ob_start();
    header('Content-Type: application/json');
    
    $action = isset($_GET['action']) ? $_GET['action'] : $_POST['action'];
    
    // Only enforce CSRF for POST requests that modify data
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['addRecord', 'updateRecord', 'deleteRecord'])) {
        // Check both header and POST parameter for CSRF token
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = isset($headers['X-CSRF-TOKEN']) ? $headers['X-CSRF-TOKEN'] : '';
        
        // Also check POST parameter as fallback
        if (empty($token) && isset($_POST['csrf_token'])) {
            $token = $_POST['csrf_token'];
        }
        
        if (empty($token) || $token !== $_SESSION['csrf_token']) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
            exit();
        }
    }

    try {
        $conn = getDBConnection();
        $form_id = intval($form_def['id']);

        // GET RECORDS
        if ($action === 'getRecords') {
            $query = "SELECT cd.*, u.username as submitted_by_username FROM crud_data cd LEFT JOIN users u ON cd.submitted_by = u.id WHERE cd.form_id = " . $form_id . " ORDER BY cd.id DESC";
            $result = mysqli_query($conn, $query);
            $records = [];
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['data'] = json_decode($row['data_json'], true) ?: [];
                    $row['created_at'] = date('Y-m-d\TH:i:s\Z', strtotime($row['created_at']));
                    $row['updated_at'] = $row['updated_at'] ? date('Y-m-d\TH:i:s\Z', strtotime($row['updated_at'])) : null;

                    $fquery = "SELECT field_name, file_name, file_path, file_type FROM crud_files WHERE record_id = " . intval($row['id']);
                    $fres = mysqli_query($conn, $fquery);
                    $files = [];
                    if ($fres) {
                        while ($frow = mysqli_fetch_assoc($fres)) {
                            $files[$frow['field_name']] = $frow;
                        }
                        mysqli_free_result($fres);
                    }
                    $row['files'] = $files;
                    $records[] = $row;
                }
                mysqli_free_result($result);
            }
            ob_end_clean();
            echo json_encode(['success' => true, 'data' => $records]);
            exit();
        }

        // GET CHART DATA
        if ($action === 'getChartData') {
            $field_name = isset($_GET['field']) ? trim($_GET['field']) : '';
            $chart_type = isset($_GET['type']) ? trim($_GET['type']) : 'bar';
            
            if (empty($field_name)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Field name required']);
                exit();
            }

            $field_def = null;
            foreach ($form_fields as $f) {
                if ($f['name'] === $field_name) {
                    $field_def = $f;
                    break;
                }
            }
            if (!$field_def) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Field not found']);
                exit();
            }

            $json_path = '$.' . $field_name;
            
            if (in_array($field_def['type'], ['select', 'radio'])) {
                $options = $field_def['options'] ?? [];
                $labels = [];
                $data = [];
                
                foreach ($options as $opt) {
                    $escaped_opt = mysqli_real_escape_string($conn, $opt);
                    $query = "SELECT COUNT(*) as count FROM crud_data WHERE form_id = " . $form_id . " AND JSON_UNQUOTE(JSON_EXTRACT(data_json, '{$json_path}')) = '{$escaped_opt}'";
                    $result = mysqli_query($conn, $query);
                    $count = 0;
                    if ($result) {
                        $row = mysqli_fetch_assoc($result);
                        $count = intval($row['count']);
                        mysqli_free_result($result);
                    }
                    $labels[] = $opt;
                    $data[] = $count;
                }
                ob_end_clean();
                echo json_encode(['success' => true, 'labels' => $labels, 'data' => $data, 'type' => $chart_type]);
                exit();
            }

            if ($field_def['type'] === 'date') {
                $query = "SELECT JSON_UNQUOTE(JSON_EXTRACT(data_json, '{$json_path}')) as val, COUNT(*) as count, DATE(created_at) as date FROM crud_data WHERE form_id = " . $form_id . " AND JSON_UNQUOTE(JSON_EXTRACT(data_json, '{$json_path}')) IS NOT NULL GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30";
                $result = mysqli_query($conn, $query);
                $labels = [];
                $data = [];
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $labels[] = $row['date'];
                        $data[] = intval($row['count']);
                    }
                    mysqli_free_result($result);
                }
                ob_end_clean();
                echo json_encode(['success' => true, 'labels' => $labels, 'data' => $data, 'type' => $chart_type]);
                exit();
            }

            if (in_array($field_def['type'], ['number', 'range'])) {
                $query = "SELECT JSON_UNQUOTE(JSON_EXTRACT(data_json, '{$json_path}')) as val FROM crud_data WHERE form_id = " . $form_id . " AND JSON_UNQUOTE(JSON_EXTRACT(data_json, '{$json_path}')) IS NOT NULL";
                $result = mysqli_query($conn, $query);
                $values = [];
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $v = floatval($row['val']);
                        if ($v > 0) $values[] = $v;
                    }
                    mysqli_free_result($result);
                }
                ob_end_clean();
                echo json_encode(['success' => true, 'data' => $values, 'type' => $chart_type]);
                exit();
            }

            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Unsupported field type for charts']);
            exit();
        }

        // GET AVAILABLE APPOINTMENT SLOTS
        if ($action === 'getAvailableSlots' && isset($_GET['field_name']) && isset($_GET['date'])) {
            $field_name = trim($_GET['field_name']);
            $date = trim($_GET['date']);

            $appt_config = null;
            foreach ($form_fields as $ff) {
                if ($ff['name'] === $field_name && $ff['type'] === 'appointment' && isset($ff['appointment_config'])) {
                    $appt_config = $ff['appointment_config'];
                    break;
                }
            }

            if (!$appt_config) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Appointment config not found']);
                exit();
            }

            $dayOfWeek = (int)date('w', strtotime($date));
            $daysOff = isset($appt_config['days_off']) ? $appt_config['days_off'] : [];
            if (in_array($dayOfWeek, $daysOff)) {
                ob_end_clean();
                echo json_encode(['success' => true, 'slots' => [], 'message' => 'This day is a day off']);
                exit();
            }

            $holidays = isset($appt_config['holidays']) ? $appt_config['holidays'] : [];
            if (in_array($date, $holidays)) {
                ob_end_clean();
                echo json_encode(['success' => true, 'slots' => [], 'message' => 'This date is a holiday']);
                exit();
            }

            $duration = intval($appt_config['slot_duration'] ?? 30);
            $startTime = strtotime($date . ' ' . ($appt_config['start_time'] ?? '09:00'));
            $endTime = strtotime($date . ' ' . ($appt_config['end_time'] ?? '17:00'));
            $breakStart = !empty($appt_config['break_start']) ? strtotime($date . ' ' . $appt_config['break_start']) : null;
            $breakEnd = !empty($appt_config['break_end']) ? strtotime($date . ' ' . $appt_config['break_end']) : null;
            $maxBookings = intval($appt_config['max_bookings'] ?? 1);

            $exclude_id = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : 0;
            
            $booked_counts = [];
            if ($exclude_id > 0) {
                $query = "SELECT data_json FROM crud_data WHERE form_id = " . $form_id . " AND id != " . $exclude_id;
            } else {
                $query = "SELECT data_json FROM crud_data WHERE form_id = " . $form_id;
            }
            $result = mysqli_query($conn, $query);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
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
                mysqli_free_result($result);
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
                $available = $booked < $maxBookings;

                $slots[] = [
                    'time' => $slotLabel,
                    'start' => date('H:i', $current),
                    'end' => date('H:i', $slotEnd),
                    'booked' => $booked,
                    'max' => $maxBookings,
                    'available' => $available
                ];

                $current = $slotEnd;
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'slots' => $slots]);
            exit();
        }

        // ADD RECORD
        if ($action === 'addRecord') {
            if (!$can_edit) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to add records']);
                exit();
            }

            if (!empty($form_def['schedule_start']) && strtotime($form_def['schedule_start']) > time()) {
                echo json_encode(['success' => false, 'message' => 'This form is not yet active. It opens on ' . date('M d, Y h:i A', strtotime($form_def['schedule_start']))]);
                exit();
            }
            if (!empty($form_def['schedule_end']) && strtotime($form_def['schedule_end']) < time()) {
                echo json_encode(['success' => false, 'message' => 'This form has closed. It was available until ' . date('M d, Y h:i A', strtotime($form_def['schedule_end']))]);
                exit();
            }
            if (!empty($form_def['expires_at']) && strtotime($form_def['expires_at']) < time()) {
                echo json_encode(['success' => false, 'message' => 'This form has expired and is no longer accepting submissions']);
                exit();
            }

            if (!empty($form_def['max_submissions']) && intval($form_def['max_submissions']) > 0) {
                $cnt_query = "SELECT COUNT(*) as total FROM crud_data WHERE form_id = " . $form_id;
                $cnt_result = mysqli_query($conn, $cnt_query);
                $total = 0;
                if ($cnt_result) {
                    $row = mysqli_fetch_assoc($cnt_result);
                    $total = intval($row['total']);
                    mysqli_free_result($cnt_result);
                }
                if ($total >= intval($form_def['max_submissions'])) {
                    echo json_encode(['success' => false, 'message' => 'This form has reached its maximum number of submissions']);
                    exit();
                }
            }

            if (!empty($form_def['one_per_user'])) {
                $chk_query = "SELECT COUNT(*) as total FROM crud_data WHERE form_id = " . $form_id . " AND submitted_by = " . intval($user_id);
                $chk_result = mysqli_query($conn, $chk_query);
                $already = 0;
                if ($chk_result) {
                    $row = mysqli_fetch_assoc($chk_result);
                    $already = intval($row['total']);
                    mysqli_free_result($chk_result);
                }
                if ($already > 0) {
                    echo json_encode(['success' => false, 'message' => 'You have already submitted a response to this form']);
                    exit();
                }
            }

            $data = [];
            foreach ($form_fields as $field) {
                $fname = $field['name'];
                if ($field['type'] === 'file' || $field['type'] === 'signature' || $field['type'] === 'camera') continue;
                $data[$fname] = isset($_POST[$fname]) ? trim($_POST[$fname]) : '';
            }

            foreach ($form_fields as $field) {
                $fname = $field['name'];
                $ftype = $field['type'];
                if ($ftype === 'file' || $ftype === 'signature' || $ftype === 'camera') continue;
                $value = $data[$fname] ?? '';
                $label = $field['label'] ?? $fname;
                $custom_error = !empty($field['custom_error']) ? $field['custom_error'] : null;

                if (!empty($field['required']) && $value === '') {
                    $msg = $custom_error ? $custom_error : "$label is required";
                    ob_end_clean();
                    echo json_encode(['success' => false, 'message' => $msg, 'field' => $fname]);
                    exit();
                }
                if ($value === '') continue;

                if (!empty($field['regex'])) {
                    $pattern = '/' . str_replace('/', '\\/', $field['regex']) . '/';
                    if (!@preg_match($pattern, $value)) {
                        $msg = $custom_error ? $custom_error : "$label does not match the required format";
                        ob_end_clean();
                        echo json_encode(['success' => false, 'message' => $msg, 'field' => $fname]);
                        exit();
                    }
                }

                if (!empty($field['unique'])) {
                    $json_path = '$.' . $fname;
                    $escaped_value = mysqli_real_escape_string($conn, $value);
                    $uq_query = "SELECT cd.id FROM crud_data cd WHERE cd.form_id = " . $form_id . " AND JSON_UNQUOTE(JSON_EXTRACT(cd.data_json, '{$json_path}')) = '{$escaped_value}' LIMIT 1";
                    $uq_result = mysqli_query($conn, $uq_query);
                    if ($uq_result && mysqli_num_rows($uq_result) > 0) {
                        mysqli_free_result($uq_result);
                        $msg = $custom_error ? $custom_error : "\"$value\" already exists for $label. This field requires a unique value.";
                        ob_end_clean();
                        echo json_encode(['success' => false, 'message' => $msg, 'field' => $fname]);
                        exit();
                    }
                    if ($uq_result) mysqli_free_result($uq_result);
                }
            }

            if (!empty($form_def['duplicate_detection'])) {
                $dup_fields = json_decode($form_def['duplicate_fields'] ?? '[]', true);
                if (is_array($dup_fields) && count($dup_fields) > 0) {
                    $conditions = [];
                    $has_conditions = false;
                    foreach ($dup_fields as $df) {
                        if (isset($data[$df]) && $data[$df] !== '') {
                            $escaped_value = mysqli_real_escape_string($conn, $data[$df]);
                            $conditions[] = "JSON_UNQUOTE(JSON_EXTRACT(cd.data_json, '$." . $df . "')) = '" . $escaped_value . "'";
                            $has_conditions = true;
                        }
                    }
                    if ($has_conditions) {
                        $dup_sql = "SELECT cd.id FROM crud_data cd WHERE cd.form_id = " . $form_id . " AND " . implode(' AND ', $conditions) . " LIMIT 1";
                        $dup_result = mysqli_query($conn, $dup_sql);
                        if ($dup_result && mysqli_num_rows($dup_result) > 0) {
                            $existing_id = mysqli_fetch_assoc($dup_result)['id'];
                            mysqli_free_result($dup_result);
                            if (empty($_POST['_confirm_duplicate'])) {
                                ob_end_clean();
                                echo json_encode(['success' => false, 'duplicate' => true, 'existing_id' => $existing_id, 'message' => 'A similar record (#' . $existing_id . ') already exists. Do you want to submit anyway?']);
                                exit();
                            }
                        }
                        if ($dup_result) mysqli_free_result($dup_result);
                    }
                }
            }

            $data_json = json_encode($data);
            $ip = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR']);
            $escaped_data_json = mysqli_real_escape_string($conn, $data_json);

            $insert_query = "INSERT INTO crud_data (form_id, data_json, submitted_by, ip_address) VALUES (" . $form_id . ", '{$escaped_data_json}', " . intval($user_id) . ", '{$ip}')";
            mysqli_query($conn, $insert_query);
            $record_id = mysqli_insert_id($conn);

            foreach ($form_fields as $field) {
                if (($field['type'] === 'file' || $field['type'] === 'signature' || $field['type'] === 'camera') && isset($_FILES[$field['name']]) && $_FILES[$field['name']]['error'] === UPLOAD_ERR_OK) {
                    $uploaded = handleCrudFileUpload($_FILES[$field['name']], $form_slug, $form_id, $record_id, $field['name'], $user_id, $conn);
                    if ($uploaded) {
                        $data[$field['name']] = $uploaded['file_path'];
                    }
                }
            }

            $data_json = json_encode($data);
            $escaped_data_json = mysqli_real_escape_string($conn, $data_json);
            $update_query = "UPDATE crud_data SET data_json = '{$escaped_data_json}' WHERE id = " . intval($record_id);
            mysqli_query($conn, $update_query);

            logActivity($user_id, $username, 'Added Record', "Added record #{$record_id} in {$form_def['form_name']}");

            if ($form_def['email_notify_admin']) {
                createNotificationForAdmins('New Submission', "New record added in \"{$form_def['form_name']}\"", 'info', "crud_view.php?form=$form_slug");
            }

            $smtp_enabled = getSetting('smtp_enabled', '0');
            if ($smtp_enabled === '1' && function_exists('sendEmail')) {
                $branding = getSiteBranding();

                if ($form_def['email_on_submit'] && $form_def['email_notify_admin']) {
                    $admin_email = getSetting('smtp_from_email', '');
                    if (!empty($admin_email)) {
                        $subject = "New Submission: {$form_def['form_name']}";
                        $body = "<div style='font-family:Arial;max-width:500px;margin:0 auto;padding:20px;'>";
                        $body .= "<div style='background:#001f3f;padding:20px;text-align:center;border-radius:8px 8px 0 0;'>";
                        $body .= "<h2 style='color:#fff;margin:0;'>" . htmlspecialchars($branding['site_name']) . "</h2></div>";
                        $body .= "<div style='background:#fff;padding:20px;border:1px solid #e9ecef;'>";
                        $body .= "<h3>New Record Submitted</h3>";
                        $body .= "<p>A new record <strong>#$record_id</strong> was added in <strong>" . htmlspecialchars($form_def['form_name']) . "</strong> by <strong>" . htmlspecialchars($username) . "</strong>.</p>";
                        $body .= "</div></div>";
                        sendEmail($admin_email, $subject, $body);
                    }
                }

                if ($form_def['email_confirmation']) {
                    $submitted_email = '';
                    foreach ($form_fields as $field) {
                        if ($field['type'] === 'email' && !empty($data[$field['name']])) {
                            $submitted_email = $data[$field['name']];
                            break;
                        }
                    }
                    if (!empty($submitted_email)) {
                        $subject = "Submission Received: {$form_def['form_name']}";
                        $body = "<div style='font-family:Arial;max-width:500px;margin:0 auto;padding:20px;'>";
                        $body .= "<div style='background:#001f3f;padding:20px;text-align:center;border-radius:8px 8px 0 0;'>";
                        $body .= "<h2 style='color:#fff;margin:0;'>" . htmlspecialchars($branding['site_name']) . "</h2></div>";
                        $body .= "<div style='background:#fff;padding:20px;border:1px solid #e9ecef;'>";
                        $body .= "<h3>Thank you for your submission!</h3>";
                        $body .= "<p>We have received your <strong>" . htmlspecialchars($form_def['form_name']) . "</strong> submission.</p>";
                        $body .= "<p>Reference ID: <strong>#$record_id</strong></p>";
                        $body .= "</div></div>";
                        sendEmail($submitted_email, $subject, $body);
                    }
                }
            }

            $response = ['success' => true, 'message' => 'Record added successfully'];
            if (!empty($form_def['thank_you_message'])) {
                $response['thank_you_message'] = $form_def['thank_you_message'];
            }
            if (!empty($form_def['redirect_url']) && filter_var($form_def['redirect_url'], FILTER_VALIDATE_URL)) {
                $response['redirect_url'] = $form_def['redirect_url'];
            }
            echo json_encode($response);
            exit();
        }

        // UPDATE RECORD - FIXED
        if ($action === 'updateRecord') {
            if (!$can_edit) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'You do not have permission to edit records']);
                exit();
            }

            $record_id = intval($_POST['record_id'] ?? 0);
            if ($record_id <= 0) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Invalid record ID']);
                exit();
            }

            $query = "SELECT data_json, submitted_by FROM crud_data WHERE id = " . $record_id . " AND form_id = " . $form_id;
            $result = mysqli_query($conn, $query);
            if (!$result || mysqli_num_rows($result) === 0) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Record not found']);
                exit();
            }
            $existing = mysqli_fetch_assoc($result);
            mysqli_free_result($result);

            if ($role !== 'Admin' && (int)($existing['submitted_by'] ?? 0) !== (int)$user_id) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'You can only edit your own records']);
                exit();
            }

            $data = json_decode($existing['data_json'], true) ?: [];

            foreach ($form_fields as $field) {
                $fname = $field['name'];
                if ($field['type'] === 'file' || $field['type'] === 'signature' || $field['type'] === 'camera') {
                    if (isset($_FILES[$fname]) && $_FILES[$fname]['error'] === UPLOAD_ERR_OK) {
                        $old_query = "SELECT id, file_path FROM crud_files WHERE record_id = " . $record_id . " AND field_name = '" . mysqli_real_escape_string($conn, $fname) . "'";
                        $old_result = mysqli_query($conn, $old_query);
                        $old = mysqli_fetch_assoc($old_result);
                        mysqli_free_result($old_result);

                        if ($old) {
                            $fpath = __DIR__ . '/' . $old['file_path'];
                            if (file_exists($fpath)) unlink($fpath);
                            $del_query = "DELETE FROM crud_files WHERE id = " . intval($old['id']);
                            mysqli_query($conn, $del_query);
                        }

                        $uploaded = handleCrudFileUpload($_FILES[$fname], $form_slug, $form_id, $record_id, $fname, $user_id, $conn);
                        if ($uploaded) {
                            $data[$fname] = $uploaded['file_path'];
                        }
                    }
                    continue;
                }
                $data[$fname] = isset($_POST[$fname]) ? trim($_POST[$fname]) : '';
            }

            foreach ($form_fields as $field) {
                $fname = $field['name'];
                $ftype = $field['type'];
                if ($ftype === 'file' || $ftype === 'signature' || $ftype === 'camera') continue;
                $value = $data[$fname] ?? '';
                $label = $field['label'] ?? $fname;
                $custom_error = !empty($field['custom_error']) ? $field['custom_error'] : null;

                if (!empty($field['required']) && $value === '') {
                    $msg = $custom_error ? $custom_error : "$label is required";
                    ob_end_clean();
                    echo json_encode(['success' => false, 'message' => $msg, 'field' => $fname]);
                    exit();
                }
                if ($value === '') continue;

                if (!empty($field['regex'])) {
                    $pattern = '/' . str_replace('/', '\\/', $field['regex']) . '/';
                    if (!@preg_match($pattern, $value)) {
                        $msg = $custom_error ? $custom_error : "$label does not match the required format";
                        ob_end_clean();
                        echo json_encode(['success' => false, 'message' => $msg, 'field' => $fname]);
                        exit();
                    }
                }

                if (!empty($field['unique'])) {
                    $json_path = '$.' . $fname;
                    $escaped_value = mysqli_real_escape_string($conn, $value);
                    $uq_query = "SELECT cd.id FROM crud_data cd WHERE cd.form_id = " . $form_id . " AND cd.id != " . $record_id . " AND JSON_UNQUOTE(JSON_EXTRACT(cd.data_json, '{$json_path}')) = '{$escaped_value}' LIMIT 1";
                    $uq_result = mysqli_query($conn, $uq_query);
                    if ($uq_result && mysqli_num_rows($uq_result) > 0) {
                        mysqli_free_result($uq_result);
                        $msg = $custom_error ? $custom_error : "\"$value\" already exists for $label. This field requires a unique value.";
                        ob_end_clean();
                        echo json_encode(['success' => false, 'message' => $msg, 'field' => $fname]);
                        exit();
                    }
                    if ($uq_result) mysqli_free_result($uq_result);
                }
            }

            $data_json = json_encode($data);
            $escaped_data_json = mysqli_real_escape_string($conn, $data_json);
            $update_query = "UPDATE crud_data SET data_json = '{$escaped_data_json}', updated_at = NOW() WHERE id = " . $record_id;
            $result = mysqli_query($conn, $update_query);
            
            if ($result) {
                logActivity($user_id, $username, 'Updated Record', "Updated record #{$record_id} in {$form_def['form_name']}");
                ob_end_clean();
                echo json_encode(['success' => true, 'message' => 'Record updated successfully']);
            } else {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to update record: ' . mysqli_error($conn)]);
            }
            exit();
        }

        // DELETE RECORD - FIXED WITH CSRF
        if ($action === 'deleteRecord') {
            if (!$can_delete) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'You do not have permission to delete records']);
                exit();
            }

            $record_id = intval($_POST['record_id'] ?? 0);
            if ($record_id <= 0) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Invalid record ID']);
                exit();
            }

            if ($role !== 'Admin') {
                $own_query = "SELECT submitted_by FROM crud_data WHERE id = " . $record_id . " AND form_id = " . $form_id;
                $own_result = mysqli_query($conn, $own_query);
                if (!$own_result || mysqli_num_rows($own_result) === 0) {
                    ob_end_clean();
                    echo json_encode(['success' => false, 'message' => 'Record not found']);
                    exit();
                }
                $own_row = mysqli_fetch_assoc($own_result);
                mysqli_free_result($own_result);
                if ((int)($own_row['submitted_by'] ?? 0) !== (int)$user_id) {
                    ob_end_clean();
                    echo json_encode(['success' => false, 'message' => 'You can only delete your own records']);
                    exit();
                }
            }

            $fquery = "SELECT file_path FROM crud_files WHERE record_id = " . $record_id;
            $fres = mysqli_query($conn, $fquery);
            $upload_base = realpath(__DIR__ . '/uploads');
            while ($frow = mysqli_fetch_assoc($fres)) {
                $filepath = realpath(__DIR__ . '/' . $frow['file_path']);
                if ($filepath && $upload_base && strpos($filepath, $upload_base) === 0 && file_exists($filepath)) {
                    unlink($filepath);
                }
            }
            mysqli_free_result($fres);

            $del1_query = "DELETE FROM crud_files WHERE record_id = " . $record_id;
            mysqli_query($conn, $del1_query);

            $del2_query = "DELETE FROM crud_data WHERE id = " . $record_id . " AND form_id = " . $form_id;
            $result = mysqli_query($conn, $del2_query);

            if ($result && mysqli_affected_rows($conn) > 0) {
                logActivity($user_id, $username, 'Deleted Record', "Deleted record #{$record_id} in {$form_def['form_name']}");
                ob_end_clean();
                echo json_encode(['success' => true, 'message' => 'Record deleted successfully']);
            } else {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to delete record or record not found']);
            }
            exit();
        }

    } catch (Exception $e) {
        error_log("crud_view.php error: " . $e->getMessage());
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }

    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    exit();
}

function handleCrudFileUpload($file, $form_slug, $form_id, $record_id, $field_name, $uploaded_by, $conn) {
    $upload_dir = __DIR__ . '/uploads/crud/' . $form_slug . '/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) {
        return false;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $filename = $field_name . '_' . $record_id . '_' . time() . '.' . $safe_ext;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        $relative_path = 'uploads/crud/' . $form_slug . '/' . $filename;

        $insert_query = "INSERT INTO crud_files (form_id, record_id, field_name, file_name, file_path, file_type, file_size, uploaded_by) VALUES (" 
            . intval($form_id) . ", " 
            . intval($record_id) . ", '" 
            . mysqli_real_escape_string($conn, $field_name) . "', '" 
            . mysqli_real_escape_string($conn, $file['name']) . "', '" 
            . mysqli_real_escape_string($conn, $relative_path) . "', '" 
            . mysqli_real_escape_string($conn, $mime) . "', " 
            . intval($file['size']) . ", " 
            . intval($uploaded_by) . ")";
        mysqli_query($conn, $insert_query);

        return ['file_path' => $relative_path, 'file_name' => $file['name']];
    }
    return false;
}

// Get total records count for stats
$total_records = 0;
try {
    $conn = getDBConnection();
    $cnt_query = "SELECT COUNT(*) as total FROM crud_data WHERE form_id = " . intval($form_def['id']);
    $cnt_result = mysqli_query($conn, $cnt_query);
    if ($cnt_result) {
        $row = mysqli_fetch_assoc($cnt_result);
        $total_records = intval($row['total']);
        mysqli_free_result($cnt_result);
    }
} catch (Exception $e) {}

// Get monthly records for stats
$monthly_records = 0;
try {
    $conn = getDBConnection();
    $cnt_query = "SELECT COUNT(*) as total FROM crud_data WHERE form_id = " . intval($form_def['id']) . " AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())";
    $cnt_result = mysqli_query($conn, $cnt_query);
    if ($cnt_result) {
        $row = mysqli_fetch_assoc($cnt_result);
        $monthly_records = intval($row['total']);
        mysqli_free_result($cnt_result);
    }
} catch (Exception $e) {}

// Generate CSRF token for JavaScript
$csrf_token_js = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
    <title><?php echo htmlspecialchars($form_def['form_name']); ?> · Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>
    
    <style>
        /* ... (all the existing CSS styles remain exactly the same) ... */
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
            --transition-bounce: 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
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
        /* ... rest of the CSS remains the same ... */
        .breadcrumb-modern {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--text-muted);
            padding: 20px 0 12px; margin-bottom: 4px;
        }
        .breadcrumb-modern a { color: var(--text-secondary); text-decoration: none; transition: color var(--transition-fast); display: flex; align-items: center; gap: 6px; }
        .breadcrumb-modern a:hover { color: var(--accent-blue); }
        .breadcrumb-modern .sep { color: var(--text-muted); font-size: 10px; }
        .breadcrumb-modern .current { color: var(--text-secondary); font-weight: 500; }

        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            flex-wrap: wrap; gap: 20px; padding: 4px 0 24px;
            border-bottom: 1px solid var(--border-color); margin-bottom: 28px;
        }
        .page-header-left { display: flex; align-items: center; gap: 20px; }
        .page-header-left .icon-wrapper {
            width: 56px; height: 56px; border-radius: var(--radius-lg);
            background: linear-gradient(135deg, var(--accent-green-bg), rgba(16, 185, 129, 0.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: var(--accent-green);
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
            display: flex; align-items: center; gap: 10px;
        }
        .page-header-left .title-group h1 img {
            height: 32px; width: 32px; object-fit: contain; border-radius: 6px;
        }
        .page-header-left .title-group .subtitle {
            font-size: 14px; color: var(--text-secondary);
            margin: 2px 0 0; font-weight: 400;
        }
        .page-header-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

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
        .btn-xs { padding: 4px 10px; font-size: 11px; border-radius: var(--radius-sm); }

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
            height: 2.5px; background: linear-gradient(90deg, var(--accent-green), var(--accent-blue));
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
        .stat-card:hover .stat-icon { background: var(--accent-green-bg); color: var(--accent-green); }
        .stat-card .stat-value { font-size: 32px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.025em; line-height: 1.2; }
        .stat-card .stat-change {
            font-size: 12px; font-weight: 500; margin-top: 4px;
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 12px; border-radius: var(--radius-full);
            background: var(--accent-green-bg); color: var(--accent-green);
        }

        .charts-grid {
            display: grid; grid-template-columns: repeat(2, 1fr);
            gap: 20px; margin-bottom: 28px;
        }
        .chart-card {
            background: var(--bg-card); border-radius: var(--radius-lg);
            padding: 20px 24px; border: 1px solid var(--border-color);
            transition: all var(--transition-normal);
        }
        .chart-card:hover { border-color: var(--border-hover); }
        .chart-card .chart-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .chart-card .chart-header h4 {
            font-size: 15px; font-weight: 600; color: var(--text-primary);
            margin: 0; display: flex; align-items: center; gap: 8px;
        }
        .chart-card .chart-header h4 i { color: var(--accent-purple); }
        .chart-card .chart-wrapper { position: relative; height: 220px; }
        .chart-card .chart-wrapper canvas { width: 100% !important; height: 100% !important; }

        .table-wrapper {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
        }
        .table-wrapper:hover { border-color: var(--border-hover); }

        .table-scroll-container {
            overflow-x: auto;
            overflow-y: visible;
            max-width: 100%;
            -webkit-overflow-scrolling: touch;
            padding: 0;
        }
        .table-scroll-container::-webkit-scrollbar {
            height: 8px;
        }
        .table-scroll-container::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 4px;
        }
        .table-scroll-container::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: 4px;
        }
        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        .table-scroll-hint {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 8px 16px;
            font-size: 12px;
            color: var(--text-muted);
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            animation: hintPulse 2.5s ease-in-out infinite;
        }
        .table-scroll-hint i { font-size: 14px; }
        .table-scroll-hint .hint-key {
            display: inline-block;
            padding: 1px 8px;
            background: var(--bg-input);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            font-size: 10px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        @keyframes hintPulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }

        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-toolbar .left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
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
        .table-toolbar .left .field-count-badge {
            font-size: 11px;
            color: var(--text-muted);
            background: var(--accent-purple-bg);
            padding: 2px 12px;
            border-radius: var(--radius-full);
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

        .table-wrapper .dataTables_wrapper {
            padding: 0 16px 16px;
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
            background: var(--accent-green);
            color: #fff;
            border-radius: var(--radius-sm);
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--accent-green);
            color: #fff;
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .table-wrapper table.dataTable {
            width: 100% !important;
            border-collapse: collapse;
            margin: 0 !important;
            font-size: 13px;
            background: transparent !important;
            min-width: 100%;
        }
        
        .table-wrapper table.dataTable thead th {
            padding: 12px 16px 10px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
            background: var(--bg-card) !important;
            text-align: left;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
            min-width: 100px;
        }
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
        .table-wrapper table.dataTable thead th:first-child {
            min-width: 60px;
        }
        
        .table-wrapper table.dataTable tbody td {
            padding: 11px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            vertical-align: middle;
            background: transparent !important;
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body:not(.dark-mode) .table-wrapper table.dataTable tbody td,
        [data-theme="light"] .table-wrapper table.dataTable tbody td {
            color: #1e293b !important;
            border-bottom-color: #e2e8f0 !important;
        }
        .table-wrapper table.dataTable tbody td:first-child {
            font-weight: 600;
            color: var(--text-secondary);
        }
        body:not(.dark-mode) .table-wrapper table.dataTable tbody td:first-child,
        [data-theme="light"] .table-wrapper table.dataTable tbody td:first-child {
            color: #475569 !important;
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

        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 500;
        }

        .action-icon {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
            font-size: 13px;
        }
        body:not(.dark-mode) .action-icon,
        [data-theme="light"] .action-icon {
            color: #94a3b8 !important;
        }
        .action-icon:hover {
            background: var(--bg-input);
            color: var(--text-primary);
        }
        .edit-icon:hover {
            color: var(--accent-blue);
            background: var(--accent-blue-bg);
        }
        .delete-icon:hover {
            color: var(--accent-red);
            background: var(--accent-red-bg);
        }

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
            max-width: 720px;
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
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
            display: flex;
            align-items: center; gap: 12px;
            letter-spacing: -0.02em;
        }
        .modal-header h3 i { color: var(--accent-green); }
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
        .modal-close:hover { background: var(--accent-red-bg); color: var(--accent-red); }
        .modal-body { padding: 24px 28px; overflow-y: auto; flex: 1; }

        .record-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .record-details-grid .detail-item {
            padding: 12px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }
        .record-details-grid .detail-item .detail-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .record-details-grid .detail-item .detail-value {
            font-size: 14px;
            color: var(--text-primary);
            word-break: break-word;
        }
        body:not(.dark-mode) .record-details-grid .detail-item {
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        body:not(.dark-mode) .record-details-grid .detail-item .detail-value {
            color: #0f172a;
        }

        .record-details-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .form-group label i { margin-right: 4px; color: var(--text-muted); width: 16px; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            transition: all var(--transition-fast);
            width: 100%;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-bg);
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-group small { font-size: 12px; color: var(--text-muted); }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }
        .form-actions .btn { flex: 1; justify-content: center; padding: 12px; }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 16px 18px; }
            .stat-card .stat-value { font-size: 22px; }
            .table-toolbar { flex-direction: column; align-items: stretch; gap: 10px; padding: 12px 14px; }
            .table-toolbar .right { flex-wrap: wrap; }
            .table-toolbar .right .btn .label { display: none; }
            .table-wrapper .dataTables_wrapper { padding: 0 10px 12px; }
            .table-wrapper table.dataTable { font-size: 12px; }
            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td { padding: 8px 10px; }
            .table-scroll-hint { font-size: 10px; padding: 4px 12px; }
            .modal { margin: 10px; border-radius: var(--radius-lg); max-height: 95vh; }
            .modal-header { padding: 18px 20px 12px; }
            .modal-body { padding: 18px 20px 22px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { flex: none; }
            .record-details-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .stat-value { font-size: 18px; }
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .table-wrapper table.dataTable thead th,
            .table-wrapper table.dataTable tbody td { padding: 6px 8px; font-size: 11px; }
            .table-wrapper table.dataTable thead th { min-width: 70px; }
        }

        .initially-hidden { display: none; }
        .skeleton-card { background: var(--bg-card); border-radius: var(--radius-lg); padding: 20px 24px; border: 1px solid var(--border-color); }
        .skeleton { background: var(--bg-input); border-radius: var(--radius-sm); animation: skeletonPulse 1.5s ease-in-out infinite; }
        .skeleton-text { height: 16px; width: 60%; margin: 8px 0; }
        .skeleton-text-large { height: 24px; width: 40%; }
        @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }

        .chart-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 180px;
            color: var(--text-muted);
            gap: 8px;
        }
        .chart-empty i { font-size: 32px; opacity: 0.3; }
        .chart-empty p { font-size: 14px; margin: 0; }

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
            background: var(--accent-green) !important;
            color: #fff !important;
            border-color: transparent !important;
        }

        * { transition: background-color var(--transition-slow), color var(--transition-slow), border-color var(--transition-slow), box-shadow var(--transition-slow); }

        .record-details-modal .modal {
            max-width: 580px;
        }
        .record-details-modal .record-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .record-details-modal .detail-item {
            padding: 10px 14px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }
        .record-details-modal .detail-item .detail-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 3px;
        }
        .record-details-modal .detail-item .detail-value {
            font-size: 13px;
            color: var(--text-primary);
            word-break: break-word;
        }
        .record-details-modal .detail-item .detail-value img {
            max-height: 80px;
            max-width: 100%;
            border-radius: 4px;
        }
        body:not(.dark-mode) .record-details-modal .detail-item {
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        body:not(.dark-mode) .record-details-modal .detail-item .detail-value {
            color: #0f172a;
        }
        .record-details-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
            justify-content: center;
        }
        .record-details-actions .btn {
            font-size: 12px;
            padding: 6px 14px;
        }
        @media (max-width: 640px) {
            .record-details-modal .record-details-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>
    <div class="app-container">
        <?php include 'sidebar.php'; ?>
        <div class="main-content">
            <div class="breadcrumb-modern">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                <span class="current"><?php echo htmlspecialchars($form_def['form_name']); ?></span>
            </div>

            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="<?php echo htmlspecialchars($form_def['form_icon']); ?>"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>
                            <?php if (!empty($form_def['form_logo'])): ?>
                            <img src="<?php echo htmlspecialchars($form_def['form_logo']); ?>" alt="Logo">
                            <?php endif; ?>
                            <?php echo htmlspecialchars($form_def['form_name']); ?>
                        </h1>
                        <p class="subtitle">
                            <?php echo htmlspecialchars($form_def['form_description'] ?? 'Manage records'); ?>
                            <?php if (!empty($form_def['is_public'])): ?>
                            <span class="status-badge" style="background:var(--accent-purple-bg);color:var(--accent-purple);font-size:11px;padding:2px 12px;"><i class="fas fa-globe"></i> Public</span>
                            <?php endif; ?>
                            <span class="status-badge" style="background:var(--bg-input);color:var(--text-muted);font-size:11px;padding:2px 12px;">
                                <i class="fas fa-list"></i> <?php echo count($form_fields); ?> fields
                            </span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadRecords()" title="Refresh">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                    <?php if ($can_edit): ?>
                    <button class="btn btn-success" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add Record
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($form_def['is_public']) && !empty($form_def['public_token'])): ?>
                    <button class="btn" onclick="showFormQR()" style="background:var(--accent-purple);color:#fff;">
                        <i class="fas fa-qrcode"></i> Share
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Records</span>
                        <span class="stat-icon"><i class="fas fa-database"></i></span>
                    </div>
                    <div class="stat-value" id="totalRecords"><?php echo $total_records; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">This Month</span>
                        <span class="stat-icon"><i class="fas fa-calendar-month"></i></span>
                    </div>
                    <div class="stat-value" id="monthlyRecords"><?php echo $monthly_records; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Fields</span>
                        <span class="stat-icon"><i class="fas fa-list-ul"></i></span>
                    </div>
                    <div class="stat-value"><?php echo count($form_fields); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Last Record</span>
                        <span class="stat-icon"><i class="fas fa-clock"></i></span>
                    </div>
                    <div class="stat-value" id="lastRecord" style="font-size:18px;">--</div>
                </div>
            </div>

            <?php if (count($chart_fields) > 0): ?>
            <div class="charts-grid" id="chartsGrid">
                <?php foreach (array_slice($chart_fields, 0, 4) as $cf): ?>
                <div class="chart-card" id="chartCard_<?php echo htmlspecialchars($cf['name']); ?>">
                    <div class="chart-header">
                        <h4><i class="fas fa-chart-<?php echo htmlspecialchars($cf['chart_type'] ?? 'bar'); ?>"></i> <?php echo htmlspecialchars($cf['chart_label'] ?? $cf['label']); ?></h4>
                        <span style="font-size:11px;color:var(--text-muted);"><?php echo ucfirst($cf['chart_type'] ?? 'bar'); ?></span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="chart_<?php echo htmlspecialchars($cf['name']); ?>"></canvas>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="left">
                        <h3><i class="fas fa-table"></i> Records</h3>
                        <span class="count-badge" id="recordCountBadge">0 records</span>
                        <span class="field-count-badge"><i class="fas fa-columns"></i> <?php echo count($form_fields); ?> columns</span>
                    </div>
                    <div class="right">
                        <button class="btn btn-ghost btn-sm" onclick="exportData('copy')" title="Copy">
                            <i class="fas fa-copy"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('csv')" title="CSV">
                            <i class="fas fa-file-csv"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('excel')" title="Excel">
                            <i class="fas fa-file-excel"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('pdf')" title="PDF">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="exportData('print')" title="Print">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>

                <div class="table-scroll-hint" id="scrollHint">
                    <i class="fas fa-arrows-left-right"></i>
                    <span>Scroll horizontally to see all <?php echo count($form_fields); ?> fields</span>
                    <span class="hint-key">Shift + Scroll</span>
                    <i class="fas fa-chevron-right"></i>
                </div>

                <div id="loadingSkeleton">
                    <div class="skeleton-card"><div class="skeleton skeleton-text-large"></div><div class="skeleton skeleton-text"></div></div>
                </div>

                <div id="mainContent" class="initially-hidden">
                    <div class="table-scroll-container" id="tableScrollContainer">
                        <table id="recordsTable" class="display" style="width:100%;">
                            <thead><tr><th>#</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay record-details-modal" id="recordDetailsModal" onclick="if(event.target===this)closeRecordDetails()">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="detailsModalTitle"><i class="fas fa-eye"></i> Record Details</h3>
                <button class="modal-close" onclick="closeRecordDetails()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div id="recordDetailsContent"></div>
                <div class="record-details-actions">
                    <button class="btn btn-ghost btn-sm" onclick="exportDetails('copy')"><i class="fas fa-copy"></i> Copy</button>
                    <button class="btn btn-ghost btn-sm" onclick="exportDetails('csv')"><i class="fas fa-file-csv"></i> CSV</button>
                    <button class="btn btn-ghost btn-sm" onclick="exportDetails('excel')"><i class="fas fa-file-excel"></i> Excel</button>
                    <button class="btn btn-ghost btn-sm" onclick="exportDetails('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
                    <button class="btn btn-ghost btn-sm" onclick="exportDetails('print')"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="recordModal" onclick="if(event.target===this)closeModal()">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Record</h3>
                <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="recordForm" enctype="multipart/form-data">
                    <input type="hidden" id="recordId" name="record_id" value="">
                    <div id="stepsProgressContainer"></div>
                    <div id="dynamicFields"></div>
                    <div id="stepNav" style="display:none;"></div>
                    <div id="defaultFormActions" class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-ghost" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                        <button type="button" class="btn btn-ghost" id="saveDraftBtn" onclick="saveCrudDraft()" title="Save as draft"><i class="fas fa-save"></i> Draft</button>
                        <button type="button" class="btn btn-ghost" id="clearFormBtn" onclick="clearCrudForm()" title="Clear all fields"><i class="fas fa-eraser"></i> Clear</button>
                    </div>
                    <div id="crudDraftNotice" style="display:none;margin-top:8px;padding:8px 12px;background:var(--accent-blue-bg);border-radius:6px;font-size:12px;color:var(--accent-blue);text-align:center;">
                        <i class="fas fa-info-circle"></i> <span id="crudDraftText">Draft restored.</span>
                        <button type="button" onclick="document.getElementById('crudDraftNotice').style.display='none'" style="background:none;border:none;color:var(--accent-blue);cursor:pointer;margin-left:6px;font-size:11px;text-decoration:underline;">Dismiss</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // CSRF Token for AJAX requests
    var CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]') ? 
        document.querySelector('meta[name="csrf-token"]').getAttribute('content') : 
        '<?php echo htmlspecialchars($csrf_token_js); ?>';
    
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            // For POST requests that modify data, always include CSRF token
            if (settings.type === 'POST' || settings.method === 'POST') {
                xhr.setRequestHeader('X-CSRF-TOKEN', CSRF_TOKEN);
            }
        }
    });
    </script>

    <script>
    var FORM_DEF, FORM_FIELDS, FORM_SLUG, CAN_EDIT, CAN_DELETE;
    try {
        FORM_DEF = <?php echo json_encode($form_def, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}'; ?>;
        FORM_FIELDS = <?php echo json_encode($form_fields, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]'; ?>;
        FORM_SLUG = <?php echo json_encode($form_slug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '""'; ?>;
        CAN_EDIT = <?php echo $can_edit ? 'true' : 'false'; ?>;
        CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    } catch(e) {
        console.error('Failed to parse PHP data:', e);
        FORM_DEF = {};
        FORM_FIELDS = [];
        FORM_SLUG = '';
        CAN_EDIT = false;
        CAN_DELETE = false;
    }

    var recordsTable = null;
    var recordsData = [];
    var isEditMode = false;
    var signaturePads = {};
    var HAS_SIGNATURE = FORM_FIELDS.some(function(f) { return f.type === 'signature'; });
    var HAS_QR = FORM_FIELDS.some(function(f) { return f.type === 'qr_scanner'; });
    var PDF_ENABLED = <?php echo ($form_def['enable_pdf_download'] ?? 0) ? 'true' : 'false'; ?>;
    var SITE_BRANDING = <?php echo json_encode(getSiteBranding(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var chartInstances = {};
    var chartFields = <?php echo json_encode(array_slice($chart_fields, 0, 4)); ?>;
    var qrScannerStreams = {};
    var cameraStreams = {};
    var capturedPhotos = {};
    var CRUD_DRAFT_KEY = 'crud_draft_' + FORM_SLUG;
    var currentDetailsRecord = null;

    var FORM_STEPS = [];
    try { FORM_STEPS = FORM_DEF.form_steps ? JSON.parse(FORM_DEF.form_steps) : []; } catch(e) { FORM_STEPS = []; }
    var IS_MULTISTEP = FORM_STEPS.length > 0;
    var crudCurrentStep = 1;

    $(document).ready(function() {
        loadRecords();
        renderDynamicFormFields();
        loadCharts();
        var lastRec = document.getElementById('lastRecord');
        if (lastRec) {
            var total = parseInt(document.getElementById('totalRecords').textContent) || 0;
            if (total === 0) {
                lastRec.textContent = 'No records';
            } else {
                lastRec.textContent = 'Just now';
            }
        }
        console.log('[CRUD View] Loaded with ' + FORM_FIELDS.length + ' fields');
        console.log('[CRUD View] CAN_EDIT:', CAN_EDIT, 'CAN_DELETE:', CAN_DELETE);
    });

    function loadRecords() {
        $('#loadingSkeleton').show();
        $('#mainContent').hide();

        $.ajax({
            url: 'crud_view.php?form=' + FORM_SLUG + '&action=getRecords',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#loadingSkeleton').hide();
                $('#mainContent').show();
                if (response.success) {
                    recordsData = response.data;
                    updateStats(response.data);
                    initRecordsTable(response.data);
                    loadCharts();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Unknown error' });
                }
            },
            error: function(xhr, status, err) {
                $('#loadingSkeleton').hide();
                $('#mainContent').show();
                console.error('AJAX error:', status, err, xhr.responseText);
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not load records.' });
            }
        });
    }

    function updateStats(data) {
        var total = data.length;
        document.getElementById('totalRecords').textContent = total;
        document.getElementById('recordCountBadge').textContent = total + ' record' + (total !== 1 ? 's' : '');
        var now = new Date();
        var monthly = data.filter(function(r) {
            var d = new Date(r.created_at);
            return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
        });
        document.getElementById('monthlyRecords').textContent = monthly.length;
        var last = document.getElementById('lastRecord');
        if (total === 0) {
            last.textContent = 'No records';
        } else {
            var latest = new Date(data[0].created_at);
            var diff = Math.floor((Date.now() - latest.getTime()) / 60000);
            if (diff < 1) last.textContent = 'Just now';
            else if (diff < 60) last.textContent = diff + 'm ago';
            else if (diff < 1440) last.textContent = Math.floor(diff / 60) + 'h ago';
            else last.textContent = latest.toLocaleDateString();
        }
    }

    function initRecordsTable(data) {
        if (recordsTable) { recordsTable.destroy(); recordsTable = null; }

        var columns = [{ data: 'id', title: '#', width: '50px' }];
        
        var tableFields = FORM_FIELDS.filter(function(f) { 
            return f.show_in_table !== false && f.type !== 'hidden'; 
        });

        tableFields.forEach(function(field) {
            columns.push({
                data: null,
                title: field.label,
                name: field.name,
                width: field.type === 'textarea' ? '160px' : '120px',
                render: function(rowData, type, row) {
                    try {
                        var fieldData = rowData.data || {};
                        var val = fieldData[field.name];
                        if (val === undefined || val === null) val = '';
                        val = String(val);
                        
                        if ((field.type === 'file' || field.type === 'camera') && val) {
                            if (val.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
                                return '<img src="' + val + '" alt="' + field.label + '" style="max-width:36px;max-height:36px;border-radius:4px;cursor:pointer;object-fit:cover;" onclick="window.open(\'' + val + '\',\'_blank\')">';
                            }
                            return '<a href="' + val + '" target="_blank" class="btn btn-sm btn-ghost" style="font-size:10px;padding:2px 8px;"><i class="fas fa-download"></i></a>';
                        }
                        if (field.type === 'qr_scanner' && val) {
                            return '<i class="fas fa-qrcode" style="color:var(--accent-purple);margin-right:4px;"></i>' + (val.length > 25 ? val.substring(0, 25) + '...' : val);
                        }
                        if (field.type === 'gps' && val) {
                            var parts = val.split(',');
                            if (parts.length === 2) {
                                var lat = parseFloat(parts[0]).toFixed(4);
                                var lng = parseFloat(parts[1]).toFixed(4);
                                return '<a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank" style="color:var(--accent-blue);text-decoration:none;font-size:12px;" title="Open in Google Maps"><i class="fas fa-map-marker-alt"></i> ' + lat + ', ' + lng + '</a>';
                            }
                            return val;
                        }
                        if (field.type === 'color' && val) {
                            return '<span style="display:inline-block;width:18px;height:18px;background:' + val + ';border-radius:4px;border:1px solid var(--border-color);vertical-align:middle;"></span> ' + val.toUpperCase();
                        }
                        if ((field.type === 'url' || field.type === 'link') && val) {
                            return '<a href="' + val + '" target="_blank" style="color:var(--accent-blue);font-size:12px;" title="' + val + '">' + val.substring(0, 20) + '</a>';
                        }
                        if (field.type === 'appointment' && val) {
                            return '<i class="fas fa-calendar-check" style="color:var(--accent-green);margin-right:4px;"></i>' + val;
                        }
                        if (field.type === 'signature' && rowData.files && rowData.files[field.name]) {
                            return '<i class="fas fa-signature" style="color:var(--accent-blue);"></i>';
                        }
                        if (val.length > 40) {
                            return '<span title="' + val.replace(/"/g, '&quot;') + '">' + val.substring(0, 35) + '...</span>';
                        }
                        if (val === '') return '<span style="color:var(--text-muted);font-size:12px;">—</span>';
                        return val;
                    } catch(e) { return ''; }
                }
            });
        });

        columns.push({
            data: null,
            title: 'Submitted',
            name: 'submitted',
            width: '80px',
            render: function(rowData) {
                if (rowData.is_public_submission == 1) return '<span class="status-badge" style="background:var(--accent-amber-bg);color:var(--accent-amber);font-size:10px;">Public</span>';
                return rowData.submitted_by_username || 'System';
            }
        });

        columns.push({
            data: 'created_at',
            title: 'Date',
            name: 'created_at',
            width: '90px',
            render: function(val) {
                if (!val) return '-';
                var d = new Date(val);
                return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
            }
        });

        if (CAN_EDIT || CAN_DELETE || HAS_SIGNATURE || HAS_QR || PDF_ENABLED) {
            columns.push({
                data: null,
                title: 'Actions',
                name: 'actions',
                orderable: false,
                width: '100px',
                className: 'actions-col',
                render: function(rowData) {
                    var html = '';
                    if (CAN_EDIT) html += '<button class="action-icon edit-icon" onclick="editRecord(' + rowData.id + ')" title="Edit"><i class="fas fa-pen"></i></button>';
                    if (CAN_DELETE) html += '<button class="action-icon delete-icon" onclick="deleteRecord(' + rowData.id + ')" title="Delete"><i class="fas fa-trash-alt"></i></button>';
                    html += '<button class="action-icon" onclick="viewRecordDetails(' + rowData.id + ')" title="View Details" style="color:var(--accent-purple);"><i class="fas fa-eye"></i></button>';
                    if (HAS_SIGNATURE && rowData.files) {
                        FORM_FIELDS.forEach(function(f) {
                            if (f.type === 'signature' && rowData.files[f.name]) {
                                var sigPath = rowData.files[f.name].file_path.replace(/'/g, "\\'");
                                var sigLabel = (f.label || 'Signature').replace(/'/g, "\\'");
                                html += '<button class="action-icon" onclick="viewSignature(\'' + sigPath + '\', \'' + sigLabel + '\')" title="View Signature"><i class="fas fa-signature" style="color:var(--accent-blue);"></i></button>';
                            }
                        });
                    }
                    if (HAS_QR) {
                        var fieldData = rowData.data || {};
                        FORM_FIELDS.forEach(function(f) {
                            if (f.type === 'qr_scanner' && fieldData[f.name]) {
                                var qrVal = fieldData[f.name].replace(/'/g, "\\'");
                                html += '<button class="action-icon" onclick="viewQRCode(\'' + qrVal + '\')" title="View QR"><i class="fas fa-qrcode" style="color:var(--accent-purple);"></i></button>';
                            }
                        });
                    }
                    if (PDF_ENABLED) {
                        html += '<button class="action-icon" onclick="generateRecordPDF(' + rowData.id + ')" title="Download PDF"><i class="fas fa-file-pdf" style="color:var(--accent-red);"></i></button>';
                    }
                    return html;
                }
            });
        }

        var theadHtml = '<tr>';
        columns.forEach(function(col) { 
            theadHtml += '<th>' + (col.title || '') + '</th>'; 
        });
        theadHtml += '</tr>';
        $('#recordsTable thead').html(theadHtml);
        $('#recordsTable tbody').html('');

        recordsTable = $('#recordsTable').DataTable({
            data: data,
            destroy: true,
            columns: columns,
            pageLength: 15,
            lengthMenu: [[10, 15, 25, 50, 100, -1], [10, 15, 25, 50, 100, "All"]],
            responsive: {
                details: {
                    display: $.fn.dataTable.Responsive.display.modal({
                        header: function(row) {
                            return 'Record Details';
                        }
                    }),
                    renderer: $.fn.dataTable.Responsive.renderer.tableAll({
                        tableClass: 'table table-striped table-hover'
                    })
                }
            },
            autoWidth: false,
            scrollX: true,
            scrollCollapse: true,
            columnDefs: [
                { targets: 0, orderable: false },
                { targets: '_all', className: 'dt-nowrap' }
            ],
            dom: '<"top"lf>rt<"bottom"ip>',
            language: {
                emptyTable: 'No records found',
                info: 'Showing _START_ to _END_ of _TOTAL_ records',
                infoEmpty: 'No records available',
                infoFiltered: '(filtered from _MAX_ total records)'
            },
            order: [[0, 'desc']],
            buttons: [
                { extend: 'copy', text: 'Copy', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'csv', text: 'CSV', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'excel', text: 'Excel', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'pdf', text: 'PDF', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'print', text: 'Print', exportOptions: { columns: ':not(:last-child)' } }
            ]
        });

        $('.dt-buttons').hide();

        setTimeout(function() {
            var container = document.getElementById('tableScrollContainer');
            var hasScroll = container && container.scrollWidth > container.clientWidth;
            document.getElementById('scrollHint').style.display = hasScroll ? 'flex' : 'none';
        }, 200);
    }

    // ============================
    // EDIT RECORD
    // ============================
    function editRecord(id) {
        console.log('Edit record called with ID:', id);
        
        var record = null;
        for (var i = 0; i < recordsData.length; i++) {
            if (recordsData[i].id == id) {
                record = recordsData[i];
                break;
            }
        }
        
        if (!record) {
            console.error('Record not found in recordsData:', id);
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: 'Record not found. Please refresh the page.',
                background: 'var(--bg-card)', 
                color: 'var(--text-primary)' 
            });
            return;
        }

        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit ' + FORM_DEF.form_name;
        document.getElementById('recordId').value = id;

        var draftBtn = document.getElementById('saveDraftBtn');
        var clearBtn = document.getElementById('clearFormBtn');
        var draftNotice = document.getElementById('crudDraftNotice');
        if (draftBtn) draftBtn.style.display = 'none';
        if (clearBtn) clearBtn.style.display = 'none';
        if (draftNotice) draftNotice.style.display = 'none';

        FORM_FIELDS.forEach(function(field) {
            var val = record.data && record.data[field.name] !== undefined ? record.data[field.name] : '';
            
            if (field.type === 'radio') {
                document.querySelectorAll('input[name="' + field.name + '"]').forEach(function(r) {
                    r.checked = r.value == val;
                });
            } else if (field.type === 'checkbox') {
                if (field.options && field.options.length > 0) {
                    var vals = typeof val === 'string' ? val.split(',') : [];
                    document.querySelectorAll('input[name="' + field.name + '"]').forEach(function(cb) {
                        cb.checked = vals.indexOf(cb.value) >= 0;
                    });
                } else {
                    var el = document.getElementById('field_' + field.name);
                    if (el) el.checked = val == '1' || val == 'true' || val === true;
                }
            } else if (field.type === 'file') {
                var prev = document.getElementById('filePreview_' + field.name);
                if (prev && val) {
                    if (val.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
                        prev.innerHTML = '<img src="' + val + '" style="max-width:100px;max-height:100px;border-radius:4px;">';
                    } else {
                        prev.innerHTML = '<a href="' + val + '" target="_blank"><i class="fas fa-file"></i> Current file</a>';
                    }
                    prev.style.display = 'block';
                }
            } else if (field.type === 'select' && (field.options || []).length >= 5) {
                var sdWrapper = document.getElementById('sd_' + field.name);
                if (sdWrapper && sdWrapper._sdSetValue) {
                    sdWrapper._sdSetValue(val);
                } else {
                    var hi = document.getElementById('field_' + field.name);
                    if (hi) hi.value = val;
                }
            } else if (field.type === 'gps') {
                if (val) {
                    var parts = val.split(',');
                    if (parts.length === 2) {
                        var lat = parseFloat(parts[0]);
                        var lng = parseFloat(parts[1]);
                        document.getElementById('field_' + field.name).value = val;
                        document.getElementById('gpsLat_' + field.name).textContent = lat.toFixed(6);
                        document.getElementById('gpsLng_' + field.name).textContent = lng.toFixed(6);
                        document.getElementById('gpsAcc_' + field.name).textContent = 'Previously captured';
                        document.getElementById('gpsMapLink_' + field.name).href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
                        document.getElementById('gpsDisplay_' + field.name).style.display = 'block';
                        document.getElementById('gpsClearBtn_' + field.name).style.display = 'inline-flex';
                        document.getElementById('gpsStartBtn_' + field.name).innerHTML = '<i class="fas fa-sync"></i> Recapture';
                    }
                } else {
                    clearGPS(field.name);
                }
            } else if (field.type === 'signature') {
                if (record.files && record.files[field.name]) {
                    var sigPreview = document.getElementById('sigExisting_' + field.name);
                    if (sigPreview) {
                        var sigPath = record.files[field.name].file_path;
                        sigPreview.innerHTML = '<div class="sig-edit-label" style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:4px;"><i class="fas fa-signature"></i> Current Signature:</div><img src="' + sigPath + '?' + Date.now() + '" alt="Signature" style="max-width:100%;max-height:120px;border:1px solid var(--border-color);border-radius:4px;background:#fff;">';
                        sigPreview.style.display = 'block';
                    }
                }
            } else if (field.type === 'appointment') {
                if (val) {
                    var parts = val.split(' ');
                    if (parts.length === 2) {
                        var apptDate = parts[0];
                        var apptTime = parts[1];
                        document.getElementById('field_' + field.name).value = val;
                        var dateInput = document.getElementById('apptDate_' + field.name);
                        if (dateInput) {
                            dateInput.value = apptDate;
                            loadAppointmentSlots(field.name, apptTime);
                        }
                        var selectedDiv = document.getElementById('apptSelected_' + field.name);
                        if (selectedDiv) {
                            selectedDiv.innerHTML = '<i class="fas fa-check-circle" style="color:var(--accent-green);"></i> Selected: <strong>' + apptDate + ' ' + apptTime + '</strong> <button type="button" onclick="clearAppointmentSlot(\'' + field.name + '\')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;margin-left:8px;"><i class="fas fa-times"></i></button>';
                            selectedDiv.style.display = 'flex';
                        }
                    }
                }
            } else if (field.type === 'color') {
                var el = document.getElementById('field_' + field.name);
                if (el && val) { el.value = val; updateColorPreview(field.name, val); }
            } else if (field.type !== 'hidden') {
                var el = document.getElementById('field_' + field.name);
                if (el) el.value = val;
            }
        });

        FORM_FIELDS.filter(function(f) { return f.dependent_on && f.type === 'select'; }).forEach(function(childField) {
            var parentField = FORM_FIELDS.find(function(pf) { return pf.name === childField.dependent_on; });
            if (!parentField) return;
            var depOptions = childField.dependent_options || {};
            var parentEl = document.getElementById('field_' + parentField.name);
            if (!parentEl) return;
            var parentVal = parentEl.value;
            var childOpts = depOptions[parentVal] || [];

            var childEl = document.getElementById('field_' + childField.name);
            if (childEl && childEl.tagName === 'SELECT') {
                childEl.innerHTML = '<option value="">-- Select --</option>';
                childOpts.forEach(function(opt) {
                    var o = document.createElement('option');
                    o.value = opt; o.textContent = opt;
                    childEl.appendChild(o);
                });
                var childVal = record.data && record.data[childField.name] ? record.data[childField.name] : '';
                if (childVal) childEl.value = childVal;
            }
            var sdChild = document.getElementById('sd_' + childField.name);
            if (sdChild && sdChild._sdSetValue) {
                var sdOpts = childOpts.map(function(o) { return { value: o, label: o }; });
                initSearchableDropdown('sd_' + childField.name, sdOpts, record.data && record.data[childField.name] ? record.data[childField.name] : '', '-- Select ' + childField.label + ' --');
            }
        });

        document.getElementById('recordModal').classList.add('active');

        setTimeout(function() {
            FORM_FIELDS.filter(function(f) { return f.type === 'signature'; }).forEach(function(field) {
                initSignaturePad(field.name);
                if (record.files && record.files[field.name]) {
                    var pad = signaturePads[field.name];
                    if (pad) {
                        var img = new Image();
                        img.crossOrigin = 'anonymous';
                        img.onload = function() {
                            pad.ctx.drawImage(img, 0, 0, pad.canvas.width / pad.dpr, pad.canvas.height / pad.dpr);
                        };
                        img.onerror = function() {
                            console.error('Failed to load signature image:', record.files[field.name].file_path);
                        };
                        img.src = record.files[field.name].file_path + '?' + Date.now();
                    }
                }
            });
        }, 200);
    }

    // ============================
    // DELETE RECORD - FIXED CSRF
    // ============================
    function deleteRecord(id) {
        console.log('Delete record called with ID:', id);
        
        Swal.fire({
            title: 'Delete Record?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then(function(result) {
            if (result.isConfirmed) {
                var fd = new FormData();
                fd.append('action', 'deleteRecord');
                fd.append('record_id', id);
                fd.append('csrf_token', CSRF_TOKEN);
                
                $.ajax({
                    url: 'crud_view.php?form=' + FORM_SLUG,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    headers: {
                        'X-CSRF-TOKEN': CSRF_TOKEN
                    },
                    success: function(response) {
                        console.log('Delete response:', response);
                        if (response.success) {
                            Swal.fire({ 
                                icon: 'success', 
                                title: 'Deleted!', 
                                text: response.message || 'Record deleted successfully',
                                timer: 2000, 
                                showConfirmButton: false, 
                                background: 'var(--bg-card)', 
                                color: 'var(--text-primary)' 
                            });
                            setTimeout(loadRecords, 100);
                        } else {
                            Swal.fire({ 
                                icon: 'error', 
                                title: 'Error', 
                                text: response.message || 'Failed to delete record',
                                background: 'var(--bg-card)', 
                                color: 'var(--text-primary)' 
                            });
                        }
                    },
                    error: function(xhr, status, err) {
                        console.error('Delete AJAX error:', status, err, xhr.responseText);
                        var msg = 'Could not delete record. Please try again.';
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp && resp.message) msg = resp.message;
                        } catch(e) {}
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

    // ============================
    // VIEW RECORD DETAILS
    // ============================
    function viewRecordDetails(id) {
        var record = null;
        for (var i = 0; i < recordsData.length; i++) {
            if (recordsData[i].id == id) {
                record = recordsData[i];
                break;
            }
        }
        
        if (!record) {
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: 'Record not found. Please refresh the page.',
                background: 'var(--bg-card)', 
                color: 'var(--text-primary)' 
            });
            return;
        }
        
        currentDetailsRecord = record;

        var title = document.getElementById('detailsModalTitle');
        title.innerHTML = '<i class="fas fa-eye"></i> Record #' + record.id + ' Details';

        var container = document.getElementById('recordDetailsContent');
        var html = '<div class="record-details-grid">';

        FORM_FIELDS.forEach(function(field) {
            var val = record.data && record.data[field.name] !== undefined ? record.data[field.name] : '';
            if (field.type === 'hidden') return;
            
            var displayVal = val;
            if (field.type === 'file' || field.type === 'camera') {
                if (val) {
                    if (val.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
                        displayVal = '<img src="' + val + '" alt="' + field.label + '" style="max-width:100%;max-height:120px;border-radius:4px;">';
                    } else {
                        displayVal = '<a href="' + val + '" target="_blank"><i class="fas fa-file"></i> Download</a>';
                    }
                } else {
                    displayVal = '<span style="color:var(--text-muted);">No file</span>';
                }
            } else if (field.type === 'gps' && val) {
                var parts = val.split(',');
                if (parts.length === 2) {
                    var lat = parseFloat(parts[0]).toFixed(6);
                    var lng = parseFloat(parts[1]).toFixed(6);
                    displayVal = '<a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank"><i class="fas fa-map-marker-alt"></i> ' + lat + ', ' + lng + '</a>';
                }
            } else if (field.type === 'color' && val) {
                displayVal = '<span style="display:inline-block;width:20px;height:20px;background:' + val + ';border-radius:4px;border:1px solid var(--border-color);vertical-align:middle;"></span> ' + val.toUpperCase();
            } else if (field.type === 'url' || field.type === 'link') {
                if (val) displayVal = '<a href="' + val + '" target="_blank">' + val + '</a>';
            } else if (field.type === 'appointment' && val) {
                displayVal = '<i class="fas fa-calendar-check" style="color:var(--accent-green);"></i> ' + val;
            } else if (field.type === 'signature' && record.files && record.files[field.name]) {
                displayVal = '<i class="fas fa-signature" style="color:var(--accent-blue);"></i> <a href="' + record.files[field.name].file_path + '" target="_blank">View Signature</a>';
            } else if (!val) {
                displayVal = '<span style="color:var(--text-muted);">—</span>';
            }
            
            html += '<div class="detail-item">';
            html += '<div class="detail-label">' + (field.label || field.name) + '</div>';
            html += '<div class="detail-value">' + displayVal + '</div>';
            html += '</div>';
        });

        html += '<div class="detail-item">';
        html += '<div class="detail-label">Submitted By</div>';
        html += '<div class="detail-value">' + (record.submitted_by_username || 'System') + '</div>';
        html += '</div>';

        html += '<div class="detail-item">';
        html += '<div class="detail-label">Date</div>';
        html += '<div class="detail-value">' + new Date(record.created_at).toLocaleString() + '</div>';
        html += '</div>';

        html += '</div>';
        container.innerHTML = html;

        document.getElementById('recordDetailsModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeRecordDetails() {
        document.getElementById('recordDetailsModal').classList.remove('active');
        document.body.style.overflow = '';
        currentDetailsRecord = null;
    }

    // ============================
    // EXPORT FUNCTIONS
    // ============================
    function exportDetails(type) {
        if (!currentDetailsRecord) {
            Swal.fire({ icon: 'warning', title: 'No record selected', text: 'Please open a record first.' });
            return;
        }

        var record = currentDetailsRecord;
        var data = record.data || {};
        var rows = [];
        var headers = ['Field', 'Value'];

        FORM_FIELDS.forEach(function(field) {
            if (field.type === 'hidden') return;
            var val = data[field.name] || '';
            rows.push([field.label, val]);
        });
        rows.push(['Submitted By', record.submitted_by_username || 'System']);
        rows.push(['Date', new Date(record.created_at).toLocaleString()]);
        rows.push(['Record ID', record.id]);

        var csvContent = headers.join(',') + '\n' + rows.map(function(r) { 
            return r.map(function(c) { 
                var str = String(c).replace(/"/g, '""');
                return '"' + str + '"'; 
            }).join(',');
        }).join('\n');

        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });

        switch(type) {
            case 'copy':
                navigator.clipboard.writeText(csvContent).then(function() {
                    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Record details copied to clipboard.', timer: 1500, showConfirmButton: false });
                }).catch(function() {
                    var textarea = document.createElement('textarea');
                    textarea.value = csvContent;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Record details copied to clipboard.', timer: 1500, showConfirmButton: false });
                });
                break;
            case 'csv':
                var link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'record_' + record.id + '.csv';
                link.click();
                URL.revokeObjectURL(link.href);
                Swal.fire({ icon: 'success', title: 'CSV Exported!', timer: 1500, showConfirmButton: false });
                break;
            case 'excel':
                var excelHtml = '<table>';
                excelHtml += '<tr><th>Field</th><th>Value</th></tr>';
                rows.forEach(function(r) {
                    excelHtml += '<tr><td>' + r[0] + '</td><td>' + r[1] + '</td></tr>';
                });
                excelHtml += '</table>';
                var excelBlob = new Blob([excelHtml], { type: 'application/vnd.ms-excel' });
                var link = document.createElement('a');
                link.href = URL.createObjectURL(excelBlob);
                link.download = 'record_' + record.id + '.xls';
                link.click();
                URL.revokeObjectURL(link.href);
                Swal.fire({ icon: 'success', title: 'Excel Exported!', timer: 1500, showConfirmButton: false });
                break;
            case 'pdf':
            case 'print':
                var printWindow = window.open('', '_blank', 'width=800,height=600');
                printWindow.document.write('<!DOCTYPE html><html><head><title>Record #' + record.id + '</title>');
                printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:40px;max-width:800px;margin:0 auto;}h1{color:#001f3f;border-bottom:2px solid #001f3f;padding-bottom:10px;}table{width:100%;border-collapse:collapse;margin-top:20px;}td{padding:10px 12px;border-bottom:1px solid #e0e0e0;}.label{font-weight:600;color:#555;width:30%;}.value{color:#222;}@media print{.no-print{display:none;}}</style>');
                printWindow.document.write('</head><body>');
                printWindow.document.write('<h1>Record #' + record.id + '</h1>');
                printWindow.document.write('<table>');
                rows.forEach(function(r) {
                    printWindow.document.write('<tr><td class="label">' + r[0] + '</td><td class="value">' + r[1] + '</td></tr>');
                });
                printWindow.document.write('</table>');
                printWindow.document.write('<p style="margin-top:30px;color:#999;font-size:11px;">' + (type === 'pdf' ? 'Generated' : 'Printed') + ' on ' + new Date().toLocaleString() + '</p>');
                printWindow.document.write('</body></html>');
                printWindow.document.close();
                printWindow.onload = function() { printWindow.print(); };
                break;
            default:
                Swal.fire({ icon: 'error', title: 'Invalid export type' });
        }
    }

    function exportData(type) {
        if (!recordsTable) {
            Swal.fire({ icon: 'warning', title: 'Table not loaded', text: 'Please wait for records to load.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }
        try {
            var buttons = recordsTable.buttons();
            var idx = { copy: 0, csv: 1, excel: 2, pdf: 3, print: 4 };
            if (type in idx && buttons.button(idx[type])) {
                buttons.button(idx[type]).trigger();
                Swal.fire({ icon: 'success', title: 'Export started', timer: 1500, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
            } else {
                Swal.fire({ icon: 'error', title: 'Export failed', text: 'Export type not available.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            }
        } catch(e) {
            Swal.fire({ icon: 'error', title: 'Export failed', text: e.message, background: 'var(--bg-card)', color: 'var(--text-primary)' });
        }
    }

    // ============================
    // CHARTS
    // ============================
    function loadCharts() {
        if (chartFields.length === 0) return;
        chartFields.forEach(function(field) {
            var canvas = document.getElementById('chart_' + field.name);
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var chartType = field.chart_type || 'bar';
            
            $.ajax({
                url: 'crud_view.php?form=' + FORM_SLUG + '&action=getChartData&field=' + encodeURIComponent(field.name) + '&type=' + chartType,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var chartData = buildChartData(response, field);
                        if (chartInstances[field.name]) {
                            chartInstances[field.name].destroy();
                        }
                        chartInstances[field.name] = new Chart(ctx, chartData);
                    } else {
                        showChartEmpty(canvas);
                    }
                },
                error: function() {
                    showChartEmpty(canvas);
                }
            });
        });
    }

    function buildChartData(response, field) {
        var chartType = field.chart_type || 'bar';
        var isPie = ['pie', 'doughnut'].indexOf(chartType) >= 0;
        var colors = ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#ef4444', '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#8b5cf6'];

        var datasets = [];
        var labels = [];

        if (response.labels && response.data) {
            labels = response.labels;
            datasets = [{
                label: field.chart_label || field.label,
                data: response.data,
                backgroundColor: isPie ? colors.slice(0, response.data.length) : colors[0] + '80',
                borderColor: isPie ? colors.slice(0, response.data.length) : colors[0],
                borderWidth: isPie ? 2 : 1,
                fill: chartType === 'area',
                tension: 0.4
            }];
        } else if (response.data && typeof response.data === 'object') {
            labels = Object.keys(response.data);
            datasets = [{
                label: field.chart_label || field.label,
                data: Object.values(response.data),
                backgroundColor: isPie ? colors.slice(0, Object.keys(response.data).length) : colors[0] + '80',
                borderColor: isPie ? colors.slice(0, Object.keys(response.data).length) : colors[0],
                borderWidth: isPie ? 2 : 1,
                fill: chartType === 'area',
                tension: 0.4
            }];
        } else if (Array.isArray(response.data)) {
            var dist = {};
            response.data.forEach(function(v) {
                var key = String(v);
                dist[key] = (dist[key] || 0) + 1;
            });
            labels = Object.keys(dist);
            datasets = [{
                label: field.chart_label || field.label,
                data: Object.values(dist),
                backgroundColor: isPie ? colors.slice(0, labels.length) : colors[0] + '80',
                borderColor: isPie ? colors.slice(0, labels.length) : colors[0],
                borderWidth: isPie ? 2 : 1,
                fill: chartType === 'area',
                tension: 0.4
            }];
        }

        var config = {
            type: chartType === 'area' ? 'line' : chartType,
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: labels.length <= 8,
                        labels: { color: getComputedStyle(document.body).getPropertyValue('--text-secondary').trim() || '#94a3b8' }
                    }
                },
                scales: isPie ? {} : {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: getComputedStyle(document.body).getPropertyValue('--text-secondary').trim() || '#94a3b8', maxRotation: 45 }
                    },
                    y: {
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: getComputedStyle(document.body).getPropertyValue('--text-secondary').trim() || '#94a3b8' },
                        beginAtZero: true
                    }
                }
            }
        };

        if (chartType === 'area') {
            datasets.forEach(function(ds) { ds.fill = true; });
        }

        return config;
    }

    function showChartEmpty(canvas) {
        var parent = canvas.parentElement;
        parent.innerHTML = '<div class="chart-empty"><i class="fas fa-chart-simple"></i><p>No data available</p></div>';
    }

    // ============================
    // FORM RENDERER
    // ============================
    function renderDynamicFormFields() {
        var container = document.getElementById('dynamicFields');
        var progressContainer = document.getElementById('stepsProgressContainer');
        var stepNavEl = document.getElementById('stepNav');
        var defaultActions = document.getElementById('defaultFormActions');

        var stepGroups = {};
        FORM_FIELDS.forEach(function(field) {
            var s = field.step || 1;
            if (!stepGroups[s]) stepGroups[s] = [];
            stepGroups[s].push(field);
        });
        if (!IS_MULTISTEP) stepGroups = { 1: FORM_FIELDS };

        if (IS_MULTISTEP && progressContainer) {
            var ph = '<div class="form-steps-progress" id="crudStepsProgress">';
            FORM_STEPS.forEach(function(name, i) {
                if (i > 0) ph += '<div class="step-line" id="crudStepLine_' + (i) + '"></div>';
                ph += '<div class="step-item' + (i === 0 ? ' active' : '') + '" id="crudStepItem_' + (i + 1) + '">';
                ph += '<div class="step-circle">' + (i + 1) + '</div>';
                ph += '<div class="step-label">' + (name || 'Step ' + (i + 1)) + '</div>';
                ph += '</div>';
            });
            ph += '</div>';
            progressContainer.innerHTML = ph;
        }

        var html = '';
        var stepNums = Object.keys(stepGroups).sort(function(a,b){ return a-b; });
        stepNums.forEach(function(stepNum) {
            var isActive = (!IS_MULTISTEP || parseInt(stepNum) === 1);
            if (IS_MULTISTEP) {
                html += '<div class="step-content' + (isActive ? ' active' : '') + '" data-step="' + stepNum + '">';
            }
            html += '<div class="form-grid">';

            stepGroups[stepNum].forEach(function(field) {
                var req = field.required ? 'required' : '';
                var reqMark = field.required ? ' *' : '';
                var widthClass = field.width === 'full' ? 'style="grid-column: 1 / -1;"' : '';
                var placeholder = field.placeholder ? 'placeholder="' + field.placeholder.replace(/"/g, '&quot;') + '"' : '';
                var iconHtml = field.icon ? '<i class="' + field.icon + '"></i> ' : '';
                var condAttr = field.condition ? ' data-condition=\'' + JSON.stringify(field.condition).replace(/'/g, "&#39;") + '\'' : '';

                html += '<div class="form-group" ' + widthClass + condAttr + '>';
                html += '<label>' + iconHtml + field.label + reqMark + '</label>';

                if (field.reference_image) {
                    html += '<div class="field-ref-img-display" onclick="Swal.fire({imageUrl:\'' + field.reference_image + '\',imageAlt:\'Reference\',showConfirmButton:true,confirmButtonText:\'Close\',confirmButtonColor:\'#001f3f\',width:\'auto\',background:\'var(--bg-card)\',color:\'var(--text-primary)\'})">';
                    html += '<img src="' + field.reference_image + '" alt="Reference">';
                    html += '<span><i class="fas fa-image"></i> Click to enlarge</span>';
                    html += '</div>';
                }

                switch (field.type) {
                    case 'textarea':
                        html += '<textarea id="field_' + field.name + '" name="' + field.name + '" rows="3" ' + placeholder + ' ' + req + '></textarea>';
                        break;
                    case 'select':
                        var opts = field.options || [];
                        if (opts.length >= 5) {
                            html += '<input type="hidden" id="field_' + field.name + '" name="' + field.name + '" ' + req + '>';
                            html += '<div id="sd_' + field.name + '" data-hidden-input="field_' + field.name + '" data-field-name="' + field.name + '"></div>';
                        } else {
                            html += '<select id="field_' + field.name + '" name="' + field.name + '" ' + req + '>';
                            html += '<option value="">-- Select --</option>';
                            opts.forEach(function(opt) {
                                html += '<option value="' + opt.replace(/"/g, '&quot;') + '">' + opt + '</option>';
                            });
                            html += '</select>';
                        }
                        break;
                    case 'radio':
                        html += '<div class="checkbox-group">';
                        (field.options || []).forEach(function(opt, i) {
                            html += '<label class="checkbox-label"><input type="radio" name="' + field.name + '" value="' + opt.replace(/"/g, '&quot;') + '" ' + (i === 0 && field.required ? 'required' : '') + '> ' + opt + '</label>';
                        });
                        html += '</div>';
                        break;
                    case 'checkbox':
                        if (field.options && field.options.length > 0) {
                            html += '<div class="checkbox-group">';
                            field.options.forEach(function(opt) {
                                html += '<label class="checkbox-label"><input type="checkbox" name="' + field.name + '" value="' + opt.replace(/"/g, '&quot;') + '"> ' + opt + '</label>';
                            });
                            html += '</div>';
                        } else {
                            html += '<div class="checkbox-group"><label class="checkbox-label"><input type="checkbox" id="field_' + field.name + '" name="' + field.name + '" value="1"> ' + field.label + '</label></div>';
                        }
                        break;
                    case 'file':
                        html += '<input type="file" id="field_' + field.name + '" name="' + field.name + '" accept="' + (field.accept || '*/*') + '" ' + req + '>';
                        html += '<small style="color:var(--text-muted)">Max: ' + (field.max_size || 2) + 'MB</small>';
                        html += '<div id="filePreview_' + field.name + '" class="file-preview-box" style="display:none;margin-top:8px;"></div>';
                        break;
                    case 'signature':
                        html += '<div class="signature-wrapper">';
                        html += '<div class="signature-canvas-container" id="sigContainer_' + field.name + '">';
                        html += '<canvas id="sigCanvas_' + field.name + '" class="signature-canvas"></canvas>';
                        html += '</div>';
                        html += '<div id="sigExisting_' + field.name + '" class="signature-existing-preview" style="display:none;"></div>';
                        html += '<div class="signature-btn-row">';
                        html += '<button type="button" class="btn btn-sm btn-ghost" onclick="clearSignature(\'' + field.name + '\')"><i class="fas fa-eraser"></i> Clear</button>';
                        html += '</div>';
                        html += '<input type="hidden" id="field_' + field.name + '" name="' + field.name + '">';
                        html += '</div>';
                        break;
                    case 'qr_scanner':
                        html += '<div class="qr-scanner-wrapper" id="qrWrapper_' + field.name + '">';
                        html += '<input type="text" id="field_' + field.name + '" name="' + field.name + '" ' + placeholder + ' ' + req + ' readonly>';
                        html += '<div class="qr-btn-row">';
                        html += '<button type="button" class="btn btn-primary btn-sm" onclick="startQRScanner(\'' + field.name + '\')"><i class="fas fa-qrcode"></i> Scan</button>';
                        html += '<button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'field_' + field.name + '\').value=\'\'" style="display:none;" id="qrClear_' + field.name + '"><i class="fas fa-times"></i> Clear</button>';
                        html += '</div>';
                        html += '<div class="qr-video-container" id="qrVideo_' + field.name + '" style="display:none;">';
                        html += '<video id="qrStream_' + field.name + '" playsinline></video>';
                        html += '<div class="qr-overlay"><div class="qr-scan-line"></div></div>';
                        html += '<button type="button" class="btn btn-danger btn-sm" onclick="stopQRScanner(\'' + field.name + '\')" style="margin-top:8px;"><i class="fas fa-stop"></i> Stop</button>';
                        html += '</div>';
                        html += '</div>';
                        break;
                    case 'camera':
                        html += '<div class="camera-capture-wrapper" id="cameraWrapper_' + field.name + '">';
                        html += '<input type="hidden" id="field_' + field.name + '" name="' + field.name + '">';
                        html += '<div class="camera-preview" id="cameraPreview_' + field.name + '" style="display:none;">';
                        html += '<img id="cameraImg_' + field.name + '" alt="Captured photo">';
                        html += '</div>';
                        html += '<div class="camera-video-container" id="cameraVideo_' + field.name + '" style="display:none;">';
                        html += '<video id="cameraStream_' + field.name + '" playsinline></video>';
                        html += '</div>';
                        html += '<canvas id="cameraCanvas_' + field.name + '" style="display:none;"></canvas>';
                        html += '<div class="camera-btn-row" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">';
                        html += '<button type="button" class="btn btn-primary btn-sm" id="cameraStartBtn_' + field.name + '" onclick="startCamera(\'' + field.name + '\')"><i class="fas fa-camera"></i> Open Camera</button>';
                        html += '<button type="button" class="btn btn-success btn-sm" id="cameraCaptureBtn_' + field.name + '" onclick="capturePhoto(\'' + field.name + '\')" style="display:none;"><i class="fas fa-circle"></i> Capture</button>';
                        html += '<button type="button" class="btn btn-ghost btn-sm" id="cameraRetakeBtn_' + field.name + '" onclick="retakePhoto(\'' + field.name + '\')" style="display:none;"><i class="fas fa-redo"></i> Retake</button>';
                        html += '</div>';
                        html += '</div>';
                        break;
                    case 'gps':
                        html += '<div class="gps-capture-wrapper" id="gpsWrapper_' + field.name + '">';
                        html += '<input type="hidden" id="field_' + field.name + '" name="' + field.name + '">';
                        html += '<div class="gps-display" id="gpsDisplay_' + field.name + '" style="display:none;">';
                        html += '<div class="gps-coords-box">';
                        html += '<div class="gps-coord-item"><i class="fas fa-crosshairs" style="color:var(--accent-blue);"></i> <strong>Lat:</strong> <span id="gpsLat_' + field.name + '">--</span></div>';
                        html += '<div class="gps-coord-item"><i class="fas fa-crosshairs" style="color:var(--accent-blue);"></i> <strong>Lng:</strong> <span id="gpsLng_' + field.name + '">--</span></div>';
                        html += '<div class="gps-coord-item"><i class="fas fa-bullseye" style="color:var(--accent-green);"></i> <strong>Accuracy:</strong> <span id="gpsAcc_' + field.name + '">--</span></div>';
                        html += '</div>';
                        html += '<a id="gpsMapLink_' + field.name + '" href="#" target="_blank" class="btn btn-sm" style="background:var(--accent-blue);color:#fff;margin-top:8px;display:inline-flex;align-items:center;gap:5px;font-size:12px;"><i class="fas fa-map"></i> View on Map</a>';
                        html += '</div>';
                        html += '<div class="gps-btn-row" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">';
                        html += '<button type="button" class="btn btn-primary btn-sm" id="gpsStartBtn_' + field.name + '" onclick="captureGPS(\'' + field.name + '\')"><i class="fas fa-map-marker-alt"></i> Capture GPS</button>';
                        html += '<button type="button" class="btn btn-ghost btn-sm" id="gpsClearBtn_' + field.name + '" onclick="clearGPS(\'' + field.name + '\')" style="display:none;"><i class="fas fa-times"></i> Clear</button>';
                        html += '</div>';
                        html += '<div id="gpsLoading_' + field.name + '" style="display:none;padding:10px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Getting location...</div>';
                        html += '</div>';
                        break;
                    case 'color':
                        html += '<div class="color-picker-wrapper" id="colorWrap_' + field.name + '">';
                        html += '<div class="color-picker-swatch" id="colorSwatch_' + field.name + '" style="background:#001f3f;" onclick="document.getElementById(\'field_' + field.name + '\').click()"></div>';
                        html += '<div class="color-picker-info"><span class="color-picker-hex" id="colorHex_' + field.name + '">#001f3f</span><span class="color-picker-label">Click swatch to change</span></div>';
                        html += '<input type="color" id="field_' + field.name + '" name="' + field.name + '" value="#001f3f" ' + req + ' class="color-picker-input" onchange="updateColorPreview(\'' + field.name + '\', this.value)" oninput="updateColorPreview(\'' + field.name + '\', this.value)">';
                        html += '</div>';
                        break;
                    case 'range':
                        var min = (field.validation && field.validation.min != null) ? field.validation.min : 0;
                        var max = (field.validation && field.validation.max != null) ? field.validation.max : 100;
                        var step = (field.validation && field.validation.step != null) ? field.validation.step : 1;
                        html += '<input type="range" id="field_' + field.name + '" name="' + field.name + '" min="' + min + '" max="' + max + '" step="' + step + '" ' + req + '>';
                        html += '<span id="rangeVal_' + field.name + '" style="color:var(--text-secondary);margin-left:8px;"></span>';
                        break;
                    case 'appointment':
                        var apptConfig = field.appointment_config || {};
                        var advDays = apptConfig.advance_days || 30;
                        var today = new Date();
                        var maxDate = new Date(today.getTime() + advDays * 86400000);
                        var minDateStr = today.toISOString().split('T')[0];
                        var maxDateStr = maxDate.toISOString().split('T')[0];
                        html += '<div class="appt-picker-wrapper" id="apptWrapper_' + field.name + '" data-config=\'' + JSON.stringify(apptConfig).replace(/'/g, "&#39;") + '\'>';
                        html += '<input type="hidden" id="field_' + field.name + '" name="' + field.name + '" ' + req + '>';
                        html += '<div class="appt-date-row" style="margin-bottom:8px;">';
                        html += '<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><i class="fas fa-calendar" style="color:var(--accent-blue);"></i> Select Date</label>';
                        html += '<input type="date" id="apptDate_' + field.name + '" min="' + minDateStr + '" max="' + maxDateStr + '" onchange="loadAppointmentSlots(\'' + field.name + '\')" style="max-width:220px;">';
                        html += '</div>';
                        html += '<div id="apptSlotsContainer_' + field.name + '" class="appt-slots-container" style="display:none;"></div>';
                        html += '<div id="apptSelected_' + field.name + '" class="appt-selected-slot" style="display:none;"></div>';
                        html += '</div>';
                        break;
                    default:
                        html += '<input type="' + field.type + '" id="field_' + field.name + '" name="' + field.name + '" ' + placeholder + ' ' + req + '>';
                }

                html += '</div>';
            });

            html += '</div>';
            if (IS_MULTISTEP) html += '</div>';
        });

        container.innerHTML = html;

        if (IS_MULTISTEP && stepNavEl) {
            crudCurrentStep = 1;
            var totalSteps = FORM_STEPS.length;
            stepNavEl.style.display = '';
            stepNavEl.innerHTML = '<div class="step-navigation">'
                + '<button type="button" class="btn btn-ghost" id="crudPrevBtn" onclick="crudChangeStep(-1)" style="display:none;"><i class="fas fa-arrow-left"></i> Previous</button>'
                + '<span class="step-indicator-text" id="crudStepIndicator">Step 1 of ' + totalSteps + '</span>'
                + '<button type="button" class="btn btn-primary" id="crudNextBtn" onclick="crudChangeStep(1)">Next <i class="fas fa-arrow-right"></i></button>'
                + '<button type="submit" class="btn btn-success" id="crudSubmitBtn" style="display:none;"><i class="fas fa-save"></i> Save</button>'
                + '</div>';
            if (defaultActions) defaultActions.style.display = 'none';
        } else {
            if (stepNavEl) stepNavEl.style.display = 'none';
            if (defaultActions) defaultActions.style.display = '';
        }

        FORM_FIELDS.filter(function(f) { return f.type === 'signature'; }).forEach(function(field) {
            setTimeout(function() { initSignaturePad(field.name); }, 100);
        });

        FORM_FIELDS.filter(function(f) { return f.type === 'range'; }).forEach(function(field) {
            var el = document.getElementById('field_' + field.name);
            var span = document.getElementById('rangeVal_' + field.name);
            if (el && span) {
                span.textContent = el.value;
                el.addEventListener('input', function() { span.textContent = el.value; });
            }
        });

        FORM_FIELDS.filter(function(f) { return f.type === 'select' && (f.options || []).length >= 5; }).forEach(function(field) {
            var sdOptions = (field.options || []).map(function(opt) {
                return { value: opt, label: opt };
            });
            initSearchableDropdown('sd_' + field.name, sdOptions, '', '-- Select ' + field.label + ' --');
        });

        FORM_FIELDS.filter(function(f) { return f.dependent_on && ['select','radio','checkbox'].indexOf(f.type) >= 0; }).forEach(function(childField) {
            var parentField = FORM_FIELDS.find(function(pf) { return pf.name === childField.dependent_on; });
            if (!parentField) return;
            var depOptions = childField.dependent_options || {};

            function updateDependentOptions() {
                var parentEl = document.getElementById('field_' + parentField.name);
                if (!parentEl) return;
                var parentVal = parentEl.value;
                var childOpts = depOptions[parentVal] || [];

                var childEl = document.getElementById('field_' + childField.name);
                if (childEl && childEl.tagName === 'SELECT') {
                    childEl.innerHTML = '<option value="">-- Select --</option>';
                    childOpts.forEach(function(opt) {
                        childEl.innerHTML += '<option value="' + opt.replace(/"/g, '&quot;') + '">' + opt + '</option>';
                    });
                }

                var sdWrapper = document.getElementById('sd_' + childField.name);
                if (sdWrapper && sdWrapper._sdSetValue) {
                    sdWrapper._sdSetValue('');
                    var sdOpts = childOpts.map(function(o) { return { value: o, label: o }; });
                    initSearchableDropdown('sd_' + childField.name, sdOpts, '', '-- Select ' + childField.label + ' --');
                }
            }

            var parentEl = document.getElementById('field_' + parentField.name);
            if (parentEl) {
                parentEl.addEventListener('change', updateDependentOptions);
            }
            var parentHidden = document.getElementById('field_' + parentField.name);
            if (parentHidden && parentHidden.type === 'hidden') {
                var observer = new MutationObserver(function() { updateDependentOptions(); });
                observer.observe(parentHidden, { attributes: true, attributeFilter: ['value'] });
                var sdParent = document.getElementById('sd_' + parentField.name);
                if (sdParent) {
                    sdParent._onChange = function() { setTimeout(updateDependentOptions, 50); };
                }
            }
            updateDependentOptions();
        });

        crudEvaluateConditions();
        var form = document.getElementById('recordForm');
        if (form) {
            form.addEventListener('change', crudEvaluateConditions);
            form.addEventListener('input', crudEvaluateConditions);
        }
    }

    function initSearchableDropdown(wrapperId, options, selectedValue, placeholder) {
        var wrapper = document.getElementById(wrapperId);
        if (!wrapper) return;
        wrapper.innerHTML = '';
        wrapper.classList.add('searchable-dropdown');

        var hiddenInput = wrapper.getAttribute('data-hidden-input');
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'searchable-dropdown-input';
        input.placeholder = placeholder || 'Select...';
        input.autocomplete = 'off';

        var arrow = document.createElement('span');
        arrow.className = 'searchable-dropdown-arrow';
        arrow.innerHTML = '<i class="fas fa-chevron-down"></i>';

        var list = document.createElement('div');
        list.className = 'searchable-dropdown-list';
        list.style.display = 'none';

        wrapper.appendChild(input);
        wrapper.appendChild(arrow);
        wrapper.appendChild(list);

        var isOpen = false;
        var currentValue = selectedValue || '';

        var sel = options.find(function(o) { return o.value === currentValue; });
        if (sel) input.value = sel.label;

        if (hiddenInput) {
            var hi = document.getElementById(hiddenInput);
            if (hi) hi.value = currentValue;
        }

        function renderList(filter) {
            var html = '';
            var filtered = options.filter(function(o) {
                return !filter || o.label.toLowerCase().indexOf(filter.toLowerCase()) >= 0;
            });
            if (filtered.length === 0) {
                html = '<div class="searchable-dropdown-item no-results">No results found</div>';
            } else {
                filtered.forEach(function(o) {
                    var selClass = o.value === currentValue ? ' selected' : '';
                    var iconHtml = o.icon ? '<i class="' + o.icon + '"></i> ' : '';
                    html += '<div class="searchable-dropdown-item' + selClass + '" data-value="' + o.value + '">' + iconHtml + o.label + '</div>';
                });
            }
            list.innerHTML = html;
        }

        function open() {
            isOpen = true;
            list.style.display = 'block';
            arrow.classList.add('open');
            input.value = '';
            input.placeholder = 'Type to search...';
            renderList('');
        }

        function close() {
            isOpen = false;
            list.style.display = 'none';
            arrow.classList.remove('open');
            var sel = options.find(function(o) { return o.value === currentValue; });
            input.value = sel ? sel.label : '';
            input.placeholder = placeholder || 'Select...';
        }

        function selectOption(val) {
            currentValue = val;
            if (hiddenInput) {
                var hi = document.getElementById(hiddenInput);
                if (hi) hi.value = val;
            }
            if (wrapper._onChange) wrapper._onChange(val);
            close();
        }

        input.addEventListener('click', function() {
            if (isOpen) close(); else open();
        });

        input.addEventListener('input', function() {
            if (!isOpen) open();
            renderList(input.value);
        });

        list.addEventListener('click', function(e) {
            var item = e.target.closest('.searchable-dropdown-item');
            if (item && !item.classList.contains('no-results')) {
                selectOption(item.getAttribute('data-value'));
            }
        });

        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target) && isOpen) close();
        });

        wrapper._sdGetValue = function() { return currentValue; };
        wrapper._sdSetValue = function(v) {
            currentValue = v;
            if (hiddenInput) {
                var hi = document.getElementById(hiddenInput);
                if (hi) hi.value = v;
            }
            var sel = options.find(function(o) { return o.value === v; });
            input.value = sel ? sel.label : '';
        };
    }

    function crudChangeStep(dir) {
        var newStep = crudCurrentStep + dir;
        if (newStep < 1 || newStep > FORM_STEPS.length) return;

        if (dir > 0) {
            var curContent = document.querySelector('#dynamicFields .step-content[data-step="' + crudCurrentStep + '"]');
            if (curContent) {
                var invalid = false;
                curContent.querySelectorAll('[required]').forEach(function(el) {
                    var fg = el.closest('.form-group');
                    if (fg && fg.style.display === 'none') return;
                    if (!el.value) { el.reportValidity(); invalid = true; }
                });
                if (invalid) return;
            }
        }

        document.querySelectorAll('#dynamicFields .step-content').forEach(function(s) { s.classList.remove('active'); });
        var target = document.querySelector('#dynamicFields .step-content[data-step="' + newStep + '"]');
        if (target) target.classList.add('active');

        crudCurrentStep = newStep;
        crudUpdateStepUI();
    }

    function crudUpdateStepUI() {
        if (!IS_MULTISTEP) return;
        var total = FORM_STEPS.length;
        for (var i = 1; i <= total; i++) {
            var item = document.getElementById('crudStepItem_' + i);
            if (!item) continue;
            item.classList.remove('active', 'completed');
            if (i === crudCurrentStep) item.classList.add('active');
            else if (i < crudCurrentStep) item.classList.add('completed');
        }
        for (var j = 1; j < total; j++) {
            var line = document.getElementById('crudStepLine_' + j);
            if (line) line.classList.toggle('completed', j < crudCurrentStep);
        }
        var prev = document.getElementById('crudPrevBtn');
        var next = document.getElementById('crudNextBtn');
        var sub = document.getElementById('crudSubmitBtn');
        var ind = document.getElementById('crudStepIndicator');
        if (prev) prev.style.display = crudCurrentStep > 1 ? '' : 'none';
        if (next) next.style.display = crudCurrentStep < total ? '' : 'none';
        if (sub) sub.style.display = crudCurrentStep === total ? '' : 'none';
        if (ind) ind.textContent = 'Step ' + crudCurrentStep + ' of ' + total;
    }

    function crudEvaluateConditions() {
        document.querySelectorAll('#dynamicFields .form-group[data-condition]').forEach(function(fg) {
            try {
                var cond = JSON.parse(fg.dataset.condition);
                var visible = crudCheckCondition(cond);
                fg.style.display = visible ? '' : 'none';
                fg.querySelectorAll('[required]').forEach(function(el) {
                    if (!visible) { el.removeAttribute('required'); el.dataset.wasRequired = '1'; }
                    else if (el.dataset.wasRequired === '1') { el.setAttribute('required', ''); }
                });
            } catch(e) {}
        });
    }

    function crudCheckCondition(cond) {
        if (!cond || !cond.field) return true;
        var val = crudGetFieldValue(cond.field);
        switch (cond.operator) {
            case 'equals': return val === cond.value;
            case 'not_equals': return val !== cond.value;
            case 'contains': return val.indexOf(cond.value) >= 0;
            case 'not_empty': return val !== '';
            case 'empty': return val === '';
            case 'greater_than': return parseFloat(val) > parseFloat(cond.value);
            case 'less_than': return parseFloat(val) < parseFloat(cond.value);
            default: return true;
        }
    }

    function crudGetFieldValue(fieldName) {
        var radio = document.querySelector('#recordForm input[name="' + fieldName + '"]:checked');
        if (radio) return radio.value;
        var checkboxes = document.querySelectorAll('#recordForm input[name="' + fieldName + '"]:checked');
        if (checkboxes.length > 0) {
            var vals = [];
            checkboxes.forEach(function(cb) { vals.push(cb.value); });
            return vals.join(',');
        }
        var el = document.querySelector('#recordForm [name="' + fieldName + '"]');
        return el ? el.value : '';
    }

    function initSignaturePad(fieldName) {
        var canvas = document.getElementById('sigCanvas_' + fieldName);
        if (!canvas) return;

        var container = document.getElementById('sigContainer_' + fieldName);
        var dpr = window.devicePixelRatio || 1;
        var containerWidth = container ? container.offsetWidth : 400;
        var canvasHeight = 150;

        canvas.width = containerWidth * dpr;
        canvas.height = canvasHeight * dpr;
        canvas.style.width = containerWidth + 'px';
        canvas.style.height = canvasHeight + 'px';

        var ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        var drawing = false;

        canvas.addEventListener('mousedown', function(e) { drawing = true; ctx.beginPath(); ctx.moveTo(e.offsetX, e.offsetY); });
        canvas.addEventListener('mousemove', function(e) { if (!drawing) return; ctx.lineTo(e.offsetX, e.offsetY); ctx.stroke(); });
        canvas.addEventListener('mouseup', function() { drawing = false; });
        canvas.addEventListener('mouseleave', function() { drawing = false; });

        canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var r = canvas.getBoundingClientRect(); var scaleX = canvas.width / dpr / r.width; var scaleY = canvas.height / dpr / r.height; ctx.beginPath(); ctx.moveTo((e.touches[0].clientX - r.left) * scaleX, (e.touches[0].clientY - r.top) * scaleY); }, { passive: false });
        canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; var r = canvas.getBoundingClientRect(); var scaleX = canvas.width / dpr / r.width; var scaleY = canvas.height / dpr / r.height; ctx.lineTo((e.touches[0].clientX - r.left) * scaleX, (e.touches[0].clientY - r.top) * scaleY); ctx.stroke(); }, { passive: false });
        canvas.addEventListener('touchend', function() { drawing = false; });

        ctx.strokeStyle = '#001f3f';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        signaturePads[fieldName] = { canvas: canvas, ctx: ctx, dpr: dpr };
    }

    function clearSignature(fieldName) {
        var pad = signaturePads[fieldName];
        if (pad) {
            pad.ctx.clearRect(0, 0, pad.canvas.width / pad.dpr, pad.canvas.height / pad.dpr);
        }
        var existingPreview = document.getElementById('sigExisting_' + fieldName);
        if (existingPreview) { existingPreview.style.display = 'none'; existingPreview.innerHTML = ''; }
    }

    function playBeep() {
        try {
            var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            var osc1 = audioCtx.createOscillator();
            var gain1 = audioCtx.createGain();
            osc1.type = 'sine';
            osc1.frequency.value = 1800;
            gain1.gain.setValueAtTime(0.3, audioCtx.currentTime);
            gain1.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.15);
            osc1.connect(gain1);
            gain1.connect(audioCtx.destination);
            osc1.start(audioCtx.currentTime);
            osc1.stop(audioCtx.currentTime + 0.15);
            var osc2 = audioCtx.createOscillator();
            var gain2 = audioCtx.createGain();
            osc2.type = 'sine';
            osc2.frequency.value = 2400;
            gain2.gain.setValueAtTime(0.25, audioCtx.currentTime + 0.12);
            gain2.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
            osc2.connect(gain2);
            gain2.connect(audioCtx.destination);
            osc2.start(audioCtx.currentTime + 0.12);
            osc2.stop(audioCtx.currentTime + 0.3);
            setTimeout(function() { audioCtx.close(); }, 500);
        } catch(e) {}
    }

    function startQRScanner(fieldName) {
        var videoContainer = document.getElementById('qrVideo_' + fieldName);
        var video = document.getElementById('qrStream_' + fieldName);
        videoContainer.style.display = 'block';

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function(stream) {
            qrScannerStreams[fieldName] = stream;
            video.srcObject = stream;
            video.play();
            scanQRFrame(fieldName);
        })
        .catch(function(err) {
            videoContainer.style.display = 'none';
            Swal.fire({ icon: 'error', title: 'Camera Error', text: 'Could not access camera: ' + err.message });
        });
    }

    function scanQRFrame(fieldName) {
        var video = document.getElementById('qrStream_' + fieldName);
        if (!qrScannerStreams[fieldName] || video.paused || video.ended) return;

        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            var canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

            if (typeof BarcodeDetector !== 'undefined') {
                var detector = new BarcodeDetector({ formats: ['qr_code'] });
                detector.detect(canvas).then(function(barcodes) {
                    if (barcodes.length > 0) {
                        onQRDetected(fieldName, barcodes[0].rawValue);
                        return;
                    }
                    requestAnimationFrame(function() { scanQRFrame(fieldName); });
                }).catch(function() {
                    requestAnimationFrame(function() { scanQRFrame(fieldName); });
                });
            } else if (typeof jsQR !== 'undefined') {
                var code = jsQR(imageData.data, imageData.width, imageData.height);
                if (code) {
                    onQRDetected(fieldName, code.data);
                    return;
                }
                requestAnimationFrame(function() { scanQRFrame(fieldName); });
            } else {
                requestAnimationFrame(function() { scanQRFrame(fieldName); });
            }
        } else {
            requestAnimationFrame(function() { scanQRFrame(fieldName); });
        }
    }

    function onQRDetected(fieldName, value) {
        playBeep();
        document.getElementById('field_' + fieldName).value = value;
        document.getElementById('qrClear_' + fieldName).style.display = 'inline-flex';
        stopQRScanner(fieldName);
        Swal.fire({ icon: 'success', title: 'QR Scanned!', text: value.substring(0, 100), timer: 2000, showConfirmButton: false });
    }

    function stopQRScanner(fieldName) {
        var stream = qrScannerStreams[fieldName];
        if (stream) {
            stream.getTracks().forEach(function(t) { t.stop(); });
            delete qrScannerStreams[fieldName];
        }
        var videoContainer = document.getElementById('qrVideo_' + fieldName);
        if (videoContainer) videoContainer.style.display = 'none';
    }

    function viewQRCode(value) {
        var qrImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(value);
        Swal.fire({
            title: 'QR Code',
            html: '<div style="text-align:center;">' +
                '<div style="background:#fff;display:inline-block;padding:16px;border-radius:8px;border:2px solid var(--border-color);">' +
                '<img src="' + qrImgUrl + '" alt="QR Code" style="width:200px;height:200px;">' +
                '</div>' +
                '<div style="margin-top:14px;padding:10px;background:var(--bg-primary);border-radius:6px;word-break:break-all;font-size:13px;color:var(--text-secondary);max-height:80px;overflow-y:auto;">' +
                '<i class="fas fa-qrcode" style="color:var(--accent-purple);"></i> ' +
                value.replace(/</g, '&lt;').replace(/>/g, '&gt;') +
                '</div></div>',
            width: 380,
            showCloseButton: true,
            showDenyButton: true,
            confirmButtonText: '<i class="fas fa-download"></i> Download',
            denyButtonText: 'Close',
            confirmButtonColor: '#001f3f',
            denyButtonColor: '#6c757d',
            reverseButtons: true,
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then(function(result) {
            if (result.isConfirmed) {
                var a = document.createElement('a');
                a.href = qrImgUrl;
                a.download = 'qrcode.png';
                a.target = '_blank';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        });
    }

    function startCamera(fieldName) {
        var videoContainer = document.getElementById('cameraVideo_' + fieldName);
        var video = document.getElementById('cameraStream_' + fieldName);
        var startBtn = document.getElementById('cameraStartBtn_' + fieldName);
        var captureBtn = document.getElementById('cameraCaptureBtn_' + fieldName);
        var previewDiv = document.getElementById('cameraPreview_' + fieldName);

        previewDiv.style.display = 'none';
        videoContainer.style.display = 'block';
        startBtn.style.display = 'none';
        captureBtn.style.display = 'inline-flex';

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } } })
        .then(function(stream) {
            cameraStreams[fieldName] = stream;
            video.srcObject = stream;
            video.play();
        })
        .catch(function(err) {
            videoContainer.style.display = 'none';
            startBtn.style.display = 'inline-flex';
            captureBtn.style.display = 'none';
            Swal.fire({ icon: 'error', title: 'Camera Error', text: 'Could not access camera: ' + err.message });
        });
    }

    function capturePhoto(fieldName) {
        var video = document.getElementById('cameraStream_' + fieldName);
        var canvas = document.getElementById('cameraCanvas_' + fieldName);
        var img = document.getElementById('cameraImg_' + fieldName);
        var previewDiv = document.getElementById('cameraPreview_' + fieldName);
        var videoContainer = document.getElementById('cameraVideo_' + fieldName);
        var captureBtn = document.getElementById('cameraCaptureBtn_' + fieldName);
        var retakeBtn = document.getElementById('cameraRetakeBtn_' + fieldName);

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0);

        var stream = cameraStreams[fieldName];
        if (stream) {
            stream.getTracks().forEach(function(t) { t.stop(); });
            delete cameraStreams[fieldName];
        }

        var dataUrl = canvas.toDataURL('image/jpeg', 0.85);
        img.src = dataUrl;
        previewDiv.style.display = 'block';
        videoContainer.style.display = 'none';
        captureBtn.style.display = 'none';
        retakeBtn.style.display = 'inline-flex';

        canvas.toBlob(function(blob) {
            capturedPhotos[fieldName] = blob;
        }, 'image/jpeg', 0.85);
    }

    function retakePhoto(fieldName) {
        var retakeBtn = document.getElementById('cameraRetakeBtn_' + fieldName);
        retakeBtn.style.display = 'none';
        document.getElementById('cameraPreview_' + fieldName).style.display = 'none';
        delete capturedPhotos[fieldName];
        startCamera(fieldName);
    }

    function captureGPS(fieldName) {
        if (!navigator.geolocation) {
            Swal.fire({ icon: 'error', title: 'Not Supported', text: 'Geolocation is not supported by your browser.' });
            return;
        }

        var loadingEl = document.getElementById('gpsLoading_' + fieldName);
        var startBtn = document.getElementById('gpsStartBtn_' + fieldName);
        var gpsCaptured = false;

        loadingEl.style.display = 'block';
        startBtn.disabled = true;
        startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';

        function onSuccess(position) {
            gpsCaptured = true;
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            var acc = position.coords.accuracy;

            document.getElementById('field_' + fieldName).value = lat + ',' + lng;
            document.getElementById('gpsLat_' + fieldName).textContent = lat.toFixed(6);
            document.getElementById('gpsLng_' + fieldName).textContent = lng.toFixed(6);
            document.getElementById('gpsAcc_' + fieldName).textContent = (acc < 1000 ? acc.toFixed(1) + ' m' : (acc / 1000).toFixed(2) + ' km');
            document.getElementById('gpsMapLink_' + fieldName).href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
            document.getElementById('gpsDisplay_' + fieldName).style.display = 'block';
            document.getElementById('gpsClearBtn_' + fieldName).style.display = 'inline-flex';
            loadingEl.style.display = 'none';
            startBtn.disabled = false;
            startBtn.innerHTML = '<i class="fas fa-sync"></i> Recapture';
        }

        function onError(error) {
            if (gpsCaptured) return;
            loadingEl.style.display = 'none';
            startBtn.disabled = false;
            startBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Capture GPS';

            var title = 'GPS Error';
            var msg = 'Could not get your location.';
            var footer = '';

            if (error.code === 1) {
                title = 'Location Access Denied';
                msg = 'Your device or browser blocked location access.';
                footer = '<div style="text-align:left;font-size:13px;line-height:1.6;">'
                    + '<b>Try these steps:</b><br>'
                    + '1. <b>Windows:</b> Settings → Privacy → Location → turn ON<br>'
                    + '2. <b>Browser:</b> Click the lock icon in address bar → allow Location<br>'
                    + '3. Reload the page after changing settings'
                    + '</div>';
            } else if (error.code === 2) {
                msg = 'Location unavailable. Please ensure you are not in airplane mode.';
            } else if (error.code === 3) {
                msg = 'Location request timed out. Please check your connection.';
            }

            Swal.fire({ icon: 'error', title: title, text: msg, footer: footer, background: 'var(--bg-card)', color: 'var(--text-primary)' });
        }

        function doGetPosition() {
            navigator.geolocation.getCurrentPosition(onSuccess, onError,
                { enableHighAccuracy: false, timeout: 30000, maximumAge: 60000 }
            );
        }

        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                if (result.state === 'denied') {
                    loadingEl.style.display = 'none';
                    startBtn.disabled = false;
                    startBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Capture GPS';
                    Swal.fire({
                        icon: 'warning',
                        title: 'Location Blocked',
                        html: 'Location permission is currently <b>blocked</b> for this site.',
                        footer: '<div style="text-align:left;font-size:13px;line-height:1.6;">'
                            + '<b>To fix this:</b><br>'
                            + '1. Click the <b>lock icon</b> in the address bar<br>'
                            + '2. Find <b>Location</b> and change it to <b>Allow</b><br>'
                            + '3. <b>Reload</b> the page<br><br>'
                            + '<b>Also check:</b> Windows Settings → Privacy → Location must be ON'
                            + '</div>',
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                    return;
                }
                doGetPosition();
            }).catch(function() { doGetPosition(); });
        } else {
            doGetPosition();
        }
    }

    function clearGPS(fieldName) {
        document.getElementById('field_' + fieldName).value = '';
        document.getElementById('gpsDisplay_' + fieldName).style.display = 'none';
        document.getElementById('gpsClearBtn_' + fieldName).style.display = 'none';
        document.getElementById('gpsStartBtn_' + fieldName).innerHTML = '<i class="fas fa-map-marker-alt"></i> Capture GPS';
    }

    function updateColorPreview(fieldName, value) {
        var swatch = document.getElementById('colorSwatch_' + fieldName);
        var hex = document.getElementById('colorHex_' + fieldName);
        if (swatch) swatch.style.background = value;
        if (hex) hex.textContent = value.toUpperCase();
    }

    function loadAppointmentSlots(fieldName, preselectTime) {
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
        if (!preselectTime) {
            selectedDisplay.style.display = 'none';
            hiddenInput.value = '';
        }

        var slotUrl = 'crud_view.php?form=' + FORM_SLUG + '&action=getAvailableSlots&field_name=' + encodeURIComponent(fieldName) + '&date=' + encodeURIComponent(dateInput.value);
        var editId = document.getElementById('recordId') ? document.getElementById('recordId').value : '';
        if (editId) slotUrl += '&exclude_id=' + encodeURIComponent(editId);

        $.ajax({
            url: slotUrl,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    if (response.slots.length === 0) {
                        slotsContainer.innerHTML = '<div class="appt-no-slots"><i class="fas fa-calendar-times"></i> ' + (response.message || 'No available slots') + '</div>';
                        return;
                    }
                    var html = '<div class="appt-slots-grid">';
                    response.slots.forEach(function(slot) {
                        var isPreselected = preselectTime && slot.time === preselectTime;
                        var cls = slot.available || isPreselected ? 'appt-slot appt-slot-available' : 'appt-slot appt-slot-booked';
                        if (isPreselected) cls += ' appt-slot-selected';
                        var onclick = (slot.available || isPreselected) ? ' onclick="selectAppointmentSlot(\'' + fieldName + '\',\'' + slot.time + '\',\'' + dateInput.value + '\')"' : '';
                        var statusIcon = (slot.available || isPreselected) ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';
                        var bookedInfo = slot.max > 1 ? '<span class="appt-slot-count">' + slot.booked + '/' + slot.max + '</span>' : '';
                        html += '<div class="' + cls + '"' + onclick + '>' + statusIcon + ' <span class="appt-slot-time">' + slot.time + '</span>' + bookedInfo + '</div>';
                    });
                    html += '</div>';
                    slotsContainer.innerHTML = html;
                } else {
                    slotsContainer.innerHTML = '<div class="appt-no-slots"><i class="fas fa-exclamation-triangle"></i> ' + (response.message || 'Error loading slots') + '</div>';
                }
            },
            error: function() {
                slotsContainer.innerHTML = '<div class="appt-no-slots"><i class="fas fa-exclamation-triangle"></i> Connection error</div>';
            }
        });
    }

    function selectAppointmentSlot(fieldName, slotTime, date) {
        var hiddenInput = document.getElementById('field_' + fieldName);
        var selectedDisplay = document.getElementById('apptSelected_' + fieldName);
        var slotsContainer = document.getElementById('apptSlotsContainer_' + fieldName);

        hiddenInput.value = date + ' ' + slotTime;

        slotsContainer.querySelectorAll('.appt-slot').forEach(function(el) {
            el.classList.remove('appt-slot-selected');
        });
        slotsContainer.querySelectorAll('.appt-slot-available').forEach(function(el) {
            if (el.querySelector('.appt-slot-time') && el.querySelector('.appt-slot-time').textContent === slotTime) {
                el.classList.add('appt-slot-selected');
            }
        });

        selectedDisplay.style.display = 'flex';
        selectedDisplay.innerHTML = '<i class="fas fa-calendar-check" style="color:var(--accent-blue);"></i> <strong>' + date + '</strong> &nbsp; <i class="fas fa-clock" style="color:var(--accent-blue);"></i> <strong>' + slotTime + '</strong> <button type="button" class="btn btn-sm btn-ghost" onclick="clearAppointmentSlot(\'' + fieldName + '\')" style="margin-left:auto;font-size:11px;padding:2px 8px;"><i class="fas fa-times"></i></button>';
    }

    function clearAppointmentSlot(fieldName) {
        document.getElementById('field_' + fieldName).value = '';
        var selectedDisplay = document.getElementById('apptSelected_' + fieldName);
        if (selectedDisplay) selectedDisplay.style.display = 'none';
        var slotsContainer = document.getElementById('apptSlotsContainer_' + fieldName);
        if (slotsContainer) {
            slotsContainer.querySelectorAll('.appt-slot').forEach(function(el) {
                el.classList.remove('appt-slot-selected');
            });
        }
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add ' + FORM_DEF.form_name;
        document.getElementById('recordId').value = '';
        document.getElementById('recordForm').reset();

        FORM_FIELDS.filter(function(f) { return f.type === 'file'; }).forEach(function(f) {
            var prev = document.getElementById('filePreview_' + f.name);
            if (prev) { prev.style.display = 'none'; prev.innerHTML = ''; }
        });

        Object.keys(signaturePads).forEach(clearSignature);
        FORM_FIELDS.filter(function(f) { return f.type === 'signature'; }).forEach(function(f) {
            var preview = document.getElementById('sigExisting_' + f.name);
            if (preview) { preview.style.display = 'none'; preview.innerHTML = ''; }
        });

        FORM_FIELDS.filter(function(f) { return f.type === 'select' && (f.options || []).length >= 5; }).forEach(function(f) {
            var sdWrapper = document.getElementById('sd_' + f.name);
            if (sdWrapper && sdWrapper._sdSetValue) sdWrapper._sdSetValue('');
        });

        FORM_FIELDS.filter(function(f) { return f.type === 'gps'; }).forEach(function(f) { clearGPS(f.name); });

        FORM_FIELDS.filter(function(f) { return f.type === 'appointment'; }).forEach(function(f) {
            clearAppointmentSlot(f.name);
            var dateInput = document.getElementById('apptDate_' + f.name);
            if (dateInput) dateInput.value = '';
            var slotsContainer = document.getElementById('apptSlotsContainer_' + f.name);
            if (slotsContainer) { slotsContainer.style.display = 'none'; slotsContainer.innerHTML = ''; }
        });

        if (IS_MULTISTEP) {
            crudCurrentStep = 1;
            document.querySelectorAll('#dynamicFields .step-content').forEach(function(s) { s.classList.remove('active'); });
            var firstStep = document.querySelector('#dynamicFields .step-content[data-step="1"]');
            if (firstStep) firstStep.classList.add('active');
            crudUpdateStepUI();
        }

        crudEvaluateConditions();

        var draftBtn = document.getElementById('saveDraftBtn');
        var clearBtn = document.getElementById('clearFormBtn');
        if (draftBtn) draftBtn.style.display = '';
        if (clearBtn) clearBtn.style.display = '';

        document.getElementById('recordModal').classList.add('active');

        setTimeout(function() {
            FORM_FIELDS.filter(function(f) { return f.type === 'signature'; }).forEach(function(field) {
                initSignaturePad(field.name);
            });
        }, 150);

        setTimeout(function() { restoreCrudDraft(); }, 200);
    }

    function closeModal() {
        document.getElementById('recordModal').classList.remove('active');
        Object.keys(qrScannerStreams).forEach(function(k) { stopQRScanner(k); });
        Object.keys(cameraStreams).forEach(function(k) {
            cameraStreams[k].getTracks().forEach(function(t) { t.stop(); });
            delete cameraStreams[k];
        });
    }

    function viewSignature(filePath, fieldLabel) {
        Swal.fire({
            title: fieldLabel || 'Signature',
            html: '<div style="text-align:center;padding:10px;">' +
                '<div style="background:#fff;border:2px solid var(--border-color);border-radius:8px;padding:16px;display:inline-block;">' +
                '<img src="' + filePath + '?' + Date.now() + '" alt="' + (fieldLabel || 'Signature') + '" style="max-width:100%;max-height:300px;" onerror="this.parentElement.innerHTML=\'<p style=color:#ea4335>Failed to load signature</p>\'">' +
                '</div></div>',
            width: 520,
            showCloseButton: true,
            showDenyButton: true,
            confirmButtonText: '<i class="fas fa-download"></i> Download',
            denyButtonText: '<i class="fas fa-times"></i> Close',
            confirmButtonColor: '#001f3f',
            denyButtonColor: '#6c757d',
            reverseButtons: true,
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then(function(result) {
            if (result.isConfirmed) {
                var a = document.createElement('a');
                a.href = filePath;
                a.download = (fieldLabel || 'signature').replace(/[^a-zA-Z0-9]/g, '_') + '.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        });
    }

    function generateRecordPDF(id) {
        var record = recordsData.find(function(r) { return r.id === id; });
        if (!record) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Record not found', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }

        var logoUrl = SITE_BRANDING.site_logo || '';
        if (logoUrl) {
            loadImageAsBase64(logoUrl, function(base64) {
                buildAndDownloadPDF(record, id, base64);
            });
        } else {
            buildAndDownloadPDF(record, id, null);
        }
    }

    function loadImageAsBase64(url, callback) {
        var img = new Image();
        img.crossOrigin = 'Anonymous';
        img.onload = function() {
            var canvas = document.createElement('canvas');
            canvas.width = img.width;
            canvas.height = img.height;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            try { callback(canvas.toDataURL('image/png')); } catch(e) { callback(null); }
        };
        img.onerror = function() { callback(null); };
        img.src = url;
    }

    function buildAndDownloadPDF(record, id, logoBase64) {
        var data = record.data || {};
        var formName = FORM_DEF.form_name || 'Form';
        var siteName = SITE_BRANDING.site_name || 'Dashboard System';
        var copyright = SITE_BRANDING.copyright_text || '';
        var tempEl = document.createElement('div');
        tempEl.innerHTML = copyright;
        copyright = tempEl.textContent || tempEl.innerText || '';

        var fieldItems = [];
        FORM_FIELDS.forEach(function(field) {
            if (field.type === 'hidden' || field.type === 'file' || field.type === 'signature') return;
            var val = data[field.name];
            if (val === undefined || val === null) val = '';
            val = String(val);
            if (field.type === 'date' && val) {
                try { val = new Date(val).toLocaleDateString(); } catch(e) {}
            }
            if (field.type === 'datetime' && val) {
                try { val = new Date(val).toLocaleString(); } catch(e) {}
            }
            if (field.type === 'color' && val) { val = val.toUpperCase(); }
            if (field.type === 'gps' && val) {
                var gParts = val.split(',');
                if (gParts.length === 2) {
                    val = 'Lat: ' + parseFloat(gParts[0]).toFixed(6) + ', Lng: ' + parseFloat(gParts[1]).toFixed(6);
                }
            }
            fieldItems.push({
                label: field.label || field.name,
                value: val || '-',
                isFullWidth: field.type === 'textarea' || field.width === 'full'
            });
        });

        var tableBody = [];
        var i = 0;
        while (i < fieldItems.length) {
            var item = fieldItems[i];
            var cellLeft = {
                stack: [
                    { text: item.label, style: 'fieldLabel', margin: [0, 0, 0, 3] },
                    { text: item.value, style: 'fieldValue' }
                ]
            };
            if (item.isFullWidth || i + 1 >= fieldItems.length) {
                tableBody.push([{ ...cellLeft, colSpan: 2 }, {}]);
                i++;
            } else {
                var next = fieldItems[i + 1];
                var cellRight = {
                    stack: [
                        { text: next.label, style: 'fieldLabel', margin: [0, 0, 0, 3] },
                        { text: next.value, style: 'fieldValue' }
                    ]
                };
                tableBody.push([cellLeft, cellRight]);
                i += 2;
            }
        }

        var signatureContent = [];
        FORM_FIELDS.forEach(function(field) {
            if (field.type === 'signature' && record.files && record.files[field.name]) {
                signatureContent.push(
                    { text: field.label || 'Signature', style: 'signatureLabel', margin: [0, 15, 0, 5] },
                    { text: 'Signature on file', italics: true, color: '#666666', fontSize: 10 }
                );
            }
        });

        var submittedBy = record.submitted_by_username || (record.is_public_submission == 1 ? 'Public Submission' : 'System');
        var createdDate = record.created_at ? new Date(record.created_at).toLocaleString() : '-';

        var headerColumns = [];
        if (logoBase64) {
            headerColumns.push({ image: logoBase64, width: 40, height: 40, margin: [0, 0, 10, 0] });
        }
        headerColumns.push({
            stack: [
                { text: siteName, fontSize: 14, bold: true, color: '#001f3f' },
                { text: formName, fontSize: 10, color: '#666666', margin: [0, 2, 0, 0] }
            ],
            width: '*'
        });
        headerColumns.push({
            stack: [
                { text: 'Record #' + id, style: 'recordId' },
                { text: createdDate, fontSize: 9, color: '#999999', alignment: 'right', margin: [0, 2, 0, 0] }
            ],
            width: 'auto',
            alignment: 'right'
        });

        var footerContent = [
            { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 515, y2: 0, lineWidth: 1, lineColor: '#cccccc' }], margin: [0, 25, 0, 10] }
        ];
        if (copyright) {
            footerContent.push({ text: copyright, fontSize: 9, color: '#999999', alignment: 'center', margin: [0, 0, 0, 3] });
        }
        footerContent.push({ text: 'Generated on ' + new Date().toLocaleString(), fontSize: 8, color: '#bbbbbb', alignment: 'center' });

        var docDefinition = {
            pageSize: 'A4',
            pageMargins: [40, 60, 40, 60],
            content: [
                { columns: headerColumns, margin: [0, 0, 0, 8] },
                { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 515, y2: 0, lineWidth: 2, lineColor: '#001f3f' }], margin: [0, 0, 0, 15] },
                {
                    columns: [
                        { text: [{ text: 'Submitted by: ', bold: true }, submittedBy], fontSize: 10, color: '#444444' },
                        { text: [{ text: 'Date: ', bold: true }, createdDate], fontSize: 10, color: '#444444', alignment: 'right' }
                    ],
                    margin: [0, 0, 0, 15]
                },
                {
                    table: {
                        headerRows: 0,
                        widths: ['50%', '50%'],
                        body: tableBody.length > 0 ? tableBody : [[{ text: 'No data', colSpan: 2 }, {}]]
                    },
                    layout: {
                        hLineWidth: function(i) { return 0.5; },
                        vLineWidth: function(i, node) { return i === 0 || i === node.table.widths.length ? 0.5 : 0.3; },
                        hLineColor: function() { return '#dddddd'; },
                        vLineColor: function() { return '#dddddd'; },
                        fillColor: function(i) { return i % 2 === 0 ? '#f8f9fa' : null; },
                        paddingLeft: function() { return 10; },
                        paddingRight: function() { return 10; },
                        paddingTop: function() { return 8; },
                        paddingBottom: function() { return 8; }
                    }
                }
            ].concat(signatureContent).concat(footerContent),
            styles: {
                header: { fontSize: 20, bold: true, color: '#001f3f' },
                recordId: { fontSize: 12, color: '#0074D9', bold: true },
                fieldLabel: { bold: true, fontSize: 10, color: '#333333' },
                fieldValue: { fontSize: 10, color: '#555555' },
                signatureLabel: { bold: true, fontSize: 11, color: '#001f3f' }
            },
            defaultStyle: { font: 'Roboto' }
        };

        pdfMake.createPdf(docDefinition).download('record_' + id + '.pdf');
    }

    function showFormQR() {
        var formName = FORM_DEF.form_name || 'Form';
        var publicToken = FORM_DEF.public_token || '';
        if (!publicToken) {
            Swal.fire({ icon: 'info', title: 'Not Public', text: 'This form does not have a public link.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }
        var baseUrl = window.location.href.replace(/\/[^\/]*(\?.*)?$/, '/');
        var publicUrl = baseUrl + 'public_form.php?token=' + publicToken;

        Swal.fire({
            title: '<i class="fas fa-qrcode" style="color:var(--accent-purple);"></i> Share Form',
            html: '<div style="text-align:center;">'
                + '<p style="margin:0 0 4px;font-size:15px;font-weight:600;color:var(--text-primary);">' + formName + '</p>'
                + '<p style="margin:0 0 16px;font-size:12px;color:var(--text-muted);">Scan this QR code to open the form</p>'
                + '<div id="swalQrContainer" style="display:inline-block;padding:16px;background:#fff;border-radius:12px;border:2px solid var(--border-color);"></div>'
                + '<div style="margin-top:16px;display:flex;gap:8px;align-items:center;">'
                + '  <input type="text" id="swalQrUrl" value="' + publicUrl + '" readonly style="flex:1;padding:10px 12px;border:2px solid var(--border-color);border-radius:8px;font-size:12px;background:var(--bg-input);color:var(--text-primary);">'
                + '  <button id="qrCopyBtn" onclick="copyQRLink()" class="btn btn-sm" style="background:var(--accent-blue);color:#fff;white-space:nowrap;padding:10px 14px;border-radius:8px;"><i class="fas fa-copy"></i> Copy</button>'
                + '</div>'
                + '<div style="display:flex;gap:8px;margin-top:12px;justify-content:center;">'
                + '  <button onclick="downloadQR()" class="btn btn-sm" style="background:var(--accent-blue);color:#fff;padding:8px 16px;border-radius:8px;"><i class="fas fa-download"></i> PNG</button>'
                + '  <button onclick="printQR()" class="btn btn-sm" style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:8px;"><i class="fas fa-print"></i> Print</button>'
                + '</div>'
                + '</div>',
            showConfirmButton: true,
            confirmButtonText: '<i class="fas fa-times"></i> Close',
            confirmButtonColor: '#001f3f',
            width: 440,
            background: 'var(--bg-card)',
            color: 'var(--text-primary)',
            didOpen: function() {
                var container = document.getElementById('swalQrContainer');
                if (container && typeof QRCode !== 'undefined') {
                    new QRCode(container, {
                        text: publicUrl,
                        width: 200,
                        height: 200,
                        colorDark: '#001f3f',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.H
                    });
                }
            }
        });
    }

    function copyQRLink() {
        var input = document.getElementById('swalQrUrl');
        input.select();
        input.setSelectionRange(0, 99999);
        try {
            document.execCommand('copy');
            var btn = document.getElementById('qrCopyBtn');
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            btn.style.background = 'var(--accent-green)';
            setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; btn.style.background = 'var(--accent-blue)'; }, 2000);
        } catch(e) {}
    }

    function downloadQR() {
        var canvas = document.querySelector('#swalQrContainer canvas');
        if (!canvas) return;
        var formName = FORM_DEF.form_name || 'Form';
        var dlCanvas = document.createElement('canvas');
        var padding = 40;
        var textHeight = 50;
        dlCanvas.width = canvas.width + padding * 2;
        dlCanvas.height = canvas.height + padding * 2 + textHeight;
        var ctx = dlCanvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, dlCanvas.width, dlCanvas.height);
        ctx.drawImage(canvas, padding, padding);
        ctx.fillStyle = '#001f3f';
        ctx.font = 'bold 16px Arial, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(formName, dlCanvas.width / 2, canvas.height + padding + 30);
        ctx.fillStyle = '#666666';
        ctx.font = '12px Arial, sans-serif';
        ctx.fillText('Scan to open form', dlCanvas.width / 2, canvas.height + padding + 48);
        var link = document.createElement('a');
        link.download = formName.replace(/[^a-zA-Z0-9]/g, '_') + '_QR.png';
        link.href = dlCanvas.toDataURL('image/png');
        link.click();
    }

    function printQR() {
        var canvas = document.querySelector('#swalQrContainer canvas');
        if (!canvas) return;
        var formName = FORM_DEF.form_name || 'Form';
        var win = window.open('', '_blank');
        win.document.write('<!DOCTYPE html><html><head><title>QR Code</title><style>body{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:Arial,sans-serif;}h2{color:#001f3f;margin-bottom:8px;}p{color:#666;margin-top:0;}</style></head><body>');
        win.document.write('<h2>' + formName + '</h2><p>Scan to open form</p>');
        win.document.write('<img src="' + canvas.toDataURL('image/png') + '" style="width:300px;height:300px;">');
        win.document.write('</body></html>');
        win.document.close();
        win.onload = function() { win.print(); };
    }

    document.getElementById('recordForm').addEventListener('submit', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        var action = isEditMode ? 'updateRecord' : 'addRecord';
        formData.append('action', action);
        formData.append('csrf_token', CSRF_TOKEN);

        FORM_FIELDS.filter(function(f) { return f.type === 'checkbox' && f.options && f.options.length > 0; }).forEach(function(field) {
            var checked = [];
            document.querySelectorAll('input[name="' + field.name + '"]:checked').forEach(function(cb) { checked.push(cb.value); });
            formData.set(field.name, checked.join(','));
        });

        var sigFields = FORM_FIELDS.filter(function(f) { return f.type === 'signature'; });
        var sigPromises = sigFields.map(function(field) {
            return new Promise(function(resolve) {
                var pad = signaturePads[field.name];
                if (pad) {
                    pad.canvas.toBlob(function(blob) {
                        if (blob) formData.set(field.name, blob, field.name + '.png');
                        resolve();
                    });
                } else {
                    resolve();
                }
            });
        });

        FORM_FIELDS.filter(function(f) { return f.type === 'camera'; }).forEach(function(field) {
            if (capturedPhotos[field.name]) {
                formData.set(field.name, capturedPhotos[field.name], field.name + '.jpg');
            }
        });

        Promise.all(sigPromises).then(function() {
            $.ajax({
                url: 'crud_view.php?form=' + FORM_SLUG,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                headers: {
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                success: function(response) {
                    if (response.success) {
                        if (action === 'addRecord') clearCrudDraft();
                        if (action === 'addRecord' && response.redirect_url) {
                            Swal.fire({ icon: 'success', title: 'Submitted!', text: 'Redirecting...', timer: 1500, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' })
                            .then(function() { window.location.href = response.redirect_url; });
                        } else if (action === 'addRecord' && response.thank_you_message) {
                            Swal.fire({ icon: 'success', title: 'Submitted!', text: response.thank_you_message, confirmButtonColor: '#001f3f', background: 'var(--bg-card)', color: 'var(--text-primary)' })
                            .then(function() { closeModal(); loadRecords(); });
                        } else {
                            Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                            closeModal();
                            setTimeout(loadRecords, 100);
                        }
                    } else if (response.duplicate) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Possible Duplicate',
                            html: response.message + '<br><br>Click <strong>Submit Anyway</strong> to proceed.',
                            showCancelButton: true,
                            confirmButtonText: 'Submit Anyway',
                            cancelButtonText: 'Cancel',
                            confirmButtonColor: '#e65100',
                            background: 'var(--bg-card)',
                            color: 'var(--text-primary)'
                        }).then(function(result) {
                            if (result.isConfirmed) {
                                formData.append('_confirm_duplicate', '1');
                                $.ajax({
                                    url: 'crud_view.php?form=' + FORM_SLUG,
                                    method: 'POST',
                                    data: formData,
                                    processData: false,
                                    contentType: false,
                                    dataType: 'json',
                                    headers: {
                                        'X-CSRF-TOKEN': CSRF_TOKEN
                                    },
                                    success: function(r) {
                                        if (r.success) {
                                            clearCrudDraft();
                                            Swal.fire({ icon: 'success', title: 'Success!', text: r.message, timer: 2000, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                                            closeModal();
                                            setTimeout(loadRecords, 100);
                                        } else {
                                            Swal.fire({ icon: 'error', title: 'Error', text: r.message, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                                        }
                                    }
                                });
                            }
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                    }
                },
                error: function(xhr, status, err) {
                    console.error('Submit AJAX error:', status, err);
                    var msg = 'Connection error. Please try again.';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp && resp.message) msg = resp.message;
                    } catch(e) {}
                    Swal.fire({ icon: 'error', title: 'Error', text: msg, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                }
            });
        });
    });

    function collectCrudDraft() {
        var data = {};
        FORM_FIELDS.forEach(function(field) {
            if (field.type === 'file' || field.type === 'signature' || field.type === 'camera' || field.type === 'qr_scanner') return;
            if (field.type === 'checkbox') {
                var checked = [];
                document.querySelectorAll('input[name="' + field.name + '"]:checked').forEach(function(cb) { checked.push(cb.value); });
                data[field.name] = checked;
            } else if (field.type === 'radio') {
                var sel = document.querySelector('input[name="' + field.name + '"]:checked');
                data[field.name] = sel ? sel.value : '';
            } else {
                var el = document.getElementById(field.name) || document.querySelector('[name="' + field.name + '"]');
                if (el) data[field.name] = el.value || '';
            }
        });
        return data;
    }

    function saveCrudDraft() {
        if (isEditMode) return;
        var data = collectCrudDraft();
        data._saved_at = new Date().toISOString();
        try {
            localStorage.setItem(CRUD_DRAFT_KEY, JSON.stringify(data));
            Swal.fire({ icon: 'success', title: 'Draft Saved', text: 'Your progress has been saved locally.', timer: 1500, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
        } catch(e) {
            Swal.fire({ icon: 'error', title: 'Save Failed', text: 'Could not save draft to local storage.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
        }
    }

    function restoreCrudDraft() {
        if (isEditMode) return;
        try {
            var raw = localStorage.getItem(CRUD_DRAFT_KEY);
            if (!raw) return;
            var data = JSON.parse(raw);
            FORM_FIELDS.forEach(function(field) {
                if (!(field.name in data)) return;
                if (field.type === 'file' || field.type === 'signature' || field.type === 'camera' || field.type === 'qr_scanner') return;
                if (field.type === 'checkbox') {
                    var vals = data[field.name] || [];
                    document.querySelectorAll('input[name="' + field.name + '"]').forEach(function(cb) {
                        cb.checked = vals.indexOf(cb.value) >= 0;
                    });
                } else if (field.type === 'radio') {
                    document.querySelectorAll('input[name="' + field.name + '"]').forEach(function(rb) {
                        rb.checked = rb.value === data[field.name];
                    });
                } else if (field.type === 'select') {
                    var el = document.getElementById(field.name);
                    if (el) el.value = data[field.name] || '';
                    var sdWrapper = document.getElementById('sd_' + field.name);
                    if (sdWrapper && sdWrapper._sdSetValue) sdWrapper._sdSetValue(data[field.name] || '');
                } else {
                    var el = document.getElementById(field.name) || document.querySelector('[name="' + field.name + '"]');
                    if (el) el.value = data[field.name] || '';
                }
            });
            var notice = document.getElementById('crudDraftNotice');
            var txt = document.getElementById('crudDraftText');
            if (notice && txt) {
                var savedAt = data._saved_at ? new Date(data._saved_at).toLocaleString() : '';
                txt.textContent = 'Draft restored' + (savedAt ? ' from ' + savedAt : '') + '.';
                notice.style.display = 'block';
            }
            FORM_FIELDS.filter(function(f) { return f.type === 'color'; }).forEach(function(f) {
                var el = document.getElementById('field_' + f.name);
                if (el && el.value) updateColorPreview(f.name, el.value);
            });
            crudEvaluateConditions();
        } catch(e) { /* ignore */ }
    }

    function clearCrudDraft() {
        try { localStorage.removeItem(CRUD_DRAFT_KEY); } catch(e) {}
        var notice = document.getElementById('crudDraftNotice');
        if (notice) notice.style.display = 'none';
    }

    function clearCrudForm() {
        Swal.fire({
            icon: 'question',
            title: 'Clear Form?',
            text: 'This will clear all fields and remove the saved draft.',
            showCancelButton: true,
            confirmButtonText: 'Yes, Clear',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#e53e3e',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then(function(result) {
            if (result.isConfirmed) {
                document.getElementById('recordForm').reset();
                FORM_FIELDS.filter(function(f) { return f.type === 'select' && (f.options || []).length >= 5; }).forEach(function(f) {
                    var sdWrapper = document.getElementById('sd_' + f.name);
                    if (sdWrapper && sdWrapper._sdSetValue) sdWrapper._sdSetValue('');
                });
                FORM_FIELDS.filter(function(f) { return f.type === 'appointment'; }).forEach(function(f) {
                    clearAppointmentSlot(f.name);
                    var dateInput = document.getElementById('apptDate_' + f.name);
                    if (dateInput) dateInput.value = '';
                    var slotsContainer = document.getElementById('apptSlotsContainer_' + f.name);
                    if (slotsContainer) { slotsContainer.style.display = 'none'; slotsContainer.innerHTML = ''; }
                });
                FORM_FIELDS.filter(function(f) { return f.type === 'gps'; }).forEach(function(f) { clearGPS(f.name); });
                clearCrudDraft();
                crudEvaluateConditions();
                Swal.fire({ icon: 'success', title: 'Cleared', timer: 1000, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
            }
        });
    }

    var _crudDraftTimer = null;
    function _crudAutoSave() {
        if (isEditMode) return;
        clearTimeout(_crudDraftTimer);
        _crudDraftTimer = setTimeout(function() {
            var data = collectCrudDraft();
            data._saved_at = new Date().toISOString();
            try { localStorage.setItem(CRUD_DRAFT_KEY, JSON.stringify(data)); } catch(e) {}
        }, 2000);
    }
    document.getElementById('recordForm').addEventListener('input', _crudAutoSave);
    document.getElementById('recordForm').addEventListener('change', _crudAutoSave);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRecordDetails();
            closeModal();
        }
    });
    </script>
</body>
</html>