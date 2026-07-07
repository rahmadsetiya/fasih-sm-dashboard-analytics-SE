<?php

return [
    'version' => env('APP_VERSION', '0.3.0'),

    'history' => [
        [
            'version' => '0.3.0',
            'released_at' => '2026-07-07',
            'title' => 'Versioning dan changelog pengguna',
            'summary' => 'Menambahkan versioning aplikasi, halaman changelog, dan penonaktifan sementara modul yang datanya belum dapat diperbarui secara andal.',
            'highlights' => [
                'Menambahkan halaman changelog agar user dapat melihat riwayat update langsung dari aplikasi.',
                'Menampilkan versi aplikasi aktif dan ringkasan rilis terbaru pada sidebar.',
                'Menonaktifkan sementara halaman Heatmap Aktivitas, Analitik Petugas, Daftar Penugasan, dan Statistik Inferensia karena sumber data belum stabil.',
                'Memperbaiki bootstrap Inertia SSR agar halaman autentikasi dapat dirender tanpa error.',
            ],
        ],
    ],
];
