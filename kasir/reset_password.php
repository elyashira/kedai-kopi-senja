<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('kasir');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pelanggan_id = intval($_POST['pelanggan_id'] ?? 0);
    $password_sementara = trim($_POST['password_sementara'] ?? '');

    if ($pelanggan_id > 0 && !empty($password_sementara)) {
        if (strlen($password_sementara) < 8) {
            $error = 'Password sementara minimal harus 8 karakter!';
        } else {
            try {
                $hashed_password = password_hash($password_sementara, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET password = :pass, wajib_ganti_password = 1 
                    WHERE id = :id AND role = 'pelanggan'
                ");
                $stmt->execute([
                    'pass' => $hashed_password,
                    'id' => $pelanggan_id
                ]);

                if ($stmt->rowCount() > 0) {
                    $success = "Password pelanggan berhasil direset! Password sementara: <strong>" . htmlspecialchars($password_sementara) . "</strong> (Pelanggan wajib menggantinya saat login berikutnya).";
                } else {
                    $error = "Gagal mereset password. Pastikan akun pelanggan valid.";
                }
            } catch (Exception $e) {
                $error = "Terjadi kesalahan: " . $e->getMessage();
            }
        }
    } else {
        $error = "Pilih akun pelanggan dan masukkan password sementara!";
    }
}

$stmtPelanggan = $pdo->query("SELECT id, nama, no_hp, email FROM users WHERE role = 'pelanggan' ORDER BY nama");
$pelanggan_list = $stmtPelanggan->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password Pelanggan - Kasir Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Kasir</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/kasir/index.php">Daftar Menu</a>
            <a href="<?= BASEURL ?>/kasir/transaksi.php">Buat Pesanan</a>
            <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php">Manajemen Pesanan</a>
            <a href="<?= BASEURL ?>/kasir/reset_password.php" class="active">Reset Password Pelanggan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Reset Password Pelanggan</h2>
        <p class="subtitle">Bantu pelanggan yang lupa password dengan memberikan password sementara.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <form action="" method="POST" style="max-width: 600px; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Pilih Pelanggan</label>
                <select name="pelanggan_id" required style="width: 100%; padding: 10px; margin-top: 5px;">
                    <option value="">Pilih Pelanggan</option>
                    <?php foreach ($pelanggan_list as $pl): ?>
                        <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['nama']) ?> (No. HP: <?= htmlspecialchars($pl['no_hp']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label>Password Sementara Baru (Minimal 8 karakter)</label>
                <input type="text" name="password_sementara" required placeholder="Contoh: kopi12345" style="width: 100%; padding: 10px; margin-top: 5px;">
            </div>

            <button type="submit" class="btn-primary" style="padding: 10px 20px;">Reset Password</button>
        </form>
    </div>
</body>
</html>