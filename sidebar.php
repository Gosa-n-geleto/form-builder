<?php
/**
 * Developed by Gosa Negeso
 * WhatsApp: https://wa.me/953115473 (For Custom Projects)
 *
 * Shared Sidebar Component
 * Include this file in all dashboard pages
 *
 * Required variables before including:
 * - $username: Current logged-in username
 * - $role: Current user role (Admin/User)
 * - $current_page: Current page identifier (dashboard/users/logs/account/settings)
 * - $user_id: Current user ID
 */

if (!isset($username) || !isset($role) || !isset($current_page) || !isset($user_id)) {
    die('Sidebar requires $username, $role, $current_page, and $user_id variables');
}

// Get site branding logo (from system settings)
$branding = getSiteBranding();
$site_logo = $branding['site_logo'] ?? '';
$site_name = $branding['site_name'] ?? 'Dashboard';

// Get user's profile image for the user avatar (small profile picture)
$profile_image = getProfileImage($user_id);
$default_avatar = "https://ui-avatars.com/api/?name=" . urlencode($username) . "&background=3b82f6&color=fff&size=64";
$avatar_src = $profile_image ? $profile_image : $default_avatar;

// For the main logo, use site logo or fallback
$default_logo = "https://raw.githubusercontent.com/ASK-US-Youth/Ask-Us/main/photo_2025-07-25_07-32-30.jpg";
$logo_src = !empty($site_logo) ? $site_logo : $default_logo;

// Check if any submenu item is active
$account_submenu_active = in_array($current_page, ['account', 'logs']);
$system_submenu_active = in_array($current_page, ['settings', 'oauth_setup', 'smtp_setup', 'sessions', 'backup', 'crud_settings']);

// Load dynamic CRUD forms for sidebar
$sidebar_crud_forms = [];
try {
    $sidebar_crud_forms = getCrudForms($role, isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);
} catch (Exception $e) {
    // Table may not exist yet
}
$crud_submenu_active = false;
foreach ($sidebar_crud_forms as $cf) {
    if ($current_page === 'crud_' . $cf['form_slug']) {
        $crud_submenu_active = true;
        break;
    }
}
// Get user's custom theme
$user_theme = getUserTheme($user_id);
// Get default language for Google Translate
$default_language = getDefaultLanguage();

// Output custom theme CSS
echo generateUserThemeCSS($user_id);
?>

<style>
/* ============================================================
   GLOBAL THEME VARIABLES - DARK MODE SUPPORT
   ============================================================ */

:root {
    /* Light Mode Colors (Default) */
    --bg-primary: #f8fafc;
    --bg-secondary: #ffffff;
    --bg-card: #ffffff;
    --bg-input: #f1f5f9;
    --bg-hover: #f1f5f9;
    
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #94a3b8;
    
    --border-color: #e2e8f0;
    --border-hover: #cbd5e1;
    
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
    --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
    
    --accent-blue: #3b82f6;
    --accent-green: #10b981;
    --accent-red: #ef4444;
    --accent-yellow: #f59e0b;
}

/* Dark Mode Colors */
body.dark-mode {
    --bg-primary: #0f172a;
    --bg-secondary: #1e293b;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --bg-hover: #334155;
    
    --text-primary: #f1f5f9;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;
    
    --border-color: #334155;
    --border-hover: #475569;
    
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.4);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.4);
    --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.4);
}

/* ============================================================
   SIDEBAR VARIABLES
   ============================================================ */

:root {
    --sidebar-width: 260px;
    --sidebar-collapsed-width: 68px;
    --sidebar-bg: #0d0d14;
    --sidebar-hover: rgba(255,255,255,0.05);
    --sidebar-active: rgba(59, 130, 246, 0.12);
    --sidebar-text: #94a3b8;
    --sidebar-text-hover: #f1f5f9;
    --sidebar-border: rgba(255,255,255,0.06);
    --sidebar-shadow: 0 0 40px rgba(0,0,0,0.4);
    --sidebar-radius: 10px;
    --sidebar-transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Light Mode Sidebar Override */
body:not(.dark-mode) {
    --sidebar-bg: #ffffff;
    --sidebar-hover: rgba(0,0,0,0.04);
    --sidebar-active: rgba(59, 130, 246, 0.06);
    --sidebar-text: #64748b;
    --sidebar-text-hover: #0f172a;
    --sidebar-border: rgba(0,0,0,0.06);
    --sidebar-shadow: 0 0 40px rgba(0,0,0,0.06);
}

/* Dark Mode Sidebar Override */
body.dark-mode {
    --sidebar-bg: #0d0d14;
    --sidebar-hover: rgba(255,255,255,0.05);
    --sidebar-active: rgba(59, 130, 246, 0.12);
    --sidebar-text: #94a3b8;
    --sidebar-text-hover: #f1f5f9;
    --sidebar-border: rgba(255,255,255,0.06);
    --sidebar-shadow: 0 0 40px rgba(0,0,0,0.4);
}

/* ============================================================
   GLOBAL STYLES
   ============================================================ */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: background-color 0.4s ease, color 0.4s ease;
}

/* ============================================================
   APP WRAPPER
   ============================================================ */

.app-wrapper {
    display: flex;
    min-height: 100vh;
    position: relative;
}

/* ============================================================
   MAIN CONTENT OFFSET
   ============================================================ */

