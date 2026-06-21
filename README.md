# Dashboard SE Enrekang — Monitoring SE2026

Dashboard monitoring FASIH (Sensus/Survey) berbasis web untuk Kabupaten Enrekang.

## Tech Stack

- **Backend**: Laravel 13 (PHP 8.3+)
- **Frontend**: Vue 3 + Inertia.js + TypeScript + Tailwind CSS 4 + Vite
- **UI**: Reka UI (shadcn-style), Charts via `vue3-apexcharts`
- **Auth**: Laravel Fortify + Passkeys

---

## Pengembangan Lokal

### Prasyarat

- PHP 8.3+
- Composer
- Node.js + npm

### Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Jalankan dev server

```bash
composer run dev
```

Menjalankan `artisan serve` + `queue:listen` + Vite HMR secara bersamaan.

### Perintah lainnya

```bash
composer run test        # config:clear → pint → tsc → phpunit
composer run lint        # perbaiki code style PHP
composer run ci:check    # full CI pipeline

npm run lint             # ESLint auto-fix
npm run format           # Prettier write
npm run types:check      # TypeScript type-check
```

---

## Database

Dua koneksi SQLite:

| Koneksi | File | Isi |
|---|---|---|
| `sqlite` (default) | `database/database.sqlite` | App data: user, session, cache, queue |
| `fasih` (read-only) | `storage/app/fasih.db` | Data sensus — diupload user via UI |

`fasih.db` **tidak ada saat fresh install** — selalu cek `file_exists()` sebelum query.

Tabel `progress_pengawas` dan `progress_pencacah` pakai kolom `snapshot_at` untuk membedakan snapshot per waktu dalam satu file.

---

## Deploy ke Shared Hosting (cPanel)

### Struktur direktori server

```
~/
├── public_html/
│   └── dashboard-se.enrekang.stat7300.net/   <- web root
│       ├── index.php                          <- dari deploy/public_html.index.php
│       ├── .htaccess                          <- dari public/.htaccess
│       └── build/                             <- dari public/build/ (hasil npm run build)
└── APP_FOLDER/
    └── dashboard-se.enrekang.stat7300.net/    <- seluruh app Laravel
```

### Langkah deploy

**1. Build frontend (lokal):**

```bash
npm run build
```

**2. Upload ke server:**

- Seluruh project (kecuali `node_modules/`, `public/`, `.git/`, `deploy/`) ke `APP_FOLDER/dashboard-se.enrekang.stat7300.net/`
- `deploy/public_html.index.php` ke `public_html/dashboard-se.enrekang.stat7300.net/index.php`
- `public/.htaccess` ke `public_html/dashboard-se.enrekang.stat7300.net/.htaccess`
- `public/build/` ke `public_html/dashboard-se.enrekang.stat7300.net/build/`
- `public/logo-se2026.jpg` (dan aset publik lain) ke `public_html/dashboard-se.enrekang.stat7300.net/`

**3. Konfigurasi server (via cPanel Terminal):**

```bash
cd ~/APP_FOLDER/dashboard-se.enrekang.stat7300.net

# Salin dan isi .env
cp deploy/.env.production .env
# Edit: isi APP_KEY setelah generate

# Generate key
/usr/local/bin/ea-php83 artisan key:generate

# Migrasi database
/usr/local/bin/ea-php83 artisan migrate --force

# Optimize
/usr/local/bin/ea-php83 artisan optimize

# Permission storage
chmod -R 775 storage bootstrap/cache
```

> **Catatan PHP CLI**: cPanel Terminal pakai PHP sistem (8.2). Gunakan `/usr/local/bin/ea-php83` untuk PHP 8.3.
> Bisa set alias sekali pakai: `alias php=/usr/local/bin/ea-php83`

**4. Isi `.env` production:**

```env
APP_NAME="Dashboard SE Enrekang"
APP_ENV=production
APP_KEY=           # diisi otomatis oleh key:generate
APP_DEBUG=false
APP_URL=https://dashboard-se.enrekang.stat7300.net

DB_CONNECTION=sqlite
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
LOG_CHANNEL=single
LOG_LEVEL=error
```

---

## Setup Pertama Kali

### Buat user pertama

```bash
/usr/local/bin/ea-php83 artisan tinker
```

```php
\App\Models\User::create([
    'name' => 'Admin',
    'email' => 'email@example.com',
    'password' => bcrypt('passwordmu'),
]);
```

### Set sebagai admin (satu kali saja)

```php
\App\Models\User::where('email', 'email@example.com')->update(['is_admin' => true]);
```

Setelah login sebagai admin, gunakan menu **Manajemen User** di sidebar untuk CRUD user selanjutnya tanpa tinker.

---

## Manajemen Data

### Upload fasih.db

Gunakan tombol **Import Database** di sidebar. File disimpan ke `storage/app/fasih.db`.

> **Penting**: User login disimpan di `database/database.sqlite` (bukan `fasih.db`).
> Import ulang `fasih.db` **tidak menghapus** data user/session.

### Tabel yang harus ada di fasih.db

- `progress_pengawas`
- `progress_pencacah`
- `assignments`
- `scrape_runs`
- `wilayah`
- `assignment_status_changes`
- `users` (user FASIH — bukan user login app)

---

## Halaman

| URL | Keterangan |
|---|---|
| `/` | Dashboard utama per petugas/wilayah |
| `/ringkasan` | Ringkasan kabupaten (tabel kecamatan) |
| `/heatmap` | Heatmap aktivitas per petugas per hari |
| `/admin/users` | Manajemen user (admin only) |

---

## File Penting

| File | Keterangan |
|---|---|
| `deploy/public_html.index.php` | Entry point untuk `public_html/` di shared hosting |
| `deploy/.env.production` | Template `.env` untuk production |
| `storage/app/fasih.db` | Data sensus (diupload user, tidak di-commit) |
| `database/database.sqlite` | App database (user, session, cache) |

### File auto-generated — jangan edit manual

- `resources/js/actions/**` — Wayfinder action types
- `resources/js/routes/**` — Wayfinder route helpers
- `resources/js/components/ui/*` — shadcn/reka-ui templates
