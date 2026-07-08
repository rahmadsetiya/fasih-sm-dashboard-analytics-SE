<?php

return [
    'version' => env('APP_VERSION', '0.3.1'),

    'history' => [
        [
            'version' => '0.3.1',
            'released_at' => '2026-07-08',
            'title' => 'Penyempurnaan pengalaman dashboard',
            'summary' => 'Meningkatkan hierarki visual, pengalaman filter wilayah, keterbacaan data petugas, dan konsistensi grafik tren progres.',
            'highlights' => [
                'Mengubah akses changelog menjadi badge yang lebih jelas dan menyederhanakan metadata pada header.',
                'Memperbarui Filter Wilayah menjadi card interaktif dengan status, chip pilihan, dan animasi.',
                'Mengoptimalkan layout filter dan kartu metrik untuk desktop serta perangkat mobile.',
                'Menampilkan nama pengawas dan pencacah dalam format Title Case.',
                'Menetapkan grafik tren menjadi tujuh titik aktual dan tiga titik proyeksi.',
                'Menyembunyikan sementara akses Nama Wilayah dari sidebar.',
            ],
        ],
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
