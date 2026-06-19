<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const STATUS_COLS = [
        'OPEN',
        'DRAFT',
        'SUBMITTED BY Pencacah',
        'APPROVED BY Pengawas',
        'REJECTED BY Pengawas',
        'EDITED BY Pengawas',
        'REVOKED BY Pengawas',
        'SUBMITTED RESPONDENT',
    ];

    public function index(): Response
    {
        $dbPath = config('database.connections.fasih.database');

        $snapshots = [];
        if (file_exists($dbPath)) {
            $snapshots = DB::connection('fasih')
                ->table('progress_pengawas')
                ->selectRaw('DISTINCT snapshot_at')
                ->orderByDesc('snapshot_at')
                ->pluck('snapshot_at')
                ->all();
        }

        return Inertia::render('Dashboard', [
            'snapshots' => $snapshots,
            'db_ready'  => file_exists($dbPath),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $snapshot    = $request->input('snapshot', '');
        $role        = in_array($request->input('role'), ['pengawas', 'pencacah'])
            ? $request->input('role') : 'pengawas';
        $level       = $request->input('level', 'kec');
        $filterCodes = array_values(array_filter((array) $request->input('filter_codes', [])));
        $filterLevel = $request->input('filter_level', '') ?? '';

        $table = 'progress_' . $role;
        $base  = DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot);
        $this->applyFilter($base, $filterCodes, $filterLevel);

        return response()->json([
            'metrics'        => $this->calcMetrics(clone $base),
            'status_totals'  => $this->calcStatusTotals(clone $base),
            'breakdown'      => $this->calcBreakdown(clone $base, $level),
            'trend'          => $this->calcTrend($table, $filterCodes, $filterLevel),
            'filter_options' => $this->calcFilterOptions($table, $snapshot, $level, $filterCodes, $filterLevel),
        ]);
    }

    public function snapshots(): JsonResponse
    {
        $rows = [];
        foreach (['progress_pengawas', 'progress_pencacah'] as $t) {
            foreach (DB::connection('fasih')->table($t)
                ->selectRaw('DISTINCT snapshot_at')->orderByDesc('snapshot_at')
                ->pluck('snapshot_at') as $s) {
                $rows[$s] = $s;
            }
        }
        krsort($rows);
        return response()->json(array_values($rows));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function applyFilter($query, array $codes, ?string $level): void
    {
        if (empty($codes) || $level === '') return;
        match ($level) {
            'kec'  => $query->whereIn('kec_key', $codes),
            'desa' => $query->whereIn('desa_key', $codes),
            'sls'  => $query->whereIn('idsubsls', $codes),
            default => null,
        };
    }

    private function statusSumSql(): string
    {
        return collect(self::STATUS_COLS)
            ->map(fn ($c) => "SUM(\"$c\") as \"$c\"")
            ->implode(', ');
    }

    // ── calculators ───────────────────────────────────────────────────────

    private function calcMetrics($query): array
    {
        $row = $query->selectRaw("
            COUNT(DISTINCT username)  as total_petugas,
            COUNT(DISTINCT desa_key)  as total_desa,
            COUNT(DISTINCT kec_key)   as total_kec,
            SUM(region_total)         as total_rt,
            SUM(\"OPEN\")             as total_open,
            SUM(\"APPROVED BY Pengawas\") as total_approved,
            SUM(\"SUBMITTED BY Pencacah\") as total_submitted
        ")->first();

        $total    = (int) ($row->total_rt    ?: 1);
        $open     = (int) ($row->total_open  ?: 0);
        $approved = (int) ($row->total_approved ?: 0);
        $submitted = (int) ($row->total_submitted ?: 0);

        return [
            'total_petugas'  => (int) $row->total_petugas,
            'total_kec'      => (int) $row->total_kec,
            'total_desa'     => (int) $row->total_desa,
            'total_rt'       => $total,
            'progress_pct'   => round(($total - $open) / $total * 100, 1),
            'approved_pct'   => round($approved / $total * 100, 1),
            'submitted_pct'  => round($submitted / $total * 100, 1),
        ];
    }

    private function calcStatusTotals($query): array
    {
        $row    = $query->selectRaw($this->statusSumSql())->first();
        $result = [];
        foreach (self::STATUS_COLS as $col) {
            $result[$col] = (int) ($row->$col ?? 0);
        }
        return $result;
    }

    private function calcBreakdown($query, string $level): array
    {
        $sums = $this->statusSumSql();

        $rows = match ($level) {
            'desa' => $query->selectRaw("
                    desa_key as grp_key, nmdesa as label, nmkec,
                    SUM(region_total) as total, $sums
                ")->groupBy('desa_key', 'nmdesa', 'nmkec')->get(),

            'sls' => $query->selectRaw("
                    idsubsls as grp_key, nmsls as label, nmdesa, nmkec,
                    SUM(region_total) as total, $sums
                ")->groupBy('idsubsls', 'nmsls', 'nmdesa', 'nmkec')->get(),

            'by_pengawas', 'by_pencacah' => $query->selectRaw("
                    username as grp_key,
                    COALESCE(NULLIF(nama_lengkap,''), username) as label,
                    COUNT(DISTINCT kec_key) as kec_count,
                    COUNT(DISTINCT desa_key) as desa_count,
                    SUM(region_total) as total, $sums
                ")->groupBy('username', 'nama_lengkap')->get(),

            default => /* kec */ $query->selectRaw("
                    kec_key as grp_key, nmkec as label,
                    SUM(region_total) as total, $sums
                ")->groupBy('kec_key', 'nmkec')->get(),
        };

        return $rows->map(function ($r) use ($level) {
            $total    = (int) ($r->total ?: 1);
            $open     = (int) ($r->OPEN ?? 0);
            $approved = (int) ($r->{'APPROVED BY Pengawas'} ?? 0);

            $statuses = [];
            foreach (self::STATUS_COLS as $c) {
                $statuses[$c] = (int) ($r->$c ?? 0);
            }

            $row = [
                'key'          => $r->grp_key,
                'label'        => $r->label,
                'total'        => $total,
                'progress_pct' => round(($total - $open) / $total * 100, 1),
                'approved_pct' => round($approved / $total * 100, 1),
                'statuses'     => $statuses,
            ];

            if (isset($r->nmkec))     $row['nmkec']      = $r->nmkec;
            if (isset($r->nmdesa))    $row['nmdesa']      = $r->nmdesa;
            if (isset($r->kec_count)) $row['kec_count']   = (int) $r->kec_count;
            if (isset($r->desa_count))$row['desa_count']  = (int) $r->desa_count;

            return $row;
        })->sortByDesc('total')->values()->all();
    }

    private function calcTrend(string $table, array $filterCodes, ?string $filterLevel): array
    {
        $query = DB::connection('fasih')->table($table);
        $this->applyFilter($query, $filterCodes, $filterLevel);

        return $query->selectRaw("
            snapshot_at,
            SUM(region_total)             as total,
            SUM(\"OPEN\")                 as total_open,
            SUM(\"SUBMITTED BY Pencacah\") as total_submitted,
            SUM(\"APPROVED BY Pengawas\") as total_approved
        ")->groupBy('snapshot_at')->orderBy('snapshot_at')->get()
            ->map(function ($r) {
                $total     = (int) ($r->total ?: 1);
                $open      = (int) ($r->total_open ?: 0);
                $submitted = (int) ($r->total_submitted ?: 0);
                $approved  = (int) ($r->total_approved ?: 0);
                return [
                    'snapshot_at'   => $r->snapshot_at,
                    'progress_pct'  => round(($total - $open) / $total * 100, 1),
                    'submitted_pct' => round($submitted / $total * 100, 1),
                    'approved_pct'  => round($approved / $total * 100, 1),
                    'total'         => $total,
                ];
            })->all();
    }

    private function calcFilterOptions(
        string $table, string $snapshot,
        string $level, array $filterCodes, string $filterLevel
    ): ?array {
        if (in_array($level, ['kec', 'by_pengawas', 'by_pencacah'])) {
            return null;
        }

        $query = DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot);

        if ($level === 'desa') {
            return $query->selectRaw("kec_key as code, nmkec as label, SUM(region_total) as total")
                ->groupBy('kec_key', 'nmkec')->orderBy('nmkec')->get()
                ->map(fn ($r) => ['code' => $r->code, 'label' => $r->label, 'total' => (int) $r->total])
                ->values()->all();
        }

        if ($level === 'sls') {
            // If kec codes are already selected, narrow desa options to those kec
            if (!empty($filterCodes) && $filterLevel === 'kec') {
                $query->whereIn('kec_key', $filterCodes);
            }
            return $query->selectRaw("desa_key as code, nmdesa as label, nmkec, SUM(region_total) as total")
                ->groupBy('desa_key', 'nmdesa', 'nmkec')->orderBy('nmkec')->orderBy('nmdesa')->get()
                ->map(fn ($r) => ['code' => $r->code, 'label' => $r->label, 'kec' => $r->nmkec, 'total' => (int) $r->total])
                ->values()->all();
        }

        return null;
    }
}
