<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'User';
$user_id = $_SESSION['user_id'];
$current_page = 'crud_settings';

if ($role !== 'Admin') { header("Location: dashboard.php"); exit(); }

$user_theme = getUserTheme($user_id);

// =============================================
// AJAX HANDLERS - ALL PREPARED STATEMENTS REMOVED
// =============================================
if (isset($_GET['action']) || isset($_POST['action'])) {
    ob_start();
    header('Content-Type: application/json');
    enforceCSRF();
    $action = isset($_GET['action']) ? $_GET['action'] : $_POST['action'];

    try {
        $conn = getDBConnection();

        // GET USERS - FIXED: No prepared statements
        if ($action === 'getUsers') {
            $query = "SELECT id, username, role FROM users ORDER BY username ASC";
            $result = mysqli_query($conn, $query);
            $users = [];
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $users[] = $row;
                }
                mysqli_free_result($result);
            }
            ob_end_clean();
            echo json_encode(['success' => true, 'data' => $users]);
            exit();
        }

        // GET FORMS - FIXED: No prepared statements
        if ($action === 'getForms') {
            $query = "SELECT id, form_name, form_slug, form_icon, form_description, form_logo, fields_json, access_type, can_edit_roles, can_delete_roles, is_public, public_token, email_on_submit, email_notify_admin, email_confirmation, enable_pdf_download, expires_at, max_submissions, form_password, one_per_user, thank_you_message, redirect_url, form_steps, schedule_start, schedule_end, duplicate_detection, duplicate_fields, visible_to_users, editable_by_users, status, sort_order, created_at, updated_at FROM crud_forms ORDER BY sort_order ASC, form_name ASC";
            $result = mysqli_query($conn, $query);
            $forms = [];
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $fields = json_decode($row['fields_json'], true);
                    $row['fields_count'] = is_array($fields) ? count($fields) : 0;
                    $row['created_at'] = date('Y-m-d\TH:i:s\Z', strtotime($row['created_at']));
                    $row['updated_at'] = $row['updated_at'] ? date('Y-m-d\TH:i:s\Z', strtotime($row['updated_at'])) : null;
                    $forms[] = $row;
                }
                mysqli_free_result($result);
            }
            ob_end_clean();
            echo json_encode(['success' => true, 'data' => $forms]);
            exit();
        }

        // GET SINGLE FORM - FIXED: No prepared statements
        if ($action === 'getForm' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $query = "SELECT * FROM crud_forms WHERE id = " . $id;
            $result = mysqli_query($conn, $query);
            $form = $result ? mysqli_fetch_assoc($result) : null;
            if ($result) mysqli_free_result($result);
            if ($form) {
                echo json_encode(['success' => true, 'data' => $form]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Form not found']);
            }
            exit();
        }

        // UPLOAD FIELD REFERENCE IMAGE
        if ($action === 'uploadRefImage') {
            if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'No image uploaded or upload error']);
                exit();
            }

            $file = $_FILES['image'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowed)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP images allowed']);
                exit();
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Image must be under 5MB']);
                exit();
            }

            $upload_dir = __DIR__ . '/uploads/field_references';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) { $ext = 'png'; }
            $filename = 'ref_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

            if (move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $filename)) {
                ob_end_clean();
                echo json_encode(['success' => true, 'url' => 'uploads/field_references/' . $filename]);
            } else {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to save image']);
            }
            exit();
        }

        // ADD FORM - FIXED: No prepared statements
        if ($action === 'addForm') {
            $form_name = mysqli_real_escape_string($conn, trim($_POST['form_name'] ?? ''));
            $form_icon = mysqli_real_escape_string($conn, trim($_POST['form_icon'] ?? 'fas fa-list'));
            $form_description = mysqli_real_escape_string($conn, trim($_POST['form_description'] ?? ''));
            $form_logo = !empty($_POST['form_logo']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['form_logo'])) . "'" : "NULL";
            $fields_json = mysqli_real_escape_string($conn, $_POST['fields_json'] ?? '[]');
            $access_type = mysqli_real_escape_string($conn, $_POST['access_type'] ?? 'both');
            $can_edit_roles = mysqli_real_escape_string($conn, $_POST['can_edit_roles'] ?? '["Admin"]');
            $can_delete_roles = mysqli_real_escape_string($conn, $_POST['can_delete_roles'] ?? '["Admin"]');
            $is_public = intval($_POST['is_public'] ?? 0);
            $email_on_submit = intval($_POST['email_on_submit'] ?? 0);
            $email_notify_admin = intval($_POST['email_notify_admin'] ?? 0);
            $email_confirmation = intval($_POST['email_confirmation'] ?? 0);
            $enable_pdf_download = intval($_POST['enable_pdf_download'] ?? 0);
            $expires_at = !empty($_POST['expires_at']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['expires_at'])) . "'" : "NULL";
            $max_submissions = intval($_POST['max_submissions'] ?? 0);
            $form_password = !empty($_POST['form_password']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['form_password'])) . "'" : "NULL";
            $one_per_user = intval($_POST['one_per_user'] ?? 0);
            $thank_you_message = !empty($_POST['thank_you_message']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['thank_you_message'])) . "'" : "NULL";
            $redirect_url = !empty($_POST['redirect_url']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['redirect_url'])) . "'" : "NULL";
            if (!empty($_POST['redirect_url']) && !filter_var($_POST['redirect_url'], FILTER_VALIDATE_URL)) { $redirect_url = "NULL"; }
            $form_steps = !empty($_POST['form_steps']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['form_steps'])) . "'" : "NULL";
            $schedule_start = !empty($_POST['schedule_start']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['schedule_start'])) . "'" : "NULL";
            $schedule_end = !empty($_POST['schedule_end']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['schedule_end'])) . "'" : "NULL";
            $duplicate_detection = intval($_POST['duplicate_detection'] ?? 0);
            $duplicate_fields = !empty($_POST['duplicate_fields']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['duplicate_fields'])) . "'" : "NULL";
            $visible_to_users = !empty($_POST['visible_to_users']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['visible_to_users'])) . "'" : "NULL";
            $editable_by_users = !empty($_POST['editable_by_users']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['editable_by_users'])) . "'" : "NULL";

            if (empty($form_name)) {
                echo json_encode(['success' => false, 'message' => 'Form name is required']);
                exit();
            }

            $fields = json_decode($_POST['fields_json'] ?? '[]', true);
            if (!is_array($fields) || count($fields) === 0) {
                echo json_encode(['success' => false, 'message' => 'At least one field is required']);
                exit();
            }

            $form_slug = generateFormSlug($form_name);
            $public_token = $is_public ? "'" . mysqli_real_escape_string($conn, generatePublicToken()) . "'" : "NULL";

            // Save form logo if provided (base64 data URL)
            $form_logo_path = "NULL";
            if (!empty($_POST['form_logo']) && strpos($_POST['form_logo'], 'data:image') === 0) {
                $logo_dir = __DIR__ . '/uploads/crud/logos';
                if (!file_exists($logo_dir)) mkdir($logo_dir, 0755, true);
                $ext = 'png';
                if (preg_match('/^data:image\/([\w]+);/', $_POST['form_logo'], $m)) $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $logo_filename = $form_slug . '_' . time() . '.' . $ext;
                $logo_data = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $_POST['form_logo']), true);
                if ($logo_data !== false && strlen($logo_data) <= 5 * 1024 * 1024) {
                    file_put_contents($logo_dir . '/' . $logo_filename, $logo_data);
                    $form_logo_path = "'" . mysqli_real_escape_string($conn, 'uploads/crud/logos/' . $logo_filename) . "'";
                }
            } elseif (!empty($_POST['form_logo'])) {
                $form_logo_path = "'" . mysqli_real_escape_string($conn, $_POST['form_logo']) . "'";
            }

            // Get max sort_order
            $max_sort_result = mysqli_query($conn, "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_sort FROM crud_forms");
            $max_sort = 1;
            if ($max_sort_result) {
                $row = mysqli_fetch_assoc($max_sort_result);
                $max_sort = intval($row['next_sort']);
                mysqli_free_result($max_sort_result);
            }

            $insert_query = "INSERT INTO crud_forms (form_name, form_slug, form_icon, form_description, form_logo, fields_json, access_type, can_edit_roles, can_delete_roles, is_public, public_token, email_on_submit, email_notify_admin, email_confirmation, enable_pdf_download, expires_at, max_submissions, form_password, one_per_user, thank_you_message, redirect_url, form_steps, schedule_start, schedule_end, duplicate_detection, duplicate_fields, visible_to_users, editable_by_users, sort_order, created_by) VALUES (
                '{$form_name}',
                '{$form_slug}',
                '{$form_icon}',
                '{$form_description}',
                {$form_logo_path},
                '{$fields_json}',
                '{$access_type}',
                '{$can_edit_roles}',
                '{$can_delete_roles}',
                {$is_public},
                {$public_token},
                {$email_on_submit},
                {$email_notify_admin},
                {$email_confirmation},
                {$enable_pdf_download},
                {$expires_at},
                {$max_submissions},
                {$form_password},
                {$one_per_user},
                {$thank_you_message},
                {$redirect_url},
                {$form_steps},
                {$schedule_start},
                {$schedule_end},
                {$duplicate_detection},
                {$duplicate_fields},
                {$visible_to_users},
                {$editable_by_users},
                {$max_sort},
                {$user_id}
            )";
            mysqli_query($conn, $insert_query);
            $new_id = mysqli_insert_id($conn);

            // Create upload directory
            $upload_dir = __DIR__ . '/uploads/crud/' . $form_slug;
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            logActivity($user_id, $username, 'Created Form', "Created form: $form_name (slug: $form_slug)");
            createNotificationForAdmins('New Form Created', "Form \"$form_name\" has been created.", 'success', 'crud_settings.php');

            echo json_encode(['success' => true, 'message' => 'Form created successfully', 'id' => $new_id, 'slug' => $form_slug]);
            exit();
        }

        // UPDATE FORM - FIXED: No prepared statements
        if ($action === 'updateForm') {
            $id = intval($_POST['id'] ?? 0);
            $form_name = mysqli_real_escape_string($conn, trim($_POST['form_name'] ?? ''));
            $form_icon = mysqli_real_escape_string($conn, trim($_POST['form_icon'] ?? 'fas fa-list'));
            $form_description = mysqli_real_escape_string($conn, trim($_POST['form_description'] ?? ''));
            $form_logo = !empty($_POST['form_logo']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['form_logo'])) . "'" : "NULL";
            $fields_json = mysqli_real_escape_string($conn, $_POST['fields_json'] ?? '[]');
            $access_type = mysqli_real_escape_string($conn, $_POST['access_type'] ?? 'both');
            $can_edit_roles = mysqli_real_escape_string($conn, $_POST['can_edit_roles'] ?? '["Admin"]');
            $can_delete_roles = mysqli_real_escape_string($conn, $_POST['can_delete_roles'] ?? '["Admin"]');
            $is_public = intval($_POST['is_public'] ?? 0);
            $email_on_submit = intval($_POST['email_on_submit'] ?? 0);
            $email_notify_admin = intval($_POST['email_notify_admin'] ?? 0);
            $email_confirmation = intval($_POST['email_confirmation'] ?? 0);
            $enable_pdf_download = intval($_POST['enable_pdf_download'] ?? 0);
            $expires_at = !empty($_POST['expires_at']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['expires_at'])) . "'" : "NULL";
            $max_submissions = intval($_POST['max_submissions'] ?? 0);
            $form_password = !empty($_POST['form_password']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['form_password'])) . "'" : "NULL";
            $one_per_user = intval($_POST['one_per_user'] ?? 0);
            $thank_you_message = !empty($_POST['thank_you_message']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['thank_you_message'])) . "'" : "NULL";
            $redirect_url = !empty($_POST['redirect_url']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['redirect_url'])) . "'" : "NULL";
            if (!empty($_POST['redirect_url']) && !filter_var($_POST['redirect_url'], FILTER_VALIDATE_URL)) { $redirect_url = "NULL"; }
            $form_steps = !empty($_POST['form_steps']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['form_steps'])) . "'" : "NULL";
            $schedule_start = !empty($_POST['schedule_start']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['schedule_start'])) . "'" : "NULL";
            $schedule_end = !empty($_POST['schedule_end']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['schedule_end'])) . "'" : "NULL";
            $duplicate_detection = intval($_POST['duplicate_detection'] ?? 0);
            $duplicate_fields = !empty($_POST['duplicate_fields']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['duplicate_fields'])) . "'" : "NULL";
            $visible_to_users = !empty($_POST['visible_to_users']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['visible_to_users'])) . "'" : "NULL";
            $editable_by_users = !empty($_POST['editable_by_users']) ? "'" . mysqli_real_escape_string($conn, trim($_POST['editable_by_users'])) . "'" : "NULL";

            if ($id === 0 || empty($form_name)) {
                echo json_encode(['success' => false, 'message' => 'Invalid form data']);
                exit();
            }

            // Check if public token needed
            $existing_query = "SELECT public_token, is_public, form_slug FROM crud_forms WHERE id = " . $id;
            $existing_result = mysqli_query($conn, $existing_query);
            $ex_result = $existing_result ? mysqli_fetch_assoc($existing_result) : null;
            if ($existing_result) mysqli_free_result($existing_result);

            $public_token = $ex_result ? $ex_result['public_token'] : null;
            if ($is_public && empty($public_token)) {
                $public_token = generatePublicToken();
            } elseif (!$is_public) {
                $public_token = $ex_result ? $ex_result['public_token'] : null;
            }
            $public_token_val = $public_token ? "'" . mysqli_real_escape_string($conn, $public_token) . "'" : "NULL";

            // Save form logo if provided (base64 data URL)
            $form_logo_path = "NULL";
            if (!empty($_POST['form_logo']) && strpos($_POST['form_logo'], 'data:image') === 0) {
                $logo_dir = __DIR__ . '/uploads/crud/logos';
                if (!file_exists($logo_dir)) mkdir($logo_dir, 0755, true);
                $cur_slug = $ex_result ? $ex_result['form_slug'] : 'form';
                $ext = 'png';
                if (preg_match('/^data:image\/([\w]+);/', $_POST['form_logo'], $m)) $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $logo_filename = $cur_slug . '_' . time() . '.' . $ext;
                $logo_data = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $_POST['form_logo']), true);
                if ($logo_data !== false && strlen($logo_data) <= 5 * 1024 * 1024) {
                    file_put_contents($logo_dir . '/' . $logo_filename, $logo_data);
                    $form_logo_path = "'" . mysqli_real_escape_string($conn, 'uploads/crud/logos/' . $logo_filename) . "'";
                }
            } elseif (!empty($_POST['form_logo']) && strpos($_POST['form_logo'], 'uploads/') === 0) {
                $form_logo_path = "'" . mysqli_real_escape_string($conn, $_POST['form_logo']) . "'";
            }

            $update_query = "UPDATE crud_forms SET 
                form_name = '{$form_name}',
                form_icon = '{$form_icon}',
                form_description = '{$form_description}',
                form_logo = {$form_logo_path},
                fields_json = '{$fields_json}',
                access_type = '{$access_type}',
                can_edit_roles = '{$can_edit_roles}',
                can_delete_roles = '{$can_delete_roles}',
                is_public = {$is_public},
                public_token = {$public_token_val},
                email_on_submit = {$email_on_submit},
                email_notify_admin = {$email_notify_admin},
                email_confirmation = {$email_confirmation},
                enable_pdf_download = {$enable_pdf_download},
                expires_at = {$expires_at},
                max_submissions = {$max_submissions},
                form_password = {$form_password},
                one_per_user = {$one_per_user},
                thank_you_message = {$thank_you_message},
                redirect_url = {$redirect_url},
                form_steps = {$form_steps},
                schedule_start = {$schedule_start},
                schedule_end = {$schedule_end},
                duplicate_detection = {$duplicate_detection},
                duplicate_fields = {$duplicate_fields},
                visible_to_users = {$visible_to_users},
                editable_by_users = {$editable_by_users}
                WHERE id = {$id}";
            mysqli_query($conn, $update_query);

            logActivity($user_id, $username, 'Updated Form', "Updated form: $form_name");

            echo json_encode(['success' => true, 'message' => 'Form updated successfully']);
            exit();
        }

        // DELETE FORM - FIXED: No prepared statements
        if ($action === 'deleteForm') {
            $id = intval($_POST['id'] ?? 0);

            // Get form info
            $query = "SELECT form_name, form_slug FROM crud_forms WHERE id = " . $id;
            $result = mysqli_query($conn, $query);
            $form = $result ? mysqli_fetch_assoc($result) : null;
            if ($result) mysqli_free_result($result);

            if (!$form) {
                echo json_encode(['success' => false, 'message' => 'Form not found']);
                exit();
            }

            // Delete files from disk
            $files_query = "SELECT file_path FROM crud_files WHERE form_id = " . $id;
            $files_result = mysqli_query($conn, $files_query);
            if ($files_result) {
                while ($file = mysqli_fetch_assoc($files_result)) {
                    $filepath = __DIR__ . '/' . $file['file_path'];
                    if (file_exists($filepath)) unlink($filepath);
                }
                mysqli_free_result($files_result);
            }

            // Delete DB records
            mysqli_query($conn, "DELETE FROM crud_files WHERE form_id = " . $id);
            mysqli_query($conn, "DELETE FROM crud_data WHERE form_id = " . $id);
            mysqli_query($conn, "DELETE FROM crud_forms WHERE id = " . $id);

            logActivity($user_id, $username, 'Deleted Form', "Deleted form: {$form['form_name']}");
            createNotificationForAdmins('Form Deleted', "Form \"{$form['form_name']}\" has been deleted.", 'danger', 'crud_settings.php');

            echo json_encode(['success' => true, 'message' => 'Form and all its data deleted']);
            exit();
        }

        // TOGGLE STATUS - FIXED: No prepared statements
        if ($action === 'toggleStatus') {
            $id = intval($_POST['id'] ?? 0);
            mysqli_query($conn, "UPDATE crud_forms SET status = IF(status='active','inactive','active') WHERE id = " . $id);

            logActivity($user_id, $username, 'Toggled Form Status', "Toggled status for form ID: $id");
            echo json_encode(['success' => true, 'message' => 'Status updated']);
            exit();
        }

        // DUPLICATE FORM - FIXED: No prepared statements
        if ($action === 'duplicateForm') {
            $id = intval($_POST['id'] ?? 0);
            $query = "SELECT * FROM crud_forms WHERE id = " . $id;
            $result = mysqli_query($conn, $query);
            $form = $result ? mysqli_fetch_assoc($result) : null;
            if ($result) mysqli_free_result($result);

            if (!$form) {
                echo json_encode(['success' => false, 'message' => 'Form not found']);
                exit();
            }

            $new_name = $form['form_name'] . ' (Copy)';
            $new_slug = generateFormSlug($new_name);

            $dup_expires = $form['expires_at'] ? "'" . mysqli_real_escape_string($conn, $form['expires_at']) . "'" : "NULL";
            $dup_max_sub = intval($form['max_submissions'] ?? 0);
            $dup_password = $form['form_password'] ? "'" . mysqli_real_escape_string($conn, $form['form_password']) . "'" : "NULL";
            $dup_one_per = intval($form['one_per_user'] ?? 0);
            $dup_thankyou = $form['thank_you_message'] ? "'" . mysqli_real_escape_string($conn, $form['thank_you_message']) . "'" : "NULL";
            $dup_redirect = $form['redirect_url'] ? "'" . mysqli_real_escape_string($conn, $form['redirect_url']) . "'" : "NULL";
            $dup_steps = $form['form_steps'] ? "'" . mysqli_real_escape_string($conn, $form['form_steps']) . "'" : "NULL";
            $dup_schedule_start = $form['schedule_start'] ? "'" . mysqli_real_escape_string($conn, $form['schedule_start']) . "'" : "NULL";
            $dup_schedule_end = $form['schedule_end'] ? "'" . mysqli_real_escape_string($conn, $form['schedule_end']) . "'" : "NULL";
            $dup_duplicate_detection = intval($form['duplicate_detection'] ?? 0);
            $dup_duplicate_fields = $form['duplicate_fields'] ? "'" . mysqli_real_escape_string($conn, $form['duplicate_fields']) . "'" : "NULL";
            $dup_visible_to = $form['visible_to_users'] ? "'" . mysqli_real_escape_string($conn, $form['visible_to_users']) . "'" : "NULL";
            $dup_editable_by = $form['editable_by_users'] ? "'" . mysqli_real_escape_string($conn, $form['editable_by_users']) . "'" : "NULL";
            $dup_logo = $form['form_logo'] ? "'" . mysqli_real_escape_string($conn, $form['form_logo']) . "'" : "NULL";

            $insert_query = "INSERT INTO crud_forms (form_name, form_slug, form_icon, form_description, form_logo, fields_json, access_type, can_edit_roles, can_delete_roles, is_public, email_on_submit, email_notify_admin, email_confirmation, enable_pdf_download, expires_at, max_submissions, form_password, one_per_user, thank_you_message, redirect_url, form_steps, schedule_start, schedule_end, duplicate_detection, duplicate_fields, visible_to_users, editable_by_users, status, sort_order, created_by) VALUES (
                '" . mysqli_real_escape_string($conn, $new_name) . "',
                '" . mysqli_real_escape_string($conn, $new_slug) . "',
                '" . mysqli_real_escape_string($conn, $form['form_icon']) . "',
                '" . mysqli_real_escape_string($conn, $form['form_description']) . "',
                {$dup_logo},
                '" . mysqli_real_escape_string($conn, $form['fields_json']) . "',
                '" . mysqli_real_escape_string($conn, $form['access_type']) . "',
                '" . mysqli_real_escape_string($conn, $form['can_edit_roles']) . "',
                '" . mysqli_real_escape_string($conn, $form['can_delete_roles']) . "',
                0,
                {$form['email_on_submit']},
                {$form['email_notify_admin']},
                {$form['email_confirmation']},
                {$form['enable_pdf_download']},
                {$dup_expires},
                {$dup_max_sub},
                {$dup_password},
                {$dup_one_per},
                {$dup_thankyou},
                {$dup_redirect},
                {$dup_steps},
                {$dup_schedule_start},
                {$dup_schedule_end},
                {$dup_duplicate_detection},
                {$dup_duplicate_fields},
                {$dup_visible_to},
                {$dup_editable_by},
                'active',
                999,
                {$user_id}
            )";
            mysqli_query($conn, $insert_query);

            $upload_dir = __DIR__ . '/uploads/crud/' . $new_slug;
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

            logActivity($user_id, $username, 'Duplicated Form', "Duplicated form: {$form['form_name']} -> $new_name");

            echo json_encode(['success' => true, 'message' => 'Form duplicated successfully']);
            exit();
        }

        // =============================================
        // AI REWRITE PROMPT
        // =============================================
        if ($action === 'aiRewritePrompt') {
            $raw_prompt = trim($_POST['prompt'] ?? '');
            if (empty($raw_prompt)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Please enter a prompt first']);
                exit();
            }

            $gemini_key = getSetting('gemini_api_key', '');
            if (empty($gemini_key)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini API key not configured. Go to Settings > API Integration to add it.']);
                exit();
            }

            $mode = trim($_POST['mode'] ?? 'create');

            if ($mode === 'modify') {
                $system_prompt = "You are an expert CRUD form architect. The user has an existing form and wants to modify it. "
                    . "Rewrite their modification request into a clear, detailed specification. "
                    . "Include: (1) Which NEW fields to ADD (with types, validation, options). "
                    . "(2) Which existing fields to MODIFY and exactly what to change (e.g., make required, add validation, change options, change type). "
                    . "(3) Which fields to REMOVE if any. "
                    . "(4) New validation rules, dropdown options, or chart settings needed. "
                    . "(5) Any other structural changes (field widths, ordering, etc.). "
                    . "Be specific about preserving existing fields that are not mentioned for changes. "
                    . "Write 3-8 sentences as a clear modification specification. Do NOT generate JSON or code. Do NOT wrap in quotes or markdown.";
            } else {
                $system_prompt = "You are an expert CRUD form architect for web applications. The user will describe a form they want to build. "
                    . "Your job is to rewrite their rough idea into a detailed, professional form specification. "
                    . "Include: (1) All fields the form should have with appropriate types (text, email, number, date, select, textarea, file, tel, url, checkbox, radio, gps, camera, signature, etc.). "
                    . "(2) Which fields should be REQUIRED vs optional — primary identifiers like name/email/ID should be required, supplementary fields like notes/comments should be optional. "
                    . "(3) Validation rules — specify realistic min/max ranges (e.g., age: 1-120, percentage: 0-100, salary: minimum 0, phone: 10-15 digits, text lengths: name 2-100 chars). "
                    . "(4) For dropdown/radio fields, list the specific options (3-8 choices). "
                    . "(5) Which categorical fields (like status, gender, department, category) should have chart visualizations on the dashboard (pie/doughnut for categories, bar for numeric comparisons, line for trends over time). "
                    . "(6) Smart field widths — half for short fields like name/email/phone, full for textarea/descriptions/addresses. "
                    . "Write 4-8 sentences as a clear natural language specification. Do NOT generate JSON or code. Do NOT wrap in quotes or markdown.";
            }

            $payload = json_encode([
                'contents' => [
                    ['parts' => [['text' => $system_prompt . "\n\nUser's rough idea: " . $raw_prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 500
                ]
            ]);

            $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($gemini_key);

            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 30,
                    'ignore_errors' => true
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ];

            $ctx = stream_context_create($opts);
            $response = @file_get_contents($api_url, false, $ctx);

            if ($response === false) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to connect to Gemini API. Check your internet connection.']);
                exit();
            }

            $data = json_decode($response, true);

            if (isset($data['error'])) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini API error: ' . ($data['error']['message'] ?? 'Unknown error')]);
                exit();
            }

            $rewritten = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (empty($rewritten)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini returned an empty response. Try again.']);
                exit();
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'rewritten_prompt' => trim($rewritten)]);
            exit();
        }

        // =============================================
        // AI BUILD FORM
        // =============================================
        if ($action === 'aiBuildForm') {
            $prompt = trim($_POST['prompt'] ?? '');
            if (empty($prompt)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'No prompt provided']);
                exit();
            }

            $gemini_key = getSetting('gemini_api_key', '');
            if (empty($gemini_key)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini API key not configured. Go to Settings > API Integration to add it.']);
                exit();
            }

            $system_prompt = <<<'PROMPT'