.app-container {
    flex: 1;
    margin-left: var(--sidebar-width);
    transition: margin-left var(--sidebar-transition), width var(--sidebar-transition);
    min-height: 100vh;
    width: calc(100% - var(--sidebar-width));
    background: var(--bg-primary);
}

/* When sidebar is collapsed */
.sidebar.collapsed ~ .app-container,
.sidebar.collapsed + .app-container {
    margin-left: var(--sidebar-collapsed-width);
    width: calc(100% - var(--sidebar-collapsed-width));
}

/* ============================================================
   MAIN CONTENT
   ============================================================ */

.main-content {
    flex: 1;
    padding: 20px 28px 28px;
    min-height: 100vh;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: background-color 0.4s ease, color 0.4s ease;
}

/* ============================================================
   SIDEBAR CONTAINER
   ============================================================ */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--sidebar-width);
    background: var(--sidebar-bg);
    border-right: 1px solid var(--sidebar-border);
    display: flex;
    flex-direction: column;
    z-index: 1000;
    transition: width var(--sidebar-transition), transform var(--sidebar-transition), background-color 0.4s ease, border-color 0.4s ease;
    overflow: hidden;
    box-shadow: var(--sidebar-shadow);
    flex-shrink: 0;
    height: 100vh;
    max-height: 100vh;
}

/* ============================================================
   SIDEBAR HEADER
   ============================================================ */

.sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    min-height: 56px;
    border-bottom: 1px solid var(--sidebar-border);
    flex-shrink: 0;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    overflow: hidden;
}

.sidebar-brand .brand-icon {
    width: 32px;
    height: 32px;
    border-radius: var(--sidebar-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff;
    flex-shrink: 0;
}

.sidebar-brand .brand-text {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    letter-spacing: -0.02em;
    white-space: nowrap;
    transition: opacity var(--sidebar-transition), color 0.4s ease;
}

.sidebar-brand .brand-text .highlight {
    color: #3b82f6;
}

.sidebar-toggle-btn {
    width: 30px;
    height: 30px;
    border-radius: var(--sidebar-radius);
    border: none;
    background: transparent;
    color: var(--sidebar-text);
    cursor: pointer;
    transition: all var(--sidebar-transition);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
}

.sidebar-toggle-btn:hover {
    background: var(--sidebar-hover);
    color: var(--sidebar-text-hover);
}

/* ============================================================
   SIDEBAR BRANDING
   ============================================================ */

.sidebar-branding {
    padding: 18px 18px 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
    border-bottom: 1px solid var(--sidebar-border);
    position: relative;
}

.sidebar-branding .logo-wrapper {
    position: relative;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    border: 3px solid var(--sidebar-border);
    background: var(--sidebar-bg);
    transition: all var(--sidebar-transition), border-color 0.4s ease;
    box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
}

.sidebar-branding .logo-wrapper:hover {
    border-color: #3b82f6;
    box-shadow: 0 0 30px rgba(59, 130, 246, 0.08);
    transform: scale(1.02);
}

.sidebar-branding .logo-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sidebar-branding .logo-wrapper .logo-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 700;
    color: var(--text-muted);
    background: var(--bg-input);
}

.sidebar-branding .org-name {
    margin-top: 12px;
    text-align: center;
    transition: all var(--sidebar-transition);
}

.sidebar-branding .org-name .name-main {
    font-size: 18px;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.02em;
    display: block;
    line-height: 1.2;
    transition: color 0.4s ease;
}

.sidebar-branding .org-name .name-main .highlight {
    color: #3b82f6;
}

.sidebar-branding .org-name .name-sub {
    font-size: 9px;
    font-weight: 500;
    color: var(--sidebar-text);
    letter-spacing: 0.04em;
    display: block;
    margin-top: 2px;
    line-height: 1.3;
    opacity: 0.7;
    text-transform: uppercase;
    transition: color 0.4s ease;
}

/* ============================================================
   USER PROFILE
   ============================================================ */

.sidebar-user {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    margin: 10px 12px 8px;
    border-radius: var(--sidebar-radius);
    background: var(--sidebar-hover);
    border: 1px solid var(--sidebar-border);
    transition: all var(--sidebar-transition), background-color 0.4s ease, border-color 0.4s ease;
    cursor: default;
    flex-shrink: 0;
}

.sidebar-user:hover {
    background: var(--sidebar-hover);
    border-color: var(--border-hover);
}

.sidebar-user .user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    border: 2px solid var(--sidebar-border);
}

.sidebar-user .user-info {
    flex: 1;
    min-width: 0;
}

.sidebar-user .user-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.4s ease;
}

.sidebar-user .user-role {
    font-size: 10px;
    color: var(--sidebar-text);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    display: flex;
    align-items: center;
    gap: 5px;
}

.sidebar-user .user-role .role-dot {
    display: inline-block;
    width: 5px;
    height: 5px;
    border-radius: 50%;
}

.sidebar-user .user-role .role-dot.admin {
    background: #3b82f6;
}

.sidebar-user .user-role .role-dot.user {
    background: #10b981;
}

/* ============================================================
   SIDEBAR MENU - SCROLLABLE
   ============================================================ */

.sidebar-menu-section {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 6px 10px 8px;
    min-height: 0;
    flex-shrink: 1;
}

.sidebar-menu-section::-webkit-scrollbar {
    width: 3px;
}
.sidebar-menu-section::-webkit-scrollbar-track {
    background: transparent;
}
.sidebar-menu-section::-webkit-scrollbar-thumb {
    background: var(--sidebar-text);
    border-radius: 4px;
}
.sidebar-menu-section::-webkit-scrollbar-thumb:hover {
    background: var(--text-secondary);
}

