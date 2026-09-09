<?php
// DEFAULT HOME: REDIRECT LANGSUNG KE KASIR PUBLIK (tanpa login)
// Karena kasir.php = halaman publik standalone untuk input order pembeli (es teller & dawet baraya)
// Dashboard admin = login manual via tombol pojok kanan atas di kasir.php atau link login.php langsung
$base = '';
if (isset($_SERVER['HTTP_HOST'])) {
    $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($script !== '/' && $script !== '\\') $base .= rtrim($script, '/');
}
$target = rtrim($base, '/') . '/kasir.php';
header('HTTP/1.1 302 Found');
header('Location: ' . $target);
exit;
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=kasir.php">
<title>Mengarahkan ke Kasir Es Teller Dawet Baraya...</title>
</head>
<body style="margin:0; padding:0; background:#FFFBEB; font-family:system-ui,Segoe UI,Tahoma,sans-serif; color:#450A0A;">
    <div style="max-width:540px; margin:90px auto 0 auto; padding:26px 22px; text-align:center; border:2px solid #FBBF24; border-radius:22px; background:#FFFFFF; box-shadow:0 18px 40px rgba(153,27,27,0.12);">
        <div style="font-size:34px; margin-bottom:12px;">🧋☕</div>
        <h1 style="margin:0 0 8px 0; font-size:20px; letter-spacing:0.3px; font-weight:900;">Es Teller & Dawet Baraya</h1>
        <p style="margin:0 0 18px 0; font-size:14px; color:#991B1B; font-weight:600;">Mengarahkan ke halaman kasir...</p>
        <p style="margin:0; font-size:13px; color:#374151;">
            Jika tidak otomatis pindah, silahkan klik <a href="kasir.php" style="color:#7F1D1D; font-weight:800; text-decoration:underline;">Masuk ke Kasir</a>
        </p>
    </div>
    <script>window.location.replace('kasir.php');</script>
</body>
</html>
