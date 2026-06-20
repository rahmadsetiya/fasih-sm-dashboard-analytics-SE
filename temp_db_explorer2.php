<?php

use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Query 1: Aggregate totals from progress_pengawas
echo "===== QUERY 1: AGGREGATE TOTALS progress_pengawas =====\n";
$aggregate1 = DB::connection('fasih')->table('progress_pengawas')->selectRaw('
  SUM(region_total) as total_regions,
  SUM("OPEN") as total_open,
  SUM("DRAFT") as total_draft,
  SUM("SUBMITTED BY Pencacah") as total_submitted_pencacah,
  SUM("APPROVED BY Pengawas") as total_approved,
  SUM("REJECTED BY Pengawas") as total_rejected,
  SUM("EDITED BY Pengawas") as total_edited,
  SUM("REVOKED BY Pengawas") as total_revoked,
  SUM("SUBMITTED RESPONDENT") as total_submitted_respondent
')->first();
echo json_encode($aggregate1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 2: Aggregate totals from progress_pencacah
echo "===== QUERY 2: AGGREGATE TOTALS progress_pencacah =====\n";
$aggregate2 = DB::connection('fasih')->table('progress_pencacah')->selectRaw('
  SUM(region_total) as total_regions,
  SUM("OPEN") as total_open,
  SUM("DRAFT") as total_draft,
  SUM("SUBMITTED BY Pencacah") as total_submitted_pencacah,
  SUM("APPROVED BY Pengawas") as total_approved,
  SUM("REJECTED BY Pengawas") as total_rejected,
  SUM("EDITED BY Pengawas") as total_edited,
  SUM("REVOKED BY Pengawas") as total_revoked,
  SUM("SUBMITTED RESPONDENT") as total_submitted_respondent
')->first();
echo json_encode($aggregate2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 3: Distinct role names in pencacah
echo "===== QUERY 3: DISTINCT role_name progress_pencacah =====\n";
$roles2 = DB::connection('fasih')->table('progress_pencacah')->selectRaw('DISTINCT role_name')->pluck('role_name')->toArray();
echo json_encode($roles2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 4: Distinct snapshot_at in progress_pencacah
echo "===== QUERY 4: DISTINCT snapshot_at progress_pencacah =====\n";
$snapshots2 = DB::connection('fasih')->table('progress_pencacah')->selectRaw('DISTINCT snapshot_at')->pluck('snapshot_at')->toArray();
echo json_encode($snapshots2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 5: Count distinct users per role
echo "===== QUERY 5: DISTINCT USERS COUNT per Role =====\n";
$usersByRole1 = DB::connection('fasih')->table('progress_pengawas')
    ->selectRaw('role_name, COUNT(DISTINCT user_id) as user_count')
    ->groupBy('role_name')
    ->get()
    ->toArray();
echo json_encode($usersByRole1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 6: Data distribution across snapshots
echo "===== QUERY 6: ROW COUNT per snapshot_at progress_pengawas =====\n";
$distBySnapshot1 = DB::connection('fasih')->table('progress_pengawas')
    ->selectRaw('snapshot_at, COUNT(*) as row_count')
    ->groupBy('snapshot_at')
    ->get()
    ->toArray();
echo json_encode($distBySnapshot1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 7: Data distribution across snapshots for pencacah
echo "===== QUERY 7: ROW COUNT per snapshot_at progress_pencacah =====\n";
$distBySnapshot2 = DB::connection('fasih')->table('progress_pencacah')
    ->selectRaw('snapshot_at, COUNT(*) as row_count')
    ->groupBy('snapshot_at')
    ->get()
    ->toArray();
echo json_encode($distBySnapshot2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 8: Check unique survey_period_ids
echo "===== QUERY 8: DISTINCT survey_period_id count =====\n";
$periodCount1 = DB::connection('fasih')->table('progress_pengawas')->selectRaw('COUNT(DISTINCT survey_period_id) as cnt')->first();
echo json_encode($periodCount1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 9: Get sample of region_total distribution
echo "===== QUERY 9: REGION_TOTAL STATISTICS =====\n";
$regionStats = DB::connection('fasih')->table('progress_pengawas')->selectRaw('
  MIN(region_total) as min_region_total,
  MAX(region_total) as max_region_total,
  AVG(region_total) as avg_region_total,
  COUNT(DISTINCT region_total) as distinct_totals
')->first();
echo json_encode($regionStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Query 10: Check if data is per-region-per-user or per-region
echo "===== QUERY 10: sample data - check data granularity =====\n";
$sample = DB::connection('fasih')->table('progress_pengawas')
    ->select('snapshot_at', 'user_id', 'username', 'region_code', 'region_total', 'OPEN', 'user_total')
    ->limit(5)
    ->get()
    ->toArray();
echo json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";
