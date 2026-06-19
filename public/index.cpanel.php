<?php

/**
 * cPanel shared hosting entry point.
 *
 * Deploy steps:
 *   1. Upload seluruh folder Laravel ke ~/APP_FOLDER  (mis. ~/fasih-dashboard)
 *   2. Upload isi folder public/ ke ~/public_html/
 *   3. Rename file ini menjadi index.php di public_html/
 *   4. Ganti APP_FOLDER di bawah dengan nama folder aktual kamu
 *   5. Jalankan: composer install --no-dev di APP_FOLDER via SSH / cPanel Terminal
 *   6. Salin .env.example ke .env, isi APP_KEY, APP_URL, dan path fasih.db
 *   7. php artisan migrate --force
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Ganti 'APP_FOLDER' dengan nama folder aplikasi Laravel kamu
// Contoh: jika folder ada di /home/username/fasih-dashboard, tulis 'fasih-dashboard'
define('CPANEL_APP_FOLDER', 'APP_FOLDER');

$appBase = dirname(__DIR__) . '/' . CPANEL_APP_FOLDER;

if (file_exists($maintenance = $appBase . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appBase . '/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appBase . '/bootstrap/app.php';

$app->handleRequest(Request::capture());
