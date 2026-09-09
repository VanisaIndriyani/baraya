<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../includes/image-utils.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Cek apakah request AJAX
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'GET') {
    // GET: Ambil data barang
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'nama';
    $order = isset($_GET['order']) ? $_GET['order'] : 'ASC';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    $offset = ($page - 1) * $limit;

    // Validasi sort column
    $allowed_sorts = ['id', 'nama', 'kategori', 'stok', 'harga_beli'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'nama';
    $order = strtoupper($order) == 'DESC' ? 'DESC' : 'ASC';

    if ($id) {
        // Ambil single barang
        $stmt = $pdo->prepare("SELECT b.*, s.nama as supplier_nama FROM barang b LEFT JOIN supplier s ON b.id_supplier = s.id WHERE b.id = ?");
        $stmt->execute([$id]);
        $barang = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $barang]);
        exit;
    }

    // Ambil multiple barang
    $where = '';
    $params = [];
    if ($search) {
        $where = " WHERE b.nama LIKE ? OR b.kategori LIKE ? OR s.nama LIKE ?";
        $params = ["%$search%", "%$search%", "%$search%"];
    }

    // Hitung total
    $count_sql = "SELECT COUNT(*) as total FROM barang b LEFT JOIN supplier s ON b.id_supplier = s.id $where";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Ambil data
    $sql = "SELECT b.*, s.nama as supplier_nama FROM barang b LEFT JOIN supplier s ON b.id_supplier = s.id $where ORDER BY $sort $order LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $barang = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'data' => $barang,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
    exit;
}

if ($method == 'POST') {
    // POST: Tambah atau Edit barang
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    try {
        if ($action == 'tambah') {
            $nama = trim($_POST['nama'] ?? '');
            if ($nama === '') {
                throw new Exception('Nama barang tidak boleh kosong');
            }
            $kategori = !empty($_POST['kategori']) ? trim($_POST['kategori']) : 'stok';
            $id_supplier = !empty($_POST['id_supplier']) ? $_POST['id_supplier'] : null;
            $harga_beli = isset($_POST['harga_beli']) ? $_POST['harga_beli'] : 0;
            $satuan = !empty($_POST['satuan']) ? trim($_POST['satuan']) : 'pcs';
            $stok = isset($_POST['stok']) ? $_POST['stok'] : 0;
            $min_stok = isset($_POST['min_stok']) ? $_POST['min_stok'] : 0;
            $foto = null;

            if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                $filename = uniqid() . '.' . $ext;
                $filepath = '../uploads/barang/' . $filename;
                move_uploaded_file($_FILES['foto']['tmp_name'], $filepath);
                if (function_exists('compressImage')) {
                    compressImage($filepath, $filepath, 70);
                }
                $foto = $filename;
            }

            $stmt = $pdo->prepare("INSERT INTO barang (nama, kategori, id_supplier, harga_beli, satuan, stok, min_stok, foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nama, $kategori, $id_supplier, $harga_beli, $satuan, $stok, $min_stok, $foto]);

            $response = ['success' => true, 'message' => 'Barang berhasil ditambahkan'];
        } elseif ($action == 'edit') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$id) {
                throw new Exception('ID barang tidak valid');
            }
            $nama = trim($_POST['nama'] ?? '');
            if ($nama === '') {
                throw new Exception('Nama barang tidak boleh kosong');
            }
            $row_old = $pdo->prepare("SELECT * FROM barang WHERE id = ?");
            $row_old->execute([$id]);
            $old = $row_old->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new Exception('Barang tidak ditemukan');
            }

            $kategori = !empty($_POST['kategori']) ? trim($_POST['kategori']) : ($old['kategori'] ?: 'stok');
            $id_supplier = !empty($_POST['id_supplier']) ? $_POST['id_supplier'] : $old['id_supplier'];
            $harga_beli = isset($_POST['harga_beli']) ? $_POST['harga_beli'] : $old['harga_beli'];
            $satuan = !empty($_POST['satuan']) ? trim($_POST['satuan']) : ($old['satuan'] ?: 'pcs');
            $stok = isset($_POST['stok']) ? $_POST['stok'] : $old['stok'];
            $min_stok = isset($_POST['min_stok']) ? $_POST['min_stok'] : $old['min_stok'];
            $foto = $old['foto'];

            if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                $filename = uniqid() . '.' . $ext;
                $filepath = '../uploads/barang/' . $filename;
                move_uploaded_file($_FILES['foto']['tmp_name'], $filepath);
                if (function_exists('compressImage')) {
                    compressImage($filepath, $filepath, 70);
                }
                $new_foto = $filename;

                if ($old['foto'] && file_exists('../uploads/barang/' . $old['foto'])) {
                    unlink('../uploads/barang/' . $old['foto']);
                }
                $foto = $new_foto;
            }

            $stmt = $pdo->prepare("UPDATE barang SET nama = ?, kategori = ?, id_supplier = ?, harga_beli = ?, satuan = ?, stok = ?, min_stok = ?, foto = ? WHERE id = ?");
            $stmt->execute([$nama, $kategori, $id_supplier, $harga_beli, $satuan, $stok, $min_stok, $foto, $id]);

            $response = ['success' => true, 'message' => 'Barang berhasil diperbarui'];
        } elseif ($action == 'delete') {
            // Hapus barang
            $id = $_POST['id'];
            
            // Ambil foto
            $stmt = $pdo->prepare("SELECT foto FROM barang WHERE id = ?");
            $stmt->execute([$id]);
            $old_foto = $stmt->fetch(PDO::FETCH_ASSOC)['foto'];
            
            if ($old_foto && file_exists('../uploads/barang/' . $old_foto)) {
                unlink('../uploads/barang/' . $old_foto);
            }
            
            $stmt = $pdo->prepare("DELETE FROM barang WHERE id = ?");
            $stmt->execute([$id]);
            
            $response = ['success' => true, 'message' => 'Barang berhasil dihapus'];
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request method']);
exit;
