<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('kasir');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pesanan_id = intval($_POST['pesanan_id'] ?? 0);
    $aksi = $_POST['aksi'] ?? ''; // 'proses', 'selesai', 'batal'

    if ($pesanan_id > 0) {
        try {
            $pdo->beginTransaction();

            // ambil data pesanan
            $stmtP = $pdo->prepare("SELECT * FROM pesanan WHERE id = :id FOR UPDATE");
            $stmtP->execute(['id' => $pesanan_id]);
            $pesanan = $stmtP->fetch();

            if (!$pesanan) {
                throw new Exception("Pesanan tidak ditemukan.");
            }

            if ($aksi === 'proses') {
                if ($pesanan['status'] !== 'Baru') {
                    throw new Exception("Status pesanan harus 'Baru' untuk diubah ke 'Diproses'.");
                }
                $pdo->prepare("UPDATE pesanan SET status = 'Diproses' WHERE id = :id")->execute(['id' => $pesanan_id]);
                $success = "Status pesanan {$pesanan['kode_pesanan']} diubah menjadi Diproses.";

            } elseif ($aksi === 'selesai') {
                if ($pesanan['status'] !== 'Diproses') {
                    throw new Exception("Status pesanan harus 'Diproses' sebelum diselesaikan (tidak boleh loncat).");
                }
                $pdo->prepare("UPDATE pesanan SET status = 'Selesai' WHERE id = :id")->execute(['id' => $pesanan_id]);
                $success = "Status pesanan {$pesanan['kode_pesanan']} telah Selesai.";

            } elseif ($aksi === 'batal') {
                if ($pesanan['status'] !== 'Baru') {
                    throw new Exception("Pesanan hanya dapat dibatalkan saat statusnya masih 'Baru'. Pesanan yang sudah diproses tidak dapat dibatalkan.");
                }

                // kembalikan stok
                $stmtD = $pdo->prepare("SELECT * FROM detail_pesanan WHERE pesanan_id = :p_id");
                $stmtD->execute(['p_id' => $pesanan_id]);
                $details = $stmtD->fetchAll();

                foreach ($details as $d) {
                    $m_id = $d['menu_id'];
                    $qty = $d['jumlah_porsi'];

                    // ambil resep menu tersebut
                    $stmtR = $pdo->prepare("SELECT * FROM resep WHERE menu_id = :m_id");
                    $stmtR->execute(['m_id' => $m_id]);
                    $resep_list = $stmtR->fetchAll();

                    foreach ($resep_list as $r) {
                        $b_id = $r['bahan_id'];
                        $kembali = $r['takaran'] * $qty;

                        // tambahkan stok bahan
                        $pdo->prepare("UPDATE bahan SET stok = stok + :kembali WHERE id = :b_id")
                            ->execute(['kembali' => $kembali, 'b_id' => $b_id]);
                    }

                    // perbarui status menu jika sebelumnya 'Habis' dan sekarang bahan sudah cukup kembali
                    $pdo->prepare("UPDATE menu SET status = 'Aktif' WHERE id = :m_id AND status = 'Habis'")
                        ->execute(['m_id' => $m_id]);
                }

                // dibatalkan
                $pdo->prepare("UPDATE pesanan SET status = 'Dibatalkan' WHERE id = :id")->execute(['id' => $pesanan_id]);
                $success = "Pesanan {$pesanan['kode_pesanan']} berhasil dibatalkan dan stok bahan telah dikembalikan.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$stmtList = $pdo->query("
    SELECT p.*, u.nama AS nama_pelanggan 
    FROM pesanan p 
    LEFT JOIN users u ON p.pelanggan_id = u.id 
    ORDER BY p.id DESC
");
$pesanan_list = $stmtList->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Pesanan - Kasir Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">Kedai Kopi Senja - Kasir</div>
        <div class="nav-menu">
            <a href="<?= BASEURL ?>/kasir/index.php">Daftar Menu</a>
            <a href="<?= BASEURL ?>/kasir/transaksi.php">Buat Pesanan</a>
            <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php" class="active">Manajemen Pesanan</a>
            <a href="<?= BASEURL ?>/kasir/reset_password.php">Reset Password Pelanggan</a>
            <a href="<?= BASEURL ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Manajemen & Riwayat Pesanan</h2>
        <p class="subtitle">Kelola status pesanan (Baru &rarr; Diproses &rarr; Selesai) atau batalkan pesanan berstatus Baru.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kode Pesanan</th>
                        <th>Meja</th>
                        <th>Pemesan</th>
                        <th>Metode Bayar</th>
                        <th>Total Bayar</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pesanan_list) > 0): ?>
                        <?php foreach ($pesanan_list as $p): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['kode_pesanan']) ?></strong><br><small><?= $p['created_at'] ?></small></td>
                                <td>Meja <?= $p['nomor_meja'] ?></td>
                                <td><?= $p['nama_pelanggan'] ? htmlspecialchars($p['nama_pelanggan']) : 'Kasir (Offline)' ?></td>
                                <td><?= htmlspecialchars($p['metode_bayar']) ?></td>
                                <td>Rp <?= number_format($p['total_bayar'], 0, ',', '.') ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($p['status']) ?>">
                                        <?= htmlspecialchars($p['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <form action="" method="POST" style="display: inline-block;">
                                        <input type="hidden" name="pesanan_id" value="<?= $p['id'] ?>">
                                        
                                        <?php if ($p['status'] === 'Baru'): ?>
                                            <button type="submit" name="aksi" value="proses" class="btn-sm btn-info">Proses</button>
                                            <button type="submit" name="aksi" value="batal" class="btn-sm btn-danger" onclick="return confirm('Yakin ingin membatalkan pesanan ini? Stok akan dikembalikan.')">Batalkan</button>
                                        <?php elseif ($p['status'] === 'Diproses'): ?>
                                            <button type="submit" name="aksi" value="selesai" class="btn-sm btn-success">Selesai</button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">Belum ada data pesanan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>