<?php

return [
    'version' => env('APP_VERSION', '0.4.6'),

    'history' => [
        [
            'version' => '0.4.6',
            'released_at' => '2026-07-22',
            'title' => 'Analisis Prelist Dinamis vs Awal',
            'summary' => 'Menambahkan pilihan basis prelist agar user bisa membandingkan target dinamis dari FASIH dengan prelist awal operasional.',
            'highlights' => [
                'Menambahkan toggle Basis Prelist Dinamis/Awal pada Dashboard dan Ringkasan Kabupaten.',
                'Menampilkan card Gap Prelist berisi total dinamis, total awal, selisih, dan mismatch coverage Sub-SLS.',
                'Menambahkan kolom Prelist Dinamis, Prelist Awal, dan Selisih pada tabel rincian dashboard serta export Excel.',
                'Menambahkan command import prelist awal dari workbook Master SE2026 tanpa menyimpan file Excel di repository.',
                'Memastikan persentase submit, approved, dan rejected memakai denominator basis prelist yang dipilih.',
            ],
        ],
        [
            'version' => '0.4.5',
            'released_at' => '2026-07-20',
            'title' => 'Bobot Reject pada Proyeksi Petugas',
            'summary' => 'Menyempurnakan proyeksi petugas dengan laju efektif yang mempertimbangkan tingkat reject.',
            'highlights' => [
                'Memastikan progress proyeksi menghitung seluruh status selain OPEN dan DRAFT.',
                'Menambahkan jumlah reject, persentase reject, laju efektif per hari, dan estimasi selesai terkoreksi reject.',
                'Menambahkan badge Reject Rendah, Perlu Pantau, dan Reject Tinggi untuk membaca risiko kualitas petugas.',
                'Memperbarui tabel, modal detail, tren, dan export Excel proyeksi agar menampilkan indikator reject.',
            ],
        ],
        [
            'version' => '0.4.4',
            'released_at' => '2026-07-20',
            'title' => 'Proyeksi Selesai Petugas',
            'summary' => 'Menambahkan monitoring target submit harian per PPL/PML sampai deadline operasional.',
            'highlights' => [
                'Menambahkan halaman Proyeksi Petugas untuk melihat target submit harian, laju aktual, sisa assignment, dan estimasi selesai.',
                'Menampilkan badge Aman, Berisiko, Belum Bergerak, dan Selesai agar prioritas pendampingan lebih cepat terlihat.',
                'Menambahkan modal detail petugas berisi tren harian, target vs realisasi, breakdown status, dan wilayah tugas.',
                'Menyediakan filter role, snapshot, deadline, wilayah, status proyeksi, pencarian nama, serta export Excel sesuai tampilan.',
            ],
        ],
        [
            'version' => '0.4.3',
            'released_at' => '2026-07-14',
            'title' => 'Akurasi Filter dan Ekspor Dashboard',
            'summary' => 'Memperbaiki filter wilayah bertingkat, memperjelas metrik submit, dan menambahkan ekspor tabel sesuai tampilan user.',
            'highlights' => [
                'Mengubah label metrik progress menjadi % Submit dengan rumus yang tetap konsisten: Total dikurangi OPEN dan DRAFT.',
                'Menambahkan status Admin Kabupaten ke tabel, pie chart, ringkasan, dan detail peta.',
                'Memperbaiki filter Desa/SLS agar pilihan wilayah pada beberapa kecamatan tidak saling bocor karena kode lokal yang sama.',
                'Menambahkan export Excel pada tabel dashboard sesuai filter, pencarian, sorting, dan kolom status aktif.',
                'Menampilkan modal changelog otomatis saat user pertama membuka aplikasi, dengan opsi jangan tampilkan lagi per versi.',
                'Mengubah tren submit agar memakai snapshot terakhir setiap tanggal, tetap tujuh titik aktual dan tiga titik proyeksi.',
            ],
        ],
        [
            'version' => '0.4.2',
            'released_at' => '2026-07-08',
            'title' => 'Konteks Peta dan Batas Wilayah',
            'summary' => 'Memperjelas orientasi spasial melalui peta dasar dan hierarki garis batas administratif.',
            'highlights' => [
                'Menambahkan peta dasar OpenStreetMap di bawah layer progress wilayah.',
                'Membedakan batas Kecamatan, Desa, SLS, dan Sub-SLS melalui warna serta ketebalan garis.',
                'Mempertahankan fallback background agar polygon tetap dapat digunakan saat tile tidak tersedia.',
                'Memperbarui atribusi footer aplikasi untuk IPDS BPS Enrekang.',
            ],
        ],
        [
            'version' => '0.4.1',
            'released_at' => '2026-07-08',
            'title' => 'Filter Petugas dan Detail Wilayah',
            'summary' => 'Menambahkan eksplorasi wilayah tugas PPL/PML serta rincian progress operasional pada setiap polygon.',
            'highlights' => [
                'Menambahkan filter PPL dan PML yang menyorot seluruh wilayah tugas pada peta.',
                'Menambahkan modal detail wilayah untuk Kecamatan, Desa, SLS, dan Sub-SLS.',
                'Menampilkan Open, Draft, Submitted, Rejected, dan Approved secara agregat dan per PPL.',
                'Menampilkan relasi PPL dengan PML tanpa mengekspos email atau username.',
                'Memindahkan aksi drill-down ke modal detail agar klik polygon konsisten.',
            ],
        ],
        [
            'version' => '0.4.0',
            'released_at' => '2026-07-08',
            'title' => 'Peta Wilayah Interaktif',
            'summary' => 'Menambahkan eksplorasi progres berbasis polygon hingga Sub-SLS dengan drill-down, perbandingan snapshot, dan laporan kualitas geometri.',
            'highlights' => [
                'Menambahkan halaman Peta Wilayah offline berbasis MapLibre dengan 669 polygon wilayah.',
                'Mendukung drill-down Kecamatan, Desa, SLS, dan Sub-SLS serta detail status dan petugas.',
                'Menambahkan choropleth progress, approved, submitted, rejected, open, assignment, prioritas, dan coverage.',
                'Menambahkan perbandingan snapshot, pencarian wilayah, ekspor CSV/PNG, dan deep-link.',
                'Menambahkan validasi GeoJSON dan laporan coverage join antara geometri dan fasih.db.',
            ],
        ],
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
