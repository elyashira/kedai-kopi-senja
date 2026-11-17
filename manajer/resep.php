<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('manajer');

$namaManajer = $_SESSION['nama'] ?? 'Manajer';

$error = '';
$success = '';
$hasil_import = [];

$menu_id = (int)($_GET['menu_id'] ?? 0);
$menu = null;

/* FUNGSI BANTU*/

function teksNormal($teks)
{
    return strtolower(preg_replace('/\s+/', '', trim((string)$teks)));
}

function satuanNormal($satuan, $takaran)
{
    $satuan = strtolower(trim($satuan));
    $satuan = str_replace([' ', '.'], '', $satuan);

    $daftar = [
        'g' => ['g', 1],
        'gr' => ['g', 1],
        'gram' => ['g', 1],
        'kg' => ['g', 1000],
        'kilogram' => ['g', 1000],

        'ml' => ['ml', 1],
        'mililiter' => ['ml', 1],
        'l' => ['ml', 1000],
        'liter' => ['ml', 1000],

        'pc' => ['pcs', 1],
        'pcs' => ['pcs', 1],
        'buah' => ['pcs', 1],
        'unit' => ['pcs', 1]
    ];

    if (!isset($daftar[$satuan])) {
        return null;
    }

    return [
        'satuan' => $daftar[$satuan][0],
        'takaran' => (float)$takaran * $daftar[$satuan][1]
    ];
}

function kolomCSV($header, $nama)
{
    foreach ($header as $i => $kolom) {
        if (teksNormal($kolom) === teksNormal($nama)) {
            return $i;
        }
    }

    return false;
}

/*AMBIL DATA MENU*/

$menu_list = $pdo->query("
    SELECT id, kode_menu, nama_menu, kategori, harga_jual
    FROM menu
    ORDER BY nama_menu
")->fetchAll(PDO::FETCH_ASSOC);

if ($menu_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM menu WHERE id = ?");
    $stmt->execute([$menu_id]);
    $menu = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$menu) {
        $menu_id = 0;
        $error = 'Menu tidak ditemukan.';
    }
}