.sidebar-menu-title {
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sidebar-text);
    padding: 14px 12px 6px;
    opacity: 0.5;
    transition: opacity var(--sidebar-transition), color 0.4s ease;
}

.sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    position: relative;
}

/* ============================================================
   SIDEBAR MENU ITEMS
   ============================================================ */

.sidebar-menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: var(--sidebar-radius);
    color: var(--sidebar-text);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all var(--sidebar-transition), color 0.4s ease, background-color 0.4s ease;
    position: relative;
    white-space: nowrap;
    overflow: hidden;
}

.sidebar-menu a:hover {
    background: var(--sidebar-hover);
    color: var(--sidebar-text-hover);
}

.sidebar-menu a.active {
    background: var(--sidebar-active);
    color: #3b82f6;
}

.sidebar-menu a.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 20px;
    background: #3b82f6;
    border-radius: 0 4px 4px 0;
}

.sidebar-menu a i {
    width: 18px;
    text-align: center;
    font-size: 15px;
    flex-shrink: 0;
    transition: font-size var(--sidebar-transition);
}

.sidebar-menu a span {
    flex: 1;
    transition: opacity var(--sidebar-transition);
}

/* ============================================================
   SECTION DIVIDER
   ============================================================ */

.sidebar-divider {
    padding: 10px 12px 4px;
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sidebar-text);
    opacity: 0.3;
    cursor: default;
    user-select: none;
}

.sidebar-divider span {
    display: block;
    border-top: 1px solid var(--sidebar-border);
    padding-top: 8px;
}

/* ============================================================
   SUBMENU
   ============================================================ */

.sidebar-submenu {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.sidebar-submenu.open {
    max-height: 300px;
}

.sidebar-submenu li a {
    padding: 6px 12px 6px 42px;
    font-size: 12px;
    font-weight: 400;
}

.sidebar-submenu li a i {
    font-size: 13px;
    width: 16px;
}

.sidebar-submenu li a::before {
    display: none;
}

.submenu-toggle {
    cursor: pointer;
}

.submenu-arrow {
    font-size: 10px;
    transition: transform 0.3s ease;
    margin-left: auto;
}

.submenu-toggle.open .submenu-arrow {
    transform: rotate(180deg);
}

/* ============================================================
   TOOLTIP (Collapsed)
   ============================================================ */

.sidebar-menu li[data-tooltip] {
    position: relative;
}

.sidebar.collapsed .sidebar-menu li[data-tooltip]:hover::after {
    content: attr(data-tooltip);
    position: absolute;
    left: calc(var(--sidebar-collapsed-width) + 10px);
    top: 50%;
    transform: translateY(-50%);
    background: var(--bg-card);
    color: var(--text-primary);
    padding: 5px 14px;
    border-radius: var(--sidebar-radius);
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--border-color);
    z-index: 1001;
    pointer-events: none;
    animation: tooltipFade 0.15s ease;
}

@keyframes tooltipFade {
    from { opacity: 0; transform: translateY(-50%) scale(0.95); }
    to { opacity: 1; transform: translateY(-50%) scale(1); }
}

/* ============================================================
   SIDEBAR BOTTOM - ALWAYS VISIBLE
   ============================================================ */

.sidebar-bottom {
    flex-shrink: 0;
    border-top: 1px solid var(--sidebar-border);
    padding: 10px 10px 16px;
    background: var(--sidebar-bg);
    transition: background-color 0.4s ease, border-color 0.4s ease;
    position: relative;
    z-index: 2;
}

.sidebar-theme,
.sidebar-logout {
    padding: 2px 0;
}

.sidebar-theme button,
.sidebar-logout button {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 12px;
    border-radius: var(--sidebar-radius);
    border: none;
    background: transparent;
    color: var(--sidebar-text);
    font-size: 13px;
    font-weight: 500;
    font-family: inherit;
    cursor: pointer;
    transition: all var(--sidebar-transition), color 0.4s ease, background-color 0.4s ease;
}

.sidebar-theme button:hover,
.sidebar-logout button:hover {
    background: var(--sidebar-hover);
    color: var(--sidebar-text-hover);
}

.sidebar-theme button i,
.sidebar-logout button i {
    width: 18px;
    text-align: center;
    font-size: 15px;
    flex-shrink: 0;
}

.sidebar-logout button {
    color: var(--sidebar-text);
}

.sidebar-logout button:hover {
    color: #ef4444;
}

/* ============================================================
   FLOATING LOGOUT BUTTON - INSIDE SIDEBAR
   ============================================================ */

.floating-logout-btn {
    display: none;
    position: absolute;
    bottom: 20px;
    right: 20px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    border: none;
    box-shadow: 0 4px 20px rgba(239, 68, 68, 0.4);
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.floating-logout-btn:hover {
    transform: scale(1.1) rotate(-5deg);
    box-shadow: 0 8px 30px rgba(239, 68, 68, 0.5);
}

.floating-logout-btn:active {
    transform: scale(0.95);
}

.floating-logout-btn .tooltip-text {
    position: absolute;
    right: 66px;
    background: var(--bg-card);
    color: var(--text-primary);
    padding: 6px 14px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-color);
    opacity: 0;
    pointer-events: none;
    transition: all 0.3s ease;
}

.floating-logout-btn:hover .tooltip-text {
    opacity: 1;
}

/* ============================================================
   COLLAPSED STATE
   ============================================================ */

