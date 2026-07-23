<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfficerProjectionService
{
    private const DEFAULT_DEADLINE = '2026-08-31';

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

    private const SUBMIT_STATUS_COLS = [
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

    private const REJECT_STATUS_COLS = [
        'REJECTED BY Pengawas',
        'REJECTED BY Admin Kabupaten',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $dbPath = config('database.connections.fasih.database');
        if (! is_string($dbPath) || ! file_exists($dbPath)) {
            return $this->emptyResponse('Database FASIH belum tersedia.');
        }

        $role = $this->normalizeRole($filters['role'] ?? null);
        $table = $this->tableForRole($role);

        if (! $this->tableExists($table)) {
            return $this->emptyResponse('Tabel progress belum tersedia.');
        }

        $latestSnapshot = $this->resolveSnapshot($table, $filters['snapshot'] ?? null);
        if ($latestSnapshot === null) {
            return $this->emptyResponse('Snapshot progress belum tersedia.');
        }

        $deadline = $this->resolveDeadline($filters['deadline'] ?? null);
        $snapshotDate = CarbonImmutable::parse(substr($latestSnapshot, 0, 10), config('app.timezone'));
        $daysLeft = max(1, $snapshotDate->diffInDays($deadline, false) + 1);

        $rows = $this->projectionRows($table, $role, $latestSnapshot, $deadline, $daysLeft, $filters);
        $rows = $this->applySearchAndStatus($rows, $filters);
        $rows = $this->sortRows($rows, (string) ($filters['sort'] ?? 'remaining_total'), (string) ($filters['direction'] ?? 'desc'));

        return [
            'empty' => false,
            'message' => null,
            'summary' => $this->summary($rows, $latestSnapshot, $deadline, $daysLeft, $role),
            'rows' => $rows->values()->all(),
            'history' => $this->aggregateHistory($table, $filters),
            'filter_options' => $this->filterOptions($table, $latestSnapshot, $filters),
            'status_columns' => self::STATUS_COLS,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function detail(string $officerKey, array $filters): array
    {
        $data = $this->list($filters);
        if ($data['empty']) {
            return $data;
        }

        $row = collect($data['rows'])->firstWhere('officer_key', $officerKey);
        if (! $row) {
            return [
                'empty' => true,
                'message' => 'Petugas tidak ditemukan pada scope filter aktif.',
            ];
        }

        $role = $this->normalizeRole($filters['role'] ?? null);
        $table = $this->tableForRole($role);
        $snapshot = (string) $data['summary']['snapshot'];

        return [
            'empty' => false,
            'message' => null,
            'officer' => [
                'key' => $row['officer_key'],
                'name' => $row['name'],
                'role' => $role,
                'role_label' => $role === 'pencacah' ? 'PPL' : 'PML',
            ],
            'metrics' => $row,
            'status_totals' => $row['statuses'],
            'daily_history' => $this->officerHistory($table, $row['officer_key'], $filters),
            'target_vs_actual' => $this->targetVsActual($table, $row, $filters),
            'regions' => $this->regions($table, $snapshot, $row['officer_key'], $filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResponse(string $message): array
    {
        return [
            'empty' => true,
            'message' => $message,
            'summary' => [
                'snapshot' => null,
                'deadline' => self::DEFAULT_DEADLINE,
                'days_left' => 0,
                'role' => 'pencacah',
                'total_officers' => 0,
                'total_assignment' => 0,
                'submitted_total' => 0,
                'rejected_total' => 0,
                'rejection_rate' => 0.0,
                'remaining_total' => 0,
                'required_daily_submit' => 0,
                'quality_adjusted_daily_rate' => 0.0,
                'counts_by_status' => $this->emptyStatusCounts(),
                'counts_by_reject_risk' => $this->emptyRejectRiskCounts(),
            ],
            'rows' => [],
            'history' => [],
            'filter_options' => ['snapshots' => [], 'kec' => [], 'desa' => [], 'sls' => []],
            'status_columns' => self::STATUS_COLS,
        ];
    }

    private function normalizeRole(mixed $role): string
    {
        return $role === 'pengawas' ? 'pengawas' : 'pencacah';
    }

    private function tableForRole(string $role): string
    {
        return $role === 'pengawas' ? 'progress_pengawas' : 'progress_pencacah';
    }

    private function tableExists(string $table): bool
    {
        return DB::connection('fasih')
            ->table('sqlite_master')
            ->where('type', 'table')
            ->where('name', $table)
            ->exists();
    }

    private function resolveSnapshot(string $table, mixed $snapshot): ?string
    {
        if (is_string($snapshot) && $snapshot !== '') {
            $exists = DB::connection('fasih')->table($table)
                ->where('snapshot_at', $snapshot)
                ->exists();

            if ($exists) {
                return $snapshot;
            }
        }

        $latest = DB::connection('fasih')->table($table)->max('snapshot_at');

        return is_string($latest) ? $latest : null;
    }

    private function resolveDeadline(mixed $deadline): CarbonImmutable
    {
        if (is_string($deadline) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            return CarbonImmutable::parse($deadline, config('app.timezone'));
        }

        return CarbonImmutable::parse(self::DEFAULT_DEADLINE, config('app.timezone'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function projectionRows(string $table, string $role, string $snapshot, CarbonImmutable $deadline, int $daysLeft, array $filters): Collection
    {
        $current = $this->currentRows($table, $snapshot, $filters);
        $firsts = $this->firstRows($table, $filters)->keyBy('officer_key');

        return $current->map(function (object $row) use ($firsts, $daysLeft, $role) {
            $officerKey = (string) $row->officer_key;
            $submitted = (int) $row->submitted_total;
            $rejected = (int) $row->rejected_total;
            $total = (int) $row->total_assignment;
            $remaining = max(0, $total - $submitted);
            $first = $firsts->get($officerKey);
            $firstSubmitted = $first ? (int) $first->submitted_total : $submitted;
            $firstDate = $first ? (string) $first->snapshot_date : (string) $row->snapshot_date;
            $daysSpan = max(0, CarbonImmutable::parse($firstDate)->diffInDays(CarbonImmutable::parse((string) $row->snapshot_date), false));
            $delta = max(0, $submitted - $firstSubmitted);
            $actualRate = $daysSpan > 0 ? $delta / $daysSpan : 0.0;
            $requiredDaily = $remaining > 0 ? (int) ceil($remaining / $daysLeft) : 0;
            $rejectionRate = $submitted > 0 ? ($rejected / $submitted) * 100 : 0.0;
            $qualityRate = max(0.0, $actualRate * (1 - ($rejectionRate / 100)));
            $etaDays = $actualRate > 0 && $remaining > 0 ? (int) ceil($remaining / $actualRate) : null;
            $estimatedFinish = $etaDays !== null
                ? CarbonImmutable::parse((string) $row->snapshot_date)->addDays($etaDays)->toDateString()
                : null;
            $qualityEtaDays = $qualityRate > 0 && $remaining > 0 ? (int) ceil($remaining / $qualityRate) : null;
            $qualityEstimatedFinish = $qualityEtaDays !== null
                ? CarbonImmutable::parse((string) $row->snapshot_date)->addDays($qualityEtaDays)->toDateString()
                : null;
            $status = $this->projectionStatus($remaining, $actualRate, $qualityRate, $requiredDaily);
            $rejectRisk = $this->rejectRisk($rejectionRate);

            return [
                'officer_key' => $officerKey,
                'name' => $this->titleName((string) $row->name),
                'role' => $role,
                'role_label' => $role === 'pencacah' ? 'PPL' : 'PML',
                'total_assignment' => $total,
                'submitted_total' => $submitted,
                'rejected_total' => $rejected,
                'rejection_rate' => round($rejectionRate, 2),
                'remaining_total' => $remaining,
                'open_total' => (int) $row->open_total,
                'draft_total' => (int) $row->draft_total,
                'required_daily_submit' => $requiredDaily,
                'actual_daily_rate' => round($actualRate, 2),
                'quality_adjusted_daily_rate' => round($qualityRate, 2),
                'estimated_finish_date' => $estimatedFinish,
                'quality_adjusted_finish_date' => $qualityEstimatedFinish,
                'reject_risk' => $rejectRisk,
                'reject_risk_label' => $this->rejectRiskLabel($rejectRisk),
                'projection_status' => $status,
                'projection_status_label' => $this->statusLabel($status),
                'first_snapshot_date' => $firstDate,
                'snapshot_date' => (string) $row->snapshot_date,
                'days_observed' => $daysSpan,
                'submit_delta' => $delta,
                'statuses' => $this->statusArray($row),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function currentRows(string $table, string $snapshot, array $filters): Collection
    {
        return $this->baseQuery($table, $filters)
            ->where('snapshot_at', $snapshot)
            ->selectRaw($this->officerSelectSql())
            ->groupBy('officer_key', 'name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function firstRows(string $table, array $filters): Collection
    {
        $submitSql = $this->submitSql();
        $daily = $this->baseQuery($table, $filters)
            ->selectRaw("
                {$this->officerKeySql()} as officer_key,
                DATE(snapshot_at) as snapshot_date,
                snapshot_at,
                SUM({$submitSql}) as submitted_total
            ")
            ->groupBy('officer_key', 'snapshot_date', 'snapshot_at');

        $latestDaily = DB::connection('fasih')
            ->query()
            ->fromSub($daily, 'daily')
            ->selectRaw('
                daily.*,
                ROW_NUMBER() OVER (
                    PARTITION BY daily.officer_key, daily.snapshot_date
                    ORDER BY daily.snapshot_at DESC
                ) as daily_rank
            ');

        $firstDaily = DB::connection('fasih')
            ->query()
            ->fromSub($latestDaily, 'latest_daily')
            ->where('daily_rank', 1)
            ->selectRaw('
                latest_daily.*,
                ROW_NUMBER() OVER (
                    PARTITION BY latest_daily.officer_key
                    ORDER BY latest_daily.snapshot_date ASC
                ) as first_rank
            ');

        return DB::connection('fasih')
            ->query()
            ->fromSub($firstDaily, 'first_daily')
            ->where('first_rank', 1)
            ->select('officer_key', 'snapshot_date', 'submitted_total')
            ->get();
    }

    private function officerSelectSql(): string
    {
        $statusSums = collect(self::STATUS_COLS)
            ->map(fn (string $status) => 'SUM(COALESCE("'.str_replace('"', '""', $status).'", 0)) as "'.$status.'"')
            ->implode(', ');

        return "
            COALESCE(NULLIF(user_id, ''), NULLIF(username, ''), NULLIF(nama_lengkap, '')) as officer_key,
            COALESCE(NULLIF(nama_lengkap, ''), NULLIF(username, ''), 'Tanpa nama') as name,
            DATE(snapshot_at) as snapshot_date,
            MAX(COALESCE(user_total, region_total, 0)) as total_assignment,
            SUM(COALESCE(\"OPEN\", 0)) as open_total,
            SUM(COALESCE(\"DRAFT\", 0)) as draft_total,
            SUM({$this->submitSql()}) as submitted_total,
            SUM({$this->rejectSql()}) as rejected_total,
            {$statusSums}
        ";
    }

    private function submitSql(): string
    {
        return collect(self::SUBMIT_STATUS_COLS)
            ->map(fn (string $status) => 'COALESCE("'.str_replace('"', '""', $status).'", 0)')
            ->implode(' + ');
    }

    private function rejectSql(): string
    {
        return collect(self::REJECT_STATUS_COLS)
            ->map(fn (string $status) => 'COALESCE("'.str_replace('"', '""', $status).'", 0)')
            ->implode(' + ');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(string $table, array $filters): Builder
    {
        $query = DB::connection('fasih')->table($table);

        $kec = $this->stringList($filters['kdkec'] ?? []);
        $desa = $this->stringList($filters['kddes'] ?? []);
        $sls = $this->stringList($filters['kdsls'] ?? []);
        $subsls = $this->stringList($filters['idsubsls'] ?? []);

        if ($kec !== []) {
            $query->whereIn('kdkec', $kec);
        }

        if ($desa !== []) {
            $query->where(function (Builder $builder) use ($desa) {
                foreach ($desa as $code) {
                    $parts = explode('-', $code);
                    if (count($parts) === 2) {
                        [$kdkec, $kddes] = $parts;
                        $builder->orWhere(fn (Builder $sub) => $sub
                            ->where('kdkec', $kdkec)
                            ->where('kddes', $kddes));
                    } else {
                        $builder->orWhere('kddes', $code);
                    }
                }
            });
        }

        if ($sls !== []) {
            $query->where(function (Builder $builder) use ($sls) {
                foreach ($sls as $code) {
                    $parts = explode('-', $code);
                    if (count($parts) === 3) {
                        [$kdkec, $kddes, $kdsls] = $parts;
                        $builder->orWhere(fn (Builder $sub) => $sub
                            ->where('kdkec', $kdkec)
                            ->where('kddes', $kddes)
                            ->where('kdsls', $kdsls));
                    } else {
                        $builder->orWhere('kdsls', $code);
                    }
                }
            });
        }

        if ($subsls !== []) {
            $query->whereIn('idsubsls', $subsls);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, fn ($item) => is_string($item) && $item !== ''));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applySearchAndStatus(Collection $rows, array $filters): Collection
    {
        $search = Str::lower(trim((string) ($filters['search'] ?? '')));
        $status = (string) ($filters['status'] ?? '');

        return $rows
            ->when($search !== '', fn (Collection $items) => $items->filter(
                fn (array $row) => str_contains(Str::lower((string) $row['name']), $search)
            ))
            ->when($status !== '', fn (Collection $items) => $items->filter(
                fn (array $row) => $row['projection_status'] === $status
            ))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort, string $direction): Collection
    {
        $allowed = ['name', 'total_assignment', 'submitted_total', 'rejected_total', 'rejection_rate', 'remaining_total', 'required_daily_submit', 'actual_daily_rate', 'quality_adjusted_daily_rate', 'estimated_finish_date', 'quality_adjusted_finish_date', 'projection_status', 'reject_risk'];
        $field = in_array($sort, $allowed, true) ? $sort : 'remaining_total';
        $descending = strtolower($direction) !== 'asc';

        return $rows->sortBy(
            fn (array $row) => $row[$field] ?? null,
            SORT_REGULAR,
            $descending,
        )->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows, string $snapshot, CarbonImmutable $deadline, int $daysLeft, string $role): array
    {
        $remaining = (int) $rows->sum('remaining_total');
        $submitted = (int) $rows->sum('submitted_total');
        $rejected = (int) $rows->sum('rejected_total');

        return [
            'snapshot' => $snapshot,
            'deadline' => $deadline->toDateString(),
            'days_left' => $daysLeft,
            'role' => $role,
            'total_officers' => $rows->count(),
            'total_assignment' => (int) $rows->sum('total_assignment'),
            'submitted_total' => $submitted,
            'rejected_total' => $rejected,
            'rejection_rate' => $submitted > 0 ? round(($rejected / $submitted) * 100, 2) : 0.0,
            'remaining_total' => $remaining,
            'required_daily_submit' => $remaining > 0 ? (int) ceil($remaining / $daysLeft) : 0,
            'quality_adjusted_daily_rate' => round((float) $rows->sum('quality_adjusted_daily_rate'), 2),
            'counts_by_status' => [
                'done' => $rows->where('projection_status', 'done')->count(),
                'on_track' => $rows->where('projection_status', 'on_track')->count(),
                'behind' => $rows->where('projection_status', 'behind')->count(),
                'no_rate' => $rows->where('projection_status', 'no_rate')->count(),
            ],
            'counts_by_reject_risk' => [
                'low' => $rows->where('reject_risk', 'low')->count(),
                'watch' => $rows->where('reject_risk', 'watch')->count(),
                'high' => $rows->where('reject_risk', 'high')->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function aggregateHistory(string $table, array $filters): array
    {
        return $this->latestDailyRows($table, $filters)
            ->selectRaw("
                DATE({$table}.snapshot_at) as date,
                latest_daily.snapshot_at as snapshot_at,
                SUM({$this->submitSql()}) as submitted_total,
                SUM({$this->rejectSql()}) as rejected_total,
                SUM(COALESCE(\"OPEN\", 0)) as open_total,
                SUM(COALESCE(\"DRAFT\", 0)) as draft_total,
                SUM(COALESCE(region_total, 0)) as raw_total
            ")
            ->groupBy('date', 'latest_daily.snapshot_at')
            ->orderBy('date')
            ->get()
            ->map(fn (object $row) => [
                'date' => $row->date,
                'snapshot_at' => $row->snapshot_at,
                'submitted_total' => (int) $row->submitted_total,
                'rejected_total' => (int) $row->rejected_total,
                'open_total' => (int) $row->open_total,
                'draft_total' => (int) $row->draft_total,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function officerHistory(string $table, string $officerKey, array $filters): array
    {
        return $this->latestDailyRows($table, $filters, $officerKey)
            ->selectRaw("
                DATE({$table}.snapshot_at) as date,
                latest_daily.snapshot_at as snapshot_at,
                MAX(COALESCE(user_total, region_total, 0)) as total_assignment,
                SUM({$this->submitSql()}) as submitted_total,
                SUM({$this->rejectSql()}) as rejected_total,
                SUM(COALESCE(\"OPEN\", 0)) as open_total,
                SUM(COALESCE(\"DRAFT\", 0)) as draft_total
            ")
            ->groupBy('date', 'latest_daily.snapshot_at')
            ->orderBy('date')
            ->get()
            ->map(fn (object $row) => [
                'date' => $row->date,
                'snapshot_at' => $row->snapshot_at,
                'total_assignment' => (int) $row->total_assignment,
                'submitted_total' => (int) $row->submitted_total,
                'rejected_total' => (int) $row->rejected_total,
                'open_total' => (int) $row->open_total,
                'draft_total' => (int) $row->draft_total,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function latestDailyRows(string $table, array $filters, ?string $officerKey = null): Builder
    {
        $latest = $this->baseQuery($table, $filters)
            ->when($officerKey !== null, fn (Builder $query) => $query->whereRaw($this->officerKeySql().' = ?', [$officerKey]))
            ->selectRaw('DATE(snapshot_at) as snapshot_date, MAX(snapshot_at) as snapshot_at')
            ->groupBy('snapshot_date');

        return $this->baseQuery($table, $filters)
            ->when($officerKey !== null, fn (Builder $query) => $query->whereRaw($this->officerKeySql().' = ?', [$officerKey]))
            ->joinSub(
                $latest,
                'latest_daily',
                fn ($join) => $join
                    ->on("{$table}.snapshot_at", '=', 'latest_daily.snapshot_at')
                    ->whereRaw("DATE({$table}.snapshot_at) = latest_daily.snapshot_date")
            );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function latestDailyOfficerRows(string $table, array $filters): Builder
    {
        $latest = $this->baseQuery($table, $filters)
            ->selectRaw("
                {$this->officerKeySql()} as officer_key,
                DATE(snapshot_at) as snapshot_date,
                MAX(snapshot_at) as snapshot_at
            ")
            ->groupBy('officer_key', 'snapshot_date');

        return $this->baseQuery($table, $filters)
            ->joinSub(
                $latest,
                'latest_daily',
                fn ($join) => $join
                    ->on("{$table}.snapshot_at", '=', 'latest_daily.snapshot_at')
                    ->whereRaw("DATE({$table}.snapshot_at) = latest_daily.snapshot_date")
                    ->whereRaw($this->officerKeySql($table).' = latest_daily.officer_key')
            );
    }

    private function officerKeySql(?string $table = null): string
    {
        $prefix = $table !== null ? $table.'.' : '';

        return "COALESCE(NULLIF({$prefix}user_id, ''), NULLIF({$prefix}username, ''), NULLIF({$prefix}nama_lengkap, ''))";
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function targetVsActual(string $table, array $row, array $filters): array
    {
        $history = $this->officerHistory($table, (string) $row['officer_key'], $filters);
        if ($history === []) {
            return [];
        }

        $firstSubmit = (int) $history[0]['submitted_total'];
        $required = (int) $row['required_daily_submit'];

        return collect($history)->map(function (array $point, int $index) use ($firstSubmit, $required) {
            return [
                'date' => $point['date'],
                'actual_submit' => $point['submitted_total'],
                'actual_reject' => $point['rejected_total'],
                'target_submit' => $firstSubmit + ($required * $index),
            ];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function regions(string $table, string $snapshot, string $officerKey, array $filters): array
    {
        return $this->baseQuery($table, $filters)
            ->where('snapshot_at', $snapshot)
            ->whereRaw("COALESCE(NULLIF(user_id, ''), NULLIF(username, ''), NULLIF(nama_lengkap, '')) = ?", [$officerKey])
            ->selectRaw("
                idsubsls, kdkec, kddes, kdsls, kdsubsls, nmkec, nmdesa, nmsls, nmsubsls,
                SUM(COALESCE(region_total, 0)) as total_assignment,
                SUM(COALESCE(\"OPEN\", 0)) as open_total,
                SUM(COALESCE(\"DRAFT\", 0)) as draft_total,
                SUM({$this->submitSql()}) as submitted_total,
                SUM({$this->rejectSql()}) as rejected_total
            ")
            ->groupBy('idsubsls', 'kdkec', 'kddes', 'kdsls', 'kdsubsls', 'nmkec', 'nmdesa', 'nmsls', 'nmsubsls')
            ->orderBy('kdkec')
            ->orderBy('kddes')
            ->orderBy('kdsls')
            ->orderBy('kdsubsls')
            ->get()
            ->map(fn (object $row) => [
                'idsubsls' => $row->idsubsls,
                'label' => $this->titleName((string) ($row->nmsubsls ?: $row->nmsls ?: $row->nmdesa ?: $row->nmkec ?: $row->idsubsls)),
                'kecamatan' => $this->titleName((string) $row->nmkec),
                'desa' => $this->titleName((string) $row->nmdesa),
                'sls' => $this->titleName((string) $row->nmsls),
                'total_assignment' => (int) $row->total_assignment,
                'submitted_total' => (int) $row->submitted_total,
                'rejected_total' => (int) $row->rejected_total,
                'open_total' => (int) $row->open_total,
                'draft_total' => (int) $row->draft_total,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filterOptions(string $table, string $snapshot, array $filters): array
    {
        $base = DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot);

        return [
            'snapshots' => DB::connection('fasih')->table($table)
                ->selectRaw('DISTINCT snapshot_at')
                ->orderByDesc('snapshot_at')
                ->pluck('snapshot_at')
                ->all(),
            'kec' => (clone $base)
                ->selectRaw('kdkec as code, nmkec as label, SUM(region_total) as total')
                ->whereNotNull('kdkec')
                ->groupBy('kdkec', 'nmkec')
                ->orderBy('nmkec')
                ->get()
                ->map(fn (object $row) => ['code' => $row->code, 'label' => $this->titleName((string) $row->label), 'total' => (int) $row->total])
                ->all(),
            'desa' => (clone $base)
                ->selectRaw('kddes as code, kdkec, nmdesa as label, SUM(region_total) as total')
                ->whereNotNull('kddes')
                ->groupBy('kdkec', 'kddes', 'nmdesa')
                ->orderBy('nmdesa')
                ->get()
                ->map(fn (object $row) => ['code' => $row->kdkec.'-'.$row->code, 'raw_code' => $row->code, 'kdkec' => $row->kdkec, 'label' => $this->titleName((string) $row->label), 'total' => (int) $row->total])
                ->all(),
            'sls' => (clone $base)
                ->selectRaw('kdsls as code, kdkec, kddes, nmsls as label, SUM(region_total) as total')
                ->whereNotNull('kdsls')
                ->groupBy('kdkec', 'kddes', 'kdsls', 'nmsls')
                ->orderBy('nmsls')
                ->get()
                ->map(fn (object $row) => ['code' => $row->kdkec.'-'.$row->kddes.'-'.$row->code, 'raw_code' => $row->code, 'kdkec' => $row->kdkec, 'kddes' => $row->kddes, 'label' => $this->titleName((string) $row->label), 'total' => (int) $row->total])
                ->all(),
        ];
    }

    private function projectionStatus(int $remaining, float $actualRate, float $qualityAdjustedRate, int $requiredDaily): string
    {
        if ($remaining <= 0) {
            return 'done';
        }
        if ($actualRate <= 0) {
            return 'no_rate';
        }

        return $qualityAdjustedRate >= $requiredDaily ? 'on_track' : 'behind';
    }

    private function rejectRisk(float $rejectionRate): string
    {
        return match (true) {
            $rejectionRate >= 10 => 'high',
            $rejectionRate >= 5 => 'watch',
            default => 'low',
        };
    }

    private function rejectRiskLabel(string $risk): string
    {
        return match ($risk) {
            'high' => 'Reject Tinggi',
            'watch' => 'Perlu Pantau',
            default => 'Reject Rendah',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'done' => 'Selesai',
            'on_track' => 'Aman',
            'behind' => 'Berisiko',
            'no_rate' => 'Belum Bergerak',
            default => 'Tidak diketahui',
        };
    }

    /**
     * @return array<string, int>
     */
    private function statusArray(object $row): array
    {
        $statuses = [];
        foreach (self::STATUS_COLS as $status) {
            $statuses[$status] = (int) ($row->{$status} ?? 0);
        }

        return $statuses;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStatusCounts(): array
    {
        return ['done' => 0, 'on_track' => 0, 'behind' => 0, 'no_rate' => 0];
    }

    /**
     * @return array<string, int>
     */
    private function emptyRejectRiskCounts(): array
    {
        return ['low' => 0, 'watch' => 0, 'high' => 0];
    }

    private function titleName(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');
        if ($value === '') {
            return 'Tanpa nama';
        }

        return Str::title(Str::lower($value));
    }
}
