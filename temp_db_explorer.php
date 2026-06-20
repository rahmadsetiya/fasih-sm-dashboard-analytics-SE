<?php

use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Query 1: List all tables
echo "===== QUERY 1: ALL TABLES =====\n";
$tables = DB::connection('fasih')->select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
echo json_encode($tables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 2: Schema for progress_pengawas
echo "===== QUERY 2: SCHEMA progress_pengawas =====\n";
$schema = DB::connection('fasih')->select('PRAGMA table_info(progress_pengawas)');
echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 3: Schema for progress_pencacah
echo "===== QUERY 3: SCHEMA progress_pencacah =====\n";
$schema2 = DB::connection('fasih')->select('PRAGMA table_info(progress_pencacah)');
echo json_encode($schema2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 4: Sample data from progress_pengawas
echo "===== QUERY 4: SAMPLE DATA progress_pengawas =====\n";
$data1 = DB::connection('fasih')->table('progress_pengawas')->limit(3)->get()->toArray();
echo json_encode($data1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 5: Sample data from progress_pencacah
echo "===== QUERY 5: SAMPLE DATA progress_pencacah =====\n";
$data2 = DB::connection('fasih')->table('progress_pencacah')->limit(3)->get()->toArray();
echo json_encode($data2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 6: Distinct snapshot_at values
echo "===== QUERY 6: DISTINCT snapshot_at (progress_pengawas) =====\n";
$snapshots = DB::connection('fasih')->table('progress_pengawas')->selectRaw('DISTINCT snapshot_at')->pluck('snapshot_at')->toArray();
echo json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 7: Distinct role_name values
echo "===== QUERY 7: DISTINCT role_name (progress_pengawas) =====\n";
$roles = DB::connection('fasih')->table('progress_pengawas')->selectRaw('DISTINCT role_name')->pluck('role_name')->toArray();
echo json_encode($roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 8: Region code analysis
echo "===== QUERY 8: REGION CODE ANALYSIS =====\n";
$regionAnalysis = DB::connection('fasih')->table('progress_pengawas')->selectRaw('MIN(LENGTH(region_code)) as min_len, MAX(LENGTH(region_code)) as max_len, COUNT(DISTINCT region_code) as total_regions, COUNT(*) as total_rows')->first();
echo json_encode($regionAnalysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 9: Sample region codes
echo "===== QUERY 9: SAMPLE REGION CODES =====\n";
$regionCodes = DB::connection('fasih')->table('progress_pengawas')->selectRaw('DISTINCT region_code')->limit(10)->pluck('region_code')->toArray();
echo json_encode($regionCodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 10: Distinct usernames count
echo "===== QUERY 10: DISTINCT USERNAMES COUNT =====\n";
$usernamesCount = DB::connection('fasih')->table('progress_pengawas')->selectRaw('COUNT(DISTINCT username) as cnt')->first();
echo json_encode($usernamesCount, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 11: Check progress_pencacah columns
echo "===== QUERY 11: Column names in progress_pencacah =====\n";
$colResult = DB::connection('fasih')->select('PRAGMA table_info(progress_pencacah)');
$colNames = array_column($colResult, 'name');
echo json_encode($colNames, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 12: Count rows in each table
echo "===== QUERY 12: ROW COUNTS =====\n";
$rowCounts = [];
$rowCounts['progress_pengawas'] = DB::connection('fasih')->table('progress_pengawas')->count();
$rowCounts['progress_pencacah'] = DB::connection('fasih')->table('progress_pencacah')->count();
echo json_encode($rowCounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 13: Get all column names for both tables
echo "===== QUERY 13: ALL COLUMNS progress_pengawas =====\n";
$allCols1 = array_column(DB::connection('fasih')->select('PRAGMA table_info(progress_pengawas)'), 'name');
echo json_encode($allCols1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

echo "===== QUERY 14: ALL COLUMNS progress_pencacah =====\n";
$allCols2 = array_column(DB::connection('fasih')->select('PRAGMA table_info(progress_pencacah)'), 'name');
echo json_encode($allCols2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";