You are a CRUD form builder AI. Generate a complete form definition as a single valid JSON object. No markdown, no code fences, no explanation — ONLY raw JSON.

The JSON must have this exact structure:
{
  "form_name": "string - human readable form title",
  "form_icon": "string - Font Awesome 6 class, e.g. fas fa-users",
  "form_description": "string - brief 1-sentence description",
  "access_type": "both|admin|user",
  "is_public": false,
  "email_on_submit": false,
  "email_notify_admin": false,
  "email_confirmation": false,
  "enable_pdf_download": false,
  "max_submissions": 0,
  "one_per_user": false,
  "fields": [
    {
      "label": "string - human readable label",
      "type": "text|email|number|date|datetime|time|tel|url|textarea|select|radio|checkbox|file|signature|color|range|hidden|password|link|qr_scanner|camera|gps|appointment",
      "name": "string - snake_case field name",
      "icon": "string - Font Awesome 6 class",
      "placeholder": "string - hint text or empty string",
      "width": "half|full",
      "required": true,
      "show_in_table": true,
      "options": ["only for select/radio/checkbox - array of option strings"],
      "validation": {"min": null, "max": null, "step": null},
      "chart_enabled": false,
      "chart_type": "pie|doughnut|bar|line|area",
      "chart_label": "string - chart title for dashboard display",
      "unique": false,
      "regex": "optional regex pattern string",
      "custom_error": "optional custom validation error message",
      "appointment_config": {
        "slot_duration": 30,
        "start_time": "09:00",
        "end_time": "17:00",
        "break_start": "12:00",
        "break_end": "13:00",
        "days_off": [0, 6],
        "holidays": [],
        "max_bookings": 1,
        "advance_days": 30
      }
    }
  ]
}

=== FIELD TYPE RULES ===
- Generate 5-15 fields depending on form complexity
- Use appropriate types: email for emails, tel for phones, date for dates/DOB, number for quantities/amounts/scores/ages, select for limited choices (3-15 options), radio for 2-5 exclusive choices, textarea for long text, file for attachments, gps for location/coordinates, appointment for scheduling
- Every field MUST have a unique snake_case "name"
- For select/radio/checkbox: provide realistic "options" array (3-8 choices)
- Use "half" width for short fields (name, email, phone, date, number, select), "full" for textarea, descriptions, addresses
- Pick relevant Font Awesome 6 icons per field

=== REQUIRED FIELD INTELLIGENCE ===
- "required": true for: full name, email, phone, ID numbers, primary dates, key selection fields
- "required": false for: comments, notes, additional description, secondary contact info, optional file uploads

=== VALIDATION RULES ===
- NUMBER: ALWAYS set sensible min/max. Examples: age min:1 max:120, percentage min:0 max:100, quantity min:1 max:10000
- TEXT: min = min chars, max = max chars. Examples: full_name min:2 max:100, description min:10 max:500
- TEL: min = min digits, max = max digits. Examples: phone min:10 max:15
- DATE: min/max as YYYY-MM-DD
- RANGE: ALWAYS set min, max, step
- OMIT validation for: select, radio, checkbox, file, email, url, signature, camera, gps, color, hidden

=== CHART VISUALIZATION ===
- Enable charts on 2-4 analytically valuable fields per form
- select/radio with categorical data: chart_enabled:true, chart_type:"pie", chart_label:"[Label] Distribution"
- number/range fields: chart_enabled:true, chart_type:"bar", chart_label:"[Label] Overview"
- date fields: chart_enabled:true, chart_type:"line", chart_label:"[Label] Over Time"

=== TABLE DISPLAY ===
- "show_in_table": true for the 5-6 most important/identifying fields
- "show_in_table": false for long text, file uploads, hidden, GPS, signature

=== GENERAL ===
- access_type: "both" unless prompt specifically says admin-only or user-only
- is_public: true only if prompt implies public-facing form
- Return ONLY the JSON object, nothing else
PROMPT;

            $payload = json_encode([
                'contents' => [
                    ['parts' => [['text' => $system_prompt . "\n\nBuild a form for: " . $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 4096,
                    'responseMimeType' => 'application/json'
                ]
            ]);

            $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($gemini_key);

            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 60,
                    'ignore_errors' => true
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ];

            $ctx = stream_context_create($opts);
            $response = @file_get_contents($api_url, false, $ctx);

            if ($response === false) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to connect to Gemini API. Check your internet connection.']);
                exit();
            }

            $data = json_decode($response, true);

            if (isset($data['error'])) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini API error: ' . ($data['error']['message'] ?? 'Unknown error')]);
                exit();
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (empty($text)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini returned an empty response. Try again.']);
                exit();
            }

            // Clean response - strip markdown code fences if present
            $text = trim($text);
            $text = preg_replace('/^```json\s*/s', '', $text);
            $text = preg_replace('/^```\s*/s', '', $text);
            $text = preg_replace('/\s*```$/s', '', $text);
            $text = trim($text);

            $form_data = json_decode($text, true);
            if (!$form_data || !isset($form_data['fields']) || !is_array($form_data['fields'])) {
                error_log("AI form builder - invalid JSON: " . substr($text, 0, 500));
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'AI returned invalid form data. Please try again with a more specific prompt.']);
                exit();
            }

            // Sanitize field types
            $valid_types = ['text','email','number','date','datetime','time','tel','url','textarea','select','radio','checkbox','file','signature','color','range','hidden','password','link','qr_scanner','camera','gps','appointment'];
            foreach ($form_data['fields'] as &$field) {
                if (!in_array($field['type'] ?? '', $valid_types)) {
                    $field['type'] = 'text';
                }
                $field['name'] = preg_replace('/[^a-z0-9_]/', '_', strtolower($field['name'] ?? 'field'));
            }
            unset($field);

            ob_end_clean();
            echo json_encode(['success' => true, 'form_data' => $form_data]);
            exit();
        }

        // =============================================
        // AI MODIFY FORM (Edit Mode)
        // =============================================
        if ($action === 'aiModifyForm') {
            $prompt = trim($_POST['prompt'] ?? '');
            $existing_fields = trim($_POST['existing_fields'] ?? '[]');
            $form_name = trim($_POST['form_name'] ?? '');

            if (empty($prompt)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'No prompt provided']);
                exit();
            }

            $gemini_key = getSetting('gemini_api_key', '');
            if (empty($gemini_key)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini API key not configured. Go to Settings > API Integration to add it.']);
                exit();
            }

            $system_prompt = <<<'PROMPT'
You are a CRUD form modifier AI. You will receive an existing form's current fields and a modification request. Output the COMPLETE updated fields as a single valid JSON object. No markdown, no code fences, no explanation — ONLY raw JSON.

The JSON must have this exact structure:
{
  "fields": [
    {
      "label": "string - human readable label",
      "type": "text|email|number|date|datetime|time|tel|url|textarea|select|radio|checkbox|file|signature|color|range|hidden|password|link|qr_scanner|camera|gps|appointment",
      "name": "string - snake_case field name",
      "icon": "string - Font Awesome 6 class",
      "placeholder": "string - hint text or empty string",
      "width": "half|full",
      "required": true,
      "show_in_table": true,
      "options": ["only for select/radio/checkbox - array of option strings"],
      "validation": {"min": null, "max": null, "step": null},
      "chart_enabled": false,
      "chart_type": "pie|doughnut|bar|line|area",
      "chart_label": "string - chart title",
      "unique": false,
      "regex": "optional regex pattern string",
      "custom_error": "optional custom validation error message",
      "appointment_config": {
        "slot_duration": 30,
        "start_time": "09:00",
        "end_time": "17:00",
        "break_start": "12:00",
        "break_end": "13:00",
        "days_off": [0, 6],
        "holidays": [],
        "max_bookings": 1,
        "advance_days": 30
      }
    }
  ]
}

=== MODIFICATION RULES ===
- Output ALL fields — both unchanged existing fields AND any new/modified ones
- PRESERVE all properties for fields NOT being changed
- ADD new fields at the end of the array unless the user specifies a position
- REMOVE fields ONLY when the user explicitly asks to remove/delete them
- MODIFY only the specific properties the user asks to change on existing fields
- Maintain the original field order for unchanged fields

=== FIELD TYPE RULES ===
- Available types: text, email, number, date, datetime, time, tel, url, textarea, select, radio, checkbox, file, signature, color, range, hidden, password, link, qr_scanner, camera, gps, appointment
- For select/radio/checkbox: provide realistic options array (3-8 choices)
- Use half width for short fields, full for textarea/descriptions/addresses

=== VALIDATION RULES ===
- NUMBER: ALWAYS set min/max (age 1-120, percentage 0-100, quantity 1-10000, etc.)
- TEXT: min/max for chars (name 2-100, description 10-500, etc.)
- TEL: min/max for digits (phone 10-15, extension 3-6, etc.)
- DATE: min/max as YYYY-MM-DD
- RANGE: ALWAYS set min, max, step

=== REQUIRED FIELD INTELLIGENCE ===
- required:true for identifiers (name, email, phone, ID), key selections (status, category, department)
- required:false for supplementary (comments, notes, optional uploads, remarks)

=== CHART VISUALIZATION ===
- Enable on 2-4 analytically valuable fields
- pie/doughnut for categorical, bar for numeric, line for dates

=== TABLE DISPLAY ===
- show_in_table:true for the 5-6 most important fields
- show_in_table:false for long text, file uploads, hidden, GPS, signature

