<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$dbname = 'db_usaha_dawet';
$username = 'root';
$password = '';

try {
    // Koneksi ke server MySQL tanpa database
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Baca file schema.sql
    $schema = file_get_contents(__DIR__ . '/config/schema.sql');

    // Eksekusi schema
    $pdo->exec($schema);

    echo "<h3>Instalasi berhasil!</h3>";
    echo "<p>Database dan tabel telah dibuat.</p>";
    echo "<p><a href='login.php'>Klik di sini untuk login</a></p>";
    echo "<p>Username: admin<br>Password: password</p>";

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>