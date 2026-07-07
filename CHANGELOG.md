# Changelog

Semua perubahan penting pada proyek ini didokumentasikan di file ini.

Format changelog ini mengikuti pendekatan ringkas berbasis versi aplikasi yang ditampilkan ke user di dalam dashboard.

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