.sidebar.collapsed {
    width: var(--sidebar-collapsed-width);
}

.sidebar.collapsed .brand-text,
.sidebar.collapsed .sidebar-menu-title,
.sidebar.collapsed .sidebar-menu a span,
.sidebar.collapsed .sidebar-theme button span,
.sidebar.collapsed .sidebar-logout button span,
.sidebar.collapsed .sidebar-divider span,
.sidebar.collapsed .submenu-arrow,
.sidebar.collapsed .org-name,
.sidebar.collapsed .user-info {
    opacity: 0;
    width: 0;
    overflow: hidden;
    margin: 0;
    padding: 0;
    pointer-events: none;
}

.sidebar.collapsed .sidebar-menu a i {
    font-size: 18px;
}

.sidebar.collapsed .sidebar-menu a {
    justify-content: center;
    padding: 10px;
}

.sidebar.collapsed .sidebar-branding .logo-wrapper {
    width: 44px;
    height: 44px;
}

.sidebar.collapsed .sidebar-branding {
    padding: 12px 0 8px;
}

.sidebar.collapsed .sidebar-user {
    display: none !important;
}

.sidebar.collapsed .sidebar-menu a.active::before {
    left: auto;
    right: 0;
}

.sidebar.collapsed .sidebar-submenu {
    max-height: 0 !important;
}

.sidebar.collapsed .sidebar-theme button,
.sidebar.collapsed .sidebar-logout button {
    justify-content: center;
    padding: 10px;
}

.sidebar.collapsed .sidebar-header {
    padding: 14px 12px;
    justify-content: center;
}

/* SHOW EXPAND BUTTON WHEN COLLAPSED */
.sidebar.collapsed .sidebar-toggle-btn {
    display: flex !important;
    position: absolute;
    right: -12px;
    top: 50%;
    transform: translateY(-50%);
    background: var(--sidebar-bg);
    border: 1px solid var(--sidebar-border);
    border-radius: 50%;
    width: 24px;
    height: 24px;
    font-size: 10px;
    box-shadow: var(--sidebar-shadow);
}

.sidebar.collapsed .sidebar-toggle-btn:hover {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
}

/* Collapsed bottom actions */
.sidebar.collapsed .sidebar-bottom {
    padding: 8px 6px 12px;
}

/* Hide floating button when collapsed */
.sidebar.collapsed .floating-logout-btn {
    display: none !important;
}

/* ============================================================
   HAMBURGER BUTTON (Mobile)
   ============================================================ */

.hamburger-btn {
    display: none;
    position: fixed;
    top: 10px;
    left: 10px;
    z-index: 1001;
    width: 40px;
    height: 40px;
    border-radius: var(--sidebar-radius);
    border: none;
    background: var(--bg-card);
    color: var(--text-primary);
    font-size: 18px;
    cursor: pointer;
    transition: all var(--sidebar-transition), background-color 0.4s ease, color 0.4s ease;
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-color);
}

.hamburger-btn:hover {
    background: #3b82f6;
    color: #fff;
}

.hamburger-btn .hamburger-icon {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    width: 100%;
    height: 100%;
}

.hamburger-btn .hamburger-icon span {
    display: block;
    width: 18px;
    height: 2px;
    background: currentColor;
    border-radius: 2px;
    transition: all 0.3s ease;
}

.hamburger-btn.active .hamburger-icon span:nth-child(1) {
    transform: translateY(6px) rotate(45deg);
}

.hamburger-btn.active .hamburger-icon span:nth-child(2) {
    opacity: 0;
}

.hamburger-btn.active .hamburger-icon span:nth-child(3) {
    transform: translateY(-6px) rotate(-45deg);
}

/* ============================================================
   MOBILE OVERLAY
   ============================================================ */

.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.sidebar-overlay.active {
    opacity: 1;
}

/* ============================================================
   RESPONSIVE - FIXED MOBILE VISIBILITY
   ============================================================ */

@media (max-width: 1024px) {
    :root {
        --sidebar-width: 250px;
    }
}

