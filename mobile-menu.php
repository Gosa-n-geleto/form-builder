<?php


// Get current page from the including file
$mobile_current = isset($current_page) ? $current_page : '';
$mobile_role = isset($role) ? $role : 'User';
$mobile_tab = isset($_GET['tab']) ? $_GET['tab'] : '';

// Check if current page is a CRUD form
$mobile_is_crud = strpos($mobile_current, 'crud_') === 0;
?>

<!-- Mobile Bottom Navigation Bar -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
    <?php if ($mobile_role === 'Admin'): ?>
    <a href="dashboard.php" class="mobile-nav-item <?php echo $mobile_current === 'dashboard' ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i>
        <span>Dashboard</span>
    </a>
    <a href="users.php" class="mobile-nav-item <?php echo $mobile_current === 'users' ? 'active' : ''; ?>">
        <i class="fas fa-users"></i>
        <span>Users</span>
    </a>
    <a href="settings.php" class="mobile-nav-item <?php echo $mobile_current === 'settings' ? 'active' : ''; ?>">
        <i class="fas fa-cog"></i>
        <span>Settings</span>
    </a>
    <?php else: ?>
    <a href="account.php" class="mobile-nav-item <?php echo ($mobile_current === 'account' && $mobile_tab !== 'settings') ? 'active' : ''; ?>">
        <i class="fas fa-user-circle"></i>
        <span>Account</span>
    </a>
    <a href="logs.php" class="mobile-nav-item <?php echo $mobile_current === 'logs' ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        <span>Logs</span>
    </a>
    <a href="account.php?tab=settings" class="mobile-nav-item <?php echo ($mobile_current === 'account' && $mobile_tab === 'settings') ? 'active' : ''; ?>">
        <i class="fas fa-cog"></i>
        <span>Settings</span>
    </a>
    <?php endif; ?>

    <button class="mobile-nav-item mobile-nav-more" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
        <span>More</span>
    </button>
</nav>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

<script>
/**
 * Mobile Menu Functions
 */
function toggleMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');

        if (sidebar.classList.contains('mobile-open')) {
            document.body.style.overflow = 'hidden';
            document.body.style.position = 'fixed';
            document.body.style.width = '100%';
            document.body.style.top = `-${window.scrollY}px`;
        } else {
            const scrollY = document.body.style.top;
            document.body.style.overflow = '';
            document.body.style.position = '';
            document.body.style.width = '';
            document.body.style.top = '';
            window.scrollTo(0, parseInt(scrollY || '0') * -1);
        }
    }
}

function closeMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar && sidebar.classList.contains('mobile-open')) {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');

        const scrollY = document.body.style.top;
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
        window.scrollTo(0, parseInt(scrollY || '0') * -1);
    }
}

// Close mobile menu when clicking a link in sidebar
document.addEventListener('DOMContentLoaded', function() {
    const sidebarLinks = document.querySelectorAll('.sidebar-menu a:not(.submenu-toggle)');
    sidebarLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            closeMobileMenu();
        });
    });

    // Close menu on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });

    // Handle swipe to close sidebar
    let touchStartX = 0;
    let touchEndX = 0;
    const sidebar = document.getElementById('sidebar');

    if (sidebar) {
        sidebar.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        sidebar.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
    }

    function handleSwipe() {
        const swipeThreshold = 50;
        if (touchStartX - touchEndX > swipeThreshold) {
            // Swipe left - close sidebar
            closeMobileMenu();
        }
    }

    // Prevent body scroll when touching sidebar overlay
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('touchmove', function(e) {
            e.preventDefault();
        }, { passive: false });
    }
});

// Handle orientation change
window.addEventListener('orientationchange', function() {
    // Small delay to let the browser adjust
    setTimeout(function() {
        if (window.innerWidth > 768) {
            closeMobileMenu();
        }
    }, 100);
});

// Handle resize - close mobile menu if window becomes larger
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) {
            sidebar.classList.remove('mobile-open');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
    }
});
</script>
