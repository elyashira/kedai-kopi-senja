<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$namaManajer = $_SESSION['nama'] ?? 'Manajer';

$error = '';
$success = '';

// Ambil kategori dari tabel master
$stmtKategori = $pdo->query("
    SELECT id, kode_kategori, nama_kategori
    FROM kategori_menu
    ORDER BY nama_kategori
");
$kategoriList = $stmtKategori->fetchAll();

$kategoriMap = [];
foreach ($kategoriList as $item) {
    $kategoriMap[strtolower(trim($item['nama_kategori']))] = $item;
}

// Proses penyimpanan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    // Tambah menu baru
    if ($aksi === 'tambah_menu') {
        $namaMenu = trim($_POST['nama_menu'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        $hargaJual = floatval($_POST['harga_jual'] ?? 0);

        if ($namaMenu !== '' && $hargaJual > 0) {
            try {
                // Cek nama menu agar tidak duplikat
                $stmtCek = $pdo->prepare("
                    SELECT COUNT(*) FROM menu
                    WHERE LOWER(TRIM(nama_menu)) = LOWER(TRIM(:nama))
                ");
                $stmtCek->execute(['nama' => $namaMenu]);

                if ($stmtCek->fetchColumn() > 0) {
                    throw new Exception("Nama menu sudah terdaftar.");
                }

                $kategoriData = $kategoriMap[strtolower($kategori)] ?? null;

                if (!$kategoriData) {
                    throw new Exception("Kategori menu tidak valid.");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO menu
                    (nama_menu, kategori, kategori_id, harga_jual, status)
                    VALUES
                    (:nama, :kategori, :kategori_id, :harga, 'Aktif')
                ");

                $stmt->execute([
                    'nama' => $namaMenu,
                    'kategori' => $kategoriData['nama_kategori'],
                    'kategori_id' => $kategoriData['id'],
                    'harga' => $hargaJual
                ]);

                $success = "Menu baru berhasil ditambahkan! Silakan atur resepnya.";

            } catch (Exception $e) {
                $error = "Gagal menambah menu: " . $e->getMessage();
            }
        } else {
            $error = "Nama menu dan harga jual wajib diisi dengan benar!";
        }

    // IMPORT MENU CSV
    } elseif ($aksi === 'import_csv') {

        if (
            !isset($_FILES['file_csv']) ||
            $_FILES['file_csv']['error'] !== UPLOAD_ERR_OK
        ) {
            $error = "File CSV gagal diunggah.";
        } else {
            $ekstensi = strtolower(
                pathinfo($_FILES['file_csv']['name'], PATHINFO_EXTENSION)
            );

            if ($ekstensi !== 'csv') {
                $error = "File harus menggunakan format CSV.";
            } else {
                $handle = fopen($_FILES['file_csv']['tmp_name'], 'r');

                if (!$handle) {
                    $error = "File CSV tidak dapat dibaca.";
                } else {
                    try {
                        $barisPertama = fgets($handle);

                        if ($barisPertama === false) {
                            throw new Exception("File CSV kosong.");
                        }

                        // Mendukung pemisah koma atau titik koma
                        $jumlahKoma = substr_count($barisPertama, ',');
                        $jumlahTitikKoma = substr_count($barisPertama, ';');

                        $delimiter = $jumlahTitikKoma > $jumlahKoma
                            ? ';'
                            : ',';

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

                        $cariKolom = function ($pilihan) use ($header) {
                            foreach ($pilihan as $nama) {
                                $posisi = array_search($nama, $header, true);

                                if ($posisi !== false) {
                                    return $posisi;
                                }
                            }

                            return false;
                        };

                        $kolomKode = $cariKolom([
                            'kode',
                            'kode_menu'
                        ]);

                        $kolomNama = $cariKolom([
                            'nama_menu',
                            'nama menu',
                            'nama'
                        ]);

                        $kolomKategori = $cariKolom([
                            'kategori',
                            'category'
                        ]);

                        $kolomHarga = $cariKolom([
                            'harga_jual',
                            'harga jual',
                            'harga'
                        ]);

                        if (
                            $kolomKode === false ||
                            $kolomNama === false ||
                            $kolomKategori === false ||
                            $kolomHarga === false
                        ) {
                            throw new Exception(
                                "Header CSV harus memiliki kolom kode, nama_menu, kategori, dan harga_jual."
                            );
                        }

                        // Query cek duplikat
                        $stmtCek = $pdo->prepare("
                            SELECT COUNT(*) FROM menu
                            WHERE kode_menu = :kode
                            OR LOWER(TRIM(nama_menu)) = LOWER(TRIM(:nama))
                        ");

                        // Query tambah menu
                        $stmtIns = $pdo->prepare("
                            INSERT INTO menu
                            (
                                kode_menu,
                                nama_menu,
                                kategori,
                                kategori_id,
                                harga_jual,
                                status
                            )
                            VALUES
                            (
                                :kode,
                                :nama,
                                :kategori,
                                :kategori_id,
                                :harga,
                                'Aktif'
                            )
                        ");

                        $berhasil = 0;
                        $dilewati = 0;

                        $pdo->beginTransaction();

                        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {

                            // Lewati baris kosong
                            if (
                                count($data) === 1 &&
                                trim($data[0]) === ''
                            ) {
                                continue;
                            }

                            $kode = trim($data[$kolomKode] ?? '');
                            $nama = trim($data[$kolomNama] ?? '');
                            $kategori = trim($data[$kolomKategori] ?? '');
                            $hargaRaw = trim($data[$kolomHarga] ?? '');

                            // Ubah harga menjadi angka
                            $harga = floatval(str_replace(',', '.', $hargaRaw));

                            // Cocokkan kategori dengan tabel master
                            $kategoriData = $kategoriMap[strtolower($kategori)] ?? null;

                            // Validasi data
                            if (
                                $kode === '' ||
                                $nama === '' ||
                                !$kategoriData ||
                                $harga <= 0
                            ) {
                                $dilewati++;
                                continue;
                            }

                            // Cek kode atau nama yang sudah terdaftar
                            $stmtCek->execute([
                                'kode' => $kode,
                                'nama' => $nama
                            ]);

                            if ($stmtCek->fetchColumn() > 0) {
                                $dilewati++;
                                continue;
                            }

                            // Simpan menu
                            $stmtIns->execute([
                                'kode' => $kode,
                                'nama' => $nama,
                                'kategori' => $kategoriData['nama_kategori'],
                                'kategori_id' => $kategoriData['id'],
                                'harga' => $harga
                            ]);

                            $berhasil++;
                        }

                        $pdo->commit();

                        $success = "Import selesai! $berhasil menu berhasil ditambahkan dan $dilewati baris dilewati. Silakan atur resep setiap menu.";

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

    // Ubah status menu
    } elseif ($aksi === 'toggle_status') {
        $menuId = intval($_POST['menu_id'] ?? 0);

        if ($menuId > 0) {
            $stmtCek = $pdo->prepare("
                SELECT status FROM menu WHERE id = :id
            ");
            $stmtCek->execute(['id' => $menuId]);
            $menuData = $stmtCek->fetch();

            if ($menuData) {
                $statusBaru = ($menuData['status'] === 'Aktif')
                    ? 'Nonaktif'
                    : 'Aktif';

                $stmt = $pdo->prepare("
                    UPDATE menu SET status = :status WHERE id = :id
                ");

                $stmt->execute([
                    'status' => $statusBaru,
                    'id' => $menuId
                ]);

                $success = "Status menu berhasil diubah menjadi $statusBaru.";
            } else {
                $error = "Menu tidak ditemukan.";
            }
        }
    }
}

// Ambil daftar menu
$stmtMenu = $pdo->query("
    SELECT * FROM menu
    ORDER BY kategori, nama_menu
");
$menuList = $stmtMenu->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu & Resep - Manajer Kedai Kopi Senja</title>
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

    <!-- konten utama -->
    <main class="manager-content">

        <!-- topbar -->
        <div class="manager-topbar">
            <div>
                <span class="manager-eyebrow">OVERVIEW</span>
                <h1>Menu & Resep</h1>
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
                <h2>Kelola Menu dan Resep</h2>
                <p>
                    Atur daftar menu, harga jual, resep, HPP, dan margin keuntungan.
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

            <!-- form tambah menu -->
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h3>Tambah Menu Baru</h3>
                <form action="" method="POST"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 15px;">
                    <input type="hidden" name="aksi" value="tambah_menu">
                    <div class="form-group">
                        <label>Nama Menu</label>
                        <input
                            type="text"
                            name="nama_menu"
                            required
                            placeholder="Contoh: Caffe Latte"
                        >
                    </div>

                    <div class="form-group">
                        <label>Kategori</label>
                        <select name="kategori" required>
                            <?php foreach ($kategoriList as $kategori): ?>
                                <option value="<?= htmlspecialchars($kategori['nama_kategori']) ?>">
                                    <?= htmlspecialchars($kategori['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Harga Jual (Rp)</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            name="harga_jual"
                            required
                            placeholder="Contoh: 25000"
                        >
                    </div>

                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button
                            type="submit"
                            class="btn-primary"
                            style="width: 100%; padding: 11px;"
                        >
                            Simpan Menu
                        </button>
                    </div>

                </form>
            </div>

            <!-- form import menu CSV -->
            <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h3>Import Menu dari CSV</h3>
                <p>Masukkan daftar menu melalui file CSV. Menu yang berhasil diimport akan berstatus Aktif.</p>
                <form action="" method="POST" enctype="multipart/form-data"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 15px;">
                    <input type="hidden" name="aksi" value="import_csv">
                    <div class="form-group">
                        <label>Pilih File CSV</label>
                        <input
                            type="file"
                            name="file_csv"
                            accept=".csv"
                            required
                        >
                    </div>

                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button
                            type="submit"
                            class="btn-primary"
                            style="width: 100%; padding: 11px;"
                        >
                            Import Menu
                        </button>
                    </div>

                </form>

                <small>
                    Format header: kode, nama_menu, kategori, harga_jual.
                    Kategori yang diterima: Minuman dan Makanan.
                </small>
            </div>

            <!-- tabel menu -->
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
                        <?php if (count($menuList) > 0): ?>
                            <?php foreach ($menuList as $menu): ?>
                                <?php
                                    // Hitung HPP dan margin
                                    $stmtR = $pdo->prepare("
                                        SELECT SUM(r.takaran * b.harga_satuan) AS hpp
                                        FROM resep r
                                        JOIN bahan b ON r.bahan_id = b.id
                                        WHERE r.menu_id = :m_id
                                    ");

                                    $stmtR->execute(['m_id' => $menu['id']]);

                                    $hppData = $stmtR->fetch();
                                    $hpp = floatval($hppData['hpp'] ?? 0);
                                    $margin = $menu['harga_jual'] - $hpp;

                                    // Cek apakah menu sudah memiliki resep
                                    $stmtCekResep = $pdo->prepare("
                                        SELECT COUNT(*) FROM resep WHERE menu_id = :m_id
                                    ");

                                    $stmtCekResep->execute(['m_id' => $menu['id']]);
                                    $punyaResep = $stmtCekResep->fetchColumn() > 0;
                                ?>

                                <tr>
                                    <td>
                                        <strong>
                                            <?= htmlspecialchars($menu['nama_menu']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($menu['kategori']) ?>
                                    </td>

                                    <td>
                                        Rp <?= number_format($menu['harga_jual'], 0, ',', '.') ?>
                                    </td>

                                    <td>
                                        Rp <?= number_format($hpp, 0, ',', '.') ?>
                                    </td>

                                    <td>
                                        <strong style="color: <?= $margin >= 0 ? 'green' : 'red' ?>;">
                                            Rp <?= number_format($margin, 0, ',', '.') ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <span class="badge badge-<?= strtolower($menu['status']) ?>">
                                            <?= htmlspecialchars($menu['status']) ?>
                                        </span>

                                        <?php if (!$punyaResep): ?>
                                            <br>
                                            <small style="color: red;">(Belum ada resep!)</small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div style="display: flex; gap: 5px; align-items: center;">
                                            <a
                                                href="<?= BASEURL ?>/manajer/resep.php?menu_id=<?= $menu['id'] ?>"
                                                class="btn-sm btn-info"
                                            >
                                                Atur Resep
                                            </a>
                                            <form action="" method="POST" style="display: inline;">
                                                <input type="hidden" name="aksi" value="toggle_status">
                                                <input
                                                    type="hidden"
                                                    name="menu_id"
                                                    value="<?= $menu['id'] ?>"
                                                >
                                                <button type="submit" class="btn-sm <?= $menu['status'] === 'Aktif' ? 'btn-danger' : 'btn-success' ?>">
                                                    <?= $menu['status'] === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    Belum ada menu yang terdaftar.
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