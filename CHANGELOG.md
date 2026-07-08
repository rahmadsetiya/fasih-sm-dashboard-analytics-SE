# Changelog

Semua perubahan penting pada proyek ini didokumentasikan di file ini.

Format changelog ini mengikuti pendekatan ringkas berbasis versi aplikasi yang ditampilkan ke user di dalam dashboard.

## [0.3.1] - 2026-07-08

### Ditambahkan
- Badge versi pada tombol changelog agar pembaruan lebih mudah ditemukan.
- Card Filter Wilayah interaktif dengan ikon, status filter, chip pilihan, dan animasi expand/collapse.
- Penanda komposisi grafik tren `7 aktual + 3 proyeksi`.

### Diubah
- Metadata versi dan snapshot pada header dibuat lebih ringkas.
- Filter dashboard mobile dipadatkan dan filter utama dibuat sticky.
- Hierarki warna kartu metrik diperjelas agar Progress menjadi fokus utama.
- Label status pada komposisi data ditampilkan lebih lengkap.
- Nama pengawas dan pencacah ditampilkan dalam format Title Case.
- Grafik tren menggunakan tujuh snapshot aktual terbaru dan tiga titik proyeksi.

### Disembunyikan
- Akses Nama Wilayah pada sidebar desktop dan mobile.

### Diperbaiki
- Warning atribut Vue pada halaman Dashboard dengan fragment root.
- Responsivitas dashboard tanpa horizontal overflow pada viewport mobile.

## [0.3.0] - 2026-07-07

### Ditambahkan
- Versioning aplikasi melalui `APP_VERSION` dan `config/app.php`.
- Metadata rilis terpusat di `config/releases.php`.
- Halaman `Changelog` di aplikasi untuk menampilkan riwayat update ke user.
- Ringkasan versi aktif dan rilis terbaru pada sidebar.

### Diubah
- Navigasi aplikasi kini menyertakan akses langsung ke changelog.
- Halaman Heatmap Aktivitas, Analitik Petugas, Daftar Penugasan, dan Statistik Inferensia dinonaktifkan sementara dari sidebar.

### Diperbaiki
- Bootstrap Inertia SSR diperbaiki agar halaman autentikasi tidak gagal dirender.

### Catatan
- Dokumentasi perubahan formal dimulai dari versi `0.3.0`. Perubahan sebelum versi ini belum dibackfill secara detail.

