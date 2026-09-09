// Base URL
function getBaseUrl() {
    const path = window.location.pathname;
    const parts = path.split('/');
    let base = '';
    for (let i = 0; i < parts.length; i++) {
        if (parts[i] === 'pages' || parts[i] === 'api') break;
        base += parts[i] + '/';
    }
    return base;
}

const BASE_URL = getBaseUrl();

// Helper: Show SweetAlert
function showAlert(type, title, text, callback = null) {
    const config = {
        icon: type,
        title: title,
        text: text,
        timer: type === 'success' ? 1500 : 3000,
        timerProgressBar: true
    };
    if (callback) {
        config.showConfirmButton = true;
        config.confirmButtonColor = '#1E3A8A';
    }
    Swal.fire(config).then(callback);
}

// Helper: Close modal by ID
function closeModal(modalId) {
    const modalEl = document.getElementById(modalId);
    if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    }
}

// Helper: Open modal by ID
function openModal(modalId) {
    const modalEl = document.getElementById(modalId);
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

// Generic Data Manager Class
class DataManager {
    constructor(options) {
        this.apiUrl = options.apiUrl;
        this.tableContainerId = options.tableContainerId;
        this.data = [];
        this.pagination = { page: 1, limit: 10, total: 0, pages: 0 };
        this.sort = { column: options.defaultSort || 'nama', order: 'ASC' };
        this.search = '';
        this.searchTimeout = null;
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadData();
    }

    bindEvents() {
        // Search
        const searchInput = document.getElementById(`${this.tableContainerId}-search`);
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                this.search = e.target.value;
                this.pagination.page = 1;
                this.searchTimeout = setTimeout(() => this.loadData(), 300);
            });
        }

        // Pagination buttons scoped per table manager
        const paginationPrefix = this.tableContainerId.replace(/-table$/, '');
        const prevBtn = document.getElementById(`${paginationPrefix}-prev`);
        const nextBtn = document.getElementById(`${paginationPrefix}-next`);

        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.prevPage());
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.nextPage());
        }

        // Sort headers
        const tableContainer = document.getElementById(this.tableContainerId);
        if (tableContainer) {
            tableContainer.addEventListener('click', (e) => {
                const th = e.target.closest('[data-sort]');
                if (th) {
                    this.handleSort(th.dataset.sort);
                }
            });
        }
    }

    async loadData() {
        try {
            const params = new URLSearchParams({
                search: this.search,
                page: this.pagination.page,
                limit: this.pagination.limit,
                sort: this.sort.column,
                order: this.sort.order
            });
            const response = await fetch(`${BASE_URL}${this.apiUrl}?${params}`);
            const result = await response.json();
            
            if (result.success) {
                this.data = result.data;
                this.pagination = result.pagination;
                this.renderTable();
            } else {
                showAlert('error', 'Gagal', result.message || 'Gagal memuat data');
            }
        } catch (error) {
            console.error(error);
            showAlert('error', 'Gagal', 'Terjadi kesalahan jaringan');
        }
    }

    handleSort(column) {
        if (this.sort.column === column) {
            this.sort.order = this.sort.order === 'ASC' ? 'DESC' : 'ASC';
        } else {
            this.sort.column = column;
            this.sort.order = 'ASC';
        }
        this.loadData();
    }

    prevPage() {
        if (this.pagination.page > 1) {
            this.pagination.page--;
            this.loadData();
        }
    }

    nextPage() {
        if (this.pagination.page < this.pagination.pages) {
            this.pagination.page++;
            this.loadData();
        }
    }

    renderTable() {
        // Override in subclass
        console.warn('renderTable not implemented');
    }

    async handleAction(action, id) {
        // Override in subclass
        console.warn('handleAction not implemented');
    }
}

// Sidebar Mobile Toggle
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle for mobile
    const openSidebarBtn = document.getElementById('openSidebar');
    const closeSidebarBtn = document.getElementById('closeSidebar');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');

    if (openSidebarBtn && sidebar && overlay) {
        openSidebarBtn.addEventListener('click', () => {
            sidebar.classList.add('show');
            overlay.classList.add('show');
        });
    }

    if (closeSidebarBtn && sidebar && overlay) {
        closeSidebarBtn.addEventListener('click', () => {
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
});
