<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$namaManajer = $_SESSION['nama'] ?? 'Manajer';
$error = '';
$success = '';

$bahanId = intval($_GET['id'] ?? $_POST['bahan_id'] ?? 0);

if ($bahanId <= 0) {
    header('Location: ' . BASEURL . '/manajer/bahan.php');
    exit;
}

// Ambil data bahan
$stmt = $pdo->prepare("SELECT * FROM bahan WHERE id = :id");
$stmt->execute(['id' => $bahanId]);
$bahan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bahan) {
    header('Location: ' . BASEURL . '/manajer/bahan.php');
    exit;
}

// Proses update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaBahan = trim($_POST['nama_bahan'] ?? '');
    $hargaSatuan = $_POST['harga_satuan'] ?? '';
    $batasMinimum = $_POST['batas_minimum'] ?? '';

    if ($namaBahan === '') {
        $error = "Nama bahan wajib diisi.";
    } elseif (
        !is_numeric($hargaSatuan) ||
        !is_numeric($batasMinimum) ||
        $hargaSatuan < 0 ||
        $batasMinimum < 0
    ) {
        $error = "Harga dan batas minimum harus berupa angka dan tidak boleh negatif.";
    } else {
        try {
            // Cek nama bahan agar tidak duplikat
            $stmtCek = $pdo->prepare("
                SELECT COUNT(*)
                FROM bahan
                WHERE LOWER(TRIM(nama_bahan)) = LOWER(TRIM(:nama))
                AND id != :id
            ");

            $stmtCek->execute([
                'nama' => $namaBahan,
                'id' => $bahanId
            ]);

            if ($stmtCek->fetchColumn() > 0) {
                throw new Exception("Nama bahan sudah digunakan oleh bahan lain.");
            }

            // Update data bahan
            $stmtUpdate = $pdo->prepare("
                UPDATE bahan
                SET
                    nama_bahan = :nama,
                    harga_satuan = :harga,
                    batas_minimum = :batas,
                    last_updated_price = NOW()
                WHERE id = :id
            ");

            $stmtUpdate->execute([
                'nama' => $namaBahan,
                'harga' => $hargaSatuan,
                'batas' => $batasMinimum,
                'id' => $bahanId
            ]);

            $success = "Data bahan berhasil diperbarui.";

            // Ambil ulang data terbaru
            $stmt = $pdo->prepare("SELECT * FROM bahan WHERE id = :id");
            $stmt->execute(['id' => $bahanId]);
            $bahan = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $error = "Gagal memperbarui data bahan.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Update Bahan - Kedai Kopi Senja</title>

    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">

    <style>
        .update-card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            margin-top: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            max-width: 750px;
        }

        .update-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 20px;
        }

        .update-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .update-form input {
            width: 100%;
            box-sizing: border-box;
        }

        .update-form input[readonly] {
            background: #f1eee9;
            color: #777;
            cursor: not-allowed;
        }

        .update-actions {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 6px;
            background: #e8d1a7;
            color: #442d1c;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            .update-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="manager-shell">

    <!-- Sidebar -->
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

    <!-- Konten utama -->
    <main class="manager-content">

        <div class="manager-topbar">

            <div>
                <span class="manager-eyebrow">KELOLA DATA</span>
                <h1>Update Bahan</h1>
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
            <div class="manager-welcome">
                <span class="manager-eyebrow">KEDAI KOPI SENJA</span>
                <h2>Perbarui Data Bahan</h2>
                <p>
                    Ubah informasi bahan baku yang sudah terdaftar.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <div class="update-card">
                <h3>Informasi Bahan</h3>
                <form method="POST" action="" class="update-form">

                    <input
                        type="hidden"
                        name="bahan_id"
                        value="<?= $bahan['id'] ?>"
                    >

                    <div class="form-group">
                        <label>Nama Bahan</label>
                        <input
                            type="text"
                            name="nama_bahan"
                            value="<?= htmlspecialchars($bahan['nama_bahan']) ?>"
                            placeholder="Masukkan nama bahan"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Satuan Dasar</label>
                        <input
                            type="text"
                            value="<?= htmlspecialchars($bahan['satuan_dasar']) ?>"
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label>Harga Satuan (Rp)</label>
                        <input
                            type="number"
                            name="harga_satuan"
                            step="0.01"
                            min="0"
                            value="<?= htmlspecialchars($bahan['harga_satuan']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Stok Saat Ini</label>
                        <input
                            type="number"
                            step="0.01"
                            value="<?= htmlspecialchars($bahan['stok']) ?>"
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label>Batas Minimum Stok</label>
                        <input
                            type="number"
                            name="batas_minimum"
                            step="0.01"
                            min="0"
                            value="<?= htmlspecialchars($bahan['batas_minimum']) ?>"
                            required
                        >
                    </div>

                </form>

                <div class="update-actions">
                    <button
                        type="submit"
                        form="formUpdateBahan"
                        class="btn-primary"
                    >
                        Simpan Perubahan
                    </button>

                    <a
                        href="<?= BASEURL ?>/manajer/bahan.php"
                        class="btn-back"
                    >
                        Kembali
                    </a>
                </div>
            </div>
        </div>
        <footer class="manager-footer">
            Kedai Kopi Senja · Dashboard Manajer
        </footer>

    </main>

</div>

<script>
// menghubungkan tombol simpan dengan form update
document.querySelector('.update-actions button').addEventListener('click', function () {
    document.querySelector('.update-form').requestSubmit();
});
</script>
</body>
</html>