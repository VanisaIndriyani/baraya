    </main>
</div>

<?php if (!empty($_SESSION['user_id']) && isset($base_url)): ?>
<button type="button" class="asisten-tombol" id="asistenBuka" aria-label="Buka pesan asisten">
    <i class="bi bi-chat-dots-fill"></i>
</button>
<section class="asisten-panel d-none" id="asistenPanel" aria-label="Asisten catatan">
    <div class="asisten-head">
        <div>
            <div class="fw-bold">Asisten Baraya</div>
            <div class="small" style="color:#FDE68A;">ChatGPT. Tanya bebas, atau catat pendapatan dan pengeluaran.</div>
        </div>
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm text-white" id="asistenHapus" aria-label="Hapus riwayat chat" title="Hapus riwayat"><i class="bi bi-trash"></i></button>
            <button type="button" class="btn btn-sm text-white" id="asistenTutup" aria-label="Tutup"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <div class="asisten-pesan" id="asistenPesan"></div>
    <form class="asisten-form" id="asistenForm">
        <input type="text" class="form-control" id="asistenInput" placeholder="pendapatan 500rb pengeluaran 30rb" autocomplete="off">
        <button class="asisten-kirim" type="submit" aria-label="Kirim"><i class="bi bi-send-fill"></i></button>
    </form>
</section>
<script>
(function () {
    const url = <?php echo json_encode($base_url . '/api/asisten.php'); ?>;
    const panel = document.getElementById('asistenPanel');
    const kotak = document.getElementById('asistenPesan');
    const input = document.getElementById('asistenInput');
    const form = document.getElementById('asistenForm');
    const kunci = 'baraya-asisten-pesan';
    let pesan = [];
    try { pesan = JSON.parse(sessionStorage.getItem(kunci) || '[]'); } catch (e) { pesan = []; }
    if (sessionStorage.getItem('baraya-asisten-buka') === '1') {
        panel.classList.remove('d-none');
        sessionStorage.removeItem('baraya-asisten-buka');
    }

    function gambar() {
        kotak.innerHTML = '';
        if (!pesan.length) {
            const awal = document.createElement('div');
            awal.className = 'asisten-balon bot';
            awal.textContent = 'Tanya bebas, misalnya pendapatan hari ini berapa.\nAtau catat: pendapatan 500rb pengeluaran 30rb.';
            kotak.appendChild(awal);
            ['Pendapatan hari ini berapa', 'Pengeluaran hari ini berapa', 'Sisa stok'].forEach(function (teks) {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'asisten-chip';
                chip.textContent = teks;
                chip.addEventListener('click', function () { kirim(teks); });
                kotak.appendChild(chip);
            });
            return;
        }
        pesan.forEach(function (item) {
            const el = document.createElement('div');
            el.className = 'asisten-balon ' + (item.dari === 'user' ? 'user' : 'bot');
            el.textContent = item.teks;
            kotak.appendChild(el);
        });
        kotak.scrollTop = kotak.scrollHeight;
    }

    function simpanLokal() {
        sessionStorage.setItem(kunci, JSON.stringify(pesan.slice(-30)));
    }

    async function kirim(teks) {
        teks = (teks || '').trim();
        if (!teks) return;
        pesan.push({ dari: 'user', teks: teks });
        simpanLokal();
        gambar();
        input.value = '';
        const tunggu = document.createElement('div');
        tunggu.className = 'asisten-balon bot';
        tunggu.textContent = 'Sebentar...';
        kotak.appendChild(tunggu);
        kotak.scrollTop = kotak.scrollHeight;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    pesan: teks,
                    riwayat: pesan.slice(0, -1).slice(-8)
                })
            });
            const data = await res.json();
            pesan.push({ dari: 'bot', teks: data.teks || 'Tidak ada jawaban.' });
            simpanLokal();
            gambar();
            if (data.simpan) {
                sessionStorage.setItem('baraya-asisten-buka', '1');
                setTimeout(function () { window.location.reload(); }, 900);
            }
        } catch (e) {
            pesan.push({ dari: 'bot', teks: 'Koneksi gagal. Coba lagi.' });
            simpanLokal();
            gambar();
        }
    }

    document.getElementById('asistenBuka').addEventListener('click', function () {
        panel.classList.toggle('d-none');
        if (!panel.classList.contains('d-none')) input.focus();
    });
    document.getElementById('asistenTutup').addEventListener('click', function () {
        panel.classList.add('d-none');
    });
    document.getElementById('asistenHapus').addEventListener('click', function () {
        pesan = [];
        sessionStorage.removeItem(kunci);
        gambar();
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        kirim(input.value);
    });
    gambar();
})();
</script>
<?php endif; ?>

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
