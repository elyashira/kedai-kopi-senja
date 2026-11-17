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
                $hashed_password = password_hash(
                    $password_sementara,
                    PASSWORD_DEFAULT
                );

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
                    $success = "Password pelanggan berhasil direset! Password sementara: <strong>"
                        . htmlspecialchars($password_sementara)
                        . "</strong> (Pelanggan wajib menggantinya saat login berikutnya).";
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

$stmtPelanggan = $pdo->query("
    SELECT id, nama, no_hp, email
    FROM users
    WHERE role = 'pelanggan'
    ORDER BY nama
");

$pelanggan_list = $stmtPelanggan->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Kasir Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css?v=3">
</head>

<body class="kasir-body">
<div class="kasir-layout">

    <!-- header -->
    <header class="kasir-header">
        <div class="kasir-brand">
                <strong>KEDAI KOPI SENJA</strong>
            </div>

        <div class="kasir-user">
            <span>Kasir</span>
            <a href="<?= BASEURL ?>/logout.php">Logout</a>
        </div>
    </header>

    <!-- pesan notifikasi -->
    <?php if (!empty($error)): ?>
        <div class="alert-error" style="margin: 12px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert-success" style="margin: 12px;">
            <?= $success ?>
        </div>
    <?php endif; ?>

    <!-- konten utama -->
    <main class="kasir-main" style="grid-template-columns: minmax(0, 1fr);">
        <section class="kasir-menu">
            <div class="kasir-menu-header">
                <div>
                    <h2>Reset Password Pelanggan</h2>
                    <p>
                        Bantu pelanggan yang lupa password dengan memberikan
                        password sementara.
                    </p>
                </div>
            </div>

            <div class="kasir-order" style="max-width: 650px;">

                <h3 style="margin-top: 0; font-size: 15px;">
                    Form Reset Password
                </h3>

                <form action="" method="POST">

                    <div class="form-group">
                        <label for="pelanggan_id">Pilih Pelanggan</label>

                        <select
                            name="pelanggan_id"
                            id="pelanggan_id"
                            required>
                            <option value="">Pilih Pelanggan</option>

                            <?php foreach ($pelanggan_list as $pl): ?>
                                <option value="<?= (int) $pl['id'] ?>">
                                    <?= htmlspecialchars($pl['nama']) ?>
                                    (No. HP:
                                    <?= htmlspecialchars($pl['no_hp'] ?? '-') ?>)
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group">
                        <label for="password_sementara">
                            Password Sementara Baru
                        </label>

                        <input
                            type="text"
                            name="password_sementara"
                            id="password_sementara"
                            minlength="8"
                            required
                            placeholder="Minimal 8 karakter">

                        <small class="text-muted">
                            Pelanggan wajib mengganti password saat login berikutnya.
                        </small>
                    </div>

                    <button
                        type="submit"
                        class="btn-primary"
                        style="padding: 11px 20px;">
                        Reset Password
                    </button>
                </form>
            </div>
        </section>
    </main>

    <!-- navigasi bawah -->
    <footer class="kasir-bottom-nav">
        <a href="<?= BASEURL ?>/kasir/index.php"
           class="bottom-nav-item">
            <span>🏠︎</span>
            <small>Menu</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php"
           class="bottom-nav-item active">
            <span>🗒</span>
            <small>Pesanan</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/reset_password.php"
           class="bottom-nav-item">
            <span>⟳</span>
            <small>Reset Akun</small>
        </a>
    </footer>

</div>
</body>
</html>