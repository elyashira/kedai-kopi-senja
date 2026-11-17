
<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$namaManajer = $_SESSION['nama'] ?? 'Manajer';

$error = '';
$success = '';

// Konversi satuan ke satuan dasar
function konversiSatuanBahan($satuan, $stok, $harga)
{
    $satuan = strtolower(trim($satuan));

    switch ($satuan) {
        case 'kg':
            return [
                'satuan' => 'g',
                'stok' => $stok * 1000,
                'harga' => $harga / 1000
            ];

        case 'g':
        case 'gram':
            return [
                'satuan' => 'g',
                'stok' => $stok,
                'harga' => $harga
            ];

        case 'liter':
        case 'l':
            return [
                'satuan' => 'ml',
                'stok' => $stok * 1000,
                'harga' => $harga / 1000
            ];

        case 'ml':
            return [
                'satuan' => 'ml',
                'stok' => $stok,
                'harga' => $harga
            ];

        case 'pcs':
        case 'pc':
        case 'buah':
            return [
                'satuan' => 'pcs',
                'stok' => $stok,
                'harga' => $harga
            ];

        default:
            throw new Exception("Satuan '$satuan' tidak dikenali.");
    }
}

// Membaca angka dari CSV
function angkaCSV($nilai)
{
    $nilai = trim((string) $nilai);
    $nilai = str_replace(',', '.', $nilai);

    if ($nilai === '' || !is_numeric($nilai)) {
        throw new Exception("Nilai angka tidak valid.");
    }

    $angka = (float) $nilai;

    if (!is_finite($angka)) {
        throw new Exception("Nilai angka tidak valid.");
    }

    return $angka;
}

