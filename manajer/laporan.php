<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$stmtLapPenjualan = $pdo->query("
    SELECT m.nama_menu, m.kategori, 
           SUM(dp.jumlah_porsi) AS total_terjual, 
           SUM(dp.subtotal_item) AS total_pendapatan 
    FROM detail_pesanan dp
    JOIN pesanan p ON dp.pesanan_id = p.id
    JOIN menu m ON dp.menu_id = m.id
    WHERE p.status != 'Dibatalkan'
    GROUP BY m.id
    ORDER BY total_terjual DESC
");
$laporan_penjualan = $stmtLapPenjualan->fetchAll();

$stmtBahanMenipis = $pdo->query("
    SELECT * FROM bahan 
    WHERE stok <= batas_minimum 
    ORDER BY stok ASC
");
$bahan_menipis = $stmtBahanMenipis->fetchAll();

$stmtMargin = $pdo->query("SELECT * FROM menu ORDER BY nama_menu");
$menu_margins = $stmtMargin->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Manajer - Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Manajer</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/manajer/index.php">Dashboard</a>
            <a href="<?= BASEURL ?>/manajer/bahan.php">Bahan & Stok</a>
            <a href="<?= BASEURL ?>/manajer/menu.php">Menu & Resep</a>
            <a href="<?= BASEURL ?>/manajer/pembelian.php">Stok Masuk</a>
            <a href="<?= BASEURL ?>/manajer/laporan.php" class="active">Laporan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Laporan & Analisis Kedai Kopi Senja</h2>
        <p class="subtitle">Laporan lengkap penjualan per menu, daftar bahan menipis, dan margin keuntungan tiap menu.</p>

        <h3 style="color: #c0392b; margin-top: 30px;">Daftar Bahan Menipis / Perlu Restock</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Bahan</th>
                        <th>Satuan Dasar</th>
                        <th>Stok Saat Ini</th>
                        <th>Batas Minimum</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($bahan_menipis) > 0): ?>
                        <?php foreach ($bahan_menipis as $bm): ?>
                            <tr class="table-row-warning">
                                <td><strong><?= htmlspecialchars($bm['nama_bahan']) ?></strong></td>
                                <td><?= htmlspecialchars($bm['satuan_dasar']) ?></td>
                                <td><strong style="color: red;"><?= $bm['stok'] ?></strong></td>
                                <td><?= $bm['batas_minimum'] ?></td>
                                <td><span class="badge badge-danger">Menipis / Perlu Pembelian</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">Semua stok bahan dalam kondisi aman.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top: 40px;">Laporan Penjualan per Menu</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Menu</th>
                        <th>Kategori</th>
                        <th>Total Porsi Terjual</th>
                        <th>Total Pendapatan Kotor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($laporan_penjualan) > 0): ?>
                        <?php foreach ($laporan_penjualan as $lp): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($lp['nama_menu']) ?></strong></td>
                                <td><?= htmlspecialchars($lp['kategori']) ?></td>
                                <td><?= $lp['total_terjual'] ?> porsi</td>
                                <td>Rp <?= number_format($lp['total_pendapatan'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center">Belum ada data penjualan tercatat.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top: 40px;">Analisis Margin Keuntungan per Menu</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Menu</th>
                        <th>Kategori</th>
                        <th>Harga Jual</th>
                        <th>HPP (Modal)</th>
                        <th>Margin Keuntungan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($menu_margins) > 0): ?>
                        <?php foreach ($menu_margins as $mm): ?>
                            <?php
                                // hitung HPP
                                $stmtR = $pdo->prepare("
                                    SELECT SUM(r.takaran * b.harga_satuan) AS hpp 
                                    FROM resep r 
                                    JOIN bahan b ON r.bahan_id = b.id 
                                    WHERE r.menu_id = :m_id
                                ");
                                $stmtR->execute(['m_id' => $mm['id']]);
                                $hpp = floatval($stmtR->fetch()['hpp'] ?? 0);
                                $margin = $mm['harga_jual'] - $hpp;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($mm['nama_menu']) ?></strong></td>
                                <td><?= htmlspecialchars($mm['kategori']) ?></td>
                                <td>Rp <?= number_format($mm['harga_jual'], 0, ',', '.') ?></td>
                                <td>Rp <?= number_format($hpp, 0, ',', '.') ?></td>
                                <td><strong style="color: <?= $margin >= 0 ? 'green' : 'red' ?>;">Rp <?= number_format($margin, 0, ',', '.') ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">Belum ada menu terdaftar.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>