<?php
require_once __DIR__ . '/env.php';

function ambilHargaBahanSupplier()
{
    $url = rtrim(env('LAYANAN_URL', ''), '/') . '/harga-bahan';
    $apiKey = env('LAYANAN_API_KEY', '');

    if ($url === '/harga-bahan' || $apiKey === '') {
        return [
            'success' => false,
            'message' => 'Konfigurasi API belum lengkap.'
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'Ekstensi cURL belum tersedia.'
        ];
    }

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);

    curl_close($curl);

    if ($response === false || $curlError !== 0 || $httpCode !== 200) {
        return [
            'success' => false,
            'message' => 'Layanan supplier sedang tidak tersedia.'
        ];
    }

    $hasil = json_decode($response, true);

    if (
        !is_array($hasil) ||
        !isset($hasil['data']) ||
        !is_array($hasil['data'])
    ) {
        return [
            'success' => false,
            'message' => 'Respons supplier tidak valid.'
        ];
    }

    return [
        'success' => true,
        'diperbarui' => $hasil['diperbarui'] ?? null,
        'data' => $hasil['data']
    ];
}
