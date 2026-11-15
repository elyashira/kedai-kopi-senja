<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('kasir');

$error = '';
$success = '';

$stmtMenu = $pdo->query("
    SELECT m.* FROM menu m 
    WHERE m.status = 'Aktif' 
    AND EXISTS (SELECT 1 FROM resep r WHERE r.menu_id = m.id)
    ORDER BY m.nama_menu
");
$menus = $stmtMenu->fetchAll();

// Proses form pesanan dikirim
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomor_meja = intval($_POST['nomor_meja'] ?? 0);
    $metode_bayar = $_POST['metode_bayar'] ?? 'Tunai';
    $pesanan_items = $_POST['menu_item'] ?? []; 

    // validasi nomor meja (1-10)
    if ($nomor_meja < 1 || $nomor_meja > 10) {
        $error = 'Nomor meja tidak valid! Harus antara 1 sampai 10.';
    } else {
        // filter item yang jumlahnya > 0
        $items_to_order = [];
        foreach ($pesanan_items as $menu_id => $qty) {
            $qty = intval($qty);
            if ($qty > 0) {
                $items_to_order[$menu_id] = $qty;
            }
        }

        if (empty($items_to_order)) {
            $error = 'Pilih minimal satu menu dengan jumlah pesanan lebih dari 0!';
        } else {
            try {
                $pdo->beginTransaction();

                //menghitung total kebutuhan bahan dari seluruh item pesanan
                $kebutuhan_bahan = [];
                $detail_data = [];
                $subtotal = 0;

                foreach ($items_to_order as $menu_id => $qty) {
                    $stmtM = $pdo->prepare("SELECT * FROM menu WHERE id = :id AND status = 'Aktif'");
                    $stmtM->execute(['id' => $menu_id]);
                    $menu = $stmtM->fetch();

                    if (!$menu) {
                        throw new Exception("Menu dengan ID $menu_id tidak ditemukan atau tidak aktif.");
                    }

                    $item_subtotal = $menu['harga_jual'] * $qty;
                    $subtotal += $item_subtotal;

                    $detail_data[] = [
                        'menu_id' => $menu_id,
                        'jumlah_porsi' => $qty,
                        'harga_snapshot' => $menu['harga_jual'],
                        'subtotal_item' => $item_subtotal
                    ];

                    $stmtR = $pdo->prepare("SELECT * FROM resep WHERE menu_id = :menu_id");
                    $stmtR->execute(['menu_id' => $menu_id]);
                    $resep_list = $stmtR->fetchAll();

                    foreach ($resep_list as $r) {
                        $b_id = $r['bahan_id'];
                        $total_butuh = $r['takaran'] * $qty;

                        if (!isset($kebutuhan_bahan[$b_id])) {
                            $kebutuhan_bahan[$b_id] = 0;
                        }
                        $kebutuhan_bahan[$b_id] += $total_butuh;
                    }
                }

                // mengecek ketersediaan stok bahan di database
                $bahan_kurang = [];
                foreach ($kebutuhan_bahan as $b_id => $butuh) {
                    $stmtB = $pdo->prepare("SELECT * FROM bahan WHERE id = :id FOR UPDATE");
                    $stmtB->execute(['id' => $b_id]);
                    $bahan = $stmtB->fetch();

                    if ($bahan['stok'] < $butuh) {
                        $bahan_kurang[] = "{$bahan['nama_bahan']} (Kurang, butuh: $butuh {$bahan['satuan_dasar']}, tersedia: {$bahan['stok']} {$bahan['satuan_dasar']})";
                    }
                }

                if (!empty($bahan_kurang)) {
                    throw new Exception("Pesanan ditolak! Bahan baku tidak mencukupi:\n- " . implode("\n- ", $bahan_kurang));
                }

                // mengurangi stok bahan & perbarui status menu jika stok jadi < 1 porsi
                foreach ($kebutuhan_bahan as $b_id => $butuh) {
                    $stmtUpd = $pdo->prepare("UPDATE bahan SET stok = stok - :butuh WHERE id = :id");
                    $stmtUpd->execute(['butuh' => $butuh, 'id' => $b_id]);

                    // Cek apakah ada menu yang menggunakan bahan ini menjadi habis
                    $stmtMenuCek = $pdo->prepare("SELECT DISTINCT menu_id FROM resep WHERE bahan_id = :b_id");
                    $stmtMenuCek->execute(['b_id' => $b_id]);
                    $affected_menus = $stmtMenuCek->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($affected_menus as $m_id) {
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

                        if (!$bisa_dibuat) {
                            $pdo->prepare("UPDATE menu SET status = 'Habis' WHERE id = :m_id AND status = 'Aktif'")->execute(['m_id' => $m_id]);
                        }
                    }
                }

                // mengitung biaya
                $service_charge = $subtotal * 0.05;
                $pajak = ($subtotal + $service_charge) * 0.10;
                $total_bayar = $subtotal + $service_charge + $pajak;

                // simpan pesanan ke database
                $kode_pesanan = 'KKS-' . date('Ymd') . '-' . rand(100, 999);
                $stmtOrder = $pdo->prepare("
                    INSERT INTO pesanan (kode_pesanan, nomor_meja, metode_bayar, subtotal, service_charge, pajak, total_bayar, status) 
                    VALUES (:kode, :meja, :bayar, :sub, :service, :pajak, :total, 'Baru')
                ");
                $stmtOrder->execute([
                    'kode' => $kode_pesanan,
                    'meja' => $nomor_meja,
                    'bayar' => $metode_bayar,
                    'sub' => $subtotal,
                    'service' => $service_charge,
                    'pajak' => $pajak,
                    'total' => $total_bayar
                ]);
                $pesanan_id = $pdo->lastInsertId();

                // simpan detail pesanan
                $stmtDetail = $pdo->prepare("
                    INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah_porsi, harga_satuan_snapshot, subtotal_item) 
                    VALUES (:p_id, :m_id, :jml, :harga, :sub)
                ");
                foreach ($detail_data as $dt) {
                    $stmtDetail->execute([
                        'p_id' => $pesanan_id,
                        'm_id' => $dt['menu_id'],
                        'jml' => $dt['jumlah_porsi'],
                        'harga' => $dt['harga_snapshot'],
                        'sub' => $dt['subtotal_item']
                    ]);
                }

                $pdo->commit();
                $success = "Pesanan berhasil dibuat dengan Kode: <strong>$kode_pesanan</strong>! Stok telah terpotong otomatis.";

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Pesanan - Kasir Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Kasir</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/kasir/index.php">Daftar Menu</a>
            <a href="<?= BASEURL ?>/kasir/transaksi.php" class="active">Buat Pesanan</a>
            <a href="<?= BASEURL ?>/kasir/reset_password.php">Reset Password Pelanggan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Buat Pesanan Baru</h2>
        <p class="subtitle">Masukkan nomor meja, metode pembayaran, dan jumlah porsi menu yang dipesan.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error" style="white-space: pre-line;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <form action="" method="POST" class="form-transaksi">
            <div class="form-row">
                <div class="form-group">
                    <label>Nomor Meja (1 - 10)</label>
                    <input type="number" name="nomor_meja" min="1" max="10" required value="1">
                </div>
                <div class="form-group">
                    <label>Metode Pembayaran</label>
                    <select name="metode_bayar" required>
                        <option value="Tunai">Tunai</option>
                        <option value="QRIS">QRIS</option>
                    </select>
                </div>
            </div>

            <h3>Pilih Menu</h3>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Menu</th>
                            <th>Kategori</th>
                            <th>Harga Jual</th>
                            <th>Jumlah Porsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menus as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['nama_menu']) ?></td>
                                <td><?= htmlspecialchars($m['kategori']) ?></td>
                                <td>Rp <?= number_format($m['harga_jual'], 0, ',', '.') ?></td>
                                <td>
                                    <input type="number" name="menu_item[<?= $m['id'] ?>]" min="0" value="0" class="input-qty" style="width: 80px; padding: 5px;">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 20px;">Proses Pesanan & Potong Stok</button>
        </form>
    </div>
</body>
</html>