// Proses penyimpanan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah') {
        $namaBahan = trim($_POST['nama_bahan'] ?? '');
        $satuanDasar = $_POST['satuan_dasar'] ?? 'g';
        $hargaSatuan = (float) ($_POST['harga_satuan'] ?? 0);
        $stokAwal = (float) ($_POST['stok_awal'] ?? 0);
        $batasMinimum = (float) ($_POST['batas_minimum'] ?? 0);

        if ($namaBahan !== '') {
            try {
                if (!in_array($satuanDasar, ['g', 'ml', 'pcs'], true)) {
                    throw new Exception("Satuan dasar tidak valid.");
                }

                if (
                    !is_finite($hargaSatuan) ||
                    !is_finite($stokAwal) ||
                    !is_finite($batasMinimum) ||
                    $hargaSatuan < 0 ||
                    $stokAwal < 0 ||
                    $batasMinimum < 0
                ) {
                    throw new Exception("Harga, stok, dan batas minimum tidak boleh negatif atau tidak valid.");
                }

                $stmtCek = $pdo->prepare("
                    SELECT COUNT(*) FROM bahan
                    WHERE LOWER(TRIM(nama_bahan)) = LOWER(TRIM(:nama))
                ");
                $stmtCek->execute(['nama' => $namaBahan]);

                if ($stmtCek->fetchColumn() > 0) {
                    throw new Exception("Nama bahan sudah terdaftar (tidak boleh ganda).");
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO bahan
                    (nama_bahan, satuan_dasar, harga_satuan, stok, batas_minimum, last_updated_price)
                    VALUES (:nama, :satuan, :harga, :stok, :batas, NOW())
                ");

                $stmtIns->execute([
                    'nama' => $namaBahan,
                    'satuan' => $satuanDasar,
                    'harga' => $hargaSatuan,
                    'stok' => $stokAwal,
                    'batas' => $batasMinimum
                ]);

                $success = "Bahan baru berhasil ditambahkan!";

            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = "Nama bahan wajib diisi!";
        }

    } elseif ($aksi === 'import_csv') {

        if (
            !isset($_FILES['file_csv']) ||
            $_FILES['file_csv']['error'] !== UPLOAD_ERR_OK
        ) {
            $error = "File CSV gagal diunggah.";
        } else {
            $namaFile = $_FILES['file_csv']['name'];
            $ekstensi = strtolower(pathinfo($namaFile, PATHINFO_EXTENSION));

            if ($ekstensi !== 'csv') {
                $error = "File harus menggunakan format CSV.";
            } else {
                $handle = fopen($_FILES['file_csv']['tmp_name'], 'r');

                if (!$handle) {
                    $error = "File CSV tidak dapat dibaca.";
                } else {
                    try {
                        // Membaca baris pertama untuk menentukan pemisah
                        $barisPertama = fgets($handle);

                        if ($barisPertama === false) {
                            throw new Exception("File CSV kosong.");
                        }

                        $jumlahKoma = substr_count($barisPertama, ',');
                        $jumlahTitikKoma = substr_count($barisPertama, ';');

                        $delimiter = $jumlahTitikKoma > $jumlahKoma ? ';' : ',';

                        rewind($handle);

                        $header = fgetcsv($handle, 0, $delimiter);

                        if (!$header) {
                            throw new Exception("Header CSV tidak ditemukan.");
                        }

                        // Membersihkan header dan BOM
                        $header = array_map(function ($kolom) {
                            $kolom = preg_replace('/^\xEF\xBB\xBF/', '', $kolom);
                            return strtolower(trim($kolom));
                        }, $header);

                        // Mencari posisi kolom berdasarkan nama header
                        $cariKolom = function ($pilihan) use ($header) {
                            foreach ($pilihan as $nama) {
                                $posisi = array_search($nama, $header, true);

                                if ($posisi !== false) {
                                    return $posisi;
                                }
                            }

                            return false;
                        };

                        // Format kolom mengikuti bahan.csv dari Data_Awal
                        $kolomKode = $cariKolom([
                            'kode',
                            'kode_bahan'
                        ]);

                        $kolomNama = $cariKolom([
                            'nama_bahan',
                            'nama bahan',
                            'nama'
                        ]);

                        $kolomSatuan = $cariKolom([
                            'satuan',
                            'satuan_dasar',
                            'unit'
                        ]);

                        $kolomHarga = $cariKolom([
                            'harga_per_satuan',
                            'harga_satuan',
                            'harga satuan',
                            'harga'
                        ]);

                        $kolomStok = $cariKolom([
                            'stok_awal',
                            'stok',
                            'jumlah_stok',
                            'jumlah'
                        ]);

                        if (
                            $kolomKode === false ||
                            $kolomNama === false ||
                            $kolomSatuan === false ||
                            $kolomHarga === false ||
                            $kolomStok === false
                        ) {
                            throw new Exception(
                                "Header CSV harus memiliki kolom kode, nama_bahan, stok_awal, satuan, dan harga_per_satuan."
                            );
                        }

                        $pdo->beginTransaction();

                        // Cek duplikasi berdasarkan kode atau nama bahan
                        $stmtCek = $pdo->prepare("
                            SELECT COUNT(*) FROM bahan
                            WHERE kode_bahan = :kode
                            OR LOWER(TRIM(nama_bahan)) = LOWER(TRIM(:nama))
                        ");

                        $stmtIns = $pdo->prepare("
                            INSERT INTO bahan
                            (kode_bahan, nama_bahan, satuan_dasar, harga_satuan, stok, batas_minimum, last_updated_price)
                            VALUES (:kode, :nama, :satuan, :harga, :stok, 0, NOW())
                        ");

                        $berhasil = 0;
                        $dilewati = 0;
                        $nomorBaris = 1;

                        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $nomorBaris++;

                            // Lewati baris kosong
                            if (
                                count($data) === 1 &&
                                trim($data[0]) === ''
                            ) {
                                continue;
                            }

                            try {
                                $kode = trim($data[$kolomKode] ?? '');
                                $nama = trim($data[$kolomNama] ?? '');
                                $satuan = trim($data[$kolomSatuan] ?? '');

                                if ($kode === '' || $nama === '' || $satuan === '') {
                                    throw new Exception("Data kode, nama, atau satuan kosong.");
                                }

                                $harga = angkaCSV($data[$kolomHarga] ?? '');
                                $stok = angkaCSV($data[$kolomStok] ?? '');

                                if ($harga < 0 || $stok < 0) {
                                    throw new Exception("Harga atau stok tidak boleh negatif.");
                                }

                                // Cek bahan yang sudah terdaftar
                                $stmtCek->execute([
                                    'kode' => $kode,
                                    'nama' => $nama
                                ]);

                                if ($stmtCek->fetchColumn() > 0) {
                                    throw new Exception("Kode atau nama bahan sudah terdaftar.");
                                }

                                // Konversi satuan ke g, ml, atau pcs
                                $hasilKonversi = konversiSatuanBahan(
                                    $satuan,
                                    $stok,
                                    $harga
                                );

                                $stmtIns->execute([
                                    'kode' => $kode,
                                    'nama' => $nama,
                                    'satuan' => $hasilKonversi['satuan'],
                                    'harga' => $hasilKonversi['harga'],
                                    'stok' => $hasilKonversi['stok']
                                ]);

                                $berhasil++;

                            } catch (Exception $e) {
                                // Baris tidak valid dilewati, proses baris berikutnya tetap berjalan
                                $dilewati++;
                            }
                        }

                        $pdo->commit();

                        $success = "Import selesai! $berhasil bahan berhasil ditambahkan dan $dilewati baris dilewati. Batas minimum bahan hasil import diatur menjadi 0.";

                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = "Import gagal: " . $e->getMessage();
                    } finally {
                        fclose($handle);
                    }
                }
            }
        }
    } elseif ($aksi === 'update_batas') {
        $bahanId = intval($_POST['bahan_id'] ?? 0);
        $batasMinimum = (float) ($_POST['batas_minimum'] ?? 0);

        if ($bahanId > 0 && $batasMinimum >= 0 && is_finite($batasMinimum)) {
            $stmtUpd = $pdo->prepare("
                UPDATE bahan
                SET batas_minimum = :batas
                WHERE id = :id
            ");

            $stmtUpd->execute([
                'batas' => $batasMinimum,
                'id' => $bahanId
            ]);

            $success = "Batas minimum bahan berhasil diperbarui.";
        } else {
            $error = "Batas minimum tidak boleh negatif.";
        }

    } elseif ($aksi === 'hapus') {
        $bahanId = intval($_POST['bahan_id'] ?? 0);

        if ($bahanId <= 0) {
            $error = "Data bahan tidak valid.";
        } else {
            try {
                // Bahan yang masih dipakai resep tidak boleh dihapus
                $stmtCekResep = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM resep
                    WHERE bahan_id = :id
                ");
                $stmtCekResep->execute(['id' => $bahanId]);

                if ($stmtCekResep->fetchColumn() > 0) {
                    throw new Exception("Bahan tidak bisa dihapus karena masih digunakan dalam resep.");
                }

                $stmtHapus = $pdo->prepare("
                    DELETE FROM bahan
                    WHERE id = :id
                ");
                $stmtHapus->execute(['id' => $bahanId]);

                if ($stmtHapus->rowCount() > 0) {
                    $success = "Bahan berhasil dihapus.";
                } else {
                    $error = "Data bahan tidak ditemukan.";
                }

            } catch (PDOException $e) {
                $error = "Bahan tidak bisa dihapus karena masih digunakan oleh data lain.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// ambil daftar bahan
$stmtBahan = $pdo->query("SELECT * FROM bahan ORDER BY nama_bahan");
$bahanList = $stmtBahan->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bahan & Stok - Manajer Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">
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

    <!-- konten utama -->
    <main class="manager-content">

        <!-- topbar -->
        <div class="manager-topbar">
            <div>
                <span class="manager-eyebrow">OVERVIEW</span>
                <h1>Bahan & Stok</h1>
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
            <!-- welcome -->
            <div class="manager-welcome">
                <span class="manager-eyebrow">KEDAI KOPI SENJA</span>
                <h2>Kelola Bahan Baku</h2>
                <p>
                    Atur persediaan, harga, dan batas minimum stok bahan baku kedai.
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

            <!-- form tambah bahan -->
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h3>Tambah Bahan Baru</h3>
                <form action="" method="POST"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">

                    <input type="hidden" name="aksi" value="tambah">

                    <div class="form-group">
                        <label>Nama Bahan</label>
                        <input
                            type="text"
                            name="nama_bahan"
                            required
                            placeholder="Contoh: Susu UHT"
                        >
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
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="harga_satuan"
                            required
                            value="0"
                        >
                    </div>

                    <div class="form-group">
                        <label>Stok Awal</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="stok_awal"
                            required
                            value="0"
                        >
                    </div>

                    <div class="form-group">
                        <label>Batas Minimum</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="batas_minimum"
                            required
                            value="0"
                        >
                    </div>

                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button
                            type="submit"
                            class="btn-primary"
                            style="width: 100%; padding: 10px;"
                        >
                            Simpan Bahan
                        </button>
                    </div>
                </form>
            </div>

            <!-- form import CSV -->
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h3>Import Bahan dari CSV</h3>
                <p>Import data bahan dari file CSV. Satuan kg dan liter akan otomatis dikonversi menjadi gram dan mililiter.</p>
                <form action="" method="POST" enctype="multipart/form-data"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                    <input type="hidden" name="aksi" value="import_csv">
                    <div class="form-group">
                        <label>Pilih File CSV</label>
                        <input
                            type="file"
                            name="file_csv"
                            accept=".csv"
                            required>
                    </div>

                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button
                            type="submit"
                            class="btn-primary"
                            style="width: 100%; padding: 10px;"
                        >
                            Import CSV
                        </button>
                    </div>
                </form>
                <small>
                    Format header: kode, nama_bahan, stok_awal, satuan, harga_per_satuan.
                    Contoh satuan: kg, g, liter, ml, pcs.
                </small>
            </div>

            <!-- tabel bahan -->
            <h3>Daftar Bahan & Status Stok</h3>

            <div class="table-responsive">
                <table class="data-table">

                    <thead>
                        <tr>
                            <th>Nama Bahan</th>
                            <th>Satuan</th>
                            <th>Harga Satuan</th>
                            <th>Stok Saat Ini</th>
                            <th>Batas Minimum</th>
                            <th>Status Stok</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($bahanList) > 0): ?>
                            <?php foreach ($bahanList as $bahan): ?>
                                <?php
                                    $isMenipis = $bahan['stok'] <= $bahan['batas_minimum'];
                                ?>

                                <tr class="<?= $isMenipis ? 'table-row-warning' : '' ?>">

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars($bahan['nama_bahan']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($bahan['satuan_dasar']) ?>
                                    </td>

                                    <td>
                                        Rp <?= number_format($bahan['harga_satuan'], 2, ',', '.') ?>
                                    </td>

                                    <td>
                                        <strong><?= htmlspecialchars($bahan['stok']) ?></strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($bahan['batas_minimum']) ?>
                                    </td>

                                    <td>
                                        <?php if ($isMenipis): ?>
                                            <span class="badge badge-danger">
                                                Perlu Restock
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-success">
                                                Aman
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div style="display: flex; gap: 7px; align-items: center;">
                                            <a
                                                href="<?= BASEURL ?>/manajer/update.php?id=<?= (int) $bahan['id'] ?>"
                                                class="btn-sm btn-info"
                                                style="text-decoration: none;"
                                            >
                                                Update
                                            </a>

                                            <form
                                                action=""
                                                method="POST"
                                                style="display: inline;"
                                                onsubmit="return confirm('Yakin ingin menghapus bahan ini?');"
                                            >
                                                <input type="hidden" name="aksi" value="hapus">

                                                <input
                                                    type="hidden"
                                                    name="bahan_id"
                                                    value="<?= (int) $bahan['id'] ?>">

                                                <button type="submit" class="btn-sm btn-danger">
                                                    Hapus
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    Belum ada data bahan baku.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- footer -->
        <footer class="manager-footer">
            Kedai Kopi Senja · Dashboard Manajer
        </footer>
    </main>
</div>
</body>
</html>