=== IMPORTANT ===
- Return ONLY the JSON object with a "fields" key
- Include ALL fields in output (unchanged + modified + new)
- Missing fields = deleted, so be careful
PROMPT;

            $user_message = "EXISTING FORM: \"" . $form_name . "\"\n\nCURRENT FIELDS:\n" . $existing_fields . "\n\nMODIFICATION REQUEST:\n" . $prompt;

            $payload = json_encode([
                'contents' => [
                    ['parts' => [['text' => $system_prompt . "\n\n" . $user_message]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 4096,
                    'responseMimeType' => 'application/json'
                ]
            ]);

            $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($gemini_key);

            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 60,
                    'ignore_errors' => true
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ];

            $ctx = stream_context_create($opts);
            $response = @file_get_contents($api_url, false, $ctx);

            if ($response === false) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to connect to Gemini API. Check your internet connection.']);
                exit();
            }

            $data = json_decode($response, true);

            if (isset($data['error'])) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini API error: ' . ($data['error']['message'] ?? 'Unknown error')]);
                exit();
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (empty($text)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Gemini returned an empty response. Try again.']);
                exit();
            }

            // Clean response
            $text = trim($text);
            $text = preg_replace('/^```json\s*/s', '', $text);
            $text = preg_replace('/^```\s*/s', '', $text);
            $text = preg_replace('/\s*```$/s', '', $text);
            $text = trim($text);

            $form_data = json_decode($text, true);
            if (!$form_data || !isset($form_data['fields']) || !is_array($form_data['fields'])) {
                error_log("AI form modifier - invalid JSON: " . substr($text, 0, 500));
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'AI returned invalid data. Please try again with a more specific request.']);
                exit();
            }

            // Sanitize field types
            $valid_types = ['text','email','number','date','datetime','time','tel','url','textarea','select','radio','checkbox','file','signature','color','range','hidden','password','link','qr_scanner','camera','gps','appointment'];
            foreach ($form_data['fields'] as &$field) {
                if (!in_array($field['type'] ?? '', $valid_types)) {
                    $field['type'] = 'text';
                }
                $field['name'] = preg_replace('/[^a-z0-9_]/', '_', strtolower($field['name'] ?? 'field'));
            }
            unset($field);

            ob_end_clean();
            echo json_encode(['success' => true, 'form_data' => $form_data]);
            exit();
        }

    } catch (Exception $e) {
        error_log("crud_settings.php error: " . $e->getMessage());
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }

    // Unknown action
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>CRUD Form Builder</title>
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
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>
    
    <style>
        /* ============================================================
           CRUD SETTINGS - WORLD-CLASS MODERN UI
           Same design language as Users page
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
            display: flex; align-items: center; gap: 8px;
        }
        .page-header-left .title-group .subtitle .badge-beta {
            font-size: 10px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.05em; padding: 2px 10px; border-radius: var(--radius-full);
            background: var(--accent-purple-bg); color: var(--accent-purple);
            border: 1px solid rgba(139, 92, 246, 0.15);
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
            position: relative; overflow: hidden; user-select: none;
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

        /* Stats */
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
            height: 2.5px; background: linear-gradient(90deg, var(--accent-purple), var(--accent-pink));
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

        /* Table */
        .table-wrapper {
            background: var(--bg-card); border-radius: var(--radius-lg);
            border: 1px solid var(--border-color); overflow: hidden;
            transition: all var(--transition-normal);
        }
        .table-wrapper:hover { border-color: var(--border-hover); }
        .table-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap; gap: 12px;
        }
        .table-toolbar .left { display: flex; align-items: center; gap: 14px; }
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

        /* Modal - Full Screen */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.7); backdrop-filter: blur(12px);
            z-index: 9999; align-items: center; justify-content: center;
            padding: 20px; animation: overlayIn var(--transition-normal);
        }
        .modal-overlay.active { display: flex; }
        @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }

        .modal-fullscreen {
            background: var(--bg-card); border-radius: var(--radius-xl);
            max-width: 100%; width: 100%; height: 95vh;
            box-shadow: var(--shadow-xl); border: 1px solid var(--border-color);
            overflow: hidden; animation: modalIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex; flex-direction: column;
            position: relative;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.92) translateY(24px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-header {
            padding: 20px 28px 16px; border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .modal-header h3 {
            font-size: 20px; font-weight: 700; margin: 0;
            color: var(--text-primary); display: flex; align-items: center; gap: 12px;
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

        .modal-body {
            padding: 24px 28px; overflow-y: auto; flex: 1;
        }

        /* Form Builder Sections */
        .form-builder-section {
            background: var(--bg-secondary); border-radius: var(--radius-lg);
            padding: 20px 24px; margin-bottom: 20px;
            border: 1px solid var(--border-color);
            transition: all var(--transition-normal);
        }
        .form-builder-section:hover { border-color: var(--border-hover); }
        .form-builder-section h4 {
            font-size: 16px; font-weight: 600; color: var(--text-primary);
            margin: 0 0 4px; display: flex; align-items: center; gap: 10px;
        }
        .form-builder-section h4 i { color: var(--accent-purple); }
        .form-builder-section .section-desc {
            font-size: 13px; color: var(--text-muted);
            margin: 0 0 16px; padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        /* AI Builder */
        .ai-builder-section {
            background: linear-gradient(135deg, var(--accent-purple-bg), var(--accent-blue-bg));
            border-color: rgba(139, 92, 246, 0.2);
        }
        .ai-badge {
            font-size: 10px; font-weight: 600; text-transform: uppercase;
            padding: 2px 12px; border-radius: var(--radius-full);
            background: var(--accent-purple); color: #fff;
            letter-spacing: 0.04em;
        }
        .ai-rewritten-box {
            background: var(--bg-card); padding: 16px 20px;
            border-radius: var(--radius-md); border: 1px solid var(--border-color);
            color: var(--text-primary); font-size: 14px; line-height: 1.6;
            min-height: 80px; max-height: 200px; overflow-y: auto;
            white-space: pre-wrap;
        }
        .ai-btn-row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .ai-hint { font-size: 12px; color: var(--text-muted); }
        .ai-loading-bar {
            height: 4px; background: var(--border-color); border-radius: var(--radius-full);
            overflow: hidden; margin: 12px 0;
        }
        .ai-loading-progress {
            height: 100%; width: 30%; background: linear-gradient(90deg, var(--accent-purple), var(--accent-blue));
            border-radius: var(--radius-full); animation: loadingProgress 1.5s ease-in-out infinite;
        }
        @keyframes loadingProgress { 0% { transform: translateX(-100%); } 100% { transform: translateX(400%); } }
        .ai-loading-text { font-size: 14px; color: var(--text-secondary); }

        /* Toggle Cards */
        .fb-toggle-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
        }
        .fb-toggle-card {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; background: var(--bg-card);
            border-radius: var(--radius-md); border: 1px solid var(--border-color);
            transition: all var(--transition-fast);
            gap: 12px;
        }
        .fb-toggle-card:hover { border-color: var(--border-hover); }
        .fb-toggle-card-content { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .fb-toggle-card-icon {
            width: 36px; height: 36px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .fb-icon-blue { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .fb-icon-green { background: var(--accent-green-bg); color: var(--accent-green); }
        .fb-icon-purple { background: var(--accent-purple-bg); color: var(--accent-purple); }
        .fb-icon-warning { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .fb-icon-navy { background: rgba(0, 31, 63, 0.15); color: #001f3f; }
        .fb-toggle-card-title { font-size: 14px; font-weight: 600; color: var(--text-primary); }
        .fb-toggle-card-desc { font-size: 12px; color: var(--text-muted); }

        /* Toggle Switch */
        .fb-toggle { flex-shrink: 0; }
        .fb-toggle-input { display: none; }
        .fb-toggle-label {
            display: inline-block; width: 44px; height: 24px;
            background: var(--bg-input); border-radius: var(--radius-full);
            cursor: pointer; position: relative; transition: all var(--transition-fast);
            border: 2px solid var(--border-color);
        }
        .fb-toggle-label .fb-toggle-slider {
            position: absolute; top: 2px; left: 2px;
            width: 16px; height: 16px; background: var(--text-muted);
            border-radius: var(--radius-full); transition: all var(--transition-fast);
        }
        .fb-toggle-input:checked + .fb-toggle-label {
            background: var(--accent-blue); border-color: var(--accent-blue);
        }
        .fb-toggle-input:checked + .fb-toggle-label .fb-toggle-slider {
            transform: translateX(20px); background: #fff;
        }

        /* Form Grid */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .form-grid-3col { grid-template-columns: repeat(3, 1fr); }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .form-group label i { margin-right: 4px; color: var(--text-muted); width: 16px; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 14px; border-radius: var(--radius-md);
            border: 1px solid var(--border-color); background: var(--bg-input);
            color: var(--text-primary); font-size: 14px; font-family: inherit;
            transition: all var(--transition-fast); width: 100%;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-bg);
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-group small { font-size: 12px; color: var(--text-muted); }

        /* Icon Preview */
        .icon-preview-wrapper { display: flex; align-items: center; gap: 10px; }
        .icon-preview-box {
            width: 40px; height: 40px; border-radius: var(--radius-md);
            border: 1px solid var(--border-color); background: var(--bg-card);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: var(--text-primary); flex-shrink: 0;
        }
        .form-logo-upload-wrapper { display: flex; flex-direction: column; gap: 6px; }
        .form-logo-preview {
            width: 120px; height: 80px; border-radius: var(--radius-md);
            border: 2px dashed var(--border-color); background: var(--bg-input);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; cursor: pointer; transition: all var(--transition-fast);
            color: var(--text-muted); font-size: 12px;
        }
        .form-logo-preview:hover { border-color: var(--accent-blue); background: var(--bg-card-hover); }
        .form-logo-preview i { font-size: 24px; color: var(--text-muted); }
        .form-logo-preview.has-image { border-style: solid; padding: 4px; }
        .form-logo-preview.has-image img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .form-logo-preview.has-image i, .form-logo-preview.has-image span { display: none; }

        /* Field Cards */
        .fields-container { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }
        .field-card {
            background: var(--bg-card); border-radius: var(--radius-md);
            border: 1px solid var(--border-color); padding: 16px 20px;
            transition: all var(--transition-fast);
        }
        .field-card:hover { border-color: var(--border-hover); }
        .field-card-ghost { opacity: 0.4; border-style: dashed; }
        .field-card-header {
            display: flex; align-items: center; gap: 12px;
            padding-bottom: 12px; border-bottom: 1px solid var(--border-color);
            margin-bottom: 12px;
        }
        .field-drag-handle { cursor: grab; color: var(--text-muted); font-size: 14px; }
        .field-drag-handle:active { cursor: grabbing; }
        .field-number {
            font-size: 12px; font-weight: 600; color: var(--text-muted);
            background: var(--bg-input); padding: 2px 10px; border-radius: var(--radius-full);
        }
        .field-label-preview { font-weight: 600; color: var(--text-primary); flex: 1; }
        .field-type-badge {
            font-size: 11px; font-weight: 500; text-transform: uppercase;
            padding: 2px 12px; border-radius: var(--radius-full);
            background: var(--accent-purple-bg); color: var(--accent-purple);
        }
        .field-remove-btn {
            width: 30px; height: 30px; border-radius: var(--radius-sm);
            border: none; background: transparent; color: var(--text-muted);
            cursor: pointer; transition: all var(--transition-fast);
            display: flex; align-items: center; justify-content: center;
        }
        .field-remove-btn:hover { background: var(--accent-red-bg); color: var(--accent-red); }
        .field-card-body { display: flex; flex-direction: column; gap: 8px; }

        /* Quick Add Row */
        .quick-add-row {
            display: flex; flex-wrap: wrap; gap: 6px;
            padding: 10px 0; border-top: 1px solid var(--border-color);
            margin-top: 4px;
        }
        .quick-add-btn {
            padding: 4px 12px; border-radius: var(--radius-full);
            border: 1px solid var(--border-color); background: var(--bg-input);
            color: var(--text-secondary); font-size: 11px; font-weight: 500;
            cursor: pointer; transition: all var(--transition-fast);
            font-family: inherit; white-space: nowrap;
        }
        .quick-add-btn:hover { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue); }

        /* Options Tags */
        .opt-tags-wrapper { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; padding: 6px 10px; background: var(--bg-input); border-radius: var(--radius-md); border: 1px solid var(--border-color); }
        .opt-tags-wrapper:focus-within { border-color: var(--accent-blue); box-shadow: 0 0 0 3px var(--accent-blue-bg); }
        .opt-tags-container { display: flex; flex-wrap: wrap; gap: 4px; flex: 1; }
        .opt-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: var(--accent-blue-bg); color: var(--accent-blue); border-radius: var(--radius-full); font-size: 12px; }
        .opt-tag-remove { cursor: pointer; font-size: 10px; opacity: 0.6; }
        .opt-tag-remove:hover { opacity: 1; }
        .opt-tags-input { border: none; background: transparent; color: var(--text-primary); font-size: 13px; outline: none; flex: 1; min-width: 120px; padding: 4px 0; }

        /* Regex Tester */
        .regex-tester-box { margin-top: 8px; padding: 8px 12px; background: var(--bg-secondary); border-radius: var(--radius-sm); border: 1px solid var(--border-color); }
        .regex-tester-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .regex-tester-label { font-size: 11px; font-weight: 500; color: var(--text-muted); }
        .regex-test-input { flex: 1; min-width: 120px; padding: 4px 10px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-input); color: var(--text-primary); font-size: 12px; font-family: monospace; }
        .regex-test-input:focus { outline: none; border-color: var(--accent-blue); }
        .regex-result-badge { font-size: 11px; font-weight: 500; padding: 2px 10px; border-radius: var(--radius-full); }
        .regex-match { background: var(--accent-green-bg); color: var(--accent-green); }
        .regex-no-match { background: var(--accent-red-bg); color: var(--accent-red); }
        .regex-error { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .regex-presets { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
        .regex-preset-btn { padding: 2px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-input); color: var(--text-muted); font-size: 10px; cursor: pointer; font-family: monospace; transition: all var(--transition-fast); }
        .regex-preset-btn:hover { border-color: var(--accent-blue); color: var(--accent-blue); }

        /* Conditional Logic */
        .condition-toggle-row { margin-bottom: 6px; }
        .condition-config { display: none; padding: 10px 14px; background: var(--bg-secondary); border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-top: 6px; }
        .condition-config.active { display: block; }

        /* Dependent Dropdown */
        .dep-map-builder { margin-top: 8px; padding: 8px 12px; background: var(--bg-secondary); border-radius: var(--radius-sm); border: 1px solid var(--border-color); }
        .dep-map-empty { font-size: 13px; color: var(--text-muted); padding: 8px 0; }
        .dep-map-table { display: flex; flex-direction: column; gap: 4px; }
        .dep-map-header-row { display: grid; grid-template-columns: 1fr 2fr; gap: 12px; font-weight: 600; font-size: 12px; color: var(--text-muted); padding: 4px 0; border-bottom: 1px solid var(--border-color); }
        .dep-map-row { display: grid; grid-template-columns: 1fr 2fr; gap: 12px; align-items: center; padding: 4px 0; }
        .dep-map-parent-cell { font-size: 13px; color: var(--text-primary); }
        .dep-map-child-cell input { width: 100%; padding: 4px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-input); color: var(--text-primary); font-size: 12px; }

        /* Step Manager */
        .step-manager { display: none; margin-top: 12px; padding: 12px 16px; background: var(--bg-secondary); border-radius: var(--radius-md); border: 1px solid var(--border-color); }
        .step-manager.active { display: block; }
        .step-manager-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
        .step-manager-item { display: flex; align-items: center; gap: 10px; padding: 6px 10px; background: var(--bg-card); border-radius: var(--radius-sm); border: 1px solid var(--border-color); }
        .step-manager-item .step-num { font-weight: 700; color: var(--accent-purple); font-size: 14px; min-width: 24px; }
        .step-manager-item input { flex: 1; border: none; background: transparent; color: var(--text-primary); font-size: 13px; padding: 4px 0; }
        .step-manager-item input:focus { outline: none; }
        .remove-step-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 0 4px; }
        .remove-step-btn:hover { color: var(--accent-red); }

        /* Searchable Dropdown */
        .searchable-dropdown { position: relative; }
        .searchable-dropdown-input { width: 100%; padding: 10px 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-input); color: var(--text-primary); font-size: 14px; cursor: pointer; }
        .searchable-dropdown-input:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 3px var(--accent-blue-bg); }
        .searchable-dropdown-arrow { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; transition: transform var(--transition-fast); }
        .searchable-dropdown-arrow.open { transform: translateY(-50%) rotate(180deg); }
        .searchable-dropdown-list { position: absolute; top: calc(100% + 4px); left: 0; right: 0; max-height: 200px; overflow-y: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-color); box-shadow: var(--shadow-md); z-index: 100; }
        .searchable-dropdown-item { padding: 8px 14px; cursor: pointer; transition: all var(--transition-fast); display: flex; align-items: center; gap: 8px; color: var(--text-primary); font-size: 13px; }
        .searchable-dropdown-item:hover { background: var(--bg-card-hover); }
        .searchable-dropdown-item.selected { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .searchable-dropdown-item.no-results { cursor: default; color: var(--text-muted); }
        .searchable-dropdown-item i { width: 18px; text-align: center; color: var(--text-muted); }

        /* Multi-select */
        .sd-tags-container { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; padding: 4px 8px; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-input); min-height: 42px; cursor: pointer; transition: all var(--transition-fast); }
        .sd-tags-container.sd-focused { border-color: var(--accent-blue); box-shadow: 0 0 0 3px var(--accent-blue-bg); }
        .sd-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px 2px 10px; background: var(--accent-blue-bg); color: var(--accent-blue); border-radius: var(--radius-full); font-size: 12px; }
        .sd-tag-remove { cursor: pointer; font-size: 10px; opacity: 0.6; }
        .sd-tag-remove:hover { opacity: 1; }
        .sd-multi-input { border: none; background: transparent; color: var(--text-primary); font-size: 13px; outline: none; flex: 1; min-width: 80px; padding: 4px 0; }
        .sd-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: var(--radius-full); background: var(--accent-blue); color: #fff; font-size: 11px; font-weight: 600; margin-right: 4px; }
        .sd-check { font-size: 16px; color: var(--text-muted); width: 20px; text-align: center; }
        .sd-check.sd-checked { color: var(--accent-blue); }
        .sd-item-selected { background: var(--accent-blue-bg); }
        .sd-item-label { flex: 1; }
        .sd-list-actions { padding: 6px 14px; border-bottom: 1px solid var(--border-color); display: flex; gap: 8px; }
        .sd-action-btn { padding: 4px 12px; border-radius: var(--radius-sm); border: none; background: var(--bg-input); color: var(--text-secondary); font-size: 12px; cursor: pointer; transition: all var(--transition-fast); }
        .sd-action-btn:hover { background: var(--accent-blue); color: #fff; }

        /* Floating Bottom Bar */
        .modal-floating-bar {
            display: none; position: sticky; bottom: 0;
            padding: 16px 28px; background: var(--bg-card);
            border-top: 1px solid var(--border-color);
            justify-content: flex-end; gap: 12px;
            flex-shrink: 0;
        }
        .modal-floating-bar .btn { min-width: 120px; justify-content: center; }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-grid-3col { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 16px 18px; }
            .stat-card .stat-value { font-size: 22px; }
            .table-toolbar { flex-direction: column; align-items: stretch; gap: 10px; padding: 14px 16px; }
            .table-toolbar .right { flex-wrap: wrap; }
            .modal-body { padding: 16px; }
            .form-builder-section { padding: 14px 16px; }
            .form-grid-3col { grid-template-columns: 1fr; }
            .fb-toggle-grid { grid-template-columns: 1fr; }
            .modal-fullscreen { height: 98vh; border-radius: var(--radius-lg); margin: 4px; }
            .modal-header { padding: 14px 16px 12px; }
            .modal-header h3 { font-size: 17px; }
            .modal-floating-bar { padding: 12px 16px; flex-direction: column; }
            .modal-floating-bar .btn { width: 100%; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .stat-value { font-size: 18px; }
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .quick-add-row { gap: 4px; }
            .quick-add-btn { font-size: 10px; padding: 2px 8px; }
            .field-card { padding: 12px 14px; }
            .form-logo-preview { width: 80px; height: 60px; }
        }

        /* Smooth Theme Transition */
        * { transition: background-color var(--transition-slow), color var(--transition-slow), border-color var(--transition-slow), box-shadow var(--transition-slow); }

        /* DataTable overrides */
        .table-wrapper .dataTables_wrapper { padding: 0 24px 20px; }
        .table-wrapper .dataTables_wrapper .dataTables_filter { display: none; }
        .table-wrapper .dataTables_wrapper .dataTables_length { display: none; }
        .table-wrapper .dataTables_wrapper .dataTables_info { padding: 16px 0 0; font-size: 13px; color: var(--text-muted); }
        .table-wrapper .dataTables_wrapper .dataTables_paginate { padding: 16px 0 0; }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 6px 14px; border-radius: var(--radius-sm); border: none;
            background: transparent; color: var(--text-secondary); font-weight: 500;
            font-size: 13px; transition: all var(--transition-fast); margin: 0 2px; cursor: pointer;
        }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button:hover { background: var(--bg-input); color: var(--text-primary); }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: var(--accent-purple); color: #fff; border-radius: var(--radius-sm); }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover { background: var(--accent-purple); color: #fff; }
        .table-wrapper .dataTables_wrapper .dataTables_paginate .paginate_button.disabled { opacity: 0.3; cursor: not-allowed; }
        .table-wrapper table.dataTable { width: 100% !important; border-collapse: collapse; margin: 0 !important; font-size: 14px; background: transparent !important; }
        .table-wrapper table.dataTable thead th { padding: 14px 14px 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); border-bottom: 1px solid var(--border-color); background: transparent !important; text-align: left; }
        .table-wrapper table.dataTable tbody td { padding: 14px 14px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); vertical-align: middle; background: transparent !important; }
        .table-wrapper table.dataTable tbody tr:hover td { background: var(--bg-card-hover) !important; }
        .table-wrapper table.dataTable tbody tr:last-child td { border-bottom: none; }

        /* Status Badges */
        .status-badge { display: inline-block; padding: 2px 12px; border-radius: var(--radius-full); font-size: 12px; font-weight: 500; }
        .status-admin { background: #e3f2fd; color: #1565c0; }
        .status-user { background: #e8f5e9; color: #2e7d32; }

        /* Action Icons */
        .action-icon { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 4px 8px; border-radius: var(--radius-sm); transition: all var(--transition-fast); font-size: 14px; }
        .action-icon:hover { background: var(--bg-input); color: var(--text-primary); }
        .edit-icon:hover { color: var(--accent-blue); background: var(--accent-blue-bg); }
        .delete-icon:hover { color: var(--accent-red); background: var(--accent-red-bg); }

        /* Skeleton */
        .skeleton-card { background: var(--bg-card); border-radius: var(--radius-lg); padding: 20px 24px; border: 1px solid var(--border-color); }
        .skeleton { background: var(--bg-input); border-radius: var(--radius-sm); animation: skeletonPulse 1.5s ease-in-out infinite; }
        .skeleton-text { height: 16px; width: 60%; margin: 8px 0; }
        .skeleton-text-large { height: 24px; width: 40%; }
        @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }

        .initially-hidden { display: none; }
        .field-count-badge {
            font-size: 12px; font-weight: 500; color: var(--text-secondary);
            background: var(--bg-input); padding: 2px 12px; border-radius: var(--radius-full);
            margin-left: 8px;
        }
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
                <span class="current">CRUD Form Builder</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-cogs"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>CRUD Form Builder</h1>
                        <p class="subtitle">
                            Create and manage dynamic forms for your application
                            <span class="badge-beta">v3.0</span>
                        </p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="loadForms()" title="Refresh (Ctrl+R)">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                    <button class="btn btn-primary" onclick="openFormBuilder()">
                        <i class="fas fa-plus"></i> Create Form
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Forms</span>
                        <span class="stat-icon"><i class="fas fa-table"></i></span>
                    </div>
                    <div class="stat-value" id="totalForms">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Active</span>
                        <span class="stat-icon"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <div class="stat-value" id="activeForms">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Inactive</span>
                        <span class="stat-icon"><i class="fas fa-pause-circle"></i></span>
                    </div>
                    <div class="stat-value" id="inactiveForms">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Fields</span>
                        <span class="stat-icon"><i class="fas fa-list-ul"></i></span>
                    </div>
                    <div class="stat-value" id="totalFields">0</div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="left">
                        <h3><i class="fas fa-table"></i> Form Definitions</h3>
                        <span class="count-badge" id="formCountBadge">0 forms</span>
                    </div>
                    <div class="right">
                        <button class="btn btn-ghost btn-sm" onclick="loadForms()"><i class="fas fa-sync"></i></button>
                    </div>
                </div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-card"><div class="skeleton skeleton-text-large"></div><div class="skeleton skeleton-text"></div></div>
                </div>

                <div id="mainContent" class="initially-hidden">
                    <div class="table-responsive">
                        <table id="formsTable" class="display" style="width:100%">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Form</th>
                                    <th>Fields</th>
                                    <th>Access</th>
                                    <th>Status</th>
                                    <th>Public</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- FORM BUILDER - FULL SCREEN MODAL -->
    <!-- ============================================ -->
    <div class="modal-overlay" id="formBuilderModal" onclick="if(event.target===this)closeFormBuilder()">
        <div class="modal-fullscreen" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="builderTitle"><i class="fas fa-hammer"></i> Create New Form</h3>
                <button class="modal-close" onclick="closeFormBuilder()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="builderBody">
                <form id="formBuilderForm" onsubmit="return false;">
                    <input type="hidden" id="editFormId" value="">

                    <!-- ===== AI FORM BUILDER ===== -->
                    <div class="form-builder-section ai-builder-section" id="aiBuilderSection">
                        <h4><i class="fas fa-robot"></i> AI Form Builder <span class="ai-badge">Powered by Gemini</span></h4>
                        <p class="section-desc" id="aiSectionDesc">Describe the form you need in plain English. AI will generate the complete form structure for you.</p>

                        <div id="aiStep1">
                            <div class="form-group" style="margin-bottom:12px;">
                                <label><i class="fas fa-pen-fancy"></i> Describe Your Form</label>
                                <textarea id="aiPromptInput" rows="3" placeholder="e.g. I need a Student Registration Form with name, email, phone, date of birth, gender, course selection, and a photo upload field..." style="resize:vertical;"></textarea>
                            </div>
                            <div class="ai-btn-row">
                                <button type="button" class="btn btn-primary" id="aiRewriteBtn" onclick="aiRewritePrompt()">
                                    <i class="fas fa-magic"></i> Rewrite Prompt
                                </button>
                                <span class="ai-hint" id="aiHint">AI will enhance your prompt for better results</span>
                            </div>
                        </div>

                        <div id="aiStep2" style="display:none;">
                            <div class="form-group" style="margin-bottom:12px;">
                                <label><i class="fas fa-wand-magic-sparkles"></i> Enhanced Prompt</label>
                                <div class="ai-rewritten-box" id="aiRewrittenText"></div>
                            </div>
                            <div class="ai-btn-row">
                                <button type="button" class="btn btn-success" id="aiBuildBtn" onclick="aiBuildForm()">
                                    <i class="fas fa-hammer"></i> Start Build
                                </button>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="aiResetPrompt()">
                                    <i class="fas fa-redo"></i> Start Over
                                </button>
                            </div>
                        </div>

                        <div id="aiLoading" style="display:none;">
                            <div class="ai-loading-bar"><div class="ai-loading-progress"></div></div>
                            <p class="ai-loading-text" id="aiLoadingText"><i class="fas fa-cog fa-spin"></i> AI is thinking...</p>
                        </div>
                    </div>

                    <!-- ===== FORM DETAILS ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-info-circle"></i> Form Details</h4>
                        <p class="section-desc">Define your form's name, icon, and description. The icon appears in the sidebar menu.</p>
                        <div class="form-group" style="margin-bottom:15px;">
                            <label><i class="fas fa-font"></i> Form Name *</label>
                            <input type="text" id="formName" required placeholder="e.g. Students, Orders, Products, Invoices">
                        </div>
                        <div class="form-grid form-grid-3col">
                            <div class="form-group">
                                <label><i class="fas fa-icons"></i> Sidebar Icon</label>
                                <div class="icon-preview-wrapper">
                                    <input type="text" id="formIcon" value="fas fa-list" placeholder="e.g. fas fa-users" oninput="updateFormIconPreview()" style="flex:1;">
                                    <div class="icon-preview-box" id="formIconPreview"><i class="fas fa-list"></i></div>
                                </div>
                                <small style="color:var(--text-muted);"><a href="https://fontawesome.com/icons" target="_blank" style="color:var(--accent-blue);">Browse Font Awesome icons</a></small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-align-left"></i> Description</label>
                                <input type="text" id="formDescription" placeholder="Brief description of this form">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-image"></i> Form Logo</label>
                                <div class="form-logo-upload-wrapper">
                                    <div class="form-logo-preview" id="formLogoPreview" onclick="document.getElementById('formLogoInput').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Upload Logo</span>
                                    </div>
                                    <input type="file" id="formLogoInput" accept="image/*" onchange="previewFormLogo(this)" style="display:none;">
                                    <div style="display:flex;gap:6px;">
                                        <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('formLogoInput').click()" style="font-size:12px;flex:1;"><i class="fas fa-upload"></i> Choose</button>
                                        <button type="button" class="btn btn-sm btn-danger" id="formLogoRemoveBtn" onclick="removeFormLogo()" style="font-size:11px;display:none;"><i class="fas fa-times"></i> Remove</button>
                                    </div>
                                    <input type="hidden" id="formLogo" value="">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== ACCESS & PERMISSIONS ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-shield-alt"></i> Access & Permissions</h4>
                        <p class="section-desc">Control who can view, edit, and delete records in this form.</p>
                        <div class="form-grid" style="grid-template-columns: repeat(3, 1fr);">
                            <div class="form-group">
                                <label><i class="fas fa-eye"></i> Who Can View *</label>
                                <div id="sdAccessType" data-hidden-input="accessTypeHidden"></div>
                                <input type="hidden" id="accessTypeHidden" value="both">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-edit"></i> Who Can Edit</label>
                                <div id="sdEditRoles" data-multi="true"></div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-trash"></i> Who Can Delete</label>
                                <div id="sdDeleteRoles" data-multi="true"></div>
                            </div>
                        </div>
                        <hr style="margin:16px 0;border-color:var(--border-color);">
                        <p class="section-desc"><i class="fas fa-user-lock" style="color:var(--accent-purple);margin-right:4px;"></i> <strong>Per-User Access</strong> — Restrict this form to specific users</p>
                        <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                            <div class="form-group">
                                <label><i class="fas fa-eye"></i> Visible To Users</label>
                                <div id="sdVisibleToUsers" data-multi="true"></div>
                                <small style="color:var(--text-muted);font-size:11px;">Only these users (+ admins) can see this form</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-pen"></i> Editable By Users</label>
                                <div id="sdEditableByUsers" data-multi="true"></div>
                                <small style="color:var(--text-muted);font-size:11px;">Only these users can edit records</small>
                            </div>
                        </div>
                    </div>

                    <!-- ===== PUBLIC, EMAIL & EXPORT ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-globe"></i> Public Form, Email & Export</h4>
                        <p class="section-desc">Enable a shareable public link, configure email notifications, and export options.</p>
                        <div class="fb-toggle-grid">
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-blue"><i class="fas fa-link"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">Public Form</div>
                                        <div class="fb-toggle-card-desc">Generate a shareable link</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="isPublic" class="fb-toggle-input">
                                    <label for="isPublic" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-green"><i class="fas fa-file-pdf"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">PDF Export</div>
                                        <div class="fb-toggle-card-desc">Download records as PDF</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="enablePdfDownload" class="fb-toggle-input">
                                    <label for="enablePdfDownload" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-warning"><i class="fas fa-envelope"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">Email Admin on Submit</div>
                                        <div class="fb-toggle-card-desc">Notify admin on new submission</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="emailOnSubmit" class="fb-toggle-input">
                                    <label for="emailOnSubmit" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-navy"><i class="fas fa-bell"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">In-App Notification</div>
                                        <div class="fb-toggle-card-desc">Show in dashboard bell</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="emailNotifyAdmin" class="fb-toggle-input">
                                    <label for="emailNotifyAdmin" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-purple"><i class="fas fa-paper-plane"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">Confirmation Email</div>
                                        <div class="fb-toggle-card-desc">Send to submitter (requires email field)</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="emailConfirmation" class="fb-toggle-input">
                                    <label for="emailConfirmation" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== LIMITS & SECURITY ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-shield-alt"></i> Limits & Security</h4>
                        <p class="section-desc">Set form expiry, submission limits, and password protection.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-times"></i> Expiry Date</label>
                                <input type="datetime-local" id="expiresAt">
                                <small>Form stops accepting submissions after this date</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> Max Submissions</label>
                                <input type="number" id="maxSubmissions" min="0" value="0" placeholder="0 = unlimited">
                                <small>0 = unlimited submissions</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Form Password</label>
                                <input type="text" id="formPassword" placeholder="Leave empty for no password">
                                <small>Visitors must enter this password to submit</small>
                            </div>
                        </div>
                        <div class="fb-toggle-grid" style="margin-top:16px;">
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-blue"><i class="fas fa-user-check"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">One Submission Per User</div>
                                        <div class="fb-toggle-card-desc">Limit to one submission per user/IP</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="onePerUser" class="fb-toggle-input">
                                    <label for="onePerUser" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-warning"><i class="fas fa-copy"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">Duplicate Detection</div>
                                        <div class="fb-toggle-card-desc">Prevent duplicate submissions</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="duplicateDetection" class="fb-toggle-input" onchange="toggleDuplicateDetection()">
                                    <label for="duplicateDetection" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                        </div>
                        <div id="duplicateFieldsWrapper" style="display:none;margin-top:12px;padding:14px;background:var(--bg-primary);border-radius:8px;border:1px solid var(--border-color);">
                            <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px;"><i class="fas fa-check-double" style="color:var(--accent-purple);margin-right:6px;"></i> Fields to Check for Duplicates</label>
                            <small style="display:block;color:var(--text-muted);font-size:12px;margin-bottom:10px;">If a new submission matches ALL selected fields of an existing record, it will be rejected.</small>
                            <div id="duplicateFieldsContainer" style="display:flex;flex-wrap:wrap;gap:10px;"></div>
                        </div>
                    </div>

                    <!-- ===== AFTER SUBMISSION ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-smile"></i> After Submission</h4>
                        <p class="section-desc">Customize what happens after a form is submitted.</p>
                        <div class="form-group" style="margin-bottom:12px;">
                            <label><i class="fas fa-comment-dots"></i> Custom Thank You Message</label>
                            <textarea id="thankYouMessage" rows="2" placeholder="Thank you for your submission! We will get back to you shortly."></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label><i class="fas fa-external-link-alt"></i> Redirect URL</label>
                            <input type="url" id="redirectUrl" placeholder="https://example.com/thank-you">
                            <small>If set, user is redirected here after submission</small>
                        </div>
                    </div>

                    <!-- ===== SCHEDULING ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-calendar-check"></i> Form Scheduling</h4>
                        <p class="section-desc">Schedule when the form automatically becomes active and inactive.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-play-circle"></i> Schedule Start</label>
                                <input type="datetime-local" id="scheduleStart">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-stop-circle"></i> Schedule End</label>
                                <input type="datetime-local" id="scheduleEnd">
                            </div>
                        </div>
                    </div>

                    <!-- ===== MULTI-STEP ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-layer-group"></i> Multi-Step Form</h4>
                        <p class="section-desc">Split your form into multiple steps with a progress bar.</p>
                        <div class="fb-toggle-grid" style="grid-template-columns:1fr;">
                            <div class="fb-toggle-card">
                                <div class="fb-toggle-card-content">
                                    <div class="fb-toggle-card-icon fb-icon-purple"><i class="fas fa-layer-group"></i></div>
                                    <div class="fb-toggle-card-info">
                                        <div class="fb-toggle-card-title">Enable Multi-Step Form</div>
                                        <div class="fb-toggle-card-desc">Break long forms into steps with progress indicator</div>
                                    </div>
                                </div>
                                <div class="fb-toggle">
                                    <input type="checkbox" id="enableMultiStep" class="fb-toggle-input" onchange="toggleMultiStep()">
                                    <label for="enableMultiStep" class="fb-toggle-label"><span class="fb-toggle-slider"></span></label>
                                </div>
                            </div>
                        </div>
                        <div id="stepManager" class="step-manager">
                            <div id="stepManagerList" class="step-manager-list"></div>
                            <button type="button" class="btn btn-sm btn-success" onclick="addStep()" style="width:100%;">
                                <i class="fas fa-plus"></i> Add Step
                            </button>
                        </div>
                    </div>

                    <!-- ===== FIELD BUILDER ===== -->
                    <div class="form-builder-section">
                        <h4><i class="fas fa-list-alt"></i> Form Fields <span id="fieldCount" class="field-count-badge">0</span></h4>
                        <p class="section-desc">Add fields to your form. Drag to reorder. Click any field type below to quick-add it.</p>

                        <div class="quick-add-row" id="quickAddRow">
                            <button type="button" class="quick-add-btn" onclick="quickAddField('text')"><i class="fas fa-font"></i> Text</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('email')"><i class="fas fa-envelope"></i> Email</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('number')"><i class="fas fa-hashtag"></i> Number</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('date')"><i class="fas fa-calendar"></i> Date</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('datetime')"><i class="fas fa-clock"></i> Date/Time</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('time')"><i class="fas fa-hourglass"></i> Time</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('tel')"><i class="fas fa-phone"></i> Phone</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('url')"><i class="fas fa-link"></i> URL</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('textarea')"><i class="fas fa-align-left"></i> Textarea</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('select')"><i class="fas fa-list"></i> Dropdown</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('radio')"><i class="fas fa-dot-circle"></i> Radio</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('checkbox')"><i class="fas fa-check-square"></i> Checkbox</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('file')"><i class="fas fa-upload"></i> File</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('signature')"><i class="fas fa-signature"></i> Signature</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('color')"><i class="fas fa-palette"></i> Color</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('range')"><i class="fas fa-sliders-h"></i> Range</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('hidden')"><i class="fas fa-eye-slash"></i> Hidden</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('password')"><i class="fas fa-lock"></i> Password</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('link')"><i class="fas fa-external-link-alt"></i> Link</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('qr_scanner')"><i class="fas fa-qrcode"></i> QR Scanner</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('camera')"><i class="fas fa-camera"></i> Camera</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('gps')"><i class="fas fa-map-marker-alt"></i> GPS</button>
                            <button type="button" class="quick-add-btn" onclick="quickAddField('appointment')"><i class="fas fa-calendar-check"></i> Appointment</button>
                        </div>

                        <div id="fieldsContainer" class="fields-container"></div>
                        <button type="button" class="btn btn-success" onclick="addField()" style="margin-top:15px;width:100%;padding:12px;">
                            <i class="fas fa-plus-circle"></i> Add Field
                        </button>
                    </div>
                </form>
            </div>

            <!-- Floating Bottom Bar -->
            <div class="modal-floating-bar" id="modalFloatingBar">
                <button type="button" class="btn btn-ghost" onclick="closeFormBuilder()"><i class="fas fa-times"></i> Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveForm()"><i class="fas fa-save"></i> Save Form</button>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- SCRIPTS -->
    <!-- ============================================ -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <!-- CSRF Token -->
    <script>
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo generateCSRFToken(); ?>');
        }
    });
    </script>

    <script>
    /* Developed by Rameez Scripts */

    let formsTable = null;
    let formsData = [];
    let isEditMode = false;
    let fieldCounter = 0;
    let sortable = null;

    const FIELD_TYPES = [
        { value: 'text', label: 'Text', icon: 'fas fa-font' },
        { value: 'email', label: 'Email', icon: 'fas fa-envelope' },
        { value: 'number', label: 'Number', icon: 'fas fa-hashtag' },
        { value: 'date', label: 'Date', icon: 'fas fa-calendar' },
        { value: 'datetime', label: 'Date & Time', icon: 'fas fa-clock' },
        { value: 'time', label: 'Time', icon: 'fas fa-hourglass' },
        { value: 'tel', label: 'Phone', icon: 'fas fa-phone' },
        { value: 'url', label: 'URL', icon: 'fas fa-link' },
        { value: 'textarea', label: 'Textarea', icon: 'fas fa-align-left' },
        { value: 'select', label: 'Dropdown', icon: 'fas fa-list' },
        { value: 'radio', label: 'Radio Buttons', icon: 'fas fa-dot-circle' },
        { value: 'checkbox', label: 'Checkbox', icon: 'fas fa-check-square' },
        { value: 'file', label: 'File Upload', icon: 'fas fa-upload' },
        { value: 'signature', label: 'Signature', icon: 'fas fa-signature' },
        { value: 'color', label: 'Color Picker', icon: 'fas fa-palette' },
        { value: 'range', label: 'Range Slider', icon: 'fas fa-sliders-h' },
        { value: 'hidden', label: 'Hidden', icon: 'fas fa-eye-slash' },
        { value: 'password', label: 'Password', icon: 'fas fa-lock' },
        { value: 'link', label: 'Link/URL Display', icon: 'fas fa-external-link-alt' },
        { value: 'qr_scanner', label: 'QR Code Scanner', icon: 'fas fa-qrcode' },
        { value: 'camera', label: 'Camera Capture', icon: 'fas fa-camera' },
        { value: 'gps', label: 'GPS Location', icon: 'fas fa-map-marker-alt' },
        { value: 'appointment', label: 'Appointment Slot', icon: 'fas fa-calendar-check' },
    ];

    const CHART_COMPATIBLE_TYPES = {
        'select': ['pie', 'doughnut', 'bar'],
        'radio': ['pie', 'doughnut', 'bar'],
        'checkbox': ['bar', 'doughnut'],
        'number': ['bar', 'line', 'area'],
        'range': ['bar', 'line', 'area'],
        'date': ['line', 'area', 'bar'],
        'datetime': ['line', 'area', 'bar']
    };

    const CHART_TYPE_LABELS = {
        'pie': { label: 'Pie Chart', icon: 'fas fa-chart-pie' },
        'doughnut': { label: 'Doughnut Chart', icon: 'fas fa-circle-notch' },
        'bar': { label: 'Bar Chart', icon: 'fas fa-chart-bar' },
        'line': { label: 'Line Chart', icon: 'fas fa-chart-line' },
        'area': { label: 'Area Chart', icon: 'fas fa-chart-area' }
    };

    const ROLE_OPTIONS = [
        { value: 'Admin', label: 'Admin', icon: 'fas fa-user-shield' },
        { value: 'User', label: 'User', icon: 'fas fa-user' }
    ];

    const ACCESS_TYPE_OPTIONS = [
        { value: 'both', label: 'Both (Admin & User)', icon: 'fas fa-users' },
        { value: 'admin', label: 'Admin Only', icon: 'fas fa-user-shield' },
        { value: 'user', label: 'User Only', icon: 'fas fa-user' }
    ];

    let cachedUserOptions = null;
    let formSteps = [];

    // ============================
    // LOAD FORMS
    // ============================
    function loadForms() {
        $('#loadingSkeleton').show();
        $('#mainContent').hide();

        $.ajax({
            url: 'crud_settings.php?action=getForms',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#loadingSkeleton').hide();
                $('#mainContent').show();
                if (response.success) {
                    formsData = response.data;
                    updateStats(response.data);
                    initFormsTable(response.data);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Unknown error' });
                }
            },
            error: function(xhr, status, err) {
                $('#loadingSkeleton').hide();
                $('#mainContent').show();
                console.error('AJAX error:', status, err, xhr.responseText);
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not load forms.' });
            }
        });
    }

    function updateStats(data) {
        const total = data.length;
        const active = data.filter(f => f.status === 'active').length;
        const inactive = data.filter(f => f.status === 'inactive').length;
        let totalFields = 0;
        data.forEach(f => { totalFields += f.fields_count || 0; });

        document.getElementById('totalForms').textContent = total;
        document.getElementById('activeForms').textContent = active;
        document.getElementById('inactiveForms').textContent = inactive;
        document.getElementById('totalFields').textContent = totalFields;
        document.getElementById('formCountBadge').textContent = total + ' form' + (total !== 1 ? 's' : '');
    }

    function initFormsTable(data) {
        if (formsTable) formsTable.destroy();

        formsTable = $('#formsTable').DataTable({
            data: data,
            destroy: true,
            columns: [
                { data: 'id', title: 'ID', width: '50px' },
                {
                    data: null, title: 'Form',
                    render: function(data) {
                        return `<i class="${data.form_icon}" style="color:var(--accent-purple);margin-right:8px;"></i>
                                <strong>${data.form_name}</strong>
                                <br><small style="color:var(--text-muted)">${data.form_slug}</small>`;
                    }
                },
                {
                    data: 'fields_count', title: 'Fields',
                    render: function(data) {
                        return `<span class="status-badge" style="background:var(--accent-purple-bg);color:var(--accent-purple);">${data} fields</span>`;
                    }
                },
                {
                    data: 'access_type', title: 'Access',
                    render: function(data) {
                        const colors = { admin: '#fff3e0;color:#e65100', user: '#e8f5e9;color:#2e7d32', both: '#f3e5f5;color:#6a1b9a' };
                        return `<span class="status-badge" style="background:${colors[data] || '#eee;color:#333'}">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                    }
                },
                {
                    data: 'status', title: 'Status',
                    render: function(data) {
                        return data === 'active'
                            ? '<span class="status-badge status-admin">Active</span>'
                            : '<span class="status-badge" style="background:#ffebee;color:#c62828">Inactive</span>';
                    }
                },
                {
                    data: null, title: 'Public',
                    render: function(data) {
                        if (data.is_public == 1 && data.public_token) {
                            return `<a href="public_form.php?token=${data.public_token}" target="_blank" class="btn btn-sm" style="background:var(--accent-purple);color:#fff;font-size:11px;padding:4px 8px;"><i class="fas fa-external-link-alt"></i> Link</a>
                                    <button class="btn btn-sm" onclick="showFormQR('${data.form_name.replace(/'/g, "\\'")}','${data.public_token}')" title="QR Code" style="background:#8e24aa;color:#fff;font-size:11px;padding:4px 8px;margin-left:4px;"><i class="fas fa-qrcode"></i></button>`;
                        }
                        return '<span style="color:var(--text-muted)">Private</span>';
                    }
                },
                {
                    data: 'created_at', title: 'Created',
                    render: function(data) {
                        if (!data) return '-';
                        const d = new Date(data);
                        return d.toLocaleDateString();
                    }
                },
                {
                    data: null, title: 'Actions', orderable: false, width: '150px',
                    render: function(data) {
                        return `<button class="action-icon edit-icon" onclick="editForm(${data.id})" title="Edit"><i class="fas fa-edit"></i></button>
                                <button class="action-icon" onclick="duplicateForm(${data.id})" title="Duplicate" style="color:var(--accent-purple);"><i class="fas fa-copy"></i></button>
                                <button class="action-icon" onclick="toggleFormStatus(${data.id})" title="Toggle Status" style="color:var(--accent-amber);"><i class="fas fa-power-off"></i></button>
                                <button class="action-icon delete-icon" onclick="deleteForm(${data.id}, '${data.form_name.replace(/'/g, "\\'")}')" title="Delete"><i class="fas fa-trash"></i></button>`;
                    }
                }
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            responsive: true,
            dom: '<"top"lf>rt<"bottom"ip>',
            order: [[0, 'asc']]
        });
    }

    // ============================
    // FORM BUILDER - OPEN/CLOSE
    // ============================
    function openFormBuilder() {
        isEditMode = false;
        fieldCounter = 0;
        document.getElementById('builderTitle').innerHTML = '<i class="fas fa-hammer"></i> Create New Form';
        document.getElementById('editFormId').value = '';
        document.getElementById('formName').value = '';
        document.getElementById('formIcon').value = 'fas fa-list';
        updateFormIconPreview();
        document.getElementById('formDescription').value = '';
        document.getElementById('formLogo').value = '';
        document.getElementById('formLogoPreview').innerHTML = '<i class="fas fa-cloud-upload-alt"></i><span>Upload Logo</span>';
        document.getElementById('formLogoPreview').classList.remove('has-image');
        document.getElementById('formLogoRemoveBtn').style.display = 'none';
        document.getElementById('formLogoInput').value = '';
        initAccessTypeDropdown('both');
        initRoleDropdowns(['Admin'], ['Admin']);
        document.getElementById('isPublic').checked = false;
        document.getElementById('emailOnSubmit').checked = false;
        document.getElementById('emailNotifyAdmin').checked = false;
        document.getElementById('emailConfirmation').checked = false;
        document.getElementById('enablePdfDownload').checked = false;
        document.getElementById('expiresAt').value = '';
        document.getElementById('maxSubmissions').value = '0';
        document.getElementById('formPassword').value = '';
        document.getElementById('onePerUser').checked = false;
        document.getElementById('thankYouMessage').value = '';
        document.getElementById('redirectUrl').value = '';
        document.getElementById('enableMultiStep').checked = false;
        document.getElementById('scheduleStart').value = '';
        document.getElementById('scheduleEnd').value = '';
        document.getElementById('duplicateDetection').checked = false;
        document.getElementById('duplicateFieldsWrapper').style.display = 'none';
        document.getElementById('duplicateFieldsContainer').innerHTML = '';
        formSteps = [];
        document.getElementById('stepManager').classList.remove('active');
        document.getElementById('stepManagerList').innerHTML = '';
        document.getElementById('fieldsContainer').innerHTML = '';
        updateFieldCount();
        initUserDropdowns([], []);

        addField();

        document.getElementById('aiBuilderSection').style.display = 'block';
        aiResetPrompt();
        document.getElementById('aiSectionDesc').textContent = 'Describe the form you need in plain English. AI will generate the complete form structure for you.';
        document.getElementById('aiPromptInput').placeholder = 'e.g. I need a Student Registration Form with name, email, phone, date of birth, gender, course selection, and a photo upload field...';
        document.getElementById('aiRewriteBtn').innerHTML = '<i class="fas fa-magic"></i> Rewrite Prompt';
        document.getElementById('aiBuildBtn').innerHTML = '<i class="fas fa-hammer"></i> Start Build';
        document.getElementById('aiHint').textContent = 'AI will enhance your prompt for better results';

        initSortable();
        document.getElementById('modalFloatingBar').style.display = 'flex';
        document.getElementById('formBuilderModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeFormBuilder() {
        document.getElementById('formBuilderModal').classList.remove('active');
        document.body.style.overflow = '';
        document.getElementById('modalFloatingBar').style.display = 'none';
    }

    // ============================
    // EDIT FORM
    // ============================
    function editForm(id) {
        document.getElementById('aiBuilderSection').style.display = 'block';
        aiResetPrompt();
        document.getElementById('aiSectionDesc').textContent = 'Describe changes you want to make to this form. AI will modify the fields while keeping the existing structure.';
        document.getElementById('aiPromptInput').placeholder = 'e.g. Add a file upload field, make phone required, add a department dropdown with HR/IT/Finance options...';
        document.getElementById('aiRewriteBtn').innerHTML = '<i class="fas fa-magic"></i> Enhance Request';
        document.getElementById('aiBuildBtn').innerHTML = '<i class="fas fa-hammer"></i> Apply Changes';
        document.getElementById('aiHint').textContent = 'AI will refine your modification request';

        isEditMode = true;
        $.ajax({
            url: 'crud_settings.php?action=getForm&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    fieldCounter = 0;
                    const form = response.data;

                    document.getElementById('builderTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Form: ' + form.form_name;
                    document.getElementById('editFormId').value = form.id;
                    document.getElementById('formName').value = form.form_name;
                    document.getElementById('formIcon').value = form.form_icon;
                    updateFormIconPreview();
                    document.getElementById('formDescription').value = form.form_description || '';

                    // Logo
                    if (form.form_logo) {
                        var preview = document.getElementById('formLogoPreview');
                        preview.innerHTML = '<img src="' + form.form_logo + '" alt="Logo">';
                        preview.classList.add('has-image');
                        document.getElementById('formLogo').value = form.form_logo;
                        document.getElementById('formLogoRemoveBtn').style.display = 'block';
                    } else {
                        removeFormLogo();
                    }

                    initAccessTypeDropdown(form.access_type || 'both');
                    const editRoles = JSON.parse(form.can_edit_roles || '["Admin"]');
                    const deleteRoles = JSON.parse(form.can_delete_roles || '["Admin"]');
                    initRoleDropdowns(editRoles, deleteRoles);

                    document.getElementById('isPublic').checked = form.is_public == 1;
                    document.getElementById('emailOnSubmit').checked = form.email_on_submit == 1;
                    document.getElementById('emailNotifyAdmin').checked = form.email_notify_admin == 1;
                    document.getElementById('emailConfirmation').checked = form.email_confirmation == 1;
                    document.getElementById('enablePdfDownload').checked = form.enable_pdf_download == 1;

                    if (form.expires_at) {
                        var dt = form.expires_at.replace(' ', 'T');
                        if (dt.length > 16) dt = dt.substring(0, 16);
                        document.getElementById('expiresAt').value = dt;
                    } else { document.getElementById('expiresAt').value = ''; }
                    document.getElementById('maxSubmissions').value = form.max_submissions || 0;
                    document.getElementById('formPassword').value = form.form_password || '';
                    document.getElementById('onePerUser').checked = form.one_per_user == 1;
                    document.getElementById('thankYouMessage').value = form.thank_you_message || '';
                    document.getElementById('redirectUrl').value = form.redirect_url || '';

                    if (form.schedule_start) {
                        var st = form.schedule_start.replace(' ', 'T');
                        if (st.length > 16) st = st.substring(0, 16);
                        document.getElementById('scheduleStart').value = st;
                    } else { document.getElementById('scheduleStart').value = ''; }
                    if (form.schedule_end) {
                        var en = form.schedule_end.replace(' ', 'T');
                        if (en.length > 16) en = en.substring(0, 16);
                        document.getElementById('scheduleEnd').value = en;
                    } else { document.getElementById('scheduleEnd').value = ''; }

                    document.getElementById('duplicateDetection').checked = form.duplicate_detection == 1;
                    if (form.duplicate_detection == 1) {
                        document.getElementById('duplicateFieldsWrapper').style.display = 'block';
                    } else {
                        document.getElementById('duplicateFieldsWrapper').style.display = 'none';
                    }

                    formSteps = [];
                    if (form.form_steps) {
                        try { formSteps = JSON.parse(form.form_steps); } catch(e) { formSteps = []; }
                    }
                    if (formSteps.length > 0) {
                        document.getElementById('enableMultiStep').checked = true;
                        document.getElementById('stepManager').classList.add('active');
                        renderStepManager();
                    } else {
                        document.getElementById('enableMultiStep').checked = false;
                        document.getElementById('stepManager').classList.remove('active');
                        document.getElementById('stepManagerList').innerHTML = '';
                    }

                    var visibleUsers = [];
                    var editableUsers = [];
                    try { visibleUsers = JSON.parse(form.visible_to_users || '[]'); } catch(e) {}
                    try { editableUsers = JSON.parse(form.editable_by_users || '[]'); } catch(e) {}
                    if (!Array.isArray(visibleUsers)) visibleUsers = [];
                    if (!Array.isArray(editableUsers)) editableUsers = [];
                    initUserDropdowns(visibleUsers, editableUsers);

                    document.getElementById('fieldsContainer').innerHTML = '';
                    const fields = JSON.parse(form.fields_json || '[]');
                    fields.sort((a, b) => (a.order || 0) - (b.order || 0));
                    fields.forEach(function(field) {
                        addField(field);
                    });
                    updateFieldCount();

                    if (form.duplicate_detection == 1) {
                        refreshDuplicateFieldsList();
                        try {
                            var savedDupFields = JSON.parse(form.duplicate_fields || '[]');
                            if (Array.isArray(savedDupFields)) {
                                savedDupFields.forEach(function(fn) {
                                    document.querySelectorAll('.dup-field-cb').forEach(function(cb) {
                                        if (cb.value === fn) cb.checked = true;
                                    });
                                });
                            }
                        } catch(e) {}
                    }

                    initSortable();
                    document.getElementById('modalFloatingBar').style.display = 'flex';
                    document.getElementById('formBuilderModal').classList.add('active');
                    document.body.style.overflow = 'hidden';
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                }
            }
        });
    }

    // ============================
    // SEARCHABLE DROPDOWN HELPERS
    // ============================
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
        wrapper._sdSetValue = function(v) { currentValue = v; close(); };
    }

    function initMultiSearchableDropdown(wrapperId, options, selectedValues, placeholder) {
        var wrapper = document.getElementById(wrapperId);
        if (!wrapper) return;
        wrapper.innerHTML = '';
        wrapper.classList.add('searchable-dropdown', 'sd-multi');

        var currentValues = (selectedValues || []).slice();

        var tagsContainer = document.createElement('div');
        tagsContainer.className = 'sd-tags-container';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'sd-multi-input';
        input.placeholder = placeholder || 'Select...';
        input.autocomplete = 'off';

        var countBadge = document.createElement('span');
        countBadge.className = 'sd-count-badge';
        countBadge.style.display = 'none';

        var arrow = document.createElement('span');
        arrow.className = 'searchable-dropdown-arrow';
        arrow.innerHTML = '<i class="fas fa-chevron-down"></i>';

        var list = document.createElement('div');
        list.className = 'searchable-dropdown-list';
        list.style.display = 'none';

        tagsContainer.appendChild(input);
        wrapper.appendChild(tagsContainer);
        wrapper.appendChild(countBadge);
        wrapper.appendChild(arrow);
        wrapper.appendChild(list);

        var isOpen = false;

        function updateCount() {
            if (currentValues.length > 0) {
                countBadge.textContent = currentValues.length;
                countBadge.style.display = 'inline-flex';
            } else {
                countBadge.style.display = 'none';
            }
        }

        function renderTags() {
            tagsContainer.querySelectorAll('.sd-tag').forEach(function(t) { t.remove(); });
            currentValues.forEach(function(val) {
                var opt = options.find(function(o) { return o.value === val; });
                if (!opt) return;
                var tag = document.createElement('span');
                tag.className = 'sd-tag';
                tag.innerHTML = (opt.icon ? '<i class="' + opt.icon + '" style="font-size:11px;"></i> ' : '')
                    + '<span class="sd-tag-label">' + opt.label + '</span>'
                    + ' <i class="fas fa-times sd-tag-remove" data-val="' + val + '"></i>';
                tagsContainer.insertBefore(tag, input);
            });
            input.placeholder = currentValues.length > 0 ? '' : (placeholder || 'Select...');
            updateCount();
        }

        function renderList(filter) {
            var html = '';
            var allSelected = currentValues.length === options.length;

            if (options.length > 2 && !filter) {
                html += '<div class="sd-list-actions">';
                if (allSelected) {
                    html += '<button type="button" class="sd-action-btn" data-action="clear-all"><i class="fas fa-times-circle"></i> Clear All</button>';
                } else {
                    html += '<button type="button" class="sd-action-btn" data-action="select-all"><i class="fas fa-check-double"></i> Select All</button>';
                }
                html += '</div>';
            }

            var filtered = options.filter(function(o) {
                return !filter || o.label.toLowerCase().indexOf(filter.toLowerCase()) >= 0;
            });
            if (filtered.length === 0) {
                html += '<div class="searchable-dropdown-item no-results">No results found</div>';
            } else {
                filtered.forEach(function(o) {
                    var isSelected = currentValues.indexOf(o.value) >= 0;
                    var checkIcon = isSelected
                        ? '<span class="sd-check sd-checked"><i class="fas fa-check-square"></i></span>'
                        : '<span class="sd-check"><i class="far fa-square"></i></span>';
                    var selClass = isSelected ? ' sd-item-selected' : '';
                    var iconHtml = o.icon ? '<i class="' + o.icon + '" style="width:18px;text-align:center;font-size:13px;color:var(--accent-purple);"></i> ' : '';
                    html += '<div class="searchable-dropdown-item' + selClass + '" data-value="' + o.value + '">'
                        + checkIcon + iconHtml + '<span class="sd-item-label">' + o.label + '</span></div>';
                });
            }
            list.innerHTML = html;
        }

        function open() {
            isOpen = true;
            list.style.display = 'block';
            arrow.classList.add('open');
            tagsContainer.classList.add('sd-focused');
            input.focus();
            renderList('');
        }

        function close() {
            isOpen = false;
            list.style.display = 'none';
            arrow.classList.remove('open');
            tagsContainer.classList.remove('sd-focused');
            input.value = '';
            input.placeholder = currentValues.length > 0 ? '' : (placeholder || 'Select...');
        }

        function toggleValue(val) {
            var idx = currentValues.indexOf(val);
            if (idx >= 0) { currentValues.splice(idx, 1); }
            else { currentValues.push(val); }
            renderTags();
            renderList(input.value);
            if (wrapper._onChange) wrapper._onChange(currentValues);
        }

        function selectAll() { currentValues = options.map(function(o) { return o.value; }); renderTags(); renderList(input.value); if (wrapper._onChange) wrapper._onChange(currentValues); }
        function clearAll() { currentValues = []; renderTags(); renderList(input.value); if (wrapper._onChange) wrapper._onChange(currentValues); }

        tagsContainer.addEventListener('click', function(e) {
            var removeBtn = e.target.closest('.sd-tag-remove');
            if (removeBtn) { e.stopPropagation(); toggleValue(removeBtn.getAttribute('data-val')); return; }
            if (isOpen) close(); else open();
        });

        arrow.addEventListener('click', function(e) {
            e.stopPropagation();
            if (isOpen) close(); else open();
        });

        input.addEventListener('focus', function() { if (!isOpen) open(); });
        input.addEventListener('input', function() { if (!isOpen) open(); renderList(input.value); });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !input.value && currentValues.length > 0) {
                toggleValue(currentValues[currentValues.length - 1]);
            }
        });

        list.addEventListener('click', function(e) {
            var actionBtn = e.target.closest('.sd-action-btn');
            if (actionBtn) {
                e.stopPropagation();
                var action = actionBtn.getAttribute('data-action');
                if (action === 'select-all') selectAll();
                else if (action === 'clear-all') clearAll();
                return;
            }
            var item = e.target.closest('.searchable-dropdown-item');
            if (item && !item.classList.contains('no-results')) {
                e.stopPropagation();
                toggleValue(item.getAttribute('data-value'));
            }
        });

        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target) && isOpen) close();
        });

        renderTags();
        wrapper._sdGetValues = function() { return currentValues.slice(); };
        wrapper._sdSetValues = function(vals) { currentValues = (vals || []).slice(); renderTags(); };
    }

    function initAccessTypeDropdown(selectedValue) {
        initSearchableDropdown('sdAccessType', ACCESS_TYPE_OPTIONS, selectedValue || 'both', 'Select access...');
    }

    function initRoleDropdowns(editRoles, deleteRoles) {
        initMultiSearchableDropdown('sdEditRoles', ROLE_OPTIONS, editRoles || ['Admin'], 'Select roles...');
        initMultiSearchableDropdown('sdDeleteRoles', ROLE_OPTIONS, deleteRoles || ['Admin'], 'Select roles...');
    }

    function initUserDropdowns(visibleUsers, editableUsers) {
        if (cachedUserOptions) {
            initMultiSearchableDropdown('sdVisibleToUsers', cachedUserOptions, (visibleUsers || []).map(String), 'Select users...');
            initMultiSearchableDropdown('sdEditableByUsers', cachedUserOptions, (editableUsers || []).map(String), 'Select users...');
            return;
        }
        $.ajax({
            url: 'crud_settings.php?action=getUsers',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    cachedUserOptions = response.data.map(function(u) {
                        var roleIcon = u.role === 'Admin' ? 'fas fa-user-shield' : 'fas fa-user';
                        return { value: String(u.id), label: u.username + ' (' + u.role + ')', icon: roleIcon };
                    });
                    initMultiSearchableDropdown('sdVisibleToUsers', cachedUserOptions, (visibleUsers || []).map(String), 'Select users...');
                    initMultiSearchableDropdown('sdEditableByUsers', cachedUserOptions, (editableUsers || []).map(String), 'Select users...');
                }
            }
        });
    }

    // ============================
    // FORM LOGO & ICON HELPERS
    // ============================
    function updateFormIconPreview() {
        var val = document.getElementById('formIcon').value || 'fas fa-list';
        document.getElementById('formIconPreview').innerHTML = '<i class="' + val + '"></i>';
    }

    function previewFormLogo(input) {
        var preview = document.getElementById('formLogoPreview');
        var removeBtn = document.getElementById('formLogoRemoveBtn');
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Logo">';
                preview.classList.add('has-image');
                document.getElementById('formLogo').value = e.target.result;
                removeBtn.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function removeFormLogo() {
        var preview = document.getElementById('formLogoPreview');
        preview.innerHTML = '<i class="fas fa-cloud-upload-alt"></i><span>Upload Logo</span>';
        preview.classList.remove('has-image');
        document.getElementById('formLogo').value = '';
        document.getElementById('formLogoInput').value = '';
        document.getElementById('formLogoRemoveBtn').style.display = 'none';
    }

    // ============================
    // FIELD MANAGEMENT
    // ============================
    function updateFieldCount() {
        var count = document.querySelectorAll('.field-card').length;
        document.getElementById('fieldCount').textContent = count;
    }

    function initSortable() {
        var container = document.getElementById('fieldsContainer');
        if (sortable) sortable.destroy();
        sortable = new Sortable(container, {
            handle: '.field-drag-handle',
            animation: 150,
            ghostClass: 'field-card-ghost',
        });
    }

    function addField(fieldData) {
        fieldCounter++;
        var idx = fieldCounter;
        var f = fieldData || {};
        var fieldType = f.type || 'text';

        var needsOptions = ['select', 'radio', 'checkbox'].indexOf(fieldType) >= 0;
        var needsFile = fieldType === 'file';
        var needsValidation = ['text', 'number', 'range', 'date', 'datetime', 'time', 'tel'].indexOf(fieldType) >= 0;

        var html = '<div class="field-card" data-field-idx="' + idx + '" id="fieldCard_' + idx + '">'
            + '<div class="field-card-header">'
            + '  <div class="field-drag-handle"><i class="fas fa-grip-vertical"></i></div>'
            + '  <span class="field-number">#' + idx + '</span>'
            + '  <span class="field-label-preview" id="labelPreview_' + idx + '">' + (f.label || 'New Field') + '</span>'
            + '  <span class="field-type-badge" id="typeBadge_' + idx + '">' + fieldType + '</span>'
            + '  <button type="button" class="field-remove-btn" onclick="removeField(' + idx + ')" title="Remove field"><i class="fas fa-times"></i></button>'
            + '</div>'
            + '<div class="field-card-body">'
            + '  <div class="form-grid-3col">'
            + '    <div class="form-group">'
            + '      <label><i class="fas fa-tag"></i> Label *</label>'
            + '      <input type="text" id="fLabel_' + idx + '" value="' + (f.label || '').replace(/"/g, '&quot;') + '" placeholder="e.g. Full Name" required oninput="autoFieldName(' + idx + ');updateLabelPreview(' + idx + ')">'
            + '    </div>'
            + '    <div class="form-group">'
            + '      <label><i class="fas fa-code"></i> Field Name</label>'
            + '      <input type="text" id="fName_' + idx + '" value="' + (f.name || '') + '" placeholder="auto_generated" style="font-family:monospace;font-size:14px;">'
            + '    </div>'
            + '    <div class="form-group">'
            + '      <label><i class="fas fa-cube"></i> Type *</label>'
            + '      <div id="typeDropdown_' + idx + '" data-hidden-input="fType_' + idx + '"></div>'
            + '      <input type="hidden" id="fType_' + idx + '" value="' + fieldType + '">'
            + '    </div>'
            + '  </div>'
            + '  <div class="form-grid-3col" style="margin-top:8px;">'
            + '    <div class="form-group">'
            + '      <label><i class="fas fa-icons"></i> Icon</label>'
            + '      <div class="icon-preview-wrapper">'
            + '        <input type="text" id="fIcon_' + idx + '" value="' + (f.icon || 'fas fa-font') + '" placeholder="fas fa-font" oninput="updateFieldIconPreview(' + idx + ')">'
            + '        <div class="icon-preview-box" id="fieldIconPreview_' + idx + '"><i class="' + (f.icon || 'fas fa-font') + '"></i></div>'
            + '      </div>'
            + '    </div>'
            + '    <div class="form-group">'
            + '      <label><i class="fas fa-i-cursor"></i> Placeholder</label>'
            + '      <input type="text" id="fPlaceholder_' + idx + '" value="' + (f.placeholder || '').replace(/"/g, '&quot;') + '" placeholder="Hint text shown in field">'
            + '    </div>'
            + '    <div class="form-group">'
            + '      <label><i class="fas fa-arrows-alt-h"></i> Width</label>'
            + '      <select id="fWidth_' + idx + '">'
            + '        <option value="half"' + (f.width === 'half' || !f.width ? ' selected' : '') + '>Half (50%)</option>'
            + '        <option value="full"' + (f.width === 'full' ? ' selected' : '') + '>Full (100%)</option>'
            + '      </select>'
            + '    </div>'
            + '  </div>'
            + '  <div style="margin-top:8px;padding:8px 0;border-top:1px solid var(--border-color);">'
            + '    <div class="checkbox-group" style="flex-wrap:wrap;gap:10px;display:flex;align-items:center;">'
            + '      <label class="checkbox-label" style="font-size:13px;"><input type="checkbox" id="fRequired_' + idx + '"' + (f.required ? ' checked' : '') + '> Required</label>'
            + '      <label class="checkbox-label" style="font-size:13px;"><input type="checkbox" id="fShowTable_' + idx + '"' + (f.show_in_table !== false ? ' checked' : '') + '> Show in Table</label>'
            + '      <div id="stepDropdownWrap_' + idx + '" style="display:none;margin-left:auto;">'
            + '        <label style="font-size:12px;font-weight:600;margin-right:5px;">Step:</label>'
            + '        <select id="fFieldStep_' + idx + '" style="padding:4px 8px;font-size:12px;border-radius:4px;border:1px solid var(--border-color);min-width:80px;">'
            + '          <option value="1">Step 1</option>'
            + '        </select>'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            // Reference Image
            + '  <div style="margin-top:8px;padding:8px 12px;background:var(--bg-secondary);border-radius:8px;">'
            + '    <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px;"><i class="fas fa-image"></i> Reference Image <small style="font-weight:400;">(optional)</small></label>'
            + '    <div style="display:flex;align-items:center;gap:10px;">'
            + '      <input type="file" id="refImgFile_' + idx + '" accept="image/*" style="display:none;" onchange="uploadRefImage(' + idx + ')">'
            + '      <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById(\'refImgFile_' + idx + '\').click()" id="refImgUploadBtn_' + idx + '" style="font-size:12px;padding:4px 12px;' + (f.reference_image ? 'display:none;' : '') + '">'
            + '        <i class="fas fa-upload"></i> Upload'
            + '      </button>'
            + '      <div id="refImgPreview_' + idx + '" style="display:' + (f.reference_image ? 'flex' : 'none') + ';align-items:center;gap:8px;">'
            + '        <img id="refImgThumb_' + idx + '" src="' + (f.reference_image || '') + '" style="max-width:100px;max-height:50px;border-radius:4px;border:1px solid var(--border-color);object-fit:cover;cursor:pointer;" onclick="viewRefImage(' + idx + ')">'
            + '        <button type="button" class="btn btn-sm btn-danger" onclick="removeRefImage(' + idx + ')" style="font-size:10px;padding:2px 8px;"><i class="fas fa-trash"></i></button>'
            + '      </div>'
            + '      <input type="hidden" id="fRefImage_' + idx + '" value="' + (f.reference_image || '') + '">'
            + '    </div>'
            + '  </div>'
            // Options
            + '  <div id="optionsSection_' + idx + '" style="display:' + (needsOptions ? 'block' : 'none') + ';margin-top:8px;">'
            + '    <div class="form-group">'
            + '      <label><i class="fas fa-list-ol"></i> Options <small style="font-weight:400;color:var(--text-muted);">(type and press Enter)</small></label>'
            + '      <div class="opt-tags-wrapper" id="optTagsWrapper_' + idx + '">'
            + '        <div class="opt-tags-container" id="optTags_' + idx + '"></div>'
            + '        <input type="text" class="opt-tags-input" id="optInput_' + idx + '" placeholder="Type option and press Enter..." onkeydown="handleOptTagKey(event,' + idx + ')">'
            + '      </div>'
            + '      <input type="hidden" id="fOptions_' + idx + '">'
            + '      <div style="display:flex;gap:6px;margin-top:4px;">'
            + '        <button type="button" class="btn btn-sm btn-ghost" onclick="bulkAddOptions(' + idx + ')" style="font-size:11px;padding:2px 10px;"><i class="fas fa-paste"></i> Bulk Add</button>'
            + '        <button type="button" class="btn btn-sm btn-ghost" onclick="clearAllOptions(' + idx + ')" style="font-size:11px;padding:2px 10px;color:var(--accent-red);"><i class="fas fa-trash"></i> Clear</button>'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            // File
            + '  <div id="fileSection_' + idx + '" style="display:' + (needsFile ? 'block' : 'none') + ';margin-top:8px;">'
            + '    <div class="form-grid">'
            + '      <div class="form-group"><label>Accept Types</label><input type="text" id="fAccept_' + idx + '" value="' + (f.accept || 'image/*') + '" placeholder="image/*,.pdf,.doc"></div>'
            + '      <div class="form-group"><label>Max Size (MB)</label><input type="number" id="fMaxSize_' + idx + '" value="' + (f.max_size || 2) + '" min="1" max="50"></div>'
            + '    </div>'
            + '  </div>'
            // Appointment
            + '  <div id="appointmentSection_' + idx + '" style="display:' + (fieldType === 'appointment' ? 'block' : 'none') + ';margin-top:8px;">'
            + '    <div class="form-grid">'
            + '      <div class="form-group"><label>Slot Duration</label><select id="fApptDuration_' + idx + '">'
            + '        <option value="15"' + ((f.appointment_config && f.appointment_config.slot_duration == 15) ? ' selected' : '') + '>15 min</option>'
            + '        <option value="30"' + ((!f.appointment_config || f.appointment_config.slot_duration == 30) ? ' selected' : '') + '>30 min</option>'
            + '        <option value="60"' + ((f.appointment_config && f.appointment_config.slot_duration == 60) ? ' selected' : '') + '>1 hour</option>'
            + '        <option value="90"' + ((f.appointment_config && f.appointment_config.slot_duration == 90) ? ' selected' : '') + '>1.5 hours</option>'
            + '        <option value="120"' + ((f.appointment_config && f.appointment_config.slot_duration == 120) ? ' selected' : '') + '>2 hours</option>'
            + '      </select></div>'
            + '      <div class="form-group"><label>Start Time</label><input type="time" id="fApptStart_' + idx + '" value="' + (f.appointment_config ? f.appointment_config.start_time : '09:00') + '"></div>'
            + '      <div class="form-group"><label>End Time</label><input type="time" id="fApptEnd_' + idx + '" value="' + (f.appointment_config ? f.appointment_config.end_time : '17:00') + '"></div>'
            + '      <div class="form-group"><label>Break Start</label><input type="time" id="fApptBreakStart_' + idx + '" value="' + (f.appointment_config ? (f.appointment_config.break_start || '') : '12:00') + '"></div>'
            + '      <div class="form-group"><label>Break End</label><input type="time" id="fApptBreakEnd_' + idx + '" value="' + (f.appointment_config ? (f.appointment_config.break_end || '') : '13:00') + '"></div>'
            + '      <div class="form-group"><label>Max Bookings/Slot</label><input type="number" id="fApptMaxBookings_' + idx + '" value="' + (f.appointment_config ? (f.appointment_config.max_bookings || 1) : 1) + '" min="1"></div>'
            + '    </div>'
            + '    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">'
            + '      <div class="form-group" style="flex:1;"><label>Advance Days</label><input type="number" id="fApptAdvanceDays_' + idx + '" value="' + (f.appointment_config ? (f.appointment_config.advance_days || 30) : 30) + '" min="1"></div>'
            + '      <div class="form-group" style="flex:1;"><label>Days Off</label>'
            + '        <div style="display:flex;flex-wrap:wrap;gap:4px;">'
            + '          <label style="font-size:11px;"><input type="checkbox" class="apptDayOff_' + idx + '" value="0"' + (f.appointment_config && f.appointment_config.days_off && f.appointment_config.days_off.indexOf(0) >= 0 ? ' checked' : '') + '> Sun</label>'
            + '          <label style="font-size:11px;"><input type="checkbox" class="apptDayOff_' + idx + '" value="1"' + (f.appointment_config && f.appointment_config.days_off && f.appointment_config.days_off.indexOf(1) >= 0 ? ' checked' : '') + '> Mon</label>'
            + '          <label style="font-size:11px;"><input type="checkbox" class="apptDayOff_' + idx + '" value="2"' + (f.appointment_config && f.appointment_config.days_off && f.appointment_config.days_off.indexOf(2) >= 0 ? ' checked' : '') + '> Tue</label>'
            + '          <label style="font-size:11px;"><input type="checkbox" class="apptDayOff_' + idx + '" value="3"' + (f.appointment_config && f.appointment_config.days_off && f.appointment_config.days_off.indexOf(3) >= 0 ? ' checked' : '') + '> Wed</label>'
            + '          <label style="font-size:11px;"><input type="checkbox" class="apptDayOff_' + idx + '" value="4"' + (f.appointment_config && f.appointment_config.days_off && f.appointment_config.days_off.indexOf(4) >= 0 ? ' checked' : '') + '> Thu</label>'
            + '          <label style="font-size:11px;"><input type="checkbox" class="apptDayOff_' + idx + '" value="5"' + (f.appointment_config && f.appointment_config.days_off && f.appointment_config.days_off.indexOf(5) >= 0 ? ' checked' : '') + '> Fri</label>'
            + '          <label style="font-size:11px;"><input type="checkbox" class="apptDayOff_' + idx + '" value="6"' + (f.appointment_config && f.appointment_config.days_off && f.appointment_config.days_off.indexOf(6) >= 0 ? ' checked' : '') + '> Sat</label>'
            + '        </div>'
            + '      </div>'
            + '    </div>'
            + '    <div class="form-group" style="margin-top:4px;"><label>Holidays (comma-separated)</label><input type="text" id="fApptHolidays_' + idx + '" value="' + (f.appointment_config && f.appointment_config.holidays ? f.appointment_config.holidays.join(', ') : '') + '" placeholder="2026-01-01, 2026-03-23"></div>'
            + '  </div>'
            // Validation
            + '  <div id="validationSection_' + idx + '" style="display:' + (needsValidation ? 'block' : 'none') + ';margin-top:8px;">'
            + '    <label style="font-weight:600;font-size:13px;color:var(--text-secondary);display:block;margin-bottom:4px;"><i class="fas fa-shield-alt"></i> Validation</label>'
            + '    <div class="form-grid">'
            + '      <div class="form-group"><label id="fMinLabel_' + idx + '">Min</label><input type="text" id="fMin_' + idx + '" value="' + ((f.validation && f.validation.min != null) ? f.validation.min : '') + '"></div>'
            + '      <div class="form-group"><label id="fMaxLabel_' + idx + '">Max</label><input type="text" id="fMax_' + idx + '" value="' + ((f.validation && f.validation.max != null) ? f.validation.max : '') + '"></div>'
            + '      <div class="form-group" id="fStepGroup_' + idx + '"><label>Step</label><input type="text" id="fStep_' + idx + '" value="' + ((f.validation && f.validation.step != null) ? f.validation.step : '') + '"></div>'
            + '    </div>'
            + '  </div>'
            // Chart
            + '  <div id="chartSection_' + idx + '" style="display:' + (CHART_COMPATIBLE_TYPES[fieldType] ? 'block' : 'none') + ';margin-top:8px;">'
            + '    <div style="display:flex;align-items:center;margin-bottom:6px;">'
            + '      <label style="font-weight:600;color:var(--text-secondary);font-size:13px;"><i class="fas fa-chart-pie"></i> Chart Visualization</label>'
            + '    </div>'
            + '    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
            + '      <label style="font-size:13px;"><input type="checkbox" id="fChartEnabled_' + idx + '"' + (f.chart_enabled ? ' checked' : '') + ' onchange="toggleChartOptions(' + idx + ')"> Enable Chart</label>'
            + '      <div id="chartOptionsPanel_' + idx + '" style="display:' + (f.chart_enabled ? 'flex' : 'none') + ';gap:8px;flex-wrap:wrap;">'
            + '        <select id="fChartType_' + idx + '" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);font-size:12px;">' + buildChartTypeOptions(fieldType, f.chart_type) + '</select>'
            + '        <input type="text" id="fChartLabel_' + idx + '" value="' + (f.chart_label || '').replace(/"/g, '&quot;') + '" placeholder="Chart label" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);font-size:12px;width:150px;">'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            // Conditional Logic
            + '  <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border-color);">'
            + '    <label class="checkbox-label" style="font-size:13px;"><input type="checkbox" id="fConditionEnabled_' + idx + '"' + (f.condition ? ' checked' : '') + ' onchange="toggleCondition(' + idx + ')"> <i class="fas fa-eye" style="color:var(--accent-purple);"></i> Conditional Logic</label>'
            + '    <div id="conditionConfig_' + idx + '" class="condition-config' + (f.condition ? ' active' : '') + '">'
            + '      <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:8px;">'
            + '        <div class="form-group" style="margin-bottom:0;"><label style="font-size:12px;">When Field</label><select id="fCondField_' + idx + '"><option value="">-- Select --</option></select></div>'
            + '        <div class="form-group" style="margin-bottom:0;"><label style="font-size:12px;">Operator</label><select id="fCondOp_' + idx + '">'
            + '          <option value="equals"' + (f.condition && f.condition.operator === 'equals' ? ' selected' : '') + '>Equals</option>'
            + '          <option value="not_equals"' + (f.condition && f.condition.operator === 'not_equals' ? ' selected' : '') + '>Not Equals</option>'
            + '          <option value="contains"' + (f.condition && f.condition.operator === 'contains' ? ' selected' : '') + '>Contains</option>'
            + '          <option value="not_empty"' + (f.condition && f.condition.operator === 'not_empty' ? ' selected' : '') + '>Not Empty</option>'
            + '          <option value="empty"' + (f.condition && f.condition.operator === 'empty' ? ' selected' : '') + '>Is Empty</option>'
            + '          <option value="greater_than"' + (f.condition && f.condition.operator === 'greater_than' ? ' selected' : '') + '>Greater Than</option>'
            + '          <option value="less_than"' + (f.condition && f.condition.operator === 'less_than' ? ' selected' : '') + '>Less Than</option>'
            + '        </select></div>'
            + '        <div class="form-group" style="margin-bottom:0;" id="condValueWrap_' + idx + '"><label style="font-size:12px;">Value</label><input type="text" id="fCondValue_' + idx + '" value="' + (f.condition && f.condition.value ? f.condition.value.replace(/"/g, '&quot;') : '') + '" placeholder="Value"></div>'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            // Advanced Validation
            + '  <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border-color);">'
            + '    <label style="font-weight:600;color:var(--text-secondary);font-size:13px;display:block;margin-bottom:4px;"><i class="fas fa-cog"></i> Advanced Validation</label>'
            + '    <div class="form-grid" style="grid-template-columns:auto 1fr 1fr;gap:8px;">'
            + '      <div class="form-group" style="margin-bottom:0;min-width:100px;"><label class="checkbox-label" style="font-size:12px;"><input type="checkbox" id="fUnique_' + idx + '"' + (f.unique ? ' checked' : '') + '> Unique</label></div>'
            + '      <div class="form-group" style="margin-bottom:0;"><label style="font-size:12px;">Regex</label><input type="text" id="fRegex_' + idx + '" value="' + (f.regex || '').replace(/"/g, '&quot;') + '" placeholder="^[A-Z]{3}-[0-9]+$" style="font-family:monospace;font-size:12px;" oninput="updateRegexTester(' + idx + ')"></div>'
            + '      <div class="form-group" style="margin-bottom:0;"><label style="font-size:12px;">Custom Error</label><input type="text" id="fCustomError_' + idx + '" value="' + (f.custom_error || '').replace(/"/g, '&quot;') + '" placeholder="Custom error message" style="font-size:12px;"></div>'
            + '    </div>'
            + '    <div class="regex-presets" style="margin-top:4px;">'
            + '      <button type="button" class="regex-preset-btn" onclick="setRegexPreset(' + idx + ',\'letters\')">Aa</button>'
            + '      <button type="button" class="regex-preset-btn" onclick="setRegexPreset(' + idx + ',\'numbers\')">123</button>'
            + '      <button type="button" class="regex-preset-btn" onclick="setRegexPreset(' + idx + ',\'alphanum\')">A1</button>'
            + '      <button type="button" class="regex-preset-btn" onclick="setRegexPreset(' + idx + ',\'phone\')"><i class="fas fa-phone"></i></button>'
            + '      <button type="button" class="regex-preset-btn" onclick="setRegexPreset(' + idx + ',\'zip\')">ZIP</button>'
            + '      <button type="button" class="regex-preset-btn" onclick="setRegexPreset(' + idx + ',\'id_code\')">ID</button>'
            + '    </div>'
            + '    <div id="regexTester_' + idx + '" class="regex-tester-box"' + (f.regex ? '' : ' style="display:none;"') + '>'
            + '      <div class="regex-tester-row">'
            + '        <i class="fas fa-flask"></i>'
            + '        <span class="regex-tester-label">Test:</span>'
            + '        <input type="text" id="regexTestInput_' + idx + '" placeholder="Type to test..." oninput="testRegexLive(' + idx + ')" class="regex-test-input">'
            + '        <span id="regexResult_' + idx + '" class="regex-result-badge"></span>'
            + '      </div>'
            + '    </div>'
            // Dependent dropdown
            + '    <div id="dependentSection_' + idx + '" style="display:' + (['select', 'radio', 'checkbox'].indexOf(fieldType) >= 0 ? 'block' : 'none') + ';margin-top:8px;">'
            + '      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><i class="fas fa-sitemap"></i> Dependent Options</label>'
            + '      <div class="form-group" style="margin-bottom:4px;"><label style="font-size:12px;">Parent Field</label><select id="fDependentOn_' + idx + '" onchange="onDependentParentChange(' + idx + ')"><option value="">-- None --</option></select></div>'
            + '      <div id="depMapBuilder_' + idx + '" class="dep-map-builder" style="display:none;"></div>'
            + '      <textarea id="fDependentOptions_' + idx + '" style="display:none;">' + (f.dependent_options ? JSON.stringify(f.dependent_options) : '') + '</textarea>'
            + '    </div>'
            + '  </div>'
            + '</div></div>';

        document.getElementById('fieldsContainer').insertAdjacentHTML('beforeend', html);

        var typeOptions = FIELD_TYPES.map(function(t) { return { value: t.value, label: t.label, icon: t.icon }; });
        initSearchableDropdown('typeDropdown_' + idx, typeOptions, fieldType, 'Search field type...');
        var typeWrapper = document.getElementById('typeDropdown_' + idx);
        if (typeWrapper) {
            typeWrapper._onChange = function(val) { onFieldTypeChange(idx); };
        }

        if (document.getElementById('enableMultiStep').checked) {
            document.getElementById('stepDropdownWrap_' + idx).style.display = 'inline-flex';
            updateStepDropdownForField(idx, f.step || 1);
        }

        updateValidationLabels(idx);
        populateConditionFields(idx, f.condition ? f.condition.field : '');
        populateDependentParentFields(idx, f.dependent_on || '');

        if (f.dependent_on) {
            setTimeout(function() { onDependentParentChange(idx); }, 100);
        }

        var condOp = document.getElementById('fCondOp_' + idx);
        if (condOp) {
            condOp.addEventListener('change', function() {
                var op = this.value;
                document.getElementById('condValueWrap_' + idx).style.display = (op === 'empty' || op === 'not_empty') ? 'none' : 'block';
            });
            if (f.condition && (f.condition.operator === 'empty' || f.condition.operator === 'not_empty')) {
                document.getElementById('condValueWrap_' + idx).style.display = 'none';
            }
        }

        if (['select', 'radio', 'checkbox'].indexOf(fieldType) >= 0 && f.options && f.options.length > 0) {
            initOptionTags(idx, f.options);
        }

        updateFieldCount();
    }

    function removeField(idx) {
        var el = document.getElementById('fieldCard_' + idx);
        if (el) { el.remove(); updateFieldCount(); }
    }

    function autoFieldName(idx) {
        var label = document.getElementById('fLabel_' + idx).value;
        var nameField = document.getElementById('fName_' + idx);
        if (!nameField.value || nameField.dataset.autoGenerated === 'true') {
            nameField.value = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
            nameField.dataset.autoGenerated = 'true';
        }
    }

    function updateLabelPreview(idx) {
        var val = document.getElementById('fLabel_' + idx).value || 'New Field';
        document.getElementById('labelPreview_' + idx).textContent = val;
    }

    function updateFieldIconPreview(idx) {
        var val = document.getElementById('fIcon_' + idx).value || 'fas fa-font';
        document.getElementById('fieldIconPreview_' + idx).innerHTML = '<i class="' + val + '"></i>';
    }

    function onFieldTypeChange(idx) {
        var type = document.getElementById('fType_' + idx).value;
        document.getElementById('typeBadge_' + idx).textContent = type;
        document.getElementById('optionsSection_' + idx).style.display = ['select', 'radio', 'checkbox'].indexOf(type) >= 0 ? 'block' : 'none';
        document.getElementById('fileSection_' + idx).style.display = type === 'file' ? 'block' : 'none';
        document.getElementById('validationSection_' + idx).style.display = ['text', 'number', 'range', 'date', 'datetime', 'time', 'tel'].indexOf(type) >= 0 ? 'block' : 'none';
        var apptSection = document.getElementById('appointmentSection_' + idx);
        if (apptSection) apptSection.style.display = type === 'appointment' ? 'block' : 'none';
        updateValidationLabels(idx);

        var chartSection = document.getElementById('chartSection_' + idx);
        if (chartSection) {
            chartSection.style.display = CHART_COMPATIBLE_TYPES[type] ? 'block' : 'none';
            if (!CHART_COMPATIBLE_TYPES[type]) {
                var cb = document.getElementById('fChartEnabled_' + idx);
                if (cb) { cb.checked = false; toggleChartOptions(idx); }
            }
            updateChartTypeDropdown(idx);
        }

        var depSection = document.getElementById('dependentSection_' + idx);
        if (depSection) {
            depSection.style.display = ['select', 'radio', 'checkbox'].indexOf(type) >= 0 ? 'block' : 'none';
        }
    }

    function updateValidationLabels(idx) {
        var type = document.getElementById('fType_' + idx).value;
        var minLabel = document.getElementById('fMinLabel_' + idx);
        var maxLabel = document.getElementById('fMaxLabel_' + idx);
        var stepGroup = document.getElementById('fStepGroup_' + idx);
        var minInput = document.getElementById('fMin_' + idx);
        var maxInput = document.getElementById('fMax_' + idx);
        if (!minLabel || !maxLabel) return;

        switch(type) {
            case 'number': case 'range':
                minLabel.textContent = 'Min Value'; maxLabel.textContent = 'Max Value';
                if (minInput) { minInput.type = 'number'; minInput.placeholder = 'e.g. 0'; }
                if (maxInput) { maxInput.type = 'number'; maxInput.placeholder = 'e.g. 100'; }
                if (stepGroup) stepGroup.style.display = 'block';
                break;
            case 'text': case 'tel': case 'password':
                minLabel.textContent = 'Min Length'; maxLabel.textContent = 'Max Length';
                if (minInput) { minInput.type = 'number'; minInput.placeholder = 'e.g. 2'; }
                if (maxInput) { maxInput.type = 'number'; maxInput.placeholder = 'e.g. 100'; }
                if (stepGroup) stepGroup.style.display = 'none';
                break;
            case 'date': case 'datetime':
                minLabel.textContent = 'Min Date'; maxLabel.textContent = 'Max Date';
                if (minInput) { minInput.type = (type === 'datetime') ? 'datetime-local' : 'date'; minInput.placeholder = ''; }
                if (maxInput) { maxInput.type = (type === 'datetime') ? 'datetime-local' : 'date'; maxInput.placeholder = ''; }
                if (stepGroup) stepGroup.style.display = 'none';
                break;
            case 'time':
                minLabel.textContent = 'Min Time'; maxLabel.textContent = 'Max Time';
                if (minInput) { minInput.type = 'time'; minInput.placeholder = ''; }
                if (maxInput) { maxInput.type = 'time'; maxInput.placeholder = ''; }
                if (stepGroup) stepGroup.style.display = 'none';
                break;
            default:
                minLabel.textContent = 'Min'; maxLabel.textContent = 'Max';
                if (minInput) { minInput.type = 'text'; minInput.placeholder = ''; }
                if (maxInput) { maxInput.type = 'text'; maxInput.placeholder = ''; }
                if (stepGroup) stepGroup.style.display = 'block';
        }
    }

    function buildChartTypeOptions(fieldType, selectedType) {
        var types = CHART_COMPATIBLE_TYPES[fieldType] || [];
        var html = '';
        types.forEach(function(t) {
            var info = CHART_TYPE_LABELS[t];
            var sel = (t === selectedType) ? ' selected' : '';
            html += '<option value="' + t + '"' + sel + '>' + info.label + '</option>';
        });
        return html || '<option value="">N/A</option>';
    }

    function toggleChartOptions(idx) {
        var enabled = document.getElementById('fChartEnabled_' + idx).checked;
        document.getElementById('chartOptionsPanel_' + idx).style.display = enabled ? 'flex' : 'none';
    }

    function updateChartTypeDropdown(idx) {
        var type = document.getElementById('fType_' + idx).value;
        var select = document.getElementById('fChartType_' + idx);
        if (select) select.innerHTML = buildChartTypeOptions(type, '');
    }

    // ============================
    // OPTIONS TAGS
    // ============================
    function initOptionTags(idx, options) {
        var container = document.getElementById('optTags_' + idx);
        var hidden = document.getElementById('fOptions_' + idx);
        if (!container) return;
        container.innerHTML = '';
        (options || []).forEach(function(opt) {
            if (opt.trim()) addOptTag(idx, opt.trim());
        });
        syncOptHidden(idx);
    }

    function addOptTag(idx, value) {
        var container = document.getElementById('optTags_' + idx);
        if (!container) return;
        var existing = container.querySelectorAll('.opt-tag');
        for (var i = 0; i < existing.length; i++) {
            if (existing[i].getAttribute('data-value') === value) return;
        }
        var tag = document.createElement('span');
        tag.className = 'opt-tag';
        tag.setAttribute('data-value', value);
        tag.innerHTML = '<span class="opt-tag-label">' + value.replace(/</g, '&lt;') + '</span><i class="fas fa-times opt-tag-remove" onclick="removeOptTag(this,' + idx + ')"></i>';
        container.appendChild(tag);
        syncOptHidden(idx);
    }

    function removeOptTag(el, idx) {
        el.parentElement.remove();
        syncOptHidden(idx);
    }

    function syncOptHidden(idx) {
        var container = document.getElementById('optTags_' + idx);
        var hidden = document.getElementById('fOptions_' + idx);
        if (!container || !hidden) return;
        var tags = container.querySelectorAll('.opt-tag');
        var values = [];
        tags.forEach(function(t) { values.push(t.getAttribute('data-value')); });
        hidden.value = values.join('\n');
    }

    function handleOptTagKey(e, idx) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            var input = document.getElementById('optInput_' + idx);
            var val = input.value.trim();
            if (val) {
                val.split(',').forEach(function(v) {
                    v = v.trim();
                    if (v) addOptTag(idx, v);
                });
                input.value = '';
                syncOptHidden(idx);
            }
        }
    }

    function bulkAddOptions(idx) {
        Swal.fire({
            title: 'Bulk Add Options',
            input: 'textarea',
            inputLabel: 'Paste options (one per line)',
            inputPlaceholder: 'Option 1\nOption 2\nOption 3',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-plus"></i> Add All',
            confirmButtonColor: 'var(--accent-blue)',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)',
            inputAttributes: { style: 'min-height:150px;font-size:14px;' }
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                result.value.split('\n').forEach(function(line) {
                    var v = line.trim();
                    if (v) addOptTag(idx, v);
                });
                syncOptHidden(idx);
            }
        });
    }

    function clearAllOptions(idx) {
        var container = document.getElementById('optTags_' + idx);
        if (!container) return;
        if (container.querySelectorAll('.opt-tag').length === 0) return;
        Swal.fire({
            title: 'Clear All Options?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, clear',
            confirmButtonColor: '#ea4335',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then(function(result) {
            if (result.isConfirmed) { container.innerHTML = ''; syncOptHidden(idx); }
        });
    }

    // ============================
    // REFERENCE IMAGE
    // ============================
    function uploadRefImage(idx) {
        var fileInput = document.getElementById('refImgFile_' + idx);
        var file = fileInput.files[0];
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            Swal.fire({ icon: 'warning', title: 'Invalid File', text: 'Please select an image file.' });
            fileInput.value = ''; return;
        }
        if (file.size > 5 * 1024 * 1024) {
            Swal.fire({ icon: 'warning', title: 'Too Large', text: 'Image must be under 5MB.' });
            fileInput.value = ''; return;
        }

        var uploadBtn = document.getElementById('refImgUploadBtn_' + idx);
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        uploadBtn.disabled = true;

        var fd = new FormData();
        fd.append('action', 'uploadRefImage');
        fd.append('image', file);

        $.ajax({
            url: 'crud_settings.php',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                uploadBtn.disabled = false;
                if (response.success) {
                    document.getElementById('fRefImage_' + idx).value = response.url;
                    document.getElementById('refImgThumb_' + idx).src = response.url;
                    document.getElementById('refImgPreview_' + idx).style.display = 'flex';
                    uploadBtn.style.display = 'none';
                } else {
                    Swal.fire({ icon: 'error', title: 'Upload Failed', text: response.message });
                }
            },
            error: function() {
                uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                uploadBtn.disabled = false;
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to upload image.' });
            }
        });
        fileInput.value = '';
    }

    function removeRefImage(idx) {
        document.getElementById('fRefImage_' + idx).value = '';
        document.getElementById('refImgPreview_' + idx).style.display = 'none';
        document.getElementById('refImgUploadBtn_' + idx).style.display = '';
    }

    function viewRefImage(idx) {
        var src = document.getElementById('refImgThumb_' + idx).src;
        if (!src) return;
        Swal.fire({ imageUrl: src, imageAlt: 'Reference Image', showConfirmButton: true, confirmButtonText: 'Close', confirmButtonColor: '#001f3f', width: 'auto' });
    }

    // ============================
    // QUICK ADD
    // ============================
    function quickAddField(type) {
        var defaults = {
            text: { label: '', type: 'text', icon: 'fas fa-font', placeholder: '' },
            email: { label: 'Email', type: 'email', icon: 'fas fa-envelope', placeholder: 'name@example.com', name: 'email', required: true },
            number: { label: '', type: 'number', icon: 'fas fa-hashtag', placeholder: '', validation: { min: 0 } },
            date: { label: '', type: 'date', icon: 'fas fa-calendar', placeholder: '' },
            datetime: { label: '', type: 'datetime', icon: 'fas fa-clock', placeholder: '' },
            time: { label: '', type: 'time', icon: 'fas fa-hourglass', placeholder: '' },
            tel: { label: 'Phone', type: 'tel', icon: 'fas fa-phone', placeholder: '+1234567890', name: 'phone', validation: { min: 10, max: 15 } },
            url: { label: 'Website', type: 'url', icon: 'fas fa-link', placeholder: 'https://example.com', name: 'website' },
            textarea: { label: '', type: 'textarea', icon: 'fas fa-align-left', placeholder: '', width: 'full' },
            select: { label: '', type: 'select', icon: 'fas fa-list', options: ['Option 1', 'Option 2', 'Option 3'] },
            radio: { label: '', type: 'radio', icon: 'fas fa-dot-circle', options: ['Yes', 'No'] },
            checkbox: { label: '', type: 'checkbox', icon: 'fas fa-check-square', options: ['Option 1', 'Option 2'] },
            file: { label: '', type: 'file', icon: 'fas fa-upload', accept: 'image/*', max_size: 2 },
            signature: { label: 'Signature', type: 'signature', icon: 'fas fa-signature', name: 'signature', width: 'full' },
            color: { label: 'Color', type: 'color', icon: 'fas fa-palette', name: 'color' },
            range: { label: '', type: 'range', icon: 'fas fa-sliders-h', validation: { min: 0, max: 100, step: 1 } },
            hidden: { label: '', type: 'hidden', icon: 'fas fa-eye-slash', show_in_table: false },
            password: { label: 'Password', type: 'password', icon: 'fas fa-lock', name: 'password', required: true },
            link: { label: 'Link', type: 'link', icon: 'fas fa-external-link-alt', placeholder: 'https://example.com', name: 'link' },
            qr_scanner: { label: 'QR Code', type: 'qr_scanner', icon: 'fas fa-qrcode', name: 'qr_code' },
            camera: { label: 'Photo', type: 'camera', icon: 'fas fa-camera', name: 'photo' },
            gps: { label: 'Location', type: 'gps', icon: 'fas fa-map-marker-alt', name: 'location' },
            appointment: { label: 'Appointment', type: 'appointment', icon: 'fas fa-calendar-check', name: 'appointment', width: 'full', appointment_config: { slot_duration: 30, start_time: '09:00', end_time: '17:00', break_start: '12:00', break_end: '13:00', days_off: [0, 6], holidays: [], max_bookings: 1, advance_days: 30 } }
        };
        addField(defaults[type] || { type: type });
        initSortable();
        var container = document.getElementById('fieldsContainer');
        container.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ============================
    // CONDITIONAL LOGIC
    // ============================
    function toggleCondition(idx) {
        var enabled = document.getElementById('fConditionEnabled_' + idx).checked;
        document.getElementById('conditionConfig_' + idx).classList.toggle('active', enabled);
    }

    function populateConditionFields(idx, selectedField) {
        var sel = document.getElementById('fCondField_' + idx);
        if (!sel) return;
        var html = '<option value="">-- Select Field --</option>';
        document.querySelectorAll('.field-card').forEach(function(card) {
            var cardIdx = card.dataset.fieldIdx;
            if (cardIdx == idx) return;
            var label = document.getElementById('fLabel_' + cardIdx);
            var name = document.getElementById('fName_' + cardIdx);
            if (label && name) {
                var lbl = label.value || 'Field #' + cardIdx;
                var nm = name.value || 'field_' + cardIdx;
                var selected = (nm === selectedField) ? ' selected' : '';
                html += '<option value="' + nm + '"' + selected + '>' + lbl + '</option>';
            }
        });
        sel.innerHTML = html;
    }

    // ============================
    // DEPENDENT DROPDOWN
    // ============================
    function populateDependentParentFields(idx, selectedParent) {
        var sel = document.getElementById('fDependentOn_' + idx);
        if (!sel) return;
        var html = '<option value="">-- None (Independent) --</option>';
        document.querySelectorAll('.field-card').forEach(function(card) {
            var cardIdx = card.dataset.fieldIdx;
            if (cardIdx == idx) return;
            var type = document.getElementById('fType_' + cardIdx);
            var label = document.getElementById('fLabel_' + cardIdx);
            var name = document.getElementById('fName_' + cardIdx);
            if (type && label && name && ['select', 'radio'].indexOf(type.value) >= 0) {
                var lbl = label.value || 'Field #' + cardIdx;
                var nm = name.value || 'field_' + cardIdx;
                var selected = (nm === selectedParent) ? ' selected' : '';
                html += '<option value="' + nm + '"' + selected + '>' + lbl + '</option>';
            }
        });
        sel.innerHTML = html;
    }

    function onDependentParentChange(idx) {
        var parentSel = document.getElementById('fDependentOn_' + idx);
        var builderDiv = document.getElementById('depMapBuilder_' + idx);
        var parentName = parentSel ? parentSel.value : '';

        if (!parentName || !builderDiv) {
            if (builderDiv) { builderDiv.style.display = 'none'; builderDiv.innerHTML = ''; }
            return;
        }

        var parentCard = null;
        document.querySelectorAll('.field-card').forEach(function(card) {
            var cardIdx = card.dataset.fieldIdx;
            var nameEl = document.getElementById('fName_' + cardIdx);
            if (nameEl && nameEl.value === parentName) parentCard = card;
        });

        if (!parentCard) {
            builderDiv.innerHTML = '<p class="dep-map-empty"><i class="fas fa-exclamation-circle"></i> Parent field not found</p>';
            builderDiv.style.display = 'block'; return;
        }

        var parentIdx = parentCard.dataset.fieldIdx;
        var optionsTextarea = document.getElementById('fOptions_' + parentIdx);
        if (!optionsTextarea) {
            builderDiv.innerHTML = '<p class="dep-map-empty"><i class="fas fa-exclamation-circle"></i> Parent has no options</p>';
            builderDiv.style.display = 'block'; return;
        }

        var parentOptions = optionsTextarea.value.split('\n').map(function(o) { return o.trim(); }).filter(function(o) { return o; });
        if (parentOptions.length === 0) {
            builderDiv.innerHTML = '<p class="dep-map-empty"><i class="fas fa-info-circle"></i> Add options to parent field first</p>';
            builderDiv.style.display = 'block'; return;
        }

        var existingMap = {};
        try {
            var existing = document.getElementById('fDependentOptions_' + idx).value;
            if (existing) existingMap = JSON.parse(existing);
        } catch(e) {}

        var html = '<div class="dep-map-table">'
            + '<div class="dep-map-header-row"><div>When Parent =</div><div>Show These Options</div></div>';
        parentOptions.forEach(function(opt) {
            var childOpts = existingMap[opt] ? existingMap[opt].join(', ') : '';
            var escapedOpt = opt.replace(/"/g, '&quot;');
            html += '<div class="dep-map-row">'
                + '<div class="dep-map-parent-cell">' + opt + '</div>'
                + '<div class="dep-map-child-cell"><input type="text" data-parent="' + escapedOpt + '" class="dep-map-input" value="' + childOpts.replace(/"/g, '&quot;') + '" placeholder="Option1, Option2..." oninput="buildDependentJSON(' + idx + ')"></div>'
                + '</div>';
        });
        html += '</div>';
        builderDiv.innerHTML = html;
        builderDiv.style.display = 'block';
    }

    function buildDependentJSON(idx) {
        var map = {};
        var builderDiv = document.getElementById('depMapBuilder_' + idx);
        if (!builderDiv) return;
        builderDiv.querySelectorAll('.dep-map-input').forEach(function(input) {
            var parent = input.dataset.parent;
            var children = input.value.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s; });
            if (children.length > 0) map[parent] = children;
        });
        document.getElementById('fDependentOptions_' + idx).value = JSON.stringify(map);
    }

    // ============================
    // REGEX HELPERS
    // ============================
    var REGEX_PRESETS = {
        letters: { pattern: '^[A-Za-z ]+$', error: 'Only letters and spaces allowed' },
        numbers: { pattern: '^[0-9]+$', error: 'Only numbers allowed' },
        alphanum: { pattern: '^[A-Za-z0-9]+$', error: 'Only letters and numbers allowed' },
        phone: { pattern: '^\\+?[0-9]{10,15}$', error: 'Valid phone number (10-15 digits)' },
        zip: { pattern: '^[0-9]{5}(-[0-9]{4})?$', error: 'Valid ZIP code (e.g. 12345)' },
        id_code: { pattern: '^[A-Z]{2,4}-[0-9]{3,}$', error: 'Format: AB-001 or XYZ-1234' }
    };

    function setRegexPreset(idx, key) {
        var preset = REGEX_PRESETS[key];
        if (!preset) return;
        document.getElementById('fRegex_' + idx).value = preset.pattern;
        var errorInput = document.getElementById('fCustomError_' + idx);
        if (errorInput && !errorInput.value.trim()) errorInput.value = preset.error;
        updateRegexTester(idx);
    }

    function updateRegexTester(idx) {
        var regexVal = document.getElementById('fRegex_' + idx).value.trim();
        var tester = document.getElementById('regexTester_' + idx);
        if (!tester) return;
        if (regexVal) { tester.style.display = 'block'; testRegexLive(idx); }
        else { tester.style.display = 'none'; document.getElementById('regexResult_' + idx).innerHTML = ''; }
    }

    function testRegexLive(idx) {
        var regexVal = document.getElementById('fRegex_' + idx).value.trim();
        var testInput = document.getElementById('regexTestInput_' + idx);
        var resultBadge = document.getElementById('regexResult_' + idx);
        if (!regexVal || !testInput || !resultBadge) return;
        var testVal = testInput.value;
        if (!testVal) { resultBadge.innerHTML = ''; resultBadge.className = 'regex-result-badge'; testInput.style.borderColor = 'var(--border-color)'; return; }
        try {
            var regex = new RegExp(regexVal);
            if (regex.test(testVal)) {
                resultBadge.innerHTML = '<i class="fas fa-check-circle"></i> Match';
                resultBadge.className = 'regex-result-badge regex-match';
                testInput.style.borderColor = 'var(--accent-green)';
            } else {
                resultBadge.innerHTML = '<i class="fas fa-times-circle"></i> No match';
                resultBadge.className = 'regex-result-badge regex-no-match';
                testInput.style.borderColor = 'var(--accent-red)';
            }
        } catch(e) {
            resultBadge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Invalid regex';
            resultBadge.className = 'regex-result-badge regex-error';
            testInput.style.borderColor = 'var(--accent-amber)';
        }
    }

    // ============================
    // MULTI-STEP
    // ============================
    function toggleMultiStep() {
        var enabled = document.getElementById('enableMultiStep').checked;
        document.getElementById('stepManager').classList.toggle('active', enabled);
        if (enabled && formSteps.length === 0) { addStep('Step 1'); addStep('Step 2'); }
        document.querySelectorAll('.field-card').forEach(function(card) {
            var idx = card.dataset.fieldIdx;
            var wrap = document.getElementById('stepDropdownWrap_' + idx);
            if (wrap) wrap.style.display = enabled ? 'inline-flex' : 'none';
        });
        if (enabled) refreshAllStepDropdowns();
    }

    function addStep(name) {
        var stepNum = formSteps.length + 1;
        formSteps.push(name || 'Step ' + stepNum);
        renderStepManager();
        refreshAllStepDropdowns();
    }

    function removeStep(index) {
        if (formSteps.length <= 2) {
            Swal.fire({ icon: 'warning', title: 'Minimum Steps', text: 'Multi-step forms require at least 2 steps.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }
        formSteps.splice(index, 1);
        renderStepManager();
        refreshAllStepDropdowns();
    }

    function renderStepManager() {
        var list = document.getElementById('stepManagerList');
        var html = '';
        formSteps.forEach(function(name, i) {
            html += '<div class="step-manager-item">'
                + '<span class="step-num">' + (i + 1) + '</span>'
                + '<input type="text" value="' + (name || '').replace(/"/g, '&quot;') + '" onchange="formSteps[' + i + ']=this.value; refreshAllStepDropdowns();" placeholder="Step name">'
                + '<button type="button" class="remove-step-btn" onclick="removeStep(' + i + ')" title="Remove"><i class="fas fa-times"></i></button>'
                + '</div>';
        });
        list.innerHTML = html;
    }

    function refreshAllStepDropdowns() {
        document.querySelectorAll('.field-card').forEach(function(card) {
            var idx = card.dataset.fieldIdx;
            var sel = document.getElementById('fFieldStep_' + idx);
            if (sel) {
                var curVal = sel.value || '1';
                var html = '';
                formSteps.forEach(function(name, i) {
                    var num = i + 1;
                    var selected = (parseInt(curVal) === num) ? ' selected' : '';
                    html += '<option value="' + num + '"' + selected + '>' + num + ': ' + (name || 'Step ' + num) + '</option>';
                });
                sel.innerHTML = html;
            }
        });
    }

    function updateStepDropdownForField(idx, selectedStep) {
        var sel = document.getElementById('fFieldStep_' + idx);
        if (!sel) return;
        var html = '';
        formSteps.forEach(function(name, i) {
            var num = i + 1;
            var selected = (parseInt(selectedStep) === num) ? ' selected' : '';
            html += '<option value="' + num + '"' + selected + '>' + num + ': ' + (name || 'Step ' + num) + '</option>';
        });
        sel.innerHTML = html;
    }

    // ============================
    // DUPLICATE DETECTION
    // ============================
    function toggleDuplicateDetection() {
        var enabled = document.getElementById('duplicateDetection').checked;
        document.getElementById('duplicateFieldsWrapper').style.display = enabled ? 'block' : 'none';
        if (enabled) refreshDuplicateFieldsList();
    }

    function refreshDuplicateFieldsList() {
        var container = document.getElementById('duplicateFieldsContainer');
        var html = '';
        document.querySelectorAll('.field-card').forEach(function(card) {
            var idx = card.dataset.fieldIdx;
            var label = document.getElementById('fLabel_' + idx);
            var name = document.getElementById('fName_' + idx);
            var type = document.getElementById('fType_' + idx);
            if (label && name && type) {
                var t = type.value;
                if (['text','email','tel','number','url','select','radio'].indexOf(t) >= 0) {
                    var lbl = label.value || 'Field #' + idx;
                    var nm = name.value || 'field_' + idx;
                    html += '<label class="checkbox-label" style="font-size:13px;"><input type="checkbox" class="dup-field-cb" value="' + nm + '"> ' + lbl + '</label>';
                }
            }
        });
        container.innerHTML = html || '<p style="color:var(--text-muted);font-size:13px;">Add fields first, then select which ones to check.</p>';
    }

    function collectDuplicateFields() {
        var checked = [];
        document.querySelectorAll('.dup-field-cb:checked').forEach(function(cb) { checked.push(cb.value); });
        return JSON.stringify(checked);
    }

    // ============================
    // COLLECT FIELDS & SAVE
    // ============================
    function collectFields() {
        const cards = document.querySelectorAll('.field-card');
        const fields = [];
        let order = 1;

        cards.forEach(function(card) {
            const idx = card.dataset.fieldIdx;
            const type = document.getElementById('fType_' + idx).value;
            const field = {
                name: document.getElementById('fName_' + idx).value || 'field_' + idx,
                label: document.getElementById('fLabel_' + idx).value,
                type: type,
                required: document.getElementById('fRequired_' + idx).checked,
                placeholder: document.getElementById('fPlaceholder_' + idx).value,
                icon: document.getElementById('fIcon_' + idx).value || 'fas fa-font',
                width: document.getElementById('fWidth_' + idx).value,
                show_in_table: document.getElementById('fShowTable_' + idx).checked,
                order: order++
            };

            if (['select', 'radio', 'checkbox'].includes(type)) {
                const opts = document.getElementById('fOptions_' + idx).value.split('\n').map(o => o.trim()).filter(o => o);
                field.options = opts;
            }

            if (type === 'file') {
                field.accept = document.getElementById('fAccept_' + idx).value;
                field.max_size = parseFloat(document.getElementById('fMaxSize_' + idx).value) || 2;
            }

            if (type === 'appointment') {
                var daysOff = [];
                document.querySelectorAll('.apptDayOff_' + idx + ':checked').forEach(function(cb) { daysOff.push(parseInt(cb.value)); });
                var holidaysStr = (document.getElementById('fApptHolidays_' + idx).value || '').trim();
                var holidays = holidaysStr ? holidaysStr.split(',').map(function(h) { return h.trim(); }).filter(function(h) { return h; }) : [];
                field.appointment_config = {
                    slot_duration: parseInt(document.getElementById('fApptDuration_' + idx).value) || 30,
                    start_time: document.getElementById('fApptStart_' + idx).value || '09:00',
                    end_time: document.getElementById('fApptEnd_' + idx).value || '17:00',
                    break_start: document.getElementById('fApptBreakStart_' + idx).value || '',
                    break_end: document.getElementById('fApptBreakEnd_' + idx).value || '',
                    max_bookings: parseInt(document.getElementById('fApptMaxBookings_' + idx).value) || 1,
                    advance_days: parseInt(document.getElementById('fApptAdvanceDays_' + idx).value) || 30,
                    days_off: daysOff,
                    holidays: holidays
                };
            }

            if (['text', 'number', 'range', 'date', 'datetime', 'time', 'tel'].includes(type)) {
                const min = document.getElementById('fMin_' + idx).value;
                const max = document.getElementById('fMax_' + idx).value;
                const step = document.getElementById('fStep_' + idx).value;
                if (min || max || step) {
                    field.validation = {};
                    if (min) field.validation.min = parseFloat(min) || min;
                    if (max) field.validation.max = parseFloat(max) || max;
                    if (step) field.validation.step = parseFloat(step);
                }
            }

            var chartEnabledEl = document.getElementById('fChartEnabled_' + idx);
            if (chartEnabledEl && chartEnabledEl.checked && CHART_COMPATIBLE_TYPES[type]) {
                field.chart_enabled = true;
                field.chart_type = document.getElementById('fChartType_' + idx).value || (CHART_COMPATIBLE_TYPES[type] ? CHART_COMPATIBLE_TYPES[type][0] : 'bar');
                field.chart_label = document.getElementById('fChartLabel_' + idx).value || field.label;
            }

            if (document.getElementById('enableMultiStep').checked) {
                var stepSel = document.getElementById('fFieldStep_' + idx);
                field.step = stepSel ? parseInt(stepSel.value) || 1 : 1;
            }

            var condEnabled = document.getElementById('fConditionEnabled_' + idx);
            if (condEnabled && condEnabled.checked) {
                var condField = document.getElementById('fCondField_' + idx).value;
                var condOp = document.getElementById('fCondOp_' + idx).value;
                var condVal = document.getElementById('fCondValue_' + idx).value;
                if (condField) {
                    field.condition = { field: condField, operator: condOp, value: condVal };
                }
            }

            var uniqueEl = document.getElementById('fUnique_' + idx);
            if (uniqueEl && uniqueEl.checked) { field.unique = true; }

            var regexEl = document.getElementById('fRegex_' + idx);
            if (regexEl && regexEl.value.trim()) { field.regex = regexEl.value.trim(); }

            var errorEl = document.getElementById('fCustomError_' + idx);
            if (errorEl && errorEl.value.trim()) { field.custom_error = errorEl.value.trim(); }

            var depOn = document.getElementById('fDependentOn_' + idx);
            var depOpts = document.getElementById('fDependentOptions_' + idx);
            if (depOn && depOn.value) {
                field.dependent_on = depOn.value;
                try { var parsed = JSON.parse(depOpts.value); field.dependent_options = parsed; } catch(e) {}
            }

            var refImg = document.getElementById('fRefImage_' + idx);
            if (refImg && refImg.value.trim()) { field.reference_image = refImg.value.trim(); }

            fields.push(field);
        });

        return fields;
    }

    function saveForm() {
        const formName = document.getElementById('formName').value.trim();
        if (!formName) {
            Swal.fire({ icon: 'warning', title: 'Missing', text: 'Form name is required', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }

        const fields = collectFields();
        if (fields.length === 0) {
            Swal.fire({ icon: 'warning', title: 'No Fields', text: 'Add at least one field', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }

        for (let f of fields) {
            if (!f.label) {
                Swal.fire({ icon: 'warning', title: 'Missing Label', text: 'All fields must have a label', background: 'var(--bg-card)', color: 'var(--text-primary)' });
                return;
            }
        }

        var sdEdit = document.getElementById('sdEditRoles');
        var sdDelete = document.getElementById('sdDeleteRoles');
        const editRoles = sdEdit && sdEdit._sdGetValues ? sdEdit._sdGetValues() : ['Admin'];
        const deleteRoles = sdDelete && sdDelete._sdGetValues ? sdDelete._sdGetValues() : ['Admin'];

        const formData = new FormData();
        formData.append('form_name', formName);
        formData.append('form_icon', document.getElementById('formIcon').value || 'fas fa-list');
        formData.append('form_description', document.getElementById('formDescription').value);
        formData.append('form_logo', document.getElementById('formLogo').value || '');
        formData.append('fields_json', JSON.stringify(fields));
        formData.append('access_type', document.getElementById('accessTypeHidden').value);
        formData.append('can_edit_roles', JSON.stringify(editRoles));
        formData.append('can_delete_roles', JSON.stringify(deleteRoles));
        formData.append('is_public', document.getElementById('isPublic').checked ? 1 : 0);
        formData.append('email_on_submit', document.getElementById('emailOnSubmit').checked ? 1 : 0);
        formData.append('email_notify_admin', document.getElementById('emailNotifyAdmin').checked ? 1 : 0);
        formData.append('email_confirmation', document.getElementById('emailConfirmation').checked ? 1 : 0);
        formData.append('enable_pdf_download', document.getElementById('enablePdfDownload').checked ? 1 : 0);
        formData.append('expires_at', document.getElementById('expiresAt').value || '');
        formData.append('max_submissions', document.getElementById('maxSubmissions').value || 0);
        formData.append('form_password', document.getElementById('formPassword').value || '');
        formData.append('one_per_user', document.getElementById('onePerUser').checked ? 1 : 0);
        formData.append('thank_you_message', document.getElementById('thankYouMessage').value || '');
        formData.append('redirect_url', document.getElementById('redirectUrl').value || '');
        formData.append('form_steps', document.getElementById('enableMultiStep').checked ? JSON.stringify(formSteps) : '');
        formData.append('schedule_start', document.getElementById('scheduleStart').value || '');
        formData.append('schedule_end', document.getElementById('scheduleEnd').value || '');
        formData.append('duplicate_detection', document.getElementById('duplicateDetection').checked ? 1 : 0);
        formData.append('duplicate_fields', collectDuplicateFields());

        var sdVisible = document.getElementById('sdVisibleToUsers');
        var sdEditable = document.getElementById('sdEditableByUsers');
        var visibleUsers = sdVisible && sdVisible._sdGetValues ? sdVisible._sdGetValues() : [];
        var editableUsers = sdEditable && sdEditable._sdGetValues ? sdEditable._sdGetValues() : [];
        formData.append('visible_to_users', visibleUsers.length > 0 ? JSON.stringify(visibleUsers) : '');
        formData.append('editable_by_users', editableUsers.length > 0 ? JSON.stringify(editableUsers) : '');

        let action = 'addForm';
        if (isEditMode) {
            action = 'updateForm';
            formData.append('id', document.getElementById('editFormId').value);
        }
        formData.append('action', action);

        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });

        $.ajax({
            url: 'crud_settings.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                    closeFormBuilder();
                    setTimeout(loadForms, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            }
        });
    }

    // ============================
    // TABLE ACTIONS
    // ============================
    function deleteForm(id, name) {
        Swal.fire({
            title: 'Delete Form?',
            html: `This will permanently delete <strong>${name}</strong> and ALL its records and files.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData();
                fd.append('action', 'deleteForm');
                fd.append('id', id);
                $.ajax({
                    url: 'crud_settings.php',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                            setTimeout(loadForms, 100);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                        }
                    }
                });
            }
        });
    }

    function toggleFormStatus(id) {
        const fd = new FormData();
        fd.append('action', 'toggleStatus');
        fd.append('id', id);
        $.ajax({
            url: 'crud_settings.php',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Updated!', text: response.message, timer: 1500, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                    setTimeout(loadForms, 100);
                }
            }
        });
    }

    function duplicateForm(id) {
        const fd = new FormData();
        fd.append('action', 'duplicateForm');
        fd.append('id', id);
        $.ajax({
            url: 'crud_settings.php',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Duplicated!', text: response.message, timer: 2000, showConfirmButton: false, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                    setTimeout(loadForms, 100);
                }
            }
        });
    }

    // ============================
    // QR CODE
    // ============================
    function showFormQR(formName, publicToken) {
        var baseUrl = window.location.href.replace(/\/[^\/]*$/, '/');
        var publicUrl = baseUrl + 'public_form.php?token=' + publicToken;

        Swal.fire({
            title: '<i class="fas fa-qrcode" style="color:#8e24aa;"></i> Share Form',
            html: '<div style="text-align:center;">'
                + '<p style="margin:0 0 4px;font-size:15px;font-weight:600;color:var(--text-primary);">' + formName + '</p>'
                + '<p style="margin:0 0 16px;font-size:12px;color:var(--text-muted);">Scan to open the form</p>'
                + '<div id="swalQrContainer" style="display:inline-block;padding:16px;background:#fff;border-radius:12px;border:2px solid #eee;"></div>'
                + '<div style="margin-top:16px;display:flex;gap:8px;align-items:center;">'
                + '  <input type="text" id="swalQrUrl" value="' + publicUrl + '" readonly style="flex:1;padding:10px 12px;border:2px solid var(--border-color);border-radius:8px;font-size:12px;background:var(--bg-input);color:var(--text-primary);">'
                + '  <button id="qrCopyBtn" onclick="copyQRLink()" class="btn btn-sm" style="background:var(--accent-purple);color:#fff;white-space:nowrap;padding:10px 14px;border-radius:8px;"><i class="fas fa-copy"></i> Copy</button>'
                + '</div>'
                + '<div style="display:flex;gap:8px;margin-top:12px;justify-content:center;">'
                + '  <button onclick="downloadQR(\'' + formName.replace(/'/g, "\\'") + '\')" class="btn btn-sm" style="background:var(--accent-blue);color:#fff;padding:8px 16px;border-radius:8px;"><i class="fas fa-download"></i> PNG</button>'
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
            setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; btn.style.background = 'var(--accent-purple)'; }, 2000);
        } catch(e) {}
    }

    function downloadQR(formName) {
        var canvas = document.querySelector('#swalQrContainer canvas');
        if (!canvas) return;
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
        var win = window.open('', '_blank');
        var formName = document.querySelector('.swal2-title') ? document.querySelector('.swal2-title').textContent.replace('Share Form', '').trim() : 'Form';
        win.document.write('<!DOCTYPE html><html><head><title>QR Code</title><style>body{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:Arial,sans-serif;}h2{color:#001f3f;margin-bottom:8px;}p{color:#666;margin-top:0;}</style></head><body>');
        win.document.write('<h2>' + formName + '</h2>');
        win.document.write('<p>Scan to open form</p>');
        win.document.write('<img src="' + canvas.toDataURL('image/png') + '" style="width:300px;height:300px;">');
        win.document.write('</body></html>');
        win.document.close();
        win.onload = function() { win.print(); };
    }

    // ============================
    // AI FORM BUILDER
    // ============================
    function aiResetPrompt() {
        document.getElementById('aiStep1').style.display = 'block';
        document.getElementById('aiStep2').style.display = 'none';
        document.getElementById('aiLoading').style.display = 'none';
        document.getElementById('aiPromptInput').value = '';
        document.getElementById('aiRewrittenText').textContent = '';
    }

    function aiRewritePrompt() {
        var prompt = document.getElementById('aiPromptInput').value.trim();
        if (!prompt) {
            Swal.fire({ icon: 'warning', title: 'Empty Prompt', text: 'Please describe the form you want to create.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }
        if (prompt.length < 10) {
            Swal.fire({ icon: 'warning', title: 'Too Short', text: 'Please provide a more detailed description (at least 10 characters).', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }

        document.getElementById('aiStep1').style.display = 'none';
        document.getElementById('aiStep2').style.display = 'none';
        document.getElementById('aiLoading').style.display = 'block';
        document.getElementById('aiLoadingText').innerHTML = '<i class="fas fa-cog fa-spin"></i> Rewriting your prompt...';

        var fd = new FormData();
        fd.append('action', 'aiRewritePrompt');
        if (isEditMode) {
            var currentFields = collectFields();
            var fieldSummary = currentFields.map(function(f) {
                return f.label + ' (' + f.type + (f.required ? ', required' : '') + ')';
            }).join(', ');
            var formName = document.getElementById('formName').value;
            fd.append('prompt', 'Existing form "' + formName + '" has fields: ' + fieldSummary + '. User wants to: ' + prompt);
            fd.append('mode', 'modify');
        } else {
            fd.append('prompt', prompt);
        }

        $.ajax({
            url: 'crud_settings.php',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 35000,
            success: function(response) {
                document.getElementById('aiLoading').style.display = 'none';
                if (response.success) {
                    document.getElementById('aiRewrittenText').textContent = response.rewritten_prompt;
                    document.getElementById('aiStep2').style.display = 'block';
                } else {
                    document.getElementById('aiStep1').style.display = 'block';
                    Swal.fire({ icon: 'error', title: 'AI Error', text: response.message, background: 'var(--bg-card)', color: 'var(--text-primary)' });
                }
            },
            error: function(xhr, status) {
                document.getElementById('aiLoading').style.display = 'none';
                document.getElementById('aiStep1').style.display = 'block';
                var msg = status === 'timeout' ? 'Request timed out. Please try again.' : 'Connection error. Check your internet.';
                Swal.fire({ icon: 'error', title: 'Error', text: msg, background: 'var(--bg-card)', color: 'var(--text-primary)' });
            }
        });
    }

    function aiBuildForm() {
        var rewrittenPrompt = document.getElementById('aiRewrittenText').textContent.trim();
        if (!rewrittenPrompt) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'No enhanced prompt found.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
            return;
        }

        document.getElementById('aiStep2').style.display = 'none';
        document.getElementById('aiLoading').style.display = 'block';
        document.getElementById('aiLoadingText').innerHTML = isEditMode
            ? '<i class="fas fa-wand-magic-sparkles fa-spin"></i> Modifying your form...'
            : '<i class="fas fa-hammer fa-spin"></i> Building your form...';

        var fd = new FormData();
        if (isEditMode) {
            fd.append('action', 'aiModifyForm');
            fd.append('prompt', rewrittenPrompt);
            fd.append('existing_fields', JSON.stringify(collectFields()));
            fd.append('form_name', document.getElementById('formName').value);
        } else {
            fd.append('action', 'aiBuildForm');
            fd.append('prompt', rewrittenPrompt);
        }

        $.ajax({
            url: 'crud_settings.php',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 65000,
            success: function(response) {
                document.getElementById('aiLoading').style.display = 'none';
                if (response.success && response.form_data) {
                    if (isEditMode) {
                        applyAIModifiedFields(response.form_data);
                    } else {
                        applyAIFormData(response.form_data);
                    }
                    Swal.fire({
                        icon: 'success',
                        title: isEditMode ? 'Form Modified!' : 'Form Generated!',
                        text: isEditMode
                            ? 'AI has updated the fields. Review changes below, then click Save Form.'
                            : 'AI has populated the form. Review the fields and settings below, then click Save Form.',
                        timer: 3000,
                        showConfirmButton: false,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                } else {
                    document.getElementById('aiStep2').style.display = 'block';
                    Swal.fire({ icon: 'error', title: 'Build Failed', text: response.message || 'AI could not generate the form.', background: 'var(--bg-card)', color: 'var(--text-primary)' });
                }
            },
            error: function(xhr, status) {
                document.getElementById('aiLoading').style.display = 'none';
                document.getElementById('aiStep2').style.display = 'block';
                var msg = status === 'timeout' ? 'Request timed out. The AI may be overloaded. Try again.' : 'Connection error.';
                Swal.fire({ icon: 'error', title: 'Error', text: msg, background: 'var(--bg-card)', color: 'var(--text-primary)' });
            }
        });
    }

    function applyAIFormData(formData) {
        if (formData.form_name) document.getElementById('formName').value = formData.form_name;
        if (formData.form_icon) { document.getElementById('formIcon').value = formData.form_icon; updateFormIconPreview(); }
        if (formData.form_description) document.getElementById('formDescription').value = formData.form_description;
        if (formData.access_type && ['both','admin','user'].indexOf(formData.access_type) >= 0) {
            initAccessTypeDropdown(formData.access_type);
        }

        document.getElementById('isPublic').checked = !!formData.is_public;
        document.getElementById('emailOnSubmit').checked = !!formData.email_on_submit;
        document.getElementById('emailNotifyAdmin').checked = !!formData.email_notify_admin;
        document.getElementById('emailConfirmation').checked = !!formData.email_confirmation;
        document.getElementById('enablePdfDownload').checked = !!formData.enable_pdf_download;
        document.getElementById('maxSubmissions').value = formData.max_submissions || 0;
        document.getElementById('onePerUser').checked = !!formData.one_per_user;
        document.getElementById('thankYouMessage').value = formData.thank_you_message || '';

        formSteps = [];
        if (formData.form_steps && Array.isArray(formData.form_steps)) {
            formSteps = formData.form_steps;
            document.getElementById('enableMultiStep').checked = true;
            document.getElementById('stepManager').classList.add('active');
            renderStepManager();
        } else {
            document.getElementById('enableMultiStep').checked = false;
            document.getElementById('stepManager').classList.remove('active');
        }

        document.getElementById('fieldsContainer').innerHTML = '';
        fieldCounter = 0;
        if (formData.fields && Array.isArray(formData.fields)) {
            formData.fields.forEach(function(field) {
                addField({
                    label: field.label || '',
                    type: field.type || 'text',
                    name: field.name || '',
                    icon: field.icon || 'fas fa-font',
                    placeholder: field.placeholder || '',
                    width: field.width || 'half',
                    required: field.required === true,
                    show_in_table: field.show_in_table !== false,
                    options: field.options || [],
                    validation: field.validation || {},
                    chart_enabled: field.chart_enabled === true,
                    chart_type: field.chart_type || '',
                    chart_label: field.chart_label || '',
                    unique: field.unique === true,
                    regex: field.regex || '',
                    custom_error: field.custom_error || '',
                    step: field.step || 1,
                    condition: field.condition || null,
                    appointment_config: field.appointment_config || null,
                    reference_image: field.reference_image || ''
                });
            });
        }

        updateFieldCount();
        initSortable();
        document.getElementById('aiStep1').style.display = 'block';
        document.getElementById('aiStep2').style.display = 'none';
        document.getElementById('aiLoading').style.display = 'none';

        var detailsSection = document.getElementById('aiBuilderSection').nextElementSibling;
        if (detailsSection) detailsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function applyAIModifiedFields(formData) {
        document.getElementById('fieldsContainer').innerHTML = '';
        fieldCounter = 0;
        if (formData.fields && Array.isArray(formData.fields)) {
            formData.fields.forEach(function(field) {
                addField({
                    label: field.label || '',
                    type: field.type || 'text',
                    name: field.name || '',
                    icon: field.icon || 'fas fa-font',
                    placeholder: field.placeholder || '',
                    width: field.width || 'half',
                    required: field.required === true,
                    show_in_table: field.show_in_table !== false,
                    options: field.options || [],
                    validation: field.validation || {},
                    chart_enabled: field.chart_enabled === true,
                    chart_type: field.chart_type || '',
                    chart_label: field.chart_label || '',
                    unique: field.unique === true,
                    regex: field.regex || '',
                    custom_error: field.custom_error || '',
                    step: field.step || 1,
                    condition: field.condition || null,
                    appointment_config: field.appointment_config || null,
                    reference_image: field.reference_image || ''
                });
            });
        }
        updateFieldCount();
        initSortable();
        document.getElementById('aiStep1').style.display = 'block';
        document.getElementById('aiStep2').style.display = 'none';
        document.getElementById('aiLoading').style.display = 'none';
        document.getElementById('aiPromptInput').value = '';
        document.getElementById('aiRewrittenText').textContent = '';
        document.getElementById('fieldsContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ============================
    // INIT
    // ============================
    $(document).ready(function() {
        loadForms();
    });
    </script>
</body>
</html>