/*IMPOR RESEP CSV*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['aksi'] ?? '') === 'impor_csv'
) {
    if (
        !isset($_FILES['file_csv']) ||
        $_FILES['file_csv']['error'] !== UPLOAD_ERR_OK
    ) {
        $error = 'Pilih file CSV terlebih dahulu.';
    } elseif (
        strtolower(pathinfo($_FILES['file_csv']['name'], PATHINFO_EXTENSION)) !== 'csv'
    ) {
        $error = 'File harus berformat CSV.';
    } else {
        $handle = fopen($_FILES['file_csv']['tmp_name'], 'r');

        if (!$handle) {
            $error = 'File CSV tidak dapat dibaca.';
        } else {
            // Deteksi pemisah CSV
            $barisPertama = fgets($handle);

            if ($barisPertama === false) {
                $error = 'File CSV kosong.';
                fclose($handle);
            } else {
                $delimiter = substr_count($barisPertama, ';') >
                    substr_count($barisPertama, ',') ? ';' : ',';

                rewind($handle);

                $header = fgetcsv($handle, 0, $delimiter);

                if (!$header) {
                    $error = 'Header CSV tidak ditemukan.';
                    fclose($handle);
                } else {
                    $header = array_map(function ($kolom) {
                        $kolom = preg_replace('/^\xEF\xBB\xBF/', '', $kolom);
                        return trim($kolom);
                    }, $header);

                    $cKode = kolomCSV($header, 'kode_menu');
                    $cBahan = kolomCSV($header, 'nama_bahan');
                    $cTakaran = kolomCSV($header, 'takaran');
                    $cSatuan = kolomCSV($header, 'satuan');

                    if (
                        $cKode === false ||
                        $cBahan === false ||
                        $cTakaran === false ||
                        $cSatuan === false
                    ) {
                        $error = 'Kolom CSV harus berisi: kode_menu, nama_bahan, takaran, satuan.';
                        fclose($handle);
                    } else {
                        $mapMenu = [];
                        $mapBahan = [];

                        foreach (
                            $pdo->query("SELECT id, kode_menu FROM menu")
                                ->fetchAll(PDO::FETCH_ASSOC) as $m
                        ) {
                            $mapMenu[teksNormal($m['kode_menu'])] = $m['id'];
                        }

                        foreach (
                            $pdo->query("
                                SELECT id, nama_bahan, satuan_dasar
                                FROM bahan
                            ")->fetchAll(PDO::FETCH_ASSOC) as $b
                        ) {
                            $mapBahan[teksNormal($b['nama_bahan'])] = [
                                'id' => $b['id'],
                                'satuan' => $b['satuan_dasar']
                            ];
                        }

                        // Pencocokan nama bahan yang salah ketik
                        $alias = [
                            'bijikopiarabica' => 'bijikopiarabika'
                        ];

                        $baru = 0;
                        $update = 0;
                        $lewati = 0;
                        $catatan = [];

                        try {
                            $pdo->beginTransaction();

                            $cek = $pdo->prepare("
                                SELECT id
                                FROM resep
                                WHERE menu_id = ? AND bahan_id = ?
                                LIMIT 1
                            ");

                            $insert = $pdo->prepare("
                                INSERT INTO resep (menu_id, bahan_id, takaran)
                                VALUES (?, ?, ?)
                            ");

                            $ubah = $pdo->prepare("
                                UPDATE resep
                                SET takaran = ?
                                WHERE id = ?
                            ");

                            $nomor = 1;

                            while (
                                ($data = fgetcsv($handle, 0, $delimiter)) !== false
                            ) {
                                $nomor++;

                                if (
                                    count($data) === 1 &&
                                    trim($data[0]) === ''
                                ) {
                                    continue;
                                }

                                $kode = trim($data[$cKode] ?? '');
                                $nama = trim($data[$cBahan] ?? '');
                                $takaran = str_replace(
                                    ',',
                                    '.',
                                    trim($data[$cTakaran] ?? '')
                                );
                                $satuan = trim($data[$cSatuan] ?? '');

                                if (
                                    $kode === '' ||
                                    $nama === '' ||
                                    !is_numeric($takaran) ||
                                    (float)$takaran <= 0
                                ) {
                                    $lewati++;
                                    $catatan[] = "Baris $nomor: data tidak lengkap atau takaran tidak valid.";
                                    continue;
                                }

                                $kodeNormal = teksNormal($kode);
                                $namaNormal = teksNormal($nama);

                                if (!isset($mapMenu[$kodeNormal])) {
                                    $lewati++;
                                    $catatan[] = "Baris $nomor: kode menu $kode tidak ditemukan.";
                                    continue;
                                }

                                if (isset($alias[$namaNormal])) {
                                    $namaNormal = $alias[$namaNormal];
                                }

                                if (!isset($mapBahan[$namaNormal])) {
                                    $lewati++;
                                    $catatan[] = "Baris $nomor: bahan $nama tidak ditemukan.";
                                    continue;
                                }

                                $bahan = $mapBahan[$namaNormal];
                                $hasilSatuan = satuanNormal($satuan, $takaran);

                                if (!$hasilSatuan) {
                                    $lewati++;
                                    $catatan[] = "Baris $nomor: satuan $satuan tidak dikenali.";
                                    continue;
                                }

                                if ($hasilSatuan['satuan'] !== $bahan['satuan']) {
                                    $lewati++;
                                    $catatan[] = "Baris $nomor: satuan $nama tidak cocok dengan data bahan.";
                                    continue;
                                }

                                $idMenu = (int)$mapMenu[$kodeNormal];
                                $idBahan = (int)$bahan['id'];
                                $takaranFinal = $hasilSatuan['takaran'];

                                $cek->execute([$idMenu, $idBahan]);
                                $resepLama = $cek->fetch(PDO::FETCH_ASSOC);

                                if ($resepLama) {
                                    $ubah->execute([
                                        $takaranFinal,
                                        $resepLama['id']
                                    ]);
                                    $update++;
                                } else {
                                    $insert->execute([
                                        $idMenu,
                                        $idBahan,
                                        $takaranFinal
                                    ]);
                                    $baru++;
                                }
                            }

                            $pdo->commit();

                            $hasil_import = [
                                'baru' => $baru,
                                'update' => $update,
                                'lewati' => $lewati,
                                'catatan' => $catatan
                            ];

                            $success = 'Impor resep selesai diproses.';

                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $error = 'Impor gagal: ' . $e->getMessage();
                        }

                        fclose($handle);
                    }
                }
            }
        }
    }
}

/*TAMBAH, EDIT, HAPUS RESEP*/