@media (max-width: 768px) {
    :root {
        --sidebar-width: 280px;
        --sidebar-collapsed-width: 280px;
    }

    .hamburger-btn {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .sidebar {
        transform: translateX(-100%);
        width: var(--sidebar-width);
        border-radius: 0;
        box-shadow: var(--shadow-xl);
        height: 100vh;
        max-height: 100vh;
    }

    .sidebar.mobile-open {
        transform: translateX(0);
    }

    .sidebar.collapsed {
        width: var(--sidebar-width);
    }

    .sidebar.collapsed .brand-text,
    .sidebar.collapsed .sidebar-menu-title,
    .sidebar.collapsed .sidebar-menu a span,
    .sidebar.collapsed .sidebar-theme button span,
    .sidebar.collapsed .sidebar-logout button span,
    .sidebar.collapsed .sidebar-divider span,
    .sidebar.collapsed .submenu-arrow,
    .sidebar.collapsed .org-name,
    .sidebar.collapsed .user-info {
        opacity: 1;
        width: auto;
        overflow: visible;
        margin: inherit;
        padding: inherit;
        pointer-events: auto;
    }

    .sidebar.collapsed .sidebar-menu a {
        justify-content: flex-start;
        padding: 8px 12px;
    }

    .sidebar.collapsed .sidebar-menu a i {
        font-size: 15px;
    }

    .sidebar.collapsed .sidebar-branding .logo-wrapper {
        width: 70px;
        height: 70px;
    }

    .sidebar.collapsed .sidebar-branding {
        padding: 16px 18px 12px;
    }

    .sidebar.collapsed .sidebar-user {
        display: flex !important;
    }

    .sidebar.collapsed .sidebar-theme button,
    .sidebar.collapsed .sidebar-logout button {
        justify-content: flex-start;
        padding: 8px 12px;
    }

    .sidebar.collapsed .sidebar-header {
        padding: 14px 18px;
        justify-content: space-between;
    }

    .sidebar.collapsed .sidebar-toggle-btn {
        display: flex !important;
        position: static;
        transform: none;
        width: 30px;
        height: 30px;
        border-radius: var(--sidebar-radius);
        border: none;
        background: transparent;
        box-shadow: none;
    }

    .sidebar.collapsed .sidebar-menu a.active::before {
        left: 0;
        right: auto;
    }

    .sidebar.collapsed .sidebar-submenu {
        max-height: 0 !important;
    }

    .sidebar-overlay.active {
        display: block;
    }

    .app-container {
        margin-left: 0 !important;
        width: 100% !important;
    }
    
    .sidebar.collapsed ~ .app-container,
    .sidebar.collapsed + .app-container {
        margin-left: 0 !important;
        width: 100% !important;
    }

    /* ===== MOBILE SIDEBAR BOTTOM ===== */
    .sidebar-bottom {
        padding: 12px 12px 20px !important;
        border-top: 2px solid var(--sidebar-border);
        background: var(--sidebar-bg);
        flex-shrink: 0;
        position: relative;
        z-index: 10;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
    }

    .sidebar-theme button,
    .sidebar-logout button {
        padding: 14px 16px !important;
        font-size: 15px !important;
        border-radius: var(--radius-sm) !important;
        min-height: 50px !important;
    }

    .sidebar-logout button {
        background: rgba(239, 68, 68, 0.08) !important;
        color: #ef4444 !important;
        border: 1px solid rgba(239, 68, 68, 0.15) !important;
        font-weight: 600 !important;
    }

    .sidebar-logout button:hover {
        background: rgba(239, 68, 68, 0.15) !important;
        transform: scale(1.02);
    }

    .sidebar-logout button:active {
        transform: scale(0.98);
    }

    .sidebar-logout button i {
        font-size: 18px !important;
    }

    .sidebar-menu-section {
        flex: 1;
        overflow-y: auto;
        padding-bottom: 10px;
        min-height: 0;
        flex-shrink: 1;
    }

    .sidebar-menu {
        padding-bottom: 10px;
    }

    .sidebar {
        overflow: hidden;
    }

    /* ===== FLOATING LOGOUT BUTTON - VISIBLE ON MOBILE INSIDE SIDEBAR ===== */
    .floating-logout-btn {
        display: flex !important;
        width: 60px;
        height: 60px;
        bottom: 30px;
        right: 20px;
        font-size: 24px;
        box-shadow: 0 8px 32px rgba(239, 68, 68, 0.5);
    }

    .floating-logout-btn:hover {
        transform: scale(1.1);
    }
}

@media (max-width: 480px) {
    :root {
        --sidebar-width: 280px;
    }

    .hamburger-btn {
        top: 8px;
        left: 8px;
        width: 36px;
        height: 36px;
        font-size: 16px;
    }

    .sidebar-branding .logo-wrapper {
        width: 60px;
        height: 60px;
    }

    .sidebar-branding .org-name .name-main {
        font-size: 16px;
    }

    .sidebar-user .user-name {
        font-size: 12px;
    }

    .sidebar-bottom {
        padding: 10px 10px 16px !important;
    }

    .sidebar-theme button,
    .sidebar-logout button {
        padding: 12px 14px !important;
        font-size: 14px !important;
        min-height: 44px !important;
    }
    
    .sidebar-logout button i {
        font-size: 16px !important;
    }

    .floating-logout-btn {
        width: 54px;
        height: 54px;
        font-size: 20px;
        bottom: 24px;
        right: 16px;
    }
}

/* ============================================================
   SCROLLBAR STYLING
   ============================================================ */

.sidebar-menu-section {
    scrollbar-width: thin;
    scrollbar-color: var(--sidebar-text) transparent;
}

/* ============================================================
   ANIMATIONS
   ============================================================ */

@keyframes sidebarSlideIn {
    from { transform: translateX(-100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.sidebar.mobile-open {
    animation: sidebarSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* ============================================================
   THEME TRANSITION
   ============================================================ */

.sidebar,
.sidebar *,
body,
.main-content,
.app-container,
.hamburger-btn,
.floating-logout-btn {
    transition: background-color 0.4s ease,
                color 0.4s ease,
                border-color 0.4s ease,
                box-shadow 0.4s ease,
                width var(--sidebar-transition),
                transform var(--sidebar-transition);
}

/* ============================================================
   DARK MODE SPECIFIC - CARDS & COMPONENTS
   ============================================================ */

body.dark-mode .card,
body.dark-mode .panel,
body.dark-mode .widget {
    background: var(--bg-card);
    border-color: var(--border-color);
    color: var(--text-primary);
}

body.dark-mode .table {
    background: var(--bg-card);
    color: var(--text-primary);
}

body.dark-mode .table td,
body.dark-mode .table th {
    border-color: var(--border-color);
}

body.dark-mode .form-control,
body.dark-mode .input-group-text {
    background: var(--bg-input);
    border-color: var(--border-color);
    color: var(--text-primary);
}

body.dark-mode .form-control:focus {
    background: var(--bg-input);
    border-color: var(--accent-blue);
    color: var(--text-primary);
}

body.dark-mode .form-control::placeholder {
    color: var(--text-muted);
}

body.dark-mode .modal-content {
    background: var(--bg-card);
    color: var(--text-primary);
}

body.dark-mode .modal-header,
body.dark-mode .modal-footer {
    border-color: var(--border-color);
}

body.dark-mode .dropdown-menu {
    background: var(--bg-card);
    border-color: var(--border-color);
}

body.dark-mode .dropdown-item {
    color: var(--text-secondary);
}

body.dark-mode .dropdown-item:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}

body.dark-mode .nav-tabs .nav-link {
    color: var(--text-secondary);
}

body.dark-mode .nav-tabs .nav-link.active {
    background: var(--bg-card);
    color: var(--text-primary);
    border-color: var(--border-color);
}

body.dark-mode .nav-tabs {
    border-color: var(--border-color);
}

body.dark-mode .alert {
    background: var(--bg-input);
    color: var(--text-primary);
}

body.dark-mode .alert-success {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
}

body.dark-mode .alert-danger {
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
}

body.dark-mode .alert-warning {
    background: rgba(245, 158, 11, 0.15);
    color: #fbbf24;
}

body.dark-mode .alert-info {
    background: rgba(59, 130, 246, 0.15);
    color: #60a5fa;
}

body.dark-mode .floating-logout-btn {
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    box-shadow: 0 8px 32px rgba(239, 68, 68, 0.3);
}

body.dark-mode .floating-logout-btn .tooltip-text {
    background: var(--bg-card);
    color: var(--text-primary);
    border-color: var(--border-color);
}
</style>

<!-- ============================================================
   HTML STRUCTURE
   ============================================================ -->

<!-- HAMBURGER MENU BUTTON (Mobile) -->
<button class="hamburger-btn" id="hamburgerBtn" onclick="toggleMobileSidebar()" aria-label="Toggle Menu">
    <span class="hamburger-icon">
        <span></span>
        <span></span>
        <span></span>
    </span>
</button>

<!-- MOBILE OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <a href="dashboard.php" class="sidebar-brand">
            <div class="brand-icon">
                <i class="fas fa-tachometer-alt"></i>
            </div>
            <span class="brand-text">ASK <span class="highlight">US</span></span>
        </a>
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
    </div>
    
    <!-- Branding Section with Site Logo -->
    <div class="sidebar-branding">
        <div class="logo-wrapper">
            <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="<?php echo htmlspecialchars($site_name); ?> Logo" onerror="this.style.display='none';this.parentElement.querySelector('.logo-fallback').style.display='flex'">
            <div class="logo-fallback" style="display:none;">
                <span><?php echo substr($site_name, 0, 2); ?></span>
            </div>
        </div>
        <div class="org-name">
            <span class="name-main">ASK <span class="highlight">US</span></span>
            <span class="name-sub">Positive Children, Adolescents and Youth National Association</span>
        </div>
    </div>

    <!-- User Profile -->
    <div class="sidebar-user">
        <img src="<?php echo htmlspecialchars($avatar_src); ?>" alt="Profile" class="user-avatar" onerror="this.src='<?php echo $default_avatar; ?>'">
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
            <div class="user-role">
                <span class="role-dot <?php echo strtolower($role); ?>"></span>
                <?php echo htmlspecialchars($role); ?>
            </div>
        </div>
    </div>

    <!-- Menu - Scrollable -->
    <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Main</div>
        <ul class="sidebar-menu">
            <?php if ($role === 'Admin'): ?>
            <li data-tooltip="Dashboard">
                <a href="dashboard.php" class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($role === 'Admin'): ?>
            <li data-tooltip="Users">
                <a href="users.php" class="<?php echo $current_page === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
            </li>
            <?php endif; ?>

            <div class="sidebar-divider"><span>Forms</span></div>

            <?php if ($role === 'Admin'): ?>
            <li data-tooltip="CRUD Settings">
                <a href="crud_settings.php" class="<?php echo $current_page === 'crud_settings' ? 'active' : ''; ?>">
                    <i class="fas fa-tools"></i>
                    <span>CRUD Settings</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (count($sidebar_crud_forms) > 0): ?>
            <?php foreach ($sidebar_crud_forms as $cf): ?>
            <li data-tooltip="<?php echo htmlspecialchars($cf['form_name']); ?>">
                <a href="crud_view.php?form=<?php echo htmlspecialchars($cf['form_slug']); ?>" class="<?php echo $current_page === 'crud_' . $cf['form_slug'] ? 'active' : ''; ?>">
                    <i class="<?php echo htmlspecialchars($cf['form_icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($cf['form_name']); ?></span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="sidebar-divider"><span>Tools</span></div>

            <li data-tooltip="Send Email">
                <a href="email.php" class="<?php echo $current_page === 'email' ? 'active' : ''; ?>">
                    <i class="fas fa-paper-plane"></i>
                    <span>Send Email</span>
                </a>
            </li>

            <li class="has-submenu" data-tooltip="My Account">
                <a href="#" class="submenu-toggle <?php echo $account_submenu_active ? 'active' : ''; ?>" data-submenu="account-submenu">
                    <i class="fas fa-user-circle"></i>
                    <span>My Account</span>
                    <i class="fas fa-chevron-down submenu-arrow"></i>
                </a>

                <ul class="sidebar-submenu" id="account-submenu">
                    <li data-tooltip="My Profile">
                        <a href="account.php" class="<?php echo $current_page === 'account' ? 'active' : ''; ?>">
                            <i class="fas fa-user"></i>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li data-tooltip="Activity Logs">
                        <a href="logs.php" class="<?php echo $current_page === 'logs' ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i>
                            <span>Activity Logs</span>
                        </a>
                    </li>
                </ul>
            </li>

            <?php if ($role === 'Admin'): ?>
            <li class="has-submenu" data-tooltip="System">
                <a href="#" class="submenu-toggle <?php echo $system_submenu_active ? 'active' : ''; ?>" data-submenu="system-submenu">
                    <i class="fas fa-server"></i>
                    <span>System</span>
                    <i class="fas fa-chevron-down submenu-arrow"></i>
                </a>

                <ul class="sidebar-submenu" id="system-submenu">
                    <li data-tooltip="Site Settings">
                        <a href="settings.php" class="<?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                            <i class="fas fa-cog"></i>
                            <span>Site Settings</span>
                        </a>
                    </li>
                    <li data-tooltip="OAuth Setup">
                        <a href="oauth_setup.php" class="<?php echo $current_page === 'oauth_setup' ? 'active' : ''; ?>">
                            <i class="fab fa-google"></i>
                            <span>OAuth Setup</span>
                        </a>
                    </li>
                    <li data-tooltip="SMTP Setup">
                        <a href="smtp_setup.php" class="<?php echo $current_page === 'smtp_setup' ? 'active' : ''; ?>">
                            <i class="fas fa-envelope"></i>
                            <span>SMTP Setup</span>
                        </a>
                    </li>
                    <li data-tooltip="Sessions">
                        <a href="sessions.php" class="<?php echo $current_page === 'sessions' ? 'active' : ''; ?>">
                            <i class="fas fa-desktop"></i>
                            <span>Sessions</span>
                        </a>
                    </li>
                    <li data-tooltip="Backup">
                        <a href="backup.php" class="<?php echo $current_page === 'backup' ? 'active' : ''; ?>">
                            <i class="fas fa-database"></i>
                            <span>Backup</span>
                        </a>
                    </li>
                </ul>
            </li>
            <?php endif; ?>

            <li data-tooltip="About App">
                <a href="about.php" class="<?php echo $current_page === 'about' ? 'active' : ''; ?>">
                    <i class="fas fa-info-circle"></i>
                    <span>About App</span>
                </a>
            </li>
        </ul>
    </div>

    <div id="google_translate_element" class="notranslate" style="display:none;"></div>
    
    <!-- Bottom Actions - ALWAYS VISIBLE -->
    <div class="sidebar-bottom">
        <div class="sidebar-theme">
            <button onclick="toggleTheme()">
                <i class="fas fa-moon" id="themeIcon"></i>
                <span id="themeText">Dark Mode</span>
            </button>
        </div>
        <div class="sidebar-logout">
            <button onclick="confirmLogout()">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </div>
    </div>

    <!-- FLOATING LOGOUT BUTTON - INSIDE SIDEBAR (Visible on Mobile) -->
    <button class="floating-logout-btn" onclick="confirmLogout()" title="Logout">
        <i class="fas fa-sign-out-alt"></i>
        <span class="tooltip-text">Logout</span>
    </button>
</div>

<!-- CSRF Token for AJAX Requests -->
<script>
if (typeof jQuery !== 'undefined') {
    $.ajaxSetup({
        beforeSend: function(xhr, settings) {
            xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo generateCSRFToken(); ?>');
        }
    });
}
</script>

<!-- Sidebar JavaScript -->
<script>
/**
 * ============================================================
 * SIDEBAR - COMPLETE VERSION
 * Handles: Collapse, Expand, Mobile Toggle, Theme, Submenus
 * ============================================================
 */

// ===== THEME TOGGLE =====
function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const userThemeMode = '<?php echo $user_theme['theme_mode']; ?>';
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    let isDark = false;
    if (savedTheme) {
        isDark = savedTheme === 'dark';
    } else if (userThemeMode) {
        isDark = userThemeMode === 'dark';
        localStorage.setItem('theme', userThemeMode);
    } else {
        isDark = prefersDark;
    }

    if (isDark) {
        document.body.classList.add('dark-mode');
        document.body.classList.remove('light-mode');
        updateThemeButton(true);
    } else {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('light-mode');
        updateThemeButton(false);
    }
}

function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    document.body.classList.toggle('light-mode', !isDark);
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeButton(isDark);
    
    document.dispatchEvent(new CustomEvent('themeChanged', { detail: { isDark: isDark } }));
}

function updateThemeButton(isDark) {
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');

    if (icon && text) {
        if (isDark) {
            icon.className = 'fas fa-sun';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
}

initTheme();

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
        if (e.matches) {
            document.body.classList.add('dark-mode');
            document.body.classList.remove('light-mode');
            updateThemeButton(true);
        } else {
            document.body.classList.remove('dark-mode');
            document.body.classList.add('light-mode');
            updateThemeButton(false);
        }
    }
});

// ===== CONFIRM LOGOUT =====
function confirmLogout() {
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'logout.php';
    }
}

