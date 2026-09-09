<?php
// Cek apakah di local atau hosting
$is_local = $_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == '127.0.0.1';

if ($is_local) {
    // Kredensial Local (Laragon)
    $host = 'localhost';
    $dbname = 'db_usaha_dawet';
    $username = 'root';
    $password = '';
} else {
    // Kredensial Hosting
    $host = 'localhost';
    $dbname = 'bitubimy_vanisa';
    $username = 'bitubimy_izsaa';
    $password = 'jokiizsaa200504';
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>