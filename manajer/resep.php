<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$menu_id = intval($_GET['menu_id'] ?? 0);
if ($menu_id <= 0) {
    header('Location: ' . BASEURL . '/manajer/menu.php');
    exit;
}

// mengambil info
$stmtM = $pdo->prepare("SELECT * FROM menu WHERE id = :id");
$stmtM->execute(['id' => $menu_id]);
$menu = $stmtM->fetch();

if (!$menu) {
    header('Location: ' . BASEURL . '/manajer/menu.php');
    exit;
}

$error = '';
$success = '';

// tambah/hapus bahan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah_resep') {
        $bahan_id = intval($_POST['bahan_id'] ?? 0);
        $takaran = floatval($_POST['takaran'] ?? 0);

        if ($bahan_id > 0 && $takaran > 0) {
            try {
                // cek bahan sudah ada di resep menu ini
                $stmtCek = $pdo->prepare("SELECT COUNT(*) FROM resep WHERE menu_id = :m_id AND bahan_id = :b_id");
                $stmtCek->execute(['m_id' => $menu_id, 'b_id' => $bahan_id]);
                if ($stmtCek->fetchColumn() > 0) {
                    throw new Exception("Bahan ini sudah ada dalam resep menu tersebut. Silakan hapus atau edit jika ingin mengubah takaran.");
                }

                $stmtIns = $pdo->prepare("INSERT INTO resep (menu_id, bahan_id, takaran) VALUES (:m_id, :b_id, :takaran)");
                $stmtIns->execute(['m_id' => $menu_id, 'b_id' => $bahan_id, 'takaran' => $takaran]);
                $success = "Bahan berhasil ditambahkan ke resep!";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = "Pilih bahan dan masukkan takaran yang valid!";
        }
    } elseif ($aksi === 'hapus_resep') {
        $resep_id = intval($_POST['resep_id'] ?? 0);
        $pdo->prepare("DELETE FROM resep WHERE id = :id")->execute(['id' => $resep_id]);
        $success = "Bahan berhasil dihapus dari resep.";
    }
}

// ambil daftar resep untuk menu ini
$stmtResep = $pdo->prepare("
    SELECT r.*, b.nama_bahan, b.satuan_dasar, b.harga_satuan 
    FROM resep r 
    JOIN bahan b ON r.bahan_id = b.id 
    WHERE r.menu_id = :m_id
");
$stmtResep->execute(['m_id' => $menu_id]);
$resep_list = $stmtResep->fetchAll();

// dropdown bahan
$stmtBahan = $pdo->query("SELECT * FROM bahan ORDER BY nama_bahan");
$bahan_list = $stmtBahan->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Resep <?= htmlspecialchars($menu['nama_menu']) ?> - Manajer</title>
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
        <h2>Kelola Resep Menu: <?= htmlspecialchars($menu['nama_menu']) ?></h2>
        <p class="subtitle">Kategori: <?= htmlspecialchars($menu['kategori']) ?> | Harga Jual: Rp <?= number_format($menu['harga_jual'], 0, ',', '.') ?></p>

        <p><a href="<?= BASEURL ?>/manajer/menu.php" class="btn-sm btn-info">&larr; Kembali ke Daftar Menu</a></p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <h3>Tambah Komposisi Bahan Resep</h3>
            <form action="" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 15px;">
                <input type="hidden" name="aksi" value="tambah_resep">
                <div class="form-group">
                    <label>Pilih Bahan Baku</label>
                    <select name="bahan_id" required style="width: 100%; padding: 10px;">
                        <option value="">-- Pilih Bahan --</option>
                        <?php foreach ($bahan_list as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['nama_bahan']) ?> (Satuan: <?= $b['satuan_dasar'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Takaran Sesuai Satuan Dasar</label>
                    <input type="number" step="0.01" name="takaran" required placeholder="Contoh: 18 (gram/ml/pcs)" style="width: 100%; padding: 10px;">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 11px;">Tambahkan ke Resep</button>
                </div>
            </form>
        </div>

        <h3>Komposisi Bahan Saat Ini</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Bahan</th>
                        <th>Takaran per Porsi</th>
                        <th>Harga Satuan Bahan</th>
                        <th>Subtotal HPP Bahan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_hpp = 0;
                    if (count($resep_list) > 0): 
                    ?>
                        <?php foreach ($resep_list as $r): 
                            $sub_hpp = $r['takaran'] * $r['harga_satuan'];
                            $total_hpp += $sub_hpp;
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['nama_bahan']) ?></strong></td>
                                <td><?= $r['takaran'] . ' ' . $r['satuan_dasar'] ?></td>
                                <td>Rp <?= number_format($r['harga_satuan'], 2, ',', '.') ?></td>
                                <td>Rp <?= number_format($sub_hpp, 2, ',', '.') ?></td>
                                <td>
                                    <form action="" method="POST" onsubmit="return confirm('Hapus bahan ini dari resep?')">
                                        <input type="hidden" name="aksi" value="hapus_resep">
                                        <input type="hidden" name="resep_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn-sm btn-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="3" style="text-align: right;"><strong>Total HPP Menu:</strong></td>
                            <td colspan="2"><strong style="color: #333;">Rp <?= number_format($total_hpp, 2, ',', '.') ?></strong></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">Belum ada bahan dalam resep menu ini (Menu belum bisa dipesan).</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>