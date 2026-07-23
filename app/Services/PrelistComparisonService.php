<?php

namespace App\Services;

use App\Models\InitialPrelist;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PrelistComparisonService
{
    public function basis(mixed $basis): string
    {
        return $basis === 'initial' ? 'initial' : 'dynamic';
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, mixed>
     */
    public function comparison(
        array $filterKec = [],
        array $filterDesa = [],
        array $filterSls = [],
        ?string $progressTable = null,
        ?string $snapshot = null,
    ): array {
        $progressIds = $progressTable !== null
            ? $this->progressIds($progressTable, $snapshot, $filterKec, $filterDesa, $filterSls)
            : [];
        $dynamicIds = $progressIds;
        $initialIds = $this->initialIds($filterKec, $filterDesa, $filterSls);
        $dynamicSet = array_fill_keys($dynamicIds, true);
        $initialSet = array_fill_keys($initialIds, true);
        $initialAvailable = $this->initialAvailable();
        $dynamicTotal = $this->dynamicTotal($filterKec, $filterDesa, $filterSls, $progressTable, $snapshot);
        $initialTotal = $this->initialTotal($filterKec, $filterDesa, $filterSls);
        $delta = $dynamicTotal - $initialTotal;

        return [
            'dynamic_total' => $dynamicTotal,
            'initial_total' => $initialTotal,
            'delta' => $delta,
            'delta_pct' => $initialTotal > 0 ? round(($delta / $initialTotal) * 100, 1) : 0.0,
            'matched_subsls' => count(array_intersect_key($dynamicSet, $initialSet)),
            'initial_only_subsls' => count(array_diff_key($initialSet, $dynamicSet)),
            'initial_without_assignments_subsls' => 0,
            'initial_without_assignments_with_progress_subsls' => 0,
            'initial_without_assignments_missing_progress_subsls' => 0,
            'dynamic_only_subsls' => count(array_diff_key($dynamicSet, $initialSet)),
            'zero_initial_subsls' => $this->zeroInitialCount($filterKec, $filterDesa, $filterSls),
            'initial_available' => $initialAvailable,
        ];
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     */
    public function totalForBasis(
        string $basis,
        array $filterKec = [],
        array $filterDesa = [],
        array $filterSls = [],
        ?string $progressTable = null,
        ?string $snapshot = null,
    ): int {
        if ($basis === 'initial' && $this->initialAvailable()) {
            return $this->initialTotal($filterKec, $filterDesa, $filterSls);
        }

        return $this->dynamicTotal($filterKec, $filterDesa, $filterSls, $progressTable, $snapshot);
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, array{dynamic:int, initial:int, selected:int}>
     */
    public function groupTotals(
        string $level,
        string $basis,
        array $filterKec = [],
        array $filterDesa = [],
        array $filterSls = [],
        ?string $progressTable = null,
        ?string $snapshot = null,
    ): array {
        if (in_array($level, ['by_pengawas', 'by_pencacah'], true)) {
            return [];
        }

        $dynamic = $this->dynamicGroupTotals($level, $filterKec, $filterDesa, $filterSls, $progressTable, $snapshot);
        $initial = $this->initialGroupTotals($level, $filterKec, $filterDesa, $filterSls);
        $keys = array_unique([...array_keys($dynamic), ...array_keys($initial)]);
        $result = [];

        foreach ($keys as $key) {
            $dynamicTotal = $dynamic[$key] ?? 0;
            $initialTotal = $initial[$key] ?? 0;
            $result[$key] = [
                'dynamic' => $dynamicTotal,
                'initial' => $initialTotal,
                'selected' => $basis === 'initial' && $this->initialAvailable()
                    ? $initialTotal
                    : $dynamicTotal,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, array{dynamic:int, initial:int, selected:int}>
     */
    public function officerGroupTotals(
        string $level,
        string $basis,
        array $filterKec = [],
        array $filterDesa = [],
        array $filterSls = [],
        ?string $progressTable = null,
        ?string $snapshot = null,
    ): array {
        if (! in_array($level, ['by_pengawas', 'by_pencacah'], true)) {
            return [];
        }

        $dynamic = $this->officerProgressTotals($progressTable, $snapshot, $filterKec, $filterDesa, $filterSls);
        $initial = $this->officerInitialTotals($progressTable, $snapshot, $filterKec, $filterDesa, $filterSls);
        $keys = array_unique([...array_keys($dynamic), ...array_keys($initial)]);
        $result = [];

        foreach ($keys as $key) {
            $dynamicTotal = $dynamic[$key] ?? 0;
            $initialTotal = $initial[$key] ?? 0;
            $result[$key] = [
                'dynamic' => $dynamicTotal,
                'initial' => $initialTotal,
                'selected' => $basis === 'initial' && $this->initialAvailable()
                    ? $initialTotal
                    : $dynamicTotal,
            ];
        }

        return $result;
    }

    public function initialAvailable(): bool
    {
        return Schema::hasTable('initial_prelists')
            && InitialPrelist::query()->exists();
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, int>
     */
    private function officerInitialTotals(?string $table, ?string $snapshot, array $filterKec, array $filterDesa, array $filterSls): array
    {
        if ($table === null || ! $this->fasihTableExists($table)) {
            return [];
        }

        $initialByRegion = $this->initialGroupTotals('subsls', $filterKec, $filterDesa, $filterSls);
        if ($initialByRegion === []) {
            return [];
        }

        $snapshot ??= DB::connection('fasih')->table($table)->max('snapshot_at');
        $query = DB::connection('fasih')
            ->table($table)
            ->whereNotNull('username')
            ->whereNotNull('idsubsls')
            ->select('username', 'idsubsls');

        if ($snapshot !== null) {
            $query->where('snapshot_at', $snapshot);
        }

        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        $result = [];
        foreach ($query->distinct()->get() as $row) {
            $officerId = (string) $row->username;
            $idsubsls = (string) $row->idsubsls;
            $result[$officerId] = ($result[$officerId] ?? 0) + ($initialByRegion[$idsubsls] ?? 0);
        }

        return $result;
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return list<string>
     */
    private function initialIds(array $filterKec, array $filterDesa, array $filterSls): array
    {
        if (! Schema::hasTable('initial_prelists')) {
            return [];
        }

        $query = InitialPrelist::query()->whereNotNull('idsubsls');
        $this->applyInitialGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query->distinct()->pluck('idsubsls')->filter()->values()->all();
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return list<string>
     */
    private function progressIds(string $table, ?string $snapshot, array $filterKec, array $filterDesa, array $filterSls): array
    {
        if (! $this->fasihTableExists($table)) {
            return [];
        }

        $snapshot ??= DB::connection('fasih')->table($table)->max('snapshot_at');
        $query = DB::connection('fasih')->table($table)->whereNotNull('idsubsls');
        if ($snapshot !== null) {
            $query->where('snapshot_at', $snapshot);
        }

        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query->distinct()->pluck('idsubsls')->filter()->values()->all();
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     */
    private function dynamicTotal(
        array $filterKec,
        array $filterDesa,
        array $filterSls,
        ?string $progressTable = null,
        ?string $snapshot = null,
    ): int {
        if ($progressTable === null || ! $this->fasihTableExists($progressTable)) {
            return 0;
        }

        $snapshot ??= DB::connection('fasih')->table($progressTable)->max('snapshot_at');
        $query = DB::connection('fasih')->table($progressTable);
        if ($snapshot !== null) {
            $query->where('snapshot_at', $snapshot);
        }
        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return (int) $query->sum('region_total');
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     */
    private function initialTotal(array $filterKec, array $filterDesa, array $filterSls): int
    {
        if (! Schema::hasTable('initial_prelists')) {
            return 0;
        }

        $query = InitialPrelist::query();
        $this->applyInitialGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return (int) $query->sum('total_assignment_fasih');
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     */
    private function zeroInitialCount(array $filterKec, array $filterDesa, array $filterSls): int
    {
        if (! Schema::hasTable('initial_prelists')) {
            return 0;
        }

        $query = InitialPrelist::query()->where('total_assignment_fasih', 0);
        $this->applyInitialGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return (int) $query->count();
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, int>
     */
    private function dynamicGroupTotals(
        string $level,
        array $filterKec,
        array $filterDesa,
        array $filterSls,
        ?string $progressTable = null,
        ?string $snapshot = null,
    ): array {
        if ($progressTable === null || ! $this->fasihTableExists($progressTable)) {
            return [];
        }

        [$keySql, $groupBy] = $this->dynamicGroupSql($level);
        $snapshot ??= DB::connection('fasih')->table($progressTable)->max('snapshot_at');
        $query = DB::connection('fasih')->table($progressTable)->selectRaw("{$keySql} as grp_key, SUM(region_total) as total");
        if ($snapshot !== null) {
            $query->where('snapshot_at', $snapshot);
        }
        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query
            ->whereNotNull('idsubsls')
            ->groupBy(...$groupBy)
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->grp_key => (int) $row->total])
            ->all();
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, int>
     */
    private function officerProgressTotals(?string $table, ?string $snapshot, array $filterKec, array $filterDesa, array $filterSls): array
    {
        if ($table === null || ! $this->fasihTableExists($table)) {
            return [];
        }

        $snapshot ??= DB::connection('fasih')->table($table)->max('snapshot_at');
        $query = DB::connection('fasih')
            ->table($table)
            ->whereNotNull('username')
            ->selectRaw('username as grp_key, SUM(region_total) as total');

        if ($snapshot !== null) {
            $query->where('snapshot_at', $snapshot);
        }

        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query
            ->groupBy('username')
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->grp_key => (int) $row->total])
            ->all();
    }

    /**
     * @param  list<string>  $filterKec
     * @param  list<string>  $filterDesa
     * @param  list<string>  $filterSls
     * @return array<string, int>
     */
    private function initialGroupTotals(string $level, array $filterKec, array $filterDesa, array $filterSls): array
    {
        if (! Schema::hasTable('initial_prelists')) {
            return [];
        }

        [$keySql, $groupBy] = $this->initialGroupSql($level);
        $query = InitialPrelist::query()->selectRaw("{$keySql} as grp_key, SUM(total_assignment_fasih) as total");
        $this->applyInitialGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query
            ->whereNotNull('idsubsls')
            ->groupBy(...$groupBy)
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->grp_key => (int) $row->total])
            ->all();
    }

    /**
     * @return array{0:string, 1:list<string>}
     */
    private function dynamicGroupSql(string $level): array
    {
        return match ($level) {
            'desa' => ["SUBSTR(idsubsls, 5, 3) || '-' || SUBSTR(idsubsls, 8, 3)", ['grp_key']],
            'sls' => ['SUBSTR(idsubsls, 5, 3) || SUBSTR(idsubsls, 8, 3) || SUBSTR(idsubsls, 11, 4)', ['grp_key']],
            'subsls' => ['idsubsls', ['idsubsls']],
            default => ['SUBSTR(idsubsls, 5, 3)', ['grp_key']],
        };
    }

    /**
     * @return array{0:string, 1:list<string>}
     */
    private function initialGroupSql(string $level): array
    {
        return match ($level) {
            'desa' => ["SUBSTR(idsubsls, 5, 3) || '-' || SUBSTR(idsubsls, 8, 3)", ['grp_key']],
            'sls' => ['SUBSTR(idsubsls, 5, 3) || SUBSTR(idsubsls, 8, 3) || SUBSTR(idsubsls, 11, 4)', ['grp_key']],
            'subsls' => ['idsubsls', ['idsubsls']],
            default => ['SUBSTR(idsubsls, 5, 3)', ['grp_key']],
        };
    }

    /**
     * @param  list<string>  $kec
     * @param  list<string>  $desa
     * @param  list<string>  $sls
     */
    private function applyGeoFilter(Builder $query, array $kec, array $desa, array $sls): void
    {
        if ($kec !== []) {
            $query->whereIn(DB::raw('SUBSTR(idsubsls, 5, 3)'), array_map($this->normalizeKec(...), $kec));
        }

        if ($desa !== []) {
            $query->where(function (Builder $builder) use ($desa) {
                foreach ($desa as $code) {
                    [$kdkec, $kddes] = $this->normalizeDesa($code);
                    $builder->orWhere(fn (Builder $sub) => $sub
                        ->where(DB::raw('SUBSTR(idsubsls, 5, 3)'), $kdkec)
                        ->where(DB::raw('SUBSTR(idsubsls, 8, 3)'), $kddes));
                }
            });
        }

        if ($sls !== []) {
            $query->where(function (Builder $builder) use ($sls) {
                foreach ($sls as $code) {
                    [$kdkec, $kddes, $kdsls] = $this->normalizeSls($code);
                    $builder->orWhere(fn (Builder $sub) => $sub
                        ->where(DB::raw('SUBSTR(idsubsls, 5, 3)'), $kdkec)
                        ->where(DB::raw('SUBSTR(idsubsls, 8, 3)'), $kddes)
                        ->where(DB::raw('SUBSTR(idsubsls, 11, 4)'), $kdsls));
                }
            });
        }
    }

    /**
     * @param  list<string>  $kec
     * @param  list<string>  $desa
     * @param  list<string>  $sls
     */
    private function applyInitialGeoFilter(EloquentBuilder $query, array $kec, array $desa, array $sls): void
    {
        if ($kec !== []) {
            $query->whereIn(DB::raw('SUBSTR(idsubsls, 5, 3)'), array_map($this->normalizeKec(...), $kec));
        }

        if ($desa !== []) {
            $query->where(function (EloquentBuilder $builder) use ($desa) {
                foreach ($desa as $code) {
                    [$kdkec, $kddes] = $this->normalizeDesa($code);
                    $builder->orWhere(fn (EloquentBuilder $sub) => $sub
                        ->where(DB::raw('SUBSTR(idsubsls, 5, 3)'), $kdkec)
                        ->where(DB::raw('SUBSTR(idsubsls, 8, 3)'), $kddes));
                }
            });
        }

        if ($sls !== []) {
            $query->where(function (EloquentBuilder $builder) use ($sls) {
                foreach ($sls as $code) {
                    [$kdkec, $kddes, $kdsls] = $this->normalizeSls($code);
                    $builder->orWhere(fn (EloquentBuilder $sub) => $sub
                        ->where(DB::raw('SUBSTR(idsubsls, 5, 3)'), $kdkec)
                        ->where(DB::raw('SUBSTR(idsubsls, 8, 3)'), $kddes)
                        ->where(DB::raw('SUBSTR(idsubsls, 11, 4)'), $kdsls));
                }
            });
        }
    }

    private function normalizeKec(string $code): string
    {
        $code = trim($code);

        return strlen($code) > 3 ? substr($code, -3) : $code;
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function normalizeDesa(string $code): array
    {
        $parts = explode('-', trim($code));

        if (count($parts) === 2) {
            return [$this->normalizeKec($parts[0]), substr($parts[1], -3)];
        }

        $code = trim($code);

        return [substr($code, -6, 3) ?: '', substr($code, -3)];
    }

    /**
     * @return array{0:string, 1:string, 2:string}
     */
    private function normalizeSls(string $code): array
    {
        $parts = explode('-', trim($code));

        if (count($parts) === 3) {
            return [$this->normalizeKec($parts[0]), substr($parts[1], -3), substr($parts[2], -4)];
        }

        $code = trim($code);

        return [substr($code, -10, 3) ?: '', substr($code, -7, 3) ?: '', substr($code, -4)];
    }

    private function fasihTableExists(string $table): bool
    {
        $dbPath = config('database.connections.fasih.database');
        if (! is_string($dbPath) || ! file_exists($dbPath)) {
            return false;
        }

        return DB::connection('fasih')
            ->table('sqlite_master')
            ->where('type', 'table')
            ->where('name', $table)
            ->exists();
    }
}
