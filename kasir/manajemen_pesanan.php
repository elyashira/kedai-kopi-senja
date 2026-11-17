<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('kasir');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pesanan_id = intval($_POST['pesanan_id'] ?? 0);
    $aksi = $_POST['aksi'] ?? '';

    if ($pesanan_id > 0) {
        try {
            $pdo->beginTransaction();

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

                $pdo->prepare("UPDATE pesanan SET status = 'Diproses' WHERE id = :id")
                    ->execute(['id' => $pesanan_id]);
                $success = "Status pesanan {$pesanan['kode_pesanan']} diubah menjadi Diproses.";

            } elseif ($aksi === 'selesai') {
                if ($pesanan['status'] !== 'Diproses') {
                    throw new Exception("Status pesanan harus 'Diproses' sebelum diselesaikan.");
                }

                $pdo->prepare("UPDATE pesanan SET status = 'Selesai' WHERE id = :id")
                    ->execute(['id' => $pesanan_id]);
                $success = "Pesanan {$pesanan['kode_pesanan']} telah Selesai.";

            } elseif ($aksi === 'batal') {
                if ($pesanan['status'] !== 'Baru') {
                    throw new Exception("Pesanan hanya dapat dibatalkan saat statusnya masih 'Baru'.");
                }

                $stmtD = $pdo->prepare("SELECT * FROM detail_pesanan WHERE pesanan_id = :p_id");
                $stmtD->execute(['p_id' => $pesanan_id]);
                $details = $stmtD->fetchAll();

                foreach ($details as $d) {
                    $m_id = $d['menu_id'];
                    $qty = $d['jumlah_porsi'];

                    $stmtR = $pdo->prepare("SELECT * FROM resep WHERE menu_id = :m_id");
                    $stmtR->execute(['m_id' => $m_id]);
                    $resep_list = $stmtR->fetchAll();

                    foreach ($resep_list as $r) {
                        $b_id = $r['bahan_id'];
                        $kembali = $r['takaran'] * $qty;

                        $pdo->prepare("UPDATE bahan SET stok = stok + :kembali WHERE id = :b_id")
                            ->execute([
                                'kembali' => $kembali,
                                'b_id' => $b_id
                            ]);
                    }

                    $pdo->prepare("UPDATE menu SET status = 'Aktif' WHERE id = :m_id AND status = 'Habis'")
                        ->execute(['m_id' => $m_id]);
                }

                $pdo->prepare("UPDATE pesanan SET status = 'Dibatalkan' WHERE id = :id")
                    ->execute(['id' => $pesanan_id]);
                $success = "Pesanan {$pesanan['kode_pesanan']} berhasil dibatalkan dan stok bahan telah dikembalikan.";
            }

            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
    <title>Manajemen Pesanan - Kedai Kopi Senja</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
</head>

<body class="kasir-body">
<div class="kasir-layout">
    <!-- header -->
    <header class="kasir-header">
        <div class="kasir-brand">
                <strong>KEDAI KOPI SENJA</strong>
            </div>

        <div class="kasir-user">
            <span>Kasir</span>
            <a href="<?= BASEURL ?>/logout.php">Logout</a>
        </div>
    </header>

    <!-- konten -->
    <main class="kasir-main" style="grid-template-columns: minmax(0, 1fr);">
        <section class="kasir-menu">
            
            <!-- notifikasi -->
            <?php if (!empty($error)): ?>
                <div class="alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <!-- tabel pesanan -->
            <div class="kasir-order"
                 style="position:static; min-height:0; width:100%;">
                <div class="order-header">
                    <h3>Daftar Pesanan</h3>
                    <span>
                        <?= count($pesanan_list) ?> Pesanan
                    </span>
                </div>

                <div class="table-responsive" style="margin-top:15px;">
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
                                        <td>
                                            <strong>
                                                <?= htmlspecialchars($p['kode_pesanan']) ?>
                                            </strong>
                                            <br>
                                            <small><?= htmlspecialchars($p['created_at']) ?></small>
                                        </td>
                                        <td>
                                            Meja <?= htmlspecialchars($p['nomor_meja']) ?>
                                        </td>
                                        <td>
                                            <?= $p['nama_pelanggan']
                                                ? htmlspecialchars($p['nama_pelanggan'])
                                                : 'Kasir (Offline)' ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($p['metode_bayar']) ?>
                                        </td>
                                        <td>
                                            <strong>
                                                Rp <?= number_format($p['total_bayar'], 0, ',', '.') ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="badge">
                                                <?= htmlspecialchars($p['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST"
                                                  action=""
                                                  style="display:flex; gap:6px; align-items:center;">
                                                <input type="hidden"
                                                       name="pesanan_id"
                                                       value="<?= $p['id'] ?>">

                                                <?php if ($p['status'] === 'Baru'): ?>
                                                    <button type="submit"
                                                            name="aksi"
                                                            value="proses"
                                                            class="btn-primary"
                                                            style="padding:7px 11px; font-size:11px;">
                                                        Proses
                                                    </button>

                                                    <button type="submit"
                                                            name="aksi"
                                                            value="batal"
                                                            class="btn-secondary"
                                                            style="padding:7px 11px; font-size:11px;"
                                                            onclick="return confirm('Yakin ingin membatalkan pesanan ini? Stok akan dikembalikan.')">
                                                        Batalkan
                                                    </button>

                                                <?php elseif ($p['status'] === 'Diproses'): ?>

                                                    <button type="submit"
                                                            name="aksi"
                                                            value="selesai"
                                                            class="btn-primary"
                                                            style="padding:7px 11px; font-size:11px;">
                                                        Selesai
                                                    </button>

                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);"> - </span>
                                                <?php endif; ?>

                                                <!-- Tombol Struk -->
                                                <a href="struk_pesanan.php?id=<?= (int)$p['id'] ?>"
                                                   target="_blank"
                                                   class="btn-secondary"
                                                   style="padding:7px 11px; font-size:11px; text-decoration:none;">
                                                    Struk
                                                </a>

                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">
                                        Belum ada data pesanan.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <!-- navigasi bawah -->
    <footer class="kasir-bottom-nav">
        <a href="<?= BASEURL ?>/kasir/index.php"
           class="bottom-nav-item">
            <span>🏠︎</span>
            <small>Menu</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php"
           class="bottom-nav-item">
            <span>🗒</span>
            <small>Pesanan</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/reset_password.php"
           class="bottom-nav-item">
            <span>⟳</span>
            <small>Reset Akun</small>
        </a>
    </footer>
</div>
</body>
</html>