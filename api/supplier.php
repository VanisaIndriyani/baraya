<?php
header('Content-Type: application/json');
require_once '../config/database.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'GET') {
    // GET: Ambil data supplier
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'nama';
    $order = isset($_GET['order']) ? $_GET['order'] : 'ASC';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    $offset = ($page - 1) * $limit;

    $allowed_sorts = ['id', 'nama', 'no_hp'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'nama';
    $order = strtoupper($order) == 'DESC' ? 'DESC' : 'ASC';

    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM supplier WHERE id = ?");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $supplier]);
        exit;
    }

    $where = '';
    $params = [];
    if ($search) {
        $where = " WHERE nama LIKE ? OR no_hp LIKE ? OR alamat LIKE ?";
        $params = ["%$search%", "%$search%", "%$search%"];
    }

    $count_sql = "SELECT COUNT(*) as total FROM supplier $where";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT * FROM supplier $where ORDER BY $sort $order LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $supplier = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'data' => $supplier,
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
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    try {
        if ($action == 'tambah') {
            $nama = $_POST['nama'];
            $no_hp = $_POST['no_hp'];
            $alamat = $_POST['alamat'];
            
            $stmt = $pdo->prepare("INSERT INTO supplier (nama, no_hp, alamat) VALUES (?, ?, ?)");
            $stmt->execute([$nama, $no_hp, $alamat]);
            
            $response = ['success' => true, 'message' => 'Supplier berhasil ditambahkan'];
        } elseif ($action == 'edit') {
            $id = $_POST['id'];
            $nama = $_POST['nama'];
            $no_hp = $_POST['no_hp'];
            $alamat = $_POST['alamat'];
            
            $stmt = $pdo->prepare("UPDATE supplier SET nama = ?, no_hp = ?, alamat = ? WHERE id = ?");
            $stmt->execute([$nama, $no_hp, $alamat, $id]);
            
            $response = ['success' => true, 'message' => 'Supplier berhasil diperbarui'];
        } elseif ($action == 'delete') {
            $id = $_POST['id'];
            
            $stmt = $pdo->prepare("DELETE FROM supplier WHERE id = ?");
            $stmt->execute([$id]);
            
            $response = ['success' => true, 'message' => 'Supplier berhasil dihapus'];
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request method']);
exit;
