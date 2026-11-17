
<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$namaManajer = $_SESSION['nama'] ?? 'Manajer';

$error = '';
$success = '';

// proses penyimpanan stok masuk
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bahanId = filter_var(
        $_POST['bahan_id'] ?? 0,
        FILTER_VALIDATE_INT
    );

    $jumlahInput = trim($_POST['jumlah_masuk'] ?? '');
    $tanggalPembelian = trim(
        $_POST['tanggal_pembelian'] ?? ''
    );

    $jumlahMasuk = is_numeric($jumlahInput)
        ? (float) $jumlahInput
        : 0;

    // validasi input
    if (!$bahanId || $bahanId <= 0) {
        $error = "Pilih bahan baku yang valid!";
    } elseif ($jumlahInput === '' || !is_numeric($jumlahInput) || $jumlahMasuk <= 0) {
        $error = "Jumlah stok masuk harus lebih dari 0!";
    } elseif (
        !DateTime::createFromFormat('Y-m-d', $tanggalPembelian) ||
        DateTime::createFromFormat('Y-m-d', $tanggalPembelian)->format('Y-m-d') !== $tanggalPembelian
    ) {
        $error = "Tanggal pembelian tidak valid!";
    } else {
        try {
            $pdo->beginTransaction();

            // cek dan kunci bahan yang dipilih
            $stmtCek = $pdo->prepare("
                SELECT id, nama_bahan, stok
                FROM bahan
                WHERE id = :id
                FOR UPDATE
            ");

            $stmtCek->execute([
                'id' => $bahanId
            ]);

            $bahan = $stmtCek->fetch(PDO::FETCH_ASSOC);

            if (!$bahan) {
                throw new Exception("Bahan baku tidak ditemukan!");
            }

            // catat pembelian
            $stmtIns = $pdo->prepare("
                INSERT INTO pembelian_bahan
                    (bahan_id, jumlah_masuk, tanggal_pembelian)
                VALUES
                    (:bahan_id, :jumlah, :tgl)
            ");

            $stmtIns->execute([
                'bahan_id' => $bahanId,
                'jumlah' => $jumlahMasuk,
                'tgl' => $tanggalPembelian
            ]);

            // tambah stok bahan
            $stmtUpd = $pdo->prepare("
                UPDATE bahan
                SET stok = stok + :jumlah
                WHERE id = :id
            ");

            $stmtUpd->execute([
                'jumlah' => $jumlahMasuk,
                'id' => $bahanId
            ]);

            // cek seluruh menu yang menggunakan bahan tersebut
            $stmtMenuCek = $pdo->prepare("
                SELECT DISTINCT menu_id
                FROM resep
                WHERE bahan_id = :b_id
            ");

            $stmtMenuCek->execute([
                'b_id' => $bahanId
            ]);

            $menuTerkait = $stmtMenuCek->fetchAll(PDO::FETCH_COLUMN);

            foreach ($menuTerkait as $menuId) {

                // cek kecukupan semua bahan untuk satu porsi
                $stmtResep = $pdo->prepare("
                    SELECT r.takaran, b.stok
                    FROM resep r
                    JOIN bahan b ON r.bahan_id = b.id
                    WHERE r.menu_id = :m_id
                ");

                $stmtResep->execute([
                    'm_id' => $menuId
                ]);

                $resepItems = $stmtResep->fetchAll(PDO::FETCH_ASSOC);

                // menu tanpa resep tidak boleh diaktifkan
                if (count($resepItems) === 0) {
                    continue;
                }

                $bisaDibuat = true;

                foreach ($resepItems as $item) {
                    if ((float) $item['stok'] < (float) $item['takaran']) {
                        $bisaDibuat = false;
                        break;
                    }
                }

                // aktifkan kembali hanya menu yang sebelumnya Habis
                if ($bisaDibuat) {
                    $stmtAktif = $pdo->prepare("
                        UPDATE menu
                        SET status = 'Aktif'
                        WHERE id = :m_id
                        AND status = 'Habis'
                    ");

                    $stmtAktif->execute([
                        'm_id' => $menuId
                    ]);
                }
            }

            $pdo->commit();

            $success = "Stok masuk berhasil dicatat dan inventaris bahan telah bertambah!";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Gagal mencatat stok masuk: " . $e->getMessage();
        }
    }
}

// ambil daftar bahan
$stmtBahan = $pdo->query("
    SELECT id, nama_bahan, satuan_dasar, stok
    FROM bahan
    ORDER BY nama_bahan
");

$bahanList = $stmtBahan->fetchAll(PDO::FETCH_ASSOC);

// ambil riwayat pembelian
$stmtRiwayat = $pdo->query("
    SELECT pb.*, b.nama_bahan, b.satuan_dasar
    FROM pembelian_bahan pb
    JOIN bahan b ON pb.bahan_id = b.id
    ORDER BY pb.id DESC
    LIMIT 50
");

$riwayatList = $stmtRiwayat->fetchAll(PDO::FETCH_ASSOC);
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

<div class="manager-shell">

    <!-- SIDEBAR -->
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

    <!-- KONTEN UTAMA -->
    <main class="manager-content">

        <!-- TOPBAR -->
        <div class="manager-topbar">
            <div>
                <span class="manager-eyebrow">OVERVIEW</span>
                <h1>Stok Masuk</h1>
            </div>

            <div class="manager-profile">
                <div class="manager-avatar">
                    <?= strtoupper(substr($namaManajer, 0, 1)) ?>
                </div>

                <div>
                    <strong><?= htmlspecialchars($namaManajer) ?></strong>
                    <small>Manajer</small>
                </div>
            </div>
        </div>

        <div class="container">

            <!-- SAMBUTAN -->
            <div class="manager-welcome">
                <span class="manager-eyebrow">KEDAI KOPI SENJA</span>
                <h2>Catat Stok Masuk</h2>
                <p>
                    Tambahkan stok bahan baku dan pantau riwayat pembelian kedai.
                </p>
            </div>

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

            <!-- FORM STOK MASUK -->
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">

                <h3>Form Stok Masuk</h3>

                <form action="" method="POST"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 15px;">

                    <div class="form-group">
                        <label>Pilih Bahan Baku</label>

                        <select name="bahan_id" required style="width: 100%; padding: 10px;">
                            <option value="">-- Pilih Bahan --</option>

                            <?php foreach ($bahanList as $bahan): ?>
                                <option value="<?= $bahan['id'] ?>">
                                    <?= htmlspecialchars($bahan['nama_bahan']) ?>
                                    (Stok saat ini: <?= htmlspecialchars($bahan['stok'] . ' ' . $bahan['satuan_dasar']) ?>)
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group">
                        <label>Jumlah Masuk (<?= htmlspecialchars($bahan['satuan_dasar'] ?? 'satuan dasar') ?>)</label>

                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            name="jumlah_masuk"
                            required
                            placeholder="Contoh: 1000"
                            style="width: 100%; padding: 10px;"
                        >
                    </div>

                    <div class="form-group">
                        <label>Tanggal Pembelian</label>

                        <input
                            type="date"
                            name="tanggal_pembelian"
                            required
                            value="<?= date('Y-m-d') ?>"
                            style="width: 100%; padding: 10px;"
                        >
                    </div>

                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button
                            type="submit"
                            class="btn-primary"
                            style="width: 100%; padding: 11px;"
                        >
                            Simpan Stok Masuk
                        </button>
                    </div>

                </form>
            </div>

            <!-- RIWAYAT PEMBELIAN -->
            <h3>Riwayat Pembelian Bahan</h3>
            <p class="subtitle">Menampilkan 50 data terbaru.</p>

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

                        <?php if (count($riwayatList) > 0): ?>

                            <?php foreach ($riwayatList as $riwayat): ?>

                                <tr>
                                    <td>
                                        <?= htmlspecialchars($riwayat['tanggal_pembelian']) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars($riwayat['nama_bahan']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        +<?= htmlspecialchars($riwayat['jumlah_masuk'] . ' ' . $riwayat['satuan_dasar']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($riwayat['created_at']) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="4" class="text-center">
                                    Belum ada riwayat pembelian bahan.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- FOOTER -->
        <footer class="manager-footer">
            Kedai Kopi Senja · Dashboard Manajer
        </footer>

    </main>

</div>

</body>
</html>