// ===== SIDEBAR COLLAPSE =====
function initSidebar() {
    const savedState = localStorage.getItem('sidebarCollapsed');
    const sidebar = document.getElementById('sidebar');
    const isCollapsed = savedState === 'true' && window.innerWidth > 768;
    
    if (isCollapsed) {
        sidebar.classList.add('collapsed');
        updateSidebarIcon(true);
    } else {
        sidebar.classList.remove('collapsed');
        updateSidebarIcon(false);
    }
    
    updateAppContainerMargin(isCollapsed);
}

function updateAppContainerMargin(isCollapsed) {
    const appContainer = document.querySelector('.app-container');
    if (!appContainer) return;
    
    if (window.innerWidth <= 768) {
        appContainer.style.marginLeft = '0';
        appContainer.style.width = '100%';
        return;
    }
    
    if (isCollapsed) {
        appContainer.style.marginLeft = 'var(--sidebar-collapsed-width)';
        appContainer.style.width = 'calc(100% - var(--sidebar-collapsed-width))';
    } else {
        appContainer.style.marginLeft = 'var(--sidebar-width)';
        appContainer.style.width = 'calc(100% - var(--sidebar-width))';
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    updateSidebarIcon(isCollapsed);
    updateAppContainerMargin(isCollapsed);
}

function updateSidebarIcon(isCollapsed) {
    const icon = document.getElementById('sidebarToggleIcon');
    if (icon) {
        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
}

// ===== MOBILE SIDEBAR TOGGLE =====
function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const hamburger = document.getElementById('hamburgerBtn');
    
    const isOpen = sidebar.classList.toggle('mobile-open');
    overlay.classList.toggle('active', isOpen);
    hamburger.classList.toggle('active', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const hamburger = document.getElementById('hamburgerBtn');
    
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('active');
    hamburger.classList.remove('active');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }
    }
});