if (
    $menu &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['aksi'] ?? '') !== 'impor_csv'
) {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah_resep') {
        $bahan_id = (int)($_POST['bahan_id'] ?? 0);
        $takaran = (float)($_POST['takaran'] ?? 0);

        if ($bahan_id <= 0 || $takaran <= 0) {
            $error = 'Pilih bahan dan masukkan takaran yang valid.';
        } else {
            $cek = $pdo->prepare("
                SELECT id
                FROM resep
                WHERE menu_id = ? AND bahan_id = ?
            ");
            $cek->execute([$menu_id, $bahan_id]);

            if ($cek->fetch()) {
                $error = 'Bahan sudah ada dalam resep. Silakan edit takarannya.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO resep (menu_id, bahan_id, takaran)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$menu_id, $bahan_id, $takaran]);

                $success = 'Bahan berhasil ditambahkan.';
            }
        }
    } elseif ($aksi === 'edit_resep') {
        $resep_id = (int)($_POST['resep_id'] ?? 0);
        $takaran = (float)($_POST['takaran'] ?? 0);

        if ($resep_id <= 0 || $takaran <= 0) {
            $error = 'Takaran tidak valid.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE resep
                SET takaran = ?
                WHERE id = ? AND menu_id = ?
            ");
            $stmt->execute([$takaran, $resep_id, $menu_id]);

            $success = $stmt->rowCount() > 0
                ? 'Takaran resep berhasil diperbarui.'
                : 'Tidak ada perubahan pada takaran resep.';
        }
    } elseif ($aksi === 'hapus_resep') {
        $resep_id = (int)($_POST['resep_id'] ?? 0);

        $stmt = $pdo->prepare("
            DELETE FROM resep
            WHERE id = ? AND menu_id = ?
        ");
        $stmt->execute([$resep_id, $menu_id]);

        $success = $stmt->rowCount() > 0
            ? 'Bahan berhasil dihapus dari resep.'
            : 'Data resep tidak ditemukan.';
    }
}

/*DATA RESEP DAN BAHAN*/

$semua_resep = [];
$resep_list = [];
$bahan_list = [];

if ($menu) {
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.takaran,
            b.id AS bahan_id,
            b.nama_bahan,
            b.satuan_dasar,
            b.harga_satuan
        FROM resep r
        JOIN bahan b ON r.bahan_id = b.id
        WHERE r.menu_id = ?
        ORDER BY b.nama_bahan
    ");
    $stmt->execute([$menu_id]);
    $resep_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bahan_list = $pdo->query("
        SELECT id, nama_bahan, satuan_dasar
        FROM bahan
        ORDER BY nama_bahan
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $dataResep = $pdo->query("
        SELECT
            r.menu_id,
            r.takaran,
            b.nama_bahan,
            b.satuan_dasar,
            b.harga_satuan
        FROM resep r
        JOIN bahan b ON r.bahan_id = b.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dataResep as $r) {
        $semua_resep[$r['menu_id']][] = $r;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resep - Manajer Kedai Kopi Senja</title>
    <link rel="stylesheet" href="<?= BASEURL ?>/assets/style.css">

    <style>
        .recipe-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 18px;
            margin-top: 20px;
        }

        .recipe-card {
            background: #fff;
            border: 1px solid #e9e1d8;
            border-radius: 14px;
            padding: 20px;
        }

        .recipe-card h3 {
            color: #442d1c;
            margin: 0 0 8px;
        }

        .recipe-card p {
            color: #786b5f;
            font-size: 13px;
        }

        .recipe-import-box {
            background: #faf7f3;
            border: 1px dashed #c9b49d;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .recipe-import-box p {
            font-size: 13px;
            color: #786b5f;
        }

        .recipe-import-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .recipe-result {
            background: #f5f1eb;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .recipe-result li {
            margin: 5px 0;
        }

        .recipe-hpp {
            color: #743014;
            font-weight: bold;
        }

        @media(max-width: 600px) {
            .recipe-import-form {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>

<body>
<div class="manager-shell">
    <!-- sidebar -->
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

            <a href="<?= BASEURL ?>/manajer/resep.php" class="active">
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
                <h1>Resep</h1>
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
            <?php if ($error): ?>
                <div class="alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($hasil_import): ?>
                <div class="recipe-result">
                    <strong>Hasil Impor CSV</strong>
                    <ul>
                        <li>Resep baru: <?= $hasil_import['baru'] ?></li>
                        <li>Resep diperbarui: <?= $hasil_import['update'] ?></li>
                        <li>Baris dilewati: <?= $hasil_import['lewati'] ?></li>
                    </ul>

                    <?php if ($hasil_import['catatan']): ?>
                        <strong>Catatan impor:</strong>

                        <ul>
                            <?php foreach ($hasil_import['catatan'] as $catatan): ?>
                                <li><?= htmlspecialchars($catatan) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- welcome -->
            <div class="manager-welcome">
                <span class="manager-eyebrow">KEDAI KOPI SENJA</span>
                <h2>
                    <?= $menu
                        ? htmlspecialchars($menu['nama_menu'])
                        : 'Kelola Resep Menu'
                    ?>
                </h2>
                <p>Atur komposisi bahan untuk setiap menu yang tersedia.</p>
            </div>

            <!-- impor csv -->
            <section class="manager-panel" style="margin-bottom:22px;">
                <div class="manager-panel-heading">
                    <div>
                        <h3>Impor Data Resep</h3>
                        <p>
                            Unggah file resep.csv untuk memasukkan komposisi semua menu.
                        </p>
                    </div>
                </div>

                <div class="recipe-import-box">
                    <p>
                        Format kolom CSV:
                        <strong>kode_menu, nama_bahan, takaran, satuan</strong>.
                        Impor dilakukan untuk seluruh menu sekaligus.
                    </p>

                    <form
                        method="POST"
                        action="<?= BASEURL ?>/manajer/resep.php<?= $menu ? '?menu_id='.$menu_id : '' ?>"
                        enctype="multipart/form-data"
                        class="recipe-import-form"
                    >
                        <input type="hidden" name="aksi" value="impor_csv">

                        <input
                            type="file"
                            name="file_csv"
                            accept=".csv"
                            required
                        >

                        <button type="submit" class="manager-primary-btn">
                            Impor CSV
                        </button>
                    </form>
                </div>
            </section>

            <?php if (!$menu): ?>

                <!-- daftar resep -->
                <section class="manager-panel manager-report-panel">
                    <div class="manager-panel-heading">
                        <div>
                            <h3>Daftar Resep Menu</h3>
                            <p>
                                Pilih menu untuk melihat dan mengatur komposisi bahannya.
                            </p>
                        </div>

                        <span class="panel-tag">
                            <?= count($menu_list) ?> Menu
                        </span>
                    </div>

                    <?php if ($menu_list): ?>
                        <div class="recipe-grid">
                            <?php foreach ($menu_list as $m): ?>
                                <?php
                                $daftarBahan = $semua_resep[$m['id']] ?? [];
                                $hpp = 0;

                                foreach ($daftarBahan as $b) {
                                    $hpp += $b['takaran'] * $b['harga_satuan'];
                                }
                                ?>
                                <article class="recipe-card">
                                    <h3>
                                        <?= htmlspecialchars($m['nama_menu']) ?>
                                    </h3>

                                    <p>
                                        <?= htmlspecialchars($m['kategori'] ?? 'Menu') ?>
                                    </p>

                                    <p>
                                        <?= count($daftarBahan) ?> bahan dalam resep
                                    </p>
                                    <div class="recipe-hpp">
                                        HPP:
                                        Rp <?= number_format($hpp, 2, ',', '.') ?>
                                    </div><br>
                                    <a
                                        class="btn-sm btn-info"
                                        href="<?= BASEURL ?>/manajer/resep.php?menu_id=<?= $m['id'] ?>"
                                    >
                                        Kelola Resep
                                    </a>
                                </article>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <p>Belum ada data menu. Impor menu terlebih dahulu melalui halaman Menu.</p>

                    <?php endif; ?>
                </section>

            <?php else: ?>
                <p style="margin-bottom:20px;">
                    <a
                        href="<?= BASEURL ?>/manajer/resep.php"
                        class="btn-sm btn-info"
                    >
                        &larr; Kembali ke Daftar Resep
                    </a>
                </p>

                <!-- tambah bahan -->
                <section class="manager-panel" style="margin-bottom:22px;">
                    <div class="manager-panel-heading">
                        <div>
                            <h3>Tambah Komposisi Bahan</h3>
                            <p>
                                Masukkan bahan yang digunakan untuk satu porsi menu.
                            </p>
                        </div>
                    </div>

                    <form
                        method="POST"
                        action="<?= BASEURL ?>/manajer/resep.php?menu_id=<?= $menu_id ?>"
                        style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;"
                    >
                        <input type="hidden" name="aksi" value="tambah_resep">
                        <div class="form-group">
                            <label>Pilih Bahan</label>
                            <select name="bahan_id" required>
                                <option value="">-- Pilih Bahan --</option>
                                <?php foreach ($bahan_list as $b): ?>
                                    <option value="<?= $b['id'] ?>">
                                        <?= htmlspecialchars($b['nama_bahan']) ?>
                                        (<?= htmlspecialchars($b['satuan_dasar']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Takaran per Porsi</label>
                            <input
                                type="number"
                                name="takaran"
                                min="0.001"
                                step="0.001"
                                required
                            >
                        </div>
                        <button type="submit" class="manager-primary-btn">
                            + Tambah Bahan
                        </button>
                    </form>
                </section>

                <!-- daftar komposisi -->
                <section class="manager-panel manager-report-panel">
                    <div class="manager-panel-heading">
                        <div>
                            <h3>Komposisi Bahan</h3>
                            <p>
                                Daftar bahan dan perhitungan HPP untuk satu porsi.
                            </p>
                        </div>

                        <span class="panel-tag">
                            <?= count($resep_list) ?> Bahan
                        </span>
                    </div>

                    <div class="manager-table-wrap">
                        <table class="manager-table">
                            <thead>
                                <tr>
                                    <th>Nama Bahan</th>
                                    <th>Takaran</th>
                                    <th>Harga Satuan</th>
                                    <th>Subtotal HPP</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php $totalHpp = 0; ?>
                                <?php if ($resep_list): ?>
                                    <?php foreach ($resep_list as $r): ?>
                                        <?php
                                        $subtotal = $r['takaran'] * $r['harga_satuan'];
                                        $totalHpp += $subtotal;
                                        ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($r['nama_bahan']) ?>
                                            </td>

                                            <td>
                                                <?= number_format($r['takaran'], 3, ',', '.') ?>
                                                <?= htmlspecialchars($r['satuan_dasar']) ?>
                                            </td>

                                            <td>
                                                Rp <?= number_format($r['harga_satuan'], 2, ',', '.') ?>
                                            </td>

                                            <td>
                                                Rp <?= number_format($subtotal, 2, ',', '.') ?>
                                            </td>

                                            <td>
                                                <div style="display:flex;gap:6px;flex-wrap:wrap;">

                                                    <!-- edit -->
                                                    <form
                                                        method="POST"
                                                        action="<?= BASEURL ?>/manajer/resep.php?menu_id=<?= $menu_id ?>"
                                                        style="display:flex;gap:4px;"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="aksi"
                                                            value="edit_resep"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="resep_id"
                                                            value="<?= $r['id'] ?>"
                                                        >

                                                        <input
                                                            type="number"
                                                            name="takaran"
                                                            value="<?= htmlspecialchars($r['takaran']) ?>"
                                                            min="0.001"
                                                            step="0.001"
                                                            required
                                                            style="width:80px;"
                                                        >

                                                        <button
                                                            class="btn-sm btn-info"
                                                            type="submit"
                                                        >
                                                            Edit
                                                        </button>
                                                    </form>

                                                    <!-- hapus -->
                                                    <form
                                                        method="POST"
                                                        action="<?= BASEURL ?>/manajer/resep.php?menu_id=<?= $menu_id ?>"
                                                        onsubmit="return confirm('Hapus bahan dari resep?')"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="aksi"
                                                            value="hapus_resep"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="resep_id"
                                                            value="<?= $r['id'] ?>"
                                                        >

                                                        <button
                                                            class="btn-sm btn-danger"
                                                            type="submit"
                                                        >
                                                            Hapus
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>
                                    <tr>
                                        <td colspan="3" style="text-align:right;">
                                            <strong>Total HPP:</strong>
                                        </td>
                                        <td colspan="2">
                                            <strong>
                                                Rp <?= number_format($totalHpp, 2, ',', '.') ?>
                                            </strong>
                                        </td>
                                    </tr>

                                <?php else: ?>
                                    <tr>
                                        <td colspan="5">
                                            Belum ada bahan dalam resep menu ini.
                                        </td>
                                    </tr>

                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <!-- footer -->
        <footer class="manager-footer">
            Kedai Kopi Senja · Dashboard Manajer
        </footer>
    </main>
</div>
</body>
</html>
