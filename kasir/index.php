<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('kasir');

$error = '';
$success = '';

$stmt = $pdo->query("
    SELECT m.*,
    EXISTS (
        SELECT 1 FROM resep r WHERE r.menu_id = m.id
    ) AS punya_resep
    FROM menu m
    ORDER BY m.kategori, m.nama_menu
");
$menus = $stmt->fetchAll();

$kategori = [];
foreach ($menus as $menu) {
    $kategori[] = $menu['kategori'];
}
$kategori = array_unique($kategori);

// proses pemesanan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomor_meja = intval($_POST['nomor_meja'] ?? 0);
    $metode_bayar = $_POST['metode_bayar'] ?? '';
    $pesanan_items = $_POST['menu_item'] ?? [];

    if ($nomor_meja < 1 || $nomor_meja > 10) {
        $error = 'Nomor meja tidak valid! Harus antara 1 sampai 10.';
    } elseif (!in_array($metode_bayar, ['Tunai', 'QRIS'], true)) {
        $error = 'Metode pembayaran tidak valid.';
    } else {
        $items_to_order = [];

        foreach ($pesanan_items as $menu_id => $qty) {
            $menu_id = intval($menu_id);
            $qty = intval($qty);

            if ($menu_id > 0 && $qty > 0) {
                $items_to_order[$menu_id] = $qty;
            }
        }

        if (empty($items_to_order)) {
            $error = 'Pilih minimal satu menu untuk dipesan.';
        } else {
            try {
                $pdo->beginTransaction();

                $kebutuhan_bahan = [];
                $detail_data = [];
                $subtotal = 0;

                foreach ($items_to_order as $menu_id => $qty) {
                    $stmtM = $pdo->prepare("
                        SELECT * FROM menu
                        WHERE id = :id AND status = 'Aktif'
                    ");
                    $stmtM->execute(['id' => $menu_id]);
                    $menu = $stmtM->fetch();

                    if (!$menu) {
                        throw new Exception(
                            "Menu dengan ID $menu_id tidak ditemukan atau tidak aktif."
                        );
                    }

                    $stmtR = $pdo->prepare("
                        SELECT * FROM resep WHERE menu_id = :menu_id
                    ");
                    $stmtR->execute(['menu_id' => $menu_id]);
                    $resep_list = $stmtR->fetchAll();

                    if (empty($resep_list)) {
                        throw new Exception(
                            "Menu {$menu['nama_menu']} belum memiliki resep."
                        );
                    }

                    $item_subtotal = $menu['harga_jual'] * $qty;
                    $subtotal += $item_subtotal;

                    $detail_data[] = [
                        'menu_id' => $menu_id,
                        'jumlah_porsi' => $qty,
                        'harga_snapshot' => $menu['harga_jual'],
                        'subtotal_item' => $item_subtotal
                    ];

                    foreach ($resep_list as $r) {
                        $b_id = $r['bahan_id'];
                        $total_butuh = $r['takaran'] * $qty;

                        if (!isset($kebutuhan_bahan[$b_id])) {
                            $kebutuhan_bahan[$b_id] = 0;
                        }

                        $kebutuhan_bahan[$b_id] += $total_butuh;
                    }
                }

                // periksa stok
                $bahan_kurang = [];

                foreach ($kebutuhan_bahan as $b_id => $butuh) {
                    $stmtB = $pdo->prepare("
                        SELECT * FROM bahan WHERE id = :id FOR UPDATE
                    ");
                    $stmtB->execute(['id' => $b_id]);
                    $bahan = $stmtB->fetch();

                    if (!$bahan || $bahan['stok'] < $butuh) {
                        if ($bahan) {
                            $bahan_kurang[] =
                                "{$bahan['nama_bahan']} (butuh: $butuh {$bahan['satuan_dasar']}, tersedia: {$bahan['stok']} {$bahan['satuan_dasar']})";
                        } else {
                            $bahan_kurang[] = "Bahan dengan ID $b_id tidak ditemukan.";
                        }
                    }
                }

                if (!empty($bahan_kurang)) {
                    throw new Exception(
                        "Pesanan ditolak! Stok bahan tidak mencukupi:\n- " .
                        implode("\n- ", $bahan_kurang)
                    );
                }

                // potong stok bahan
                foreach ($kebutuhan_bahan as $b_id => $butuh) {
                    $stmtUpd = $pdo->prepare("
                        UPDATE bahan
                        SET stok = stok - :butuh
                        WHERE id = :id
                    ");
                    $stmtUpd->execute([
                        'butuh' => $butuh,
                        'id' => $b_id
                    ]);

                    // periksa menu yang menggunakan bahan tersebut
                    $stmtMenuCek = $pdo->prepare("
                        SELECT DISTINCT menu_id
                        FROM resep
                        WHERE bahan_id = :b_id
                    ");
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
                            $pdo->prepare("
                                UPDATE menu
                                SET status = 'Habis'
                                WHERE id = :m_id AND status = 'Aktif'
                            ")->execute(['m_id' => $m_id]);
                        }
                    }
                }

                // menghitung biaya
                $service_charge = $subtotal * 0.05;
                $pajak = ($subtotal + $service_charge) * 0.10;
                $total_bayar = $subtotal + $service_charge + $pajak;

                // menyimpan pesanan
                $kode_pesanan = 'KKS-' . date('Ymd') . '-' . rand(100, 999);

                $stmtOrder = $pdo->prepare("
                    INSERT INTO pesanan
                    (kode_pesanan, nomor_meja, metode_bayar, subtotal,
                    service_charge, pajak, total_bayar, status)
                    VALUES
                    (:kode, :meja, :bayar, :sub, :service, :pajak, :total, 'Baru')
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

                // menyimpan detail
                $stmtDetail = $pdo->prepare("
                    INSERT INTO detail_pesanan
                    (pesanan_id, menu_id, jumlah_porsi,
                    harga_satuan_snapshot, subtotal_item)
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

                $success = "Pesanan berhasil dibuat! Kode pesanan: " .
                    $kode_pesanan;

                // perbarui daftar menu
                $stmt = $pdo->query("
                    SELECT m.*,
                    EXISTS (
                        SELECT 1 FROM resep r WHERE r.menu_id = m.id
                    ) AS punya_resep
                    FROM menu m
                    ORDER BY m.kategori, m.nama_menu
                ");
                $menus = $stmt->fetchAll();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

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
    <title>Kasir - Kedai Kopi Senja</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
</head>

<body class="kasir-body">
<div class="kasir-layout">
    <header class="kasir-header">
        <div class="kasir-brand">
                <strong>KEDAI KOPI SENJA</strong>
            </div>

        <div class="kasir-user">
            <span>Kasir</span>
            <a href="<?= BASEURL ?>/logout.php">Logout</a>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="alert-error" style="white-space: pre-line; margin: 12px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-success" style="margin: 12px;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="formPesanan">
        <input type="hidden" name="nomor_meja" id="nomorMeja">
        <input type="hidden" name="metode_bayar" id="metodeBayar">
        <main class="kasir-main">
            <aside class="kasir-sidebar">
                <h3>KATEGORI</h3>
                <button type="button"
                        class="kategori-btn active"
                        onclick="filterMenu('Semua', this)">
                    Semua Menu
                </button>

                <?php foreach ($kategori as $k): ?>
                    <button type="button"
                            class="kategori-btn"
                            onclick="filterMenu(<?= htmlspecialchars(json_encode($k), ENT_QUOTES, 'UTF-8') ?>, this)">
                        <?= htmlspecialchars($k) ?>
                    </button>
                <?php endforeach; ?>
            </aside>

            <section class="kasir-menu">
                <div class="kasir-menu-header">
                    <div>
                        <h2>Daftar Menu</h2>
                        <p>Pilih menu untuk membuat pesanan.</p>
                    </div>
                    <input
                        type="text"
                        id="searchMenu"
                        placeholder="Cari menu..."
                        onkeyup="searchMenu()">
                </div>
                <div class="menu-grid" id="menuGrid">
                    <?php if (count($menus) > 0): ?>
                        <?php foreach ($menus as $m): ?>
                            <?php
                            $bisa_dipesan =
                                $m['status'] === 'Aktif' &&
                                $m['punya_resep'];
                            ?>
                            <div class="menu-card"
                                 data-kategori="<?= htmlspecialchars($m['kategori']) ?>"
                                 data-nama="<?= htmlspecialchars(strtolower($m['nama_menu'])) ?>"
                                 data-id="<?= (int)$m['id'] ?>"
                                 data-nama-menu="<?= htmlspecialchars($m['nama_menu'], ENT_QUOTES) ?>"
                                 data-harga="<?= (float)$m['harga_jual'] ?>"
                                 data-bisa="<?= $bisa_dipesan ? '1' : '0' ?>"
                                 onclick="tambahMenu(this)">

                                <div class="menu-image">
                                    <span>FOTO MENU</span>
                                </div>
                                <div class="menu-info">
                                    <span class="menu-category">
                                        <?= htmlspecialchars($m['kategori']) ?>
                                    </span>
                                    <h3>
                                        <?= htmlspecialchars($m['nama_menu']) ?>
                                    </h3>
                                    <strong class="menu-price">
                                        Rp <?= number_format($m['harga_jual'], 0, ',', '.') ?>
                                    </strong>

                                    <?php if ($bisa_dipesan): ?>
                                        <span class="menu-status tersedia">
                                            Available
                                        </span>
                                    <?php else: ?>
                                        <span class="menu-status habis">
                                            Unavailable
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($bisa_dipesan): ?>
                                        <small class="petunjuk-menu">
                                            Klik untuk menambahkan
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Belum ada menu yang tersedia.</p>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="kasir-order">

                <div class="order-header">
                    <h3>Pesanan</h3>
                    <span id="labelMeja">Belum dipilih</span>
                </div>

                <div id="orderContent">
                    <div class="order-empty">
                        <div class="order-empty-icon">+</div>
                        <strong>Belum ada pesanan</strong>
                        <p>Silakan mulai pesanan terlebih dahulu.</p>
                    </div>
                </div>

                <div class="order-footer">
                    <div id="ringkasanTotal" style="display:none;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                            <span>Subtotal</span>
                            <strong id="subtotalText">Rp 0</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                            <span>Service 5%</span>
                            <span id="serviceText">Rp 0</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                            <span>Pajak 10%</span>
                            <span id="pajakText">Rp 0</span>
                        </div>
                        <hr>
                        <div style="display:flex; justify-content:space-between; margin:12px 0;">
                            <strong>Total</strong>
                            <strong id="totalText">Rp 0</strong>
                        </div>
                    </div>

                    <button type="button"
                            class="btn-buat-pesanan"
                            id="btnMulai"
                            onclick="bukaModal()">
                        BUAT PESANAN
                    </button>

                    <button type="submit"
                            class="btn-buat-pesanan"
                            id="btnProses"
                            style="display:none;">
                        PROSES PESANAN
                    </button>

                    <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php"
                       class="btn-riwayat">
                        LIHAT PESANAN
                    </a>
                </div>
            </aside>
        </main>

        <footer class="kasir-bottom-nav">
        <a href="<?= BASEURL ?>/kasir/index.php"
           class="bottom-nav-item">
            <span>🏠︎</span>
            <small>Menu</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php"
           class="bottom-nav-item active">
            <span>🗒</span>
            <small>Pesanan</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/reset_password.php"
           class="bottom-nav-item">
            <span>⟳</span>
            <small>Reset Akun</small>
        </a>
        </footer>
    </form>
</div>

<!-- mulai pesanan -->
<div id="modalPesanan"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:1000; align-items:center; justify-content:center; padding:20px;">

    <div style="background:#303637; color:#f4f0ed; width:100%; max-width:400px; padding:24px; border-radius:14px; border:1px solid #484f50;">

        <h2 style="margin-top:0;">Mulai Pesanan</h2>
        <p style="color:#aaa;">Tentukan meja dan metode pembayaran terlebih dahulu.</p>

        <label for="pilihMeja">Nomor Meja</label>
        <select id="pilihMeja"
                style="width:100%; padding:12px; margin:8px 0 18px; border-radius:8px;">
            <option value="">Pilih meja</option>
            <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?= $i ?>">Meja <?= $i ?></option>
            <?php endfor; ?>
        </select>

        <label for="pilihBayar">Metode Pembayaran</label>
        <select id="pilihBayar"
                style="width:100%; padding:12px; margin:8px 0 20px; border-radius:8px;">
            <option value="Tunai">Tunai</option>
            <option value="QRIS">QRIS</option>
        </select>

        <div style="display:flex; gap:10px;">
            <button type="button"
                    onclick="tutupModal()"
                    class="btn-riwayat"
                    style="flex:1;">
                BATAL
            </button>

            <button type="button"
                    onclick="mulaiPesanan()"
                    class="btn-buat-pesanan"
                    style="flex:1;">
                MULAI
            </button>
        </div>
    </div>
</div>

<script>
let pesanan = {};
let pesananDimulai = false;

function formatRupiah(angka) {
    return 'Rp ' + Number(angka).toLocaleString('id-ID');
}

function bukaModal() {
    document.getElementById('modalPesanan').style.display = 'flex';
}

function tutupModal() {
    document.getElementById('modalPesanan').style.display = 'none';
}

function mulaiPesanan() {
    const meja = document.getElementById('pilihMeja').value;
    const bayar = document.getElementById('pilihBayar').value;

    if (!meja) {
        alert('Silakan pilih nomor meja terlebih dahulu.');
        return;
    }

    document.getElementById('nomorMeja').value = meja;
    document.getElementById('metodeBayar').value = bayar;
    document.getElementById('labelMeja').textContent = 'Meja ' + meja;

    pesananDimulai = true;

    document.getElementById('btnMulai').style.display = 'none';
    document.getElementById('btnProses').style.display = 'block';

    tutupModal();

    alert('Pesanan dimulai. Silakan pilih menu.');
}

function tambahMenu(card) {
    if (!pesananDimulai) {
        alert('Silakan klik BUAT PESANAN dan pilih meja terlebih dahulu.');
        return;
    }

    if (card.dataset.bisa !== '1') {
        return;
    }

    const id = card.dataset.id;

    if (!pesanan[id]) {
        pesanan[id] = {
            id: id,
            nama: card.dataset.namaMenu,
            harga: Number(card.dataset.harga),
            jumlah: 0
        };
    }

    pesanan[id].jumlah++;
    tampilkanPesanan();
}

function ubahJumlah(id, perubahan) {
    if (!pesanan[id]) return;

    pesanan[id].jumlah += perubahan;

    if (pesanan[id].jumlah <= 0) {
        delete pesanan[id];
    }

    tampilkanPesanan();
}

function tampilkanPesanan() {
    const content = document.getElementById('orderContent');
    const totalBox = document.getElementById('ringkasanTotal');
    const btnProses = document.getElementById('btnProses');

    const daftar = Object.values(pesanan);

    if (daftar.length === 0) {
        content.innerHTML = `
            <div class="order-empty">
                <div class="order-empty-icon">+</div>
                <strong>Belum ada menu</strong>
                <p>Klik menu yang ingin dipesan.</p>
            </div>
        `;

        totalBox.style.display = 'none';
        btnProses.disabled = true;
        btnProses.style.opacity = '0.5';
        return;
    }

    let subtotal = 0;
    let html = '<div style="padding:12px;">';

    daftar.forEach(item => {
        const jumlahHarga = item.harga * item.jumlah;
        subtotal += jumlahHarga;

        html += `
            <div style="border-bottom:1px solid #484f50; padding:12px 0;">
                <strong>${escapeHTML(item.nama)}</strong>
                <div style="color:#aaa; margin:5px 0;">
                    ${formatRupiah(item.harga)}
                </div>
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <button type="button"
                                onclick="ubahJumlah('${item.id}', -1)"
                                style="padding:5px 10px;">−</button>

                        <strong>${item.jumlah}</strong>

                        <button type="button"
                                onclick="ubahJumlah('${item.id}', 1)"
                                style="padding:5px 10px;">+</button>
                    </div>

                    <strong>${formatRupiah(jumlahHarga)}</strong>
                </div>
            </div>

            <input type="hidden"
                   name="menu_item[${item.id}]"
                   value="${item.jumlah}">
        `;
    });

    html += '</div>';
    content.innerHTML = html;

    const service = subtotal * 0.05;
    const pajak = (subtotal + service) * 0.10;
    const total = subtotal + service + pajak;

    document.getElementById('subtotalText').textContent = formatRupiah(subtotal);
    document.getElementById('serviceText').textContent = formatRupiah(service);
    document.getElementById('pajakText').textContent = formatRupiah(pajak);
    document.getElementById('totalText').textContent = formatRupiah(total);

    totalBox.style.display = 'block';
    btnProses.disabled = false;
    btnProses.style.opacity = '1';
}

function escapeHTML(teks) {
    return String(teks).replace(/[&<>"']/g, function (karakter) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[karakter];
    });
}

function filterMenu(kategori, tombol) {
    document.querySelectorAll('.menu-card').forEach(card => {
        card.style.display =
            kategori === 'Semua' || card.dataset.kategori === kategori
                ? ''
                : 'none';
    });

    document.querySelectorAll('.kategori-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    tombol.classList.add('active');
}

function searchMenu() {
    const keyword = document
        .getElementById('searchMenu')
        .value.toLowerCase();

    document.querySelectorAll('.menu-card').forEach(card => {
        const nama = card.dataset.nama;
        card.style.display = nama.includes(keyword) ? '' : 'none';
    });
}

document.getElementById('formPesanan').addEventListener('submit', function (e) {
    if (!pesananDimulai) {
        e.preventDefault();
        alert('Mulai pesanan terlebih dahulu.');
        return;
    }

    if (Object.keys(pesanan).length === 0) {
        e.preventDefault();
        alert('Pilih minimal satu menu.');
        return;
    }

    if (!confirm('Proses pesanan dan potong stok bahan sekarang?')) {
        e.preventDefault();
    }
});
</script>
</body>
</html>
