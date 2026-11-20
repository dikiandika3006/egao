<?php

/**
 * Integrasi SATUSEHAT (Kemenkes). Kredensial per cabang diisi Owner di Pengaturan → SATUSEHAT,
 * pilihan Sandbox/Production disimpan di tabel settings (satusehat_env).
 */
return [
    'environments' => [
        'sandbox' => [
            'label' => 'Sandbox (uji coba)',
            'auth_url' => env('SATUSEHAT_SANDBOX_AUTH_URL', 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1'),
            'fhir_url' => env('SATUSEHAT_SANDBOX_FHIR_URL', 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1'),
        ],
        'production' => [
            'label' => 'Production (data asli)',
            'auth_url' => env('SATUSEHAT_PRODUCTION_AUTH_URL', 'https://api-satusehat.kemkes.go.id/oauth2/v1'),
            'fhir_url' => env('SATUSEHAT_PRODUCTION_FHIR_URL', 'https://api-satusehat.kemkes.go.id/fhir-r4/v1'),
        ],
    ],

    // Detik tunggu respons SATUSEHAT.
    'timeout' => (int) env('SATUSEHAT_TIMEOUT', 30),

    // Batas percobaan otomatis per kunjungan; setelah itu hanya lewat tombol "Kirim ulang".
    'max_attempts' => (int) env('SATUSEHAT_MAX_ATTEMPTS', 5),

    // Jumlah kunjungan per putaran jadwal (tiap 5 menit).
    'batch_size' => (int) env('SATUSEHAT_BATCH_SIZE', 25),
];
