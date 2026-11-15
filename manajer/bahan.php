<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$error = '';
$success = '';

// proses tambah bahan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah') {
        $nama_bahan = trim($_POST['nama_bahan'] ?? '');
        $satuan_dasar = $_POST['satuan_dasar'] ?? 'g';
        $harga_satuan = floatval($_POST['harga_satuan'] ?? 0);
        $stok_awal = floatval($_POST['stok_awal'] ?? 0);
        $batas_minimum = floatval($_POST['batas_minimum'] ?? 0);

        if (!empty($nama_bahan)) {
            try {
                // cek nama
                $stmtCek = $pdo->prepare("SELECT COUNT(*) FROM bahan WHERE LOWER(TRIM(nama_bahan)) = LOWER(TRIM(:nama))");
                $stmtCek->execute(['nama' => $nama_bahan]);
                if ($stmtCek->fetchColumn() > 0) {
                    throw new Exception("Nama bahan sudah terdaftar (tidak boleh ganda).");
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO bahan (nama_bahan, satuan_dasar, harga_satuan, stok, batas_minimum, last_updated_price) 
                    VALUES (:nama, :satuan, :harga, :stok, :batas, NOW())
                ");
                $stmtIns->execute([
                    'nama' => $nama_bahan,
                    'satuan' => $satuan_dasar,
                    'harga' => $harga_satuan,
                    'stok' => $stok_awal,
                    'batas' => $batas_minimum
                ]);
                $success = "Bahan baru berhasil ditambahkan!";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = "Nama bahan wajib diisi!";
        }
    } elseif ($aksi === 'update_batas') {
        $bahan_id = intval($_POST['bahan_id'] ?? 0);
        $batas_minimum = floatval($_POST['batas_minimum'] ?? 0);

        if ($bahan_id > 0) {
            $stmtUpd = $pdo->prepare("UPDATE bahan SET batas_minimum = :batas WHERE id = :id");
            $stmtUpd->execute(['batas' => $batas_minimum, 'id' => $bahan_id]);
            $success = "Batas minimum bahan berhasil diperbarui.";
        }
    }
}

// ambil daftar bahan
$stmtBahan = $pdo->query("SELECT * FROM bahan ORDER BY nama_bahan");
$bahan_list = $stmtBahan->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Bahan - Manajer Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Manajer</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/manajer/index.php">Dashboard</a>
            <a href="<?= BASEURL ?>/manajer/bahan.php" class="active">Bahan & Stok</a>
            <a href="<?= BASEURL ?>/manajer/menu.php">Menu & Resep</a>
            <a href="<?= BASEURL ?>/manajer/pembelian.php">Stok Masuk</a>
            <a href="<?= BASEURL ?>/manajer/laporan.php">Laporan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Manajemen Bahan Baku & Batas Minimum</h2>
        <p class="subtitle">Kelola inventaris bahan dasar, satuan, harga satuan, dan batas minimum stok untuk deteksi bahan menipis.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <!-- form tambah bahan -->
        <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <h3>Tambah Bahan Baku Baru</h3>
            <form action="" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <input type="hidden" name="aksi" value="tambah">
                <div class="form-group">
                    <label>Nama Bahan</label>
                    <input type="text" name="nama_bahan" required placeholder="Contoh: Susu UHT">
                </div>
                <div class="form-group">
                    <label>Satuan Dasar</label>
                    <select name="satuan_dasar" required>
                        <option value="g">Gram (g)</option>
                        <option value="ml">Mililiter (ml)</option>
                        <option value="pcs">Pcs</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Harga Satuan (Rp)</label>
                    <input type="number" step="0.01" name="harga_satuan" required value="0">
                </div>
                <div class="form-group">
                    <label>Stok Awal</label>
                    <input type="number" step="0.01" name="stok_awal" required value="0">
                </div>
                <div class="form-group">
                    <label>Batas Minimum</label>
                    <input type="number" step="0.01" name="batas_minimum" required value="0">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 10px;">Simpan Bahan</button>
                </div>
            </form>
        </div>

        <!-- tabel -->
        <h3>Daftar Bahan Baku & Status Stok</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Bahan</th>
                        <th>Satuan Dasar</th>
                        <th>Harga Satuan</th>
                        <th>Stok Saat Ini</th>
                        <th>Batas Minimum</th>
                        <th>Status Stok</th>
                        <th>Aksi (Ubah Batas Min)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($bahan_list) > 0): ?>
                        <?php foreach ($bahan_list as $b): ?>
                            <?php 
                                $is_menipis = $b['stok'] <= $b['batas_minimum'];
                            ?>
                            <tr class="<?= $is_menipis ? 'table-row-warning' : '' ?>">
                                <td><strong><?= htmlspecialchars($b['nama_bahan']) ?></strong></td>
                                <td><?= htmlspecialchars($b['satuan_dasar']) ?></td>
                                <td>Rp <?= number_format($b['harga_satuan'], 2, ',', '.') ?></td>
                                <td><strong><?= $b['stok'] ?></strong></td>
                                <td><?= $b['batas_minimum'] ?></td>
                                <td>
                                    <?php if ($is_menipis): ?>
                                        <span class="badge badge-danger">Menipis / Habis!</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Aman</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="" method="POST" style="display: inline-flex; gap: 5px;">
                                        <input type="hidden" name="aksi" value="update_batas">
                                        <input type="hidden" name="bahan_id" value="<?= $b['id'] ?>">
                                        <input type="number" step="0.01" name="batas_minimum" value="<?= $b['batas_minimum'] ?>" style="width: 80px; padding: 4px;" required>
                                        <button type="submit" class="btn-sm btn-info">Update</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">Belum ada data bahan baku.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>