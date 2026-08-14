<?php
/*
  Developed by Muhafiz Ahmed
  WhatsApp: https://wa.me/+251953115473 (For Custom Projects)
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
$current_page = 'about';

$branding = getSiteBranding();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <title>About · <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=7.0">
    <?php echo generateUserThemeCSS($user_id); ?>
    
    <style>
        /* ============================================================
           ABOUT PAGE - WORLD-CLASS MODERN UI
           Same design language as other pages
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
        .btn-ghost { background: transparent; border-color: transparent; color: var(--text-muted); }
        .btn-ghost:hover { background: var(--bg-card); border-color: var(--border-color); color: var(--text-primary); }
        .btn-sm { padding: 6px 14px; font-size: 12px; border-radius: var(--radius-sm); }

        /* About Cards */
        .about-section {
            max-width: 1200px;
            margin: 0 auto;
        }

        .about-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
            margin-bottom: 24px;
        }
        .about-card:hover { border-color: var(--border-hover); }

        .about-card .card-header {
            display: flex; align-items: center; gap: 14px;
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .about-card .card-header .header-icon {
            width: 40px; height: 40px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .header-icon-purple { background: var(--accent-purple-bg); color: var(--accent-purple); }
        .header-icon-blue { background: var(--accent-blue-bg); color: var(--accent-blue); }
        .header-icon-green { background: var(--accent-green-bg); color: var(--accent-green); }
        .header-icon-amber { background: var(--accent-amber-bg); color: var(--accent-amber); }
        .header-icon-red { background: var(--accent-red-bg); color: var(--accent-red); }
        .header-icon-pink { background: var(--accent-pink-bg); color: var(--accent-pink); }

        .about-card .card-header .header-title {
            font-size: 18px; font-weight: 700; color: var(--text-primary);
        }
        .about-card .card-header .header-subtitle {
            font-size: 13px; color: var(--text-muted);
        }
        .about-card .card-body { padding: 24px; }

        /* About Header */
        .about-header-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all var(--transition-normal);
            margin-bottom: 24px;
            padding: 32px 36px;
            display: flex;
            align-items: center;
            gap: 32px;
            flex-wrap: wrap;
        }
        .about-header-card:hover { border-color: var(--border-hover); }

        .about-header-card .about-logo {
            width: 80px;
            height: 80px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            flex-shrink: 0;
            border: 2px solid var(--border-color);
            background: var(--bg-secondary);
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .about-header-card .about-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .about-header-card .about-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
            letter-spacing: -0.02em;
        }
        .about-header-card .about-title .about-dev {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 4px 0 0;
        }
        .about-header-card .about-title .about-dev strong {
            color: var(--accent-purple);
        }

        /* Body Text */
        .about-card .card-body p {
            font-size: 15px;
            line-height: 1.7;
            color: var(--text-secondary);
            margin: 0 0 10px;
        }
        body:not(.dark-mode) .about-card .card-body p {
            color: #475569;
        }
        .about-card .card-body p strong {
            color: var(--text-primary);
        }
        body:not(.dark-mode) .about-card .card-body p strong {
            color: #0f172a;
        }
        .about-card .card-body .mb-24 { margin-bottom: 24px; }

        /* About Features List */
        .about-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .about-features li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.6;
        }
        body:not(.dark-mode) .about-features li {
            color: #475569;
        }
        .about-features li i {
            margin-top: 2px;
            width: 18px;
            text-align: center;
            flex-shrink: 0;
            color: var(--accent-blue);
        }
        .about-features li strong {
            color: var(--text-primary);
        }
        body:not(.dark-mode) .about-features li strong {
            color: #0f172a;
        }

        /* Tables */
        .about-table-wrapper {
            overflow-x: auto;
        }
        .about-roles-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .about-roles-table thead th {
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
            background: var(--bg-secondary);
            text-align: left;
        }
        body:not(.dark-mode) .about-roles-table thead th {
            background: #eef2f6;
            color: #475569;
        }
        .about-roles-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            vertical-align: middle;
        }
        body:not(.dark-mode) .about-roles-table tbody td {
            color: #475569;
        }
        .about-roles-table tbody tr:hover td {
            background: var(--bg-card-hover);
        }
        body:not(.dark-mode) .about-roles-table tbody tr:hover td {
            background: #f8fafc;
        }
        .about-roles-table .text-success { color: var(--accent-green); }
        .about-roles-table .text-danger { color: var(--accent-red); }

        .role-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
        }
        .role-admin {
            background: var(--accent-blue-bg);
            color: var(--accent-blue);
        }
        .role-sf {
            background: var(--accent-green-bg);
            color: var(--accent-green);
        }

        /* Developer Section */
        .about-developer .card-body {
            padding: 24px;
        }
        .developer-info {
            display: flex;
            align-items: flex-start;
            gap: 24px;
            flex-wrap: wrap;
        }
        .developer-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border-color);
            flex-shrink: 0;
            background: var(--bg-secondary);
        }
        .developer-details {
            flex: 1;
        }
        .developer-details h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 2px;
        }
        .developer-brand {
            font-size: 13px;
            color: var(--accent-purple);
            font-weight: 500;
            margin: 0 0 8px;
        }
        .developer-details p {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.7;
            margin: 0 0 12px;
        }
        body:not(.dark-mode) .developer-details p {
            color: #475569;
        }
        .developer-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .dev-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
            transition: all var(--transition-fast);
        }
        .dev-link.whatsapp {
            background: #25D366;
            color: #fff;
        }
        .dev-link.whatsapp:hover {
            background: #128C7E;
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(37, 211, 102, 0.35);
        }

        /* Footer */
        .about-footer {
            text-align: center;
            padding: 20px 0 10px;
            color: var(--text-muted);
            font-size: 14px;
        }
        .about-footer .text-danger { color: var(--accent-red); }
        .about-footer .about-version {
            font-size: 12px;
            margin-top: 4px;
            opacity: 0.6;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .page-header-right { width: 100%; }
            .page-header-right .btn { flex: 1; justify-content: center; }
            .about-header-card { flex-direction: column; text-align: center; padding: 24px 20px; }
            .about-header-card .about-title h1 { font-size: 24px; }
            .about-card .card-header { flex-wrap: wrap; }
            .about-card .card-body { padding: 16px; }
            .developer-info { flex-direction: column; align-items: center; text-align: center; }
            .developer-links { justify-content: center; }
            .about-roles-table { font-size: 13px; }
            .about-roles-table thead th,
            .about-roles-table tbody td { padding: 8px 10px; }
        }
        @media (max-width: 480px) {
            .page-header-left .icon-wrapper { width: 42px; height: 42px; font-size: 18px; }
            .page-header-left .title-group h1 { font-size: 20px; }
            .about-header-card .about-logo { width: 60px; height: 60px; }
            .about-header-card .about-title h1 { font-size: 20px; }
            .about-features li { font-size: 13px; }
            .about-roles-table { font-size: 12px; }
            .about-roles-table thead th,
            .about-roles-table tbody td { padding: 6px 8px; }
        }

        * { transition: background-color var(--transition-slow), color var(--transition-slow), border-color var(--transition-slow), box-shadow var(--transition-slow); }
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
                <span class="current">About</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="icon-wrapper">
                        <i class="fas fa-info-circle"></i>
                        <span class="pulse-dot"></span>
                    </div>
                    <div class="title-group">
                        <h1>About This App</h1>
                        <p class="subtitle">Everything you need to know about the dashboard and form builder</p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-ghost btn-sm" onclick="window.print()" title="Print">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <div class="about-section">

                <!-- App Header -->
                <div class="about-header-card">
                    <div class="about-logo">
                        <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="App Logo" onerror="this.parentElement.innerHTML='<i class=\'fas fa-cube\' style=\'font-size:40px;color:var(--text-muted);opacity:0.3;\'></i>'">
                    </div>
                    <div class="about-title">
                        <h1><?php echo htmlspecialchars($branding['site_name']); ?></h1>
                        <p class="about-dev">Developed by <strong>Muhafiz Ahmed</strong> · <span style="color:var(--text-muted);">Muhammed Computer Technology</span></p>
                    </div>
                </div>

                <!-- What is this App -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-purple"><i class="fas fa-question-circle"></i></div>
                        <div>
                            <div class="header-title">What is this App?</div>
                            <div class="header-subtitle">Your all-in-one dashboard and form builder</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p>This is a complete, all-in-one dashboard system built from scratch with <strong>PHP 8+</strong> and <strong>MySQL</strong>. It's basically your central hub for managing users, tracking activity, and most importantly &mdash; building powerful dynamic forms without writing a single line of code.</p>
                        <p>The main highlight is the <strong>CRUD Form Builder</strong>. Think of it like Google Forms but way more powerful. You can create any kind of form you want &mdash; student registrations, appointment bookings, event signups, support tickets, surveys, inventory tracking &mdash; literally anything. And you don't need to be a developer to use it. The AI can even build the entire form for you just from a text description.</p>
                        <p>Every form you create automatically gets a full data management interface with search, sort, pagination, CSV/PDF export, charts, and more. You can share forms publicly (with a link or QR code), protect them with passwords, set submission limits, schedule them, and control exactly who can see and edit what.</p>
                    </div>
                </div>

                <!-- How to Create a Form -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-blue"><i class="fas fa-route"></i></div>
                        <div>
                            <div class="header-title">How to Create a Form</div>
                            <div class="header-subtitle">Step by step guide from start to finish</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="mb-24">Here's the complete process from start to finish. Follow these steps and you'll have a fully working form in minutes.</p>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-1" style="color:var(--accent-blue);width:24px;"></i> Open the Form Builder</h3>
                        <p style="margin-bottom:16px;padding-left:28px;">Go to <strong>CRUD Forms</strong> in the sidebar (Admin only). Click the <strong>"Create New Form"</strong> button. This opens the Form Builder panel where you configure everything about your form.</p>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-2" style="color:var(--accent-blue);width:24px;"></i> Fill in Form Details</h3>
                        <div style="padding-left:28px;margin-bottom:16px;">
                            <p>Give your form a <strong>name</strong> (e.g., "Student Registration"), the slug is auto-generated from the name. You can also set:</p>
                            <ul class="about-features" style="margin-top:8px;">
                                <li><i class="fas fa-icons"></i> <strong>Form Icon</strong> &mdash; Pick any Font Awesome icon (e.g., fas fa-user-graduate)</li>
                                <li><i class="fas fa-image"></i> <strong>Form Logo</strong> &mdash; Upload a custom logo image that shows on the form header</li>
                                <li><i class="fas fa-align-left"></i> <strong>Description</strong> &mdash; A short description shown on public forms</li>
                                <li><i class="fas fa-user-shield"></i> <strong>Access Type</strong> &mdash; Admin Only, User Only, or Both</li>
                                <li><i class="fas fa-user-edit"></i> <strong>Edit / Delete Roles</strong> &mdash; Choose which roles can edit or delete records</li>
                            </ul>
                        </div>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-3" style="color:var(--accent-blue);width:24px;"></i> Add Fields (Two Ways)</h3>
                        <div style="padding-left:28px;margin-bottom:16px;">
                            <p><strong>Option A: Manual</strong> &mdash; Click the <strong>"Add Field"</strong> button. For each field you configure the label, field type, placeholder, icon, width (half or full), required or not, and any validation rules. You also get <strong>Quick-Add buttons</strong> for all 22 field types so you can add a field with one click.</p>
                            <p style="margin-top:8px;"><strong>Option B: AI Build</strong> &mdash; Just describe what you want in plain English. For example type: <em>"Create a job application form with name, email, phone, position dropdown, resume upload, and cover letter"</em>. The AI will generate all the fields automatically with proper types, icons, validations, and even chart assignments. You can then tweak the fields as needed.</p>
                        </div>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-4" style="color:var(--accent-blue);width:24px;"></i> Configure Each Field</h3>
                        <div style="padding-left:28px;margin-bottom:16px;">
                            <p>Every field has its own settings panel where you can configure:</p>
                            <ul class="about-features" style="margin-top:8px;">
                                <li><i class="fas fa-tag"></i> <strong>Label &amp; Name</strong> &mdash; What the user sees + the database field name</li>
                                <li><i class="fas fa-list"></i> <strong>Field Type</strong> &mdash; Choose from 22+ types (text, select, file, signature, appointment, etc.)</li>
                                <li><i class="fas fa-icons"></i> <strong>Icon</strong> &mdash; Pick a Font Awesome icon for the field</li>
                                <li><i class="fas fa-columns"></i> <strong>Width</strong> &mdash; Half width (two fields per row) or Full width</li>
                                <li><i class="fas fa-asterisk"></i> <strong>Required</strong> &mdash; Mark fields that must be filled</li>
                                <li><i class="fas fa-table"></i> <strong>Show in Table</strong> &mdash; Whether this field appears in the data table view</li>
                                <li><i class="fas fa-chart-bar"></i> <strong>Chart Type</strong> &mdash; Assign a chart visualization (Bar, Pie, Doughnut, Line, Polar Area)</li>
                                <li><i class="fas fa-ruler"></i> <strong>Validation</strong> &mdash; Min/Max values, min/max length, regex patterns, unique constraint</li>
                                <li><i class="fas fa-exclamation-triangle"></i> <strong>Custom Error Message</strong> &mdash; Your own error text when validation fails</li>
                            </ul>
                        </div>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-5" style="color:var(--accent-blue);width:24px;"></i> Set Up Options for Select / Radio / Checkbox</h3>
                        <div style="padding-left:28px;margin-bottom:16px;">
                            <p>For Dropdown, Radio, and Checkbox fields, you need to add the options the user will choose from. The builder uses a <strong>tag-based input</strong> &mdash; just type an option and press <strong>Enter</strong> or <strong>comma</strong> to add it. Each option appears as a navy pill tag that you can remove with one click. There's also a <strong>"Bulk Add"</strong> button if you want to paste a whole list at once.</p>
                        </div>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-6" style="color:var(--accent-blue);width:24px;"></i> Configure Advanced Settings</h3>
                        <div style="padding-left:28px;margin-bottom:16px;">
                            <p>Scroll down in the form builder to find the advanced settings. These are organized as toggle cards that you can enable or disable:</p>
                            <ul class="about-features" style="margin-top:8px;">
                                <li><i class="fas fa-globe"></i> <strong>Public Form</strong> &mdash; Enable this to generate a public link that anyone can use without logging in</li>
                                <li><i class="fas fa-lock"></i> <strong>Password Protection</strong> &mdash; Set a password that users must enter before accessing the form</li>
                                <li><i class="fas fa-envelope"></i> <strong>Email on Submit</strong> &mdash; Send email notifications when someone submits the form</li>
                                <li><i class="fas fa-file-pdf"></i> <strong>PDF Download</strong> &mdash; Let users download individual records as PDF</li>
                                <li><i class="fas fa-calendar-alt"></i> <strong>Form Scheduling</strong> &mdash; Set a start and end date/time when the form is available</li>
                                <li><i class="fas fa-hourglass-end"></i> <strong>Expiry Date</strong> &mdash; Form automatically stops accepting submissions after this date</li>
                                <li><i class="fas fa-hashtag"></i> <strong>Submission Limit</strong> &mdash; Set maximum number of responses (e.g., max 100 registrations)</li>
                                <li><i class="fas fa-user-check"></i> <strong>One Per User</strong> &mdash; Limit to one submission per user or IP address</li>
                                <li><i class="fas fa-copy"></i> <strong>Duplicate Detection</strong> &mdash; Warn when someone submits data that's similar to an existing record</li>
                                <li><i class="fas fa-layer-group"></i> <strong>Multi-Step Form</strong> &mdash; Break the form into multiple steps with a progress bar</li>
                                <li><i class="fas fa-heart"></i> <strong>Thank You Message</strong> &mdash; Custom message shown after successful submission</li>
                                <li><i class="fas fa-external-link-alt"></i> <strong>Redirect URL</strong> &mdash; Redirect users to another page after they submit</li>
                                <li><i class="fas fa-eye"></i> <strong>Visible To Users</strong> &mdash; Control which specific users can see this form</li>
                                <li><i class="fas fa-pen"></i> <strong>Editable By Users</strong> &mdash; Control which specific users can edit records in this form</li>
                            </ul>
                        </div>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-7" style="color:var(--accent-blue);width:24px;"></i> Drag &amp; Drop to Reorder</h3>
                        <p style="margin-bottom:16px;padding-left:28px;">All fields can be dragged and dropped to change their order. Just grab a field card and drag it up or down. The order you set here is the order they appear on the form.</p>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-8" style="color:var(--accent-blue);width:24px;"></i> Save the Form</h3>
                        <p style="margin-bottom:16px;padding-left:28px;">Hit <strong>"Save Form"</strong>. That's it! Your form is now live and ready to use. It will appear in the sidebar under CRUD Forms. If you made it public, a shareable link and QR code are generated automatically.</p>

                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-9" style="color:var(--accent-blue);width:24px;"></i> Start Using Your Form</h3>
                        <div style="padding-left:28px;">
                            <p>Once saved, you and your users can:</p>
                            <ul class="about-features" style="margin-top:8px;">
                                <li><i class="fas fa-plus-circle"></i> <strong>Add Records</strong> &mdash; Click "Add Record" to open the form modal and start filling in data</li>
                                <li><i class="fas fa-edit"></i> <strong>Edit Records</strong> &mdash; Update any existing submission</li>
                                <li><i class="fas fa-trash"></i> <strong>Delete Records</strong> &mdash; Remove records (with confirmation)</li>
                                <li><i class="fas fa-search"></i> <strong>Search &amp; Filter</strong> &mdash; Use the DataTable search to find specific records</li>
                                <li><i class="fas fa-file-csv"></i> <strong>Export</strong> &mdash; Download all data as CSV, PDF, or print it directly</li>
                                <li><i class="fas fa-chart-pie"></i> <strong>View Charts</strong> &mdash; See visual breakdowns of your data</li>
                                <li><i class="fas fa-qrcode"></i> <strong>Share via QR Code</strong> &mdash; Generate a QR code for the public form link</li>
                                <li><i class="fas fa-save"></i> <strong>Save Progress</strong> &mdash; Save a draft and come back later to finish</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- All 22 Field Types -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-green"><i class="fas fa-th-list"></i></div>
                        <div>
                            <div class="header-title">All 22 Field Types</div>
                            <div class="header-subtitle">Every field type supported by the Form Builder</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="mb-24">Every field type supported by the Form Builder. Each one is fully configurable with validations, icons, and layout options.</p>

                        <div class="about-table-wrapper">
                            <table class="about-roles-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th>Field Type</th>
                                        <th style="width:40px;">Icon</th>
                                        <th>What it Does</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="4" style="background:var(--bg-secondary);font-weight:600;font-size:13px;"><i class="fas fa-keyboard" style="color:var(--accent-blue);"></i> Text &amp; Input Fields</td></tr>
                                    <tr><td>1</td><td><strong>Text</strong></td><td><i class="fas fa-font" style="color:var(--accent-blue);"></i></td><td>Single line text input. Supports min/max length, regex validation, unique constraint. Used for names, titles, codes, etc.</td></tr>
                                    <tr><td>2</td><td><strong>Number</strong></td><td><i class="fas fa-hashtag" style="color:var(--accent-blue);"></i></td><td>Numeric input with min/max/step validation. Used for quantities, prices, ages, GPA, etc.</td></tr>
                                    <tr><td>3</td><td><strong>Email</strong></td><td><i class="fas fa-envelope" style="color:var(--accent-blue);"></i></td><td>Email input with built-in format validation. Can be set as unique to prevent duplicate emails.</td></tr>
                                    <tr><td>4</td><td><strong>Telephone</strong></td><td><i class="fas fa-phone" style="color:var(--accent-blue);"></i></td><td>Phone number input. Supports regex pattern validation for specific phone formats.</td></tr>
                                    <tr><td>5</td><td><strong>URL</strong></td><td><i class="fas fa-link" style="color:var(--accent-blue);"></i></td><td>Website URL input with format validation. For collecting links, portfolios, social profiles.</td></tr>
                                    <tr><td>6</td><td><strong>Password</strong></td><td><i class="fas fa-key" style="color:var(--accent-blue);"></i></td><td>Masked password input. The value is hidden as dots while typing.</td></tr>
                                    <tr><td>7</td><td><strong>Textarea</strong></td><td><i class="fas fa-align-left" style="color:var(--accent-blue);"></i></td><td>Multi-line text area for long descriptions, notes, comments, addresses, etc.</td></tr>
                                    <tr><td>8</td><td><strong>Hidden</strong></td><td><i class="fas fa-eye-slash" style="color:var(--accent-blue);"></i></td><td>Invisible field with a pre-set default value. Useful for tracking source, form version, or internal IDs.</td></tr>
                                    <tr><td colspan="4" style="background:var(--bg-secondary);font-weight:600;font-size:13px;"><i class="fas fa-list" style="color:var(--accent-blue);"></i> Selection Fields</td></tr>
                                    <tr><td>9</td><td><strong>Dropdown (Select)</strong></td><td><i class="fas fa-caret-down" style="color:var(--accent-blue);"></i></td><td>Single-choice dropdown menu. Add options via tag input. Automatically becomes a <strong>Searchable Dropdown</strong> when there are 5+ options. Can also be configured as a <strong>Dependent Dropdown</strong>.</td></tr>
                                    <tr><td>10</td><td><strong>Radio Buttons</strong></td><td><i class="fas fa-dot-circle" style="color:var(--accent-blue);"></i></td><td>Single-choice option list displayed as radio buttons. Good for yes/no, gender, T-shirt size, etc.</td></tr>
                                    <tr><td>11</td><td><strong>Checkboxes</strong></td><td><i class="fas fa-check-square" style="color:var(--accent-blue);"></i></td><td>Multi-choice checkboxes. Users can select multiple options. Also works as a single toggle checkbox.</td></tr>
                                    <tr><td colspan="4" style="background:var(--bg-secondary);font-weight:600;font-size:13px;"><i class="fas fa-calendar" style="color:var(--accent-blue);"></i> Date &amp; Time Fields</td></tr>
                                    <tr><td>12</td><td><strong>Date</strong></td><td><i class="fas fa-calendar-day" style="color:var(--accent-blue);"></i></td><td>Date picker. Supports min/max date validation. Format: YYYY-MM-DD.</td></tr>
                                    <tr><td>13</td><td><strong>DateTime</strong></td><td><i class="fas fa-calendar-alt" style="color:var(--accent-blue);"></i></td><td>Date and time picker combined. For scheduling exact moments.</td></tr>
                                    <tr><td>14</td><td><strong>Time</strong></td><td><i class="fas fa-clock" style="color:var(--accent-blue);"></i></td><td>Time-only picker. Supports min/max time validation.</td></tr>
                                    <tr><td colspan="4" style="background:var(--bg-secondary);font-weight:600;font-size:13px;"><i class="fas fa-photo-video" style="color:var(--accent-blue);"></i> Media &amp; File Fields</td></tr>
                                    <tr><td>15</td><td><strong>File Upload</strong></td><td><i class="fas fa-upload" style="color:var(--accent-blue);"></i></td><td>Upload any file (images, PDFs, docs). Configurable file type filter and max file size. Shows preview for images.</td></tr>
                                    <tr><td>16</td><td><strong>Signature Pad</strong></td><td><i class="fas fa-signature" style="color:var(--accent-blue);"></i></td><td>Draw your signature with mouse or finger (touch screens). Saves as PNG image.</td></tr>
                                    <tr><td>17</td><td><strong>Camera Capture</strong></td><td><i class="fas fa-camera" style="color:var(--accent-blue);"></i></td><td>Opens the device camera directly in the form. Take a photo and it gets attached to the submission.</td></tr>
                                    <tr><td colspan="4" style="background:var(--bg-secondary);font-weight:600;font-size:13px;"><i class="fas fa-star" style="color:var(--accent-blue);"></i> Special Fields</td></tr>
                                    <tr><td>18</td><td><strong>Color Picker</strong></td><td><i class="fas fa-palette" style="color:var(--accent-blue);"></i></td><td>Visual color selector. Returns hex color code (e.g., #FF5733).</td></tr>
                                    <tr><td>19</td><td><strong>Range Slider</strong></td><td><i class="fas fa-sliders-h" style="color:var(--accent-blue);"></i></td><td>Slider input with configurable min, max, and step. Displays current value.</td></tr>
                                    <tr><td>20</td><td><strong>QR Code Scanner</strong></td><td><i class="fas fa-qrcode" style="color:var(--accent-blue);"></i></td><td>Opens the camera and scans QR codes. The decoded text is saved as the field value.</td></tr>
                                    <tr><td>21</td><td><strong>GPS Location</strong></td><td><i class="fas fa-map-marker-alt" style="color:var(--accent-blue);"></i></td><td>Captures the user's current GPS coordinates with accuracy indicator. Links to Google Maps.</td></tr>
                                    <tr><td>22</td><td><strong>Appointment Slot</strong></td><td><i class="fas fa-calendar-check" style="color:var(--accent-blue);"></i></td><td>A complete appointment booking system inside your form. Configurable time slots, working hours, break times, days off, holidays, and max bookings.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Special Features Explained -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-amber"><i class="fas fa-magic"></i></div>
                        <div>
                            <div class="header-title">Special Features Explained</div>
                            <div class="header-subtitle">Powerful features that make this form builder stand out</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="mb-24">These features make the Form Builder way more powerful than your typical form tool. Here's how each one works in detail.</p>

                        <!-- AI -->
                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-robot" style="color:#8e24aa;width:24px;"></i> AI-Powered Form Generation</h3>
                        <div style="padding-left:28px;margin-bottom:20px;">
                            <p>Instead of adding fields one by one, you can describe your form in plain English and the AI builds it for you. It uses <strong>Google Gemini AI</strong> to understand your request and generate all the fields with the correct types, labels, icons, validations, and even chart assignments.</p>
                            <p style="margin-top:8px;"><strong>How to use it:</strong> In the form builder, click the <strong>"AI Build"</strong> tab. Type something like <em>"I need a form for hotel booking with guest name, email, phone, room type dropdown, check-in date, check-out date, number of guests, and special requests"</em>. The AI will generate all 8 fields with proper field types, icons, and settings.</p>
                            <p style="margin-top:8px;"><strong>AI Rewrite:</strong> If you already have fields but want to improve them, click "AI Rewrite". The AI will optimize field names, add missing icons, suggest better field types, and assign chart visualizations to relevant fields.</p>
                        </div>

                        <!-- Dependent Dropdowns -->
                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-link" style="color:#e65100;width:24px;"></i> Dependent Dropdowns</h3>
                        <div style="padding-left:28px;margin-bottom:20px;">
                            <p>Link two dropdown fields together so the child field's options change based on what the user selects in the parent field.</p>
                            <p style="margin-top:8px;"><strong>Example:</strong> "Vehicle Make" dropdown (Toyota, Honda, Ford). When user selects "Toyota", the "Vehicle Model" dropdown automatically shows Corolla, Camry, RAV4, etc.</p>
                        </div>

                        <!-- Appointment Slots -->
                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-calendar-check" style="color:#2e7d32;width:24px;"></i> Appointment Slot Booking</h3>
                        <div style="padding-left:28px;margin-bottom:20px;">
                            <p>A full appointment scheduling system built right into the form. Perfect for clinics, salons, consultations, or any service that needs time slots.</p>
                            <p style="margin-top:8px;"><strong>Configurable options:</strong> Slot duration, working hours, break time, days off, holidays, max bookings per slot, and advance booking days.</p>
                        </div>

                        <!-- Conditional Logic -->
                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-code-branch" style="color:#1565c0;width:24px;"></i> Conditional Logic</h3>
                        <div style="padding-left:28px;margin-bottom:20px;">
                            <p>Show or hide fields based on what the user selects in other fields. Makes forms smarter and shorter &mdash; users only see what's relevant to them.</p>
                            <p style="margin-top:8px;"><strong>Operators:</strong> Equals, Not Equals, Contains, Not Empty, Empty, Greater Than, Less Than.</p>
                        </div>

                        <!-- Multi-Step -->
                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-layer-group" style="color:#6a1b9a;width:24px;"></i> Multi-Step Forms</h3>
                        <div style="padding-left:28px;margin-bottom:20px;">
                            <p>Break long forms into multiple steps with a visual progress bar. Makes complex forms less overwhelming for users.</p>
                        </div>

                        <!-- QR Code -->
                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-qrcode" style="color:#8e24aa;width:24px;"></i> QR Code Form Sharing</h3>
                        <div style="padding-left:28px;margin-bottom:20px;">
                            <p>Every public form gets a QR code that you can share, download as PNG, or print. People just scan it with their phone camera and the form opens directly.</p>
                        </div>

                        <!-- Save Progress -->
                        <h3 style="margin:0 0 8px;font-size:15px;color:var(--text-primary);"><i class="fas fa-save" style="color:#2e7d32;width:24px;"></i> Save Progress (Draft Saving)</h3>
                        <div style="padding-left:28px;margin-bottom:0;">
                            <p>Users can save their form progress and come back later to finish it. The draft is saved automatically in the browser's local storage with a 2-second debounce. Works on both admin and public forms.</p>
                        </div>
                    </div>
                </div>

                <!-- Data Management -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-blue"><i class="fas fa-table"></i></div>
                        <div>
                            <div class="header-title">Data Management</div>
                            <div class="header-subtitle">Everything you can do with your form submissions</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="mb-24">Once your form is set up and people start submitting data, here's what you can do with it.</p>

                        <ul class="about-features">
                            <li><i class="fas fa-table"></i> <strong>DataTables View</strong> &mdash; All submissions appear in a searchable, sortable table with pagination</li>
                            <li><i class="fas fa-search"></i> <strong>Global Search</strong> &mdash; Type anything to search across all fields instantly</li>
                            <li><i class="fas fa-sort"></i> <strong>Column Sorting</strong> &mdash; Click any column header to sort ascending/descending</li>
                            <li><i class="fas fa-file-csv"></i> <strong>CSV Export</strong> &mdash; Download all records as a CSV spreadsheet</li>
                            <li><i class="fas fa-file-pdf"></i> <strong>PDF Export</strong> &mdash; Export records as a formatted PDF document</li>
                            <li><i class="fas fa-print"></i> <strong>Print</strong> &mdash; Print records directly from the browser</li>
                            <li><i class="fas fa-file-pdf"></i> <strong>Individual PDF</strong> &mdash; Download any single record as a formatted PDF</li>
                            <li><i class="fas fa-plus-circle"></i> <strong>Add Records</strong> &mdash; Add new submissions from within the dashboard</li>
                            <li><i class="fas fa-edit"></i> <strong>Edit Records</strong> &mdash; Update any field in any existing record</li>
                            <li><i class="fas fa-trash-alt"></i> <strong>Delete Records</strong> &mdash; Remove individual records with confirmation dialog</li>
                            <li><i class="fas fa-chart-pie"></i> <strong>Charts &amp; Analytics</strong> &mdash; Visual data breakdowns using Bar, Pie, Doughnut, Line, and Polar Area charts</li>
                            <li><i class="fas fa-expand-arrows-alt"></i> <strong>Responsive</strong> &mdash; Tables work perfectly on mobile with responsive collapsing</li>
                            <li><i class="fas fa-moon"></i> <strong>Dark Mode</strong> &mdash; Full dark mode support across all views</li>
                        </ul>
                    </div>
                </div>

                <!-- Core Dashboard Features -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-pink"><i class="fas fa-clipboard-list"></i></div>
                        <div>
                            <div class="header-title">Core Dashboard Features</div>
                            <div class="header-subtitle">Complete feature list of the dashboard system</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <ul class="about-features">
                            <li><i class="fas fa-lock"></i> <strong>User Authentication</strong> &mdash; Login, Signup, OTP Email Verification, Forgot Password, Remember Me</li>
                            <li><i class="fab fa-google"></i> <strong>Google OAuth</strong> &mdash; Login with Google account (configurable)</li>
                            <li><i class="fas fa-tachometer-alt"></i> <strong>Admin Dashboard</strong> &mdash; Overview with user counts, activity stats, recent logins</li>
                            <li><i class="fas fa-users-cog"></i> <strong>User Management</strong> &mdash; Add, edit, delete users. Assign roles</li>
                            <li><i class="fas fa-history"></i> <strong>Activity Logs</strong> &mdash; Full audit trail of who did what and when</li>
                            <li><i class="fas fa-bell"></i> <strong>Notifications</strong> &mdash; Real-time notification bell with unread count</li>
                            <li><i class="fas fa-palette"></i> <strong>Theme Customization</strong> &mdash; Light / Dark mode toggle, works across all pages</li>
                            <li><i class="fas fa-envelope"></i> <strong>SMTP Email</strong> &mdash; Configure SMTP settings for sending emails</li>
                            <li><i class="fas fa-cog"></i> <strong>Site Settings</strong> &mdash; Customize site name, logo, copyright, currency, and more</li>
                            <li><i class="fas fa-desktop"></i> <strong>Session Management</strong> &mdash; View active sessions, automatic timeout after inactivity</li>
                            <li><i class="fas fa-database"></i> <strong>Database Backup</strong> &mdash; Download full database backup (Admin only)</li>
                            <li><i class="fas fa-tools"></i> <strong>Maintenance Mode</strong> &mdash; Take the system offline for maintenance (Admin only)</li>
                            <li><i class="fas fa-language"></i> <strong>Google Translate</strong> &mdash; Built-in language translation for multi-language support</li>
                            <li><i class="fas fa-shield-alt"></i> <strong>Security</strong> &mdash; CSRF protection, rate limiting, login attempt tracking, secure headers</li>
                            <li><i class="fas fa-mobile-alt"></i> <strong>Mobile Responsive</strong> &mdash; Fully optimized for all screen sizes with hamburger menu</li>
                        </ul>
                    </div>
                </div>

                <!-- User Roles -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-blue"><i class="fas fa-users-cog"></i></div>
                        <div>
                            <div class="header-title">User Roles &amp; Permissions</div>
                            <div class="header-subtitle">What each role can do in the system</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="mb-24">The system has different user roles. Each role has different access levels. Here's what's allowed for each role.</p>

                        <div class="about-table-wrapper">
                            <table class="about-roles-table">
                                <thead>
                                    <tr>
                                        <th>Feature / Action</th>
                                        <th><span class="role-badge role-admin">Admin</span></th>
                                        <th><span class="role-badge role-sf">User</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="3" style="background:var(--bg-secondary);font-weight:600;font-size:13px;"><i class="fas fa-tachometer-alt" style="color:var(--accent-blue);"></i> Dashboard &amp; System</td></tr>
                                    <tr><td>View Admin Dashboard</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>Manage All Users</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>View / Edit Own Profile</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> Yes</td></tr>
                                    <tr><td>Change Own Password</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> Yes</td></tr>
                                    <tr><td>View Activity Logs</td><td><i class="fas fa-check-circle text-success"></i> All Users</td><td><i class="fas fa-check-circle text-success"></i> Own Only</td></tr>
                                    <tr><td>Site Settings &amp; Branding</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>SMTP / OAuth Configuration</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>Session Management</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>Database Backup &amp; Restore</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>Maintenance Mode</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td colspan="3" style="background:var(--bg-secondary);font-weight:600;font-size:13px;"><i class="fas fa-wand-magic-sparkles" style="color:var(--accent-blue);"></i> CRUD Form Builder</td></tr>
                                    <tr><td>Create / Edit / Delete Forms</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>AI Form Generation</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>Configure Form Settings</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>Duplicate Forms</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-times-circle text-danger"></i> No</td></tr>
                                    <tr><td>View Form Submissions</td><td><i class="fas fa-check-circle text-success"></i> All Forms</td><td><i class="fas fa-check-circle text-success"></i> Allowed Forms</td></tr>
                                    <tr><td>Add Records</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> If Form Allows</td></tr>
                                    <tr><td>Edit Records</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> If Role Assigned</td></tr>
                                    <tr><td>Delete Records</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> If Role Assigned</td></tr>
                                    <tr><td>Export Data (CSV / PDF / Print)</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> Yes</td></tr>
                                    <tr><td>Submit Public Forms</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> Yes (No Login Needed)</td></tr>
                                    <tr><td>Share Forms via QR Code</td><td><i class="fas fa-check-circle text-success"></i> Yes</td><td><i class="fas fa-check-circle text-success"></i> Public Forms Only</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tech Stack -->
                <div class="about-card">
                    <div class="card-header">
                        <div class="header-icon header-icon-blue"><i class="fas fa-laptop-code"></i></div>
                        <div>
                            <div class="header-title">Tech Stack</div>
                            <div class="header-subtitle">Technologies and libraries used to build this system</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="about-table-wrapper">
                            <table class="about-roles-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Technology</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td><strong>Backend</strong></td><td>PHP 8+ with MySQLi (Prepared Statements)</td></tr>
                                    <tr><td><strong>Database</strong></td><td>MySQL / MariaDB</td></tr>
                                    <tr><td><strong>Frontend</strong></td><td>HTML5, CSS3, JavaScript</td></tr>
                                    <tr><td><strong>UI Framework</strong></td><td>Custom Navy Blue CSS Theme (Light + Dark Mode)</td></tr>
                                    <tr><td><strong>Data Tables</strong></td><td>DataTables v1.13.7 (Search, Sort, Pagination, Export)</td></tr>
                                    <tr><td><strong>Charts</strong></td><td>Chart.js 4.4.0 (Bar, Pie, Doughnut, Line, Polar Area)</td></tr>
                                    <tr><td><strong>Icons</strong></td><td>Font Awesome 6.5.1</td></tr>
                                    <tr><td><strong>Alerts</strong></td><td>SweetAlert2 (All notifications and confirmations)</td></tr>
                                    <tr><td><strong>QR Codes</strong></td><td>QRCode.js (Generate), jsQR (Scan)</td></tr>
                                    <tr><td><strong>Drag &amp; Drop</strong></td><td>SortableJS</td></tr>
                                    <tr><td><strong>PDF Generation</strong></td><td>pdfmake + JSZip</td></tr>
                                    <tr><td><strong>AI</strong></td><td>Google Gemini API (Form Generation &amp; Rewriting)</td></tr>
                                    <tr><td><strong>Server</strong></td><td>Apache (XAMPP / LAMP / WAMP compatible)</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Developer -->
                <div class="about-card about-developer">
                    <div class="card-header">
                        <div class="header-icon header-icon-purple"><i class="fas fa-code"></i></div>
                        <div>
                            <div class="header-title">About the Developer</div>
                            <div class="header-subtitle">Who built this system</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="developer-info">
                            <img src="https://raw.githubusercontent.com/ASK-US-Youth/Ask-Us/main/WIN_20240507_08_59_35_Pro.jpg" alt="Muhafiz Ahmed" class="developer-avatar">
                            <div class="developer-details">
                                <h3>Muhafiz Ahmed</h3>
                                <p class="developer-brand">Muhammed Computer Technology</p>
                                <p>I build custom web apps, dashboards, PHP/MySQL solutions, and automation tools. If you need something built for your business or project, feel free to reach out! I do custom projects for any kind of requirement.</p>
                                <div class="developer-links">
                                    <a href="https://wa.me/+251953115473" target="_blank" class="dev-link whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp Me</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="about-footer">
                    <p>Made with <i class="fas fa-heart text-danger"></i> by Muhammed Computer Technology</p>
                    <p class="about-version">Version 3.0.0</p>
                </div>

            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
    $(document).ready(function() {
        // Remove initial hidden class
        document.body.classList.remove('initially-hidden');

        console.log('🚀 About page loaded successfully');
    });
    </script>
</body>
</html>