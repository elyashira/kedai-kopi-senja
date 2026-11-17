<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role('kasir');

$pesanan_id = (int)($_GET['id'] ?? 0);

if ($pesanan_id <= 0) {
    die('ID pesanan tidak valid.');
}

// Ambil data pesanan
$stmt = $pdo->prepare("
    SELECT *
    FROM pesanan
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$pesanan_id]);
$pesanan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pesanan) {
    die('Pesanan tidak ditemukan.');
}

// Ambil detail pesanan beserta nama menu
$stmtDetail = $pdo->prepare("
    SELECT
        dp.*,
        m.nama_menu
    FROM detail_pesanan dp
    INNER JOIN menu m ON dp.menu_id = m.id
    WHERE dp.pesanan_id = ?
    ORDER BY dp.id ASC
");
$stmtDetail->execute([$pesanan_id]);
$detailPesanan = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

// Format rupiah
function formatRupiah($angka)
{
    return 'Rp' . number_format((float)$angka, 0, ',', '.');
}

// Tanggal pesanan
$tanggal = date(
    'd/m/Y H:i',
    strtotime($pesanan['created_at'])
);

// Ambil nilai transaksi dari database
$subtotal = (float)$pesanan['subtotal'];
$service = (float)$pesanan['service_charge'];
$pajak = (float)$pesanan['pajak'];
$total = (float)$pesanan['total_bayar'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Pesanan - Kedai Kopi Senja</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 8px 15px 32px;
            background: #F5EAD3;
            font-family: Arial, sans-serif;
            color: #513824;
        }

        .struk {
            width: 100%;
            max-width: 440px;
            margin: 0 auto;
            padding: 28px;
            background: #FFFCF6;
            border: 1px dashed #CDBB9A;
        }

        .header {
            text-align: center;
            margin-bottom: 38px;
        }

        .header h1 {
            margin: 0;
            font-family: Georgia, serif;
            font-size: 32px;
            font-weight: normal;
            color: #513824;
        }

        .alamat {
            margin-top: 7px;
            font-size: 12px;
            color: #927B5D;
        }

        .judul {
            margin: 0 0 12px;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            color: #304957;
        }

        .invoice {
            margin-bottom: 12px;
            text-align: center;
            font-size: 12px;
            color: #927B5D;
        }

        .items {
            margin-top: 8px;
        }

        .item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 13px;
            font-size: 14px;
        }

        .item-nama {
            flex: 1;
            line-height: 1.4;
        }

        .item-harga {
            white-space: nowrap;
            text-align: right;
        }

        .garis {
            border: none;
            margin: 18px 0;
        }

        .rincian {
            margin-top: 26px;
        }

        .rincian-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 13px;
            font-size: 14px;
        }

        .rincian-row span:last-child {
            white-space: nowrap;
            text-align: right;
        }

        .total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
            color: #304957;
        }

        .total strong:first-child {
            font-size: 28px;
        }

        .total strong:last-child {
            font-size: 28px;
            text-align: right;
        }

        .terima-kasih {
            margin-top: 10px;
            text-align: center;
            font-size: 12px;
            color: #927B5D;
        }

        .tombol {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 20px auto;
        }

        .tombol button,
        .tombol a {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            background: #513824;
            color: white;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .tombol a {
            background: #927B5D;
        }

        @media print {
            body {
                padding: 0;
                background: white;
            }

            .struk {
                margin: 0 auto;
                border: 1px dashed #CDBB9A;
                width: 100%;
                max-width: 440px;
            }

            .tombol {
                display: none !important;
            }

            @page {
                size: auto;
                margin: 8mm;
            }
        }
    </style>
</head>

<body>

<div class="struk">

    <div class="header">
        <h1>Kedai Kopi Senja</h1>
        <div class="alamat">
            Jl. Senja No. 12 · Bandung
        </div>
    </div>

    <h3 class="judul">STRUK PESANAN</h3>

    <div class="invoice">
        <?= htmlspecialchars($pesanan['kode_pesanan']) ?>
        ·
        <?= htmlspecialchars($tanggal) ?>
    </div>

    <div class="items">

        <?php foreach ($detailPesanan as $item): ?>

            <div class="item">

                <div class="item-nama">
                    <?= htmlspecialchars($item['nama_menu']) ?>
                    x<?= (int)$item['jumlah_porsi'] ?>
                </div>

                <div class="item-harga">
                    <?= formatRupiah($item['subtotal_item']) ?>
                </div>

            </div>

        <?php endforeach; ?>

    </div>

    <div class="rincian">

        <div class="rincian-row">
            <span>Subtotal</span>
            <span><?= formatRupiah($subtotal) ?></span>
        </div>

        <div class="rincian-row">
            <span>Service charge</span>
            <span><?= formatRupiah($service) ?></span>
        </div>

        <div class="rincian-row">
            <span>Pajak</span>
            <span><?= formatRupiah($pajak) ?></span>
        </div>

    </div>

    <div class="total">
        <strong>Total</strong>
        <strong><?= formatRupiah($total) ?></strong>
    </div>

    <div class="terima-kasih">
        Terima kasih telah berkunjung.
    </div>

</div>

<div class="tombol">
    <button onclick="window.print()">Cetak Struk</button>
    <a href="manajemen_pesanan.php">Kembali</a>
</div>

</body>
</html>