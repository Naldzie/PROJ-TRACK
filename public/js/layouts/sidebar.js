// ============================================================================
// Layouts — responsive sidebar toggle (shared by app / stafflayouts /
// contractorlayouts). Works with public/css/layouts/responsive.css.
// ============================================================================

function toggleSidebar() {
    document.body.classList.toggle('sidebar-open');
}

function closeSidebar() {
    document.body.classList.remove('sidebar-open');
}

document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector('.sidebar');

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', closeSidebar);
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });

    // If the viewport grows past the mobile breakpoint, reset the open state
    // so the fixed sidebar isn't left mid-transition.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 1023.98) closeSidebar();
    });
});
