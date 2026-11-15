<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_role('kasir');

$stmt = $pdo->query("SELECT * FROM menu ORDER BY kategori, nama_menu");
$menus = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Kasir - Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Kasir</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/kasir/index.php" class="active">Daftar Menu</a>
            <a href="<?= BASEURL ?>/kasir/transaksi.php">Buat Pesanan</a>
            <a href="<?= BASEURL ?>/kasir/reset_password.php">Reset Password Pelanggan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout (<?= htmlspecialchars($_SESSION['nama']) ?>)</a>
        </div>
    </div>

    <div class="container">
        <h2>Daftar Menu Tersedia</h2>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Menu</th>
                        <th>Kategori</th>
                        <th>Harga Jual</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($menus) > 0): ?>
                        <?php foreach ($menus as $index => $m): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($m['nama_menu']) ?></td>
                                <td><?= htmlspecialchars($m['kategori']) ?></td>
                                <td>Rp <?= number_format($m['harga_jual'], 0, ',', '.') ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($m['status']) ?>">
                                        <?= htmlspecialchars($m['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">Belum ada menu yang tersedia.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>