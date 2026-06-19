<?php

namespace App\Http\Controllers;

use App\Models\PetugasName;
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
            'db_ready' => file_exists($dbPath),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $snapshot = $request->input('snapshot', '');
        $role = in_array($request->input('role'), ['pengawas', 'pencacah'])
            ? $request->input('role') : 'pengawas';
        $level = $request->input('level', 'kec');

        $filterKec = array_values(array_filter((array) $request->input('filter_kec', [])));
        $filterDesa = array_values(array_filter((array) $request->input('filter_desa', [])));
        $filterSls = array_values(array_filter((array) $request->input('filter_sls', [])));

        $petugasUsername = $request->input('petugas_username', '');

        $table = 'progress_'.$role;
        $base = DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot);
        $this->applyGeoFilter($base, $filterKec, $filterDesa, $filterSls);

        if ($petugasUsername) {
            $base->where('username', $petugasUsername);
        }

        $nameOverrides = PetugasName::pluck('display_name', 'username')->all();

        return response()->json([
            'metrics' => $this->calcMetrics(clone $base),
            'status_totals' => $this->calcStatusTotals(clone $base),
            'breakdown' => $this->calcBreakdown(clone $base, $level, $nameOverrides),
            'trend' => $this->calcTrend($table, $filterKec, $filterDesa, $filterSls),
            'filter_options' => $this->calcFilterOptions($table, $snapshot, $level, $filterKec, $filterDesa, $filterSls),
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

    private function applyGeoFilter($query, array $kec, array $desa, array $sls): void
    {
        if ($kec) {
            $query->whereIn('kdkec', $kec);
        }
        if ($desa) {
            $query->whereIn('kddes', $desa);
        }
        if ($sls) {
            $query->whereIn('kdsls', $sls);
        }
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
        $row = $query->selectRaw('
            COUNT(DISTINCT username)          as total_petugas,
            COUNT(DISTINCT kdkec)             as total_kec,
            COUNT(DISTINCT kdkec || kddes)    as total_desa,
            SUM(region_total)                 as total_rt,
            SUM("OPEN")                       as total_open,
            SUM("APPROVED BY Pengawas")       as total_approved,
            SUM("SUBMITTED BY Pencacah")      as total_submitted
        ')->first();

        $total = (int) ($row->total_rt ?: 1);
        $open = (int) ($row->total_open ?: 0);
        $approved = (int) ($row->total_approved ?: 0);
        $submitted = (int) ($row->total_submitted ?: 0);

        return [
            'total_petugas' => (int) $row->total_petugas,
            'total_kec' => (int) $row->total_kec,
            'total_desa' => (int) $row->total_desa,
            'total_rt' => $total,
            'progress_pct' => round(($total - $open) / $total * 100, 1),
            'approved_pct' => round($approved / $total * 100, 1),
            'submitted_pct' => round($submitted / $total * 100, 1),
        ];
    }

    private function calcStatusTotals($query): array
    {
        $row = $query->selectRaw($this->statusSumSql())->first();
        $result = [];
        foreach (self::STATUS_COLS as $col) {
            $result[$col] = (int) ($row->$col ?? 0);
        }

        return $result;
    }

    private function calcBreakdown($query, string $level, array $nameOverrides = []): array
    {
        $sums = $this->statusSumSql();

        $rows = match ($level) {
            'desa' => $query->selectRaw("
                    kdkec || '-' || kddes as grp_key,
                    nmdesa as label, nmkec,
                    SUM(region_total) as total, $sums
                ")->groupBy('kdkec', 'kddes', 'nmdesa', 'nmkec')->get(),

            'sls' => $query->selectRaw("
                    kdkec || kddes || kdsls as grp_key,
                    nmsls as label, nmdesa, nmkec,
                    SUM(region_total) as total, $sums
                ")->groupBy('kdkec', 'kddes', 'kdsls', 'nmsls', 'nmdesa', 'nmkec')->get(),

            'subsls' => $query->selectRaw("
                    idsubsls as grp_key,
                    nmsubsls as label, nmsls, nmdesa, nmkec,
                    SUM(region_total) as total, $sums
                ")->groupBy('idsubsls', 'nmsubsls', 'nmsls', 'nmdesa', 'nmkec')->get(),

            'by_pengawas', 'by_pencacah' => $query->selectRaw("
                    username as grp_key,
                    COALESCE(NULLIF(nama_lengkap,''), username) as label,
                    COUNT(DISTINCT kdkec) as kec_count,
                    COUNT(DISTINCT kdkec || kddes) as desa_count,
                    SUM(region_total) as total, $sums
                ")->groupBy('username', 'nama_lengkap')->get(),

            default => /* kec */ $query->selectRaw("
                    kdkec as grp_key, nmkec as label,
                    SUM(region_total) as total, $sums
                ")->groupBy('kdkec', 'nmkec')->get(),
        };

        return $rows->map(function ($r) use ($nameOverrides) {
            $total = (int) ($r->total ?: 1);
            $open = (int) ($r->OPEN ?? 0);
            $approved = (int) ($r->{'APPROVED BY Pengawas'} ?? 0);

            $statuses = [];
            foreach (self::STATUS_COLS as $c) {
                $statuses[$c] = (int) ($r->$c ?? 0);
            }

            $row = [
                'key' => $r->grp_key,
                'label' => $nameOverrides[$r->grp_key] ?? $r->label,
                'total' => $total,
                'progress_pct' => round(($total - $open) / $total * 100, 1),
                'approved_pct' => round($approved / $total * 100, 1),
                'statuses' => $statuses,
            ];

            if (isset($r->nmkec)) {
                $row['nmkec'] = $r->nmkec;
            }
            if (isset($r->nmdesa)) {
                $row['nmdesa'] = $r->nmdesa;
            }
            if (isset($r->nmsls)) {
                $row['nmsls'] = $r->nmsls;
            }
            if (isset($r->kec_count)) {
                $row['kec_count'] = (int) $r->kec_count;
            }
            if (isset($r->desa_count)) {
                $row['desa_count'] = (int) $r->desa_count;
            }

            return $row;
        })->sortByDesc('total')->values()->all();
    }

    private function calcTrend(string $table, array $filterKec, array $filterDesa, array $filterSls): array
    {
        $query = DB::connection('fasih')->table($table);
        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query->selectRaw('
            snapshot_at,
            SUM(region_total)             as total,
            SUM("OPEN")                   as total_open,
            SUM("SUBMITTED BY Pencacah")  as total_submitted,
            SUM("APPROVED BY Pengawas")   as total_approved
        ')->groupBy('snapshot_at')->orderBy('snapshot_at')->get()
            ->map(function ($r) {
                $total = (int) ($r->total ?: 1);
                $open = (int) ($r->total_open ?: 0);
                $submitted = (int) ($r->total_submitted ?: 0);
                $approved = (int) ($r->total_approved ?: 0);

                return [
                    'snapshot_at' => $r->snapshot_at,
                    'progress_pct' => round(($total - $open) / $total * 100, 1),
                    'submitted_pct' => round($submitted / $total * 100, 1),
                    'approved_pct' => round($approved / $total * 100, 1),
                    'total' => $total,
                ];
            })->all();
    }

    private function calcFilterOptions(
        string $table, string $snapshot, string $level,
        array $filterKec, array $filterDesa, array $filterSls
    ): array {
        $base = fn () => DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot);

        // Provinsi & kabupaten (informational — usually single values)
        $provRow = $base()->selectRaw('kdprov as code, nmprov as label')->groupBy('kdprov', 'nmprov')->first();
        $kabRow = $base()->selectRaw('kdkab as code, nmkab as label')->groupBy('kdkab', 'nmkab')->first();

        $kecOpts = $base()
            ->selectRaw('kdkec as code, nmkec as label, SUM(region_total) as total')
            ->groupBy('kdkec', 'nmkec')
            ->orderBy('nmkec')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'label' => $r->label,
                'total' => (int) $r->total,
            ])
            ->values()->all();

        $result = [
            'prov' => $provRow ? [['code' => $provRow->code, 'label' => $provRow->label]] : [],
            'kab' => $kabRow ? [['code' => $kabRow->code,  'label' => $kabRow->label]] : [],
            'kec' => $kecOpts,
            'desa' => null,
            'sls' => null,
        ];

        if (in_array($level, ['desa', 'sls', 'subsls', 'by_pengawas', 'by_pencacah'])) {
            $desaQ = $base()
                ->selectRaw('kdkec as kec_code, nmkec as kec, kddes as code, nmdesa as label, SUM(region_total) as total')
                ->groupBy('kdkec', 'nmkec', 'kddes', 'nmdesa')
                ->orderBy('nmkec')
                ->orderBy('nmdesa');
            if ($filterKec) {
                $desaQ->whereIn('kdkec', $filterKec);
            }

            $result['desa'] = $desaQ->get()
                ->map(fn ($r) => [
                    'code' => $r->code,
                    'label' => $r->label,
                    'kec_code' => $r->kec_code,
                    'kec' => $r->kec,
                    'total' => (int) $r->total,
                ])
                ->values()->all();
        }

        if ($level === 'subsls') {
            $slsQ = $base()
                ->selectRaw('kdkec as kec_code, nmkec as kec, kddes as desa_code, nmdesa as desa, kdsls as code, nmsls as label, SUM(region_total) as total')
                ->groupBy('kdkec', 'nmkec', 'kddes', 'nmdesa', 'kdsls', 'nmsls')
                ->orderBy('nmkec')
                ->orderBy('nmdesa')
                ->orderBy('nmsls');
            if ($filterKec) {
                $slsQ->whereIn('kdkec', $filterKec);
            }
            if ($filterDesa) {
                $slsQ->whereIn('kddes', $filterDesa);
            }

            $result['sls'] = $slsQ->get()
                ->map(fn ($r) => [
                    'code' => $r->code,
                    'label' => $r->label,
                    'desa_code' => $r->desa_code,
                    'desa' => $r->desa,
                    'kec_code' => $r->kec_code,
                    'kec' => $r->kec,
                    'total' => (int) $r->total,
                ])
                ->values()->all();
        }

        return $result;
    }
}
