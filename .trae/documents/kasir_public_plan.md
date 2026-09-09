# Kasir Publik CRUD Produk + Cetak Resi Implementation Plan

## Repository Research (Conclusion)

1. **Arsitektur Saat Ini**:
   - Tema global: **Maroon 3 level gradasi (#450A0A→#7F1D1D→#991B1B) + aksen Emas #FBBF24** → SEMUA halaman baru WAJIB match tema ini.
   - DB: PDO via `config/database.php` (lokal: `db_usaha_dawet` user root tanpa pw). Pattern auto-repair schema dengan `SHOW COLUMNS` + `ALTER TABLE` try/catch (dari pembelian.php) untuk anti-migration-manual.
   - Halaman admin pakai `includes/header.php` (sidebar maroon) + `includes/footer.php`. Array `$page_titles` di [header.php:L132-L143](file:///d:/APLIKASI/laragon/www/JULY/sistem_baru/includes/header.php#L132-L143) perlu ditambah 2 entry.
   - `login.php` sudah clean tanpa link public apapun. BISA ditambah 1 link-box baru DI BAWAH form login.
   - Folder `uploads/` BELUM ADA sama sekali. Perlu dibuat `uploads/produk_kasir/`.
   - Helper `includes/image-utils.php` sudah ada function `compressImage()` bisa dipakai.

2. **Requirement User (VERBATIM)**:
   - Menu kasir **PUBLIC (bisa diakses SEBELUM LOGIN)** = halaman standalone tanpa session check.
   - Admin punya menu **CRUD PRODUK** (cukup 3 field: nama, harga, gambar).
   - Produk muncul di halaman kasir publik.
   - Kasir bisa: pilih produk card → input qty → hitung total → input nominal bayar → HITUNG KEMBALIAN → **CETAK RESI** dengan branding header "**Es Teller & Dawet Baraya**".
   - **PENTING**: Kasir ini **GA ADA HUBUNGAN NYA** dengan menu keuangan/stok yang lain → 100% STANDALONE, tidak nyentuh tabel penjualan/pembelian/saldo_rekening SEDIKITPUN. Data hanya dipakai untuk kebutuhan cetak resi cepat di depan customer.

---

## Files and Modules (Plan)

| File/Path | Aksi | Apa yang diubah/dibuat |
|---|---|---|
| `uploads/produk_kasir/.htaccess` | **CREATE** | `Options -Indexes` biar folder ga bisa di-browse tapi gambar tetap accessible via URL |
| `kasir.php` (root level) | **CREATE** | Halaman KASIR PUBLIK (no login required). Layout: Header Brand + Grid Produk Card 4 kolom + Sidebar Keranjang (Qty, Total, Bayar, Kembalian) + Tombol Cetak Resi. Pakai style maroon-emas match tema. Cetak = fitur `window.print()` dengan CSS `@media print`. |
| `pages/produk_kasir.php` | **CREATE** | Halaman ADMIN CRUD Produk. Include header/footer. Fitur: Tabel produk list + Modal Tambah (nama, harga, upload gambar) + Modal Edit + Hapus konfirmasi Swal. Auto-repair schema tabel kasir_produk di awal. |
| `includes/header.php` | **EDIT** | 1. Tambah 2 entry ke `$page_titles` array: produk_kasir.php & kasir.php. 2. Tambah **Section baru "Menu Publik" di SIDEBAR** sebelum section "Uang & Keuangan": `Produk Kasir (CRUD)` icon bi-grid-1x2-fill dan `Buka Kasir Publik` icon bi-cash-register (buka tab baru). |
| `login.php` | **EDIT** | Tambah **1 link-box baru DI BAWAH form login** (setelah button submit): "Buka Halaman Kasir Publik" icon bi-cash-register warna maroon+emas rounded pill. NO require session. |
| `config/database.php` (optional) | **NO EDIT** | Tidak usah diubah. Pattern auto-repair schema dijalankan di `produk_kasir.php` top-of-page. |

---

## Implementation Steps (Dependency Order)

**Urutan kerjanya BOTTOM UP biar ga ada error "tabel ga ada" atau "404 folder":**

1. **Step 1 — Infrastructure Setup**
   - Create folder `uploads/produk_kasir/` + file `.htaccess` (Options -Indexes).
   - (Optional) `index.html` kosong di folder uploads.

2. **Step 2 — Create Admin CRUD (pages/produk_kasir.php)**
   - **TOP OF PAGE**: Function `kasirPastikanTabelProduk()` pattern auto-repair try/catch:
     ```
     SHOW COLUMNS FROM kasir_produk → jika tabel ga ada → CREATE TABLE:
       id INT PK AI,
       nama VARCHAR(255) NOT NULL,
       harga INT NOT NULL DEFAULT 0,
       gambar VARCHAR(255) DEFAULT NULL,
       urutan INT DEFAULT 0,
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP
     ```
   - Handle POST: `tambah_produk`, `edit_produk`, `hapus_produk` (pakai SweetAlert2 pattern `$GLOBALS['alert_script']` seperti pembelian).
   - Upload gambar: cek `$_FILES['gambar']`, pakai `compressImage` ke folder uploads/produk_kasir, max 2MB, accept jpg/png/webp. Hapus gambar lama pas edit/hapus.
   - UI: Header border atas 3px maroon, section atas ada 2 kolom: Card ringkasan (Total Produk) + Tombol "+ Tambah Produk" pill gradient maroon. Body: Grid responsive 2/3/4 kolom CARD PRODUK (gambar rounded atas, nama, harga, button edit/hapus) — karena ga banyak kolom, card lebih cocok dari tabel.

3. **Step 3 — Create Public Kasir Page (kasir.php di root)**
   - **NO SESSION CHECK** = 100% public. Include langsung Bootstrap CSS + Bootstrap Icons dari CDN (GA include header/footer admin biar ga ada sidebar).
   - Query SELECT semua produk ORDER BY urutan ASC, id DESC. Jika produk 0 → tampilkan card "Belum ada produk. Silakan login admin → tambah produk di Produk Kasir."
   - Layout KANAN-KIRI (desktop):
     - **KIRI 65%**: Header brand "Es Teller & Dawet Baraya" gradient maroon → grid 4 kolom card produk (gambar, nama, harga, qty stepper + button tambah ke keranjang / quick qty).
     - **KANAN 35% sticky**: Card Keranjang (sticky top-4) → list item produk + qty (bisa edit/hapus) → Divider → SUBTOTAL → Input "Nominal Bayar" auto-format ribuan → Card KEMBALIAN (update realtime dengan JS) → Tombol BESAR "CETAK RESI" gradient maroon-emas + shadow glow + border emas.
   - **CETAK RESI**: Ketika tombol Cetak ditekan → validasi dulu (total > 0, bayar >= total → jika kurang error). Jika lolos → generate HTML struk dalam hidden div dengan class `.receipt` → `window.print()` dengan CSS `@media print` (Sembunyikan SEMUA elemen, hanya `.receipt` yang ditampilkan). Struk format thermal 80mm:
     ```
     === ES TELLER & DAWET BARAYA ===
           Jl. [nomor, kota]
     --------------------------------
     TANGGAL: [dd-mm-yyyy HH:ii:ss]
     NO.STRUK: [KSR- + timestamp]
     --------------------------------
     [ITEM] @[HARGA] x[QTY]  = [SUB]
     ...
     --------------------------------
     TOTAL             Rp [FORMAT]
     BAYAR             Rp [FORMAT]
     KEMBALIAN         Rp [FORMAT]
     --------------------------------
       Terima Kasih Atas Kunjungannya
          #BarayaDawet #EsTeller
     ```
   - Mobile: Layout single column (Keranjang di atas produk, atau collapse accordion).

4. **Step 4 — Integrasi Sidebar Admin (includes/header.php)**
   - Update `$page_titles` array tambah: `'produk_kasir.php' => 'Kelola Produk Kasir'`
   - Tambah Section SIDEBAR baru bernama "**Menu Publik**" di ANTARA section "Transaksi Harian" dan "Uang & Keuangan":
     ```html
     <div class="menu-section">
         <div class="menu-label">Menu Publik</div>
         <a href=".../pages/produk_kasir.php" class="menu-item ...">
             <i class="bi bi-grid-1x2-fill"></i><span>Produk Kasir</span>
         </a>
         <a href=".../kasir.php" target="_blank" class="menu-item">
             <i class="bi bi-cash-register"></i><span>Buka Kasir <i class="bi bi-box-arrow-up-right ms-1 small"></i></span>
         </a>
     </div>
     ```
   - Link kasir.php pakai `target="_blank"` biar buka tab baru.

5. **Step 5 — Link di Login Page (login.php)**
   - Tepat DI BAWAH `</form>` (sebelum script tag), tambah div `.link-box` BARU warna maroon-emas dengan link ke kasir.php:
     ```
     Icon cash-register | "Buka Halaman Kasir Publik"
     ```
   - Gradient link-box: `rgba(153,27,27,0.08)` background, `rgba(251,191,36,0.45)` border dashed. Link warna `#7F1D1D`.

---

## Dependencies and Considerations

- **GA HUBUNGAN SAMA DATA LAIN**: 100% JANGAN ada referensi ke tabel penjualan, saldo_rekening, barang, pembelian. Semua transaksi di kasir.php HANYA di FRONTEND JS (tidak disimpan ke DB). Hanya tabel `kasir_produk` yang baru buat sebagai satu-satunya touchpoint DB.
- **SweetAlert2 di Admin**: pages/produk_kasir.php harus pakai pattern `$GLOBALS['alert_script']` seperti pembelian.php — karena CDN Swal di footer.php. **JANGAN echo Swal.fire() di tengah halaman** (akan Swal undefined).
- **Upload gambar**: cek ekstensi yang diizinkan (jpg/jpeg/png/webp), max 2MB. Resize otomatis width 800px dengan compressImage kualitas 80 biar ga makan storage. Nama file: `produk_[id]_[timestamp].[ext]` biar unique (no overwrite).
- **Session Check**: `pages/produk_kasir.php` → HARUS require session (include header.php otomatis cek). `kasir.php` → JANGAN require session apapun, public total.
- **Cache Buster**: Kalau edit CSS style.css, biarkan — kedua halaman baru pakai CSS inline sendiri (kasir.php ga include style.css admin, produk_kasir.php include yang sudah ada dengan v=20260909_MAROON → OK).
- **Format Rupiah JS**: Di kasir.js, buat fungsi `formatRupiah(number)` untuk tampilan text input dan list. Untuk input nominal bayar, pakai event input auto strip non-digit dan format ribuan realtime.

---

## Validation (Setelah Implementasi)

1. **GET DIAGNOSTICS**: Jalankan `GetDiagnostics` untuk:
   - `includes/header.php`
   - `login.php`
   - `pages/produk_kasir.php`
   - `kasir.php`
   → SEMUA harus return array kosong (0 PHP error).

2. **Manual Test Checklist**:
   - ✅ **Login Page**: Buka `http://localhost/JULY/sistem_baru/login.php` → ADA tombol/link "Buka Kasir Publik" di bawah form → klik → masuk halaman kasir.php tanpa perlu login.
   - ✅ **Sidebar Admin**: Login → Sidebar ADA section baru "Menu Publik" dengan 2 item → klik "Produk Kasir" masuk CRUD page. Klik "Buka Kasir" buka tab baru kasir.php.
   - ✅ **CRUD Produk (Admin)**:
     - Page pertama buka TANPA SQL error (auto-repair buat tabel otomatis).
     - Tambah produk: isi nama "Es Teller Original", harga 12000, upload gambar es_teler.jpg → submit → SWAL Berhasil muncul. Folder `uploads/produk_kasir/` terisi file.
     - Edit produk: ganti nama/harga/gambar → tersimpan, gambar lama terhapus.
     - Hapus produk: Swal konfirmasi → di-click → data terhapus, gambar juga terhapus dari folder.
   - ✅ **Kasir Publik (Tanpa Login)**:
     - Buka `http://localhost/JULY/sistem_baru/kasir.php` → SEMUA produk dari CRUD muncul sebagai card.
     - Click Tambah ke Keranjang → qty bisa diubah → subtotal update realtime.
     - Input nominal bayar KURANG dari total → tombol Cetak disabled / error Swal.
     - Input nominal bayar CUKUP → KEMBALIAN terhitung otomatis benar.
     - Klik Cetak Resi → jendela print muncul → HANYA struk yang tercetak, card produk dan keranjang HILANG dari print view. Struk header: "ES TELLER & DAWET BARAYA", ada tanggal, no struk, item list, total, bayar, kembalian, footer terima kasih.
   - ✅ **Isolasi Data**: Transaksi kasir GA merubah data apapun di tabel barang/penjualan/saldo_rekening (verify via phpmyadmin — 0 perubahan di tabel selain kasir_produk).

---

## Risks dan Handling

| Risiko | Mitigasi / Fallback |
|---|---|
| `uploads/` folder tidak writable di hosting | Di produk_kasir.php, sebelum upload cek `is_writable()`. Jika tidak bisa, tampilkan alert: "Folder upload tidak writable. Coba chmod 777 uploads/produk_kasir/". Fallback: allow produk tanpa gambar (gambar nullable, tampilkan placeholder gradient). |
| `kasir_produk` tabel error duplicate column ALTER | Semua query `ALTER TABLE` dan `CREATE TABLE` dibungkus `try/catch` PDO, dan sebelum create cek dulu `SHOW TABLES LIKE 'kasir_produk'`. |
| Browser cache halaman kasir.php | Tambah `<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">` di head kasir.php, dan link CDN selalu pakai version (Bootstrap 5.3.3 sudah fixed). |
| Cetak resi keluar dengan sidebar/popup | CSS `@media print` di kasir.php harus spesifik: `body > *:not(.receipt):not(.receipt *) { display: none !important; }`. Struk ditaruh di root-level div dengan class receipt. |
| Input harga di admin kirim string "Rp 12.000" | Di HTML input harga `<input type="number" step="100">` (bukan text) biar browser langsung kirim integer. Di PHP side tambah `(int) preg_replace('/[^\d]/', '', $_POST['harga'])` double guard. |

---
Plan ini 1 plan terikat, setelah APPROVED langsung implementasikan step 1-5 sesuai urutan dependency. Jangan skip step!