// ===== WINDOW RESIZE HANDLER =====
let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
        const sidebar = document.getElementById('sidebar');
        const isCollapsed = sidebar.classList.contains('collapsed');
        updateAppContainerMargin(isCollapsed);
        
        if (window.innerWidth > 768) {
            closeMobileSidebar();
        }
    }, 100);
});

// ===== SIDEBAR SUBMENU TOGGLE =====
document.addEventListener('DOMContentLoaded', function() {
    const submenuToggles = document.querySelectorAll('.submenu-toggle');

    submenuToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();

            const submenuId = this.getAttribute('data-submenu');
            const submenu = document.getElementById(submenuId);

            if (!submenu) return;

            this.classList.toggle('open');
            submenu.classList.toggle('open');
        });
    });

    <?php if ($account_submenu_active): ?>
    const accountToggle = document.querySelector('[data-submenu="account-submenu"]');
    const accountSubmenu = document.getElementById('account-submenu');
    if (accountToggle && accountSubmenu) {
        accountToggle.classList.add('open');
        accountSubmenu.classList.add('open');
    }
    <?php endif; ?>

    <?php if ($system_submenu_active): ?>
    const systemToggle = document.querySelector('[data-submenu="system-submenu"]');
    const systemSubmenu = document.getElementById('system-submenu');
    if (systemToggle && systemSubmenu) {
        systemToggle.classList.add('open');
        systemSubmenu.classList.add('open');
    }
    <?php endif; ?>

    initSidebar();
});

