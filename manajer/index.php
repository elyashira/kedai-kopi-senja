<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/supplier_api.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$error = '';
$success = '';

// sinkronisasi harga bahan dari layanan supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_supplier'])) {

    $hasilApi = ambilHargaBahanSupplier();

    if (!$hasilApi['success']) {
        $error = $hasilApi['message'] . ' Harga terakhir tetap digunakan.';
    } else {

        $tanggalUpdate = $hasilApi['diperbarui'] ?? '';

        $tanggalValid = is_string($tanggalUpdate)
            ? DateTime::createFromFormat('!Y-m-d', $tanggalUpdate)
            : false;

        if (
            !$tanggalValid ||
            $tanggalValid->format('Y-m-d') !== $tanggalUpdate
        ) {
            $error = "Tanggal pembaruan supplier tidak valid. Harga terakhir tetap digunakan.";
        } else {

            $jumlahBerhasil = 0;
            $jumlahTidakValid = 0;

            try {
                $pdo->beginTransaction();

                $stmtCari = $pdo->prepare("
                    SELECT id, satuan_dasar
                    FROM bahan
                    WHERE kode_bahan = :kode
                ");

                $stmtUpd = $pdo->prepare("
                    UPDATE bahan
                    SET harga_satuan = :harga,
                        last_updated_price = :tanggal
                    WHERE id = :id
                ");

                foreach ($hasilApi['data'] as $item) {

                    $kode = trim($item['kode'] ?? '');
                    $satuan = $item['satuan_dasar'] ?? '';
                    $hargaBaru = $item['harga_per_satuan'] ?? null;

                    if (
                        $kode === '' ||
                        !in_array($satuan, ['g', 'ml', 'pcs'], true) ||
                        !is_numeric($hargaBaru) ||
                        !is_finite((float) $hargaBaru) ||
                        (float) $hargaBaru < 0
                    ) {
                        $jumlahTidakValid++;
                        continue;
                    }

                    $stmtCari->execute([
                        'kode' => $kode
                    ]);

                    $bahan = $stmtCari->fetch(PDO::FETCH_ASSOC);

                    if (
                        !$bahan ||
                        $bahan['satuan_dasar'] !== $satuan
                    ) {
                        $jumlahTidakValid++;
                        continue;
                    }

                    $stmtUpd->execute([
                        'harga' => (float) $hargaBaru,
                        'tanggal' => $tanggalUpdate,
                        'id' => $bahan['id']
                    ]);

                    $jumlahBerhasil++;
                }

                $pdo->commit();

                if ($jumlahBerhasil > 0) {
                    $success = "Sinkronisasi selesai. {$jumlahBerhasil} harga bahan berhasil diperbarui.";

                    if ($jumlahTidakValid > 0) {
                        $success .= " {$jumlahTidakValid} data dilewati karena tidak cocok atau tidak valid.";
                    }
                } else {
                    $error = "Tidak ada harga yang berhasil diperbarui. Periksa kode bahan dan satuan di database.";
                }

            } catch (Exception $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = "Sinkronisasi gagal. Harga terakhir tetap digunakan.";
            }
        }
    }
}

// statistik dashboard

$totalMenu = (int) $pdo->query("
    SELECT COUNT(*)
    FROM menu
")->fetchColumn();

$totalBahanMenipis = (int) $pdo->query("
    SELECT COUNT(*)
    FROM bahan
    WHERE stok <= batas_minimum
")->fetchColumn();

$totalPesananHariIni = (int) $pdo->query("
    SELECT COUNT(*)
    FROM pesanan
    WHERE DATE(created_at) = CURDATE()
    AND status != 'Dibatalkan'
")->fetchColumn();

$pendapatanHariIni = (float) ($pdo->query("
    SELECT COALESCE(SUM(total_bayar), 0)
    FROM pesanan
    WHERE status != 'Dibatalkan'
    AND DATE(created_at) = CURDATE()
")->fetchColumn() ?: 0);

// laporan penjualan per menu

$stmtLapPenjualan = $pdo->query("
    SELECT
        m.nama_menu,
        m.kategori,
        SUM(dp.jumlah_porsi) AS total_terjual,
        SUM(dp.subtotal_item) AS total_pendapatan
    FROM detail_pesanan dp
    JOIN pesanan p ON dp.pesanan_id = p.id
    JOIN menu m ON dp.menu_id = m.id
    WHERE p.status != 'Dibatalkan'
    GROUP BY m.id, m.nama_menu, m.kategori
    ORDER BY total_terjual DESC
");

$laporan_penjualan = $stmtLapPenjualan->fetchAll(PDO::FETCH_ASSOC);

// bahan menipis

$bahan_menipis = $pdo->query("
    SELECT
        nama_bahan,
        satuan_dasar,
        stok,
        batas_minimum
    FROM bahan
    WHERE stok <= batas_minimum
    ORDER BY stok ASC
")->fetchAll(PDO::FETCH_ASSOC);

// margin keuntungan per menu

$menu_margins = $pdo->query("
    SELECT
        m.id,
        m.nama_menu,
        m.kategori,
        m.harga_jual,
        COALESCE(SUM(r.takaran * b.harga_satuan), 0) AS hpp,
        COUNT(r.id) AS jumlah_resep
    FROM menu m
    LEFT JOIN resep r ON r.menu_id = m.id
    LEFT JOIN bahan b ON b.id = r.bahan_id
    GROUP BY
        m.id,
        m.nama_menu,
        m.kategori,
        m.harga_jual
    ORDER BY m.nama_menu
")->fetchAll(PDO::FETCH_ASSOC);

// nilai maksimum untuk grafik menu terlaris

$nilaiMaksGrafik = 1;

foreach (array_slice($laporan_penjualan, 0, 5) as $itemGrafik) {
    $nilaiMaksGrafik = max(
        $nilaiMaksGrafik,
        (int) $itemGrafik['total_terjual']
    );
}

$namaManajer = $_SESSION['nama'] ?? 'Manajer';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Manajer - Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>

<body class="manager-body">
<div class="manager-shell">

    <!-- sidebar -->
    <aside class="manager-sidebar">
        <div class="manager-side-title">
            <br>KEDAI KOPI SENJA
        </div>

        <nav class="manager-nav">

            <a href="<?= BASEURL ?>/manajer/index.php">
                <span class="manager-nav-icon">🏠︎</span>
                <span>Dashboard</span>
            </a>

            <a href="<?= BASEURL ?>/manajer/bahan.php">
                <span class="manager-nav-icon">🗒</span>
                <span>Bahan & Stok</span>
            </a>

            <a href="<?= BASEURL ?>/manajer/menu.php">
                <span class="manager-nav-icon">☕︎</span>
                <span>Menu</span>
            </a>

            <a href="<?= BASEURL ?>/manajer/resep.php">
                <span class="manager-nav-icon">🗐</span>
                <span>Resep</span>
            </a>

            <a href="<?= BASEURL ?>/manajer/pembelian.php">
                <span class="manager-nav-icon">✚</span>
                <span>Stok Masuk</span>
            </a>

        </nav>

        <a href="<?= BASEURL ?>/logout.php" class="manager-logout">
            <span class="manager-nav-icon">➜</span>
            <span>Logout</span>
        </a>

    </aside>

    <!-- konten utama -->
    <main class="manager-content">

        <!-- header -->
        <header class="manager-topbar">

            <div>
                <span class="manager-eyebrow">OVERVIEW</span>
                <h1>Dashboard</h1>
            </div>

            <div class="manager-profile">

                <div class="manager-avatar">
                    <?= htmlspecialchars(strtoupper(substr($namaManajer, 0, 1))) ?>
                </div>

                <div>
                    <strong><?= htmlspecialchars($namaManajer) ?></strong>
                    <small>Manajer</small>
                </div>
            </div>
        </header>

        <!-- notifikasi -->
        <?php if ($error !== ''): ?>
            <div class="alert-error manager-alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert-success manager-alert">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- welcome -->
        <section class="manager-welcome">

            <div>
                <span class="manager-eyebrow">KEDAI KOPI SENJA</span>

                <h2>
                    Halo, <?= htmlspecialchars($namaManajer) ?>!
                </h2>

                <p>
                    Berikut ringkasan operasional kedai hari ini.
                </p>
            </div>
        </section>

        <!-- statistik -->
        <section class="manager-stat-grid">
            <article class="manager-stat-card">
                <div class="stat-icon stat-coffee">☕︎</div>
                <div>
                    <span>Total Menu</span>
                    <strong>
                        <?= number_format($totalMenu, 0, ',', '.') ?>
                    </strong>
                    <small>Menu terdaftar</small>
                </div>
            </article>

            <article class="manager-stat-card">
                <div class="stat-icon stat-stock">🗒</div>
                <div>
                    <span>Bahan Menipis</span>
                    <strong>
                        <?= number_format($totalBahanMenipis, 0, ',', '.') ?>
                    </strong>
                    <small>Perlu diperiksa</small>
                </div>
            </article>

            <article class="manager-stat-card">
                <div class="stat-icon stat-order">✎</div>
                <div>
                    <span>Pesanan Hari Ini</span>
                    <strong>
                        <?= number_format($totalPesananHariIni, 0, ',', '.') ?>
                    </strong>
                    <small>Pesanan tidak dibatalkan</small>
                </div>
            </article>

            <article class="manager-stat-card">
                <div class="stat-icon stat-income">Rp</div>
                <div>
                    <span>Pendapatan Hari Ini</span>
                    <strong class="stat-money">
                        Rp <?= number_format($pendapatanHariIni, 0, ',', '.') ?>
                    </strong>
                    <small>Dari pesanan tidak dibatalkan</small>
                </div>
            </article>

        </section>

        <!-- grafik dan supplier -->
        <section class="manager-main-grid">

            <!-- grafik menu terlaris -->
            <article class="manager-panel manager-chart-panel">
                <div class="manager-panel-heading">
                    <div>
                        <h3>Menu Terlaris</h3>
                        <p>5 menu dengan jumlah porsi terjual terbanyak</p>
                    </div>
                    <span class="panel-tag">Semua waktu</span>
                </div>

                <?php if (count($laporan_penjualan) > 0): ?>
                    <div class="simple-chart">
                        <?php foreach (array_slice($laporan_penjualan, 0, 5) as $item): ?>
                            <?php
                            $lebarBar = max(
                                4,
                                ((int) $item['total_terjual'] / $nilaiMaksGrafik) * 100
                            );
                            ?>
                            <div class="chart-row">
                                <div class="chart-label">
                                    <span><?= htmlspecialchars($item['nama_menu']) ?></span>
                                    <strong>
                                        <?= number_format((int) $item['total_terjual'], 0, ',', '.') ?>
                                        porsi
                                    </strong>
                                </div>

                                <div class="chart-track">
                                    <div
                                        class="chart-bar"
                                        style="width: <?= $lebarBar ?>%;">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <div class="manager-empty">
                        Belum ada data penjualan untuk ditampilkan.
                    </div>

                <?php endif; ?>
            </article>

            <!-- supplier -->
            <article class="manager-panel manager-supplier-panel">
                <div class="manager-panel-heading">
                    <div>
                        <h3>Harga Supplier</h3><br>
                        <p>Perbarui harga bahan dari layanan supplier.</p>
                    </div>
                </div>

                <form method="POST" action="">

                    <button
                        type="submit"
                        name="sync_supplier"
                        value="1"
                        class="manager-primary-btn">
                        Sinkronkan Harga
                    </button>
                </form>
                <p class="supplier-note">
                    Jika layanan supplier sedang tidak tersedia,
                    harga terakhir di database tetap digunakan.
                </p>
            </article>
        </section>

        <!-- laporan operasional -->
        <section class="manager-panel manager-report-panel" id="laporan">
            <div class="manager-panel-heading report-heading">
                <div>
                    <h3>Laporan Operasional</h3>
                    <p>Ringkasan penjualan, stok, dan margin menu.</p>
                </div>
                <span class="panel-tag">Data terbaru</span>
            </div>

            <!-- laporan stok -->
            <div class="report-section">
                <div class="report-section-title">
                    <h4>Perlu Restock</h4>
                    <span>
                        <?= count($bahan_menipis) ?> bahan
                    </span>
                </div>
                <div class="manager-table-wrap">
                    <table class="manager-table">
                        <thead>
                            <tr>
                                <th>Nama Bahan</th>
                                <th>Satuan</th>
                                <th>Stok Saat Ini</th>
                                <th>Batas Minimum</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($bahan_menipis): ?>
                                <?php foreach ($bahan_menipis as $bm): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?= htmlspecialchars($bm['nama_bahan']) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($bm['satuan_dasar']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($bm['stok']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($bm['batas_minimum']) ?>
                                        </td>

                                        <td>
                                            <span class="manager-status warning">
                                                Perlu restock
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="manager-table-empty">
                                        Semua stok bahan dalam kondisi aman.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- laporan penjualan -->
            <div class="report-section">
                <div class="report-section-title">
                    <h4>Penjualan per Menu</h4>
                </div>
                <div class="manager-table-wrap">
                    <table class="manager-table">
                        <thead>
                            <tr>
                                <th>Nama Menu</th>
                                <th>Kategori</th>
                                <th>Porsi Terjual</th>
                                <th>Pendapatan Kotor</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($laporan_penjualan): ?>
                                <?php foreach ($laporan_penjualan as $lp): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?= htmlspecialchars($lp['nama_menu']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($lp['kategori']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $lp['total_terjual'], 0, ',', '.') ?>
                                        </td>

                                        <td>
                                            Rp <?= number_format((float) $lp['total_pendapatan'], 0, ',', '.') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="manager-table-empty">
                                        Belum ada data penjualan tercatat.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- laporan margin -->
            <div class="report-section">
                <div class="report-section-title">
                    <h4>Margin Keuntungan per Menu</h4>
                </div>

                <div class="manager-table-wrap">
                    <table class="manager-table">
                        <thead>
                            <tr>
                                <th>Nama Menu</th>
                                <th>Kategori</th>
                                <th>Harga Jual</th>
                                <th>HPP / Modal</th>
                                <th>Margin</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($menu_margins): ?>
                                <?php foreach ($menu_margins as $mm): ?>
                                    <?php
                                    $memilikiResep = (int) $mm['jumlah_resep'] > 0;
                                    $margin = $memilikiResep
                                        ? (float) $mm['harga_jual'] - (float) $mm['hpp']
                                        : null;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?= htmlspecialchars($mm['nama_menu']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($mm['kategori']) ?>
                                        </td>

                                        <td>
                                            Rp <?= number_format((float) $mm['harga_jual'], 0, ',', '.') ?>
                                        </td>

                                        <td>
                                            <?php if ($memilikiResep): ?>

                                                Rp <?= number_format((float) $mm['hpp'], 0, ',', '.') ?>

                                            <?php else: ?>

                                                <span class="manager-status warning">
                                                    Belum ada resep
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($memilikiResep): ?>
                                                <strong class="<?= $margin >= 0 ? 'margin-positive' : 'margin-negative' ?>">
                                                    Rp <?= number_format($margin, 0, ',', '.') ?>
                                                </strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>

                                <tr>
                                    <td colspan="5" class="manager-table-empty">
                                        Belum ada menu terdaftar.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- footer -->
        <footer class="manager-footer">
            Kedai Kopi Senja · Dashboard Manajer
        </footer>
    </main>
</div>
</body>
</html>