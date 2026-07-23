<?php

namespace App\Http\Controllers;

use App\Models\PetugasName;
use App\Services\PrelistComparisonService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        'COMPLETED BY Admin Kabupaten',
        'EDITED BY Admin Kabupaten',
        'REJECTED BY Admin Kabupaten',
        'REVOKED BY Admin Kabupaten',
    ];

    private const STATUS_SUM_SQL = 'SUM("OPEN") as "OPEN", SUM("DRAFT") as "DRAFT", SUM("SUBMITTED BY Pencacah") as "SUBMITTED BY Pencacah", SUM("APPROVED BY Pengawas") as "APPROVED BY Pengawas", SUM("REJECTED BY Pengawas") as "REJECTED BY Pengawas", SUM("EDITED BY Pengawas") as "EDITED BY Pengawas", SUM("REVOKED BY Pengawas") as "REVOKED BY Pengawas", SUM("SUBMITTED RESPONDENT") as "SUBMITTED RESPONDENT", SUM("COMPLETED BY Admin Kabupaten") as "COMPLETED BY Admin Kabupaten", SUM("EDITED BY Admin Kabupaten") as "EDITED BY Admin Kabupaten", SUM("REJECTED BY Admin Kabupaten") as "REJECTED BY Admin Kabupaten", SUM("REVOKED BY Admin Kabupaten") as "REVOKED BY Admin Kabupaten"';

    private const SUBMIT_SUM_SQL = 'SUM("SUBMITTED BY Pencacah") + SUM("APPROVED BY Pengawas") + SUM("REJECTED BY Pengawas") + SUM("EDITED BY Pengawas") + SUM("REVOKED BY Pengawas") + SUM("SUBMITTED RESPONDENT") + SUM("COMPLETED BY Admin Kabupaten") + SUM("EDITED BY Admin Kabupaten") + SUM("REJECTED BY Admin Kabupaten") + SUM("REVOKED BY Admin Kabupaten")';

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

    public function data(Request $request, PrelistComparisonService $prelists): JsonResponse
    {
        $snapshot = $request->input('snapshot', '');
        $role = in_array($request->input('role'), ['pengawas', 'pencacah'])
            ? $request->input('role') : 'pengawas';
        $level = $request->input('level', 'kec');
        $prelistBasis = $prelists->basis($request->input('prelist_basis'));

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
        $basisTotal = $prelists->totalForBasis($prelistBasis, $filterKec, $filterDesa, $filterSls, $table, $snapshot);
        $groupTotals = match (true) {
            $petugasUsername !== '' && ! in_array($level, ['by_pengawas', 'by_pencacah'], true) => $prelists->progressScopedGroupTotals(
                $level,
                $prelistBasis,
                $table,
                $snapshot,
                $petugasUsername,
                $filterKec,
                $filterDesa,
                $filterSls,
            ),
            in_array($level, ['by_pengawas', 'by_pencacah'], true) => $prelists->officerGroupTotals($level, $prelistBasis, $filterKec, $filterDesa, $filterSls, $table, $snapshot),
            default => $prelists->groupTotals($level, $prelistBasis, $filterKec, $filterDesa, $filterSls, $table, $snapshot),
        };

        return response()->json([
            'metrics' => $this->calcMetrics(clone $base, $basisTotal),
            'status_totals' => $this->calcStatusTotals(clone $base),
            'breakdown' => $this->calcBreakdown(clone $base, $level, $nameOverrides, $groupTotals),
            'trend' => $this->calcTrend($table, $filterKec, $filterDesa, $filterSls, $prelistBasis, $prelists),
            'filter_options' => $this->calcFilterOptions($table, $snapshot, $level, $filterKec, $filterDesa, $filterSls),
            'prelist_basis' => $prelistBasis,
            'prelist_comparison' => $prelists->comparison($filterKec, $filterDesa, $filterSls, $table, $snapshot),
        ]);
    }

    public function ringkasan(): Response
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

        return Inertia::render('Ringkasan', [
            'snapshots' => $snapshots,
            'db_ready' => file_exists($dbPath),
        ]);
    }

    public function ringkasanData(Request $request, PrelistComparisonService $prelists): JsonResponse
    {
        $snapshot = $request->input('snapshot', '');
        $prelistBasis = $prelists->basis($request->input('prelist_basis'));

        $baseP = DB::connection('fasih')->table('progress_pengawas')->where('snapshot_at', $snapshot);
        $baseC = DB::connection('fasih')->table('progress_pencacah')->where('snapshot_at', $snapshot);
        $basisTotal = $prelists->totalForBasis($prelistBasis, [], [], [], 'progress_pengawas', $snapshot);
        $kecTotals = $prelists->groupTotals('kec', $prelistBasis, [], [], [], 'progress_pengawas', $snapshot);

        // Geo info
        $geoRow = (clone $baseP)
            ->whereNotNull('kdprov')
            ->whereNotNull('nmprov')
            ->whereNotNull('kdkab')
            ->whereNotNull('nmkab')
            ->selectRaw('kdprov, nmprov, kdkab, nmkab')
            ->first();

        // Metrics
        $submitSql = self::SUBMIT_SUM_SQL;
        $m = (clone $baseP)->selectRaw("
            COUNT(DISTINCT kdkec)                as total_kec,
            COUNT(DISTINCT kdkec || kddes)        as total_desa,
            COUNT(DISTINCT kdkec || kddes || kdsls) as total_sls,
            COUNT(DISTINCT idsubsls)             as total_subsls,
            COUNT(DISTINCT username)             as total_pengawas,
            SUM(\"OPEN\")                          as total_open,
            SUM(\"DRAFT\")                         as total_draft,
            SUM(\"APPROVED BY Pengawas\")          as total_approved,
            SUM(\"SUBMITTED BY Pencacah\")         as total_submitted,
            SUM(\"REJECTED BY Pengawas\")          as total_rejected,
            ({$submitSql})                         as total_submit_progress
        ")->first();

        $totalPencacah = (clone $baseC)->distinct()->count('username');

        $total = max(1, $basisTotal);
        $submitProgress = (int) ($m->total_submit_progress ?: 0);
        $approved = (int) ($m->total_approved ?: 0);
        $submitted = (int) ($m->total_submitted ?: 0);
        $rejected = (int) ($m->total_rejected ?: 0);

        // Status totals
        $statusRow = (clone $baseP)->selectRaw(self::STATUS_SUM_SQL)->first();
        $statusTotals = [];
        foreach (self::STATUS_COLS as $col) {
            $statusTotals[$col] = (int) ($statusRow->$col ?? 0);
        }

        // Kecamatan breakdown
        $sums = self::STATUS_SUM_SQL;
        $kecRows = (clone $baseP)->selectRaw("
            kdkec, nmkec,
            COUNT(DISTINCT kdkec || kddes) as total_desa,
            SUM(region_total) as progress_total, $sums
        ")->groupBy('kdkec', 'nmkec')->orderByDesc('progress_total')->get();

        $kecamatan = $kecRows->map(function ($r) use ($kecTotals) {
            $prelist = $kecTotals[(string) $r->kdkec] ?? null;
            $tot = (int) (($prelist['selected'] ?? $r->progress_total) ?: 1);
            $app = (int) ($r->{'APPROVED BY Pengawas'} ?? 0);
            $statuses = [];
            foreach (self::STATUS_COLS as $c) {
                $statuses[$c] = (int) ($r->$c ?? 0);
            }
            $submitProgress = $this->actualSubmitTotal($statuses);

            return [
                'kdkec' => $r->kdkec,
                'nmkec' => $r->nmkec,
                'total_desa' => (int) $r->total_desa,
                'total' => $tot,
                'progress_total' => (int) $r->progress_total,
                'prelist_dynamic' => (int) ($prelist['dynamic'] ?? 0),
                'prelist_initial' => (int) ($prelist['initial'] ?? 0),
                'prelist_delta' => (int) (($prelist['dynamic'] ?? 0) - ($prelist['initial'] ?? 0)),
                'progress_pct' => round($submitProgress / $tot * 100, 1),
                'approved_pct' => round($app / $tot * 100, 1),
                'statuses' => $statuses,
            ];
        })->sortByDesc('total')->values()->all();

        return response()->json([
            'kab_name' => $geoRow->nmkab ?? '—',
            'prov_name' => $geoRow->nmprov ?? '—',
            'metrics' => [
                'total_kec' => (int) $m->total_kec,
                'total_desa' => (int) $m->total_desa,
                'total_sls' => (int) $m->total_sls,
                'total_subsls' => (int) $m->total_subsls,
                'total_pengawas' => (int) $m->total_pengawas,
                'total_pencacah' => $totalPencacah,
                'total_assignment' => $total,
                'progress_pct' => round($submitProgress / $total * 100, 1),
                'approved_pct' => round($approved / $total * 100, 1),
                'submitted_pct' => round($submitted / $total * 100, 1),
                'rejected_pct' => round($rejected / $total * 100, 1),
            ],
            'status_totals' => $statusTotals,
            'kecamatan' => $kecamatan,
            'trend' => $this->calcTrend('progress_pengawas', [], [], [], $prelistBasis, $prelists),
            'prelist_basis' => $prelistBasis,
            'prelist_comparison' => $prelists->comparison([], [], [], 'progress_pengawas', $snapshot),
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

    /**
     * @param  list<string>  $kec
     * @param  list<string>  $desa
     * @param  list<string>  $sls
     */
    private function applyGeoFilter(Builder $query, array $kec, array $desa, array $sls): void
    {
        if ($kec) {
            $query->whereIn('kdkec', $kec);
        }
        if ($desa) {
            $query->where(function (Builder $q) use ($desa) {
                foreach ($desa as $code) {
                    $parts = explode('-', $code);
                    if (count($parts) === 2) {
                        [$kdkec, $kddes] = $parts;
                        $q->orWhere(fn (Builder $sub) => $sub
                            ->where('kdkec', $kdkec)
                            ->where('kddes', $kddes));
                    } else {
                        $q->orWhere('kddes', $code);
                    }
                }
            });
        }
        if ($sls) {
            $query->where(function (Builder $q) use ($sls) {
                foreach ($sls as $code) {
                    $parts = explode('-', $code);
                    if (count($parts) === 3) {
                        [$kdkec, $kddes, $kdsls] = $parts;
                        $q->orWhere(fn (Builder $sub) => $sub
                            ->where('kdkec', $kdkec)
                            ->where('kddes', $kddes)
                            ->where('kdsls', $kdsls));
                    } else {
                        $q->orWhere('kdsls', $code);
                    }
                }
            });
        }
    }

    // ── calculators ───────────────────────────────────────────────────────

    /**
     * @return array<string, int|float>
     */
    private function calcMetrics(Builder $query, int $basisTotal = 0): array
    {
        $submitSql = self::SUBMIT_SUM_SQL;
        $row = $query->selectRaw("
            COUNT(DISTINCT username)          as total_petugas,
            COUNT(DISTINCT kdkec)             as total_kec,
            COUNT(DISTINCT kdkec || kddes)    as total_desa,
            SUM(region_total)                 as progress_total,
            SUM(\"OPEN\")                       as total_open,
            SUM(\"DRAFT\")                      as total_draft,
            SUM(\"APPROVED BY Pengawas\")       as total_approved,
            SUM(\"SUBMITTED BY Pencacah\")      as total_submitted,
            SUM(\"REJECTED BY Pengawas\")       as total_rejected,
            ({$submitSql})                      as total_submit_progress
        ")->first();

        $total = (int) ($basisTotal ?: $row->progress_total ?: 1);
        $submitProgress = (int) ($row->total_submit_progress ?: 0);
        $approved = (int) ($row->total_approved ?: 0);
        $submitted = (int) ($row->total_submitted ?: 0);
        $rejected = (int) ($row->total_rejected ?: 0);

        return [
            'total_petugas' => (int) $row->total_petugas,
            'total_kec' => (int) $row->total_kec,
            'total_desa' => (int) $row->total_desa,
            'total_assignment' => $total,
            'progress_total' => (int) ($row->progress_total ?: 0),
            'progress_pct' => round($submitProgress / $total * 100, 1),
            'approved_pct' => round($approved / $total * 100, 1),
            'submitted_pct' => round($submitted / $total * 100, 1),
            'rejected_pct' => round($rejected / $total * 100, 1),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function calcStatusTotals(Builder $query): array
    {
        $row = $query->selectRaw(self::STATUS_SUM_SQL)->first();
        $result = [];
        foreach (self::STATUS_COLS as $col) {
            $result[$col] = (int) ($row->$col ?? 0);
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $statuses
     */
    private function actualSubmitTotal(array $statuses): int
    {
        return collect(self::STATUS_COLS)
            ->reject(fn (string $status) => in_array($status, ['OPEN', 'DRAFT'], true))
            ->sum(fn (string $status) => $statuses[$status] ?? 0);
    }

    /**
     * @param  array<string, string>  $nameOverrides
     * @return array<int, array<string, mixed>>
     */
    private function calcBreakdown(Builder $query, string $level, array $nameOverrides = [], array $prelistTotals = []): array
    {
        $sums = self::STATUS_SUM_SQL;

        $rows = match ($level) {
            'desa' => $query->selectRaw("
                    kdkec || '-' || kddes as grp_key,
                    nmdesa as label, nmkec,
                    SUM(region_total) as progress_total, $sums
                ")->groupBy('kdkec', 'kddes', 'nmdesa', 'nmkec')->get(),

            'sls' => $query->selectRaw("
                    kdkec || kddes || kdsls as grp_key,
                    nmsls as label, nmdesa, nmkec,
                    SUM(region_total) as progress_total, $sums
                ")->groupBy('kdkec', 'kddes', 'kdsls', 'nmsls', 'nmdesa', 'nmkec')->get(),

            'subsls' => $query->selectRaw("
                    idsubsls as grp_key,
                    nmsubsls as label, nmsls, nmdesa, nmkec,
                    SUM(region_total) as progress_total, $sums
                ")->groupBy('idsubsls', 'nmsubsls', 'nmsls', 'nmdesa', 'nmkec')->get(),

            'by_pengawas', 'by_pencacah' => $query->selectRaw("
                    username as grp_key,
                    COALESCE(NULLIF(nama_lengkap,''), username) as label,
                    COUNT(DISTINCT kdkec) as kec_count,
                    COUNT(DISTINCT kdkec || kddes) as desa_count,
                    SUM(region_total) as progress_total, $sums
                ")->groupBy('username', 'nama_lengkap')->get(),

            default => /* kec */ $query->selectRaw("
                    kdkec as grp_key, nmkec as label,
                    SUM(region_total) as progress_total, $sums
                ")->groupBy('kdkec', 'nmkec')->get(),
        };

        return $rows->map(function ($r) use ($level, $nameOverrides, $prelistTotals) {
            $prelist = $prelistTotals[(string) $r->grp_key] ?? null;
            $progressTotal = (int) ($r->progress_total ?: 0);
            $total = (int) (($prelist['selected'] ?? $progressTotal) ?: 1);
            $open = (int) ($r->OPEN ?? 0);
            $approved = (int) ($r->{'APPROVED BY Pengawas'} ?? 0);
            $lapanganTotal =
                (int) ($r->DRAFT ?? 0) +
                (int) ($r->{'SUBMITTED BY Pencacah'} ?? 0) +
                (int) ($r->{'APPROVED BY Pengawas'} ?? 0) +
                (int) ($r->{'REJECTED BY Pengawas'} ?? 0) +
                (int) ($r->{'EDITED BY Pengawas'} ?? 0) +
                (int) ($r->{'REVOKED BY Pengawas'} ?? 0) +
                (int) ($r->{'SUBMITTED RESPONDENT'} ?? 0) +
                (int) ($r->{'COMPLETED BY Admin Kabupaten'} ?? 0) +
                (int) ($r->{'EDITED BY Admin Kabupaten'} ?? 0) +
                (int) ($r->{'REJECTED BY Admin Kabupaten'} ?? 0) +
                (int) ($r->{'REVOKED BY Admin Kabupaten'} ?? 0);

            $statuses = [];
            foreach (self::STATUS_COLS as $c) {
                $statuses[$c] = (int) ($r->$c ?? 0);
            }
            $submitProgress = $this->actualSubmitTotal($statuses);

            $label = $nameOverrides[$r->grp_key] ?? $r->label ?? null;
            if (! is_string($label) || trim($label) === '') {
                $grpKey = is_scalar($r->grp_key) ? trim((string) $r->grp_key) : '';
                $label = $grpKey !== '' ? "Tanpa label ($grpKey)" : 'Tanpa label wilayah';
            }

            if (in_array($level, ['by_pengawas', 'by_pencacah'], true)) {
                $label = Str::title(Str::lower(trim($label)));
            }

            $row = [
                'key' => $r->grp_key,
                'label' => $label,
                'total' => $total,
                'progress_total' => $progressTotal,
                'prelist_dynamic' => (int) ($prelist['dynamic'] ?? 0),
                'prelist_initial' => (int) ($prelist['initial'] ?? 0),
                'prelist_delta' => (int) (($prelist['dynamic'] ?? 0) - ($prelist['initial'] ?? 0)),
                'progress_pct' => round($submitProgress / $total * 100, 1),
                'lapangan_total' => $lapanganTotal,
                'lapangan_pct' => round($lapanganTotal / $total * 100, 1),
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

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<int, array<string, int|float|string>>
     */
    private function calcTrend(
        string $table,
        array $filterKec,
        array $filterDesa,
        array $filterSls,
        string $prelistBasis = 'dynamic',
        ?PrelistComparisonService $prelists = null,
    ): array {
        $query = DB::connection('fasih')->table($table);
        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        $latestSnapshots = (clone $query)
            ->whereNotNull('snapshot_at')
            ->selectRaw('DATE(snapshot_at) as snapshot_date, MAX(snapshot_at) as snapshot_at')
            ->groupBy('snapshot_date')
            ->orderByDesc('snapshot_date')
            ->limit(7)
            ->pluck('snapshot_at')
            ->sort()
            ->values()
            ->all();

        if ($latestSnapshots === []) {
            return [];
        }

        $basisTotal = $prelists?->totalForBasis($prelistBasis, $filterKec, $filterDesa, $filterSls, $table) ?? 0;
        $submitSql = self::SUBMIT_SUM_SQL;

        return $query
            ->whereIn('snapshot_at', $latestSnapshots)
            ->selectRaw("
            snapshot_at,
            SUM(region_total)             as progress_total,
            SUM(\"OPEN\")                   as total_open,
            SUM(\"DRAFT\")                  as total_draft,
            SUM(\"SUBMITTED BY Pencacah\")  as total_submitted,
            SUM(\"APPROVED BY Pengawas\")   as total_approved,
            ({$submitSql})                 as total_submit_progress
        ")
            ->groupBy('snapshot_at')
            ->orderBy('snapshot_at')
            ->get()
            ->map(function ($r) use ($basisTotal) {
                $total = (int) ($basisTotal ?: $r->progress_total ?: 1);
                $submitProgress = (int) ($r->total_submit_progress ?: 0);
                $submitted = (int) ($r->total_submitted ?: 0);
                $approved = (int) ($r->total_approved ?: 0);

                return [
                    'snapshot_at' => $r->snapshot_at,
                    'progress_pct' => round($submitProgress / $total * 100, 1),
                    'submitted_pct' => round($submitted / $total * 100, 1),
                    'approved_pct' => round($approved / $total * 100, 1),
                    'total' => $total,
                ];
            })->all();
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, mixed>
     */
    private function calcFilterOptions(
        string $table, string $snapshot, string $level,
        array $filterKec, array $filterDesa, array $filterSls
    ): array {
        $base = fn () => DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot);

        // Provinsi & kabupaten (informational — usually single values)
        $provRow = $base()->whereNotNull('kdprov')->whereNotNull('nmprov')
            ->selectRaw('kdprov as code, nmprov as label')->groupBy('kdprov', 'nmprov')->first();
        $kabRow = $base()->whereNotNull('kdkab')->whereNotNull('nmkab')
            ->selectRaw('kdkab as code, nmkab as label')->groupBy('kdkab', 'nmkab')->first();

        $kecOpts = $base()
            ->whereNotNull('kdkec')
            ->whereNotNull('nmkec')
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
                ->whereNotNull('kdkec')
                ->whereNotNull('nmkec')
                ->whereNotNull('kddes')
                ->whereNotNull('nmdesa')
                ->selectRaw('kdkec as kec_code, nmkec as kec, kddes as code, nmdesa as label, SUM(region_total) as total')
                ->groupBy('kdkec', 'nmkec', 'kddes', 'nmdesa')
                ->orderBy('nmkec')
                ->orderBy('nmdesa');
            if ($filterKec) {
                $desaQ->whereIn('kdkec', $filterKec);
            }

            $result['desa'] = $desaQ->get()
                ->map(fn ($r) => [
                    'code' => "{$r->kec_code}-{$r->code}",
                    'label' => $r->label,
                    'kec_code' => $r->kec_code,
                    'kec' => $r->kec,
                    'total' => (int) $r->total,
                ])
                ->values()->all();
        }

        if ($level === 'subsls') {
            $slsQ = $base()
                ->whereNotNull('kdkec')
                ->whereNotNull('nmkec')
                ->whereNotNull('kddes')
                ->whereNotNull('nmdesa')
                ->whereNotNull('kdsls')
                ->whereNotNull('nmsls')
                ->selectRaw('kdkec as kec_code, nmkec as kec, kddes as desa_code, nmdesa as desa, kdsls as code, nmsls as label, SUM(region_total) as total')
                ->groupBy('kdkec', 'nmkec', 'kddes', 'nmdesa', 'kdsls', 'nmsls')
                ->orderBy('nmkec')
                ->orderBy('nmdesa')
                ->orderBy('nmsls');
            if ($filterKec) {
                $slsQ->whereIn('kdkec', $filterKec);
            }
            if ($filterDesa) {
                $slsQ->where(function (Builder $query) use ($filterDesa) {
                    foreach ($filterDesa as $code) {
                        $parts = explode('-', $code);
                        if (count($parts) === 2) {
                            [$kdkec, $kddes] = $parts;
                            $query->orWhere(fn (Builder $sub) => $sub
                                ->where('kdkec', $kdkec)
                                ->where('kddes', $kddes));
                        } else {
                            $query->orWhere('kddes', $code);
                        }
                    }
                });
            }

            $result['sls'] = $slsQ->get()
                ->map(fn ($r) => [
                    'code' => "{$r->kec_code}-{$r->desa_code}-{$r->code}",
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
