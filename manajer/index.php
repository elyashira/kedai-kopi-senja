<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$error = '';
$success = '';

// sinkronisasi harga
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_supplier'])) {
    $ch = curl_init(SUPPLIER_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . SUPPLIER_API_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $data_supplier = json_decode($response, true);
        if (!empty($data_supplier)) {
            try {
                $pdo->beginTransaction();
                foreach ($data_supplier as $nama_bahan => $harga_baru) {
                    $stmtUpd = $pdo->prepare("
                        UPDATE bahan 
                        SET harga_satuan = :harga, last_updated_price = NOW() 
                        WHERE LOWER(TRIM(nama_bahan)) = LOWER(TRIM(:nama))
                    ");
                    $stmtUpd->execute(['harga' => $harga_baru, 'nama' => $nama_bahan]);
                }
                $pdo->commit();
                $success = "Harga bahan berhasil disinkronkan dari Supplier terbaru!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Gagal memperbarui harga dari supplier: " . $e->getMessage();
            }
        } else {
            $error = "Format data dari supplier tidak valid.";
        }
    } else {
        $error = "Peringatan: Layanan Supplier tidak dapat dihubungi (atau API key ditolak). Sistem menggunakan harga terakhir yang tersimpan di database (Fallback Aktif).";
    }
}

// statistik dashboard
$totalMenu = $pdo->query("SELECT COUNT(*) FROM menu")->fetchColumn();
$totalBahanMenipis = $pdo->query("SELECT COUNT(*) FROM bahan WHERE stok <= batas_minimum")->fetchColumn();
$totalPesananHariIni = $pdo->query("SELECT COUNT(*) FROM pesanan WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$pendapatanHariIni = $pdo->query("SELECT SUM(total_bayar) FROM pesanan WHERE status != 'Dibatalkan' AND DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Manajer - Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Manajer</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/manajer/index.php" class="active">Dashboard</a>
            <a href="<?= BASEURL ?>/manajer/bahan.php">Bahan & Stok</a>
            <a href="<?= BASEURL ?>/manajer/menu.php">Menu & Resep</a>
            <a href="<?= BASEURL ?>/manajer/pembelian.php">Stok Masuk</a>
            <a href="<?= BASEURL ?>/manajer/laporan.php">Laporan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout (<?= htmlspecialchars($_SESSION['nama']) ?>)</a>
        </div>
    </div>

    <div class="container">
        <h2>Dashboard Manajer Kedai Kopi Senja</h2>
        <p class="subtitle">Selamat datang kembali, Manajer. Pantau operasional kafe dan sinkronisasi harga bahan di sini.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error" style="background: #fff3cd; color: #856404; border: 1px solid #ffeeba;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <!-- statistik -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-top: 20px; margin-bottom: 30px;">
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h4 style="color: #666; margin-bottom: 5px;">Total Menu</h4>
                <h2 style="color: #2c3e50;"><?= $totalMenu ?> Menu</h2>
            </div>
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h4 style="color: #666; margin-bottom: 5px;">Bahan Menipis</h4>
                <h2 style="color: <?= $totalBahanMenipis > 0 ? 'red' : 'green' ?>;"><?= $totalBahanMenipis ?> Bahan</h2>
            </div>
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h4 style="color: #666; margin-bottom: 5px;">Pesanan Hari Ini</h4>
                <h2 style="color: #2c3e50;"><?= $totalPesananHariIni ?> Pesanan</h2>
            </div>
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h4 style="color: #666; margin-bottom: 5px;">Pendapatan Hari Ini</h4>
                <h2 style="color: #27ae60;">Rp <?= number_format($pendapatanHariIni, 0, ',', '.') ?></h2>
            </div>
        </div>

        <div class="card" style="background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <h3>Integrasi Harga Supplier</h3>
            <p style="margin-bottom: 15px; color: #555;">Perbarui harga bahan baku terbaru dari layanan supplier eksternal. Jika layanan tidak aktif, sistem secara otomatis menerapkan harga terakhir yang tersimpan.</p>
            <form action="" method="POST">
                <button type="submit" name="sync_supplier" value="1" class="btn-primary" style="padding: 10px 20px;">Sinkronkan Harga Supplier Sekarang</button>
            </form>
        </div>
    </div>
</body>
</html>