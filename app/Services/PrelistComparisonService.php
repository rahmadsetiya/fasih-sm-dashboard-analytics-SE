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
        $assignmentIds = $this->assignmentIds($filterKec, $filterDesa, $filterSls);
        $progressIds = $progressTable !== null
            ? $this->progressIds($progressTable, $snapshot, $filterKec, $filterDesa, $filterSls)
            : [];
        $dynamicIds = array_values(array_unique([...$assignmentIds, ...$progressIds]));
        $initialIds = $this->initialIds($filterKec, $filterDesa, $filterSls);
        $assignmentSet = array_fill_keys($assignmentIds, true);
        $dynamicSet = array_fill_keys($dynamicIds, true);
        $initialSet = array_fill_keys($initialIds, true);
        $progressSet = array_fill_keys($progressIds, true);
        $initialWithoutAssignments = array_diff_key($initialSet, $assignmentSet);
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
            'initial_without_assignments_subsls' => count($initialWithoutAssignments),
            'initial_without_assignments_with_progress_subsls' => count(array_intersect_key($initialWithoutAssignments, $progressSet)),
            'initial_without_assignments_missing_progress_subsls' => count(array_diff_key($initialWithoutAssignments, $progressSet)),
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
        if (! in_array($level, ['by_pengawas', 'by_pencacah'], true) || ! $this->fasihTableExists('petugas_wilayah')) {
            return [];
        }

        $column = $level === 'by_pengawas' ? 'pengawas_user_id' : 'pencacah_user_id';
        $officerIdSql = "COALESCE(NULLIF(u.email, ''), ".$this->normalizedIdSql("pw.{$column}").')';
        $dynamicByRegion = $this->dynamicGroupTotals('subsls', $filterKec, $filterDesa, $filterSls, $progressTable, $snapshot);
        $initialByRegion = $this->initialGroupTotals('subsls', $filterKec, $filterDesa, $filterSls);
        $query = DB::connection('fasih')
            ->table('petugas_wilayah as pw')
            ->leftJoin('users as u', 'u.user_id', '=', "pw.{$column}")
            ->whereNotNull("pw.{$column}")
            ->whereNotNull('pw.idsubsls')
            ->selectRaw("{$officerIdSql} as officer_id, pw.idsubsls");

        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        $result = [];
        foreach ($query->distinct()->get() as $row) {
            $officerId = (string) $row->officer_id;
            $idsubsls = (string) $row->idsubsls;
            $result[$officerId] ??= ['dynamic' => 0, 'initial' => 0, 'selected' => 0];
            $result[$officerId]['dynamic'] += $dynamicByRegion[$idsubsls] ?? 0;
            $result[$officerId]['initial'] += $initialByRegion[$idsubsls] ?? 0;
        }

        foreach ($result as $officerId => $totals) {
            $result[$officerId]['selected'] = $basis === 'initial' && $this->initialAvailable()
                ? $totals['initial']
                : $totals['dynamic'];
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
     * @return list<string>
     */
    private function assignmentIds(array $filterKec, array $filterDesa, array $filterSls): array
    {
        if (! $this->fasihTableExists('assignments')) {
            return [];
        }

        $query = DB::connection('fasih')->table('assignments')->whereNotNull('idsubsls');
        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query->distinct()->pluck('idsubsls')->filter()->values()->all();
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
     * @return array<string, int>
     */
    private function progressFallbackTotals(?string $table, ?string $snapshot, array $filterKec, array $filterDesa, array $filterSls): array
    {
        if ($table === null || ! $this->fasihTableExists($table)) {
            return [];
        }

        $assignmentIds = array_fill_keys($this->assignmentIds($filterKec, $filterDesa, $filterSls), true);
        $snapshot ??= DB::connection('fasih')->table($table)->max('snapshot_at');
        $query = DB::connection('fasih')
            ->table($table)
            ->whereNotNull('idsubsls');

        if ($snapshot !== null) {
            $query->where('snapshot_at', $snapshot);
        }

        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return $query
            ->selectRaw('idsubsls, SUM(region_total) as total')
            ->groupBy('idsubsls')
            ->get()
            ->filter(fn (object $row) => ! isset($assignmentIds[(string) $row->idsubsls]))
            ->mapWithKeys(fn (object $row) => [(string) $row->idsubsls => (int) ($row->total ?? 0)])
            ->all();
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
        if (! $this->fasihTableExists('assignments')) {
            return 0;
        }

        $query = DB::connection('fasih')->table('assignments');
        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        return (int) $query->count()
            + array_sum($this->progressFallbackTotals($progressTable, $snapshot, $filterKec, $filterDesa, $filterSls));
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
        if (! $this->fasihTableExists('assignments')) {
            return [];
        }

        [$keySql, $groupBy] = $this->dynamicGroupSql($level);
        $query = DB::connection('fasih')->table('assignments')->selectRaw("{$keySql} as grp_key, COUNT(*) as total");
        $this->applyGeoFilter($query, $filterKec, $filterDesa, $filterSls);

        $totals = $query
            ->whereNotNull('idsubsls')
            ->groupBy(...$groupBy)
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->grp_key => (int) $row->total])
            ->all();

        foreach ($this->progressFallbackTotals($progressTable, $snapshot, $filterKec, $filterDesa, $filterSls) as $idsubsls => $total) {
            $key = $this->groupKeyFromIdsubsls($level, (string) $idsubsls);
            $totals[$key] = ($totals[$key] ?? 0) + $total;
        }

        return $totals;
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

    private function groupKeyFromIdsubsls(string $level, string $idsubsls): string
    {
        return match ($level) {
            'desa' => substr($idsubsls, 4, 3).'-'.substr($idsubsls, 7, 3),
            'sls' => substr($idsubsls, 4, 3).substr($idsubsls, 7, 3).substr($idsubsls, 10, 4),
            'subsls' => $idsubsls,
            default => substr($idsubsls, 4, 3),
        };
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private function normalizedIdSql(string $column): string
    {
        return "CASE WHEN typeof({$column}) = 'blob' THEN lower(substr(hex({$column}), 1, 8) || '-' || substr(hex({$column}), 9, 4) || '-' || substr(hex({$column}), 13, 4) || '-' || substr(hex({$column}), 17, 4) || '-' || substr(hex({$column}), 21, 12)) ELSE CAST({$column} AS TEXT) END";
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
