<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HeatmapController extends Controller
{
    private const VALID_STATUS = [1, 2, 3];

    private const VALID_DIMENSION = ['pencacah', 'pengawas'];

    private const GEO_COL_MAP = [
        'kec' => 'c.kdkec',
        'desa' => 'c.kddes',
        'sls' => 'c.kdsls',
        'subsls' => 'c.kdsubsls',
    ];

    public function index(): Response
    {
        $dbPath = config('database.connections.fasih.database');

        $dateRange = null;
        $wilayah = [];

        if (file_exists($dbPath)) {
            $range = DB::connection('fasih')
                ->table('assignment_status_changes')
                ->selectRaw('MIN(DATE(change_date)) as min_date, MAX(DATE(change_date)) as max_date')
                ->first();

            if ($range && $range->min_date) {
                $dateRange = ['min' => $range->min_date, 'max' => $range->max_date];
            }

            $wilayah = DB::connection('fasih')
                ->table('wilayah')
                ->whereIn('level', [3, 4, 5, 6])
                ->select('uuid', 'level', 'full_code as code', 'name', 'parent_uuid')
                ->orderBy('level')
                ->orderBy('name')
                ->get()
                ->groupBy('level')
                ->map(fn ($rows) => $rows->values()->all())
                ->toArray();
        }

        return Inertia::render('Heatmap', [
            'db_ready' => file_exists($dbPath),
            'date_range' => $dateRange,
            'wilayah' => $wilayah,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['series' => [], 'days' => []]);
        }

        $statusId = (int) $request->input('status_id', 1);
        if (! in_array($statusId, self::VALID_STATUS)) {
            $statusId = 1;
        }

        $dimension = $request->input('dimension', 'pencacah');
        if (! in_array($dimension, self::VALID_DIMENSION)) {
            $dimension = 'pencacah';
        }

        $dateFrom = $request->input('date_from') ?: null;
        $dateTo = $request->input('date_to') ?: null;
        $filterLevel = $request->input('filter_level') ?: null;
        $filterCode = $request->input('filter_code') ?: null;

        $base = DB::connection('fasih')
            ->table('assignment_status_changes as c')
            ->where('c.to_status_id', $statusId);

        if ($dateFrom) {
            $base->whereRaw('DATE(c.change_date) >= ?', [$dateFrom]);
        }
        if ($dateTo) {
            $base->whereRaw('DATE(c.change_date) <= ?', [$dateTo]);
        }
        if ($filterLevel && $filterCode && isset(self::GEO_COL_MAP[$filterLevel])) {
            $base->where(self::GEO_COL_MAP[$filterLevel], $filterCode);
        }

        $rows = match ($dimension) {
            'pengawas' => (clone $base)
                ->leftJoin('users as u', 'u.user_id', '=', 'c.pengawas_user_id')
                ->whereNotNull('c.pengawas_user_id')
                ->selectRaw("DATE(c.change_date) as day, hex(c.pengawas_user_id) as dim_key, COALESCE(NULLIF(u.fullname,''), u.email, hex(c.pengawas_user_id)) as dim_label, COUNT(*) as cnt")
                ->groupByRaw('day, dim_key, dim_label')
                ->orderBy('day')
                ->get(),

            default => /* pencacah */ (clone $base)
                ->leftJoin('users as u', 'u.user_id', '=', 'c.pencacah_user_id')
                ->whereNotNull('c.pencacah_user_id')
                ->selectRaw("DATE(c.change_date) as day, hex(c.pencacah_user_id) as dim_key, COALESCE(NULLIF(u.fullname,''), u.email, hex(c.pencacah_user_id)) as dim_label, COUNT(*) as cnt")
                ->groupByRaw('day, dim_key, dim_label')
                ->orderBy('day')
                ->get(),
        };

        return response()->json($this->buildSeries($rows, $dateFrom, $dateTo));
    }

    public function hourly(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['data' => []]);
        }

        $statusId = (int) $request->input('status_id', 0);
        $dimension = in_array($request->input('dimension', 'pencacah'), self::VALID_DIMENSION)
            ? $request->input('dimension', 'pencacah') : 'pencacah';

        $query = DB::connection('fasih')->table('assignment_status_changes as c');

        if ($statusId > 0 && in_array($statusId, self::VALID_STATUS)) {
            $query->where('c.to_status_id', $statusId);
        }

        if ($dimension === 'pengawas') {
            $query->whereNotNull('c.pengawas_user_id');
        } else {
            $query->whereNotNull('c.pencacah_user_id');
        }

        $rows = $query
            ->selectRaw("CAST(strftime('%H', change_date) AS INTEGER) as hour, COUNT(*) as cnt")
            ->groupByRaw("strftime('%H', change_date)")
            ->orderBy('hour')
            ->get();

        $byHour = collect($rows)->keyBy('hour');
        $data = [];
        for ($h = 0; $h < 24; $h++) {
            $data[] = ['hour' => $h, 'cnt' => (int) ($byHour[$h]->cnt ?? 0)];
        }

        return response()->json(['data' => $data]);
    }

    private function buildSeries($rows, ?string $dateFrom, ?string $dateTo): array
    {
        if ($rows->isEmpty()) {
            return ['series' => [], 'days' => []];
        }

        $daySet = [];
        $byDim = [];

        foreach ($rows as $row) {
            if (! $row->day) {
                continue;
            }
            $daySet[$row->day] = true;
            $key = $row->dim_key ?? 'unknown';

            if (! isset($byDim[$key])) {
                $byDim[$key] = [
                    'name' => $row->dim_label ?: $key,
                    'counts' => [],
                    'total' => 0,
                ];
            }
            $byDim[$key]['counts'][$row->day] = (int) $row->cnt;
            $byDim[$key]['total'] += (int) $row->cnt;
        }

        if (empty($daySet)) {
            return ['series' => [], 'days' => []];
        }

        $minDay = $dateFrom ?? min(array_keys($daySet));
        $maxDay = $dateTo ?? max(array_keys($daySet));
        $days = $this->generateDateRange($minDay, $maxDay);

        uasort($byDim, fn ($a, $b) => $b['total'] <=> $a['total']);
        $byDim = array_slice($byDim, 0, 30, true);

        $series = [];
        foreach ($byDim as $key => $dim) {
            $data = [];
            foreach ($days as $day) {
                $data[] = ['x' => $day, 'y' => $dim['counts'][$day] ?? 0];
            }
            $series[] = ['name' => $dim['name'], 'data' => $data, 'total' => $dim['total']];
        }

        return ['series' => $series, 'days' => $days];
    }

    private function generateDateRange(string $from, string $to): array
    {
        $days = [];
        $current = new DateTime($from);
        $end = new DateTime($to);

        while ($current <= $end) {
            $days[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }

        return $days;
    }
}
