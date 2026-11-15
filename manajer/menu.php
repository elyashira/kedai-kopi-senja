<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah_menu') {
        $nama_menu = trim($_POST['nama_menu'] ?? '');
        $kategori = $_POST['kategori'] ?? 'Kopi';
        $harga_jual = floatval($_POST['harga_jual'] ?? 0);

        if (!empty($nama_menu) && $harga_jual > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO menu (nama_menu, kategori, harga_jual, status) VALUES (:nama, :kategori, :harga, 'Aktif')");
                $stmt->execute([
                    'nama' => $nama_menu,
                    'kategori' => $kategori,
                    'harga' => $harga_jual
                ]);
                $success = "Menu baru berhasil ditambahkan! Silakan atur resepnya.";
            } catch (Exception $e) {
                $error = "Gagal menambah menu: " . $e->getMessage();
            }
        } else {
            $error = "Nama menu dan harga jual wajib diisi dengan benar!";
        }
    } elseif ($aksi === 'toggle_status') {
        $menu_id = intval($_POST['menu_id'] ?? 0);
        $status_sekarang = $_POST['status_sekarang'] ?? 'Aktif';

        $statusBaru = ($status_sekarang === 'Aktif') ? 'Nonaktif' : 'Aktif';

        $stmt = $pdo->prepare("UPDATE menu SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $statusBaru, 'id' => $menu_id]);
        $success = "Status menu berhasil diubah menjadi $statusBaru.";
    }
}

$stmtMenu = $pdo->query("SELECT * FROM menu ORDER BY kategori, nama_menu");
$menus = $stmtMenu->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Menu & Resep - Manajer Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Manajer</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/manajer/index.php">Dashboard</a>
            <a href="<?= BASEURL ?>/manajer/bahan.php">Bahan & Stok</a>
            <a href="<?= BASEURL ?>/manajer/menu.php" class="active">Menu & Resep</a>
            <a href="<?= BASEURL ?>/manajer/pembelian.php">Stok Masuk</a>
            <a href="<?= BASEURL ?>/manajer/laporan.php">Laporan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Manajemen Menu, Resep, HPP, & Margin</h2>
        <p class="subtitle">Kelola daftar menu, atur status aktif/nonaktif, hitung HPP otomatis dari resep, dan lihat margin keuntungan.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <h3>Tambah Menu Baru</h3>
            <form action="" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 15px;">
                <input type="hidden" name="aksi" value="tambah_menu">
                <div class="form-group">
                    <label>Nama Menu</label>
                    <input type="text" name="nama_menu" required placeholder="Contoh: Caffe Latte">
                </div>
                <div class="form-group">
                    <label>Kategori</label>
                    <select name="kategori" required>
                        <option value="Kopi">Kopi</option>
                        <option value="Non-Kopi">Non-Kopi</option>
                        <option value="Makanan Ringan">Makanan Ringan</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Harga Jual (Rp)</label>
                    <input type="number" step="0.01" name="harga_jual" required placeholder="Contoh: 25000">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 11px;">Simpan Menu</button>
                </div>
            </form>
        </div>

        <h3>Daftar Menu, HPP, & Margin</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Menu</th>
                        <th>Kategori</th>
                        <th>Harga Jual</th>
                        <th>HPP (Modal)</th>
                        <th>Margin Keuntungan</th>
                        <th>Status</th>
                        <th>Aksi / Kelola Resep</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($menus) > 0): ?>
                        <?php foreach ($menus as $m): ?>
                            <?php
                                // hitung HPP dari resep & harga satuan bahan
                                $stmtR = $pdo->prepare("
                                    SELECT SUM(r.takaran * b.harga_satuan) AS hpp 
                                    FROM resep r 
                                    JOIN bahan b ON r.bahan_id = b.id 
                                    WHERE r.menu_id = :m_id
                                ");
                                $stmtR->execute(['m_id' => $m['id']]);
                                $hpp_data = $stmtR->fetch();
                                $hpp = floatval($hpp_data['hpp'] ?? 0);
                                $margin = $m['harga_jual'] - $hpp;

                                // pengecekan menu terdapat resep atau tidak
                                $stmtCekResep = $pdo->prepare("SELECT COUNT(*) FROM resep WHERE menu_id = :m_id");
                                $stmtCekResep->execute(['m_id' => $m['id']]);
                                $punya_resep = $stmtCekResep->fetchColumn() > 0;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($m['nama_menu']) ?></strong></td>
                                <td><?= htmlspecialchars($m['kategori']) ?></td>
                                <td>Rp <?= number_format($m['harga_jual'], 0, ',', '.') ?></td>
                                <td>Rp <?= number_format($hpp, 0, ',', '.') ?></td>
                                <td><strong style="color: <?= $margin >= 0 ? 'green' : 'red' ?>;">Rp <?= number_format($margin, 0, ',', '.') ?></strong></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($m['status']) ?>">
                                        <?= htmlspecialchars($m['status']) ?>
                                    </span>
                                    <?php if (!$punya_resep): ?>
                                        <br><small style="color: red;">(Belum ada resep!)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px; align-items: center;">
                                        <a href="<?= BASEURL ?>/manajer/resep.php?menu_id=<?= $m['id'] ?>" class="btn-sm btn-info">Atur Resep</a>
                                        
                                        <form action="" method="POST" style="display: inline;">
                                            <input type="hidden" name="aksi" value="toggle_status">
                                            <input type="hidden" name="menu_id" value="<?= $m['id'] ?>">
                                            <input type="hidden" name="status_sekarang" value="<?= $m['status'] ?>">
                                            <?php if ($m['status'] === 'Aktif'): ?>
                                                <button type="submit" class="btn-sm btn-danger">Nonaktifkan</button>
                                            <?php else: ?>
                                                <button type="submit" class="btn-sm btn-success">Aktifkan</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">Belum ada menu yang terdaftar.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>