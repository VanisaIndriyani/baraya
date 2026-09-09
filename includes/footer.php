    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo $base_url; ?>/assets/js/app.js"></script>

<script>
// Sidebar Mobile Toggle
const openSidebar = document.getElementById('openSidebar');
const closeSidebar = document.getElementById('closeSidebar');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');

if (openSidebar && sidebar && overlay) {
    openSidebar.addEventListener('click', () => {
        sidebar.classList.add('show');
        overlay.classList.add('show');
    });
}

if (closeSidebar && sidebar && overlay) {
    closeSidebar.addEventListener('click', () => {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    });
}

if (overlay) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    });
}

// Close sidebar on menu click (mobile)
const menuItems = document.querySelectorAll('.menu-item');
menuItems.forEach(item => {
    item.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        }
    });
});
</script>
</body>
</html>
