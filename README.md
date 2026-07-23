# Dashboard SE Enrekang — Monitoring SE2026

Dashboard monitoring berbasis web untuk memantau progres kerja lapangan **Sensus/Survey FASIH** di Kabupaten Enrekang. Dibuat oleh IPDS BPS Enrekang.

---

## Daftar Isi

1. [Fitur](#fitur)
2. [Tech Stack](#tech-stack)
3. [Prasyarat](#prasyarat)
4. [Setup Lokal](#setup-lokal)
5. [Generate fasih.db (Scraper)](#generate-fasihdb-scraper)
6. [Deploy ke Shared Hosting (cPanel)](#deploy-ke-shared-hosting-cpanel)
7. [Setup Pertama Kali di Server](#setup-pertama-kali-di-server)
8. [Manajemen Data](#manajemen-data)
9. [Halaman & Fitur](#halaman--fitur)
10. [Versioning & Changelog](#versioning--changelog)
11. [Struktur Database](#struktur-database)
12. [File Penting](#file-penting)
13. [Troubleshooting](#troubleshooting)

---

## Fitur

- **Proyeksi Petugas** - target submit harian, laju aktual, estimasi selesai, dan prioritas pendampingan PPL/PML sampai deadline operasional

- **Dashboard utama** — filter per snapshot, role (Pengawas/Pencacah), level wilayah, dan region; metric cards, donut chart, ranking wilayah responsif, tren submit, tabel rincian, dan export Excel sesuai tampilan tabel
- **Ringkasan Kabupaten** — tabel rekap per kecamatan dengan persentase progres dan approval
- **Heatmap Aktivitas** — aktivitas petugas per hari dan per jam; filter tanggal dan wilayah
- **Analitik Petugas** — 6 tab analitik: Daftar, Funnel Status, Matrix, Leaderboard, Mangkrak, Proyeksi Selesai
- **Daftar Penugasan** — tabel seluruh assignment + riwayat perubahan status per penugasan
- **Statistik Inferensial** — uji proporsi, komparasi wilayah, chi-square, korelasi, analisis bangunan kosong
- **Analisis Prelist** - toggle Prelist Dinamis/Awal, gap target, dan import prelist awal dari workbook Master SE2026
- **Import Database** — upload `fasih.db` langsung dari browser tanpa akses server
- **Admin Panel** — manajemen user (CRUD), nama petugas, dan nama wilayah
- **Dark mode** + timezone WITA (UTC+8)
- **Autentikasi** — login password + Passkeys (WebAuthn)

---

## Tech Stack

| Layer | Teknologi |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Frontend | Vue 3, TypeScript, Tailwind CSS 4 |
| Routing | Inertia.js (SSR-style SPA, tanpa REST API publik) |
| Charts | ApexCharts via `vue3-apexcharts` |
| UI Components | Reka UI (shadcn-style), PrimeVue, Lucide icons |
| Auth | Laravel Fortify + Passkeys (WebAuthn) |
| Build Tool | Vite |
| Database | SQLite (2 koneksi terpisah) |

---

## Prasyarat

Pastikan sudah terinstal di mesin lokal:

| Software | Versi Minimum | Cek |
|---|---|---|
| PHP | 8.3+ | `php -v` |
| Composer | 2.x | `composer -V` |
| Node.js | 18+ | `node -v` |
| npm | 9+ | `npm -v` |

> **Windows**: Disarankan pakai [Laragon](https://laragon.org/) — sudah include PHP 8.3, Composer, dan Node.js dalam satu installer.
>
> **Linux/Mac**: Gunakan `brew` (macOS) atau `apt`/`dnf` (Linux).

---

## Setup Lokal

### 1. Clone repository

```bash
git clone https://github.com/rahmadsetiya/fasih-sm-dashboard-analytics-SE.git
cd fasih-sm-dashboard-analytics-SE
```

### 2. Install dependencies

```bash
composer install
npm install
```

### 3. Konfigurasi environment

```bash
cp .env.example .env
php artisan key:generate
```

Buka `.env` — untuk dev lokal nilai defaultnya sudah cukup:

```env
APP_NAME="FASIH Dashboard"
APP_URL=http://localhost:8000
DB_CONNECTION=sqlite
```

### 4. Setup database

```bash
php artisan migrate
```

Ini membuat `database/database.sqlite` untuk data user, session, cache, dan queue.

> File `storage/app/fasih.db` (data sensus) **tidak dibuat otomatis** — diupload via UI setelah app berjalan.

### 5. Buat user pertama

```bash
php artisan tinker
```

```php
$user = \App\Models\User::create([
    'name'     => 'Admin',
    'email'    => 'email@example.com',
    'password' => bcrypt('passwordmu'),
]);
$user->update(['is_admin' => true]);
exit
```

### 6. Jalankan dev server

```bash
composer run dev
```

Perintah ini menjalankan tiga proses sekaligus:

- `php artisan serve` — Laravel backend (port 8000)
- `php artisan queue:listen` — queue worker untuk background jobs
- `npm run dev` — Vite HMR (hot reload frontend)

Buka browser ke **http://localhost:8000**.

### 7. Upload fasih.db

Setelah login, klik tombol **Import Database** di sidebar dan upload file `fasih.db`. Lihat bagian [Generate fasih.db](#generate-fasihdb-scraper) untuk cara mendapatkan file ini.

---

## Generate fasih.db (Scraper)

`fasih.db` adalah database SQLite berisi data progres lapangan hasil scraping dari platform FASIH. File ini **tidak tersimpan di repo ini** — harus di-generate menggunakan scraper terpisah.

**Repo scraper:** [https://github.com/rahmadsetiya/fasih-scraper](https://github.com/rahmadsetiya/fasih-scraper)

> Repo scraper bersifat **private**. Hubungi pemilik untuk meminta akses:
> - GitHub: [@rahmadsetiya](https://github.com/rahmadsetiya)
> - Email: rahmadsetiyabudi@gmail.com

Setelah scraper dijalankan sesuai instruksinya, output-nya adalah file `fasih.db` yang langsung bisa diupload ke dashboard via tombol **Import Database**.

---

## Deploy ke Shared Hosting (cPanel)

### Gambaran Struktur Direktori Server

```
~/
├── public_html/
│   └── <domain>/          <- web root (subdomain/domain yang kamu daftarkan)
│       ├── index.php       <- dari deploy/public_html.index.php
│       ├── .htaccess       <- dari public/.htaccess
│       └── build/          <- symlink ke $APPDIR/public/build
└── <app-dir>/
    └── <domain>/           <- seluruh app Laravel (di luar web root!)
        ├── app/
        ├── config/
        ├── database/
        ├── public/build/
        ├── storage/
        ├── vendor/
        ├── .env
        └── ...
```

> Laravel ditaruh di luar `public_html/` agar `app/`, `config/`, `.env`, dan file sensitif tidak bisa diakses langsung dari browser.

---

### Langkah Deploy

#### 0. Tentukan path server kamu

Di cPanel Terminal, definisikan dua variabel ini sesuai setup servermu. Semua perintah di bawah menggunakan variabel ini.

```bash
# Ganti sesuai domain dan nama folder yang kamu pakai di server
export DOMAIN="namadomain.com"           # contoh: dashboard.kantorku.net
export APPDIR="$HOME/laravel/$DOMAIN"    # lokasi app Laravel (di luar public_html)
export PUBDIR="$HOME/public_html/$DOMAIN" # web root domain
export PHP="/usr/local/bin/ea-php83"     # path PHP 8.3 di cPanel (biasanya ini)
```

> Folder `$APPDIR` bisa di mana saja asal di luar `public_html/`. Nama foldernya bebas — `laravel/`, `apps/`, `private/`, dll.

---

#### A. Persiapan di Lokal

**1. Build frontend:**

```bash
npm run build
```

Menghasilkan file statis di `public/build/` yang siap production.

**2. Pastikan `vendor/` ada** (dari `composer install`). Jika tidak mau upload `vendor/`, install ulang di server (lihat langkah B, poin 2).

---

#### B. Upload ke Server

Ada dua metode:

**Metode 1 — Git Deploy Otomatis (Direkomendasikan)**

Jika sudah dikonfigurasi Git Version Control di cPanel, deploy berjalan otomatis setiap `git push`. Sesuaikan dulu path di `.cpanel.yml` (baris `export APPDIR` dan `export PUBDIR`) dengan path servermu, lalu:

```bash
git push origin main
```

Setelah push, login ke cPanel → **Git Version Control** → klik **Update** (atau tunggu webhook trigger).

**Metode 2 — Upload Manual**

Upload file berikut via cPanel File Manager atau FTP/SFTP:

| Sumber (lokal) | Tujuan (server) |
|---|---|
| Seluruh project (kecuali `node_modules/`, `.git/`) | `$APPDIR/` |
| `deploy/public_html.index.php` | `$PUBDIR/index.php` |
| `public/.htaccess` | `$PUBDIR/.htaccess` |
| `public/build/` | `$PUBDIR/build/` |
| `public/*.jpg`, `public/*.png`, `public/favicon*` | `$PUBDIR/` |

---

#### C. Konfigurasi Server (via cPanel Terminal)

Masuk ke **cPanel → Terminal**. Pastikan variabel di [Langkah 0](#0-tentukan-path-server-kamu) sudah di-set, lalu jalankan:

**1. Pindah ke direktori app:**

```bash
cd "$APPDIR"
```

**2. Install Composer dependencies (jika `vendor/` belum ada):**

```bash
$PHP ~/bin/composer install \
  --no-dev --optimize-autoloader --no-interaction \
  --ignore-platform-req=ext-fileinfo
```

**3. Buat dan isi file `.env`:**

```bash
cp deploy/env.production.example .env
```

Edit `.env` — sesuaikan minimal dua nilai ini:

```env
APP_NAME="FASIH Dashboard"
APP_ENV=production
APP_KEY=                    # dikosongkan dulu, akan diisi oleh key:generate
APP_DEBUG=false
APP_URL=https://<domain-kamu>

APP_LOCALE=id
APP_FALLBACK_LOCALE=id

DB_CONNECTION=sqlite

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

LOG_CHANNEL=single
LOG_LEVEL=error
```

**4. Generate APP_KEY:**

```bash
$PHP artisan key:generate
```

**5. Jalankan migrasi:**

```bash
$PHP artisan migrate --force
```

**6. Cache konfigurasi (wajib di production):**

```bash
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
```

**7. Set permissions:**

```bash
chmod -R 775 storage bootstrap/cache
```

**8. Symlink `public/build` ke web root:**

```bash
ln -sfn "$APPDIR/public/build" "$PUBDIR/build"
```

---

## Setup Pertama Kali di Server

### Buat user admin pertama

Jalankan via cPanel Terminal (variabel `$APPDIR` dan `$PHP` dari [Langkah 0](#0-tentukan-path-server-kamu)):

```bash
cd "$APPDIR"
$PHP artisan tinker
```

```php
$user = \App\Models\User::create([
    'name'     => 'Admin',
    'email'    => 'email@example.com',
    'password' => bcrypt('passwordmu'),
]);
$user->update(['is_admin' => true]);
exit
```

Setelah login sebagai admin, gunakan menu **Admin → Manajemen User** di sidebar untuk menambah/mengedit/menghapus user selanjutnya — tanpa perlu tinker lagi.

---

## Manajemen Data

### Import fasih.db

Setelah login:

1. Klik tombol **Import Database** di sidebar kiri
2. Pilih file `fasih.db`
3. Klik Upload — proses berjalan sebagai background job via queue

> File disimpan ke `storage/app/fasih.db`.
>
> Import ulang **tidak menghapus** data user/session — `database/database.sqlite` terpisah dari `fasih.db`.

### Queue Worker di Production

Di shared hosting, queue tidak berjalan sebagai daemon. Untuk memproses job import database, setup **Cron Job** di cPanel (ganti path sesuai servermu):

```
* * * * * /usr/local/bin/ea-php83 /home/<cpanel-user>/<app-dir>/<domain>/artisan queue:work --once --tries=1 2>/dev/null
```

Atau jalankan manual setelah upload (dari cPanel Terminal):

```bash
$PHP artisan queue:work --once
```

### Manajemen Nama Wilayah

Nama wilayah disimpan di tabel `region_names` (`database.sqlite`). Bisa diimport via file CSV di halaman Admin → Settings.

### Manajemen Nama Petugas

Nama tampilan petugas bisa diset di Admin → Nama Petugas, menggunakan username FASIH sebagai key.

---

## Halaman & Fitur

| URL | Nama | Keterangan |
|---|---|---|
| `/` | Dashboard | Filter snapshot, role, level wilayah, basis prelist; metric cards, gap prelist, chart status, ranking top wilayah, trend, dan tabel rincian |
| `/ringkasan` | Ringkasan Kabupaten | Rekap kabupaten dengan basis prelist, gap coverage, komposisi status, dan trend |
| `/proyeksi` | Proyeksi Petugas | Target submit harian per PPL/PML, status Aman/Berisiko/Belum Bergerak, modal detail, dan export Excel |
| `/heatmap` | Heatmap Aktivitas | Aktivitas per petugas per hari; drill-down per jam |
| `/petugas` | Analitik Petugas | 6 tab analitik (lihat di bawah) |
| `/penugasan` | Daftar Penugasan | Tabel semua assignment + riwayat perubahan status |
| `/statistik` | Statistik | Uji proporsi, komparasi, chi-square, korelasi, bangunan kosong |
| `/admin/users` | Manajemen User | CRUD user (admin only) |

### Tab di Halaman Analitik Petugas (`/petugas`)

| Tab | Isi |
|---|---|
| Daftar | Tabel seluruh petugas dengan jumlah assignment dan ringkasan status |
| Funnel Status | Funnel chart: OPEN → SUBMITTED → APPROVED |
| Matrix | Scatter plot: kecepatan penyelesaian (hari) vs rejection rate (% ditolak pengawas) |
| Leaderboard | Ranking petugas berdasarkan approval rate, dengan pagination |
| Mangkrak | Assignment yang sudah lama tidak ada pergerakan status |
| Proyeksi | Estimasi tanggal selesai berdasarkan laju saat ini (deadline: 31 Agustus 2026) |

---

### Catatan Perilaku Dashboard (`/`)

- Grafik **Top wilayah** memakai tampilan bar chart penuh di desktop dan tampilan ranking card yang lebih ringkas di mobile agar label wilayah tetap terbaca.
- Grafik **Tren Submit Over Time** hanya memakai **snapshot terakhir pada tiap tanggal** agar satu hari tidak muncul berkali-kali.
- Rentang tren memakai **7 titik aktual terakhir** dan maksimal **3 titik proyeksi** ke depan.
- Toggle **Basis Prelist** menentukan denominator `Total Assignment`: `Dinamis` memakai `SUM(region_total)` dari tabel status scrape `progress_pengawas/progress_pencacah` pada snapshot aktif, sedangkan `Awal` memakai tabel app `initial_prelists` yang otomatis diisi dari fixture `database/data/initial_prelists.json` saat migrasi.
- Card **Gap Prelist** menampilkan total dinamis dari status scrape, total awal, selisih, dan mismatch Sub-SLS agar perbedaan target tidak tersembunyi.
- Metrik **% Submit** memakai rumus `jumlah aktual semua status selain OPEN dan DRAFT / Total Assignment * 100`, dengan `Total Assignment` mengikuti basis prelist aktif.
- Filter Desa dan SLS memakai kode komposit parent-child (`kdkec-kddes` dan `kdkec-kddes-kdsls`) agar pilihan pada beberapa kecamatan tidak saling bercampur ketika kode lokal sama.
- Tabel rincian dapat diekspor ke Excel sesuai level, filter, pencarian, sorting, dan kolom status aktif yang sedang tampil.
- Tabel rincian per wilayah/petugas memiliki kolom **Progres Lapangan** dengan rumus:

  `Total Assignment - OPEN`

- Nilai **Progres Lapangan** ditampilkan sebagai total kasus dan persentasenya terhadap `Total Assignment`.

### Prelist Awal

Prelist awal standar sudah dibundel sebagai fixture JSON di `database/data/initial_prelists.json` dan otomatis dimuat ke tabel `initial_prelists` saat `php artisan migrate --force`. File workbook Master SE2026 dan `database.sqlite` tetap tidak perlu di-commit.

Jika server sudah pernah menjalankan migrasi sebelum fixture ini tersedia dan dashboard masih menampilkan warning prelist awal belum tersedia, jalankan seed fixture bawaan:

```bash
php artisan prelist:seed-awal
```

Jika suatu saat prelist awal diganti dari workbook baru, simpan workbook Master SE2026 di lokasi aman lalu jalankan:

```bash
php artisan prelist:import-awal "C:\path\Master SE2026 7316.xlsx" --sheet="Rekap Prelist"
```

Command membaca sheet yang namanya mengandung `Rekap Prelist`, mengambil `IDSUBSLS_25_2` dari kolom `D` dan `TOTAL ASSIGNMENT FASIH` dari kolom `AD`, lalu melakukan upsert ke tabel `initial_prelists`.

### Catatan Proyeksi Petugas (`/proyeksi`)

- Deadline default adalah **31 Agustus 2026** dan bisa diubah dari filter tanggal.
- Sumber histori memakai snapshot harian terakhir dari `progress_pencacah` atau `progress_pengawas`.
- Submit dihitung sebagai seluruh status selain `OPEN` dan `DRAFT`, termasuk status Admin Kabupaten.
- Target submit/hari memakai rumus `CEIL(Sisa Assignment / Hari Tersisa Inklusif)`.
- Badge proyeksi: `Aman`, `Berisiko`, `Belum Bergerak`, dan `Selesai`.
- Reject tetap dihitung sebagai progress, tetapi menjadi penimbang kualitas melalui `Reject %`, `Laju Efektif/Hari`, dan badge `Reject Rendah/Perlu Pantau/Reject Tinggi`.

---

## Versioning & Changelog

- Versi aktif aplikasi diatur melalui `APP_VERSION` atau nilai default pada `config/app.php`.
- Riwayat rilis dikelola terpusat di `config/releases.php` dan ditampilkan melalui modal Changelog di sidebar.
- Modal changelog otomatis muncul sekali saat user pertama membuka aplikasi pada versi aktif. Jika user memilih `Jangan tampilkan lagi untuk versi ini`, preferensi disimpan di browser melalui `localStorage` dengan key per versi.
- Ringkasan perubahan untuk developer tetap dicatat di `CHANGELOG.md`.

Saat merilis versi baru, naikkan `APP_VERSION`, tambahkan entri terbaru di urutan pertama `config/releases.php`, lalu perbarui `CHANGELOG.md`.

### Format GeoJSON Wilayah

Peta wilayah membaca `storage/app/final_sls_202517316.geojson`. File harus berupa `FeatureCollection` dengan geometri `Polygon` atau `MultiPolygon` dalam CRS84. Properti join wajib adalah `idsubsls`; properti hierarki yang digunakan adalah `idkec`, `iddesa`, dan `idsls`.

Saat endpoint boundaries pertama kali dipanggil, aplikasi membuat artifact tersanitasi di `storage/app/private/geo/boundaries.json`. Hanya kode, nama wilayah, luas, periode, dan geometri yang diteruskan ke browser. Path sumber dan atribut internal lainnya dibuang.

---

## Struktur Database

### `database/database.sqlite` — koneksi default

Dibuat otomatis oleh `php artisan migrate`.

| Tabel | Isi |
|---|---|
| `users` | Akun login dashboard |
| `petugas_names` | Nama tampilan petugas (key: username FASIH) |
| `region_names` | Nama wilayah (key: region_code) |
| `jobs` | Queue jobs |
| `sessions`, `cache` | Session dan cache aplikasi |

### `storage/app/fasih.db` — koneksi `fasih` (read-only)

Diupload via UI. **Tidak ada saat fresh install.**

| Tabel | Isi |
|---|---|
| `progress_pengawas` | Progres per pengawas per snapshot waktu |
| `progress_pencacah` | Progres per pencacah per snapshot waktu |
| `assignments` | Daftar semua penugasan |
| `assignment_status_changes` | Riwayat perubahan status per penugasan |
| `scrape_runs` | Metadata tiap kali scraper dijalankan |
| `wilayah` | Hierarki wilayah (kab/kec/desa/SLS) |
| `users` | User FASIH (bukan user login dashboard) |

Kolom `snapshot_at` di `progress_pengawas` dan `progress_pencacah` membedakan data antar titik waktu scraping dalam satu file. Pada halaman dashboard, data tren harian diringkas memakai `snapshot_at` paling akhir untuk setiap tanggal.

---

## Perintah yang Sering Digunakan

```bash
# Dev server (Laravel + Queue + Vite HMR sekaligus)
composer run dev

# Jalankan semua test
composer run test

# Fix PHP code style
composer run lint

# Full CI check (lint + types + phpunit)
composer run ci:check

# Frontend checks
npm run lint             # ESLint auto-fix
npm run format           # Prettier write
npm run types:check      # TypeScript type-check
npm run build            # Build production frontend
```

---

## File Penting

| File/Folder | Keterangan |
|---|---|
| `.cpanel.yml` | Skrip deploy otomatis untuk cPanel Git Version Control |
| `deploy/public_html.index.php` | Entry point `public_html/` untuk shared hosting |
| `deploy/env.production.example` | Template `.env` untuk production |
| `storage/app/fasih.db` | Data sensus dari scraper (diupload user, **tidak di-commit**) |
| `database/database.sqlite` | App database (user, session, cache) — tidak di-commit |

### File auto-generated — jangan edit manual

| Path | Di-generate oleh |
|---|---|
| `resources/js/actions/**` | `php artisan wayfinder:generate` |
| `resources/js/routes/**` | `php artisan wayfinder:generate` |
| `resources/js/components/ui/*` | shadcn/reka-ui CLI |

---

## Troubleshooting

**`storage/app/fasih.db` tidak ditemukan setelah upload**

Queue worker belum berjalan. Jalankan manual:

```bash
/usr/local/bin/ea-php83 artisan queue:work --once
```

**500 error di production**

1. Cek `storage/logs/laravel.log`
2. Sementara set `APP_DEBUG=true` di `.env` untuk lihat pesan error lengkap
3. Pastikan `storage/` dan `bootstrap/cache/` writable: `chmod -R 775 storage bootstrap/cache`
4. Pastikan `APP_KEY` sudah diisi (jalankan `artisan key:generate`)

**Asset tidak ditemukan (404 pada `/build/...`)**

Symlink `public/build` → web root belum dibuat. Jalankan (variabel dari [Langkah 0](#0-tentukan-path-server-kamu)):

```bash
ln -sfn "$APPDIR/public/build" "$PUBDIR/build"
```

**Route tidak ditemukan setelah deploy**

Cache route lama masih aktif. Jalankan:

```bash
/usr/local/bin/ea-php83 artisan route:cache
```

**Queue stuck / import tidak selesai**

Di shared hosting tanpa daemon queue, jalankan manual:

```bash
$PHP artisan queue:work --once --tries=1
```

**cPanel Terminal PHP salah versi**

cPanel Terminal default ke PHP sistem (bisa beda versi). Selalu gunakan path eksplisit atau via variabel `$PHP` dari [Langkah 0](#0-tentukan-path-server-kamu):

```bash
/usr/local/bin/ea-php83 artisan ...
```

---

*Made with ♥ @ IPDS BPS Enrekang*
