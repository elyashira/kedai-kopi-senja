
<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('kasir');

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

    <!-- HEADER -->
    <header class="kasir-header">
        <div class="kasir-brand">
            <div class="kasir-logo">KS</div>
            <div>
                <strong>KEDAI KOPI SENJA</strong>
                <small>Point of Sale</small>
            </div>
        </div>

        <div class="kasir-user">
            <span>Kasir</span>
            <a href="<?= BASEURL ?>/logout.php">Logout</a>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="kasir-main">

        <!-- SIDEBAR KATEGORI -->
        <aside class="kasir-sidebar">
            <h3>KATEGORI</h3>

            <button class="kategori-btn active"
                    onclick="filterMenu('Semua', this)">
                Semua Menu
            </button>

            <?php foreach ($kategori as $k): ?>
                <button class="kategori-btn"
                        onclick="filterMenu(<?= htmlspecialchars(json_encode($k), ENT_QUOTES, 'UTF-8') ?>, this)">
                    <?= htmlspecialchars($k) ?>
                </button>
            <?php endforeach; ?>
        </aside>

        <!-- DAFTAR MENU -->
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
                    onkeyup="searchMenu()"
                >
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
                             data-nama="<?= htmlspecialchars(strtolower($m['nama_menu'])) ?>">

                            <!-- TEMPAT FOTO PRODUK -->
                            <div class="menu-image">
                                <!-- Foto produk bisa ditambahkan nanti -->
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
                                        Tersedia
                                    </span>
                                <?php elseif (!$m['punya_resep']): ?>
                                    <span class="menu-status habis">
                                        Belum Ada Resep
                                    </span>
                                <?php else: ?>
                                    <span class="menu-status habis">
                                        <?= htmlspecialchars($m['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p>Belum ada menu yang tersedia.</p>

                <?php endif; ?>

            </div>

        </section>

        <!-- PANEL PESANAN -->
        <aside class="kasir-order">

            <div class="order-header">
                <h3>Pesanan</h3>
                <span>Kasir</span>
            </div>

            <div class="order-empty">
                <div class="order-empty-icon">+</div>
                <strong>Belum ada pesanan</strong>
                <p>Silakan pilih menu untuk memulai transaksi.</p>
            </div>

            <div class="order-footer">
                <a href="<?= BASEURL ?>/kasir/transaksi.php"
                   class="btn-buat-pesanan">
                    BUAT PESANAN
                </a>

                <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php"
                   class="btn-riwayat">
                    LIHAT PESANAN
                </a>
            </div>

        </aside>

    </main>

    <!-- NAVIGASI BAWAH -->
    <footer class="kasir-bottom-nav">

        <a href="<?= BASEURL ?>/kasir/index.php" class="bottom-nav-item active">
            <span>⌂</span>
            <small>Menu</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/transaksi.php" class="bottom-nav-item">
            <span>＋</span>
            <small>Transaksi</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/manajemen_pesanan.php" class="bottom-nav-item">
            <span>▤</span>
            <small>Pesanan</small>
        </a>

        <a href="<?= BASEURL ?>/kasir/reset_password.php" class="bottom-nav-item">
            <span>♙</span>
            <small>Reset Akun</small>
        </a>

        <a href="<?= BASEURL ?>/logout.php" class="bottom-nav-item">
            <span>↪</span>
            <small>Keluar</small>
        </a>

    </footer>

</div>

<script>
function filterMenu(kategori, tombol) {
    const cards = document.querySelectorAll('.menu-card');

    cards.forEach(card => {
        if (kategori === 'Semua' ||
            card.dataset.kategori === kategori) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
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
</script>

</body>
</html>
