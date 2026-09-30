<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['teks' => 'Silakan login dulu.', 'simpan' => false]);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/asisten-utils.php';

$mentah = file_get_contents('php://input');
$data = json_decode($mentah ?: '', true);
$pesan = '';
if (is_array($data) && isset($data['pesan'])) {
    $pesan = (string) $data['pesan'];
} elseif (isset($_POST['pesan'])) {
    $pesan = (string) $_POST['pesan'];
}

$riwayat = [];
if (is_array($data['riwayat'] ?? null)) {
    foreach (array_slice($data['riwayat'], -8) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $teksRiwayat = trim((string) ($item['teks'] ?? ''));
        if ($teksRiwayat === '') {
            continue;
        }
        $riwayat[] = [
            'dari' => ($item['dari'] ?? '') === 'user' ? 'user' : 'assistant',
            'teks' => function_exists('mb_substr') ? mb_substr($teksRiwayat, 0, 400, 'UTF-8') : substr($teksRiwayat, 0, 400),
        ];
    }
}

try {
    $hasil = asistenJawab($pdo, $pesan, $riwayat);
    echo json_encode([
        'teks' => $hasil['teks'],
        'simpan' => (bool) $hasil['simpan'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'teks' => 'Gagal memproses: ' . $e->getMessage(),
        'simpan' => false,
    ], JSON_UNESCAPED_UNICODE);
}
