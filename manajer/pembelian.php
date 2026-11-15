<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$error = '';
$success = '';

//proses penyimpanan stok masuk
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bahan_id = intval($_POST['bahan_id'] ?? 0);
    $jumlah_masuk = floatval($_POST['jumlah_masuk'] ?? 0);
    $tanggal_pembelian = $_POST['tanggal_pembelian'] ?? date('Y-m-d');

    if ($bahan_id > 0 && $jumlah_masuk > 0) {
        try {
            $pdo->beginTransaction();

            // 1. catat pembelian
            $stmtIns = $pdo->prepare("
                INSERT INTO pembelian_bahan (bahan_id, jumlah_masuk, tanggal_pembelian) 
                VALUES (:bahan_id, :jumlah, :tgl)
            ");
            $stmtIns->execute([
                'bahan_id' => $bahan_id,
                'jumlah' => $jumlah_masuk,
                'tgl' => $tanggal_pembelian
            ]);

            // 2. tambah stok di tabel bahan
            $stmtUpd = $pdo->prepare("UPDATE bahan SET stok = stok + :jumlah WHERE id = :id");
            $stmtUpd->execute([
                'jumlah' => $jumlah_masuk,
                'id' => $bahan_id
            ]);

            // 3. periksa kecukupan menu
            $stmtMenuCek = $pdo->prepare("SELECT DISTINCT menu_id FROM resep WHERE bahan_id = :b_id");
            $stmtMenuCek->execute(['b_id' => $bahan_id]);
            $affected_menus = $stmtMenuCek->fetchAll(PDO::FETCH_COLUMN);

            foreach ($affected_menus as $m_id) {
                // cek minimum stok (minimal 1 porsi)
                $stmtResepM = $pdo->prepare("
                    SELECT r.takaran, b.stok 
                    FROM resep r 
                    JOIN bahan b ON r.bahan_id = b.id 
                    WHERE r.menu_id = :m_id
                ");
                $stmtResepM->execute(['m_id' => $m_id]);
                $resep_items = $stmtResepM->fetchAll();

                $bisa_dibuat = true;
                foreach ($resep_items as $ri) {
                    if ($ri['stok'] < $ri['takaran']) {
                        $bisa_dibuat = false;
                        break;
                    }
                }

                if ($bisa_dibuat) {
                    $pdo->prepare("UPDATE menu SET status = 'Aktif' WHERE id = :m_id AND status = 'Habis'")
                        ->execute(['m_id' => $m_id]);
                }
            }

            $pdo->commit();
            $success = "Stok masuk berhasil dicatat dan inventaris bahan telah bertambah!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Gagal mencatat stok masuk: " . $e->getMessage();
        }
    } else {
        $error = "Pilih bahan dan masukkan jumlah stok masuk yang valid!";
    }
}

$stmtBahan = $pdo->query("SELECT id, nama_bahan, satuan_dasar, stok FROM bahan ORDER BY nama_bahan");
$bahan_list = $stmtBahan->fetchAll();

$stmtRiwayat = $pdo->query("
    SELECT pb.*, b.nama_bahan, b.satuan_dasar 
    FROM pembelian_bahan pb 
    JOIN bahan b ON pb.bahan_id = b.id 
    ORDER BY pb.id DESC LIMIT 50
");
$riwayat_list = $stmtRiwayat->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Masuk - Manajer Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Manajer</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/manajer/index.php">Dashboard</a>
            <a href="<?= BASEURL ?>/manajer/bahan.php">Bahan & Stok</a>
            <a href="<?= BASEURL ?>/manajer/menu.php">Menu & Resep</a>
            <a href="<?= BASEURL ?>/manajer/pembelian.php" class="active">Stok Masuk</a>
            <a href="<?= BASEURL ?>/manajer/laporan.php">Laporan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Pencatatan Stok Masuk (Pembelian Bahan)</h2>
        <p class="subtitle">Catat penambahan stok bahan baku dari supplier atau pasar untuk memperbarui inventaris kedai.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <h3>Form Pembelian / Stok Masuk</h3>
            <form action="" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 15px;">
                <div class="form-group">
                    <label>Pilih Bahan Baku</label>
                    <select name="bahan_id" required style="width: 100%; padding: 10px;">
                        <option value="">-- Pilih Bahan --</option>
                        <?php foreach ($bahan_list as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['nama_bahan']) ?> (Stok saat ini: <?= $b['stok'] . ' ' . $b['satuan_dasar'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jumlah Masuk</label>
                    <input type="number" step="0.01" name="jumlah_masuk" required placeholder="Contoh: 1000" style="width: 100%; padding: 10px;">
                </div>
                <div class="form-group">
                    <label>Tanggal Pembelian</label>
                    <input type="date" name="tanggal_pembelian" required value="<?= date('Y-m-d') ?>" style="width: 100%; padding: 10px;">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 11px;">Simpan Stok Masuk</button>
                </div>
            </form>
        </div>

        <h3>Riwayat Pembelian Bahan</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Nama Bahan</th>
                        <th>Jumlah Masuk</th>
                        <th>Waktu Pencatatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($riwayat_list) > 0): ?>
                        <?php foreach ($riwayat_list as $rw): ?>
                            <tr>
                                <?= htmlspecialchars($rw['tanggal_pembelian']) ?></td>
                                <td><strong><?= htmlspecialchars($rw['nama_bahan']) ?></strong></td>
                                <td>+<?= $rw['jumlah_masuk'] . ' ' . $rw['satuan_dasar'] ?></td>
                                <td><?= $rw['created_at'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center">Belum ada riwayat pembelian bahan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>