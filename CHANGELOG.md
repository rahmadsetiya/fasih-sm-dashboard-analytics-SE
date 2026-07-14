# Changelog

Semua perubahan penting pada proyek ini didokumentasikan di file ini.

Format changelog ini mengikuti pendekatan ringkas berbasis versi aplikasi yang ditampilkan ke user di dalam dashboard.

## [0.4.3] - 2026-07-14

### Ditambahkan
- Export Excel pada tabel dashboard yang mengikuti level, filter wilayah, pencarian, sorting, dan kolom status aktif.
- Modal changelog otomatis saat user pertama membuka aplikasi, dengan opsi `Jangan tampilkan lagi untuk versi ini`.
- Status `COMPLETED/EDITED/REJECTED/REVOKED BY Admin Kabupaten` pada agregasi dashboard, ringkasan, pie chart, dan detail peta.

### Diubah
- Label metrik `Progress` di dashboard dan ringkasan diubah menjadi `% Submit`.
- Tren Submit Over Time memakai snapshot terakhir pada setiap tanggal, tetap tujuh titik aktual dan tiga titik proyeksi.
- `Progres Lapangan` kini menghitung seluruh status selain `OPEN`, termasuk `SUBMITTED RESPONDENT` dan status Admin Kabupaten.
- Label status pada pie/donut chart disamakan dengan nama kolom asli dari `fasih.db`.

### Diperbaiki
- Filter Desa/SLS bertingkat kini memakai kode komposit parent-child sehingga memilih desa pada beberapa kecamatan tidak ikut mencentang atau memfilter desa lain dengan kode lokal sama.

## [0.4.2] - 2026-07-08

### Ditambahkan
- Peta dasar OpenStreetMap untuk memberi konteks jalan dan lokasi di balik polygon progress.
- Layer batas bertingkat dengan garis berbeda untuk Kecamatan, Desa, SLS, dan Sub-SLS.
- Fallback background lokal agar peta operasional tetap dapat digunakan saat tile eksternal tidak tersedia.

### Diubah
- Warna dan ketebalan batas menyesuaikan mode terang/gelap dan tingkat zoom.
- Footer sidebar menjadi `Build with ♥️ IPDS BPS Enrekang`.

## [0.4.1] - 2026-07-08

### Ditambahkan
- Filter PPL/PML untuk menyorot dan menyesuaikan viewport ke seluruh wilayah tugas petugas.
- Modal detail pada setiap level wilayah dengan ringkasan Open, Draft, Submitted, Rejected, dan Approved.
- Rincian progress per pencacah beserta PML penanggung jawab dan pencarian nama petugas.
- Deep-link untuk mempertahankan filter petugas aktif.

### Diubah
- Klik polygon membuka modal detail; drill-down dilakukan melalui tombol di dalam modal.
- Polygon di luar wilayah tugas petugas diredupkan tanpa menghilangkan konteks geografis.

### Keamanan
- API petugas hanya mengirim ID internal, nama, peran, dan cakupan tugas tanpa email atau username.

## [0.4.0] - 2026-07-08

### Ditambahkan
- Halaman Peta Wilayah berbasis MapLibre yang berfungsi tanpa tile atau basemap eksternal.
- Choropleth hingga Sub-SLS untuk Progress, Submitted, Approved, Rejected, Open, assignment, prioritas, dan coverage.
- Drill-down wilayah, pencarian, tooltip, detail status/petugas/tren, dan breadcrumb.
- Perbandingan dua snapshot dalam bentuk delta spasial.
- Daftar wilayah prioritas, laporan kualitas geometri, ekspor CSV/PNG, dan deep-link.
- Pipeline sanitasi GeoJSON dengan ETag serta cache privat.
- API geospasial terautentikasi untuk boundaries, metrics, dan detail wilayah.

### Keamanan
- Properti internal GeoJSON seperti path sumber, email, dan metadata proses tidak dikirim ke browser.

### Kualitas Data
- Seluruh 669 polygon riil cocok dengan kode wilayah di `fasih.db`.
- Kode sentinel `7316000000000000` dilaporkan terpisah sebagai data tanpa geometri.

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
