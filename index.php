
<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/seeder.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_user = trim($_POST['nama'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($nama_user) && !empty($password)) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE nama = :nama
            AND role IN ('manajer', 'kasir')
        ");

        $stmt->execute(['nama' => $nama_user]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nama'] = $user['nama'];
            $_SESSION['role'] = $user['role'];

            // Redirect sesuai role

            if ($user['role'] === 'manajer') {
                header('Location: ' . BASEURL . '/manajer/index.php');
                exit;

            } elseif ($user['role'] === 'kasir') {
                header('Location: ' . BASEURL . '/kasir/index.php');
                exit;
            }

        } else {
            $error = 'Nama atau password salah, atau Anda tidak memiliki hak akses staf.';
        }

    } else {
        $error = 'Semua kolom wajib diisi!';
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>

<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <h2>Kedai Kopi Senja</h2>
            <?php if (!empty($error)): ?>

                <div class="alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>
            <form action="" method="POST">
                <div class="form-group">
                    <label for="nama">Nama Akun</label>
                    <input
                        type="text"
                        id="nama"
                        name="nama"
                        placeholder="Masukkan nama akun"
                        autocomplete="username"
                        required
                    >
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
                        required
                    >
                </div>
                <button type="submit" class="btn-primary">
                    Masuk
                </button>
            </form>
        </div>
    </div>
</body>
</html>
