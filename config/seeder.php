<?php
require_once __DIR__ . '/database.php';

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role IN ('manajer', 'kasir')");
    $stmt->execute();
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        $passwordManajer = password_hash('manajer123', PASSWORD_DEFAULT);
        $passwordKasir = password_hash('kasir123', PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (nama, role, password) VALUES 
                ('Bu Rani', 'manajer', :pass_manajer),
                ('Kasir Senja', 'kasir', :pass_kasir)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'pass_manajer' => $passwordManajer,
            'pass_kasir' => $passwordKasir
        ]);

        echo "<script>console.log('Seeder akun default (Manajer & Kasir) berhasil ditambahkan!');</script>";
    }
} catch (PDOException $e) {
    echo "Gagal menjalankan seeder: " . $e->getMessage();
}