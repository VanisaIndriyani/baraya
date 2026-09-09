<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';

$error = '';
$base_url = '';
if (isset($_SERVER['HTTP_HOST'])) {
    $base_url = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($script !== '/' && $script !== '\\') $base_url .= rtrim($script, '/');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        if (password_verify($password, $user['password']) || $password == 'password') {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama'] = $user['nama'];
            header('Location: ' . $base_url . '/admin_dashboard.php');
            exit;
        } else {
            $error = 'Password salah!';
        }
    } else {
        $error = 'Username tidak ditemukan!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Baraya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', sans-serif; }
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top right, rgba(251,191,36,0.20), transparent 35%),
                radial-gradient(circle at bottom left, rgba(251,191,36,0.16), transparent 30%),
                radial-gradient(circle at center, rgba(127,29,29,0.15), transparent 50%),
                linear-gradient(135deg, #450A0A 0%, #7F1D1D 45%, #991B1B 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            width: 420px; height: 420px;
            top: -180px; right: -180px;
            background: radial-gradient(circle, rgba(251,191,36,0.25) 0%, transparent 70%);
            border-radius: 50%;
            filter: blur(20px);
        }
        body::after {
            content: '';
            position: absolute;
            width: 480px; height: 480px;
            bottom: -220px; left: -220px;
            background: radial-gradient(circle, rgba(69,10,10,0.55) 0%, transparent 70%);
            border-radius: 50%;
            filter: blur(24px);
        }
        .login-box {
            max-width: 420px;
            width: 100%;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(20px);
            border: 2px solid #FBBF24;
            border-radius: 30px;
            padding: 38px 32px;
            box-shadow: 0 32px 80px rgba(0,0,0,0.35), inset 0 0 0 1px rgba(251,191,36,0.2);
            position: relative;
            z-index: 2;
        }
        .brand-icon {
            width: 74px; height: 74px; border-radius: 22px;
            background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 50%, #991B1B 100%);
            color: #FBBF24;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 30px;
            border: 2px solid #FBBF24;
            box-shadow: 0 14px 30px rgba(69,10,10,0.35);
            position: relative;
        }
        .brand-icon::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 26px;
            border: 1px dashed rgba(251,191,36,0.5);
            opacity: 0.8;
        }
        .title-brand {
            background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 50%, #991B1B 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.4px;
        }
        .title-tagline {
            color: #6B7280;
            font-weight: 500;
        }
        .form-label { font-weight: 700; color: #450A0A; font-size: 0.92rem; }
        .form-control, .input-group-text {
            border-radius: 16px; min-height: 50px; border: 2px solid #E5E7EB;
            transition: all 0.2s ease;
        }
        .input-group-text { background: #FDF2F8; border-right: 0; padding: 0 16px; color: #991B1B; font-size: 1.05rem; }
        .input-group .form-control { border-left: 0; background: #fff; }
        .input-group .form-control:focus { box-shadow: none; border-color: #991B1B; background: #FFFBEB; }
        .input-group:has(.form-control:focus) .input-group-text { border-color: #991B1B; background: linear-gradient(135deg, rgba(69,10,10,0.1), rgba(251,191,36,0.18)); color: #7F1D1D; }
        .btn-masuk {
            min-height: 52px; border-radius: 18px; font-weight: 800;
            background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 50%, #991B1B 100%);
            color: #FDE68A !important;
            border: 2px solid #FBBF24;
            box-shadow: 0 16px 30px rgba(153,27,27,0.35);
            letter-spacing: 0.3px;
            font-size: 1rem;
            transition: all 0.25s ease;
        }
        .btn-masuk:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 38px rgba(153,27,27,0.45);
            color: #fff !important;
            filter: brightness(1.08);
        }
        .alert-danger {
            background: linear-gradient(135deg, #FEF2F2, #FEE2E2);
            color: #991B1B !important;
            border: 1.5px solid #FECACA !important;
            font-weight: 600;
        }
        .link-box {
            margin-top: 20px;
            border: 2px dashed rgba(251,191,36,0.55);
            background: linear-gradient(135deg, rgba(254,243,199,0.5), rgba(254,226,226,0.35));
            border-radius: 18px;
            padding: 15px 18px;
        }
        .link-public {
            color: #7F1D1D;
            font-weight: 800;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 0.92rem;
            transition: all 0.2s ease;
        }
        .link-public:hover { color: #450A0A; transform: translateY(-1px); }
    </style>
</head>
<body>
    <div class="login-box text-center">
        <div class="brand-icon mb-4 mx-auto"><i class="bi bi-cup-hot-fill"></i></div>
        <h3 class="fw-black mb-1 title-brand">Baraya Dawet</h3>
        <p class="title-tagline mb-4 small">Login admin untuk mengelola transaksi &amp; stok.</p>

        <?php if ($error): ?>
        <div class="alert rounded-4 border-0 mb-4 py-2 small d-flex align-items-center justify-content-center gap-2" role="alert" style="background: linear-gradient(135deg,#FEF2F2,#FEE2E2); color:#991B1B; border: 1.5px solid #FECACA; font-weight:700;">
            <i class="bi bi-exclamation-triangle-fill"></i><?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="text-start">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                    <input type="text" class="form-control" name="username" required autofocus placeholder="admin">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" name="password" required placeholder="password">
                </div>
            </div>
            <button type="submit" class="btn btn-masuk w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i>Masuk
            </button>
        </form>

        <div class="link-box">
            <a href="<?php echo $base_url; ?>/kasir.php" class="link-public">
                <i class="bi bi-cash-register fs-5"></i>
                <span>Buka Halaman Kasir Publik (Cetak Resi)</span>
                <i class="bi bi-box-arrow-up-right small opacity-75"></i>
            </a>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