// ===== GOOGLE TRANSLATE =====
(function() {
    const defaultLang = '<?php echo htmlspecialchars($default_language); ?>';
    
    function clearTranslateCookies() {
        var domains = ['', window.location.hostname, '.' + window.location.hostname];
        domains.forEach(function(domain) {
            var cookieStr = 'googtrans=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
            if (domain) cookieStr += ';domain=' + domain;
            document.cookie = cookieStr;
        });
    }

    function setTranslateCookie(lang) {
        clearTranslateCookies();
        if (lang && lang !== 'en') {
            var value = '/en/' + lang;
            document.cookie = 'googtrans=' + value + ';path=/';
            document.cookie = 'googtrans=' + value + ';path=/;domain=' + window.location.hostname;
            document.cookie = 'googtrans=' + value + ';path=/;domain=.' + window.location.hostname;
            return true;
        }
        return false;
    }

    if (defaultLang && defaultLang !== 'en') {
        clearTranslateCookies();
        setTranslateCookie(defaultLang);
        
        function loadGoogleTranslate() {
            if (typeof google !== 'undefined' && google.translate) return;
            
            window.googleTranslateElementInit = function() {
                try {
                    new google.translate.TranslateElement({
                        pageLanguage: 'en',
                        autoDisplay: false,
                        layout: google.translate.TranslateElement.InlineLayout.SIMPLE
                    }, 'google_translate_element');
                } catch(e) {}
            };

            var script = document.createElement('script');
            script.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
            script.async = true;
            document.head.appendChild(script);
        }

        if (!document.querySelector('script[src*="translate.google.com"]')) {
            loadGoogleTranslate();
        }
    } else {
        clearTranslateCookies();
    }
})();

function revertToEnglish() {
    var domains = ['', window.location.hostname, '.' + window.location.hostname];
    domains.forEach(function(domain) {
        var cookieStr = 'googtrans=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
        if (domain) cookieStr += ';domain=' + domain;
        document.cookie = cookieStr;
    });
    location.reload();
}

// ===== KEYBOARD SHORTCUTS =====
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        if (window.innerWidth <= 768) {
            toggleMobileSidebar();
        } else {
            toggleSidebar();
        }
    }
});

console.log('🚀 Sidebar loaded successfully');
console.log('⌨️ Shortcut: Ctrl+B (Cmd+B) to toggle sidebar');
</script>
