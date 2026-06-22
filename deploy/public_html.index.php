<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// ~/public_html/dashboard-se.enrekang.stat7300.net/index.php
// dirname(__DIR__)         = ~/public_html/
// dirname(dirname(__DIR__)) = ~/
$appDir = dirname(dirname(__DIR__)).'/APP_FOLDER/dashboard-se.enrekang.stat7300.net';

if (file_exists($maintenance = $appDir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appDir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appDir.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
